<?php
// ============================================================
//  API_CONVERSAS.PHP — Criar, apagar e carregar conversas
// ============================================================

require_once 'auth.php';
require_once 'configuracao.php';
require_once 'conexao.php';

// Todas as acções exigem sessão activa
iniciarSessao();
$utilizador    = utilizadorActual();
$id_utilizador = $utilizador['id_utilizador'] ?? null;

$pdo  = obterConexao();
$acao = $_GET['acao'] ?? '';

// ── CRIAR nova conversa ──────────────────────────────────────
if ($acao === 'criar') {
    $id_sessao = 'sess_' . time() . '_' . bin2hex(random_bytes(4));

    $stmt = $pdo->prepare("
        INSERT INTO conversas (id_configuracao_bot, id_sessao, id_utilizador)
        VALUES (:bot, :s, :uid)
        RETURNING id_conversa, id_sessao, iniciada_em
    ");
    $stmt->execute([':bot' => BOT_ID, ':s' => $id_sessao, ':uid' => $id_utilizador]);
    $nova = $stmt->fetch();

    respostaJson(true, [
        'id_conversa' => $nova['id_conversa'],
        'id_sessao'   => $nova['id_sessao'],
        'iniciada_em' => $nova['iniciada_em'],
    ]);
}

// ── APAGAR conversa ──────────────────────────────────────────
if ($acao === 'apagar') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $id    = $corpo['id_conversa'] ?? '';

    if (!$id) respostaJson(false, null, 'ID em falta.');

    // Só apaga se pertencer ao utilizador logado E ao bot correcto
  $stmt = $pdo->prepare("
    DELETE FROM conversas
    WHERE id_conversa         = :id
      AND id_configuracao_bot = :bot
      AND (id_utilizador = :uid OR (:uid2 IS NULL AND id_utilizador IS NULL))
");
$stmt->execute([':id' => $id, ':bot' => BOT_ID, ':uid' => $id_utilizador, ':uid2' => $id_utilizador]);

    respostaJson(true, ['apagado' => $stmt->rowCount() > 0]);
}

// ── CARREGAR mensagens de uma conversa ───────────────────────
if ($acao === 'carregar') {
    $id_conversa = $_GET['id_conversa'] ?? '';

    if (!$id_conversa) respostaJson(false, null, 'ID em falta.');

    // Confirma que a conversa pertence ao utilizador logado E a este bot
$stmt = $pdo->prepare("
    SELECT id_sessao FROM conversas
    WHERE id_conversa         = :id
      AND id_configuracao_bot = :bot
      AND (id_utilizador = :uid OR (:uid2 IS NULL AND id_utilizador IS NULL))
");
$stmt->execute([':id' => $id_conversa, ':bot' => BOT_ID, ':uid' => $id_utilizador, ':uid2' => $id_utilizador]);
    $conversa = $stmt->fetch();

    if (!$conversa) respostaJson(false, null, 'Conversa não encontrada.');

    $stmt = $pdo->prepare("
        SELECT papel, conteudo, enviada_em
        FROM mensagens
        WHERE id_conversa = :id
        ORDER BY enviada_em ASC
    ");
    $stmt->execute([':id' => $id_conversa]);
    $mensagens = $stmt->fetchAll();

    respostaJson(true, [
        'id_conversa' => $id_conversa,
        'id_sessao'   => $conversa['id_sessao'],
        'mensagens'   => $mensagens,
    ]);
}

respostaJson(false, null, 'Acção inválida.');