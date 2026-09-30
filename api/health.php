<?php
/**
 * Verificacao de saude para monitoracao (OPS-02).
 *
 * Responde apenas se o PHP atende e se o banco aceita consulta:
 *   200 {"status":"ok","database":"ok"}
 *   503 {"status":"fail","database":"fail"}
 * Nao expoe versao, caminho, host nem mensagem de erro.
 *
 * Acesso restrito aos enderecos de HEALTH_ALLOWED_IPS (IPs ou CIDR separados
 * por virgula). Sem a variavel, somente loopback. Fora da lista: 403.
 *
 * Nao abre sessao e nao inclui init.php: a sondagem nao cria arquivo de sessao
 * nem disputa o lock do estado de pausas. A conexao usa timeout curto e propria,
 * para que um banco travado nao prenda um worker do pool do PHP-FPM.
 */
define('CHRONODESK_STATELESS', true);
require_once __DIR__ . '/../db.php';

header('Cache-Control: no-store');
require_http_method(['GET', 'HEAD']);

$health_allowed_ips = trim((string)(getenv('HEALTH_ALLOWED_IPS') ?: ''));
if ($health_allowed_ips === '') {
    $health_allowed_ips = '127.0.0.1,::1';
}
if (!ip_in_allowlist((string)($_SERVER['REMOTE_ADDR'] ?? ''), $health_allowed_ips)) {
    json_response(['status' => 'forbidden'], 403);
}

$database_ok = false;
try {
    // Mesma abertura da aplicacao (db.php), inclusive o fuso da sessao: se a
    // aplicacao nao consegue conectar, a sondagem tambem nao.
    $pdo = db_connect([
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
    ]);
    $database_ok = (string)$pdo->query('SELECT 1')->fetchColumn() === '1';
} catch (Throwable $e) {
    $database_ok = false;
}

$status = $database_ok ? 'ok' : 'fail';
json_response(['status' => $status, 'database' => $status], $database_ok ? 200 : 503);
