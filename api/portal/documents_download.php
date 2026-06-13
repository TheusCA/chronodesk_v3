<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/DocumentService.php';

require_get_method();
$role = require_portal_auth();
$actor = portal_actor($role);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    json_response(['sucesso' => false, 'mensagem' => 'Documento invalido.'], 400);
}

try {
    $document = (new DocumentService())->download((int)$id, $actor);
    audit_log('DOCUMENT_DOWNLOADED', 'Documento ID ' . (int)$id . ' baixado.', 'INFO');

    $downloadName = preg_replace('/[^A-Za-z0-9._ -]/u', '_', (string)$document['original_name']);
    $downloadName = trim((string)$downloadName, " .");
    if ($downloadName === '') {
        $downloadName = 'documento.' . $document['extension'];
    }

    header('Content-Type: ' . $document['detected_mime']);
    header('Content-Length: ' . (int)$document['size_bytes']);
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\"\\") . '"; filename*=UTF-8\'\''
        . rawurlencode($downloadName));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($document['path']);
    exit;
} catch (DomainException $error) {
    audit_log('DOCUMENT_DOWNLOAD_DENIED', 'Download negado para documento ID ' . (int)$id, 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 403);
} catch (Throwable $error) {
    audit_log('DOCUMENT_DOWNLOAD_FAILED', 'Falha interna no download do documento ID ' . (int)$id, 'ERROR');
    error_log('[DOCUMENT_DOWNLOAD] ' . get_class($error) . ': ' . $error->getMessage());
    json_response(['sucesso' => false, 'mensagem' => 'Documento indisponivel.'], 500);
}
