<?php
declare(strict_types=1);

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
            $employees[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['nome'],
                'team' => validate_funcionario_equipe((string)$row['equipe']),
                'work_start' => $row['jornada_entrada'],
                'work_end' => $row['jornada_saida'],
                'lunch_start' => $row['almoco_inicio'],
                'lunch_end' => $row['almoco_fim'],
                'outside_map' => (int)$row['fora_mapa_escala'] === 1,
            ];
        }
        return $employees;
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
