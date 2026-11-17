<?php
declare(strict_types=1);

// ============================================================================
// QA do Controle de CHG no portal (desenho principal, 9.17)
//
// Sem banco:
// 1. estrutura da migration 025, do rollback, das linhas do ensaio e da
//    consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote. Dados so ficticios.
//
// Uso: php scripts/qa-chg.php
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
// 1. Migration 025, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261117_025_chg.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261117_025_chg_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261117_025_ensaio_linhas_chg.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261117_025_chg_check.sql');

const CHG_TABLES = [
    'funcionario_grupos', 'portal_chg_carteiras', 'portal_chg_locais', 'portal_chg_updates',
    'portal_chg_update_carteiras', 'portal_chg_update_locais', 'portal_chg_plans', 'portal_chg_attempts',
    'portal_chg_endpoints', 'portal_chg_rollbacks',
];

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 025: fuso da sessao (A4)');
foreach (CHG_TABLES as $i => $table) {
    $n = $i + 1;
    assert_same(1, substr_count($migration, "CREATE TABLE {$table} ("), "migration 025: cria {$table}");
    assert_same(1, preg_match("/WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = '{$table}'\\s*\\);\\s*SET @create_{$n}_sql := IF\\(\\s*@has_{$n} = 0,/", $migration), "migration 025: {$table} so se nao existir (idempotente)");
}
assert_same(10, substr_count($migration, 'CREATE TABLE '), 'migration 025: exatamente dez tabelas novas');
assert_same(0, preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP|TRUNCATE|ALTER\s+TABLE)\b/i', $migration), 'migration 025: so estrutura, nenhuma linha nem tabela existente muda');
assert_same(27, substr_count($migration, 'CONSTRAINT ck_'), 'migration 025: 27 CHECKs (a verificacao espera checks_025 = 27)');
assert_same(true, strpos($check, 'checks_025 = 27') !== false && strpos($check, 'tabelas_025 = 10') !== false, 'verificacao 025: o esperado escrito bate com a migration');

// Grupo Tools (D-CHG-13): tabela por funcionario, lista fechada, FK com RESTRICT.
assert_same(1, substr_count($migration, "grupo ENUM(''tools'') NOT NULL"), 'migration 025: grupo em lista fechada (so tools)');
assert_same(1, substr_count($migration, 'PRIMARY KEY (funcionario_id, grupo)'), 'migration 025: uma pertenca por funcionario e grupo');
assert_same(1, substr_count($migration, 'CONSTRAINT fk_funcionario_grupos_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'), 'migration 025: grupo preso ao cadastro (RESTRICT)');

// Card (9.17.3).
assert_same([1, 1, 1, 1, 1, 1, 1], [
    substr_count($migration, 'CONSTRAINT ck_chg_update_janela CHECK (sd_inicio IS NULL OR sd_fim IS NULL OR sd_fim >= sd_inicio)'),
    substr_count($migration, 'CONSTRAINT ck_chg_update_numero CHECK (chg_numero IS NULL OR (CHAR_LENGTH(TRIM(chg_numero)) > 0 AND CAST(chg_numero AS BINARY) = CAST(UPPER(chg_numero) AS BINARY)))'),
    substr_count($migration, 'CONSTRAINT ck_chg_update_status CHECK (chg_status IS NULL OR chg_numero IS NOT NULL)'),
    substr_count($migration, 'CONSTRAINT ck_chg_update_tecnico CHECK (tecnico_id IS NULL OR tecnico_nome_legado IS NULL)'),
    substr_count($migration, 'CONSTRAINT ck_chg_update_manual CHECK ((stage_manual IS NULL) = (stage_manual_auto IS NULL))'),
    substr_count($migration, 'CONSTRAINT fk_chg_update_tecnico FOREIGN KEY (tecnico_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'),
    substr_count($migration, 'UNIQUE KEY uq_chg_update_legacy (legacy_id)'),
], 'migration 025: fim nao antes do inicio, numero em maiusculas, status so com numero, tecnico por ID ou nome do historico, manual com a base, tecnico por FK e id legado unico');
assert_same([1, 1, 1], [
    substr_count($migration, "service_status ENUM(''aguardando'', ''aprovado'', ''cancelado'') NOT NULL DEFAULT ''aguardando''"),
    substr_count($migration, "chg_status ENUM(''pendente'', ''aprovada'', ''cancelada'') NULL"),
    substr_count($migration, "tipo ENUM(''padrao'', ''emergencial'') NOT NULL DEFAULT ''padrao''"),
], 'migration 025: aprovacao Service, status da CHG (nulo = nao solicitada) e tipo em lista fechada');
assert_same(1, substr_count($migration, 'row_version INT UNSIGNED NOT NULL DEFAULT 1'), 'migration 025: versao da linha para o 409 (D-CHG-21)');
assert_same([1, 0], [substr_count($migration, 'archived_at DATETIME NULL'), preg_match('/ON DELETE CASCADE/i', $migration)], 'migration 025: card arquiva (nunca apagado) e nenhuma FK em cascata (D-CHG-20)');

// Partes do card.
assert_same(1, substr_count($migration, 'UNIQUE KEY uq_chg_update_local_nome (update_id, nome)'), 'migration 025: locais do card sem repetir');
assert_same(1, substr_count($migration, "CONSTRAINT ck_chg_update_carteira_codigo CHECK (codigo IS NULL OR REGEXP_LIKE(codigo, ''^[0-9]{1,10}$''))"), 'migration 025: carteira do card com codigo numerico ou sem codigo (D-CHG-18)');
assert_same(3, preg_match_all("/ENUM\\(''pendente'', ''fechada''\\) NOT NULL DEFAULT ''pendente''/", $migration), 'migration 025: as tres tasks do plano, pendente ou fechada');
assert_same(1, substr_count($migration, 'CONSTRAINT ck_chg_endpoint_inicio CHECK (start_at IS NULL OR start_date IS NULL)'), 'migration 025: inicio do patch com hora ou so a data (horario pendente), nunca os dois');
assert_same(2, substr_count($migration, "url = '''' OR url LIKE ''http://_%'' OR url LIKE ''https://_%''"), 'migration 025: links de endpoint e rollback so http(s) (D-CHG-22)');
assert_same([1, 1], [
    substr_count($migration, 'active_update_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(removed_at IS NULL, update_id, NULL)) STORED'),
    substr_count($migration, 'UNIQUE KEY uq_chg_rollback_ativo (active_update_id)'),
], 'migration 025: no maximo um rollback ativo por card');
assert_same(0, preg_match('/\b(observacao|note|comentario|chamado)\b/i', $migration), 'migration 025: nenhuma coluna de texto livre alem das do v5.2 (o chamado fica de fora, D-CHG-24)');

// Rollback.
assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 025: para se ja houver copia *_bkp_025');
assert_same(true, strpos($rollback, 'CALL rollback_025_confere_copias();') < strpos($rollback, 'CREATE TABLE funcionario_grupos_bkp_025'), 'rollback 025: confere as copias antes de copiar');
$drops = [];
foreach (['portal_chg_endpoints', 'portal_chg_attempts', 'portal_chg_rollbacks', 'portal_chg_plans', 'portal_chg_update_locais', 'portal_chg_update_carteiras', 'portal_chg_updates', 'portal_chg_locais', 'portal_chg_carteiras', 'funcionario_grupos'] as $table) {
    $drops[] = strpos($rollback, "'DROP TABLE {$table}'");
    assert_same(true, strpos($rollback, "CREATE TABLE {$table}_bkp_025 AS") !== false, "rollback 025: copia {$table}");
}
$sorted = $drops;
sort($sorted);
assert_same(true, !in_array(false, $drops, true) && $drops === $sorted && strpos($rollback, 'CREATE TABLE portal_chg_rollbacks_bkp_025') < $drops[0], 'rollback 025: copia as dez tabelas e apaga na ordem inversa das FKs');

// Linhas do ensaio (ficticias, so em ensaio_*).
assert_same(10, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio: cada comando so no banco ensaio_');
assert_same(true, strpos($lines, "'Carteira Teste 01'") !== false && strpos($lines, "'CHG0000001'") !== false && strpos($lines, "'https://endpoint.teste/1'") !== false, 'linhas do ensaio: so dados ficticios');
assert_same(true, strpos($lines, "<> 'somente_leitura'") !== false, 'linhas do ensaio: Tools so para quem nao e somente leitura (D-CHG-14)');

// Verificacao.
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 025 so le');
// O nome do tecnico do historico so aparece contado (IS NOT NULL), nunca devolvido.
assert_same([1, 0], [
    substr_count($check, 'WHERE tecnico_nome_legado IS NOT NULL) AS cards_tecnico_historico'),
    preg_match('/\b(solicitante|tecnico_nome_legado|chg_numero|url|link_planilha|versao|nome|ad_login|login)\b/i', str_replace('tecnico_nome_legado IS NOT NULL', '', qa_sql_without_comments($check))),
], 'verificacao 025 nao devolve nome, link, caminho nem numero de CHG (o tecnico do historico so contado)');
assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $check), 'verificacao 025 sem relogio do banco');
assert_same(2, preg_match_all('/IF\(\s*@tem_025 = 10,/', $check), "verificacao 025: contagens das tabelas so com elas presentes, senao '025 ausente'");
foreach (['tabelas_025', 'checks_025', 'copias_de_rollback_025', 'grupo_tools', 'carteiras_catalogo', 'locais_catalogo', 'cards', 'cards_ativos', 'cards_importados', 'cards_tecnico_historico', 'planos', 'tentativas', 'endpoints', 'rollbacks_ativos', 'cards_sem_plano_1', 'cards_sem_tentativa', 'tentativas_atuais_no_historico', 'historico_sem_data', 'rollbacks_ativos_repetidos', 'tools_somente_leitura', 'tools_inativos'] as $column) {
    assert_same(1, preg_match('/\bAS ' . $column . '\b/', $check), "verificacao 025: conta {$column} (nome exato da coluna)");
}
assert_same(1, preg_match_all('/BIT_XOR\(CRC32\(CONCAT_WS\(/', $check), 'verificacao 025: assinatura dos cadastros (a 025 nao muda linha)');

echo 'QA do Controle de CHG OK (' . $GLOBALS['qa_checks'] . " conferencias)\n";
