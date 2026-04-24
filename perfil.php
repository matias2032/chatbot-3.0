<?php
require_once 'auth.php';
require_once 'configuracao.php';
require_once 'conexao.php';

exigirAdmin();

$pdo  = obterConexao();
$stmt = $pdo->prepare("
    SELECT *, EXTRACT(YEAR FROM AGE(data_nascimento))::INTEGER AS idade
    FROM perfil_criador
    WHERE id_configuracao_bot = :bot
    LIMIT 1
");
$stmt->execute([':bot' => BOT_ID]);
$perfil = $stmt->fetch() ?: [];

// Redes sociais (JSONB → array PHP)
$redes = [];
if (!empty($perfil['redes_sociais'])) {
    $redes = json_decode($perfil['redes_sociais'], true) ?? [];
}

function val(array $arr, string $chave): string {
    return htmlspecialchars($arr[$chave] ?? '');
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil do Criador</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        body { overflow: auto; }
        .area-chat { overflow: auto; }
        .conteudo-admin { padding: 32px; max-width: 800px; }
        .titulo-pagina { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 6px; }
        .subtitulo-pagina { font-size: 13px; color: var(--cor-texto-2); margin-bottom: 32px; }

        /* Card de pré-visualização */
        .card-preview {
            background: var(--cor-fundo-2); border: 1px solid var(--cor-borda);
            border-radius: var(--raio); padding: 24px;
            display: flex; align-items: center; gap: 20px;
            margin-bottom: 28px;
        }
        .avatar-preview {
            width: 72px; height: 72px; border-radius: 50%;
            background: var(--cor-acento-suave); border: 2px solid var(--cor-borda-forte);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 600; color: var(--cor-acento);
            flex-shrink: 0; overflow: hidden;
        }
        .avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
        .preview-nome { font-size: 18px; font-weight: 600; margin-bottom: 2px; }
        .preview-profissao { font-size: 13px; color: var(--cor-acento); }
        .preview-idade { font-size: 12px; color: var(--cor-texto-3); margin-top: 4px; }

        /* Formulário */
        .card-form {
            background: var(--cor-fundo-2); border: 1px solid var(--cor-borda);
            border-radius: var(--raio); padding: 28px; margin-bottom: 24px;
        }
        .secao-titulo {
            font-size: 13px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.08em; color: var(--cor-texto-3);
            margin-bottom: 16px; padding-bottom: 10px;
            border-bottom: 1px solid var(--cor-borda);
        }
        .grelha-form { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        .campo-grupo { display: flex; flex-direction: column; gap: 6px; }
        .campo-grupo.full { grid-column: 1 / -1; }
        .campo-grupo label { font-size: 12px; font-weight: 500; color: var(--cor-texto-2); text-transform: uppercase; letter-spacing: 0.06em; }
        .campo-grupo input,
        .campo-grupo textarea {
            padding: 10px 14px; background: var(--cor-fundo-3);
            border: 1px solid var(--cor-borda-forte); border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px; color: var(--cor-texto);
            outline: none; transition: border-color var(--transicao);
        }
        .campo-grupo input:focus,
        .campo-grupo textarea:focus {
            border-color: var(--cor-acento); box-shadow: 0 0 0 3px var(--cor-acento-suave);
        }
        .campo-grupo textarea { resize: vertical; min-height: 90px; line-height: 1.6; }

        .acoes-form { display: flex; gap: 10px; margin-top: 8px; }
        .btn-primario {
            padding: 10px 24px; background: var(--cor-acento); color: #fff;
            border: none; border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background var(--transicao);
        }
        .btn-primario:hover { background: var(--cor-acento-hover); }
        .btn-primario:disabled { opacity: 0.5; cursor: not-allowed; }

        .notificacao {
            position: fixed; top: 20px; right: 20px;
            padding: 12px 20px; border-radius: var(--raio-sm);
            font-size: 13px; font-weight: 500; z-index: 1000; display: none;
        }
        .notificacao.sucesso { background: rgba(74,222,128,0.15); border: 1px solid rgba(74,222,128,0.3); color: #4ade80; }
        .notificacao.erro    { background: rgba(248,113,113,0.15); border: 1px solid rgba(248,113,113,0.3); color: #f87171; }

        @media (max-width: 640px) { .grelha-form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<aside class="barra-lateral">
    <div class="logo-area">
        <div class="logo-icone">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                <circle cx="14" cy="14" r="13" stroke="var(--cor-acento)" stroke-width="1.5"/>
                <path d="M8 14c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6" stroke="var(--cor-acento)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="14" cy="14" r="2.5" fill="var(--cor-acento)"/>
            </svg>
        </div>
        <span class="logo-nome">Admin</span>
    </div>
    <nav class="nav-lateral">
        <a href="index.php"      class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 3h12M2 8h8M2 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Chat público
        </a>
        <a href="admin.php"      class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="2" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
            Dashboard
        </a>
        <a href="treinar.php"    class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Treinar bot
        </a>
        <a href="documentos.php" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 2h6l4 4v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.5"/><path d="M9 2v4h4" stroke="currentColor" stroke-width="1.5"/></svg>
            Documentos
        </a>
        <a href="perfil.php"     class="nav-item ativo">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2 14c0-3.3 2.7-4 6-4s6 .7 6 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Perfil do criador
        </a>
    </nav>
    <div class="rodape-lateral">
        <a href="logout.php" style="font-size:12px;color:var(--cor-erro);text-decoration:none;">Terminar sessão</a>
    </div>
</aside>

<main class="area-chat">
<div class="conteudo-admin">

    <h1 class="titulo-pagina">Perfil do criador</h1>
    <p class="subtitulo-pagina">Estes dados são usados pelo bot para se apresentar quando perguntado sobre o seu criador.</p>

    <!-- Pré-visualização -->
    <div class="card-preview">
        <div class="avatar-preview" id="avatar-preview">
            <?php if (!empty($perfil['url_foto'])): ?>
            <img src="<?= val($perfil, 'url_foto') ?>" alt="Foto" onerror="this.parentElement.textContent='<?= strtoupper(substr($perfil['nome_completo'] ?? 'C', 0, 1)) ?>'">
            <?php else: ?>
            <?= strtoupper(substr($perfil['nome_completo'] ?? 'C', 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="preview-nome" id="prev-nome"><?= val($perfil, 'nome_completo') ?: 'Nome do Criador' ?></div>
            <div class="preview-profissao" id="prev-profissao"><?= val($perfil, 'profissao') ?: 'Profissão' ?></div>
            <?php if (!empty($perfil['idade'])): ?>
            <div class="preview-idade" id="prev-idade"><?= $perfil['idade'] ?> anos · <?= val($perfil, 'nacionalidade') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Dados pessoais -->
    <div class="card-form">
        <div class="secao-titulo">Dados pessoais</div>
        <div class="grelha-form">
            <div class="campo-grupo">
                <label for="nome_completo">Nome completo *</label>
                <input type="text" id="nome_completo" value="<?= val($perfil, 'nome_completo') ?>" maxlength="150" oninput="document.getElementById('prev-nome').textContent = this.value || 'Nome do Criador'">
            </div>
            <div class="campo-grupo">
                <label for="data_nascimento">Data de nascimento</label>
                <input type="date" id="data_nascimento" value="<?= val($perfil, 'data_nascimento') ?>">
            </div>
            <div class="campo-grupo">
                <label for="profissao">Profissão</label>
                <input type="text" id="profissao" value="<?= val($perfil, 'profissao') ?>" maxlength="100" oninput="document.getElementById('prev-profissao').textContent = this.value || 'Profissão'">
            </div>
            <div class="campo-grupo">
                <label for="nacionalidade">Nacionalidade</label>
                <input type="text" id="nacionalidade" value="<?= val($perfil, 'nacionalidade') ?>" maxlength="80">
            </div>
            <div class="campo-grupo">
                <label for="telefone">Telefone</label>
                <input type="tel" id="telefone" value="<?= val($perfil, 'telefone') ?>" maxlength="30" placeholder="+258 84 000 0000">
            </div>
            <div class="campo-grupo">
                <label for="email">Email</label>
                <input type="email" id="email" value="<?= val($perfil, 'email') ?>" maxlength="150">
            </div>
            <div class="campo-grupo full">
                <label for="morada">Morada</label>
                <input type="text" id="morada" value="<?= val($perfil, 'morada') ?>" maxlength="300" placeholder="Cidade, País">
            </div>
            <div class="campo-grupo full">
                <label for="bio">Biografia / Apresentação</label>
                <textarea id="bio" placeholder="Escreve uma breve apresentação que o bot usará ao falar sobre o seu criador..."><?= val($perfil, 'bio') ?></textarea>
            </div>
        </div>

        <div class="secao-titulo">Foto e redes sociais</div>
        <div class="grelha-form">
            <div class="campo-grupo full">
                <label for="url_foto">URL da foto de perfil</label>
                <input type="url" id="url_foto" value="<?= val($perfil, 'url_foto') ?>" placeholder="https://exemplo.com/foto.jpg">
            </div>
            <div class="campo-grupo">
                <label for="rs_linkedin">LinkedIn</label>
                <input type="url" id="rs_linkedin" value="<?= htmlspecialchars($redes['linkedin'] ?? '') ?>" placeholder="https://linkedin.com/in/...">
            </div>
            <div class="campo-grupo">
                <label for="rs_github">GitHub</label>
                <input type="url" id="rs_github" value="<?= htmlspecialchars($redes['github'] ?? '') ?>" placeholder="https://github.com/...">
            </div>
            <div class="campo-grupo">
                <label for="rs_instagram">Instagram</label>
                <input type="text" id="rs_instagram" value="<?= htmlspecialchars($redes['instagram'] ?? '') ?>" placeholder="@utilizador">
            </div>
            <div class="campo-grupo">
                <label for="rs_outro">Outro link</label>
                <input type="url" id="rs_outro" value="<?= htmlspecialchars($redes['outro'] ?? '') ?>" placeholder="https://...">
            </div>
        </div>

        <div class="acoes-form">
            <button class="btn-primario" id="btn-guardar">Guardar perfil</button>
        </div>
    </div>

</div>
</main>

<div class="notificacao" id="notificacao"></div>

<script>
document.getElementById('btn-guardar').addEventListener('click', async () => {
    const nome = document.getElementById('nome_completo').value.trim();
    if (!nome) {
        mostrarNotificacao('O nome completo é obrigatório.', 'erro');
        return;
    }

    const btn = document.getElementById('btn-guardar');
    btn.disabled = true;
    btn.textContent = 'A guardar…';

    const payload = {
        nome_completo:   nome,
        data_nascimento: document.getElementById('data_nascimento').value || null,
        profissao:       document.getElementById('profissao').value.trim()    || null,
        nacionalidade:   document.getElementById('nacionalidade').value.trim()|| null,
        telefone:        document.getElementById('telefone').value.trim()     || null,
        email:           document.getElementById('email').value.trim()        || null,
        morada:          document.getElementById('morada').value.trim()       || null,
        bio:             document.getElementById('bio').value.trim()          || null,
        url_foto:        document.getElementById('url_foto').value.trim()     || null,
        redes_sociais: {
            linkedin:  document.getElementById('rs_linkedin').value.trim()  || null,
            github:    document.getElementById('rs_github').value.trim()    || null,
            instagram: document.getElementById('rs_instagram').value.trim() || null,
            outro:     document.getElementById('rs_outro').value.trim()     || null,
        }
    };

    // Remove nulos das redes sociais
    Object.keys(payload.redes_sociais).forEach(k => {
        if (!payload.redes_sociais[k]) delete payload.redes_sociais[k];
    });

    try {
        const resp  = await fetch('api_perfil.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const dados = await resp.json();

     if (dados.sucesso) {
    mostrarNotificacao('Perfil guardado com sucesso!', 'sucesso');

    // Actualiza avatar se URL foi fornecida
    const url = payload.url_foto;
    if (url) {
        const av = document.getElementById('avatar-preview');
        av.innerHTML = `<img src="${url}" alt="Foto" style="width:100%;height:100%;object-fit:cover;border-radius:50%" onerror="this.parentElement.textContent='${nome.charAt(0).toUpperCase()}'">`;
    }

    // 🔥 REDIRECIONAMENTO PARA O DASHBOARD
    setTimeout(() => {
        window.location.href = 'admin.php';
    }, 1200); // espera 1.2s para mostrar a notificação
} else {
            mostrarNotificacao(dados.erro || 'Erro ao guardar perfil.', 'erro');
        }
    } catch(e) {
        mostrarNotificacao('Erro de ligação ao servidor.', 'erro');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Guardar perfil';
    }
});

function mostrarNotificacao(msg, tipo) {
    const n = document.getElementById('notificacao');
    n.textContent = msg;
    n.className = 'notificacao ' + tipo;
    n.style.display = 'block';
    setTimeout(() => { n.style.display = 'none'; }, 3500);
}
</script>
</body>
</html>