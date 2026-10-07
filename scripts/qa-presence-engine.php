<?php
declare(strict_types=1);

// ============================================================================
// QA do motor de presenca (Parte B, F2; desenho, secoes 3.2, 4 e 9)
//
// Sem banco:
// 1. PresenceEngine (funcoes puras): ciclo (criterio de aceite da secao 4.2 e
//    forma fechada contra a contagem dia a dia), escala do dia (P3, P4, P12,
//    excecoes), precedencia por instante (secao 4.3), bordas (secao 4.4) e a
//    identidade da soma dos contadores (secao 4.5);
// 2. PresenceRepository contra um PDO espiao: 4 consultas para 1 e para 50
//    colaboradores, sem NOW()/CURRENT_DATE e sem observacao ou motivo;
// 3. PresenceService: validacao do GET portal/presence.php, filtros, pausas
//    indisponiveis, presenca no status.php e o interruptor
//    STATUS_PRESENCE_ENABLED.
// As consultas SQL nao rodam em MySQL aqui (sem MySQL local).
//
// Uso: php scripts/qa-presence-engine.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-presence-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.251';
define('CHRONODESK_STATELESS', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/PresenceService.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

$checks = 0;
function assert_same($expected, $actual, string $message): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nesperado: " . var_export($expected, true) . "\nobtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_throws(string $class, callable $callback, string $message): void {
    try {
        $callback();
    } catch (Throwable $error) {
        assert_same($class, get_class($error), $message);
        return;
    }
    assert_same($class, 'nenhuma excecao', $message);
}

function at(string $dateTime): DateTimeImmutable {
    return new DateTimeImmutable($dateTime, new DateTimeZone('America/Sao_Paulo'));
}

function employee(array $overrides = []): array {
    return $overrides + [
        'id' => 1, 'name' => 'QA', 'team' => 'n1',
        'work_start' => '08:00:00', 'work_end' => '17:00:00',
        'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00', 'outside_map' => false,
    ];
}

function rule(string $type, ?array $config = null, string $from = '2026-01-01', ?string $until = null, int $id = 1): array {
    return [
        'id' => $id, 'rule_type' => $type, 'rule_config' => $config === null ? null : json_encode($config),
        'effective_from' => $from, 'effective_until' => $until,
    ];
}

function situation(array $employee, string $dateTime, array $rules, ?array $exception = null, array $absences = [], ?array $pause = null): array {
    $presence = PresenceEngine::nowPresence($employee, at($dateTime), $rules, $exception, $absences, $pause);
    return [$presence['situacao'], $presence['origem'], $presence['detalhe']];
}

// ============================================================================
// 1. Ciclo
// ============================================================================
$cycle = rule('cycle', ['pattern' => ['remote', 'onsite', 'onsite'], 'anchor_date' => '2026-10-05', 'business_days_only' => true], '2026-10-05');
// Criterio de aceite da secao 4.2 (1R/2P, ancora na segunda 05/10/2026).
$expected = [
    '05' => 'R', '06' => 'P', '07' => 'P', '08' => 'R', '09' => 'P',
    '12' => 'P', '13' => 'R', '14' => 'P', '15' => 'P', '16' => 'R',
    '19' => 'P', '20' => 'P', '21' => 'R', '22' => 'P', '23' => 'P',
];
$got = [];
foreach (array_keys($expected) as $day) {
    $schedule = PresenceEngine::scheduleForDay(employee(), "2026-10-{$day}", [$cycle], null);
    $got[$day] = $schedule['situacao'] === 'presencial' ? 'P' : ($schedule['situacao'] === 'remoto' ? 'R' : $schedule['situacao']);
}
assert_same($expected, $got, 'ciclo 1R/2P: tres semanas do criterio de aceite');
assert_same('dia_nao_util', PresenceEngine::scheduleForDay(employee(), '2026-10-10', [$cycle], null)['situacao'], 'ciclo: sabado e dia nao util e nao consome posicao');
assert_same('Ciclo 2P/1R', PresenceEngine::scheduleForDay(employee(), '2026-10-06', [$cycle], null)['rule_label'], 'ciclo: rotulo 2P/1R');
assert_same(['presencial', 'regra_escala', 'Presencial (ciclo 2P/1R)'], situation(employee(), '2026-10-06 10:00', [$cycle]), 'ciclo: detalhe da situacao');

// Forma fechada contra a contagem dia a dia, nos dois sentidos.
mt_srand(20261005);
$base = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
$mismatches = 0;
for ($i = 0; $i < 4000; $i++) {
    $a = $base->modify('+' . mt_rand(0, 900) . ' days');
    $b = $base->modify('+' . mt_rand(0, 900) . ' days');
    [$lo, $hi] = $a <= $b ? [$a, $b] : [$b, $a];
    $count = 0;
    for ($d = $lo; $d < $hi; $d = $d->modify('+1 day')) {
        $count += (int)$d->format('N') <= 5 ? 1 : 0;
    }
    $count = $a <= $b ? $count : -$count;
    if (PresenceEngine::businessDaysBetween($a->format('Y-m-d'), $b->format('Y-m-d')) !== $count) {
        $mismatches++;
    }
}
assert_same(0, $mismatches, 'dias uteis: forma fechada igual a contagem dia a dia em 4.000 pares');
assert_same(2, PresenceEngine::cyclePosition('2026-10-05', '2026-10-02', 3), 'ciclo: data antes da ancora conta para tras sem posicao negativa');
assert_same(0, PresenceEngine::cyclePosition('2026-10-05', '2026-10-05', 3), 'ciclo: ancora e a posicao 0');

foreach ([
    'pattern curto' => ['pattern' => ['onsite'], 'anchor_date' => '2026-10-05'],
    'pattern com valor invalido' => ['pattern' => ['onsite', 'home'], 'anchor_date' => '2026-10-05'],
    'pattern longo' => ['pattern' => array_fill(0, 21, 'onsite'), 'anchor_date' => '2026-10-05'],
    'ancora no sabado' => ['pattern' => ['onsite', 'remote'], 'anchor_date' => '2026-10-10'],
    'ancora invalida' => ['pattern' => ['onsite', 'remote'], 'anchor_date' => '2026-02-30'],
    'sem ancora' => ['pattern' => ['onsite', 'remote']],
] as $case => $config) {
    $schedule = PresenceEngine::scheduleForDay(employee(), '2026-10-06', [rule('cycle', $config)], null);
    assert_same(['sem_escala', 'Ciclo com configuração inválida'], [$schedule['situacao'], $schedule['rule_label']], "ciclo invalido ({$case}): sem escala, sem excecao");
}

// ============================================================================
// 2. Escala do dia (secao 4.2, P3, P4, P12)
// ============================================================================
$monday = '2026-10-05';
$saturday = '2026-10-10';
foreach ([
    ['always_onsite', null, $monday, 'presencial'],
    ['always_remote', null, $monday, 'remoto'],
    ['even_days', null, '2026-10-06', 'presencial'],
    ['even_days', null, '2026-10-07', 'remoto'],
    ['odd_days', null, '2026-10-07', 'presencial'],
    ['odd_days', null, '2026-10-06', 'remoto'],
    ['fixed_weekdays', ['weekdays' => ['mon', 'wed']], $monday, 'presencial'],
    ['fixed_weekdays', ['weekdays' => ['mon', 'wed']], '2026-10-06', 'remoto'],
    ['fixed_weekdays', ['weekdays' => ['tue'], 'input_mode' => 'home'], '2026-10-06', 'presencial'],
    ['undefined', null, $monday, 'sem_escala'],
    ['tipo_desconhecido', null, $monday, 'sem_escala'],
] as [$type, $config, $date, $want]) {
    assert_same($want, PresenceEngine::scheduleForDay(employee(), $date, [rule($type, $config)], null)['situacao'], "regra {$type} em {$date}");
}
assert_same('Presencial: seg e qua', PresenceEngine::scheduleForDay(employee(), $monday, [rule('fixed_weekdays', ['weekdays' => ['wed', 'mon']])], null)['rule_label'], 'dias fixos: rotulo em ordem');
foreach (['always_onsite', 'always_remote', 'even_days', 'odd_days', 'fixed_weekdays', 'undefined'] as $type) {
    assert_same('dia_nao_util', PresenceEngine::scheduleForDay(employee(), $saturday, [rule($type, ['weekdays' => ['mon']])], null)['situacao'], "P3: {$type} no sabado e dia nao util");
}
assert_same('dia_nao_util', PresenceEngine::scheduleForDay(employee(), '2026-10-11', [rule('always_onsite')], null)['situacao'], 'P3: domingo e dia nao util');
assert_same(['sem_escala', 'nenhuma'], array_values(array_intersect_key(PresenceEngine::scheduleForDay(employee(), $monday, [], null), ['situacao' => 1, 'fonte' => 1])), 'P4: sem regra e sem escala');

// Vigencia: cada data usa a regra que vigorava nela; linha vazia nao vigora.
$history = [rule('always_remote', null, '2026-01-01', '2026-10-06', 1), rule('always_onsite', null, '2026-10-07', null, 2)];
assert_same('remoto', PresenceEngine::scheduleForDay(employee(), '2026-10-06', $history, null)['situacao'], 'vigencia: ultimo dia da regra antiga');
assert_same('presencial', PresenceEngine::scheduleForDay(employee(), '2026-10-07', $history, null)['situacao'], 'vigencia: primeiro dia da nova');
assert_same('sem_escala', PresenceEngine::scheduleForDay(employee(), '2026-10-06', [rule('always_onsite', null, '2026-10-07', '2026-10-06')], null)['situacao'], 'vigencia: linha vazia (fim antes do inicio) nao vigora');
assert_same('sem_escala', PresenceEngine::scheduleForDay(employee(), '2026-10-06', [rule('always_onsite', null, '2026-10-07')], null)['situacao'], 'vigencia: regra agendada ainda nao vale');

// Excecoes: trabalho vale ate no fim de semana; ausencia nao muda a escala.
assert_same(['presencial', 'excecao', 'Exceção: plantão'], array_values(array_intersect_key(PresenceEngine::scheduleForDay(employee(), $saturday, [rule('always_remote')], ['exception_type' => 'oncall']), ['situacao' => 1, 'fonte' => 1, 'rule_label' => 1])), 'excecao de plantao no sabado gera o dia');
assert_same('presencial', PresenceEngine::scheduleForDay(employee(), $monday, [rule('always_remote')], ['exception_type' => 'training'])['situacao'], 'excecao de treinamento: presencial');
assert_same('remoto', PresenceEngine::scheduleForDay(employee(), $monday, [], ['exception_type' => 'remote'])['situacao'], 'excecao remota vale mesmo sem regra');
assert_same('presencial', PresenceEngine::scheduleForDay(employee(), $monday, [rule('always_onsite')], ['exception_type' => 'vacation'])['situacao'], 'excecao de ferias nao muda a escala (vira ausencia)');

// Lideranca (P12): implicita, presencial em dia util, ignora linha de regra.
$leader = employee(['id' => 2, 'team' => 'lideranca']);
assert_same(['presencial', 'implicita', 'Regra fixa'], array_values(array_intersect_key(PresenceEngine::scheduleForDay($leader, $monday, [rule('always_remote')], null), ['situacao' => 1, 'fonte' => 1, 'rule_label' => 1])), 'Lideranca: regra fixa presencial, mesmo com linha de regra');
assert_same('dia_nao_util', PresenceEngine::scheduleForDay($leader, $saturday, [], null)['situacao'], 'Lideranca: sabado sem excecao e dia nao util');
assert_same('presencial', PresenceEngine::scheduleForDay($leader, $saturday, [], ['exception_type' => 'onsite'])['situacao'], 'Lideranca: sabado com excecao presencial');
assert_same(['presencial', 'regra_escala', 'Presencial (regra fixa)'], situation($leader, '2026-10-05 10:00', []), 'Lideranca: detalhe Presencial (regra fixa)');

// ============================================================================
// 3. Situacao no instante (secao 4.3) e bordas (secao 4.4)
// ============================================================================
$onsite = [rule('always_onsite')];
$vacation = ['exception_type' => 'vacation'];
$fullDay = [['origem' => 'excecao', 'period_type' => 'full_day']];
$coffee = ['em_pausa' => true, 'motivo' => 'Café'];

assert_same(['ausente', 'ausencia', 'Ausente (dia todo)'], situation(employee(), '2026-10-05 10:00', $onsite, $vacation, $fullDay, $coffee), '1: ausencia vence pausa e escala');
assert_same(['ausente', 'ausencia', 'Ausente (dia todo)'], situation(employee(), '2026-10-05 20:00', $onsite, $vacation, $fullDay), '1: ausencia vence fora do expediente');
assert_same(['ausente', 'ausencia', 'Ausente (dia todo)'], situation(employee(), '2026-10-10 10:00', $onsite, $vacation, $fullDay), '1: ausencia vence fim de semana');
assert_same(['fora_expediente', 'expediente', 'Expediente 08:00–17:00'], situation(employee(), '2026-10-05 07:59', $onsite), '2: 07:59 antes da jornada');
assert_same('presencial', situation(employee(), '2026-10-05 08:00', $onsite)[0], '2: 08:00 dentro da jornada');
assert_same('presencial', situation(employee(), '2026-10-05 16:59', $onsite)[0], '2: 16:59 dentro da jornada');
assert_same('fora_expediente', situation(employee(), '2026-10-05 17:00', $onsite)[0], '2: 17:00 fora da jornada');
assert_same(['fora_expediente', 'expediente', 'Fim de semana'], situation(employee(), '2026-10-10 10:00', $onsite), '2: dia nao util');
assert_same('fora_expediente', situation(employee(), '2026-10-05 18:00', $onsite, null, [], $coffee)[0], '2: pausa esquecida fora do expediente');
assert_same('fora_expediente', situation(employee(), '2026-10-05 18:00', [])[0], '2: sem escala e fora do expediente');
assert_same(['pausa', 'pausa', 'Café'], situation(employee(), '2026-10-05 10:00', $onsite, null, [], $coffee), '3: pausa com o motivo');
assert_same(['pausa', 'pausa', 'Café'], situation(employee(), '2026-10-05 12:30', $onsite, null, [], $coffee), '3: pausa vence almoco');
assert_same('Pausa', situation(employee(), '2026-10-05 10:00', $onsite, null, [], ['em_pausa' => true, 'motivo' => ''])[2], '3: pausa sem motivo');
assert_same('presencial', situation(employee(), '2026-10-05 10:00', $onsite, null, [], ['em_pausa' => false, 'motivo' => 'Café'])[0], '3: em_pausa falso nao e pausa');
assert_same('presencial', situation(employee(), '2026-10-05 11:59', $onsite)[0], '3a: 11:59 antes do almoco');
assert_same(['almoco', 'expediente', 'Almoço 12:00–13:00'], situation(employee(), '2026-10-05 12:00', $onsite), '3a: 12:00 almoco (P2)');
assert_same('almoco', situation(employee(), '2026-10-05 12:59', $onsite)[0], '3a: 12:59 almoco');
assert_same('presencial', situation(employee(), '2026-10-05 13:00', $onsite)[0], '3a: 13:00 volta a escala');
assert_same('almoco', situation(employee(), '2026-10-05 12:30', [])[0], '3a: almoco vale mesmo sem escala');
assert_same(['sem_escala', 'regra_escala', 'Sem escala definida'], situation(employee(), '2026-10-05 10:00', []), '5: sem regra');
assert_same(['remoto', 'regra_escala', 'Remoto (sempre remoto)'], situation(employee(), '2026-10-05 10:00', [rule('always_remote')]), '4: remoto com o rotulo da regra');
assert_same(['presencial', 'regra_escala', 'Presencial (exceção: treinamento)'], situation(employee(), '2026-10-05 10:00', [], ['exception_type' => 'training']), '4: treinamento nao e ausencia');
assert_same('presencial', situation(employee(), '2026-10-05 10:00', [], ['exception_type' => 'oncall'])[0], '4: plantao nao e ausencia');

// 23:30 e 00:30: a data e a do instante.
$night = employee(['work_start' => '15:30', 'work_end' => '23:59', 'lunch_start' => '19:00', 'lunch_end' => '20:00']);
assert_same('presencial', situation($night, '2026-10-09 23:30', $onsite)[0], '23:30 de sexta: escala de sexta');
assert_same('fora_expediente', situation($night, '2026-10-09 23:59', $onsite)[0], '23:59: fim da jornada');
assert_same(['fora_expediente', 'expediente', 'Fim de semana'], situation($night, '2026-10-10 00:30', $onsite), '00:30 de sabado: data de sabado');
assert_same('fora_expediente', situation($night, '2026-10-05 00:30', $onsite)[0], '00:30 de segunda: antes da jornada');

// Horario invalido ou nulo: a checagem correspondente nao se aplica.
assert_same('presencial', situation(employee(['work_start' => null, 'work_end' => '25:00']), '2026-10-05 22:00', $onsite)[0], 'jornada invalida: sem checagem de expediente');
assert_same('presencial', situation(employee(['lunch_start' => null]), '2026-10-05 12:30', $onsite)[0], 'almoco nulo: sem almoco');
assert_same('presencial', situation(employee(['work_start' => 'abc']), '2026-10-05 10:00', $onsite)[0], 'jornada com texto: ignorada, nao comparada como texto');

// Outras datas: ausencia de dia inteiro, senao a escala; pausa e expediente nao entram.
$day = PresenceEngine::dayPresence(employee(), '2026-10-06', [$cycle], null, []);
assert_same(['presencial', 'regra_escala', 'Presencial (ciclo 2P/1R)', false], [$day['situacao'], $day['origem'], $day['detalhe'], $day['em_almoco']], 'outra data: escala do dia');
$day = PresenceEngine::dayPresence(employee(), '2026-10-06', [$cycle], ['exception_type' => 'day_off'], $fullDay);
assert_same(['ausente', 'ausencia', 'Ausente (dia todo)'], [$day['situacao'], $day['origem'], $day['detalhe']], 'outra data: folga e ausencia de dia inteiro');
assert_same([['origem' => 'excecao', 'period_type' => 'full_day', 'ativa' => true]], $day['ausencias'], 'outra data: ausencia ativa, sem categoria nem observacao');
$day = PresenceEngine::dayPresence(employee(), $saturday, [$cycle], null, []);
assert_same(['fora_expediente', 'expediente', 'Fim de semana'], [$day['situacao'], $day['origem'], $day['detalhe']], 'outra data: fim de semana');

// ============================================================================
// 4. Identidade da soma (secao 4.5)
// ============================================================================
$population = [
    employee(['id' => 1]), employee(['id' => 2, 'work_start' => '10:00', 'work_end' => '19:00', 'lunch_start' => '14:00', 'lunch_end' => '15:00']),
    employee(['id' => 3, 'team' => 'lideranca']), employee(['id' => 4, 'work_start' => '15:30', 'work_end' => '23:59']),
];
$rulesFor = [1 => [$cycle], 2 => [rule('always_remote')], 3 => [], 4 => []];
$instants = 0;
for ($minute = 0; $minute < 24 * 60; $minute += 17) {
    foreach (['2026-10-05', '2026-10-06', '2026-10-10'] as $date) {
        $now = at($date . ' ' . sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60));
        $presences = [];
        foreach ($population as $index => $person) {
            $exception = $index === 1 && $date === '2026-10-06' ? ['exception_type' => 'leave'] : null;
            $absences = $exception ? $fullDay : [];
            $pause = ($minute / 17) % 3 === 0 ? $coffee : null;
            $presences[] = PresenceEngine::nowPresence($person, $now, $rulesFor[$person['id']], $exception, $absences, $pause);
        }
        $summary = PresenceEngine::summarize($presences);
        $sum = $summary['disponiveis'] + $summary['em_pausa'] + $summary['almoco'] + $summary['ausentes'] + $summary['fora_expediente'];
        if ($sum !== $summary['escalados'] || $summary['escalados'] + $summary['sem_escala'] + ($date === '2026-10-10' ? 4 : 0) !== count($population)) {
            assert_same($summary['escalados'], $sum, "identidade da soma em {$now->format('Y-m-d H:i')}");
        }
        $instants++;
    }
}
assert_same(true, $instants > 200, 'identidade da soma conferida em mais de 200 instantes');
assert_same(
    ['escalados' => 1, 'disponiveis' => 0, 'em_pausa' => 0, 'almoco' => 0, 'ausentes' => 1, 'fora_expediente' => 0, 'sem_escala' => 1],
    PresenceEngine::summarize([
        PresenceEngine::nowPresence(employee(), at('2026-10-05 10:00'), $onsite, $vacation, $fullDay, null),
        PresenceEngine::nowPresence(employee(), at('2026-10-05 10:00'), [], $vacation, $fullDay, null),
    ]),
    'contadores: ausente com escala conta; sem escala fica fora da soma mesmo com ausencia'
);

// ============================================================================
// 5. Repositorio e servico contra um PDO espiao
// ============================================================================
final class QaPresencePdo extends PDO {
    public array $employees = [];
    public array $rules = [];
    public array $exceptions = [];
    public array $absences = [];
    public array $statements = [];

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $sql = (string)preg_replace('/\s+/', ' ', trim($query));
        $this->statements[] = $sql;
        return new QaPresenceStatement($this, $sql);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: query() nao prevista');
    }
}

final class QaPresenceStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaPresencePdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params = $params ?? [];
        $employeeFilter = $params[':employee_id'] ?? null;
        $match = static fn(array $row): bool => $employeeFilter === null || (int)$row['employee_id'] === (int)$employeeFilter;
        if (str_starts_with($this->sql, 'SELECT id, nome, equipe, jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, fora_mapa_escala FROM funcionarios WHERE ativo = 1')) {
            $this->rows = array_values(array_filter($this->pdo->employees, static fn(array $row): bool => (int)$row['ativo'] === 1
                && ($employeeFilter === null || (int)$row['id'] === (int)$employeeFilter)));
            usort($this->rows, static fn(array $a, array $b): int => [$a['nome'], $a['id']] <=> [$b['nome'], $b['id']]);
            return true;
        }
        if (str_starts_with($this->sql, 'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE effective_from <= :date_to AND (effective_until IS NULL OR effective_until >= :date_from)')) {
            $this->rows = array_values(array_filter($this->pdo->rules, static fn(array $row): bool => $match($row)
                && $row['effective_from'] <= $params[':date_to']
                && ($row['effective_until'] === null || $row['effective_until'] >= $params[':date_from'])));
            return true;
        }
        if (str_starts_with($this->sql, 'SELECT employee_id, exception_date, exception_type FROM portal_schedule_exceptions WHERE exception_date BETWEEN :date_from AND :date_to')) {
            $this->rows = array_values(array_filter($this->pdo->exceptions, static fn(array $row): bool => $match($row)
                && $row['exception_date'] >= $params[':date_from'] && $row['exception_date'] <= $params[':date_to']));
            return true;
        }
        if (str_starts_with($this->sql, "SELECT id, employee_id, starts_on, ends_on, period_type, start_time, end_time, category FROM portal_absences WHERE status = 'approved' AND starts_on <= :date_to AND ends_on >= :date_from")) {
            $this->rows = array_values(array_filter($this->pdo->absences, static fn(array $row): bool => $match($row)
                && $row['status'] === 'approved' && $row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']));
            $this->rows = array_map(static fn(array $row): array => $row + ['id' => 0, 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'folga'], $this->rows);
            usort($this->rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $this->sql);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }
}

function presence_db(int $employees): QaPresencePdo {
    $pdo = new QaPresencePdo();
    for ($id = 1; $id <= $employees; $id++) {
        $pdo->employees[] = [
            'id' => $id, 'nome' => sprintf('Colaborador %02d', $id), 'equipe' => $id % 2 === 0 ? 'N2' : 'n1',
            'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00',
            'fora_mapa_escala' => 0, 'ativo' => 1,
        ];
        $pdo->rules[] = ['id' => $id, 'employee_id' => $id, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => null];
        $pdo->exceptions[] = ['employee_id' => $id, 'exception_date' => '2026-10-06', 'exception_type' => 'remote', 'note' => 'nunca lida'];
    }
    return $pdo;
}

// N+1: o mesmo numero de comandos para 1 e para 50 colaboradores, e para 1 e 93 dias.
$counts = [];
foreach ([[1, '2026-10-05'], [50, '2026-10-05'], [50, '2027-01-05']] as [$size, $to]) {
    $pdo = presence_db($size);
    $result = (new PresenceService($pdo))->period(['from' => '2026-10-05', 'to' => $to], at('2026-10-05 10:00'), []);
    $counts[] = count($pdo->statements);
    assert_same($size * ((new DateTimeImmutable('2026-10-05'))->diff(new DateTimeImmutable($to))->days + 1), count($result['days']), "periodo: uma linha por colaborador e dia ({$size}, ate {$to})");
}
assert_same([4, 4, 4], $counts, 'N+1: 4 comandos para 1 e 50 colaboradores e para 1 e 93 dias');
$pdo = presence_db(50);
(new PresenceService($pdo))->attachToStatus(['n1' => [], 'n2' => []], at('2026-10-05 10:00'));
assert_same(4, count($pdo->statements), 'status.php: 4 comandos a mais por requisicao');
$allSql = implode("\n", $pdo->statements);
assert_same(0, preg_match('/NOW\(\)|CURRENT_DATE|CURDATE|CURRENT_TIMESTAMP/i', $allSql), 'repositorio: sem relogio do banco');
assert_same(0, preg_match('/\bnote\b|\breason\b|\babsence_type\b|SELECT \*/i', $allSql), 'repositorio: sem observacao, motivo, categoria nem SELECT *');

// Periodo: validacao (400 no endpoint) e filtros.
$service = new PresenceService(presence_db(3));
$now = at('2026-10-05 10:00');
foreach ([
    'from invalido' => ['from' => '05/10/2026'],
    'data inexistente' => ['from' => '2026-02-30'],
    'to antes de from' => ['from' => '2026-10-05', 'to' => '2026-10-04'],
    '94 dias' => ['from' => '2026-10-05', 'to' => '2027-01-06'],
    'equipe invalida' => ['team' => 'n3'],
    'equipe como lista' => ['team' => ['n1']],
    'colaborador zero' => ['employee_id' => '0'],
    'colaborador texto' => ['employee_id' => '1 OR 1=1'],
] as $case => $query) {
    assert_throws(InvalidArgumentException::class, static fn() => $service->period($query, $now, []), "periodo: {$case} recusado");
}
$result = $service->period(['from' => '2026-10-05', 'to' => '2027-01-05'], $now, []);
assert_same(['from' => '2026-10-05', 'to' => '2027-01-05'], $result['period'], 'periodo: 93 dias aceitos');
$result = $service->period([], $now, []);
assert_same(['2026-10-05', '2026-10-05', '2026-10-05T10:00:00-03:00'], [$result['today'], $result['period']['to'], $result['server_now']], 'periodo: padrao hoje, server_now com fuso');
assert_same(['Colaborador 01', 'Colaborador 02', 'Colaborador 03'], array_column($result['employees'], 'name'), 'periodo: colaboradores por nome');
assert_same(['id', 'name', 'team'], array_keys($result['employees'][0]), 'periodo: colaborador so com id, nome e equipe');
$result = $service->period(['team' => 'n2', 'from' => '2026-10-06'], $now, []);
assert_same([[2, 'remoto', 'Remoto (exceção: remoto)']], array_map(static fn(array $d): array => [$d['employee_id'], $d['situacao'], $d['detalhe']], $result['days']), 'periodo: filtro de equipe (N2 normalizada) e excecao do dia');

// Hoje usa o instante e a pausa; estado ilegivel nao vira "sem pausa" silencioso.
$result = $service->period(['employee_id' => '1'], $now, ['1' => ['em_pausa' => true, 'motivo_pausa' => 'Café']]);
assert_same([['employee_id' => 1, 'situacao' => 'pausa']], array_map(static fn(array $d): array => ['employee_id' => $d['employee_id'], 'situacao' => $d['situacao']], $result['days']), 'periodo: hoje com a pausa do estado');
assert_same(true, $result['meta']['pausas_disponiveis'], 'periodo: pausas disponiveis');
$result = $service->period(['employee_id' => '1'], $now, null);
assert_same(['presencial', false], [$result['days'][0]['situacao'], $result['meta']['pausas_disponiveis']], 'periodo: estado ilegivel avisa em meta');
$result = $service->period(['employee_id' => '1', 'from' => '2026-10-06', 'to' => '2026-10-07'], $now, null);
assert_same(true, $result['meta']['pausas_disponiveis'], 'periodo sem hoje: pausas nao se aplicam');

// Fora do Mapa, sem equipe valida e inativos ficam de fora; Lideranca entra.
$pdo = presence_db(4);
$pdo->employees[0]['fora_mapa_escala'] = 1;
$pdo->employees[1]['equipe'] = 'Liderança';
$pdo->employees[2]['ativo'] = 0;
$pdo->employees[3]['equipe'] = 'desconhecida';
$pdo->employees[] = ['id' => 9, 'nome' => 'Lider', 'equipe' => 'NA', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'fora_mapa_escala' => 0, 'ativo' => 1];
$result = (new PresenceService($pdo))->period(['from' => '2026-10-07'], $now, []);
assert_same([[2, 'lideranca'], [9, 'lideranca']], array_map(static fn(array $e): array => [$e['id'], $e['team']], $result['employees']), 'periodo: fora do Mapa, inativo e equipe invalida fora; Lideranca entra');
assert_same('Presencial (regra fixa)', $result['days'][0]['detalhe'], 'periodo: Lideranca com regra fixa, sem a linha de regra');

// Ausencia aprovada de portal_absences (dia inteiro ate o F3); outras situacoes ignoradas.
$pdo = presence_db(2);
$pdo->absences = [
    ['employee_id' => 1, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-06', 'status' => 'approved'],
    ['employee_id' => 2, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'status' => 'pending'],
];
$result = (new PresenceService($pdo))->period(['from' => '2026-10-06', 'to' => '2026-10-07'], $now, []);
assert_same(
    [['2026-10-06', 1, 'ausente'], ['2026-10-06', 2, 'remoto'], ['2026-10-07', 1, 'presencial'], ['2026-10-07', 2, 'presencial']],
    array_map(static fn(array $d): array => [$d['date'], $d['employee_id'], $d['situacao']], $result['days']),
    'periodo: ausencia aprovada ate o ultimo dia; pendente ignorada'
);
assert_same([['origem' => 'ausencia', 'period_type' => 'full_day', 'ativa' => true]], $result['days'][0]['ausencias'], 'periodo: ausencia de portal_absences sem categoria nem motivo');

// status.php: presenca em cada item de n1/n2 com a pausa do proprio item.
$pdo = presence_db(3);
$pdo->employees[1]['fora_mapa_escala'] = 1;
$pdo->exceptions[] = ['employee_id' => 3, 'exception_date' => '2026-10-05', 'exception_type' => 'leave', 'note' => 'nunca lida'];
$status = [
    'n1' => [['id' => 1, 'em_pausa' => true, 'motivo_pausa' => 'Café'], ['id' => 3, 'em_pausa' => false, 'motivo_pausa' => null]],
    'n2' => [['id' => 2, 'em_pausa' => false, 'motivo_pausa' => null], ['id' => 77, 'em_pausa' => false, 'motivo_pausa' => null]],
    'server_now' => 'x',
];
$attached = (new PresenceService($pdo))->attachToStatus($status, at('2026-10-05 10:00'));
assert_same(['situacao' => 'pausa', 'origem' => 'pausa', 'detalhe' => 'Café'], $attached['n1'][0]['presenca'], 'status: pausa do item');
assert_same(['situacao' => 'ausente', 'origem' => 'ausencia', 'detalhe' => 'Ausente (dia todo)'], $attached['n1'][1]['presenca'], 'status: licenca vira ausente sem categoria');
assert_same([null, null], [$attached['n2'][0]['presenca'], $attached['n2'][1]['presenca']], 'status: fora do Mapa e inativo (fora da carga) com presenca null');
assert_same('x', $attached['server_now'], 'status: demais campos intactos');

// Interruptor STATUS_PRESENCE_ENABLED: padrao ligado.
foreach (['' => true, '1' => true, 'true' => true, 'ON' => true, 'yes' => true, '0' => false, 'false' => false, ' Off ' => false, 'no' => false, 'desligado' => true] as $value => $want) {
    putenv('STATUS_PRESENCE_ENABLED=' . $value);
    assert_same($want, PresenceService::statusPresenceEnabled(), "interruptor: '{$value}'");
}
putenv('STATUS_PRESENCE_ENABLED');
assert_same(true, PresenceService::statusPresenceEnabled(), 'interruptor: ausente = ligado');

/// ============================================================================
// 6. F3: ausencia parcial (secoes 4.3 e 4.4) e categoria so para quem pode (P9)
// ============================================================================
$onsiteRule = [rule('always_onsite')];
$fromTwo = ['origem' => 'ausencia', 'period_type' => 'from_time', 'start_time' => '14:00:00', 'end_time' => null];
$tenToNoon = ['origem' => 'ausencia', 'period_type' => 'interval', 'start_time' => '10:00:00', 'end_time' => '12:00:00'];
$monday = '2026-10-05';
// "A partir de 14:00": 13:59 escala com marcador; 14:00 ausente; fim do expediente volta para fora do expediente.
assert_same(['presencial', 'regra_escala', 'Presencial (sempre presencial) · Ausente a partir de 14:00'], situation(employee(), "{$monday} 13:59", $onsiteRule, null, [$fromTwo]), 'parcial: 13:59 com marcador');
assert_same(['ausente', 'ausencia', 'Ausente desde 14:00'], situation(employee(), "{$monday} 14:00", $onsiteRule, null, [$fromTwo]), 'parcial: 14:00 ausente');
assert_same(['ausente', 'ausencia', 'Ausente desde 14:00'], situation(employee(), "{$monday} 16:59", $onsiteRule, null, [$fromTwo]), 'parcial: ate o fim do expediente');
assert_same(['fora_expediente', 'expediente', 'Expediente 08:00–17:00'], situation(employee(), "{$monday} 17:00", $onsiteRule, null, [$fromTwo]), 'parcial: depois do expediente, fora do expediente');
assert_same(['fora_expediente', 'expediente', 'Expediente 08:00–17:00'], situation(employee(), "{$monday} 07:30", $onsiteRule, null, [$fromTwo]), 'parcial: antes do expediente, fora do expediente');
$now = PresenceEngine::nowPresence(employee(), at("{$monday} 13:59"), $onsiteRule, null, [$fromTwo], null);
assert_same([['origem' => 'ausencia', 'period_type' => 'from_time', 'ativa' => false, 'start_time' => '14:00', 'end_time' => null]], $now['ausencias'], 'parcial: marcador inativo com horario, sem categoria nem observacao');
assert_same(true, PresenceEngine::nowPresence(employee(), at("{$monday} 14:00"), $onsiteRule, null, [$fromTwo], null)['ausencias'][0]['ativa'], 'parcial: ativa as 14:00');
// Intervalo 10:00-12:00: 09:59 marcador; 10:00 e 11:59 ausente; 12:00 volta (almoco da jornada).
assert_same(['presencial', 'regra_escala', 'Presencial (sempre presencial) · Ausente 10:00–12:00'], situation(employee(), "{$monday} 09:59", $onsiteRule, null, [$tenToNoon]), 'intervalo: 09:59 com marcador');
assert_same(['ausente', 'ausencia', 'Ausente 10:00–12:00'], situation(employee(), "{$monday} 10:00", $onsiteRule, null, [$tenToNoon]), 'intervalo: 10:00 ausente');
assert_same(['ausente', 'ausencia', 'Ausente 10:00–12:00'], situation(employee(), "{$monday} 11:59", $onsiteRule, null, [$tenToNoon]), 'intervalo: 11:59 ausente');
assert_same(['almoco', 'expediente', 'Almoço 12:00–13:00'], situation(employee(), "{$monday} 12:00", $onsiteRule, null, [$tenToNoon]), 'intervalo: no fim volta a precedencia seguinte');
assert_same(['presencial', 'regra_escala', 'Presencial (sempre presencial)'], situation(employee(), "{$monday} 15:00", $onsiteRule, null, [$tenToNoon]), 'intervalo: encerrado nao deixa marcador');
// Precedencia: parcial ativa vence pausa; dia inteiro vence parcial; a primeira parcial ativa vale.
assert_same(['ausente', 'ausencia', 'Ausente 10:00–12:00'], situation(employee(), "{$monday} 10:30", $onsiteRule, null, [$tenToNoon], ['em_pausa' => true, 'motivo' => 'Café']), 'parcial ativa vence pausa');
assert_same(['ausente', 'ausencia', 'Ausente (dia todo)'], situation(employee(), "{$monday} 10:30", $onsiteRule, null, [$tenToNoon, ['origem' => 'excecao', 'period_type' => 'full_day']]), 'dia inteiro vence parcial, em qualquer ordem');
assert_same(['ausente', 'ausencia', 'Ausente desde 09:00'], situation(employee(), "{$monday} 10:30", $onsiteRule, null, [['start_time' => '09:00'] + $fromTwo, $tenToNoon]), 'duas parciais ativas: vale a primeira (por id)');
// Fim de semana e cadastro sem jornada.
assert_same(['ausente', 'ausencia', 'Ausente 10:00–12:00'], situation(employee(), '2026-10-10 10:30', $onsiteRule, null, [$tenToNoon]), 'parcial ativa vence fim de semana');
assert_same(['fora_expediente', 'expediente', 'Fim de semana'], situation(employee(), '2026-10-10 09:00', $onsiteRule, null, [$tenToNoon]), 'parcial futura no fim de semana: fim de semana, sem marcador');
assert_same(['ausente', 'ausencia', 'Ausente desde 14:00'], situation(employee(['work_start' => null, 'work_end' => null]), "{$monday} 23:30", $onsiteRule, null, [$fromTwo]), 'sem jornada valida: "a partir de" vale ate o fim do dia');
assert_same(['presencial', 'regra_escala', 'Presencial (sempre presencial)'], situation(employee(), "{$monday} 15:00", $onsiteRule, null, [['start_time' => '25:00'] + $fromTwo]), 'horario invalido: parcial nunca ativa e sem marcador');
// Outra data: parcial e so marcador; dia inteiro continua ausente.
$day = PresenceEngine::dayPresence(employee(), '2026-10-06', $onsiteRule, null, [$fromTwo]);
assert_same(['presencial', 'regra_escala', 'Presencial (sempre presencial) · Ausente a partir de 14:00', false], [$day['situacao'], $day['origem'], $day['detalhe'], $day['ausencias'][0]['ativa']], 'outra data: parcial como marcador');
$day = PresenceEngine::dayPresence(employee(), '2026-10-06', $onsiteRule, null, [$tenToNoon, ['origem' => 'ausencia', 'period_type' => 'full_day', 'category' => 'ferias']]);
assert_same(['ausente', 'Ausente (dia todo)', [false, true], 'ferias'], [$day['situacao'], $day['detalhe'], array_column($day['ausencias'], 'ativa'), $day['ausencias'][1]['category']], 'outra data: dia inteiro ausente; categoria so quando veio');
// Contadores: parcial ativa conta como ausente; futura, como disponivel.
$counted = PresenceEngine::summarize([
    PresenceEngine::nowPresence(employee(), at("{$monday} 14:30"), $onsiteRule, null, [$fromTwo], null),
    PresenceEngine::nowPresence(employee(['id' => 2]), at("{$monday} 13:30"), $onsiteRule, null, [$fromTwo], null),
]);
assert_same([2, 1, 1], [$counted['escalados'], $counted['ausentes'], $counted['disponiveis']], 'contadores: parcial ativa e ausente; futura e disponivel');

// Servico: periodo com parcial e P9; status.php com parcial e sem categoria.
$pdo = presence_db(3);
$pdo->absences = [
    ['id' => 11, 'employee_id' => 1, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'status' => 'approved', 'period_type' => 'from_time', 'start_time' => '14:00:00', 'end_time' => null, 'category' => 'compromisso'],
    ['id' => 12, 'employee_id' => 2, 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-06', 'status' => 'approved', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'ferias'],
];
$service = new PresenceService($pdo);
$categories = static function (array $result): array {
    $found = [];
    foreach ($result['days'] as $day) {
        foreach ($day['ausencias'] as $absence) {
            $found[$day['employee_id'] . ':' . $day['date']] = $absence['category'] ?? null;
        }
    }
    return $found;
};
$query = ['from' => '2026-10-05', 'to' => '2026-10-06'];
foreach (['gestor', 'lideranca', 'admin'] as $role) {
    assert_same(['1:2026-10-05' => 'compromisso', '2:2026-10-06' => 'ferias'], $categories($service->period($query, at('2026-10-05 15:00'), [], ['role' => $role, 'employee_id' => null])), "P9: {$role} ve a categoria de todos");
}
foreach (['tecnico', 'somente_leitura'] as $role) {
    assert_same(['1:2026-10-05' => 'compromisso', '2:2026-10-06' => null], $categories($service->period($query, at('2026-10-05 15:00'), [], ['role' => $role, 'employee_id' => 1])), "P9: {$role} ve so a categoria da propria");
}
assert_same(['1:2026-10-05' => null, '2:2026-10-06' => null], $categories($service->period($query, at('2026-10-05 15:00'), [])), 'P9: sem quem consulta, nenhuma categoria');
assert_same(['1:2026-10-05' => null, '2:2026-10-06' => null], $categories($service->period($query, at('2026-10-05 15:00'), [], ['role' => 'tecnico', 'employee_id' => 0])), 'P9: tecnico sem colaborador na sessao, nenhuma categoria');
$result = $service->period($query, at('2026-10-05 15:00'), [], ['role' => 'admin']);
assert_same(['ausente', 'Ausente desde 14:00'], [$result['days'][0]['situacao'], $result['days'][0]['detalhe']], 'periodo: hoje com parcial ativa');
assert_same(['ausente', 'Ausente (dia todo)'], [$result['days'][4]['situacao'], $result['days'][4]['detalhe']], 'periodo: outra data com dia inteiro');
assert_same(0, preg_match('/note|reason|observ/i', (string)json_encode($result)), 'periodo: observacao nunca sai (decisao 4)');
$attached = $service->attachToStatus(['n1' => [['id' => 1, 'em_pausa' => false, 'motivo_pausa' => null]], 'n2' => []], at('2026-10-05 15:00'));
assert_same(['situacao' => 'ausente', 'origem' => 'ausencia', 'detalhe' => 'Ausente desde 14:00'], $attached['n1'][0]['presenca'], 'status: parcial ativa, sem categoria');
$attached = $service->attachToStatus(['n1' => [['id' => 1, 'em_pausa' => false, 'motivo_pausa' => null]], 'n2' => []], at('2026-10-05 10:00'));
assert_same('Presencial (sempre presencial) · Ausente a partir de 14:00', $attached['n1'][0]['presenca']['detalhe'], 'status: parcial ainda nao iniciada como marcador');
$pdo = presence_db(50);
(new PresenceService($pdo))->period(['from' => '2026-10-05'], at('2026-10-05 10:00'), [], ['role' => 'admin']);
$absenceSql = implode("\n", array_filter($pdo->statements, static fn (string $sql): bool => strpos($sql, 'portal_absences') !== false));
assert_same([4, true, 0], [count($pdo->statements), strpos($absenceSql, 'ORDER BY id') !== false, preg_match('/\bnote\b|\breason\b/i', $absenceSql)], 'repositorio: ainda 4 comandos; ausencias por id, sem observacao');
$presenceSource = (string)file_get_contents(__DIR__ . '/../api/portal/presence.php');
assert_same(true, strpos($presenceSource, '$viewer = portal_actor(require_portal_auth());') !== false && strpos($presenceSource, ", \$viewer));") !== false, 'presence.php: passa quem consulta (P9)');

// Estrutura: o motor nao le relogio, banco nem arquivo.
$engineSource = (string)file_get_contents(__DIR__ . '/../services/PresenceEngine.php');
assert_same(0, preg_match("/new DateTime(Immutable)?\\(\\s*\\)|new DateTime(Immutable)?\\('now'|\\btime\\(\\)|\\bdate\\(|PDO|file_get_contents|fopen|getenv/", $engineSource), 'motor: sem relogio, banco, arquivo nem ambiente');
$statusSource = (string)file_get_contents(__DIR__ . '/../api/status.php');
assert_same(true, strpos($statusSource, 'if (PresenceService::statusPresenceEnabled()) {') !== false && strpos($statusSource, '} catch (Throwable $error) {') !== false, 'status.php: presenca atras do interruptor e com falha suave');

echo "QA presence engine OK ({$checks} verificacoes)\n";
