<?php
declare(strict_types=1);

// ============================================================================
// QA do Mapa PA no motor (Parte B, F6, commit 1; desenho, secao 9.8)
//
// Leitura do mapa (PaMapService::list) sobre o motor de presenca:
// 1. Conflito por PA e dia util da semana (seg a sex): dois ou mais com
//    situacao presencial. Pares x impares, dias fixos disjuntos (L-09),
//    remoto, sem escala, ausente o dia todo (F3 e excecao antiga), parcial,
//    Lideranca, arquivado ate a vespera, inativo antigo, vinculo que comeca
//    no meio da semana, tabela antiga por data, fim de semana fora (DF3),
//    todas as equipes (o PA e um so).
// 2. Presenca de cada vinculo pelo motor, nao pela copia (L-02): situacao,
//    origem, detalhe, parcial; motivo sem situacao (DF6); fim de semana.
// 3. P9 e DF7 por perfil: categoria so para a gestao e o proprio; observacao
//    nunca; elegiveis, login, observacao do vinculo e autores so para quem
//    edita o mapa. Ausencias da semana (DF9).
// 4. Numero de consultas fixo para N cadastros (L-10) e o mapa nao le a data
//    de corte (DF1).
//
// Nao acessa banco (usa um banco simulado) nem os arquivos de estado da aplicacao.
//
// Uso: php scripts/qa-pa-conflicts.php
// ============================================================================

putenv('APP_ENV=development');
putenv('APP_DEBUG=false');
putenv('SECRET_KEY=qa-only-secret-key');
putenv('MAIL_ENABLED=false');
$qaStateDir = sys_get_temp_dir() . '/chronodesk-qa-pa-' . bin2hex(random_bytes(6));
if (!mkdir($qaStateDir, 0700)) {
    fwrite(STDERR, "FALHOU: nao criou diretorio temporario do QA\n");
    exit(1);
}
register_shutdown_function(static function () use ($qaStateDir): void {
    foreach (glob($qaStateDir . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($qaStateDir);
});
putenv('PHP_ERROR_LOG=' . $qaStateDir . '/php-error.log');
define('ESTADO_JSON', $qaStateDir . '/estado.json');
define('PAUSAS_CSV', $qaStateDir . '/pausas.csv');
define('CONFIG_JSON', $qaStateDir . '/config_sistema.json');
define('FUNCIONARIOS_JSON', $qaStateDir . '/funcionarios.json');
define('CHRONODESK_STATELESS', true);

$root = dirname(__DIR__);
$checks = 0;

function assert_same($expected, $actual, string $message): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n  esperado: " . var_export($expected, true) . "\n  obtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

require_once $root . '/config.php';
require_once $root . '/services/PaMapService.php';

// ----------------------------------------------------------------------------
// Banco simulado: so as consultas do mapa e do motor; qualquer outra para.
// ----------------------------------------------------------------------------
final class QaPaPdo extends PDO {
    public array $employees = [];
    public array $rules = [];
    public array $exceptions = [];
    public array $absences = [];
    public array $assignments = [];
    public array $legacy = [];
    public array $tables = ['portal_pa_assignments' => 1, 'portal_pa_map' => 1];
    public array $log = [];

    public function __construct() {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QaPaStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $statement = new QaPaStatement($this, (string)preg_replace('/\s+/', ' ', trim($query)));
        $statement->execute();
        return $statement;
    }
}

final class QaPaStatement extends PDOStatement {
    private array $rows = [];

    public function __construct(private QaPaPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = $this->sql;
        $db = $this->pdo;
        $db->log[] = $sql . (isset($params[':table_name']) ? ' #' . $params[':table_name'] : '');
        $this->rows = [];
        if (strpos($sql, 'portal_presence_cutoff') !== false || ($params[':table_name'] ?? '') === 'portal_presence_cutoff') {
            throw new PDOException('SQLSTATE[HY000]: banco simulado: o Mapa PA nao le a data de corte (DF1)');
        }
        if ($sql === 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name') {
            $this->rows[] = ['total' => $db->tables[$params[':table_name']] ?? 0];
            return true;
        }
        if (strpos($sql, 'SELECT pa_number, display_order, grid_row, grid_column, label, status FROM portal_pa_inventory WHERE active = 1') === 0) {
            foreach (['1732', '1731', '1730', '1729', '1728', '1727', '1726', '1725', '1724', '1723', '1722'] as $index => $pa) {
                $this->rows[] = ['pa_number' => $pa, 'display_order' => $index + 1, 'grid_row' => 1, 'grid_column' => $index + 1, 'label' => null, 'status' => 'active'];
            }
            return true;
        }
        $visible = static fn (array $employee, bool $withArchived): bool => (int)$employee['ativo'] === 1 || ($withArchived && $employee['arquivado_em'] !== null);
        $activeOnly = static fn (string $table): bool => strpos($sql, " AND EXISTS (SELECT 1 FROM funcionarios f WHERE f.id = {$table}.employee_id AND f.ativo = 1) ORDER BY") !== false;
        // Motor: regras (com o arquivado), excecoes, ausencias com categoria e cadastros.
        $rulesPrefix = 'SELECT r.id, r.employee_id, f.nome AS employee_name, f.ad_login, LOWER(f.equipe) AS team, r.rule_type, r.rule_config, r.effective_from, r.effective_until, r.created_by, r.updated_by, r.created_at, r.updated_at FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND (f.ativo = 1 OR f.arquivado_em IS NOT NULL) AND f.fora_mapa_escala = 0 WHERE LOWER(f.equipe) IN ("n1", "n2")';
        if (strpos($sql, $rulesPrefix) === 0) {
            foreach ($db->rules as $id => $rule) {
                $employee = $db->employees[$rule['employee_id']];
                $team = strtolower($employee['equipe']);
                if (!$visible($employee, true) || !in_array($team, ['n1', 'n2'], true) || $employee['fora_mapa_escala'] === 1
                    || (isset($params[':period_from']) && $rule['effective_until'] !== null && $rule['effective_until'] < $params[':period_from'])) {
                    continue;
                }
                $this->rows[] = [
                    'id' => $id, 'employee_id' => $rule['employee_id'], 'employee_name' => $employee['nome'], 'ad_login' => 'qa.' . $rule['employee_id'],
                    'team' => $team, 'rule_type' => $rule['rule_type'], 'rule_config' => $rule['rule_config'] ?? null,
                    'effective_from' => $rule['effective_from'], 'effective_until' => $rule['effective_until'],
                    'created_by' => 'qa', 'updated_by' => 'qa', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
                ];
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, employee_id, employee_name, team, exception_date, exception_type, note, created_by, created_at, updated_at FROM portal_schedule_exceptions WHERE exception_date BETWEEN :date_from AND :date_to') === 0) {
            foreach ($db->exceptions as $id => $row) {
                if ($row['exception_date'] >= $params[':date_from'] && $row['exception_date'] <= $params[':date_to']) {
                    $this->rows[] = $row + ['id' => $id, 'employee_name' => $db->employees[$row['employee_id']]['nome'], 'team' => 'n1', 'note' => 'observacao ficticia', 'created_by' => 'qa', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT a.employee_id, a.starts_on, a.ends_on, a.period_type, a.start_time, a.end_time, a.category FROM portal_absences a JOIN funcionarios f ON f.id = a.employee_id AND (f.ativo = 1 OR f.arquivado_em IS NOT NULL) WHERE a.starts_on <= :date_to AND a.ends_on >= :date_from AND a.status = "approved"') === 0) {
            foreach ($db->absences as $row) {
                if ($visible($db->employees[$row['employee_id']], true) && $row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']) {
                    $this->rows[] = array_intersect_key($row, array_flip(['employee_id', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time', 'category']));
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, nome AS employee_name, equipe, arquivado_em FROM funcionarios WHERE (ativo = 1 OR arquivado_em IS NOT NULL) AND fora_mapa_escala = 0') === 0) {
            foreach ($db->employees as $id => $row) {
                if ($visible($row, true) && $row['fora_mapa_escala'] === 0) {
                    $this->rows[] = ['id' => $id, 'employee_name' => $row['nome'], 'equipe' => $row['equipe'], 'arquivado_em' => $row['arquivado_em']];
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['employee_name'], $a['id']] <=> [$b['employee_name'], $b['id']]);
            return true;
        }
        // Mapa: elegiveis, vinculos recorrentes e tabela antiga.
        if (strpos($sql, 'SELECT id, nome AS name, equipe AS team, ad_login FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0') === 0) {
            foreach ($db->employees as $id => $row) {
                if ((int)$row['ativo'] === 1 && $row['fora_mapa_escala'] === 0) {
                    $this->rows[] = ['id' => $id, 'name' => $row['nome'], 'team' => $row['equipe'], 'ad_login' => 'qa.login.' . $id];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, pa_number, employee_id, employee_name, employee_login, team, schedule_rule_type, schedule_rule_config, valid_from, valid_until, notes, created_by, updated_by, created_at, updated_at FROM portal_pa_assignments WHERE active = 1 AND valid_from <= :date_until AND (valid_until IS NULL OR valid_until >= :date_from)') === 0) {
            foreach ($db->assignments as $id => $row) {
                $employee = $db->employees[$row['employee_id']];
                if ($row['valid_from'] <= $params[':date_until'] && ($row['valid_until'] === null || $row['valid_until'] >= $params[':date_from'])
                    && (!isset($params[':team']) || $employee['equipe'] === $params[':team'])
                    && (!$activeOnly('portal_pa_assignments') || (int)$employee['ativo'] === 1)) {
                    $this->rows[] = [
                        'id' => $id, 'pa_number' => $row['pa_number'], 'employee_id' => $row['employee_id'], 'employee_name' => $employee['nome'],
                        'employee_login' => 'qa.login.' . $row['employee_id'], 'team' => $employee['equipe'],
                        // Copia de proposito diferente da regra vigente: o mapa nao pode le-la (L-02).
                        'schedule_rule_type' => 'always_remote', 'schedule_rule_config' => null,
                        'valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until'], 'notes' => 'nota do vinculo ' . $id,
                        'created_by' => 'qa.criou', 'updated_by' => 'qa.alterou', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
                    ];
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['pa_number'], $a['employee_name']] <=> [$b['pa_number'], $b['employee_name']]);
            return true;
        }
        if (strpos($sql, 'SELECT id, pa_number, employee_id, employee_name, employee_login, team, schedule_status, schedule_label, notes, created_by, updated_by, created_at, updated_at FROM portal_pa_map WHERE record_status = "active" AND work_date = :work_date') === 0) {
            foreach ($db->legacy as $id => $row) {
                $employee = $db->employees[$row['employee_id']];
                if ($row['work_date'] === $params[':work_date'] && (!isset($params[':team']) || $employee['equipe'] === $params[':team'])
                    && (!$activeOnly('portal_pa_map') || (int)$employee['ativo'] === 1)) {
                    $this->rows[] = ['id' => $id, 'pa_number' => $row['pa_number'], 'employee_id' => $row['employee_id'], 'employee_name' => $employee['nome'],
                        'employee_login' => 'qa.login.' . $row['employee_id'], 'team' => $employee['equipe'], 'schedule_status' => 'onsite', 'schedule_label' => null,
                        'notes' => 'nota antiga ' . $id, 'created_by' => 'qa.criou', 'updated_by' => 'qa.alterou', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];
                }
            }
            return true;
        }
        if ($sql === 'SELECT pa_number, employee_id, team, work_date FROM portal_pa_map WHERE record_status = "active" AND work_date BETWEEN :date_from AND :date_to ORDER BY work_date, pa_number') {
            foreach ($db->legacy as $row) {
                if ($row['work_date'] >= $params[':date_from'] && $row['work_date'] <= $params[':date_to']) {
                    $this->rows[] = ['pa_number' => $row['pa_number'], 'employee_id' => $row['employee_id'], 'team' => $db->employees[$row['employee_id']]['equipe'], 'work_date' => $row['work_date']];
                }
            }
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }
}

// ----------------------------------------------------------------------------
// Massa ficticia. Semana de segunda 16/11/2026 a sexta 20/11; hoje = 18/11.
// 16 e 18 e 20 sao pares; 17 e 19, impares.
// ----------------------------------------------------------------------------
$today = '2026-11-18';
$baseDb = static function (): QaPaPdo {
    $pdo = new QaPaPdo();
    $person = static fn (string $nome, string $equipe, int $ativo = 1, ?string $arquivado = null, int $fora = 0): array => ['nome' => $nome, 'equipe' => $equipe, 'ativo' => $ativo, 'arquivado_em' => $arquivado, 'fora_mapa_escala' => $fora];
    $pdo->employees = [
        1 => $person('Tecnico A', 'n1'), 2 => $person('Tecnico B', 'n1'), 3 => $person('Tecnico C', 'n1'), 4 => $person('Tecnico D', 'n1'),
        5 => $person('Tecnico E', 'n2'), 6 => $person('Tecnico F', 'n2'), 7 => $person('Tecnico G', 'n2'), 8 => $person('Lider H', 'lideranca'),
        9 => $person('Tecnico I', 'n1', 0, '2026-11-18 10:00:00'), 10 => $person('Tecnico J', 'n1', 0), 11 => $person('Tecnico K', 'n1'),
        12 => $person('Tecnico L', 'n1'), 14 => $person('Tecnico N', 'n1'), 15 => $person('Tecnico O', 'n1'), 16 => $person('Tecnico P', 'n1'),
        17 => $person('Tecnico Q', 'n1'), 18 => $person('Tecnico R', 'n1'), 19 => $person('Tecnico S', 'n2'), 20 => $person('Tecnico T', 'n1'),
        21 => $person('Tecnico U', 'n1'), 22 => $person('Tecnico V', 'n1', 0, '2026-11-16 09:00:00'), 23 => $person('Tecnico X', 'n1', 1, null, 1),
    ];
    $rule = static fn (int $employeeId, string $type, ?string $config = null): array => ['employee_id' => $employeeId, 'rule_type' => $type, 'rule_config' => $config, 'effective_from' => '2026-01-05', 'effective_until' => null];
    $pdo->rules = [
        101 => $rule(1, 'even_days'), 102 => $rule(2, 'odd_days'), 103 => $rule(3, 'always_onsite'), 104 => $rule(4, 'always_onsite'),
        105 => $rule(5, 'fixed_weekdays', '{"weekdays":["mon","wed","fri"]}'), 106 => $rule(6, 'fixed_weekdays', '{"weekdays":["tue","thu"]}'),
        107 => $rule(7, 'always_remote'), 109 => $rule(9, 'always_onsite'), 110 => $rule(10, 'always_onsite'), 112 => $rule(12, 'always_onsite'),
        114 => $rule(14, 'always_onsite'), 115 => $rule(15, 'always_onsite'), 116 => $rule(16, 'always_onsite'), 117 => $rule(17, 'always_onsite'),
        118 => $rule(18, 'always_onsite'), 119 => $rule(19, 'always_onsite'), 120 => $rule(20, 'always_onsite'), 121 => $rule(21, 'always_onsite'),
        122 => $rule(22, 'always_onsite'),
    ];
    // Ausencias do F3 (com observacao, que nunca pode sair) e excecoes antigas.
    $pdo->absences = [
        ['employee_id' => 4, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'ferias', 'reason' => 'observacao ficticia'],
        ['employee_id' => 4, 'starts_on' => '2026-11-19', 'ends_on' => '2026-11-19', 'period_type' => 'from_time', 'start_time' => '14:00:00', 'end_time' => null, 'category' => 'compromisso', 'reason' => 'observacao ficticia'],
        // T ausente na terca, antes do vinculo dele comecar (quarta): fora das ausencias da semana do mapa.
        ['employee_id' => 20, 'starts_on' => '2026-11-17', 'ends_on' => '2026-11-17', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'folga', 'reason' => 'observacao ficticia'],
    ];
    $pdo->exceptions = [
        201 => ['employee_id' => 12, 'exception_date' => '2026-11-20', 'exception_type' => 'day_off'],
        202 => ['employee_id' => 3, 'exception_date' => '2026-11-21', 'exception_type' => 'onsite'],
        203 => ['employee_id' => 4, 'exception_date' => '2026-11-21', 'exception_type' => 'onsite'],
    ];
    $assignment = static fn (string $pa, int $employeeId, string $from = '2026-09-01', ?string $until = null): array => ['pa_number' => $pa, 'employee_id' => $employeeId, 'valid_from' => $from, 'valid_until' => $until];
    $pdo->assignments = [
        1 => $assignment('1732', 1), 2 => $assignment('1732', 2),                    // pares x impares: nunca juntos
        3 => $assignment('1731', 3), 4 => $assignment('1731', 4),                    // D ausente dia todo em 17; parcial em 19
        5 => $assignment('1730', 5), 6 => $assignment('1730', 6),                    // dias fixos disjuntos (L-09)
        7 => $assignment('1729', 7), 8 => $assignment('1729', 11),                   // remoto e sem escala
        9 => $assignment('1728', 8), 10 => $assignment('1728', 12),                  // Lideranca; L de folga (excecao antiga) em 20
        11 => $assignment('1727', 9, '2026-09-01', '2026-11-17'), 12 => $assignment('1727', 14), // I arquivado em 18 (PA fechado em 17)
        13 => $assignment('1726', 10), 14 => $assignment('1726', 15),                // J inativo antigo, sem data
        15 => $assignment('1725', 17),                                               // Q; P so pela tabela antiga em 19
        16 => $assignment('1724', 18), 17 => $assignment('1724', 19),                // equipes diferentes no mesmo PA
        18 => $assignment('1723', 20, '2026-11-18'), 19 => $assignment('1723', 21),  // T comeca na quarta
        20 => $assignment('1722', 22, '2026-09-01', '2026-11-17'),                   // V arquivado em 16, vinculo ate 17 (dado antigo)
    ];
    // Tabela antiga: P so em 19; Q e L tambem pela tabela antiga, no PA do
    // proprio vinculo (o mesmo tecnico duas vezes no dia nao e conflito nem
    // ausencia repetida).
    $pdo->legacy = [
        301 => ['pa_number' => '1725', 'employee_id' => 16, 'work_date' => '2026-11-19'],
        302 => ['pa_number' => '1725', 'employee_id' => 17, 'work_date' => '2026-11-16'],
        303 => ['pa_number' => '1728', 'employee_id' => 12, 'work_date' => '2026-11-20'],
    ];
    return $pdo;
};
$actors = [
    'gestor' => ['role' => 'gestor', 'employee_id' => null],
    'proprio' => ['role' => 'tecnico', 'employee_id' => 4],
    'outro' => ['role' => 'tecnico', 'employee_id' => 1],
    'leitura' => ['role' => 'somente_leitura', 'employee_id' => null],
];
$list = static function (array $filters = [], array $actor = ['role' => 'admin', 'employee_id' => null], bool $canManage = true, ?QaPaPdo $pdo = null) use ($baseDb, $today): array {
    return (new PaMapService($pdo ?? $baseDb(), $today))->list($filters + ['date' => '2026-11-18'], $actor, $canManage);
};

// ----------------------------------------------------------------------------
// 1. Conflito por PA e dia util da semana
// ----------------------------------------------------------------------------
$result = $list();
assert_same(['from' => '2026-11-16', 'to' => '2026-11-20'], $result['week'], 'semana de segunda a sexta da data');
$conflictsByPa = [];
foreach ($result['conflicts'] as $conflict) {
    $conflictsByPa[$conflict['pa_number']][substr($conflict['date'], 8, 2)] = $conflict['employee_ids'];
}
assert_same([
    '1723' => ['18' => [20, 21], '19' => [20, 21], '20' => [20, 21]],
    '1724' => ['16' => [18, 19], '17' => [18, 19], '18' => [18, 19], '19' => [18, 19], '20' => [18, 19]],
    '1725' => ['19' => [16, 17]],
    '1727' => ['16' => [9, 14], '17' => [9, 14]],
    '1728' => ['16' => [8, 12], '17' => [8, 12], '18' => [8, 12], '19' => [8, 12]],
    '1731' => ['16' => [3, 4], '18' => [3, 4], '19' => [3, 4], '20' => [3, 4]],
], $conflictsByPa, 'conflito: pares x impares e dias fixos disjuntos nunca; remoto e sem escala nao; ausente o dia todo (F3 e excecao antiga) nao; parcial e Lideranca sim; arquivado ate a vespera; inativo antigo nao; comeca no meio da semana; tabela antiga; equipes diferentes; sabado fora')
;
$byPa = array_column($result['pas'], null, 'pa_number');
assert_same(
    ['1732' => [false, []], '1731' => [true, ['2026-11-16', '2026-11-18', '2026-11-19', '2026-11-20']], '1730' => [false, []], '1726' => [false, []]],
    array_map(static fn (array $pa): array => [$pa['has_conflict'], $pa['conflict_days']], array_intersect_key($byPa, array_flip(['1732', '1731', '1730', '1726']))),
    'has_conflict e conflict_days por PA'
);
assert_same([false, false], [array_key_exists('2026-11-21', $conflictsByPa['1731'] ?? []), strpos((string)json_encode($result['conflicts']), 'Tecnico') !== false], 'conflito: sabado fora da semana (DF3), mesmo com os dois presenciais por excecao; sem nome no conflito');
// Filtro de equipe: o vinculo some da lista, o conflito do PA continua (o PA e um so).
$n1 = $list(['team' => 'n1']);
assert_same([[18], [18, 19]], [array_column(array_column($n1['pas'], null, 'pa_number')['1724']['assignments'], 'employee_id'), array_values(array_filter($n1['conflicts'], static fn (array $c): bool => $c['pa_number'] === '1724'))[0]['employee_ids']], 'filtro de equipe: lista so N1; conflito do PA com as duas equipes');
// Data no fim de semana: semana que termina nele; conflito so de seg a sex.
$saturday = $list(['date' => '2026-11-21']);
assert_same([['from' => '2026-11-16', 'to' => '2026-11-20'], $result['conflicts']], [$saturday['week'], $saturday['conflicts']], 'sabado: mesma semana e mesmo conflito');

// ----------------------------------------------------------------------------
// 2. Presenca pelo motor, nao pela copia (L-02)
// ----------------------------------------------------------------------------
$view = static function (array $result): array {
    $rows = [];
    foreach ($result['items'] as $item) {
        $rows[$item['pa_number'] . ':' . $item['employee_id']] = [
            $item['schedule_rule_type'], $item['schedule_rule_label'], $item['active_on_date'], $item['presence_status'],
            $item['presence']['situacao'] ?? null, $item['presence']['detalhe'] ?? null, $item['presence']['parcial'] ?? null, $item['presence_reason'] ?? null,
        ];
    }
    ksort($rows);
    return $rows;
};
$wednesday = $view($result);
assert_same([false, false, false], [isset($wednesday['1722:22']), isset($wednesday['1726:10']), isset($wednesday['1727:9'])], 'quarta (hoje): sem vinculo de inativo de hoje em diante (D13 do F4)');
$expectedWednesday = [
    '1726:15' => ['always_onsite', 'Sempre presencial', true, 'onsite', 'presencial', 'Presencial (sempre presencial)', null, null],
    '1728:8' => ['always_onsite', OperationalService::LEADERSHIP_RULE_LABEL, true, 'onsite', 'presencial', 'Presencial (regra fixa)', null, null],
    '1729:11' => ['undefined', 'Sem escala definida', false, 'offsite', 'sem_escala', 'Sem escala definida', null, null],
    '1729:7' => ['always_remote', 'Sempre remoto', false, 'remote', 'remoto', 'Remoto (sempre remoto)', null, null],
    '1730:5' => ['fixed_weekdays', 'Presencial: seg, qua e sex', true, 'onsite', 'presencial', 'Presencial (presencial: seg, qua e sex)', null, null],
    '1730:6' => ['fixed_weekdays', 'Presencial: ter e qui', false, 'remote', 'remoto', 'Remoto (presencial: ter e qui)', null, null],
    '1732:1' => ['even_days', 'Dias pares', true, 'onsite', 'presencial', 'Presencial (dias pares)', null, null],
    '1732:2' => ['odd_days', 'Dias ímpares', false, 'remote', 'remoto', 'Remoto (dias ímpares)', null, null],
];
assert_same($expectedWednesday, array_intersect_key($wednesday, $expectedWednesday), 'quarta (hoje): regra vigente do motor, nao a copia (sempre remoto gravado em todos)');
$thursday = $view($list(['date' => '2026-11-19']));
assert_same(['always_onsite', 'Sempre presencial', true, 'onsite', 'presencial', 'Presencial (sempre presencial) · Ausente a partir de 14:00', 'a partir de 14:00', null], $thursday['1731:4'], 'parcial: presencial com o marcador e o campo parcial');
$monday = $view($list(['date' => '2026-11-16']));
assert_same([
    ['undefined', 'Sem escala definida', false, 'offsite', null, null, null, 'inativo'],
    ['undefined', 'Sem escala definida', false, 'offsite', null, null, null, 'arquivado'],
    ['always_onsite', 'Sempre presencial', true, 'onsite', 'presencial', 'Presencial (sempre presencial)', null, null],
], [$monday['1726:10'] ?? null, $monday['1722:22'] ?? null, $monday['1727:9'] ?? null], 'segunda (passado): inativo antigo e arquivado a partir do arquivamento sem dia no motor (sem situacao nem regra, com o motivo; DF6); arquivado antes dele presencial');
$tuesday = $view($list(['date' => '2026-11-17']));
assert_same(['always_onsite', 'Sempre presencial', false, 'offsite', 'ausente', 'Ausente (dia todo)', null, null], $tuesday['1731:4'], 'ausente o dia todo: situacao ausente, sem parcial');
$sat = $view($saturday);
assert_same([
    ['always_onsite', 'Sempre presencial', true, 'onsite', 'presencial', 'Presencial (exceção: presencial)', null, null],
    ['even_days', 'Dias pares', false, 'offsite', null, null, null, 'fim_de_semana'],
], [$sat['1731:3'], $sat['1732:1']], 'sabado: excecao de trabalho presencial; os demais sem situacao, com a regra do ultimo dia util');
assert_same(false, array_key_exists('schedule_rule_config', $result['items'][0]), 'copia da configuracao fora da resposta');
$legacyThursday = array_values(array_filter($list(['date' => '2026-11-19'])['items'], static fn (array $item): bool => $item['source'] === 'legacy'));
assert_same([['legacy-301', '1725', 16, 'presencial']], array_map(static fn (array $item): array => [$item['id'], $item['pa_number'], $item['employee_id'], $item['presence']['situacao'] ?? null], $legacyThursday), 'tabela antiga por data: com a presenca do motor');

// ----------------------------------------------------------------------------
// 3. P9, DF7 e ausencias da semana
// ----------------------------------------------------------------------------
$absences = static fn (array $result): array => array_map(static fn (array $item): string => substr($item['date'], 8, 2) . ' ' . $item['employee_name'] . ' ' . $item['origem'] . ' ' . $item['period_type'] . ($item['start_time'] !== null ? ' ' . $item['start_time'] : '') . (isset($item['category']) ? ' ' . $item['category'] : ''), $result['absences_week']);
$categoryOf = static fn (array $result, int $employeeId): ?string => array_values(array_filter($result['items'], static fn (array $item): bool => (int)$item['employee_id'] === $employeeId))[0]['presence']['categoria'] ?? null;
$byActor = [];
foreach ($actors as $who => $actor) {
    $tue = $list(['date' => '2026-11-17'], $actor, false);
    $thu = $list(['date' => '2026-11-19'], $actor, false);
    $byActor[$who] = [$absences($tue), $categoryOf($tue, 4), $categoryOf($thu, 4)];
    foreach ([$tue, $thu] as $view) {
        $json = (string)json_encode($view, JSON_UNESCAPED_UNICODE);
        assert_same([false, false], [strpos($json, 'observacao ficticia') !== false, strpos($json, '"reason"') !== false || strpos($json, '"note"') !== false], "P9 ({$who}): observacao nunca");
        foreach ($view['absences_week'] as $item) {
            assert_same(true, !isset($item['category']) || $who === 'gestor' || ($who === 'proprio' && $item['employee_id'] === 4), "P9 ({$who}): categoria so para a gestao e o proprio");
        }
    }
}
$week = ['17 Tecnico D ausencia full_day', '19 Tecnico D ausencia from_time 14:00', '20 Tecnico L excecao full_day'];
$withCategory = ['17 Tecnico D ausencia full_day Férias', '19 Tecnico D ausencia from_time 14:00 Compromisso', '20 Tecnico L excecao full_day'];
assert_same([
    'gestor' => [$withCategory, 'Férias', 'Compromisso'],
    'proprio' => [$withCategory, 'Férias', 'Compromisso'],
    'outro' => [$week, null, null],
    'leitura' => [$week, null, null],
], $byActor, 'P9 e DF9: ausencias da semana (F3 e excecao antiga, dia inteiro e parcial) e categoria so para a gestao e o proprio');
$teamWeek = $list(['team' => 'n2'], ['role' => 'gestor', 'employee_id' => null], false);
assert_same([], $teamWeek['absences_week'], 'ausencias da semana com o filtro de equipe do vinculo');
// DF7: so quem edita o mapa recebe elegiveis, login, observacao e autores.
$marks = ['qa.login.', 'nota do vinculo', 'nota antiga', 'qa.criou', 'qa.alterou'];
foreach ([true, false] as $canManage) {
    $view = $list(['date' => '2026-11-19'], ['role' => 'gestor', 'employee_id' => null], $canManage);
    $json = (string)json_encode($view, JSON_UNESCAPED_UNICODE);
    assert_same(
        array_fill_keys(array_merge(['employees'], $marks), $canManage),
        array_merge(['employees' => array_key_exists('employees', $view)], array_combine($marks, array_map(static fn (string $mark): bool => strpos($json, $mark) !== false, $marks))),
        'DF7: ' . ($canManage ? 'quem edita recebe tudo' : 'quem nao edita fica sem elegiveis, login, observacao e autores')
    );
    assert_same(true, count($view['items']) > 0 && isset($view['items'][0]['employee_name'], $view['items'][0]['team'], $view['items'][0]['pa_number']) && array_key_exists('presence', $view['items'][0]), 'DF7: nome, equipe, PA e situacao para todos');
}
$eligible = array_column($list(['date' => '2026-11-18'])['employees'], null, 'id');
assert_same([
    [false, false, false],
    ['even_days', 'Dias pares', 'onsite'],
    ['undefined', 'Sem escala definida', 'no_schedule'],
], [
    [isset($eligible[9]), isset($eligible[10]), isset($eligible[23])],
    [$eligible[1]['schedule_rule_type'], $eligible[1]['schedule_rule_label'], $eligible[1]['schedule_status']],
    [$eligible[11]['schedule_rule_type'], $eligible[11]['schedule_rule_label'], $eligible[11]['schedule_status']],
], 'elegiveis: sem arquivado, inativo e fora do Mapa; escala do dia pelo motor');
assert_same(['even_days', 'Dias pares', 'no_schedule'], [$list(['date' => '2026-11-21'])['employees'][0]['schedule_rule_type'] ?? null, array_column($list(['date' => '2026-11-21'])['employees'], null, 'id')[1]['schedule_rule_label'], array_column($list(['date' => '2026-11-21'])['employees'], null, 'id')[1]['schedule_status']], 'elegiveis no sabado: regra do ultimo dia util, sem escala no dia');

// ----------------------------------------------------------------------------
// 4. Consultas fixas para N cadastros (L-10) e sem leitura do corte (DF1)
// ----------------------------------------------------------------------------
$count = static function (int $extra) use ($baseDb, $today): array {
    $pdo = $baseDb();
    for ($i = 0; $i < $extra; $i++) {
        $id = 500 + $i;
        $pdo->employees[$id] = ['nome' => 'Extra ' . $id, 'equipe' => 'n1', 'ativo' => 1, 'arquivado_em' => null, 'fora_mapa_escala' => 0];
        $pdo->rules[1000 + $id] = ['employee_id' => $id, 'rule_type' => 'even_days', 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
        $pdo->assignments[1000 + $id] = ['pa_number' => '1721', 'employee_id' => $id, 'valid_from' => '2026-09-01', 'valid_until' => null];
        $pdo->legacy[1000 + $id] = ['pa_number' => '1720', 'employee_id' => $id, 'work_date' => '2026-11-18'];
    }
    (new PaMapService($pdo, $today))->list(['date' => '2026-11-18'], ['role' => 'admin', 'employee_id' => null], true);
    return $pdo->log;
};
$small = $count(0);
$large = $count(40);
assert_same([count($small), $small], [count($large), $large], 'numero de consultas fixo: 40 cadastros a mais com vinculo, regra e tabela antiga nao mudam as consultas (L-10)');
assert_same(true, count($small) <= 13, 'consultas da leitura do mapa: no maximo 13 (' . count($small) . ')');
assert_same(1, count(array_filter($small, static fn (string $sql): bool => strpos($sql, 'information_schema') !== false && strpos($sql, '#portal_pa_assignments') !== false)), 'existencia de cada tabela conferida uma vez por requisicao');
assert_same(0, count(array_filter(array_merge($small, $large), static fn (string $sql): bool => stripos($sql, 'cutoff') !== false)), 'o mapa nao le a data de corte (DF1)');
$serviceSource = (string)file_get_contents($root . '/services/PaMapService.php');
assert_same([false, false], [strpos($serviceSource, 'PresenceCutoff') !== false, strpos($serviceSource, 'presenceCutoff') !== false], 'PaMapService sem leitura do corte (DF1)');
assert_same([false, false], [strpos($serviceSource, 'ruleActiveOnDate') !== false, strpos($serviceSource, 'storedRuleType') !== false], 'leitura da copia removida (L-02)');

// Runners.
foreach (['scripts/test-linux.sh', 'scripts/test-local.ps1'] as $runner) {
    assert_same(true, strpos((string)file_get_contents($root . '/' . $runner), 'qa-pa-conflicts.php') !== false, "{$runner} roda o qa-pa-conflicts");
}

echo "QA PA conflicts OK ({$checks} verificacoes)\n";
