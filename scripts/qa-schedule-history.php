<?php
declare(strict_types=1);

// ============================================================================
// QA do historico de escala (Parte B, F1)
//
// Sem banco: testa a estrutura da migration 011, do rollback e da consulta de
// verificacao. As consultas SQL nao rodam aqui (sem MySQL local): a prova de
// ponta a ponta e o ensaio no servidor (scripts/ensaio-migration.sh).
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
require_once __DIR__ . '/../services/OperationalService.php';

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

echo "QA schedule history OK\n";
