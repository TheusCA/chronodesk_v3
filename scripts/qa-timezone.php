<?php
declare(strict_types=1);

// ============================================================================
// QA de fuso horario da sessao MySQL (BIZ-02)
//
// Modo padrao (sem banco):  php scripts/qa-timezone.php
//   - abertura da conexao: comando inicial, fallback e falha fechada, com uma
//     fabrica de PDO simulada;
//   - regressao com relogio simulado em 23:30 e 00:30. SEM MySQL, o que roda e
//     um MODELO da sessao: a data e a hora que o MySQL devolve para um instante
//     sao esse instante convertido para o fuso da sessao. O modelo prova que os
//     fusos enviados pelo db.php dao a data do PHP e que UTC reproduz o defeito.
//     Nao prova que o servidor aceita o comando: isso e o modo --db.
//
// Modo --db (no servidor, contra o banco real; SOMENTE LEITURA):
//   sudo -u www-data php scripts/qa-timezone.php --db
//   - fuso efetivo da sessao aberta por get_db_connection();
//   - NOW(), CURRENT_DATE e conversao de TIMESTAMP contra o relogio do PHP;
//   - relogio do MySQL simulado em 23:30 e 00:30 com SET timestamp;
//   - fallback real: fuso nomeado inexistente cai para o deslocamento fixo.
//   Nao grava nada: so SELECT de expressoes e SET de variaveis de sessao.
// ============================================================================

$qaDbMode = in_array('--db', array_slice($argv ?? [], 1), true);

if (!$qaDbMode) {
    putenv('APP_ENV=development');
    putenv('APP_DEBUG=false');
    putenv('SECRET_KEY=qa-only-secret-key');
    putenv('MAIL_ENABLED=false');

    // Os avisos de error_log() provocados de proposito (fallback de fuso, chave
    // de desenvolvimento) nao poluem a saida do QA.
    $errorLogFile = tempnam(sys_get_temp_dir(), 'qa-timezone-');
    putenv('PHP_ERROR_LOG=' . $errorLogFile);
    register_shutdown_function(static function () use ($errorLogFile): void {
        @unlink($errorLogFile);
    });
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';

// Sem sessao: o QA tambem roda no servidor e nao deve criar arquivo de sessao.
define('CHRONODESK_STATELESS', true);
require_once __DIR__ . '/../db.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

// Instantes da regressao, no relogio de parede de America/Sao_Paulo.
const QA_TZ_INSTANTS = [
    '2026-10-05 23:30:00',  // dentro da janela 21:00-00:00
    '2026-10-06 00:30:00',  // logo depois da virada
    '2026-10-05 20:59:59',  // ultimo segundo antes da janela
    '2026-10-05 21:00:00',  // primeiro segundo da janela
    '2026-10-05 23:59:59',  // fim do turno 15:30-23:59
    '2026-10-31 23:30:00',  // virada de mes
    '2026-12-31 23:30:00',  // virada de ano
];

function qa_epoch(string $wallClock): int {
    $instant = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $wallClock,
        new DateTimeZone('America/Sao_Paulo')
    );
    if (!$instant) {
        fwrite(STDERR, "FAIL: instante invalido no QA: {$wallClock}\n");
        exit(1);
    }
    return $instant->getTimestamp();
}

// ----------------------------------------------------------------------------
// Modo --db: banco real, somente leitura
// ----------------------------------------------------------------------------
if ($qaDbMode) {
    $pdo = get_db_connection();
    $candidates = db_time_zone_candidates();

    $sessionZone = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
    assert_same(true, in_array($sessionZone, $candidates, true), 'sessao usa um dos fusos do db.php');
    echo "sessao MySQL em {$sessionZone}\n";
    if ($sessionZone !== $candidates[0]) {
        echo "AVISO: fuso nomeado indisponivel; sessao no deslocamento fixo\n";
    }

    $row = $pdo->query('SELECT UNIX_TIMESTAMP() AS epoch, NOW() AS agora, CURRENT_DATE AS hoje')->fetch();
    $epoch = (int)$row['epoch'];
    assert_same(true, abs($epoch - time()) <= 5, 'relogios do PHP e do MySQL diferem em ate 5 s');
    assert_same(date('Y-m-d H:i:s', $epoch), $row['agora'], 'NOW() no fuso do PHP');
    assert_same(date('Y-m-d', $epoch), $row['hoje'], 'CURRENT_DATE no fuso do PHP');

    try {
        $pdo->exec('SET timestamp = ' . qa_epoch(QA_TZ_INSTANTS[0]));
    } catch (PDOException $e) {
        fwrite(STDERR, "FAIL: nao foi possivel simular o relogio do MySQL (SET timestamp recusado).\n"
            . "  A regressao de 23:30/00:30 NAO foi executada contra o banco.\n"
            . "  Alternativa: docs/FUSO_HORARIO_BIZ02.md, secao 8.3.\n");
        exit(1);
    }
    try {
        foreach (QA_TZ_INSTANTS as $wallClock) {
            $epoch = qa_epoch($wallClock);
            $pdo->exec('SET timestamp = ' . $epoch);
            $row = $pdo->query(
                'SELECT NOW() AS agora, CURRENT_TIMESTAMP AS carimbo, CURRENT_DATE AS hoje,
                        DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY) AS ontem,
                        FROM_UNIXTIME(' . $epoch . ') AS convertido'
            )->fetch();
            $today = substr($wallClock, 0, 10);
            $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
            assert_same($wallClock, $row['agora'], "MySQL em {$wallClock}: NOW()");
            assert_same($wallClock, $row['carimbo'], "MySQL em {$wallClock}: CURRENT_TIMESTAMP");
            assert_same($today, $row['hoje'], "MySQL em {$wallClock}: CURRENT_DATE");
            assert_same($yesterday, $row['ontem'], "MySQL em {$wallClock}: vespera (remocao de escala)");
            assert_same($wallClock, $row['convertido'], "MySQL em {$wallClock}: leitura de TIMESTAMP");
        }
    } finally {
        $pdo->exec('SET timestamp = DEFAULT');
    }

    $fallback = db_connect([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], null, ['Zona/Inexistente', $candidates[count($candidates) - 1]]);
    assert_same(
        $candidates[count($candidates) - 1],
        (string)$fallback->query('SELECT @@session.time_zone')->fetchColumn(),
        'fuso nomeado inexistente cai para o deslocamento fixo'
    );
    $fallback = null;

    $refused = false;
    try {
        db_connect([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], null, ['Zona/Inexistente']);
    } catch (PDOException $e) {
        $refused = true;
    }
    assert_same(true, $refused, 'sem fuso valido a conexao nao abre');

    echo "QA timezone (banco real) OK\n";
    exit(0);
}

// ----------------------------------------------------------------------------
// Fusos enviados ao MySQL
// ----------------------------------------------------------------------------
assert_same('America/Sao_Paulo', date_default_timezone_get(), 'PHP em America/Sao_Paulo');
assert_same(['America/Sao_Paulo', '-03:00'], db_time_zone_candidates(), 'fuso nomeado e, de reserva, deslocamento fixo');

// ----------------------------------------------------------------------------
// Abertura da conexao com fabrica simulada
// ----------------------------------------------------------------------------
final class QaTimezonePdo extends PDO {
    public function __construct() {
    }
}

function qa_pdo_exception(int $driverCode, string $text, bool $withErrorInfo = true): PDOException {
    $e = new PDOException("SQLSTATE[HY000] [{$driverCode}] {$text}");
    if ($withErrorInfo) {
        $e->errorInfo = ['HY000', $driverCode, $text];
    }
    return $e;
}

/**
 * Fabrica que responde a cada chamada com o proximo item do roteiro: uma
 * excecao (lancada) ou 'ok' (conexao simulada). Registra o que recebeu.
 */
function qa_factory(array $script, array &$calls): callable {
    return static function (string $dsn, string $user, string $pass, array $options) use (&$script, &$calls): PDO {
        $calls[] = ['dsn' => $dsn, 'options' => $options];
        $step = array_shift($script);
        if ($step instanceof PDOException) {
            throw $step;
        }
        return new QaTimezonePdo();
    };
}

$unknownZone = "Unknown or incorrect time zone: 'America/Sao_Paulo'";

// Caminho normal: uma tentativa, fuso nomeado, opcoes do chamador preservadas.
$calls = [];
$pdo = db_connect([PDO::ATTR_TIMEOUT => 2], qa_factory(['ok'], $calls));
assert_same(true, $pdo instanceof PDO, 'conexao aberta');
assert_same(1, count($calls), 'fuso nomeado aceito: uma unica tentativa');
assert_same("SET time_zone = 'America/Sao_Paulo'", $calls[0]['options'][DB_INIT_COMMAND_ATTRIBUTE], 'comando inicial define o fuso nomeado');
assert_same(2, $calls[0]['options'][PDO::ATTR_TIMEOUT], 'opcoes do chamador sao preservadas');
assert_same(true, strpos($calls[0]['dsn'], 'mysql:host=') === 0, 'DSN do MySQL');
if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    assert_same(constant('PDO::MYSQL_ATTR_INIT_COMMAND'), DB_INIT_COMMAND_ATTRIBUTE, 'atributo e o MYSQL_ATTR_INIT_COMMAND do driver');
}

// Fuso nomeado desconhecido (1298): segunda tentativa com o deslocamento fixo.
$calls = [];
db_connect([], qa_factory([qa_pdo_exception(1298, $unknownZone), 'ok'], $calls));
assert_same(2, count($calls), 'erro 1298 leva a segunda tentativa');
assert_same("SET time_zone = '-03:00'", $calls[1]['options'][DB_INIT_COMMAND_ATTRIBUTE], 'segunda tentativa usa o deslocamento fixo');
assert_same(true, strpos((string)file_get_contents($errorLogFile), '[DB] Fuso nomeado') !== false, 'fallback deixa aviso no log');

// O mesmo erro reconhecido so pela mensagem (errorInfo ausente).
$calls = [];
db_connect([], qa_factory([qa_pdo_exception(1298, $unknownZone, false), 'ok'], $calls));
assert_same(2, count($calls), 'erro 1298 reconhecido pela mensagem');

// Falha fechada: se o deslocamento fixo tambem falhar, nao ha conexao.
$calls = [];
$thrown = null;
try {
    db_connect([], qa_factory([qa_pdo_exception(1298, $unknownZone), qa_pdo_exception(1298, "Unknown or incorrect time zone: '-03:00'")], $calls));
} catch (PDOException $e) {
    $thrown = $e;
}
assert_same(true, $thrown instanceof PDOException, 'sem fuso valido a conexao nao abre');
assert_same(2, count($calls), 'nao ha terceira tentativa');

// Outros erros nao disparam segunda tentativa: nao dobrar o tempo de espera
// com o banco fora do ar, nem repetir senha errada.
foreach ([1045 => 'Access denied', 2002 => 'Connection refused', 1049 => 'Unknown database'] as $code => $text) {
    $calls = [];
    $thrown = null;
    try {
        db_connect([], qa_factory([qa_pdo_exception($code, $text), 'ok'], $calls));
    } catch (PDOException $e) {
        $thrown = $e;
    }
    assert_same(true, $thrown instanceof PDOException, "erro {$code} sobe para o chamador");
    assert_same(1, count($calls), "erro {$code} nao dispara segunda tentativa");
}

// Lista de fusos vazia nunca abre conexao sem fuso.
$calls = [];
$thrown = null;
try {
    db_connect([], qa_factory(['ok'], $calls), []);
} catch (PDOException $e) {
    $thrown = $e;
}
assert_same(true, $thrown instanceof PDOException, 'lista de fusos vazia e recusada');
assert_same(0, count($calls), 'lista de fusos vazia nao tenta conectar');

// O fuso entra no texto do comando: valor fora do formato nunca chega ao banco.
foreach (["America/Sao_Paulo'; DROP TABLE x; --", "' OR '1'='1", '-3:00', '', 'America/Sao Paulo'] as $invalidZone) {
    $calls = [];
    $thrown = null;
    try {
        db_connect([], qa_factory(['ok'], $calls), ['America/Sao_Paulo', $invalidZone]);
    } catch (PDOException $e) {
        $thrown = $e;
    }
    assert_same(true, $thrown instanceof PDOException, 'fuso fora do formato e recusado');
    assert_same(0, count($calls), 'fuso fora do formato nao tenta conectar');
}
assert_same(true, db_is_valid_time_zone('America/Sao_Paulo'), 'fuso nomeado valido');
assert_same(true, db_is_valid_time_zone('-03:00'), 'deslocamento valido');

assert_same(false, db_is_unknown_time_zone_error(qa_pdo_exception(1045, 'Access denied')), 'acesso negado nao e erro de fuso');
assert_same(true, db_is_unknown_time_zone_error(qa_pdo_exception(1298, $unknownZone)), 'erro 1298 e erro de fuso');

// ----------------------------------------------------------------------------
// Estrutural: toda conexao passa por db_connect()
// ----------------------------------------------------------------------------
$qaRoot = realpath(__DIR__ . '/..');
$phpFiles = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($qaRoot, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $file): bool {
        return !in_array($file->getFilename(), ['.git', 'node_modules', 'vendor', 'scripts', 'app'], true);
    }
));
$directConnections = [];
foreach ($phpFiles as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    if (preg_match('/new\s+\\\\?PDO\s*\(/', (string)file_get_contents($file->getPathname())) === 1) {
        $directConnections[] = str_replace('\\', '/', substr($file->getPathname(), strlen($qaRoot) + 1));
    }
}
assert_same(['db.php'], $directConnections, 'somente db.php abre conexao; o resto usa db_connect()');

$healthSource = (string)file_get_contents($qaRoot . '/api/health.php');
assert_same(true, strpos($healthSource, 'db_connect(') !== false, 'health abre a conexao por db_connect()');
assert_same(true, strpos($healthSource, 'PDO::ATTR_TIMEOUT => 2') !== false, 'health mantem o timeout curto');

// ----------------------------------------------------------------------------
// Regressao com relogio simulado (modelo da sessao MySQL)
// ----------------------------------------------------------------------------
/**
 * O que a sessao MySQL devolve para um instante, dado o fuso da sessao.
 */
function qa_mysql_session(int $epoch, string $sessionZone): array {
    $now = (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone($sessionZone));
    return [
        'now' => $now->format('Y-m-d H:i:s'),
        'current_date' => $now->format('Y-m-d'),
        'yesterday' => $now->modify('-1 day')->format('Y-m-d'),
    ];
}

foreach (QA_TZ_INSTANTS as $wallClock) {
    $epoch = qa_epoch($wallClock);
    $phpNow = date('Y-m-d H:i:s', $epoch);
    $phpToday = date('Y-m-d', $epoch);
    $phpYesterday = date('Y-m-d', strtotime($phpToday . ' -1 day'));
    assert_same($wallClock, $phpNow, "PHP em {$wallClock}");

    foreach (db_time_zone_candidates() as $sessionZone) {
        $mysql = qa_mysql_session($epoch, $sessionZone);
        assert_same($phpNow, $mysql['now'], "{$wallClock}, sessao {$sessionZone}: NOW() igual ao PHP");
        assert_same($phpToday, $mysql['current_date'], "{$wallClock}, sessao {$sessionZone}: CURRENT_DATE igual ao PHP");
        assert_same($phpYesterday, $mysql['yesterday'], "{$wallClock}, sessao {$sessionZone}: vespera igual ao PHP");
    }

    // Controle: a sessao em UTC (situacao anterior) reproduz o defeito. Sem este
    // controle, os testes acima passariam mesmo se o modelo ignorasse o fuso.
    $utc = qa_mysql_session($epoch, 'UTC');
    assert_same(date('Y-m-d H:i:s', $epoch + 10800), $utc['now'], "{$wallClock}, sessao UTC: NOW() 3 h adiante");
    $insideWindow = substr($wallClock, 11) >= '21:00:00';
    assert_same(!$insideWindow, $utc['current_date'] === $phpToday, "{$wallClock}, sessao UTC: CURRENT_DATE erra so das 21:00 as 00:00");
}

// Os dois instantes pedidos, por extenso.
$at2330 = qa_mysql_session(qa_epoch('2026-10-05 23:30:00'), 'UTC');
assert_same('2026-10-06', $at2330['current_date'], '23:30 com sessao UTC: MySQL ja no dia seguinte');
assert_same('2026-10-05', $at2330['yesterday'], '23:30 com sessao UTC: escala removida seria encerrada HOJE, nao ontem');
$at2330 = qa_mysql_session(qa_epoch('2026-10-05 23:30:00'), 'America/Sao_Paulo');
assert_same('2026-10-05', $at2330['current_date'], '23:30 com sessao corrigida: mesmo dia do PHP');
assert_same('2026-10-04', $at2330['yesterday'], '23:30 com sessao corrigida: escala removida encerrada ontem');
$at0030 = qa_mysql_session(qa_epoch('2026-10-06 00:30:00'), 'America/Sao_Paulo');
assert_same('2026-10-06', $at0030['current_date'], '00:30 com sessao corrigida: dia novo');
assert_same('2026-10-06 00:30:00', $at0030['now'], '00:30 com sessao corrigida: NOW() igual ao PHP');

echo "QA timezone OK\n";
