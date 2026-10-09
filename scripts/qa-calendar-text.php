<?php
declare(strict_types=1);

// ============================================================================
// QA do texto livre no Calendario e na lista de plantoes (SEC-07b)
//
// 1. listCalendar, blocos de pausa, hora extra, ajuste de ponto e plantao:
//    todos veem o evento (D1); o texto livre (motivo, justificativa,
//    observacao), so a gestao e o proprio colaborador pelo employee_id da
//    sessao (D4). Motivo de pausa da lista fechada de validate_motivo_pausa
//    aparece para todos; fora dela vira "Pausa" para os demais (D2).
// 2. listOncall: observacao e logins de quem cadastrou e alterou so para a
//    gestao e o proprio (D3).
// 3. Estrutura: oncall.php passa o ator; a lista de motivos nao e duplicada.
// Ponta a ponta (endpoints reais por perfil) no qa-security.
// Sem banco: um PDO simulado responde so as consultas desses blocos.
//
// Uso: php scripts/qa-calendar-text.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-calendar-text-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
define('CHRONODESK_STATELESS', true);

require_once __DIR__ . '/../services/OperationalService.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

/**
 * Registros ficticios (nenhum dado real): [tipo][id] = [employee_id, texto].
 * Colaborador 1 e o tecnico da sessao; 2 e outro colaborador; 0, registro
 * antigo sem colaborador (nunca e o "proprio", nem de sessao sem colaborador).
 */
const QA_RECORDS = [
    'pause' => [
        11 => [1, 'Café'],
        12 => [2, 'Pessoal'],
        13 => [2, 'texto livre antigo B'],
        14 => [1, 'texto livre antigo A'],
        15 => [2, null],
        16 => [2, 'café'],
        17 => [0, 'texto livre sem colaborador'],
    ],
    'overtime' => [21 => [1, 'motivo HE A'], 22 => [2, 'motivo HE B'], 23 => [0, 'motivo HE sem colaborador']],
    'time_adjustment' => [31 => [1, 'justificativa A'], 32 => [2, 'justificativa B'], 33 => [0, 'justificativa sem colaborador']],
    'oncall' => [41 => [1, 'observacao A'], 42 => [2, 'observacao B'], 43 => [2, null], 44 => [0, 'observacao sem colaborador']],
];

final class QaCalendarTextPdo extends PDO {
    public array $queries = [];

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaCalendarTextStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }
}

final class QaCalendarTextStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaCalendarTextPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $this->pdo->queries[] = [$this->sql, $params];
        $this->rows = [];
        $own = isset($params[':employee_id']) ? (int)$params[':employee_id'] : null;
        // Blocos do Calendario: a coluna de texto sai como description.
        $calendar = '/^SELECT (?:p\.id, p\.nome_funcionario AS title, p\.motivo_pausa|id, employee_name AS title, (?:reason|justification|note)) AS description, .* FROM (pausas p|portal_overtime_entries|portal_time_adjustments|portal_oncall_shifts) WHERE /';
        if (preg_match($calendar, $this->sql, $match) === 1) {
            $type = ['pausas p' => 'pause', 'portal_overtime_entries' => 'overtime', 'portal_time_adjustments' => 'time_adjustment', 'portal_oncall_shifts' => 'oncall'][$match[1]];
            foreach (QA_RECORDS[$type] as $id => [$employeeId, $text]) {
                if ($own === null || $own === $employeeId) {
                    $this->rows[] = [
                        'id' => $id, 'title' => 'Colaborador ' . $employeeId, 'description' => $text,
                        'starts_at' => '2026-10-07 08:00:00', 'ends_at' => '2026-10-07 09:00:00',
                        'team' => 'n1', 'employee_id' => $employeeId, 'status' => 'approved',
                    ];
                }
            }
            return true;
        }
        if (strpos($this->sql, 'SELECT id, employee_id, employee_name, team, starts_on, ends_on, start_time, end_time, shift_type, note, status, created_by, updated_by, created_at, updated_at FROM portal_oncall_shifts') === 0) {
            foreach (QA_RECORDS['oncall'] as $id => [$employeeId, $text]) {
                if ($own === null || $own === $employeeId) {
                    $this->rows[] = [
                        'id' => $id, 'employee_id' => $employeeId, 'employee_name' => 'Colaborador ' . $employeeId, 'team' => 'n1',
                        'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08', 'start_time' => '18:00:00', 'end_time' => '08:00:00',
                        'shift_type' => 'oncall', 'note' => $text, 'status' => 'active', 'created_by' => 'qa.gestor',
                        'updated_by' => 'qa.lider', 'created_at' => null, 'updated_at' => null,
                    ];
                }
            }
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

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$service = new OperationalService(new QaCalendarTextPdo(), '2026-10-07');

/** Descricao de cada evento do bloco, por id do registro. */
$calendarText = static function (string $type, array $actor, array $filters = []) use ($service): array {
    $result = $service->listCalendar($filters + ['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => $type], $actor);
    $texts = [];
    foreach ($result['items'] as $event) {
        assert_same($type, $event['event_type'], "SEC-07b: so eventos do tipo {$type}");
        $texts[(int)substr($event['id'], strlen($type) + 1)] = $event['description'];
    }
    ksort($texts);
    return $texts;
};

$actors = [
    'gestor' => ['role' => 'gestor', 'employee_id' => null],
    'lideranca' => ['role' => 'lideranca', 'employee_id' => null],
    'admin' => ['role' => 'admin', 'employee_id' => null],
    'tecnico' => ['role' => 'tecnico', 'employee_id' => 1],
    'tecnico_sem_colaborador' => ['role' => 'tecnico', 'employee_id' => 0],
    'somente_leitura' => ['role' => 'somente_leitura', 'employee_id' => 1],
    'somente_leitura_sem_colaborador' => ['role' => 'somente_leitura', 'employee_id' => 0],
    'sem_ator' => [],
];
$isManager = static fn (string $name): bool => in_array($name, ['gestor', 'lideranca', 'admin'], true);
$ownId = static fn (array $actor): int => (int)($actor['employee_id'] ?? 0);

// ----------------------------------------------------------------------------
// 1. Calendario: pausa (D2)
// ----------------------------------------------------------------------------
foreach ($actors as $name => $actor) {
    $expected = [];
    foreach (QA_RECORDS['pause'] as $id => [$employeeId, $text]) {
        $sees = $isManager($name) || ($ownId($actor) > 0 && $ownId($actor) === $employeeId);
        $expected[$id] = $sees || $text === null ? (string)$text : (validate_motivo_pausa($text) ?? 'Pausa');
    }
    assert_same($expected, $calendarText('pause', $actor), "SEC-07b pausa: {$name}");
}
// Casos fixos, sem depender da regra acima.
assert_same([11 => 'Café', 12 => 'Pessoal', 13 => 'texto livre antigo B', 14 => 'texto livre antigo A', 15 => '', 16 => 'café', 17 => 'texto livre sem colaborador'], $calendarText('pause', $actors['gestor']), 'SEC-07b pausa: gestao ve o valor gravado');
assert_same([11 => 'Café', 12 => 'Pessoal', 13 => 'Pausa', 14 => 'texto livre antigo A', 15 => '', 16 => 'Pausa', 17 => 'Pausa'], $calendarText('pause', $actors['tecnico']), 'SEC-07b pausa: tecnico ve a lista fechada, o proprio texto e "Pausa" no texto de outro');
assert_same([11 => 'Café', 12 => 'Pessoal', 13 => 'Pausa', 14 => 'Pausa', 15 => '', 16 => 'Pausa', 17 => 'Pausa'], $calendarText('pause', $actors['tecnico_sem_colaborador']), 'SEC-07b pausa: sem colaborador na sessao, nenhum texto fora da lista');
// Tecnico pedindo outro colaborador pelo parametro: o evento aparece (D1), sem o texto fora da lista.
assert_same([12 => 'Pessoal', 13 => 'Pausa', 15 => '', 16 => 'Pausa'], $calendarText('pause', $actors['tecnico'], ['employee_id' => '2']), 'SEC-07b pausa: tecnico pedindo outro colaborador');

// ----------------------------------------------------------------------------
// 2. Calendario: hora extra, ajuste de ponto e plantao (D1, D4)
// ----------------------------------------------------------------------------
foreach (['overtime', 'time_adjustment', 'oncall'] as $type) {
    foreach ($actors as $name => $actor) {
        $expected = [];
        foreach (QA_RECORDS[$type] as $id => [$employeeId, $text]) {
            $sees = $isManager($name) || ($ownId($actor) > 0 && $ownId($actor) === $employeeId);
            $expected[$id] = $sees ? (string)$text : '';
        }
        assert_same($expected, $calendarText($type, $actor), "SEC-07b {$type}: {$name}");
    }
    $own = array_key_first(QA_RECORDS[$type]);
    $other = $own + 1;
    assert_same([$own => QA_RECORDS[$type][$own][1], $other => QA_RECORDS[$type][$other][1]], array_slice($calendarText($type, $actors['lideranca']), 0, 2, true), "SEC-07b {$type}: Lideranca ve os textos");
    assert_same([$own => QA_RECORDS[$type][$own][1], $other => ''], array_slice($calendarText($type, $actors['tecnico']), 0, 2, true), "SEC-07b {$type}: tecnico ve so o proprio texto");
    // Tecnico pedindo outro colaborador: eventos aparecem, textos vazios.
    $others = $calendarText($type, $actors['tecnico'], ['employee_id' => '2']);
    assert_same(true, count($others) > 0, "SEC-07b {$type}: tecnico pedindo outro colaborador ve os eventos");
    assert_same(array_fill_keys(array_keys($others), ''), $others, "SEC-07b {$type}: tecnico pedindo outro colaborador nao ve os textos");
}

// ----------------------------------------------------------------------------
// 3. Lista de plantoes (D3)
// ----------------------------------------------------------------------------
$private = ['note', 'created_by', 'updated_by'];
foreach ($actors as $name => $actor) {
    foreach ([[], ['employee_id' => '2']] as $filters) {
        $rows = $service->listOncall($filters + ['from' => '2026-10-01', 'to' => '2026-10-31'], $actor);
        assert_same(true, count($rows) > 0, "SEC-07b plantoes: {$name} ve os plantoes");
        foreach ($rows as $row) {
            $sees = $isManager($name) || ($ownId($actor) > 0 && $ownId($actor) === $row['employee_id']);
            $label = "SEC-07b plantoes: {$name}, plantao {$row['id']}" . ($filters ? ' (filtro de outro colaborador)' : '');
            assert_same($sees ? $private : [], array_values(array_intersect($private, array_keys($row))), $label . ': campos privados');
            assert_same(['id', 'employee_id', 'employee_name', 'team', 'starts_on', 'ends_on', 'start_time', 'end_time', 'shift_type', 'status'], array_values(array_diff(array_keys($row), $private, ['created_at', 'updated_at'])), $label . ': demais campos');
            if ($sees) {
                assert_same([QA_RECORDS['oncall'][$row['id']][1], 'qa.gestor', 'qa.lider'], [$row['note'], $row['created_by'], $row['updated_by']], $label . ': valores');
            }
        }
    }
}
$technicianOncall = $service->listOncall(['from' => '2026-10-01', 'to' => '2026-10-31'], $actors['tecnico']);
assert_same([41 => true, 42 => false, 43 => false, 44 => false], array_combine(array_column($technicianOncall, 'id'), array_map(static fn (array $row): bool => array_key_exists('note', $row), $technicianOncall)), 'SEC-07b plantoes: tecnico ve so a propria observacao');

// ----------------------------------------------------------------------------
// 4. Estrutura
// ----------------------------------------------------------------------------
$root = dirname(__DIR__);
assert_same(true, strpos((string)file_get_contents($root . '/api/portal/oncall.php'), '$service->listOncall($_GET, portal_actor($role))') !== false, 'SEC-07b: oncall.php passa o ator');
$serviceSource = (string)file_get_contents($root . '/services/OperationalService.php');
assert_same(true, strpos($serviceSource, "validate_motivo_pausa(\$row['description']) ?? 'Pausa'") !== false, 'SEC-07b: motivo comparado com validate_motivo_pausa');
assert_same(0, preg_match("/'Caf\x{e9}'|'Pessoal'|'Reuni\x{e3}o'/u", $serviceSource), 'SEC-07b: lista de motivos nao duplicada no servico');

echo "QA calendar text OK\n";
