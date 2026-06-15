<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/CriticalIncidentService.php';
require_once __DIR__ . '/../../services/SpreadsheetImportService.php';

require_post_method();
require_portal_auth(['admin', 'gestor']);
$action = null;

try {
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
            CriticalIncidentService::MAX_IMPORT_ROWS,
            CriticalIncidentService::MAX_IMPORT_COLUMNS,
            CriticalIncidentService::MAX_IMPORT_CELL_CHARS
        );
        $service = new CriticalIncidentService();
        $preview = $service->previewImport($rows);
        audit_log(
            'CRITICAL_INCIDENT_IMPORT_FILE_PREVIEWED',
            'Arquivo de chamados criticos validado. Linhas ' . count($preview['rows']),
            'INFO'
        );
        json_response(['sucesso' => true, 'parsed_rows' => $rows] + $preview);
    }
    $data = portal_json_input(CriticalIncidentService::MAX_IMPORT_PAYLOAD_BYTES);
    $action = $data['action'] ?? 'preview';
    if (!is_string($action) || !isset($data['rows']) || !is_array($data['rows'])) {
        throw new InvalidArgumentException('Importacao invalida.');
    }
    $service = new CriticalIncidentService();
    if ($action === 'preview') {
        $preview = $service->previewImport($data['rows']);
        audit_log(
            'CRITICAL_INCIDENT_IMPORT_PREVIEWED',
            'Previa de importacao de chamados criticos. Linhas ' . count($preview['rows']),
            'INFO'
        );
        json_response(['sucesso' => true] + $preview);
    }
    if ($action === 'confirm') {
        $count = $service->confirmImport($data['rows'], portal_username());
        audit_log(
            'CRITICAL_INCIDENT_IMPORT_CONFIRMED',
            'Importacao de chamados criticos confirmada. Linhas ' . $count,
            'WARNING'
        );
        json_response([
            'sucesso' => true,
            'imported' => $count,
            'mensagem' => "{$count} chamado(s) importado(s).",
        ]);
    }
    throw new InvalidArgumentException('Acao de importacao invalida.');
} catch (Throwable $error) {
    audit_log(
        'CRITICAL_INCIDENT_IMPORT_REJECTED',
        'Tentativa de importacao rejeitada: ' . get_class($error) . ' - ' . $error->getMessage(),
        'WARNING'
    );
    portal_operational_error($error);
}
