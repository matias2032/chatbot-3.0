<?php
// ============================================================
//  CONFIGURACAO.PHP — Adaptado para Google Gemini AI
// ============================================================

// ------------------------------------------------------------
// AMBIENTE
// Define como 'producao' no servidor (Render)
// ------------------------------------------------------------
if (!defined('AMBIENTE')) {
    define('AMBIENTE', (getenv('RENDER') ? 'producao' : 'desenvolvimento'));
}

// ------------------------------------------------------------
// GOOGLE GEMINI AI
// Obtém a tua chave em: https://aistudio.google.com
// No Render, adiciona uma Environment Variable chamada GEMINI_API_KEY
// ------------------------------------------------------------
// 1. Tentar obter do ambiente (Render)
$chave = getenv('GEMINI_API_KEY');

// 2. Se for localhost e não houver variável de ambiente, tenta ler o .env
if (!$chave && AMBIENTE === 'desenvolvimento') {
    $caminhoEnv = __DIR__ . '/.env';
    if (file_exists($caminhoEnv)) {
        $linhas = file($caminhoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($linhas as $linha) {
            // Procura a linha que começa com GEMINI_API_KEY=
            if (strpos($linha, 'GEMINI_API_KEY=') === 0) {
                $chave = trim(str_replace('GEMINI_API_KEY=', '', $linha));
            }
        }
    }
}

define('GEMINI_CHAVE_API', $chave ?: 'CHAVE_NAO_CONFIGURADA');

// Modelo Flash é o melhor custo-benefício (e tem tier gratuito amplo)
define('GEMINI_MODELO', 'gemini-2.5-flash-lite');

// Mudamos de v1beta para v1 e garantimos o formato absoluto do modelo
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODELO . ':generateContent');
// ------------------------------------------------------------
// BOT — ID fixo do teu bot na tabela configuracao_bot
// ------------------------------------------------------------
define('BOT_ID', getenv('BOT_ID'));

// ------------------------------------------------------------
// UPLOADS
// ------------------------------------------------------------
define('PASTA_UPLOADS', __DIR__ . '/uploads/');
define('TAMANHO_MAXIMO_MB', 10);
define('TAMANHO_MAXIMO_BYTES', TAMANHO_MAXIMO_MB * 1024 * 1024);
define('TIPOS_PERMITIDOS', ['application/pdf', 'text/plain']);

// ------------------------------------------------------------
// RAG (Busca de Conhecimento)
// ------------------------------------------------------------
define('CHUNK_TAMANHO', 800);
define('CHUNK_SOBREPOSICAO', 100);
define('MAX_RESULTADOS_BUSCA', 5);
define('MAX_HISTORICO_MENSAGENS', 10);

// ------------------------------------------------------------
// SEGURANÇA
// ------------------------------------------------------------
define('ADMIN_SENHA', getenv('ADMIN_PASSWORD') ?: 'admin123');

// ------------------------------------------------------------
// FUNÇÕES AUXILIARES
// ------------------------------------------------------------
function definirCabecalhosJson(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

function respostaJson(bool $sucesso, mixed $dados = null, string $erro = ''): void {
    definirCabecalhosJson();
    echo json_encode([
        'sucesso' => $sucesso,
        'dados'   => $dados,
        'erro'    => $erro,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Criar pasta de uploads se não existir
if (!is_dir(PASTA_UPLOADS)) {
    mkdir(PASTA_UPLOADS, 0755, true);
}