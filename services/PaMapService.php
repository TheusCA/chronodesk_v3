<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/OperationalService.php';

final class PaMapService {
    public const MAX_PAYLOAD_BYTES = 32768;
    private const STATUSES = ['onsite', 'hybrid', 'remote', 'free', 'critical', 'unavailable'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? get_db_connection();
    }

    public function list(array $filters): array {
        $date = $this->date($filters['date'] ?? date('Y-m-d'), 'Data');
        $team = $this->team($filters['team'] ?? null, true);
        $sql = 'SELECT id, pa_number, work_date, shift_label, employee_id,
                       employee_name, employee_login, team, status, schedule_status,
                       schedule_label, notes, created_by, updated_by, created_at, updated_at
                FROM portal_pa_map
                WHERE record_status = "active" AND work_date = :work_date';
        $params = [':work_date' => $date];
        if ($team) {
            $sql .= ' AND team = :team';
            $params[':team'] = $team;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY pa_number, shift_label, employee_name');
        $stmt->execute($params);

        return [
            'date' => $date,
            'team' => $team,
            'items' => $stmt->fetchAll(),
            'employees' => $this->eligibleEmployees($date, $team),
        ];
    }

    public function eligibleEmployees(string $date, ?string $team = null): array {
        $date = $this->date($date, 'Data');
        $team = $this->team($team, true);
        $sql = 'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login
                FROM funcionarios
                WHERE ativo = 1 AND LOWER(equipe) IN ("n1", "n2")';
        $params = [];
        if ($team) {
            $sql .= ' AND LOWER(equipe) = :team';
            $params[':team'] = $team;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY nome');
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $schedule = $this->scheduleForEmployee((int)$row['id'], $date);
            $items[] = $row + [
                'schedule_status' => $schedule['status'],
                'schedule_label' => $schedule['label'],
                'schedule_source' => $schedule['source'],
            ];
        }
        return $items;
    }

    public function save(array $data, string $actor): array {
        return $this->atomic(function () use ($data, $actor): array {
            $id = $this->positiveInt($data['id'] ?? null, true);
            $paNumber = $this->paNumber($data['pa_number'] ?? null);
            $date = $this->date($data['work_date'] ?? date('Y-m-d'), 'Data');
            $shiftLabel = $this->shiftLabel($data['shift_label'] ?? '');
            $employee = $this->employee($data['employee_id'] ?? null);
            $status = $this->status($data['status'] ?? 'onsite');
            $notes = $this->text($data['notes'] ?? '', 1000, true);
            $schedule = $this->scheduleForEmployee((int)$employee['id'], $date);
            $warnings = $this->scheduleWarnings($schedule);

            if ($schedule['block']) {
                throw new DomainException($schedule['message']);
            }
            if (
                $schedule['status'] === 'remote'
                && empty($data['confirm_remote_allocation'])
            ) {
                throw new DomainException('Colaborador marcado como remoto nesta data. Confirme para alocar mesmo assim.');
            }

            $this->assertNoConflict($paNumber, $date, $shiftLabel, (int)$employee['id'], $id);
            $params = [
                ':pa_number' => $paNumber,
                ':work_date' => $date,
                ':shift_label' => $shiftLabel,
                ':employee_id' => (int)$employee['id'],
                ':employee_name' => $employee['name'],
                ':employee_login' => $employee['ad_login'],
                ':team' => $employee['team'],
                ':status' => $status,
                ':schedule_status' => $schedule['status'],
                ':schedule_label' => $schedule['label'],
                ':notes' => $notes ?: null,
                ':updated_by' => $actor,
            ];

            if ($id) {
                $stmt = $this->pdo->prepare(
                    'UPDATE portal_pa_map
                     SET pa_number = :pa_number,
                         work_date = :work_date,
                         shift_label = :shift_label,
                         employee_id = :employee_id,
                         employee_name = :employee_name,
                         employee_login = :employee_login,
                         team = :team,
                         status = :status,
                         schedule_status = :schedule_status,
                         schedule_label = :schedule_label,
                         notes = :notes,
                         updated_by = :updated_by
                     WHERE id = :id AND record_status = "active"'
                );
                $stmt->execute($params + [':id' => $id]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('Alocacao de PA nao encontrada.');
                }
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_pa_map
                        (pa_number, work_date, shift_label, employee_id, employee_name,
                         employee_login, team, status, schedule_status, schedule_label,
                         notes, created_by, updated_by)
                     VALUES
                        (:pa_number, :work_date, :shift_label, :employee_id, :employee_name,
                         :employee_login, :team, :status, :schedule_status, :schedule_label,
                         :notes, :created_by, :updated_by)'
                );
                $stmt->execute($params + [':created_by' => $actor]);
                $id = (int)$this->pdo->lastInsertId();
            }

            return ['id' => $id, 'warnings' => $warnings];
        });
    }

    public function remove(int $id, string $actor): void {
        $id = $this->positiveInt($id);
        $stmt = $this->pdo->prepare(
            'UPDATE portal_pa_map
             SET record_status = "deleted", deleted_at = CURRENT_TIMESTAMP, updated_by = :updated_by
             WHERE id = :id AND record_status = "active"'
        );
        $stmt->execute([':updated_by' => $actor, ':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Alocacao de PA nao encontrada.');
        }
    }

    private function assertNoConflict(string $paNumber, string $date, string $shiftLabel, int $employeeId, ?int $id): void {
        $params = [
            ':pa_number' => $paNumber,
            ':work_date' => $date,
            ':shift_label' => $shiftLabel,
            ':id' => $id ?: 0,
        ];
        $stmt = $this->pdo->prepare(
            'SELECT id FROM portal_pa_map
             WHERE record_status = "active"
               AND pa_number = :pa_number
               AND work_date = :work_date
               AND shift_label = :shift_label
               AND id <> :id
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Este PA ja possui colaborador nesta data e turno.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM portal_pa_map
             WHERE record_status = "active"
               AND employee_id = :employee_id
               AND work_date = :work_date
               AND shift_label = :shift_label
               AND id <> :id
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':work_date' => $date,
            ':shift_label' => $shiftLabel,
            ':id' => $id ?: 0,
        ]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Este colaborador ja esta alocado em outro PA nesta data e turno.');
        }
    }

    private function employee($id): array {
        $employeeId = $this->positiveInt($id);
        $stmt = $this->pdo->prepare(
            'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login
             FROM funcionarios
             WHERE id = :id AND ativo = 1
             LIMIT 1'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        if (!$employee || !in_array($employee['team'], ['n1', 'n2'], true)) {
            throw new InvalidArgumentException('Colaborador ativo invalido.');
        }
        return $employee;
    }

    private function scheduleForEmployee(int $employeeId, string $date): array {
        $exception = $this->scheduleException($employeeId, $date);
        if ($exception) {
            $type = $exception['exception_type'];
            if (in_array($type, ['vacation', 'leave', 'absence', 'day_off'], true)) {
                return [
                    'status' => $type,
                    'label' => $type,
                    'source' => 'exception',
                    'block' => true,
                    'message' => 'Colaborador com ausencia/ferias nesta data.',
                ];
            }
            return [
                'status' => in_array($type, ['onsite', 'training', 'oncall'], true) ? 'onsite' : 'remote',
                'label' => $type,
                'source' => 'exception',
                'block' => false,
                'message' => null,
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT rule_type
             FROM portal_schedule_rules
             WHERE employee_id = :employee_id
               AND effective_from <= :work_date
               AND (effective_until IS NULL OR effective_until >= :work_date)
             ORDER BY effective_from DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([':employee_id' => $employeeId, ':work_date' => $date]);
        $rule = $stmt->fetchColumn();
        if (!$rule) {
            return [
                'status' => 'no_schedule',
                'label' => 'Sem escala confirmada',
                'source' => 'none',
                'block' => false,
                'message' => null,
            ];
        }
        $presence = OperationalService::presenceForRule((string)$rule, $date);
        return [
            'status' => $presence,
            'label' => $presence,
            'source' => 'rule',
            'block' => false,
            'message' => null,
        ];
    }

    private function scheduleException(int $employeeId, string $date): ?array {
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
        if ($schedule['status'] === 'remote') {
            return ['Colaborador marcado como remoto nesta data.'];
        }
        if ($schedule['status'] === 'no_schedule') {
            return ['Colaborador sem escala confirmada nesta data.'];
        }
        return [];
    }

    private function paNumber($value): string {
        $text = $this->text($value, 20);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{0,19}$/', $text)) {
            throw new InvalidArgumentException('PA invalido.');
        }
        return strtoupper(trim($text));
    }

    private function status($value): string {
        $status = strtolower(trim((string)$value));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Status do PA invalido.');
        }
        return $status;
    }

    private function shiftLabel($value): string {
        $text = $this->text($value, 40, true);
        if ($text === '') {
            return 'integral';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 .:_-]{0,39}$/', $text)) {
            throw new InvalidArgumentException('Turno invalido.');
        }
        return strtolower($text);
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
