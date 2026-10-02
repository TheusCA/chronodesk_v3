<?php
/**
 * Conexão com o Banco de Dados MySQL
 */

require_once __DIR__ . '/config.php';

// Valor de PDO::MYSQL_ATTR_INIT_COMMAND. A constante só existe com o pdo_mysql
// carregado; sem o driver, o new PDO falha de qualquer forma ("could not find driver").
const DB_INIT_COMMAND_ATTRIBUTE = 1002;

/**
 * Fusos que a sessão MySQL tenta usar, em ordem: o fuso nomeado do PHP
 * (config.php) e, como reserva, o deslocamento fixo equivalente a ele agora.
 * Derivar do PHP mantém os dois lados sempre no mesmo fuso.
 */
function db_time_zone_candidates(): array {
    return array_values(array_unique([date_default_timezone_get(), date('P')]));
}

/**
 * O fuso entra no texto do comando inicial, que não aceita parâmetro: só passa
 * nome de fuso (America/Sao_Paulo) ou deslocamento (-03:00).
 */
function db_is_valid_time_zone(string $time_zone): bool {
    return preg_match('~^(?:[A-Za-z]+(?:/[A-Za-z0-9_+\-]+)*|[+-]\d{2}:\d{2})$~', $time_zone) === 1;
}

/**
 * Erro 1298 do MySQL: fuso nomeado desconhecido (tabelas de fuso não carregadas).
 */
function db_is_unknown_time_zone_error(PDOException $e): bool {
    $driver_code = is_array($e->errorInfo ?? null) ? (int)($e->errorInfo[1] ?? 0) : 0;

    return $driver_code === 1298
        || (int)$e->getCode() === 1298
        || strpos($e->getMessage(), '[1298]') !== false;
}

/**
 * [BIZ-02] Abre a conexão já com o fuso da sessão igual ao do PHP.
 *
 * Sem isso a sessão herda o fuso do servidor MySQL (UTC no container): NOW(),
 * CURRENT_DATE e os DEFAULT CURRENT_TIMESTAMP ficam 3 h à frente do PHP, e das
 * 21:00 às 00:00 o CURRENT_DATE já é o dia seguinte.
 *
 * O SET vai em MYSQL_ATTR_INIT_COMMAND: se ele falhar, a conexão não abre, então
 * nunca existe conexão em uso com o fuso errado. Fuso nomeado desconhecido cai
 * para o deslocamento fixo; qualquer outro erro sobe sem segunda tentativa.
 *
 * $factory e $time_zones existem para os testes (scripts/qa-timezone.php).
 */
function db_connect(array $options = [], ?callable $factory = null, ?array $time_zones = null): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $factory = $factory ?? static function (string $dsn, string $user, string $pass, array $options): PDO {
        return new PDO($dsn, $user, $pass, $options);
    };

    $time_zones = array_values($time_zones ?? db_time_zone_candidates());
    if ($time_zones === [] || array_filter($time_zones, 'db_is_valid_time_zone') !== $time_zones) {
        throw new PDOException('Fuso da sessão MySQL indefinido ou inválido.');
    }

    $last = count($time_zones) - 1;
    foreach ($time_zones as $index => $time_zone) {
        try {
            return $factory($dsn, DB_USER, DB_PASS, $options + [
                DB_INIT_COMMAND_ATTRIBUTE => "SET time_zone = '{$time_zone}'",
            ]);
        } catch (PDOException $e) {
            if ($index === $last || !db_is_unknown_time_zone_error($e)) {
                throw $e;
            }
            error_log('[DB] Fuso nomeado indisponível no MySQL; usando deslocamento fixo.');
        }
    }

    throw new PDOException('Fuso da sessão MySQL indefinido ou inválido.');
}

/**
 * [PERF-02] Lista limitada que avisa quando cortou. O chamador busca $limit + 1
 * linhas; a linha a mais só serve para saber que existem outras.
 */
function db_limit_rows(array $rows, int $limit): array {
    return [
        'items' => array_slice($rows, 0, $limit),
        'truncated' => count($rows) > $limit,
        'limit' => $limit,
    ];
}

/**
 * [PERF-02] Percorre um resultado inteiro em páginas de $chunk linhas, sem
 * carregar tudo na memória e sem LIMIT fixo: exportação nunca trunca.
 *
 * $fetch_page(?array $last_row, int $chunk) devolve a próxima página, a partir
 * da última linha da página anterior (null na primeira). A paginação é por
 * chave (keyset), então linhas com a mesma data não se repetem nem se perdem.
 */
function db_keyset_iterate(callable $fetch_page, int $chunk = 500): Generator {
    $chunk = max(1, $chunk);
    $last_row = null;
    do {
        $rows = $fetch_page($last_row, $chunk);
        foreach ($rows as $row) {
            yield $row;
        }
        $last_row = $rows === [] ? null : $rows[count($rows) - 1];
    } while (count($rows) === $chunk);
}

// Definida só se ainda não existir: o processo filho do qa-security define uma
// conexão simulada antes de incluir o endpoint. Em produção ninguém a define
// antes, e o comportamento não muda (mesmo padrão das constantes de caminho
// em config.php).
if (!function_exists('get_db_connection')) {
    function get_db_connection() {
        static $pdo = null;

        if ($pdo !== null) {
            return $pdo;
        }

        try {
            $pdo = db_connect([
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return $pdo;
        } catch (\PDOException $e) {
            // Em produção, logar o erro e mostrar mensagem genérica
            error_log("Erro de conexão com o banco de dados.");
            throw new \Exception(public_error_message($e, "Erro ao conectar ao banco de dados."));
        }
    }
}
