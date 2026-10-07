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

// Excecao sem captura vai para o STDERR (o log de erros do QA e temporario).
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

/**
 * Banco simulado: tabelas em memoria e so as consultas que o servico de
 * escala faz. Consulta nao prevista falha como banco fora. Gravacao que
 * violaria uq_schedule_rule_employee_from ou uq_schedule_rule_open falha
 * como o MySQL (erro 1062) e nao muda nada.
 *
 * REPEATABLE READ (revisao do F1): a primeira leitura simples da transacao
 * fixa o snapshot das regras; leitura simples do historico devolve esse
 * snapshot mais as gravacoes da propria transacao, e so a leitura com trava
 * (FOR UPDATE) ve o que outra transacao gravou depois. $whileWaitingLock
 * simula outra transacao que grava e faz commit enquanto esta espera a trava
 * do colaborador.
 */
final class QaSchedulePdo extends PDO {
    public array $employees = [];
    public array $rules = [];
    public array $exceptions = [];
    public array $paAssignments = [];
    public array $absences = [];
    public array $sync = [];
    public array $audits = [];
    public array $events = [];
    public int $nextId = 1000;
    public ?array $readView = null;
    public array $txWritten = [];
    public ?Closure $whileWaitingLock = null;
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
        $this->readView = null;
        $this->txWritten = [];
        $this->events[] = 'begin';
        return true;
    }

    public function commit(): bool {
        $this->snapshot = null;
        $this->readView = null;
        $this->txWritten = [];
        $this->events[] = 'commit';
        return true;
    }

    public function rollBack(): bool {
        [$this->rules, $this->exceptions, $this->audits, $this->nextId] = $this->snapshot;
        $this->snapshot = null;
        $this->readView = null;
        $this->txWritten = [];
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
        foreach (array_unique(array_merge(array_keys($this->rules), array_keys($rules))) as $id) {
            if (($this->rules[$id] ?? null) !== ($rules[$id] ?? null)) {
                $this->txWritten[$id] = true;
            }
        }
        $this->rules = $rules;
    }

    /** Regras vistas por uma leitura simples: snapshot da transacao mais as proprias gravacoes. */
    public function consistentRules(): array {
        if ($this->snapshot === null || $this->readView === null) {
            return $this->rules;
        }
        $rules = $this->readView;
        foreach (array_keys($this->txWritten) as $id) {
            if (isset($this->rules[$id])) {
                $rules[$id] = $this->rules[$id];
            } else {
                unset($rules[$id]);
            }
        }
        return $rules;
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
        // Primeira leitura simples da transacao fixa o snapshot (REPEATABLE READ).
        if ($db->inTransaction() && $db->readView === null && strpos($sql, 'SELECT') === 0 && substr($sql, -10) !== 'FOR UPDATE') {
            $db->readView = $db->rules;
        }
        if ($sql === 'SELECT 1') {
            return true;
        }
        if ($sql === 'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)') {
            $db->audits[] = ['action' => $params[2], 'details' => $params[3], 'severity' => $params[4]];
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_sync_queue') === 0) {
            $db->sync[] = $params;
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
            if ($db->whileWaitingLock !== null) {
                $concurrent = $db->whileWaitingLock;
                $db->whileWaitingLock = null;
                $concurrent($db);
            }
            $row = $db->employees[(int)$params[':id']] ?? null;
            if ($row !== null && (int)$row['ativo'] === 1) {
                $this->rows[] = [$match[1] => $match[1] === 'id' ? (int)$params[':id'] : (int)($row['fora_mapa_escala'] ?? 0)];
            }
            return true;
        }
        if (preg_match('/^SELECT id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id( FOR UPDATE)?$/', $sql, $match)) {
            foreach (isset($match[1]) ? $db->rules : $db->consistentRules() as $rule) {
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
        // Excecoes: upsert por colaborador e data, e leitura do id.
        if (strpos($sql, 'INSERT INTO portal_schedule_exceptions (') === 0) {
            if (!in_array($params[':team'], ['n1', 'n2', 'lideranca'], true)) {
                throw new PDOException('SQLSTATE[01000]: Data truncated for column team');
            }
            $key = $params[':employee_id'] . ':' . $params[':exception_date'];
            $db->exceptions[$key] = [
                'id' => $db->exceptions[$key]['id'] ?? $db->nextId++, 'employee_id' => (int)$params[':employee_id'],
                'employee_name' => $params[':employee_name'], 'team' => $params[':team'], 'exception_date' => $params[':exception_date'],
                'exception_type' => $params[':exception_type'], 'note' => $params[':note'],
            ];
            return true;
        }
        if ($sql === 'SELECT id FROM portal_schedule_exceptions WHERE employee_id = :employee_id AND exception_date = :exception_date') {
            $row = $db->exceptions[$params[':employee_id'] . ':' . $params[':exception_date']] ?? null;
            $this->rows = $row ? [['id' => $row['id']]] : [];
            return true;
        }
        // Importacao: identificacao por id ou login (as condicoes do servico).
        if (preg_match('/^SELECT id, nome AS name, LOWER\(equipe\) AS team, ad_login(, fora_mapa_escala)? FROM funcionarios WHERE ativo = 1 AND \(/', $sql)) {
            foreach ($db->employees as $id => $row) {
                $byId = isset($params[':id']) && (int)$params[':id'] === $id;
                $byLogin = isset($params[':ad_login']) && strtolower((string)$row['ad_login']) === $params[':ad_login'];
                if ((int)$row['ativo'] === 1 && ($byId || $byLogin)) {
                    $this->rows[] = ['id' => $id, 'name' => $row['nome'], 'team' => strtolower($row['equipe']), 'ad_login' => $row['ad_login']]
                        + (strpos($sql, 'fora_mapa_escala') !== false ? ['fora_mapa_escala' => (int)$row['fora_mapa_escala']] : []);
                }
            }
            return true;
        }
        // GET de escalas: regras com cadastro (N1 e N2), Lideranca, excecoes e ausencias.
        if (strpos($sql, 'SELECT r.id, r.employee_id, f.nome AS employee_name,') === 0) {
            // O filtro de periodo so e emulado na forma exata do servico.
            if (isset($params[':period_from']) && strpos($sql, 'AND (r.effective_until IS NULL OR (r.effective_until >= :period_from AND r.effective_until >= r.effective_from))') === false) {
                throw new PDOException('SQLSTATE[HY000]: banco simulado: filtro de periodo nao previsto');
            }
            foreach ($db->rules as $rule) {
                $employee = $db->employees[$rule['employee_id']] ?? null;
                if ($employee === null || (int)$employee['ativo'] !== 1 || !in_array(strtolower($employee['equipe']), ['n1', 'n2'], true)
                    || (strpos($sql, 'f.fora_mapa_escala = 0') !== false && (int)$employee['fora_mapa_escala'] === 1)
                    || (isset($params[':team']) && strtolower($employee['equipe']) !== $params[':team'])
                    || (isset($params[':employee_id']) && $rule['employee_id'] !== $params[':employee_id'])
                    || (isset($params[':period_from']) && $rule['effective_until'] !== null
                        && ($rule['effective_until'] < $params[':period_from'] || $rule['effective_until'] < $rule['effective_from']))) {
                    continue;
                }
                $this->rows[] = $rule + ['employee_name' => $employee['nome'], 'ad_login' => $employee['ad_login'], 'created_by' => 'qa', 'updated_by' => 'qa', 'created_at' => null, 'updated_at' => null];
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, nome AS employee_name, ad_login, equipe, jornada_entrada, jornada_saida FROM funcionarios WHERE ativo = 1') === 0) {
            foreach ($db->employees as $id => $row) {
                if ((int)$row['ativo'] !== 1 || (isset($params[':employee_id']) && $params[':employee_id'] !== $id)
                    || (strpos($sql, 'fora_mapa_escala = 0') !== false && (int)$row['fora_mapa_escala'] === 1)) {
                    continue;
                }
                $this->rows[] = ['id' => $id, 'employee_name' => $row['nome'], 'ad_login' => $row['ad_login'], 'equipe' => $row['equipe'],
                    'jornada_entrada' => $row['jornada_entrada'] ?? '08:00:00', 'jornada_saida' => $row['jornada_saida'] ?? '17:00:00'];
            }
            return true;
        }
        // [F2B] Cadastros da tela Escala (motor): ativos, sem os fora do Mapa.
        if (strpos($sql, 'SELECT id, nome AS employee_name, equipe FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0') === 0) {
            foreach ($db->employees as $id => $row) {
                if ((int)$row['ativo'] !== 1 || (int)$row['fora_mapa_escala'] === 1
                    || (isset($params[':employee_id']) && $params[':employee_id'] !== $id)) {
                    continue;
                }
                $this->rows[] = ['id' => $id, 'employee_name' => $row['nome'], 'equipe' => $row['equipe']];
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['employee_name'], $a['id']] <=> [$b['employee_name'], $b['id']]);
            return true;
        }
        if (strpos($sql, 'SELECT id, employee_id, employee_name, team, exception_date,') === 0) {
            foreach ($db->exceptions as $row) {
                if ($row['exception_date'] >= $params[':date_from'] && $row['exception_date'] <= $params[':date_to']) {
                    $this->rows[] = $row;
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT a.employee_id, a.starts_on, a.ends_on FROM portal_absences a') === 0) {
            foreach ($db->absences as $row) {
                if ($row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']
                    && (!isset($params[':employee_id']) || $row['employee_id'] === $params[':employee_id'])) {
                    $this->rows[] = $row;
                }
            }
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

/** Trava do colaborador nas gravacoes de escala (le a marcacao fora do Mapa). */
const QA_LOCK_SQL = 'SELECT fora_mapa_escala FROM funcionarios WHERE id = :id AND ativo = 1 FOR UPDATE';
/** Leitura do historico: com trava (revisao do F1). */
const QA_HISTORY_SQL = 'SELECT id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id FOR UPDATE';

/** Banco simulado com um cadastro N1 (1), um N2 (2), um da Lideranca (3) e as regras dadas. */
function qa_schedule_db(array $rules = []): QaSchedulePdo {
    $pdo = new QaSchedulePdo();
    $pdo->employees = [
        1 => ['nome' => 'Tecnico QA 1', 'equipe' => 'n1', 'ad_login' => 'qa.tecnico1', 'access_role' => 'tecnico', 'ativo' => 1, 'fora_mapa_escala' => 0],
        2 => ['nome' => 'Tecnico QA 2', 'equipe' => 'N2', 'ad_login' => 'qa.tecnico2', 'access_role' => 'tecnico', 'ativo' => 1, 'fora_mapa_escala' => 0],
        3 => ['nome' => 'Lider QA', 'equipe' => 'Liderança', 'ad_login' => 'qa.lider', 'access_role' => 'lideranca', 'ativo' => 1, 'fora_mapa_escala' => 0,
            'jornada_entrada' => '09:00:00', 'jornada_saida' => '18:00:00'],
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
// Revisao do F1: com qualquer copia *_bkp_011 ja existente, o rollback para
// antes de alterar (um segundo rollback apagava o historico novo e a copia
// ficava a antiga).
$abortAt = $position($rollback, 'CALL rollback_011_confere_copias();');
assert_same(1, preg_match("/IF \(SELECT COUNT\(\*\) FROM information_schema\.TABLES\s+WHERE TABLE_SCHEMA = DATABASE\(\)\s+AND TABLE_NAME IN \('portal_schedule_rules_bkp_011', 'portal_schedule_exceptions_bkp_011', 'funcionarios_fora_mapa_bkp_011'\)\) > 0 THEN\s+SIGNAL SQLSTATE '45000'/", $rollback), 'rollback: SIGNAL se qualquer copia ja existir');
assert_same(1, preg_match("/SET MESSAGE_TEXT = '(rollback_011 abortado: [^']+)'/", $rollback, $signalMessage), 'rollback: mensagem da parada');
assert_same(true, strlen($signalMessage[1]) <= 128 && strpos($signalMessage[1], 'Nada foi alterado') !== false, 'rollback: mensagem clara e dentro do limite de 128 do SIGNAL');
assert_same(true, $position($rollback, 'DROP PROCEDURE IF EXISTS rollback_011_confere_copias;') < $position($rollback, 'CREATE PROCEDURE rollback_011_confere_copias()'), 'rollback: procedimento de checagem recriado a cada execucao');
assert_same(true, $abortAt > -1 && $abortAt === min(array_filter([
    $position($rollback, 'CREATE TABLE portal_schedule_rules_bkp_011'), $position($rollback, 'DELETE FROM'),
    $position($rollback, 'UPDATE '), $position($rollback, 'ALTER TABLE'), $abortAt,
], static fn (int $at): bool => $at > -1)), 'rollback: checagem das copias antes de qualquer alteracao');
assert_same(0, preg_match('/@has_(rules|exceptions|fora_mapa)_bkp|CREATE TABLE IF NOT EXISTS/', $rollback), 'rollback: copia nunca e pulada em silencio');
assert_same(true, $position($rollback, 'DROP PROCEDURE rollback_011_confere_copias;') < $position($rollback, 'CREATE TABLE portal_schedule_rules_bkp_011'), 'rollback: procedimento removido quando a checagem passa');
assert_same(true, $position($rollback, "UPDATE portal_schedule_rules SET rule_type = 'undefined', rule_config = NULL WHERE rule_type = 'cycle'") < $position($rollback, '@remove_cycle_sql'), 'rollback converte cycle antes de encolher o ENUM');
assert_same(true, $position($rollback, "DELETE FROM portal_schedule_exceptions WHERE team = 'lideranca'") < $position($rollback, '@remove_exception_team_sql'), 'rollback tira excecao de lideranca antes de encolher o ENUM');
assert_same(true, $position($rollback, 'ORDER BY (effective_until IS NULL) DESC, effective_from DESC, id DESC') > -1, 'rollback mantem a regra aberta ou a de inicio mais recente');
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE)\b/i', $check), 'verificacao so le');
assert_same(0, preg_match('/\b(employee_name|nome|ad_login|note)\b/i', $check), 'verificacao nao devolve nome, login nem observacao');
assert_same(2, preg_match_all('/BIT_XOR\(CRC32\(CONCAT_WS\(/', $check), 'verificacao: assinatura das colunas originais de regras e excecoes (o CHECKSUM TABLE muda com a coluna gerada)');
assert_same(0, preg_match('/open_employee_id|fora_mapa_escala/', $check), 'verificacao: so colunas que existem antes da 011 e depois do rollback');

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
$lockAt = array_search(QA_LOCK_SQL, $pdo->events, true);
$readAt = array_search(QA_HISTORY_SQL, $pdo->events, true);
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
assert_same(false, in_array(QA_LOCK_SQL, $pdo->events, true), 'P10: recusa antes de travar e de ler o historico');
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

// GET: vigente e agendada por padrao; encerrada antes do periodo so com
// include_history=1. Cada data usa a regra que vigorava nela.
$pdo = qa_schedule_db([
    qa_rule(20, 'always_remote', '2026-08-01', '2026-09-30'),
    qa_rule(21, 'odd_days', '2026-10-01', '2026-10-19'),
    qa_rule(22, 'always_onsite', '2026-10-20', null),
    qa_rule(23, 'undefined', '2026-10-25', '2026-10-04'),
]);
$service = new OperationalService($pdo, $today);
// Ordem: o banco simulado nao ordena; compara o conjunto.
$flags = static function (array $view): array {
    $flags = array_map(
        static fn (array $rule): string => $rule['id'] . ':' . ($rule['is_current'] ? 'vigente' : ($rule['is_future'] ? 'agendada' : 'encerrada')),
        array_values(array_filter($view['rules'], static fn (array $rule): bool => !$rule['implicit']))
    );
    sort($flags);
    return $flags;
};
$view = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-23']);
assert_same(['21:vigente', '22:agendada'], $flags($view), 'GET: vigente e agendada, sem encerrada nem linha vazia');
assert_same([$today, null], [$view['today'], $view['rules'][0]['config']], 'GET: data de hoje do servidor e config');
$history = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-23', 'include_history' => '1']);
assert_same(['20:encerrada', '21:vigente', '22:agendada', '23:encerrada'], $flags($history), 'GET: include_history traz o historico inteiro');
$days = [];
foreach ($view['generated'] as $item) {
    if ($item['employee_id'] === 1 && in_array($item['date'], ['2026-10-19', '2026-10-20'], true)) {
        $days[$item['date']] = $item['rule_type'];
    }
}
assert_same(['2026-10-19' => 'odd_days', '2026-10-20' => 'always_onsite'], $days, 'GET: cada data usa a regra que vigorava nela');
$view = $service->scheduleData(['from' => '2026-09-25', 'to' => '2026-10-05']);
assert_same(['20:encerrada', '21:vigente', '22:agendada'], $flags($view), 'GET: regra encerrada dentro do periodo aparece');

// Concorrencia (revisao do F1, reproduzida em MySQL 8.0.46): outra gravacao
// no mesmo colaborador (regra a partir de 10/10) faz commit enquanto esta
// (regra a partir de 20/10) espera a trava. Sem leitura com trava, esta
// leria o historico antigo e gravaria sobreposicao (a conferencia tambem).
$pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', '2026-10-30'), qa_rule(11, 'always_remote', '2026-10-31', null)]);
$pdo->whileWaitingLock = static function (QaSchedulePdo $db): void {
    $db->rules[10]['effective_until'] = '2026-10-09';
    $db->rules[900] = ['id' => 900, 'employee_id' => 1, 'team' => 'n1', 'rule_type' => 'even_days', 'rule_config' => null,
        'effective_from' => '2026-10-10', 'effective_until' => '2026-10-30'];
};
$service = new OperationalService($pdo, $today);
assert_same('ok', qa_outcome(fn () => $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'always_onsite', 'effective_from' => '2026-10-20'], 'qa', 'gestor')), 'concorrencia: gravacao que esperou a trava');
assert_same(null, $pdo->whileWaitingLock, 'concorrencia: a outra gravacao aconteceu durante a espera');
assert_same([], ScheduleRulePlanner::violations(array_values($pdo->rules)), 'concorrencia: nenhuma sobreposicao gravada');
$line = $pdo->timeline(1, '2026-10-09', '2026-10-31');
assert_same(['odd_days ', 'even_days ', 'even_days ', 'always_onsite ', 'always_onsite ', 'always_remote '], [
    $line['2026-10-09'], $line['2026-10-10'], $line['2026-10-19'], $line['2026-10-20'], $line['2026-10-30'], $line['2026-10-31'],
], 'concorrencia: a regra nova fecha a gravada pela outra transacao');
$historyReads = array_values(array_filter($pdo->events, static fn (string $event): bool => strpos($event, 'FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY') !== false));
assert_same([QA_HISTORY_SQL, QA_HISTORY_SQL], $historyReads, 'concorrencia: planejador e conferencia leem o historico com trava');
$serviceSource = (string)file_get_contents(__DIR__ . '/../services/OperationalService.php');
assert_same(1, preg_match('/FROM portal_schedule_rules\s+WHERE employee_id = :employee_id\s+ORDER BY effective_from, id\s+FOR UPDATE\'/', $serviceSource), 'concorrencia: a leitura do historico no servico e com trava');

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

// ----------------------------------------------------------------------------
// 4. Escala implicita da Lideranca (P12) e L9
// ----------------------------------------------------------------------------
$leaderRule = ['employee_id' => 3, 'rule_type' => 'always_onsite'];
$pdo = qa_schedule_db();
$service = new OperationalService($pdo, $today);
assert_same('409: ' . OperationalService::LEADERSHIP_RULE_MESSAGE, qa_outcome(fn () => $service->saveScheduleRule($leaderRule, 'qa', 'admin')), 'P12: admin nao cadastra regra para a Lideranca');
assert_same([[], false], [$pdo->rules, in_array(QA_LOCK_SQL, $pdo->events, true)], 'P12: recusa sem gravar nem travar');
foreach (['lideranca', 'gestor'] as $role) {
    assert_same('403: Somente administradores alteram a escala da Liderança.', qa_outcome(fn () => $service->saveScheduleRule($leaderRule, 'qa', $role)), "L9: {$role} recebe 403 em regra da Lideranca");
    assert_same('403: Somente administradores alteram a escala da Liderança.', qa_outcome(fn () => $service->saveScheduleException(['employee_id' => 3, 'exception_date' => '2026-10-10', 'exception_type' => 'onsite'], 'qa', $role)), "L9: {$role} recebe 403 em excecao da Lideranca");
    assert_same('403: Somente administradores alteram a escala da Liderança.', qa_outcome(fn () => $service->removeScheduleRule(3, 'qa', $role)), "L9: {$role} recebe 403 ao remover regra da Lideranca");
}
assert_same('409: ' . OperationalService::LEADERSHIP_REMOTE_MESSAGE, qa_outcome(fn () => $service->saveScheduleException(['employee_id' => 3, 'exception_date' => '2026-10-06', 'exception_type' => 'remote'], 'qa', 'admin')), 'P12: excecao remota da Lideranca recusada');
assert_same([], $pdo->exceptions, 'P12: nada gravado na recusa da remota');
foreach (['onsite' => '2026-10-10', 'training' => '2026-10-07', 'oncall' => '2026-10-08'] as $type => $date) {
    assert_same('ok', qa_outcome(fn () => $service->saveScheduleException(['employee_id' => 3, 'exception_date' => $date, 'exception_type' => $type], 'qa', 'admin')), "P12: admin grava excecao {$type} da Lideranca");
}
assert_same('lideranca', $pdo->exceptions['3:2026-10-10']['team'], 'P12: excecao da Lideranca grava a equipe normalizada (passo 2.6)');
assert_same('ok', qa_outcome(fn () => $service->saveScheduleException(['employee_id' => 1, 'exception_date' => '2026-10-06', 'exception_type' => 'remote'], 'qa', 'gestor')), 'controle: gestor grava excecao remota de tecnico');

// GET: Lideranca com regra fixa presencial, dias uteis da jornada; sabado so por excecao.
$view = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-11']);
$implicit = array_values(array_filter($view['rules'], static fn (array $rule): bool => $rule['implicit']));
assert_same(1, count($implicit), 'P12: uma regra implicita para o cadastro da Lideranca');
assert_same([3, 'lideranca', OperationalService::LEADERSHIP_RULE_LABEL, true, false, '09:00', '18:00', null], [
    $implicit[0]['employee_id'], $implicit[0]['team'], $implicit[0]['rule_label'], $implicit[0]['is_current'], $implicit[0]['is_future'],
    $implicit[0]['work_start'], $implicit[0]['work_end'], $implicit[0]['id'],
], 'P12: regra implicita com a jornada do cadastro, sem linha no banco');
$leaderDays = [];
foreach ($view['generated'] as $item) {
    if ($item['employee_id'] === 3) {
        $leaderDays[$item['date']] = $item['presence_type'] . '/' . $item['source'];
    }
}
assert_same([
    '2026-10-05' => 'onsite/implicit', '2026-10-06' => 'onsite/implicit', '2026-10-07' => 'onsite/exception',
    '2026-10-08' => 'onsite/exception', '2026-10-09' => 'onsite/implicit', '2026-10-10' => 'onsite/exception',
], $leaderDays, 'P12: presencial seg a sex, sabado so com excecao, domingo fora');
$filtered = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-11', 'team' => 'n1']);
assert_same([], array_values(array_filter($filtered['rules'], static fn (array $rule): bool => $rule['implicit'])), 'P12: filtro N1 nao traz a Lideranca');

foreach (['admin' => true, 'lideranca' => false, 'gestor' => false, 'tecnico' => false, 'somente_leitura' => false] as $role => $expected) {
    assert_same($expected, portal_role_can($role, 'escalas.lideranca'), "L9: permissao escalas.lideranca para {$role}");
}

// Importacao: linha de lider recebe aviso e e ignorada, sem 403 para o gestor.
$pdo = qa_schedule_db();
$service = new OperationalService($pdo, $today);
$importRows = [['login_ad' => 'qa.lider', 'regra' => 'presencial'], ['login_ad' => 'qa.tecnico1', 'regra' => 'par']];
$preview = $service->previewScheduleImport($importRows);
assert_same([1, 0, 1], [$preview['valid_count'], $preview['invalid_count'], $preview['ignored_count']], 'importacao: lider ignorado, tecnico valido');
assert_same([true, true, [OperationalService::LEADERSHIP_IMPORT_WARNING], null], [$preview['rows'][0]['valid'], $preview['rows'][0]['ignored'], $preview['rows'][0]['warnings'], $preview['rows'][0]['rule_type']], 'importacao: aviso na linha do lider');
assert_same(1, $service->confirmScheduleImport($importRows, 'qa', 'gestor'), 'importacao: gestor importa so a linha do tecnico');
assert_same([1], array_values(array_unique(array_column($pdo->rules, 'employee_id'))), 'importacao: nenhuma regra gravada para a Lideranca');
assert_same('400: Nenhuma linha a importar: todas foram ignoradas.', qa_outcome(fn () => $service->confirmScheduleImport([['login_ad' => 'qa.lider', 'regra' => 'presencial']], 'qa', 'admin')), 'importacao: so linhas de lider nao importa nada');
$preview = $service->previewScheduleImport([['login_ad' => 'qa.lider', 'regra' => 'nao existe']]);
assert_same([true, []], [$preview['rows'][0]['ignored'], $preview['rows'][0]['errors']], 'importacao: regra invalida em linha de lider so gera o aviso');

// ----------------------------------------------------------------------------
// 5. Cadastro fora do Mapa PA e da escala (P12)
// ----------------------------------------------------------------------------
$pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', null)]);
$pdo->employees[1]['fora_mapa_escala'] = 1;
$pdo->employees[3]['fora_mapa_escala'] = 1;
$service = new OperationalService($pdo, $today);
$outside = '409: ' . OperationalService::OUTSIDE_MAP_MESSAGE;
assert_same($outside, qa_outcome(fn () => $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'even_days'], 'qa', 'admin')), 'fora do Mapa: regra recusada (409)');
assert_same($outside, qa_outcome(fn () => $service->saveScheduleException(['employee_id' => 1, 'exception_date' => '2026-10-06', 'exception_type' => 'onsite'], 'qa', 'gestor')), 'fora do Mapa: excecao recusada (409)');
assert_same($outside, qa_outcome(fn () => $service->saveScheduleException(['employee_id' => 3, 'exception_date' => '2026-10-10', 'exception_type' => 'onsite'], 'qa', 'admin')), 'fora do Mapa: excecao de lider marcado recusada');
assert_same([[10 => null], []], [array_column($pdo->rules, 'effective_until', 'id'), $pdo->exceptions], 'fora do Mapa: nada gravado nas recusas');
assert_same('403: Somente administradores alteram a escala da Liderança.', qa_outcome(fn () => $service->saveScheduleException(['employee_id' => 3, 'exception_date' => '2026-10-10', 'exception_type' => 'onsite'], 'qa', 'gestor')), 'fora do Mapa: L9 continua antes (403)');
$view = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-09']);
// [F2B] Tela no motor: o cadastro 2, sem regra, aparece "Sem escala" (P4);
// os marcados (1 e o lider 3) continuam fora.
assert_same([[], [2, 2, 2, 2, 2], ['no_schedule']], [
    array_column($view['rules'], 'employee_id'),
    array_column($view['generated'], 'employee_id'),
    array_values(array_unique(array_column($view['generated'], 'presence_type'))),
], 'fora do Mapa: fora da tela de escala, inclusive a Lideranca');
$preview = $service->previewScheduleImport([['login_ad' => 'qa.tecnico1', 'regra' => 'par']]);
assert_same([false, ['Cadastro fora do Mapa PA e da escala.']], [$preview['rows'][0]['valid'], $preview['rows'][0]['errors']], 'fora do Mapa: linha da importacao invalida');
assert_same(1, $service->removeScheduleRule(1, 'qa', 'admin'), 'fora do Mapa: remover regra antiga continua permitido');
$pdo->employees[1]['fora_mapa_escala'] = 0;
assert_same('ok', qa_outcome(fn () => $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'even_days'], 'qa', 'gestor')), 'fora do Mapa: desmarcado volta a receber regra');

// ----------------------------------------------------------------------------
// 6. F2B: ciclo, dias de home, previa, tela Escala no motor, importacao e fila
// ----------------------------------------------------------------------------
$cycle = static fn (array $overrides = []): array => [
    'employee_id' => 1,
    'rule_type' => 'cycle',
    'cycle' => $overrides + ['onsite_days' => 2, 'remote_days' => 1, 'starts_with' => 'remote', 'anchor_date' => '2026-10-05'],
];
$letters = static fn (array $days): string => implode(' ', array_map(
    static fn (array $day): string => substr($day['date'], 8, 2) . ($day['situacao'] === 'presencial' ? 'P' : ($day['situacao'] === 'remoto' ? 'R' : '-')),
    $days
));

// Ciclo: o servidor monta o pattern, forca o inicio na data inicial e grava
// a configuracao em ordem fixa.
$pdo = qa_schedule_db([qa_rule(10, 'even_days', '2026-09-01', null)]);
$service = new OperationalService($pdo, $today);
$saved = $service->saveScheduleRule($cycle() + ['effective_from' => '2026-11-02'], 'qa', 'gestor');
$row = $pdo->rules[$saved['id']];
assert_same(['cycle', '{"pattern":["remote","onsite","onsite"],"anchor_date":"2026-10-05","business_days_only":true}', '2026-10-05', null],
    [$row['rule_type'], $row['rule_config'], $row['effective_from'], $row['effective_until']], 'ciclo: pattern montado, inicio = data inicial (effective_from enviado e ignorado), JSON em ordem fixa');
assert_same([10, '2026-10-04'], [$saved['closed_rule_id'], $saved['closed_until']], 'ciclo: regra anterior encerrada na vespera da data inicial');
$payload = json_decode(end($pdo->sync)[':payload_json'], true);
assert_same([['remote', 'onsite', 'onsite'], '2026-10-05'], [$payload['pattern'] ?? null, $payload['effective_from']], 'ciclo: pattern no payload da portal_sync_queue');
$before = count($pdo->rules);
$again = $service->saveScheduleRule($cycle(['onsite_days' => '2', 'remote_days' => '1']), 'qa', 'gestor');
assert_same([true, $before], [$again['unchanged'], count($pdo->rules)], 'ciclo: salvar de novo a mesma regra nao cria historico');
$view = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-16', 'employee_id' => '1']);
assert_same('05R 06P 07P 08R 09P 12P 13R 14P 15P 16R', $letters($view['generated']), 'ciclo: criterio de aceite (1R/2P a partir de seg 05/10) na tela Escala');
assert_same(['Ciclo 2P/1R'], array_values(array_unique(array_column($view['generated'], 'rule_label'))), 'ciclo: rotulo "Ciclo 2P/1R"');
$current = array_values(array_filter($view['rules'], static fn (array $rule): bool => $rule['is_current']));
assert_same(['Ciclo 2P/1R', ['remote', 'onsite', 'onsite']], [$current[0]['rule_label'], $current[0]['config']['pattern']], 'ciclo: GET devolve rotulo e configuracao');

$pdo = qa_schedule_db();
$service = new OperationalService($pdo, $today);
foreach ([
    ['data inicial no sabado', $cycle(['anchor_date' => '2026-10-10']), '400: A data inicial do ciclo precisa ser dia util (segunda a sexta).'],
    ['data inicial invalida', $cycle(['anchor_date' => '2026-02-30']), '400: Data inicial do ciclo invalida.'],
    ['sem configuracao', ['employee_id' => 1, 'rule_type' => 'cycle'], '400: Informe a configuracao do ciclo.'],
    ['zero dia presencial', $cycle(['onsite_days' => 0]), '400: Dias presenciais do ciclo: informe um numero inteiro de 1 a 19.'],
    ['dia remoto fracionado', $cycle(['remote_days' => '1.5']), '400: Dias remotos do ciclo: informe um numero inteiro de 1 a 19.'],
    ['dia remoto negativo', $cycle(['remote_days' => -1]), '400: Dias remotos do ciclo: informe um numero inteiro de 1 a 19.'],
    ['dia remoto como booleano', $cycle(['remote_days' => true]), '400: Dias remotos do ciclo: informe um numero inteiro de 1 a 19.'],
    ['mais de 20 dias', $cycle(['onsite_days' => 11, 'remote_days' => 10]), '400: O ciclo pode ter no maximo 20 dias.'],
    ['comeca por valor desconhecido', $cycle(['starts_with' => 'home']), '400: Informe se o ciclo comeca por dia presencial ou remoto.'],
    ['data inicial passada pelo gestor', $cycle(['anchor_date' => '2026-09-28']), '403: Somente administradores e Liderança gravam regra com início retroativo.'],
] as [$label, $payload, $expected]) {
    assert_same($expected, qa_outcome(fn () => $service->saveScheduleRule($payload, 'qa', 'gestor')), "ciclo: {$label}");
}
assert_same([], $pdo->rules, 'ciclo: nada gravado nas recusas');
assert_same('ok', qa_outcome(fn () => $service->saveScheduleRule($cycle(['onsite_days' => 10, 'remote_days' => 10, 'starts_with' => 'onsite']), 'qa', 'gestor')), 'ciclo: limite de 20 dias aceito');
assert_same(20, count(json_decode(end($pdo->rules)['rule_config'], true)['pattern']), 'ciclo: 20 posicoes gravadas');
assert_same('ok', qa_outcome(fn () => $service->saveScheduleRule($cycle(['anchor_date' => '2026-09-28', 'onsite_days' => 1]), 'qa', 'lideranca')), 'ciclo: data inicial passada pela Lideranca (escalas.retroativa)');
assert_same(true, strpos(end($pdo->audits)['details'], 'retroativa=sim') !== false, 'ciclo: retroativo auditado');

// Dias fixos informados como home: o servidor converte em presenciais.
$pdo = qa_schedule_db();
$service = new OperationalService($pdo, $today);
$home = ['employee_id' => 1, 'rule_type' => 'fixed_weekdays', 'input_mode' => 'home', 'home_weekdays' => ['thu', 'tue']];
$saved = $service->saveScheduleRule($home, 'qa', 'gestor');
assert_same('{"weekdays":["mon","wed","fri"],"input_mode":"home"}', $pdo->rules[$saved['id']]['rule_config'], 'home: "ter e qui" grava seg, qua e sex');
$payload = json_decode(end($pdo->sync)[':payload_json'], true);
assert_same([['mon', 'wed', 'fri'], false], [$payload['weekdays'], array_key_exists('pattern', $payload)], 'home: fila com os dias presenciais e sem pattern (so o ciclo leva)');
$view = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-09', 'employee_id' => '1']);
assert_same('Presencial: seg, qua e sex · home ter e qui', $view['rules'][0]['rule_label'], 'home: rotulo com presenciais e home');
assert_same('05P 06R 07P 08R 09P', $letters($view['generated']), 'home: dias calculados');
$sameAsOnsite = $service->saveScheduleRule(['employee_id' => 1, 'rule_type' => 'fixed_weekdays', 'weekdays' => ['mon', 'wed', 'fri']], 'qa', 'gestor');
assert_same([true, 1], [$sameAsOnsite['unchanged'], count($pdo->rules)], 'home: os mesmos dias informados como presenciais sao a mesma regra (input_mode so de exibicao)');
foreach ([
    ['todos os dias em home', ['home_weekdays' => ['mon', 'tue', 'wed', 'thu', 'fri']], '400: Todos os dias em home office: use a regra "Sempre remoto".'],
    ['nenhum dia de home', ['home_weekdays' => []], '400: Nenhum dia de home office marcado: para presencial em todos os dias uteis, use a regra "Sempre presencial".'],
    ['dia de home invalido', ['home_weekdays' => ['sat']], '400: Dia da semana invalido.'],
    ['modo desconhecido', ['input_mode' => 'misto'], '400: Modo de informar os dias invalido.'],
] as [$label, $overrides, $expected]) {
    assert_same($expected, qa_outcome(fn () => $service->saveScheduleRule($overrides + $home + ['effective_from' => '2026-10-12'], 'qa', 'gestor')), "home: {$label}");
}
assert_same(1, count($pdo->rules), 'home: nada gravado nas recusas');
// Regra antiga, sem input_mode, continua igual.
$pdo = qa_schedule_db([qa_rule(10, 'fixed_weekdays', '2026-09-01', null, $weekdaysMonWed)]);
$service = new OperationalService($pdo, $today);
$view = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-09', 'employee_id' => '1']);
assert_same(['Presencial: seg e qua', $weekdaysMonWed, '05P 06R 07P 08R 09R'], [$view['rules'][0]['rule_label'], $pdo->rules[10]['rule_config'], $letters($view['generated'])], 'home: fixed_weekdays antigo intacto');

// Previa: mesmo payload, nao grava nada e devolve o mesmo "closes" que o
// "rule" aplica.
$pdo = qa_schedule_db([qa_rule(10, 'even_days', '2026-09-01', null)]);
$service = new OperationalService($pdo, $today);
$state = static fn (QaSchedulePdo $db): array => [$db->rules, $db->exceptions, $db->audits, $db->sync];
$snapshot = $state($pdo);
$preview = $service->previewScheduleRule($cycle(), 'gestor');
assert_same($snapshot, $state($pdo), 'previa: nada gravado (regras, excecoes, auditoria e fila)');
assert_same([], array_values(array_filter($pdo->events, static fn (string $sql): bool => preg_match('/^(INSERT|UPDATE|DELETE)/', $sql) === 1)), 'previa: nenhum comando de escrita');
assert_same('05R 06P 07P 08R 09P 12P 13R 14P 15P 16R', $letters($preview['preview']), 'previa: 10 dias uteis pelo motor (criterio de aceite)');
assert_same(['seg', 'ter', 'onsite'], [$preview['preview'][0]['weekday'], $preview['preview'][1]['weekday'], $preview['preview'][1]['presence_type']], 'previa: dia da semana e presence_type');
assert_same(['Ciclo 2P/1R', '2026-10-05', null, false, false], [$preview['rule_label'], $preview['effective_from'], $preview['effective_until'], $preview['unchanged'], $preview['retroactive']], 'previa: rotulo, vigencia e marcas');
assert_same(['rule_id' => 10, 'rule_type' => 'even_days', 'rule_label' => 'Dias pares', 'effective_until' => '2026-10-04'], $preview['closes'], 'previa: regra que sera encerrada');
$saved = $service->saveScheduleRule($cycle(), 'qa', 'gestor');
assert_same([$preview['closes']['rule_id'], $preview['closes']['effective_until']], [$saved['closed_rule_id'], $saved['closed_until']], 'previa: closes igual ao que o rule aplicou');
$preview = $service->previewScheduleRule($cycle(), 'gestor');
assert_same([true, null], [$preview['unchanged'], $preview['closes']], 'previa: regra igual a vigente');
// Regra agendada depois: a previa mostra os dias dela com o rotulo dela.
$pdo = qa_schedule_db([qa_rule(13, 'always_onsite', '2026-10-12', null)]);
$service = new OperationalService($pdo, $today);
$preview = $service->previewScheduleRule(['employee_id' => 1, 'rule_type' => 'always_remote', 'effective_from' => '2026-10-07'], 'gestor');
assert_same(['07R 08R 09R 12P 13P 14P 15P 16P 19P 20P', null, '2026-10-11'], [$letters($preview['preview']), $preview['closes'], $preview['effective_until']], 'previa: regra no meio termina antes da agendada');
assert_same(['Sempre remoto', 'Sempre presencial'], [$preview['preview'][0]['rule_label'], $preview['preview'][3]['rule_label']], 'previa: rotulo por dia');
$preview = $service->previewScheduleRule(['employee_id' => 1, 'rule_type' => 'always_remote', 'effective_from' => '2026-10-10'], 'gestor');
assert_same('2026-10-12', $preview['preview'][0]['date'], 'previa: inicio no sabado comeca no proximo dia util');
// Mesmas recusas do rule.
$pdo->employees[2]['fora_mapa_escala'] = 1;
assert_same('409: ' . OperationalService::LEADERSHIP_RULE_MESSAGE, qa_outcome(fn () => $service->previewScheduleRule(['employee_id' => 3, 'rule_type' => 'always_onsite'], 'admin')), 'previa: Lideranca 409');
assert_same('409: ' . OperationalService::OUTSIDE_MAP_MESSAGE, qa_outcome(fn () => $service->previewScheduleRule(['employee_id' => 2, 'rule_type' => 'always_onsite'], 'admin')), 'previa: fora do Mapa 409');
assert_same('400: Todos os dias em home office: use a regra "Sempre remoto".', qa_outcome(fn () => $service->previewScheduleRule(['home_weekdays' => ['mon', 'tue', 'wed', 'thu', 'fri']] + $home, 'gestor')), 'previa: mesmas validacoes');
assert_same('403: Somente administradores e Liderança gravam regra com início retroativo.', qa_outcome(fn () => $service->previewScheduleRule(['employee_id' => 1, 'rule_type' => 'always_remote', 'effective_from' => '2026-10-02'], 'gestor')), 'previa: retroativo do gestor 403');

// Propriedade: o historico da previa (applyPlan) e o que a gravacao deixa no banco.
$timeline = static function (array $rules): array {
    $result = [];
    for ($date = '2026-09-01'; $date <= '2026-12-31'; $date = ScheduleRulePlanner::addDays($date, 1)) {
        $rule = ScheduleRulePlanner::ruleAt($rules, $date);
        $result[$date] = $rule === null ? null : $rule['rule_type'] . ' ' . ($rule['rule_config'] ?? '');
    }
    return $result;
};
mt_srand(2026);
$types = ['even_days', 'odd_days', 'always_onsite', 'always_remote', 'cycle'];
for ($round = 0; $round < 200; $round++) {
    $pdo = qa_schedule_db([qa_rule(10, 'odd_days', '2026-09-01', '2026-10-19'), qa_rule(11, 'always_remote', '2026-10-20', null)]);
    $service = new OperationalService($pdo, $today);
    for ($step = 0; $step < 4; $step++) {
        $type = $types[mt_rand(0, 4)];
        $from = ScheduleRulePlanner::addDays('2026-10-05', mt_rand(0, 30));
        if ($type === 'cycle' && !PresenceEngine::isBusinessDay($from)) {
            $from = '2026-10-05';
        }
        $onsiteDays = mt_rand(1, 3);
        $payload = $type === 'cycle'
            ? $cycle(['anchor_date' => $from, 'onsite_days' => $onsiteDays])
            : ['employee_id' => 1, 'rule_type' => $type, 'effective_from' => $from];
        $config = $type === 'cycle'
            ? json_encode(['pattern' => array_merge(['remote'], array_fill(0, $onsiteDays, 'onsite')), 'anchor_date' => $from, 'business_days_only' => true], JSON_UNESCAPED_SLASHES)
            : null;
        $rules = array_values($pdo->rules);
        $plan = ScheduleRulePlanner::planSave($rules, $from, $type, $config, $today);
        $expected = ScheduleRulePlanner::applyPlan($rules, $plan, ['rule_type' => $type, 'rule_config' => $config]);
        $service->saveScheduleRule($payload, 'qa', 'gestor');
        assert_same($timeline($expected), $timeline(array_values($pdo->rules)), "previa: applyPlan igual a gravacao (rodada {$round}, passo {$step})");
    }
}

// Tela Escala no motor (P3, P4, ausencia); Lideranca como antes.
$pdo = qa_schedule_db([
    qa_rule(10, 'even_days', '2026-09-01', null),
    ['employee_id' => 2, 'team' => 'n2'] + qa_rule(20, 'undefined', '2026-09-01', null),
]);
$pdo->employees[4] = ['nome' => 'Tecnico QA 4', 'equipe' => 'n1', 'ad_login' => 'qa.tecnico4', 'access_role' => 'tecnico', 'ativo' => 1, 'fora_mapa_escala' => 0];
$pdo->exceptions['1:2026-10-06'] = ['id' => 500, 'employee_id' => 1, 'employee_name' => 'Tecnico QA 1', 'team' => 'n1', 'exception_date' => '2026-10-06', 'exception_type' => 'day_off', 'note' => 'texto da observacao'];
$pdo->exceptions['1:2026-10-10'] = ['id' => 501, 'employee_id' => 1, 'employee_name' => 'Tecnico QA 1', 'team' => 'n1', 'exception_date' => '2026-10-10', 'exception_type' => 'oncall', 'note' => null];
$pdo->absences = [['employee_id' => 1, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08']];
$service = new OperationalService($pdo, $today);
$view = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-11']);
$byKey = [];
foreach ($view['generated'] as $item) {
    $byKey[$item['employee_id'] . ':' . $item['date']] = $item;
}
assert_same([false, false, false], [isset($byKey['1:2026-10-11']), isset($byKey['2:2026-10-10']), isset($byKey['3:2026-10-10'])], 'motor: fim de semana sem excecao fora da tela (P3)');
assert_same(['onsite', 'exception', 'Exceção: plantão'], [$byKey['1:2026-10-10']['presence_type'], $byKey['1:2026-10-10']['source'], $byKey['1:2026-10-10']['escala']['rule_label']], 'motor: plantao no sabado aparece');
assert_same(['no_schedule', 'Sem escala', 'Sem escala definida', 'sem_escala', 'undefined'], [$byKey['2:2026-10-05']['presence_type'], $byKey['2:2026-10-05']['label'], $byKey['2:2026-10-05']['detalhe'], $byKey['2:2026-10-05']['situacao'], $byKey['2:2026-10-05']['rule_type']], 'motor: undefined vira sem escala, nao remoto (P4)');
assert_same(['no_schedule', 'none', null, 'Sem escala definida'], [$byKey['4:2026-10-05']['presence_type'], $byKey['4:2026-10-05']['source'], $byKey['4:2026-10-05']['rule_type'], $byKey['4:2026-10-05']['rule_label']], 'motor: cadastro sem regra aparece sem escala (P4)');
assert_same(['absence', 'exception', 'Ausente (dia todo)', 'Dias pares'], [$byKey['1:2026-10-06']['presence_type'], $byKey['1:2026-10-06']['source'], $byKey['1:2026-10-06']['detalhe'], $byKey['1:2026-10-06']['rule_label']], 'motor: folga vira ausente (dia todo), sem categoria');
assert_same(false, strpos((string)json_encode($view['generated']), 'texto da observacao') !== false, 'motor: observacao da excecao fora da escala gerada');
assert_same(['absence', 'absence'], [$byKey['1:2026-10-08']['presence_type'], $byKey['1:2026-10-08']['source']], 'motor: ausencia aprovada aparece como ausente (antes sumia)');
assert_same(['onsite', 'implicit', 'always_onsite', OperationalService::LEADERSHIP_RULE_LABEL], [$byKey['3:2026-10-05']['presence_type'], $byKey['3:2026-10-05']['source'], $byKey['3:2026-10-05']['rule_type'], $byKey['3:2026-10-05']['rule_label']], 'motor: Lideranca presencial (regra fixa)');
$monday = array_values(array_filter($view['generated'], static fn (array $item): bool => $item['date'] === '2026-10-05'));
assert_same([3, 1, 2, 4], array_column($monday, 'employee_id'), 'motor: um item por cadastro por dia, por nome');
$teamView = $service->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-05', 'team' => 'n1']);
assert_same([1, 4], array_column($teamView['generated'], 'employee_id'), 'motor: filtro de equipe (sem a Lideranca, como antes)');

// Importacao: ciclo recusado (a planilha nao tem a configuracao).
$preview = $service->previewScheduleImport([['login_ad' => 'qa.tecnico1', 'regra' => 'Ciclo']]);
assert_same([false, [OperationalService::CYCLE_IMPORT_MESSAGE]], [$preview['rows'][0]['valid'], $preview['rows'][0]['errors']], 'importacao: ciclo recusado com orientacao');
$preview = $service->previewScheduleImport([['login_ad' => 'qa.tecnico1', 'regra' => 'qualquer']]);
assert_same(['Regra invalida.'], $preview['rows'][0]['errors'], 'importacao: regra desconhecida com a mensagem de antes');

// Ciclo gravado com configuracao invalida (dado ruim): rotulo explicito e
// "Sem escala" na tela, sem 400.
$pdo = qa_schedule_db([qa_rule(10, 'cycle', '2026-10-05', null, '{"pattern":["onsite"],"anchor_date":"2026-10-05"}')]);
$view = (new OperationalService($pdo, $today))->scheduleData(['from' => '2026-10-05', 'to' => '2026-10-05', 'employee_id' => '1']);
assert_same(['Ciclo com configuracao invalida', 'no_schedule', 'Ciclo com configuracao invalida'], [$view['rules'][0]['rule_label'], $view['generated'][0]['presence_type'], $view['generated'][0]['rule_label']], 'ciclo invalido: rotulo explicito e sem escala');

echo "QA schedule history OK\n";
