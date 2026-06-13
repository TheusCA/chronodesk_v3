<?php
/**
 * [SECURED] Alterar Senha Admin
 * Correções: NÃO sanitizar senha, auditoria
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Usuario.php';

verificar_admin_login_api();
require_post_method();

if (!ENABLE_LOCAL_ADMIN || ($_SESSION['admin_auth_type'] ?? '') !== 'local') {
    json_response([
        'sucesso' => false,
        'mensagem' => 'A senha desta conta deve ser alterada no Active Directory.'
    ], 403);
}
require_csrf_token();
require_json_content_type();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['nova_senha'])) {
    json_response(['sucesso' => false, 'mensagem' => 'Nova senha não fornecida'], 400);
}

// [VULN-008] NÃO sanitizar senha - apenas validar comprimento
// password_hash é seguro contra qualquer input
$nova_senha = $data['nova_senha'] ?? '';

if (strlen($nova_senha) < 8) {
    json_response(['sucesso' => false, 'mensagem' => 'A senha deve ter no mínimo 8 caracteres'], 400);
}
if (strlen($nova_senha) > 128) {
    json_response(['sucesso' => false, 'mensagem' => 'A senha não pode exceder 128 caracteres'], 400);
}

$username = sanitize_input($_SESSION['admin_username'] ?? '', 100);
try {
    if ((new Usuario())->atualizarSenhaPorUsername($username, $nova_senha)) {
        audit_log('PASSWORD_CHANGED', 'Senha do administrador alterada', 'CRITICAL');
        json_response(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso!']);
    }
    json_response(['sucesso' => false, 'mensagem' => 'Usuário local não encontrado.'], 404);
} catch (Throwable $e) {
    error_log('[PASSWORD_CHANGE] Falha ao alterar senha local: ' . $e->getMessage());
    json_response(['sucesso' => false, 'mensagem' => 'Não foi possível alterar a senha.'], 500);
}
