<?php
// [9.17] Controle de CHG (desenho principal, 9.17).
// Desligado (CHG_MODULE_ENABLED ausente ou falso): 404, nada vaza (D-CHG-17).
// GET (todo autenticado): cards nao arquivados com a etapa e os rotulos
//      calculados no agora do servidor, catalogos, tecnicos (ID e nome dos
//      funcionarios ativos), can_manage e can_import. ?duplicar=ID: a base
//      do formulario "Duplicar" (nada e gravado).
// POST (chg.manage por perfil ou grupo Tools, CSRF): create, update, as acoes
//      do card (ChgService::action) e save_catalog. Sem permissao: 403 com a
//      recusa auditada (so o perfil e a acao).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/ChgService.php';

if (!chg_module_enabled()) {
    json_response(['sucesso' => false, 'mensagem' => 'Recurso não encontrado.'], 404);
}

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$action = null;

try {
    $store = new PdoChgStore(get_db_connection());
    $service = new ChgService($store);
    $employeeId = (int)($_SESSION['ci_funcionario_id'] ?? 0);
    $canManage = ChgAccess::canManage($role, ci_session_is_current() ? $employeeId : null, $store);
    if ($method === 'GET') {
        if (isset($_GET['duplicar'])) {
            $id = filter_var($_GET['duplicar'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new InvalidArgumentException('Card inválido.');
            }
            json_response(['sucesso' => true, 'base' => $service->duplicateBase($id)]);
        }
        json_response(['sucesso' => true] + $service->board($canManage, ChgAccess::canImport($role)));
    }
    $data = portal_json_input();
    $action = $data['action'] ?? null;
    if (!is_string($action) || preg_match('/^[a-z_]{1,30}$/', $action) !== 1) {
        throw new InvalidArgumentException('Ação do Controle de CHG inválida.');
    }
    if (!$canManage) {
        audit_log('CHG_NEGADO', "Perfil {$role} sem permissao tentou acao={$action} no Controle de CHG.", 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => ChgService::FORBIDDEN_MESSAGE], 403);
    }
    $username = portal_username();
    switch ($action) {
        case 'create':
            json_response(['sucesso' => true, 'mensagem' => 'Atualização cadastrada.', 'card' => $service->create($data, $username)]);
        case 'update':
            json_response(['sucesso' => true, 'mensagem' => 'Atualização salva.', 'card' => $service->update($data, $username)]);
        case 'save_catalog':
            json_response(['sucesso' => true, 'mensagem' => 'Catálogo salvo.'] + $service->saveCatalog($data, $username));
    }
    json_response(['sucesso' => true, 'card' => $service->action($action, $data, $username)]);
} catch (Throwable $e) {
    portal_operational_error($e);
}
