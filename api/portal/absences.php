<?php
require_once __DIR__ . '/_bootstrap.php';

// [SEC-07, F3 commit 1] Ausências por perfil: gestão vê todas; técnico e
// somente leitura, só as próprias (docs/DESENHO_PA_ESCALA_AUSENCIA.md, 3.3 e 9.4).
require_get_method();
$actor = portal_actor(require_portal_auth());
try {
    json_response(['sucesso' => true] + (new PortalService())->absences($actor));
} catch (Throwable $error) {
    portal_operational_error($error);
}
