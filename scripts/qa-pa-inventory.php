<?php
declare(strict_types=1);

// ============================================================================
// QA do inventario de PA completo e das preferencias (desenho do F10, 3.3 e
// 3.3.1; lote F10-3)
//
// Sem banco:
// 1. estrutura da migration 022, do rollback, das linhas do ensaio e da
//    consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote.
//
// Uso: php scripts/qa-pa-inventory.php
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
// 1. Migration 022, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261119_022_pa_inventory.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261119_022_pa_inventory_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261119_022_ensaio_linhas_inv.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261119_022_pa_inventory_check.sql');

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 022: fuso da sessao (A4)');
foreach (['seat_type', 'can_lend', 'reserved_team'] as $column) {
    assert_same(1, preg_match("/TABLE_NAME = 'portal_pa_inventory' AND COLUMN_NAME = '{$column}'\\s*\\);\\s*SET @add_{$column}_sql := IF\\(\\s*@has_{$column} = 0,/", $migration), "migration 022: coluna {$column} so se nao existir (idempotente)");
}
assert_same([1, 1, 1], [
    substr_count($migration, "ADD COLUMN seat_type ENUM(''flexible'', ''fixed'') NOT NULL DEFAULT ''flexible''"),
    substr_count($migration, 'ADD COLUMN can_lend TINYINT(1) NOT NULL DEFAULT 0'),
    substr_count($migration, "ADD COLUMN reserved_team ENUM(''critico'', ''hibrido'') NULL DEFAULT NULL"),
], 'migration 022: tipo flexivel por padrao, sem emprestimo e sem reserva (nenhum PA muda no deploy)');
assert_same(3, substr_count($migration, 'ALGORITHM=INSTANT'), 'migration 022: as tres colunas so no metadado (INSTANT, explicito)');
assert_same([1, 1, 2], [
    substr_count($migration, "ADD CONSTRAINT ck_pa_inventory_reserved CHECK (reserved_team IS NULL OR seat_type = ''fixed'')"),
    substr_count($migration, "ADD CONSTRAINT ck_pa_inventory_lend CHECK (can_lend IN (0, 1) AND (can_lend = 0 OR (seat_type = ''fixed'' AND reserved_team IS NULL)))"),
    substr_count($migration, 'ALGORITHM=COPY'),
], 'migration 022: reserva so em fixo, emprestimo so em fixo sem reserva (COPY explicito: o MySQL confere as linhas)');
foreach (['ck_pa_inventory_reserved', 'ck_pa_inventory_lend'] as $constraint) {
    assert_same(1, substr_count($migration, "CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = '{$constraint}'"), "migration 022: {$constraint} so se nao existir");
}
assert_same(1, substr_count($migration, 'CREATE TABLE portal_pa_preferences ('), 'migration 022: cria as preferencias');
assert_same(1, preg_match("/WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'portal_pa_preferences'\\s*\\);\\s*SET @create_preferences_sql := IF\\(\\s*@has_preferences_table = 0,/", $migration), 'migration 022: preferencias so se nao existir (idempotente)');
assert_same([1, 1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_pa_preference (pa_id, employee_id)'),
    substr_count($migration, 'KEY idx_pa_preference_employee (employee_id)'),
    substr_count($migration, 'CONSTRAINT fk_pa_preference_pa FOREIGN KEY (pa_id) REFERENCES portal_pa_inventory (id) ON DELETE RESTRICT'),
    substr_count($migration, 'CONSTRAINT fk_pa_preference_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'),
], 'migration 022: uma preferencia por par, indice da FK do cadastro, FKs com RESTRICT');
assert_same(0, preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP|TRUNCATE)\b/i', $migration), 'migration 022: so estrutura, nenhuma linha muda');
assert_same(0, preg_match('/\b(note|notes|observacao|nome|employee_name|login)\b/i', (string)preg_replace("/COMMENT\\s*(=\\s*)?''[^']*''/", '', $migration)), 'migration 022: nenhuma coluna de texto livre nem nome de pessoa');

assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 022: para se ja houver copia *_bkp_022');
$copyInventory = strpos($rollback, 'CREATE TABLE portal_pa_inventory_bkp_022');
$copyPreferences = strpos($rollback, 'CREATE TABLE portal_pa_preferences_bkp_022');
$dropPreferences = strpos($rollback, "'DROP TABLE portal_pa_preferences'");
$dropLend = strpos($rollback, "'ALTER TABLE portal_pa_inventory DROP CHECK ck_pa_inventory_lend'");
$dropReserved = strpos($rollback, "'ALTER TABLE portal_pa_inventory DROP CHECK ck_pa_inventory_reserved'");
$dropColumns = strpos($rollback, "CONCAT('ALTER TABLE portal_pa_inventory ', GROUP_CONCAT(CONCAT('DROP COLUMN ', COLUMN_NAME)");
assert_same(true, strpos($rollback, 'CALL rollback_022_confere_copias();') < $copyInventory && $copyInventory < $copyPreferences && $copyPreferences < $dropPreferences && $dropPreferences < $dropLend && $dropLend < $dropReserved && $dropReserved < $dropColumns, 'rollback 022: confere, copia o inventario e as preferencias, e so entao tira a tabela, as restricoes e as colunas');
assert_same(1, substr_count($rollback, 'SELECT id, pa_number, seat_type, can_lend, reserved_team'), 'rollback 022: a copia do inventario guarda so ID, numero, tipo, emprestimo e reserva');
assert_same(0, preg_match('/\b(DELETE|UPDATE|TRUNCATE)\b/i', $rollback), 'rollback 022: nenhuma linha apagada nem alterada (so copias e estrutura)');

assert_same(3, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio: cada comando so no banco ensaio_');
assert_same(2, substr_count($lines, "AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()))"), 'linhas do ensaio: reserva e fixo so em PA sem vinculo vigente ou futuro');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP)\b|portal_pa_assignments\s+SET|funcionarios\s+SET/i', qa_sql_without_comments($lines)), 'linhas do ensaio: nenhum vinculo nem cadastro alterado');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 022 so le');
foreach (['colunas_022', 'checks_022', 'tabela_022', 'copias_de_rollback_022', 'reservados_critico', 'reservados_hibrido', 'fixos_que_emprestam', 'preferencias_em_pa_reservado', 'preferencias_fora_do_grupo_comum', 'reservados_com_vinculo_vigente', 'fixos_com_vinculos_sobrepostos'] as $count) {
    assert_same(1, preg_match('/AS ' . $count . '(,|;|\'|$|\s)/m', $check), "verificacao 022: conta {$count}");
}
assert_same(0, preg_match('/\b(nome|employee_name|employee_login|ad_login|notes)\b/i', qa_sql_without_comments($check)), 'verificacao 022: nenhuma coluna de nome, login ou observacao');

echo "QA PA inventory OK ({$GLOBALS['qa_checks']} verificacoes)\n";
