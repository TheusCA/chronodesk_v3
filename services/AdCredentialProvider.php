<?php
declare(strict_types=1);

interface AdCredentialProvider {
    /**
     * @return array{username: string, password: string}|null
     */
    public function credentials(): ?array;

    public function source(): string;
}

final class NullAdCredentialProvider implements AdCredentialProvider {
    public function credentials(): ?array {
        return null;
    }

    public function source(): string {
        return 'none';
    }
}

final class EnvAdCredentialProvider implements AdCredentialProvider {
    public function credentials(): ?array {
        $username = trim((string)(getenv('AD_BIND_USER') ?: ''));
        $password = (string)(getenv('AD_BIND_PASS') ?: '');
        if ($username === '' && $password === '') {
            return null;
        }
        if ($username === '' || $password === '') {
            throw new RuntimeException('Credenciais AD de servico incompletas no ambiente.');
        }
        self::assertCredentialValues($username, $password);

        return ['username' => $username, 'password' => $password];
    }

    public function source(): string {
        return 'env';
    }

    private static function assertCredentialValues(string $username, string $password): void {
        if (
            strlen($username) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $username) === 1
            || strlen($password) > 4096
            || strpos($password, "\0") !== false
        ) {
            throw new RuntimeException('Credenciais AD de servico invalidas.');
        }
    }
}

final class RuntimeFileAdCredentialProvider implements AdCredentialProvider {
    private const MAX_SECRET_FILE_BYTES = 4096;

    private string $usernamePath;
    private string $passwordPath;

    public function __construct(string $usernamePath, string $passwordPath) {
        $this->usernamePath = $usernamePath;
        $this->passwordPath = $passwordPath;
    }

    public function credentials(): ?array {
        $usernameExists = is_file($this->usernamePath);
        $passwordExists = is_file($this->passwordPath);
        if (!$usernameExists && !$passwordExists) {
            return null;
        }
        if (!$usernameExists || !$passwordExists) {
            throw new RuntimeException('Arquivos de credencial AD de servico incompletos.');
        }

        $username = trim($this->readSecretFile($this->usernamePath));
        $password = rtrim($this->readSecretFile($this->passwordPath), "\r\n");
        if ($username === '' || $password === '') {
            throw new RuntimeException('Credenciais AD de servico vazias.');
        }
        if (
            strlen($username) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $username) === 1
            || strpos($password, "\0") !== false
        ) {
            throw new RuntimeException('Credenciais AD de servico invalidas.');
        }

        return ['username' => $username, 'password' => $password];
    }

    public function source(): string {
        return 'runtime_file';
    }

    private function readSecretFile(string $path): string {
        if ($path === '' || strpos($path, "\0") !== false || !$this->isAbsolutePath($path)) {
            throw new RuntimeException('Caminho de credencial AD invalido.');
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || is_link($path)) {
            throw new RuntimeException('Arquivo de credencial AD invalido.');
        }
        $this->assertOutsideWebroot($resolved);

        $size = filesize($resolved);
        if ($size === false || $size < 1 || $size > self::MAX_SECRET_FILE_BYTES) {
            throw new RuntimeException('Arquivo de credencial AD possui tamanho invalido.');
        }
        if (!is_readable($resolved)) {
            throw new RuntimeException('Arquivo de credencial AD nao esta legivel pelo processo PHP.');
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $permissions = fileperms($resolved);
            if ($permissions !== false && ($permissions & 0x0007) !== 0) {
                throw new RuntimeException('Arquivo de credencial AD nao pode ser acessivel por outros usuarios.');
            }
        }

        $content = file_get_contents($resolved);
        if ($content === false || strlen($content) > self::MAX_SECRET_FILE_BYTES) {
            throw new RuntimeException('Falha ao ler credencial AD de servico.');
        }
        return $content;
    }

    private function assertOutsideWebroot(string $path): void {
        $blockedRoots = [realpath(dirname(__DIR__))];
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== '') {
            $blockedRoots[] = realpath($documentRoot);
        }

        $normalizedPath = $this->normalizePath($path);
        foreach (array_filter($blockedRoots) as $root) {
            $normalizedRoot = rtrim($this->normalizePath((string)$root), '/') . '/';
            if (strpos($normalizedPath . '/', $normalizedRoot) === 0) {
                throw new RuntimeException('Credencial AD nao pode ficar dentro do projeto ou webroot.');
            }
        }
    }

    private function isAbsolutePath(string $path): bool {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1;
    }

    private function normalizePath(string $path): string {
        $path = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}

final class AdCredentialProviderFactory {
    public static function fromEnvironment(): AdCredentialProvider {
        $provider = strtolower(trim((string)(getenv('AD_CREDENTIAL_PROVIDER') ?: 'none')));
        if ($provider === 'none') {
            return new NullAdCredentialProvider();
        }
        if ($provider === 'env') {
            return new EnvAdCredentialProvider();
        }
        if ($provider === 'runtime_file') {
            return new RuntimeFileAdCredentialProvider(
                (string)(getenv('AD_BIND_USER_FILE') ?: '/run/chronodesk/ad-bind-user'),
                (string)(getenv('AD_BIND_PASS_FILE') ?: '/run/chronodesk/ad-bind-pass')
            );
        }

        throw new RuntimeException('AD_CREDENTIAL_PROVIDER invalido.');
    }
}
