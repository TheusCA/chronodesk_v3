<?php
/**
 * Módulo de Segurança - ChronoDesk
 * [SECURED] Versão corrigida com todas as mitigações aplicadas
 * 
 * Correções aplicadas:
 * - VULN-006: Rate limiting por IP (file-based)
 * - VULN-013: CSP com suporte a nonce
 * - VULN-015: HSTS enforcement
 * - VULN-017: Sistema de auditoria
 * - VULN-022: Validação de Content-Type
 */

// Nonce global para CSP (gerado por requisição)
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));

/**
 * Retorna o nonce CSP para uso em tags <script> e <style>
 */
function get_csp_nonce() {
    return $GLOBALS['csp_nonce'] ?? '';
}

// ============================================
// SANITIZAÇÃO
// ============================================

function sanitize_input($data, $max_length = 1000) {
    if (is_null($data)) return '';
    $data = (string)$data;
    if (strlen($data) > $max_length) {
        $data = substr($data, 0, $max_length);
    }
    $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);
    return trim($data);
}

function sanitize_output($data, $flags = ENT_QUOTES | ENT_HTML5) {
    if (is_null($data)) return '';
    return htmlspecialchars((string)$data, $flags, 'UTF-8');
}

function sanitize_attr($data) {
    return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
}

// ============================================
// VALIDAÇÕES
// ============================================

function validate_funcionario_id($id) {
    $id = filter_var($id, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 999]
    ]);
    return $id !== false ? $id : null;
}

function validate_time($time) {
    if (!is_string($time) || strlen($time) !== 5) return false;
    return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) === 1;
}

function validate_equipe($equipe) {
    return in_array($equipe, ['n1', 'n2'], true);
}

function validate_nome($nome, $min_length = 3, $max_length = 100) {
    $nome = sanitize_input($nome, $max_length);
    if (strlen($nome) < $min_length) return false;
    if (!preg_match('/^[\p{L}\s\-\'\.]+$/u', $nome)) return false;
    return $nome;
}

/**
 * [VULN-019] Valida motivo de pausa contra lista permitida
 */
function validate_motivo_pausa($motivo) {
    $motivos_validos = ['Café', 'Pessoal', 'Reunião'];
    return in_array($motivo, $motivos_validos, true) ? $motivo : null;
}

// ============================================
// SENHAS
// ============================================

function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// ============================================
// CSRF
// ============================================

function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * [VULN-005] Verificação CSRF para endpoints POST
 * Verifica header X-CSRF-Token ou campo POST csrf_token
 */
function require_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    
    $token = $_POST['csrf_token'] 
        ?? $_SERVER['HTTP_X_CSRF_TOKEN'] 
        ?? null;
    
    if (!$token || !verify_csrf_token($token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Token CSRF inválido. Recarregue a página e tente novamente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================
// HEADERS DE SEGURANÇA
// ============================================

/**
 * [VULN-013] CSP com nonce
 * [VULN-015] HSTS enforcement
 */
function set_security_headers() {
    $nonce = get_csp_nonce();
    
    // Proteção XSS
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // Content Security Policy com nonce
    // NOTA: 'unsafe-inline' mantido temporariamente para compatibilidade com
    // event handlers dinâmicos no JS. Refatorar para addEventListener em fase futura.
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "script-src 'self' 'unsafe-inline' 'nonce-{$nonce}'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com 'nonce-{$nonce}'; "
        . "img-src 'self' data:; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "media-src 'self' data:; "
        . "connect-src 'self';"
    );
    
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Permissions Policy
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    // Desabilitar informações do servidor
    header('Server: ');
    
    // [VULN-015] HSTS - Strict Transport Security
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    } else {
        // Redirecionar somente quando o VirtualHost HTTPS estiver configurado.
        $force_https = in_array(
            strtolower(trim((string)(getenv('FORCE_HTTPS') ?: 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        if (defined('APP_ENV') && APP_ENV === 'production' && $force_https) {
            $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('Location: ' . $redirect, true, 301);
            exit;
        }
    }
}

// ============================================
// SESSÃO SEGURA
// ============================================

function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        $secure_cookie = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $secure_cookie_env = getenv('SESSION_COOKIE_SECURE');
        if ($secure_cookie_env !== false && $secure_cookie_env !== '') {
            $secure_cookie = in_array(strtolower(trim($secure_cookie_env)), ['1', 'true', 'yes', 'on'], true);
        }
        $same_site = getenv('SESSION_COOKIE_SAMESITE') ?: 'Strict';
        if (!in_array($same_site, ['Strict', 'Lax', 'None'], true)) {
            $same_site = 'Strict';
        }

        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', $secure_cookie ? 1 : 0);
        ini_set('session.cookie_samesite', $same_site);
        ini_set('session.use_strict_mode', 1);
        
        $savePath = session_save_path();
        if (empty($savePath)) $savePath = sys_get_temp_dir();
        
        if (!is_writable($savePath)) {
            $localPath = __DIR__ . '/sessions';
            if (!file_exists($localPath)) @mkdir($localPath, 0755, true);
            if (is_writable($localPath)) session_save_path($localPath);
        }
        
        if (!@session_start()) {
            $localPath = __DIR__ . '/sessions';
            if (!file_exists($localPath)) @mkdir($localPath, 0755, true);
            if (is_writable($localPath)) {
                session_save_path($localPath);
                @session_start();
            }
        }
        
        // Regenerar ID periodicamente (30 min)
        if (isset($_SESSION['created'])) {
            if (time() - $_SESSION['created'] > 1800) {
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        } else {
            $_SESSION['created'] = time();
        }
    }
}

function public_error_message($exception, $production_message = 'Erro interno. Tente novamente ou contate o suporte.') {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        return $exception instanceof \Throwable ? $exception->getMessage() : (string)$exception;
    }
    return $production_message;
}

// ============================================
// [VULN-006] RATE LIMITING POR IP (FILE-BASED)
// ============================================

/**
 * Rate limiting baseado em IP usando arquivos temporários
 * NÃO pode ser contornado removendo cookies de sessão
 */
function check_rate_limit($key, $max_requests = 5, $time_window = 900) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $safe_key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
    $rate_file = sys_get_temp_dir() . '/chronodesk_rate_' . md5($safe_key . '_' . $ip) . '.json';
    $now = time();
    
    $handle = @fopen($rate_file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        error_log('[RATE_LIMIT] Falha ao bloquear arquivo de rate limit.');
        return false;
    }

    try {
        rewind($handle);
        $content = stream_get_contents($handle);
        $stored = $content ? json_decode($content, true) : null;
        $data = is_array($stored)
            ? $stored
            : ['count' => 0, 'reset' => $now + $time_window, 'first_attempt' => $now];

        if ($now > ($data['reset'] ?? 0)) {
            $data = ['count' => 0, 'reset' => $now + $time_window, 'first_attempt' => $now];
        }

        if (($data['count'] ?? 0) >= $max_requests) {
            return false;
        }

        $data['count'] = ($data['count'] ?? 0) + 1;
        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, json_encode($data)) === false) {
            error_log('[RATE_LIMIT] Falha ao persistir rate limit.');
            return false;
        }
        fflush($handle);
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// ============================================
// [VULN-017] SISTEMA DE AUDITORIA
// ============================================

/**
 * Registra evento de auditoria no banco de dados
 */
function audit_log($action, $details = '', $severity = 'INFO') {
    try {
        if (!function_exists('get_db_connection')) return;
        $pdo = get_db_connection();
        
        // Verificar se a tabela existe (silencioso)
        try {
            $pdo->query("SELECT 1 FROM audit_log LIMIT 1");
        } catch (\PDOException $e) {
            error_log('[AUDIT] Tabela audit_log indisponível; execute o schema de deploy.');
            return;
            // Tabela não existe, tentar criar
        }
        
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SESSION['admin_username'] ?? $_SESSION['username'] ?? $_SESSION['ci_username'] ?? 'anonymous',
            substr($action, 0, 100),
            substr($details, 0, 5000),
            in_array($severity, ['INFO', 'WARNING', 'CRITICAL']) ? $severity : 'INFO'
        ]);
    } catch (\Exception $e) {
        // Fallback para error_log se o banco falhar
        error_log("[AUDIT] [{$severity}] {$action}: {$details} - DB Error: " . $e->getMessage());
    }
}

// ============================================
// [VULN-022] VALIDAÇÃO DE CONTENT-TYPE
// ============================================

/**
 * Exige Content-Type application/json em requisições POST
 */
function require_json_content_type() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $media_type = strtolower(trim(explode(';', $ct, 2)[0]));
    if ($media_type !== 'application/json' && !str_ends_with($media_type, '+json')) {
        http_response_code(415);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Content-Type deve ser application/json'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function require_get_method() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Método não permitido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function session_window_is_current($login_time, $last_activity, $absolute_timeout, $idle_timeout, $now = null) {
    $now = $now ?? time();
    $login_time = (int)$login_time;
    $last_activity = (int)$last_activity;
    $absolute_timeout = (int)$absolute_timeout;
    $idle_timeout = (int)$idle_timeout;

    if ($login_time <= 0 || $last_activity <= 0) {
        return false;
    }
    if ($absolute_timeout > 0 && ($now - $login_time) > $absolute_timeout) {
        return false;
    }
    if ($idle_timeout > 0 && ($now - $last_activity) > $idle_timeout) {
        return false;
    }
    return true;
}

function ci_session_is_current(): bool {
    if (!isset($_SESSION['ci_logged_in']) || $_SESSION['ci_logged_in'] !== true) {
        return false;
    }

    $absolute_timeout = max(60, (int)(getenv('CI_SESSION_ABSOLUTE_TIMEOUT') ?: 28800));
    $idle_timeout = max(60, (int)(getenv('CI_SESSION_IDLE_TIMEOUT') ?: 1800));

    return session_window_is_current(
        $_SESSION['ci_login_time'] ?? 0,
        $_SESSION['ci_last_activity'] ?? 0,
        $absolute_timeout,
        $idle_timeout
    );
}

function require_post_method() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Metodo nao permitido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function with_pause_state_lock(callable $callback) {
    $state_path = defined('ESTADO_JSON') ? ESTADO_JSON : __DIR__ . '/estado.json';
    $lock_path = sys_get_temp_dir() . '/chronodesk_pause_' . md5($state_path) . '.lock';
    $handle = @fopen($lock_path, 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        error_log('[PAUSE_STATE] Falha ao adquirir lock de estado.');
        if (function_exists('json_response') && PHP_SAPI !== 'cli') {
            json_response([
                'sucesso' => false,
                'mensagem' => 'O estado de pausas esta temporariamente indisponivel.'
            ], 503);
        }
        throw new RuntimeException('Nao foi possivel bloquear o estado de pausas.');
    }

    try {
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function destroy_current_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            $params['secure'] ?? false,
            $params['httponly'] ?? true
        );
    }

    session_destroy();
}

function clear_ci_session() {
    unset(
        $_SESSION['ci_logged_in'],
        $_SESSION['ci_funcionario_id'],
        $_SESSION['ci_username'],
        $_SESSION['ci_nome'],
        $_SESSION['ci_login_time'],
        $_SESSION['ci_last_activity']
    );
}

// ============================================
// PATH TRAVERSAL & JSON VALIDATION
// ============================================

function safe_file_path($file_path, $base_directory) {
    $base_directory = realpath($base_directory);
    $file_path = realpath($file_path);
    if ($file_path === false) return false;
    if (substr($file_path, 0, strlen($base_directory)) !== $base_directory) return false;
    return $file_path;
}

function validate_json_input($json_string, $max_length = 10000) {
    if (strlen($json_string) > $max_length) return null;
    $data = json_decode($json_string, true);
    if (json_last_error() !== JSON_ERROR_NONE) return null;
    return $data;
}

function deep_clean_input($data) {
    if (is_array($data)) return array_map('deep_clean_input', $data);
    if (is_string($data)) return sanitize_input($data);
    if (is_int($data) || is_float($data)) return $data;
    if (is_bool($data)) return $data;
    return null;
}
