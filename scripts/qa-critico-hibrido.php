<?php
declare(strict_types=1);

// ============================================================================
// QA das equipes Critico e Hibrido (F9; desenho principal, 9.15)
//
// Sem banco:
// 1. estrutura da migration 020, do rollback, das linhas do ensaio e da
//    consulta de verificacao;
// 2. regras puras: a lista unica de equipes (PortalTeams), os motivos e o
//    limite da pausa por motivo e equipe (PauseRules) e os validadores.
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

// ----------------------------------------------------------------------------
// 2. Regras puras: equipes (PortalTeams) e pausa por motivo e equipe (PauseRules)
// ----------------------------------------------------------------------------
require_once $root . '/config.php';
require_once $root . '/classes/GerenciadorPausas.php';
require_once $root . '/services/PortalTeams.php';
require_once $root . '/services/PauseRules.php';

// Equipes: uma lista so, valores sem acento, apelidos na entrada (D-F9-1, D-F9-2).
assert_same(['n1', 'n2', 'lideranca', 'critico', 'hibrido'], PortalTeams::ALL, 'equipes: as cinco de cadastro, na ordem');
assert_same(['n1', 'n2', 'critico', 'hibrido'], PortalTeams::WORK_TEAMS, 'equipes de tecnico: N1, N2, Critico e Hibrido (a Lideranca fora do painel)');
assert_same(['n1', 'n2'], PortalTeams::COMMON_GROUP, 'grupo comum: so N1 e N2 (D-F9-10 a D-F9-12)');
$teamCases = [
    'n1' => 'n1', 'N1' => 'n1', ' n2 ' => 'n2', 'lideranca' => 'lideranca', 'Liderança' => 'lideranca',
    'Não se aplica' => 'lideranca', 'na' => 'lideranca', 'sem_equipe' => 'lideranca',
    'critico' => 'critico', 'Crítico' => 'critico', 'CRÍTICO' => 'critico', 'crítico' => 'critico',
    'hibrido' => 'hibrido', 'Híbrido' => 'hibrido', 'HÍBRIDO' => 'hibrido',
    'LIDERANÇA' => null, "lideran\u{00C3}\u{00A7}a" => null, 'supervisao' => null, '' => null, 'critica' => null, 'n3' => null, 'hb' => null,
];
foreach ($teamCases as $input => $expected) {
    assert_same($expected, validate_funcionario_equipe($input), "equipe do cadastro: '{$input}'");
    assert_same($expected, PortalTeams::normalize($input), "PortalTeams::normalize: '{$input}'");
}
assert_same([null, null, null], [PortalTeams::normalize(null), PortalTeams::normalize(['n1']), PortalTeams::normalize(1.5)], 'equipe: tipo errado e null');
foreach (['n1' => true, 'n2' => true, 'critico' => true, 'hibrido' => true, 'lideranca' => false, 'x' => false] as $team => $expected) {
    assert_same($expected, validate_equipe($team), "equipe de tecnico: {$team}");
}
assert_same(false, validate_equipe(null), 'equipe de tecnico: null');
foreach (['n1' => true, 'n2' => true, 'critico' => false, 'hibrido' => false, 'lideranca' => false] as $team => $expected) {
    assert_same($expected, PortalTeams::inCommonGroup($team), "grupo comum: {$team}");
}
assert_same(['N1', 'N2', 'Liderança', 'Crítico', 'Híbrido'], array_map([PortalTeams::class, 'label'], PortalTeams::ALL), 'rotulos das equipes');
assert_same(
    [['value' => 'critico', 'label' => 'Crítico', 'work_team' => true, 'common_group' => false], ['value' => 'lideranca', 'label' => 'Liderança', 'work_team' => false, 'common_group' => false]],
    [PortalTeams::catalog()[3], PortalTeams::catalog()[2]],
    'catalogo para o frontend: valor, rotulo, tecnico e grupo comum'
);

// Grupo da escala derivado da equipe (D-F9-12): Critico e Hibrido sempre separado.
$groupCases = [
    ['n1', null, 'comum'], ['n1', '', 'comum'], ['n1', 'comum', 'comum'], ['n1', 'separado', 'separado'],
    ['n2', 'separado', 'separado'], ['lideranca', null, 'comum'],
    ['critico', null, 'separado'], ['critico', 'separado', 'separado'], ['critico', 'comum', null],
    ['hibrido', null, 'separado'], ['hibrido', 'comum', null],
    ['n1', 'outro', null], ['critico', 'outro', null],
];
foreach ($groupCases as [$team, $requested, $expected]) {
    assert_same($expected, PortalTeams::scheduleGroupFor($team, $requested), 'grupo da escala: ' . $team . ' / ' . var_export($requested, true));
}

// Motivos por equipe: "Almoço" so no Critico (11.3, tratamento 4); sem equipe, a lista fechada inteira.
foreach (['n1', 'n2', 'hibrido', 'lideranca', null, 'x'] as $team) {
    assert_same(['Café', 'Pessoal', 'Reunião'], PauseRules::reasonsFor($team), 'motivos da equipe ' . var_export($team, true));
}
assert_same(['Café', 'Pessoal', 'Reunião', 'Almoço'], PauseRules::reasonsFor('critico'), 'motivos do Critico, com o Almoço');
$reasonCases = [
    // [motivo, equipe (null = sem equipe), esperado]
    ['Almoço', 'critico', 'Almoço'], ['Almoço', 'Crítico', 'Almoço'], ['Almoço', 'n1', null], ['Almoço', 'n2', null],
    ['Almoço', 'hibrido', null], ['Almoço', 'lideranca', null], ['Almoço', 'desconhecida', null], ['Almoço', null, 'Almoço'],
    ['Café', 'critico', 'Café'], ['Café', 'n1', 'Café'], ['Reunião', 'hibrido', 'Reunião'], ['Pessoal', null, 'Pessoal'],
    ['almoço', 'critico', null], ['Almoco', 'critico', null], ['Lanche', 'critico', null], ['Lanche', null, null], ['', null, null],
];
foreach ($reasonCases as [$reason, $team, $expected]) {
    $actual = $team === null ? validate_motivo_pausa($reason) : validate_motivo_pausa($reason, $team);
    assert_same($expected, $actual, "motivo '{$reason}' na equipe " . var_export($team, true));
}
assert_same([null, null], [validate_motivo_pausa(['Café']), PauseRules::validReason(1)], 'motivo: tipo errado');

// Limite simultaneo e alertas: nem Pessoal, nem Reuniao, nem Almoco (D-F9-5).
foreach (['Café' => true, 'Pessoal' => false, 'pessoal' => false, 'Reunião' => false, 'reuniao' => false, 'Almoço' => false, 'Outro' => true, '' => true] as $reason => $expected) {
    assert_same($expected, PauseRules::countsForTeamLimit($reason), "conta no limite e nos alertas: '{$reason}'");
}
assert_same(PauseRules::countsForTeamLimit('Almoço'), GerenciadorPausas::motivo_conta_para_limite('Almoço'), 'GerenciadorPausas usa a mesma regra (Almoço)');
foreach (['Café', 'Pessoal', 'Reunião'] as $reason) {
    assert_same(PauseRules::countsForTeamLimit($reason), GerenciadorPausas::motivo_conta_para_limite($reason), "GerenciadorPausas usa a mesma regra ({$reason})");
}
assert_same([[false, false], [true, false], [true, true], [false, false], [false, false], [false, false]], [
    PauseRules::alerts('Café', 899), PauseRules::alerts('Café', 900), PauseRules::alerts('Café', 1200),
    PauseRules::alerts('Almoço', 3600), PauseRules::alerts('Pessoal', 3600), PauseRules::alerts('Reunião', 3600),
], 'alertas de 15 e 20 min: so o cafe, nas bordas 900 e 1200 s; o almoco nunca');

// Intervalo de almoco do cadastro (D-F9-6 revista): fim depois do inicio, no mesmo dia.
$intervalCases = [
    ['12:00', '13:00', 3600], ['12:00:00', '13:00:00', 3600], ['11:30', '12:45', 4500], ['12:00', '12:01', 60],
    ['12:00:00', '12:00:30', 30], ['13:00', '12:00', null], ['12:00', '12:00', null], ['23:00', '00:30', null],
    ['', '13:00', null], ['12:00', null, null], ['24:00', '25:00', null], ['24:00', '24:30', null], ['12h', '13h', null], ['1200', '1300', null],
];
foreach ($intervalCases as [$start, $end, $expected]) {
    assert_same($expected, PauseRules::lunchIntervalSeconds($start, $end), 'intervalo do almoco: ' . var_export($start, true) . ' a ' . var_export($end, true));
}

// Limite aplicado: cafe pela configuracao; almoco pelo contrato, senao o intervalo, senao sem limite.
$limitCases = [
    // [motivo, minutos, intervalo, contrato, esperado]
    ['Café', 20, null, null, 1200], ['Café', 25, 3600, 5400, 1500], ['Café', 0, null, null, null],
    ['Pessoal', 20, 3600, 5400, null], ['Reunião', 20, 3600, null, null],
    ['Almoço', 20, 3600, null, 3600], ['Almoço', 20, 4500, null, 4500], ['Almoço', 20, null, null, null],
    ['Almoço', 20, 3600, 5400, 5400], ['Almoço', 20, null, 2700, 2700], ['Almoço', 20, 3600, 0, 3600], ['Almoço', 20, 0, null, null],
];
foreach ($limitCases as [$reason, $minutes, $interval, $contract, $expected]) {
    assert_same($expected, PauseRules::appliedLimitSeconds($reason, $minutes, $interval, $contract), "limite aplicado: {$reason}, {$minutes} min, intervalo " . var_export($interval, true) . ', contrato ' . var_export($contract, true));
}

// Excedeu: com o limite gravado; antigas pela configuracao atual; almoco sem limite nunca.
$exceededCases = [
    // [motivo, duracao, limite gravado, minutos atuais, esperado]
    ['Café', 1201, null, 20, true], ['Café', 1200, null, 20, false], ['Café', 1300, 1500, 20, false], ['Café', 1501, 1500, 20, true],
    ['Café', 1201, null, 25, false], ['Café', 1201, '1200', 25, true], ['Café', 1201, '', 20, true],
    ['Almoço', 9999, null, 20, false], ['Almoço', 3601, 3600, 20, true], ['Almoço', 3600, 3600, 20, false], ['Almoço', 3601, '3600', 20, true],
    ['Pessoal', 9999, null, 20, false], ['Reunião', 9999, null, 20, false], ['Café', 50, 0, 20, false], ['Café', 50, null, 0, false],
];
foreach ($exceededCases as [$reason, $duration, $stored, $minutes, $expected]) {
    assert_same($expected, PauseRules::exceeded($reason, $duration, $stored, $minutes), "excedeu: {$reason}, {$duration} s, gravado " . var_export($stored, true) . ", {$minutes} min");
}

// Fonte do almoco no motor (D-F9-8): pausa so no Critico.
assert_same(['cadastro', 'cadastro', 'pausa', 'cadastro', 'cadastro', 'cadastro'], array_map([PauseRules::class, 'lunchSource'], ['n1', 'n2', 'critico', 'hibrido', 'lideranca', null]), 'fonte do almoco: pausa so no Critico');

// Totais e reuniao.
assert_same([true, true, true, false], array_map([PauseRules::class, 'countsInTotals'], ['Café', 'Pessoal', 'Reunião', 'Almoço']), 'totais e medias: o almoco fica fora');
assert_same([true, false, true, true, true, true], array_map([PauseRules::class, 'meetingNeedsApproval'], ['n1', 'n2', 'critico', 'hibrido', 'lideranca', null]), 'reuniao sem aprovacao so no N2 (D-F9-9)');

// "Almoço" fora do Critico: 400 na API e recusa no gerenciador, pela equipe do cadastro (nunca a do cliente).
$startApi = (string)file_get_contents($root . '/api/iniciar_pausa.php');
$managerSource = (string)file_get_contents($root . '/classes/GerenciadorPausas.php');
assert_same(1, preg_match('/\$cadastro = \$gerenciador->getFuncionario\(\$funcionario_id\);\s+if \(\$cadastro !== null && validate_motivo_pausa\(\$motivo, \$cadastro->equipe\) === null\) \{\s+json_response\(\["sucesso" => false, "mensagem" => PauseRules::LUNCH_ONLY_CRITICAL_MESSAGE\], 400\);/', $startApi), 'iniciar_pausa.php: motivo conferido com a equipe do cadastro, 400');
assert_same(true, strpos($startApi, 'validate_motivo_pausa($motivo, $cadastro->equipe)') < strpos($startApi, 'with_pause_state_lock('), 'iniciar_pausa.php: a conferencia antes de iniciar');
assert_same(1, preg_match('/if \(validate_motivo_pausa\(\$motivo, \$funcionario->equipe\) === null\) \{\s+return \["sucesso" => false, "codigo" => "motivo_da_equipe", "mensagem" => PauseRules::LUNCH_ONLY_CRITICAL_MESSAGE\];/', $managerSource), 'GerenciadorPausas::iniciar_pausa: recusa o motivo fora da equipe do cadastro (defesa)');
assert_same(true, strpos($managerSource, 'validate_motivo_pausa($motivo, $funcionario->equipe)') < strpos($managerSource, '$funcionario->em_pausa = true;'), 'GerenciadorPausas::iniciar_pausa: a recusa antes de marcar a pausa');

// Viewer scope (avisos e anexos): a equipe do cadastro, inclusive as novas (D-F9-14).
$scopeSource = (string)file_get_contents($root . '/security.php');
assert_same(1, preg_match("/\\\$team = validate_funcionario_equipe\\(\\(string\\)\\\$stmt->fetchColumn\\(\\)\\);\\s+return \\['all' => false, 'team' => \\\$team\\];/", $scopeSource), 'portal_viewer_scope: a equipe do cadastro pela lista unica (Critico e Hibrido incluidos)');
foreach (['critico', 'hibrido'] as $team) {
    assert_same([true, true, false, false], [
        announcement_visible(['all' => false, 'team' => $team], $team),
        announcement_visible(['all' => false, 'team' => $team], 'all'),
        announcement_visible(['all' => false, 'team' => $team], 'n1'),
        announcement_visible(['all' => false, 'team' => 'n1'], $team),
    ], "avisos: o tecnico do {$team} ve o dele e o de todos, e nada de outra equipe");
}

echo 'QA critico e hibrido OK (' . ($GLOBALS['qa_checks'] ?? 0) . " verificacoes)\n";
