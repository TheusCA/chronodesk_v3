<?php
declare(strict_types=1);

// ============================================================================
// QA da Escala do Critico em grade semanal (pedido da Lideranca, 2026-11-24;
// docs/DESENHO_ESCALA_CRITICO.md, D-ESC-1 a D-ESC-20)
//
// Sem banco:
// 1. estrutura da migration 027, do rollback (com as paradas), das linhas do
//    ensaio e da consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote. Nenhum nome real: so valores ficticios.
//
// Uso: php scripts/qa-critical-grid.php
// ============================================================================

function assert_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
    $GLOBALS['qa_checks'] = ($GLOBALS['qa_checks'] ?? 0) + 1;
}

/** SQL sem comentarios de linha (-- ...), para as checagens estruturais. */
function qa_sql_without_comments(string $sql): string {
    return (string)preg_replace('/^\s*--.*$/m', '', $sql);
}

$root = dirname(__DIR__);

// ----------------------------------------------------------------------------
// 1. Migration 027, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261124_027_critical_grid.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261124_027_critical_grid_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261124_027_ensaio_linhas.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261124_027_critical_grid_check.sql');
$palette = "''azul'', ''verde'', ''pessego'', ''lilas'', ''amarelo'', ''turquesa'', ''cinza'', ''areia''";
$oldCategories = "''folga'', ''ferias'', ''ausencia_justificada'', ''compromisso'', ''outro''";

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 027: fuso da sessao (A4)');
assert_same([1, 1, 1], [
    preg_match("/TABLE_NAME = 'portal_critical_roles' AND COLUMN_NAME = 'color_token'\\s*\\);\\s*SET @add_color_sql := IF\\(\\s*@has_color = 0,/", $migration),
    preg_match("/CONSTRAINT_NAME = 'ck_critical_role_color'\\s*\\);\\s*SET @add_color_check_sql := IF\\(\\s*@has_color_check = 0,/", $migration),
    preg_match("/TABLE_NAME = 'portal_holidays'\\s*\\);\\s*SET @create_holidays_sql := IF\\(\\s*@has_holidays = 0,/", $migration),
], 'migration 027: coluna, CHECK da cor e tabela de feriados so se nao existirem (idempotente)');
assert_same([1, 1], [
    substr_count($migration, "ADD COLUMN color_token VARCHAR(16) NULL COMMENT ''Cor do papel na paleta fechada da tela (D-ESC-13); nulo = areia''\n        AFTER sort_order, ALGORITHM=INSTANT"),
    substr_count($migration, "ADD CONSTRAINT ck_critical_role_color CHECK (color_token IS NULL OR color_token IN ({$palette}))"),
], 'migration 027: cor nula por padrao (INSTANT) e so a paleta fechada das 8 cores, sem hex');
assert_same(1, preg_match("/UPDATE portal_critical_roles\\s+SET color_token = CASE\\s+WHEN name LIKE 'Presencial%' THEN 'azul'\\s+WHEN name LIKE 'Abertura%' THEN 'verde'\\s+WHEN name LIKE 'Fechamento%' THEN 'pessego'\\s+WHEN name LIKE 'Home%' THEN 'lilas'\\s+END\\s+WHERE color_token IS NULL\\s+AND \\(name LIKE 'Presencial%' OR name LIKE 'Abertura%' OR name LIKE 'Fechamento%' OR name LIKE 'Home%'\\);/", $migration), 'migration 027: cor padrao pelo comeco do nome, so onde a cor esta nula (D-ESC-13)');
assert_same([1, 1, 0], [
    preg_match("/CHECK_CLAUSE LIKE '%uniqueItems%'\\s*\\);\\s*SET @drop_roles_check_sql := IF\\(\\s*@roles_check_unique > 0,/", $migration),
    substr_count($migration, "ADD CONSTRAINT ck_critical_rotation_roles CHECK (JSON_SCHEMA_VALID(''{\"type\": \"array\", \"minItems\": 1, \"maxItems\": 20, \"items\": {\"type\": \"integer\", \"minimum\": 1}}'', roles))"),
    substr_count($migration, 'employees'),
], 'migration 027: vagas: a CHECK dos papeis troca so quando ainda exige uniqueItems; continua 1 a 20 inteiros >= 1; as pessoas nao mudam (D-ESC-15)');
assert_same([1, 1], [
    substr_count($migration, "COLUMN_TYPE LIKE '%''banco_horas''%'"),
    substr_count($migration, "MODIFY COLUMN category ENUM({$oldCategories}, ''banco_horas'') NOT NULL"),
], 'migration 027: banco_horas no FIM do ENUM, so se faltar (D-ESC-18)');
assert_same([1, 1, 1, 1, 1], [
    substr_count($migration, "holiday_date DATE NOT NULL COMMENT ''Data do feriado (uma por data)''"),
    substr_count($migration, "scope ENUM(''todos'') NOT NULL DEFAULT ''todos''"),
    substr_count($migration, 'row_version INT UNSIGNED NOT NULL DEFAULT 1'),
    substr_count($migration, 'UNIQUE KEY uq_holiday_date (holiday_date)'),
    substr_count($migration, 'CONSTRAINT ck_holiday_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)'),
], 'migration 027: feriados com data unica, nome, abrangencia todos e row_version (D-ESC-16)');
assert_same(0, preg_match('/\b(INSERT\s+INTO|DELETE\s+FROM|DROP\s+TABLE|DROP\s+COLUMN|TRUNCATE)\b/i', $migration), 'migration 027: nada apagado nem inserido (o unico UPDATE e a cor padrao)');
assert_same(1, preg_match_all('/\bUPDATE\s+(?!CURRENT_TIMESTAMP)\w+/i', $migration), 'migration 027: um UPDATE so (fora o ON UPDATE do updated_at)');

assert_same(2, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 027: duas paradas (copia anterior e rodizio com vaga repetida)');
preg_match_all("/MESSAGE_TEXT = '([^']*)'/", $rollback, $messages);
assert_same([true, true], array_map(static fn (string $m): bool => strlen($m) <= 128 && str_starts_with($m, 'rollback_027 abortado: '), $messages[1]), 'rollback 027: mensagens do SIGNAL ate 128 caracteres (limite do MySQL) e com o prefixo');
assert_same([1, 1, 1], [
    substr_count($rollback, "    IF repetidos > 0 THEN\n        SIGNAL SQLSTATE '45000'"),
    substr_count($rollback, "SET @copy_color_sql := IF(\n    @has_color = 1,"),
    substr_count($rollback, "SET @copy_banco_sql := IF(\n    @has_banco = 1,"),
], 'rollback 027: para com qualquer rodizio repetido; as copias da cor e do banco de horas rodam sempre que a coluna e o valor existem');
assert_same(1, substr_count($rollback, "WHERE JSON_LENGTH(r.roles) <> (\n               SELECT COUNT(DISTINCT j.role_id)\n                 FROM JSON_TABLE(r.roles, '$[*]' COLUMNS (role_id INT PATH '$')) AS j);"), 'rollback 027: a parada conta o rodizio com papel repetido');
$positions = [
    strpos($rollback, 'CALL rollback_027_confere();'),
    strpos($rollback, "'RENAME TABLE portal_holidays TO portal_holidays_bkp_027'"),
    strpos($rollback, "'CREATE TABLE portal_absences_banco_horas_bkp_027 AS SELECT id FROM portal_absences WHERE category = ''banco_horas'''"),
    strpos($rollback, "'UPDATE portal_absences SET category = ''outro'' WHERE category = ''banco_horas'''"),
    strpos($rollback, "MODIFY COLUMN category ENUM({$oldCategories}) NOT NULL"),
    strpos($rollback, "'ALTER TABLE portal_critical_role_rotations DROP CHECK ck_critical_rotation_roles'"),
    strpos($rollback, "\"uniqueItems\": true"),
    strpos($rollback, "'CREATE TABLE portal_critical_roles_color_bkp_027 AS SELECT id, color_token FROM portal_critical_roles WHERE color_token IS NOT NULL'"),
    strpos($rollback, "'ALTER TABLE portal_critical_roles DROP CHECK ck_critical_role_color'"),
    strpos($rollback, "'ALTER TABLE portal_critical_roles DROP COLUMN color_token'"),
];
$sorted = $positions;
sort($sorted);
assert_same([false, $sorted], [in_array(false, $positions, true), $positions], 'rollback 027: confere, guarda os feriados, copia e converte o banco de horas, volta o ENUM e a CHECK, copia a cor e so entao tira a coluna');
assert_same(1, preg_match_all('/\bUPDATE\s+\w+/i', $rollback), 'rollback 027: a unica linha alterada e o banco de horas que vira outro (com copia antes)');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP\s+TABLE)\b/i', $rollback), 'rollback 027: nenhuma linha nem tabela apagada');

assert_same(3, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio 027: cada comando so no banco ensaio_');
assert_same([1, 1, 1], [
    substr_count($lines, "'Feriado de ensaio', 'todos', 'ensaio', 'ensaio'"),
    substr_count($lines, "SET color_token = 'turquesa'"),
    substr_count($lines, "'Ficticio', 'critico', 'banco_horas'"),
], 'linhas do ensaio 027: um feriado, uma cor e uma ausencia de banco de horas, todos ficticios');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP)\b/i', qa_sql_without_comments($lines)), 'linhas do ensaio 027: nada apagado');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 027 so le');
foreach (['colunas_027', 'checks_027', 'tabela_027', 'enum_027', 'copias_de_rollback_027', 'papeis_com_cor', 'rodizios_com_vaga_repetida', 'feriados', 'ausencias_banco_horas', 'cor_fora_da_lista', 'feriados_repetidos'] as $column) {
    assert_same(1, preg_match('/\bAS ' . $column . '\b/', $check), "verificacao 027: conta {$column}");
}
assert_same(0, preg_match('/\b(name|nome|employee_name|created_by|updated_by)\b/', (string)preg_replace("/WHERE TABLE_SCHEMA.*$/m", '', qa_sql_without_comments((string)preg_replace('/AND CONSTRAINT_NAME[^\n]*/', '', $check)))), 'verificacao 027: nenhum nome nem login na saida');
assert_same(1, substr_count($check, "BIT_XOR(CRC32(CONCAT_WS('|', id, presence_type, work_start, work_end, active, sort_order)))"), 'verificacao 027: assinatura dos papeis sem a cor e sem o nome');

// ----------------------------------------------------------------------------
// 2. Servidor: feriado no motor e nas telas (D-ESC-17), vagas no papel vago
//    (D-ESC-15), cor (D-ESC-13), banco de horas (D-ESC-18) e o cadastro de
//    feriados (D-ESC-16), contra um banco simulado
// ----------------------------------------------------------------------------
require_once $root . '/security.php';
require_once $root . '/services/PresenceService.php';
require_once $root . '/services/CriticalRolePlanner.php';
require_once $root . '/services/HolidayService.php';
require_once $root . '/services/PauseRules.php';
require_once $root . '/services/AbsenceRules.php';

/** Banco simulado: responde pelas consultas conhecidas (por trecho do SQL). */
final class QaGridPdo extends PDO {
    public array $employees = [];
    public array $rules = [];
    public array $exceptions = [];
    public array $absences = [];
    public array $roles = [];
    public array $rotations = [];
    public array $overrides = [];
    public array $holidays = [];
    public array $statements = [];
    public bool $inTx = false;
    public int $nextId = 500;

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $sql = (string)preg_replace('/\s+/', ' ', trim($query));
        $this->statements[] = $sql;
        return new QaGridStatement($this, $sql);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $stmt = $this->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function beginTransaction(): bool { $this->inTx = true; return true; }
    public function commit(): bool { $this->inTx = false; return true; }
    public function rollBack(): bool { $this->inTx = false; return true; }
    public function inTransaction(): bool { return $this->inTx; }
    public function lastInsertId(?string $name = null): string|false { return (string)($this->nextId - 1); }
}

final class QaGridStatement extends PDOStatement {
    public array $rows = [];

    public function __construct(private QaGridPdo $db, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $p = $params ?? [];
        $db = $this->db;
        $sql = $this->sql;
        $this->rows = [];
        $one = static fn (array $row): bool => !isset($p[':employee_id']) || (int)$row['employee_id'] === (int)$p[':employee_id'];
        switch (true) {
            case $sql === 'SELECT id FROM funcionarios WHERE id = :id FOR UPDATE':
                foreach ($db->employees as $r) {
                    if ((int)$r['id'] === (int)$p[':id']) {
                        $this->rows[] = ['id' => $r['id']];
                    }
                }
                return true;
            case str_contains($sql, 'FROM funcionarios WHERE ativo = 1'):
                $this->rows = array_values(array_filter($db->employees, static fn (array $r): bool => (int)$r['ativo'] === 1 && (!isset($p[':employee_id']) || (int)$r['id'] === (int)$p[':employee_id'])));
                return true;
            case str_contains($sql, 'FROM portal_schedule_rules'):
                $this->rows = array_values(array_filter($db->rules, $one));
                return true;
            case str_contains($sql, 'FROM portal_schedule_exceptions WHERE exception_date BETWEEN'):
                $this->rows = array_values(array_filter($db->exceptions, static fn (array $r): bool => $one($r) && $r['exception_date'] >= $p[':date_from'] && $r['exception_date'] <= $p[':date_to']));
                return true;
            case str_contains($sql, "FROM portal_absences WHERE status = 'approved'"):
                $this->rows = array_values(array_filter($db->absences, static fn (array $r): bool => $one($r) && $r['starts_on'] <= $p[':date_to'] && $r['ends_on'] >= $p[':date_from']));
                return true;
            case str_contains($sql, 'FROM portal_critical_roles ORDER BY sort_order, id'):
                $this->rows = array_values($db->roles);
                return true;
            case str_contains($sql, 'FROM portal_critical_role_rotations WHERE effective_from <= :date_to'):
                $this->rows = array_values(array_filter($db->rotations, static fn (array $r): bool => $r['effective_from'] <= $p[':date_to']));
                return true;
            case str_contains($sql, 'FROM portal_critical_role_overrides WHERE starts_on <= :date_to'):
                $this->rows = array_values(array_filter($db->overrides, static fn (array $r): bool => $r['starts_on'] <= $p[':date_to'] && $r['ends_on'] >= $p[':date_from']));
                return true;
            case str_starts_with($sql, 'SELECT id, holiday_date, name FROM portal_holidays WHERE holiday_date BETWEEN'):
            case str_starts_with($sql, 'SELECT id, holiday_date, name, scope, row_version, updated_by, updated_at FROM portal_holidays WHERE holiday_date BETWEEN'):
                $rows = array_values(array_filter($db->holidays, static fn (array $r): bool => $r['holiday_date'] >= $p[':date_from'] && $r['holiday_date'] <= $p[':date_to']));
                usort($rows, static fn (array $a, array $b): int => strcmp($a['holiday_date'], $b['holiday_date']));
                $this->rows = $rows;
                return true;
            case $sql === 'SELECT id FROM portal_holidays WHERE holiday_date = :holiday_date FOR UPDATE':
                foreach ($db->holidays as $r) {
                    if ($r['holiday_date'] === $p[':holiday_date']) {
                        $this->rows[] = ['id' => $r['id']];
                    }
                }
                return true;
            case $sql === 'SELECT row_version FROM portal_holidays WHERE id = :id FOR UPDATE':
                $r = $db->holidays[(int)$p[':id']] ?? null;
                $this->rows = $r === null ? [] : [['row_version' => $r['row_version']]];
                return true;
            case $sql === 'SELECT holiday_date FROM portal_holidays WHERE id = :id':
                $this->rows = [['holiday_date' => $db->holidays[(int)$p[':id']]['holiday_date']]];
                return true;
            case str_starts_with($sql, 'INSERT INTO portal_holidays '):
                $db->holidays[$db->nextId] = ['id' => $db->nextId, 'holiday_date' => $p[':holiday_date'], 'name' => $p[':name'], 'scope' => $p[':scope'], 'row_version' => 1, 'updated_by' => $p[':updated_by'], 'updated_at' => null];
                $db->nextId++;
                return true;
            case str_starts_with($sql, 'UPDATE portal_holidays SET '):
                $r = $db->holidays[(int)$p[':id']] ?? null;
                if ($r !== null && $r['row_version'] === (int)$p[':row_version']) {
                    $db->holidays[(int)$p[':id']] = ['holiday_date' => $p[':holiday_date'], 'name' => $p[':name'], 'row_version' => $r['row_version'] + 1, 'updated_by' => $p[':updated_by']] + $r;
                }
                return true;
            case str_starts_with($sql, 'DELETE FROM portal_holidays WHERE id = :id AND row_version = :row_version'):
                if (($db->holidays[(int)$p[':id']]['row_version'] ?? null) === (int)$p[':row_version']) {
                    unset($db->holidays[(int)$p[':id']]);
                }
                return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }
}

function qa_grid_db(): QaGridPdo {
    $db = new QaGridPdo();
    foreach ([901 => 'Ana Ficticia', 902 => 'Bruno Ficticio', 903 => 'Carla Ficticia', 904 => 'Diego Ficticio', 905 => 'Ana Ruiz Ficticia'] as $id => $name) {
        $db->employees[] = ['id' => $id, 'nome' => $name, 'equipe' => 'critico', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00', 'fora_mapa_escala' => 0, 'ativo' => 1];
    }
    $db->employees[] = ['id' => 11, 'nome' => 'Tecnico Ficticio N1', 'equipe' => 'n1', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00', 'fora_mapa_escala' => 0, 'ativo' => 1];
    $db->rules[] = ['id' => 1, 'employee_id' => 11, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => null];
    $db->roles = [
        1 => ['id' => 1, 'name' => 'Presencial', 'presence_type' => 'onsite', 'work_start' => '08:00:00', 'work_end' => '18:00:00', 'active' => 1, 'sort_order' => 1, 'color_token' => 'azul'],
        2 => ['id' => 2, 'name' => 'Abertura home', 'presence_type' => 'remote', 'work_start' => '07:00:00', 'work_end' => '17:00:00', 'active' => 1, 'sort_order' => 2, 'color_token' => 'verde'],
        3 => ['id' => 3, 'name' => 'Fechamento home', 'presence_type' => 'remote', 'work_start' => '10:30:00', 'work_end' => '20:30:00', 'active' => 1, 'sort_order' => 3, 'color_token' => 'pessego'],
        4 => ['id' => 4, 'name' => 'Home', 'presence_type' => 'remote', 'work_start' => '08:00:00', 'work_end' => '18:00:00', 'active' => 1, 'sort_order' => 4, 'color_token' => null],
    ];
    // Rodizio com vagas: Presencial x2 (D-ESC-15), 5 pessoas, 5 vagas.
    $db->rotations[] = ['id' => 1, 'effective_from' => '2026-11-02', 'period' => 'week', 'anchor_date' => '2026-11-02', 'roles' => '[1, 1, 2, 3, 4]', 'employees' => '[901, 902, 903, 904, 905]', 'updated_by' => 'qa.rodizio', 'updated_at' => '2026-11-01 09:00:00'];
    $db->holidays[30] = ['id' => 30, 'holiday_date' => '2026-11-20', 'name' => 'Feriado Ficticio', 'scope' => 'todos', 'row_version' => 1, 'updated_by' => 'qa', 'updated_at' => null];
    return $db;
}

$at = static fn (string $value): DateTimeImmutable => new DateTimeImmutable($value, new DateTimeZone('America/Sao_Paulo'));

// 2a. Motor puro: feriado como dia sem expediente; a excecao de trabalho vale.
$person = ['id' => 1, 'team' => 'critico', 'work_start' => '08:00', 'work_end' => '18:00', 'lunch_start' => null, 'lunch_end' => null, 'lunch_source' => 'pausa'];
$holiday = ['id' => 30, 'name' => 'Feriado Ficticio'];
$role = CriticalRolePlanner::roleView(qa_grid_db()->roles[1]);
$onHoliday = PresenceRepository::shiftFor($person, '2026-11-20', $role, $holiday);
$schedule = PresenceEngine::scheduleForDay($onHoliday, '2026-11-20', [], null);
assert_same(['dia_nao_util', 'feriado', 'Feriado: Feriado Ficticio'], [$schedule['situacao'], $schedule['fonte'], $schedule['rule_label']], 'motor: feriado vence o papel do dia (dia sem expediente, D-ESC-2)');
$schedule = PresenceEngine::scheduleForDay($onHoliday, '2026-11-20', [], ['exception_type' => 'oncall']);
assert_same(['presencial', 'excecao'], [$schedule['situacao'], $schedule['fonte']], 'motor: excecao de trabalho por data vale no feriado (plantao)');
$day = PresenceEngine::dayPresence($onHoliday, '2026-11-20', [], null, []);
assert_same(['fora_expediente', 'expediente', 'Feriado: Feriado Ficticio'], [$day['situacao'], $day['origem'], $day['detalhe']], 'motor: outra data no feriado = fora do expediente, com o nome');
$now = PresenceEngine::nowPresence($onHoliday, $at('2026-11-20 10:00'), [], null, [], null);
assert_same(['fora_expediente', 'Feriado: Feriado Ficticio'], [$now['situacao'], $now['detalhe']], 'motor: agora, no feriado, fora do expediente');
$absentOnHoliday = PresenceEngine::dayPresence($onHoliday, '2026-11-20', [], null, [['origem' => 'ausencia', 'period_type' => 'full_day', 'category' => 'ferias']]);
assert_same('ausente', $absentOnHoliday['situacao'], 'motor: ausencia de dia inteiro vence o feriado (D-ESC-2)');
assert_same(['escalados' => 0, 'disponiveis' => 0, 'em_pausa' => 0, 'almoco' => 0, 'ausentes' => 0, 'fora_expediente' => 0, 'sem_escala' => 0], PresenceEngine::summarize([$now, $absentOnHoliday]), 'motor: feriado fora dos contadores (nao e falta nem sem escala)');
assert_same([false, true, true, false, 'Feriado'], [
    PresenceEngine::hasShiftOn('2026-11-20', null, $holiday),
    PresenceEngine::hasShiftOn('2026-11-20', ['exception_type' => 'oncall'], $holiday),
    PresenceEngine::hasShiftOn('2026-11-19', null, null),
    PresenceEngine::hasShiftOn('2026-11-21', null, null),
    PresenceEngine::holidayLabel(['name' => '  ']),
], 'pausas: feriado sem ninguem no turno (nao conta no "1 disponivel"), salvo excecao de trabalho');
assert_same(['no_turno' => 0, 'disponiveis' => 0, 'sem_disponivel' => false], PauseRules::teamAvailability([
    1 => ['in_shift' => PresenceEngine::hasShiftOn('2026-11-20', null, $holiday) && PauseRules::inShift('08:00', '18:00', '10:00'), 'paused' => true, 'absent' => false],
]), 'pausas: no feriado nenhum alerta de equipe sem disponivel');
assert_same([0, 3], [PresenceEngine::cyclePosition('2026-11-16', '2026-11-16', 5), PresenceEngine::cyclePosition('2026-11-16', '2026-11-19', 5)], 'ciclo: o feriado continua dia util na contagem (D-F9-30)');
assert_same(1, CriticalRolePlanner::periodIndex('week', '2026-11-02', '2026-11-20') - CriticalRolePlanner::periodIndex('week', '2026-11-02', '2026-11-13'), 'rodizio: a semana do feriado conta normalmente (D-PC-11)');

// 2b. Presenca (presence.php), status e Visao operacional com o feriado.
$db = qa_grid_db();
$period = (new PresenceService($db))->period(['from' => '2026-11-19', 'to' => '2026-11-20', 'team' => 'critico'], $at('2026-11-19 10:00'), [], ['role' => 'admin']);
$byDay = [];
foreach ($period['days'] as $row) {
    $byDay[$row['date']][] = $row['situacao'] . '|' . $row['detalhe'];
}
assert_same([5, ['fora_expediente|Feriado: Feriado Ficticio']], [count($byDay['2026-11-19']), array_values(array_unique($byDay['2026-11-20']))], 'presence.php: o feriado sai para todos como fora do expediente, com o nome; o dia antes, normal');
$holidaySql = array_values(array_filter($db->statements, static fn (string $sql): bool => str_contains($sql, 'portal_holidays')));
assert_same([1, 0], [count($holidaySql), preg_match('/created_by|updated_by|NOW\(\)|CURDATE/i', implode(' ', $holidaySql))], 'presence.php: uma consulta de feriados por carga, sem autoria nem relogio do banco');
$view = (new PresenceService(qa_grid_db()))->operationalView($at('2026-11-20 10:00'), []);
$critical = $view['summary']['critico'];
assert_same([0, 0, []], [$critical['escalados'], $critical['sem_escala'], $view['papeis_vagos']], 'visao operacional: no feriado ninguem escalado e nenhum papel vago');
$dbVacant = qa_grid_db();
$dbVacant->employees[1]['ativo'] = 0;
$vacant = (new PresenceService($dbVacant))->operationalView($at('2026-11-19 10:00'), [])['papeis_vagos'];
assert_same(1, count($vacant), 'visao operacional: dia util com 1 pessoa fora, a vaga dela aparece (sem feriado)');
$status = (new PresenceService(qa_grid_db()))->attachToStatus(['n1' => [['id' => 11, 'em_pausa' => false]], 'n2' => []], $at('2026-11-20 10:00'));
assert_same(['fora_expediente', 'Feriado: Feriado Ficticio'], [$status['n1'][0]['presenca']['situacao'], $status['n1'][0]['presenca']['detalhe']], 'status.php: N1 no feriado fora do expediente, com o nome');
$technicians = (new PresenceService(qa_grid_db()))->technicianPresence($at('2026-11-20 10:00'), [], ['role' => 'tecnico', 'employee_id' => 0]);
assert_same('Feriado: Feriado Ficticio', $technicians[901]['detalhe'], 'tecnicos: situacao de agora no feriado, para qualquer perfil');

// 2c. Vagas: o papel vago conta por vaga (Presencial x2).
$rotation = CriticalRolePlanner::rotationView(qa_grid_db()->rotations[0]);
assert_same([1, 1], [CriticalRolePlanner::rotationRoleId($rotation, 901, '2026-11-02'), CriticalRolePlanner::rotationRoleId($rotation, 902, '2026-11-02')], 'vagas: duas pessoas no Presencial na mesma semana');
assert_same([], CriticalRolePlanner::vacantRoleIds([$rotation], [], '2026-11-02', [901, 902, 903, 904, 905]), 'vagas: todas preenchidas, nenhum vago');
assert_same([1], CriticalRolePlanner::vacantRoleIds([$rotation], [], '2026-11-02', [901, 903, 904, 905]), 'vagas: uma das duas vagas do Presencial sem pessoa, o papel aparece uma vez');
assert_same([1, 1], CriticalRolePlanner::vacantRoleIds([$rotation], [], '2026-11-02', [903, 904, 905]), 'vagas: as duas vagas sem pessoa, o papel aparece duas vezes');
assert_same([1, 2], CriticalRolePlanner::vacantRoleIds([$rotation], [['employee_id' => 903, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'role_id' => 1]], '2026-11-02', [903, 904, 905]), 'vagas: a troca preenche uma das duas vagas do Presencial; a Abertura de quem trocou fica vaga');
$preview = CriticalRolePlanner::preview($rotation, [901, 902, 903, 904, 905], 1);
assert_same(['2026-11-02', 5, 5], [$preview[0]['date'], count($preview), count($preview[0]['roles'])], 'vagas: previa com uma pessoa por vaga');

// 2d. Banco de horas, cor e as leituras do motor nas outras telas.
assert_same(['Banco de horas', 'Banco de horas'], [AbsenceRules::CATEGORIES['banco_horas'], OperationalService::absenceCategoryLabel('banco_horas')], 'banco de horas: rotulo no servico de ausencias e no calendario (D-ESC-18)');
assert_same(['folga', 'ferias', 'ausencia_justificada', 'compromisso', 'outro', 'banco_horas'], array_keys(AbsenceRules::CATEGORIES), 'banco de horas: no fim da lista, como no ENUM');
assert_same(['azul', 'verde', 'pessego', 'lilas', 'amarelo', 'turquesa', 'cinza', 'areia'], CriticalRolePlanner::COLORS, 'cor: a paleta do servidor e a mesma da CHECK da 027');
$operationalSource = (string)file_get_contents($root . '/services/OperationalService.php');
$presenceSource = (string)file_get_contents($root . '/services/PresenceService.php');
$pauseSource = (string)file_get_contents($root . '/classes/GerenciadorPausas.php');
$paMapSource = (string)file_get_contents($root . '/services/PaMapService.php');
assert_same([1, 1, 1, 1], [
    substr_count($operationalSource, '$holidays = $repository->holidaysByDate($from, $to);'),
    substr_count($operationalSource, 'PresenceRepository::shiftFor($employee, $dateKey, $role, $holidays[$dateKey] ?? null)'),
    substr_count($operationalSource, "'holidays' => (new PresenceRepository(\$this->pdo))->holidaysByDate(\$from, \$to)"),
    substr_count($operationalSource, "if ((!\$type || \$type === 'holiday') && !\$status) {"),
], 'escala, relatorios, calendario e Mapa PA: o feriado pelo engineSchedule (dia fora), o evento do Calendario e os feriados do mapPresence');
assert_same([1, 3, 1], [
    substr_count($presenceSource, "\$holidays[\$dateKey] ?? null"),
    substr_count($presenceSource, 'holidaysByDate('),
    substr_count($presenceSource, "if (\$context !== null && (\$context['holiday'] ?? null) === null) {"),
], 'presenca, status e visao operacional: o feriado no motor e nenhum papel vago no feriado');
assert_same([1, 1], [
    substr_count($pauseSource, "PresenceEngine::hasShiftOn(\$dia, \$excecao, \$feriado)"),
    substr_count($pauseSource, "\$lidas[] = \$repositorio->holidaysByDate(\$dia, \$dia)[\$dia] ?? null;"),
], 'pausas: o feriado tira do turno (o "1 disponivel" nao conta) e a leitura falha sozinha');
assert_same(1, substr_count($paMapSource, "isset(\$presence['holidays'][\$date]) => 'feriado',"), 'Mapa PA: vinculo no feriado com o motivo feriado');
$calendar = (new OperationalService(qa_grid_db(), '2026-11-19'))->listCalendar(['from' => '2026-11-01', 'to' => '2026-11-30', 'type' => 'holiday'], ['role' => 'tecnico', 'employee_id' => 0]);
$calendarItems = $calendar['items'] ?? $calendar['events'] ?? $calendar;
assert_same([['holiday-30', 'holiday', 'Feriado: Feriado Ficticio', '2026-11-20 00:00:00', null]], array_map(static fn (array $e): array => [$e['id'], $e['event_type'], $e['title'], $e['starts_at'], $e['employee_id']], array_values(array_filter($calendarItems, 'is_array'))), 'calendario: um evento do feriado, sem pessoa, para qualquer perfil');

// 2e. Cadastro de feriados: permissao, validacao, 409 por data e por versao, auditoria so com ID e data.
assert_same([true, true, false, false, false], array_map(static fn (string $role): bool => portal_role_can($role, 'feriados.manage'), ['admin', 'lideranca', 'gestor', 'tecnico', 'somente_leitura']), 'feriados: so Lideranca e admin gravam (D-ESC-16)');
$db = qa_grid_db();
$audits = [];
$service = new HolidayService($db, function (string $action, string $details) use (&$audits): void { $audits[] = $action . ' ' . $details; });
$outcome = static function (callable $call): string {
    try {
        $call();
        return 'ok';
    } catch (PortalForbiddenException $e) {
        return '403: ' . $e->getMessage();
    } catch (InvalidArgumentException $e) {
        return '400: ' . $e->getMessage();
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    }
};
assert_same('403: ' . HolidayService::FORBIDDEN_MESSAGE, $outcome(fn () => $service->save(['date' => '2026-12-25', 'name' => 'Natal'], 'qa', 'gestor')), 'feriados: gestor nao grava');
assert_same('400: Data do feriado inválida.', $outcome(fn () => $service->save(['date' => '2026-02-30', 'name' => 'X'], 'qa', 'lideranca')), 'feriados: data invalida 400');
assert_same('400: Nome do feriado: de 1 a 80 caracteres.', $outcome(fn () => $service->save(['date' => '2026-12-25', 'name' => '   '], 'qa', 'lideranca')), 'feriados: nome vazio 400');
assert_same('ok', $outcome(fn () => $service->save(['date' => '2026-12-25', 'name' => '  Natal   ficticio '], 'qa.lider', 'lideranca')), 'feriados: Lideranca cadastra');
$created = end($db->holidays);
assert_same(['2026-12-25', 'Natal ficticio', 'todos', 1], [$created['holiday_date'], $created['name'], $created['scope'], $created['row_version']], 'feriados: data, nome sem espacos sobrando, abrangencia todos, versao 1');
assert_same('409: Já existe feriado em 25/12/2026.', $outcome(fn () => $service->save(['date' => '2026-12-25', 'name' => 'Outro'], 'qa', 'admin')), 'feriados: data repetida 409');
assert_same('409: ' . HolidayService::CONFLICT_MESSAGE, $outcome(fn () => $service->save(['id' => $created['id'], 'row_version' => 2, 'date' => '2026-12-25', 'name' => 'Natal'], 'qa', 'admin')), 'feriados: versao velha 409');
assert_same('ok', $outcome(fn () => $service->save(['id' => $created['id'], 'row_version' => 1, 'date' => '2026-12-24', 'name' => 'Vespera'], 'qa', 'admin')), 'feriados: admin altera com a versao');
assert_same(['2026-12-24', 2], [$db->holidays[$created['id']]['holiday_date'], $db->holidays[$created['id']]['row_version']], 'feriados: data nova e versao 2');
assert_same('400: Versão do feriado inválida.', $outcome(fn () => $service->delete(['id' => $created['id']], 'admin')), 'feriados: remover sem versao 400');
assert_same('409: ' . HolidayService::CONFLICT_MESSAGE, $outcome(fn () => $service->delete(['id' => $created['id'], 'row_version' => 1], 'admin')), 'feriados: remover com versao velha 409');
assert_same('ok', $outcome(fn () => $service->delete(['id' => $created['id'], 'row_version' => 2], 'lideranca')), 'feriados: remover com a versao');
assert_same(false, isset($db->holidays[$created['id']]), 'feriados: removido');
assert_same(['FERIADO_CRIADO feriado_id=' . $created['id'] . '; data=2026-12-25', 'FERIADO_ALTERADO feriado_id=' . $created['id'] . '; data=2026-12-24', 'FERIADO_REMOVIDO feriado_id=' . $created['id'] . '; data=2026-12-24'], $audits, 'feriados: auditoria so com o ID e a data (sem nome nem login)');
$list = $service->list(['year' => '2026'], 'tecnico');
assert_same([false, 1, 'Feriado Ficticio'], [$list['can_manage'], count($list['items']), $list['items'][0]['name']], 'feriados: qualquer perfil le o ano; so a gestao do pedido grava');
assert_same('400: Ano inválido.', $outcome(fn () => $service->list(['year' => '26'], 'tecnico')), 'feriados: ano invalido 400');
$endpoint = (string)file_get_contents($root . '/api/portal/holidays.php');
assert_same([1, true], [substr_count($endpoint, 'portal_json_input('), strpos($endpoint, "if (!portal_role_can(\$role, 'feriados.manage')) {") < strpos($endpoint, 'portal_json_input(')], 'feriados: o perfil e conferido antes de ler o corpo (CSRF e JSON no portal_json_input)');

// ----------------------------------------------------------------------------
// 3. Grade do mes (critical_grid.php): semanas, pessoas, celulas pelo motor,
//    privacidade da ausencia, versao, resumo, previa, 409 e o .xlsx
// ----------------------------------------------------------------------------
require_once $root . '/services/CriticalGridService.php';
require_once $root . '/services/CriticalGridSheet.php';

/** Base do mes de novembro: ausencias, excecao, troca e o feriado de 20/11 (tudo ficticio). */
function qa_grid_month_db(): QaGridPdo {
    $db = qa_grid_db();
    $absence = static fn (int $id, int $employee, string $category, string $from, string $to, string $period = 'full_day', ?string $start = null, ?string $end = null): array => ['id' => $id, 'employee_id' => $employee, 'category' => $category, 'starts_on' => $from, 'ends_on' => $to, 'period_type' => $period, 'start_time' => $start, 'end_time' => $end, 'created_by' => 'qa.lider', 'updated_by' => null, 'updated_at' => '2026-11-10 10:00:00'];
    $db->absences = [
        $absence(70, 901, 'banco_horas', '2026-11-18', '2026-11-19'),
        $absence(71, 902, 'ferias', '2026-11-23', '2026-11-23'),
        $absence(72, 903, 'folga', '2026-11-24', '2026-11-24'),
        $absence(73, 904, 'compromisso', '2026-11-17', '2026-11-17', 'interval', '14:00:00', '16:00:00'),
    ];
    $db->exceptions = [['id' => 80, 'employee_id' => 905, 'exception_date' => '2026-11-25', 'exception_type' => 'remote', 'created_by' => 'qa.lider', 'updated_at' => '2026-11-11 10:00:00']];
    $db->overrides = [['id' => 90, 'employee_id' => 903, 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-26', 'role_id' => 2, 'reason' => 'troca', 'updated_by' => 'qa.troca', 'updated_at' => '2026-11-12 10:00:00']];
    return $db;
}
$lider = ['role' => 'lideranca', 'username' => 'qa.lider', 'employee_id' => null];
$tecnico = ['role' => 'tecnico', 'username' => 'qa.tec', 'employee_id' => 901];
$gridOf = static fn (QaGridPdo $db, array $actor, string $month = '2026-11'): array => (new CriticalGridService($db, '2026-11-19'))->month(['month' => $month], $actor);

// 3a. Semanas (seg a sex com dia util do mes) e pessoas.
$weekStarts = static fn (int $year, int $month): array => array_column(CriticalGridService::weeks($year, $month), 'start');
assert_same([['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30'], [45, 49]], [$weekStarts(2026, 11), [CriticalGridService::weeks(2026, 11)[0]['iso'], CriticalGridService::weeks(2026, 11)[4]['iso']]], 'semanas: novembro de 2026 comeca no domingo (1a semana em 02/11) e termina numa segunda (semana de 30/11)');
$january = CriticalGridService::weeks(2026, 1);
assert_same([['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31'], '2026-01-26'], [$weekStarts(2026, 8), end($january)['start']], 'semanas: mes que comeca no sabado e mes que termina no sabado');
$grid = $gridOf(qa_grid_month_db(), $lider);
assert_same(['2026-11', 'Novembro 2026', '2026-10', '2026-12', true], [$grid['month']['key'], $grid['month']['label'], $grid['month']['prev'], $grid['month']['next'], $grid['can_manage']], 'mes: titulo, anterior e proximo; Lideranca edita');
assert_same(['2026-12', '2027-01'], [$gridOf(qa_grid_month_db(), $lider, '2026-12')['month']['prev'] === '2026-11' ? '2026-12' : 'x', $gridOf(qa_grid_month_db(), $lider, '2026-12')['month']['next']], 'mes: dezembro vira para janeiro do ano seguinte');
assert_same([false, true, false], array_map(static fn (array $w): bool => $w['current'], [$grid['weeks'][1], $grid['weeks'][2], $grid['weeks'][3]]), 'semanas: so a semana de hoje (19/11) aberta');
assert_same([[901, 'Ana F.'], [902, 'Bruno'], [903, 'Carla'], [904, 'Diego'], [905, 'Ana R.']], array_map(static fn (array $p): array => [$p['id'], $p['short_name']], $grid['people']), 'pessoas: na ordem do rodizio; nome curto, com a inicial quando o 1o nome repete (D-ESC-4)');
$outsiders = qa_grid_month_db();
$outsiders->employees[] = ['id' => 950, 'nome' => 'Fora Do Mapa', 'equipe' => 'critico', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00', 'fora_mapa_escala' => 1, 'ativo' => 1];
$outsiders->employees[] = ['id' => 951, 'nome' => 'Zeca Fora Do Rodizio', 'equipe' => 'critico', 'jornada_entrada' => '09:00:00', 'jornada_saida' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00', 'fora_mapa_escala' => 0, 'ativo' => 1];
$outsiders->rules[] = ['id' => 2, 'employee_id' => 951, 'rule_type' => 'always_remote', 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => null];
$withOutsiders = $gridOf($outsiders, $lider);
assert_same([6, 951, 'Home 09:00–18:00', 'regra'], [count($withOutsiders['people']), end($withOutsiders['people'])['id'], $withOutsiders['cells']['951:2026-11-19']['label'], $withOutsiders['cells']['951:2026-11-19']['source']], 'pessoas: fora do Mapa fica de fora; quem nao esta no rodizio vem depois, pela regra individual');

// 3b. Celulas (pelo motor, precedencia D-ESC-2) para a Lideranca.
$cell = static fn (array $grid, int $id, string $date): array => $grid['cells'][$id . ':' . $date];
$view = static fn (array $c): array => [$c['label'], $c['tone'], $c['source'], $c['marker']];
assert_same(['Abertura home 07:00–17:00', 'papel-verde', 'rodizio', null], $view($cell($grid, 901, '2026-11-16')), 'celula: papel do rodizio com a cor do papel');
assert_same(['Presencial 08:00–18:00', 'papel-azul', 'rodizio', null], $view($cell($grid, 904, '2026-11-16')), 'celula: Presencial x2 (vaga 1)');
assert_same(['Presencial 08:00–18:00', 'papel-azul', 'rodizio', null], $view($cell($grid, 905, '2026-11-16')), 'celula: Presencial x2 (vaga 2)');
assert_same(['Home 08:00–18:00', 'papel-areia', 'rodizio', null], $view($cell($grid, 903, '2026-11-16')), 'celula: papel sem cor na areia');
assert_same(['Banco de horas', 'banco', 'ausencia', null], $view($cell($grid, 901, '2026-11-18')), 'celula: banco de horas no lugar do papel');
assert_same(['Férias', 'ferias', 'ausencia', null], $view($cell($grid, 902, '2026-11-23')), 'celula: ferias');
assert_same(['Folga', 'ausente', 'ausencia', null], $view($cell($grid, 903, '2026-11-24')), 'celula: a Lideranca ve a categoria (Folga)');
assert_same(['Feriado', 'feriado', 'feriado', null, 'Feriado: Feriado Ficticio'], array_merge($view($cell($grid, 902, '2026-11-20')), [$cell($grid, 902, '2026-11-20')['detail']]), 'celula: feriado no lugar do papel, com o nome no detalhe');
assert_same(['Home (exceção)', 'papel-lilas', 'excecao', 'excecao'], $view($cell($grid, 905, '2026-11-25')), 'celula: excecao por data com o icone (nao vem do rodizio)');
$holidayAbsence = qa_grid_month_db();
$holidayAbsence->absences[] = ['id' => 74, 'employee_id' => 903, 'category' => 'ferias', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'created_by' => 'qa', 'updated_by' => null, 'updated_at' => null];
assert_same(['Férias', 'ferias', 'ausencia', null], $view($cell($gridOf($holidayAbsence, $lider), 903, '2026-11-20')), 'celula: ausencia de dia inteiro vence o feriado (D-ESC-2)');
assert_same(['Abertura home 07:00–17:00', 'papel-verde', 'troca', 'troca', 'qa.troca'], array_merge($view($cell($grid, 903, '2026-11-26')), [$cell($grid, 903, '2026-11-26')['last_change']['by']]), 'celula: troca pontual com o icone e quem alterou');
assert_same(['Presencial 08:00–18:00', 'Ausente 14:00–16:00 (Compromisso)'], [$cell($grid, 904, '2026-11-17')['label'], $cell($grid, 904, '2026-11-17')['detail']], 'celula: ausencia parcial deixa o papel e marca o horario');
assert_same(['qa.rodizio', 'qa.lider'], [$cell($grid, 901, '2026-11-16')['last_change']['by'], $cell($grid, 901, '2026-11-18')['last_change']['by']], 'celula: quem alterou por ultimo (rodizio e ausencia)');
assert_same([false, true], [$cell($grid, 901, '2026-12-01')['in_month'], $cell($grid, 901, '2026-11-30')['in_month']], 'celula: dias de dezembro na ultima semana ficam fora do mes');
assert_same([['troca' => ['id' => 90, 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-26']], ['ausencia' => ['id' => 70, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-19']], ['excecao' => ['id' => 80, 'date' => '2026-11-25']]], [$cell($grid, 903, '2026-11-26')['refs'], $cell($grid, 901, '2026-11-19')['refs'], $cell($grid, 905, '2026-11-25')['refs']], 'celula: o que desfazer, so para a gestao');
assert_same(6, count($grid['absence_choices']), 'gestao: as seis categorias de ausencia, com o banco de horas');

// 3c. Privacidade (D-ESC-5): o tecnico ve so Ferias, Banco de horas, Feriado ou Ausente; nada de refs nem observacao.
$seen = $gridOf(qa_grid_month_db(), $tecnico);
assert_same([false, 'Ausente', 'Banco de horas', 'Férias', 'Feriado', 'Ausente 14:00–16:00', [], [], []], [
    $seen['can_manage'], $cell($seen, 903, '2026-11-24')['label'], $cell($seen, 901, '2026-11-18')['label'], $cell($seen, 902, '2026-11-23')['label'],
    $cell($seen, 902, '2026-11-20')['label'], $cell($seen, 904, '2026-11-17')['detail'], $cell($seen, 903, '2026-11-26')['refs'], $seen['absence_choices'], $seen['exception_choices'],
], 'privacidade: tecnico sem a categoria (Folga vira Ausente), sem o tipo da parcial e sem o que desfazer');
$json = (string)json_encode($seen, JSON_UNESCAPED_UNICODE);
assert_same([0, 0, 0], [substr_count($json, 'Folga'), substr_count($json, 'Compromisso'), preg_match('/"note"|"reason"/', $json)], 'privacidade: nenhuma categoria escondida nem observacao em lugar nenhum da resposta');

// 3d. Resumo do mes (so os dias do mes).
$summary = array_column($grid['summary'], null, 'employee_id');
$row = $summary[901];
assert_same([2, 1], [$row['absent'], $row['holiday']], 'resumo: ausencias e feriado contados a parte');
assert_same($row['onsite'] + $row['home'] + $row['absent'] + $row['holiday'], 21, 'resumo: os 21 dias uteis de novembro de 2026 numa conta so');
assert_same((int)round(100 * $row['onsite'] / ($row['onsite'] + $row['home'])), $row['onsite_pct'], 'resumo: % presencial = presencial / (presencial + home)');
assert_same(array_sum($row['roles']), $row['onsite'] + $row['home'], 'resumo: dias por papel = dias trabalhados (sem excecao nem regra para 901)');

// 3e. Carga fixa (D-ESC-3): 8 consultas para 5 ou 25 pessoas; sem observacao nem relogio do banco.
$counts = [];
foreach ([0, 20] as $extra) {
    $db = qa_grid_month_db();
    for ($i = 0; $i < $extra; $i++) {
        $db->employees[] = ['id' => 1000 + $i, 'nome' => 'Extra ' . $i, 'equipe' => 'critico', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '18:00:00', 'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00', 'fora_mapa_escala' => 0, 'ativo' => 1];
    }
    $gridOf($db, $lider);
    $counts[] = count($db->statements);
    $sql = implode("\n", $db->statements);
}
assert_same([8, 8, 0], [$counts[0], $counts[1], preg_match('/\bnote\b|\bnome\b.*portal_absences|NOW\(\)|CURDATE|SELECT \*/i', $sql)], 'carga: 8 consultas fixas, qualquer que seja o numero de pessoas, sem observacao nem relogio do banco');

// 3f. Versao da celula (D-ESC-6): muda com a troca do dia, fica igual nos outros dias.
$before = $gridOf(qa_grid_month_db(), $lider);
$changed = qa_grid_month_db();
$changed->overrides[] = ['id' => 91, 'employee_id' => 902, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'role_id' => 4, 'reason' => 'troca', 'updated_by' => 'qa', 'updated_at' => '2026-11-13 10:00:00'];
$after = $gridOf($changed, $lider);
assert_same([true, false], [$cell($before, 902, '2026-11-17')['version'] !== $cell($after, 902, '2026-11-17')['version'], $cell($before, 902, '2026-11-16')['version'] !== $cell($after, 902, '2026-11-16')['version']], 'versao: so o dia que mudou muda');
$sameRole = qa_grid_month_db();
$sameRole->overrides[] = ['id' => 92, 'employee_id' => 904, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-16', 'role_id' => 1, 'reason' => 'cobertura', 'updated_by' => 'qa', 'updated_at' => '2026-11-13 10:00:00'];
$sameRoleGrid = $gridOf($sameRole, $lider);
assert_same([true, 'troca'], [$cell($sameRoleGrid, 904, '2026-11-16')['version'] !== $cell($before, 904, '2026-11-16')['version'], $cell($sameRoleGrid, 904, '2026-11-16')['marker']], 'versao: muda com a troca mesmo para o papel que o rodizio ja dava');
$sameAuthor = qa_grid_month_db();
$sameAuthor->absences[0]['updated_at'] = '2026-11-15 08:00:00';
assert_same(true, $cell($gridOf($sameAuthor, $lider), 901, '2026-11-18')['version'] !== $cell($before, 901, '2026-11-18')['version'], 'versao: muda tambem quando a ausencia e editada');

// 3g. Previa (D-ESC-11) e os 409.
$service = new CriticalGridService(qa_grid_month_db(), '2026-11-19');
$try = static function (callable $call): string {
    try {
        $call();
        return 'ok';
    } catch (PortalForbiddenException $e) {
        return '403: ' . $e->getMessage();
    } catch (InvalidArgumentException $e) {
        return '400: ' . $e->getMessage();
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    }
};
$preview = $service->preview(['employee_id' => 905, 'from' => '2026-11-23', 'to' => '2026-11-24', 'change' => ['type' => 'absence', 'category' => 'banco_horas']], $lider);
assert_same(['Seg 23/11: Abertura home 07:00–17:00 → Banco de horas', 'Ter 24/11: Abertura home 07:00–17:00 → Banco de horas'], array_column($preview['changes'], 'text'), 'previa: antes -> depois por dia');
$role = $service->preview(['employee_id' => 901, 'from' => '2026-11-20', 'to' => '2026-11-24', 'change' => ['type' => 'role', 'role_id' => 1]], $lider);
assert_same(['2026-11-23', '2026-11-24'], array_column($role['changes'], 'date'), 'previa: a troca so nos dias uteis sem feriado (D-ESC-12)');
assert_same('Seg 23/11: Fechamento home 10:30–20:30 → Presencial 08:00–18:00', $role['changes'][0]['text'], 'previa: trocar o papel do dia');
$exception = $service->preview(['employee_id' => 902, 'from' => '2026-11-16', 'to' => '2026-11-17', 'change' => ['type' => 'exception', 'exception_type' => 'onsite']], $lider);
assert_same('Seg 16/11: Fechamento home 10:30–20:30 → Presencial (exceção)', $exception['changes'][0]['text'], 'previa: excecao presencial forcada');
$undo = $service->preview(['employee_id' => 903, 'from' => '2026-11-26', 'to' => '2026-11-26', 'change' => ['type' => 'undo', 'target' => 'troca', 'ref_id' => 90]], $lider);
assert_same(['Qui 26/11: Abertura home 07:00–17:00 → Presencial 08:00–18:00'], array_column($undo['changes'], 'text'), 'previa: desfazer a troca volta ao rodizio');
$undoAbsence = $service->preview(['employee_id' => 901, 'from' => '2026-11-19', 'to' => '2026-11-19', 'change' => ['type' => 'undo', 'target' => 'ausencia', 'ref_id' => 70]], $lider);
assert_same(['2026-11-18', '2026-11-19'], array_column($undoAbsence['changes'], 'date'), 'previa: desfazer a ausencia cobre o registro inteiro (D-ESC-10)');
$lonely = qa_grid_month_db();
foreach ([901, 902, 903, 904] as $index => $id) {
    $lonely->absences[] = ['id' => 200 + $index, 'employee_id' => $id, 'category' => 'ferias', 'starts_on' => '2026-11-27', 'ends_on' => '2026-11-27', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'created_by' => 'qa', 'updated_by' => null, 'updated_at' => null];
}
$alone = (new CriticalGridService($lonely, '2026-11-19'))->preview(['employee_id' => 905, 'from' => '2026-11-27', 'to' => '2026-11-27', 'change' => ['type' => 'absence', 'category' => 'folga']], $lider);
assert_same(['Sex 27/11: o Crítico fica sem ninguém disponível.'], array_column($alone['warnings'], 'text'), 'previa: aviso quando o Critico fica sem ninguem no dia (deixa salvar)');
assert_same([], $preview['warnings'], 'previa: sem aviso quando sobra alguem');
assert_same('409: 1 troca(s) já cobrem o período: desfaça antes.', $try(fn () => $service->preview(['employee_id' => 903, 'from' => '2026-11-25', 'to' => '2026-11-27', 'change' => ['type' => 'role', 'role_id' => 1]], $lider)), 'previa: troca sobreposta 409 com a contagem');
assert_same('409: Já existe ausência aprovada do colaborador em 23/11/2026 (dia todo).', $try(fn () => $service->preview(['employee_id' => 902, 'from' => '2026-11-23', 'to' => '2026-11-23', 'change' => ['type' => 'absence', 'category' => 'folga']], $lider)), 'previa: ausencia sobreposta 409 (a regra das ausencias)');
assert_same('409: Esta troca não cobre mais o dia: recarregue a tela.', $try(fn () => $service->preview(['employee_id' => 903, 'from' => '2026-11-25', 'to' => '2026-11-25', 'change' => ['type' => 'undo', 'target' => 'troca', 'ref_id' => 90]], $lider)), 'previa: desfazer troca que nao cobre o dia 409');
assert_same('403: ' . CriticalGridService::FORBIDDEN_MESSAGE, $try(fn () => $service->preview(['employee_id' => 905, 'from' => '2026-11-23', 'change' => ['type' => 'absence', 'category' => 'ferias']], ['role' => 'gestor'])), 'previa: gestor 403 (so Lideranca e admin)');
assert_same('400: Até 31 dias por vez.', $try(fn () => $service->preview(['employee_id' => 905, 'from' => '2026-11-01', 'to' => '2026-12-02', 'change' => ['type' => 'absence', 'category' => 'ferias']], $lider)), 'previa: ate 31 dias (32 recusa)');
assert_same('400: Tipo de ausência inválido.', $try(fn () => $service->preview(['employee_id' => 905, 'from' => '2026-11-23', 'change' => ['type' => 'absence', 'category' => 'atestado']], $lider)), 'previa: atestado nao e categoria (D-ESC-8)');
assert_same('400: Desfazer vale para um dia por vez.', $try(fn () => $service->preview(['employee_id' => 903, 'from' => '2026-11-25', 'to' => '2026-11-26', 'change' => ['type' => 'undo', 'target' => 'troca', 'ref_id' => 90]], $lider)), 'previa: desfazer so de um dia');
assert_same('409: Só cadastros ativos do Crítico têm escala nesta tela.', $try(fn () => $service->preview(['employee_id' => 11, 'from' => '2026-11-23', 'change' => ['type' => 'absence', 'category' => 'ferias']], $lider)), 'previa: N1 fora da tela');
$stale = new CriticalGridService(qa_grid_month_db(), '2026-11-19');
assert_same('409: 1 dia(s) mudaram desde que a tela carregou: recarregue e repita.', $try(fn () => $stale->apply(['employee_id' => 905, 'from' => '2026-11-23', 'to' => '2026-11-24', 'change' => ['type' => 'absence', 'category' => 'ferias'], 'versions' => ['2026-11-23' => 'velha', '2026-11-24' => $cell($grid, 905, '2026-11-24')['version']]], $lider)), 'gravar: versao velha de um dia 409 com a contagem, antes de gravar');
assert_same('400: Versões dos dias ausentes: recarregue a tela.', $try(fn () => $stale->apply(['employee_id' => 905, 'from' => '2026-11-23', 'change' => ['type' => 'absence', 'category' => 'ferias']], $lider)), 'gravar: sem as versoes 400');
$gridSource = (string)file_get_contents($root . '/services/CriticalGridService.php');
$applyBody = substr($gridSource, (int)strpos($gridSource, 'public function apply('), 2600);
$order = [strpos($applyBody, '$this->lockEmployee('), strpos($applyBody, '$this->load('), strpos($applyBody, 'throw new DomainException(sprintf(self::STALE_MESSAGE'), strpos($applyBody, '$this->write(')];
assert_same([false, true], [in_array(false, $order, true), $order[0] < $order[1] && $order[1] < $order[2] && $order[2] < $order[3]], 'gravar: trava o colaborador, carrega, confere as versoes e so entao grava');
$endpoint = (string)file_get_contents($root . '/api/portal/critical_grid.php');
assert_same([1, true], [substr_count($endpoint, 'portal_json_input('), strpos($endpoint, "if (!portal_role_can(\$role, 'escalas.manage')) {") < strpos($endpoint, 'portal_json_input(')], 'endpoint: o perfil e conferido antes de ler o corpo (uma leitura so, depois)');

// 3h. O .xlsx (D-ESC-19): aba do mes, blocos por semana, cores da planilha, resumo e legenda.
function qa_zip_entries(string $zip): array {
    $files = [];
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $head = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr($zip, $pos + 4, 26));
        $name = substr($zip, $pos + 30, $head['name']);
        $files[$name] = substr($zip, $pos + 30 + $head['name'] + $head['extra'], $head['compressed']);
        $pos += 30 + $head['name'] + $head['extra'] + $head['compressed'];
    }
    return $files;
}
$files = qa_zip_entries(CriticalGridSheet::build($seen));
$sheet = $files['xl/worksheets/sheet1.xml'];
assert_same([1, 1, 1], [
    substr_count($files['xl/workbook.xml'], 'name="Novembro 2026"'),
    substr_count($sheet, '<t xml:space="preserve">Novembro 2026</t>'),
    substr_count($sheet, '<mergeCell ref="A1:F1"/>'),
], 'xlsx: aba e titulo do mes, titulo mesclado');
assert_same([5, 1, 1], [
    preg_match_all('/<t xml:space="preserve">Semana \d+  \(Início: \d{2}\/\d{2}\/2026\)<\/t>/u', $sheet),
    substr_count($sheet, '<t xml:space="preserve">Semana 45  (Início: 02/11/2026)</t>'),
    substr_count($sheet, "<t xml:space=\"preserve\">Seg\n(16/11)</t>"),
], 'xlsx: um bloco por semana, com o cabecalho do dia');
assert_same([0, 0], [substr_count($sheet, 'Folga'), substr_count($sheet, 'Compromisso')], 'xlsx: a mesma privacidade da tela (o tecnico exporta o que ve)');
assert_same([1, 1, 1, 1], [
    substr_count($files['xl/styles.xml'], 'rgb="FF92CDDC"'),
    substr_count($files['xl/styles.xml'], 'rgb="FFFFFF00"'),
    substr_count($sheet, '<t xml:space="preserve">Resumo do mês</t>'),
    substr_count($sheet, '<t xml:space="preserve">% presencial</t>'),
], 'xlsx: cores da planilha (feriado e banco de horas) e o resumo');
assert_same([1, 1], [substr_count($sheet, '<t xml:space="preserve">Legenda</t>'), substr_count($sheet, '<t xml:space="preserve">Banco de horas</t>') >= 1 ? 1 : 0], 'xlsx: legenda com as cores fixas');
assert_same('Escala Critico Marco 2026.xlsx', CriticalGridSheet::fileName('Março 2026'), 'xlsx: nome do arquivo sem acento');
assert_same(true, strpos($endpoint, "audit_log('ESCALA_CRITICO_EXPORTADA', 'Exportacao XLSX da Escala do Critico: mes ' . \$grid['month']['key'] . ', pessoas ' . count(\$grid['people'])") !== false, 'xlsx: auditoria so com o mes e contagens');

echo 'QA critical grid OK (' . $GLOBALS['qa_checks'] . " verificacoes)\n";
