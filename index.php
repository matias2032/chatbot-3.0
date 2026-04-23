<?php
// ============================================================
//  INDEX.PHP — Interface pública do chatbot com gestão de chats
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

$pdo = obterConexao();

// Busca dados do perfil e bot
$stmt = $pdo->prepare("
    SELECT b.nome AS nome_bot, b.descricao,
           p.nome_completo, p.profissao, p.url_foto
    FROM configuracao_bot b
    LEFT JOIN perfil_criador p ON p.id_configuracao_bot = b.id_configuracao_bot
    WHERE b.id_configuracao_bot = :bot
    LIMIT 1
");
$stmt->execute([':bot' => BOT_ID]);
$info = $stmt->fetch();

$nome_bot      = $info['nome_bot']      ?? 'ChatBot';
$descricao_bot = $info['descricao']     ?? 'Assistente inteligente';
$nome_criador  = $info['nome_completo'] ?? '';
$profissao     = $info['profissao']     ?? '';

// Busca todas as conversas do bot para listagem na sidebar
$stmt = $pdo->prepare("
    SELECT c.id_conversa, c.id_sessao, c.iniciada_em, c.ultima_mensagem_em,
           COUNT(m.id_mensagem) AS total_msgs,
           (SELECT conteudo FROM mensagens
            WHERE id_conversa = c.id_conversa AND papel = 'utilizador'
            ORDER BY enviada_em ASC LIMIT 1) AS primeira_msg
    FROM conversas c
    LEFT JOIN mensagens m ON m.id_conversa = c.id_conversa
    WHERE c.id_configuracao_bot = :bot
    GROUP BY c.id_conversa, c.id_sessao, c.iniciada_em, c.ultima_mensagem_em
    ORDER BY c.ultima_mensagem_em DESC
");
$stmt->execute([':bot' => BOT_ID]);
$conversas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nome_bot) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        /* ── Gestão de chats na sidebar ── */
        .seccao-chats { margin-top: 1.2rem; flex: 1; display: flex; flex-direction: column; min-height: 0; }
        .seccao-chats-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 0.2rem 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 0.5rem;
        }
        .seccao-chats-titulo { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.4; }
        .btn-novo-chat {
            display: flex; align-items: center; gap: 0.3rem;
            background: var(--cor-acento); color: #000;
            border: none; border-radius: 6px; padding: 0.3rem 0.6rem;
            font-size: 0.72rem; font-weight: 600; cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-novo-chat:hover { opacity: 0.85; }

        .lista-chats { overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 0.2rem; }
        .lista-chats::-webkit-scrollbar { width: 3px; }
        .lista-chats::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

        .item-chat {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.55rem 0.6rem; border-radius: 8px; cursor: pointer;
            transition: background 0.15s; position: relative;
            border: 1px solid transparent;
        }
        .item-chat:hover { background: rgba(255,255,255,0.05); }
        .item-chat.activo { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.08); }
        .item-chat-icon { opacity: 0.4; flex-shrink: 0; }
        .item-chat-info { flex: 1; min-width: 0; }
        .item-chat-titulo {
            font-size: 0.78rem; font-weight: 500; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis; opacity: 0.85;
        }
        .item-chat-meta { font-size: 0.65rem; opacity: 0.35; margin-top: 0.1rem; }
        .btn-apagar-chat {
            background: none; border: none; cursor: pointer;
            opacity: 0; padding: 0.2rem; border-radius: 4px;
            color: #f87171; transition: opacity 0.15s, background 0.15s;
            flex-shrink: 0;
        }
        .item-chat:hover .btn-apagar-chat { opacity: 1; }
        .btn-apagar-chat:hover { background: rgba(248,113,113,0.15); }

        .sem-chats { font-size: 0.75rem; opacity: 0.3; text-align: center; padding: 1rem 0; }
    </style>
</head>
<body>

<!-- ========================================================
     BARRA LATERAL
======================================================== -->
<aside class="barra-lateral">
    <div class="logo-area">
        <div class="logo-icone">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                <circle cx="14" cy="14" r="13" stroke="var(--cor-acento)" stroke-width="1.5"/>
                <path d="M8 14c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6" stroke="var(--cor-acento)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="14" cy="14" r="2.5" fill="var(--cor-acento)"/>
            </svg>
        </div>
        <span class="logo-nome"><?= htmlspecialchars($nome_bot) ?></span>
    </div>

    <div class="bot-info-lateral">
        <p class="bot-descricao"><?= htmlspecialchars($descricao_bot) ?></p>
    </div>

    <?php if ($nome_criador): ?>
    <div class="criador-lateral">
        <div class="criador-etiqueta">Criado por</div>
        <div class="criador-nome"><?= htmlspecialchars($nome_criador) ?></div>
        <?php if ($profissao): ?>
        <div class="criador-profissao"><?= htmlspecialchars($profissao) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Gestão de Chats ── -->
    <div class="seccao-chats">
        <div class="seccao-chats-header">
            <span class="seccao-chats-titulo">Conversas</span>
            <button class="btn-novo-chat" id="btn-novo-chat">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                Nova
            </button>
        </div>

        <div class="lista-chats" id="lista-chats">
            <?php if (empty($conversas)): ?>
                <p class="sem-chats">Nenhuma conversa ainda</p>
            <?php else: foreach ($conversas as $conv):
                $titulo = $conv['primeira_msg']
                    ? mb_strimwidth($conv['primeira_msg'], 0, 35, '…')
                    : 'Conversa sem mensagens';
                $data = date('d/m H:i', strtotime($conv['ultima_mensagem_em']));
            ?>
                <div class="item-chat" data-id="<?= htmlspecialchars($conv['id_conversa']) ?>" data-sessao="<?= htmlspecialchars($conv['id_sessao']) ?>">
                    <div class="item-chat-icon">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                            <path d="M2 2h10a1 1 0 011 1v6a1 1 0 01-1 1H5l-3 2V3a1 1 0 011-1z" stroke="currentColor" stroke-width="1.2"/>
                        </svg>
                    </div>
                    <div class="item-chat-info">
                        <div class="item-chat-titulo"><?= htmlspecialchars($titulo) ?></div>
                        <div class="item-chat-meta"><?= $data ?> · <?= $conv['total_msgs'] ?> msgs</div>
                    </div>
                    <button class="btn-apagar-chat" title="Apagar conversa" data-id="<?= htmlspecialchars($conv['id_conversa']) ?>">
                        <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                            <path d="M2 3h9M5 3V2h3v1M4 3l.5 7h4L9 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <nav class="nav-lateral">
        <a href="admin.php" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="2" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
            Painel Admin
        </a>
    </nav>

    <div class="rodape-lateral">
        <span>Criado por Matias Alberto Matavel</span>
    </div>
</aside>

<!-- ========================================================
     ÁREA PRINCIPAL DO CHAT
======================================================== -->
<main class="area-chat">

    <header class="cabecalho-chat">
        <div class="cabecalho-info">
            <div class="status-indicador"></div>
            <div>
                <h1 class="cabecalho-titulo" id="titulo-chat"><?= htmlspecialchars($nome_bot) ?></h1>
                <p class="cabecalho-subtitulo">Online · Responde em segundos</p>
            </div>
        </div>
        <button class="btn-limpar" id="btn-limpar" title="Nova conversa">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </button>
    </header>

    <div class="janela-mensagens" id="janela-mensagens">
        <div class="mensagem mensagem-bot" id="msg-boas-vindas">
            <div class="avatar-bot">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="8" stroke="var(--cor-acento)" stroke-width="1.2"/><circle cx="9" cy="9" r="2" fill="var(--cor-acento)"/></svg>
            </div>
            <div class="balao">
                <p>Olá! Sou o <strong><?= htmlspecialchars($nome_bot) ?></strong>. Como posso ajudar?</p>
                <div class="sugestoes">
                    <button class="sugestao" onclick="usarSugestao(this)">Quem te criou?</button>
                    <button class="sugestao" onclick="usarSugestao(this)">O que sabes fazer?</button>
                    <button class="sugestao" onclick="usarSugestao(this)">Que documentos tens disponíveis?</button>
                </div>
            </div>
        </div>
    </div>

    <div class="indicador-digitacao" id="indicador-digitacao" style="display:none">
        <div class="avatar-bot">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="8" stroke="var(--cor-acento)" stroke-width="1.2"/><circle cx="9" cy="9" r="2" fill="var(--cor-acento)"/></svg>
        </div>
        <div class="balao balao-digitacao">
            <span></span><span></span><span></span>
        </div>
    </div>

    <div class="area-entrada">
        <div class="caixa-entrada">
            <textarea
                id="campo-mensagem"
                class="campo-mensagem"
                placeholder="Escreve a tua mensagem..."
                rows="1"
                maxlength="2000"
            ></textarea>
            <button class="btn-enviar" id="btn-enviar" disabled>
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                    <path d="M15 9L3 3l3 6-3 6 12-6z" fill="currentColor"/>
                </svg>
            </button>
        </div>
        <p class="aviso-rodape">As respostas baseiam-se no conhecimento configurado.</p>
    </div>

</main>

<script src="js/chat.js"></script>
</body>
</html>