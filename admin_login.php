<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_assets.php';
require_once __DIR__ . '/classes/Usuario.php';

// Se já estiver logado como admin, redirecionar
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $base_path = get_base_path();
    header('Location: ' . $base_path . '/admin.php');
    exit;
}

$error = '';
$usuarioModel = new Usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [VULN-005] Verificar CSRF no formulário de login
    require_csrf_token();
    // Rate limiting básico (5 tentativas por 15 minutos)
    if (!check_rate_limit('admin_login', 5, 900)) {
        $error = 'Muitas tentativas de login. Aguarde 15 minutos antes de tentar novamente.';
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $authenticated = false;
        
        // 1. Tentar autenticação via Banco de Dados
        $user = $usuarioModel->autenticar($username, $password);
        if ($user) {
            if ($user['role'] === 'admin') {
                $authenticated = true;
            } else {
                $error = 'Acesso permitido apenas para administradores.';
            }
        }
        
        // 2. Fallback: Configuração (se não autenticou no banco e não houve erro específico de permissão)
        if (!$authenticated && empty($error)) {
            $config = carregar_configuracao();
            $admin_username = $config['admin_username'] ?? ADMIN_USERNAME;
            $admin_password_hash = $config['admin_password_hash'] ?? null;
            
            if ($username === $admin_username && $admin_password_hash && verify_password($password, $admin_password_hash)) {
                $authenticated = true;
                
                // Auto-migração: Criar este usuário no banco se não existir
                try {
                    if (!$usuarioModel->autenticar($username, $password)) {
                         $usuarioModel->criar($username, $password, 'admin');
                    }
                } catch (Exception $e) {
                    // Ignorar erro de criação duplicada ou outros na migração silenciosa
                }
            }
        }
        
        if ($authenticated) {
            // Regenerar ID de sessão após login bem-sucedido
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);
            
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = sanitize_output($username);
            $_SESSION['login_time'] = time();
            
            $base_path = get_base_path();
            $admin_url = $base_path . '/admin.php';
            header('Location: ' . $admin_url);
            exit;
        } else {
            if (empty($error)) {
                $error = 'Credenciais inválidas';
            }
            error_log("Tentativa de login admin falhou para usuário: " . $username);
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
    <title>Login Administrativo - ChronoDesk</title>
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
            <h2>Acesso Restrito</h2>
            <p style="text-align: center; color: var(--text-muted); margin-bottom: 1.5rem;">Painel Administrativo</p>
            
            <?php if ($error): ?>
                <div class="error-message">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="form-group">
                    <label for="username">Usuário Administrador:</label>
                    <input type="text" id="username" name="username" required autofocus placeholder="Usuário admin">
                </div>
                
                <div class="form-group">
                    <label for="password">Senha:</label>
                    <input type="password" id="password" name="password" required placeholder="Senha de acesso">
                </div>
                
                <button type="submit" class="login-btn">Acessar Painel</button>
            </form>
            
            <a href="<?php echo get_base_path(); ?>/index.php" class="back-link">← Voltar ao ChronoDesk</a>
        </div>
    </div>
</body>
</html>
