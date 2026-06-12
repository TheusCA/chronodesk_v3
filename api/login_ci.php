<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_ldap.php';
header('Content-Type: application/json; charset=utf-8');

require_post_method();
require_csrf_token();
require_json_content_type();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos.'], 400);
}

$login_ad = sanitize_input($data['login_ad'] ?? '', 150);
$senha_ad = isset($data['senha_ad']) ? (string)$data['senha_ad'] : '';
if (!$login_ad || !$senha_ad) {
    json_response(['sucesso' => false, 'mensagem' => 'Informe login e senha do AD.'], 400);
}

$rate_key = 'ci_login_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($login_ad));
if (!check_rate_limit('ci_login_global', 20, 300) || !check_rate_limit($rate_key, 5, 300)) {
    audit_log('CI_LOGIN_RATE_LIMIT', 'Rate limit no login CI para usuario ' . normalizar_samaccountname($login_ad), 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Muitas tentativas de autenticação. Aguarde alguns minutos e tente novamente.'], 429);
}

$autenticacao = autenticar_ci_via_ad($login_ad, $senha_ad);
$senha_ad = '';

if (!$autenticacao['sucesso']) {
    audit_log('CI_LOGIN_FAILURE', 'Falha de login CI para usuario ' . normalizar_samaccountname($login_ad), 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => $autenticacao['mensagem']], 401);
}

$funcionario = $autenticacao['funcionario'] ?? null;
if (!$funcionario || !($funcionario['ativo'] ?? true)) {
    audit_log('CI_LOGIN_INACTIVE', 'Login CI bloqueado por funcionario inativo ou inexistente', 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Funcionário inativo ou não cadastrado.'], 403);
}

session_regenerate_id(true);
$_SESSION['ci_logged_in'] = true;
$_SESSION['ci_funcionario_id'] = (int)$funcionario['id'];
$_SESSION['ci_username'] = normalizar_samaccountname($autenticacao['ad_user']['login'] ?? $login_ad);
$_SESSION['ci_nome'] = sanitize_input($funcionario['nome'] ?? '', 100);
$_SESSION['ci_login_time'] = time();
$_SESSION['ci_last_activity'] = time();

audit_log('CI_LOGIN_SUCCESS', 'Login CI bem-sucedido para funcionario ID ' . (int)$funcionario['id'], 'INFO');

json_response([
    'sucesso' => true,
    'mensagem' => 'Login realizado com sucesso.',
    'ci' => [
        'funcionario_id' => (int)$funcionario['id'],
        'username' => $_SESSION['ci_username'],
        'nome' => $_SESSION['ci_nome'],
    ],
]);
