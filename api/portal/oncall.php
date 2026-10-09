<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();

try {
    // [Lote 4, 0d] Conexao dentro do try: banco indisponivel vira 503 pelo
    // portal_operational_error(), sem excecao sem captura.
    $service = new OperationalService();
    if ($method === 'GET') {
        json_response(['sucesso' => true, 'items' => $service->listOncall($_GET, portal_actor($role))]);
    }
    if (!portal_role_is_manager($role)) {
        json_response(['sucesso' => false, 'mensagem' => 'Apenas gestores podem cadastrar plantoes.'], 403);
    }
    $data = portal_json_input();
    $id = $service->createOncall($data, portal_username());
    audit_log('ONCALL_CREATED', 'Plantao criado. ID ' . $id, 'WARNING');
    json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Plantao cadastrado.'], 201);
} catch (Throwable $e) {
    portal_operational_error($e);
}
