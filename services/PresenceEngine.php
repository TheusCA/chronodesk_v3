<?php
declare(strict_types=1);

require_once __DIR__ . '/ScheduleRulePlanner.php';

/**
 * Motor de presenca (Parte B, F2; desenho, secao 4).
 *
 * Funcoes puras: recebem os dados ja carregados e o instante; nao consultam
 * banco, arquivo nem relogio. Dado ruim (regra desconhecida, ciclo mal
 * configurado, horario invalido) nunca lanca excecao: vira "sem escala" ou
 * deixa de aplicar a checagem de horario, porque o status.php nao pode cair
 * por causa de uma linha.
 *
 * Colaborador: ['id' => int, 'team' => 'n1'|'n2'|'lideranca',
 *               'work_start' => 'H:i', 'work_end' => 'H:i',
 *               'lunch_start' => 'H:i', 'lunch_end' => 'H:i'] (horario pode
 *               vir com segundos ou nulo).
 * Regra: como em ScheduleRulePlanner, mais 'rule_config' (JSON ou array).
 * Excecao do dia: ['exception_type' => string] ou null.
 * Ausencias do dia, na ordem de prioridade (a primeira vale, secao 4.4):
 *   lista de ['origem' => 'excecao'|'ausencia',
 *   'period_type' => 'full_day'|'from_time'|'interval',
 *   'start_time' => ?'H:i', 'end_time' => ?'H:i', 'category' => ?string].
 *   'category' so vem quando quem consulta pode ve-la (P9); a observacao
 *   nunca entra no motor (decisao 4 do F3).
 * Pausa: ['em_pausa' => bool, 'motivo' => ?string] ou null.
 *
 * Ausencia (F3, secoes 4.3 e 4.4): dia inteiro vale o dia todo; "a partir
 * de" vale do horario ate o fim do expediente; "intervalo" vale em
 * inicio <= agora < fim. As excecoes day_off, absence, vacation e leave
 * antigas continuam como ausencia de dia inteiro (decisao 2 do F3).
 */
final class PresenceEngine {
    public const ABSENCE_EXCEPTIONS = ['day_off', 'absence', 'vacation', 'leave'];
    private const WORK_EXCEPTIONS = [
        'remote' => 'remoto',
        'onsite' => 'presencial',
        'training' => 'presencial',
        'oncall' => 'presencial',
    ];
    private const EXCEPTION_LABELS = [
        'remote' => 'exceção: remoto',
        'onsite' => 'exceção: presencial',
        'training' => 'exceção: treinamento',
        'oncall' => 'exceção: plantão',
    ];
    private const WEEKDAY_KEYS = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri'];
    private const WEEKDAY_LABELS = ['mon' => 'seg', 'tue' => 'ter', 'wed' => 'qua', 'thu' => 'qui', 'fri' => 'sex'];
    private const SITUATION_LABELS = ['presencial' => 'Presencial', 'remoto' => 'Remoto'];

    /**
     * Escala do dia (secao 4.2). Excecao de trabalho vale ate em fim de
     * semana (sabado de plantao); sem ela, sabado e domingo sao dia nao util
     * para todos (P3). A Lideranca tem escala implicita presencial (P12).
     *
     * Devolve ['situacao' => 'presencial'|'remoto'|'sem_escala'|'dia_nao_util',
     *          'fonte' => 'excecao'|'implicita'|'regra'|'nenhuma',
     *          'rule_type' => ?string, 'rule_label' => string].
     */
    public static function scheduleForDay(array $employee, string $date, array $rules, ?array $exception): array {
        $type = (string)($exception['exception_type'] ?? '');
        if (isset(self::WORK_EXCEPTIONS[$type])) {
            return self::schedule(self::WORK_EXCEPTIONS[$type], 'excecao', null, self::EXCEPTION_LABELS[$type]);
        }
        if (!self::isBusinessDay($date)) {
            return self::schedule('dia_nao_util', 'nenhuma', null, 'Fim de semana');
        }
        if (($employee['team'] ?? null) === 'lideranca') {
            return self::schedule('presencial', 'implicita', 'always_onsite', 'regra fixa');
        }
        $rule = ScheduleRulePlanner::ruleAt($rules, $date);
        if ($rule === null) {
            return self::schedule('sem_escala', 'nenhuma', null, 'Sem escala definida');
        }
        $ruleType = (string)$rule['rule_type'];
        $config = self::config($rule['rule_config'] ?? null);
        $day = (int)substr($date, 8, 2);
        switch ($ruleType) {
            case 'always_onsite':
                return self::schedule('presencial', 'regra', $ruleType, 'sempre presencial');
            case 'always_remote':
                return self::schedule('remoto', 'regra', $ruleType, 'sempre remoto');
            case 'even_days':
                return self::schedule($day % 2 === 0 ? 'presencial' : 'remoto', 'regra', $ruleType, 'dias pares');
            case 'odd_days':
                return self::schedule($day % 2 === 1 ? 'presencial' : 'remoto', 'regra', $ruleType, 'dias ímpares');
            case 'fixed_weekdays':
                $weekdays = self::weekdays($config['weekdays'] ?? []);
                $key = self::WEEKDAY_KEYS[self::isoWeekday($date)];
                return self::schedule(
                    in_array($key, $weekdays, true) ? 'presencial' : 'remoto',
                    'regra',
                    $ruleType,
                    'presencial: ' . self::weekdayListLabel($weekdays)
                );
            case 'cycle':
                $cycle = self::cycleConfig($config);
                if ($cycle === null) {
                    return self::schedule('sem_escala', 'regra', $ruleType, 'ciclo com configuração inválida');
                }
                $position = self::cyclePosition($cycle['anchor_date'], $date, count($cycle['pattern']));
                $value = $cycle['pattern'][$position] === 'onsite' ? 'presencial' : 'remoto';
                return self::schedule($value, 'regra', $ruleType, self::cycleLabel($cycle['pattern']));
            default:
                // undefined (escala removida antes do F1) e tipo desconhecido (P4).
                return self::schedule('sem_escala', 'regra', $ruleType, 'Sem escala definida');
        }
    }

    /**
     * Situacao de uma data que nao e hoje (secao 4.3, ultimo paragrafo):
     * ausente se houver ausencia de dia inteiro; senao, a escala do dia, com
     * a ausencia parcial como marcador (no detalhe e em ausencias[], inativa).
     * Pausa e expediente nao entram.
     */
    public static function dayPresence(array $employee, string $date, array $rules, ?array $exception, array $absences): array {
        $schedule = self::scheduleForDay($employee, $date, $rules, $exception);
        $items = self::absenceItems($absences, static fn (array $absence): bool => self::isFullDay($absence));
        if (self::hasFullDayAbsence($absences)) {
            return self::result('ausente', 'ausencia', 'Ausente (dia todo)', $schedule, $items, false);
        }
        if ($schedule['situacao'] === 'dia_nao_util') {
            return self::result('fora_expediente', 'expediente', 'Fim de semana', $schedule, $items, false);
        }
        return self::result($schedule['situacao'], 'regra_escala', self::scheduleDetail($schedule) . self::partialMarker($absences, null), $schedule, $items, false);
    }

    /**
     * Situacao no instante $now, para a data de $now (secao 4.3): ausencia,
     * fora do expediente (ou dia nao util), pausa, almoco, escala.
     */
    public static function nowPresence(
        array $employee,
        DateTimeImmutable $now,
        array $rules,
        ?array $exception,
        array $absences,
        ?array $pause
    ): array {
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i');
        $schedule = self::scheduleForDay($employee, $date, $rules, $exception);
        $workStart = self::time($employee['work_start'] ?? null);
        $workEnd = self::time($employee['work_end'] ?? null);
        $lunchStart = self::time($employee['lunch_start'] ?? null);
        $lunchEnd = self::time($employee['lunch_end'] ?? null);
        $inLunch = $lunchStart !== null && $lunchEnd !== null && $time >= $lunchStart && $time < $lunchEnd;
        $isActive = static fn (array $absence): bool => self::absenceActive($absence, $time, $workEnd);
        $items = self::absenceItems($absences, $isActive);

        // 1. Ausencia ativa: dia inteiro primeiro; senao, a primeira parcial ativa.
        if (self::hasFullDayAbsence($absences)) {
            return self::result('ausente', 'ausencia', 'Ausente (dia todo)', $schedule, $items, $inLunch);
        }
        foreach ($absences as $absence) {
            if ($isActive($absence)) {
                return self::result('ausente', 'ausencia', self::activeDetail($absence), $schedule, $items, $inLunch);
            }
        }
        if ($schedule['situacao'] === 'dia_nao_util') {
            return self::result('fora_expediente', 'expediente', 'Fim de semana', $schedule, $items, $inLunch);
        }
        if ($workStart !== null && $workEnd !== null && ($time < $workStart || $time >= $workEnd)) {
            return self::result('fora_expediente', 'expediente', 'Expediente ' . $workStart . '–' . $workEnd, $schedule, $items, $inLunch);
        }
        if (($pause['em_pausa'] ?? false) === true) {
            $reason = trim((string)($pause['motivo'] ?? ''));
            return self::result('pausa', 'pausa', $reason !== '' ? $reason : 'Pausa', $schedule, $items, $inLunch);
        }
        if ($inLunch) {
            return self::result('almoco', 'expediente', 'Almoço ' . $lunchStart . '–' . $lunchEnd, $schedule, $items, true);
        }
        // 4. Escala; ausencia parcial que ainda vai comecar hoje vira marcador.
        return self::result($schedule['situacao'], 'regra_escala', self::scheduleDetail($schedule) . self::partialMarker($absences, $time), $schedule, $items, false);
    }

    /**
     * Contadores de uma equipe (secao 4.5), a partir das situacoes de hoje.
     * escalados = disponiveis + em_pausa + almoco + ausentes + fora_expediente;
     * sem_escala fica fora da soma.
     */
    public static function summarize(array $presences): array {
        $summary = ['escalados' => 0, 'disponiveis' => 0, 'em_pausa' => 0, 'almoco' => 0,
                    'ausentes' => 0, 'fora_expediente' => 0, 'sem_escala' => 0];
        foreach ($presences as $presence) {
            $scheduled = in_array($presence['escala']['situacao'] ?? null, ['presencial', 'remoto'], true);
            if (!$scheduled) {
                if (($presence['escala']['situacao'] ?? null) === 'sem_escala') {
                    $summary['sem_escala']++;
                }
                continue;
            }
            $summary['escalados']++;
            $bucket = match ($presence['situacao']) {
                'presencial', 'remoto' => 'disponiveis',
                'pausa' => 'em_pausa',
                'almoco' => 'almoco',
                'ausente' => 'ausentes',
                default => 'fora_expediente',
            };
            $summary[$bucket]++;
        }
        return $summary;
    }

    /**
     * Posicao no ciclo: dias uteis em [ancora, data) mod tamanho (secao 4.2).
     * Data antes da ancora conta para tras, sem posicao negativa.
     */
    public static function cyclePosition(string $anchorDate, string $date, int $size): int {
        $count = self::businessDaysBetween($anchorDate, $date);
        return (($count % $size) + $size) % $size;
    }

    /**
     * Dias uteis (seg a sex) em [de, ate); negativo quando ate < de. Forma
     * fechada: semanas inteiras x 5 mais o resto, sem laco por dia.
     */
    public static function businessDaysBetween(string $from, string $to): int {
        if ($to < $from) {
            return -self::businessDaysBetween($to, $from);
        }
        $start = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
        $days = (int)$start->diff($end)->days;
        $count = intdiv($days, 7) * 5;
        $weekday = (int)$start->format('N');
        for ($i = 0; $i < $days % 7; $i++) {
            if ((($weekday - 1 + $i) % 7) < 5) {
                $count++;
            }
        }
        return $count;
    }

    public static function isBusinessDay(string $date): bool {
        return self::isoWeekday($date) <= 5;
    }

    /** Ciclo valido ou null: pattern de 2 a 20 posicoes onsite/remote e ancora em dia util. */
    public static function cycleConfig(array $config): ?array {
        $pattern = $config['pattern'] ?? null;
        $anchor = $config['anchor_date'] ?? null;
        if (!is_array($pattern) || !array_is_list($pattern) || count($pattern) < 2 || count($pattern) > 20) {
            return null;
        }
        foreach ($pattern as $value) {
            if ($value !== 'onsite' && $value !== 'remote') {
                return null;
            }
        }
        if (!is_string($anchor) || !self::validDate($anchor) || !self::isBusinessDay($anchor)) {
            return null;
        }
        return ['pattern' => $pattern, 'anchor_date' => $anchor];
    }

    public static function cycleLabel(array $pattern): string {
        $counts = array_count_values($pattern);
        return 'ciclo ' . ($counts['onsite'] ?? 0) . 'P/' . ($counts['remote'] ?? 0) . 'R';
    }

    public static function validDate(string $value): bool {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function schedule(string $situation, string $source, ?string $ruleType, string $label): array {
        return ['situacao' => $situation, 'fonte' => $source, 'rule_type' => $ruleType, 'rule_label' => ucfirst($label)];
    }

    private static function scheduleDetail(array $schedule): string {
        if (!isset(self::SITUATION_LABELS[$schedule['situacao']])) {
            return 'Sem escala definida';
        }
        return self::SITUATION_LABELS[$schedule['situacao']] . ' (' . lcfirst($schedule['rule_label']) . ')';
    }

    private static function result(
        string $situation,
        string $origin,
        string $detail,
        array $schedule,
        array $items,
        bool $inLunch
    ): array {
        return [
            'situacao' => $situation,
            'origem' => $origin,
            'detalhe' => $detail,
            'escala' => $schedule,
            'ausencias' => $items,
            'em_almoco' => $inLunch,
        ];
    }

    /**
     * ausencias[] da resposta: origem, periodo e se esta ativa; horario so na
     * parcial; categoria so quando veio (P9). Nunca a observacao.
     */
    private static function absenceItems(array $absences, callable $isActive): array {
        return array_map(static function (array $absence) use ($isActive): array {
            $item = [
                'origem' => $absence['origem'],
                'period_type' => self::isFullDay($absence) ? 'full_day' : $absence['period_type'],
                'ativa' => $isActive($absence),
            ];
            if (!self::isFullDay($absence)) {
                $item['start_time'] = self::time($absence['start_time'] ?? null);
                $item['end_time'] = self::time($absence['end_time'] ?? null);
            }
            if (isset($absence['category'])) {
                $item['category'] = $absence['category'];
            }
            return $item;
        }, $absences);
    }

    private static function hasFullDayAbsence(array $absences): bool {
        foreach ($absences as $absence) {
            if (self::isFullDay($absence)) {
                return true;
            }
        }
        return false;
    }

    /** Periodo desconhecido conta como dia inteiro (falha para o lado de ausente). */
    private static function isFullDay(array $absence): bool {
        return !in_array($absence['period_type'] ?? 'full_day', ['from_time', 'interval'], true);
    }

    /**
     * Ausencia ativa em $time: dia inteiro sempre; "a partir de" do horario ate
     * o fim do expediente (sem jornada valida, ate o fim do dia); "intervalo"
     * em inicio <= $time < fim. Horario invalido: parcial nunca ativa.
     */
    private static function absenceActive(array $absence, string $time, ?string $workEnd): bool {
        if (self::isFullDay($absence)) {
            return true;
        }
        $start = self::time($absence['start_time'] ?? null);
        if ($start === null || $time < $start) {
            return false;
        }
        if ($absence['period_type'] === 'from_time') {
            return $workEnd === null || $time < $workEnd;
        }
        $end = self::time($absence['end_time'] ?? null);
        return $end !== null && $time < $end;
    }

    private static function activeDetail(array $absence): string {
        $start = self::time($absence['start_time'] ?? null);
        return $absence['period_type'] === 'from_time'
            ? 'Ausente desde ' . $start
            : 'Ausente ' . $start . '–' . self::time($absence['end_time'] ?? null);
    }

    /**
     * Marcador da proxima ausencia parcial: a de menor inicio que ainda vai
     * comecar ($time nulo em outra data: a de menor inicio do dia). Empate
     * pela ordem de entrada (id), por isso so troca com inicio estritamente menor.
     */
    private static function partialMarker(array $absences, ?string $time): string {
        $label = self::partialMarkerLabel($absences, $time);
        return $label === null ? '' : ' · Ausente ' . $label;
    }

    /**
     * [F5, ajuste b do commit 4] Horario do marcador da parcial ("09:00–10:00"
     * ou "a partir de 15:00"), a mesma regra do detalhe; null sem marcador.
     * Aceita as ausencias de entrada ou os itens de ausencias[] da resposta.
     */
    public static function partialMarkerLabel(array $absences, ?string $time = null): ?string {
        $next = null;
        $nextStart = null;
        foreach ($absences as $absence) {
            $start = self::time($absence['start_time'] ?? null);
            if (self::isFullDay($absence) || $start === null || ($time !== null && $start <= $time)) {
                continue;
            }
            if ($nextStart === null || $start < $nextStart) {
                $next = $absence;
                $nextStart = $start;
            }
        }
        if ($next === null) {
            return null;
        }
        return $next['period_type'] === 'from_time'
            ? 'a partir de ' . $nextStart
            : $nextStart . '–' . self::time($next['end_time'] ?? null);
    }

    private static function isoWeekday(string $date): int {
        return (int)(new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->format('N');
    }

    private static function config($config): array {
        if (is_array($config)) {
            return $config;
        }
        if (!is_string($config) || trim($config) === '') {
            return [];
        }
        $decoded = json_decode($config, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function weekdays($value): array {
        if (!is_array($value)) {
            return [];
        }
        $selected = array_map(static fn($day): string => strtolower(trim((string)$day)), $value);
        return array_values(array_filter(self::WEEKDAY_KEYS, static fn(string $key): bool => in_array($key, $selected, true)));
    }

    private static function weekdayListLabel(array $weekdays): string {
        $labels = array_map(static fn(string $key): string => self::WEEKDAY_LABELS[$key], $weekdays);
        if ($labels === []) {
            return 'dias não definidos';
        }
        $last = array_pop($labels);
        return $labels === [] ? $last : implode(', ', $labels) . ' e ' . $last;
    }

    /** 'H:i' a partir de 'H:i' ou 'H:i:s'; null se invalido. */
    private static function time($value): ?string {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            return null;
        }
        return substr($value, 0, 5);
    }
}
