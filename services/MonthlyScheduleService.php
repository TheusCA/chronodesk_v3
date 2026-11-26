<?php
declare(strict_types=1);

require_once __DIR__ . '/MonthlySchedulePlanner.php';
require_once __DIR__ . '/OperationalService.php';
require_once __DIR__ . '/PaInventoryRules.php';

/**
 * [F10-4] Escala do mes no portal (desenho do F10, 3.5 e 8.9; migration 023).
 *
 * - previa: calcula com os dados atuais (presenca pelo motor, sem feriado;
 *   PA pelo motor de distribuicao, via planner) e devolve a grade, o RESUMO e o hash; nada
 *   e gravado;
 * - publicar e regerar: transacao com o mes travado (FOR UPDATE), 409 pela
 *   revisao esperada, pela row_version e pelo hash (o servidor recalcula;
 *   nunca grava o que o cliente mandou); grava a revisao e as linhas a partir
 *   da data inicial; o passado nunca e reescrito (D-F10-2);
 * - leitura: a linha vigente de cada dia (a revisao de maior numero com
 *   from_date <= dia), com a divergencia contra a presenca de hoje (D-F10-14);
 * - travas de PA por dia (pa_map.manage);
 * - o JSON paridade-escala-v1 (so admin; desenho 8.1).
 * Auditoria so com IDs e contagens.
 */
final class MonthlyScheduleService
{
    public const FORBIDDEN_MESSAGE = 'Apenas administradores e Liderança geram e publicam a escala do mês.';
    public const STALE_REVISION = 'Outra publicação aconteceu; recarregue a prévia.';
    public const STALE_INPUT = 'Os dados mudaram desde a prévia; confira a prévia nova.';
    public const PAST_MONTH = 'Mês passado: a escala não é reescrita.';

    private PDO $pdo;
    private ?string $today;
    private ?Closure $audit;

    public function __construct(?PDO $pdo = null, ?string $today = null, ?Closure $audit = null)
    {
        $this->pdo = $pdo ?? get_db_connection();
        $this->today = $today;
        $this->audit = $audit;
    }

    // ------------------------------------------------------------------
    // Previa e publicacao
    // ------------------------------------------------------------------

    /** Previa (nada e gravado). $data: mes, from opcional. */
    public function preview(array $data, array $actor): array
    {
        $this->assertCanPublish($actor);
        $month = $this->month($data['mes'] ?? null);
        $from = $this->fromDate($month, $data['from'] ?? null, $this->state($month, false)['current_revision'] > 0);
        $computed = $this->compute($month, $from);
        $state = $this->state($month, false);
        return $this->view($month, $computed, $state) + ['from_date' => $from];
    }

    /**
     * Publica ou regera: $data mes, from_date, expected_revision, row_version,
     * input_hash. 409 se outra publicacao aconteceu ou se os dados mudaram.
     */
    public function publish(array $data, array $actor): array
    {
        $this->assertCanPublish($actor);
        $month = $this->month($data['mes'] ?? null);
        $expected = $this->intValue($data['expected_revision'] ?? null, 'Revisão esperada inválida.');
        $hash = $data['input_hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException('Hash da prévia inválido: gere a prévia de novo.');
        }
        return $this->atomic(function () use ($month, $expected, $hash, $data, $actor): array {
            $state = $this->state($month, true);
            if ($state['current_revision'] !== $expected) {
                throw new DomainException(self::STALE_REVISION);
            }
            $from = $this->fromDate($month, $data['from_date'] ?? null, $state['current_revision'] > 0);
            $computed = $this->compute($month, $from);
            if (!hash_equals($computed['input_hash'], $hash)) {
                throw new DomainException(self::STALE_INPUT);
            }
            $revision = $state['current_revision'] + 1;
            $reason = $state['current_revision'] === 0 ? 'publicacao' : 'regeracao';
            $by = (string)($actor['username'] ?? $actor['login'] ?? 'portal');
            $stmt = $this->pdo->prepare('INSERT INTO portal_monthly_schedule_revisions (schedule_id, revision, from_date, input_hash, engine_version, reason, published_by, counts)
                VALUES (:schedule, :revision, :from_date, :hash, :engine, :reason, :by, :counts)');
            $stmt->execute([':schedule' => $state['id'], ':revision' => $revision, ':from_date' => $from, ':hash' => $computed['input_hash'],
                ':engine' => $computed['engine_version'], ':reason' => $reason, ':by' => $by, ':counts' => json_encode($computed['counts'])]);
            $revisionId = (int)$this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO portal_monthly_schedule_entries (revision_id, work_date, employee_id, situation, source, team_snapshot, pa_number, pa_source, no_pa, moved)
                VALUES (:revision, :date, :employee, :situation, :source, :team, :pa, :pa_source, :no_pa, :moved)');
            foreach ($computed['entries'] as $entry) {
                $insert->execute([':revision' => $revisionId, ':date' => $entry['work_date'], ':employee' => $entry['employee_id'], ':situation' => $entry['situation'],
                    ':source' => $entry['source'], ':team' => $entry['team_snapshot'], ':pa' => $entry['pa_number'], ':pa_source' => $entry['pa_source'],
                    ':no_pa' => $entry['no_pa'], ':moved' => $entry['moved']]);
            }
            $this->pdo->prepare('UPDATE portal_monthly_schedules SET current_revision = :revision, row_version = row_version + 1 WHERE id = :id')
                ->execute([':revision' => $revision, ':id' => $state['id']]);
            $counts = $computed['counts'];
            $this->audit($reason === 'publicacao' ? 'ESCALA_MENSAL_PUBLICADA' : 'ESCALA_MENSAL_REGERADA',
                sprintf('mes=%s; revisao=%d; de=%s; dias=%d; linhas=%d; presenciais=%d; sem_pa=%d; trocas=%d; atencao_config=%d; pa_insuficiente=%d',
                    $month, $revision, $from, $counts['dias'], $counts['linhas'], $counts['presenciais'], $counts['sem_pa'], $counts['trocas'], $counts['atencao_config'], $counts['pa_insuficiente']),
                $reason === 'publicacao' ? 'INFO' : 'WARNING');
            return ['mes' => $month, 'revision' => $revision, 'reason' => $reason, 'from_date' => $from, 'counts' => $counts];
        });
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * O mes publicado, com a divergencia contra a presenca de hoje. Todos
     * leem (D-F10-10): situacao e PA, sem categoria de ausencia.
     */
    public function read(array $query, array $actor): array
    {
        $month = $this->month($query['mes'] ?? $this->currentMonth());
        $state = $this->state($month, false);
        $days = MonthlySchedulePlanner::businessDays($month);
        $entries = $state['current_revision'] > 0 ? $this->publishedEntries($state['id'], $days) : [];
        $presence = $this->operational()->monthPresence($days[0], end($days));
        $divergences = MonthlySchedulePlanner::divergences($entries, $presence);
        $people = [];
        foreach ($presence as $row) {
            $people[(int)$row['employee_id']] ??= ['id' => (int)$row['employee_id'], 'name' => $row['name'], 'team' => PortalTeams::normalize((string)$row['team'])];
        }
        $cells = [];
        foreach ($entries as $entry) {
            $key = $entry['employee_id'] . ':' . $entry['work_date'];
            $cells[$key] = [
                'situation' => $entry['situation'],
                'pa' => $entry['pa_number'],
                'pa_source' => $entry['pa_source'],
                'no_pa' => (bool)$entry['no_pa'],
                'moved' => (bool)$entry['moved'],
                'revision' => (int)$entry['revision'],
            ] + (isset($divergences[$key]) ? ['divergence' => $divergences[$key]] : []);
        }
        $summary = $this->publishedSummary($state['id'], $state['current_revision']);
        return [
            'mes' => $month,
            'label' => self::monthLabel($month),
            'prev' => (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m'),
            'next' => (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m'),
            'today' => $this->today(),
            'days' => $days,
            'holidays' => array_keys((new PresenceRepository($this->pdo))->holidaysByDate($days[0], end($days))),
            'current_revision' => $state['current_revision'],
            'row_version' => $state['row_version'],
            'revisions' => $this->revisions($state['id']),
            'people' => array_values(array_filter($people, static fn (array $p): bool => isset(array_flip(array_column($entries, 'employee_id'))[$p['id']]))),
            'cells' => $cells,
            'summary' => $summary,
            'divergences' => count($divergences),
            'can_publish' => portal_role_can((string)($actor['role'] ?? ''), 'escala_mensal.publish'),
            'can_lock' => portal_role_can((string)($actor['role'] ?? ''), 'pa_map.manage'),
            'earliest_from' => MonthlySchedulePlanner::earliestFrom($month, $this->today()),
        ];
    }

    /** JSON paridade-escala-v1 (so admin): o publicado ou, sem publicacao, a previa. Sem nome. */
    public function parity(array $query, array $actor): array
    {
        if (($actor['role'] ?? null) !== 'admin') {
            throw new PortalForbiddenException('Somente administradores leem a paridade da escala.');
        }
        $month = $this->month($query['mes'] ?? null);
        $days = MonthlySchedulePlanner::businessDays($month);
        $lines = [];
        foreach ($this->operational()->monthPresence($days[0], end($days)) as $row) {
            $lines[] = [
                'employee_id' => (int)$row['employee_id'],
                'data' => $row['date'],
                'situacao' => MonthlySchedulePlanner::paritySituation((string)$row['presence_type']),
                'fonte' => MonthlySchedulePlanner::paritySource((string)$row['source']),
                'rule_type' => $row['rule_type'],
            ];
        }
        usort($lines, static fn (array $a, array $b): int => [$a['data'], $a['employee_id']] <=> [$b['data'], $b['employee_id']]);
        $state = $this->state($month, false);
        $pa = [];
        $source = 'previa';
        if ($state['current_revision'] > 0) {
            $source = 'publicado';
            foreach ($this->publishedEntries($state['id'], $days) as $entry) {
                if ($entry['pa_number'] !== null || (int)$entry['no_pa'] === 1) {
                    $pa[] = ['employee_id' => (int)$entry['employee_id'], 'data' => $entry['work_date'], 'pa' => $entry['pa_number'], 'sem_pa' => (bool)$entry['no_pa'], 'troca' => (bool)$entry['moved']];
                }
            }
        } else {
            foreach ($this->compute($month, $days[0])['entries'] as $entry) {
                if ($entry['pa_number'] !== null || $entry['no_pa'] === 1) {
                    $pa[] = ['employee_id' => $entry['employee_id'], 'data' => $entry['work_date'], 'pa' => $entry['pa_number'], 'sem_pa' => $entry['no_pa'] === 1, 'troca' => $entry['moved'] === 1];
                }
            }
        }
        $this->audit('ESCALA_MENSAL_PARIDADE', sprintf('mes=%s; linhas=%d; pa=%d; fonte=%s', $month, count($lines), count($pa), $source), 'INFO');
        return ['formato' => 'paridade-escala-v1', 'mes' => $month, 'fonte_pa' => $source, 'linhas' => $lines, 'pa' => $pa];
    }

    // ------------------------------------------------------------------
    // Travas de PA por dia
    // ------------------------------------------------------------------

    /** Cria ($employee_id) ou remove (sem employee_id) a trava do PA no dia. */
    public function lock(array $data, array $actor): array
    {
        if (!portal_role_can((string)($actor['role'] ?? ''), 'pa_map.manage')) {
            throw new PortalForbiddenException('Apenas administradores e Liderança travam PA no dia.');
        }
        $date = $this->dateValue($data['date'] ?? null);
        if ($date <= $this->today()) {
            throw new DomainException('A trava vale só do próximo dia útil em diante.');
        }
        $pa = $data['pa'] ?? null;
        if (!is_string($pa) && !is_int($pa) || preg_match('/^\d{1,6}$/', (string)$pa) !== 1) {
            throw new InvalidArgumentException('PA inválido.');
        }
        $pa = (string)$pa;
        $employee = $data['employee_id'] ?? null;
        return $this->atomic(function () use ($date, $pa, $employee, $actor): array {
            $this->pdo->prepare('DELETE FROM portal_pa_day_locks WHERE work_date = :date AND pa_number = :pa')->execute([':date' => $date, ':pa' => $pa]);
            if ($employee === null || $employee === '') {
                $this->audit('PA_TRAVA_REMOVIDA', "data={$date}; pa={$pa}", 'INFO');
                return ['date' => $date, 'pa' => $pa, 'employee_id' => null];
            }
            $id = $this->intValue($employee, 'Colaborador inválido.');
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM portal_pa_inventory WHERE pa_number = :pa AND active = 1');
            $exists->execute([':pa' => $pa]);
            if ((int)$exists->fetchColumn() === 0) {
                throw new DomainException('PA fora do inventário ativo.');
            }
            $this->pdo->prepare('DELETE FROM portal_pa_day_locks WHERE work_date = :date AND employee_id = :id')->execute([':date' => $date, ':id' => $id]);
            $this->pdo->prepare('INSERT INTO portal_pa_day_locks (work_date, pa_number, employee_id, created_by) VALUES (:date, :pa, :id, :by)')
                ->execute([':date' => $date, ':pa' => $pa, ':id' => $id, ':by' => (string)($actor['username'] ?? 'portal')]);
            $this->audit('PA_TRAVA_CRIADA', "data={$date}; pa={$pa}; employee_id={$id}", 'INFO');
            return ['date' => $date, 'pa' => $pa, 'employee_id' => $id];
        });
    }

    // ------------------------------------------------------------------
    // Carga das entradas
    // ------------------------------------------------------------------

    /** Calcula o mes a partir de $from com as entradas atuais (MonthlySchedulePlanner). */
    public function compute(string $month, string $from): array
    {
        return MonthlySchedulePlanner::compute($this->inputs($month, $from));
    }

    public function inputs(string $month, string $from): array
    {
        $days = MonthlySchedulePlanner::businessDays($month, $from);
        if ($days === []) {
            throw new DomainException('Nenhum dia útil a calcular a partir dessa data.');
        }
        $to = end($days);
        return [
            'month' => $month,
            'from' => $from,
            'presence' => $this->operational()->monthPresence($from, $to),
            'people' => $this->people(),
            'rotation' => $this->rotation($from, $to),
            'seats' => $this->seats($from, $to),
            'preferences' => $this->preferences(),
            'locks' => $this->locks($from, $to),
            'last_pa' => $this->lastPa($from),
        ];
    }

    private function people(): array
    {
        $people = [];
        foreach ($this->pdo->query('SELECT id, grupo_escala FROM funcionarios WHERE ativo = 1 OR arquivado_em IS NOT NULL ORDER BY id')->fetchAll() as $row) {
            $people[(int)$row['id']] = ['grupo' => (string)$row['grupo_escala']];
        }
        return $people;
    }

    /** [data => [employee_id => nome do time]] pela pertenca vigente na data. */
    private function rotation(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare('SELECT m.employee_id, m.valid_from, m.valid_until, t.name FROM portal_rotation_team_members m
            JOIN portal_rotation_teams t ON t.id = m.team_id WHERE m.valid_from <= :to AND (m.valid_until IS NULL OR m.valid_until >= :from) ORDER BY m.employee_id, m.valid_from');
        $stmt->execute([':from' => $from, ':to' => $to]);
        $members = $stmt->fetchAll();
        $result = [];
        foreach (MonthlySchedulePlanner::businessDays(substr($from, 0, 7), $from) as $date) {
            foreach ($members as $member) {
                if ($member['valid_from'] <= $date && ($member['valid_until'] === null || $member['valid_until'] >= $date)) {
                    $result[$date][(int)$member['employee_id']] = (string)$member['name'];
                }
            }
        }
        return $result;
    }

    /** Inventario no formato do motor, com o dono fixo pelos vinculos com vigencia. */
    private function seats(string $from, string $to): array
    {
        $owners = [];
        $stmt = $this->pdo->prepare('SELECT pa_number, employee_id, valid_from, valid_until FROM portal_pa_assignments
            WHERE active = 1 AND deleted_at IS NULL AND valid_from <= :to AND (valid_until IS NULL OR valid_until >= :from) ORDER BY pa_number, valid_from, id');
        $stmt->execute([':from' => $from, ':to' => $to]);
        foreach ($stmt->fetchAll() as $row) {
            $owners[(string)$row['pa_number']][] = ['employee_id' => (int)$row['employee_id'], 'from' => $row['valid_from'], 'until' => $row['valid_until']];
        }
        $seats = [];
        foreach ($this->pdo->query('SELECT pa_number, grid_row, grid_column, status, active, seat_type, can_lend, reserved_team FROM portal_pa_inventory ORDER BY grid_row, grid_column, pa_number')->fetchAll() as $row) {
            if (preg_match('/^\d+$/', (string)$row['pa_number']) !== 1) {
                continue;
            }
            $stored = PaInventoryRules::storedSeat($row);
            $fixed = $stored['seat_type'] === 'fixed';
            $seats[] = [
                'pa' => (int)$row['pa_number'],
                'row' => (int)$row['grid_row'],
                'column' => (int)$row['grid_column'],
                'type' => $fixed ? 'fixed' : 'flexible',
                'can_lend' => $fixed && $stored['can_lend'],
                'reserved_for' => $fixed && $stored['reserved_team'] === PortalTeams::CRITICAL ? 'critical' : null,
                'active' => (int)$row['active'] === 1,
                'unavailable' => (string)$row['status'] !== 'active',
                'owners' => $fixed && $stored['reserved_team'] === null ? ($owners[(string)$row['pa_number']] ?? []) : [],
            ];
        }
        return $seats;
    }

    private function preferences(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT i.pa_number, p.employee_id FROM portal_pa_preferences p JOIN portal_pa_inventory i ON i.id = p.pa_id ORDER BY i.pa_number, p.employee_id')->fetchAll() as $row) {
            $result[(int)$row['pa_number']][] = (int)$row['employee_id'];
        }
        return $result;
    }

    private function locks(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare('SELECT work_date, pa_number, employee_id FROM portal_pa_day_locks WHERE work_date BETWEEN :from AND :to ORDER BY work_date, pa_number');
        $stmt->execute([':from' => $from, ':to' => $to]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string)$row['work_date']][] = ['pa' => (int)$row['pa_number'], 'employee_id' => (int)$row['employee_id']];
        }
        return $result;
    }

    /**
     * last_pa do ultimo dia util publicado antes de $from (desenho 3.4): o do
     * mes anterior na 1a publicacao, o do dia anterior na regeracao. Sem
     * publicacao anterior, vazio (D-F10-12).
     */
    private function lastPa(string $from): array
    {
        $stmt = $this->pdo->prepare('SELECT MAX(e.work_date) FROM portal_monthly_schedule_entries e WHERE e.work_date < :from AND e.work_date >= :since');
        $stmt->execute([':from' => $from, ':since' => (new DateTimeImmutable($from))->modify('-40 days')->format('Y-m-d')]);
        $day = $stmt->fetchColumn();
        if (!is_string($day) || $day === '') {
            return [];
        }
        $result = [];
        foreach ($this->entriesOn([$day]) as $entry) {
            if ($entry['pa_number'] !== null && $entry['pa_source'] !== 'reserved') {
                $result[(int)$entry['employee_id']] = (int)$entry['pa_number'];
            }
        }
        ksort($result);
        return $result;
    }

    // ------------------------------------------------------------------
    // Leitura do publicado
    // ------------------------------------------------------------------

    /** Linha vigente de cada pessoa e dia: a revisao de maior numero com from_date <= dia. */
    private function publishedEntries(int $scheduleId, array $days): array
    {
        $stmt = $this->pdo->prepare('SELECT e.work_date, e.employee_id, e.situation, e.source, e.team_snapshot, e.pa_number, e.pa_source, e.no_pa, e.moved, r.revision
            FROM portal_monthly_schedule_entries e JOIN portal_monthly_schedule_revisions r ON r.id = e.revision_id
            WHERE r.schedule_id = :schedule AND r.from_date <= e.work_date
              AND r.revision = (SELECT MAX(r2.revision) FROM portal_monthly_schedule_revisions r2 WHERE r2.schedule_id = r.schedule_id AND r2.from_date <= e.work_date)
            ORDER BY e.work_date, e.employee_id');
        $stmt->execute([':schedule' => $scheduleId]);
        $wanted = array_flip($days);
        return array_values(array_filter($stmt->fetchAll(), static fn (array $row): bool => isset($wanted[(string)$row['work_date']])));
    }

    private function entriesOn(array $days): array
    {
        $result = [];
        foreach ($days as $day) {
            $schedule = $this->pdo->prepare('SELECT id FROM portal_monthly_schedules WHERE ref_month = :month');
            $schedule->execute([':month' => substr($day, 0, 7) . '-01']);
            $id = $schedule->fetchColumn();
            if ($id !== false) {
                $result = array_merge($result, $this->publishedEntries((int)$id, [$day]));
            }
        }
        return $result;
    }

    private function publishedSummary(int $scheduleId, int $revision): ?array
    {
        if ($revision === 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT counts FROM portal_monthly_schedule_revisions WHERE schedule_id = :id AND revision = :revision');
        $stmt->execute([':id' => $scheduleId, ':revision' => $revision]);
        $counts = json_decode((string)$stmt->fetchColumn(), true);
        return is_array($counts) ? $counts : null;
    }

    private function revisions(int $scheduleId): array
    {
        if ($scheduleId === 0) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT revision, from_date, reason, published_by, published_at FROM portal_monthly_schedule_revisions WHERE schedule_id = :id ORDER BY revision DESC');
        $stmt->execute([':id' => $scheduleId]);
        return array_map(static fn (array $r): array => ['revision' => (int)$r['revision'], 'from_date' => $r['from_date'], 'reason' => $r['reason'], 'by' => $r['published_by'], 'at' => $r['published_at']], $stmt->fetchAll());
    }

    // ------------------------------------------------------------------
    // Estado do mes, validacao e apoio
    // ------------------------------------------------------------------

    /**
     * Estado do mes. Com $lock, cria a linha se faltar (a chave unica serializa
     * a criacao; duplicada relê) e trava com FOR UPDATE.
     */
    private function state(string $month, bool $lock): array
    {
        $ref = $month . '-01';
        if ($lock) {
            $this->pdo->prepare('INSERT IGNORE INTO portal_monthly_schedules (ref_month, created_by) VALUES (:month, :by)')->execute([':month' => $ref, ':by' => 'portal']);
        }
        $stmt = $this->pdo->prepare('SELECT id, current_revision, row_version FROM portal_monthly_schedules WHERE ref_month = :month' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([':month' => $ref]);
        $row = $stmt->fetch();
        return $row === false
            ? ['id' => 0, 'current_revision' => 0, 'row_version' => 0]
            : ['id' => (int)$row['id'], 'current_revision' => (int)$row['current_revision'], 'row_version' => (int)$row['row_version']];
    }

    /**
     * Data inicial (D-F10-2): o proximo dia util depois de hoje, no minimo;
     * 1a publicacao de mes futuro, o 1o dia util do mes. Mes passado: 409.
     */
    private function fromDate(string $month, mixed $requested, bool $published): string
    {
        $earliest = MonthlySchedulePlanner::earliestFrom($month, $this->today());
        if ($earliest === null) {
            throw new DomainException(self::PAST_MONTH);
        }
        $first = MonthlySchedulePlanner::businessDays($month)[0];
        if ($requested === null || $requested === '') {
            return !$published && $first > $this->today() ? $first : $earliest;
        }
        $date = $this->dateValue($requested);
        if (substr($date, 0, 7) !== $month || !in_array($date, MonthlySchedulePlanner::businessDays($month), true)) {
            throw new InvalidArgumentException('A data inicial precisa ser um dia útil do mês.');
        }
        if ($date < $earliest) {
            throw new DomainException('O passado não é reescrito: a data inicial mínima é ' . self::dateLabel($earliest) . '.');
        }
        return $date;
    }

    private function view(string $month, array $computed, array $state): array
    {
        $cells = [];
        foreach ($computed['entries'] as $entry) {
            $cells[$entry['employee_id'] . ':' . $entry['work_date']] = ['situation' => $entry['situation'], 'pa' => $entry['pa_number'], 'pa_source' => $entry['pa_source'],
                'no_pa' => $entry['no_pa'] === 1, 'moved' => $entry['moved'] === 1];
        }
        $changed = 0;
        if ($state['current_revision'] > 0) {
            $published = [];
            foreach ($this->publishedEntries($state['id'], $computed['days']) as $entry) {
                $published[$entry['employee_id'] . ':' . $entry['work_date']] = [$entry['situation'], $entry['pa_number']];
            }
            foreach ($cells as $key => $cell) {
                $changed += ($published[$key] ?? null) === [$cell['situation'], $cell['pa']] ? 0 : 1;
            }
        }
        return [
            'mes' => $month,
            'days' => $computed['days'],
            'cells' => $cells,
            'summary' => $computed['summary'],
            'counts' => $computed['counts'],
            'input_hash' => $computed['input_hash'],
            'engine_version' => $computed['engine_version'],
            'current_revision' => $state['current_revision'],
            'row_version' => $state['row_version'],
            'changed_cells' => $changed,
        ];
    }

    private function assertCanPublish(array $actor): void
    {
        if (!portal_role_can((string)($actor['role'] ?? ''), 'escala_mensal.publish')) {
            throw new PortalForbiddenException(self::FORBIDDEN_MESSAGE);
        }
    }

    private function month(mixed $value): string
    {
        MonthlySchedulePlanner::monthStart($value);
        return (string)$value;
    }

    private function dateValue(mixed $value): string
    {
        if (!is_string($value) || !PresenceEngine::validDate($value)) {
            throw new InvalidArgumentException('Data inválida.');
        }
        return $value;
    }

    private function intValue(mixed $value, string $message): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($int === false || is_bool($value)) {
            throw new InvalidArgumentException($message);
        }
        return (int)$int;
    }

    private function today(): string
    {
        return $this->today ?? (new DateTimeImmutable('now'))->format('Y-m-d');
    }

    private function currentMonth(): string
    {
        return substr($this->today(), 0, 7);
    }

    private function operational(): OperationalService
    {
        return new OperationalService($this->pdo, $this->today);
    }

    public static function monthLabel(string $month): string
    {
        $names = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        return $names[(int)substr($month, 5, 2) - 1] . ' ' . substr($month, 0, 4);
    }

    private static function dateLabel(string $date): string
    {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }

    private function audit(string $action, string $details, string $severity): void
    {
        if ($this->audit !== null) {
            ($this->audit)($action, $details, $severity);
        } elseif (function_exists('audit_log')) {
            audit_log($action, $details, $severity);
        }
    }

    private function atomic(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
