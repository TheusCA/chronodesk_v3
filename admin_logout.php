<?php
require_once __DIR__ . '/config.php';

session_destroy();

// Calcular caminho base corretamente
$base_path = get_base_path();
$index_url = $base_path . '/index.php';
header('Location: ' . $index_url);
exit;
?>
