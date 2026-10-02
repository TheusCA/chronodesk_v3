<?php
require_once __DIR__ . '/_bootstrap.php';

require_get_method();
require_portal_auth();
// [Lote 4, 0d] Banco indisponivel: 503 com mensagem, sem erro fatal.
try {
    $service = new PortalService();
    json_response(['sucesso' => true] + $service->schedules('sobreaviso'));
} catch (Throwable $error) {
    portal_operational_error($error);
}
