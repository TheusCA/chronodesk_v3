<?php
require_once __DIR__ . '/config.php';

destroy_current_session();

// Calcular caminho base corretamente
$base_path = get_base_path();
$login_url = $base_path . '/login.php';
header('Location: ' . $login_url);
exit;
?>

