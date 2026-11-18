<?php
declare(strict_types=1);

// ============================================================================
// QA do Controle de CHG no portal (desenho principal, 9.17)
//
// Sem banco:
// 1. estrutura da migration 025, do rollback, das linhas do ensaio e da
//    consulta de verificacao;
// 2. regras puras (ChgRules), em tabela: etapa automatica e com o manual
//    (igual ao v5.2), mover, concluir, tentativas de patch, duplicar, links e
//    os rotulos prontos (selo, aviso, barra de SLA);
// 3. ChgService contra um banco em memoria: permissao (perfil e grupo Tools),
//    formulario, 409 de versao, acoes do card, catalogos, auditoria so com IDs;
// 4. tabela do card (copiar) e o Excel do servidor (ZIP, XML, formulas);
// 5. endpoints, sessao, cadastro (grupo Tools) e o interruptor (leitura do
//    codigo; a matriz de perfis fica no qa-security).
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

// ----------------------------------------------------------------------------
// 2. Regras puras (ChgRules): etapa, movimento, conclusao, tentativas, links
// ----------------------------------------------------------------------------
require_once $root . '/services/ChgRules.php';

$now = new DateTimeImmutable('2026-11-17 12:00:00');
$ep = static fn (string $url, ?string $at = null, ?string $date = null): array => ['url' => $url, 'start_at' => $at, 'start_date' => $date];
$attempt = static fn (int $n, ?string $status = null, bool $reag = false, array $endpoints = [], ?string $registered = null): array => ['attempt_no' => $n, 'patch_status' => $status, 'reagendando' => $reag, 'registered_at' => $registered, 'endpoints' => $endpoints];
$approved = ['chg_numero' => 'CHG0000001', 'chg_status' => 'aprovada', 'service_status' => 'aprovado', 'tecnico_id' => 7];
$c = static fn (array $over = []): array => ChgRules::card(array_merge($approved, $over));

// autoStage (igual ao v5.2), em tabela: [rotulo, card, esperado]
$autoCases = [
    ['rollback com sucesso vai a Validacao mesmo com o patch em Erro', ['rollback' => ['status' => 'sucesso'], 'attempts' => [$attempt(1, 'erro')]], 'valid'],
    ['rollback em andamento fica em Execucao', ['rollback' => ['status' => 'em_andamento']], 'exec'],
    ['rollback com erro fica em Execucao', ['rollback' => ['status' => 'erro']], 'exec'],
    ['rollback vale ate sem CHG', ['chg_numero' => null, 'chg_status' => null, 'rollback' => ['status' => 'em_andamento']], 'exec'],
    ['sem numero de CHG: Comite', ['chg_numero' => null, 'chg_status' => null, 'sd_inicio' => '2026-11-17 08:00:00'], 'comite'],
    ['CHG pendente: Comite', ['chg_status' => 'pendente', 'sd_inicio' => '2026-11-17 08:00:00'], 'comite'],
    ['numero so com espacos: Comite', ['chg_numero' => '   ', 'sd_inicio' => '2026-11-17 08:00:00'], 'comite'],
    ['patch Sucesso: Validacao', ['attempts' => [$attempt(1, 'sucesso')]], 'valid'],
    ['patch Parcial: Validacao', ['attempts' => [$attempt(1, 'parcial')]], 'valid'],
    ['patch Erro: Preparacao', ['attempts' => [$attempt(1, 'erro')], 'sd_inicio' => '2026-11-17 08:00:00'], 'prep'],
    ['reagendando com o novo patch no futuro: Preparacao', ['sd_inicio' => '2026-11-17 08:00:00', 'attempts' => [$attempt(1, 'erro', false, [], '2026-11-17 09:00:00.000'), $attempt(2, null, true, [$ep('', '2026-11-17 13:00:00')])]], 'prep'],
    ['reagendando a partir do inicio do novo patch: Execucao', ['attempts' => [$attempt(1), $attempt(2, null, true, [$ep('', '2026-11-17 12:00:00')])]], 'exec'],
    ['reagendando: vale o menor inicio dos endpoints', ['attempts' => [$attempt(1), $attempt(2, null, true, [$ep('', '2026-11-17 18:00:00'), $ep('', '2026-11-17 11:59:00')])]], 'exec'],
    ['reagendando so com a data (horario pendente): Preparacao', ['sd_inicio' => '2026-11-17 08:00:00', 'attempts' => [$attempt(1), $attempt(2, null, true, [$ep('', null, '2026-11-17')])]], 'prep'],
    ['sem inicio do SD: Preparacao', ['sd_inicio' => null], 'prep'],
    ['agora no inicio: Execucao', ['sd_inicio' => '2026-11-17 12:00:00'], 'exec'],
    ['inicio no passado: Execucao', ['sd_inicio' => '2026-11-16 20:00:00'], 'exec'],
    ['exatamente 4 h antes: Preparacao', ['sd_inicio' => '2026-11-17 16:00:00'], 'prep'],
    ['4 h e 1 min antes: Comite', ['sd_inicio' => '2026-11-17 16:01:00'], 'comite'],
    ['inicio amanha: Comite', ['sd_inicio' => '2026-11-18 20:00:00'], 'comite'],
];
foreach ($autoCases as [$label, $over, $expected]) {
    assert_same($expected, ChgRules::autoStage($c($over), $now), "autoStage: {$label}");
}

// stageOf: cancelamento, conclusao e a combinacao manual x automatico do v5.2.
$stageCases = [
    ['aprovacao Service cancelada', ['service_status' => 'cancelado', 'concluida' => true], 'cancel'],
    ['CHG cancelada', ['chg_status' => 'cancelada', 'concluida' => true], 'cancel'],
    ['concluida', ['concluida' => true, 'sd_inicio' => '2026-11-17 08:00:00'], 'done'],
    ['manual a frente da base: fica no manual', ['sd_inicio' => '2026-11-18 20:00:00', 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite'], 'prep'],
    ['manual a frente: o automatico que passa dele vale', ['sd_inicio' => '2026-11-17 08:00:00', 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite'], 'exec'],
    ['manual dois passos a frente, automatico entre os dois', ['sd_inicio' => '2026-11-17 15:00:00', 'stage_manual' => 'exec', 'stage_manual_auto' => 'comite'], 'exec'],
    ['manual atras da base, automatico ainda na base: manual', ['sd_inicio' => '2026-11-17 15:00:00', 'stage_manual' => 'comite', 'stage_manual_auto' => 'prep'], 'comite'],
    ['manual atras da base, automatico mudou: automatico', ['sd_inicio' => '2026-11-17 08:00:00', 'stage_manual' => 'comite', 'stage_manual_auto' => 'prep'], 'exec'],
    ['manual desconhecido: automatico', ['sd_inicio' => '2026-11-17 08:00:00', 'stage_manual' => 'triagem', 'stage_manual_auto' => 'comite'], 'exec'],
    ['base desconhecida: compara com o automatico', ['sd_inicio' => '2026-11-18 20:00:00', 'stage_manual' => 'valid', 'stage_manual_auto' => 'x'], 'valid'],
];
foreach ($stageCases as [$label, $over, $expected]) {
    assert_same($expected, ChgRules::stageOf($c($over), $now), "stageOf: {$label}");
}
assert_same([true, false, false], [
    ChgRules::isManual($c(['sd_inicio' => '2026-11-18 20:00:00', 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite']), $now),
    ChgRules::isManual($c(['sd_inicio' => '2026-11-17 15:00:00', 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite']), $now),
    ChgRules::isManual($c(['sd_inicio' => '2026-11-18 20:00:00', 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite', 'concluida' => true]), $now),
], 'isManual: so quando a etapa vem do manual e difere da automatica (e nunca concluida)');
assert_same([true, false, false], [
    ChgRules::waitingWindow($c(['sd_inicio' => '2026-11-18 20:00:00']), $now),
    ChgRules::waitingWindow($c(['sd_inicio' => '2026-11-18 20:00:00', 'chg_status' => 'pendente']), $now),
    ChgRules::waitingWindow($c(['sd_inicio' => '2026-11-17 15:00:00']), $now),
], 'waitingWindow: CHG aprovada ainda no Comite');
assert_same([true, false, false], [
    ChgRules::isLate($c(['sd_inicio' => '2026-11-16 08:00:00', 'sd_fim' => '2026-11-17 11:59:00']), $now),
    ChgRules::isLate($c(['sd_inicio' => '2026-11-16 08:00:00', 'sd_fim' => '2026-11-17 12:00:00']), $now),
    ChgRules::isLate($c(['sd_inicio' => '2026-11-16 08:00:00', 'sd_fim' => '2026-11-17 11:00:00', 'attempts' => [$attempt(1, 'sucesso')]]), $now),
], 'isLate: Janela encerrada so em Execucao com o fim no passado');

// Conclusao (finishBlocker) e tasks necessarias.
$plans = [
    ['implantacao' => 'CTASK0000001', 'backup' => 'N/A', 'rollback' => 'na', 'task_impl' => 'fechada', 'task_bkp' => 'pendente', 'task_roll' => 'pendente'],
    ['implantacao' => 'CTASK0000002', 'backup' => 'CTASK0000003', 'rollback' => '', 'task_impl' => 'pendente', 'task_bkp' => 'pendente', 'task_roll' => 'pendente'],
];
assert_same([true, false, false, false, true], [ChgRules::taskNeeded('CTASK1'), ChgRules::taskNeeded('N/A'), ChgRules::taskNeeded(' na '), ChgRules::taskNeeded(''), ChgRules::taskNeeded('NAO')], 'task necessaria: plano preenchido e diferente de N/A');
assert_same(['Task de Implantação (Plano 2)', 'Task de Backup (Plano 2)'], ChgRules::openTasks($c(['plans' => $plans])), 'tasks abertas: so as necessarias, com o plano');
$finishCases = [
    ['rollback em andamento', ['rollback' => ['status' => 'em_andamento'], 'plans' => [ChgRules::emptyPlan()]], 'Finalize o rollback com Sucesso antes de concluir.'],
    ['rollback com erro', ['rollback' => ['status' => 'erro']], 'Finalize o rollback com Sucesso antes de concluir.'],
    ['sem tecnico', ['tecnico_id' => null], 'Informe o técnico responsável antes de concluir.'],
    ['tecnico so do historico vale', ['tecnico_id' => null, 'tecnico_nome_legado' => 'Tecnico 01'], null],
    ['duas tasks abertas', ['plans' => $plans], 'Feche antes de concluir: Task de Implantação (Plano 2) e Task de Backup (Plano 2).'],
    ['tres tasks abertas', ['plans' => [$plans[1], ['implantacao' => 'X1'] + ChgRules::emptyPlan()]], 'Feche antes de concluir: Task de Implantação (Plano 1), Task de Backup (Plano 1) e Task de Implantação (Plano 2).'],
    ['uma task aberta', ['plans' => [['implantacao' => 'X1'] + ChgRules::emptyPlan()]], 'Feche antes de concluir: Task de Implantação (Plano 1).'],
    ['rollback com sucesso e tudo fechado', ['rollback' => ['status' => 'sucesso'], 'plans' => [$plans[0]]], null],
];
foreach ($finishCases as [$label, $over, $expected]) {
    assert_same($expected, ChgRules::finishBlocker($c($over)), "concluir: {$label}");
}

// Mover (moveTo do v5.2).
$future = ['sd_inicio' => '2026-11-18 20:00:00'];
assert_same(['concluida' => true, 'concluida_em' => '2026-11-17 12:00:00.000', 'service_status' => 'aprovado', 'chg_status' => 'pendente'], ChgRules::moveChanges($c(['service_status' => 'cancelado', 'chg_status' => 'cancelada']), 'done', $now), 'mover para Concluidas: conclui e desfaz o cancelamento (CHG com numero volta a Pendente)');
assert_same(['concluida' => true, 'concluida_em' => '2026-11-17 12:00:00.000', 'chg_status' => null], ChgRules::moveChanges($c(['chg_numero' => '', 'chg_status' => 'cancelada']), 'done', $now), 'mover para Concluidas: CHG cancelada sem numero fica sem status');
assert_same(['service_status' => 'cancelado'], ChgRules::moveChanges($c($future), 'cancel', $now), 'mover para Canceladas: aprovacao Service = Cancelado');
assert_same(['concluida' => false, 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite'], ChgRules::moveChanges($c($future), 'prep', $now), 'mover para outra coluna: grava o manual e a base automatica');
assert_same(['concluida' => false, 'service_status' => 'aguardando', 'stage_manual' => null, 'stage_manual_auto' => null], ChgRules::moveChanges($c($future + ['service_status' => 'cancelado']), 'comite', $now), 'mover de Canceladas para a coluna automatica: desfaz o cancelamento e limpa o manual');
assert_same(['concluida' => false, 'stage_manual' => null, 'stage_manual_auto' => null], ChgRules::moveChanges($c($future + ['concluida' => true]), 'comite', $now), 'reabrir pelo movimento: volta ao automatico');
assert_same(null, ChgRules::moveChanges($c($future), 'comite', $now), 'mover para a coluna atual: nada muda');
try {
    ChgRules::moveChanges($c(), 'arquivo', $now);
    assert_same('excecao', 'nenhuma', 'mover para etapa desconhecida e recusado');
} catch (InvalidArgumentException $e) {
    assert_same('Etapa inválida.', $e->getMessage(), 'mover para etapa desconhecida e recusado');
}
$withRollback = $c(['rollback' => ['status' => 'sucesso'], 'attempts' => [$attempt(1, 'erro')]]);
assert_same(['stage_manual' => 'valid', 'stage_manual_auto' => 'prep'], ChgRules::keepStageChanges($withRollback, array_merge($withRollback, ['rollback' => null]), $now), 'excluir o rollback mantem a etapa (grava o manual)');
assert_same(['stage_manual' => null, 'stage_manual_auto' => null], ChgRules::keepStageChanges($c(['rollback' => ['status' => 'erro'], 'sd_inicio' => '2026-11-17 08:00:00']), $c(['sd_inicio' => '2026-11-17 08:00:00']), $now), 'excluir o rollback na etapa automatica limpa o manual');
assert_same([], ChgRules::keepStageChanges($c(['concluida' => true]), $c(), $now), 'excluir o rollback de card concluido nao mexe na etapa');

// Tentativas de patch.
$two = $c(['attempts' => [$attempt(1, 'erro', false, [$ep('https://e.teste/1', '2026-11-17 09:00:00')], '2026-11-17 10:00:00.000'), $attempt(2, 'erro', true, [$ep(''), $ep('https://e.teste/2', '2026-11-17 11:00:00'), $ep('', null, '2026-11-17')])]]);
$after = ChgRules::newPatchAttempts($two, $now);
assert_same([3, [1, 2, 3], [null, null, '2026-11-17 12:00:00.000'], [true, true, null]], [count($after), array_column($after, 'attempt_no'), [$after[2]['registered_at'], $after[0]['registered_at'] === '2026-11-17 10:00:00.000' ? null : 'x', $after[1]['registered_at']], [$after[1]['reagendando'], $after[2]['reagendando'], $after[2]['patch_status']]], 'novo patch: a atual vai ao historico com a data de registro e abre a 3a, reagendando');
assert_same([[$ep('https://e.teste/2', '2026-11-17 11:00:00'), $ep('', null, '2026-11-17')], [$ep('', null, '2026-11-17')]], [$after[1]['endpoints'], $after[2]['endpoints']], 'novo patch: so os endpoints preenchidos vao ao historico; a nova abre com so a data de hoje');
try {
    ChgRules::newPatchAttempts($c(['attempts' => array_map(static fn (int $n): array => $attempt($n), range(1, 50))]), $now);
    assert_same('excecao', 'nenhuma', 'novo patch: limite de 50 tentativas');
} catch (DomainException $e) {
    assert_same('Limite de 50 tentativas de patch neste card.', $e->getMessage(), 'novo patch: limite de 50 tentativas');
}
$deleted = ChgRules::deleteAttempt(['attempts' => $after], 3);
assert_same([[1, 2], null, true], [array_column($deleted, 'attempt_no'), $deleted[1]['registered_at'], $deleted[1]['reagendando']], 'excluir a tentativa atual: a anterior volta a ser a atual');
$deleted = ChgRules::deleteAttempt(['attempts' => $after], 1);
assert_same([[1, 2], '2026-11-17 12:00:00.000', null], [array_column($deleted, 'attempt_no'), $deleted[0]['registered_at'], $deleted[1]['registered_at']], 'excluir do historico: renumera e a atual continua');
assert_same([['attempt_no' => 1, 'patch_status' => null, 'reagendando' => false, 'registered_at' => null, 'endpoints' => []]], ChgRules::deleteAttempt($c(['attempts' => [$attempt(1, 'erro', true, [$ep('https://e.teste/1')])]]), 1), 'excluir a unica tentativa: volta vazia');
try {
    ChgRules::deleteAttempt($two, 3);
    assert_same('excecao', 'nenhuma', 'excluir tentativa inexistente');
} catch (InvalidArgumentException $e) {
    assert_same('Tentativa inexistente.', $e->getMessage(), 'excluir tentativa inexistente');
}

// Duplicar.
$dup = ChgRules::duplicateBase(['id' => 9, 'row_version' => 4, 'legacy_id' => 'a1', 'solicitante' => 'Solicitante Teste 01', 'concluida_em' => 'x'] + $c(['plans' => $plans, 'rollback' => ['status' => 'sucesso'], 'concluida' => true, 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite', 'attempts' => $two['attempts']]));
assert_same([false, false, false, null, null, null, 'aguardando', false, null, null, null, 1, [], $plans, 'Solicitante Teste 01'], [
    isset($dup['id']), isset($dup['row_version']), isset($dup['legacy_id']), $dup['chg_numero'], $dup['chg_status'], $dup['tecnico_id'], $dup['service_status'],
    $dup['concluida'], $dup['stage_manual'], $dup['stage_manual_auto'], $dup['rollback'], count($dup['attempts']), $dup['attempts'][0]['endpoints'], $dup['plans'], $dup['solicitante'],
], 'duplicar: sem CHG, tecnico, endpoints, rollback, historico, status e etapa; os planos ficam (v5.2)');

// Links (D-CHG-22), numero da CHG e fim automatico.
$linkCases = [
    ['', true], ['https://endpoint.teste/a?b=1', true], ['http://endpoint.teste', true], ['HTTPS://ENDPOINT.TESTE', true],
    ['javascript:alert(1)', false], ['JavaScript:alert(1)', false], ['data:text/html;base64,AAAA', false], ['vbscript:x', false], ['file:///c:/x', false],
    ['www.endpoint.teste', false], ['endpoint.teste/x', false], ['https://', false], ['https:///caminho', false], ['https://a b.teste', false],
    ["https://endpoint.teste\n", false], ['ftp://endpoint.teste', false], ['\\\\servidor\\pasta', false], ['https://' . str_repeat('a', 2041), false],
];
foreach ($linkCases as [$url, $expected]) {
    assert_same($expected, ChgRules::validLink($url), 'link: ' . var_export(substr($url, 0, 40), true));
}
assert_same(['CHG0000001', null, null], [ChgRules::chgNumber(' chg0000001 '), ChgRules::chgNumber('   '), ChgRules::chgNumber(null)], 'numero da CHG: maiusculas, vazio = sem numero');
assert_same('2026-11-18 20:30:00', ChgRules::autoEnd('2026-11-17 20:30:00'), 'fim do SD automatico: inicio + 24 h');

// Rotulos prontos (o servidor calcula; a tela so mostra).
assert_same(['2h05', '45 min', 'agora', 'agora'], [
    ChgRules::untilLabel(new DateTimeImmutable('2026-11-17 14:05:30'), $now),
    ChgRules::untilLabel(new DateTimeImmutable('2026-11-17 12:45:00'), $now),
    ChgRules::untilLabel($now, $now),
    ChgRules::untilLabel(new DateTimeImmutable('2026-11-17 11:00:00'), $now),
], 'rotulo "faltam": h e min, ou agora');
$badgeCases = [
    [['chg_numero' => null, 'chg_status' => null, 'sd_inicio' => '2026-11-18 20:00:00'], ['text' => 'Aguardando CHG', 'tone' => 'amber']],
    [['chg_status' => 'pendente', 'sd_inicio' => '2026-11-18 20:00:00'], ['text' => 'Aguardando aprovação', 'tone' => 'violet']],
    [['sd_inicio' => '2026-11-18 20:00:00'], ['text' => 'CHG aprovada', 'tone' => 'green']],
    [['sd_inicio' => '2026-11-17 14:05:00'], ['text' => 'Inicia em 2h05', 'tone' => 'blue']],
    [['sd_inicio' => null], ['text' => 'Sem início definido', 'tone' => 'blue']],
    [['attempts' => [$attempt(1, 'erro')]], ['text' => 'Patch com erro · reagendar', 'tone' => 'red']],
    [['sd_inicio' => '2026-11-17 08:00:00', 'attempts' => [$attempt(1), $attempt(2, null, true, [$ep('', null, '2026-11-17')])]], ['text' => 'Novo patch · horário pendente', 'tone' => 'blue']],
    [['sd_inicio' => '2026-11-17 08:00:00', 'attempts' => [$attempt(1), $attempt(2, null, true, [$ep('', '2026-11-17 12:30:00')])]], ['text' => 'Novo patch inicia em 30 min', 'tone' => 'blue']],
    [['rollback' => ['status' => 'em_andamento']], ['text' => 'Rollback · Em andamento', 'tone' => 'pink']],
    [['sd_inicio' => '2026-11-16 08:00:00', 'sd_fim' => '2026-11-17 08:00:00'], ['text' => 'Janela encerrada', 'tone' => 'red']],
    [['sd_inicio' => '2026-11-17 08:00:00', 'sd_fim' => '2026-11-18 08:00:00'], ['text' => 'Em execução', 'tone' => 'cyan']],
    [['attempts' => [$attempt(1, 'parcial')]], ['text' => 'Validando TASKs', 'tone' => 'blue']],
    [['concluida' => true], ['text' => 'Concluída', 'tone' => 'green']],
    [['service_status' => 'cancelado'], ['text' => 'Cancelado', 'tone' => 'red']],
];
foreach ($badgeCases as $k => [$over, $expected]) {
    assert_same($expected, ChgRules::badge($c($over), $now), 'selo do card, caso ' . ($k + 1) . ': ' . $expected['text']);
}
assert_same(['CHG aprovada. Inicia preparação 18/11 16h00 (faltam 28h00).', null], [ChgRules::waitingNote($c(['sd_inicio' => '2026-11-18 20:00:00']), $now), ChgRules::waitingNote($c(['sd_inicio' => '2026-11-17 15:00:00']), $now)], 'aviso do Comite: quando entra em preparacao');
$sla = ChgRules::slaBar($c([
    'sd_inicio' => '2026-11-17 06:00:00', 'sd_fim' => '2026-11-18 06:00:00',
    'attempts' => [$attempt(1, 'erro', false, [$ep('https://e.teste/1', '2026-11-17 07:00:00')], '2026-11-17 08:00:00.000'), $attempt(2, null, true, [$ep('https://e.teste/2', '2026-11-17 13:00:00'), $ep('', '2026-11-17 10:00:00')])],
    'rollback' => ['status' => 'em_andamento', 'start_at' => '2026-11-17 11:00:00'],
]), $now);
assert_same([25, 'restam 18h00'], [$sla['percent'], $sla['left']], 'barra de SLA: percentual e "restam"');
assert_same([
    ['step' => 5, 'color' => 0, 'state' => 'past', 'label' => 'Patch: 17/11 07h00, 4% do SLA (Erro)'],
    ['step' => 15, 'color' => 1, 'state' => 'now', 'label' => '2º Patch 2: 17/11 10h00, 17% do SLA'],
    ['step' => 20, 'color' => 'pink', 'state' => 'now', 'label' => 'Rollback (Em andamento): 17/11 11h00, 21% do SLA'],
    ['step' => 30, 'color' => 0, 'state' => 'future', 'label' => '2º Patch 1: 17/11 13h00, 29% do SLA'],
], $sla['ticks'], 'barra de SLA: marcas em passos de 5 %, historico, rollback e futuro');
assert_same(['Rollback (Em andamento) iniciado 17/11 11h00', '2º Patch 2 iniciado 17/11 10h00', '2º Patch 1 inicia 17/11 13h00'], array_column($sla['lines'], 'text'), 'barra de SLA: linhas da tentativa atual, o rollback primeiro');
assert_same(['encerrado há 1h30', null], [ChgRules::slaBar($c(['sd_inicio' => '2026-11-16 10:30:00', 'sd_fim' => '2026-11-17 10:30:00']), $now)['left'], ChgRules::slaBar($c(['sd_inicio' => '2026-11-17 10:00:00', 'sd_fim' => '2026-11-17 10:00:00']), $now)], 'barra de SLA: "encerrado ha" e sem barra com fim igual ao inicio');
$view = ChgRules::view($approved + ['sd_inicio' => '2026-11-18 20:00:00', 'sd_fim' => '2026-11-19 20:00:00', 'stage_manual' => 'prep', 'stage_manual_auto' => 'comite'], $now);
assert_same(['prep', 'comite', true, false, false, '2026-11-18 16:00:00', null, [], false], [$view['stage'], $view['auto_stage'], $view['is_manual'], $view['late'], $view['waiting_window'], $view['prep_at'], $view['sla'], $view['open_tasks'], $view['can_new_patch']], 'view: etapa, automatica, manual, prep_at e sem barra fora da Execucao');

assert_same([true, false, true, false], [
    ChgRules::view($approved + ['sd_inicio' => '2026-11-17 08:00:00'], $now)['can_new_patch'],
    ChgRules::view($approved + ['sd_inicio' => '2026-11-17 08:00:00', 'rollback' => ['status' => 'em_andamento']], $now)['can_new_patch'],
    ChgRules::view($approved + ['attempts' => [$attempt(1, 'erro')]], $now)['can_new_patch'],
    ChgRules::view($approved + ['attempts' => [$attempt(1, 'erro')], 'rollback' => ['status' => 'sucesso']], $now)['can_new_patch'],
], 'view: "+ Novo patch" em Execucao ou com patch em Erro, nunca com rollback ativo');

// ----------------------------------------------------------------------------
// 3. Servico (ChgService) contra um banco em memoria: permissao, formulario,
//    409, acoes com as regras do v5.2, catalogos e auditoria so com IDs
// ----------------------------------------------------------------------------
require_once $root . '/security.php';
require_once $root . '/services/ChgService.php';

final class QaChgStore implements ChgStore {
    public array $rows = [];
    public array $employees = [7 => 'Tecnico 07', 8 => 'Tecnico 08'];
    public array $tools = [8 => true];
    public array $catalog = ['carteira' => [], 'local' => []];
    private ?array $snapshot = null;
    private int $nextRollback = 1;

    public function cards(?int $id = null): array {
        $list = [];
        foreach ($this->rows as $rowId => $card) {
            if (($card['archived_at'] ?? null) === null && ($id === null || $rowId === $id)) {
                $list[] = $card + ['tecnico_nome' => $card['tecnico_id'] === null ? null : ($this->employees[$card['tecnico_id']] ?? 'Arquivado')];
            }
        }
        return $list;
    }
    public function lockCard(int $id): ?array {
        return $this->cards($id)[0] ?? null;
    }
    public function insertCard(array $card, string $actor): int {
        $id = count($this->rows) + 1;
        $this->rows[$id] = $this->withRollbackId(['id' => $id] + $card);
        return $id;
    }
    public function saveCard(array $card, array $before, string $actor): void {
        unset($card['tecnico_nome'], $card['view'], $card['tabela'], $card['qtd_pas']);
        // Como o PdoChgStore: o UPDATE nao mexe no id legado nem na criacao.
        $old = $this->rows[(int)$card['id']] ?? [];
        $card = ['legacy_id' => $old['legacy_id'] ?? null, 'created_at' => $old['created_at'] ?? null] + $card;
        $card['legacy_id'] = $old['legacy_id'] ?? null;
        $card['created_at'] = $old['created_at'] ?? $card['created_at'];
        $this->rows[(int)$card['id']] = $this->withRollbackId($card);
    }
    private function withRollbackId(array $card): array {
        if (($card['rollback'] ?? null) !== null && !isset($card['rollback']['id'])) {
            $card['rollback']['id'] = $this->nextRollback++;
        }
        return $card;
    }
    public function activeEmployees(): array {
        return $this->employees;
    }
    public function catalogs(): array {
        return ['carteiras' => array_values($this->catalog['carteira']), 'locais' => array_values($this->catalog['local'])];
    }
    public function saveCatalog(string $kind, array $row, string $actor): int {
        foreach ($this->catalog[$kind] as $id => $item) {
            $key = $kind === 'carteira' ? 'codigo' : 'nome';
            if ($id !== $row['id'] && ChgRules::lower($item[$key]) === ChgRules::lower($row[$key])) {
                throw new PDOException('Duplicate entry', 23000);
            }
        }
        $id = $row['id'] ?? count($this->catalog[$kind]) + 1;
        $this->catalog[$kind][$id] = ['id' => $id] + $row;
        return $id;
    }
    public function inTools(int $employeeId): bool {
        return isset($this->tools[$employeeId]) && isset($this->employees[$employeeId]);
    }
    public function legacyIndex(): array {
        $index = [];
        foreach ($this->rows as $id => $card) {
            if (($card['legacy_id'] ?? null) !== null) {
                $index[$card['legacy_id']] = ['id' => $id, 'updated_at' => $card['updated_at'], 'row_version' => $card['row_version']];
            }
        }
        return $index;
    }
    public function begin(): void {
        $this->snapshot = [$this->rows, $this->catalog];
    }
    public function commit(): void {
        $this->snapshot = null;
    }
    public function rollBack(): void {
        [$this->rows, $this->catalog] = $this->snapshot;
        $this->snapshot = null;
    }
    public function inTransaction(): bool {
        return $this->snapshot !== null;
    }
}

$audits = [];
$clock = new DateTimeImmutable('2026-11-17 12:00:00');
$store = new QaChgStore();
$service = new ChgService($store, static function () use (&$clock): DateTimeImmutable {
    return $clock;
}, static function (string $action, string $details, string $severity) use (&$audits): void {
    $audits[] = "{$action} {$details}";
});
$expectError = static function (callable $call, string $class, string $message, string $label): void {
    try {
        $call();
        assert_same($class, 'nenhuma excecao', $label);
    } catch (Throwable $e) {
        assert_same([$class, $message], [get_class($e), $e->getMessage()], $label);
    }
};

// Permissao (9.17.2): admin e Lideranca pelo perfil; Tools pela pessoa; somente
// leitura nunca; importar so o admin.
assert_same(
    [true, true, false, false, true, false, false, false, false],
    [
        ChgAccess::canManage('admin', null, $store), ChgAccess::canManage('lideranca', null, $store),
        ChgAccess::canManage('gestor', null, $store), ChgAccess::canManage('tecnico', 7, $store),
        ChgAccess::canManage('tecnico', 8, $store), ChgAccess::canManage('somente_leitura', 8, $store),
        ChgAccess::canManage(null, 8, $store), ChgAccess::canManage('tecnico', null, $store),
        ChgAccess::canManage('tecnico', 9, $store),
    ],
    'permissao: admin e Lideranca; tecnico so no Tools; somente leitura nunca, nem no Tools (D-CHG-14)'
);
assert_same([true, false, false, false, false], [ChgAccess::canImport('admin'), ChgAccess::canImport('lideranca'), ChgAccess::canImport('gestor'), ChgAccess::canImport('tecnico'), ChgAccess::canImport('somente_leitura')], 'importar: so o admin (D-CHG-15)');
assert_same([true, false, true], [in_array('chg.manage', portal_permissions_for_role('lideranca'), true), in_array('chg.manage', portal_permissions_for_role('gestor'), true), in_array('chg.import', portal_permissions_for_role('admin'), true)], 'permissoes: chg.manage na Lideranca (e no admin), chg.import so no admin');
$gestorTools = new QaChgStore();
$gestorTools->tools = [7 => true];
assert_same(true, ChgAccess::canManage('gestor', 7, $gestorTools), 'permissao: gestor no grupo Tools escreve (o grupo e separado do perfil)');
unset($gestorTools->employees[7]);
assert_same(false, ChgAccess::canManage('tecnico', 7, $gestorTools), 'permissao: Tools de cadastro arquivado nao vale');

// Formulario.
$form = [
    'solicitante' => 'Solicitante Teste 01', 'tipo' => 'padrao',
    'carteiras' => [['codigo' => '9001', 'nome' => 'Carteira Teste 01', 'pas' => 12], ['sem_codigo' => true, 'nome' => 'Carteira Teste 02', 'pas' => 3]],
    'locais' => ['Local Teste 01', 'Local Teste 02'],
    'versao' => '\\\\servidor\\setups\\Versao Teste 01', 'versao_anterior' => '', 'descricao' => 'Descricao teste', 'link_planilha' => 'https://planilha.teste/1',
    'sugestao_at' => '2026-11-18 20:00', 'service_status' => 'aguardando', 'sd_inicio' => '2026-11-18 20:00', 'sd_fim' => '2026-11-19 20:00',
    'chg_numero' => 'chg0000001', 'chg_status' => '', 'tecnico_id' => 7,
    'plans' => [['implantacao' => 'ctask0000001', 'backup' => 'n/a', 'rollback' => 'CTASK0000002', 'task_impl' => 'pendente', 'task_bkp' => 'pendente', 'task_roll' => 'pendente']],
];
$card = $service->create($form, 'qa.ator');
assert_same([1, 1, 'CHG0000001', 'pendente', 'CTASK0000001', 'N/A', null, 15, 'comite', 'Aguardando aprovação'], [
    $card['id'], $card['row_version'], $card['chg_numero'], $card['chg_status'], $card['plans'][0]['implantacao'], $card['plans'][0]['backup'],
    $card['carteiras'][1]['codigo'], $card['qtd_pas'], $card['view']['stage'], $card['view']['badge']['text'],
], 'criar: numero e planos em maiusculas, numero novo vira Pendente, carteira sem codigo, PAs = soma, etapa e selo do servidor');
assert_same(['CHG_CRIADO Card ID 1 criado'], $audits, 'criar: auditoria so com o ID');
$formErrors = [
    ['campo desconhecido', ['chamado' => 'x'], 'Campo desconhecido no formulário.'],
    ['sem solicitante', ['solicitante' => '  '], 'Informe o solicitante.'],
    ['sem data sugestao', ['sugestao_at' => ''], 'Informe a data sugestão.'],
    ['data invalida', ['sugestao_at' => '2026-02-30 10:00'], 'Data sugestão: data e hora inválidas.'],
    ['fim antes do inicio', ['sd_fim' => '2026-11-18 19:59'], 'O fim do Service Desk não pode ser antes do início.'],
    ['aprovada sem numero', ['chg_numero' => '', 'chg_status' => 'aprovada'], 'Informe o número da CHG antes de marcá-la como aprovada.'],
    ['link javascript', ['link_planilha' => 'javascript:alert(1)'], 'Link da planilha: use o endereço completo, com https://.'],
    ['link sem esquema', ['link_planilha' => 'www.planilha.teste'], 'Link da planilha: use o endereço completo, com https://.'],
    ['local repetido (sem caixa)', ['locais' => ['Local Teste 01', 'local teste 01']], 'O local local teste 01 foi informado mais de uma vez.'],
    ['local vazio', ['locais' => ['Local Teste 01', ' ']], 'Informe o local 2 ou remova a linha vazia.'],
    ['sem local', ['locais' => []], 'Informe de 1 a 20 locais.'],
    ['carteira sem codigo nem a marca', ['carteiras' => [['codigo' => '', 'nome' => 'Carteira Teste 03']]], "Na carteira 1, informe o código ou marque 'Sem código'."],
    ['carteira sem nome', ['carteiras' => [['codigo' => '9001', 'nome' => '']]], 'Na carteira 1, informe o nome.'],
    ['codigo com letra', ['carteiras' => [['codigo' => '90A', 'nome' => 'Carteira Teste 03']]], 'Código da carteira 1: só números.'],
    ['PAs negativos', ['carteiras' => [['codigo' => '9001', 'nome' => 'Carteira Teste 01', 'pas' => -1]]], 'PAs da carteira 1: número de 0 a 99999.'],
    ['PAs em texto', ['carteiras' => [['codigo' => '9001', 'nome' => 'Carteira Teste 01', 'pas' => '12']]], 'PAs da carteira 1: número de 0 a 99999.'],
    ['carteiras demais', ['carteiras' => array_fill(0, 51, ['codigo' => '9001', 'nome' => 'Carteira Teste 01'])], 'Informe de 1 a 50 carteiras.'],
    ['descricao longa', ['descricao' => str_repeat('a', 301)], 'Descrição: até 300 caracteres.'],
    ['texto com controle', ['solicitante' => "Solicitante\x07"], 'Solicitante: caracteres inválidos.'],
    ['tecnico fora dos ativos', ['tecnico_id' => 99], 'Técnico inválido: escolha um funcionário ativo.'],
    ['tipo fora da lista', ['tipo' => 'normal'], 'Tipo de execução: valor inválido.'],
    ['plano sem nenhum', ['plans' => []], 'Informe de 1 a 20 planos.'],
    ['task fora da lista', ['plans' => [['task_impl' => 'aberta']]], 'Task do plano 1: valor inválido.'],
];
foreach ($formErrors as [$label, $over, $message]) {
    $expectError(static fn () => $service->create(array_merge($form, $over), 'qa.ator'), 'InvalidArgumentException', $message, "formulario: {$label}");
}
assert_same(1, count($store->rows), 'formulario recusado nao grava nada');

// 409 de concorrencia (D-CHG-21) e a edicao.
$expectError(static fn () => $service->update(['id' => 1, 'row_version' => 5] + $form, 'qa.ator'), 'ChgConflictException', 'Alguém alterou este card; recarregue.', 'editar com versao velha: 409');
$card = $service->update(['id' => 1, 'row_version' => 1] + array_merge($form, ['chg_numero' => '', 'chg_status' => 'pendente']), 'qa.ator');
assert_same([2, null, null], [$card['row_version'], $card['chg_numero'], $card['chg_status']], 'editar: versao + 1; sem numero, o status e limpo');
$expectError(static fn () => $service->action('set_field', ['id' => 1, 'row_version' => 1, 'field' => 'service_status', 'value' => 'aprovado'], 'qa.ator'), 'ChgConflictException', 'Alguém alterou este card; recarregue.', 'acao com versao velha: 409');
$expectError(static fn () => $service->action('apagar', ['id' => 1, 'row_version' => 2], 'qa.ator'), 'InvalidArgumentException', 'Ação do Controle de CHG inválida.', 'acao desconhecida: 400');
$expectError(static fn () => $service->action('auto', ['id' => 99, 'row_version' => 1], 'qa.ator'), 'InvalidArgumentException', 'Card inexistente ou arquivado.', 'card inexistente: 400');

// Acoes do card (regras do v5.2).
$act = static function (string $action, array $data = []) use ($service, &$card): array {
    $card = $service->action($action, ['id' => $card['id'], 'row_version' => $card['row_version']] + $data, 'qa.ator');
    return $card;
};
$act('set_field', ['field' => 'chg_numero', 'value' => 'chg0000009']);
assert_same(['CHG0000009', 'pendente'], [$card['chg_numero'], $card['chg_status']], 'numero novo pelo card: maiusculas e Pendente');
$expectError(static fn () => $service->action('set_field', ['id' => 1, 'row_version' => $card['row_version'], 'field' => 'chg_numero', 'value' => str_repeat('A', 41)], 'qa.ator'), 'InvalidArgumentException', 'CHG: até 40 caracteres.', 'numero da CHG longo demais');
$act('set_field', ['field' => 'chg_status', 'value' => 'aprovada']);
assert_same(['comite', 'CHG aprovada', 'CHG aprovada. Inicia preparação 18/11 16h00 (faltam 28h00).'], [$card['view']['stage'], $card['view']['badge']['text'], $card['view']['note']], 'aprovada com a janela amanha: Comite com o aviso da preparacao');
$act('move', ['stage' => 'prep']);
assert_same(['prep', 'prep', 'comite', true], [$card['view']['stage'], $card['stage_manual'], $card['stage_manual_auto'], $card['view']['is_manual']], 'mover para Preparacao: manual com a base');
$act('auto');
assert_same(['comite', null], [$card['view']['stage'], $card['stage_manual']], 'voltar ao automatico');
$act('move', ['stage' => 'prep']);
$act('set_field', ['field' => 'patch_status', 'value' => 'erro']);
assert_same([null, 'prep', 'Patch com erro · reagendar', true], [$card['stage_manual'], $card['view']['stage'], $card['view']['badge']['text'], $card['view']['can_new_patch']], 'patch com Erro passa por cima do manual');
$act('new_patch');
assert_same([2, true, '2026-11-17', 'Novo patch · horário pendente'], [count($card['attempts']), $card['attempts'][1]['reagendando'], $card['attempts'][1]['endpoints'][0]['start_date'], $card['view']['badge']['text']], 'novo patch: a 2a tentativa abre so com a data de hoje');
$act('endpoint_field', ['index' => 0, 'field' => 'start', 'value' => '2026-11-17 11:30']);
assert_same(['exec', '2026-11-17 11:30:00', null], [$card['view']['stage'], $card['attempts'][1]['endpoints'][0]['start_at'], $card['attempts'][1]['endpoints'][0]['start_date']], 'horario do novo patch no passado: Execucao');
$expectError(static fn () => $service->action('endpoint_field', ['id' => 1, 'row_version' => $card['row_version'], 'index' => 0, 'field' => 'url', 'value' => 'data:text/html,x'], 'qa.ator'), 'InvalidArgumentException', 'Link do endpoint: use o endereço completo, com https://.', 'endpoint com link data: recusado');
$expectError(static fn () => $service->action('endpoint_field', ['id' => 1, 'row_version' => $card['row_version'], 'index' => 0, 'field' => 'start', 'value' => '17/11/2026 11:30'], 'qa.ator'), 'InvalidArgumentException', 'Início do patch: data e hora inválidas.', 'inicio do patch fora do formato');
$act('endpoint_field', ['index' => 0, 'field' => 'url', 'value' => 'https://endpoint.teste/2']);
$act('add_endpoint');
assert_same(['https://endpoint.teste/2', null, '2026-11-17'], [$card['attempts'][1]['endpoints'][0]['url'], $card['attempts'][1]['endpoints'][1]['start_at'], $card['attempts'][1]['endpoints'][1]['start_date']], 'novo endpoint no reagendamento: so a data do primeiro');
$act('delete_endpoint', ['index' => 1]);
assert_same(1, count($card['attempts'][1]['endpoints']), 'excluir endpoint');
$act('history_status', ['attempt_no' => 1, 'value' => 'parcial']);
assert_same('parcial', $card['attempts'][0]['patch_status'], 'status de tentativa do historico editavel');
$expectError(static fn () => $service->action('history_status', ['id' => 1, 'row_version' => $card['row_version'], 'attempt_no' => 2, 'value' => 'erro'], 'qa.ator'), 'InvalidArgumentException', 'Só o status das tentativas do histórico é editado aqui.', 'status da tentativa atual nao e pelo historico');
$act('add_plan');
$act('plan_field', ['plan' => 1, 'field' => 'backup', 'value' => 'ctask0000003']);
assert_same([2, 'CTASK0000003'], [count($card['plans']), $card['plans'][1]['backup']], 'novo plano e numero em maiusculas');
$act('plan_task', ['plan' => 1, 'task' => 'task_roll', 'value' => 'iniciar_rollback']);
assert_same(['exec', 'em_andamento', 1, '2026-11-17 12:00:00', 'Rollback · Em andamento'], [$card['view']['stage'], $card['rollback']['status'], $card['rollback']['plan_position'], $card['rollback']['started_at'], $card['view']['badge']['text']], 'iniciar rollback pela task do Plano 2: Execucao, agora, plano de origem');
$expectError(static fn () => $service->action('plan_task', ['id' => 1, 'row_version' => $card['row_version'], 'plan' => 0, 'task' => 'task_roll', 'value' => 'iniciar_rollback'], 'qa.ator'), 'DomainException', 'Já existe rollback em andamento.', 'um rollback ativo so');
$expectError(static fn () => $service->action('delete_plan', ['id' => 1, 'row_version' => $card['row_version'], 'plan' => 1], 'qa.ator'), 'DomainException', 'Existe um rollback associado a este plano. Finalize ou exclua o rollback antes de remover o plano.', 'plano do rollback ativo nao sai');
$expectError(static fn () => $service->action('delete_plan', ['id' => 1, 'row_version' => $card['row_version'], 'plan' => 0], 'qa.ator'), 'DomainException', 'O Plano 1 não pode ser excluído. Edite os números se necessário.', 'Plano 1 nao sai');
$expectError(static fn () => $service->action('finish', ['id' => 1, 'row_version' => $card['row_version']], 'qa.ator'), 'DomainException', 'Finalize o rollback com Sucesso antes de concluir.', 'concluir com rollback em andamento: 409');
$act('rollback_field', ['field' => 'url', 'value' => 'https://endpoint.teste/rollback']);
$act('move', ['stage' => 'valid']);
$act('move', ['stage' => 'prep']);
assert_same(['prep', 'prep'], [$card['view']['stage'], $card['stage_manual']], 'rollback em andamento movido a mao para Preparacao');
$act('rollback_field', ['field' => 'status', 'value' => 'sucesso']);
assert_same(['valid', 'https://endpoint.teste/rollback', null], [$card['view']['stage'], $card['rollback']['url'], $card['stage_manual']], 'rollback com Sucesso passa por cima do manual: Validacao (v5.2)');
$expectError(static fn () => $service->action('finish', ['id' => 1, 'row_version' => $card['row_version']], 'qa.ator'), 'DomainException', 'Feche antes de concluir: Task de Implantação (Plano 1), Task de Rollback (Plano 1) e Task de Backup (Plano 2).', 'concluir com tasks abertas: a lista do que falta');
foreach ([[0, 'task_impl'], [0, 'task_roll'], [1, 'task_bkp']] as [$plan, $task]) {
    $act('plan_task', ['plan' => $plan, 'task' => $task, 'value' => 'fechada']);
}
$act('finish');
assert_same(['done', true, '2026-11-17 12:00:00.000'], [$card['view']['stage'], $card['concluida'], $card['concluida_em']], 'concluir execucao');
$act('reopen');
assert_same(['valid', false], [$card['view']['stage'], $card['concluida']], 'reabrir');
$act('delete_rollback');
assert_same(['valid', null, 'valid', 'valid'], [$card['view']['stage'], $card['rollback'], $card['stage_manual'], $card['view']['badge']['text'] === 'Validando TASKs' ? 'valid' : 'x'], 'excluir o rollback mantem a etapa (manual)');
$act('delete_attempt', ['attempt_no' => 2]);
assert_same([1, 'parcial'], [count($card['attempts']), $card['attempts'][0]['patch_status']], 'excluir a tentativa atual: a anterior volta');
$act('move', ['stage' => 'cancel']);
assert_same(['cancel', 'cancelado'], [$card['view']['stage'], $card['service_status']], 'mover para Canceladas');
$dup = $service->duplicateBase(1);
assert_same([null, null, 'aguardando', null, 1, 'Solicitante Teste 01'], [$dup['chg_numero'], $dup['tecnico_id'], $dup['service_status'], $dup['rollback'], count($dup['attempts']), $dup['solicitante']], 'duplicar: a base do formulario, sem CHG, tecnico, rollback e historico');
$act('archive');
assert_same([['id' => 1, 'archived' => true], []], [$card, $service->board(true, false)['cards']], 'arquivar: o card sai das telas (nunca apagado)');
assert_same(true, isset($store->rows[1]) && $store->rows[1]['archived_at'] === '2026-11-17 12:00:00', 'arquivar: a linha continua no banco');

// Auditoria so com IDs e a operacao (nada de nome, link, caminho ou numero).
assert_same(0, preg_match('/Solicitante|Tecnico|Carteira|Local|https?:|CHG\d|CTASK|servidor|Versao Teste|Descricao/i', implode("\n", array_map(static fn (string $a): string => substr($a, strpos($a, ' ') + 1), $audits))), 'auditoria: so IDs, a acao e a versao');
assert_same(1, count(array_filter($audits, static fn (string $a): bool => str_starts_with($a, 'CHG_ARQUIVADO Card ID 1 acao=archive versao='))), 'auditoria: arquivamento');

// Catalogos.
$saved = $service->saveCatalog(['kind' => 'carteira', 'codigo' => '9001', 'nome' => 'Carteira Teste 01'], 'qa.ator');
assert_same([1, '9001'], [$saved['id'], $saved['catalogos']['carteiras'][0]['codigo']], 'catalogo: carteira nova');
$expectError(static fn () => $service->saveCatalog(['kind' => 'carteira', 'codigo' => '9001', 'nome' => 'Outra'], 'qa.ator'), 'DomainException', 'Já existe carteira com este código.', 'catalogo: codigo repetido = 409');
$expectError(static fn () => $service->saveCatalog(['kind' => 'carteira', 'codigo' => 'A1', 'nome' => 'Outra'], 'qa.ator'), 'InvalidArgumentException', 'Código da carteira: só números.', 'catalogo: codigo com letra');
$service->saveCatalog(['kind' => 'local', 'nome' => 'Local Teste 01'], 'qa.ator');
$expectError(static fn () => $service->saveCatalog(['kind' => 'local', 'nome' => 'LOCAL TESTE 01'], 'qa.ator'), 'DomainException', 'Já existe local com este nome.', 'catalogo: local repetido sem diferenca de caixa');

// ----------------------------------------------------------------------------
// 4. Tabela do card (copiar) e Excel do servidor (ZIP proprio, XML, formulas)
// ----------------------------------------------------------------------------
require_once $root . '/services/ChgExport.php';

$service2 = new ChgService(new QaChgStore(), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-17 12:00:00'), static function (): void {
});
$card = $service2->create(array_merge($form, ['solicitante' => '=HYPERLINK("https://x.teste")', 'descricao' => 'https://descricao.teste/nao-e-link', 'chg_status' => 'aprovada', 'sd_inicio' => '2026-11-17 08:00', 'sd_fim' => '2026-11-18 08:00', 'link_planilha' => 'https://planilha.teste/1']), 'qa.ator');
$rows = array_column($card['tabela'], 'v', 'k');
assert_same([
    '9001 - Carteira Teste 01 (12 PAs); Carteira Teste 02 (3 PAs)', 'Local Teste 01; Local Teste 02', '15', 'Padrão', '17/11/2026 08h00',
    'Em execução', 'CHG0000001', 'Aprovada', 'Tecnico 07', 'Pendente', '',
], [
    $rows['Carteiras e códigos'], $rows['Locais impactados'], $rows['Quantidade de Pas'], $rows['Tipo de Execução'], $rows['Inicio ServiceDesk'],
    $rows['Etapa'], $rows['CHG'], $rows['Status CHG'], $rows['Tecnico Responsavel'], $rows['Task de Implantação'], $rows['Task de Backup'],
], 'tabela do card: carteiras com PAs, locais, soma, rotulos e tasks (N/A sem status)');
assert_same(['548235', 'BF8F00', null], [
    $card['tabela'][array_search('Status CHG', array_column($card['tabela'], 'k'), true)]['color'],
    $card['tabela'][array_search('Aprovação Service', array_column($card['tabela'], 'k'), true)]['color'],
    $card['tabela'][array_search('Tecnico Responsavel', array_column($card['tabela'], 'k'), true)]['color'],
], 'tabela do card: as cores de status do v5.2');
assert_same("Dados\nSolicitante\t=HYPERLINK(\"https://x.teste\")", implode("\n", array_slice(explode("\n", ChgTable::text($card['tabela'])), 0, 2)), 'texto da tabela com tabulacao');

// Tabela para copiar (HTML com as cores do v5.2), montada no servidor.
$htmlRows = [
    ['k' => 'Solicitante', 'v' => '<b>x</b>"', 'section' => false, 'rollback' => false, 'bold' => false, 'color' => null, 'link' => false],
    ['k' => 'Link', 'v' => 'javascript:alert(1)', 'section' => false, 'rollback' => false, 'bold' => false, 'color' => null, 'link' => true],
    ['k' => 'Planilha', 'v' => 'https://planilha.teste/1?a=1&b=2', 'section' => false, 'rollback' => false, 'bold' => false, 'color' => null, 'link' => true],
    ['k' => 'Rollback', 'v' => '', 'section' => true, 'rollback' => true, 'bold' => true, 'color' => null, 'link' => false],
    ['k' => 'Status', 'v' => 'Erro', 'section' => false, 'rollback' => false, 'bold' => false, 'color' => 'C00000', 'link' => false],
    ['k' => 'Cor', 'v' => 'x', 'section' => false, 'rollback' => false, 'bold' => false, 'color' => 'red;x:y', 'link' => false],
];
$copyHtml = ChgTable::html($htmlRows);
assert_same([true, false, false, true, true, true, false], [
    str_contains($copyHtml, '&lt;b&gt;x&lt;/b&gt;&quot;'), str_contains($copyHtml, '<b>x'), str_contains($copyHtml, 'href="javascript'),
    str_contains($copyHtml, '<a href="https://planilha.teste/1?a=1&amp;b=2" style="color:#0563C1">'), str_contains($copyHtml, 'background:#F7DFF0'),
    str_contains($copyHtml, 'font-weight:700;color:#C00000;'), str_contains($copyHtml, 'red;x:y'),
], 'tabela para copiar: valores escapados, link so http(s), cores do v5.2 e cor fora do formato ignorada');
$stale = $card;
$stale['view']['stage'] = 'cancel';
assert_same($card['view']['stage'], $service2->present($stale, new DateTimeImmutable('2026-11-17 12:00:00'))['view']['stage'], 'apresentar: a view e sempre recalculada (um card ja apresentado nao guarda a etapa velha)');
$copied = $service2->copyTable($card['id']);
assert_same(['Dados', 'Solicitante' . "\t" . '=HYPERLINK("https://x.teste")', true], [...array_slice(explode("\n", $copied['texto']), 0, 2), str_contains($copied['html'], '=HYPERLINK(&quot;https://x.teste&quot;)')], 'copiar: o servidor devolve o texto e o HTML do card');
$expectError(static fn () => $service2->copyTable(99), 'InvalidArgumentException', 'Card inexistente.', 'copiar: card inexistente');

$bytes = ChgExport::xlsx([$card, $card]);
// Le o ZIP sem compressao: cada entrada com o CRC certo.
$entries = [];
for ($offset = 0; substr($bytes, $offset, 4) === "PK\x03\x04";) {
    $head = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnamelen/vextra', substr($bytes, $offset + 4, 26));
    $name = substr($bytes, $offset + 30, $head['namelen']);
    $content = substr($bytes, $offset + 30 + $head['namelen'] + $head['extra'], $head['csize']);
    $entries[$name] = [$head['method'], $head['crc'] === crc32($content), $content];
    $offset += 30 + $head['namelen'] + $head['extra'] + $head['csize'];
}
assert_same(['[Content_Types].xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/_rels/sheet1.xml.rels', 'xl/worksheets/sheet2.xml', 'xl/worksheets/_rels/sheet2.xml.rels', 'xl/styles.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', '_rels/.rels'], array_keys($entries), 'Excel: as partes do pacote');
assert_same([true, [0], [true]], [str_ends_with($bytes, pack('v', 0)) && substr_count($bytes, "PK\x05\x06") === 1, array_values(array_unique(array_column($entries, 0))), array_values(array_unique(array_column($entries, 1)))], 'Excel: ZIP sem compressao, com o CRC de cada entrada e o fim do diretorio');
libxml_use_internal_errors(true);
foreach ($entries as $name => [, , $xml]) {
    assert_same(true, simplexml_load_string($xml) !== false, "Excel: {$name} e XML valido");
}
$sheet1 = $entries['xl/worksheets/sheet1.xml'][2];
$sheet2 = $entries['xl/worksheets/sheet2.xml'][2];
assert_same(true, strpos($entries['xl/workbook.xml'][2], '<sheet name="Cards" sheetId="1" r:id="rId1"/><sheet name="Base" sheetId="2" r:id="rId2"/>') !== false, 'Excel: abas Cards e Base');
assert_same([0, 2, 0], [substr_count($sheet1 . $sheet2, '<f>'), substr_count($sheet1, '=HYPERLINK(&quot;https://x.teste&quot;)'), preg_match('/t="(str|s)"/', $sheet1 . $sheet2)], 'Excel: nenhuma formula; o texto que parece formula fica como texto (inlineStr)');
preg_match('/<c r="B2" s="(\d+)" t="inlineStr">/', $sheet1, $quoted);
assert_same(1, preg_match('/<cellXfs count="\d+">(?:<xf[^>]*>(?:<alignment[^>]*\/><\/xf>)?)*/', $entries['xl/styles.xml'][2]), 'Excel: estilos');
$xfs = [];
preg_match_all('/<xf numFmtId="0"[^>]*>/', explode('<cellXfs', $entries['xl/styles.xml'][2])[1], $xfs);
assert_same(true, isset($quoted[1]) && str_contains($xfs[0][(int)$quoted[1]], 'quotePrefix="1"'), 'Excel: celula que comeca com = ganha quotePrefix (protecao contra formula)');
assert_same([true, 0], [str_contains($entries['xl/worksheets/_rels/sheet1.xml.rels'][2], 'Target="https://planilha.teste/1" TargetMode="External"'), substr_count($entries['xl/worksheets/_rels/sheet1.xml.rels'][2] . $entries['xl/worksheets/_rels/sheet2.xml.rels'][2], 'x.teste') + substr_count($entries['xl/worksheets/_rels/sheet1.xml.rels'][2] . $entries['xl/worksheets/_rels/sheet2.xml.rels'][2], 'descricao.teste')], 'Excel: links so dos campos de link http(s), nunca de outro texto (mesmo comecando com https)');
assert_same([true, true], [str_contains($sheet2, '<autoFilter ref="A1:'), str_contains($sheet2, '<v>15</v>')], 'Excel: Base com filtro automatico e PAs como numero');
assert_same(['A', 'Z', 'AA', 'AZ', 'BA'], [XlsxWriter::column(0), XlsxWriter::column(25), XlsxWriter::column(26), XlsxWriter::column(51), XlsxWriter::column(52)], 'Excel: nomes de coluna');
assert_same([true, true, true, true, false, false], [XlsxWriter::needsQuotePrefix('=1'), XlsxWriter::needsQuotePrefix('+1'), XlsxWriter::needsQuotePrefix('-1'), XlsxWriter::needsQuotePrefix('@x'), XlsxWriter::needsQuotePrefix('1-2'), XlsxWriter::needsQuotePrefix('')], 'Excel: o que ganha quotePrefix');
assert_same(true, str_starts_with(ChgExport::xlsx([]), 'PK'), 'Excel: lista vazia ainda gera o arquivo');

// ----------------------------------------------------------------------------
// 5. Endpoints, sessao, cadastro de funcionarios e flag (leitura do codigo)
// ----------------------------------------------------------------------------
$api = (string)file_get_contents($root . '/api/portal/chg.php');
$export = (string)file_get_contents($root . '/api/portal/chg_export.php');
assert_same([true, true], [strpos($api, "if (!chg_module_enabled()) {\n    json_response(['sucesso' => false, 'mensagem' => 'Recurso não encontrado.'], 404);") !== false, strpos($export, "if (!chg_module_enabled()) {\n    json_response(['sucesso' => false, 'mensagem' => 'Recurso não encontrado.'], 404);") !== false], 'endpoints: desligado = 404 (D-CHG-17)');
$guard = strpos($api, 'if (!$canManage) {');
assert_same(true, $guard !== false && strpos($api, "audit_log('CHG_NEGADO'") > $guard && strpos($api, 'ChgService::FORBIDDEN_MESSAGE], 403);') > $guard && $guard < strpos($api, "case 'create':") && strpos($api, '$data = portal_json_input();') < $guard, 'endpoint: POST com CSRF e JSON (portal_json_input) e 403 auditado antes de qualquer escrita');
assert_same(true, strpos($api, 'ChgAccess::canManage($role, ci_session_is_current() ? $employeeId : null, $store)') !== false, 'endpoint: Tools so pelo funcionario da sessao CI atual');
assert_same(true, strpos($export, 'require_get_method();') !== false && strpos($export, "audit_log('CHG_EXPORTADO', 'Exportacao Excel do Controle de CHG. Cards ' . count(\$cards), 'INFO');") !== false, 'exportar: GET autenticado, auditoria so com a contagem');
assert_same(true, strpos((string)file_get_contents($root . '/api/session.php'), "'modulos' => ['chg' => chg_module_enabled()],") !== false, 'sessao: o modulo ligado vai para a tela');
putenv('CHG_MODULE_ENABLED');
$flags = [chg_module_enabled()];
foreach (['true', '1', 'on', 'false', '0', 'talvez', ' '] as $value) {
    putenv('CHG_MODULE_ENABLED=' . $value);
    $flags[] = chg_module_enabled();
}
putenv('CHG_MODULE_ENABLED');
assert_same([false, true, true, true, false, false, false, false], $flags, 'flag: ausente, falsa, invalida ou vazia = desligado (D-CHG-17)');
$update = (string)file_get_contents($root . '/api/atualizar_funcionario.php');
assert_same(true, strpos($update, '$toolsDenied = EmployeeGroups::toolsDenied($grupo_tools, $access_role);') < strpos($update, '$pdo->commit();') && strpos($update, "} elseif (portal_role_value(\$access_role) === 'somente_leitura') {") !== false && strpos($update, "audit_log('FUNCIONARIO_GRUPO_ALTERADO', EmployeeGroups::auditDetails(") !== false, 'cadastro: Tools na mesma transacao, recusado ao somente leitura, tirado ao virar somente leitura, auditado');
assert_same(true, strpos((string)file_get_contents($root . '/api/adicionar_funcionario.php'), 'EmployeeGroups::toolsDenied($grupo_tools, $access_role) !== null') !== false && strpos((string)file_get_contents($root . '/api/listar_funcionarios.php'), "\$item['grupo_tools'] = isset(\$grupo_tools[(int)\$f->id]);") !== false, 'cadastro: Tools na criacao e na lista da gestao');
assert_same(['Grupo tools do funcionario ID 12: entrou por lideranca', null, EmployeeGroups::READ_ONLY_MESSAGE, true, false, null], [
    EmployeeGroups::auditDetails(12, 'entrou', 'lideranca'), EmployeeGroups::toolsDenied(true, 'tecnico'), EmployeeGroups::toolsDenied(true, 'somente_leitura'),
    EmployeeGroups::flag(true), EmployeeGroups::flag(0), EmployeeGroups::flag('sim'),
], 'grupo Tools: auditoria so com IDs, somente leitura recusado, valor so booleano');

// ----------------------------------------------------------------------------
// 6. Importacao do Controle v5 (ChgImport): previa so com contagens, idempotente
//    pelo id legado, updatedAt mais novo vence, norm() do v5.2, tecnico por nome
// ----------------------------------------------------------------------------
require_once $root . '/services/ChgImport.php';

$importNow = new DateTimeImmutable('2026-11-17 12:00:00');
$ms = static fn (string $local): int => (int)(new DateTimeImmutable($local, $importNow->getTimezone()))->format('U') * 1000 + 250;
$asLocal = static fn (int $millis): string => (new DateTimeImmutable('@' . intdiv($millis, 1000)))->setTimezone($importNow->getTimezone())->format('Y-m-d H:i:s') . '.250';
$itemA = [
    'id' => 'a1test', 'solicitante' => 'Solicitante Teste 01', 'chamado' => 'campo que o v5.2 nao usa',
    'carteiras' => [['codigo' => '9001', 'nome' => 'Carteira Teste 01', 'semCodigo' => false]], 'locais' => ['Local Teste 01', 'local teste 01'], 'qtdPas' => '12',
    'tipo' => 'Padrão', 'versao' => 'Versao Teste 01', 'link' => 'www.planilha.teste/1', 'sugestao' => '2026-11-18T20:00', 'status' => 'Aprovado',
    'inicio' => '2026-11-18T20:00', 'fim' => '2026-11-19T20:00', 'chg' => 'chg0000001', 'chgStatus' => 'Aprovada', 'tecnico' => 'técnico  07',
    'planoImplantacao' => 'ctask1', 'planoBackup' => 'N/A', 'planoRollback' => 'ctask2', 'taskImplStatus' => 'Fechada', 'taskBkpStatus' => '', 'taskRollStatus' => '',
    'planosExtras' => [['planoImplantacao' => 'ctask3', 'taskImplStatus' => 'Aberta']],
    'endpoints' => [['url' => 'https://endpoint.teste/1', 'hora' => '2026-11-18T21:00']],
    'historico' => [['tentativa' => 1, 'endpoints' => [['url' => 'endpoint.teste/0', 'hora' => '20:30'], ['url' => '', 'hora' => '01:30']], 'patchStatus' => 'Erro', 'registradoEm' => $ms('2026-11-18 20:45:00'), 'reagendandoPatch' => false]],
    'patchStatus' => 'Sucesso', 'reagendandoPatch' => true,
    'rollback' => ['ativo' => true, 'url' => 'https://endpoint.teste/rb', 'hora' => '2026-11-18T22:00', 'iniciadoEm' => '2026-11-18T22:00'],
    'stageManual' => 'triagem', 'stageManualAuto' => 'prep', 'concluida' => false,
    'createdAt' => $ms('2026-11-10 09:00:00'), 'updatedAt' => $ms('2026-11-12 10:00:00'),
];
$itemB = [
    'id' => 'b2test', 'analista' => 'Solicitante Teste 02', 'carteira' => '9002 - Carteira Teste 02', 'local' => 'Local Teste 02', 'tecnico' => 'Tecnico Repetido',
    'chgStatus' => 'Aprovada', 'link' => 'www.planilha.teste/arquivo.xlsx',
    'inicio' => '2026-11-18T20:00', 'fim' => '2026-11-18T19:00', 'sugestao' => '2026-11-18T20:00', 'concluida' => true, 'concluidaEm' => $ms('2026-11-15 10:00:00'),
    'createdAt' => $ms('2026-11-10 09:00:00'), 'updatedAt' => $ms('2026-11-15 10:00:00'),
];
$dados = ['app' => 'controle_atualizações', 'versao' => 2, 'items' => [
    $itemA, $itemB, ['solicitante' => 'Solicitante Teste 09'] + $itemA,
    ['id' => 'c3test', 'solicitante' => 'Solicitante Teste 03', 'createdAt' => 1, 'updatedAt' => 1],
    ['id' => 'id invalido!', 'solicitante' => 'x'],
    ['id' => 'e5test', 'solicitante' => 'Solicitante Teste 05', 'versao' => str_repeat('v', 501)],
], 'deleted' => ['c3test', 'zzantigo']];
$importStore = new QaChgStore();
$importStore->employees += [20 => 'Tecnico Repetido', 21 => 'tecnico repetido'];
$importer = new ChgImport($importStore, $importNow);
$pick = static function (array $counts, array $keys): array {
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = $counts[$key] ?? 0;
    }
    return $out;
};
$preview = $importer->run($dados, null, false, 'qa.admin');
assert_same(
    ['novos' => 2, 'atualizados' => 0, 'iguais' => 0, 'ignorados_em_deleted' => 1, 'invalidos' => 2, 'repetidos_no_arquivo' => 1, 'tecnicos_sem_correspondencia' => 1, 'links_completados' => 3, 'fim_antes_do_inicio_limpo' => 1],
    $pick($preview['contagens'], ['novos', 'atualizados', 'iguais', 'ignorados_em_deleted', 'invalidos', 'repetidos_no_arquivo', 'tecnicos_sem_correspondencia', 'links_completados', 'fim_antes_do_inicio_limpo']),
    'importar, previa: novos, em deleted, invalidos, repetido no arquivo, tecnico sem correspondencia, links completados e o fim antes do inicio, so contagens'
);
assert_same([['chamado' => 1], 0], [$preview['campos_descartados'], count($importStore->rows)], 'importar, previa: o campo que o v5.2 nao usa e contado (D-CHG-24) e nada e gravado');
assert_same(0, preg_match('/Solicitante Teste|Carteira Teste|Local Teste|planilha\.teste|endpoint\.teste|CHG0000|ctask|Versao Teste|Tecnico Repetido|tecnico  07/i', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'importar, previa: nenhum valor de card na resposta');
$importer->run($dados, null, true, 'qa.admin');
$byLegacy = [];
foreach ($importStore->rows as $row) {
    $byLegacy[$row['legacy_id']] = ChgRules::card($row);
}
$a = $byLegacy['a1test'];
assert_same(
    ['Solicitante Teste 01', 7, null, ['Local Teste 01'], 'https://www.planilha.teste/1', 12, 'CHG0000001', 'aprovada', 'aprovado', 'padrao'],
    [$a['solicitante'], $a['tecnico_id'], $a['tecnico_nome_legado'], $a['locais'], $a['link_planilha'], $a['carteiras'][0]['pas'], $a['chg_numero'], $a['chg_status'], $a['service_status'], $a['tipo']],
    'importar: tecnico casado por nome sem acento e sem caixa, locais sem repetir, link www completado, PAs da carteira unica, numero em maiusculas'
);
assert_same(
    [['CTASK1', 'N/A', 'CTASK2', 'fechada', 'pendente', 'pendente'], ['CTASK3', 'pendente']],
    [array_values($a['plans'][0]), [$a['plans'][1]['implantacao'], $a['plans'][1]['task_impl']]],
    'importar: Plano 1 dos campos originais e os planos extras (task so Fechada ou pendente, norm())'
);
assert_same(
    [2, 'erro', '2026-11-18 20:30:00', '2026-11-19 01:30:00', 'https://endpoint.teste/0', $asLocal($ms('2026-11-18 20:45:00')), 'sucesso', true, null, '2026-11-18 21:00:00'],
    [count($a['attempts']), $a['attempts'][0]['patch_status'], $a['attempts'][0]['endpoints'][0]['start_at'], $a['attempts'][0]['endpoints'][1]['start_at'], $a['attempts'][0]['endpoints'][0]['url'], $a['attempts'][0]['registered_at'], $a['attempts'][1]['patch_status'], $a['attempts'][1]['reagendando'], $a['attempts'][1]['registered_at'], $a['attempts'][1]['endpoints'][0]['start_at']],
    'importar: historico de tentativas (hora so com HH:MM relativa ao inicio, antes dele no dia seguinte, como o patchDT) e a tentativa atual'
);
assert_same(['em_andamento', '2026-11-18 22:00:00', 0, 'comite', 'prep'], [$a['rollback']['status'], $a['rollback']['start_at'], $a['rollback']['plan_position'], $a['stage_manual'], $a['stage_manual_auto']], 'importar: rollback ativo sem status = Em andamento; triagem = comite (norm())');
assert_same([$asLocal($ms('2026-11-10 09:00:00')), $asLocal($ms('2026-11-12 10:00:00'))], [$a['created_at'], $a['updated_at']], 'importar: createdAt e updatedAt preservados (milissegundos)');
$b = $byLegacy['b2test'];
assert_same(
    ['Solicitante Teste 02', null, 'Tecnico Repetido', [['codigo' => '9002', 'nome' => 'Carteira Teste 02', 'pas' => null]], ['Local Teste 02'], null, true, $asLocal($ms('2026-11-15 10:00:00')), null, 'https://www.planilha.teste/arquivo.xlsx'],
    [$b['solicitante'], $b['tecnico_id'], $b['tecnico_nome_legado'], $b['carteiras'], $b['locais'], $b['sd_fim'], $b['concluida'], $b['concluida_em'], $b['chg_status'], $b['link_planilha']],
    'importar: analista vira solicitante, carteira antiga em texto, nome repetido fica no historico (D-CHG-27), fim antes do inicio limpo, concluidaEm preservado, status sem numero limpo, www completado (mesmo com extensao)'
);
assert_same(2, count($importStore->rows), 'importar: dois cards gravados (deleted, invalidos e repetidos fora)');
$again = $importer->run($dados, null, true, 'qa.admin');
assert_same([0, 0, 2, 2], [$again['contagens']['novos'], $again['contagens']['atualizados'], $again['contagens']['iguais'], count($importStore->rows)], 'importar de novo: idempotente pelo id legado (updatedAt igual, nada muda)');
$newer = ['items' => [['updatedAt' => $ms('2026-11-16 10:00:00'), 'tecnico' => 'Tecnico 08'] + $itemA, ['updatedAt' => $ms('2026-11-14 10:00:00')] + $itemB], 'deleted' => ['a1test']];
$mixed = $importer->run($newer, null, false, 'qa.admin');
assert_same([0, 0, 1, 1], [$mixed['contagens']['novos'], $mixed['contagens']['atualizados'], $mixed['contagens']['mais_antigos_que_o_portal'], $mixed['contagens']['em_deleted_e_no_portal'] ?? 0], 'importar: mais antigo que o portal fica de fora; em deleted e ja no portal e contado (e nao importado)');
$newer['deleted'] = [];
$updated = $importer->run($newer, null, true, 'qa.admin');
$a2 = ChgRules::card($importStore->rows[array_search('a1test', array_column($importStore->rows, 'legacy_id', 'id'), true)]);
assert_same([1, 8, $a['row_version'] + 1, 2], [$updated['contagens']['atualizados'], $a2['tecnico_id'], $a2['row_version'], count($importStore->rows)], 'importar: updatedAt mais novo vence (atualiza o card, versao + 1); card em deleted nunca e apagado (D-CHG-28)');
$catalogPreview = $importer->run(null, ['app' => 'catalogo', 'carteiras' => [['codigo' => '9001', 'nome' => 'Carteira Teste 01'], ['codigo' => '9001', 'nome' => 'Repetida'], ['codigo' => 'A1', 'nome' => 'Invalida']], 'locais' => ['Local Teste 01', 'LOCAL TESTE 01', '']], true, 'qa.admin');
assert_same(['carteiras_novas' => 1, 'carteiras_existentes' => 1, 'carteiras_invalidas' => 1, 'locais_novos' => 1, 'locais_existentes' => 1, 'locais_invalidos' => 1], $pick($catalogPreview['contagens'], ['carteiras_novas', 'carteiras_existentes', 'carteiras_invalidas', 'locais_novos', 'locais_existentes', 'locais_invalidos']), 'importar o catalogo: carteira por codigo, local sem diferenca de caixa, invalidos contados');
$expectError(static fn () => $importer->run(['items' => 'x'], null, false, 'qa.admin'), 'InvalidArgumentException', 'Dados inválidos: esperado o JSON v2 do Controle de CHG ({"items": [...], "deleted": [...]}).', 'importar: arquivo fora do formato v2');
$expectError(static fn () => $importer->run(['items' => array_fill(0, 2001, [])], null, false, 'qa.admin'), 'InvalidArgumentException', 'Arquivo com mais de 2000 cards.', 'importar: limite de cards');
$expectError(static fn () => $importer->run(null, ['carteiras' => 'x'], false, 'qa.admin'), 'InvalidArgumentException', 'Catálogo inválido: esperado {"carteiras": [...], "locais": [...]}.', 'importar: catalogo fora do formato');
$expectError(static fn () => $importer->run(null, null, false, 'qa.admin'), 'InvalidArgumentException', 'Envie o JSON de dados, o do catálogo ou os dois.', 'importar: sem arquivo');
assert_same(['tecnico 07', 'joao da silva'], [ChgImport::fold('Técnico  07 '), ChgImport::fold('JOÃO DA SILVA')], 'importar: nome sem acento, sem caixa e sem espacos repetidos');
$importApi = (string)file_get_contents($root . '/api/portal/chg_import.php');
$guardAt = strpos($importApi, 'if (!ChgAccess::canImport($role)) {');
assert_same(true, $guardAt !== false && $guardAt < strpos($importApi, '$data = portal_json_input(ChgImport::MAX_BYTES);') && strpos($importApi, "audit_log('CHG_NEGADO'") > $guardAt && strpos($importApi, "if (!chg_module_enabled()) {") < $guardAt, 'importar, endpoint: so o admin (403 auditado antes de ler o corpo), CSRF, limite de 2 MB e desligado = 404');
assert_same(1, preg_match("/audit_log\\('CHG_IMPORTADO', 'Importacao do Controle v5: novos ' \\. \\\$c\\['novos'\\]/", $importApi), 'importar, endpoint: auditoria so com as contagens');

echo 'QA do Controle de CHG OK (' . $GLOBALS['qa_checks'] . " conferencias)\n";
