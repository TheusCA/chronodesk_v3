<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/SharePointSyncService.php';
require_once __DIR__ . '/ScheduleRulePlanner.php';

final class OperationalService {
    public const MAX_IMPORT_ROWS = 500;
    public const MAX_IMPORT_COLUMNS = 6;
    public const MAX_IMPORT_CELL_CHARS = 500;
    public const MAX_IMPORT_PAYLOAD_BYTES = 1048576;
    public const LIST_LIMIT = 500;
    private const RULE_TYPES = [
        'even_days', 'odd_days', 'always_remote', 'always_onsite', 'undefined', 'fixed_weekdays',
    ];
    private const WEEKDAY_ORDER = ['mon', 'tue', 'wed', 'thu', 'fri'];
    private const WEEKDAY_LABELS = [
        'mon' => 'seg',
        'tue' => 'ter',
        'wed' => 'qua',
        'thu' => 'qui',
        'fri' => 'sex',
    ];
    /** [P12] Escala implicita da Lideranca (desenho, secao 0.6). */
    public const LEADERSHIP_RULE_LABEL = 'Presencial (regra fixa)';
    public const LEADERSHIP_RULE_MESSAGE = 'A Liderança tem escala fixa presencial nos dias úteis, na jornada do próprio cadastro: não há regra a cadastrar. Para treinamento, plantão ou sábado, use exceção por data.';
    public const LEADERSHIP_REMOTE_MESSAGE = 'A Liderança tem escala sempre presencial: exceção remota não é aceita.';
    public const LEADERSHIP_IMPORT_WARNING = 'Linha ignorada: a Liderança tem escala fixa presencial, sem regra a importar.';
    public const ALLOWED_IMPORT_HEADERS = [
        'id', 'employee_id',
        'login_ad', 'ad_login', 'email',
        'nome', 'name',
        'equipe', 'team',
        'regra', 'rule_type',
    ];

    private PDO $pdo;
    private SharePointSyncService $sync;
    private ?string $today;

    /** $today: data de hoje (Y-m-d) fixa, so para teste; padrao e a data do PHP. */
    public function __construct(?PDO $pdo = null, ?string $today = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->sync = new SharePointSyncService();
        $this->today = $today;
    }

    public static function competencyRange(?string $reference = null): array {
        $reference = $reference ?: date('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}$/', $reference)) {
            $reference .= '-01';
        }
        $base = self::dateValue($reference, 'competencia');
        $day = (int)$base->format('d');
        if ($day >= 16) {
            $start = $base->modify('first day of this month')->setDate(
                (int)$base->format('Y'),
                (int)$base->format('m'),
                16
            );
            $labelDate = $base->modify('first day of next month');
        } else {
            $previous = $base->modify('first day of previous month');
            $start = $previous->setDate(
                (int)$previous->format('Y'),
                (int)$previous->format('m'),
                16
            );
            $labelDate = $base;
        }
        $end = $start->modify('+1 month')->setDate(
            (int)$start->modify('+1 month')->format('Y'),
            (int)$start->modify('+1 month')->format('m'),
            15
        );

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'key' => $labelDate->format('Y-m'),
            'label' => self::monthName((int)$labelDate->format('m')) . '/' . $labelDate->format('Y'),
        ];
    }

    public static function presenceForRule(string $rule, string $date, array $weekdays = []): string {
        $dateValue = self::dateValue($date, 'Data');
        $day = (int)$dateValue->format('d');
        return match ($rule) {
            'always_onsite' => 'onsite',
            'always_remote', 'undefined' => 'remote',
            'even_days' => $day % 2 === 0 ? 'onsite' : 'remote',
            'odd_days' => $day % 2 === 1 ? 'onsite' : 'remote',
            'fixed_weekdays' => in_array(strtolower($dateValue->format('D')), $weekdays, true) ? 'onsite' : 'remote',
            default => throw new InvalidArgumentException('Regra de escala invalida.'),
        };
    }

    public function employees(bool $includeInactive = false): array {
        $sql = 'SELECT id, nome AS name, equipe AS team, ad_login, ativo AS active
                FROM funcionarios';
        if (!$includeInactive) {
            $sql .= ' WHERE ativo = 1';
        }
        $sql .= ' ORDER BY nome';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function listCalendar(array $filters): array {
        [$from, $to] = $this->period($filters, 62);
        $team = $this->team($filters['team'] ?? null, true);
        $employeeId = $this->positiveInt($filters['employee_id'] ?? null, true);
        $type = $this->text($filters['type'] ?? '', 40, true);
        $status = $this->text($filters['status'] ?? '', 30, true);
        $events = [];

        if (!$type || $type === 'manual') {
            $sql = 'SELECT id, title, description, type, starts_at, ends_at, team,
                           related_username, status
                    FROM portal_calendar_events
                    WHERE starts_at <= CONCAT(:date_to, " 23:59:59")
                      AND COALESCE(ends_at, starts_at) >= CONCAT(:date_from, " 00:00:00")';
            $params = [':date_from' => $from, ':date_to' => $to];
            if ($team) {
                $sql .= ' AND team = :team';
                $params[':team'] = $team;
            }
            if ($status) {
                $sql .= ' AND status = :status';
                $params[':status'] = $status;
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY starts_at');
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $events[] = $this->calendarRow('manual', $row);
            }
        }

        if (!$type || $type === 'pause') {
            $sql = 'SELECT p.id, p.nome_funcionario AS title, p.motivo_pausa AS description,
                           p.inicio_pausa AS starts_at, p.fim_pausa AS ends_at,
                           p.equipe AS team, p.id_funcionario AS employee_id,
                           COALESCE(p.status_aprovacao, "completed") AS status
                    FROM pausas p
                    WHERE DATE(p.inicio_pausa) BETWEEN :date_from AND :date_to';
            $params = [':date_from' => $from, ':date_to' => $to];
            if ($team) {
                $sql .= ' AND p.equipe = :team';
                $params[':team'] = $team;
            }
            if ($employeeId) {
                $sql .= ' AND p.id_funcionario = :employee_id';
                $params[':employee_id'] = $employeeId;
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY p.inicio_pausa');
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $events[] = $this->calendarRow('pause', $row);
            }
        }

        if (!$type || $type === 'war_room') {
            $sql = 'SELECT id, incident_number AS title,
                           COALESCE(room_description, title) AS description,
                           COALESCE(room_opened_at, incident_opened_at, opened_at) AS starts_at,
                           COALESCE(normalized_at, resolved_at) AS ends_at,
                           sector AS team, status
                    FROM portal_critical_incidents
                    WHERE COALESCE(room_date, DATE(opened_at)) BETWEEN :date_from AND :date_to';
            $params = [':date_from' => $from, ':date_to' => $to];
            if ($status) {
                $sql .= ' AND status = :status';
                $params[':status'] = $status;
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY starts_at');
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $events[] = $this->calendarRow('war_room', $row);
            }
        }

        if (!$type || $type === 'absence') {
            $sql = 'SELECT id, employee_name AS title, reason AS description,
                           CONCAT(starts_on, " 00:00:00") AS starts_at,
                           CONCAT(ends_on, " 23:59:59") AS ends_at,
                           NULL AS team, employee_id, status
                    FROM portal_absences
                    WHERE starts_on <= :date_to AND ends_on >= :date_from';
            $params = [':date_from' => $from, ':date_to' => $to];
            if ($employeeId) {
                $sql .= ' AND employee_id = :employee_id';
                $params[':employee_id'] = $employeeId;
            }
            if ($status) {
                $sql .= ' AND status = :status';
                $params[':status'] = $status;
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY starts_at');
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $events[] = $this->calendarRow('absence', $row);
            }
        }

        foreach ([
            ['overtime', 'portal_overtime_entries', 'work_date', 'start_time', 'end_time', 'reason'],
            ['time_adjustment', 'portal_time_adjustments', 'adjustment_date', 'correct_time', 'correct_time', 'justification'],
            ['oncall', 'portal_oncall_shifts', 'starts_on', 'start_time', 'end_time', 'note'],
        ] as [$eventType, $table, $dateColumn, $startColumn, $endColumn, $descriptionColumn]) {
            if ($type && $type !== $eventType) {
                continue;
            }
            $endDateColumn = $eventType === 'oncall' ? 'ends_on' : $dateColumn;
            $sql = "SELECT id, employee_name AS title, {$descriptionColumn} AS description,
                           CONCAT({$dateColumn}, ' ', COALESCE({$startColumn}, '00:00:00')) AS starts_at,
                           CONCAT({$endDateColumn}, ' ', COALESCE({$endColumn}, '23:59:59')) AS ends_at,
                           team, employee_id, status
                    FROM {$table}
                    WHERE {$dateColumn} <= :date_to AND {$endDateColumn} >= :date_from";
            $params = [':date_from' => $from, ':date_to' => $to];
            $this->appendEmployeeTeamFilters($sql, $params, $team, $employeeId);
            if ($status) {
                $sql .= ' AND status = :status';
                $params[':status'] = $status;
            }
            $stmt = $this->pdo->prepare($sql . " ORDER BY {$dateColumn}");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $events[] = $this->calendarRow($eventType, $row);
            }
        }

        if (!$type || in_array($type, ['schedule', 'onsite', 'remote'], true)) {
            foreach ($this->generatedSchedule($from, $to, $team, $employeeId) as $row) {
                if ($type && $type !== 'schedule' && $row['presence_type'] !== $type) {
                    continue;
                }
                $events[] = [
                    'id' => 'schedule-' . $row['employee_id'] . '-' . $row['date'],
                    'event_type' => 'schedule',
                    'title' => $row['label'] . ': ' . $row['employee_name'],
                    'description' => $row['source'] === 'exception'
                        ? 'Excecao de escala'
                        : ($row['rule_label'] ?? $this->scheduleRuleLabel($row['rule_type'])),
                    'starts_at' => $row['date'] . ' 00:00:00',
                    'ends_at' => $row['date'] . ' 23:59:59',
                    'team' => $row['team'],
                    'employee_id' => $row['employee_id'],
                    'status' => $row['presence_type'],
                ];
            }
        }

        usort($events, static fn(array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));
        return [
            'period' => ['from' => $from, 'to' => $to],
            'items' => $events,
        ];
    }

    public function createCalendarEvent(array $data, string $actor): int {
        $title = $this->requiredText($data['title'] ?? null, 160, 'Titulo');
        $description = $this->text($data['description'] ?? '', 5000, true);
        $type = $this->requiredText($data['type'] ?? 'manual', 50, 'Tipo');
        $startsAt = $this->dateTime($data['starts_at'] ?? null, 'Inicio');
        $endsAt = isset($data['ends_at']) && $data['ends_at'] !== ''
            ? $this->dateTime($data['ends_at'], 'Fim')
            : null;
        if ($endsAt && $endsAt < $startsAt) {
            throw new InvalidArgumentException('O fim nao pode ser anterior ao inicio.');
        }
        $team = $this->team($data['team'] ?? null, true);

        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_calendar_events
                (title, description, type, starts_at, ends_at, team, related_username, created_by, status)
             VALUES
                (:title, :description, :type, :starts_at, :ends_at, :team, :related_username, :created_by, "active")'
        );
        $stmt->execute([
            ':title' => $title,
            ':description' => $description ?: null,
            ':type' => $type,
            ':starts_at' => $startsAt->format('Y-m-d H:i:s'),
            ':ends_at' => $endsAt?->format('Y-m-d H:i:s'),
            ':team' => $team,
            ':related_username' => $this->text($data['related_username'] ?? '', 100, true) ?: null,
            ':created_by' => $actor,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function scheduleData(array $filters): array {
        [$from, $to] = $this->period($filters, 93);
        $team = $this->team($filters['team'] ?? null, true);
        $employeeId = $this->positiveInt($filters['employee_id'] ?? null, true);
        // [F1] Padrao: regras que vigoram no periodo e as agendadas; com
        // include_history=1, tambem as encerradas antes dele.
        $includeHistory = in_array((string)($filters['include_history'] ?? ''), ['1', 'true'], true);
        $rules = array_merge(
            $this->scheduleRules($team, $employeeId, $includeHistory ? null : $from),
            $this->leadershipImplicitRules($team, $employeeId)
        );
        $exceptions = $this->scheduleExceptions($from, $to, $team, $employeeId);
        return [
            'period' => ['from' => $from, 'to' => $to],
            'today' => $this->today(),
            'rules' => $rules,
            'exceptions' => $exceptions,
            'generated' => $this->generatedSchedule($from, $to, $team, $employeeId, $rules, $exceptions),
        ];
    }

    /**
     * [F1] Grava a regra com historico (desenho, secao 2.1). Devolve id,
     * unchanged (regra igual a vigente: nada gravado), closed_rule_id e
     * closed_until (regra encerrada pela nova) e retroactive.
     */
    public function saveScheduleRule(array $data, string $actor, string $actorRole): array {
        return $this->atomic(fn(): array => $this->saveScheduleRuleUnsafe($data, $actor, $actorRole));
    }

    private function saveScheduleRuleUnsafe(array $data, string $actor, string $actorRole, string $origin = 'manual'): array {
        $employee = $this->scheduleEmployee($data['employee_id'] ?? null);
        $this->assertScheduleChangeAllowed($employee, $actorRole);
        if ($employee['team'] === 'lideranca') {
            throw new DomainException(self::LEADERSHIP_RULE_MESSAGE);
        }
        $rule = (string)($data['rule_type'] ?? '');
        if (!in_array($rule, self::RULE_TYPES, true)) {
            throw new InvalidArgumentException('Regra de escala invalida.');
        }
        $weekdays = $this->weekdaysForRulePayload($rule, $data['weekdays'] ?? null);
        $ruleConfig = $weekdays === [] ? null : json_encode(['weekdays' => $weekdays], JSON_UNESCAPED_SLASHES);
        $today = $this->today();
        $effectiveFrom = self::dateValue($data['effective_from'] ?? $today, 'Inicio da vigencia')->format('Y-m-d');
        // [P10, L6] Inicio retroativo reescreve historico: admin e Lideranca.
        if ($effectiveFrom < $today && !portal_role_can($actorRole, 'escalas.retroativa')) {
            throw new PortalForbiddenException('Somente administradores e Liderança gravam regra com início retroativo.');
        }
        $this->lockScheduleEmployee($employee);
        $rules = $this->employeeScheduleRules((int)$employee['id']);
        $plan = ScheduleRulePlanner::planSave($rules, $effectiveFrom, $rule, $ruleConfig, $today);
        if ($plan['action'] === 'unchanged') {
            return ['id' => $plan['target_id'], 'unchanged' => true, 'closed_rule_id' => null, 'closed_until' => null, 'retroactive' => false];
        }
        if ($plan['close'] !== null) {
            $this->closeScheduleRule($plan['close'], (int)$employee['id'], $actor);
        }
        $params = [
            ':employee_id' => (int)$employee['id'],
            ':employee_name' => $employee['name'],
            ':ad_login' => $employee['ad_login'],
            ':team' => $employee['team'],
            ':rule_type' => $rule,
            ':rule_config' => $ruleConfig,
            ':effective_until' => $plan['effective_until'],
            ':updated_by' => $actor,
        ];
        if ($plan['action'] === 'update') {
            $stmt = $this->pdo->prepare(
                'UPDATE portal_schedule_rules
                 SET employee_name = :employee_name,
                     ad_login = :ad_login,
                     team = :team,
                     rule_type = :rule_type,
                     rule_config = :rule_config,
                     effective_until = :effective_until,
                     updated_by = :updated_by
                 WHERE id = :id AND employee_id = :employee_id'
            );
            $stmt->execute($params + [':id' => $plan['target_id']]);
            $id = (int)$plan['target_id'];
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_schedule_rules
                    (employee_id, employee_name, ad_login, team, rule_type, rule_config, effective_from, effective_until, created_by, updated_by)
                 VALUES
                    (:employee_id, :employee_name, :ad_login, :team, :rule_type, :rule_config, :effective_from, :effective_until, :created_by, :updated_by)'
            );
            $stmt->execute($params + [':effective_from' => $effectiveFrom, ':created_by' => $actor]);
            $id = (int)$this->pdo->lastInsertId();
        }
        $this->assertScheduleHistoryConsistent($rules, (int)$employee['id']);
        $this->auditScheduleRuleChange($origin, $plan, $rules !== [], [
            'id' => $id,
            'employee_id' => (int)$employee['id'],
            'employee_name' => $employee['name'],
            'ad_login' => $employee['ad_login'],
            'team' => $employee['team'],
            'rule_type' => $rule,
            'effective_from' => $effectiveFrom,
            'effective_until' => $plan['effective_until'],
        ], $actor);
        $this->sync->enqueue($this->pdo, 'schedule', $id, [
            'employee_id' => (int)$employee['id'],
            'employee_name' => $employee['name'],
            'team' => $employee['team'],
            'rule_type' => $rule,
            'weekdays' => $weekdays,
            'effective_from' => $effectiveFrom,
            'effective_until' => $plan['effective_until'],
        ], $actor);
        return [
            'id' => $id,
            'unchanged' => false,
            'closed_rule_id' => $plan['close']['id'] ?? null,
            'closed_until' => $plan['close']['effective_until'] ?? null,
            'retroactive' => $plan['retroactive'],
        ];
    }

    /**
     * [F1] Remove a escala a partir de hoje: a regra vigente fecha ontem, sem
     * trocar o tipo (o historico guarda qual era); regra que comecaria hoje ou
     * depois e apagada, com o conteudo na auditoria. Devolve as linhas afetadas.
     */
    public function removeScheduleRule(int $employeeId, string $actor, string $actorRole): int {
        return $this->atomic(function () use ($employeeId, $actor, $actorRole): int {
            $employee = $this->scheduleEmployee($employeeId);
            $this->assertScheduleChangeAllowed($employee, $actorRole);
            $this->lockScheduleEmployee($employee);
            $rules = $this->employeeScheduleRules((int)$employee['id']);
            $plan = ScheduleRulePlanner::planRemove($rules, $this->today());
            if ($plan['close'] === null && $plan['delete'] === []) {
                throw new DomainException('Colaborador nao possui regra de escala vigente nem agendada.');
            }
            if ($plan['close'] !== null) {
                $this->closeScheduleRule($plan['close'], (int)$employee['id'], $actor);
            }
            $delete = $this->pdo->prepare(
                'DELETE FROM portal_schedule_rules WHERE id = :id AND employee_id = :employee_id'
            );
            foreach ($plan['delete'] as $row) {
                $delete->execute([':id' => (int)$row['id'], ':employee_id' => (int)$employee['id']]);
            }
            $this->assertScheduleHistoryConsistent($rules, (int)$employee['id']);
            $this->auditScheduleRuleRemoval($plan, $employee, $actor);
            $reference = $plan['current'] ?? $plan['delete'][0];
            $this->sync->enqueue($this->pdo, 'schedule', (int)$reference['id'], [
                'kind' => 'rule_removed',
                'employee_id' => (int)$employee['id'],
                'employee_name' => $employee['name'],
                'rule_type' => $reference['rule_type'],
                'effective_until' => $plan['close']['effective_until'] ?? null,
            ], $actor);
            return ($plan['close'] !== null ? 1 : 0) + count($plan['delete']);
        });
    }

    public function saveScheduleException(array $data, string $actor, string $actorRole): int {
        return $this->atomic(fn(): int => $this->saveScheduleExceptionUnsafe($data, $actor, $actorRole));
    }

    private function saveScheduleExceptionUnsafe(array $data, string $actor, string $actorRole): int {
        $employee = $this->scheduleEmployee($data['employee_id'] ?? null);
        $this->assertScheduleChangeAllowed($employee, $actorRole);
        $date = self::dateValue($data['exception_date'] ?? null, 'Data');
        $type = (string)($data['exception_type'] ?? '');
        $allowed = ['remote', 'onsite', 'day_off', 'absence', 'training', 'oncall', 'vacation', 'leave'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Tipo de excecao invalido.');
        }
        if ($employee['team'] === 'lideranca' && $type === 'remote') {
            throw new DomainException(self::LEADERSHIP_REMOTE_MESSAGE);
        }
        $this->lockScheduleEmployee($employee);
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_schedule_exceptions
                (employee_id, employee_name, team, exception_date, exception_type, note, created_by)
             VALUES
                (:employee_id, :employee_name, :team, :exception_date, :exception_type, :note, :created_by)
             ON DUPLICATE KEY UPDATE
                exception_type = VALUES(exception_type),
                note = VALUES(note),
                created_by = VALUES(created_by)'
        );
        $stmt->execute([
            ':employee_id' => $employee['id'],
            ':employee_name' => $employee['name'],
            ':team' => $employee['team'],
            ':exception_date' => $date->format('Y-m-d'),
            ':exception_type' => $type,
            ':note' => $this->text($data['note'] ?? '', 1000, true) ?: null,
            ':created_by' => $actor,
        ]);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM portal_schedule_exceptions WHERE employee_id = :employee_id AND exception_date = :exception_date'
        );
        $stmt->execute([':employee_id' => $employee['id'], ':exception_date' => $date->format('Y-m-d')]);
        $id = (int)$stmt->fetchColumn();
        $this->sync->enqueue($this->pdo, 'schedule', $id, [
            'kind' => 'exception',
            'employee_id' => (int)$employee['id'],
            'date' => $date->format('Y-m-d'),
            'exception_type' => $type,
        ], $actor);
        return $id;
    }

    public static function validateScheduleImportRows(array $rows): array {
        if (!self::isList($rows)) {
            throw new InvalidArgumentException('Rows deve ser uma lista de linhas.');
        }
        if ($rows === []) {
            throw new InvalidArgumentException('A importacao nao possui linhas de dados.');
        }
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw new InvalidArgumentException(
                'O arquivo excede o limite de ' . self::MAX_IMPORT_ROWS . ' linhas.'
            );
        }

        $expectedKeys = null;
        $normalizedRows = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || $row === [] || self::isList($row)) {
                throw new InvalidArgumentException('Estrutura invalida na linha ' . ($index + 2) . '.');
            }
            if (count($row) > self::MAX_IMPORT_COLUMNS) {
                throw new InvalidArgumentException(
                    'A linha ' . ($index + 2) . ' excede o limite de '
                    . self::MAX_IMPORT_COLUMNS . ' colunas.'
                );
            }

            $keys = array_keys($row);
            foreach ($keys as $key) {
                if (!is_string($key) || !in_array($key, self::ALLOWED_IMPORT_HEADERS, true)) {
                    throw new InvalidArgumentException('Cabecalho de importacao nao permitido.');
                }
            }
            $sortedKeys = $keys;
            sort($sortedKeys);
            if ($expectedKeys === null) {
                $expectedKeys = $sortedKeys;
                $hasIdentity = count(array_intersect(
                    $keys,
                    ['id', 'employee_id', 'login_ad', 'ad_login', 'email', 'nome', 'name']
                )) > 0;
                $hasRule = count(array_intersect($keys, ['regra', 'rule_type'])) > 0;
                if (!$hasIdentity || !$hasRule) {
                    throw new InvalidArgumentException(
                        'O CSV deve conter uma identificacao do colaborador e a coluna de regra.'
                    );
                }
            } elseif ($sortedKeys !== $expectedKeys) {
                throw new InvalidArgumentException('Todas as linhas devem usar os mesmos cabecalhos.');
            }

            $normalizedRow = [];
            foreach ($row as $key => $value) {
                if (!is_string($value) && !is_int($value)) {
                    throw new InvalidArgumentException(
                        'A linha ' . ($index + 2) . ' contem uma celula invalida.'
                    );
                }
                $text = trim((string)$value);
                if (self::stringLength($text) > self::MAX_IMPORT_CELL_CHARS) {
                    throw new InvalidArgumentException(
                        'A linha ' . ($index + 2) . ' contem uma celula acima do limite de '
                        . self::MAX_IMPORT_CELL_CHARS . ' caracteres.'
                    );
                }
                $normalizedRow[$key] = $text;
            }
            $normalizedRows[] = $normalizedRow;
        }
        return $normalizedRows;
    }

    public function previewScheduleImport(array $rows): array {
        $rows = self::validateScheduleImportRows($rows);
        $result = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $employee = $this->findEmployeeForImport($row);
            $rule = $this->normalizeRule((string)($row['regra'] ?? $row['rule_type'] ?? ''));
            $team = strtolower(trim((string)($row['equipe'] ?? $row['team'] ?? '')));
            $errors = [];
            $warnings = [];
            // [P12] Linha de lider: aviso e ignorada (escala implicita).
            $ignored = $employee !== null && $employee['team'] === 'lideranca';
            if ($ignored) {
                $warnings[] = self::LEADERSHIP_IMPORT_WARNING;
            }
            if (!$employee) {
                $errors[] = 'Colaborador nao encontrado.';
            }
            if (!$ignored && !$rule) {
                $errors[] = 'Regra invalida.';
            }
            if (!$ignored && $employee && $team && $team !== $employee['team']) {
                $errors[] = 'Equipe divergente do cadastro.';
            }
            if ($employee && isset($seen[$employee['id']])) {
                $errors[] = 'Colaborador duplicado no arquivo.';
            }
            if ($employee) {
                $seen[$employee['id']] = true;
            }
            $result[] = [
                'line' => $index + 2,
                'employee_id' => $employee ? (int)$employee['id'] : null,
                'employee_name' => $employee['name'] ?? ($row['nome'] ?? ''),
                'ad_login' => $employee['ad_login'] ?? ($row['login_ad'] ?? ''),
                'team' => $employee['team'] ?? $team,
                'rule_type' => $ignored ? null : $rule,
                'valid' => count($errors) === 0,
                'ignored' => $ignored && count($errors) === 0,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }
        return [
            'rows' => $result,
            'valid_count' => count(array_filter($result, static fn(array $row): bool => $row['valid'] && !$row['ignored'])),
            'invalid_count' => count(array_filter($result, static fn(array $row): bool => !$row['valid'])),
            'ignored_count' => count(array_filter($result, static fn(array $row): bool => $row['ignored'])),
        ];
    }

    public function confirmScheduleImport(array $rows, string $actor, string $actorRole): int {
        $preview = $this->previewScheduleImport($rows);
        if ($preview['invalid_count'] > 0) {
            throw new InvalidArgumentException('A importacao possui linhas invalidas.');
        }
        if ($preview['valid_count'] === 0) {
            throw new InvalidArgumentException('Nenhuma linha a importar: todas foram ignoradas.');
        }
        return $this->atomic(function () use ($preview, $actor, $actorRole): int {
            foreach ($preview['rows'] as $row) {
                if ($row['ignored']) {
                    continue;
                }
                $this->saveScheduleRuleUnsafe([
                    'employee_id' => $row['employee_id'],
                    'rule_type' => $row['rule_type'],
                    'effective_from' => $this->today(),
                ], $actor, $actorRole, 'import');
            }
            return $preview['valid_count'];
        });
    }

    public function listOvertime(array $filters, array $actor): array {
        return $this->listWorkflowRecords('portal_overtime_entries', 'work_date', $filters, $actor);
    }

    /**
     * [BIZ-01] Totais de horas extras de todo o filtro (periodo, equipe,
     * colaborador e escopo do perfil), sem o filtro de status: os totais ja
     * separam os status por si. Nao depende do limite da lista da tela.
     */
    public function overtimeTotalsForFilters(array $filters, array $actor): array {
        return self::overtimeTotals($this->iterateWorkflowRecords(
            'portal_overtime_entries',
            'work_date',
            $filters,
            $actor,
            false
        ));
    }

    /**
     * [BIZ-01] Soma minutos por status. Aprovado conta so "approved"; pendente,
     * so "pending". Rejeitado nunca entra em total. "synced" e "sync_error" nao
     * tem gravador no codigo e ficam fora dos dois totais ate haver decisao.
     */
    public static function overtimeTotals(iterable $rows): array {
        $totals = ['approved_minutes' => 0, 'pending_minutes' => 0, 'by_employee' => []];
        foreach ($rows as $row) {
            $key = match ((string)($row['status'] ?? '')) {
                'approved' => 'approved_minutes',
                'pending' => 'pending_minutes',
                default => null,
            };
            $employeeId = (int)($row['employee_id'] ?? 0);
            if (!isset($totals['by_employee'][$employeeId])) {
                $totals['by_employee'][$employeeId] = ['approved_minutes' => 0, 'pending_minutes' => 0];
            }
            if ($key === null) {
                continue;
            }
            $minutes = (int)($row['total_minutes'] ?? 0);
            $totals[$key] += $minutes;
            $totals['by_employee'][$employeeId][$key] += $minutes;
        }
        return $totals;
    }

    public function createOvertime(array $data, array $actor): int {
        return $this->atomic(fn(): int => $this->createOvertimeUnsafe($data, $actor));
    }

    private function createOvertimeUnsafe(array $data, array $actor): int {
        $employee = $this->targetEmployee($data, $actor);
        $date = self::dateValue($data['work_date'] ?? null, 'Data');
        $start = $this->timeValue($data['start_time'] ?? null, 'Hora inicial');
        $end = $this->timeValue($data['end_time'] ?? null, 'Hora final');
        $minutes = self::overtimeMinutes($start, $end);
        $reason = $this->requiredText($data['reason'] ?? null, 500, 'Motivo');
        $justification = $this->requiredText($data['justification'] ?? null, 2000, 'Justificativa');
        $username = $actor['username'];

        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_overtime_entries
                (employee_id, employee_name, team, work_date, start_time, end_time, total_minutes,
                 reason, justification, status, created_by, updated_by)
             VALUES
                (:employee_id, :employee_name, :team, :work_date, :start_time, :end_time, :total_minutes,
                 :reason, :justification, "pending", :created_by, :updated_by)'
        );
        $stmt->execute([
            ':employee_id' => $employee['id'],
            ':employee_name' => $employee['name'],
            ':team' => $employee['team'],
            ':work_date' => $date->format('Y-m-d'),
            ':start_time' => $start,
            ':end_time' => $end,
            ':total_minutes' => $minutes,
            ':reason' => $reason,
            ':justification' => $justification,
            ':created_by' => $username,
            ':updated_by' => $username,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->sync->enqueue($this->pdo, 'overtime', $id, [
            'id' => $id,
            'employee_id' => (int)$employee['id'],
            'employee_name' => $employee['name'],
            'team' => $employee['team'],
            'work_date' => $date->format('Y-m-d'),
            'start_time' => $start,
            'end_time' => $end,
            'total_minutes' => $minutes,
            'reason' => $reason,
            'justification' => $justification,
            'status' => 'pending',
        ], $username);
        return $id;
    }

    public function decideOvertime(int $id, string $decision, array $actor): void {
        $this->decideWorkflow('portal_overtime_entries', 'overtime', $id, $decision, $actor);
    }

    public function listTimeAdjustments(array $filters, array $actor): array {
        return $this->listWorkflowRecords('portal_time_adjustments', 'adjustment_date', $filters, $actor);
    }

    public function createTimeAdjustment(array $data, array $actor): int {
        return $this->atomic(fn(): int => $this->createTimeAdjustmentUnsafe($data, $actor));
    }

    private function createTimeAdjustmentUnsafe(array $data, array $actor): int {
        $employee = $this->targetEmployee($data, $actor);
        $date = self::dateValue($data['adjustment_date'] ?? null, 'Data');
        $type = (string)($data['adjustment_type'] ?? '');
        if (!in_array($type, ['entry', 'lunch_out', 'lunch_return', 'exit', 'absence', 'other'], true)) {
            throw new InvalidArgumentException('Tipo de ajuste invalido.');
        }
        $correctTime = $type === 'absence'
            ? null
            : $this->timeValue($data['correct_time'] ?? null, 'Horario correto');
        $recordedTime = empty($data['recorded_time'])
            ? null
            : $this->timeValue($data['recorded_time'], 'Horario registrado');
        $justification = $this->requiredText($data['justification'] ?? null, 2000, 'Justificativa');
        $username = $actor['username'];
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_time_adjustments
                (employee_id, employee_name, team, adjustment_date, adjustment_type,
                 correct_time, recorded_time, justification, status, created_by, updated_by)
             VALUES
                (:employee_id, :employee_name, :team, :adjustment_date, :adjustment_type,
                 :correct_time, :recorded_time, :justification, "pending", :created_by, :updated_by)'
        );
        $stmt->execute([
            ':employee_id' => $employee['id'],
            ':employee_name' => $employee['name'],
            ':team' => $employee['team'],
            ':adjustment_date' => $date->format('Y-m-d'),
            ':adjustment_type' => $type,
            ':correct_time' => $correctTime,
            ':recorded_time' => $recordedTime,
            ':justification' => $justification,
            ':created_by' => $username,
            ':updated_by' => $username,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->sync->enqueue($this->pdo, 'time_adjustment', $id, [
            'id' => $id,
            'employee_id' => (int)$employee['id'],
            'employee_name' => $employee['name'],
            'team' => $employee['team'],
            'adjustment_date' => $date->format('Y-m-d'),
            'adjustment_type' => $type,
            'correct_time' => $correctTime,
            'recorded_time' => $recordedTime,
            'justification' => $justification,
            'status' => 'pending',
        ], $username);
        return $id;
    }

    public function decideTimeAdjustment(int $id, string $decision, array $actor): void {
        $this->decideWorkflow('portal_time_adjustments', 'time_adjustment', $id, $decision, $actor);
    }

    public function listPendingWorkflowApprovals(array $actor): array {
        if (!portal_role_is_manager($actor['role'] ?? null)) {
            return [
                'overtime' => [],
                'time_adjustments' => [],
                'overtime_truncated' => false,
                'time_adjustments_truncated' => false,
                'limit' => self::LIST_LIMIT,
            ];
        }

        $overtime = $this->pendingWorkflowRecords('portal_overtime_entries', 'work_date');
        $adjustments = $this->pendingWorkflowRecords('portal_time_adjustments', 'adjustment_date');
        return [
            'overtime' => $overtime['items'],
            'time_adjustments' => $adjustments['items'],
            'overtime_truncated' => $overtime['truncated'],
            'time_adjustments_truncated' => $adjustments['truncated'],
            'limit' => self::LIST_LIMIT,
        ];
    }

    public function listOncall(array $filters): array {
        [$from, $to] = $this->period($filters, 366);
        $sql = 'SELECT id, employee_id, employee_name, team, starts_on, ends_on,
                       start_time, end_time, shift_type, note, status, created_by,
                       updated_by, created_at, updated_at
                FROM portal_oncall_shifts
                WHERE starts_on <= :date_to AND ends_on >= :date_from';
        $params = [':date_from' => $from, ':date_to' => $to];
        $team = $this->team($filters['team'] ?? null, true);
        $employeeId = $this->positiveInt($filters['employee_id'] ?? null, true);
        $this->appendEmployeeTeamFilters($sql, $params, $team, $employeeId);
        $stmt = $this->pdo->prepare($sql . ' ORDER BY starts_on DESC, start_time DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function createOncall(array $data, string $actor): int {
        return $this->atomic(fn(): int => $this->createOncallUnsafe($data, $actor));
    }

    private function createOncallUnsafe(array $data, string $actor): int {
        $employee = $this->employee($data['employee_id'] ?? null);
        $startsOn = self::dateValue($data['starts_on'] ?? null, 'Data inicial');
        $endsOn = self::dateValue($data['ends_on'] ?? null, 'Data final');
        if ($endsOn < $startsOn) {
            throw new InvalidArgumentException('A data final nao pode ser anterior a inicial.');
        }
        $type = (string)($data['shift_type'] ?? '');
        if (!in_array($type, ['oncall', 'standby', 'emergency', 'weekend', 'holiday'], true)) {
            throw new InvalidArgumentException('Tipo de plantao invalido.');
        }
        $startTime = $this->timeValue($data['start_time'] ?? null, 'Hora inicial');
        $endTime = $this->timeValue($data['end_time'] ?? null, 'Hora final');
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_oncall_shifts
                (employee_id, employee_name, team, starts_on, ends_on, start_time, end_time,
                 shift_type, note, status, created_by, updated_by)
             VALUES
                (:employee_id, :employee_name, :team, :starts_on, :ends_on, :start_time, :end_time,
                 :shift_type, :note, "active", :created_by, :updated_by)'
        );
        $payload = [
            ':employee_id' => $employee['id'],
            ':employee_name' => $employee['name'],
            ':team' => $employee['team'],
            ':starts_on' => $startsOn->format('Y-m-d'),
            ':ends_on' => $endsOn->format('Y-m-d'),
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':shift_type' => $type,
            ':note' => $this->text($data['note'] ?? '', 1000, true) ?: null,
            ':created_by' => $actor,
            ':updated_by' => $actor,
        ];
        $stmt->execute($payload);
        $id = (int)$this->pdo->lastInsertId();
        $this->sync->enqueue($this->pdo, 'oncall', $id, array_combine(
            array_map(static fn(string $key): string => ltrim($key, ':'), array_keys($payload)),
            array_values($payload)
        ) + ['id' => $id, 'status' => 'active'], $actor);
        return $id;
    }

    public function report(array $filters): array {
        $competency = self::competencyRange($filters['competency'] ?? null);
        $from = $filters['from'] ?? $competency['start'];
        $to = $filters['to'] ?? $competency['end'];
        [$from, $to] = $this->period(['from' => $from, 'to' => $to], 366);
        $team = $this->team($filters['team'] ?? null, true);
        $employeeId = $this->positiveInt($filters['employee_id'] ?? null, true);

        $overtime = $this->reportRows('portal_overtime_entries', 'work_date', $from, $to, $team, $employeeId);
        $adjustments = $this->reportRows('portal_time_adjustments', 'adjustment_date', $from, $to, $team, $employeeId);
        $oncall = $this->reportOncallRows($from, $to, $team, $employeeId);
        $schedule = $this->generatedSchedule($from, $to, $team, $employeeId);

        $byEmployee = [];
        $byTeam = [];
        foreach ($overtime as $row) {
            // [BIZ-01] Rejeitado nunca entra em total; aprovado e pendente separados.
            $key = match ($row['status']) {
                'approved' => 'overtime_approved_minutes',
                'pending' => 'overtime_pending_minutes',
                default => null,
            };
            if ($key === null) {
                continue;
            }
            $name = $row['employee_name'];
            $rowTeam = strtoupper($row['team']);
            $byEmployee[$name][$key] = ($byEmployee[$name][$key] ?? 0) + (int)$row['total_minutes'];
            $byTeam[$rowTeam][$key] = ($byTeam[$rowTeam][$key] ?? 0) + (int)$row['total_minutes'];
        }
        $overtimeTotals = self::overtimeTotals($overtime);
        foreach ($adjustments as $row) {
            $byEmployee[$row['employee_name']]['adjustments'] = ($byEmployee[$row['employee_name']]['adjustments'] ?? 0) + 1;
            $key = strtoupper($row['team']);
            $byTeam[$key]['adjustments'] = ($byTeam[$key]['adjustments'] ?? 0) + 1;
        }
        foreach ($oncall as $row) {
            $byEmployee[$row['employee_name']]['oncall'] = ($byEmployee[$row['employee_name']]['oncall'] ?? 0) + 1;
            $key = strtoupper($row['team']);
            $byTeam[$key]['oncall'] = ($byTeam[$key]['oncall'] ?? 0) + 1;
        }
        foreach ($schedule as $row) {
            if (!in_array($row['presence_type'], ['onsite', 'remote'], true)) {
                continue;
            }
            $key = $row['presence_type'] === 'onsite' ? 'onsite_days' : 'remote_days';
            $byEmployee[$row['employee_name']][$key] = ($byEmployee[$row['employee_name']][$key] ?? 0) + 1;
            $teamKey = strtoupper($row['team']);
            $byTeam[$teamKey][$key] = ($byTeam[$teamKey][$key] ?? 0) + 1;
        }

        $statuses = array_merge(
            array_column($overtime, 'status'),
            array_column($adjustments, 'status'),
            array_column($oncall, 'status')
        );
        return [
            'competency' => $competency,
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'overtime_count' => count($overtime),
                'overtime_approved_minutes' => $overtimeTotals['approved_minutes'],
                'overtime_pending_minutes' => $overtimeTotals['pending_minutes'],
                'adjustments_count' => count($adjustments),
                'oncall_count' => count($oncall),
                'onsite_days' => count(array_filter($schedule, static fn(array $row): bool => $row['presence_type'] === 'onsite')),
                'remote_days' => count(array_filter($schedule, static fn(array $row): bool => $row['presence_type'] === 'remote')),
                'pending' => count(array_filter($statuses, static fn(string $status): bool => $status === 'pending')),
                'rejected' => count(array_filter($statuses, static fn(string $status): bool => $status === 'rejected')),
                'sync_errors' => count(array_filter($statuses, static fn(string $status): bool => $status === 'sync_error')),
            ],
            'by_employee' => $byEmployee,
            'by_team' => $byTeam,
            'overtime' => $overtime,
            'time_adjustments' => $adjustments,
            'oncall' => $oncall,
            'schedule' => $schedule,
        ];
    }

    public function streamReportCsv(array $report): void {
        $filename = 'portal_sdk_' . $report['competency']['key'] . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $handle = fopen('php://output', 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['tipo', 'colaborador', 'equipe', 'data', 'detalhe', 'status'], ';');
        foreach ($report['overtime'] as $row) {
            $this->csvRow($handle, ['hora_extra', $row['employee_name'], strtoupper($row['team']), $row['work_date'], $row['total_minutes'] . ' min - ' . $row['reason'], $row['status']]);
        }
        foreach ($report['time_adjustments'] as $row) {
            $this->csvRow($handle, ['ajuste_ponto', $row['employee_name'], strtoupper($row['team']), $row['adjustment_date'], $row['adjustment_type'] . ' - ' . $row['justification'], $row['status']]);
        }
        foreach ($report['oncall'] as $row) {
            $this->csvRow($handle, ['plantao', $row['employee_name'], strtoupper($row['team']), $row['starts_on'], $row['shift_type'], $row['status']]);
        }
        foreach ($report['schedule'] as $row) {
            $this->csvRow($handle, ['escala', $row['employee_name'], strtoupper($row['team']), $row['date'], $row['label'], $row['presence_type']]);
        }
        fclose($handle);
    }

    /**
     * [BIZ-01][PERF-02] Todas as linhas do filtro, em paginas, sem limite. Os
     * totais por colaborador cobrem o filtro inteiro e sao calculados antes da
     * primeira linha: "Total aprovado" e "Total pendente"; rejeitado fica fora
     * dos dois e aparece identificado na coluna Status.
     */
    public function streamOvertimeCsv(array $filters, array $actor): void {
        $totalsByEmployee = $this->overtimeTotalsForFilters($filters, $actor)['by_employee'];
        $rows = $this->iterateWorkflowRecords('portal_overtime_entries', 'work_date', $filters, $actor);

        $filename = 'horas_extras_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $handle = fopen('php://output', 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::OVERTIME_CSV_HEADER, ';');
        foreach ($rows as $row) {
            $this->csvRow($handle, self::overtimeCsvRow($row, $totalsByEmployee));
        }
        fclose($handle);
    }

    public const OVERTIME_CSV_HEADER = [
        'NC',
        'Nome completo',
        'Data da realizacao',
        'Hora de entrada',
        'Hora de saida',
        'Descricao',
        'Total de Horas',
        'Total aprovado',
        'Total pendente',
        'Status',
    ];

    /**
     * Uma linha do CSV de horas extras. Os dois totais sao do colaborador no
     * filtro inteiro e se repetem em cada linha dele, como o antigo "Total
     * Realizado", que somava todos os status.
     */
    public static function overtimeCsvRow(array $row, array $totalsByEmployee): array {
        $employeeTotals = $totalsByEmployee[(int)$row['employee_id']] ?? ['approved_minutes' => 0, 'pending_minutes' => 0];
        return [
            '',
            $row['employee_name'],
            $row['work_date'],
            substr((string)$row['start_time'], 0, 5),
            substr((string)$row['end_time'], 0, 5),
            $row['reason'] ?: $row['justification'],
            self::minutesText((int)$row['total_minutes']),
            self::minutesText((int)$employeeTotals['approved_minutes']),
            self::minutesText((int)$employeeTotals['pending_minutes']),
            self::workflowStatusLabel($row['status']),
        ];
    }

    public function streamTimeAdjustmentsCsv(array $filters, array $actor): void {
        $rows = $this->iterateWorkflowRecords('portal_time_adjustments', 'adjustment_date', $filters, $actor);
        $filename = 'correcao_ponto_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $handle = fopen('php://output', 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'NC',
            'Nome completo',
            'Equipe',
            'Data',
            'Tipo de ajuste',
            'Horario registrado',
            'Horario correto',
            'Justificativa',
            'Status',
            'Aprovador',
            'Data de aprovacao/rejeicao',
        ], ';');
        foreach ($rows as $row) {
            $this->csvRow($handle, [
                '',
                $row['employee_name'],
                strtoupper($row['team']),
                $row['adjustment_date'],
                $this->adjustmentTypeLabel($row['adjustment_type']),
                $row['recorded_time'] ? substr((string)$row['recorded_time'], 0, 5) : '',
                $row['correct_time'] ? substr((string)$row['correct_time'], 0, 5) : '',
                $row['justification'],
                $this->workflowStatusLabel($row['status']),
                $row['approved_by'] ?? '',
                $row['approved_at'] ?? '',
            ]);
        }
        fclose($handle);
    }

    /**
     * Lista da tela: ate LIST_LIMIT linhas, com aviso quando ha mais (PERF-02).
     */
    private function listWorkflowRecords(string $table, string $dateColumn, array $filters, array $actor): array {
        [$sql, $params] = $this->workflowFilterSql($table, $dateColumn, $filters, $actor, true);
        $stmt = $this->pdo->prepare($sql . " ORDER BY {$dateColumn} DESC, id DESC LIMIT " . (self::LIST_LIMIT + 1));
        $stmt->execute($params);
        return db_limit_rows($stmt->fetchAll(), self::LIST_LIMIT);
    }

    /**
     * Todas as linhas do filtro, em paginas por chave (data, id), para export.
     */
    private function iterateWorkflowRecords(
        string $table,
        string $dateColumn,
        array $filters,
        array $actor,
        bool $withStatus = true
    ): Generator {
        [$sql, $params] = $this->workflowFilterSql($table, $dateColumn, $filters, $actor, $withStatus);
        return db_keyset_iterate(function (?array $lastRow, int $chunk) use ($sql, $params, $dateColumn): array {
            if ($lastRow !== null) {
                $sql .= " AND ({$dateColumn} < :cursor_date OR ({$dateColumn} = :cursor_same_date AND id < :cursor_id))";
                $params[':cursor_date'] = $lastRow[$dateColumn];
                $params[':cursor_same_date'] = $lastRow[$dateColumn];
                $params[':cursor_id'] = (int)$lastRow['id'];
            }
            $stmt = $this->pdo->prepare($sql . " ORDER BY {$dateColumn} DESC, id DESC LIMIT " . $chunk);
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    }

    /**
     * Filtro comum da lista e do export. Sem periodo informado, vale a
     * competencia corrente (16 a 15), a mesma que a tela usa por padrao.
     */
    private function workflowFilterSql(
        string $table,
        string $dateColumn,
        array $filters,
        array $actor,
        bool $withStatus
    ): array {
        [$from, $to] = $this->period($filters, 366, true);
        $sql = 'SELECT ' . $this->workflowColumns($table)
            . " FROM {$table} WHERE {$dateColumn} BETWEEN :date_from AND :date_to";
        $params = [':date_from' => $from, ':date_to' => $to];
        $team = $this->team($filters['team'] ?? null, true);
        $employeeId = $this->positiveInt($filters['employee_id'] ?? null, true);
        if (in_array($actor['role'], ['tecnico', 'somente_leitura'], true)) {
            $employeeId = (int)$actor['employee_id'];
        }
        $this->appendEmployeeTeamFilters($sql, $params, $team, $employeeId);
        $status = $this->text($filters['status'] ?? '', 20, true);
        if ($withStatus && $status) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        return [$sql, $params];
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
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function decideWorkflow(string $table, string $recordType, int $id, string $decision, array $actor): void {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Decisao invalida.');
        }
        $this->atomic(function () use ($table, $recordType, $id, $decision, $actor): void {
            $stmt = $this->pdo->prepare(
                'SELECT ' . $this->workflowColumns($table) . " FROM {$table} WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $id]);
            $record = $stmt->fetch();
            if (!$record) {
                throw new DomainException('Registro nao encontrado.');
            }
            if ($record['status'] !== 'pending') {
                throw new DomainException('Somente registros pendentes podem ser decididos.');
            }
            $actorEmployeeId = $this->actorEmployeeId($actor);
            if ($actorEmployeeId !== null && (int)$record['employee_id'] === $actorEmployeeId) {
                throw new DomainException('Nao e permitido aprovar o proprio lancamento.');
            }
            if ($recordType === 'overtime' && $decision === 'approved') {
                self::assertOvertimeApprovable($record);
            }
            $update = $this->pdo->prepare(
                "UPDATE {$table}
                 SET status = :status, approved_by = :approved_by, approved_at = NOW(), updated_by = :updated_by
                 WHERE id = :id"
            );
            $update->execute([
                ':status' => $decision,
                ':approved_by' => $actor['username'],
                ':updated_by' => $actor['username'],
                ':id' => $id,
            ]);
            $record['status'] = $decision;
            $record['approved_by'] = $actor['username'];
            $this->sync->enqueue($this->pdo, $recordType, $id, $record, $actor['username']);
        });
    }

    private function pendingWorkflowRecords(string $table, string $dateColumn): array {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->workflowColumns($table)
            . " FROM {$table} WHERE status = :status ORDER BY {$dateColumn} DESC, id DESC LIMIT " . (self::LIST_LIMIT + 1)
        );
        $stmt->execute([':status' => 'pending']);
        return db_limit_rows($stmt->fetchAll(), self::LIST_LIMIT);
    }

    private function actorEmployeeId(array $actor): ?int {
        if (!empty($actor['employee_id'])) {
            return (int)$actor['employee_id'];
        }
        $username = strtolower(trim((string)($actor['username'] ?? '')));
        if ($username === '') {
            return null;
        }
        if (strpos($username, '@') !== false) {
            $username = explode('@', $username, 2)[0];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM funcionarios WHERE ativo = 1 AND LOWER(ad_login) = :ad_login LIMIT 1'
        );
        $stmt->execute([':ad_login' => $username]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function targetEmployee(array $data, array $actor): array {
        if ($actor['role'] === 'somente_leitura') {
            throw new DomainException('Seu perfil possui acesso somente para leitura.');
        }
        if ($actor['role'] === 'tecnico') {
            if (empty($actor['employee_id'])) {
                throw new DomainException('Sessao sem colaborador vinculado.');
            }
            if (!empty($data['employee_id']) && (int)$data['employee_id'] !== (int)$actor['employee_id']) {
                throw new DomainException('Nao e permitido criar lancamento para outro colaborador.');
            }
            return $this->employee($actor['employee_id']);
        }
        return $this->employee($data['employee_id'] ?? null);
    }

    private function employee($id): array {
        $employee = $this->activeEmployeeRow($this->positiveInt($id));
        if (!$employee || !in_array($employee['team'], ['n1', 'n2'], true)) {
            throw new InvalidArgumentException('Colaborador nao encontrado ou inativo.');
        }
        return $employee;
    }

    private function activeEmployeeRow(int $employeeId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login, access_role
             FROM funcionarios WHERE id = :id AND ativo = 1'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        return $employee ?: null;
    }

    /** [F1] Colaborador ativo de qualquer equipe (N1, N2 ou Lideranca), com a equipe normalizada. */
    private function scheduleEmployee($id): array {
        $employee = $this->activeEmployeeRow($this->positiveInt($id));
        $team = $employee ? validate_funcionario_equipe((string)$employee['team']) : null;
        if (!$employee || $team === null) {
            throw new InvalidArgumentException('Colaborador nao encontrado ou inativo.');
        }
        $employee['team'] = $team;
        return $employee;
    }

    /**
     * [F1] Trava o cadastro ate o fim da transacao: serializa as gravacoes de
     * escala do mesmo colaborador (a nao sobreposicao fica no servico).
     */
    private function lockScheduleEmployee(array $employee): void {
        $stmt = $this->pdo->prepare('SELECT id FROM funcionarios WHERE id = :id AND ativo = 1 FOR UPDATE');
        $stmt->execute([':id' => (int)$employee['id']]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Colaborador nao encontrado ou inativo.');
        }
    }

    /** [F1] Historico completo de regras do colaborador, para o planejador. */
    private function employeeScheduleRules(int $employeeId): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, rule_type, rule_config, effective_from, effective_until
             FROM portal_schedule_rules
             WHERE employee_id = :employee_id
             ORDER BY effective_from, id'
        );
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetchAll();
    }

    private function closeScheduleRule(array $close, int $employeeId, string $actor): void {
        $stmt = $this->pdo->prepare(
            'UPDATE portal_schedule_rules
             SET effective_until = :effective_until, updated_by = :updated_by
             WHERE id = :id AND employee_id = :employee_id'
        );
        $stmt->execute([
            ':effective_until' => $close['effective_until'],
            ':updated_by' => $actor,
            ':id' => $close['id'],
            ':employee_id' => $employeeId,
        ]);
    }

    /**
     * [F1] Confere o historico depois de gravar: nenhuma violacao nova (regra
     * aberta repetida, inicio repetido, sobreposicao). Se houver, a excecao
     * desfaz a transacao inteira.
     */
    private function assertScheduleHistoryConsistent(array $before, int $employeeId): void {
        $new = array_diff(
            ScheduleRulePlanner::violations($this->employeeScheduleRules($employeeId)),
            ScheduleRulePlanner::violations($before)
        );
        if ($new !== []) {
            throw new RuntimeException('Historico de escala inconsistente apos gravar: ' . implode('; ', $new));
        }
    }

    /** [L9] Escala de Liderança, ou de cadastro Liderança/admin, só pelo admin. */
    private function assertScheduleChangeAllowed(array $employee, string $actorRole): void {
        $denied = schedule_change_denied($actorRole, $employee['team'] ?? null, $employee['access_role'] ?? null);
        if ($denied !== null) {
            throw new PortalForbiddenException($denied[1]);
        }
    }

    private function findEmployeeForImport(array $row): ?array {
        $conditions = [];
        $params = [];
        if (!empty($row['id']) || !empty($row['employee_id'])) {
            $conditions[] = 'id = :id';
            $params[':id'] = (int)($row['id'] ?? $row['employee_id']);
        }
        $login = strtolower(trim((string)($row['login_ad'] ?? $row['ad_login'] ?? $row['email'] ?? '')));
        if (strpos($login, '@') !== false) {
            $login = explode('@', $login, 2)[0];
        }
        if ($login !== '') {
            $conditions[] = 'LOWER(ad_login) = :ad_login';
            $params[':ad_login'] = $login;
        }
        $name = trim((string)($row['nome'] ?? $row['name'] ?? ''));
        if ($name !== '') {
            $conditions[] = 'LOWER(nome) = LOWER(:name)';
            $params[':name'] = $name;
        }
        if (!$conditions) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login
             FROM funcionarios WHERE ativo = 1 AND (' . implode(' OR ', $conditions) . ') LIMIT 2'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (count($rows) !== 1) {
            return null;
        }
        $rows[0]['team'] = validate_funcionario_equipe((string)$rows[0]['team']) ?? $rows[0]['team'];
        return $rows[0];
    }

    private function normalizeRule(string $rule): ?string {
        $key = strtolower(trim($rule));
        $map = [
            'even_days' => 'even_days', 'par' => 'even_days', 'pares' => 'even_days',
            'odd_days' => 'odd_days', 'impar' => 'odd_days', 'impares' => 'odd_days',
            'always_remote' => 'always_remote', 'remoto' => 'always_remote',
            'always_onsite' => 'always_onsite', 'presencial' => 'always_onsite',
            'undefined' => 'undefined', 'sem escala' => 'undefined',
        ];
        return $map[$key] ?? null;
    }

    /**
     * $periodFrom: so as regras que vigoram a partir dessa data (e as
     * agendadas); null traz o historico inteiro, inclusive linhas vazias.
     */
    private function scheduleRules(?string $team, ?int $employeeId, ?string $periodFrom = null): array {
        $sql = 'SELECT r.id, r.employee_id, f.nome AS employee_name, f.ad_login, LOWER(f.equipe) AS team, r.rule_type, r.rule_config,
                       r.effective_from, r.effective_until, r.created_by, r.updated_by,
                       r.created_at, r.updated_at
                FROM portal_schedule_rules r
                JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 1
                WHERE LOWER(f.equipe) IN ("n1", "n2")';
        $params = [];
        if ($periodFrom !== null) {
            $sql .= ' AND (r.effective_until IS NULL
                           OR (r.effective_until >= :period_from AND r.effective_until >= r.effective_from))';
            $params[':period_from'] = $periodFrom;
        }
        if ($team) {
            $sql .= ' AND LOWER(f.equipe) = :team';
            $params[':team'] = $team;
        }
        if ($employeeId) {
            $sql .= ' AND r.employee_id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY employee_name, r.effective_from DESC, r.id DESC');
        $stmt->execute($params);
        return array_map(fn(array $row): array => $this->scheduleRuleView($row), $stmt->fetchAll());
    }

    /**
     * [P12] Escala implicita da Lideranca: sem linha em portal_schedule_rules;
     * presencial nos dias uteis, na jornada do proprio cadastro; sabado e
     * domingo so por excecao. O filtro de equipe da tela so tem N1 e N2.
     */
    private function leadershipImplicitRules(?string $team, ?int $employeeId): array {
        if ($team !== null) {
            return [];
        }
        $sql = 'SELECT id, nome AS employee_name, ad_login, equipe, jornada_entrada, jornada_saida
                FROM funcionarios
                WHERE ativo = 1';
        $params = [];
        if ($employeeId) {
            $sql .= ' AND id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY nome');
        $stmt->execute($params);
        $rules = [];
        foreach ($stmt->fetchAll() as $row) {
            if (validate_funcionario_equipe((string)$row['equipe']) !== 'lideranca') {
                continue;
            }
            $rules[] = [
                'id' => null,
                'employee_id' => (int)$row['id'],
                'employee_name' => $row['employee_name'],
                'ad_login' => $row['ad_login'],
                'team' => 'lideranca',
                'rule_type' => 'always_onsite',
                'rule_config' => null,
                'config' => null,
                'weekdays' => [],
                'rule_label' => self::LEADERSHIP_RULE_LABEL,
                'effective_from' => null,
                'effective_until' => null,
                'is_current' => true,
                'is_future' => false,
                'implicit' => true,
                'work_start' => substr((string)$row['jornada_entrada'], 0, 5),
                'work_end' => substr((string)$row['jornada_saida'], 0, 5),
            ];
        }
        return $rules;
    }

    private function scheduleExceptions(string $from, string $to, ?string $team, ?int $employeeId): array {
        $sql = 'SELECT id, employee_id, employee_name, team, exception_date,
                       exception_type, note, created_by, created_at, updated_at
                FROM portal_schedule_exceptions
                WHERE exception_date BETWEEN :date_from AND :date_to';
        $params = [':date_from' => $from, ':date_to' => $to];
        $this->appendEmployeeTeamFilters($sql, $params, $team, $employeeId);
        $stmt = $this->pdo->prepare($sql . ' ORDER BY exception_date, employee_name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function generatedSchedule(
        string $from,
        string $to,
        ?string $team = null,
        ?int $employeeId = null,
        ?array $rules = null,
        ?array $exceptions = null
    ): array {
        $rules = $rules ?? $this->scheduleRules($team, $employeeId);
        $exceptions = $exceptions ?? $this->scheduleExceptions($from, $to, $team, $employeeId);
        $exceptionMap = [];
        foreach ($exceptions as $exception) {
            $exceptionMap[$exception['employee_id'] . ':' . $exception['exception_date']] = $exception;
        }
        $absenceMap = $this->scheduleBlockingAbsences($from, $to, $team, $employeeId);
        $items = [];
        $start = self::dateValue($from, 'Inicio');
        $end = self::dateValue($to, 'Fim');
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            foreach ($rules as $rule) {
                $dateKey = $date->format('Y-m-d');
                $implicit = ($rule['implicit'] ?? false) === true;
                if (!$implicit && $dateKey < $rule['effective_from']) {
                    continue;
                }
                if (!$implicit && $rule['effective_until'] && $dateKey > $rule['effective_until']) {
                    continue;
                }
                $key = $rule['employee_id'] . ':' . $dateKey;
                if (isset($absenceMap[$key])) {
                    continue;
                }
                $exception = $exceptionMap[$key] ?? null;
                // [P12] Lideranca: sabado e domingo so com excecao.
                if ($implicit && !$exception && (int)$date->format('N') >= 6) {
                    continue;
                }
                if ($exception) {
                    $presence = $this->presenceForException($exception['exception_type']);
                    $label = $this->scheduleStatusLabel($presence);
                    $source = 'exception';
                } else {
                    $presence = self::presenceForRule($rule['rule_type'], $dateKey, $rule['weekdays'] ?? []);
                    $label = $this->scheduleStatusLabel($presence);
                    $source = $implicit ? 'implicit' : 'rule';
                }
                $ruleLabel = $implicit
                    ? $rule['rule_label']
                    : $this->scheduleRuleLabel($rule['rule_type'], $rule['weekdays'] ?? []);
                $items[] = [
                    'employee_id' => (int)$rule['employee_id'],
                    'employee_name' => $rule['employee_name'],
                    'team' => $rule['team'],
                    'date' => $dateKey,
                    'presence_type' => $presence,
                    'label' => $label,
                    'source' => $source,
                    'rule_type' => $rule['rule_type'],
                    'weekdays' => $rule['weekdays'] ?? [],
                    'rule_label' => $ruleLabel,
                ];
            }
        }
        return $items;
    }

    private function presenceForException(string $type): string {
        return match ($type) {
            'onsite', 'oncall', 'training' => 'onsite',
            'remote' => 'remote',
            'day_off' => 'day_off',
            'absence', 'vacation', 'leave' => 'absence',
            default => 'remote',
        };
    }

    private function scheduleBlockingAbsences(string $from, string $to, ?string $team, ?int $employeeId): array {
        $sql = 'SELECT a.employee_id, a.starts_on, a.ends_on
                FROM portal_absences a
                JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 1
                WHERE a.starts_on <= :date_to
                  AND a.ends_on >= :date_from
                  AND a.status = "approved"';
        $params = [':date_from' => $from, ':date_to' => $to];
        if ($team) {
            $sql .= ' AND LOWER(f.equipe) = :team';
            $params[':team'] = $team;
        }
        if ($employeeId) {
            $sql .= ' AND a.employee_id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $map = [];
        $rangeStart = self::dateValue($from, 'Inicio');
        $rangeEnd = self::dateValue($to, 'Fim');
        foreach ($stmt->fetchAll() as $row) {
            $start = self::dateValue($row['starts_on'], 'Inicio da ausencia');
            $end = self::dateValue($row['ends_on'], 'Fim da ausencia');
            if ($start < $rangeStart) {
                $start = $rangeStart;
            }
            if ($end > $rangeEnd) {
                $end = $rangeEnd;
            }
            for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                $map[$row['employee_id'] . ':' . $date->format('Y-m-d')] = true;
            }
        }
        return $map;
    }

    private function scheduleStatusLabel(string $status): string {
        return match ($status) {
            'onsite' => 'Presencial',
            'remote' => 'Remoto',
            'absence' => 'Ausencia',
            'day_off' => 'Folga',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function normalizeScheduleWeekdays($value, bool $strict = true): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (!is_array($value) || self::isList($value) === false) {
            if ($strict) {
                throw new InvalidArgumentException('Dias da semana invalidos.');
            }
            return [];
        }
        $seen = [];
        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                if ($strict) {
                    throw new InvalidArgumentException('Dias da semana invalidos.');
                }
                return [];
            }
            $weekday = strtolower(trim($item));
            if (!in_array($weekday, self::WEEKDAY_ORDER, true)) {
                if ($strict) {
                    throw new InvalidArgumentException('Dia da semana invalido.');
                }
                return [];
            }
            if (isset($seen[$weekday])) {
                if ($strict) {
                    throw new InvalidArgumentException('Dias da semana duplicados.');
                }
                return [];
            }
            $seen[$weekday] = true;
            $normalized[] = $weekday;
        }
        return array_values(array_filter(
            self::WEEKDAY_ORDER,
            static fn(string $weekday): bool => isset($seen[$weekday])
        ));
    }

    public static function scheduleRuleConfigWeekdays($config): array {
        if (!is_string($config) || trim($config) === '') {
            return [];
        }
        $decoded = json_decode($config, true);
        if (!is_array($decoded)) {
            return [];
        }
        return self::normalizeScheduleWeekdays($decoded['weekdays'] ?? [], false);
    }

    public static function weekdayListLabel(array $weekdays): string {
        $labels = array_values(array_filter(array_map(
            static fn(string $weekday): ?string => self::WEEKDAY_LABELS[$weekday] ?? null,
            $weekdays
        )));
        if ($labels === []) {
            return 'dias nao definidos';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }
        $last = array_pop($labels);
        return implode(', ', $labels) . ' e ' . $last;
    }

    private function weekdaysForRulePayload(string $rule, $value): array {
        if ($rule !== 'fixed_weekdays') {
            return [];
        }
        $weekdays = self::normalizeScheduleWeekdays($value);
        if ($weekdays === []) {
            throw new InvalidArgumentException('Selecione ao menos um dia presencial.');
        }
        return $weekdays;
    }

    private function scheduleRuleView(array $row): array {
        $weekdays = $row['rule_type'] === 'fixed_weekdays'
            ? self::scheduleRuleConfigWeekdays($row['rule_config'] ?? null)
            : [];
        $row['weekdays'] = $weekdays;
        $row['rule_label'] = $this->scheduleRuleLabel((string)$row['rule_type'], $weekdays);
        // [F1] A tela nao infere "ativa" por effective_until vazio (regra agendada).
        $config = is_string($row['rule_config'] ?? null) ? json_decode($row['rule_config'], true) : null;
        $row['config'] = is_array($config) ? $config : null;
        $row['is_current'] = ScheduleRulePlanner::isCurrent($row, $this->today());
        $row['is_future'] = ScheduleRulePlanner::isFuture($row, $this->today());
        $row['implicit'] = false;
        return $row;
    }

    private function scheduleRuleLabel(string $rule, array $weekdays = []): string {
        return match ($rule) {
            'even_days' => 'Dias pares',
            'odd_days' => 'Dias impares',
            'always_onsite' => 'Sempre presencial',
            'always_remote' => 'Sempre remoto',
            'undefined' => 'Sem escala definida',
            'fixed_weekdays' => 'Presencial: ' . self::weekdayListLabel($weekdays),
            default => 'Regra nao informada',
        };
    }

    private function auditScheduleRuleChange(string $origin, array $plan, bool $hadRules, array $current, string $actor): void {
        if (!function_exists('audit_log')) {
            return;
        }
        $previous = $plan['previous'];
        $action = match (true) {
            $origin === 'import' => 'SCHEDULE_RULE_IMPORTED',
            $previous === null && $hadRules => 'SCHEDULE_RULE_RECREATED',
            $previous === null => 'SCHEDULE_RULE_CREATED',
            default => 'SCHEDULE_RULE_UPDATED',
        };
        audit_log(
            $action,
            'Usuario=' . $actor
                . '; origem=' . $origin
                . '; operacao=' . $plan['action']
                . '; retroativa=' . ($plan['retroactive'] ? 'sim' : 'nao')
                . '; employee_id=' . (int)$current['employee_id']
                . '; employee_name=' . ($current['employee_name'] ?? '')
                . '; ad_login=' . ($current['ad_login'] ?? '')
                . '; team=' . ($current['team'] ?? '')
                . '; regra_anterior=' . ($previous['rule_type'] ?? 'none')
                . '; vigencia_anterior=' . ($previous['effective_from'] ?? 'none')
                . '..' . ($previous['effective_until'] ?? 'NULL')
                . '; encerrada=' . ($plan['close'] === null ? 'nenhuma' : $plan['close']['id'] . ' ate ' . $plan['close']['effective_until'])
                . '; regra_nova=' . ($current['rule_type'] ?? '')
                . '; vigencia_nova=' . ($current['effective_from'] ?? '')
                . '..' . ($current['effective_until'] ?? 'NULL'),
            'WARNING'
        );
    }

    private function auditScheduleRuleRemoval(array $plan, array $employee, string $actor): void {
        if (!function_exists('audit_log')) {
            return;
        }
        $deleted = array_map(
            static fn (array $row): string => $row['id'] . ':' . $row['rule_type']
                . ($row['rule_config'] ? ' ' . $row['rule_config'] : '')
                . ' ' . $row['effective_from'] . '..' . ($row['effective_until'] ?? 'NULL'),
            $plan['delete']
        );
        audit_log(
            'SCHEDULE_RULE_REMOVED',
            'Usuario=' . $actor
                . '; origem=manual'
                . '; employee_id=' . (int)$employee['id']
                . '; employee_name=' . ($employee['name'] ?? '')
                . '; ad_login=' . ($employee['ad_login'] ?? '')
                . '; team=' . ($employee['team'] ?? '')
                . '; regra_vigente=' . ($plan['current']['rule_type'] ?? 'none')
                . '; encerrada=' . ($plan['close'] === null ? 'nenhuma' : $plan['close']['id'] . ' ate ' . $plan['close']['effective_until'])
                . '; apagadas=' . ($deleted === [] ? 'nenhuma' : implode(' | ', $deleted)),
            'WARNING'
        );
    }

    /**
     * Nome do status no CSV: nunca em branco, inclusive para "synced" e
     * "sync_error", que ficam fora dos totais (divida tecnica, HORAS_EXTRAS_LOTE6).
     */
    private static function workflowStatusLabel(string $status): string {
        return match ($status) {
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            'synced' => 'Sincronizado',
            'sync_error' => 'Erro de sincronizacao',
            '' => 'Sem status',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function adjustmentTypeLabel(string $type): string {
        return match ($type) {
            'entry' => 'Entrada',
            'lunch_out' => 'Saida para almoco',
            'lunch_return' => 'Retorno do almoco',
            'exit' => 'Saida',
            'absence' => 'Ausencia',
            'other' => 'Outro',
            default => 'Ajuste',
        };
    }

    private static function minutesText(int $minutes): string {
        return sprintf('%02d:%02d', intdiv(max(0, $minutes), 60), max(0, $minutes) % 60);
    }

    private function reportRows(string $table, string $dateColumn, string $from, string $to, ?string $team, ?int $employeeId): array {
        $sql = 'SELECT ' . $this->workflowColumns($table)
            . " FROM {$table} WHERE {$dateColumn} BETWEEN :date_from AND :date_to";
        $params = [':date_from' => $from, ':date_to' => $to];
        $this->appendEmployeeTeamFilters($sql, $params, $team, $employeeId);
        $stmt = $this->pdo->prepare($sql . " ORDER BY {$dateColumn}, employee_name");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function reportOncallRows(string $from, string $to, ?string $team, ?int $employeeId): array {
        $sql = 'SELECT id, employee_id, employee_name, team, starts_on, ends_on,
                       start_time, end_time, shift_type, note, status, created_by,
                       updated_by, created_at, updated_at
                FROM portal_oncall_shifts
                WHERE starts_on <= :date_to AND ends_on >= :date_from';
        $params = [':date_from' => $from, ':date_to' => $to];
        $this->appendEmployeeTeamFilters($sql, $params, $team, $employeeId);
        $stmt = $this->pdo->prepare($sql . ' ORDER BY starts_on, employee_name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function appendEmployeeTeamFilters(
        string &$sql,
        array &$params,
        ?string $team,
        ?int $employeeId,
        string $alias = ''
    ): void {
        $prefix = $alias ? $alias . '.' : '';
        if ($team) {
            $sql .= " AND {$prefix}team = :team";
            $params[':team'] = $team;
        }
        if ($employeeId) {
            $sql .= " AND {$prefix}employee_id = :employee_id";
            $params[':employee_id'] = $employeeId;
        }
    }

    private function workflowColumns(string $table): string {
        return match ($table) {
            'portal_overtime_entries' =>
                'id, employee_id, employee_name, team, work_date, start_time, end_time,
                 total_minutes, reason, justification, status, approved_by, approved_at,
                 created_by, updated_by, created_at, updated_at',
            'portal_time_adjustments' =>
                'id, employee_id, employee_name, team, adjustment_date, adjustment_type,
                 correct_time, recorded_time, justification, status, approved_by, approved_at,
                 created_by, updated_by, created_at, updated_at',
            default => throw new LogicException('Tabela de workflow invalida.'),
        };
    }

    private function calendarRow(string $type, array $row): array {
        return [
            'id' => $type . '-' . $row['id'],
            'event_type' => $type,
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'team' => $row['team'] ?? null,
            'employee_id' => isset($row['employee_id']) ? (int)$row['employee_id'] : null,
            'status' => $row['status'],
        ];
    }

    private function period(array $filters, int $maxDays, bool $defaultToCompetency = false): array {
        if (!empty($filters['competency'])) {
            $competency = self::competencyRange((string)$filters['competency']);
            $from = $competency['start'];
            $to = $competency['end'];
        } elseif ($defaultToCompetency) {
            $competency = self::competencyRange();
            $from = $filters['from'] ?? $competency['start'];
            $to = $filters['to'] ?? $competency['end'];
        } else {
            $from = $filters['from'] ?? date('Y-m-01');
            $to = $filters['to'] ?? date('Y-m-t');
        }
        $start = self::dateValue($from, 'Data inicial');
        $end = self::dateValue($to, 'Data final');
        if ($end < $start) {
            throw new InvalidArgumentException('Periodo invalido.');
        }
        if ((int)$start->diff($end)->format('%a') > $maxDays) {
            throw new InvalidArgumentException("O periodo maximo permitido e de {$maxDays} dias.");
        }
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private static function dateValue($value, string $label): DateTimeImmutable {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        return $date;
    }

    private function dateTime($value, string $label): DateTimeImmutable {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/', $value)) {
            throw new InvalidArgumentException("{$label} invalido.");
        }
        $normalized = str_replace('T', ' ', trim($value));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($normalized, 0, 16));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException("{$label} invalido.");
        }
        return $date;
    }

    private function timeValue($value, string $label): string {
        if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    /**
     * [BIZ-04] Lancamento antigo com entrada igual a saida, gravado como 24 h
     * antes da recusa no cadastro, nao pode ser aprovado. A rejeicao continua
     * permitida; lancamentos ja aprovados e os dados gravados nao mudam.
     */
    public static function assertOvertimeApprovable(array $record): void {
        $normalize = static fn ($time): string => strlen((string)$time) === 5 ? $time . ':00' : (string)$time;
        if ($normalize($record['start_time'] ?? '') === $normalize($record['end_time'] ?? '')) {
            throw new DomainException(
                'Lancamento com hora de entrada igual a hora de saida, contado como 24 h. '
                . 'Nao e possivel aprova-lo: rejeite e peca ao colaborador para lancar de novo '
                . 'com o horario real de saida (se passou da meia-noite, a saida fica menor que a entrada).'
            );
        }
    }

    /**
     * [BIZ-04] Minutos entre entrada e saida no formato HH:MM[:SS]. Saida menor
     * que a entrada e virada de dia (22:00 as 02:00 = 240). Entrada igual a
     * saida e recusada: antes virava 24 h de hora extra. Sem teto por lancamento
     * (decisao do negocio). Conta em segundos do dia, sem DateTime nem fuso
     * (BIZ-06).
     */
    public static function overtimeMinutes(string $start, string $end): int {
        $seconds = static function (string $time): int {
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $time, $parts)) {
                throw new InvalidArgumentException('Horario invalido.');
            }
            return (int)$parts[1] * 3600 + (int)$parts[2] * 60 + (int)($parts[3] ?? 0);
        };
        $difference = $seconds($end) - $seconds($start);
        if ($difference === 0) {
            throw new InvalidArgumentException(
                'Hora de entrada igual a hora de saida. Informe o horario real de saida; '
                . 'se a hora extra passou da meia-noite, a saida fica menor que a entrada.'
            );
        }
        if ($difference < 0) {
            $difference += 86400;
        }
        $minutes = intdiv($difference, 60);
        if ($minutes < 1) {
            throw new InvalidArgumentException('Intervalo de horas extras invalido.');
        }
        return $minutes;
    }

    private function positiveInt($value, bool $nullable = false): ?int {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Identificador invalido.');
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new InvalidArgumentException('Identificador invalido.');
        }
        return (int)$result;
    }

    private function today(): string {
        return $this->today ?? date('Y-m-d');
    }

    private function team($value, bool $nullable = false): ?string {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        $team = strtolower(trim((string)$value));
        if (!in_array($team, ['n1', 'n2'], true)) {
            throw new InvalidArgumentException('Equipe invalida.');
        }
        return $team;
    }

    private function requiredText($value, int $max, string $label): string {
        $text = $this->text($value, $max);
        if ($text === '') {
            throw new InvalidArgumentException("{$label} e obrigatorio.");
        }
        return $text;
    }

    private function text($value, int $max, bool $allowEmpty = false): string {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Texto invalido.');
        }
        $text = trim((string)$value);
        if (!$allowEmpty && $text === '') {
            return '';
        }
        if (self::stringLength($text) > $max) {
            throw new InvalidArgumentException('Texto excede o limite permitido.');
        }
        return $text;
    }

    private static function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function isList(array $items): bool {
        $expected = 0;
        foreach ($items as $key => $_value) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private static function monthName(int $month): string {
        return [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Marco', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ][$month];
    }

    private function csvRow($handle, array $row): void {
        fputcsv($handle, array_map(static function ($value) {
            return csv_neutralize_cell((string)$value);
        }, $row), ';');
    }
}
