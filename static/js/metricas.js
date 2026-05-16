document.addEventListener("DOMContentLoaded", async function() {
    await carregarMetricas();
    await carregarSolicitacoesPendentes();
});

async function carregarMetricas() {
    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/metricas.php');
        const metricas = await response.json();

        preencherMetrica("total-pausas-funcionario", metricas.total_pausas_funcionario, "pausas");
        preencherMetrica("duracao-total-funcionario", metricas.duracao_total_funcionario, "segundos", formatarTempo);
        preencherMetrica("duracao-media-funcionario", metricas.duracao_media_funcionario, "segundos", formatarTempo);
        preencherMetrica("total-pausas-equipe", metricas.total_pausas_equipe, "pausas");
        preencherMetrica("duracao-total-equipe", metricas.duracao_total_equipe, "segundos", formatarTempo);
        preencherMetrica("duracao-media-equipe", metricas.duracao_media_equipe, "segundos", formatarTempo);
        preencherMetrica("pausas-excedidas", metricas.pausas_excedidas, "pausas excedidas");
        
        // Novas métricas por motivo
        preencherMetricaComIcone("total-pausas-por-motivo", metricas.total_pausas_por_motivo, "pausas");
        preencherMetricaComIcone("duracao-total-por-motivo", metricas.duracao_total_por_motivo, "segundos", formatarTempo);
        preencherMetricaComIcone("duracao-media-por-motivo", metricas.duracao_media_por_motivo, "segundos", formatarTempo);

        // Novas métricas de alertas de 15 e 20 minutos
        preencherMetrica("alertas-15min-funcionario", metricas.alertas_15min_funcionario, "alertas de 15min");
        preencherMetrica("alertas-15min-equipe", metricas.alertas_15min_equipe, "alertas de 15min");
        preencherMetrica("alertas-20min-funcionario", metricas.alertas_20min_funcionario, "alertas de 20min");
        preencherMetrica("alertas-20min-equipe", metricas.alertas_20min_equipe, "alertas de 20min");

    } catch (error) {
        console.error("Erro ao carregar métricas:", error);
        document.querySelector(".metrics-container").innerHTML = "<p style=\"color: red; text-align: center;\">Erro ao carregar métricas. Verifique o console para detalhes.</p>";
    }
}

function preencherMetrica(elementId, data, unit, formatter = null) {
    const element = document.getElementById(elementId);
    if (!element) return;

    // Remover classe de loading
    element.classList.remove('loading-text');

    if (Object.keys(data).length === 0) {
        element.innerHTML = `<p>Nenhum dado disponível para ${elementId.replace(/-/g, " ")}.</p>`;
        return;
    }

    let html = "";
    for (const key in data) {
        let value = data[key];
        if (formatter) {
            value = formatter(value);
        }
        html += `<div class="metric-item"><strong>${key}:</strong> ${value} ${unit}</div>`;
    }
    element.innerHTML = html;
}

function preencherMetricaComIcone(elementId, data, unit, formatter = null) {
    const element = document.getElementById(elementId);
    if (!element) return;

    // Remover classe de loading
    element.classList.remove('loading-text');

    if (Object.keys(data).length === 0) {
        element.innerHTML = `<p>Nenhum dado disponível para ${elementId.replace(/-/g, " ")}.</p>`;
        return;
    }

    let html = "";
    for (const key in data) {
        let value = data[key];
        if (formatter) {
            value = formatter(value);
        }
        const icone = obterIconeMotivo(key);
        html += `<div class="metric-item"><strong>${icone} ${key}:</strong> ${value} ${unit}</div>`;
    }
    element.innerHTML = html;
}

function obterIconeMotivo(motivo) {
    switch(motivo) {
        case 'Café': return '☕';
        case 'Pessoal': return '👤';
        case 'Reunião': return '🤝';
        default: return '❓';
    }
}

function formatarTempo(segundos) {
    if (segundos === null || isNaN(segundos)) return "N/A";
    const minutos = Math.floor(segundos / 60);
    const segs = Math.round(segundos % 60);
    return `${minutos}m ${segs}s`;
}

// Função para carregar solicitações pendentes
async function carregarSolicitacoesPendentes() {
    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/solicitacoes_pendentes.php');
        const data = await response.json();
        
        const container = document.getElementById('pending-requests-container');
        const list = document.getElementById('pending-requests-list');
        
        if (data.solicitacoes && data.solicitacoes.length > 0) {
            container.style.display = 'block';
            list.innerHTML = '';
            
            data.solicitacoes.forEach(solicitacao => {
                const item = criarItemSolicitacao(solicitacao);
                list.appendChild(item);
            });
        } else {
            container.style.display = 'none';
        }
    } catch (error) {
        console.error("Erro ao carregar solicitações pendentes:", error);
    }
}

// Função para criar item de solicitação
function criarItemSolicitacao(solicitacao) {
    const item = document.createElement('div');
    item.className = 'pending-request-item';
    
    const timestamp = new Date(solicitacao.solicitacao_timestamp);
    const timeString = timestamp.toLocaleString('pt-BR');
    
    const iconeMotivo = obterIconeMotivo(solicitacao.motivo);
    
    item.innerHTML = `
        <div class="request-header">
            <div class="request-info">
                ${iconeMotivo} ${solicitacao.nome} (ID: ${solicitacao.id}) - Equipe ${solicitacao.equipe.toUpperCase()} - ${solicitacao.motivo}
            </div>
            <div class="request-time">
                Solicitado em: ${timeString}
            </div>
        </div>
        <div class="request-observation">
            <strong>Observação:</strong> ${solicitacao.observacao || 'Nenhuma observação fornecida'}
        </div>
        <div class="request-actions">
            <button class="btn btn-success btn-sm" onclick="aprovarSolicitacao(${solicitacao.id})">
                ✅ Aprovar
            </button>
            <button class="btn btn-danger btn-sm" onclick="rejeitarSolicitacao(${solicitacao.id})">
                ❌ Rejeitar
            </button>
        </div>
    `;
    
    return item;
}

// Função para aprovar solicitação
async function aprovarSolicitacao(funcionarioId) {
    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/aprovar_pausa.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                funcionario_id: funcionarioId
            })
        });
        
        const data = await response.json();
        
        if (data.sucesso) {
            alert('✅ ' + data.mensagem);
            await carregarSolicitacoesPendentes(); // Recarregar lista
        } else {
            alert('❌ ' + data.mensagem);
        }
    } catch (error) {
        alert('Erro ao aprovar solicitação: ' + error.message);
    }
}

// Função para rejeitar solicitação
async function rejeitarSolicitacao(funcionarioId) {
    if (confirm('Tem certeza que deseja rejeitar esta solicitação de pausa?')) {
        try {
            const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
            const response = await fetch(apiUrl + '/rejeitar_pausa.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    funcionario_id: funcionarioId
                })
            });
            
            const data = await response.json();
            
            if (data.sucesso) {
                alert('❌ ' + data.mensagem);
                await carregarSolicitacoesPendentes(); // Recarregar lista
            } else {
                alert('❌ ' + data.mensagem);
            }
        } catch (error) {
            alert('Erro ao rejeitar solicitação: ' + error.message);
        }
    }
}

// Atualizar solicitações pendentes a cada 30 segundos
setInterval(carregarSolicitacoesPendentes, 30000);

