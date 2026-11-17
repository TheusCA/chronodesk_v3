<?php
// [9.16] Rodizio de papeis do Critico (desenho principal, 9.16).
// GET: papeis, rodizios (todas as versoes) e trocas (de hoje em diante;
//      historico=1 traz todas), para quem le escalas (mesma regra do GET de
//      rotation_teams.php). So IDs de pessoas: o nome vem da lista de
//      funcionarios que a tela ja carrega.
// POST (escalas.manage, CSRF; AD-PC-3: so Lideranca e admin): save_role,
//      preview_rotation, save_rotation, add_override, remove_override. Data
//      anterior a hoje exige escalas.retroativa (no servico).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/CriticalRoleService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$action = null;

try {
    $service = new CriticalRoleService();
    if ($method === 'GET') {
        $withHistory = in_array((string)($_GET['historico'] ?? ''), ['1', 'true'], true);
        json_response(['sucesso' => true] + $service->overview($withHistory));
    }
    if (!portal_role_can($role, 'escalas.manage')) {
        audit_log('PAPEL_CRITICO_NEGADO', 'Perfil sem permissao tentou alterar papeis do Critico.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => CriticalRoleService::FORBIDDEN_MESSAGE], 403);
    }
    $data = portal_json_input();
    $action = $data['action'] ?? null;
    if (!is_string($action)) {
        throw new InvalidArgumentException('Ação de papel do Crítico inválida.');
    }
    $actor = portal_username();
    switch ($action) {
        case 'save_role':
            $saved = $service->saveRole($data, $actor, $role);
            json_response(['sucesso' => true, 'mensagem' => $saved['created'] ? 'Papel criado.' : 'Papel salvo.'] + $saved);
        case 'preview_rotation':
            json_response(['sucesso' => true] + $service->previewRotation($data, $role));
        case 'save_rotation':
            json_response(['sucesso' => true, 'mensagem' => 'Rodízio de papéis salvo.'] + $service->saveRotation($data, $actor, $role));
        case 'add_override':
            json_response(['sucesso' => true, 'mensagem' => 'Troca de papel registrada.'] + $service->addOverride($data, $actor, $role));
        case 'remove_override':
            json_response(['sucesso' => true, 'mensagem' => 'Troca de papel removida.'] + $service->removeOverride($data, $role));
    }
    throw new InvalidArgumentException('Ação de papel do Crítico inválida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
