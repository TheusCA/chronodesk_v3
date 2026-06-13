<?php
require_once __DIR__ . '/../config.php';

require_get_method();
require_portal_auth(['admin']);

$config = carregar_configuracao();

json_response([
    'sucesso' => true,
    'configuracoes' => [
        'limite_pausa_por_equipe' => (int)($config['limite_pausa_por_equipe'] ?? 2),
        'duracao_pausa_minutos' => (int)($config['duracao_pausa_minutos'] ?? 20),
        'alerta_15minutos' => (bool)($config['alerta_15minutos'] ?? true),
        'alerta_20minutos' => (bool)($config['alerta_20minutos'] ?? true),
        'auth_source' => AUTH_SOURCE,
        'local_admin_enabled' => ENABLE_LOCAL_ADMIN,
        'local_password_change_allowed' => ENABLE_LOCAL_ADMIN
            && ($_SESSION['admin_auth_type'] ?? '') === 'local',
    ],
]);
