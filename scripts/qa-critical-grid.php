<?php
declare(strict_types=1);

// ============================================================================
// QA da Escala do Critico em grade semanal (pedido da Lideranca, 2026-11-24;
// docs/DESENHO_ESCALA_CRITICO.md, D-ESC-1 a D-ESC-20)
//
// Sem banco:
// 1. estrutura da migration 027, do rollback (com as paradas), das linhas do
//    ensaio e da consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote. Nenhum nome real: so valores ficticios.
//
// Uso: php scripts/qa-critical-grid.php
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
// 1. Migration 027, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261124_027_critical_grid.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261124_027_critical_grid_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261124_027_ensaio_linhas.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261124_027_critical_grid_check.sql');
$palette = "''azul'', ''verde'', ''pessego'', ''lilas'', ''amarelo'', ''turquesa'', ''cinza'', ''areia''";
$oldCategories = "''folga'', ''ferias'', ''ausencia_justificada'', ''compromisso'', ''outro''";

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 027: fuso da sessao (A4)');
assert_same([1, 1, 1], [
    preg_match("/TABLE_NAME = 'portal_critical_roles' AND COLUMN_NAME = 'color_token'\\s*\\);\\s*SET @add_color_sql := IF\\(\\s*@has_color = 0,/", $migration),
    preg_match("/CONSTRAINT_NAME = 'ck_critical_role_color'\\s*\\);\\s*SET @add_color_check_sql := IF\\(\\s*@has_color_check = 0,/", $migration),
    preg_match("/TABLE_NAME = 'portal_holidays'\\s*\\);\\s*SET @create_holidays_sql := IF\\(\\s*@has_holidays = 0,/", $migration),
], 'migration 027: coluna, CHECK da cor e tabela de feriados so se nao existirem (idempotente)');
assert_same([1, 1], [
    substr_count($migration, "ADD COLUMN color_token VARCHAR(16) NULL COMMENT ''Cor do papel na paleta fechada da tela (D-ESC-13); nulo = areia''\n        AFTER sort_order, ALGORITHM=INSTANT"),
    substr_count($migration, "ADD CONSTRAINT ck_critical_role_color CHECK (color_token IS NULL OR color_token IN ({$palette}))"),
], 'migration 027: cor nula por padrao (INSTANT) e so a paleta fechada das 8 cores, sem hex');
assert_same(1, preg_match("/UPDATE portal_critical_roles\\s+SET color_token = CASE\\s+WHEN name LIKE 'Presencial%' THEN 'azul'\\s+WHEN name LIKE 'Abertura%' THEN 'verde'\\s+WHEN name LIKE 'Fechamento%' THEN 'pessego'\\s+WHEN name LIKE 'Home%' THEN 'lilas'\\s+END\\s+WHERE color_token IS NULL\\s+AND \\(name LIKE 'Presencial%' OR name LIKE 'Abertura%' OR name LIKE 'Fechamento%' OR name LIKE 'Home%'\\);/", $migration), 'migration 027: cor padrao pelo comeco do nome, so onde a cor esta nula (D-ESC-13)');
assert_same([1, 1, 0], [
    preg_match("/CHECK_CLAUSE LIKE '%uniqueItems%'\\s*\\);\\s*SET @drop_roles_check_sql := IF\\(\\s*@roles_check_unique > 0,/", $migration),
    substr_count($migration, "ADD CONSTRAINT ck_critical_rotation_roles CHECK (JSON_SCHEMA_VALID(''{\"type\": \"array\", \"minItems\": 1, \"maxItems\": 20, \"items\": {\"type\": \"integer\", \"minimum\": 1}}'', roles))"),
    substr_count($migration, 'employees'),
], 'migration 027: vagas: a CHECK dos papeis troca so quando ainda exige uniqueItems; continua 1 a 20 inteiros >= 1; as pessoas nao mudam (D-ESC-15)');
assert_same([1, 1], [
    substr_count($migration, "COLUMN_TYPE LIKE '%''banco_horas''%'"),
    substr_count($migration, "MODIFY COLUMN category ENUM({$oldCategories}, ''banco_horas'') NOT NULL"),
], 'migration 027: banco_horas no FIM do ENUM, so se faltar (D-ESC-18)');
assert_same([1, 1, 1, 1, 1], [
    substr_count($migration, "holiday_date DATE NOT NULL COMMENT ''Data do feriado (uma por data)''"),
    substr_count($migration, "scope ENUM(''todos'') NOT NULL DEFAULT ''todos''"),
    substr_count($migration, 'row_version INT UNSIGNED NOT NULL DEFAULT 1'),
    substr_count($migration, 'UNIQUE KEY uq_holiday_date (holiday_date)'),
    substr_count($migration, 'CONSTRAINT ck_holiday_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)'),
], 'migration 027: feriados com data unica, nome, abrangencia todos e row_version (D-ESC-16)');
assert_same(0, preg_match('/\b(INSERT\s+INTO|DELETE\s+FROM|DROP\s+TABLE|DROP\s+COLUMN|TRUNCATE)\b/i', $migration), 'migration 027: nada apagado nem inserido (o unico UPDATE e a cor padrao)');
assert_same(1, preg_match_all('/\bUPDATE\s+(?!CURRENT_TIMESTAMP)\w+/i', $migration), 'migration 027: um UPDATE so (fora o ON UPDATE do updated_at)');

assert_same(2, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 027: duas paradas (copia anterior e rodizio com vaga repetida)');
preg_match_all("/MESSAGE_TEXT = '([^']*)'/", $rollback, $messages);
assert_same([true, true], array_map(static fn (string $m): bool => strlen($m) <= 128 && str_starts_with($m, 'rollback_027 abortado: '), $messages[1]), 'rollback 027: mensagens do SIGNAL ate 128 caracteres (limite do MySQL) e com o prefixo');
assert_same([1, 1, 1], [
    substr_count($rollback, "    IF repetidos > 0 THEN\n        SIGNAL SQLSTATE '45000'"),
    substr_count($rollback, "SET @copy_color_sql := IF(\n    @has_color = 1,"),
    substr_count($rollback, "SET @copy_banco_sql := IF(\n    @has_banco = 1,"),
], 'rollback 027: para com qualquer rodizio repetido; as copias da cor e do banco de horas rodam sempre que a coluna e o valor existem');
assert_same(1, substr_count($rollback, "WHERE JSON_LENGTH(r.roles) <> (\n               SELECT COUNT(DISTINCT j.role_id)\n                 FROM JSON_TABLE(r.roles, '$[*]' COLUMNS (role_id INT PATH '$')) AS j);"), 'rollback 027: a parada conta o rodizio com papel repetido');
$positions = [
    strpos($rollback, 'CALL rollback_027_confere();'),
    strpos($rollback, "'RENAME TABLE portal_holidays TO portal_holidays_bkp_027'"),
    strpos($rollback, "'CREATE TABLE portal_absences_banco_horas_bkp_027 AS SELECT id FROM portal_absences WHERE category = ''banco_horas'''"),
    strpos($rollback, "'UPDATE portal_absences SET category = ''outro'' WHERE category = ''banco_horas'''"),
    strpos($rollback, "MODIFY COLUMN category ENUM({$oldCategories}) NOT NULL"),
    strpos($rollback, "'ALTER TABLE portal_critical_role_rotations DROP CHECK ck_critical_rotation_roles'"),
    strpos($rollback, "\"uniqueItems\": true"),
    strpos($rollback, "'CREATE TABLE portal_critical_roles_color_bkp_027 AS SELECT id, color_token FROM portal_critical_roles WHERE color_token IS NOT NULL'"),
    strpos($rollback, "'ALTER TABLE portal_critical_roles DROP CHECK ck_critical_role_color'"),
    strpos($rollback, "'ALTER TABLE portal_critical_roles DROP COLUMN color_token'"),
];
$sorted = $positions;
sort($sorted);
assert_same([false, $sorted], [in_array(false, $positions, true), $positions], 'rollback 027: confere, guarda os feriados, copia e converte o banco de horas, volta o ENUM e a CHECK, copia a cor e so entao tira a coluna');
assert_same(1, preg_match_all('/\bUPDATE\s+\w+/i', $rollback), 'rollback 027: a unica linha alterada e o banco de horas que vira outro (com copia antes)');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP\s+TABLE)\b/i', $rollback), 'rollback 027: nenhuma linha nem tabela apagada');

assert_same(3, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio 027: cada comando so no banco ensaio_');
assert_same([1, 1, 1], [
    substr_count($lines, "'Feriado de ensaio', 'todos', 'ensaio', 'ensaio'"),
    substr_count($lines, "SET color_token = 'turquesa'"),
    substr_count($lines, "'Ficticio', 'critico', 'banco_horas'"),
], 'linhas do ensaio 027: um feriado, uma cor e uma ausencia de banco de horas, todos ficticios');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP)\b/i', qa_sql_without_comments($lines)), 'linhas do ensaio 027: nada apagado');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 027 so le');
foreach (['colunas_027', 'checks_027', 'tabela_027', 'enum_027', 'copias_de_rollback_027', 'papeis_com_cor', 'rodizios_com_vaga_repetida', 'feriados', 'ausencias_banco_horas', 'cor_fora_da_lista', 'feriados_repetidos'] as $column) {
    assert_same(1, preg_match('/\bAS ' . $column . '\b/', $check), "verificacao 027: conta {$column}");
}
assert_same(0, preg_match('/\b(name|nome|employee_name|created_by|updated_by)\b/', (string)preg_replace("/WHERE TABLE_SCHEMA.*$/m", '', qa_sql_without_comments((string)preg_replace('/AND CONSTRAINT_NAME[^\n]*/', '', $check)))), 'verificacao 027: nenhum nome nem login na saida');
assert_same(1, substr_count($check, "BIT_XOR(CRC32(CONCAT_WS('|', id, presence_type, work_start, work_end, active, sort_order)))"), 'verificacao 027: assinatura dos papeis sem a cor e sem o nome');

echo 'QA critical grid OK (' . $GLOBALS['qa_checks'] . " verificacoes)\n";
