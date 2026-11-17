<?php
declare(strict_types=1);

/**
 * [9.17, D-CHG-25] Escritor de .xlsx minimo, sem dependencia e sem o
 * ZipArchive: o ZIP e montado aqui com entradas sem compressao (metodo 0) e o
 * crc32() nativo do PHP. Celulas de texto sempre como inlineStr (nunca
 * formula); texto que comeca com =, +, -, @, tab ou CR ganha o quotePrefix
 * (Excel trata como texto, sem mostrar o apostrofo).
 *
 * Celula: string, int, float, null ou ['v' => valor, 's' => estilo]. Estilo:
 * bold, color (RRGGBB), fill (RRGGBB), border (bool), wrap, center, underline.
 * Opcoes da aba: cols (larguras), merges (['A1:B1']), autofilter ('A1:D9') e
 * links ([['ref' => 'B5', 'url' => 'https://...']], so http(s)).
 */
final class XlsxWriter {
    private array $sheets = [];
    private array $fonts = [];
    private array $fills = [];
    private array $xfs = [];

    public function __construct() {
        $this->fonts = ['' => 0];
        $this->fills = ['none' => 0, 'gray125' => 1];
        $this->xfs = ['default' => 0];
    }

    public function addSheet(string $name, array $rows, array $options = []): void {
        if (preg_match('/^[^\[\]:*?\/\\\\]{1,31}$/u', $name) !== 1) {
            throw new InvalidArgumentException('Nome de aba inválido.');
        }
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'options' => $options];
    }

    public static function column(int $index): string {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    }

    public static function needsQuotePrefix(string $value): bool {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }

    private static function xml(string $value): string {
        $value = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function fontId(array $style): int {
        $key = (empty($style['bold']) ? '' : 'b') . '|' . ($style['color'] ?? '') . '|' . (empty($style['underline']) ? '' : 'u');
        if ($key === '||') {
            return 0;
        }
        return $this->fonts[$key] ??= count($this->fonts);
    }

    private function fillId(array $style): int {
        $fill = $style['fill'] ?? null;
        return $fill === null ? 0 : ($this->fills[$fill] ??= count($this->fills));
    }

    private function styleId(array $style, bool $quote): int {
        $font = $this->fontId($style);
        $fill = $this->fillId($style);
        $key = implode('|', [$font, $fill, empty($style['border']) ? 0 : 1, empty($style['wrap']) ? 0 : 1, empty($style['center']) ? 0 : 1, $quote ? 1 : 0]);
        return $this->xfs[$key] ??= count($this->xfs);
    }

    private function cellXml(string $ref, mixed $cell): string {
        $style = [];
        if (is_array($cell)) {
            $style = $cell['s'] ?? [];
            $cell = $cell['v'] ?? null;
        }
        if ($cell === null || $cell === '') {
            $id = $style === [] ? 0 : $this->styleId($style, false);
            return $id === 0 ? '' : "<c r=\"{$ref}\" s=\"{$id}\"/>";
        }
        if (is_int($cell) || is_float($cell)) {
            $id = $style === [] ? 0 : $this->styleId($style, false);
            return "<c r=\"{$ref}\"" . ($id > 0 ? " s=\"{$id}\"" : '') . '><v>' . $cell . '</v></c>';
        }
        $text = (string)$cell;
        $id = $this->styleId($style, self::needsQuotePrefix($text));
        return "<c r=\"{$ref}\"" . ($id > 0 ? " s=\"{$id}\"" : '') . ' t="inlineStr"><is><t xml:space="preserve">' . self::xml($text) . '</t></is></c>';
    }

    private function sheetXml(array $sheet, array &$rels): string {
        $rowsXml = '';
        foreach (array_values($sheet['rows']) as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $cell) {
                $cells .= $this->cellXml(self::column($c) . ($r + 1), $cell);
            }
            $rowsXml .= '<row r="' . ($r + 1) . '">' . $cells . '</row>';
        }
        $opts = $sheet['options'];
        $cols = '';
        foreach ($opts['cols'] ?? [] as $i => $width) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$width . '" customWidth="1"/>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $rowsXml . '</sheetData>';
        if (!empty($opts['autofilter'])) {
            $xml .= '<autoFilter ref="' . self::xml($opts['autofilter']) . '"/>';
        }
        if (!empty($opts['merges'])) {
            $xml .= '<mergeCells count="' . count($opts['merges']) . '">'
                . implode('', array_map(static fn (string $m): string => '<mergeCell ref="' . self::xml($m) . '"/>', $opts['merges'])) . '</mergeCells>';
        }
        $links = '';
        foreach ($opts['links'] ?? [] as $link) {
            if (preg_match('~^https?://~i', (string)$link['url']) !== 1) {
                continue;
            }
            $rid = 'rId' . (count($rels) + 1);
            $rels[] = '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . self::xml($link['url']) . '" TargetMode="External"/>';
            $links .= '<hyperlink ref="' . self::xml($link['ref']) . '" r:id="' . $rid . '"/>';
        }
        if ($links !== '') {
            $xml .= '<hyperlinks>' . $links . '</hyperlinks>';
        }
        return $xml . '</worksheet>';
    }

    private function stylesXml(): string {
        $fonts = '';
        foreach (array_keys($this->fonts) as $key) {
            [$bold, $color, $under] = array_pad(explode('|', (string)$key), 3, '');
            $fonts .= '<font>' . ($bold === 'b' ? '<b/>' : '') . ($under === 'u' ? '<u/>' : '') . '<sz val="10"/>'
                . '<color rgb="FF' . ($color !== '' ? $color : '000000') . '"/><name val="Verdana"/><family val="2"/></font>';
        }
        $fills = '';
        foreach (array_keys($this->fills) as $key) {
            $fills .= in_array($key, ['none', 'gray125'], true)
                ? '<fill><patternFill patternType="' . $key . '"/></fill>'
                : '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $key . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $xfs = '';
        foreach (array_keys($this->xfs) as $key) {
            if ($key === 'default') {
                $xfs .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
                continue;
            }
            [$font, $fill, $border, $wrap, $center, $quote] = array_map('intval', explode('|', (string)$key));
            $xfs .= '<xf numFmtId="0" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
                . ($font > 0 ? ' applyFont="1"' : '') . ($fill > 0 ? ' applyFill="1"' : '') . ($border > 0 ? ' applyBorder="1"' : '')
                . ($quote ? ' quotePrefix="1"' : '') . ' applyAlignment="1"><alignment vertical="top"'
                . ($wrap ? ' wrapText="1"' : '') . ($center ? ' horizontal="center"' : '') . '/></xf>';
        }
        $side = '<color rgb="FF9BB7C4"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($this->fonts) . '">' . $fonts . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . $fills . '</fills>'
            . '<borders count="2"><border/><border><left style="thin">' . $side . '</left><right style="thin">' . $side . '</right><top style="thin">' . $side . '</top><bottom style="thin">' . $side . '</bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . $xfs . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** Os bytes do .xlsx. */
    public function build(): string {
        if ($this->sheets === []) {
            throw new LogicException('Planilha sem abas.');
        }
        $files = [];
        $sheetEntries = '';
        $workbookRels = '';
        $types = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $rels = [];
            $files["xl/worksheets/sheet{$n}.xml"] = $this->sheetXml($sheet, $rels);
            if ($rels !== []) {
                $files["xl/worksheets/_rels/sheet{$n}.xml.rels"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $rels) . '</Relationships>';
            }
            $sheetEntries .= '<sheet name="' . self::xml($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $workbookRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $count = count($this->sheets);
        $files['xl/styles.xml'] = $this->stylesXml();
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetEntries . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $workbookRels
            . '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files = ['[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $types . '</Types>'] + $files;
        return self::zip($files);
    }

    /** ZIP com entradas sem compressao (metodo 0), nomes em UTF-8. */
    public static function zip(array $files): string {
        $data = '';
        $central = '';
        $time = 0;
        $date = (1 << 5) | 1; // 1980-01-01: o arquivo sai igual para o mesmo conteudo
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0) . $name . $content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }
}
