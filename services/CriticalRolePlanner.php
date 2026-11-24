<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/ScheduleRulePlanner.php';

/**
 * Rodizio de papeis do Critico (desenho principal, 9.16): o papel do dia de
 * cada pessoa, a partir do rodizio com vigencia e das trocas pontuais.
 *
 * Funcoes puras: recebem papeis, rodizios e trocas ja carregados; nao
 * consultam banco nem relogio. Quem grava (CriticalRoleService) trava antes
 * de ler.
 *
 * Papel: ['id', 'name', 'presence_type' ('onsite'|'remote'), 'work_start',
 * 'work_end' ('HH:MM'), 'active' (bool), 'sort_order']. Rodizio: ['id',
 * 'effective_from', 'period' ('week'|'month'), 'anchor_date', 'roles' (lista
 * de IDs), 'employees' (lista de IDs)]. Troca: ['id', 'employee_id',
 * 'starts_on', 'ends_on' (inclusive), 'role_id', 'reason'].
 *
 * Regra (9.16.1, AD-PC-1, AD-PC-2, AD-PC-4): so dia util tem papel (fim de
 * semana e feriado, so por excecao de escala por data, fora daqui). A troca
 * pontual vence o rodizio. No periodo k contado da ancora (semana a partir
 * da segunda, ou mes do calendario), a pessoa da posicao i fica com a
 * posicao (i + k) mod max(pessoas, papeis): posicao sem papel = pessoa a mais
 * (fica pelo cadastro); papel sem pessoa (ou com pessoa inativa) = vago.
 */
final class CriticalRolePlanner {
    public const PERIODS = ['week', 'month'];
    public const PRESENCE_TYPES = ['onsite', 'remote'];
    public const REASONS = ['troca', 'cobertura', 'outro'];
    /** [D-ESC-13] Paleta fechada da cor do papel (a mesma CHECK da 027); nulo = areia na tela. */
    public const COLORS = ['azul', 'verde', 'pessego', 'lilas', 'amarelo', 'turquesa', 'cinza', 'areia'];
    public const MAX_ITEMS = 20;
    public const MAX_OVERRIDE_DAYS = 31;
    public const PREVIEW_WEEKS = 8;

    /** Rodizio que vale na data: o de maior effective_from <= data. */
    public static function rotationAt(array $rotations, string $date): ?array {
        $found = null;
        foreach ($rotations as $rotation) {
            if ($rotation['effective_from'] <= $date && ($found === null || $rotation['effective_from'] > $found['effective_from'])) {
                $found = $rotation;
            }
        }
        return $found;
    }

    /** Ancora valida: segunda-feira (semana) ou dia 1 (mes). */
    public static function validAnchor(string $period, string $anchor): bool {
        if (!PresenceEngine::validDate($anchor)) {
            return false;
        }
        return match ($period) {
            'week' => (int)(new DateTimeImmutable($anchor . ' 00:00:00', new DateTimeZone('UTC')))->format('N') === 1,
            'month' => substr($anchor, 8, 2) === '01',
            default => false,
        };
    }

    /** Periodo k da data a partir da ancora (negativo antes dela). */
    public static function periodIndex(string $period, string $anchor, string $date): int {
        if ($period === 'month') {
            return ((int)substr($date, 0, 4) * 12 + (int)substr($date, 5, 2)) - ((int)substr($anchor, 0, 4) * 12 + (int)substr($anchor, 5, 2));
        }
        $from = new DateTimeImmutable($anchor . ' 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        $days = (int)$from->diff($to)->format('%r%a');
        return intdiv($days - (($days % 7 + 7) % 7), 7);
    }

    /** Posicao (0..max-1) da pessoa no periodo da data, ou null fora do rodizio. */
    public static function slotFor(array $rotation, int $employeeId, string $date): ?int {
        $position = array_search($employeeId, array_map('intval', $rotation['employees']), true);
        if ($position === false) {
            return null;
        }
        $size = max(count($rotation['employees']), count($rotation['roles']));
        $k = self::periodIndex($rotation['period'], $rotation['anchor_date'], $date);
        return ((($position + $k) % $size) + $size) % $size;
    }

    /** ID do papel do rodizio para a pessoa na data (sem olhar o dia util), ou null. */
    public static function rotationRoleId(array $rotation, int $employeeId, string $date): ?int {
        $slot = self::slotFor($rotation, $employeeId, $date);
        if ($slot === null || !isset($rotation['roles'][$slot])) {
            return null;
        }
        return (int)$rotation['roles'][$slot];
    }

    /** Troca pontual da pessoa que cobre a data, ou null. */
    public static function overrideAt(array $overrides, int $employeeId, string $date): ?array {
        foreach ($overrides as $override) {
            if ((int)$override['employee_id'] === $employeeId && $override['starts_on'] <= $date && $override['ends_on'] >= $date) {
                return $override;
            }
        }
        return null;
    }

    /**
     * Papel do dia da pessoa: null em dia nao util (AD-PC-4) ou sem papel;
     * senao o papel com 'source' = 'troca' (vence) ou 'rodizio'.
     * $rolesById: id => papel (todos, inclusive inativos: o rodizio gravado vale).
     */
    public static function dayRole(int $employeeId, string $date, array $rotations, array $overrides, array $rolesById): ?array {
        if (!PresenceEngine::isBusinessDay($date)) {
            return null;
        }
        $override = self::overrideAt($overrides, $employeeId, $date);
        if ($override !== null && isset($rolesById[(int)$override['role_id']])) {
            return $rolesById[(int)$override['role_id']] + ['source' => 'troca'];
        }
        $rotation = self::rotationAt($rotations, $date);
        if ($rotation === null) {
            return null;
        }
        $roleId = self::rotationRoleId($rotation, $employeeId, $date);
        if ($roleId === null || !isset($rolesById[$roleId])) {
            return null;
        }
        return $rolesById[$roleId] + ['source' => 'rodizio'];
    }

    /**
     * Papeis vagos na data (AD-PC-2): do rodizio vigente, as vagas sem pessoa
     * na posicao ou com pessoa fora de $activeEmployeeIds (arquivada ou que
     * saiu do Critico), menos as cobertas por troca pontual. [D-ESC-15] Com o
     * mesmo papel em mais de uma vaga, a conta e por vaga: o papel aparece uma
     * vez para cada vaga sem ninguem. Dia nao util: [].
     */
    public static function vacantRoleIds(array $rotations, array $overrides, string $date, array $activeEmployeeIds): array {
        if (!PresenceEngine::isBusinessDay($date)) {
            return [];
        }
        $rotation = self::rotationAt($rotations, $date);
        if ($rotation === null) {
            return [];
        }
        $active = array_flip(array_map('intval', $activeEmployeeIds));
        $filled = [];
        foreach ($activeEmployeeIds as $employeeId) {
            $role = self::overrideAt($overrides, (int)$employeeId, $date);
            if ($role !== null) {
                $filled[(int)$role['role_id']] = ($filled[(int)$role['role_id']] ?? 0) + 1;
            }
        }
        foreach (array_map('intval', $rotation['employees']) as $employeeId) {
            $override = self::overrideAt($overrides, $employeeId, $date);
            $roleId = self::rotationRoleId($rotation, $employeeId, $date);
            if ($roleId !== null && isset($active[$employeeId]) && $override === null) {
                $filled[$roleId] = ($filled[$roleId] ?? 0) + 1;
            }
        }
        $vacant = [];
        foreach (array_map('intval', $rotation['roles']) as $roleId) {
            if (($filled[$roleId] ?? 0) > 0) {
                $filled[$roleId]--;
                continue;
            }
            $vacant[] = $roleId;
        }
        return $vacant;
    }

    /**
     * Previa do rodizio (sem trocas): as semanas a partir de effective_from,
     * so dias uteis; por dia, o papel de cada pessoa (ou null) e os vagos.
     */
    public static function preview(array $rotation, array $activeEmployeeIds, int $weeks = self::PREVIEW_WEEKS): array {
        $days = [];
        $end = ScheduleRulePlanner::addDays($rotation['effective_from'], 7 * $weeks - 1);
        for ($date = $rotation['effective_from']; $date <= $end; $date = ScheduleRulePlanner::addDays($date, 1)) {
            if (!PresenceEngine::isBusinessDay($date)) {
                continue;
            }
            $roles = [];
            foreach (array_map('intval', $rotation['employees']) as $employeeId) {
                $roles[(string)$employeeId] = self::rotationRoleId($rotation, $employeeId, $date);
            }
            $days[] = ['date' => $date, 'roles' => $roles, 'vacant' => self::vacantRoleIds([$rotation], [], $date, $activeEmployeeIds)];
        }
        return $days;
    }

    /** Jornada 'HH:MM' valida e sem cruzar a meia-noite (inicio < fim). */
    public static function validShift(mixed $start, mixed $end): bool {
        return is_string($start) && is_string($end)
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) === 1
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) === 1
            && $start < $end;
    }

    /** Lista de IDs (1 a 20, inteiros >= 1, sem repeticao) ou null. */
    public static function idList(mixed $value): ?array {
        return self::ids($value, false);
    }

    /**
     * [D-ESC-15] Vagas do rodizio: 1 a 20 IDs de papel, inteiros >= 1, o mesmo
     * papel pode repetir (Presencial x2). Ou null.
     */
    public static function slotList(mixed $value): ?array {
        return self::ids($value, true);
    }

    private static function ids(mixed $value, bool $repeat): ?array {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_ITEMS) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            $id = is_int($item) || (is_string($item) && ctype_digit($item)) ? (int)$item : 0;
            if ($id < 1 || (!$repeat && in_array($id, $ids, true))) {
                return null;
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /** [D-ESC-13] Cor valida da paleta, ou null (sem cor). Outro valor: false. */
    public static function colorToken(mixed $value): string|false|null {
        if ($value === null || $value === '') {
            return null;
        }
        return is_string($value) && in_array($value, self::COLORS, true) ? $value : false;
    }

    /** Linhas do banco no formato das funcoes acima (horas em HH:MM, listas de IDs). */
    public static function roleView(array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'presence_type' => (string)$row['presence_type'],
            'work_start' => substr((string)$row['work_start'], 0, 5),
            'work_end' => substr((string)$row['work_end'], 0, 5),
            'active' => (int)$row['active'] === 1,
            'sort_order' => (int)$row['sort_order'],
            // [D-ESC-13] Cor da paleta (027); sem a coluna ou nula, null.
            'color_token' => isset($row['color_token']) && in_array($row['color_token'], self::COLORS, true) ? (string)$row['color_token'] : null,
        ];
    }

    public static function rotationView(array $row): array {
        $ids = static function (mixed $value): array {
            $list = is_array($value) ? $value : json_decode((string)$value, true);
            return is_array($list) ? array_values(array_map('intval', $list)) : [];
        };
        return [
            'id' => (int)$row['id'],
            'effective_from' => (string)$row['effective_from'],
            'period' => (string)$row['period'],
            'anchor_date' => (string)$row['anchor_date'],
            'roles' => $ids($row['roles']),
            'employees' => $ids($row['employees']),
        ];
    }

    public static function overrideView(array $row): array {
        return [
            'id' => (int)$row['id'],
            'employee_id' => (int)$row['employee_id'],
            'starts_on' => (string)$row['starts_on'],
            'ends_on' => (string)$row['ends_on'],
            'role_id' => (int)$row['role_id'],
            'reason' => (string)$row['reason'],
        ];
    }

    /** Rotulo do papel: "Abertura home 07:00–17:00". */
    public static function label(array $role): string {
        return $role['name'] . ' ' . substr((string)$role['work_start'], 0, 5) . '–' . substr((string)$role['work_end'], 0, 5);
    }
}
