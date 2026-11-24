<?php
// [D-ESC-16] Feriados (migration 027).
// GET ?year=AAAA: os feriados do ano, para qualquer sessao do portal (o nome
//      do feriado nao e dado de pessoa).
// POST (feriados.manage, CSRF: Lideranca e admin): save (sem id cria; com id
//      e row_version altera) e delete (id e row_version). 409 quando outra
//      pessoa mudou antes ou a data ja tem feriado.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/HolidayService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();

try {
    $service = new HolidayService();
    if ($method === 'GET') {
        json_response(['sucesso' => true] + $service->list($_GET, $role));
    }
    if (!portal_role_can($role, 'feriados.manage')) {
        audit_log('FERIADO_NEGADO', 'Perfil sem permissao tentou alterar feriados.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => HolidayService::FORBIDDEN_MESSAGE], 403);
    }
    $data = portal_json_input();
    $action = $data['action'] ?? null;
    if ($action === 'save') {
        $saved = $service->save($data, portal_username(), $role);
        json_response(['sucesso' => true, 'mensagem' => $saved['created'] ? 'Feriado cadastrado.' : 'Feriado salvo.'] + $saved);
    }
    if ($action === 'delete') {
        json_response(['sucesso' => true, 'mensagem' => 'Feriado removido.'] + $service->delete($data, $role));
    }
    throw new InvalidArgumentException('Ação de feriado inválida.');
} catch (Throwable $e) {
    portal_operational_error($e);
}
