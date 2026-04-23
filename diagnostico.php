<?php
// diagnostico.php — APAGAR DEPOIS DE USAR
// Acede via: http://localhost/teu-projecto/diagnostico.php
error_reporting(E_ALL); ini_set('display_errors', 1);
echo "<pre>\n=== DIAGNÓSTICO PDF ===\n\n";

$autoload = __DIR__ . '/vendor/autoload.php';
echo "autoload existe: " . (file_exists($autoload) ? "SIM" : "NÃO — corre: composer install") . "\n";
if (file_exists($autoload)) require_once $autoload;

$existe = class_exists('\Smalot\PdfParser\Parser');
echo "Smalot\\PdfParser\\Parser: " . ($existe ? "ENCONTRADA ✓" : "NÃO ENCONTRADA ✗") . "\n";
if (!$existe) { echo "\nSOLUÇÃO: composer require smalot/pdfparser\n</pre>"; exit; }

$pasta = __DIR__ . '/uploads/';
$pdfs  = glob($pasta . '*.pdf') ?: [];
if (empty($pdfs)) {
    echo "\nNenhum PDF em uploads/. A criar PDF mínimo de teste...\n";
    if (!is_dir($pasta)) mkdir($pasta, 0755, true);
    $p = $pasta . 'diagnostico_teste.pdf';
    file_put_contents($p, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n4 0 obj<</Length 44>>\nstream\nBT /F1 12 Tf 100 700 Td (Teste diagnostico) Tj ET\nendstream\nendobj\n5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\nxref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000274 00000 n \n0000000368 00000 n \ntrailer<</Size 6/Root 1 0 R>>\nstartxref\n441\n%%EOF");
    $pdfs = [$p];
}

$caminho = $pdfs[0];
echo "\nTeste com: {$caminho}\n";
echo "Tamanho: " . number_format(filesize($caminho)) . " bytes\n\n";

try {
    $config = new \Smalot\PdfParser\Config();
    $config->setRetainImageContent(false);
    $parser  = new \Smalot\PdfParser\Parser([], $config);
    $pdf     = $parser->parseFile($caminho);
    $paginas = $pdf->getPages();
    echo "Páginas: " . count($paginas) . "\n";
    $texto = '';
    foreach ($paginas as $i => $pg) {
        $t = $pg->getText();
        echo "  Pág " . ($i+1) . ": " . strlen($t) . " chars\n";
        $texto .= $t;
    }
    $total = strlen(trim($texto));
    echo "\nTotal: {$total} chars\n--- Amostra (600 chars) ---\n";
    echo htmlspecialchars(mb_substr(trim($texto), 0, 600)) . "\n---\n\n";
    echo $total > 10 ? "✓ SMALOT FUNCIONA\n" : "✗ PDF sem texto — provavelmente scanned/imagem\n";
} catch (\Throwable $e) {
    echo "✗ ERRO: " . $e->getMessage() . "\n   em " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== SISTEMA ===\n";
echo "PHP: " . PHP_VERSION . " | OS: " . PHP_OS . " | Sep: '" . DIRECTORY_SEPARATOR . "'\n";
echo "shell_exec: " . (function_exists('shell_exec') ? 'disponível' : 'desactivado') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "s\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "\n=== FIM ===\n</pre>";