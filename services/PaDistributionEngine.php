<?php
declare(strict_types=1);

/**
 * Motor puro de distribuicao de PA do F10 (desenho F10, 3.4; lote F10-1).
 *
 * Sem banco, arquivo nem relogio, como o PresenceEngine: recebe tudo ja
 * carregado e devolve a distribuicao de cada dia. Deterministico: a mesma
 * entrada da sempre a mesma saida.
 *
 * Regra do script da planilha "Escala Home" (build_pa_assignments), igual:
 *   - PA fixo: dono presente senta nele; dono ausente: o PA so entra na lista
 *     do dia se puder emprestar; o reservado (Hibrido/Critico) sempre ocupado;
 *   - pessoas do grupo comum presenciais sem PA fixo, pelo nome normalizado;
 *     PAs pela ordem (fileira, coluna, numero);
 *   - custo 0 para o PA do dia util anterior (last_pa), 2 para preferencia e
 *     12 + |j - i| para o resto;
 *   - cabem todos: programacao dinamica exata, desempate pelo primeiro PA da
 *     ordem (o "<" estrito do script);
 *   - falta PA: caminho guloso (pontos 0/2/12, desempate pelo nome) e quem
 *     sobra fica "sem PA";
 *   - troca: quem tinha last_pa e mudou de PA; o dono fixo nao conta troca.
 *
 * Diferencas aprovadas (desenho 3.4, D-F10-7, D-F10-13, D-F10-17):
 *   - identidade por employee_id; o nome so ordena, com o ID no desempate;
 *   - last_pa inicial pela entrada (o do ultimo dia publicado; vazio no 1o mes);
 *   - travas manuais por dia, antes do calculo; trava sem efeito vira alerta;
 *   - PA inativo ou indisponivel nunca entra, nem por trava;
 *   - programacao dinamica ate DP_MAX_SEATS PAs livres no dia; acima, o guloso
 *     com alerta;
 *   - dono com mais de um PA fixo no dia: so o primeiro da ordem, com alerta.
 *
 * Entrada (InvalidArgumentException se a forma estiver errada):
 *   days        lista de datas Y-m-d em ordem crescente, sem repeticao;
 *   seats       lista de ['pa' => int, 'row' => int, 'column' => int,
 *               'type' => 'flexible'|'fixed', 'can_lend' => bool,
 *               'reserved_for' => null|'hybrid'|'critical' (so em fixed),
 *               'active' => bool, 'unavailable' => bool,
 *               'owners' => [['employee_id' => int, 'from' => ?Y-m-d,
 *               'until' => ?Y-m-d], ...]] (dono fixo com vigencia, inclusiva);
 *   people      [employee_id => nome] do grupo comum (o nome so ordena);
 *   presence    [data => [employee_id => 'onsite'|'remote'|'absent']], do
 *               grupo comum e dos donos fixos de fora dele; sem entrada = ausente
 *               (e alerta, se a pessoa for do grupo comum);
 *   preferences [pa => [employee_id, ...]];
 *   locks       [data => [['pa' => int, 'employee_id' => int], ...]];
 *   last_pa     [employee_id => pa] antes do 1o dia;
 *   reserved_people (opcional; F10-3, D-F10-19 revista) [data => [reserva =>
 *               [employee_id, ...]]]: quem da equipe da reserva esta
 *               presencial no dia, ja na ordem (orderReservedPeople: pelo
 *               papel do dia do 9.16). Com a entrada do dia e da reserva, os
 *               PAs reservados dela, na ordem, recebem essas pessoas
 *               (reservedSeating); PA sem pessoa fica reservado e vazio (nao
 *               empresta); pessoa sem PA vira o alerta reserved_short. Sem a
 *               entrada, o reservado fica ocupado sem pessoa, como no script.
 */
final class PaDistributionEngine
{
    public const VERSION = 'f10-pa-2';
    public const DP_MAX_SEATS = 16;
    public const COST_LAST_PA = 0;
    public const COST_PREFERENCE = 2;
    public const COST_OTHER = 12;

    public const STATUS_NO_PA = 'PA INSUFICIENTE';
    public const STATUS_CONFIG = 'ATENCAO CONFIG';

    /** Alertas que pedem conferencia da configuracao (status ATENCAO CONFIG). */
    private const CONFIG_ALERTS = ['presence_missing', 'lock_ignored', 'owner_overlap', 'owner_multiple_seats', 'reserved_short'];
    private const SEAT_TYPES = ['flexible', 'fixed'];
    private const RESERVED = ['hybrid', 'critical'];
    private const PRESENCE = ['onsite', 'remote', 'absent'];
    /** Troca de acento do norm() do script (linhas 38-46), depois da maiuscula. */
    private const SCRIPT_ACCENTS = [
        'Ã' => 'A', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ç' => 'C',
    ];

    /** @var array<string, string>|null */
    private static ?array $upperLatin1 = null;

    public static function distribute(array $input): array
    {
        $days = self::days($input['days'] ?? null);
        $seats = self::seats($input['seats'] ?? null);
        $people = self::people($input['people'] ?? []);
        $presence = self::presence($input['presence'] ?? []);
        $preferences = self::preferences($input['preferences'] ?? [], $seats);
        $locks = self::locks($input['locks'] ?? []);
        $lastPa = self::lastPa($input['last_pa'] ?? []);
        $reservedPeople = self::reservedPeople($input['reserved_people'] ?? [], $people);

        $result = [];
        foreach ($days as $date) {
            $result[$date] = self::day($date, $seats, $people, $presence[$date] ?? [], $preferences, $locks[$date] ?? [], $lastPa, $reservedPeople[$date] ?? null);
        }
        ksort($lastPa);
        return ['engine_version' => self::VERSION, 'days' => $result, 'last_pa' => $lastPa];
    }

    /**
     * O norm() do script: sem espaco nas pontas, maiusculas e sem os acentos
     * da lista dele. A maiuscula cobre ASCII e Latin-1 (portugues) sem
     * depender do mbstring, para dar o mesmo resultado em qualquer servidor;
     * outros caracteres ficam como estao.
     */
    public static function normalizeName(string $name): string
    {
        // Com /u o \s do PCRE do PHP cobre o espaco Unicode (NBSP inclusive), como o strip() do Python.
        $trimmed = (string)preg_replace('/^\s+|\s+$/u', '', $name);
        return strtr(strtr(strtoupper($trimmed), self::upperLatin1()), self::SCRIPT_ACCENTS);
    }

    /**
     * [F10-3, D-F10-19 revista] Assentos reservados de uma equipe no dia: os
     * PAs reservados, ja na ordem do inventario (fileira, coluna, numero), e
     * as pessoas da equipe presenciais no dia, ja na ordem (orderReservedPeople).
     * O 1o PA fica com a 1a pessoa, e assim por diante. Devolve
     * ['occupants' => [pa => employee_id|null], 'without_seat' => [ids]]:
     * PA sem pessoa fica null (reservado e vazio); pessoa sem PA vai para
     * without_seat. Regra unica do motor e do Mapa PA.
     */
    public static function reservedSeating(array $seatPas, array $orderedIds): array
    {
        $occupants = [];
        $ids = array_values($orderedIds);
        foreach (array_values($seatPas) as $index => $pa) {
            $occupants[$pa] = $ids[$index] ?? null;
        }
        return ['occupants' => $occupants, 'without_seat' => array_slice($ids, count($occupants))];
    }

    /**
     * [F10-3] Ordem de quem ocupa os assentos reservados: quem tem papel do
     * dia (9.16) primeiro, pela ordem do papel (sort_order e o ID do papel);
     * depois quem nao tem papel; no empate, o nome normalizado e o ID.
     * $people: lista de ['employee_id' => int, 'name' => string,
     * 'role_order' => ?int, 'role_id' => ?int]. Devolve a lista de IDs.
     */
    public static function orderReservedPeople(array $people): array
    {
        $keyed = [];
        foreach ($people as $person) {
            $role = $person['role_order'] ?? null;
            $keyed[] = [
                $role === null ? 1 : 0,
                (int)($role ?? 0),
                (int)($person['role_id'] ?? 0),
                self::normalizeName((string)($person['name'] ?? '')),
                (int)$person['employee_id'],
            ];
        }
        usort($keyed, static fn (array $a, array $b): int => $a <=> $b);
        return array_map(static fn (array $key): int => $key[4], $keyed);
    }

    // ------------------------------------------------------------------
    // Um dia
    // ------------------------------------------------------------------

    private static function day(string $date, array $seats, array $people, array $presence, array $preferences, array $locks, array &$lastPa, ?array $reservedToday = null): array
    {
        $occupants = [];
        $alerts = [];
        $available = [];
        $fixedUsed = [];
        $moved = [];
        $reservedSeats = [];

        // 1. PAs fixos e reservados; flexiveis e fixos que emprestam vao para a lista.
        foreach ($seats as $seat) {
            $pa = $seat['pa'];
            if (!$seat['active'] || $seat['unavailable']) {
                continue;
            }
            if ($seat['type'] === 'flexible') {
                $available[$pa] = true;
                continue;
            }
            if ($seat['reserved_for'] !== null) {
                $reservedSeats[$seat['reserved_for']][] = $pa;
                $occupants[$pa] = self::occupant(null, $seat['reserved_for'], 'reserved');
                continue;
            }
            $owner = self::ownerAt($seat, $date, $alerts);
            if ($owner !== null && ($presence[$owner] ?? null) === 'onsite') {
                if (!isset($fixedUsed[$owner])) {
                    $occupants[$pa] = self::occupant($owner, null, 'fixed');
                    $fixedUsed[$owner] = $pa;
                    if (isset($people[$owner])) {
                        $lastPa[$owner] = $pa;
                    }
                    continue;
                }
                $alerts[] = ['code' => 'owner_multiple_seats', 'pa' => $pa, 'employee_id' => $owner];
            }
            if ($seat['can_lend']) {
                $available[$pa] = true;
            }
        }
        $capacity = count($available);

        // 1b. [F10-3] Reservados com as pessoas da equipe no dia (papeis do 9.16).
        foreach ($reservedToday ?? [] as $team => $ids) {
            $seating = self::reservedSeating($reservedSeats[$team] ?? [], $ids);
            foreach ($seating['occupants'] as $pa => $id) {
                $occupants[$pa] = self::occupant($id, $team, 'reserved');
            }
            if ($seating['without_seat'] !== []) {
                $alerts[] = ['code' => 'reserved_short', 'reserved_for' => $team, 'count' => count($seating['without_seat'])];
            }
        }

        // 2. Grupo comum presencial ainda sem PA.
        $onsite = [];
        $onsiteTotal = 0;
        foreach ($people as $id => $key) {
            $state = $presence[$id] ?? null;
            if ($state === null) {
                $alerts[] = ['code' => 'presence_missing', 'employee_id' => $id];
                continue;
            }
            if ($state !== 'onsite') {
                continue;
            }
            $onsiteTotal++;
            if (!isset($fixedUsed[$id])) {
                $onsite[$id] = $key;
            }
        }

        // 3. Travas do dia, antes do calculo.
        $knownSeats = array_column($seats, 'pa', 'pa');
        $lockedSeats = [];
        $lockedPeople = [];
        foreach ($locks as $lock) {
            [$pa, $id] = [$lock['pa'], $lock['employee_id']];
            $reason = null;
            if (isset($lockedSeats[$pa])) {
                $reason = 'duplicate_seat';
            } elseif (isset($lockedPeople[$id])) {
                $reason = 'duplicate_person';
            } elseif (!isset($knownSeats[$pa])) {
                $reason = 'unknown_seat';
            } elseif (!isset($available[$pa])) {
                $reason = 'seat_not_available';
            } elseif (!isset($people[$id])) {
                $reason = 'person_not_common';
            } elseif (isset($fixedUsed[$id])) {
                $reason = 'person_has_fixed_seat';
            } elseif (!isset($onsite[$id])) {
                $reason = 'person_not_onsite';
            }
            if ($reason !== null) {
                $alerts[] = ['code' => 'lock_ignored', 'pa' => $pa, 'employee_id' => $id, 'reason' => $reason];
                continue;
            }
            $lockedSeats[$pa] = true;
            $lockedPeople[$id] = true;
            unset($available[$pa], $onsite[$id]);
            $occupants[$pa] = self::occupant($id, null, 'lock');
            self::move($id, $pa, $lastPa, $moved);
        }

        // 4. Distribuicao do resto.
        $ids = array_keys($onsite);
        usort($ids, static fn (int $a, int $b): int => [$onsite[$a], $a] <=> [$onsite[$b], $b]);
        $seatList = array_keys($available);
        $noPa = [];
        $method = 'none';
        if ($ids !== []) {
            if (count($ids) <= count($seatList) && count($seatList) <= self::DP_MAX_SEATS) {
                $method = 'dp';
                $assignment = self::dynamic($ids, $seatList, $lastPa, $preferences);
            } else {
                $method = 'greedy';
                if (count($ids) <= count($seatList)) {
                    $alerts[] = ['code' => 'greedy_large', 'seats' => count($seatList)];
                }
                [$assignment, $noPa] = self::greedy($ids, $seatList, $lastPa, $preferences);
            }
            foreach ($assignment as $pa => $id) {
                $occupants[$pa] = self::occupant($id, null, 'engine');
                self::move($id, $pa, $lastPa, $moved);
            }
        }
        if ($noPa !== []) {
            $alerts[] = ['code' => 'no_pa', 'count' => count($noPa)];
        }

        $ordered = [];
        foreach ($seats as $seat) {
            if (isset($occupants[$seat['pa']])) {
                $ordered[$seat['pa']] = $occupants[$seat['pa']];
            }
        }
        sort($moved);
        $codes = array_column($alerts, 'code');
        // Ordem do script (linhas 1121-1122): ATENCAO CONFIG, depois PA INSUFICIENTE.
        $status = [];
        if (array_intersect($codes, self::CONFIG_ALERTS) !== []) {
            $status[] = self::STATUS_CONFIG;
        }
        if ($noPa !== []) {
            $status[] = self::STATUS_NO_PA;
        }

        return [
            'occupants' => $ordered,
            'no_pa' => $noPa,
            'moved' => $moved,
            'moves' => count($moved),
            'method' => $method,
            'capacity' => $capacity,
            'onsite' => $onsiteTotal,
            'alerts' => $alerts,
            'status' => $status,
        ];
    }

    /**
     * Programacao dinamica exata sobre os conjuntos de PAs usados. O valor de
     * um conjunto e o menor custo para as pessoas que faltam; no empate fica o
     * primeiro PA da ordem (o "<" estrito do script). Devolve [pa => id].
     */
    private static function dynamic(array $ids, array $seatList, array $lastPa, array $preferences): array
    {
        $n = count($ids);
        $m = count($seatList);
        $cost = [];
        foreach ($ids as $i => $id) {
            foreach ($seatList as $j => $pa) {
                $base = self::points($id, $pa, $lastPa, $preferences);
                $cost[$i][$j] = $base === self::COST_OTHER ? self::COST_OTHER + abs($j - $i) : $base;
            }
        }
        $full = 1 << $m;
        $popcount = [0];
        $buckets = array_fill(0, $n + 1, []);
        $buckets[0][] = 0;
        for ($mask = 1; $mask < $full; $mask++) {
            $popcount[$mask] = $popcount[$mask >> 1] + ($mask & 1);
            if ($popcount[$mask] <= $n) {
                $buckets[$popcount[$mask]][] = $mask;
            }
        }
        $value = [];
        $choice = [];
        foreach ($buckets[$n] as $mask) {
            $value[$mask] = 0;
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            foreach ($buckets[$i] as $mask) {
                $best = null;
                $bestSeat = -1;
                for ($j = 0; $j < $m; $j++) {
                    if (($mask & (1 << $j)) !== 0) {
                        continue;
                    }
                    $candidate = $cost[$i][$j] + $value[$mask | (1 << $j)];
                    if ($best === null || $candidate < $best) {
                        $best = $candidate;
                        $bestSeat = $j;
                    }
                }
                $value[$mask] = $best;
                $choice[$mask] = $bestSeat;
            }
        }
        $assignment = [];
        $mask = 0;
        foreach ($ids as $id) {
            $j = $choice[$mask];
            $assignment[$seatList[$j]] = $id;
            $mask |= 1 << $j;
        }
        return $assignment;
    }

    /**
     * Caminho guloso do script: para cada PA, na ordem, a pessoa de menores
     * pontos (0/2/12, sem o |j - i|), com o nome e o ID no desempate.
     * Devolve [[pa => id], [ids sem PA]].
     */
    private static function greedy(array $ids, array $seatList, array $lastPa, array $preferences): array
    {
        $assignment = [];
        $unassigned = $ids;
        foreach ($seatList as $pa) {
            if ($unassigned === []) {
                break;
            }
            $bestIndex = null;
            $bestPoints = null;
            foreach ($unassigned as $index => $id) {
                $points = self::points($id, $pa, $lastPa, $preferences);
                if ($bestPoints === null || $points < $bestPoints) {
                    $bestPoints = $points;
                    $bestIndex = $index;
                }
            }
            $assignment[$pa] = $unassigned[$bestIndex];
            unset($unassigned[$bestIndex]);
        }
        return [$assignment, array_values($unassigned)];
    }

    private static function points(int $id, int $pa, array $lastPa, array $preferences): int
    {
        if (($lastPa[$id] ?? null) === $pa) {
            return self::COST_LAST_PA;
        }
        if (isset($preferences[$pa][$id])) {
            return self::COST_PREFERENCE;
        }
        return self::COST_OTHER;
    }

    private static function move(int $id, int $pa, array &$lastPa, array &$moved): void
    {
        $previous = $lastPa[$id] ?? null;
        if ($previous !== null && $previous !== $pa) {
            $moved[] = $id;
        }
        $lastPa[$id] = $pa;
    }

    private static function occupant(?int $id, ?string $reserved, string $source): array
    {
        return ['employee_id' => $id, 'reserved_for' => $reserved, 'source' => $source];
    }

    private static function ownerAt(array $seat, string $date, array &$alerts): ?int
    {
        $covering = array_values(array_filter(
            $seat['owners'],
            static fn (array $owner): bool => ($owner['from'] === null || $owner['from'] <= $date)
                && ($owner['until'] === null || $date <= $owner['until'])
        ));
        if (count($covering) > 1) {
            $alerts[] = ['code' => 'owner_overlap', 'pa' => $seat['pa'], 'count' => count($covering)];
        }
        return $covering[0]['employee_id'] ?? null;
    }

    // ------------------------------------------------------------------
    // Validacao da entrada (fronteira do motor)
    // ------------------------------------------------------------------

    private static function days(mixed $days): array
    {
        if (!is_array($days) || !array_is_list($days)) {
            throw new InvalidArgumentException('days: lista de datas obrigatoria');
        }
        $previous = null;
        foreach ($days as $date) {
            self::validDate($date, 'days');
            if ($previous !== null && $date <= $previous) {
                throw new InvalidArgumentException('days: datas em ordem crescente, sem repeticao');
            }
            $previous = $date;
        }
        return $days;
    }

    private static function seats(mixed $seats): array
    {
        if (!is_array($seats) || !array_is_list($seats)) {
            throw new InvalidArgumentException('seats: lista obrigatoria');
        }
        $out = [];
        foreach ($seats as $seat) {
            if (!is_array($seat)) {
                throw new InvalidArgumentException('seats: cada PA e um array');
            }
            $pa = self::positiveInt($seat['pa'] ?? null, 'seats.pa');
            if (isset($out[$pa])) {
                throw new InvalidArgumentException("seats: PA {$pa} repetido");
            }
            $type = $seat['type'] ?? null;
            if (!in_array($type, self::SEAT_TYPES, true)) {
                throw new InvalidArgumentException("seats: tipo invalido no PA {$pa}");
            }
            $reserved = $seat['reserved_for'] ?? null;
            if ($reserved !== null && (!in_array($reserved, self::RESERVED, true) || $type !== 'fixed')) {
                throw new InvalidArgumentException("seats: reserva invalida no PA {$pa} (so hybrid/critical, em fixed)");
            }
            $row = $seat['row'] ?? null;
            $column = $seat['column'] ?? null;
            if (!is_int($row) || $row < 1 || !is_int($column) || $column < 0) {
                throw new InvalidArgumentException("seats: fileira e coluna invalidas no PA {$pa}");
            }
            $owners = $seat['owners'] ?? [];
            if (!is_array($owners) || !array_is_list($owners)) {
                throw new InvalidArgumentException("seats: owners invalido no PA {$pa}");
            }
            $out[$pa] = [
                'pa' => $pa,
                'row' => $row,
                'column' => $column,
                'type' => $type,
                'can_lend' => self::bool($seat['can_lend'] ?? false, 'seats.can_lend'),
                'reserved_for' => $reserved,
                'active' => self::bool($seat['active'] ?? true, 'seats.active'),
                'unavailable' => self::bool($seat['unavailable'] ?? false, 'seats.unavailable'),
                'owners' => array_map(static fn ($owner): array => self::owner($owner, $pa), $owners),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['row'], $a['column'], $a['pa']] <=> [$b['row'], $b['column'], $b['pa']]);
        return $out;
    }

    private static function owner(mixed $owner, int $pa): array
    {
        if (!is_array($owner)) {
            throw new InvalidArgumentException("seats: dono invalido no PA {$pa}");
        }
        $from = $owner['from'] ?? null;
        $until = $owner['until'] ?? null;
        if ($from !== null) {
            self::validDate($from, 'seats.owners.from');
        }
        if ($until !== null) {
            self::validDate($until, 'seats.owners.until');
        }
        if ($from !== null && $until !== null && $until < $from) {
            throw new InvalidArgumentException("seats: vigencia do dono invertida no PA {$pa}");
        }
        return ['employee_id' => self::positiveInt($owner['employee_id'] ?? null, 'seats.owners.employee_id'), 'from' => $from, 'until' => $until];
    }

    private static function people(mixed $people): array
    {
        if (!is_array($people)) {
            throw new InvalidArgumentException('people: mapa id => nome');
        }
        $out = [];
        foreach ($people as $id => $name) {
            self::positiveInt($id, 'people.id');
            if (!is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('people: nome vazio');
            }
            $out[$id] = self::normalizeName($name);
        }
        return $out;
    }

    private static function presence(mixed $presence): array
    {
        if (!is_array($presence)) {
            throw new InvalidArgumentException('presence: mapa data => [id => situacao]');
        }
        foreach ($presence as $date => $byPerson) {
            self::validDate((string)$date, 'presence');
            if (!is_array($byPerson)) {
                throw new InvalidArgumentException('presence: mapa id => situacao');
            }
            foreach ($byPerson as $id => $state) {
                self::positiveInt($id, 'presence.id');
                if (!in_array($state, self::PRESENCE, true)) {
                    throw new InvalidArgumentException('presence: situacao invalida');
                }
            }
        }
        return $presence;
    }

    private static function preferences(mixed $preferences, array $seats): array
    {
        if (!is_array($preferences)) {
            throw new InvalidArgumentException('preferences: mapa pa => [ids]');
        }
        $known = array_column($seats, 'pa', 'pa');
        $out = [];
        foreach ($preferences as $pa => $ids) {
            if (!isset($known[$pa]) || !is_array($ids)) {
                throw new InvalidArgumentException('preferences: PA desconhecido ou lista invalida');
            }
            foreach ($ids as $id) {
                $out[$pa][self::positiveInt($id, 'preferences.id')] = true;
            }
        }
        return $out;
    }

    private static function locks(mixed $locks): array
    {
        if (!is_array($locks)) {
            throw new InvalidArgumentException('locks: mapa data => [travas]');
        }
        $out = [];
        foreach ($locks as $date => $list) {
            self::validDate((string)$date, 'locks');
            if (!is_array($list) || !array_is_list($list)) {
                throw new InvalidArgumentException('locks: lista de travas por data');
            }
            $day = [];
            foreach ($list as $lock) {
                if (!is_array($lock)) {
                    throw new InvalidArgumentException('locks: trava invalida');
                }
                $day[] = ['pa' => self::positiveInt($lock['pa'] ?? null, 'locks.pa'), 'employee_id' => self::positiveInt($lock['employee_id'] ?? null, 'locks.employee_id')];
            }
            // Ordem fixa (PA, pessoa): a trava repetida que perde e sempre a mesma.
            usort($day, static fn (array $a, array $b): int => [$a['pa'], $a['employee_id']] <=> [$b['pa'], $b['employee_id']]);
            $out[$date] = $day;
        }
        return $out;
    }

    private static function reservedPeople(mixed $reserved, array $people): array
    {
        if (!is_array($reserved)) {
            throw new InvalidArgumentException('reserved_people: mapa data => [reserva => [ids]]');
        }
        $out = [];
        foreach ($reserved as $date => $byTeam) {
            self::validDate((string)$date, 'reserved_people');
            if (!is_array($byTeam)) {
                throw new InvalidArgumentException('reserved_people: mapa reserva => [ids]');
            }
            $seen = [];
            foreach ($byTeam as $team => $ids) {
                if (!in_array($team, self::RESERVED, true) || !is_array($ids) || !array_is_list($ids)) {
                    throw new InvalidArgumentException('reserved_people: reserva invalida (so hybrid/critical, com lista de IDs)');
                }
                foreach ($ids as $id) {
                    self::positiveInt($id, 'reserved_people.id');
                    if (isset($seen[$id]) || isset($people[$id])) {
                        throw new InvalidArgumentException('reserved_people: pessoa repetida no dia ou do grupo comum');
                    }
                    $seen[$id] = true;
                }
                $out[(string)$date][$team] = $ids;
            }
        }
        return $out;
    }

    private static function lastPa(mixed $lastPa): array
    {
        if (!is_array($lastPa)) {
            throw new InvalidArgumentException('last_pa: mapa id => pa');
        }
        $out = [];
        foreach ($lastPa as $id => $pa) {
            $out[self::positiveInt($id, 'last_pa.id')] = self::positiveInt($pa, 'last_pa.pa');
        }
        return $out;
    }

    private static function validDate(mixed $value, string $field): void
    {
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$field}: data invalida (Y-m-d)");
        }
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$field}: inteiro positivo obrigatorio");
        }
        return $value;
    }

    private static function bool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException("{$field}: booleano obrigatorio");
        }
        return $value;
    }

    /** Minuscula -> maiuscula do Latin-1 (U+00E0..U+00FE, sem o U+00F7), o ss e o y com trema. */
    private static function upperLatin1(): array
    {
        if (self::$upperLatin1 === null) {
            $map = ["\u{00DF}" => 'SS', "\u{00FF}" => "\u{0178}"];
            for ($code = 0xE0; $code <= 0xFE; $code++) {
                if ($code !== 0xF7) {
                    $map[self::utf8($code)] = self::utf8($code - 0x20);
                }
            }
            self::$upperLatin1 = $map;
        }
        return self::$upperLatin1;
    }

    private static function utf8(int $code): string
    {
        return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
    }
}

