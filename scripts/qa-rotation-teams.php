<?php
declare(strict_types=1);

// ============================================================================
// QA dos times de rodizio (F10-2; desenho do F10, secoes 3.1 e 3.2)
//
// Sem banco:
// 1. estrutura da migration 021, do rollback, das linhas do ensaio e da
//    consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh,
// registrado no desenho, 8.6) e no roteiro do deploy 4.
//
// Uso: php scripts/qa-rotation-teams.php
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
// 1. Migration 021, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migrationRaw = (string)file_get_contents($root . '/migrations/20261115_021_rotation_teams.sql');
$rollbackRaw = (string)file_get_contents($root . '/migrations/rollback/20261115_021_rotation_teams_down.sql');
$linesRaw = (string)file_get_contents($root . '/migrations/rollback/20261115_021_ensaio_linhas_f10.sql');
$checkRaw = (string)file_get_contents($root . '/migrations/verificacao/20261115_021_rotation_teams_check.sql');
$migration = qa_sql_without_comments($migrationRaw);
$rollback = qa_sql_without_comments($rollbackRaw);
$lines = qa_sql_without_comments($linesRaw);
$check = qa_sql_without_comments($checkRaw);
assert_same(true, $migration !== '' && $rollback !== '' && $lines !== '' && $check !== '', '021: le a migration, o rollback, as linhas do ensaio e a verificacao');

foreach (['migration' => $migration, 'rollback' => $rollback, 'linhas do ensaio' => $lines] as $label => $sql) {
    assert_same(1, preg_match("/^\\s*SET time_zone = 'America\\/Sao_Paulo';/", $sql), "021 {$label}: comeca com SET time_zone (regra do A4)");
    // O relogio do banco so como DEFAULT de coluna de autoria (esquema), nunca em dado.
    $withoutDefaults = (string)preg_replace('/DEFAULT CURRENT_TIMESTAMP( ON UPDATE CURRENT_TIMESTAMP)?/', '', $sql);
    assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $withoutDefaults), "021 {$label}: sem relogio do banco (regra do A4)");
    assert_same(0, preg_match('/\b(id|employee_id|team_id)\s*(=|IN)\s*\(?\s*\d/i', $sql), "021 {$label}: sem ID fixo");
    assert_same(0, preg_match('/\b(nome|employee_name|ad_login)\s*(=|LIKE|IN)\s*\(?\s*\'/i', $sql), "021 {$label}: sem nome ou login fixo");
}
foreach (['migration' => $migration, 'rollback' => $rollback] as $label => $sql) {
    assert_same(0, preg_match('/^\s*(ALTER|CREATE|DROP) TABLE/mi', $sql), "021 {$label}: todo ALTER, CREATE e DROP de tabela fica dentro de IF com checagem em information_schema (idempotente)");
    $prepares = preg_match_all('/^PREPARE /m', $sql);
    assert_same(true, $prepares > 0, "021 {$label}: usa PREPARE");
    assert_same([$prepares, $prepares], [preg_match_all('/^EXECUTE /m', $sql), preg_match_all('/^DEALLOCATE PREPARE /m', $sql)], "021 {$label}: todo PREPARE tem EXECUTE e DEALLOCATE");
}

// Migration: tres tabelas, a coluna das regras com FK, o grupo da escala; nenhuma linha alterada.
foreach (['portal_rotation_teams', 'portal_rotation_team_versions', 'portal_rotation_team_members'] as $table) {
    assert_same(1, substr_count($migration, "'CREATE TABLE {$table} ("), "021: cria {$table} uma vez, dentro do IF");
    assert_same(true, $position($migration, "TABLE_NAME = '{$table}'") > -1 && $position($migration, "TABLE_NAME = '{$table}'") < $position($migration, "'CREATE TABLE {$table} ("), "021: confere {$table} no information_schema antes de criar");
}
assert_same(true, $position($migration, "'CREATE TABLE portal_rotation_teams (") < $position($migration, "'CREATE TABLE portal_rotation_team_versions (") && $position($migration, "'CREATE TABLE portal_rotation_team_versions (") < $position($migration, "'CREATE TABLE portal_rotation_team_members (") && $position($migration, "'CREATE TABLE portal_rotation_team_members (") < $position($migration, 'ADD CONSTRAINT fk_schedule_rule_rotation_team'), '021: times, versoes, membros e a FK das regras, nessa ordem (FK so para tabela que ja existe)');
assert_same(1, preg_match("/UNIQUE KEY uq_rotation_team_name \\(name\\)/", $migration), '021: nome do time unico');
assert_same(1, preg_match("/UNIQUE KEY uq_rotation_version_team_from \\(team_id, effective_from\\)/", $migration), '021: uma versao por time e data de inicio');
assert_same(1, preg_match("/CHECK \\(JSON_SCHEMA_VALID\\(''\\{\"type\": \"array\", \"minItems\": 2, \"maxItems\": 20, \"items\": \\{\"enum\": \\[\"onsite\", \"remote\"\\]\\}\\}'', pattern\\)\\)/", $migration), '021: padrao de 2 a 20 posicoes onsite/remote no banco (o limite do motor)');
assert_same(1, preg_match('/CONSTRAINT ck_rotation_version_anchor CHECK \(WEEKDAY\(anchor_date\) < 5\)/', $migration), '021: ancora em dia util');
assert_same(1, preg_match('/CONSTRAINT ck_rotation_version_weeks CHECK \(weeks IS NULL OR \(weeks BETWEEN 1 AND 4 AND JSON_LENGTH\(pattern\) = 5 \* weeks AND WEEKDAY\(anchor_date\) = 0\)\)/', $migration), '021: semana a semana com 1 a 4 semanas, 5 posicoes por semana e ancora na segunda (desenho 3.2)');
assert_same(1, preg_match('/GENERATED ALWAYS AS \(CASE WHEN valid_until IS NULL THEN employee_id ELSE NULL END\) STORED/', $migration), '021: coluna gerada da pertenca aberta');
assert_same(true, $position($migration, 'UNIQUE KEY uq_rotation_member_open (open_employee_id)') > -1 && $position($migration, 'UNIQUE KEY uq_rotation_member_employee_from (employee_id, valid_from)') > -1, '021: uma pertenca aberta por pessoa e um inicio por data');
assert_same(1, preg_match('/CONSTRAINT ck_rotation_member_period CHECK \(valid_until IS NULL OR valid_until >= valid_from\)/', $migration), '021: fim da pertenca nunca antes do inicio');
assert_same(4, preg_match_all('/REFERENCES (portal_rotation_teams|funcionarios) \(id\) ON DELETE RESTRICT/', $migration), '021: quatro FKs (versoes e membros para o time, membros para o cadastro, regras para o time)');
assert_same(4, preg_match_all('/ON DELETE RESTRICT/', $migration), '021: toda FK com ON DELETE RESTRICT (versoes, membros x2, regras)');
assert_same(0, preg_match('/ON DELETE (CASCADE|SET NULL)/i', $migration), '021: nenhum apagamento em cascata');
assert_same(1, preg_match("/ADD COLUMN rotation_team_id INT UNSIGNED NULL/", $migration), '021: rotation_team_id nulo (as regras de hoje ficam NULL)');
assert_same(true, $position($migration, 'ADD COLUMN rotation_team_id') < $position($migration, 'ADD INDEX idx_schedule_rule_rotation_team (rotation_team_id)') && $position($migration, 'ADD INDEX idx_schedule_rule_rotation_team (rotation_team_id)') < $position($migration, 'ADD CONSTRAINT fk_schedule_rule_rotation_team'), '021: coluna, indice e FK das regras, nessa ordem');
assert_same(1, preg_match("/ADD COLUMN grupo_escala ENUM\\(''comum'', ''separado''\\) NOT NULL DEFAULT ''comum''/", $migration), "021: grupo_escala com 'comum' para todos");
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/mi', $migration), '021 nao altera linha de dado');
assert_same([1, 1], [preg_match('/^-- Rollback: migrations\/rollback\/20261115_021_rotation_teams_down\.sql\.$/m', $migrationRaw), preg_match('/^-- Verificacao: migrations\/verificacao\/20261115_021_rotation_teams_check\.sql\.$/m', $migrationRaw)], '021: cabecalho aponta o rollback e a verificacao');
assert_same(1, preg_match('/bash scripts\/ensaio-migration\.sh f10_021 \\\\\n--\s+migrations\/20261115_021_rotation_teams\.sql \\\\\n--\s+migrations\/rollback\/20261115_021_rotation_teams_down\.sql audit_log/', $migrationRaw), '021: cabecalho com o comando do ensaio');

// Rollback: para antes de alterar se existir copia; copia o que existe; tira
// FK, indice e coluna; as tabelas saem por ultimo (FK).
$backups = ['portal_rotation_teams_bkp_021', 'portal_rotation_team_versions_bkp_021', 'portal_rotation_team_members_bkp_021', 'portal_schedule_rules_rotation_bkp_021', 'funcionarios_grupo_escala_bkp_021'];
assert_same(1, preg_match("/IF \\(SELECT COUNT\\(\\*\\) FROM information_schema\\.TABLES\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\)\\s+AND TABLE_NAME IN \\('" . implode("', '", $backups) . "'\\)\\) > 0 THEN\\s+SIGNAL SQLSTATE '45000'/", $rollback), 'rollback 021: SIGNAL se qualquer copia ja existir');
assert_same(1, preg_match("/SET MESSAGE_TEXT = '(rollback_021 abortado: [^']+)'/", $rollback, $signalMessage), 'rollback 021: mensagem da parada');
assert_same(true, strlen($signalMessage[1]) <= 128 && strpos($signalMessage[1], 'Nada foi alterado') !== false, 'rollback 021: mensagem clara e dentro do limite de 128 do SIGNAL');
assert_same(true, $position($rollback, 'DROP PROCEDURE IF EXISTS rollback_021_confere_copias;') < $position($rollback, 'CREATE PROCEDURE rollback_021_confere_copias()'), 'rollback 021: procedimento de checagem recriado a cada execucao');
$abortAt = $position($rollback, 'CALL rollback_021_confere_copias();');
$firstChange = min(array_filter([$position($rollback, "'CREATE TABLE portal_rotation_teams_bkp_021"), $position($rollback, "'ALTER TABLE"), $position($rollback, "'DROP TABLE")], static fn (int $at): bool => $at > -1));
assert_same(true, $abortAt > -1 && $abortAt < $firstChange && $position($rollback, 'DROP PROCEDURE rollback_021_confere_copias;') < $firstChange, 'rollback 021: checagem das copias antes de qualquer alteracao');
foreach ($backups as $backup) {
    assert_same(1, substr_count($rollback, "'CREATE TABLE {$backup} AS"), "rollback 021: copia para {$backup}");
    assert_same(true, $position($rollback, "'CREATE TABLE {$backup} AS") < $position($rollback, "'ALTER TABLE") && $position($rollback, "'CREATE TABLE {$backup} AS") < $position($rollback, "'DROP TABLE"), "rollback 021: {$backup} antes de alterar");
}
assert_same(0, preg_match('/CREATE TABLE IF NOT EXISTS/', $rollback), 'rollback 021: copia nunca e pulada em silencio (IF NOT EXISTS mascararia a copia antiga)');
assert_same(0, preg_match('/open_employee_id/', substr($rollback, $position($rollback, "'CREATE TABLE portal_rotation_team_members_bkp_021 AS"), 400)), 'rollback 021: copia dos membros sem a coluna gerada');
assert_same(1, preg_match("/SELECT id, grupo_escala FROM funcionarios WHERE grupo_escala <> ''comum''/", $rollback), 'rollback 021: copia do grupo so com o id de quem nao esta no comum');
assert_same(0, preg_match('/\b(nome|employee_name|ad_login)\b/i', $rollback), 'rollback 021: copia sem nome nem login');
assert_same(true, $position($rollback, 'DROP FOREIGN KEY fk_schedule_rule_rotation_team') < $position($rollback, 'DROP INDEX idx_schedule_rule_rotation_team') && $position($rollback, 'DROP INDEX idx_schedule_rule_rotation_team') < $position($rollback, 'DROP COLUMN rotation_team_id'), 'rollback 021: FK, indice e coluna das regras, nessa ordem');
assert_same(true, $position($rollback, "'DROP TABLE portal_rotation_team_members'") < $position($rollback, "'DROP TABLE portal_rotation_team_versions'") && $position($rollback, "'DROP TABLE portal_rotation_team_versions'") < $position($rollback, "'DROP TABLE portal_rotation_teams'") && $position($rollback, 'DROP COLUMN rotation_team_id') < $position($rollback, "'DROP TABLE portal_rotation_teams'"), 'rollback 021: membros, versoes e times por ultimo, depois da FK das regras');
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/mi', $rollback), 'rollback 021: nenhuma linha das regras alterada ou apagada (as regras gravadas pelos times continuam validas)');

// Linhas do ensaio: so no banco descartavel, idempotentes, sem cadastro fixo.
$writes = preg_match_all('/^\s*(INSERT INTO|UPDATE)\b/mi', $lines);
assert_same([5, 5], [$writes, preg_match_all("/WHERE DATABASE\\(\\) LIKE 'ensaio\\\\_%'/", $lines)], 'linhas do ensaio 021: cada gravacao so no banco ensaio_<nome>');
assert_same(3, preg_match_all('/AND NOT EXISTS \(SELECT 1 FROM portal_rotation_/', $lines), 'linhas do ensaio 021: cada INSERT idempotente');
assert_same(true, strpos($lines, 'AND r.rotation_team_id IS NULL') !== false && strpos($lines, "AND f.grupo_escala = 'comum'") !== false, 'linhas do ensaio 021: cada UPDATE idempotente');
assert_same(0, preg_match('/\b(DELETE|DROP|ALTER|TRUNCATE|REPLACE)\b/i', $lines), 'linhas do ensaio 021: nada apagado nem alterado no esquema');
assert_same(1, preg_match('/PREPARO=migrations\/20261115_021_rotation_teams\.sql/', $linesRaw), 'linhas do ensaio 021: cabecalho com o comando da variante');

// Verificacao: so le, so contagens, funciona antes da 021 e depois do rollback.
$checkWithoutPrepared = (string)preg_replace("/SET @\\w+_sql := IF\\(.*?\\);/s", '', $check);
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', $check), 'verificacao 021 so le');
assert_same(0, preg_match('/\b(employee_name|nome|ad_login|note|name)\b/i', $check), 'verificacao 021 nao devolve nome, login nem observacao');
assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $check), 'verificacao 021 sem relogio do banco');
assert_same(0, preg_match('/portal_rotation_team_members m|portal_rotation_teams\)|grupo_escala, ativo/', $checkWithoutPrepared), 'verificacao 021: as consultas das tabelas e colunas novas so dentro do IF (rodam so com a 021 presente)');
assert_same(2, preg_match_all("/IF\\(\\s*@tem_021 = 5,/", $check), "verificacao 021: contagens dos times e do grupo so com as 3 tabelas e as 2 colunas presentes, senao '021 ausente'");
assert_same(2, preg_match_all('/BIT_XOR\(CRC32\(CONCAT_WS\(/', $check), 'verificacao 021: assinaturas das regras e dos cadastros (colunas anteriores a 021)');
assert_same(0, preg_match('/rotation_team_id|grupo_escala/', (string)substr($check, $position($check, "SELECT 'regras' AS tabela"))), 'verificacao 021: assinaturas sem as colunas novas (iguais antes, com e depois do rollback)');
foreach (['pessoas_com_mais_de_uma_aberta', 'pertencas_sobrepostas', 'membros_abertos_sem_regra_aberta_do_time', 'regras_de_time_sem_membro', 'copias_de_rollback_021'] as $column) {
    assert_same(true, strpos($check, "AS {$column}") !== false, "verificacao 021: conta {$column}");
}

fwrite(STDOUT, 'QA dos times de rodizio: ' . $GLOBALS['qa_checks'] . " verificacoes OK\n");
