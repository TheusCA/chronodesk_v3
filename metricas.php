<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/config_assets.php';

verificar_login();
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
    <title>Métricas de Pausas - ChronoDesk</title>
    <?php
    // Calcular caminho base
    $base_path_metricas = get_base_path();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path_metricas . '/static/css/style.css?v=' . time()); ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path_metricas . '/static/css/metricas.css?v=' . time()); ?>" type="text/css">
    <script src="<?php echo htmlspecialchars($base_path_metricas . '/static/js/csrf_fetch.js'); ?>"></script>
    <script src="<?php echo htmlspecialchars($base_path_metricas . '/static/js/theme.js'); ?>" defer></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>📊 Métricas ChronoDesk</h1>
            <p>Análise detalhada de produtividade e bem-estar</p>
            <div class="theme-toggle-container">
                <button id="theme-toggle" class="btn-theme-toggle" title="Alternar Tema">🌙</button>
            </div>
        </header>

        <!-- Seção de Solicitações Pendentes -->
        <div class="pending-requests-container" id="pending-requests-container" style="display: none;">
            <h2>🤝 Solicitações Pendentes</h2>
            <div id="pending-requests-list"></div>
        </div>

        <div class="metrics-dashboard">
            <!-- KPIs Principais (Totais) -->
            <div class="dashboard-section-title">Visão Geral</div>
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon">👥</div>
                    <div class="kpi-content">
                        <h3>Pausas por Equipe</h3>
                        <div id="total-pausas-equipe" class="kpi-value-container loading-text">Carregando...</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">⏱️</div>
                    <div class="kpi-content">
                        <h3>Duração Total (Equipe)</h3>
                        <div id="duracao-total-equipe" class="kpi-value-container loading-text">Carregando...</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">📊</div>
                    <div class="kpi-content">
                        <h3>Média por Equipe</h3>
                        <div id="duracao-media-equipe" class="kpi-value-container loading-text">Carregando...</div>
                    </div>
                </div>
                 <div class="kpi-card warning">
                    <div class="kpi-icon">⚠️</div>
                    <div class="kpi-content">
                        <h3>Pausas Excedidas</h3>
                        <div id="pausas-excedidas" class="kpi-value-container loading-text">Carregando...</div>
                    </div>
                </div>
            </div>

            <!-- Detalhamento por Funcionário -->
            <div class="dashboard-section-title">Detalhamento por Funcionário</div>
            <div class="details-grid">
                <div class="detail-card">
                    <h3>Total de Pausas</h3>
                    <div id="total-pausas-funcionario" class="scrollable-content loading-text">Carregando...</div>
                </div>
                <div class="detail-card">
                    <h3>Duração Total</h3>
                    <div id="duracao-total-funcionario" class="scrollable-content loading-text">Carregando...</div>
                </div>
                <div class="detail-card">
                    <h3>Duração Média</h3>
                    <div id="duracao-media-funcionario" class="scrollable-content loading-text">Carregando...</div>
                </div>
            </div>

            <!-- Análise por Motivo -->
            <div class="dashboard-section-title">Análise por Motivo</div>
            <div class="details-grid">
                <div class="detail-card">
                    <h3>Ocorrências por Motivo</h3>
                    <div id="total-pausas-por-motivo" class="scrollable-content loading-text">Carregando...</div>
                </div>
                <div class="detail-card">
                    <h3>Tempo Total por Motivo</h3>
                    <div id="duracao-total-por-motivo" class="scrollable-content loading-text">Carregando...</div>
                </div>
                <div class="detail-card">
                    <h3>Média por Motivo</h3>
                    <div id="duracao-media-por-motivo" class="scrollable-content loading-text">Carregando...</div>
                </div>
            </div>

            <!-- Alertas de Segurança -->
            <div class="dashboard-section-title">Alertas de Segurança</div>
            <div class="details-grid two-columns">
                <div class="detail-card alert-card">
                    <h3>Alertas de 15 Minutos</h3>
                    <div class="split-content">
                        <div>
                            <h4>Por Funcionário</h4>
                            <div id="alertas-15min-funcionario"></div>
                        </div>
                        <div>
                            <h4>Por Equipe</h4>
                            <div id="alertas-15min-equipe"></div>
                        </div>
                    </div>
                </div>
                <div class="detail-card alert-card critical">
                    <h3>Alertas de 20 Minutos (Crítico)</h3>
                    <div class="split-content">
                        <div>
                            <h4>Por Funcionário</h4>
                            <div id="alertas-20min-funcionario"></div>
                        </div>
                        <div>
                            <h4>Por Equipe</h4>
                            <div id="alertas-20min-equipe"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="action-buttons">
            <a href="<?php echo get_base_path(); ?>/api/download_relatorio.php" class="btn btn-success">📥 Baixar Relatório Completo</a>
            <a href="<?php echo get_base_path(); ?>/index.php" class="btn btn-secondary">🏠 Voltar ao Gerenciador</a>
            <a href="<?php echo get_base_path(); ?>/logout.php" class="btn btn-danger">🚪 Sair</a>
        </div>
    </div>

    <script>
        // Definir caminho base para APIs
        const BASE_PATH = '<?php echo htmlspecialchars($base_path_metricas, ENT_QUOTES, 'UTF-8'); ?>';
        const API_BASE_URL = BASE_PATH + '/api';
    </script>
    <script src="<?php echo htmlspecialchars($base_path_metricas . '/static/js/metricas.js?v=' . time()); ?>"></script>
</body>
</html>
