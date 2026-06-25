<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/OperationalService.php';

final class PaMapService {
    public const MAX_PAYLOAD_BYTES = 32768;

    private const DEFAULT_PA_NUMBERS = [
        '1732', '1731', '1730', '1729', '1728', '1727', '1726', '1725',
        '1724', '1723', '1722', '1721', '1720', '1719', '1718', '1717',
    ];
    private const RULE_TYPES = [
        'even_days', 'odd_days', 'always_onsite', 'always_remote', 'undefined', 'fixed_weekdays',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? get_db_connection();
    }

    public function list(array $filters): array {
        $date = $this->date($filters['date'] ?? date('Y-m-d'), 'Data');
        $team = $this->team($filters['team'] ?? null, true);
        $inventory = $this->inventory();
        $assignments = $this->assignmentsForMap($date, $team);
        $legacy = $this->legacySpecificDateAssignments($date, $team);
        $byPa = [];

        foreach (array_merge($assignments, $legacy) as $assignment) {
            $byPa[$assignment['pa_number']][] = $assignment;
        }

        $pas = [];
        foreach ($inventory as $pa) {
            $pas[] = $pa + [
                'assignments' => $byPa[$pa['pa_number']] ?? [],
            ];
        }

        return [
            'date' => $date,
            'team' => $team,
            'pas' => $pas,
            'items' => array_merge($assignments, $legacy),
            'employees' => $this->eligibleEmployees($date, $team),
        ];
    }

    public function eligibleEmployees(string $date, ?string $team = null): array {
        $date = $this->date($date, 'Data');
        $team = $this->team($team, true);
        $sql = 'SELECT id, nome AS name, equipe AS team, ad_login
                FROM funcionarios
                WHERE ativo = 1';
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
            $schedule = $this->scheduleForEmployee((int)$row['id'], $date);
            $row['team'] = $normalizedTeam;
            $items[] = $row + [
                'schedule_rule_type' => $schedule['rule_type'],
                'schedule_rule_label' => $this->ruleLabel($schedule['rule_type']),
                'schedule_status' => $schedule['status'],
                'schedule_label' => $schedule['label'],
                'schedule_source' => $schedule['source'],
            ];
        }
        return $items;
    }

    public function save(array $data, string $actor): array {
        return $this->atomic(function () use ($data, $actor): array {
            if (!$this->tableExists('portal_pa_assignments')) {
                throw new DomainException('Tabela de vinculos do Mapa de PA ainda nao foi criada.');
            }
            $id = $this->positiveInt($data['id'] ?? null, true);
            $paNumber = $this->paNumber($data['pa_number'] ?? null);
            $employee = $this->employee($data['employee_id'] ?? null);
            $validFrom = $this->date($data['valid_from'] ?? $data['work_date'] ?? date('Y-m-d'), 'Inicio da validade');
            $validUntil = $this->nullableDate($data['valid_until'] ?? null, 'Fim da validade');
            if ($validUntil !== null && $validUntil < $validFrom) {
                throw new InvalidArgumentException('Fim da validade nao pode ser anterior ao inicio.');
            }
            $notes = $this->text($data['notes'] ?? '', 1000, true);
            $schedule = $this->scheduleForEmployee((int)$employee['id'], $validFrom);
            $warnings = $this->scheduleWarnings($schedule);

            if ($schedule['block']) {
                throw new DomainException($schedule['message']);
            }
            if ($schedule['rule_type'] === 'always_remote' && empty($data['confirm_remote_allocation'])) {
                throw new DomainException('Colaborador marcado como remoto nesta data. Confirme para alocar mesmo assim.');
            }

            $this->assertNoConflict(
                $paNumber,
                (int)$employee['id'],
                $schedule['rule_type'],
                $validFrom,
                $validUntil,
                $id
            );

            $params = [
                ':pa_number' => $paNumber,
                ':employee_id' => (int)$employee['id'],
                ':employee_name' => $employee['name'],
                ':employee_login' => $employee['ad_login'],
                ':team' => $employee['team'],
                ':schedule_rule_type' => $schedule['rule_type'],
                ':schedule_rule_config' => $schedule['rule_config'] ?? null,
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
                    throw new DomainException('Vinculo de PA nao encontrado.');
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

            return ['id' => $id, 'warnings' => $warnings];
        });
    }

    public function remove(int $id, string $actor): void {
        if (!$this->tableExists('portal_pa_assignments')) {
            throw new DomainException('Tabela de vinculos do Mapa de PA ainda nao foi criada.');
        }
        $id = $this->positiveInt($id);
        $stmt = $this->pdo->prepare(
            'UPDATE portal_pa_assignments
             SET active = 0, deleted_at = CURRENT_TIMESTAMP, updated_by = :updated_by
             WHERE id = :id AND active = 1'
        );
        $stmt->execute([':updated_by' => $actor, ':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Vinculo de PA nao encontrado.');
        }
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

    private function assignmentsForMap(string $date, ?string $team): array {
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
            ':date_from' => $date,
            ':date_until' => $date,
        ];
        if ($team) {
            $sql .= ' AND team = :team';
            $params[':team'] = $team;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY pa_number, employee_name');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->assignmentView($row, $date, 'assignment');
        }
        return $items;
    }

    private function legacySpecificDateAssignments(string $date, ?string $team): array {
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
        $stmt = $this->pdo->prepare($sql . ' ORDER BY pa_number, employee_name');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $ruleType = $this->scheduleForEmployee((int)$row['employee_id'], $date)['rule_type'];
            $items[] = $this->assignmentView([
                'id' => 'legacy-' . $row['id'],
                'pa_number' => $row['pa_number'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'employee_login' => $row['employee_login'],
                'team' => $row['team'],
                'schedule_rule_type' => $ruleType,
                'schedule_rule_config' => $this->scheduleForEmployee((int)$row['employee_id'], $date)['rule_config'] ?? null,
                'valid_from' => $date,
                'valid_until' => $date,
                'notes' => $row['notes'],
                'created_by' => $row['created_by'],
                'updated_by' => $row['updated_by'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ], $date, 'legacy');
        }
        return $items;
    }

    private function assignmentView(array $row, string $date, string $source): array {
        $ruleType = $this->ruleType($row['schedule_rule_type'] ?? 'undefined');
        $weekdays = $ruleType === 'fixed_weekdays'
            ? OperationalService::scheduleRuleConfigWeekdays($row['schedule_rule_config'] ?? null)
            : [];
        $activeOnDate = $this->ruleActiveOnDate($ruleType, $date, $weekdays);
        return $row + [
            'source' => $source,
            'schedule_rule_type' => $ruleType,
            'schedule_rule_label' => $this->ruleLabel($ruleType, $weekdays),
            'schedule_weekdays' => $weekdays,
            'active_on_date' => $activeOnDate,
            'presence_status' => $activeOnDate ? 'onsite' : (in_array($ruleType, ['always_remote', 'fixed_weekdays'], true) ? 'remote' : 'offsite'),
        ];
    }

    private function assertNoConflict(
        string $paNumber,
        int $employeeId,
        string $ruleType,
        string $validFrom,
        ?string $validUntil,
        ?int $id
    ): void {
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
            throw new DomainException('Este colaborador ja possui vinculo ativo em outro PA no periodo.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, schedule_rule_type, employee_name
             FROM portal_pa_assignments
             WHERE active = 1
               AND pa_number = :pa_number
               AND id <> :id
               AND valid_from <= :new_until
               AND (valid_until IS NULL OR valid_until >= :new_from)
             FOR UPDATE'
        );
        $stmt->execute([
            ':pa_number' => $paNumber,
            ':id' => $id ?: 0,
            ':new_until' => $rangeUntil,
            ':new_from' => $validFrom,
        ]);
        foreach ($stmt->fetchAll() as $existing) {
            if ($this->rulesConflict($ruleType, $this->ruleType($existing['schedule_rule_type']))) {
                throw new DomainException('Conflito de escala no PA com ' . $existing['employee_name'] . '.');
            }
        }
    }

    private function rulesConflict(string $a, string $b): bool {
        $a = $this->ruleType($a);
        $b = $this->ruleType($b);
        if ($a === 'always_remote' || $b === 'always_remote') {
            return false;
        }
        if ($a === 'even_days' && $b === 'odd_days') {
            return false;
        }
        if ($a === 'odd_days' && $b === 'even_days') {
            return false;
        }
        return true;
    }

    private function employee($id): array {
        $employeeId = $this->positiveInt($id);
        $stmt = $this->pdo->prepare(
            'SELECT id, nome AS name, equipe AS team, ad_login
             FROM funcionarios
             WHERE id = :id AND ativo = 1
             LIMIT 1'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        if (!$employee) {
            throw new InvalidArgumentException('Colaborador ativo invalido.');
        }
        $normalizedTeam = $this->normalizeTeam($employee['team'] ?? null);
        if ($normalizedTeam === null) {
            throw new InvalidArgumentException('Colaborador ativo invalido.');
        }
        $employee['team'] = $normalizedTeam;
        return $employee;
    }

    private function scheduleForEmployee(int $employeeId, string $date): array {
        $exception = $this->scheduleException($employeeId, $date);
        if ($exception) {
            $type = $exception['exception_type'];
            if (in_array($type, ['vacation', 'leave', 'absence', 'day_off'], true)) {
                return [
                    'rule_type' => 'undefined',
                    'rule_config' => null,
                    'weekdays' => [],
                    'status' => $type,
                    'label' => $type,
                    'source' => 'exception',
                    'block' => true,
                    'message' => 'Colaborador com ausencia/ferias nesta data.',
                ];
            }
        }

        if (!$this->tableExists('portal_schedule_rules')) {
            return [
                'rule_type' => 'undefined',
                'rule_config' => null,
                'weekdays' => [],
                'status' => 'no_schedule',
                'label' => 'Sem escala definida',
                'source' => 'none',
                'block' => false,
                'message' => null,
            ];
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
            return [
                'rule_type' => 'undefined',
                'rule_config' => null,
                'weekdays' => [],
                'status' => 'no_schedule',
                'label' => 'Sem escala definida',
                'source' => 'none',
                'block' => false,
                'message' => null,
            ];
        }
        $ruleType = $this->ruleType((string)$rule['rule_type']);
        $weekdays = $ruleType === 'fixed_weekdays'
            ? OperationalService::scheduleRuleConfigWeekdays($rule['rule_config'] ?? null)
            : [];
        $presence = $ruleType === 'undefined'
            ? 'no_schedule'
            : OperationalService::presenceForRule($ruleType, $date, $weekdays);
        return [
            'rule_type' => $ruleType,
            'rule_config' => $ruleType === 'fixed_weekdays' && $weekdays !== []
                ? json_encode(['weekdays' => $weekdays], JSON_UNESCAPED_SLASHES)
                : null,
            'weekdays' => $weekdays,
            'status' => $presence,
            'label' => $this->ruleLabel($ruleType, $weekdays),
            'source' => 'rule',
            'block' => false,
            'message' => null,
        ];
    }

    private function scheduleException(int $employeeId, string $date): ?array {
        if (!$this->tableExists('portal_schedule_exceptions')) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT exception_type
             FROM portal_schedule_exceptions
             WHERE employee_id = :employee_id AND exception_date = :work_date
             LIMIT 1'
        );
        $stmt->execute([':employee_id' => $employeeId, ':work_date' => $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function scheduleWarnings(array $schedule): array {
        if ($schedule['rule_type'] === 'always_remote') {
            return ['Colaborador marcado como remoto nesta data.'];
        }
        if ($schedule['rule_type'] === 'undefined') {
            return ['Colaborador sem escala definida.'];
        }
        return [];
    }

    private function ruleActiveOnDate(string $ruleType, string $date, array $weekdays = []): bool {
        $ruleType = $this->ruleType($ruleType);
        if ($ruleType === 'always_onsite') {
            return true;
        }
        if ($ruleType === 'always_remote' || $ruleType === 'undefined') {
            return false;
        }
        $day = (int)substr($date, -2);
        if ($ruleType === 'even_days') {
            return $day % 2 === 0;
        }
        if ($ruleType === 'odd_days') {
            return $day % 2 === 1;
        }
        if ($ruleType === 'fixed_weekdays') {
            return OperationalService::presenceForRule($ruleType, $date, $weekdays) === 'onsite';
        }
        return false;
    }

    private function ruleLabel(string $ruleType, array $weekdays = []): string {
        $labels = [
            'even_days' => 'Dias pares',
            'odd_days' => 'Dias impares',
            'always_onsite' => 'Sempre presencial',
            'always_remote' => 'Remoto',
            'undefined' => 'Sem escala',
            'fixed_weekdays' => 'Presencial: ' . OperationalService::weekdayListLabel($weekdays),
        ];
        return $labels[$this->ruleType($ruleType)];
    }

    private function ruleType($value): string {
        $ruleType = strtolower(trim((string)$value));
        return in_array($ruleType, self::RULE_TYPES, true) ? $ruleType : 'undefined';
    }

    private function paNumber($value): string {
        $text = $this->text($value, 20);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{0,19}$/', $text)) {
            throw new InvalidArgumentException('PA invalido.');
        }
        return strtoupper(trim($text));
    }

    private function date($value, string $label): string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException("{$label} invalida.");
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
        $team = strtolower(trim((string)$value));
        if (!in_array($team, ['n1', 'n2', 'lideranca'], true)) {
            throw new InvalidArgumentException('Equipe invalida.');
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
        if ($team === 'n1' || $team === 'n2') {
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
            throw new InvalidArgumentException('Identificador invalido.');
        }
        return (int)$result;
    }

    private function text($value, int $max, bool $allowEmpty = false): string {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Texto invalido.');
        }
        $text = trim((string)$value);
        if ($text === '' && !$allowEmpty) {
            throw new InvalidArgumentException('Texto obrigatorio.');
        }
        if (self::stringLength($text) > $max) {
            throw new InvalidArgumentException('Texto excede o limite permitido.');
        }
        return $text;
    }

    private function tableExists(string $table): bool {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
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
