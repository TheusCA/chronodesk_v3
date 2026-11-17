<?php
// [9.17, D-CHG-16, D-CHG-25] Exportar o Controle de CHG em Excel (.xlsx gerado
// no servidor: abas Cards e Base). Todo autenticado (e o que ele ja ve).
// ?ids=1,2,3: os cards que a tela mostra com os filtros, na ordem da tela
// (ate 2000). Auditoria so com a contagem.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/ChgService.php';
require_once __DIR__ . '/../../services/ChgExport.php';

if (!chg_module_enabled()) {
    json_response(['sucesso' => false, 'mensagem' => 'Recurso não encontrado.'], 404);
}

require_get_method();
require_portal_auth();

try {
    $raw = $_GET['ids'] ?? '';
    if (!is_string($raw) || strlen($raw) > 24000 || preg_match('/^(\d{1,10}(,\d{1,10}){0,1999})?$/', $raw) !== 1) {
        throw new InvalidArgumentException('Lista de cards inválida.');
    }
    $ids = $raw === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $raw))));
    $service = new ChgService(new PdoChgStore(get_db_connection()));
    $board = $service->board(false, false);
    $byId = [];
    foreach ($board['cards'] as $card) {
        $byId[$card['id']] = $card;
    }
    $cards = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $cards[] = $byId[$id];
        }
    }
    $bytes = ChgExport::xlsx($cards);
    audit_log('CHG_EXPORTADO', 'Exportacao Excel do Controle de CHG. Cards ' . count($cards), 'INFO');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Controle_Atualizacoes_' . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
} catch (Throwable $error) {
    portal_operational_error($error);
}
