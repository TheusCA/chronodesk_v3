<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/config_assets.php';
$ci_logged_in = ci_session_is_current();
$ci_funcionario_id = (int)($_SESSION['ci_funcionario_id'] ?? 0);
$ci_nome = $_SESSION['ci_nome'] ?? '';
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
    <title>ChronoDesk - Gestão de Tempo</title>
    <?php
    $base_path_test = dirname($_SERVER['SCRIPT_NAME']);
    if ($base_path_test === '/') $base_path_test = '';
    else $base_path_test = rtrim($base_path_test, '/');
    $css_path = $base_path_test . '/static/css/style.css?v=' . time();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($css_path); ?>" type="text/css">
    <script src="static/js/csrf_fetch.js"></script>
    <script src="static/js/theme.js" defer></script>
    <style>
        /* Banner crítico fixo no topo */
        .banner-critico {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            background: #E81123;
            color: #fff;
            padding: 12px 20px;
            z-index: 9999;
            font-weight: 600;
            font-size: 1rem;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            animation: piscar 1s infinite alternate;
        }
        @keyframes piscar { from { opacity: 1; } to { opacity: 0.75; } }
        .btn-fechar-banner {
            background: rgba(255,255,255,0.25);
            border: none;
            color: #fff;
            font-size: 1rem;
            padding: 4px 10px;
            cursor: pointer;
            border-radius: 4px;
        }
        /* Select de CI */
        .select-ci {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1.5px solid var(--border-color, #ccc);
            background: var(--input-bg, #fff);
            color: var(--text-primary, #111);
            font-size: 1rem;
            margin-bottom: 8px;
            cursor: pointer;
        }
        .select-ci:focus { outline: 2px solid #0078d4; }
        /* Contador de pausas */
        .contador-pausas { font-size: 0.85rem; color: var(--text-secondary, #666); margin-top: 4px; }
        .contador-pausas.ativo { color: #E81123; font-weight: 600; }
        /* Badge de equipe no card */
        .funcionario-equipe-badge {
            font-size: 0.7rem;
            font-weight: 700;
            background: var(--border-color, #eee);
            padding: 2px 7px;
            border-radius: 10px;
            color: var(--text-secondary, #555);
        }
        .hidden { display: none !important; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>⏳ ChronoDesk</h1>
            <p>Gestão Inteligente de Tempo e Pausas</p>
            <div id="ci-session-info" class="ci-session-info<?php echo $ci_logged_in ? '' : ' hidden'; ?>">
                <span id="ci-session-name"><?php echo htmlspecialchars($ci_nome, ENT_QUOTES, 'UTF-8'); ?></span>
                <button id="btn-ci-logout" type="button" class="btn btn-secondary">Sair</button>
            </div>
        </header>

        <section id="ci-login-screen" class="ci-login-screen<?php echo $ci_logged_in ? ' hidden' : ''; ?>" style="<?php echo $ci_logged_in ? 'display:none;' : ''; ?>">
            <div class="ci-login-card">
                <div class="ci-login-icon">⏳</div>
                <h2>ChronoDesk</h2>
                <p>Gestão Inteligente de Tempo e Pausas</p>
                <div class="ci-login-section-title">Acesso do CI</div>
                <form id="ci-login-form" autocomplete="off">
                    <label for="ci-login-ad">Login AD</label>
                    <input id="ci-login-ad" name="login_ad" type="text" autocomplete="username" required>

                    <label for="ci-senha-ad">Senha AD</label>
                    <input id="ci-senha-ad" name="senha_ad" type="password" autocomplete="current-password" required>

                    <button type="submit" class="btn btn-primary">Entrar</button>
                </form>
                <a href="<?php echo get_base_path(); ?>/admin_login.php" class="ci-admin-link">Acesso Administrativo</a>
            </div>
        </section>

        <div id="dashboard-main" class="main-content<?php echo $ci_logged_in ? '' : ' hidden'; ?>" style="<?php echo $ci_logged_in ? '' : 'display:none;'; ?>">
            <div class="actions-panel">
                <h2>Ações Rápidas</h2>
                <div id="contador-pausas-wrap">
                    <span id="contador-pausas" class="contador-pausas">Carregando...</span>
                </div>

                <div class="action-group">
                    <div class="input-group">
                        <label for="select-iniciar-ci" class="input-label">Iniciar Pausa</label>
                        <!-- SUBSTITUÍDO: input type=number por select com nomes dos CIs -->
                        <select id="select-iniciar-ci" class="select-ci">
                            <option value="">— Selecione o CI —</option>
                        </select>
                        <select id="motivo-pausa" class="motivo-select" onchange="verificarMotivoReuniao()">
                            <option value="">Motivo</option>
                            <option value="Café">☕ Café</option>
                            <option value="Pessoal">👤 Pessoal</option>
                            <option value="Reunião">🤝 Reunião</option>
                        </select>
                        <div id="observacao-reuniao" class="observacao-reuniao" style="display: none;">
                            <textarea id="observacao-texto" placeholder="Descrição da reunião..." maxlength="200" rows="3"></textarea>
                            <button id="btn-solicitar-pausa" type="button" onclick="solicitarPausaComAprovacao()" class="btn btn-warning">Solicitar</button>
                        </div>
                        <button id="btn-iniciar-normal" type="button" onclick="iniciarPausa()" class="btn btn-primary">Iniciar</button>
                    </div>
                </div>

                <div class="action-group">
                    <div class="input-group">
                        <label for="select-finalizar-ci" class="input-label">Finalizar Pausa</label>
                        <!-- SUBSTITUÍDO: input type=number por select com nomes dos CIs -->
                        <select id="select-finalizar-ci" class="select-ci">
                            <option value="">— Selecione o CI —</option>
                        </select>
                        <button id="btn-finalizar-pausa" type="button" onclick="finalizarPausa()" class="btn btn-secondary">Finalizar</button>
                    </div>
                </div>

                <div class="action-group action-group-compact">
                    <button onclick="atualizarStatus()" class="btn btn-refresh">🔄 Atualizar</button>
                    <a href="<?php echo get_base_path(); ?>/login.php" class="btn btn-metrics">📊 Métricas</a>
                </div>

                <div class="action-group action-group-compact">
                    <a href="<?php echo get_base_path(); ?>/admin_login.php" class="btn btn-admin">⚙️ Admin</a>
                </div>
            </div>

            <div class="status-panel">
                <h2>Status das Equipes</h2>
                <div class="team-section">
                    <h3>Equipe N1</h3>
                    <div id="equipe-n1" class="team-grid"></div>
                </div>
                <div class="team-section">
                    <h3>Equipe N2</h3>
                    <div id="equipe-n2" class="team-grid"></div>
                </div>
            </div>
        </div>

        <div id="message-area" class="message-area"></div>
    </div>

    <script>
        window.BASE_PATH = <?php echo json_encode($base_path_test, JSON_UNESCAPED_SLASHES); ?>;
        window.API_BASE_URL = window.BASE_PATH + '/api';
        window.APP_DEBUG = <?php echo defined('APP_DEBUG') && APP_DEBUG ? 'true' : 'false'; ?>;
        window.CI_AUTHENTICATED = <?php echo $ci_logged_in ? 'true' : 'false'; ?>;
        window.CI_FUNCIONARIO_ID = <?php echo $ci_funcionario_id; ?>;
        window.CI_NOME = <?php echo json_encode($ci_nome, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo htmlspecialchars($base_path_test . '/static/js/script.js?v=' . time()); ?>"></script>
</body>
</html>
