<?php
/**
 * [SECURED] Alterar Senha Admin
 * Correções: NÃO sanitizar senha, auditoria
 */
require_once __DIR__ . '/../config.php';

verificar_admin_login_api();

if (!ENABLE_LOCAL_ADMIN) {
    json_response([
        'sucesso' => false,
        'mensagem' => 'Autenticação local está desativada. A senha deve ser alterada no Active Directory.'
    ], 403);
}
require_csrf_token();
require_json_content_type();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido'], 405);
}

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

$config = carregar_configuracao();
$config['admin_password_hash'] = hash_password($nova_senha);

if (salvar_configuracao($config)) {
    // [VULN-017] Auditoria
    audit_log('PASSWORD_CHANGED', 'Senha do administrador alterada', 'CRITICAL');
    json_response(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso!']);
} else {
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao alterar senha'], 500);
}
