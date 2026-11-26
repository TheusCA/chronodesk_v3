<?php
declare(strict_types=1);

require_once __DIR__ . '/PaDistributionEngine.php';
require_once __DIR__ . '/PortalTeams.php';

/**
 * [F10-4] Nucleo puro da escala do mes (desenho do F10, 3.5, 3.6 e 8.9): sem
 * banco, arquivo nem relogio. O MonthlyScheduleService carrega as entradas e
 * grava; aqui ficam os dias, a entrada do PaDistributionEngine, a grade, os
 * indicadores do RESUMO do script, o hash das entradas e a divergencia.
 *
 * Entrada de compute():
 *   month       'AAAA-MM';
 *   from        primeiro dia calculado (Y-m-d, dentro do mes);
 *   presence    [['employee_id', 'name', 'team', 'date', 'presence_type'
 *               (onsite|remote|absence|no_schedule), 'source', 'papel'?], ...]
 *               (OperationalService::monthPresence, sem feriado);
 *   people      [employee_id => ['grupo' => 'comum'|..., 'rotation_team' => ?nome]]
 *               (grupo da escala e o time de rodizio na data, por pessoa; o
 *               time por data vem em rotation [data][id] => nome);
 *   rotation    [data => [employee_id => nome do time]] (opcional);
 *   seats       a lista do motor (PaDistributionEngine, 'seats');
 *   preferences [pa => [employee_id, ...]];
 *   locks       [data => [['pa' => int, 'employee_id' => int], ...]];
 *   last_pa     [employee_id => pa] do ultimo dia util publicado antes de from.
 */
final class MonthlySchedulePlanner
{
    public const VERSION = 'f10-mes-1';
    public const STATUS_OK = 'OK';

    private const SITUATION = ['onsite' => 'onsite', 'remote' => 'remote', 'absence' => 'absent'];
    private const SOURCE = ['rule' => 'rule', 'exception' => 'exception', 'absence' => 'absence', 'implicit' => 'implicit', 'role' => 'role', 'none' => 'none'];

    /** Dias uteis (seg a sex) do mes a partir de $from; o feriado conta (D-F10-4 a). */
    public static function businessDays(string $month, ?string $from = null): array
    {
        $first = self::monthStart($month);
        $start = $from ?? $first;
        if (substr($start, 0, 7) !== $month) {
            throw new InvalidArgumentException('Data inicial fora do mês.');
        }
        $days = [];
        $date = new DateTimeImmutable($first);
        $end = $date->modify('last day of this month');
        for (; $date <= $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            if ((int)$date->format('N') <= 5 && $key >= $start) {
                $days[] = $key;
            }
        }
        return $days;
    }

    /** 'AAAA-MM' valido -> 'AAAA-MM-01'. */
    public static function monthStart(mixed $month): string
    {
        if (!is_string($month) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) !== 1 || (int)$m[1] < 2020 || (int)$m[1] > 2100) {
            throw new InvalidArgumentException('Mês inválido: use AAAA-MM.');
        }
        return $month . '-01';
    }

    /**
     * Primeiro dia que uma publicacao pode gravar (D-F10-2): mes passado nao;
     * 1a publicacao de mes futuro, o 1o dia util do mes; senao o proximo dia
     * util depois de hoje (o passado nunca e reescrito). null = mes passado.
     */
    public static function earliestFrom(string $month, string $today): ?string
    {
        $days = self::businessDays($month);
        foreach ($days as $day) {
            if ($day > $today) {
                return $day;
            }
        }
        return null;
    }

    public static function compute(array $input): array
    {
        $month = (string)($input['month'] ?? '');
        $days = self::businessDays($month, $input['from'] ?? null);
        $people = $input['people'] ?? [];
        $rotation = $input['rotation'] ?? [];

        // Presenca por dia e pessoa (so dias uteis calculados).
        $wanted = array_flip($days);
        $rows = [];
        $names = [];
        $teams = [];
        foreach ($input['presence'] ?? [] as $row) {
            $date = (string)$row['date'];
            if (!isset($wanted[$date])) {
                continue;
            }
            $id = (int)$row['employee_id'];
            $rows[$date][$id] = $row;
            $names[$id] = (string)($row['name'] ?? '');
            $teams[$id] = PortalTeams::normalize((string)($row['team'] ?? ''));
        }
        ksort($names);

        // Grupo comum: N1/N2 do grupo 'comum' (quem nao veio no people fica fora).
        $common = [];
        foreach ($names as $id => $name) {
            if (PortalTeams::inCommonGroup($teams[$id]) && (($people[$id]['grupo'] ?? null) === 'comum')) {
                $common[$id] = $name;
            }
        }
        $presence = [];
        $reserved = [];
        $config = [];
        foreach ($days as $date) {
            $critical = [];
            foreach ($rows[$date] ?? [] as $id => $row) {
                $type = (string)$row['presence_type'];
                if (isset(self::SITUATION[$type])) {
                    $presence[$date][$id] = self::SITUATION[$type];
                } elseif (isset($common[$id])) {
                    $config[$date][] = $id;
                }
                if ($teams[$id] === PortalTeams::CRITICAL && $type === 'onsite') {
                    $critical[] = ['employee_id' => $id, 'name' => $names[$id], 'role_order' => $row['papel']['sort_order'] ?? null, 'role_id' => $row['papel']['id'] ?? null];
                }
            }
            $reserved[$date] = ['critical' => PaDistributionEngine::orderReservedPeople($critical)];
        }
        $result = PaDistributionEngine::distribute([
            'days' => $days,
            'seats' => $input['seats'] ?? [],
            'people' => $common,
            'presence' => $presence,
            'preferences' => $input['preferences'] ?? [],
            'locks' => $input['locks'] ?? [],
            'last_pa' => $input['last_pa'] ?? [],
            'reserved_people' => $reserved,
        ]);

        $entries = [];
        $summary = [];
        foreach ($days as $date) {
            $day = $result['days'][$date];
            $seatOf = [];
            foreach ($day['occupants'] as $pa => $seat) {
                if (($seat['employee_id'] ?? null) !== null) {
                    $seatOf[(int)$seat['employee_id']] = ['pa' => (string)$pa, 'source' => (string)$seat['source']];
                }
            }
            $noPa = array_flip($day['no_pa']);
            $moved = array_flip($day['moved']);
            $counts = ['onsite' => 0, 'remote' => 0, 'absent' => 0, 'n1' => 0, 'n2' => 0, 'hybrid' => 0, 'critical' => 0, 'home_by_team' => []];
            foreach ($rows[$date] ?? [] as $id => $row) {
                $type = (string)$row['presence_type'];
                if (!isset(self::SITUATION[$type])) {
                    continue;
                }
                $situation = self::SITUATION[$type];
                $seat = $seatOf[$id] ?? null;
                $source = self::SOURCE[(string)$row['source']] ?? 'none';
                $team = $rotation[$date][$id] ?? null;
                if ($source === 'rule' && $team !== null) {
                    $source = 'team';
                }
                $entries[] = [
                    'work_date' => $date,
                    'employee_id' => $id,
                    'situation' => $situation,
                    'source' => $source,
                    'team_snapshot' => $teams[$id],
                    'pa_number' => $seat['pa'] ?? null,
                    'pa_source' => $seat['source'] ?? null,
                    'no_pa' => isset($noPa[$id]) ? 1 : 0,
                    'moved' => isset($moved[$id]) ? 1 : 0,
                ];
                if (isset($common[$id])) {
                    $counts[$situation]++;
                    if ($situation === 'onsite' && in_array($teams[$id], [PortalTeams::N1, PortalTeams::N2], true)) {
                        $counts[$teams[$id]]++;
                    }
                    if ($situation === 'remote' && $team !== null) {
                        $counts['home_by_team'][$team] = ($counts['home_by_team'][$team] ?? 0) + 1;
                    }
                } elseif ($situation === 'onsite' && $teams[$id] === PortalTeams::HYBRID) {
                    $counts['hybrid']++;
                } elseif ($situation === 'onsite' && $teams[$id] === PortalTeams::CRITICAL) {
                    $counts['critical']++;
                }
            }
            ksort($counts['home_by_team']);
            // Quem do grupo comum esta sem regra nao tem presenca no motor:
            // o alerta presence_missing ja da o ATENCAO CONFIG (desenho 3.5).
            $status = $day['status'];
            $base = $counts['onsite'] + $counts['remote'];
            $summary[$date] = $counts + [
                'percent_onsite' => $base === 0 ? null : (int)round(100 * $counts['onsite'] / $base),
                'capacity' => (int)$day['capacity'],
                'reserved_seats' => self::reservedSeats($input['seats'] ?? []),
                'moves' => count($day['moved']),
                'no_pa' => count($day['no_pa']),
                'without_schedule' => count($config[$date] ?? []),
                'method' => $day['method'],
                'alerts' => array_values(array_unique(array_map(static fn (array $a): string => (string)$a['code'], $day['alerts']))),
                'status' => $status === [] ? [self::STATUS_OK] : $status,
            ];
        }
        return [
            'month' => $month,
            'days' => $days,
            'engine_version' => $result['engine_version'],
            'entries' => $entries,
            'summary' => $summary,
            'last_pa' => $result['last_pa'],
            'common' => array_keys($common),
            'counts' => self::counts($entries, $summary),
            'input_hash' => self::inputHash($input, $days),
        ];
    }

    /**
     * SHA-256 da forma canonica das entradas (desenho 3.5): presenca, grupo,
     * times, assentos, preferencias, travas, last_pa e as versoes do calculo.
     * O nome entra (ordena o motor); a categoria e a observacao nunca vem.
     */
    public static function inputHash(array $input, array $days): string
    {
        $presence = [];
        foreach ($input['presence'] ?? [] as $row) {
            if (in_array((string)$row['date'], $days, true)) {
                $presence[] = [(string)$row['date'], (int)$row['employee_id'], (string)($row['name'] ?? ''), (string)($row['team'] ?? ''),
                    (string)$row['presence_type'], (string)$row['source'], $row['papel']['id'] ?? null, $row['papel']['sort_order'] ?? null];
            }
        }
        sort($presence);
        $canonical = [
            'versions' => [self::VERSION, PaDistributionEngine::VERSION],
            'month' => $input['month'] ?? null,
            'days' => $days,
            'presence' => $presence,
            'people' => self::sorted($input['people'] ?? []),
            'rotation' => self::sorted($input['rotation'] ?? []),
            'seats' => $input['seats'] ?? [],
            'preferences' => self::sorted($input['preferences'] ?? []),
            'locks' => self::sorted($input['locks'] ?? []),
            'last_pa' => self::sorted($input['last_pa'] ?? []),
        ];
        return hash('sha256', (string)json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Divergencia da escala publicada com a presenca de hoje (D-F10-14): a
     * situacao mudou (ausencia ou excecao nova depois de publicar) ou a pessoa
     * ausente segue com PA. Devolve [employee_id:data => motivo].
     */
    public static function divergences(array $published, array $current): array
    {
        $now = [];
        foreach ($current as $row) {
            $now[(int)$row['employee_id'] . ':' . $row['date']] = self::SITUATION[(string)$row['presence_type']] ?? 'sem_escala';
        }
        $result = [];
        foreach ($published as $entry) {
            $key = (int)$entry['employee_id'] . ':' . $entry['work_date'];
            $today = $now[$key] ?? 'fora';
            if ($today !== $entry['situation']) {
                $result[$key] = $today === 'absent' && $entry['pa_number'] !== null ? 'ausente_com_pa' : 'situacao_mudou';
            }
        }
        ksort($result);
        return $result;
    }

    /** Contagens da revisao (so numeros): vai para o JSON da revisao e para a auditoria. */
    public static function counts(array $entries, array $summary): array
    {
        $statusDays = ['ok' => 0, 'pa_insuficiente' => 0, 'atencao_config' => 0];
        foreach ($summary as $day) {
            if ($day['status'] === [self::STATUS_OK]) {
                $statusDays['ok']++;
            }
            $statusDays['pa_insuficiente'] += in_array(PaDistributionEngine::STATUS_NO_PA, $day['status'], true) ? 1 : 0;
            $statusDays['atencao_config'] += in_array(PaDistributionEngine::STATUS_CONFIG, $day['status'], true) ? 1 : 0;
        }
        return [
            'dias' => count($summary),
            'linhas' => count($entries),
            'pessoas' => count(array_unique(array_column($entries, 'employee_id'))),
            'presenciais' => count(array_filter($entries, static fn (array $e): bool => $e['situation'] === 'onsite')),
            'com_pa' => count(array_filter($entries, static fn (array $e): bool => $e['pa_number'] !== null)),
            'sem_pa' => array_sum(array_column($entries, 'no_pa')),
            'trocas' => array_sum(array_column($entries, 'moved')),
        ] + $statusDays;
    }

    /** Modo da planilha (aba ESCALA e TODOS) pela regra do motor. */
    public static function modeLabel(?string $team, ?string $ruleType, ?string $rotationTeam): string
    {
        return match (true) {
            $team === PortalTeams::CRITICAL => 'CRÍTICO',
            $team === PortalTeams::HYBRID => 'HÍBRIDO',
            $team === PortalTeams::LEADERSHIP => 'LIDERANÇA',
            $rotationTeam !== null => 'RODÍZIO',
            $ruleType === 'always_onsite' => 'SEM HOME',
            $ruleType === 'always_remote' => 'FULL HOME',
            $ruleType === 'cycle' || $ruleType === 'fixed_weekdays' => 'FIXO',
            default => 'SEM REGRA',
        };
    }

    /** Situacao do contrato paridade-escala-v1 (desenho 8.1). */
    public static function paritySituation(string $presenceType): string
    {
        return ['onsite' => 'presencial', 'remote' => 'remoto', 'absence' => 'ausente'][$presenceType] ?? 'sem_escala';
    }

    /** Fonte do contrato paridade-escala-v1. */
    public static function paritySource(string $source): string
    {
        return ['rule' => 'regra', 'exception' => 'excecao', 'absence' => 'ausencia', 'implicit' => 'implicita', 'role' => 'regra'][$source] ?? 'nenhuma';
    }

    private static function reservedSeats(array $seats): int
    {
        return count(array_filter($seats, static fn (array $s): bool => ($s['reserved_for'] ?? null) !== null && ($s['active'] ?? false) && !($s['unavailable'] ?? false)));
    }

    private static function sorted(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_is_list($item) ? $item : self::sorted($item);
            }
        }
        return $value;
    }
}
