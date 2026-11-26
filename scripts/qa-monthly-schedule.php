<?php
declare(strict_types=1);

// ============================================================================
// QA da escala do mes no portal (F10-4 a F10-6 e a importacao da planilha
// Base; docs/DESENHO_F10_ESCALA_MENSAL.md, 3.5 a 3.7 e 8.9)
//
// Sem banco:
// 1. estrutura da migration 023, do rollback (com a parada), das linhas do
//    ensaio e da consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote. Nenhum nome real: so valores ficticios.
//
// Uso: php scripts/qa-monthly-schedule.php
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
// 1. Migration 023, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261126_023_monthly_schedule.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261126_023_monthly_schedule_down.sql'));
$lines = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261126_023_ensaio_linhas.sql'));
$check = (string)file_get_contents($root . '/migrations/verificacao/20261126_023_monthly_schedule_check.sql');
$tables = ['portal_monthly_schedules', 'portal_monthly_schedule_revisions', 'portal_monthly_schedule_entries', 'portal_pa_day_locks'];

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 023: fuso da sessao (A4)');
foreach ($tables as $table) {
    assert_same(1, preg_match("/TABLE_NAME = '{$table}'\\s*\\);\\s*SET @create_[a-z]+_sql := IF\\(\\s*@has_[a-z]+ = 0,\\s*'CREATE TABLE {$table} \\(/", $migration), "migration 023: {$table} so se nao existir (idempotente)");
}
assert_same([0, 0, 0, 0], [
    preg_match('/\b((?<!ON )UPDATE|(?<!ON )DELETE|INSERT|DROP|TRUNCATE)\b/i', $migration),
    preg_match('/ALTER TABLE/i', $migration),
    preg_match('/\b(nome|name|login|observ|note|category)\b/i', $migration),
    preg_match('/ON DELETE CASCADE/i', $migration),
], 'migration 023: so CREATE TABLE (nenhuma linha de outra tabela muda), sem nome, login, observacao ou categoria, sem cascata');
assert_same([1, 1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_monthly_schedule_month (ref_month)'),
    substr_count($migration, "CONSTRAINT ck_monthly_schedule_day1 CHECK (DAYOFMONTH(ref_month) = 1)"),
    substr_count($migration, "current_revision INT UNSIGNED NOT NULL DEFAULT 0"),
    substr_count($migration, "row_version INT UNSIGNED NOT NULL DEFAULT 1"),
], 'migration 023: um mes por linha (dia 1), contador de revisao e row_version (409, D-F10-48)');
assert_same([1, 1, 1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_monthly_revision (schedule_id, revision)'),
    substr_count($migration, "reason ENUM(''publicacao'', ''regeracao'') NOT NULL"),
    substr_count($migration, 'input_hash CHAR(64) NOT NULL'),
    substr_count($migration, "CONSTRAINT ck_monthly_revision_counts CHECK (JSON_TYPE(counts) = ''OBJECT'')"),
    substr_count($migration, 'CONSTRAINT fk_monthly_revision_schedule FOREIGN KEY (schedule_id) REFERENCES portal_monthly_schedules (id) ON DELETE RESTRICT'),
], 'migration 023: revisoes numeradas por mes, motivo, hash das entradas e contagens em JSON');
assert_same([1, 1, 1, 1, 1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_monthly_entry (revision_id, work_date, employee_id)'),
    substr_count($migration, "situation ENUM(''onsite'', ''remote'', ''absent'') NOT NULL"),
    substr_count($migration, "source ENUM(''rule'', ''team'', ''exception'', ''absence'', ''implicit'', ''role'', ''none'') NOT NULL"),
    substr_count($migration, "pa_source ENUM(''fixed'', ''reserved'', ''lock'', ''engine'') NULL"),
    substr_count($migration, 'CONSTRAINT ck_monthly_entry_pa CHECK ((pa_number IS NULL) = (pa_source IS NULL))'),
    substr_count($migration, 'CONSTRAINT ck_monthly_entry_no_pa CHECK (no_pa IN (0, 1) AND moved IN (0, 1) AND NOT (no_pa = 1 AND pa_number IS NOT NULL))'),
    substr_count($migration, 'CONSTRAINT fk_monthly_entry_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'),
], 'migration 023: linha por pessoa e dia, so IDs, situacao e origem fechadas, PA e fonte juntos, sem PA nunca com PA');
assert_same([1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_pa_day_lock_pa (work_date, pa_number)'),
    substr_count($migration, 'UNIQUE KEY uq_pa_day_lock_employee (work_date, employee_id)'),
    substr_count($migration, 'CONSTRAINT fk_pa_day_lock_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'),
], 'migration 023: trava: um PA por pessoa e uma pessoa por PA no dia');

// Rollback: para antes de alterar se ja houver copia; RENAME (nada e apagado).
assert_same(1, substr_count($rollback, "SET time_zone = 'America/Sao_Paulo';"), 'rollback 023: fuso da sessao');
assert_same(true, strpos($rollback, 'CALL rollback_023_confere();') < strpos($rollback, 'RENAME TABLE'), 'rollback 023: a checagem roda antes de qualquer RENAME');
assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'\n            SET MESSAGE_TEXT = 'rollback_023 abortado: ja existe copia *_bkp_023 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';"), 'rollback 023: para com copia anterior (a mensagem cabe nos 128 caracteres do SIGNAL)');
assert_same(true, strlen('rollback_023 abortado: ja existe copia *_bkp_023 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.') <= 128, 'rollback 023: mensagem do SIGNAL ate 128 caracteres');
foreach ($tables as $table) {
    assert_same(1, substr_count($rollback, "'RENAME TABLE {$table} TO {$table}_bkp_023'"), "rollback 023: {$table} vira a copia {$table}_bkp_023");
    assert_same(1, substr_count($rollback, "'{$table}_bkp_023'"), "rollback 023: a checagem procura a copia de {$table}");
}
assert_same([0, 0], [preg_match('/\b(DROP TABLE|DELETE|TRUNCATE|UPDATE)\b/i', $rollback), preg_match('/\bDROP\b(?! PROCEDURE)/i', $rollback)], 'rollback 023: nada e apagado (so RENAME; o DROP e so o do procedimento temporario)');
assert_same(true, strpos($rollback, 'portal_monthly_schedule_entries TO') < strpos($rollback, 'portal_monthly_schedule_revisions TO') && strpos($rollback, 'portal_monthly_schedule_revisions TO') < strpos($rollback, 'portal_monthly_schedules TO'), 'rollback 023: das linhas para o mes (filhas antes da mae)');

// Linhas do ensaio: so no banco ensaio_*, idempotentes, so nas tabelas da 023.
preg_match_all('/^INSERT INTO ([a-z_]+)/m', $lines, $inserted);
assert_same($tables, $inserted[1], 'linhas do ensaio 023: gravam so nas quatro tabelas da 023, uma vez cada');
assert_same([4, 4, 0], [
    substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"),
    preg_match_all('/NOT EXISTS \(SELECT 1 FROM portal_(monthly_schedules|monthly_schedule_revisions|monthly_schedule_entries|pa_day_locks)\b/', $lines),
    preg_match('/\b(nome|login)\b/i', $lines),
], 'linhas do ensaio 023: so no banco de ensaio, idempotentes, sem nome nem login');

// Verificacao: so contagens e assinaturas; as contagens novas so com a 023 inteira.
assert_same([1, 1, 1, 1], [
    substr_count($check, "SET @tem_023 := ("),
    substr_count($check, '@tem_023 = 4,') === 2 ? 1 : 0,
    preg_match("/AND TABLE_NAME IN \('portal_monthly_schedule_revisions', 'portal_monthly_schedule_entries', 'portal_pa_day_locks'\)\s+AND CONSTRAINT_NAME/", $check),
    preg_match("/CONSTRAINT_TYPE = 'CHECK'\s+AND TABLE_NAME IN \('portal_monthly_schedules', 'portal_monthly_schedule_revisions', 'portal_monthly_schedule_entries'\)/", $check),
], 'verificacao 023: contagens novas so com as 4 tabelas; FKs e CHECKs contadas so nas tabelas vivas (depois do rollback, 0)');
assert_same([0, 1, 1], [
    preg_match('/SELECT[^;]*\b(nome|login|created_by|published_by)\b/i', qa_sql_without_comments($check)),
    substr_count($check, 'AS meses_sem_revisao_publicada_com_contador'),
    substr_count($check, 'AS linhas_com_pa_e_sem_pa'),
], 'verificacao 023: sem nome nem autoria; problemas contados');

// ----------------------------------------------------------------------------
// 2. MonthlySchedulePlanner (puro): dias, grade, RESUMO, hash e divergencia
// ----------------------------------------------------------------------------
require_once $root . '/services/MonthlySchedulePlanner.php';
$qaTry = static function (callable $call): string {
    try {
        $call();
        return 'ok';
    } catch (InvalidArgumentException $e) {
        return '400: ' . $e->getMessage();
    }
};
assert_same(23, count(MonthlySchedulePlanner::businessDays('2026-12')), 'dias: dezembro de 2026 tem 23 dias uteis (feriado conta, D-F10-4 a)');
assert_same(['2026-12-30', '2026-12-31'], MonthlySchedulePlanner::businessDays('2026-12', '2026-12-30'), 'dias: a partir da data inicial');
assert_same(['400: Mês inválido: use AAAA-MM.', '400: Mês inválido: use AAAA-MM.', '400: Data inicial fora do mês.'], [
    $qaTry(fn () => MonthlySchedulePlanner::businessDays('2026-13')),
    $qaTry(fn () => MonthlySchedulePlanner::businessDays('26-12')),
    $qaTry(fn () => MonthlySchedulePlanner::businessDays('2026-12', '2027-01-04')),
], 'dias: mes e data inicial validados');
assert_same(['2026-12-01', '2026-11-27', null, '2026-11-30'], [
    MonthlySchedulePlanner::earliestFrom('2026-12', '2026-11-26'),
    MonthlySchedulePlanner::earliestFrom('2026-11', '2026-11-26'),
    MonthlySchedulePlanner::earliestFrom('2026-10', '2026-11-26'),
    MonthlySchedulePlanner::earliestFrom('2026-11', '2026-11-27'),
], 'data minima: o proximo dia util depois de hoje; mes passado null (D-F10-2)');

// Cenario ficticio: 4 do grupo comum (2 N1, 2 N2), 1 do Critico, 1 Lideranca;
// 3 PAs flexiveis e 1 reservado ao Critico; 2 dias.
$qaSeats = [
    ['pa' => 1, 'row' => 1, 'column' => 1, 'type' => 'flexible', 'can_lend' => false, 'reserved_for' => null, 'active' => true, 'unavailable' => false, 'owners' => []],
    ['pa' => 2, 'row' => 1, 'column' => 2, 'type' => 'flexible', 'can_lend' => false, 'reserved_for' => null, 'active' => true, 'unavailable' => false, 'owners' => []],
    ['pa' => 3, 'row' => 1, 'column' => 3, 'type' => 'flexible', 'can_lend' => false, 'reserved_for' => null, 'active' => true, 'unavailable' => false, 'owners' => []],
    ['pa' => 9, 'row' => 2, 'column' => 1, 'type' => 'fixed', 'can_lend' => false, 'reserved_for' => 'critical', 'active' => true, 'unavailable' => false, 'owners' => []],
];
$qaPresence = static function (array $types): array {
    $rows = [];
    $names = [11 => 'Alfa', 12 => 'Bravo', 21 => 'Charlie', 22 => 'Delta', 31 => 'Eco', 41 => 'Foxtrot'];
    $teams = [11 => 'n1', 12 => 'n1', 21 => 'n2', 22 => 'n2', 31 => 'critico', 41 => 'lideranca'];
    foreach ($types as $date => $byId) {
        foreach ($byId as $id => $type) {
            $rows[] = ['employee_id' => $id, 'name' => $names[$id], 'team' => $teams[$id], 'date' => $date, 'presence_type' => $type, 'source' => $id === 41 ? 'implicit' : 'rule', 'rule_type' => 'cycle']
                + ($id === 31 ? ['papel' => ['id' => 1, 'sort_order' => 1, 'label' => 'Presencial']] : []);
        }
    }
    return $rows;
};
$qaInput = [
    'month' => '2026-12',
    'from' => '2026-12-30',
    'presence' => $qaPresence([
        '2026-12-30' => [11 => 'onsite', 12 => 'remote', 21 => 'onsite', 22 => 'absence', 31 => 'onsite', 41 => 'onsite'],
        '2026-12-31' => [11 => 'onsite', 12 => 'onsite', 21 => 'onsite', 22 => 'onsite', 31 => 'remote', 41 => 'onsite'],
    ]),
    'people' => [11 => ['grupo' => 'comum'], 12 => ['grupo' => 'comum'], 21 => ['grupo' => 'comum'], 22 => ['grupo' => 'comum'], 31 => ['grupo' => 'comum'], 41 => ['grupo' => 'comum']],
    'rotation' => ['2026-12-30' => [11 => 'Time 1', 12 => 'Time 2'], '2026-12-31' => [11 => 'Time 1', 12 => 'Time 2']],
    'seats' => $qaSeats,
    'preferences' => [2 => [21]],
    'locks' => [],
    'last_pa' => [11 => 3],
];
$qaPlan = MonthlySchedulePlanner::compute($qaInput);
$qaCell = static function (array $plan, int $id, string $date): ?array {
    foreach ($plan['entries'] as $entry) {
        if ($entry['employee_id'] === $id && $entry['work_date'] === $date) {
            return $entry;
        }
    }
    return null;
};
assert_same([11, 12, 21, 22], $qaPlan['common'], 'grupo comum: N1 e N2 do grupo comum (Critico e Lideranca fora)');
assert_same(['3', 'engine', 'team'], [$qaCell($qaPlan, 11, '2026-12-30')['pa_number'], $qaCell($qaPlan, 11, '2026-12-30')['pa_source'], $qaCell($qaPlan, 11, '2026-12-30')['source']], 'grade: o PA do dia util anterior (last_pa) e a origem "time" de quem esta num time de rodizio');
assert_same(['2', 'rule'], [$qaCell($qaPlan, 21, '2026-12-30')['pa_number'], $qaCell($qaPlan, 21, '2026-12-30')['source']], 'grade: a preferencia pesa (custo 2) e a regra individual fica "regra"');
assert_same(['9', 'reserved'], [$qaCell($qaPlan, 31, '2026-12-30')['pa_number'], $qaCell($qaPlan, 31, '2026-12-30')['pa_source']], 'grade: o Critico presencial no reservado pelo papel do dia');
assert_same([null, 'absent', null], [$qaCell($qaPlan, 12, '2026-12-30')['pa_number'], $qaCell($qaPlan, 22, '2026-12-30')['situation'], $qaCell($qaPlan, 22, '2026-12-30')['pa_number']], 'grade: home e ausente sem PA');
assert_same([null, 'onsite'], [$qaCell($qaPlan, 41, '2026-12-30')['pa_number'], $qaCell($qaPlan, 41, '2026-12-30')['situation']], 'grade: a Lideranca presencial implicita, fora da distribuicao');
$qaDay = $qaPlan['summary']['2026-12-30'];
assert_same([2, 1, 1, 1, 1, 0, 1, 67, ['Time 2' => 1], ['OK'], 1, 3], [
    $qaDay['onsite'], $qaDay['remote'], $qaDay['absent'], $qaDay['n1'], $qaDay['n2'], $qaDay['hybrid'], $qaDay['critical'], $qaDay['percent_onsite'],
    $qaDay['home_by_team'], $qaDay['status'], $qaDay['reserved_seats'], $qaDay['capacity'],
], 'RESUMO: presenciais, home, ausentes, N1, N2, Hibrido, Critico, % presencial (sem os ausentes), home por time, STATUS, assentos reservados e PAs do dia');
$qaFull = $qaPlan['summary']['2026-12-31'];
assert_same([4, 1, ['PA INSUFICIENTE'], 1], [$qaFull['onsite'], $qaFull['no_pa'], $qaFull['status'], array_sum(array_map(static fn (array $e): int => $e['work_date'] === '2026-12-31' ? $e['no_pa'] : 0, $qaPlan['entries']))], 'RESUMO: 4 presenciais e 3 PAs: PA INSUFICIENTE pela contagem real, com 1 sem PA');
$qaNoRule = $qaInput;
$qaNoRule['presence'][] = ['employee_id' => 13, 'name' => 'Golf', 'team' => 'n1', 'date' => '2026-12-30', 'presence_type' => 'no_schedule', 'source' => 'none', 'rule_type' => null];
$qaNoRule['people'][13] = ['grupo' => 'comum'];
$qaNoRulePlan = MonthlySchedulePlanner::compute($qaNoRule);
assert_same([['ATENCAO CONFIG'], 1, null], [$qaNoRulePlan['summary']['2026-12-30']['status'], $qaNoRulePlan['summary']['2026-12-30']['without_schedule'], $qaCell($qaNoRulePlan, 13, '2026-12-30')], 'STATUS: alguem do grupo comum sem regra da ATENCAO CONFIG (e nao vira linha)');
$qaSeparate = $qaInput;
$qaSeparate['people'][12] = ['grupo' => 'separado'];
assert_same([11, 21, 22], MonthlySchedulePlanner::compute($qaSeparate)['common'], 'grupo comum: o N1 no grupo "separado" fica fora');
assert_same([$qaPlan['input_hash'], 64], [MonthlySchedulePlanner::compute($qaInput)['input_hash'], strlen($qaPlan['input_hash'])], 'hash: deterministico, SHA-256');
$qaChanged = [];
foreach (['presence' => static function (array $i): array { $i['presence'][0]['presence_type'] = 'remote'; return $i; },
          'seats' => static function (array $i): array { $i['seats'][0]['unavailable'] = true; return $i; },
          'preferences' => static function (array $i): array { $i['preferences'] = []; return $i; },
          'locks' => static function (array $i): array { $i['locks'] = ['2026-12-31' => [['pa' => 1, 'employee_id' => 12]]]; return $i; },
          'last_pa' => static function (array $i): array { $i['last_pa'] = []; return $i; },
          'rotation' => static function (array $i): array { $i['rotation'] = []; return $i; },
          'people' => static function (array $i): array { $i['people'][12]['grupo'] = 'separado'; return $i; }] as $what => $change) {
    $qaChanged[$what] = MonthlySchedulePlanner::compute($change($qaInput))['input_hash'] !== $qaPlan['input_hash'];
}
assert_same(array_fill_keys(['presence', 'seats', 'preferences', 'locks', 'last_pa', 'rotation', 'people'], true), $qaChanged, 'hash: muda com qualquer entrada (presenca, assentos, preferencias, travas, last_pa, times e grupo)');
$qaReordered = $qaInput;
$qaReordered['presence'] = array_reverse($qaInput['presence']);
$qaReordered['preferences'] = [2 => [21]];
assert_same($qaPlan['input_hash'], MonthlySchedulePlanner::compute($qaReordered)['input_hash'], 'hash: a ordem das linhas de presenca nao muda o hash (forma canonica)');
$qaLocked = $qaInput;
$qaLocked['locks'] = ['2026-12-31' => [['pa' => 1, 'employee_id' => 12]]];
assert_same(['1', 'lock'], [$qaCell(MonthlySchedulePlanner::compute($qaLocked), 12, '2026-12-31')['pa_number'], $qaCell(MonthlySchedulePlanner::compute($qaLocked), 12, '2026-12-31')['pa_source']], 'trava: a pessoa no PA travado, com a fonte "lock"');
assert_same(['11:2026-12-30' => 'ausente_com_pa', '12:2026-12-30' => 'situacao_mudou', '21:2026-12-30' => 'situacao_mudou'], MonthlySchedulePlanner::divergences(
    array_values(array_filter($qaPlan['entries'], static fn (array $e): bool => $e['work_date'] === '2026-12-30' && in_array($e['employee_id'], [11, 12, 21], true))),
    [['employee_id' => 11, 'date' => '2026-12-30', 'presence_type' => 'absence'], ['employee_id' => 12, 'date' => '2026-12-30', 'presence_type' => 'onsite']]
), 'divergencia: ausencia nova com PA, situacao mudada e quem saiu da presenca (D-F10-14)');
assert_same([], MonthlySchedulePlanner::divergences($qaPlan['entries'], $qaInput['presence']), 'divergencia: nenhuma com a presenca do calculo');
assert_same(['presencial', 'remoto', 'ausente', 'sem_escala', 'regra', 'excecao', 'ausencia', 'implicita', 'regra', 'nenhuma'], [
    MonthlySchedulePlanner::paritySituation('onsite'), MonthlySchedulePlanner::paritySituation('remote'), MonthlySchedulePlanner::paritySituation('absence'), MonthlySchedulePlanner::paritySituation('no_schedule'),
    MonthlySchedulePlanner::paritySource('rule'), MonthlySchedulePlanner::paritySource('exception'), MonthlySchedulePlanner::paritySource('absence'), MonthlySchedulePlanner::paritySource('implicit'), MonthlySchedulePlanner::paritySource('role'), MonthlySchedulePlanner::paritySource('none'),
], 'paridade-escala-v1: situacao e fonte do contrato (8.1)');
assert_same([2, 12, 6, 9, 6, 1, 1, 1, 1, 0], array_values(array_intersect_key($qaPlan['counts'], array_flip(['dias', 'linhas', 'pessoas', 'presenciais', 'com_pa', 'sem_pa', 'trocas', 'ok', 'pa_insuficiente', 'atencao_config']))), 'contagens da revisao: so numeros');
assert_same(false, (bool)preg_match('/Alfa|Bravo|Charlie|Delta|Eco|Foxtrot/', (string)json_encode([$qaPlan['entries'], $qaPlan['summary'], $qaPlan['counts']])), 'grade, RESUMO e contagens sem nome (so IDs)');

// ----------------------------------------------------------------------------
// 3. Servico e endpoint (estrutura; o comportamento no MySQL e a integracao)
// ----------------------------------------------------------------------------
$service = (string)file_get_contents($root . '/services/MonthlyScheduleService.php');
$endpoint = (string)file_get_contents($root . '/api/portal/monthly_schedule.php');
$security = (string)file_get_contents($root . '/security.php');
assert_same([1, 1, 1, 1], [
    substr_count($service, "'SELECT id, current_revision, row_version FROM portal_monthly_schedules WHERE ref_month = :month' . (\$lock ? ' FOR UPDATE' : '')"),
    substr_count($service, 'if ($state[\'current_revision\'] !== $expected) {' . "\n" . '                throw new DomainException(self::STALE_REVISION);'),
    substr_count($service, 'if (!hash_equals($computed[\'input_hash\'], $hash)) {' . "\n" . '                throw new DomainException(self::STALE_INPUT);'),
    substr_count($service, '$computed = $this->compute($month, $from);' . "\n" . '            if (!hash_equals('),
], 'publicar: mes travado (FOR UPDATE), 409 pela revisao e pelo hash, e o servidor recalcula (nunca grava o que o cliente mandou)');
assert_same(true, strpos($service, 'return $this->atomic(function () use ($month, $expected, $hash, $data, $actor): array {') !== false && strpos($service, "UPDATE portal_monthly_schedules SET current_revision = :revision, row_version = row_version + 1 WHERE id = :id") !== false, 'publicar: numa transacao; incrementa revisao e row_version');
assert_same([0, 1], [preg_match('/DELETE FROM portal_monthly_schedule/', $service), substr_count($service, "DELETE FROM portal_pa_day_locks WHERE work_date = :date AND pa_number = :pa")], 'nada da escala e apagado (so a trava do dia e trocada)');
assert_same([1, 1], [substr_count($service, "if (\$date <= \$this->today()) {\n            throw new DomainException('A trava vale só do próximo dia útil em diante.');"), substr_count($service, "throw new DomainException('O passado não é reescrito: a data inicial mínima é ' . self::dateLabel(\$earliest) . '.');")], 'o passado nunca e reescrito: trava e regeracao so do proximo dia util em diante');
assert_same([1, 1, 1], [
    substr_count($service, "if ((\$actor['role'] ?? null) !== 'admin') {\n            throw new PortalForbiddenException('Somente administradores leem a paridade da escala.');"),
    substr_count($service, "    private function assertCanPublish(array \$actor): void\n    {\n        if (!portal_role_can((string)(\$actor['role'] ?? ''), 'escala_mensal.publish')) {"),
    substr_count($endpoint, "if (!portal_role_can(\$role, 'escala_mensal.publish')) {"),
], 'permissoes: paridade so admin; previa e publicacao com escala_mensal.publish (no servico e no endpoint)');
assert_same(1, preg_match("/'escalas\\.manage'.*'escala_mensal\\.publish',/s", $security), 'escala_mensal.publish no conjunto da Lideranca (e o admin herda; D-F10-3)');
assert_same(0, preg_match('/audit\([^;]*(name|nome|\$names)/', $service), 'auditoria so com IDs e contagens');
assert_same(true, strpos($service, "'ESCALA_MENSAL_PUBLICADA' : 'ESCALA_MENSAL_REGERADA'") !== false && strpos($service, "\$reason === 'publicacao' ? 'INFO' : 'WARNING'") !== false, 'auditoria: publicada INFO, regerada WARNING');
assert_same([1, 1], [substr_count($service, '$this->operational()->monthPresence($from, $to)'), substr_count((string)file_get_contents($root . '/services/OperationalService.php'), "\$screen, true, false) as \$row) {")], 'presenca: pelo motor de sempre, sem os feriados (o rodizio conta o dia, D-F10-4 a)');

echo 'QA monthly schedule OK (' . ($GLOBALS['qa_checks'] ?? 0) . " verificacoes)\n";
