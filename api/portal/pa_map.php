<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/PaMapService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$service = new PaMapService();

try {
    if ($method === 'GET') {
        json_response([
            'sucesso' => true,
            'can_manage' => $role === 'admin',
        ] + $service->list($_GET));
    }

    if ($role !== 'admin') {
        audit_log('PA_MAP_WRITE_DENIED', 'Perfil sem permissao tentou alterar Mapa de PA.', 'WARNING');
        json_response([
            'sucesso' => false,
            'mensagem' => 'Apenas administradores podem alterar o Mapa de PA.',
        ], 403);
    }

    $data = portal_json_input(PaMapService::MAX_PAYLOAD_BYTES);
    $action = $data['action'] ?? 'save';
    if (!is_string($action)) {
        throw new InvalidArgumentException('Acao invalida.');
    }

    if ($action === 'remove') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            throw new InvalidArgumentException('Alocacao invalida.');
        }
        $service->remove((int)$id, portal_username());
        audit_log('PA_MAP_REMOVED', 'Alocacao de PA removida. ID ' . (int)$id, 'INFO');
        json_response(['sucesso' => true, 'mensagem' => 'PA desocupado.']);
    }

    if ($action !== 'save') {
        throw new InvalidArgumentException('Acao invalida.');
    }
    $result = $service->save($data, portal_username());
    audit_log('PA_MAP_SAVED', 'Alocacao de PA salva. ID ' . (int)$result['id'], 'INFO');
    json_response([
        'sucesso' => true,
        'id' => $result['id'],
        'warnings' => $result['warnings'],
        'mensagem' => 'Alocacao de PA salva.',
    ]);
} catch (Throwable $error) {
    portal_operational_error($error);
}
