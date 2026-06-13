<?php
require_once __DIR__ . '/_bootstrap.php';

require_get_method();
require_portal_auth(['admin', 'gestor']);

json_response([
    'sucesso' => true,
    'reports' => [
        [
            'key' => 'pausas',
            'name' => 'Relatório de pausas',
            'description' => 'Métricas consolidadas e histórico detalhado.',
            'download_url' => '../api/download_relatorio.php',
        ],
    ],
]);
