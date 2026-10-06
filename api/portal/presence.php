<?php
/**
 * GET portal/presence.php (Parte B, F2; desenho, secao 3.2): situacao de
 * presenca por colaborador e data. Qualquer perfil autenticado; categoria e
 * observacao de ausencia nao saem daqui (P9, F3).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/PresenceService.php';

require_get_method();
require_portal_auth();

try {
    $now = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');
    $from = is_string($_GET['from'] ?? null) && $_GET['from'] !== '' ? $_GET['from'] : $today;
    $to = is_string($_GET['to'] ?? null) && $_GET['to'] !== '' ? $_GET['to'] : $from;
    // Estado das pausas so quando o periodo inclui hoje; ilegivel = null.
    $pauseState = ['funcionarios' => []];
    if ($from <= $today && $to >= $today && is_file(ESTADO_JSON)) {
        $pauseState = ler_estado_pausas(ESTADO_JSON);
    }
    $service = new PresenceService(get_db_connection());
    json_response(['sucesso' => true] + $service->period($_GET, $now, $pauseState === null ? null : $pauseState['funcionarios']));
} catch (Throwable $error) {
    portal_operational_error($error);
}
