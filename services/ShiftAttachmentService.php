<?php
require_once __DIR__ . '/../db.php';

final class ShiftAttachmentService {
    public const MAX_FILE_BYTES = 10485760;
    public const MAX_REQUEST_BYTES = 12582912;
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'pdf', 'csv', 'xls', 'xlsx'];
    private const MIME_TYPES = [
        'png' => ['image/png', 'image/x-png'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'pdf' => ['application/pdf'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'xls' => [
            'application/vnd.ms-excel',
            'application/vnd.ms-office',
            'application/x-ole-storage',
            'application/octet-stream',
        ],
        'xlsx' => [
            'application/zip',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ];

    private PDO $pdo;
    private string $storageRoot;

    public function __construct(?PDO $pdo = null, ?string $storageRoot = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $configured = $storageRoot ?: trim((string)(getenv('SHIFT_STORAGE_PATH') ?: ''));
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $base = $documentRoot !== false ? dirname($documentRoot) : dirname(__DIR__, 2);
        $this->storageRoot = $configured !== ''
            ? rtrim($configured, '\\/')
            : $base . DIRECTORY_SEPARATOR . 'chronodesk_private' . DIRECTORY_SEPARATOR . 'shift_schedules';
    }

    public function list(array $filters): array {
        $sql = 'SELECT id, title, reference_month, notes, original_name, extension,
                       detected_mime, size_bytes, uploaded_by, uploaded_at
                FROM portal_shift_attachments WHERE status = "active"';
        $params = [];
        $month = trim((string)($filters['month'] ?? ''));
        if ($month !== '') {
            $this->month($month);
            $sql .= ' AND reference_month = :reference_month';
            $params[':reference_month'] = $month;
        }
        $extension = strtolower(trim((string)($filters['extension'] ?? '')));
        if ($extension !== '') {
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException('Tipo de escala invalido.');
            }
            $sql .= ' AND extension = :extension';
            $params[':extension'] = $extension;
        }
        $search = $this->text($filters['search'] ?? '', 100, true);
        if ($search !== '') {
            $sql .= ' AND (title LIKE :search_title OR notes LIKE :search_notes OR original_name LIKE :search_file)';
            $value = '%' . $search . '%';
            $params[':search_title'] = $value;
            $params[':search_notes'] = $value;
            $params[':search_file'] = $value;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY uploaded_at DESC, id DESC LIMIT 200');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function upload(array $file, array $metadata, array $actor): array {
        $this->assertManager($actor);
        foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
            if (!array_key_exists($key, $file) || is_array($file[$key])) {
                throw new InvalidArgumentException('Estrutura de upload invalida.');
            }
        }
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            if (in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new LengthException('Arquivo acima do limite de 10 MB.');
            }
            throw new InvalidArgumentException('Selecione um arquivo para publicar.');
        }
        if (!is_uploaded_file((string)$file['tmp_name'])) {
            throw new InvalidArgumentException('O upload nao foi recebido corretamente.');
        }
        $originalName = $this->safeName((string)$file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Formato nao permitido. Envie PNG, JPG, JPEG, PDF, CSV, XLS ou XLSX.');
        }
        $size = (int)$file['size'];
        if ($size < 1) {
            throw new InvalidArgumentException('Selecione um arquivo para publicar.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new LengthException('Arquivo acima do limite de 10 MB.');
        }
        $path = (string)$file['tmp_name'];
        $mime = $this->mime($path);
        if (!in_array($mime, self::MIME_TYPES[$extension], true)) {
            throw new InvalidArgumentException('Formato nao permitido. Envie PNG, JPG, JPEG, PDF, CSV, XLS ou XLSX.');
        }
        $this->validateContent($path, $extension);

        $title = $this->text($metadata['title'] ?? null, 180, false, 'Titulo e obrigatorio.');
        $month = $this->month((string)($metadata['reference_month'] ?? ''));
        $notes = $this->text($metadata['notes'] ?? '', 2000, true);
        $storedName = bin2hex(random_bytes(32)) . '.' . $extension;
        $shard = substr($storedName, 0, 2);
        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . $shard;
        $this->ensurePrivateDirectory($directory);
        $target = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($path, $target)) {
            throw new RuntimeException('Nao foi possivel armazenar a escala.');
        }
        @chmod($target, 0600);
        $sha = hash_file('sha256', $target);
        if (!is_string($sha)) {
            @unlink($target);
            throw new RuntimeException('Nao foi possivel validar a integridade da escala.');
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_shift_attachments
                    (title, reference_month, notes, original_name, stored_name, storage_key,
                     extension, detected_mime, size_bytes, sha256, uploaded_by, updated_by)
                 VALUES
                    (:title, :reference_month, :notes, :original_name, :stored_name, :storage_key,
                     :extension, :detected_mime, :size_bytes, :sha256, :uploaded_by, :updated_by)'
            );
            $stmt->execute([
                ':title' => $title,
                ':reference_month' => $month,
                ':notes' => $notes !== '' ? $notes : null,
                ':original_name' => $originalName,
                ':stored_name' => $storedName,
                ':storage_key' => $shard . '/' . $storedName,
                ':extension' => $extension,
                ':detected_mime' => $mime,
                ':size_bytes' => $size,
                ':sha256' => $sha,
                ':uploaded_by' => $actor['username'],
                ':updated_by' => $actor['username'],
            ]);
        } catch (Throwable $error) {
            @unlink($target);
            throw $error;
        }
        return ['id' => (int)$this->pdo->lastInsertId(), 'title' => $title];
    }

    public function download(int $id): array {
        $stmt = $this->pdo->prepare(
            'SELECT original_name, storage_key, extension, detected_mime, size_bytes, sha256
             FROM portal_shift_attachments WHERE id = :id AND status = "active" LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();
        if (!$item) {
            throw new DomainException('Escala nao encontrada.');
        }
        $path = $this->resolvePath((string)$item['storage_key']);
        if (
            !is_file($path)
            || !is_readable($path)
            || (int)filesize($path) !== (int)$item['size_bytes']
        ) {
            throw new RuntimeException('Arquivo da escala indisponivel.');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals((string)$item['sha256'], $actual)) {
            throw new RuntimeException('Falha na integridade da escala.');
        }
        return $item + ['path' => $path];
    }

    private function validateContent(string $path, string $extension): void {
        if (in_array($extension, ['png', 'jpg', 'jpeg'], true) && @getimagesize($path) === false) {
            throw new InvalidArgumentException('Imagem invalida.');
        }
        if ($extension === 'pdf') {
            $handle = fopen($path, 'rb');
            $signature = $handle ? fread($handle, 5) : false;
            if ($handle) fclose($handle);
            if ($signature !== '%PDF-') {
                throw new InvalidArgumentException('PDF invalido.');
            }
        }
        if ($extension === 'csv') {
            $content = file_get_contents($path, false, null, 0, min((int)filesize($path), 1048576));
            if (!is_string($content) || strpos($content, "\0") !== false) {
                throw new InvalidArgumentException('CSV invalido ou binario.');
            }
        }
        if ($extension === 'xls') {
            $handle = fopen($path, 'rb');
            $signature = $handle ? fread($handle, 8) : false;
            if ($handle) fclose($handle);
            if ($signature !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
                throw new InvalidArgumentException('Planilha XLS invalida.');
            }
        }
        if ($extension === 'xlsx') {
            require_once __DIR__ . '/SpreadsheetImportService.php';
            (new SpreadsheetImportService())->validateXlsxFile($path);
        }
    }

    private function ensurePrivateDirectory(string $directory): void {
        if (!$this->isAbsolutePath($this->storageRoot)) {
            throw new RuntimeException('Storage de escalas exige caminho absoluto.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Storage privado indisponivel.');
        }
        $root = realpath($this->storageRoot);
        if ($root === false) {
            throw new RuntimeException('Storage privado indisponivel.');
        }
        $protectedRoots = [realpath(dirname(__DIR__))];
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== '') {
            $protectedRoots[] = realpath($documentRoot);
        }
        foreach (array_filter($protectedRoots, 'is_string') as $protectedRoot) {
            if ($root === $protectedRoot || strpos($root, $protectedRoot . DIRECTORY_SEPARATOR) === 0) {
                throw new RuntimeException('Storage de escalas deve ficar fora do projeto e do webroot.');
            }
        }
    }

    private function resolvePath(string $key): string {
        if (!preg_match('#^[a-f0-9]{2}/[a-f0-9]{64}\.(png|jpe?g|pdf|csv|xls|xlsx)$#', $key)) {
            throw new RuntimeException('Chave de storage invalida.');
        }
        $root = realpath($this->storageRoot);
        $path = $root !== false ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key)) : false;
        if ($root === false || $path === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Caminho de storage invalido.');
        }
        return $path;
    }

    private function safeName(string $name): string {
        if (
            $name === ''
            || strlen($name) > 180
            || strpos($name, '/') !== false
            || strpos($name, '\\') !== false
            || preg_match('/\.(php|phtml|phar|js|html?|svg|exe|bat|cmd|ps1|sh|jar|msi|dll|zip|rar|7z)(?:\.|$)/i', $name)
        ) {
            throw new InvalidArgumentException('Nome de arquivo invalido.');
        }
        return trim($name, " .");
    }

    private function isAbsolutePath(string $path): bool {
        return strpos($path, DIRECTORY_SEPARATOR) === 0
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || strpos($path, '\\\\') === 0;
    }

    private function mime(string $path): string {
        if (!function_exists('finfo_open') || !function_exists('finfo_file')) {
            throw new RuntimeException('Extensao fileinfo indisponivel no servidor.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) throw new RuntimeException('Fileinfo indisponivel.');
        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }
        if (!is_string($mime) || $mime === '') throw new InvalidArgumentException('MIME invalido.');
        return strtolower($mime);
    }

    private function month(string $value): string {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException('Mes de referencia invalido.');
        }
        return $value;
    }

    private function text($value, int $max, bool $empty = false, string $requiredMessage = 'Campo obrigatorio nao informado.'): string {
        if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Texto invalido.');
        $text = trim((string)$value);
        if (!$empty && $text === '') throw new InvalidArgumentException($requiredMessage);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > $max) throw new InvalidArgumentException('Texto excede o limite.');
        return $text;
    }

    private function assertManager(array $actor): void {
        if (($actor['role'] ?? '') !== 'admin') {
            throw new DomainException('Seu perfil nao pode publicar escalas.');
        }
    }
}
