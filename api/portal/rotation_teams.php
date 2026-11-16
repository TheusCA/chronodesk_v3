<?php
// [F10-2] Times de rodizio (desenho do F10, secao 3.1).
// GET: times, versoes e pertencas (abertas e futuras; historico=1 traz todas),
//      para quem le escalas (mesma regra do GET de schedules.php). So o ID do
//      membro: o nome vem da lista de funcionarios que a tela ja carrega.
// POST (escalas.manage, CSRF): save_team, save_version, preview_version,
//      add_member, move_member, end_member. Data anterior a hoje exige
//      escalas.retroativa (no servico).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/RotationTeamService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$action = null;

try {
    $service = new RotationTeamService();
    if ($method === 'GET') {
        $withHistory = in_array((string)($_GET['historico'] ?? ''), ['1', 'true'], true);
        json_response(['sucesso' => true] + $service->listTeams($withHistory));
    }
    if (!portal_role_can($role, 'escalas.manage')) {
        audit_log('TIME_RODIZIO_NEGADO', 'Perfil sem permissao tentou alterar time de rodizio.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Apenas administradores e Liderança alteram times de rodízio.'], 403);
    }
    $data = portal_json_input();
    $action = $data['action'] ?? null;
    if (!is_string($action)) {
        throw new InvalidArgumentException('Ação de time de rodízio inválida.');
    }
    $actor = portal_username();
    switch ($action) {
        case 'save_team':
            $saved = $service->saveTeam($data, $actor, $role);
            json_response(['sucesso' => true, 'mensagem' => $saved['created'] ? 'Time criado.' : 'Time salvo.'] + $saved);
        case 'preview_version':
            json_response(['sucesso' => true] + $service->previewVersion($data, $role));
        case 'save_version':
            json_response(['sucesso' => true, 'mensagem' => 'Padrão do time salvo.'] + $service->saveVersion($data, $actor, $role));
        case 'add_member':
            json_response(['sucesso' => true, 'mensagem' => 'Técnico incluído no time.'] + $service->addMember($data, $actor, $role));
        case 'move_member':
            json_response(['sucesso' => true, 'mensagem' => 'Técnico movido de time.'] + $service->moveMember($data, $actor, $role));
        case 'end_member':
            $ended = $service->endMember($data, $actor, $role);
            json_response(['sucesso' => true, 'mensagem' => $ended['cancelled'] ? 'Entrada no time cancelada.' : 'Saída do time registrada.'] + $ended);
    }
    throw new InvalidArgumentException('Ação de time de rodízio inválida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
