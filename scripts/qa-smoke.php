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
require_once __DIR__ . '/../services/OperationalService.php';
require_once __DIR__ . '/../services/DocumentService.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class QaTransactionPdo extends PDO {
    public bool $active = false;
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct(bool $active = false) {
        $this->active = $active;
    }

    public function inTransaction(): bool {
        return $this->active;
    }

    public function beginTransaction(): bool {
        $this->begins++;
        $this->active = true;
        return true;
    }

    public function commit(): bool {
        $this->commits++;
        $this->active = false;
        return true;
    }

    public function rollBack(): bool {
        $this->rollbacks++;
        $this->active = false;
        return true;
    }
}

function invoke_atomic(OperationalService $service, callable $callback) {
    $method = new ReflectionMethod(OperationalService::class, 'atomic');
    $method->setAccessible(true);
    return $method->invoke($service, $callback);
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
assert_same('na', validate_funcionario_equipe('NA'), 'aceita equipe administrativa');
assert_same('somente_leitura', validate_access_role('somente_leitura'), 'aceita perfil somente leitura');
assert_same(null, validate_access_role('superadmin'), 'rejeita perfil desconhecido');

$aprilCompetency = OperationalService::competencyRange('2026-04');
assert_same('2026-03-16', $aprilCompetency['start'], 'competencia abril inicia em 16/03');
assert_same('2026-04-15', $aprilCompetency['end'], 'competencia abril termina em 15/04');
assert_same('onsite', OperationalService::presenceForRule('even_days', '2026-04-16'), 'regra par gera presencial');
assert_same('remote', OperationalService::presenceForRule('even_days', '2026-04-17'), 'regra par gera remoto em dia impar');
assert_same('onsite', OperationalService::presenceForRule('odd_days', '2026-04-17'), 'regra impar gera presencial');
assert_same(500, OperationalService::MAX_IMPORT_ROWS, 'limite de linhas da importacao');
assert_same(6, OperationalService::MAX_IMPORT_COLUMNS, 'limite de colunas da importacao');

$validatedImport = OperationalService::validateScheduleImportRows([
    ['id' => '1', 'equipe' => 'n1', 'regra' => 'par'],
]);
assert_same('1', $validatedImport[0]['id'], 'estrutura CSV valida');

$invalidImportRejected = false;
try {
    OperationalService::validateScheduleImportRows([
        ['id' => '1', 'regra' => ['nested']],
    ]);
} catch (InvalidArgumentException $e) {
    $invalidImportRejected = true;
}
assert_same(true, $invalidImportRejected, 'rejeita celula aninhada na importacao');

$unknownHeaderRejected = false;
try {
    OperationalService::validateScheduleImportRows([
        ['id' => '1', 'regra' => 'par', 'arquivo' => 'payload.php'],
    ]);
} catch (InvalidArgumentException $e) {
    $unknownHeaderRejected = true;
}
assert_same(true, $unknownHeaderRejected, 'rejeita cabecalho inesperado na importacao');

$documentService = new DocumentService(
    new QaTransactionPdo(),
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chronodesk_document_qa_' . bin2hex(random_bytes(4))
);
$validateDocumentContent = new ReflectionMethod(DocumentService::class, 'validateContent');
$validateDocumentContent->setAccessible(true);
$rejectDangerousName = new ReflectionMethod(DocumentService::class, 'rejectDangerousName');
$rejectDangerousName->setAccessible(true);
$resolveDocumentPath = new ReflectionMethod(DocumentService::class, 'resolveStoragePath');
$resolveDocumentPath->setAccessible(true);

$textDocument = tempnam(sys_get_temp_dir(), 'chronodesk_txt_');
file_put_contents($textDocument, "procedimento seguro\nlinha 2");
$validateDocumentContent->invoke($documentService, $textDocument, 'txt');
assert_same(true, is_file($textDocument), 'aceita conteudo textual permitido');

$binaryDocument = tempnam(sys_get_temp_dir(), 'chronodesk_bin_');
file_put_contents($binaryDocument, "cabecalho\0binario");
$binaryRejected = false;
try {
    $validateDocumentContent->invoke($documentService, $binaryDocument, 'txt');
} catch (ReflectionException | InvalidArgumentException $error) {
    $binaryRejected = true;
}
assert_same(true, $binaryRejected, 'rejeita binario disfarçado de texto');

$dangerousNameRejected = false;
try {
    $rejectDangerousName->invoke($documentService, 'manual.php.pdf');
} catch (ReflectionException | InvalidArgumentException $error) {
    $dangerousNameRejected = true;
}
assert_same(true, $dangerousNameRejected, 'rejeita extensao perigosa em nome composto');

$traversalRejected = false;
try {
    $resolveDocumentPath->invoke($documentService, '../arquivo.pdf');
} catch (ReflectionException | RuntimeException $error) {
    $traversalRejected = true;
}
assert_same(true, $traversalRejected, 'rejeita path traversal em chave de documento');
@unlink($textDocument);
@unlink($binaryDocument);

$ownPdo = new QaTransactionPdo();
$ownService = new OperationalService($ownPdo);
assert_same('ok', invoke_atomic($ownService, static fn(): string => 'ok'), 'atomic retorna resultado');
assert_same(1, $ownPdo->begins, 'atomic abre transacao propria');
assert_same(1, $ownPdo->commits, 'atomic confirma transacao propria');
assert_same(0, $ownPdo->rollbacks, 'atomic nao reverte sucesso');

$outerPdo = new QaTransactionPdo(true);
$outerService = new OperationalService($outerPdo);
assert_same('nested', invoke_atomic($outerService, static fn(): string => 'nested'), 'atomic aceita transacao externa');
assert_same(0, $outerPdo->begins, 'atomic nao abre transacao aninhada');
assert_same(0, $outerPdo->commits, 'atomic nao confirma transacao externa');
assert_same(0, $outerPdo->rollbacks, 'atomic nao reverte transacao externa');

$rollbackPdo = new QaTransactionPdo();
$rollbackService = new OperationalService($rollbackPdo);
$rollbackCaught = false;
try {
    invoke_atomic($rollbackService, static function (): void {
        throw new RuntimeException('rollback');
    });
} catch (RuntimeException $e) {
    $rollbackCaught = true;
}
assert_same(true, $rollbackCaught, 'atomic propaga excecao');
assert_same(1, $rollbackPdo->rollbacks, 'atomic reverte transacao propria em erro');

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
