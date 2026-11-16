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

// ----------------------------------------------------------------------------
// 2. Padrao explicito do ciclo (CyclePattern, funcao pura)
// ----------------------------------------------------------------------------
require_once $root . '/services/CyclePattern.php';
require_once $root . '/services/RotationTeamPlanner.php';

$qaOutcome = static function (callable $call): string {
    try {
        $call();
        return 'ok';
    } catch (PortalForbiddenException $e) {
        return '403: ' . $e->getMessage();
    } catch (InvalidArgumentException $e) {
        return '400: ' . $e->getMessage();
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
};
$wk = static fn (string $days): array => array_map(static fn (string $d): string => $d === 'P' ? 'onsite' : 'remote', str_split($days));
$letters = static fn (array $pattern): string => implode('', array_map(static fn (string $v): string => $v === 'onsite' ? 'P' : 'R', $pattern));

assert_same([true, true, false], [CyclePattern::isExplicit(['pattern' => []]), CyclePattern::isExplicit(['weeks' => []]), CyclePattern::isExplicit(['onsite_days' => 2])], 'ciclo: modo novo so com pattern ou weeks (os blocos ficam no caminho de antes)');
$parsed = CyclePattern::fromPayload(['weeks' => [$wk('PPRPR'), $wk('RPPPP'), $wk('PPPPR')], 'anchor_date' => '2026-09-28']);
assert_same(['PPRPRRPPPPPPPPR', 3, '2026-09-28'], [$letters($parsed['pattern']), $parsed['weeks'], $parsed['anchor_date']], 'ciclo: semanas concatenadas em ordem (desenho 2.2: 5 x N posicoes)');
assert_same('{"pattern":["remote","onsite"],"anchor_date":"2026-10-05","business_days_only":true}', CyclePattern::config(['remote', 'onsite'], '2026-10-05', null), 'ciclo: configuracao igual a dos blocos sem weeks (JSON em ordem fixa)');
assert_same('{"pattern":["remote","onsite","onsite","onsite","onsite"],"anchor_date":"2026-10-05","business_days_only":true,"weeks":1}', CyclePattern::config($wk('RPPPP'), '2026-10-05', 1), 'ciclo: weeks no fim, so no semana a semana');
assert_same(['Ciclo 2P/1R', 'Ciclo 1 semana, 4P/1R', 'Ciclo 3 semanas, 11P/4R'], [CyclePattern::label($wk('RPP'), null), CyclePattern::label($wk('RPPPP'), 1), CyclePattern::label($parsed['pattern'], 3)], 'ciclo: rotulo "Ciclo 3 semanas, 11P/4R" (desenho 3.2) e o do motor sem semanas');
foreach ([
    [['weeks' => 2, 'pattern' => array_fill(0, 10, 'onsite')], 2],
    [['weeks' => 2, 'pattern' => array_fill(0, 9, 'onsite')], null],
    [['weeks' => 5, 'pattern' => array_fill(0, 25, 'onsite')], null],
    [['weeks' => '2', 'pattern' => array_fill(0, 10, 'onsite')], null],
    [['pattern' => array_fill(0, 10, 'onsite')], null],
    [null, null],
] as $i => [$config, $expected]) {
    assert_same($expected, CyclePattern::weeksFromConfig($config), "ciclo: weeks so valido conta no rotulo (caso {$i})");
}
foreach ([
    'sem data' => [['pattern' => ['remote', 'onsite']], '400: Data inicial do ciclo inválida.'],
    'data invalida' => [['pattern' => ['remote', 'onsite'], 'anchor_date' => '2026-13-01'], '400: Data inicial do ciclo inválida.'],
    'semana como texto' => [['weeks' => 'PPRPR', 'anchor_date' => '2026-10-05'], '400: O ciclo semana a semana tem de 1 a 4 semanas.'],
    'semana 2 com 6 dias' => [['weeks' => [$wk('PPRPR'), $wk('PPRPRP')], 'anchor_date' => '2026-10-05'], '400: Semana 2 do ciclo: informe os 5 dias, de segunda a sexta.'],
    'semana como mapa' => [['weeks' => [['seg' => 'onsite', 'ter' => 'onsite', 'qua' => 'remote', 'qui' => 'onsite', 'sex' => 'onsite']], 'anchor_date' => '2026-10-05'], '400: Semana 1 do ciclo: informe os 5 dias, de segunda a sexta.'],
    'semanal no domingo' => [['weeks' => [$wk('PPRPR')], 'anchor_date' => '2026-10-04'], '400: No ciclo semana a semana, a data inicial precisa ser uma segunda-feira.'],
    'valor numerico' => [['pattern' => [1, 0], 'anchor_date' => '2026-10-05'], '400: Cada dia do ciclo é presencial (onsite) ou remoto (remote).'],
    'valor em maiusculas' => [['pattern' => ['ONSITE', 'remote'], 'anchor_date' => '2026-10-05'], '400: Cada dia do ciclo é presencial (onsite) ou remoto (remote).'],
    'sem dia remoto' => [['weeks' => [$wk('PPPPP')], 'anchor_date' => '2026-10-05'], '400: Ciclo sem nenhum dia remoto: use a regra "Sempre presencial".'],
    'sem dia presencial' => [['pattern' => ['remote', 'remote', 'remote'], 'anchor_date' => '2026-10-05'], '400: Ciclo sem nenhum dia presencial: use a regra "Sempre remoto".'],
] as $label => [$payload, $expected]) {
    assert_same($expected, $qaOutcome(fn () => CyclePattern::fromPayload($payload)), "ciclo: {$label}");
}
assert_same(20, count(CyclePattern::fromPayload(['weeks' => array_fill(0, 4, $wk('PPRPR')), 'anchor_date' => '2026-10-05'])['pattern']), 'ciclo: 4 semanas = 20 posicoes (o limite do motor)');

// ----------------------------------------------------------------------------
// 3. Planejador dos times (funcao pura): intencoes, dono, restauracao, prova
// ----------------------------------------------------------------------------
$v = static fn (string $from, array $pattern, string $anchor, ?int $weeks = null): array => ['id' => 0, 'team_id' => 7, 'effective_from' => $from, 'pattern' => $pattern, 'anchor_date' => $anchor, 'weeks' => $weeks];
$r = static fn (int $id, string $type, string $from, ?string $until, ?string $config = null, ?int $team = null): array => ['id' => $id, 'rule_type' => $type, 'rule_config' => $config, 'rotation_team_id' => $team, 'effective_from' => $from, 'effective_until' => $until];
$v1 = $v('2026-10-01', $wk('RPP'), '2026-10-01');
$v2 = $v('2026-12-01', $wk('PRP'), '2026-10-01');
assert_same([null, $v1, $v1, $v2], [RotationTeamPlanner::versionAt([$v1, $v2], '2026-09-30'), RotationTeamPlanner::versionAt([$v2, $v1], '2026-10-01'), RotationTeamPlanner::versionAt([$v1, $v2], '2026-11-30'), RotationTeamPlanner::versionAt([$v1, $v2], '2027-05-01')], 'planejador: versao vigente e a de maior inicio <= data, em qualquer ordem');
$cfg1 = RotationTeamPlanner::versionConfig($v1);
$cfg2 = RotationTeamPlanner::versionConfig($v2);
$members = [['id' => 1, 'team_id' => 7, 'employee_id' => 5, 'valid_from' => '2026-10-01', 'valid_until' => '2026-10-31'], ['id' => 2, 'team_id' => 8, 'employee_id' => 5, 'valid_from' => '2026-11-01', 'valid_until' => null]];
assert_same([1, 2, null], [RotationTeamPlanner::membershipAt($members, '2026-10-31')['id'] ?? null, RotationTeamPlanner::membershipAt($members, '2027-01-01')['id'] ?? null, RotationTeamPlanner::membershipAt($members, '2026-09-30')], 'planejador: pertenca que cobre a data (fim inclusive)');
assert_same([[1], [2], [1, 2]], [array_column(RotationTeamPlanner::overlapping($members, '2026-09-01', '2026-10-01'), 'id'), array_column(RotationTeamPlanner::overlapping($members, '2026-11-01', null), 'id'), array_column(RotationTeamPlanner::overlapping($members, '2026-10-31', '2026-11-01'), 'id')], 'planejador: sobreposicao de pertencas');

// Entrar no time com regra individual: encerra na vespera e grava a do time (dono 7).
$rules = [$r(10, 'always_onsite', '2026-09-01', null)];
$intents = RotationTeamPlanner::memberIntents($rules, [$v1, $v2], 7, '2026-11-16', null);
assert_same([['2026-11-16', $cfg1, 7], ['2026-12-01', $cfg2, 7]], array_map(static fn (array $i): array => [$i['from'], $i['rule_config'], $i['rotation_team_id']], $intents), 'planejador: uma intencao na entrada e uma em cada versao posterior');
$sim = RotationTeamPlanner::simulate($rules, $intents, '2026-11-15');
$byFrom = array_column($sim['rules'], null, 'effective_from');
assert_same([['2026-09-01', '2026-11-15', null], ['2026-11-16', '2026-11-30', 7], ['2026-12-01', null, 7]], array_map(static fn (array $x): array => [$x['effective_from'], $x['effective_until'], $x['rotation_team_id']], array_values($byFrom)), 'planejador: regra individual encerrada na vespera; regras do time com o dono, uma por versao');
assert_same([], ScheduleRulePlanner::violations($sim['rules']), 'planejador: historico sem violacao');
// Regra individual igual ao padrao do time passa a ser do time (o dono conta).
$same = [$r(11, 'cycle', '2026-11-16', null, $cfg1)];
$sim = RotationTeamPlanner::simulate($same, RotationTeamPlanner::memberIntents($same, [$v1], 7, '2026-11-16', null), '2026-11-15');
assert_same([[11, '2026-11-16', null, 7]], array_map(static fn (array $x): array => [$x['id'], $x['effective_from'], $x['effective_until'], $x['rotation_team_id']], $sim['rules']), 'planejador: mesma regra de outro dono no mesmo inicio e atualizada no lugar e passa a ser do time');
$same = [$r(11, 'cycle', '2026-10-05', null, $cfg1)];
$sim = RotationTeamPlanner::simulate($same, RotationTeamPlanner::memberIntents($same, [$v1], 7, '2026-11-16', null), '2026-11-15');
assert_same([['2026-10-05', '2026-11-15', null], ['2026-11-16', null, 7]], array_map(static fn (array $x): array => [$x['effective_from'], $x['effective_until'], $x['rotation_team_id']], $sim['rules']), 'planejador: mesma regra de outro dono antes da entrada e encerrada; a do time comeca na entrada');
$own = [$r(12, 'cycle', '2026-11-16', null, $cfg1, 7)];
assert_same([], RotationTeamPlanner::simulate($own, RotationTeamPlanner::memberIntents($own, [$v1], 7, '2026-11-16', null), '2026-11-15')['plans'], 'planejador: regra do proprio time igual nao muda (idempotente)');
// Regra individual agendada depois da entrada: incompativel (409); de time, substituida.
$later = [$r(10, 'always_onsite', '2026-09-01', '2026-12-31'), $r(13, 'always_remote', '2027-01-01', null)];
assert_same([13], array_column(RotationTeamPlanner::incompatibleRules($later, '2026-11-16', null), 'id'), 'planejador: regra individual agendada depois e incompativel');
assert_same([], RotationTeamPlanner::incompatibleRules($later, '2026-11-16', '2026-12-31'), 'planejador: regra individual depois da saida nao e incompativel');
$otherTeam = [$r(10, 'cycle', '2026-10-01', '2026-11-30', $cfg2, 8), $r(14, 'cycle', '2026-12-01', null, $cfg2, 8)];
assert_same([], RotationTeamPlanner::incompatibleRules($otherTeam, '2026-11-16', null), 'planejador: regra de outro time depois nao bloqueia');
$sim = RotationTeamPlanner::simulate($otherTeam, RotationTeamPlanner::memberIntents($otherTeam, [$v1], 7, '2026-11-16', null), '2026-11-15');
assert_same([[7], [$cfg1]], [array_values(array_unique(array_map(static fn (array $x): ?int => $x['effective_from'] >= '2026-11-16' ? $x['rotation_team_id'] : 7, $sim['rules']))), array_values(array_unique(array_map(static fn (array $x): ?string => $x['effective_from'] >= '2026-11-16' ? $x['rule_config'] : $cfg1, $sim['rules'])))], 'planejador: regras de outro time na faixa viram do time novo, com o padrao dele');
assert_same('409: O time não tem padrão em 15/09/2026: cadastre a versão do padrão antes.', $qaOutcome(fn () => RotationTeamPlanner::memberIntents([], [$v1], 7, '2026-09-15', null)), 'planejador: sem versao na entrada, 409');
// Pertenca encerrada: depois da saida, a regra que vigorava volta (sair nao muda a regra).
$former = [$r(20, 'cycle', '2026-10-01', null, $cfg1, 7)];
$intents = RotationTeamPlanner::memberIntents($former, [$v1, $v('2026-11-02', $wk('PPR'), '2026-10-01')], 7, '2026-11-02', '2026-11-20');
assert_same(['2026-11-02', '2026-11-21'], array_column($intents, 'from'), 'planejador: versao nova na pertenca encerrada: grava ate a saida e restaura no dia seguinte');
$sim = RotationTeamPlanner::simulate($former, $intents, '2026-11-01');
assert_same([$cfg1, RotationTeamPlanner::versionConfig($v('2026-11-02', $wk('PPR'), '2026-10-01')), $cfg1], array_map(static fn (string $d): ?string => ScheduleRulePlanner::ruleAt($sim['rules'], $d)['rule_config'] ?? null, ['2026-11-01', '2026-11-20', '2026-11-21']), 'planejador: antes, durante e depois da pertenca encerrada');
$noRuleAfter = [$r(21, 'always_onsite', '2026-09-01', '2026-10-31')];
$intents = RotationTeamPlanner::memberIntents($noRuleAfter, [$v1], 7, '2026-10-05', '2026-10-31');
assert_same(['from' => '2026-11-01', 'close_at' => '2026-10-31'], end($intents), 'planejador: sem regra depois da saida, a do time encerra na saida');
$sim = RotationTeamPlanner::simulate([], RotationTeamPlanner::memberIntents([], [$v1], 7, '2026-10-05', '2026-10-31'), '2026-10-01');
assert_same([['2026-10-05', '2026-10-31']], array_map(static fn (array $x): array => [$x['effective_from'], $x['effective_until']], $sim['rules']), 'planejador: sem regra nenhuma, a do time vale so na pertenca');

// Propriedade: historicos e versoes aleatorios. Depois da simulacao: sem
// violacao; antes da entrada, nada muda; na pertenca, toda data com regra do
// time e o padrao da versao daquela data; depois da saida, a escala de antes.
mt_srand(20261115);
$types = ['always_onsite', 'always_remote', 'even_days', 'fixed_weekdays'];
$propertyChecks = 0;
for ($round = 0; $round < 300; $round++) {
    $rules = [];
    $cursor = '2026-09-01';
    $id = 1;
    while ($cursor < '2027-03-01' && $id < 6) {
        $until = mt_rand(0, 3) === 0 ? null : ScheduleRulePlanner::addDays($cursor, mt_rand(10, 60));
        $owner = mt_rand(0, 2) === 0 ? mt_rand(7, 8) : null;
        $type = $owner !== null ? 'cycle' : $types[mt_rand(0, 3)];
        $config = $type === 'cycle' ? ($owner === 7 ? $cfg1 : $cfg2) : ($type === 'fixed_weekdays' ? '{"weekdays":["mon","wed"]}' : null);
        $rules[] = $r($id++, $type, $cursor, $until, $config, $owner);
        if ($until === null) {
            break;
        }
        $cursor = ScheduleRulePlanner::addDays($until, mt_rand(1, 5));
    }
    $versions = [$v('2026-09-01', $wk('RPP'), '2026-09-01')];
    for ($k = 0, $n = mt_rand(0, 3); $k < $n; $k++) {
        $versions[] = $v(ScheduleRulePlanner::addDays('2026-09-02', mt_rand(0, 180)), array_map(static fn (): string => mt_rand(0, 1) ? 'onsite' : 'remote', range(1, mt_rand(2, 6))), '2026-09-01');
    }
    $versions = array_values(array_column($versions, null, 'effective_from'));
    foreach ($versions as $k => $version) {
        if (!in_array('onsite', $version['pattern'], true)) {
            $versions[$k]['pattern'][] = 'onsite';
        }
    }
    $start = ScheduleRulePlanner::addDays('2026-09-15', mt_rand(0, 120));
    $until = mt_rand(0, 1) ? null : ScheduleRulePlanner::addDays($start, mt_rand(0, 90));
    if (RotationTeamPlanner::incompatibleRules($rules, $start, $until) !== []) {
        continue;
    }
    $sim = RotationTeamPlanner::simulate($rules, RotationTeamPlanner::memberIntents($rules, $versions, 7, $start, $until), '2026-09-01');
    assert_same([], ScheduleRulePlanner::violations($sim['rules']), "propriedade: sem violacao (rodada {$round})");
    for ($date = '2026-09-01'; $date <= '2027-06-30'; $date = ScheduleRulePlanner::addDays($date, 3)) {
        $after = ScheduleRulePlanner::ruleAt($sim['rules'], $date);
        $before = ScheduleRulePlanner::ruleAt($rules, $date);
        if ($date < $start || ($until !== null && $date > $until)) {
            $expected = $before === null ? null : [$before['rule_type'], $before['rule_config'], $before['rotation_team_id']];
            assert_same($expected, $after === null ? null : [$after['rule_type'], $after['rule_config'], $after['rotation_team_id']], "propriedade: fora da pertenca a regra de antes ({$date}, rodada {$round})");
        } else {
            assert_same(['cycle', RotationTeamPlanner::versionConfig(RotationTeamPlanner::versionAt($versions, $date)), 7], $after === null ? null : [$after['rule_type'], $after['rule_config'], $after['rotation_team_id']], "propriedade: na pertenca a regra do time com a versao da data ({$date}, rodada {$round})");
        }
        $propertyChecks++;
    }
    $resim = RotationTeamPlanner::simulate($sim['rules'], RotationTeamPlanner::memberIntents($sim['rules'], $versions, 7, $start, $until), '2026-09-01');
    assert_same([], $resim['plans'], "propriedade: gravar de novo nao muda nada (rodada {$round})");
}
assert_same(true, $propertyChecks > 10000, 'propriedade: mais de 10 mil datas conferidas');

// Prova pelo motor e classificacao do plano de migracao.
$employee = ['id' => 5, 'team' => 'n1', 'fora_mapa_escala' => 0];
$teams = [
    ['key' => 'Time 1', 'id' => 1, 'pattern' => $wk('RPP'), 'anchor_date' => '2026-10-01'],
    ['key' => 'Time 2', 'id' => 2, 'pattern' => $wk('PRP'), 'anchor_date' => '2026-10-01'],
    ['key' => 'Time 3', 'id' => 3, 'pattern' => $wk('PPR'), 'anchor_date' => '2026-10-01'],
];
$cycleRule = static fn (string $letters, string $anchor, string $from = '2026-10-01', ?string $until = null): array => ['id' => 1, 'rule_type' => 'cycle', 'rule_config' => CyclePattern::config(array_map(static fn (string $d): string => $d === 'P' ? 'onsite' : 'remote', str_split($letters)), $anchor, null), 'rotation_team_id' => null, 'effective_from' => $from, 'effective_until' => $until];
// Secao 7 do desenho: Time 1 em HOME em 12/11, Time 2 em 13/11, Time 3 em 16/11.
foreach ([['2026-11-12', 'Time 1'], ['2026-11-13', 'Time 2'], ['2026-11-16', 'Time 3'], ['2026-11-09', 'Time 1'], ['2026-11-10', 'Time 2'], ['2026-11-11', 'Time 3']] as [$anchor, $team]) {
    $class = RotationTeamPlanner::classifyForTeams($employee, [$cycleRule('RPP', $anchor, $anchor)], $teams, '2026-11-23', '2027-11-23', '2026-11-15');
    assert_same(['convertivel', $team, 0], [$class['status'], $class['team'] ?? null, $class['prova']['diferentes'] ?? null], "plano: ciclo 1R/2P com inicio em {$anchor} vira {$team} sem nenhum dia diferente");
    assert_same(true, ($class['prova']['dias'] ?? 0) > 250, "plano: prova pelo motor em todos os dias uteis do horizonte ({$anchor})");
}
assert_same(['convertivel', 'Time 2'], array_values(array_intersect_key(RotationTeamPlanner::classifyForTeams($employee, [$cycleRule('PRP', '2026-11-12', '2026-11-12')], $teams, '2026-11-23', '2027-01-31', '2026-11-15'), ['status' => 0, 'team' => 0])), 'plano: qualquer rotacao de 1R/2P (PRP com inicio em 12/11)');
foreach ([
    'lideranca' => [['team' => 'lideranca'] + $employee, [$cycleRule('RPP', '2026-11-12')]],
    'fora_do_mapa' => [['fora_mapa_escala' => 1] + $employee, [$cycleRule('RPP', '2026-11-12')]],
    'sem_regra' => [$employee, []],
    'nao_e_ciclo' => [$employee, [['rule_type' => 'always_onsite', 'rule_config' => null] + $cycleRule('RPP', '2026-11-12')]],
    'ciclo_invalido' => [$employee, [['rule_config' => '{"pattern":["onsite"],"anchor_date":"2026-11-12"}'] + $cycleRule('RPP', '2026-11-12')]],
    'ciclo_fora_do_rodizio' => [$employee, [$cycleRule('RRPP', '2026-11-12')]],
    'regra_individual_agendada_depois' => [$employee, [$cycleRule('RPP', '2026-11-12', '2026-10-01', '2026-12-31'), ['id' => 2, 'rule_type' => 'always_onsite', 'rule_config' => null, 'rotation_team_id' => null, 'effective_from' => '2027-01-01', 'effective_until' => null]]],
] as $status => [$who, $history]) {
    assert_same($status, RotationTeamPlanner::classifyForTeams($who, $history, $teams, '2026-11-23', '2027-01-31', '2026-11-15')['status'], "plano: motivo {$status}");
}
assert_same('ciclo_fora_do_rodizio', RotationTeamPlanner::classifyForTeams($employee, [$cycleRule('RRP', '2026-11-12')], $teams, '2026-11-23', '2027-01-31', '2026-11-15')['status'], 'plano: 3 posicoes com 2 remotos fica fora');
$proof = RotationTeamPlanner::compareDays($employee, [$cycleRule('RPP', '2026-11-12')], [$cycleRule('PRP', '2026-11-12')], '2026-11-23', '2026-11-27');
assert_same(['dias' => 5, 'iguais' => 2, 'diferentes' => 3, 'primeiro_diferente' => '2026-11-23'], $proof, 'plano: compareDays conta e acha o primeiro dia diferente');

// ----------------------------------------------------------------------------
// 4. Script do plano (so leitura): funcao pura e linha de comando
// ----------------------------------------------------------------------------
require_once $root . '/scripts/plano-times-rodizio.php';
$planEmployees = [['id' => 1, 'team' => 'n1', 'fora_mapa_escala' => 0], ['id' => 2, 'team' => 'n1', 'fora_mapa_escala' => 0], ['id' => 3, 'team' => 'n2', 'fora_mapa_escala' => 0],
    ['id' => 4, 'team' => 'n2', 'fora_mapa_escala' => 0], ['id' => 5, 'team' => 'lideranca', 'fora_mapa_escala' => 0], ['id' => 6, 'team' => 'n1', 'fora_mapa_escala' => 1], ['id' => 7, 'team' => 'n1', 'fora_mapa_escala' => 0]];
$planRules = [1 => [$cycleRule('RPP', '2026-11-12', '2026-11-12')], 2 => [$cycleRule('RPP', '2026-11-13', '2026-11-13')], 3 => [$cycleRule('RPP', '2026-11-16', '2026-11-16')],
    4 => [['rule_type' => 'fixed_weekdays', 'rule_config' => '{"weekdays":["mon"]}'] + $cycleRule('RPP', '2026-11-12')], 6 => [$cycleRule('RPP', '2026-11-12')], 7 => [$cycleRule('RRPP', '2026-11-12')]];
$plan = plano_times_rodizio($planEmployees, $planRules, '2026-11-23', '2026-10-01', 365, '2026-11-15');
assert_same([3, 1, 1, 1], [$plan['motivos']['convertivel'], $plan['por_time']['Time 1'], $plan['por_time']['Time 2'], $plan['por_time']['Time 3']], 'plano: um convertivel em cada time');
assert_same([1, 1, 1, 1], [$plan['motivos']['nao_e_ciclo'], $plan['motivos']['lideranca'], $plan['motivos']['fora_do_mapa'], $plan['motivos']['ciclo_fora_do_rodizio']], 'plano: motivos contados');
assert_same([0, true], [$plan['dias_diferentes'], $plan['dias_comparados'] === $plan['dias_iguais'] && $plan['dias_comparados'] > 3 * 250], 'plano: prova pelo motor, nenhum dia diferente');
assert_same(['employee_id', 'status', 'team'], array_keys($plan['detalhe'][0]), 'plano: detalhe so com ID, motivo e time (nunca nome)');
$planSource = (string)file_get_contents($root . '/scripts/plano-times-rodizio.php');
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE)\b/', (string)preg_replace('/^\s*(\/\/|\*).*$/m', '', $planSource)), 'plano: nenhum comando de escrita no script');
assert_same(true, strpos($planSource, "\$pdo->exec('SET SESSION TRANSACTION READ ONLY');") !== false && strpos($planSource, '$pdo->rollBack();') !== false, 'plano: transacao READ ONLY, desfeita no fim');
assert_same(0, preg_match('/\bnome\b|ad_login|employee_name/', (string)preg_replace('/^\s*(\/\/|\*).*$/m', '', $planSource)), 'plano: nao le nome nem login');
assert_same(1, preg_match("/if \\(PHP_SAPI !== 'cli'\\) \\{\\s+http_response_code\\(404\\);/", $planSource), 'plano: fora da linha de comando, 404');
$usage = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/plano-times-rodizio.php') . ' --a-partir=2026-02-30 2>&1', $usage, $usageCode);
assert_same([2, true], [$usageCode, strpos(implode("\n", $usage), 'Uso: php scripts/plano-times-rodizio.php') !== false], 'plano: data invalida mostra o uso e sai com 2, sem tocar no banco');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/plano-times-rodizio.php') . ' --a-partir=2026-11-23 --ancora=2026-10-03 2>&1', $usage, $usageCode);
assert_same(2, $usageCode, 'plano: ancora no fim de semana recusada');

// Buraco no historico dentro da pertenca: o time cobre os dias sem regra.
$gapped = [$r(30, 'always_onsite', '2026-09-01', '2026-10-23'), $r(31, 'cycle', '2026-10-27', null, $cfg2, 8)];
$sim = RotationTeamPlanner::simulate($gapped, RotationTeamPlanner::memberIntents($gapped, [$v1], 7, '2026-10-20', null), '2026-10-01');
assert_same([7, 7, 7], array_map(static fn (string $d): ?int => ScheduleRulePlanner::ruleAt($sim['rules'], $d)['rotation_team_id'] ?? null, ['2026-10-20', '2026-10-25', '2026-10-27']), 'planejador: os dias sem regra dentro da pertenca ficam com a regra do time');

// ----------------------------------------------------------------------------
// 5. RotationTeamService contra o banco simulado
// ----------------------------------------------------------------------------
putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-rotation-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.251';
define('CHRONODESK_STATELESS', true);
$GLOBALS['qa_pdo'] = null;
function get_db_connection() {
    if (!$GLOBALS['qa_pdo'] instanceof PDO) {
        throw new PDOException('SQLSTATE[HY000]: banco simulado ausente');
    }
    return $GLOBALS['qa_pdo'];
}
require_once $root . '/services/RotationTeamService.php';

/**
 * Banco simulado dos times: tabelas em memoria, so as consultas do servico, e
 * as restricoes da 021 e da 011 (nome unico, versao por time e data, uma
 * pertenca aberta por pessoa, um inicio por pessoa, regra aberta e inicio por
 * pessoa). Violacao falha como o MySQL (1062) e a transacao desfaz tudo.
 */
final class QaRotationPdo extends PDO {
    public array $employees = [];
    public array $teams = [];
    public array $versions = [];
    public array $members = [];
    public array $rules = [];
    public array $sync = [];
    public array $audits = [];
    public array $events = [];
    public int $nextId = 500;
    private ?array $snapshot = null;

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaRotationStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }

    public function beginTransaction(): bool {
        $this->snapshot = $this->tables();
        $this->events[] = 'begin';
        return true;
    }

    public function commit(): bool {
        $this->snapshot = null;
        $this->events[] = 'commit';
        return true;
    }

    public function rollBack(): bool {
        [$this->teams, $this->versions, $this->members, $this->rules, $this->sync, $this->audits, $this->nextId] = $this->snapshot;
        $this->snapshot = null;
        $this->events[] = 'rollback';
        return true;
    }

    public function inTransaction(): bool {
        return $this->snapshot !== null;
    }

    public function lastInsertId(?string $name = null): string|false {
        return (string)($this->nextId - 1);
    }

    public function tables(): array {
        return [$this->teams, $this->versions, $this->members, $this->rules, $this->sync, $this->audits, $this->nextId];
    }

    public function check(): void {
        $seen = [];
        foreach ($this->teams as $team) {
            $seen['name:' . $team['name']] = ($seen['name:' . $team['name']] ?? 0) + 1;
        }
        foreach ($this->versions as $row) {
            $seen['version:' . $row['team_id'] . ':' . $row['effective_from']] = ($seen['version:' . $row['team_id'] . ':' . $row['effective_from']] ?? 0) + 1;
        }
        foreach ($this->members as $row) {
            $seen['from:' . $row['employee_id'] . ':' . $row['valid_from']] = ($seen['from:' . $row['employee_id'] . ':' . $row['valid_from']] ?? 0) + 1;
            if ($row['valid_until'] === null) {
                $seen['open:' . $row['employee_id']] = ($seen['open:' . $row['employee_id']] ?? 0) + 1;
            }
            if ($row['valid_until'] !== null && $row['valid_until'] < $row['valid_from']) {
                throw new PDOException('SQLSTATE[HY000]: Check constraint ck_rotation_member_period is violated');
            }
        }
        foreach ($this->rules as $row) {
            $seen['rule:' . $row['employee_id'] . ':' . $row['effective_from']] = ($seen['rule:' . $row['employee_id'] . ':' . $row['effective_from']] ?? 0) + 1;
            if ($row['effective_until'] === null) {
                $seen['ruleopen:' . $row['employee_id']] = ($seen['ruleopen:' . $row['employee_id']] ?? 0) + 1;
            }
        }
        if (max($seen ?: [0]) > 1) {
            throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        }
    }
}

final class QaRotationStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaRotationPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $p = $params ?? [];
        $db = $this->pdo;
        $sql = $this->sql;
        $this->rows = [];
        $db->events[] = $sql;
        $bk = $db->tables();
        try {
            $this->run($sql, $p, $db);
            $db->check();
        } catch (PDOException $e) {
            [$db->teams, $db->versions, $db->members, $db->rules, $db->sync, $db->audits, $db->nextId] = $bk;
            throw $e;
        }
        return true;
    }

    private function run(string $sql, array $p, QaRotationPdo $db): void {
        $employee = static function (int $id) use ($db): ?array {
            $row = $db->employees[$id] ?? null;
            return $row === null ? null : ['id' => $id] + $row;
        };
        switch (true) {
            case $sql === 'SELECT 1 FROM audit_log LIMIT 1':
                return;
            case $sql === 'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)':
                $db->audits[] = ['action' => $p[2], 'details' => $p[3], 'severity' => $p[4]];
                return;
            case strpos($sql, 'INSERT INTO portal_sync_queue') === 0:
                $db->sync[] = $p;
                return;
            case $sql === 'SELECT id, name, active FROM portal_rotation_teams ORDER BY name, id':
                $rows = array_values($db->teams);
                usort($rows, static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
                $this->rows = $rows;
                return;
            case $sql === 'SELECT id, team_id, effective_from, pattern, anchor_date, weeks FROM portal_rotation_team_versions ORDER BY team_id, effective_from':
            case $sql === 'SELECT id, team_id, effective_from, pattern, anchor_date, weeks FROM portal_rotation_team_versions WHERE team_id = :team_id ORDER BY effective_from FOR UPDATE':
                $rows = array_values(array_filter($db->versions, static fn (array $v): bool => !isset($p[':team_id']) || $v['team_id'] === (int)$p[':team_id']));
                usort($rows, static fn (array $a, array $b): int => [$a['team_id'], $a['effective_from']] <=> [$b['team_id'], $b['effective_from']]);
                $this->rows = $rows;
                return;
            case strpos($sql, 'SELECT m.id, m.team_id, m.employee_id, m.valid_from, m.valid_until, f.ativo AS employee_active FROM portal_rotation_team_members m JOIN funcionarios f ON f.id = m.employee_id') === 0:
                foreach ($db->members as $m) {
                    if (isset($p[':today']) && $m['valid_until'] !== null && $m['valid_until'] < $p[':today']) {
                        continue;
                    }
                    if (isset($p[':team_id']) && ($m['team_id'] !== (int)$p[':team_id'] || ($m['valid_until'] !== null && $m['valid_until'] < $p[':effective_from']))) {
                        continue;
                    }
                    $this->rows[] = $m + ['employee_active' => (int)$db->employees[$m['employee_id']]['ativo']];
                }
                usort($this->rows, isset($p[':team_id'])
                    ? static fn (array $a, array $b): int => [$a['employee_id'], $a['valid_from']] <=> [$b['employee_id'], $b['valid_from']]
                    : static fn (array $a, array $b): int => [$a['team_id'], $a['valid_from'], $a['employee_id']] <=> [$b['team_id'], $b['valid_from'], $b['employee_id']]);
                return;
            case $sql === 'INSERT INTO portal_rotation_teams (name, active, created_by, updated_by) VALUES (:name, 1, :created_by, :updated_by)':
                $db->teams[$db->nextId] = ['id' => $db->nextId, 'name' => $p[':name'], 'active' => 1];
                $db->nextId++;
                return;
            case $sql === 'SELECT id, name, active FROM portal_rotation_teams WHERE id = :id FOR UPDATE':
                $this->rows = isset($db->teams[(int)$p[':id']]) ? [$db->teams[(int)$p[':id']]] : [];
                return;
            case $sql === 'SELECT id FROM portal_rotation_teams WHERE name = :name':
                foreach ($db->teams as $team) {
                    if (strcasecmp($team['name'], $p[':name']) === 0) {
                        $this->rows[] = ['id' => $team['id']];
                    }
                }
                return;
            case $sql === 'SELECT name FROM portal_rotation_teams WHERE id = :id':
                $this->rows = isset($db->teams[(int)$p[':id']]) ? [['name' => $db->teams[(int)$p[':id']]['name']]] : [];
                return;
            case $sql === 'UPDATE portal_rotation_teams SET name = :name, active = :active, updated_by = :updated_by WHERE id = :id':
                $db->teams[(int)$p[':id']] = ['name' => $p[':name'], 'active' => (int)$p[':active']] + $db->teams[(int)$p[':id']];
                return;
            case $sql === 'SELECT COUNT(*) FROM portal_rotation_team_members WHERE team_id = :team_id AND (valid_until IS NULL OR valid_until >= :today)':
                $this->rows = [['n' => count(array_filter($db->members, static fn (array $m): bool => $m['team_id'] === (int)$p[':team_id'] && ($m['valid_until'] === null || $m['valid_until'] >= $p[':today'])))]];
                return;
            case strpos($sql, 'UPDATE portal_rotation_team_versions SET pattern = :pattern') === 0:
                $db->versions[(int)$p[':id']] = ['pattern' => $p[':pattern'], 'anchor_date' => $p[':anchor_date'], 'weeks' => $p[':weeks']] + $db->versions[(int)$p[':id']];
                return;
            case strpos($sql, 'INSERT INTO portal_rotation_team_versions') === 0:
                $db->versions[$db->nextId] = ['id' => $db->nextId, 'team_id' => (int)$p[':team_id'], 'effective_from' => $p[':effective_from'], 'pattern' => $p[':pattern'], 'anchor_date' => $p[':anchor_date'], 'weeks' => $p[':weeks']];
                $db->nextId++;
                return;
            case $sql === 'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login, access_role, fora_mapa_escala FROM funcionarios WHERE id = :id AND ativo = 1 FOR UPDATE':
                $row = $employee((int)$p[':id']);
                $this->rows = $row !== null && (int)$row['ativo'] === 1 ? [['id' => $row['id'], 'name' => $row['nome'], 'team' => strtolower($row['equipe']), 'ad_login' => $row['ad_login'], 'access_role' => $row['access_role'], 'fora_mapa_escala' => $row['fora_mapa_escala']]] : [];
                return;
            case $sql === 'SELECT id, LOWER(equipe) AS team, access_role FROM funcionarios WHERE id = :id FOR UPDATE':
                $row = $employee((int)$p[':id']);
                $this->rows = $row !== null ? [['id' => $row['id'], 'team' => strtolower($row['equipe']), 'access_role' => $row['access_role']]] : [];
                return;
            case $sql === 'SELECT id, team_id, employee_id, valid_from, valid_until FROM portal_rotation_team_members WHERE employee_id = :employee_id ORDER BY valid_from FOR UPDATE':
                $this->rows = array_values(array_filter($db->members, static fn (array $m): bool => $m['employee_id'] === (int)$p[':employee_id']));
                usort($this->rows, static fn (array $a, array $b): int => strcmp($a['valid_from'], $b['valid_from']));
                return;
            case $sql === 'SELECT id, team_id, employee_id, valid_from, valid_until FROM portal_rotation_team_members WHERE id = :id FOR UPDATE':
                $this->rows = isset($db->members[(int)$p[':id']]) ? [$db->members[(int)$p[':id']]] : [];
                return;
            case $sql === 'INSERT INTO portal_rotation_team_members (team_id, employee_id, valid_from, valid_until, created_by, updated_by) VALUES (:team_id, :employee_id, :valid_from, NULL, :created_by, :updated_by)':
                $db->members[$db->nextId] = ['id' => $db->nextId, 'team_id' => (int)$p[':team_id'], 'employee_id' => (int)$p[':employee_id'], 'valid_from' => $p[':valid_from'], 'valid_until' => null];
                $db->nextId++;
                return;
            case $sql === 'UPDATE portal_rotation_team_members SET team_id = :team_id, updated_by = :updated_by WHERE id = :id':
                $db->members[(int)$p[':id']]['team_id'] = (int)$p[':team_id'];
                return;
            case $sql === 'UPDATE portal_rotation_team_members SET valid_until = :valid_until, updated_by = :updated_by WHERE id = :id':
            case $sql === 'UPDATE portal_rotation_team_members SET valid_until = :valid_until, updated_by = :updated_by WHERE id = :id AND valid_until IS NULL':
                if (isset($db->members[(int)$p[':id']]) && (strpos($sql, 'AND valid_until IS NULL') === false || $db->members[(int)$p[':id']]['valid_until'] === null)) {
                    $db->members[(int)$p[':id']]['valid_until'] = $p[':valid_until'];
                }
                return;
            case $sql === 'DELETE FROM portal_rotation_team_members WHERE id = :id AND valid_until IS NULL':
                if (isset($db->members[(int)$p[':id']]) && $db->members[(int)$p[':id']]['valid_until'] === null) {
                    unset($db->members[(int)$p[':id']]);
                }
                return;
            case $sql === 'SELECT id, rule_type, rule_config, rotation_team_id, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id FOR UPDATE':
                foreach ($db->rules as $rule) {
                    if ($rule['employee_id'] === (int)$p[':employee_id']) {
                        $this->rows[] = array_intersect_key($rule, array_flip(['id', 'rule_type', 'rule_config', 'rotation_team_id', 'effective_from', 'effective_until']));
                    }
                }
                usort($this->rows, static fn (array $a, array $b): int => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);
                return;
            case $sql === 'UPDATE portal_schedule_rules SET effective_until = :effective_until, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id':
                if (($db->rules[(int)$p[':id']]['employee_id'] ?? null) === (int)$p[':employee_id']) {
                    $db->rules[(int)$p[':id']]['effective_until'] = $p[':effective_until'];
                }
                return;
            case strpos($sql, 'UPDATE portal_schedule_rules SET employee_name = :employee_name') === 0:
                if (($db->rules[(int)$p[':id']]['employee_id'] ?? null) === (int)$p[':employee_id']) {
                    $db->rules[(int)$p[':id']] = ['team' => $p[':team'], 'rule_type' => $p[':rule_type'], 'rule_config' => $p[':rule_config'], 'rotation_team_id' => $p[':rotation_team_id'], 'effective_until' => $p[':effective_until']] + $db->rules[(int)$p[':id']];
                }
                return;
            case strpos($sql, 'INSERT INTO portal_schedule_rules (employee_id, employee_name, ad_login, team, rule_type, rule_config, rotation_team_id,') === 0:
                $db->rules[$db->nextId] = ['id' => $db->nextId, 'employee_id' => (int)$p[':employee_id'], 'team' => $p[':team'], 'rule_type' => $p[':rule_type'], 'rule_config' => $p[':rule_config'], 'rotation_team_id' => $p[':rotation_team_id'], 'effective_from' => $p[':effective_from'], 'effective_until' => $p[':effective_until']];
                $db->nextId++;
                return;
            case $sql === 'DELETE FROM portal_schedule_rules WHERE id = :id AND employee_id = :employee_id':
                if (($db->rules[(int)$p[':id']]['employee_id'] ?? null) === (int)$p[':employee_id']) {
                    unset($db->rules[(int)$p[':id']]);
                }
                return;
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

    public function rowCount(): int {
        return 1;
    }
}

/** Banco com N1 (1, 2, 4 e 6), N2 (5), Lideranca (3), um gestor N2 (7), um fora do Mapa (8) e um arquivado (9). */
function qa_rotation_db(): QaRotationPdo {
    $pdo = new QaRotationPdo();
    $employee = static fn (string $nome, string $equipe, string $role = 'tecnico', int $ativo = 1, int $fora = 0): array => ['nome' => $nome, 'equipe' => $equipe, 'ad_login' => 'qa.' . strtolower(str_replace(' ', '', $nome)), 'access_role' => $role, 'ativo' => $ativo, 'fora_mapa_escala' => $fora];
    $pdo->employees = [
        1 => $employee('Tecnico Um', 'n1'), 2 => $employee('Tecnico Dois', 'n1'), 3 => $employee('Lider Tres', 'Liderança', 'lideranca'),
        4 => $employee('Tecnico Quatro', 'n1'), 5 => $employee('Tecnico Cinco', 'N2'), 6 => $employee('Tecnico Seis', 'n1', 'lideranca'),
        7 => $employee('Gestor Sete', 'n2', 'gestor'), 8 => $employee('Fora Oito', 'n1', 'tecnico', 1, 1), 9 => $employee('Arquivado Nove', 'n1', 'tecnico', 0),
    ];
    $GLOBALS['qa_pdo'] = $pdo;
    return $pdo;
}
$rule = static fn (int $id, int $employee, string $type, string $from, ?string $until, ?string $config = null, ?int $team = null): array => [$id => ['id' => $id, 'employee_id' => $employee, 'team' => 'n1', 'rule_type' => $type, 'rule_config' => $config, 'rotation_team_id' => $team, 'effective_from' => $from, 'effective_until' => $until]];
$today = '2026-11-16';
$rpp = ['pattern' => $wk('RPP'), 'anchor_date' => '2026-10-01'];
$names = ['Tecnico', 'Lider', 'Gestor', 'qa.'];
$noNames = static function (array $audits) use ($names): bool {
    foreach ($audits as $audit) {
        foreach ($names as $name) {
            if (strpos($audit['details'], $name) !== false) {
                return false;
            }
        }
    }
    return true;
};

// Permissao: gestor e tecnico, 403 em toda gravacao e na previa; nada gravado.
$pdo = qa_rotation_db();
$service = new RotationTeamService($pdo, $today);
foreach (['gestor', 'tecnico', 'somente_leitura'] as $role) {
    foreach ([
        'saveTeam' => fn () => $service->saveTeam(['name' => 'Time 1'], 'qa', $role),
        'saveVersion' => fn () => $service->saveVersion(['team_id' => 1], 'qa', $role),
        'previewVersion' => fn () => $service->previewVersion(['team_id' => 1], $role),
        'addMember' => fn () => $service->addMember(['team_id' => 1], 'qa', $role),
        'moveMember' => fn () => $service->moveMember(['team_id' => 1], 'qa', $role),
        'endMember' => fn () => $service->endMember(['member_id' => 1], 'qa', $role),
    ] as $method => $call) {
        assert_same('403: Apenas administradores e Liderança alteram times de rodízio.', $qaOutcome($call), "servico: {$method} pelo {$role} 403 (D-F10-5)");
    }
}
assert_same([[], [], []], [$pdo->teams, $pdo->events, $pdo->audits], 'servico: a recusa de perfil vem antes de qualquer consulta');

// Criar o time com a primeira versao, na mesma transacao.
$created = $service->saveTeam(['name' => '  Time   1 ', 'version' => ['effective_from' => '2026-10-01', 'cycle' => $rpp]], 'qa.lider', 'lideranca');
$teamId = $created['team_id'];
assert_same([true, 'Time 1', 1, '["remote","onsite","onsite"]'], [$created['created'], $pdo->teams[$teamId]['name'], count($pdo->versions), array_values($pdo->versions)[0]['pattern']], 'servico: time criado com o nome normalizado e a 1a versao');
assert_same(['TIME_RODIZIO_CRIADO', 'TIME_RODIZIO_VERSAO_ALTERADA'], array_column($pdo->audits, 'action'), 'servico: criacao e versao auditadas');
assert_same('409: Já existe um time com esse nome.', $qaOutcome(fn () => $service->saveTeam(['name' => 'time 1'], 'qa', 'admin')), 'servico: nome repetido 409');
foreach (['' => 'vazio', str_repeat('x', 61) => '61 caracteres'] as $name => $label) {
    assert_same('400: Nome do time: de 1 a 60 caracteres.', $qaOutcome(fn () => $service->saveTeam(['name' => $name], 'qa', 'admin')), "servico: nome {$label} 400");
}
assert_same('ok', $qaOutcome(fn () => $service->saveTeam(['name' => str_repeat('é', 60)], 'qa', 'admin')), 'servico: 60 caracteres com acento aceitos (contagem por caractere, sem mbstring)');
$team2 = $service->saveTeam(['name' => 'Time 2', 'version' => ['effective_from' => '2026-10-01', 'cycle' => ['pattern' => $wk('PRP'), 'anchor_date' => '2026-10-01']]], 'qa', 'admin')['team_id'];
assert_same('400: Informe o padrão do time (lista de dias ou semanas).', $qaOutcome(fn () => $service->saveVersion(['team_id' => $teamId, 'effective_from' => '2026-11-16', 'cycle' => ['onsite_days' => 2, 'remote_days' => 1, 'starts_with' => 'remote', 'anchor_date' => '2026-10-01']], 'qa', 'admin')), 'servico: versao do time so por padrao ou semanas');
assert_same('400: Time inválido.', $qaOutcome(fn () => $service->saveVersion(['team_id' => 'x', 'effective_from' => '2026-11-16', 'cycle' => $rpp], 'qa', 'admin')), 'servico: time invalido 400');
assert_same('400: Time não encontrado.', $qaOutcome(fn () => $service->saveVersion(['team_id' => 999, 'effective_from' => '2026-11-16', 'cycle' => $rpp], 'qa', 'admin')), 'servico: time inexistente 400');

// Entrar no time: a regra individual encerra na vespera; a do time vale desde a entrada.
$pdo->rules = $rule(10, 1, 'always_onsite', '2026-09-01', null) + $rule(11, 2, 'even_days', '2026-09-01', null) + $rule(12, 5, 'always_remote', '2026-09-01', null);
$pdo->audits = [];
$added = $service->addMember(['team_id' => $teamId, 'employee_id' => 1, 'valid_from' => '2026-11-23'], 'qa.lider', 'lideranca');
$after = array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === 1));
assert_same([['always_onsite', '2026-09-01', '2026-11-22', null], ['cycle', '2026-11-23', null, $teamId]], array_map(static fn (array $x): array => [$x['rule_type'], $x['effective_from'], $x['effective_until'], $x['rotation_team_id']], $after), 'servico: entrada grava a regra do time e encerra a individual na vespera');
assert_same(RotationTeamPlanner::versionConfig(['pattern' => $wk('RPP'), 'anchor_date' => '2026-10-01', 'weeks' => null]), $after[1]['rule_config'], 'servico: a regra copia o padrao e a ancora da versao');
assert_same([1, '2026-11-23', null], [$added['rules_written'], $pdo->members[$added['member_id']]['valid_from'], $pdo->members[$added['member_id']]['valid_until']], 'servico: pertenca aberta desde a entrada');
assert_same(['TIME_RODIZIO_MEMBRO_ALTERADO', 'INFO'], [end($pdo->audits)['action'], end($pdo->audits)['severity']], 'servico: entrada auditada (INFO)');
assert_same(true, $noNames($pdo->audits) && strpos(end($pdo->audits)['details'], "time_id={$teamId}; employee_id=1;") !== false, 'servico: auditoria so com IDs (nunca nome nem login)');
$queue = json_decode((string)(end($pdo->sync)[':payload_json'] ?? '{}'), true);
assert_same(['time_rodizio', ['remote', 'onsite', 'onsite']], [$queue['origem'] ?? null, $queue['pattern'] ?? null], 'servico: fila de sincronizacao com a regra do time, como a individual');
$lockOrder = array_values(array_filter($pdo->events, static fn (string $sql): bool => strpos($sql, 'FOR UPDATE') !== false));
assert_same(true, strpos($lockOrder[0] ?? '', 'FROM portal_rotation_teams WHERE id = :id FOR UPDATE') !== false, 'servico: o time e travado antes do colaborador');

foreach ([
    'ja no time' => [['team_id' => $team2, 'employee_id' => 1, 'valid_from' => '2026-12-01'], '409: Este técnico está no Time 1; mude pelo time. Para trocar de time, use Mover.'],
    'Lideranca' => [['team_id' => $teamId, 'employee_id' => 3, 'valid_from' => '2026-11-23'], '409: A Liderança tem escala fixa presencial: não entra em time de rodízio.'],
    'fora do Mapa' => [['team_id' => $teamId, 'employee_id' => 8, 'valid_from' => '2026-11-23'], '409: Cadastro marcado como fora do Mapa PA e da escala: não entra em time de rodízio.'],
    'arquivado' => [['team_id' => $teamId, 'employee_id' => 9, 'valid_from' => '2026-11-23'], '400: Colaborador não encontrado ou inativo.'],
    'sem versao na data' => [['team_id' => $teamId, 'employee_id' => 2, 'valid_from' => '2026-09-15'], '409: O time não tem padrão em 15/09/2026: cadastre a versão do padrão antes.'],
    'data invalida' => [['team_id' => $teamId, 'employee_id' => 2, 'valid_from' => '2026-02-30'], '400: Início no time: data inválida.'],
] as $label => [$payload, $expected]) {
    $before = $pdo->tables();
    assert_same($expected, $qaOutcome(fn () => $service->addMember($payload, 'qa', 'admin')), "servico: entrada recusada ({$label})");
    assert_same($before, $pdo->tables(), "servico: nada gravado na recusa ({$label})");
}
// L9: a Lideranca nao mexe na escala de quem tem perfil Lideranca; o admin pode.
assert_same('403: Somente administradores alteram a escala da Liderança.', $qaOutcome(fn () => $service->addMember(['team_id' => $teamId, 'employee_id' => 6, 'valid_from' => '2026-11-23'], 'qa', 'lideranca')), 'servico: L9 (perfil Lideranca so pelo admin)');
// Regra individual agendada depois da entrada: 409 e nada gravado.
$pdo->rules += $rule(13, 4, 'always_onsite', '2026-09-01', '2026-12-31') + $rule(14, 4, 'always_remote', '2027-01-01', null);
$before = $pdo->tables();
assert_same('409: O técnico tem regra individual agendada depois de 23/11/2026: o time não sobrescreve. Ajuste essa regra antes.', $qaOutcome(fn () => $service->addMember(['team_id' => $teamId, 'employee_id' => 4, 'valid_from' => '2026-11-23'], 'qa', 'admin')), 'servico: regra individual agendada depois da entrada 409');
assert_same($before, $pdo->tables(), 'servico: nada gravado no 409 da regra agendada');

// Versao nova: grava a regra de cada membro a partir dela; o planejador cuida do resto.
$service->addMember(['team_id' => $teamId, 'employee_id' => 2, 'valid_from' => '2026-11-23'], 'qa', 'admin');
$preview = $service->previewVersion(['team_id' => $teamId, 'effective_from' => '2026-12-07', 'cycle' => ['weeks' => [$wk('PPRPR'), $wk('RPPPP')], 'anchor_date' => '2026-12-07']], 'lideranca');
assert_same([20, 'Ciclo 2 semanas, 7P/3R', 2, 0, false, false], [count($preview['preview']), $preview['label'], $preview['members_changed'], $preview['members_blocked'], $preview['replaces'], $preview['retroactive']], 'servico: previa da versao (20 dias uteis, rotulo, membros que mudam, nenhum bloqueado)');
assert_same(['2026-12-07', 'onsite', 'remote'], [$preview['preview'][0]['date'], $preview['preview'][0]['presence_type'], $preview['preview'][2]['presence_type']], 'servico: previa pelo motor (seg P, qua R)');
$tablesBeforePreview = $pdo->tables();
$service->previewVersion(['team_id' => $teamId, 'effective_from' => '2026-12-07', 'cycle' => $rpp], 'admin');
assert_same($tablesBeforePreview, $pdo->tables(), 'servico: previa nao grava nada');
$pdo->audits = [];
$saved = $service->saveVersion(['team_id' => $teamId, 'effective_from' => '2026-12-07', 'cycle' => ['weeks' => [$wk('PPRPR'), $wk('RPPPP')], 'anchor_date' => '2026-12-07']], 'qa', 'lideranca');
foreach ([1, 2] as $member) {
    $history = array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === $member));
    $last = end($history);
    assert_same(['2026-12-07', null, $teamId, 2], [$last['effective_from'], $last['effective_until'], $last['rotation_team_id'], json_decode($last['rule_config'], true)['weeks'] ?? null], "servico: membro {$member} com a regra da versao nova desde o inicio dela");
    assert_same([], ScheduleRulePlanner::violations($history), "servico: historico do membro {$member} sem violacao");
}
assert_same([2, 2, 2], [$saved['members_affected'], $saved['members_changed'], $saved['rules_written']], 'servico: dois membros, uma regra cada');
$versionAudit = end($pdo->audits);
assert_same(['TIME_RODIZIO_VERSAO_ALTERADA', 'WARNING'], [$versionAudit['action'], $versionAudit['severity']], 'servico: versao auditada como WARNING (desenho 3.1)');
assert_same(true, strpos($versionAudit['details'], 'posicoes=10; semanas=2; membros_afetados=2; employee_ids=1,2;') !== false && $noNames([$versionAudit]), 'servico: auditoria da versao com tamanho do padrao, contagem e IDs');
$employee5 = array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === 5));
assert_same([[12, 'always_remote', null, null]], array_map(static fn (array $x): array => [$x['id'], $x['rule_type'], $x['effective_until'], $x['rotation_team_id']], $employee5), 'servico: quem nao e membro nao muda');
// Correcao da versao no mesmo inicio: atualiza a versao e as regras no lugar.
$rulesBefore = count($pdo->rules);
$service->saveVersion(['team_id' => $teamId, 'effective_from' => '2026-12-07', 'cycle' => ['pattern' => $wk('PRP'), 'anchor_date' => '2026-12-07']], 'qa', 'admin');
assert_same([$rulesBefore, 3, 'correcao'], [count($pdo->rules), count(array_filter($pdo->versions, static fn (array $x): bool => $x['team_id'] === $teamId)) + 1, preg_match('/operacao=(\w+)/', end($pdo->audits)['details'], $op) ? $op[1] : null], 'servico: correcao no mesmo inicio nao cria linha nova (versao nem regra)');
// Membro com regra individual agendada depois: 409 com a contagem, nada gravado (nem a versao).
$service->addMember(['team_id' => $teamId, 'employee_id' => 5, 'valid_from' => '2026-11-23'], 'qa', 'admin');
$pdoRuleId = $pdo->nextId++;
$pdo->rules[$pdoRuleId] = ['id' => $pdoRuleId, 'employee_id' => 5, 'team' => 'n2', 'rule_type' => 'always_onsite', 'rule_config' => null, 'rotation_team_id' => null, 'effective_from' => '2027-02-01', 'effective_until' => null];
foreach ($pdo->rules as $id => $x) {
    if ($x['employee_id'] === 5 && $x['effective_until'] === null && $x['effective_from'] < '2027-02-01') {
        $pdo->rules[$id]['effective_until'] = '2027-01-31';
    }
}
$before = $pdo->tables();
assert_same('409: 1 membro(s) com regra individual agendada depois de 04/01/2027: o time não sobrescreve. Ajuste essas regras ou encerre a pertença antes.', $qaOutcome(fn () => $service->saveVersion(['team_id' => $teamId, 'effective_from' => '2027-01-04', 'cycle' => $rpp], 'qa', 'admin')), 'servico: versao com membro bloqueado 409 com a contagem');
assert_same($before, $pdo->tables(), 'servico: nada gravado (nem a versao) no 409');
assert_same(1, $service->previewVersion(['team_id' => $teamId, 'effective_from' => '2027-01-04', 'cycle' => $rpp], 'admin')['members_blocked'], 'servico: a previa mostra o bloqueado');

// Troca de time: a pertenca atual fecha na vespera; as regras do time antigo na faixa viram do novo.
assert_same('ok', $qaOutcome(fn () => $service->moveMember(['team_id' => $team2, 'employee_id' => 1, 'valid_from' => '2027-01-04'], 'qa', 'admin')), 'servico: troca de time aceita');
$m1 = array_values(array_filter($pdo->members, static fn (array $x): bool => $x['employee_id'] === 1));
assert_same([[$teamId, '2026-11-23', '2027-01-03'], [$team2, '2027-01-04', null]], array_map(static fn (array $x): array => [$x['team_id'], $x['valid_from'], $x['valid_until']], $m1), 'servico: troca fecha a pertenca na vespera e abre a nova');
$r1 = array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === 1));
assert_same([$teamId, $team2], [ScheduleRulePlanner::ruleAt($r1, '2027-01-03')['rotation_team_id'], ScheduleRulePlanner::ruleAt($r1, '2027-01-04')['rotation_team_id']], 'servico: regra do time antigo ate a vespera, do novo desde a troca');
assert_same(true, strpos(end($pdo->audits)['details'], "operacao=troca; time_anterior_id={$teamId}; time_id={$team2}; employee_id=1;") !== false, 'servico: troca auditada com os dois times');
assert_same('409: O técnico já está nesse time.', $qaOutcome(fn () => $service->moveMember(['team_id' => $team2, 'employee_id' => 1, 'valid_from' => '2027-02-01'], 'qa', 'admin')), 'servico: troca para o mesmo time 409');
assert_same('409: O técnico não tem pertença aberta nessa data: use Incluir no time.', $qaOutcome(fn () => $service->moveMember(['team_id' => $team2, 'employee_id' => 4, 'valid_from' => '2027-02-01'], 'qa', 'admin')), 'servico: troca sem pertenca 409');

// Saida: a pertenca fecha; a regra fica como esta (desenho 3.1).
$rulesBefore = $pdo->rules;
$memberId = array_key_last(array_filter($pdo->members, static fn (array $x): bool => $x['employee_id'] === 2));
$service->endMember(['member_id' => $memberId, 'valid_until' => '2027-01-15'], 'qa', 'admin');
assert_same(['2027-01-15', $rulesBefore], [$pdo->members[$memberId]['valid_until'], $pdo->rules], 'servico: saida fecha a pertenca e nao muda regra');
assert_same('409: Esta pertença já foi encerrada.', $qaOutcome(fn () => $service->endMember(['member_id' => $memberId, 'valid_until' => '2027-01-20'], 'qa', 'admin')), 'servico: encerrar de novo 409');
$member5 = array_key_last(array_filter($pdo->members, static fn (array $x): bool => $x['employee_id'] === 5));
$service->endMember(['member_id' => $member5, 'valid_until' => '2027-01-31'], 'qa', 'admin');
assert_same('ok', $qaOutcome(fn () => $service->saveVersion(['team_id' => $teamId, 'effective_from' => '2027-01-11', 'cycle' => ['pattern' => $wk('PPR'), 'anchor_date' => '2026-10-01']], 'qa', 'admin')), 'servico: versao depois da saida de um membro');
$r2 = array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === 2));
assert_same([['PPR'], ['PRP']], [[$letters(json_decode(ScheduleRulePlanner::ruleAt($r2, '2027-01-15')['rule_config'], true)['pattern'])], [$letters(json_decode(ScheduleRulePlanner::ruleAt($r2, '2027-01-16')['rule_config'], true)['pattern'])]], 'servico: ex-membro recebe a versao so ate a saida; depois, a regra de antes');
// Pertenca futura cancelada: as regras do time saem e a anterior volta a valer como antes.
$pdo->rules += $rule(40, 4, 'always_onsite', '2027-01-01', null);
unset($pdo->rules[14]);
$pdo->rules[13]['effective_until'] = '2026-12-31';
$snapshotRules4 = array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === 4));
$future = $service->addMember(['team_id' => $teamId, 'employee_id' => 4, 'valid_from' => '2027-02-01'], 'qa', 'admin');
$cancel = $service->endMember(['member_id' => $future['member_id'], 'valid_until' => '2027-01-31'], 'qa', 'admin');
assert_same([true, 2, $snapshotRules4, false], [$cancel['cancelled'], $cancel['rules_undone'], array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === 4)), isset($pdo->members[$future['member_id']])], 'servico: cancelar a entrada futura desfaz as regras e volta o historico de antes');
$started = $service->addMember(['team_id' => $teamId, 'employee_id' => 4, 'valid_from' => '2027-01-01'], 'qa', 'admin');
$serviceLater = new RotationTeamService($pdo, '2027-01-10');
assert_same('400: O último dia no time não pode ser antes do início da pertença.', $qaOutcome(fn () => $serviceLater->endMember(['member_id' => $started['member_id'], 'valid_until' => '2026-12-31'], 'qa', 'admin')), 'servico: pertenca ja comecada nao e cancelada');
// Desativar time com membro: 409; sem membro, desativa.
assert_same('409: O time tem membros: encerre ou mova as pertenças antes de desativar.', $qaOutcome(fn () => $service->saveTeam(['team_id' => $teamId, 'name' => 'Time 1', 'active' => false], 'qa', 'admin')), 'servico: desativar com membro 409');
$empty = $service->saveTeam(['name' => 'Time vazio'], 'qa', 'admin')['team_id'];
$service->saveTeam(['team_id' => $empty, 'name' => 'Time vazio', 'active' => false], 'qa', 'admin');
assert_same([0, 'TIME_RODIZIO_ATUALIZADO'], [$pdo->teams[$empty]['active'], end($pdo->audits)['action']], 'servico: desativa o time sem membro');
assert_same('409: Time desativado: reative o time antes de incluir membros.', $qaOutcome(fn () => $service->addMember(['team_id' => $empty, 'employee_id' => 7, 'valid_from' => '2026-11-23'], 'qa', 'admin')), 'servico: time desativado nao recebe membro');
// Arquivado com pertenca aberta: a versao o ignora e conta.
$pdo->members[$pdo->nextId] = ['id' => $pdo->nextId, 'team_id' => $team2, 'employee_id' => 9, 'valid_from' => '2026-10-01', 'valid_until' => null];
$pdo->nextId++;
$saved = [];
assert_same('ok', $qaOutcome(function () use (&$saved, $service, $team2, $rpp): void {
    $saved = $service->saveVersion(['team_id' => $team2, 'effective_from' => '2027-03-01', 'cycle' => $rpp], 'qa', 'admin');
}), 'servico: versao aceita com um membro arquivado');
assert_same(1, $saved['members_inactive'] ?? null, 'servico: cadastro arquivado na versao: ignorado e contado (nao recebe regra)');
assert_same(true, strpos(end($pdo->audits)['details'], 'inativos_ignorados=1') !== false, 'servico: ignorado na auditoria');

// Leitura: times, versoes e pertencas so com IDs (o nome do membro nao vem).
$list = $service->listTeams();
$t1 = array_values(array_filter($list['teams'], static fn (array $t): bool => $t['id'] === $teamId))[0];
assert_same(['id', 'employee_id', 'valid_from', 'valid_until', 'employee_active', 'is_current'], array_keys($t1['members'][0]), 'leitura: membro so com ID, datas e situacao');
assert_same(false, strpos((string)json_encode($list), 'Tecnico') !== false || strpos((string)json_encode($list), 'qa.') !== false, 'leitura: nenhum nome nem login na resposta');
assert_same(true, count($service->listTeams(true)['teams'][0]['members']) >= count($list['teams'][0]['members']), 'leitura: historico=1 traz tambem as encerradas');

// Invariancia: quem nao e membro nunca tem linha alterada, em operacoes aleatorias.
mt_srand(1115);
$pdo = qa_rotation_db();
$service = new RotationTeamService($pdo, $today);
$tA = $service->saveTeam(['name' => 'A', 'version' => ['effective_from' => '2026-10-01', 'cycle' => $rpp]], 'qa', 'admin')['team_id'];
$tB = $service->saveTeam(['name' => 'B', 'version' => ['effective_from' => '2026-10-01', 'cycle' => ['pattern' => $wk('PPR'), 'anchor_date' => '2026-10-01']]], 'qa', 'admin')['team_id'];
foreach ([1, 2, 4, 5, 7] as $who) {
    $pdo->rules += $rule(100 + $who, $who, ['always_onsite', 'even_days', 'always_remote'][$who % 3], '2026-09-01', null);
}
$outsiders = [5, 7];
$frozen = array_filter($pdo->rules, static fn (array $x): bool => in_array($x['employee_id'], $outsiders, true));
for ($step = 0; $step < 60; $step++) {
    $who = [1, 2, 4][mt_rand(0, 2)];
    $date = ScheduleRulePlanner::addDays('2026-11-16', mt_rand(0, 120));
    $team = mt_rand(0, 1) ? $tA : $tB;
    $op = mt_rand(0, 3);
    $qaOutcome(match ($op) {
        0 => fn () => $service->addMember(['team_id' => $team, 'employee_id' => $who, 'valid_from' => $date], 'qa', 'admin'),
        1 => fn () => $service->moveMember(['team_id' => $team, 'employee_id' => $who, 'valid_from' => $date], 'qa', 'admin'),
        2 => fn () => $service->saveVersion(['team_id' => $team, 'effective_from' => $date, 'cycle' => ['pattern' => array_map(static fn (): string => mt_rand(0, 1) ? 'onsite' : 'remote', range(1, 4)) + [0 => 'remote', 1 => 'onsite'], 'anchor_date' => '2026-10-01']], 'qa', 'admin'),
        default => fn () => ($open = array_key_first(array_filter($pdo->members, static fn (array $m): bool => $m['employee_id'] === $who && $m['valid_until'] === null))) !== null
            ? $service->endMember(['member_id' => $open, 'valid_until' => max($date, $pdo->members[$open]['valid_from'])], 'qa', 'admin') : null,
    });
    assert_same($frozen, array_filter($pdo->rules, static fn (array $x): bool => in_array($x['employee_id'], $outsiders, true)), "invariancia: quem nao e membro nao muda (passo {$step})");
    foreach ([1, 2, 4] as $member) {
        $history = array_values(array_filter($pdo->rules, static fn (array $x): bool => $x['employee_id'] === $member));
        assert_same([], ScheduleRulePlanner::violations($history), "invariancia: historico do membro {$member} sem violacao (passo {$step})");
        foreach (array_filter($pdo->members, static fn (array $m): bool => $m['employee_id'] === $member) as $membership) {
            for ($d = max($membership['valid_from'], '2026-11-16'); $d <= min($membership['valid_until'] ?? '2027-06-30', '2027-06-30'); $d = ScheduleRulePlanner::addDays($d, 7)) {
                assert_same($membership['team_id'], ScheduleRulePlanner::ruleAt($history, $d)['rotation_team_id'] ?? null, "invariancia: na pertenca a regra e do time ({$member}, {$d}, passo {$step})");
            }
        }
    }
}

// ----------------------------------------------------------------------------
// 6. Endpoint (leitura do codigo): permissao antes do corpo, CSRF, sem nome
// ----------------------------------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/portal/rotation_teams.php');
$permissionAt = strpos($endpoint, "if (!portal_role_can(\$role, 'escalas.manage')) {");
assert_same(true, $permissionAt !== false && $permissionAt < strpos($endpoint, '$data = portal_json_input();') && strpos($endpoint, "json_response(['sucesso' => true] + \$service->listTeams(\$withHistory));") < $permissionAt, 'endpoint: GET para quem le escalas; gravacao so com escalas.manage, conferida antes de ler o corpo');
assert_same(true, strpos($endpoint, "audit_log('TIME_RODIZIO_NEGADO'") !== false && strpos($endpoint, '], 403);') !== false, 'endpoint: recusa auditada com 403');
assert_same(1, substr_count($endpoint, 'portal_json_input()'), 'endpoint: corpo so pelo portal_json_input (CSRF e Content-Type)');
assert_same(['save_team', 'preview_version', 'save_version', 'add_member', 'move_member', 'end_member'], preg_match_all("/case '([a-z_]+)':/", $endpoint, $cases) ? $cases[1] : [], 'endpoint: as seis acoes do desenho (3.1) mais a previa');
assert_same(true, strpos($endpoint, 'portal_operational_error($e);') !== false, 'endpoint: erros pelo portal_operational_error (400, 403, 409, 503 sem detalhe interno)');

fwrite(STDOUT, 'QA dos times de rodizio: ' . $GLOBALS['qa_checks'] . " verificacoes OK\n");
