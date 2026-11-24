<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxWriter.php';

/**
 * [Lote NC revisto, D-NC-14 a D-NC-18] Planilha de horas extras no modelo do
 * RH ("Total H-E Suporte Tecnico BPM MM-AAAA.xlsx"): uma aba "Plan1", colunas
 * A a F (centro de custo, NC, nome, data, total de horas e descricao), uma
 * linha por lancamento aprovado, o total geral em H1 e I1, o estilo do modelo
 * (Aptos Narrow 11, cabecalho em negrito no fundo 00B0F0, centralizado, borda
 * fina; autofiltro; larguras). Funcao pura: recebe as linhas ja filtradas.
 */
final class OvertimeRhSheet {
    public const SHEET = 'Plan1';
    /** Como no modelo do RH, com o espaco no fim de "Nome completo " (o modelo e a referencia). */
    public const HEADERS = ['CENTRO DE CUSTO', 'NC', 'Nome completo ', 'Data da realização', 'Total de Horas', 'Descrição'];
    public const DEFAULT_COST_CENTER = '1.123.01  Suporte Tecnico';
    public const TOTAL_LABEL = 'Total de Horas Geral:';
    public const TIME_FORMAT = '[h]:mm:ss';
    public const DATE_FORMAT = 'dd/mm/yyyy';
    public const WIDTHS = [0 => 22.4, 1 => 7, 2 => 45.1, 3 => 16.2, 4 => 13.1, 5 => 67.9, 7 => 18.7];
    private const FONT = ['name' => 'Aptos Narrow', 'size' => 11];
    private const UPPER = [
        'á' => 'Á', 'à' => 'À', 'â' => 'Â', 'ã' => 'Ã', 'ä' => 'Ä', 'é' => 'É', 'è' => 'È', 'ê' => 'Ê', 'ë' => 'Ë',
        'í' => 'Í', 'ì' => 'Ì', 'î' => 'Î', 'ï' => 'Ï', 'ó' => 'Ó', 'ò' => 'Ò', 'ô' => 'Ô', 'õ' => 'Õ', 'ö' => 'Ö',
        'ú' => 'Ú', 'ù' => 'Ù', 'û' => 'Û', 'ü' => 'Ü', 'ç' => 'Ç', 'ñ' => 'Ñ',
    ];

    /** [D-NC-18] Maiusculas com as letras acentuadas do portugues, sem o mbstring. */
    public static function upper(string $value): string {
        return strtoupper(strtr($value, self::UPPER));
    }

    /** Nome do arquivo pelo mes do fim do periodo (o fim da competencia). */
    public static function fileName(string $periodTo): string {
        return 'Total H-E Suporte Tecnico BPM ' . substr($periodTo, 5, 2) . '-' . substr($periodTo, 0, 4) . '.xlsx';
    }

    /** Data do Excel (dias desde 1899-12-30). */
    public static function excelDate(string $date): int {
        return (int)(new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))->diff(new DateTimeImmutable($date, new DateTimeZone('UTC')))->days;
    }

    /** Linhas na ordem da planilha: data e depois nome (e o ID, para desempate). */
    public static function ordered(array $rows): array {
        usort($rows, static fn (array $a, array $b): int => [$a['work_date'], self::upper((string)$a['employee_name']), (int)$a['id']] <=> [$b['work_date'], self::upper((string)$b['employee_name']), (int)$b['id']]);
        return $rows;
    }

    /**
     * Os bytes do .xlsx. $rows: lancamentos aprovados, cada um com id,
     * work_date, employee_name, nc (texto de digitos), total_minutes, reason e
     * justification. O NC de todos tem de estar presente (quem chama recusa antes).
     */
    public static function build(array $rows, string $costCenter): string {
        $data = ['font' => self::FONT, 'border' => 'black', 'center' => true];
        $header = ['font' => self::FONT, 'bold' => true, 'fill' => '00B0F0', 'border' => 'black', 'center' => true, 'vcenter' => true];
        $sheet = [];
        $first = [];
        foreach (self::HEADERS as $title) {
            $first[] = ['v' => $title, 's' => $header];
        }
        $rows = self::ordered($rows);
        $last = max(2, count($rows) + 1);
        $first[] = null;
        $first[] = ['v' => self::TOTAL_LABEL, 's' => ['font' => self::FONT]];
        $first[] = ['f' => 'SUM(E2:E' . $last . ')', 's' => ['font' => self::FONT, 'numFmt' => self::TIME_FORMAT]];
        $sheet[] = $first;
        foreach ($rows as $row) {
            $reason = trim((string)($row['reason'] ?? ''));
            $sheet[] = [
                ['v' => $costCenter, 's' => $data],
                ['v' => (int)$row['nc'], 's' => $data],
                ['v' => self::upper((string)$row['employee_name']), 's' => $data],
                ['v' => self::excelDate((string)$row['work_date']), 's' => $data + ['numFmt' => self::DATE_FORMAT]],
                ['v' => ((int)$row['total_minutes']) / 1440, 's' => $data + ['numFmt' => self::TIME_FORMAT, 'vcenter' => true]],
                ['v' => $reason !== '' ? $reason : (string)($row['justification'] ?? ''), 's' => $data],
            ];
        }
        $writer = new XlsxWriter();
        $writer->addSheet(self::SHEET, $sheet, [
            'cols' => self::WIDTHS,
            'autofilter' => 'A1:F' . max(1, count($rows) + 1),
        ]);
        return $writer->build();
    }
}
