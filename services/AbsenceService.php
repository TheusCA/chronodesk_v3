<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/AbsenceRules.php';

/**
 * Ausencias do RF-04 (Parte B, F3; desenho, secoes 3.3 e 9.4), sobre a tabela
 * da migration 012.
 *
 * Leitura: gestao (gestor, Lideranca, admin) ve todas; tecnico e somente
 * leitura, so as proprias, pelo colaborador da sessao (SEC-07). A observacao
 * (coluna reason, "note" na API) so sai nesta lista (decisao 4).
 * Escrita (ausencias.manage): o endpoint nega por perfil antes de chegar aqui.
 * Retroativo (inicio anterior a hoje, editar ou cancelar ausencia ja
 * iniciada): ausencias.retroativa (Lideranca e admin, L6). Ausencia de quem e
 * da Lideranca, ou tem perfil Lideranca ou admin: so admin (A4).
 * Concorrencia: o colaborador e travado (FOR UPDATE) antes de ler as ausencias
 * dele, e toda leitura que decide a gravacao depois da trava e com trava
 * (licao do F1: no REPEATABLE READ, a leitura simples usaria o snapshot fixado
 * antes da trava).
 * Auditoria: IDs, datas, periodo e categoria; nunca o texto da observacao.
 */
final class AbsenceService {
    public const LIST_LIMIT = 500;
    private const TEAMS = ['n1', 'n2', 'lideranca'];
    private const STATUS_FILTERS = ['approved', 'cancelled', 'all'];

    private PDO $pdo;
    private ?string $today;
    private ?DateTimeImmutable $now;

    /** $today e $now fixos so para teste; padrao e o relogio do PHP (America/Sao_Paulo). */
    public function __construct(?PDO $pdo = null, ?string $today = null, ?DateTimeImmutable $now = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->today = $today;
        $this->now = $now;
    }

    /**
     * GET: periodo (padrao: mes corrente; ate 366 dias), team, employee_id e
     * status (approved padrao, cancelled, all).
     */
    public function list(array $filters, array $actor): array {
        [$from, $to] = $this->period($filters);
        $status = $this->enumFilter($filters['status'] ?? null, self::STATUS_FILTERS, 'approved', 'Status invalido.');
        $team = $this->enumFilter($filters['team'] ?? null, self::TEAMS, null, 'Equipe invalida.');
        $role = $actor['role'] ?? null;
        $manager = portal_role_is_manager($role);
        $employeeId = $manager
            ? $this->optionalId($filters['employee_id'] ?? null)
            : (int)($actor['employee_id'] ?? 0);
        $base = [
            'period' => ['from' => $from, 'to' => $to],
            'can_manage' => portal_role_can($role, 'ausencias.manage'),
            'can_retroactive' => portal_role_can($role, 'ausencias.retroativa'),
            'categories' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(AbsenceRules::CATEGORIES),
                AbsenceRules::CATEGORIES
            ),
        ];
        if (!$manager && $employeeId < 1) {
            return $base + db_limit_rows([], self::LIST_LIMIT);
        }
        $sql = 'SELECT id, employee_id, employee_name, team, category, starts_on, ends_on, period_type,
                       start_time, end_time, reason AS note, status, retroactive, created_by, created_at,
                       updated_by, updated_at, cancelled_by, cancelled_at
                FROM portal_absences
                WHERE starts_on <= :date_to AND ends_on >= :date_from';
        $params = [':date_from' => $from, ':date_to' => $to];
        if ($status !== 'all') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        if ($team !== null) {
            $sql .= ' AND team = :team';
            $params[':team'] = $team;
        }
        if ($employeeId) {
            $sql .= ' AND employee_id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY starts_on DESC, id DESC LIMIT ' . (self::LIST_LIMIT + 1));
        $stmt->execute($params);
        return $base + db_limit_rows($stmt->fetchAll(), self::LIST_LIMIT);
    }

    /** POST create. Devolve ['id' => int, 'retroactive' => bool]. */
    public function create(array $data, array $actor): array {
        $absence = AbsenceRules::normalize($data);
        $retroactive = AbsenceRules::startsBeforeToday($absence, $this->today());
        $this->assertRetroactiveAllowed($retroactive, $actor);
        return $this->atomic(function () use ($absence, $retroactive, $actor): array {
            $employee = $this->lockEmployee($absence['employee_id']);
            $this->assertEmployeeAllowed($employee, $actor);
            AbsenceRules::assertWithinWorkday($absence, $employee['jornada_entrada'], $employee['jornada_saida']);
            $this->assertNoConflict($absence, $employee, null);
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_absences
                    (employee_id, employee_name, team, category, starts_on, ends_on, period_type, start_time,
                     end_time, reason, status, retroactive, created_by)
                 VALUES
                    (:employee_id, :employee_name, :team, :category, :starts_on, :ends_on, :period_type, :start_time,
                     :end_time, :reason, :status, :retroactive, :created_by)'
            );
            $stmt->execute($this->absenceParams($absence) + [
                ':employee_id' => $absence['employee_id'],
                ':employee_name' => $employee['nome'],
                ':team' => $employee['team'],
                ':status' => 'approved',
                ':retroactive' => $retroactive ? 1 : 0,
                ':created_by' => $this->username($actor),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->audit('ABSENCE_CREATED', $retroactive ? 'WARNING' : 'INFO', $id, $absence, $retroactive, $actor);
            return ['id' => $id, 'retroactive' => $retroactive];
        });
    }

    /** POST update: mesmos campos e id; o colaborador nao muda. */
    public function update(array $data, array $actor): array {
        $id = $this->requiredId($data['id'] ?? null);
        $absence = AbsenceRules::normalize($data);
        $startsBeforeToday = AbsenceRules::startsBeforeToday($absence, $this->today());
        $this->assertRetroactiveAllowed($startsBeforeToday, $actor);
        return $this->atomic(function () use ($id, $absence, $startsBeforeToday, $actor): array {
            [$current, $employee] = $this->lockAbsence($id);
            // Autorizacao (A4) antes de qualquer outra resposta sobre o registro.
            $this->assertEmployeeAllowed($employee, $actor);
            if ((int)$current['employee_id'] !== $absence['employee_id']) {
                throw new InvalidArgumentException('Nao e possivel trocar o colaborador da ausencia: cancele e lance de novo.');
            }
            if ($current['status'] !== 'approved') {
                throw new DomainException('Ausencia cancelada nao pode ser alterada.');
            }
            $retroactive = $startsBeforeToday || AbsenceRules::hasStarted($current, $this->today());
            $this->assertRetroactiveAllowed($retroactive, $actor);
            AbsenceRules::assertWithinWorkday($absence, $employee['jornada_entrada'], $employee['jornada_saida']);
            $this->assertNoConflict($absence, $employee, $id);
            $stmt = $this->pdo->prepare(
                'UPDATE portal_absences
                 SET category = :category, starts_on = :starts_on, ends_on = :ends_on, period_type = :period_type,
                     start_time = :start_time, end_time = :end_time, reason = :reason,
                     retroactive = GREATEST(retroactive, :retroactive), updated_by = :updated_by
                 WHERE id = :id AND status = :status'
            );
            $stmt->execute($this->absenceParams($absence) + [
                ':retroactive' => $retroactive ? 1 : 0,
                ':updated_by' => $this->username($actor),
                ':id' => $id,
                ':status' => 'approved',
            ]);
            $this->audit('ABSENCE_UPDATED', 'WARNING', $id, $absence, $retroactive, $actor, $current);
            return ['id' => $id, 'retroactive' => $retroactive];
        });
    }

    /** POST cancel: o registro fica, com status cancelled e autoria. */
    public function cancel(array $data, array $actor): array {
        $id = $this->requiredId($data['id'] ?? null);
        return $this->atomic(function () use ($id, $actor): array {
            [$current, $employee] = $this->lockAbsence($id);
            $this->assertEmployeeAllowed($employee, $actor);
            if ($current['status'] !== 'approved') {
                throw new DomainException('Ausencia ja cancelada.');
            }
            $retroactive = AbsenceRules::hasStarted($current, $this->today());
            $this->assertRetroactiveAllowed($retroactive, $actor);
            $stmt = $this->pdo->prepare(
                'UPDATE portal_absences
                 SET status = :cancelled, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at,
                     retroactive = GREATEST(retroactive, :retroactive), updated_by = :updated_by
                 WHERE id = :id AND status = :status'
            );
            $username = $this->username($actor);
            $stmt->execute([
                ':cancelled' => 'cancelled',
                ':cancelled_by' => $username,
                ':cancelled_at' => $this->now()->format('Y-m-d H:i:s'),
                ':retroactive' => $retroactive ? 1 : 0,
                ':updated_by' => $username,
                ':id' => $id,
                ':status' => 'approved',
            ]);
            $this->audit('ABSENCE_CANCELLED', 'WARNING', $id, $current, $retroactive, $actor);
            return ['id' => $id, 'retroactive' => $retroactive];
        });
    }

    /**
     * Ausencia e colaborador travados, nesta ordem de trava: colaborador e
     * depois a ausencia (a mesma ordem do create). A primeira leitura so
     * descobre o colaborador; a que decide e a com trava.
     */
    private function lockAbsence(int $id): array {
        $found = $this->findAbsence($id, false);
        if ($found === null) {
            throw new DomainException('Ausencia nao encontrada.');
        }
        $employee = $this->lockEmployee((int)$found['employee_id'], true);
        $current = $this->findAbsence($id, true);
        if ($current === null || (int)$current['employee_id'] !== (int)$found['employee_id']) {
            throw new DomainException('Ausencia nao encontrada.');
        }
        return [$current, $employee];
    }

    private function findAbsence(int $id, bool $lock): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, employee_id, category, starts_on, ends_on, period_type, start_time, end_time, status, retroactive
             FROM portal_absences WHERE id = :id' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Colaborador ativo, travado ate o fim da transacao. $includeInactive: a
     * edicao e o cancelamento de ausencia de quem foi desativado continuam
     * (o F4 cancela as futuras no arquivamento); o lancamento novo, nao.
     */
    private function lockEmployee(int $employeeId, bool $includeInactive = false): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, equipe, access_role, jornada_entrada, jornada_saida, ativo
             FROM funcionarios WHERE id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        if (!$employee || (!$includeInactive && (int)$employee['ativo'] !== 1)) {
            throw new InvalidArgumentException('Colaborador nao encontrado ou inativo.');
        }
        $employee['team'] = validate_funcionario_equipe((string)$employee['equipe']);
        if (!in_array($employee['team'], self::TEAMS, true)) {
            throw new InvalidArgumentException('A equipe do cadastro nao recebe ausencia.');
        }
        return $employee;
    }

    private function assertNoConflict(array $absence, array $employee, ?int $ignoreId): void {
        $stmt = $this->pdo->prepare(
            'SELECT id, starts_on, ends_on, period_type, start_time, end_time, status
             FROM portal_absences
             WHERE employee_id = :employee_id AND status = :status
               AND starts_on <= :date_to AND ends_on >= :date_from
             FOR UPDATE'
        );
        $stmt->execute([
            ':employee_id' => (int)$employee['id'],
            ':status' => 'approved',
            ':date_from' => $absence['starts_on'],
            ':date_to' => $absence['ends_on'],
        ]);
        $conflict = AbsenceRules::findConflict($absence, $stmt->fetchAll(), $employee['jornada_saida'], $ignoreId);
        if ($conflict !== null) {
            throw new DomainException(AbsenceRules::conflictMessage($conflict));
        }
    }

    private function assertEmployeeAllowed(array $employee, array $actor): void {
        $denied = absence_change_denied((string)($actor['role'] ?? ''), $employee['team'], $employee['access_role'] ?? null);
        if ($denied !== null) {
            throw new PortalForbiddenException($denied[1]);
        }
    }

    private function assertRetroactiveAllowed(bool $retroactive, array $actor): void {
        if ($retroactive && !portal_role_can($actor['role'] ?? null, 'ausencias.retroativa')) {
            throw new PortalForbiddenException('Somente administradores e Liderança lançam, editam ou cancelam ausência com início anterior a hoje ou já iniciada.');
        }
    }

    private function absenceParams(array $absence): array {
        return [
            ':category' => $absence['category'],
            ':starts_on' => $absence['starts_on'],
            ':ends_on' => $absence['ends_on'],
            ':period_type' => $absence['period_type'],
            ':start_time' => $absence['start_time'],
            ':end_time' => $absence['end_time'],
            ':reason' => $absence['note'],
        ];
    }

    /** Auditoria sem o texto da observacao (decisao 4). */
    private function audit(string $action, string $severity, int $id, array $absence, bool $retroactive, array $actor, ?array $before = null): void {
        if (!function_exists('audit_log')) {
            return;
        }
        $details = 'Usuario=' . $this->username($actor)
            . '; perfil=' . ($actor['role'] ?? '')
            . '; ausencia_id=' . $id
            . '; employee_id=' . (int)($absence['employee_id'] ?? $before['employee_id'] ?? 0)
            . '; periodo=' . AbsenceRules::periodLabel($absence)
            . '; categoria=' . ($absence['category'] ?? '')
            . '; retroativa=' . ($retroactive ? 'sim' : 'nao');
        if ($before !== null) {
            $details .= '; antes=' . AbsenceRules::periodLabel($before) . ' ' . ($before['category'] ?? '');
        }
        audit_log($action, $details, $severity);
    }

    private function period(array $filters): array {
        $today = $this->today();
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $from = ($from === null || $from === '') ? substr($today, 0, 8) . '01' : $this->filterDate($from, 'Data inicial invalida.');
        $to = ($to === null || $to === '')
            ? (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d')
            : $this->filterDate($to, 'Data final invalida.');
        if ($to < $from) {
            throw new InvalidArgumentException('A data final deve ser igual ou posterior a inicial.');
        }
        if ((int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1 > AbsenceRules::MAX_DAYS) {
            throw new InvalidArgumentException('O periodo maximo e de ' . AbsenceRules::MAX_DAYS . ' dias.');
        }
        return [$from, $to];
    }

    private function filterDate($value, string $message): string {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private function enumFilter($value, array $allowed, ?string $default, string $message): ?string {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private function optionalId($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->requiredId($value);
    }

    private function requiredId($value): int {
        $valid = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1);
        if (!$valid || (int)$value < 1) {
            throw new InvalidArgumentException('Identificador invalido.');
        }
        return (int)$value;
    }

    private function username(array $actor): string {
        return (string)($actor['username'] ?? 'anonymous');
    }

    private function today(): string {
        return $this->today ?? date('Y-m-d');
    }

    private function now(): DateTimeImmutable {
        return $this->now ?? new DateTimeImmutable('now');
    }

    private function atomic(callable $callback) {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
