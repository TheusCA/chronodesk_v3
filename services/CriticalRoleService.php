<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/PortalTeams.php';
require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/ScheduleRulePlanner.php';
require_once __DIR__ . '/CriticalRolePlanner.php';

/**
 * Rodizio de papeis do Critico (desenho principal, 9.16): papeis, rodizio com
 * vigencia e trocas pontuais. Nada e gravado por pessoa e dia: o papel do dia
 * e calculado (D-PC-2) e lido pelo motor pela jornada do dia (shiftFor).
 *
 * Permissao (D-F10-5, AD-PC-3): gravar exige escalas.manage (Lideranca e
 * admin); data anterior a hoje, escalas.retroativa. Auditoria so com IDs
 * (D-PC-5). Travas, sempre nesta ordem: os papeis (na gravacao do rodizio) e,
 * depois, os colaboradores em ordem de id.
 */
final class CriticalRoleService {
    public const NAME_MAX = 60;
    public const FORBIDDEN_MESSAGE = 'Apenas administradores e Liderança alteram os papéis do Crítico.';
    public const TEAM_CHANGE_MESSAGE = 'Este técnico está no rodízio de papéis do Crítico; tire-o do rodízio (versão nova) antes de mudar a equipe.';

    private PDO $pdo;
    private ?string $today;

    /** $today: data de hoje (Y-m-d) fixa, so para teste; padrao e a data do PHP. */
    public function __construct(?PDO $pdo = null, ?string $today = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->today = $today;
    }

    /**
     * Papeis, rodizios (todas as versoes), trocas (de hoje em diante; com
     * $withHistory, todas), o papel de hoje de cada pessoa ativa do Critico
     * (null sem papel) e, para o rodizio vigente e os futuros, quantas
     * pessoas da lista estao arquivadas ou fora do Critico (9.16.4: a tela
     * pede uma versao nova). So IDs de pessoas.
     */
    public function overview(bool $withHistory = false): array {
        $today = $this->today();
        $roles = $this->roles();
        $rotations = $this->rotations(false);
        $sql = 'SELECT id, employee_id, starts_on, ends_on, role_id, reason FROM portal_critical_role_overrides';
        $params = [];
        if (!$withHistory) {
            $sql .= ' WHERE ends_on >= :today';
            $params[':today'] = $today;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY starts_on, employee_id');
        $stmt->execute($params);
        $overrides = array_map([CriticalRolePlanner::class, 'overrideView'], $stmt->fetchAll());
        $critical = $this->activeCriticalIds();
        $current = CriticalRolePlanner::rotationAt($rotations, $today);
        $result = [];
        foreach ($rotations as $rotation) {
            $live = $rotation === $current || $rotation['effective_from'] > $today;
            $result[] = $rotation + [
                'is_current' => $rotation === $current,
                'invalid_people' => $live ? count(array_diff($rotation['employees'], $critical)) : 0,
            ];
        }
        $todayRoles = [];
        foreach ($critical as $employeeId) {
            $dayRole = CriticalRolePlanner::dayRole($employeeId, $today, $rotations, $overrides, $roles);
            $todayRoles[(string)$employeeId] = $dayRole === null ? null : ['role_id' => $dayRole['id'], 'source' => $dayRole['source']];
        }
        return [
            'today' => $today,
            'roles' => array_values($roles),
            'today_roles' => $todayRoles,
            'rotations' => $result,
            'overrides' => $overrides,
            'vacant_today' => CriticalRolePlanner::vacantRoleIds($rotations, $overrides, $today, $critical),
            'reasons' => CriticalRolePlanner::REASONS,
        ];
    }

    /** Cria ou altera o papel (nome, tipo, jornada, ativo, ordem). */
    public function saveRole(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $actor): array {
            $name = $this->name($data['name'] ?? null);
            $presence = $data['presence_type'] ?? null;
            if (!in_array($presence, CriticalRolePlanner::PRESENCE_TYPES, true)) {
                throw new InvalidArgumentException('Tipo de presença do papel: presencial ou remoto.');
            }
            $start = $data['work_start'] ?? null;
            $end = $data['work_end'] ?? null;
            if (!CriticalRolePlanner::validShift($start, $end)) {
                throw new InvalidArgumentException('Jornada do papel: início antes do fim, no mesmo dia (HH:MM).');
            }
            $active = !array_key_exists('active', $data) || filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
            $order = filter_var($data['sort_order'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
            if ($order === false) {
                throw new InvalidArgumentException('Ordem do papel: de 0 a 999.');
            }
            $roleId = $this->optionalId($data['role_id'] ?? null, 'Papel');
            // [D-ESC-13] Cor da paleta; ausente na edicao mantem a gravada (9.12.1).
            $colorSent = array_key_exists('color_token', $data);
            $color = CriticalRolePlanner::colorToken($colorSent ? $data['color_token'] : null);
            if ($color === false) {
                throw new InvalidArgumentException('Cor do papel: escolha uma da paleta.');
            }
            $this->assertNameFree($name, $roleId);
            $params = [':name' => $name, ':presence_type' => $presence, ':work_start' => $start . ':00', ':work_end' => $end . ':00', ':active' => $active ? 1 : 0, ':sort_order' => $order, ':updated_by' => $actor];
            if ($roleId === null) {
                if (!$active) {
                    throw new InvalidArgumentException('Papel novo começa ativo.');
                }
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_critical_roles (name, presence_type, work_start, work_end, active, sort_order, color_token, created_by, updated_by)
                     VALUES (:name, :presence_type, :work_start, :work_end, :active, :sort_order, :color_token, :created_by, :updated_by)'
                );
                $stmt->execute($params + [':color_token' => $color, ':created_by' => $actor]);
                $roleId = (int)$this->pdo->lastInsertId();
                $this->audit('PAPEL_CRITICO_CRIADO', "papel_id={$roleId}", 'INFO');
                return ['role_id' => $roleId, 'created' => true];
            }
            $this->lockRole($roleId);
            if (!$active && $this->activeRolesExcept($roleId) === 0) {
                throw new DomainException('Precisa ficar pelo menos 1 papel ativo.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE portal_critical_roles SET name = :name, presence_type = :presence_type, work_start = :work_start,
                 work_end = :work_end, active = :active, sort_order = :sort_order,' . ($colorSent ? ' color_token = :color_token,' : '') . ' updated_by = :updated_by WHERE id = :id'
            );
            $stmt->execute($params + [':id' => $roleId] + ($colorSent ? [':color_token' => $color] : []));
            $this->audit('PAPEL_CRITICO_ATUALIZADO', "papel_id={$roleId}; ativo=" . ($active ? 'sim' : 'nao'), 'INFO');
            return ['role_id' => $roleId, 'created' => false];
        });
    }

    /**
     * Previa do rodizio (sem gravar, 9.16.4): as 8 semanas a partir do inicio,
     * pessoa x dia util com o papel, e os papeis vagos de cada dia. Mesmas
     * validacoes e permissoes do salvar.
     */
    public function previewRotation(array $data, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $role): array {
            $rotation = $this->rotationRequest($data, $role);
            return [
                'effective_from' => $rotation['effective_from'],
                'replaces' => $this->rotationAtDate($rotation['effective_from']) !== null,
                'retroactive' => $rotation['effective_from'] < $this->today(),
                'extra_people' => max(0, count($rotation['employees']) - count($rotation['roles'])),
                'vacant_roles' => max(0, count($rotation['roles']) - count($rotation['employees'])),
                'preview' => CriticalRolePlanner::preview($rotation, $rotation['employees']),
            ];
        });
    }

    /** Grava a versao do rodizio (nova, ou correcao da que comeca na mesma data). */
    public function saveRotation(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $actor, $role): array {
            $rotation = $this->rotationRequest($data, $role);
            $existing = $this->rotationAtDate($rotation['effective_from']);
            $params = [
                ':period' => $rotation['period'],
                ':anchor_date' => $rotation['anchor_date'],
                ':roles' => json_encode($rotation['roles']),
                ':employees' => json_encode($rotation['employees']),
                ':updated_by' => $actor,
            ];
            if ($existing !== null) {
                $stmt = $this->pdo->prepare(
                    'UPDATE portal_critical_role_rotations SET period = :period, anchor_date = :anchor_date, roles = :roles,
                     employees = :employees, updated_by = :updated_by WHERE id = :id'
                );
                $stmt->execute($params + [':id' => $existing['id']]);
                $rotationId = $existing['id'];
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_critical_role_rotations (effective_from, period, anchor_date, roles, employees, created_by, updated_by)
                     VALUES (:effective_from, :period, :anchor_date, :roles, :employees, :created_by, :updated_by)'
                );
                $stmt->execute($params + [':effective_from' => $rotation['effective_from'], ':created_by' => $actor]);
                $rotationId = (int)$this->pdo->lastInsertId();
            }
            $this->audit('RODIZIO_PAPEIS_SALVO', "rodizio_id={$rotationId}; desde={$rotation['effective_from']}; periodo={$rotation['period']}; papeis=" . implode(',', $rotation['roles']) . '; pessoas=' . implode(',', $rotation['employees']) . '; retroativa=' . ($rotation['effective_from'] < $this->today() ? 'sim' : 'nao'), 'INFO');
            return ['rotation_id' => $rotationId, 'replaced' => $existing !== null];
        });
    }

    /** Troca pontual (AD-PC-3: so a gestao): pessoa ativa do Critico, papel ativo, sem sobrepor outra troca. */
    public function addOverride(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $actor, $role): array {
            $employeeId = $this->requiredId($data['employee_id'] ?? null, 'Colaborador');
            $start = $this->date($data['starts_on'] ?? null, 'Início da troca');
            $end = $this->date($data['ends_on'] ?? $start, 'Fim da troca');
            if ($end < $start) {
                throw new InvalidArgumentException('O fim da troca deve ser igual ou posterior ao início.');
            }
            if ((int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days >= CriticalRolePlanner::MAX_OVERRIDE_DAYS) {
                throw new InvalidArgumentException('Troca pontual: até ' . CriticalRolePlanner::MAX_OVERRIDE_DAYS . ' dias. Para mais, salve uma versão do rodízio.');
            }
            $reason = $data['reason'] ?? null;
            if (!in_array($reason, CriticalRolePlanner::REASONS, true)) {
                throw new InvalidArgumentException('Motivo da troca inválido.');
            }
            $this->assertRetroactiveAllowed($start, $role);
            $roleId = $this->requiredId($data['role_id'] ?? null, 'Papel');
            $roleRow = $this->lockRole($roleId);
            if ((int)$roleRow['active'] !== 1) {
                throw new DomainException('Papel desativado: escolha um papel ativo.');
            }
            $this->lockCriticalEmployee($employeeId);
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM portal_critical_role_overrides
                 WHERE employee_id = :employee_id AND starts_on <= :ends_on AND ends_on >= :starts_on FOR UPDATE'
            );
            $stmt->execute([':employee_id' => $employeeId, ':starts_on' => $start, ':ends_on' => $end]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new DomainException('Já existe troca para este técnico nesse período: remova-a antes.');
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_critical_role_overrides (employee_id, starts_on, ends_on, role_id, reason, created_by, updated_by)
                 VALUES (:employee_id, :starts_on, :ends_on, :role_id, :reason, :created_by, :updated_by)'
            );
            $stmt->execute([':employee_id' => $employeeId, ':starts_on' => $start, ':ends_on' => $end, ':role_id' => $roleId, ':reason' => $reason, ':created_by' => $actor, ':updated_by' => $actor]);
            $overrideId = (int)$this->pdo->lastInsertId();
            $this->audit('TROCA_PAPEL_CRITICO', "operacao=inclusao; troca_id={$overrideId}; employee_id={$employeeId}; papel_id={$roleId}; de={$start}; ate={$end}; motivo={$reason}", 'INFO');
            return ['override_id' => $overrideId];
        });
    }

    /** Remove a troca; troca que ja comecou exige escalas.retroativa. */
    public function removeOverride(array $data, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $role): array {
            $overrideId = $this->requiredId($data['override_id'] ?? null, 'Troca');
            $stmt = $this->pdo->prepare('SELECT id, employee_id, starts_on, ends_on FROM portal_critical_role_overrides WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $overrideId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new InvalidArgumentException('Troca não encontrada.');
            }
            $this->assertRetroactiveAllowed((string)$row['starts_on'], $role);
            $this->pdo->prepare('DELETE FROM portal_critical_role_overrides WHERE id = :id')->execute([':id' => $overrideId]);
            $this->audit('TROCA_PAPEL_CRITICO', "operacao=remocao; troca_id={$overrideId}; employee_id={$row['employee_id']}", 'INFO');
            return ['override_id' => $overrideId];
        });
    }

    /**
     * [9.16.4] Mudar a equipe de quem esta no rodizio vigente ou num futuro: a
     * mensagem do 409, ou null. Chamado com o cadastro travado (atualizar_funcionario).
     */
    public function teamChangeDenied(int $employeeId, string $currentTeam, string $newTeam): ?string {
        if ($currentTeam !== PortalTeams::CRITICAL || $newTeam === PortalTeams::CRITICAL) {
            return null;
        }
        $today = $this->today();
        $rotations = $this->rotations(false);
        $current = CriticalRolePlanner::rotationAt($rotations, $today);
        foreach ($rotations as $rotation) {
            $live = $rotation === $current || $rotation['effective_from'] > $today;
            if ($live && in_array($employeeId, $rotation['employees'], true)) {
                return self::TEAM_CHANGE_MESSAGE;
            }
        }
        return null;
    }

    /** Pedido do rodizio validado (previa e salvar), com os papeis travados. */
    private function rotationRequest(array $data, string $role): array {
        $from = $this->date($data['effective_from'] ?? null, 'Início do rodízio');
        $this->assertRetroactiveAllowed($from, $role);
        $period = $data['period'] ?? null;
        if (!in_array($period, CriticalRolePlanner::PERIODS, true)) {
            throw new InvalidArgumentException('Período do rodízio: semana ou mês.');
        }
        $anchor = $data['anchor_date'] ?? $from;
        if (!is_string($anchor) || !CriticalRolePlanner::validAnchor($period, $anchor)) {
            throw new InvalidArgumentException($period === 'week' ? 'Âncora do rodízio semanal: uma segunda-feira.' : 'Âncora do rodízio mensal: o dia 1 do mês.');
        }
        // [D-ESC-15] Vagas: o mesmo papel pode repetir (Presencial x2).
        $roleIds = CriticalRolePlanner::slotList($data['roles'] ?? null);
        if ($roleIds === null) {
            throw new InvalidArgumentException('Vagas do rodízio: de 1 a ' . CriticalRolePlanner::MAX_ITEMS . ' (o mesmo papel pode repetir).');
        }
        $employeeIds = CriticalRolePlanner::idList($data['employees'] ?? null);
        if ($employeeIds === null) {
            throw new InvalidArgumentException('Pessoas do rodízio: de 1 a ' . CriticalRolePlanner::MAX_ITEMS . ', sem repetir.');
        }
        $roles = $this->roles(true);
        $missing = count(array_filter(array_unique($roleIds), static fn (int $id): bool => !isset($roles[$id]) || $roles[$id]['active'] !== true));
        if ($missing > 0) {
            throw new DomainException("{$missing} papel(is) do rodízio inexistente(s) ou desativado(s): revise a lista.");
        }
        $sorted = $employeeIds;
        sort($sorted);
        $invalid = 0;
        foreach ($sorted as $employeeId) {
            if (!$this->lockCriticalEmployee($employeeId, false)) {
                $invalid++;
            }
        }
        if ($invalid > 0) {
            throw new DomainException("{$invalid} pessoa(s) do rodízio arquivada(s) ou fora do Crítico: revise a lista.");
        }
        return ['id' => 0, 'effective_from' => $from, 'period' => $period, 'anchor_date' => $anchor, 'roles' => $roleIds, 'employees' => $employeeIds];
    }

    /** Papeis por id; com $lock, travados. */
    private function roles(bool $lock = false): array {
        $rows = $this->pdo->query(
            'SELECT id, name, presence_type, work_start, work_end, active, sort_order, color_token FROM portal_critical_roles ORDER BY sort_order, id' . ($lock ? ' FOR UPDATE' : '')
        )->fetchAll();
        $roles = [];
        foreach ($rows as $row) {
            $roles[(int)$row['id']] = CriticalRolePlanner::roleView($row);
        }
        return $roles;
    }

    /** Rodizios na ordem da vigencia. */
    private function rotations(bool $lock): array {
        $rows = $this->pdo->query(
            'SELECT id, effective_from, period, anchor_date, roles, employees FROM portal_critical_role_rotations ORDER BY effective_from' . ($lock ? ' FOR UPDATE' : '')
        )->fetchAll();
        return array_map([CriticalRolePlanner::class, 'rotationView'], $rows);
    }

    private function rotationAtDate(string $date): ?array {
        $stmt = $this->pdo->prepare('SELECT id FROM portal_critical_role_rotations WHERE effective_from = :effective_from FOR UPDATE');
        $stmt->execute([':effective_from' => $date]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : ['id' => (int)$id];
    }

    private function lockRole(int $roleId): array {
        $stmt = $this->pdo->prepare('SELECT id, active FROM portal_critical_roles WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $roleId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Papel não encontrado.');
        }
        return $row;
    }

    private function activeRolesExcept(int $roleId): int {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM portal_critical_roles WHERE active = 1 AND id <> :id');
        $stmt->execute([':id' => $roleId]);
        return (int)$stmt->fetchColumn();
    }

    /** Trava o cadastro; ativo do Critico (senao 409, ou false com $throw = false). */
    private function lockCriticalEmployee(int $employeeId, bool $throw = true): bool {
        $stmt = $this->pdo->prepare('SELECT id, equipe, ativo FROM funcionarios WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch();
        $ok = $row && (int)$row['ativo'] === 1 && PortalTeams::normalize((string)$row['equipe']) === PortalTeams::CRITICAL;
        if (!$ok && $throw) {
            throw new DomainException('Só cadastros ativos do Crítico têm papel.');
        }
        return (bool)$ok;
    }

    /** IDs dos cadastros ativos do Critico. */
    private function activeCriticalIds(): array {
        $rows = $this->pdo->query('SELECT id, equipe FROM funcionarios WHERE ativo = 1')->fetchAll();
        $ids = [];
        foreach ($rows as $row) {
            if (PortalTeams::normalize((string)$row['equipe']) === PortalTeams::CRITICAL) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    private function assertCanManage(string $role): void {
        if (!portal_role_can($role, 'escalas.manage')) {
            throw new PortalForbiddenException(self::FORBIDDEN_MESSAGE);
        }
    }

    private function assertRetroactiveAllowed(string $date, string $role): void {
        if ($date < $this->today() && !portal_role_can($role, 'escalas.retroativa')) {
            throw new PortalForbiddenException('Somente administradores e Liderança gravam data anterior a hoje.');
        }
    }

    private function assertNameFree(string $name, ?int $roleId): void {
        $stmt = $this->pdo->prepare('SELECT id FROM portal_critical_roles WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $found = $stmt->fetchColumn();
        if ($found !== false && (int)$found !== $roleId) {
            throw new DomainException('Já existe um papel com esse nome.');
        }
    }

    private function name(mixed $value): string {
        $name = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if ($name === '' || preg_match_all('/./us', $name) > self::NAME_MAX) {
            throw new InvalidArgumentException('Nome do papel: de 1 a ' . self::NAME_MAX . ' caracteres.');
        }
        return $name;
    }

    private function requiredId(mixed $value, string $label): int {
        $id = $this->optionalId($value, $label);
        if ($id === null) {
            throw new InvalidArgumentException($label . ' inválido.');
        }
        return $id;
    }

    private function optionalId(mixed $value, string $label): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException($label . ' inválido.');
        }
        return (int)$id;
    }

    private function date(mixed $value, string $label): string {
        if (!is_string($value) || !PresenceEngine::validDate($value)) {
            throw new InvalidArgumentException($label . ': data inválida.');
        }
        return $value;
    }

    private function audit(string $action, string $details, string $severity): void {
        if (function_exists('audit_log')) {
            audit_log($action, $details, $severity);
        }
    }

    private function atomic(callable $callback): mixed {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function today(): string {
        return $this->today ?? date('Y-m-d');
    }
}
