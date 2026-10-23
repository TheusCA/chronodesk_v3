<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../services/PresenceService.php';

require_get_method();
$role = require_portal_auth();
$viewer = portal_actor($role);

// [F7, D14] Situacao de agora pelo motor, atras do mesmo interruptor do
// status.php (DV4) e sem derrubar a lista se o calculo falhar (DV5). Sem
// polling (P14) e sem ler a data de corte (DV3).
global $gerenciador;
$now = new DateTimeImmutable('now');
$pausas = $gerenciador->pausas_atuais();
$presence = null;
if (PresenceService::statusPresenceEnabled()) {
    try {
        $presence = (new PresenceService(get_db_connection()))->technicianPresence($now, $pausas, $viewer);
    } catch (Throwable $error) {
        error_log('[PRESENCE] Presenca indisponivel na lista de tecnicos: ' . get_class($error));
    }
}
// [F7, DV9] Perfil de acesso so para quem administra cadastros (a tela nao usa o campo).
$seesRole = in_array('funcionarios.manage', portal_permissions_for_role($role), true);

$items = [];
foreach ($gerenciador->getFuncionarios() as $funcionario) {
    if (
        !($funcionario->ativo ?? true)
        || !in_array(strtolower((string)$funcionario->equipe), ['n1', 'n2'], true)
    ) {
        continue;
    }
    $item = [
        'id' => (int)$funcionario->id,
        'name' => $funcionario->nome,
        'team' => strtoupper($funcionario->equipe),
    ];
    if ($seesRole) {
        $item['role'] = ucfirst(str_replace('_', ' ', $funcionario->access_role ?? 'tecnico'));
    }
    $items[] = $item + [
        'shift' => $funcionario->jornada_entrada . ' - ' . $funcionario->jornada_saida,
        'status' => $funcionario->status_disponibilidade()['label'] ?? 'Indisponível',
        'on_break' => (bool)$funcionario->em_pausa,
        'presence' => $presence[(int)$funcionario->id] ?? null,
    ];
}
json_response([
    'sucesso' => true,
    'server_now' => $now->format(DATE_ATOM),
    'presence_meta' => [
        'disponivel' => $presence !== null,
        'pausas_disponiveis' => $pausas !== null,
    ],
    'items' => $items,
]);
