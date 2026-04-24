<?php
// ============================================================
//  REGISTO.PHP — Formulário e processamento de registo
// ============================================================

require_once 'auth.php';
iniciarSessao();

// Já logado? Redireciona
if (estaLogado()) {
    header('Location: index.php');
    exit;
}

require_once 'conexao.php';

$erro       = '';
$campo_erro = ''; // para destacar o campo com erro

// ── Processar formulário ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome            = trim($_POST['nome']            ?? '');
    $email           = trim($_POST['email']           ?? '');
    $senha           = $_POST['senha']                ?? '';
    $senha_confirmar = $_POST['senha_confirmar']      ?? '';

    // Validações no servidor
    if ($nome === '' || $email === '' || $senha === '' || $senha_confirmar === '') {
        $erro = 'Preenche todos os campos.';
    } elseif (mb_strlen($nome) < 2) {
        $erro = 'O nome deve ter pelo menos 2 caracteres.';
        $campo_erro = 'nome';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Endereço de email inválido.';
        $campo_erro = 'email';
    } elseif (mb_strlen($senha) < 8) {
        $erro = 'A palavra-passe deve ter pelo menos 8 caracteres.';
        $campo_erro = 'senha';
    } elseif ($senha !== $senha_confirmar) {
        $erro = 'As palavras-passe não coincidem.';
        $campo_erro = 'senha_confirmar';
    } else {
        $pdo = obterConexao();

        // Verificar se o email já está em uso
        $stmt = $pdo->prepare("SELECT id_utilizador FROM utilizadores WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $erro = 'Este email já está registado.';
            $campo_erro = 'email';
        } else {
            // Tudo ok — criar utilizador
            $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $pdo->prepare("
                INSERT INTO utilizadores (nome, email, senha_hash, perfil)
                VALUES (:nome, :email, :hash, 'utilizador')
            ");
            $stmt->execute([
                ':nome'  => $nome,
                ':email' => $email,
                ':hash'  => $hash,
            ]);

            header('Location: login.php?registado=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar conta — ChatBot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; overflow: auto; }

        .auth-card {
            width: 100%;
            max-width: 420px;
            background: var(--cor-fundo-2);
            border: 1px solid var(--cor-borda);
            border-radius: var(--raio);
            padding: 2.5rem 2rem;
            box-shadow: var(--sombra);
        }

        .auth-logo {
            display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;
        }
        .auth-logo-icone {
            width: 40px; height: 40px;
            background: var(--cor-acento-suave);
            border: 1px solid var(--cor-borda-forte);
            border-radius: var(--raio-sm);
            display: flex; align-items: center; justify-content: center;
        }
        .auth-logo-nome { font-size: 18px; font-weight: 600; letter-spacing: -0.02em; }

        .auth-titulo { font-size: 22px; font-weight: 600; margin-bottom: 0.25rem; }
        .auth-subtitulo { font-size: 13px; color: var(--cor-texto-2); margin-bottom: 1.8rem; }

        .campo-grupo { margin-bottom: 1.1rem; }
        .campo-grupo label {
            display: block; font-size: 12px; font-weight: 500;
            color: var(--cor-texto-2); margin-bottom: 0.4rem;
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .campo-grupo input {
            width: 100%;
            background: var(--cor-fundo-3);
            border: 1px solid var(--cor-borda-forte);
            border-radius: var(--raio-sm);
            padding: 0.65rem 0.9rem;
            font-family: var(--fonte-ui); font-size: 14px;
            color: var(--cor-texto);
            outline: none;
            transition: border-color var(--transicao), box-shadow var(--transicao);
        }
        .campo-grupo input:focus {
            border-color: var(--cor-acento);
            box-shadow: 0 0 0 3px var(--cor-acento-suave);
        }
        .campo-grupo input.erro-campo {
            border-color: var(--cor-erro);
            box-shadow: 0 0 0 3px rgba(248,113,113,0.15);
        }

        .dica-senha { font-size: 11px; color: var(--cor-texto-3); margin-top: 0.3rem; }

        .btn-auth {
            width: 100%; padding: 0.75rem;
            background: var(--cor-acento); color: #fff;
            border: none; border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px; font-weight: 600;
            cursor: pointer; margin-top: 0.5rem;
            transition: background var(--transicao);
        }
        .btn-auth:hover { background: var(--cor-acento-hover); }

        .auth-link { text-align: center; margin-top: 1.2rem; font-size: 13px; color: var(--cor-texto-2); }
        .auth-link a { color: var(--cor-acento); text-decoration: none; font-weight: 500; }
        .auth-link a:hover { text-decoration: underline; }

        .alerta {
            padding: 0.7rem 1rem; border-radius: var(--raio-sm);
            font-size: 13px; margin-bottom: 1.2rem;
        }
        .alerta-erro { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: var(--cor-erro); }
    </style>
</head>
<body>

<div class="auth-card">
    <div class="auth-logo">
        <div class="auth-logo-icone">
            <svg width="22" height="22" viewBox="0 0 28 28" fill="none">
                <circle cx="14" cy="14" r="13" stroke="var(--cor-acento)" stroke-width="1.5"/>
                <path d="M8 14c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6" stroke="var(--cor-acento)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="14" cy="14" r="2.5" fill="var(--cor-acento)"/>
            </svg>
        </div>
        <span class="auth-logo-nome">ChatBot</span>
    </div>

    <h1 class="auth-titulo">Criar conta</h1>
    <p class="auth-subtitulo">Regista-te para começar a usar o chatbot</p>

    <?php if ($erro): ?>
        <div class="alerta alerta-erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="POST" action="registo.php" novalidate>
        <div class="campo-grupo">
            <label for="nome">Nome completo</label>
            <input
                type="text" id="nome" name="nome"
                value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>"
                placeholder="O teu nome"
                class="<?= $campo_erro === 'nome' ? 'erro-campo' : '' ?>"
                autocomplete="name" required
            >
        </div>

        <div class="campo-grupo">
            <label for="email">Email</label>
            <input
                type="email" id="email" name="email"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                placeholder="o-teu@email.com"
                class="<?= $campo_erro === 'email' ? 'erro-campo' : '' ?>"
                autocomplete="email" required
            >
        </div>

        <div class="campo-grupo">
            <label for="senha">Palavra-passe</label>
            <input
                type="password" id="senha" name="senha"
                placeholder="Mínimo 8 caracteres"
                class="<?= $campo_erro === 'senha' ? 'erro-campo' : '' ?>"
                autocomplete="new-password" required
            >
            <p class="dica-senha">Mínimo de 8 caracteres.</p>
        </div>

        <div class="campo-grupo">
            <label for="senha_confirmar">Confirmar palavra-passe</label>
            <input
                type="password" id="senha_confirmar" name="senha_confirmar"
                placeholder="Repete a palavra-passe"
                class="<?= $campo_erro === 'senha_confirmar' ? 'erro-campo' : '' ?>"
                autocomplete="new-password" required
            >
        </div>

        <button type="submit" class="btn-auth">Criar conta</button>
    </form>

    <p class="auth-link">
        Já tens conta? <a href="login.php">Entrar</a>
    </p>
</div>

</body>
</html>