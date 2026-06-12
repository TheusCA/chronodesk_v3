<?php
require_once __DIR__ . '/config.php';

require_post_method();
require_csrf_token();

if (usuario_pode_acessar_metricas()) {
    audit_log('LOGOUT', 'Logout do painel de métricas', 'INFO');
}
destroy_current_session();

// Calcular caminho base corretamente
$base_path = get_base_path();
$login_url = $base_path . '/login.php';
header('Location: ' . $login_url);
exit;
?>

