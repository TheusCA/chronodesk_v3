<?php
/**
 * ============================================================
 * ChronoDesk v3.0 - Autenticação LDAP / Active Directory
 * ============================================================
 * Domínio e servidores AD são definidos pelo ambiente.
 *
 * COMO FUNCIONA:
 * 1. O usuário digita seu login do Windows (ex: joao.silva) + senha do AD
 * 2. Este arquivo tenta conectar ao servidor LDAP (Active Directory)
 * 3. Se as credenciais baterem, retorna os dados do usuário do AD
 * 4. O sistema associa o ad_login ao funcionário cadastrado na tabela funcionarios
 *
 * PRÉ-REQUISITOS NO SERVIDOR LINUX:
 * - Extensão PHP LDAP habilitada: sudo apt install php-ldap
 * - PHP >= 7.4
 * - Acesso de rede aos servidores configurados na porta 389 (LDAP) ou 636 (LDAPS)
 * ============================================================
 */

/**
 * Tenta autenticar um usuário no Active Directory via LDAP
 *
 * @param string $username  Login do Windows sem domínio (ex: "joao.silva")
 * @param string $password  Senha do Windows
 * @return array|false      Array com dados do usuário AD em caso de sucesso, false em falha
 */
function autenticar_ad(string $username, string $password) {
    if (!function_exists('ldap_connect')) {
        error_log('[AUTH_AD] Extensão PHP LDAP não está habilitada.');
        if (function_exists('audit_log')) {
            audit_log('LDAP_EXTENSION_MISSING', 'Extensão PHP LDAP não habilitada', 'CRITICAL');
        }
        return false;
    }

    if (!function_exists('get_db_connection')) {
        require_once __DIR__ . '/db.php';
    }

    // Configurações do AD - mova para .env em produção
    $ad_domain    = getenv('AD_DOMAIN')    ?: 'gruponp.local';
    $ad_upn_suffix = getenv('AD_UPN_SUFFIX') ?: $ad_domain;
    $ad_servers_env = trim((string)(getenv('AD_SERVERS') ?: ''));
    if ($ad_servers_env === '') {
        error_log('[AUTH_AD] AD_SERVERS não configurado.');
        return false;
    }
    $ad_servers   = explode(',', $ad_servers_env);
    $ad_port      = (int)(getenv('AD_PORT') ?: 389);    // 389=LDAP, 636=LDAPS
    $ad_use_tls   = getenv('AD_USE_TLS') === 'true';    // StartTLS

    // Sanitização básica - login não deve conter caracteres perigosos para LDAP
    $login_info = normalizar_login_ldap($username, $ad_upn_suffix, $ad_domain);
    $username = $login_info['samaccountname'];
    if (!$username || !$password) return false;

    // Tentar cada servidor AD em ordem (failover)
    foreach ($ad_servers as $server) {
        $server = trim($server);
        $ldap_uri = "ldap://{$server}:{$ad_port}";

        $conn = @ldap_connect($ldap_uri);
        if (!$conn) continue;

        // Configurações LDAP
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5); // 5s timeout por servidor

        // StartTLS se configurado
        if ($ad_use_tls) {
            if (!@ldap_start_tls($conn)) {
                error_log("[AUTH_AD] Falha no StartTLS para {$server}");
                ldap_close($conn);
                continue;
            }
        }

        // Tentar bind com os UPNs em ordem de preferência
        $bind = false;
        foreach ($login_info['bind_upns'] as $bind_dn) {
            $bind = @ldap_bind($conn, $bind_dn, $password);
            if ($bind) {
                break;
            }
        }

        if ($bind) {
            // Autenticação bem-sucedida - buscar atributos do usuário no AD
            $user_data = buscar_atributos_usuario($conn, $username, $ad_domain);
            ldap_close($conn);

            error_log("[AUTH_AD] Login bem-sucedido: {$username} via {$server}");
            return $user_data ?: [
                'login'        => $username,
                'display_name' => $username,
                'email'        => $username . '@' . $ad_upn_suffix,
                'groups'       => []
            ];
        }

        // Bind falhou - verificar se é senha errada ou servidor indisponível
        $ldap_errno = ldap_errno($conn);
        ldap_close($conn);

        // Erro 49 = credenciais inválidas. Qualquer outro erro = problema de conexão
        if ($ldap_errno === 49) {
            error_log("[AUTH_AD] Credenciais inválidas para: {$username}");
            return false; // Falha de autenticação definitiva (não tentar próximo servidor)
        }

        // Outro erro (timeout, servidor down) → tentar próximo servidor
        error_log("[AUTH_AD] Erro LDAP {$ldap_errno} no servidor {$server}, tentando próximo...");
    }

    error_log("[AUTH_AD] Todos os servidores AD falharam para: {$username}");
    if (function_exists('audit_log')) {
        audit_log('LDAP_SERVERS_UNAVAILABLE', 'Falha de autenticação AD por indisponibilidade dos servidores configurados', 'WARNING');
    }
    return false;
}

/**
 * Busca atributos do usuário no AD após bind bem-sucedido
 */
function buscar_atributos_usuario($conn, string $username, string $domain): array {
    // Converter domínio em base DN: gruponp.local → DC=gruponp,DC=local
    $base_dn_parts = array_map(function($part) {
        return 'DC=' . $part;
    }, explode('.', $domain));
    $base_dn = implode(',', $base_dn_parts);

    $filter     = "(sAMAccountName=" . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ")";
    $attributes = ['displayName', 'mail', 'memberOf', 'sAMAccountName', 'department'];

    $search = @ldap_search($conn, $base_dn, $filter, $attributes, 0, 1, 5);
    if (!$search) return ['login' => $username];

    $entries = ldap_get_entries($conn, $search);
    if (!$entries || $entries['count'] === 0) return ['login' => $username];

    $entry  = $entries[0];
    $groups = [];

    if (isset($entry['memberof'])) {
        for ($i = 0; $i < $entry['memberof']['count']; $i++) {
            // Extrair nome do grupo do DN (CN=NomeGrupo,OU=...)
            if (preg_match('/^CN=([^,]+)/i', $entry['memberof'][$i], $m)) {
                $groups[] = $m[1];
            }
        }
    }

    return [
        'login'        => $username,
        'display_name' => $entry['displayname'][0]   ?? $username,
        'email'        => $entry['mail'][0]            ?? ($username . '@' . $domain),
        'department'   => $entry['department'][0]      ?? '',
        'groups'       => $groups,
    ];
}

/**
 * Sanitização do username para LDAP
 * Remove caracteres especiais perigosos para evitar LDAP Injection
 */
function normalizar_login_ldap(string $login, string $ad_upn_suffix, string $ad_domain): array {
    $login = trim($login);
    if (
        $login === ''
        || strlen($login) > 150
        || preg_match('/^[a-zA-Z0-9.\-_@]+$/', $login) !== 1
        || substr_count($login, '@') > 1
    ) {
        return ['samaccountname' => '', 'bind_upns' => []];
    }

    $username = $login;
    $explicit_upn = '';
    if (strpos($login, '@') !== false) {
        list($user_part, $suffix) = explode('@', $login, 2);
        $username = $user_part;
        if ($user_part !== '' && $suffix !== '') {
            $allowed_suffixes = array_filter(array_unique([
                strtolower(trim($ad_upn_suffix)),
                strtolower(trim($ad_domain)),
            ]));
            if (!in_array(strtolower($suffix), $allowed_suffixes, true)) {
                return ['samaccountname' => '', 'bind_upns' => []];
            }
            $explicit_upn = strtolower($user_part . '@' . $suffix);
        }
    }

    $username = strtolower($username);
    if (strlen($username) < 2 || strlen($username) > 100) {
        return ['samaccountname' => '', 'bind_upns' => []];
    }

    $bind_upns = [];
    if ($explicit_upn) {
        $bind_upns[] = $explicit_upn;
    }

    if ($ad_upn_suffix) {
        $bind_upns[] = strtolower($username . '@' . $ad_upn_suffix);
    }

    if ($ad_domain && strcasecmp($ad_domain, $ad_upn_suffix) !== 0) {
        $bind_upns[] = strtolower($username . '@' . $ad_domain);
    }

    $bind_upns = array_values(array_unique($bind_upns));
    return ['samaccountname' => $username, 'bind_upns' => $bind_upns];
}

function sanitize_ldap_username(string $username, string $allowed_suffix = ''): string {
    $login_info = normalizar_login_ldap($username, $allowed_suffix, $allowed_suffix);
    return $login_info['samaccountname'];
}

/**
 * Busca o funcionário no banco de dados pelo ad_login
 * Retorna o array do funcionário ou null se não encontrado
 */
function buscar_funcionario_por_ad_login(string $ad_login): ?array {
    if (!function_exists('get_db_connection')) {
        require_once __DIR__ . '/db.php';
    }

    $ad_login = normalizar_samaccountname($ad_login);
    if ($ad_login === null) {
        return null;
    }

    try {
        $pdo = get_db_connection();
        $roleColumn = function_exists('funcionarios_access_role_supported')
            && funcionarios_access_role_supported()
            ? 'access_role'
            : "'tecnico' AS access_role";
        $stmt = $pdo->prepare(
            "SELECT id, nome, equipe, {$roleColumn}, ativo
             FROM funcionarios
             WHERE (LOWER(ad_login) = :login OR LOWER(ad_login_ativo) = :active_login)
               AND ativo = 1
             LIMIT 1"
        );
        $stmt->execute([
            ':login' => $ad_login,
            ':active_login' => $ad_login,
        ]);
        $row = $stmt->fetch();
        if ($row) {
            $row['id'] = (int)$row['id'];
            return $row;
        }
    } catch (Exception $e) {
        error_log("[AUTH_AD] Erro ao buscar funcionário por AD login: " . $e->getMessage());
    }

    if (function_exists('carregar_funcionarios_sistema')) {
        $funcionarios = carregar_funcionarios_sistema();
        foreach ($funcionarios as $funcionario) {
            if (!isset($funcionario['ad_login'])) {
                continue;
            }
            if (normalizar_samaccountname($funcionario['ad_login']) === $ad_login && (!isset($funcionario['ativo']) || $funcionario['ativo'])) {
                return [
                    'id' => (int)$funcionario['id'],
                    'nome' => $funcionario['nome'],
                    'equipe' => $funcionario['equipe'],
                    'access_role' => $funcionario['access_role'] ?? 'tecnico',
                    'ativo' => true,
                ];
            }
        }
    }

    return null;
}

function normalizar_nome_ad(string $nome): string {
    $nome = function_exists('mb_strtolower')
        ? mb_strtolower($nome, 'UTF-8')
        : strtolower($nome);
    $nome = trim($nome);
    if (class_exists('Transliterator')) {
        $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($transliterator) {
            $nome = $transliterator->transliterate($nome);
        }
    }
    $nome = preg_replace('/[^a-z0-9]+/u', ' ', $nome);
    return trim(preg_replace('/\s+/', ' ', $nome));
}

function tentar_vincular_funcionario_ad(array $ad_user): ?array {
    $auto_link_enabled = in_array(
        strtolower(trim((string)(getenv('ENABLE_AD_AUTO_LINK') ?: 'false'))),
        ['1', 'true', 'yes', 'on'],
        true
    );
    if (!$auto_link_enabled) {
        return null;
    }

    $login = normalizar_samaccountname($ad_user['login'] ?? '');
    $display_name = normalizar_nome_ad((string)($ad_user['display_name'] ?? ''));
    if ($login === null || $display_name === '') {
        return null;
    }

    try {
        $pdo = get_db_connection();
        $roleColumn = function_exists('funcionarios_access_role_supported')
            && funcionarios_access_role_supported()
            ? 'access_role'
            : "'tecnico' AS access_role";
        $stmt = $pdo->query(
            "SELECT id, nome, equipe, {$roleColumn}, ativo
             FROM funcionarios
             WHERE ativo = 1 AND (ad_login IS NULL OR ad_login = '')"
        );
        $matches = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($row) => normalizar_nome_ad((string)$row['nome']) === $display_name
        ));
        if (count($matches) !== 1) {
            return null;
        }

        $funcionario = $matches[0];
        $update = $pdo->prepare(
            "UPDATE funcionarios
             SET ad_login = :login
             WHERE id = :id AND ativo = 1 AND (ad_login IS NULL OR ad_login = '')"
        );
        $update->execute([':login' => $login, ':id' => (int)$funcionario['id']]);
        if ($update->rowCount() !== 1) {
            return null;
        }

        audit_log(
            'AD_LOGIN_AUTO_LINK',
            'Login AD vinculado automaticamente ao funcionário ID ' . (int)$funcionario['id'],
            'INFO'
        );
        $funcionario['id'] = (int)$funcionario['id'];
        return $funcionario;
    } catch (Throwable $e) {
        error_log('[AUTH_AD] Falha no vínculo automático: ' . $e->getMessage());
        return null;
    }
}

/**
 * Fluxo completo de autenticação do CI via AD
 * Usado nas páginas de pausa que requerem identificação do CI
 *
 * @param string $username   Login do Windows
 * @param string $password   Senha do Windows
 * @return array             ['sucesso' => bool, 'funcionario' => array|null, 'mensagem' => string]
 */
function autenticar_ci_via_ad(string $username, string $password): array {
    // 1. Validar no AD
    $ad_user = autenticar_ad($username, $password);
    if (!$ad_user) {
        return [
            'sucesso'      => false,
            'funcionario'  => null,
            'mensagem'     => 'Login ou senha incorretos. Utilize suas credenciais do AD corporativo.'
        ];
    }

    // 2. Verificar se o login está associado a um funcionário no sistema
    $funcionario = buscar_funcionario_por_ad_login($ad_user['login']);
    if (!$funcionario) {
        $funcionario = tentar_vincular_funcionario_ad($ad_user);
    }
    if (!$funcionario) {
        return [
            'sucesso'      => false,
            'funcionario'  => null,
            'mensagem'     => "Usuário '{$ad_user['login']}' não está cadastrado como CI no sistema. Contate o administrador."
        ];
    }

    return [
        'sucesso'      => true,
        'funcionario'  => $funcionario,
        'ad_user'      => $ad_user,
        'mensagem'     => "Autenticado como {$funcionario['nome']}."
    ];
}

function ci_sessao_autenticada_para_funcionario($funcionario_id): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!ci_session_is_current()) {
        if (isset($_SESSION['ci_logged_in']) && $_SESSION['ci_logged_in'] === true) {
            if (function_exists('audit_log')) {
                audit_log('CI_SESSION_EXPIRED', 'Sessao CI expirada por timeout para funcionario ID ' . (int)($_SESSION['ci_funcionario_id'] ?? 0), 'INFO');
            }
            clear_ci_session();
            json_response(['sucesso' => false, 'mensagem' => 'Sessão CI expirada. Faça login novamente.'], 401);
        }
        return false;
    }

    $now = time();
    $_SESSION['ci_last_activity'] = $now;

    $session_funcionario_id = (int)($_SESSION['ci_funcionario_id'] ?? 0);
    if ($session_funcionario_id !== (int)$funcionario_id) {
        if (function_exists('audit_log')) {
            audit_log(
                'CI_PAUSA_OUTRO_FUNCIONARIO',
                'Sessao CI tentou agir para funcionario ID ' . (int)$funcionario_id . ' usando sessao do funcionario ID ' . $session_funcionario_id,
                'CRITICAL'
            );
        }
        json_response([
            'sucesso' => false,
            'mensagem' => 'Sua sessão CI não pertence ao funcionário selecionado.'
        ], 403);
    }

    if (function_exists('carregar_funcionarios_sistema')) {
        foreach (carregar_funcionarios_sistema() as $funcionario) {
            if ((int)($funcionario['id'] ?? 0) === $session_funcionario_id) {
                if (!($funcionario['ativo'] ?? true)) {
                    json_response(['sucesso' => false, 'mensagem' => 'Funcionário inativo não pode executar ações de pausa.'], 403);
                }
                return true;
            }
        }
    }

    json_response(['sucesso' => false, 'mensagem' => 'Sessão CI inválida. Faça login novamente.'], 401);
    return false;
}

/**
 * Exige autenticação AD do próprio CI antes de ações de pausa.
 */
function exigir_autenticacao_ci_pausa($data, $funcionario_id, $contexto = 'pausa') {
    if (!is_array($data)) {
        json_response(['sucesso' => false, 'mensagem' => 'Dados inválidos.'], 400);
    }

    if (current_portal_role() === 'somente_leitura') {
        audit_log('CI_PAUSE_READ_ONLY_DENIED', 'Perfil somente leitura tentou executar acao de pausa.', 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Seu perfil possui acesso somente para leitura.'], 403);
    }

    if (ci_sessao_autenticada_para_funcionario($funcionario_id)) {
        return [
            'sucesso' => true,
            'funcionario' => [
                'id' => (int)$_SESSION['ci_funcionario_id'],
                'nome' => $_SESSION['ci_nome'] ?? '',
                'ativo' => true,
            ],
            'ad_user' => [
                'login' => $_SESSION['ci_username'] ?? '',
            ],
            'mensagem' => 'Sessão CI autenticada.'
        ];
    }

    $login_ad = sanitize_input($data['login_ad'] ?? '', 150);
    $senha_ad = isset($data['senha_ad']) ? (string)$data['senha_ad'] : '';

    if (!$login_ad || !$senha_ad) {
        json_response(['sucesso' => false, 'mensagem' => 'Informe login e senha do AD.'], 400);
    }

    $rate_key = 'ad_' . $contexto . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($login_ad));
    if (function_exists('check_rate_limit') && !check_rate_limit($rate_key, 5, 300)) {
        json_response(['sucesso' => false, 'mensagem' => 'Muitas tentativas de autenticação. Aguarde alguns minutos e tente novamente.'], 429);
    }

    $autenticacao = autenticar_ci_via_ad($login_ad, $senha_ad);
    if (!$autenticacao['sucesso']) {
        if (function_exists('audit_log')) {
            audit_log('CI_AD_LOGIN_FAILURE', 'Falha de autenticacao AD no contexto ' . $contexto . ' para login ' . normalizar_samaccountname($login_ad), 'WARNING');
        }
        json_response(['sucesso' => false, 'mensagem' => $autenticacao['mensagem']], 401);
    }
    if (function_exists('clear_rate_limit')) {
        clear_rate_limit($rate_key);
    }

    $funcionario_autenticado_id = (int)($autenticacao['funcionario']['id'] ?? 0);
    if ($funcionario_autenticado_id !== (int)$funcionario_id) {
        if (function_exists('audit_log')) {
            audit_log(
                'CI_PAUSA_OUTRO_FUNCIONARIO',
                'Tentativa de ' . $contexto . ' para funcionario ID ' . (int)$funcionario_id . ' usando credenciais do funcionario ID ' . $funcionario_autenticado_id,
                'CRITICAL'
            );
        }
        json_response([
            'sucesso' => false,
            'mensagem' => 'As credenciais informadas não pertencem ao CI selecionado.'
        ], 403);
    }

    return $autenticacao;
}
