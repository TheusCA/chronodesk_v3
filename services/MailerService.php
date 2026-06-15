<?php

class MailerService {
    private bool $enabled;
    private string $from;
    private string $fromName;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;
    private int $timeout;

    public function __construct() {
        $this->enabled = filter_var(getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $this->from = trim((string)(getenv('MAIL_FROM') ?: ''));
        $this->fromName = $this->sanitizeHeader((string)(getenv('MAIL_FROM_NAME') ?: 'Portal SDK'));
        $this->host = trim((string)(getenv('SMTP_HOST') ?: ''));
        $this->port = max(1, min((int)(getenv('SMTP_PORT') ?: 587), 65535));
        $this->username = (string)(getenv('SMTP_USERNAME') ?: '');
        $this->password = (string)(getenv('SMTP_PASSWORD') ?: '');
        $this->encryption = strtolower(trim((string)(getenv('SMTP_ENCRYPTION') ?: 'tls')));
        $this->timeout = max(2, min((int)(getenv('SMTP_TIMEOUT') ?: 10), 30));
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    public function send(array $recipients, string $subject, string $body): array {
        if (!$this->enabled) {
            return ['sent' => false, 'skipped' => true, 'message' => 'Envio de e-mail desabilitado.'];
        }
        if (!filter_var($this->from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_FROM inválido.');
        }

        $recipients = array_values(array_unique(array_filter(
            array_map('trim', $recipients),
            static fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL)
        )));
        if (!$recipients) {
            throw new RuntimeException('Nenhum destinatário de e-mail válido configurado.');
        }

        $subject = $this->sanitizeHeader($subject);
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if ($this->host !== '') {
            $this->sendSmtp($recipients, $subject, $body);
        } else {
            $headers = [
                'From: ' . $this->formatAddress($this->from, $this->fromName),
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: Portal SDK',
            ];
            $sent = @mail(
                implode(', ', $recipients),
                $this->encodeHeader($subject),
                $body,
                implode("\r\n", $headers)
            );
            if (!$sent) {
                throw new RuntimeException('A função mail() não confirmou o envio.');
            }
        }

        return ['sent' => true, 'skipped' => false, 'message' => 'E-mail enviado.'];
    }

    private function sendSmtp(array $recipients, string $subject, string $body): void {
        if (!in_array($this->encryption, ['', 'none', 'tls', 'ssl'], true)) {
            throw new RuntimeException('SMTP_ENCRYPTION inválido.');
        }
        if ($this->username !== '' && in_array($this->encryption, ['', 'none'], true)) {
            throw new RuntimeException('Autenticação SMTP exige TLS ou SSL.');
        }
        $transport = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$socket) {
            throw new RuntimeException('Não foi possível conectar ao servidor SMTP.');
        }
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);
            $hostname = gethostname() ?: 'chronodesk';
            $this->command($socket, 'EHLO ' . preg_replace('/[^a-zA-Z0-9.-]/', '', $hostname), [250]);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Falha ao ativar criptografia TLS no SMTP.');
                }
                $this->command($socket, 'EHLO ' . preg_replace('/[^a-zA-Z0-9.-]/', '', $hostname), [250]);
            }

            if ($this->username !== '') {
                if ($this->password === '') {
                    throw new RuntimeException('SMTP_PASSWORD não configurada.');
                }
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->username), [334]);
                $this->command($socket, base64_encode($this->password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command($socket, 'DATA', [354]);

            $headers = [
                'From: ' . $this->formatAddress($this->from, $this->fromName),
                'To: ' . implode(', ', $recipients),
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'Date: ' . date(DATE_RFC2822),
            ];
            $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body);
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, array $expected): void {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('Falha de comunicação com o servidor SMTP.');
        }
        $this->expect($socket, $expected);
    }

    private function expect($socket, array $expected): void {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('Servidor SMTP rejeitou a operação (código ' . $code . ').');
        }
    }

    private function sanitizeHeader(string $value): string {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private function encodeHeader(string $value): string {
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function formatAddress(string $email, string $name): string {
        return $name === '' ? $email : sprintf('"%s" <%s>', addcslashes($name, '"\\'), $email);
    }
}
