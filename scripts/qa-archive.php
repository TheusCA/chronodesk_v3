<?php
declare(strict_types=1);

// ============================================================================
// QA do arquivamento de tecnicos (Parte B, F4; desenho, secao 9.6)
//
// 1. Migration 014, rollback, verificacao e dados de ensaio (estrutura; o SQL
//    so roda no servidor e no MySQL do responsavel: ensaio e teste).
// As secoes do plano (funcao pura), do servico e dos endpoints entram nos
// commits 2 e 3 do F4.
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

// Runners.
foreach (['scripts/test-linux.sh', 'scripts/test-local.ps1'] as $runner) {
    assert_same(true, strpos((string)file_get_contents($root . '/' . $runner), 'qa-archive.php') !== false, "{$runner} roda o qa-archive");
}

echo "QA archive OK ({$checks} verificacoes)\n";
