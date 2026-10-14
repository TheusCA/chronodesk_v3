<?php
declare(strict_types=1);

// ============================================================================
// QA da data de corte do motor de presenca (Parte B, F5; desenho, secao 9.7)
//
// 1. Migration 019, registro do corte, rollback e verificacao (estrutura; o
//    SQL so roda no servidor e no MySQL do responsavel: ensaio e teste).
// 2. PresenceCutoff: divisao do periodo no corte (funcao pura), aviso do CSV
//    e leitura do corte (sem a 019, vazia, valida, invalida).
// 3. Relatorio e CSV divididos no corte (commit 2): parte antiga identica,
//    motor a partir do corte (P3, P4, ausencia, parcial, Lideranca, fora do
//    Mapa, arquivado ate a vespera), totais, aviso, filtros e campos novos so
//    quando o periodo alcanca o corte.
// 4. Calendario dividido no corte (commit 3): escala antiga igual antes do
//    corte; depois, um evento do motor por tecnico e dia util, com situacao,
//    origem e detalhe; ausencia como situacao (evento cortado na vespera,
//    inteiro para quem nao esta no motor); categoria so para a gestao e o
//    proprio (P9), observacao nunca; filtros type e status; sem relogio.
//
// Nao acessa banco (usa um banco simulado) nem os arquivos de estado da aplicacao.
//
// Uso: php scripts/qa-presence-cutoff.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$qaStateDir = sys_get_temp_dir() . '/chronodesk-qa-cutoff-' . bin2hex(random_bytes(6));
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

$root = dirname(__DIR__);
$checks = 0;

function assert_same($expected, $actual, string $message): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n  esperado: " . var_export($expected, true) . "\n  obtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// ----------------------------------------------------------------------------
// 1. Migration 019, registro do corte, rollback e verificacao (estrutura)
// ----------------------------------------------------------------------------
$sqlFile = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    assert_same(true, is_string($content) && $content !== '', "{$path}: arquivo lido");
    return (string)preg_replace('/^\s*--.*$/m', '', (string)$content);
};
$rawFile = static fn (string $path): string => (string)file_get_contents($root . '/' . $path);
$migration = $sqlFile('migrations/20261013_019_presence_cutoff.sql');
$register = $sqlFile('migrations/20261013_019_registrar_corte.sql');
$rollback = $sqlFile('migrations/rollback/20261013_019_presence_cutoff_down.sql');
$check = $sqlFile('migrations/verificacao/20261013_019_presence_cutoff_check.sql');
$at = static fn (string $sql, string $needle): int => ($found = strpos($sql, $needle)) === false ? -1 : $found;
$clock = '/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP|LOCALTIME|LOCALTIMESTAMP)\b/i';

foreach (['019' => $migration, 'registro 019' => $register, 'rollback 019' => $rollback, 'verificacao 019' => $check] as $label => $sql) {
    assert_same(1, preg_match("/^\\s*SET time_zone = 'America\\/Sao_Paulo';/", $sql), "{$label}: comeca com SET time_zone (regra do A4)");
    assert_same(0, preg_match('/\b(employee_id|id_funcionario)\b/i', $sql), "{$label}: nao toca dado de colaborador");
    assert_same(0, preg_match('/\b(nome|ad_login|employee_name|reason|note|observacao\w*)\b/i', $sql), "{$label}: sem nome, login nem texto livre");
    assert_same(0, preg_match('/SELECT\s+\*/i', $sql), "{$label}: sem SELECT *");
}
foreach (['019' => $migration, 'rollback 019' => $rollback] as $label => $sql) {
    assert_same(0, preg_match($clock, $sql), "{$label}: sem relogio do banco (regra do A4)");
}
// Registro: o relogio so na checagem de data passada (leitura), nunca num valor gravado.
assert_same(1, preg_match_all($clock, $register), 'registro 019: um unico uso do relogio');
assert_same(true, $at($register, 'IF corte < CURDATE() THEN') > -1, 'registro 019: o relogio so na recusa de data passada');
assert_same(0, preg_match('/(VALUES|SELECT)[^;]*\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE)\b/is', $register), 'registro 019: nenhum valor gravado vem do relogio');
// Verificacao: "hoje" uma vez, depois do SET time_zone e antes das consultas.
assert_same(1, preg_match_all($clock, $check), 'verificacao 019: um unico uso do relogio');
$today = $at($check, 'SET @hoje := CURDATE();');
assert_same(true, $today > $at($check, "SET time_zone = 'America/Sao_Paulo';") && $today < $at($check, 'SELECT'), 'verificacao 019: @hoje depois do SET time_zone e antes das consultas');

foreach (['019' => $migration, 'rollback 019' => $rollback, 'verificacao 019' => $check] as $label => $sql) {
    $prepares = preg_match_all('/^PREPARE /m', $sql);
    assert_same(true, $prepares > 0, "{$label}: usa PREPARE");
    assert_same([$prepares, $prepares], [preg_match_all('/^EXECUTE /m', $sql), preg_match_all('/^DEALLOCATE PREPARE /m', $sql)], "{$label}: todo PREPARE tem EXECUTE e DEALLOCATE");
    preg_match_all('/^PREPARE (\w+) FROM @(\w+);/m', $sql, $prepared, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    assert_same($prepares, count($prepared), "{$label}: todo PREPARE le uma variavel");
    foreach ($prepared as $statement) {
        $assigned = $at($sql, "SET @{$statement[2][0]} := ");
        assert_same(true, $assigned > -1 && $assigned < $statement[0][1], "{$label}: @{$statement[2][0]} atribuida antes do PREPARE");
    }
}

// Migration: so a tabela, vazia, idempotente.
assert_same(0, preg_match('/^\s*(CREATE|ALTER|DROP)\s+TABLE/mi', $migration), '019: CREATE TABLE so dentro do IF com checagem em information_schema (idempotente)');
assert_same(1, preg_match("/SET @create_cutoff_sql := IF\\(\\s*@has_cutoff_table = 0,/", $migration), '019: cria so se a tabela nao existe');
assert_same(true, $at($migration, "TABLE_NAME = 'portal_presence_cutoff'") > -1, '019: checagem em information_schema');
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|TRUNCATE|REPLACE)\b/mi', $migration), '019: sem linha (tabela vazia: nada muda ate o registro)');
// Fora dos dois gatilhos (abaixo), nenhuma parada, remocao ou data do banco.
$triggerSql = [
    'update' => "CREATE TRIGGER IF NOT EXISTS trg_presence_cutoff_no_update BEFORE UPDATE ON portal_presence_cutoff FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'corte imutavel: use o rollback da 019';",
    'delete' => "CREATE TRIGGER IF NOT EXISTS trg_presence_cutoff_no_delete BEFORE DELETE ON portal_presence_cutoff FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'corte imutavel: use o rollback da 019';",
];
$flatMigration = (string)preg_replace('/\s+/', ' ', $migration);
assert_same(0, preg_match('/\b(SIGNAL|DROP|DEFAULT CURRENT|ON UPDATE)\b/i', str_replace($triggerSql, '', $flatMigration)), '019: sem parada, sem remocao e sem data do banco fora dos gatilhos');
foreach ([
    'id TINYINT UNSIGNED NOT NULL' => 'chave de uma linha',
    'cutoff_date DATE NOT NULL' => 'data do corte',
    'registered_by VARCHAR(100) NOT NULL' => 'autor do registro',
    'PRIMARY KEY (id)' => 'chave primaria',
    'CONSTRAINT ck_presence_cutoff_single CHECK (id = 1)' => 'no maximo uma linha (D3)',
    'CONSTRAINT ck_presence_cutoff_day CHECK (DAYOFMONTH(cutoff_date) = 16)' => 'sempre dia 16 (D2)',
    'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' => 'InnoDB e utf8mb4',
] as $needle => $label) {
    assert_same(true, $at($migration, $needle) > -1, "019: {$label}");
}

// Gatilhos da imutabilidade (D3 no banco; aprovacao do commit 1, 2026-10-14):
// UPDATE e DELETE sempre param, sem condicao; INSERT livre para o registro.
foreach ($triggerSql as $event => $statement) {
    assert_same(1, substr_count($flatMigration, $statement), "019: gatilho BEFORE {$event} incondicional, com a mensagem do rollback");
    assert_same(true, strpos($flatMigration, $statement) > $at($flatMigration, 'EXECUTE create_cutoff_stmt;'), "019: gatilho BEFORE {$event} depois da tabela");
}
assert_same([2, 2], [preg_match_all('/\bCREATE TRIGGER\b/i', $migration), preg_match_all('/\bCREATE TRIGGER IF NOT EXISTS\b/', $migration)], '019: so os dois gatilhos, ambos com IF NOT EXISTS (idempotente)');
assert_same(0, preg_match('/\bDROP TRIGGER\b|\b(BEFORE|AFTER) INSERT\b|\bAFTER (UPDATE|DELETE)\b/i', $migration), '019: sem DROP TRIGGER (sem janela sem protecao), sem gatilho de INSERT nem AFTER');
assert_same(true, strlen('corte imutavel: use o rollback da 019') <= 128, '019: mensagem do gatilho dentro do limite de 128 do SIGNAL');
assert_same(true, strpos($rawFile('migrations/20261013_019_presence_cutoff.sql'), 'Exige MySQL 8.0.29 ou mais novo') !== false, '019: cabecalho avisa a versao minima (IF NOT EXISTS em CREATE TRIGGER)');
// O registro so insere (nao passa pelos gatilhos); o rollback nao depende deles.
assert_same(0, preg_match('/\bTRIGGER\b/i', $register . $rollback), 'registro e rollback 019 nao mexem em gatilho');

// Registro: recusas antes de gravar, na ordem; gravacao e auditoria juntas.
assert_same(true, $at($register, 'DROP PROCEDURE IF EXISTS registrar_corte_019;') > -1 && $at($register, 'DROP PROCEDURE IF EXISTS registrar_corte_019;') < $at($register, 'CREATE PROCEDURE registrar_corte_019('), 'registro 019: procedimento recriado a cada execucao');
assert_same(true, $at($register, 'CALL registrar_corte_019(@corte, @autor);') > $at($register, 'END//'), 'registro 019: chamado com @corte e @autor');
assert_same(true, $at($register, 'DROP PROCEDURE registrar_corte_019;') > $at($register, 'CALL registrar_corte_019('), 'registro 019: procedimento removido depois do registro');
assert_same(true, $at($register, "DECLARE EXIT HANDLER FOR SQLEXCEPTION\n    BEGIN\n        ROLLBACK;\n        RESIGNAL;\n    END;") > -1, 'registro 019: qualquer erro desfaz e repassa a mensagem');
preg_match_all("/SIGNAL SQLSTATE '45000'\\s+SET MESSAGE_TEXT = '([^']+)';/", $register, $signals, PREG_OFFSET_CAPTURE);
$messages = array_map(static fn (array $match): string => $match[0], $signals[1]);
assert_same(7, count($messages), 'registro 019: sete recusas');
foreach ($messages as $message) {
    assert_same(true, strlen($message) <= 128 && strpos($message, 'registro_019 abortado: ') === 0 && substr($message, -18) === 'Nada foi alterado.', "registro 019: mensagem clara e dentro do limite de 128 do SIGNAL ({$message})");
}
$refusals = [
    "TABLE_NAME = 'portal_presence_cutoff') = 0 THEN" => 'sem a migration 019',
    "IF corte_texto IS NULL OR autor IS NULL OR TRIM(autor) = '' THEN" => 'sem @corte ou @autor',
    "IF corte_texto NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN" => 'formato AAAA-MM-DD',
    "IF SUBSTRING(corte_texto, 9, 2) <> '16' THEN" => 'dia diferente de 16 (D2)',
    'IF mes < 1 OR mes > 12 OR ano < 2000 THEN' => 'mes ou ano invalido',
    'IF corte < CURDATE() THEN' => 'data anterior ao dia em que roda (D3)',
    'IF (SELECT COUNT(*) FROM portal_presence_cutoff) > 0 THEN' => 'corte ja registrado (D3, imutavel)',
];
$previous = -1;
foreach ($refusals as $needle => $label) {
    $position = $at($register, $needle);
    assert_same(true, $position > $previous, "registro 019: recusa {$label}, na ordem");
    $previous = $position;
}
$transaction = $at($register, 'START TRANSACTION;');
assert_same(true, $transaction > $previous, 'registro 019: todas as recusas antes da transacao');
$insertCutoff = $at($register, "INSERT INTO portal_presence_cutoff (id, cutoff_date, registered_by)\n    VALUES (1, corte, TRIM(autor));");
$insertAudit = $at($register, "INSERT INTO audit_log (user_ip, username, action, details, severity)\n    VALUES ('migration', 'registro_019', 'PRESENCE_CUTOFF_REGISTERED',");
$commit = $at($register, 'COMMIT;');
assert_same(true, $transaction < $insertCutoff && $insertCutoff < $insertAudit && $insertAudit < $commit, 'registro 019: linha e evento na mesma transacao');
assert_same(true, $at($register, "CONCAT('019: corte do motor de presenca em ', DATE_FORMAT(corte, '%Y-%m-%d')), 'CRITICAL');") > -1, 'registro 019: evento CRITICAL so com a data (sem o autor)');
assert_same(2, preg_match_all('/\bINSERT\b/i', $register), 'registro 019: so as duas gravacoes');
assert_same(0, preg_match('/\b(UPDATE|DELETE|ALTER|DROP TABLE|TRUNCATE|REPLACE)\b/i', $register), 'registro 019: nao altera nem apaga nada');
assert_same(true, $at($register, 'SET corte = MAKEDATE(ano, 1) + INTERVAL (mes - 1) MONTH + INTERVAL 15 DAY;') > $at($register, 'IF mes < 1 OR mes > 12 OR ano < 2000 THEN'), 'registro 019: data montada (dia 16 existe em todo mes), sem conversao de texto');
assert_same(0, preg_match('/\b(STR_TO_DATE|CAST\([^)]*AS DATE)/i', $register), 'registro 019: sem conversao de texto em data (erro de modo estrito)');
$registerHeader = $rawFile('migrations/20261013_019_registrar_corte.sql');
assert_same(true, strpos($registerHeader, 'NAO E MIGRATION DE DEPLOY') !== false, 'registro 019: cabecalho avisa que nao e migration');
assert_same(true, strpos($registerHeader, "SELECT IF(DAYOFMONTH(CURDATE()) >= 16, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16')) AS corte;") !== false, 'registro 019: consulta da data da D2 no cabecalho');

// A consulta do cabecalho da o 1o dia da competencia seguinte (competencyRange: 16 a 15).
$suggested = static function (string $today): string {
    $date = new DateTimeImmutable($today);
    return (int)$date->format('d') >= 16 ? $date->modify('first day of next month')->format('Y-m-16') : $date->format('Y-m-16');
};
foreach (['2026-10-13' => '2026-10-16', '2026-10-15' => '2026-10-16', '2026-10-16' => '2026-11-16', '2026-10-31' => '2026-11-16', '2026-12-20' => '2027-01-16', '2027-01-31' => '2027-02-16'] as $deployDay => $expected) {
    assert_same($expected, $suggested($deployDay), "data da D2 para deploy em {$deployDay}");
}
// Mesma regra pela competencia do proprio relatorio: o corte e o inicio da competencia seguinte a do dia do deploy.
require_once $root . '/config.php';
require_once $root . '/services/OperationalService.php';
foreach (['2026-10-13', '2026-10-15', '2026-10-16', '2026-10-31', '2026-12-20', '2027-01-31'] as $deployDay) {
    $competency = OperationalService::competencyRange($deployDay);
    $next = OperationalService::competencyRange((new DateTimeImmutable($competency['end']))->modify('+1 day')->format('Y-m-d'));
    assert_same($next['start'], $suggested($deployDay), "data da D2 = inicio da competencia seguinte (deploy em {$deployDay})");
}

// Rollback: parada, copia antes de alterar, auditoria, depois a tabela.
$abortAt = $at($rollback, 'CALL rollback_019_confere_copia();');
$firstChange = min(array_filter([$at($rollback, 'CREATE TABLE portal_presence_cutoff_bkp_019'), $at($rollback, 'INSERT INTO audit_log'), $at($rollback, 'DROP TABLE'), $at($rollback, 'PREPARE ')], static fn (int $pos): bool => $pos > -1));
assert_same(true, $abortAt > -1 && $abortAt < $firstChange, 'rollback 019: checagem da copia antes de qualquer alteracao');
assert_same(true, $at($rollback, 'DROP PROCEDURE IF EXISTS rollback_019_confere_copia;') > -1 && $at($rollback, 'DROP PROCEDURE IF EXISTS rollback_019_confere_copia;') < $at($rollback, 'CREATE PROCEDURE rollback_019_confere_copia()'), 'rollback 019: procedimento recriado a cada execucao');
assert_same(1, preg_match("/IF \\(SELECT COUNT\\(\\*\\) FROM information_schema\\.TABLES\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'portal_presence_cutoff_bkp_019'\\) > 0 THEN\\s+SIGNAL SQLSTATE '45000'/", $rollback), 'rollback 019: para se a copia ja existe');
assert_same(1, preg_match("/SET MESSAGE_TEXT = '([^']+)'/", $rollback, $message), 'rollback 019: mensagem da parada');
assert_same(true, strlen($message[1]) <= 128 && strpos($message[1], 'Nada foi alterado') !== false, 'rollback 019: mensagem clara e dentro do limite de 128 do SIGNAL');
preg_match_all('/SET @(\w+)_sql := IF\(\s*@(\w+) (= 0|> 0),/', $rollback, $rollbackIfs, PREG_SET_ORDER);
assert_same(['copy', 'audit', 'drop_cutoff'], array_column($rollbackIfs, 1), 'rollback 019: copia, auditoria e remocao condicionais, nessa ordem');
foreach ($rollbackIfs as $if) {
    assert_same(['has_cutoff_table', '> 0'], [$if[2], $if[3]], "rollback 019: @{$if[1]}_sql so com a tabela presente");
}
assert_same(true, $at($rollback, "'CREATE TABLE portal_presence_cutoff_bkp_019 AS SELECT id, cutoff_date, registered_by FROM portal_presence_cutoff'") > -1, 'rollback 019: copia da linha do corte');
assert_same(true, $at($rollback, "''PRESENCE_CUTOFF_REMOVED''") > -1 && $at($rollback, "''CRITICAL''") > -1, 'rollback 019: remocao do corte auditada (CRITICAL)');
assert_same(0, preg_match('/registered_by[^;]*audit_log|audit_log[^;]*registered_by/is', $rollback), 'rollback 019: o autor nao vai para a auditoria');
assert_same(true, $at($rollback, 'EXECUTE copy_stmt;') < $at($rollback, 'EXECUTE audit_stmt;') && $at($rollback, 'EXECUTE audit_stmt;') < $at($rollback, 'EXECUTE drop_cutoff_stmt;'), 'rollback 019: copia e auditoria antes de remover a tabela');
assert_same(true, $at($rollback, "'DROP TABLE portal_presence_cutoff'") > -1, 'rollback 019: remove a tabela do corte');
assert_same(0, preg_match('/\b(UPDATE|DELETE|TRUNCATE|ALTER)\b/i', $rollback), 'rollback 019: sem UPDATE nem DELETE (nao passa pelos gatilhos) e nao altera outra tabela');
assert_same(1, preg_match_all('/DROP TABLE/', $rollback), 'rollback 019: um unico DROP TABLE, que leva os gatilhos junto');

// Verificacao: so le, so contagens e a data; o autor nao sai.
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|RENAME|CALL)\b/i', str_replace(["EVENT_MANIPULATION = 'UPDATE'", "EVENT_MANIPULATION = 'DELETE'"], '', $check)), 'verificacao 019 so le');
$flatCheck = (string)preg_replace('/\s+/', ' ', $check);
assert_same(true, $at($flatCheck, "(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'portal_presence_cutoff' AND ACTION_TIMING = 'BEFORE' AND ACTION_STATEMENT LIKE '%corte imutavel: use o rollback da 019%' AND ((TRIGGER_NAME = 'trg_presence_cutoff_no_update' AND EVENT_MANIPULATION = 'UPDATE') OR (TRIGGER_NAME = 'trg_presence_cutoff_no_delete' AND EVENT_MANIPULATION = 'DELETE'))) AS gatilhos_019") > -1, 'verificacao 019: gatilhos_019 conta os dois pelo nome, evento, momento, tabela e corpo');
assert_same([2, true], [substr_count($check, 'registered_by'), $at($check, "SUM(registered_by IS NULL OR TRIM(registered_by) = '''')") > -1], 'verificacao 019: autor nunca exibido (so na contagem sem_autor)');
foreach (['tabela_019', 'checks_019', 'gatilhos_019', 'copia_rollback', 'procedimentos_soltos', 'cortes', 'corte', 'corte_dia_16', 'corte_no_futuro', 'sem_autor', 'eventos_registro', 'eventos_remocao', 'inconsistente'] as $column) {
    assert_same(true, $at($check, "AS {$column}") > -1, "verificacao 019: {$column}");
}
assert_same(true, $at($check, "@has_cutoff_table = 1,") > -1 && $at($check, "@has_audit_log = 1,") > -1, 'verificacao 019: corte e auditoria so com as tabelas presentes (ensaio sem audit_log)');
assert_same(true, $at($check, "AND ROUTINE_NAME IN ('registrar_corte_019', 'rollback_019_confere_copia')") > -1, 'verificacao 019: conta procedimento que sobrou de recusa');
assert_same(true, $at($check, 'SUM(cutoff_date > @hoje)') > -1, 'verificacao 019: corte no futuro (D2)');
// Consistencia (aprovacao dos gatilhos, 2026-10-14): TRUNCATE nao passa pelos
// gatilhos; registros menos remocoes tem de ser igual as linhas da tabela.
$cortesExpr = "SET @cortes_expr := IF(@has_cutoff_table = 1, '(SELECT COUNT(*) FROM portal_presence_cutoff)', '0');";
assert_same(true, $at($flatCheck, $cortesExpr) > $at($flatCheck, 'SET @has_cutoff_table :=') && $at($flatCheck, $cortesExpr) < $at($flatCheck, 'SET @audit_check_sql :='), 'verificacao 019: cortes da consistencia pela tabela, 0 sem ela, antes da consulta da auditoria');
assert_same(1, substr_count($flatCheck, "IF(COALESCE(SUM(action = ''PRESENCE_CUTOFF_REGISTERED''), 0) - COALESCE(SUM(action = ''PRESENCE_CUTOFF_REMOVED''), 0) <> ', @cortes_expr, ', 1, 0) AS inconsistente FROM audit_log"), 'verificacao 019: inconsistente = 1 quando registros - remocoes difere de cortes');
assert_same(true, $at($flatCheck, "SET @audit_check_sql := IF( @has_audit_log = 1, CONCAT(") > -1, 'verificacao 019: inconsistente so com o audit_log (na consulta da auditoria)');
$checkHeader = $rawFile('migrations/verificacao/20261013_019_presence_cutoff_check.sql');
assert_same([true, true], [strpos($checkHeader, 'TRUNCATE') !== false, strpos($checkHeader, 'inconsistente = 1 no deploy: PARAR e avisar') !== false], 'verificacao 019: cabecalho explica o TRUNCATE e manda parar com inconsistente = 1');

// Ensaio: comandos nos cabecalhos (variantes a, b e c), com audit_log como extra.
$migrationHeader = $rawFile('migrations/20261013_019_presence_cutoff.sql');
assert_same(true, strpos($migrationHeader, "bash scripts/ensaio-migration.sh f5 \\\n--     migrations/20261013_019_presence_cutoff.sql \\\n--     migrations/rollback/20261013_019_presence_cutoff_down.sql audit_log") !== false, '019: ensaio (a) no cabecalho, com audit_log');
foreach (["SET @corte := '2099-12-16'; SET @autor := 'qa.ensaio';" => '(b) corte valido no futuro', "SET @corte := '2020-01-16'; SET @autor := 'qa.ensaio';" => '(c) data passada', 'PREPARO=migrations/20261013_019_presence_cutoff.sql' => '019 como PREPARO'] as $needle => $label) {
    assert_same(true, strpos($registerHeader, $needle) !== false, "registro 019: ensaio {$label} no cabecalho");
}

// Runners.
foreach (['scripts/test-linux.sh', 'scripts/test-local.ps1'] as $runner) {
    assert_same(true, strpos((string)file_get_contents($root . '/' . $runner), 'qa-presence-cutoff.php') !== false, "{$runner} roda o qa-presence-cutoff");
}

// ----------------------------------------------------------------------------
// 2. PresenceCutoff: divisao do periodo (funcao pura), aviso e leitura do corte
// ----------------------------------------------------------------------------
require_once $root . '/services/PresenceCutoff.php';
$range = static fn (?string $from, ?string $to = null): ?array => $from === null ? null : ['from' => $from, 'to' => $to ?? $from];
foreach ([
    'sem corte' => ['2026-11-01', '2026-11-30', null, $range('2026-11-01', '2026-11-30'), null],
    'periodo inteiro antes do corte' => ['2026-10-16', '2026-11-15', '2026-11-16', $range('2026-10-16', '2026-11-15'), null],
    'fim na vespera do corte' => ['2026-11-10', '2026-11-15', '2026-11-16', $range('2026-11-10', '2026-11-15'), null],
    'fim no dia do corte' => ['2026-11-10', '2026-11-16', '2026-11-16', $range('2026-11-10', '2026-11-15'), $range('2026-11-16')],
    'cruzando o corte' => ['2026-11-01', '2026-11-30', '2026-11-16', $range('2026-11-01', '2026-11-15'), $range('2026-11-16', '2026-11-30')],
    'comeca no dia do corte' => ['2026-11-16', '2026-12-15', '2026-11-16', null, $range('2026-11-16', '2026-12-15')],
    'periodo inteiro depois do corte' => ['2027-01-01', '2027-01-31', '2026-11-16', null, $range('2027-01-01', '2027-01-31')],
    'um dia, na vespera' => ['2026-11-15', '2026-11-15', '2026-11-16', $range('2026-11-15'), null],
    'um dia, no corte' => ['2026-11-16', '2026-11-16', '2026-11-16', null, $range('2026-11-16')],
    'corte em janeiro' => ['2026-12-20', '2027-01-20', '2027-01-16', $range('2026-12-20', '2027-01-15'), $range('2027-01-16', '2027-01-20')],
    'corte no futuro, periodo de hoje' => ['2026-10-01', '2026-10-31', '2099-12-16', $range('2026-10-01', '2026-10-31'), null],
    'ano inteiro cruzando' => ['2026-01-01', '2026-12-31', '2026-03-16', $range('2026-01-01', '2026-03-15'), $range('2026-03-16', '2026-12-31')],
] as $label => [$from, $to, $cutoff, $legacy, $engine]) {
    assert_same(['legacy' => $legacy, 'engine' => $engine], PresenceCutoff::split($from, $to, $cutoff), "split: {$label}");
}
$refused = static function (callable $call): string {
    try {
        $call();
        return 'aceito';
    } catch (InvalidArgumentException $e) {
        return 'recusado';
    }
};
foreach (['fim antes do inicio' => ['2026-11-30', '2026-11-01', null], 'data invalida' => ['2026-02-30', '2026-03-10', null], 'corte invalido' => ['2026-11-01', '2026-11-30', '16/11/2026']] as $label => [$from, $to, $cutoff]) {
    assert_same('recusado', $refused(static fn () => PresenceCutoff::split($from, $to, $cutoff)), "split: {$label} recusado");
}
assert_same('Escala pelo cálculo antigo até 15/11/2026 e pelas regras novas a partir de 16/11/2026', PresenceCutoff::splitNotice('2026-11-16'), 'aviso do CSV com a vespera e o corte');
assert_same('Escala pelo cálculo antigo até 15/01/2027 e pelas regras novas a partir de 16/01/2027', PresenceCutoff::splitNotice('2027-01-16'), 'aviso do CSV em janeiro');

/**
 * Banco simulado so de leitura (secoes 2 e 3): tabela do corte, cadastros,
 * regras, excecoes e ausencias, nas consultas exatas do servico; hora extra,
 * ajuste e plantao vazios. Consulta nao prevista falha.
 */
final class QaCutoffPdo extends PDO {
    public bool $cutoffTable = false;
    public ?string $cutoff = null;
    public array $employees = [];
    public array $rules = [];
    public array $exceptions = [];
    public array $absences = [];
    public array $log = [];

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaCutoffStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }
}

final class QaCutoffStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaCutoffPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = $this->sql;
        $db = $this->pdo;
        $db->log[] = $sql;
        $this->rows = [];
        foreach (['FROM portal_overtime_entries', 'FROM portal_time_adjustments', 'FROM portal_oncall_shifts', 'FROM portal_calendar_events', 'FROM pausas p', 'FROM portal_critical_incidents'] as $empty) {
            if (strpos($sql, $empty) !== false) {
                return true;
            }
        }
        if ($sql === 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name' && $params[':table_name'] === 'portal_presence_cutoff') {
            $this->rows[] = ['total' => $db->cutoffTable ? 1 : 0];
            return true;
        }
        if ($sql === 'SELECT cutoff_date FROM portal_presence_cutoff WHERE id = 1') {
            if (!$db->cutoffTable) {
                throw new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'portal_presence_cutoff' doesn't exist");
            }
            if ($db->cutoff !== null) {
                $this->rows[] = ['cutoff_date' => $db->cutoff];
            }
            return true;
        }
        $visible = static fn (array $employee, bool $withArchived): bool => (int)$employee['ativo'] === 1
            || ($withArchived && $employee['arquivado_em'] !== null);
        $matchesEmployee = static fn (int $id): bool => !isset($params[':employee_id']) || $id === $params[':employee_id'];
        $rulesPrefix = 'SELECT r.id, r.employee_id, f.nome AS employee_name, f.ad_login, LOWER(f.equipe) AS team, r.rule_type, r.rule_config, r.effective_from, r.effective_until, r.created_by, r.updated_by, r.created_at, r.updated_at FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND ';
        if (strpos($sql, $rulesPrefix) === 0) {
            $rest = substr($sql, strlen($rulesPrefix));
            $withArchived = strpos($rest, '(f.ativo = 1 OR f.arquivado_em IS NOT NULL) ') === 0;
            if (!$withArchived && strpos($rest, 'f.ativo = 1 ') !== 0) {
                throw new PDOException('SQLSTATE[HY000]: banco simulado: filtro de cadastro nao previsto');
            }
            $outsideMap = strpos($rest, ' AND f.fora_mapa_escala = 0 WHERE LOWER(f.equipe) IN ("n1", "n2")') !== false;
            foreach ($db->rules as $rule) {
                $employee = $db->employees[$rule['employee_id']];
                $team = strtolower($employee['equipe']);
                if (!$visible($employee, $withArchived) || !in_array($team, ['n1', 'n2'], true)
                    || ($outsideMap && $employee['fora_mapa_escala'] === 1)
                    || (isset($params[':team']) && $team !== $params[':team'])
                    || !$matchesEmployee($rule['employee_id'])
                    || (isset($params[':period_from']) && $rule['effective_until'] !== null && $rule['effective_until'] < $params[':period_from'])) {
                    continue;
                }
                $this->rows[] = [
                    'id' => $rule['id'], 'employee_id' => $rule['employee_id'], 'employee_name' => $employee['nome'],
                    'ad_login' => 'qa.' . $rule['employee_id'], 'team' => $team, 'rule_type' => $rule['rule_type'],
                    'rule_config' => $rule['rule_config'], 'effective_from' => $rule['effective_from'], 'effective_until' => $rule['effective_until'],
                    'created_by' => 'qa', 'updated_by' => 'qa', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
                ];
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['employee_name'], $b['effective_from'], $b['id']] <=> [$b['employee_name'], $a['effective_from'], $a['id']]);
            return true;
        }
        if (strpos($sql, 'SELECT id, employee_id, employee_name, team, exception_date, exception_type, note, created_by, created_at, updated_at FROM portal_schedule_exceptions WHERE exception_date BETWEEN :date_from AND :date_to') === 0) {
            foreach ($db->exceptions as $row) {
                if ($row['exception_date'] >= $params[':date_from'] && $row['exception_date'] <= $params[':date_to']
                    && (!isset($params[':team']) || $row['team'] === $params[':team']) && $matchesEmployee($row['employee_id'])) {
                    $this->rows[] = $row + ['employee_name' => $db->employees[$row['employee_id']]['nome'], 'note' => 'observacao ficticia', 'created_by' => 'qa', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['exception_date'], $a['employee_name']] <=> [$b['exception_date'], $b['employee_name']]);
            return true;
        }
        // Bloco de ausencias do Calendario (categoria, nunca o motivo).
        if (strpos($sql, 'SELECT id, employee_name AS title, category, CONCAT(starts_on, " 00:00:00") AS starts_at, CONCAT(ends_on, " 23:59:59") AS ends_at, NULL AS team, employee_id, status FROM portal_absences WHERE starts_on <= :date_to AND ends_on >= :date_from AND status = "approved"') === 0) {
            foreach ($db->absences as $row) {
                if ($row['status'] === 'approved' && $row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from'] && $matchesEmployee($row['employee_id'])) {
                    $this->rows[] = [
                        'id' => $row['id'], 'title' => $db->employees[$row['employee_id']]['nome'], 'category' => $row['category'],
                        'starts_at' => $row['starts_on'] . ' 00:00:00', 'ends_at' => $row['ends_on'] . ' 23:59:59',
                        'team' => null, 'employee_id' => $row['employee_id'], 'status' => 'approved',
                    ];
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));
            return true;
        }
        // Ausencias do motor; no Calendario, com a categoria (F5, commit 3).
        $absencesPrefix = 'SELECT a.employee_id, a.starts_on, a.ends_on, a.period_type, a.start_time, a.end_time FROM portal_absences a JOIN funcionarios f ON f.id = a.employee_id AND ';
        $absenceSql = str_replace('a.end_time, a.category FROM', 'a.end_time FROM', $sql, $withCategory);
        if (strpos($absenceSql, $absencesPrefix) === 0) {
            $rest = substr($absenceSql, strlen($absencesPrefix));
            $withArchived = strpos($rest, '(f.ativo = 1 OR f.arquivado_em IS NOT NULL) WHERE ') === 0;
            if (!$withArchived && strpos($rest, 'f.ativo = 1 WHERE ') !== 0) {
                throw new PDOException('SQLSTATE[HY000]: banco simulado: filtro de cadastro nao previsto');
            }
            foreach ($db->absences as $row) {
                $employee = $db->employees[$row['employee_id']];
                if ($visible($employee, $withArchived) && $row['status'] === 'approved'
                    && $row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']
                    && (!isset($params[':team']) || strtolower($employee['equipe']) === $params[':team']) && $matchesEmployee($row['employee_id'])) {
                    $this->rows[] = array_intersect_key($row, array_flip(array_merge(['employee_id', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time'], $withCategory === 1 ? ['category'] : [])));
                }
            }
            return true;
        }
        $screen = 'SELECT id, nome AS employee_name, equipe FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0';
        $report = 'SELECT id, nome AS employee_name, equipe, arquivado_em FROM funcionarios WHERE (ativo = 1 OR arquivado_em IS NOT NULL) AND fora_mapa_escala = 0';
        if (strpos($sql, $screen) === 0 || strpos($sql, $report) === 0) {
            $withArchived = strpos($sql, $report) === 0;
            foreach ($db->employees as $id => $row) {
                if ($visible($row, $withArchived) && $row['fora_mapa_escala'] === 0 && $matchesEmployee($id)) {
                    $this->rows[] = ['id' => $id, 'employee_name' => $row['nome'], 'equipe' => $row['equipe']] + ($withArchived ? ['arquivado_em' => $row['arquivado_em']] : []);
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['employee_name'], $a['id']] <=> [$b['employee_name'], $b['id']]);
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
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
}

// Leitura: sem a tabela, nem consulta a tabela; vazia, null; data invalida para.
$cutoffPdo = static function (bool $table, ?string $cutoff): QaCutoffPdo {
    $pdo = new QaCutoffPdo();
    $pdo->cutoffTable = $table;
    $pdo->cutoff = $cutoff;
    return $pdo;
};
$pdo = $cutoffPdo(false, null);
assert_same([null, 1], [PresenceCutoff::read($pdo), count($pdo->log)], 'leitura: sem a 019, sem corte e sem consultar a tabela');
assert_same(null, PresenceCutoff::read($cutoffPdo(true, null)), 'leitura: tabela vazia, sem corte');
assert_same('2026-11-16', PresenceCutoff::read($cutoffPdo(true, '2026-11-16')), 'leitura: corte registrado');
foreach (['2026-11-15', '2026-02-30', '2026-13-16', 'xxxx-xx-16', '16/11/2026', ''] as $invalid) {
    $outcome = (static function () use ($cutoffPdo, $invalid): string {
        try {
            PresenceCutoff::read($cutoffPdo(true, $invalid));
            return 'aceito';
        } catch (UnexpectedValueException $e) {
            return 'recusado';
        }
    })();
    assert_same('recusado', $outcome, "leitura: corte invalido no banco ('{$invalid}') para a requisicao");
}

// ----------------------------------------------------------------------------
// 3. Relatorio e CSV divididos no corte (D4 a D7)
// ----------------------------------------------------------------------------
// Massa ficticia; corte em 16/11/2026 (segunda-feira). Periodo base: 12/11
// (quinta) a 22/11 (domingo): antigo de 12 a 15, motor de 16 a 22.
$reportDb = static function (bool $table = true, ?string $cutoff = '2026-11-16') use ($cutoffPdo): QaCutoffPdo {
    $pdo = $cutoffPdo($table, $cutoff);
    $employee = static fn (string $nome, string $equipe, int $ativo = 1, ?string $arquivadoEm = null, int $foraMapa = 0): array => [
        'nome' => $nome, 'equipe' => $equipe, 'ativo' => $ativo, 'arquivado_em' => $arquivadoEm, 'fora_mapa_escala' => $foraMapa,
    ];
    $pdo->employees = [
        1 => $employee('Tecnico A', 'n1'),
        2 => $employee('Tecnico B', 'N2'),
        3 => $employee('Tecnico C', 'n1'),
        4 => $employee('Tecnico D', 'n1'),
        5 => $employee('Tecnico E', 'n2', 0, '2026-11-19 10:00:00'),
        6 => $employee('Tecnico F', 'n1', 0),
        7 => $employee('Tecnico G', 'n1', 1, null, 1),
        8 => $employee('Lider H', 'Liderança'),
        9 => $employee('Tecnico I', 'n1'),
    ];
    $rule = static fn (int $id, int $employeeId, string $type, string $from, ?string $until): array => [
        'id' => $id, 'employee_id' => $employeeId, 'rule_type' => $type, 'rule_config' => null, 'effective_from' => $from, 'effective_until' => $until,
    ];
    $pdo->rules = [
        $rule(11, 1, 'even_days', '2026-09-01', null),
        // B sem regra (P4).
        $rule(31, 3, 'undefined', '2026-09-01', null),
        $rule(41, 4, 'always_onsite', '2026-09-01', null),
        // E arquivado em 19/11: regra fechada na vespera (F4).
        $rule(51, 5, 'always_remote', '2026-09-01', '2026-11-18'),
        // F inativo antigo (sem data) e G fora do Mapa.
        $rule(61, 6, 'always_onsite', '2026-09-01', null),
        $rule(71, 7, 'always_onsite', '2026-09-01', null),
        // I troca de regra depois do corte.
        $rule(91, 9, 'odd_days', '2026-09-01', '2026-11-17'),
        $rule(92, 9, 'always_remote', '2026-11-18', null),
    ];
    $pdo->exceptions = [
        ['id' => 201, 'employee_id' => 1, 'team' => 'n1', 'exception_date' => '2026-11-21', 'exception_type' => 'oncall'],
        ['id' => 202, 'employee_id' => 2, 'team' => 'n2', 'exception_date' => '2026-11-18', 'exception_type' => 'day_off'],
        // I: excecao de trabalho no dia de uma parcial (Calendario: categoria junto da excecao).
        ['id' => 203, 'employee_id' => 9, 'team' => 'n1', 'exception_date' => '2026-11-20', 'exception_type' => 'remote'],
    ];
    $absence = static fn (int $id, int $employeeId, string $from, string $to, string $status = 'approved', string $period = 'full_day', ?string $start = null, string $category = 'ferias'): array => [
        'id' => $id, 'employee_id' => $employeeId, 'starts_on' => $from, 'ends_on' => $to, 'status' => $status,
        'category' => $category, 'period_type' => $period, 'start_time' => $start, 'end_time' => null,
        'reason' => 'observacao ficticia',
    ];
    $pdo->absences = [
        // D: ausencia que cruza o corte e parcial depois dele.
        $absence(301, 4, '2026-11-13', '2026-11-17'),
        $absence(302, 4, '2026-11-19', '2026-11-19', 'approved', 'from_time', '14:00:00', 'compromisso'),
        // E (arquivado): ausencia antes do arquivamento.
        $absence(303, 5, '2026-11-17', '2026-11-17'),
        // A: pendente nao conta.
        $absence(304, 1, '2026-11-20', '2026-11-20', 'pending'),
        // G (fora do Mapa) e F (inativo antigo): fora do motor, continuam como evento no Calendario.
        $absence(305, 7, '2026-11-17', '2026-11-18', 'approved', 'full_day', null, 'folga'),
        $absence(306, 6, '2026-11-18', '2026-11-18', 'approved', 'full_day', null, 'outro'),
        $absence(307, 9, '2026-11-20', '2026-11-20', 'approved', 'from_time', '15:00:00', 'outro'),
    ];
    return $pdo;
};
$code = static fn (array $row): string => substr($row['date'], 8, 2) . ['onsite' => 'P', 'remote' => 'R', 'absence' => 'A', 'no_schedule' => 'S', 'day_off' => 'F'][$row['presence_type']];
$byName = static function (array $rows) use ($code): array {
    $days = [];
    foreach ($rows as $row) {
        $days[$row['employee_name']] = trim(($days[$row['employee_name']] ?? '') . ' ' . $code($row));
    }
    ksort($days);
    return $days;
};
$service = new OperationalService($reportDb(), '2026-11-10');
$report = $service->report(['from' => '2026-11-12', 'to' => '2026-11-22']);
$legacyOnly = (new OperationalService($reportDb(false), '2026-11-10'))->report(['from' => '2026-11-12', 'to' => '2026-11-15']);
$legacyRows = array_values(array_filter($report['schedule'], static fn (array $row): bool => $row['date'] < '2026-11-16'));
$engineRows = array_values(array_filter($report['schedule'], static fn (array $row): bool => $row['date'] >= '2026-11-16'));
assert_same($legacyOnly['schedule'], $legacyRows, 'relatorio cruzando o corte: linhas antes do corte identicas as do calculo antigo');
assert_same(array_merge($legacyRows, $engineRows), $report['schedule'], 'relatorio cruzando o corte: parte antiga primeiro');
assert_same([
    'Tecnico A' => '12P 13R 14P 15R',
    'Tecnico C' => '12R 13R 14R 15R',
    'Tecnico D' => '12P',
    'Tecnico G' => '12P 13P 14P 15P',
    'Tecnico I' => '12R 13P 14R 15P',
], $byName($legacyRows), 'parte antiga: fim de semana conta, undefined vira remoto, fora do Mapa entra, sem Lideranca, sem inativo nem arquivado');
assert_same([
    'Lider H' => '16P 17P 18P 19P 20P',
    'Tecnico A' => '16P 17R 18P 19R 20P 21P',
    'Tecnico B' => '16S 17S 18A 19S 20S',
    'Tecnico C' => '16S 17S 18S 19S 20S',
    'Tecnico D' => '16A 17A 18P 19P 20P',
    'Tecnico E' => '16R 17A 18R',
    'Tecnico I' => '16R 17P 18R 19R 20R',
], $byName($engineRows), 'motor: P3 (sabado so com plantao), P4, ausencia (cruzando o corte, excecao de folga, arquivado), parcial nao tira o dia, Lideranca, arquivado ate a vespera, sem inativo antigo nem fora do Mapa, pendente ignorada');
$row = static function (array $rows, string $name, string $date): array {
    foreach ($rows as $candidate) {
        if ($candidate['employee_name'] === $name && $candidate['date'] === $date) {
            return $candidate;
        }
    }
    return [];
};
assert_same(['Ausência', 'absence', 'ausente'], [$row($engineRows, 'Tecnico D', '2026-11-16')['label'], $row($engineRows, 'Tecnico D', '2026-11-16')['source'], $row($engineRows, 'Tecnico D', '2026-11-16')['situacao']], 'motor: ausencia aprovada com rotulo acentuado (D11), origem ausencia');
assert_same(['exception', 'Ausência'], [$row($engineRows, 'Tecnico B', '2026-11-18')['source'], $row($engineRows, 'Tecnico B', '2026-11-18')['label']], 'motor: excecao de folga como ausencia');
assert_same(['onsite', 'exception'], [$row($engineRows, 'Tecnico A', '2026-11-21')['presence_type'], $row($engineRows, 'Tecnico A', '2026-11-21')['source']], 'motor: sabado de plantao (excecao de trabalho, P3)');
assert_same(true, strpos($row($engineRows, 'Tecnico D', '2026-11-19')['detalhe'], 'Ausente a partir de 14:00') !== false, 'motor: parcial como marcador, sem tirar o dia');
assert_same(['Sem escala', 'Sem escala definida', 'none'], [$row($engineRows, 'Tecnico B', '2026-11-16')['label'], $row($engineRows, 'Tecnico B', '2026-11-16')['rule_label'], $row($engineRows, 'Tecnico B', '2026-11-16')['source']], 'motor: sem regra e sem escala (P4)');
assert_same(['no_schedule', 'Sem escala definida'], [$row($engineRows, 'Tecnico C', '2026-11-16')['presence_type'], $row($engineRows, 'Tecnico C', '2026-11-16')['rule_label']], 'motor: undefined e sem escala, nao remoto (P4)');
assert_same([OperationalService::LEADERSHIP_RULE_LABEL, 'implicit', 'lideranca'], [$row($engineRows, 'Lider H', '2026-11-16')['rule_label'], $row($engineRows, 'Lider H', '2026-11-16')['source'], $row($engineRows, 'Lider H', '2026-11-16')['team']], 'motor: Lideranca com a regra fixa');
assert_same(['Dias ímpares', 'Dias impares'], [$row($engineRows, 'Tecnico I', '2026-11-16')['rule_label'], $row($legacyRows, 'Tecnico I', '2026-11-12')['rule_label']], 'D11: acento so no caminho do motor; a parte antiga com o rotulo de hoje');
assert_same(false, strpos(json_encode($report, JSON_UNESCAPED_UNICODE), 'ferias') !== false || strpos(json_encode($report, JSON_UNESCAPED_UNICODE), 'observacao') !== false, 'relatorio sem categoria nem observacao de ausencia');
assert_same(['date' => '2026-11-16', 'split' => true], $report['cutoff'], 'relatorio cruzando o corte: cutoff com split');
assert_same(
    ['onsite_days' => 9 + 13, 'remote_days' => 8 + 8, 'absence_days' => 4, 'no_schedule_days' => 9],
    array_intersect_key($report['summary'], array_flip(['onsite_days', 'remote_days', 'absence_days', 'no_schedule_days'])),
    'resumo: totais das duas partes; ausencia e sem escala so do motor'
);
$sorted = static function (array $groups): array {
    ksort($groups);
    return array_map(static function (array $values): array {
        ksort($values);
        return $values;
    }, $groups);
};
assert_same([
    'Lider H' => ['onsite_days' => 5],
    'Tecnico A' => ['onsite_days' => 6, 'remote_days' => 4],
    'Tecnico B' => ['absence_days' => 1, 'no_schedule_days' => 4],
    'Tecnico C' => ['no_schedule_days' => 5, 'remote_days' => 4],
    'Tecnico D' => ['absence_days' => 2, 'onsite_days' => 4],
    'Tecnico E' => ['absence_days' => 1, 'remote_days' => 2],
    'Tecnico G' => ['onsite_days' => 4],
    'Tecnico I' => ['onsite_days' => 3, 'remote_days' => 6],
], $sorted($report['by_employee']), 'por colaborador: duas partes somadas');
assert_same([
    'LIDERANCA' => ['onsite_days' => 5],
    'N1' => ['absence_days' => 2, 'no_schedule_days' => 5, 'onsite_days' => 17, 'remote_days' => 14],
    'N2' => ['absence_days' => 2, 'no_schedule_days' => 4, 'remote_days' => 2],
], $sorted($report['by_team']), 'por equipe: duas partes somadas');
// CSV: aviso depois do cabecalho; escala do motor sem categoria.
ob_start();
$service->streamReportCsv($report);
$csv = (string)ob_get_clean();
$lines = array_values(array_filter(explode("\n", substr($csv, 3)), static fn (string $line): bool => $line !== ''));
$cells = array_map(static fn (string $line): array => str_getcsv($line, ';'), $lines);
assert_same("\xEF\xBB\xBF", substr($csv, 0, 3), 'CSV: BOM como antes');
assert_same(['tipo', 'colaborador', 'equipe', 'data', 'detalhe', 'status'], $cells[0], 'CSV: cabecalho de antes');
assert_same(['aviso', '', '', '2026-11-16', 'Escala pelo cálculo antigo até 15/11/2026 e pelas regras novas a partir de 16/11/2026', ''], $cells[1], 'CSV: aviso logo depois do cabecalho');
assert_same(true, in_array(['escala', 'Tecnico D', 'N1', '2026-11-16', 'Ausência', 'absence'], $cells, true), 'CSV: ausencia com o rotulo, sem a categoria');
assert_same(count($report['schedule']), count($cells) - 2, 'CSV: uma linha por dia de escala, alem do cabecalho e do aviso');
assert_same(false, strpos($csv, 'ferias') !== false, 'CSV sem categoria');
// Periodo inteiro a partir do corte: so o motor, split false, sem aviso.
$after = $service->report(['from' => '2026-11-16', 'to' => '2026-11-22']);
assert_same([['date' => '2026-11-16', 'split' => false], $engineRows], [$after['cutoff'], $after['schedule']], 'periodo a partir do corte: so o motor, split false');
ob_start();
$service->streamReportCsv($after);
assert_same(0, substr_count((string)ob_get_clean(), "\naviso;"), 'periodo a partir do corte: CSV sem aviso');
$later = $service->report(['from' => '2026-11-18', 'to' => '2026-11-22']);
assert_same(['date' => '2026-11-16', 'split' => false], $later['cutoff'], 'periodo que comeca depois do corte: cutoff com a data registrada, nao o inicio do periodo');
// Periodo inteiro antes do corte (e corte no futuro): identico ao sem corte, sem campo novo.
foreach ([
    'periodo antes do corte' => [$reportDb(), ['from' => '2026-11-02', 'to' => '2026-11-15']],
    'corte no futuro' => [$reportDb(true, '2026-12-16'), ['from' => '2026-11-12', 'to' => '2026-11-22']],
    'tabela vazia' => [$reportDb(true, null), ['from' => '2026-11-12', 'to' => '2026-11-22']],
] as $label => [$pdo, $filters]) {
    $expected = (new OperationalService($reportDb(false), '2026-11-10'))->report($filters);
    $actual = (new OperationalService($pdo, '2026-11-10'))->report($filters);
    assert_same($expected, $actual, "{$label}: relatorio identico ao sem corte");
    assert_same(false, isset($actual['cutoff']) || isset($actual['summary']['absence_days']) || isset($actual['summary']['no_schedule_days']), "{$label}: nenhum campo novo");
    ob_start();
    (new OperationalService($reportDb(false), '2026-11-10'))->streamReportCsv($expected);
    $expectedCsv = (string)ob_get_clean();
    ob_start();
    (new OperationalService($pdo, '2026-11-10'))->streamReportCsv($actual);
    assert_same($expectedCsv, (string)ob_get_clean(), "{$label}: CSV identico ao sem corte");
}
// Filtros: equipe tira a Lideranca e a outra equipe; colaborador arquivado so ate a vespera.
assert_same([
    'Tecnico A' => '16P 17R 18P 19R 20P 21P',
    'Tecnico C' => '16S 17S 18S 19S 20S',
    'Tecnico D' => '16A 17A 18P 19P 20P',
    'Tecnico I' => '16R 17P 18R 19R 20R',
], $byName($service->report(['from' => '2026-11-16', 'to' => '2026-11-22', 'team' => 'n1'])['schedule']), 'filtro de equipe no motor: so N1, sem a Lideranca');
$archived = $service->report(['from' => '2026-11-12', 'to' => '2026-11-22', 'employee_id' => '5']);
assert_same(['Tecnico E' => '16R 17A 18R'], $byName($archived['schedule']), 'filtro do arquivado: nada antes do corte (calculo antigo sem inativo), motor ate a vespera do arquivamento');
assert_same([], $service->report(['from' => '2026-11-12', 'to' => '2026-11-22', 'employee_id' => '6'])['schedule'], 'inativo antigo (sem data): fora das duas partes');
// Corte lido uma vez por requisicao (servico), mesmo com varios relatorios.
$pdo = $reportDb();
$once = new OperationalService($pdo, '2026-11-10');
$once->report(['from' => '2026-11-12', 'to' => '2026-11-22']);
$once->report(['from' => '2026-11-16', 'to' => '2026-11-22']);
assert_same(1, count(array_filter($pdo->log, static fn (string $sql): bool => $sql === 'SELECT cutoff_date FROM portal_presence_cutoff WHERE id = 1')), 'corte lido uma vez por requisicao');

// ----------------------------------------------------------------------------
// 4. Calendario dividido no corte (commit 3; D7 a D9, P9)
// ----------------------------------------------------------------------------
$actors = [
    'gestor' => ['role' => 'gestor', 'employee_id' => null],
    'proprio' => ['role' => 'tecnico', 'employee_id' => 4],
    'outro' => ['role' => 'tecnico', 'employee_id' => 1],
    'leitura' => ['role' => 'somente_leitura', 'employee_id' => null],
];
$calendar = static function (array $actor, array $filters = [], ?QaCutoffPdo $pdo = null, string $today = '2026-11-10') use ($reportDb): array {
    return (new OperationalService($pdo ?? $reportDb(), $today))->listCalendar($filters + ['from' => '2026-11-12', 'to' => '2026-11-22'], $actor)['items'];
};
$engineEvents = static fn (array $items): array => array_values(array_filter($items, static fn (array $item): bool => isset($item['situacao'])));
$event = static function (array $items, string $id): array {
    foreach ($items as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }
    return [];
};
$names = static fn (QaCutoffPdo $pdo): array => array_map(static fn (array $employee): string => $employee['nome'], $pdo->employees);
$eventCodes = static function (array $items) use ($code, $names, $reportDb): array {
    $byEmployee = $names($reportDb());
    $days = [];
    foreach ($items as $item) {
        $name = $byEmployee[$item['employee_id']];
        $days[$name] = trim(($days[$name] ?? '') . ' ' . $code(['date' => substr($item['starts_at'], 0, 10), 'presence_type' => $item['status']]));
    }
    ksort($days);
    return $days;
};
$items = $calendar($actors['gestor']);
$legacyItems = $calendar($actors['gestor'], [], $reportDb(false));
$before = static fn (array $list): array => array_values(array_filter($list, static fn (array $item): bool => $item['event_type'] === 'schedule' && $item['starts_at'] < '2026-11-16 00:00:00'));
assert_same($before($legacyItems), $before($items), 'Calendario: escala antes do corte igual a do calculo antigo, evento a evento');
assert_same(0, count(array_filter($items, static fn (array $item): bool => $item['event_type'] === 'schedule' && $item['starts_at'] >= '2026-11-16' && !isset($item['situacao']))), 'Calendario: do corte em diante, nenhuma escala do calculo antigo');
assert_same($byName($engineRows), $eventCodes($engineEvents($items)), 'Calendario: um evento por tecnico e dia util do motor, igual ao relatorio (P3, P4, ausencia, arquivado ate a vespera, Lideranca; sem fora do Mapa nem inativo antigo)');
// Ausencia como evento: ate a vespera do corte para quem esta no motor; inteira para quem nao esta.
$absenceEvents = [];
foreach ($items as $item) {
    if ($item['event_type'] === 'absence') {
        $absenceEvents[$item['id']] = [$item['starts_at'], $item['ends_at'], $item['description']];
    }
}
assert_same([
    'absence-301' => ['2026-11-13 00:00:00', '2026-11-15 23:59:59', 'Férias'],
    'absence-305' => ['2026-11-17 00:00:00', '2026-11-18 23:59:59', 'Folga'],
    'absence-306' => ['2026-11-18 00:00:00', '2026-11-18 23:59:59', 'Outro'],
], $absenceEvents, 'Calendario: ausencia que cruza o corte vai ate a vespera; a de depois do corte vira situacao; fora do Mapa e inativo antigo continuam como evento');
foreach ([
    'schedule-4-2026-11-16' => ['Ausência: Tecnico D', 'absence', 'ausente', 'ausencia', 'Ausente (dia todo)'],
    'schedule-2-2026-11-18' => ['Ausência: Tecnico B', 'absence', 'ausente', 'ausencia', 'Ausente (dia todo)'],
    'schedule-1-2026-11-21' => ['Presencial: Tecnico A', 'onsite', 'presencial', 'regra_escala', 'Presencial (exceção: plantão)'],
    'schedule-3-2026-11-16' => ['Sem escala: Tecnico C', 'no_schedule', 'sem_escala', 'regra_escala', 'Sem escala definida'],
    'schedule-8-2026-11-16' => ['Presencial: Lider H', 'onsite', 'presencial', 'regra_escala', 'Presencial (regra fixa)'],
] as $id => $expected) {
    $item = $event($items, $id);
    assert_same($expected, [$item['title'] ?? null, $item['status'] ?? null, $item['situacao'] ?? null, $item['origem'] ?? null, $item['detalhe'] ?? null], "Calendario: evento {$id} com titulo, status, situacao, origem e detalhe do motor");
}
assert_same(true, strpos($event($items, 'schedule-4-2026-11-19')['detalhe'], 'Ausente a partir de 14:00') !== false, 'Calendario: parcial como marcador no detalhe');
// P9: categoria so para a gestao e o proprio; observacao nunca; excecao sem categoria.
$descriptions = [];
foreach ($actors as $who => $actor) {
    $list = $calendar($actor);
    foreach (['schedule-4-2026-11-16', 'schedule-4-2026-11-19', 'schedule-5-2026-11-17', 'schedule-9-2026-11-20', 'schedule-2-2026-11-18', 'schedule-1-2026-11-21', 'schedule-8-2026-11-16', 'schedule-9-2026-11-16', 'schedule-3-2026-11-16', 'absence-301'] as $id) {
        $descriptions[$who][$id] = $event($list, $id)['description'] ?? null;
    }
    $json = (string)json_encode($list, JSON_UNESCAPED_UNICODE);
    assert_same([false, false, false], [strpos($json, 'observacao ficticia') !== false, strpos($json, '"ausencias"') !== false, strpos($json, '"category"') !== false], "Calendario ({$who}): sem observacao, sem os itens internos do motor e sem a categoria crua");
    if (in_array($who, ['outro', 'leitura'], true)) {
        foreach (['Férias', 'Compromisso', 'Folga', 'Outro'] as $label) {
            assert_same(false, strpos($json, $label) !== false, "Calendario ({$who}): sem a categoria {$label} de outro colaborador");
        }
    }
}
$common = ['schedule-2-2026-11-18' => 'Exceção de escala', 'schedule-1-2026-11-21' => 'Exceção de escala', 'schedule-8-2026-11-16' => OperationalService::LEADERSHIP_RULE_LABEL, 'schedule-9-2026-11-16' => 'Dias ímpares', 'schedule-3-2026-11-16' => 'Sem escala definida'];
assert_same([
    'gestor' => ['schedule-4-2026-11-16' => 'Férias', 'schedule-4-2026-11-19' => 'Sempre presencial · Compromisso', 'schedule-5-2026-11-17' => 'Férias', 'schedule-9-2026-11-20' => 'Exceção de escala · Outro'] + $common + ['absence-301' => 'Férias'],
    'proprio' => ['schedule-4-2026-11-16' => 'Férias', 'schedule-4-2026-11-19' => 'Sempre presencial · Compromisso', 'schedule-5-2026-11-17' => '', 'schedule-9-2026-11-20' => 'Exceção de escala'] + $common + ['absence-301' => 'Férias'],
    'outro' => ['schedule-4-2026-11-16' => '', 'schedule-4-2026-11-19' => 'Sempre presencial', 'schedule-5-2026-11-17' => '', 'schedule-9-2026-11-20' => 'Exceção de escala'] + $common + ['absence-301' => ''],
    'leitura' => ['schedule-4-2026-11-16' => '', 'schedule-4-2026-11-19' => 'Sempre presencial', 'schedule-5-2026-11-17' => '', 'schedule-9-2026-11-20' => 'Exceção de escala'] + $common + ['absence-301' => ''],
], $descriptions, 'Calendario: descricao por perfil (categoria so para a gestao e o proprio, P9)');
// Filtros: type pela situacao; status pelo mesmo mapa do corte em diante.
$ids = static fn (array $list): array => array_column($list, 'id');
$typeAbsence = $calendar($actors['gestor'], ['type' => 'absence']);
assert_same(['absence-301', 'schedule-4-2026-11-16', 'absence-305', 'schedule-4-2026-11-17', 'schedule-5-2026-11-17', 'absence-306', 'schedule-2-2026-11-18'], $ids($typeAbsence), 'type=absence: eventos de ausencia e as situacoes de ausencia do motor');
foreach (['onsite', 'remote'] as $filterType) {
    $list = $calendar($actors['gestor'], ['type' => $filterType]);
    assert_same([true, true], [count($list) > 0, count($list) === count(array_filter($list, static fn (array $item): bool => $item['status'] === $filterType))], "type={$filterType}: so eventos com essa situacao, nas duas partes");
}
$typeSchedule = $calendar($actors['gestor'], ['type' => 'schedule']);
assert_same([0, true], [count(array_filter($typeSchedule, static fn (array $item): bool => $item['event_type'] !== 'schedule')), in_array('no_schedule', array_column($typeSchedule, 'status'), true)], 'type=schedule: so escala, com sem escala e ausencia do motor');
$statusAbsence = $engineEvents($calendar($actors['gestor'], ['status' => 'absence']));
assert_same(['schedule-4-2026-11-16', 'schedule-4-2026-11-17', 'schedule-5-2026-11-17', 'schedule-2-2026-11-18'], $ids($statusAbsence), 'status=absence: do corte em diante, so as situacoes de ausencia');
$statusApproved = $calendar($actors['gestor'], ['status' => 'approved']);
assert_same([[], ['absence-301', 'absence-305', 'absence-306']], [$engineEvents($statusApproved), $ids(array_values(array_filter($statusApproved, static fn (array $item): bool => $item['event_type'] === 'absence')))], 'status=approved: eventos de ausencia como antes; nenhum evento do motor');
assert_same([
    'Tecnico A' => '16P 17R 18P 19R 20P 21P',
    'Tecnico C' => '16S 17S 18S 19S 20S',
    'Tecnico D' => '16A 17A 18P 19P 20P',
    'Tecnico I' => '16R 17P 18R 19R 20R',
], $eventCodes($engineEvents($calendar($actors['gestor'], ['team' => 'n1']))), 'team=n1: motor so com N1, sem a Lideranca');
assert_same(['absence-301', 'absence-305', 'absence-306'], $ids(array_values(array_filter($calendar($actors['gestor'], ['team' => 'n1']), static fn (array $item): bool => $item['event_type'] === 'absence'))), 'team=n1: o bloco de ausencias continua sem filtro de equipe (como antes)');
$archived = $calendar($actors['gestor'], ['employee_id' => '5']);
assert_same([['Tecnico E' => '16R 17A 18R'], []], [$eventCodes($engineEvents($archived)), array_values(array_filter($archived, static fn (array $item): bool => $item['event_type'] === 'absence'))], 'colaborador arquivado: motor ate a vespera; a ausencia vira situacao');
$outside = $calendar($actors['gestor'], ['employee_id' => '7']);
assert_same([[], ['absence-305']], [$engineEvents($outside), $ids(array_values(array_filter($outside, static fn (array $item): bool => $item['event_type'] === 'absence')))], 'fora do Mapa: sem escala do motor; a ausencia continua como evento');
// Periodo so depois do corte: a ausencia que acabou de ser cortada nao aparece.
$after = $calendar($actors['gestor'], ['from' => '2026-11-16']);
assert_same(['absence-305', 'absence-306'], $ids(array_values(array_filter($after, static fn (array $item): bool => $item['event_type'] === 'absence'))), 'periodo depois do corte: ausencia de quem esta no motor nao vira evento fora do periodo');
// Sem relogio (D9): o "hoje" do servico nao muda o Calendario.
assert_same($items, $calendar($actors['gestor'], [], null, '2026-11-19'), 'Calendario sem relogio: o mesmo resultado em outro dia');
// Periodo antes do corte, corte no futuro e tabela vazia: identico ao sem corte, por perfil.
foreach ([
    'periodo antes do corte' => [static fn (): QaCutoffPdo => $reportDb(), ['from' => '2026-11-02', 'to' => '2026-11-15']],
    'corte no futuro' => [static fn (): QaCutoffPdo => $reportDb(true, '2026-12-16'), []],
    'tabela vazia' => [static fn (): QaCutoffPdo => $reportDb(true, null), []],
] as $label => [$db, $filters]) {
    foreach ($actors as $who => $actor) {
        assert_same($calendar($actor, $filters, $reportDb(false)), $calendar($actor, $filters, $db()), "Calendario ({$label}, {$who}): identico ao sem corte");
    }
}
// Os demais tipos nao leem o corte; Calendario e relatorio leem uma vez.
$pdo = $reportDb();
(new OperationalService($pdo, '2026-11-10'))->listCalendar(['from' => '2026-11-12', 'to' => '2026-11-22', 'type' => 'overtime'], $actors['gestor']);
assert_same(0, count(array_filter($pdo->log, static fn (string $sql): bool => strpos($sql, 'information_schema') !== false || strpos($sql, 'portal_presence_cutoff') !== false)), 'Calendario com outro tipo: nao le o corte');
$pdo = $reportDb();
$shared = new OperationalService($pdo, '2026-11-10');
$shared->listCalendar(['from' => '2026-11-12', 'to' => '2026-11-22'], $actors['gestor']);
$shared->report(['from' => '2026-11-12', 'to' => '2026-11-22']);
assert_same(1, count(array_filter($pdo->log, static fn (string $sql): bool => $sql === 'SELECT cutoff_date FROM portal_presence_cutoff WHERE id = 1')), 'Calendario e relatorio: corte lido uma vez por requisicao');

echo "QA presence cutoff OK ({$checks} verificacoes)\n";
