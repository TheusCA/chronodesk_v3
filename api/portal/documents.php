<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/DocumentService.php';

$method = require_http_method(['GET', 'POST']);
$role = require_portal_auth();
$actor = portal_actor($role);
$service = new DocumentService();

try {
    if ($method === 'GET') {
        json_response([
            'sucesso' => true,
            'items' => $service->list($_GET, $actor),
            'limits' => [
                'max_file_bytes' => DocumentService::MAX_FILE_BYTES,
                'max_request_bytes' => DocumentService::MAX_REQUEST_BYTES,
                'allowed_extensions' => DocumentService::ALLOWED_EXTENSIONS,
            ],
            'can_upload' => in_array($role, ['admin', 'gestor'], true),
        ]);
    }

    require_csrf_token();
    $contentLength = filter_var(
        $_SERVER['CONTENT_LENGTH'] ?? 0,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($contentLength !== false && $contentLength > DocumentService::MAX_REQUEST_BYTES) {
        audit_log('DOCUMENT_UPLOAD_REJECTED', 'Requisicao de upload acima do limite.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'A requisicao excede o limite de 12 MB.'], 413);
    }
    if (!in_array($role, ['admin', 'gestor'], true)) {
        audit_log('DOCUMENT_UPLOAD_DENIED', 'Perfil sem permissao tentou enviar documento.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Seu perfil nao pode enviar documentos.'], 403);
    }
    if (!isset($_FILES['document']) || !is_array($_FILES['document'])) {
        audit_log('DOCUMENT_UPLOAD_REJECTED', 'Upload sem arquivo valido.', 'WARNING');
        throw new InvalidArgumentException('Selecione um arquivo para enviar.');
    }

    $document = $service->upload($_FILES['document'], $_POST, $actor);
    audit_log(
        'DOCUMENT_UPLOADED',
        'Documento ID ' . $document['id'] . ', extensao ' . $document['extension']
        . ', tamanho ' . $document['size_bytes'] . ' bytes.',
        'WARNING'
    );
    json_response([
        'sucesso' => true,
        'document' => $document,
        'mensagem' => 'Documento enviado com seguranca.',
    ], 201);
} catch (LengthException $error) {
    audit_log('DOCUMENT_UPLOAD_REJECTED', $error->getMessage(), 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 413);
} catch (InvalidArgumentException | DomainException $error) {
    audit_log('DOCUMENT_UPLOAD_REJECTED', $error->getMessage(), 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 400);
} catch (Throwable $error) {
    audit_log('DOCUMENT_UPLOAD_FAILED', 'Falha interna ao processar upload de documento.', 'ERROR');
    error_log('[DOCUMENT_UPLOAD] ' . get_class($error) . ': ' . $error->getMessage());
    json_response(['sucesso' => false, 'mensagem' => 'Nao foi possivel processar o documento.'], 500);
}
