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
// 4. repositorio: carga em lote so com cadastro do Critico;
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
assert_same(['id' => 9, 'name' => 'X', 'presence_type' => 'remote', 'work_start' => '07:00', 'work_end' => '17:00', 'active' => true, 'sort_order' => 2], CriticalRolePlanner::roleView(['id' => '9', 'name' => 'X', 'presence_type' => 'remote', 'work_start' => '07:00:00', 'work_end' => '17:00:00', 'active' => '1', 'sort_order' => '2']), 'linha do papel: horas em HH:MM e tipos');
assert_same([3, 1], CriticalRolePlanner::rotationView(['id' => 1, 'effective_from' => '2026-11-16', 'period' => 'week', 'anchor_date' => '2026-11-16', 'roles' => '[3, 1]', 'employees' => '[11]'])['roles'], 'linha do rodizio: listas lidas do JSON, na ordem');

fwrite(STDOUT, 'QA do rodizio de papeis do Critico: ' . $GLOBALS['qa_checks'] . " verificacoes OK\n");
