<?php
declare(strict_types=1);

// ============================================================================
// QA da saida antiga de escala (Parte B, F2B; aprovacao de 2026-10-07)
//
// Condicao da aprovacao do F2B: o relatorio operacional (JSON), o CSV e o
// Calendario devolvem exatamente o mesmo resultado de antes do F2B para toda
// regra que nao e "cycle". As mudancas P3, P4 e ausencia valem so na tela
// Escala neste lote; Calendario e Relatorios trocam de vez no F5.
//
// Como: um banco simulado em memoria com todos os tipos de regra antigos,
// historico (troca no meio do periodo, escala removida, linha vazia),
// excecoes de todos os tipos (inclusive em fim de semana), ausencias
// aprovadas e pendentes, inativo, fora do Mapa e Lideranca. A saida de
// report(), streamReportCsv() e listCalendar() e comparada, byte a byte, com
// a referencia gravada em scripts/fixtures/schedule-legacy-output.json
// (sha256, bytes e linhas de cada saida).
//
// A referencia foi gravada com o codigo ANTERIOR ao F2B (commit c941f6c),
// com --gravar-referencia. Nao regravar depois do F2B: se a comparacao
// falhar, a saida mudou e isso precisa de decisao do responsavel.
//
// Uso: php scripts/qa-schedule-legacy-output.php [--gravar-referencia | --despejar DIR]
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-legacy-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
define('CHRONODESK_STATELESS', true);

$GLOBALS['qa_pdo'] = null;
function get_db_connection() {
    if (!$GLOBALS['qa_pdo'] instanceof PDO) {
        throw new PDOException('SQLSTATE[HY000]: banco simulado ausente');
    }
    return $GLOBALS['qa_pdo'];
}

require_once __DIR__ . '/../services/OperationalService.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

const QA_LEGACY_REFERENCE = __DIR__ . '/fixtures/schedule-legacy-output.json';

/**
 * Banco simulado so de leitura: as consultas de escala do relatorio e do
 * calendario sao emuladas; as tabelas que nao tratam de escala (hora extra,
 * ponto, plantao, eventos, pausas, chamados) voltam vazias. Consulta nao
 * prevista falha, para uma consulta nova nao passar sem ser vista.
 */
final class QaLegacyPdo extends PDO {
    public array $employees = [];
    public array $rules = [];
    public array $exceptions = [];
    public array $absences = [];

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaLegacyStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }

    public function inTransaction(): bool {
        return false;
    }
}

final class QaLegacyStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaLegacyPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = $this->sql;
        $db = $this->pdo;
        $this->rows = [];
        foreach (['portal_overtime_entries', 'portal_time_adjustments', 'portal_oncall_shifts', 'portal_calendar_events', 'FROM pausas p', 'portal_critical_incidents'] as $empty) {
            if (strpos($sql, $empty) !== false) {
                return true;
            }
        }
        // Regras com cadastro (scheduleRules), na forma exata do servico.
        if (strpos($sql, 'SELECT r.id, r.employee_id, f.nome AS employee_name, f.ad_login, LOWER(f.equipe) AS team, r.rule_type, r.rule_config,') === 0) {
            if (isset($params[':period_from']) && strpos($sql, 'AND (r.effective_until IS NULL OR (r.effective_until >= :period_from AND r.effective_until >= r.effective_from))') === false) {
                throw new PDOException('SQLSTATE[HY000]: banco simulado: filtro de periodo nao previsto');
            }
            foreach ($db->rules as $rule) {
                $employee = $db->employees[$rule['employee_id']];
                $team = strtolower($employee['equipe']);
                if ((int)$employee['ativo'] !== 1 || !in_array($team, ['n1', 'n2'], true)
                    || (strpos($sql, 'f.fora_mapa_escala = 0') !== false && (int)$employee['fora_mapa_escala'] === 1)
                    || (isset($params[':team']) && $team !== $params[':team'])
                    || (isset($params[':employee_id']) && $rule['employee_id'] !== $params[':employee_id'])
                    || (isset($params[':period_from']) && $rule['effective_until'] !== null
                        && ($rule['effective_until'] < $params[':period_from'] || $rule['effective_until'] < $rule['effective_from']))) {
                    continue;
                }
                $this->rows[] = [
                    'id' => $rule['id'], 'employee_id' => $rule['employee_id'], 'employee_name' => $employee['nome'],
                    'ad_login' => $employee['ad_login'], 'team' => $team, 'rule_type' => $rule['rule_type'],
                    'rule_config' => $rule['rule_config'], 'effective_from' => $rule['effective_from'],
                    'effective_until' => $rule['effective_until'], 'created_by' => 'qa', 'updated_by' => 'qa',
                    'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
                ];
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['employee_name'], $b['effective_from'], $b['id']] <=> [$b['employee_name'], $a['effective_from'], $a['id']]);
            return true;
        }
        // Excecoes do periodo (filtro de equipe pela coluna da excecao).
        if (strpos($sql, 'SELECT id, employee_id, employee_name, team, exception_date, exception_type, note,') === 0) {
            foreach ($db->exceptions as $row) {
                if ($row['exception_date'] < $params[':date_from'] || $row['exception_date'] > $params[':date_to']
                    || (isset($params[':team']) && $row['team'] !== $params[':team'])
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])) {
                    continue;
                }
                $this->rows[] = $row + ['note' => null, 'created_by' => 'qa', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['exception_date'], $a['employee_name']] <=> [$b['exception_date'], $b['employee_name']]);
            return true;
        }
        // Ausencias aprovadas que tiram o dia da escala (cadastro ativo).
        if (strpos($sql, 'SELECT a.employee_id, a.starts_on, a.ends_on FROM portal_absences a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 1') === 0) {
            foreach ($db->absences as $row) {
                $employee = $db->employees[$row['employee_id']];
                if ((int)$employee['ativo'] !== 1 || $row['status'] !== 'approved'
                    || $row['starts_on'] > $params[':date_to'] || $row['ends_on'] < $params[':date_from']
                    || (isset($params[':team']) && strtolower($employee['equipe']) !== $params[':team'])
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])) {
                    continue;
                }
                $this->rows[] = ['employee_id' => $row['employee_id'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on']];
            }
            return true;
        }
        // Bloco de ausencias do calendario (SEC-07 fica para o F3).
        if (strpos($sql, 'SELECT id, employee_name AS title, reason AS description,') === 0) {
            foreach ($db->absences as $row) {
                if ($row['starts_on'] > $params[':date_to'] || $row['ends_on'] < $params[':date_from']
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])
                    || (isset($params[':status']) && $row['status'] !== $params[':status'])) {
                    continue;
                }
                $this->rows[] = [
                    'id' => $row['id'], 'title' => $db->employees[$row['employee_id']]['nome'], 'description' => 'motivo ficticio',
                    'starts_at' => $row['starts_on'] . ' 00:00:00', 'ends_at' => $row['ends_on'] . ' 23:59:59',
                    'team' => null, 'employee_id' => $row['employee_id'], 'status' => $row['status'],
                ];
            }
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }
}

/**
 * Massa ficticia (nenhum dado real): todos os tipos de regra antigos, com
 * historico, e os casos de borda que a saida antiga trata.
 */
function qa_legacy_db(): QaLegacyPdo {
    $pdo = new QaLegacyPdo();
    $employee = static fn (string $nome, string $equipe, string $login, int $ativo = 1, int $foraMapa = 0): array => [
        'nome' => $nome, 'equipe' => $equipe, 'ad_login' => $login, 'ativo' => $ativo, 'fora_mapa_escala' => $foraMapa,
    ];
    $pdo->employees = [
        1 => $employee('Tecnico A', 'n1', 'qa.a'),
        2 => $employee('Tecnico B', 'N2', 'qa.b'),
        3 => $employee('Tecnico C', 'n1', 'qa.c'),
        4 => $employee('Tecnico D', 'n2', 'qa.d'),
        5 => $employee('Tecnico E', 'n1', 'qa.e'),
        6 => $employee('Tecnico F', 'n2', 'qa.f'),
        7 => $employee('Lider G', 'Liderança', 'qa.g'),
        8 => $employee('Tecnico H', 'n1', 'qa.h', 0),
        9 => $employee('Tecnico I', 'n2', 'qa.i', 1, 1),
        10 => $employee('Tecnico J', 'n1', 'qa.j'),
    ];
    $rule = static fn (int $id, int $employeeId, string $type, string $from, ?string $until, ?string $config = null): array => [
        'id' => $id, 'employee_id' => $employeeId, 'rule_type' => $type, 'rule_config' => $config,
        'effective_from' => $from, 'effective_until' => $until,
    ];
    foreach ([
        // Troca no meio do periodo: pares ate 04/10, dias fixos a partir de 05/10.
        $rule(11, 1, 'even_days', '2026-09-01', '2026-10-04'),
        $rule(12, 1, 'fixed_weekdays', '2026-10-05', null, '{"weekdays":["mon","wed","fri"]}'),
        $rule(21, 2, 'odd_days', '2026-08-01', null),
        $rule(31, 3, 'always_onsite', '2026-07-01', null),
        // Comeca no meio do periodo.
        $rule(41, 4, 'always_remote', '2026-09-20', null),
        // Escala removida pelo codigo anterior ao F1 (undefined fechada) e depois nada.
        $rule(51, 5, 'undefined', '2026-08-01', '2026-09-25'),
        // undefined aberta: conta como remoto na regra antiga.
        $rule(61, 6, 'undefined', '2026-09-01', null),
        // Inativo e fora do Mapa (o relatorio antigo inclui o fora do Mapa).
        $rule(81, 8, 'even_days', '2026-09-01', null),
        $rule(91, 9, 'always_onsite', '2026-09-01', null),
        // Linha vazia (fim antes do inicio) e regra aberta.
        $rule(101, 10, 'odd_days', '2026-09-01', null),
        $rule(102, 10, 'always_onsite', '2026-10-10', '2026-10-09'),
        // Dias fixos com configuracao invalida (dias desconhecidos viram lista vazia).
        $rule(103, 3, 'fixed_weekdays', '2026-06-01', '2026-06-30', '{"weekdays":["xyz"]}'),
    ] as $row) {
        $pdo->rules[$row['id']] = $row;
    }
    foreach ([
        [201, 1, 'n1', '2026-10-07', 'remote'],
        [202, 2, 'n2', '2026-09-19', 'onsite'],
        [203, 3, 'n1', '2026-10-08', 'day_off'],
        [204, 3, 'n1', '2026-10-09', 'vacation'],
        [205, 4, 'n2', '2026-09-22', 'absence'],
        [206, 4, 'n2', '2026-09-23', 'leave'],
        [207, 2, 'n2', '2026-10-01', 'training'],
        [208, 1, 'n1', '2026-09-17', 'oncall'],
        [209, 6, 'n2', '2026-10-02', 'onsite'],
        [210, 7, 'lideranca', '2026-10-06', 'training'],
        [211, 3, 'n1', '2026-10-03', 'oncall'],
        [212, 5, 'n1', '2026-09-24', 'remote'],
    ] as [$id, $employeeId, $team, $date, $type]) {
        $pdo->exceptions[$id] = [
            'id' => $id, 'employee_id' => $employeeId, 'employee_name' => $pdo->employees[$employeeId]['nome'],
            'team' => $team, 'exception_date' => $date, 'exception_type' => $type,
        ];
    }
    $pdo->absences = [
        ['id' => 301, 'employee_id' => 2, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14', 'status' => 'approved'],
        ['id' => 302, 'employee_id' => 3, 'starts_on' => '2026-09-25', 'ends_on' => '2026-09-25', 'status' => 'pending'],
        ['id' => 303, 'employee_id' => 8, 'starts_on' => '2026-09-28', 'ends_on' => '2026-09-30', 'status' => 'approved'],
        ['id' => 304, 'employee_id' => 1, 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-18', 'status' => 'approved'],
    ];
    $GLOBALS['qa_pdo'] = $pdo;
    return $pdo;
}

/** Saida de cada consumidor antigo, nas variacoes de filtro usadas pelas telas. */
function qa_legacy_outputs(OperationalService $service): array {
    $outputs = [];
    foreach ([
        'report_competencia' => ['competency' => '2026-10'],
        'report_n2' => ['from' => '2026-09-16', 'to' => '2026-10-15', 'team' => 'n2'],
        'report_colaborador_1' => ['from' => '2026-09-16', 'to' => '2026-10-15', 'employee_id' => '1'],
        'report_ano' => ['from' => '2026-01-01', 'to' => '2026-12-31'],
    ] as $name => $filters) {
        $report = $service->report($filters);
        $outputs[$name] = $report;
        ob_start();
        $service->streamReportCsv($report);
        $outputs[$name . '_csv'] = ob_get_clean();
    }
    foreach ([
        'calendar_outubro' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        'calendar_schedule' => ['from' => '2026-09-16', 'to' => '2026-10-15', 'type' => 'schedule'],
        'calendar_onsite' => ['from' => '2026-09-16', 'to' => '2026-10-15', 'type' => 'onsite'],
        'calendar_remote' => ['from' => '2026-09-16', 'to' => '2026-10-15', 'type' => 'remote'],
        'calendar_n1' => ['from' => '2026-09-16', 'to' => '2026-10-15', 'team' => 'n1'],
        'calendar_colaborador_3' => ['from' => '2026-09-16', 'to' => '2026-10-15', 'employee_id' => '3'],
    ] as $name => $filters) {
        $outputs[$name] = $service->listCalendar($filters);
    }
    return $outputs;
}

/** JSON canonico de uma saida (o CSV entra como texto). */
function qa_legacy_encode($output): string {
    return json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Linhas de uma saida: itens da escala, do calendario ou do CSV. */
function qa_legacy_rows($output): int {
    if (is_string($output)) {
        return substr_count($output, "
");
    }
    return count($output['schedule'] ?? $output['items'] ?? []);
}

/**
 * Referencia: sha256, tamanho e linhas de cada saida (byte a byte, sem
 * guardar ~1 MB de saida no repositorio). Para ver a diferenca de uma saida
 * que mudou: --despejar DIR grava as saidas em DIR; rode no commit c941f6c e
 * no atual e compare os arquivos.
 */
function qa_legacy_signature(array $outputs): array {
    $signature = [];
    foreach ($outputs as $name => $output) {
        $encoded = qa_legacy_encode($output);
        $signature[$name] = ['sha256' => hash('sha256', $encoded), 'bytes' => strlen($encoded), 'linhas' => qa_legacy_rows($output)];
    }
    return $signature;
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}
"
            . '  esperado: ' . var_export($expected, true) . "
"
            . '  obtido:   ' . var_export($actual, true) . "
");
        exit(1);
    }
}

$service = new OperationalService(qa_legacy_db(), '2026-10-07');
$outputs = qa_legacy_outputs($service);
$signature = qa_legacy_signature($outputs);

$dumpAt = array_search('--despejar', $argv, true);
if ($dumpAt !== false) {
    $dir = $argv[$dumpAt + 1] ?? '';
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "Uso: --despejar DIR (diretorio existente)
");
        exit(1);
    }
    foreach ($outputs as $name => $output) {
        file_put_contents($dir . '/' . $name . (is_string($output) ? '.csv' : '.json'), is_string($output)
            ? $output
            : json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "
");
    }
    echo 'Saidas gravadas em ' . $dir . "
";
    exit(0);
}

if (in_array('--gravar-referencia', $argv, true)) {
    if (!is_dir(dirname(QA_LEGACY_REFERENCE))) {
        mkdir(dirname(QA_LEGACY_REFERENCE), 0775, true);
    }
    file_put_contents(QA_LEGACY_REFERENCE, json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "
");
    echo 'Referencia gravada: ' . QA_LEGACY_REFERENCE . "
";
    exit(0);
}

$reference = file_get_contents(QA_LEGACY_REFERENCE);
assert_same(true, is_string($reference) && $reference !== '', 'le a referencia gravada antes do F2B');
$expected = json_decode($reference, true, 512, JSON_THROW_ON_ERROR);

// A massa precisa exercitar o que a referencia diz cobrir.
$schedule = $outputs['report_ano']['schedule'];
assert_same(true, count($schedule) > 300, 'massa: escala do ano com volume');
foreach (['even_days', 'odd_days', 'always_onsite', 'always_remote', 'undefined', 'fixed_weekdays'] as $type) {
    assert_same(true, in_array($type, array_column($schedule, 'rule_type'), true), "massa: cobre {$type}");
}
assert_same(true, in_array('exception', array_column($schedule, 'source'), true), 'massa: cobre excecao');
$presenceTypes = array_values(array_unique(array_column($schedule, 'presence_type')));
sort($presenceTypes);
assert_same(['absence', 'day_off', 'onsite', 'remote'], $presenceTypes, 'massa: presencial, remoto, folga e ausencia');
$weekendRows = array_filter($schedule, static fn (array $row): bool => (int)(new DateTimeImmutable($row['date']))->format('N') >= 6);
assert_same(true, count($weekendRows) > 0, 'massa: cobre fim de semana (a regra antiga gera sabado e domingo)');

assert_same(array_keys($expected), array_keys($signature), 'mesmas saidas da referencia');
foreach ($expected as $name => $reference) {
    assert_same($reference, $signature[$name], "saida {$name} identica a referencia anterior ao F2B (sha256, bytes, linhas)");
}

echo 'QA schedule legacy output OK (' . count($expected) . " saidas identicas a referencia anterior ao F2B)\n";
