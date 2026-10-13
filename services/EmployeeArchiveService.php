<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/GerenciadorPausas.php';
require_once __DIR__ . '/EmployeeArchivePlanner.php';
require_once __DIR__ . '/ScheduleRulePlanner.php';
require_once __DIR__ . '/SharePointSyncService.php';

/** Cadastro inexistente no arquivamento ou na restauracao (404). */
final class EmployeeArchiveNotFoundException extends RuntimeException {
}

/**
 * Arquivar e restaurar tecnicos (Parte B, F4; desenho, secao 9.6).
 *
 * Arquivar, na ordem (D10): (1) trava do estado de pausas
 * (with_pause_state_lock), a mesma dos endpoints de pausa: arquivo antes do
 * banco; (2) leitura do estado.json dentro do lock; (3) transacao: cadastro e
 * linhas do colaborador lidos com FOR UPDATE (licao do F1), plano pela funcao
 * pura EmployeeArchivePlanner, gravacoes, auditoria na mesma conexao; (4)
 * commit; (5) estado.json gravado ainda dentro do lock, so a entrada do
 * colaborador e so se o plano mexe na pausa (L-11). Qualquer falha antes do
 * commit desfaz tudo, inclusive o historico da pausa, e o estado nao e
 * tocado. Falha so na gravacao do estado, depois do commit, vira aviso.
 *
 * O instante e o do PHP (new DateTimeImmutable('now'), America/Sao_Paulo,
 * A4): em outro fuso, "hoje" vira em outra hora e o plano muda; por isso
 * instante fora do fuso e recusado antes de qualquer leitura.
 */
final class EmployeeArchiveService {
    public const TIMEZONE = 'America/Sao_Paulo';
    public const SELF_ARCHIVE_MESSAGE = 'Não é possível arquivar o próprio cadastro.';
    public const LEADERSHIP_TEAM_MESSAGE = 'Somente administradores arquivam cadastros da equipe Liderança.';
    public const RESTORE_DENIED_MESSAGE = 'Somente administradores restauram cadastros arquivados.';
    public const ALREADY_ACTIVE_MESSAGE = 'Este cadastro já está ativo.';
    public const LOGIN_IN_USE_MESSAGE = 'O login AD deste cadastro já pertence a outro funcionário ativo.';
    public const NOT_FOUND_MESSAGE = 'Funcionário não encontrado.';
    public const ALLOWLIST_WARNING = 'O login deste cadastro está em AD_ADMIN_USERS: o acesso administrativo continua pela allowlist até o login ser retirado do .env.';
    public const STATE_WARNING = 'O estado de pausas não pôde ser gravado. O cadastro já está fora do painel; a entrada é limpa na limpeza automática ou ao restaurar.';
    public const GENERIC_ERROR = 'Não foi possível concluir a operação. Nada foi alterado.';
    public const PAUSE_NOTE = 'Encerrada pelo arquivamento do cadastro (admin_archive) por ';

    private const EMPTY_PAUSE_ENTRY = [
        'em_pausa' => false,
        'inicio_pausa' => null,
        'motivo_pausa' => null,
        'status_aprovacao' => null,
        'solicitacao_timestamp' => null,
        'observacao_reuniao' => null,
    ];

    private PDO $pdo;
    private string $statePath;
    private Closure $clock;
    private Closure $writeState;

    /**
     * $clock: instante do arquivamento (padrao: new DateTimeImmutable('now')).
     * $writeState: grava o conteudo do estado (padrao: file_put_contents com
     * LOCK_EX, como os gravadores do estado); devolve false na falha.
     */
    public function __construct(?PDO $pdo = null, ?string $statePath = null, ?Closure $clock = null, ?Closure $writeState = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->statePath = $statePath ?? ESTADO_JSON;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
        $this->writeState = $writeState ?? static fn (string $path, string $content): bool
            => file_put_contents($path, $content, LOCK_EX) !== false;
    }

    /** Ator a partir da sessao: perfil, usuario e o colaborador da sessao CI (qualquer perfil). */
    public static function sessionActor(string $role, array $session): array {
        $username = $session['admin_username'] ?? $session['username'] ?? $session['ci_username'] ?? 'anonymous';
        $employeeId = (int)($session['ci_funcionario_id'] ?? 0);
        return [
            'role' => $role,
            'username' => sanitize_input((string)$username, 100),
            'session_employee_id' => $employeeId > 0 ? $employeeId : null,
        ];
    }

    /**
     * Resposta HTTP de uma falha do arquivamento ou da restauracao:
     * [status, mensagem]. Erro inesperado (dado fora do formato, invariante,
     * falha de gravacao) vai para o error_log com a classe e a mensagem, e o
     * usuario recebe so a mensagem generica.
     */
    public static function httpError(Throwable $error): array {
        if ($error instanceof PortalForbiddenException) {
            return [403, $error->getMessage()];
        }
        if ($error instanceof EmployeeArchiveNotFoundException) {
            return [404, $error->getMessage()];
        }
        if ($error instanceof DomainException) {
            return [409, $error->getMessage()];
        }
        if (database_unavailable_error($error)) {
            error_log('[ARCHIVE] Banco indisponivel: ' . get_class($error));
            return [503, DATABASE_UNAVAILABLE_MESSAGE];
        }
        error_log('[ARCHIVE] ' . get_class($error) . ': ' . $error->getMessage());
        return [500, self::GENERIC_ERROR];
    }

    /** Instante no fuso do PHP; outro fuso e recusado antes de qualquer leitura. */
    public function instant(): DateTimeImmutable {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable || $now->getTimezone()->getName() !== self::TIMEZONE) {
            throw new LogicException('Instante do arquivamento fora do fuso ' . self::TIMEZONE . ' (regra do A4).');
        }
        return $now;
    }

    /**
     * Arquiva o cadastro (ativo) ou regulariza um inativo anterior ao F4 (D5).
     * Devolve ['efeitos' => [...], 'regularizacao' => bool, 'avisos' => list<string>].
     */
    public function archive(int $employeeId, array $actor): array {
        $now = $this->instant();
        return with_pause_state_lock(function () use ($employeeId, $actor, $now): array {
            $state = ler_estado_pausas($this->statePath);
            if ($state === null && is_file($this->statePath)) {
                error_log("[ARCHIVE] Estado de pausas ilegivel; arquivamento do funcionario ID {$employeeId} segue sem pausa.");
            }
            $entry = $state['funcionarios'][$employeeId] ?? null;

            $this->pdo->beginTransaction();
            try {
                $employee = $this->lockEmployee($employeeId);
                $this->assertArchiveAllowed($employee, $actor);
                $today = $now->format('Y-m-d');
                $rules = $this->employeeRules($employeeId);
                $plan = EmployeeArchivePlanner::plan([
                    'employee' => $employee,
                    'rules' => $rules,
                    'exceptions' => $this->lockedRows(
                        'SELECT id, employee_id, exception_date, exception_type
                         FROM portal_schedule_exceptions WHERE employee_id = :employee_id AND exception_date >= :today
                         ORDER BY exception_date, id FOR UPDATE',
                        [':employee_id' => $employeeId, ':today' => $today]
                    ),
                    'absences' => $this->lockedRows(
                        'SELECT id, employee_id, starts_on, ends_on, period_type, status
                         FROM portal_absences WHERE employee_id = :employee_id AND status = :status AND ends_on >= :today
                         ORDER BY starts_on, id FOR UPDATE',
                        [':employee_id' => $employeeId, ':status' => 'approved', ':today' => $today]
                    ),
                    'pa_links' => $this->lockedRows(
                        'SELECT id, employee_id, valid_from, valid_until, active
                         FROM portal_pa_assignments WHERE employee_id = :employee_id AND active = 1
                           AND (valid_until IS NULL OR valid_until >= :today)
                         ORDER BY valid_from, id FOR UPDATE',
                        [':employee_id' => $employeeId, ':today' => $today]
                    ),
                    'pause' => $entry,
                ], $now);
                $this->applyArchive($plan, $employee, $actor, is_array($entry) ? $entry : [], $now, $rules);
                $allowlisted = $this->allowlisted($employee);
                $this->auditArchive($plan, $employee, $actor, $allowlisted);
                $this->pdo->commit();
            } catch (Throwable $error) {
                $this->rollBack();
                throw $error;
            }

            $warnings = $allowlisted ? [self::ALLOWLIST_WARNING] : [];
            if ($plan['pause']['action'] !== 'none' && !$this->clearStateEntry($state, $employeeId)) {
                error_log("[ARCHIVE] Estado de pausas nao gravado depois do arquivamento do funcionario ID {$employeeId}.");
                $warnings[] = self::STATE_WARNING;
            }
            return [
                'efeitos' => $plan['efeitos'],
                'regularizacao' => $plan['employee']['regularizacao'],
                'avisos' => $warnings,
            ];
        }, $this->statePath);
    }

    /**
     * Restaura um cadastro inativo (D12): ativo = 1, sem data nem autor de
     * arquivamento; PA, escala e ausencias nao voltam. So admin
     * (funcionarios.restaurar). Devolve ['avisos' => list<string>].
     */
    public function restore(int $employeeId, array $actor): array {
        if (!portal_role_can($actor['role'] ?? null, 'funcionarios.restaurar')) {
            throw new PortalForbiddenException(self::RESTORE_DENIED_MESSAGE);
        }
        return with_pause_state_lock(function () use ($employeeId, $actor): array {
            $state = ler_estado_pausas($this->statePath);
            $this->pdo->beginTransaction();
            try {
                $employee = $this->lockEmployee($employeeId);
                if ((int)$employee['ativo'] === 1) {
                    throw new DomainException(self::ALREADY_ACTIVE_MESSAGE);
                }
                $login = trim((string)($employee['ad_login'] ?? ''));
                if ($login !== '' && $this->lockedRows(
                    'SELECT id FROM funcionarios WHERE id <> :id AND ativo = 1 AND ad_login = :ad_login LIMIT 1 FOR UPDATE',
                    [':id' => $employeeId, ':ad_login' => $login]
                ) !== []) {
                    throw new DomainException(self::LOGIN_IN_USE_MESSAGE);
                }
                $this->write(
                    'UPDATE funcionarios SET ativo = 1, arquivado_em = NULL, arquivado_por = NULL WHERE id = :id AND ativo = 0',
                    [':id' => $employeeId],
                    'cadastro'
                );
                audit_log(
                    'FUNCIONARIO_RESTAURADO',
                    'funcionario_id=' . $employeeId
                        . '; perfil=' . ($employee['access_role'] ?? '-')
                        . '; equipe=' . ($employee['equipe'] ?? '-')
                        . '; ator=' . ($actor['role'] ?? '-')
                        . '; arquivado_em=' . (($employee['arquivado_em'] ?? null) ?: 'desconhecida')
                        . '; pa_escala_ausencias=nao_devolvidos',
                    'CRITICAL'
                );
                $this->pdo->commit();
            } catch (Throwable $error) {
                $this->rollBack();
                throw $error;
            }

            $warnings = [];
            $entry = $state['funcionarios'][$employeeId] ?? null;
            if (is_array($entry) && array_intersect_key($entry, self::EMPTY_PAUSE_ENTRY) != self::EMPTY_PAUSE_ENTRY
                && !$this->clearStateEntry($state, $employeeId)) {
                error_log("[ARCHIVE] Estado de pausas nao gravado depois da restauracao do funcionario ID {$employeeId}.");
                $warnings[] = self::STATE_WARNING;
            }
            return ['avisos' => $warnings];
        }, $this->statePath);
    }

    private function lockEmployee(int $employeeId): array {
        $rows = $this->lockedRows(
            'SELECT id, nome, equipe, access_role, ad_login, ativo, arquivado_em FROM funcionarios WHERE id = :id FOR UPDATE',
            [':id' => $employeeId]
        );
        if ($rows === []) {
            throw new EmployeeArchiveNotFoundException(self::NOT_FOUND_MESSAGE);
        }
        return $rows[0];
    }

    /**
     * D1: regra do Lote 5b com o perfil atual lido com trava (Liderança nao
     * arquiva cadastro de Liderança nem de admin) e, pela L9, cadastro da
     * equipe Liderança so pelo admin (os efeitos mexem na escala dele). D3:
     * o proprio cadastro nunca (pela sessao CI ou pelo login AD).
     */
    private function assertArchiveAllowed(array $employee, array $actor): void {
        $role = (string)($actor['role'] ?? '');
        $denied = funcionario_change_denied($role, (string)($employee['access_role'] ?? ''), null);
        if ($denied !== null) {
            throw new PortalForbiddenException($denied[1]);
        }
        if (!portal_role_can($role, 'escalas.lideranca') && validate_funcionario_equipe((string)($employee['equipe'] ?? '')) === 'lideranca') {
            throw new PortalForbiddenException(self::LEADERSHIP_TEAM_MESSAGE);
        }
        $ownLogin = ad_login_key($employee['ad_login'] ?? '');
        if (($actor['session_employee_id'] ?? null) === (int)$employee['id']
            || ($ownLogin !== null && $ownLogin === ad_login_key($actor['username'] ?? ''))) {
            throw new DomainException(self::SELF_ARCHIVE_MESSAGE);
        }
    }

    private function applyArchive(array $plan, array $employee, array $actor, array $entry, DateTimeImmutable $now, array $rulesBefore): void {
        $employeeId = (int)$employee['id'];
        $username = (string)($actor['username'] ?? 'anonymous');
        $stamp = $now->format('Y-m-d H:i:s');

        foreach ($plan['pa']['close'] as $close) {
            $this->write(
                'UPDATE portal_pa_assignments SET valid_until = :valid_until, updated_by = :updated_by
                 WHERE id = :id AND employee_id = :employee_id AND active = 1',
                [':valid_until' => $close['valid_until'], ':updated_by' => $username, ':id' => $close['id'], ':employee_id' => $employeeId],
                'vinculo de PA'
            );
        }
        foreach ($plan['pa']['remove'] as $remove) {
            $this->write(
                'UPDATE portal_pa_assignments SET active = 0, deleted_at = :deleted_at, updated_by = :updated_by
                 WHERE id = :id AND employee_id = :employee_id AND active = 1',
                [':deleted_at' => $stamp, ':updated_by' => $username, ':id' => $remove['id'], ':employee_id' => $employeeId],
                'vinculo de PA'
            );
        }

        $rules = $plan['rules'];
        if ($rules['close'] !== null) {
            $this->write(
                'UPDATE portal_schedule_rules SET effective_until = :effective_until, updated_by = :updated_by
                 WHERE id = :id AND employee_id = :employee_id',
                [':effective_until' => $rules['close']['effective_until'], ':updated_by' => $username, ':id' => $rules['close']['id'], ':employee_id' => $employeeId],
                'regra de escala'
            );
        }
        foreach ($rules['delete'] as $rule) {
            $this->write(
                'DELETE FROM portal_schedule_rules WHERE id = :id AND employee_id = :employee_id',
                [':id' => (int)$rule['id'], ':employee_id' => $employeeId],
                'regra de escala'
            );
        }
        if ($rules['close'] !== null || $rules['delete'] !== []) {
            $this->assertScheduleHistoryConsistent($rulesBefore, $employeeId);
            $reference = $rules['current'] ?? $rules['delete'][0];
            (new SharePointSyncService())->enqueue($this->pdo, 'schedule', (int)$reference['id'], [
                'kind' => 'rule_removed',
                'origem' => 'arquivamento',
                'employee_id' => $employeeId,
                'rule_type' => $reference['rule_type'],
                'effective_until' => $rules['close']['effective_until'] ?? null,
            ], $username);
        }

        foreach ($plan['exceptions']['delete'] as $exception) {
            $this->write(
                'DELETE FROM portal_schedule_exceptions WHERE id = :id AND employee_id = :employee_id',
                [':id' => $exception['id'], ':employee_id' => $employeeId],
                'excecao de escala'
            );
        }

        foreach ($plan['absences']['cancel'] as $absence) {
            $this->write(
                'UPDATE portal_absences SET status = :cancelled, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at, updated_by = :updated_by
                 WHERE id = :id AND employee_id = :employee_id AND status = :approved',
                [':cancelled' => 'cancelled', ':cancelled_by' => $username, ':cancelled_at' => $stamp, ':updated_by' => $username,
                 ':id' => $absence['id'], ':employee_id' => $employeeId, ':approved' => 'approved'],
                'ausencia'
            );
        }
        foreach ($plan['absences']['cut'] as $cut) {
            $this->write(
                'UPDATE portal_absences SET ends_on = :ends_on, updated_by = :updated_by
                 WHERE id = :id AND employee_id = :employee_id AND status = :approved',
                [':ends_on' => $cut['ends_on'], ':updated_by' => $username, ':id' => $cut['id'], ':employee_id' => $employeeId, ':approved' => 'approved'],
                'ausencia'
            );
        }

        $pause = $plan['pause'];
        if ($pause['action'] === 'finalize') {
            $countsForLimit = GerenciadorPausas::motivo_conta_para_limite($pause['motivo_pausa']);
            $previousNote = trim((string)($entry['observacao_reuniao'] ?? ''));
            $note = self::PAUSE_NOTE . $username;
            $this->write(
                'INSERT INTO pausas (
                    id_funcionario, nome_funcionario, equipe, inicio_pausa, fim_pausa,
                    duracao_segundos, motivo_pausa, alerta_15min, alerta_20min,
                    status_aprovacao, observacao_reuniao
                 ) VALUES (
                    :id_funcionario, :nome_funcionario, :equipe, :inicio_pausa, :fim_pausa,
                    :duracao_segundos, :motivo_pausa, :alerta_15min, :alerta_20min,
                    :status_aprovacao, :observacao_reuniao
                 )',
                [
                    ':id_funcionario' => $employeeId,
                    ':nome_funcionario' => (string)($employee['nome'] ?? ''),
                    ':equipe' => (string)($employee['equipe'] ?? ''),
                    ':inicio_pausa' => $pause['inicio'],
                    ':fim_pausa' => $pause['fim'],
                    ':duracao_segundos' => $pause['duracao_segundos'],
                    ':motivo_pausa' => $pause['motivo_pausa'],
                    ':alerta_15min' => $countsForLimit && $pause['duracao_segundos'] >= 15 * 60 ? 1 : 0,
                    ':alerta_20min' => $countsForLimit && $pause['duracao_segundos'] >= 20 * 60 ? 1 : 0,
                    ':status_aprovacao' => 'aprovado',
                    ':observacao_reuniao' => $previousNote === '' ? $note : $previousNote . ' | ' . $note,
                ],
                'historico da pausa'
            );
        } elseif ($pause['action'] === 'clear_invalid') {
            error_log("[ARCHIVE] Entrada ilegivel do estado de pausas limpa sem historico: funcionario ID {$employeeId}.");
        }

        $this->write(
            'UPDATE funcionarios SET ativo = 0, arquivado_em = :arquivado_em, arquivado_por = :arquivado_por WHERE id = :id',
            [':arquivado_em' => $plan['employee']['arquivado_em'], ':arquivado_por' => $username, ':id' => $employeeId],
            'cadastro'
        );
    }

    /** Historico completo de regras do colaborador, com trava (licao do F1). */
    private function employeeRules(int $employeeId): array {
        return $this->lockedRows(
            'SELECT id, employee_id, rule_type, rule_config, effective_from, effective_until
             FROM portal_schedule_rules WHERE employee_id = :employee_id ORDER BY effective_from, id FOR UPDATE',
            [':employee_id' => $employeeId]
        );
    }

    /**
     * [F1] Nenhuma violacao NOVA no historico de escala depois de gravar
     * (dado antigo com problema nao bloqueia o arquivamento); senao a
     * transacao cai. Mesmo criterio do OperationalService.
     */
    private function assertScheduleHistoryConsistent(array $before, int $employeeId): void {
        $new = array_diff(
            ScheduleRulePlanner::violations($this->employeeRules($employeeId)),
            ScheduleRulePlanner::violations($before)
        );
        if ($new !== []) {
            throw new RuntimeException('Historico de escala inconsistente apos o arquivamento: ' . implode('; ', $new));
        }
    }

    private function allowlisted(array $employee): bool {
        $login = trim((string)($employee['ad_login'] ?? ''));
        return $login !== '' && is_ad_admin_authorized($login);
    }

    /** FUNCIONARIO_ARQUIVADO (CRITICAL): ids, datas, tipos e contagens; nunca nome, login, motivo nem observacao. */
    private function auditArchive(array $plan, array $employee, array $actor, bool $allowlisted): void {
        $ids = static fn (array $items): string => $items === [] ? 'nenhum' : implode(',', array_map(static fn (array $item): string => (string)$item['id'], $items));
        $rules = $plan['rules'];
        $pause = ['finalize' => 'finalizada', 'discard_request' => 'solicitacao_descartada', 'clear_invalid' => 'limpa_sem_historico', 'none' => 'nenhuma'][$plan['pause']['action']];
        audit_log(
            'FUNCIONARIO_ARQUIVADO',
            'funcionario_id=' . (int)$employee['id']
                . '; perfil=' . ($employee['access_role'] ?? '-')
                . '; equipe=' . ($employee['equipe'] ?? '-')
                . '; ator=' . ($actor['role'] ?? '-')
                . '; regularizacao=' . ($plan['employee']['regularizacao'] ? 'sim' : 'nao')
                . '; pa_encerrados=' . $ids($plan['pa']['close'])
                . '; pa_removidos=' . $ids($plan['pa']['remove'])
                . '; regra_encerrada=' . ($rules['close'] === null ? 'nenhuma' : $rules['close']['id'] . ' ate ' . $rules['close']['effective_until'])
                . '; regras_apagadas=' . ($rules['delete'] === [] ? 'nenhuma' : implode(' | ', array_map(
                    static fn (array $rule): string => $rule['id'] . ':' . $rule['rule_type'] . ' ' . $rule['effective_from'] . '..' . ($rule['effective_until'] ?? 'NULL'),
                    $rules['delete']
                )))
                . '; excecoes_apagadas=' . ($plan['exceptions']['delete'] === [] ? 'nenhuma' : implode(' | ', array_map(
                    static fn (array $exception): string => $exception['id'] . ':' . $exception['exception_type'] . ' ' . $exception['exception_date'],
                    $plan['exceptions']['delete']
                )))
                . '; ausencias_canceladas=' . $ids($plan['absences']['cancel'])
                . '; ausencias_cortadas=' . ($plan['absences']['cut'] === [] ? 'nenhuma' : implode(' | ', array_map(
                    static fn (array $cut): string => $cut['id'] . ' ate ' . $cut['ends_on'] . ' (antes ' . $cut['previous_ends_on'] . ')',
                    $plan['absences']['cut']
                )))
                . '; pausa=' . $pause
                . '; allowlist=' . ($allowlisted ? 'sim' : 'nao'),
            'CRITICAL'
        );
    }

    /**
     * Zera a entrada do colaborador no estado lido dentro do lock e grava o
     * arquivo inteiro (as demais entradas como foram lidas). Sem estado
     * legivel, nao ha o que gravar.
     */
    private function clearStateEntry(?array $state, int $employeeId): bool {
        if ($state === null || !isset($state['funcionarios'][$employeeId])) {
            return true;
        }
        $state['funcionarios'][$employeeId] = self::EMPTY_PAUSE_ENTRY;
        $content = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return is_string($content) && ($this->writeState)($this->statePath, $content);
    }

    private function lockedRows(string $sql, array $params): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Gravacao de uma linha travada: exatamente uma linha afetada, senao a transacao cai. */
    private function write(string $sql, array $params, string $label): void {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Arquivamento: gravacao de {$label} afetou " . $stmt->rowCount() . ' linhas (esperado 1).');
        }
    }

    private function rollBack(): void {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
