<?php
declare(strict_types=1);

// ============================================================================
// QA de ausencias (Parte B, F3)
//
// Commit 1 (SEC-07, L-01): escopo de leitura com a tabela de hoje.
// - PortalService::absences: gestao ve todas; tecnico e somente leitura, so
//   as proprias, pelo employee_id da sessao; sem colaborador na sessao, lista
//   vazia sem consultar o banco.
// - Bloco de ausencias do Calendario: o motivo nunca sai; todos veem quem
//   esta ausente e o periodo (P9); o tipo, so a gestao e o proprio; o tipo
//   "atestado" sai como "Ausencia justificada".
// Sem banco: um PDO simulado emula as duas consultas e guarda o SQL e os
// parametros. Os commits seguintes do F3 ampliam este QA.
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
require_once __DIR__ . '/../services/PortalService.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

/** Ausencias ficticias (nenhum dado real), na estrutura da tabela de hoje. */
const QA_ABSENCES = [
    ['id' => 1, 'employee_id' => 1, 'employee_name' => 'Tecnico A', 'absence_type' => 'ferias', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09', 'reason' => 'texto livre A', 'status' => 'approved'],
    ['id' => 2, 'employee_id' => 2, 'employee_name' => 'Tecnico B', 'absence_type' => 'atestado', 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-06', 'reason' => 'texto livre B', 'status' => 'approved'],
    ['id' => 3, 'employee_id' => 3, 'employee_name' => 'Tecnico C', 'absence_type' => 'justificada', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08', 'reason' => 'texto livre C', 'status' => 'pending'],
    ['id' => 4, 'employee_id' => 1, 'employee_name' => 'Tecnico A', 'absence_type' => 'folga', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15', 'reason' => null, 'status' => 'approved'],
    // Linha sem colaborador valido (so possivel por SQL direto antes da FK da 012).
    ['id' => 5, 'employee_id' => 0, 'employee_name' => 'Sem cadastro', 'absence_type' => 'ferias', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'reason' => 'texto livre E', 'status' => 'approved'],
];

final class QaAbsencesPdo extends PDO {
    public array $queries = [];

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaAbsencesStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $query);
    }
}

final class QaAbsencesStatement extends PDOStatement {
    private array $rows = [];
    private array $bound = [];

    public function __construct(private QaAbsencesPdo $pdo, private string $sql) {
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->bound[$param] = $value;
        return true;
    }

    public function execute(?array $params = null): bool {
        $params = ($params ?? []) + $this->bound;
        $this->pdo->queries[] = [$this->sql, $params];
        $this->rows = [];
        // Lista de ausencias (PortalService::absences).
        if (strpos($this->sql, 'SELECT id, employee_id, employee_name, absence_type, starts_on, ends_on, reason, status, approved_by, created_at, updated_at FROM portal_absences') === 0) {
            $own = strpos($this->sql, ' WHERE employee_id = :employee_id ') !== false ? (int)$params[':employee_id'] : null;
            foreach (QA_ABSENCES as $row) {
                if ($own === null || $row['employee_id'] === $own) {
                    $this->rows[] = $row + ['approved_by' => null, 'created_at' => null, 'updated_at' => null];
                }
            }
            return true;
        }
        // Bloco de ausencias do Calendario (listCalendar com type=absence).
        if (strpos($this->sql, 'SELECT id, employee_name AS title, absence_type, CONCAT(starts_on, " 00:00:00") AS starts_at,') === 0) {
            foreach (QA_ABSENCES as $row) {
                $onlyApproved = strpos($this->sql, 'AND status = "approved"') !== false;
                if ($row['starts_on'] > $params[':date_to'] || $row['ends_on'] < $params[':date_from']
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])
                    || (isset($params[':status']) && $row['status'] !== $params[':status'])
                    || ($onlyApproved && $row['status'] !== 'approved')) {
                    continue;
                }
                $this->rows[] = [
                    'id' => $row['id'], 'title' => $row['employee_name'], 'absence_type' => $row['absence_type'],
                    'starts_at' => $row['starts_on'] . ' 00:00:00', 'ends_at' => $row['ends_on'] . ' 23:59:59',
                    'team' => null, 'employee_id' => $row['employee_id'], 'status' => $row['status'],
                ];
            }
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $this->sql);
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

$root = dirname(__DIR__);
$ids = static fn (array $result): array => array_column($result['items'], 'id');

// ----------------------------------------------------------------------------
// 1. Lista de ausencias por perfil (SEC-07)
// ----------------------------------------------------------------------------
foreach (['gestor', 'lideranca', 'admin'] as $role) {
    $pdo = qa_db();
    $result = (new PortalService())->absences(['role' => $role, 'employee_id' => null]);
    assert_same([1, 2, 3, 4, 5], $ids($result), "lista: {$role} ve todas");
    assert_same(false, strpos($pdo->queries[0][0], 'WHERE') !== false, "lista: {$role} sem filtro de colaborador");
}
foreach (['tecnico', 'somente_leitura'] as $role) {
    $pdo = qa_db();
    $result = (new PortalService())->absences(['role' => $role, 'employee_id' => 1]);
    assert_same([1, 4], $ids($result), "lista: {$role} ve so as proprias");
    assert_same(1, $pdo->queries[0][1][':employee_id'], "lista: {$role} filtrado pelo colaborador da sessao");
    assert_same('texto livre A', $result['items'][0]['reason'], "lista: {$role} ve a observacao da propria (decisao 4)");
    $pdo = qa_db();
    $result = (new PortalService())->absences(['role' => $role, 'employee_id' => 0]);
    assert_same([[], []], [$result['items'], $pdo->queries], "lista: {$role} sem colaborador na sessao, vazia e sem consultar o banco");
}
$pdo = qa_db();
$result = (new PortalService())->absences([]);
assert_same([[], []], [$result['items'], $pdo->queries], 'lista: sem perfil, vazia (negar por padrao)');
$result = (new PortalService())->absences(['role' => 'gestor'], 2);
assert_same([[1, 2], true, 2], [$ids($result), $result['truncated'], $result['limit']], 'lista: limite com truncated');

// ----------------------------------------------------------------------------
// 2. Bloco de ausencias do Calendario (L-01)
// ----------------------------------------------------------------------------
$calendar = static function (array $actor, array $filters = []): array {
    qa_db();
    $service = new OperationalService($GLOBALS['qa_pdo'], '2026-10-07');
    $result = $service->listCalendar($filters + ['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'absence'], $actor);
    return array_column($result['items'], 'description', 'id');
};
// A ausencia 3 esta pendente: fora do calendario (decisao 5).
$labels = ['absence-1' => 'Férias', 'absence-2' => 'Ausência justificada', 'absence-4' => 'Folga', 'absence-5' => 'Férias'];
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
assert_same(false, stripos($encoded, 'atestado') !== false, 'calendario: o tipo atestado nao aparece (D4)');
assert_same(false, array_key_exists('absence_type', $full['items'][0]), 'calendario: coluna crua do tipo fora da resposta');
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
// 3. Endpoints (estrutura; o comportamento de ponta a ponta esta no qa-security)
// ----------------------------------------------------------------------------
$absencesEndpoint = (string)file_get_contents($root . '/api/portal/absences.php');
assert_same(true, strpos($absencesEndpoint, '(new PortalService())->absences($actor)') !== false && strpos($absencesEndpoint, 'portal_list_response(') === false, 'absences.php usa a lista com escopo');
assert_same(true, strpos((string)file_get_contents($root . '/api/portal/calendar.php'), '$service->listCalendar($_GET, portal_actor($role))') !== false, 'calendar.php passa o ator');

echo "QA absences OK\n";
