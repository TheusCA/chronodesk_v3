<?php
declare(strict_types=1);
// ============================================================================
// Paridade da escala mensal do F10 com a planilha "Escala Home" (desenho F10,
// secao 4; lote F10-1). Ferramenta da estacao, so linha de comando.
//
// LGPD: a planilha, o arquivo mensal e o casamento nome -> ID tem nomes reais
// e ficam so em _referencias/ (fora do git). Este script os recebe por
// argumento e imprime SO contagens; nunca nome, login nem dado pessoal. Com
// --detalhe, imprime o ID do portal (ou um indice provisorio) e a data.
//
// Uso (o PHP local nao precisa do ZipArchive: o leitor de xlsx e proprio):
//   php scripts/paridade-escala-mensal.php modelo-casamento <planilha.xlsx> <_referencias/casamento-nome-id.csv>
//   php scripts/paridade-escala-mensal.php escala <planilha.xlsx> --meses=2026-10,2026-11 [--casamento=<csv> --portal=<json>[,<json>]] [--detalhe]
//   php scripts/paridade-escala-mensal.php pa <planilha.xlsx> --mes=2026-12 [--mensal=<arquivo mensal.xlsx>] [--casamento=<csv>] [--fora-da-equipe=presente|ausente]
//
// escala sem --portal: recalcula o HISTORICO com a configuracao ATUAL da
//   planilha traduzida em regras do portal e o PresenceEngine (prova a
//   traducao e o motor). Com --portal (JSON por employee_id, formato
//   paridade-escala-v1) e --casamento: compara com o que o portal calcula.
// pa: monta a entrada do PaDistributionEngine com a presenca do HISTORICO e o
//   CONFIG PA da planilha; com --mensal, compara com a aba PA DIARIO.
// ============================================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../services/PaDistributionEngine.php';
require_once __DIR__ . '/../services/PresenceEngine.php';

const PARIDADE_MAX_FILE_BYTES = 20971520;
const PARIDADE_MAX_ENTRY_BYTES = 33554432;
const PARIDADE_MAX_ENTRIES = 2000;
const PARIDADE_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const PARIDADE_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const PARIDADE_PKG_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
const PARIDADE_TEMPLATE_HEADER = ['nome_planilha', 'nome_normalizado', 'primeiro_nome_pa_diario', 'ambiguo', 'employee_id', 'abas'];

final class ParidadeErro extends RuntimeException
{
}

function paridade_norm(?string $value): string
{
    return PaDistributionEngine::normalizeName((string)$value);
}

// ----------------------------------------------------------------------------
// Leitor de xlsx (zip + zlib + SimpleXML), so leitura, com limites
// ----------------------------------------------------------------------------

/** Conteudo das entradas .xml e .rels do pacote: [nome => bytes]. */
function paridade_zip_entries(string $path): array
{
    if (!is_file($path) || filesize($path) > PARIDADE_MAX_FILE_BYTES) {
        throw new ParidadeErro('arquivo inexistente ou maior que 20 MB: ' . basename($path));
    }
    $data = (string)file_get_contents($path);
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false || strlen($data) - $eocd < 22) {
        throw new ParidadeErro('pacote zip invalido: ' . basename($path));
    }
    $end = unpack('vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset', substr($data, $eocd + 4, 16));
    if ($end['entries'] > PARIDADE_MAX_ENTRIES || $end['cdOffset'] === 0xFFFFFFFF || $end['cdOffset'] + $end['cdSize'] > $eocd) {
        throw new ParidadeErro('pacote zip fora do limite ou zip64: ' . basename($path));
    }
    $out = [];
    $pos = $end['cdOffset'];
    $total = 0;
    for ($i = 0; $i < $end['entries']; $i++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") {
            throw new ParidadeErro('diretorio central invalido: ' . basename($path));
        }
        $h = unpack('vmethod', substr($data, $pos + 10, 2)) + unpack('VcompSize/Vsize/vnameLen/vextraLen/vcommentLen', substr($data, $pos + 20, 14)) + unpack('Voffset', substr($data, $pos + 42, 4));
        $name = substr($data, $pos + 46, $h['nameLen']);
        $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        if (!preg_match('/\.(xml|rels)$/', $name)) {
            continue;
        }
        if ($h['size'] > PARIDADE_MAX_ENTRY_BYTES || ($total += $h['size']) > 4 * PARIDADE_MAX_ENTRY_BYTES || substr($data, $h['offset'], 4) !== "PK\x03\x04") {
            throw new ParidadeErro('entrada do zip fora do limite: ' . basename($path));
        }
        $local = unpack('vnameLen/vextraLen', substr($data, $h['offset'] + 26, 4));
        $raw = substr($data, $h['offset'] + 30 + $local['nameLen'] + $local['extraLen'], $h['compSize']);
        if ($h['method'] === 0) {
            $content = $raw;
        } elseif ($h['method'] === 8) {
            set_error_handler(static fn (): bool => true);
            try {
                $content = gzinflate($raw, max(1, $h['size']));
            } finally {
                restore_error_handler();
            }
        } else {
            throw new ParidadeErro("metodo de compressao {$h['method']} nao suportado");
        }
        if (!is_string($content) || strlen($content) !== $h['size']) {
            throw new ParidadeErro('entrada do zip corrompida: ' . basename($path));
        }
        $out[$name] = $content;
    }
    return $out;
}

function paridade_xml(string $content): SimpleXMLElement
{
    if (stripos($content, '<!DOCTYPE') !== false) {
        throw new ParidadeErro('xml com DOCTYPE recusado');
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
    libxml_use_internal_errors($previous);
    if ($xml === false) {
        throw new ParidadeErro('xml invalido no pacote');
    }
    return $xml;
}

function paridade_column(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + ord($ch) - 64;
    }
    return $n;
}

/** Abas do xlsx: [nome => [linha => [coluna => texto]]], linhas e colunas a partir de 1. */
function paridade_xlsx_sheets(string $path): array
{
    $entries = paridade_zip_entries($path);
    foreach (['xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
        if (!isset($entries[$required])) {
            throw new ParidadeErro('xlsx sem ' . $required);
        }
    }
    $shared = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        foreach (paridade_xml($entries['xl/sharedStrings.xml'])->children(PARIDADE_NS)->si as $si) {
            $dom = dom_import_simplexml($si);
            $text = '';
            foreach ($dom->getElementsByTagNameNS(PARIDADE_NS, 't') as $t) {
                $text .= $t->textContent;
            }
            $shared[] = $text;
        }
    }
    $targets = [];
    foreach (dom_import_simplexml(paridade_xml($entries['xl/_rels/workbook.xml.rels']))->getElementsByTagNameNS(PARIDADE_PKG_REL_NS, 'Relationship') as $rel) {
        $target = ltrim($rel->getAttribute('Target'), '/');
        $targets[$rel->getAttribute('Id')] = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
    }
    $sheets = [];
    foreach (paridade_xml($entries['xl/workbook.xml'])->children(PARIDADE_NS)->sheets->sheet as $sheet) {
        $rid = (string)$sheet->attributes(PARIDADE_REL_NS)['id'];
        $file = $targets[$rid] ?? '';
        if (!isset($entries[$file])) {
            throw new ParidadeErro('aba sem arquivo no pacote');
        }
        $cells = [];
        foreach (paridade_xml($entries[$file])->children(PARIDADE_NS)->sheetData->row as $row) {
            foreach ($row->children(PARIDADE_NS)->c as $c) {
                if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$c->attributes()['r'], $ref)) {
                    throw new ParidadeErro('referencia de celula invalida');
                }
                $type = (string)$c->attributes()['t'];
                $children = $c->children(PARIDADE_NS);
                if ($type === 'inlineStr') {
                    $value = '';
                    foreach (dom_import_simplexml($c)->getElementsByTagNameNS(PARIDADE_NS, 't') as $t) {
                        $value .= $t->textContent;
                    }
                } elseif (!isset($children->v)) {
                    continue;
                } elseif ($type === 's') {
                    $value = $shared[(int)$children->v] ?? '';
                } else {
                    $value = (string)$children->v;
                }
                $cells[(int)$ref[2]][paridade_column($ref[1])] = $value;
            }
        }
        $sheets[(string)$sheet->attributes()['name']] = $cells;
    }
    return $sheets;
}

function paridade_cell(array $sheet, int $row, int $col): string
{
    return trim((string)($sheet[$row][$col] ?? ''));
}

/** excel_date() do script: serial do Excel, dd/mm/aaaa, aaaa-mm-dd ou dd/mm/aa; null se nada servir. */
function paridade_date(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return gmdate('Y-m-d', ((int)floor((float)$value) - 25569) * 86400);
    }
    foreach (['d/m/Y', 'Y-m-d', 'd/m/y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, substr($value, 0, 10), new DateTimeZone('UTC'));
        if ($date !== false && $date->format($format) === substr($value, 0, 10)) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

/** int(valor or padrao) do Python: vazio ou zero vira o padrao. */
function paridade_int_or(string $value, int $default): int
{
    return ($value === '' || (float)$value == 0.0) ? $default : (int)(float)$value;
}

function paridade_weekday(string $date): int
{
    return (int)gmdate('N', (int)strtotime($date . ' 00:00:00 UTC'));
}

// ----------------------------------------------------------------------------
// Leitura da base (as abas de entrada do script, mesmas regras)
// ----------------------------------------------------------------------------

function paridade_base(array $sheets): array
{
    foreach (['CONFIG', 'EQUIPE', 'REGRAS FIXAS', 'EXCECOES', 'CONFIG PA', 'HISTORICO'] as $name) {
        if (!isset($sheets[$name])) {
            throw new ParidadeErro("a planilha nao tem a aba {$name}");
        }
    }
    $cfg = $sheets['CONFIG'];
    $config = [
        'anchor' => paridade_date(paridade_cell($cfg, 6, 2)) ?? '2026-10-01',
        'phase' => [
            'TIME 1' => paridade_int_or(paridade_cell($cfg, 10, 2), 0),
            'TIME 2' => paridade_int_or(paridade_cell($cfg, 11, 2), 1),
            'TIME 3' => paridade_int_or(paridade_cell($cfg, 12, 2), 2),
        ],
    ];
    $team = [];
    foreach ($sheets['EQUIPE'] as $r => $row) {
        $name = paridade_cell($sheets['EQUIPE'], $r, 2);
        if ($r < 4 || $name === '') {
            continue;
        }
        $team[] = [
            'name' => $name, 'key' => paridade_norm($name), 'active' => paridade_norm($row[1] ?? '') === 'SIM',
            'tower' => paridade_norm($row[3] ?? ''), 'mode' => paridade_norm($row[4] ?? ''), 'team' => paridade_norm($row[5] ?? ''),
            'common' => paridade_norm($row[6] ?? '') === 'SIM',
        ];
    }
    $rules = [];
    foreach ($sheets['REGRAS FIXAS'] as $r => $row) {
        $name = paridade_cell($sheets['REGRAS FIXAS'], $r, 2);
        if ($r < 4 || $name === '' || paridade_norm($row[1] ?? '') !== 'SIM') {
            continue;
        }
        $key = paridade_norm($name);
        $rules[$key]['type'] = paridade_norm($row[4] ?? '');
        $week = paridade_int_or(trim((string)($row[5] ?? '')), 1);
        for ($d = 0; $d < 5; $d++) {
            $rules[$key]['cycles'][$week][$d] = paridade_norm($row[6 + $d] ?? '');
        }
    }
    $exceptions = [];
    foreach ($sheets['EXCECOES'] as $r => $row) {
        $name = paridade_cell($sheets['EXCECOES'], $r, 2);
        $start = paridade_date(trim((string)($row[3] ?? '')));
        $end = paridade_date(trim((string)($row[4] ?? '')));
        $status = paridade_norm($row[5] ?? '');
        if ($r >= 4 && paridade_norm($row[1] ?? '') === 'SIM' && $name !== '' && $start !== null && $end !== null && in_array($status, ['HOME', 'PRESENCIAL', 'FERIAS'], true)) {
            $exceptions[] = ['key' => paridade_norm($name), 'start' => $start, 'end' => $end, 'status' => $status];
        }
    }
    $pas = [];
    foreach ($sheets['CONFIG PA'] as $r => $row) {
        $pa = trim((string)($row[1] ?? ''));
        if ($r < 4 || !is_numeric($pa) || paridade_norm($row[8] ?? '') !== 'SIM') {
            continue;
        }
        $pas[] = [
            'pa' => (int)(float)$pa, 'row' => paridade_norm($row[2] ?? ''), 'order' => is_numeric(trim((string)($row[3] ?? ''))) ? (int)(float)$row[3] : 0,
            'type' => paridade_norm($row[4] ?? ''), 'fixed' => trim((string)($row[5] ?? '')), 'loan' => paridade_norm($row[6] ?? '') === 'SIM',
            'prefs' => array_values(array_filter(array_map('trim', explode(';', (string)($row[7] ?? ''))), static fn (string $x): bool => $x !== '')),
        ];
    }
    $history = [];
    foreach ($sheets['HISTORICO'] as $r => $row) {
        $date = paridade_date(trim((string)($row[3] ?? '')));
        $name = trim((string)($row[4] ?? ''));
        if ($r < 2 || $date === null || $name === '') {
            continue;
        }
        $history[] = ['date' => $date, 'name' => $name, 'key' => paridade_norm($name), 'mode' => paridade_norm($row[5] ?? ''), 'team' => paridade_norm($row[6] ?? ''), 'status' => paridade_norm($row[7] ?? '')];
    }
    return [
        'config' => $config, 'team' => $team, 'rules' => $rules,
        'fixed_anchor' => paridade_date(paridade_cell($sheets['REGRAS FIXAS'], 4, 13)) ?? '2026-09-28',
        'exceptions' => $exceptions, 'pas' => $pas, 'history' => $history,
    ];
}

/** EQUIPE ativa por chave (load_team so le ATIVO? = SIM). */
function paridade_active_team(array $base): array
{
    $out = [];
    foreach ($base['team'] as $person) {
        if ($person['active']) {
            $out[$person['key']] = $person;
        }
    }
    return $out;
}

function paridade_is_common(array $person): bool
{
    return $person['common'] && in_array($person['mode'], ['RODIZIO', 'FIXO', 'SEM HOME'], true);
}

/**
 * Regra do portal equivalente ao modo da pessoa na configuracao atual (desenho
 * 2.2): RODIZIO = cycle de 3 com a ancora da CONFIG; FIXO CICLO = cycle de 5xN
 * ancorado na segunda (a da ancora, ou a seguinte); FIXO SEMANAL =
 * fixed_weekdays; SEM HOME = always_onsite. Null: sem traducao (time fora de
 * TIME 1..3).
 */
function paridade_portal_rule(array $base, array $person): ?array
{
    $rule = static fn (string $type, ?array $config): array => ['rule_type' => $type, 'rule_config' => $config, 'effective_from' => '2000-01-03', 'effective_until' => null];
    switch ($person['mode']) {
        case 'SEM HOME':
            return $rule('always_onsite', null);
        case 'RODIZIO':
            $phase = $base['config']['phase'][$person['team']] ?? null;
            if ($phase === null) {
                return null;
            }
            return $rule('cycle', ['pattern' => array_map(static fn (int $i): string => $i === $phase ? 'remote' : 'onsite', [0, 1, 2]), 'anchor_date' => $base['config']['anchor']]);
        case 'FIXO':
            $rec = $base['rules'][$person['key']] ?? null;
            if ($rec === null) {
                return $rule('always_onsite', null);
            }
            $day = static fn (array $week, int $d): string => ($week[$d] ?? '') === 'HOME' ? 'remote' : 'onsite';
            if ($rec['type'] === 'CICLO') {
                $pattern = [];
                for ($w = 1; $w <= max(array_keys($rec['cycles'])); $w++) {
                    $week = $rec['cycles'][$w] ?? ($rec['cycles'][1] ?? []);
                    for ($d = 0; $d < 5; $d++) {
                        $pattern[] = $day($week, $d);
                    }
                }
                $anchor = $base['fixed_anchor'];
                $monday = gmdate('Y-m-d', (int)strtotime($anchor . ' UTC') + ((8 - paridade_weekday($anchor)) % 7) * 86400);
                return $rule('cycle', ['pattern' => $pattern, 'anchor_date' => $monday]);
            }
            $week = $rec['cycles'][1] ?? [];
            $keys = ['mon', 'tue', 'wed', 'thu', 'fri'];
            return $rule('fixed_weekdays', ['weekdays' => array_values(array_filter($keys, static fn (string $k, int $d): bool => $day($week, $d) === 'onsite', ARRAY_FILTER_USE_BOTH))]);
        default:
            return null;
    }
}

function paridade_exception(array $base, string $key, string $date): ?string
{
    foreach ($base['exceptions'] as $exception) {
        if ($exception['key'] === $key && $exception['start'] <= $date && $date <= $exception['end']) {
            return $exception['status'];
        }
    }
    return null;
}

// ----------------------------------------------------------------------------
// Casamento nome -> ID (arquivo local)
// ----------------------------------------------------------------------------

/** Linhas do modelo: um nome normalizado por linha, com as abas onde aparece. */
function paridade_template_rows(array $base): array
{
    $seen = [];
    $add = static function (string $name, string $sheet) use (&$seen): void {
        $key = paridade_norm($name);
        if ($key === '' || in_array($key, ['HIBRIDO', 'CRITICO'], true)) {
            return;
        }
        $seen[$key] ??= ['name' => trim($name), 'sheets' => []];
        $seen[$key]['sheets'][$sheet] = true;
    };
    foreach ($base['team'] as $person) {
        $add($person['name'], 'EQUIPE');
    }
    foreach ($base['history'] as $row) {
        $add($row['name'], 'HISTORICO');
    }
    foreach (array_keys($base['rules']) as $key) {
        $add($key, 'REGRAS FIXAS');
    }
    foreach ($base['exceptions'] as $exception) {
        $add($exception['key'], 'EXCECOES');
    }
    foreach ($base['pas'] as $pa) {
        $add($pa['fixed'], 'CONFIG PA');
        foreach ($pa['prefs'] as $pref) {
            $add($pref, 'CONFIG PA');
        }
    }
    ksort($seen, SORT_STRING);
    $firstCount = [];
    foreach (array_keys($seen) as $key) {
        $first = explode(' ', $key)[0];
        $firstCount[$first] = ($firstCount[$first] ?? 0) + 1;
    }
    $rows = [];
    foreach ($seen as $key => $item) {
        $first = explode(' ', $key)[0];
        $rows[] = [$item['name'], $key, (string)preg_split('/\s+/', $item['name'])[0], $firstCount[$first] > 1 ? 'SIM' : 'NAO', '', implode('+', array_keys($item['sheets']))];
    }
    return $rows;
}

/** Grava o modelo so em _referencias/, sem sobrescrever (o arquivo preenchido nunca e perdido). */
function paridade_write_template(string $out, array $rows): void
{
    $parent = realpath(dirname($out));
    if ($parent === false || basename($parent) !== '_referencias') {
        throw new ParidadeErro('o modelo do casamento so pode ser gravado numa pasta _referencias (fora do git)');
    }
    if (file_exists($out)) {
        throw new ParidadeErro('o arquivo de casamento ja existe; nada foi gravado (apague ou renomeie antes, se for mesmo para refazer)');
    }
    $handle = fopen($out, 'xb');
    if ($handle === false) {
        throw new ParidadeErro('nao foi possivel criar o arquivo de casamento');
    }
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, PARIDADE_TEMPLATE_HEADER, ';', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ';', '"', '');
    }
    fclose($handle);
}

/** Le o casamento preenchido: [chave => ['id' => ?int, 'first' => string, 'ambiguous' => bool]]. */
function paridade_read_match(string $path): array
{
    $handle = is_file($path) ? fopen($path, 'rb') : false;
    if ($handle === false) {
        throw new ParidadeErro('casamento nao encontrado: ' . basename($path));
    }
    $header = fgetcsv($handle, 0, ';', '"', '');
    $header = is_array($header) ? array_map(static fn ($h): string => trim(str_replace("\xEF\xBB\xBF", '', (string)$h)), $header) : [];
    $col = array_flip($header);
    foreach (['nome_normalizado', 'primeiro_nome_pa_diario', 'ambiguo', 'employee_id'] as $needed) {
        if (!isset($col[$needed])) {
            throw new ParidadeErro("casamento sem a coluna {$needed}");
        }
    }
    $out = [];
    while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        $key = paridade_norm($row[$col['nome_normalizado']] ?? '');
        if ($key === '') {
            continue;
        }
        $id = trim((string)($row[$col['employee_id']] ?? ''));
        if ($id !== '' && !preg_match('/^[1-9]\d{0,9}$/', $id)) {
            throw new ParidadeErro('casamento com employee_id invalido (so inteiro positivo)');
        }
        $out[$key] = ['id' => $id === '' ? null : (int)$id, 'first' => paridade_norm($row[$col['primeiro_nome_pa_diario']] ?? ''), 'ambiguous' => paridade_norm($row[$col['ambiguo']] ?? '') === 'SIM'];
    }
    fclose($handle);
    return $out;
}

// ----------------------------------------------------------------------------
// Paridade da escala (4.1)
// ----------------------------------------------------------------------------

function paridade_months(array $base, array $months): array
{
    $byMonth = [];
    foreach ($base['history'] as $row) {
        $month = substr($row['date'], 0, 7);
        if (in_array($month, $months, true)) {
            $byMonth[$month][] = $row;
        }
    }
    return $byMonth;
}

/**
 * Sem portal: HISTORICO x configuracao atual traduzida e PresenceEngine.
 * Devolve [mes => [motivo => contagem]] e, com $detail, a lista de [chave, data, motivo].
 */
function paridade_scale_from_sheet(array $base, array $months, array &$detail = []): array
{
    $team = paridade_active_team($base);
    $counts = [];
    foreach (paridade_months($base, $months) as $month => $rows) {
        $counts[$month] = ['linhas' => count($rows)];
        foreach ($rows as $row) {
            $person = $team[$row['key']] ?? null;
            if ($person === null) {
                $motive = 'fora da EQUIPE atual';
            } elseif ($person['mode'] !== $row['mode'] || $person['team'] !== $row['team']) {
                $motive = 'modo ou time mudou depois da geracao';
            } elseif (!paridade_is_common($person)) {
                $motive = 'fora do grupo comum hoje';
            } else {
                $exception = paridade_exception($base, $row['key'], $row['date']);
                $rule = paridade_portal_rule($base, $person);
                if ($exception === 'FERIAS') {
                    $status = 'FERIAS';
                } elseif ($rule === null) {
                    $status = 'SEM REGRA';
                } else {
                    $exceptionRow = $exception === null ? null : ['exception_type' => $exception === 'HOME' ? 'remote' : 'onsite'];
                    $schedule = PresenceEngine::scheduleForDay(['team' => null], $row['date'], [$rule], $exceptionRow);
                    $status = ['presencial' => 'PRESENCIAL', 'remoto' => 'HOME'][$schedule['situacao']] ?? 'SEM REGRA';
                }
                if ($status === $row['status']) {
                    $motive = 'iguais';
                } elseif ($status === 'SEM REGRA') {
                    $motive = 'sem regra traduzivel';
                } elseif ($exception !== null || $row['status'] === 'FERIAS') {
                    $motive = 'excecao ou ausencia so de um lado';
                } else {
                    $motive = 'mesma regra, dia diferente';
                }
            }
            $counts[$month][$motive] = ($counts[$month][$motive] ?? 0) + 1;
            if ($motive !== 'iguais') {
                $detail[] = [$row['key'], $row['date'], $motive];
            }
        }
    }
    return $counts;
}

/** Linhas do JSON do portal (formato paridade-escala-v1) indexadas por "id|data". */
function paridade_read_portal(array $paths): array
{
    $out = [];
    foreach ($paths as $path) {
        $json = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($json) || ($json['formato'] ?? '') !== 'paridade-escala-v1' || !is_array($json['linhas'] ?? null)) {
            throw new ParidadeErro('JSON do portal invalido (esperado formato paridade-escala-v1): ' . basename($path));
        }
        foreach ($json['linhas'] as $line) {
            $id = $line['employee_id'] ?? null;
            $date = (string)($line['data'] ?? '');
            if (!is_int($id) || $id < 1 || paridade_date($date) !== $date || strpos($date, '-') === false) {
                throw new ParidadeErro('linha do JSON do portal sem employee_id ou data');
            }
            $out[$id . '|' . $date] = ['situacao' => (string)($line['situacao'] ?? ''), 'fonte' => (string)($line['fonte'] ?? ''), 'rule_type' => $line['rule_type'] ?? null];
        }
    }
    return $out;
}

/**
 * Com portal: HISTORICO x o que o portal calcula para o mesmo employee_id e
 * dia. Motivos da secao 4.1 do desenho.
 */
function paridade_scale_from_portal(array $base, array $months, array $match, array $portal, array &$detail = []): array
{
    $team = paridade_active_team($base);
    $expectedType = static function (?array $person, array $base): ?string {
        if ($person === null) {
            return null;
        }
        if ($person['mode'] === 'FIXO') {
            return ($base['rules'][$person['key']]['type'] ?? '') === 'CICLO' ? 'cycle' : 'fixed_weekdays';
        }
        return ['RODIZIO' => 'cycle', 'SEM HOME' => 'always_onsite', 'FULL HOME' => 'always_remote'][$person['mode']] ?? null;
    };
    $counts = [];
    $seen = [];
    foreach (paridade_months($base, $months) as $month => $rows) {
        $counts[$month] = ['linhas' => count($rows)];
        foreach ($rows as $row) {
            $id = $match[$row['key']]['id'] ?? null;
            $portalRow = $id === null ? null : ($portal[$id . '|' . $row['date']] ?? null);
            $sheetSituation = ['PRESENCIAL' => 'presencial', 'HOME' => 'remoto', 'FERIAS' => 'ausente'][$row['status']] ?? '?';
            if ($id === null) {
                $motive = 'sem casamento de ID';
            } elseif ($portalRow === null) {
                $motive = 'fora do grupo comum de um lado (so na planilha)';
            } elseif ($portalRow['situacao'] === $sheetSituation) {
                $motive = 'iguais';
            } elseif ($portalRow['situacao'] === 'fora') {
                $motive = 'fora do grupo comum de um lado (portal: fora do Mapa)';
            } elseif ($portalRow['situacao'] === 'dia_nao_util') {
                $motive = 'dia nao util de um lado';
            } elseif ($portalRow['situacao'] === 'sem_escala') {
                $motive = 'sem regra no portal';
            } elseif (in_array($portalRow['fonte'], ['excecao', 'ausencia'], true) || $row['status'] === 'FERIAS' || paridade_exception($base, $row['key'], $row['date']) !== null) {
                $motive = 'excecao ou ausencia so de um lado';
            } elseif ($expectedType($team[$row['key']] ?? null, $base) !== $portalRow['rule_type']) {
                $motive = 'tipo de regra diferente';
            } else {
                $motive = 'mesma regra, dia diferente';
            }
            if ($id !== null) {
                $seen[$id . '|' . $row['date']] = true;
            }
            $counts[$month][$motive] = ($counts[$month][$motive] ?? 0) + 1;
            if ($motive !== 'iguais') {
                $detail[] = [$id === null ? $row['key'] : $id, $row['date'], $motive];
            }
        }
    }
    foreach ($portal as $key => $portalRow) {
        [$id, $date] = explode('|', $key);
        $month = substr($date, 0, 7);
        if (!isset($seen[$key]) && isset($counts[$month]) && in_array($portalRow['situacao'], ['presencial', 'remoto', 'ausente'], true)) {
            $motive = 'fora do grupo comum de um lado (so no portal)';
            $counts[$month][$motive] = ($counts[$month][$motive] ?? 0) + 1;
            $detail[] = [(int)$id, $date, $motive];
        }
    }
    return $counts;
}

// ----------------------------------------------------------------------------
// Paridade de PA (4.4)
// ----------------------------------------------------------------------------

/**
 * Entrada do PaDistributionEngine para o mes: presenca do grupo comum pelo
 * HISTORICO; CONFIG PA traduzido (FIXO -> fixed, FLEXIVEL -> flexible,
 * BLOQUEADO -> indisponivel, outro tipo fora; "Hibrido"/"Critico" ->
 * reservado); dono de fora do grupo comum pelo modo na EQUIPE (HIBRIDO e
 * CRITICO presentes; o resto ausente); dono que nao esta na EQUIPE pelo
 * $outside ("presente" reproduz os dois "sempre presentes" do script).
 * IDs pelo casamento ou, sem ele, provisorios em memoria.
 */
function paridade_pa_input(array $base, string $month, ?array $match, string $outside): array
{
    $ids = [];
    $missing = [];
    $id = static function (string $key) use (&$ids, &$missing, $match): int {
        if (isset($ids[$key])) {
            return $ids[$key];
        }
        $matched = $match[$key]['id'] ?? null;
        if ($match !== null && $matched === null) {
            $missing[$key] = true;
        }
        // Provisorio acima de qualquer ID real, para nunca colidir.
        return $ids[$key] = $matched ?? (1000000000 + count($ids));
    };
    $rows = paridade_months($base, [$month])[$month] ?? [];
    $days = [];
    $presence = [];
    $people = [];
    foreach ($rows as $row) {
        $pid = $id($row['key']);
        $people[$pid] = $row['key'];
        $days[$row['date']] = true;
        $presence[$row['date']][$pid] = ['PRESENCIAL' => 'onsite', 'HOME' => 'remote'][$row['status']] ?? 'absent';
    }
    ksort($days);
    $team = paridade_active_team($base);
    $seats = [];
    $preferences = [];
    $outsideOwners = [];
    foreach ($base['pas'] as $rec) {
        if (!in_array($rec['type'], ['FIXO', 'FLEXIVEL', 'BLOQUEADO'], true)) {
            continue;
        }
        $seat = ['pa' => $rec['pa'], 'row' => $rec['row'] === 'SUPERIOR' ? 1 : 2, 'column' => $rec['order'], 'type' => $rec['type'] === 'FIXO' ? 'fixed' : 'flexible', 'can_lend' => $rec['loan'], 'unavailable' => $rec['type'] === 'BLOQUEADO', 'owners' => []];
        $ownerKey = paridade_norm($rec['fixed']);
        if ($rec['type'] === 'FIXO' && in_array($ownerKey, ['HIBRIDO', 'CRITICO'], true)) {
            $seat['reserved_for'] = $ownerKey === 'HIBRIDO' ? 'hybrid' : 'critical';
        } elseif ($rec['type'] === 'FIXO' && $ownerKey !== '') {
            $seat['owners'] = [['employee_id' => $id($ownerKey)]];
            if (!isset($people[$ids[$ownerKey]])) {
                $outsideOwners[$ownerKey] = $ids[$ownerKey];
            }
        }
        $seats[] = $seat;
        $preferences[$rec['pa']] = array_values(array_unique(array_map(static fn (string $p): int => $id(paridade_norm($p)), $rec['prefs'])));
    }
    $notInTeam = 0;
    foreach ($outsideOwners as $key => $ownerId) {
        $person = $team[$key] ?? null;
        $notInTeam += $person === null ? 1 : 0;
        $state = $person === null ? ($outside === 'presente' ? 'onsite' : 'absent') : (in_array($person['mode'], ['HIBRIDO', 'CRITICO'], true) ? 'onsite' : 'absent');
        foreach (array_keys($days) as $date) {
            $presence[$date][$ownerId] = $state;
        }
    }
    return [
        'input' => ['days' => array_keys($days), 'seats' => $seats, 'people' => $people, 'presence' => $presence, 'preferences' => $preferences],
        'keys' => array_flip($ids),
        'counts' => ['pessoas' => count($people), 'donos de fora do grupo comum' => count($outsideOwners), 'donos fora da EQUIPE (' . $outside . ')' => $notInTeam, 'sem casamento de ID' => count($missing)],
    ];
}

/** Contagens do resultado do motor para o mes. */
function paridade_pa_counts(array $result): array
{
    $counts = ['dias' => 0, 'programacao dinamica' => 0, 'guloso' => 0, 'ninguem presencial' => 0, 'sem PA (pessoa-dia)' => 0, 'dias com sem PA' => 0, 'trocas' => 0];
    $capacity = [];
    $onsite = [];
    foreach ($result['days'] as $day) {
        $counts['dias']++;
        $counts[['dp' => 'programacao dinamica', 'greedy' => 'guloso', 'none' => 'ninguem presencial'][$day['method']]]++;
        $counts['sem PA (pessoa-dia)'] += count($day['no_pa']);
        $counts['dias com sem PA'] += $day['no_pa'] === [] ? 0 : 1;
        $counts['trocas'] += $day['moves'];
        $capacity[] = $day['capacity'];
        $onsite[] = $day['onsite'];
        foreach ($day['alerts'] as $alert) {
            $key = 'alerta ' . $alert['code'] . (isset($alert['reason']) ? ' (' . $alert['reason'] . ')' : '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }
    $counts['PAs livres no dia (min-max)'] = $capacity === [] ? '-' : min($capacity) . '-' . max($capacity);
    $counts['presenciais comuns no dia (max)'] = $onsite === [] ? 0 : max($onsite);
    return $counts;
}

/**
 * Compara o resultado do motor com a aba PA DIARIO MM-AAAA de um arquivo
 * mensal gerado pelo script (linhas 704-832): datas "dd/mm" na linha 3 a
 * partir da coluna 4; uma linha por PA (numero na coluna 1) com o primeiro
 * nome ou LIVRE; linhas TROCAS FLEXIVEIS e SEM PA. O primeiro nome vem da
 * coluna do casamento (ou da 1a palavra do nome); ambiguo conta a parte.
 */
function paridade_pa_compare(array $sheets, string $month, array $result, array $keys, ?array $match, array $seatNumbers): array
{
    [$year, $mm] = explode('-', $month);
    $name = "PA DIARIO {$mm}-{$year}";
    if (!isset($sheets[$name])) {
        throw new ParidadeErro("o arquivo mensal nao tem a aba {$name}");
    }
    $sheet = $sheets[$name];
    $columns = [];
    foreach ($sheet[3] ?? [] as $col => $value) {
        if ($col >= 4 && preg_match('/^(\d{2})\/(\d{2})$/', trim((string)$value), $m)) {
            $columns["{$year}-{$m[2]}-{$m[1]}"] = $col;
        }
    }
    $first = static function (?int $id, ?string $reserved) use ($keys, $match): array {
        if ($reserved !== null) {
            return [$reserved === 'hybrid' ? 'HIBRIDO' : 'CRITICO', false];
        }
        if ($id === null) {
            return ['LIVRE', false];
        }
        $key = $keys[$id];
        $entry = $match[$key] ?? null;
        $short = $entry !== null && $entry['first'] !== '' ? $entry['first'] : explode(' ', $key)[0];
        return [$short, (bool)($entry['ambiguous'] ?? false)];
    };
    $counts = ['dias na aba' => count($columns), 'dias sem a data no motor' => 0, 'celulas iguais' => 0, 'celulas divergentes' => 0, 'celulas com primeiro nome ambiguo' => 0, 'PAs so na aba' => 0, 'trocas iguais (dias)' => 0, 'trocas divergentes (dias)' => 0, 'sem PA iguais (dias)' => 0, 'sem PA divergentes (dias)' => 0];
    $paRows = [];
    $movesRow = null;
    $noPaRow = null;
    foreach ($sheet as $r => $row) {
        $label = paridade_norm($row[1] ?? '');
        if ($r >= 4 && preg_match('/^\d+(\.0+)?$/', $label)) {
            $paRows[(int)$label] = $r;
        } elseif ($label === 'TROCAS FLEXIVEIS') {
            $movesRow = $r;
        } elseif ($label === 'SEM PA') {
            $noPaRow = $r;
        }
    }
    foreach ($columns as $date => $col) {
        $day = $result['days'][$date] ?? null;
        if ($day === null) {
            $counts['dias sem a data no motor']++;
            continue;
        }
        foreach ($paRows as $pa => $r) {
            $expected = paridade_norm($sheet[$r][$col] ?? '');
            $occupant = $day['occupants'][$pa] ?? null;
            [$short, $ambiguous] = $first($occupant['employee_id'] ?? null, $occupant['reserved_for'] ?? null);
            if ($ambiguous && $expected !== 'LIVRE') {
                $counts['celulas com primeiro nome ambiguo']++;
            } elseif ($short === $expected) {
                $counts['celulas iguais']++;
            } else {
                $counts['celulas divergentes']++;
            }
        }
        if ($movesRow !== null) {
            $counts[((int)(float)($sheet[$movesRow][$col] ?? 0)) === $day['moves'] ? 'trocas iguais (dias)' : 'trocas divergentes (dias)']++;
        }
        if ($noPaRow !== null) {
            $text = trim((string)($sheet[$noPaRow][$col] ?? ''));
            $expectedNoPa = $text === '' || $text === '-' ? [] : array_map('paridade_norm', explode(',', $text));
            $gotNoPa = array_map(static fn (int $id): string => $first($id, null)[0], $day['no_pa']);
            sort($expectedNoPa);
            sort($gotNoPa);
            $counts[$expectedNoPa === $gotNoPa ? 'sem PA iguais (dias)' : 'sem PA divergentes (dias)']++;
        }
    }
    $counts['PAs so na aba'] = count(array_diff(array_keys($paRows), $seatNumbers));
    return $counts;
}

// ----------------------------------------------------------------------------
// Linha de comando
// ----------------------------------------------------------------------------

function paridade_print(string $title, array $counts): void
{
    echo "== {$title} ==\n";
    foreach ($counts as $key => $value) {
        if (is_array($value)) {
            echo "  {$key}:\n";
            foreach ($value as $k => $v) {
                echo "    {$k}: {$v}\n";
            }
        } else {
            echo "  {$key}: {$value}\n";
        }
    }
}

function paridade_main(array $argv): int
{
    $command = $argv[1] ?? '';
    $positional = [];
    $options = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $options[$m[1]] = $m[2] ?? '1';
        } else {
            $positional[] = $arg;
        }
    }
    try {
        if (!in_array($command, ['modelo-casamento', 'escala', 'pa'], true) || !isset($positional[0])) {
            throw new ParidadeErro('uso: modelo-casamento <planilha> <saida.csv> | escala <planilha> --meses=AAAA-MM[,..] [--casamento= --portal=] [--detalhe] | pa <planilha> --mes=AAAA-MM [--mensal=] [--casamento=] [--fora-da-equipe=presente|ausente]');
        }
        $base = paridade_base(paridade_xlsx_sheets($positional[0]));
        if ($command === 'modelo-casamento') {
            if (!isset($positional[1])) {
                throw new ParidadeErro('falta o caminho do modelo (em _referencias/)');
            }
            $rows = paridade_template_rows($base);
            paridade_write_template($positional[1], $rows);
            paridade_print('modelo do casamento gravado (coluna employee_id vazia)', [
                'nomes' => count($rows),
                'primeiro nome ambiguo' => count(array_filter($rows, static fn (array $r): bool => $r[3] === 'SIM')),
                'so no HISTORICO' => count(array_filter($rows, static fn (array $r): bool => $r[5] === 'HISTORICO')),
                'no CONFIG PA e fora da EQUIPE' => count(array_filter($rows, static fn (array $r): bool => strpos($r[5], 'CONFIG PA') !== false && strpos($r[5], 'EQUIPE') === false)),
            ]);
            return 0;
        }
        $match = isset($options['casamento']) ? paridade_read_match($options['casamento']) : null;
        $detail = [];
        if ($command === 'escala') {
            $months = array_values(array_filter(explode(',', $options['meses'] ?? ''), static fn (string $m): bool => preg_match('/^\d{4}-\d{2}$/', $m) === 1));
            if ($months === []) {
                throw new ParidadeErro('informe --meses=AAAA-MM[,AAAA-MM]');
            }
            if (isset($options['portal'])) {
                if ($match === null) {
                    throw new ParidadeErro('--portal exige --casamento (o JSON do portal so tem employee_id)');
                }
                $counts = paridade_scale_from_portal($base, $months, $match, paridade_read_portal(explode(',', $options['portal'])), $detail);
                $title = 'escala: HISTORICO x portal';
            } else {
                $counts = paridade_scale_from_sheet($base, $months, $detail);
                $title = 'escala: HISTORICO x configuracao atual da planilha no motor do portal (sem o JSON do portal)';
            }
            foreach ($counts as $month => $byMotive) {
                paridade_print("{$title}, {$month}", $byMotive);
            }
        } else {
            $month = $options['mes'] ?? '';
            if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                throw new ParidadeErro('informe --mes=AAAA-MM');
            }
            $outside = $options['fora-da-equipe'] ?? 'presente';
            if (!in_array($outside, ['presente', 'ausente'], true)) {
                throw new ParidadeErro('--fora-da-equipe=presente ou ausente');
            }
            $built = paridade_pa_input($base, $month, $match, $outside);
            if ($built['input']['days'] === []) {
                throw new ParidadeErro("o HISTORICO nao tem {$month}");
            }
            $result = PaDistributionEngine::distribute($built['input']);
            paridade_print("PA: entrada de {$month}", $built['counts']);
            paridade_print("PA: motor em {$month} (last_pa vazio no 1o dia, como o script)", paridade_pa_counts($result));
            if (isset($options['mensal'])) {
                paridade_print("PA: motor x aba PA DIARIO de {$month}", paridade_pa_compare(paridade_xlsx_sheets($options['mensal']), $month, $result, $built['keys'], $match, array_column($built['input']['seats'], 'pa')));
            }
        }
        if (isset($options['detalhe'])) {
            $provisional = [];
            foreach ($detail as [$who, $date, $motive]) {
                $label = is_int($who) ? "ID {$who}" : '#' . ($provisional[$who] ??= count($provisional) + 1) . ' (sem ID)';
                echo "  {$label} {$date}: {$motive}\n";
            }
        }
        return 0;
    } catch (ParidadeErro $e) {
        fwrite(STDERR, 'PARIDADE: ' . $e->getMessage() . "\n");
        return 2;
    }
}

if (!defined('PARIDADE_SEM_MAIN')) {
    exit(paridade_main($argv));
}
