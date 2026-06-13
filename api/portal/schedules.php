<?php
require_once __DIR__ . '/_bootstrap.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$service = new OperationalService();
$action = null;

try {
    if ($method === 'GET') {
        if (isset($_GET['type']) && $_GET['type'] !== '') {
            $legacyService = new PortalService();
            $type = sanitize_input($_GET['type'], 30);
            json_response(['sucesso' => true, 'items' => $legacyService->schedules($type)]);
        }
        json_response(['sucesso' => true] + $service->scheduleData($_GET));
    }
    if (!in_array($role, ['admin', 'gestor'], true)) {
        json_response(['sucesso' => false, 'mensagem' => 'Apenas gestores podem alterar escalas.'], 403);
    }
    $data = portal_json_input(OperationalService::MAX_IMPORT_PAYLOAD_BYTES);
    $action = $data['action'] ?? 'rule';
    if (!is_string($action)) {
        throw new InvalidArgumentException('Acao de escala invalida.');
    }
    if ($action === 'rule') {
        $id = $service->saveScheduleRule($data, portal_username());
        audit_log('SCHEDULE_RULE_SAVED', 'Regra de escala salva. ID ' . $id, 'WARNING');
        json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Regra de escala salva.']);
    }
    if ($action === 'exception') {
        $id = $service->saveScheduleException($data, portal_username());
        audit_log('SCHEDULE_EXCEPTION_SAVED', 'Excecao de escala salva. ID ' . $id, 'WARNING');
        json_response(['sucesso' => true, 'id' => $id, 'mensagem' => 'Excecao de escala salva.']);
    }
    if ($action === 'import_preview') {
        if (!isset($data['rows']) || !is_array($data['rows'])) {
            throw new InvalidArgumentException('Rows deve ser uma lista de linhas.');
        }
        $preview = $service->previewScheduleImport($data['rows']);
        audit_log(
            'SCHEDULE_IMPORT_PREVIEWED',
            'Previa de importacao validada. Linhas ' . count($preview['rows']),
            'INFO'
        );
        json_response(['sucesso' => true] + $preview);
    }
    if ($action === 'import_confirm') {
        if (!isset($data['rows']) || !is_array($data['rows'])) {
            throw new InvalidArgumentException('Rows deve ser uma lista de linhas.');
        }
        $count = $service->confirmScheduleImport($data['rows'], portal_username());
        audit_log('SCHEDULE_IMPORT_CONFIRMED', 'Importacao de escala confirmada. Linhas ' . $count, 'WARNING');
        json_response(['sucesso' => true, 'imported' => $count, 'mensagem' => "{$count} regra(s) importada(s)."]);
    }
    throw new InvalidArgumentException('Acao de escala invalida.');
} catch (Throwable $e) {
    if (in_array($action, ['import_preview', 'import_confirm'], true)) {
        audit_log(
            'SCHEDULE_IMPORT_REJECTED',
            'Tentativa de importacao rejeitada: ' . get_class($e) . ' - ' . $e->getMessage(),
            'WARNING'
        );
    }
    portal_operational_error($e);
}
