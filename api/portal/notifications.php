<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$service = new PortalService();
$username = portal_username();

if ($method === 'GET') {
    $items = $service->notifications($username, $role);
    json_response([
        'sucesso' => true,
        'items' => $items,
        'unread' => count(array_filter($items, static fn($item) => !(bool)$item['is_read'])),
    ]);
}

require_csrf_token();
require_json_content_type();
$data = json_decode(file_get_contents('php://input'), true);
$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!$id) {
    json_response(['sucesso' => false, 'mensagem' => 'Notificação inválida.'], 400);
}

$updated = $service->markNotificationRead((int)$id, $username, $role);
json_response([
    'sucesso' => $updated,
    'mensagem' => $updated ? 'Notificação marcada como lida.' : 'Notificação não encontrada.',
], $updated ? 200 : 404);
