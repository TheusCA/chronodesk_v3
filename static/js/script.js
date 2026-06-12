// ============================================================
// ChronoDesk v3.0 - script.js
// FIX: double setInterval removido (estava duplicado)
// NOVO: seleção de CI por nome ao invés de ID numérico
// NOVO: confirmação antes de iniciar/finalizar
// NOVO: banner fixo para alertas críticos
// ============================================================

// ----------- Mensagens -----------
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function exibirMensagem(mensagem, tipo) {
    const messageArea = document.getElementById('message-area');
    const icon = tipo === 'success' ? '✅' : tipo === 'error' ? '❌' : tipo === 'warning' ? '⚠️' : tipo === 'critical' ? '🚨' : 'ℹ️';
    const safeTipo = ['success', 'error', 'warning', 'critical'].includes(tipo) ? tipo : 'info';
    messageArea.innerHTML = `<div class="message ${safeTipo}">${icon} ${escapeHtml(mensagem)}</div>`;
    if (tipo !== 'critical') {
        setTimeout(() => {
            messageArea.style.opacity = '0';
            messageArea.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                messageArea.innerHTML = '';
                messageArea.style.opacity = '1';
                messageArea.style.transform = 'translateY(0)';
            }, 300);
        }, 5000);
    }
}

// Banner fixo para alertas críticos (não some automaticamente)
function exibirBannerCritico(nomes) {
    let banner = document.getElementById('banner-critico');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'banner-critico';
        banner.className = 'banner-critico';
        banner.innerHTML = `
            <span id="banner-critico-texto"></span>
            <button onclick="fecharBannerCritico()" class="btn-fechar-banner">✕</button>
        `;
        document.body.prepend(banner);
    }
    document.getElementById('banner-critico-texto').textContent =
        `🚨 ALERTA CRÍTICO: ${nomes} em pausa há mais de 20 minutos!`;
    banner.style.display = 'flex';
}

function fecharBannerCritico() {
    const b = document.getElementById('banner-critico');
    if (b) b.style.display = 'none';
}

// ----------- Sessão CI -----------
function getCiFuncionarioId() {
    return parseInt(window.CI_FUNCIONARIO_ID || 0, 10);
}

function isAppDebugMode() {
    return window.APP_DEBUG === true || document.querySelector('meta[name="app-debug"]')?.getAttribute('content') === 'true';
}

function logDebug(...args) {
    if (!isAppDebugMode()) return;
    if (typeof debugLog === 'function') {
        debugLog(...args);
        return;
    }
    console.log(...args);
}

function getApiBaseUrl() {
    if (window.API_BASE_URL && typeof window.API_BASE_URL === 'string') {
        return window.API_BASE_URL.replace(/\/$/, '');
    }

    const path = window.location.pathname || '';
    let basePath = path;

    if (basePath.endsWith('/')) {
        basePath = basePath.slice(0, -1);
    } else {
        const lastSlash = basePath.lastIndexOf('/');
        if (lastSlash >= 0) {
            basePath = basePath.substring(0, lastSlash);
        }
    }

    return (basePath || '') + '/api';
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function getJsonHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken()
    };
}

async function fetchJsonSeguro(url, options = {}) {
    const response = await fetch(url, options);
    const contentType = response.headers.get('content-type') || '';
    const text = await response.text();

    let data = null;
    if (contentType.includes('application/json')) {
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('A API retornou JSON inválido.');
        }
    } else {
        const preview = text.substring(0, 120).replace(/\s+/g, ' ');
        throw new Error(`A API retornou resposta não JSON. HTTP ${response.status}. Prévia: ${preview}`);
    }

    return { response, data };
}

function limparSenhaCi() {
    const senha = document.getElementById('ci-senha-ad');
    if (senha) senha.value = '';
}

function setDashboardVisible(visible) {
    const loginScreen = document.getElementById('ci-login-screen');
    const dashboard = document.getElementById('dashboard-main');
    const sessionInfo = document.getElementById('ci-session-info');
    if (loginScreen) {
        loginScreen.classList.toggle('hidden', visible);
        loginScreen.style.display = visible ? 'none' : '';
    }
    if (dashboard) {
        dashboard.classList.toggle('hidden', !visible);
        dashboard.style.display = visible ? '' : 'none';
    }
    if (sessionInfo) sessionInfo.classList.toggle('hidden', !visible);
}

function setButtonLoading(button, loading, loadingText = 'Processando...') {
    if (!button) return;
    if (loading) {
        button.dataset.originalText = button.textContent;
        button.textContent = loadingText;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    } else {
        button.textContent = button.dataset.originalText || button.textContent;
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

async function loginCi(event) {
    event.preventDefault();
    const submitButton = event.currentTarget.querySelector('button[type="submit"]');
    const login = document.getElementById('ci-login-ad');
    const senha = document.getElementById('ci-senha-ad');
    const loginAd = login ? login.value.trim() : '';
    const senhaAd = senha ? senha.value : '';

    if (!loginAd || !senhaAd) {
        exibirMensagem('Informe login e senha do AD.', 'error');
        return;
    }

    setButtonLoading(submitButton, true, 'Entrando...');
    try {
        const { data: resultado } = await fetchJsonSeguro(getApiBaseUrl() + '/login_ci.php', {
            method: 'POST',
            headers: getJsonHeaders(),
            body: JSON.stringify({ login_ad: loginAd, senha_ad: senhaAd })
        });
        limparSenhaCi();

        if (!resultado.sucesso) {
            exibirMensagem(resultado.mensagem || 'Falha no login CI.', 'error');
            return;
        }

        window.CI_AUTHENTICATED = true;
        window.CI_FUNCIONARIO_ID = parseInt(resultado.ci?.funcionario_id || 0, 10);
        window.CI_NOME = resultado.ci?.nome || '';

        const sessionName = document.getElementById('ci-session-name');
        if (sessionName) sessionName.textContent = window.CI_NOME;
        setDashboardVisible(true);
        await carregarFuncionariosSelect();
        await atualizarStatus();
        exibirMensagem('Login CI realizado com sucesso.', 'success');
    } catch (error) {
        limparSenhaCi();
        exibirMensagem('Erro ao autenticar CI: ' + error.message, 'error');
    } finally {
        setButtonLoading(submitButton, false);
    }
}

async function logoutCi() {
    const logoutButton = document.getElementById('btn-ci-logout');
    setButtonLoading(logoutButton, true, 'Saindo...');
    try {
        const { data: resultado } = await fetchJsonSeguro(getApiBaseUrl() + '/logout_ci.php', {
            method: 'POST',
            headers: getJsonHeaders(),
            body: JSON.stringify({})
        });
        if (resultado && resultado.sucesso === false) {
            logDebug('Falha ao encerrar sessão CI:', resultado.mensagem || resultado);
        }
    } catch (error) {
        logDebug('Falha ao encerrar sessão CI:', error);
    }
    window.CI_AUTHENTICATED = false;
    window.CI_FUNCIONARIO_ID = 0;
    window.CI_NOME = '';
    setDashboardVisible(false);
    limparSenhaCi();
    setButtonLoading(logoutButton, false);
    exibirMensagem('Sessão encerrada.', 'success');
}

// ----------- Lista de funcionários para selects -----------
let _funcionariosCache = [];

async function carregarFuncionariosSelect() {
    try {
        const apiUrl = getApiBaseUrl();
        const resp = await fetch(apiUrl + '/listar_funcionarios.php');
        const data = await resp.json();
        if (data.sucesso && data.funcionarios) {
            const ciId = getCiFuncionarioId();
            _funcionariosCache = ciId > 0
                ? data.funcionarios.filter(f => parseInt(f.id, 10) === ciId)
                : data.funcionarios;
            preencherSelects(_funcionariosCache);
        }
    } catch (e) {
        console.warn('Não foi possível carregar lista de funcionários:', e);
    }
}

function preencherSelects(funcionarios) {
    ['select-iniciar-ci', 'select-finalizar-ci'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const valorAtual = sel.value;
        sel.innerHTML = '<option value="">— Selecione o CI —</option>';
        funcionarios
            .filter(f => f.ativo !== false)
            .sort((a, b) => a.nome.localeCompare(b.nome))
            .forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = `${f.nome} (${f.equipe.toUpperCase()})`;
                sel.appendChild(opt);
            });
        if (valorAtual) sel.value = valorAtual;
    });
}

// ----------- Iniciar Pausa -----------
async function iniciarPausa() {
    const sel = document.getElementById('select-iniciar-ci');
    const funcionarioId = parseInt(sel ? sel.value : '');
    const motivoPausa = document.getElementById('motivo-pausa').value;

    if (!funcionarioId) {
        exibirMensagem('Por favor, selecione o CI.', 'error');
        return;
    }
    if (!motivoPausa) {
        exibirMensagem('Por favor, selecione o motivo da pausa.', 'error');
        return;
    }
    if (motivoPausa === 'Reunião') {
        exibirMensagem('Para pausas de reunião, use o botão "Solicitar" e preencha a observação.', 'warning');
        return;
    }

    if (!window.CI_AUTHENTICATED || getCiFuncionarioId() !== funcionarioId) {
        exibirMensagem('Faça login com o CI selecionado antes de iniciar pausa.', 'error');
        return;
    }

    // Confirmação com nome do CI
    const nomeCi = sel.options[sel.selectedIndex]?.text || `ID ${funcionarioId}`;
    if (!confirm(`Confirmar início de pausa para ${nomeCi}?\nMotivo: ${motivoPausa}`)) {
        return;
    }

    const actionButton = document.getElementById('btn-iniciar-normal');
    setButtonLoading(actionButton, true, 'Iniciando...');
    try {
        const apiUrl = getApiBaseUrl();
        const { data: resultado } = await fetchJsonSeguro(apiUrl + '/iniciar_pausa.php', {
            method: 'POST',
            headers: getJsonHeaders(),
            body: JSON.stringify({
                funcionario_id: funcionarioId,
                motivo_pausa: motivoPausa
            })
        });
        if (resultado.sucesso) {
            exibirMensagem(resultado.mensagem, 'success');
            sel.value = '';
            document.getElementById('motivo-pausa').value = '';
            atualizarStatus();
        } else {
            exibirMensagem(resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagem('Erro ao iniciar pausa: ' + error.message, 'error');
    } finally {
        setButtonLoading(actionButton, false);
    }
}

// ----------- Finalizar Pausa -----------
async function finalizarPausa() {
    const sel = document.getElementById('select-finalizar-ci');
    const funcionarioId = parseInt(sel ? sel.value : '');

    if (!funcionarioId) {
        exibirMensagem('Por favor, selecione o CI.', 'error');
        return;
    }

    const nomeCi = sel.options[sel.selectedIndex]?.text || `ID ${funcionarioId}`;

    if (!window.CI_AUTHENTICATED || getCiFuncionarioId() !== funcionarioId) {
        exibirMensagem('Faça login com o CI selecionado antes de finalizar pausa.', 'error');
        return;
    }

    if (!confirm(`Confirmar finalização de pausa para ${nomeCi}?`)) {
        return;
    }

    const actionButton = document.getElementById('btn-finalizar-pausa');
    setButtonLoading(actionButton, true, 'Finalizando...');
    try {
        const apiUrl = getApiBaseUrl();
        const { data: resultado } = await fetchJsonSeguro(apiUrl + '/finalizar_pausa.php', {
            method: 'POST',
            headers: getJsonHeaders(),
            body: JSON.stringify({
                funcionario_id: funcionarioId
            })
        });
        if (resultado.sucesso) {
            exibirMensagem(resultado.mensagem, 'success');
            sel.value = '';
            atualizarStatus();
        } else {
            exibirMensagem(resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagem('Erro ao finalizar pausa: ' + error.message, 'error');
    } finally {
        setButtonLoading(actionButton, false);
    }
}

// ----------- Status -----------
async function atualizarStatus() {
    try {
        const equipeN1 = document.getElementById('equipe-n1');
        const equipeN2 = document.getElementById('equipe-n2');
        if (equipeN1) equipeN1.style.opacity = '0.6';
        if (equipeN2) equipeN2.style.opacity = '0.6';

        const apiUrl = getApiBaseUrl();
        const response = await fetch(apiUrl + '/status.php');
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const status = await response.json();

        if (!status.n1) status.n1 = [];
        if (!status.n2) status.n2 = [];

        if (equipeN1) equipeN1.style.opacity = '1';
        if (equipeN2) equipeN2.style.opacity = '1';

        verificarPausasEsquecidas(status);

        if (equipeN1) {
            equipeN1.innerHTML = '';
            if (status.n1.length > 0) {
                status.n1.forEach(f => { try { equipeN1.appendChild(criarCardFuncionario(f)); } catch(e) { console.error(e); } });
            } else {
                equipeN1.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-secondary);">Nenhum funcionário na equipe N1</div>';
            }
        }
        if (equipeN2) {
            equipeN2.innerHTML = '';
            if (status.n2.length > 0) {
                status.n2.forEach(f => { try { equipeN2.appendChild(criarCardFuncionario(f)); } catch(e) { console.error(e); } });
            } else {
                equipeN2.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-secondary);">Nenhum funcionário na equipe N2</div>';
            }
        }

        // Atualizar contadores de pausa nos selects (mostrar quantos CIs estão em pausa)
        atualizarContadorPausas(status);

    } catch (error) {
        console.error('Erro ao atualizar status:', error);
        exibirMensagem('Erro ao atualizar status: ' + error.message, 'error');
        const e1 = document.getElementById('equipe-n1');
        const e2 = document.getElementById('equipe-n2');
        if (e1) e1.style.opacity = '1';
        if (e2) e2.style.opacity = '1';
    }
}

function atualizarContadorPausas(status) {
    const todos = [...(status.n1 || []), ...(status.n2 || [])];
    const emPausa = todos.filter(f => f.em_pausa).length;
    const contador = document.getElementById('contador-pausas');
    if (contador) {
        contador.textContent = emPausa > 0 ? `${emPausa} em pausa` : 'Nenhum em pausa';
        contador.className = emPausa > 0 ? 'contador-pausas ativo' : 'contador-pausas';
    }
}

// ----------- Alertas -----------
function verificarPausasEsquecidas(status) {
    const LIMITE_15 = 15 * 60;
    const LIMITE_20 = 20 * 60;
    let alerta20 = [], alerta15 = [];

    [...(status.n1 || []), ...(status.n2 || [])].forEach(f => {
        if (f.em_pausa) {
            if (f.tempo_pausa >= LIMITE_20) alerta20.push(f);
            else if (f.tempo_pausa >= LIMITE_15) alerta15.push(f);
        }
    });

    if (alerta20.length > 0) {
        exibirBannerCritico(alerta20.map(f => f.nome).join(', '));
    } else {
        fecharBannerCritico();
        if (alerta15.length > 0) {
            exibirMensagem(`ATENÇÃO: ${alerta15.map(f => f.nome).join(', ')} em pausa há mais de 15 min.`, 'warning');
        }
    }
}

// ----------- Cards -----------
function criarCardFuncionario(funcionario) {
    const card = document.createElement('div');
    const LIMITE_15 = 15 * 60;
    const LIMITE_20 = 20 * 60;

    let disponibilidade = funcionario.disponibilidade || {};
    if (!disponibilidade.status) {
        if (!funcionario.ativo) disponibilidade = {status:'inativo', label:'Inativo', cor:'#9ca3af'};
        else if (funcionario.em_pausa) disponibilidade = {status:'em_pausa', label:'Em Pausa', cor:'#ef4444'};
        else {
            const agora = new Date();
            const horaAtual = agora.getHours() * 100 + agora.getMinutes();
            const entrada = parseTime(funcionario.jornada_entrada || '08:00');
            const saida = parseTime(funcionario.jornada_saida || '17:00');
            const almocoIni = parseTime(funcionario.almoco_inicio || '12:00');
            const almocoFim = parseTime(funcionario.almoco_fim || '13:00');
            if (horaAtual < entrada) disponibilidade = {status:'antes_jornada', label:'Antes da jornada', cor:'#f59e0b'};
            else if (horaAtual >= saida) disponibilidade = {status:'apos_jornada', label:'Após a jornada', cor:'#f59e0b'};
            else if (horaAtual >= almocoIni && horaAtual < almocoFim) disponibilidade = {status:'almoco', label:'Horário de almoço', cor:'#ef4444'};
            else disponibilidade = {status:'disponivel', label:'Disponível', cor:'#10b981'};
        }
    }

    let nivelAlerta = 'normal';
    if (funcionario.em_pausa && funcionario.tempo_pausa >= LIMITE_20) nivelAlerta = 'critico';
    else if (funcionario.em_pausa && funcionario.tempo_pausa >= LIMITE_15) nivelAlerta = 'alerta';

    const solicitacaoPendente = funcionario.status_aprovacao === 'pendente';
    let classes = 'funcionario-card';
    if (solicitacaoPendente) {
        classes += ' solicitacao-pendente';
    } else if (funcionario.em_pausa) {
        classes += ' em-pausa';
        if (nivelAlerta === 'critico') classes += ' alerta-critico';
        else if (nivelAlerta === 'alerta') classes += ' alerta-15min';
    } else {
        switch(disponibilidade.status) {
            case 'disponivel': classes += ' disponivel'; break;
            case 'almoco': classes += ' horario-almoco'; break;
            case 'antes_jornada': case 'apos_jornada': classes += ' fora-jornada'; break;
            case 'inativo': classes += ' inativo'; break;
            default: classes += ' indisponivel';
        }
    }
    card.className = classes;

    const iconeStatus = solicitacaoPendente ? '📋' : (funcionario.em_pausa ? '🔴' : obterIconeDisponibilidade(disponibilidade.status));
    const textoStatus = solicitacaoPendente ? 'Aguardando aprovação' : (funcionario.em_pausa ? 'Em Pausa' : (disponibilidade.label || 'Indisponível'));
    let corStatus = '#E81123';
    if (solicitacaoPendente) {
        corStatus = '#eab308';
    } else if (!funcionario.em_pausa) {
        switch(disponibilidade.status) {
            case 'disponivel': corStatus = '#107C10'; break;
            case 'antes_jornada': case 'apos_jornada': corStatus = '#FFB900'; break;
            case 'inativo': corStatus = '#707070'; break;
        }
    }

    let tempoPausaHtml = '';
    if (funcionario.em_pausa && funcionario.tempo_pausa > 0) {
        const m = Math.floor(funcionario.tempo_pausa / 60);
        const s = funcionario.tempo_pausa % 60;
        tempoPausaHtml = `<div class="status-tempo-pausa">⏱️ ${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}</div>`;
    }

    let alertaHtml = '';
    if (solicitacaoPendente) {
        alertaHtml = '<div class="status-alerta status-pendente">Pendente</div>';
    } else if (funcionario.em_pausa) {
        if (nivelAlerta === 'critico') alertaHtml = '<div class="status-alerta status-critico">🚨 Crítico</div>';
        else if (nivelAlerta === 'alerta') alertaHtml = '<div class="status-alerta status-warning">⚠️ 15min</div>';
    }

    // Contador de pausas do dia (se disponível)
    let pausasHojeHtml = '';
    if (funcionario.pausas_hoje !== undefined) {
        pausasHojeHtml = `<div class="detalhe-item"><span class="detalhe-label">Pausas hoje:</span> <span class="detalhe-valor">${funcionario.pausas_hoje}</span></div>`;
    }

    const safeFuncionarioId = parseInt(funcionario.id, 10) || 0;
    const detalhesId = `detalhes-${safeFuncionarioId}-${Date.now()}`;
    let detalhesHtml = pausasHojeHtml;
    if (funcionario.em_pausa && funcionario.motivo_pausa) detalhesHtml += `<div class="detalhe-item"><span class="detalhe-label">Motivo:</span> <span class="detalhe-valor">${obterIconeMotivo(funcionario.motivo_pausa)} ${escapeHtml(funcionario.motivo_pausa)}</span></div>`;
    if (funcionario.jornada_entrada && funcionario.jornada_saida) detalhesHtml += `<div class="detalhe-item"><span class="detalhe-label">Jornada:</span> <span class="detalhe-valor">${escapeHtml(funcionario.jornada_entrada)} - ${escapeHtml(funcionario.jornada_saida)}</span></div>`;
    if (funcionario.ativo === false) detalhesHtml += `<div class="detalhe-item"><span class="detalhe-label">Status:</span> <span class="detalhe-valor inativo">⛔ Inativo</span></div>`;

    card.innerHTML = `
        <div class="card-header">
            <div class="funcionario-nome">${escapeHtml(funcionario.nome)}</div>
            <div class="funcionario-equipe-badge">${escapeHtml((funcionario.equipe || '').toUpperCase())}</div>
        </div>
        <div class="status-principal" style="border-left:4px solid ${corStatus};">
            <div class="status-icone-texto">
                <span class="status-icone" style="color:${corStatus};font-size:2rem;">${iconeStatus}</span>
                <div class="status-texto-container">
                    <div class="status-texto-principal" style="color:${corStatus};font-weight:600;font-size:1.125rem;">${escapeHtml(textoStatus)}</div>
                    ${tempoPausaHtml}
                </div>
            </div>
            ${alertaHtml}
        </div>
        ${detalhesHtml ? `
        <div class="card-detalhes-container">
            <button class="btn-detalhes" onclick="toggleDetalhes('${detalhesId}')" type="button">
                <span class="detalhes-icon">▶</span> <span class="detalhes-texto">Detalhes</span>
            </button>
            <div id="${detalhesId}" class="card-detalhes" style="display:none;">${detalhesHtml}</div>
        </div>` : ''}
    `;
    return card;
}

function parseTime(t) {
    if (!t) return 0;
    const p = t.split(':');
    return parseInt(p[0]) * 100 + parseInt(p[1] || 0);
}

function toggleDetalhes(id) {
    const d = document.getElementById(id);
    const btn = d.previousElementSibling;
    const icon = btn.querySelector('.detalhes-icon');
    if (d.style.display === 'none') { d.style.display = 'block'; icon.textContent = '▼'; btn.classList.add('ativo'); }
    else { d.style.display = 'none'; icon.textContent = '▶'; btn.classList.remove('ativo'); }
}

function obterIconeDisponibilidade(status) {
    const m = {disponivel:'🟢', almoco:'🍽️', antes_jornada:'⏸️', apos_jornada:'⏸️', inativo:'⛔', em_pausa:'🔴'};
    return m[status] || '⚪';
}

function obterIconeMotivo(motivo) {
    const m = {'Café':'☕', 'Pessoal':'👤', 'Reunião':'🤝'};
    return m[motivo] || '❓';
}

// ----------- Reunião -----------
function verificarMotivoReuniao() {
    const motivo = document.getElementById('motivo-pausa').value;
    const obs = document.getElementById('observacao-reuniao');
    const btnNormal = document.getElementById('btn-iniciar-normal');
    if (motivo === 'Reunião') {
        obs.style.display = 'block';
        btnNormal.style.display = 'none';
    } else {
        obs.style.display = 'none';
        btnNormal.style.display = 'inline-block';
    }
}

async function solicitarPausaComAprovacao() {
    const sel = document.getElementById('select-iniciar-ci');
    const funcionarioId = parseInt(sel ? sel.value : '');
    const observacao = document.getElementById('observacao-texto').value.trim();

    if (!funcionarioId) { exibirMensagem('Por favor, selecione o CI.', 'error'); return; }
    if (!observacao) { exibirMensagem('Por favor, descreva o motivo da reunião.', 'error'); return; }

    const nomeCi = sel.options[sel.selectedIndex]?.text || `ID ${funcionarioId}`;

    if (!window.CI_AUTHENTICATED || getCiFuncionarioId() !== funcionarioId) {
        exibirMensagem('Faça login com o CI selecionado antes de solicitar pausa.', 'error');
        return;
    }

    if (!confirm(`Solicitar pausa de reunião para ${nomeCi}?\n\nObservação: ${observacao}`)) {
        return;
    }

    const actionButton = document.getElementById('btn-solicitar-pausa');
    setButtonLoading(actionButton, true, 'Enviando...');
    try {
        const apiUrl = getApiBaseUrl();
        const { data } = await fetchJsonSeguro(apiUrl + '/solicitar_pausa_com_aprovacao.php', {
            method: 'POST',
            headers: getJsonHeaders(),
            body: JSON.stringify({
                funcionario_id: funcionarioId,
                motivo_pausa: 'Reunião',
                observacao
            })
        });
        if (data.sucesso) {
            exibirMensagem(data.mensagem, 'success');
            sel.value = '';
            document.getElementById('observacao-texto').value = '';
            document.getElementById('motivo-pausa').value = '';
            verificarMotivoReuniao();
            atualizarStatus();
        } else {
            exibirMensagem(data.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagem('Erro ao solicitar pausa de reunião: ' + error.message, 'error');
    } finally {
        setButtonLoading(actionButton, false);
    }
}

// ----------- Init (FIX: apenas 1 setInterval) -----------
document.addEventListener('DOMContentLoaded', function() {
    const ciLoginForm = document.getElementById('ci-login-form');
    const ciLogoutButton = document.getElementById('btn-ci-logout');
    if (ciLoginForm) ciLoginForm.addEventListener('submit', loginCi);
    if (ciLogoutButton) ciLogoutButton.addEventListener('click', logoutCi);

    setDashboardVisible(!!window.CI_AUTHENTICATED);
    if (window.CI_AUTHENTICATED) {
        carregarFuncionariosSelect();
        atualizarStatus();
    }
    // CORREÇÃO: apenas UM setInterval (o outro estava duplicado no escopo global)
    setInterval(() => {
        if (window.CI_AUTHENTICATED) atualizarStatus();
    }, 30000);
    logDebug('API base calculada:', getApiBaseUrl());
    logDebug('ChronoDesk v3.0 inicializado - Atualização automática a cada 30s');
});
