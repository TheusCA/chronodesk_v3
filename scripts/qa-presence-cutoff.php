<?php
declare(strict_types=1);

// ============================================================================
// QA da data de corte do motor de presenca (Parte B, F5; desenho, secao 9.7)
//
// 1. Migration 019, registro do corte, rollback e verificacao (estrutura; o
//    SQL so roda no servidor e no MySQL do responsavel: ensaio e teste).
//
// Nao acessa banco nem os arquivos de estado da aplicacao.
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
assert_same(0, preg_match('/\b(SIGNAL|DROP|DEFAULT CURRENT|ON UPDATE)\b/i', $migration), '019: sem parada, sem remocao e sem data do banco');
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
assert_same(0, preg_match('/\b(UPDATE|DELETE|TRUNCATE|ALTER)\b/i', $rollback), 'rollback 019: nao altera outra tabela');

// Verificacao: so le, so contagens e a data; o autor nao sai.
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|RENAME|CALL)\b/i', $check), 'verificacao 019 so le');
assert_same([2, true], [substr_count($check, 'registered_by'), $at($check, "SUM(registered_by IS NULL OR TRIM(registered_by) = '''')") > -1], 'verificacao 019: autor nunca exibido (so na contagem sem_autor)');
foreach (['tabela_019', 'checks_019', 'copia_rollback', 'procedimentos_soltos', 'cortes', 'corte', 'corte_dia_16', 'corte_no_futuro', 'sem_autor', 'eventos_registro', 'eventos_remocao'] as $column) {
    assert_same(true, $at($check, "AS {$column}") > -1, "verificacao 019: {$column}");
}
assert_same(true, $at($check, "@has_cutoff_table = 1,") > -1 && $at($check, "@has_audit_log = 1,") > -1, 'verificacao 019: corte e auditoria so com as tabelas presentes (ensaio sem audit_log)');
assert_same(true, $at($check, "AND ROUTINE_NAME IN ('registrar_corte_019', 'rollback_019_confere_copia')") > -1, 'verificacao 019: conta procedimento que sobrou de recusa');
assert_same(true, $at($check, 'SUM(cutoff_date > @hoje)') > -1, 'verificacao 019: corte no futuro (D2)');

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

echo "QA presence cutoff OK ({$checks} verificacoes)\n";
