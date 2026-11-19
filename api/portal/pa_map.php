<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/PaMapService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();

try {
    // [Lote 4, 0d] Conexao dentro do try: banco indisponivel vira 503 pelo
    // portal_operational_error(), sem excecao sem captura.
    $service = new PaMapService();
    if ($method === 'GET') {
        // [F6] Ator para a P9 (categoria so para a gestao e o proprio) e quem
        // edita o mapa para a DF7 (logins, observacao e elegiveis).
        $canManage = portal_role_can($role, 'pa_map.manage');
        json_response([
            'sucesso' => true,
            'can_manage' => $canManage,
        ] + $service->list($_GET, portal_actor($role), $canManage));
    }

    if (!portal_role_can($role, 'pa_map.manage')) {
        audit_log('PA_MAP_WRITE_DENIED', 'Perfil sem permissao tentou alterar Mapa de PA.', 'WARNING');
        json_response([
            'sucesso' => false,
            'mensagem' => 'Apenas administradores e Liderança podem alterar o Mapa de PA.',
        ], 403);
    }

    $data = portal_json_input(PaMapService::MAX_PAYLOAD_BYTES);
    $action = $data['action'] ?? 'save';
    if (!is_string($action)) {
        throw new InvalidArgumentException('Ação inválida.');
    }

    if ($action === 'remove') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            throw new InvalidArgumentException('Alocação inválida.');
        }
        // [F6, DF11] Auditoria com o vinculo, o PA e o colaborador (IDs; sem nome nem login).
        $removed = $service->remove((int)$id, portal_username());
        audit_log('PA_MAP_REMOVED', 'Alocacao de PA removida. ID ' . $removed['id'] . ', PA ' . $removed['pa_number'] . ', colaborador ID ' . $removed['employee_id'], 'INFO');
        json_response(['sucesso' => true, 'mensagem' => 'PA desocupado.']);
    }

    // [F10-3] Inventario: tipo, emprestimo e reserva do PA (WARNING: muda a
    // distribuicao e quem ocupa o PA); preferencias (INFO). So IDs e valores
    // da lista fechada, sem nome nem login.
    if ($action === 'inventory_save') {
        $result = $service->saveSeat($data, portal_username());
        if ($result['changed'] !== []) {
            $describe = static fn (array $seat): string => 'tipo ' . $seat['seat_type'] . ', empresta ' . ($seat['can_lend'] ? 'sim' : 'nao') . ', reserva ' . ($seat['reserved_team'] ?? 'nenhuma');
            audit_log('PA_INVENTARIO_ALTERADO', 'Inventario de PA alterado. PA ' . $result['pa_number'] . ': ' . $describe($result['before']) . ' -> ' . $describe($result['after']), 'WARNING');
        }
        json_response(['sucesso' => true, 'pa_number' => $result['pa_number'], 'changed' => $result['changed'], 'mensagem' => $result['changed'] === [] ? 'Nada mudou no PA.' : 'Inventário do PA salvo.']);
    }
    if ($action === 'preferences_save') {
        $result = $service->savePreferences($data, portal_username());
        if ($result['added'] !== [] || $result['removed'] !== []) {
            audit_log('PA_PREFERENCIA_ALTERADA', 'Preferencias de PA alteradas. PA ' . $result['pa_number'] . ', incluidos ID ' . ($result['added'] === [] ? 'nenhum' : implode(', ', $result['added'])) . ', retirados ID ' . ($result['removed'] === [] ? 'nenhum' : implode(', ', $result['removed'])), 'INFO');
        }
        json_response(['sucesso' => true, 'pa_number' => $result['pa_number'], 'total' => $result['total'], 'mensagem' => 'Preferências do PA salvas.']);
    }

    if ($action !== 'save') {
        throw new InvalidArgumentException('Ação inválida.');
    }
    // [F6, P13] Conflito, remoto, ausencia e sem escala nao bloqueiam: a
    // resposta traz warnings[] e conflicts[], e o conflito aceito e auditado.
    $result = $service->save($data, portal_username());
    audit_log('PA_MAP_SAVED', 'Alocacao de PA salva. ID ' . $result['id'] . ', PA ' . $result['pa_number'] . ', colaborador ID ' . $result['employee_id'], 'INFO');
    if ($result['conflict_audit'] !== null) {
        audit_log('PA_MAP_CONFLICT_ACCEPTED', $result['conflict_audit'], 'WARNING');
    }
    json_response([
        'sucesso' => true,
        'id' => $result['id'],
        'warnings' => $result['warnings'],
        'conflicts' => $result['conflicts'],
        'conflicts_more' => $result['conflicts_more'],
        'mensagem' => 'Alocação de PA salva.',
    ]);
} catch (Throwable $error) {
    portal_operational_error($error);
}
