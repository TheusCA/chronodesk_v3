<?php
require_once __DIR__ . '/../db.php';

final class SharePointSyncService {
    public function enqueue(
        PDO $pdo,
        string $recordType,
        int $recordId,
        array $payload,
        string $createdBy
    ): void {
        if (!in_array($recordType, ['overtime', 'time_adjustment', 'schedule', 'oncall'], true)) {
            throw new InvalidArgumentException('Tipo de sincronizacao invalido.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO portal_sync_queue
                (record_type, record_id, operation, status, payload_json, created_by)
             VALUES
                (:record_type, :record_id, "upsert", "pending", :payload_json, :created_by)
             ON DUPLICATE KEY UPDATE
                payload_json = VALUES(payload_json),
                created_by = VALUES(created_by),
                attempts = 0,
                next_attempt_at = NULL,
                last_attempt_at = NULL,
                last_error = NULL,
                updated_at = CURRENT_TIMESTAMP'
        );
        try {
            $stmt->execute([
                ':record_type' => $recordType,
                ':record_id' => $recordId,
                ':payload_json' => json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                ':created_by' => $createdBy,
            ]);
        } catch (Throwable $error) {
            error_log(
                '[SYNC_QUEUE_ERROR] Falha ao enfileirar '
                . $recordType . ' ID ' . $recordId . ': ' . $error->getMessage()
            );
            throw $error;
        }
    }

    public function processPending(int $limit = 20): array {
        $message = 'Sincronizacao com Microsoft Graph ainda nao configurada. '
            . 'A fila MySQL permanece como fonte de reprocessamento.';
        if (function_exists('audit_log')) {
            audit_log('SYNC_PROCESS_BLOCKED', $message, 'WARNING');
        }
        error_log('[SYNC_PROCESS_BLOCKED] ' . $message);
        throw new LogicException($message);
    }
}
