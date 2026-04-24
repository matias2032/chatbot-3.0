<?php
require_once 'auth.php';
require_once 'configuracao.php';
require_once 'conexao.php';

exigirAdmin();

$pdo = obterConexao();

// Busca todas as entradas de conhecimento deste bot
$stmt = $pdo->prepare("
    SELECT id_base_conhecimento, titulo, categoria, prioridade,
           ativo, criado_em,
           LEFT(conteudo, 100) AS resumo
    FROM base_conhecimento
    WHERE id_configuracao_bot = :bot
    ORDER BY prioridade ASC, criado_em DESC
");
$stmt->execute([':bot' => BOT_ID]);
$entradas = $stmt->fetchAll();

// Busca categorias existentes para o datalist
$stmt = $pdo->prepare("
    SELECT DISTINCT categoria FROM base_conhecimento
    WHERE id_configuracao_bot = :bot AND categoria IS NOT NULL
    ORDER BY categoria
");
$stmt->execute([':bot' => BOT_ID]);
$categorias = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treinar Bot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        body { overflow: auto; }
        .area-chat { overflow: auto; }
        .conteudo-admin { padding: 32px; max-width: 960px; }
        .titulo-pagina { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 6px; }
        .subtitulo-pagina { font-size: 13px; color: var(--cor-texto-2); margin-bottom: 32px; }

        /* Formulário */
        .card-form {
            background: var(--cor-fundo-2); border: 1px solid var(--cor-borda);
            border-radius: var(--raio); padding: 28px; margin-bottom: 32px;
        }
        .card-form-titulo { font-size: 15px; font-weight: 600; margin-bottom: 20px; }
        .grelha-form { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .campo-grupo { display: flex; flex-direction: column; gap: 6px; }
        .campo-grupo.full { grid-column: 1 / -1; }
        .campo-grupo label { font-size: 12px; font-weight: 500; color: var(--cor-texto-2); text-transform: uppercase; letter-spacing: 0.06em; }
        .campo-grupo input,
        .campo-grupo select,
        .campo-grupo textarea {
            padding: 10px 14px;
            background: var(--cor-fundo-3); border: 1px solid var(--cor-borda-forte);
            border-radius: var(--raio-sm); font-family: var(--fonte-ui); font-size: 14px;
            color: var(--cor-texto); outline: none; transition: border-color var(--transicao);
        }
        .campo-grupo input:focus,
        .campo-grupo select:focus,
        .campo-grupo textarea:focus {
            border-color: var(--cor-acento);
            box-shadow: 0 0 0 3px var(--cor-acento-suave);
        }
        .campo-grupo textarea { resize: vertical; min-height: 140px; line-height: 1.6; }
        .campo-grupo select option { background: var(--cor-fundo-3); }
        .campo-hint { font-size: 11px; color: var(--cor-texto-3); }

        .acoes-form { display: flex; gap: 10px; margin-top: 20px; }
        .btn-primario {
            padding: 10px 22px; background: var(--cor-acento); color: #fff;
            border: none; border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background var(--transicao);
        }
        .btn-primario:hover { background: var(--cor-acento-hover); }
        .btn-secundario {
            padding: 10px 18px; background: transparent;
            border: 1px solid var(--cor-borda-forte); border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px; color: var(--cor-texto-2);
            cursor: pointer; transition: all var(--transicao);
        }
        .btn-secundario:hover { border-color: var(--cor-texto-2); color: var(--cor-texto); }

        /* Tabela de entradas */
        .card-tabela {
            background: var(--cor-fundo-2); border: 1px solid var(--cor-borda);
            border-radius: var(--raio); overflow: hidden;
        }
        .card-tabela-cabecalho {
            padding: 16px 24px; border-bottom: 1px solid var(--cor-borda);
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-tabela-titulo { font-size: 15px; font-weight: 600; }
        .contador { font-size: 12px; color: var(--cor-texto-3); }

        .tabela-conhecimento { width: 100%; border-collapse: collapse; }
        .tabela-conhecimento th {
            padding: 10px 20px; text-align: left;
            font-size: 11px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--cor-texto-3);
            border-bottom: 1px solid var(--cor-borda);
        }
        .tabela-conhecimento td {
            padding: 14px 20px; font-size: 13px;
            border-bottom: 1px solid var(--cor-borda); color: var(--cor-texto-2);
            vertical-align: top;
        }
        .tabela-conhecimento tr:last-child td { border-bottom: none; }
        .tabela-conhecimento tr:hover td { background: var(--cor-fundo-3); }
        .titulo-entrada { font-weight: 600; color: var(--cor-texto); margin-bottom: 3px; }
        .resumo-entrada { color: var(--cor-texto-3); font-size: 12px; }

        .badge-categoria {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: 11px; background: var(--cor-acento-suave);
            color: var(--cor-acento); border: 1px solid rgba(108,143,255,0.2);
        }
        .badge-prioridade {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: 11px; background: var(--cor-fundo-3);
            color: var(--cor-texto-3); border: 1px solid var(--cor-borda);
        }
        .toggle-ativo {
            width: 36px; height: 20px; border-radius: 10px;
            border: none; cursor: pointer; transition: background 0.2s;
            position: relative;
        }
        .toggle-ativo::after {
            content: ''; position: absolute;
            top: 3px; left: 3px;
            width: 14px; height: 14px;
            border-radius: 50%; background: #fff;
            transition: transform 0.2s;
        }
        .toggle-ativo.ligado  { background: var(--cor-acento); }
        .toggle-ativo.desligado { background: var(--cor-borda-forte); }
        .toggle-ativo.ligado::after  { transform: translateX(16px); }

        .btn-eliminar {
            padding: 5px 10px; background: transparent;
            border: 1px solid rgba(248,113,113,0.3); border-radius: var(--raio-sm);
            color: var(--cor-erro); font-size: 12px; cursor: pointer;
            transition: all var(--transicao);
        }
        .btn-eliminar:hover { background: rgba(248,113,113,0.1); }

        /* Notificação */
        .notificacao {
            position: fixed; top: 20px; right: 20px;
            padding: 12px 20px; border-radius: var(--raio-sm);
            font-size: 13px; font-weight: 500; z-index: 1000;
            animation: entrar 0.3s ease;
            display: none;
        }
        .notificacao.sucesso { background: rgba(74,222,128,0.15); border: 1px solid rgba(74,222,128,0.3); color: #4ade80; }
        .notificacao.erro    { background: rgba(248,113,113,0.15); border: 1px solid rgba(248,113,113,0.3); color: #f87171; }

        .sem-dados { padding: 40px; text-align: center; color: var(--cor-texto-3); font-size: 14px; }

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
        <a href="treinar.php"    class="nav-item ativo">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Treinar bot
        </a>
        <a href="documentos.php" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 2h6l4 4v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.5"/><path d="M9 2v4h4" stroke="currentColor" stroke-width="1.5"/></svg>
            Documentos
        </a>
        <a href="perfil.php"     class="nav-item">
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

    <h1 class="titulo-pagina">Treinar bot</h1>
    <p class="subtitulo-pagina">Adiciona conhecimento textual que o bot usará para responder.</p>

    <!-- Formulário de adição -->
    <div class="card-form">
        <div class="card-form-titulo">Nova entrada de conhecimento</div>
        <div class="grelha-form">
            <div class="campo-grupo">
                <label for="titulo">Título *</label>
                <input type="text" id="titulo" placeholder="Ex: Regulamento de Matrículas" maxlength="255">
            </div>
            <div class="campo-grupo">
                <label for="categoria">Categoria</label>
                <input type="text" id="categoria" list="lista-categorias" placeholder="Ex: regulamento, FAQ, perfil">
                <datalist id="lista-categorias">
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="campo-grupo">
                <label for="etiquetas">Etiquetas</label>
                <input type="text" id="etiquetas" placeholder="ispt, regulamento, normas (separadas por vírgula)">
                <span class="campo-hint">Separa com vírgulas</span>
            </div>
            <div class="campo-grupo">
                <label for="prioridade">Prioridade</label>
                <select id="prioridade">
                    <option value="1">1 — Muito alta</option>
                    <option value="3">3 — Alta</option>
                    <option value="5" selected>5 — Normal</option>
                    <option value="7">7 — Baixa</option>
                    <option value="10">10 — Muito baixa</option>
                </select>
                <span class="campo-hint">1 = responde primeiro</span>
            </div>
            <div class="campo-grupo full">
                <label for="conteudo">Conteúdo *</label>
                <textarea id="conteudo" placeholder="Escreve aqui o conhecimento que o bot deve ter..."></textarea>
            </div>
        </div>
        <div class="acoes-form">
            <button class="btn-primario" id="btn-guardar">Guardar entrada</button>
            <button class="btn-secundario" id="btn-limpar-form">Limpar formulário</button>
        </div>
    </div>

    <!-- Lista de entradas -->
    <div class="card-tabela">
        <div class="card-tabela-cabecalho">
            <span class="card-tabela-titulo">Conhecimento registado</span>
            <span class="contador"><?= count($entradas) ?> entrada(s)</span>
        </div>

        <?php if (empty($entradas)): ?>
        <div class="sem-dados">Nenhuma entrada ainda. Adiciona conhecimento acima.</div>
        <?php else: ?>
        <table class="tabela-conhecimento">
            <thead>
                <tr>
                    <th>Título / Resumo</th>
                    <th>Categoria</th>
                    <th>Prioridade</th>
                    <th>Activo</th>
                    <th>Acções</th>
                </tr>
            </thead>
            <tbody id="corpo-tabela">
            <?php foreach ($entradas as $e): ?>
            <tr id="linha-<?= $e['id_base_conhecimento'] ?>">
                <td>
                    <div class="titulo-entrada"><?= htmlspecialchars($e['titulo']) ?></div>
                    <div class="resumo-entrada"><?= htmlspecialchars($e['resumo']) ?>…</div>
                </td>
                <td>
                    <?php if ($e['categoria']): ?>
                    <span class="badge-categoria"><?= htmlspecialchars($e['categoria']) ?></span>
                    <?php else: ?>
                    <span style="color:var(--cor-texto-3)">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge-prioridade"><?= $e['prioridade'] ?></span></td>
                <td>
                    <button
                        class="toggle-ativo <?= $e['ativo'] ? 'ligado' : 'desligado' ?>"
                        data-id="<?= $e['id_base_conhecimento'] ?>"
                        data-ativo="<?= $e['ativo'] ? '1' : '0' ?>"
                        title="<?= $e['ativo'] ? 'Desactivar' : 'Activar' ?>"
                        onclick="alternarAtivo(this)"
                    ></button>
                </td>
                <td>
                    <button
                        class="btn-eliminar"
                        onclick="eliminarEntrada('<?= $e['id_base_conhecimento'] ?>', this)"
                    >Eliminar</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
</main>

<!-- Notificação -->
<div class="notificacao" id="notificacao"></div>

<script>
// ------------------------------------------------------------
// Guardar nova entrada
// ------------------------------------------------------------
document.getElementById('btn-guardar').addEventListener('click', async () => {
    const titulo    = document.getElementById('titulo').value.trim();
    const conteudo  = document.getElementById('conteudo').value.trim();

    if (!titulo || !conteudo) {
        mostrarNotificacao('Título e conteúdo são obrigatórios.', 'erro');
        return;
    }

    const etiquetasRaw = document.getElementById('etiquetas').value;
    const etiquetas = etiquetasRaw.split(',').map(e => e.trim()).filter(Boolean);

    const btn = document.getElementById('btn-guardar');
    btn.disabled = true;
    btn.textContent = 'A guardar…';

    try {
        const resp = await fetch('api_treinar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                acao:       'inserir',
                titulo:     titulo,
                conteudo:   conteudo,
                categoria:  document.getElementById('categoria').value.trim(),
                etiquetas:  etiquetas,
                prioridade: parseInt(document.getElementById('prioridade').value),
            })
        });
        const dados = await resp.json();

        if (dados.sucesso) {
            mostrarNotificacao('Entrada guardada com sucesso!', 'sucesso');
            document.getElementById('btn-limpar-form').click();
            setTimeout(() => location.reload(), 1200);
        } else {
            mostrarNotificacao(dados.erro || 'Erro ao guardar.', 'erro');
        }
    } catch(e) {
        mostrarNotificacao('Erro de ligação ao servidor.', 'erro');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Guardar entrada';
    }
});

// Limpar formulário
document.getElementById('btn-limpar-form').addEventListener('click', () => {
    ['titulo','categoria','etiquetas','conteudo'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.getElementById('prioridade').value = '5';
});

// ------------------------------------------------------------
// Eliminar entrada
// ------------------------------------------------------------
async function eliminarEntrada(id, btn) {
    if (!confirm('Eliminar esta entrada permanentemente?')) return;

    btn.disabled = true;
    btn.textContent = '…';

    try {
        const resp = await fetch('api_treinar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'eliminar', id })
        });
        const dados = await resp.json();

        if (dados.sucesso) {
            document.getElementById('linha-' + id).remove();
            mostrarNotificacao('Entrada eliminada.', 'sucesso');
        } else {
            mostrarNotificacao(dados.erro || 'Erro ao eliminar.', 'erro');
            btn.disabled = false;
            btn.textContent = 'Eliminar';
        }
    } catch(e) {
        mostrarNotificacao('Erro de ligação.', 'erro');
        btn.disabled = false;
        btn.textContent = 'Eliminar';
    }
}

// ------------------------------------------------------------
// Alternar activo/inactivo
// ------------------------------------------------------------
async function alternarAtivo(btn) {
    const id    = btn.dataset.id;
    const atual = btn.dataset.ativo === '1';
    const novoEstado = !atual;

    try {
        const resp = await fetch('api_treinar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'alternar_ativo', id, ativo: novoEstado })
        });
        const dados = await resp.json();

        if (dados.sucesso) {
            btn.classList.toggle('ligado',    novoEstado);
            btn.classList.toggle('desligado', !novoEstado);
            btn.dataset.ativo = novoEstado ? '1' : '0';
        } else {
            mostrarNotificacao('Erro ao alterar estado.', 'erro');
        }
    } catch(e) {
        mostrarNotificacao('Erro de ligação.', 'erro');
    }
}

// ------------------------------------------------------------
// Notificação
// ------------------------------------------------------------
function mostrarNotificacao(msg, tipo) {
    const n = document.getElementById('notificacao');
    n.textContent = msg;
    n.className = 'notificacao ' + tipo;
    n.style.display = 'block';
    setTimeout(() => { n.style.display = 'none'; }, 3000);
}
</script>

</body>
</html>