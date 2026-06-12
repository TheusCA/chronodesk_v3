// Função para exibir mensagens
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function exibirMensagemAdmin(mensagem, tipo) {
    const messageArea = document.getElementById('admin-message-area');
    const icon = tipo === 'success' ? '✅' : tipo === 'error' ? '❌' : tipo === 'warning' ? '⚠️' : 'ℹ️';
    const safeTipo = ['success', 'error', 'warning'].includes(tipo) ? tipo : 'info';
    messageArea.innerHTML = `<div class="message ${safeTipo}">${icon} ${escapeHtml(mensagem)}</div>`;
    
    // Limpar mensagem após 5 segundos
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

// Função para salvar configurações
async function salvarConfiguracoes() {
    const limitePausa = parseInt(document.getElementById('limite-pausa').value);
    const duracaoPausa = parseInt(document.getElementById('duracao-pausa').value);
    const alerta15min = document.getElementById('alerta-15min').checked;
    const alerta20min = document.getElementById('alerta-20min').checked;

    if (!limitePausa || limitePausa < 1 || limitePausa > 10) {
        exibirMensagemAdmin('Por favor, insira um limite válido (1-10)', 'error');
        return;
    }

    if (!duracaoPausa || duracaoPausa < 5 || duracaoPausa > 60) {
        exibirMensagemAdmin('Por favor, insira uma duração válida (5-60 minutos)', 'error');
        return;
    }

    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/salvar_configuracao.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                limite_pausa_por_equipe: limitePausa,
                duracao_pausa_minutos: duracaoPausa,
                alerta_15minutos: alerta15min,
                alerta_20minutos: alerta20min
            })
        });

        const resultado = await response.json();

        if (resultado.sucesso) {
            exibirMensagemAdmin(resultado.mensagem, 'success');
            // Recarregar a página após 2 segundos para aplicar mudanças
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            exibirMensagemAdmin(resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao salvar configurações: ' + error.message, 'error');
    }
}

// Função para adicionar funcionário
async function adicionarFuncionario() {
    const id = parseInt(document.getElementById('novo-id').value);
    const nome = document.getElementById('novo-nome').value.trim();
    const equipe = document.getElementById('novo-equipe').value;
    const adLogin = document.getElementById('novo-ad-login').value.trim();
    const jornadaEntrada = document.getElementById('novo-jornada-entrada').value || '08:00';
    const jornadaSaida = document.getElementById('novo-jornada-saida').value || '17:00';
    const almocoInicio = document.getElementById('novo-almoco-inicio').value || '12:00';
    const almocoFim = document.getElementById('novo-almoco-fim').value || '13:00';
    const ativo = document.getElementById('novo-ativo').checked;

    if (!id || id < 1 || id > 999) {
        exibirMensagemAdmin('Por favor, insira um ID válido (1-999)', 'error');
        return;
    }

    if (!nome || nome.length < 3) {
        exibirMensagemAdmin('Por favor, insira um nome válido (mínimo 3 caracteres)', 'error');
        return;
    }

    if (!equipe || (equipe !== 'n1' && equipe !== 'n2')) {
        exibirMensagemAdmin('Por favor, selecione uma equipe válida', 'error');
        return;
    }

    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/adicionar_funcionario.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: id,
                nome: nome,
                equipe: equipe,
                ad_login: adLogin || null,
                jornada_entrada: jornadaEntrada,
                jornada_saida: jornadaSaida,
                almoco_inicio: almocoInicio,
                almoco_fim: almocoFim,
                ativo: ativo
            })
        });

        const resultado = await response.json();

        if (resultado.sucesso) {
            exibirMensagemAdmin(resultado.mensagem, 'success');
            // Limpar campos
            document.getElementById('novo-id').value = '';
            document.getElementById('novo-nome').value = '';
            document.getElementById('novo-ad-login').value = '';
            document.getElementById('novo-equipe').value = 'n1';
            document.getElementById('novo-jornada-entrada').value = '08:00';
            document.getElementById('novo-jornada-saida').value = '17:00';
            document.getElementById('novo-almoco-inicio').value = '12:00';
            document.getElementById('novo-almoco-fim').value = '13:00';
            document.getElementById('novo-ativo').checked = true;
            // Recarregar lista
            carregarFuncionarios();
        } else {
            exibirMensagemAdmin(resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao adicionar funcionário: ' + error.message, 'error');
    }
}

// Função para remover funcionário
async function removerFuncionario(id) {
    if (!confirm('Tem certeza que deseja remover este funcionário?')) {
        return;
    }

    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/remover_funcionario.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                funcionario_id: id
            })
        });

        const resultado = await response.json();

        if (resultado.sucesso) {
            exibirMensagemAdmin(resultado.mensagem, 'success');
            // Recarregar lista
            carregarFuncionarios();
        } else {
            exibirMensagemAdmin(resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao remover funcionário: ' + error.message, 'error');
    }
}

// Função para editar funcionário (abre modal ou formulário)
function editarFuncionario(id, nome, equipe, jornadaEntrada, jornadaSaida, almocoInicio, almocoFim, ativo, adLogin) {
    // Usar valores padrão se não fornecidos
    jornadaEntrada = jornadaEntrada || '08:00';
    jornadaSaida = jornadaSaida || '17:00';
    almocoInicio = almocoInicio || '12:00';
    almocoFim = almocoFim || '13:00';
    ativo = ativo !== undefined ? ativo : true;
    
    const novoNome = prompt('Digite o novo nome:', nome);
    if (!novoNome || novoNome.trim() === '') {
        return;
    }

    const novaEquipe = prompt('Digite a nova equipe (n1 ou n2):', equipe);
    if (!novaEquipe || (novaEquipe !== 'n1' && novaEquipe !== 'n2')) {
        exibirMensagemAdmin('Equipe inválida. Deve ser n1 ou n2', 'error');
        return;
    }
    
    // Solicitar horários
    const novaJornadaEntrada = prompt('Horário de entrada (HH:MM):', jornadaEntrada) || jornadaEntrada;
    const novaJornadaSaida = prompt('Horário de saída (HH:MM):', jornadaSaida) || jornadaSaida;
    const novoAlmocoInicio = prompt('Início do almoço (HH:MM):', almocoInicio) || almocoInicio;
    const novoAlmocoFim = prompt('Fim do almoço (HH:MM):', almocoFim) || almocoFim;
    const novoAdLogin = prompt('Login AD (deixe em branco para não vincular):', adLogin || '');
    const novoAtivo = confirm('Funcionário está ativo? (OK para ativo, Cancelar para inativo)');

    atualizarFuncionario(id, novoNome.trim(), novaEquipe, novaJornadaEntrada, novaJornadaSaida, novoAlmocoInicio, novoAlmocoFim, novoAtivo, (novoAdLogin || '').trim());
}

// Função para atualizar funcionário
async function atualizarFuncionario(id, nome, equipe, jornadaEntrada, jornadaSaida, almocoInicio, almocoFim, ativo, adLogin) {
    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/atualizar_funcionario.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                funcionario_id: id,
                nome: nome,
                equipe: equipe,
                ad_login: adLogin || null,
                jornada_entrada: jornadaEntrada || '08:00',
                jornada_saida: jornadaSaida || '17:00',
                almoco_inicio: almocoInicio || '12:00',
                almoco_fim: almocoFim || '13:00',
                ativo: ativo !== undefined ? ativo : true
            })
        });

        const resultado = await response.json();

        if (resultado.sucesso) {
            exibirMensagemAdmin(resultado.mensagem, 'success');
            // Recarregar lista
            carregarFuncionarios();
        } else {
            exibirMensagemAdmin(resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao atualizar funcionário: ' + error.message, 'error');
    }
}

// Função para carregar lista de funcionários
async function carregarFuncionarios() {
    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/listar_funcionarios.php');
        const resultado = await response.json();

        if (resultado.sucesso) {
            exibirFuncionarios(resultado.funcionarios);
        } else {
            exibirMensagemAdmin('Erro ao carregar funcionários: ' + resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao carregar funcionários: ' + error.message, 'error');
    }
}

// Função para exibir funcionários
function exibirFuncionarios(funcionarios) {
    const lista = document.getElementById('lista-funcionarios');
    
    if (!funcionarios || funcionarios.length === 0) {
        lista.innerHTML = '<p style="color: var(--gray-500); text-align: center; padding: 2rem;">Nenhum funcionário cadastrado</p>';
        return;
    }

    const funcionariosPorId = new Map();
    let html = '';
    funcionarios.forEach(func => {
        const funcionarioId = Number.parseInt(func.id, 10);
        if (!Number.isInteger(funcionarioId) || funcionarioId < 1) {
            return;
        }
        funcionariosPorId.set(funcionarioId, func);
        const statusEmPausa = func.em_pausa ? '🔴 Em pausa' : '🟢 Disponível';
        const equipeLabel = String(func.equipe || '').toUpperCase();
        
        // Informações de disponibilidade
        const disponibilidade = func.disponibilidade || {status: 'indisponivel', label: 'Indisponível', cor: '#999'};
        const ativoStatus = func.ativo !== false ? '✅ Ativo' : '⛔ Inativo';
        
        // Horários
        const jornadaEntrada = func.jornada_entrada || '08:00';
        const jornadaSaida = func.jornada_saida || '17:00';
        const almocoInicio = func.almoco_inicio || '12:00';
        const almocoFim = func.almoco_fim || '13:00';
        const adLogin = func.ad_login || '';

        html += `
            <div class="funcionario-item">
                <div class="funcionario-info">
                    <strong>ID ${funcionarioId}: ${escapeHtml(func.nome)}</strong>
                    <span>Equipe: ${escapeHtml(equipeLabel)} | AD: ${escapeHtml(adLogin || 'Não vinculado')} | ${ativoStatus} | Status: ${statusEmPausa}</span>
                    <div style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--gray-600);">
                        <span style="display: inline-block; margin-right: 1rem;">
                            ⏰ Jornada: ${escapeHtml(jornadaEntrada)} - ${escapeHtml(jornadaSaida)}
                        </span>
                        <span style="display: inline-block; margin-right: 1rem;">
                            🍽️ Almoço: ${escapeHtml(almocoInicio)} - ${escapeHtml(almocoFim)}
                        </span>
                        <span style="display: inline-block; color: ${escapeHtml(disponibilidade.cor)}; font-weight: 600;">
                            ${disponibilidade.status === 'disponivel' ? '✅' : disponibilidade.status === 'almoco' ? '🍽️' : '⏸️'}
                            ${escapeHtml(disponibilidade.label)}
                        </span>
                    </div>
                </div>
                <div class="funcionario-actions">
                    <button class="btn-small btn-edit" type="button" data-action="edit" data-funcionario-id="${funcionarioId}">
                        ✏️ Editar
                    </button>
                    <button class="btn-small btn-delete" type="button" data-action="remove" data-funcionario-id="${funcionarioId}">
                        🗑️ Remover
                    </button>
                </div>
            </div>
        `;
    });

    lista.innerHTML = html;

    lista.querySelectorAll('[data-action][data-funcionario-id]').forEach(button => {
        button.addEventListener('click', () => {
            const funcionarioId = Number.parseInt(button.dataset.funcionarioId, 10);
            const funcionario = funcionariosPorId.get(funcionarioId);
            if (!funcionario) {
                exibirMensagemAdmin('Funcionário não encontrado na lista atual.', 'error');
                return;
            }

            if (button.dataset.action === 'remove') {
                removerFuncionario(funcionarioId);
                return;
            }

            editarFuncionario(
                funcionarioId,
                funcionario.nome,
                funcionario.equipe,
                funcionario.jornada_entrada,
                funcionario.jornada_saida,
                funcionario.almoco_inicio,
                funcionario.almoco_fim,
                funcionario.ativo !== false,
                funcionario.ad_login || ''
            );
        });
    });
}

// Função para alterar senha do administrador
async function alterarSenhaAdmin() {
    const novaSenha = document.getElementById('admin-password').value;
    const confirmarSenha = document.getElementById('admin-password-confirm').value;

    if (!novaSenha || novaSenha.length < 8) {
        exibirMensagemAdmin('A senha deve ter no mínimo 8 caracteres', 'error');
        return;
    }

    if (novaSenha !== confirmarSenha) {
        exibirMensagemAdmin('As senhas não coincidem', 'error');
        return;
    }

    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/alterar_senha_admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                nova_senha: novaSenha
            })
        });

        const resultado = await response.json();

        if (resultado.sucesso) {
            exibirMensagemAdmin(resultado.mensagem, 'success');
            // Limpar campos
            document.getElementById('admin-password').value = '';
            document.getElementById('admin-password-confirm').value = '';
        } else {
            exibirMensagemAdmin(resultado.mensagem, 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao alterar senha: ' + error.message, 'error');
    }
}

// Carregar funcionários quando a página carrega
document.addEventListener('DOMContentLoaded', function() {
    if (typeof funcionariosIniciais !== 'undefined') {
        exibirFuncionarios(funcionariosIniciais);
    } else {
        carregarFuncionarios();
    }
});
