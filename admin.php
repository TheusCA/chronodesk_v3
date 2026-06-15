<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/config_assets.php';

verificar_admin_login();

// Carregar configurações do sistema
$config = carregar_configuracao();

// Obter lista de funcionários do sistema
$funcionarios_sistema = [];
global $gerenciador;
if ($gerenciador) {
    $funcionarios = $gerenciador->obter_funcionarios();
    foreach ($funcionarios as $id => $func) {
        $funcionarios_sistema[] = [
            'id' => $func->id,
            'nome' => $func->nome,
            'equipe' => $func->equipe,
            'ad_login' => $func->ad_login ?? null,
            'em_pausa' => $func->em_pausa ?? false,
            'ativo' => $func->ativo ?? true,
            'jornada_entrada' => $func->jornada_entrada ?? '08:00',
            'jornada_saida' => $func->jornada_saida ?? '17:00',
            'almoco_inicio' => $func->almoco_inicio ?? '12:00',
            'almoco_fim' => $func->almoco_fim ?? '13:00',
            'disponibilidade' => $func->status_disponibilidade()
        ];
    }
}

// Calcular caminho base
$base_path_admin = get_base_path();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo generate_csrf_token(); ?>">
    <meta name="app-debug" content="<?php echo defined('APP_DEBUG') && APP_DEBUG ? 'true' : 'false'; ?>">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Painel Administrativo - ChronoDesk</title>
    <link rel="icon" href="<?php echo sanitize_attr($base_path_admin . '/app/favicon.png'); ?>" type="image/png">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path_admin . '/static/css/style.css?v=' . time()); ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path_admin . '/static/css/admin.css?v=' . time()); ?>" type="text/css">
    <script src="<?php echo htmlspecialchars($base_path_admin . '/static/js/csrf_fetch.js?v=' . time()); ?>"></script>
    <script src="<?php echo htmlspecialchars($base_path_admin . '/static/js/theme.js?v=' . time()); ?>" defer></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>⚙️ Painel Administrativo</h1>
            <p>Configurações do Sistema e Gerenciamento</p>
        </header>

        <div class="admin-main-content">
            <!-- Grid Topo: Configurações -->
            <div class="admin-grid-row">
                <!-- Seção de Configurações do Sistema -->
                <div class="admin-section">
                    <h2>🔧 Configurações do Sistema</h2>
                    <div class="config-form">
                        <div class="form-group">
                            <label for="limite-pausa">Limite de Pausas por Equipe:</label>
                            <input type="number" id="limite-pausa" value="<?php echo htmlspecialchars($config['limite_pausa_por_equipe']); ?>" min="1" max="10">
                            <small>Número máximo de pausas simultâneas permitidas por equipe</small>
                        </div>

                        <div class="form-group">
                            <label for="duracao-pausa">Duração Máxima da Pausa (minutos):</label>
                            <input type="number" id="duracao-pausa" value="<?php echo htmlspecialchars($config['duracao_pausa_minutos']); ?>" min="5" max="60">
                            <small>Tempo máximo permitido para uma pausa antes de alertas</small>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="alerta-15min" <?php echo ($config['alerta_15minutos'] ?? true) ? 'checked' : ''; ?>>
                                Ativar alerta aos 15 minutos
                            </label>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="alerta-20min" <?php echo ($config['alerta_20minutos'] ?? true) ? 'checked' : ''; ?>>
                                Ativar alerta crítico aos 20 minutos
                            </label>
                        </div>

                        <button onclick="salvarConfiguracoes()" class="btn btn-primary">💾 Salvar Configurações</button>
                    </div>
                </div>

                <!-- Seção de Configurações de Segurança -->
                <div class="admin-section">
                    <h2>🔒 Configurações de Segurança</h2>
                    <div class="config-form">
                        <div class="form-group">
                            <label for="admin-username">Usuário Administrador:</label>
                            <input type="text" id="admin-username" value="<?php echo htmlspecialchars($config['admin_username'] ?? ADMIN_USERNAME); ?>" disabled>
                            <small>O usuário não pode ser alterado por segurança</small>
                        </div>

                        <div class="form-group">
                            <label for="admin-password">Nova Senha Administrador:</label>
                            <input type="password" id="admin-password" placeholder="Deixe em branco para não alterar">
                            <small>Digite uma nova senha para alterar a atual</small>
                        </div>

                        <div class="form-group">
                            <label for="admin-password-confirm">Confirmar Nova Senha:</label>
                            <input type="password" id="admin-password-confirm" placeholder="Confirme a nova senha">
                        </div>

                        <button onclick="alterarSenhaAdmin()" class="btn btn-warning">🔒 Alterar Senha</button>
                    </div>
                </div>
            </div>

            <!-- Grid Meio: Gestão de Usuários e Funcionários -->
            <div class="admin-grid-row management-row">
                <!-- Seção de Gerenciamento de Usuários Administrativos -->
                <div class="admin-section">
                    <h2>🛡️ Usuários do Sistema</h2>
                    <div class="funcionarios-list-container">
                        <div class="add-funcionario-section">
                            <h3>➕ Criar Usuário</h3>
                            <div class="form-row-compact">
                                <div class="form-group">
                                    <input type="text" id="novo-user-login" placeholder="Login">
                                </div>
                                <div class="form-group">
                                    <input type="password" id="novo-user-senha" placeholder="Senha">
                                </div>
                                <div class="form-group">
                                    <select id="novo-user-role">
                                        <option value="gestor">Gestor</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <button onclick="adicionarUsuario()" class="btn btn-success btn-icon-only" title="Adicionar">➕</button>
                            </div>
                        </div>

                        <div class="funcionarios-list compact-list">
                            <div id="lista-usuarios-sistema">
                                <table class="metric-table" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>Usuário</th>
                                            <th>Função</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tabela-usuarios-body">
                                        <!-- Rows -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Seção de Gerenciamento de Funcionários -->
                <div class="admin-section wide-section">
                    <h2>👥 Funcionários</h2>
                    <div class="funcionarios-list-container">
                        <div class="add-funcionario-section">
                            <h3>➕ Novo Funcionário</h3>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>ID</label>
                                    <input type="number" id="novo-id" min="1" max="999" placeholder="ID">
                                </div>
                                <div class="form-group flex-grow">
                                    <label>Nome</label>
                                    <input type="text" id="novo-nome" placeholder="Nome completo">
                                </div>
                                <div class="form-group">
                                    <label>Login AD</label>
                                    <input type="text" id="novo-ad-login" placeholder="usuario.ad">
                                </div>
                                <div class="form-group">
                                    <label>Equipe</label>
                                    <select id="novo-equipe">
                                        <option value="n1">N1</option>
                                        <option value="n2">N2</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Jornada</label>
                                    <div class="time-inputs">
                                        <input type="time" id="novo-jornada-entrada" value="08:00">
                                        <span>às</span>
                                        <input type="time" id="novo-jornada-saida" value="17:00">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Almoço</label>
                                    <div class="time-inputs">
                                        <input type="time" id="novo-almoco-inicio" value="12:00">
                                        <span>às</span>
                                        <input type="time" id="novo-almoco-fim" value="13:00">
                                    </div>
                                </div>
                                <div class="form-group checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="novo-ativo" checked>
                                        Ativo
                                    </label>
                                </div>
                                <div class="form-group">
                                    <button onclick="adicionarFuncionario()" class="btn btn-success">Adicionar</button>
                                </div>
                            </div>
                        </div>

                        <div class="funcionarios-list">
                            <h3>📋 Lista Completa</h3>
                            <div id="lista-funcionarios">
                                <!-- Funcionários serão carregados aqui via JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Área de Mensagens -->
            <div id="admin-message-area" class="message-area"></div>

            <!-- Botões de Ação -->
            <div class="admin-actions">
                <a href="<?php echo get_base_path(); ?>/index.php" class="btn btn-secondary">🏠 Voltar ao Sistema</a>
                <form method="POST" action="<?php echo get_base_path(); ?>/admin_logout.php" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize_attr(generate_csrf_token()); ?>">
                    <button type="submit" class="btn btn-danger">🚪 Sair da Área Administrativa</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Definir caminho base para APIs
        const BASE_PATH = '<?php echo htmlspecialchars($base_path_admin, ENT_QUOTES, 'UTF-8'); ?>';
        const API_BASE_URL = BASE_PATH + '/api';
        
        // Dados iniciais
        const funcionariosIniciais = <?php echo json_encode($funcionarios_sistema, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo htmlspecialchars($base_path_admin . '/static/js/admin.js?v=' . time()); ?>"></script>
    <script src="<?php echo htmlspecialchars($base_path_admin . '/static/js/admin_users.js?v=' . time()); ?>"></script>
</body>
</html>
