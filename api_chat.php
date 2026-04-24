<?php
// ============================================================
//  API_CHAT.PHP — RAG corrigido: busca genérica em fragmentos
// ============================================================

require_once 'auth.php';
require_once 'configuracao.php';
require_once 'conexao.php';

// Exige sessão activa
iniciarSessao();
$utilizador    = utilizadorActual();
$id_utilizador = $utilizador['id_utilizador'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respostaJson(false, null, 'Método não permitido.');

$corpo     = json_decode(file_get_contents('php://input'), true);
$mensagem  = trim($corpo['mensagem']  ?? '');
$id_sessao = trim($corpo['id_sessao'] ?? '');

if ($mensagem === '') respostaJson(false, null, 'Mensagem vazia.');
if ($id_sessao === '') respostaJson(false, null, 'Sessão inválida.');

$pdo = obterConexao();

// ------------------------------------------------------------
// 1. Obtém ou cria conversa
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id_conversa FROM conversas
    WHERE id_sessao = :s AND id_configuracao_bot = :bot
      AND (id_utilizador = :uid OR (:uid2::text IS NULL AND id_utilizador IS NULL))
    LIMIT 1
");
$stmt->execute([':s' => $id_sessao, ':bot' => BOT_ID, ':uid' => $id_utilizador, ':uid2' => $id_utilizador]);
$conversa = $stmt->fetch();

if (!$conversa) {
    $stmt = $pdo->prepare("
        INSERT INTO conversas (id_configuracao_bot, id_sessao, id_utilizador)
        VALUES (:bot, :s, :uid) RETURNING id_conversa
    ");
    $stmt->execute([':bot' => BOT_ID, ':s' => $id_sessao, ':uid' => $id_utilizador]);
    $id_conversa = $stmt->fetchColumn();
} else {
    $id_conversa = $conversa['id_conversa'];
}

// ------------------------------------------------------------
// 2. Guarda mensagem do utilizador
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO mensagens (id_conversa, papel, conteudo)
    VALUES (:c, 'utilizador', :m) RETURNING id_mensagem
");
$stmt->execute([':c' => $id_conversa, ':m' => $mensagem]);
$id_msg_user = $stmt->fetchColumn();

// ------------------------------------------------------------
// FUNÇÃO: Extrai palavras-chave removendo stopwords
// ------------------------------------------------------------
function extrairPalavrasChave(string $texto): array {
    $stopwords = [
        'o','a','os','as','um','uma','uns','umas','de','do','da','dos','das',
        'em','no','na','nos','nas','por','para','com','sem','que','se','e',
        'é','ao','aos','à','às','pelo','pela','pelos','pelas','me','te','lhe',
        'eu','tu','ele','ela','nós','vós','eles','elas','isso','este','esta',
        'esse','essa','aquele','aquela','como','mais','mas','ou','já','foi',
        'ser','ter','haver','estar','fazer','dizer','ir','ver','dar','saber',
        'querer','poder','dever','qual','quais','quando','onde','quem','quanto',
        'diz','faz','tem','vai','vem','seu','sua','seus','suas','meu','minha',
    ];
    $palavras = preg_split('/[\s\-_,;.!?()\[\]{}:\/\\\\]+/', mb_strtolower(trim($texto)));
    return array_values(array_filter(
        $palavras,
        fn($p) => mb_strlen($p) >= 3 && !in_array($p, $stopwords)
    ));
}

// ------------------------------------------------------------
// 3. BUSCA RAG
// ------------------------------------------------------------
$contexto_partes = [];
$fontes_usadas   = [];

// ── 3a. BASE DE CONHECIMENTO ──────────────────────────────────

// Tentativa 1: FTS português
$stmt = $pdo->prepare("
    SELECT id_base_conhecimento, titulo, conteudo,
           ts_rank(
               to_tsvector('portuguese', titulo || ' ' || conteudo),
               plainto_tsquery('portuguese', :q)
           ) AS r
    FROM base_conhecimento
    WHERE id_configuracao_bot = :bot AND ativo = TRUE
      AND to_tsvector('portuguese', titulo || ' ' || conteudo)
          @@ plainto_tsquery('portuguese', :q2)
    ORDER BY r DESC LIMIT :lim
");
$stmt->execute([':bot' => BOT_ID, ':q' => $mensagem, ':q2' => $mensagem, ':lim' => MAX_RESULTADOS_BUSCA]);
$conhecimentos = $stmt->fetchAll();

// Tentativa 2: ILIKE por palavras-chave
if (empty($conhecimentos)) {
    $palavras = array_slice(extrairPalavrasChave($mensagem), 0, 5);
    if (!empty($palavras)) {
        $conds  = [];
        $params = [':bot' => BOT_ID];
        foreach ($palavras as $i => $p) {
            $conds[]       = "(titulo ILIKE :p{$i} OR conteudo ILIKE :p{$i})";
            $params[":p{$i}"] = "%{$p}%";
        }
        $stmt = $pdo->prepare(
            "SELECT id_base_conhecimento, titulo, conteudo, 1.0 AS r
             FROM base_conhecimento
             WHERE id_configuracao_bot = :bot AND ativo = TRUE
               AND (" . implode(' OR ', $conds) . ")
             LIMIT " . MAX_RESULTADOS_BUSCA
        );
        $stmt->execute($params);
        $conhecimentos = $stmt->fetchAll();
    }
}

// Tentativa 3: top por prioridade (fallback final)
if (empty($conhecimentos)) {
    $stmt = $pdo->prepare("
        SELECT id_base_conhecimento, titulo, conteudo, prioridade AS r
        FROM base_conhecimento
        WHERE id_configuracao_bot = :bot AND ativo = TRUE
        ORDER BY prioridade ASC LIMIT 3
    ");
    $stmt->execute([':bot' => BOT_ID]);
    $conhecimentos = $stmt->fetchAll();
}

foreach ($conhecimentos as $k) {
    $contexto_partes[] = "### {$k['titulo']}\n{$k['conteudo']}";
    $fontes_usadas[]   = ['tipo' => 'conhecimento', 'id' => $k['id_base_conhecimento']];
}

// ── 3b. FRAGMENTOS DE DOCUMENTOS ─────────────────────────────
$restantes = MAX_RESULTADOS_BUSCA - count($contexto_partes);

if ($restantes > 0) {
    $fragmentos = [];

    // Estratégia 1: websearch_to_tsquery
    if (empty($fragmentos)) {
        try {
            $stmt = $pdo->prepare("
                SELECT f.id_fragmento, f.conteudo, d.nome_original,
                       ts_rank(
                           to_tsvector('portuguese', f.conteudo),
                           websearch_to_tsquery('portuguese', :q)
                       ) AS r
                FROM fragmentos_documento f
                JOIN documentos d ON d.id_documento = f.id_documento
                WHERE d.id_configuracao_bot = :bot
                  AND d.estado = 'pronto'
                  AND to_tsvector('portuguese', f.conteudo)
                      @@ websearch_to_tsquery('portuguese', :q2)
                ORDER BY r DESC
                LIMIT :lim
            ");
            $stmt->execute([':bot' => BOT_ID, ':q' => $mensagem, ':q2' => $mensagem, ':lim' => $restantes]);
            $fragmentos = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[RAG] websearch_to_tsquery falhou: " . $e->getMessage());
            $fragmentos = [];
        }
    }

    // Estratégia 2: plainto_tsquery clássico
    if (empty($fragmentos)) {
        try {
            $stmt = $pdo->prepare("
                SELECT f.id_fragmento, f.conteudo, d.nome_original,
                       ts_rank(
                           to_tsvector('portuguese', f.conteudo),
                           plainto_tsquery('portuguese', :q)
                       ) AS r
                FROM fragmentos_documento f
                JOIN documentos d ON d.id_documento = f.id_documento
                WHERE d.id_configuracao_bot = :bot
                  AND d.estado = 'pronto'
                  AND to_tsvector('portuguese', f.conteudo)
                      @@ plainto_tsquery('portuguese', :q2)
                ORDER BY r DESC
                LIMIT :lim
            ");
            $stmt->execute([':bot' => BOT_ID, ':q' => $mensagem, ':q2' => $mensagem, ':lim' => $restantes]);
            $fragmentos = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[RAG] plainto_tsquery falhou: " . $e->getMessage());
            $fragmentos = [];
        }
    }

    // Estratégia 3: ILIKE por palavras-chave individuais
    if (empty($fragmentos)) {
        $palavras = array_slice(extrairPalavrasChave($mensagem), 0, 6);
        if (!empty($palavras)) {
            $conds  = [];
            $params = [':bot' => BOT_ID];
            foreach ($palavras as $i => $p) {
                $conds[]         = "f.conteudo ILIKE :p{$i}";
                $params[":p{$i}"] = "%{$p}%";
            }
            $sql = "
                SELECT f.id_fragmento, f.conteudo, d.nome_original, 1.0 AS r
                FROM fragmentos_documento f
                JOIN documentos d ON d.id_documento = f.id_documento
                WHERE d.id_configuracao_bot = :bot
                  AND d.estado = 'pronto'
                  AND (" . implode(' OR ', $conds) . ")
                ORDER BY f.indice_fragmento ASC
                LIMIT " . (int)$restantes;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $fragmentos = $stmt->fetchAll();
        }
    }

    // Estratégia 4: Primeiros fragmentos dos documentos mais recentes
    if (empty($fragmentos)) {
        $stmt = $pdo->prepare("
            SELECT f.id_fragmento, f.conteudo, d.nome_original, 0.1 AS r
            FROM fragmentos_documento f
            JOIN documentos d ON d.id_documento = f.id_documento
            WHERE d.id_configuracao_bot = :bot
              AND d.estado = 'pronto'
            ORDER BY d.processado_em DESC, f.indice_fragmento ASC
            LIMIT :lim
        ");
        $stmt->execute([':bot' => BOT_ID, ':lim' => $restantes]);
        $fragmentos = $stmt->fetchAll();
    }

    foreach ($fragmentos as $f) {
        $contexto_partes[] = "### Documento: {$f['nome_original']}\n{$f['conteudo']}";
        $fontes_usadas[]   = ['tipo' => 'fragmento', 'id' => $f['id_fragmento']];
    }

    error_log("[RAG] Mensagem: '{$mensagem}' | Fragmentos encontrados: " . count($fragmentos));
}

// ------------------------------------------------------------
// 4. Perfil do criador
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT p.*,
           EXTRACT(YEAR FROM AGE(p.data_nascimento))::INTEGER AS idade,
           b.nome AS nome_bot,
           b.prompt_sistema
    FROM perfil_criador p
    JOIN configuracao_bot b ON b.id_configuracao_bot = p.id_configuracao_bot
    WHERE p.id_configuracao_bot = :bot
    LIMIT 1
");
$stmt->execute([':bot' => BOT_ID]);
$perfil = $stmt->fetch();

// ------------------------------------------------------------
// 5. Monta prompt de sistema
// ------------------------------------------------------------
$nome_bot_str     = $perfil['nome_bot']      ?? 'MeuBot';
$nome_criador_str = $perfil['nome_completo'] ?? 'o teu criador';

$identidade  = "### IDENTIDADE (OBRIGATÓRIO)\n";
$identidade .= "1. O teu nome é {$nome_bot_str}.\n";
$identidade .= "2. Foste integralmente criado por {$nome_criador_str}.\n";
$identidade .= "3. Nunca digas que és produto da Google, Anthropic ou outro fornecedor.\n\n";

$prompt_sistema = $identidade . ($perfil['prompt_sistema'] ?? '');

if ($perfil) {
    $idade_txt = $perfil['idade'] ? "{$perfil['idade']} anos" : 'não informada';
    $prompt_sistema .= "\n\n## Dados do Criador\n"
        . "- Nome: {$perfil['nome_completo']}\n"
        . "- Data de nascimento: {$perfil['data_nascimento']}\n"
        . "- Idade: {$idade_txt}\n"
        . "- Telefone: {$perfil['telefone']}\n"
        . "- Morada: {$perfil['morada']}\n"
        . "- Email: {$perfil['email']}\n"
        . "- Profissão: {$perfil['profissao']}\n"
        . "- Nacionalidade: {$perfil['nacionalidade']}\n"
        . "- Bio: {$perfil['bio']}\n";
}

if (!empty($contexto_partes)) {
    $prompt_sistema .= "\n\n## Base de Conhecimento\n"
        . "Usa OBRIGATORIAMENTE as informações abaixo para responder. "
        . "Se a resposta estiver aqui, usa-a directamente. "
        . "Se não estiver, diz honestamente que não tens essa informação.\n\n"
        . implode("\n\n---\n\n", $contexto_partes);
} else {
    $prompt_sistema .= "\n\n## Base de Conhecimento\n"
        . "Não foi encontrado contexto relevante para esta pergunta. "
        . "Responde de forma geral sendo honesto sobre as limitações.";
}

// ------------------------------------------------------------
// 6. Histórico recente da conversa
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT papel, conteudo FROM mensagens
    WHERE id_conversa = :c AND id_mensagem != :id
    ORDER BY enviada_em DESC LIMIT :lim
");
$stmt->execute([':c' => $id_conversa, ':id' => $id_msg_user, ':lim' => MAX_HISTORICO_MENSAGENS]);
$historico = array_reverse($stmt->fetchAll());

// ------------------------------------------------------------
// 7. Payload para a API do Gemini
// ------------------------------------------------------------
$contents = [];
foreach ($historico as $msg) {
    $contents[] = [
        'role'  => $msg['papel'] === 'utilizador' ? 'user' : 'model',
        'parts' => [['text' => $msg['conteudo']]],
    ];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $mensagem]]];

$payload = [
    'system_instruction' => ['parts' => [['text' => $prompt_sistema]]],
    'contents'           => $contents,
    'generationConfig'   => ['maxOutputTokens' => 1024, 'temperature' => 0.7],
];

// ------------------------------------------------------------
// 8. Chamada cURL à API do Gemini
// ------------------------------------------------------------
$url = GEMINI_API_URL . '?key=' . GEMINI_CHAVE_API;
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$inicio    = microtime(true);
$raw       = curl_exec($ch);
$tempo_ms  = (int)((microtime(true) - $inicio) * 1000);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $http_code !== 200) {
    respostaJson(false, null, 'Erro Gemini. Código: ' . $http_code . ' — ' . ($raw ?: 'Sem resposta'));
}

$dados      = json_decode($raw, true);
$texto_resp = $dados['candidates'][0]['content']['parts'][0]['text']
    ?? 'Não consegui gerar uma resposta. Tenta novamente.';
$t_entrada  = $dados['usageMetadata']['promptTokenCount']     ?? null;
$t_saida    = $dados['usageMetadata']['candidatesTokenCount'] ?? null;

// ------------------------------------------------------------
// 9. Guarda resposta do assistente
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO mensagens
        (id_conversa, papel, conteudo, tokens_entrada, tokens_saida, tempo_resposta_ms)
    VALUES (:c, 'assistente', :m, :te, :ts, :t)
    RETURNING id_mensagem
");
$stmt->execute([
    ':c'  => $id_conversa,
    ':m'  => $texto_resp,
    ':te' => $t_entrada,
    ':ts' => $t_saida,
    ':t'  => $tempo_ms,
]);
$id_msg_bot = $stmt->fetchColumn();

// ------------------------------------------------------------
// 10. Regista fontes usadas
// ------------------------------------------------------------
foreach ($fontes_usadas as $fonte) {
    $pdo->prepare("
        INSERT INTO fontes_mensagem (id_mensagem, id_base_conhecimento, id_fragmento)
        VALUES (:m, :k, :f)
    ")->execute([
        ':m' => $id_msg_bot,
        ':k' => $fonte['tipo'] === 'conhecimento' ? $fonte['id'] : null,
        ':f' => $fonte['tipo'] === 'fragmento'    ? $fonte['id'] : null,
    ]);
}

// ------------------------------------------------------------
// 11. Resposta final ao frontend
// ------------------------------------------------------------
respostaJson(true, [
    'resposta'    => $texto_resp,
    'id_conversa' => $id_conversa,
    'tokens'      => ['entrada' => $t_entrada, 'saida' => $t_saida],
    'tempo_ms'    => $tempo_ms,
    'fontes'      => count($fontes_usadas),
]);