<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_assets.php';
require_once __DIR__ . '/auth_ldap.php';

if (usuario_pode_acessar_metricas()) {
    header('Location: ' . get_base_path() . '/metricas.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $username_input = sanitize_input($_POST['username'] ?? '', 150);
    $password = (string)($_POST['password'] ?? '');
    $username = normalizar_samaccountname($username_input);

    if (!check_rate_limit('metricas_login', 5, 900)) {
        $error = 'Muitas tentativas de login. Aguarde 15 minutos antes de tentar novamente.';
    } elseif ($username === null || $password === '') {
        $error = 'Informe seu login e senha do AD.';
    } else {
        $authenticated = false;
        $auth_type = '';

        $ad_user = autenticar_ad($username_input, $password);
        if ($ad_user) {
            $ad_username = normalizar_samaccountname($ad_user['login'] ?? $username);
            if ($ad_username !== null && is_ad_admin_authorized($ad_username)) {
                $authenticated = true;
                $auth_type = 'ad';
                $username = $ad_username;
            } else {
                $error = 'Usuário AD autenticado, mas não autorizado como gestor ou administrador.';
            }
        }

        if (!$authenticated && $error === '' && ENABLE_LOCAL_ADMIN) {
            require_once __DIR__ . '/classes/Usuario.php';
            try {
                $user = (new Usuario())->autenticar($username, $password);
                if ($user && in_array($user['role'], ['admin', 'gestor'], true)) {
                    $authenticated = true;
                    $auth_type = 'local';
                }
            } catch (Throwable $e) {
                error_log('[METRICAS_LOGIN] Falha na autenticação local: ' . $e->getMessage());
            }
        }

        $password = '';
        if ($authenticated) {
            clear_rate_limit('metricas_login');
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['auth_type'] = $auth_type;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            audit_log('ADMIN_LOGIN_SUCCESS', 'Acesso às métricas via ' . $auth_type . ' para ' . $username, 'INFO');
            header('Location: ' . get_base_path() . '/metricas.php');
            exit;
        }

        if ($error === '') {
            $error = 'Login ou senha incorretos.';
        }
        audit_log('ADMIN_LOGIN_FAILURE', 'Falha de acesso às métricas para ' . ($username ?? 'inválido'), 'WARNING');
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>Login de métricas - ChronoDesk</title>
    <link rel="icon" href="<?php echo sanitize_attr(get_base_path() . '/app/favicon.svg'); ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?php echo sanitize_attr(get_base_path() . '/static/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo sanitize_attr(get_base_path() . '/static/css/login.css'); ?>">
    <script src="<?php echo sanitize_attr(get_base_path() . '/static/js/theme.js'); ?>" defer></script>
</head>
<body class="dark-mode">
    <main class="login-container">
        <section class="login-box" aria-labelledby="login-title">
            <div class="logo-container"><div class="brand-logo-text">ChronoDesk</div></div>
            <h2 id="login-title">Acesso às métricas</h2>
            <p class="login-description">Use suas credenciais corporativas.</p>

            <?php if ($error !== ''): ?>
                <div class="error-message" role="alert"><?php echo sanitize_output($error); ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize_attr(generate_csrf_token()); ?>">
                <div class="form-group">
                    <label for="username">Login AD</label>
                    <input type="text" id="username" name="username" autocomplete="username" required autofocus
                           placeholder="usuario ou usuario@paschoalotto.com.br">
                </div>
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>
                <button type="submit" class="login-btn">Entrar</button>
            </form>
            <a href="<?php echo sanitize_attr(get_base_path() . '/index.php'); ?>" class="back-link">Voltar ao ChronoDesk</a>
        </section>
    </main>
</body>
</html>
