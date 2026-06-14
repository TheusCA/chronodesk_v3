<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/DocumentService.php';

require_post_method();
$role = require_portal_auth(['admin', 'gestor']);
$actor = portal_actor($role);

try {
    $data = portal_json_input();
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new InvalidArgumentException('Documento invalido.');
    }
    (new DocumentService())->delete((int)$id, $actor);
    audit_log('DOCUMENT_DELETED', 'Documento ID ' . (int)$id . ' excluido logicamente.', 'WARNING');
    json_response(['sucesso' => true, 'mensagem' => 'Documento removido da listagem.']);
} catch (InvalidArgumentException $error) {
    json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 400);
} catch (DomainException $error) {
    json_response(['sucesso' => false, 'mensagem' => $error->getMessage()], 404);
} catch (Throwable $error) {
    audit_log('DOCUMENT_DELETE_FAILED', 'Falha interna na exclusao logica do documento ID ' . (int)($id ?? 0), 'CRITICAL');
    error_log('[DOCUMENT_DELETE] ' . get_class($error) . ': ' . $error->getMessage());
    json_response(['sucesso' => false, 'mensagem' => 'Nao foi possivel remover o documento.'], 500);
}
