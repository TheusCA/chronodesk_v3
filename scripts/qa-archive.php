<?php
declare(strict_types=1);

// ============================================================================
// QA do arquivamento de tecnicos (Parte B, F4; desenho, secao 9.6)
//
// 1. Migration 014, rollback, verificacao e dados de ensaio (estrutura; o SQL
//    so roda no servidor e no MySQL do responsavel: ensaio e teste).
// 2. Plano do arquivamento (EmployeeArchivePlanner, funcao pura): cadastro
//    (D5), PA (D6), regras (planRemove do F1), excecoes (D7), ausencias (D8
//    com o ajuste de 2026-10-13), pausa (P16, D9), virada 23:59/00:00 com o
//    instante injetado, invariante de periodo valido em varredura, dado fora
//    do formato e pureza.
// O servico e os endpoints entram no commit 3 do F4.
//
// Uso: php scripts/qa-archive.php
// ============================================================================

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

$root = dirname(__DIR__);
$checks = 0;

function assert_same($expected, $actual, string $message): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n  esperado: " . var_export($expected, true) . "\n  obtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// ----------------------------------------------------------------------------
// 1. Migration 014, rollback, verificacao e ensaio (estrutura)
// ----------------------------------------------------------------------------
$sqlFile = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    assert_same(true, is_string($content) && $content !== '', "{$path}: arquivo lido");
    return (string)preg_replace('/^\s*--.*$/m', '', (string)$content);
};
$migration = $sqlFile('migrations/20261013_014_employee_archive.sql');
$rollback = $sqlFile('migrations/rollback/20261013_014_employee_archive_down.sql');
$check = $sqlFile('migrations/verificacao/20261013_014_employee_archive_check.sql');
$f4Rows = $sqlFile('migrations/rollback/20261013_014_ensaio_linhas_f4.sql');
$at = static fn (string $sql, string $needle): int => ($found = strpos($sql, $needle)) === false ? -1 : $found;

foreach (['014' => $migration, 'rollback 014' => $rollback, 'verificacao 014' => $check, 'ensaio linhas F4' => $f4Rows] as $label => $sql) {
    assert_same(1, preg_match("/^\\s*SET time_zone = 'America\\/Sao_Paulo';/", $sql), "{$label}: comeca com SET time_zone (regra do A4)");
    assert_same(0, preg_match('/\b(employee_id|id)\s*(=|IN)\s*\(?\s*\d/i', $sql), "{$label}: sem id fixo de colaborador");
}
foreach (['014' => $migration, 'rollback 014' => $rollback, 'ensaio linhas F4' => $f4Rows] as $label => $sql) {
    assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $sql), "{$label}: sem relogio do banco (regra do A4)");
}
// A verificacao so le: "hoje" e a data do MySQL no fuso do PHP, uma vez, depois do SET time_zone.
assert_same(1, preg_match_all('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $check), 'verificacao 014: um unico uso do relogio');
$today = $at($check, 'SET @hoje := CURDATE();');
assert_same(true, $today > $at($check, "SET time_zone = 'America/Sao_Paulo';") && $today < $at($check, 'SELECT'), 'verificacao 014: @hoje depois do SET time_zone e antes das consultas');

foreach (['014' => $migration, 'rollback 014' => $rollback, 'verificacao 014' => $check] as $label => $sql) {
    $prepares = preg_match_all('/^PREPARE /m', $sql);
    assert_same(true, $prepares > 0, "{$label}: usa PREPARE");
    assert_same([$prepares, $prepares], [preg_match_all('/^EXECUTE /m', $sql), preg_match_all('/^DEALLOCATE PREPARE /m', $sql)], "{$label}: todo PREPARE tem EXECUTE e DEALLOCATE");
    // Todo PREPARE le uma variavel atribuida antes no arquivo (variavel sem SET vira NULL e o PREPARE falha no meio).
    preg_match_all('/^PREPARE (\w+) FROM @(\w+);/m', $sql, $prepared, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    assert_same($prepares, count($prepared), "{$label}: todo PREPARE le uma variavel");
    foreach ($prepared as $statement) {
        $variable = $statement[2][0];
        $assigned = $at($sql, "SET @{$variable} := ");
        assert_same(true, $assigned > -1 && $assigned < $statement[0][1], "{$label}: @{$variable} atribuida antes do PREPARE");
    }
}
foreach (['014' => $migration, 'rollback 014' => $rollback] as $label => $sql) {
    assert_same(0, preg_match('/^\s*(ALTER|CREATE)\s+TABLE/mi', $sql), "{$label}: todo ALTER/CREATE TABLE dentro de IF com checagem em information_schema (idempotente)");
}
// Idempotencia: a migration so acrescenta o que falta (= 0); o rollback so tira o que existe (> 0).
preg_match_all('/SET @(\w+)_sql := IF\(\s*@(\w+) (= 0|> 0),/', $migration, $migrationIfs, PREG_SET_ORDER);
assert_same(3, count($migrationIfs), '014: tres passos condicionais');
foreach ($migrationIfs as $if) {
    assert_same('= 0', $if[3], "014: @{$if[1]}_sql so acrescenta se @{$if[2]} = 0");
}
preg_match_all('/SET @(drop_\w+)_sql := IF\(\s*@(\w+) (= 0|> 0),/', $rollback, $rollbackIfs, PREG_SET_ORDER);
assert_same(3, count($rollbackIfs), 'rollback 014: tres remocoes condicionais');
foreach ($rollbackIfs as $if) {
    assert_same('> 0', $if[3], "rollback 014: @{$if[1]}_sql so remove se @{$if[2]} > 0");
}
assert_same(1, preg_match("/SET @copy_sql := IF\\(\\s*@has_014_columns = 2,/", $rollback), 'rollback 014: copia com data e autor quando as duas colunas existem');

// Migration: so estrutura, colunas nulas no fim, CHECK do cadastro ativo.
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|TRUNCATE|REPLACE)\b/mi', $migration), '014 nao altera linha de dado');
assert_same(true, $at($migration, 'ADD COLUMN arquivado_em DATETIME NULL') > -1, '014: arquivado_em DATETIME nulo');
assert_same(true, $at($migration, 'ADD COLUMN arquivado_por VARCHAR(100) NULL') > -1, '014: arquivado_por VARCHAR(100) nulo');
assert_same(0, preg_match('/\bAFTER\b/i', $migration), '014: colunas no fim, sem AFTER (nao depende da 011)');
assert_same(0, preg_match('/\bDEFAULT\b/i', $migration), '014: sem DEFAULT (data so pelo PHP, regra do A4)');
assert_same(true, $at($migration, 'CHECK (ativo = 0 OR (arquivado_em IS NULL AND arquivado_por IS NULL))') > -1, '014: CHECK ck_funcionario_arquivado (D11)');
assert_same(true, $at($migration, "COLUMN_NAME = 'arquivado_por'") < $at($migration, 'ADD CONSTRAINT ck_funcionario_arquivado'), '014: CHECK depois das duas colunas');
foreach (["COLUMN_NAME = 'arquivado_em'", "COLUMN_NAME = 'arquivado_por'", "CONSTRAINT_NAME = 'ck_funcionario_arquivado' AND CONSTRAINT_TYPE = 'CHECK'"] as $guard) {
    assert_same(true, $at($migration, $guard) > -1, "014: checagem em information_schema ({$guard})");
}
assert_same(0, preg_match('/\b(SIGNAL|DROP)\b/i', $migration), '014: sem parada e sem remocao');

// Rollback: parada, copia minima antes de alterar, CHECK antes das colunas.
$abortAt = $at($rollback, 'CALL rollback_014_confere_copia();');
$firstChange = min(array_filter([$at($rollback, 'CREATE TABLE funcionarios_bkp_014'), $at($rollback, 'ALTER TABLE'), $at($rollback, 'PREPARE ')], static fn (int $pos): bool => $pos > -1));
assert_same(true, $abortAt > -1 && $abortAt < $firstChange, 'rollback 014: checagem da copia antes de qualquer alteracao');
assert_same(true, $at($rollback, 'DROP PROCEDURE IF EXISTS rollback_014_confere_copia;') < $at($rollback, 'CREATE PROCEDURE rollback_014_confere_copia()'), 'rollback 014: procedimento recriado a cada execucao');
assert_same(1, preg_match("/IF \\(SELECT COUNT\\(\\*\\) FROM information_schema\\.TABLES\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'funcionarios_bkp_014'\\) > 0 THEN\\s+SIGNAL SQLSTATE '45000'/", $rollback), 'rollback 014: para se a copia ja existe');
assert_same(1, preg_match("/SET MESSAGE_TEXT = '([^']+)'/", $rollback, $message), 'rollback 014: mensagem da parada');
assert_same(true, strlen($message[1]) <= 128 && strpos($message[1], 'Nada foi alterado') !== false, 'rollback 014: mensagem clara e dentro do limite de 128 do SIGNAL');
assert_same(true, $at($rollback, "'CREATE TABLE funcionarios_bkp_014 AS SELECT id, ativo, arquivado_em, arquivado_por FROM funcionarios'") > -1, 'rollback 014: copia de id, ativo, data e autor');
assert_same(true, $at($rollback, "'CREATE TABLE funcionarios_bkp_014 AS SELECT id, ativo FROM funcionarios'") > -1, 'rollback 014: sem as colunas, copia so id e ativo (nunca pula a copia)');
assert_same(0, preg_match('/CREATE TABLE IF NOT EXISTS|SELECT \*|\b(nome|ad_login|equipe|access_role)\b/i', $rollback), 'rollback 014: copia minima, sem nome nem login, nunca pulada em silencio');
$copyAt = $at($rollback, 'EXECUTE copy_stmt;');
$dropCheck = $at($rollback, "'ALTER TABLE funcionarios DROP CHECK ck_funcionario_arquivado'");
$dropBy = $at($rollback, "'ALTER TABLE funcionarios DROP COLUMN arquivado_por'");
$dropAt = $at($rollback, "'ALTER TABLE funcionarios DROP COLUMN arquivado_em'");
assert_same(true, $copyAt > -1 && $copyAt < $dropCheck && $dropCheck < $dropBy && $dropCheck < $dropAt, 'rollback 014: copia, depois CHECK, depois colunas');
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|TRUNCATE|REPLACE)\b/mi', $rollback), 'rollback 014: nenhuma linha muda (ativo = 0 fica)');

// Verificacao: so le, so contagens, sem nome nem login.
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|RENAME)\b/i', $check), 'verificacao 014 so le');
assert_same(0, preg_match('/\b(employee_name|nome|ad_login|reason|note|notes|observacao\w*)\b/i', $check), 'verificacao 014 sem nome, login nem texto');
assert_same(0, preg_match('/SELECT\s+\*/i', $check), 'verificacao 014 sem SELECT *');
foreach (['colunas_014', 'check_014', 'copia_rollback', 'cadastros', 'inativos', 'arquivados_f4', 'inativos_sem_data', 'ativos_com_data', 'arquivados_sem_autor',
          'inativos_com_pa', 'inativos_com_regra', 'excecoes_de_inativos', 'ausencias_de_inativos', 'pa_periodo_invertido'] as $column) {
    assert_same(true, $at($check, "AS {$column}") > -1, "verificacao 014: {$column}");
}
foreach (['portal_pa_assignments', 'portal_schedule_rules', 'portal_schedule_exceptions', 'portal_absences'] as $table) {
    assert_same(true, $at($check, "TABLE_NAME = '{$table}'") > -1, "verificacao 014: {$table} so com a tabela presente (ensaio sem DADOS)");
}
assert_same(true, $at($check, "@has_014_columns = 2,") > -1, 'verificacao 014: cadastros so com as colunas da 014');
assert_same(4, substr_count($check, 'AND f.ativo = 0'), 'verificacao 014: os quatro efeitos pendentes so de inativos');
assert_same(4, substr_count($check, '>= @hoje'), 'verificacao 014: os quatro efeitos pendentes de hoje em diante');
assert_same(true, $at($check, "WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= @hoje)") > -1, 'verificacao 014: PA aberto ou futuro');
assert_same(true, $at($check, "AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from)") > -1, 'verificacao 014: regra vazia (removida antes de comecar) nao conta');
assert_same(true, $at($check, "WHERE b.status = ''approved'' AND b.ends_on >= @hoje") > -1, 'verificacao 014: so ausencia aprovada');

// Ensaio: so no banco descartavel, um UPDATE so em funcionarios, idempotente, data explicita.
assert_same(true, $at($f4Rows, "WHERE DATABASE() LIKE 'ensaio\\_%'") > -1, 'ensaio linhas F4: so altera banco ensaio_*');
assert_same(1, preg_match_all('/\bUPDATE\b/i', $f4Rows), 'ensaio linhas F4: um UPDATE so');
assert_same(1, preg_match('/^\s*UPDATE funcionarios f\b/m', $f4Rows), 'ensaio linhas F4: so em funcionarios');
assert_same(0, preg_match('/\b(INSERT|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE)\b/i', $f4Rows), 'ensaio linhas F4: so atualiza');
assert_same(0, preg_match('/\b(nome|ad_login)\b/i', $f4Rows), 'ensaio linhas F4: sem nome nem login');
assert_same(true, $at($f4Rows, "JOIN (SELECT COUNT(*) AS n FROM funcionarios WHERE arquivado_por = 'qa.ensaio') AS ja ON ja.n = 0") > -1, 'ensaio linhas F4: idempotente');
assert_same(true, $at($f4Rows, "f.arquivado_em = '2026-10-13 10:00:00'") > -1 && $at($f4Rows, "f.arquivado_por = 'qa.ensaio'") > -1 && $at($f4Rows, 'f.ativo = 0') > -1, 'ensaio linhas F4: arquiva com data e autor (respeita o CHECK)');

// ----------------------------------------------------------------------------
// 2. Plano do arquivamento (EmployeeArchivePlanner, funcao pura)
// ----------------------------------------------------------------------------
require_once $root . '/services/EmployeeArchivePlanner.php';

const QA_EMPLOYEE = 10;

function qa_now(string $local): DateTimeImmutable {
    return new DateTimeImmutable($local, new DateTimeZone('America/Sao_Paulo'));
}

function qa_input(array $overrides = []): array {
    return $overrides + [
        'employee' => ['id' => QA_EMPLOYEE, 'ativo' => 1, 'arquivado_em' => null],
        'rules' => [],
        'exceptions' => [],
        'absences' => [],
        'pa_links' => [],
        'pause' => null,
    ];
}

function qa_pa(int $id, string $from, ?string $until, int $active = 1): array {
    return ['id' => $id, 'employee_id' => QA_EMPLOYEE, 'valid_from' => $from, 'valid_until' => $until, 'active' => $active];
}

function qa_rule(int $id, string $from, ?string $until, string $type = 'fixed_weekdays'): array {
    return ['id' => $id, 'employee_id' => QA_EMPLOYEE, 'rule_type' => $type, 'rule_config' => null, 'effective_from' => $from, 'effective_until' => $until];
}

function qa_exception(int $id, string $date, string $type = 'remote'): array {
    return ['id' => $id, 'employee_id' => QA_EMPLOYEE, 'exception_date' => $date, 'exception_type' => $type];
}

function qa_absence(int $id, string $from, string $to, string $period = 'full_day', string $status = 'approved'): array {
    return ['id' => $id, 'employee_id' => QA_EMPLOYEE, 'starts_on' => $from, 'ends_on' => $to, 'period_type' => $period, 'status' => $status];
}

/** Nome da excecao lancada (ou 'ok') e a mensagem. */
function qa_plan_outcome(array $input, DateTimeImmutable $now): array {
    try {
        EmployeeArchivePlanner::plan($input, $now);
        return ['ok', ''];
    } catch (Throwable $error) {
        return [get_class($error), $error->getMessage()];
    }
}

function qa_ids(array $items): array {
    return array_map(static fn (array $item): int => (int)$item['id'], $items);
}

/** Invariante sobre um plano produzido a partir de $input. */
function qa_assert_valid_periods(array $plan, array $input, string $label): void {
    $index = static function (array $rows): array {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['id']] = $row;
        }
        return $indexed;
    };
    assert_same([], EmployeeArchivePlanner::invertedPeriods($plan, $index($input['pa_links']), $index($input['rules']), $index($input['absences'])), "{$label}: nenhum periodo invertido");
}

$now = qa_now('2026-10-13 10:00:00');

// Cadastro (D5): ativo arquiva; inativo antigo (data nula) regulariza; arquivado pelo F4 da 409.
$plan = EmployeeArchivePlanner::plan(qa_input(), $now);
assert_same(['id' => QA_EMPLOYEE, 'regularizacao' => false, 'arquivado_em' => '2026-10-13 10:00:00'], $plan['employee'], 'cadastro ativo: arquiva com a data de agora');
assert_same('2026-10-13', $plan['today'], 'hoje vem de $now');
foreach ([null, ''] as $empty) {
    $plan = EmployeeArchivePlanner::plan(qa_input(['employee' => ['id' => QA_EMPLOYEE, 'ativo' => 0, 'arquivado_em' => $empty], 'pa_links' => [qa_pa(1, '2026-10-01', null)]]), $now);
    assert_same(true, $plan['employee']['regularizacao'], 'inativo antigo (data ' . var_export($empty, true) . '): regularizacao');
    assert_same(1, $plan['efeitos']['pa_encerrados'], 'inativo antigo: os efeitos valem (D5)');
}
assert_same(
    [DomainException::class, 'Este cadastro já foi arquivado em 01/10/2026. Restaure-o antes de arquivar de novo.'],
    qa_plan_outcome(qa_input(['employee' => ['id' => QA_EMPLOYEE, 'ativo' => 0, 'arquivado_em' => '2026-10-01 09:00:00']]), $now),
    'arquivado pelo F4: DomainException (409) com a data'
);
assert_same(DomainException::class, qa_plan_outcome(qa_input(['employee' => ['id' => QA_EMPLOYEE, 'ativo' => '0', 'arquivado_em' => '2026-10-01 09:00:00']]), $now)[0], 'arquivado pelo F4 com ativo em texto: 409');
assert_same(false, EmployeeArchivePlanner::plan(qa_input(['employee' => ['id' => QA_EMPLOYEE, 'ativo' => true, 'arquivado_em' => null]]), $now)['employee']['regularizacao'], 'ativo booleano: arquivamento normal');

// PA (D6).
$input = qa_input(['pa_links' => [
    qa_pa(1, '2026-10-01', null),          // aberto desde antes: fecha ontem
    qa_pa(2, '2026-10-12', null),          // comecou ontem: fecha ontem (fim = inicio)
    qa_pa(3, '2026-10-13', null),          // comeca hoje: sai
    qa_pa(4, '2026-10-20', null),          // futuro: sai
    qa_pa(5, '2026-10-01', '2026-10-12'),  // encerrado ontem: nada
    qa_pa(6, '2026-10-01', '2026-10-13'),  // termina hoje: fecha ontem
    qa_pa(7, '2026-10-01', null, 0),       // inativo: nada
    qa_pa(8, '2026-10-13', '2026-10-13'),  // so hoje: sai
    qa_pa(9, '2026-10-20', '2026-10-25'),  // futuro com fim: sai
]]);
$plan = EmployeeArchivePlanner::plan($input, $now);
assert_same([
    ['id' => 1, 'valid_until' => '2026-10-12', 'previous_until' => null],
    ['id' => 2, 'valid_until' => '2026-10-12', 'previous_until' => null],
    ['id' => 6, 'valid_until' => '2026-10-12', 'previous_until' => '2026-10-13'],
], $plan['pa']['close'], 'PA: iniciado antes de hoje fecha ontem');
assert_same([3, 4, 8, 9], qa_ids($plan['pa']['remove']), 'PA: de hoje ou futuro sai (active = 0)');
assert_same([3, 4], [$plan['efeitos']['pa_encerrados'], $plan['efeitos']['pa_removidos']], 'PA: contagens');
qa_assert_valid_periods($plan, $input, 'PA');

// Regras (planRemove do F1).
$input = qa_input(['rules' => [
    qa_rule(1, '2026-01-01', '2026-08-31'),  // encerrada: nada
    qa_rule(2, '2026-09-01', '2026-10-19'),  // vigente: fecha ontem
    qa_rule(3, '2026-10-20', null),          // futura: apagada
    qa_rule(4, '2026-11-05', '2026-11-04'),  // vazia (removida antes de comecar): nada
]]);
$plan = EmployeeArchivePlanner::plan($input, $now);
assert_same(['id' => 2, 'effective_until' => '2026-10-12', 'previous_until' => '2026-10-19'], $plan['rules']['close'], 'regra vigente fecha ontem');
assert_same([3], qa_ids($plan['rules']['delete']), 'regra futura apagada; vazia e encerrada ficam');
assert_same([1, 1], [$plan['efeitos']['regras_encerradas'], $plan['efeitos']['regras_removidas']], 'regras: contagens');
$plan = EmployeeArchivePlanner::plan(qa_input(['rules' => [qa_rule(5, '2026-10-13', null)]]), $now);
assert_same([null, [5]], [$plan['rules']['close'], qa_ids($plan['rules']['delete'])], 'regra que comeca hoje: apagada, nunca fechada em ontem');
qa_assert_valid_periods($plan, qa_input(['rules' => [qa_rule(5, '2026-10-13', null)]]), 'regra de hoje');

// Excecoes (D7): de hoje em diante saem, sem a note.
$plan = EmployeeArchivePlanner::plan(qa_input(['exceptions' => [
    qa_exception(1, '2026-10-12'), qa_exception(2, '2026-10-13', 'training'), qa_exception(3, '2026-10-14', 'oncall'),
]]), $now);
assert_same([
    ['id' => 2, 'exception_date' => '2026-10-13', 'exception_type' => 'training'],
    ['id' => 3, 'exception_date' => '2026-10-14', 'exception_type' => 'oncall'],
], $plan['exceptions']['delete'], 'excecoes de hoje em diante saem, com tipo e data');
assert_same(2, $plan['efeitos']['excecoes_removidas'], 'excecoes: contagem');

// Ausencias (D8 com o ajuste de 2026-10-13).
$input = qa_input(['absences' => [
    qa_absence(1, '2026-10-13', '2026-10-13'),                 // dia inteiro que comeca hoje: cancelada
    qa_absence(2, '2026-10-13', '2026-10-13', 'from_time'),    // parcial de hoje: cancelada
    qa_absence(3, '2026-10-13', '2026-10-13', 'interval'),     // parcial de hoje: cancelada
    qa_absence(4, '2026-10-13', '2026-10-17'),                 // varios dias comecando hoje: cancelada
    qa_absence(5, '2026-10-10', '2026-10-15'),                 // em curso, iniciada antes: corta em ontem
    qa_absence(6, '2026-10-10', '2026-10-13'),                 // iniciada antes, termina hoje: corta em ontem
    qa_absence(7, '2026-10-12', '2026-10-12'),                 // terminou ontem: nada
    qa_absence(8, '2026-10-14', '2026-10-14', 'full_day', 'cancelled'), // ja cancelada: nada
    qa_absence(9, '2026-10-14', '2026-10-14', 'from_time'),    // parcial futura: cancelada
    qa_absence(10, '2026-10-12', '2026-10-20'),                // comecou ontem: corta em ontem (fim = inicio)
    qa_absence(11, '2026-10-20', '2026-10-24'),                // futura: cancelada
    qa_absence(12, '2026-10-12', '2026-10-12', 'interval'),    // parcial de ontem: nada
]]);
$plan = EmployeeArchivePlanner::plan($input, $now);
assert_same([1, 2, 3, 4, 9, 11], qa_ids($plan['absences']['cancel']), 'ausencias que comecam hoje ou depois (inclusive parciais e de varios dias) sao canceladas');
assert_same([
    ['id' => 5, 'ends_on' => '2026-10-12', 'previous_ends_on' => '2026-10-15'],
    ['id' => 6, 'ends_on' => '2026-10-12', 'previous_ends_on' => '2026-10-13'],
    ['id' => 10, 'ends_on' => '2026-10-12', 'previous_ends_on' => '2026-10-20'],
], $plan['absences']['cut'], 'ausencias iniciadas antes de hoje e em curso cortadas em ontem');
assert_same([6, 3], [$plan['efeitos']['ausencias_canceladas'], $plan['efeitos']['ausencias_cortadas']], 'ausencias: contagens');
qa_assert_valid_periods($plan, $input, 'ausencias');

// Pausa (P16, D9).
$pause = static fn (array $entry): array => EmployeeArchivePlanner::plan(qa_input(['pause' => $entry]), $now)['pause'];
assert_same([
    'action' => 'finalize', 'inicio' => '2026-10-13 09:45:00', 'fim' => '2026-10-13 10:00:00',
    'duracao_segundos' => 900, 'motivo_pausa' => 'Café',
], $pause(['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:45:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado']), 'pausa aprovada: finalizada agora, com a duracao');
$plan = EmployeeArchivePlanner::plan(qa_input(['pause' => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:45:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado']]), $now);
assert_same([true, false], [$plan['efeitos']['pausa_finalizada'], $plan['efeitos']['solicitacao_descartada']], 'pausa aprovada: efeitos');
assert_same(['action' => 'discard_request'], $pause(['em_pausa' => false, 'inicio_pausa' => null, 'motivo_pausa' => 'Reunião', 'status_aprovacao' => 'pendente']), 'solicitacao pendente: descartada');
$plan = EmployeeArchivePlanner::plan(qa_input(['pause' => ['em_pausa' => false, 'status_aprovacao' => 'pendente']]), $now);
assert_same([false, true], [$plan['efeitos']['pausa_finalizada'], $plan['efeitos']['solicitacao_descartada']], 'solicitacao pendente: efeitos');
assert_same(['action' => 'none'], $pause(['em_pausa' => false, 'inicio_pausa' => null, 'motivo_pausa' => null, 'status_aprovacao' => null]), 'sem pausa: nada');
assert_same(['action' => 'none'], EmployeeArchivePlanner::plan(qa_input(), $now)['pause'], 'sem entrada no estado: nada');
foreach ([
    'aprovacao pendente com em_pausa' => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:45:00-03:00', 'status_aprovacao' => 'pendente'],
    'inicio relativo' => ['em_pausa' => true, 'inicio_pausa' => 'now', 'status_aprovacao' => 'aprovado'],
    'inicio sem fuso' => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13 09:45:00', 'status_aprovacao' => 'aprovado'],
    'inicio ausente' => ['em_pausa' => true, 'status_aprovacao' => 'aprovado'],
    'hora que transborda' => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T24:30:00-03:00', 'status_aprovacao' => 'aprovado'],
    'em_pausa em texto' => ['em_pausa' => 'true', 'inicio_pausa' => '2026-10-13T09:45:00-03:00', 'status_aprovacao' => 'aprovado'],
] as $label => $entry) {
    $expected = $label === 'em_pausa em texto' ? ['action' => 'none'] : ['action' => 'clear_invalid'];
    assert_same($expected, $pause($entry), "pausa ilegivel ({$label}): sem historico");
}
assert_same(0, $pause(['em_pausa' => true, 'inicio_pausa' => '2026-10-13T10:05:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado'])['duracao_segundos'], 'pausa com inicio depois de agora: duracao 0');
$otherZone = $pause(['em_pausa' => true, 'inicio_pausa' => '2026-10-13T10:45:00+00:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado']);
assert_same(['2026-10-13 07:45:00', 8100], [$otherZone['inicio'], $otherZone['duracao_segundos']], 'pausa com inicio em outro fuso: inicio no fuso de agora e duracao pelo instante');

// Virada 23:59:59 / 00:00:00 com o instante injetado.
$turn = qa_input([
    'pa_links' => [qa_pa(1, '2026-10-13', null), qa_pa(2, '2026-10-14', null)],
    'rules' => [qa_rule(1, '2026-10-13', null)],
    'exceptions' => [qa_exception(1, '2026-10-13'), qa_exception(2, '2026-10-14')],
    'absences' => [qa_absence(1, '2026-10-13', '2026-10-13', 'from_time'), qa_absence(2, '2026-10-12', '2026-10-14'), qa_absence(3, '2026-10-14', '2026-10-14')],
]);
$before = EmployeeArchivePlanner::plan($turn, qa_now('2026-10-13 23:59:59'));
$after = EmployeeArchivePlanner::plan($turn, qa_now('2026-10-14 00:00:00'));
assert_same(['2026-10-13', '2026-10-14'], [$before['today'], $after['today']], 'virada: hoje muda a meia-noite');
assert_same(['2026-10-13 23:59:59', '2026-10-14 00:00:00'], [$before['employee']['arquivado_em'], $after['employee']['arquivado_em']], 'virada: arquivado_em e o instante');
assert_same([[], [1, 2]], [qa_ids($before['pa']['close']), qa_ids($before['pa']['remove'])], 'virada 23:59: PA de hoje e de amanha saem');
assert_same([[['id' => 1, 'valid_until' => '2026-10-13', 'previous_until' => null]], [2]], [$after['pa']['close'], qa_ids($after['pa']['remove'])], 'virada 00:00: PA de ontem fecha ontem; o de hoje sai');
assert_same([null, [1]], [$before['rules']['close'], qa_ids($before['rules']['delete'])], 'virada 23:59: regra de hoje apagada');
assert_same([['id' => 1, 'effective_until' => '2026-10-13', 'previous_until' => null], []], [$after['rules']['close'], $after['rules']['delete']], 'virada 00:00: a mesma regra fecha ontem');
assert_same([[1, 2], [2]], [qa_ids($before['exceptions']['delete']), qa_ids($after['exceptions']['delete'])], 'virada: excecao de ontem fica');
assert_same([[1, 3], [['id' => 2, 'ends_on' => '2026-10-12', 'previous_ends_on' => '2026-10-14']]], [qa_ids($before['absences']['cancel']), $before['absences']['cut']], 'virada 23:59: parcial de hoje cancelada; em curso corta em 12');
assert_same([[3], [['id' => 2, 'ends_on' => '2026-10-13', 'previous_ends_on' => '2026-10-14']]], [qa_ids($after['absences']['cancel']), $after['absences']['cut']], 'virada 00:00: parcial de ontem fica; em curso corta em 13; a de hoje cancelada');
qa_assert_valid_periods($before, $turn, 'virada 23:59');
qa_assert_valid_periods($after, $turn, 'virada 00:00');

// Invariante em varredura: inicio e fim de -3 a +3 dias de hoje (fim aberto
// tambem), PA, regra e ausencia; nenhum plano grava periodo invertido.
$base = '2026-10-13';
$sweep = 0;
for ($from = -3; $from <= 3; $from++) {
    foreach (array_merge(range($from, 3), [null]) as $until) {
        $fromDate = ScheduleRulePlanner::addDays($base, $from);
        $untilDate = $until === null ? null : ScheduleRulePlanner::addDays($base, $until);
        $input = qa_input([
            'pa_links' => [qa_pa(1, $fromDate, $untilDate)],
            'rules' => [qa_rule(1, $fromDate, $untilDate)],
            'absences' => $untilDate === null ? [] : [qa_absence(1, $fromDate, $untilDate)],
        ]);
        foreach (['2026-10-13 00:00:00', '2026-10-13 12:00:00', '2026-10-13 23:59:59'] as $instant) {
            $plan = EmployeeArchivePlanner::plan($input, qa_now($instant));
            qa_assert_valid_periods($plan, $input, "varredura {$from}/" . var_export($until, true) . " {$instant}");
            foreach ($plan['pa']['close'] as $close) {
                assert_same(true, $close['valid_until'] < $base && $fromDate < $base, 'varredura: PA so fecha se comecou antes de hoje, e fecha antes de hoje');
            }
            foreach ($plan['absences']['cut'] as $cut) {
                assert_same(true, $cut['ends_on'] === '2026-10-12' && $fromDate < $base, 'varredura: ausencia so e cortada se comecou antes de hoje');
            }
            $sweep++;
        }
    }
}
// Ausencias parciais (um dia) de -3 a +3: canceladas a partir de hoje, nunca cortadas.
for ($day = -3; $day <= 3; $day++) {
    $date = ScheduleRulePlanner::addDays($base, $day);
    $plan = EmployeeArchivePlanner::plan(qa_input(['absences' => [qa_absence(1, $date, $date, 'from_time'), qa_absence(2, $date, $date, 'interval')]]), $now);
    assert_same([$day >= 0 ? [1, 2] : [], []], [qa_ids($plan['absences']['cancel']), $plan['absences']['cut']], "parciais em {$date}: canceladas a partir de hoje, nunca cortadas");
    $sweep++;
}
assert_same(true, $sweep >= 100, "varredura com {$sweep} planos");

// A invariante reprova plano forjado (o que a checagem final do plan() faz).
$forged = ['pa' => ['close' => [['id' => 1, 'valid_until' => '2026-10-12']], 'remove' => []], 'rules' => ['close' => ['id' => 1, 'effective_until' => '2026-10-12']], 'absences' => ['cut' => [['id' => 1, 'ends_on' => '2026-10-12']], 'cancel' => []]];
assert_same(3, count(EmployeeArchivePlanner::invertedPeriods($forged, [1 => ['valid_from' => '2026-10-13']], [1 => ['effective_from' => '2026-10-13']], [1 => ['starts_on' => '2026-10-13']])), 'invariante acusa PA, regra e ausencia invertidas');
assert_same([], EmployeeArchivePlanner::invertedPeriods($forged, [1 => ['valid_from' => '2026-10-12']], [1 => ['effective_from' => '2026-10-12']], [1 => ['starts_on' => '2026-10-12']]), 'invariante aceita fim igual ao inicio');
assert_same(3, count(EmployeeArchivePlanner::invertedPeriods($forged, [], [], [])), 'invariante acusa linha do plano sem a linha de origem');

// Contagens com tudo junto.
$plan = EmployeeArchivePlanner::plan(qa_input([
    'pa_links' => [qa_pa(1, '2026-10-01', null), qa_pa(2, '2026-10-20', null)],
    'rules' => [qa_rule(1, '2026-09-01', null), qa_rule(2, '2026-11-01', null)],
    'exceptions' => [qa_exception(1, '2026-10-14')],
    'absences' => [qa_absence(1, '2026-10-10', '2026-10-15'), qa_absence(2, '2026-10-20', '2026-10-21')],
    'pause' => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:50:00-03:00', 'motivo_pausa' => 'Pessoal', 'status_aprovacao' => 'aprovado'],
]), $now);
assert_same([
    'pa_encerrados' => 1, 'pa_removidos' => 1, 'regras_encerradas' => 1, 'regras_removidas' => 1,
    'excecoes_removidas' => 1, 'ausencias_canceladas' => 1, 'ausencias_cortadas' => 1,
    'pausa_finalizada' => true, 'solicitacao_descartada' => false,
], $plan['efeitos'], 'efeitos: uma contagem por efeito');
$plan = EmployeeArchivePlanner::plan(qa_input(), $now);
assert_same([0, 0, 0, 0, 0, 0, 0, false, false], array_values($plan['efeitos']), 'sem nada a fazer: efeitos zerados (o cadastro ainda e arquivado)');

// Dado fora do formato: UnexpectedValueException antes de qualquer gravacao (nunca 400 nem 409).
foreach ([
    'cadastro ausente' => qa_input(['employee' => null]),
    'id do cadastro zero' => qa_input(['employee' => ['id' => 0, 'ativo' => 1]]),
    'id em texto com zero a esquerda' => qa_input(['employee' => ['id' => '010', 'ativo' => 1]]),
    'data inexistente' => qa_input(['absences' => [qa_absence(1, '2026-02-30', '2026-03-01')]]),
    'data com hora' => qa_input(['pa_links' => [qa_pa(1, '2026-10-01 00:00:00', null)]]),
    'linha de outro colaborador' => qa_input(['exceptions' => [['id' => 1, 'employee_id' => 11, 'exception_date' => '2026-10-14', 'exception_type' => 'remote']]]),
    'linha sem colaborador' => qa_input(['pa_links' => [['id' => 1, 'valid_from' => '2026-10-01', 'valid_until' => null, 'active' => 1]]]),
    'linha sem id' => qa_input(['absences' => [['employee_id' => QA_EMPLOYEE, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'status' => 'approved']]]),
    'linhas como mapa' => qa_input(['rules' => ['a' => qa_rule(1, '2026-09-01', null)]]),
    'regra sem fim de vigencia' => qa_input(['rules' => [['id' => 1, 'employee_id' => QA_EMPLOYEE, 'rule_type' => 'fixed_weekdays', 'rule_config' => null, 'effective_from' => '2026-09-01']]]),
    'linha que nao e lista' => qa_input(['absences' => ['texto']]),
    'linha repetida' => qa_input(['pa_links' => [qa_pa(1, '2026-10-01', null), qa_pa(1, '2026-10-20', null)]]),
] as $label => $input) {
    assert_same(UnexpectedValueException::class, qa_plan_outcome($input, $now)[0], "dado fora do formato ({$label})");
}
assert_same('ok', qa_plan_outcome(qa_input(['employee' => ['id' => '10', 'ativo' => '1', 'arquivado_em' => null]]), $now)[0], 'id e ativo em texto, como vem do PDO');

// Funcao pura: sem banco, arquivo, relogio nem entrada externa.
$plannerSource = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string)file_get_contents($root . '/services/EmployeeArchivePlanner.php'));
// Chamada de funcao global (nao metodo self::/->, nem a declaracao "function date(").
$impure = '/\b(PDO|carregar_estado|ler_estado_pausas|get_db_connection)\b'
    . '|(?<!function )(?<![:>\w$])(time|microtime|strtotime|mt_rand|random_int|hrtime|sleep|date|fopen|file_get_contents|file_put_contents|getenv)\s*\('
    . '|\$_(GET|POST|SERVER|SESSION|COOKIE|ENV)\b|new DateTime(Immutable)?\s*\(/';
assert_same(0, preg_match($impure, $plannerSource, $impureFound), 'planejador nao le banco, arquivo, relogio nem entrada externa' . ($impureFound ? ': ' . $impureFound[0] : ''));
foreach (['time()', "date('Y')", "new DateTimeImmutable('now')", "getenv('X')", "\$_SERVER['x']", 'get_db_connection()', 'x = strtotime ($a)'] as $probe) {
    assert_same(1, preg_match($impure, $probe), "a checagem de pureza pega {$probe}");
}
foreach (['self::date($v)', 'private static function date(mixed $value', '$now->format(\'Y\')'] as $probe) {
    assert_same(0, preg_match($impure, $probe), "a checagem de pureza aceita {$probe}");
}
preg_match_all('/createFromFormat\(([^,]+),/', $plannerSource, $formats);
assert_same(true, count($formats[1]) >= 3, 'planejador le datas so por formato explicito');
foreach ($formats[1] as $format) {
    assert_same(true, in_array(trim($format), ["'!Y-m-d'", "'!Y-m-d H:i:s'", 'DATE_ATOM'], true), "createFromFormat({$format}) sem preencher campo com o relogio");
}
assert_same(true, strpos($plannerSource, 'ScheduleRulePlanner::planRemove($rules, $today)') !== false, 'regras pelo planRemove do F1, sem regra duplicada');
$invariantAt = strpos($plannerSource, 'self::assertValidPeriods($plan,');
assert_same(true, $invariantAt !== false && $invariantAt < (int)strpos($plannerSource, 'return $plan;'), 'invariante conferida antes de devolver o plano');

// Runners.
foreach (['scripts/test-linux.sh', 'scripts/test-local.ps1'] as $runner) {
    assert_same(true, strpos((string)file_get_contents($root . '/' . $runner), 'qa-archive.php') !== false, "{$runner} roda o qa-archive");
}

echo "QA archive OK ({$checks} verificacoes)\n";
