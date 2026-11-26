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

echo 'QA monthly schedule OK (' . ($GLOBALS['qa_checks'] ?? 0) . " verificacoes)\n";
