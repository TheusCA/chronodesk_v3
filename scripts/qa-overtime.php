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

// Totais aceitam um gerador: os totais do filtro percorrem as linhas em paginas.
$generator = (static function () use ($rows): Generator {
    yield from $rows;
})();
assert_same($totals, OperationalService::overtimeTotals($generator), 'totais iguais a partir de gerador');

// Status sem gravador aparece pelo nome, nunca em branco (o rotulo segue no
// CSV de correcao de ponto; o CSV de horas extras saiu no lote NC revisto).
$statusLabel = new ReflectionMethod(OperationalService::class, 'workflowStatusLabel');
foreach (['pending' => 'Pendente', 'approved' => 'Aprovado', 'rejected' => 'Rejeitado', 'synced' => 'Sincronizado', 'sync_error' => 'Erro de sincronizacao', '' => 'Sem status'] as $status => $label) {
    assert_same($label, $statusLabel->invoke(null, $status), "rotulo do status \"{$status}\" no CSV");
}

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

// ----------------------------------------------------------------------------
// BIZ-04: aprovacao de pendente antigo com entrada igual a saida (24 h)
// ----------------------------------------------------------------------------
// PDO simulado: devolve o registro travado e registra as escritas.
final class QaDecisionPdo extends PDO {
    public array $log = [];
    public bool $transaction = false;
    /** Login AD => id do funcionario ativo (actorEmployeeId de quem nao e tecnico). */
    public array $employeesByLogin = [];

    public function __construct(public array $record) {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaDecisionStatement($this, preg_replace('/\s+/', ' ', trim($query)));
    }

    public function beginTransaction(): bool {
        $this->transaction = true;
        $this->log[] = 'BEGIN';
        return true;
    }

    public function commit(): bool {
        $this->transaction = false;
        $this->log[] = 'COMMIT';
        return true;
    }

    public function rollBack(): bool {
        $this->transaction = false;
        $this->log[] = 'ROLLBACK';
        return true;
    }

    public function inTransaction(): bool {
        return $this->transaction;
    }
}

final class QaDecisionStatement extends PDOStatement {
    private array $result = [];

    public function __construct(private QaDecisionPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        if (strpos($this->sql, 'FOR UPDATE') !== false) {
            $this->pdo->log[] = 'LOCK';
            $this->result = [$this->pdo->record];
        } elseif (strpos($this->sql, 'UPDATE portal_') === 0) {
            $this->pdo->log[] = 'UPDATE ' . $params[':status'];
        } elseif (strpos($this->sql, 'INSERT INTO portal_sync_queue') === 0) {
            $this->pdo->log[] = 'SYNC';
        } elseif ($this->sql === 'SELECT id FROM funcionarios WHERE ativo = 1 AND LOWER(ad_login) = :ad_login LIMIT 1') {
            $id = $this->pdo->employeesByLogin[$params[':ad_login']] ?? null;
            $this->result = $id === null ? [] : [['id' => $id]];
        } else {
            throw new LogicException('SQL inesperado: ' . $this->sql);
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->result) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->result);
        return $row === null ? false : array_values($row)[$column];
    }
}

function qa_decide(string $method, array $record, string $decision, ?array $actor = null, array $employeesByLogin = []): array {
    $pdo = new QaDecisionPdo($record);
    $pdo->employeesByLogin = $employeesByLogin;
    $service = new OperationalService($pdo);
    $actor = $actor ?? ['username' => 'qa.gestor', 'role' => 'gestor', 'employee_id' => 99];
    try {
        $service->{$method}(1, $decision, $actor);
        return ['ok', $pdo->log];
    } catch (DomainException $e) {
        return [$e->getMessage(), $pdo->log];
    }
}

$overtimeRecord = static fn (string $start, string $end, string $status = 'pending', int $minutes = 1440): array => [
    'id' => 1, 'employee_id' => 7, 'employee_name' => 'QA', 'team' => 'n1', 'work_date' => '2026-09-10',
    'start_time' => $start, 'end_time' => $end, 'total_minutes' => $minutes, 'reason' => 'r', 'justification' => 'j',
    'status' => $status, 'approved_by' => null, 'approved_at' => null, 'created_by' => 'qa', 'updated_by' => 'qa',
    'created_at' => '2026-09-10 10:00:00', 'updated_at' => '2026-09-10 10:00:00',
];
$dayLong = $overtimeRecord('10:00:00', '10:00:00');

[$message, $log] = qa_decide('decideOvertime', $dayLong, 'approved');
assert_same(true, strpos($message, 'hora de entrada igual a hora de saida') !== false, 'aprovar pendente de 24 h e recusado');
assert_same(true, strpos($message, 'rejeite e peca ao colaborador para lancar de novo') !== false, 'mensagem orienta a rejeitar e lancar de novo');
assert_same(['BEGIN', 'LOCK', 'ROLLBACK'], $log, 'recusa da aprovacao nao grava nada nem enfileira sincronizacao');
assert_same(['ok', ['BEGIN', 'LOCK', 'UPDATE rejected', 'SYNC', 'COMMIT']], qa_decide('decideOvertime', $dayLong, 'rejected'), 'rejeitar pendente de 24 h continua permitido');
assert_same(true, strpos(qa_decide('decideOvertime', $overtimeRecord('22:15', '22:15:00'), 'approved')[0], 'hora de entrada igual') !== false, 'HH:MM e HH:MM:SS iguais tambem sao recusados');
assert_same(true, strpos(qa_decide('decideOvertime', $overtimeRecord('08:00:00', '08:00:00', 'pending', 0), 'approved')[0], 'hora de entrada igual') !== false, 'regra pelo horario, nao pelo total gravado');
assert_same(['ok', ['BEGIN', 'LOCK', 'UPDATE approved', 'SYNC', 'COMMIT']], qa_decide('decideOvertime', $overtimeRecord('22:00:00', '02:00:00', 'pending', 240), 'approved'), 'controle: virada de dia continua aprovavel');
assert_same(['ok', ['BEGIN', 'LOCK', 'UPDATE approved', 'SYNC', 'COMMIT']], qa_decide('decideOvertime', $overtimeRecord('10:00:00', '10:01:00', 'pending', 1), 'approved'), 'controle: um minuto continua aprovavel');
assert_same(
    ['Somente registros pendentes podem ser decididos.', ['BEGIN', 'LOCK', 'ROLLBACK']],
    qa_decide('decideOvertime', $overtimeRecord('10:00:00', '10:00:00', 'approved'), 'approved'),
    'lancamento de 24 h ja aprovado nao muda'
);
$adjustment = [
    'id' => 1, 'employee_id' => 7, 'employee_name' => 'QA', 'team' => 'n1', 'adjustment_date' => '2026-09-10',
    'adjustment_type' => 'entrada', 'correct_time' => '08:00:00', 'recorded_time' => '08:00:00', 'justification' => 'j',
    'status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'created_by' => 'qa', 'updated_by' => 'qa',
    'created_at' => '2026-09-10 10:00:00', 'updated_at' => '2026-09-10 10:00:00',
];
assert_same(['ok', ['BEGIN', 'LOCK', 'UPDATE approved', 'SYNC', 'COMMIT']], qa_decide('decideTimeAdjustment', $adjustment, 'approved'), 'correcao de ponto nao e afetada pela regra de horas extras');

// Lote 5b (QA 12): a Lideranca aprova, mas nao o proprio lancamento. Sem
// employee_id na sessao, o colaborador vem do login AD (actorEmployeeId).
$leader = ['username' => 'qa.lider', 'role' => 'lideranca', 'employee_id' => null];
$ownLeaderRecord = $overtimeRecord('18:00:00', '20:00:00', 'pending', 120);
assert_same(
    ['Nao e permitido aprovar o proprio lancamento.', ['BEGIN', 'LOCK', 'ROLLBACK']],
    qa_decide('decideOvertime', $ownLeaderRecord, 'approved', $leader, ['qa.lider' => 7]),
    'Lideranca nao aprova o proprio lancamento'
);
assert_same(
    ['ok', ['BEGIN', 'LOCK', 'UPDATE approved', 'SYNC', 'COMMIT']],
    qa_decide('decideOvertime', $ownLeaderRecord, 'approved', $leader, ['qa.lider' => 8]),
    'controle: Lideranca aprova lancamento de outro colaborador'
);

// Estrutural: nenhuma exportacao volta a usar a lista limitada.
$overtimeRhExport = substr($serviceSource, (int)strpos($serviceSource, 'private function overtimeRhSplit'), 900);
$adjustmentCsv = substr($serviceSource, (int)strpos($serviceSource, 'public function streamTimeAdjustmentsCsv'), 400);
assert_same([false, false, false], [strpos($serviceSource, 'streamOvertimeCsv') !== false, strpos($serviceSource, 'OVERTIME_CSV_HEADER') !== false, strpos($serviceSource, 'overtimeCsvRow') !== false], 'o CSV de horas extras saiu do servico (a exportacao e a planilha do RH)');
assert_same(true, strpos($overtimeRhExport, 'iterateWorkflowRecords') !== false, 'planilha do RH percorre o filtro inteiro');
assert_same(false, strpos($overtimeRhExport, 'listOvertime') !== false, 'planilha do RH nao usa a lista da tela');
assert_same(true, strpos($adjustmentCsv, 'iterateWorkflowRecords') !== false, 'CSV de correcao de ponto percorre o filtro inteiro');
assert_same(false, strpos($adjustmentCsv, 'listTimeAdjustments') !== false, 'CSV de correcao de ponto nao usa a lista da tela');
$criticalSource = (string)file_get_contents(__DIR__ . '/../services/CriticalIncidentService.php');
$criticalExport = substr($criticalSource, (int)strpos($criticalSource, 'public function exportRows'), 1400);
assert_same(true, strpos($criticalExport, 'db_keyset_iterate') !== false, 'CSV de chamados criticos percorre o filtro inteiro');
assert_same(false, strpos($criticalExport, 'LIMIT 500') !== false, 'CSV de chamados criticos sem limite fixo');
$reportSource = (string)file_get_contents(__DIR__ . '/../api/download_relatorio.php');
assert_same(true, strpos($reportSource, 'obter_metricas(true, $gravar_pausa_detalhada)') !== false, 'relatorio de pausas exporta todas as pausas');
assert_same(false, strpos($reportSource, "\$metricas['pausas_detalhadas']") !== false, 'relatorio de pausas nao usa a amostra da tela');

// ----------------------------------------------------------------------------
// [Lote NC revisto, D-NC-14 a D-NC-18] Planilha de horas extras no modelo do
// RH: o .xlsx gerado e aberto aqui (o ZIP do XlsxWriter e sem compressao) e
// conferido celula a celula. Nomes e NC ficticios.
// ----------------------------------------------------------------------------
require_once __DIR__ . '/../services/OvertimeRhSheet.php';

/** Entradas de um ZIP sem compressao (metodo 0), como o XlsxWriter grava. */
function qa_zip_entries(string $zip): array {
    $files = [];
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $head = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr($zip, $pos + 4, 26));
        assert_same(0, $head['method'], 'xlsx: entrada sem compressao');
        $name = substr($zip, $pos + 30, $head['name']);
        $files[$name] = substr($zip, $pos + 30 + $head['name'] + $head['extra'], $head['compressed']);
        $pos += 30 + $head['name'] + $head['extra'] + $head['compressed'];
    }
    return $files;
}

function qa_xml(string $xml): SimpleXMLElement {
    $node = simplexml_load_string($xml);
    $node->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    return $node;
}

$rhRows = [
    ['id' => 3, 'employee_id' => 12, 'employee_name' => 'bruno ficticio', 'work_date' => '2026-11-18', 'nc' => '900000012', 'total_minutes' => 90, 'reason' => '', 'justification' => 'Justificativa QA', 'status' => 'approved'],
    ['id' => 1, 'employee_id' => 11, 'employee_name' => 'Ana Lúcia Ficticia', 'work_date' => '2026-11-18', 'nc' => '900000011', 'total_minutes' => 60, 'reason' => 'Motivo QA', 'justification' => 'x', 'status' => 'approved'],
    ['id' => 2, 'employee_id' => 13, 'employee_name' => 'Caio Ficticio', 'work_date' => '2026-11-16', 'nc' => '913', 'total_minutes' => 1500, 'reason' => '=1+1', 'justification' => '', 'status' => 'approved'],
];
$files = qa_zip_entries(OvertimeRhSheet::build($rhRows, OvertimeRhSheet::DEFAULT_COST_CENTER));
$workbook = qa_xml($files['xl/workbook.xml']);
$sheet = qa_xml($files['xl/worksheets/sheet1.xml']);
$styles = qa_xml($files['xl/styles.xml']);
assert_same(['Plan1'], array_map(static fn ($s): string => (string)$s['name'], $workbook->xpath('//x:sheets/x:sheet')), 'planilha do RH: uma aba so, "Plan1"');
assert_same(1, count($workbook->xpath('//x:calcPr[@fullCalcOnLoad="1"]')), 'planilha do RH: o Excel calcula a formula do total ao abrir');
$cell = static function (string $ref) use ($sheet): ?SimpleXMLElement {
    $found = $sheet->xpath("//x:c[@r='{$ref}']");
    return $found[0] ?? null;
};
$text = static fn (string $ref): string => (string)($cell($ref)?->is->t ?? '');
$xfs = $styles->xpath('//x:cellXfs/x:xf');
$fmtCodes = [];
foreach ($styles->xpath('//x:numFmts/x:numFmt') as $fmt) {
    $fmtCodes[(int)$fmt['numFmtId']] = (string)$fmt['formatCode'];
}
assert_same([164, 165], array_keys($fmtCodes), 'planilha do RH: formatos proprios a partir do id 164 (abaixo e formato embutido do Excel, que ignora o codigo)');
$fonts = $styles->xpath('//x:fonts/x:font');
$fills = $styles->xpath('//x:fills/x:fill');
$borders = $styles->xpath('//x:borders/x:border');
$styleOf = static function (string $ref) use ($cell, $xfs, $fmtCodes, $fonts, $fills, $borders): array {
    $xf = $xfs[(int)($cell($ref)['s'] ?? 0)];
    $font = $fonts[(int)$xf['fontId']];
    $fill = $fills[(int)$xf['fillId']];
    $border = $borders[(int)$xf['borderId']];
    return [
        'formato' => $fmtCodes[(int)$xf['numFmtId']] ?? (string)$xf['numFmtId'],
        'fonte' => (string)$font->name['val'] . ' ' . (string)$font->sz['val'] . (isset($font->b) ? ' negrito' : ''),
        'fundo' => (string)($fill->patternFill->fgColor['rgb'] ?? ''),
        'borda' => (string)($border->left['style'] ?? '') . ' ' . (string)($border->left->color['rgb'] ?? ''),
        'centro' => (string)($xf->alignment['horizontal'] ?? ''),
    ];
};
assert_same(OvertimeRhSheet::HEADERS, array_map($text, ['A1', 'B1', 'C1', 'D1', 'E1', 'F1']), 'planilha do RH: os seis cabecalhos, nesta ordem, com acento');
assert_same(['CENTRO DE CUSTO', 'NC', 'Nome completo ', 'Data da realização', 'Total de Horas', 'Descrição'], OvertimeRhSheet::HEADERS, 'planilha do RH: o texto exato dos cabecalhos do modelo, com o espaco no fim de "Nome completo "');
assert_same(['Nome completo ', 'Nome completo ', 1], [$text('C1'), OvertimeRhSheet::HEADERS[2], substr_count($files['xl/worksheets/sheet1.xml'], '<c r="C1" s="1" t="inlineStr"><is><t xml:space="preserve">Nome completo </t></is></c>')], 'planilha do RH: C1 do .xlsx gerado com o espaco no fim, igual ao modelo, e o xml:space="preserve" que faz o Excel guardar o espaco');
assert_same(['Total de Horas Geral:', 'SUM(E2:E4)', '[h]:mm:ss', null], [$text('H1'), (string)$cell('I1')->f, $styleOf('I1')['formato'], $cell('G1')], 'planilha do RH: H1 com o rotulo, I1 com a soma de E2 ate a ultima linha em [h]:mm:ss, G1 vazia');
assert_same(['formato' => '0', 'fonte' => 'Aptos Narrow 11 negrito', 'fundo' => 'FF00B0F0', 'borda' => 'thin FF000000', 'centro' => 'center'], $styleOf('C1'), 'planilha do RH: cabecalho em negrito, Aptos Narrow 11, fundo 00B0F0, centralizado, borda fina preta');
// Ordem: data e depois nome (Caio em 16/11; Ana e Bruno em 18/11).
assert_same(['CAIO FICTICIO', 'ANA LÚCIA FICTICIA', 'BRUNO FICTICIO'], [$text('C2'), $text('C3'), $text('C4')], 'planilha do RH: uma linha por lancamento, por data e depois nome, o nome em maiusculas (com acento)');
assert_same(['1.123.01  Suporte Tecnico', '1.123.01  Suporte Tecnico'], [$text('A2'), $text('A4')], 'planilha do RH: centro de custo padrao com os dois espacos do modelo');
assert_same([null, '913', null, '46342', null, '1.0416666666667'], [isset($cell('B2')['t']) ? (string)$cell('B2')['t'] : null, (string)$cell('B2')->v, isset($cell('D2')['t']) ? (string)$cell('D2')['t'] : null, (string)$cell('D2')->v, isset($cell('E2')['t']) ? (string)$cell('E2')['t'] : null, substr((string)$cell('E2')->v, 0, 15)], 'planilha do RH: NC como numero, data como numero de data do Excel e o total como fracao do dia (25 h)');
assert_same(['dd/mm/yyyy', '[h]:mm:ss', 'Aptos Narrow 11', 'thin FF000000', 'center', 'center'], [$styleOf('D2')['formato'], $styleOf('E2')['formato'], $styleOf('B2')['fonte'], $styleOf('F2')['borda'], $styleOf('A2')['centro'], $styleOf('E3')['centro']], 'planilha do RH: data em dd/mm/yyyy, tempo em [h]:mm:ss, dados centralizados com borda fina, Aptos Narrow 11');
assert_same(['=1+1', 'Motivo QA', 'Justificativa QA'], [$text('F2'), $text('F3'), $text('F4')], 'planilha do RH: a descricao e o motivo ou, vazio, a justificativa (texto, nunca formula)');
assert_same([1, true], [count($xfs[(int)$cell('F2')['s']]->xpath('@quotePrefix')), (string)$cell('F2')['t'] === 'inlineStr'], 'planilha do RH: descricao que comeca com = fica texto (quotePrefix)');
assert_same('A1:F4', (string)$sheet->autoFilter['ref'], 'planilha do RH: autofiltro de A1 ate F da ultima linha');
$widths = [];
foreach ($sheet->xpath('//x:cols/x:col') as $col) {
    $widths[(int)$col['min']] = (float)$col['width'];
}
assert_same([1 => 22.4, 2 => 7.0, 3 => 45.1, 4 => 16.2, 5 => 13.1, 6 => 67.9, 8 => 18.7], $widths, 'planilha do RH: as larguras do modelo (A a F e H)');
assert_same(['Total H-E Suporte Tecnico BPM 11-2026.xlsx', 46342, 'ÁÉÍÓÚÂÊÔÃÕÇ ABC'], [OvertimeRhSheet::fileName('2026-11-15'), OvertimeRhSheet::excelDate('2026-11-16'), OvertimeRhSheet::upper('áéíóúâêôãõç abc')], 'planilha do RH: nome do arquivo pelo mes do fim do periodo, data do Excel e maiusculas sem mbstring');
$empty = qa_zip_entries(OvertimeRhSheet::build([], 'CC QA'));
assert_same(['SUM(E2:E2)', 'A1:F1'], [(string)qa_xml($empty['xl/worksheets/sheet1.xml'])->xpath("//x:c[@r='I1']")[0]->f, (string)qa_xml($empty['xl/worksheets/sheet1.xml'])->autoFilter['ref']], 'planilha do RH: sem aprovados, so o cabecalho e o total zerado');
// O que nao usa os recursos novos (Controle de CHG) sai igual, byte a byte.
$chgLike = new XlsxWriter();
$chgLike->addSheet('Aba', [[['v' => 'Titulo', 's' => ['bold' => true, 'fill' => 'DCE6F1', 'border' => true, 'center' => true]], ['v' => '=x', 's' => ['color' => 'FF0000', 'wrap' => true]], 12, 3.5, null], [['v' => 'link', 's' => ['underline' => true, 'color' => '0563C1']], '', ['v' => '', 's' => ['border' => true]], 'texto', -1]], ['cols' => [20, 30, 10], 'merges' => ['A3:B3'], 'autofilter' => 'A1:E2', 'links' => [['ref' => 'A2', 'url' => 'https://exemplo.invalid/x']]]);
$chgLike->addSheet('Outra', [['x']]);
assert_same('8a8227839363e9e4c7669a022124494c8d0ca60e015750521ce7bddaae682ce2', hash('sha256', $chgLike->build()), 'XlsxWriter: sem os recursos novos, a saida e a mesma de antes (Controle de CHG)');

// So aprovados, pelo servico: a previa conta os que ficam fora; sem NC, a planilha nao sai.
final class QaRhPdo extends PDO {
    public array $rows = [];
    public array $sql = [];
    public function __construct() {
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaRhStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }
}
final class QaRhStatement extends PDOStatement {
    private array $result = [];
    public function __construct(private QaRhPdo $db, private string $query) {
    }
    public function execute(?array $params = null): bool {
        $this->db->sql[] = $this->query;
        if (strpos($this->query, ' FROM portal_overtime_entries WHERE work_date BETWEEN :date_from AND :date_to') === false) {
            throw new PDOException('consulta nao prevista: ' . $this->query);
        }
        $this->result = isset($params[':cursor_id']) ? [] : $this->db->rows;
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        return $this->result;
    }
}
$rhDb = static function (array $rows): QaRhPdo {
    $pdo = new QaRhPdo();
    $pdo->rows = $rows;
    return $pdo;
};
$mixed = array_merge($rhRows, [
    ['id' => 4, 'employee_id' => 14, 'employee_name' => 'Pendente QA', 'work_date' => '2026-11-17', 'nc' => '914', 'total_minutes' => 30, 'reason' => 'p', 'justification' => 'p', 'status' => 'pending'],
    ['id' => 5, 'employee_id' => 15, 'employee_name' => 'Rejeitado QA', 'work_date' => '2026-11-17', 'nc' => '915', 'total_minutes' => 30, 'reason' => 'r', 'justification' => 'r', 'status' => 'rejected'],
    ['id' => 6, 'employee_id' => 16, 'employee_name' => 'Sincronizado QA', 'work_date' => '2026-11-17', 'nc' => '916', 'total_minutes' => 30, 'reason' => 's', 'justification' => 's', 'status' => 'synced'],
    ['id' => 7, 'employee_id' => 17, 'employee_name' => 'Erro QA', 'work_date' => '2026-11-17', 'nc' => '917', 'total_minutes' => 30, 'reason' => 'e', 'justification' => 'e', 'status' => 'sync_error'],
]);
$gestor = ['role' => 'gestor', 'employee_id' => null, 'username' => 'qa.gestor'];
$rhPdo = $rhDb($mixed);
$rhService = new OperationalService($rhPdo, '2026-11-20');
$rhPreview = $rhService->overtimeRhPreview(['competency' => '2026-11'], $gestor);
assert_same(['arquivo' => 'Total H-E Suporte Tecnico BPM 11-2026.xlsx', 'aprovados' => 3, 'pendentes_fora' => 1, 'outros_fora' => 3, 'total_minutos' => 1650, 'sem_nc' => ['lancamentos' => 0, 'pessoas' => []]], array_diff_key($rhPreview, ['periodo' => true]), 'exportacao do RH, previa: so os aprovados vao; pendentes e os demais status (rejeitado, sincronizado, erro) contados como fora; o total geral');
$rhExport = (new OperationalService($rhDb($mixed), '2026-11-20'))->overtimeRhXlsx(['competency' => '2026-11'], $gestor, 'CC QA');
$exported = qa_xml(qa_zip_entries($rhExport['bytes'])['xl/worksheets/sheet1.xml']);
$exportedNames = array_map(static fn ($c): string => (string)$c->is->t, $exported->xpath("//x:c[starts-with(@r, 'C') and @r != 'C1']"));
assert_same([['CAIO FICTICIO', 'ANA LÚCIA FICTICIA', 'BRUNO FICTICIO'], 'CC QA', 'Total H-E Suporte Tecnico BPM 11-2026.xlsx'], [$exportedNames, (string)$exported->xpath("//x:c[@r='A2']")[0]->is->t, $rhExport['arquivo']], 'exportacao do RH: so os aprovados na planilha, com o centro de custo configurado');
assert_same([true, false, true], [$rhPdo->sql !== [], strpos(implode("\n", $rhPdo->sql), 'AND status = :status') !== false, strpos(implode("\n", $rhPdo->sql), 'AS nc FROM portal_overtime_entries') !== false], 'exportacao do RH: le todos os status (a separacao e no PHP) e o NC da copia ou do cadastro');
$noNc = $mixed;
$noNc[1]['nc'] = null;
$noNc[] = ['id' => 8, 'employee_id' => 11, 'employee_name' => 'Ana Lúcia Ficticia', 'work_date' => '2026-11-19', 'nc' => '', 'total_minutes' => 10, 'reason' => 'x', 'justification' => 'x', 'status' => 'approved'];
$noNc[3]['nc'] = null;
$missingPreview = (new OperationalService($rhDb($noNc), '2026-11-20'))->overtimeRhPreview(['competency' => '2026-11'], $gestor);
$refused = '';
try {
    (new OperationalService($rhDb($noNc), '2026-11-20'))->overtimeRhXlsx(['competency' => '2026-11'], $gestor, 'CC QA');
} catch (DomainException $e) {
    $refused = $e->getMessage();
}
assert_same([['lancamentos' => 2, 'pessoas' => [['employee_id' => 11, 'employee_name' => 'Ana Lúcia Ficticia']]], '2 lançamento(s) aprovado(s) sem NC no cadastro: a planilha não sai. Veja quem falta na prévia da exportação.'], [$missingPreview['sem_nc'], $refused], 'exportacao do RH: aprovado sem NC (o pendente sem NC nao conta) recusa a planilha; a previa lista quem falta, uma vez por pessoa');
$overtimeEndpoint = (string)file_get_contents(__DIR__ . '/../api/portal/overtime.php');
assert_same([true, true, true, true], [
    strpos($overtimeEndpoint, "if ((\$_GET['format'] ?? '') === 'csv') {\n            throw new InvalidArgumentException(") !== false,
    strpos($overtimeEndpoint, "header('Content-Disposition: attachment; filename=\"' . \$export['arquivo'] . '\"');") !== false,
    strpos($overtimeEndpoint, "audit_log('OVERTIME_EXPORTED', \"Exportacao XLSX de horas extras (modelo do RH): aprovados {\$p['aprovados']}, pendentes fora {\$p['pendentes_fora']}, outros fora {\$p['outros_fora']}, minutos {\$p['total_minutos']}.\", 'INFO');") !== false,
    strpos($overtimeEndpoint, "(string)(\$config['centro_custo_horas_extras'] ?? OvertimeRhSheet::DEFAULT_COST_CENTER)") !== false,
], 'exportacao do RH: o CSV de horas extras saiu, o .xlsx com o nome do modelo, a auditoria com o formato e as contagens, o centro de custo da configuracao');

echo "QA overtime OK\n";
