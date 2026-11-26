<?php
declare(strict_types=1);

require_once __DIR__ . "/PaDistributionEngine.php";
require_once __DIR__ . "/PresenceEngine.php";
require_once __DIR__ . "/ScheduleRulePlanner.php";

final class EscalaBaseErro extends RuntimeException
{
}

/**
 * [F10] Leitor da planilha "Escala Home - Base" (xlsx: zip + zlib + SimpleXML, so
 * leitura, com limites; sem o ZipArchive), extraido da paridade do F10-1 para
 * a importacao e a paridade usarem o mesmo codigo. Nunca imprime nada.
 */
final class EscalaBaseWorkbook
{
    public const MAX_FILE_BYTES = 20971520;
    public const MAX_ENTRY_BYTES = 33554432;
    public const MAX_ENTRIES = 2000;
    private const NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    private const REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships";
    private const PKG_REL_NS = "http://schemas.openxmlformats.org/package/2006/relationships";


    /** Conteudo das entradas .xml e .rels do pacote: [nome => bytes]. */
    public static function zipEntries(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_FILE_BYTES) {
            throw new EscalaBaseErro('arquivo inexistente ou maior que 20 MB: ' . basename($path));
        }
        $data = (string)file_get_contents($path);
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false || strlen($data) - $eocd < 22) {
            throw new EscalaBaseErro('pacote zip invalido: ' . basename($path));
        }
        $end = unpack('vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset', substr($data, $eocd + 4, 16));
        if ($end['entries'] > self::MAX_ENTRIES || $end['cdOffset'] === 0xFFFFFFFF || $end['cdOffset'] + $end['cdSize'] > $eocd) {
            throw new EscalaBaseErro('pacote zip fora do limite ou zip64: ' . basename($path));
        }
        $out = [];
        $pos = $end['cdOffset'];
        $total = 0;
        for ($i = 0; $i < $end['entries']; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                throw new EscalaBaseErro('diretorio central invalido: ' . basename($path));
            }
            $h = unpack('vmethod', substr($data, $pos + 10, 2)) + unpack('VcompSize/Vsize/vnameLen/vextraLen/vcommentLen', substr($data, $pos + 20, 14)) + unpack('Voffset', substr($data, $pos + 42, 4));
            $name = substr($data, $pos + 46, $h['nameLen']);
            $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
            if (!preg_match('/\.(xml|rels)$/', $name)) {
                continue;
            }
            if ($h['size'] > self::MAX_ENTRY_BYTES || ($total += $h['size']) > 4 * self::MAX_ENTRY_BYTES || substr($data, $h['offset'], 4) !== "PK\x03\x04") {
                throw new EscalaBaseErro('entrada do zip fora do limite: ' . basename($path));
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
                throw new EscalaBaseErro("metodo de compressao {$h['method']} nao suportado");
            }
            if (!is_string($content) || strlen($content) !== $h['size']) {
                throw new EscalaBaseErro('entrada do zip corrompida: ' . basename($path));
            }
            $out[$name] = $content;
        }
        return $out;
    }

    public static function xml(string $content): SimpleXMLElement
    {
        if (stripos($content, '<!DOCTYPE') !== false) {
            throw new EscalaBaseErro('xml com DOCTYPE recusado');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new EscalaBaseErro('xml invalido no pacote');
        }
        return $xml;
    }

    public static function column(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + ord($ch) - 64;
        }
        return $n;
    }

    /** Abas do xlsx: [nome => [linha => [coluna => texto]]], linhas e colunas a partir de 1. */
    public static function sheets(string $path): array
    {
        $entries = self::zipEntries($path);
        foreach (['xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
            if (!isset($entries[$required])) {
                throw new EscalaBaseErro('xlsx sem ' . $required);
            }
        }
        $shared = [];
        if (isset($entries['xl/sharedStrings.xml'])) {
            foreach (self::xml($entries['xl/sharedStrings.xml'])->children(self::NS)->si as $si) {
                $dom = dom_import_simplexml($si);
                $text = '';
                foreach ($dom->getElementsByTagNameNS(self::NS, 't') as $t) {
                    $text .= $t->textContent;
                }
                $shared[] = $text;
            }
        }
        $targets = [];
        foreach (dom_import_simplexml(self::xml($entries['xl/_rels/workbook.xml.rels']))->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship') as $rel) {
            $target = ltrim($rel->getAttribute('Target'), '/');
            $targets[$rel->getAttribute('Id')] = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
        }
        $sheets = [];
        foreach (self::xml($entries['xl/workbook.xml'])->children(self::NS)->sheets->sheet as $sheet) {
            $rid = (string)$sheet->attributes(self::REL_NS)['id'];
            $file = $targets[$rid] ?? '';
            if (!isset($entries[$file])) {
                throw new EscalaBaseErro('aba sem arquivo no pacote');
            }
            $cells = [];
            foreach (self::xml($entries[$file])->children(self::NS)->sheetData->row as $row) {
                foreach ($row->children(self::NS)->c as $c) {
                    if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$c->attributes()['r'], $ref)) {
                        throw new EscalaBaseErro('referencia de celula invalida');
                    }
                    $type = (string)$c->attributes()['t'];
                    $children = $c->children(self::NS);
                    if ($type === 'inlineStr') {
                        $value = '';
                        foreach (dom_import_simplexml($c)->getElementsByTagNameNS(self::NS, 't') as $t) {
                            $value .= $t->textContent;
                        }
                    } elseif (!isset($children->v)) {
                        continue;
                    } elseif ($type === 's') {
                        $value = $shared[(int)$children->v] ?? '';
                    } else {
                        $value = (string)$children->v;
                    }
                    $cells[(int)$ref[2]][self::column($ref[1])] = $value;
                }
            }
            $sheets[(string)$sheet->attributes()['name']] = $cells;
        }
        return $sheets;
    }


    /** excel_date() do script: serial do Excel, dd/mm/aaaa, aaaa-mm-dd ou dd/mm/aa; null se nada servir. */
    public static function date(string $value): ?string
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
    public static function intOr(string $value, int $default): int
    {
        return ($value === '' || (float)$value == 0.0) ? $default : (int)(float)$value;
    }

    public static function weekday(string $date): int
    {
        return (int)gmdate('N', (int)strtotime($date . ' 00:00:00 UTC'));
    }


    public static function cell(array $sheet, int $row, int $col): string
    {
        return trim((string)($sheet[$row][$col] ?? ""));
    }

    public static function norm(?string $value): string
    {
        return PaDistributionEngine::normalizeName((string)$value);
    }

    public static function base(array $sheets): array
    {
        foreach (['CONFIG', 'EQUIPE', 'REGRAS FIXAS', 'EXCECOES', 'CONFIG PA', 'HISTORICO'] as $name) {
            if (!isset($sheets[$name])) {
                throw new EscalaBaseErro("a planilha nao tem a aba {$name}");
            }
        }
        $cfg = $sheets['CONFIG'];
        $config = [
            'anchor' => self::date(self::cell($cfg, 6, 2)) ?? '2026-10-01',
            'phase' => [
                'TIME 1' => self::intOr(self::cell($cfg, 10, 2), 0),
                'TIME 2' => self::intOr(self::cell($cfg, 11, 2), 1),
                'TIME 3' => self::intOr(self::cell($cfg, 12, 2), 2),
            ],
        ];
        $team = [];
        foreach ($sheets['EQUIPE'] as $r => $row) {
            $name = self::cell($sheets['EQUIPE'], $r, 2);
            if ($r < 4 || $name === '') {
                continue;
            }
            $team[] = [
                'name' => $name, 'key' => self::norm($name), 'active' => self::norm($row[1] ?? '') === 'SIM',
                'tower' => self::norm($row[3] ?? ''), 'mode' => self::norm($row[4] ?? ''), 'team' => self::norm($row[5] ?? ''),
                'common' => self::norm($row[6] ?? '') === 'SIM',
            ];
        }
        $rules = [];
        foreach ($sheets['REGRAS FIXAS'] as $r => $row) {
            $name = self::cell($sheets['REGRAS FIXAS'], $r, 2);
            if ($r < 4 || $name === '' || self::norm($row[1] ?? '') !== 'SIM') {
                continue;
            }
            $key = self::norm($name);
            $rules[$key]['type'] = self::norm($row[4] ?? '');
            $week = self::intOr(trim((string)($row[5] ?? '')), 1);
            for ($d = 0; $d < 5; $d++) {
                $rules[$key]['cycles'][$week][$d] = self::norm($row[6 + $d] ?? '');
            }
        }
        $exceptions = [];
        foreach ($sheets['EXCECOES'] as $r => $row) {
            $name = self::cell($sheets['EXCECOES'], $r, 2);
            $start = self::date(trim((string)($row[3] ?? '')));
            $end = self::date(trim((string)($row[4] ?? '')));
            $status = self::norm($row[5] ?? '');
            if ($r >= 4 && self::norm($row[1] ?? '') === 'SIM' && $name !== '' && $start !== null && $end !== null && in_array($status, ['HOME', 'PRESENCIAL', 'FERIAS'], true)) {
                $exceptions[] = ['key' => self::norm($name), 'start' => $start, 'end' => $end, 'status' => $status];
            }
        }
        $pas = [];
        foreach ($sheets['CONFIG PA'] as $r => $row) {
            $pa = trim((string)($row[1] ?? ''));
            if ($r < 4 || !is_numeric($pa) || self::norm($row[8] ?? '') !== 'SIM') {
                continue;
            }
            $pas[] = [
                'pa' => (int)(float)$pa, 'row' => self::norm($row[2] ?? ''), 'order' => is_numeric(trim((string)($row[3] ?? ''))) ? (int)(float)$row[3] : 0,
                'type' => self::norm($row[4] ?? ''), 'fixed' => trim((string)($row[5] ?? '')), 'loan' => self::norm($row[6] ?? '') === 'SIM',
                'prefs' => array_values(array_filter(array_map('trim', explode(';', (string)($row[7] ?? ''))), static fn (string $x): bool => $x !== '')),
            ];
        }
        $history = [];
        foreach ($sheets['HISTORICO'] as $r => $row) {
            $date = self::date(trim((string)($row[3] ?? '')));
            $name = trim((string)($row[4] ?? ''));
            if ($r < 2 || $date === null || $name === '') {
                continue;
            }
            $history[] = ['date' => $date, 'name' => $name, 'key' => self::norm($name), 'mode' => self::norm($row[5] ?? ''), 'team' => self::norm($row[6] ?? ''), 'status' => self::norm($row[7] ?? '')];
        }
        return [
            'config' => $config, 'team' => $team, 'rules' => $rules,
            'fixed_anchor' => self::date(self::cell($sheets['REGRAS FIXAS'], 4, 13)) ?? '2026-09-28',
            'exceptions' => $exceptions, 'pas' => $pas, 'history' => $history,
        ];
    }

    /** EQUIPE ativa por chave (load_team so le ATIVO? = SIM). */
    public static function activeTeam(array $base): array
    {
        $out = [];
        foreach ($base['team'] as $person) {
            if ($person['active']) {
                $out[$person['key']] = $person;
            }
        }
        return $out;
    }

    public static function isCommon(array $person): bool
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
    public static function portalRule(array $base, array $person): ?array
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
                    $monday = gmdate('Y-m-d', (int)strtotime($anchor . ' UTC') + ((8 - self::weekday($anchor)) % 7) * 86400);
                    return $rule('cycle', ['pattern' => $pattern, 'anchor_date' => $monday]);
                }
                $week = $rec['cycles'][1] ?? [];
                $keys = ['mon', 'tue', 'wed', 'thu', 'fri'];
                return $rule('fixed_weekdays', ['weekdays' => array_values(array_filter($keys, static fn (string $k, int $d): bool => $day($week, $d) === 'onsite', ARRAY_FILTER_USE_BOTH))]);
            default:
                return null;
        }
    }

    /**
     * A regra traduzida passa a valer no dia inicial: o ciclo ganha a ancora
     * no dia inicial (ou na segunda seguinte, no semana a semana) com o padrao
     * girado para dar os mesmos dias da ancora original (desenho 2.2).
     */
    public static function ruleFrom(array $rule, string $from): array
    {
        if ($rule['rule_type'] !== 'cycle') {
            return ['effective_from' => $from] + $rule;
        }
        $pattern = $rule['rule_config']['pattern'];
        $size = count($pattern);
        $anchor = $from;
        $weekly = $size % 5 === 0 && $size > 3;
        if ($weekly) {
            while (self::weekday($anchor) !== 1) {
                $anchor = ScheduleRulePlanner::addDays($anchor, 1);
            }
        } else {
            while (!PresenceEngine::isBusinessDay($anchor)) {
                $anchor = ScheduleRulePlanner::addDays($anchor, 1);
            }
        }
        $offset = PresenceEngine::cyclePosition($rule['rule_config']['anchor_date'], $anchor, $size);
        $rotated = array_merge(array_slice($pattern, $offset), array_slice($pattern, 0, $offset));
        return ['rule_type' => 'cycle', 'rule_config' => ['pattern' => $rotated, 'anchor_date' => $anchor], 'effective_from' => $anchor, 'effective_until' => null];
    }
}
