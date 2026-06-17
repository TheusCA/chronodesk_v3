<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/ShiftAttachmentService.php';

require_get_method();
require_portal_auth();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    json_response(['sucesso' => false, 'mensagem' => 'Escala invalida.'], 400);
}
try {
    $item = (new ShiftAttachmentService())->download((int)$id);
    audit_log('SHIFT_ATTACHMENT_DOWNLOADED', 'Escala ID ' . (int)$id . ' baixada.', 'INFO');
    $name = preg_replace('/[^A-Za-z0-9._ -]/u', '_', (string)$item['original_name']);
    header('Content-Type: ' . $item['detected_mime']);
    header('Content-Length: ' . (int)$item['size_bytes']);
    $inline = isset($_GET['preview'])
        && $_GET['preview'] === '1'
        && in_array($item['extension'], ['png', 'jpg', 'jpeg', 'pdf'], true);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($name, "\"\\") . '"; filename*=UTF-8\'\''
        . rawurlencode($name));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($item['path']);
    exit;
} catch (DomainException $error) {
    audit_log('SHIFT_ATTACHMENT_DOWNLOAD_DENIED', 'Escala ID ' . (int)$id . ' nao encontrada.', 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Escala nao encontrada.'], 404);
} catch (Throwable $error) {
    audit_log('SHIFT_ATTACHMENT_DOWNLOAD_FAILED', 'Falha no download da escala ID ' . (int)$id . '.', 'CRITICAL');
    error_log('[SHIFT_DOWNLOAD] ' . get_class($error) . ': ' . $error->getMessage());
    json_response(['sucesso' => false, 'mensagem' => 'Escala indisponivel.'], 500);
}
