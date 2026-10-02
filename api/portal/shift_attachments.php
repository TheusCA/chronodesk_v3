<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/ShiftAttachmentService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);
$service = new ShiftAttachmentService();

try {
    if ($method === 'GET') {
        json_response([
            'sucesso' => true,
        ] + $service->list($_GET) + [
            'can_upload' => portal_role_can($role, 'escalas.manage'),
            'limits' => [
                'max_file_bytes' => ShiftAttachmentService::MAX_FILE_BYTES,
                'allowed_extensions' => ShiftAttachmentService::ALLOWED_EXTENSIONS,
            ],
        ]);
    }
    require_csrf_token();
    // [Lote 5c] Perfil antes de qualquer validacao do arquivo.
    if (!portal_role_can($role, 'escalas.manage')) {
        audit_log('SHIFT_ATTACHMENT_UPLOAD_DENIED', 'Perfil sem permissao tentou publicar escala.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Seu perfil nao pode publicar escalas.'], 403);
    }
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength === false || $contentLength < 1) {
        json_response(['sucesso' => false, 'mensagem' => 'Tamanho da requisicao invalido.'], 411);
    }
    if ($contentLength > ShiftAttachmentService::MAX_REQUEST_BYTES) {
        throw new LengthException('A requisicao excede o limite de 12 MB.');
    }
    if (!isset($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
        throw new InvalidArgumentException('Selecione um arquivo de escala.');
    }
    $item = $service->upload($_FILES['attachment'], $_POST, $actor);
    audit_log('SHIFT_ATTACHMENT_UPLOADED', 'Escala publicada. ID ' . $item['id'], 'WARNING');
    json_response(['sucesso' => true, 'item' => $item, 'mensagem' => 'Escala publicada com sucesso.'], 201);
} catch (Throwable $error) {
    audit_log('SHIFT_ATTACHMENT_REJECTED', get_class($error) . ': ' . $error->getMessage(), 'WARNING');
    if ($error instanceof RuntimeException) {
        error_log('[SHIFT_ATTACHMENT_UPLOAD] ' . get_class($error) . ': ' . $error->getMessage());
        json_response([
            'sucesso' => false,
            'mensagem' => 'Nao foi possivel salvar o arquivo. Tente novamente ou acione o suporte.',
        ], 500);
    }
    portal_operational_error($error);
}
