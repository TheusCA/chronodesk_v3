<?php
declare(strict_types=1);

/**
 * Regras de ausencia (Parte B, F3; desenho, secoes 3.3 e 9.4).
 *
 * Funcoes puras: recebem o payload, a data de hoje (do PHP, America/Sao_Paulo)
 * e as ausencias ja carregadas; nao consultam banco nem relogio. O
 * AbsenceService trava o colaborador, carrega com trava e grava.
 *
 * Ausencia normalizada: ['employee_id' => int, 'starts_on' => 'Y-m-d',
 *   'ends_on' => 'Y-m-d', 'period_type' => 'full_day'|'from_time'|'interval',
 *   'start_time' => ?'H:i', 'end_time' => ?'H:i', 'category' => string,
 *   'note' => ?string].
 */
final class AbsenceRules {
    public const CATEGORIES = [
        'folga' => 'Folga',
        'ferias' => 'Férias',
        'ausencia_justificada' => 'Ausência justificada',
        'compromisso' => 'Compromisso',
        'outro' => 'Outro',
    ];
    public const PERIOD_TYPES = ['full_day', 'from_time', 'interval'];
    public const MAX_DAYS = 366;
    public const NOTE_MAX_CHARS = 500;

    /**
     * Payload de create/update validado (400 em InvalidArgumentException):
     * datas, periodo (P5: horario so com um dia), categoria, observacao.
     * O horario dentro da jornada (P1) depende do cadastro: withinWorkday().
     */
    public static function normalize(array $data): array {
        $employeeId = self::positiveInt($data['employee_id'] ?? null, 'Colaborador inválido.');
        $startsOn = self::date($data['starts_on'] ?? null, 'Data inicial inválida.');
        $endsOn = self::date($data['ends_on'] ?? $startsOn, 'Data final inválida.');
        if ($endsOn < $startsOn) {
            throw new InvalidArgumentException('A data final deve ser igual ou posterior à inicial.');
        }
        if (self::daysBetween($startsOn, $endsOn) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('A ausência pode ter no máximo ' . self::MAX_DAYS . ' dias.');
        }
        $periodType = $data['period_type'] ?? 'full_day';
        if (!is_string($periodType) || !in_array($periodType, self::PERIOD_TYPES, true)) {
            throw new InvalidArgumentException('Tipo de período inválido.');
        }
        $startTime = self::time($data['start_time'] ?? null, 'Horário inicial inválido.');
        $endTime = self::time($data['end_time'] ?? null, 'Horário final inválido.');
        if ($periodType === 'full_day') {
            $startTime = null;
            $endTime = null;
        } else {
            if ($startsOn !== $endsOn) {
                throw new InvalidArgumentException('Horário só pode ser informado em ausência de um dia; período de vários dias é sempre dia inteiro.');
            }
            if ($startTime === null) {
                throw new InvalidArgumentException('Informe o horário inicial.');
            }
            if ($periodType === 'from_time') {
                $endTime = null;
            } elseif ($endTime === null || $endTime <= $startTime) {
                throw new InvalidArgumentException('No intervalo, o horário final deve ser posterior ao inicial.');
            }
        }
        $category = $data['category'] ?? null;
        if (!is_string($category) || !isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException('Categoria de ausência inválida.');
        }
        $note = $data['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            throw new InvalidArgumentException('Observação inválida.');
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && self::length($note) > self::NOTE_MAX_CHARS) {
            throw new InvalidArgumentException('A observação pode ter no máximo ' . self::NOTE_MAX_CHARS . ' caracteres.');
        }
        return [
            'employee_id' => $employeeId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'period_type' => $periodType,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'category' => $category,
            'note' => $note === '' ? null : $note,
        ];
    }

    /**
     * P1: horario dentro da jornada do colaborador. Jornada invalida no
     * cadastro: ausencia com horario e recusada (dia inteiro continua).
     */
    public static function assertWithinWorkday(array $absence, ?string $workStart, ?string $workEnd): void {
        if ($absence['period_type'] === 'full_day') {
            return;
        }
        $workStart = self::storedTime($workStart);
        $workEnd = self::storedTime($workEnd);
        if ($workStart === null || $workEnd === null || $workStart >= $workEnd) {
            throw new InvalidArgumentException('A jornada do cadastro é inválida: lance a ausência como dia inteiro ou corrija a jornada.');
        }
        $message = 'O horário deve ficar dentro da jornada do colaborador (' . $workStart . '–' . $workEnd . ').';
        if ($absence['start_time'] < $workStart || $absence['start_time'] >= $workEnd) {
            throw new InvalidArgumentException($message);
        }
        if ($absence['end_time'] !== null && $absence['end_time'] > $workEnd) {
            throw new InvalidArgumentException($message);
        }
    }

    /** Inicio anterior a hoje: lancamento retroativo (D3, L6). */
    public static function startsBeforeToday(array $absence, string $today): bool {
        return $absence['starts_on'] < $today;
    }

    /** Ausencia ja iniciada (editar ou cancelar e retroativo; D3). */
    public static function hasStarted(array $absence, string $today): bool {
        return $absence['starts_on'] <= $today;
    }

    /**
     * Sobreposicao (P6, aprovada em 2026-10-07): datas que se cruzam E (alguma
     * de dia inteiro ou de varios dias, ou as duas parciais no mesmo dia com
     * horarios que se cruzam). "A partir de" vai ate o fim da jornada.
     */
    public static function overlaps(array $a, array $b, ?string $workEnd): bool {
        if ($a['starts_on'] > $b['ends_on'] || $b['starts_on'] > $a['ends_on']) {
            return false;
        }
        if ($a['period_type'] === 'full_day' || $b['period_type'] === 'full_day'
            || $a['starts_on'] !== $a['ends_on'] || $b['starts_on'] !== $b['ends_on']) {
            return true;
        }
        $end = self::storedTime($workEnd) ?? '24:00';
        $aStart = self::storedTime($a['start_time']);
        $bStart = self::storedTime($b['start_time']);
        $aEnd = self::storedTime($a['end_time']) ?? $end;
        $bEnd = self::storedTime($b['end_time']) ?? $end;
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /** Primeira ausencia aprovada que conflita com $candidate (ignora $ignoreId), ou null. */
    public static function findConflict(array $candidate, array $existing, ?string $workEnd, ?int $ignoreId = null): ?array {
        foreach ($existing as $absence) {
            if (($absence['status'] ?? 'approved') !== 'approved' || ($ignoreId !== null && (int)$absence['id'] === $ignoreId)) {
                continue;
            }
            if (self::overlaps($candidate, $absence, $workEnd)) {
                return $absence;
            }
        }
        return null;
    }

    /** Mensagem do 409: o periodo em conflito, sem categoria nem observacao. */
    public static function conflictMessage(array $conflict): string {
        return 'Já existe ausência aprovada do colaborador ' . self::periodLabel($conflict) . '.';
    }

    public static function periodLabel(array $absence): string {
        $date = static fn (string $value): string => substr($value, 8, 2) . '/' . substr($value, 5, 2) . '/' . substr($value, 0, 4);
        $days = $absence['starts_on'] === $absence['ends_on']
            ? 'em ' . $date($absence['starts_on'])
            : 'de ' . $date($absence['starts_on']) . ' a ' . $date($absence['ends_on']);
        $start = self::storedTime($absence['start_time'] ?? null);
        $end = self::storedTime($absence['end_time'] ?? null);
        return match ($absence['period_type']) {
            'from_time' => $days . ' a partir de ' . $start,
            'interval' => $days . ', ' . $start . '–' . $end,
            default => $days . ' (dia todo)',
        };
    }

    /** Hora do banco ('H:i:s') ou do payload ('H:i') como 'H:i'; null se vazia ou invalida. */
    public static function storedTime($value): ?string {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            return null;
        }
        return substr($value, 0, 5);
    }

    private static function time($value, string $message): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private static function date($value, string $message): string {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException($message);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private static function positiveInt($value, string $message): int {
        $valid = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1);
        $number = $valid ? (int)$value : 0;
        if ($number < 1) {
            throw new InvalidArgumentException($message);
        }
        return $number;
    }

    private static function daysBetween(string $from, string $to): int {
        $utc = new DateTimeZone('UTC');
        return (int)(new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc))->days;
    }

    /**
     * Caracteres UTF-8, como o VARCHAR(500) do utf8mb4 conta, sem depender da
     * extensao mbstring (o fallback por strlen contaria bytes). UTF-8 invalido:
     * 400.
     */
    private static function length(string $value): int {
        $count = preg_match_all('/./su', $value);
        if ($count === false) {
            throw new InvalidArgumentException('Observação inválida.');
        }
        return $count;
    }
}
