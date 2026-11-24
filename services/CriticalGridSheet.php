<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxWriter.php';

/**
 * [D-ESC-19] O mes da Escala do Critico em .xlsx, no formato da planilha da
 * Lideranca: uma aba "<Mes> <Ano>", titulo, um bloco por semana ("Semana <ISO>
 * (Inicio: dd/mm/aaaa)"), o cabecalho "Seg (dd/mm)", uma linha por pessoa
 * (nome curto) com o rotulo do dia na cor do papel, o resumo do mes e a
 * legenda. Funcao pura: recebe a resposta do CriticalGridService::month (que
 * ja aplicou a privacidade da ausencia de quem exporta) e devolve os bytes.
 */
final class CriticalGridSheet {
    public const TITLE_FILL = '1F4E78';
    public const WEEK_FILL = '5B9BD5';
    public const DAY_FILL = 'D9EAF7';
    public const NAME_FILL = 'F2F6FC';
    public const OUTSIDE_FILL = 'F2F2F2';
    /** Os tons da tela nas cores da planilha (D-ESC-14). */
    public const FILLS = [
        'papel-azul' => 'C5D9F1', 'papel-verde' => 'EBF1DE', 'papel-pessego' => 'FCE4D6', 'papel-lilas' => 'CCC0DA',
        'papel-amarelo' => 'FFF2CC', 'papel-turquesa' => 'DAEEF3', 'papel-cinza' => 'E7E6E6', 'papel-areia' => 'EEE6D8',
        'banco' => 'FFFF00', 'ferias' => 'FFEB9C', 'feriado' => '92CDDC', 'ausente' => 'D9D9D9', 'sem' => 'FFFFFF',
    ];
    public const LEGEND = [
        ['banco', 'Banco de horas'], ['ferias', 'Férias'], ['feriado', 'Feriado'], ['ausente', 'Ausente'],
    ];

    /** Nome do arquivo: "Escala Critico <Mes> <Ano>.xlsx" (sem acento). */
    public static function fileName(string $monthLabel): string {
        return 'Escala Critico ' . strtr($monthLabel, ['ç' => 'c', 'Ç' => 'C']) . '.xlsx';
    }

    public static function build(array $grid): string {
        $border = ['border' => 'black'];
        $title = ['bold' => true, 'color' => 'FFFFFF', 'fill' => self::TITLE_FILL, 'center' => true, 'vcenter' => true];
        $week = ['bold' => true, 'color' => 'FFFFFF', 'fill' => self::WEEK_FILL, 'center' => true];
        $head = ['bold' => true, 'fill' => self::DAY_FILL, 'center' => true, 'wrap' => true] + $border;
        $rows = [[['v' => $grid['month']['label'], 's' => $title]]];
        $merges = ['A1:F1'];
        $rows[] = [];
        foreach ($grid['weeks'] as $block) {
            $rows[] = [['v' => 'Semana ' . $block['iso'] . '  (Início: ' . self::date($block['start']) . ')', 's' => $week]];
            $merges[] = 'A' . count($rows) . ':F' . count($rows);
            $line = [['v' => 'Técnico', 's' => $head]];
            foreach ($block['days'] as $index => $date) {
                $line[] = ['v' => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex'][$index] . "\n(" . substr($date, 8, 2) . '/' . substr($date, 5, 2) . ')', 's' => $head];
            }
            $rows[] = $line;
            foreach ($grid['people'] as $person) {
                $line = [['v' => $person['short_name'], 's' => ['bold' => true, 'fill' => self::NAME_FILL] + $border]];
                foreach ($block['days'] as $date) {
                    $cell = $grid['cells'][$person['id'] . ':' . $date] ?? null;
                    if ($cell === null || $cell['in_month'] !== true) {
                        $line[] = ['v' => '', 's' => ['fill' => self::OUTSIDE_FILL] + $border];
                        continue;
                    }
                    $line[] = ['v' => $cell['label'], 's' => ['fill' => self::FILLS[$cell['tone']] ?? 'FFFFFF', 'center' => true] + $border];
                }
                $rows[] = $line;
            }
            $rows[] = [];
        }
        // Resumo do mes (como a Capa): por pessoa, os dias de cada papel, home, presencial e o %.
        $roles = array_values(array_filter($grid['roles'], static function (array $role) use ($grid): bool {
            foreach ($grid['summary'] as $row) {
                if (($row['roles'][(string)$role['id']] ?? 0) > 0) {
                    return true;
                }
            }
            return false;
        }));
        $rows[] = [['v' => 'Resumo do mês', 's' => ['bold' => true, 'fill' => self::DAY_FILL]]];
        $line = [['v' => 'Técnico', 's' => $head]];
        foreach ($roles as $role) {
            $line[] = ['v' => $role['name'], 's' => $head];
        }
        foreach (['Dias home', 'Dias presencial', '% presencial'] as $label) {
            $line[] = ['v' => $label, 's' => $head];
        }
        $rows[] = $line;
        $names = array_column($grid['people'], 'short_name', 'id');
        foreach ($grid['summary'] as $row) {
            $line = [['v' => $names[$row['employee_id']] ?? '', 's' => ['bold' => true] + $border]];
            foreach ($roles as $role) {
                $line[] = ['v' => $row['roles'][(string)$role['id']] ?? 0, 's' => ['center' => true] + $border];
            }
            $line[] = ['v' => $row['home'], 's' => ['center' => true] + $border];
            $line[] = ['v' => $row['onsite'], 's' => ['center' => true] + $border];
            $line[] = ['v' => $row['onsite_pct'] === null ? '' : $row['onsite_pct'] . '%', 's' => ['center' => true] + $border];
            $rows[] = $line;
        }
        $rows[] = [];
        // Legenda: os papeis com a cor e as cores fixas.
        $rows[] = [['v' => 'Legenda', 's' => ['bold' => true, 'fill' => self::DAY_FILL]]];
        foreach ($grid['roles'] as $role) {
            if (($role['active'] ?? true) === true) {
                $rows[] = [['v' => $role['label'], 's' => ['fill' => self::FILLS['papel-' . ($role['color_token'] ?? 'areia')]] + $border]];
            }
        }
        foreach (self::LEGEND as [$tone, $label]) {
            $rows[] = [['v' => $label, 's' => ['fill' => self::FILLS[$tone]] + $border]];
        }
        $writer = new XlsxWriter();
        $writer->addSheet(self::sheetName($grid['month']['label']), $rows, ['cols' => [0 => 18.1, 1 => 23.9, 2 => 23.9, 3 => 23.9, 4 => 23.9, 5 => 23.9], 'merges' => $merges]);
        return $writer->build();
    }

    /** "Novembro 2026" (o nome da aba, ate 31 caracteres). */
    public static function sheetName(string $label): string {
        return preg_match('/^.{1,31}/u', $label, $match) === 1 ? $match[0] : 'Escala';
    }

    private static function date(string $date): string {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }
}
