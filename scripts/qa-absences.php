<?php
declare(strict_types=1);

// ============================================================================
// QA de ausencias (Parte B, F3)
//
// Commit 1 (SEC-07, L-01): escopo de leitura (desde a 012, com as colunas novas).
// - PortalService::absences: gestao ve todas; tecnico e somente leitura, so
//   as proprias, pelo employee_id da sessao; sem colaborador na sessao, lista
//   vazia sem consultar o banco.
// - Bloco de ausencias do Calendario: o motivo nunca sai; todos veem quem
//   esta ausente e o periodo (P9); o tipo, so a gestao e o proprio; o tipo
//   "atestado" sai como "Ausencia justificada".
// Sem banco: um PDO simulado emula as duas consultas e guarda o SQL e os
// parametros. Secao 4: estrutura da migration 012, do rollback, da
// verificacao e dos arquivos de ensaio. Os commits seguintes ampliam este QA.
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

/** Ausencias ficticias (nenhum dado real), na estrutura da migration 012. */
const QA_ABSENCES = [
    ['id' => 1, 'employee_id' => 1, 'employee_name' => 'Tecnico A', 'category' => 'ferias', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09', 'reason' => 'texto livre A', 'status' => 'approved'],
    ['id' => 2, 'employee_id' => 2, 'employee_name' => 'Tecnico B', 'category' => 'ausencia_justificada', 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-06', 'reason' => 'texto livre B', 'status' => 'approved'],
    ['id' => 3, 'employee_id' => 3, 'employee_name' => 'Tecnico C', 'category' => 'compromisso', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08', 'reason' => 'texto livre C', 'status' => 'cancelled'],
    ['id' => 4, 'employee_id' => 1, 'employee_name' => 'Tecnico A', 'category' => 'folga', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15', 'reason' => null, 'status' => 'approved'],
    // Linha sem colaborador valido (o banco simulado nao tem a FK da 012).
    ['id' => 5, 'employee_id' => 0, 'employee_name' => 'Sem cadastro', 'category' => 'outro', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'reason' => 'texto livre E', 'status' => 'approved'],
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
        if (strpos($this->sql, 'SELECT id, employee_id, employee_name, team, category, starts_on, ends_on, period_type, start_time, end_time, reason, status, retroactive, created_by, updated_by, cancelled_by, cancelled_at, created_at, updated_at FROM portal_absences') === 0) {
            $own = strpos($this->sql, ' WHERE employee_id = :employee_id ') !== false ? (int)$params[':employee_id'] : null;
            foreach (QA_ABSENCES as $row) {
                if ($own === null || $row['employee_id'] === $own) {
                    $this->rows[] = $row + ['team' => 'n1', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'retroactive' => 0, 'created_by' => 'qa', 'updated_by' => null, 'cancelled_by' => null, 'cancelled_at' => null, 'created_at' => null, 'updated_at' => null];
                }
            }
            return true;
        }
        // Bloco de ausencias do Calendario (listCalendar com type=absence).
        if (strpos($this->sql, 'SELECT id, employee_name AS title, category, CONCAT(starts_on, " 00:00:00") AS starts_at,') === 0) {
            foreach (QA_ABSENCES as $row) {
                $onlyApproved = strpos($this->sql, 'AND status = "approved"') !== false;
                if ($row['starts_on'] > $params[':date_to'] || $row['ends_on'] < $params[':date_from']
                    || (isset($params[':employee_id']) && $row['employee_id'] !== $params[':employee_id'])
                    || (isset($params[':status']) && $row['status'] !== $params[':status'])
                    || ($onlyApproved && $row['status'] !== 'approved')) {
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
// 3. Endpoints (estrutura; o comportamento de ponta a ponta esta no qa-security)
// ----------------------------------------------------------------------------
$absencesEndpoint = (string)file_get_contents($root . '/api/portal/absences.php');
assert_same(true, strpos($absencesEndpoint, '(new PortalService())->absences($actor)') !== false && strpos($absencesEndpoint, 'portal_list_response(') === false, 'absences.php usa a lista com escopo');
assert_same(true, strpos((string)file_get_contents($root . '/api/portal/calendar.php'), '$service->listCalendar($_GET, portal_actor($role))') !== false, 'calendar.php passa o ator');

// ----------------------------------------------------------------------------
// 4. Migration 012, rollback, verificacao e ensaio (estrutura; o SQL so roda
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
