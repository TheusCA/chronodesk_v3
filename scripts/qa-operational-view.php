<?php
declare(strict_types=1);

// ============================================================================
// QA da Visao Operacional no motor (Parte B, F7; desenho, secao 9.9)
//
// Sem banco:
// 1. PresenceService::operationalSummary com relogio injetado: identidade da
//    soma por equipe (escalados = disponiveis + em_pausa + almoco + ausentes
//    + fora_expediente; sem_escala fora) nos instantes da 9.9, total = N1 + N2
//    com a Lideranca separada, e quem fica fora de tudo (0.6);
// 2. pausas do estado ja carregado (DV2) e estado ilegivel (DV6), inclusive
//    no GerenciadorPausas;
// 3. PresenceService::technicianPresence: P9 (categoria), parcial e DV10
//    (motivo de pausa fora da lista);
// 4. numero de consultas fixo (4) e sem a data de corte (DV3);
// 5. estrutura dos endpoints: interruptor (DV4), falha suave (DV5), DV9;
// 6. observacao de reuniao so para a gestao e o proprio (DV11, commit 2).
// Os endpoints rodam de ponta a ponta no qa-security (perfis, interruptor e
// falha). As consultas SQL nao rodam em MySQL aqui (sem MySQL local).
//
// Uso: php scripts/qa-operational-view.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$qaDir = sys_get_temp_dir() . '/chronodesk-qa-operational-' . bin2hex(random_bytes(6));
if (!mkdir($qaDir, 0700)) {
    fwrite(STDERR, "FAIL: nao criou diretorio temporario do QA\n");
    exit(1);
}
putenv('PHP_ERROR_LOG=' . $qaDir . '/php.log');
register_shutdown_function(static function () use ($qaDir): void {
    foreach (glob($qaDir . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($qaDir);
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.252';
define('CHRONODESK_STATELESS', true);
// Estado de pausas do GerenciadorPausas no diretorio do QA; o banco nunca e aberto.
define('ESTADO_JSON', $qaDir . '/estado.json');
function get_db_connection(): PDO {
    throw new PDOException('SQLSTATE[HY000]: QA sem banco');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/PresenceService.php';
require_once __DIR__ . '/../classes/GerenciadorPausas.php';

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

function at(string $dateTime): DateTimeImmutable {
    return new DateTimeImmutable($dateTime, new DateTimeZone('America/Sao_Paulo'));
}

// Banco simulado: as 4 consultas do PresenceRepository, com o filtro do SQL
// emulado; qualquer outra consulta falha (inclusive a da data de corte).
final class QaOperationalPdo extends PDO {
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
        return new QaOperationalStatement($this, $sql);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: query() nao prevista');
    }
}

final class QaOperationalStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaOperationalPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params = $params ?? [];
        if ($this->sql === 'SELECT id, nome, equipe, jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, fora_mapa_escala FROM funcionarios WHERE ativo = 1 ORDER BY nome, id') {
            $this->rows = array_values(array_filter($this->pdo->employees, static fn(array $row): bool => (int)$row['ativo'] === 1));
            usort($this->rows, static fn(array $a, array $b): int => [$a['nome'], $a['id']] <=> [$b['nome'], $b['id']]);
            return true;
        }
        if ($this->sql === 'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE effective_from <= :date_to AND (effective_until IS NULL OR effective_until >= :date_from) ORDER BY employee_id, effective_from') {
            $this->rows = array_values(array_filter($this->pdo->rules, static fn(array $row): bool => $row['effective_from'] <= $params[':date_to']
                && ($row['effective_until'] === null || $row['effective_until'] >= $params[':date_from'])));
            return true;
        }
        if ($this->sql === 'SELECT employee_id, exception_date, exception_type FROM portal_schedule_exceptions WHERE exception_date BETWEEN :date_from AND :date_to') {
            $this->rows = array_values(array_filter($this->pdo->exceptions, static fn(array $row): bool => $row['exception_date'] >= $params[':date_from'] && $row['exception_date'] <= $params[':date_to']));
            return true;
        }
        if ($this->sql === "SELECT id, employee_id, starts_on, ends_on, period_type, start_time, end_time, category FROM portal_absences WHERE status = 'approved' AND starts_on <= :date_to AND ends_on >= :date_from ORDER BY id") {
            $this->rows = array_values(array_filter($this->pdo->absences, static fn(array $row): bool => $row['status'] === 'approved'
                && $row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']));
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

function employee_row(int $id, string $team, array $overrides = []): array {
    return $overrides + [
        'id' => $id, 'nome' => sprintf('Colaborador %02d', $id), 'equipe' => $team,
        'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00',
        'fora_mapa_escala' => 0, 'ativo' => 1,
    ];
}

function rule_row(int $employeeId, string $type): array {
    return ['id' => $employeeId, 'employee_id' => $employeeId, 'rule_type' => $type, 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => null];
}

function absence_row(int $id, int $employeeId, string $periodType, ?string $start, ?string $end, string $category, string $status = 'approved'): array {
    return ['id' => $id, 'employee_id' => $employeeId, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'status' => $status,
        'period_type' => $periodType, 'start_time' => $start, 'end_time' => $end, 'category' => $category, 'reason' => 'observacao nunca lida'];
}

// Segunda-feira, 2026-10-05. N1: 1 presencial, 2 remoto, 3 sem regra.
// N2: 4 presencial com ausencia "a partir de 14:00"; 5 com excecao antiga de
// licenca; 11 presencial com ausencia 10:00-11:00. Lideranca: 6 (rotulo com
// acento, escala implicita). Fora de tudo: 7 fora do Mapa, 8 arquivado, 9
// equipe fora das tres, 10 e 12 rotulo de Lideranca que o PHP nao reconhece
// (mal codificado e com maiuscula acentuada), 13 ausencia pendente nao conta.
function operational_db(): QaOperationalPdo {
    $pdo = new QaOperationalPdo();
    $pdo->employees = [
        employee_row(1, 'n1'), employee_row(2, 'N1'), employee_row(3, 'n1'),
        employee_row(4, 'n2'), employee_row(5, 'n2'), employee_row(11, 'n2'), employee_row(13, 'n2'),
        employee_row(6, 'Liderança'),
        employee_row(7, 'n1', ['fora_mapa_escala' => 1]),
        employee_row(8, 'n1', ['ativo' => 0, 'arquivado_em' => '2026-10-01 09:00:00']),
        employee_row(9, 'critico'),
        employee_row(10, "lideran\u{00C3}\u{00A7}a"),
        employee_row(12, 'LIDERANÇA'),
    ];
    foreach ([1 => 'always_onsite', 2 => 'always_remote', 4 => 'always_onsite', 5 => 'always_onsite', 11 => 'always_onsite', 13 => 'always_onsite',
              7 => 'always_onsite', 8 => 'always_onsite', 9 => 'always_onsite', 10 => 'always_onsite', 12 => 'always_onsite'] as $id => $type) {
        $pdo->rules[] = rule_row($id, $type);
    }
    $pdo->rules[] = ['id' => 99, 'employee_id' => 3, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => '2026-09-30'];
    $pdo->exceptions = [
        ['employee_id' => 5, 'exception_date' => '2026-10-05', 'exception_type' => 'leave'],
    ];
    $pdo->absences = [
        absence_row(21, 4, 'from_time', '14:00:00', null, 'compromisso'),
        absence_row(22, 11, 'interval', '10:00:00', '11:00:00', 'ferias'),
        absence_row(23, 13, 'full_day', null, null, 'folga', 'pending'),
    ];
    return $pdo;
}

const BUCKETS = ['disponiveis', 'em_pausa', 'almoco', 'ausentes', 'fora_expediente'];

function assert_identity(array $summary, string $label): void {
    foreach (['n1', 'n2', 'lideranca', 'total'] as $team) {
        $sum = 0;
        foreach (BUCKETS as $bucket) {
            $sum += $summary[$team][$bucket];
        }
        assert_same($summary[$team]['escalados'], $sum, "{$label}: identidade da soma em {$team}");
    }
    foreach (array_merge(['escalados', 'sem_escala'], BUCKETS) as $key) {
        assert_same($summary['n1'][$key] + $summary['n2'][$key], $summary['total'][$key], "{$label}: total = N1 + N2 em {$key}, sem a Lideranca");
    }
}

/** [escalados, disponiveis, em_pausa, almoco, ausentes, fora_expediente, sem_escala] */
function counts(array $team): array {
    return [$team['escalados'], $team['disponiveis'], $team['em_pausa'], $team['almoco'], $team['ausentes'], $team['fora_expediente'], $team['sem_escala']];
}

$pausesCoffeeMeeting = [1 => ['em_pausa' => true, 'motivo' => 'Café'], 2 => ['em_pausa' => true, 'motivo' => 'Reunião']];
$summaryAt = static function (string $dateTime, ?array $pauses) {
    return (new PresenceService(operational_db()))->operationalSummary(at($dateTime), $pauses);
};

// ============================================================================
// 1. Contadores nos instantes da 9.9 (relogio injetado)
// ============================================================================
$expected = [
    // instante => [n1, n2, lideranca], com as pausas de 1 (Cafe) e 2 (Reuniao)
    '2026-10-05 07:59' => [[2, 0, 0, 0, 0, 2, 1], [4, 0, 0, 0, 1, 3, 0], [1, 0, 0, 0, 0, 1, 0]],
    '2026-10-05 08:00' => [[2, 0, 2, 0, 0, 0, 1], [4, 3, 0, 0, 1, 0, 0], [1, 1, 0, 0, 0, 0, 0]],
    '2026-10-05 10:30' => [[2, 0, 2, 0, 0, 0, 1], [4, 2, 0, 0, 2, 0, 0], [1, 1, 0, 0, 0, 0, 0]],
    '2026-10-05 12:00' => [[2, 0, 2, 0, 0, 0, 1], [4, 0, 0, 3, 1, 0, 0], [1, 0, 0, 1, 0, 0, 0]],
    '2026-10-05 12:59' => [[2, 0, 2, 0, 0, 0, 1], [4, 0, 0, 3, 1, 0, 0], [1, 0, 0, 1, 0, 0, 0]],
    '2026-10-05 13:00' => [[2, 0, 2, 0, 0, 0, 1], [4, 3, 0, 0, 1, 0, 0], [1, 1, 0, 0, 0, 0, 0]],
    '2026-10-05 13:59' => [[2, 0, 2, 0, 0, 0, 1], [4, 3, 0, 0, 1, 0, 0], [1, 1, 0, 0, 0, 0, 0]],
    '2026-10-05 14:00' => [[2, 0, 2, 0, 0, 0, 1], [4, 2, 0, 0, 2, 0, 0], [1, 1, 0, 0, 0, 0, 0]],
    '2026-10-05 16:59' => [[2, 0, 2, 0, 0, 0, 1], [4, 2, 0, 0, 2, 0, 0], [1, 1, 0, 0, 0, 0, 0]],
    '2026-10-05 17:00' => [[2, 0, 0, 0, 0, 2, 1], [4, 0, 0, 0, 1, 3, 0], [1, 0, 0, 0, 0, 1, 0]],
    '2026-10-05 23:30' => [[2, 0, 0, 0, 0, 2, 1], [4, 0, 0, 0, 1, 3, 0], [1, 0, 0, 0, 0, 1, 0]],
    // Terca 00:30: a excecao e as ausencias eram da segunda.
    '2026-10-06 00:30' => [[2, 0, 0, 0, 0, 2, 1], [4, 0, 0, 0, 0, 4, 0], [1, 0, 0, 0, 0, 1, 0]],
    // Sabado sem excecao de trabalho: ninguem escalado, nem "sem escala".
    '2026-10-10 10:00' => [[0, 0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0, 0]],
];
foreach ($expected as $dateTime => [$n1, $n2, $leaders]) {
    $summary = $summaryAt($dateTime, $pausesCoffeeMeeting);
    assert_identity($summary, $dateTime);
    assert_same([$n1, $n2, $leaders], [counts($summary['n1']), counts($summary['n2']), counts($summary['lideranca'])], "contadores as {$dateTime}");
    assert_same(['n1', 'n2', 'lideranca', 'total'], array_keys($summary), "{$dateTime}: N1, N2, Lideranca e total");
}
// Criterio de aceite do requisito: ausencia "a partir de 14:00" sai dos disponiveis so as 14:00.
assert_same([3, 2], [$summaryAt('2026-10-05 13:59', [])['n2']['disponiveis'], $summaryAt('2026-10-05 14:00', [])['n2']['disponiveis']], 'aceite: sai dos disponiveis as 14:00, nao antes');
// Pausa de cafe e de reuniao em "Em pausa", nunca em "Ausentes"; sem pausa, remoto conta como disponivel.
$summary = $summaryAt('2026-10-05 10:30', $pausesCoffeeMeeting);
assert_same([2, 0], [$summary['n1']['em_pausa'], $summary['n1']['ausentes']], 'cafe e reuniao em pausa, nunca ausentes');
$summary = $summaryAt('2026-10-05 10:30', []);
assert_same([2, 2, 0], [$summary['n1']['escalados'], $summary['n1']['disponiveis'], $summary['n1']['em_pausa']], 'remoto e presencial contam como disponiveis');
// Parcial: ativa (11, 10:00-11:00) ausente; futura (4, a partir de 14:00) disponivel; encerrada volta.
assert_same([2, 2], [$summaryAt('2026-10-05 10:30', [])['n2']['disponiveis'], $summaryAt('2026-10-05 10:30', [])['n2']['ausentes']], 'parcial ativa ausente e futura disponivel');
assert_same([3, 1], [$summaryAt('2026-10-05 11:00', [])['n2']['disponiveis'], $summaryAt('2026-10-05 11:00', [])['n2']['ausentes']], 'intervalo encerrado volta a disponivel; excecao antiga segue ausente');
// Sabado com excecao de trabalho: so quem tem a excecao fica escalado.
$pdo = operational_db();
$pdo->exceptions[] = ['employee_id' => 1, 'exception_date' => '2026-10-10', 'exception_type' => 'onsite'];
$summary = (new PresenceService($pdo))->operationalSummary(at('2026-10-10 10:00'), []);
assert_identity($summary, 'sabado com excecao');
assert_same([[1, 1, 0, 0, 0, 0, 0], 0], [counts($summary['n1']), $summary['total']['sem_escala']], 'sabado: excecao de trabalho escala so quem a tem');
// Fora de tudo: fora do Mapa, arquivado, equipe desconhecida, rotulo de Lideranca nao reconhecido e ausencia pendente.
$summary = $summaryAt('2026-10-05 10:30', []);
assert_same([7, 1, 4], [$summary['total']['escalados'] + $summary['total']['sem_escala'], $summary['lideranca']['escalados'], $summary['n2']['escalados']], 'so 1 a 6, 11 e 13 contam; a Lideranca so com o 6 (10 e 12, rotulos que o PHP nao reconhece, ficam fora: comportamento atual, risco registrado na 9.9)');
assert_same([2, 2], [$summary['n2']['ausentes'], $summary['n2']['disponiveis']], 'ausencia pendente nao conta: o 13 segue disponivel');
// Lideranca separada: nunca entra no total.
$pdo = operational_db();
$pdo->employees = [employee_row(6, 'lideranca')];
$summary = (new PresenceService($pdo))->operationalSummary(at('2026-10-05 10:30'), []);
assert_same([1, 0], [$summary['lideranca']['disponiveis'], $summary['total']['escalados']], 'Lideranca fora do total');

// ============================================================================
// 2. Pausas: estado ja carregado (DV2) e estado ilegivel (DV6)
// ============================================================================
$summary = $summaryAt('2026-10-05 10:30', null);
assert_same([0, 2], [$summary['n1']['em_pausa'], $summary['n1']['disponiveis']], 'estado ilegivel: ninguem em pausa (quem chama avisa)');
$summary = $summaryAt('2026-10-05 10:30', [4 => ['em_pausa' => true, 'motivo' => 'Café'], 11 => ['em_pausa' => true, 'motivo' => 'Café']]);
assert_same([1, 2], [$summary['n2']['em_pausa'], $summary['n2']['ausentes']], 'ausencia ativa vence a pausa (secao 4.3)');
$summary = $summaryAt('2026-10-05 10:30', [7 => ['em_pausa' => true, 'motivo' => 'Café'], 8 => ['em_pausa' => true, 'motivo' => 'Café']]);
assert_same(0, $summary['total']['em_pausa'] + $summary['lideranca']['em_pausa'], 'pausa de fora do Mapa ou arquivado nao conta');

$manager = new GerenciadorPausas();
foreach ([1 => 'n1', 2 => 'n1', 6 => 'lideranca'] as $id => $team) {
    $manager->adicionar_funcionario(new Funcionario($id, 'QA ' . $id, $team));
}
assert_same(null, $manager->pausas_atuais(), 'GerenciadorPausas: antes de carregar, pausas indisponiveis');
$manager->carregar_estado();
assert_same([1 => ['em_pausa' => false, 'motivo' => null], 2 => ['em_pausa' => false, 'motivo' => null], 6 => ['em_pausa' => false, 'motivo' => null]], $manager->pausas_atuais(), 'GerenciadorPausas: sem arquivo de estado, ninguem em pausa (lido)');
file_put_contents(ESTADO_JSON, '{"funcionarios": {"1": {"em_pausa": true, "motivo_pausa": "Café", "inicio_pausa": "' . date('c') . '"}, "6": {"em_pausa": true, "motivo_pausa": "Reunião"}}}');
$manager->carregar_estado();
assert_same([1 => ['em_pausa' => true, 'motivo' => 'Café'], 2 => ['em_pausa' => false, 'motivo' => null], 6 => ['em_pausa' => true, 'motivo' => 'Reunião']], $manager->pausas_atuais(), 'GerenciadorPausas: pausas do estado lido');
file_put_contents(ESTADO_JSON, '{"funcionarios": ');
$manager->carregar_estado();
assert_same(null, $manager->pausas_atuais(), 'GerenciadorPausas: estado ilegivel nao vira "nenhuma pausa" (DV6)');
file_put_contents(ESTADO_JSON, '{"outra": []}');
$manager->carregar_estado();
assert_same(null, $manager->pausas_atuais(), 'GerenciadorPausas: estado sem a chave funcionarios e ilegivel');

// ============================================================================
// 3. technicians.php: situacao de agora, parcial, P9 e DV10
// ============================================================================
$pauses = [1 => ['em_pausa' => true, 'motivo' => 'Texto livre antigo'], 2 => ['em_pausa' => true, 'motivo' => 'Café']];
$presenceFor = static fn(array $viewer, string $dateTime = '2026-10-05 10:30', ?array $statePauses = null): array
    => (new PresenceService(operational_db()))->technicianPresence(at($dateTime), $statePauses ?? $pauses, $viewer);
$gestor = $presenceFor(['role' => 'gestor', 'employee_id' => null]);
assert_same([1, 2, 3, 4, 5, 6, 11, 13], (static function (array $ids): array { sort($ids); return $ids; })(array_keys($gestor)), 'technicians: so N1, N2 e Lideranca do motor (fora do Mapa, arquivado e equipe desconhecida sem chave)');
assert_same(['situacao' => 'pausa', 'origem' => 'pausa', 'detalhe' => 'Texto livre antigo'], $gestor[1], 'DV10: gestao ve o motivo fora da lista');
assert_same(['situacao' => 'presencial', 'origem' => 'regra_escala', 'detalhe' => 'Presencial (sempre presencial) · Ausente a partir de 14:00', 'parcial' => 'a partir de 14:00', 'categoria' => 'Compromisso'], $gestor[4], 'P9: gestao ve parcial e categoria do marcador');
assert_same(['situacao' => 'ausente', 'origem' => 'ausencia', 'detalhe' => 'Ausente 10:00–11:00', 'categoria' => 'Férias'], $gestor[11], 'P9: ausente agora com a categoria da parcial ativa, sem parcial');
assert_same(['situacao' => 'ausente', 'origem' => 'ausencia', 'detalhe' => 'Ausente (dia todo)'], $gestor[5], 'excecao antiga: ausente sem categoria');
assert_same(['situacao' => 'sem_escala', 'origem' => 'regra_escala', 'detalhe' => 'Sem escala definida'], $gestor[3], 'sem regra vigente: sem escala');
foreach (['tecnico', 'somente_leitura'] as $role) {
    $other = $presenceFor(['role' => $role, 'employee_id' => 4]);
    assert_same(['situacao' => 'pausa', 'origem' => 'pausa', 'detalhe' => 'Pausa'], $other[1], "DV10: {$role} ve \"Pausa\" no motivo fora da lista de outro");
    assert_same('Café', $other[2]['detalhe'], "DV10: {$role} ve o motivo da lista fechada");
    assert_same(['a partir de 14:00', 'Compromisso'], [$other[4]['parcial'] ?? null, $other[4]['categoria'] ?? null], "P9: {$role} ve a categoria da propria ausencia");
    assert_same(false, array_key_exists('categoria', $other[11]), "P9: {$role} nao ve a categoria de outro");
    $own = $presenceFor(['role' => $role, 'employee_id' => 1]);
    assert_same('Texto livre antigo', $own[1]['detalhe'], "DV10: {$role} ve o proprio motivo");
}
assert_same(false, array_key_exists('categoria', $presenceFor(['role' => 'tecnico', 'employee_id' => 0])[4]), 'P9: tecnico sem colaborador na sessao nao ve categoria');
assert_same(false, array_key_exists('categoria', $presenceFor([])[4]), 'P9: sem quem consulta, nenhuma categoria');
$early = $presenceFor(['role' => 'admin'], '2026-10-05 09:00');
assert_same(['Presencial (sempre presencial) · Ausente 10:00–11:00', '10:00–11:00', 'Férias'], [$early[11]['detalhe'], $early[11]['parcial'], $early[11]['categoria']], 'parcial futura: marcador e categoria do marcador');
assert_same(['a partir de 14:00', 'Compromisso'], [$early[4]['parcial'], $early[4]['categoria']], 'parcial futura: cada um com o proprio marcador');
$late = $presenceFor(['role' => 'admin'], '2026-10-05 14:30');
assert_same(['ausente', 'Ausente desde 14:00', false, 'Compromisso'], [$late[4]['situacao'], $late[4]['detalhe'], isset($late[4]['parcial']), $late[4]['categoria']], 'parcial ativa: ausente, sem parcial, com a categoria');
assert_same(['situacao' => 'presencial', 'origem' => 'regra_escala', 'detalhe' => 'Presencial (regra fixa)'], $presenceFor(['role' => 'admin'])[6], 'Lideranca com escala implicita');
$ilegivel = (new PresenceService(operational_db()))->technicianPresence(at('2026-10-05 10:30'), null, ['role' => 'admin']);
assert_same('remoto', $ilegivel[2]['situacao'], 'technicians: estado ilegivel nao inventa pausa');
assert_same(0, preg_match('/observ|reason|note|nunca lida/i', (string)json_encode($gestor)), 'technicians: observacao da ausencia nunca sai');
// Bordas da parcial e da categoria as 10:30. 31: parcial futura de id menor e
// intervalo ativo. 32: intervalo ja encerrado. 33: intervalo ativo de id menor
// e dia inteiro. 34: duas futuras, a de menor inicio com id menor.
$pdo = new QaOperationalPdo();
foreach ([31, 32, 33, 34] as $id) {
    $pdo->employees[] = employee_row($id, 'n1');
    $pdo->rules[] = rule_row($id, 'always_onsite');
}
$pdo->absences = [
    absence_row(50, 31, 'from_time', '15:00:00', null, 'outro'),
    absence_row(51, 31, 'interval', '10:00:00', '11:00:00', 'compromisso'),
    absence_row(53, 32, 'interval', '09:00:00', '10:00:00', 'compromisso'),
    absence_row(54, 33, 'interval', '10:00:00', '12:00:00', 'compromisso'),
    absence_row(55, 33, 'full_day', null, null, 'ferias'),
    absence_row(56, 34, 'interval', '11:00:00', '12:00:00', 'compromisso'),
    absence_row(57, 34, 'from_time', '14:00:00', null, 'outro'),
];
$edge = (new PresenceService($pdo))->technicianPresence(at('2026-10-05 10:30'), [], ['role' => 'gestor']);
assert_same(['ausente', 'Ausente 10:00–11:00', false, 'Compromisso'], [$edge[31]['situacao'], $edge[31]['detalhe'], isset($edge[31]['parcial']), $edge[31]['categoria'] ?? null], 'ausente por parcial: sem a parcial seguinte e com a categoria da ativa, nao da de id menor');
assert_same(['presencial', false, false], [$edge[32]['situacao'], isset($edge[32]['parcial']), isset($edge[32]['categoria'])], 'parcial encerrada: sem marcador nem categoria');
assert_same(['Ausente (dia todo)', 'Férias'], [$edge[33]['detalhe'], $edge[33]['categoria'] ?? null], 'dia inteiro vence a parcial ativa de id menor tambem na categoria');
assert_same(['11:00–12:00', 'Compromisso'], [$edge[34]['parcial'] ?? null, $edge[34]['categoria'] ?? null], 'marcador: a parcial de menor inicio, nao a ultima');

// ============================================================================
// 4. Consultas: 4 a mais, qualquer que seja o numero de cadastros; sem o corte
// ============================================================================
$counts = [];
$allSql = '';
foreach ([1, 50] as $size) {
    foreach (['summary', 'technicians'] as $method) {
        $pdo = new QaOperationalPdo();
        for ($id = 1; $id <= $size; $id++) {
            $pdo->employees[] = employee_row($id, $id % 3 === 0 ? 'lideranca' : ($id % 2 === 0 ? 'n2' : 'n1'));
            $pdo->rules[] = rule_row($id, 'always_onsite');
        }
        $service = new PresenceService($pdo);
        $method === 'summary'
            ? $service->operationalSummary(at('2026-10-05 10:30'), [])
            : $service->technicianPresence(at('2026-10-05 10:30'), [], ['role' => 'admin']);
        $counts[] = count($pdo->statements);
        $allSql .= implode("\n", $pdo->statements) . "\n";
    }
}
assert_same([4, 4, 4, 4], $counts, 'N+1: 4 consultas para 1 e 50 cadastros, no resumo e na lista');
assert_same(0, preg_match('/presence_cutoff|cutoff_date|NOW\(\)|CURRENT_DATE|CURDATE|CURRENT_TIMESTAMP/i', $allSql), 'consultas: sem a data de corte (DV3) e sem relogio do banco');
assert_same(0, preg_match('/\bnote\b|\breason\b|SELECT \*/i', $allSql), 'consultas: sem observacao nem SELECT *');

// ============================================================================
// 5. Estrutura dos endpoints
// ============================================================================
$dashboard = (string)file_get_contents(__DIR__ . '/../api/portal/dashboard.php');
$technicians = (string)file_get_contents(__DIR__ . '/../api/portal/technicians.php');
$service = (string)file_get_contents(__DIR__ . '/../services/PresenceService.php');
foreach (['dashboard.php' => $dashboard, 'technicians.php' => $technicians] as $name => $source) {
    assert_same(1, preg_match('/if \(PresenceService::statusPresenceEnabled\(\)\) \{\s+try \{.*?\} catch \(Throwable \$error\) \{\s+error_log\(\'\[PRESENCE\] [^\']+\' \. get_class\(\$error\)\);/s', $source), "{$name}: interruptor (DV4) e falha suave so com a classe do erro (DV5)");
    assert_same(true, strpos($source, '$pausas = $gerenciador->pausas_atuais();') !== false, "{$name}: pausas do estado ja carregado (DV2)");
    assert_same(0, preg_match('/PresenceCutoff|presence_cutoff/i', $source), "{$name}: nao le a data de corte (DV3)");
}
assert_same(0, preg_match('/PresenceCutoff|presence_cutoff/i', $service), 'PresenceService: nao le a data de corte (DV3)');
assert_same(true, strpos($dashboard, "'cis_disponiveis' => \$presenceSummary['total']['disponiveis'] ?? null,") !== false, 'dashboard.php: CIs disponiveis = total.disponiveis, ou null sem presenca');
assert_same(true, strpos($technicians, "\$seesRole = in_array('funcionarios.manage', portal_permissions_for_role(\$role), true);") !== false, 'technicians.php: role so com funcionarios.manage (DV9)');
assert_same(true, strpos($technicians, '$viewer = portal_actor($role);') !== false && strpos($technicians, '->technicianPresence($now, $pausas, $viewer)') !== false, 'technicians.php: passa quem consulta (P9, DV10)');
$status = (string)file_get_contents(__DIR__ . '/../api/status.php');
assert_same(0, preg_match('/operationalSummary|technicianPresence|pausas_atuais/', $status), 'status.php: sem consulta nem calculo novo (DV1)');

// ============================================================================
// 6. Observacao de reuniao por perfil (DV11)
// ============================================================================
$meetingItem = static fn ($id, ?string $note): array => ['id' => $id, 'nome' => 'QA ' . $id, 'motivo_pausa' => 'Reunião', 'em_pausa' => true, 'observacao_reuniao' => $note];
$meetingStatus = [
    'n1' => [$meetingItem(1, 'pauta 1'), $meetingItem('7', 'pauta 7')],
    'n2' => [$meetingItem(13, null)],
    'server_now' => '2026-10-27T10:00:00-03:00',
    'poll_interval_seconds' => 15,
];
$notesFor = static function (array $viewer) use ($meetingStatus): array {
    $filtered = GerenciadorPausas::status_para_o_perfil($meetingStatus, $viewer);
    $notes = [];
    foreach (array_merge($filtered['n1'], $filtered['n2']) as $item) {
        $notes[(string)$item['id']] = array_key_exists('observacao_reuniao', $item) ? $item['observacao_reuniao'] : 'sem chave';
    }
    return $notes;
};
$everyone = ['1' => 'pauta 1', '7' => 'pauta 7', '13' => null];
foreach (['admin', 'lideranca', 'gestor'] as $role) {
    assert_same($everyone, $notesFor(['role' => $role, 'employee_id' => null]), "DV11: {$role} recebe a chave de todos (inclusive nula)");
}
assert_same(['1' => 'sem chave', '7' => 'pauta 7', '13' => 'sem chave'], $notesFor(['role' => 'tecnico', 'employee_id' => 7]), 'DV11: tecnico so com a propria (id do estado como texto)');
assert_same(['1' => 'sem chave', '7' => 'sem chave', '13' => null], $notesFor(['role' => 'somente_leitura', 'employee_id' => 13]), 'DV11: somente leitura so com a propria, mesmo nula');
foreach ([['role' => 'tecnico', 'employee_id' => 0], ['role' => 'tecnico', 'employee_id' => null], ['role' => 'tecnico'], []] as $viewer) {
    assert_same(['1' => 'sem chave', '7' => 'sem chave', '13' => 'sem chave'], $notesFor($viewer), 'DV11: sem gestao nem employee_id da sessao, nenhuma observacao: ' . json_encode($viewer));
}
$filtered = GerenciadorPausas::status_para_o_perfil($meetingStatus, ['role' => 'tecnico', 'employee_id' => 1]);
unset($meetingStatus['n1'][1]['observacao_reuniao'], $meetingStatus['n2'][0]['observacao_reuniao']);
assert_same($meetingStatus, $filtered, 'DV11: so a chave sai; o resto do item, a ordem e os campos do status ficam');
$withoutId = ['n1' => [['nome' => 'QA sem id', 'observacao_reuniao' => 'pauta'], $meetingItem(0, 'pauta 0')]];
foreach ([['role' => 'tecnico', 'employee_id' => 0], ['role' => 'somente_leitura']] as $viewer) {
    $filtered = GerenciadorPausas::status_para_o_perfil($withoutId, $viewer);
    assert_same([false, false], array_map(static fn (array $item): bool => array_key_exists('observacao_reuniao', $item), $filtered['n1']), 'DV11: sessao sem employee_id nunca casa com item sem id ou id 0: ' . json_encode($viewer));
}
assert_same(['n1' => []], GerenciadorPausas::status_para_o_perfil(['n1' => []], ['role' => 'tecnico', 'employee_id' => 1]), 'DV11: status sem N2 nao quebra');
$statusFilter = '/\$status = GerenciadorPausas::status_para_o_perfil\(\$gerenciador->obter_status\(\), (portal_actor\(\$role\)|\$actor)\);/';
assert_same(1, preg_match($statusFilter, $status), 'status.php: filtro da DV11 sobre o obter_status, pela sessao');
assert_same(1, preg_match($statusFilter, $dashboard), 'dashboard.php: filtro da DV11 sobre o obter_status (active_pauses incluido)');
assert_same(1, preg_match('/\$role = require_portal_auth\(\);\s+\$actor = portal_actor\(\$role\);/', $dashboard), 'dashboard.php: $actor da sessao, antes do filtro');
assert_same(2, substr_count($dashboard, 'obter_status()') + substr_count($status, 'obter_status()'), 'status.php e dashboard.php: uma leitura do obter_status cada, ja filtrada');

echo "QA operational view OK ({$checks} verificacoes)\n";
