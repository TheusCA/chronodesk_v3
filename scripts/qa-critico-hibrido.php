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
// 3. servidor: painel com as quatro equipes, pausa de almoco do Critico,
//    limite gravado, Metricas, motor (almoco pela pausa, jornada do dia) e
//    cadastro (grupo da escala derivado), com estado e historico em
//    diretorio temporario e sem banco.
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

// Isolamento (secoes 2 e 3): estado e historico das pausas num diretorio
// temporario e banco simulado ausente; nada toca os arquivos da aplicacao.
putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$qaStateDir = sys_get_temp_dir() . '/chronodesk-qa-f9-' . bin2hex(random_bytes(6));
if (!mkdir($qaStateDir, 0700)) {
    fwrite(STDERR, "FALHOU: nao criou diretorio temporario do QA\n");
    exit(1);
}
register_shutdown_function(static function () use ($qaStateDir): void {
    foreach (glob($qaStateDir . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($qaStateDir);
});
putenv('PHP_ERROR_LOG=' . $qaStateDir . '/php-error.log');
define('ESTADO_JSON', $qaStateDir . '/estado.json');
define('PAUSAS_CSV', $qaStateDir . '/pausas.csv');
define('CONFIG_JSON', $qaStateDir . '/config_sistema.json');
define('FUNCIONARIOS_JSON', $qaStateDir . '/funcionarios.json');
define('CHRONODESK_STATELESS', true);
$GLOBALS['qa_pdo'] = null;
function get_db_connection() {
    if (!$GLOBALS['qa_pdo'] instanceof PDO) {
        throw new PDOException('SQLSTATE[HY000]: banco simulado ausente');
    }
    return $GLOBALS['qa_pdo'];
}

$root = dirname(__DIR__);
$position =static fn (string $sql, string $needle): int => ($found = strpos($sql, $needle)) === false ? -1 : $found;

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

// ----------------------------------------------------------------------------
// 3. Servidor: painel, pausa de almoco, limite gravado, Metricas, motor e cadastro
// ----------------------------------------------------------------------------
require_once $root . '/services/PresenceEngine.php';
require_once $root . '/services/PresenceRepository.php';

$qaWriteState = static function (array $entries): void {
    file_put_contents(ESTADO_JSON, json_encode(['funcionarios' => $entries], JSON_UNESCAPED_UNICODE));
};
$newManager = static function (int $limit = 2, int $minutes = 20) use ($qaWriteState): GerenciadorPausas {
    $qaWriteState([]);
    $manager = new GerenciadorPausas($limit, $minutes);
    foreach ([
        [41, 'n1', '12:00', '13:00'], [42, 'n2', '12:00', '13:00'], [43, 'critico', '12:00', '13:00'],
        [44, 'critico', '11:30', '12:45'], [45, 'hibrido', '12:00', '13:00'], [46, 'lideranca', '12:00', '13:00'],
        [47, 'supervisao', '12:00', '13:00'], [48, 'critico', '13:00', '13:00'], [49, 'Crítico', '12:00', '13:00'],
    ] as [$id, $team, $lunchStart, $lunchEnd]) {
        $manager->adicionar_funcionario(new Funcionario($id, "Tecnico {$id}", $team, '00:00', '23:59', $lunchStart, $lunchEnd, true));
    }
    $manager->carregar_estado();
    return $manager;
};

// Painel (D-F9-3): uma chave por equipe de tecnico; Lideranca e desconhecida fora.
$manager = $newManager();
$status = $manager->obter_status();
assert_same(['n1', 'n2', 'critico', 'hibrido', 'server_now', 'poll_interval_seconds'], array_keys($status), 'status: chaves n1, n2, critico e hibrido (aditivo)');
assert_same([[41], [42], [43, 44, 48, 49], [45]], [array_column($status['n1'], 'id'), array_column($status['n2'], 'id'), array_column($status['critico'], 'id'), array_column($status['hibrido'], 'id')], 'status: cada tecnico na sua equipe; Lideranca e equipe desconhecida fora');
assert_same(['critico', 'critico'], [$status['critico'][0]['equipe'], $status['critico'][3]['equipe']], 'status: equipe normalizada (Crítico vira critico)');

// Observacao da reuniao (DV11): so a gestao e o proprio, tambem no Critico e no Hibrido.
$withNote = ['n1' => [], 'n2' => [], 'critico' => [['id' => 43, 'observacao_reuniao' => 'texto']], 'hibrido' => [['id' => 45, 'observacao_reuniao' => 'texto']]];
$filtered = GerenciadorPausas::status_para_o_perfil($withNote, ['role' => 'tecnico', 'employee_id' => 41]);
assert_same([false, false], [array_key_exists('observacao_reuniao', $filtered['critico'][0]), array_key_exists('observacao_reuniao', $filtered['hibrido'][0])], 'status: observacao da reuniao do Critico e do Hibrido fora para outro tecnico');
$own = GerenciadorPausas::status_para_o_perfil($withNote, ['role' => 'tecnico', 'employee_id' => 43]);
assert_same(['texto', false], [$own['critico'][0]['observacao_reuniao'] ?? null, array_key_exists('observacao_reuniao', $own['hibrido'][0])], 'status: o proprio ve a sua observacao');

// "Almoço": so o Critico; fora do limite simultaneo; limite do intervalo do cadastro.
$manager = $newManager(1);
$refused = $manager->iniciar_pausa(41, 'Almoço');
assert_same([false, 'motivo_da_equipe', PauseRules::LUNCH_ONLY_CRITICAL_MESSAGE], [$refused['sucesso'], $refused['codigo'] ?? null, $refused['mensagem']], 'iniciar_pausa: Almoço no N1 recusado');
assert_same(false, $manager->iniciar_pausa(45, 'Almoço')['sucesso'], 'iniciar_pausa: Almoço no Hibrido recusado (AD-F9-4: pelo cadastro)');
assert_same(true, $manager->iniciar_pausa(43, 'Almoço')['sucesso'], 'iniciar_pausa: Almoço no Critico aceito');
assert_same(true, $manager->iniciar_pausa(44, 'Café')['sucesso'], 'limite 1 por equipe: o almoco nao ocupa a vaga do cafe (D-F9-5)');
$coffeeRefused = $manager->iniciar_pausa(48, 'Café');
assert_same([false, true], [$coffeeRefused['sucesso'], strpos($coffeeRefused['mensagem'], 'Limite de pausas simultâneas') === 0], 'limite 1 por equipe: o 2o cafe do Critico recusado');
assert_same(true, $manager->iniciar_pausa(48, 'Almoço')['sucesso'], 'almoco sem limite simultaneo: o 2o almoco do Critico aceito');
assert_same(true, $manager->iniciar_pausa(41, 'Café')['sucesso'], 'limite por equipe: o cafe do N1 nao conta com o Critico');
$status = $manager->obter_status();
$byId = [];
foreach (['n1', 'n2', 'critico', 'hibrido'] as $team) {
    foreach ($status[$team] as $item) {
        $byId[$item['id']] = $item;
    }
}
assert_same([3600, 1200, 0, 1200, 1200], [$byId[43]['duracao_limite_segundos'], $byId[44]['duracao_limite_segundos'], $byId[48]['duracao_limite_segundos'], $byId[41]['duracao_limite_segundos'], $byId[42]['duracao_limite_segundos']], 'status: almoco pelo intervalo do cadastro (3600), cafe pela configuracao (1200), almoco sem intervalo valido 0 (sem limite); sem pausa, a configuracao, como antes');

// Finalizar (sem banco: historico no CSV de reserva): sem alerta no almoco; excedeu pelo limite aplicado.
$manager = $newManager();
$manager->iniciar_pausa(43, 'Almoço');
$manager->getFuncionario(43)->inicio_pausa = new DateTime('-70 minutes');
$finished = $manager->finalizar_pausa(43);
assert_same([true, true, false, false], [$finished['sucesso'], strpos($finished['mensagem'], '(Excedeu o tempo limite de 60 minutos!)') !== false, strpos($finished['mensagem'], 'ALERTA') !== false, strpos($finished['mensagem'], 'ATENÇÃO') !== false], 'finalizar: almoco de 70 min excede o intervalo de 60, sem alerta de 15/20 min');
$csv = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', '\\'), array_values(array_filter(explode("\n", (string)file_get_contents(PAUSAS_CSV)))));
assert_same(['Almoço', 'False', 'False', 11], [$csv[0][6], $csv[0][7], $csv[0][8], count($csv[0])], 'CSV de reserva: almoco sem alerta e no formato de antes (11 colunas, sem o limite: o cabecalho antigo continua valendo)');
$manager->iniciar_pausa(44, 'Café');
$manager->getFuncionario(44)->inicio_pausa = new DateTime('-21 minutes');
$finished = $manager->finalizar_pausa(44);
assert_same([true, true, true], [strpos($finished['mensagem'], '(Excedeu o tempo limite de 20 minutos!)') !== false, strpos($finished['mensagem'], 'ALERTA CRÍTICO') !== false, $finished['sucesso']], 'finalizar: cafe de 21 min excede 20 e da o alerta de 20 min, como antes');
$manager->iniciar_pausa(48, 'Almoço');
$manager->getFuncionario(48)->inicio_pausa = new DateTime('-200 minutes');
$finished = $manager->finalizar_pausa(48);
assert_same([true, false], [$finished['sucesso'], strpos($finished['mensagem'], 'Excedeu') !== false], 'finalizar: almoco sem intervalo valido nunca excede (DL17)');
$managerSource = (string)file_get_contents($root . '/classes/GerenciadorPausas.php');
assert_same([1, 1], [preg_match('/alerta_20min, limite_segundos,\s+status_aprovacao, observacao_reuniao\s+\) VALUES \(/', $managerSource), substr_count($managerSource, "':limite_segundos' => \$limite_segundos,")], 'finalizar: grava o limite aplicado em pausas.limite_segundos (D-F9-7)');
assert_same(3, substr_count($managerSource, 'alerta_15min, alerta_20min, limite_segundos,'), 'o INSERT e as duas leituras das Metricas trazem o limite gravado');

// Metricas: excedeu pelo limite gravado; almoco fora dos totais, dentro de "por motivo".
$process = new ReflectionMethod(GerenciadorPausas::class, 'processar_linha_metrica');
$averages = new ReflectionMethod(GerenciadorPausas::class, 'calcular_medias_metricas');
$manager = $newManager(2, 25);
$m = ['total_pausas_funcionario' => [], 'duracao_total_funcionario' => [], 'duracao_media_funcionario' => [], 'total_pausas_equipe' => [], 'duracao_total_equipe' => [], 'duracao_media_equipe' => [], 'pausas_excedidas' => [], 'total_pausas_por_motivo' => [], 'duracao_total_por_motivo' => [], 'duracao_media_por_motivo' => [], 'alertas_15min_funcionario' => [], 'alertas_15min_equipe' => [], 'alertas_20min_funcionario' => [], 'alertas_20min_equipe' => [], 'pausas_reuniao_aprovadas' => [], 'pausas_reuniao_rejeitadas' => [], 'pausas_reuniao_pendentes' => [], 'pausas_detalhadas' => []];
$row = static fn (int $id, string $team, string $reason, int $seconds, ?int $limit): array => ['id_funcionario' => $id, 'nome_funcionario' => "Tecnico {$id}", 'equipe' => $team, 'inicio_pausa' => '2026-11-16 12:00:00', 'fim_pausa' => '2026-11-16 13:00:00', 'duracao_segundos' => $seconds, 'motivo_pausa' => $reason, 'alerta_15min' => 0, 'alerta_20min' => 0, 'limite_segundos' => $limit, 'status_aprovacao' => 'aprovado', 'observacao_reuniao' => ''];
foreach ([
    $row(43, 'critico', 'Almoço', 3700, 3600),  // excede o limite gravado
    $row(43, 'critico', 'Almoço', 9999, null),  // sem limite gravado: nunca excede
    $row(43, 'critico', 'Café', 1300, 1200),    // gravado 1200: excede (a configuracao atual de 25 min nao reescreve)
    $row(44, 'critico', 'Café', 1300, null),    // antiga: configuracao atual (25 min), nao excede
    $row(41, 'n1', 'Café', 1600, null),         // antiga: excede 25 min
] as $data) {
    $args = [&$m, $data];
    $process->invokeArgs($manager, $args);
}
$averages->invokeArgs($manager, [&$m]);
assert_same(['Tecnico 43 (ID: 43)' => 2, 'Tecnico 41 (ID: 41)' => 1], $m['pausas_excedidas'], 'Metricas: excedeu pelo limite gravado; antigas pela configuracao atual; almoco sem limite nunca');
assert_same([['Tecnico 43 (ID: 43)' => 1, 'Tecnico 44 (ID: 44)' => 1, 'Tecnico 41 (ID: 41)' => 1], ['critico' => 2, 'n1' => 1], ['critico' => 2600, 'n1' => 1600]], [$m['total_pausas_funcionario'], $m['total_pausas_equipe'], $m['duracao_total_equipe']], 'Metricas: o almoco fica fora dos totais por funcionario e por equipe');
assert_same([['Almoço' => 2, 'Café' => 3], ['Almoço' => 13699, 'Café' => 4200]], [$m['total_pausas_por_motivo'], $m['duracao_total_por_motivo']], 'Metricas: o almoco continua em "por motivo"');
assert_same([5, [true, false, true, false, true], [3600, null, 1200, null, null]], [count($m['pausas_detalhadas']), array_column($m['pausas_detalhadas'], 'excedeu_limite'), array_column($m['pausas_detalhadas'], 'limite_segundos')], 'Metricas: detalhe com excedeu e o limite gravado');
$exportSource = (string)file_get_contents($root . '/api/download_relatorio.php');
assert_same(0, substr_count($exportSource, 'limite_segundos'), 'exportacao: colunas de antes (o limite nao vira coluna nova no CSV)');

// Motor (D-F9-8): almoco do Critico pela pausa; N1 pelo cadastro, como antes.
$employee = static fn (string $team): array => ['id' => 1, 'team' => $team, 'work_start' => '08:00:00', 'work_end' => '17:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00', 'lunch_source' => PauseRules::lunchSource($team)];
$onsite = [['id' => 1, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-01', 'effective_until' => null]];
$at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-11-16 ' . $time);
$presence = static fn (string $team, string $time, ?array $pause = null, array $absences = []): array => PresenceEngine::nowPresence($employee($team), $at($time), $onsite, null, $absences, $pause);
$lunchPause = ['em_pausa' => true, 'motivo' => 'Almoço', 'inicio' => '12:05'];
$cases = [
    // [equipe, hora, pausa, [situacao, origem, detalhe]]
    ['n1', '12:30', null, ['almoco', 'expediente', 'Almoço 12:00–13:00']],
    ['critico', '12:30', null, ['presencial', 'regra_escala', null]],
    ['critico', '15:10', $lunchPause, ['almoco', 'pausa', 'Almoço desde 12:05']],
    ['critico', '12:30', ['em_pausa' => true, 'motivo' => 'Almoço'], ['almoco', 'pausa', 'Almoço']],
    ['critico', '10:00', ['em_pausa' => true, 'motivo' => 'Café', 'inicio' => '09:55'], ['pausa', 'pausa', 'Café']],
    ['n1', '10:00', $lunchPause, ['pausa', 'pausa', 'Almoço']],
    ['critico', '18:00', $lunchPause, ['fora_expediente', 'expediente', null]],
];
foreach ($cases as [$team, $time, $pause, [$situation, $origin, $detail]]) {
    $result = $presence($team, $time, $pause);
    assert_same([$situation, $origin], [$result['situacao'], $result['origem']], "motor: {$team} as {$time} " . json_encode($pause, JSON_UNESCAPED_UNICODE));
    if ($detail !== null) {
        assert_same($detail, $result['detalhe'], "motor: detalhe de {$team} as {$time}");
    }
}
assert_same('ausente', $presence('critico', '12:30', $lunchPause, [['origem' => 'ausencia', 'period_type' => 'full_day']])['situacao'], 'motor: ausencia vence o almoco pela pausa (4.3)');
assert_same(1, PresenceEngine::summarize([$presence('critico', '12:30', $lunchPause), $presence('critico', '12:30')])['almoco'], 'motor: o almoco pela pausa conta em "almoco" na soma (4.5)');
$repositorySource = (string)file_get_contents($root . '/services/PresenceRepository.php');
assert_same([1, 1], [substr_count($repositorySource, "'lunch_source' => PauseRules::lunchSource(\$team),"), preg_match('/public static function shiftFor\(array \$employee, string \$date\): array \{\s+return \$employee;\s+\}/', $repositorySource)], 'repositorio: fonte do almoco pela equipe e a jornada do dia numa funcao unica (hoje, a do cadastro)');
assert_same(['x' => 1], PresenceRepository::shiftFor(['x' => 1], '2026-11-16'), 'jornada do dia: hoje, o cadastro sem mudanca (9.15.6)');
$serviceSource = (string)file_get_contents($root . '/services/PresenceService.php');
assert_same(3, substr_count($serviceSource, 'PresenceRepository::shiftFor('), 'presenca: periodo, status e Visao operacional pedem a jornada do dia');
assert_same(1, preg_match('/\$common = array_merge\(\.\.\.array_map\(static fn \(string \$team\): array => \$byTeam\[\$team\], PortalTeams::COMMON_GROUP\)\);\s+return \$summary \+ \[\'total\' => PresenceEngine::summarize\(\$common\)\];/', $serviceSource), 'Visao operacional: total = grupo comum (N1 + N2), Critico e Hibrido em linhas proprias (D-F9-10)');

// Disponibilidade do cadastro: o Critico nao fica "Horario de almoco" pelo intervalo.
$n1 = new Funcionario(51, 'Tecnico 51', 'n1', '08:00', '17:00', '12:00', '13:00', true);
$critical = new Funcionario(52, 'Tecnico 52', 'critico', '08:00', '17:00', '12:00', '13:00', true);
assert_same([false, true], [$n1->esta_disponivel('12:30'), $critical->esta_disponivel('12:30')], 'cadastro: o intervalo do cadastro bloqueia o N1, nao o Critico');

// Endpoints e servicos pela lista unica.
$sourceChecks = [
    'api/portal/dashboard.php' => ['$funcionarios = array_merge(...array_map(static fn (string $team): array => $status[$team] ?? [], PortalTeams::WORK_TEAMS));'],
    'api/portal/technicians.php' => ['$equipe = PortalTeams::normalize($funcionario->equipe);', "if (!(\$funcionario->ativo ?? true) || !PortalTeams::isWorkTeam(\$equipe)) {", "'team' => PortalTeams::label(\$equipe),"],
    'services/OperationalService.php' => ['WHERE LOWER(f.equipe) IN ("n1", "n2", "critico", "hibrido")', 'if (!$employee || !PortalTeams::isWorkTeam($team)) {', '$team = PortalTeams::normalize(is_scalar($value) ? (string)$value : null);'],
    'services/AbsenceService.php' => ['private const TEAMS = PortalTeams::ALL;'],
    'services/ShiftAttachmentService.php' => ['public const TEAMS = PortalTeams::ALL;'],
    'services/PaMapService.php' => ["if (in_array(\$team, ['n1', 'n2', 'critico', 'hibrido'], true)) {"],
    'services/RotationTeamService.php' => ["if (\$employee['team'] === PortalTeams::CRITICAL || \$employee['team'] === PortalTeams::HYBRID) {", 'if (!PortalTeams::inCommonGroup($employee[\'team\'])) {'],
    'api/atualizar_funcionario.php' => ['if (PortalTeams::scheduleGroupFor($equipe, $grupo_escala_sent ? $grupo_escala : null) === null) {', "\$grupo_escala = 'separado';"],
    'api/adicionar_funcionario.php' => ["if (PortalTeams::scheduleGroupFor(\$equipe, null) === 'separado') {"],
];
foreach ($sourceChecks as $file => $needles) {
    $source = (string)file_get_contents($root . '/' . $file);
    foreach ($needles as $needle) {
        assert_same(1, substr_count($source, $needle), "{$file}: {$needle}");
    }
}
$update = (string)file_get_contents($root . '/api/atualizar_funcionario.php');
assert_same(true, strpos($update, 'PortalTeams::scheduleGroupFor($equipe') < strpos($update, '$pdo->beginTransaction();') && strpos($update, "\$grupo_escala = 'separado';") < strpos($update, "if (\$grupo_escala_sent) {\n        \$grupoStmt"), 'atualizar_funcionario: grupo derivado antes da transacao, gravado nela com a equipe (D-F9-12)');
$add = (string)file_get_contents($root . '/api/adicionar_funcionario.php');
assert_same(true, strpos($add, "UPDATE funcionarios SET grupo_escala = 'separado' WHERE id = :id") > strpos($add, '$pdo->beginTransaction();') && strpos($add, "UPDATE funcionarios SET grupo_escala = 'separado' WHERE id = :id") < strpos($add, '$pdo->commit();'), 'adicionar_funcionario: Critico e Hibrido no grupo separado na mesma transacao');

echo 'QA critico e hibrido OK (' . ($GLOBALS['qa_checks'] ?? 0) . " verificacoes)\n";
