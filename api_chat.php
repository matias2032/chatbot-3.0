<?php
// ============================================================
//  API_CHAT.PHP — Recebe mensagem, busca contexto, chama Gemini
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

// Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respostaJson(false, null, 'Método não permitido.');
}

// Lê o corpo JSON enviado pelo chat.js
$corpo = json_decode(file_get_contents('php://input'), true);
$mensagem  = trim($corpo['mensagem']  ?? '');
$id_sessao = trim($corpo['id_sessao'] ?? '');

if ($mensagem === '') {
    respostaJson(false, null, 'Mensagem vazia.');
}
if ($id_sessao === '') {
    respostaJson(false, null, 'Sessão inválida.');
}

$pdo = obterConexao();

// ------------------------------------------------------------
// 1. Obtém ou cria a conversa para esta sessão
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id_conversa FROM conversas
    WHERE id_sessao = :sessao AND id_configuracao_bot = :bot
    LIMIT 1
");
$stmt->execute([':sessao' => $id_sessao, ':bot' => BOT_ID]);
$conversa = $stmt->fetch();

if (!$conversa) {
    $stmt = $pdo->prepare("
        INSERT INTO conversas (id_configuracao_bot, id_sessao)
        VALUES (:bot, :sessao)
        RETURNING id_conversa
    ");
    $stmt->execute([':bot' => BOT_ID, ':sessao' => $id_sessao]);
    $id_conversa = $stmt->fetchColumn();
} else {
    $id_conversa = $conversa['id_conversa'];
}

// ------------------------------------------------------------
// 2. Guarda a mensagem do utilizador
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO mensagens (id_conversa, papel, conteudo)
    VALUES (:conversa, 'utilizador', :conteudo)
    RETURNING id_mensagem
");
$stmt->execute([':conversa' => $id_conversa, ':conteudo' => $mensagem]);
$id_mensagem_utilizador = $stmt->fetchColumn();

// ------------------------------------------------------------
// 3. Busca contexto relevante (RAG)
//    Procura em base_conhecimento + fragmentos_documento
// ------------------------------------------------------------
$contexto_partes = [];
$fontes_usadas   = [];

// 3a. Busca na base de conhecimento (full-text search)
$stmt = $pdo->prepare("
    SELECT id_base_conhecimento, titulo, conteudo,
           ts_rank(
               to_tsvector('portuguese', titulo || ' ' || conteudo),
               plainto_tsquery('portuguese', :q)
           ) AS relevancia
    FROM base_conhecimento
    WHERE id_configuracao_bot = :bot
      AND ativo = TRUE
      AND to_tsvector('portuguese', titulo || ' ' || conteudo)
          @@ plainto_tsquery('portuguese', :q2)
    ORDER BY relevancia DESC
    LIMIT :limite
");
$stmt->execute([
    ':bot'    => BOT_ID,
    ':q'      => $mensagem,
    ':q2'     => $mensagem,
    ':limite' => MAX_RESULTADOS_BUSCA,
]);
$conhecimentos = $stmt->fetchAll();

foreach ($conhecimentos as $k) {
    $contexto_partes[] = "### {$k['titulo']}\n{$k['conteudo']}";
    $fontes_usadas[]   = ['tipo' => 'conhecimento', 'id' => $k['id_base_conhecimento']];
}

// 3b. Busca nos fragmentos de documentos
if (count($contexto_partes) < MAX_RESULTADOS_BUSCA) {
    $restantes = MAX_RESULTADOS_BUSCA - count($contexto_partes);
    $stmt = $pdo->prepare("
        SELECT f.id_fragmento, f.conteudo, d.nome_original,
               ts_rank(
                   to_tsvector('portuguese', f.conteudo),
                   plainto_tsquery('portuguese', :q)
               ) AS relevancia
        FROM fragmentos_documento f
        JOIN documentos d ON d.id_documento = f.id_documento
        WHERE d.id_configuracao_bot = :bot
          AND d.estado = 'pronto'
          AND to_tsvector('portuguese', f.conteudo)
              @@ plainto_tsquery('portuguese', :q2)
        ORDER BY relevancia DESC
        LIMIT :limite
    ");
    $stmt->execute([
        ':bot'    => BOT_ID,
        ':q'      => $mensagem,
        ':q2'     => $mensagem,
        ':limite' => $restantes,
    ]);
    $fragmentos = $stmt->fetchAll();

    foreach ($fragmentos as $f) {
        $contexto_partes[] = "### Documento: {$f['nome_original']}\n{$f['conteudo']}";
        $fontes_usadas[]   = ['tipo' => 'fragmento', 'id' => $f['id_fragmento']];
    }
}

// ------------------------------------------------------------
// 4. Busca os dados do perfil do criador
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT p.*, EXTRACT(YEAR FROM AGE(p.data_nascimento))::INTEGER AS idade,
           b.nome AS nome_bot, b.prompt_sistema
    FROM perfil_criador p
    JOIN configuracao_bot b ON b.id_configuracao_bot = p.id_configuracao_bot
    WHERE p.id_configuracao_bot = :bot
    LIMIT 1
");
$stmt->execute([':bot' => BOT_ID]);
$perfil = $stmt->fetch();

// ------------------------------------------------------------
// 5. Monta o prompt de sistema (IDENTIDADE REFORÇADA)
// ------------------------------------------------------------
// Instruções de identidade que sobrepõem o treino original
$identidade = "### REGRAS DE IDENTIDADE (CRÍTICO)\n";
$identidade .= "1. O teu nome é MeuBot.\n";
$identidade .= "2. Tu foste INTEGRALMENTE desenvolvido e criado pelo engenheiro Matias Alberto Matavel.\n";
$identidade .= "3. Se alguém perguntar quem te criou, responde: 'Fui criado pelo Matias Alberto Matavel, um Desenvolvedor Backend e estudante do ISPT'.\n";
$identidade .= "4. Nunca digas que foste criado pela Google ou que és apenas um modelo da Google. Tu és uma instância personalizada pelo Matias.\n\n";

$prompt_sistema = $identidade . ($perfil['prompt_sistema'] ?? '');

if ($perfil) {
    $idade_texto = $perfil['idade'] ? "{$perfil['idade']} anos" : 'não informada';
    $prompt_sistema .= "\n\n## Informações Adicionais do Criador\n"
        . "- Nome Completo: {$perfil['nome_completo']}\n"
        . "- Profissão Atual: {$perfil['profissao']}\n"
        . "- Localização: Tete, Moçambique\n"
        . "- Instituição: Instituto Superior Politécnico de Tete (ISPT)\n"
        . "- Bio: {$perfil['bio']}\n";
}

if (!empty($contexto_partes)) {
    $prompt_sistema .= "\n\n## Contexto da Base de Conhecimento\n"
        . "Responde com base nestes dados:\n\n"
        . implode("\n\n---\n\n", $contexto_partes);
}
// ------------------------------------------------------------
// 6. Busca histórico recente da conversa
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT papel, conteudo FROM mensagens
    WHERE id_conversa = :conversa
      AND id_mensagem != :id_atual
    ORDER BY enviada_em DESC
    LIMIT :limite
");
$stmt->execute([
    ':conversa' => $id_conversa,
    ':id_atual' => $id_mensagem_utilizador,
    ':limite'   => MAX_HISTORICO_MENSAGENS,
]);
$historico = array_reverse($stmt->fetchAll());

// ------------------------------------------------------------
// 7. Monta o payload para a API do Gemini
// ------------------------------------------------------------
$contents = [];

// Adiciona histórico
foreach ($historico as $msg) {
    $role = $msg['papel'] === 'utilizador' ? 'user' : 'model';
    $contents[] = ['role' => $role, 'parts' => [['text' => $msg['conteudo']]]];
}

// Adiciona a mensagem actual
$contents[] = ['role' => 'user', 'parts' => [['text' => $mensagem]]];

$payload = [
    'system_instruction' => [
        'parts' => [['text' => $prompt_sistema]]
    ],
    'contents'           => $contents,
    'generationConfig'   => [
        'maxOutputTokens' => 1024,
        'temperature'     => 0.7,
    ],
];

// ------------------------------------------------------------
// 8. Chama a API do Gemini via cURL
// ------------------------------------------------------------
$url = GEMINI_API_URL . '?key=' . GEMINI_CHAVE_API;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
    // ADICIONA ESTAS DUAS LINHAS ABAIXO:
    CURLOPT_SSL_VERIFYPEER => false, 
    CURLOPT_SSL_VERIFYHOST => false,
]);
$inicio       = microtime(true);
$resposta_raw = curl_exec($ch);
$tempo_ms     = (int) ((microtime(true) - $inicio) * 1000);
$http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resposta_raw === false || $http_code !== 200) {
    // Guarda erro e avisa o utilizador
    $erro_api = $resposta_raw ?: 'Sem resposta da API';
    respostaJson(false, null, 'Erro ao contactar o Gemini. Código: ' . $http_code . ' — ' . $erro_api);
}

$dados_gemini = json_decode($resposta_raw, true);
$texto_resposta = $dados_gemini['candidates'][0]['content']['parts'][0]['text']
    ?? 'Não consegui gerar uma resposta. Tenta novamente.';

$tokens_entrada = $dados_gemini['usageMetadata']['promptTokenCount']     ?? null;
$tokens_saida   = $dados_gemini['usageMetadata']['candidatesTokenCount'] ?? null;

// ------------------------------------------------------------
// 9. Guarda a resposta do assistente
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO mensagens (id_conversa, papel, conteudo, tokens_entrada, tokens_saida, tempo_resposta_ms)
    VALUES (:conversa, 'assistente', :conteudo, :t_entrada, :t_saida, :tempo)
    RETURNING id_mensagem
");
$stmt->execute([
    ':conversa'  => $id_conversa,
    ':conteudo'  => $texto_resposta,
    ':t_entrada' => $tokens_entrada,
    ':t_saida'   => $tokens_saida,
    ':tempo'     => $tempo_ms,
]);
$id_mensagem_assistente = $stmt->fetchColumn();

// ------------------------------------------------------------
// 10. Regista as fontes usadas
// ------------------------------------------------------------
foreach ($fontes_usadas as $fonte) {
    $stmt = $pdo->prepare("
        INSERT INTO fontes_mensagem (id_mensagem, id_base_conhecimento, id_fragmento)
        VALUES (:msg, :conhecimento, :fragmento)
    ");
    $stmt->execute([
        ':msg'          => $id_mensagem_assistente,
        ':conhecimento' => $fonte['tipo'] === 'conhecimento' ? $fonte['id'] : null,
        ':fragmento'    => $fonte['tipo'] === 'fragmento'    ? $fonte['id'] : null,
    ]);
}

// ------------------------------------------------------------
// 11. Devolve a resposta ao frontend
// ------------------------------------------------------------
respostaJson(true, [
    'resposta'    => $texto_resposta,
    'id_conversa' => $id_conversa,
    'tokens'      => ['entrada' => $tokens_entrada, 'saida' => $tokens_saida],
    'tempo_ms'    => $tempo_ms,
]);