<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../init.php';

$method = require_http_method(['POST']);
$role = require_portal_auth();

if ($role !== 'admin') {
    audit_log(
        'BREAK_FORCE_END_DENIED',
        'Perfil sem permissao tentou derrubar pausa. Usuario: ' . portal_username() . ', role: ' . $role,
        'WARNING'
    );
    json_response(['sucesso' => false, 'mensagem' => 'Apenas administradores podem derrubar pausas.'], 403);
}

try {
    $data = portal_json_input();
    $rawFuncionarioId = $data['funcionario_id'] ?? $data['employee_id'] ?? null;
    if (!is_scalar($rawFuncionarioId)) {
        throw new InvalidArgumentException('Funcionario invalido.');
    }
    $funcionarioId = filter_var(
        $rawFuncionarioId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if (!$funcionarioId) {
        throw new InvalidArgumentException('Funcionario invalido.');
    }

    $rawJustificativa = $data['justificativa'] ?? '';
    if (!is_scalar($rawJustificativa) && $rawJustificativa !== null) {
        throw new InvalidArgumentException('Justificativa invalida.');
    }
    $justificativa = sanitize_input($rawJustificativa ?? '', 500);
    global $gerenciador;

    $resultado = with_pause_state_lock(function () use ($gerenciador, $funcionarioId, $justificativa) {
        $gerenciador->carregar_estado();
        $funcionario = $gerenciador->getFuncionario((int)$funcionarioId);
        if (!$funcionario) {
            return ['sucesso' => false, 'mensagem' => 'Funcionario nao encontrado.', 'http_status' => 404];
        }
        if (!($funcionario->ativo ?? true)) {
            return ['sucesso' => false, 'mensagem' => 'Funcionario inativo nao pode ser operado.', 'http_status' => 409];
        }
        if (!$funcionario->em_pausa) {
            return ['sucesso' => false, 'mensagem' => $funcionario->nome . ' nao esta em pausa.', 'http_status' => 409];
        }

        return $gerenciador->finalizar_pausa((int)$funcionarioId, [
            'origem' => 'admin_force_end',
            'admin' => portal_username(),
            'justificativa' => $justificativa,
        ]);
    });

    if ($resultado['sucesso']) {
        $detalhes = $resultado['detalhes'] ?? [];
        audit_log(
            'BREAK_FORCE_ENDED_BY_ADMIN',
            'Admin ' . portal_username()
                . ' derrubou pausa do funcionario ID ' . (int)($detalhes['funcionario_id'] ?? $funcionarioId)
                . ' (' . ($detalhes['funcionario_nome'] ?? 'desconhecido') . ')'
                . '; motivo=' . ($detalhes['motivo_pausa'] ?? 'n/a')
                . '; inicio=' . ($detalhes['inicio_pausa'] ?? 'n/a')
                . '; fim=' . ($detalhes['fim_pausa'] ?? 'n/a')
                . '; duracao_segundos=' . (int)($detalhes['duracao_segundos'] ?? 0)
                . '; origem=derrubada manual por admin'
                . ($justificativa !== '' ? '; justificativa=' . $justificativa : ''),
            'WARNING'
        );
    }

    $httpStatus = $resultado['sucesso'] ? 200 : (int)($resultado['http_status'] ?? 409);
    unset($resultado['http_status']);
    json_response($resultado, $httpStatus);
} catch (Throwable $error) {
    portal_operational_error($error);
}
