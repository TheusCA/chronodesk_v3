<?php
declare(strict_types=1);

// ============================================================================
// QA do NC (matricula do cadastro Senior) no cadastro, na hora extra e na
// correcao de ponto (lote NC, 2026-11-23; docs/DESENHO_NC_E_ICONE.md)
//
// Sem banco:
// 1. estrutura da migration 026, do rollback, das linhas do ensaio e da
//    consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote. Nenhuma matricula real: so valores ficticios.
//
// Uso: php scripts/qa-nc.php
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
// 1. Migration 026, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261123_026_matricula_nc.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261123_026_matricula_nc_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261123_026_ensaio_linhas_nc.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261123_026_matricula_nc_check.sql');
$digits = "REGEXP_LIKE(%s, ''^[0-9]{1,12}$'', ''c'')";

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 026: fuso da sessao (A4)');
foreach ([['funcionarios', 'matricula', 'has_matricula'], ['portal_overtime_entries', 'employee_nc', 'has_overtime_nc'], ['portal_time_adjustments', 'employee_nc', 'has_adjustment_nc']] as [$table, $column, $flag]) {
    assert_same(1, preg_match("/TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'\\s*\\);\\s*SET @add_" . substr($flag, 4) . "_sql := IF\\(\\s*@{$flag} = 0,/", $migration), "migration 026: {$table}.{$column} so se nao existir (idempotente)");
}
assert_same([1, 2, 3], [
    substr_count($migration, 'ADD COLUMN matricula VARCHAR(12) NULL DEFAULT NULL'),
    substr_count($migration, 'ADD COLUMN employee_nc VARCHAR(12) NULL DEFAULT NULL'),
    substr_count($migration, 'ALGORITHM=INSTANT'),
], 'migration 026: as tres colunas NULL (nenhuma linha muda), so no metadado (INSTANT explicito)');
assert_same([1, 1, 1, 3], [
    substr_count($migration, 'ADD CONSTRAINT ck_funcionarios_matricula CHECK (matricula IS NULL OR ' . sprintf($digits, 'matricula') . ')'),
    substr_count($migration, 'ADD CONSTRAINT ck_overtime_employee_nc CHECK (employee_nc IS NULL OR ' . sprintf($digits, 'employee_nc') . ')'),
    substr_count($migration, 'ADD CONSTRAINT ck_time_adjustment_employee_nc CHECK (employee_nc IS NULL OR ' . sprintf($digits, 'employee_nc') . ')'),
    substr_count($migration, 'ALGORITHM=COPY'),
], 'migration 026: so digitos, de 1 a 12, sensivel a caixa, nos tres lugares (COPY explicito: o MySQL confere as linhas)');
foreach (['ck_funcionarios_matricula', 'ck_overtime_employee_nc', 'ck_time_adjustment_employee_nc'] as $constraint) {
    assert_same(1, substr_count($migration, "CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = '{$constraint}'"), "migration 026: {$constraint} so se nao existir");
}
assert_same([1, 1], [
    substr_count($migration, 'ALTER TABLE funcionarios ADD UNIQUE KEY uq_funcionarios_matricula (matricula), ALGORITHM=INPLACE, LOCK=NONE'),
    substr_count($migration, "TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_matricula'"),
], 'migration 026: NC unico entre os cadastros (varios NULL), sem travar a tabela, so se nao existir');
assert_same(0, preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP|TRUNCATE)\b/i', $migration), 'migration 026: so estrutura, nenhuma linha muda');
assert_same(0, preg_match('/NOT NULL/i', (string)preg_replace("/COMMENT\\s*(=\\s*)?''[^']*''/", '', $migration)), 'migration 026: nenhuma coluna obrigatoria no banco (o cadastro antigo fica sem NC)');

assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 026: para se ja houver copia *_bkp_026');
$positions = [
    strpos($rollback, 'CALL rollback_026_confere_copias();'),
    strpos($rollback, 'CREATE TABLE funcionarios_nc_bkp_026 AS SELECT id, matricula FROM funcionarios WHERE matricula IS NOT NULL'),
    strpos($rollback, 'CREATE TABLE portal_overtime_entries_nc_bkp_026 AS SELECT id, employee_nc FROM portal_overtime_entries WHERE employee_nc IS NOT NULL'),
    strpos($rollback, 'CREATE TABLE portal_time_adjustments_nc_bkp_026 AS SELECT id, employee_nc FROM portal_time_adjustments WHERE employee_nc IS NOT NULL'),
    strpos($rollback, "'ALTER TABLE portal_time_adjustments DROP CHECK ck_time_adjustment_employee_nc'"),
    strpos($rollback, "'ALTER TABLE portal_time_adjustments DROP COLUMN employee_nc'"),
    strpos($rollback, "'ALTER TABLE portal_overtime_entries DROP CHECK ck_overtime_employee_nc'"),
    strpos($rollback, "'ALTER TABLE portal_overtime_entries DROP COLUMN employee_nc'"),
    strpos($rollback, "'ALTER TABLE funcionarios DROP CHECK ck_funcionarios_matricula'"),
    strpos($rollback, "'ALTER TABLE funcionarios DROP INDEX uq_funcionarios_matricula'"),
    strpos($rollback, "'ALTER TABLE funcionarios DROP COLUMN matricula'"),
];
$sorted = $positions;
sort($sorted);
assert_same([false, $sorted], [in_array(false, $positions, true), $positions], 'rollback 026: confere, copia o NC (so ID e NC das linhas com NC) e so entao tira restricoes, indice e colunas');
assert_same(0, preg_match('/\b(DELETE|UPDATE|TRUNCATE)\b/i', $rollback), 'rollback 026: nenhuma linha apagada nem alterada (so copias e estrutura)');

assert_same(3, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio: cada comando so no banco ensaio_');
assert_same(1, substr_count($lines, "SET f.matricula = CONCAT('9', LPAD(f.id, 8, '0'))"), 'linhas do ensaio: NC ficticio (9 + o ID com 8 digitos), nunca uma matricula real');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP|INSERT)\b/i', qa_sql_without_comments($lines)), 'linhas do ensaio: nada apagado nem criado');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 026 so le');
foreach (['colunas_026', 'checks_026', 'indice_026', 'copias_de_rollback_026', 'cadastros_ativos', 'ativos_com_nc', 'ativos_sem_nc', 'horas_extras_com_copia', 'correcoes_com_copia', 'nc_repetido', 'nc_fora_do_formato', 'copias_fora_do_formato', 'copias_diferentes_do_cadastro'] as $count) {
    assert_same(1, preg_match('/AS ' . $count . '(,|;|\'|$|\s)/m', $check), "verificacao 026: conta {$count}");
}
$checkSql = qa_sql_without_comments($check);
// Lista branca dos usos do NC na verificacao: nome da coluna no
// information_schema, IS (NOT) NULL, COUNT(DISTINCT), REGEXP_LIKE e a
// comparacao da copia com o cadastro. Qualquer outro uso (exibir, concatenar,
// agrupar) reprova.
$ncLeft = (string)preg_replace([
    "/'(matricula|employee_nc)'/",
    '/\b(\w+\.)?(matricula|employee_nc) IS (NOT )?NULL\b/',
    '/COUNT\(DISTINCT matricula\)/',
    '/REGEXP_LIKE\((matricula|employee_nc), /',
    '/\b[ot]\.employee_nc <=> f\.matricula\b/',
], '', $checkSql);
assert_same([0, 0], [
    preg_match('/\b(matricula|employee_nc)\b/i', $ncLeft),
    preg_match('/\b(nome|employee_name|ad_login|reason|justification|note)\b/i', $checkSql),
], 'verificacao 026: nenhum NC, nome, login nem texto livre na saida (o NC so em contagem, formato e comparacao)');

echo "QA NC OK ({$GLOBALS['qa_checks']} verificacoes)\n";
