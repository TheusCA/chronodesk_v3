<?php
declare(strict_types=1);

require_once __DIR__ . '/PauseRules.php';
require_once __DIR__ . '/CriticalRolePlanner.php';

/**
 * Carga em lote para o motor de presenca (Parte B, F2; desenho, secao 4.6).
 *
 * Quatro consultas por chamada, independentemente do numero de
 * colaboradores e de dias: funcionarios, regras que cruzam o intervalo,
 * excecoes do intervalo e ausencias aprovadas que cruzam o intervalo. Sem
 * NOW()/CURRENT_DATE (a data vem do PHP) e sem a observacao das excecoes nem
 * a das ausencias (dado que o motor nao usa nao e lido). A categoria da
 * ausencia e lida para a P9 (F3): o servico so a repassa a quem pode ve-la.
 */
final class PresenceRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Ativos, com a equipe normalizada. $employeeId restringe a um; o filtro
     * de equipe e feito por quem chama, sobre a equipe normalizada.
     */
    public function employees(?int $employeeId = null): array {
        $sql = 'SELECT id, nome, equipe, jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, fora_mapa_escala
                FROM funcionarios
                WHERE ativo = 1';
        $params = [];
        if ($employeeId !== null) {
            $sql .= ' AND id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY nome, id');
        $stmt->execute($params);
        $employees = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $team = validate_funcionario_equipe((string)$row['equipe']);
            $employees[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['nome'],
                'team' => $team,
                'work_start' => $row['jornada_entrada'],
                'work_end' => $row['jornada_saida'],
                'lunch_start' => $row['almoco_inicio'],
                'lunch_end' => $row['almoco_fim'],
                // [F9, 11.3] Critico: o almoco vem da pausa "Almoço", nao do intervalo do cadastro.
                'lunch_source' => PauseRules::lunchSource($team),
                'outside_map' => (int)$row['fora_mapa_escala'] === 1,
            ];
        }
        return $employees;
    }

    /**
     * [F9, 9.15.6] Jornada do dia: a unica fonte que o motor le. Sem papel do
     * dia, a do cadastro; [9.16] com papel do Critico (troca pontual ou
     * rodizio, de criticalRoleDays), a jornada do papel e o papel, que o motor
     * le como a escala do dia (origem 'papel'). Funcao pura sobre o cadastro
     * carregado.
     */
    public static function shiftFor(array $employee, string $date, ?array $role = null): array {
        if ($role === null) {
            return $employee;
        }
        return ['work_start' => $role['work_start'], 'work_end' => $role['work_end'], 'critical_role' => $role] + $employee;
    }

    /**
     * [9.16, D-PC-4] Papeis, rodizios e trocas que cruzam [de, ate], em tres
     * consultas, so quando ha cadastro do Critico em $employees (lista com
     * 'id' e 'team' normalizada): sem Critico, nenhuma consulta. Falha de
     * leitura: null (sem papel; a jornada fica a do cadastro), com log.
     */
    public function criticalRoleContext(array $employees, string $from, string $to): ?array {
        $ids = [];
        foreach ($employees as $employee) {
            if (($employee['team'] ?? null) === PortalTeams::CRITICAL) {
                $ids[] = (int)$employee['id'];
            }
        }
        if ($ids === []) {
            return null;
        }
        try {
            $roles = [];
            foreach ($this->pdo->query('SELECT id, name, presence_type, work_start, work_end, active, sort_order FROM portal_critical_roles ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $roles[(int)$row['id']] = CriticalRolePlanner::roleView($row);
            }
            $stmt = $this->pdo->prepare('SELECT id, effective_from, period, anchor_date, roles, employees FROM portal_critical_role_rotations WHERE effective_from <= :date_to ORDER BY effective_from');
            $stmt->execute([':date_to' => $to]);
            $rotations = array_map([CriticalRolePlanner::class, 'rotationView'], $stmt->fetchAll(PDO::FETCH_ASSOC));
            $stmt = $this->pdo->prepare('SELECT id, employee_id, starts_on, ends_on, role_id, reason FROM portal_critical_role_overrides WHERE starts_on <= :date_to AND ends_on >= :date_from ORDER BY employee_id, starts_on');
            $stmt->execute([':date_from' => $from, ':date_to' => $to]);
            $overrides = array_map([CriticalRolePlanner::class, 'overrideView'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log('[PRESENCA] Papeis do Critico indisponiveis; jornada pelo cadastro: ' . get_class($e));
            return null;
        }
        return ['employee_ids' => $ids, 'roles' => $roles, 'rotations' => $rotations, 'overrides' => $overrides];
    }

    /** Papel do dia por "employee_id:data" (so quem tem papel), a partir do contexto. */
    public static function criticalRoleDays(?array $context, string $from, string $to): array {
        if ($context === null) {
            return [];
        }
        $days = [];
        for ($date = $from; $date <= $to; $date = ScheduleRulePlanner::addDays($date, 1)) {
            foreach ($context['employee_ids'] as $id) {
                $role = CriticalRolePlanner::dayRole($id, $date, $context['rotations'], $context['overrides'], $context['roles']);
                if ($role !== null) {
                    $days[$id . ':' . $date] = $role;
                }
            }
        }
        return $days;
    }

    /** Regras que cruzam [de, ate], agrupadas por colaborador. */
    public function rulesByEmployee(string $from, string $to, ?int $employeeId = null): array {
        $sql = 'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until
                FROM portal_schedule_rules
                WHERE effective_from <= :date_to
                  AND (effective_until IS NULL OR effective_until >= :date_from)';
        $params = [':date_from' => $from, ':date_to' => $to];
        if ($employeeId !== null) {
            $sql .= ' AND employee_id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY employee_id, effective_from');
        $stmt->execute($params);
        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rules[(int)$row['employee_id']][] = [
                'id' => (int)$row['id'],
                'rule_type' => (string)$row['rule_type'],
                'rule_config' => $row['rule_config'],
                'effective_from' => (string)$row['effective_from'],
                'effective_until' => $row['effective_until'] === null ? null : (string)$row['effective_until'],
            ];
        }
        return $rules;
    }

    /** Excecoes de [de, ate], por "employee_id:data" (a UNIQUE garante uma por dia). */
    public function exceptionsByDay(string $from, string $to, ?int $employeeId = null): array {
        $sql = 'SELECT employee_id, exception_date, exception_type
                FROM portal_schedule_exceptions
                WHERE exception_date BETWEEN :date_from AND :date_to';
        $params = [':date_from' => $from, ':date_to' => $to];
        if ($employeeId !== null) {
            $sql .= ' AND employee_id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $exceptions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exceptions[(int)$row['employee_id'] . ':' . (string)$row['exception_date']] = [
                'exception_type' => (string)$row['exception_type'],
            ];
        }
        return $exceptions;
    }

    /**
     * Ausencias aprovadas que cruzam [de, ate], por colaborador, em ordem de
     * id (a primeira vale, secao 4.4), com o periodo do dia (F3).
     */
    public function absencesByEmployee(string $from, string $to, ?int $employeeId = null): array {
        $sql = 'SELECT id, employee_id, starts_on, ends_on, period_type, start_time, end_time, category
                FROM portal_absences
                WHERE status = \'approved\'
                  AND starts_on <= :date_to
                  AND ends_on >= :date_from';
        $params = [':date_from' => $from, ':date_to' => $to];
        if ($employeeId !== null) {
            $sql .= ' AND employee_id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);
        $absences = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $absences[(int)$row['employee_id']][] = [
                'starts_on' => (string)$row['starts_on'],
                'ends_on' => (string)$row['ends_on'],
                'period_type' => (string)$row['period_type'],
                'start_time' => $row['start_time'] === null ? null : (string)$row['start_time'],
                'end_time' => $row['end_time'] === null ? null : (string)$row['end_time'],
                'category' => (string)$row['category'],
            ];
        }
        return $absences;
    }
}
