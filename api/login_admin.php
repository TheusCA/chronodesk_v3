<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_ldap.php';

require_post_method();
require_csrf_token();
require_json_content_type();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos.'], 400);
}

$username_input = sanitize_input($data['username'] ?? '', 150);
$password = (string)($data['password'] ?? '');
$username = normalizar_samaccountname($username_input);
if ($username === null || $password === '') {
    json_response(['sucesso' => false, 'mensagem' => 'Informe login e senha do AD.'], 400);
}

$rate_key = 'admin_api_' . preg_replace('/[^a-z0-9_]/', '_', $username);
if (!check_rate_limit('admin_api_global', 10, 900) || !check_rate_limit($rate_key, 5, 900)) {
    audit_log('ADMIN_LOGIN_FAILURE', 'Rate limit no login administrativo para ' . $username, 'WARNING');
    json_response([
        'sucesso' => false,
        'mensagem' => 'Muitas tentativas de login. Aguarde alguns minutos.'
    ], 429);
}

$authenticated = false;
$auth_type = '';
$ad_user = autenticar_ad($username_input, $password);
if ($ad_user) {
    $ad_username = normalizar_samaccountname($ad_user['login'] ?? $username);
    if ($ad_username !== null && is_ad_admin_authorized($ad_username)) {
        $authenticated = true;
        $auth_type = 'ad';
        $username = $ad_username;
    } else {
        $password = '';
        audit_log('ADMIN_LOGIN_FAILURE', 'Usuário AD sem autorização administrativa: ' . $username, 'WARNING');
        json_response([
            'sucesso' => false,
            'mensagem' => 'Usuário AD autenticado, mas não autorizado como administrador ou gestor.'
        ], 403);
    }
}

if (!$authenticated && ENABLE_LOCAL_ADMIN) {
    require_once __DIR__ . '/../classes/Usuario.php';
    try {
        $user = (new Usuario())->autenticar($username, $password);
        if ($user && in_array($user['role'], ['admin', 'gestor'], true)) {
            $authenticated = true;
            $auth_type = 'local';
            $local_role = $user['role'];
        }
    } catch (Throwable $e) {
        error_log('[ADMIN_API_LOGIN] Falha local: ' . $e->getMessage());
    }
}

$password = '';
if (!$authenticated) {
    audit_log('ADMIN_LOGIN_FAILURE', 'Falha de login administrativo para ' . $username, 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Login ou senha incorretos.'], 401);
}

clear_rate_limit('admin_api_global');
clear_rate_limit($rate_key);
session_regenerate_id(true);
$session_role = $auth_type === 'local' ? ($local_role ?? 'gestor') : 'admin';
$_SESSION['admin_logged_in'] = $session_role === 'admin';
$_SESSION['admin_auth_type'] = $auth_type;
$_SESSION['admin_username'] = $username;
$_SESSION['portal_role'] = $session_role;
$_SESSION['logged_in'] = true;
$_SESSION['username'] = $username;
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();
audit_log('ADMIN_LOGIN_SUCCESS', 'Login administrativo via ' . $auth_type . ' para ' . $username, 'INFO');

json_response([
    'sucesso' => true,
    'mensagem' => 'Login administrativo realizado.',
    'gestor' => [
        'autenticado' => true,
        'admin' => $session_role === 'admin',
        'username' => $username,
        'role' => $session_role,
    ],
]);
