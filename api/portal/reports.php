<?php
require_once __DIR__ . '/_bootstrap.php';

require_get_method();
require_portal_auth(['admin', 'gestor']);

try {
    $service = new OperationalService();
    $report = $service->report($_GET);
    if (($_GET['format'] ?? '') === 'csv') {
        audit_log('OPERATIONAL_REPORT_EXPORTED', 'Relatorio operacional CSV exportado.', 'INFO');
        $service->streamReportCsv($report);
        exit;
    }
    json_response(['sucesso' => true] + $report);
} catch (Throwable $e) {
    portal_operational_error($e);
}
