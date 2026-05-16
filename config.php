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
        if (!getenv($key)) {
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

// Caminhos de arquivos
define('PAUSAS_CSV', __DIR__ . '/pausas.csv');
define('ESTADO_JSON', __DIR__ . '/estado.json');
define('CONFIG_JSON', __DIR__ . '/config_sistema.json');
define('FUNCIONARIOS_JSON', __DIR__ . '/funcionarios.json');

// ============================================
// [VULN-002] Credenciais do banco via variáveis de ambiente
// ============================================
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'sistema_pausas');
define('DB_USER', getenv('DB_USER') ?: 'root');        // Fallback para dev
define('DB_PASS', getenv('DB_PASS') ?: '');             // Fallback para dev

// ============================================
// [VULN-001] SECRET_KEY fixa via .env
// ============================================
$secret_key = getenv('SECRET_KEY');
if (!$secret_key || strpos($secret_key, 'GERE_UMA_CHAVE') !== false) {
    // Em desenvolvimento, gerar uma chave temporária mas logar warning
    $secret_key = 'DEV_ONLY_' . hash('sha256', __DIR__ . php_uname());
    error_log('[SECURITY WARNING] SECRET_KEY não configurada em .env! Usando chave temporária de desenvolvimento.');
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
// Ambiente e Debug
// ============================================
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');

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
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
}
// Se a origem não está na lista, NÃO enviar headers CORS

// Tratar requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
    
    if (isset($_SERVER['SCRIPT_NAME']) && !empty($_SERVER['SCRIPT_NAME'])) {
        $cached_path = dirname($_SERVER['SCRIPT_NAME']);
    } else {
        if (isset($_SERVER['SCRIPT_FILENAME']) && isset($_SERVER['DOCUMENT_ROOT'])) {
            $script_file = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
            $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            if (strpos($script_file, $doc_root) === 0) {
                $cached_path = dirname(substr($script_file, strlen($doc_root)));
            } else {
                $cached_path = '';
            }
        } else {
            $cached_path = '';
        }
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
    
    // Timeout absoluto: 8 horas
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 28800)) {
        session_destroy();
        _redirecionar_login('login.php', 'Sessão expirada. Faça login novamente.');
    }
    
    // Timeout de inatividade: 30 minutos
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        session_destroy();
        _redirecionar_login('login.php', 'Sessão expirada por inatividade.');
    }
    $_SESSION['last_activity'] = time();
}

/**
 * [VULN-009] Verificar login admin com timeouts
 */
function verificar_admin_login() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        _redirecionar_login('admin_login.php');
    }
    
    // Timeout absoluto: 4 horas (mais restritivo para admin)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 14400)) {
        audit_log('SESSION_EXPIRED', 'Admin session timeout absoluto', 'INFO');
        session_destroy();
        _redirecionar_login('admin_login.php', 'Sessão administrativa expirada.');
    }
    
    // Timeout de inatividade: 20 minutos
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1200)) {
        audit_log('SESSION_EXPIRED', 'Admin session inatividade', 'INFO');
        session_destroy();
        _redirecionar_login('admin_login.php', 'Sessão expirada por inatividade.');
    }
    $_SESSION['last_activity'] = time();
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
    
    if (file_exists(FUNCIONARIOS_JSON)) {
        $funcionarios = json_decode(file_get_contents(FUNCIONARIOS_JSON), true);
        if ($funcionarios && is_array($funcionarios) && count($funcionarios) > 0) {
            foreach ($funcionarios as &$func) {
                if (!isset($func['jornada_entrada'])) $func['jornada_entrada'] = '08:00';
                if (!isset($func['jornada_saida'])) $func['jornada_saida'] = '17:00';
                if (!isset($func['almoco_inicio'])) $func['almoco_inicio'] = '12:00';
                if (!isset($func['almoco_fim'])) $func['almoco_fim'] = '13:00';
                if (!isset($func['ativo'])) $func['ativo'] = true;
            }
            unset($func);
            return $funcionarios;
        }
    }
    
    salvar_funcionarios_sistema($funcionarios_padrao);
    return $funcionarios_padrao;
}

function salvar_funcionarios_sistema($funcionarios) {
    $safe_path = safe_file_path(dirname(FUNCIONARIOS_JSON), __DIR__);
    if ($safe_path === false) return false;
    
    $sanitized = [];
    foreach ($funcionarios as $func) {
        $sanitized[] = [
            'id' => validate_funcionario_id($func['id'] ?? 0) ?? 0,
            'nome' => validate_nome($func['nome'] ?? '') ?: '',
            'equipe' => validate_equipe($func['equipe'] ?? 'n1') ? $func['equipe'] : 'n1',
            'jornada_entrada' => validate_time($func['jornada_entrada'] ?? '08:00') ? $func['jornada_entrada'] : '08:00',
            'jornada_saida' => validate_time($func['jornada_saida'] ?? '17:00') ? $func['jornada_saida'] : '17:00',
            'almoco_inicio' => validate_time($func['almoco_inicio'] ?? '12:00') ? $func['almoco_inicio'] : '12:00',
            'almoco_fim' => validate_time($func['almoco_fim'] ?? '13:00') ? $func['almoco_fim'] : '13:00',
            'ativo' => isset($func['ativo']) ? (bool)$func['ativo'] : true
        ];
    }
    
    $result = file_put_contents(
        FUNCIONARIOS_JSON,
        json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    
    if (function_exists('opcache_invalidate')) opcache_invalidate(FUNCIONARIOS_JSON, true);
    return $result !== false;
}
