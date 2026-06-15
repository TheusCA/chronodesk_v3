<?php

final class SpreadsheetImportService {
    public const MAX_FILE_BYTES = 2097152;
    public const MAX_REQUEST_BYTES = 3145728;
    private const MAX_ARCHIVE_ENTRIES = 1000;
    private const MAX_UNCOMPRESSED_BYTES = 16777216;

    public function parseUpload(array $file, int $maxRows, int $maxColumns, int $maxCellChars): array {
        $this->validateUploadArray($file);
        $name = $this->safeName((string)$file['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'xls') {
            throw new DomainException(
                'XLS legado nao e suportado com seguranca neste servidor. Converta para XLSX ou CSV.'
            );
        }
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('Formato permitido: CSV ou XLSX.');
        }
        if ((int)$file['size'] < 1 || (int)$file['size'] > self::MAX_FILE_BYTES) {
            throw new LengthException('A planilha esta vazia ou excede o limite de 2 MB.');
        }
        $path = (string)$file['tmp_name'];
        if (!is_uploaded_file($path) && PHP_SAPI !== 'cli') {
            throw new InvalidArgumentException('Origem do upload invalida.');
        }
        $mime = $this->mime($path);
        if ($extension === 'csv') {
            if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true)) {
                throw new InvalidArgumentException('O conteudo nao corresponde a um CSV.');
            }
            return $this->parseCsv($path, $maxRows, $maxColumns, $maxCellChars);
        }
        if (!in_array($mime, [
            'application/zip',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true)) {
            throw new InvalidArgumentException('O conteudo nao corresponde a um XLSX.');
        }
        return $this->parseXlsx($path, $maxRows, $maxColumns, $maxCellChars);
    }

    public function validateXlsxFile(
        string $path,
        int $maxRows = 5000,
        int $maxColumns = 100,
        int $maxCellChars = 4000
    ): void {
        $this->parseXlsx($path, $maxRows, $maxColumns, $maxCellChars);
    }

    private function parseCsv(string $path, int $maxRows, int $maxColumns, int $maxCellChars): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel ler o CSV.');
        }
        try {
            $sample = fread($handle, 4096);
            if ($sample === false || str_contains($sample, "\0")) {
                throw new InvalidArgumentException('CSV invalido ou binario.');
            }
            rewind($handle);
            $delimiter = substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
            $matrix = [];
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if (count($matrix) > $maxRows) {
                    throw new LengthException("A planilha excede {$maxRows} linhas.");
                }
                $matrix[] = $row;
            }
        } finally {
            fclose($handle);
        }
        return $this->matrixToRows($matrix, $maxRows, $maxColumns, $maxCellChars);
    }

    private function parseXlsx(string $path, int $maxRows, int $maxColumns, int $maxCellChars): array {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive e obrigatorio para importar XLSX.');
        }
        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('Pacote XLSX invalido.');
        }
        try {
            $this->validateArchive($archive);
            $sharedStrings = $this->sharedStrings($archive, $maxCellChars);
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            if (!is_string($sheet) || strlen($sheet) > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('A primeira planilha do XLSX e invalida.');
            }
            $xml = $this->xml($sheet);
            $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $matrix = [];
            foreach ($xml->xpath('//x:sheetData/x:row') ?: [] as $rowNode) {
                if (count($matrix) > $maxRows) {
                    throw new LengthException("A planilha excede {$maxRows} linhas.");
                }
                $row = [];
                foreach ($rowNode->xpath('./x:c') ?: [] as $cell) {
                    $reference = (string)$cell['r'];
                    if (!preg_match('/^([A-Z]+)\d+$/', $reference, $match)) {
                        throw new InvalidArgumentException('Referencia de celula XLSX invalida.');
                    }
                    $column = $this->columnIndex($match[1]);
                    if ($column >= $maxColumns) {
                        throw new LengthException("A planilha excede {$maxColumns} colunas.");
                    }
                    $type = (string)$cell['t'];
                    $valueNodes = $cell->xpath('./x:v');
                    $inlineNodes = $cell->xpath('./x:is/x:t');
                    $raw = $valueNodes ? (string)$valueNodes[0] : ($inlineNodes ? (string)$inlineNodes[0] : '');
                    $value = $type === 's' ? ($sharedStrings[(int)$raw] ?? '') : $raw;
                    if ($this->length($value) > $maxCellChars) {
                        throw new LengthException("Uma celula excede {$maxCellChars} caracteres.");
                    }
                    $row[$column] = $value;
                }
                if ($row !== []) {
                    $width = max(array_keys($row)) + 1;
                    $matrix[] = array_replace(array_fill(0, $width, ''), $row);
                }
            }
        } finally {
            $archive->close();
        }
        return $this->matrixToRows($matrix, $maxRows, $maxColumns, $maxCellChars);
    }

    private function validateArchive(ZipArchive $archive): void {
        if ($archive->numFiles < 3 || $archive->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new InvalidArgumentException('Pacote XLSX excede a complexidade permitida.');
        }
        $total = 0;
        $required = ['[Content_Types].xml' => false, 'xl/workbook.xml' => false, 'xl/worksheets/sheet1.xml' => false];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';
            $normalized = str_replace('\\', '/', $name);
            if (
                $normalized === ''
                || str_contains($normalized, "\0")
                || str_starts_with($normalized, '/')
                || preg_match('#(^|/)\.\.(?:/|$)#', $normalized)
            ) {
                throw new InvalidArgumentException('Pacote XLSX contem caminho inseguro.');
            }
            $lower = strtolower($normalized);
            if (
                str_contains($lower, 'vbaproject')
                || str_contains($lower, '/macros/')
                || str_contains($lower, '/externallinks/')
                || str_contains($lower, '/embeddings/')
                || str_contains($lower, '/activex/')
                || str_ends_with($lower, '.bin')
            ) {
                throw new InvalidArgumentException('Planilhas com macros ou conteudo externo nao sao permitidas.');
            }
            $size = (int)($stat['size'] ?? 0);
            $compressed = max(1, (int)($stat['comp_size'] ?? 0));
            $total += $size;
            if ($size > self::MAX_UNCOMPRESSED_BYTES || $size / $compressed > 100) {
                throw new InvalidArgumentException('Pacote XLSX possui compressao suspeita.');
            }
            if (array_key_exists($normalized, $required)) {
                $required[$normalized] = true;
            }
        }
        if ($total > self::MAX_UNCOMPRESSED_BYTES || in_array(false, $required, true)) {
            throw new InvalidArgumentException('Estrutura XLSX incompleta ou excessiva.');
        }
        $contentTypes = $archive->getFromName('[Content_Types].xml');
        if (
            !is_string($contentTypes)
            || stripos($contentTypes, 'spreadsheetml.sheet.main+xml') === false
            || stripos($contentTypes, 'macroEnabled') !== false
        ) {
            throw new InvalidArgumentException('Tipo de pacote XLSX invalido.');
        }
    }

    private function sharedStrings(ZipArchive $archive, int $maxCellChars): array {
        $content = $archive->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }
        if (!is_string($content) || strlen($content) > self::MAX_UNCOMPRESSED_BYTES) {
            throw new InvalidArgumentException('Tabela de textos XLSX invalida.');
        }
        $xml = $this->xml($content);
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach ($xml->xpath('//x:si') ?: [] as $item) {
            $parts = [];
            foreach ($item->xpath('.//x:t') ?: [] as $text) {
                $parts[] = (string)$text;
            }
            $value = implode('', $parts);
            if ($this->length($value) > $maxCellChars) {
                throw new LengthException("Uma celula excede {$maxCellChars} caracteres.");
            }
            $strings[] = $value;
        }
        return $strings;
    }

    private function matrixToRows(array $matrix, int $maxRows, int $maxColumns, int $maxCellChars): array {
        while ($matrix && !array_filter(end($matrix), static fn($value): bool => trim((string)$value) !== '')) {
            array_pop($matrix);
        }
        if (count($matrix) < 2) {
            throw new InvalidArgumentException('A planilha deve conter cabecalho e ao menos uma linha.');
        }
        $headers = array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            array_shift($matrix)
        );
        if (count($headers) > $maxColumns || in_array('', $headers, true)) {
            throw new InvalidArgumentException('Cabecalho vazio ou acima do limite de colunas.');
        }
        if (count(array_unique($headers)) !== count($headers)) {
            throw new InvalidArgumentException('A planilha possui cabecalhos duplicados.');
        }
        if (count($matrix) > $maxRows) {
            throw new LengthException("A planilha excede {$maxRows} linhas.");
        }
        $rows = [];
        foreach ($matrix as $line => $values) {
            if (count($values) > count($headers) || count($values) > $maxColumns) {
                throw new InvalidArgumentException('Linha ' . ($line + 2) . ' possui colunas excedentes.');
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $value = trim((string)($values[$index] ?? ''));
                if ($this->length($value) > $maxCellChars) {
                    throw new LengthException('Linha ' . ($line + 2) . ' possui celula acima do limite.');
                }
                $row[$header] = $value;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function validateUploadArray(array $file): void {
        foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
            if (!array_key_exists($key, $file) || is_array($file[$key])) {
                throw new InvalidArgumentException('Estrutura de upload invalida.');
            }
        }
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('O upload nao foi recebido corretamente.');
        }
    }

    private function safeName(string $name): string {
        if (
            $name === ''
            || strlen($name) > 180
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/\.(php|phtml|phar|js|html?|svg|exe|bat|cmd|ps1|sh|jar|msi|dll|zip|rar|7z)(?:\.|$)/i', $name)
        ) {
            throw new InvalidArgumentException('Nome ou extensao de arquivo invalida.');
        }
        return trim($name, " .");
    }

    private function mime(string $path): string {
        if (!function_exists('finfo_open') || !function_exists('finfo_file')) {
            throw new RuntimeException('Extensao fileinfo indisponivel no servidor.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Fileinfo indisponivel.');
        }
        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }
        if (!is_string($mime) || $mime === '') {
            throw new InvalidArgumentException('MIME do arquivo nao identificado.');
        }
        return strtolower($mime);
    }

    private function xml(string $content): SimpleXMLElement {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$xml) {
            throw new InvalidArgumentException('XML interno do XLSX invalido.');
        }
        return $xml;
    }

    private function columnIndex(string $letters): int {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }
        return $index - 1;
    }

    private function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
