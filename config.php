<?php
/**
 * Configurações do Sistema - ChronoDesk
 * [SECURED] Versão corrigida com todas as mitigações aplicadas
 * 
 * Correções aplicadas:
 * - VULN-001: SECRET_KEY carregada de .env (fixa)
 * - VULN-002: Credenciais DB de .env + usuário dedicado
 * - VULN-004: CORS com origens explícitas
 * - VULN-009: Sessões com expiração absoluta e inatividade
 * - VULN-014: AUTH_SOURCE configurável (eliminar dual auth)
 * - VULN-025: limpar_estado_antigo() corrigido (não deleta tudo)
 */

// Carregar módulo de segurança primeiro
require_once __DIR__ . '/security.php';

// ============================================
// [VULN-001/002] CARREGAR VARIÁVEIS DE AMBIENTE
// ============================================
function load_env($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Remover aspas se presentes
        $value = trim($value, '"\'');
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

// Carregar .env de FORA do document root (ideal) ou da raiz do projeto
$env_paths = [
    dirname(__DIR__) . '/.env',      // Um nível acima (fora do htdocs) - RECOMENDADO
    __DIR__ . '/.env',                // Na raiz do projeto (protegido pelo .htaccess)
];
foreach ($env_paths as $env_path) {
    if (file_exists($env_path)) {
        load_env($env_path);
        break;
    }
}

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// ============================================
// Ambiente, debug e tratamento de erros
// ============================================
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (getenv('PHP_ERROR_LOG')) {
    ini_set('error_log', getenv('PHP_ERROR_LOG'));
}

// Caminhos de arquivos
define('PAUSAS_CSV', __DIR__ . '/pausas.csv');
define('ESTADO_JSON', __DIR__ . '/estado.json');
define('CONFIG_JSON', __DIR__ . '/config_sistema.json');
define('FUNCIONARIOS_JSON', __DIR__ . '/funcionarios.json');

// ============================================
// [VULN-002] Credenciais do banco via variáveis de ambiente
// ============================================
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', max(1, min((int)(getenv('DB_PORT') ?: 3306), 65535)));
define('DB_NAME', getenv('DB_NAME') ?: 'sistema_pausas');
define('DB_USER', getenv('DB_USER') ?: 'chronodesk_app');
define('DB_PASS', getenv('DB_PASS') ?: '');
$db_charset = strtolower(trim((string)(getenv('DB_CHARSET') ?: 'utf8mb4')));
define('DB_CHARSET', in_array($db_charset, ['utf8mb4', 'utf8'], true) ? $db_charset : 'utf8mb4');

// ============================================
// [VULN-001] SECRET_KEY fixa via .env
// ============================================
$secret_key = getenv('SECRET_KEY');
if (!$secret_key || strpos($secret_key, 'GERE_UMA_CHAVE') !== false) {
    if (APP_ENV === 'production') {
        error_log('[SECURITY CRITICAL] SECRET_KEY não configurada para produção.');
        throw new RuntimeException('Configuração de produção incompleta.');
    }
    $secret_key = 'DEV_ONLY_' . hash('sha256', __DIR__ . php_uname());
    error_log('[SECURITY WARNING] SECRET_KEY não configurada em .env. Usando chave temporária de desenvolvimento.');
}
define('SECRET_KEY', $secret_key);

// Credenciais padrão (usernames - NÃO são segredos)
define('USUARIO_ADMIN', 'admin');
define('ADMIN_USERNAME', 'administrador');

// ============================================
// [VULN-014] Fonte de autenticação configurável
// ============================================
define('AUTH_SOURCE', getenv('AUTH_SOURCE') ?: 'json_fallback');
// 'mysql'         = apenas banco de dados (recomendado para produção)
// 'json_fallback' = MySQL com fallback para config_sistema.json (legacy/migração)

// ============================================
// Autenticação administrativa híbrida
// ============================================
$ad_admin_users_env = getenv('AD_ADMIN_USERS');
define('AD_ADMIN_USERS', ($ad_admin_users_env !== false) ? trim($ad_admin_users_env) : '');

$enable_local_admin_env = strtolower(trim((string)(getenv('ENABLE_LOCAL_ADMIN') ?: 'false')));
define('ENABLE_LOCAL_ADMIN', !in_array($enable_local_admin_env, ['false', '0', 'no'], true));

if (APP_ENV === 'production') {
    if (APP_DEBUG) {
        error_log('[SECURITY CRITICAL] APP_DEBUG=true não é permitido em produção.');
        throw new RuntimeException('Configuração de produção insegura.');
    }
    if (DB_USER === 'root' || DB_PASS === '') {
        error_log('[SECURITY CRITICAL] Produção exige usuário MySQL dedicado e senha definida.');
        throw new RuntimeException('Configuração de banco de dados de produção incompleta.');
    }
    if (AD_ADMIN_USERS === '') {
        error_log('[SECURITY CRITICAL] AD_ADMIN_USERS não configurado para produção.');
        throw new RuntimeException('Configuração administrativa de produção incompleta.');
    }
}

// Configurar headers de segurança
set_security_headers();

// Configurar sessão segura
secure_session_start();

// ============================================
// [VULN-004] CORS com origens explícitas
// ============================================
$cors_origins_env = getenv('ALLOWED_ORIGINS') ?: 'http://localhost,http://localhost:80';
$allowed_origins = array_map('trim', explode(',', $cors_origins_env));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
}
// Se a origem não está na lista, NÃO enviar headers CORS

// Tratar requisições OPTIONS (preflight). Em CLI, REQUEST_METHOD não existe.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================
// Funções Utilitárias
// ============================================

function inicializar_csv() {
    if (!file_exists(PAUSAS_CSV)) {
        $file = fopen(PAUSAS_CSV, 'w');
        $headers = ['id_funcionario', 'nome_funcionario', 'equipe', 'inicio_pausa', 'fim_pausa', 
                     'duracao_segundos', 'motivo_pausa', 'alerta_15min', 'alerta_20min', 
                     'status_aprovacao', 'observacao_reuniao'];
        fputcsv($file, $headers);
        fclose($file);
    }
}

/**
 * [VULN-025] Limpar estado antigo SEM deletar o arquivo inteiro
 * Agora reseta pausas abandonadas individualmente (>24h)
 */
function limpar_estado_antigo() {
    with_pause_state_lock(function () {
    if (!file_exists(ESTADO_JSON)) return;
    
    $estado = json_decode(file_get_contents(ESTADO_JSON), true);
    if (!$estado || !isset($estado['funcionarios'])) return;
    
    $modificado = false;
    $now = new DateTime();
    
    foreach ($estado['funcionarios'] as $id => &$func) {
        if (($func['em_pausa'] ?? false) && isset($func['inicio_pausa'])) {
            try {
                $inicio = new DateTime($func['inicio_pausa']);
                $diff = $now->getTimestamp() - $inicio->getTimestamp();
                if ($diff > 86400) { // Pausa > 24h = abandonada
                    $func['em_pausa'] = false;
                    $func['inicio_pausa'] = null;
                    $func['motivo_pausa'] = null;
                    $func['status_aprovacao'] = null;
                    $func['solicitacao_timestamp'] = null;
                    $func['observacao_reuniao'] = null;
                    error_log("[CLEANUP] Pausa abandonada resetada: funcionario ID {$id}");
                    $modificado = true;
                }
            } catch (Exception $e) {
                // Data inválida, resetar
                $func['em_pausa'] = false;
                $func['inicio_pausa'] = null;
                $modificado = true;
            }
        }
    }
    unset($func);
    
    if ($modificado) {
        file_put_contents(ESTADO_JSON, json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    });
}

function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_base_path() {
    static $cached_path = null;
    if ($cached_path !== null) return $cached_path;

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (
        $scriptName === ''
        || preg_match('#(?:^|/)(?:var/www|srv/www|home/[^/]+|[A-Za-z]:)(?:/|$)#i', $scriptName)
    ) {
        $cached_path = '';
    } else {
        $cached_path = dirname('/' . ltrim($scriptName, '/'));
    }

    if (strlen($cached_path) > 0 && $cached_path[0] !== '/') {
        $cached_path = '/' . $cached_path;
    }
    $cached_path = rtrim($cached_path, '/');
    if ($cached_path === '/') $cached_path = '';
    
    return $cached_path;
}

/**
 * [VULN-009] Verificar login com timeout absoluto e inatividade
 */
function verificar_login() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        _redirecionar_login('login.php');
    }
    
    if (!session_window_is_current(
        $_SESSION['login_time'] ?? 0,
        $_SESSION['last_activity'] ?? 0,
        28800,
        1800
    )) {
        destroy_current_session();
        _redirecionar_login('login.php', 'Sessão expirada. Faça login novamente.');
    }
    $_SESSION['last_activity'] = time();
}

function usuario_pode_acessar_metricas() {
    $authenticated = (
        (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) ||
        (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
    );
    return $authenticated && session_window_is_current(
        $_SESSION['login_time'] ?? 0,
        $_SESSION['last_activity'] ?? 0,
        14400,
        1200
    );
}

function verificar_login_api() {
    $authenticated = (
        (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) ||
        (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
    );
    if (!$authenticated) {
        json_response([
            'sucesso' => false,
            'mensagem' => 'Sessão expirada ou acesso não autorizado.'
        ], 401);
    }

    $now = time();
    $login_time = (int)($_SESSION['login_time'] ?? 0);
    $last_activity = (int)($_SESSION['last_activity'] ?? 0);
    if (!session_window_is_current($login_time, $last_activity, 14400, 1200, $now)) {
        audit_log('SESSION_EXPIRED', 'Sessão de gestor expirada em API', 'INFO');
        destroy_current_session();
        json_response([
            'sucesso' => false,
            'mensagem' => 'Sessão expirada. Faça login novamente.'
        ], 401);
    }

    $_SESSION['last_activity'] = $now;
}

/**
 * [VULN-009] Verificar login admin com timeouts
 */
function verificar_admin_login() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        _redirecionar_login('admin_login.php');
    }
    
    if (!session_window_is_current(
        $_SESSION['login_time'] ?? 0,
        $_SESSION['last_activity'] ?? 0,
        14400,
        1200
    )) {
        audit_log('SESSION_EXPIRED', 'Sessão administrativa expirada', 'INFO');
        destroy_current_session();
        _redirecionar_login('admin_login.php', 'Sessão administrativa expirada.');
    }
    $_SESSION['last_activity'] = time();
}

function verificar_admin_login_api() {
    verificar_login_api();

    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        json_response([
            'sucesso' => false,
            'mensagem' => 'Seu perfil não possui permissão administrativa.'
        ], 403);
    }
}

function _redirecionar_login($page, $msg = '') {
    $base_path = get_base_path();
    $url = $base_path . '/' . $page;
    if (!empty($_SERVER['REQUEST_URI'])) {
        $url .= '?return=' . urlencode($_SERVER['REQUEST_URI']);
    }
    if ($msg) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . 'msg=' . urlencode($msg);
    }
    header('Location: ' . $url);
    exit;
}

function carregar_configuracao() {
    $default_config = [
        'limite_pausa_por_equipe' => 2,
        'duracao_pausa_minutos' => 20,
        'alerta_15minutos' => true,
        'alerta_20minutos' => true,
        'admin_username' => ADMIN_USERNAME
    ];
    
    if (file_exists(CONFIG_JSON)) {
        $config_content = file_get_contents(CONFIG_JSON);
        $config = json_decode($config_content, true);
        
        if ($config) {
            // Migração: senha texto plano → hash
            if (isset($config['admin_password']) && !isset($config['admin_password_hash'])) {
                $config['admin_password_hash'] = hash_password($config['admin_password']);
                unset($config['admin_password']);
                salvar_configuracao($config);
                audit_log('PASSWORD_MIGRATED', 'Admin password migrada de texto plano para hash', 'WARNING');
            }
            return array_merge($default_config, $config);
        }
    }
    
    if (!isset($default_config['admin_password_hash'])) {
        $default_config['admin_password_hash'] = hash_password('DEFINA_UMA_SENHA_SEGURA');
    }
    
    salvar_configuracao($default_config);
    return $default_config;
}

function salvar_configuracao($config) {
    // Garantir que nunca salvamos senha em texto plano
    if (isset($config['admin_password'])) {
        if (!isset($config['admin_password_hash'])) {
            $config['admin_password_hash'] = hash_password($config['admin_password']);
        }
        unset($config['admin_password']);
    }
    
    $safe_path = safe_file_path(dirname(CONFIG_JSON), __DIR__);
    if ($safe_path === false) return false;
    
    $result = file_put_contents(
        CONFIG_JSON,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(CONFIG_JSON, true);
    }
    
    return $result !== false;
}

function normalizar_samaccountname($login) {
    $login = strtolower(trim(sanitize_input($login ?? '', 150)));
    if ($login === '' || preg_match('/^[a-z0-9._@-]+$/', $login) !== 1) {
        return null;
    }

    if (strpos($login, '@') !== false) {
        [$username, $suffix] = explode('@', $login, 2);
        $allowed_suffixes = array_filter(array_unique([
            strtolower(trim((string)(getenv('AD_UPN_SUFFIX') ?: ''))),
            strtolower(trim((string)(getenv('AD_DOMAIN') ?: ''))),
        ]));
        if ($username === '' || $suffix === '' || !in_array($suffix, $allowed_suffixes, true)) {
            return null;
        }
        $login = $username;
    }

    return strlen($login) >= 2 && strlen($login) <= 100 ? $login : null;
}

function get_ad_admin_users() {
    $users = [];
    foreach (explode(',', AD_ADMIN_USERS) as $user) {
        $normalized = normalizar_samaccountname($user);
        if ($normalized !== null) {
            $users[] = $normalized;
        }
    }
    return array_values(array_unique($users));
}

function is_ad_admin_authorized($login) {
    $normalized = normalizar_samaccountname($login);
    if ($normalized === null) {
        return false;
    }
    return in_array($normalized, get_ad_admin_users(), true);
}

function carregar_funcionarios_sistema() {
    $funcionarios_padrao = [
        ['id' => 1, 'nome' => 'Amauri Alaxandre', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 2, 'nome' => 'David Alexandre', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 3, 'nome' => 'Gustavo Eugenio', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 4, 'nome' => 'Matheus Moraes', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 5, 'nome' => 'Pedro Henrique', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 6, 'nome' => 'Sabrina Cristina', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 7, 'nome' => 'Victor Franco', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 8, 'nome' => 'Vinicius Martins', 'equipe' => 'n1', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 9, 'nome' => 'Bruno Ventura', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 10, 'nome' => 'Fabio Luiz', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 11, 'nome' => 'Gabriel Henrique', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 12, 'nome' => 'Guilherme Martin', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 13, 'nome' => 'Isabelly Cristina', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 14, 'nome' => 'João Victor', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 15, 'nome' => 'Luiz Felipe', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 16, 'nome' => 'Danilo Angelo', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
        ['id' => 17, 'nome' => 'Davi Henrique', 'equipe' => 'n2', 'jornada_entrada' => '08:00', 'jornada_saida' => '17:00', 'almoco_inicio' => '12:00', 'almoco_fim' => '13:00', 'ativo' => true],
    ];
    
    $funcionarios_mysql = carregar_funcionarios_mysql();
    if (count($funcionarios_mysql) > 0) {
        return $funcionarios_mysql;
    }

    $funcionarios_json = carregar_funcionarios_json_fallback();
    if (count($funcionarios_json) > 0) {
        return $funcionarios_json;
    }

    salvar_funcionarios_sistema($funcionarios_padrao);
    return $funcionarios_padrao;
}

function salvar_funcionarios_sistema($funcionarios) {
    if (salvar_funcionarios_mysql($funcionarios)) {
        return true;
    }

    return salvar_funcionarios_json_fallback($funcionarios);
}

function normalizar_ad_login($ad_login) {
    return normalizar_samaccountname($ad_login);
}

function validate_ad_login($ad_login) {
    $ad_login = normalizar_ad_login($ad_login);
    if ($ad_login === null) {
        return null;
    }
    if (strlen($ad_login) > 100) {
        return false;
    }
    return preg_match('/^[a-z0-9._@-]+$/', $ad_login) === 1 ? $ad_login : false;
}

function funcionarios_access_role_supported(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    try {
        if (!function_exists('get_db_connection')) {
            require_once __DIR__ . '/db.php';
        }
        get_db_connection()->query('SELECT access_role FROM funcionarios LIMIT 0');
        $supported = true;
    } catch (Throwable $error) {
        $supported = false;
    }
    return $supported;
}

function carregar_funcionarios_mysql() {
    try {
        if (!function_exists('get_db_connection')) {
            require_once __DIR__ . '/db.php';
        }
        $pdo = get_db_connection();
        $roleColumn = funcionarios_access_role_supported()
            ? 'access_role'
            : "'tecnico' AS access_role";
        $stmt = $pdo->query(
            "SELECT id, nome, equipe, {$roleColumn}, ad_login, jornada_entrada,
                    jornada_saida, almoco_inicio, almoco_fim, ativo
             FROM funcionarios
             ORDER BY id ASC"
        );
        $funcionarios = [];
        foreach ($stmt->fetchAll() as $row) {
            $funcionarios[] = normalizar_funcionario_array($row);
        }
        return $funcionarios;
    } catch (Exception $e) {
        error_log('[FUNCIONARIOS] Fallback JSON ativo: ' . $e->getMessage());
        return [];
    }
}

function carregar_funcionarios_json_fallback() {
    if (!file_exists(FUNCIONARIOS_JSON)) {
        return [];
    }

    $funcionarios = json_decode(file_get_contents(FUNCIONARIOS_JSON), true);
    if (!$funcionarios || !is_array($funcionarios) || count($funcionarios) === 0) {
        return [];
    }

    $normalizados = [];
    foreach ($funcionarios as $func) {
        $normalizados[] = normalizar_funcionario_array($func);
    }
    return $normalizados;
}

function salvar_funcionarios_mysql($funcionarios) {
    try {
        if (!function_exists('get_db_connection')) {
            require_once __DIR__ . '/db.php';
        }
        $pdo = get_db_connection();
        $hasAccessRole = funcionarios_access_role_supported();
        $roleInsertColumn = $hasAccessRole ? ', access_role' : '';
        $roleInsertValue = $hasAccessRole ? ', :access_role' : '';
        $roleUpdate = $hasAccessRole ? ', access_role = VALUES(access_role)' : '';
        $sql = "INSERT INTO funcionarios (
                    id, nome, equipe{$roleInsertColumn}, ad_login, jornada_entrada,
                    jornada_saida, almoco_inicio, almoco_fim, ativo
                ) VALUES (
                    :id, :nome, :equipe{$roleInsertValue}, :ad_login, :jornada_entrada,
                    :jornada_saida, :almoco_inicio, :almoco_fim, :ativo
                )
                ON DUPLICATE KEY UPDATE
                    nome = VALUES(nome),
                    equipe = VALUES(equipe){$roleUpdate},
                    ad_login = VALUES(ad_login),
                    jornada_entrada = VALUES(jornada_entrada),
                    jornada_saida = VALUES(jornada_saida),
                    almoco_inicio = VALUES(almoco_inicio),
                    almoco_fim = VALUES(almoco_fim),
                    ativo = VALUES(ativo)";
        $stmt = $pdo->prepare($sql);
        foreach ($funcionarios as $func) {
            $func = normalizar_funcionario_array($func);
            $params = [
                ':id' => $func['id'],
                ':nome' => $func['nome'],
                ':equipe' => $func['equipe'],
                ':ad_login' => $func['ad_login'],
                ':jornada_entrada' => formatar_hora_mysql($func['jornada_entrada'], '08:00:00'),
                ':jornada_saida' => formatar_hora_mysql($func['jornada_saida'], '17:00:00'),
                ':almoco_inicio' => formatar_hora_mysql($func['almoco_inicio'], '12:00:00'),
                ':almoco_fim' => formatar_hora_mysql($func['almoco_fim'], '13:00:00'),
                ':ativo' => $func['ativo'] ? 1 : 0,
            ];
            if ($hasAccessRole) {
                $params[':access_role'] = $func['access_role'];
            }
            $stmt->execute($params);
        }
        return true;
    } catch (Exception $e) {
        error_log('[FUNCIONARIOS] Erro ao salvar no MySQL: ' . $e->getMessage());
        return false;
    }
}

function funcionario_ad_login_ativo_existe($ad_login, $ignorar_id = null) {
    $ad_login = normalizar_ad_login($ad_login);
    if ($ad_login === null) {
        return false;
    }

    try {
        if (!function_exists('get_db_connection')) {
            require_once __DIR__ . '/db.php';
        }
        $pdo = get_db_connection();
        $sql = "SELECT id FROM funcionarios WHERE ad_login = :ad_login AND ativo = 1";
        $params = [':ad_login' => $ad_login];
        if ($ignorar_id !== null) {
            $sql .= " AND id <> :id";
            $params[':id'] = (int)$ignorar_id;
        }
        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        foreach (carregar_funcionarios_json_fallback() as $func) {
            if ((int)$func['id'] === (int)$ignorar_id) {
                continue;
            }
            if (($func['ativo'] ?? true) && normalizar_ad_login($func['ad_login'] ?? null) === $ad_login) {
                return true;
            }
        }
        return false;
    }
}

function normalizar_funcionario_array($func) {
    $ad_login = validate_ad_login($func['ad_login'] ?? null);
    if ($ad_login === false) {
        $ad_login = null;
    }

    $access_role = validate_access_role($func['access_role'] ?? 'tecnico') ?? 'tecnico';
    $equipe = validate_funcionario_equipe($func['equipe'] ?? 'n1') ?? 'n1';
    if ($access_role === 'tecnico' && $equipe === 'na') {
        $equipe = 'n1';
    }

    return [
        'id' => validate_funcionario_id($func['id'] ?? 0) ?? 0,
        'nome' => validate_nome($func['nome'] ?? '') ?: '',
        'equipe' => $equipe,
        'access_role' => $access_role,
        'ad_login' => $ad_login,
        'jornada_entrada' => normalizar_hora_funcionario($func['jornada_entrada'] ?? '08:00', '08:00'),
        'jornada_saida' => normalizar_hora_funcionario($func['jornada_saida'] ?? '17:00', '17:00'),
        'almoco_inicio' => normalizar_hora_funcionario($func['almoco_inicio'] ?? '12:00', '12:00'),
        'almoco_fim' => normalizar_hora_funcionario($func['almoco_fim'] ?? '13:00', '13:00'),
        'ativo' => isset($func['ativo']) ? (bool)$func['ativo'] : true
    ];
}

function normalizar_hora_funcionario($hora, $default = '08:00') {
    $hora = (string)$hora;
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $hora, $m)) {
        return $m[1] . ':' . $m[2];
    }
    return $default;
}

function formatar_hora_mysql($hora, $default = '08:00:00') {
    $hora = (string)$hora;
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $hora, $m)) {
        return $m[1] . ':' . $m[2] . ':' . ($m[3] ?? '00');
    }
    return $default;
}

function salvar_funcionarios_json_fallback($funcionarios) {
    $safe_path = safe_file_path(dirname(FUNCIONARIOS_JSON), __DIR__);
    if ($safe_path === false) return false;
    
    $sanitized = [];
    foreach ($funcionarios as $func) {
        $sanitized[] = normalizar_funcionario_array($func);
    }
    
    $result = file_put_contents(
        FUNCIONARIOS_JSON,
        json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    
    if (function_exists('opcache_invalidate')) opcache_invalidate(FUNCIONARIOS_JSON, true);
    return $result !== false;
}
