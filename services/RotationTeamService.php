<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/SharePointSyncService.php';
require_once __DIR__ . '/ScheduleRulePlanner.php';
require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/CyclePattern.php';
require_once __DIR__ . '/RotationTeamPlanner.php';

/**
 * Times de rodizio (F10-2; desenho do F10, secao 3.1): cadastro, versoes do
 * padrao e membros com vigencia. O time e a fonte; a regra por pessoa e a
 * copia com vigencia, gravada na mesma transacao (portal_schedule_rules com o
 * rotation_team_id), pelo mesmo planejador da regra individual. O motor de
 * presenca nao muda: continua lendo portal_schedule_rules.
 *
 * Permissao (D-F10-5): gravar exige escalas.manage (Lideranca e admin); data
 * anterior a hoje, escalas.retroativa; escala de cadastro da Lideranca ou com
 * perfil Lideranca/admin, so admin (L9). Auditoria so com IDs.
 *
 * Travas, sempre nesta ordem (sem deadlock com a regra individual, que trava
 * so o colaborador): o time e, depois, os colaboradores em ordem de id.
 */
final class RotationTeamService {
    public const NAME_MAX = 60;
    public const VERSION_PREVIEW_DAYS = 20;
    public const MEMBER_MESSAGE = 'Este técnico está no %s; mude pelo time.';

    private PDO $pdo;
    private SharePointSyncService $sync;
    private ?string $today;

    /** $today: data de hoje (Y-m-d) fixa, so para teste; padrao e a data do PHP. */
    public function __construct(?PDO $pdo = null, ?string $today = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->sync = new SharePointSyncService();
        $this->today = $today;
    }

    /**
     * Times, versoes e pertencas (abertas e futuras; com $withHistory, todas).
     * So IDs dos membros: o nome vem da lista de funcionarios que a tela ja tem.
     */
    public function listTeams(bool $withHistory = false): array {
        $teams = $this->pdo->query(
            'SELECT id, name, active FROM portal_rotation_teams ORDER BY name, id'
        )->fetchAll();
        $versions = $this->pdo->query(
            'SELECT id, team_id, effective_from, pattern, anchor_date, weeks
             FROM portal_rotation_team_versions ORDER BY team_id, effective_from'
        )->fetchAll();
        $sql = 'SELECT m.id, m.team_id, m.employee_id, m.valid_from, m.valid_until, f.ativo AS employee_active
                FROM portal_rotation_team_members m
                JOIN funcionarios f ON f.id = m.employee_id';
        $params = [];
        if (!$withHistory) {
            $sql .= ' WHERE m.valid_until IS NULL OR m.valid_until >= :today';
            $params[':today'] = $this->today();
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY m.team_id, m.valid_from, m.employee_id');
        $stmt->execute($params);
        $members = $stmt->fetchAll();
        $today = $this->today();
        $result = [];
        foreach ($teams as $team) {
            $teamId = (int)$team['id'];
            $teamVersions = array_values(array_map(fn (array $row): array => $this->versionView($row), array_filter($versions, static fn (array $row): bool => (int)$row['team_id'] === $teamId)));
            $current = RotationTeamPlanner::versionAt($teamVersions, $today);
            $result[] = [
                'id' => $teamId,
                'name' => $team['name'],
                'active' => (int)$team['active'] === 1,
                'current_version' => $current,
                'versions' => $teamVersions,
                'members' => array_values(array_map(static fn (array $row): array => [
                    'id' => (int)$row['id'],
                    'employee_id' => (int)$row['employee_id'],
                    'valid_from' => $row['valid_from'],
                    'valid_until' => $row['valid_until'],
                    'employee_active' => (int)$row['employee_active'] === 1,
                    'is_current' => $row['valid_from'] <= $today && ($row['valid_until'] === null || $row['valid_until'] >= $today),
                ], array_filter($members, static fn (array $row): bool => (int)$row['team_id'] === $teamId))),
            ];
        }
        return ['today' => $today, 'teams' => $result];
    }

    /**
     * Cria o time (com a primeira versao, opcional, na mesma transacao) ou
     * renomeia, ativa e desativa. Desativar com pertenca aberta ou futura: 409.
     */
    public function saveTeam(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $actor, $role): array {
            $name = $this->teamName($data['name'] ?? null);
            $active = !array_key_exists('active', $data) || filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
            $teamId = $this->optionalId($data['team_id'] ?? null, 'Time');
            if ($teamId === null) {
                $this->assertNameFree($name, null);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_rotation_teams (name, active, created_by, updated_by) VALUES (:name, 1, :created_by, :updated_by)'
                );
                $stmt->execute([':name' => $name, ':created_by' => $actor, ':updated_by' => $actor]);
                $teamId = (int)$this->pdo->lastInsertId();
                $this->audit('TIME_RODIZIO_CRIADO', "time_id={$teamId}", 'INFO');
                $version = null;
                if (isset($data['version']) && is_array($data['version'])) {
                    $version = $this->saveVersionUnsafe(['team_id' => $teamId] + $data['version'], $actor, $role);
                }
                return ['team_id' => $teamId, 'created' => true, 'version' => $version];
            }
            $team = $this->lockTeam($teamId);
            $this->assertNameFree($name, $teamId);
            if (!$active && (int)$team['active'] === 1 && $this->openOrFutureMembers($teamId) > 0) {
                throw new DomainException('O time tem membros: encerre ou mova as pertenças antes de desativar.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE portal_rotation_teams SET name = :name, active = :active, updated_by = :updated_by WHERE id = :id'
            );
            $stmt->execute([':name' => $name, ':active' => $active ? 1 : 0, ':updated_by' => $actor, ':id' => $teamId]);
            $this->audit('TIME_RODIZIO_ATUALIZADO', "time_id={$teamId}; ativo=" . ($active ? 'sim' : 'nao'), 'INFO');
            return ['team_id' => $teamId, 'created' => false, 'version' => null];
        });
    }

    /**
     * Previa da versao (sem gravar): os proximos dias uteis do padrao a partir
     * do inicio, quantos membros mudam e quantos tem regra individual agendada
     * (o 409 do salvar). Mesmas validacoes e permissoes do salvar.
     */
    public function previewVersion(array $data, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $role): array {
            $request = $this->versionRequest($data, $role);
            $impact = $this->versionImpact($request);
            $size = count($request['pattern']);
            $days = [];
            for ($date = $request['effective_from']; count($days) < self::VERSION_PREVIEW_DAYS; $date = ScheduleRulePlanner::addDays($date, 1)) {
                if (!PresenceEngine::isBusinessDay($date)) {
                    continue;
                }
                $days[] = ['date' => $date, 'presence_type' => $request['pattern'][PresenceEngine::cyclePosition($request['anchor_date'], $date, $size)]];
            }
            return [
                'team_id' => $request['team_id'],
                'effective_from' => $request['effective_from'],
                'label' => CyclePattern::label($request['pattern'], $request['weeks']),
                'replaces' => $request['existing'] !== null,
                'retroactive' => $request['effective_from'] < $this->today(),
                'members_changed' => $impact['changed'],
                'members_blocked' => $impact['blocked'],
                'members_inactive' => $impact['inactive'],
                'preview' => $days,
            ];
        });
    }

    /**
     * Grava a versao (nova, ou correcao da que comeca na mesma data) e, na
     * mesma transacao, a regra de cada membro a partir dela. Membro com regra
     * individual agendada depois: 409 com a contagem, nada gravado.
     */
    public function saveVersion(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(fn (): array => $this->saveVersionUnsafe($data, $actor, $role));
    }

    /** Entra no time a partir de valid_from: grava a regra do time na mesma transacao. */
    public function addMember(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $actor, $role): array {
            $teamId = $this->requiredId($data['team_id'] ?? null, 'Time');
            $employeeId = $this->requiredId($data['employee_id'] ?? null, 'Colaborador');
            $from = $this->date($data['valid_from'] ?? null, 'Início no time');
            $this->assertRetroactiveAllowed($from, $role);
            $team = $this->lockTeam($teamId);
            if ((int)$team['active'] !== 1) {
                throw new DomainException('Time desativado: reative o time antes de incluir membros.');
            }
            $versions = $this->teamVersions($teamId);
            $employee = $this->lockMember($employeeId, $role);
            $memberships = $this->employeeMemberships($employeeId);
            $open = array_values(array_filter($memberships, static fn (array $m): bool => $m['valid_until'] === null || $m['valid_until'] >= $from));
            if ($open !== []) {
                throw new DomainException(sprintf(self::MEMBER_MESSAGE, $this->teamLabel((int)$open[0]['team_id'])) . ' Para trocar de time, use Mover.');
            }
            $written = $this->writeMemberRules($employee, $teamId, $versions, $from, null, $actor);
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_rotation_team_members (team_id, employee_id, valid_from, valid_until, created_by, updated_by)
                 VALUES (:team_id, :employee_id, :valid_from, NULL, :created_by, :updated_by)'
            );
            $stmt->execute([':team_id' => $teamId, ':employee_id' => $employeeId, ':valid_from' => $from, ':created_by' => $actor, ':updated_by' => $actor]);
            $memberId = (int)$this->pdo->lastInsertId();
            $this->audit('TIME_RODIZIO_MEMBRO_ALTERADO', "operacao=entrada; time_id={$teamId}; employee_id={$employeeId}; pertenca_id={$memberId}; desde={$from}; regras_gravadas={$written}; retroativa=" . ($from < $this->today() ? 'sim' : 'nao'), 'INFO');
            return ['member_id' => $memberId, 'rules_written' => $written];
        });
    }

    /**
     * Troca de time a partir de valid_from: a pertenca atual fecha na vespera
     * (ou troca de time no lugar, se comecou nesse dia) e a regra do time novo
     * vale a partir de valid_from.
     */
    public function moveMember(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $actor, $role): array {
            $teamId = $this->requiredId($data['team_id'] ?? null, 'Time');
            $employeeId = $this->requiredId($data['employee_id'] ?? null, 'Colaborador');
            $from = $this->date($data['valid_from'] ?? null, 'Início no time novo');
            $this->assertRetroactiveAllowed($from, $role);
            $team = $this->lockTeam($teamId);
            if ((int)$team['active'] !== 1) {
                throw new DomainException('Time desativado: reative o time antes de incluir membros.');
            }
            $versions = $this->teamVersions($teamId);
            $employee = $this->lockMember($employeeId, $role);
            $memberships = $this->employeeMemberships($employeeId);
            $current = RotationTeamPlanner::membershipAt($memberships, $from);
            if ($current === null || $current['valid_until'] !== null) {
                throw new DomainException('O técnico não tem pertença aberta nessa data: use Incluir no time.');
            }
            if ((int)$current['team_id'] === $teamId) {
                throw new DomainException('O técnico já está nesse time.');
            }
            $later = array_values(array_filter($memberships, static fn (array $m): bool => $m['valid_from'] > $from));
            if ($later !== []) {
                throw new DomainException('O técnico tem pertença agendada depois dessa data: encerre-a antes.');
            }
            $written = $this->writeMemberRules($employee, $teamId, $versions, $from, null, $actor);
            if ($current['valid_from'] === $from) {
                $stmt = $this->pdo->prepare('UPDATE portal_rotation_team_members SET team_id = :team_id, updated_by = :updated_by WHERE id = :id');
                $stmt->execute([':team_id' => $teamId, ':updated_by' => $actor, ':id' => (int)$current['id']]);
                $memberId = (int)$current['id'];
            } else {
                $stmt = $this->pdo->prepare('UPDATE portal_rotation_team_members SET valid_until = :valid_until, updated_by = :updated_by WHERE id = :id');
                $stmt->execute([':valid_until' => ScheduleRulePlanner::addDays($from, -1), ':updated_by' => $actor, ':id' => (int)$current['id']]);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_rotation_team_members (team_id, employee_id, valid_from, valid_until, created_by, updated_by)
                     VALUES (:team_id, :employee_id, :valid_from, NULL, :created_by, :updated_by)'
                );
                $stmt->execute([':team_id' => $teamId, ':employee_id' => $employeeId, ':valid_from' => $from, ':created_by' => $actor, ':updated_by' => $actor]);
                $memberId = (int)$this->pdo->lastInsertId();
            }
            $this->audit('TIME_RODIZIO_MEMBRO_ALTERADO', "operacao=troca; time_anterior_id={$current['team_id']}; time_id={$teamId}; employee_id={$employeeId}; pertenca_id={$memberId}; desde={$from}; regras_gravadas={$written}; retroativa=" . ($from < $this->today() ? 'sim' : 'nao'), 'INFO');
            return ['member_id' => $memberId, 'rules_written' => $written];
        });
    }

    /**
     * Sai do time: a pertenca termina em valid_until (inclusive). A regra fica
     * como esta, ate outra regra (desenho 3.1). Pertenca que ainda nao comecou
     * e cancelada (fim antes do inicio nao existe): fica so na auditoria.
     */
    public function endMember(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        return $this->atomic(function () use ($data, $actor, $role): array {
            $memberId = $this->requiredId($data['member_id'] ?? null, 'Pertença');
            $until = $this->date($data['valid_until'] ?? null, 'Último dia no time');
            $this->assertRetroactiveAllowed($until, $role);
            $row = $this->memberRow($memberId);
            $this->lockTeam((int)$row['team_id']);
            $this->lockMemberForEnd((int)$row['employee_id'], $role);
            $row = $this->memberRow($memberId);
            if ($row['valid_until'] !== null) {
                throw new DomainException('Esta pertença já foi encerrada.');
            }
            if ($until < $row['valid_from']) {
                if ($row['valid_from'] < $this->today()) {
                    throw new InvalidArgumentException('O último dia no time não pode ser antes do início da pertença.');
                }
                $undone = $this->undoFutureMembershipRules($row, $actor);
                $stmt = $this->pdo->prepare('DELETE FROM portal_rotation_team_members WHERE id = :id AND valid_until IS NULL');
                $stmt->execute([':id' => $memberId]);
                $this->audit('TIME_RODIZIO_MEMBRO_ALTERADO', "operacao=cancelamento; time_id={$row['team_id']}; employee_id={$row['employee_id']}; pertenca_id={$memberId}; inicio_cancelado={$row['valid_from']}; regras_desfeitas={$undone}", 'INFO');
                return ['member_id' => $memberId, 'cancelled' => true, 'rules_undone' => $undone];
            }
            $stmt = $this->pdo->prepare('UPDATE portal_rotation_team_members SET valid_until = :valid_until, updated_by = :updated_by WHERE id = :id AND valid_until IS NULL');
            $stmt->execute([':valid_until' => $until, ':updated_by' => $actor, ':id' => $memberId]);
            $this->audit('TIME_RODIZIO_MEMBRO_ALTERADO', "operacao=saida; time_id={$row['team_id']}; employee_id={$row['employee_id']}; pertenca_id={$memberId}; ate={$until}", 'INFO');
            return ['member_id' => $memberId, 'cancelled' => false];
        });
    }

    // ------------------------------------------------------------------------

    /**
     * Cancelar pertenca que ainda nao comecou (inicio hoje ou depois): as
     * regras do time gravadas a partir do inicio saem e a regra que a entrada
     * encerrou na vespera volta a valer como antes (ate a proxima regra, ou
     * aberta). Regra de outro dono depois do inicio: 409, nada muda.
     */
    private function undoFutureMembershipRules(array $membership, string $actor): int {
        $employeeId = (int)$membership['employee_id'];
        $teamId = (int)$membership['team_id'];
        $from = (string)$membership['valid_from'];
        $before = $this->employeeRules($employeeId);
        $later = array_values(array_filter($before, static fn (array $r): bool => !ScheduleRulePlanner::isEmpty($r) && $r['effective_from'] >= $from));
        foreach ($later as $rule) {
            if ($rule['rotation_team_id'] !== $teamId) {
                throw new DomainException('Há regra de outra origem depois do início da pertença: ajuste-a antes de cancelar.');
            }
        }
        $delete = $this->pdo->prepare('DELETE FROM portal_schedule_rules WHERE id = :id AND employee_id = :employee_id');
        foreach ($later as $rule) {
            $delete->execute([':id' => (int)$rule['id'], ':employee_id' => $employeeId]);
        }
        $undone = count($later);
        $eve = ScheduleRulePlanner::addDays($from, -1);
        $previous = ScheduleRulePlanner::ruleAt($before, $eve);
        if ($previous !== null && $previous['effective_until'] === $eve && $later !== []) {
            // A entrada encerrou a regra anterior na vespera e a do time herdou o
            // fim dela: o fim original e o da ultima regra do time apagada.
            usort($later, static fn (array $a, array $b): int => strcmp($a['effective_from'], $b['effective_from']));
            $stmt = $this->pdo->prepare(
                'UPDATE portal_schedule_rules SET effective_until = :effective_until, updated_by = :updated_by WHERE id = :id AND employee_id = :employee_id'
            );
            $stmt->execute([':effective_until' => $later[count($later) - 1]['effective_until'], ':updated_by' => $actor, ':id' => (int)$previous['id'], ':employee_id' => $employeeId]);
            $undone++;
        }
        $new = array_diff(ScheduleRulePlanner::violations($this->employeeRules($employeeId)), ScheduleRulePlanner::violations($before));
        if ($new !== []) {
            throw new RuntimeException('Historico de escala inconsistente apos cancelar a pertenca: ' . implode('; ', $new));
        }
        return $undone;
    }

    private function saveVersionUnsafe(array $data, string $actor, string $role): array {
        $request = $this->versionRequest($data, $role);
        $impact = $this->versionImpact($request);
        if ($impact['blocked'] > 0) {
            throw new DomainException($impact['blocked'] . ' membro(s) com regra individual agendada depois de ' . self::dateLabel($request['effective_from']) . ': o time não sobrescreve. Ajuste essas regras ou encerre a pertença antes.');
        }
        $patternJson = (string)json_encode($request['pattern']);
        if ($request['existing'] !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE portal_rotation_team_versions SET pattern = :pattern, anchor_date = :anchor_date, weeks = :weeks, updated_by = :updated_by
                 WHERE id = :id AND team_id = :team_id'
            );
            $stmt->execute([':pattern' => $patternJson, ':anchor_date' => $request['anchor_date'], ':weeks' => $request['weeks'], ':updated_by' => $actor,
                ':id' => (int)$request['existing']['id'], ':team_id' => $request['team_id']]);
            $versionId = (int)$request['existing']['id'];
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_rotation_team_versions (team_id, effective_from, pattern, anchor_date, weeks, created_by, updated_by)
                 VALUES (:team_id, :effective_from, :pattern, :anchor_date, :weeks, :created_by, :updated_by)'
            );
            $stmt->execute([':team_id' => $request['team_id'], ':effective_from' => $request['effective_from'], ':pattern' => $patternJson,
                ':anchor_date' => $request['anchor_date'], ':weeks' => $request['weeks'], ':created_by' => $actor, ':updated_by' => $actor]);
            $versionId = (int)$this->pdo->lastInsertId();
        }
        $versions = $this->teamVersions($request['team_id']);
        $written = 0;
        $employees = [];
        foreach ($impact['members'] as $member) {
            $written += $this->writeMemberRules($member['employee'], $request['team_id'], $versions, $member['start'], $member['until'], $actor);
            $employees[] = (int)$member['employee']['id'];
        }
        $this->audit(
            'TIME_RODIZIO_VERSAO_ALTERADA',
            "time_id={$request['team_id']}; versao_id={$versionId}; desde={$request['effective_from']}; operacao=" . ($request['existing'] !== null ? 'correcao' : 'nova')
                . '; posicoes=' . count($request['pattern']) . '; semanas=' . ($request['weeks'] ?? 'nao')
                . '; membros_afetados=' . count($employees) . '; employee_ids=' . ($employees === [] ? 'nenhum' : implode(',', $employees))
                . "; regras_gravadas={$written}; inativos_ignorados={$impact['inactive']}; retroativa=" . ($request['effective_from'] < $this->today() ? 'sim' : 'nao'),
            'WARNING'
        );
        return ['version_id' => $versionId, 'members_affected' => count($employees), 'members_changed' => $impact['changed'], 'rules_written' => $written, 'members_inactive' => $impact['inactive']];
    }

    /** Payload da versao validado, com o time travado e a versao do mesmo inicio (se houver). */
    private function versionRequest(array $data, string $role): array {
        $teamId = $this->requiredId($data['team_id'] ?? null, 'Time');
        $from = $this->date($data['effective_from'] ?? null, 'Início da versão');
        $cycle = $data['cycle'] ?? null;
        if (!is_array($cycle) || !CyclePattern::isExplicit($cycle)) {
            throw new InvalidArgumentException('Informe o padrão do time (lista de dias ou semanas).');
        }
        $parsed = CyclePattern::fromPayload($cycle);
        $this->assertRetroactiveAllowed($from, $role);
        $this->lockTeam($teamId);
        $existing = null;
        foreach ($this->teamVersions($teamId) as $version) {
            if ($version['effective_from'] === $from) {
                $existing = $version;
            }
        }
        return ['team_id' => $teamId, 'effective_from' => $from, 'existing' => $existing] + $parsed;
    }

    /**
     * Membros afetados pela versao: pertencas que alcancam o inicio dela (a
     * gravacao vale de max(inicio, entrada) ate a saida). Cadastro inativo nao
     * recebe regra (foi arquivado: as regras ja foram encerradas) e e contado
     * a parte. Trava cada membro, em ordem de id.
     */
    private function versionImpact(array $request): array {
        $versions = $this->teamVersions($request['team_id']);
        $versions = array_values(array_filter($versions, static fn (array $v): bool => $v['effective_from'] !== $request['effective_from']));
        $versions[] = ['id' => 0, 'team_id' => $request['team_id'], 'effective_from' => $request['effective_from'],
            'pattern' => $request['pattern'], 'anchor_date' => $request['anchor_date'], 'weeks' => $request['weeks']];
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.team_id, m.employee_id, m.valid_from, m.valid_until, f.ativo AS employee_active
             FROM portal_rotation_team_members m
             JOIN funcionarios f ON f.id = m.employee_id
             WHERE m.team_id = :team_id AND (m.valid_until IS NULL OR m.valid_until >= :effective_from)
             ORDER BY m.employee_id, m.valid_from
             FOR UPDATE'
        );
        $stmt->execute([':team_id' => $request['team_id'], ':effective_from' => $request['effective_from']]);
        $result = ['changed' => 0, 'blocked' => 0, 'inactive' => 0, 'members' => []];
        foreach ($stmt->fetchAll() as $membership) {
            if ((int)$membership['employee_active'] !== 1) {
                $result['inactive']++;
                continue;
            }
            $employee = $this->lockMemberForVersion((int)$membership['employee_id']);
            $start = max($request['effective_from'], (string)$membership['valid_from']);
            $until = $membership['valid_until'];
            $rules = $this->employeeRules((int)$employee['id']);
            if (RotationTeamPlanner::incompatibleRules($rules, $start, $until) !== []) {
                $result['blocked']++;
                continue;
            }
            $simulated = RotationTeamPlanner::simulate($rules, RotationTeamPlanner::memberIntents($rules, $versions, $request['team_id'], $start, $until), $this->today());
            if ($simulated['plans'] !== []) {
                $result['changed']++;
            }
            $result['members'][] = ['employee' => $employee, 'start' => $start, 'until' => $until];
        }
        return $result;
    }

    /**
     * Grava as regras de um membro na pertenca [start, until], intencao por
     * intencao, relendo o historico com trava antes de cada uma (o mesmo
     * planSave da regra individual). Devolve quantas linhas mudaram.
     */
    private function writeMemberRules(array $employee, int $teamId, array $versions, string $start, ?string $until, string $actor): int {
        $employeeId = (int)$employee['id'];
        $before = $this->employeeRules($employeeId);
        if (RotationTeamPlanner::incompatibleRules($before, $start, $until) !== []) {
            throw new DomainException('O técnico tem regra individual agendada depois de ' . self::dateLabel($start) . ': o time não sobrescreve. Ajuste essa regra antes.');
        }
        $written = 0;
        foreach (RotationTeamPlanner::memberIntents($before, $versions, $teamId, $start, $until) as $intent) {
            $rules = $this->employeeRules($employeeId);
            $plan = RotationTeamPlanner::planIntent($rules, $intent, $this->today());
            if ($plan['action'] === 'unchanged') {
                continue;
            }
            if ($plan['close'] !== null) {
                $stmt = $this->pdo->prepare(
                    'UPDATE portal_schedule_rules SET effective_until = :effective_until, updated_by = :updated_by
                     WHERE id = :id AND employee_id = :employee_id'
                );
                $stmt->execute([':effective_until' => $plan['close']['effective_until'], ':updated_by' => $actor, ':id' => $plan['close']['id'], ':employee_id' => $employeeId]);
            }
            if ($plan['action'] === 'close') {
                $written++;
                continue;
            }
            $params = [
                ':employee_id' => $employeeId,
                ':employee_name' => $employee['name'],
                ':ad_login' => $employee['ad_login'],
                ':team' => $employee['team'],
                ':rule_type' => $intent['rule_type'],
                ':rule_config' => $intent['rule_config'],
                ':rotation_team_id' => $intent['rotation_team_id'],
                ':effective_until' => $plan['effective_until'],
                ':updated_by' => $actor,
            ];
            if ($plan['action'] === 'update') {
                $stmt = $this->pdo->prepare(
                    'UPDATE portal_schedule_rules
                     SET employee_name = :employee_name, ad_login = :ad_login, team = :team, rule_type = :rule_type,
                         rule_config = :rule_config, rotation_team_id = :rotation_team_id, effective_until = :effective_until, updated_by = :updated_by
                     WHERE id = :id AND employee_id = :employee_id'
                );
                $stmt->execute($params + [':id' => $plan['target_id']]);
                $ruleId = (int)$plan['target_id'];
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_schedule_rules
                        (employee_id, employee_name, ad_login, team, rule_type, rule_config, rotation_team_id, effective_from, effective_until, created_by, updated_by)
                     VALUES
                        (:employee_id, :employee_name, :ad_login, :team, :rule_type, :rule_config, :rotation_team_id, :effective_from, :effective_until, :created_by, :updated_by)'
                );
                $stmt->execute($params + [':effective_from' => $plan['effective_from'], ':created_by' => $actor]);
                $ruleId = (int)$this->pdo->lastInsertId();
            }
            $written++;
            $config = json_decode((string)$intent['rule_config'], true);
            $syncPayload = [
                'employee_id' => $employeeId,
                'employee_name' => $employee['name'],
                'team' => $employee['team'],
                'rule_type' => $intent['rule_type'],
                'weekdays' => [],
                'effective_from' => $plan['effective_from'],
                'effective_until' => $plan['effective_until'],
                'origem' => 'time_rodizio',
            ];
            if (is_array($config) && isset($config['pattern'])) {
                $syncPayload['pattern'] = $config['pattern'];
            }
            $this->sync->enqueue($this->pdo, 'schedule', $ruleId, $syncPayload, $actor);
        }
        $new = array_diff(ScheduleRulePlanner::violations($this->employeeRules($employeeId)), ScheduleRulePlanner::violations($before));
        if ($new !== []) {
            throw new RuntimeException('Historico de escala inconsistente apos gravar o time: ' . implode('; ', $new));
        }
        return $written;
    }

    private function assertCanManage(string $role): void {
        if (!portal_role_can($role, 'escalas.manage')) {
            throw new PortalForbiddenException('Apenas administradores e Liderança alteram times de rodízio.');
        }
    }

    private function assertRetroactiveAllowed(string $date, string $role): void {
        if ($date < $this->today() && !portal_role_can($role, 'escalas.retroativa')) {
            throw new PortalForbiddenException('Somente administradores e Liderança gravam data anterior a hoje.');
        }
    }

    private function lockTeam(int $teamId): array {
        $stmt = $this->pdo->prepare('SELECT id, name, active FROM portal_rotation_teams WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $teamId]);
        $team = $stmt->fetch();
        if (!$team) {
            throw new InvalidArgumentException('Time não encontrado.');
        }
        return $team;
    }

    private function teamVersions(int $teamId): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, effective_from, pattern, anchor_date, weeks
             FROM portal_rotation_team_versions WHERE team_id = :team_id ORDER BY effective_from FOR UPDATE'
        );
        $stmt->execute([':team_id' => $teamId]);
        return array_map(fn (array $row): array => $this->versionView($row), $stmt->fetchAll());
    }

    private function versionView(array $row): array {
        $pattern = is_array($row['pattern']) ? $row['pattern'] : json_decode((string)$row['pattern'], true);
        $pattern = is_array($pattern) ? array_values($pattern) : [];
        $weeks = $row['weeks'] === null ? null : (int)$row['weeks'];
        return [
            'id' => (int)$row['id'],
            'team_id' => (int)$row['team_id'],
            'effective_from' => $row['effective_from'],
            'pattern' => $pattern,
            'anchor_date' => $row['anchor_date'],
            'weeks' => $weeks,
            'label' => CyclePattern::label($pattern, $weeks),
        ];
    }

    /** Cadastro do membro, travado: ativo, N1 ou N2, dentro do Mapa e permitido ao ator (L9). */
    private function lockMember(int $employeeId, string $role): array {
        $employee = $this->lockEmployee($employeeId);
        if ($employee === null) {
            throw new InvalidArgumentException('Colaborador não encontrado ou inativo.');
        }
        $denied = schedule_change_denied($role, $employee['team'], $employee['access_role'] ?? null);
        if ($denied !== null) {
            throw new PortalForbiddenException($denied[1]);
        }
        if ($employee['team'] === 'lideranca') {
            throw new DomainException('A Liderança tem escala fixa presencial: não entra em time de rodízio.');
        }
        if (!in_array($employee['team'], ['n1', 'n2'], true)) {
            throw new InvalidArgumentException('Colaborador não encontrado ou inativo.');
        }
        if ((int)$employee['fora_mapa_escala'] === 1) {
            throw new DomainException('Cadastro marcado como fora do Mapa PA e da escala: não entra em time de rodízio.');
        }
        return $employee;
    }

    /** Na versao, o membro ja passou pelas regras de entrada; so a trava (e o cadastro ativo). */
    private function lockMemberForVersion(int $employeeId): array {
        $employee = $this->lockEmployee($employeeId);
        if ($employee === null) {
            throw new InvalidArgumentException('Colaborador não encontrado ou inativo.');
        }
        return $employee;
    }

    /** Encerrar a pertenca vale tambem para cadastro inativo (arquivado), com a regra L9. */
    private function lockMemberForEnd(int $employeeId, string $role): void {
        $stmt = $this->pdo->prepare('SELECT id, LOWER(equipe) AS team, access_role FROM funcionarios WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        if (!$employee) {
            throw new InvalidArgumentException('Colaborador não encontrado.');
        }
        $denied = schedule_change_denied($role, validate_funcionario_equipe((string)$employee['team']), $employee['access_role'] ?? null);
        if ($denied !== null) {
            throw new PortalForbiddenException($denied[1]);
        }
    }

    private function lockEmployee(int $employeeId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome AS name, LOWER(equipe) AS team, ad_login, access_role, fora_mapa_escala
             FROM funcionarios WHERE id = :id AND ativo = 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
        if (!$employee) {
            return null;
        }
        $employee['team'] = validate_funcionario_equipe((string)$employee['team']) ?? (string)$employee['team'];
        return $employee;
    }

    private function employeeMemberships(int $employeeId): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, employee_id, valid_from, valid_until
             FROM portal_rotation_team_members WHERE employee_id = :employee_id ORDER BY valid_from FOR UPDATE'
        );
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetchAll();
    }

    private function memberRow(int $memberId): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, employee_id, valid_from, valid_until FROM portal_rotation_team_members WHERE id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $memberId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Pertença não encontrada.');
        }
        return $row;
    }

    /** Historico do colaborador com o dono de cada regra, com trava (licao do F1). */
    private function employeeRules(int $employeeId): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, rule_type, rule_config, rotation_team_id, effective_from, effective_until
             FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id FOR UPDATE'
        );
        $stmt->execute([':employee_id' => $employeeId]);
        return array_map(static function (array $row): array {
            $row['rotation_team_id'] = $row['rotation_team_id'] === null ? null : (int)$row['rotation_team_id'];
            return $row;
        }, $stmt->fetchAll());
    }

    private function openOrFutureMembers(int $teamId): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM portal_rotation_team_members WHERE team_id = :team_id AND (valid_until IS NULL OR valid_until >= :today)'
        );
        $stmt->execute([':team_id' => $teamId, ':today' => $this->today()]);
        return (int)$stmt->fetchColumn();
    }

    private function teamLabel(int $teamId): string {
        $stmt = $this->pdo->prepare('SELECT name FROM portal_rotation_teams WHERE id = :id');
        $stmt->execute([':id' => $teamId]);
        $name = $stmt->fetchColumn();
        return $name === false ? 'outro time' : (string)$name;
    }

    private function assertNameFree(string $name, ?int $teamId): void {
        $stmt = $this->pdo->prepare('SELECT id FROM portal_rotation_teams WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $found = $stmt->fetchColumn();
        if ($found !== false && (int)$found !== $teamId) {
            throw new DomainException('Já existe um time com esse nome.');
        }
    }

    private function teamName(mixed $value): string {
        $name = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if ($name === '' || preg_match_all('/./us', $name) > self::NAME_MAX) {
            throw new InvalidArgumentException('Nome do time: de 1 a ' . self::NAME_MAX . ' caracteres.');
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

    private static function dateLabel(string $date): string {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }
}
