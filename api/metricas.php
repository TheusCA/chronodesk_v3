<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../services/ApprovalRequestService.php';

require_get_method();
require_portal_auth(PORTAL_MANAGER_ROLES);

global $gerenciador;
$metricas = $gerenciador->obter_metricas();
$solicitacoes = (new ApprovalRequestService())->listRecent();
$metricas['solicitacoes_reuniao'] = $solicitacoes['items'];
$metricas['solicitacoes_reuniao_truncadas'] = $solicitacoes['truncated'];
$metricas['solicitacoes_reuniao_limite'] = $solicitacoes['limit'];
json_response($metricas);

