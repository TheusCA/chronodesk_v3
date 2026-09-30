<?php
declare(strict_types=1);

// ============================================================================
// QA de horas extras e exportacoes (Lote 6 — BIZ-01, BIZ-04, PERF-02)
//
// Sem banco: testa as funcoes que decidem os numeros (minutos, totais, linha do
// CSV, competencia) e a paginacao das exportacoes sobre dados em memoria. As
// consultas SQL de paginacao nao rodam aqui: sao conferidas por estrutura e
// precisam do banco real para prova de ponta a ponta.
//
// Uso: php scripts/qa-overtime.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-overtime-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
define('CHRONODESK_STATELESS', true);
require_once __DIR__ . '/../services/OperationalService.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_rejects(callable $callback, string $expectedText, string $message): void {
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        assert_same(true, strpos($e->getMessage(), $expectedText) !== false, $message . ' (mensagem)');
        return;
    }
    fwrite(STDERR, "FAIL: {$message}: nenhuma excecao\n");
    exit(1);
}

// ----------------------------------------------------------------------------
// BIZ-04: minutos entre entrada e saida
// ----------------------------------------------------------------------------
assert_same(60, OperationalService::overtimeMinutes('18:00:00', '19:00:00'), 'periodo simples');
assert_same(60, OperationalService::overtimeMinutes('18:00', '19:00'), 'aceita HH:MM');
assert_same(240, OperationalService::overtimeMinutes('22:00:00', '02:00:00'), 'virada de dia: saida menor que a entrada');
assert_same(1, OperationalService::overtimeMinutes('23:59:00', '00:00:00'), 'virada de dia de um minuto');
assert_same(1439, OperationalService::overtimeMinutes('00:00:00', '23:59:00'), 'maior periodo possivel sem teto');
assert_same(1439, OperationalService::overtimeMinutes('00:01:00', '00:00:00'), 'quase 24 h com virada de dia');
assert_same(90, OperationalService::overtimeMinutes('17:30:30', '19:00:59'), 'segundos truncam para minutos');

assert_rejects(static fn() => OperationalService::overtimeMinutes('08:00:00', '08:00:00'), 'igual a hora de saida', 'entrada igual a saida e recusada');
assert_rejects(static fn() => OperationalService::overtimeMinutes('08:00', '08:00:00'), 'igual a hora de saida', 'igualdade reconhecida com e sem segundos');
assert_rejects(static fn() => OperationalService::overtimeMinutes('00:00:00', '00:00:00'), 'meia-noite', 'mensagem orienta a virada de dia');
assert_rejects(static fn() => OperationalService::overtimeMinutes('18:00:00', '18:00:30'), 'invalido', 'menos de um minuto e recusado');
assert_rejects(static fn() => OperationalService::overtimeMinutes('24:00', '01:00'), 'invalido', 'horario fora do formato');

// O preview da tela espelha a regra.
$operationalPage = (string)file_get_contents(__DIR__ . '/../frontend/src/pages/OperationalPages.jsx');
assert_same(true, strpos($operationalPage, 'if (end === start) return { minutes: 0, overnight: false, sameTime: true }') !== false, 'preview recusa entrada igual a saida');
assert_same(true, strpos($operationalPage, 'const overnight = end < start') !== false, 'preview trata virada de dia so com saida menor');
assert_same(true, strpos($operationalPage, 'if (overtime && preview.sameTime)') !== false, 'envio bloqueado com entrada igual a saida');

// ----------------------------------------------------------------------------
// BIZ-01: totais aprovado e pendente; rejeitado fora de tudo
// ----------------------------------------------------------------------------
$rows = [
    ['employee_id' => 1, 'status' => 'approved', 'total_minutes' => 120],
    ['employee_id' => 1, 'status' => 'approved', 'total_minutes' => 30],
    ['employee_id' => 1, 'status' => 'pending', 'total_minutes' => 60],
    ['employee_id' => 1, 'status' => 'rejected', 'total_minutes' => 1440],
    ['employee_id' => 2, 'status' => 'pending', 'total_minutes' => 45],
    ['employee_id' => 2, 'status' => 'rejected', 'total_minutes' => 90],
    ['employee_id' => 3, 'status' => 'rejected', 'total_minutes' => 600],
    ['employee_id' => 4, 'status' => 'synced', 'total_minutes' => 15],
];
$totals = OperationalService::overtimeTotals($rows);
assert_same(150, $totals['approved_minutes'], 'total aprovado soma so aprovados');
assert_same(105, $totals['pending_minutes'], 'total pendente soma so pendentes');
assert_same(['approved_minutes' => 150, 'pending_minutes' => 60], $totals['by_employee'][1], 'colaborador 1: rejeitado de 24 h nao entra');
assert_same(['approved_minutes' => 0, 'pending_minutes' => 45], $totals['by_employee'][2], 'colaborador 2: so pendente');
assert_same(['approved_minutes' => 0, 'pending_minutes' => 0], $totals['by_employee'][3], 'colaborador so com rejeitado tem totais zerados');
assert_same(['approved_minutes' => 0, 'pending_minutes' => 0], $totals['by_employee'][4], 'status sem gravador fica fora dos totais');
assert_same(['approved_minutes' => 0, 'pending_minutes' => 0, 'by_employee' => []], OperationalService::overtimeTotals([]), 'sem linhas, totais zerados');

// Totais aceitam um gerador: o CSV percorre o filtro em paginas.
$generator = (static function () use ($rows): Generator {
    yield from $rows;
})();
assert_same($totals, OperationalService::overtimeTotals($generator), 'totais iguais a partir de gerador');

// Linha do CSV: colunas e totais do colaborador no filtro inteiro.
assert_same(
    ['NC', 'Nome completo', 'Data da realizacao', 'Hora de entrada', 'Hora de saida', 'Descricao', 'Total de Horas', 'Total aprovado', 'Total pendente', 'Status'],
    OperationalService::OVERTIME_CSV_HEADER,
    'cabecalho do CSV: sete colunas originais, dois totais e status'
);
$csvRow = OperationalService::overtimeCsvRow([
    'employee_id' => 1, 'employee_name' => 'Colaborador QA', 'work_date' => '2026-10-05',
    'start_time' => '22:00:00', 'end_time' => '02:00:00', 'reason' => 'Janela de mudanca',
    'justification' => 'x', 'total_minutes' => 240, 'status' => 'rejected',
], $totals['by_employee']);
assert_same(['', 'Colaborador QA', '2026-10-05', '22:00', '02:00', 'Janela de mudanca', '04:00', '02:30', '01:00', 'Rejeitado'], $csvRow, 'linha rejeitada mostra status e nao altera os totais');
$csvRow = OperationalService::overtimeCsvRow([
    'employee_id' => 9, 'employee_name' => 'Sem totais', 'work_date' => '2026-10-05',
    'start_time' => '18:00:00', 'end_time' => '19:00:00', 'reason' => '', 'justification' => 'Justificativa',
    'total_minutes' => 60, 'status' => 'approved',
], []);
assert_same(['', 'Sem totais', '2026-10-05', '18:00', '19:00', 'Justificativa', '01:00', '00:00', '00:00', 'Aprovado'], $csvRow, 'colaborador sem totais sai zerado; descricao cai para a justificativa');

// ----------------------------------------------------------------------------
// Competencia: 16 de um mes a 15 do seguinte
// ----------------------------------------------------------------------------
foreach ([
    '2026-10-05' => ['2026-09-16', '2026-10-15', '2026-10'],
    '2026-10-15' => ['2026-09-16', '2026-10-15', '2026-10'],
    '2026-10-16' => ['2026-10-16', '2026-11-15', '2026-11'],
    '2026-12-20' => ['2026-12-16', '2027-01-15', '2027-01'],
    '2026-01-10' => ['2025-12-16', '2026-01-15', '2026-01'],
    '2026-02-28' => ['2026-02-16', '2026-03-15', '2026-03'],
] as $reference => [$start, $end, $key]) {
    $competency = OperationalService::competencyRange($reference);
    assert_same([$start, $end, $key], [$competency['start'], $competency['end'], $competency['key']], "competencia de {$reference}");
}

// Sem periodo informado, lista e CSV de horas extras usam a competencia corrente.
$service = (new ReflectionClass(OperationalService::class))->newInstanceWithoutConstructor();
$period = new ReflectionMethod(OperationalService::class, 'period');
$period->setAccessible(true);
$current = OperationalService::competencyRange();
assert_same([$current['start'], $current['end']], $period->invoke($service, [], 366, true), 'padrao da lista de horas extras e a competencia');
assert_same(['2026-09-16', '2026-10-15'], $period->invoke($service, ['competency' => '2026-10'], 366, true), 'competencia informada vale');
assert_same(['2026-10-01', '2026-10-10'], $period->invoke($service, ['from' => '2026-10-01', 'to' => '2026-10-10'], 366, true), 'periodo informado vale');
assert_same([date('Y-m-01'), date('Y-m-t')], $period->invoke($service, [], 62), 'demais telas mantem o mes calendario');

$serviceSource = (string)file_get_contents(__DIR__ . '/../services/OperationalService.php');
assert_same(true, strpos($serviceSource, '[$from, $to] = $this->period($filters, 366, true);') !== false, 'filtro de horas extras usa a competencia por padrao');

// ----------------------------------------------------------------------------
// PERF-02: lista com aviso de corte
// ----------------------------------------------------------------------------
$many = array_map(static fn(int $id): array => ['id' => $id], range(1, 501));
$limited = db_limit_rows($many, 500);
assert_same(500, count($limited['items']), 'lista corta no limite');
assert_same(true, $limited['truncated'], 'lista avisa que cortou');
assert_same(500, $limited['limit'], 'lista informa o limite');
$exact = db_limit_rows(array_slice($many, 0, 500), 500);
assert_same(false, $exact['truncated'], 'exatamente no limite nao e corte');
assert_same(500, count($exact['items']), 'exatamente no limite mostra tudo');

// ----------------------------------------------------------------------------
// PERF-02: exportacao percorre tudo, em paginas por chave (data, id)
// ----------------------------------------------------------------------------
/**
 * Tabela em memoria na ordem da exportacao (data DESC, id DESC), com muitas
 * linhas na mesma data para exercitar o desempate por id.
 */
function qa_table(int $count): array {
    $rows = [];
    for ($id = 1; $id <= $count; $id++) {
        $rows[] = ['id' => $id, 'work_date' => sprintf('2026-10-%02d', 1 + intdiv($id, 97) % 28)];
    }
    usort($rows, static fn(array $a, array $b): int => [$b['work_date'], $b['id']] <=> [$a['work_date'], $a['id']]);
    return $rows;
}

/**
 * Pagina com a mesma condicao do SQL: (data < cursor) OU (data = cursor E id < cursor_id).
 */
function qa_page(array $table, ?array $last, int $chunk, int &$queries): array {
    $queries++;
    $rows = $last === null ? $table : array_values(array_filter(
        $table,
        static fn(array $row): bool => $row['work_date'] < $last['work_date']
            || ($row['work_date'] === $last['work_date'] && $row['id'] < $last['id'])
    ));
    return array_slice($rows, 0, $chunk);
}

foreach ([0 => 1, 1 => 1, 499 => 1, 500 => 2, 501 => 2, 1000 => 3, 1203 => 3] as $count => $expectedQueries) {
    $table = qa_table($count);
    $queries = 0;
    $seen = iterator_to_array(db_keyset_iterate(
        static function (?array $last, int $chunk) use ($table, &$queries): array {
            return qa_page($table, $last, $chunk, $queries);
        },
        500
    ), false);
    assert_same($table, $seen, "exportacao de {$count} linhas: todas, uma vez, na ordem");
    assert_same($expectedQueries, $queries, "exportacao de {$count} linhas: paginas lidas");
}

$queries = 0;
$table = qa_table(7);
assert_same(7, count(iterator_to_array(db_keyset_iterate(
    static function (?array $last, int $chunk) use ($table, &$queries): array {
        return qa_page($table, $last, $chunk, $queries);
    },
    0
), false)), 'pagina invalida vira 1 e ainda termina');

// Estrutural: nenhuma exportacao volta a usar a lista limitada.
$overtimeCsv = substr($serviceSource, (int)strpos($serviceSource, 'public function streamOvertimeCsv'), 900);
$adjustmentCsv = substr($serviceSource, (int)strpos($serviceSource, 'public function streamTimeAdjustmentsCsv'), 400);
assert_same(true, strpos($overtimeCsv, 'iterateWorkflowRecords') !== false, 'CSV de horas extras percorre o filtro inteiro');
assert_same(false, strpos($overtimeCsv, 'listOvertime') !== false, 'CSV de horas extras nao usa a lista da tela');
assert_same(true, strpos($adjustmentCsv, 'iterateWorkflowRecords') !== false, 'CSV de correcao de ponto percorre o filtro inteiro');
assert_same(false, strpos($adjustmentCsv, 'listTimeAdjustments') !== false, 'CSV de correcao de ponto nao usa a lista da tela');
$criticalSource = (string)file_get_contents(__DIR__ . '/../services/CriticalIncidentService.php');
$criticalExport = substr($criticalSource, (int)strpos($criticalSource, 'public function exportRows'), 1400);
assert_same(true, strpos($criticalExport, 'db_keyset_iterate') !== false, 'CSV de chamados criticos percorre o filtro inteiro');
assert_same(false, strpos($criticalExport, 'LIMIT 500') !== false, 'CSV de chamados criticos sem limite fixo');
$reportSource = (string)file_get_contents(__DIR__ . '/../api/download_relatorio.php');
assert_same(true, strpos($reportSource, 'obter_metricas(true, $gravar_pausa_detalhada)') !== false, 'relatorio de pausas exporta todas as pausas');
assert_same(false, strpos($reportSource, "\$metricas['pausas_detalhadas']") !== false, 'relatorio de pausas nao usa a amostra da tela');

echo "QA overtime OK\n";
