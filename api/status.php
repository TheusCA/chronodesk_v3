<?php
/**
 * Status operacional em tempo real, restrito a sessoes autenticadas.
 */
require_once __DIR__ . '/../config.php';
require_get_method();
require_portal_auth();
require_once __DIR__ . '/../init.php';
global $gerenciador;
$status = $gerenciador->obter_status();
json_response($status);
