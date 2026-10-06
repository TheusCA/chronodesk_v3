<?php
/**
 * Status operacional em tempo real, restrito a sessoes autenticadas.
 */
require_once __DIR__ . '/../config.php';
require_get_method();
require_portal_auth();
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../services/PresenceService.php';
global $gerenciador;
$status = $gerenciador->obter_status();

// [F2, P14] Presenca de hoje em cada item, desligavel por
// STATUS_PRESENCE_ENABLED. Falha no calculo nao derruba o status.
if (PresenceService::statusPresenceEnabled()) {
    try {
        $status = (new PresenceService(get_db_connection()))->attachToStatus($status, new DateTimeImmutable('now'));
        $status['presenca_disponivel'] = true;
    } catch (Throwable $error) {
        error_log('[PRESENCE] Presenca indisponivel no status: ' . get_class($error));
        $status['presenca_disponivel'] = false;
    }
}
json_response($status);
