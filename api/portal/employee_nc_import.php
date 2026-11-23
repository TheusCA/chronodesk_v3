<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/SpreadsheetImportService.php';
require_once __DIR__ . '/../../services/EmployeeNcImport.php';

// [Lote NC revisto, D-NC-11 a D-NC-13] Importacao de NCs com previa: so quem
// gerencia funcionarios (Lideranca e admin). O arquivo vai nas duas etapas;
// 'confirm' refaz a previa e grava, numa transacao. A auditoria leva as
// contagens e os IDs, nunca o NC.
require_post_method();
$role = require_portal_auth();

try {
    if (!portal_role_can($role, 'funcionarios.manage')) {
        json_response(['sucesso' => false, 'mensagem' => 'Só Liderança e admin importam NCs.'], 403);
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
        throw new InvalidArgumentException('Envie a planilha (.xlsx ou .csv) com as colunas NC e Nome completo.');
    }
    $action = $_POST['action'] ?? 'preview';
    if (!in_array($action, ['preview', 'confirm'], true)) {
        throw new InvalidArgumentException('Acao de importacao invalida.');
    }
    $matrix = (new SpreadsheetImportService())->parseUploadMatrix(
        $_FILES['spreadsheet'],
        EmployeeNcImport::MAX_ROWS,
        EmployeeNcImport::MAX_COLUMNS,
        EmployeeNcImport::MAX_CELL_CHARS
    );
    $import = new EmployeeNcImport(get_db_connection());
    if ($action === 'preview') {
        $result = $import->preview($matrix);
        $r = $result['resumo'];
        audit_log('FUNCIONARIO_NC_IMPORTACAO_PREVIA', "Previa de importacao de NC: {$r['linhas']} linha(s); entram {$r['entra']}, mudam {$r['muda']}, iguais {$r['igual']}, nao casaram {$r['nao_casou']}, NC invalido {$r['nc_invalido']}, NC repetido {$r['nc_repetido']}", 'INFO');
    } else {
        $result = $import->confirm($matrix);
        audit_log('FUNCIONARIO_NC_IMPORTADO', EmployeeNcImport::auditDetails($result, $role), 'WARNING');
    }
    unset($result['todos']);
    json_response(['sucesso' => true, 'acao' => $action] + $result);
} catch (Throwable $e) {
    portal_operational_error($e);
}
