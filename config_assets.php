<?php
/**
 * [SECURED] Configuração de Assets com Versionamento
 * Correções: DEBUG via variável de ambiente
 */
if (!function_exists('get_base_path')) {
    require_once __DIR__ . '/config.php';
}

define('ASSETS_VERSION', '2.1.0');

// [VULN-020] Debug controlado por variável de ambiente
if (!defined('DEBUG')) {
    define('DEBUG', defined('APP_DEBUG') ? APP_DEBUG : false);
}

function asset_url($path) {
    if (substr($path, 0, 1) !== '/') $path = '/' . $path;
    
    if (function_exists('get_base_path')) {
        $base_path = get_base_path();
    } else {
        $script_file = $_SERVER['SCRIPT_FILENAME'];
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        $script_path = str_replace('\\', '/', $script_file);
        $doc_root_normalized = str_replace('\\', '/', $doc_root);
        if (strpos($script_path, $doc_root_normalized) === 0) {
            $base_path = dirname(substr($script_path, strlen($doc_root_normalized)));
        } else {
            $base_path = dirname($_SERVER['SCRIPT_NAME']);
        }
        if (strlen($base_path) > 0 && $base_path[0] !== '/') $base_path = '/' . $base_path;
        $base_path = rtrim($base_path, '/');
        if ($base_path === '/') $base_path = '';
    }
    
    $full_path = $base_path . $path;
    $version = ASSETS_VERSION;
    if (defined('DEBUG') && DEBUG) {
        $version = time();
    }
    return $full_path . '?v=' . $version;
}
