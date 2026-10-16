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
// 3. Servico (EmployeeArchiveService) com banco simulado com transacao e
//    rollback e o estado de pausas num diretorio temporario: instante no fuso
//    do PHP (UTC e outro fuso recusados), ordem estado.json -> banco e
//    leituras com FOR UPDATE, efeitos gravados, historico da pausa, estado
//    so com a entrada do colaborador, clear_invalid no log e na auditoria,
//    falhas no meio (nada gravado, estado intacto), falha do estado depois do
//    commit (aviso), permissoes (D1, L9, D3), restaurar (D12), httpError,
//    L-11 e endpoints (estrutura; ponta a ponta no qa-security).
// 4. L-07 (commit 4): obter_status() sem inativos (status.php e contadores do
//    dashboard.php), solicitacoes pendentes sem inativos e Mapa PA de hoje em
//    diante sem vinculo de inativo (D13; passado sem mudanca); SQLSTATE e
//    codigo do driver no error_log da PDOException, sem a mensagem.
//
// Nao acessa banco nem os arquivos de estado da aplicacao.
//
// Uso: php scripts/qa-archive.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
putenv('AD_DOMAIN=corp.local');
putenv('AD_UPN_SUFFIX=corp.local');
putenv('AD_ADMIN_USERS=qa.admin,qa.outro.admin');
$qaStateDir = sys_get_temp_dir() . '/chronodesk-qa-archive-' . bin2hex(random_bytes(6));
if (!mkdir($qaStateDir, 0700)) {
    fwrite(STDERR, "FALHOU: nao criou diretorio temporario do QA\n");
    exit(1);
}
$errorLogFile = $qaStateDir . '/php-error.log';
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($qaStateDir): void {
    foreach (glob($qaStateDir . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($qaStateDir);
});
define('ESTADO_JSON', $qaStateDir . '/estado.json');
define('PAUSAS_CSV', $qaStateDir . '/pausas.csv');
define('CONFIG_JSON', $qaStateDir . '/config_sistema.json');
define('FUNCIONARIOS_JSON', $qaStateDir . '/funcionarios.json');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.251';
define('CHRONODESK_STATELESS', true);

$GLOBALS['qa_pdo'] = null;
function get_db_connection() {
    if (!$GLOBALS['qa_pdo'] instanceof PDO) {
        throw new PDOException('SQLSTATE[HY000]: banco simulado ausente');
    }
    return $GLOBALS['qa_pdo'];
}

require_once dirname(__DIR__) . '/config.php';
ini_set('error_log', $errorLogFile);

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

// ----------------------------------------------------------------------------
// 3. Servico (EmployeeArchiveService)
// ----------------------------------------------------------------------------
require_once $root . '/services/EmployeeArchiveService.php';

/**
 * Banco simulado com as tabelas que o arquivamento le e grava. Transacao com
 * copia e rollback; registro de cada comando; falha injetada por trecho do
 * SQL ($failOn) e "nenhuma linha afetada" ($zeroRowsOn).
 */
final class QaArchivePdo extends PDO {
    public array $tables;
    public array $log = [];
    public ?string $failOn = null;
    public ?string $zeroRowsOn = null;
    /** Linha de regra que aparece na 2a leitura do historico (gravacao concorrente simulada). */
    public ?array $injectRuleOnSecondRead = null;
    public int $ruleReads = 0;
    private ?array $snapshot = null;

    public function __construct(array $tables) {
        $this->tables = $tables + [
            'funcionarios' => [], 'portal_schedule_rules' => [], 'portal_schedule_exceptions' => [],
            'portal_absences' => [], 'portal_pa_assignments' => [], 'pausas' => [], 'portal_sync_queue' => [], 'audit_log' => [],
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaArchiveStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (trim($query) === 'SELECT 1 FROM audit_log LIMIT 1') {
            $this->log[] = 'QUERY audit_log';
            return new QaArchiveStatement($this, 'SELECT 1');
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }

    public function beginTransaction(): bool {
        $this->snapshot = $this->tables;
        $this->log[] = 'BEGIN';
        return true;
    }

    public function commit(): bool {
        $this->snapshot = null;
        $this->log[] = 'COMMIT';
        return true;
    }

    public function rollBack(): bool {
        $this->tables = (array)$this->snapshot;
        $this->snapshot = null;
        $this->log[] = 'ROLLBACK';
        return true;
    }

    public function inTransaction(): bool {
        return $this->snapshot !== null;
    }

    /** Comandos SQL entre o BEGIN e o fim da transacao. */
    public function transactionStatements(): array {
        $inside = [];
        $open = false;
        foreach ($this->log as $entry) {
            if ($entry === 'BEGIN') {
                $open = true;
            } elseif (in_array($entry, ['COMMIT', 'ROLLBACK'], true)) {
                $open = false;
            } elseif ($open) {
                $inside[] = $entry;
            }
        }
        return $inside;
    }
}

final class QaArchiveStatement extends PDOStatement {
    private array $rows = [];
    private int $affected = 0;

    public function __construct(private QaArchivePdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $db = $this->pdo;
        $sql = $this->sql;
        $db->log[] = $sql;
        if ($db->failOn !== null && strpos($sql, $db->failOn) !== false) {
            throw new PDOException('SQLSTATE[HY000]: falha injetada no QA');
        }
        $this->rows = [];
        $this->affected = 0;
        $t = &$db->tables;
        $rulesRead = 'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id FOR UPDATE';
        if ($sql === $rulesRead && ++$db->ruleReads === 2 && $db->injectRuleOnSecondRead !== null) {
            $t['portal_schedule_rules'][(int)$db->injectRuleOnSecondRead['id']] = $db->injectRuleOnSecondRead;
        }
        $byEmployee = static fn (string $table): array => array_values(array_filter($t[$table], static fn (array $row): bool => (int)$row['employee_id'] === (int)$params[':employee_id']));
        $pick = static fn (array $row, array $columns): array => array_intersect_key($row, array_flip($columns));
        if ($sql === 'SELECT 1') {
            return true;
        }
        if ($sql === 'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)') {
            $t['audit_log'][] = ['action' => $params[2], 'details' => $params[3], 'severity' => $params[4]];
            return true;
        }
        if ($sql === 'SELECT id, nome, equipe, access_role, ad_login, ativo, arquivado_em FROM funcionarios WHERE id = :id FOR UPDATE') {
            $row = $t['funcionarios'][(int)$params[':id']] ?? null;
            $this->rows = $row ? [$pick($row, ['id', 'nome', 'equipe', 'access_role', 'ad_login', 'ativo', 'arquivado_em'])] : [];
            return true;
        }
        if ($sql === 'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id FOR UPDATE') {
            $this->rows = array_map(static fn (array $row): array => $pick($row, ['id', 'employee_id', 'rule_type', 'rule_config', 'effective_from', 'effective_until']), $byEmployee('portal_schedule_rules'));
            return true;
        }
        if ($sql === 'SELECT id, employee_id, exception_date, exception_type FROM portal_schedule_exceptions WHERE employee_id = :employee_id AND exception_date >= :today ORDER BY exception_date, id FOR UPDATE') {
            foreach ($byEmployee('portal_schedule_exceptions') as $row) {
                if ($row['exception_date'] >= $params[':today']) {
                    $this->rows[] = $pick($row, ['id', 'employee_id', 'exception_date', 'exception_type']);
                }
            }
            return true;
        }
        if ($sql === 'SELECT id, employee_id, starts_on, ends_on, period_type, status FROM portal_absences WHERE employee_id = :employee_id AND status = :status AND ends_on >= :today ORDER BY starts_on, id FOR UPDATE') {
            foreach ($byEmployee('portal_absences') as $row) {
                if ($row['status'] === $params[':status'] && $row['ends_on'] >= $params[':today']) {
                    $this->rows[] = $pick($row, ['id', 'employee_id', 'starts_on', 'ends_on', 'period_type', 'status']);
                }
            }
            return true;
        }
        if ($sql === 'SELECT id, employee_id, valid_from, valid_until, active FROM portal_pa_assignments WHERE employee_id = :employee_id AND active = 1 AND (valid_until IS NULL OR valid_until >= :today) ORDER BY valid_from, id FOR UPDATE') {
            foreach ($byEmployee('portal_pa_assignments') as $row) {
                if ((int)$row['active'] === 1 && ($row['valid_until'] === null || $row['valid_until'] >= $params[':today'])) {
                    $this->rows[] = $pick($row, ['id', 'employee_id', 'valid_from', 'valid_until', 'active']);
                }
            }
            return true;
        }
        if ($sql === 'SELECT id FROM funcionarios WHERE id <> :id AND ativo = 1 AND ad_login = :ad_login LIMIT 1 FOR UPDATE') {
            foreach ($t['funcionarios'] as $id => $row) {
                if ($id !== (int)$params[':id'] && (int)$row['ativo'] === 1 && strcasecmp((string)$row['ad_login'], (string)$params[':ad_login']) === 0) {
                    $this->rows[] = ['id' => $id];
                }
            }
            return true;
        }
        $update = function (string $table, int $id, callable $matches, array $changes) use (&$t, $db, $sql): void {
            if ($db->zeroRowsOn !== null && strpos($sql, $db->zeroRowsOn) !== false) {
                return;
            }
            if (isset($t[$table][$id]) && $matches($t[$table][$id])) {
                $t[$table][$id] = $changes + $t[$table][$id];
                $this->affected = 1;
            }
        };
        $own = static fn (array $row): bool => (int)$row['employee_id'] === (int)$params[':employee_id'];
        switch ($sql) {
            case 'UPDATE portal_pa_assignments SET valid_until = :valid_until, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id AND active = 1':
                $update('portal_pa_assignments', (int)$params[':id'], static fn (array $row): bool => $own($row) && (int)$row['active'] === 1, ['valid_until' => $params[':valid_until'], 'updated_by' => $params[':updated_by']]);
                return true;
            case 'UPDATE portal_pa_assignments SET active = 0, deleted_at = :deleted_at, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id AND active = 1':
                $update('portal_pa_assignments', (int)$params[':id'], static fn (array $row): bool => $own($row) && (int)$row['active'] === 1, ['active' => 0, 'deleted_at' => $params[':deleted_at'], 'updated_by' => $params[':updated_by']]);
                return true;
            case 'UPDATE portal_schedule_rules SET effective_until = :effective_until, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id':
                $update('portal_schedule_rules', (int)$params[':id'], $own, ['effective_until' => $params[':effective_until'], 'updated_by' => $params[':updated_by']]);
                return true;
            case 'UPDATE portal_absences SET status = :cancelled, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id AND status = :approved':
                $update('portal_absences', (int)$params[':id'], static fn (array $row): bool => $own($row) && $row['status'] === $params[':approved'], ['status' => $params[':cancelled'], 'cancelled_by' => $params[':cancelled_by'], 'cancelled_at' => $params[':cancelled_at'], 'updated_by' => $params[':updated_by']]);
                return true;
            case 'UPDATE portal_absences SET ends_on = :ends_on, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id AND status = :approved':
                $update('portal_absences', (int)$params[':id'], static fn (array $row): bool => $own($row) && $row['status'] === $params[':approved'], ['ends_on' => $params[':ends_on'], 'updated_by' => $params[':updated_by']]);
                return true;
            case 'UPDATE funcionarios SET ativo = 0, arquivado_em = :arquivado_em, arquivado_por = :arquivado_por WHERE id = :id':
                $update('funcionarios', (int)$params[':id'], static fn (array $row): bool => true, ['ativo' => 0, 'arquivado_em' => $params[':arquivado_em'], 'arquivado_por' => $params[':arquivado_por']]);
                return true;
            case 'UPDATE funcionarios SET ativo = 1, arquivado_em = NULL, arquivado_por = NULL WHERE id = :id AND ativo = 0':
                $update('funcionarios', (int)$params[':id'], static fn (array $row): bool => (int)$row['ativo'] === 0, ['ativo' => 1, 'arquivado_em' => null, 'arquivado_por' => null]);
                return true;
        }
        foreach (['DELETE FROM portal_schedule_rules WHERE id = :id AND employee_id = :employee_id' => 'portal_schedule_rules',
                  'DELETE FROM portal_schedule_exceptions WHERE id = :id AND employee_id = :employee_id' => 'portal_schedule_exceptions'] as $delete => $table) {
            if ($sql === $delete) {
                $row = $t[$table][(int)$params[':id']] ?? null;
                if ($row !== null && $own($row) && ($db->zeroRowsOn === null || strpos($sql, $db->zeroRowsOn) === false)) {
                    unset($t[$table][(int)$params[':id']]);
                    $this->affected = 1;
                }
                return true;
            }
        }
        if (strpos($sql, 'INSERT INTO pausas ( id_funcionario,') === 0) {
            $t['pausas'][] = $params;
            $this->affected = 1;
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_sync_queue (record_type, record_id, operation, status, payload_json, created_by)') === 0) {
            $t['portal_sync_queue'][] = $params;
            $this->affected = 1;
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    public function rowCount(): int {
        return $this->affected;
    }
}

const QA_SP = 'America/Sao_Paulo';

function qa_service_tables(): array {
    $employee = static fn (int $id, string $team, string $role, string $login, int $active = 1, ?string $archivedAt = null): array
        => ['id' => $id, 'nome' => "Ficticio {$id}", 'equipe' => $team, 'access_role' => $role, 'ad_login' => $login, 'ativo' => $active, 'arquivado_em' => $archivedAt, 'arquivado_por' => null];
    return [
        'funcionarios' => [
            10 => $employee(10, 'n1', 'tecnico', 'tecnico.dez'),
            11 => $employee(11, 'n2', 'tecnico', 'tecnico.onze', 0),
            12 => $employee(12, 'n1', 'tecnico', 'tecnico.doze', 0, '2026-10-01 09:00:00'),
            13 => $employee(13, 'lideranca', 'tecnico', 'tecnico.lideranca'),
            14 => $employee(14, 'lideranca', 'lideranca', 'qa.lider'),
            15 => $employee(15, 'n2', 'tecnico', 'qa.outro.admin'),
            16 => $employee(16, 'n1', 'tecnico', 'tecnico.doze'),
        ],
        'portal_schedule_rules' => [
            1 => ['id' => 1, 'employee_id' => 10, 'rule_type' => 'fixed_weekdays', 'rule_config' => '{"weekdays":[1,3]}', 'effective_from' => '2026-09-01', 'effective_until' => null, 'updated_by' => null],
            2 => ['id' => 2, 'employee_id' => 10, 'rule_type' => 'all_onsite', 'rule_config' => null, 'effective_from' => '2026-08-01', 'effective_until' => '2026-08-31', 'updated_by' => null],
            3 => ['id' => 3, 'employee_id' => 16, 'rule_type' => 'all_onsite', 'rule_config' => null, 'effective_from' => '2026-09-01', 'effective_until' => null, 'updated_by' => null],
        ],
        'portal_schedule_exceptions' => [
            1 => ['id' => 1, 'employee_id' => 10, 'exception_date' => '2026-10-12', 'exception_type' => 'remote', 'note' => 'texto livre'],
            2 => ['id' => 2, 'employee_id' => 10, 'exception_date' => '2026-10-14', 'exception_type' => 'training', 'note' => 'texto livre'],
        ],
        'portal_absences' => [
            1 => ['id' => 1, 'employee_id' => 10, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-15', 'period_type' => 'full_day', 'status' => 'approved', 'reason' => 'observacao', 'cancelled_by' => null, 'cancelled_at' => null, 'updated_by' => null],
            2 => ['id' => 2, 'employee_id' => 10, 'starts_on' => '2026-10-13', 'ends_on' => '2026-10-13', 'period_type' => 'from_time', 'status' => 'approved', 'reason' => 'observacao', 'cancelled_by' => null, 'cancelled_at' => null, 'updated_by' => null],
            3 => ['id' => 3, 'employee_id' => 10, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22', 'period_type' => 'full_day', 'status' => 'approved', 'reason' => 'observacao', 'cancelled_by' => null, 'cancelled_at' => null, 'updated_by' => null],
            4 => ['id' => 4, 'employee_id' => 10, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-06', 'period_type' => 'full_day', 'status' => 'approved', 'reason' => 'observacao', 'cancelled_by' => null, 'cancelled_at' => null, 'updated_by' => null],
        ],
        'portal_pa_assignments' => [
            1 => ['id' => 1, 'employee_id' => 10, 'valid_from' => '2026-09-01', 'valid_until' => null, 'active' => 1, 'deleted_at' => null, 'updated_by' => null],
            2 => ['id' => 2, 'employee_id' => 10, 'valid_from' => '2026-10-13', 'valid_until' => null, 'active' => 1, 'deleted_at' => null, 'updated_by' => null],
            3 => ['id' => 3, 'employee_id' => 10, 'valid_from' => '2026-08-01', 'valid_until' => '2026-08-31', 'active' => 1, 'deleted_at' => null, 'updated_by' => null],
        ],
    ];
}

/** Estado de pausas com uma entrada por colaborador, como o salvar_estado grava. */
function qa_write_state(array $entries): string {
    $state = ['funcionarios' => []];
    foreach ($entries as $id => $entry) {
        $state['funcionarios'][$id] = $entry + ['em_pausa' => false, 'inicio_pausa' => null, 'motivo_pausa' => null, 'status_aprovacao' => null, 'solicitacao_timestamp' => null, 'observacao_reuniao' => null];
    }
    $content = (string)json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents(ESTADO_JSON, $content);
    return $content;
}

function qa_service(QaArchivePdo $pdo, string $instant = '2026-10-13 10:00:00', string $zone = QA_SP, ?Closure $writeState = null): EmployeeArchiveService {
    $GLOBALS['qa_pdo'] = $pdo;
    return new EmployeeArchiveService($pdo, ESTADO_JSON, static fn (): DateTimeImmutable => new DateTimeImmutable($instant, new DateTimeZone($zone)), $writeState);
}

function qa_service_outcome(callable $call): array {
    try {
        return ['ok', $call()];
    } catch (Throwable $error) {
        return [get_class($error), $error->getMessage()];
    }
}

function qa_error_log(): string {
    global $errorLogFile;
    clearstatcache(true, $errorLogFile);
    return is_file($errorLogFile) ? (string)file_get_contents($errorLogFile) : '';
}

$admin = ['role' => 'admin', 'username' => 'qa.admin', 'session_employee_id' => null];
$leader = ['role' => 'lideranca', 'username' => 'qa.lider', 'session_employee_id' => 14];
$pauseEntry = ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:40:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado'];

// Instante: so no fuso do PHP. UTC e deslocamento fixo sao recusados antes de
// qualquer leitura (em UTC, "hoje" vira as 21h e o plano muda).
assert_same(QA_SP, date_default_timezone_get(), 'config.php fixa o fuso do PHP em America/Sao_Paulo (A4)');
assert_same(QA_SP, (new EmployeeArchiveService(new QaArchivePdo([]), ESTADO_JSON))->instant()->getTimezone()->getName(), 'instante padrao do servico no fuso do PHP');
foreach (['UTC' => 'UTC', 'deslocamento -03:00' => '-03:00', 'outro fuso' => 'America/Manaus'] as $label => $zone) {
    $pdo = new QaArchivePdo(qa_service_tables());
    $before = $pdo->tables;
    $stateBefore = qa_write_state([10 => $pauseEntry]);
    [$class, $message] = qa_service_outcome(static fn () => qa_service($pdo, '2026-10-14 00:30:00', $zone)->archive(10, $admin));
    assert_same(LogicException::class, $class, "instante em {$label}: recusado (LogicException)");
    assert_same(true, strpos($message, 'fora do fuso America/Sao_Paulo') !== false, "instante em {$label}: mensagem");
    assert_same([[], $before, $stateBefore], [$pdo->log, $pdo->tables, (string)file_get_contents(ESTADO_JSON)], "instante em {$label}: nenhuma leitura nem gravacao, estado intacto");
}
$serviceSource = (string)file_get_contents($root . '/services/EmployeeArchiveService.php');
$serviceCode = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $serviceSource);
assert_same(1, substr_count($serviceCode, "new DateTimeImmutable('now')"), 'servico cria o instante sem fuso explicito (o do PHP), num lugar so');
assert_same(0, preg_match("/new DateTime(Immutable)?\((?!'now'\))|(?<![:>\w$])(time|date|strtotime|microtime)\s*\(/", $serviceCode), 'servico sem outra leitura do relogio');
// As 21:30 locais (00:30 UTC do dia seguinte), "hoje" e o dia local.
$pdo = new QaArchivePdo(qa_service_tables());
qa_write_state([]);
$result = qa_service($pdo, '2026-10-13 21:30:00')->archive(10, $admin);
assert_same(['2026-10-13 21:30:00', '2026-10-12', 'cancelled'], [$pdo->tables['funcionarios'][10]['arquivado_em'], $pdo->tables['portal_absences'][1]['ends_on'], $pdo->tables['portal_absences'][2]['status']], 'as 21:30 locais: data e cortes pelo dia local, nao pelo de UTC');

// Arquivamento completo com pausa aprovada.
$pdo = new QaArchivePdo(qa_service_tables());
qa_write_state([10 => $pauseEntry + ['observacao_reuniao' => 'nota anterior'], 11 => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:55:00-03:00', 'motivo_pausa' => 'Pessoal', 'status_aprovacao' => 'aprovado']]);
$otherBefore = json_decode((string)file_get_contents(ESTADO_JSON), true)['funcionarios'][11];
$result = qa_service($pdo)->archive(10, $admin);
assert_same([
    'efeitos' => [
        'pa_encerrados' => 1, 'pa_removidos' => 1, 'regras_encerradas' => 1, 'regras_removidas' => 0,
        'excecoes_removidas' => 1, 'ausencias_canceladas' => 2, 'ausencias_cortadas' => 1,
        'pausa_finalizada' => true, 'solicitacao_descartada' => false,
    ],
    'regularizacao' => false,
    'avisos' => [],
], $result, 'arquivar: efeitos com as nove chaves, sem aviso');
$t = $pdo->tables;
assert_same(['ativo' => 0, 'arquivado_em' => '2026-10-13 10:00:00', 'arquivado_por' => 'qa.admin'], array_intersect_key($t['funcionarios'][10], array_flip(['ativo', 'arquivado_em', 'arquivado_por'])), 'cadastro: ativo 0, data e autor');
assert_same([['2026-10-12', 1], [null, 0], ['2026-08-31', 1]], [[$t['portal_pa_assignments'][1]['valid_until'], $t['portal_pa_assignments'][1]['active']], [$t['portal_pa_assignments'][2]['valid_until'], $t['portal_pa_assignments'][2]['active']], [$t['portal_pa_assignments'][3]['valid_until'], $t['portal_pa_assignments'][3]['active']]], 'PA: aberto fecha ontem, o de hoje sai, o encerrado fica');
assert_same('2026-10-13 10:00:00', $t['portal_pa_assignments'][2]['deleted_at'], 'PA removido com a data do instante (sem relogio do banco)');
assert_same(['2026-10-12', '2026-08-31', null], [$t['portal_schedule_rules'][1]['effective_until'], $t['portal_schedule_rules'][2]['effective_until'], $t['portal_schedule_rules'][3]['effective_until']], 'regras: vigente fecha ontem; encerrada e a de outro colaborador ficam');
assert_same([1], array_keys($t['portal_schedule_exceptions']), 'excecao de amanha apagada; a de ontem fica');
assert_same([['approved', '2026-10-12'], ['cancelled', '2026-10-13'], ['cancelled', '2026-10-22'], ['approved', '2026-10-06']], array_map(static fn (array $row): array => [$row['status'], $row['ends_on']], array_values($t['portal_absences'])), 'ausencias: em curso cortada em ontem; parcial de hoje e futura canceladas; passada fica');
assert_same(['qa.admin', '2026-10-13 10:00:00'], [$t['portal_absences'][2]['cancelled_by'], $t['portal_absences'][2]['cancelled_at']], 'ausencia cancelada com autor e a data do instante');
assert_same(1, count($t['pausas']), 'pausa: um registro no historico');
$pauseRow = $t['pausas'][0];
assert_same(
    [10, 'Ficticio 10', 'n1', '2026-10-13 09:40:00', '2026-10-13 10:00:00', 1200, 'Café', 1, 1, 'aprovado', 'nota anterior | ' . EmployeeArchiveService::PAUSE_NOTE . 'qa.admin'],
    [$pauseRow[':id_funcionario'], $pauseRow[':nome_funcionario'], $pauseRow[':equipe'], $pauseRow[':inicio_pausa'], $pauseRow[':fim_pausa'], $pauseRow[':duracao_segundos'], $pauseRow[':motivo_pausa'], $pauseRow[':alerta_15min'], $pauseRow[':alerta_20min'], $pauseRow[':status_aprovacao'], $pauseRow[':observacao_reuniao']],
    'pausa: historico com origem admin_archive no texto, alertas de 15 e 20 min pelo motivo'
);
assert_same(1, count($t['portal_sync_queue']), 'regra encerrada vai para a fila de sincronizacao, como no F1');
$state = json_decode((string)file_get_contents(ESTADO_JSON), true);
assert_same(['em_pausa' => false, 'inicio_pausa' => null, 'motivo_pausa' => null, 'status_aprovacao' => null, 'solicitacao_timestamp' => null, 'observacao_reuniao' => null], $state['funcionarios'][10], 'estado: entrada do arquivado zerada');
assert_same($otherBefore, $state['funcionarios'][11], 'estado: as outras entradas ficam como foram lidas');
$audits = array_values(array_filter($t['audit_log'], static fn (array $row): bool => $row['action'] === 'FUNCIONARIO_ARQUIVADO'));
assert_same(1, count($audits), 'auditoria: um FUNCIONARIO_ARQUIVADO');
assert_same('CRITICAL', $audits[0]['severity'], 'auditoria: CRITICAL');
assert_same(
    'funcionario_id=10; perfil=tecnico; equipe=n1; ator=admin; regularizacao=nao; pa_encerrados=1; pa_removidos=2; regra_encerrada=1 ate 2026-10-12; regras_apagadas=nenhuma; excecoes_apagadas=2:training 2026-10-14; ausencias_canceladas=2,3; ausencias_cortadas=1 ate 2026-10-12 (antes 2026-10-15); pausa=finalizada; allowlist=nao',
    $audits[0]['details'],
    'auditoria: ids, datas, tipos e contagens'
);
assert_same(0, preg_match('/Ficticio|tecnico\.dez|Caf|nota anterior|texto livre|observacao/', $audits[0]['details']), 'auditoria: sem nome, login, motivo nem observacao');

// Leituras: estado.json antes do banco; dentro da transacao, toda leitura com FOR UPDATE e o cadastro primeiro.
$inside = $pdo->transactionStatements();
$selects = array_values(array_filter($inside, static fn (string $sql): bool => strpos($sql, 'SELECT') === 0 && $sql !== 'SELECT 1'));
assert_same(true, count($selects) >= 5, 'transacao: leituras do cadastro, regras, excecoes, ausencias e PA');
assert_same([], array_values(array_filter($selects, static fn (string $sql): bool => substr($sql, -10) !== 'FOR UPDATE')), 'transacao: toda leitura com FOR UPDATE (licao do F1)');
assert_same(0, strpos($inside[0], 'SELECT id, nome, equipe, access_role, ad_login, ativo, arquivado_em FROM funcionarios WHERE id = :id FOR UPDATE'), 'transacao: o cadastro e travado primeiro');
assert_same(['BEGIN', 'COMMIT'], array_values(array_filter($pdo->log, static fn (string $entry): bool => in_array($entry, ['BEGIN', 'COMMIT', 'ROLLBACK'], true))), 'uma transacao, com commit');
$archiveBody = substr($serviceSource, (int)strpos($serviceSource, 'public function archive('), (int)strpos($serviceSource, 'public function restore(') - (int)strpos($serviceSource, 'public function archive('));
$lockAt = strpos($archiveBody, 'with_pause_state_lock(function ()');
$stateAt = strpos($archiveBody, 'ler_estado_pausas($this->statePath)');
$beginAt = strpos($archiveBody, '$this->pdo->beginTransaction()');
$commitAt = strpos($archiveBody, '$this->pdo->commit()');
$writeAt = strpos($archiveBody, '$this->clearStateEntry($state, $employeeId)');
assert_same(true, $lockAt !== false && $lockAt < $stateAt && $stateAt < $beginAt && $beginAt < $commitAt && $commitAt < $writeAt && strpos($archiveBody, '}, $this->statePath);') > $writeAt, 'ordem: lock, estado.json, transacao, commit, gravacao do estado ainda dentro do lock');
assert_same(true, strpos($archiveBody, '$now = $this->instant();') < $lockAt, 'instante conferido antes do lock e do banco');

// Historico antigo com sobreposicao (dado anterior ao F1) nao bloqueia: so violacao nova derruba.
$tables = qa_service_tables();
$tables['portal_schedule_rules'][4] = ['id' => 4, 'employee_id' => 10, 'rule_type' => 'all_remote', 'rule_config' => null, 'effective_from' => '2026-08-15', 'effective_until' => '2026-08-20', 'updated_by' => null];
$pdo = new QaArchivePdo($tables);
qa_write_state([]);
assert_same('ok', qa_service_outcome(static fn () => qa_service($pdo)->archive(10, $admin))[0], 'historico antigo com sobreposicao: arquiva (so violacao nova derruba)');

// Alertas do historico pelo motivo e pelo limiar (mesma regra do GerenciadorPausas).
foreach ([
    'Pessoal de 30 min' => [['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:30:00-03:00', 'motivo_pausa' => 'Pessoal', 'status_aprovacao' => 'aprovado'], [1800, 0, 0]],
    'Café de 16 min' => [['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:44:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado'], [960, 1, 0]],
    'Café de 14 min' => [['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:46:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado'], [840, 0, 0]],
] as $label => [$entry, $expected]) {
    $pdo = new QaArchivePdo(qa_service_tables());
    qa_write_state([10 => $entry]);
    qa_service($pdo)->archive(10, $admin);
    $row = $pdo->tables['pausas'][0];
    assert_same($expected, [$row[':duracao_segundos'], $row[':alerta_15min'], $row[':alerta_20min']], "historico da pausa ({$label}): duracao e alertas");
}

// Violacao NOVA no historico de escala depois de gravar derruba tudo.
$pdo = new QaArchivePdo(qa_service_tables());
$pdo->injectRuleOnSecondRead = ['id' => 9, 'employee_id' => 10, 'rule_type' => 'all_remote', 'rule_config' => null, 'effective_from' => '2026-10-01', 'effective_until' => '2026-10-05', 'updated_by' => null];
$before = $pdo->tables;
$stateBefore = qa_write_state([10 => $pauseEntry]);
[$class, $message] = qa_service_outcome(static fn () => qa_service($pdo)->archive(10, $admin));
assert_same([RuntimeException::class, true], [$class, strpos($message, 'Historico de escala inconsistente') === 0], 'violacao nova no historico de escala: RuntimeException');
unset($pdo->tables['portal_schedule_rules'][9]);
assert_same([$before, $stateBefore, 'ROLLBACK'], [$pdo->tables, (string)file_get_contents(ESTADO_JSON), end($pdo->log)], 'violacao nova: rollback, nada gravado, estado intacto');

// Solicitacao pendente: descartada, sem historico.
$pdo = new QaArchivePdo(qa_service_tables());
qa_write_state([10 => ['em_pausa' => false, 'motivo_pausa' => 'Reunião', 'status_aprovacao' => 'pendente', 'solicitacao_timestamp' => '2026-10-13T09:50:00-03:00', 'observacao_reuniao' => 'pauta']]);
$result = qa_service($pdo)->archive(10, $admin);
assert_same([false, true, []], [$result['efeitos']['pausa_finalizada'], $result['efeitos']['solicitacao_descartada'], $pdo->tables['pausas']], 'pendente: descartada, sem historico');
assert_same(false, json_decode((string)file_get_contents(ESTADO_JSON), true)['funcionarios'][10]['status_aprovacao'] !== null, 'pendente: estado zerado');

// clear_invalid: estado zerado sem historico, no error_log e na auditoria (so ids e a acao).
$pdo = new QaArchivePdo(qa_service_tables());
qa_write_state([10 => ['em_pausa' => true, 'inicio_pausa' => 'now', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado', 'observacao_reuniao' => 'texto qualquer']]);
$logBefore = strlen(qa_error_log());
$result = qa_service($pdo)->archive(10, $admin);
$logAfter = substr(qa_error_log(), $logBefore);
assert_same([false, false, []], [$result['efeitos']['pausa_finalizada'], $result['efeitos']['solicitacao_descartada'], $pdo->tables['pausas']], 'clear_invalid: sem historico e fora dos efeitos');
assert_same(false, json_decode((string)file_get_contents(ESTADO_JSON), true)['funcionarios'][10]['em_pausa'], 'clear_invalid: estado zerado');
assert_same(true, strpos($logAfter, '[ARCHIVE] Entrada ilegivel do estado de pausas limpa sem historico: funcionario ID 10.') !== false, 'clear_invalid: registrado no error_log');
assert_same(0, preg_match('/Caf|texto qualquer/', $logAfter), 'clear_invalid: log sem motivo nem observacao');
$audit = array_values(array_filter($pdo->tables['audit_log'], static fn (array $row): bool => $row['action'] === 'FUNCIONARIO_ARQUIVADO'))[0]['details'];
assert_same(true, strpos($audit, 'pausa=limpa_sem_historico') !== false && preg_match('/Caf|texto qualquer/', $audit) === 0, 'clear_invalid: na auditoria, sem motivo nem observacao');

// Sem pausa: o estado nao e regravado; sem arquivo de estado, nenhum e criado.
$pdo = new QaArchivePdo(qa_service_tables());
$content = qa_write_state([10 => [], 11 => $pauseEntry]);
touch(ESTADO_JSON, 1700000000);
clearstatcache(true, ESTADO_JSON);
qa_service($pdo)->archive(10, $admin);
clearstatcache(true, ESTADO_JSON);
assert_same([$content, 1700000000], [(string)file_get_contents(ESTADO_JSON), filemtime(ESTADO_JSON)], 'sem pausa: estado.json nao e regravado');
@unlink(ESTADO_JSON);
$pdo = new QaArchivePdo(qa_service_tables());
assert_same('ok', qa_service_outcome(static fn () => qa_service($pdo)->archive(10, $admin))[0], 'sem estado.json: arquiva');
assert_same(false, is_file(ESTADO_JSON), 'sem estado.json: nenhum arquivo criado');

// Falha no meio: nada gravado (rollback) e estado intacto. httpError por tipo.
foreach ([
    'historico da pausa (banco)' => ['failOn' => 'INSERT INTO pausas', 'class' => PDOException::class, 'status' => 503],
    'cadastro, ultimo passo (banco)' => ['failOn' => 'UPDATE funcionarios SET ativo = 0', 'class' => PDOException::class, 'status' => 503],
    'fila de sincronizacao (banco)' => ['failOn' => 'INSERT INTO portal_sync_queue', 'class' => PDOException::class, 'status' => 503],
    'linha mudou (0 afetadas)' => ['zeroRowsOn' => 'UPDATE portal_absences SET ends_on', 'class' => RuntimeException::class, 'status' => 500],
] as $label => $case) {
    $pdo = new QaArchivePdo(qa_service_tables());
    $pdo->failOn = $case['failOn'] ?? null;
    $pdo->zeroRowsOn = $case['zeroRowsOn'] ?? null;
    $before = $pdo->tables;
    $stateBefore = qa_write_state([10 => $pauseEntry]);
    [$class] = qa_service_outcome(static fn () => qa_service($pdo)->archive(10, $admin));
    assert_same($case['class'], $class, "falha em {$label}: excecao");
    assert_same([$before, $stateBefore], [$pdo->tables, (string)file_get_contents(ESTADO_JSON)], "falha em {$label}: nada gravado (rollback) e estado intacto");
    assert_same('ROLLBACK', end($pdo->log), "falha em {$label}: rollback");
}
// Dado fora do formato e instante fora do fuso: 500 generico, error_log com classe e mensagem, nada gravado.
$tables = qa_service_tables();
$tables['portal_pa_assignments'][4] = ['id' => 4, 'employee_id' => 10, 'valid_from' => '2026-02-30', 'valid_until' => null, 'active' => 1, 'deleted_at' => null, 'updated_by' => null];
$pdo = new QaArchivePdo($tables);
$before = $pdo->tables;
$stateBefore = qa_write_state([10 => $pauseEntry]);
$error = null;
try {
    qa_service($pdo)->archive(10, $admin);
} catch (Throwable $caught) {
    $error = $caught;
}
assert_same(UnexpectedValueException::class, $error === null ? null : get_class($error), 'dado fora do formato: UnexpectedValueException do planejador');
assert_same([$before, $stateBefore, 'ROLLBACK'], [$pdo->tables, (string)file_get_contents(ESTADO_JSON), end($pdo->log)], 'dado fora do formato: rollback, nada gravado, estado intacto');
$logBefore = strlen(qa_error_log());
assert_same([500, EmployeeArchiveService::GENERIC_ERROR], EmployeeArchiveService::httpError($error), 'dado fora do formato: 500 generico');
assert_same(true, strpos(substr(qa_error_log(), $logBefore), '[ARCHIVE] UnexpectedValueException: Data invalida no plano de arquivamento (pa_links.valid_from).') !== false, 'dado fora do formato: error_log com classe e mensagem');
$logBefore = strlen(qa_error_log());
assert_same([500, EmployeeArchiveService::GENERIC_ERROR], EmployeeArchiveService::httpError(new LogicException('Plano de arquivamento com periodo invertido: x')), 'LogicException: 500 generico');
assert_same(true, strpos(substr(qa_error_log(), $logBefore), '[ARCHIVE] LogicException: Plano de arquivamento com periodo invertido: x') !== false, 'LogicException: error_log com classe e mensagem');
assert_same([409, 'Ja arquivado'], EmployeeArchiveService::httpError(new DomainException('Ja arquivado')), 'DomainException: 409 com a mensagem');
assert_same([403, 'Negado'], EmployeeArchiveService::httpError(new PortalForbiddenException('Negado')), 'PortalForbiddenException: 403');
assert_same([404, 'X'], EmployeeArchiveService::httpError(new EmployeeArchiveNotFoundException('X')), 'cadastro inexistente: 404');
assert_same([503, DATABASE_UNAVAILABLE_MESSAGE], EmployeeArchiveService::httpError(new PDOException('SQLSTATE[HY000]: x')), 'banco: 503');
assert_same(false, strpos(EmployeeArchiveService::httpError(new RuntimeException('detalhe interno'))[1], 'detalhe interno') !== false, 'erro inesperado: detalhe nunca vai ao usuario');

// Falha so na gravacao do estado, depois do commit: arquivado, com aviso.
$pdo = new QaArchivePdo(qa_service_tables());
$stateBefore = qa_write_state([10 => $pauseEntry]);
$logBefore = strlen(qa_error_log());
$result = qa_service($pdo, '2026-10-13 10:00:00', QA_SP, static fn (string $path, string $content): bool => false)->archive(10, $admin);
assert_same([[EmployeeArchiveService::STATE_WARNING], 0, 1], [$result['avisos'], $pdo->tables['funcionarios'][10]['ativo'], count($pdo->tables['pausas'])], 'estado nao gravado depois do commit: arquivado, historico gravado, aviso');
assert_same([$stateBefore, true], [(string)file_get_contents(ESTADO_JSON), strpos(substr(qa_error_log(), $logBefore), '[ARCHIVE] Estado de pausas nao gravado depois do arquivamento do funcionario ID 10.') !== false], 'estado nao gravado: arquivo como estava e registro no error_log');

// Permissoes (D1, L9), proprio cadastro (D3), arquivado e inexistente: nada gravado.
foreach ([
    'gestor' => [10, ['role' => 'gestor', 'username' => 'qa.gestor', 'session_employee_id' => 3], PortalForbiddenException::class, 'Seu perfil não possui permissão para gerenciar funcionários.'],
    'Liderança em cadastro de Liderança' => [14, ['role' => 'lideranca', 'username' => 'qa.outra', 'session_employee_id' => 20], PortalForbiddenException::class, 'A Liderança não altera cadastros de Liderança ou de administradores.'],
    'Liderança em técnico da equipe Liderança (L9)' => [13, $leader, PortalForbiddenException::class, EmployeeArchiveService::LEADERSHIP_TEAM_MESSAGE],
    'próprio cadastro pela sessão CI' => [10, ['role' => 'admin', 'username' => 'qa.admin', 'session_employee_id' => 10], DomainException::class, EmployeeArchiveService::SELF_ARCHIVE_MESSAGE],
    'próprio cadastro pelo login' => [15, ['role' => 'admin', 'username' => 'QA.Outro.Admin', 'session_employee_id' => null], DomainException::class, EmployeeArchiveService::SELF_ARCHIVE_MESSAGE],
    'arquivado pelo F4' => [12, $admin, DomainException::class, 'Este cadastro já foi arquivado em 01/10/2026. Restaure-o antes de arquivar de novo.'],
    'inexistente' => [99, $admin, EmployeeArchiveNotFoundException::class, EmployeeArchiveService::NOT_FOUND_MESSAGE],
] as $label => [$id, $actor, $class, $message]) {
    $pdo = new QaArchivePdo(qa_service_tables());
    $before = $pdo->tables;
    $stateBefore = qa_write_state([$id => $pauseEntry]);
    assert_same([$class, $message], qa_service_outcome(static fn () => qa_service($pdo)->archive($id, $actor)), "arquivar: {$label}");
    assert_same([$before, $stateBefore], [$pdo->tables, (string)file_get_contents(ESTADO_JSON)], "arquivar: {$label}: nada gravado, estado intacto");
}
assert_same('ok', qa_service_outcome(static fn () => qa_service(new QaArchivePdo(qa_service_tables()))->archive(13, $admin))[0], 'admin arquiva tecnico da equipe Lideranca');
assert_same('ok', qa_service_outcome(static fn () => qa_service(new QaArchivePdo(qa_service_tables()))->archive(10, $leader))[0], 'Lideranca arquiva tecnico de N1 (regra do 5b)');
// Regularizacao (D5) e aviso da allowlist (D3).
$pdo = new QaArchivePdo(qa_service_tables());
qa_write_state([]);
$result = qa_service($pdo)->archive(11, $admin);
assert_same([true, '2026-10-13 10:00:00'], [$result['regularizacao'], $pdo->tables['funcionarios'][11]['arquivado_em']], 'inativo antigo: regularizado com a data (D5)');
$pdo = new QaArchivePdo(qa_service_tables());
$result = qa_service($pdo)->archive(15, $admin);
assert_same([EmployeeArchiveService::ALLOWLIST_WARNING], $result['avisos'], 'login na allowlist: aviso (D3)');
assert_same(true, strpos(end($pdo->tables['audit_log'])['details'], 'allowlist=sim') !== false, 'login na allowlist: na auditoria');
assert_same(['role' => 'lideranca', 'username' => 'qa.lider', 'session_employee_id' => 14], EmployeeArchiveService::sessionActor('lideranca', ['ci_funcionario_id' => 14, 'admin_username' => 'qa.lider', 'ci_username' => 'outro']), 'ator da sessao: usuario de gestao e colaborador da sessao CI');
assert_same(['role' => 'admin', 'username' => 'qa.admin', 'session_employee_id' => null], EmployeeArchiveService::sessionActor('admin', ['admin_username' => 'qa.admin']), 'ator da sessao: admin pela allowlist sem colaborador');

// Restaurar (D12). O cadastro 16 (ativo, mesmo login do 12) so entra no caso do conflito.
$tables = qa_service_tables();
unset($tables['funcionarios'][16]);
$pdo = new QaArchivePdo($tables);
qa_write_state([12 => $pauseEntry, 10 => $pauseEntry]);
$result = qa_service($pdo)->restore(12, $admin);
assert_same([['avisos' => []], 1, null, null], [$result, $pdo->tables['funcionarios'][12]['ativo'], $pdo->tables['funcionarios'][12]['arquivado_em'], $pdo->tables['funcionarios'][12]['arquivado_por']], 'restaurar: ativo, sem data nem autor');
$state = json_decode((string)file_get_contents(ESTADO_JSON), true)['funcionarios'];
assert_same([false, true], [$state[12]['em_pausa'], $state[10]['em_pausa']], 'restaurar: entrada velha do restaurado limpa; as outras ficam');
assert_same('funcionario_id=12; perfil=tecnico; equipe=n1; ator=admin; arquivado_em=2026-10-01 09:00:00; pa_escala_ausencias=nao_devolvidos', end($pdo->tables['audit_log'])['details'], 'restaurar: auditoria');
assert_same('CRITICAL', end($pdo->tables['audit_log'])['severity'], 'restaurar: CRITICAL');
assert_same([[], []], [$pdo->tables['pausas'], $pdo->tables['portal_sync_queue']], 'restaurar: PA, escala e ausencias nao voltam (nada alem do cadastro)');
foreach ([
    'Liderança' => [12, $leader, PortalForbiddenException::class, EmployeeArchiveService::RESTORE_DENIED_MESSAGE],
    'gestor' => [12, ['role' => 'gestor', 'username' => 'qa.gestor', 'session_employee_id' => null], PortalForbiddenException::class, EmployeeArchiveService::RESTORE_DENIED_MESSAGE],
    'técnico' => [12, ['role' => 'tecnico', 'username' => 'x', 'session_employee_id' => 10], PortalForbiddenException::class, EmployeeArchiveService::RESTORE_DENIED_MESSAGE],
    'cadastro ativo' => [10, $admin, DomainException::class, EmployeeArchiveService::ALREADY_ACTIVE_MESSAGE],
    'login em uso por outro ativo' => [12, $admin, DomainException::class, EmployeeArchiveService::LOGIN_IN_USE_MESSAGE],
    'inexistente' => [99, $admin, EmployeeArchiveNotFoundException::class, EmployeeArchiveService::NOT_FOUND_MESSAGE],
] as $label => [$id, $actor, $class, $message]) {
    $tables = qa_service_tables();
    if ($label !== 'login em uso por outro ativo') {
        unset($tables['funcionarios'][16]);
    }
    $pdo = new QaArchivePdo($tables);
    $before = $pdo->tables;
    assert_same([$class, $message], qa_service_outcome(static fn () => qa_service($pdo)->restore($id, $actor)), "restaurar: {$label}");
    assert_same($before, $pdo->tables, "restaurar: {$label}: nada gravado");
    if ($class === PortalForbiddenException::class) {
        assert_same([], $pdo->log, "restaurar: {$label}: recusado antes do banco");
    }
}
assert_same('ok', qa_service_outcome(static fn () => qa_service(new QaArchivePdo(qa_service_tables()))->restore(11, $admin))[0], 'restaurar: inativo antigo (sem data) tambem restaura');

// L-11 e endpoints (estrutura; ponta a ponta no qa-security).
$managerSource = (string)file_get_contents($root . '/classes/GerenciadorPausas.php');
$updateMethod = substr($managerSource, (int)strpos($managerSource, 'public function atualizar_funcionario('), 1500);
$updateMethod = substr($updateMethod, 0, (int)strpos($updateMethod, "\n    public function "));
assert_same(true, $updateMethod !== '' && strpos($updateMethod, '$this->salvar_estado();') === false, 'L-11: atualizar_funcionario nao regrava o estado');
assert_same(false, strpos($managerSource, 'public function remover_funcionario(') !== false, 'L-11: remover_funcionario (sem uso) saiu');
foreach (['api/remover_funcionario.php', 'api/restaurar_funcionario.php', 'api/atualizar_funcionario.php', 'api/adicionar_funcionario.php'] as $file) {
    $source = (string)file_get_contents($root . '/' . $file);
    $code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
    assert_same(0, preg_match('/salvar_estado|file_put_contents|estado\.json|ESTADO_JSON/', $code), "{$file}: nao grava o estado de pausas (L-11)");
}
$removeSource = (string)file_get_contents($root . '/api/remover_funcionario.php');
assert_same(true, strpos($removeSource, "require_funcionario_change(\$actor_role, null, null, 'arquivar');") < strpos($removeSource, 'require_csrf_token();'), 'remover: perfil antes do CSRF e do banco');
assert_same(true, strpos($removeSource, '(new EmployeeArchiveService())->archive(') !== false && strpos($removeSource, 'EmployeeArchiveService::httpError($e)') !== false, 'remover: arquiva pelo servico, erros pelo httpError');
assert_same(true, strpos($removeSource, "'efeitos' => \$resultado['efeitos'],") !== false, 'remover: devolve os efeitos');
$restoreSource = (string)file_get_contents($root . '/api/restaurar_funcionario.php');
assert_same(true, strpos($restoreSource, "if (!portal_role_can(\$actor_role, 'funcionarios.restaurar')) {") < strpos($restoreSource, 'require_csrf_token();'), 'restaurar: permissao antes do CSRF e do banco');
foreach (['admin' => true, 'lideranca' => false, 'gestor' => false, 'tecnico' => false, 'somente_leitura' => false] as $role => $allowed) {
    assert_same($allowed, portal_role_can($role, 'funcionarios.restaurar'), "funcionarios.restaurar para {$role} (D1)");
}
$updateSource = (string)file_get_contents($root . '/api/atualizar_funcionario.php');
assert_same(0, preg_match('/ativo = :ativo|\':ativo\'/', $updateSource), 'atualizar: nao grava ativo (D4)');
assert_same(true, strpos($updateSource, "SELECT fora_mapa_escala, ativo FROM funcionarios WHERE id = :id FOR UPDATE") !== false, 'atualizar: confere ativo no cadastro travado');

// ============================================================================
// 4. L-07: status, pendentes e Mapa PA sem inativos; codigos da PDOException
// ============================================================================

// httpError: SQLSTATE e codigo do driver no error_log, nunca a mensagem do
// banco (pode trazer dados); o usuario continua com o 503.
$pdoError = static function (string $message, ?array $errorInfo): PDOException {
    $error = new PDOException($message);
    $error->errorInfo = $errorInfo;
    return $error;
};
foreach ([
    'SIGNAL do trigger' => [$pdoError('SQLSTATE[45000]: <<Unknown error>>: 1644 Nome Secreto da Silva', ['45000', 1644, 'Nome Secreto da Silva']), 'sqlstate=45000 driver=1644'],
    'espera de trava' => [$pdoError('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; Nome Secreto da Silva', ['HY000', 1205, 'Lock wait timeout exceeded; Nome Secreto da Silva']), 'sqlstate=HY000 driver=1205'],
    'deadlock' => [$pdoError('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found; Nome Secreto da Silva', ['40001', 1213, 'Deadlock found; Nome Secreto da Silva']), 'sqlstate=40001 driver=1213'],
    'conexao sem errorInfo' => [$pdoError('SQLSTATE[HY000] [2002] Connection refused Nome Secreto da Silva', null), 'sqlstate=HY000 driver=2002'],
    'errorInfo so com SQLSTATE' => [$pdoError('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away Nome Secreto da Silva', ['HY000', null, null]), 'sqlstate=HY000 driver=2006'],
    'sem codigo nenhum' => [$pdoError('Nome Secreto da Silva', null), 'sqlstate=desconhecido driver=desconhecido'],
    'so errorInfo, texto sem prefixo' => [$pdoError('Nome Secreto da Silva', ['23000', 1062, 'Nome Secreto da Silva']), 'sqlstate=23000 driver=1062'],
    'errorInfo prevalece sobre o texto' => [$pdoError('SQLSTATE[HY000]: General error: 1205 Nome Secreto da Silva', ['40001', 1213, 'Nome Secreto da Silva']), 'sqlstate=40001 driver=1213'],
    'errorInfo vazio' => [$pdoError('Nome Secreto da Silva', ['', null, null]), 'sqlstate=desconhecido driver=desconhecido'],
    'errorInfo com texto no lugar do codigo' => [$pdoError('Nome Secreto da Silva', ['Nome Secreto', 'Nome Secreto', 'Nome Secreto da Silva']), 'sqlstate=desconhecido driver=desconhecido'],
    'PDOException dentro do DatabaseUnavailableException' => [new DatabaseUnavailableException('Erro ao conectar ao banco de dados.', 0, $pdoError('SQLSTATE[HY000] [1045] Access denied for user Nome Secreto da Silva', null)), 'sqlstate=HY000 driver=1045'],
] as $label => [$error, $codes]) {
    $logBefore = strlen(qa_error_log());
    assert_same([503, DATABASE_UNAVAILABLE_MESSAGE], EmployeeArchiveService::httpError($error), "httpError ({$label}): 503 de sempre");
    $logged = substr(qa_error_log(), $logBefore);
    assert_same(true, strpos($logged, '[ARCHIVE] Banco indisponivel: ' . get_class($error) . ' ' . $codes . PHP_EOL) !== false, "httpError ({$label}): error_log com {$codes}");
    assert_same(false, strpos($logged, 'Nome Secreto') !== false, "httpError ({$label}): mensagem do banco fora do error_log");
}
$logBefore = strlen(qa_error_log());
EmployeeArchiveService::httpError(new DatabaseUnavailableException('Banco fora.'));
assert_same(true, strpos(substr(qa_error_log(), $logBefore), '[ARCHIVE] Banco indisponivel: DatabaseUnavailableException' . PHP_EOL) !== false, 'httpError: sem PDOException na cadeia, sem codigos');

// obter_status(): inativo fora (status.php; o dashboard.php conta a partir
// daqui). Nulo conta como ativo, como no "ativo" => ativo ?? true da saida.
$GLOBALS['qa_pdo'] = null;
$manager = new GerenciadorPausas(2, 20);
foreach ([[31, 'n1', true], [32, 'n1', false], [33, 'n2', 0], [34, 'n2', true], [35, 'n2', null]] as [$id, $team, $active]) {
    $manager->adicionar_funcionario(new Funcionario($id, "Tecnico {$id}", $team, '00:00', '23:59', '23:58', '23:59', $active));
}
qa_write_state([
    31 => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:40:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado'],
    32 => ['em_pausa' => true, 'inicio_pausa' => '2026-10-13T09:40:00-03:00', 'motivo_pausa' => 'Café', 'status_aprovacao' => 'aprovado'],
    33 => ['status_aprovacao' => 'pendente', 'motivo_pausa' => 'Reunião', 'solicitacao_timestamp' => '2026-10-13T09:40:00-03:00'],
    34 => ['status_aprovacao' => 'pendente', 'motivo_pausa' => 'Reunião', 'solicitacao_timestamp' => '2026-10-13T09:40:00-03:00'],
]);
$manager->carregar_estado();
$status = $manager->obter_status();
assert_same([[31], [34, 35]], [array_column($status['n1'], 'id'), array_column($status['n2'], 'id')], 'obter_status: inativos fora (false e 0); ativo nulo fica (L-07)');
$listed = array_merge($status['n1'], $status['n2']);
assert_same([1, 1, [true, true, true]], [
    count(array_filter($listed, static fn (array $item): bool => !empty($item['em_pausa']))),
    count(array_filter($listed, static fn (array $item): bool => ($item['status_aprovacao'] ?? null) === 'pendente')),
    array_map(static fn (array $item): bool => $item['ativo'], $listed),
], 'obter_status: pausa e pendente do inativo fora dos contadores; contrato com ativo mantido');
$dashboardSource = (string)file_get_contents($root . '/api/portal/dashboard.php');
assert_same([true, false], [strpos($dashboardSource, '$status = $gerenciador->obter_status();') !== false, strpos($dashboardSource, 'getFuncionarios(') !== false], 'dashboard.php: contadores e active_pauses so a partir do obter_status()');
assert_same(true, strpos((string)file_get_contents($root . '/api/status.php'), '$status = $gerenciador->obter_status();') !== false, 'status.php: lista a partir do obter_status()');
// Listagem (commit 5): data do arquivamento so para quem gerencia
// funcionarios, so a data (sem autor), nula no ativo (ponta a ponta no qa-security).
$listSource = (string)file_get_contents($root . '/api/listar_funcionarios.php');
assert_same(true, strpos($listSource, "if (\$is_admin) {\n    try {\n        \$arquivado_em = [];\n        foreach (get_db_connection()->query('SELECT id, arquivado_em FROM funcionarios WHERE ativo = 0')") !== false, 'listagem: arquivado_em lido so para quem gerencia funcionarios, sem o autor');
assert_same(true, strpos($listSource, "substr((string)\$row['arquivado_em'], 0, 10)") !== false && strpos($listSource, "\$item['arquivado_em'] = \$ativo ? null : (\$arquivado_em[(int)\$f->id] ?? null);") !== false, 'listagem: so a data, nula no ativo');
$pendingSource = (string)file_get_contents($root . '/api/solicitacoes_pendentes.php');
assert_same(true, strpos($pendingSource, 'if (($funcionario->ativo ?? true) && $funcionario->status_aprovacao == "pendente") {') !== false, 'solicitacoes_pendentes: inativo fora, mesma regra do obter_status()');

// Mapa PA (D13): de hoje em diante sem vinculo de inativo (recorrente e da
// tabela antiga); o passado como esta. O banco simulado so aplica o filtro de
// ativo quando o SQL traz a clausula exata.
final class QaMapPdo extends PDO {
    public array $sql = [];

    public function __construct(public array $employees, public array $assignments, public array $legacy) {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaMapStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[42S02]: inventario ausente no banco simulado');
    }

    public bool $transaction = false;
    /** @var list<bool> Ocupantes do save consultados com o filtro de ativo (decisao 8). */
    public array $occupantFilter = [];

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
        return (string)count($this->assignments);
    }
}

final class QaMapStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaMapPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $db = $this->pdo;
        $sql = $this->sql;
        $db->sql[] = $sql;
        $this->rows = [];
        $activeOnly = static fn (string $table): bool => strpos($sql, " AND EXISTS (SELECT 1 FROM funcionarios f WHERE f.id = {$table}.employee_id AND f.ativo = 1) ORDER BY") !== false;
        $active = static fn (array $row): bool => (int)$db->employees[(int)$row['employee_id']]['ativo'] === 1;
        if ($sql === 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name') {
            $this->rows[] = ['total' => 1];
            return true;
        }
        if (strpos($sql, 'SELECT id, pa_number, employee_id, employee_name, employee_login, team, schedule_rule_type, schedule_rule_config, valid_from, valid_until, notes, created_by, updated_by, created_at, updated_at FROM portal_pa_assignments WHERE active = 1 AND valid_from <= :date_until AND (valid_until IS NULL OR valid_until >= :date_from)') === 0) {
            foreach ($db->assignments as $row) {
                if ($row['valid_from'] <= $params[':date_until'] && ($row['valid_until'] === null || $row['valid_until'] >= $params[':date_from']) && (!$activeOnly('portal_pa_assignments') || $active($row))) {
                    $this->rows[] = $row;
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, pa_number, employee_id, employee_name, employee_login, team, schedule_status, schedule_label, notes, created_by, updated_by, created_at, updated_at FROM portal_pa_map WHERE record_status = "active" AND work_date = :work_date') === 0) {
            foreach ($db->legacy as $row) {
                if ($row['work_date'] === $params[':work_date'] && (!$activeOnly('portal_pa_map') || $active($row))) {
                    $this->rows[] = array_diff_key($row, ['work_date' => true]);
                }
            }
            return true;
        }
        if ($sql === 'SELECT id, nome AS name, equipe AS team, ad_login FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0 ORDER BY nome') {
            foreach ($db->employees as $id => $row) {
                if ((int)$row['ativo'] === 1) {
                    $this->rows[] = ['id' => $id, 'name' => "Tecnico {$id}", 'team' => 'n1', 'ad_login' => null];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT exception_type FROM portal_schedule_exceptions WHERE ') === 0 || strpos($sql, 'SELECT rule_type, rule_config FROM portal_schedule_rules WHERE ') === 0) {
            return true;
        }
        // [F6] Motor do mapa (OperationalService::mapPresence): todos sempre
        // presenciais desde 2026-01-01 (conflito do save, commit 2), sem
        // excecao nem ausencia; cadastros com o arquivado (D7).
        if (strpos($sql, 'SELECT r.id, r.employee_id, f.nome AS employee_name') === 0) {
            foreach ($db->employees as $id => $row) {
                if ((int)$row['ativo'] === 1 || ($row['arquivado_em'] ?? null) !== null) {
                    $this->rows[] = ['id' => 900 + $id, 'employee_id' => $id, 'employee_name' => "Tecnico {$id}", 'ad_login' => null, 'team' => 'n1',
                        'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => null,
                        'created_by' => 'qa', 'updated_by' => 'qa', 'created_at' => '2026-01-01 08:00:00', 'updated_at' => '2026-01-01 08:00:00'];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, employee_id, employee_name, team, exception_date, exception_type, note,') === 0
            || strpos($sql, 'SELECT a.employee_id, a.starts_on, a.ends_on, a.period_type, a.start_time, a.end_time') === 0) {
            return true;
        }
        if (strpos($sql, 'SELECT id, nome AS employee_name, equipe, arquivado_em FROM funcionarios WHERE (ativo = 1 OR arquivado_em IS NOT NULL) AND fora_mapa_escala = 0') === 0) {
            foreach ($db->employees as $id => $row) {
                if ((int)$row['ativo'] === 1 || ($row['arquivado_em'] ?? null) !== null) {
                    $this->rows[] = ['id' => $id, 'employee_name' => "Tecnico {$id}", 'equipe' => 'n1', 'arquivado_em' => $row['arquivado_em'] ?? null];
                }
            }
            return true;
        }
        if ($sql === 'SELECT pa_number, employee_id, team, work_date FROM portal_pa_map WHERE record_status = "active" AND work_date BETWEEN :date_from AND :date_to ORDER BY work_date, pa_number') {
            foreach ($db->legacy as $row) {
                if ($row['work_date'] >= $params[':date_from'] && $row['work_date'] <= $params[':date_to']) {
                    $this->rows[] = ['pa_number' => $row['pa_number'], 'employee_id' => $row['employee_id'], 'team' => $row['team'], 'work_date' => $row['work_date']];
                }
            }
            return true;
        }
        // save(): cadastro, conferencia de conflito e gravacao.
        if ($sql === 'SELECT id, nome AS name, equipe AS team, ad_login, fora_mapa_escala FROM funcionarios WHERE id = :id AND ativo = 1 LIMIT 1 FOR UPDATE') {
            $id = (int)$params[':id'];
            if (isset($db->employees[$id]) && (int)$db->employees[$id]['ativo'] === 1) {
                $this->rows[] = ['id' => $id, 'name' => "Tecnico {$id}", 'team' => 'n1', 'ad_login' => null, 'fora_mapa_escala' => 0];
            }
            return true;
        }
        $overlaps = static fn (array $row): bool => (int)$row['id'] !== (int)$params[':id']
            && $row['valid_from'] <= $params[':new_until'] && ($row['valid_until'] === null || $row['valid_until'] >= $params[':new_from']);
        if ($sql === 'SELECT id, pa_number FROM portal_pa_assignments WHERE active = 1 AND employee_id = :employee_id AND id <> :id AND valid_from <= :new_until AND (valid_until IS NULL OR valid_until >= :new_from) LIMIT 1 FOR UPDATE') {
            foreach ($db->assignments as $row) {
                if ((int)$row['employee_id'] === (int)$params[':employee_id'] && $overlaps($row)) {
                    $this->rows[] = ['id' => $row['id'], 'pa_number' => $row['pa_number']];
                }
            }
            return true;
        }
        // [F6, commit 2] Ocupantes do PA no horizonte do aviso de conflito; o
        // filtro de ativo so vale com a clausula exata do servico.
        if (strpos($sql, 'SELECT employee_id, valid_from, valid_until FROM portal_pa_assignments WHERE active = 1 AND pa_number = :pa_number AND id <> :id AND valid_from <= :date_until AND (valid_until IS NULL OR valid_until >= :date_from)') === 0) {
            $db->occupantFilter[] = strpos($sql, ' AND EXISTS (SELECT 1 FROM funcionarios f WHERE f.id = portal_pa_assignments.employee_id AND f.ativo = 1) ORDER BY id') !== false;
            foreach ($db->assignments as $row) {
                if ($row['pa_number'] === $params[':pa_number'] && (int)$row['id'] !== (int)$params[':id'] && $row['valid_from'] <= $params[':date_until']
                    && ($row['valid_until'] === null || $row['valid_until'] >= $params[':date_from']) && (!$activeOnly('portal_pa_assignments') || $active($row))) {
                    $this->rows[] = ['employee_id' => $row['employee_id'], 'valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until']];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT employee_id, work_date FROM portal_pa_map WHERE record_status = "active" AND pa_number = :pa_number AND work_date BETWEEN :date_from AND :date_to') === 0) {
            $db->occupantFilter[] = strpos($sql, ' AND EXISTS (SELECT 1 FROM funcionarios f WHERE f.id = portal_pa_map.employee_id AND f.ativo = 1) ORDER BY work_date') !== false;
            foreach ($db->legacy as $row) {
                if ($row['pa_number'] === $params[':pa_number'] && $row['work_date'] >= $params[':date_from'] && $row['work_date'] <= $params[':date_to'] && (!$activeOnly('portal_pa_map') || $active($row))) {
                    $this->rows[] = ['employee_id' => $row['employee_id'], 'work_date' => $row['work_date']];
                }
            }
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_pa_assignments (') === 0) {
            $db->assignments[] = [
                'id' => count($db->assignments) + 1, 'pa_number' => $params[':pa_number'], 'employee_id' => $params[':employee_id'],
                'valid_from' => $params[':valid_from'], 'valid_until' => $params[':valid_until'],
            ];
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

require_once $root . '/services/PaMapService.php';
$today = date('Y-m-d');
$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$mapRow = static fn (int $id, int $employeeId, string $pa, array $extra): array => [
    'id' => $id, 'pa_number' => $pa, 'employee_id' => $employeeId, 'employee_name' => "Tecnico {$employeeId}", 'employee_login' => null,
    'team' => 'n1', 'notes' => null, 'created_by' => 'qa', 'updated_by' => 'qa', 'created_at' => '2026-01-01 08:00:00', 'updated_at' => '2026-01-01 08:00:00',
] + $extra;
$recurring = ['schedule_rule_type' => 'always_onsite', 'schedule_rule_config' => null, 'valid_from' => '2026-01-01', 'valid_until' => null];
$legacyRows = [];
foreach ([$yesterday, $today, $tomorrow] as $index => $date) {
    $legacyRows[] = $mapRow(100 + $index, 42, '1729', ['schedule_status' => 'onsite', 'schedule_label' => null, 'work_date' => $date]);
    $legacyRows[] = $mapRow(110 + $index, 43, '1728', ['schedule_status' => 'onsite', 'schedule_label' => null, 'work_date' => $date]);
    $legacyRows[] = $mapRow(120 + $index, 41, '1727', ['schedule_status' => 'onsite', 'schedule_label' => null, 'work_date' => $date]);
}
$mapPdo = new QaMapPdo(
    [41 => ['ativo' => 1], 42 => ['ativo' => 0], 43 => ['ativo' => '0']],
    [$mapRow(1, 41, '1732', $recurring), $mapRow(2, 42, '1731', $recurring), $mapRow(3, 43, '1730', $recurring)],
    $legacyRows
);
$map = new PaMapService($mapPdo, $today);
$mapView = static function (string $date) use ($map): array {
    $result = $map->list(['date' => $date], ['role' => 'admin', 'employee_id' => null], true);
    $view = [];
    foreach ($result['items'] as $item) {
        $view[] = $item['source'] . ':' . $item['employee_id'];
    }
    $byPa = [];
    foreach ($result['pas'] as $pa) {
        foreach ($pa['assignments'] as $item) {
            $byPa[] = $pa['pa_number'] . ':' . $item['employee_id'];
        }
    }
    return [$view, $byPa, array_column($result['employees'], 'id')];
};
assert_same([
    ['assignment:41', 'assignment:42', 'assignment:43', 'legacy:42', 'legacy:43', 'legacy:41'],
    ['1732:41', '1731:42', '1730:43', '1729:42', '1728:43', '1727:41'],
    [41],
], $mapView($yesterday), 'Mapa PA de ontem: vinculos de inativo como estao (D13, passado sem mudanca)');
foreach (['hoje' => $today, 'amanha' => $tomorrow] as $label => $date) {
    assert_same([
        ['assignment:41', 'legacy:41'],
        ['1732:41', '1727:41'],
        [41],
    ], $mapView($date), "Mapa PA de {$label}: sem vinculo de inativo, recorrente e da tabela antiga (L-07, D13)");
}
$filtered = array_values(array_filter($mapPdo->sql, static fn (string $sql): bool => strpos($sql, 'FROM portal_pa_') !== false));
assert_same(12, count($filtered), 'Mapa PA: quatro leituras de vinculo por data (recorrente e tabela antiga, na data e na semana do conflito; F6)');
// Decisao 8 do commit 4, no F6 (commit 2): o conflito do save() virou aviso
// (P13), no horizonte de hoje em diante (DF4), com a regra do mapa: vinculo
// de cadastro inativo nao entra no conflito nem na auditoria; ocupado por
// ativo salva e avisa. Todos sempre presenciais nesta massa.
$saveOutcome = static function (int $employeeId, string $pa, string $validFrom) use ($mapRow, $recurring, $today): array {
    $pdo = new QaMapPdo(
        [41 => ['ativo' => 1], 42 => ['ativo' => 0], 43 => ['ativo' => '0'], 44 => ['ativo' => 1]],
        [$mapRow(1, 41, '1732', $recurring), $mapRow(2, 42, '1731', $recurring), $mapRow(3, 43, '1730', $recurring)],
        [$mapRow(130, 42, '1731', ['work_date' => $today, 'schedule_status' => 'onsite', 'schedule_label' => null])]
    );
    $outcome = qa_service_outcome(static fn () => (new PaMapService($pdo, $today))->save(['pa_number' => $pa, 'employee_id' => $employeeId, 'valid_from' => $validFrom], 'qa.lider'));
    if ($outcome[0] !== 'ok') {
        return [$outcome[0] . ': ' . $outcome[1], count($pdo->assignments)];
    }
    $result = $outcome[1];
    $ids = array_values(array_unique(array_merge([], ...array_column($result['conflicts'], 'employee_ids'))));
    return ['ok', count($pdo->assignments), $ids, count($result['conflicts']) + $result['conflicts_more'], $result['conflict_audit'] === null ? null : preg_replace('/ID \d+, PA/', 'ID ?, PA', $result['conflict_audit']), $pdo->occupantFilter];
};
foreach (['hoje' => $today, 'amanha' => $tomorrow, 'ontem (horizonte de hoje em diante)' => $yesterday] as $label => $date) {
    assert_same(['ok', 4, [], 0, null, [true, true]], $saveOutcome(44, '1731', $date), "Mapa PA: PA ocupado por inativo (recorrente e tabela antiga) salva sem conflito e sem o inativo na auditoria, vinculo de {$label} (decisao 8)");
    assert_same(['ok', 4, [], 0, null, [true, true]], $saveOutcome(44, '1730', $date), "Mapa PA: PA ocupado por inativo com ativo em texto ('0'), vinculo de {$label}");
    $active = $saveOutcome(44, '1732', $date);
    assert_same(['ok', 4, [41, 44], 20, [true, true]], [$active[0], $active[1], $active[2], $active[3], $active[5]], "Mapa PA: PA ocupado por ativo salva e avisa os 20 dias uteis do horizonte ({$label}; P13, DF4)");
    assert_same(1, preg_match('/^Alocacao de PA salva com conflito\. ID \?, PA 1732, colaboradores ID 41, 44, datas (\d{4}-\d{2}-\d{2}, ){9}\d{4}-\d{2}-\d{2} e mais 10$/', (string)$active[4]), "Mapa PA: auditoria com PA, IDs e ate 10 datas, sem nome ({$label})");
}
assert_same([DomainException::class . ': Este colaborador já possui vínculo ativo em outro PA no período.', 3], $saveOutcome(41, '1731', $today), 'Mapa PA: o mesmo colaborador em outro PA no periodo continua 409');

$filter = new ReflectionMethod(PaMapService::class, 'activeEmployeeFilter');
$filter->setAccessible(true);
assert_same('', $filter->invoke($map, 'portal_pa_assignments', $yesterday), 'Mapa PA: sem filtro antes de hoje');
assert_same([LogicException::class, 'Tabela fora da lista do Mapa PA.'], qa_service_outcome(static fn () => $filter->invoke($map, 'funcionarios', $today)), 'Mapa PA: tabela do filtro so por lista branca');

// Runners.
foreach (['scripts/test-linux.sh', 'scripts/test-local.ps1'] as $runner) {
    assert_same(true, strpos((string)file_get_contents($root . '/' . $runner), 'qa-archive.php') !== false, "{$runner} roda o qa-archive");
}

echo "QA archive OK ({$checks} verificacoes)\n";
