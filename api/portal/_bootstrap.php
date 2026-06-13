<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../services/PortalService.php';
require_once __DIR__ . '/../../services/OperationalService.php';

const PORTAL_DEFAULT_JSON_MAX_BYTES = 65536;

function portal_username(): string {
    return sanitize_input(
        $_SESSION['admin_username']
            ?? $_SESSION['username']
            ?? $_SESSION['ci_username']
            ?? 'anonymous',
        100
    );
}

function portal_list_response(string $resource, array $roles = []): void {
    require_get_method();
    require_portal_auth($roles);
    $service = new PortalService();
    try {
        json_response([
            'sucesso' => true,
            'items' => $service->list($resource),
        ]);
    } catch (PortalStorageException $e) {
        json_response([
            'sucesso' => false,
            'mensagem' => $e->getMessage(),
        ], 503);
    }
}

function portal_actor(?string $role = null): array {
    $role = $role ?? require_portal_auth();
    return [
        'role' => $role,
        'username' => portal_username(),
        'employee_id' => $role === 'tecnico' ? (int)($_SESSION['ci_funcionario_id'] ?? 0) : null,
        'employee_name' => $role === 'tecnico' ? sanitize_input($_SESSION['ci_nome'] ?? '', 100) : null,
    ];
}

function portal_json_input(int $maxBytes = PORTAL_DEFAULT_JSON_MAX_BYTES): array {
    require_csrf_token();
    require_json_content_type();
    if ($maxBytes < 1) {
        throw new LogicException('Limite de payload invalido.');
    }
    $contentLength = filter_var(
        $_SERVER['CONTENT_LENGTH'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($contentLength !== false && $contentLength !== null && $contentLength > $maxBytes) {
        audit_log('JSON_PAYLOAD_REJECTED', 'Payload acima do limite em ' . ($_SERVER['REQUEST_URI'] ?? 'portal'), 'WARNING');
        throw new LengthException('O payload excede o limite permitido.');
    }

    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        throw new RuntimeException('Nao foi possivel ler o payload.');
    }
    try {
        $raw = stream_get_contents($stream, $maxBytes + 1);
    } finally {
        fclose($stream);
    }
    if ($raw === false) {
        throw new RuntimeException('Nao foi possivel ler o payload.');
    }
    if (strlen($raw) > $maxBytes) {
        audit_log('JSON_PAYLOAD_REJECTED', 'Payload acima do limite em ' . ($_SERVER['REQUEST_URI'] ?? 'portal'), 'WARNING');
        throw new LengthException('O payload excede o limite permitido.');
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('JSON invalido.', 0, $error);
    }
    if (!is_array($data) || array_is_list($data)) {
        throw new InvalidArgumentException('Dados invalidos.');
    }
    return $data;
}

function portal_operational_error(Throwable $error): void {
    if ($error instanceof LengthException) {
        json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 413);
    }
    if ($error instanceof PortalStorageException) {
        json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 503);
    }
    if ($error instanceof InvalidArgumentException) {
        json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 400);
    }
    if ($error instanceof DomainException) {
        json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 409);
    }
    error_log('[PORTAL_OPERATIONAL] ' . get_class($error) . ': ' . $error->getMessage());
    json_response([
        'sucesso' => false,
        'mensagem' => 'Nao foi possivel concluir a operacao.',
    ], 500);
}
