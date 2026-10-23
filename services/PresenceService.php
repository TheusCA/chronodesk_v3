<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/PresenceRepository.php';
require_once __DIR__ . '/OperationalService.php';

/**
 * Consumidores do motor de presenca no F2: GET portal/presence.php e a
 * presenca de hoje no status.php (P14). No F7, os contadores do
 * dashboard.php e a situacao de agora do technicians.php. Carrega em lote
 * pelo repositorio e calcula pelo PresenceEngine; o instante vem de quem chama.
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
     * entradas do estado.json por id, ou null quando ilegivel. $viewer:
     * portal_actor() de quem consulta; a categoria da ausencia so vai para a
     * gestao e para o proprio colaborador (P9, F3). Sem $viewer, nenhuma.
     */
    public function period(array $query, DateTimeImmutable $now, ?array $pauseState, array $viewer = []): array {
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
        $absences = $this->repository->absencesByEmployee($from, $to, $employeeId);
        $seesAllCategories = portal_role_is_manager($viewer['role'] ?? null);
        $viewerEmployeeId = (int)($viewer['employee_id'] ?? 0);

        $days = [];
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $dateKey = $date->format('Y-m-d');
            foreach ($employees as $employee) {
                $id = $employee['id'];
                $exception = $exceptions[$id . ':' . $dateKey] ?? null;
                $showCategory = $seesAllCategories || ($viewerEmployeeId > 0 && $viewerEmployeeId === $id);
                $dayAbsences = self::absencesForDay($dateKey, $exception, $absences[$id] ?? [], $showCategory);
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
        $absences = $this->repository->absencesByEmployee($today, $today);

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

    /**
     * [F7] Contadores da Visao Operacional (secoes 4.5 e 5.7) no instante
     * $now, pelo summarize: N1, N2 e Lideranca, e o total = N1 + N2 (a
     * Lideranca fica separada e fora do total, D10). $pauses: ver nowPresences.
     */
    public function operationalSummary(DateTimeImmutable $now, ?array $pauses): array {
        $byTeam = ['n1' => [], 'n2' => [], 'lideranca' => []];
        foreach ($this->nowPresences($now, $pauses) as $row) {
            $byTeam[$row['team']][] = $row['presence'];
        }
        return [
            'n1' => PresenceEngine::summarize($byTeam['n1']),
            'n2' => PresenceEngine::summarize($byTeam['n2']),
            'lideranca' => PresenceEngine::summarize($byTeam['lideranca']),
            'total' => PresenceEngine::summarize(array_merge($byTeam['n1'], $byTeam['n2'])),
        ];
    }

    /**
     * [F7, D14] Situacao de agora por colaborador para o technicians.php:
     * id => situacao, origem e detalhe, mais 'parcial' (horario da proxima
     * ausencia parcial de hoje, quando ha marcador) e 'categoria' (P9: so a
     * gestao e o proprio). Motivo de pausa fora da lista fechada vira "Pausa"
     * para quem nao e gestao nem o proprio (DV10, regra do SEC-07b).
     * Observacao nunca. Fora do Mapa ou de N1, N2 e Lideranca: sem chave.
     */
    public function technicianPresence(DateTimeImmutable $now, ?array $pauses, array $viewer): array {
        $time = $now->format('H:i');
        $items = [];
        foreach ($this->nowPresences($now, $pauses) as $id => $row) {
            $presence = $row['presence'];
            $personal = self::seesPersonal($viewer, $id);
            $absent = $presence['situacao'] === 'ausente';
            $detail = $presence['detalhe'];
            if ($presence['origem'] === 'pausa' && !$personal) {
                $detail = validate_motivo_pausa($detail) ?? 'Pausa';
            }
            $partial = $absent ? null : PresenceEngine::partialMarkerLabel($presence['ausencias'], $time);
            $category = $personal ? self::nowCategory($presence['ausencias'], $absent, $time) : null;
            $items[$id] = [
                'situacao' => $presence['situacao'],
                'origem' => $presence['origem'],
                'detalhe' => $detail,
            ] + ($partial === null ? [] : ['parcial' => $partial])
              + ($category === null ? [] : ['categoria' => OperationalService::absenceCategoryLabel($category)]);
        }
        return $items;
    }

    /**
     * [F7] Situacao de agora de cada cadastro do motor (N1, N2 e Lideranca),
     * numa carga so: as 4 consultas do repositorio, qualquer que seja o numero
     * de cadastros. Fora do Mapa, inativo e equipe fora das tres ficam de fora
     * (0.6). Nao le a data de corte (DV3). $pauses: id => ['em_pausa' => bool,
     * 'motivo' => ?string], do estado ja carregado pelo init.php (DV2), ou null
     * com o estado de pausas ilegivel (DV6): ai ninguem conta em pausa, e quem
     * chama avisa. A categoria entra no motor so para a P9 do technicians.php.
     */
    private function nowPresences(DateTimeImmutable $now, ?array $pauses): array {
        $today = $now->format('Y-m-d');
        $employees = $this->repository->employees();
        $rules = $this->repository->rulesByEmployee($today, $today);
        $exceptions = $this->repository->exceptionsByDay($today, $today);
        $absences = $this->repository->absencesByEmployee($today, $today);
        $presences = [];
        foreach ($employees as $employee) {
            if ($employee['outside_map'] || $employee['team'] === null) {
                continue;
            }
            $id = $employee['id'];
            $exception = $exceptions[$id . ':' . $today] ?? null;
            $presences[$id] = [
                'team' => $employee['team'],
                'presence' => PresenceEngine::nowPresence(
                    $employee,
                    $now,
                    $rules[$id] ?? [],
                    $exception,
                    self::absencesForDay($today, $exception, $absences[$id] ?? [], true),
                    $pauses === null ? null : ($pauses[$id] ?? null)
                ),
            ];
        }
        return $presences;
    }

    /**
     * [F7, P9] Categoria da ausencia que decide agora: ausente, a de dia
     * inteiro ou a primeira parcial ativa (a mesma ordem do motor); senao, a
     * do marcador (menor inicio ainda por vir, empate pela ordem). Excecao de
     * ausencia antiga nao tem categoria.
     */
    private static function nowCategory(array $items, bool $absent, string $time): ?string {
        $chosen = null;
        foreach ($items as $item) {
            if ($absent) {
                if ($item['period_type'] === 'full_day') {
                    return $item['category'] ?? null;
                }
                if ($chosen === null && $item['ativa']) {
                    $chosen = $item;
                }
                continue;
            }
            $start = $item['start_time'] ?? null;
            if ($item['period_type'] !== 'full_day' && $start !== null && $start > $time
                && ($chosen === null || $start < $chosen['start_time'])) {
                $chosen = $item;
            }
        }
        return $chosen['category'] ?? null;
    }

    /** Gestao ve tudo; tecnico e somente leitura, so o proprio cadastro (P9, SEC-07b). */
    private static function seesPersonal(array $viewer, int $employeeId): bool {
        if (portal_role_is_manager($viewer['role'] ?? null)) {
            return true;
        }
        $own = (int)($viewer['employee_id'] ?? 0);
        return $own > 0 && $own === $employeeId;
    }

    /**
     * Ausencias de um dia: excecao de ausencia antiga (dia inteiro, decisao 2
     * do F3) e as aprovadas que cobrem a data, com o periodo. $showCategory:
     * P9; a observacao nunca entra.
     */
    private static function absencesForDay(string $date, ?array $exception, array $rows, bool $showCategory = false): array {
        $absences = [];
        if (in_array($exception['exception_type'] ?? null, PresenceEngine::ABSENCE_EXCEPTIONS, true)) {
            $absences[] = ['origem' => 'excecao', 'period_type' => 'full_day'];
        }
        foreach ($rows as $row) {
            if ($row['starts_on'] <= $date && $row['ends_on'] >= $date) {
                $absences[] = [
                    'origem' => 'ausencia',
                    'period_type' => $row['period_type'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                ] + ($showCategory ? ['category' => $row['category']] : []);
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
