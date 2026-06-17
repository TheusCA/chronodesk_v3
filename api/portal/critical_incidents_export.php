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
    $columns = CriticalIncidentService::EXPORT_COLUMNS;
    fputcsv($output, array_keys($columns), ';');
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $field) {
            $value = $row[$field] ?? '';
            if ($field === 'source') {
                $value = CriticalIncidentService::sourceLabel($value);
            }
            if (in_array($field, ['room_opening_duration_minutes', 'room_duration_minutes'], true)
                && $value !== null
                && $value !== ''
            ) {
                $minutes = max(0, (int)$value);
                $value = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            }
            $text = (string)$value;
            $values[] = preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text) ? "'" . $text : $text;
        }
        fputcsv($output, $values, ';');
    }
    fclose($output);
    exit;
} catch (Throwable $error) {
    portal_operational_error($error);
}
