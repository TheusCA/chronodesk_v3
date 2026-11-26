<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxWriter.php';

/**
 * [F10-6] O mes publicado em .xlsx com as 6 abas do script V17 (desenho do
 * F10, 1.5 e 3.7; D-F10-9 (a)): ESCALA, TODOS, RESUMO, PA DIARIO, MAPA PA e
 * RESUMO PA, no visual da planilha (cabecalho azul-marinho, presencial azul
 * claro, home verde claro, ferias e ausencia cinza, alerta vermelho claro),
 * pelo XlsxWriter (sem o ZipArchive). Valores so como texto ou numero, nunca
 * formula. Sem categoria de ausencia: ausente sai "AUSENTE" (6.4). O nome e o
 * do cadastro; no PA DIARIO e no MAPA PA, o primeiro nome (com a inicial do
 * sobrenome quando o primeiro nome se repete, D-F10-52).
 */
final class MonthlyScheduleSheet
{
    private const NAVY = '1F4E79';
    private const MID = '5B9BD5';
    private const LIGHT = 'D9EAF7';
    private const HOME = 'E2F0D9';
    private const GRAY = 'E7E6E6';
    private const YELLOW = 'FFF2CC';
    private const RED = 'F4CCCC';
    private const WEEKDAYS = ['SEG', 'TER', 'QUA', 'QUI', 'SEX'];
    private const STATUS = ['onsite' => 'PRESENCIAL', 'remote' => 'HOME', 'absent' => 'AUSENTE'];

    public static function fileName(string $month): string
    {
        return 'Escala do mes ' . substr($month, 5, 2) . '-' . substr($month, 0, 4) . '.xlsx';
    }

    public static function build(array $month): string
    {
        $suffix = substr($month['mes'], 5, 2) . '-' . substr($month['mes'], 0, 4);
        $writer = new XlsxWriter();
        $common = array_values(array_filter($month['people'], static fn (array $p): bool => $p['common']));
        usort($common, static fn (array $a, array $b): int => [(string)$a['rotation_team'], $a['name']] <=> [(string)$b['rotation_team'], $b['name']]);
        $all = $month['people'];
        usort($all, static fn (array $a, array $b): int => [$a['tower'], $a['name']] <=> [$b['tower'], $b['name']]);
        $writer->addSheet('ESCALA ' . $suffix, ...self::grid($month, $common, true));
        $writer->addSheet('TODOS ' . $suffix, ...self::grid($month, $all, false));
        $writer->addSheet('RESUMO ' . $suffix, ...self::summary($month));
        $writer->addSheet('PA DIARIO ' . $suffix, ...self::paDaily($month));
        $writer->addSheet('MAPA PA ' . $suffix, ...self::paMap($month));
        $writer->addSheet('RESUMO PA ' . $suffix, ...self::paSummary($month));
        return $writer->build();
    }

    /** ESCALA (grupo comum, com o total de HOME e os indicadores) e TODOS. */
    private static function grid(array $month, array $people, bool $withIndicators): array
    {
        $head = ['bold' => true, 'color' => 'FFFFFF', 'fill' => self::NAVY, 'border' => 'black', 'center' => true];
        $rows = [array_merge(array_map(static fn (string $t): array => ['v' => $t, 's' => $head], ['NOME', 'MODO', 'TIME', 'TORRE']),
            array_map(static fn (string $d): array => ['v' => self::WEEKDAYS[(int)(new DateTimeImmutable($d))->format('N') - 1], 's' => $head], $month['days']),
            $withIndicators ? [['v' => 'HOME NO MÊS', 's' => $head]] : [])];
        $rows[] = array_merge(array_fill(0, 4, ['v' => '', 's' => $head]),
            array_map(static fn (string $d): array => ['v' => self::date($d) . (in_array($d, $month['holidays'], true) ? ' (feriado)' : ''), 's' => $head], $month['days']));
        foreach ($people as $person) {
            $line = [(string)$person['name'], $person['mode'], (string)($person['rotation_team'] ?? ''), $person['tower']];
            $home = 0;
            foreach ($month['days'] as $day) {
                $cell = $month['cells'][$person['id'] . ':' . $day] ?? null;
                $status = $cell === null ? ($person['common'] ? 'SEM ESCALA' : 'OUTRA ESCALA') : self::STATUS[$cell['situation']];
                $home += $status === 'HOME' ? 1 : 0;
                $line[] = ['v' => $status, 's' => ['fill' => self::statusFill($status), 'border' => 'black', 'center' => true]];
            }
            if ($withIndicators) {
                $line[] = ['v' => $home, 's' => ['bold' => true, 'center' => true, 'border' => 'black']];
            }
            $rows[] = $line;
        }
        if ($withIndicators) {
            $rows[] = [];
            foreach (self::indicatorRows($month) as $label => $values) {
                $rows[] = array_merge([['v' => $label, 's' => ['bold' => true, 'fill' => self::LIGHT, 'border' => 'black']], '', '', ''],
                    array_map(static fn ($v): array => ['v' => $v, 's' => ['center' => true, 'border' => 'black'] + (is_string($v) && $v !== 'OK' ? ['fill' => self::RED, 'bold' => true] : [])], $values));
            }
        }
        $cols = array_merge([28, 14, 10, 8], array_fill(0, count($month['days']), 13), $withIndicators ? [12] : []);
        return [$rows, ['cols' => $cols]];
    }

    /** Indicadores por dia, na ordem da aba ESCALA do script (linhas 1088-1132). */
    private static function indicatorRows(array $month): array
    {
        $teams = [];
        foreach ($month['summary'] as $day) {
            foreach (array_keys($day['home_by_team'] ?? []) as $team) {
                $teams[$team] = true;
            }
        }
        ksort($teams);
        $rows = ['PRESENCIAIS COMUNS' => [], 'HOME COMUNS' => [], '% PRESENCIAL' => [], 'N1 PRESENCIAL' => [], 'N2 PRESENCIAL' => [], 'PAs COMUNS' => []];
        foreach (array_keys($teams) as $team) {
            $rows[strtoupper($team) . ' HOME'] = [];
        }
        $rows += ['HÍBRIDO PRESENCIAL' => [], 'CRÍTICO PRESENCIAL' => [], 'TOTAL PRESENCIAL + H/C' => [], 'SEM PA' => [], 'TROCAS' => [], 'STATUS' => []];
        foreach ($month['days'] as $date) {
            $day = $month['summary'][$date] ?? null;
            $rows['PRESENCIAIS COMUNS'][] = $day['onsite'] ?? '';
            $rows['HOME COMUNS'][] = $day['remote'] ?? '';
            $rows['% PRESENCIAL'][] = isset($day['percent_onsite']) ? $day['percent_onsite'] . '%' : '';
            $rows['N1 PRESENCIAL'][] = $day['n1'] ?? '';
            $rows['N2 PRESENCIAL'][] = $day['n2'] ?? '';
            $rows['PAs COMUNS'][] = $day['capacity'] ?? '';
            foreach (array_keys($teams) as $team) {
                $rows[strtoupper($team) . ' HOME'][] = $day['home_by_team'][$team] ?? 0;
            }
            $rows['HÍBRIDO PRESENCIAL'][] = $day['hybrid'] ?? '';
            $rows['CRÍTICO PRESENCIAL'][] = $day['critical'] ?? '';
            $rows['TOTAL PRESENCIAL + H/C'][] = $day === null ? '' : $day['onsite'] + $day['hybrid'] + $day['critical'];
            $rows['SEM PA'][] = $day['no_pa'] ?? '';
            $rows['TROCAS'][] = $day['moves'] ?? '';
            $rows['STATUS'][] = $day === null ? '' : implode(' | ', $day['status']);
        }
        return $rows;
    }

    /** RESUMO: uma linha por dia com os indicadores e o STATUS. */
    private static function summary(array $month): array
    {
        $indicators = self::indicatorRows($month);
        $head = ['bold' => true, 'color' => 'FFFFFF', 'fill' => self::NAVY, 'border' => 'black', 'center' => true, 'wrap' => true];
        $rows = [array_merge([['v' => 'DATA', 's' => $head], ['v' => 'DIA', 's' => $head]], array_map(static fn (string $l): array => ['v' => $l, 's' => $head], array_keys($indicators)))];
        foreach ($month['days'] as $i => $date) {
            $line = [self::date($date), self::WEEKDAYS[(int)(new DateTimeImmutable($date))->format('N') - 1]];
            foreach ($indicators as $values) {
                $v = $values[$i];
                $line[] = ['v' => $v, 's' => ['center' => true, 'border' => 'black'] + (is_string($v) && $v !== 'OK' && !str_ends_with($v, '%') && $v !== '' ? ['fill' => self::RED, 'bold' => true] : [])];
            }
            $rows[] = $line;
        }
        return [$rows, ['cols' => array_merge([12, 6], array_fill(0, count($indicators), 13))]];
    }

    /** PA DIARIO: PA x dia com o ocupante (primeiro nome) ou LIVRE; TROCAS e SEM PA. */
    private static function paDaily(array $month): array
    {
        $occupants = self::occupants($month);
        $short = self::shortNames($month['people']);
        $head = ['bold' => true, 'color' => 'FFFFFF', 'fill' => self::NAVY, 'border' => 'black', 'center' => true];
        $rows = [array_merge([['v' => 'PA', 's' => $head], ['v' => 'FILEIRA', 's' => $head]], array_map(static fn (string $d): array => ['v' => self::date($d), 's' => $head], $month['days']))];
        foreach ($month['seats'] as $seat) {
            $line = [$seat['pa'], $seat['row'] === 1 ? 'SUPERIOR' : ($seat['row'] === 2 ? 'INFERIOR' : 'FILEIRA ' . $seat['row'])];
            foreach ($month['days'] as $day) {
                $id = $occupants[$day][$seat['pa']] ?? null;
                $line[] = ['v' => !$seat['active'] ? 'BLOQUEADO' : ($id === null ? ($seat['reserved'] ? 'RESERVADO' : 'LIVRE') : $short[$id]),
                    's' => ['center' => true, 'border' => 'black', 'fill' => $id === null ? self::GRAY : self::LIGHT]];
            }
            $rows[] = $line;
        }
        $moves = ['TROCAS FLEXÍVEIS', ''];
        $noPa = ['SEM PA', ''];
        foreach ($month['days'] as $day) {
            $moves[] = $month['summary'][$day]['moves'] ?? 0;
            $names = [];
            foreach ($month['cells'] as $key => $cell) {
                [$id, $date] = explode(':', $key);
                if ($date === $day && $cell['no_pa']) {
                    $names[] = $short[(int)$id];
                }
            }
            $noPa[] = ['v' => implode(', ', $names), 's' => $names === [] ? [] : ['fill' => self::RED, 'bold' => true]];
        }
        $rows[] = $moves;
        $rows[] = $noPa;
        return [$rows, ['cols' => array_merge([8, 11], array_fill(0, count($month['days']), 12))]];
    }

    /** MAPA PA: por semana e dia, as fileiras com o ocupante. */
    private static function paMap(array $month): array
    {
        $occupants = self::occupants($month);
        $short = self::shortNames($month['people']);
        $byRow = [];
        foreach ($month['seats'] as $seat) {
            $byRow[$seat['row']][] = $seat;
        }
        ksort($byRow);
        $rows = [];
        $width = max(array_map('count', $byRow ?: [[]]));
        foreach ($month['days'] as $day) {
            $rows[] = [['v' => self::WEEKDAYS[(int)(new DateTimeImmutable($day))->format('N') - 1] . ' ' . self::date($day), 's' => ['bold' => true, 'color' => 'FFFFFF', 'fill' => self::NAVY]]];
            foreach ($byRow as $seats) {
                $numbers = [''];
                $names = [''];
                foreach ($seats as $seat) {
                    $id = $occupants[$day][$seat['pa']] ?? null;
                    $numbers[] = ['v' => 'PA ' . $seat['pa'], 's' => ['bold' => true, 'center' => true, 'fill' => self::MID, 'color' => 'FFFFFF', 'border' => 'black']];
                    $names[] = ['v' => $id === null ? ($seat['reserved'] ? 'RESERVADO' : 'LIVRE') : $short[$id], 's' => ['center' => true, 'border' => 'black', 'fill' => $id === null ? self::GRAY : self::YELLOW]];
                }
                $rows[] = $numbers;
                $rows[] = $names;
            }
            $rows[] = [];
        }
        return [$rows, ['cols' => array_merge([4], array_fill(0, $width, 13))]];
    }

    /** RESUMO PA: por PA, EXCLUSIVO, COMPARTILHADO, ROTATIVO ou LIVRE, com os nomes (sem o corte de 8). */
    private static function paSummary(array $month): array
    {
        $occupants = self::occupants($month);
        $short = self::shortNames($month['people']);
        $head = ['bold' => true, 'color' => 'FFFFFF', 'fill' => self::NAVY, 'border' => 'black', 'center' => true];
        $rows = [[['v' => 'RESUMO MENSAL DE PAs - ' . substr($month['mes'], 5, 2) . '/' . substr($month['mes'], 0, 4), 's' => ['bold' => true, 'color' => self::NAVY]]],
            array_map(static fn (string $t): array => ['v' => $t, 's' => $head], ['PA', 'FILEIRA', 'USO', 'DIAS OCUPADO', 'OCUPANTES'])];
        foreach ($month['seats'] as $seat) {
            $ids = [];
            $busy = 0;
            foreach ($month['days'] as $day) {
                $id = $occupants[$day][$seat['pa']] ?? null;
                if ($id !== null) {
                    $busy++;
                    $ids[$id] = true;
                }
            }
            $use = match (true) {
                count($ids) === 0 => 'LIVRE',
                count($ids) === 1 => 'EXCLUSIVO',
                count($ids) === 2 => 'COMPARTILHADO',
                default => 'ROTATIVO',
            };
            $names = array_map(static fn (int $id): string => $short[$id], array_keys($ids));
            sort($names);
            $rows[] = [$seat['pa'], $seat['row'] === 1 ? 'SUPERIOR' : ($seat['row'] === 2 ? 'INFERIOR' : 'FILEIRA ' . $seat['row']),
                ['v' => $use, 's' => ['bold' => true, 'fill' => $use === 'LIVRE' ? self::GRAY : self::LIGHT]], $busy, implode(', ', $names)];
        }
        return [$rows, ['cols' => [8, 11, 16, 14, 60]]];
    }

    /** [dia => [pa => employee_id]] das celulas com PA. */
    private static function occupants(array $month): array
    {
        $result = [];
        foreach ($month['cells'] as $key => $cell) {
            if ($cell['pa'] !== null) {
                [$id, $day] = explode(':', $key);
                $result[$day][(string)$cell['pa']] = (int)$id;
            }
        }
        return $result;
    }

    /**
     * [D-F10-52] Primeiro nome do cadastro; quando o primeiro nome se repete,
     * com a inicial do ultimo sobrenome (sem nomes no codigo, ao contrario do
     * short_pa_name do script).
     */
    public static function shortNames(array $people): array
    {
        $first = [];
        foreach ($people as $person) {
            $parts = preg_split('/\s+/u', trim((string)$person['name'])) ?: [''];
            $first[$person['id']] = [$parts[0], count($parts) > 1 ? end($parts) : ''];
        }
        $counts = array_count_values(array_map(static fn (array $p): string => strtolower($p[0]), $first));
        $result = [];
        foreach ($first as $id => [$name, $last]) {
            $result[$id] = $counts[strtolower($name)] > 1 && $last !== '' && preg_match('/^./u', $last, $initial) === 1 ? $name . ' ' . $initial[0] . '.' : $name;
        }
        return $result;
    }

    private static function statusFill(string $status): string
    {
        return ['PRESENCIAL' => self::LIGHT, 'HOME' => self::HOME, 'AUSENTE' => self::GRAY, 'OUTRA ESCALA' => self::GRAY][$status] ?? self::RED;
    }

    private static function date(string $date): string
    {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }
}
