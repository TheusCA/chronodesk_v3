<?php
declare(strict_types=1);

// ============================================================================
// QA do historico de escala (Parte B, F1)
//
// Sem banco:
// 1. estrutura da migration 011, do rollback e da consulta de verificacao;
// 2. planejador de vigencia (funcao pura) e propriedade do historico em
//    sequencias aleatorias de gravacao e remocao;
// 3. OperationalService contra um banco simulado em memoria, que emula as
//    consultas do servico, as UNIQUE da 011 (inicio e regra aberta), a
//    transacao (rollback desfaz) e o audit_log.
// As consultas SQL nao rodam em MySQL aqui (sem MySQL local): a prova de
// ponta a ponta e o ensaio no servidor (scripts/ensaio-migration.sh) e o
// roteiro do deploy.
//
// Uso: php scripts/qa-schedule-history.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-schedule-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
define('CHRONODESK_STATELESS', true);

// O audit_log usa get_db_connection(); db.php so define a funcao se ela
// ainda nao existir. Aqui ela devolve o banco simulado do teste em curso.
$GLOBALS['qa_pdo'] = null;
function get_db_connection() {
    if (!$GLOBALS['qa_pdo'] instanceof PDO) {
        throw new PDOException('SQLSTATE[HY000]: banco simulado ausente');
    }
    return $GLOBALS['qa_pdo'];
}

require_once __DIR__ . '/../services/OperationalService.php';

/**
 * Banco simulado: tabelas em memoria e so as consultas que o servico de
 * escala faz. Consulta nao prevista falha como banco fora. Gravacao que
 * violaria uq_schedule_rule_employee_from ou uq_schedule_rule_open falha
 * como o MySQL (erro 1062) e nao muda nada.
 */
final class QaSchedulePdo extends PDO {
    public array $employees = [];
    public array $rules = [];
    public array $exceptions = [];
    public array $paAssignments = [];
    public array $audits = [];
    public array $events = [];
    public int $nextId = 1000;
    private ?array $snapshot = null;

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaScheduleStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (trim($query) === 'SELECT 1 FROM audit_log LIMIT 1') {
            return new QaScheduleStatement($this, 'SELECT 1');
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }

    public function beginTransaction(): bool {
        $this->snapshot = [$this->rules, $this->exceptions, $this->audits, $this->nextId];
        $this->events[] = 'begin';
        return true;
    }

    public function commit(): bool {
        $this->snapshot = null;
        $this->events[] = 'commit';
        return true;
    }

    public function rollBack(): bool {
        [$this->rules, $this->exceptions, $this->audits, $this->nextId] = $this->snapshot;
        $this->snapshot = null;
        $this->events[] = 'rollback';
        return true;
    }

    public function inTransaction(): bool {
        return $this->snapshot !== null;
    }

    public function lastInsertId(?string $name = null): string|false {
        return (string)($this->nextId - 1);
    }

    /** Aplica a gravacao e confere as UNIQUE da 011; violacao desfaz e lanca 1062. */
    public function writeRules(array $rules): void {
        $open = [];
        $starts = [];
        foreach ($rules as $rule) {
            $startKey = $rule['employee_id'] . ':' . $rule['effective_from'];
            if (isset($starts[$startKey]) || ($rule['effective_until'] === null && isset($open[$rule['employee_id']]))) {
                throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
            }
            $starts[$startKey] = true;
            if ($rule['effective_until'] === null) {
                $open[$rule['employee_id']] = true;
            }
        }
        $this->rules = $rules;
    }

    /** Linha do tempo de um colaborador: tipo e configuracao vigentes por data. */
    public function timeline(int $employeeId, string $from, string $to): array {
        $rules = array_values(array_filter($this->rules, static fn (array $rule): bool => $rule['employee_id'] === $employeeId));
        $line = [];
        for ($date = $from; $date <= $to; $date = ScheduleRulePlanner::addDays($date, 1)) {
            $rule = ScheduleRulePlanner::ruleAt($rules, $date);
            $line[$date] = $rule === null ? null : $rule['rule_type'] . ' ' . ($rule['rule_config'] ?? '');
        }
        return $line;
    }
}

final class QaScheduleStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaSchedulePdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = $this->sql;
        $db = $this->pdo;
        $this->rows = [];
        $db->events[] = $sql;
        if ($sql === 'SELECT 1') {
            return true;
        }
        if ($sql === 'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)') {
            $db->audits[] = ['action' => $params[2], 'details' => $params[3], 'severity' => $params[4]];
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_sync_queue') === 0) {
            return true;
        }
        if ($sql === 'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login, access_role FROM funcionarios WHERE id = :id AND ativo = 1') {
            $row = $db->employees[(int)$params[':id']] ?? null;
            if ($row !== null && (int)$row['ativo'] === 1) {
                $this->rows[] = ['id' => (int)$params[':id'], 'name' => $row['nome'], 'team' => strtolower($row['equipe']), 'ad_login' => $row['ad_login'], 'access_role' => $row['access_role']];
            }
            return true;
        }
        if (preg_match('/^SELECT (id|fora_mapa_escala) FROM funcionarios WHERE id = :id AND ativo = 1 FOR UPDATE$/', $sql, $match)) {
            $row = $db->employees[(int)$params[':id']] ?? null;
            if ($row !== null && (int)$row['ativo'] === 1) {
                $this->rows[] = [$match[1] => $match[1] === 'id' ? (int)$params[':id'] : (int)($row['fora_mapa_escala'] ?? 0)];
            }
            return true;
        }
        if ($sql === 'SELECT id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id') {
            foreach ($db->rules as $rule) {
                if ($rule['employee_id'] === (int)$params[':employee_id']) {
                    $this->rows[] = array_intersect_key($rule, array_flip(['id', 'rule_type', 'rule_config', 'effective_from', 'effective_until']));
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);
            return true;
        }
        if ($sql === 'UPDATE portal_schedule_rules SET effective_until = :effective_until, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id') {
            $rules = $db->rules;
            $this->matchRule($rules, $params, static fn (array $rule): array => ['effective_until' => $params[':effective_until']] + $rule);
            $db->writeRules($rules);
            return true;
        }
        if (strpos($sql, 'UPDATE portal_schedule_rules SET employee_name = :employee_name,') === 0) {
            $rules = $db->rules;
            $this->matchRule($rules, $params, static fn (array $rule): array => [
                'team' => $params[':team'], 'rule_type' => $params[':rule_type'], 'rule_config' => $params[':rule_config'],
                'effective_until' => $params[':effective_until'],
            ] + $rule);
            $db->writeRules($rules);
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_schedule_rules (') === 0) {
            $rules = $db->rules;
            $rules[$db->nextId] = [
                'id' => $db->nextId, 'employee_id' => (int)$params[':employee_id'], 'team' => $params[':team'],
                'rule_type' => $params[':rule_type'], 'rule_config' => $params[':rule_config'],
                'effective_from' => $params[':effective_from'], 'effective_until' => $params[':effective_until'],
            ];
            $db->writeRules($rules);
            $db->nextId++;
            return true;
        }
        if ($sql === 'DELETE FROM portal_schedule_rules WHERE id = :id AND employee_id = :employee_id') {
            $rules = $db->rules;
            $this->matchRule($rules, $params, static fn (array $rule): ?array => null);
            $db->writeRules(array_filter($rules));
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
    }

    private function matchRule(array &$rules, array $params, callable $change): void {
        $id = (int)$params[':id'];
        if (isset($rules[$id]) && $rules[$id]['employee_id'] === (int)$params[':employee_id']) {
            $rules[$id] = $change($rules[$id]);
        }
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

    public function rowCount(): int {
        return 1;
    }
}

/** Banco simulado com um cadastro N1 (1), um N2 (2) e as regras dadas. */
function qa_schedule_db(array $rules = []): QaSchedulePdo {
    $pdo = new QaSchedulePdo();
    $pdo->employees = [
        1 => ['nome' => 'Tecnico QA 1', 'equipe' => 'n1', 'ad_login' => 'qa.tecnico1', 'access_role' => 'tecnico', 'ativo' => 1, 'fora_mapa_escala' => 0],
        2 => ['nome' => 'Tecnico QA 2', 'equipe' => 'N2', 'ad_login' => 'qa.tecnico2', 'access_role' => 'tecnico', 'ativo' => 1, 'fora_mapa_escala' => 0],
    ];
    foreach ($rules as $rule) {
        $pdo->rules[$rule['id']] = $rule + ['employee_id' => 1, 'team' => 'n1', 'rule_config' => null];
    }
    $GLOBALS['qa_pdo'] = $pdo;
    return $pdo;
}

/** Executa e devolve 'ok', '403: msg', '409: msg', '400: msg' ou a classe da excecao. */
function qa_outcome(callable $call): string {
    try {
        $call();
        return 'ok';
    } catch (PortalForbiddenException $e) {
        return '403: ' . $e->getMessage();
    } catch (InvalidArgumentException $e) {
        return '400: ' . $e->getMessage();
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
}

function qa_rule(int $id, string $type, string $from, ?string $until, ?string $config = null): array {
    return ['id' => $id, 'rule_type' => $type, 'rule_config' => $config, 'effective_from' => $from, 'effective_until' => $until];
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

/** SQL sem comentarios de linha (-- ...), para as checagens estruturais. */
function qa_sql_without_comments(string $sql): string {
    return (string)preg_replace('/^\s*--.*$/m', '', $sql);
}

// ----------------------------------------------------------------------------
// 1. Migration 011, rollback e consulta de verificacao (estrutura)
// ----------------------------------------------------------------------------
$migrationPath = __DIR__ . '/../migrations/20261005_011_schedule_history.sql';
$rollbackPath = __DIR__ . '/../migrations/rollback/20261005_011_schedule_history_down.sql';
$checkPath = __DIR__ . '/../migrations/verificacao/20261005_011_schedule_history_check.sql';
$migration = qa_sql_without_comments((string)file_get_contents($migrationPath));
$rollback = qa_sql_without_comments((string)file_get_contents($rollbackPath));
$check = qa_sql_without_comments((string)file_get_contents($checkPath));
assert_same(true, $migration !== '' && $rollback !== '' && $check !== '', 'le a 011, o rollback e a verificacao');

foreach (['migration' => $migration, 'rollback' => $rollback] as $label => $sql) {
    assert_same(1, preg_match("/^\\s*SET time_zone = 'America\\/Sao_Paulo';/", $sql), "{$label}: comeca com SET time_zone (regra do A4)");
    assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $sql), "{$label}: sem relogio do banco (regra do A4)");
    assert_same(0, preg_match('/^\s*ALTER TABLE/mi', $sql), "{$label}: todo ALTER fica dentro de IF com checagem em information_schema (idempotente)");
    $prepares = preg_match_all('/^PREPARE /m', $sql);
    assert_same(true, $prepares > 0, "{$label}: usa PREPARE");
    assert_same($prepares, preg_match_all('/^EXECUTE /m', $sql), "{$label}: todo PREPARE tem EXECUTE");
    assert_same($prepares, preg_match_all('/^DEALLOCATE PREPARE /m', $sql), "{$label}: todo PREPARE tem DEALLOCATE");
    // Nenhum cadastro fixo: id, nome ou login literal em filtro.
    assert_same(0, preg_match('/\b(id|employee_id)\s*(=|IN)\s*\(?\s*\d/i', $sql), "{$label}: sem ID fixo de colaborador");
    assert_same(0, preg_match('/\b(nome|employee_name|ad_login)\s*(=|LIKE|IN)\s*\(?\s*\'/i', $sql), "{$label}: sem nome ou login fixo");
}

$position = static fn (string $sql, string $needle): int => ($found = strpos($sql, $needle)) === false ? -1 : $found;
assert_same(true, $position($migration, 'ADD INDEX idx_schedule_rule_employee_period') > -1, '011 cria o indice do periodo');
assert_same(true, $position($migration, 'ADD INDEX idx_schedule_rule_employee_period') < $position($migration, 'DROP INDEX uq_schedule_rule_employee'), '011 cria o indice da FK antes de derrubar a UNIQUE');
assert_same(true, $position($migration, 'ADD UNIQUE KEY uq_schedule_rule_employee_from (employee_id, effective_from)') > -1, '011: uma regra por data de inicio');
assert_same(1, preg_match('/GENERATED ALWAYS AS \(CASE WHEN effective_until IS NULL THEN employee_id ELSE NULL END\) STORED/', $migration), '011: coluna gerada da regra aberta');
assert_same(true, $position($migration, 'ADD UNIQUE KEY uq_schedule_rule_open (open_employee_id)') > -1, '011: uma regra aberta por colaborador');
assert_same(1, preg_match("/ENUM\\(''even_days'', ''odd_days'', ''always_onsite'', ''always_remote'', ''undefined'', ''fixed_weekdays'', ''cycle''\\)/", $migration), '011: cycle no fim do ENUM, sem mudar a posicao dos valores');
assert_same(2, substr_count($migration, "MODIFY COLUMN team ENUM(''n1'', ''n2'', ''lideranca'') NOT NULL"), '011: lideranca em regras e excecoes (passo 2.6)');
assert_same(1, preg_match("/ADD COLUMN fora_mapa_escala TINYINT\\(1\\) NOT NULL DEFAULT 0/", $migration), '011: fora_mapa_escala com 0 para todos');
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/mi', $migration), '011 nao altera linha de dado');

assert_same(true, $position($rollback, 'ADD UNIQUE KEY uq_schedule_rule_employee (employee_id)') < $position($rollback, 'DROP INDEX idx_schedule_rule_employee_period'), 'rollback recria a UNIQUE antes de tirar o indice da FK');
assert_same(true, $position($rollback, 'ADD UNIQUE KEY uq_schedule_rule_employee (employee_id)') > $position($rollback, 'WHERE posicao > 1'), 'rollback reduz a uma regra por colaborador antes da UNIQUE');
foreach (['portal_schedule_rules_bkp_011', 'portal_schedule_exceptions_bkp_011', 'funcionarios_fora_mapa_bkp_011'] as $backup) {
    assert_same(true, $position($rollback, "CREATE TABLE {$backup} AS") > -1, "rollback copia para {$backup}");
    assert_same(true, $position($rollback, "CREATE TABLE {$backup} AS") < $position($rollback, 'DELETE FROM'), "rollback copia {$backup} antes de apagar");
}
assert_same(true, $position($rollback, "UPDATE portal_schedule_rules SET rule_type = 'undefined', rule_config = NULL WHERE rule_type = 'cycle'") < $position($rollback, '@remove_cycle_sql'), 'rollback converte cycle antes de encolher o ENUM');
assert_same(true, $position($rollback, "DELETE FROM portal_schedule_exceptions WHERE team = 'lideranca'") < $position($rollback, '@remove_exception_team_sql'), 'rollback tira excecao de lideranca antes de encolher o ENUM');
assert_same(true, $position($rollback, 'ORDER BY (effective_until IS NULL) DESC, effective_from DESC, id DESC') > -1, 'rollback mantem a regra aberta ou a de inicio mais recente');
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE)\b/i', $check), 'verificacao so le');
assert_same(0, preg_match('/\b(employee_name|nome|ad_login|note)\b/i', $check), 'verificacao nao devolve nome, login nem observacao');

// ----------------------------------------------------------------------------
// 2. Planejador de vigencia (funcao pura)
// ----------------------------------------------------------------------------
$today = '2026-10-05';
$weekdaysMonWed = '{"weekdays":["mon","wed"]}';

// Sem regra: insere aberta.
$plan = ScheduleRulePlanner::planSave([], '2026-10-05', 'even_days', null, $today);
assert_same(['insert', null, null, null, false], [$plan['action'], $plan['effective_until'], $plan['close'], $plan['previous'], $plan['retroactive']], 'planejador: primeira regra aberta');

// Regra vigente: fecha na vespera e a nova herda o fim (aberta).
$current = [qa_rule(10, 'odd_days', '2026-09-01', null)];
$plan = ScheduleRulePlanner::planSave($current, '2026-10-05', 'even_days', null, $today);
assert_same('insert', $plan['action'], 'planejador: troca de regra insere');
assert_same(['id' => 10, 'effective_until' => '2026-10-04', 'previous_until' => null], $plan['close'], 'planejador: fecha a vigente na vespera');
assert_same(null, $plan['effective_until'], 'planejador: nova regra fica aberta');
assert_same(10, (int)$plan['previous']['id'], 'planejador: anterior para a auditoria');

// Mesmo inicio: atualiza no lugar, sem mudar o fim.
$plan = ScheduleRulePlanner::planSave([qa_rule(11, 'odd_days', '2026-10-05', null)], '2026-10-05', 'even_days', null, $today);
assert_same(['update', 11, null, null], [$plan['action'], $plan['target_id'], $plan['effective_until'], $plan['close']], 'planejador: correcao no mesmo dia atualiza no lugar');

// Igual a vigente: nada muda (reimportacao).
$plan = ScheduleRulePlanner::planSave($current, '2026-10-20', 'odd_days', null, $today);
assert_same(['unchanged', 10], [$plan['action'], $plan['target_id']], 'planejador: regra igual a vigente nao cria linha');
$plan = ScheduleRulePlanner::planSave([qa_rule(12, 'fixed_weekdays', '2026-09-01', null, $weekdaysMonWed)], '2026-10-05', 'fixed_weekdays', '{"weekdays": ["mon", "wed"]}', $today);
assert_same('unchanged', $plan['action'], 'planejador: configuracao igual com outra grafia do JSON');
$plan = ScheduleRulePlanner::planSave([qa_rule(12, 'fixed_weekdays', '2026-09-01', null, $weekdaysMonWed)], '2026-10-05', 'fixed_weekdays', '{"weekdays":["mon","fri"]}', $today);
assert_same('insert', $plan['action'], 'planejador: mesmos tipo e dias diferentes muda');

// Regra futura: a nova vale ate a vespera dela.
$withFuture = [qa_rule(10, 'odd_days', '2026-09-01', '2026-10-19'), qa_rule(13, 'always_onsite', '2026-10-20', null)];
$plan = ScheduleRulePlanner::planSave($withFuture, '2026-10-10', 'even_days', null, $today);
assert_same([10, '2026-10-09', '2026-10-19'], [$plan['close']['id'], $plan['close']['effective_until'], $plan['effective_until']], 'planejador: regra no meio termina antes da agendada');
$plan = ScheduleRulePlanner::planSave([qa_rule(13, 'always_onsite', '2026-10-20', null)], '2026-10-06', 'even_days', null, $today);
assert_same([null, '2026-10-19'], [$plan['close'], $plan['effective_until']], 'planejador: sem vigente, vale ate a vespera da proxima');
$plan = ScheduleRulePlanner::planSave($current, '2026-11-01', 'even_days', null, $today);
assert_same(['2026-10-31', null, false], [$plan['close']['effective_until'], $plan['effective_until'], $plan['retroactive']], 'planejador: agendar regra futura fecha a vigente na vespera');

// Retroativa.
$plan = ScheduleRulePlanner::planSave($current, '2026-09-15', 'even_days', null, $today);
assert_same([true, '2026-09-14', null], [$plan['retroactive'], $plan['close']['effective_until'], $plan['effective_until']], 'planejador: inicio retroativo marcado');
$plan = ScheduleRulePlanner::planSave($current, '2026-08-01', 'even_days', null, $today);
assert_same([null, '2026-08-31', true], [$plan['close'], $plan['effective_until'], $plan['retroactive']], 'planejador: retroativa antes de toda regra vale ate a vespera da primeira');

// Lacuna de escala removida: a nova regra nao reabre a remocao.
$removed = [qa_rule(14, 'odd_days', '2026-09-01', '2026-09-30')];
$plan = ScheduleRulePlanner::planSave($removed, '2026-09-20', 'even_days', null, $today);
assert_same(['2026-09-19', '2026-09-30'], [$plan['close']['effective_until'], $plan['effective_until']], 'planejador: dentro de periodo encerrado, a nova termina onde ele terminava');
$plan = ScheduleRulePlanner::planSave($removed, '2026-10-05', 'even_days', null, $today);
assert_same(['insert', null, null], [$plan['action'], $plan['close'], $plan['effective_until']], 'planejador: recadastro depois da remocao cria regra aberta');

// Linha vazia (escala removida antes de comecar, codigo anterior): reaproveitada.
$empty = [qa_rule(15, 'undefined', '2026-10-10', '2026-10-04')];
assert_same(true, ScheduleRulePlanner::isEmpty($empty[0]), 'planejador: linha vazia reconhecida');
assert_same(null, ScheduleRulePlanner::ruleAt($empty, '2026-10-10'), 'planejador: linha vazia nao vigora');
$plan = ScheduleRulePlanner::planSave($empty, '2026-10-10', 'even_days', null, $today);
assert_same(['update', 15, null, null], [$plan['action'], $plan['target_id'], $plan['effective_until'], $plan['close']], 'planejador: linha vazia com o mesmo inicio e reaproveitada');
$plan = ScheduleRulePlanner::planSave(array_merge($empty, [qa_rule(16, 'odd_days', '2026-09-01', null)]), '2026-10-06', 'even_days', null, $today);
assert_same(null, $plan['effective_until'], 'planejador: linha vazia futura nao corta a nova regra');

// Vigente e agendada.
foreach ([
    [qa_rule(1, 'odd_days', '2026-09-01', null), true, false],
    [qa_rule(1, 'odd_days', '2026-10-05', '2026-10-05'), true, false],
    [qa_rule(1, 'odd_days', '2026-09-01', '2026-10-04'), false, false],
    [qa_rule(1, 'odd_days', '2026-10-06', null), false, true],
    [qa_rule(1, 'odd_days', '2026-10-10', '2026-10-04'), false, false],
] as [$rule, $isCurrent, $isFuture]) {
    $label = $rule['effective_from'] . '..' . ($rule['effective_until'] ?? 'NULL');
    assert_same($isCurrent, ScheduleRulePlanner::isCurrent($rule, $today), "is_current {$label}");
    assert_same($isFuture, ScheduleRulePlanner::isFuture($rule, $today), "is_future {$label}");
}

// Remocao.
$plan = ScheduleRulePlanner::planRemove($withFuture, $today);
assert_same([10, '2026-10-04', [13]], [$plan['close']['id'], $plan['close']['effective_until'], array_column($plan['delete'], 'id')], 'remocao: fecha a vigente ontem e apaga a agendada');
$plan = ScheduleRulePlanner::planRemove([qa_rule(17, 'odd_days', '2026-10-05', null)], $today);
assert_same([null, [17]], [$plan['close'], array_column($plan['delete'], 'id')], 'remocao: regra que comeca hoje e apagada');
$plan = ScheduleRulePlanner::planRemove($removed, $today);
assert_same([null, []], [$plan['close'], $plan['delete']], 'remocao: nada vigente nem agendado');
$plan = ScheduleRulePlanner::planRemove($empty, $today);
assert_same([], $plan['delete'], 'remocao: linha vazia antiga nao e apagada');

// Invariantes.
assert_same([], ScheduleRulePlanner::violations($withFuture), 'invariantes: historico valido');
assert_same(true, ScheduleRulePlanner::violations([qa_rule(1, 'a', '2026-09-01', null), qa_rule(2, 'b', '2026-10-01', null)]) !== [], 'invariantes: duas abertas');
assert_same(true, ScheduleRulePlanner::violations([qa_rule(1, 'a', '2026-09-01', '2026-10-10'), qa_rule(2, 'b', '2026-10-01', '2026-10-31')]) !== [], 'invariantes: sobreposicao');
assert_same(true, ScheduleRulePlanner::violations([qa_rule(1, 'a', '2026-09-01', '2026-08-01'), qa_rule(2, 'b', '2026-09-01', null)]) !== [], 'invariantes: inicio repetido');
assert_same([], ScheduleRulePlanner::violations([qa_rule(1, 'a', '2026-10-10', '2026-10-04'), qa_rule(2, 'b', '2026-09-01', null)]), 'invariantes: linha vazia nao sobrepoe');

// ----------------------------------------------------------------------------
// 3. Servico de escala contra o banco simulado
// ----------------------------------------------------------------------------
$pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', null)]);
$service = new OperationalService($pdo, $today);
$saved = $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'even_days', 'effective_from' => '2026-10-05'], 'qa.gestor', 'gestor');
assert_same([false, 10, '2026-10-04', false], [$saved['unchanged'], $saved['closed_rule_id'], $saved['closed_until'], $saved['retroactive']], 'servico: troca de regra informa a encerrada');
assert_same('2026-10-04', $pdo->rules[10]['effective_until'], 'servico: regra anterior fechada na vespera');
assert_same('odd_days', $pdo->rules[10]['rule_type'], 'servico: regra anterior mantem o tipo (historico)');
assert_same(['even_days', '2026-10-05', null], [$pdo->rules[$saved['id']]['rule_type'], $pdo->rules[$saved['id']]['effective_from'], $pdo->rules[$saved['id']]['effective_until']], 'servico: nova regra aberta');
$lockAt = array_search('SELECT id FROM funcionarios WHERE id = :id AND ativo = 1 FOR UPDATE', $pdo->events, true);
$readAt = array_search('SELECT id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id', $pdo->events, true);
assert_same(true, $lockAt !== false && $readAt !== false && $lockAt < $readAt, 'servico: trava o colaborador antes de ler o historico');
$audit = end($pdo->audits);
assert_same(['SCHEDULE_RULE_UPDATED', 'WARNING'], [$audit['action'], $audit['severity']], 'servico: troca auditada');
assert_same(true, strpos($audit['details'], 'retroativa=nao') !== false && strpos($audit['details'], 'encerrada=10 ate 2026-10-04') !== false, 'servico: auditoria com retroativa e regra encerrada');

$audits = count($pdo->audits);
$again = $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'even_days', 'effective_from' => '2026-10-20'], 'qa.gestor', 'gestor');
assert_same([true, $saved['id']], [$again['unchanged'], $again['id']], 'servico: regra igual a vigente nao grava');
assert_same([2, $audits], [count($pdo->rules), count($pdo->audits)], 'servico: nada gravado nem auditado quando igual');

// P10 e L6: inicio retroativo so admin e Lideranca; gestor recebe 403 antes de travar.
$pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', null)]);
$service = new OperationalService($pdo, $today);
assert_same('403: Somente administradores e Liderança gravam regra com início retroativo.', qa_outcome(fn () => $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'even_days', 'effective_from' => '2026-10-04'], 'qa', 'gestor')), 'P10: gestor nao grava retroativa');
assert_same(false, in_array('SELECT id FROM funcionarios WHERE id = :id AND ativo = 1 FOR UPDATE', $pdo->events, true), 'P10: recusa antes de travar e de ler o historico');
assert_same([null], array_column($pdo->rules, 'effective_until'), 'P10: nada gravado na recusa');
foreach (['lideranca', 'admin'] as $role) {
    $pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', null)]);
    $service = new OperationalService($pdo, $today);
    $saved = $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'even_days', 'effective_from' => '2026-09-15'], 'qa', $role);
    assert_same([true, '2026-09-14'], [$saved['retroactive'], $pdo->rules[10]['effective_until']], "L6: {$role} grava retroativa");
    assert_same(true, strpos(end($pdo->audits)['details'], 'retroativa=sim') !== false, "L6: retroativa de {$role} auditada");
}
foreach (['gestor', 'lideranca', 'admin'] as $role) {
    $pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', null)]);
    $service = new OperationalService($pdo, $today);
    assert_same('ok', qa_outcome(fn () => $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'even_days'], 'qa', $role)), "sem data: hoje, permitido a {$role}");
    assert_same('ok', qa_outcome(fn () => $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'always_remote', 'effective_from' => '2026-11-02'], 'qa', $role)), "regra agendada permitida a {$role}");
}
assert_same(false, portal_role_can('gestor', 'escalas.retroativa'), 'permissao retroativa: gestor nao');
assert_same(true, portal_role_can('lideranca', 'escalas.retroativa') && portal_role_can('admin', 'escalas.retroativa'), 'permissao retroativa: Lideranca e admin');

// Remocao: fecha ontem sem trocar o tipo e apaga a agendada, com auditoria.
$pdo = qa_schedule_db([qa_rule(10, 'fixed_weekdays', '2026-09-01', '2026-10-19', $weekdaysMonWed), qa_rule(13, 'always_onsite', '2026-10-20', null)]);
$service = new OperationalService($pdo, $today);
assert_same(2, $service->removeScheduleRule(1, 'qa.lider', 'lideranca'), 'remocao: duas linhas afetadas');
assert_same(['fixed_weekdays', $weekdaysMonWed, '2026-10-04'], [$pdo->rules[10]['rule_type'], $pdo->rules[10]['rule_config'], $pdo->rules[10]['effective_until']], 'remocao: regra vigente fechada ontem com tipo e dias (L-08)');
assert_same(false, isset($pdo->rules[13]), 'remocao: regra agendada apagada');
$audit = end($pdo->audits);
assert_same(['SCHEDULE_RULE_REMOVED', 'WARNING'], [$audit['action'], $audit['severity']], 'remocao: auditada');
assert_same(true, strpos($audit['details'], 'apagadas=13:always_onsite 2026-10-20..NULL') !== false, 'remocao: conteudo da apagada na auditoria');
assert_same('409: Colaborador nao possui regra de escala vigente nem agendada.', qa_outcome(fn () => $service->removeScheduleRule(1, 'qa.lider', 'lideranca')), 'remocao: sem regra vigente nem agendada');

// Rollback da transacao: violacao desfaz tudo (UNIQUE de regra aberta simulada).
$pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', null), qa_rule(11, 'even_days', '2026-08-01', '2026-08-31')]);
$pdo->rules[11]['effective_until'] = null; // dado inconsistente de proposito: duas abertas
$service = new OperationalService($pdo, $today);
$before = $pdo->rules;
$outcome = qa_outcome(fn () => $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'always_remote', 'effective_from' => '2026-08-15'], 'qa', 'admin'));
assert_same(true, strpos($outcome, 'PDOException') === 0 || strpos($outcome, 'RuntimeException') === 0, 'servico: gravacao que quebraria o historico falha');
assert_same([$before, 'rollback'], [$pdo->rules, end($pdo->events)], 'servico: falha desfaz a transacao inteira');

// Propriedade: sequencias aleatorias de gravacao e remocao, por quem pode
// retroativo. A cada passo: nenhuma violacao; antes do inicio, nada muda; do
// inicio ate o fim do trecho substituido, vale a regra nova; depois, nada
// muda. Na remocao: antes de hoje nada muda; de hoje em diante, sem regra.
$types = [
    ['even_days', null], ['odd_days', null], ['always_onsite', null], ['always_remote', null],
    ['fixed_weekdays', $weekdaysMonWed], ['fixed_weekdays', '{"weekdays":["tue","thu"]}'],
];
$window = ['2026-08-01', '2027-01-31'];
$operations = 0;
for ($seed = 1; $seed <= 40; $seed++) {
    mt_srand($seed);
    $initial = mt_rand(0, 2) === 0
        ? [qa_rule(1, 'undefined', ScheduleRulePlanner::addDays('2026-10-05', mt_rand(1, 20)), '2026-10-04')]
        : [];
    $pdo = qa_schedule_db($initial);
    $service = new OperationalService($pdo, $today);
    for ($step = 0; $step < 30; $step++) {
        $before = $pdo->timeline(1, ...$window);
        $rulesBefore = array_values(array_filter($pdo->rules, static fn (array $rule): bool => $rule['employee_id'] === 1));
        if (mt_rand(0, 5) === 0) {
            $outcome = qa_outcome(fn () => $service->removeScheduleRule(1, 'qa', 'admin'));
            $after = $pdo->timeline(1, ...$window);
            foreach ($after as $date => $value) {
                $expected = $date < $today ? $before[$date] : null;
                assert_same($expected, $value, "propriedade (semente {$seed}, passo {$step}): remocao em {$date}");
            }
            assert_same(true, $outcome === 'ok' || strpos($outcome, '409:') === 0, "propriedade (semente {$seed}): remocao sem erro inesperado");
        } else {
            [$type, $config] = $types[mt_rand(0, count($types) - 1)];
            $from = ScheduleRulePlanner::addDays('2026-09-01', mt_rand(0, 100));
            $cover = ScheduleRulePlanner::ruleAt($rulesBefore, $from);
            $segmentEnd = $cover !== null ? $cover['effective_until'] : null;
            if ($cover === null) {
                foreach ($rulesBefore as $rule) {
                    if (!ScheduleRulePlanner::isEmpty($rule) && $rule['effective_from'] > $from
                        && ($segmentEnd === null || $rule['effective_from'] <= $segmentEnd)) {
                        $segmentEnd = ScheduleRulePlanner::addDays($rule['effective_from'], -1);
                    }
                }
            }
            $payload = ['employee_id' => 1, 'rule_type' => $type, 'effective_from' => $from];
            if ($config !== null) {
                $payload['weekdays'] = json_decode($config, true)['weekdays'];
            }
            assert_same('ok', qa_outcome(fn () => $service->saveScheduleRule($payload, 'qa', 'admin')), "propriedade (semente {$seed}, passo {$step}): gravacao");
            $after = $pdo->timeline(1, ...$window);
            foreach ($after as $date => $value) {
                $inSegment = $date >= $from && ($segmentEnd === null || $date <= $segmentEnd);
                $expected = $inSegment ? $type . ' ' . ($config ?? '') : $before[$date];
                assert_same($expected, $value, "propriedade (semente {$seed}, passo {$step}): {$type} desde {$from}, data {$date}");
            }
        }
        $rulesAfter = array_values(array_filter($pdo->rules, static fn (array $rule): bool => $rule['employee_id'] === 1));
        assert_same([], ScheduleRulePlanner::violations($rulesAfter), "propriedade (semente {$seed}, passo {$step}): invariantes");
        $operations++;
    }
}
assert_same(1200, $operations, 'propriedade: 1.200 operacoes conferidas');

echo "QA schedule history OK\n";
