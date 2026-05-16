<?php
/**
 * ============================================================
 * ChronoDesk v3.0 - Autenticação LDAP / Active Directory
 * ============================================================
 * Domínio AD da empresa: gruponp.local
 * Servidores AD: 10.108.50.206 / 10.110.53.205 / 10.108.50.205
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
 * - Acesso de rede aos IPs dos servidores AD (10.108.50.x) na porta 389 (LDAP) ou 636 (LDAPS)
 * ============================================================
 */

/**
 * Tenta autenticar um usuário no Active Directory via LDAP
 *
 * @param string $username  Login do Windows sem domínio (ex: "joao.silva")
 * @param string $password  Senha do Windows
 * @return array|false      Array com dados do usuário AD em caso de sucesso, false em falha
 */
function autenticar_ad(string $username, string $password): array|false {
    // Configurações do AD - mova para .env em produção
    $ad_domain    = getenv('AD_DOMAIN')    ?: 'gruponp.local';
    $ad_servers   = explode(',', getenv('AD_SERVERS') ?: '10.108.50.206,10.110.53.205,10.108.50.205');
    $ad_port      = (int)(getenv('AD_PORT') ?: 389);    // 389=LDAP, 636=LDAPS
    $ad_use_tls   = getenv('AD_USE_TLS') === 'true';    // StartTLS

    // Sanitização básica - login não deve conter caracteres perigosos para LDAP
    $username = sanitize_ldap_username($username);
    if (!$username || !$password) return false;

    // Formato UPN para bind: usuario@dominio
    $bind_dn = $username . '@' . $ad_domain;

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

        // Tentar bind com as credenciais do usuário
        $bind = @ldap_bind($conn, $bind_dn, $password);

        if ($bind) {
            // Autenticação bem-sucedida - buscar atributos do usuário no AD
            $user_data = buscar_atributos_usuario($conn, $username, $ad_domain);
            ldap_close($conn);

            error_log("[AUTH_AD] Login bem-sucedido: {$username} via {$server}");
            return $user_data ?: [
                'login'        => $username,
                'display_name' => $username,
                'email'        => $username . '@' . $ad_domain,
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
    return false;
}

/**
 * Busca atributos do usuário no AD após bind bem-sucedido
 */
function buscar_atributos_usuario($conn, string $username, string $domain): array {
    // Converter domínio em base DN: gruponp.local → DC=gruponp,DC=local
    $base_dn = implode(',', array_map(
        fn($part) => 'DC=' . $part,
        explode('.', $domain)
    ));

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
function sanitize_ldap_username(string $username): string {
    $username = trim($username);
    // Apenas letras, números, ponto, hífen, underscore e arroba
    $username = preg_replace('/[^a-zA-Z0-9.\-_@]/', '', $username);
    // Remover @ se presente (aceitar "joao.silva" ou "joao.silva@gruponp.local")
    $username = explode('@', $username)[0];
    return strlen($username) >= 2 ? $username : '';
}

/**
 * Busca o funcionário no banco de dados pelo ad_login
 * Retorna o array do funcionário ou null se não encontrado
 */
function buscar_funcionario_por_ad_login(string $ad_login): ?array {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            "SELECT id, nome, equipe, ativo FROM funcionarios WHERE ad_login = :login AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([':login' => strtolower(trim($ad_login))]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Exception $e) {
        error_log("[AUTH_AD] Erro ao buscar funcionário por AD login: " . $e->getMessage());
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
            'mensagem'     => 'Login ou senha incorretos. Use suas credenciais do Windows.'
        ];
    }

    // 2. Verificar se o login está associado a um funcionário no sistema
    $funcionario = buscar_funcionario_por_ad_login($ad_user['login']);
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
