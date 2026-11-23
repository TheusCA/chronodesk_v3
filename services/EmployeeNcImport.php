<?php
declare(strict_types=1);

require_once __DIR__ . '/EmployeeNc.php';

/**
 * [Lote NC revisto, D-NC-11 a D-NC-13] Importacao de NCs com previa: uma
 * planilha (.xlsx ou .csv; o modelo do RH serve) com as colunas "NC" e "Nome
 * completo". Casa pelo nome normalizado com os cadastros ativos e separa o
 * que entra (cadastro sem NC), o que muda (outro NC), o que fica igual, o que
 * nao casou, o NC invalido e o NC repetido. Nada e gravado na previa; o
 * confirmar refaz a previa numa transacao, com os cadastros travados, e grava
 * so o que entra e o que muda. Rodar de novo o mesmo arquivo da tudo igual.
 */
final class EmployeeNcImport {
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 30;
    public const MAX_CELL_CHARS = 200;
    public const LIST_LIMIT = 200;
    public const HEADER_MESSAGE = 'A planilha precisa das colunas "NC" e "Nome completo".';

    private const FOLD = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ç' => 'c', 'Ñ' => 'n',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /** Nome ou cabecalho sem acento, em minusculas, com um espaco entre as palavras. */
    public static function normalize(string $value): string {
        $value = strtolower(strtr($value, self::FOLD));
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    /** NC lido da planilha: numero do Excel ("123" ou "123.0") vira texto; o resto pela regra do NC. */
    public static function ncFromCell(string $value): string|null|false {
        $value = trim($value);
        if (preg_match('/^(\d+)\.0+$/', $value, $match) === 1) {
            $value = $match[1];
        }
        return EmployeeNc::normalize($value);
    }

    /** Previa sobre o estado atual do banco; nada e gravado. */
    public function preview(array $matrix): array {
        return $this->classify($matrix, false);
    }

    /**
     * Confirmar: a previa de novo, numa transacao, com os cadastros travados;
     * grava so o que entra e o que muda. Devolve a previa e os IDs gravados.
     */
    public function confirm(array $matrix): array {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $this->classify($matrix, true);
            $store = new EmployeeNc($this->pdo);
            foreach (['entra', 'muda'] as $group) {
                foreach ($result['todos'][$group] as $item) {
                    $store->store($item['employee_id'], $item['nc']);
                }
            }
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $result['gravados'] = [
            'incluidos' => array_column($result['todos']['entra'], 'employee_id'),
            'alterados' => array_column($result['todos']['muda'], 'employee_id'),
        ];
        return $result;
    }

    /** [D-NC-13] Auditoria: contagens e IDs, nunca o NC. */
    public static function auditDetails(array $result, string $actorRole): string {
        $ids = static fn (array $list): string => $list === [] ? '-' : implode(', ', $list);
        $r = $result['resumo'];
        return "Importacao de NC por {$actorRole}: {$r['linhas']} linha(s); incluidos {$r['entra']} (IDs " . $ids($result['gravados']['incluidos'])
            . "), alterados {$r['muda']} (IDs " . $ids($result['gravados']['alterados']) . "), iguais {$r['igual']}, nao casaram {$r['nao_casou']}, NC invalido {$r['nc_invalido']}, NC repetido {$r['nc_repetido']}";
    }

    private function classify(array $matrix, bool $lock): array {
        [$header, $ncCol, $nameCol] = $this->header($matrix);
        $byName = [];
        $invalid = [];
        $lines = 0;
        foreach (array_slice($matrix, $header + 1, null, true) as $index => $row) {
            $name = trim((string)($row[$nameCol] ?? ''));
            $rawNc = trim((string)($row[$ncCol] ?? ''));
            if ($name === '' && $rawNc === '') {
                continue;
            }
            $lines++;
            $nc = self::ncFromCell($rawNc);
            $key = self::normalize($name);
            if ($key === '' || $nc === null || $nc === false) {
                $invalid[] = ['linha' => $index + 1, 'nome' => $name, 'motivo' => $key === '' ? 'sem nome' : ($nc === null ? 'sem NC' : 'NC inválido')];
                continue;
            }
            $byName[$key] ??= ['nome' => $name, 'ncs' => []];
            $byName[$key]['ncs'][$nc] = true;
        }
        $employees = $this->activeEmployees($lock);
        $holders = $this->ncHolders();
        $namesByNc = [];
        foreach ($byName as $key => $entry) {
            foreach (array_keys($entry['ncs']) as $nc) {
                $namesByNc[(string)$nc][$key] = true;
            }
        }
        $groups = ['entra' => [], 'muda' => [], 'igual' => [], 'nao_casou' => [], 'nc_repetido' => []];
        foreach ($byName as $key => $entry) {
            $ncs = array_map('strval', array_keys($entry['ncs']));
            if (count($ncs) > 1) {
                $groups['nc_repetido'][] = ['nome' => $entry['nome'], 'nc' => implode(', ', $ncs), 'motivo' => 'o mesmo nome com NCs diferentes no arquivo'];
                continue;
            }
            $nc = $ncs[0];
            if (count($namesByNc[$nc]) > 1) {
                $groups['nc_repetido'][] = ['nome' => $entry['nome'], 'nc' => $nc, 'motivo' => 'o mesmo NC para nomes diferentes no arquivo'];
                continue;
            }
            $matches = $employees[$key] ?? [];
            if (count($matches) !== 1) {
                $groups['nao_casou'][] = ['nome' => $entry['nome'], 'motivo' => $matches === [] ? 'sem cadastro ativo com esse nome' : 'mais de um cadastro ativo com esse nome'];
                continue;
            }
            $employee = $matches[0];
            $holder = $holders[$nc] ?? null;
            if ($holder !== null && $holder !== $employee['id']) {
                $groups['nc_repetido'][] = ['nome' => $entry['nome'], 'nc' => $nc, 'motivo' => 'o NC já está em outro cadastro'];
                continue;
            }
            $item = ['employee_id' => $employee['id'], 'nome' => $employee['nome'], 'nc' => $nc];
            if ($employee['nc'] === $nc) {
                $groups['igual'][] = $item;
            } elseif ($employee['nc'] === null) {
                $groups['entra'][] = $item;
            } else {
                $groups['muda'][] = $item + ['nc_atual' => $employee['nc']];
            }
        }
        $byNameSort = static fn (array $a, array $b): int => strcmp(self::normalize($a['nome']), self::normalize($b['nome']));
        foreach ($groups as $group => $list) {
            usort($list, $byNameSort);
            $groups[$group] = $list;
        }
        return [
            'resumo' => ['linhas' => $lines] + array_map('count', $groups) + ['nc_invalido' => count($invalid)],
            'entra' => array_slice($groups['entra'], 0, self::LIST_LIMIT),
            'muda' => array_slice($groups['muda'], 0, self::LIST_LIMIT),
            'nao_casou' => array_slice($groups['nao_casou'], 0, self::LIST_LIMIT),
            'nc_repetido' => array_slice($groups['nc_repetido'], 0, self::LIST_LIMIT),
            'nc_invalido' => array_slice($invalid, 0, self::LIST_LIMIT),
            'todos' => ['entra' => $groups['entra'], 'muda' => $groups['muda']],
        ];
    }

    /** A 1a linha com as colunas "NC" e "Nome completo" (sem acento, caixa e espaco). */
    private function header(array $matrix): array {
        foreach ($matrix as $index => $row) {
            $ncCol = null;
            $nameCol = null;
            foreach ($row as $col => $value) {
                $name = self::normalize((string)$value);
                if ($name === 'nc' && $ncCol === null) {
                    $ncCol = $col;
                } elseif ($name === 'nome completo' && $nameCol === null) {
                    $nameCol = $col;
                }
            }
            if ($ncCol !== null && $nameCol !== null) {
                return [$index, $ncCol, $nameCol];
            }
        }
        throw new InvalidArgumentException(self::HEADER_MESSAGE);
    }

    /** Cadastros ativos por nome normalizado (lista: o mesmo nome pode ter mais de um). */
    private function activeEmployees(bool $lock): array {
        $rows = $this->pdo->query('SELECT id, nome, matricula FROM funcionarios WHERE ativo = 1 ORDER BY id' . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($rows as $row) {
            $byName[self::normalize((string)$row['nome'])][] = [
                'id' => (int)$row['id'],
                'nome' => (string)$row['nome'],
                'nc' => $row['matricula'] === null ? null : (string)$row['matricula'],
            ];
        }
        return $byName;
    }

    /** NC => ID de quem tem (ativo ou arquivado). */
    private function ncHolders(): array {
        $holders = [];
        foreach ($this->pdo->query('SELECT id, matricula FROM funcionarios WHERE matricula IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $holders[(string)$row['matricula']] = (int)$row['id'];
        }
        return $holders;
    }
}
