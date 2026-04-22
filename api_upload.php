<?php
// ============================================================
//  API_UPLOAD.PHP — Corrigido: async + MIME fix + erro de ligação
// ============================================================

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
        $pdo->prepare("UPDATE documentos SET estado='a_processar',mensagem_erro=NULL WHERE id_documento=:id")->execute([':id'=>$id]);
        $pdo->prepare("DELETE FROM fragmentos_documento WHERE id_documento=:id")->execute([':id'=>$id]);
        processarDocumento($pdo, $id, $doc['caminho_ficheiro'], $doc['tipo_mime']);
        respostaJson(true, null, '');
    }

    // Nova acção: verificar estado (para polling do frontend)
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
// Upload de ficheiro (multipart/form-data)
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

if (!in_array($tipo_mime, ['application/pdf','text/plain']) && !in_array($extensao, ['pdf','txt'])) {
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
        ':cam'  => $caminho_final, ':mime' => $tipo_mime, ':tam' => $tamanho,
        ':cat'  => $categoria, ':desc' => $descricao,
    ]);
    $id_documento = $stmt->fetchColumn();
} catch (PDOException $e) {
    if (file_exists($caminho_final)) unlink($caminho_final);
    respostaJson(false, null, 'Erro na BD: ' . $e->getMessage());
}

// ---------------------------------------------------------------
// RESPONDE AO BROWSER IMEDIATAMENTE, processa PDF em background.
// Resolve o "Erro de ligação" em ficheiros grandes/lentos.
// ---------------------------------------------------------------
$resposta_imediata = json_encode([
    'sucesso' => true,
    'dados'   => ['id' => $id_documento, 'nome' => $nome_original, 'estado' => 'a_processar'],
    'erro'    => '',
], JSON_UNESCAPED_UNICODE);

// Limpa qualquer buffer existente
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Connection: close');
header('Content-Length: ' . strlen($resposta_imediata));
echo $resposta_imediata;
flush();

// Fecha a ligação HTTP (PHP-FPM / Apache mod_php)
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// Aumenta tempo e continua em background
set_time_limit(300);
ignore_user_abort(true);
session_write_close(); // liberta a sessão para não bloquear outros pedidos

processarDocumento($pdo, $id_documento, $caminho_final, $tipo_mime);
exit;


// ============================================================
// FUNÇÕES
// ============================================================

function processarDocumento(PDO $pdo, string $id, string $caminho, string $mime): void {
    try {
        $texto = extrairTexto($caminho, $mime);
        if (trim($texto) === '') {
            $pdo->prepare("UPDATE documentos SET estado='erro',mensagem_erro='Sem texto extraível. PDF baseado em imagem?' WHERE id_documento=:id")
                ->execute([':id' => $id]);
            return;
        }
        criarFragmentos($pdo, $id, $texto);
        $pdo->prepare("UPDATE documentos SET estado='pronto',processado_em=NOW(),mensagem_erro=NULL WHERE id_documento=:id")
            ->execute([':id' => $id]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE documentos SET estado='erro',mensagem_erro=:m WHERE id_documento=:id")
            ->execute([':id' => $id, ':m' => substr($e->getMessage(), 0, 500)]);
    }
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

function extrairTexto(string $caminho, string $mime): string {
    if (str_contains($mime, 'text') || str_ends_with($caminho, '.txt')) {
        $t = file_get_contents($caminho);
        return $t !== false ? limparTexto($t) : '';
    }
    $dis = ini_get('disable_functions') ?: '';
    if (function_exists('shell_exec') && !str_contains($dis, 'shell_exec')) {
        $r = @shell_exec('pdftotext ' . escapeshellarg($caminho) . ' - 2>/dev/null');
        if ($r && strlen(trim($r)) > 20) return limparTexto($r);
    }
    return extrairTextoPdfFallback($caminho);
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
        preg_match_all('/<([0-9A-Fa-f\s]+)>/', $b, $sh);
        foreach ($sh[1] as $hex) {
            $hex = preg_replace('/\s/', '', $hex);
            if (strlen($hex) % 2 === 0) {
                $d = '';
                for ($i = 0; $i < strlen($hex); $i += 2) {
                    $ch = chr(hexdec(substr($hex, $i, 2)));
                    if (ctype_print($ch) || $ch === ' ') $d .= $ch;
                }
                if (trim($d) !== '') $texto .= $d . ' ';
            }
        }
    }
    return limparTexto($texto);
}

function limparTexto(string $t): string {
    // 1. Remove caracteres nulos e outros caracteres de controlo que o Postgres rejeita
    $t = str_replace(chr(0), '', $t);
    
    // 2. Remove sequências de bytes que não são UTF-8 válidas
    // O modificador /u garante que o PCRE trate a string como UTF-8
    $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
    
    // 3. Remove caracteres de controlo (exceto nova linha e tabulação)
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t);
    
    // 4. Remove caracteres que, embora técnicos, o Postgres às vezes rejeita em UTF-8
    // Esta regex limpa caracteres inválidos da especificação Unicode
    $t = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $t);

    // 5. Normaliza espaços e quebras de linha
    $t = preg_replace('/[ \t]+/', ' ', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    
    return trim($t);
}

function criarFragmentos(PDO $pdo, string $id_doc, string $texto): int {
    // --- CORREÇÃO 1: Limpeza profunda antes de processar ---
    // Garante que o texto está em UTF-8 puro e sem caracteres nulos (chr 0)
    $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
    $texto = str_replace(chr(0), '', $texto);

    $tam = CHUNK_TAMANHO;
    $sob = min(CHUNK_SOBREPOSICAO, (int)($tam / 2));
    $len = mb_strlen($texto);
    $pos = 0; 
    $frags = [];

    while ($pos < $len) {
        $f = mb_substr($texto, $pos, $tam);
        if (trim($f) !== '') {
            $frags[] = $f;
        }
        $pos += ($tam - $sob);
    }

    // --- CORREÇÃO 2: Preparação do Statement ---
    $stmt = $pdo->prepare("
        INSERT INTO fragmentos_documento (id_documento, indice_fragmento, conteudo, total_tokens)
        VALUES (:doc, :i, :c, :t)
        ON CONFLICT (id_documento, indice_fragmento) 
        DO UPDATE SET conteudo = EXCLUDED.conteudo
    ");

    $inseridos = 0;
    foreach ($frags as $i => $f) {
        // Limpeza final de cada fragmento para o Postgres não rejeitar
        $f_limpo = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $f);
        
        $sucesso = $stmt->execute([
            ':doc' => $id_doc,
            ':i'   => $i,
            ':c'   => $f_limpo,
            ':t'   => (int)(mb_strlen($f_limpo) / 4)
        ]);
        
        if ($sucesso) $inseridos++;
    }

    return $inseridos;
}