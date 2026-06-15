<?php
require_once __DIR__ . '/MailerService.php';
require_once __DIR__ . '/../db.php';

class NotificationService {
    public function notifyMeetingApproval(array $request): array {
        $approvers = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)(getenv('MAIL_APPROVERS') ?: ''))
        )));
        $adminUrl = $this->applicationBaseUrl() . '/app/admin?tab=aprovacoes';
        $requestedAt = $request['requested_at'] instanceof DateTimeInterface
            ? $request['requested_at']->format('d/m/Y H:i:s')
            : date('d/m/Y H:i:s');

        $body = implode("\n", [
            'Uma solicitação de pausa de reunião aguarda aprovação no ChronoDesk.',
            '',
            'Colaborador: ' . ($request['employee_name'] ?? ''),
            'Equipe: ' . strtoupper((string)($request['team'] ?? '')),
            'Motivo: ' . ($request['reason'] ?? 'Reunião'),
            'Observação: ' . (($request['observation'] ?? '') ?: 'Não informada'),
            'Solicitada em: ' . $requestedAt,
            'Status: Pendente',
            '',
            'Acesse o painel autenticado para analisar:',
            $adminUrl,
        ]);

        $this->createInternalNotification(
            'Nova reunião aguardando aprovação',
            ($request['employee_name'] ?? 'Colaborador') . ' solicitou uma pausa de reunião.',
            '/app/admin?tab=aprovacoes'
        );

        $mailer = new MailerService();
        try {
            $result = $mailer->send(
                $approvers,
                'ChronoDesk - pausa de reunião pendente',
                $body
            );
            if ($result['sent']) {
                audit_log(
                    'MAIL_APPROVAL_SENT',
                    'Notificação de aprovação enviada para ' . count($approvers) . ' aprovador(es).',
                    'INFO'
                );
            }
            return $result;
        } catch (Throwable $e) {
            error_log('[MAIL] Falha ao enviar notificação de aprovação.');
            audit_log('MAIL_APPROVAL_FAILURE', 'Falha no envio da notificação de aprovação.', 'WARNING');
            return ['sent' => false, 'skipped' => false, 'message' => 'Falha no envio de e-mail.'];
        }
    }

    private function createInternalNotification(string $title, string $message, string $url): void {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare(
                'INSERT INTO portal_notifications
                    (recipient_role, title, message, type, severity, related_url)
                 VALUES
                    (:role, :title, :message, :type, :severity, :url)'
            );
            foreach (['admin', 'gestor'] as $role) {
                $stmt->execute([
                    ':role' => $role,
                    ':title' => $title,
                    ':message' => $message,
                    ':type' => 'approval',
                    ':severity' => 'warning',
                    ':url' => $url,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[NOTIFICATION] Notificação interna indisponível.');
        }
    }

    private function applicationBaseUrl(): string {
        $configured = function_exists('configured_application_base_url')
            ? configured_application_base_url()
            : null;
        if ($configured !== null) {
            return $configured;
        }

        $base = function_exists('get_base_path') ? get_base_path() : '';
        $origin = function_exists('safe_request_origin')
            ? safe_request_origin()
            : 'http://localhost';
        return $origin . rtrim($base, '/');
    }
}
