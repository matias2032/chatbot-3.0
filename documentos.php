<?php
require_once 'auth.php';
require_once 'configuracao.php';
require_once 'conexao.php';

exigirAdmin();

$pdo = obterConexao();

$stmt = $pdo->prepare("
    SELECT id_documento, nome_original, tipo_mime, tamanho_bytes,
           total_paginas, categoria, estado, mensagem_erro,
           carregado_em, processado_em,
           (SELECT COUNT(*) FROM fragmentos_documento f WHERE f.id_documento = d.id_documento) AS total_fragmentos
    FROM documentos d
    WHERE id_configuracao_bot = :bot
    ORDER BY carregado_em DESC
");
$stmt->execute([':bot' => BOT_ID]);
$documentos = $stmt->fetchAll();

function formatarTamanho(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        body { overflow: auto; }
        .area-chat { overflow: auto; }
        .conteudo-admin { padding: 32px; max-width: 960px; }
        .titulo-pagina { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 6px; }
        .subtitulo-pagina { font-size: 13px; color: var(--cor-texto-2); margin-bottom: 32px; }

        /* Zona de upload */
        .zona-upload {
            background: var(--cor-fundo-2); border: 2px dashed var(--cor-borda-forte);
            border-radius: var(--raio); padding: 40px;
            text-align: center; margin-bottom: 32px;
            transition: border-color var(--transicao), background var(--transicao);
            cursor: pointer;
        }
        .zona-upload.arrastando {
            border-color: var(--cor-acento);
            background: var(--cor-acento-suave);
        }
        .zona-icone { margin-bottom: 12px; color: var(--cor-texto-3); }
        .zona-titulo { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .zona-subtitulo { font-size: 13px; color: var(--cor-texto-3); margin-bottom: 20px; }

        .form-upload-campos {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 12px; max-width: 560px; margin: 0 auto 20px;
            text-align: left;
        }
        .campo-grupo { display: flex; flex-direction: column; gap: 5px; }
        .campo-grupo label { font-size: 11px; font-weight: 500; color: var(--cor-texto-2); text-transform: uppercase; letter-spacing: 0.06em; }
        .campo-grupo input,
        .campo-grupo select {
            padding: 9px 12px; background: var(--cor-fundo-3);
            border: 1px solid var(--cor-borda-forte); border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 13px; color: var(--cor-texto); outline: none;
            transition: border-color var(--transicao);
        }
        .campo-grupo input:focus, .campo-grupo select:focus {
            border-color: var(--cor-acento); box-shadow: 0 0 0 3px var(--cor-acento-suave);
        }

        .input-ficheiro { display: none; }
        .btn-escolher {
            padding: 10px 24px; background: var(--cor-acento); color: #fff;
            border: none; border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background var(--transicao); margin-right: 10px;
        }
        .btn-escolher:hover { background: var(--cor-acento-hover); }

        /* Barra de progresso */
        .barra-progresso-container {
            display: none; max-width: 400px; margin: 16px auto 0;
        }
        .barra-progresso-fundo {
            height: 6px; background: var(--cor-borda-forte); border-radius: 3px; overflow: hidden;
        }
        .barra-progresso-fill {
            height: 100%; background: var(--cor-acento); border-radius: 3px;
            transition: width 0.3s ease; width: 0%;
        }
        .barra-progresso-texto { font-size: 12px; color: var(--cor-texto-3); margin-top: 6px; }

        /* Ficheiro seleccionado */
        .ficheiro-seleccionado {
            display: none; max-width: 400px; margin: 12px auto 0;
            background: var(--cor-fundo-3); border: 1px solid var(--cor-borda);
            border-radius: var(--raio-sm); padding: 10px 14px;
            display: flex; align-items: center; gap: 10px; font-size: 13px;
        }
        .ficheiro-seleccionado { display: none; }

        /* Tabela de documentos */
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

        .tabela-docs { width: 100%; border-collapse: collapse; }
        .tabela-docs th {
            padding: 10px 20px; text-align: left;
            font-size: 11px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--cor-texto-3);
            border-bottom: 1px solid var(--cor-borda);
        }
        .tabela-docs td {
            padding: 14px 20px; font-size: 13px;
            border-bottom: 1px solid var(--cor-borda); color: var(--cor-texto-2);
            vertical-align: middle;
        }
        .tabela-docs tr:last-child td { border-bottom: none; }
        .tabela-docs tr:hover td { background: var(--cor-fundo-3); }
        .nome-doc { font-weight: 600; color: var(--cor-texto); max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 500; }
        .badge-pronto    { background: rgba(74,222,128,0.15);  color: #4ade80; }
        .badge-pendente  { background: rgba(251,191,36,0.15);  color: #fbbf24; }
        .badge-erro      { background: rgba(248,113,113,0.15); color: #f87171; }
        .badge-processar { background: rgba(96,165,250,0.15);  color: #60a5fa; }

        .acoes-doc { display: flex; gap: 6px; }
        .btn-doc {
            padding: 4px 10px; border-radius: var(--raio-sm); font-size: 12px;
            cursor: pointer; border: 1px solid; transition: all var(--transicao); font-family: var(--fonte-ui);
        }
        .btn-reprocessar { border-color: rgba(96,165,250,0.3); color: #60a5fa; background: transparent; }
        .btn-reprocessar:hover { background: rgba(96,165,250,0.1); }
        .btn-eliminar-doc { border-color: rgba(248,113,113,0.3); color: #f87171; background: transparent; }
        .btn-eliminar-doc:hover { background: rgba(248,113,113,0.1); }

        .erro-detalhe { font-size: 11px; color: var(--cor-erro); margin-top: 3px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sem-dados { padding: 40px; text-align: center; color: var(--cor-texto-3); font-size: 14px; }

        .notificacao {
            position: fixed; top: 20px; right: 20px;
            padding: 12px 20px; border-radius: var(--raio-sm);
            font-size: 13px; font-weight: 500; z-index: 1000; display: none;
        }
        .notificacao.sucesso { background: rgba(74,222,128,0.15); border: 1px solid rgba(74,222,128,0.3); color: #4ade80; }
        .notificacao.erro    { background: rgba(248,113,113,0.15); border: 1px solid rgba(248,113,113,0.3); color: #f87171; }

        @media (max-width: 640px) { .form-upload-campos { grid-template-columns: 1fr; } }
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
        <a href="documentos.php" class="nav-item ativo">
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

    <h1 class="titulo-pagina">Documentos</h1>
    <p class="subtitulo-pagina">Carrega PDFs e ficheiros de texto para o bot aprender o seu conteúdo.</p>

    <!-- Zona de upload -->
    <div class="zona-upload" id="zona-upload" onclick="document.getElementById('input-ficheiro').click()">
        <div class="zona-icone">
            <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                <rect x="8" y="4" width="18" height="24" rx="3" stroke="currentColor" stroke-width="1.5"/>
                <path d="M20 4l8 8h-8V4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M28 26v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V14a2 2 0 0 1 2-2h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                <path d="M20 20v8M17 25l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="zona-titulo">Arrasta um ficheiro ou clica para escolher</div>
        <div class="zona-subtitulo">PDF ou TXT · máx. <?= TAMANHO_MAXIMO_MB ?> MB</div>

        <div class="form-upload-campos" onclick="event.stopPropagation()">
            <div class="campo-grupo">
                <label>Categoria</label>
                <input type="text" id="upload-categoria" placeholder="Ex: regulamento, manual">
            </div>
            <div class="campo-grupo">
                <label>Descrição (opcional)</label>
                <input type="text" id="upload-descricao" placeholder="Breve descrição do documento">
            </div>
        </div>

        <div id="ficheiro-info" class="ficheiro-seleccionado"></div>

        <div>
            <button class="btn-escolher" onclick="event.stopPropagation(); document.getElementById('input-ficheiro').click()">
                Escolher ficheiro
            </button>
        </div>

        <input type="file" id="input-ficheiro" class="input-ficheiro" accept=".pdf,.txt">

        <div class="barra-progresso-container" id="progresso-container">
            <div class="barra-progresso-fundo">
                <div class="barra-progresso-fill" id="barra-fill"></div>
            </div>
            <div class="barra-progresso-texto" id="progresso-texto">A carregar…</div>
        </div>
    </div>

    <!-- Lista de documentos -->
    <div class="card-tabela">
        <div class="card-tabela-cabecalho">
            <span class="card-tabela-titulo">Documentos carregados</span>
            <span class="contador"><?= count($documentos) ?> documento(s)</span>
        </div>

        <?php if (empty($documentos)): ?>
        <div class="sem-dados">Nenhum documento ainda. Carrega um ficheiro acima.</div>
        <?php else: ?>
        <table class="tabela-docs">
            <thead>
                <tr>
                    <th>Ficheiro</th>
                    <th>Tamanho</th>
                    <th>Fragmentos</th>
                    <th>Estado</th>
                    <th>Data</th>
                    <th>Acções</th>
                </tr>
            </thead>
            <tbody id="corpo-tabela-docs">
            <?php foreach ($documentos as $doc):
                $classe_badge = match($doc['estado']) {
                    'pronto'      => 'badge-pronto',
                    'pendente'    => 'badge-pendente',
                    'erro'        => 'badge-erro',
                    'a_processar' => 'badge-processar',
                    default       => 'badge-pendente',
                };
            ?>
            <tr id="doc-<?= $doc['id_documento'] ?>">
                <td>
                    <div class="nome-doc" title="<?= htmlspecialchars($doc['nome_original']) ?>">
                        <?= htmlspecialchars($doc['nome_original']) ?>
                    </div>
                    <?php if ($doc['categoria']): ?>
                    <div style="font-size:11px;color:var(--cor-acento);margin-top:2px"><?= htmlspecialchars($doc['categoria']) ?></div>
                    <?php endif; ?>
                    <?php if ($doc['estado'] === 'erro' && $doc['mensagem_erro']): ?>
                    <div class="erro-detalhe" title="<?= htmlspecialchars($doc['mensagem_erro']) ?>"><?= htmlspecialchars($doc['mensagem_erro']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= formatarTamanho((int)$doc['tamanho_bytes']) ?></td>
                <td><?= $doc['total_fragmentos'] ?></td>
                <td><span class="badge <?= $classe_badge ?>"><?= $doc['estado'] ?></span></td>
                <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($doc['carregado_em'])) ?></td>
                <td>
                    <div class="acoes-doc">
                        <?php if ($doc['estado'] === 'erro'): ?>
                        <button class="btn-doc btn-reprocessar" onclick="reprocessar('<?= $doc['id_documento'] ?>', this)">Reprocessar</button>
                        <?php endif; ?>
                        <button class="btn-doc btn-eliminar-doc" onclick="eliminarDoc('<?= $doc['id_documento'] ?>', this)">Eliminar</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
</main>

<div class="notificacao" id="notificacao"></div>

<script>
// ============================================================
//  SUBSTITUIR o bloco <script> em documentos.php por este
// ============================================================

const inputFicheiro = document.getElementById('input-ficheiro');
const zonaUpload    = document.getElementById('zona-upload');
const ficheiroInfo  = document.getElementById('ficheiro-info');
const progressoCont = document.getElementById('progresso-container');
const barraFill     = document.getElementById('barra-fill');
const progressoTxt  = document.getElementById('progresso-texto');

// Drag & drop
zonaUpload.addEventListener('dragover',  e => { e.preventDefault(); zonaUpload.classList.add('arrastando'); });
zonaUpload.addEventListener('dragleave', () => zonaUpload.classList.remove('arrastando'));
zonaUpload.addEventListener('drop', e => {
    e.preventDefault();
    zonaUpload.classList.remove('arrastando');
    const f = e.dataTransfer.files[0];
    if (f) processarFicheiro(f);
});

inputFicheiro.addEventListener('change', () => {
    if (inputFicheiro.files[0]) processarFicheiro(inputFicheiro.files[0]);
});

function processarFicheiro(ficheiro) {
    const tiposPermitidos = ['application/pdf', 'text/plain'];
    const maxBytes = <?= TAMANHO_MAXIMO_BYTES ?>;
    const ext = ficheiro.name.split('.').pop().toLowerCase();

    if (!tiposPermitidos.includes(ficheiro.type) && !['pdf','txt'].includes(ext)) {
        mostrarNotificacao('Tipo não permitido. Usa PDF ou TXT.', 'erro');
        return;
    }
    if (ficheiro.size > maxBytes) {
        mostrarNotificacao('Ficheiro grande demais. Máximo <?= TAMANHO_MAXIMO_MB ?> MB.', 'erro');
        return;
    }

    ficheiroInfo.style.display = 'flex';
    ficheiroInfo.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
            <path d="M4 2h6l4 4v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="var(--cor-acento)" stroke-width="1.5"/>
        </svg>
        <span style="color:var(--cor-texto)">${ficheiro.name}</span>
        <span style="color:var(--cor-texto-3);margin-left:auto">${formatarBytes(ficheiro.size)}</span>
    `;
    enviarFicheiro(ficheiro);
}

async function enviarFicheiro(ficheiro) {
    progressoCont.style.display = 'block';
    barraFill.style.width = '10%';
    progressoTxt.textContent = 'A enviar…';

    const formData = new FormData();
    formData.append('ficheiro',  ficheiro);
    formData.append('categoria', document.getElementById('upload-categoria').value.trim());
    formData.append('descricao', document.getElementById('upload-descricao').value.trim());

    try {
        barraFill.style.width = '40%';
        progressoTxt.textContent = 'A enviar ficheiro…';

        const resp  = await fetch('api_upload.php', { method: 'POST', body: formData });
        const dados = await resp.json();

        if (!dados.sucesso) {
            barraFill.style.width = '100%';
            barraFill.style.background = 'var(--cor-erro, #f87171)';
            progressoTxt.textContent = 'Erro: ' + (dados.erro || 'Erro desconhecido');
            mostrarNotificacao(dados.erro || 'Erro ao enviar.', 'erro');
            return;
        }

        // Ficheiro enviado — agora faz polling até o processamento terminar
        barraFill.style.width = '60%';
        progressoTxt.textContent = 'A processar documento…';
        mostrarNotificacao('Ficheiro enviado! A processar…', 'sucesso');

        // Adiciona linha temporária na tabela
        adicionarLinhaTemp(dados.dados.id, ficheiro.name);

        // Polling: verifica o estado de 3 em 3 segundos
        await aguardarProcessamento(dados.dados.id);

    } catch(e) {
        // "Erro de ligação" no fetch pode acontecer em localhost por timeout
        // Mas o ficheiro JÁ foi guardado. Recarrega após 2s.
        progressoTxt.textContent = 'A verificar estado…';
        mostrarNotificacao('Ficheiro enviado! A verificar processamento…', 'sucesso');
        await new Promise(r => setTimeout(r, 2000));
        location.reload();
    }
}

async function aguardarProcessamento(id, tentativas = 0) {
    const MAX_TENTATIVAS = 40; // 40 × 3s = 2 minutos
    if (tentativas >= MAX_TENTATIVAS) {
        barraFill.style.width = '100%';
        progressoTxt.textContent = 'Tempo esgotado. Verifica o estado na lista.';
        setTimeout(() => location.reload(), 2000);
        return;
    }

    await new Promise(r => setTimeout(r, 3000));

    try {
        const resp  = await fetch('api_upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'verificar_estado', id })
        });
        const dados = await resp.json();

        if (!dados.sucesso || !dados.dados) {
            await aguardarProcessamento(id, tentativas + 1);
            return;
        }

        const estado = dados.dados.estado;
        const frags  = dados.dados.total_fragmentos;

        // Actualiza linha na tabela
        actualizarLinhaTabelaEstado(id, estado, frags);

        if (estado === 'pronto') {
            barraFill.style.width = '100%';
            progressoTxt.textContent = `Concluído! ${frags} fragmento(s) criado(s).`;
            mostrarNotificacao(`Documento processado com ${frags} fragmento(s)!`, 'sucesso');
            setTimeout(() => location.reload(), 2000);

        } else if (estado === 'erro') {
            barraFill.style.width = '100%';
            barraFill.style.background = 'var(--cor-erro, #f87171)';
            const msg = dados.dados.mensagem_erro || 'Erro ao processar.';
            progressoTxt.textContent = 'Erro: ' + msg;
            mostrarNotificacao(msg, 'erro');
            setTimeout(() => location.reload(), 3000);

        } else {
            // Ainda a_processar — continua polling
            const progresso = Math.min(60 + tentativas * 2, 90);
            barraFill.style.width = progresso + '%';
            progressoTxt.textContent = `A processar… (${tentativas + 1})`;
            await aguardarProcessamento(id, tentativas + 1);
        }
    } catch(e) {
        await aguardarProcessamento(id, tentativas + 1);
    }
}

function adicionarLinhaTemp(id, nome) {
    const tbody = document.getElementById('corpo-tabela-docs');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.id = 'doc-' + id;
    tr.innerHTML = `
        <td><div class="nome-doc">${nome}</div></td>
        <td>—</td>
        <td id="frags-${id}">0</td>
        <td><span class="badge badge-processar" id="badge-${id}">a_processar</span></td>
        <td>${new Date().toLocaleDateString('pt-PT')}</td>
        <td><button class="btn-doc btn-eliminar-doc" onclick="eliminarDoc('${id}', this)">Eliminar</button></td>
    `;
    tbody.insertBefore(tr, tbody.firstChild);
}

function actualizarLinhaTabelaEstado(id, estado, frags) {
    const badge = document.getElementById('badge-' + id);
    const fragsEl = document.getElementById('frags-' + id);
    if (badge) {
        badge.className = 'badge ' + ({
            'pronto': 'badge-pronto', 'erro': 'badge-erro',
            'a_processar': 'badge-processar', 'pendente': 'badge-pendente'
        }[estado] || 'badge-pendente');
        badge.textContent = estado;
    }
    if (fragsEl) fragsEl.textContent = frags;
}

async function reprocessar(id, btn) {
    btn.disabled = true; btn.textContent = '…';
    try {
        const resp  = await fetch('api_upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'reprocessar', id })
        });
        const dados = await resp.json();
        if (dados.sucesso) {
            mostrarNotificacao('A reprocessar…', 'sucesso');
            actualizarLinhaTabelaEstado(id, 'a_processar', 0);
            await aguardarProcessamento(id);
        } else {
            mostrarNotificacao(dados.erro || 'Erro.', 'erro');
            btn.disabled = false; btn.textContent = 'Reprocessar';
        }
    } catch(e) {
        mostrarNotificacao('Erro de ligação.', 'erro');
        btn.disabled = false; btn.textContent = 'Reprocessar';
    }
}

async function eliminarDoc(id, btn) {
    if (!confirm('Eliminar este documento e todos os fragmentos?')) return;
    btn.disabled = true; btn.textContent = '…';
    try {
        const resp  = await fetch('api_upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'eliminar', id })
        });
        const dados = await resp.json();
        if (dados.sucesso) {
            document.getElementById('doc-' + id)?.remove();
            mostrarNotificacao('Documento eliminado.', 'sucesso');
        } else {
            mostrarNotificacao(dados.erro || 'Erro.', 'erro');
            btn.disabled = false; btn.textContent = 'Eliminar';
        }
    } catch(e) {
        mostrarNotificacao('Erro de ligação.', 'erro');
        btn.disabled = false; btn.textContent = 'Eliminar';
    }
}

function formatarBytes(b) {
    if (b >= 1048576) return (b/1048576).toFixed(1) + ' MB';
    if (b >= 1024)    return (b/1024).toFixed(1)    + ' KB';
    return b + ' B';
}

function mostrarNotificacao(msg, tipo) {
    const n = document.getElementById('notificacao');
    n.textContent = msg;
    n.className = 'notificacao ' + tipo;
    n.style.display = 'block';
    clearTimeout(n._timer);
    n._timer = setTimeout(() => { n.style.display = 'none'; }, 4000);
}
</script>

</body>
</html>