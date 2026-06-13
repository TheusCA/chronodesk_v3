<?php
require_once __DIR__ . '/../db.php';

class ApprovalRequestService {
    public function create(array $request): void {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare(
                'INSERT INTO pause_approval_requests
                    (employee_id, employee_name, team, reason, observation, requested_at, status)
                 VALUES
                    (:employee_id, :employee_name, :team, :reason, :observation, :requested_at, :status)'
            );
            $stmt->execute([
                ':employee_id' => (int)$request['employee_id'],
                ':employee_name' => substr((string)$request['employee_name'], 0, 100),
                ':team' => substr((string)$request['team'], 0, 30),
                ':reason' => substr((string)$request['reason'], 0, 50),
                ':observation' => substr((string)($request['observation'] ?? ''), 0, 1000),
                ':requested_at' => $request['requested_at'] instanceof DateTimeInterface
                    ? $request['requested_at']->format('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s'),
                ':status' => 'pending',
            ]);
        } catch (Throwable $e) {
            error_log('[APPROVAL_HISTORY] Falha ao registrar solicitação: ' . $e->getMessage());
        }
    }

    public function decide(int $employeeId, string $status, string $decidedBy): void {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Status de aprovação inválido.');
        }
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare(
                'UPDATE pause_approval_requests
                 SET status = :status, decided_at = CURRENT_TIMESTAMP, decided_by = :decided_by
                 WHERE employee_id = :employee_id AND status = :pending
                 ORDER BY requested_at DESC
                 LIMIT 1'
            );
            $stmt->execute([
                ':status' => $status,
                ':decided_by' => substr($decidedBy, 0, 100),
                ':employee_id' => $employeeId,
                ':pending' => 'pending',
            ]);
        } catch (Throwable $e) {
            error_log('[APPROVAL_HISTORY] Falha ao registrar decisão: ' . $e->getMessage());
        }
    }

    public function listRecent(int $limit = 500): array {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare(
                'SELECT id, employee_id, employee_name, team, reason, observation,
                        requested_at, status, decided_at, decided_by
                 FROM pause_approval_requests
                 ORDER BY requested_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', max(1, min($limit, 1000)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[APPROVAL_HISTORY] Histórico indisponível: ' . $e->getMessage());
            return [];
        }
    }
}
