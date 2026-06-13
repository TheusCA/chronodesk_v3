<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/SharePointSyncService.php';

final class OperationalService {
    public const MAX_IMPORT_ROWS = 500;
    public const MAX_IMPORT_COLUMNS = 6;
    public const MAX_IMPORT_CELL_CHARS = 500;
    public const MAX_IMPORT_PAYLOAD_BYTES = 1048576;
    public const ALLOWED_IMPORT_HEADERS = [
        'id', 'employee_id',
        'login_ad', 'ad_login', 'email',
        'nome', 'name',
        'equipe', 'team',
        'regra', 'rule_type',
    ];

    private PDO $pdo;
    private SharePointSyncService $sync;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->sync = new SharePointSyncService();
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

    public static function presenceForRule(string $rule, string $date): string {
        $day = (int)self::dateValue($date, 'Data')->format('d');
        return match ($rule) {
            'always_onsite' => 'onsite',
            'always_remote', 'undefined' => 'remote',
            'even_days' => $day % 2 === 0 ? 'onsite' : 'remote',
            'odd_days' => $day % 2 === 1 ? 'onsite' : 'remote',
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
                    WHERE DATE(starts_at) BETWEEN :date_from AND :date_to';
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
                    'title' => $row['employee_name'],
                    'description' => $row['label'],
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
        $rules = $this->scheduleRules($team, $employeeId);
        $exceptions = $this->scheduleExceptions($from, $to, $team, $employeeId);
        return [
            'period' => ['from' => $from, 'to' => $to],
            'rules' => $rules,
            'exceptions' => $exceptions,
            'generated' => $this->generatedSchedule($from, $to, $team, $employeeId, $rules, $exceptions),
        ];
    }

    public function saveScheduleRule(array $data, string $actor): int {
        return $this->atomic(fn(): int => $this->saveScheduleRuleUnsafe($data, $actor));
    }

    private function saveScheduleRuleUnsafe(array $data, string $actor): int {
        $employee = $this->employee($data['employee_id'] ?? null);
        $rule = (string)($data['rule_type'] ?? '');
        if (!in_array($rule, ['even_days', 'odd_days', 'always_remote', 'always_onsite', 'undefined'], true)) {
            throw new InvalidArgumentException('Regra de escala invalida.');
        }
        $effectiveFrom = self::dateValue($data['effective_from'] ?? date('Y-m-d'), 'Inicio da vigencia');
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_schedule_rules
                (employee_id, employee_name, ad_login, team, rule_type, effective_from, created_by, updated_by)
             VALUES
                (:employee_id, :employee_name, :ad_login, :team, :rule_type, :effective_from, :created_by, :updated_by)
             ON DUPLICATE KEY UPDATE
                employee_name = VALUES(employee_name),
                ad_login = VALUES(ad_login),
                team = VALUES(team),
                rule_type = VALUES(rule_type),
                effective_from = VALUES(effective_from),
                effective_until = NULL,
                updated_by = VALUES(updated_by)'
        );
        $stmt->execute([
            ':employee_id' => $employee['id'],
            ':employee_name' => $employee['name'],
            ':ad_login' => $employee['ad_login'],
            ':team' => $employee['team'],
            ':rule_type' => $rule,
            ':effective_from' => $effectiveFrom->format('Y-m-d'),
            ':created_by' => $actor,
            ':updated_by' => $actor,
        ]);
        $id = $this->scheduleRuleId((int)$employee['id']);
        $this->sync->enqueue($this->pdo, 'schedule', $id, [
            'employee_id' => (int)$employee['id'],
            'employee_name' => $employee['name'],
            'team' => $employee['team'],
            'rule_type' => $rule,
            'effective_from' => $effectiveFrom->format('Y-m-d'),
        ], $actor);
        return $id;
    }

    public function saveScheduleException(array $data, string $actor): int {
        return $this->atomic(fn(): int => $this->saveScheduleExceptionUnsafe($data, $actor));
    }

    private function saveScheduleExceptionUnsafe(array $data, string $actor): int {
        $employee = $this->employee($data['employee_id'] ?? null);
        $date = self::dateValue($data['exception_date'] ?? null, 'Data');
        $type = (string)($data['exception_type'] ?? '');
        $allowed = ['remote', 'onsite', 'day_off', 'absence', 'training', 'oncall', 'vacation', 'leave'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Tipo de excecao invalido.');
        }
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
        if (!array_is_list($rows)) {
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
            if (!is_array($row) || $row === [] || array_is_list($row)) {
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
            if (!$employee) {
                $errors[] = 'Colaborador nao encontrado.';
            }
            if (!$rule) {
                $errors[] = 'Regra invalida.';
            }
            if ($employee && $team && $team !== $employee['team']) {
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
                'rule_type' => $rule,
                'valid' => count($errors) === 0,
                'errors' => $errors,
            ];
        }
        return [
            'rows' => $result,
            'valid_count' => count(array_filter($result, static fn(array $row): bool => $row['valid'])),
            'invalid_count' => count(array_filter($result, static fn(array $row): bool => !$row['valid'])),
        ];
    }

    public function confirmScheduleImport(array $rows, string $actor): int {
        $preview = $this->previewScheduleImport($rows);
        if ($preview['invalid_count'] > 0 || $preview['valid_count'] === 0) {
            throw new InvalidArgumentException('A importacao possui linhas invalidas.');
        }
        return $this->atomic(function () use ($preview, $actor): int {
            foreach ($preview['rows'] as $row) {
                $this->saveScheduleRuleUnsafe([
                    'employee_id' => $row['employee_id'],
                    'rule_type' => $row['rule_type'],
                    'effective_from' => date('Y-m-d'),
                ], $actor);
            }
            return $preview['valid_count'];
        });
    }

    public function listOvertime(array $filters, array $actor): array {
        return $this->listWorkflowRecords('portal_overtime_entries', 'work_date', $filters, $actor);
    }

    public function createOvertime(array $data, array $actor): int {
        return $this->atomic(fn(): int => $this->createOvertimeUnsafe($data, $actor));
    }

    private function createOvertimeUnsafe(array $data, array $actor): int {
        $employee = $this->targetEmployee($data, $actor);
        $date = self::dateValue($data['work_date'] ?? null, 'Data');
        $start = $this->timeValue($data['start_time'] ?? null, 'Hora inicial');
        $end = $this->timeValue($data['end_time'] ?? null, 'Hora final');
        $minutes = $this->minutesBetween($start, $end);
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
            $name = $row['employee_name'];
            $rowTeam = strtoupper($row['team']);
            $byEmployee[$name]['overtime_minutes'] = ($byEmployee[$name]['overtime_minutes'] ?? 0) + (int)$row['total_minutes'];
            $byTeam[$rowTeam]['overtime_minutes'] = ($byTeam[$rowTeam]['overtime_minutes'] ?? 0) + (int)$row['total_minutes'];
        }
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
                'overtime_minutes' => array_sum(array_map(static fn(array $row): int => (int)$row['total_minutes'], $overtime)),
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
        $filename = 'chronodesk_' . $report['competency']['key'] . '.csv';
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

    private function listWorkflowRecords(string $table, string $dateColumn, array $filters, array $actor): array {
        [$from, $to] = $this->period($filters, 366);
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
        if ($status) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        $stmt = $this->pdo->prepare($sql . " ORDER BY {$dateColumn} DESC, id DESC LIMIT 500");
        $stmt->execute($params);
        return $stmt->fetchAll();
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
            if (strcasecmp((string)$record['created_by'], $actor['username']) === 0) {
                throw new DomainException('Nao e permitido aprovar o proprio lancamento.');
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
        $employeeId = $this->positiveInt($id);
        $stmt = $this->pdo->prepare(
            'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login
             FROM funcionarios WHERE id = :id AND ativo = 1'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        if (!$employee || !in_array($employee['team'], ['n1', 'n2'], true)) {
            throw new InvalidArgumentException('Colaborador ativo invalido.');
        }
        return $employee;
    }

    private function findEmployeeForImport(array $row): ?array {
        $conditions = [];
        $params = [];
        if (!empty($row['id']) || !empty($row['employee_id'])) {
            $conditions[] = 'id = :id';
            $params[':id'] = (int)($row['id'] ?? $row['employee_id']);
        }
        $login = strtolower(trim((string)($row['login_ad'] ?? $row['ad_login'] ?? $row['email'] ?? '')));
        if (str_contains($login, '@')) {
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
        return count($rows) === 1 ? $rows[0] : null;
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

    private function scheduleRules(?string $team, ?int $employeeId): array {
        $sql = 'SELECT id, employee_id, employee_name, ad_login, team, rule_type,
                       effective_from, effective_until, created_by, updated_by,
                       created_at, updated_at
                FROM portal_schedule_rules WHERE 1 = 1';
        $params = [];
        $this->appendEmployeeTeamFilters($sql, $params, $team, $employeeId);
        $stmt = $this->pdo->prepare($sql . ' ORDER BY employee_name');
        $stmt->execute($params);
        return $stmt->fetchAll();
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
        $items = [];
        $start = self::dateValue($from, 'Inicio');
        $end = self::dateValue($to, 'Fim');
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            foreach ($rules as $rule) {
                if ($date->format('Y-m-d') < $rule['effective_from']) {
                    continue;
                }
                if ($rule['effective_until'] && $date->format('Y-m-d') > $rule['effective_until']) {
                    continue;
                }
                $key = $rule['employee_id'] . ':' . $date->format('Y-m-d');
                $exception = $exceptionMap[$key] ?? null;
                if ($exception) {
                    $presence = in_array($exception['exception_type'], ['onsite', 'oncall', 'training'], true)
                        ? 'onsite'
                        : 'remote';
                    $label = $exception['exception_type'];
                    $source = 'exception';
                } else {
                    $presence = self::presenceForRule($rule['rule_type'], $date->format('Y-m-d'));
                    $label = $presence;
                    $source = 'rule';
                }
                $items[] = [
                    'employee_id' => (int)$rule['employee_id'],
                    'employee_name' => $rule['employee_name'],
                    'team' => $rule['team'],
                    'date' => $date->format('Y-m-d'),
                    'presence_type' => $presence,
                    'label' => $label,
                    'source' => $source,
                    'rule_type' => $rule['rule_type'],
                ];
            }
        }
        return $items;
    }

    private function scheduleRuleId(int $employeeId): int {
        $stmt = $this->pdo->prepare('SELECT id FROM portal_schedule_rules WHERE employee_id = :employee_id');
        $stmt->execute([':employee_id' => $employeeId]);
        return (int)$stmt->fetchColumn();
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

    private function period(array $filters, int $maxDays): array {
        if (!empty($filters['competency'])) {
            $competency = self::competencyRange((string)$filters['competency']);
            $from = $competency['start'];
            $to = $competency['end'];
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

    private function minutesBetween(string $start, string $end): int {
        $startAt = new DateTimeImmutable('2000-01-01 ' . $start);
        $endAt = new DateTimeImmutable('2000-01-01 ' . $end);
        if ($endAt <= $startAt) {
            $endAt = $endAt->modify('+1 day');
        }
        $minutes = (int)(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);
        if ($minutes < 1 || $minutes > 1440) {
            throw new InvalidArgumentException('Intervalo de horas extras invalido.');
        }
        return $minutes;
    }

    private function positiveInt($value, bool $nullable = false): ?int {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new InvalidArgumentException('Identificador invalido.');
        }
        return (int)$result;
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

    private static function monthName(int $month): string {
        return [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Marco', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ][$month];
    }

    private function csvRow($handle, array $row): void {
        fputcsv($handle, array_map(static function ($value) {
            $text = (string)$value;
            return preg_match('/^[=+\-@]/u', $text) ? "'" . $text : $text;
        }, $row), ';');
    }
}
