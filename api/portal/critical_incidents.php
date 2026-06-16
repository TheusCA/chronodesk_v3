<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/CriticalIncidentService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$service = new CriticalIncidentService();

try {
    if ($method === 'GET') {
        $id = filter_var(
            $_GET['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if (isset($_GET['id'])) {
            if (!$id) {
                throw new InvalidArgumentException('Chamado critico invalido.');
            }
            json_response(['sucesso' => true, 'item' => $service->get((int)$id)]);
        }
        json_response([
            'sucesso' => true,
            'can_manage' => in_array($role, ['admin', 'gestor'], true),
        ] + $service->list($_GET));
    }

    $data = portal_json_input(CriticalIncidentService::MAX_IMPORT_PAYLOAD_BYTES);
    $action = $data['action'] ?? 'create';
    if (!is_string($action)) {
        throw new InvalidArgumentException('Acao invalida.');
    }
    if ($action === 'create') {
        require_portal_write_access($role);
        if (!in_array($role, ['admin', 'gestor'], true)) {
            $data['status'] = 'open';
        }
        $id = $service->create($data, portal_username());
        audit_log('CRITICAL_INCIDENT_CREATED', 'Chamado critico criado. ID ' . $id, 'WARNING');
        json_response([
            'sucesso' => true,
            'id' => $id,
            'mensagem' => 'Chamado critico cadastrado.',
        ], 201);
    }

    if (!in_array($role, ['admin', 'gestor'], true)) {
        audit_log('CRITICAL_INCIDENT_WRITE_DENIED', 'Perfil sem permissao tentou gerenciar chamado critico.', 'WARNING');
        json_response([
            'sucesso' => false,
            'mensagem' => 'Apenas administradores e gestores podem gerenciar chamados criticos.',
        ], 403);
    }

    $id = filter_var(
        $data['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if (!$id) {
        throw new InvalidArgumentException('Chamado critico invalido.');
    }
    if ($action === 'update') {
        $before = $service->get((int)$id);
        $service->update((int)$id, $data, portal_username());
        $after = $service->get((int)$id);
        audit_log('CRITICAL_INCIDENT_UPDATED', 'Chamado critico atualizado. ID ' . (int)$id, 'WARNING');
        if ($before['status'] !== $after['status']) {
            audit_log(
                'CRITICAL_INCIDENT_STATUS_CHANGED',
                'Chamado critico ID ' . (int)$id . ': ' . $before['status'] . ' -> ' . $after['status'],
                'WARNING'
            );
        }
        json_response(['sucesso' => true, 'mensagem' => 'Chamado critico atualizado.']);
    }
    if ($action === 'status') {
        $before = $service->get((int)$id);
        $status = $data['status'] ?? null;
        if (!is_string($status)) {
            throw new InvalidArgumentException('Status invalido.');
        }
        $service->changeStatus((int)$id, $status, portal_username());
        audit_log(
            'CRITICAL_INCIDENT_STATUS_CHANGED',
            'Chamado critico ID ' . (int)$id . ': ' . $before['status'] . ' -> ' . $status,
            'WARNING'
        );
        json_response(['sucesso' => true, 'mensagem' => 'Status do chamado atualizado.']);
    }
    throw new InvalidArgumentException('Acao invalida.');
} catch (Throwable $error) {
    portal_operational_error($error);
}
