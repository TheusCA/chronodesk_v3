<?php
declare(strict_types=1);

// ============================================================================
// QA do almoco por contrato (Parte B, F8, commit 1; desenho, secao 9.10)
//
// Regra pura (EmployeeLunchPolicy): fim pela duracao, almoco dentro da jornada,
// decisao do fim (contrato, excecao, sem contrato, campo ausente), permissao da
// marca, motivo em lista fechada e eventos de auditoria sem texto livre.
// Sem banco e sem duracoes reais: contratos e minutos aqui sao ficticios
// (AD1 da Lideranca ainda A DEFINIR).
// ============================================================================

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

require_once $root . '/services/EmployeeLunchPolicy.php';

// Contratos ficticios: duracoes de proposito "estranhas", para nao parecer dado real.
const QA_CONTRACTS = [
    7 => ['ativo' => true, 'almoco_minutos' => 37],
    8 => ['ativo' => true, 'almoco_minutos' => 75],
    9 => ['ativo' => false, 'almoco_minutos' => 50],
    10 => ['ativo' => true, 'almoco_minutos' => 600],
];
const QA_REASONS = ['motivo_qa_a', 'motivo_qa_b'];

function qa_input(array $overrides = []): array {
    return array_merge(['jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00'], $overrides);
}
function qa_current(?int $contract = null, bool $exception = false, ?string $reason = null): array {
    return ['contrato_id' => $contract, 'almoco_excecao' => $exception, 'almoco_excecao_motivo' => $reason];
}
function qa_decide(array $input, ?array $current = null, bool $can = true, ?array $reasons = null, array $contracts = QA_CONTRACTS): array {
    return EmployeeLunchPolicy::decide($input, $current, $contracts, $can, $reasons);
}
function qa_refusal(array $result): array {
    return [$result['ok'], $result['status'] ?? null, $result['campo'] ?? null, $result['mensagem'] ?? null];
}
function qa_values(array $result): array {
    assert_same(true, $result['ok'], 'resultado aceito antes de ler os valores' . ($result['ok'] ? '' : ' (' . ($result['mensagem'] ?? '') . ')'));
    return $result['valores'];
}
function qa_actions(array $result): array {
    return array_map(static fn (array $event) => $event['acao'], $result['eventos'] ?? []);
}
function qa_exception_of(callable $fn): ?string {
    try {
        $fn();
    } catch (Throwable $error) {
        return get_class($error) . ': ' . $error->getMessage();
    }
    return null;
}

// ----------------------------------------------------------------------------
// 1. Horarios: formato, fim pela duracao e dentro da jornada
// ----------------------------------------------------------------------------
foreach ([['00:00', 0], ['08:05', 485], ['23:59', 1439], ['12:30:45', 750]] as [$text, $minutes]) {
    assert_same($minutes, EmployeeLunchPolicy::minutes($text), "minutos de {$text}");
}
foreach (['24:00', '7:00', '12:60', '12:00:60', '', ' 12:00', '12:00 ', 'x', null, 720, true] as $bad) {
    assert_same(null, EmployeeLunchPolicy::minutes($bad), 'formato recusado: ' . var_export($bad, true));
}
assert_same('09:05', EmployeeLunchPolicy::format(545), 'formato com zero a esquerda');
assert_same('12:37', EmployeeLunchPolicy::endFor('12:00', 37), 'fim pela duracao');
assert_same('13:15', EmployeeLunchPolicy::endFor('12:00', 75), 'fim que passa da hora cheia');
assert_same('23:59', EmployeeLunchPolicy::endFor('23:00', 59), 'fim no ultimo minuto do dia');
assert_same(null, EmployeeLunchPolicy::endFor('23:00', 60), 'fim na meia-noite: recusado');
assert_same(null, EmployeeLunchPolicy::endFor('23:30', 45), 'fim depois da meia-noite: recusado');
assert_same(null, EmployeeLunchPolicy::endFor('12:00', 0), 'duracao zero: recusada');
assert_same(null, EmployeeLunchPolicy::endFor('xx', 30), 'inicio invalido: sem fim');

foreach ([
    ['08:00', '17:00', '12:00', '13:00', null, 'meio do dia'],
    ['08:00', '17:00', '08:00', '09:00', null, 'comeca na entrada'],
    ['08:00', '17:00', '16:00', '17:00', null, 'termina na saida'],
    ['08:00', '17:00', '07:59', '09:00', ['almoco_inicio', EmployeeLunchPolicy::MSG_START_OUTSIDE], 'comeca antes da entrada'],
    ['08:00', '17:00', '17:00', '17:30', ['almoco_inicio', EmployeeLunchPolicy::MSG_START_OUTSIDE], 'comeca na saida'],
    ['08:00', '17:00', '16:30', '17:01', ['almoco_fim', EmployeeLunchPolicy::MSG_END_OUTSIDE], 'termina depois da saida'],
    ['08:00', '17:00', '12:00', '12:00', ['almoco_fim', EmployeeLunchPolicy::MSG_START_BEFORE_END], 'inicio igual ao fim'],
    ['08:00', '17:00', '13:00', '12:00', ['almoco_fim', EmployeeLunchPolicy::MSG_START_BEFORE_END], 'inicio depois do fim'],
    ['17:00', '08:00', '12:00', '13:00', ['jornada_entrada', EmployeeLunchPolicy::MSG_SHIFT], 'jornada invertida'],
    ['08:00', '08:00', '08:00', '08:00', ['jornada_entrada', EmployeeLunchPolicy::MSG_SHIFT], 'jornada vazia'],
    ['08:00', '17:00', 'x', '13:00', ['almoco_inicio', EmployeeLunchPolicy::MSG_START], 'inicio invalido'],
    ['08:00', '17:00', '12:00', 'x', ['almoco_fim', EmployeeLunchPolicy::MSG_END], 'fim invalido'],
] as [$in, $out, $start, $end, $expected, $label]) {
    assert_same($expected, EmployeeLunchPolicy::withinShift($in, $out, $start, $end), "dentro da jornada: {$label}");
}

// ----------------------------------------------------------------------------
// 2. Fim: contrato sem excecao calcula; excecao e sem contrato usam o enviado
// ----------------------------------------------------------------------------
$created = qa_decide(qa_input(['contrato_id' => 7, 'almoco_fim' => '15:00']));
assert_same(['contrato_id' => 7, 'almoco_excecao' => false, 'almoco_excecao_motivo' => null, 'almoco_inicio' => '12:00', 'almoco_fim' => '12:37'], qa_values($created), 'novo com contrato: fim calculado, o enviado e ignorado (DL5)');
assert_same(true, $created['fim_calculado'], 'novo com contrato: fim_calculado');
assert_same(['FUNCIONARIO_CONTRATO_ALTERADO'], qa_actions($created), 'novo com contrato: evento do contrato');
assert_same('12:37', qa_values(qa_decide(qa_input(['contrato_id' => 7, 'almoco_fim' => null])))['almoco_fim'], 'contrato: fim ausente nao importa');
assert_same('12:37', qa_values(qa_decide(qa_input(['contrato_id' => '7'])))['almoco_fim'], 'contrato_id como texto de digitos');
assert_same('12:30', qa_values(qa_decide(qa_input(['almoco_inicio' => '11:15:30', 'contrato_id' => 8])))['almoco_fim'], 'inicio com segundos: normalizado e somado');
assert_same('11:15', qa_values(qa_decide(qa_input(['almoco_inicio' => '11:15:30', 'contrato_id' => 8])))['almoco_inicio'], 'inicio gravado sem segundos');

$manual = qa_decide(qa_input(['almoco_fim' => '13:20:10']));
assert_same(['contrato_id' => null, 'almoco_excecao' => false, 'almoco_excecao_motivo' => null, 'almoco_inicio' => '12:00', 'almoco_fim' => '13:20'], qa_values($manual), 'novo sem contrato: fim manual (decisao 3)');
assert_same(false, $manual['fim_calculado'], 'sem contrato: fim_calculado falso');
assert_same([], qa_actions($manual), 'novo sem contrato e sem excecao: nenhum evento');

$exception = qa_decide(qa_input(['contrato_id' => 7, 'almoco_excecao' => true, 'almoco_fim' => '13:30']));
assert_same('13:30', qa_values($exception)['almoco_fim'], 'excecao: fim manual mesmo com contrato');
assert_same(false, $exception['fim_calculado'], 'excecao: fim_calculado falso');
assert_same(['FUNCIONARIO_CONTRATO_ALTERADO', 'FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO'], qa_actions($exception), 'novo com contrato e excecao: dois eventos');

assert_same([false, 400, 'almoco_fim', EmployeeLunchPolicy::MSG_END_MISSING], qa_refusal(qa_decide(qa_input(['almoco_fim' => null]))), 'sem contrato e sem fim: 400');
assert_same([false, 400, 'almoco_fim', EmployeeLunchPolicy::MSG_END_MISSING], qa_refusal(qa_decide(array_diff_key(qa_input(), ['almoco_fim' => 1]))), 'sem contrato e fim ausente: 400');
assert_same([false, 400, 'almoco_fim', EmployeeLunchPolicy::MSG_END], qa_refusal(qa_decide(qa_input(['almoco_fim' => '13h']))), 'fim manual fora do formato: 400');
assert_same([false, 400, 'almoco_fim', EmployeeLunchPolicy::MSG_END_MISSING], qa_refusal(qa_decide(qa_input(['contrato_id' => 7, 'almoco_excecao' => true, 'almoco_fim' => '']))), 'excecao sem fim: 400');

// Fim calculado tambem passa pela jornada e pela meia-noite.
assert_same([false, 400, 'almoco_fim', EmployeeLunchPolicy::MSG_END_OUTSIDE], qa_refusal(qa_decide(qa_input(['almoco_inicio' => '16:30', 'contrato_id' => 7]))), 'fim calculado depois da saida: 400');
assert_same('17:00', qa_values(qa_decide(qa_input(['almoco_inicio' => '15:45', 'contrato_id' => 8])))['almoco_fim'], 'fim calculado exatamente na saida: aceito');
assert_same([false, 400, 'almoco_inicio', EmployeeLunchPolicy::MSG_PAST_MIDNIGHT], qa_refusal(qa_decide(qa_input(['jornada_saida' => '23:59', 'almoco_inicio' => '23:30', 'contrato_id' => 7]))), 'fim calculado depois da meia-noite: 400');
assert_same([false, 400, 'almoco_fim', EmployeeLunchPolicy::MSG_END_OUTSIDE], qa_refusal(qa_decide(qa_input(['contrato_id' => 10]))), 'duracao maior que a jornada: fora da jornada');
assert_same([false, 409, 'contrato_id', EmployeeLunchPolicy::MSG_CONTRACT_DURATION], qa_refusal(qa_decide(qa_input(['contrato_id' => 7]), null, true, null, [7 => ['ativo' => true, 'almoco_minutos' => 0]])), 'duracao zero gravada: 409');
assert_same([false, 409, 'contrato_id', EmployeeLunchPolicy::MSG_CONTRACT_DURATION], qa_refusal(qa_decide(qa_input(['contrato_id' => 7]), null, true, null, [7 => ['ativo' => true, 'almoco_minutos' => 1440]])), 'duracao de um dia inteiro: 409');

// Jornada e inicio do pedido.
assert_same([false, 400, 'jornada_entrada', EmployeeLunchPolicy::MSG_SHIFT], qa_refusal(qa_decide(qa_input(['jornada_entrada' => '18:00']))), 'jornada invertida: 400');
assert_same([false, 400, 'jornada_entrada', EmployeeLunchPolicy::MSG_SHIFT], qa_refusal(qa_decide(qa_input(['jornada_saida' => null]))), 'jornada sem saida: 400');
// Ordem das recusas: a jornada vem antes do contrato, da marca e do fim.
assert_same([false, 400, 'jornada_entrada', EmployeeLunchPolicy::MSG_SHIFT], qa_refusal(qa_decide(qa_input(['jornada_entrada' => '18:00', 'contrato_id' => 99]))), 'jornada invertida e contrato invalido: a jornada primeiro');
assert_same([false, 400, 'jornada_entrada', EmployeeLunchPolicy::MSG_SHIFT], qa_refusal(qa_decide(qa_input(['jornada_entrada' => '18:00', 'almoco_fim' => null]))), 'jornada invertida e sem fim: a jornada primeiro');
assert_same([false, 400, 'jornada_entrada', EmployeeLunchPolicy::MSG_SHIFT], qa_refusal(qa_decide(qa_input(['jornada_entrada' => '18:00', 'almoco_excecao' => true]), null, false)), 'jornada invertida e sem permissao: a jornada primeiro, sem negacao');
assert_same([false, 400, 'almoco_inicio', EmployeeLunchPolicy::MSG_START], qa_refusal(qa_decide(qa_input(['almoco_inicio' => '25:00']))), 'inicio invalido: 400');
assert_same([false, 400, 'almoco_inicio', EmployeeLunchPolicy::MSG_START_OUTSIDE], qa_refusal(qa_decide(qa_input(['almoco_inicio' => '07:00', 'almoco_fim' => '08:00']))), 'almoco antes da jornada: 400');

// ----------------------------------------------------------------------------
// 3. Contrato: ausente mantem, null tira, inativo so se ja for o atual (DL4, DL12)
// ----------------------------------------------------------------------------
$kept = qa_decide(qa_input(['almoco_fim' => '19:00']), qa_current(8));
assert_same(['contrato_id' => 8, 'almoco_fim' => '13:15'], array_intersect_key(qa_values($kept), ['contrato_id' => 1, 'almoco_fim' => 1]), 'campo ausente: mantem o contrato e recalcula (tela legada)');
assert_same([], qa_actions($kept), 'campo ausente: nenhum evento');
$removed = qa_decide(qa_input(['contrato_id' => null, 'almoco_fim' => '13:10']), qa_current(8));
assert_same([null, '13:10'], [qa_values($removed)['contrato_id'], qa_values($removed)['almoco_fim']], 'contrato null: tira e volta ao fim manual');
assert_same([['acao' => 'FUNCIONARIO_CONTRATO_ALTERADO', 'severidade' => 'INFO', 'antes' => 8, 'depois' => null]], $removed['eventos'], 'tirar o contrato: evento com antes e depois');
assert_same(null, qa_values(qa_decide(qa_input(['contrato_id' => '']), qa_current(8)))['contrato_id'], 'contrato vazio: tira');
$changed = qa_decide(qa_input(['contrato_id' => 7]), qa_current(8));
assert_same([['acao' => 'FUNCIONARIO_CONTRATO_ALTERADO', 'severidade' => 'INFO', 'antes' => 8, 'depois' => 7]], $changed['eventos'], 'trocar de contrato: evento');
assert_same('12:37', qa_values($changed)['almoco_fim'], 'trocar de contrato: fim pela duracao nova');
assert_same([], qa_actions(qa_decide(qa_input(['contrato_id' => 8]), qa_current(8))), 'mesmo contrato: nenhum evento');

assert_same([false, 400, 'contrato_id', EmployeeLunchPolicy::MSG_CONTRACT_INACTIVE], qa_refusal(qa_decide(qa_input(['contrato_id' => 9]))), 'novo com contrato inativo: 400');
assert_same([false, 400, 'contrato_id', EmployeeLunchPolicy::MSG_CONTRACT_INACTIVE], qa_refusal(qa_decide(qa_input(['contrato_id' => 9]), qa_current(8))), 'trocar para contrato inativo: 400');
assert_same('12:50', qa_values(qa_decide(qa_input(['contrato_id' => 9]), qa_current(9)))['almoco_fim'], 'contrato inativo atual: continua calculando (DL4)');
assert_same('12:50', qa_values(qa_decide(qa_input(), qa_current(9)))['almoco_fim'], 'contrato inativo atual, campo ausente: continua calculando');
foreach ([99, '99', 0, -7, '07', '7.0', 7.0, true, 'sete', ['7']] as $bad) {
    assert_same([false, 400, 'contrato_id', EmployeeLunchPolicy::MSG_CONTRACT], qa_refusal(qa_decide(qa_input(['contrato_id' => $bad]))), 'contrato recusado: ' . json_encode($bad));
}

// ----------------------------------------------------------------------------
// 4. Excecao: booleano estrito, ausente mantem, permissao propria (DL8, DL9)
// ----------------------------------------------------------------------------
foreach ([[true, true], [1, true], ['1', true], [false, false], [0, false], ['0', false]] as [$raw, $expected]) {
    assert_same($expected, qa_values(qa_decide(qa_input(['almoco_excecao' => $raw]), qa_current(null, !$expected)))['almoco_excecao'], 'excecao aceita: ' . json_encode($raw));
}
foreach (['true', 'sim', 2, null, '', [], 1.0] as $bad) {
    assert_same([false, 400, 'almoco_excecao', EmployeeLunchPolicy::MSG_EXCEPTION], qa_refusal(qa_decide(qa_input(['almoco_excecao' => $bad]))), 'excecao recusada: ' . json_encode($bad));
}
assert_same(true, qa_values(qa_decide(qa_input(['almoco_fim' => '13:40']), qa_current(7, true)))['almoco_excecao'], 'excecao ausente: mantem');
assert_same('13:40', qa_values(qa_decide(qa_input(['almoco_fim' => '13:40']), qa_current(7, true)))['almoco_fim'], 'excecao mantida: fim manual');

$unmark = qa_decide(qa_input(['almoco_excecao' => false, 'almoco_fim' => '13:40']), qa_current(7, true));
assert_same(['12:37', true], [qa_values($unmark)['almoco_fim'], $unmark['fim_calculado']], 'desmarcar com contrato: fim recalculado (DL9)');
assert_same(['FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO'], qa_actions($unmark), 'desmarcar: evento da excecao');
assert_same('13:40', qa_values(qa_decide(qa_input(['almoco_excecao' => false, 'almoco_fim' => '13:40']), qa_current(null, true)))['almoco_fim'], 'desmarcar sem contrato: fim manual');
$mark = qa_decide(qa_input(['almoco_excecao' => true, 'almoco_fim' => '13:40']), qa_current(7));
assert_same(['13:40', false], [qa_values($mark)['almoco_fim'], $mark['fim_calculado']], 'marcar: passa a valer o fim enviado (DL9)');

// Sem a permissao: mudar a marca da 403 com o evento de negacao; manter nao.
$denied = qa_decide(qa_input(['almoco_excecao' => true, 'almoco_fim' => '13:40']), qa_current(7), false);
assert_same([false, 403, 'almoco_excecao', EmployeeLunchPolicy::MSG_EXCEPTION_DENIED], qa_refusal($denied), 'sem permissao, marcar: 403');
assert_same(['acao' => 'FUNCIONARIO_ALTERACAO_NEGADA', 'severidade' => 'WARNING', 'contexto' => 'almoco_excecao'], $denied['negacao'], 'sem permissao: evento de negacao');
assert_same([false, 403], array_slice(qa_refusal(qa_decide(qa_input(['almoco_excecao' => false]), qa_current(7, true), false)), 0, 2), 'sem permissao, desmarcar: 403');
assert_same([false, 403], array_slice(qa_refusal(qa_decide(qa_input(['almoco_excecao' => true, 'almoco_fim' => '13:40']), null, false)), 0, 2), 'sem permissao, novo com excecao: 403');
assert_same(true, qa_decide(qa_input(['almoco_excecao' => true, 'almoco_fim' => '13:40']), qa_current(7, true), false)['ok'], 'sem permissao, marca igual: aceito');
assert_same(true, qa_decide(qa_input(['almoco_excecao' => false, 'contrato_id' => 8]), qa_current(7), false)['ok'], 'sem permissao, so trocar o contrato: aceito');
assert_same(true, qa_decide(qa_input(['almoco_fim' => '13:40']), qa_current(7, true), false)['ok'], 'sem permissao, campo ausente: aceito');
assert_same(null, qa_decide(qa_input(['contrato_id' => 99]), null, false)['negacao'], 'recusa comum: sem evento de negacao');

// ----------------------------------------------------------------------------
// 5. Motivo: so com lista fechada (AD2 = sim); obrigatorio com a excecao
// ----------------------------------------------------------------------------
assert_same([false, 400, 'almoco_excecao_motivo', EmployeeLunchPolicy::MSG_REASON], qa_refusal(qa_decide(qa_input(['almoco_excecao' => true, 'almoco_excecao_motivo' => 'motivo_qa_a', 'almoco_fim' => '13:40']))), 'sem lista (AD2 = nao): motivo enviado recusado');
assert_same(null, qa_values(qa_decide(qa_input(['almoco_excecao' => true, 'almoco_excecao_motivo' => null, 'almoco_fim' => '13:40'])))['almoco_excecao_motivo'], 'sem lista: motivo nulo aceito');
assert_same(null, qa_values(qa_decide(qa_input(['almoco_excecao' => true, 'almoco_fim' => '13:40'])))['almoco_excecao_motivo'], 'sem lista: excecao sem motivo');

$withReason = qa_decide(qa_input(['almoco_excecao' => true, 'almoco_excecao_motivo' => 'motivo_qa_a', 'almoco_fim' => '13:40']), qa_current(7), true, QA_REASONS);
assert_same('motivo_qa_a', qa_values($withReason)['almoco_excecao_motivo'], 'com lista: motivo gravado');
assert_same([false, 400, 'almoco_excecao_motivo', EmployeeLunchPolicy::MSG_REASON_MISSING], qa_refusal(qa_decide(qa_input(['almoco_excecao' => true, 'almoco_fim' => '13:40']), qa_current(7), true, QA_REASONS)), 'com lista: excecao sem motivo, 400');
assert_same([false, 400, 'almoco_excecao_motivo', EmployeeLunchPolicy::MSG_REASON_MISSING], qa_refusal(qa_decide(qa_input(['almoco_excecao' => true, 'almoco_excecao_motivo' => '', 'almoco_fim' => '13:40']), qa_current(7), true, QA_REASONS)), 'com lista: motivo vazio, 400');
foreach (['Motivo QA A', 'motivo_qa_c', 'MOTIVO_QA_A', 1, ['motivo_qa_a']] as $bad) {
    assert_same([false, 400, 'almoco_excecao_motivo', EmployeeLunchPolicy::MSG_REASON], qa_refusal(qa_decide(qa_input(['almoco_excecao' => true, 'almoco_excecao_motivo' => $bad, 'almoco_fim' => '13:40']), null, true, QA_REASONS)), 'motivo fora da lista: ' . json_encode($bad));
}
assert_same('motivo_qa_b', qa_values(qa_decide(qa_input(['almoco_fim' => '13:40']), qa_current(7, true, 'motivo_qa_b'), true, QA_REASONS))['almoco_excecao_motivo'], 'motivo ausente com a excecao mantida: mantem');
assert_same(null, qa_values(qa_decide(qa_input(['almoco_excecao' => false, 'almoco_excecao_motivo' => 'motivo_qa_b']), qa_current(7, true, 'motivo_qa_b'), true, QA_REASONS))['almoco_excecao_motivo'], 'desmarcar: motivo vira nulo');
$reasonChange = qa_decide(qa_input(['almoco_excecao_motivo' => 'motivo_qa_a', 'almoco_fim' => '13:40']), qa_current(7, true, 'motivo_qa_b'), true, QA_REASONS);
assert_same(['FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO'], qa_actions($reasonChange), 'trocar so o motivo: evento da excecao');
assert_same(['motivo_qa_b', 'motivo_qa_a'], [$reasonChange['eventos'][0]['motivo_antes'], $reasonChange['eventos'][0]['motivo_depois']], 'trocar o motivo: antes e depois');
assert_same([false, 403], array_slice(qa_refusal(qa_decide(qa_input(['almoco_excecao_motivo' => 'motivo_qa_a', 'almoco_fim' => '13:40']), qa_current(7, true, 'motivo_qa_b'), false, QA_REASONS)), 0, 2), 'sem permissao, trocar o motivo: 403');
assert_same(true, qa_decide(qa_input(['almoco_excecao_motivo' => 'motivo_qa_b', 'almoco_fim' => '13:40']), qa_current(7, true, 'motivo_qa_b'), false, QA_REASONS)['ok'], 'sem permissao, motivo igual: aceito');

// ----------------------------------------------------------------------------
// 6. Auditoria: so IDs, perfil, horarios e codigos; sem nome nem texto livre
// ----------------------------------------------------------------------------
$events = qa_decide(qa_input(['contrato_id' => 8, 'almoco_excecao' => true, 'almoco_excecao_motivo' => 'motivo_qa_a', 'almoco_fim' => '13:40']), qa_current(7), true, QA_REASONS)['eventos'];
assert_same([
    ['FUNCIONARIO_CONTRATO_ALTERADO', 'Contrato do funcionario ID 41 alterado de ID 7 para ID 8 por lideranca', 'INFO'],
    ['FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO', 'Excecao de almoco do funcionario ID 41 alterada de nao para sim por lideranca; almoco 12:00-13:40; motivo nenhum para motivo_qa_a', 'WARNING'],
], EmployeeLunchPolicy::auditMessages($events, 41, 'lideranca'), 'auditoria: mensagens dos dois eventos');
assert_same([['FUNCIONARIO_CONTRATO_ALTERADO', 'Contrato do funcionario ID 5 alterado de nenhum para ID 7 por admin', 'INFO']], EmployeeLunchPolicy::auditMessages(qa_decide(qa_input(['contrato_id' => 7]))['eventos'], 5, 'admin'), 'auditoria: contrato novo');
assert_same([['FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO', 'Excecao de almoco do funcionario ID 5 alterada de sim para nao por admin; almoco 12:00-12:37', 'WARNING']], EmployeeLunchPolicy::auditMessages($unmark['eventos'], 5, 'admin'), 'auditoria: excecao sem lista, sem motivo na mensagem');
assert_same([['FUNCIONARIO_ALTERACAO_NEGADA', 'contexto=almoco_excecao perfil_ator=gestor funcionario_id=5', 'WARNING']], EmployeeLunchPolicy::auditMessages([$denied['negacao']], 5, 'gestor'), 'auditoria: negacao no formato do fora do Mapa');
assert_same('contexto=almoco_excecao perfil_ator=desconhecido funcionario_id=5', EmployeeLunchPolicy::auditMessages([$denied['negacao']], 5, "admin'; DROP")[0][1], 'auditoria: perfil fora do formato vira desconhecido');
assert_same(UnexpectedValueException::class . ': Evento de auditoria fora da lista do almoco.', qa_exception_of(static fn () => EmployeeLunchPolicy::auditMessages([['acao' => 'OUTRO', 'severidade' => 'INFO']], 5, 'admin')), 'auditoria: evento desconhecido recusado');
$marker = '[QA_MARCADOR_TEXTO_LIVRE]';
$all = json_encode(EmployeeLunchPolicy::auditMessages(qa_decide(qa_input(['contrato_id' => 8, 'almoco_excecao' => true, 'almoco_fim' => '13:40', 'nome' => $marker, 'observacao' => $marker]), qa_current(7))['eventos'], 41, 'admin'));
assert_same(false, strpos((string)$all, 'QA_MARCADOR') !== false, 'auditoria: campo livre do pedido nunca entra');

// ----------------------------------------------------------------------------
// 7. Dado de quem chama fora do formato: excecao, antes de decidir
// ----------------------------------------------------------------------------
foreach ([
    [qa_current(0), 'Contrato gravado fora do formato.', 'contrato gravado zero'],
    [['contrato_id' => '7', 'almoco_excecao' => false, 'almoco_excecao_motivo' => null], 'Contrato gravado fora do formato.', 'contrato gravado como texto'],
    [['contrato_id' => null, 'almoco_excecao' => 0, 'almoco_excecao_motivo' => null], 'Marca de excecao gravada fora do formato.', 'marca gravada como inteiro'],
    [['contrato_id' => null, 'almoco_excecao_motivo' => null], 'Marca de excecao gravada fora do formato.', 'marca gravada ausente'],
    [['contrato_id' => null, 'almoco_excecao' => true, 'almoco_excecao_motivo' => 3], 'Motivo gravado fora do formato.', 'motivo gravado inteiro'],
] as [$current, $message, $label]) {
    assert_same(UnexpectedValueException::class . ': ' . $message, qa_exception_of(static fn () => qa_decide(qa_input(), $current)), "estado gravado: {$label}");
}
foreach ([
    [['7' => ['ativo' => true, 'almoco_minutos' => 30]], 'chave como texto de digitos vira inteiro no PHP', null],
    [[0 => ['ativo' => true, 'almoco_minutos' => 30]], 'id zero', 'Contrato carregado fora do formato.'],
    [['x' => ['ativo' => true, 'almoco_minutos' => 30]], 'id texto', 'Contrato carregado fora do formato.'],
    [[7 => ['ativo' => 1, 'almoco_minutos' => 30]], 'ativo inteiro', 'Contrato carregado fora do formato.'],
    [[7 => ['ativo' => true, 'almoco_minutos' => '30']], 'minutos texto', 'Contrato carregado fora do formato.'],
    [[7 => ['ativo' => true]], 'sem minutos', 'Contrato carregado fora do formato.'],
] as [$contracts, $label, $message]) {
    assert_same($message === null ? null : UnexpectedValueException::class . ': ' . $message, qa_exception_of(static fn () => qa_decide(qa_input(), null, true, null, $contracts)), "contratos carregados: {$label}");
}
foreach ([[['Motivo Livre']], [['']], [[1]], [['a' . str_repeat('b', 40)]]] as [$list]) {
    assert_same(UnexpectedValueException::class . ': Lista de motivos fora do formato.', qa_exception_of(static fn () => qa_decide(qa_input(), null, true, $list)), 'lista de motivos recusada: ' . json_encode($list));
}

// ----------------------------------------------------------------------------
// 8. Regra pura e sem duracoes reais; runners
// ----------------------------------------------------------------------------
$source = (string)file_get_contents($root . '/services/EmployeeLunchPolicy.php');
foreach (['PDO', 'get_db_connection', 'file_get_contents', 'fopen', 'time(', 'date(', 'DateTime', '$_', 'getenv', 'audit_log', 'require'] as $forbidden) {
    assert_same(false, strpos($source, $forbidden) !== false, "regra pura: sem {$forbidden}");
}
assert_same(0, preg_match("/'almoco_minutos'\s*=>\s*\d/", $source), 'regra pura: nenhuma duracao de contrato no codigo');
assert_same(0, preg_match('/[\x{00C0}-\x{00FF}]/u', implode('', array_map(static fn ($m) => $m[1], EmployeeLunchPolicy::auditMessages($events, 41, 'admin')))), 'auditoria sem acento, como as demais');
foreach (['scripts/test-linux.sh', 'scripts/test-local.ps1'] as $runner) {
    assert_same(true, strpos((string)file_get_contents($root . '/' . $runner), 'qa-employee-lunch.php') !== false, "{$runner} roda o qa-employee-lunch");
}

echo "QA employee lunch OK ({$checks} verificacoes)\n";
