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
require_once __DIR__ . '/../services/CriticalIncidentService.php';
require_once __DIR__ . '/../services/AdCredentialProvider.php';

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
assert_same(true, in_array('operacao.approve', portal_permissions_for_role('gestor'), true), 'gestor pode decidir pausas');
assert_same(false, in_array('operacao.approve', portal_permissions_for_role('tecnico'), true), 'tecnico nao pode decidir pausas');
$_SESSION['admin_logged_in'] = true;
assert_same('admin', current_portal_role(), 'RBAC identifica admin');
assert_same(true, in_array('admin.manage', portal_permissions_for_role('admin'), true), 'admin recebe permissao administrativa');
assert_same(true, in_array('operacao.approve', portal_permissions_for_role('admin'), true), 'admin pode decidir pausas proprias ou de terceiros');
$_SESSION = [];

foreach (['aprovar', 'rejeitar'] as $pauseDecision) {
    $decisionSource = file_get_contents(__DIR__ . '/../api/' . $pauseDecision . '_pausa.php');
    assert_same(true, is_string($decisionSource), "le endpoint de {$pauseDecision} pausa");
    assert_same(
        true,
        strpos($decisionSource, "require_portal_auth(['admin', 'gestor'])") !== false,
        "{$pauseDecision} exige role administrativa no backend"
    );
    assert_same(
        true,
        strpos($decisionSource, 'require_csrf_token()') !== false,
        "{$pauseDecision} exige CSRF"
    );
    assert_same(
        false,
        strpos($decisionSource, 'session_actor_matches_employee') !== false,
        "{$pauseDecision} permite decisao propria para admin ou gestor"
    );
}

assert_same('na', validate_funcionario_equipe('NA'), 'aceita equipe administrativa');
assert_same('somente_leitura', validate_access_role('somente_leitura'), 'aceita perfil somente leitura');
assert_same(null, validate_access_role('superadmin'), 'rejeita perfil desconhecido');

putenv('AD_CREDENTIAL_PROVIDER=none');
assert_same('none', AdCredentialProviderFactory::fromEnvironment()->source(), 'provider AD desabilitado por padrao');
putenv('AD_CREDENTIAL_PROVIDER=env');
putenv('AD_BIND_USER=svc.chronodesk');
$qaAdPassword = 'qa-' . bin2hex(random_bytes(4));
putenv('AD_BIND_PASS=' . $qaAdPassword);
$adCredentials = AdCredentialProviderFactory::fromEnvironment()->credentials();
assert_same('svc.chronodesk', $adCredentials['username'], 'provider AD le usuario do ambiente');
assert_same($qaAdPassword, $adCredentials['password'], 'provider AD le senha do ambiente sem transforma-la');
putenv('AD_BIND_USER');
putenv('AD_BIND_PASS');
putenv('AD_CREDENTIAL_PROVIDER=none');

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

assert_same(500, CriticalIncidentService::MAX_IMPORT_ROWS, 'limite de linhas dos chamados criticos');
assert_same(26, CriticalIncidentService::MAX_IMPORT_COLUMNS, 'limite de colunas dos chamados criticos');
CriticalIncidentService::assertImportRowsShape([[
    'ticket_number' => 'INC001',
    'source' => 'servicenow',
    'title' => 'Indisponibilidade',
    'severity' => 'critical',
    'status' => 'open',
    'opened_at' => '2026-06-14 10:00',
]]);

$criticalNestedRejected = false;
try {
    CriticalIncidentService::assertImportRowsShape([[
        'ticket_number' => 'INC001',
        'source' => 'servicenow',
        'title' => ['nested'],
        'severity' => 'critical',
        'status' => 'open',
        'opened_at' => '2026-06-14 10:00',
    ]]);
} catch (InvalidArgumentException $error) {
    $criticalNestedRejected = true;
}
assert_same(true, $criticalNestedRejected, 'rejeita estrutura aninhada em chamados criticos');

$criticalMissingHeaderRejected = false;
try {
    CriticalIncidentService::assertImportRowsShape([[
        'ticket_number' => 'INC001',
        'title' => 'Sem origem',
        'severity' => 'critical',
        'status' => 'open',
        'opened_at' => '2026-06-14 10:00',
    ]]);
} catch (InvalidArgumentException $error) {
    $criticalMissingHeaderRejected = true;
}
assert_same(true, $criticalMissingHeaderRejected, 'rejeita coluna obrigatoria ausente em chamados criticos');

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
assert_same(true, in_array('doc', DocumentService::ALLOWED_EXTENSIONS, true), 'permite extensao doc validada');

$legacyWordDocument = tempnam(sys_get_temp_dir(), 'chronodesk_doc_');
file_put_contents(
    $legacyWordDocument,
    "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
    . str_repeat("\0", 64)
    . "W\0o\0r\0d\0D\0o\0c\0u\0m\0e\0n\0t"
);
$validateDocumentContent->invoke($documentService, $legacyWordDocument, 'doc');
assert_same(true, is_file($legacyWordDocument), 'aceita assinatura OLE de documento Word legado');

$fakeLegacyWordDocument = tempnam(sys_get_temp_dir(), 'chronodesk_fake_doc_');
file_put_contents($fakeLegacyWordDocument, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 128));
$fakeLegacyWordRejected = false;
try {
    $validateDocumentContent->invoke($documentService, $fakeLegacyWordDocument, 'doc');
} catch (ReflectionException | InvalidArgumentException $error) {
    $fakeLegacyWordRejected = true;
}
assert_same(true, $fakeLegacyWordRejected, 'rejeita arquivo OLE sem stream Word');

$macroLegacyWordDocument = tempnam(sys_get_temp_dir(), 'chronodesk_macro_doc_');
file_put_contents(
    $macroLegacyWordDocument,
    "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
    . "W\0o\0r\0d\0D\0o\0c\0u\0m\0e\0n\0t"
    . "_\0V\0B\0A\0_\0P\0R\0O\0J\0E\0C\0T"
);
$macroLegacyWordRejected = false;
try {
    $validateDocumentContent->invoke($documentService, $macroLegacyWordDocument, 'doc');
} catch (ReflectionException | InvalidArgumentException $error) {
    $macroLegacyWordRejected = true;
}
assert_same(true, $macroLegacyWordRejected, 'rejeita documento Word legado com macro');

$fakeOfficePackage = tempnam(sys_get_temp_dir(), 'chronodesk_fake_office_');
file_put_contents(
    $fakeOfficePackage,
    "PK\x03\x04[Content_Types].xml word/document.xml "
    . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
);
$fakeOfficeRejected = false;
try {
    $validateDocumentContent->invoke($documentService, $fakeOfficePackage, 'docx');
} catch (ReflectionException | InvalidArgumentException | RuntimeException $error) {
    $fakeOfficeRejected = true;
}
assert_same(true, $fakeOfficeRejected, 'rejeita pacote Office falso sem estrutura ZIP valida');

$traversalRejected = false;
try {
    $resolveDocumentPath->invoke($documentService, '../arquivo.pdf');
} catch (ReflectionException | RuntimeException $error) {
    $traversalRejected = true;
}
assert_same(true, $traversalRejected, 'rejeita path traversal em chave de documento');
@unlink($textDocument);
@unlink($binaryDocument);
@unlink($legacyWordDocument);
@unlink($fakeLegacyWordDocument);
@unlink($macroLegacyWordDocument);
@unlink($fakeOfficePackage);

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
