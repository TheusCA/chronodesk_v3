<?php
declare(strict_types=1);

// ============================================================================
// QA de seguranca — limite de login por usuario (SEC-03) e testes negativos
// (QA-01). Lote 7.
//
// Uso: php scripts/qa-security.php
//
// 1. SEC-03 em unidade: configuracao, chave do contador, regra da janela e o
//    protocolo SQL contra uma tabela simulada em memoria.
// 2. CSV: neutralizacao de formula por comportamento, inclusive UTF-8 invalido.
// 3. Endpoints reais, cada um num processo PHP filho: sessao, token CSRF e
//    corpo JSON simulados. Cobre senha vazia, POST sem CSRF (403), tecnico em
//    endpoint de gestor (403), sem sessao (401) e contador indisponivel (503,
//    sem consultar o AD).
//
// Nao acessa banco nem AD, nem no servidor: o filho aponta o banco para
// 127.0.0.1:1 (nada escuta) e o AD para um host .invalid (RFC 6761), e essas
// variaveis tem precedencia sobre o .env. Escreve so num diretorio temporario
// proprio, removido ao final.
// ============================================================================

const QA_RUNNER_FLAG = '--run-endpoint';
const QA_PASSWORD = 'SenhaQa-Nunca-Gravar-7';

function qa_isolated_environment(string $errorLog): void {
    foreach ([
        'APP_ENV' => 'development',
        'APP_DEBUG' => 'false',
        'SECRET_KEY' => 'qa-only-secret-key',
        'MAIL_ENABLED' => 'false',
        'APP_BASE_URL' => '',
        'AD_DOMAIN' => 'corp.local',
        'AD_UPN_SUFFIX' => 'corp.local',
        'AD_SERVERS' => 'dc-inexistente.invalid',
        'AD_ADMIN_USERS' => 'qa.admin',
        'ENABLE_LOCAL_ADMIN' => 'false',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
        'DB_PASS' => 'qa-sem-banco',
        'LOGIN_USER_MAX_FAILURES' => '5',
        'LOGIN_USER_WINDOW_SECONDS' => '900',
        'PHP_ERROR_LOG' => $errorLog,
    ] as $name => $value) {
        putenv("{$name}={$value}");
    }
}

// ----------------------------------------------------------------------------
// Processo filho: executa um endpoint real e devolve status e corpo.
// ----------------------------------------------------------------------------
if (($argv[1] ?? '') === QA_RUNNER_FLAG) {
    $spec = json_decode((string)base64_decode((string)($argv[2] ?? ''), true), true);
    if (!is_array($spec)) {
        fwrite(STDERR, "spec invalida\n");
        exit(2);
    }
    qa_isolated_environment($spec['error_log']);

    // php://input nao existe na CLI: um wrapper devolve o corpo da requisicao
    // e repassa os demais caminhos php:// ao wrapper original.
    final class QaRequestStream {
        public static string $body = '';
        public $context;
        private int $position = 0;
        private $inner = null;

        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
            if (strtolower($path) === 'php://input') {
                return true;
            }
            stream_wrapper_restore('php');
            $this->inner = @fopen($path, $mode);
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
            return $this->inner !== false;
        }

        public function stream_read(int $count): string|false {
            if ($this->inner !== null) {
                return fread($this->inner, $count);
            }
            $chunk = substr(self::$body, $this->position, $count);
            $this->position += strlen($chunk);
            return $chunk;
        }

        public function stream_write(string $data): int {
            return $this->inner !== null ? (int)fwrite($this->inner, $data) : 0;
        }

        public function stream_eof(): bool {
            return $this->inner !== null ? feof($this->inner) : $this->position >= strlen(self::$body);
        }

        public function stream_stat(): array {
            return [];
        }

        public function stream_close(): void {
            if ($this->inner !== null) {
                fclose($this->inner);
            }
        }
    }
    // Estado de pausas, CSV e configuracao no diretorio do QA: init.php grava
    // nesses arquivos (inicializar_csv, limpar_estado_antigo).
    $qaStateDir = dirname($spec['error_log']);
    define('PAUSAS_CSV', $qaStateDir . '/pausas.csv');
    define('ESTADO_JSON', $qaStateDir . '/estado.json');
    define('CONFIG_JSON', $qaStateDir . '/config_sistema.json');
    define('FUNCIONARIOS_JSON', $qaStateDir . '/funcionarios.json');

    QaRequestStream::$body = (string)($spec['body'] ?? '');
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', QaRequestStream::class);

    ini_set('session.use_cookies', '0');
    session_id('qasec' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION['csrf_token'] = 'qa-csrf-token';
    $now = time();
    foreach ($spec['session'] ?? [] as $key => $value) {
        $_SESSION[$key] = $value === '__now__' ? $now : $value;
    }

    $csrf = ['valid' => 'qa-csrf-token', 'invalid' => 'outro-token', 'missing' => null][$spec['csrf']];
    $_SERVER['REQUEST_METHOD'] = $spec['method'];
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $_SERVER['SERVER_NAME'] = 'chronodesk.local';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['SCRIPT_NAME'] = '/' . $spec['endpoint'];
    if (isset($spec['content_type'])) {
        $_SERVER['CONTENT_TYPE'] = $spec['content_type'];
        $_SERVER['CONTENT_LENGTH'] = (string)strlen(QaRequestStream::$body);
    }
    if (isset($spec['post'])) {
        $_POST = $spec['post'];
        if ($csrf !== null) {
            $_POST['csrf_token'] = $csrf;
        }
    } elseif ($csrf !== null) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    }

    ob_start();
    register_shutdown_function(static function (): void {
        $body = (string)ob_get_clean();
        $status = http_response_code();
        fwrite(STDOUT, "\nQA_RESULT " . json_encode([
            'status' => $status === false ? 200 : $status,
            'body' => $body,
        ]) . "\n");
    });
    $endpoint = dirname(__DIR__) . '/' . $spec['endpoint'];
    chdir(dirname($endpoint));
    require $endpoint;
    exit;
}

// ----------------------------------------------------------------------------
// Processo principal
// ----------------------------------------------------------------------------
$qaDir = sys_get_temp_dir() . '/chronodesk-qa-security-' . bin2hex(random_bytes(6));
if (!mkdir($qaDir, 0700)) {
    fwrite(STDERR, "FAIL: nao criou diretorio temporario do QA\n");
    exit(1);
}
register_shutdown_function(static function () use ($qaDir): void {
    foreach (glob($qaDir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($qaDir);
});

$parentLog = $qaDir . '/parent.log';
qa_isolated_environment($parentLog);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
define('CHRONODESK_STATELESS', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_ldap.php';
require_once __DIR__ . '/../services/OperationalService.php';
// config.php manda os erros para o log do QA, apagado ao final; erro fatal do
// proprio QA precisa aparecer na saida.
ini_set('display_errors', 'stderr');

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

// ============================================================================
// 1. SEC-03 — limite de login por usuario
// ============================================================================

// Configuracao: faixa aceita e valor padrao para ausente ou invalido.
$configCases = [
    [false, false, 5, 900, 'ausente usa o padrao'],
    ['3', '1800', 3, 1800, 'valores validos'],
    ['1', '60', 1, 60, 'minimos da faixa'],
    ['20', '86400', 20, 86400, 'maximos da faixa'],
    ['0', '59', 5, 900, 'abaixo da faixa usa o padrao'],
    ['21', '86401', 5, 900, 'acima da faixa usa o padrao'],
    ['07', '0900', 5, 900, 'zero a esquerda usa o padrao (igual ao preflight)'],
    ['abc', '15m', 5, 900, 'nao numerico usa o padrao'],
    ['', ' ', 5, 900, 'vazio usa o padrao'],
];
foreach ($configCases as [$max, $window, $expectedMax, $expectedWindow, $label]) {
    putenv($max === false ? 'LOGIN_USER_MAX_FAILURES' : "LOGIN_USER_MAX_FAILURES={$max}");
    putenv($window === false ? 'LOGIN_USER_WINDOW_SECONDS' : "LOGIN_USER_WINDOW_SECONDS={$window}");
    assert_same(['max_attempts' => $expectedMax, 'window' => $expectedWindow], login_user_throttle_config(), "configuracao: {$label}");
}
putenv('LOGIN_USER_MAX_FAILURES=5');
putenv('LOGIN_USER_WINDOW_SECONDS=900');

// Chave: o mesmo sAMAccountName do bind; variacoes da mesma conta contam juntas.
$keyCases = [
    ['joao.silva', 'joao.silva'],
    ['JOAO.SILVA', 'joao.silva'],
    [' joao.silva ', 'joao.silva'],
    ['joao.silva@corp.local', 'joao.silva'],
    ['joao.silva@CORP.LOCAL', 'joao.silva'],
    ['joao.silva@', 'joao.silva'],
    ['joao.silva@outro.com', null],
    ['@corp.local', null],
    ['a', null],
    ['', null],
    ['joao silva', null],
    ['(uid=*)', null],
    [str_repeat('a', 101), null],
];
foreach ($keyCases as [$login, $expected]) {
    assert_same($expected, login_user_throttle_key($login), 'chave do contador para ' . json_encode($login));
}

// Login sem chave nunca chega ao bind: autenticar_ad() recusa antes de tentar
// conexao (o laco de servidores registra "[AUTH_AD] Todos os servidores").
function qa_ad_attempts(string $log): int {
    return substr_count((string)@file_get_contents($log), '[AUTH_AD] Todos os servidores');
}
foreach ($keyCases as [$login, $expected]) {
    if ($expected !== null) {
        continue;
    }
    $before = qa_ad_attempts($parentLog);
    assert_same(false, autenticar_ad($login, QA_PASSWORD), 'login sem chave nao autentica: ' . json_encode($login));
    assert_same($before, qa_ad_attempts($parentLog), 'login sem chave nao chega ao bind: ' . json_encode($login));
}
$before = qa_ad_attempts($parentLog);
autenticar_ad('joao.silva@', QA_PASSWORD);
assert_same($before + 1, qa_ad_attempts($parentLog), 'controle: login com chave chega ao laco de bind');

// Regra da janela, pura.
$now = 1_790_000_000;
$at = static fn (int $secondsAgo): string => date('Y-m-d H:i:s', $now - $secondsAgo);
$decideCases = [
    [null, ['allowed' => true, 'attempts' => 1, 'retry_after' => 0], 'sem linha: primeira tentativa'],
    [['failed_attempts' => 0, 'last_attempt_at' => $at(0)], ['allowed' => true, 'attempts' => 1, 'retry_after' => 0], 'linha zerada'],
    [['failed_attempts' => 4, 'last_attempt_at' => $at(10)], ['allowed' => true, 'attempts' => 5, 'retry_after' => 0], 'quinta tentativa ainda passa'],
    [['failed_attempts' => 5, 'last_attempt_at' => $at(10)], ['allowed' => false, 'attempts' => 5, 'retry_after' => 890], 'sexta tentativa bloqueada'],
    [['failed_attempts' => 5, 'last_attempt_at' => $at(899)], ['allowed' => false, 'attempts' => 5, 'retry_after' => 1], 'ultimo segundo da janela ainda bloqueia'],
    [['failed_attempts' => 5, 'last_attempt_at' => $at(900)], ['allowed' => true, 'attempts' => 1, 'retry_after' => 0], 'janela inteira sem tentativa zera a contagem'],
    [['failed_attempts' => 4, 'last_attempt_at' => $at(899)], ['allowed' => true, 'attempts' => 5, 'retry_after' => 0], 'tentativas espacadas dentro da janela acumulam (regra do AD)'],
    [['failed_attempts' => 9, 'last_attempt_at' => $at(-30)], ['allowed' => false, 'attempts' => 9, 'retry_after' => 930], 'relogio adiantado nao libera'],
    [['failed_attempts' => 5, 'last_attempt_at' => 'lixo'], ['allowed' => true, 'attempts' => 1, 'retry_after' => 0], 'data ilegivel vale zero'],
    [['failed_attempts' => -3, 'last_attempt_at' => $at(1)], ['allowed' => true, 'attempts' => 1, 'retry_after' => 0], 'contagem negativa vale zero'],
];
foreach ($decideCases as [$row, $expected, $label]) {
    assert_same($expected, login_user_throttle_decide($row, $now, 5, 900), "regra da janela: {$label}");
}

// Tabela simulada: interpreta os comandos de security.php e registra a ordem.
final class QaThrottlePdo extends PDO {
    public array $rows = [];
    public array $log = [];
    public bool $transaction = false;
    public ?string $throwOn = null;
    public ?string $falseOn = null;

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $sql = preg_replace('/\s+/', ' ', trim($query));
        if ($this->throwOn !== null && strpos($sql, $this->throwOn) !== false) {
            throw new PDOException('SQLSTATE[HY000]: falha simulada');
        }
        return new QaThrottleStatement($this, $sql);
    }

    public function beginTransaction(): bool {
        $this->log[] = 'BEGIN';
        $this->transaction = true;
        return true;
    }

    public function commit(): bool {
        $this->log[] = 'COMMIT';
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool {
        $this->log[] = 'ROLLBACK';
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool {
        return $this->transaction;
    }
}

final class QaThrottleStatement extends PDOStatement {
    private array $result = [];

    public function __construct(private QaThrottlePdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params = $params ?? [];
        $pdo = $this->pdo;
        $sql = $this->sql;
        if ($pdo->falseOn !== null && strpos($sql, $pdo->falseOn) !== false) {
            return false;
        }
        foreach ($params as $param) {
            if (strpos((string)$param, QA_PASSWORD) !== false) {
                throw new LogicException('senha enviada ao banco');
            }
        }

        if (strpos($sql, 'ON DUPLICATE KEY UPDATE login = login') !== false) {
            $pdo->log[] = 'ENSURE ' . $params[0] . ($pdo->transaction ? ' (em transacao)' : '');
            $pdo->rows[$params[0]] ??= ['failed_attempts' => 0, 'last_attempt_at' => $params[1]];
        } elseif (strpos($sql, 'ON DUPLICATE KEY UPDATE failed_attempts = ?, last_attempt_at = ?') !== false) {
            if ($params[1] !== $params[3] || $params[2] !== $params[4]) {
                throw new LogicException('upsert com valores divergentes');
            }
            $pdo->log[] = 'WRITE ' . $params[0] . '=' . $params[1] . ($pdo->transaction ? '' : ' (FORA da transacao)');
            $pdo->rows[$params[0]] = ['failed_attempts' => $params[1], 'last_attempt_at' => $params[2]];
        } elseif ($sql === 'SELECT failed_attempts, last_attempt_at FROM login_user_throttle WHERE login = ? FOR UPDATE') {
            $pdo->log[] = 'LOCK ' . $params[0] . ($pdo->transaction ? '' : ' (FORA da transacao)');
            $this->result = isset($pdo->rows[$params[0]]) ? [$pdo->rows[$params[0]]] : [];
        } elseif ($sql === 'DELETE FROM login_user_throttle WHERE last_attempt_at < ?') {
            $pdo->log[] = 'CLEANUP';
            foreach ($pdo->rows as $login => $row) {
                if ($row['last_attempt_at'] < $params[0]) {
                    unset($pdo->rows[$login]);
                }
            }
        } elseif ($sql === 'DELETE FROM login_user_throttle WHERE login = ?') {
            $pdo->log[] = 'RELEASE ' . $params[0];
            unset($pdo->rows[$params[0]]);
        } else {
            throw new LogicException('SQL inesperado: ' . $sql);
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->result) ?? false;
    }
}

$pdo = new QaThrottlePdo();
assert_same('allowed', login_user_throttle_acquire('Joao.Silva', $pdo, $now), 'primeira tentativa permitida');
assert_same(
    ['ENSURE joao.silva', 'BEGIN', 'LOCK joao.silva', 'WRITE joao.silva=1', 'COMMIT', 'CLEANUP'],
    $pdo->log,
    'protocolo: linha garantida fora da transacao, trava, grava, confirma e limpa'
);
foreach (['joao.silva@corp.local', 'JOAO.SILVA', 'joao.silva@', 'joao.silva'] as $i => $spelling) {
    assert_same('allowed', login_user_throttle_acquire($spelling, $pdo, $now + $i + 1), "tentativa " . ($i + 2) . " permitida ({$spelling})");
}
assert_same(5, $pdo->rows['joao.silva']['failed_attempts'], 'grafias da mesma conta contam juntas');
$pdo->log = [];
assert_same('blocked', login_user_throttle_acquire('joao.silva', $pdo, $now + 10), 'sexta tentativa bloqueada');
assert_same(['ENSURE joao.silva', 'BEGIN', 'LOCK joao.silva', 'COMMIT', 'CLEANUP'], $pdo->log, 'tentativa bloqueada nao grava nem estende o bloqueio');
assert_same(date('Y-m-d H:i:s', $now + 4), $pdo->rows['joao.silva']['last_attempt_at'], 'bloqueio conta da ultima tentativa permitida');
assert_same('allowed', login_user_throttle_acquire('maria.souza', $pdo, $now + 10), 'outro usuario nao e afetado');
assert_same('blocked', login_user_throttle_acquire('joao.silva', $pdo, $now + 4 + 899), 'bloqueado ate o fim da janela');
assert_same('allowed', login_user_throttle_acquire('joao.silva', $pdo, $now + 4 + 900), 'liberado depois da janela');
assert_same(1, $pdo->rows['joao.silva']['failed_attempts'], 'contagem recomeca depois da janela');
assert_same(true, isset($pdo->rows['maria.souza']), 'limpeza preserva linha ainda dentro da janela');
login_user_throttle_acquire('pedro.alves', $pdo, $now + 10 + 901);
assert_same(false, isset($pdo->rows['maria.souza']), 'limpeza apaga linha vencida de outro usuario');
assert_same(true, isset($pdo->rows['joao.silva']), 'limpeza preserva a linha recente');

for ($i = 0; $i < 4; $i++) {
    login_user_throttle_acquire('joao.silva', $pdo, $now + 1000 + $i);
}
assert_same('blocked', login_user_throttle_acquire('joao.silva', $pdo, $now + 1010), 'cinco tentativas sem sucesso bloqueiam');
login_user_throttle_release('JOAO.SILVA@corp.local', $pdo);
assert_same(false, isset($pdo->rows['joao.silva']), 'login concluido zera o contador da conta');
assert_same('allowed', login_user_throttle_acquire('joao.silva', $pdo, $now + 1011), 'depois do login concluido a conta volta a tentar');

$pdo->log = [];
assert_same('allowed', login_user_throttle_acquire('usuario invalido', $pdo, $now), 'login sem chave segue para a validacao normal');
login_user_throttle_release('usuario invalido', $pdo);
assert_same([], $pdo->log, 'login sem chave nao toca o banco');

putenv('LOGIN_USER_MAX_FAILURES=2');
$pdo = new QaThrottlePdo();
login_user_throttle_acquire('ana.lima', $pdo, $now);
login_user_throttle_acquire('ana.lima', $pdo, $now + 1);
assert_same('blocked', login_user_throttle_acquire('ana.lima', $pdo, $now + 2), 'limiar vem do .env');
putenv('LOGIN_USER_MAX_FAILURES=5');

// Falha fechada: qualquer erro do banco recusa o login e desfaz a transacao.
foreach ([
    ['throwOn', 'login = login', [], 'falha ao garantir a linha'],
    ['throwOn', 'FOR UPDATE', ['BEGIN', 'ROLLBACK'], 'falha ao travar a linha'],
    ['falseOn', 'FOR UPDATE', ['BEGIN', 'ROLLBACK'], 'execute devolvendo false (conexao sem modo de excecao)'],
    ['falseOn', 'failed_attempts = ?', ['BEGIN', 'LOCK joao.silva', 'ROLLBACK'], 'falha ao gravar a tentativa'],
] as [$mode, $needle, $expectedLog, $label]) {
    $pdo = new QaThrottlePdo();
    $pdo->{$mode} = $needle;
    assert_same('unavailable', login_user_throttle_acquire('joao.silva', $pdo, $now), "falha fechada: {$label}");
    assert_same($expectedLog, array_values(array_filter($pdo->log, static fn ($entry) => strpos($entry, 'ENSURE') !== 0)), "transacao desfeita: {$label}");
}
$pdo = new QaThrottlePdo();
$pdo->throwOn = 'DELETE';
login_user_throttle_release('joao.silva', $pdo);
assert_same(true, true, 'falha ao zerar o contador nao derruba o login concluido');

assert_same(
    'Muitas tentativas de autenticação. Aguarde alguns minutos e tente novamente.',
    login_user_throttle_message('blocked'),
    'mensagem do bloqueio igual a do limite por IP (nao revela se a conta existe)'
);

// Toda chamada ao AD a partir de um ponto de entrada passa pelo contador antes.
// Ponto de entrada novo faz este teste falhar e exige revisao. A busca e por
// token do PHP: nome citado em comentario ou texto nao conta como chamada.
function qa_call_offsets(string $source, array $names): array {
    $offsets = [];
    $tokens = token_get_all($source);
    $offset = 0;
    $previous = null;
    foreach ($tokens as $index => $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (
            is_array($token)
            && $token[0] === T_STRING
            && in_array($token[1], $names, true)
            && $previous !== T_FUNCTION
            && ($tokens[$index + 1] ?? null) === '('
        ) {
            $offsets[] = $offset;
        }
        if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $previous = is_array($token) ? $token[0] : $token;
        }
        $offset += strlen($text);
    }
    return $offsets;
}

$adCallers = [];
$root = realpath(__DIR__ . '/..');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if ($file->getExtension() !== 'php' || preg_match('#^(\.git|frontend|vendor|scripts|node_modules)/#', $path)) {
        continue;
    }
    $offsets = qa_call_offsets((string)file_get_contents($file->getPathname()), ['autenticar_ad', 'autenticar_ci_via_ad']);
    if ($offsets !== []) {
        $adCallers[$path] = $offsets;
    }
}
ksort($adCallers);
assert_same(['admin_login.php', 'api/login_admin.php', 'api/login_ci.php', 'auth_ldap.php', 'login.php'], array_keys($adCallers), 'pontos de entrada que chamam o AD');
foreach ($adCallers as $path => $offsets) {
    $source = (string)file_get_contents($root . '/' . $path);
    if ($path === 'auth_ldap.php') {
        // Duas chamadas: autenticar_ad() dentro de autenticar_ci_via_ad() e a de
        // exigir_autenticacao_ci_pausa(), o unico ponto de entrada do arquivo.
        $start = (int)strpos($source, 'function exigir_autenticacao_ci_pausa(');
        $entryCalls = array_values(array_filter($offsets, static fn (int $offset): bool => $offset > $start));
        $throttle = array_values(array_filter(
            qa_call_offsets($source, ['require_login_user_throttle']),
            static fn (int $offset): bool => $offset > $start
        ));
        assert_same(2, count($offsets), 'auth_ldap.php chama o AD em dois pontos conhecidos');
        assert_same(true, $start > 0 && count($entryCalls) === 1 && $throttle !== [] && $throttle[0] < $entryCalls[0], 'reautenticacao de pausa passa pelo contador antes do AD');
        assert_same(true, qa_call_offsets($source, ['login_user_throttle_release']) !== [], 'reautenticacao de pausa zera o contador no sucesso');
        continue;
    }
    $throttle = qa_call_offsets($source, ['login_user_throttle_acquire', 'require_login_user_throttle']);
    assert_same(true, $throttle !== [] && $throttle[0] < min($offsets), "{$path} passa pelo contador antes do AD");
    assert_same(true, qa_call_offsets($source, ['login_user_throttle_release']) !== [], "{$path} zera o contador no login concluido");
}

// ============================================================================
// 2. CSV — formula neutralizada (SEC-11), implementacao unica
// ============================================================================
$csvCases = [
    ['=1+1', "'=1+1"],
    ['+5511999', "'+5511999"],
    ['-2+3', "'-2+3"],
    ['@SUM(A1)', "'@SUM(A1)"],
    [' =1', "' =1"],
    ["\t=1", "'\t=1"],
    ["\tTexto", "'\tTexto"],
    ["\rTexto", "'\rTexto"],
    ["\x01=1", "'\x01=1"],
    ["=HYPERLINK(\"x\")\xFF", "'=HYPERLINK(\"x\")\xFF"],
    ["\xFFtexto", "\xFFtexto"],
    ['Texto comum', 'Texto comum'],
    ['a=b', 'a=b'],
    ['10-2', '10-2'],
    ['Ação', 'Ação'],
    ['', ''],
];
foreach ($csvCases as [$input, $expected]) {
    assert_same($expected, csv_neutralize_cell($input), 'CSV neutraliza ' . json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE));
}

$csvRow = new ReflectionMethod(OperationalService::class, 'csvRow');
$service = (new ReflectionClass(OperationalService::class))->newInstanceWithoutConstructor();
$memory = fopen('php://memory', 'w+b');
$csvRow->invoke($service, $memory, ['=cmd|"/c calc"!A1', 'Normal', -5]);
rewind($memory);
assert_same("\"'=cmd|\"\"/c calc\"\"!A1\";Normal;'-5\n", stream_get_contents($memory), 'exportacao operacional grava a celula neutralizada');
fclose($memory);

foreach ([
    'services/OperationalService.php',
    'api/download_relatorio.php',
    'api/portal/critical_incidents_export.php',
] as $path) {
    $source = (string)file_get_contents($root . '/' . $path);
    assert_same(true, strpos($source, 'csv_neutralize_cell(') !== false, "{$path} usa a neutralizacao unica");
}
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if ($file->getExtension() !== 'php' || $path === 'security.php' || preg_match('#^(\.git|frontend|vendor|scripts|node_modules)/#', $path)) {
        continue;
    }
    assert_same(false, strpos((string)file_get_contents($file->getPathname()), '[=+\\-@]') !== false, "{$path} nao tem copia propria do regex de CSV");
}

// ============================================================================
// 3. QA-01 — endpoints reais em processo filho
// ============================================================================
function qa_run_endpoint(string $qaDir, array $spec): array {
    static $run = 0;
    $run++;
    $log = $qaDir . "/endpoint-{$run}.log";
    touch($log);
    $spec += ['csrf' => 'valid', 'session' => [], 'body' => null];
    $spec['error_log'] = $log;
    $command = [
        PHP_BINARY,
        '-d', 'display_errors=stderr',
        '-d', 'log_errors=1',
        '-d', 'error_log="' . $log . '"',
        '-d', 'sys_temp_dir="' . $qaDir . '"',
        '-d', 'session.save_path="' . $qaDir . '"',
        __FILE__,
        QA_RUNNER_FLAG,
        base64_encode((string)json_encode($spec)),
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: nao iniciou o processo filho\n");
        exit(1);
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if (!preg_match('/^QA_RESULT (.+)$/m', $stdout, $match)) {
        fwrite(STDERR, "FAIL: {$spec['endpoint']} nao devolveu resultado\n{$stdout}\n{$stderr}\n");
        exit(1);
    }
    $result = json_decode($match[1], true);
    $result['log'] = (string)file_get_contents($log);
    $result['stderr'] = $stderr;
    return $result;
}

function qa_assert_endpoint(array $result, int $status, string $message, string $label): void {
    assert_same($status, $result['status'], "{$label}: HTTP {$status}");
    assert_same(true, strpos($result['body'], $message) !== false, "{$label}: mensagem \"{$message}\"");
    assert_same('', $result['stderr'], "{$label}: sem erro de PHP");
    foreach ([$result['body'], $result['log']] as $text) {
        assert_same(false, strpos($text, QA_PASSWORD) !== false, "{$label}: senha nunca aparece na resposta nem no log");
    }
}

function qa_assert_no_ad(array $result, string $label): void {
    assert_same(false, strpos($result['log'], '[AUTH_AD]') !== false, "{$label}: AD nao consultado");
}

$json = 'application/json';
$credentials = static fn (string $loginField, string $passwordField, string $password): string
    => (string)json_encode([$loginField => 'joao.silva', $passwordField => $password]);
$technician = ['ci_logged_in' => true, 'ci_funcionario_id' => 1, 'ci_username' => 'joao.silva', 'ci_access_role' => 'tecnico', 'ci_login_time' => '__now__', 'ci_last_activity' => '__now__'];
$manager = ['logged_in' => true, 'username' => 'qa.gestor', 'portal_role' => 'gestor', 'login_time' => '__now__', 'last_activity' => '__now__'];

// Os arquivos reais de estado da aplicacao nao podem mudar durante o QA (no
// servidor sao os de producao).
function qa_state_snapshot(string $root): array {
    $snapshot = [];
    foreach (['pausas.csv', 'estado.json', 'config_sistema.json', 'funcionarios.json'] as $name) {
        $path = $root . '/' . $name;
        clearstatcache(true, $path);
        $snapshot[$name] = is_file($path) ? [filemtime($path), md5_file($path)] : null;
    }
    return $snapshot;
}
$stateBefore = qa_state_snapshot($root);

// POST sem CSRF -> 403, antes de qualquer autenticacao.
foreach (['missing' => 'sem token', 'invalid' => 'token invalido'] as $csrf => $label) {
    $result = qa_run_endpoint($qaDir, ['endpoint' => 'api/login_ci.php', 'method' => 'POST', 'content_type' => $json, 'csrf' => $csrf, 'body' => $credentials('login_ad', 'senha_ad', QA_PASSWORD)]);
    qa_assert_endpoint($result, 403, 'Token CSRF', "login CI {$label}");
    qa_assert_no_ad($result, "login CI {$label}");
}

// Senha vazia -> 400, sem AD e sem tocar o contador.
foreach ([
    ['api/login_ci.php', 'login_ad', 'senha_ad'],
    ['api/login_admin.php', 'username', 'password'],
] as [$endpoint, $loginField, $passwordField]) {
    $result = qa_run_endpoint($qaDir, ['endpoint' => $endpoint, 'method' => 'POST', 'content_type' => $json, 'body' => $credentials($loginField, $passwordField, '')]);
    qa_assert_endpoint($result, 400, 'Informe login e senha do AD.', "{$endpoint} com senha vazia");
    qa_assert_no_ad($result, "{$endpoint} com senha vazia");
    assert_same(false, strpos($result['log'], '[LOGIN_THROTTLE]') !== false, "{$endpoint} com senha vazia nao toca o contador");
}
$result = qa_run_endpoint($qaDir, ['endpoint' => 'admin_login.php', 'method' => 'POST', 'post' => ['username' => 'joao.silva', 'password' => '']]);
qa_assert_endpoint($result, 200, 'Informe seu login e senha do AD.', 'formulario administrativo com senha vazia');
qa_assert_no_ad($result, 'formulario administrativo com senha vazia');

// Contador indisponivel (banco fora) -> recusa sem consultar o AD.
foreach ([
    ['api/login_ci.php', 'login_ad', 'senha_ad'],
    ['api/login_admin.php', 'username', 'password'],
] as [$endpoint, $loginField, $passwordField]) {
    $result = qa_run_endpoint($qaDir, ['endpoint' => $endpoint, 'method' => 'POST', 'content_type' => $json, 'body' => $credentials($loginField, $passwordField, QA_PASSWORD)]);
    qa_assert_endpoint($result, 503, 'Não foi possível validar o login agora.', "{$endpoint} com contador indisponivel");
    qa_assert_no_ad($result, "{$endpoint} com contador indisponivel");
}
foreach (['admin_login.php', 'login.php'] as $endpoint) {
    $result = qa_run_endpoint($qaDir, ['endpoint' => $endpoint, 'method' => 'POST', 'post' => ['username' => 'joao.silva', 'password' => QA_PASSWORD]]);
    qa_assert_endpoint($result, 200, 'Não foi possível validar o login agora.', "formulario {$endpoint} com contador indisponivel");
    qa_assert_no_ad($result, "formulario {$endpoint} com contador indisponivel");
}
$result = qa_run_endpoint($qaDir, [
    'endpoint' => 'api/iniciar_pausa.php',
    'method' => 'POST',
    'content_type' => $json,
    'body' => (string)json_encode(['funcionario_id' => 1, 'motivo_pausa' => 'Café', 'login_ad' => 'joao.silva', 'senha_ad' => QA_PASSWORD]),
]);
qa_assert_endpoint($result, 503, 'Não foi possível validar o login agora.', 'reautenticacao AD da pausa com contador indisponivel');
qa_assert_no_ad($result, 'reautenticacao AD da pausa com contador indisponivel');

// Autorizacao: sem sessao -> 401; tecnico em endpoint de gestor -> 403.
$result = qa_run_endpoint($qaDir, ['endpoint' => 'api/portal/reports.php', 'method' => 'GET', 'csrf' => 'missing']);
qa_assert_endpoint($result, 401, 'Sessão expirada ou acesso não autorizado.', 'relatorio sem sessao');
$result = qa_run_endpoint($qaDir, ['endpoint' => 'api/portal/reports.php', 'method' => 'GET', 'csrf' => 'missing', 'session' => $technician]);
qa_assert_endpoint($result, 403, 'Seu perfil não possui permissão para este recurso.', 'tecnico no relatorio de gestor');
$result = qa_run_endpoint($qaDir, ['endpoint' => 'api/portal/documents_delete.php', 'method' => 'POST', 'content_type' => $json, 'body' => '{"id":1}', 'session' => $technician]);
qa_assert_endpoint($result, 403, 'Seu perfil não possui permissão para este recurso.', 'tecnico excluindo documento');

// Gestor com POST sem CSRF -> 403; controle: com CSRF a requisicao segue.
foreach (['missing' => 'sem token', 'invalid' => 'token invalido'] as $csrf => $label) {
    $result = qa_run_endpoint($qaDir, ['endpoint' => 'api/portal/documents_delete.php', 'method' => 'POST', 'content_type' => $json, 'csrf' => $csrf, 'body' => '{"id":1}', 'session' => $manager]);
    qa_assert_endpoint($result, 403, 'Token CSRF', "gestor excluindo documento {$label}");
}
$result = qa_run_endpoint($qaDir, ['endpoint' => 'api/portal/documents_delete.php', 'method' => 'POST', 'content_type' => $json, 'body' => '{"id":0}', 'session' => $manager]);
qa_assert_endpoint($result, 400, 'Documento invalido.', 'controle: gestor com CSRF valido passa da checagem');

assert_same($stateBefore, qa_state_snapshot($root), 'endpoints do QA nao alteram os arquivos reais de estado');
clearstatcache();
assert_same(true, is_file($qaDir . '/pausas.csv'), 'controle: init.php dos endpoints gravou no diretorio do QA');

echo "qa-security OK\n";
