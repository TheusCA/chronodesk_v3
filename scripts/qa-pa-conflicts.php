<?php
declare(strict_types=1);

// ============================================================================
// QA do Mapa PA no motor (Parte B, F6, commits 1 e 2; desenho, secao 9.8)
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
// 5. Gravacao (commit 2): conflito, remoto, ausencia e sem escala nao
//    bloqueiam (P13): warnings[] no 1o dia util do vinculo (DF5) e
//    conflicts[] por dia no horizonte da DF4, com auditoria sem nome;
//    confirm_remote_allocation ignorado; 409 para o mesmo colaborador em
//    outro PA (com trava) e fora do Mapa; 400 para inativo; decisao 8 do F4;
//    remove em transacao com PA e colaborador (DF11).
//
// 6. [F10-3] Inventario, reservados do Critico pelos papeis e preferencias.
// 7. [8.8] Papel do dia do Critico (9.16) numa fonte so: a mesma pessoa igual
//    na Escala, no Calendario, nos relatorios, na Visao operacional e no Mapa
//    PA (papel presencial x regra remota, papel remoto x regra presencial,
//    excecao por data vencendo o papel e ausencia vencendo tudo).
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
require_once $root . '/services/PresenceService.php';

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
    // [F10-3] Inventario com a 022 (pa_number => id, tipo, emprestimo, reserva),
    // preferencias ([pa_id, employee_id]) e os papeis do Critico (9.16).
    public array $seats = [];
    public array $preferences = [];
    public array $roles = [];
    public array $rotations = [];
    public array $overrides = [];
    // [8.8] Secao 7: corte do motor (Calendario e relatorios).
    public ?string $cutoff = null;
    public array $log = [];
    public array $written = [];
    public array $transactions = [];
    private bool $transaction = false;

    public function __construct() {
    }

    public function beginTransaction(): bool {
        $this->transactions[] = 'begin';
        return $this->transaction = true;
    }

    public function commit(): bool {
        $this->transactions[] = 'commit';
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool {
        $this->transactions[] = 'rollback';
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool {
        return $this->transaction;
    }

    public function lastInsertId(?string $name = null): string|false {
        return (string)array_key_last($this->assignments);
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
    private int $affected = 0;

    public function __construct(private QaPaPdo $pdo, private string $sql) {
    }

    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = $this->sql;
        $db = $this->pdo;
        $db->log[] = $sql . (isset($params[':table_name']) ? ' #' . $params[':table_name'] : '');
        $this->rows = [];
        if (strpos($sql, 'portal_presence_cutoff') !== false || ($params[':table_name'] ?? '') === 'portal_presence_cutoff') {
            if ($db->cutoff === null) {
                throw new PDOException('SQLSTATE[HY000]: banco simulado: o Mapa PA nao le a data de corte (DF1)');
            }
            // [8.8] So a secao 7 (Calendario e relatorios) tem corte.
            $this->rows[] = strpos($sql, 'information_schema') !== false ? ['total' => 1] : ['cutoff_date' => $db->cutoff];
            return true;
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
        $rulesPrefix = 'SELECT r.id, r.employee_id, f.nome AS employee_name, f.ad_login, LOWER(f.equipe) AS team, r.rule_type, r.rule_config, r.effective_from, r.effective_until, r.created_by, r.updated_by, r.created_at, r.updated_at FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND (f.ativo = 1 OR f.arquivado_em IS NOT NULL) AND f.fora_mapa_escala = 0 WHERE LOWER(f.equipe) IN ("n1", "n2", "critico", "hibrido")';
        if (strpos($sql, $rulesPrefix) === 0) {
            foreach ($db->rules as $id => $rule) {
                $employee = $db->employees[$rule['employee_id']];
                $team = strtolower($employee['equipe']);
                if (!$visible($employee, true) || !in_array($team, ['n1', 'n2', 'critico', 'hibrido'], true) || $employee['fora_mapa_escala'] === 1
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
                if (($row['active'] ?? 1) === 1 && $row['valid_from'] <= $params[':date_until'] && ($row['valid_until'] === null || $row['valid_until'] >= $params[':date_from'])
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
        // Gravacao (save e remove).
        if ($sql === 'SELECT id, nome AS name, equipe AS team, ad_login, fora_mapa_escala FROM funcionarios WHERE id = :id AND ativo = 1 LIMIT 1 FOR UPDATE') {
            $row = $db->employees[(int)$params[':id']] ?? null;
            if ($row !== null && (int)$row['ativo'] === 1) {
                $this->rows[] = ['id' => (int)$params[':id'], 'name' => $row['nome'], 'team' => $row['equipe'], 'ad_login' => 'qa.login.' . $params[':id'], 'fora_mapa_escala' => $row['fora_mapa_escala']];
            }
            return true;
        }
        if ($sql === 'SELECT id, pa_number FROM portal_pa_assignments WHERE active = 1 AND employee_id = :employee_id AND id <> :id AND valid_from <= :new_until AND (valid_until IS NULL OR valid_until >= :new_from) LIMIT 1 FOR UPDATE') {
            foreach ($db->assignments as $id => $row) {
                if (($row['active'] ?? 1) === 1 && $row['employee_id'] === $params[':employee_id'] && $id !== $params[':id']
                    && $row['valid_from'] <= $params[':new_until'] && ($row['valid_until'] === null || $row['valid_until'] >= $params[':new_from'])) {
                    $this->rows[] = ['id' => $id, 'pa_number' => $row['pa_number']];
                }
            }
            return true;
        }
        if ($sql === 'SELECT rule_type, rule_config FROM portal_schedule_rules WHERE employee_id = :employee_id AND effective_from <= :work_date_from AND (effective_until IS NULL OR effective_until >= :work_date_until) ORDER BY effective_from DESC, id DESC LIMIT 1') {
            foreach ($db->rules as $rule) {
                if ($rule['employee_id'] === $params[':employee_id'] && $rule['effective_from'] <= $params[':work_date_from']) {
                    $this->rows[] = ['rule_type' => $rule['rule_type'], 'rule_config' => $rule['rule_config']];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT employee_id, valid_from, valid_until FROM portal_pa_assignments WHERE active = 1 AND pa_number = :pa_number AND id <> :id AND valid_from <= :date_until AND (valid_until IS NULL OR valid_until >= :date_from)') === 0) {
            foreach ($db->assignments as $id => $row) {
                if (($row['active'] ?? 1) === 1 && $row['pa_number'] === $params[':pa_number'] && $id !== $params[':id']
                    && $row['valid_from'] <= $params[':date_until'] && ($row['valid_until'] === null || $row['valid_until'] >= $params[':date_from'])
                    && (!$activeOnly('portal_pa_assignments') || (int)$db->employees[$row['employee_id']]['ativo'] === 1)) {
                    $this->rows[] = ['employee_id' => $row['employee_id'], 'valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until']];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT employee_id, work_date FROM portal_pa_map WHERE record_status = "active" AND pa_number = :pa_number AND work_date BETWEEN :date_from AND :date_to') === 0) {
            foreach ($db->legacy as $row) {
                if ($row['pa_number'] === $params[':pa_number'] && $row['work_date'] >= $params[':date_from'] && $row['work_date'] <= $params[':date_to']
                    && (!$activeOnly('portal_pa_map') || (int)$db->employees[$row['employee_id']]['ativo'] === 1)) {
                    $this->rows[] = ['employee_id' => $row['employee_id'], 'work_date' => $row['work_date']];
                }
            }
            return true;
        }
        if (strpos($sql, 'INSERT INTO portal_pa_assignments (') === 0) {
            $db->assignments[max(array_keys($db->assignments)) + 1] = ['pa_number' => $params[':pa_number'], 'employee_id' => $params[':employee_id'], 'valid_from' => $params[':valid_from'], 'valid_until' => $params[':valid_until'], 'notes' => $params[':notes']];
            $db->written[] = $params;
            return true;
        }
        if (strpos($sql, 'UPDATE portal_pa_assignments SET pa_number = :pa_number,') === 0) {
            $this->affected = isset($db->assignments[$params[':id']]) && ($db->assignments[$params[':id']]['active'] ?? 1) === 1 ? 1 : 0;
            if ($this->affected === 1) {
                $db->assignments[$params[':id']] = ['pa_number' => $params[':pa_number'], 'employee_id' => $params[':employee_id'], 'valid_from' => $params[':valid_from'], 'valid_until' => $params[':valid_until'], 'notes' => $params[':notes']];
                $db->written[] = $params;
            }
            return true;
        }
        // [9.12.1] Gravado da edicao, com trava: o que a chave ausente mantem.
        if ($sql === 'SELECT valid_from, valid_until, notes FROM portal_pa_assignments WHERE id = :id AND active = 1 LIMIT 1 FOR UPDATE') {
            $row = $db->assignments[$params[':id']] ?? null;
            if ($row !== null && ($row['active'] ?? 1) === 1) {
                $this->rows[] = ['valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until'], 'notes' => $row['notes'] ?? null];
            }
            return true;
        }
        if ($sql === 'SELECT id, pa_number, employee_id FROM portal_pa_assignments WHERE id = :id AND active = 1 LIMIT 1 FOR UPDATE') {
            $row = $db->assignments[$params[':id']] ?? null;
            if ($row !== null && ($row['active'] ?? 1) === 1) {
                $this->rows[] = ['id' => $params[':id'], 'pa_number' => $row['pa_number'], 'employee_id' => $row['employee_id']];
            }
            return true;
        }
        if ($sql === 'UPDATE portal_pa_assignments SET active = 0, deleted_at = CURRENT_TIMESTAMP, updated_by = :updated_by WHERE id = :id AND active = 1') {
            $this->affected = isset($db->assignments[$params[':id']]) && ($db->assignments[$params[':id']]['active'] ?? 1) === 1 ? 1 : 0;
            if ($this->affected === 1) {
                $db->assignments[$params[':id']]['active'] = 0;
            }
            return true;
        }
        if ($this->f103($sql, $params) || ($db->cutoff !== null && $this->crossViews($sql, $params))) {
            return true;
        }
        throw new PDOException('SQLSTATE[HY000]: banco simulado: consulta nao prevista: ' . $sql);
    }

    /** [F10-3] Inventario completo, preferencias e papeis do Critico. */
    private function f103(string $sql, array $params): bool {
        $db = $this->pdo;
        if ($sql === 'SELECT id, pa_number, seat_type, can_lend, reserved_team FROM portal_pa_inventory WHERE active = 1') {
            foreach ($db->seats as $pa => $seat) {
                $this->rows[] = ['id' => $seat['id'], 'pa_number' => $pa, 'seat_type' => $seat['seat_type'], 'can_lend' => $seat['can_lend'], 'reserved_team' => $seat['reserved_team']];
            }
            return true;
        }
        if ($sql === 'SELECT id, pa_number, seat_type, can_lend, reserved_team FROM portal_pa_inventory WHERE pa_number = :pa_number AND active = 1 LIMIT 1 FOR UPDATE') {
            $seat = $db->seats[$params[':pa_number']] ?? null;
            if ($seat !== null) {
                $this->rows[] = ['id' => $seat['id'], 'pa_number' => $params[':pa_number'], 'seat_type' => $seat['seat_type'], 'can_lend' => $seat['can_lend'], 'reserved_team' => $seat['reserved_team']];
            }
            return true;
        }
        if ($sql === 'UPDATE portal_pa_inventory SET seat_type = :seat_type, can_lend = :can_lend, reserved_team = :reserved_team WHERE id = :id') {
            foreach ($db->seats as $pa => $seat) {
                if ($seat['id'] === $params[':id']) {
                    $db->seats[$pa] = ['id' => $seat['id'], 'seat_type' => $params[':seat_type'], 'can_lend' => $params[':can_lend'], 'reserved_team' => $params[':reserved_team']];
                    $db->written[] = ['inventory' => $pa];
                }
            }
            return true;
        }
        if ($sql === 'SELECT i.pa_number, p.employee_id FROM portal_pa_preferences p JOIN portal_pa_inventory i ON i.id = p.pa_id ORDER BY i.pa_number, p.employee_id') {
            $paById = array_flip(array_map(static fn (array $seat): int => $seat['id'], $db->seats));
            foreach ($db->preferences as [$paId, $employeeId]) {
                $this->rows[] = ['pa_number' => (string)$paById[$paId], 'employee_id' => $employeeId];
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['pa_number'], $a['employee_id']] <=> [$b['pa_number'], $b['employee_id']]);
            return true;
        }
        if (preg_match('/^SELECT employee_id FROM portal_pa_preferences WHERE pa_id = :pa_id ORDER BY employee_id( FOR UPDATE)?$/', $sql) === 1) {
            $ids = [];
            foreach ($db->preferences as [$paId, $employeeId]) {
                if ($paId === $params[':pa_id']) {
                    $ids[] = $employeeId;
                }
            }
            sort($ids);
            $this->rows = array_map(static fn (int $id): array => ['employee_id' => $id], $ids);
            return true;
        }
        if (preg_match('/^DELETE FROM portal_pa_preferences WHERE pa_id = \? AND employee_id IN \(\?(, \?)*\)$/', $sql) === 1) {
            $paId = array_shift($params);
            $db->preferences = array_values(array_filter($db->preferences, static fn (array $p): bool => !($p[0] === $paId && in_array($p[1], $params, true))));
            $db->written[] = ['preferences_removed' => $params];
            return true;
        }
        if ($sql === 'INSERT INTO portal_pa_preferences (pa_id, employee_id, created_by) VALUES (:pa_id, :employee_id, :created_by)') {
            $db->preferences[] = [$params[':pa_id'], $params[':employee_id']];
            $db->written[] = ['preference_added' => $params[':employee_id'], 'by' => $params[':created_by']];
            return true;
        }
        if (preg_match('/^SELECT id, equipe, grupo_escala, fora_mapa_escala FROM funcionarios WHERE ativo = 1 AND id IN \(\?(, \?)*\)$/', $sql) === 1) {
            foreach ($params as $id) {
                $row = $db->employees[$id] ?? null;
                if ($row !== null && (int)$row['ativo'] === 1) {
                    $this->rows[] = ['id' => $id, 'equipe' => $row['equipe'], 'grupo_escala' => $row['grupo_escala'] ?? (in_array($row['equipe'], ['critico', 'hibrido'], true) ? 'separado' : 'comum'), 'fora_mapa_escala' => $row['fora_mapa_escala']];
                }
            }
            return true;
        }
        $activeFilter = strpos($sql, 'f.ativo = 1)') !== false;
        $covers = static fn (array $row, string $from, string $until): bool => ($row['active'] ?? 1) === 1 && $row['valid_from'] <= $until && ($row['valid_until'] === null || $row['valid_until'] >= $from);
        if (strpos($sql, 'SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND pa_number = :pa_number AND id <> :id AND valid_from <= :date_until AND (valid_until IS NULL OR valid_until >= :date_from)') === 0) {
            $total = 0;
            foreach ($db->assignments as $id => $row) {
                if ($row['pa_number'] === $params[':pa_number'] && $id !== $params[':id'] && $covers($row, $params[':date_from'], $params[':date_until'])
                    && (!$activeFilter || (int)$db->employees[$row['employee_id']]['ativo'] === 1)) {
                    $total++;
                }
            }
            $this->rows[] = ['total' => $total];
            return true;
        }
        if (strpos($sql, 'SELECT id, valid_from, valid_until FROM portal_pa_assignments WHERE active = 1 AND pa_number = :pa_number AND (valid_until IS NULL OR valid_until >= :date_from)') === 0) {
            foreach ($db->assignments as $id => $row) {
                if ($row['pa_number'] === $params[':pa_number'] && $covers($row, $params[':date_from'], '9999-12-31')
                    && (!$activeFilter || (int)$db->employees[$row['employee_id']]['ativo'] === 1)) {
                    $this->rows[] = ['id' => $id, 'valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until']];
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['valid_from'], $a['id']] <=> [$b['valid_from'], $b['id']]);
            return true;
        }
        // Papeis do Critico (PresenceRepository::criticalRoleContext).
        if ($sql === 'SELECT id, name, presence_type, work_start, work_end, active, sort_order FROM portal_critical_roles ORDER BY sort_order, id') {
            $this->rows = array_values($db->roles);
            return true;
        }
        if ($sql === 'SELECT id, effective_from, period, anchor_date, roles, employees FROM portal_critical_role_rotations WHERE effective_from <= :date_to ORDER BY effective_from') {
            $this->rows = array_values(array_filter($db->rotations, static fn (array $r): bool => $r['effective_from'] <= $params[':date_to']));
            return true;
        }
        if ($sql === 'SELECT id, employee_id, starts_on, ends_on, role_id, reason FROM portal_critical_role_overrides WHERE starts_on <= :date_to AND ends_on >= :date_from ORDER BY employee_id, starts_on') {
            $this->rows = array_values(array_filter($db->overrides, static fn (array $o): bool => $o['starts_on'] <= $params[':date_to'] && $o['ends_on'] >= $params[':date_from']));
            return true;
        }
        return false;
    }

    /**
     * [8.8] Secao 7: as consultas da Escala, do Calendario, dos relatorios e
     * do PresenceService (Visao operacional), so com o corte ligado.
     */
    private function crossViews(string $sql, array $params): bool {
        $db = $this->pdo;
        foreach (['FROM portal_overtime_entries', 'FROM portal_time_adjustments', 'FROM portal_oncall_shifts', 'FROM portal_calendar_events'] as $empty) {
            if (strpos($sql, $empty) !== false) {
                return true;
            }
        }
        $active = static fn (array $employee): bool => (int)$employee['ativo'] === 1;
        $mapped = static fn (array $employee): bool => (int)$employee['ativo'] === 1 && $employee['fora_mapa_escala'] === 0;
        // Escala: regras so dos ativos, Lideranca implicita, ausencias e cadastros sem o arquivado.
        if (strpos($sql, 'SELECT r.id, r.employee_id, f.nome AS employee_name, f.ad_login, LOWER(f.equipe) AS team, r.rule_type, r.rule_config, r.effective_from, r.effective_until, r.created_by, r.updated_by, r.created_at, r.updated_at FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 1 AND f.fora_mapa_escala = 0 WHERE') === 0) {
            foreach ($db->rules as $id => $rule) {
                $employee = $db->employees[$rule['employee_id']];
                if ($mapped($employee) && in_array($employee['equipe'], ['n1', 'n2', 'critico', 'hibrido'], true)) {
                    $this->rows[] = ['id' => $id, 'employee_id' => $rule['employee_id'], 'employee_name' => $employee['nome'], 'ad_login' => 'qa.' . $rule['employee_id'], 'team' => $employee['equipe'],
                        'rule_type' => $rule['rule_type'], 'rule_config' => $rule['rule_config'] ?? null, 'effective_from' => $rule['effective_from'], 'effective_until' => $rule['effective_until'],
                        'created_by' => 'qa', 'updated_by' => 'qa', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00'];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, nome AS employee_name, ad_login, equipe, jornada_entrada, jornada_saida FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0') === 0) {
            foreach ($db->employees as $id => $row) {
                if ($mapped($row) && $row['equipe'] === 'lideranca') {
                    $this->rows[] = ['id' => $id, 'employee_name' => $row['nome'], 'ad_login' => 'qa.' . $id, 'equipe' => $row['equipe'], 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00'];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT a.employee_id, a.starts_on, a.ends_on, a.period_type, a.start_time, a.end_time FROM portal_absences a JOIN funcionarios f ON f.id = a.employee_id AND ') === 0) {
            $withArchived = strpos($sql, '(f.ativo = 1 OR f.arquivado_em IS NOT NULL)') !== false;
            foreach ($db->absences as $row) {
                $employee = $db->employees[$row['employee_id']];
                if (($active($employee) || ($withArchived && $employee['arquivado_em'] !== null)) && $row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']) {
                    $this->rows[] = array_intersect_key($row, array_flip(['employee_id', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time']));
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, nome AS employee_name, equipe FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0') === 0) {
            foreach ($db->employees as $id => $row) {
                if ($mapped($row)) {
                    $this->rows[] = ['id' => $id, 'employee_name' => $row['nome'], 'equipe' => $row['equipe']];
                }
            }
            usort($this->rows, static fn (array $a, array $b): int => [$a['employee_name'], $a['id']] <=> [$b['employee_name'], $b['id']]);
            return true;
        }
        // PresenceService (Visao operacional): as 4 consultas do PresenceRepository.
        if (strpos($sql, 'SELECT id, nome, equipe, jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, fora_mapa_escala FROM funcionarios WHERE ativo = 1') === 0) {
            foreach ($db->employees as $id => $row) {
                if ($active($row)) {
                    $this->rows[] = ['id' => $id, 'nome' => $row['nome'], 'equipe' => $row['equipe'], 'jornada_entrada' => '08:00:00', 'jornada_saida' => '17:00:00',
                        'almoco_inicio' => '12:00:00', 'almoco_fim' => '13:00:00', 'fora_mapa_escala' => $row['fora_mapa_escala']];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE effective_from <= :date_to') === 0) {
            foreach ($db->rules as $id => $rule) {
                if ($rule['effective_from'] <= $params[':date_to'] && ($rule['effective_until'] === null || $rule['effective_until'] >= $params[':date_from'])) {
                    $this->rows[] = ['id' => $id] + $rule + ['rule_config' => null];
                }
            }
            return true;
        }
        if (strpos($sql, 'SELECT employee_id, exception_date, exception_type FROM portal_schedule_exceptions WHERE exception_date BETWEEN :date_from AND :date_to') === 0) {
            foreach ($db->exceptions as $row) {
                if ($row['exception_date'] >= $params[':date_from'] && $row['exception_date'] <= $params[':date_to']) {
                    $this->rows[] = ['employee_id' => $row['employee_id'], 'exception_date' => $row['exception_date'], 'exception_type' => $row['exception_type']];
                }
            }
            return true;
        }
        if (strpos($sql, "SELECT id, employee_id, starts_on, ends_on, period_type, start_time, end_time, category FROM portal_absences WHERE status = 'approved'") === 0) {
            foreach ($db->absences as $index => $row) {
                if ($row['starts_on'] <= $params[':date_to'] && $row['ends_on'] >= $params[':date_from']) {
                    $this->rows[] = ['id' => $index + 1] + array_intersect_key($row, array_flip(['employee_id', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time', 'category']));
                }
            }
            return true;
        }
        return false;
    }

    public function rowCount(): int {
        return $this->affected;
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
        // [F10-3] FETCH_COLUMN devolve so a 1a coluna, como o PDO.
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn (array $row): mixed => array_values($row)[0], $rows) : $rows;
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

// ----------------------------------------------------------------------------
// 5. Gravacao (commit 2): avisos, conflito por dia e remove em transacao
// ----------------------------------------------------------------------------
// Hoje = quarta 18/11; os 20 dias uteis do horizonte (DF4) vao de 18/11 a 15/12.
// Cadastros so desta secao: Y (ter e qui), W (sempre presencial, ausente o dia
// todo em 18/11), Z (sempre remoto, N2) e AA (sem regra).
$saveDb = static function () use ($baseDb): QaPaPdo {
    $pdo = $baseDb();
    $pdo->tables['portal_schedule_rules'] = 1;
    foreach ([24 => ['Tecnico Y', 'n1'], 25 => ['Tecnico W', 'n1'], 26 => ['Tecnico Z', 'n2'], 27 => ['Tecnico AA', 'n1']] as $id => [$nome, $equipe]) {
        $pdo->employees[$id] = ['nome' => $nome, 'equipe' => $equipe, 'ativo' => 1, 'arquivado_em' => null, 'fora_mapa_escala' => 0];
    }
    $pdo->rules[124] = ['employee_id' => 24, 'rule_type' => 'fixed_weekdays', 'rule_config' => '{"weekdays":["tue","thu"]}', 'effective_from' => '2026-01-05', 'effective_until' => null];
    $pdo->rules[125] = ['employee_id' => 25, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
    $pdo->rules[126] = ['employee_id' => 26, 'rule_type' => 'always_remote', 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
    $pdo->absences[] = ['employee_id' => 25, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'ferias', 'reason' => 'observacao ficticia'];
    return $pdo;
};
$save = static function (array $data, ?QaPaPdo $pdo = null) use ($saveDb, $today): array {
    $pdo ??= $saveDb();
    try {
        $result = (new PaMapService($pdo, $today))->save($data + ['valid_from' => '2026-11-18'], 'qa.lider');
    } catch (Throwable $error) {
        $result = ['erro' => get_class($error) . ': ' . $error->getMessage()];
    }
    return [$result, $pdo];
};
$summary = static fn (array $result): array => isset($result['erro']) ? [$result['erro']] : [
    $result['warnings'],
    array_map(static fn (array $c): string => substr($c['date'], 5) . ' ' . implode('+', $c['employee_ids']), $result['conflicts']),
    $result['conflicts_more'],
];
$horizon = ['11-18', '11-19', '11-20', '11-23', '11-24', '11-25', '11-26', '11-27', '11-30', '12-01'];

// Conflito com ativo: salva, avisa os 20 dias (10 na lista e 10 a mais); o
// inativo antigo J (vinculo 13 no mesmo PA) fica fora (decisao 8).
[$result, $pdo] = $save(['pa_number' => '1726', 'employee_id' => 16]);
assert_same([[], array_map(static fn (string $d): string => $d . ' 15+16', $horizon), 10], $summary($result), 'save: conflito com ativo salva e avisa 20 dias (10 + 10 a mais); inativo fora (P13, DF4, decisao 8)');
assert_same([21, ['begin', 'commit']], [count($pdo->assignments), $pdo->transactions], 'save: gravado em transacao');
assert_same('Alocacao de PA salva com conflito. ID 21, PA 1726, colaboradores ID 15, 16, datas 2026-11-18, 2026-11-19, 2026-11-20, 2026-11-23, 2026-11-24, 2026-11-25, 2026-11-26, 2026-11-27, 2026-11-30, 2026-12-01 e mais 10', $result['conflict_audit'], 'save: auditoria do conflito com PA, IDs e datas');
assert_same([false, false], [strpos((string)json_encode($result), 'Tecnico') !== false, strpos((string)json_encode($result), 'qa.login') !== false], 'save: resposta e auditoria sem nome nem login');
assert_same(['1726', 16, 21], [$result['pa_number'], $result['employee_id'], $result['id']], 'save: PA, colaborador e ID para a auditoria do endpoint');
// Dias fixos: conflito so nos dias em que os dois estao presenciais (ter e qui com F; seg, qua e sex com E nunca).
assert_same([['Colaborador em trabalho remoto em 18/11/2026, início do vínculo.'], ['11-19 6+24', '11-24 6+24', '11-26 6+24', '12-01 6+24', '12-03 6+24', '12-08 6+24', '12-10 6+24', '12-15 6+24'], 0], $summary($save(['pa_number' => '1730', 'employee_id' => 24])[0]), 'save: conflito por dia (ter e qui), nao por tipo (L-09)');
// Ausente o dia todo no inicio: salva sem 409, avisa e fica fora do conflito nesse dia; parcial de D (19/11) conta.
[$result] = $save(['pa_number' => '1731', 'employee_id' => 25]);
assert_same([['Colaborador ausente o dia todo em 18/11/2026, início do vínculo.'], '11-19 3+4+25', 19], [$result['warnings'], substr($result['conflicts'][0]['date'], 5) . ' ' . implode('+', $result['conflicts'][0]['employee_ids']), count($result['conflicts']) + $result['conflicts_more']], 'save: ausente o dia todo avisa, sem 409, e sai do conflito no dia; parcial conta');
// Remoto: salva sem confirmacao (o campo e ignorado), com aviso e sem conflito.
$remote = $summary($save(['pa_number' => '1732', 'employee_id' => 26])[0]);
assert_same([['Colaborador em trabalho remoto em 18/11/2026, início do vínculo.'], [], 0], $remote, 'save: remoto salva sem confirmacao, com aviso, fora do conflito (DF5)');
assert_same($remote, $summary($save(['pa_number' => '1732', 'employee_id' => 26, 'confirm_remote_allocation' => true])[0]), 'save: confirm_remote_allocation aceito e ignorado (DF5)');
assert_same([['Colaborador sem escala definida em 18/11/2026, início do vínculo.'], [], 0], $summary($save(['pa_number' => '1729', 'employee_id' => 27])[0]), 'save: sem escala salva com aviso');
// Inicio no sabado: o aviso e da segunda; inicio no passado: do 1o dia util do vinculo, com o horizonte de hoje.
assert_same([['Colaborador em trabalho remoto em 23/11/2026, início do vínculo.']], [$save(['pa_number' => '1732', 'employee_id' => 26, 'valid_from' => '2026-11-21'])[0]['warnings']], 'save: inicio no sabado avisa pela segunda');
[$result, $pdo] = $save(['pa_number' => '1732', 'employee_id' => 26, 'valid_from' => '2026-11-02']);
$motorLoads = count(array_filter($pdo->log, static fn (string $sql): bool => strpos($sql, 'FROM portal_schedule_rules r JOIN funcionarios f') !== false));
assert_same([['Colaborador em trabalho remoto em 02/11/2026, início do vínculo.'], 2], [$result['warnings'], $motorLoads], 'save: inicio no passado avisa pelo 1o dia util do vinculo (motor carregado duas vezes)');
[$result, $pdo] = $save(['pa_number' => '1726', 'employee_id' => 16, 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-17']);
$motorLoads = count(array_filter($pdo->log, static fn (string $sql): bool => strpos($sql, 'FROM portal_schedule_rules r JOIN funcionarios f') !== false));
assert_same([[], [], 0, 1], array_merge($summary($result), [$motorLoads]), 'save: vinculo todo no passado sem horizonte nem conflito (DF4)');
assert_same([[], ['11-18 15+16', '11-19 15+16', '11-20 15+16'], 0], $summary($save(['pa_number' => '1726', 'employee_id' => 16, 'valid_until' => '2026-11-20'])[0]), 'save: horizonte para no fim do vinculo');
// Ocupante que comeca no meio do horizonte (25/11) e acaba antes do fim (01/12): so nesses dias.
$pdo = $saveDb();
$pdo->assignments[30] = ['pa_number' => '1721', 'employee_id' => 25, 'valid_from' => '2026-11-25', 'valid_until' => '2026-12-01'];
assert_same([[], ['11-25 16+25', '11-26 16+25', '11-27 16+25', '11-30 16+25', '12-01 16+25'], 0], $summary($save(['pa_number' => '1721', 'employee_id' => 16], $pdo)[0]), 'save: ocupante so nos dias do vinculo dele');
// Continua recusado.
[$result, $pdo] = $save(['pa_number' => '1726', 'employee_id' => 1]);
assert_same([[DomainException::class . ': Este colaborador já possui vínculo ativo em outro PA no período.'], 20, ['begin', 'rollback']], [$summary($result), count($pdo->assignments), $pdo->transactions], 'save: o mesmo colaborador em outro PA no periodo continua 409, sem gravar');
assert_same([DomainException::class . ': Este colaborador já possui vínculo ativo em outro PA no período.'], $summary($save(['pa_number' => '1732', 'employee_id' => 1, 'valid_from' => '2026-12-01'])[0]), 'save: o mesmo colaborador no mesmo PA, periodo sobreposto, continua 409');
assert_same([DomainException::class . ': Cadastro marcado como fora do Mapa PA e da escala: não recebe vínculo de PA.'], $summary($save(['pa_number' => '1726', 'employee_id' => 23])[0]), 'save: fora do Mapa continua 409');
assert_same([InvalidArgumentException::class . ': Colaborador ativo inválido.'], $summary($save(['pa_number' => '1726', 'employee_id' => 10])[0]), 'save: cadastro inativo continua recusado (400)');
assert_same([
    [InvalidArgumentException::class . ': Fim da validade não pode ser anterior ao início.'],
    [InvalidArgumentException::class . ': Início da validade inválida.'],
    [InvalidArgumentException::class . ': PA inválido.'],
], [
    $summary($save(['pa_number' => '1726', 'employee_id' => 16, 'valid_until' => '2026-11-17'])[0]),
    $summary($save(['pa_number' => '1726', 'employee_id' => 16, 'valid_from' => '2026-02-30'])[0]),
    $summary($save(['pa_number' => '#1726', 'employee_id' => 16])[0]),
], 'save: mensagens de validacao com acento (aparecem na tela)');
// Edicao: o proprio vinculo nao conta; mudar de PA confere o PA novo.
[$result, $pdo] = $save(['id' => 14, 'pa_number' => '1726', 'employee_id' => 15]);
assert_same([[[], [], 0], 20], [$summary($result), count($pdo->assignments)], 'save: edicao sem conflito com o proprio vinculo, sem inserir');
assert_same('11-18 3+4+15', $summary($save(['id' => 14, 'pa_number' => '1731', 'employee_id' => 15])[0])[1][0], 'save: edicao para outro PA confere o PA novo');
assert_same([[], [], 0], $summary($save(['id' => 14, 'pa_number' => '1726', 'employee_id' => 16])[0]), 'save: edicao que troca o colaborador nao conta o vinculo antigo (o proprio registro) como ocupante');
// [9.12.1] Edicao: chave ausente mantem o gravado (valid_from, valid_until e
// notes), lido com trava. Antes da II3, o formulario (PaMapPage.jsx,
// formFromAssignment) nao mandava valid_until e editar pela tela apagava o fim
// da validade (o vinculo voltava a valer sem fim); desde a II3 ele manda o fim
// sempre (data ou vazio). Presente, vale o enviado; nulo e vazio limpam.
$editDb = static function () use ($saveDb): QaPaPdo {
    $pdo = $saveDb();
    $pdo->assignments[40] = ['pa_number' => '1722', 'employee_id' => 27, 'valid_from' => '2026-11-02', 'valid_until' => '2026-12-31', 'notes' => 'nota gravada'];
    return $pdo;
};
$edit = static function (array $data) use ($editDb, $today): array {
    $pdo = $editDb();
    try {
        $result = (new PaMapService($pdo, $today))->save($data, 'qa.lider');
    } catch (Throwable $error) {
        $result = ['erro' => get_class($error) . ': ' . $error->getMessage()];
    }
    return [$result, $pdo];
};
$storedLink = static fn (QaPaPdo $pdo): array => array_intersect_key($pdo->assignments[40], array_flip(['valid_from', 'valid_until', 'notes']));
$keptLink = ['valid_from' => '2026-11-02', 'valid_until' => '2026-12-31', 'notes' => 'nota gravada'];
// Corpo da tela antes da II3 (id, pa_number, employee_id, valid_from e notes,
// sem valid_until): continua valendo para quem manda o corpo sem o fim.
$screenEdit = ['id' => 40, 'pa_number' => '1722', 'employee_id' => 27, 'valid_from' => '2026-11-02', 'notes' => 'nota editada', 'action' => 'save'];
[$result, $pdo] = $edit($screenEdit);
assert_same([false, array_merge($keptLink, ['notes' => 'nota editada'])], [isset($result['erro']), $storedLink($pdo)], 'edicao sem valid_until (o corpo da tela antes da II3): mantem o fim da validade gravado, grava inicio e observacao');
[$result, $pdo] = $edit(['id' => 40, 'pa_number' => '1722', 'employee_id' => 27]);
assert_same([false, $keptLink], [isset($result['erro']), $storedLink($pdo)], 'edicao sem inicio, fim e observacao: mantem os tres gravados');
[$result, $pdo] = $edit(['valid_until' => null, 'notes' => ''] + $screenEdit);
assert_same([false, ['valid_from' => '2026-11-02', 'valid_until' => null, 'notes' => null]], [isset($result['erro']), $storedLink($pdo)], 'edicao com fim nulo e observacao vazia presentes: limpa os dois (escolha explicita)');
[$result, $pdo] = $edit(['valid_from' => '2026-11-09', 'valid_until' => '2026-11-30'] + $screenEdit);
assert_same([false, ['valid_from' => '2026-11-09', 'valid_until' => '2026-11-30', 'notes' => 'nota editada']], [isset($result['erro']), $storedLink($pdo)], 'edicao com inicio e fim presentes: grava os enviados');
// Inicio depois do fim gravado (a tela nao tem o fim): 400, nada gravado.
[$result, $pdo] = $edit(['valid_from' => '2027-01-04'] + $screenEdit);
assert_same([[InvalidArgumentException::class . ': Fim da validade não pode ser anterior ao início.'], $keptLink, ['begin', 'rollback'], []], [$summary($result), $storedLink($pdo), $pdo->transactions, $pdo->written], 'edicao com inicio depois do fim gravado: 400, nada gravado');
// [II3] Corpo da tela desde a II3: o formulario carrega o fim gravado e manda
// valid_until sempre (data ou vazio = sem fim). A regra do fim nao anterior ao
// inicio e a que a tela confere antes de mandar (lib/paMap.ts).
[$result, $pdo] = $edit(['valid_until' => '2026-12-31', 'notes' => 'nota gravada'] + $screenEdit);
assert_same([false, $keptLink], [isset($result['erro']), $storedLink($pdo)], 'II3: edicao pela tela sem mexer em nada: o fim carregado volta igual');
[$result, $pdo] = $edit(['valid_until' => '2027-01-31', 'notes' => 'nota gravada'] + $screenEdit);
assert_same([false, ['valid_from' => '2026-11-02', 'valid_until' => '2027-01-31', 'notes' => 'nota gravada']], [isset($result['erro']), $storedLink($pdo)], 'II3: edicao pela tela mudando so o fim: grava o fim novo, o resto como estava');
[$result, $pdo] = $edit(['valid_until' => ''] + $screenEdit);
assert_same([false, null], [isset($result['erro']), $storedLink($pdo)['valid_until']], 'II3: fim vazio vindo da tela: sem fim (null)');
[$result, $pdo] = $edit(['valid_until' => '2026-11-02'] + $screenEdit);
assert_same([false, '2026-11-02'], [isset($result['erro']), $storedLink($pdo)['valid_until']], 'II3: fim igual ao inicio vale (vinculo de um dia), como na tela');
[$result, $pdo] = $edit(['valid_until' => '2026-11-01'] + $screenEdit);
assert_same([[InvalidArgumentException::class . ': Fim da validade não pode ser anterior ao início.'], $keptLink, ['begin', 'rollback'], []], [$summary($result), $storedLink($pdo), $pdo->transactions, $pdo->written], 'II3: fim antes do inicio no corpo da edicao: 400 com a mensagem da tela, nada gravado');
[$result, $pdo] = $save(['pa_number' => '1721', 'employee_id' => 24, 'valid_from' => '2026-11-20', 'valid_until' => '2026-11-20']);
assert_same([false, '2026-11-20', '2026-11-20'], [isset($result['erro']), end($pdo->written)[':valid_from'] ?? null, end($pdo->written)[':valid_until'] ?? null], 'II3: criacao com fim igual ao inicio vale');
// Vinculo inexistente ou ja desocupado: a recusa da gravacao, agora antes de calcular o conflito.
foreach (['inexistente' => 999, 'desocupado' => 40] as $label => $linkId) {
    $pdo = $editDb();
    if ($label === 'desocupado') {
        $pdo->assignments[40]['active'] = 0;
    }
    try {
        (new PaMapService($pdo, $today))->save(['id' => $linkId] + $screenEdit, 'qa.lider');
        $outcome = 'ok';
    } catch (DomainException $error) {
        $outcome = $error->getMessage();
    }
    assert_same(['Vínculo de PA não encontrado.', ['begin', 'rollback'], []], [$outcome, $pdo->transactions, $pdo->written], "edicao de vinculo {$label}: 409, sem gravar");
}
// O gravado e lido com FOR UPDATE, depois de travar o colaborador (a ordem do save); a criacao nao le.
$storedSql = 'SELECT valid_from, valid_until, notes FROM portal_pa_assignments WHERE id = :id AND active = 1 LIMIT 1 FOR UPDATE';
[, $pdo] = $edit($screenEdit);
$lockEmployee = array_search('SELECT id, nome AS name, equipe AS team, ad_login, fora_mapa_escala FROM funcionarios WHERE id = :id AND ativo = 1 LIMIT 1 FOR UPDATE', $pdo->log, true);
$lockStored = array_search($storedSql, $pdo->log, true);
assert_same(true, $lockEmployee !== false && $lockStored !== false && $lockEmployee < $lockStored, 'edicao: le o gravado com FOR UPDATE, depois de travar o colaborador');
[$result, $pdo] = $edit(['pa_number' => '1721', 'employee_id' => 24]);
assert_same([false, '2026-11-18', null, null, false], [isset($result['erro']), end($pdo->written)[':valid_from'], end($pdo->written)[':valid_until'], end($pdo->written)[':notes'], in_array($storedSql, $pdo->log, true)], 'criacao sem inicio, fim e observacao: hoje, sem fim e sem observacao, como antes, sem ler gravado');
// [II3] O formulario carrega o fim gravado e manda valid_until sempre; a
// mensagem do fim antes do inicio e uma so (servidor, tela, servidor simulado
// e roteiro de navegador), e a tela a pega da lib, sem copia no JSX.
$paPage = (string)file_get_contents($root . '/frontend/src/pages/PaMapPage.jsx');
$formBuilder = preg_match('/function formFromAssignment\(assignment, date\) \{\n(.*?)\n\}/s', $paPage, $formMatch) === 1 ? $formMatch[1] : '';
$emptyBuilder = preg_match('/function emptyForm\(paNumber, date\) \{\n(.*?)\n\}/s', $paPage, $emptyMatch) === 1 ? $emptyMatch[1] : '';
assert_same([true, true, true], [
    strpos($formBuilder, 'valid_from: assignment.valid_from') !== false && strpos($formBuilder, "notes: assignment.notes || ''") !== false,
    strpos($formBuilder, "valid_until: assignment.valid_until || ''") !== false,
    strpos($emptyBuilder, "valid_until: ''") !== false,
], 'II3: formulario do Mapa PA carrega inicio, fim e observacao do vinculo; adicionar abre com o fim vazio');
$endMessage = 'Fim da validade não pode ser anterior ao início.';
assert_same([true, true, true, true, true, false], [
    strpos((string)file_get_contents($root . '/services/PaMapService.php'), "throw new InvalidArgumentException('{$endMessage}');") !== false,
    strpos((string)file_get_contents($root . '/frontend/src/lib/paMap.ts'), "export const VALIDITY_END_BEFORE_START = '{$endMessage}'") !== false,
    strpos((string)file_get_contents($root . '/frontend/scripts/browser/lib/mock-server.mjs'), "export const PA_END_BEFORE_START = '{$endMessage}'") !== false,
    strpos((string)file_get_contents($root . '/frontend/scripts/browser/qa-unsaved-changes-browser.mjs'), 'refused.mensagem.includes(PA_END_BEFORE_START)') !== false,
    strpos($paPage, 'const error = validityEndError(form.valid_from, form.valid_until)') !== false,
    strpos($paPage, $endMessage) !== false,
], 'II3: a mensagem do fim antes do inicio e a do servidor na tela (pela lib), no servidor simulado e no roteiro');
// Tabela antiga no conflito do save; os IDs da auditoria juntam todos os dias.
[$result] = $save(['pa_number' => '1725', 'employee_id' => 24]);
assert_same(['11-19 16+17+24', '11-24 17+24', '11-26 17+24'], array_slice($summary($result)[1], 0, 3), 'save: vinculo da tabela antiga (P em 19/11) entra no conflito do dia');
assert_same(1, preg_match('/^Alocacao de PA salva com conflito\. ID 21, PA 1725, colaboradores ID 16, 17, 24, datas /', (string)$result['conflict_audit']), 'save: auditoria com os IDs de todos os dias, sem repeticao');
// Copia da regra gravada para o rollback (2.7).
[, $pdo] = $save(['pa_number' => '1730', 'employee_id' => 24]);
assert_same(['fixed_weekdays', '{"weekdays":["tue","thu"]}'], [end($pdo->written)[':schedule_rule_type'], end($pdo->written)[':schedule_rule_config']], 'save: copia da regra continua gravada');
// Consultas fixas no save para N cadastros.
$saveLog = static function (int $extra) use ($saveDb, $save): array {
    $pdo = $saveDb();
    for ($i = 0; $i < $extra; $i++) {
        $id = 600 + $i;
        $pdo->employees[$id] = ['nome' => 'Extra ' . $id, 'equipe' => 'n1', 'ativo' => 1, 'arquivado_em' => null, 'fora_mapa_escala' => 0];
        $pdo->rules[2000 + $id] = ['employee_id' => $id, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
        $pdo->assignments[2000 + $id] = ['pa_number' => '1721', 'employee_id' => $id, 'valid_from' => '2026-09-01', 'valid_until' => null];
    }
    $save(['pa_number' => '1726', 'employee_id' => 16], $pdo);
    return array_map(static fn (string $sql): string => preg_replace('/INSERT INTO .*/', 'INSERT', $sql), $pdo->log);
};
assert_same(count($saveLog(0)), count($saveLog(40)), 'save: numero de consultas fixo com 40 cadastros a mais');
// remove em transacao, com o PA e o colaborador para a auditoria (DF11).
$pdo = $saveDb();
assert_same([['id' => 14, 'pa_number' => '1726', 'employee_id' => 15], ['begin', 'commit'], 0], [(new PaMapService($pdo, $today))->remove(14, 'qa.lider'), $pdo->transactions, $pdo->assignments[14]['active']], 'remove: em transacao, devolve PA e colaborador (IDs)');
$pdo->transactions = [];
try {
    (new PaMapService($pdo, $today))->remove(14, 'qa.lider');
    $removed = 'ok';
} catch (DomainException $error) {
    $removed = $error->getMessage();
}
assert_same(['Vínculo de PA não encontrado.', ['begin', 'rollback']], [$removed, $pdo->transactions], 'remove: vinculo ja removido da 409 e desfaz a transacao');
$serviceSource = (string)file_get_contents($root . '/services/PaMapService.php');
assert_same([false, false, false], [strpos($serviceSource, 'confirm_remote_allocation') !== false && strpos($serviceSource, "\$data['confirm_remote_allocation']") !== false, strpos($serviceSource, 'Conflito de escala no PA com') !== false, strpos($serviceSource, 'scheduleException') !== false], 'save: sem confirmacao de remoto, sem 409 de conflito com nome, sem a excecao antiga (o motor decide)');
$endpointSource = (string)file_get_contents($root . '/api/portal/pa_map.php');
assert_same([true, true, true], [
    strpos($endpointSource, "audit_log('PA_MAP_CONFLICT_ACCEPTED', \$result['conflict_audit'], 'WARNING');") !== false,
    strpos($endpointSource, "', PA ' . \$result['pa_number'] . ', colaborador ID ' . \$result['employee_id']") !== false,
    strpos($endpointSource, "', PA ' . \$removed['pa_number'] . ', colaborador ID ' . \$removed['employee_id']") !== false,
], 'endpoint: auditoria do conflito (WARNING) e de salvar e remover com PA e IDs');

// Runners.
foreach (['scripts/test-linux.sh', 'scripts/test-local.ps1'] as $runner) {
    assert_same(true, strpos((string)file_get_contents($root . '/' . $runner), 'qa-pa-conflicts.php') !== false, "{$runner} roda o qa-pa-conflicts");
}

// ----------------------------------------------------------------------------
// 6. [F10-3] Inventario completo, reservados do Critico pelos papeis do dia
//    (D-F10-19 revista), PA fixo com um dono por data e preferencias
// ----------------------------------------------------------------------------
// Sem a 022: a leitura e a gravacao do vinculo ficam como antes, e o inventario recusa.
$plain = $list();
assert_same([false, false, false], [$plain['seat_setup'], array_key_exists('critical_seating', $plain), array_key_exists('seat_type', $plain['pas'][0])], 'sem a 022: leitura como antes (sem tipo nem reservados)');
try {
    (new PaMapService($baseDb(), $today))->saveSeat(['pa_number' => '1732', 'seat_type' => 'fixed'], 'qa.lider');
    $noSetup = 'gravou';
} catch (DomainException $error) {
    $noSetup = $error->getMessage();
}
assert_same('Inventário de PA ainda não foi atualizado (migration 022).', $noSetup, 'sem a 022: gravar o inventario da 409');

// Com a 022: 1723 e 1722 reservados ao Critico (sem vinculo vigente), 1725 fixo
// (dono Q) e 1732 com preferencias. Critico: 31 papel Presencial A (rodizio);
// 35 Presencial B (rodizio); 32 Abertura home no rodizio, mas troca para
// Presencial B em 18/11; 34 sem papel e remoto, mas excecao presencial em 18/11;
// 30 sem papel e sempre presencial; 33 sem papel, ausente o dia todo em 18/11;
// 36 com papel Presencial A, mas ausente o dia todo (a ausencia vence o papel).
$f103Db = static function () use ($saveDb): QaPaPdo {
    $pdo = $saveDb();
    $pdo->tables['portal_pa_preferences'] = 1;
    unset($pdo->assignments[18], $pdo->assignments[19]);
    foreach (['1732', '1731', '1730', '1729', '1728', '1727', '1726', '1725', '1724', '1723', '1722'] as $index => $pa) {
        $pdo->seats[$pa] = ['id' => $index + 1, 'seat_type' => 'flexible', 'can_lend' => 0, 'reserved_team' => null];
    }
    $pdo->seats['1723'] = ['id' => 10, 'seat_type' => 'fixed', 'can_lend' => 0, 'reserved_team' => 'critico'];
    $pdo->seats['1722'] = ['id' => 11, 'seat_type' => 'fixed', 'can_lend' => 0, 'reserved_team' => 'critico'];
    $pdo->seats['1725'] = ['id' => 8, 'seat_type' => 'fixed', 'can_lend' => 1, 'reserved_team' => null];
    $pdo->preferences = [[1, 2], [1, 5]];
    foreach ([30 => 'Critico Zeta', 31 => 'Critico Beta', 32 => 'Critico Gama', 33 => 'Critico Delta', 34 => 'Critico Alfa', 35 => 'Critico Eta', 36 => 'Critico Iota'] as $id => $nome) {
        $pdo->employees[$id] = ['nome' => $nome, 'equipe' => 'critico', 'ativo' => 1, 'arquivado_em' => null, 'fora_mapa_escala' => 0];
    }
    foreach ([30 => 'always_onsite', 31 => 'always_remote', 32 => 'always_onsite', 33 => 'always_onsite', 34 => 'always_remote', 35 => 'always_remote', 36 => 'always_onsite'] as $id => $type) {
        $pdo->rules[100 + $id * 10] = ['employee_id' => $id, 'rule_type' => $type, 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
    }
    $pdo->exceptions[230] = ['employee_id' => 34, 'exception_date' => '2026-11-18', 'exception_type' => 'onsite'];
    foreach ([33, 36] as $id) {
        $pdo->absences[] = ['employee_id' => $id, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'ferias', 'reason' => 'observacao ficticia'];
    }
    $pdo->roles = [
        ['id' => 1, 'name' => 'Presencial A', 'presence_type' => 'onsite', 'work_start' => '08:00:00', 'work_end' => '17:00:00', 'active' => 1, 'sort_order' => 1],
        ['id' => 2, 'name' => 'Presencial B', 'presence_type' => 'onsite', 'work_start' => '09:00:00', 'work_end' => '18:00:00', 'active' => 1, 'sort_order' => 2],
        ['id' => 3, 'name' => 'Abertura home', 'presence_type' => 'remote', 'work_start' => '07:00:00', 'work_end' => '17:00:00', 'active' => 1, 'sort_order' => 3],
        ['id' => 4, 'name' => 'Reserva', 'presence_type' => 'onsite', 'work_start' => '08:00:00', 'work_end' => '17:00:00', 'active' => 1, 'sort_order' => 4],
    ];
    $pdo->rotations = [['id' => 1, 'effective_from' => '2026-11-16', 'period' => 'week', 'anchor_date' => '2026-11-16', 'roles' => '[1,3,2,4]', 'employees' => '[31,32,35,36]']];
    $pdo->overrides = [['id' => 1, 'employee_id' => 32, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'role_id' => 2, 'reason' => 'troca']];
    // 37: troca para Abertura home (remoto) em 18/11, mas excecao presencial no dia (a excecao vence o papel).
    $pdo->employees[37] = ['nome' => 'Critico Kapa', 'equipe' => 'critico', 'ativo' => 1, 'arquivado_em' => null, 'fora_mapa_escala' => 0];
    $pdo->rules[470] = ['employee_id' => 37, 'rule_type' => 'always_remote', 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
    $pdo->exceptions[237] = ['employee_id' => 37, 'exception_date' => '2026-11-18', 'exception_type' => 'onsite'];
    $pdo->overrides[] = ['id' => 2, 'employee_id' => 37, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'role_id' => 3, 'reason' => 'troca'];
    return $pdo;
};
$view = $list([], ['role' => 'admin', 'employee_id' => null], true, $f103Db());
$byPa = array_column($view['pas'], null, 'pa_number');
assert_same(
    [true, ['fixed', false, 'critico'], ['fixed', true, null], ['flexible', false, null], [2, 5], []],
    [$view['seat_setup'], array_values(array_intersect_key($byPa['1723'], array_flip(['seat_type', 'can_lend', 'reserved_team']))), array_values(array_intersect_key($byPa['1725'], array_flip(['seat_type', 'can_lend', 'reserved_team']))), array_values(array_intersect_key($byPa['1730'], array_flip(['seat_type', 'can_lend', 'reserved_team']))), $byPa['1732']['preference_ids'], $byPa['1731']['preference_ids']],
    'com a 022: tipo, emprestimo e reserva de cada PA e as preferencias (so IDs) para quem edita'
);
// Ordem: com papel pela ordem do papel (31 Presencial A; 35 e 32 Presencial B,
// pelo nome; 37 Abertura home, presencial pela excecao); sem papel pelo nome
// (34, 30); 33 e 36 ausentes ficam fora.
assert_same(
    [['employee_id' => 31, 'employee_name' => 'Critico Beta', 'role_label' => 'Presencial A 08:00–17:00'], ['employee_id' => 35, 'employee_name' => 'Critico Eta', 'role_label' => 'Presencial B 09:00–18:00'], 2, 6, [32, 37, 34, 30]],
    [$byPa['1723']['reserved_occupant'], $byPa['1722']['reserved_occupant'], $view['critical_seating']['seats'], $view['critical_seating']['onsite'], array_column($view['critical_seating']['without_seat'], 'employee_id')],
    'reservados do Critico: os papeis do dia dizem quem ocupa (troca > rodizio > regra; ausencia e excecao vencem o papel); quem sobra fica sem assento'
);
assert_same([false, false], [array_key_exists('reserved_occupant', $byPa['1725']), array_key_exists('preference_ids', array_column($list([], ['role' => 'gestor', 'employee_id' => null], false, $f103Db())['pas'], null, 'pa_number')['1732'])], 'so o reservado tem ocupante do Critico; quem nao edita nao recebe as preferencias');
$weekend = array_column($list(['date' => '2026-11-21'], ['role' => 'admin', 'employee_id' => null], true, $f103Db())['pas'], null, 'pa_number');
assert_same([null, null], [$weekend['1723']['reserved_occupant'], $weekend['1722']['reserved_occupant']], 'reservados no sabado: vazios (nenhum papel nem presencial)');
$oneSeat = $f103Db();
$oneSeat->seats['1722'] = ['id' => 11, 'seat_type' => 'flexible', 'can_lend' => 0, 'reserved_team' => null];
$oneView = $list([], ['role' => 'admin', 'employee_id' => null], true, $oneSeat);
assert_same([1, 31, [35, 32, 37, 34, 30]],[$oneView['critical_seating']['seats'], array_column($oneView['pas'], null, 'pa_number')['1723']['reserved_occupant']['employee_id'], array_column($oneView['critical_seating']['without_seat'], 'employee_id')], 'N configuravel: com 1 PA reservado, so o 1o da ordem senta');
// Consultas fixas com a 022 (L-10): 40 cadastros a mais nao mudam as consultas.
$count103 = static function (int $extra) use ($f103Db, $today): array {
    $pdo = $f103Db();
    for ($id = 300; $id < 300 + $extra; $id++) {
        $pdo->employees[$id] = ['nome' => 'Extra ' . $id, 'equipe' => $id % 2 === 0 ? 'n1' : 'critico', 'ativo' => 1, 'arquivado_em' => null, 'fora_mapa_escala' => 0];
        $pdo->rules[3000 + $id] = ['employee_id' => $id, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
    }
    (new PaMapService($pdo, $today))->list(['date' => '2026-11-18'], ['role' => 'admin', 'employee_id' => null], true);
    return $pdo->log;
};
assert_same(count($count103(0)), count($count103(40)), 'com a 022: numero de consultas fixo para N cadastros (L-10), inclusive do Critico');

// Vinculo: PA reservado 409; PA fixo com dono no periodo 409; o proprio dono edita; fixo livre aceita.
assert_same(['DomainException: PA reservado ao Crítico: quem ocupa vem dos papéis do dia, e ele não recebe vínculo.', 'DomainException: PA fixo: já tem dono no período. Encerre o vínculo atual antes (um dono por data).'], [$save(['pa_number' => '1723', 'employee_id' => 24], $f103Db())[0]['erro'], $save(['pa_number' => '1725', 'employee_id' => 24], $f103Db())[0]['erro']], 'vinculo: PA reservado e PA fixo com dono no periodo dao 409');
$own = $f103Db();
[$ownResult] = $save(['id' => 15, 'pa_number' => '1725', 'employee_id' => 17, 'valid_from' => '2026-09-01', 'valid_until' => ''], $own);
assert_same([15, 'commit'], [$ownResult['id'] ?? $ownResult, end($own->transactions)], 'vinculo: o dono do PA fixo edita o proprio vinculo');
$freeFixed = $f103Db();
$freeFixed->seats['1725']['seat_type'] = 'fixed';
unset($freeFixed->assignments[15]);
assert_same(true, isset($save(['pa_number' => '1725', 'employee_id' => 24], $freeFixed)[0]['id']), 'vinculo: PA fixo sem dono no periodo aceita');
$nextOwner = $f103Db();
$nextOwner->assignments[15]['valid_until'] = '2026-11-30';
assert_same([true, 'DomainException: PA fixo: já tem dono no período. Encerre o vínculo atual antes (um dono por data).'], [isset($save(['pa_number' => '1725', 'employee_id' => 24, 'valid_from' => '2026-12-01'], $nextOwner)[0]['id']), $save(['pa_number' => '1725', 'employee_id' => 25, 'valid_from' => '2026-11-30'], $f103Db())[0]['erro']], 'vinculo: PA fixo aceita o proximo dono depois do fim do atual; no mesmo dia, 409');
assert_same(true, isset($save(['pa_number' => '1729', 'employee_id' => 24], $f103Db())[0]['id']), 'vinculo: PA flexivel com outros vinculos continua aceitando (conflito so avisa, F6)');
$futureOwner = $f103Db();
$futureOwner->assignments[15]['valid_from'] = '2026-12-01';
assert_same([true, 'DomainException: PA fixo: já tem dono no período. Encerre o vínculo atual antes (um dono por data).'], [isset($save(['pa_number' => '1725', 'employee_id' => 24, 'valid_until' => '2026-11-30'], $futureOwner)[0]['id']), $save(['pa_number' => '1725', 'employee_id' => 25, 'valid_until' => '2026-12-01'], $futureOwner)[0]['erro']], 'vinculo: PA fixo aceita um dono que termina antes do proximo; terminando no 1o dia dele, 409');

// Inventario: validacao, as tres travas e a gravacao.
$seatSave = static function (array $data, ?QaPaPdo $pdo = null) use ($f103Db, $today): array {
    $pdo ??= $f103Db();
    try {
        return [(new PaMapService($pdo, $today))->saveSeat($data, 'qa.lider'), $pdo];
    } catch (Throwable $error) {
        return [['erro' => get_class($error) . ': ' . $error->getMessage()], $pdo];
    }
};
assert_same([
    'InvalidArgumentException: Tipo do PA inválido: use flexível ou fixo.',
    'InvalidArgumentException: O Híbrido usa PA fixo por pessoa (vínculo do Mapa PA): a reserva por equipe é só do Crítico.',
    'InvalidArgumentException: Reserva só em PA fixo.',
    'InvalidArgumentException: Empréstimo só em PA fixo sem reserva: o dono ausente empresta o PA ao grupo comum.',
    'InvalidArgumentException: PA não encontrado no inventário.',
    'DomainException: PA com 2 vínculo(s) vigente(s) ou futuro(s): encerre antes de reservar ao Crítico.',
    'DomainException: PA com 2 preferência(s): retire antes de reservar ao Crítico.',
    'DomainException: PA com 1 par(es) de vínculos sobrepostos de hoje em diante: deixe um dono por data antes de marcar como fixo.',
], [
    $seatSave(['pa_number' => '1730', 'seat_type' => 'misto'])[0]['erro'],
    $seatSave(['pa_number' => '1730', 'seat_type' => 'fixed', 'reserved_team' => 'hibrido'])[0]['erro'],
    $seatSave(['pa_number' => '1730', 'seat_type' => 'flexible', 'reserved_team' => 'critico'])[0]['erro'],
    $seatSave(['pa_number' => '1730', 'seat_type' => 'flexible', 'can_lend' => true])[0]['erro'],
    $seatSave(['pa_number' => '1999', 'seat_type' => 'fixed'])[0]['erro'],
    $seatSave(['pa_number' => '1729', 'seat_type' => 'fixed', 'reserved_team' => 'critico'])[0]['erro'],
    $seatSave(['pa_number' => '1732', 'seat_type' => 'fixed', 'reserved_team' => 'critico'], (static function () use ($f103Db): QaPaPdo { $pdo = $f103Db(); unset($pdo->assignments[1], $pdo->assignments[2]); return $pdo; })())[0]['erro'],
    $seatSave(['pa_number' => '1724', 'seat_type' => 'fixed'])[0]['erro'],
], 'inventario: tipo, Hibrido (D-F10-19 c), reserva e emprestimo validados; reservar exige PA sem vinculo nem preferencia; fixo exige um dono por data');
[$fixed, $pdo] = $seatSave(['pa_number' => '1729', 'seat_type' => 'fixed', 'can_lend' => true], (static function () use ($f103Db): QaPaPdo { $pdo = $f103Db(); $pdo->assignments[7]['valid_until'] = '2026-11-17'; return $pdo; })());
assert_same([['seat_type', 'can_lend'], ['fixed', 1, null], ['begin', 'commit']], [$fixed['changed'], array_values(array_diff_key($pdo->seats['1729'], ['id' => 1])), $pdo->transactions], 'inventario: fixo que empresta gravado (vinculo encerrado nao conta), numa transacao');
[$same, $pdo] = $seatSave(['pa_number' => '1725', 'seat_type' => 'fixed', 'can_lend' => true]);
assert_same([[], []], [$same['changed'], $pdo->written], 'inventario: sem mudanca, nada e gravado');
[$release, $pdo] = $seatSave(['pa_number' => '1723', 'seat_type' => 'flexible']);
assert_same([['seat_type', 'reserved_team'], 'flexible', null], [$release['changed'], $pdo->seats['1723']['seat_type'], $pdo->seats['1723']['reserved_team']], 'inventario: tirar a reserva volta o PA a flexivel');

// Preferencias: so o grupo comum; PA reservado nao tem; troca o conjunto.
$prefSave = static function (array $data, ?QaPaPdo $pdo = null) use ($f103Db, $today): array {
    $pdo ??= $f103Db();
    try {
        return [(new PaMapService($pdo, $today))->savePreferences($data, 'qa.lider'), $pdo];
    } catch (Throwable $error) {
        return [['erro' => get_class($error) . ': ' . $error->getMessage()], $pdo];
    }
};
assert_same([
    'DomainException: PA reservado ao Crítico não tem preferência.',
    'DomainException: 1 colaborador(es) fora do grupo comum (N1 e N2): a preferência só vale na distribuição do grupo comum.',
    'DomainException: 1 colaborador(es) fora do grupo comum (N1 e N2): a preferência só vale na distribuição do grupo comum.',
    'DomainException: 1 colaborador(es) fora do grupo comum (N1 e N2): a preferência só vale na distribuição do grupo comum.',
    'InvalidArgumentException: Colaborador ativo inválido.',
    'InvalidArgumentException: Preferências: colaboradores inválidos ou repetidos.',
    'InvalidArgumentException: Preferências: de 0 a 20 colaboradores por PA.',
], [
    $prefSave(['pa_number' => '1723', 'employee_ids' => [1]])[0]['erro'],
    $prefSave(['pa_number' => '1730', 'employee_ids' => [1, 30]])[0]['erro'],
    $prefSave(['pa_number' => '1730', 'employee_ids' => [8]])[0]['erro'],
    $prefSave(['pa_number' => '1730', 'employee_ids' => [23]])[0]['erro'],
    $prefSave(['pa_number' => '1730', 'employee_ids' => [10]])[0]['erro'],
    $prefSave(['pa_number' => '1730', 'employee_ids' => [5, 5]])[0]['erro'],
    $prefSave(['pa_number' => '1730', 'employee_ids' => range(1, 21)])[0]['erro'],
], 'preferencias: reservado, Critico, Lideranca e fora do Mapa (409), inativo, repetido e mais de 20 (400)');
$separated = $f103Db();
$separated->employees[6]['grupo_escala'] = 'separado';
assert_same('DomainException: 1 colaborador(es) fora do grupo comum (N1 e N2): a preferência só vale na distribuição do grupo comum.', $prefSave(['pa_number' => '1730', 'employee_ids' => [6]], $separated)[0]['erro'], 'preferencias: N2 no grupo separado tambem fica de fora (D-F9-12)');
[$swap, $pdo] = $prefSave(['pa_number' => '1732', 'employee_ids' => ['5', 6]]);
assert_same([[6], [2], 2, [[1, 5], [1, 6]], ['begin', 'commit']], [$swap['added'], $swap['removed'], $swap['total'], $pdo->preferences, $pdo->transactions], 'preferencias: troca o conjunto (inclui 6, retira 2) numa transacao');
[$clear, $pdo] = $prefSave(['pa_number' => '1732', 'employee_ids' => []]);
assert_same([[], [2, 5], []], [$clear['added'], $clear['removed'], $pdo->preferences], 'preferencias: lista vazia retira todas');

// ----------------------------------------------------------------------------
// 7. [8.8] Papel do dia do Critico numa fonte so (engineSchedule): a mesma
// pessoa igual na Escala, no Calendario, nos relatorios, na Visao operacional
// (PresenceService) e no Mapa PA, em 18/11 (quarta). Alem da massa do F10-3:
// 38 regra presencial com troca para Abertura home (papel remoto vence a
// regra presencial); 39 regra presencial com troca para Presencial A e
// excecao remota (a excecao vence o papel presencial); 40 regra presencial,
// troca para Presencial A, excecao presencial e ausencia do dia todo (a
// ausencia vence tudo).
// ----------------------------------------------------------------------------
$crossDb = static function () use ($f103Db): QaPaPdo {
    $pdo = $f103Db();
    $pdo->cutoff = '2026-11-16';
    // Ordem do papel diferente do ID (a mesma ordem relativa da massa do F10-3).
    foreach ($pdo->roles as $index => $role) {
        $pdo->roles[$index]['sort_order'] = 10 * $role['sort_order'];
    }
    foreach ([38 => 'Critico Lambda', 39 => 'Critico Mi', 40 => 'Critico Ni'] as $id => $nome) {
        $pdo->employees[$id] = ['nome' => $nome, 'equipe' => 'critico', 'ativo' => 1, 'arquivado_em' => null, 'fora_mapa_escala' => 0];
        $pdo->rules[100 + $id * 10] = ['employee_id' => $id, 'rule_type' => 'always_onsite', 'rule_config' => null, 'effective_from' => '2026-01-05', 'effective_until' => null];
    }
    $pdo->overrides[] = ['id' => 3, 'employee_id' => 38, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'role_id' => 3, 'reason' => 'troca'];
    $pdo->overrides[] = ['id' => 4, 'employee_id' => 39, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'role_id' => 1, 'reason' => 'troca'];
    $pdo->overrides[] = ['id' => 5, 'employee_id' => 40, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'role_id' => 1, 'reason' => 'troca'];
    $pdo->exceptions[239] = ['employee_id' => 39, 'exception_date' => '2026-11-18', 'exception_type' => 'remote'];
    $pdo->exceptions[240] = ['employee_id' => 40, 'exception_date' => '2026-11-18', 'exception_type' => 'onsite'];
    $pdo->absences[] = ['employee_id' => 40, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-18', 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => 'folga', 'reason' => 'observacao ficticia'];
    return $pdo;
};
$criticalIds = range(30, 40);
$pick = static function (array $rows, string $idKey, string $valueKey) use ($criticalIds): array {
    $out = [];
    foreach ($rows as $row) {
        if (in_array((int)$row[$idKey], $criticalIds, true)) {
            $out[(int)$row[$idKey]] = $row[$valueKey];
        }
    }
    ksort($out);
    return $out;
};
$expected = [30 => 'onsite', 31 => 'onsite', 32 => 'onsite', 33 => 'absence', 34 => 'onsite', 35 => 'onsite', 36 => 'absence', 37 => 'onsite', 38 => 'remote', 39 => 'remote', 40 => 'absence'];
$period = ['from' => '2026-11-18', 'to' => '2026-11-18'];
$admin = ['role' => 'admin', 'employee_id' => null];
$escala = (new OperationalService($crossDb(), $today))->scheduleData($period)['generated'];
$calendar = array_values(array_filter((new OperationalService($crossDb(), $today))->listCalendar($period + ['type' => 'schedule'], $admin)['items'], static fn (array $event): bool => $event['event_type'] === 'schedule'));
$report = (new OperationalService($crossDb(), $today))->report($period)['schedule'];
$mapDays = (new OperationalService($crossDb(), $today))->mapPresence('2026-11-18', '2026-11-18', $admin)['days'];
$situacao = ['presencial' => 'onsite', 'remoto' => 'remote', 'ausente' => 'absence'];
$now = new DateTimeImmutable('2026-11-18 10:00:00', new DateTimeZone('America/Sao_Paulo'));
$operational = array_map(static fn (array $row): string => $situacao[$row['situacao']] ?? $row['situacao'], array_intersect_key((new PresenceService($crossDb()))->technicianPresence($now, [], $admin), array_flip($criticalIds)));
ksort($operational);
assert_same($expected, $pick($escala, 'employee_id', 'presence_type'), 'Escala: papel presencial vence a regra remota (31, 35), papel remoto vence a regra presencial (38), excecao vence o papel (37, 39), ausencia vence tudo (36, 40)');
assert_same($expected, $pick($calendar, 'employee_id', 'status'), 'Calendario: o mesmo dia da Escala para cada pessoa do Critico');
assert_same($expected, $pick($report, 'employee_id', 'presence_type'), 'relatorios: o mesmo dia da Escala para cada pessoa do Critico');
assert_same($expected, $operational, 'Visao operacional (PresenceService, 10:00): o mesmo dia da Escala para cada pessoa do Critico');
assert_same($expected, $pick($mapDays, 'employee_id', 'presence_type'), 'Mapa PA (mapPresence): o mesmo dia da Escala para cada pessoa do Critico');
$crossView = $list([], $admin, true, $crossDb());
$occupants = array_filter(array_column(array_filter($crossView['pas'], static fn (array $pa): bool => array_key_exists('reserved_occupant', $pa)), 'reserved_occupant'));
$seated = array_merge(array_column($occupants, 'employee_id'), array_column($crossView['critical_seating']['without_seat'], 'employee_id'));
sort($seated);
assert_same([array_keys(array_filter($expected, static fn (string $type): bool => $type === 'onsite')), 6], [$seated, $crossView['critical_seating']['onsite']], 'Mapa PA: os presenciais do Critico nos reservados (ocupantes e sem assento) sao os presenciais da Escala');
assert_same(
    [30 => 'rule', 31 => 'role', 32 => 'role', 33 => 'absence', 34 => 'exception', 35 => 'role', 36 => 'absence', 37 => 'exception', 38 => 'role', 39 => 'exception', 40 => 'absence'],
    $pick($escala, 'employee_id', 'source'),
    'Escala: origem "role" quando o papel decide; excecao e ausencia continuam com a propria origem'
);
$byId = array_column($calendar, null, 'employee_id');
$mapById = array_column($mapDays, null, 'employee_id');
assert_same(
    ['Papel do dia: Abertura home 07:00–17:00', 'Exceção de escala', 'Sempre presencial', ['id' => 3, 'sort_order' => 30, 'label' => 'Abertura home 07:00–17:00'], ['id' => 1, 'sort_order' => 10, 'label' => 'Presencial A 08:00–17:00'], false],
    [$byId[38]['description'], $byId[39]['description'], $byId[30]['description'], $mapById[38]['papel'], $mapById[39]['papel'], array_key_exists('papel', $mapById[30])],
    'Calendario com o rotulo do papel; mapPresence com o papel do dia (id, ordem e rotulo) so de quem tem papel, mesmo quando a excecao decide'
);
assert_same([false, false], [array_key_exists('papel', array_column($escala, null, 'employee_id')[38]), array_key_exists('papel', array_column($report, null, 'employee_id')[38])], 'Escala e relatorios nao levam o papel (so o mapPresence, para a ordem dos reservados)');
// Dois dias: o papel de cada dia (32: Abertura home no rodizio em 17/11, troca para Presencial B em 18/11; regra presencial).
$twoDays = array_values(array_filter((new OperationalService($crossDb(), $today))->scheduleData(['from' => '2026-11-17', 'to' => '2026-11-18'])['generated'], static fn (array $row): bool => $row['employee_id'] === 32));
assert_same([['2026-11-17', 'remote', 'role'], ['2026-11-18', 'onsite', 'role']], array_map(static fn (array $row): array => [$row['date'], $row['presence_type'], $row['source']], $twoDays), 'Escala em dois dias: o papel de cada dia (rodizio remoto em 17/11 vence a regra presencial; troca presencial em 18/11)');
// Empate na ordem do papel (Presencial A e B com a mesma ordem): desempata o ID do papel, nao o nome.
$tie = $crossDb();
$tie->roles[1]['sort_order'] = $tie->roles[0]['sort_order'];
$tie->employees[31]['nome'] = 'Critico Zz';
$tieView = array_column($list([], $admin, true, $tie)['pas'], null, 'pa_number');
assert_same([31, 35], [$tieView['1723']['reserved_occupant']['employee_id'], $tieView['1722']['reserved_occupant']['employee_id']], 'Mapa PA: papeis com a mesma ordem desempatam pelo ID do papel (vindo do mapPresence), depois pelo nome');
$paSource = (string)file_get_contents($root . '/services/PaMapService.php');
assert_same([0, 0, 0], [substr_count($paSource, 'PresenceRepository'), substr_count($paSource, 'criticalRole'), substr_count($paSource, 'criticalOnsite')], 'Mapa PA: uma fonte so (D-F10-36 retirada): nao le papel nem corrige o dia do motor');

echo "QA PA conflicts OK ({$checks} verificacoes)\n";
