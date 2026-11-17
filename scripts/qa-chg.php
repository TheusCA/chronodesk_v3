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
//    os rotulos prontos (selo, aviso, barra de SLA).
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

echo 'QA do Controle de CHG OK (' . $GLOBALS['qa_checks'] . " conferencias)\n";
