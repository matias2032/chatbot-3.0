<?php
// testar_ocr.php — APAGAR DEPOIS DE USAR
// Testa o OCR Gemini directamente no PDF problemático
// Acede: http://localhost/chatbot/testar_ocr.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(180);

require_once __DIR__ . '/configuracao.php';

echo "<pre>\n=== TESTE OCR GEMINI ===\n\n";

// Pega no primeiro PDF de uploads/
$pdfs = glob(__DIR__ . '/uploads/*.pdf') ?: [];
if (empty($pdfs)) { echo "Nenhum PDF em uploads/\n</pre>"; exit; }

$caminho = $pdfs[0];
$tamanho = filesize($caminho);
echo "Ficheiro: " . basename($caminho) . "\n";
echo "Tamanho: " . number_format($tamanho) . " bytes (" . round($tamanho/1024/1024, 1) . " MB)\n\n";

if ($tamanho > 20 * 1024 * 1024) {
    echo "✗ PDF demasiado grande (> 20 MB). Divide-o antes.\n</pre>";
    exit;
}

echo "A enviar para Gemini (pode demorar 20-60s)...\n\n";
flush();

$pdf_base64 = base64_encode(file_get_contents($caminho));

$payload = [
    'contents' => [[
        'role'  => 'user',
        'parts' => [
            ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $pdf_base64]],
            ['text' => 'Transcreve TODO o texto deste documento PDF, página por página. Mantém a estrutura original. Não adiciones comentários — apenas o texto puro.'],
        ],
    ]],
    'generationConfig' => ['maxOutputTokens' => 8192, 'temperature' => 0.1],
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODELO . ':generateContent?key=' . GEMINI_CHAVE_API;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

$inicio    = microtime(true);
$raw       = curl_exec($ch);
$tempo     = round(microtime(true) - $inicio, 1);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: {$http_code} | Tempo: {$tempo}s\n\n";

if ($http_code !== 200) {
    echo "✗ ERRO API:\n";
    $err = json_decode($raw, true);
    echo ($err['error']['message'] ?? $raw) . "\n</pre>";
    exit;
}

$dados  = json_decode($raw, true);
$texto  = $dados['candidates'][0]['content']['parts'][0]['text'] ?? '';
$reason = $dados['candidates'][0]['finishReason'] ?? 'UNKNOWN';
$tokens = $dados['usageMetadata']['candidatesTokenCount'] ?? 0;

echo "finishReason: {$reason}\n";
echo "Tokens gerados: {$tokens}\n";
echo "Chars extraídos: " . strlen($texto) . "\n\n";

if (strlen(trim($texto)) > 20) {
    echo "✓ OCR FUNCIONOU!\n\n";
    echo "--- Primeiros 1000 chars ---\n";
    echo htmlspecialchars(mb_substr($texto, 0, 1000)) . "\n---\n";
} else {
    echo "✗ Sem texto. Resposta da API:\n";
    echo htmlspecialchars(json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\n";
}

echo "\n=== FIM ===\n</pre>";