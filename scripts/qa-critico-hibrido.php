<?php
declare(strict_types=1);

// ============================================================================
// QA das equipes Critico e Hibrido (F9; desenho principal, 9.15)
//
// Sem banco:
// 1. estrutura da migration 020, do rollback, das linhas do ensaio e da
//    consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh,
// registrado na 9.15) e no roteiro do deploy 5.
//
// Uso: php scripts/qa-critico-hibrido.php
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
$position = static fn (string $sql, string $needle): int => ($found = strpos($sql, $needle)) === false ? -1 : $found;

// ----------------------------------------------------------------------------
// 1. Migration 020, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migrationRaw = (string)file_get_contents($root . '/migrations/20261116_020_teams_critico_hibrido.sql');
$rollbackRaw = (string)file_get_contents($root . '/migrations/rollback/20261116_020_teams_critico_hibrido_down.sql');
$linesRaw = (string)file_get_contents($root . '/migrations/rollback/20261116_020_ensaio_linhas_f9.sql');
$checkRaw = (string)file_get_contents($root . '/migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql');
$migration = qa_sql_without_comments($migrationRaw);
$rollback = qa_sql_without_comments($rollbackRaw);
$lines = qa_sql_without_comments($linesRaw);
$check = qa_sql_without_comments($checkRaw);
assert_same(true, $migration !== '' && $rollback !== '' && $lines !== '' && $check !== '', '020: le a migration, o rollback, as linhas do ensaio e a verificacao');

foreach (['migration' => $migration, 'rollback' => $rollback, 'linhas do ensaio' => $lines] as $label => $sql) {
    assert_same(1, preg_match("/^\\s*SET time_zone = 'America\\/Sao_Paulo';/", $sql), "020 {$label}: comeca com SET time_zone (regra do A4)");
    assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $sql), "020 {$label}: sem relogio do banco (regra do A4)");
    assert_same(0, preg_match('/\b(id|employee_id|id_funcionario)\s*(=|IN)\s*\(?\s*\d/i', $sql), "020 {$label}: sem ID fixo");
    assert_same(0, preg_match('/\b(nome|nome_funcionario|employee_name|ad_login)\s*(=|LIKE|IN)\s*\(?\s*\'/i', $sql), "020 {$label}: sem nome ou login fixo");
}

// As oito tabelas com ENUM de equipe: valores de hoje na mesma posicao, os novos no fim.
$withLeadership = ['portal_schedule_rules', 'portal_schedule_exceptions', 'portal_pa_assignments', 'portal_absences', 'portal_shift_attachments'];
$withoutLeadership = ['portal_overtime_entries', 'portal_time_adjustments', 'portal_oncall_shifts'];
$tables = array_merge($withLeadership, $withoutLeadership);
$enum = static fn (bool $leadership, bool $wide): string => "'''n1'', ''n2''" . ($leadership ? ", ''lideranca''" : '') . ($wide ? ", ''critico'', ''hibrido''" : '') . "'";
$expectedAlgorithm = ['portal_absences' => 'COPY'];
foreach ($tables as $table) {
    $leadership = in_array($table, $withLeadership, true);
    $nullability = $table === 'portal_shift_attachments' ? "'NULL DEFAULT NULL COMMENT ''Equipe que ve o anexo; NULL = todas as equipes'''" : "'NOT NULL'";
    $algorithm = $expectedAlgorithm[$table] ?? 'INSTANT';
    assert_same(1, substr_count($migration, "CALL migration_020_amplia_equipe('{$table}', " . $enum($leadership, true) . ", {$nullability}, '{$algorithm}');"), "020: {$table} amplia o ENUM uma vez, com os valores novos no fim e ALGORITHM={$algorithm}");
    assert_same(1, substr_count($rollback, "CALL rollback_020_estreita_equipe('{$table}', " . $enum($leadership, false) . ", {$nullability});"), "rollback 020: {$table} volta ao ENUM de antes");
}
assert_same([8, 8], [substr_count($migration, 'CALL migration_020_amplia_equipe('), substr_count($rollback, 'CALL rollback_020_estreita_equipe(')], '020: exatamente as oito tabelas, na migration e no rollback');
assert_same([0, 0], [substr_count($migration, 'portal_pa_map'), substr_count($rollback, 'portal_pa_map')], '020: a legada portal_pa_map fica como esta (D-F9-4)');
assert_same(1, preg_match("/IF tipo_atual IS NOT NULL AND LOCATE\\('''critico''', tipo_atual\\) = 0 THEN\\s+SET @migration_020_sql := CONCAT\\('ALTER TABLE ', tabela, ' MODIFY COLUMN team ENUM\\(', valores, '\\) ', nulidade, ', ALGORITHM=', algoritmo\\);/", $migration), '020: so altera a tabela que existe e ainda nao tem critico (idempotente), com o algoritmo explicito');
assert_same(1, preg_match("/IF tipo_atual IS NOT NULL AND LOCATE\\('''critico''', tipo_atual\\) > 0 THEN/", $rollback), 'rollback 020: so estreita a tabela que tem critico');
assert_same(true, $position($migration, 'DROP PROCEDURE IF EXISTS migration_020_amplia_equipe;') < $position($migration, 'CREATE PROCEDURE migration_020_amplia_equipe(') && $position($migration, 'DROP PROCEDURE migration_020_amplia_equipe;') > $position($migration, "CALL migration_020_amplia_equipe('portal_oncall_shifts'"), '020: procedimento temporario recriado a cada execucao e apagado no fim');
assert_same(1, preg_match("/'ALTER TABLE pausas\\s+ADD COLUMN limite_segundos INT UNSIGNED NULL\\s+COMMENT ''[^']+''\\s+AFTER alerta_20min,\\s+ALGORITHM=INSTANT',\\s+'SELECT 1'/", $migration), '020: pausas.limite_segundos nulo, sem padrao, so metadado, dentro do IF');
assert_same(true, $position($migration, "TABLE_NAME = 'pausas' AND COLUMN_NAME = 'limite_segundos'") > -1 && $position($migration, "TABLE_NAME = 'pausas' AND COLUMN_NAME = 'limite_segundos'") < $position($migration, 'ADD COLUMN limite_segundos'), '020: confere a coluna no information_schema antes de criar');
assert_same(1, preg_match("/SET @add_limite_segundos_sql := IF\\(\\s+@has_limite_segundos = 0,\\s+'ALTER TABLE pausas/", $migration), '020: a coluna so e criada se ainda nao existe (idempotente)');
assert_same(0, preg_match('/limite_segundos[^,\n]*DEFAULT/i', $migration), '020: limite_segundos sem DEFAULT (as pausas de hoje ficam NULL)');
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/mi', $migration), '020 nao altera linha de dado');
foreach (['migration' => $migration, 'rollback' => $rollback] as $label => $sql) {
    assert_same(0, preg_match('/^\s*(ALTER|CREATE) TABLE/mi', $sql), "020 {$label}: todo ALTER e CREATE de tabela fica dentro de IF ou de procedimento com checagem em information_schema (idempotente)");
    $prepares = preg_match_all('/^\s*PREPARE /m', $sql);
    assert_same(true, $prepares > 0, "020 {$label}: usa PREPARE");
    assert_same([$prepares, $prepares], [preg_match_all('/^\s*EXECUTE /m', $sql), preg_match_all('/^\s*DEALLOCATE PREPARE /m', $sql)], "020 {$label}: todo PREPARE tem EXECUTE e DEALLOCATE");
    assert_same(0, preg_match('/\bDROP TABLE\b|\bTRUNCATE\b/i', $sql), "020 {$label}: nenhuma tabela apagada");
}
assert_same([1, 1], [preg_match('/^-- Rollback: migrations\/rollback\/20261116_020_teams_critico_hibrido_down\.sql\.$/m', $migrationRaw), preg_match('/^-- Verificacao: migrations\/verificacao\/20261116_020_teams_critico_hibrido_check\.sql\.$/m', $migrationRaw)], '020: cabecalho aponta o rollback e a verificacao');
assert_same(1, preg_match('/sudo env DADOS=portal_absences,pausas \\\\\n--\s+VERIFICA=migrations\/verificacao\/20261116_020_teams_critico_hibrido_check\.sql \\\\\n--\s+bash scripts\/ensaio-migration\.sh f9_020 \\\\\n--\s+migrations\/20261116_020_teams_critico_hibrido\.sql \\\\\n--\s+migrations\/rollback\/20261116_020_teams_critico_hibrido_down\.sql \\\\\n--\s+portal_schedule_rules,portal_schedule_exceptions,portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log$/m', $migrationRaw), '020: cabecalho com o comando do ensaio (ausencias e pausas com dados, as outras seis na estrutura)');

// Rollback: para antes de alterar se existir a copia ou linha com equipe nova; copia; tira a coluna; estreita.
assert_same(1, preg_match("/IF \\(SELECT COUNT\\(\\*\\) FROM information_schema\\.TABLES\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'pausas_limite_bkp_020'\\) > 0 THEN\\s+SIGNAL SQLSTATE '45000'/", $rollback), 'rollback 020: SIGNAL se a copia ja existir');
assert_same(1, preg_match("/IF \\(SELECT COUNT\\(\\*\\) FROM funcionarios WHERE LOWER\\(TRIM\\(equipe\\)\\) IN \\('critico', 'hibrido'\\)\\)/", $rollback), 'rollback 020: confere os cadastros de Critico e Hibrido');
foreach ($tables as $table) {
    assert_same(1, substr_count($rollback, "(SELECT COUNT(*) FROM {$table} WHERE team IN ('critico', 'hibrido'))"), "rollback 020: confere linhas com equipe nova em {$table} (D-F9-15)");
}
preg_match_all("/SET MESSAGE_TEXT = '(rollback_020 abortado: [^']+)'/", $rollback, $signalMessages);
assert_same(2, count($signalMessages[1]), 'rollback 020: duas paradas (copia e linha com equipe nova)');
foreach ($signalMessages[1] as $message) {
    assert_same(true, strlen($message) <= 128 && strpos($message, 'Nada foi alterado') !== false, 'rollback 020: mensagem clara e dentro do limite de 128 do SIGNAL: ' . $message);
}
$abortAt = $position($rollback, 'CALL rollback_020_confere();');
$firstChange = min(array_filter([$position($rollback, "'CREATE TABLE pausas_limite_bkp_020"), $position($rollback, "'ALTER TABLE"), $position($rollback, "CONCAT('ALTER TABLE")], static fn (int $at): bool => $at > -1));
assert_same(true, $abortAt > -1 && $abortAt < $firstChange && $position($rollback, 'DROP PROCEDURE rollback_020_confere;') < $firstChange && $position($rollback, 'DROP PROCEDURE IF EXISTS rollback_020_confere;') < $position($rollback, 'CREATE PROCEDURE rollback_020_confere()'), 'rollback 020: checagens antes de qualquer alteracao, procedimento recriado a cada execucao');
assert_same(1, preg_match("/'CREATE TABLE pausas_limite_bkp_020 AS\\s+SELECT id, limite_segundos FROM pausas WHERE limite_segundos IS NOT NULL'/", $rollback), 'rollback 020: copia so id e limite das pausas com limite (sem nome)');
assert_same(true, $position($rollback, "'CREATE TABLE pausas_limite_bkp_020") < $position($rollback, "'ALTER TABLE pausas DROP COLUMN limite_segundos'") && $position($rollback, "'ALTER TABLE pausas DROP COLUMN limite_segundos'") < $position($rollback, "CALL rollback_020_estreita_equipe("), 'rollback 020: copia, tira a coluna e estreita o ENUM, nessa ordem');
assert_same(0, preg_match('/CREATE TABLE IF NOT EXISTS/', $rollback), 'rollback 020: copia nunca e pulada em silencio');
assert_same(0, preg_match('/\b(nome|nome_funcionario|employee_name|ad_login)\b/i', $rollback), 'rollback 020: sem nome nem login');
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/mi', $rollback), 'rollback 020: nenhuma linha trocada por script (D-F9-15)');

// Linhas do ensaio: so no banco descartavel, idempotentes, sem cadastro fixo.
$writes = preg_match_all('/^\s*(INSERT INTO|UPDATE)\b/mi', $lines);
assert_same([2, 2], [$writes, preg_match_all("/WHERE DATABASE\\(\\) LIKE 'ensaio\\\\_%'/", $lines)], 'linhas do ensaio 020: cada gravacao so no banco ensaio_<nome>');
assert_same(true, strpos($lines, "AND NOT EXISTS (SELECT 1 FROM funcionarios c WHERE LOWER(TRIM(c.equipe)) = 'critico')") !== false && strpos($lines, "AND NOT EXISTS (SELECT 1 FROM pausas WHERE motivo_pausa = 'Almoço' AND equipe = 'critico')") !== false, 'linhas do ensaio 020: cada gravacao idempotente');
assert_same(true, strpos($lines, "'Almoço', 0, 0, 3600") !== false, 'linhas do ensaio 020: almoco do Critico sem alerta de 15/20 min e com o limite gravado');
assert_same(0, preg_match('/\b(DELETE|DROP|ALTER|TRUNCATE|REPLACE)\b/i', $lines), 'linhas do ensaio 020: nada apagado nem alterado no esquema');
assert_same(1, preg_match('/PREPARO=migrations\/20261116_020_teams_critico_hibrido\.sql/', $linesRaw), 'linhas do ensaio 020: cabecalho com o comando da variante');

// Verificacao: so le, so contagens, funciona antes da 020 e depois do rollback.
$checkWithoutPrepared = str_replace(["COLUMN_NAME = 'limite_segundos'", "COLUMN_NAME = 'grupo_escala'"], '', (string)preg_replace("/SET @\\w+_sql := IF\\(.*?\\);/s", '', $check));
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', $check), 'verificacao 020 so le');
assert_same(0, preg_match('/\b(employee_name|nome|nome_funcionario|ad_login|observacao_reuniao|note)\b/i', $check), 'verificacao 020 nao devolve nome, login nem observacao');
assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $check), 'verificacao 020 sem relogio do banco');
assert_same(0, preg_match('/limite_segundos|grupo_escala/', $checkWithoutPrepared), 'verificacao 020: as consultas da coluna nova e do grupo so dentro do IF (rodam so com elas presentes)');
assert_same([1, 1], [preg_match_all("/IF\\(\\s*@tem_020 = 1,/", $check), preg_match_all("/IF\\(\\s*@tem_grupo = 1,/", $check)], "verificacao 020: limite so com a coluna, grupo so com a 021, senao 'ausente'");
assert_same(3, preg_match_all('/BIT_XOR\(CRC32\(CONCAT_WS\(/', $check), 'verificacao 020: assinaturas das pausas, ausencias e regras (colunas anteriores a 020)');
foreach (['enums_com_critico_020', 'enum_legado_pa_map_com_critico', 'coluna_020', 'checks_ausencias', 'copias_de_rollback_020', 'ativos_equipe_desconhecida', 'almoco_fora_do_critico', 'almoco_com_alerta', 'pausas_com_limite', 'limite_em_motivo_sem_limite', 'critico_ou_hibrido_no_grupo_comum'] as $column) {
    assert_same(true, strpos($check, "AS {$column}") !== false, "verificacao 020: conta {$column}");
}

echo 'QA critico e hibrido OK (' . ($GLOBALS['qa_checks'] ?? 0) . " verificacoes)\n";
