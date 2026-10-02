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

// Banco simulado (Lote 5a): responde so as consultas da revalidacao da
// sessao (A1) e aceita o audit_log, que vai para o log do processo como
// "[QA_AUDIT] ACAO [SEVERIDADE]: detalhe"; qualquer outra consulta falha como
// banco fora. Usado pelo processo filho e pelos testes de unidade.
final class QaChildPdo extends PDO {
    public function __construct(public array $db) {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaChildStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (trim($query) === 'SELECT 1 FROM audit_log LIMIT 1') {
            return new QaChildStatement($this, 'SELECT 1 FROM audit_log LIMIT 1');
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista');
    }

    public function beginTransaction(): bool {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: transacao nao prevista');
    }

    public function inTransaction(): bool {
        return false;
    }
}

final class QaChildStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaChildPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params = $params ?? [];
        if ($this->sql === 'SELECT ativo, access_role, equipe, ad_login FROM funcionarios WHERE id = ?') {
            $row = $this->pdo->db['funcionarios'][(string)$params[0]] ?? null;
            $this->rows = is_array($row) ? [$row] : [];
            return true;
        }
        if ($this->sql === 'SELECT role FROM usuarios WHERE username = ?') {
            $role = $this->pdo->db['usuarios'][(string)$params[0]] ?? null;
            $this->rows = $role !== null ? [['role' => $role]] : [];
            return true;
        }
        if ($this->sql === 'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)') {
            error_log("[QA_AUDIT] {$params[2]} [{$params[4]}]: {$params[3]}");
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista');
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista');
    }

    public function bindParam(int|string $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista');
    }
}

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
    // Banco simulado (Lote 5a): responde so as consultas da revalidacao da
    // sessao (A1); qualquer outra falha como banco fora. Sem 'db', o banco
    // real aponta para 127.0.0.1:1 e nada responde.
    if (is_array($spec['db'] ?? null)) {
        $GLOBALS['qa_child_pdo'] = new QaChildPdo($spec['db']);
        function get_db_connection(): PDO {
            return $GLOBALS['qa_child_pdo'];
        }
    }

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
    public array $registered = [];

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
        } elseif ($sql === 'SELECT 1 FROM funcionarios WHERE ad_login = ? UNION ALL SELECT 1 FROM usuarios WHERE username = ? LIMIT 1') {
            if ($params[0] !== $params[1]) {
                throw new LogicException('consulta de cadastro com chaves divergentes');
            }
            $pdo->log[] = 'REGISTERED ' . $params[0];
            $this->result = in_array($params[0], $pdo->registered, true) ? [[1 => 1]] : [];
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

    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->result);
        return $row === null ? false : reset($row);
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

// audit_log do bloqueio: login normalizado so se a conta existir no cadastro.
// A normalizacao corta no "@": uma senha digitada no campo de usuario, como
// "Senha@", viraria "senha" gravada para sempre.
$pdo = new QaThrottlePdo();
$pdo->registered = ['joao.silva'];
assert_same(
    'contexto=admin_api login=joao.silva',
    login_audit_details('JOAO.SILVA@corp.local', 'admin_api', $pdo),
    'audit_log: conta cadastrada grava o login normalizado'
);
assert_same(['REGISTERED joao.silva'], $pdo->log, 'audit_log: cadastro consultado pela chave normalizada');
foreach (['Senha@' => 'senha', 'Senha2026' => 'senha2026', 'Maria.Inexistente@corp.local' => 'maria.inexistente'] as $typed => $normalized) {
    $details = login_audit_details($typed, 'admin_api', $pdo);
    assert_same('contexto=admin_api login_cadastrado=false', $details, "audit_log: login fora do cadastro ({$typed}) sem o texto digitado");
    assert_same(false, stripos($details, $normalized) !== false, "audit_log: nem a forma normalizada de {$typed} e gravada");
}
$pdo->log = [];
assert_same('contexto=ci_login login_cadastrado=false', login_audit_details('usuario invalido', 'ci_login', $pdo), 'audit_log: login sem chave nao e gravado');
assert_same([], $pdo->log, 'audit_log: login sem chave nao consulta o cadastro');
$pdo = new QaThrottlePdo();
$pdo->registered = ['joao.silva'];
$pdo->throwOn = 'FROM funcionarios';
assert_same('contexto=admin_api login_cadastrado=false', login_audit_details('joao.silva', 'admin_api', $pdo), 'audit_log: falha no cadastro nao grava o login');
$pdo = new QaThrottlePdo();
$pdo->registered = ['joao.silva'];
$pdo->falseOn = 'FROM funcionarios';
assert_same('contexto=admin_api login_cadastrado=false', login_audit_details('joao.silva', 'admin_api', $pdo), 'audit_log: consulta de cadastro com execute false nao grava o login');

// Resposta HTTP: depende so do resultado do contador (o login nem chega a ela).
assert_same(
    [['sucesso' => false, 'mensagem' => 'Muitas tentativas de autenticação. Aguarde alguns minutos e tente novamente.'], 429],
    login_user_throttle_json_response('blocked'),
    'API: bloqueio por usuario responde 429 com a mensagem unica'
);
assert_same(
    [['sucesso' => false, 'mensagem' => 'Não foi possível validar o login agora. Tente novamente em instantes.'], 503],
    login_user_throttle_json_response('unavailable'),
    'API: contador indisponivel responde 503'
);

// Os eventos do contador so sao gravados por login_user_throttle_audit(); os
// formularios que chamam o contador direto passam por ela.
foreach (['login.php', 'admin_login.php'] as $file) {
    $source = (string)file_get_contents(__DIR__ . '/../' . $file);
    assert_same(1, substr_count($source, 'login_user_throttle_audit($throttle, $username_input,'), "{$file}: recusa do contador gravada pela funcao unica");
}
$auditEventFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath(__DIR__ . '/..'), FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = str_replace('\\', '/', (string)$file);
    if (substr($path, -4) !== '.php' || preg_match('#/(node_modules|vendor|scripts|\.git)/#', $path)) {
        continue;
    }
    $source = (string)file_get_contents($path);
    if (strpos($source, "'LOGIN_USER_THROTTLED'") !== false || strpos($source, "'LOGIN_THROTTLE_UNAVAILABLE'") !== false) {
        $auditEventFiles[] = basename($path);
    }
}
assert_same(['security.php'], $auditEventFiles, 'eventos do contador gravados so em security.php');

// Eventos de login recusado (Lote 7b): toda ocorrencia do nome do evento e o
// primeiro argumento de audit_log(), e o segundo e login_audit_details(). Nada
// de texto digitado, concatenacao ou evento vindo de variavel. Busca por token
// do PHP: comentario e texto nao contam.
$loginFailureEvents = ['LOGIN_USER_THROTTLED', 'ADMIN_LOGIN_FAILURE', 'CI_LOGIN_FAILURE', 'CI_AD_LOGIN_FAILURE', 'CI_LOGIN_RATE_LIMIT'];
function qa_significant_tokens(string $source): array {
    return array_values(array_filter(token_get_all($source), static fn ($token): bool
        => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
}
function qa_token_text($token): string {
    return is_array($token) ? $token[1] : $token;
}
$loginEventSites = [];
$loginEventViolations = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath(__DIR__ . '/..'), FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = str_replace('\\', '/', (string)$file);
    if (substr($path, -4) !== '.php' || preg_match('#/(node_modules|vendor|scripts|\.git)/#', $path)) {
        continue;
    }
    $tokens =qa_significant_tokens((string)file_get_contents($path));
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || !in_array(trim($token[1], "'\""), $loginFailureEvents, true)) {
            continue;
        }
        $where = basename($path) . ':' . $token[2];
        $valid = qa_token_text($tokens[$i - 2] ?? '') === 'audit_log'
            && qa_token_text($tokens[$i - 1] ?? '') === '('
            && qa_token_text($tokens[$i + 1] ?? '') === ','
            && qa_token_text($tokens[$i + 2] ?? '') === 'login_audit_details'
            && qa_token_text($tokens[$i + 3] ?? '') === '(';
        if ($valid) {
            // O argumento termina no parentese que fecha login_audit_details();
            // depois dele so pode vir a virgula da severidade.
            $depth = 0;
            for ($j = $i + 3; $j < count($tokens); $j++) {
                $text = qa_token_text($tokens[$j]);
                $depth += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            $valid = qa_token_text($tokens[$j + 1] ?? '') === ',';
        }
        $valid ? $loginEventSites[] = $where : $loginEventViolations[] = $where;
    }
}
assert_same([], $loginEventViolations, 'eventos de login recusado so citam o login por login_audit_details()');
assert_same(9, count($loginEventSites), 'eventos de login recusado: todos os pontos de gravacao conhecidos (ponto novo exige revisao)');

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
// Banco simulado dos processos filhos: so as linhas que a revalidacao da sessao
// (A1) consulta. Login AD do admin vem da allowlist do filho (qa.admin).
const QA_DB = [
    'funcionarios' => [
        '1' => ['ativo' => 1, 'access_role' => 'tecnico', 'equipe' => 'n1', 'ad_login' => 'joao.silva'],
        '2' => ['ativo' => 1, 'access_role' => 'lideranca', 'equipe' => 'lideranca', 'ad_login' => 'qa.lider'],
        '3' => ['ativo' => 1, 'access_role' => 'gestor', 'equipe' => 'n2', 'ad_login' => 'qa.gestor'],
        '4' => ['ativo' => 1, 'access_role' => 'somente_leitura', 'equipe' => 'n1', 'ad_login' => 'qa.leitura'],
        '5' => ['ativo' => 1, 'access_role' => 'admin', 'equipe' => 'lideranca', 'ad_login' => 'qa.lider.legado'],
        '6' => ['ativo' => 1, 'access_role' => 'admin', 'equipe' => 'n2', 'ad_login' => 'qa.n2.legado'],
        '7' => ['ativo' => 1, 'access_role' => 'tecnico', 'equipe' => 'n2', 'ad_login' => 'qa.admin'],
    ],
    'usuarios' => [],
];

// Sessao como login_ci.php a monta: CI e, para perfis de gestao, a sessao
// elevada.
function qa_ci_session(int $id, string $login, string $role): array {
    $session = [
        'ci_logged_in' => true, 'ci_funcionario_id' => $id, 'ci_username' => $login, 'ci_nome' => 'QA',
        'ci_access_role' => $role, 'ci_login_time' => '__now__', 'ci_last_activity' => '__now__',
    ];
    if (portal_role_is_manager($role)) {
        $session += [
            'ci_elevated_session' => true, 'admin_logged_in' => $role === 'admin', 'admin_auth_type' => 'employee_role',
            'admin_username' => $login, 'portal_role' => $role, 'logged_in' => true, 'username' => $login,
            'login_time' => '__now__', 'last_activity' => '__now__',
        ];
    }
    return $session;
}

const QA_ADMIN_SESSION = [
    'logged_in' => true, 'admin_logged_in' => true, 'admin_auth_type' => 'ad', 'admin_username' => 'qa.admin',
    'portal_role' => 'admin', 'username' => 'qa.admin', 'login_time' => '__now__', 'last_activity' => '__now__',
];

function qa_start_endpoint(string $qaDir, array $spec): array {
    static $run = 0;
    $run++;
    $log = $qaDir . "/endpoint-{$run}.log";
    touch($log);
    $spec += ['csrf' => 'valid', 'session' => [], 'body' => null];
    // Com sessao, o banco simulado responde a revalidacao; 'db' => false
    // simula o banco fora.
    $spec += ['db' => $spec['session'] ? QA_DB : false];
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
    return ['process' => $process, 'pipes' => $pipes, 'log' => $log, 'endpoint' => $spec['endpoint']];
}

function qa_finish_endpoint(array $handle): array {
    $stdout = (string)stream_get_contents($handle['pipes'][1]);
    $stderr = (string)stream_get_contents($handle['pipes'][2]);
    fclose($handle['pipes'][1]);
    fclose($handle['pipes'][2]);
    proc_close($handle['process']);

    if (!preg_match('/^QA_RESULT (.+)$/m', $stdout, $match)) {
        fwrite(STDERR, "FAIL: {$handle['endpoint']} nao devolveu resultado\n{$stdout}\n{$stderr}\n");
        exit(1);
    }
    $result = json_decode($match[1], true);
    $result['log'] = (string)file_get_contents($handle['log']);
    $result['stderr'] = $stderr;
    return $result;
}

function qa_run_endpoint(string $qaDir, array $spec): array {
    return qa_finish_endpoint(qa_start_endpoint($qaDir, $spec));
}

/** Varios endpoints em paralelo; devolve os resultados nas mesmas chaves. */
function qa_run_endpoints(string $qaDir, array $specs, int $parallel = 8): array {
    $results = [];
    foreach (array_chunk($specs, $parallel, true) as $chunk) {
        $handles = [];
        foreach ($chunk as $key => $spec) {
            $handles[$key] = qa_start_endpoint($qaDir, $spec);
        }
        foreach ($handles as $key => $handle) {
            $results[$key] = qa_finish_endpoint($handle);
        }
    }
    return $results;
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

// Banco fora: o audit_log cai no error_log. Grava o evento com o contexto e
// nunca o login digitado.
function qa_assert_unavailable_audit(array $result, string $label): void {
    assert_same(true, strpos($result['log'], 'LOGIN_THROTTLE_UNAVAILABLE: contexto=') !== false, "{$label}: evento registrado com o contexto");
    assert_same(false, stripos($result['log'], 'joao.silva') !== false, "{$label}: login digitado fora do log");
}

$json = 'application/json';
$credentials = static fn (string $loginField, string $passwordField, string $password): string
    => (string)json_encode([$loginField => 'joao.silva', $passwordField => $password]);
$technician = qa_ci_session(1, 'joao.silva', 'tecnico');
$manager = qa_ci_session(3, 'qa.gestor', 'gestor');

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
    qa_assert_unavailable_audit($result, "{$endpoint} com contador indisponivel");
}
foreach (['admin_login.php', 'login.php'] as $endpoint) {
    $result = qa_run_endpoint($qaDir, ['endpoint' => $endpoint, 'method' => 'POST', 'post' => ['username' => 'joao.silva', 'password' => QA_PASSWORD]]);
    qa_assert_endpoint($result, 200, 'Não foi possível validar o login agora.', "formulario {$endpoint} com contador indisponivel");
    qa_assert_no_ad($result, "formulario {$endpoint} com contador indisponivel");
    qa_assert_unavailable_audit($result, "formulario {$endpoint} com contador indisponivel");
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

// ============================================================================
// 4. Lote 5a — perfis, allowlist e revalidacao da sessao
// ============================================================================

// QA 6: perfil da sessao a partir das fontes (L1).
foreach ([
    [true, 'tecnico', 'n1', 'admin', 'allowlist da admin mesmo a tecnico'],
    [true, 'admin', 'lideranca', 'admin', 'allowlist com admin legado'],
    [false, 'admin', 'lideranca', 'lideranca', 'admin legado na Lideranca vira lideranca'],
    [false, 'admin', 'Liderança', 'lideranca', 'admin legado com a grafia antiga da equipe'],
    [false, 'admin', 'n2', 'gestor', 'admin legado fora da Lideranca vira gestor'],
    [false, 'ADMIN', 'n1', 'gestor', 'admin legado em maiusculas'],
    [false, 'lideranca', 'n1', 'lideranca', 'perfil lideranca independe da equipe'],
    [false, 'gestor', 'lideranca', 'gestor', 'equipe Lideranca nao eleva gestor'],
    [false, 'tecnico', 'lideranca', 'tecnico', 'equipe Lideranca nao eleva tecnico'],
    [false, 'somente_leitura', 'n1', 'somente_leitura', 'somente leitura'],
    [false, null, 'n1', 'tecnico', 'perfil ausente'],
    [false, 'root', 'n1', 'tecnico', 'perfil desconhecido'],
] as [$allowlisted, $accessRole, $team, $expected, $label]) {
    assert_same($expected, session_role_for($allowlisted, $accessRole, $team), "perfil da sessao: {$label}");
}

// Permissoes: gestor contido na Lideranca, Lideranca contida no admin.
$gestorPermissions = portal_permissions_for_role('gestor');
$leaderPermissions = portal_permissions_for_role('lideranca');
$adminPermissions = portal_permissions_for_role('admin');
assert_same([], array_values(array_diff($gestorPermissions, $leaderPermissions)), 'Lideranca tem tudo o que o gestor tem');
assert_same([], array_values(array_diff($leaderPermissions, $adminPermissions)), 'admin tem tudo o que a Lideranca tem');
foreach (['funcionarios.manage', 'escalas.manage', 'pa_map.manage', 'pausas.force_end', 'operacao.approve', 'ausencias.manage'] as $permission) {
    assert_same(true, in_array($permission, $leaderPermissions, true), "Lideranca recebe {$permission}");
}
foreach (['configuracoes.manage', 'integracoes.manage', 'usuarios_locais.manage', 'perfis.promote', 'admin.manage'] as $permission) {
    assert_same(false, in_array($permission, $leaderPermissions, true), "Lideranca nao recebe {$permission}");
    assert_same(true, in_array($permission, $adminPermissions, true), "admin recebe {$permission}");
}
foreach (['funcionarios.manage', 'escalas.manage', 'pa_map.manage', 'pausas.force_end'] as $permission) {
    assert_same(false, in_array($permission, $gestorPermissions, true), "gestor nao recebe {$permission}");
}
assert_same(true, portal_role_is_manager('lideranca'), 'Lideranca passa onde o gestor passa');
assert_same(false, portal_role_is_manager('tecnico'), 'tecnico nao e perfil de gestao');

// QA 7 (leitura da sessao): lideranca nao cai para gestor; portal_role admin
// sem admin_logged_in nao vira admin.
foreach ([
    [['logged_in' => true, 'portal_role' => 'lideranca'], 'lideranca'],
    [['logged_in' => true, 'portal_role' => 'gestor'], 'gestor'],
    [['logged_in' => true, 'portal_role' => 'admin'], 'gestor'],
    [['logged_in' => true, 'portal_role' => 'qualquer'], 'gestor'],
    [['logged_in' => true, 'admin_logged_in' => true], 'admin'],
    [['ci_logged_in' => true, 'ci_access_role' => 'lideranca'], 'lideranca'],
    [['ci_logged_in' => true, 'ci_access_role' => 'invalido'], 'tecnico'],
    [[], null],
] as $i => [$session, $expected]) {
    assert_same($expected, session_stored_role($session), "perfil guardado na sessao, caso {$i}");
}

// A2: allowlist normalizada como o bind.
assert_same(
    ['qa.admin', 'outro', 'maria', 'usuario'],
    ad_admin_users_from(' QA.Admin , CORP\outro, maria@corp.local, x@externo.com, , CORP\, usuario@, qa.admin'),
    'allowlist: minusculas, DOMINIO\ retirado, sufixo permitido, entrada invalida fora, sem repeticao'
);
assert_same(true, is_ad_admin_authorized('QA.ADMIN@corp.local'), 'allowlist: login com sufixo permitido');
assert_same(true, is_ad_admin_authorized('qa.admin@'), 'allowlist: "usuario@" como o bind');
assert_same(false, is_ad_admin_authorized('qa.admin@externo.com'), 'allowlist: sufixo nao permitido');
assert_same(false, is_ad_admin_authorized('CORP\qa.admin'), 'allowlist: DOMINIO\ no login e recusado, como no bind');
assert_same(false, is_ad_admin_authorized(''), 'allowlist: login vazio');

// A1: quando a revalidacao encerra a sessao.
foreach ([
    ['gestor', null, true], ['gestor', 'tecnico', true], ['admin', 'lideranca', true], ['lideranca', 'gestor', true],
    ['gestor', 'gestor', false], ['tecnico', 'gestor', false], ['lideranca', 'admin', false],
] as [$stored, $current, $expected]) {
    assert_same($expected, session_revalidation_revokes($stored, $current), "revalidacao: {$stored} -> " . ($current ?? 'nenhum'));
}

// A1: perfil atual pelas fontes, contra o banco simulado.
$sessionDb = new QaChildPdo(QA_DB);
foreach ([
    [qa_ci_session(1, 'joao.silva', 'tecnico'), ['tecnico', 'ok'], 'CI tecnico'],
    [qa_ci_session(2, 'qa.lider', 'lideranca'), ['lideranca', 'ok'], 'CI lideranca'],
    [qa_ci_session(5, 'qa.lider.legado', 'lideranca'), ['lideranca', 'ok'], 'admin legado na Lideranca'],
    [qa_ci_session(6, 'qa.n2.legado', 'gestor'), ['gestor', 'ok'], 'admin legado no N2'],
    [qa_ci_session(7, 'qa.admin', 'admin'), ['admin', 'ok'], 'CI na allowlist'],
    [qa_ci_session(99, 'ninguem', 'tecnico'), [null, 'cadastro_inexistente'], 'cadastro inexistente'],
    [qa_ci_session(1, 'outro.login', 'tecnico'), [null, 'ad_login_alterado'], 'login da sessao diferente do cadastro'],
] as [$session, $expected, $label]) {
    assert_same($expected, session_current_role($session, $sessionDb), "perfil atual: {$label}");
}
$inactiveDb = QA_DB;
$inactiveDb['funcionarios']['1']['ativo'] = 0;
assert_same([null, 'cadastro_inativo'], session_current_role(qa_ci_session(1, 'joao.silva', 'tecnico'), new QaChildPdo($inactiveDb)), 'perfil atual: cadastro inativo');
// Sessao de gestao por AD e local: sem consulta ao cadastro de funcionarios.
$noQueryDb = new QaThrottlePdo();
$noQueryDb->throwOn = 'SELECT';
assert_same(['admin', 'ok'], session_current_role(QA_ADMIN_SESSION, $noQueryDb), 'perfil atual: admin por AD so pela allowlist');
assert_same([null, 'fora_da_allowlist'], session_current_role(['admin_username' => 'ex.admin'] + QA_ADMIN_SESSION, $noQueryDb), 'perfil atual: fora da allowlist');
assert_same([null, 'acesso_local_desligado'], session_current_role(['admin_auth_type' => 'local'] + QA_ADMIN_SESSION, $noQueryDb), 'perfil atual: acesso local desligado');
foreach (['throwOn' => 'FROM funcionarios', 'falseOn' => 'FROM funcionarios'] as $mode => $needle) {
    $failingDb = new QaThrottlePdo();
    $failingDb->{$mode} = $needle;
    $failed = false;
    try {
        session_current_role(qa_ci_session(1, 'joao.silva', 'tecnico'), $failingDb);
    } catch (Throwable $e) {
        $failed = true;
    }
    assert_same(true, $failed, "perfil atual: erro do banco ({$mode}) propaga, sem perfil");
}

// A1 e QA 7 de ponta a ponta, em processo filho.
$demotedDb = QA_DB;
$demotedDb['funcionarios']['3']['access_role'] = 'tecnico';
$promotedDb = QA_DB;
$promotedDb['funcionarios']['1']['access_role'] = 'gestor';
$changedLoginDb = QA_DB;
$changedLoginDb['funcionarios']['1']['ad_login'] = 'outro.login';
$legacyAdminSession = ['admin_logged_in' => true, 'portal_role' => 'admin'] + qa_ci_session(6, 'qa.n2.legado', 'gestor');
$sessionCases = qa_run_endpoints($qaDir, [
    'lideranca' => ['endpoint' => 'api/session.php', 'method' => 'GET', 'session' => qa_ci_session(2, 'qa.lider', 'lideranca')],
    'legado' => ['endpoint' => 'api/session.php', 'method' => 'GET', 'session' => qa_ci_session(5, 'qa.lider.legado', 'lideranca')],
    'rebaixado' => ['endpoint' => 'api/portal/reports.php', 'method' => 'GET', 'session' => $manager, 'db' => $demotedDb],
    'inativo' => ['endpoint' => 'api/status.php', 'method' => 'GET', 'session' => $technician, 'db' => $inactiveDb],
    'inativo_sessao' => ['endpoint' => 'api/session.php', 'method' => 'GET', 'session' => $technician, 'db' => $inactiveDb],
    'login_trocado' => ['endpoint' => 'api/status.php', 'method' => 'GET', 'session' => $technician, 'db' => $changedLoginDb],
    'fora_allowlist' => ['endpoint' => 'api/configuracoes.php', 'method' => 'GET', 'session' => ['admin_username' => 'ex.admin', 'username' => 'ex.admin'] + QA_ADMIN_SESSION],
    'admin_legado' => ['endpoint' => 'api/configuracoes.php', 'method' => 'GET', 'session' => $legacyAdminSession],
    'promovido' => ['endpoint' => 'api/portal/reports.php', 'method' => 'GET', 'session' => $technician, 'db' => $promotedDb],
    'banco_fora' => ['endpoint' => 'api/portal/reports.php', 'method' => 'GET', 'session' => $manager, 'db' => false],
]);
foreach (['lideranca', 'legado'] as $case) {
    $body = json_decode($sessionCases[$case]['body'], true);
    assert_same('lideranca', $body['role'] ?? null, "sessao {$case}: perfil lideranca");
    assert_same(true, in_array('funcionarios.manage', $body['permissions'] ?? [], true), "sessao {$case}: permissoes da Lideranca");
    assert_same(false, in_array('configuracoes.manage', $body['permissions'] ?? [], true), "sessao {$case}: sem configuracoes");
    assert_same(false, $body['gestor']['admin'] ?? null, "sessao {$case}: nao e admin");
}
foreach ([
    'rebaixado' => 'perfil gestor -> tecnico, motivo ok',
    'inativo' => 'perfil tecnico -> nenhum, motivo cadastro_inativo',
    'login_trocado' => 'perfil tecnico -> nenhum, motivo ad_login_alterado',
    'fora_allowlist' => 'perfil admin -> nenhum, motivo fora_da_allowlist',
    'admin_legado' => 'perfil admin -> gestor, motivo ok',
] as $case => $detail) {
    assert_same(401, $sessionCases[$case]['status'], "revalidacao {$case}: sessao encerrada (401)");
    assert_same(true, strpos($sessionCases[$case]['log'], 'SESSION_REVOKED') !== false && strpos($sessionCases[$case]['log'], $detail) !== false, "revalidacao {$case}: SESSION_REVOKED com '{$detail}'");
}
$inactiveSession = json_decode($sessionCases['inativo_sessao']['body'], true);
assert_same([false, 0, null], [$inactiveSession['ci']['autenticado'] ?? null, $inactiveSession['ci']['funcionario_id'] ?? null, array_key_exists('role', $inactiveSession) ? $inactiveSession['role'] : 'ausente'], 'revalidacao: api/session.php nao mostra CI de cadastro inativo');
assert_same(403, $sessionCases['promovido']['status'], 'revalidacao: promocao nao eleva a sessao aberta');
assert_same(false, strpos($sessionCases['promovido']['log'], 'SESSION_REVOKED') !== false, 'revalidacao: promocao nao encerra a sessao');
assert_same(503, $sessionCases['banco_fora']['status'], 'revalidacao: banco fora responde 503');
assert_same(true, strpos($sessionCases['banco_fora']['body'], 'Não foi possível validar a sessão') !== false, 'revalidacao: mensagem de banco fora');
assert_same(false, strpos($sessionCases['banco_fora']['log'], 'SESSION_REVOKED') !== false, 'revalidacao: banco fora nao encerra a sessao');

// QA 11: matriz de perfis por endpoint, no estado do 5a. 'ok' = passa da
// checagem de perfil (nem 401 nem 403); [status, trecho] = resposta de regra
// de negocio esperada. Endpoint novo sem linha aqui reprova.
$roles = ['admin', 'lideranca', 'gestor', 'tecnico', 'somente_leitura', 'nenhum'];
$expect = [
    'TODOS' => ['ok', 'ok', 'ok', 'ok', 'ok', 401],
    'GESTAO' => ['ok', 'ok', 'ok', 403, 403, 401],
    'ADMIN' => ['ok', 403, 403, 403, 403, 401],
    // verificar_admin_login_api(): sem sessao de gestao a resposta e 401.
    'ADMIN_LEGADO' => ['ok', 403, 403, 401, 401, 401],
];
$emptyJson = ['content_type' => $json, 'body' => '{}'];
$matrix = [
    'api/adicionar_funcionario.php POST' => [$emptyJson, $expect['ADMIN_LEGADO']],
    'api/alterar_senha_admin.php POST' => [$emptyJson, [[403, 'alterada no Active Directory']] + $expect['ADMIN_LEGADO']],
    'api/aprovar_pausa.php POST' => [$emptyJson, $expect['GESTAO']],
    'api/atualizar_funcionario.php POST' => [$emptyJson, $expect['ADMIN_LEGADO']],
    'api/configuracoes.php GET' => [[], $expect['ADMIN']],
    'api/download_relatorio.php GET' => [[], $expect['GESTAO']],
    'api/listar_funcionarios.php GET' => [[], $expect['TODOS']],
    'api/metricas.php GET' => [[], $expect['GESTAO']],
    'api/rejeitar_pausa.php POST' => [$emptyJson, $expect['GESTAO']],
    'api/remover_funcionario.php POST' => [$emptyJson, $expect['ADMIN_LEGADO']],
    'api/salvar_configuracao.php POST' => [$emptyJson, $expect['ADMIN_LEGADO']],
    'api/solicitacoes_pendentes.php GET' => [[], $expect['GESTAO']],
    'api/status.php GET' => [[], $expect['TODOS']],
    'api/usuarios.php GET' => [[], [[403, 'usuarios locais esta desativado']] + $expect['ADMIN_LEGADO']],
    'api/portal/absences.php GET' => [[], $expect['TODOS']],
    'api/portal/admin_force_end_break.php POST' => [$emptyJson, $expect['ADMIN']],
    'api/portal/announcements.php GET' => [[], $expect['TODOS']],
    'api/portal/calendar.php GET' => [[], $expect['TODOS']],
    'api/portal/calendar.php POST' => [$emptyJson, $expect['GESTAO']],
    'api/portal/critical_incidents.php GET' => [[], $expect['TODOS']],
    'api/portal/critical_incidents.php POST' => [['content_type' => $json, 'body' => '{"action":"status","id":1}'], $expect['GESTAO']],
    'api/portal/critical_incidents_export.php GET' => [[], $expect['TODOS']],
    'api/portal/critical_incidents_import.php POST' => [$emptyJson, $expect['GESTAO']],
    'api/portal/dashboard.php GET' => [[], $expect['TODOS']],
    'api/portal/documents.php GET' => [[], $expect['TODOS']],
    'api/portal/documents.php POST' => [['content_type' => 'multipart/form-data', 'body' => 'x'], $expect['GESTAO']],
    'api/portal/documents_delete.php POST' => [['content_type' => $json, 'body' => '{"id":0}'], $expect['GESTAO']],
    'api/portal/documents_download.php GET' => [[], $expect['TODOS']],
    'api/portal/integrations.php GET' => [[], $expect['ADMIN']],
    'api/portal/notifications.php GET' => [[], $expect['TODOS']],
    'api/portal/oncall.php GET' => [[], $expect['TODOS']],
    'api/portal/oncall.php POST' => [$emptyJson, $expect['GESTAO']],
    'api/portal/overtime.php GET' => [[], $expect['TODOS']],
    'api/portal/overtime.php POST' => [['content_type' => $json, 'body' => '{"action":"decision","id":0,"decision":"approved"}'], $expect['GESTAO']],
    'api/portal/pa_map.php GET' => [[], $expect['TODOS']],
    'api/portal/pa_map.php POST' => [$emptyJson, $expect['ADMIN']],
    'api/portal/reports.php GET' => [[], $expect['GESTAO']],
    'api/portal/schedules.php GET' => [[], $expect['TODOS']],
    'api/portal/schedules.php POST' => [['content_type' => $json, 'body' => '{"action":"rule"}'], $expect['GESTAO']],
    'api/portal/schedules.php POST remove_rule' => [['content_type' => $json, 'body' => '{"action":"remove_rule"}'], $expect['ADMIN']],
    'api/portal/sdk_responsibles.php GET' => [[], $expect['TODOS']],
    // POST de anexo de escala: a checagem de perfil fica no servico, depois da
    // validacao do arquivo; coberta no 5c.
    'api/portal/shift_attachments.php GET' => [[], $expect['TODOS']],
    'api/portal/shift_attachments_download.php GET' => [[], $expect['TODOS']],
    'api/portal/standby.php GET' => [[], $expect['TODOS']],
    'api/portal/technicians.php GET' => [[], $expect['TODOS']],
    'api/portal/time_corrections.php GET' => [[], $expect['TODOS']],
    'api/portal/time_corrections.php POST' => [['content_type' => $json, 'body' => '{"action":"decision","id":0,"decision":"approved"}'], $expect['GESTAO']],
];
// Fora da matriz, com o motivo: publicos, saude restrita por IP e pausas
// autenticadas por credencial do AD a cada requisicao.
$matrixExempt = [
    'api/health.php', 'api/login_admin.php', 'api/login_ci.php', 'api/logout.php', 'api/logout_ci.php', 'api/session.php',
    'api/iniciar_pausa.php', 'api/finalizar_pausa.php', 'api/solicitar_pausa_com_aprovacao.php',
];
$covered = array_unique(array_map(static fn (string $row): string => explode(' ', $row)[0], array_keys($matrix)));
$endpointFiles = array_merge(glob($root . '/api/*.php'), glob($root . '/api/portal/*.php'));
$missing = [];
foreach ($endpointFiles as $file) {
    $relative = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
    if (basename($relative) !== '_bootstrap.php' && !in_array($relative, $covered, true) && !in_array($relative, $matrixExempt, true)) {
        $missing[] = $relative;
    }
}
assert_same([], $missing, 'matriz de perfis: todo endpoint tem linha ou esta na lista de excecoes');

$roleSessions = [
    'admin' => QA_ADMIN_SESSION,
    'lideranca' => qa_ci_session(2, 'qa.lider', 'lideranca'),
    'gestor' => qa_ci_session(3, 'qa.gestor', 'gestor'),
    'tecnico' => qa_ci_session(1, 'joao.silva', 'tecnico'),
    'somente_leitura' => qa_ci_session(4, 'qa.leitura', 'somente_leitura'),
    'nenhum' => [],
];
$matrixSpecs = [];
foreach ($matrix as $row => [$extra, $expected]) {
    [$endpoint, $method] = explode(' ', $row);
    foreach ($roles as $role) {
        $matrixSpecs["{$row} | {$role}"] = ['endpoint' => $endpoint, 'method' => $method, 'session' => $roleSessions[$role], 'db' => QA_DB] + $extra;
    }
}
$matrixResults = qa_run_endpoints($qaDir, $matrixSpecs);
$matrixFailures = [];
foreach ($matrix as $row => [$extra, $expected]) {
    foreach ($roles as $i => $role) {
        $result = $matrixResults["{$row} | {$role}"];
        $want = $expected[$i];
        if ($want === 'ok') {
            $passed = !in_array($result['status'], [401, 403], true) && strpos($result['body'], 'Não foi possível validar a sessão') === false;
        } elseif (is_array($want)) {
            $passed = $result['status'] === $want[0] && strpos($result['body'], $want[1]) !== false;
        } else {
            $passed = $result['status'] === $want;
        }
        if (!$passed) {
            $matrixFailures[] = "{$row} | {$role}: esperado " . json_encode($want) . ", obtido {$result['status']}";
        }
        // Erro fatal de codigo reprova; banco simulado fora nao (alguns
        // endpoints nao tratam banco indisponivel, comportamento anterior).
        preg_match_all('/^.*Fatal.*$/m', $result['log'] . "\n" . $result['stderr'], $fatal);
        $codeFatal = array_values(array_filter($fatal[0], static fn (string $line): bool => strpos($line, 'banco simulado') === false));
        assert_same([], $codeFatal, "matriz {$row} | {$role}: sem erro fatal de codigo");
    }
}
assert_same([], $matrixFailures, 'matriz de perfis por endpoint (5a)');

// Estrutural: o login CI precisa de AD e nao roda aqui. Confere que o perfil
// vem de session_role_for() com a allowlist (a revalidacao da requisicao
// seguinte encerraria uma sessao montada com perfil maior que o das fontes).
$loginCiSource = (string)file_get_contents($root . '/api/login_ci.php');
assert_same(true, strpos($loginCiSource, '$allowlisted = $ci_username !== null && is_ad_admin_authorized($ci_username);') !== false, 'login CI: allowlist pela normalizacao do bind');
assert_same(true, strpos($loginCiSource, '$access_role = session_role_for($allowlisted, $funcionario[\'access_role\'] ?? null, $funcionario[\'equipe\'] ?? null);') !== false, 'login CI: perfil por session_role_for()');
assert_same(false, strpos($loginCiSource, 'validate_access_role(') !== false, 'login CI: perfil nao vem direto do cadastro');
assert_same(true, strpos($loginCiSource, "\$_SESSION['admin_logged_in'] = \$access_role === 'admin';") !== false, 'login CI: admin_logged_in so para o perfil admin resolvido');

// Estrutural (A3): login AD em uso em ativos e inativos.
$configSource = (string)file_get_contents($root . '/config.php');
$inUseStart = (int)strpos($configSource, 'function funcionario_ad_login_em_uso(');
$inUseSource = substr($configSource, $inUseStart, (int)strpos($configSource, "\n}\n", $inUseStart) - $inUseStart);
assert_same(false, strpos($inUseSource, 'ativo') !== false, 'login AD em uso: nao filtra por ativo (SQL nem JSON)');
foreach (['adicionar_funcionario.php', 'atualizar_funcionario.php'] as $file) {
    $source = (string)file_get_contents($root . '/api/' . $file);
    assert_same(true, strpos($source, 'if ($ad_login !== null && funcionario_ad_login_em_uso($ad_login') !== false, "{$file}: confere login em todos os cadastros");
}

// Estrutural: migration 016 e rollback.
$migration016 = (string)file_get_contents($root . '/migrations/20261002_016_lideranca_profile.sql');
$rollback016 = (string)file_get_contents($root . '/migrations/rollback/20261002_016_lideranca_profile_down.sql');
foreach (['migration 016' => $migration016, 'rollback 016' => $rollback016] as $label => $sql) {
    $code = (string)preg_replace('/^--.*$/m', '', $sql);
    assert_same(0, strpos(ltrim($code), "SET time_zone = 'America/Sao_Paulo';"), "{$label}: comeca com SET time_zone");
    assert_same(0, preg_match('/\b(NOW|CURRENT_TIMESTAMP|CURDATE)\s*\(?/i', $code), "{$label}: sem NOW()/CURRENT_TIMESTAMP");
    assert_same(0, preg_match('/\b(UPDATE|DELETE|INSERT|DROP\s+TABLE|TRUNCATE)\b/i', $code), "{$label}: nao altera linhas nem apaga tabela");
    $firstAlter = strpos($code, 'ALTER TABLE');
    $abort = strpos($code, $label === 'migration 016' ? 'migration_016_abortada_ad_login_duplicado' : 'rollback_016_abortado_perfil_lideranca_em_uso');
    assert_same(true, $abort !== false && $firstAlter !== false && $abort < $firstAlter, "{$label}: checagem previa antes de qualquer ALTER");
}
assert_same(true, strpos($migration016, "ENUM(''tecnico'', ''gestor'', ''admin'', ''somente_leitura'', ''lideranca'')") !== false, 'migration 016: lideranca no fim do ENUM, valores existentes na mesma posicao');
assert_same(true, strpos($migration016, 'ADD UNIQUE KEY uq_funcionarios_ad_login (ad_login)') !== false, 'migration 016: UNIQUE em ad_login');
assert_same(true, strpos($migration016, 'HAVING COUNT(*) > 1') !== false && strpos($migration016, 'WHERE ad_login IS NOT NULL') !== false, 'migration 016: duplicados contam so login nao nulo');
assert_same(true, strpos($rollback016, "ENUM(''tecnico'', ''gestor'', ''admin'', ''somente_leitura'')") !== false, 'rollback 016: ENUM original');

echo "qa-security OK\n";
