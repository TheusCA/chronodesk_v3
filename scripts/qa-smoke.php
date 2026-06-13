<?php
declare(strict_types=1);

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('AD_DOMAIN=corp.local');
putenv('AD_UPN_SUFFIX=corp.local');
putenv('APP_BASE_URL');
putenv('MAIL_ENABLED=false');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
$_SERVER['SERVER_NAME'] = 'chronodesk.local';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTP_HOST'] = "attacker.example\r\nX-Test: injected";

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_ldap.php';
require_once __DIR__ . '/../services/MailerService.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assert_same('user.name', normalizar_samaccountname('User.Name'), 'normaliza samAccountName');
assert_same('user.name', normalizar_samaccountname('user.name@corp.local'), 'aceita UPN permitido');
assert_same(null, normalizar_samaccountname('user.name@evil.example'), 'rejeita UPN externo');
assert_same(null, normalizar_samaccountname('user<script>'), 'rejeita caracteres invalidos');

$validLdap = normalizar_login_ldap('User.Name@corp.local', 'corp.local', 'corp.local');
assert_same('user.name', $validLdap['samaccountname'], 'normaliza login LDAP');
$invalidLdap = normalizar_login_ldap('user)(name', 'corp.local', 'corp.local');
assert_same('', $invalidLdap['samaccountname'], 'rejeita metacaracteres LDAP');

assert_same('http://chronodesk.local', safe_request_origin(), 'ignora Host header nao confiavel');
assert_same(true, session_window_is_current(900, 950, 500, 100, 1000), 'sessao dentro da janela');
assert_same(false, session_window_is_current(100, 950, 500, 100, 1000), 'sessao absoluta expirada');

$_SESSION = [
    'logged_in' => true,
    'portal_role' => 'gestor',
    'login_time' => time(),
    'last_activity' => time(),
];
assert_same('gestor', current_portal_role(), 'RBAC identifica gestor');
assert_same(false, in_array('admin.manage', portal_permissions_for_role('gestor'), true), 'gestor nao recebe admin');
$_SESSION['admin_logged_in'] = true;
assert_same('admin', current_portal_role(), 'RBAC identifica admin');
assert_same(true, in_array('admin.manage', portal_permissions_for_role('admin'), true), 'admin recebe permissao administrativa');
$_SESSION = [];

clear_rate_limit('qa_smoke');
assert_same(true, check_rate_limit('qa_smoke', 1, 60), 'primeira tentativa permitida');
assert_same(false, check_rate_limit('qa_smoke', 1, 60), 'limite aplicado');
clear_rate_limit('qa_smoke');
assert_same(true, check_rate_limit('qa_smoke', 1, 60), 'limite limpo apos sucesso');
clear_rate_limit('qa_smoke');

$mailResult = (new MailerService())->send(['qa@example.com'], 'QA', 'Teste');
assert_same(true, $mailResult['skipped'], 'mailer desabilitado nao envia');

putenv('MAIL_ENABLED=true');
putenv('MAIL_FROM=qa@example.com');
putenv('SMTP_HOST=127.0.0.1');
putenv('SMTP_USERNAME=qa');
putenv('SMTP_PASSWORD=secret');
putenv('SMTP_ENCRYPTION=none');
$blocked = false;
try {
    (new MailerService())->send(['qa@example.com'], 'QA', 'Teste');
} catch (RuntimeException $e) {
    $blocked = strpos($e->getMessage(), 'TLS') !== false;
}
assert_same(true, $blocked, 'bloqueia autenticacao SMTP sem criptografia');

echo "QA smoke OK\n";
