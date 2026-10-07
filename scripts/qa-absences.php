<?php
declare(strict_types=1);

// ============================================================================
// QA de ausencias (Parte B, F3)
//
// 1. Lista (AbsenceService::list): gestao ve todas; tecnico e somente
//    leitura, so as proprias, pelo colaborador da sessao; filtros de periodo,
//    status, equipe e colaborador; observacao so nesta lista (decisao 4).
// 2. Regras puras (AbsenceRules): validacao do payload (P5), jornada (P1),
//    sobreposicao (P6), mensagens.
// 3. Escrita (create, update, cancel): retroativo (D3, L6), Lideranca so
//    admin (A4), sobreposicao com trava e leitura FOR UPDATE (concorrencia no
//    REPEATABLE READ emulada), transacao, auditoria sem o texto da observacao.
// 4. Bloco de ausencias do Calendario (SEC-07, L-01, decisao 5).
// 5. Endpoints (estrutura; ponta a ponta no qa-security).
// 6. Migration 012, rollback, verificacao e ensaio (estrutura).
// Sem banco: um PDO simulado com estado emula as consultas do servico.
//
// Uso: php scripts/qa-absences.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$errorLogFile = tempnam(sys_get_temp_dir(), 'qa-absences-');
putenv('PHP_ERROR_LOG=' . $errorLogFile);
register_shutdown_function(static function () use ($errorLogFile): void {
    @unlink($errorLogFile);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.250';
define('CHRONODESK_STATELESS', true);

$GLOBALS['qa_pdo'] = null;
function get_db_connection() {
    if (!$GLOBALS['qa_pdo'] instanceof PDO) {
        throw new PDOException('SQLSTATE[HY000]: banco simulado ausente');
    }
    return $GLOBALS['qa_pdo'];
}

require_once __DIR__ . '/../services/OperationalService.php';
require_once __DIR__ . '/../services/AbsenceService.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

const QA_TODAY = '2026-10-07';

/** Cadastros ficticios (nenhum dado real). */
const QA_EMPLOYEES = [
    1 => ['nome' => 'Tecnico A', 'equipe' => 'n1', 'access_role' => 'tecnico', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'ativo' => 1],
    2 => ['nome' => 'Tecnico B', 'equipe' => 'N2', 'access_role' => 'tecnico', 'jornada_entrada' => '09:00:00', 'jornada_saida' => '18:00:00', 'ativo' => 1],
    3 => ['nome' => 'Lider C', 'equipe' => 'Liderança', 'access_role' => 'lideranca', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'ativo' => 1],
    5 => ['nome' => 'Tecnico E', 'equipe' => 'n1', 'access_role' => 'tecnico', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'ativo' => 0],
    6 => ['nome' => 'Tecnico F', 'equipe' => 'n1', 'access_role' => 'lideranca', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'ativo' => 1],
    7 => ['nome' => 'Tecnico G', 'equipe' => 'critico', 'access_role' => 'tecnico', 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00', 'ativo' => 1],
    8 => ['nome' => 'Tecnico H', 'equipe' => 'n2', 'access_role' => 'tecnico', 'jornada_entrada' => null, 'jornada_saida' => null, 'ativo' => 1],
];

/** Ausencias ficticias, na estrutura da migration 012. */
function qa_absence(int $id, int $employeeId, string $category, string $from, string $to, string $status = 'approved', array $extra = []): array {
    return $extra + [
        'id' => $id, 'employee_id' => $employeeId, 'employee_name' => QA_EMPLOYEES[$employeeId]['nome'] ?? 'Sem cadastro',
        'team' => validate_funcionario_equipe((string)(QA_EMPLOYEES[$employeeId]['equipe'] ?? 'n1')) ?? 'n1',
        'category' => $category, 'starts_on' => $from, 'ends_on' => $to, 'period_type' => 'full_day',
        'start_time' => null, 'end_time' => null, 'reason' => null, 'status' => $status, 'retroactive' => 0,
        'created_by' => 'qa', 'created_at' => null, 'updated_by' => null, 'updated_at' => null,
        'cancelled_by' => null, 'cancelled_at' => null,
    ];
}

function qa_seed(): array {
    return [
        1 => qa_absence(1, 1, 'ferias', '2026-10-05', '2026-10-09', 'approved', ['reason' => 'texto livre A']),
        2 => qa_absence(2, 2, 'ausencia_justificada', '2026-10-06', '2026-10-06', 'approved', ['reason' => 'texto livre B']),
        3 => qa_absence(3, 3, 'compromisso', '2026-10-07', '2026-10-08', 'cancelled', ['reason' => 'texto livre C']),
        4 => qa_absence(4, 1, 'folga', '2026-10-15', '2026-10-15'),
        5 => qa_absence(5, 0, 'outro', '2026-10-20', '2026-10-20', 'approved', ['reason' => 'texto livre E']),
        6 => qa_absence(6, 2, 'folga', '2026-11-03', '2026-11-03'),
    ];
}

/**
 * Banco simulado com estado: transacao (rollback desfaz o que a transacao
 * gravou), REPEATABLE READ (a primeira leitura simples da transacao fixa o
 * snapshot das ausencias; so a leitura com FOR UPDATE ve o que outra transacao
 * gravou depois) e $whileWaitingLock, outra transacao que grava enquanto esta
 * espera a trava do colaborador.
 */
final class QaAbsencesPdo extends PDO {
    public array $queries = [];
    public array $absences = [];
    public array $audits = [];
    public int $nextId = 100;
    public ?array $readView = null;
    public array $txWritten = [];
    public ?Closure $whileWaitingLock = null;
    private ?array $snapshot = null;

    public function __construct() {
        $this->absences = qa_seed();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaAbsencesStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (trim($query) === 'SELECT 1 FROM audit_log LIMIT 1') {
            return new QaAbsencesStatement($this, 'SELECT 1');
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }

    public function beginTransaction(): bool {
        $this->snapshot = [$this->absences, $this->audits, $this->nextId];
        $this->readView = null;
        $this->txWritten = [];
        $this->queries[] = ['BEGIN', []];
        return true;
    }

    public function commit(): bool {
        $this->snapshot = null;
        $this->readView = null;
        $this->txWritten = [];
        $this->queries[] = ['COMMIT', []];
        return true;
    }

    public function rollBack(): bool {
        [$before, $this->audits, $this->nextId] = $this->snapshot;
        foreach (array_keys($this->txWritten) as $id) {
            if (isset($before[$id])) {
                $this->absences[$id] = $before[$id];
            } else {
                unset($this->absences[$id]);
            }
        }
        $this->snapshot = null;
        $this->readView = null;
        $this->txWritten = [];
        $this->queries[] = ['ROLLBACK', []];
        return true;
    }

    public function inTransaction(): bool {
        return $this->snapshot !== null;
    }

    public function lastInsertId(?string $name = null): string|false {
        return (string)($this->nextId - 1);
    }

    /** Ausencias vistas por uma leitura simples: snapshot da transacao mais as proprias gravacoes. */
    public function consistentAbsences(): array {
        if ($this->snapshot === null || $this->readView === null) {
            return $this->absences;
        }
        $rows = $this->readView;
        foreach (array_keys($this->txWritten) as $id) {
            if (isset($this->absences[$id])) {
                $rows[$id] = $this->absences[$id];
            } else {
                unset($rows[$id]);
            }
        }
        return $rows;
    }

    public function write(int $id, array $row): void {
        $this->absences[$id] = $row;
        $this->txWritten[$id] = true;
    }
}

final class QaAbsencesStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaAbsencesPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $db = $this->pdo;
        $sql = $this->sql;
        $db->queries[] = [$sql, $params];
        $this->rows = [];
        $locking = substr($sql, -10) === 'FOR UPDATE';
        if ($db->inTransaction() && $db->readView === null && strpos($sql, 'SELECT') === 0 && !$locking) {
            $db->readView = $db->absences;
        }
        if ($sql === 'SELECT 1') {
            return true;
        }
        if ($sql === 'INSERT INTO audit_log (user_ip, username, action, details, severity) VALUES (?, ?, ?, ?, ?)') {
            $db->audits[] = ['action' => $params[2], 'details' => $params[3], 'severity' => $params[4]];
            return true;
        }
        // Lista (AbsenceService::list).
        if (strpos($sql, 'SELECT id, employee_id, employee_name, team, category, starts_on, ends_on, period_type, start_time, end_time, reason AS note, status, retroactive,') === 0) {
            foreach ($db->absences as $row) {
                if ($row['starts_on'] > $params[':date_to'] || $row['ends_on'] < $params[':date_from']
                    || (isset($params[':status']) && $row['status'] !== $params[':status'])
                    || (isset($params[':team']) && $row['team'] !== $params[':team'])
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])) {
                    continue;
                }
                $note = $row['reason'];
                unset($row['reason']);
                $this->rows[] = $row + ['note' => $note];
            }
            usort($this->rows, static fn (array $a, array $b): int => [$b['starts_on'], $b['id']] <=> [$a['starts_on'], $a['id']]);
            if (preg_match('/ LIMIT (\d+)$/', $sql, $limit) === 1) {
                $this->rows = array_slice($this->rows, 0, (int)$limit[1]);
            }
            return true;
        }
        // Bloco de ausencias do Calendario (listCalendar com type=absence).
        if (strpos($sql, 'SELECT id, employee_name AS title, category, CONCAT(starts_on, " 00:00:00") AS starts_at,') === 0) {
            foreach ($db->absences as $row) {
                if ($row['starts_on'] > $params[':date_to'] || $row['ends_on'] < $params[':date_from']
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])
                    || (strpos($sql, 'AND status = "approved"') !== false && $row['status'] !== 'approved')) {
                    continue;
                }
                $this->rows[] = [
                    'id' => $row['id'], 'title' => $row['employee_name'], 'category' => $row['category'],
                    'starts_at' => $row['starts_on'] . ' 00:00:00', 'ends_at' => $row['ends_on'] . ' 23:59:59',
                    'team' => null, 'employee_id' => $row['employee_id'], 'status' => $row['status'],
                ];
            }
            return true;
        }
        // Trava do colaborador.
        if ($sql === 'SELECT id, nome, equipe, access_role, jornada_entrada, jornada_saida, ativo FROM funcionarios WHERE id = :id FOR UPDATE') {
            if ($db->whileWaitingLock !== null) {
                $concurrent = $db->whileWaitingLock;
                $db->whileWaitingLock = null;
                $concurrent($db);
            }
            $row = QA_EMPLOYEES[(int)$params[':id']] ?? null;
            if ($row !== null) {
                $this->rows[] = ['id' => (int)$params[':id']] + $row;
            }
            return true;
        }
        // Ausencia por id (sem e com trava).
        if (preg_match('/^SELECT id, employee_id, category, starts_on, ends_on, period_type, start_time, end_time, status, retroactive FROM portal_absences WHERE id = :id( FOR UPDATE)?$/', $sql)) {
            $source = $locking ? $db->absences : $db->consistentAbsences();
            $row = $source[(int)$params[':id']] ?? null;
            if ($row !== null) {
                $this->rows[] = array_intersect_key($row, array_flip(['id', 'employee_id', 'category', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time', 'status', 'retroactive']));
            }
            return true;
        }
        // Ausencias aprovadas do colaborador que cruzam o periodo (sobreposicao).
        if (preg_match('/^SELECT id, starts_on, ends_on, period_type, start_time, end_time, status FROM portal_absences WHERE employee_id = :employee_id AND status = :status AND starts_on <= :date_to AND ends_on >= :date_from( FOR UPDATE)?$/', $sql)) {
            foreach ($locking ? $db->absences : $db->consistentAbsences() as $row) {
                if ($row['employee_id'] === $params[':employee_id'] && $row['status'] === $params[':status']
                    && $row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']) {
                    $this->rows[] = array_intersect_key($row, array_flip(['id', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time', 'status']));
                }
            }
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_absences (') === 0) {
            $id = $db->nextId++;
            $db->write($id, [
                'id' => $id, 'employee_id' => $params[':employee_id'], 'employee_name' => $params[':employee_name'],
                'team' => $params[':team'], 'category' => $params[':category'], 'starts_on' => $params[':starts_on'],
                'ends_on' => $params[':ends_on'], 'period_type' => $params[':period_type'], 'start_time' => $params[':start_time'],
                'end_time' => $params[':end_time'], 'reason' => $params[':reason'], 'status' => $params[':status'],
                'retroactive' => $params[':retroactive'], 'created_by' => $params[':created_by'], 'created_at' => null,
                'updated_by' => null, 'updated_at' => null, 'cancelled_by' => null, 'cancelled_at' => null,
            ]);
            return true;
        }
        if (strpos($sql, 'UPDATE portal_absences SET category = :category,') === 0) {
            $row = $db->absences[(int)$params[':id']] ?? null;
            if ($row !== null && $row['status'] === $params[':status']) {
                $db->write((int)$params[':id'], [
                    'category' => $params[':category'], 'starts_on' => $params[':starts_on'], 'ends_on' => $params[':ends_on'],
                    'period_type' => $params[':period_type'], 'start_time' => $params[':start_time'], 'end_time' => $params[':end_time'],
                    'reason' => $params[':reason'], 'retroactive' => max((int)$row['retroactive'], (int)$params[':retroactive']),
                    'updated_by' => $params[':updated_by'],
                ] + $row);
            }
            return true;
        }
        if (strpos($sql, 'UPDATE portal_absences SET status = :cancelled,') === 0) {
            $row = $db->absences[(int)$params[':id']] ?? null;
            if ($row !== null && $row['status'] === $params[':status']) {
                $db->write((int)$params[':id'], [
                    'status' => $params[':cancelled'], 'cancelled_by' => $params[':cancelled_by'], 'cancelled_at' => $params[':cancelled_at'],
                    'retroactive' => max((int)$row['retroactive'], (int)$params[':retroactive']), 'updated_by' => $params[':updated_by'],
                ] + $row);
            }
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

function qa_db(): QaAbsencesPdo {
    $pdo = new QaAbsencesPdo();
    $GLOBALS['qa_pdo'] = $pdo;
    return $pdo;
}

/** Executa e devolve 'ok', '403: msg', '409: msg', '400: msg' ou a classe da excecao. */
function qa_outcome(callable $call): string {
    try {
        $call();
        return 'ok';
    } catch (PortalForbiddenException $e) {
        return '403: ' . $e->getMessage();
    } catch (InvalidArgumentException $e) {
        return '400: ' . $e->getMessage();
    } catch (DomainException $e) {
        return '409: ' . $e->getMessage();
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
}

function qa_service(QaAbsencesPdo $pdo): AbsenceService {
    return new AbsenceService($pdo, QA_TODAY, new DateTimeImmutable('2026-10-07 10:30:00'));
}

$root = dirname(__DIR__);
$ids = static fn (array $result): array => array_column($result['items'], 'id');
$actor = static fn (string $role, ?int $employeeId = null): array => ['role' => $role, 'username' => 'qa.' . $role, 'employee_id' => $employeeId];
$writes = static fn (QaAbsencesPdo $pdo): array => array_values(array_filter(array_column($pdo->queries, 0), static fn (string $sql): bool => preg_match('/^(INSERT INTO portal_absences|UPDATE portal_absences)/', $sql) === 1));

// ----------------------------------------------------------------------------
// 1. Lista (SEC-07) com filtros
// ----------------------------------------------------------------------------
foreach (['gestor', 'lideranca', 'admin'] as $role) {
    $pdo = qa_db();
    $result = qa_service($pdo)->list([], $actor($role));
    assert_same([5, 4, 2, 1], $ids($result), "lista: {$role} ve todas as aprovadas do mes corrente, mais recentes primeiro");
    assert_same(['from' => '2026-10-01', 'to' => '2026-10-31'], $result['period'], "lista: {$role} periodo padrao = mes corrente");
    assert_same([true, $role !== 'gestor'], [$result['can_manage'], $result['can_retroactive']], "lista: {$role} pode lancar; retroativo so Lideranca e admin");
}
$pdo = qa_db();
$service = qa_service($pdo);
assert_same([3], $ids($service->list(['status' => 'cancelled'], $actor('gestor'))), 'lista: filtro cancelled');
assert_same([5, 4, 3, 2, 1], $ids($service->list(['status' => 'all'], $actor('gestor'))), 'lista: filtro all');
assert_same([6], $ids($service->list(['from' => '2026-11-01', 'to' => '2026-11-30'], $actor('gestor'))), 'lista: periodo informado');
assert_same([2], $ids($service->list(['team' => 'n2'], $actor('gestor'))), 'lista: filtro de equipe (gravada na ausencia)');
assert_same([4, 1], $ids($service->list(['employee_id' => '1'], $actor('gestor'))), 'lista: gestao filtra colaborador');
assert_same('texto livre A', $service->list([], $actor('gestor'))['items'][3]['note'], 'lista: gestao ve a observacao (note)');
assert_same(false, array_key_exists('reason', $service->list([], $actor('gestor'))['items'][0]), 'lista: coluna crua reason fora da resposta');
assert_same([['value' => 'folga', 'label' => 'Folga'], ['value' => 'ferias', 'label' => 'Férias'], ['value' => 'ausencia_justificada', 'label' => 'Ausência justificada'], ['value' => 'compromisso', 'label' => 'Compromisso'], ['value' => 'outro', 'label' => 'Outro']], $service->list([], $actor('gestor'))['categories'], 'lista: cinco categorias (D4)');
foreach (['tecnico', 'somente_leitura'] as $role) {
    $pdo = qa_db();
    $result = qa_service($pdo)->list(['employee_id' => '2', 'status' => 'all'], $actor($role, 1));
    assert_same([4, 1], $ids($result), "lista: {$role} ve so as proprias, mesmo pedindo outro colaborador");
    assert_same(['texto livre A', false, false], [$result['items'][1]['note'], $result['can_manage'], $result['can_retroactive']], "lista: {$role} ve a observacao da propria (decisao 4) e nao lanca");
    $pdo = qa_db();
    $result = qa_service($pdo)->list([], $actor($role, 0));
    assert_same([[], []], [$result['items'], $pdo->queries], "lista: {$role} sem colaborador na sessao, vazia e sem consultar o banco");
}
$pdo = qa_db();
assert_same([[], []], [qa_service($pdo)->list([], [])['items'], $pdo->queries], 'lista: sem perfil, vazia (negar por padrao)');
foreach ([
    [['status' => 'pending'], '400: Status invalido.'],
    [['team' => 'n3'], '400: Equipe invalida.'],
    [['from' => '2026-02-30'], '400: Data inicial invalida.'],
    [['from' => '2026-10-10', 'to' => '2026-10-01'], '400: A data final deve ser igual ou posterior a inicial.'],
    [['from' => '2026-01-01', 'to' => '2027-01-02'], '400: O periodo maximo e de 366 dias.'],
    [['employee_id' => '1 OR 1=1'], '400: Identificador invalido.'],
] as [$filters, $expected]) {
    assert_same($expected, qa_outcome(fn () => qa_service(qa_db())->list($filters, $actor('gestor'))), 'lista: filtro invalido ' . json_encode($filters));
}
$pdo = qa_db();
for ($i = 0; $i < 501; $i++) {
    $pdo->absences[1000 + $i] = qa_absence(1000 + $i, 2, 'outro', '2026-10-01', '2026-10-01');
}
$result = qa_service($pdo)->list([], $actor('admin'));
assert_same([500, true, 500], [count($result['items']), $result['truncated'], $result['limit']], 'lista: limite de 500 com truncated (PERF-02)');
assert_same(true, substr(end($pdo->queries)[0], -10) === ' LIMIT 501', 'lista: o banco devolve no maximo 501 linhas (limite + 1)');

// ----------------------------------------------------------------------------
// 2. Regras puras
// ----------------------------------------------------------------------------
$base = ['employee_id' => 1, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'category' => 'folga'];
assert_same(['employee_id' => 1, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'folga', 'note' => null], AbsenceRules::normalize($base), 'regras: dia inteiro, padroes');
assert_same(['from_time', '14:00', null], array_values(array_intersect_key(AbsenceRules::normalize(['period_type' => 'from_time', 'start_time' => '14:00', 'end_time' => '16:00'] + $base), array_flip(['period_type', 'start_time', 'end_time']))), 'regras: "a partir de" ignora o fim');
assert_same([null, null], array_values(array_intersect_key(AbsenceRules::normalize(['start_time' => '14:00', 'end_time' => '16:00'] + $base), array_flip(['start_time', 'end_time']))), 'regras: dia inteiro ignora horarios');
assert_same('obs', AbsenceRules::normalize(['note' => '  obs  '] + $base)['note'], 'regras: observacao sem espacos nas pontas');
assert_same(null, AbsenceRules::normalize(['note' => '   '] + $base)['note'], 'regras: observacao vazia vira nula');
foreach ([
    [['employee_id' => '0'], 'Colaborador invalido.'],
    [['employee_id' => '1a'], 'Colaborador invalido.'],
    [['starts_on' => '2026-13-01'], 'Data inicial invalida.'],
    [['ends_on' => '2026-10-19'], 'A data final deve ser igual ou posterior a inicial.'],
    [['starts_on' => '2026-01-01', 'ends_on' => '2027-01-02'], 'A ausencia pode ter no maximo 366 dias.'],
    [['period_type' => 'meio_dia'], 'Tipo de periodo invalido.'],
    [['period_type' => 'from_time', 'start_time' => '14:00', 'ends_on' => '2026-10-21'], 'Horario so pode ser informado em ausencia de um dia; periodo de varios dias e sempre dia inteiro.'],
    [['period_type' => 'from_time'], 'Informe o horario inicial.'],
    [['period_type' => 'interval', 'start_time' => '10:00'], 'No intervalo, o horario final deve ser posterior ao inicial.'],
    [['period_type' => 'interval', 'start_time' => '10:00', 'end_time' => '10:00'], 'No intervalo, o horario final deve ser posterior ao inicial.'],
    [['period_type' => 'interval', 'start_time' => '25:00', 'end_time' => '26:00'], 'Horario inicial invalido.'],
    [['category' => 'atestado'], 'Categoria de ausencia invalida.'],
    [['note' => str_repeat('a', 501)], 'A observacao pode ter no maximo 500 caracteres.'],
    [['note' => ['x']], 'Observacao invalida.'],
    [['note' => "abc\xC3\x28"], 'Observacao invalida.'],
    [['note' => str_repeat('é', 501)], 'A observacao pode ter no maximo 500 caracteres.'],
] as [$overrides, $message]) {
    assert_same('400: ' . $message, qa_outcome(fn () => AbsenceRules::normalize($overrides + $base)), 'regras: ' . $message);
}
assert_same('ok', qa_outcome(fn () => AbsenceRules::normalize(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'] + $base)), 'regras: 365 dias aceitos');
assert_same('ok', qa_outcome(fn () => AbsenceRules::normalize(['note' => str_repeat('é', 500)] + $base)), 'regras: 500 caracteres multibyte aceitos');
$partial = static fn (string $type, string $start, ?string $end = null, string $day = '2026-10-20'): array => ['starts_on' => $day, 'ends_on' => $day, 'period_type' => $type, 'start_time' => $start, 'end_time' => $end];
$full = static fn (string $from, string $to): array => ['starts_on' => $from, 'ends_on' => $to, 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null];
foreach ([
    [$full('2026-10-20', '2026-10-20'), $partial('interval', '10:00', '11:00'), true, 'dia inteiro x parcial'],
    [$full('2026-10-19', '2026-10-21'), $full('2026-10-21', '2026-10-25'), true, 'periodos que se tocam no ultimo dia'],
    [$full('2026-10-19', '2026-10-20'), $full('2026-10-21', '2026-10-25'), false, 'periodos vizinhos'],
    [$partial('interval', '10:00', '12:00'), $partial('interval', '13:00', '14:00'), false, 'intervalos separados'],
    [$partial('interval', '10:00', '12:00'), $partial('interval', '12:00', '13:00'), false, 'intervalo que termina quando o outro comeca'],
    [$partial('interval', '10:00', '12:00'), $partial('interval', '11:59', '13:00'), true, 'intervalos que se cruzam'],
    [$partial('interval', '10:00', '12:00'), $partial('from_time', '11:30'), true, 'intervalo x a partir de dentro dele'],
    [$partial('interval', '13:00', '14:00'), $partial('from_time', '14:00'), false, 'a partir de quando o intervalo termina'],
    [$partial('from_time', '15:00'), $partial('interval', '16:00', '16:30'), true, 'a partir de vai ate o fim da jornada'],
    [$partial('from_time', '15:00'), $partial('interval', '17:00', '17:30'), false, 'depois do fim da jornada nao cruza'],
    [$partial('interval', '10:00', '11:00'), $partial('interval', '10:00', '11:00', '2026-10-21'), false, 'parciais em dias diferentes'],
] as [$a, $b, $expected, $label]) {
    assert_same([$expected, $expected], [AbsenceRules::overlaps($a, $b, '17:00:00'), AbsenceRules::overlaps($b, $a, '17:00:00')], "sobreposicao: {$label} (simetrica)");
}
assert_same(true, AbsenceRules::overlaps($partial('from_time', '15:00'), $partial('interval', '20:00', '21:00'), null), 'sobreposicao: sem jornada, "a partir de" vai ate o fim do dia');
assert_same(null, AbsenceRules::findConflict($full('2026-10-20', '2026-10-20'), [['id' => 9, 'status' => 'cancelled'] + $full('2026-10-20', '2026-10-20'), ['id' => 10] + $full('2026-10-20', '2026-10-20')], '17:00', 10), 'sobreposicao: cancelada e a propria nao contam');
assert_same('Ja existe ausencia aprovada do colaborador de 05/10/2026 a 09/10/2026 (dia todo).', AbsenceRules::conflictMessage($full('2026-10-05', '2026-10-09')), 'mensagem: periodo de varios dias');
assert_same('Ja existe ausencia aprovada do colaborador em 20/10/2026 a partir de 14:00.', AbsenceRules::conflictMessage($partial('from_time', '14:00:00')), 'mensagem: a partir de, hora do banco');
assert_same('Ja existe ausencia aprovada do colaborador em 20/10/2026, 10:00–12:00.', AbsenceRules::conflictMessage($partial('interval', '10:00', '12:00')), 'mensagem: intervalo');
foreach ([
    [$partial('interval', '08:00', '17:00'), 'ok'],
    [$partial('interval', '07:59', '09:00'), '400: O horario deve ficar dentro da jornada do colaborador (08:00–17:00).'],
    [$partial('interval', '16:00', '17:01'), '400: O horario deve ficar dentro da jornada do colaborador (08:00–17:00).'],
    [$partial('from_time', '17:00'), '400: O horario deve ficar dentro da jornada do colaborador (08:00–17:00).'],
    [$partial('from_time', '16:59'), 'ok'],
] as [$absence, $expected]) {
    assert_same($expected, qa_outcome(fn () => AbsenceRules::assertWithinWorkday($absence, '08:00:00', '17:00:00')), 'jornada (P1): ' . json_encode($absence));
}
assert_same('400: A jornada do cadastro e invalida: lance a ausencia como dia inteiro ou corrija a jornada.', qa_outcome(fn () => AbsenceRules::assertWithinWorkday($partial('from_time', '10:00'), null, null)), 'jornada: cadastro sem jornada recusa horario');
assert_same('ok', qa_outcome(fn () => AbsenceRules::assertWithinWorkday($full('2026-10-20', '2026-10-20'), null, null)), 'jornada: dia inteiro nao depende da jornada');

// ----------------------------------------------------------------------------
// 3. Escrita: create, update, cancel
// ----------------------------------------------------------------------------
$create = ['employee_id' => 2, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-22', 'category' => 'ferias', 'note' => 'texto sensivel X'];
$pdo = qa_db();
$result = qa_service($pdo)->create($create, $actor('gestor'));
$row = $pdo->absences[$result['id']];
assert_same([100, false], [$result['id'], $result['retroactive']], 'create: gestor lanca ausencia futura');
assert_same(['n2', 'Tecnico B', 'approved', 0, 'qa.gestor', 'texto sensivel X', 'full_day'], [$row['team'], $row['employee_name'], $row['status'], $row['retroactive'], $row['created_by'], $row['reason'], $row['period_type']], 'create: equipe e nome do cadastro, aprovada, autoria, observacao');
$audit = end($pdo->audits);
assert_same(['ABSENCE_CREATED', 'INFO'], [$audit['action'], $audit['severity']], 'create: auditada INFO');
assert_same([true, false], [strpos($audit['details'], 'ausencia_id=100; employee_id=2; periodo=de 21/10/2026 a 22/10/2026 (dia todo); categoria=ferias; retroativa=nao') !== false, strpos($audit['details'], 'texto sensivel') !== false], 'create: auditoria com periodo e categoria, sem a observacao (decisao 4)');
assert_same(['BEGIN', 'COMMIT'], [array_values(array_filter(array_column($pdo->queries, 0), static fn (string $sql): bool => in_array($sql, ['BEGIN', 'COMMIT', 'ROLLBACK'], true)))[0], end($pdo->queries)[0]], 'create: em transacao');
$lockAt = array_search('SELECT id, nome, equipe, access_role, jornada_entrada, jornada_saida, ativo FROM funcionarios WHERE id = :id FOR UPDATE', array_column($pdo->queries, 0), true);
$conflictAt = array_search(true, array_map(static fn (string $sql): bool => strpos($sql, 'SELECT id, starts_on, ends_on, period_type, start_time, end_time, status FROM portal_absences WHERE employee_id') === 0, array_column($pdo->queries, 0)), true);
assert_same(true, $lockAt !== false && $lockAt < $conflictAt && substr($pdo->queries[$conflictAt][0], -10) === 'FOR UPDATE', 'create: trava o colaborador antes de ler as ausencias, com FOR UPDATE');

// Retroativo (D3, L6): gestor recusado antes do banco; Lideranca e admin gravam marcado.
$past = ['starts_on' => '2026-10-02', 'ends_on' => '2026-10-02'] + $create;
$pdo = qa_db();
assert_same('403: Somente administradores e Liderança lançam, editam ou cancelam ausência com início anterior a hoje ou já iniciada.', qa_outcome(fn () => qa_service($pdo)->create($past, $actor('gestor'))), 'create: retroativa do gestor 403');
assert_same([], $pdo->queries, 'create: retroativa do gestor recusada sem tocar no banco');
foreach (['lideranca', 'admin'] as $role) {
    $pdo = qa_db();
    $result = qa_service($pdo)->create($past, $actor($role));
    assert_same([true, 1, 'WARNING'], [$result['retroactive'], $pdo->absences[$result['id']]['retroactive'], end($pdo->audits)['severity']], "create: retroativa pela {$role}, marcada e auditada WARNING");
}
assert_same(false, qa_service(qa_db())->create(['starts_on' => QA_TODAY, 'ends_on' => QA_TODAY] + $create, $actor('gestor'))['retroactive'] ?? true, 'create: comecando hoje nao e retroativa');

// Lideranca e perfis Lideranca/admin: so admin (A4).
foreach ([[3, 'lideranca'], [3, 'gestor'], [6, 'gestor'], [6, 'lideranca']] as [$employeeId, $role]) {
    $pdo = qa_db();
    assert_same('403: Somente administradores lançam ausência da Liderança.', qa_outcome(fn () => qa_service($pdo)->create(['employee_id' => $employeeId] + $create, $actor($role))), "A4: {$role} e cadastro {$employeeId} recebe 403");
    assert_same([], $writes($pdo), "A4: {$role} e cadastro {$employeeId} nada gravado");
}
assert_same('ok', qa_outcome(fn () => qa_service(qa_db())->create(['employee_id' => 3] + $create, $actor('admin'))), 'A4: admin lanca ausencia de lider');

// Cadastro.
foreach ([[5, '400: Colaborador nao encontrado ou inativo.'], [99, '400: Colaborador nao encontrado ou inativo.'], [7, '400: A equipe do cadastro nao recebe ausencia.']] as [$employeeId, $expected]) {
    assert_same($expected, qa_outcome(fn () => qa_service(qa_db())->create(['employee_id' => $employeeId] + $create, $actor('admin'))), "create: cadastro {$employeeId}");
}
assert_same('400: A jornada do cadastro e invalida: lance a ausencia como dia inteiro ou corrija a jornada.', qa_outcome(fn () => qa_service(qa_db())->create(['employee_id' => 8, 'ends_on' => '2026-10-21', 'period_type' => 'from_time', 'start_time' => '10:00'] + $create, $actor('gestor'))), 'create: cadastro sem jornada recusa horario');
assert_same('400: O horario deve ficar dentro da jornada do colaborador (09:00–18:00).', qa_outcome(fn () => qa_service(qa_db())->create(['ends_on' => '2026-10-21', 'period_type' => 'interval', 'start_time' => '08:00', 'end_time' => '10:00'] + $create, $actor('gestor'))), 'create: horario fora da jornada (P1)');

// Sobreposicao (P6).
$pdo = qa_db();
$service = qa_service($pdo);
assert_same('409: Ja existe ausencia aprovada do colaborador de 05/10/2026 a 09/10/2026 (dia todo).', qa_outcome(fn () => $service->create(['employee_id' => 1, 'starts_on' => '2026-10-09', 'ends_on' => '2026-10-12', 'category' => 'folga'], $actor('lideranca'))), 'P6: cruza ferias aprovada');
assert_same([[], 'ROLLBACK'], [$writes($pdo), end($pdo->queries)[0]], 'P6: nada gravado, transacao desfeita');
assert_same('ok', qa_outcome(fn () => $service->create(['employee_id' => 3, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08', 'category' => 'folga'], $actor('admin'))), 'P6: ausencia cancelada nao conflita');
$day = ['employee_id' => 2, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21', 'category' => 'compromisso'];
assert_same('ok', qa_outcome(fn () => $service->create(['period_type' => 'interval', 'start_time' => '10:00', 'end_time' => '12:00'] + $day, $actor('gestor'))), 'P6: intervalo');
assert_same('ok', qa_outcome(fn () => $service->create(['period_type' => 'interval', 'start_time' => '13:00', 'end_time' => '14:00'] + $day, $actor('gestor'))), 'P6: outro intervalo no mesmo dia');
assert_same('409: Ja existe ausencia aprovada do colaborador em 21/10/2026, 10:00–12:00.', qa_outcome(fn () => $service->create(['period_type' => 'from_time', 'start_time' => '11:30'] + $day, $actor('gestor'))), 'P6: a partir de dentro do intervalo');
assert_same('ok', qa_outcome(fn () => $service->create(['period_type' => 'from_time', 'start_time' => '14:00'] + $day, $actor('gestor'))), 'P6: a partir de quando o intervalo termina');
assert_same('409: Ja existe ausencia aprovada do colaborador em 21/10/2026, 10:00–12:00.', qa_outcome(fn () => $service->create($day, $actor('gestor'))), 'P6: dia inteiro sobre parciais');

// Update.
$pdo = qa_db();
$service = qa_service($pdo);
$update = ['id' => 4, 'employee_id' => 1, 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-16', 'category' => 'compromisso', 'note' => 'texto sensivel Y'];
assert_same(['id' => 4, 'retroactive' => false], $service->update($update, $actor('gestor')), 'update: gestor altera ausencia futura');
assert_same(['2026-10-16', 'compromisso', 'qa.gestor', 'texto sensivel Y', 'qa'], [$pdo->absences[4]['ends_on'], $pdo->absences[4]['category'], $pdo->absences[4]['updated_by'], $pdo->absences[4]['reason'], $pdo->absences[4]['created_by']], 'update: campos e autoria');
$audit = end($pdo->audits);
assert_same(['ABSENCE_UPDATED', 'WARNING', true, false], [$audit['action'], $audit['severity'], strpos($audit['details'], 'antes=em 15/10/2026 (dia todo) folga') !== false, strpos($audit['details'], 'texto sensivel') !== false], 'update: auditada com o antes, sem a observacao');
assert_same('ok', qa_outcome(fn () => $service->update($update, $actor('gestor'))), 'update: a propria ausencia nao conflita');
assert_same('400: Nao e possivel trocar o colaborador da ausencia: cancele e lance de novo.', qa_outcome(fn () => $service->update(['employee_id' => 2] + $update, $actor('gestor'))), 'update: nao troca colaborador');
assert_same('409: Ausencia nao encontrada.', qa_outcome(fn () => $service->update(['id' => 999] + $update, $actor('gestor'))), 'update: inexistente 409');
assert_same('409: Ausencia cancelada nao pode ser alterada.', qa_outcome(fn () => $service->update(['id' => 3, 'employee_id' => 3, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'category' => 'folga'], $actor('admin'))), 'update: cancelada 409');
assert_same('409: Ja existe ausencia aprovada do colaborador de 05/10/2026 a 09/10/2026 (dia todo).', qa_outcome(fn () => $service->update(['starts_on' => '2026-10-09'] + $update, $actor('lideranca'))), 'update: sobreposicao com outra');
$started = ['id' => 1, 'employee_id' => 1, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-08', 'category' => 'ferias'];
assert_same('403: Somente administradores e Liderança lançam, editam ou cancelam ausência com início anterior a hoje ou já iniciada.', qa_outcome(fn () => $service->update(['starts_on' => '2026-10-07'] + $started, $actor('gestor'))), 'update: ausencia ja iniciada, gestor 403 (mesmo movendo o inicio para hoje)');
assert_same(['id' => 1, 'retroactive' => true], $service->update($started, $actor('lideranca')), 'update: Lideranca edita ausencia ja iniciada');
assert_same(1, $pdo->absences[1]['retroactive'], 'update: marcada retroativa');
assert_same('403: Somente administradores lançam ausência da Liderança.', qa_outcome(fn () => qa_service(qa_db())->update(['id' => 3, 'employee_id' => 3, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'category' => 'folga'], $actor('lideranca'))), 'update: A4 vale na edicao (antes de olhar o status)');

// Cancel.
$pdo = qa_db();
$service = qa_service($pdo);
assert_same(['id' => 4, 'retroactive' => false], $service->cancel(['id' => 4], $actor('gestor')), 'cancel: gestor cancela ausencia futura');
assert_same(['cancelled', 'qa.gestor', '2026-10-07 10:30:00', 'folga'], [$pdo->absences[4]['status'], $pdo->absences[4]['cancelled_by'], $pdo->absences[4]['cancelled_at'], $pdo->absences[4]['category']], 'cancel: registro fica, com autoria e hora do PHP');
assert_same(['ABSENCE_CANCELLED', 'WARNING'], [end($pdo->audits)['action'], end($pdo->audits)['severity']], 'cancel: auditada');
assert_same('409: Ausencia ja cancelada.', qa_outcome(fn () => $service->cancel(['id' => 4], $actor('gestor'))), 'cancel: de novo 409');
assert_same('403: Somente administradores e Liderança lançam, editam ou cancelam ausência com início anterior a hoje ou já iniciada.', qa_outcome(fn () => $service->cancel(['id' => 1], $actor('gestor'))), 'cancel: ja iniciada, gestor 403');
assert_same('approved', $pdo->absences[1]['status'], 'cancel: recusa nao altera');
assert_same(['id' => 1, 'retroactive' => true], $service->cancel(['id' => 1], $actor('admin')), 'cancel: admin cancela ja iniciada');
// Ausencia que comeca hoje ja esta iniciada (D3): cancelar ou editar e retroativo.
$todayId = $service->create(['employee_id' => 2, 'starts_on' => QA_TODAY, 'ends_on' => QA_TODAY, 'category' => 'compromisso'], $actor('gestor'))['id'];
assert_same('403: Somente administradores e Liderança lançam, editam ou cancelam ausência com início anterior a hoje ou já iniciada.', qa_outcome(fn () => $service->cancel(['id' => $todayId], $actor('gestor'))), 'cancel: comecando hoje, gestor 403');
assert_same(['id' => $todayId, 'retroactive' => true], $service->cancel(['id' => $todayId], $actor('lideranca')), 'cancel: comecando hoje, Lideranca cancela (retroativa)');
assert_same(['400: Identificador invalido.', '409: Ausencia nao encontrada.'], [qa_outcome(fn () => $service->cancel(['id' => 'x'], $actor('admin'))), qa_outcome(fn () => $service->cancel(['id' => 999], $actor('admin')))], 'cancel: id invalido e inexistente');

// Concorrencia (licao do F1): outra gravacao aprova uma ausencia que cruza a
// edicao enquanto esta espera a trava do colaborador. A primeira leitura da
// edicao (simples) fixou o snapshot; sem FOR UPDATE na leitura das
// ausencias, a sobreposicao seria gravada.
$pdo = qa_db();
$pdo->whileWaitingLock = static function (QaAbsencesPdo $db): void {
    $db->absences[900] = qa_absence(900, 1, 'outro', '2026-10-16', '2026-10-16');
};
assert_same('409: Ja existe ausencia aprovada do colaborador em 16/10/2026 (dia todo).', qa_outcome(fn () => qa_service($pdo)->update($update, $actor('gestor'))), 'concorrencia: a gravacao que esperou a trava ve a ausencia nova');
assert_same(['2026-10-15', 'folga', true], [$pdo->absences[4]['ends_on'], $pdo->absences[4]['category'], isset($pdo->absences[900])], 'concorrencia: nada alterado; a gravacao da outra transacao fica');
$pdo = qa_db();
$pdo->whileWaitingLock = static function (QaAbsencesPdo $db): void {
    $db->absences[4]['status'] = 'cancelled';
};
assert_same('409: Ausencia cancelada nao pode ser alterada.', qa_outcome(fn () => qa_service($pdo)->update($update, $actor('gestor'))), 'concorrencia: cancelada por outra enquanto esperava a trava');

// ----------------------------------------------------------------------------
// 4. Bloco de ausencias do Calendario (L-01, decisao 5)
// ----------------------------------------------------------------------------
$calendar = static function (array $actor, array $filters = []): array {
    qa_db();
    $service = new OperationalService($GLOBALS['qa_pdo'], '2026-10-07');
    $result = $service->listCalendar($filters + ['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'absence'], $actor);
    return array_column($result['items'], 'description', 'id');
};
// A ausencia 3 esta cancelada: fora do calendario (decisao 5).
$labels = ['absence-1' => 'Férias', 'absence-2' => 'Ausência justificada', 'absence-4' => 'Folga', 'absence-5' => 'Outro'];
foreach (['gestor', 'lideranca', 'admin'] as $role) {
    assert_same($labels, $calendar(['role' => $role, 'employee_id' => null]), "calendario: {$role} ve o tipo de todas, sem o motivo");
}
foreach (['tecnico', 'somente_leitura'] as $role) {
    assert_same(['absence-1' => 'Férias', 'absence-2' => '', 'absence-4' => 'Folga', 'absence-5' => ''], $calendar(['role' => $role, 'employee_id' => 1]), "calendario: {$role} ve quem esta ausente e o periodo; o tipo so das proprias (P9)");
}
assert_same(['absence-1' => '', 'absence-2' => '', 'absence-4' => '', 'absence-5' => ''], $calendar([]), 'calendario: sem ator, nenhum tipo (padrao restrito)');
assert_same(['absence-1' => '', 'absence-2' => '', 'absence-4' => '', 'absence-5' => ''], $calendar(['role' => 'tecnico', 'employee_id' => 0]), 'calendario: tecnico sem colaborador na sessao nao ve tipo (nem de linha sem cadastro)');
$pdo = qa_db();
$service = new OperationalService($pdo, '2026-10-07');
$full = $service->listCalendar(['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'absence'], ['role' => 'admin', 'employee_id' => null]);
$encoded = (string)json_encode($full, JSON_UNESCAPED_UNICODE);
assert_same(false, strpos($encoded, 'texto livre') !== false, 'calendario: o motivo nunca sai, nem para o admin (decisao 4)');
assert_same(false, array_key_exists('category', $full['items'][0]), 'calendario: coluna crua da categoria fora da resposta');
assert_same(['Folga', 'Férias', 'Ausência justificada', 'Compromisso', 'Outro', 'Ausência'], array_map([OperationalService::class, 'absenceCategoryLabel'], ['folga', 'ferias', 'ausencia_justificada', 'compromisso', 'outro', 'desconhecida']), 'calendario: rotulos das cinco categorias (D4)');
assert_same(['Tecnico A', '2026-10-05 00:00:00', '2026-10-09 23:59:59', 'approved'], [$full['items'][0]['title'], $full['items'][0]['starts_at'], $full['items'][0]['ends_at'], $full['items'][0]['status']], 'calendario: nome, periodo e status como antes');
assert_same(false, strpos($pdo->queries[0][0], 'reason') !== false, 'calendario: o motivo nem e lido do banco');
// [Decisao 5] So ausencia aprovada: o filtro esta no SQL; com status de outro
// valor, a consulta de ausencias nem roda.
assert_same(true, strpos($pdo->queries[0][0], 'AND status = "approved"') !== false, 'calendario: so aprovada, no SQL');
assert_same($labels, $calendar(['role' => 'gestor'], ['status' => 'approved']), 'calendario: filtro approved igual ao padrao');
foreach (['pending', 'rejected', 'cancelled'] as $otherStatus) {
    $pdo = qa_db();
    $result = (new OperationalService($pdo, '2026-10-07'))->listCalendar(['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'absence', 'status' => $otherStatus], ['role' => 'admin']);
    assert_same([[], []], [$result['items'], $pdo->queries], "calendario: filtro {$otherStatus} nao traz ausencia nem consulta");
}

// ----------------------------------------------------------------------------
// 5. Endpoints (estrutura; o comportamento de ponta a ponta esta no qa-security)
// ----------------------------------------------------------------------------
$absencesEndpoint = (string)file_get_contents($root . '/api/portal/absences.php');
assert_same(true, strpos($absencesEndpoint, '(new AbsenceService())->list($_GET, $actor)') !== false && strpos($absencesEndpoint, 'portal_list_response(') === false, 'absences.php usa a lista com escopo');
$permissionAt = strpos($absencesEndpoint, "if (!portal_role_can(\$role, 'ausencias.manage')) {");
assert_same(true, $permissionAt !== false && $permissionAt < strpos($absencesEndpoint, '$data = portal_json_input();') && $permissionAt < strpos($absencesEndpoint, '$service = new AbsenceService();'), 'absences.php nega escrita por perfil antes de ler o corpo e de abrir o banco');
assert_same(true, strpos($absencesEndpoint, "audit_log('ABSENCE_WRITE_DENIED'") !== false && substr_count($absencesEndpoint, 'ABSENCE_WRITE_DENIED') === 2, 'absences.php audita a recusa de perfil e a do servico');
foreach (["\$action === 'create'" => "\$service->create(\$data, \$actor)", "\$action === 'update'" => "\$service->update(\$data, \$actor)", "\$action === 'cancel'" => "\$service->cancel(\$data, \$actor)"] as $branch => $call) {
    assert_same(true, strpos($absencesEndpoint, $branch) !== false && strpos($absencesEndpoint, $call) !== false, "absences.php: {$branch}");
}
assert_same(true, strpos($absencesEndpoint, "'Ausência lançada.'] + \$result, 201);") !== false, 'absences.php: create devolve 201');
assert_same(true, strpos((string)file_get_contents($root . '/services/PortalService.php'), 'portal_absences') === false, 'PortalService sem a lista generica de ausencias');
assert_same(true, strpos((string)file_get_contents($root . '/api/portal/calendar.php'), '$service->listCalendar($_GET, portal_actor($role))') !== false, 'calendar.php passa o ator');

// ----------------------------------------------------------------------------
// 6. Migration 012, rollback, verificacao e ensaio (estrutura; o SQL so roda
//    no servidor: ensaio e teste do responsavel)
// ----------------------------------------------------------------------------
$sqlFile = static fn (string $path): string => (string)preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($root . '/' . $path));
$migration = $sqlFile('migrations/20261007_012_absences.sql');
$rollback = $sqlFile('migrations/rollback/20261007_012_absences_down.sql');
$check = $sqlFile('migrations/verificacao/20261007_012_absences_check.sql');
$oldRow = $sqlFile('migrations/rollback/20261007_012_ensaio_linha_antiga.sql');
$f3Rows = $sqlFile('migrations/rollback/20261007_012_ensaio_linhas_f3.sql');
$at = static fn (string $sql, string $needle): int => ($found = strpos($sql, $needle)) === false ? -1 : $found;

foreach (['012' => $migration, 'rollback 012' => $rollback, 'verificacao 012' => $check, 'ensaio linha antiga' => $oldRow, 'ensaio linhas F3' => $f3Rows] as $label => $sql) {
    assert_same(true, $sql !== '', "{$label}: arquivo lido");
    assert_same(0, preg_match('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|SYSDATE|UTC_TIMESTAMP)\b/i', $sql), "{$label}: sem relogio do banco (regra do A4)");
    assert_same(0, preg_match('/\b(employee_id|id)\s*(=|IN)\s*\(?\s*\d/i', $sql), "{$label}: sem id fixo de colaborador");
}
foreach (['012' => $migration, 'rollback 012' => $rollback, 'ensaio linha antiga' => $oldRow, 'ensaio linhas F3' => $f3Rows] as $label => $sql) {
    assert_same(1, preg_match("/^\\s*SET time_zone = 'America\\/Sao_Paulo';/", $sql), "{$label}: comeca com SET time_zone (regra do A4)");
}
foreach (['012' => $migration, 'rollback 012' => $rollback] as $label => $sql) {
    assert_same(0, preg_match('/^\s*ALTER TABLE/mi', $sql), "{$label}: todo ALTER dentro de IF com checagem em information_schema (idempotente)");
    $prepares = preg_match_all('/^PREPARE /m', $sql);
    assert_same(true, $prepares > 0, "{$label}: usa PREPARE");
    assert_same([$prepares, $prepares], [preg_match_all('/^EXECUTE /m', $sql), preg_match_all('/^DEALLOCATE PREPARE /m', $sql)], "{$label}: todo PREPARE tem EXECUTE e DEALLOCATE");
    assert_same(1, preg_match("/SET MESSAGE_TEXT = '([^']+)'/", $sql, $message), "{$label}: mensagem da parada");
    assert_same(true, strlen($message[1]) <= 128 && strpos($message[1], 'Nada foi alterado') !== false, "{$label}: mensagem clara e dentro do limite de 128 do SIGNAL");
    // Todo PREPARE le uma variavel atribuida antes no arquivo (variavel sem SET vira NULL e o PREPARE falha no meio).
    preg_match_all('/^PREPARE (\w+) FROM @(\w+);/m', $sql, $prepared, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($prepared as $statement) {
        $variable = $statement[2][0];
        $assigned = $at($sql, "SET @{$variable} := ");
        assert_same(true, $assigned > -1 && $assigned < $statement[0][1], "{$label}: @{$variable} atribuida antes do PREPARE");
    }
}

// Migration: parada antes de qualquer alteracao, so com absence_type e linha.
$guardCall = $at($migration, 'CALL migration_012_confere_vazia();');
assert_same(true, $guardCall > -1 && $guardCall < $at($migration, 'PREPARE '), '012: checagem da tabela vazia antes de qualquer alteracao');
assert_same(1, preg_match("/IF \\(SELECT COUNT\\(\\*\\) FROM information_schema\\.COLUMNS\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type'\\) > 0\\s+AND \\(SELECT COUNT\\(\\*\\) FROM portal_absences\\) > 0 THEN\\s+SIGNAL SQLSTATE '45000'/", $migration), '012: para se a coluna antiga existe e ha linha (repetir depois de aplicada nao para)');
assert_same(true, $at($migration, 'DROP PROCEDURE IF EXISTS migration_012_confere_vazia;') < $at($migration, 'CREATE PROCEDURE migration_012_confere_vazia()'), '012: procedimento recriado a cada execucao');
assert_same(0, preg_match('/^\s*(INSERT|UPDATE|DELETE|TRUNCATE|REPLACE)\b/mi', $migration), '012 nao altera linha de dado');
assert_same(true, $at($migration, "ADD COLUMN team ENUM(''n1'', ''n2'', ''lideranca'') NOT NULL") > -1, '012: team com n1, n2 e lideranca, obrigatorio (decisao 8)');
assert_same(true, $at($migration, "ADD COLUMN category ENUM(''folga'', ''ferias'', ''ausencia_justificada'', ''compromisso'', ''outro'') NOT NULL") > -1, '012: cinco categorias, obrigatoria (P8, D4)');
assert_same(true, $at($migration, "ADD COLUMN period_type ENUM(''full_day'', ''from_time'', ''interval'') NOT NULL DEFAULT ''full_day''") > -1, '012: tipo de periodo');
assert_same(true, $at($migration, 'ADD COLUMN created_by VARCHAR(100) NOT NULL') > -1, '012: autoria obrigatoria');
assert_same(true, $at($migration, "'ALTER TABLE portal_absences DROP COLUMN absence_type'") > -1, '012: absence_type sai (P8)');
assert_same(true, $at($migration, "MODIFY COLUMN status ENUM(''approved'', ''cancelled'') NOT NULL DEFAULT ''approved''") > -1, '012: status so aprovado e cancelado (P8)');
assert_same(true, $at($migration, 'ADD INDEX idx_absence_employee_range (employee_id, status, starts_on, ends_on)') > -1, '012: indice do periodo');
assert_same(true, $at($migration, 'FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT') > -1, '012: FK para funcionarios, RESTRICT');
assert_same(true, $at($migration, 'ADD INDEX idx_absence_employee_range') < $at($migration, 'ADD CONSTRAINT fk_absence_employee'), '012: indice antes da FK');
assert_same(true, $at($migration, 'CHECK (ends_on >= starts_on)') > -1 && $at($migration, 'ADD CONSTRAINT ck_absence_period CHECK') > -1, '012: CHECKs do periodo');
assert_same(0, preg_match('/legacy_exception_id|\bsource\b|atestado/', $migration), '012: sem as colunas da 013 e sem o valor atestado');

// Rollback: parada, copia antes de alterar, mapeamento e ordem do ENUM.
$abortAt = $at($rollback, 'CALL rollback_012_confere_copia();');
$firstChange = min(array_filter([$at($rollback, 'CREATE TABLE portal_absences_bkp_012'), $at($rollback, 'PREPARE '), $at($rollback, 'UPDATE ')], static fn (int $pos): bool => $pos > -1));
assert_same(true, $abortAt > -1 && $abortAt < $firstChange, 'rollback 012: checagem da copia antes de qualquer alteracao');
assert_same(true, $at($rollback, 'CREATE TABLE portal_absences_bkp_012 AS SELECT * FROM portal_absences;') < $at($rollback, 'PREPARE '), 'rollback 012: copia antes de alterar');
assert_same(0, preg_match('/CREATE TABLE IF NOT EXISTS/', $rollback), 'rollback 012: copia nunca e pulada em silencio');
assert_same(1, preg_match("/SET absence_type = CASE category\\s+WHEN ''folga'' THEN ''folga''\\s+WHEN ''ferias'' THEN ''ferias''\\s+ELSE ''justificada''\\s+END/", $rollback), 'rollback 012: absence_type a partir de category, sem recriar atestado');
assert_same(true, $at($rollback, 'SET absence_type = CASE category') < $at($rollback, "MODIFY COLUMN absence_type ENUM(''ferias'', ''atestado'', ''treinamento'', ''folga'', ''justificada'') NOT NULL"), 'rollback 012: NOT NULL so depois de preencher');
$widen = $at($rollback, "MODIFY COLUMN status ENUM(''pending'', ''approved'', ''rejected'', ''cancelled'')");
$toRejected = $at($rollback, "UPDATE portal_absences SET status = ''rejected'' WHERE status = ''cancelled''");
$shrink = $at($rollback, "MODIFY COLUMN status ENUM(''pending'', ''approved'', ''rejected'') NOT NULL DEFAULT ''pending''");
assert_same(true, $widen > -1 && $widen < $toRejected && $toRejected < $shrink, 'rollback 012: amplia o ENUM, cancelled vira rejected, depois encolhe');
assert_same(true, $at($rollback, "COLUMN_NAME IN ('team', 'category', 'period_type', 'start_time', 'end_time', 'retroactive',\n                          'created_by', 'updated_by', 'cancelled_by', 'cancelled_at')") > -1, 'rollback 012: as dez colunas novas saem');
assert_same(true, $at($rollback, 'DROP FOREIGN KEY fk_absence_employee') > -1 && $at($rollback, 'DROP CHECK ck_absence_period') > -1 && $at($rollback, 'DROP CHECK ck_absence_range') > -1 && $at($rollback, 'DROP INDEX idx_absence_employee_range') > -1, 'rollback 012: FK, CHECKs e indice saem');

// Verificacao: so le, so contagens, sem nome nem observacao.
assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE)\b/i', $check), 'verificacao 012 so le');
assert_same(0, preg_match('/\b(employee_name|nome|ad_login|reason|note)\b/i', $check), 'verificacao 012 sem nome, login nem observacao');
foreach (['colunas_012', 'absence_type', 'status_tipo', 'fk', 'indice', 'checks', 'copia_rollback', 'orfas', 'periodo_invalido', 'sobreposicoes_aprovadas'] as $column) {
    assert_same(true, $at($check, "AS {$column}") > -1, "verificacao 012: {$column}");
}

// Ensaio: so no banco descartavel; sem nome real nem texto livre.
foreach (['linha antiga' => $oldRow, 'linhas F3' => $f3Rows] as $label => $sql) {
    assert_same(true, $at($sql, "WHERE DATABASE() LIKE 'ensaio\\_%'") > -1, "ensaio {$label}: so grava em banco ensaio_*");
    assert_same(1, preg_match_all('/\bINSERT INTO portal_absences\b/', $sql), "ensaio {$label}: um INSERT so em portal_absences");
    assert_same(0, preg_match('/\b(UPDATE|DELETE|ALTER|DROP)\b/i', $sql), "ensaio {$label}: so insere");
    assert_same(0, preg_match('/\breason\b/', $sql), "ensaio {$label}: sem texto de observacao");
}
assert_same(true, $at($f3Rows, "AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM portal_absences WHERE created_by = 'qa.ensaio') AS ja)") > -1, 'ensaio linhas F3: idempotente');

echo "QA absences OK\n";
