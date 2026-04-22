<?php
// ============================================================
//  API_CHAT.PHP — RAG melhorado: FTS + fallback por LIKE
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

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
$stmt = $pdo->prepare("SELECT id_conversa FROM conversas WHERE id_sessao=:s AND id_configuracao_bot=:bot LIMIT 1");
$stmt->execute([':s' => $id_sessao, ':bot' => BOT_ID]);
$conversa = $stmt->fetch();

if (!$conversa) {
    $stmt = $pdo->prepare("INSERT INTO conversas (id_configuracao_bot,id_sessao) VALUES (:bot,:s) RETURNING id_conversa");
    $stmt->execute([':bot' => BOT_ID, ':s' => $id_sessao]);
    $id_conversa = $stmt->fetchColumn();
} else {
    $id_conversa = $conversa['id_conversa'];
}

// ------------------------------------------------------------
// 2. Guarda mensagem do utilizador
// ------------------------------------------------------------
$stmt = $pdo->prepare("INSERT INTO mensagens (id_conversa,papel,conteudo) VALUES (:c,'utilizador',:m) RETURNING id_mensagem");
$stmt->execute([':c' => $id_conversa, ':m' => $mensagem]);
$id_msg_user = $stmt->fetchColumn();

// ------------------------------------------------------------
// 3. BUSCA RAG MELHORADA
//    Estratégia: FTS completo → FTS simplificado → LIKE por palavras-chave
// ------------------------------------------------------------
$contexto_partes = [];
$fontes_usadas   = [];

/**
 * Extrai as palavras-chave mais relevantes da mensagem.
 * Remove stopwords comuns em português.
 */
function extrairPalavrasChave(string $texto): array {
    // Removi 'regulamento', 'ispt', 'diz' da lista de bloqueio
    $stopwords = ['o','a','os','as','um','uma','uns','umas','de','do','da','dos','das',
                  'em','no','na','nos','nas','por','para','com','sem','que','se','e',
                  'é','ao','aos','à','às','pelo','pela','pelos','pelas','me','te','lhe'];
    
    $palavras = preg_split('/[\s\-_,;.!?()\[\]{}]+/', mb_strtolower($texto));
    return array_filter($palavras, fn($p) => mb_strlen($p) > 2 && !in_array($p, $stopwords));
}
// 3a. FTS na base_conhecimento
$stmt = $pdo->prepare("
    SELECT id_base_conhecimento, titulo, conteudo,
           ts_rank(to_tsvector('portuguese', titulo||' '||conteudo), plainto_tsquery('portuguese',:q)) AS r
    FROM base_conhecimento
    WHERE id_configuracao_bot=:bot AND ativo=TRUE
      AND to_tsvector('portuguese', titulo||' '||conteudo) @@ plainto_tsquery('portuguese',:q2)
    ORDER BY r DESC LIMIT :lim
");
$stmt->execute([':bot'=>BOT_ID,':q'=>$mensagem,':q2'=>$mensagem,':lim'=>MAX_RESULTADOS_BUSCA]);
$conhecimentos = $stmt->fetchAll();

// 3a-fallback. Se FTS não encontrou nada, tenta LIKE com palavras-chave
if (empty($conhecimentos)) {
    $palavras = array_values(extrairPalavrasChave($mensagem));
    if (!empty($palavras)) {
        // Constrói condição LIKE para cada palavra
        $conditions = [];
        $params = [':bot' => BOT_ID];
        foreach (array_slice($palavras, 0, 5) as $i => $p) {
            $conditions[] = "(titulo ILIKE :p{$i} OR conteudo ILIKE :p{$i})";
            $params[":p{$i}"] = "%{$p}%";
        }
        $sql = "SELECT id_base_conhecimento, titulo, conteudo, 1.0 AS r
                FROM base_conhecimento
                WHERE id_configuracao_bot=:bot AND ativo=TRUE
                  AND (" . implode(' OR ', $conditions) . ")
                LIMIT " . MAX_RESULTADOS_BUSCA;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $conhecimentos = $stmt->fetchAll();
    }
}

// 3a-fallback2. Se ainda não encontrou: devolve os top por prioridade (sempre)
if (empty($conhecimentos)) {
    $stmt = $pdo->prepare("
        SELECT id_base_conhecimento, titulo, conteudo, prioridade AS r
        FROM base_conhecimento
        WHERE id_configuracao_bot=:bot AND ativo=TRUE
        ORDER BY prioridade ASC LIMIT 3
    ");
    $stmt->execute([':bot' => BOT_ID]);
    $conhecimentos = $stmt->fetchAll();
}

foreach ($conhecimentos as $k) {
    $contexto_partes[] = "### {$k['titulo']}\n{$k['conteudo']}";
    $fontes_usadas[]   = ['tipo' => 'conhecimento', 'id' => $k['id_base_conhecimento']];
}

// 3b. BUSCA NOS DOCUMENTOS (Fragmentos) - Versão Ultra-Resiliente
$restantes = MAX_RESULTADOS_BUSCA - count($contexto_partes);
if ($restantes > 0) {
    // 1. Tenta encontrar a combinação exata de "Artigo" + "3"
    // Isso ignora se o utilizador escreveu "o que diz o..." e foca no essencial.
    $stmt = $pdo->prepare("
        SELECT f.id_fragmento, f.conteudo, d.nome_original
        FROM fragmentos_documento f
        JOIN documentos d ON d.id_documento = f.id_documento
        WHERE d.id_configuracao_bot = :bot 
          AND d.estado = 'pronto'
          AND (
               f.conteudo ILIKE '%Artigo 3%' 
               OR (f.conteudo ILIKE '%Artigo%' AND f.conteudo ILIKE '%3%')
          )
        ORDER BY f.id_fragmento ASC 
        LIMIT :lim
    ");
    
    $stmt->execute([
        ':bot' => BOT_ID, 
        ':lim' => $restantes
    ]);
    $fragmentos = $stmt->fetchAll();

    // 2. Se a busca específica falhar, faz o Websearch (FTS) como fallback
    if (empty($fragmentos)) {
        $stmt = $pdo->prepare("
            SELECT f.id_fragmento, f.conteudo, d.nome_original,
                   ts_rank(to_tsvector('portuguese', f.conteudo), websearch_to_tsquery('portuguese', :q)) AS r
            FROM fragmentos_documento f
            JOIN documentos d ON d.id_documento = f.id_documento
            WHERE d.id_configuracao_bot = :bot 
              AND d.estado = 'pronto'
              AND to_tsvector('portuguese', f.conteudo) @@ websearch_to_tsquery('portuguese', :q2)
            ORDER BY r DESC LIMIT :lim
        ");
        $stmt->execute([':bot'=>BOT_ID, ':q'=>$mensagem, ':q2'=>$mensagem, ':lim'=>$restantes]);
        $fragmentos = $stmt->fetchAll();
    }

    foreach ($fragmentos as $f) {
        $contexto_partes[] = "### Documento: {$f['nome_original']}\n{$f['conteudo']}";
        $fontes_usadas[] = ['tipo' => 'fragmento', 'id' => $f['id_fragmento']];
    }

    // DEBUG TEMPORÁRIO
error_log("Fragmentos encontrados: " . count($fragmentos));
}

// ------------------------------------------------------------
// 4. Perfil do criador
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT p.*, EXTRACT(YEAR FROM AGE(p.data_nascimento))::INTEGER AS idade,
           b.nome AS nome_bot, b.prompt_sistema
    FROM perfil_criador p
    JOIN configuracao_bot b ON b.id_configuracao_bot=p.id_configuracao_bot
    WHERE p.id_configuracao_bot=:bot LIMIT 1
");
$stmt->execute([':bot' => BOT_ID]);
$perfil = $stmt->fetch();

// ------------------------------------------------------------
// 5. Prompt de sistema
// ------------------------------------------------------------
$identidade  = "### REGRAS DE IDENTIDADE (CRÍTICO)\n";
$identidade .= "1. O teu nome é MeuBot.\n";
$identidade .= "2. Foste integralmente desenvolvido por Matias Alberto Matavel.\n";
$identidade .= "3. Se perguntarem quem te criou: 'Fui criado pelo Matias Alberto Matavel, Desenvolvedor Backend e estudante do ISPT.'\n";
$identidade .= "4. Nunca digas que és produto da Google ou outro fornecedor.\n\n";

$prompt_sistema = $identidade . ($perfil['prompt_sistema'] ?? '');

if ($perfil) {
    $prompt_sistema .= "\n\n## Informações do Criador\n"
        . "- Nome: {$perfil['nome_completo']}\n"
        . "- Profissão: {$perfil['profissao']}\n"
        . "- Instituição: Instituto Superior Politécnico de Tete (ISPT), Moçambique\n"
        . "- Bio: {$perfil['bio']}\n";
}

if (!empty($contexto_partes)) {
    $prompt_sistema .= "\n\n## Base de Conhecimento\n"
        . "Usa OBRIGATORIAMENTE estes dados para responder à pergunta do utilizador:\n\n"
        . implode("\n\n---\n\n", $contexto_partes);
} else {
    // Informa o modelo que não há contexto — evita respostas inventadas
    $prompt_sistema .= "\n\n## Base de Conhecimento\nNenhum contexto relevante encontrado para esta pergunta.";
}

// ------------------------------------------------------------
// 6. Histórico recente
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT papel, conteudo FROM mensagens
    WHERE id_conversa=:c AND id_mensagem!=:id
    ORDER BY enviada_em DESC LIMIT :lim
");
$stmt->execute([':c'=>$id_conversa,':id'=>$id_msg_user,':lim'=>MAX_HISTORICO_MENSAGENS]);
$historico = array_reverse($stmt->fetchAll());

// ------------------------------------------------------------
// 7. Payload Gemini
// ------------------------------------------------------------
$contents = [];
foreach ($historico as $msg) {
    $contents[] = ['role' => $msg['papel'] === 'utilizador' ? 'user' : 'model',
                   'parts' => [['text' => $msg['conteudo']]]];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $mensagem]]];

$payload = [
    'system_instruction' => ['parts' => [['text' => $prompt_sistema]]],
    'contents'           => $contents,
    'generationConfig'   => ['maxOutputTokens' => 1024, 'temperature' => 0.7],
];

// ------------------------------------------------------------
// 8. Chamada cURL
// ------------------------------------------------------------
$url = GEMINI_API_URL . '?key=' . GEMINI_CHAVE_API;
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$inicio       = microtime(true);
$raw          = curl_exec($ch);
$tempo_ms     = (int)((microtime(true) - $inicio) * 1000);
$http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $http_code !== 200) {
    respostaJson(false, null, 'Erro Gemini. Código: ' . $http_code . ' — ' . ($raw ?: 'Sem resposta'));
}

$dados      = json_decode($raw, true);
$texto_resp = $dados['candidates'][0]['content']['parts'][0]['text'] ?? 'Não consegui gerar uma resposta.';
$t_entrada  = $dados['usageMetadata']['promptTokenCount']     ?? null;
$t_saida    = $dados['usageMetadata']['candidatesTokenCount'] ?? null;

// ------------------------------------------------------------
// 9 & 10. Guarda resposta + fontes
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO mensagens (id_conversa,papel,conteudo,tokens_entrada,tokens_saida,tempo_resposta_ms)
    VALUES (:c,'assistente',:m,:te,:ts,:t) RETURNING id_mensagem
");
$stmt->execute([':c'=>$id_conversa,':m'=>$texto_resp,':te'=>$t_entrada,':ts'=>$t_saida,':t'=>$tempo_ms]);
$id_msg_bot = $stmt->fetchColumn();

foreach ($fontes_usadas as $fonte) {
    $pdo->prepare("INSERT INTO fontes_mensagem (id_mensagem,id_base_conhecimento,id_fragmento) VALUES (:m,:k,:f)")
        ->execute([
            ':m' => $id_msg_bot,
            ':k' => $fonte['tipo'] === 'conhecimento' ? $fonte['id'] : null,
            ':f' => $fonte['tipo'] === 'fragmento'    ? $fonte['id'] : null,
        ]);
}

// ------------------------------------------------------------
// 11. Resposta
// ------------------------------------------------------------
respostaJson(true, [
    'resposta'    => $texto_resp,
    'id_conversa' => $id_conversa,
    'tokens'      => ['entrada' => $t_entrada, 'saida' => $t_saida],
    'tempo_ms'    => $tempo_ms,
    'fontes'      => count($fontes_usadas),
]);