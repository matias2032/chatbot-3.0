<?php

function obterConexao(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host   = getenv('DB_HOST');
    $port   = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_NAME');
    $user   = getenv('DB_USER');
    $pass   = getenv('DB_PASS');

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Define o search_path após a conexão (compatível com pooler do Neon)
        $pdo->exec("SET search_path TO public");

        return $pdo;

    } catch (PDOException $e) {
        $mensagem = getenv('APP_ENV') === 'production'
            ? 'Erro ao conectar à base de dados. Tente mais tarde.'
            : 'Erro de conexão: ' . $e->getMessage();

        http_response_code(500);
        die(json_encode(['sucesso' => false, 'erro' => $mensagem]));
    }
}