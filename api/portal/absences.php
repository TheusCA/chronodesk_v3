<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/AbsenceService.php';

// [F3, RF-04] Ausencias (docs/DESENHO_PA_ESCALA_AUSENCIA.md, 3.3 e 9.4).
// GET: gestao ve todas; tecnico e somente leitura, so as proprias (SEC-07).
// POST create/update/cancel: ausencias.manage (gestor, Lideranca, admin),
// negado por perfil antes de tocar no banco; retroativo e Lideranca no servico.
$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);

try {
    if ($method === 'GET') {
        json_response(['sucesso' => true] + (new AbsenceService())->list($_GET, $actor));
    }
    if (!portal_role_can($role, 'ausencias.manage')) {
        audit_log('ABSENCE_WRITE_DENIED', 'Perfil sem permissao tentou alterar ausencia. Perfil=' . $role, 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Seu perfil não pode lançar, editar ou cancelar ausências.'], 403);
    }
    $data = portal_json_input();
    $action = $data['action'] ?? '';
    $service = new AbsenceService();
    if ($action === 'create') {
        $result = $service->create($data, $actor);
        json_response(['sucesso' => true, 'mensagem' => 'Ausência lançada.'] + $result, 201);
    }
    if ($action === 'update') {
        json_response(['sucesso' => true, 'mensagem' => 'Ausência alterada.'] + $service->update($data, $actor));
    }
    if ($action === 'cancel') {
        json_response(['sucesso' => true, 'mensagem' => 'Ausência cancelada.'] + $service->cancel($data, $actor));
    }
    throw new InvalidArgumentException('Ação de ausência inválida.');
} catch (Throwable $error) {
    if ($error instanceof PortalForbiddenException) {
        audit_log('ABSENCE_WRITE_DENIED', 'Recusa no servico de ausencias: ' . $error->getMessage(), 'WARNING');
    }
    portal_operational_error($error);
}
