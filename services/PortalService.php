<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/integrations/IntegrationRegistry.php';

class PortalStorageException extends RuntimeException {
}

class PortalService {
    private const TABLES = [
        'calendar' => [
            'table' => 'portal_calendar_events',
            'columns' => 'id, title, description, type, starts_at, ends_at, team, related_username, created_by, status, created_at, updated_at',
        ],
        'schedules' => [
            'table' => 'portal_schedules',
            'columns' => 'id, employee_id, employee_name, team, schedule_type, starts_at, ends_at, phone, status, created_by, created_at, updated_at',
        ],
        'absences' => [
            'table' => 'portal_absences',
            'columns' => 'id, employee_id, employee_name, absence_type, starts_on, ends_on, reason, status, approved_by, created_at, updated_at',
        ],
        'overtime' => [
            'table' => 'portal_overtime',
            'columns' => 'id, employee_id, employee_name, work_date, minutes, reason, status, approved_by, created_at, updated_at',
        ],
        'time_corrections' => [
            'table' => 'portal_time_corrections',
            'columns' => 'id, employee_id, employee_name, correction_date, correction_time, reason, justification, status, reviewed_by, created_at, updated_at',
        ],
        'announcements' => [
            'table' => 'portal_announcements',
            'columns' => 'id, title, category, severity, content, audience, is_pinned, created_by, created_at, updated_at',
        ],
        'documents' => [
            'table' => 'portal_documents',
            'columns' => 'id, title, category, document_type, content, tags, author, status, created_at, updated_at',
        ],
    ];

    public function list(string $resource, int $limit = 100): array {
        if (!isset(self::TABLES[$resource])) {
            throw new InvalidArgumentException('Recurso de portal inválido.');
        }
        $limit = max(1, min($limit, 200));
        $definition = self::TABLES[$resource];
        $table = $definition['table'];
        $columns = $definition['columns'];

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT {$columns} FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[PORTAL] Recurso indisponível: ' . $resource);
            throw new PortalStorageException('A base do portal ainda não está disponível.', 0, $e);
        }
    }

    public function schedules(?string $type = null, int $limit = 100): array {
        $allowed = ['turno', 'home_office', 'presencial', 'folga', 'ferias', 'sobreaviso', 'plantao', 'ausencia'];
        if ($type !== null && !in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Tipo de escala inválido.');
        }
        $limit = max(1, min($limit, 200));
        try {
            $pdo = get_db_connection();
            $sql = 'SELECT id, employee_id, employee_name, team, schedule_type, starts_at, ends_at, phone, status, created_by, created_at, updated_at
                    FROM portal_schedules';
            if ($type !== null) {
                $sql .= ' WHERE schedule_type = :type';
            }
            $sql .= ' ORDER BY starts_at DESC, id DESC LIMIT :limit';
            $stmt = $pdo->prepare($sql);
            if ($type !== null) {
                $stmt->bindValue(':type', $type);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[PORTAL] Escalas indisponíveis.');
            throw new PortalStorageException('A base de escalas ainda não está disponível.', 0, $e);
        }
    }

    public function integrations(): array {
        return (new IntegrationRegistry())->statuses();
    }

    public function notifications(string $username, string $role, int $limit = 20): array {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare(
                'SELECT n.id, n.title, n.message, n.type, n.severity, n.related_url,
                        CASE WHEN r.notification_id IS NULL THEN 0 ELSE 1 END AS is_read,
                        n.created_at
                 FROM portal_notifications n
                 LEFT JOIN portal_notification_reads r
                   ON r.notification_id = n.id AND r.username = :read_username
                 WHERE (n.recipient_username IS NULL OR n.recipient_username = :recipient_username)
                   AND (n.recipient_role IS NULL OR n.recipient_role = :recipient_role)
                 ORDER BY n.created_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':read_username', $username);
            $stmt->bindValue(':recipient_username', $username);
            $stmt->bindValue(':recipient_role', $role);
            $stmt->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[PORTAL] Notificações indisponíveis.');
            return [];
        }
    }

    public function markNotificationRead(int $id, string $username, string $role): bool {
        try {
            $pdo = get_db_connection();
            $pdo->beginTransaction();
            $check = $pdo->prepare(
                'SELECT id
                 FROM portal_notifications
                 WHERE id = :id
                   AND (recipient_username IS NULL OR recipient_username = :recipient_username)
                   AND (recipient_role IS NULL OR recipient_role = :recipient_role)
                 FOR UPDATE'
            );
            $check->execute([
                ':id' => $id,
                ':recipient_username' => $username,
                ':recipient_role' => $role,
            ]);
            if (!$check->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }

            $insert = $pdo->prepare(
                'INSERT INTO portal_notification_reads (notification_id, username, read_at)
                 VALUES (:notification_id, :username, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
            );
            $insert->execute([
                ':notification_id' => $id,
                ':username' => $username,
            ]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[PORTAL] Falha ao marcar notificação.');
            return false;
        }
    }
}
