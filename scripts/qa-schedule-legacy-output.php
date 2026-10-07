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
// F3 (commit 1, SEC-07 e L-01): o bloco de ausencias do Calendario mudou
// por RBAC aprovado. As tres saidas completas do Calendario que trazem
// ausencias ficam na referencia como registro, e a comparacao passa para as
// versoes "_sem_ausencia", gravadas tambem com o codigo de c941f6c (as 14
// assinaturas antigas sairam identicas nessa gravacao).
//
// Secao 2: os consumidores do ciclo fora da tela Escala (Relatorio, CSV,
// Calendario e Mapa PA), que antes do F2B davam 400 com uma regra cycle.
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
    /** [F2B] Vinculos de PA (secao 2 deste QA: Mapa PA com ciclo). */
    public array $paAssignments = [];
    public int $nextId = 1000;
    private bool $transaction = false;

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaLegacyStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }

    public function beginTransaction(): bool {
        return $this->transaction = true;
    }

    public function commit(): bool {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool {
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool {
        return $this->transaction;
    }

    public function lastInsertId(?string $name = null): string|false {
        return (string)($this->nextId - 1);
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
        // Bloco de ausencias do calendario: forma anterior ao F3 (com o motivo)
        // e a do F3 commit 1 (com o tipo, sem o motivo; SEC-07 e L-01).
        $absenceBeforeF3 = strpos($sql, 'SELECT id, employee_name AS title, reason AS description,') === 0;
        if ($absenceBeforeF3 || strpos($sql, 'SELECT id, employee_name AS title, category,') === 0) {
            foreach ($db->absences as $row) {
                if ($row['starts_on'] > $params[':date_to'] || $row['ends_on'] < $params[':date_from']
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])
                    || (isset($params[':status']) && $row['status'] !== $params[':status'])
                    || (strpos($sql, 'AND status = "approved"') !== false && $row['status'] !== 'approved')) {
                    continue;
                }
                $this->rows[] = [
                    'id' => $row['id'], 'title' => $db->employees[$row['employee_id']]['nome'],
                ] + ($absenceBeforeF3 ? ['description' => 'motivo ficticio'] : ['category' => $row['category']]) + [
                    'starts_at' => $row['starts_on'] . ' 00:00:00', 'ends_at' => $row['ends_on'] . ' 23:59:59',
                    'team' => null, 'employee_id' => $row['employee_id'], 'status' => $row['status'],
                ];
            }
            return true;
        }
        // [F2B] Mapa PA: so as consultas do PaMapService.
        if ($sql === 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name') {
            $this->rows[] = ['total' => $params[':table_name'] === 'portal_pa_map' ? 0 : 1];
            return true;
        }
        if (strpos($sql, 'SELECT id, nome AS name, equipe AS team, ad_login FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0') === 0) {
            foreach ($db->employees as $id => $row) {
                if ((int)$row['ativo'] === 1 && (int)$row['fora_mapa_escala'] === 0) {
                    $this->rows[] = ['id' => $id, 'name' => $row['nome'], 'team' => $row['equipe'], 'ad_login' => $row['ad_login']];
                }
            }
            return true;
        }
        if ($sql === 'SELECT id, nome AS name, equipe AS team, ad_login, fora_mapa_escala FROM funcionarios WHERE id = :id AND ativo = 1 LIMIT 1 FOR UPDATE') {
            $row = $db->employees[(int)$params[':id']] ?? null;
            if ($row !== null && (int)$row['ativo'] === 1) {
                $this->rows[] = ['id' => (int)$params[':id'], 'name' => $row['nome'], 'team' => $row['equipe'], 'ad_login' => $row['ad_login'], 'fora_mapa_escala' => $row['fora_mapa_escala']];
            }
            return true;
        }
        if ($sql === 'SELECT exception_type FROM portal_schedule_exceptions WHERE employee_id = :employee_id AND exception_date = :work_date LIMIT 1') {
            foreach ($db->exceptions as $row) {
                if ($row['employee_id'] === $params[':employee_id'] && $row['exception_date'] === $params[':work_date']) {
                    $this->rows[] = ['exception_type' => $row['exception_type']];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT rule_type, rule_config FROM portal_schedule_rules WHERE employee_id = :employee_id AND effective_from <= :work_date_from') === 0) {
            $found = null;
            foreach ($db->rules as $rule) {
                if ($rule['employee_id'] === $params[':employee_id'] && $rule['effective_from'] <= $params[':work_date_from']
                    && ($rule['effective_until'] === null || $rule['effective_until'] >= $params[':work_date_until'])
                    && ($found === null || $rule['effective_from'] > $found['effective_from'])) {
                    $found = $rule;
                }
            }
            if ($found !== null) {
                $this->rows[] = ['rule_type' => $found['rule_type'], 'rule_config' => $found['rule_config']];
            }
            return true;
        }
        $overlaps = static fn (array $row, array $params): bool => $row['active'] === 1
            && (int)$row['id'] !== (int)($params[':id'] ?? 0)
            && $row['valid_from'] <= $params[':new_until']
            && ($row['valid_until'] === null || $row['valid_until'] >= $params[':new_from']);
        if (strpos($sql, 'SELECT id, pa_number FROM portal_pa_assignments WHERE active = 1 AND employee_id = :employee_id') === 0) {
            foreach ($db->paAssignments as $row) {
                if ($row['employee_id'] === $params[':employee_id'] && $overlaps($row, $params)) {
                    $this->rows[] = ['id' => $row['id'], 'pa_number' => $row['pa_number']];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, schedule_rule_type, employee_name FROM portal_pa_assignments WHERE active = 1 AND pa_number = :pa_number') === 0) {
            foreach ($db->paAssignments as $row) {
                if ($row['pa_number'] === $params[':pa_number'] && $overlaps($row, $params)) {
                    $this->rows[] = ['id' => $row['id'], 'schedule_rule_type' => $row['schedule_rule_type'], 'employee_name' => $row['employee_name']];
                }
            }
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_pa_assignments (') === 0) {
            // ENUM de schedule_rule_type (migrations 009 e 010), como o MySQL em modo estrito.
            if (!in_array($params[':schedule_rule_type'], ['even_days', 'odd_days', 'always_onsite', 'always_remote', 'undefined', 'fixed_weekdays'], true)) {
                throw new PDOException("SQLSTATE[01000]: Warning: 1265 Data truncated for column 'schedule_rule_type'");
            }
            $db->paAssignments[] = [
                'id' => $db->nextId++, 'active' => 1, 'pa_number' => $params[':pa_number'], 'employee_id' => $params[':employee_id'],
                'employee_name' => $params[':employee_name'], 'employee_login' => $params[':employee_login'], 'team' => $params[':team'],
                'schedule_rule_type' => $params[':schedule_rule_type'], 'schedule_rule_config' => $params[':schedule_rule_config'],
                'valid_from' => $params[':valid_from'], 'valid_until' => $params[':valid_until'], 'notes' => $params[':notes'],
                'created_by' => $params[':created_by'], 'updated_by' => $params[':updated_by'],
                'created_at' => '2026-10-07 08:00:00', 'updated_at' => '2026-10-07 08:00:00',
            ];
            return true;
        }
        if (strpos($sql, 'SELECT id, pa_number, employee_id, employee_name, employee_login, team, schedule_rule_type, schedule_rule_config, valid_from, valid_until, notes,') === 0) {
            foreach ($db->paAssignments as $row) {
                if ($row['active'] === 1 && $row['valid_from'] <= $params[':date_until']
                    && ($row['valid_until'] === null || $row['valid_until'] >= $params[':date_from'])) {
                    $this->rows[] = array_diff_key($row, ['active' => true]);
                }
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
        ['id' => 301, 'employee_id' => 2, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14', 'status' => 'approved', 'absence_type' => 'ferias', 'category' => 'ferias'],
        ['id' => 302, 'employee_id' => 3, 'starts_on' => '2026-09-25', 'ends_on' => '2026-09-25', 'status' => 'pending', 'absence_type' => 'atestado', 'category' => 'ausencia_justificada'],
        ['id' => 303, 'employee_id' => 8, 'starts_on' => '2026-09-28', 'ends_on' => '2026-09-30', 'status' => 'approved', 'absence_type' => 'folga', 'category' => 'folga'],
        ['id' => 304, 'employee_id' => 1, 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-18', 'status' => 'approved', 'absence_type' => 'justificada', 'category' => 'ausencia_justificada'],
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
        // Visao da gestao (o codigo anterior ao F3 ignora o ator).
        $calendar = $service->listCalendar($filters, ['role' => 'gestor', 'employee_id' => null]);
        $outputs[$name] = $calendar;
        // [F3] O bloco de ausencias mudou por RBAC aprovado (SEC-07, L-01):
        // a escala do Calendario e comparada sem os eventos de ausencia.
        $calendar['items'] = array_values(array_filter($calendar['items'], static fn (array $item): bool => $item['event_type'] !== 'absence'));
        $outputs[$name . '_sem_ausencia'] = $calendar;
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
// [F3 commit 1] Saidas completas do Calendario que trazem ausencias: o bloco
// de ausencias mudou (sem o motivo; tipo so para a gestao e o proprio; SEC-07
// e L-01, aprovados em 2026-09-30). Elas ficam na referencia como registro e
// a comparacao passa para as versoes "_sem_ausencia", gravadas com o codigo de
// c941f6c (decisao 6 do plano do F3, secao 9.4 do desenho).
$changedByF3 = ['calendar_outubro', 'calendar_n1', 'calendar_colaborador_3'];
foreach ($changedByF3 as $name) {
    // So pula saida que, na referencia de c941f6c, trazia eventos de ausencia.
    assert_same(true, ($expected[$name . '_sem_ausencia']['linhas'] ?? 0) < ($expected[$name]['linhas'] ?? 0), "{$name}: na referencia, a saida completa tinha eventos de ausencia");
}
foreach ($expected as $name => $reference) {
    if (in_array($name, $changedByF3, true)) {
        continue;
    }
    assert_same($reference, $signature[$name], "saida {$name} identica a referencia anterior ao F2B (sha256, bytes, linhas)");
}

// ----------------------------------------------------------------------------
// 2. Consumidores do ciclo fora da tela Escala (F2B, decisoes 1 e 2)
// ----------------------------------------------------------------------------
// Relatorio, CSV e Calendario: o dia util do ciclo vem do motor; fim de
// semana nao gera linha. Antes do F2B, uma regra cycle dava 400 nos tres.
$cycleConfig = '{"pattern":["remote","onsite","onsite"],"anchor_date":"2026-10-05","business_days_only":true}';
$withCycle = static function () use ($cycleConfig): QaLegacyPdo {
    $pdo = qa_legacy_db();
    $pdo->employees[11] = ['nome' => 'Tecnico K', 'equipe' => 'n1', 'ad_login' => 'qa.k', 'ativo' => 1, 'fora_mapa_escala' => 0];
    $pdo->employees[12] = ['nome' => 'Tecnico L', 'equipe' => 'n1', 'ad_login' => 'qa.l', 'ativo' => 1, 'fora_mapa_escala' => 0];
    $pdo->employees[13] = ['nome' => 'Tecnico M', 'equipe' => 'n1', 'ad_login' => 'qa.m', 'ativo' => 1, 'fora_mapa_escala' => 0];
    $pdo->rules[111] = ['id' => 111, 'employee_id' => 11, 'rule_type' => 'cycle', 'rule_config' => $cycleConfig, 'effective_from' => '2026-10-05', 'effective_until' => null];
    $pdo->rules[121] = ['id' => 121, 'employee_id' => 12, 'rule_type' => 'even_days', 'rule_config' => null, 'effective_from' => '2026-09-01', 'effective_until' => null];
    $pdo->rules[131] = ['id' => 131, 'employee_id' => 13, 'rule_type' => 'always_remote', 'rule_config' => null, 'effective_from' => '2026-09-01', 'effective_until' => null];
    return $pdo;
};
$service = new OperationalService($withCycle(), '2026-10-07');
$days = static fn (array $rows): string => implode(' ', array_map(
    static fn (array $row): string => substr($row['date'], 8, 2) . ($row['presence_type'] === 'onsite' ? 'P' : 'R'),
    $rows
));
$report = $service->report(['from' => '2026-10-01', 'to' => '2026-10-16', 'employee_id' => '11']);
assert_same('05R 06P 07P 08R 09P 12P 13R 14P 15P 16R', $days($report['schedule']), 'ciclo no relatorio: criterio de aceite, sem fim de semana e nada antes da data inicial');
assert_same([['Ciclo 2P/1R'], ['rule']], [array_values(array_unique(array_column($report['schedule'], 'rule_label'))), array_values(array_unique(array_column($report['schedule'], 'source')))], 'ciclo no relatorio: rotulo e origem');
assert_same([6, 4], [$report['summary']['onsite_days'], $report['summary']['remote_days']], 'ciclo no relatorio: totais');
ob_start();
$service->streamReportCsv($report);
$csv = (string)ob_get_clean();
assert_same(true, strpos($csv, "escala;\"Tecnico K\";N1;2026-10-05;Remoto;remote\n") !== false || strpos($csv, "escala;Tecnico K;N1;2026-10-05;Remoto;remote\n") !== false, 'ciclo no CSV: linha do dia remoto');
assert_same(10, substr_count($csv, "\nescala;"), 'ciclo no CSV: uma linha por dia util');
$calendar = $service->listCalendar(['from' => '2026-10-01', 'to' => '2026-10-16', 'employee_id' => '11', 'type' => 'onsite']);
assert_same(['2026-10-06', '2026-10-07', '2026-10-09', '2026-10-12', '2026-10-14', '2026-10-15'], array_map(static fn (array $event): string => substr($event['starts_at'], 0, 10), $calendar['items']), 'ciclo no Calendario: dias presenciais');
assert_same('Ciclo 2P/1R', $calendar['items'][0]['description'], 'ciclo no Calendario: descricao');
// Excecao em dia de ciclo continua vencendo, como nos outros tipos.
$pdo = $withCycle();
$pdo->exceptions[901] = ['id' => 901, 'employee_id' => 11, 'employee_name' => 'Tecnico K', 'team' => 'n1', 'exception_date' => '2026-10-06', 'exception_type' => 'remote'];
$pdo->exceptions[902] = ['id' => 902, 'employee_id' => 11, 'employee_name' => 'Tecnico K', 'team' => 'n1', 'exception_date' => '2026-10-10', 'exception_type' => 'oncall'];
$report = (new OperationalService($pdo, '2026-10-07'))->report(['from' => '2026-10-05', 'to' => '2026-10-11', 'employee_id' => '11']);
assert_same('05R 06R 07P 08R 09P 10P', $days($report['schedule']), 'ciclo no relatorio: excecao vence (remota na terca, plantao no sabado)');
// Configuracao de ciclo invalida no banco: sem linha, sem 400.
$pdo = $withCycle();
$pdo->rules[111]['rule_config'] = '{"pattern":["onsite"],"anchor_date":"2026-10-05"}';
$report = (new OperationalService($pdo, '2026-10-07'))->report(['from' => '2026-10-05', 'to' => '2026-10-09', 'employee_id' => '11']);
assert_same([], $report['schedule'], 'ciclo invalido no banco: relatorio sem linha e sem 400');
// Com o ciclo no banco, os demais colaboradores saem iguais a referencia.
$pdo = $withCycle();
unset($pdo->employees[11], $pdo->employees[12], $pdo->employees[13], $pdo->rules[111], $pdo->rules[121], $pdo->rules[131]);
assert_same($signature, qa_legacy_signature(qa_legacy_outputs(new OperationalService($pdo, '2026-10-07'))), 'referencia estavel entre execucoes');

// Mapa PA (decisao 2 ajustada): o ciclo vem do motor na exibicao e no aviso
// de alocacao remota; o conflito continua pelo tipo (conservador) ate o F6.
require_once __DIR__ . '/../services/PaMapService.php';
$pdo = $withCycle();
$map = new PaMapService($pdo);
$eligible = [];
foreach (['2026-10-05', '2026-10-06', '2026-10-10'] as $date) {
    foreach ($map->eligibleEmployees($date) as $employee) {
        if ((int)$employee['id'] === 11) {
            $eligible[$date] = [$employee['schedule_rule_type'], $employee['schedule_rule_label'], $employee['schedule_status']];
        }
    }
}
assert_same([
    '2026-10-05' => ['cycle', 'Ciclo 2P/1R', 'remote'],
    '2026-10-06' => ['cycle', 'Ciclo 2P/1R', 'onsite'],
    '2026-10-10' => ['cycle', 'Ciclo 2P/1R', 'no_schedule'],
], $eligible, 'Mapa PA: elegivel com ciclo pelo motor (remoto, presencial, fim de semana)');
$saved = $map->save(['pa_number' => '1730', 'employee_id' => 11, 'valid_from' => '2026-10-06'], 'qa');
assert_same([], $saved['warnings'], 'Mapa PA: ciclo em dia presencial nao gera aviso de remoto');
// O ENUM da copia nao tem 'cycle': grava 'undefined' com a configuracao, e a leitura reconhece o ciclo.
assert_same(['undefined', $cycleConfig], [$pdo->paAssignments[0]['schedule_rule_type'], $pdo->paAssignments[0]['schedule_rule_config']], 'Mapa PA: copia do ciclo dentro do ENUM, com a configuracao');
$active = [];
foreach (['2026-10-06', '2026-10-08', '2026-10-10'] as $date) {
    $item = $map->list(['date' => $date])['items'][0];
    $active[$date] = [$item['active_on_date'], $item['presence_status'], $item['schedule_rule_label']];
}
assert_same([
    '2026-10-06' => [true, 'onsite', 'Ciclo 2P/1R'],
    '2026-10-08' => [false, 'remote', 'Ciclo 2P/1R'],
    '2026-10-10' => [false, 'offsite', 'Ciclo 2P/1R'],
], $active, 'Mapa PA: presencial no dia certo do ciclo, remoto no dia remoto, fora no fim de semana');
// Copia 'undefined' sem pattern (vinculo antigo) continua "Sem escala".
$pdo->paAssignments[] = ['id' => 999, 'active' => 1, 'pa_number' => '1727', 'employee_id' => 12, 'employee_name' => 'Tecnico L', 'employee_login' => 'qa.l', 'team' => 'n1',
    'schedule_rule_type' => 'undefined', 'schedule_rule_config' => null, 'valid_from' => '2026-09-01', 'valid_until' => null, 'notes' => null,
    'created_by' => 'qa', 'updated_by' => 'qa', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];
$types = array_column(array_map(static fn (array $item): array => [$item['employee_id'], $item['schedule_rule_type'] . ' / ' . $item['schedule_rule_label']], $map->list(['date' => '2026-10-06'])['items']), 1, 0);
assert_same([11 => 'cycle / Ciclo 2P/1R', 12 => 'undefined / Sem escala'], $types, 'Mapa PA: copia reconhecida como ciclo pelo pattern; undefined antigo continua sem escala');
$pdo->paAssignments = array_values(array_filter($pdo->paAssignments, static fn (array $row): bool => $row['id'] !== 999));
assert_same('409: Conflito de escala no PA com Tecnico K.', (static function () use ($map): string {
    try {
        $map->save(['pa_number' => '1730', 'employee_id' => 12, 'valid_from' => '2026-10-06'], 'qa');
        return 'ok';
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    }
})(), 'Mapa PA: conflito conservador (ciclo x dias pares no mesmo PA) ate o F6');
$conflictPdo = $withCycle();
$conflictMap = new PaMapService($conflictPdo);
$conflictMap->save(['pa_number' => '1726', 'employee_id' => 12, 'valid_from' => '2026-10-06'], 'qa');
assert_same('409: Conflito de escala no PA com Tecnico L.', (static function () use ($conflictMap): string {
    try {
        $conflictMap->save(['pa_number' => '1726', 'employee_id' => 11, 'valid_from' => '2026-10-06'], 'qa');
        return 'ok';
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    }
})(), 'Mapa PA: conflito conservador tambem quando o vinculo novo e o de ciclo');
$remoteSaved = $map->save(['pa_number' => '1730', 'employee_id' => 13, 'valid_from' => '2026-10-06', 'confirm_remote_allocation' => true], 'qa');
assert_same(['Colaborador marcado como remoto nesta data.'], $remoteSaved['warnings'], 'Mapa PA: sempre remoto continua avisando e sem conflito');
// Ciclo em dia remoto: mesmo aviso e confirmacao do sempre remoto.
$pdo = $withCycle();
$map = new PaMapService($pdo);
$outcome = static function (callable $call): string {
    try {
        $call();
        return 'ok';
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    }
};
assert_same('409: Colaborador marcado como remoto nesta data. Confirme para alocar mesmo assim.', $outcome(fn () => $map->save(['pa_number' => '1729', 'employee_id' => 11, 'valid_from' => '2026-10-05'], 'qa')), 'Mapa PA: ciclo em dia remoto pede confirmacao');
$saved = $map->save(['pa_number' => '1729', 'employee_id' => 11, 'valid_from' => '2026-10-05', 'confirm_remote_allocation' => true], 'qa');
assert_same(['Colaborador marcado como remoto nesta data.'], $saved['warnings'], 'Mapa PA: ciclo em dia remoto confirmado avisa');
// Dias pares (tipo antigo) sem mudanca: nao pede confirmacao em dia remoto.
assert_same('ok', $outcome(fn () => $map->save(['pa_number' => '1728', 'employee_id' => 12, 'valid_from' => '2026-10-07'], 'qa')), 'Mapa PA: dias pares em dia impar segue sem confirmacao (como antes)');

echo 'QA schedule legacy output OK (' . (count($expected) - count($changedByF3)) . ' saidas identicas a referencia; '
    . count($changedByF3) . " do Calendario com ausencias comparadas sem esses eventos, F3)\n";
