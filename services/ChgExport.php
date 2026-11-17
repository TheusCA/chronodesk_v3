<?php
declare(strict_types=1);

require_once __DIR__ . '/ChgTable.php';
require_once __DIR__ . '/XlsxWriter.php';

/**
 * [9.17] Excel do Controle de CHG (abas "Cards" e "Base", como o v5.2): a aba
 * Cards traz um bloco "Dados" por card, com as secoes e as cores de status; a
 * Base, uma linha por card, com o filtro automatico. Links so http(s).
 */
final class ChgExport {
    private const BORDER = ['border' => true];

    /** Os bytes do .xlsx dos cards apresentados (ChgService::present), na ordem dada. */
    public static function xlsx(array $cards): string {
        $writer = new XlsxWriter();
        $writer->addSheet('Cards', ...self::cardsSheet($cards));
        $writer->addSheet('Base', ...self::baseSheet($cards));
        return $writer->build();
    }

    private static function cardsSheet(array $cards): array {
        $rows = [];
        $merges = [];
        $links = [];
        foreach ($cards as $card) {
            $rows[] = [['v' => 'Dados', 's' => ['bold' => true, 'color' => 'FFFFFF', 'fill' => '215967', 'center' => true] + self::BORDER], ['v' => '', 's' => self::BORDER]];
            $merges[] = 'A' . count($rows) . ':B' . count($rows);
            foreach (ChgTable::rows($card) as $row) {
                if ($row['section']) {
                    $style = ['bold' => true, 'fill' => $row['rollback'] ? 'F7DFF0' : 'DDEBF7', 'color' => $row['rollback'] ? '863873' : '1F4E78'] + self::BORDER;
                    $rows[] = [['v' => $row['k'], 's' => $style], ['v' => '', 's' => $style]];
                    $merges[] = 'A' . count($rows) . ':B' . count($rows);
                    continue;
                }
                $value = ['wrap' => true] + self::BORDER;
                $isLink = $row['link'] && preg_match('~^https?://~i', $row['v']) === 1;
                if ($isLink) {
                    $value += ['color' => '0563C1', 'underline' => true];
                } elseif ($row['color'] !== null) {
                    $value += ['bold' => true, 'color' => $row['color']];
                } elseif ($row['bold']) {
                    $value += ['bold' => true];
                }
                $rows[] = [['v' => $row['k'], 's' => ['bold' => $row['bold']] + self::BORDER], ['v' => $row['v'], 's' => $value]];
                if ($isLink) {
                    $links[] = ['ref' => 'B' . count($rows), 'url' => $row['v']];
                }
            }
            $rows[] = [null, null];
        }
        if ($rows === []) {
            $rows[] = ['Nenhuma atualização nos filtros atuais'];
        }
        return [$rows, ['cols' => [32, 90], 'merges' => $merges, 'links' => $links]];
    }

    private static function baseSheet(array $cards): array {
        $perCard = array_map(static fn (array $card): array => array_values(array_filter(ChgTable::rows($card), static fn (array $r): bool => !$r['section'])), $cards);
        $columns = [];
        foreach ($perCard as $rows) {
            foreach ($rows as $row) {
                $columns[$row['k']] = true;
            }
        }
        $columns = array_keys($columns);
        $variable = static fn (string $k): bool => preg_match('/Endpoint$|– Início$|tentativa – Status$/u', $k) === 1;
        $header = array_merge(array_values(array_filter($columns, static fn (string $k): bool => !$variable($k))), array_values(array_filter($columns, $variable)));
        $headStyle = ['bold' => true, 'color' => 'FFFFFF', 'fill' => '215967', 'wrap' => true] + self::BORDER;
        $rows = [array_map(static fn (string $h): array => ['v' => $h, 's' => $headStyle], $header)];
        $links = [];
        foreach ($perCard as $cardRows) {
            $byKey = [];
            foreach ($cardRows as $row) {
                $byKey[$row['k']] = $row;
            }
            $line = [];
            foreach ($header as $c => $key) {
                $value = $byKey[$key]['v'] ?? '';
                if ($key === 'Quantidade de Pas' && $value !== '' && ctype_digit($value)) {
                    $line[] = ['v' => (int)$value, 's' => self::BORDER];
                    continue;
                }
                $line[] = ['v' => $value, 's' => self::BORDER];
                if (($byKey[$key]['link'] ?? false) && preg_match('~^https?://~i', $value) === 1) {
                    $links[] = ['ref' => XlsxWriter::column($c) . (count($rows) + 1), 'url' => $value];
                }
            }
            $rows[] = $line;
        }
        $widths = array_map(static fn (string $h): int => preg_match('/Descrição|Versão|Link|Endpoint|Plano/u', $h) === 1 ? 45 : max(14, ChgRules::length($h) + 2), $header);
        $options = ['cols' => $widths, 'links' => $links];
        if (count($rows) > 1 && $header !== []) {
            $options['autofilter'] = 'A1:' . XlsxWriter::column(count($header) - 1) . count($rows);
        }
        return [$rows, $options];
    }
}
