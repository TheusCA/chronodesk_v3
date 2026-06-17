<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/SpreadsheetImportService.php';

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
    if (isset($_FILES['spreadsheet']) && is_array($_FILES['spreadsheet'])) {
        require_csrf_token();
        $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        if ($contentLength === false || $contentLength < 1) {
            json_response(['sucesso' => false, 'mensagem' => 'Tamanho da requisicao invalido.'], 411);
        }
        if ($contentLength > SpreadsheetImportService::MAX_REQUEST_BYTES) {
            throw new LengthException('A requisicao de importacao excede 3 MB.');
        }
        $rows = (new SpreadsheetImportService())->parseUpload(
            $_FILES['spreadsheet'],
            OperationalService::MAX_IMPORT_ROWS,
            OperationalService::MAX_IMPORT_COLUMNS,
            OperationalService::MAX_IMPORT_CELL_CHARS
        );
        $preview = $service->previewScheduleImport($rows);
        audit_log('SCHEDULE_IMPORT_FILE_PREVIEWED', 'Planilha de escala validada.', 'INFO');
        json_response(['sucesso' => true, 'parsed_rows' => $rows] + $preview);
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
    if ($action === 'remove_rule') {
        if ($role !== 'admin') {
            audit_log('SCHEDULE_RULE_REMOVE_DENIED', 'Perfil sem permissao tentou remover regra de escala.', 'WARNING');
            json_response(['sucesso' => false, 'mensagem' => 'Apenas administradores podem remover regras de escala.'], 403);
        }
        $employeeId = filter_var(
            $data['employee_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if (!$employeeId) {
            throw new InvalidArgumentException('Colaborador invalido.');
        }
        $count = $service->removeScheduleRule((int)$employeeId, portal_username());
        audit_log('SCHEDULE_RULE_REMOVED', 'Regra de escala removida para colaborador ID ' . (int)$employeeId, 'WARNING');
        json_response(['sucesso' => true, 'removed' => $count, 'mensagem' => 'Regra de escala removida.']);
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
