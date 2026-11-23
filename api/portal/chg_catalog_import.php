<?php
// [Ajustes do CHG, D-CHG-39 e D-CHG-40] Importacao do catalogo de carteiras do
// Controle de CHG com previa: so chg.import (admin), CSRF, a planilha (.xlsx ou
// .csv com as colunas "cod" e "carteira"; as demais sao ignoradas). O arquivo
// vai nas duas etapas; 'confirm' refaz a previa com o catalogo travado e
// grava, numa transacao. Auditoria CHG_CATALOGO so com as contagens.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/ChgService.php';
require_once __DIR__ . '/../../services/SpreadsheetImportService.php';
require_once __DIR__ . '/../../services/ChgCatalogImport.php';

if (!chg_module_enabled()) {
    json_response(['sucesso' => false, 'mensagem' => 'Recurso não encontrado.'], 404);
}

require_post_method();
$role = require_portal_auth();

try {
    if (!ChgAccess::canImport($role)) {
        audit_log('CHG_NEGADO', "Perfil {$role} sem permissao tentou importar o catalogo de carteiras do Controle de CHG.", 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Somente administradores importam o catálogo de carteiras.'], 403);
    }
    require_csrf_token();
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength === false || $contentLength < 1) {
        json_response(['sucesso' => false, 'mensagem' => 'Tamanho da requisicao invalido.'], 411);
    }
    if ($contentLength > SpreadsheetImportService::MAX_REQUEST_BYTES) {
        throw new LengthException('A requisicao de importacao excede 3 MB.');
    }
    if (!isset($_FILES['spreadsheet']) || !is_array($_FILES['spreadsheet'])) {
        throw new InvalidArgumentException('Envie a planilha (.xlsx ou .csv) com as colunas cod e carteira.');
    }
    $action = $_POST['action'] ?? 'preview';
    if (!in_array($action, ['preview', 'confirm'], true)) {
        throw new InvalidArgumentException('Acao de importacao invalida.');
    }
    $matrix = (new SpreadsheetImportService())->parseUploadMatrix(
        $_FILES['spreadsheet'],
        ChgCatalogImport::MAX_ROWS,
        ChgCatalogImport::MAX_COLUMNS,
        ChgCatalogImport::MAX_CELL_CHARS
    );
    $import = new ChgCatalogImport(get_db_connection(), portal_username());
    $result = $action === 'preview' ? $import->preview($matrix) : $import->confirm($matrix);
    audit_log('CHG_CATALOGO', ChgCatalogImport::auditDetails($result, $action), $action === 'confirm' ? 'WARNING' : 'INFO');
    json_response(['sucesso' => true, 'acao' => $action] + $result);
} catch (Throwable $e) {
    portal_operational_error($e);
}
