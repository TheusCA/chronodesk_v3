<?php
declare(strict_types=1);

// ============================================================================
// QA de autenticacao LDAP — testes negativos (Lote 2B)
//
// Nao depende de banco nem de config.php: carrega somente auth_ldap.php.
// get_db_connection() e substituida por um stub que falha, entao qualquer
// acesso ao banco durante o teste derruba o script em vez de passar calado.
//
// PRE-REQUISITO: extensao PHP ldap habilitada.
//   Linux:   sudo apt install php-ldap
//   Windows: descomentar extension=ldap no php.ini
// Sem ela autenticar_ad() retorna false antes da guarda de senha vazia, e os
// testes passariam pelo motivo errado. Por isso o script FALHA (nao pula)
// quando a extensao esta ausente.
//
// Uso: php scripts/qa-auth.php
// ============================================================================

putenv('AD_DOMAIN=corp.local');
putenv('AD_UPN_SUFFIX=corp.local');
// Host reservado (RFC 6761): nunca resolve, entao nenhum bind real acontece.
putenv('AD_SERVERS=dc-inexistente.invalid');
putenv('AD_PORT');
putenv('AD_SCHEME');
putenv('AD_USE_TLS');

// Mensagens de error_log() de auth_ldap.php nao poluem a saida do QA.
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-auth-');
ini_set('error_log', $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});

function get_db_connection(): PDO {
    throw new RuntimeException('qa-auth nao pode acessar o banco');
}

// Registra eventos de auditoria. LDAP_SERVERS_UNAVAILABLE so e emitido depois
// do laco de servidores, entao serve de prova de que houve tentativa de conexao.
$auditEvents = [];
function audit_log($action, $details = '', $severity = 'INFO'): void {
    $GLOBALS['auditEvents'][] = (string)$action;
}

require_once __DIR__ . '/../auth_ldap.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (!function_exists('ldap_connect')) {
    fwrite(STDERR, "FAIL: extensao ldap do PHP indisponivel; qa-auth exige php-ldap (ver cabecalho do script)\n");
    exit(1);
}

// ----------------------------------------------------------------------------
// Estrutural: a guarda precisa ficar ANTES do laco de conexao/bind.
// O AD aceita bind anonimo com senha vazia e responde sucesso; sem a guarda,
// qualquer login existente viraria bypass de autenticacao.
// ----------------------------------------------------------------------------
$authLdapSource = file_get_contents(__DIR__ . '/../auth_ldap.php');
assert_same(true, is_string($authLdapSource), 'le auth_ldap.php');

$guardPosition = strpos($authLdapSource, 'if (!$username || !$password) return false;');
$loopPosition = strpos($authLdapSource, 'foreach ($ad_servers as $server)');
assert_same(true, $guardPosition !== false, 'guarda de senha vazia existe em auth_ldap.php');
assert_same(true, $loopPosition !== false, 'laco de servidores AD existe em auth_ldap.php');
assert_same(
    true,
    $guardPosition !== false && $loopPosition !== false && $guardPosition < $loopPosition,
    'guarda de senha vazia roda ANTES do laco de conexao/bind'
);
assert_same(
    false,
    strpos($authLdapSource, '"ldap://{$server}') !== false,
    'esquema LDAP nao pode ser fixo no codigo; deve vir de AD_SCHEME'
);

// ----------------------------------------------------------------------------
// Comportamental: credenciais vazias sao rejeitadas SEM tentar conexao.
// Com servidor inexistente o retorno seria false de qualquer jeito, entao o
// que distingue a guarda e a ausencia de LDAP_SERVERS_UNAVAILABLE.
// ----------------------------------------------------------------------------
$negativeCases = [
    ['user.name', '', 'senha vazia e rejeitada'],
    ['user.name', '0', 'senha "0" e rejeitada (falsy em PHP)'],
    ['', 'SenhaQualquer', 'login vazio e rejeitado'],
];
foreach ($negativeCases as [$login, $password, $label]) {
    $auditEvents = [];
    assert_same(false, autenticar_ad($login, $password), $label);
    assert_same([], $auditEvents, "{$label} antes de qualquer conexao");
}

// Controle positivo: credencial preenchida passa pela guarda e chega ao laco.
// Garante que a verificacao acima e capaz de detectar uma tentativa de conexao.
$auditEvents = [];
assert_same(false, autenticar_ad('user.name', 'SenhaQualquer'), 'servidor inexistente nao autentica');
assert_same(['LDAP_SERVERS_UNAVAILABLE'], $auditEvents, 'controle: credencial preenchida tenta conexao');

echo "qa-auth OK\n";
