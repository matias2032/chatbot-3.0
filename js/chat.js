// ============================================================
//  CHAT.JS — Gestão de conversas + suporte a utilizadores
//            anónimos e migração pós-login
// ============================================================

// ── Estado global ────────────────────────────────────────────
let ID_SESSAO   = null;
let ID_CONVERSA = null;

// ── Referências DOM ──────────────────────────────────────────
const janela      = document.getElementById('janela-mensagens');
const campo       = document.getElementById('campo-mensagem');
const btnEnviar   = document.getElementById('btn-enviar');
const btnLimpar   = document.getElementById('btn-limpar');
const indicador   = document.getElementById('indicador-digitacao');
const listaChats  = document.getElementById('lista-chats');
const btnNovoChat = document.getElementById('btn-novo-chat');

// ============================================================
// INICIALIZAÇÃO
// ============================================================
document.addEventListener('DOMContentLoaded', async () => {

    // ── 1. Migração pós-login ────────────────────────────────
    if (UTILIZADOR_LOGADO && typeof MIGRAR_SESSAO === 'string' && MIGRAR_SESSAO) {
        try {
            const res = await fetch('api_conversas.php?acao=migrar', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ id_sessao: MIGRAR_SESSAO }),
            });
            const dados = await res.json();

            if (dados.sucesso && dados.dados.migrado) {
                // Limpa vestígios anónimos do localStorage
                localStorage.removeItem('chat_id_sessao');
                localStorage.removeItem('chat_id_conversa');
                // Guarda já como conversa do utilizador logado
                localStorage.setItem('chatbot_id_conversa', dados.dados.id_conversa);
                // Carrega a conversa migrada
                await carregarConversa(dados.dados.id_conversa, false);
                destacarItemActivo();
                console.log('[Chat] Sessão anónima migrada:', dados.dados.id_conversa);
                return; // não precisa de restaurar mais nada
            }
        } catch (e) {
            console.warn('[Chat] Falha na migração:', e);
        }
    }

    // ── 2. Restaurar conversa guardada ───────────────────────
    const chave   = UTILIZADOR_LOGADO ? 'chatbot_id_conversa' : 'chat_id_conversa';
    const idSalvo = localStorage.getItem(chave);

    if (idSalvo) {
        await carregarConversa(idSalvo, false);

        // Se é anónimo, restaura também o ID_SESSAO do localStorage
        if (!UTILIZADOR_LOGADO && !ID_SESSAO) {
            ID_SESSAO = localStorage.getItem('chat_id_sessao');
        }
    }

    destacarItemActivo();
});

// ============================================================
// CARREGAR CONVERSA — busca mensagens do servidor
// ============================================================
async function carregarConversa(idConversa, limparPrimeiro = true) {
    try {
        const resp  = await fetch(`api_conversas.php?acao=carregar&id_conversa=${idConversa}`);
        const dados = await resp.json();

        if (!dados.sucesso || !dados.dados.mensagens.length) {
            // Conversa vazia ou não encontrada — limpa o localStorage
            const chave = UTILIZADOR_LOGADO ? 'chatbot_id_conversa' : 'chat_id_conversa';
            if (idConversa === localStorage.getItem(chave)) {
                localStorage.removeItem(chave);
            }
            return;
        }

        if (limparPrimeiro) limparJanela();

        // Remove boas-vindas — há mensagens reais
        document.getElementById('msg-boas-vindas')?.remove();

        ID_CONVERSA = dados.dados.id_conversa;
        ID_SESSAO   = dados.dados.id_sessao;

        // Persiste a conversa activa
        const chave = UTILIZADOR_LOGADO ? 'chatbot_id_conversa' : 'chat_id_conversa';
        localStorage.setItem(chave, ID_CONVERSA);
        if (!UTILIZADOR_LOGADO) {
            localStorage.setItem('chat_id_sessao', ID_SESSAO);
        }

        // Renderiza todas as mensagens
        for (const msg of dados.dados.mensagens) {
            const tipo = msg.papel === 'utilizador' ? 'user' : 'bot';
            adicionarMensagem(msg.conteudo, tipo, false, msg.enviada_em);
        }

        rolarParaBaixo();
        destacarItemActivo();

    } catch (e) {
        console.error('Erro ao carregar conversa:', e);
    }
}

// ============================================================
// NOVA CONVERSA
// ============================================================
async function novaConversa() {
    try {
        const resp  = await fetch('api_conversas.php?acao=criar');
        const dados = await resp.json();

        if (!dados.sucesso) return;

        ID_CONVERSA = dados.dados.id_conversa;
        ID_SESSAO   = dados.dados.id_sessao;

        if (UTILIZADOR_LOGADO) {
            // Logado: persiste e adiciona à sidebar
            localStorage.setItem('chatbot_id_conversa', ID_CONVERSA);
            adicionarItemSidebar(dados.dados);
            destacarItemActivo();
        } else {
            // Anónimo: guarda só para migração futura
            localStorage.setItem('chat_id_sessao',   ID_SESSAO);
            localStorage.setItem('chat_id_conversa', ID_CONVERSA);
        }

        limparJanela();
        mostrarBoasVindas();

    } catch (e) {
        console.error('Erro ao criar conversa:', e);
    }
}

// ============================================================
// APAGAR CONVERSA
// ============================================================
async function apagarConversa(idConversa) {
    if (!confirm('Apagar esta conversa permanentemente?')) return;

    try {
        const resp  = await fetch('api_conversas.php?acao=apagar', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ id_conversa: idConversa }),
        });
        const dados = await resp.json();

        if (!dados.sucesso) return;

        // Remove item da sidebar
        document.querySelector(`.item-chat[data-id="${idConversa}"]`)?.remove();

        // Se era a conversa activa, limpa e começa de novo
        if (idConversa === ID_CONVERSA) {
            ID_CONVERSA = null;
            ID_SESSAO   = null;
            localStorage.removeItem('chatbot_id_conversa');
            limparJanela();
            mostrarBoasVindas();
        }

        // Mostra "sem conversas" se a lista ficou vazia
        if (listaChats && !listaChats.querySelector('.item-chat')) {
            listaChats.innerHTML = '<p class="sem-chats">Nenhuma conversa ainda</p>';
        }

    } catch (e) {
        console.error('Erro ao apagar conversa:', e);
    }
}

// ============================================================
// SIDEBAR — adicionar item dinamicamente
// ============================================================
function adicionarItemSidebar(conversa) {
    if (!listaChats) return;
    listaChats.querySelector('.sem-chats')?.remove();

    const data = new Date().toLocaleString('pt-PT', {
        day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit',
    });

    const div = document.createElement('div');
    div.className      = 'item-chat';
    div.dataset.id     = conversa.id_conversa;
    div.dataset.sessao = conversa.id_sessao;
    div.innerHTML = `
        <div class="item-chat-icon">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <path d="M2 2h10a1 1 0 011 1v6a1 1 0 01-1 1H5l-3 2V3a1 1 0 011-1z" stroke="currentColor" stroke-width="1.2"/>
            </svg>
        </div>
        <div class="item-chat-info">
            <div class="item-chat-titulo">Nova conversa</div>
            <div class="item-chat-meta">${data} · 0 msgs</div>
        </div>
        <button class="btn-apagar-chat" title="Apagar conversa" data-id="${conversa.id_conversa}">
            <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <path d="M2 3h9M5 3V2h3v1M4 3l.5 7h4L9 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
            </svg>
        </button>`;

    listaChats.prepend(div);
    bindItemChat(div);
}

function actualizarTituloSidebar(texto) {
    if (!listaChats) return;
    const activo = listaChats.querySelector('.item-chat.activo .item-chat-titulo');
    if (activo && activo.textContent === 'Nova conversa') {
        activo.textContent = texto.length > 35 ? texto.slice(0, 35) + '…' : texto;
    }
}

function destacarItemActivo() {
    if (!listaChats) return;
    listaChats.querySelectorAll('.item-chat').forEach(el => {
        el.classList.toggle('activo', el.dataset.id === ID_CONVERSA);
    });
}

function bindItemChat(el) {
    el.addEventListener('click', (e) => {
        if (e.target.closest('.btn-apagar-chat')) return;
        carregarConversa(el.dataset.id, true);
    });

    el.querySelector('.btn-apagar-chat').addEventListener('click', (e) => {
        e.stopPropagation();
        apagarConversa(el.dataset.id);
    });
}

// ============================================================
// BINDINGS — Botões e Eventos
// ============================================================

// Bind nos itens já existentes no HTML (só existem se logado)
document.querySelectorAll('.item-chat').forEach(bindItemChat);

if (btnNovoChat) btnNovoChat.addEventListener('click', novaConversa);
if (btnLimpar)   btnLimpar.addEventListener('click', novaConversa);
if (btnEnviar)   btnEnviar.addEventListener('click', enviarMensagem);

// ============================================================
// JANELA — utilitários
// ============================================================
function limparJanela() {
    janela.innerHTML = '';
}

function mostrarBoasVindas() {
    const div = document.createElement('div');
    div.className = 'mensagem mensagem-bot';
    div.id = 'msg-boas-vindas';
    div.innerHTML = `
        <div class="avatar-bot">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                <circle cx="9" cy="9" r="8" stroke="var(--cor-acento)" stroke-width="1.2"/>
                <circle cx="9" cy="9" r="2" fill="var(--cor-acento)"/>
            </svg>
        </div>
        <div class="balao">
            <p>Olá! Como posso ajudar?</p>
            <div class="sugestoes">
                <button class="sugestao" onclick="usarSugestao(this)">Quem te criou?</button>
                <button class="sugestao" onclick="usarSugestao(this)">O que sabes fazer?</button>
                <button class="sugestao" onclick="usarSugestao(this)">Que documentos tens disponíveis?</button>
            </div>
        </div>`;
    janela.appendChild(div);
}

// ============================================================
// INPUT — auto-resize e atalhos de teclado
// ============================================================
if (campo) {
    campo.addEventListener('input', () => {
        campo.style.height = 'auto';
        campo.style.height = Math.min(campo.scrollHeight, 120) + 'px';
        btnEnviar.disabled = campo.value.trim() === '';
    });

    campo.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!btnEnviar.disabled) enviarMensagem();
        }
    });
}

function usarSugestao(btn) {
    campo.value = btn.textContent;
    campo.style.height = 'auto';
    btnEnviar.disabled = false;
    campo.focus();
}

// ============================================================
// FORMATAR E ESCAPAR
// ============================================================
function escaparHtml(texto) {
    return texto
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatarTexto(texto) {
    return escaparHtml(texto)
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g,     '<em>$1</em>')
        .replace(/`(.*?)`/g,       '<code>$1</code>')
        .replace(/\n/g,            '<br>');
}

function horaFormatada(dataStr) {
    const d = dataStr ? new Date(dataStr) : new Date();
    return d.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });
}

// ============================================================
// ADICIONAR MENSAGEM NA JANELA
// ============================================================
function adicionarMensagem(texto, tipo = 'bot', mostrarHora = true, dataStr = null) {
    if (tipo === 'user') document.getElementById('msg-boas-vindas')?.remove();

    const div = document.createElement('div');
    div.className = `mensagem mensagem-${tipo}`;

    const hora = mostrarHora
        ? `<div class="hora-mensagem">${horaFormatada(dataStr)}</div>`
        : '';

    if (tipo === 'bot') {
        div.innerHTML = `
            <div class="avatar-bot">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                    <circle cx="9" cy="9" r="8" stroke="var(--cor-acento)" stroke-width="1.2"/>
                    <circle cx="9" cy="9" r="2" fill="var(--cor-acento)"/>
                </svg>
            </div>
            <div>
                <div class="balao"><p>${formatarTexto(texto)}</p></div>
                ${hora}
            </div>`;
    } else if (tipo === 'user') {
        div.innerHTML = `
            <div>
                <div class="balao"><p>${escaparHtml(texto)}</p></div>
                ${hora}
            </div>`;
    } else if (tipo === 'erro') {
        div.className = 'mensagem mensagem-bot mensagem-erro';
        div.innerHTML = `
            <div class="avatar-bot">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                    <circle cx="9" cy="9" r="8" stroke="#f87171" stroke-width="1.2"/>
                    <path d="M9 6v4M9 12v.5" stroke="#f87171" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <div><div class="balao"><p>${escaparHtml(texto)}</p></div></div>`;
    }

    janela.appendChild(div);
    rolarParaBaixo();
    return div;
}

function mostrarDigitacao()  { indicador.style.display = 'flex'; rolarParaBaixo(); }
function ocultarDigitacao()  { indicador.style.display = 'none'; }
function rolarParaBaixo()    { setTimeout(() => { janela.scrollTop = janela.scrollHeight; }, 50); }

// ============================================================
// ENVIAR MENSAGEM
// ============================================================
async function enviarMensagem() {
    const texto = campo.value.trim();
    if (!texto) return;

    // Cria conversa automaticamente se ainda não existir
    if (!ID_SESSAO) {
        await novaConversa();
        if (!ID_SESSAO) return; // falhou a criar — aborta
    }

    campo.value = '';
    campo.style.height = 'auto';
    btnEnviar.disabled = true;
    campo.disabled     = true;

    adicionarMensagem(texto, 'user');
    actualizarTituloSidebar(texto);
    mostrarDigitacao();

    try {
        const resposta = await fetch('api_chat.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ mensagem: texto, id_sessao: ID_SESSAO }),
        });

        const dados = await resposta.json();
        ocultarDigitacao();

        if (dados.sucesso) {
            if (dados.dados.id_conversa) {
                ID_CONVERSA = dados.dados.id_conversa;

                if (UTILIZADOR_LOGADO) {
                    localStorage.setItem('chatbot_id_conversa', ID_CONVERSA);
                } else {
                    // Anónimo: mantém ambas as chaves para migração futura
                    localStorage.setItem('chat_id_sessao',   ID_SESSAO);
                    localStorage.setItem('chat_id_conversa', ID_CONVERSA);
                }

                destacarItemActivo();
            }

            adicionarMensagem(dados.dados.resposta, 'bot');

            // Actualiza contador de mensagens na sidebar
            const metaActivo = listaChats?.querySelector('.item-chat.activo .item-chat-meta');
            if (metaActivo) {
                const match = metaActivo.textContent.match(/(\d+) msgs/);
                if (match) {
                    const novaContagem = parseInt(match[1]) + 2;
                    metaActivo.textContent = metaActivo.textContent.replace(/\d+ msgs/, `${novaContagem} msgs`);
                }
            }
        } else {
            adicionarMensagem(dados.erro || 'Ocorreu um erro. Tenta novamente.', 'erro');
        }

    } catch (erro) {
        ocultarDigitacao();
        adicionarMensagem('Não foi possível contactar o servidor. Verifica a tua ligação.', 'erro');
        console.error('Erro ao enviar mensagem:', erro);
    } finally {
        campo.disabled = false;
        campo.focus();
    }
}