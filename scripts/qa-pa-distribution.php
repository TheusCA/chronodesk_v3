<?php
declare(strict_types=1);
// ============================================================================
// QA do motor de distribuicao de PA do F10 (desenho F10, 3.4; lote F10-1).
// Sem banco, sem rede, sem dado real: IDs e nomes ficticios.
//
// 1. Casos em tabela: custo 0/2/12+|j-i|, desempate, ordem por nome
//    normalizado (ID no desempate), emprestimo, reservado, dono com vigencia,
//    bloqueado e inativo, travas (efeito e cada motivo de trava ignorada),
//    "sem PA" pelo guloso, guloso acima de 16 PAs, continuidade (last_pa),
//    alertas, status, determinismo e validacao da entrada.
// 2. Porta em PHP do build_pa_assignments do script da planilha (por nome,
//    como o original) contra o motor, em 1000 meses aleatorios de semente fixa.
// 3. O motor e puro (sem banco, arquivo, relogio) e nada em producao o chama.
// ============================================================================
require_once __DIR__ . '/../services/PaDistributionEngine.php';

$root = dirname(__DIR__);
$checks = 0;
function assert_same($expected, $actual, string $message): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n  esperado: " . var_export($expected, true) . "\n  obtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function assert_invalid(array $input, string $needle, string $message): void {
    try {
        PaDistributionEngine::distribute($input);
    } catch (InvalidArgumentException $e) {
        assert_same(true, strpos($e->getMessage(), $needle) !== false, "{$message} (mensagem: {$e->getMessage()})");
        return;
    }
    assert_same('InvalidArgumentException', 'nenhuma excecao', $message);
}

/** PAs flexiveis na fileira 1, colunas pela ordem dada. */
function flex(array $numbers): array {
    return array_map(static fn (int $pa, int $i): array => ['pa' => $pa, 'row' => 1, 'column' => $i, 'type' => 'flexible'], $numbers, array_keys($numbers));
}
/** Todos presenciais em todas as datas. */
function onsite(array $days, array $ids): array {
    $out = [];
    foreach ($days as $day) {
        foreach ($ids as $id) {
            $out[$day][$id] = 'onsite';
        }
    }
    return $out;
}
/** [pa => employee_id] (reservado como 'R:hybrid') de um dia. */
function seated(array $day): array {
    return array_map(static fn (array $o) => $o['employee_id'] ?? 'R:' . $o['reserved_for'], $day['occupants']);
}
function run(array $input): array {
    return PaDistributionEngine::distribute($input + ['presence' => [], 'people' => []]);
}

$D1 = '2026-12-01';
$D2 = '2026-12-02';
$D3 = '2026-12-03';

// ----------------------------------------------------------------------------
// 1. Casos em tabela
// ----------------------------------------------------------------------------

// Nome normalizado (norm() do script).
foreach ([
    [' joão ', 'JOAO'], ['çé', 'CE'], ['Ödon Ávila', 'ODON AVILA'], ["\u{00A0}Íris\t", 'IRIS'], ["Ana\u{2003}", 'ANA'],
    ['ñ', 'Ñ'], ['è', 'È'], ['ß', 'SS'], ['ÿ', "\u{0178}"], ['Ü', 'U'], ['abc', 'ABC'], ['÷', '÷'],
] as [$in, $out]) {
    assert_same($out, PaDistributionEngine::normalizeName($in), "normalizeName: {$in}");
}

// Custo 12 + |j - i|: sem historico nem preferencia, a pessoa i vai para o PA i.
$r = run(['days' => [$D1], 'seats' => flex([5, 3, 9]), 'people' => [7 => 'Bia', 4 => 'Ana', 9 => 'Caio'], 'presence' => onsite([$D1], [4, 7, 9])]);
assert_same([5 => 4, 3 => 7, 9 => 9], seated($r['days'][$D1]), 'custo 12+|j-i|: pessoa i (ordem do nome) no PA i (ordem do PA)');
assert_same(['dp', 0, 3, 3], [$r['days'][$D1]['method'], $r['days'][$D1]['moves'], $r['days'][$D1]['capacity'], $r['days'][$D1]['onsite']], 'programacao dinamica, sem troca no 1o dia, capacidade e presenciais');
assert_same('engine', $r['days'][$D1]['occupants'][5]['source'], 'fonte engine');
assert_same([], $r['days'][$D1]['status'], 'status OK (lista vazia)');

// Ordem: nome normalizado (acento nao conta), ID no desempate entre homonimos.
$r = run(['days' => [$D1], 'seats' => flex([1, 2, 3, 4]), 'people' => [30 => 'Érica', 20 => 'eduardo', 12 => 'Ana', 11 => 'ANA'], 'presence' => onsite([$D1], [11, 12, 20, 30])]);
assert_same([1 => 11, 2 => 12, 3 => 20, 4 => 30], seated($r['days'][$D1]), 'ordem: ANA(11), ANA(12), EDUARDO, ERICA');

// Preferencia (2) vence a posicao; last_pa (0) vence a preferencia.
$r = run(['days' => [$D1], 'seats' => flex([1, 2, 3]), 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'preferences' => [3 => [1]]]);
assert_same([2 => 2, 3 => 1], seated($r['days'][$D1]), 'preferencia: A no PA 3 (2), B no PA 2 (12+0)');
$r = run(['days' => [$D1], 'seats' => flex([1, 2, 3]), 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'preferences' => [3 => [1]], 'last_pa' => [1 => 2]]);
assert_same([1 => 2, 2 => 1], seated($r['days'][$D1]), 'last_pa: A fica no PA 2 (0) apesar da preferencia; B no 1');
assert_same([0, []], [$r['days'][$D1]['moves'], $r['days'][$D1]['moved']], 'last_pa mantido: sem troca');
assert_same([0, 2, 12], [PaDistributionEngine::COST_LAST_PA, PaDistributionEngine::COST_PREFERENCE, PaDistributionEngine::COST_OTHER], 'constantes de custo do script (0, 2 e 12)');

// Desempate do "<" estrito: entre os de custo minimo, a sequencia de PAs menor.
// A e B preferem o PA 3. A3+B2 = 2+12 = 14; A1+B3 = 12+2 = 14. Fica (A1, B3).
$r = run(['days' => [$D1], 'seats' => flex([1, 2, 3]), 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'preferences' => [3 => [1, 2]]]);
assert_same([1 => 1, 3 => 2], seated($r['days'][$D1]), 'empate de custo: primeiro PA da ordem para a primeira pessoa');
// Dois com o mesmo last_pa: vale o custo total. A2(0)+B1(12+1) = 13;
// A1(12+0)+B2(0) = 12: B fica no PA 2 e A troca.
$r = run(['days' => [$D1], 'seats' => flex([1, 2]), 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'last_pa' => [1 => 2, 2 => 2]]);
assert_same([1 => 1, 2 => 2], seated($r['days'][$D1]), 'dois com o mesmo last_pa: o menor custo total (12 contra 13)');
assert_same([1, [1]], [$r['days'][$D1]['moves'], $r['days'][$D1]['moved']], 'troca contada para quem mudou');
// Otimo global: o guloso por pessoa daria A1(0)+B? ; aqui o total manda.
// A: last 1. B: preferencia 1. C: last 2. Seats 1,2,3.
$r = run(['days' => [$D1], 'seats' => flex([1, 2, 3]), 'people' => [1 => 'A', 2 => 'B', 3 => 'C'], 'presence' => onsite([$D1], [1, 2, 3]), 'last_pa' => [1 => 1, 3 => 2], 'preferences' => [1 => [2]]]);
assert_same([1 => 1, 2 => 3, 3 => 2], seated($r['days'][$D1]), 'otimo global: A1(0), C2(0), B3(12+1)');

// PA fixo: dono presente senta; ausente, so empresta se puder.
$fixed = static fn (int $pa, int $owner, bool $lend, array $extra = []): array => ['pa' => $pa, 'row' => 1, 'column' => 0, 'type' => 'fixed', 'can_lend' => $lend, 'owners' => [['employee_id' => $owner]]] + $extra;
$seats = array_merge([$fixed(1, 50, false)], flex([2]));
$r = run(['days' => [$D1, $D2], 'seats' => $seats, 'people' => [50 => 'Dono', 1 => 'A'], 'presence' => [$D1 => [50 => 'onsite', 1 => 'onsite'], $D2 => [50 => 'remote', 1 => 'onsite']]]);
assert_same([[1 => 50, 2 => 1], ['fixed', 'engine']], [seated($r['days'][$D1]), array_column($r['days'][$D1]['occupants'], 'source')], 'dono presente no PA fixo (fonte fixed)');
assert_same([[2 => 1], 1], [seated($r['days'][$D2]), $r['days'][$D2]['capacity']], 'dono ausente e PA sem emprestimo: PA 1 fica vazio');
assert_same([2, 1], [$r['days'][$D1]['onsite'], $r['days'][$D1]['capacity']], 'presenciais contam o dono; capacidade so a lista do motor');
assert_same([1 => 2, 50 => 1], $r['last_pa'], 'dono do grupo comum atualiza o last_pa');
$seats = array_merge([$fixed(1, 50, true)], flex([2]));
$r = run(['days' => [$D1], 'seats' => $seats, 'people' => [50 => 'Dono', 1 => 'A', 2 => 'B'], 'presence' => [$D1 => [50 => 'absent', 1 => 'onsite', 2 => 'onsite']]]);
assert_same([[1 => 1, 2 => 2], 2], [seated($r['days'][$D1]), $r['days'][$D1]['capacity']], 'dono ausente e PA que empresta: entra na lista');
// Dono de fora do grupo comum (presenca dada), sem entrada de presenca = ausente.
$r = run(['days' => [$D1], 'seats' => [$fixed(1, 90, true)], 'people' => [1 => 'A'], 'presence' => [$D1 => [90 => 'onsite', 1 => 'onsite']]]);
assert_same([[1 => 90], [1], [], []], [seated($r['days'][$D1]), $r['days'][$D1]['no_pa'], $r['last_pa'], array_column($r['days'][$D1]['alerts'], 'code') === ['no_pa'] ? [] : ['?']], 'dono de fora do grupo comum presente: senta, nao entra no last_pa, A fica sem PA');
$r = run(['days' => [$D1], 'seats' => [$fixed(1, 90, true)], 'people' => [1 => 'A'], 'presence' => [$D1 => [1 => 'onsite']]]);
assert_same([1 => 1], seated($r['days'][$D1]), 'dono sem presenca informada: ausente, o PA empresta');
// Dono troca de PA fixo pelo dono: nao conta troca.
$r = run(['days' => [$D1], 'seats' => [$fixed(1, 50, false)], 'people' => [50 => 'Dono'], 'presence' => onsite([$D1], [50]), 'last_pa' => [50 => 7]]);
assert_same([0, [50 => 1]], [$r['days'][$D1]['moves'], $r['last_pa']], 'dono fixo atualiza last_pa sem contar troca');
// Vigencia do dono (inclusiva) e sobreposicao.
$seats = [['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'fixed', 'can_lend' => true, 'owners' => [['employee_id' => 50, 'from' => $D2, 'until' => $D2]]]];
$r = run(['days' => [$D1, $D2, $D3], 'seats' => $seats, 'people' => [1 => 'A'], 'presence' => [$D1 => [50 => 'onsite', 1 => 'onsite'], $D2 => [50 => 'onsite', 1 => 'onsite'], $D3 => [50 => 'onsite', 1 => 'onsite']]]);
assert_same([[1 => 1], [1 => 50], [1 => 1]], [seated($r['days'][$D1]), seated($r['days'][$D2]), seated($r['days'][$D3])], 'dono so dentro da vigencia (de e ate inclusivos)');
$seats = [['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'fixed', 'owners' => [['employee_id' => 50, 'until' => $D2], ['employee_id' => 60, 'from' => $D2]]]];
$r = run(['days' => [$D2, $D3], 'seats' => $seats, 'presence' => onsite([$D2, $D3], [50, 60])]);
assert_same([[1 => 50], [1 => 60]], [seated($r['days'][$D2]), seated($r['days'][$D3])], 'sobreposicao: o primeiro dono informado');
assert_same([[['code' => 'owner_overlap', 'pa' => 1, 'count' => 2]], ['ATENCAO CONFIG'], []], [$r['days'][$D2]['alerts'], $r['days'][$D2]['status'], $r['days'][$D3]['alerts']], 'sobreposicao de donos: alerta e ATENCAO CONFIG so no dia dela');
// Dono de dois PAs fixos no mesmo dia: so o primeiro da ordem.
$seats = [$fixed(2, 50, true), ['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'fixed', 'can_lend' => false, 'owners' => [['employee_id' => 50]]]];
$seats[0]['column'] = 1;
$r = run(['days' => [$D1], 'seats' => $seats, 'people' => [1 => 'A'], 'presence' => onsite([$D1], [50, 1])]);
assert_same([[1 => 50, 2 => 1], ['owner_multiple_seats'], ['ATENCAO CONFIG']], [seated($r['days'][$D1]), array_column($r['days'][$D1]['alerts'], 'code'), $r['days'][$D1]['status']], 'dono de dois PAs: o primeiro da ordem; o segundo empresta, com alerta');

// Reservado (Hibrido/Critico): sempre ocupado, nunca na lista.
$seats = array_merge([['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'fixed', 'reserved_for' => 'critical', 'can_lend' => true, 'owners' => [['employee_id' => 1]]], ['pa' => 2, 'row' => 1, 'column' => 1, 'type' => 'fixed', 'reserved_for' => 'hybrid']], [['pa' => 3, 'row' => 2, 'column' => 0, 'type' => 'flexible']]);
$r = run(['days' => [$D1], 'seats' => $seats, 'people' => [1 => 'A'], 'presence' => onsite([$D1], [1])]);
assert_same([[1 => 'R:critical', 2 => 'R:hybrid', 3 => 1], 1, 'reserved'], [seated($r['days'][$D1]), $r['days'][$D1]['capacity'], $r['days'][$D1]['occupants'][1]['source']], 'reservado sempre ocupado (fonte reserved), mesmo com dono e emprestimo; capacidade 1');

// Inativo e indisponivel: nunca entram; o dono do grupo comum vai para a lista.
$seats = array_merge([$fixed(1, 1, true, ['unavailable' => true]), ['pa' => 2, 'row' => 1, 'column' => 1, 'type' => 'flexible', 'active' => false]], [['pa' => 3, 'row' => 2, 'column' => 0, 'type' => 'flexible']]);
$r = run(['days' => [$D1], 'seats' => $seats, 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2])]);
assert_same([[3 => 1], [2], 1, ['PA INSUFICIENTE']], [seated($r['days'][$D1]), $r['days'][$D1]['no_pa'], $r['days'][$D1]['capacity'], $r['days'][$D1]['status']], 'PA bloqueado e inativo fora; dono do bloqueado na lista; sem PA');

// Ordem dos PAs: fileira, coluna, numero (a entrada pode vir em qualquer ordem).
$seats = [['pa' => 9, 'row' => 2, 'column' => 0, 'type' => 'flexible'], ['pa' => 8, 'row' => 1, 'column' => 5, 'type' => 'flexible'], ['pa' => 4, 'row' => 1, 'column' => 5, 'type' => 'flexible'], ['pa' => 7, 'row' => 1, 'column' => 2, 'type' => 'flexible']];
$r = run(['days' => [$D1], 'seats' => $seats, 'people' => [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D'], 'presence' => onsite([$D1], [1, 2, 3, 4])]);
assert_same([7 => 1, 4 => 2, 8 => 3, 9 => 4], seated($r['days'][$D1]), 'ordem dos PAs: fileira, coluna, numero');

// Falta PA: guloso por PA, pontos 0/2/12, desempate pelo nome e pelo ID.
$r = run(['days' => [$D1], 'seats' => flex([1, 2]), 'people' => [5 => 'Caio', 6 => 'Bia', 7 => 'Ana'], 'presence' => onsite([$D1], [5, 6, 7]), 'preferences' => [2 => [5]], 'last_pa' => [6 => 7]]);
assert_same([[1 => 7, 2 => 5], [6], 'greedy'], [seated($r['days'][$D1]), $r['days'][$D1]['no_pa'], $r['days'][$D1]['method']], 'guloso: PA 1 para Ana (12, nome), PA 2 para Caio (preferencia); Bia sem PA');
assert_same([['PA INSUFICIENTE'], [['code' => 'no_pa', 'count' => 1]]], [$r['days'][$D1]['status'], $r['days'][$D1]['alerts']], 'sem PA: status e alerta com a contagem');
assert_same([5 => 2, 6 => 7, 7 => 1], $r['last_pa'], 'sem PA mantem o last_pa antigo (saida ordenada por ID)');
$r = run(['days' => [$D1], 'seats' => flex([1]), 'people' => [9 => 'Ana', 3 => 'ANA'], 'presence' => onsite([$D1], [3, 9])]);
assert_same([[1 => 3], [9]], [seated($r['days'][$D1]), $r['days'][$D1]['no_pa']], 'guloso: homonimos pelo ID');
$r = run(['days' => [$D1], 'seats' => flex([1, 2]), 'people' => [1 => 'Ana', 2 => 'Bia', 3 => 'Caio'], 'presence' => onsite([$D1], [1, 2, 3]), 'last_pa' => [3 => 1, 2 => 2]]);
assert_same([[1 => 3, 2 => 2], [1], 0], [seated($r['days'][$D1]), $r['days'][$D1]['no_pa'], $r['days'][$D1]['moves']], 'guloso: last_pa (0) vence o nome');
$r = run(['days' => [$D1], 'seats' => [], 'people' => [1 => 'Ana'], 'presence' => onsite([$D1], [1])]);
assert_same([[], [1], 'greedy', 0], [seated($r['days'][$D1]), $r['days'][$D1]['no_pa'], $r['days'][$D1]['method'], $r['days'][$D1]['capacity']], 'sem nenhum PA: todos sem PA');

// Limite da programacao dinamica: ate 16 PAs livres; acima, guloso com alerta.
assert_same(16, PaDistributionEngine::DP_MAX_SEATS, 'limite da programacao dinamica (D-F10-17)');
$sixteen = flex(range(1, 16));
$r = run(['days' => [$D1], 'seats' => $sixteen, 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'preferences' => [16 => [2], 15 => [1]]]);
assert_same(['dp', [15 => 1, 16 => 2], []], [$r['days'][$D1]['method'], seated($r['days'][$D1]), $r['days'][$D1]['alerts']], '16 PAs livres: programacao dinamica');
$r = run(['days' => [$D1], 'seats' => flex(range(1, 17)), 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'preferences' => [16 => [2], 15 => [1]]]);
assert_same(['greedy', [1 => 1, 2 => 2], [['code' => 'greedy_large', 'seats' => 17]], []], [$r['days'][$D1]['method'], seated($r['days'][$D1]), $r['days'][$D1]['alerts'], $r['days'][$D1]['status']], '17 PAs livres: guloso, alerta greedy_large, sem mudar o status');
// O guloso nao e otimo: com 16 a dinamica acha o melhor que o guloso nao acha.
$r = run(['days' => [$D1], 'seats' => $sixteen, 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'preferences' => [1 => [1, 2], 2 => [1]]]);
assert_same([1 => 2, 2 => 1], seated($r['days'][$D1]), 'dinamica: B no PA 1 e A no PA 2 (2+2), o guloso daria A1 B2 (2+12)');
$r = run(['days' => [$D1], 'seats' => flex(range(1, 17)), 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'preferences' => [1 => [1, 2], 2 => [1]]]);
assert_same([1 => 1, 2 => 2], seated($r['days'][$D1]), 'guloso com 17: A1 (nome) e B2');
$r = run(['days' => [$D1], 'seats' => flex(range(1, 3)), 'people' => [], 'presence' => [$D1 => []]]);
assert_same(['none', [], 3, 0], [$r['days'][$D1]['method'], seated($r['days'][$D1]), $r['days'][$D1]['capacity'], $r['days'][$D1]['onsite']], 'ninguem presencial: metodo none');

// Continuidade entre os dias e last_pa final.
$days = [$D1, $D2, $D3];
$presence = [$D1 => [1 => 'onsite', 2 => 'onsite'], $D2 => [1 => 'remote', 2 => 'onsite'], $D3 => [1 => 'onsite', 2 => 'onsite']];
$r = run(['days' => $days, 'seats' => flex([1, 2]), 'people' => [1 => 'A', 2 => 'B'], 'presence' => $presence, 'preferences' => [1 => [2]]]);
assert_same([[1 => 2, 2 => 1], [1 => 2], [1 => 2, 2 => 1]], [seated($r['days'][$D1]), seated($r['days'][$D2]), seated($r['days'][$D3])], 'continuidade: cada um volta ao PA do dia util anterior em que esteve');
assert_same([0, 0, 0], array_column($r['days'], 'moves'), 'continuidade: sem troca');
assert_same([1 => 2, 2 => 1], $r['last_pa'], 'last_pa final para o proximo mes');
$r = run(['days' => [$D1], 'seats' => flex([1, 2]), 'people' => [1 => 'A', 2 => 'B'], 'presence' => onsite([$D1], [1, 2]), 'last_pa' => [1 => 2, 2 => 1]]);
assert_same([[1 => 2, 2 => 1], 0], [seated($r['days'][$D1]), $r['days'][$D1]['moves']], 'last_pa inicial (mes anterior publicado) vale no 1o dia');
$r = run(['days' => [$D1], 'seats' => flex([1, 2]), 'people' => [1 => 'A'], 'presence' => onsite([$D1], [1]), 'last_pa' => [1 => 9]]);
assert_same([[1 => 1], 1, [1]], [seated($r['days'][$D1]), $r['days'][$D1]['moves'], $r['days'][$D1]['moved']], 'last_pa de PA que nao esta livre hoje: troca contada');
$r = run(['days' => [$D1], 'seats' => flex([1, 2]), 'people' => [5 => 'Ana', 3 => 'Bia'], 'presence' => onsite([$D1], [3, 5]), 'last_pa' => [5 => 9, 3 => 8]]);
assert_same([[1 => 5, 2 => 3], [3, 5]], [seated($r['days'][$D1]), $r['days'][$D1]['moved']], 'duas trocas no dia: moved em ordem de ID, nao na de ocupacao');

// Travas manuais por dia.
$base = ['days' => [$D1, $D2], 'seats' => array_merge([$fixed(10, 50, false)], flex([1, 2, 3])), 'people' => [1 => 'A', 2 => 'B', 3 => 'C', 50 => 'Dono'], 'presence' => onsite([$D1, $D2], [1, 2, 3, 50])];
$r = run($base + ['locks' => [$D1 => [['pa' => 1, 'employee_id' => 3]]], 'last_pa' => [3 => 2]]);
assert_same([[1 => 3, 10 => 50, 2 => 1, 3 => 2], 'lock', [3], []], [seated($r['days'][$D1]), $r['days'][$D1]['occupants'][1]['source'], $r['days'][$D1]['moved'], $r['days'][$D1]['status']], 'trava: C no PA 1 (fonte lock), conta troca, o motor distribui o resto');
assert_same([1 => 3, 10 => 50, 2 => 1, 3 => 2], seated($r['days'][$D2]), 'trava atualiza o last_pa: no dia seguinte C fica no PA 1 sem trava');
foreach ([
    'duplicate_seat' => [['pa' => 1, 'employee_id' => 1], ['pa' => 1, 'employee_id' => 2]],
    'duplicate_person' => [['pa' => 1, 'employee_id' => 1], ['pa' => 2, 'employee_id' => 1]],
    'unknown_seat' => [['pa' => 99, 'employee_id' => 1]],
    'seat_not_available' => [['pa' => 10, 'employee_id' => 1]],
    'person_not_common' => [['pa' => 1, 'employee_id' => 77]],
    'person_has_fixed_seat' => [['pa' => 1, 'employee_id' => 50]],
] as $reason => $locks) {
    $r = run($base + ['locks' => [$D1 => $locks]]);
    $ignored = array_values(array_filter($r['days'][$D1]['alerts'], static fn (array $a): bool => $a['code'] === 'lock_ignored'));
    assert_same([[$reason], ['ATENCAO CONFIG']], [array_column($ignored, 'reason'), $r['days'][$D1]['status']], "trava ignorada: {$reason}");
}
$r = run(['locks' => [$D1 => [['pa' => 1, 'employee_id' => 1]]], 'presence' => [$D1 => [1 => 'remote', 2 => 'onsite', 3 => 'onsite', 50 => 'onsite'], $D2 => onsite([$D2], [1, 2, 3, 50])[$D2]]] + $base);
assert_same(['person_not_onsite', [1 => 2, 10 => 50, 2 => 3]], [$r['days'][$D1]['alerts'][0]['reason'], seated($r['days'][$D1])], 'trava de quem nao esta presencial: ignorada, o PA volta para o motor');
$r1 = run($base + ['locks' => [$D1 => [['pa' => 2, 'employee_id' => 1], ['pa' => 2, 'employee_id' => 3]]]]);
$r2 = run($base + ['locks' => [$D1 => [['pa' => 2, 'employee_id' => 3], ['pa' => 2, 'employee_id' => 1]]]]);
assert_same([$r1, 1], [$r2, $r1['days'][$D1]['occupants'][2]['employee_id']], 'travas repetidas: a ordem de entrada nao muda o resultado (vale a de menor pessoa)');
$seats = array_merge([['pa' => 5, 'row' => 1, 'column' => 0, 'type' => 'flexible', 'unavailable' => true]], flex([1]));
$r = run(['days' => [$D1], 'seats' => $seats, 'people' => [1 => 'A'], 'presence' => onsite([$D1], [1]), 'locks' => [$D1 => [['pa' => 5, 'employee_id' => 1]]]]);
assert_same(['seat_not_available', [1 => 1]], [$r['days'][$D1]['alerts'][0]['reason'], seated($r['days'][$D1])], 'trava em PA indisponivel: ignorada');

// Presenca faltando para alguem do grupo comum.
$r = run(['days' => [$D1], 'seats' => flex([1]), 'people' => [1 => 'A', 2 => 'B'], 'presence' => [$D1 => [1 => 'onsite']]]);
assert_same([[['code' => 'presence_missing', 'employee_id' => 2]], ['ATENCAO CONFIG'], [1 => 1]], [$r['days'][$D1]['alerts'], $r['days'][$D1]['status'], seated($r['days'][$D1])], 'presenca faltando: alerta, ATENCAO CONFIG, pessoa fora da distribuicao');
$r = run(['days' => [$D1], 'seats' => flex([1]), 'people' => [1 => 'A', 2 => 'B', 3 => 'C'], 'presence' => [$D1 => [1 => 'onsite', 3 => 'onsite']]]);
assert_same(['ATENCAO CONFIG', 'PA INSUFICIENTE'], $r['days'][$D1]['status'], 'status com os dois motivos, na ordem do script (ATENCAO CONFIG primeiro)');

// Determinismo: mesma entrada, mesma saida; a ordem da entrada nao importa.
$input = ['days' => [$D1, $D2], 'seats' => array_merge([$fixed(10, 50, true)], flex([3, 1, 2])), 'people' => [3 => 'C', 1 => 'A', 2 => 'B', 50 => 'Dono'], 'presence' => onsite([$D1, $D2], [1, 2, 3]) + [], 'preferences' => [2 => [3, 1]]];
$shuffled = $input;
$shuffled['seats'] = array_reverse($input['seats']);
$shuffled['people'] = array_reverse($input['people'], true);
$shuffled['preferences'] = [2 => [1, 3]];
assert_same(run($input), run($shuffled), 'determinismo: ordem de PAs, pessoas e preferencias na entrada nao muda a saida');
assert_same(PaDistributionEngine::VERSION, run($input)['engine_version'], 'versao do motor na saida');

// Validacao da entrada (falha cedo, com mensagem).
assert_invalid(['days' => ['2026-02-30'], 'seats' => []], 'days', 'data invalida');
assert_invalid(['days' => [$D2, $D1], 'seats' => []], 'ordem crescente', 'datas fora de ordem');
assert_invalid(['days' => [$D1, $D1], 'seats' => []], 'ordem crescente', 'data repetida');
assert_invalid(['days' => [$D1]], 'seats', 'sem seats');
assert_invalid(['days' => [$D1], 'seats' => array_merge(flex([1]), flex([1]))], 'repetido', 'PA repetido');
assert_invalid(['days' => [$D1], 'seats' => [['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'shared']]], 'tipo', 'tipo invalido');
assert_invalid(['days' => [$D1], 'seats' => [['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'flexible', 'reserved_for' => 'hybrid']]], 'reserva', 'reserva em flexivel');
assert_invalid(['days' => [$D1], 'seats' => [['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'fixed', 'reserved_for' => 'tools']]], 'reserva', 'reserva desconhecida');
assert_invalid(['days' => [$D1], 'seats' => [['pa' => 1, 'row' => 0, 'column' => 0, 'type' => 'flexible']]], 'fileira', 'fileira 0');
assert_invalid(['days' => [$D1], 'seats' => [['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'flexible', 'can_lend' => 1]]], 'booleano', 'can_lend nao booleano');
assert_invalid(['days' => [$D1], 'seats' => [['pa' => '1', 'row' => 1, 'column' => 0, 'type' => 'flexible']]], 'inteiro', 'PA como texto');
assert_invalid(['days' => [$D1], 'seats' => [['pa' => 1, 'row' => 1, 'column' => 0, 'type' => 'fixed', 'owners' => [['employee_id' => 1, 'from' => $D2, 'until' => $D1]]]]], 'invertida', 'vigencia invertida');
assert_invalid(['days' => [$D1], 'seats' => [], 'presence' => [$D1 => [1 => 'home']]], 'situacao', 'situacao desconhecida');
assert_invalid(['days' => [$D1], 'seats' => [], 'people' => [1 => ' ']], 'nome', 'nome vazio');
assert_invalid(['days' => [$D1], 'seats' => flex([1]), 'preferences' => [2 => [1]]], 'preferences', 'preferencia de PA desconhecido');
assert_invalid(['days' => [$D1], 'seats' => [], 'locks' => [$D1 => [['pa' => 1]]]], 'locks.employee_id', 'trava sem pessoa');
assert_invalid(['days' => [$D1], 'seats' => [], 'last_pa' => [1 => 0]], 'last_pa', 'last_pa com PA 0');

// ----------------------------------------------------------------------------
// 2. Porta do build_pa_assignments do script (por nome) contra o motor
// ----------------------------------------------------------------------------
/**
 * Traducao literal do script V17 (linhas 355-522): nomes como chave, FIXO /
 * FLEXIVEL / BLOQUEADO, ocupantes "Hibrido"/"Critico" como texto sempre
 * presente, last_pa vazio no inicio. $status(nome, data) devolve PRESENCIAL,
 * HOME ou FERIAS; $outside[chave] o modo de quem esta fora do grupo comum.
 */
function script_port(array $paConfig, array $days, array $common, array $outside, array $alwaysPresent, callable $status): array {
    $norm = [PaDistributionEngine::class, 'normalizeName'];
    $commonKeys = [];
    foreach ($common as $name) {
        $commonKeys[$norm($name)] = $name;
    }
    $isPresent = static function (string $name, string $d) use ($norm, $commonKeys, $outside, $alwaysPresent, $status): bool {
        $key = $norm($name);
        // special_always_present do script: os textos HIBRIDO e CRITICO e dois nomes.
        if (in_array($key, array_merge(['HIBRIDO', 'CRITICO'], $alwaysPresent), true)) {
            return true;
        }
        if (isset($commonKeys[$key])) {
            return $status($commonKeys[$key], $d) === 'PRESENCIAL';
        }
        return in_array($outside[$key] ?? '', ['HIBRIDO', 'CRITICO'], true);
    };
    $assignments = [];
    $semPa = [];
    $moves = [];
    $lastPa = [];
    foreach ($days as $d) {
        $amap = [];
        $used = [];
        $available = [];
        foreach ($paConfig as $rec) {
            if ($rec['type'] === 'BLOQUEADO') {
                continue;
            }
            if ($rec['type'] === 'FIXO') {
                $owner = $rec['fixed'];
                if ($owner !== '' && $isPresent($owner, $d)) {
                    $amap[$rec['pa']] = $owner;
                    $used[$norm($owner)] = true;
                    if (isset($commonKeys[$norm($owner)])) {
                        $lastPa[$norm($owner)] = $rec['pa'];
                    }
                } elseif ($rec['loan']) {
                    $available[] = $rec;
                }
            } elseif ($rec['type'] === 'FLEXIVEL') {
                $available[] = $rec;
            }
        }
        $people = array_values(array_filter($common, static fn (string $p): bool => $status($p, $d) === 'PRESENCIAL' && !isset($used[$norm($p)])));
        usort($people, static fn (string $a, string $b): int => strcmp($norm($a), $norm($b)));
        usort($available, static fn (array $a, array $b): int => [$a['row'] === 'SUPERIOR' ? 0 : 1, $a['order'], $a['pa']] <=> [$b['row'] === 'SUPERIOR' ? 0 : 1, $b['order'], $b['pa']]);
        $prefKeys = [];
        foreach ($available as $rec) {
            $prefKeys[$rec['pa']] = array_map($norm, $rec['prefs']);
        }
        $unassigned = [];
        $today = 0;
        if ($people !== [] && count($people) <= count($available)) {
            $memo = [];
            $solve = static function (int $i, int $mask) use (&$solve, &$memo, $people, $available, $prefKeys, $lastPa, $norm): array {
                if ($i === count($people)) {
                    return [0, []];
                }
                if (isset($memo[$i][$mask])) {
                    return $memo[$i][$mask];
                }
                $best = null;
                $key = $norm($people[$i]);
                foreach ($available as $j => $rec) {
                    if ($mask & (1 << $j)) {
                        continue;
                    }
                    $p = $rec['pa'];
                    if (($lastPa[$key] ?? null) === $p) {
                        $cost = 0;
                    } elseif (in_array($key, $prefKeys[$p] ?? [], true)) {
                        $cost = 2;
                    } else {
                        $cost = 12 + abs($j - $i);
                    }
                    $next = $solve($i + 1, $mask | (1 << $j));
                    $candidate = [$cost + $next[0], array_merge([$j], $next[1])];
                    if ($best === null || $candidate[0] < $best[0]) {
                        $best = $candidate;
                    }
                }
                return $memo[$i][$mask] = $best;
            };
            $result = $solve(0, 0);
            foreach ($people as $i => $person) {
                $p = $available[$result[1][$i]]['pa'];
                $previous = $lastPa[$norm($person)] ?? null;
                $amap[$p] = $person;
                if ($previous !== null && $previous !== $p) {
                    $today++;
                }
                $lastPa[$norm($person)] = $p;
            }
        } elseif ($people !== []) {
            $unassigned = $people;
            foreach ($available as $rec) {
                $best = null;
                foreach ($unassigned as $person) {
                    $key = $norm($person);
                    $score = ($lastPa[$key] ?? null) === $rec['pa'] ? 0 : (in_array($key, $prefKeys[$rec['pa']] ?? [], true) ? 2 : 12);
                    $candidate = [$score, $key, $person];
                    if ($best === null || $candidate < $best) {
                        $best = $candidate;
                    }
                }
                if ($best !== null) {
                    $person = $best[2];
                    $previous = $lastPa[$norm($person)] ?? null;
                    $amap[$rec['pa']] = $person;
                    if ($previous !== null && $previous !== $rec['pa']) {
                        $today++;
                    }
                    $lastPa[$norm($person)] = $rec['pa'];
                    unset($unassigned[array_search($person, $unassigned, true)]);
                    $unassigned = array_values($unassigned);
                }
            }
        }
        $assignments[$d] = $amap;
        $semPa[$d] = $unassigned;
        $moves[$d] = $today;
    }
    return [$assignments, $semPa, $moves];
}

mt_srand(20261113, MT_RAND_MT19937);
$pick = static fn (array $list) => $list[mt_rand(0, count($list) - 1)];
$first = ['ana', 'Bruno', 'CAIO', 'Débora', 'érica', 'Fábio', 'Gil', 'hélio', 'Íris', 'João', 'Kátia', 'Lúcia', 'Mário', 'Núbia', 'Otávio', 'Pâmela', 'Sônia', 'Tânia', 'Úrsula', 'Zélia', 'Çaíque', 'Ödon'];
$last = ['Silva', 'Souza', 'Lima', 'Araújo', 'Gonçalves', 'Ünger', 'Ávila', 'Rêgo', 'Mota'];
$alwaysPresent = ['SEMPRE PRESENTE UM', 'SEMPRE PRESENTE DOIS'];
$months = 1000;
$totals = ['dias' => 0, 'dp' => 0, 'greedy' => 0, 'sem_pa' => 0, 'trocas' => 0];
for ($c = 0; $c < $months; $c++) {
    $year = mt_rand(2026, 2027);
    $month = mt_rand(1, 12);
    $days = [];
    for ($d = 1; $d <= (int)date('t', gmmktime(0, 0, 0, $month, 1, $year)); $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if ((int)gmdate('N', (int)strtotime($date . ' UTC')) <= 5) {
            $days[] = $date;
        }
    }
    $nCommon = mt_rand(0, mt_rand(0, 6) === 0 ? 16 : 11);
    $nOutside = mt_rand(0, 3);
    $names = [];
    while (count($names) < $nCommon + $nOutside + 2) {
        $name = $pick($first) . ' ' . $pick($last) . (mt_rand(0, 3) === 0 ? ' ' . $pick($last) : '');
        $names[PaDistributionEngine::normalizeName($name)] = mt_rand(0, 9) === 0 ? ' ' . $name . ' ' : $name;
    }
    $names = array_values($names);
    $common = array_slice($names, 0, $nCommon);
    $outsideNames = array_slice($names, $nCommon, $nOutside);
    $unknown = array_slice($names, $nCommon + $nOutside);
    $outside = [];
    foreach ($outsideNames as $name) {
        $outside[PaDistributionEngine::normalizeName($name)] = $pick(['HIBRIDO', 'CRITICO', 'FULL HOME', 'FORA']);
    }
    $statusTable = [];
    foreach ($common as $name) {
        foreach ($days as $date) {
            $roll = mt_rand(0, 9);
            $statusTable[$name][$date] = $roll < 6 ? 'PRESENCIAL' : ($roll < 9 ? 'HOME' : 'FERIAS');
        }
    }
    $status = static fn (string $name, string $date): string => $statusTable[$name][$date];
    $paConfig = [];
    $owners = [];
    $numbers = range(1, 40);
    shuffle($numbers);
    foreach (array_slice($numbers, 0, mt_rand(0, 16)) as $pa) {
        $roll = mt_rand(0, 99);
        $type = $roll < 56 ? 'FLEXIVEL' : ($roll < 88 ? 'FIXO' : ($roll < 96 ? 'BLOQUEADO' : 'OUTRO'));
        $owner = '';
        if ($type === 'FIXO') {
            $roll = mt_rand(0, 9);
            $pool = $roll < 4 ? $common : ($roll < 6 ? $outsideNames : ($roll < 7 ? ['Híbrido', 'CRITICO'] : ($roll < 8 ? $unknown : ($roll < 9 ? ['Sempre Presente Um', 'sempre presente dois'] : []))));
            $pool = array_values(array_filter($pool, static fn (string $n): bool => !isset($owners[PaDistributionEngine::normalizeName($n)]) || in_array(PaDistributionEngine::normalizeName($n), ['HIBRIDO', 'CRITICO'], true)));
            if ($pool !== []) {
                $owner = $pick($pool);
                $owners[PaDistributionEngine::normalizeName($owner)] = true;
            }
        }
        $prefs = [];
        if (mt_rand(0, 1) === 1) {
            for ($k = mt_rand(0, 3); $k > 0; $k--) {
                $prefs[] = $pick($names);
            }
        }
        $paConfig[] = ['pa' => $pa, 'row' => mt_rand(0, 1) === 0 ? 'SUPERIOR' : 'INFERIOR', 'order' => mt_rand(0, 5), 'type' => $type, 'fixed' => $owner, 'loan' => mt_rand(0, 9) < 4, 'prefs' => $prefs];
    }
    [$expected, $expectedSemPa, $expectedMoves] = script_port($paConfig, $days, $common, $outside, $alwaysPresent, $status);

    // Mesma configuracao no formato do motor.
    $ids = [];
    $id = static function (string $name) use (&$ids): int {
        $key = PaDistributionEngine::normalizeName($name);
        return $ids[$key] ??= count($ids) + 1;
    };
    $people = [];
    foreach ($common as $name) {
        $people[$id($name)] = $name;
    }
    $presence = [];
    foreach ($days as $date) {
        foreach ($common as $name) {
            $presence[$date][$id($name)] = ['PRESENCIAL' => 'onsite', 'HOME' => 'remote', 'FERIAS' => 'absent'][$status($name, $date)];
        }
        foreach ($outside as $key => $mode) {
            $presence[$date][$id($key)] = in_array($mode, ['HIBRIDO', 'CRITICO'], true) ? 'onsite' : ($mode === 'FULL HOME' ? 'remote' : 'absent');
        }
        foreach ($alwaysPresent as $key) {
            $presence[$date][$id($key)] = 'onsite';
        }
    }
    $seats = [];
    $preferences = [];
    foreach ($paConfig as $rec) {
        $ownerKey = PaDistributionEngine::normalizeName($rec['fixed']);
        $seat = ['pa' => $rec['pa'], 'row' => $rec['row'] === 'SUPERIOR' ? 1 : 2, 'column' => $rec['order'], 'type' => $rec['type'] === 'FIXO' ? 'fixed' : 'flexible', 'can_lend' => $rec['loan'], 'active' => $rec['type'] !== 'OUTRO', 'unavailable' => $rec['type'] === 'BLOQUEADO', 'owners' => []];
        if ($rec['type'] === 'FIXO' && in_array($ownerKey, ['HIBRIDO', 'CRITICO'], true)) {
            $seat['reserved_for'] = $ownerKey === 'HIBRIDO' ? 'hybrid' : 'critical';
        } elseif ($rec['type'] === 'FIXO' && $ownerKey !== '') {
            $seat['owners'] = [['employee_id' => $id($ownerKey)]];
        }
        $seats[] = $seat;
        $preferences[$rec['pa']] = array_map($id, $rec['prefs']);
    }
    $out = PaDistributionEngine::distribute(['days' => $days, 'seats' => $seats, 'people' => $people, 'presence' => $presence, 'preferences' => $preferences]);
    $keyOf = array_map('strval', array_flip($ids));
    foreach ($days as $date) {
        $day = $out['days'][$date];
        $got = [];
        foreach ($day['occupants'] as $pa => $occupant) {
            $got[$pa] = $occupant['employee_id'] !== null ? $keyOf[$occupant['employee_id']] : strtoupper($occupant['reserved_for'] === 'hybrid' ? 'HIBRIDO' : 'CRITICO');
        }
        $want = array_map([PaDistributionEngine::class, 'normalizeName'], $expected[$date]);
        ksort($got);
        ksort($want);
        $sameDay = $got === $want
            && array_map(static fn (int $i): string => $keyOf[$i], $day['no_pa']) === array_map([PaDistributionEngine::class, 'normalizeName'], $expectedSemPa[$date])
            && $day['moves'] === $expectedMoves[$date];
        if (!$sameDay) {
            assert_same(['mes ' . $c, $want, $expectedSemPa[$date], $expectedMoves[$date]], ['mes ' . $c, $got, $day['no_pa'], $day['moves']], "porta do script x motor, {$date}");
        }
        $totals['dias']++;
        $totals[$day['method']] = ($totals[$day['method']] ?? 0) + 1;
        $totals['sem_pa'] += count($day['no_pa']);
        $totals['trocas'] += $day['moves'];
    }
}
assert_same(true, $totals['dias'] > 20000 && $totals['dp'] > 5000 && $totals['greedy'] > 3000 && $totals['sem_pa'] > 1000 && $totals['trocas'] > 1000, 'porta x motor: a amostra cobre programacao dinamica, guloso, sem PA e trocas (' . json_encode($totals) . ')');

// ----------------------------------------------------------------------------
// 3. Motor puro e sem chamada em producao (rollback do F10-1 = reverter)
// ----------------------------------------------------------------------------
$source = (string)file_get_contents($root . '/services/PaDistributionEngine.php');
$code = implode('', array_map(static fn ($t): string => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t), token_get_all($source)));
assert_same(0, preg_match('/\b(PDO|mysqli|db\(|fopen|file_get_contents|file_put_contents|time\(|microtime|date\(|rand|random_|getenv|\$_(GET|POST|SERVER|SESSION|COOKIE|ENV)|require|include|new DateTime\(|\'now\'|echo|print|exit|error_log)/i', $code), 'motor puro: sem banco, arquivo, relogio, aleatorio, ambiente, saida ou include');
$callers = [];
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file): bool {
    return !in_array($file->getFilename(), ['.git', 'node_modules', 'dist', '_referencias', 'vendor'], true);
}));
foreach ($iterator as $file) {
    if (!preg_match('/\.(php|js|jsx|ts|tsx|mjs|sh|ps1|htaccess)$/', $file->getFilename())) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (in_array($relative, ['services/PaDistributionEngine.php', 'scripts/qa-pa-distribution.php', 'scripts/paridade-escala-mensal.php', 'scripts/qa-paridade-escala.php'], true)) {
        continue;
    }
    if (strpos((string)file_get_contents($file->getPathname()), 'PaDistributionEngine') !== false) {
        $callers[] = $relative;
    }
}
assert_same([], $callers, 'F10-1: o motor so e chamado pelo QA e pela paridade (nada em producao)');

echo "QA PA distribution OK ({$checks} verificacoes; porta do script x motor em {$months} meses, {$totals['dias']} dias)\n";
