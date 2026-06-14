<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/CriticalIncidentService.php';

require_get_method();
require_portal_auth();

try {
    $rows = (new CriticalIncidentService())->exportRows($_GET);
    audit_log(
        'CRITICAL_INCIDENT_EXPORTED',
        'Exportacao CSV de chamados criticos. Registros ' . count($rows),
        'INFO'
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados_criticos.csv"');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Nao foi possivel gerar o CSV.');
    }
    $headers = CriticalIncidentService::ALLOWED_IMPORT_HEADERS;
    fputcsv($output, $headers, ';');
    foreach ($rows as $row) {
        $values = [];
        foreach ($headers as $header) {
            $text = (string)($row[$header] ?? '');
            $values[] = preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text) ? "'" . $text : $text;
        }
        fputcsv($output, $values, ';');
    }
    fclose($output);
    exit;
} catch (Throwable $error) {
    portal_operational_error($error);
}
