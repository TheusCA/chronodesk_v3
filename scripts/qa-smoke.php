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
require_once __DIR__ . '/../services/SpreadsheetImportService.php';
require_once __DIR__ . '/../services/ShiftAttachmentService.php';
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
$_SERVER['SCRIPT_NAME'] = '/var/www/chronodesk/app/index.php';
assert_same('', get_base_path(), 'caminho fisico nunca vira URL publica');
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

$statusSource = file_get_contents(__DIR__ . '/../api/status.php');
assert_same(true, is_string($statusSource), 'le endpoint de status');
assert_same(
    true,
    strpos($statusSource, 'require_portal_auth()') !== false,
    'status operacional exige autenticacao'
);

$configSource = file_get_contents(__DIR__ . '/../config.php');
assert_same(true, is_string($configSource), 'le configuracao principal');
assert_same(
    true,
    strpos($configSource, "in_array(\$enable_local_admin_env, ['true', '1', 'yes', 'on'], true)") !== false,
    'admin local usa allowlist explicita'
);

foreach (['adicionar_funcionario.php', 'atualizar_funcionario.php'] as $employeeEndpoint) {
    $employeeEndpointSource = file_get_contents(__DIR__ . '/../api/' . $employeeEndpoint);
    assert_same(true, is_string($employeeEndpointSource), 'le endpoint ' . $employeeEndpoint);
    assert_same(
        true,
        strpos($employeeEndpointSource, 'verificar_admin_login_api()') !== false,
        $employeeEndpoint . ' exige permissao admin'
    );
    assert_same(
        true,
        strpos($employeeEndpointSource, "\$equipe === 'lideranca'") !== false
            && strpos($employeeEndpointSource, "\$access_role = 'admin'") !== false,
        $employeeEndpoint . ' forca Lideranca como admin'
    );
}

$adminPageSource = file_get_contents(__DIR__ . '/../frontend/src/pages/AdminPage.jsx');
assert_same(true, is_string($adminPageSource), 'le AdminPage');
assert_same(true, strpos($adminPageSource, 'Liderança') !== false, 'AdminPage exibe Lideranca');
assert_same(false, strpos($adminPageSource, 'Não se aplica') !== false, 'AdminPage nao exibe Nao se aplica');
assert_same(true, strpos($adminPageSource, "access_role: 'admin'") !== false, 'UI define admin ao selecionar Lideranca');

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

assert_same('lideranca', validate_funcionario_equipe('Liderança'), 'aceita equipe Lideranca');
assert_same('lideranca', validate_funcionario_equipe('Não se aplica'), 'normaliza equipe legada como Lideranca');
$forceEndBreakSource = file_get_contents(__DIR__ . '/../api/portal/admin_force_end_break.php');
assert_same(true, is_string($forceEndBreakSource), 'le endpoint de derrubada manual de pausa');
assert_same(true, strpos($forceEndBreakSource, 'require_portal_auth()') !== false, 'derrubada manual exige sessao');
assert_same(true, strpos($forceEndBreakSource, "\$role !== 'admin'") !== false, 'derrubada manual exige admin exato');
assert_same(true, strpos($forceEndBreakSource, 'portal_json_input()') !== false, 'derrubada manual exige CSRF e JSON');
assert_same(true, strpos($forceEndBreakSource, 'with_pause_state_lock') !== false, 'derrubada manual usa lock de estado');
assert_same(true, strpos($forceEndBreakSource, 'finalizar_pausa') !== false, 'derrubada manual reutiliza finalizacao segura');
assert_same(true, strpos($forceEndBreakSource, 'BREAK_FORCE_END_DENIED') !== false, 'derrubada manual audita tentativa negada');
assert_same(true, strpos($forceEndBreakSource, 'BREAK_FORCE_ENDED_BY_ADMIN') !== false, 'derrubada manual audita sucesso');

assert_same(null, validate_funcionario_equipe('supervisao'), 'rejeita equipe desconhecida');
assert_same('somente_leitura', validate_access_role('somente_leitura'), 'aceita perfil somente leitura');
assert_same(null, validate_access_role('superadmin'), 'rejeita perfil desconhecido');

$normalizedLeadership = normalizar_funcionario_array([
    'id' => 998,
    'nome' => 'Lider QA',
    'equipe' => 'lideranca',
    'access_role' => 'tecnico',
]);
assert_same('lideranca', $normalizedLeadership['equipe'], 'normaliza funcionario Lideranca');
assert_same('admin', $normalizedLeadership['access_role'], 'Lideranca recebe perfil admin no backend');

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
assert_same(40, CriticalIncidentService::MAX_IMPORT_COLUMNS, 'limite de colunas dos chamados criticos');
CriticalIncidentService::assertImportRowsShape([[
    'ticket_number' => 'INC001',
    'source' => 'servicenow',
    'title' => 'Indisponibilidade',
    'severity' => 'critical',
    'status' => 'open',
    'opened_at' => '2026-06-14 10:00',
]]);

$criticalService = new CriticalIncidentService(new QaTransactionPdo());
$normalizeCritical = new ReflectionMethod(CriticalIncidentService::class, 'normalizeRecord');
$normalizeCritical->setAccessible(true);
$normalizedCritical = $normalizeCritical->invoke($criticalService, [
    'incident_number' => 'INC002',
    'room_date' => '2026-06-15',
    'incident_opened_at' => '2026-06-15 10:00',
    'operation_reported_at' => '2026-06-15 10:02',
    'room_opened_at' => '2026-06-15 10:12',
    'normalized_at' => '2026-06-15 11:02',
    'room_description' => 'Indisponibilidade operacional',
]);
assert_same(12, $normalizedCritical[':room_opening_duration_minutes'], 'calcula tempo para abrir sala');
assert_same(50, $normalizedCritical[':room_duration_minutes'], 'calcula tempo de sala');

$normalizedCriticalDurations = $normalizeCritical->invoke($criticalService, [
    'incident_number' => 'INC-DURATION',
    'room_date' => '2026-06-15',
    'room_opening_duration_minutes' => '00:12',
    'room_duration_minutes' => '02:23',
    'room_description' => 'Duracoes HH:MM',
]);
assert_same(12, $normalizedCriticalDurations[':room_opening_duration_minutes'], 'aceita tempo de abertura HH:MM');
assert_same(143, $normalizedCriticalDurations[':room_duration_minutes'], 'aceita tempo de sala HH:MM');

$normalizedPartialCritical = $normalizeCritical->invoke($criticalService, [
    'incident_number' => 'INC-PARCIAL',
    'room_date' => '2026-06-15',
    'title' => '',
    'summary' => '',
]);
assert_same('INC-PARCIAL', $normalizedPartialCritical[':incident_number'], 'aceita chamado critico parcial');
assert_same('INC-PARCIAL', $normalizedPartialCritical[':title'], 'titulo legado vazio deriva do INCIDENTE');
assert_same('INC-PARCIAL', $normalizedPartialCritical[':summary'], 'sumario legado vazio deriva do INCIDENTE');
assert_same(null, $normalizedPartialCritical[':incident_opened_at'], 'chamado parcial nao exige hora de abertura');
assert_same(null, $normalizedPartialCritical[':room_opening_duration_minutes'], 'chamado parcial nao calcula abertura sem horarios');
assert_same(null, $normalizedPartialCritical[':room_duration_minutes'], 'chamado parcial nao calcula sala sem horarios');

foreach ([
    'ServiceNow' => 'servicenow',
    'Teams' => 'teams',
    'Outros' => 'other',
    'jira' => 'other',
    'manual' => 'other',
] as $sourceInput => $expectedSource) {
    $normalizedSource = $normalizeCritical->invoke($criticalService, [
        'incident_number' => 'INC-SOURCE-' . $expectedSource,
        'room_date' => '2026-06-15',
        'source' => $sourceInput,
    ]);
    assert_same($expectedSource, $normalizedSource[':source'], 'normaliza origem ' . $sourceInput);
}

$normalizedExcelCritical = $normalizeCritical->invoke($criticalService, [
    'incident_number' => 'INC-EXCEL',
    'room_date' => '46188',
    'incident_opened_at' => (string)(10 / 24),
    'operation_reported_at' => (string)((10 * 60 + 2) / 1440),
    'room_opened_at' => (string)((10 * 60 + 12) / 1440),
    'normalized_at' => (string)((11 * 60 + 2) / 1440),
    'room_description' => 'Datas numericas do Excel',
]);
assert_same('2026-06-15', $normalizedExcelCritical[':room_date'], 'converte data serial do Excel');
assert_same('2026-06-15 10:12:00', $normalizedExcelCritical[':room_opened_at'], 'converte hora serial do Excel');
assert_same(12, $normalizedExcelCritical[':room_opening_duration_minutes'], 'calcula abertura com horas do Excel');
assert_same(50, $normalizedExcelCritical[':room_duration_minutes'], 'calcula duracao com horas do Excel');

$spreadsheetCsv = tempnam(sys_get_temp_dir(), 'chronodesk_sheet_');
file_put_contents($spreadsheetCsv, "incident_number;room_date\nINC003;2026-06-15\n");
$spreadsheetService = new SpreadsheetImportService();
$parseSpreadsheetCsv = new ReflectionMethod(SpreadsheetImportService::class, 'parseCsv');
$parseSpreadsheetCsv->setAccessible(true);
$spreadsheetRows = $parseSpreadsheetCsv->invoke($spreadsheetService, $spreadsheetCsv, 500, 40, 4000);
assert_same('INC003', $spreadsheetRows[0]['incident_number'], 'parser seguro le CSV');

$warRoomCsv = tempnam(sys_get_temp_dir(), 'chronodesk_war_room_');
file_put_contents(
    $warRoomCsv,
    "\n\xEF\xBB\xBFINCIDENTE\tData da Sala\tCarteira - CC\tArea responsavel\tUsuarios afetados\tLink da sala\tHora de abertura Incidente\tHora abertura sala\tHora de normalizacao\n"
    . "INC004\t2026-06-15\tCC-01/SRE\tSRE - Netsec\t25\thttps://teams.example/sala\t08:59\t09:01\t11:24\n\n"
);
$warRoomRows = $parseSpreadsheetCsv->invoke($spreadsheetService, $warRoomCsv, 500, 40, 4000);
assert_same('INC004', $warRoomRows[0]['incidente'], 'parser remove BOM e ignora linhas vazias antes do cabecalho');
CriticalIncidentService::assertImportRowsShape($warRoomRows);
$normalizeCriticalImportRow = new ReflectionMethod(CriticalIncidentService::class, 'normalizeImportRow');
$normalizeCriticalImportRow->setAccessible(true);
$warRoomCanonical = $normalizeCriticalImportRow->invoke(null, $warRoomRows[0]);
$normalizedWarRoom = $normalizeCritical->invoke($criticalService, $warRoomCanonical);
assert_same('CC-01/SRE', $normalizedWarRoom[':sector'], 'importacao mapeia Carteira - CC');
assert_same('SRE - Netsec', $normalizedWarRoom[':responsible_area'], 'importacao mapeia Area responsavel');
assert_same(2, $normalizedWarRoom[':room_opening_duration_minutes'], 'importacao calcula abertura pelos horarios');
assert_same(143, $normalizedWarRoom[':room_duration_minutes'], 'importacao calcula tempo de sala pelos horarios');

$legacyXlsRejected = false;
try {
    $spreadsheetService->parseUpload([
        'name' => 'war-room.xls',
        'tmp_name' => $spreadsheetCsv,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($spreadsheetCsv),
    ], 500, 40, 4000);
} catch (DomainException $error) {
    $legacyXlsRejected = true;
}
assert_same(true, $legacyXlsRejected, 'XLS legado exige conversao explicita');

$shiftService = new ShiftAttachmentService(
    new QaTransactionPdo(),
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chronodesk_shift_qa_' . bin2hex(random_bytes(4))
);
$safeShiftName = new ReflectionMethod(ShiftAttachmentService::class, 'safeName');
$safeShiftName->setAccessible(true);
assert_same(true, in_array('xls', ShiftAttachmentService::ALLOWED_EXTENSIONS, true), 'feed de escalas aceita XLS');
assert_same(true, in_array('png', ShiftAttachmentService::ALLOWED_EXTENSIONS, true), 'feed de escalas aceita PNG');
$shiftExecutableRejected = false;
try {
    $safeShiftName->invoke($shiftService, 'escala.php.png');
} catch (ReflectionException | InvalidArgumentException $error) {
    $shiftExecutableRejected = true;
}
assert_same(true, $shiftExecutableRejected, 'feed de escalas rejeita double extension executavel');

$shiftSvgRejected = false;
try {
    $safeShiftName->invoke($shiftService, 'escala.svg');
} catch (ReflectionException | InvalidArgumentException $error) {
    $shiftSvgRejected = true;
}
assert_same(true, $shiftSvgRejected, 'feed de escalas rejeita SVG');

$shiftContentValidation = new ReflectionMethod(ShiftAttachmentService::class, 'validateContent');
$shiftContentValidation->setAccessible(true);
$invalidXls = tempnam(sys_get_temp_dir(), 'shift-invalid-xls-');
file_put_contents($invalidXls, '<html><script>alert(1)</script></html>');
$invalidXlsRejected = false;
try {
    $shiftContentValidation->invoke($shiftService, $invalidXls, 'xls');
} catch (ReflectionException | InvalidArgumentException $error) {
    $invalidXlsRejected = true;
}
assert_same(true, $invalidXlsRejected, 'feed de escalas rejeita XLS sem assinatura binaria');
@unlink($invalidXls);

$shiftManagerCheck = new ReflectionMethod(ShiftAttachmentService::class, 'assertManager');
$shiftManagerCheck->setAccessible(true);
$shiftManagerCheck->invoke($shiftService, ['role' => 'admin', 'username' => 'qa-admin']);
$shiftGestorDenied = false;
try {
    $shiftManagerCheck->invoke($shiftService, ['role' => 'gestor', 'username' => 'qa-gestor']);
} catch (ReflectionException | DomainException $error) {
    $shiftGestorDenied = true;
}
assert_same(true, $shiftGestorDenied, 'feed de escalas publica somente admin');

$shiftStorageValidation = new ReflectionMethod(ShiftAttachmentService::class, 'ensurePrivateDirectory');
$shiftStorageValidation->setAccessible(true);
$unsafeShiftStorage = __DIR__ . DIRECTORY_SEPARATOR . 'unsafe-shift-storage';
$projectStorageService = new ShiftAttachmentService(new QaTransactionPdo(), $unsafeShiftStorage);
$projectStorageRejected = false;
try {
    $shiftStorageValidation->invoke(
        $projectStorageService,
        $unsafeShiftStorage . DIRECTORY_SEPARATOR . 'aa'
    );
} catch (ReflectionException | RuntimeException $error) {
    $projectStorageRejected = true;
}
assert_same(true, $projectStorageRejected, 'feed de escalas rejeita storage dentro do projeto');
@rmdir($unsafeShiftStorage . DIRECTORY_SEPARATOR . 'aa');
@rmdir($unsafeShiftStorage);

$shiftApiSource = file_get_contents(__DIR__ . '/../api/portal/shift_attachments.php');
$shiftPageSource = file_get_contents(__DIR__ . '/../frontend/src/pages/ShiftSchedulesPage.jsx');
$navigationSource = file_get_contents(__DIR__ . '/../frontend/src/lib/navigation.js');
assert_same(true, is_string($shiftApiSource) && is_string($shiftPageSource) && is_string($navigationSource), 'le feed de escalas');
assert_same(true, strpos($shiftApiSource, "\$role === 'admin'") !== false, 'endpoint de escalas mostra upload apenas para admin');
assert_same(true, strpos($shiftPageSource, 'Escalas de Sábado') !== false, 'feed foi renomeado para Escalas de Sabado');
assert_same(true, strpos($navigationSource, 'Escalas de Sábado') !== false, 'menu foi renomeado para Escalas de Sabado');
assert_same(false, strpos($shiftPageSource, 'Escala de turnos') !== false, 'feed nao exibe texto antigo de turnos');
assert_same(true, strpos($shiftPageSource, "'.xls'") !== false, 'frontend aceita XLS no upload de escalas');

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

CriticalIncidentService::assertImportRowsShape([[
    'INCIDENTE' => 'INC004',
    'Data da Sala' => '2026-06-15',
    'Hora de abertura Incidente' => '10:00',
    'Hora abertura sala' => '10:12',
    'Tempo de Sala' => '00:50',
]]);

$criticalApiSource = file_get_contents(__DIR__ . '/../api/portal/critical_incidents.php');
assert_same(true, is_string($criticalApiSource), 'le endpoint de chamados criticos');
assert_same(
    true,
    strpos($criticalApiSource, "\$data['status'] = 'open';") !== false,
    'tecnico nao consegue forcar status administrativo na criacao'
);

$loginCiSource = file_get_contents(__DIR__ . '/../api/login_ci.php');
assert_same(true, is_string($loginCiSource), 'le endpoint de login CI');
assert_same(
    true,
    strpos($loginCiSource, 'ci_elevated_session') !== false && strpos($loginCiSource, 'portal_role') !== false,
    'login unico eleva admin ou gestor por access_role'
);

$appSource = file_get_contents(__DIR__ . '/../frontend/src/App.jsx');
$loginPageSource = file_get_contents(__DIR__ . '/../frontend/src/pages/LoginPage.jsx');
$loginCiSourceFrontend = file_get_contents(__DIR__ . '/../frontend/src/components/LoginCI.jsx');
assert_same(true, is_string($appSource) && is_string($loginPageSource), 'le frontend de login');
assert_same(false, strpos($appSource, "post('login_admin.php'") !== false, 'frontend nao chama login administrativo duplicado');
assert_same(false, strpos($loginPageSource, 'LoginAdmin') !== false, 'tela de login nao renderiza card administrativo duplicado');
assert_same(false, strpos($loginCiSourceFrontend, 'perfil e as permiss') !== false, 'login nao exibe texto tecnico de perfil/permissao');

$scheduleApiSource = file_get_contents(__DIR__ . '/../api/portal/schedules.php');
$operationalSource = file_get_contents(__DIR__ . '/../services/OperationalService.php');
assert_same(true, is_string($scheduleApiSource) && is_string($operationalSource), 'le backend de escala presencial');
assert_same(true, strpos($scheduleApiSource, "\$action === 'remove_rule'") !== false, 'endpoint possui acao para remover regra de escala');
assert_same(true, strpos($scheduleApiSource, "\$role !== 'admin'") !== false, 'remocao de regra exige admin');
assert_same(true, strpos($operationalSource, 'ON DUPLICATE KEY UPDATE') !== false, 'salvar regra usa UPSERT por employee_id');
assert_same(true, strpos($operationalSource, 'id = LAST_INSERT_ID(id)') !== false, 'UPSERT reaproveita id existente sem duplicar');
assert_same(true, strpos($operationalSource, 'effective_until = NULL') !== false, 'UPSERT reativa regra removida');
assert_same(true, strpos($operationalSource, "SET rule_type = \"undefined\"") !== false, 'remocao marca regra como indefinida');
assert_same(true, strpos($operationalSource, 'SCHEDULE_RULE_RECREATED') !== false, 'audita recriacao de regra');
assert_same(true, strpos($operationalSource, 'SCHEDULE_RULE_IMPORTED') !== false, 'audita importacao de regra');
assert_same(false, strpos($operationalSource, 'Colaborador ja possui regra de escala ativa.') !== false, 'importacao nao rejeita regra existente');

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
@unlink($spreadsheetCsv);
@unlink($warRoomCsv);

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
