<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/PortalTeams.php';
require_once __DIR__ . '/OperationalService.php';
require_once __DIR__ . '/PaInventoryRules.php';
require_once __DIR__ . '/PaDistributionEngine.php';

final class PaMapService {
    public const MAX_PAYLOAD_BYTES = 32768;

    private const DEFAULT_PA_NUMBERS = [
        '1732', '1731', '1730', '1729', '1728', '1727', '1726', '1725',
        '1724', '1723', '1722', '1721', '1720', '1719', '1718', '1717',
    ];
    private const RULE_TYPES = [
        'even_days', 'odd_days', 'always_onsite', 'always_remote', 'undefined', 'fixed_weekdays', 'cycle',
    ];

    /** [F6, DF4] Dias uteis do aviso de conflito ao salvar e quantos voltam na resposta. */
    private const CONFLICT_HORIZON_DAYS = 20;
    private const CONFLICT_LIST_LIMIT = 10;

    /** [F6, DF7] Campos do vinculo so para quem edita o mapa. */
    private const MANAGER_ONLY_FIELDS = ['employee_login', 'notes', 'created_by', 'updated_by'];

    private PDO $pdo;
    private ?string $today;
    private ?OperationalService $operational = null;
    private array $tables = [];

    public function __construct(?PDO $pdo = null, ?string $today = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->today = $today;
    }

    /**
     * [F6, commit 1] Leitura do mapa pelo motor (desenho 9.8): presenca de
     * cada vinculo na data (RF-08), conflito por PA e dia util da semana da
     * data (dois ou mais presenciais; RF-05, P13), ausencias da semana. Nao le
     * a copia da regra (L-02) nem a data de corte (DF1); numero de consultas
     * fixo (L-10). $canManage (pa_map.manage): sem ele, sem a lista de
     * elegiveis e sem login, observacao e autores do vinculo (DF7).
     */
    public function list(array $filters, array $actor = [], bool $canManage = false): array {
        $date = $this->date($filters['date'] ?? $this->today(), 'Data');
        $team = $this->team($filters['team'] ?? null, true);
        [$weekFrom, $weekTo] = self::week($date);
        $presence = $this->operational()->mapPresence($weekFrom, max($weekTo, $date), $actor);
        $inventory = $this->inventory();
        // [F10-3] Com a 022: tipo, emprestimo, reserva, preferencias (gestao) e
        // quem ocupa os reservados do Critico na data (papeis do 9.16).
        $seatSetup = $this->seatSetupReady();
        $criticalSeating = null;
        if ($seatSetup) {
            $inventory = $this->withSeatSetup($inventory, $canManage);
            $criticalSeating = $this->criticalSeating($inventory, $date, $presence);
        }
        $items =array_merge($this->assignmentsForMap($date, $team, $presence), $this->legacySpecificDateAssignments($date, $team, $presence));
        $weekRows = $this->weekRows($weekFrom, $weekTo);
        $conflicts = $this->weekConflicts($weekRows, $presence, $weekFrom, $weekTo);
        if (!$canManage) {
            $items = array_map(static fn (array $item): array => array_diff_key($item, array_flip(self::MANAGER_ONLY_FIELDS)), $items);
        }
        $byPa = [];
        foreach ($items as $assignment) {
            $byPa[$assignment['pa_number']][] = $assignment;
        }
        $conflictDays = [];
        foreach ($conflicts as $conflict) {
            $conflictDays[$conflict['pa_number']][] = $conflict['date'];
        }

        $pas = [];
        foreach ($inventory as $pa) {
            $pas[] = $pa + [
                'assignments' => $byPa[$pa['pa_number']] ?? [],
                'has_conflict' => isset($conflictDays[$pa['pa_number']]),
                'conflict_days' => $conflictDays[$pa['pa_number']] ?? [],
            ] + ($criticalSeating !== null && array_key_exists($pa['pa_number'], $criticalSeating['by_pa'])
                ? ['reserved_occupant' => $criticalSeating['by_pa'][$pa['pa_number']]]
                : []);
        }

        return [
            'date' => $date,
            'team' => $team,
            'server_now' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'week' => ['from' => $weekFrom, 'to' => $weekTo],
            'pas' => $pas,
            'items' => $items,
            'conflicts' => $conflicts,
            'absences_week' => $this->weekAbsences($weekRows, $presence, $team, $weekFrom, $weekTo),
            'seat_setup' => $seatSetup,
        ] + ($criticalSeating === null ? [] : ['critical_seating' => [
            'seats' => $criticalSeating['seats'],
            'onsite' => $criticalSeating['onsite'],
            'without_seat' => $criticalSeating['without_seat'],
        ]]) + ($canManage ? ['employees' => $this->eligibleEmployees($date, $team, $presence)] : []);
    }

    /**
     * Elegiveis para o formulario (so para quem edita o mapa): cadastros
     * ativos fora do "fora do Mapa", com a escala do dia pelo motor (fim de
     * semana: a regra do ultimo dia util da semana, situacao sem escala).
     */
    public function eligibleEmployees(string $date, ?string $team = null, ?array $presence = null): array {
        $date = $this->date($date, 'Data');
        $team = $this->team($team, true);
        if ($presence === null) {
            [$weekFrom, $weekTo] = self::week($date);
            $presence = $this->operational()->mapPresence($weekFrom, max($weekTo, $date), []);
        }
        // [P12, F1] Cadastro fora do Mapa PA e da escala nao e elegivel.
        $sql = 'SELECT id, nome AS name, equipe AS team, ad_login
                FROM funcionarios
                WHERE ativo = 1 AND fora_mapa_escala = 0';
        $params = [];
        if ($team) {
            $sql .= ' AND LOWER(equipe) IN (' . $this->teamSqlAliases($team) . ')';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY nome');
        $stmt->execute($params);
        $items = [];
        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            $normalizedTeam = $this->normalizeTeam($row['team'] ?? null);
            if ($normalizedTeam === null || ($team !== null && $normalizedTeam !== $team)) {
                continue;
            }
            if (isset($seen[(int)$row['id']])) {
                continue;
            }
            $seen[(int)$row['id']] = true;
            $day = $presence['days'][(int)$row['id'] . ':' . $date] ?? null;
            $rule = $day ?? self::lastBusinessDay($presence, (int)$row['id'], $date);
            $row['team'] = $normalizedTeam;
            $items[] = $row + [
                'schedule_rule_type' => $rule['rule_type'] ?? 'undefined',
                'schedule_rule_label' => $rule['rule_label'] ?? 'Sem escala definida',
                'schedule_status' => $day['presence_type'] ?? 'no_schedule',
                'schedule_label' => $day['label'] ?? 'Sem escala',
                'schedule_source' => $day['source'] ?? 'none',
            ];
        }
        return $items;
    }

    /** Segunda a sexta da semana da data (fim de semana: a semana que termina nele). */
    private static function week(string $date): array {
        $day = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        $monday = $day->modify('-' . ((int)$day->format('N') - 1) . ' days');
        return [$monday->format('Y-m-d'), $monday->modify('+4 days')->format('Y-m-d')];
    }

    /** Dia do motor mais recente antes da data, na mesma carga (regra no fim de semana). */
    private static function lastBusinessDay(array $presence, int $employeeId, string $date): ?array {
        $day = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        for ($i = 1; $i <= 6; $i++) {
            $found = $presence['days'][$employeeId . ':' . $day->modify("-{$i} days")->format('Y-m-d')] ?? null;
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private function operational(): OperationalService {
        return $this->operational ??= new OperationalService($this->pdo, $this->today);
    }

    private function today(): string {
        return $this->today ?? date('Y-m-d');
    }

    /**
     * [F6, commit 2] Gravacao do vinculo (desenho 9.8, P13). Conflito,
     * remoto, ausencia e sem escala nao bloqueiam: viram warnings[] (no 1o dia
     * util do vinculo, DF5) e conflicts[] (horizonte da DF4, ate 10 dias, com
     * conflicts_more). confirm_remote_allocation e aceito e ignorado.
     * Continua recusado: o mesmo colaborador em outro PA no periodo (409, com
     * FOR UPDATE), cadastro inativo ou inexistente (400) e fora do Mapa (409).
     */
    public function save(array $data, string $actor): array {
        return $this->atomic(function () use ($data, $actor): array {
            if (!$this->tableExists('portal_pa_assignments')) {
                throw new DomainException('Tabela de vínculos do Mapa de PA ainda não foi criada.');
            }
            $id = $this->positiveInt($data['id'] ?? null, true);
            $paNumber = $this->paNumber($data['pa_number'] ?? null);
            $employee = $this->employee($data['employee_id'] ?? null);
            if ((int)$employee['fora_mapa_escala'] === 1) {
                throw new DomainException('Cadastro marcado como fora do Mapa PA e da escala: não recebe vínculo de PA.');
            }
            // [9.12.1] Na edicao (com id), chave ausente mantem o gravado, lido
            // com trava: antes, editar pela tela (sem o campo do fim) apagava o
            // fim da validade. Desde a II3 o formulario manda valid_until
            // sempre (data ou vazio); o ausente continua valendo para os outros
            // corpos. Presente, validada como antes: valid_until e notes nulos
            // ou vazios continuam limpando. O gravado passa pelos mesmos validadores.
            $stored = $id === null ? null : $this->lockedAssignment($id);
            $validFrom = $this->date($data['valid_from'] ?? $data['work_date'] ?? ($stored === null ? $this->today() : $stored['valid_from']), 'Início da validade');
            $validUntil = $this->nullableDate(
                $stored !== null && !array_key_exists('valid_until', $data) ? $stored['valid_until'] : ($data['valid_until'] ?? null),
                'Fim da validade'
            );
            if ($validUntil !== null && $validUntil < $validFrom) {
                throw new InvalidArgumentException('Fim da validade não pode ser anterior ao início.');
            }
            $notes = $this->text($stored !== null && !array_key_exists('notes', $data) ? ($stored['notes'] ?? '') : ($data['notes'] ?? ''), 1000, true);
            $employeeId = (int)$employee['id'];
            $this->assertNoOtherPa($employeeId, $validFrom, $validUntil, $id);
            // [F10-3] PA reservado ao Critico nao recebe vinculo (quem ocupa vem
            // dos papeis do dia); PA fixo tem no maximo um dono por data (o
            // conflito da F6 vira 409 nesse tipo). O PA fica travado ate o fim.
            $seat = $this->seatSetupReady() ? $this->lockedSeat($paNumber) : null;
            if ($seat !== null && $seat['reserved_team'] !== null) {
                throw new DomainException('PA reservado ao Crítico: quem ocupa vem dos papéis do dia, e ele não recebe vínculo.');
            }
            if ($seat !== null && $seat['seat_type'] === 'fixed' && $this->ownersInPeriod($paNumber, $validFrom, $validUntil, $id) > 0) {
                throw new DomainException('PA fixo: já tem dono no período. Encerre o vínculo atual antes (um dono por data).');
            }

            // Motor: o horizonte do conflito e, se ficar antes dele, o 1o dia util do vinculo.
            $horizon = $this->conflictHorizon($validFrom, $validUntil);
            $start = self::firstBusinessDay($validFrom, $validUntil);
            $presence = $horizon === [] ? ['days' => []] : $this->operational()->mapPresence($horizon[0], $horizon[count($horizon) - 1], []);
            if ($start !== null && ($horizon === [] || $start < $horizon[0])) {
                $presence['days'] += $this->operational()->mapPresence($start, $start, [])['days'];
            }
            $warnings = self::startWarnings($start === null ? null : ($presence['days'][$employeeId . ':' . $start] ?? null), $start);
            $conflicts = $this->saveConflicts($paNumber, $employeeId, $horizon, $presence, $id);

            $copy = $this->ruleCopy($employeeId, $validFrom);
            $params = [
                ':pa_number' => $paNumber,
                ':employee_id' => $employeeId,
                ':employee_name' => $employee['name'],
                ':employee_login' => $employee['ad_login'],
                ':team' => $employee['team'],
                // [F2B] O ENUM da copia (migrations 009 e 010) nao tem 'cycle':
                // o ciclo e gravado como 'undefined' com a configuracao, e a
                // copia continua gravada para o rollback; desde o F6 a leitura usa o motor (L-02).
                ':schedule_rule_type' => $copy['rule_type'] === 'cycle' ? 'undefined' : $copy['rule_type'],
                ':schedule_rule_config' => $copy['rule_config'],
                ':valid_from' => $validFrom,
                ':valid_until' => $validUntil,
                ':notes' => $notes ?: null,
                ':updated_by' => $actor,
            ];

            if ($id) {
                $stmt = $this->pdo->prepare(
                    'UPDATE portal_pa_assignments
                     SET pa_number = :pa_number,
                         employee_id = :employee_id,
                         employee_name = :employee_name,
                         employee_login = :employee_login,
                         team = :team,
                         schedule_rule_type = :schedule_rule_type,
                         schedule_rule_config = :schedule_rule_config,
                         valid_from = :valid_from,
                         valid_until = :valid_until,
                         notes = :notes,
                         updated_by = :updated_by
                     WHERE id = :id AND active = 1'
                );
                $stmt->execute($params + [':id' => $id]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('Vínculo de PA não encontrado.');
                }
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_pa_assignments
                        (pa_number, employee_id, employee_name, employee_login, team,
                         schedule_rule_type, schedule_rule_config, valid_from, valid_until, notes, created_by, updated_by)
                     VALUES
                        (:pa_number, :employee_id, :employee_name, :employee_login, :team,
                         :schedule_rule_type, :schedule_rule_config, :valid_from, :valid_until, :notes, :created_by, :updated_by)'
                );
                $stmt->execute($params + [':created_by' => $actor]);
                $id = (int)$this->pdo->lastInsertId();
            }

            return [
                'id' => $id,
                'pa_number' => $paNumber,
                'employee_id' => $employeeId,
                'warnings' => $warnings,
                'conflicts' => array_slice($conflicts, 0, self::CONFLICT_LIST_LIMIT),
                'conflicts_more' => max(0, count($conflicts) - self::CONFLICT_LIST_LIMIT),
                'conflict_audit' => self::conflictAudit($id, $paNumber, $conflicts),
            ];
        });
    }

    /**
     * [9.12.1] Vinculo da edicao, travado depois do colaborador (a ordem do
     * save): o gravado que a chave ausente mantem. Inexistente ou desocupado
     * da a mesma recusa da gravacao.
     */
    private function lockedAssignment(int $id): array {
        $stmt = $this->pdo->prepare(
            'SELECT valid_from, valid_until, notes
             FROM portal_pa_assignments
             WHERE id = :id AND active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new DomainException('Vínculo de PA não encontrado.');
        }
        return $row;
    }

    /**
     * [F6, DF11] Desocupa o PA em transacao, com o vinculo travado; devolve o
     * PA e o colaborador para a auditoria (IDs, sem nome nem login).
     */
    public function remove(int $id, string $actor): array {
        return $this->atomic(function () use ($id, $actor): array {
            if (!$this->tableExists('portal_pa_assignments')) {
                throw new DomainException('Tabela de vínculos do Mapa de PA ainda não foi criada.');
            }
            $id = $this->positiveInt($id);
            $stmt = $this->pdo->prepare(
                'SELECT id, pa_number, employee_id
                 FROM portal_pa_assignments
                 WHERE id = :id AND active = 1
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new DomainException('Vínculo de PA não encontrado.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE portal_pa_assignments
                 SET active = 0, deleted_at = CURRENT_TIMESTAMP, updated_by = :updated_by
                 WHERE id = :id AND active = 1'
            );
            $stmt->execute([':updated_by' => $actor, ':id' => $id]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Vínculo de PA não encontrado.');
            }
            return ['id' => $id, 'pa_number' => (string)$row['pa_number'], 'employee_id' => (int)$row['employee_id']];
        });
    }

    /**
     * [F6, DF4] Dias uteis do aviso de conflito ao salvar: do maior entre o
     * inicio do vinculo e hoje, ate 20 dias uteis, sem passar do fim.
     *
     * @return list<string>
     */
    private function conflictHorizon(string $validFrom, ?string $validUntil): array {
        $days = [];
        $day = new DateTimeImmutable(max($validFrom, $this->today()) . ' 00:00:00', new DateTimeZone('UTC'));
        while (count($days) < self::CONFLICT_HORIZON_DAYS && ($validUntil === null || $day->format('Y-m-d') <= $validUntil)) {
            if (PresenceEngine::isBusinessDay($day->format('Y-m-d'))) {
                $days[] = $day->format('Y-m-d');
            }
            $day = $day->modify('+1 day');
        }
        return $days;
    }

    /** 1o dia util do vinculo (o inicio, ou a segunda seguinte), dentro da validade. */
    private static function firstBusinessDay(string $validFrom, ?string $validUntil): ?string {
        $day = new DateTimeImmutable($validFrom . ' 00:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 7; $i++, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ($validUntil !== null && $date > $validUntil) {
                return null;
            }
            if (PresenceEngine::isBusinessDay($date)) {
                return $date;
            }
        }
        return null;
    }

    /** [F6, DF5] Avisos do 1o dia util do vinculo pelo motor; nenhum bloqueia. */
    private static function startWarnings(?array $day, ?string $date): array {
        if ($day === null || $date === null) {
            return [];
        }
        $when = 'em ' . (new DateTimeImmutable($date))->format('d/m/Y') . ', início do vínculo';
        return match ($day['presence_type']) {
            'absence' => ["Colaborador ausente o dia todo {$when}."],
            'remote' => ["Colaborador em trabalho remoto {$when}."],
            'no_schedule' => ["Colaborador sem escala definida {$when}."],
            default => [],
        };
    }

    /**
     * [F6, DF4, DF8, decisao 8 do F4] Dias do horizonte em que o colaborador
     * do vinculo e mais alguem com vinculo no mesmo PA estao presenciais no
     * motor (mesma regra da leitura). O horizonte comeca hoje ou depois: sem
     * vinculo de cadastro inativo, que nao entra no conflito nem na resposta.
     * So aviso; nada e travado.
     */
    private function saveConflicts(string $paNumber, int $employeeId, array $horizon, array $presence, ?int $id): array {
        if ($horizon === []) {
            return [];
        }
        $occupants = $this->paOccupants($paNumber, $horizon[0], $horizon[count($horizon) - 1], $id);
        $onsite = static fn (int $who, string $date): bool => ($presence['days'][$who . ':' . $date]['presence_type'] ?? null) === 'onsite';
        $conflicts = [];
        foreach ($horizon as $date) {
            if (!$onsite($employeeId, $date)) {
                continue;
            }
            $ids = [$employeeId => $employeeId];
            foreach ($occupants as $row) {
                if ($row['from'] <= $date && ($row['until'] === null || $row['until'] >= $date) && $onsite($row['employee_id'], $date)) {
                    $ids[$row['employee_id']] = $row['employee_id'];
                }
            }
            if (count($ids) >= 2) {
                sort($ids);
                $conflicts[] = ['pa_number' => $paNumber, 'date' => $date, 'employee_ids' => $ids];
            }
        }
        return $conflicts;
    }

    /**
     * Vinculos do PA no periodo (recorrentes, menos o que esta sendo
     * editado, e da tabela antiga), de cadastros ativos (decisao 8 do F4).
     */
    private function paOccupants(string $paNumber, string $from, string $to, ?int $id): array {
        $stmt = $this->pdo->prepare(
            'SELECT employee_id, valid_from, valid_until
             FROM portal_pa_assignments
             WHERE active = 1
               AND pa_number = :pa_number
               AND id <> :id
               AND valid_from <= :date_until
               AND (valid_until IS NULL OR valid_until >= :date_from)'
            . $this->activeEmployeeFilter('portal_pa_assignments', $from)
            . ' ORDER BY id'
        );
        $stmt->execute([':pa_number' => $paNumber, ':id' => $id ?: 0, ':date_from' => $from, ':date_until' => $to]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = ['employee_id' => (int)$row['employee_id'], 'from' => (string)$row['valid_from'], 'until' => $row['valid_until'] === null ? null : (string)$row['valid_until']];
        }
        if ($this->tableExists('portal_pa_map')) {
            $stmt = $this->pdo->prepare(
                'SELECT employee_id, work_date
                 FROM portal_pa_map
                 WHERE record_status = "active" AND pa_number = :pa_number AND work_date BETWEEN :date_from AND :date_to'
                . $this->activeEmployeeFilter('portal_pa_map', $from)
                . ' ORDER BY work_date'
            );
            $stmt->execute([':pa_number' => $paNumber, ':date_from' => $from, ':date_to' => $to]);
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = ['employee_id' => (int)$row['employee_id'], 'from' => (string)$row['work_date'], 'until' => (string)$row['work_date']];
            }
        }
        return $rows;
    }

    /**
     * [F6] Detalhe do PA_MAP_CONFLICT_ACCEPTED: vinculo, PA, IDs dos tecnicos
     * e datas em conflito (ate 10, e quantas a mais); sem nome nem login.
     */
    private static function conflictAudit(int $id, string $paNumber, array $conflicts): ?string {
        if ($conflicts === []) {
            return null;
        }
        $ids = array_values(array_unique(array_merge(...array_column($conflicts, 'employee_ids'))));
        sort($ids);
        $dates = array_column(array_slice($conflicts, 0, self::CONFLICT_LIST_LIMIT), 'date');
        $more = count($conflicts) - count($dates);
        return 'Alocacao de PA salva com conflito. ID ' . $id . ', PA ' . $paNumber
            . ', colaboradores ID ' . implode(', ', $ids)
            . ', datas ' . implode(', ', $dates) . ($more > 0 ? " e mais {$more}" : '');
    }

    private function inventory(): array {
        try {
            $stmt = $this->pdo->query(
                'SELECT pa_number, display_order, grid_row, grid_column, label, status
                 FROM portal_pa_inventory
                 WHERE active = 1
                 ORDER BY display_order, pa_number'
            );
            $items = $stmt->fetchAll();
            if ($items !== []) {
                return $items;
            }
        } catch (Throwable $error) {
            error_log('[PA_MAP] Inventario indisponivel; usando PAs padrao.');
        }

        $items = [];
        foreach (self::DEFAULT_PA_NUMBERS as $index => $paNumber) {
            $items[] = [
                'pa_number' => $paNumber,
                'display_order' => $index + 1,
                'grid_row' => intdiv($index, 8) + 1,
                'grid_column' => ($index % 8) + 1,
                'label' => null,
                'status' => 'active',
            ];
        }
        return $items;
    }

    // ------------------------------------------------------------------
    // [F10-3] Inventario completo e preferencias (desenho do F10, 3.3.1)
    // ------------------------------------------------------------------

    /**
     * Grava o tipo, o emprestimo e a reserva de um PA (pa_map.manage), com o
     * PA travado. Reservar ao Critico so sem vinculo vigente ou futuro e sem
     * preferencia; marcar como fixo so sem vinculos sobrepostos de hoje em
     * diante. Devolve o antes e o depois (changed = [] quando nada mudou).
     */
    public function saveSeat(array $data, string $actor): array {
        return $this->atomic(function () use ($data): array {
            $this->requireSeatSetup();
            $paNumber = $this->paNumber($data['pa_number'] ?? null);
            $seat = $this->lockedSeat($paNumber);
            if ($seat === null) {
                throw new InvalidArgumentException('PA não encontrado no inventário.');
            }
            $after = PaInventoryRules::seat($data);
            $before = ['seat_type' => $seat['seat_type'], 'can_lend' => $seat['can_lend'], 'reserved_team' => $seat['reserved_team']];
            $changed = PaInventoryRules::changedFields($before, $after);
            if ($changed === []) {
                return ['pa_number' => $paNumber, 'changed' => [], 'before' => $before, 'after' => $after];
            }
            $today = $this->today();
            if ($after['reserved_team'] !== null && $before['reserved_team'] === null) {
                $links = $this->ownersInPeriod($paNumber, $today, null, null);
                if ($links > 0) {
                    throw new DomainException("PA com {$links} vínculo(s) vigente(s) ou futuro(s): encerre antes de reservar ao Crítico.");
                }
                $preferences = count($this->preferenceIdsOf((int)$seat['id'], false));
                if ($preferences > 0) {
                    throw new DomainException("PA com {$preferences} preferência(s): retire antes de reservar ao Crítico.");
                }
            }
            if ($after['seat_type'] === 'fixed' && $after['reserved_team'] === null && ($before['seat_type'] !== 'fixed' || $before['reserved_team'] !== null)) {
                $overlaps = $this->overlappingOwners($paNumber, $today);
                if ($overlaps > 0) {
                    throw new DomainException("PA com {$overlaps} par(es) de vínculos sobrepostos de hoje em diante: deixe um dono por data antes de marcar como fixo.");
                }
            }
            $stmt = $this->pdo->prepare(
                'UPDATE portal_pa_inventory
                 SET seat_type = :seat_type, can_lend = :can_lend, reserved_team = :reserved_team
                 WHERE id = :id'
            );
            $stmt->execute([':seat_type' => $after['seat_type'], ':can_lend' => $after['can_lend'] ? 1 : 0, ':reserved_team' => $after['reserved_team'], ':id' => (int)$seat['id']]);
            return ['pa_number' => $paNumber, 'changed' => $changed, 'before' => $before, 'after' => $after];
        });
    }

    /**
     * Troca o conjunto de preferencias de um PA (pa_map.manage), com o PA
     * travado: so cadastros ativos do grupo comum (N1 e N2, grupo 'comum',
     * fora do "fora do Mapa"); PA reservado nao tem preferencia. Devolve os IDs
     * incluidos e retirados (so IDs, para a auditoria).
     */
    public function savePreferences(array $data, string $actor): array {
        return $this->atomic(function () use ($data, $actor): array {
            $this->requireSeatSetup();
            $paNumber = $this->paNumber($data['pa_number'] ?? null);
            $seat = $this->lockedSeat($paNumber);
            if ($seat === null) {
                throw new InvalidArgumentException('PA não encontrado no inventário.');
            }
            if ($seat['reserved_team'] !== null) {
                throw new DomainException('PA reservado ao Crítico não tem preferência.');
            }
            $ids = PaInventoryRules::preferenceIds($data['employee_ids'] ?? null);
            if ($ids !== []) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $stmt = $this->pdo->prepare("SELECT id, equipe, grupo_escala, fora_mapa_escala FROM funcionarios WHERE ativo = 1 AND id IN ({$placeholders})");
                $stmt->execute($ids);
                $rows = $stmt->fetchAll();
                if (count($rows) !== count($ids)) {
                    throw new InvalidArgumentException('Colaborador ativo inválido.');
                }
                $outside = count(array_filter($rows, static fn (array $row): bool => !PaInventoryRules::canPrefer(['team' => PortalTeams::normalize((string)$row['equipe'])] + $row)));
                if ($outside > 0) {
                    throw new DomainException("{$outside} colaborador(es) fora do grupo comum (N1 e N2): a preferência só vale na distribuição do grupo comum.");
                }
            }
            $current = $this->preferenceIdsOf((int)$seat['id'], true);
            $removed = array_values(array_diff($current, $ids));
            $added = array_values(array_diff($ids, $current));
            if ($removed !== []) {
                $placeholders = implode(', ', array_fill(0, count($removed), '?'));
                $stmt = $this->pdo->prepare("DELETE FROM portal_pa_preferences WHERE pa_id = ? AND employee_id IN ({$placeholders})");
                $stmt->execute(array_merge([(int)$seat['id']], $removed));
            }
            if ($added !== []) {
                $stmt = $this->pdo->prepare('INSERT INTO portal_pa_preferences (pa_id, employee_id, created_by) VALUES (:pa_id, :employee_id, :created_by)');
                foreach ($added as $employeeId) {
                    $stmt->execute([':pa_id' => (int)$seat['id'], ':employee_id' => $employeeId, ':created_by' => $actor]);
                }
            }
            return ['pa_number' => $paNumber, 'added' => $added, 'removed' => $removed, 'total' => count($ids)];
        });
    }

    /** A 022 aplicada (a tabela das preferencias e a marca): uma consulta por instancia. */
    private function seatSetupReady(): bool {
        return $this->tableExists('portal_pa_preferences');
    }

    private function requireSeatSetup(): void {
        if (!$this->seatSetupReady()) {
            throw new DomainException('Inventário de PA ainda não foi atualizado (migration 022).');
        }
    }

    /** O PA do inventario, ativo, travado (FOR UPDATE), com o tipo, o emprestimo e a reserva. */
    private function lockedSeat(string $paNumber): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, pa_number, seat_type, can_lend, reserved_team
             FROM portal_pa_inventory
             WHERE pa_number = :pa_number AND active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':pa_number' => $paNumber]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int)$row['id']] + PaInventoryRules::storedSeat($row) : null;
    }

    /** Vinculos ativos do PA que cruzam o periodo (menos $id), de cadastros ativos. */
    private function ownersInPeriod(string $paNumber, string $from, ?string $until, ?int $id): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM portal_pa_assignments
             WHERE active = 1
               AND pa_number = :pa_number
               AND id <> :id
               AND valid_from <= :date_until
               AND (valid_until IS NULL OR valid_until >= :date_from)'
            . $this->activeEmployeeFilter('portal_pa_assignments', $from)
        );
        $stmt->execute([':pa_number' => $paNumber, ':id' => $id ?: 0, ':date_from' => $from, ':date_until' => $until ?? '9999-12-31']);
        return (int)$stmt->fetchColumn();
    }

    /** Pares de vinculos ativos do PA que se sobrepoem de $from em diante. */
    private function overlappingOwners(string $paNumber, string $from): int {
        $stmt = $this->pdo->prepare(
            'SELECT id, valid_from, valid_until
             FROM portal_pa_assignments
             WHERE active = 1
               AND pa_number = :pa_number
               AND (valid_until IS NULL OR valid_until >= :date_from)'
            . $this->activeEmployeeFilter('portal_pa_assignments', $from)
            . ' ORDER BY valid_from, id'
        );
        $stmt->execute([':pa_number' => $paNumber, ':date_from' => $from]);
        $rows = $stmt->fetchAll();
        $pairs = 0;
        foreach ($rows as $i => $a) {
            foreach (array_slice($rows, $i + 1) as $b) {
                if ((string)$b['valid_from'] <= (string)($a['valid_until'] ?? '9999-12-31') && (string)$a['valid_from'] <= (string)($b['valid_until'] ?? '9999-12-31')) {
                    $pairs++;
                }
            }
        }
        return $pairs;
    }

    /** IDs com preferencia pelo PA (do inventario), em ordem; $lock trava as linhas. */
    private function preferenceIdsOf(int $paId, bool $lock): array {
        $stmt = $this->pdo->prepare('SELECT employee_id FROM portal_pa_preferences WHERE pa_id = :pa_id ORDER BY employee_id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([':pa_id' => $paId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Inventario da leitura com o tipo, o emprestimo, a reserva e, para quem
     * edita o mapa, os IDs com preferencia (duas consultas, qualquer que seja
     * o numero de PAs).
     */
    private function withSeatSetup(array $inventory, bool $canManage): array {
        $setup = [];
        foreach ($this->pdo->query('SELECT id, pa_number, seat_type, can_lend, reserved_team FROM portal_pa_inventory WHERE active = 1')->fetchAll() as $row) {
            $setup[(string)$row['pa_number']] = PaInventoryRules::storedSeat($row);
        }
        $preferences = [];
        if ($canManage) {
            foreach ($this->pdo->query('SELECT i.pa_number, p.employee_id FROM portal_pa_preferences p JOIN portal_pa_inventory i ON i.id = p.pa_id ORDER BY i.pa_number, p.employee_id')->fetchAll() as $row) {
                $preferences[(string)$row['pa_number']][] = (int)$row['employee_id'];
            }
        }
        $default = ['seat_type' => 'flexible', 'can_lend' => false, 'reserved_team' => null];
        return array_map(static fn (array $pa): array => $pa + ($setup[(string)$pa['pa_number']] ?? $default)
            + ($canManage ? ['preference_ids' => $preferences[(string)$pa['pa_number']] ?? []] : []), $inventory);
    }

    /**
     * [D-F10-19 revista] Quem ocupa os PAs reservados ao Critico na data: as
     * pessoas do Critico presenciais no motor, na ordem do papel do dia (9.16;
     * sem papel por ultimo), pela regra unica do motor (reservedSeating).
     * [8.8] Uma fonte so: a presenca e o papel vem do mapPresence, que ja
     * aplica o papel com a precedencia do PresenceService (ausencia e excecao
     * por data > papel > regra); o Mapa PA nao le papel nem corrige o dia.
     * Devolve o ocupante por PA reservado (null = reservado e vazio), quantos
     * assentos, quantos presenciais e quem ficou sem assento (so ID e nome).
     */
    private function criticalSeating(array $inventory, string $date, array $presence): array {
        $reserved = array_values(array_filter($inventory, static fn (array $pa): bool => ($pa['reserved_team'] ?? null) === PortalTeams::CRITICAL));
        usort($reserved, [PaInventoryRules::class, 'inventoryOrder']);
        $onsite = [];
        foreach ($presence['days'] as $day) {
            if ($day['date'] === $date && PortalTeams::normalize((string)($day['team'] ?? '')) === PortalTeams::CRITICAL && $day['presence_type'] === 'onsite') {
                $onsite[(int)$day['employee_id']] = $day;
            }
        }
        $ordered = PaDistributionEngine::orderReservedPeople(array_map(static fn (array $day): array => [
            'employee_id' => (int)$day['employee_id'],
            'name' => (string)$day['employee_name'],
            'role_order' => $day['papel']['sort_order'] ?? null,
            'role_id' => $day['papel']['id'] ?? null,
        ], array_values($onsite)));
        $seating = PaDistributionEngine::reservedSeating(array_column($reserved, 'pa_number'), $ordered);
        $person = static fn (int $id): array => [
            'employee_id' => $id,
            'employee_name' => (string)$onsite[$id]['employee_name'],
            'role_label' => $onsite[$id]['papel']['label'] ?? null,
        ];
        $byPa = [];
        foreach ($seating['occupants'] as $pa => $id) {
            $byPa[(string)$pa] = $id === null ? null : $person($id);
        }
        return [
            'by_pa' => $byPa,
            'seats' => count($reserved),
            'onsite' => count($onsite),
            'without_seat' => array_map($person, $seating['without_seat']),
        ];
    }

    private function assignmentsForMap(string $date, ?string $team, array $presence): array {
        $items = [];
        foreach ($this->assignmentRows($date, $date, $team, $date) as $row) {
            $items[] = $this->assignmentView($row, $date, 'assignment', $presence);
        }
        return $items;
    }

    /**
     * Vinculos recorrentes ativos que cobrem o periodo; de $filterDate em
     * diante sem vinculo de cadastro inativo (D13 do F4). A copia da regra
     * vem na linha, mas nao e mais lida (L-02; continua gravada, 2.7).
     */
    private function assignmentRows(string $from, string $to, ?string $team, string $filterDate): array {
        if (!$this->tableExists('portal_pa_assignments')) {
            return [];
        }
        $sql = 'SELECT id, pa_number, employee_id, employee_name, employee_login,
                       team, schedule_rule_type, schedule_rule_config, valid_from, valid_until, notes,
                       created_by, updated_by, created_at, updated_at
                FROM portal_pa_assignments
                WHERE active = 1
                  AND valid_from <= :date_until
                  AND (valid_until IS NULL OR valid_until >= :date_from)';
        $params = [
            ':date_from' => $from,
            ':date_until' => $to,
        ];
        if ($team) {
            $sql .= ' AND team = :team';
            $params[':team'] = $team;
        }
        $sql .= $this->activeEmployeeFilter('portal_pa_assignments', $filterDate);
        $stmt = $this->pdo->prepare($sql . ' ORDER BY pa_number, employee_name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * [F6] Vinculos da semana (recorrentes e da tabela antiga, todas as
     * equipes: o PA e um so) para o conflito e as ausencias da semana. Duas
     * consultas, qualquer que seja o numero de cadastros.
     */
    private function weekRows(string $weekFrom, string $weekTo): array {
        $rows = [];
        foreach ($this->assignmentRows($weekFrom, $weekTo, null, $weekFrom) as $row) {
            $rows[] = ['pa_number' => (string)$row['pa_number'], 'employee_id' => (int)$row['employee_id'], 'team' => $row['team'],
                'from' => (string)$row['valid_from'], 'until' => $row['valid_until'] === null ? null : (string)$row['valid_until']];
        }
        if ($this->tableExists('portal_pa_map')) {
            $stmt = $this->pdo->prepare(
                'SELECT pa_number, employee_id, team, work_date
                 FROM portal_pa_map
                 WHERE record_status = "active" AND work_date BETWEEN :date_from AND :date_to
                 ORDER BY work_date, pa_number'
            );
            $stmt->execute([':date_from' => $weekFrom, ':date_to' => $weekTo]);
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = ['pa_number' => (string)$row['pa_number'], 'employee_id' => (int)$row['employee_id'], 'team' => $row['team'],
                    'from' => (string)$row['work_date'], 'until' => (string)$row['work_date']];
            }
        }
        return $rows;
    }

    /**
     * [F6, RF-05, P13, DF3, DF8] Conflito por PA e dia util da semana: dois
     * ou mais cadastros com vinculo no PA no dia e situacao presencial no
     * motor. Ausente o dia todo, remoto e sem escala nao contam; parcial e
     * Lideranca contam. Cadastro inativo de hoje em diante (e arquivado a
     * partir do arquivamento) nao tem dia no motor e nao conta (D13 do F4 e
     * decisao 8). Calculado na leitura, nunca gravado (2.7).
     */
    private function weekConflicts(array $rows, array $presence, string $weekFrom, string $weekTo): array {
        $onsite = [];
        foreach (self::days($weekFrom, $weekTo) as $date) {
            foreach ($rows as $row) {
                if ($row['from'] <= $date && ($row['until'] === null || $row['until'] >= $date)
                    && ($presence['days'][$row['employee_id'] . ':' . $date]['presence_type'] ?? null) === 'onsite') {
                    $onsite[$row['pa_number']][$date][$row['employee_id']] = $row['employee_id'];
                }
            }
        }
        $conflicts = [];
        foreach ($onsite as $paNumber => $byDate) {
            foreach ($byDate as $date => $employees) {
                if (count($employees) >= 2) {
                    sort($employees);
                    $conflicts[] = ['pa_number' => (string)$paNumber, 'date' => $date, 'employee_ids' => array_values($employees)];
                }
            }
        }
        usort($conflicts, static fn (array $a, array $b): int => [$a['pa_number'], $a['date']] <=> [$b['pa_number'], $b['date']]);
        return $conflicts;
    }

    /**
     * [F6, DF9] Ausencias da semana dos cadastros com vinculo em algum PA no
     * dia (filtro de equipe pelo vinculo): dia inteiro e parciais, do F3 e da
     * excecao antiga, ordenadas por data e nome. Categoria pela P9 (o motor so
     * a entrega para a gestao e o proprio); observacao nunca.
     */
    private function weekAbsences(array $rows, array $presence, ?string $team, string $weekFrom, string $weekTo): array {
        $items = [];
        $seen = [];
        foreach (self::days($weekFrom, $weekTo) as $date) {
            foreach ($rows as $row) {
                $key = $row['employee_id'] . ':' . $date;
                $day = $presence['days'][$key] ?? null;
                if ($day === null || isset($seen[$key]) || ($team !== null && $row['team'] !== $team)
                    || $row['from'] > $date || ($row['until'] !== null && $row['until'] < $date)) {
                    continue;
                }
                $seen[$key] = true;
                foreach ($day['absences'] as $absence) {
                    $items[] = [
                        'employee_id' => $row['employee_id'],
                        'employee_name' => $day['employee_name'],
                        'team' => $day['team'],
                        'date' => $date,
                    ] + $absence;
                }
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['date'], $a['employee_name'], $a['start_time'] ?? ''] <=> [$b['date'], $b['employee_name'], $b['start_time'] ?? '']);
        return $items;
    }

    /** @return list<string> */
    private static function days(string $from, string $to): array {
        $days = [];
        for ($day = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC')); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }
        return $days;
    }

    /**
     * [F4, L-07, D13] De hoje em diante o mapa nao mostra vinculo de cadastro
     * inativo (arquivado ou antigo); o passado fica como esta ate F5/F6.
     * Tabela so por lista branca (identificador nao e parametrizavel).
     */
    private function activeEmployeeFilter(string $table, string $date): string {
        if (!in_array($table, ['portal_pa_assignments', 'portal_pa_map'], true)) {
            throw new LogicException('Tabela fora da lista do Mapa PA.');
        }
        if ($date < $this->today()) {
            return '';
        }
        return " AND EXISTS (SELECT 1 FROM funcionarios f WHERE f.id = {$table}.employee_id AND f.ativo = 1)";
    }

    private function legacySpecificDateAssignments(string $date, ?string $team, array $presence): array {
        if (!$this->tableExists('portal_pa_map')) {
            return [];
        }
        $sql = 'SELECT id, pa_number, employee_id, employee_name, employee_login,
                       team, schedule_status, schedule_label, notes, created_by,
                       updated_by, created_at, updated_at
                FROM portal_pa_map
                WHERE record_status = "active" AND work_date = :work_date';
        $params = [':work_date' => $date];
        if ($team) {
            $sql .= ' AND team = :team';
            $params[':team'] = $team;
        }
        $sql .= $this->activeEmployeeFilter('portal_pa_map', $date);
        $stmt = $this->pdo->prepare($sql . ' ORDER BY pa_number, employee_name');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->assignmentView([
                'id' => 'legacy-' . $row['id'],
                'pa_number' => $row['pa_number'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'employee_login' => $row['employee_login'],
                'team' => $row['team'],
                'valid_from' => $date,
                'valid_until' => $date,
                'notes' => $row['notes'],
                'created_by' => $row['created_by'],
                'updated_by' => $row['updated_by'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ], $date, 'legacy', $presence);
        }
        return $items;
    }

    /**
     * [F6, L-02, RF-08] Vinculo com a presenca do motor na data: regra
     * vigente (nao a copia gravada), ausencia do F3 e da excecao antiga,
     * parcial e categoria pela P9. Sem dia no motor, presence = null e o
     * motivo (DF6): 'inativo' (inativo antigo, fora do motor), 'arquivado'
     * (data do arquivamento ou depois), 'fim_de_semana', 'feriado' (D-ESC-17)
     * ou 'sem_situacao'.
     * Os campos de antes (schedule_rule_type e label, active_on_date,
     * presence_status) continuam, calculados pelo motor; a copia
     * (schedule_rule_config) sai da resposta.
     */
    private function assignmentView(array $row, string $date, string $source, array $presence): array {
        $employeeId = (int)$row['employee_id'];
        $day = $presence['days'][$employeeId . ':' . $date] ?? null;
        $rule = $day ?? self::lastBusinessDay($presence, $employeeId, $date);
        $status = $day['presence_type'] ?? null;
        $reason = null;
        if ($day === null) {
            $archivedOn = $presence['employees'][$employeeId] ?? null;
            $reason = match (true) {
                !array_key_exists($employeeId, $presence['employees']) => 'inativo',
                $archivedOn !== null && $date >= $archivedOn => 'arquivado',
                !PresenceEngine::isBusinessDay($date) => 'fim_de_semana',
                // [D-ESC-17] Feriado: dia sem expediente para todos.
                isset($presence['holidays'][$date]) => 'feriado',
                default => 'sem_situacao',
            };
        }
        unset($row['schedule_rule_config']);
        return array_merge($row, [
            'schedule_rule_type' => $rule['rule_type'] ?? 'undefined',
            'source' => $source,
            'schedule_rule_label' => $rule['rule_label'] ?? 'Sem escala definida',
            'schedule_weekdays' => $rule['weekdays'] ?? [],
            'active_on_date' => $status === 'onsite',
            'presence_status' => in_array($status, ['onsite', 'remote'], true) ? $status : 'offsite',
            'presence' => $day === null ? null : [
                'situacao' => $day['situacao'],
                'origem' => $day['origem'],
                'detalhe' => $day['detalhe'],
                'status' => $day['presence_type'],
            ] + (isset($day['parcial']) ? ['parcial' => $day['parcial']] : [])
              + (isset($day['categoria']) ? ['categoria' => $day['categoria']] : []),
        ] + ($reason === null ? [] : ['presence_reason' => $reason]));
    }

    /**
     * O mesmo colaborador nao pode ter dois vinculos ativos no mesmo periodo
     * (em outro PA ou no mesmo): continua bloqueando, com trava. O conflito
     * de ocupacao do PA virou aviso no F6 (saveConflicts).
     */
    private function assertNoOtherPa(int $employeeId, string $validFrom, ?string $validUntil, ?int $id): void {
        $rangeUntil = $validUntil ?? '9999-12-31';
        $stmt = $this->pdo->prepare(
            'SELECT id, pa_number
             FROM portal_pa_assignments
             WHERE active = 1
               AND employee_id = :employee_id
               AND id <> :id
               AND valid_from <= :new_until
               AND (valid_until IS NULL OR valid_until >= :new_from)
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':id' => $id ?: 0,
            ':new_until' => $rangeUntil,
            ':new_from' => $validFrom,
        ]);
        if ($stmt->fetch()) {
            throw new DomainException('Este colaborador já possui vínculo ativo em outro PA no período.');
        }
    }

    private function employee($id): array {
        $employeeId = $this->positiveInt($id);
        $stmt = $this->pdo->prepare(
            'SELECT id, nome AS name, equipe AS team, ad_login, fora_mapa_escala
             FROM funcionarios
             WHERE id = :id AND ativo = 1
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        if (!$employee) {
            throw new InvalidArgumentException('Colaborador ativo inválido.');
        }
        $normalizedTeam = $this->normalizeTeam($employee['team'] ?? null);
        if ($normalizedTeam === null) {
            throw new InvalidArgumentException('Colaborador ativo inválido.');
        }
        $employee['team'] = $normalizedTeam;
        return $employee;
    }

    /**
     * Copia da regra vigente no inicio do vinculo, gravada so para o rollback
     * (2.7); desde o F6 a leitura e os avisos usam o motor (L-02).
     */
    private function ruleCopy(int $employeeId, string $date): array {
        if (!$this->tableExists('portal_schedule_rules')) {
            return ['rule_type' => 'undefined', 'rule_config' => null];
        }
        $stmt = $this->pdo->prepare(
            'SELECT rule_type, rule_config
             FROM portal_schedule_rules
             WHERE employee_id = :employee_id
               AND effective_from <= :work_date_from
               AND (effective_until IS NULL OR effective_until >= :work_date_until)
             ORDER BY effective_from DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':work_date_from' => $date,
            ':work_date_until' => $date,
        ]);
        $rule = $stmt->fetch();
        if (!$rule) {
            return ['rule_type' => 'undefined', 'rule_config' => null];
        }
        $ruleType = $this->ruleType((string)$rule['rule_type']);
        if ($ruleType === 'cycle') {
            return ['rule_type' => $ruleType, 'rule_config' => (string)$rule['rule_config']];
        }
        $weekdays = $ruleType === 'fixed_weekdays'
            ? OperationalService::scheduleRuleConfigWeekdays($rule['rule_config'] ?? null)
            : [];
        return [
            'rule_type' => $ruleType,
            'rule_config' => $weekdays !== [] ? json_encode(['weekdays' => $weekdays], JSON_UNESCAPED_SLASHES) : null,
        ];
    }

    private function ruleType($value): string {
        $ruleType = strtolower(trim((string)$value));
        return in_array($ruleType, self::RULE_TYPES, true) ? $ruleType : 'undefined';
    }

    private function paNumber($value): string {
        $text = $this->text($value, 20);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{0,19}$/', $text)) {
            throw new InvalidArgumentException('PA inválido.');
        }
        return strtoupper(trim($text));
    }

    private function date($value, string $label): string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException("{$label} inválida.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException("{$label} inválida.");
        }
        return $date->format('Y-m-d');
    }

    private function nullableDate($value, string $label): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->date($value, $label);
    }

    private function team($value, bool $nullable = false): ?string {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        // [F9] Vinculo de qualquer equipe de cadastro (Critico e Hibrido com PA fixo, D-F10-19 opcao c).
        $team = PortalTeams::normalize(is_scalar($value) ? (string)$value : null);
        if ($team === null) {
            throw new InvalidArgumentException('Equipe inválida.');
        }
        return $team;
    }

    private function normalizeTeam($value): ?string {
        $team = strtolower(trim((string)$value));
        $team = strtr($team, [
            ' ' => '_',
            '-' => '_',
            'ã' => 'a',
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ç' => 'c',
            'í' => 'i',
            'ê' => 'e',
        ]);
        if (in_array($team, ['n1', 'n2', 'critico', 'hibrido'], true)) {
            return $team;
        }
        if (in_array($team, ['lideranca', 'lider', 'leadership', 'na', 'n_a', 'nao_se_aplica', 'não_se_aplica', 'sem_equipe'], true)) {
            return 'lideranca';
        }
        return null;
    }

    private function teamSqlAliases(string $team): string {
        if ($team === 'lideranca') {
            return '"lideranca", "lider", "leadership", "na", "n_a", "nao_se_aplica", "não se aplica", "não_se_aplica", "sem_equipe", "sem equipe"';
        }
        return '"' . $team . '"';
    }

    private function positiveInt($value, bool $nullable = false): ?int {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new InvalidArgumentException('Identificador inválido.');
        }
        return (int)$result;
    }

    private function text($value, int $max, bool $allowEmpty = false): string {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Texto inválido.');
        }
        $text = trim((string)$value);
        if ($text === '' && !$allowEmpty) {
            throw new InvalidArgumentException('Texto obrigatório.');
        }
        if (self::stringLength($text) > $max) {
            throw new InvalidArgumentException('Texto excede o limite permitido.');
        }
        return $text;
    }

    /** Existencia da tabela, uma consulta por tabela e instancia (L-10). */
    private function tableExists(string $table): bool {
        if (isset($this->tables[$table])) {
            return $this->tables[$table];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        return $this->tables[$table] = (int)$stmt->fetchColumn() > 0;
    }

    private function atomic(callable $callback) {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
