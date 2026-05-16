<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_assets.php';
require_once __DIR__ . '/classes/Usuario.php';

// Se já estiver logado, redirecionar para métricas
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $base_path = get_base_path();
    $metricas_url = $base_path . '/metricas.php';
    header('Location: ' . $metricas_url);
    exit;
}

$error = '';
$usuarioModel = new Usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [VULN-005] Verificar CSRF no formulário de login
    require_csrf_token();
    // Rate limiting básico (5 tentativas por 15 minutos)
    if (!check_rate_limit('metricas_login', 5, 900)) {
        $error = 'Muitas tentativas de login. Aguarde 15 minutos antes de tentar novamente.';
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $authenticated = false;
        
        // 1. Tentar autenticação via Banco de Dados
        $user = $usuarioModel->autenticar($username, $password);
        if ($user) {
            // Qualquer usuário (admin ou gestor) pode acessar métricas
            $authenticated = true;
        }
        
        // 2. Fallback: Configuração (se não autenticou no banco)
        if (!$authenticated) {
            $config = carregar_configuracao();
            $admin_username = $config['admin_username'] ?? ADMIN_USERNAME;
            $admin_password_hash = $config['admin_password_hash'] ?? null;
            
            if ($username === $admin_username && $admin_password_hash && verify_password($password, $admin_password_hash)) {
                $authenticated = true;
                
                // Auto-migração silenciosa
                try {
                    if (!$usuarioModel->autenticar($username, $password)) {
                         $usuarioModel->criar($username, $password, 'admin');
                    }
                } catch (Exception $e) { }
            }
        }
        
        if ($authenticated) {
            // Regenerar ID de sessão após login bem-sucedido
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);
            
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = sanitize_output($username);
            $_SESSION['login_time'] = time();
            
            $base_path = get_base_path();
            
            // Verificar se há URL de retorno (com sanitização)
            // [FIX] Open Redirect: validar que return_url é relativo e pertence ao app
            $return_url = isset($_GET['return']) ? sanitize_input($_GET['return']) : '';
            if (!empty($return_url)) {
                // Rejeitar qualquer coisa com esquema (http://, //, etc.)
                if (preg_match('/^(\/\/|https?:|javascript:|data:)/i', $return_url)) {
                    $return_url = '';
                }
                // Garantir que começa com / (relativo)
                if (!empty($return_url) && $return_url[0] === '/') {
                    $redirect_url = $base_path . $return_url;
                } else {
                    $redirect_url = $base_path . '/metricas.php';
                }
            } else {
                $redirect_url = $base_path . '/metricas.php';
            }
            
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $error = 'Credenciais inválidas';
            error_log("Tentativa de login métricas falhou para usuário: " . $username);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Login - ChronoDesk</title>
    <?php
    $base_path = dirname($_SERVER['SCRIPT_NAME']);
    if ($base_path === '/') {
        $base_path = '';
    } else {
        $base_path = rtrim($base_path, '/');
    }
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path . '/static/css/style.css?v=' . time()); ?>" type="text/css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path . '/static/css/login.css?v=' . time()); ?>" type="text/css">
    <script src="static/js/theme.js" defer></script>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="theme-toggle-container">
                <button id="theme-toggle" class="btn-theme-toggle" title="Alternar Tema">🌙</button>
            </div>
            <div class="logo-container">
                <img src="static/img/logo.png" alt="ChronoDesk Logo" class="logo-img">
            </div>
            <h2>Acesso às Métricas</h2>
            
            <?php if ($error): ?>
                <div class="error-message">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="form-group">
                    <label for="username">👤 Usuário:</label>
                    <input type="text" id="username" name="username" required placeholder="Digite seu usuário">
                </div>
                
                <div class="form-group">
                    <label for="password">🔒 Senha:</label>
                    <input type="password" id="password" name="password" required placeholder="Digite sua senha">
                </div>
                
                <button type="submit" class="login-btn">🚀 Entrar</button>
            </form>
            
            <a href="<?php echo get_base_path(); ?>/index.php" class="back-link">🏠 Voltar ao ChronoDesk</a>
        </div>
    </div>
</body>
</html>
