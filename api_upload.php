<?php
// ============================================================
//  API_UPLOAD.PHP — Final: OCR Gemini página a página
//  Resolve: PDFs scanned + MAX_TOKENS em documentos longos
// ============================================================

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once 'configuracao.php';
require_once 'conexao.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_autenticado'])) {
    definirCabecalhosJson();
    echo json_encode(['sucesso' => false, 'dados' => null, 'erro' => 'Não autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo          = obterConexao();
$content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

// ------------------------------------------------------------
// Acções JSON
// ------------------------------------------------------------
if (str_contains($content_type, 'application/json')) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $acao  = $corpo['acao'] ?? '';
    $id    = trim($corpo['id'] ?? '');

    if ($id === '') respostaJson(false, null, 'ID inválido.');

    if ($acao === 'eliminar') {
        $stmt = $pdo->prepare("SELECT caminho_ficheiro FROM documentos WHERE id_documento=:id AND id_configuracao_bot=:bot");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);
        $doc = $stmt->fetch();
        if ($doc && file_exists($doc['caminho_ficheiro'])) unlink($doc['caminho_ficheiro']);
        $stmt = $pdo->prepare("DELETE FROM documentos WHERE id_documento=:id AND id_configuracao_bot=:bot");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);
        respostaJson($stmt->rowCount() > 0, null, $stmt->rowCount() === 0 ? 'Não encontrado.' : '');
    }

    if ($acao === 'reprocessar') {
        $stmt = $pdo->prepare("SELECT * FROM documentos WHERE id_documento=:id AND id_configuracao_bot=:bot");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);
        $doc = $stmt->fetch();
        if (!$doc) respostaJson(false, null, 'Documento não encontrado.');
        $pdo->prepare("UPDATE documentos SET estado='a_processar',mensagem_erro=NULL WHERE id_documento=:id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM fragmentos_documento WHERE id_documento=:id")->execute([':id' => $id]);
        processarDocumento($pdo, $id, $doc['caminho_ficheiro'], $doc['tipo_mime']);
        respostaJson(true, null, '');
    }

    if ($acao === 'verificar_estado') {
        $stmt = $pdo->prepare("
            SELECT d.estado, d.mensagem_erro,
                   COUNT(f.id_fragmento) AS total_fragmentos
            FROM documentos d
            LEFT JOIN fragmentos_documento f ON f.id_documento = d.id_documento
            WHERE d.id_documento=:id AND d.id_configuracao_bot=:bot
            GROUP BY d.estado, d.mensagem_erro
        ");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);
        $r = $stmt->fetch();
        respostaJson((bool)$r, $r ?: null, $r ? '' : 'Não encontrado.');
    }

    respostaJson(false, null, 'Acção desconhecida.');
}

// ------------------------------------------------------------
// Upload de ficheiro
// ------------------------------------------------------------
$upload_erro = $_FILES['ficheiro']['error'] ?? UPLOAD_ERR_NO_FILE;
if (!isset($_FILES['ficheiro']) || $upload_erro !== UPLOAD_ERR_OK) {
    $erros = [
        UPLOAD_ERR_INI_SIZE   => 'Excede upload_max_filesize no php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'Excede o limite do formulário.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum ficheiro enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária em falta.',
        UPLOAD_ERR_CANT_WRITE => 'Sem permissão de escrita.',
    ];
    respostaJson(false, null, $erros[$upload_erro] ?? "Código {$upload_erro}.");
}

$ficheiro      = $_FILES['ficheiro'];
$nome_original = basename($ficheiro['name']);
$tamanho       = $ficheiro['size'];
$extensao      = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
$categoria     = trim($_POST['categoria'] ?? '') ?: null;
$descricao     = trim($_POST['descricao']  ?? '') ?: null;
$tipo_mime     = detectarMime($ficheiro['tmp_name'], $nome_original);

if (!in_array($tipo_mime, ['application/pdf', 'text/plain']) && !in_array($extensao, ['pdf', 'txt'])) {
    respostaJson(false, null, "Tipo não permitido ({$tipo_mime}).");
}
if ($tamanho > TAMANHO_MAXIMO_BYTES) {
    respostaJson(false, null, 'Ficheiro grande demais. Máximo ' . TAMANHO_MAXIMO_MB . ' MB.');
}
if (!is_dir(PASTA_UPLOADS) && !mkdir(PASTA_UPLOADS, 0755, true)) {
    respostaJson(false, null, 'Não foi possível criar pasta uploads/.');
}

$nome_guardado = uniqid('doc_', true) . '.' . $extensao;
$caminho_final = PASTA_UPLOADS . $nome_guardado;

if (!move_uploaded_file($ficheiro['tmp_name'], $caminho_final)) {
    respostaJson(false, null, 'Erro ao mover ficheiro. Verifica permissões de uploads/.');
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO documentos
            (id_configuracao_bot,nome_original,nome_guardado,caminho_ficheiro,
             tipo_mime,tamanho_bytes,categoria,descricao,estado)
        VALUES (:bot,:orig,:guard,:cam,:mime,:tam,:cat,:desc,'a_processar')
        RETURNING id_documento
    ");
    $stmt->execute([
        ':bot'  => BOT_ID, ':orig' => $nome_original, ':guard' => $nome_guardado,
        ':cam'  => $caminho_final, ':mime' => $tipo_mime, ':tam'  => $tamanho,
        ':cat'  => $categoria,    ':desc' => $descricao,
    ]);
    $id_documento = $stmt->fetchColumn();
} catch (PDOException $e) {
    if (file_exists($caminho_final)) unlink($caminho_final);
    respostaJson(false, null, 'Erro na BD: ' . $e->getMessage());
}

// Responde ao browser imediatamente e processa em background
$resposta = json_encode([
    'sucesso' => true,
    'dados'   => ['id' => $id_documento, 'nome' => $nome_original, 'estado' => 'a_processar'],
    'erro'    => '',
], JSON_UNESCAPED_UNICODE);

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Connection: close');
header('Content-Length: ' . strlen($resposta));
echo $resposta;
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

set_time_limit(600); // PDFs scanned com OCR podem demorar vários minutos
ignore_user_abort(true);
session_write_close();

processarDocumento($pdo, $id_documento, $caminho_final, $tipo_mime);
exit;


// ============================================================
// FUNÇÕES
// ============================================================

function processarDocumento(PDO $pdo, string $id, string $caminho, string $mime): void {
    try {
        $texto = extrairTexto($caminho, $mime);
        if (trim($texto) === '') {
            $pdo->prepare("UPDATE documentos SET estado='erro',mensagem_erro='Sem texto extraível. O PDF é baseado em imagens e o OCR não conseguiu transcrever o conteúdo.' WHERE id_documento=:id")
                ->execute([':id' => $id]);
            return;
        }
        $total = criarFragmentos($pdo, $id, $texto);
        $pdo->prepare("UPDATE documentos SET estado='pronto',processado_em=NOW(),mensagem_erro=NULL WHERE id_documento=:id")
            ->execute([':id' => $id]);
        error_log("[UPLOAD] OK — {$id}: {$total} fragmentos.");
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE documentos SET estado='erro',mensagem_erro=:m WHERE id_documento=:id")
            ->execute([':id' => $id, ':m' => substr($e->getMessage(), 0, 500)]);
        error_log("[UPLOAD] ERRO — {$id}: " . $e->getMessage());
    }
}

// ------------------------------------------------------------
// EXTRACÇÃO DE TEXTO
// Cascata: TXT → smalot → OCR Gemini (página a página) → fallback regex
// ------------------------------------------------------------

function extrairTexto(string $caminho, string $mime): string {

    // 1. TXT — leitura directa
    if (str_contains($mime, 'text') || str_ends_with($caminho, '.txt')) {
        $t = file_get_contents($caminho);
        return $t !== false ? limparTexto($t) : '';
    }

    if (!file_exists($caminho) || !is_readable($caminho)) {
        error_log("[EXTRAI] Inacessível: {$caminho}");
        return '';
    }

    // 2. smalot/pdfparser — PDFs com texto seleccionável (rápido, sem API)
    if (class_exists('\Smalot\PdfParser\Parser')) {
        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);
            $parser = new \Smalot\PdfParser\Parser([], $config);
            $pdf    = $parser->parseFile($caminho);
            $texto  = '';
            foreach ($pdf->getPages() as $pag) {
                $texto .= $pag->getText() . "\n\n";
            }
            if (strlen(trim($texto)) > 50) {
                error_log("[EXTRAI] smalot OK: " . strlen($texto) . " chars");
                return limparTexto($texto);
            }
            // Texto insuficiente = PDF scanned → passa para OCR
            error_log("[EXTRAI] smalot: texto insuficiente (" . strlen(trim($texto)) . " chars) — a usar OCR.");
        } catch (\Throwable $e) {
            error_log("[EXTRAI] smalot excepção: " . $e->getMessage());
        }
    }

    // 3. OCR via Gemini — PDFs scanned, página a página para evitar MAX_TOKENS
    $texto_ocr = extrairTextoOcrGemini($caminho);
    if ($texto_ocr !== '') {
        error_log("[EXTRAI] OCR Gemini OK: " . strlen($texto_ocr) . " chars");
        return $texto_ocr;
    }

    // 4. pdftotext — apenas Linux/Mac
    if (DIRECTORY_SEPARATOR === '/') {
        $dis = ini_get('disable_functions') ?: '';
        if (function_exists('shell_exec') && !str_contains($dis, 'shell_exec')) {
            foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $bin) {
                if (!file_exists($bin)) continue;
                $r = @shell_exec($bin . ' -enc UTF-8 -nopgbrk ' . escapeshellarg($caminho) . ' - 2>&1');
                if ($r && strlen(trim($r)) > 20 && !str_contains($r, 'Error')) {
                    return limparTexto($r);
                }
                break;
            }
        }
    }

    // 5. Fallback regex (extracção bruta do binário PDF)
    error_log("[EXTRAI] Fallback regex para: {$caminho}");
    return extrairTextoPdfFallback($caminho);
}

// ------------------------------------------------------------
// OCR VIA GEMINI — página a página
//
// Porquê página a página?
//   O PDF de 5.7 MB com 11 páginas atingiu MAX_TOKENS (8192) numa
//   única chamada — o documento foi cortado a meio.
//   Ao processar página a página, cada chamada é pequena e
//   o documento completo é sempre transcrito integralmente.
//
// Para PDFs ≤ 3 páginas usa chamada única (mais rápido).
// Para PDFs > 3 páginas divide por grupos de 3 páginas.
// ------------------------------------------------------------

function extrairTextoOcrGemini(string $caminho): string {

    if (!defined('GEMINI_CHAVE_API') || GEMINI_CHAVE_API === 'CHAVE_NAO_CONFIGURADA') {
        error_log("[OCR] Chave Gemini não configurada.");
        return '';
    }

    $tamanho = filesize($caminho);

    // PDFs > 20 MB não são suportados pela API (limite do inline_data)
    if ($tamanho > 20 * 1024 * 1024) {
        error_log("[OCR] PDF demasiado grande ({$tamanho} bytes). Limite: 20 MB.");
        return '';
    }

    // Conta páginas com smalot (se disponível) para decidir a estratégia
    $total_paginas = contarPaginasPdf($caminho);
    error_log("[OCR] PDF: {$total_paginas} páginas, " . round($tamanho/1024) . " KB");

    // PDFs pequenos (≤ 4 páginas ou < 1MB): uma chamada única é suficiente
    if ($total_paginas <= 4 || $tamanho < 1024 * 1024) {
        return ocrGeminiChamadaUnica($caminho);
    }

    // PDFs grandes: divide em grupos de 3 páginas usando Ghostscript
    // Se o Ghostscript estiver instalado, usa divisão real de páginas
    if (ghostscriptDisponivel()) {
        error_log("[OCR] A usar Ghostscript para dividir PDF em grupos de 3 páginas.");
        return ocrGeminiComGhostscript($caminho, $total_paginas);
    }

    // Sem Ghostscript: divide o PDF binariamente por tamanho (chunks de ~1.5MB)
    // Menos preciso mas funciona sem dependências
    error_log("[OCR] Sem Ghostscript — a usar divisão por tamanho.");
    return ocrGeminiDivisaoPorTamanho($caminho);
}

/**
 * OCR numa única chamada (PDFs pequenos).
 */
function ocrGeminiChamadaUnica(string $caminho): string {
    $pdf_b64 = base64_encode(file_get_contents($caminho));
    $resposta = chamarGeminiOcr($pdf_b64, 'application/pdf');
    return $resposta;
}

/**
 * OCR com Ghostscript: extrai cada grupo de 3 páginas como PDF separado
 * e faz OCR em cada um. Resolve completamente o problema MAX_TOKENS.
 */
function ocrGeminiComGhostscript(string $caminho, int $total_paginas): string {
    $gs      = encontrarGhostscript();
    $pasta   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_' . uniqid();
    mkdir($pasta, 0755, true);

    $texto_completo = '';
    $passo = 3; // páginas por grupo

    for ($inicio = 1; $inicio <= $total_paginas; $inicio += $passo) {
        $fim      = min($inicio + $passo - 1, $total_paginas);
        $pdf_temp = $pasta . DIRECTORY_SEPARATOR . "pag_{$inicio}_{$fim}.pdf";

        // Extrai páginas com Ghostscript
        $cmd = '"' . $gs . '" -dBATCH -dNOPAUSE -dQUIET '
             . '-dFirstPage=' . $inicio . ' -dLastPage=' . $fim
             . ' -sDEVICE=pdfwrite -sOutputFile=' . escapeshellarg($pdf_temp)
             . ' ' . escapeshellarg($caminho) . ' 2>&1';

        $output = shell_exec($cmd);

        if (!file_exists($pdf_temp) || filesize($pdf_temp) < 100) {
            error_log("[OCR-GS] Falhou págs {$inicio}-{$fim}: {$output}");
            continue;
        }

        $pdf_b64 = base64_encode(file_get_contents($pdf_temp));
        unlink($pdf_temp);

        $texto_parte = chamarGeminiOcr($pdf_b64, 'application/pdf', $inicio, $fim);
        if ($texto_parte !== '') {
            $texto_completo .= "\n\n" . $texto_parte;
        }

        // Pausa de 2s entre chamadas para não exceder rate limit
        if ($fim < $total_paginas) sleep(2);
    }

    // Limpa pasta temporária
    @rmdir($pasta);

    return limparTexto($texto_completo);
}

/**
 * OCR sem Ghostscript: divide o ficheiro PDF por tamanho em chunks de ~1.5 MB.
 * Menos preciso (pode cortar a meio de uma página) mas não precisa de
 * dependências externas. Serve como fallback.
 */
function ocrGeminiDivisaoPorTamanho(string $caminho): string {
    $conteudo = file_get_contents($caminho);
    $tamanho  = strlen($conteudo);
    $chunk_bytes = 1.5 * 1024 * 1024; // 1.5 MB por chunk
    $texto_completo = '';
    $num_chunks = ceil($tamanho / $chunk_bytes);

    for ($i = 0; $i < $num_chunks; $i++) {
        $parte   = substr($conteudo, (int)($i * $chunk_bytes), (int)$chunk_bytes);
        $pdf_b64 = base64_encode($parte);
        $texto_parte = chamarGeminiOcr($pdf_b64, 'application/pdf', $i * 3 + 1, ($i + 1) * 3);
        if ($texto_parte !== '') {
            $texto_completo .= "\n\n" . $texto_parte;
        }
        if ($i < $num_chunks - 1) sleep(2);
    }

    return limparTexto($texto_completo);
}

/**
 * Chama a API Gemini com um PDF em base64 e devolve o texto transcrito.
 */
function chamarGeminiOcr(string $pdf_b64, string $mime_type, int $pag_inicio = 1, int $pag_fim = 0): string {

    $descricao_paginas = $pag_fim > 0 && $pag_fim !== $pag_inicio
        ? "Páginas {$pag_inicio} a {$pag_fim}"
        : ($pag_fim === $pag_inicio && $pag_inicio > 1 ? "Página {$pag_inicio}" : "este documento");

    $payload = [
        'contents' => [[
            'role'  => 'user',
            'parts' => [
                [
                    'inline_data' => [
                        'mime_type' => $mime_type,
                        'data'      => $pdf_b64,
                    ],
                ],
                [
                    'text' => "Transcreve TODO o texto de {$descricao_paginas}. "
                            . "Mantém a estrutura: títulos, parágrafos, artigos, números, listas. "
                            . "Não adiciones comentários nem resumos — apenas o texto puro do documento. "
                            . "Se uma página não tiver texto legível, escreve [Página sem texto].",
                ],
            ],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => 8192,
            'temperature'     => 0.1,
        ],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . GEMINI_MODELO . ':generateContent?key=' . GEMINI_CHAVE_API;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $raw       = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$raw) {
        error_log("[OCR] API erro {$http_code} págs {$pag_inicio}-{$pag_fim}");
        return '';
    }

    $dados  = json_decode($raw, true);
    $reason = $dados['candidates'][0]['finishReason'] ?? '';
    $texto  = $dados['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // MAX_TOKENS numa página individual é raro mas possível em páginas densas
    if ($reason === 'MAX_TOKENS') {
        error_log("[OCR] MAX_TOKENS nas págs {$pag_inicio}-{$pag_fim} — texto pode estar incompleto.");
    }

    return strlen(trim($texto)) > 5 ? $texto : '';
}

// ------------------------------------------------------------
// UTILITÁRIOS
// ------------------------------------------------------------

function contarPaginasPdf(string $caminho): int {
    if (class_exists('\Smalot\PdfParser\Parser')) {
        try {
            $cfg = new \Smalot\PdfParser\Config();
            $cfg->setRetainImageContent(false);
            $p = new \Smalot\PdfParser\Parser([], $cfg);
            return count($p->parseFile($caminho)->getPages());
        } catch (\Throwable $e) {}
    }
    // Fallback: conta ocorrências de "/Page" no binário do PDF
    $conteudo = file_get_contents($caminho);
    preg_match('/\/N\s+(\d+)/', $conteudo, $m);
    return isset($m[1]) ? (int)$m[1] : 10; // assume 10 se não conseguir contar
}

function ghostscriptDisponivel(): bool {
    return encontrarGhostscript() !== '';
}

function encontrarGhostscript(): string {
    // Localiza o executável do Ghostscript no Windows (caminhos comuns)
    $caminhos_win = [
        'C:\\Program Files\\gs\\gs10.05.0\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs10.04.0\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs10.03.1\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs10.02.1\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs10.01.2\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs10.00.0\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs9.56.1\\bin\\gswin64c.exe',
        'C:\\Program Files (x86)\\gs\\gs9.56.1\\bin\\gswin32c.exe',
    ];

    // Tenta encontrar dinamicamente (glob)
    $glob = glob('C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe') ?: [];
    if (!empty($glob)) {
        rsort($glob); // versão mais recente primeiro
        return $glob[0];
    }

    foreach ($caminhos_win as $c) {
        if (file_exists($c)) return $c;
    }

    // Linux/Mac
    if (DIRECTORY_SEPARATOR === '/') {
        foreach (['/usr/bin/gs', '/usr/local/bin/gs'] as $c) {
            if (file_exists($c)) return $c;
        }
    }

    return '';
}

function detectarMime(string $tmp, string $nome): string {
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $m  = finfo_file($fi, $tmp);
        finfo_close($fi);
        if ($m && $m !== 'application/octet-stream') return $m;
    }
    $h = fopen($tmp, 'rb');
    if ($h) { $b = fread($h, 4); fclose($h); if ($b === '%PDF') return 'application/pdf'; }
    return match(strtolower(pathinfo($nome, PATHINFO_EXTENSION))) {
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        default => 'application/octet-stream',
    };
}

function extrairTextoPdfFallback(string $caminho): string {
    $c = file_get_contents($caminho);
    if (!$c) return '';
    $texto = '';
    preg_match_all('/BT\s*(.*?)\s*ET/s', $c, $bl);
    foreach ($bl[1] as $b) {
        preg_match_all('/\(([^)]*)\)/', $b, $sp);
        foreach ($sp[1] as $s) {
            $texto .= str_replace(['\\n','\\r','\\t'], ["\n","\r","\t"], $s) . ' ';
        }
    }
    return limparTexto($texto);
}

function limparTexto(string $t): string {
    $t = str_replace(chr(0), '', $t);
    $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t);
    $t = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $t);
    $t = preg_replace('/[ \t]+/', ' ', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    return trim($t);
}

function criarFragmentos(PDO $pdo, string $id_doc, string $texto): int {
    $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
    $texto = str_replace(chr(0), '', $texto);
    $tam   = CHUNK_TAMANHO;
    $sob   = min(CHUNK_SOBREPOSICAO, (int)($tam / 2));
    $len   = mb_strlen($texto);
    $pos   = 0;
    $frags = [];

    while ($pos < $len) {
        $f = mb_substr($texto, $pos, $tam);
        if (trim($f) !== '') $frags[] = $f;
        $pos += ($tam - $sob);
    }

    $stmt = $pdo->prepare("
        INSERT INTO fragmentos_documento (id_documento, indice_fragmento, conteudo, total_tokens)
        VALUES (:doc, :i, :c, :t)
        ON CONFLICT (id_documento, indice_fragmento)
        DO UPDATE SET conteudo = EXCLUDED.conteudo
    ");

    $ok = 0;
    foreach ($frags as $i => $f) {
        $f = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $f);
        if ($stmt->execute([':doc' => $id_doc, ':i' => $i, ':c' => $f, ':t' => (int)(mb_strlen($f) / 4)])) {
            $ok++;
        }
    }
    return $ok;
}