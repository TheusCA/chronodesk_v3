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
    audit_log('CI_LOGIN_RATE_LIMIT', login_audit_details($login_ad, 'ci_login/rate_limit'), 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Muitas tentativas de autenticação. Aguarde alguns minutos e tente novamente.'], 429);
}
require_login_user_throttle($login_ad, 'ci_login');

$autenticacao = autenticar_ci_via_ad($login_ad, $senha_ad);
$senha_ad = '';

if (!$autenticacao['sucesso']) {
    audit_log('CI_LOGIN_FAILURE', login_audit_details($login_ad, 'ci_login'), 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => $autenticacao['mensagem']], 401);
}

$funcionario = $autenticacao['funcionario'] ?? null;
if (!$funcionario || !($funcionario['ativo'] ?? true)) {
    audit_log('CI_LOGIN_INACTIVE', 'Login CI bloqueado por funcionario inativo ou inexistente', 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Funcionário inativo ou não cadastrado.'], 403);
}

clear_rate_limit('ci_login_global');
clear_rate_limit($rate_key);
login_user_throttle_release($login_ad);
session_regenerate_id(true);
$ci_username = normalizar_samaccountname($autenticacao['ad_user']['login'] ?? $login_ad);
// [L1] Admin só pela allowlist; access_role 'admin' legado vira lideranca ou gestor.
$allowlisted = $ci_username !== null && is_ad_admin_authorized($ci_username);
$access_role = session_role_for($allowlisted, $funcionario['access_role'] ?? null, $funcionario['equipe'] ?? null);
if (!$allowlisted && portal_role_value($funcionario['access_role'] ?? '') === 'admin') {
    audit_log(
        'ADMIN_ROLE_SEM_ALLOWLIST',
        'Funcionario ID ' . (int)$funcionario['id'] . ' com perfil admin fora de AD_ADMIN_USERS; sessao como ' . $access_role,
        'WARNING'
    );
}
$_SESSION['ci_logged_in'] = true;
$_SESSION['ci_funcionario_id'] = (int)$funcionario['id'];
$_SESSION['ci_username'] = $ci_username;
$_SESSION['ci_nome'] = sanitize_input($funcionario['nome'] ?? '', 100);
$_SESSION['ci_access_role'] = $access_role;
$_SESSION['ci_login_time'] = time();
$_SESSION['ci_last_activity'] = time();

if (portal_role_is_manager($access_role)) {
    $_SESSION['ci_elevated_session'] = true;
    $_SESSION['admin_logged_in'] = $access_role === 'admin';
    $_SESSION['admin_auth_type'] = 'employee_role';
    $_SESSION['admin_username'] = $_SESSION['ci_username'];
    $_SESSION['portal_role'] = $access_role;
    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = $_SESSION['ci_username'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
}

audit_log('CI_LOGIN_SUCCESS', 'Login CI bem-sucedido para funcionario ID ' . (int)$funcionario['id'], 'INFO');

json_response([
    'sucesso' => true,
    'mensagem' => 'Login realizado com sucesso.',
    'ci' => [
        'funcionario_id' => (int)$funcionario['id'],
        'username' => $_SESSION['ci_username'],
        'nome' => $_SESSION['ci_nome'],
        'role' => $access_role,
    ],
]);
