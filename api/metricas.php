<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../services/ApprovalRequestService.php';

require_get_method();
require_portal_auth(['admin', 'gestor']);

global $gerenciador;
$metricas = $gerenciador->obter_metricas();
$metricas['solicitacoes_reuniao'] = (new ApprovalRequestService())->listRecent();
json_response($metricas);

