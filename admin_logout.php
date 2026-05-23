<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    audit_log('ADMIN_LOGOUT', 'Logout administrativo para usuario ' . ($_SESSION['admin_username'] ?? 'unknown'), 'INFO');
}

destroy_current_session();

// Calcular caminho base corretamente
$base_path = get_base_path();
$index_url = $base_path . '/index.php';
header('Location: ' . $index_url);
exit;
?>
