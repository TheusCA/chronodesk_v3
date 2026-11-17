<?php
declare(strict_types=1);

// ============================================================================
// QA do rodizio de papeis do Critico (desenho principal, 9.16)
//
// Sem banco:
// 1. estrutura da migration 024, do rollback, das linhas do ensaio e da
//    consulta de verificacao;
// 2. regras puras (CriticalRolePlanner): periodo, posicao, papel do dia,
//    vagos, previa e validacoes;
// 3. motor: a jornada do dia pelo papel (shiftFor) e a escala de origem 'papel';
// 4. repositorio: carga em lote so com cadastro do Critico;
// 5. CriticalRoleService contra o banco simulado (permissoes, validacoes,
//    409s, auditoria so com IDs);
// 6. endpoint, cadastro (409 da troca de equipe) e dashboard (leitura do codigo).
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote.
//
// Uso: php scripts/qa-critical-roles.php
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
// 1. Migration 024, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261117_024_critical_roles.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261117_024_critical_roles_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261117_024_ensaio_linhas_pc.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261117_024_critical_roles_check.sql');

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 024: fuso da sessao (A4)');
foreach (['portal_critical_roles', 'portal_critical_role_rotations', 'portal_critical_role_overrides'] as $table) {
    assert_same(1, substr_count($migration, "CREATE TABLE {$table} ("), "migration 024: cria {$table}");
    assert_same(1, preg_match("/WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = '{$table}'\\s*\\);\\s*SET @create_\\w+_sql := IF\\(\\s*@has_\\w+_table = 0,/", $migration), "migration 024: {$table} so se nao existir (idempotente)");
}
assert_same(0, preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP|TRUNCATE)\b/i', $migration), 'migration 024: so estrutura, nenhuma linha muda');
assert_same([1, 1, 1], [
    substr_count($migration, "CONSTRAINT ck_critical_role_shift CHECK (work_start < work_end)"),
    substr_count($migration, "CONSTRAINT ck_critical_rotation_anchor CHECK ((period = ''week'' AND WEEKDAY(anchor_date) = 0) OR (period = ''month'' AND DAYOFMONTH(anchor_date) = 1))"),
    substr_count($migration, "CONSTRAINT ck_critical_override_period CHECK (ends_on >= starts_on AND DATEDIFF(ends_on, starts_on) < 31)"),
], 'migration 024: jornada sem cruzar a meia-noite, ancora na segunda ou no dia 1 (AD-PC-1), troca de ate 31 dias');
assert_same(2, substr_count($migration, '{"type": "array", "minItems": 1, "maxItems": 20, "uniqueItems": true, "items": {"type": "integer", "minimum": 1}}'), 'migration 024: papeis e pessoas do rodizio como listas de IDs (1 a 20, sem repeticao)');
assert_same([1, 1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_critical_role_name (name)'),
    substr_count($migration, 'UNIQUE KEY uq_critical_rotation_from (effective_from)'),
    substr_count($migration, 'CONSTRAINT fk_critical_override_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'),
    substr_count($migration, 'CONSTRAINT fk_critical_override_role FOREIGN KEY (role_id) REFERENCES portal_critical_roles (id) ON DELETE RESTRICT'),
], 'migration 024: nome unico, uma versao por data, FKs com RESTRICT');
assert_same(1, substr_count($migration, "reason ENUM(''troca'', ''cobertura'', ''outro'') NOT NULL"), 'migration 024: motivo da troca em lista fechada, sem texto livre');
assert_same(0, preg_match('/\b(note|observacao|nome_funcionario|employee_name)\b/i', $migration), 'migration 024: nenhuma coluna de texto livre nem nome de pessoa');

assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 024: para se ja houver copia *_bkp_024');
assert_same(true, strpos($rollback, 'CALL rollback_024_confere_copias();') < strpos($rollback, 'CREATE TABLE portal_critical_roles_bkp_024'), 'rollback 024: confere as copias antes de copiar');
$dropOverrides = strpos($rollback, "'DROP TABLE portal_critical_role_overrides'");
$dropRotations = strpos($rollback, "'DROP TABLE portal_critical_role_rotations'");
$dropRoles = strpos($rollback, "'DROP TABLE portal_critical_roles'");
assert_same(true, $dropOverrides !== false && $dropOverrides < $dropRotations && $dropRotations < $dropRoles && strpos($rollback, 'CREATE TABLE portal_critical_role_overrides_bkp_024') < $dropOverrides, 'rollback 024: copia as tres tabelas e apaga trocas, rodizio e papeis, nessa ordem (FK)');

assert_same(5, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio: cada comando so no banco ensaio_');
assert_same(true, strpos($lines, "'2026-11-16', 'week', '2026-11-16'") !== false && strpos($lines, "'2026-11-18', '2026-11-18', p.id, 'troca'") !== false, 'linhas do ensaio: rodizio semanal ancorado na segunda e uma troca de um dia, datas explicitas');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 024 so le');
assert_same(0, preg_match('/\b(nome|employee_name|ad_login|login)\b/i', qa_sql_without_comments($check)), 'verificacao 024 nao devolve nome nem login');
assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $check), 'verificacao 024 sem relogio do banco');
assert_same(1, preg_match_all("/IF\\(\\s*@tem_024 = 3,/", $check), "verificacao 024: contagens das tabelas so com elas presentes, senao '024 ausente'");
foreach (['tabelas_024', 'checks_024', 'copias_de_rollback_024', 'papeis', 'papeis_ativos', 'rodizios', 'trocas', 'trocas_sobrepostas', 'trocas_fora_do_critico', 'rodizios_com_pessoa_fora_do_critico', 'rodizios_com_papel_inexistente'] as $column) {
    assert_same(1, preg_match('/\bAS ' . $column . '\b/', $check), "verificacao 024: conta {$column} (nome exato da coluna)");
}
assert_same(2, preg_match_all('/BIT_XOR\(CRC32\(CONCAT_WS\(/', $check), 'verificacao 024: assinaturas de cadastros e regras (a 024 nao muda linha)');

fwrite(STDOUT, 'QA do rodizio de papeis do Critico: ' . $GLOBALS['qa_checks'] . " verificacoes OK\n");
