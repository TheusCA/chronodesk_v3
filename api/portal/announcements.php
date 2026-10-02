<?php
require_once __DIR__ . '/_bootstrap.php';

// [SEC-07] Avisos por público (audience): gestão vê todos; técnico e somente
// leitura, os de todos e os da própria equipe (docs/DESENHO_LOTE4.md, seção 2).
require_get_method();
$actor = portal_actor(require_portal_auth());
try {
    json_response(['sucesso' => true] + (new PortalService())->announcements($actor));
} catch (Throwable $error) {
    portal_operational_error($error);
}
