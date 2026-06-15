<?php
require_once __DIR__ . '/../db.php';

final class DocumentService {
    public const MAX_FILE_BYTES = 10485760;
    public const MAX_REQUEST_BYTES = 12582912;
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xlsx', 'csv', 'txt', 'md', 'png', 'jpg', 'jpeg',
    ];
    public const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'exe', 'bat',
        'cmd', 'ps1', 'sh', 'jar', 'msi', 'dll', 'zip', 'rar', '7z',
    ];
    public const ALLOWED_STATUSES = ['active', 'archived', 'deleted'];
    public const ALLOWED_VISIBILITY = ['internal', 'management'];
    private const MAX_OFFICE_ARCHIVE_ENTRIES = 2000;
    private const MAX_OFFICE_METADATA_BYTES = 1048576;
    private const MAX_OFFICE_UNCOMPRESSED_BYTES = 67108864;
    private const MAX_OFFICE_ENTRY_BYTES = 33554432;
    private const MAX_OFFICE_COMPRESSION_RATIO = 100;

    private const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => [
            'application/msword',
            'application/vnd.ms-office',
            'application/x-ole-storage',
            'application/cdfv2',
        ],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'md' => ['text/markdown', 'text/plain'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    private PDO $pdo;
    private string $storageRoot;

    public function __construct(?PDO $pdo = null, ?string $storageRoot = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $configuredRoot = $storageRoot;
        if ($configuredRoot === null) {
            $environmentRoot = getenv('DOCUMENT_STORAGE_PATH');
            $configuredRoot = is_string($environmentRoot) && trim($environmentRoot) !== ''
                ? trim($environmentRoot)
                : null;
        }
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
            ? realpath((string)$_SERVER['DOCUMENT_ROOT'])
            : false;
        $defaultBase = $documentRoot !== false
            ? dirname($documentRoot)
            : dirname(__DIR__, 2);
        $this->storageRoot = $configuredRoot
            ? rtrim($configuredRoot, '\\/')
            : $defaultBase . DIRECTORY_SEPARATOR . 'chronodesk_private' . DIRECTORY_SEPARATOR . 'documents';
    }

    public function list(array $filters, array $actor): array {
        $sql = 'SELECT id, title, category, description, original_name, extension,
                       detected_mime, size_bytes, sha256, uploaded_by, uploaded_at,
                       status, visibility, updated_by, updated_at
                FROM portal_document_files
                WHERE status <> "deleted"';
        $params = [];

        $search = $this->text($filters['search'] ?? '', 100, true);
        if ($search !== '') {
            $sql .= ' AND (
                title LIKE :search_title
                OR original_name LIKE :search_original_name
                OR uploaded_by LIKE :search_uploaded_by
            )';
            $searchValue = '%' . $search . '%';
            $params[':search_title'] = $searchValue;
            $params[':search_original_name'] = $searchValue;
            $params[':search_uploaded_by'] = $searchValue;
        }
        $category = $this->text($filters['category'] ?? '', 60, true);
        if ($category !== '') {
            $sql .= ' AND category = :category';
            $params[':category'] = $category;
        }
        $extension = strtolower($this->text($filters['extension'] ?? '', 10, true));
        if ($extension !== '') {
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException('Tipo de documento invalido.');
            }
            $sql .= ' AND extension = :extension';
            $params[':extension'] = $extension;
        }
        $uploadedBy = $this->text($filters['uploaded_by'] ?? '', 100, true);
        if ($uploadedBy !== '') {
            $sql .= ' AND uploaded_by = :uploaded_by';
            $params[':uploaded_by'] = $uploadedBy;
        }
        $date = $this->text($filters['date'] ?? '', 10, true);
        if ($date !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException('Data invalida.');
            }
            $sql .= ' AND DATE(uploaded_at) = :uploaded_date';
            $params[':uploaded_date'] = $date;
        }
        if (($actor['role'] ?? '') === 'tecnico' || ($actor['role'] ?? '') === 'somente_leitura') {
            $sql .= ' AND visibility = "internal"';
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY uploaded_at DESC, id DESC LIMIT 300');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function upload(array $file, array $metadata, array $actor): array {
        $this->assertCanManage($actor);
        $this->validateUploadArray($file);

        $originalName = $this->safeOriginalName((string)$file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Extensao de arquivo nao permitida.');
        }
        $this->rejectDangerousName($originalName);

        $size = (int)$file['size'];
        if ($size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new LengthException('O arquivo excede o limite de 10 MB ou esta vazio.');
        }
        $tmpPath = (string)$file['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            throw new InvalidArgumentException('Origem de upload invalida.');
        }

        $detectedMime = $this->detectMime($tmpPath);
        if (!in_array($detectedMime, self::MIME_TYPES[$extension], true)) {
            throw new InvalidArgumentException('O conteudo do arquivo nao corresponde a extensao informada.');
        }
        $this->validateContent($tmpPath, $extension);

        $title = $this->requiredText($metadata['title'] ?? pathinfo($originalName, PATHINFO_FILENAME), 180, 'Titulo');
        $category = $this->requiredText($metadata['category'] ?? null, 60, 'Categoria');
        $description = $this->text($metadata['description'] ?? '', 2000, true);
        $visibilityValue = $metadata['visibility'] ?? 'internal';
        if (!is_string($visibilityValue)) {
            throw new InvalidArgumentException('Visibilidade invalida.');
        }
        $visibility = $visibilityValue;
        if (!in_array($visibility, self::ALLOWED_VISIBILITY, true)) {
            throw new InvalidArgumentException('Visibilidade invalida.');
        }

        $randomName = bin2hex(random_bytes(32)) . '.' . $extension;
        $shard = substr($randomName, 0, 2);
        $targetDirectory = $this->storageRoot . DIRECTORY_SEPARATOR . $shard;
        $this->ensureStorageDirectory($targetDirectory);
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $randomName;
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new RuntimeException('Nao foi possivel armazenar o documento.');
        }
        @chmod($targetPath, 0600);

        $sha256 = hash_file('sha256', $targetPath);
        if ($sha256 === false) {
            @unlink($targetPath);
            throw new RuntimeException('Nao foi possivel calcular a integridade do documento.');
        }
        $storageKey = $shard . '/' . $randomName;

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_document_files
                    (title, category, description, original_name, stored_name, storage_key,
                     extension, detected_mime, size_bytes, sha256, uploaded_by,
                     status, visibility, updated_by)
                 VALUES
                    (:title, :category, :description, :original_name, :stored_name, :storage_key,
                     :extension, :detected_mime, :size_bytes, :sha256, :uploaded_by,
                     "active", :visibility, :updated_by)'
            );
            $stmt->execute([
                ':title' => $title,
                ':category' => $category,
                ':description' => $description !== '' ? $description : null,
                ':original_name' => $originalName,
                ':stored_name' => $randomName,
                ':storage_key' => $storageKey,
                ':extension' => $extension,
                ':detected_mime' => $detectedMime,
                ':size_bytes' => $size,
                ':sha256' => $sha256,
                ':uploaded_by' => $actor['username'],
                ':visibility' => $visibility,
                ':updated_by' => $actor['username'],
            ]);
        } catch (Throwable $error) {
            @unlink($targetPath);
            throw $error;
        }

        return [
            'id' => (int)$this->pdo->lastInsertId(),
            'title' => $title,
            'original_name' => $originalName,
            'extension' => $extension,
            'detected_mime' => $detectedMime,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'status' => 'active',
            'visibility' => $visibility,
        ];
    }

    public function download(int $id, array $actor): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, original_name, storage_key, extension, detected_mime,
                    size_bytes, sha256, uploaded_by, visibility
             FROM portal_document_files
             WHERE id = :id AND status = "active"'
        );
        $stmt->execute([':id' => $id]);
        $document = $stmt->fetch();
        if (!$document) {
            throw new DomainException('Documento nao encontrado.');
        }
        if (
            $document['visibility'] === 'management'
            && !in_array($actor['role'] ?? '', ['admin', 'gestor'], true)
        ) {
            throw new DomainException('Documento nao disponivel para este perfil.');
        }

        $path = $this->resolveStoragePath((string)$document['storage_key']);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Arquivo de documento indisponivel.');
        }
        if ((int)filesize($path) !== (int)$document['size_bytes']) {
            throw new RuntimeException('Falha na verificacao de integridade do documento.');
        }
        $actualHash = hash_file('sha256', $path);
        if (!is_string($actualHash) || !hash_equals((string)$document['sha256'], $actualHash)) {
            throw new RuntimeException('Falha na verificacao de integridade do documento.');
        }
        return $document + ['path' => $path];
    }

    public function delete(int $id, array $actor): void {
        $this->assertCanManage($actor);
        $stmt = $this->pdo->prepare(
            'UPDATE portal_document_files
             SET status = "deleted", updated_by = :updated_by
             WHERE id = :id AND status <> "deleted"'
        );
        $stmt->execute([':updated_by' => $actor['username'], ':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Documento nao encontrado.');
        }
    }

    public function storageRoot(): string {
        return $this->storageRoot;
    }

    private function assertCanManage(array $actor): void {
        if (!in_array($actor['role'] ?? '', ['admin', 'gestor'], true)) {
            throw new DomainException('Seu perfil nao possui permissao para gerenciar documentos.');
        }
    }

    private function validateUploadArray(array $file): void {
        foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
            if (!array_key_exists($key, $file) || is_array($file[$key])) {
                throw new InvalidArgumentException('Estrutura de upload invalida.');
            }
        }
        $error = (int)$file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('O upload nao foi recebido corretamente.');
        }
    }

    private function safeOriginalName(string $name): string {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidArgumentException('Nome de arquivo invalido.');
        }
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim((string)$name, " .");
        if ($name === '' || strlen($name) > 180) {
            throw new InvalidArgumentException('Nome de arquivo invalido.');
        }
        return $name;
    }

    private function rejectDangerousName(string $name): void {
        $blocked = implode('|', array_map('preg_quote', self::BLOCKED_EXTENSIONS));
        if (preg_match('/\.(' . $blocked . ')(?:\.|$)/i', $name)) {
            throw new InvalidArgumentException('Nome de arquivo contem extensao proibida.');
        }
    }

    private function detectMime(string $path): string {
        if (!function_exists('finfo_open')) {
            throw new RuntimeException('Extensao fileinfo indisponivel no servidor.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Nao foi possivel iniciar a validacao MIME.');
        }
        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }
        if (!is_string($mime) || $mime === '') {
            throw new InvalidArgumentException('Tipo MIME nao identificado.');
        }
        return strtolower($mime);
    }

    private function validateContent(string $path, string $extension): void {
        if (in_array($extension, ['csv', 'txt', 'md'], true)) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Nao foi possivel validar o arquivo textual.');
            }
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Falha ao validar o arquivo textual.');
                    }
                    if (str_contains($chunk, "\0")) {
                        throw new InvalidArgumentException('Arquivo textual contem dados binarios.');
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $this->validateOfficePackage($path, $extension);
        }

        if ($extension === 'doc') {
            $content = file_get_contents($path);
            $wordStream = "W\0o\0r\0d\0D\0o\0c\0u\0m\0e\0n\0t";
            $macroMarkers = [
                "V\0B\0A",
                "_\0V\0B\0A\0_\0P\0R\0O\0J\0E\0C\0T",
                "M\0a\0c\0r\0o\0s",
            ];
            if (
                $content === false
                || !str_starts_with($content, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
                || (!str_contains($content, 'WordDocument') && !str_contains($content, $wordStream))
            ) {
                throw new InvalidArgumentException('Documento Word legado invalido.');
            }
            foreach ($macroMarkers as $marker) {
                if (str_contains($content, $marker)) {
                    throw new InvalidArgumentException('Documentos Word com macros nao sao permitidos.');
                }
            }
        }

        if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
            $image = @getimagesize($path);
            if ($image === false) {
                throw new InvalidArgumentException('Imagem invalida.');
            }
        }
    }

    private function validateOfficePackage(string $path, string $extension): void {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Extensao ZipArchive indisponivel no servidor.');
        }

        $archive = new ZipArchive();
        $opened = $archive->open($path, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new InvalidArgumentException('Pacote Office invalido.');
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > self::MAX_OFFICE_ARCHIVE_ENTRIES) {
                throw new InvalidArgumentException('Pacote Office excede a complexidade permitida.');
            }

            $requiredEntry = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $requiredFound = false;
            $totalUncompressed = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->getNameIndex($index);
                if (!is_string($entry) || $entry === '') {
                    throw new InvalidArgumentException('Pacote Office invalido.');
                }
                $normalizedEntry = str_replace('\\', '/', $entry);
                $lowerEntry = strtolower($normalizedEntry);
                if (
                    str_contains($normalizedEntry, "\0")
                    || str_starts_with($normalizedEntry, '/')
                    || preg_match('#(^|/)\.\.(?:/|$)#', $normalizedEntry)
                ) {
                    throw new InvalidArgumentException('Pacote Office contem caminho inseguro.');
                }
                if ($normalizedEntry === $requiredEntry) {
                    $requiredFound = true;
                }
                if (
                    str_ends_with($lowerEntry, '/vbaproject.bin')
                    || str_ends_with($lowerEntry, '/vbadata.xml')
                    || str_contains($lowerEntry, '/macros/')
                    || str_contains($lowerEntry, '/externallinks/')
                    || str_contains($lowerEntry, '/embeddings/')
                    || str_contains($lowerEntry, '/activex/')
                ) {
                    throw new InvalidArgumentException('Documentos Office com macros ou conteudo ativo nao sao permitidos.');
                }
                $stat = $archive->statIndex($index);
                if (!is_array($stat)) {
                    throw new InvalidArgumentException('Pacote Office invalido.');
                }
                $size = (int)($stat['size'] ?? 0);
                $compressedSize = max(1, (int)($stat['comp_size'] ?? 0));
                $totalUncompressed += $size;
                if (
                    $size > self::MAX_OFFICE_ENTRY_BYTES
                    || $size / $compressedSize > self::MAX_OFFICE_COMPRESSION_RATIO
                    || $totalUncompressed > self::MAX_OFFICE_UNCOMPRESSED_BYTES
                ) {
                    throw new InvalidArgumentException('Pacote Office possui compressao suspeita ou tamanho excessivo.');
                }
            }

            $contentTypesStat = $archive->statName('[Content_Types].xml');
            if (
                !$requiredFound
                || !is_array($contentTypesStat)
                || (int)($contentTypesStat['size'] ?? 0) < 1
                || (int)$contentTypesStat['size'] > self::MAX_OFFICE_METADATA_BYTES
            ) {
                throw new InvalidArgumentException('Pacote Office invalido.');
            }
            $contentTypes = $archive->getFromName(
                '[Content_Types].xml',
                self::MAX_OFFICE_METADATA_BYTES
            );
            $expectedType = $extension === 'docx'
                ? 'wordprocessingml.document.main+xml'
                : 'spreadsheetml.sheet.main+xml';
            if (
                !is_string($contentTypes)
                || stripos($contentTypes, $expectedType) === false
                || stripos($contentTypes, 'macroEnabled') !== false
                || stripos($contentTypes, 'vbaProject') !== false
            ) {
                throw new InvalidArgumentException('Pacote Office invalido ou habilitado para macros.');
            }
        } finally {
            $archive->close();
        }
    }

    private function ensureStorageDirectory(string $directory): void {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Diretorio privado de documentos indisponivel.');
        }
        $this->assertPrivateStorageRoot();
        if (!is_writable($directory)) {
            throw new RuntimeException('Diretorio privado de documentos sem permissao de escrita.');
        }
    }

    private function resolveStoragePath(string $storageKey): string {
        if (!preg_match('/^[a-f0-9]{2}\/[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $storageKey)) {
            throw new RuntimeException('Chave interna de documento invalida.');
        }
        $root = realpath($this->storageRoot);
        if ($root === false) {
            throw new RuntimeException('Diretorio privado de documentos indisponivel.');
        }
        $this->assertPrivateStorageRoot();
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey));
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Arquivo fora do armazenamento permitido.');
        }
        return $path;
    }

    private function assertPrivateStorageRoot(): void {
        if (!$this->isAbsolutePath($this->storageRoot)) {
            throw new RuntimeException('O armazenamento de documentos exige caminho absoluto.');
        }
        $storageRoot = realpath($this->storageRoot);
        if ($storageRoot === false) {
            throw new RuntimeException('Diretorio privado de documentos indisponivel.');
        }

        $protectedRoots = [realpath(dirname(__DIR__))];
        if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
            $protectedRoots[] = realpath((string)$_SERVER['DOCUMENT_ROOT']);
        }

        foreach (array_filter($protectedRoots, 'is_string') as $protectedRoot) {
            if (
                $storageRoot === $protectedRoot
                || str_starts_with($storageRoot, $protectedRoot . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('O armazenamento de documentos deve ficar fora do webroot.');
            }
        }
    }

    private function isAbsolutePath(string $path): bool {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private function requiredText($value, int $max, string $label): string {
        $text = $this->text($value, $max, true);
        if ($text === '') {
            throw new InvalidArgumentException($label . ' e obrigatorio.');
        }
        return $text;
    }

    private function text($value, int $max, bool $allowEmpty = false): string {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Texto invalido.');
        }
        $text = trim((string)$value);
        if (!$allowEmpty && $text === '') {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > $max) {
            throw new InvalidArgumentException('Texto excede o limite permitido.');
        }
        return $text;
    }
}
