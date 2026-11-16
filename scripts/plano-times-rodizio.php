<?php
declare(strict_types=1);

// ============================================================================
// Plano de migracao das regras de rodizio para os times (F10-2; desenho do
// F10, secao 8.6). SO LEITURA: nao grava nada, nem com --detalhe.
//
// A regra de cada tecnico que vigora em --a-partir vira pertenca a qual time
// (Time 1, 2 ou 3, com a ancora da planilha), sem mudar nenhum dia ate o
// horizonte? Para cada um: a classificacao, a simulacao da entrada no time
// (o mesmo planejador que o servico usa) e a prova pelo motor de presenca,
// dia util a dia util, com o historico antes e depois. Imprime so contagens;
// com --detalhe, tambem o employee_id e o time (nunca nome nem login).
//
// Uso (estacao ou servidor, com o .env do banco; transacao READ ONLY):
//   php scripts/plano-times-rodizio.php --a-partir=2026-11-23 \
//       [--ancora=2026-10-01] [--horizonte-dias=730] [--detalhe]
// Fora da linha de comando: 404.
// ============================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../services/RotationTeamPlanner.php';

const PLANO_ANCORA_PLANILHA = '2026-10-01';
const PLANO_HORIZONTE_DIAS = 730;
/** Os tres times do rodizio da planilha (desenho 2.2): mesma ancora, fase no padrao. */
const PLANO_TIMES = [
    ['key' => 'Time 1', 'id' => 1, 'pattern' => ['remote', 'onsite', 'onsite']],
    ['key' => 'Time 2', 'id' => 2, 'pattern' => ['onsite', 'remote', 'onsite']],
    ['key' => 'Time 3', 'id' => 3, 'pattern' => ['onsite', 'onsite', 'remote']],
];
const PLANO_MOTIVOS = ['convertivel', 'lideranca', 'fora_do_mapa', 'sem_regra', 'nao_e_ciclo', 'ciclo_invalido', 'ciclo_fora_do_rodizio', 'regra_individual_agendada_depois', 'sem_time_equivalente'];

/**
 * Plano puro (o QA chama sem banco): $employees com id, team e
 * fora_mapa_escala; $rulesByEmployee com as regras de cada um.
 */
function plano_times_rodizio(array $employees, array $rulesByEmployee, string $from, string $anchor, int $horizonDays, string $today): array {
    $horizon = ScheduleRulePlanner::addDays($from, $horizonDays);
    $teams = array_map(static fn (array $team): array => $team + ['anchor_date' => $anchor], PLANO_TIMES);
    $result = [
        'a_partir' => $from, 'ancora' => $anchor, 'horizonte' => $horizon,
        'tecnicos' => count($employees),
        'motivos' => array_fill_keys(PLANO_MOTIVOS, 0),
        'por_time' => array_fill_keys(array_column(PLANO_TIMES, 'key'), 0),
        'dias_comparados' => 0, 'dias_iguais' => 0, 'dias_diferentes' => 0,
        'detalhe' => [],
    ];
    foreach ($employees as $employee) {
        $rules = $rulesByEmployee[(int)$employee['id']] ?? [];
        $class = RotationTeamPlanner::classifyForTeams($employee, $rules, $teams, $from, $horizon, $today);
        $result['motivos'][$class['status']]++;
        if ($class['status'] === 'convertivel') {
            $result['por_time'][$class['team']]++;
            $result['dias_comparados'] += $class['prova']['dias'];
            $result['dias_iguais'] += $class['prova']['iguais'];
            $result['dias_diferentes'] += $class['prova']['diferentes'];
        }
        $result['detalhe'][] = ['employee_id' => (int)$employee['id'], 'status' => $class['status'], 'team' => $class['team'] ?? null];
    }
    return $result;
}

function plano_argumento(array $argv, string $name): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return null;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $from = plano_argumento($argv, 'a-partir');
    $anchor = plano_argumento($argv, 'ancora') ?? PLANO_ANCORA_PLANILHA;
    $horizonRaw = plano_argumento($argv, 'horizonte-dias') ?? (string)PLANO_HORIZONTE_DIAS;
    if ($from === null || !PresenceEngine::validDate($from) || !PresenceEngine::validDate($anchor) || !PresenceEngine::isBusinessDay($anchor)
        || preg_match('/^\d{1,4}$/', $horizonRaw) !== 1 || (int)$horizonRaw < 1) {
        fwrite(STDERR, "Uso: php scripts/plano-times-rodizio.php --a-partir=AAAA-MM-DD [--ancora=AAAA-MM-DD (dia util)] [--horizonte-dias=730] [--detalhe]\n");
        exit(2);
    }
    putenv('CHRONODESK_STATELESS=1');
    define('CHRONODESK_STATELESS', true);
    require_once __DIR__ . '/../db.php';
    $pdo = get_db_connection();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $employees = $pdo->query(
        "SELECT id, LOWER(equipe) AS team, fora_mapa_escala FROM funcionarios WHERE ativo = 1 ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $employees = array_values(array_filter(array_map(static function (array $row): array {
        $row['team'] = validate_funcionario_equipe((string)$row['team']) ?? (string)$row['team'];
        return $row;
    }, $employees), static fn (array $row): bool => in_array($row['team'], ['n1', 'n2', 'lideranca'], true)));
    $hasTeamColumn = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rotation_team_id'"
    )->fetchColumn() === 1;
    $rulesByEmployee = [];
    $rows = $pdo->query(
        'SELECT id, employee_id, rule_type, rule_config, ' . ($hasTeamColumn ? 'rotation_team_id' : 'NULL AS rotation_team_id')
        . ', effective_from, effective_until FROM portal_schedule_rules ORDER BY employee_id, effective_from, id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $row['rotation_team_id'] = $row['rotation_team_id'] === null ? null : (int)$row['rotation_team_id'];
        $rulesByEmployee[(int)$row['employee_id']][] = $row;
    }
    $pdo->rollBack();
    $plan = plano_times_rodizio($employees, $rulesByEmployee, $from, $anchor, (int)$horizonRaw, date('Y-m-d'));
    echo "Plano dos times de rodizio (so leitura; nada foi gravado)\n";
    echo "a partir de {$plan['a_partir']}, ancora {$plan['ancora']}, horizonte {$plan['horizonte']}\n";
    echo "tecnicos ativos (N1, N2 e Lideranca): {$plan['tecnicos']}\n";
    foreach ($plan['motivos'] as $motivo => $count) {
        echo "  {$motivo}: {$count}\n";
    }
    foreach ($plan['por_time'] as $team => $count) {
        echo "  convertivel no {$team}: {$count}\n";
    }
    echo "prova pelo motor: {$plan['dias_comparados']} dias comparados, {$plan['dias_iguais']} iguais, {$plan['dias_diferentes']} diferentes\n";
    if (in_array('--detalhe', $argv, true)) {
        foreach ($plan['detalhe'] as $row) {
            echo "  employee_id={$row['employee_id']} {$row['status']}" . ($row['team'] !== null ? " {$row['team']}" : '') . "\n";
        }
    }
    exit($plan['dias_diferentes'] === 0 ? 0 : 1);
}
