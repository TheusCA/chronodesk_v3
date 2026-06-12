// Função para adicionar usuário
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

async function adicionarUsuario() {
    const username = document.getElementById('novo-user-login').value.trim();
    const password = document.getElementById('novo-user-senha').value;
    const role = document.getElementById('novo-user-role').value;

    if (!username || username.length < 3) {
        exibirMensagemAdmin('Usuário deve ter pelo menos 3 caracteres', 'error');
        return;
    }

    if (!password || password.length < 8) {
        exibirMensagemAdmin('Senha deve ter pelo menos 8 caracteres', 'error');
        return;
    }

    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/usuarios.php?action=criar', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                username: username,
                password: password,
                role: role
            })
        });

        const resultado = await response.json();

        if (resultado.success) {
            exibirMensagemAdmin(resultado.message, 'success');
            // Limpar campos
            document.getElementById('novo-user-login').value = '';
            document.getElementById('novo-user-senha').value = '';
            // Recarregar lista
            carregarUsuariosSistema();
        } else {
            exibirMensagemAdmin(resultado.error || 'Erro desconhecido', 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao criar usuário: ' + error.message, 'error');
    }
}

// Função para remover usuário
async function removerUsuario(id) {
    if (!confirm('Tem certeza que deseja remover este usuário?')) {
        return;
    }

    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/usuarios.php?action=deletar', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: id
            })
        });

        const resultado = await response.json();

        if (resultado.success) {
            exibirMensagemAdmin(resultado.message, 'success');
            carregarUsuariosSistema();
        } else {
            exibirMensagemAdmin(resultado.error || 'Erro desconhecido', 'error');
        }
    } catch (error) {
        exibirMensagemAdmin('Erro ao remover usuário: ' + error.message, 'error');
    }
}

// Função para carregar usuários do sistema
async function carregarUsuariosSistema() {
    try {
        const apiUrl = (typeof API_BASE_URL !== 'undefined') ? API_BASE_URL : '/api';
        const response = await fetch(apiUrl + '/usuarios.php?action=listar');
        const resultado = await response.json();

        if (resultado.usuarios) {
            exibirUsuariosSistema(resultado.usuarios);
        } else {
            console.error('Erro ao carregar usuários:', resultado);
        }
    } catch (error) {
        console.error('Erro na requisição de usuários:', error);
    }
}

// Função para exibir a tabela de usuários
function exibirUsuariosSistema(usuarios) {
    const tbody = document.getElementById('tabela-usuarios-body');
    if (!tbody) return;

    if (!usuarios || usuarios.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding: 1rem;">Nenhum usuário encontrado</td></tr>';
        return;
    }

    let html = '';
    usuarios.forEach(user => {
        const roleBadge = user.role === 'admin' 
            ? '<span class="metric-badge danger">Admin</span>' 
            : '<span class="metric-badge info">Gestor</span>';
            
        html += `
            <tr>
                <td>${parseInt(user.id, 10)}</td>
                <td><strong>${escapeHtml(user.username)}</strong></td>
                <td>${roleBadge}</td>
                <td>${escapeHtml(new Date(user.created_at).toLocaleDateString('pt-BR'))}</td>
                <td>
                    <button class="btn-small btn-delete" onclick="removerUsuario(${parseInt(user.id, 10)})">🗑️</button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

// Adicionar chamada ao carregar a página
document.addEventListener('DOMContentLoaded', function() {
    // ... código existente ...
    carregarUsuariosSistema();
});
