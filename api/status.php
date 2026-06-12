<?php
/**
 * [SECURED] Status - Endpoint público (operacional)
 * Nota: Mantido sem autenticação por design (terminal compartilhado)
 */
require_once __DIR__ . '/../init.php';
require_get_method();
global $gerenciador;
$status = $gerenciador->obter_status();
json_response($status);
