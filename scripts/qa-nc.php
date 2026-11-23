<?php
declare(strict_types=1);

// ============================================================================
// QA do NC (matricula do cadastro Senior) no cadastro, na hora extra e na
// correcao de ponto (lote NC, 2026-11-23; docs/DESENHO_NC_E_ICONE.md)
//
// Sem banco:
// 1. estrutura da migration 026, do rollback, das linhas do ensaio e da
//    consulta de verificacao;
// 2. servidor: a regra do NC, a copia no lancamento lida do cadastro (nunca
//    do corpo), NC_OBRIGATORIO, a leitura do NC para o formulario e os CSVs.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote. Nenhuma matricula real: so valores ficticios.
//
// Uso: php scripts/qa-nc.php
// ============================================================================

function assert_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
    $GLOBALS['qa_checks'] = ($GLOBALS['qa_checks'] ?? 0) + 1;
}

/** SQL sem comentarios de linha (-- ...), para as checagens estruturais. */
function qa_sql_without_comments(string $sql): string {
    return (string)preg_replace('/^\s*--.*$/m', '', $sql);
}

$root = dirname(__DIR__);

// ----------------------------------------------------------------------------
// 1. Migration 026, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261123_026_matricula_nc.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261123_026_matricula_nc_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261123_026_ensaio_linhas_nc.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261123_026_matricula_nc_check.sql');
$digits = "REGEXP_LIKE(%s, ''^[0-9]{1,12}$'', ''c'')";

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 026: fuso da sessao (A4)');
foreach ([['funcionarios', 'matricula', 'has_matricula'], ['portal_overtime_entries', 'employee_nc', 'has_overtime_nc'], ['portal_time_adjustments', 'employee_nc', 'has_adjustment_nc']] as [$table, $column, $flag]) {
    assert_same(1, preg_match("/TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'\\s*\\);\\s*SET @add_" . substr($flag, 4) . "_sql := IF\\(\\s*@{$flag} = 0,/", $migration), "migration 026: {$table}.{$column} so se nao existir (idempotente)");
}
assert_same([1, 2, 3], [
    substr_count($migration, 'ADD COLUMN matricula VARCHAR(12) NULL DEFAULT NULL'),
    substr_count($migration, 'ADD COLUMN employee_nc VARCHAR(12) NULL DEFAULT NULL'),
    substr_count($migration, 'ALGORITHM=INSTANT'),
], 'migration 026: as tres colunas NULL (nenhuma linha muda), so no metadado (INSTANT explicito)');
assert_same([1, 1, 1, 3], [
    substr_count($migration, 'ADD CONSTRAINT ck_funcionarios_matricula CHECK (matricula IS NULL OR ' . sprintf($digits, 'matricula') . ')'),
    substr_count($migration, 'ADD CONSTRAINT ck_overtime_employee_nc CHECK (employee_nc IS NULL OR ' . sprintf($digits, 'employee_nc') . ')'),
    substr_count($migration, 'ADD CONSTRAINT ck_time_adjustment_employee_nc CHECK (employee_nc IS NULL OR ' . sprintf($digits, 'employee_nc') . ')'),
    substr_count($migration, 'ALGORITHM=COPY'),
], 'migration 026: so digitos, de 1 a 12, sensivel a caixa, nos tres lugares (COPY explicito: o MySQL confere as linhas)');
foreach (['ck_funcionarios_matricula', 'ck_overtime_employee_nc', 'ck_time_adjustment_employee_nc'] as $constraint) {
    assert_same(1, substr_count($migration, "CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = '{$constraint}'"), "migration 026: {$constraint} so se nao existir");
}
assert_same([1, 1], [
    substr_count($migration, 'ALTER TABLE funcionarios ADD UNIQUE KEY uq_funcionarios_matricula (matricula), ALGORITHM=INPLACE, LOCK=NONE'),
    substr_count($migration, "TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_matricula'"),
], 'migration 026: NC unico entre os cadastros (varios NULL), sem travar a tabela, so se nao existir');
assert_same(0, preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP|TRUNCATE)\b/i', $migration), 'migration 026: so estrutura, nenhuma linha muda');
assert_same(0, preg_match('/NOT NULL/i', (string)preg_replace("/COMMENT\\s*(=\\s*)?''[^']*''/", '', $migration)), 'migration 026: nenhuma coluna obrigatoria no banco (o cadastro antigo fica sem NC)');

assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 026: para se ja houver copia *_bkp_026');
$positions = [
    strpos($rollback, 'CALL rollback_026_confere_copias();'),
    strpos($rollback, 'CREATE TABLE funcionarios_nc_bkp_026 AS SELECT id, matricula FROM funcionarios WHERE matricula IS NOT NULL'),
    strpos($rollback, 'CREATE TABLE portal_overtime_entries_nc_bkp_026 AS SELECT id, employee_nc FROM portal_overtime_entries WHERE employee_nc IS NOT NULL'),
    strpos($rollback, 'CREATE TABLE portal_time_adjustments_nc_bkp_026 AS SELECT id, employee_nc FROM portal_time_adjustments WHERE employee_nc IS NOT NULL'),
    strpos($rollback, "'ALTER TABLE portal_time_adjustments DROP CHECK ck_time_adjustment_employee_nc'"),
    strpos($rollback, "'ALTER TABLE portal_time_adjustments DROP COLUMN employee_nc'"),
    strpos($rollback, "'ALTER TABLE portal_overtime_entries DROP CHECK ck_overtime_employee_nc'"),
    strpos($rollback, "'ALTER TABLE portal_overtime_entries DROP COLUMN employee_nc'"),
    strpos($rollback, "'ALTER TABLE funcionarios DROP CHECK ck_funcionarios_matricula'"),
    strpos($rollback, "'ALTER TABLE funcionarios DROP INDEX uq_funcionarios_matricula'"),
    strpos($rollback, "'ALTER TABLE funcionarios DROP COLUMN matricula'"),
];
$sorted = $positions;
sort($sorted);
assert_same([false, $sorted], [in_array(false, $positions, true), $positions], 'rollback 026: confere, copia o NC (so ID e NC das linhas com NC) e so entao tira restricoes, indice e colunas');
assert_same(0, preg_match('/\b(DELETE|UPDATE|TRUNCATE)\b/i', $rollback), 'rollback 026: nenhuma linha apagada nem alterada (so copias e estrutura)');

assert_same(3, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio: cada comando so no banco ensaio_');
assert_same(1, substr_count($lines, "SET f.matricula = CONCAT('9', LPAD(f.id, 8, '0'))"), 'linhas do ensaio: NC ficticio (9 + o ID com 8 digitos), nunca uma matricula real');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP|INSERT)\b/i', qa_sql_without_comments($lines)), 'linhas do ensaio: nada apagado nem criado');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 026 so le');
foreach (['colunas_026', 'checks_026', 'indice_026', 'copias_de_rollback_026', 'cadastros_ativos', 'ativos_com_nc', 'ativos_sem_nc', 'horas_extras_com_copia', 'correcoes_com_copia', 'nc_repetido', 'nc_fora_do_formato', 'copias_fora_do_formato', 'copias_diferentes_do_cadastro'] as $count) {
    assert_same(1, preg_match('/AS ' . $count . '(,|;|\'|$|\s)/m', $check), "verificacao 026: conta {$count}");
}
$checkSql = qa_sql_without_comments($check);
// Lista branca dos usos do NC na verificacao: nome da coluna no
// information_schema, IS (NOT) NULL, COUNT(DISTINCT), REGEXP_LIKE e a
// comparacao da copia com o cadastro. Qualquer outro uso (exibir, concatenar,
// agrupar) reprova.
$ncLeft = (string)preg_replace([
    "/'(matricula|employee_nc)'/",
    '/\b(\w+\.)?(matricula|employee_nc) IS (NOT )?NULL\b/',
    '/COUNT\(DISTINCT matricula\)/',
    '/REGEXP_LIKE\((matricula|employee_nc), /',
    '/\b[ot]\.employee_nc <=> f\.matricula\b/',
], '', $checkSql);
assert_same([0, 0], [
    preg_match('/\b(matricula|employee_nc)\b/i', $ncLeft),
    preg_match('/\b(nome|employee_name|ad_login|reason|justification|note)\b/i', $checkSql),
], 'verificacao 026: nenhum NC, nome, login nem texto livre na saida (o NC so em contagem, formato e comparacao)');

// ----------------------------------------------------------------------------
// 2. Servidor: regra do NC, copia no lancamento, NC_OBRIGATORIO, leitura para o
//    formulario e os CSVs (banco simulado; NC ficticio)
// ----------------------------------------------------------------------------
putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-nc-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
define('CHRONODESK_STATELESS', true);
require_once $root . '/services/OperationalService.php';

assert_same(['000123', '1', '123456789012', null, null, false, false, false, false, false, '42'], [
    EmployeeNc::normalize(' 000123 '), EmployeeNc::normalize('1'), EmployeeNc::normalize('123456789012'),
    EmployeeNc::normalize(''), EmployeeNc::normalize(null), EmployeeNc::normalize('1234567890123'),
    EmployeeNc::normalize('12a'), EmployeeNc::normalize('-12'), EmployeeNc::normalize('1 2'), EmployeeNc::normalize(['12']),
    EmployeeNc::normalize(42),
], 'NC: so digitos, de 1 a 12, zero a esquerda preservado; vazio = sem NC; o resto e invalido');
$requiredWith = static function (?string $value): bool {
    $value === null ? putenv('NC_OBRIGATORIO') : putenv('NC_OBRIGATORIO=' . $value);
    $result = EmployeeNc::required();
    putenv('NC_OBRIGATORIO');
    return $result;
};
assert_same([false, false, true, true, false, false], [$requiredWith(null), $requiredWith(''), $requiredWith('1'), $requiredWith('true'), $requiredWith('0'), $requiredWith('talvez')], 'NC_OBRIGATORIO: 1 ou true liga; ausente, vazio, 0 ou invalido desligam (so aviso)');
assert_same([null, 'incluido', 'alterado', 'removido'], [EmployeeNc::changeKind('1', '1'), EmployeeNc::changeKind(null, '1'), EmployeeNc::changeKind('1', '2'), EmployeeNc::changeKind('1', null)], 'NC: tipo da mudanca para a auditoria (nunca o valor)');
assert_same('NC do funcionario ID 7 alterado por lideranca', EmployeeNc::auditDetails(7, 'alterado', 'lideranca'), 'NC: auditoria so com o ID e o tipo');

final class QaNcPdo extends PDO {
    public array $employees = [];
    public array $inserted = [];
    public array $log = [];
    public array $rows = [];
    private bool $tx = false;

    public function __construct() {
    }

    public function beginTransaction(): bool { return $this->tx = true; }
    public function commit(): bool { $this->tx = false; return true; }
    public function rollBack(): bool { $this->tx = false; $this->inserted = []; return true; }
    public function inTransaction(): bool { return $this->tx; }
    public function lastInsertId(?string $name = null): string|false { return '501'; }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaNcStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }
}

final class QaNcStatement extends PDOStatement {
    private array $result = [];

    public function __construct(private QaNcPdo $db, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $this->db->log[] = $this->sql;
        $this->result = [];
        if ($this->sql === 'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login, access_role FROM funcionarios WHERE id = :id AND ativo = 1') {
            $row = $this->db->employees[(int)$params[':id']] ?? null;
            $this->result = $row === null ? [] : [['id' => (int)$params[':id'], 'name' => 'Ficticio ' . $params[':id'], 'team' => $row['team'], 'ad_login' => 'qa', 'access_role' => 'tecnico']];
            return true;
        }
        if (preg_match('/^SELECT matricula FROM funcionarios WHERE id = :id( FOR UPDATE)?$/', $this->sql) === 1) {
            $row = $this->db->employees[(int)$params[':id']] ?? null;
            $this->result = $row === null ? [] : [['matricula' => $row['nc']]];
            return true;
        }
        if (preg_match('/^INSERT INTO (portal_overtime_entries|portal_time_adjustments) /', $this->sql, $table) === 1) {
            $this->db->inserted[] = ['table' => $table[1]] + $params;
            return true;
        }
        if (strpos($this->sql, 'INSERT INTO portal_sync_queue') === 0) {
            return true;
        }
        if (preg_match('/^SELECT id, employee_id, employee_name, team, (work_date|adjustment_date), /', $this->sql) === 1) {
            $this->result = $this->db->rows;
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $this->sql);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->result) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->result);
        return $row === null ? false : array_values($row)[$column];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->result;
        $this->result = [];
        return $rows;
    }
}

$ncDb = static function (): QaNcPdo {
    $pdo = new QaNcPdo();
    $pdo->employees = [
        11 => ['team' => 'n1', 'nc' => '900000011'],
        12 => ['team' => 'n2', 'nc' => null],
        13 => ['team' => 'critico', 'nc' => '000000013'],
    ];
    return $pdo;
};
$tecnico = static fn (int $id): array => ['role' => 'tecnico', 'employee_id' => $id, 'username' => 'qa.tecnico'];
$gestao = ['role' => 'gestor', 'employee_id' => null, 'username' => 'qa.gestor'];
$overtimeBody = ['work_date' => '2026-11-20', 'start_time' => '18:00', 'end_time' => '19:00', 'reason' => 'qa', 'justification' => 'qa'];
$adjustmentBody = ['adjustment_date' => '2026-11-20', 'adjustment_type' => 'entry', 'correct_time' => '08:00', 'justification' => 'qa'];
$launch = static function (string $kind, array $body, array $actor, ?string $required = null) use ($ncDb): array {
    $pdo = $ncDb();
    $required === null ? putenv('NC_OBRIGATORIO') : putenv('NC_OBRIGATORIO=' . $required);
    try {
        $service = new OperationalService($pdo, '2026-11-20');
        $kind === 'overtime' ? $service->createOvertime($body, $actor) : $service->createTimeAdjustment($body, $actor);
        $error = null;
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    } finally {
        putenv('NC_OBRIGATORIO');
    }
    return [$error, array_map(static fn (array $row): array => [$row['table'], $row[':employee_id'], array_key_exists(':employee_nc', $row) ? $row[':employee_nc'] : 'sem a coluna'], $pdo->inserted)];
};
assert_same([null, [['portal_overtime_entries', 11, '900000011']]], $launch('overtime', $overtimeBody + ['employee_nc' => '1', 'nc' => '2'], $tecnico(11)), 'hora extra: a copia do NC vem do cadastro, nunca do corpo (employee_nc e nc ignorados)');
assert_same([null, [['portal_time_adjustments', 13, '000000013']]], $launch('adjustment', $adjustmentBody + ['employee_id' => 13, 'nc' => '2'], $gestao), 'correcao de ponto: a gestao lanca para outra pessoa com a copia do NC dela (zero a esquerda preservado)');
assert_same([null, [['portal_overtime_entries', 12, null]]], $launch('overtime', $overtimeBody, $tecnico(12)), 'hora extra sem NC e sem NC_OBRIGATORIO: grava com a copia NULL (so aviso)');
assert_same([null, [['portal_time_adjustments', 12, null]]], $launch('adjustment', $adjustmentBody, $tecnico(12), '0'), 'correcao sem NC com NC_OBRIGATORIO=0: grava (so aviso)');
$refused = 'DomainException: Sua matrícula (NC) não está no cadastro. Peça à Liderança para incluir.';
assert_same([[$refused, []], [$refused, []]], [$launch('overtime', $overtimeBody, $tecnico(12), '1'), $launch('adjustment', $adjustmentBody + ['employee_id' => 12], $gestao, 'true')], 'NC_OBRIGATORIO ligada: sem NC no cadastro, os dois lancamentos sao recusados (409) com a mensagem do pedido, nada gravado');
assert_same([null, [['portal_overtime_entries', 11, '900000011']]], $launch('overtime', $overtimeBody, $tecnico(11), '1'), 'NC_OBRIGATORIO ligada: com NC, grava com a copia');
$read = static function (array $query, array $actor, ?string $required = null) use ($ncDb): array|string {
    $required === null ? putenv('NC_OBRIGATORIO') : putenv('NC_OBRIGATORIO=' . $required);
    try {
        return (new OperationalService($ncDb(), '2026-11-20'))->employeeNcForForm($query, $actor);
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    } finally {
        putenv('NC_OBRIGATORIO');
    }
};
assert_same([
    ['employee_id' => 11, 'nc' => '900000011', 'nc_required' => false],
    ['employee_id' => 12, 'nc' => null, 'nc_required' => true],
    ['employee_id' => 13, 'nc' => '000000013', 'nc_required' => false],
    ['employee_id' => 11, 'nc' => '900000011', 'nc_required' => false],
    'DomainException: Sessao sem colaborador vinculado.',
    'InvalidArgumentException: Colaborador nao encontrado ou inativo.',
], [
    $read(['employee_id' => 13], $tecnico(11)),
    $read([], $tecnico(12), '1'),
    $read(['employee_id' => 13], $gestao),
    $read(['employee_id' => 13], ['role' => 'somente_leitura', 'employee_id' => 11]),
    $read([], ['role' => 'tecnico', 'employee_id' => null]),
    $read(['employee_id' => 99], $gestao),
], 'leitura do NC para o formulario: tecnico e somente leitura so o proprio (o employee_id do pedido e ignorado); a gestao, qualquer ativo; com a chave');
$csvRow = OperationalService::overtimeCsvRow(['employee_id' => 11, 'nc' => '000000013', 'employee_name' => 'Ficticio', 'work_date' => '2026-11-20', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'reason' => 'qa', 'justification' => 'qa', 'total_minutes' => 60, 'status' => 'pending'], []);
$csvOld = OperationalService::overtimeCsvRow(['employee_id' => 12, 'nc' => null, 'employee_name' => 'Ficticio', 'work_date' => '2026-11-20', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'reason' => 'qa', 'justification' => 'qa', 'total_minutes' => 60, 'status' => 'pending'], []);
assert_same(['000000013', '', 'Ficticio', 10, 10], [$csvRow[0], $csvOld[0], $csvRow[1], count($csvRow), count(OperationalService::OVERTIME_CSV_HEADER)], 'CSV de horas extras: o NC na 1a coluna (vazio sem NC); a ordem e os outros campos nao mudam');
$csvFor = static function (string $kind) use ($ncDb, $gestao): array {
    $pdo = $ncDb();
    $pdo->rows = [
        ['id' => 2, 'employee_id' => 11, 'employee_name' => 'Ficticio 11', 'team' => 'n1', 'work_date' => '2026-11-20', 'adjustment_date' => '2026-11-20', 'adjustment_type' => 'entry', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'correct_time' => '08:00:00', 'recorded_time' => null, 'total_minutes' => 60, 'reason' => 'qa', 'justification' => 'qa', 'status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'nc' => '900000011'],
        ['id' => 1, 'employee_id' => 12, 'employee_name' => 'Ficticio 12', 'team' => 'n2', 'work_date' => '2026-11-19', 'adjustment_date' => '2026-11-19', 'adjustment_type' => 'entry', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'correct_time' => '08:00:00', 'recorded_time' => null, 'total_minutes' => 60, 'reason' => 'qa', 'justification' => 'qa', 'status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'nc' => null],
    ];
    ob_start();
    $service = new OperationalService($pdo, '2026-11-20');
    $kind === 'overtime' ? @$service->streamOvertimeCsv(['from' => '2026-11-16', 'to' => '2026-11-20'], $gestao) : @$service->streamTimeAdjustmentsCsv(['from' => '2026-11-16', 'to' => '2026-11-20'], $gestao);
    $out = (string)ob_get_clean();
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', substr($out, 3)))));
    $exportSql = array_values(array_filter($pdo->log, static fn (string $sql): bool => strpos($sql, ' AS nc FROM ') !== false));
    return [array_map(static fn (string $line): string => explode(';', $line)[0], $lines), count($exportSql), $exportSql[0] ?? ''];
};
foreach (['overtime' => 'portal_overtime_entries', 'adjustment' => 'portal_time_adjustments'] as $kind => $table) {
    [$firstColumn, $exportQueries, $exportSql] = $csvFor($kind);
    assert_same([['NC', '900000011', ''], 1, true], [$firstColumn, $exportQueries, strpos($exportSql, "COALESCE({$table}.employee_nc, (SELECT f.matricula FROM funcionarios f WHERE f.id = {$table}.employee_id)) AS nc FROM {$table} WHERE") !== false], "CSV ({$kind}): a coluna NC com a copia ou, nas linhas antigas, o NC atual do cadastro, numa consulta so do export");
}
$operationalSource = (string)file_get_contents($root . '/services/OperationalService.php');
assert_same([0, 2], [substr_count($operationalSource, "\$data['nc']") + substr_count($operationalSource, "\$data['employee_nc']"), substr_count($operationalSource, '$nc = $this->launchNc((int)$employee[\'id\']);')], 'servidor: o NC do lancamento nunca vem do corpo; os dois lancamentos leem do cadastro');
foreach (['api/portal/overtime.php', 'api/portal/time_corrections.php'] as $endpoint) {
    $source = (string)file_get_contents($root . '/' . $endpoint);
    assert_same(true, strpos($source, "if ((\$_GET['nc'] ?? '') === '1') {\n            json_response(['sucesso' => true] + \$service->employeeNcForForm(\$_GET, \$actor));") !== false && strpos($source, '$actor = portal_actor($role);') < strpos($source, "employeeNcForForm"), "{$endpoint}: leitura do NC do formulario so com sessao e pelo perfil do ator");
}

echo "QA NC OK ({$GLOBALS['qa_checks']} verificacoes)\n";
