<?php
declare(strict_types=1);

// ============================================================================
// QA do rodizio de papeis do Critico (desenho principal, 9.16)
//
// Sem banco:
// 1. estrutura da migration 024, do rollback, das linhas do ensaio e da
//    consulta de verificacao;
// 2. regras puras (CriticalRolePlanner): periodo, posicao, papel do dia,
//    vagos, previa e validacoes;
// 3. motor: a jornada do dia pelo papel (shiftFor) e a escala de origem 'papel';
// 4. repositorio: carga em lote so com cadastro do Critico; os papeis vagos
//    da Visao operacional (PresenceService::operationalView);
// 5. CriticalRoleService contra o banco simulado (permissoes, validacoes,
//    409s, auditoria so com IDs);
// 6. endpoint, cadastro (409 da troca de equipe) e dashboard (leitura do codigo).
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote.
//
// Uso: php scripts/qa-critical-roles.php
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
// 1. Migration 024, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261117_024_critical_roles.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261117_024_critical_roles_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261117_024_ensaio_linhas_pc.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261117_024_critical_roles_check.sql');

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 024: fuso da sessao (A4)');
foreach (['portal_critical_roles', 'portal_critical_role_rotations', 'portal_critical_role_overrides'] as $table) {
    assert_same(1, substr_count($migration, "CREATE TABLE {$table} ("), "migration 024: cria {$table}");
    assert_same(1, preg_match("/WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = '{$table}'\\s*\\);\\s*SET @create_\\w+_sql := IF\\(\\s*@has_\\w+_table = 0,/", $migration), "migration 024: {$table} so se nao existir (idempotente)");
}
assert_same(0, preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP|TRUNCATE)\b/i', $migration), 'migration 024: so estrutura, nenhuma linha muda');
assert_same([1, 1, 1], [
    substr_count($migration, "CONSTRAINT ck_critical_role_shift CHECK (work_start < work_end)"),
    substr_count($migration, "CONSTRAINT ck_critical_rotation_anchor CHECK ((period = ''week'' AND WEEKDAY(anchor_date) = 0) OR (period = ''month'' AND DAYOFMONTH(anchor_date) = 1))"),
    substr_count($migration, "CONSTRAINT ck_critical_override_period CHECK (ends_on >= starts_on AND DATEDIFF(ends_on, starts_on) < 31)"),
], 'migration 024: jornada sem cruzar a meia-noite, ancora na segunda ou no dia 1 (AD-PC-1), troca de ate 31 dias');
assert_same(2, substr_count($migration, '{"type": "array", "minItems": 1, "maxItems": 20, "uniqueItems": true, "items": {"type": "integer", "minimum": 1}}'), 'migration 024: papeis e pessoas do rodizio como listas de IDs (1 a 20, sem repeticao)');
assert_same([1, 1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_critical_role_name (name)'),
    substr_count($migration, 'UNIQUE KEY uq_critical_rotation_from (effective_from)'),
    substr_count($migration, 'CONSTRAINT fk_critical_override_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'),
    substr_count($migration, 'CONSTRAINT fk_critical_override_role FOREIGN KEY (role_id) REFERENCES portal_critical_roles (id) ON DELETE RESTRICT'),
], 'migration 024: nome unico, uma versao por data, FKs com RESTRICT');
assert_same(1, substr_count($migration, "reason ENUM(''troca'', ''cobertura'', ''outro'') NOT NULL"), 'migration 024: motivo da troca em lista fechada, sem texto livre');
assert_same(0, preg_match('/\b(note|observacao|nome_funcionario|employee_name)\b/i', $migration), 'migration 024: nenhuma coluna de texto livre nem nome de pessoa');

assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 024: para se ja houver copia *_bkp_024');
assert_same(true, strpos($rollback, 'CALL rollback_024_confere_copias();') < strpos($rollback, 'CREATE TABLE portal_critical_roles_bkp_024'), 'rollback 024: confere as copias antes de copiar');
$dropOverrides = strpos($rollback, "'DROP TABLE portal_critical_role_overrides'");
$dropRotations = strpos($rollback, "'DROP TABLE portal_critical_role_rotations'");
$dropRoles = strpos($rollback, "'DROP TABLE portal_critical_roles'");
assert_same(true, $dropOverrides !== false && $dropOverrides < $dropRotations && $dropRotations < $dropRoles && strpos($rollback, 'CREATE TABLE portal_critical_role_overrides_bkp_024') < $dropOverrides, 'rollback 024: copia as tres tabelas e apaga trocas, rodizio e papeis, nessa ordem (FK)');

assert_same(5, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio: cada comando so no banco ensaio_');
assert_same(true, strpos($lines, "'2026-11-16', 'week', '2026-11-16'") !== false && strpos($lines, "'2026-11-18', '2026-11-18', p.id, 'troca'") !== false, 'linhas do ensaio: rodizio semanal ancorado na segunda e uma troca de um dia, datas explicitas');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 024 so le');
assert_same(0, preg_match('/\b(nome|employee_name|ad_login|login)\b/i', qa_sql_without_comments($check)), 'verificacao 024 nao devolve nome nem login');
assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $check), 'verificacao 024 sem relogio do banco');
assert_same(1, preg_match_all("/IF\\(\\s*@tem_024 = 3,/", $check), "verificacao 024: contagens das tabelas so com elas presentes, senao '024 ausente'");
foreach (['tabelas_024', 'checks_024', 'copias_de_rollback_024', 'papeis', 'papeis_ativos', 'rodizios', 'trocas', 'trocas_sobrepostas', 'trocas_fora_do_critico', 'rodizios_com_pessoa_fora_do_critico', 'rodizios_com_papel_inexistente'] as $column) {
    assert_same(1, preg_match('/\bAS ' . $column . '\b/', $check), "verificacao 024: conta {$column} (nome exato da coluna)");
}
assert_same(2, preg_match_all('/BIT_XOR\(CRC32\(CONCAT_WS\(/', $check), 'verificacao 024: assinaturas de cadastros e regras (a 024 nao muda linha)');

// ----------------------------------------------------------------------------
// 2. Regras puras (CriticalRolePlanner)
// ----------------------------------------------------------------------------
require_once $root . '/services/CriticalRolePlanner.php';

$role = static fn (int $id, string $name, string $type, string $start, string $end, bool $active = true): array => [$id => ['id' => $id, 'name' => $name, 'presence_type' => $type, 'work_start' => $start, 'work_end' => $end, 'active' => $active, 'sort_order' => $id]];
$roles = $role(1, 'Presencial A', 'onsite', '08:00', '17:00') + $role(2, 'Presencial B', 'onsite', '08:00', '17:00') + $role(3, 'Abertura home', 'remote', '07:00', '17:00')
    + $role(4, 'Fechamento home', 'remote', '10:30', '20:30') + $role(5, 'Home normal', 'remote', '08:00', '17:00');
$week = ['id' => 1, 'effective_from' => '2026-11-16', 'period' => 'week', 'anchor_date' => '2026-11-16', 'roles' => [1, 2, 3, 4, 5], 'employees' => [11, 12, 13, 14, 15]];

assert_same([true, false, true, false, false], [CriticalRolePlanner::validAnchor('week', '2026-11-16'), CriticalRolePlanner::validAnchor('week', '2026-11-17'), CriticalRolePlanner::validAnchor('month', '2026-12-01'), CriticalRolePlanner::validAnchor('month', '2026-12-02'), CriticalRolePlanner::validAnchor('dia', '2026-11-16')], 'ancora: segunda no semanal, dia 1 no mensal (AD-PC-1), periodo desconhecido recusado');
assert_same([0, 0, 1, -1, -1, 2], [
    CriticalRolePlanner::periodIndex('week', '2026-11-16', '2026-11-16'),
    CriticalRolePlanner::periodIndex('week', '2026-11-16', '2026-11-22'),
    CriticalRolePlanner::periodIndex('week', '2026-11-16', '2026-11-23'),
    CriticalRolePlanner::periodIndex('week', '2026-11-16', '2026-11-15'),
    CriticalRolePlanner::periodIndex('week', '2026-11-16', '2026-11-09'),
    CriticalRolePlanner::periodIndex('week', '2026-11-16', '2026-11-30'),
], 'periodo semanal: a semana conta da segunda; antes da ancora, negativo');
assert_same([0, 0, 1, 12, -1], [
    CriticalRolePlanner::periodIndex('month', '2026-11-01', '2026-11-30'),
    CriticalRolePlanner::periodIndex('month', '2026-11-01', '2026-11-01'),
    CriticalRolePlanner::periodIndex('month', '2026-11-01', '2026-12-01'),
    CriticalRolePlanner::periodIndex('month', '2026-11-01', '2027-11-15'),
    CriticalRolePlanner::periodIndex('month', '2026-11-01', '2026-10-31'),
], 'periodo mensal: mes do calendario (AD-PC-1), atravessa o ano');
assert_same([1, 2, 5, 3, 4], [
    CriticalRolePlanner::rotationRoleId($week, 11, '2026-11-18'),
    CriticalRolePlanner::rotationRoleId($week, 11, '2026-11-25'),
    CriticalRolePlanner::rotationRoleId($week, 11, '2026-11-11'),
    CriticalRolePlanner::rotationRoleId($week, 12, '2026-11-25'),
    CriticalRolePlanner::rotationRoleId($week, 12, '2026-11-30'),
], 'rodizio: no periodo k, a pessoa i fica com o papel (i + k) mod n; antes da ancora, para tras');
assert_same(null, CriticalRolePlanner::rotationRoleId($week, 99, '2026-11-18'), 'rodizio: quem nao esta na lista fica sem papel');

// Pessoa a mais e papel a mais (AD-PC-2).
$extraPerson = ['roles' => [1, 3], 'employees' => [11, 12, 13]] + $week;
assert_same([[1, 3, null], [3, null, 1], [null, 1, 3]], array_map(static fn (string $d): array => [CriticalRolePlanner::rotationRoleId($extraPerson, 11, $d), CriticalRolePlanner::rotationRoleId($extraPerson, 12, $d), CriticalRolePlanner::rotationRoleId($extraPerson, 13, $d)], ['2026-11-16', '2026-11-23', '2026-11-30']), 'pessoa a mais: cada semana uma fica sem papel (pelo cadastro), em rodizio');
$extraRole = ['roles' => [1, 3, 4], 'employees' => [11, 12]] + $week;
assert_same([[4], [1], [3]], array_map(static fn (string $d): array => CriticalRolePlanner::vacantRoleIds([$extraRole], [], $d, [11, 12]), ['2026-11-16', '2026-11-23', '2026-11-30']), 'papel a mais: um papel vago por semana, em rodizio (alerta)');
assert_same([], CriticalRolePlanner::vacantRoleIds([$extraRole], [], '2026-11-21', [11, 12]), 'papel vago: fim de semana nao tem papel (AD-PC-4)');
assert_same([2], CriticalRolePlanner::vacantRoleIds([$week], [], '2026-11-16', [11, 13, 14, 15]), 'papel vago: pessoa arquivada ou fora do Critico deixa o papel dela vago');
$override = ['id' => 7, 'employee_id' => 11, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-19', 'role_id' => 4, 'reason' => 'troca'];
assert_same([1], CriticalRolePlanner::vacantRoleIds([$week], [$override], '2026-11-18', [11, 12, 13, 14, 15]), 'papel vago: quem troca deixa o papel do rodizio vago se ninguem cobre');
assert_same([], CriticalRolePlanner::vacantRoleIds([$week], [$override, ['employee_id' => 14, 'role_id' => 1] + $override], '2026-11-18', [11, 12, 13, 14, 15]), 'papel vago: troca que cobre o papel o tira dos vagos');

// Papel do dia: dia util, troca vence, vigencia.
$day = static fn (int $id, string $date, array $rotations = [], array $overrides = []): ?array => CriticalRolePlanner::dayRole($id, $date, $rotations, $overrides, $roles);
assert_same(['Presencial A', 'rodizio'], [($r = $day(11, '2026-11-18', [$week]))['name'], $r['source']], 'papel do dia: o do rodizio vigente');
assert_same([null, null], [$day(11, '2026-11-21', [$week]), $day(11, '2026-11-22', [$week])], 'papel do dia: sabado e domingo sem papel (AD-PC-4)');
assert_same(['Fechamento home', 'troca'], [($r = $day(11, '2026-11-18', [$week], [$override]))['name'], $r['source']], 'papel do dia: a troca pontual vence o rodizio');
assert_same(['Fechamento home', 'troca'], [($r = $day(11, '2026-11-18', [], [$override]))['name'], $r['source']], 'papel do dia: troca vale mesmo sem rodizio');
assert_same('Presencial A', $day(11, '2026-11-20', [$week], [$override])['name'], 'papel do dia: depois da troca, o rodizio');
assert_same(null, $day(11, '2026-11-13', [$week]), 'papel do dia: antes da vigencia do rodizio, sem papel (cadastro)');
$next = ['id' => 2, 'effective_from' => '2026-12-01', 'period' => 'month', 'anchor_date' => '2026-12-01', 'roles' => [5], 'employees' => [11]];
assert_same(['Presencial A', 'Home normal'], [$day(11, '2026-11-18', [$next, $week])['name'], $day(11, '2026-12-01', [$week, $next])['name']], 'papel do dia: a versao de maior inicio ate a data vale (o passado nao muda)');
assert_same(null, CriticalRolePlanner::dayRole(11, '2026-11-18', [$week], [], [2 => $roles[2]]), 'papel do dia: papel inexistente no cadastro fica sem papel');

// Previa: 8 semanas de dias uteis, papel por pessoa e vagos.
$preview = CriticalRolePlanner::preview($extraRole, [11, 12]);
assert_same([40, '2026-11-16', '2027-01-08', ['11' => 1, '12' => 3], [4]], [count($preview), $preview[0]['date'], $preview[39]['date'], $preview[0]['roles'], $preview[0]['vacant']], 'previa: 40 dias uteis em 8 semanas, com o papel de cada pessoa e os vagos');

assert_same([true, false, false, false, false], [CriticalRolePlanner::validShift('07:00', '17:00'), CriticalRolePlanner::validShift('17:00', '07:00'), CriticalRolePlanner::validShift('08:00', '08:00'), CriticalRolePlanner::validShift('24:00', '25:00'), CriticalRolePlanner::validShift('7:00', '17:00')], 'jornada do papel: HH:MM, inicio antes do fim, sem cruzar a meia-noite');
assert_same([[3, 1], null, null, null, null, [5]], [CriticalRolePlanner::idList([3, '1']), CriticalRolePlanner::idList([1, 1]), CriticalRolePlanner::idList([]), CriticalRolePlanner::idList(range(1, 21)), CriticalRolePlanner::idList([0]), CriticalRolePlanner::idList(['5'])], 'listas do rodizio: 1 a 20 IDs positivos, sem repetir');
assert_same(['Abertura home 07:00–17:00', 'X 06:30–16:30'], [CriticalRolePlanner::label($roles[3]), CriticalRolePlanner::label(['name' => 'X', 'work_start' => '06:30:00', 'work_end' => '16:30:00'])], 'rotulo do papel: nome e jornada em HH:MM (tambem a hora do banco)');
assert_same(['id' => 9, 'name' => 'X', 'presence_type' => 'remote', 'work_start' => '07:00', 'work_end' => '17:00', 'active' => true, 'sort_order' => 2, 'color_token' => null], CriticalRolePlanner::roleView(['id' => '9', 'name' => 'X', 'presence_type' => 'remote', 'work_start' => '07:00:00', 'work_end' => '17:00:00', 'active' => '1', 'sort_order' => '2']), 'linha do papel: horas em HH:MM e tipos (sem a coluna da 027, sem cor)');
assert_same([3, 1], CriticalRolePlanner::rotationView(['id' => 1, 'effective_from' => '2026-11-16', 'period' => 'week', 'anchor_date' => '2026-11-16', 'roles' => '[3, 1]', 'employees' => '[11]'])['roles'], 'linha do rodizio: listas lidas do JSON, na ordem');

// ----------------------------------------------------------------------------
// 3. Motor: jornada do dia pelo papel e escala de origem 'papel'
// ----------------------------------------------------------------------------
require_once $root . '/services/PresenceRepository.php';
require_once $root . '/services/PresenceEngine.php';
$critical = ['id' => 11, 'name' => 'Pessoa', 'team' => 'critico', 'work_start' => '08:00', 'work_end' => '17:00', 'lunch_start' => '12:00', 'lunch_end' => '13:00', 'lunch_source' => 'pausa', 'outside_map' => false];
assert_same($critical, PresenceRepository::shiftFor($critical, '2026-11-18'), 'jornada do dia: sem papel, o cadastro sem mudanca');
$shift = PresenceRepository::shiftFor($critical, '2026-11-18', $roles[4] + ['source' => 'rodizio']);
assert_same(['10:30', '20:30', 'Fechamento home', 'critico'], [$shift['work_start'], $shift['work_end'], $shift['critical_role']['name'], $shift['team']], 'jornada do dia: com papel, a jornada do papel, o resto do cadastro');
$always = [['rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => null]];
$schedule = PresenceEngine::scheduleForDay($shift, '2026-11-18', $always, null);
assert_same(['remoto', 'papel', 'Papel: Fechamento home 10:30–20:30'], [$schedule['situacao'], $schedule['fonte'], $schedule['rule_label']], 'escala do dia: o papel vence a regra individual (D-PC-3)');
assert_same(['presencial', 'excecao'], [($e = PresenceEngine::scheduleForDay($shift, '2026-11-18', $always, ['exception_type' => 'onsite']))['situacao'], $e['fonte']], 'escala do dia: a excecao por data vence o papel');
assert_same('presencial', PresenceEngine::scheduleForDay($critical, '2026-11-18', $always, null)['situacao'], 'escala do dia: sem papel, a regra individual');
$at = static fn (string $time): array => PresenceEngine::nowPresence($shift, new DateTimeImmutable('2026-11-18 ' . $time . ':00'), $always, null, [], null);
assert_same(['fora_expediente', 'remoto', 'Remoto (papel: Fechamento home 10:30–20:30)', 'fora_expediente'], [$at('09:00')['situacao'], $at('19:00')['situacao'], $at('19:00')['detalhe'], $at('20:30')['situacao']], 'presenca agora: a jornada do papel (10:30 a 20:30) e o papel no detalhe');
assert_same('ausente', PresenceEngine::nowPresence($shift, new DateTimeImmutable('2026-11-18 11:00:00'), $always, null, [['origem' => 'ausencia', 'period_type' => 'full_day']], null)['situacao'], 'presenca agora: a ausencia vence o papel');

// ----------------------------------------------------------------------------
// 4/5. Repositorio e CriticalRoleService contra o banco simulado
// ----------------------------------------------------------------------------
putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-critical-roles-');
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
require_once $root . '/config.php';
require_once $root . '/services/CriticalRoleService.php';
/**
 * Banco simulado do rodizio de papeis: tabelas em memoria, so as consultas do
 * servico e do repositorio, e as restricoes da 024 (nome unico, uma versao por
 * data, um inicio de troca por pessoa). Violacao falha como o MySQL (1062).
 */
final class QaRolesPdo extends PDO {
    public array $employees = [];
    public array $roles = [];
    public array $rotations = [];
    public array $overrides = [];
    public array $audits = [];
    public array $log = [];
    public int $nextId = 100;
    public bool $fail = false;
    private ?array $snapshot = null;

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaRolesStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }

    public function beginTransaction(): bool {
        $this->snapshot = $this->tables();
        return true;
    }

    public function commit(): bool {
        $this->snapshot = null;
        return true;
    }

    public function rollBack(): bool {
        [$this->roles, $this->rotations, $this->overrides, $this->audits, $this->nextId] = $this->snapshot;
        $this->snapshot = null;
        return true;
    }

    public function inTransaction(): bool {
        return $this->snapshot !== null;
    }

    public function lastInsertId(?string $name = null): string|false {
        return (string)($this->nextId - 1);
    }

    public function tables(): array {
        return [$this->roles, $this->rotations, $this->overrides, $this->audits, $this->nextId];
    }

    public function check(): void {
        $seen = [];
        foreach ($this->roles as $row) {
            $seen['name:' . $row['name']] = ($seen['name:' . $row['name']] ?? 0) + 1;
        }
        foreach ($this->rotations as $row) {
            $seen['from:' . $row['effective_from']] = ($seen['from:' . $row['effective_from']] ?? 0) + 1;
        }
        foreach ($this->overrides as $row) {
            $seen['ov:' . $row['employee_id'] . ':' . $row['starts_on']] = ($seen['ov:' . $row['employee_id'] . ':' . $row['starts_on']] ?? 0) + 1;
        }
        if (max($seen ?: [0]) > 1) {
            throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        }
    }
}

final class QaRolesStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaRolesPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $db = $this->pdo;
        $db->log[] = $this->sql;
        if ($db->fail && str_contains($this->sql, 'portal_critical_')) {
            throw new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table doesn't exist");
        }
        $bk = $db->tables();
        try {
            $this->run($this->sql, $params ?? [], $db);
            $db->check();
        } catch (PDOException $e) {
            [$db->roles, $db->rotations, $db->overrides, $db->audits, $db->nextId] = $bk;
            throw $e;
        }
        return true;
    }

    private function run(string $sql, array $p, QaRolesPdo $db): void {
        $this->rows = [];
        $sorted = static function (array $rows, callable $key): array {
            usort($rows, static fn (array $a, array $b): int => $key($a) <=> $key($b));
            return array_values($rows);
        };
        switch (true) {
            case $sql === 'SELECT 1 FROM audit_log LIMIT 1':
                return;
            case $sql === 'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)':
                $db->audits[] = ['action' => $p[2], 'details' => $p[3]];
                return;
            case $sql === 'SELECT id, name, presence_type, work_start, work_end, active, sort_order, color_token FROM portal_critical_roles ORDER BY sort_order, id':
            case $sql === 'SELECT id, name, presence_type, work_start, work_end, active, sort_order, color_token FROM portal_critical_roles ORDER BY sort_order, id FOR UPDATE':
                $this->rows = $sorted($db->roles, static fn (array $r): array => [$r['sort_order'], $r['id']]);
                return;
            case $sql === 'SELECT id, effective_from, period, anchor_date, roles, employees FROM portal_critical_role_rotations ORDER BY effective_from':
            case $sql === 'SELECT id, effective_from, period, anchor_date, roles, employees FROM portal_critical_role_rotations ORDER BY effective_from FOR UPDATE':
            case $sql === 'SELECT id, effective_from, period, anchor_date, roles, employees FROM portal_critical_role_rotations WHERE effective_from <= :date_to ORDER BY effective_from':
                $rows = array_filter($db->rotations, static fn (array $r): bool => !isset($p[':date_to']) || $r['effective_from'] <= $p[':date_to']);
                $this->rows = $sorted($rows, static fn (array $r): string => $r['effective_from']);
                return;
            case $sql === 'SELECT id, employee_id, starts_on, ends_on, role_id, reason FROM portal_critical_role_overrides ORDER BY starts_on, employee_id':
            case $sql === 'SELECT id, employee_id, starts_on, ends_on, role_id, reason FROM portal_critical_role_overrides WHERE ends_on >= :today ORDER BY starts_on, employee_id':
                $rows = array_filter($db->overrides, static fn (array $r): bool => !isset($p[':today']) || $r['ends_on'] >= $p[':today']);
                $this->rows = $sorted($rows, static fn (array $r): array => [$r['starts_on'], $r['employee_id']]);
                return;
            case $sql === 'SELECT id, employee_id, starts_on, ends_on, role_id, reason FROM portal_critical_role_overrides WHERE starts_on <= :date_to AND ends_on >= :date_from ORDER BY employee_id, starts_on':
                $rows = array_filter($db->overrides, static fn (array $r): bool => $r['starts_on'] <= $p[':date_to'] && $r['ends_on'] >= $p[':date_from']);
                $this->rows = $sorted($rows, static fn (array $r): array => [$r['employee_id'], $r['starts_on']]);
                return;
            case $sql === 'SELECT id, equipe FROM funcionarios WHERE ativo = 1':
                foreach ($db->employees as $id => $e) {
                    if ($e['ativo'] === 1) {
                        $this->rows[] = ['id' => $id, 'equipe' => $e['equipe']];
                    }
                }
                return;
            case $sql === 'SELECT id, equipe, ativo FROM funcionarios WHERE id = :id FOR UPDATE':
                $e = $db->employees[(int)$p[':id']] ?? null;
                $this->rows = $e === null ? [] : [['id' => (int)$p[':id'], 'equipe' => $e['equipe'], 'ativo' => $e['ativo']]];
                return;
            case $sql === 'SELECT id FROM portal_critical_roles WHERE name = :name':
                foreach ($db->roles as $r) {
                    if (strcasecmp($r['name'], $p[':name']) === 0) {
                        $this->rows[] = ['id' => $r['id']];
                    }
                }
                return;
            case $sql === 'SELECT id, active FROM portal_critical_roles WHERE id = :id FOR UPDATE':
                $r = $db->roles[(int)$p[':id']] ?? null;
                $this->rows = $r === null ? [] : [['id' => $r['id'], 'active' => $r['active']]];
                return;
            case $sql === 'SELECT COUNT(*) FROM portal_critical_roles WHERE active = 1 AND id <> :id':
                $this->rows = [['n' => count(array_filter($db->roles, static fn (array $r): bool => $r['active'] === 1 && $r['id'] !== (int)$p[':id']))]];
                return;
            case str_starts_with($sql, 'INSERT INTO portal_critical_roles '):
                $db->roles[$db->nextId] = ['id' => $db->nextId, 'name' => $p[':name'], 'presence_type' => $p[':presence_type'], 'work_start' => $p[':work_start'], 'work_end' => $p[':work_end'], 'active' => $p[':active'], 'sort_order' => $p[':sort_order'], 'color_token' => $p[':color_token'] ?? null];
                $db->nextId++;
                return;
            case str_starts_with($sql, 'UPDATE portal_critical_roles SET '):
                $db->roles[(int)$p[':id']] = ['name' => $p[':name'], 'presence_type' => $p[':presence_type'], 'work_start' => $p[':work_start'], 'work_end' => $p[':work_end'], 'active' => $p[':active'], 'sort_order' => $p[':sort_order']] + (array_key_exists(':color_token', $p) ? ['color_token' => $p[':color_token']] : []) + $db->roles[(int)$p[':id']];
                return;
            case $sql === 'SELECT id FROM portal_critical_role_rotations WHERE effective_from = :effective_from FOR UPDATE':
                foreach ($db->rotations as $r) {
                    if ($r['effective_from'] === $p[':effective_from']) {
                        $this->rows[] = ['id' => $r['id']];
                    }
                }
                return;
            case str_starts_with($sql, 'INSERT INTO portal_critical_role_rotations '):
                $db->rotations[$db->nextId] = ['id' => $db->nextId, 'effective_from' => $p[':effective_from'], 'period' => $p[':period'], 'anchor_date' => $p[':anchor_date'], 'roles' => $p[':roles'], 'employees' => $p[':employees']];
                $db->nextId++;
                return;
            case str_starts_with($sql, 'UPDATE portal_critical_role_rotations SET '):
                $db->rotations[(int)$p[':id']] = ['period' => $p[':period'], 'anchor_date' => $p[':anchor_date'], 'roles' => $p[':roles'], 'employees' => $p[':employees']] + $db->rotations[(int)$p[':id']];
                return;
            case $sql === 'SELECT COUNT(*) FROM portal_critical_role_overrides WHERE employee_id = :employee_id AND starts_on <= :ends_on AND ends_on >= :starts_on FOR UPDATE':
                $this->rows = [['n' => count(array_filter($db->overrides, static fn (array $r): bool => $r['employee_id'] === $p[':employee_id'] && $r['starts_on'] <= $p[':ends_on'] && $r['ends_on'] >= $p[':starts_on']))]];
                return;
            case str_starts_with($sql, 'INSERT INTO portal_critical_role_overrides '):
                $db->overrides[$db->nextId] = ['id' => $db->nextId, 'employee_id' => $p[':employee_id'], 'starts_on' => $p[':starts_on'], 'ends_on' => $p[':ends_on'], 'role_id' => $p[':role_id'], 'reason' => $p[':reason']];
                $db->nextId++;
                return;
            case $sql === 'SELECT id, employee_id, starts_on, ends_on FROM portal_critical_role_overrides WHERE id = :id FOR UPDATE':
                $r = $db->overrides[(int)$p[':id']] ?? null;
                $this->rows = $r === null ? [] : [$r];
                return;
            case $sql === 'DELETE FROM portal_critical_role_overrides WHERE id = :id':
                unset($db->overrides[(int)$p[':id']]);
                return;
            // Visao operacional (PresenceService): cadastros ativos com a jornada
            // 08:00-17:00 e sem regra, excecao nem ausencia.
            case str_starts_with($sql, 'SELECT id, nome, equipe, jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, fora_mapa_escala FROM funcionarios WHERE ativo = 1'):
                foreach ($db->employees as $id => $e) {
                    if ($e['ativo'] === 1) {
                        $this->rows[] = ['id' => $id, 'nome' => 'Pessoa ' . $id, 'equipe' => $e['equipe'], 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'almoco_inicio' => null, 'almoco_fim' => null, 'fora_mapa_escala' => 0];
                    }
                }
                return;
            case str_starts_with($sql, 'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules '):
            case str_starts_with($sql, 'SELECT employee_id, exception_date, exception_type FROM portal_schedule_exceptions '):
            case str_starts_with($sql, 'SELECT id, employee_id, starts_on, ends_on, period_type, start_time, end_time, category FROM portal_absences '):
                return;
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

    public function rowCount(): int {
        return 1;
    }
}

/** Critico 11 a 15 (o 15 arquivado), N1 21, Critico com acento 16; papeis 1 a 5 (o 5 desativado). */
function qa_roles_db(): QaRolesPdo {
    $pdo = new QaRolesPdo();
    $pdo->employees = [11 => ['equipe' => 'critico', 'ativo' => 1], 12 => ['equipe' => 'critico', 'ativo' => 1], 13 => ['equipe' => 'critico', 'ativo' => 1], 14 => ['equipe' => 'critico', 'ativo' => 1], 15 => ['equipe' => 'critico', 'ativo' => 0], 16 => ['equipe' => 'Crítico', 'ativo' => 1], 21 => ['equipe' => 'n1', 'ativo' => 1]];
    foreach ([[1, 'Presencial A', 'onsite', '08:00:00', '17:00:00', 1], [2, 'Presencial B', 'onsite', '08:00:00', '17:00:00', 1], [3, 'Abertura home', 'remote', '07:00:00', '17:00:00', 1], [4, 'Fechamento home', 'remote', '10:30:00', '20:30:00', 1], [5, 'Antigo', 'remote', '09:00:00', '18:00:00', 0]] as [$id, $name, $type, $start, $end, $active]) {
        $pdo->roles[$id] = ['id' => $id, 'name' => $name, 'presence_type' => $type, 'work_start' => $start, 'work_end' => $end, 'active' => $active, 'sort_order' => $id];
    }
    $GLOBALS['qa_pdo'] = $pdo;
    return $pdo;
}
$qaOutcome = static function (callable $call): string {
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
};
$today = '2026-11-17';

// Repositorio: sem Critico, nenhuma consulta; com Critico, tres; falha, sem papel.
$pdo = qa_roles_db();
$repository = new PresenceRepository($pdo);
assert_same([null, []], [$repository->criticalRoleContext([['id' => 21, 'team' => 'n1']], $today, $today), $pdo->log], 'repositorio: sem cadastro do Critico, nenhuma consulta a mais (D-PC-4)');
$pdo->rotations[50] = ['id' => 50, 'effective_from' => '2026-11-16', 'period' => 'week', 'anchor_date' => '2026-11-16', 'roles' => '[1, 3]', 'employees' => '[11, 12, 13]'];
$pdo->overrides[60] = ['id' => 60, 'employee_id' => 12, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'role_id' => 4, 'reason' => 'cobertura'];
$context = $repository->criticalRoleContext([['id' => 11, 'team' => 'critico'], ['id' => 12, 'team' => 'critico'], ['id' => 13, 'team' => 'critico'], ['id' => 21, 'team' => 'n1']], $today, '2026-11-21');
assert_same([3, [11, 12, 13]], [count($pdo->log), $context['employee_ids']], 'repositorio: papeis, rodizios e trocas em tres consultas, so os IDs do Critico');
$days = PresenceRepository::criticalRoleDays($context, $today, '2026-11-21');
assert_same(['Presencial A', 'Fechamento home', 'Abertura home', 'troca', false, false], [$days['11:2026-11-17']['name'], $days['12:2026-11-17']['name'], $days['12:2026-11-18']['name'], $days['12:2026-11-17']['source'], isset($days['13:2026-11-17']), isset($days['11:2026-11-21'])], 'repositorio: papel do dia por pessoa e data; pessoa a mais e sabado sem papel');
$pdo->fail = true;
$pdo->log = [];
assert_same([null, []], [$repository->criticalRoleContext([['id' => 11, 'team' => 'critico']], $today, $today), PresenceRepository::criticalRoleDays(null, $today, $today)], 'repositorio: tabela ausente (antes da 024) ou falha: sem papel, jornada do cadastro');
$pdo->fail = false;

// Visao operacional: os papeis vagos de hoje na mesma carga dos contadores
// (AD-PC-2): pessoa arquivada na posicao e papel sem pessoa ficam vagos, menos
// o coberto por troca pontual; sabado, nenhum (AD-PC-4).
require_once $root . '/services/PresenceService.php';
$pdo = qa_roles_db();
$pdo->rotations[51] = ['id' => 51, 'effective_from' => '2026-11-16', 'period' => 'week', 'anchor_date' => '2026-11-16', 'roles' => '[1, 2, 3, 4]', 'employees' => '[11, 15]'];
$pdo->overrides[61] = ['id' => 61, 'employee_id' => 12, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'role_id' => 3, 'reason' => 'cobertura'];
$vacantAt = static fn (string $at): array => array_column((new PresenceService($pdo))->operationalView(new DateTimeImmutable($at), [])['papeis_vagos'], 'label', 'id');
assert_same([2 => 'Presencial B 08:00–17:00', 4 => 'Fechamento home 10:30–20:30'], $vacantAt('2026-11-17 10:00:00'), 'visao operacional: papel vago por pessoa arquivada ou sem pessoa, menos o coberto por troca (AD-PC-2)');
assert_same([], $vacantAt('2026-11-21 10:00:00'), 'visao operacional: sabado sem papel vago (AD-PC-4)');

// Permissao: gestor, tecnico e somente leitura, 403 em toda gravacao e na previa.
$pdo = qa_roles_db();
$service = new CriticalRoleService($pdo, $today);
foreach (['gestor', 'tecnico', 'somente_leitura'] as $profile) {
    foreach ([
        'saveRole' => fn () => $service->saveRole(['name' => 'X'], 'qa', $profile),
        'previewRotation' => fn () => $service->previewRotation([], $profile),
        'saveRotation' => fn () => $service->saveRotation([], 'qa', $profile),
        'addOverride' => fn () => $service->addOverride([], 'qa', $profile),
        'removeOverride' => fn () => $service->removeOverride(['override_id' => 1], $profile),
    ] as $method => $call) {
        assert_same('403: ' . CriticalRoleService::FORBIDDEN_MESSAGE, $qaOutcome($call), "servico: {$method} pelo {$profile} 403 (AD-PC-3)");
    }
}
assert_same([[], 5], [$pdo->audits, count($pdo->roles)], 'servico: recusado, nada gravado');

// Papeis.
$save = static fn (array $data, string $profile = 'lideranca'): string => $qaOutcome(fn () => $service->saveRole($data + ['presence_type' => 'remote', 'work_start' => '07:00', 'work_end' => '17:00'], 'qa.lider', $profile));
assert_same('ok', $save(['name' => '  Home   tarde ']), 'papel: Lideranca cria');
assert_same(['Home tarde', '07:00:00', 1], [($new = end($pdo->roles))['name'], $new['work_start'], $new['active']], 'papel: nome sem espacos sobrando, jornada gravada como TIME, ativo');
assert_same('409: Já existe um papel com esse nome.', $save(['name' => 'abertura home']), 'papel: nome repetido 409');
assert_same('400: Jornada do papel: início antes do fim, no mesmo dia (HH:MM).', $save(['name' => 'Noite', 'work_start' => '22:00', 'work_end' => '06:00']), 'papel: jornada que cruza a meia-noite 400');
assert_same('400: Tipo de presença do papel: presencial ou remoto.', $save(['name' => 'Y', 'presence_type' => 'hibrido']), 'papel: tipo fora da lista 400');
assert_same('400: Nome do papel: de 1 a 60 caracteres.', $save(['name' => str_repeat('a', 61)]), 'papel: nome longo 400');
assert_same('400: Papel novo começa ativo.', $save(['name' => 'Z', 'active' => false]), 'papel: novo desativado 400');
assert_same('ok', $save(['role_id' => 3, 'name' => 'Abertura home', 'work_start' => '06:30', 'work_end' => '16:30'], 'admin'), 'papel: admin edita a jornada');
assert_same('06:30:00', $pdo->roles[3]['work_start'], 'papel: jornada nova gravada');
foreach ([1, 2, 3, 4, $new['id']] as $id) {
    $pdo->roles[$id]['active'] = $id === 4 ? 1 : 0;
}
assert_same('409: Precisa ficar pelo menos 1 papel ativo.', $save(['role_id' => 4, 'name' => 'Fechamento home', 'active' => false]), 'papel: o ultimo ativo nao desativa');
foreach ([1, 2, 3] as $id) {
    $pdo->roles[$id]['active'] = 1;
}
assert_same('400: Papel inválido.', $save(['role_id' => 'x', 'name' => 'W']), 'papel: id invalido 400');
// [D-ESC-13] Cor do papel: paleta fechada; ausente na edicao mantem a gravada; vazia limpa.
assert_same('ok', $save(['name' => 'Cor nova', 'color_token' => 'turquesa']), 'cor: papel novo com cor da paleta');
$colored = end($pdo->roles);
assert_same('turquesa', $colored['color_token'], 'cor: gravada');
assert_same('400: Cor do papel: escolha uma da paleta.', $save(['name' => 'Cor hex', 'color_token' => '#ff0000']), 'cor: hex fora da paleta 400');
assert_same('400: Cor do papel: escolha uma da paleta.', $save(['name' => 'Cor roxa', 'color_token' => 'roxo']), 'cor: nome fora da paleta 400');
assert_same('ok', $save(['role_id' => $colored['id'], 'name' => 'Cor nova']), 'cor: editar sem a cor');
assert_same('turquesa', $pdo->roles[$colored['id']]['color_token'], 'cor: ausente na edicao mantem a gravada (9.12.1)');
assert_same('ok', $save(['role_id' => $colored['id'], 'name' => 'Cor nova', 'color_token' => '']), 'cor: vazia limpa');
assert_same(null, $pdo->roles[$colored['id']]['color_token'], 'cor: limpa (a tela mostra areia)');
assert_same(['azul', null, false, false, 'areia'], [CriticalRolePlanner::colorToken('azul'), CriticalRolePlanner::colorToken(null), CriticalRolePlanner::colorToken('AZUL'), CriticalRolePlanner::colorToken(7), CriticalRolePlanner::colorToken('areia')], 'cor: so as 8 da paleta, em minusculas');
assert_same('lilas', CriticalRolePlanner::roleView(['id' => 1, 'name' => 'H', 'presence_type' => 'remote', 'work_start' => '07:00:00', 'work_end' => '17:00:00', 'active' => 1, 'sort_order' => 1, 'color_token' => 'lilas'])['color_token'], 'cor: lida na linha do papel');
assert_same(null, CriticalRolePlanner::roleView(['id' => 1, 'name' => 'H', 'presence_type' => 'remote', 'work_start' => '07:00:00', 'work_end' => '17:00:00', 'active' => 1, 'sort_order' => 1, 'color_token' => '#123456'])['color_token'], 'cor: valor fora da paleta no banco vira sem cor');
$pdo->roles[$colored['id']]['active'] = 0;

// Rodizio: validacoes, previa e gravacao.
$rotation = ['effective_from' => '2026-11-23', 'period' => 'week', 'anchor_date' => '2026-11-23', 'roles' => [1, 3, 4], 'employees' => [11, 12, 13]];
$try = static fn (array $patch, string $profile = 'lideranca', string $method = 'saveRotation'): string => $qaOutcome(fn () => $method === 'saveRotation' ? $service->saveRotation($patch + $rotation, 'qa.lider', $profile) : $service->previewRotation($patch + $rotation, $profile));
assert_same('400: Âncora do rodízio semanal: uma segunda-feira.', $try(['anchor_date' => '2026-11-24']), 'rodizio: ancora semanal fora da segunda 400');
assert_same('400: Âncora do rodízio mensal: o dia 1 do mês.', $try(['period' => 'month', 'anchor_date' => '2026-11-23']), 'rodizio: ancora mensal fora do dia 1 400');
assert_same('400: Período do rodízio: semana ou mês.', $try(['period' => 'dia']), 'rodizio: periodo fora da lista 400');
assert_same('400: Pessoas do rodízio: de 1 a 20, sem repetir.', $try(['employees' => [11, 11]]), 'rodizio: pessoa repetida 400');
assert_same('400: Vagas do rodízio: de 1 a 20 (o mesmo papel pode repetir).', $try(['roles' => []]), 'rodizio: sem papel 400 (D-ESC-15: vagas)');
assert_same('409: 1 papel(is) do rodízio inexistente(s) ou desativado(s): revise a lista.', $try(['roles' => [1, 5]]), 'rodizio: papel desativado 409');
assert_same('409: 2 pessoa(s) do rodízio arquivada(s) ou fora do Crítico: revise a lista.', $try(['employees' => [11, 15, 21]]), 'rodizio: arquivado e N1 409 com a contagem (9.16.4)');
$preview = $service->previewRotation(['employees' => [11, 12, 16]] + $rotation, 'lideranca');
assert_same([false, false, 0, 0, 40, ['11' => 1, '12' => 3, '16' => 4]], [$preview['replaces'], $preview['retroactive'], $preview['extra_people'], $preview['vacant_roles'], count($preview['preview']), $preview['preview'][0]['roles']], 'rodizio: previa de 8 semanas; Critico com acento entra');
assert_same([[], []], [$pdo->rotations, array_filter($pdo->audits, static fn (array $a): bool => str_starts_with($a['action'], 'RODIZIO'))], 'rodizio: a previa nao grava');
assert_same([1, 2], [$service->previewRotation(['employees' => [11, 12, 13, 14]] + $rotation, 'lideranca')['extra_people'], $service->previewRotation(['employees' => [11]] + $rotation, 'lideranca')['vacant_roles']], 'rodizio: a previa conta pessoa a mais e papel vago (AD-PC-2)');
// [D-ESC-15] Vagas: o mesmo papel em mais de uma vaga (Presencial x2); pessoas continuam sem repetir.
assert_same([[1, 1, 3], null, [2]], [CriticalRolePlanner::slotList([1, '1', 3]), CriticalRolePlanner::slotList([1, 0]), CriticalRolePlanner::slotList(['2'])], 'vagas: o papel pode repetir; IDs positivos');
assert_same([null, null], [CriticalRolePlanner::slotList(array_fill(0, 21, 1)), CriticalRolePlanner::idList([1, 1])], 'vagas: ate 20; as pessoas (idList) continuam sem repetir');
$slots = $service->previewRotation(['roles' => [1, 1, 3, 4], 'employees' => [11, 12, 13, 16]] + $rotation, 'lideranca');
assert_same([0, 0, ['11' => 1, '12' => 1, '13' => 3, '16' => 4]], [$slots['extra_people'], $slots['vacant_roles'], $slots['preview'][0]['roles']], 'vagas: previa com Presencial x2, sem pessoa a mais nem vaga sem pessoa');
assert_same('409: 1 papel(is) do rodízio inexistente(s) ou desativado(s): revise a lista.', $try(['roles' => [5, 5, 1]]), 'vagas: papel desativado conta uma vez, mesmo repetido');
assert_same('ok', $try([]), 'rodizio: Lideranca salva');
$saved = end($pdo->rotations);
assert_same(['2026-11-23', 'week', '[1,3,4]', '[11,12,13]'], [$saved['effective_from'], $saved['period'], $saved['roles'], $saved['employees']], 'rodizio: versao gravada com as listas na ordem');
assert_same('ok', $try(['employees' => [13, 12, 11]]), 'rodizio: salvar na mesma data corrige a versao');
assert_same([1, '[13,12,11]'], [count($pdo->rotations), end($pdo->rotations)['employees']], 'rodizio: uma versao por data (correcao no lugar)');
assert_same('ok', $try(['effective_from' => '2026-11-16', 'anchor_date' => '2026-11-16'], 'admin'), 'rodizio: admin grava retroativo');
$audit = end($pdo->audits);
assert_same(['RODIZIO_PAPEIS_SALVO', true], [$audit['action'], str_contains($audit['details'], 'retroativa=sim') && str_contains($audit['details'], 'pessoas=11,12,13')], 'rodizio: auditoria com IDs e a marca de retroativa');

// Trocas pontuais.
$add = static fn (array $patch, string $profile = 'lideranca'): string => $qaOutcome(fn () => $service->addOverride($patch + ['employee_id' => 11, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-19', 'role_id' => 4, 'reason' => 'troca'], 'qa.lider', $profile));
assert_same('ok', $add([]), 'troca: Lideranca registra');
assert_same('409: Já existe troca para este técnico nesse período: remova-a antes.', $add(['starts_on' => '2026-11-19', 'ends_on' => '2026-11-20']), 'troca: sobreposta para a mesma pessoa 409');
assert_same('ok', $add(['employee_id' => 12]), 'troca: outra pessoa no mesmo periodo pode');
assert_same('409: Só cadastros ativos do Crítico têm papel.', $add(['employee_id' => 21, 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25']), 'troca: N1 409');
assert_same('409: Só cadastros ativos do Crítico têm papel.', $add(['employee_id' => 15, 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25']), 'troca: arquivado 409');
assert_same('409: Papel desativado: escolha um papel ativo.', $add(['role_id' => 5, 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25']), 'troca: papel desativado 409');
assert_same('400: Troca pontual: até 31 dias. Para mais, salve uma versão do rodízio.', $add(['starts_on' => '2026-12-01', 'ends_on' => '2027-01-01']), 'troca: mais de 31 dias 400');
assert_same('ok', $add(['employee_id' => 13, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']), 'troca: 31 dias pode');
assert_same('400: Motivo da troca inválido.', $add(['reason' => 'texto livre', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25']), 'troca: motivo fora da lista fechada 400');
assert_same('400: O fim da troca deve ser igual ou posterior ao início.', $add(['starts_on' => '2026-11-26', 'ends_on' => '2026-11-25']), 'troca: fim antes do inicio 400');
$overrideId = array_key_first($pdo->overrides);
$pdo->overrides[$overrideId]['starts_on'] = '2026-11-16';
assert_same('ok', $qaOutcome(fn () => $service->removeOverride(['override_id' => $overrideId], 'lideranca')), 'troca: Lideranca remove a que ja comecou (retroativa)');
assert_same('400: Troca não encontrada.', $qaOutcome(fn () => $service->removeOverride(['override_id' => 999], 'lideranca')), 'troca: inexistente 400');
$names = ['Pessoa', 'Tecnico', 'qa.lider'];
$withName = array_filter($pdo->audits, static function (array $audit) use ($names): bool {
    foreach ($names as $name) {
        if (str_contains($audit['details'], $name)) {
            return true;
        }
    }
    return false;
});
assert_same([], $withName, 'auditoria so com IDs: nenhum nome nem login nos detalhes (D-PC-5)');

// Visao geral: papel de hoje, vagos e quem saiu do Critico no rodizio vigente.
$pdo->employees[12]['ativo'] = 0;
$overview = $service->overview();
$current = array_values(array_filter($overview['rotations'], static fn (array $r): bool => $r['is_current']))[0];
assert_same(['2026-11-16', 1], [$current['effective_from'], $current['invalid_people']], 'visao geral: o rodizio vigente conta quem foi arquivado (a tela pede versao nova)');
assert_same([['role_id' => 1, 'source' => 'rodizio'], null], [$overview['today_roles']['11'], $overview['today_roles']['14'] ?? null], 'visao geral: papel de hoje por pessoa ativa do Critico; fora do rodizio, sem papel');
assert_same([3], $overview['vacant_today'], 'visao geral: o papel do arquivado fica vago hoje');
assert_same(['troca', 'cobertura', 'outro'], $overview['reasons'], 'visao geral: os motivos da lista fechada');

// Troca de equipe de quem esta no rodizio vigente ou futuro (9.16.4).
assert_same([CriticalRoleService::TEAM_CHANGE_MESSAGE, null, null, null], [
    $service->teamChangeDenied(11, 'critico', 'n1'),
    $service->teamChangeDenied(14, 'critico', 'n1'),
    $service->teamChangeDenied(11, 'critico', 'critico'),
    $service->teamChangeDenied(21, 'n1', 'n2'),
], 'troca de equipe: 409 so para quem esta no rodizio vigente ou futuro e sai do Critico');

// ----------------------------------------------------------------------------
// 6. Endpoint, cadastro e dashboard (leitura do codigo)
// ----------------------------------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/portal/critical_roles.php');
$permissionAt = strpos($endpoint, "if (!portal_role_can(\$role, 'escalas.manage')) {");
assert_same(true, $permissionAt !== false && $permissionAt < strpos($endpoint, '$data = portal_json_input();') && strpos($endpoint, "json_response(['sucesso' => true] + \$service->overview(\$withHistory));") < $permissionAt, 'endpoint: GET para quem le escalas; gravacao so com escalas.manage, antes de ler o corpo');
assert_same(true, strpos($endpoint, "audit_log('PAPEL_CRITICO_NEGADO'") !== false && strpos($endpoint, '], 403);') !== false, 'endpoint: recusa auditada com 403');
assert_same(1, substr_count($endpoint, 'portal_json_input()'), 'endpoint: corpo so pelo portal_json_input (CSRF e Content-Type)');
assert_same(['save_role', 'preview_rotation', 'save_rotation', 'add_override', 'remove_override'], preg_match_all("/case '([a-z_]+)':/", $endpoint, $cases) ? $cases[1] : [], 'endpoint: as cinco acoes');
assert_same(true, strpos($endpoint, 'portal_operational_error($e);') !== false, 'endpoint: erros pelo portal_operational_error');

$update = (string)file_get_contents($root . '/api/atualizar_funcionario.php');
$denyAt = strpos($update, '$papelDenied = (new CriticalRoleService($pdo))->teamChangeDenied($funcionario_id, $equipeAtual, (string)$equipe);');
assert_same(true, $denyAt !== false && strpos($update, '$pdo->beginTransaction();') < $denyAt && $denyAt < strpos($update, '"UPDATE funcionarios') && strpos($update, "json_response(['sucesso' => false, 'mensagem' => \$papelDenied], 409);") > $denyAt, 'cadastro: 409 da troca de equipe com o cadastro travado, antes do UPDATE (9.16.4)');
assert_same(1, substr_count($update, 'if ($equipeAtual === PortalTeams::CRITICAL && $equipe !== PortalTeams::CRITICAL) {'), 'cadastro: so consulta o rodizio quando alguem sai do Critico');

$dashboard = (string)file_get_contents($root . '/api/portal/dashboard.php');
assert_same([1, 1], [substr_count($dashboard, '->operationalView($now, $pausas);'), substr_count($dashboard, "'papeis_vagos_critico' => \$papeisVagos,")], 'dashboard: papeis vagos do Critico na mesma carga dos contadores (AD-PC-2)');
$presenceSource = (string)file_get_contents($root . '/services/PresenceService.php');
assert_same(3, substr_count($presenceSource, 'PresenceRepository::shiftFor($employee, '), 'presenca: periodo, status e Visao operacional pedem a jornada do dia com o papel');
assert_same(3, substr_count($presenceSource, "\$roleDays[\$id . ':' . "), 'presenca: o papel do dia por pessoa e data nas tres leituras');
$manager = (string)file_get_contents($root . '/classes/GerenciadorPausas.php');
assert_same(1, substr_count($manager, "\$jornada = PresenceRepository::shiftFor(['id' => \$id, 'work_start' => \$f->jornada_entrada, 'work_end' => \$f->jornada_saida], \$dia, \$papeis[\$id . ':' . \$dia] ?? null);"), 'pausas: a regra de pelo menos 1 disponivel usa a jornada do papel (9.16.2)');

fwrite(STDOUT, 'QA do rodizio de papeis do Critico: ' . $GLOBALS['qa_checks'] . " verificacoes OK\n");
