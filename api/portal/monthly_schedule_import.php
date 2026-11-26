<?php
// [F10] Importacao da planilha "Escala Home - Base" (desenho do F10, 8.9):
// so escala_mensal.import (admin), CSRF, a planilha Base (.xlsx) e o
// casamento nome -> ID (.csv do F10-1) nas duas etapas. 'preview' nao grava;
// 'confirm' refaz a previa e grava os passos escolhidos, numa transacao.
// Auditoria ESCALA_BASE_IMPORTADA so com as contagens.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/EscalaBaseImport.php';

const ESCALA_BASE_MAX_REQUEST_BYTES = 3145728;

require_post_method();
$role = require_portal_auth();

try {
    if (!portal_role_can($role, 'escala_mensal.import')) {
        audit_log('ESCALA_BASE_NEGADA', "Perfil {$role} sem permissao tentou importar a planilha Base.", 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Somente administradores importam a planilha Base.'], 403);
    }
    require_csrf_token();
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength === false || $contentLength < 1) {
        json_response(['sucesso' => false, 'mensagem' => 'Tamanho da requisicao invalido.'], 411);
    }
    if ($contentLength > ESCALA_BASE_MAX_REQUEST_BYTES) {
        throw new LengthException('A requisicao de importacao excede 3 MB.');
    }
    $paths = [];
    foreach (['planilha' => '.xlsx', 'casamento' => '.csv'] as $field => $extension) {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])
            || strtolower(substr((string)$file['name'], -strlen($extension))) !== $extension) {
            throw new InvalidArgumentException('Envie a planilha Base (.xlsx) e o casamento nome -> ID (.csv).');
        }
        $paths[$field] = (string)$file['tmp_name'];
    }
    $action = $_POST['action'] ?? 'preview';
    if (!in_array($action, ['preview', 'confirm'], true)) {
        throw new InvalidArgumentException('Acao de importacao invalida.');
    }
    $import = new EscalaBaseImport(get_db_connection(), portal_username());
    $from = $_POST['a_partir'] ?? null;
    $result = $action === 'preview'
        ? $import->preview($paths['planilha'], $paths['casamento'], $from)
        : $import->confirm($paths['planilha'], $paths['casamento'], $from, $_POST['passos'] ?? null);
    audit_log('ESCALA_BASE_IMPORTADA', EscalaBaseImport::auditDetails($result, $action), $action === 'confirm' ? 'WARNING' : 'INFO');
    json_response(['sucesso' => true, 'acao' => $action] + $result);
} catch (Throwable $e) {
    portal_operational_error($e);
}
