<?php
require_once __DIR__ . '/_bootstrap.php';

require_get_method();
require_portal_auth();
$type = isset($_GET['type']) ? sanitize_input($_GET['type'], 30) : null;
$service = new PortalService();

try {
    json_response(['sucesso' => true, 'items' => $service->schedules($type)]);
} catch (InvalidArgumentException $e) {
    json_response(['sucesso' => false, 'mensagem' => $e->getMessage()], 400);
} catch (PortalStorageException $e) {
    json_response(['sucesso' => false, 'mensagem' => $e->getMessage()], 503);
}
