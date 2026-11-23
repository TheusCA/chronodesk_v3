<?php
declare(strict_types=1);

/**
 * [Ajustes do CHG, D-CHG-39 e D-CHG-40] Importacao do catalogo de carteiras do
 * Controle de CHG a partir da planilha base (.xlsx ou .csv com as colunas "cod"
 * e "carteira"; as demais sao ignoradas e nunca gravadas).
 *
 * A previa compara com o catalogo inteiro (ativas e inativas): novas,
 * renomeadas (a inativa volta), reativadas, sem mudanca, as que sairam (ativas
 * fora do arquivo: viram ativo = 0, nunca apagadas) e as linhas recusadas
 * (codigo nao numerico, codigo repetido no arquivo, nome vazio ou longo). A
 * carteira de um codigo recusado no arquivo nao sai. Arquivo sem linha valida
 * nao confirma (nunca desativa o catalogo inteiro). Confirmar refaz a previa
 * com o catalogo travado e grava; repetir o arquivo da tudo "sem mudanca". Os
 * cards nao mudam: guardam a copia do codigo e do nome (D-CHG-18).
 */
final class ChgCatalogImport {
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 60;
    public const MAX_CELL_CHARS = 500;
    public const LIST_LIMIT = 200;
    public const HEADER_MESSAGE = 'A planilha precisa das colunas "cod" e "carteira".';
    public const EMPTY_MESSAGE = 'Nenhuma carteira válida na planilha: nada a importar.';
    private const FOLD = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a',
        'É' => 'e', 'Ê' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ú' => 'u', 'Ç' => 'c',
    ];
    private const GROUPS = ['novas', 'renomeadas', 'reativadas', 'sem_mudanca', 'sairam', 'recusadas'];

    public function __construct(private PDO $pdo, private string $actor) {
    }

    /** Cabecalho sem acento, caixa e espacos repetidos. */
    public static function normalize(string $value): string {
        return trim((string)preg_replace('/\s+/u', ' ', strtolower(strtr($value, self::FOLD))));
    }

    /** Codigo da celula: so digitos, de 1 a 10 (o "123.0" do Excel vira "123"); null se nao for. */
    public static function code(mixed $cell): ?string {
        $value = trim((string)$cell);
        if (preg_match('/^([0-9]+)\.0+$/', $value, $m) === 1) {
            $value = $m[1];
        }
        return preg_match('/^[0-9]{1,10}$/D', $value) === 1 ? $value : null;
    }

    public function preview(array $matrix): array {
        $result = $this->classify($matrix, false);
        unset($result['todos']);
        return $result;
    }

    /** Grava as novas, as renomeadas, as reativadas e as que sairam (ativo = 0). */
    public function confirm(array $matrix): array {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $this->classify($matrix, true);
            if (!$result['pode_confirmar']) {
                throw new InvalidArgumentException(self::EMPTY_MESSAGE);
            }
            $insert = $this->pdo->prepare('INSERT INTO portal_chg_carteiras (codigo, nome, ativo, created_by, updated_by) VALUES (:codigo, :nome, 1, :actor, :actor2)');
            foreach ($result['todos']['novas'] as $row) {
                $insert->execute([':codigo' => $row['codigo'], ':nome' => $row['nome'], ':actor' => $this->actor, ':actor2' => $this->actor]);
            }
            $update = $this->pdo->prepare('UPDATE portal_chg_carteiras SET nome = :nome, ativo = :ativo, updated_by = :actor WHERE id = :id');
            foreach (['renomeadas' => 1, 'reativadas' => 1, 'sairam' => 0] as $group => $ativo) {
                foreach ($result['todos'][$group] as $row) {
                    $update->execute([':nome' => $row['nome'], ':ativo' => $ativo, ':actor' => $this->actor, ':id' => $row['id']]);
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        unset($result['todos']);
        return $result;
    }

    /** Auditoria CHG_CATALOGO: so as contagens. */
    public static function auditDetails(array $result, string $action): string {
        $r = $result['resumo'];
        return ($action === 'confirm' ? 'Importacao do catalogo de carteiras' : 'Previa da importacao do catalogo de carteiras')
            . ": {$r['linhas']} linha(s); novas {$r['novas']}, renomeadas {$r['renomeadas']}, reativadas {$r['reativadas']}, sem mudanca {$r['sem_mudanca']}, sairam {$r['sairam']}, recusadas {$r['recusadas']}";
    }

    private function classify(array $matrix, bool $lock): array {
        [$header, $codeCol, $nameCol] = $this->header($matrix);
        $lines = [];
        $refused = [];
        $byCode = [];
        foreach ($matrix as $index => $row) {
            if ($index <= $header) {
                continue;
            }
            $rawCode = trim((string)($row[$codeCol] ?? ''));
            $name = trim((string)($row[$nameCol] ?? ''));
            if ($rawCode === '' && $name === '') {
                continue;
            }
            $line = ['linha' => $index + 1, 'codigo' => $rawCode, 'nome' => $name];
            $code = self::code($rawCode);
            $reason = $code === null ? 'código não numérico' : ($name === '' ? 'sem nome' : (preg_match('/^.{1,120}$/u', $name) !== 1 ? 'nome acima de 120 caracteres' : null));
            if ($reason !== null) {
                $refused[] = $line + ['motivo' => $reason];
                if ($code !== null) {
                    $byCode[$code][] = null;
                }
                continue;
            }
            $line['codigo'] = $code;
            $lines[] = $line;
            $byCode[$code][] = $line;
        }
        // Codigo repetido no arquivo: todas as linhas dele ficam fora, e a
        // carteira do catalogo com esse codigo nao muda nem sai.
        $valid = [];
        foreach ($lines as $line) {
            if (count($byCode[$line['codigo']]) > 1) {
                $refused[] = $line + ['motivo' => 'código repetido na planilha'];
            } else {
                $valid[$line['codigo']] = $line;
            }
        }
        $catalog = [];
        foreach ($this->pdo->query('SELECT id, codigo, nome, ativo FROM portal_chg_carteiras ORDER BY id' . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $catalog[(string)$row['codigo']] = ['id' => (int)$row['id'], 'nome' => (string)$row['nome'], 'ativo' => (int)$row['ativo'] === 1];
        }
        $groups = array_fill_keys(self::GROUPS, []);
        foreach ($valid as $code => $line) {
            $current = $catalog[$code] ?? null;
            if ($current === null) {
                $groups['novas'][] = ['codigo' => (string)$code, 'nome' => $line['nome']];
            } elseif ($current['nome'] !== $line['nome']) {
                $groups['renomeadas'][] = ['id' => $current['id'], 'codigo' => (string)$code, 'nome_atual' => $current['nome'], 'nome' => $line['nome']];
            } elseif (!$current['ativo']) {
                $groups['reativadas'][] = ['id' => $current['id'], 'codigo' => (string)$code, 'nome' => $line['nome']];
            } else {
                $groups['sem_mudanca'][] = ['codigo' => (string)$code, 'nome' => $line['nome']];
            }
        }
        foreach ($catalog as $code => $current) {
            if ($current['ativo'] && !isset($valid[$code]) && !isset($byCode[$code])) {
                $groups['sairam'][] = ['id' => $current['id'], 'codigo' => (string)$code, 'nome' => $current['nome']];
            }
        }
        $groups['recusadas'] = $refused;
        $byNumber = static fn (array $a, array $b): int => [(int)($a['linha'] ?? 0), strlen((string)$a['codigo']), (string)$a['codigo']] <=> [(int)($b['linha'] ?? 0), strlen((string)$b['codigo']), (string)$b['codigo']];
        $resumo = ['linhas' => count($lines) + count(array_filter($refused, static fn (array $r): bool => $r['motivo'] !== 'código repetido na planilha'))];
        $lists = [];
        foreach (self::GROUPS as $group) {
            usort($groups[$group], $byNumber);
            $resumo[$group] = count($groups[$group]);
            $lists[$group] = array_map(static function (array $row): array {
                unset($row['id']);
                return $row;
            }, array_slice($groups[$group], 0, self::LIST_LIMIT));
        }
        return [
            'resumo' => $resumo,
            'pode_confirmar' => $valid !== [],
            'mudancas' => $resumo['novas'] + $resumo['renomeadas'] + $resumo['reativadas'] + $resumo['sairam'],
        ] + $lists + ['todos' => $groups];
    }

    /** [indice da linha do cabecalho, coluna do codigo, coluna do nome]. */
    private function header(array $matrix): array {
        foreach ($matrix as $index => $row) {
            $names = array_map(static fn (mixed $cell): string => self::normalize((string)$cell), $row);
            $code = array_search('cod', $names, true);
            $name = array_search('carteira', $names, true);
            if ($code !== false && $name !== false) {
                return [$index, $code, $name];
            }
        }
        throw new InvalidArgumentException(self::HEADER_MESSAGE);
    }
}
