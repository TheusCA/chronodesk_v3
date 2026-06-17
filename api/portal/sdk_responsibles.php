<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/CriticalIncidentService.php';

require_get_method();
require_portal_auth();

try {
    json_response([
        'sucesso' => true,
        'items' => (new CriticalIncidentService())->listSdkResponsibleOptions(),
    ]);
} catch (Throwable $error) {
    portal_operational_error($error);
}
