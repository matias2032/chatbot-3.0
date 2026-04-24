<?php
// ============================================================
//  CRIAR_ADMIN.PHP — Script de uso único para criar o admin
//  Executar UMA VEZ via CLI:  php criar_admin.php
//  Apagar o ficheiro depois de executar!
// ============================================================

require_once __DIR__ . '/conexao.php';

$nome  = 'Administrador';
$email = 'admin@chatbot.com';
$senha = 'admin123'; // ALTERAR ANTES DE EXECUTAR EM PRODUÇÃO

$hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo = obterConexao();

// Verificar se já existe
$stmt = $pdo->prepare("SELECT id_utilizador FROM utilizadores WHERE email = :email");
$stmt->execute([':email' => $email]);
if ($stmt->fetch()) {
    echo "Admin já existe com o email: {$email}\n";
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO utilizadores (nome, email, senha_hash, perfil)
    VALUES (:nome, :email, :hash, 'admin')
    RETURNING id_utilizador
");
$stmt->execute([':nome' => $nome, ':email' => $email, ':hash' => $hash]);
$id = $stmt->fetchColumn();

echo "Admin criado com sucesso!\n";
echo "ID: {$id}\n";
echo "Email: {$email}\n";
echo "Senha: {$senha}\n";
echo "\n⚠️  APAGA ESTE FICHEIRO IMEDIATAMENTE APÓS EXECUTAR!\n";