<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/PresenceRepository.php';

/**
 * Consumidores do motor de presenca no F2: GET portal/presence.php e a
 * presenca de hoje no status.php (P14). Carrega em lote pelo repositorio e
 * calcula pelo PresenceEngine; o instante vem de quem chama.
 *
 * Escopo reduzido do F2 (plano aprovado): Calendario, Escala, Relatorios
 * (generatedSchedule) e Mapa PA continuam no calculo antigo ate o F2B, F5 e F6.
 */
final class PresenceService {
    public const MAX_DAYS = 93;
    private const TEAMS = ['n1', 'n2', 'lideranca'];

    private PresenceRepository $repository;

    public function __construct(PDO $pdo) {
        $this->repository = new PresenceRepository($pdo);
    }

    /**
     * Interruptor STATUS_PRESENCE_ENABLED (plano do F2): ausente ou vazio =
     * ligado; 0/false/off/no desliga. Valor fora da lista fica ligado e e
     * avisado no log, para um erro de digitacao nao passar despercebido.
     */
    public static function statusPresenceEnabled(): bool {
        $value = getenv('STATUS_PRESENCE_ENABLED');
        if ($value === false || trim($value) === '') {
            return true;
        }
        $value = strtolower(trim($value));
        if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        if (!in_array($value, ['1', 'true', 'on', 'yes'], true)) {
            error_log('[PRESENCE] STATUS_PRESENCE_ENABLED com valor invalido; presenca mantida ligada.');
        }
        return true;
    }

    /**
     * Resposta do GET portal/presence.php (desenho, secao 3.2). $pauseState:
     * entradas do estado.json por id, ou null quando ilegivel.
     */
    public function period(array $query, DateTimeImmutable $now, ?array $pauseState): array {
        $today = $now->format('Y-m-d');
        $from = self::queryDate($query['from'] ?? null, $today, 'from');
        $to = self::queryDate($query['to'] ?? null, $from, 'to');
        if ($to < $from) {
            throw new InvalidArgumentException('A data final deve ser igual ou posterior a inicial.');
        }
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('O periodo maximo e de ' . self::MAX_DAYS . ' dias.');
        }
        $team = self::queryTeam($query['team'] ?? null);
        $employeeId = self::queryEmployeeId($query['employee_id'] ?? null);

        $employees = array_values(array_filter(
            $this->repository->employees($employeeId),
            static fn(array $employee): bool => !$employee['outside_map']
                && $employee['team'] !== null
                && ($team === null || $employee['team'] === $team)
        ));
        $rules = $this->repository->rulesByEmployee($from, $to, $employeeId);
        $exceptions = $this->repository->exceptionsByDay($from, $to, $employeeId);
        $absences = $this->repository->absenceRangesByEmployee($from, $to, $employeeId);

        $days = [];
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $dateKey = $date->format('Y-m-d');
            foreach ($employees as $employee) {
                $id = $employee['id'];
                $exception = $exceptions[$id . ':' . $dateKey] ?? null;
                $dayAbsences = self::absencesForDay($dateKey, $exception, $absences[$id] ?? []);
                $presence = $dateKey === $today
                    ? PresenceEngine::nowPresence($employee, $now, $rules[$id] ?? [], $exception, $dayAbsences, $pauseState === null ? null : self::pause($pauseState[$id] ?? null))
                    : PresenceEngine::dayPresence($employee, $dateKey, $rules[$id] ?? [], $exception, $dayAbsences);
                $days[] = ['employee_id' => $id, 'date' => $dateKey] + $presence;
            }
        }

        return [
            'server_now' => $now->format(DATE_ATOM),
            'today' => $today,
            'period' => ['from' => $from, 'to' => $to],
            'employees' => array_map(static fn(array $employee): array => [
                'id' => $employee['id'],
                'name' => $employee['name'],
                'team' => $employee['team'],
            ], $employees),
            'days' => $days,
            'meta' => ['pausas_disponiveis' => $pauseState !== null || $from > $today || $to < $today],
        ];
    }

    /**
     * Presenca de hoje em cada item de n1/n2 do status.php (P14), com a pausa
     * do proprio item. Inativo, fora do Mapa PA e da escala ou fora da carga:
     * presenca null.
     */
    public function attachToStatus(array $status, DateTimeImmutable $now): array {
        $today = $now->format('Y-m-d');
        $employees = [];
        foreach ($this->repository->employees() as $employee) {
            $employees[$employee['id']] = $employee;
        }
        $rules = $this->repository->rulesByEmployee($today, $today);
        $exceptions = $this->repository->exceptionsByDay($today, $today);
        $absences = $this->repository->absenceRangesByEmployee($today, $today);

        foreach (['n1', 'n2'] as $team) {
            foreach ($status[$team] ?? [] as $index => $item) {
                $id = (int)($item['id'] ?? 0);
                $employee = $employees[$id] ?? null;
                if ($employee === null || $employee['outside_map']) {
                    $status[$team][$index]['presenca'] = null;
                    continue;
                }
                $exception = $exceptions[$id . ':' . $today] ?? null;
                $presence = PresenceEngine::nowPresence(
                    $employee,
                    $now,
                    $rules[$id] ?? [],
                    $exception,
                    self::absencesForDay($today, $exception, $absences[$id] ?? []),
                    ['em_pausa' => ($item['em_pausa'] ?? false) === true, 'motivo' => $item['motivo_pausa'] ?? null]
                );
                $status[$team][$index]['presenca'] = [
                    'situacao' => $presence['situacao'],
                    'origem' => $presence['origem'],
                    'detalhe' => $presence['detalhe'],
                ];
            }
        }
        return $status;
    }

    /** Ausencias de um dia: excecao de ausencia (ate o F3) e linhas aprovadas que cobrem a data. */
    private static function absencesForDay(string $date, ?array $exception, array $ranges): array {
        $absences = [];
        if (in_array($exception['exception_type'] ?? null, PresenceEngine::ABSENCE_EXCEPTIONS, true)) {
            $absences[] = ['origem' => 'excecao', 'period_type' => 'full_day'];
        }
        foreach ($ranges as [$startsOn, $endsOn]) {
            if ($startsOn <= $date && $endsOn >= $date) {
                $absences[] = ['origem' => 'ausencia', 'period_type' => 'full_day'];
            }
        }
        return $absences;
    }

    private static function pause($entry): array {
        return [
            'em_pausa' => is_array($entry) && ($entry['em_pausa'] ?? false) === true,
            'motivo' => is_array($entry) ? ($entry['motivo_pausa'] ?? null) : null,
        ];
    }

    private static function queryDate($value, string $default, string $label): string {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !PresenceEngine::validDate($value)) {
            throw new InvalidArgumentException("Parametro {$label} invalido: use AAAA-MM-DD.");
        }
        return $value;
    }

    private static function queryTeam($value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, self::TEAMS, true)) {
            throw new InvalidArgumentException('Equipe invalida.');
        }
        return $value;
    }

    private static function queryEmployeeId($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Colaborador invalido.');
        }
        return $id;
    }
}
