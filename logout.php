<?php
// ============================================================
//  LOGOUT.PHP — Destroi a sessão e redireciona para login
// ============================================================

require_once 'auth.php';
iniciarSessao();

// Limpar todas as variáveis de sessão
$_SESSION = [];

// Apagar o cookie de sessão do browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destruir a sessão no servidor
session_destroy();

// Redirecionar para o login
header('Location: index.php');
exit;