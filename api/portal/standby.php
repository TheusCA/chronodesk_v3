<?php
require_once __DIR__ . '/_bootstrap.php';

require_get_method();
require_portal_auth();
$service = new PortalService();
json_response(['sucesso' => true, 'items' => $service->schedules('sobreaviso')]);
