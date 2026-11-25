<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/PortalTeams.php';
require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/PresenceRepository.php';
require_once __DIR__ . '/ScheduleRulePlanner.php';
require_once __DIR__ . '/CriticalRolePlanner.php';
require_once __DIR__ . '/CriticalRoleService.php';
require_once __DIR__ . '/AbsenceRules.php';
require_once __DIR__ . '/AbsenceService.php';
require_once __DIR__ . '/OperationalService.php';

/**
 * [Escala do Critico em grade, D-ESC-1 a D-ESC-20] O mes do Critico em
 * semanas (seg a sex), pessoa x dia, pelo motor de presenca (uma fonte so):
 * ausencia de dia inteiro > excecao de trabalho por data > feriado > troca >
 * rodizio > regra individual (D-ESC-2). Carga fixa de 8 consultas, qualquer
 * que seja o numero de pessoas e de dias (D-ESC-3).
 *
 * O dia de uma pessoa ("celula") traz o rotulo, o tom da cor, a origem, a
 * jornada, quem alterou por ultimo e a versao (D-ESC-6). Quem nao tem
 * escalas.manage ve a ausencia so como Ferias, Banco de horas ou Ausente
 * (D-ESC-5); a observacao nunca sai.
 *
 * Gravacao (escalas.manage): previa e gravacao do dia ou do intervalo de uma
 * pessoa, pelos servicos que ja existem (troca do 9.16, ausencias, excecao
 * por data), numa transacao, com o colaborador travado e a versao de cada dia
 * conferida (409 com a contagem). Auditoria so com IDs (D-ESC-20).
 */
final class CriticalGridService {
    public const FORBIDDEN_MESSAGE = 'Apenas administradores e Liderança alteram a escala do Crítico.';
    public const STALE_MESSAGE = '%d dia(s) mudaram desde que a tela carregou: recarregue e repita.';
    public const MAX_RANGE_DAYS = 31;
    public const MONTHS = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    public const WEEKDAYS = [1 => 'Seg', 'Ter', 'Qua', 'Qui', 'Sex'];
    /** [D-ESC-9] Excecao do dia: presencial, home ou treinamento (D-ESC-8). */
    public const EXCEPTION_CHOICES = ['onsite' => 'Presencial (exceção)', 'remote' => 'Home (exceção)', 'training' => 'Treinamento'];
    public const UNDO_TARGETS = ['troca', 'ausencia', 'excecao'];
    private const LEGACY_ABSENCE_LABELS = ['vacation' => 'ferias'];
    private const WORK_EXCEPTION_LABELS = ['onsite' => 'Presencial (exceção)', 'remote' => 'Home (exceção)', 'training' => 'Treinamento', 'oncall' => 'Plantão'];

    private PDO $pdo;
    private ?string $today;
    private ?Closure $audit;

    /** $today (Y-m-d) e $audit, so para teste; padrao: a data do PHP e o audit_log. */
    public function __construct(?PDO $pdo = null, ?string $today = null, ?Closure $audit = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->today = $today;
        $this->audit = $audit;
    }

    // ------------------------------------------------------------------
    // Leitura do mes
    // ------------------------------------------------------------------

    /** GET do mes (?month=AAAA-MM; padrao: o mes de hoje). */
    public function month(array $query, array $actor): array {
        [$year, $month] = $this->monthParam($query['month'] ?? null);
        $weeks = self::weeks($year, $month);
        $from = $weeks[0]['days'][0];
        $to = end($weeks)['days'][4];
        $manager = portal_role_can($actor['role'] ?? null, 'escalas.manage');
        $data = $this->load($from, $to);
        $people = $this->orderedPeople($data, sprintf('%04d-%02d-01', $year, $month), $to);
        $today = $this->today();
        $monthKey = sprintf('%04d-%02d', $year, $month);
        $cells = [];
        $summary = [];
        foreach ($people as $person) {
            $summary[$person['id']] = ['employee_id' => $person['id'], 'roles' => [], 'home' => 0, 'onsite' => 0, 'absent' => 0, 'holiday' => 0];
            foreach ($weeks as $week) {
                foreach ($week['days'] as $date) {
                    $cell = $this->cell($data, $person, $date, $manager) + ['in_month' => substr($date, 0, 7) === $monthKey];
                    $cells[$person['id'] . ':' . $date] = $cell;
                    if ($cell['in_month']) {
                        $summary[$person['id']] = self::tally($summary[$person['id']], $cell);
                    }
                }
            }
        }
        return [
            'month' => ['key' => $monthKey, 'label' => self::MONTHS[$month] . ' ' . $year, 'prev' => self::shiftMonth($year, $month, -1), 'next' => self::shiftMonth($year, $month, 1)],
            'today' => $today,
            'can_manage' => $manager,
            'roles' => array_values(array_map(static fn (array $role): array => [
                'id' => $role['id'], 'name' => $role['name'], 'presence_type' => $role['presence_type'],
                'work_start' => $role['work_start'], 'work_end' => $role['work_end'], 'active' => $role['active'],
                'color_token' => $role['color_token'] ?? null, 'label' => CriticalRolePlanner::label($role),
            ], $data['roles'])),
            'weeks' => array_map(static fn (array $week): array => $week + ['current' => $today >= $week['days'][0] && $today <= ScheduleRulePlanner::addDays($week['days'][0], 6)], $weeks),
            'people' => array_map(static fn (array $person): array => ['id' => $person['id'], 'short_name' => $person['short_name'], 'name' => $person['name']], $people),
            'cells' => $cells,
            'summary' => array_values(array_map(static fn (array $row): array => $row + [
                'onsite_pct' => $row['onsite'] + $row['home'] === 0 ? null : (int)round(100 * $row['onsite'] / ($row['onsite'] + $row['home'])),
            ], $summary)),
            'absence_choices' => $manager ? array_map(static fn (string $key): array => ['value' => $key, 'label' => AbsenceRules::CATEGORIES[$key]], array_keys(AbsenceRules::CATEGORIES)) : [],
            'exception_choices' => $manager ? array_map(static fn (string $key): array => ['value' => $key, 'label' => self::EXCEPTION_CHOICES[$key]], array_keys(self::EXCEPTION_CHOICES)) : [],
        ];
    }

    // ------------------------------------------------------------------
    // Previa e gravacao do dia (ou do intervalo de uma pessoa)
    // ------------------------------------------------------------------

    /** Previa (sem gravar): por dia, antes -> depois, e os avisos do "1 disponivel" (D-ESC-11). */
    public function preview(array $data, array $actor): array {
        $this->assertCanManage($actor);
        $request = $this->changeRequest($data);
        $loaded = $this->load($request['from'], $request['to']);
        $person = $this->criticalPerson($loaded, $request['employee_id']);
        [$days, $simulated] = $this->simulate($loaded, $request);
        return $this->effects($loaded, $simulated, $person, $days) + ['request_key' => self::requestKey($request)];
    }

    /**
     * [Achado das capturas, 2026-11-25] Na celula do feriado o rotulo ja e
     * "Feriado": o detalhe e so o nome do cadastro (nada de "Feriado: <nome>").
     */
    private static function holidayName(?array $holiday): ?string {
        $name = trim((string)($holiday['name'] ?? ''));
        return $name === '' ? null : $name;
    }

    /**
     * Chave do pedido da previa (achado das capturas, 2026-11-25): a tela so
     * liga o salvar com a previa cuja chave e a dos parametros atuais. Formato
     * igual ao de previewKey() em frontend/src/lib/criticalGrid.ts:
     * pessoa|de|ate|tipo|valor (papel, categoria, excecao ou alvo:registro).
     */
    public static function requestKey(array $request): string {
        $change = $request['change'];
        $value = match ($change['type']) {
            'role' => (string)$change['role_id'],
            'absence' => $change['category'],
            'exception' => $change['exception_type'],
            'undo' => $change['target'] . ':' . $change['ref_id'],
        };
        return implode('|', [(string)$request['employee_id'], $request['from'], $request['to'], $change['type'], $value]);
    }

    /**
     * Grava a mudanca numa transacao: trava o colaborador, confere a versao
     * de cada dia mandado (409 com a contagem) e chama o servico de sempre.
     */
    public function apply(array $data, array $actor): array {
        $this->assertCanManage($actor);
        $request = $this->changeRequest($data);
        $versions = $data['versions'] ?? null;
        if (!is_array($versions) || $versions === []) {
            throw new InvalidArgumentException('Versões dos dias ausentes: recarregue a tela.');
        }
        return $this->atomic(function () use ($request, $versions, $actor): array {
            $this->lockEmployee($request['employee_id']);
            $loaded = $this->load($request['from'], $request['to']);
            $person = $this->criticalPerson($loaded, $request['employee_id']);
            $stale = 0;
            foreach ($versions as $date => $version) {
                if (!is_string($date) || !PresenceEngine::validDate($date) || $date < $request['from'] || $date > $request['to']) {
                    throw new InvalidArgumentException('Versões dos dias inválidas: recarregue a tela.');
                }
                if ($this->cell($loaded, $person, $date, true)['version'] !== $version) {
                    $stale++;
                }
            }
            if ($stale > 0) {
                throw new DomainException(sprintf(self::STALE_MESSAGE, $stale));
            }
            [$days, $simulated] = $this->simulate($loaded, $request);
            $effects = $this->effects($loaded, $simulated, $person, $days);
            $this->write($request, $loaded, $actor);
            $change = $request['change'];
            $this->audit('ESCALA_CRITICO_DIA', 'acao=' . $change['type'] . ($change['type'] === 'undo' ? '_' . $change['target'] : '')
                . '; employee_id=' . $request['employee_id'] . '; de=' . $request['from'] . '; ate=' . $request['to']
                . '; dias=' . count($effects['changes']) . '; avisos=' . count($effects['warnings']));
            return $effects;
        });
    }

    // ------------------------------------------------------------------
    // Pedido, simulacao e efeitos
    // ------------------------------------------------------------------

    /** Valida o pedido: pessoa, intervalo (ate 31 dias) e a mudanca. */
    private function changeRequest(array $data): array {
        $employeeId = filter_var($data['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($employeeId === false) {
            throw new InvalidArgumentException('Colaborador inválido.');
        }
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? $from;
        if (!is_string($from) || !PresenceEngine::validDate($from) || !is_string($to) || !PresenceEngine::validDate($to) || $to < $from) {
            throw new InvalidArgumentException('Dias inválidos: o fim deve ser igual ou posterior ao início.');
        }
        if ((int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Até ' . self::MAX_RANGE_DAYS . ' dias por vez.');
        }
        $change = is_array($data['change'] ?? null) ? $data['change'] : [];
        $type = $change['type'] ?? null;
        $normalized = match ($type) {
            'role' => ['type' => 'role', 'role_id' => $this->positiveId($change['role_id'] ?? null, 'Papel inválido.')],
            'absence' => ['type' => 'absence', 'category' => is_string($change['category'] ?? null) && isset(AbsenceRules::CATEGORIES[$change['category']]) ? $change['category'] : throw new InvalidArgumentException('Tipo de ausência inválido.')],
            'exception' => ['type' => 'exception', 'exception_type' => is_string($change['exception_type'] ?? null) && isset(self::EXCEPTION_CHOICES[$change['exception_type']]) ? $change['exception_type'] : throw new InvalidArgumentException('Exceção inválida: presencial, home ou treinamento.')],
            'undo' => ['type' => 'undo', 'target' => in_array($change['target'] ?? null, self::UNDO_TARGETS, true) ? $change['target'] : throw new InvalidArgumentException('O que desfazer: troca, ausência ou exceção.'), 'ref_id' => $this->positiveId($change['ref_id'] ?? null, 'Registro inválido.')],
            default => throw new InvalidArgumentException('Mudança inválida: trocar o papel, marcar ausência, exceção ou desfazer.'),
        };
        if ($normalized['type'] === 'undo' && $from !== $to) {
            throw new InvalidArgumentException('Desfazer vale para um dia por vez.');
        }
        return ['employee_id' => (int)$employeeId, 'from' => $from, 'to' => $to, 'change' => $normalized];
    }

    /**
     * Aplica a mudanca numa copia dos dados carregados. Devolve os dias
     * afetados (uteis; a troca e a excecao sem fim de semana nem feriado,
     * D-ESC-12) e a copia. O desfazer cobre o registro inteiro (D-ESC-10).
     */
    private function simulate(array $data, array $request): array {
        $id = $request['employee_id'];
        $change = $request['change'];
        $from = $request['from'];
        $to = $request['to'];
        $copy = $data;
        switch ($change['type']) {
            case 'role':
                $role = $data['roles'][$change['role_id']] ?? null;
                if ($role === null || $role['active'] !== true) {
                    throw new DomainException('Papel desativado ou inexistente: escolha um papel ativo.');
                }
                $overlap = count(array_filter($data['overrides'], static fn (array $o): bool => $o['employee_id'] === $id && $o['starts_on'] <= $to && $o['ends_on'] >= $from));
                if ($overlap > 0) {
                    throw new DomainException("{$overlap} troca(s) já cobrem o período: desfaça antes.");
                }
                $copy['overrides'][] = ['id' => 0, 'employee_id' => $id, 'starts_on' => $from, 'ends_on' => $to, 'role_id' => $change['role_id'], 'reason' => 'troca', 'updated_by' => '', 'updated_at' => ''];
                break;
            case 'absence':
                $candidate = ['employee_id' => $id, 'starts_on' => $from, 'ends_on' => $to, 'period_type' => 'full_day', 'start_time' => null, 'end_time' => null, 'category' => $change['category']];
                $existing = array_values(array_filter($data['absences'], static fn (array $a): bool => $a['employee_id'] === $id));
                $conflict = AbsenceRules::findConflict($candidate, $existing, null);
                if ($conflict !== null) {
                    throw new DomainException(AbsenceRules::conflictMessage($conflict));
                }
                $copy['absences'][] = $candidate + ['id' => 0, 'created_by' => '', 'updated_by' => null, 'updated_at' => ''];
                return [self::dates($from, $to, false, []), $copy];
            case 'exception':
                foreach (self::dates($from, $to, true, $data['holidays']) as $date) {
                    $copy['exceptions'][$id . ':' . $date] = ['id' => 0, 'employee_id' => $id, 'exception_date' => $date, 'exception_type' => $change['exception_type'], 'created_by' => '', 'updated_at' => ''];
                }
                break;
            case 'undo':
                return $this->simulateUndo($data, $copy, $request);
        }
        return [self::dates($from, $to, true, $data['holidays']), $copy];
    }

    private function simulateUndo(array $data, array $copy, array $request): array {
        $id = $request['employee_id'];
        $change = $request['change'];
        $date = $request['from'];
        if ($change['target'] === 'troca') {
            $found = array_values(array_filter($data['overrides'], static fn (array $o): bool => $o['id'] === $change['ref_id'] && $o['employee_id'] === $id && $o['starts_on'] <= $date && $o['ends_on'] >= $date));
            if ($found === []) {
                throw new DomainException('Esta troca não cobre mais o dia: recarregue a tela.');
            }
            $copy['overrides'] = array_values(array_filter($data['overrides'], static fn (array $o): bool => $o['id'] !== $change['ref_id']));
            return [self::dates($found[0]['starts_on'], $found[0]['ends_on'], true, $data['holidays']), $copy];
        }
        if ($change['target'] === 'ausencia') {
            $found = array_values(array_filter($data['absences'], static fn (array $a): bool => $a['id'] === $change['ref_id'] && $a['employee_id'] === $id && $a['starts_on'] <= $date && $a['ends_on'] >= $date));
            if ($found === []) {
                throw new DomainException('Esta ausência não cobre mais o dia: recarregue a tela.');
            }
            $copy['absences'] = array_values(array_filter($data['absences'], static fn (array $a): bool => $a['id'] !== $change['ref_id']));
            return [self::dates($found[0]['starts_on'], $found[0]['ends_on'], false, []), $copy];
        }
        $key = $id . ':' . $date;
        if (($data['exceptions'][$key]['id'] ?? null) !== $change['ref_id']) {
            throw new DomainException('Esta exceção não está mais no dia: recarregue a tela.');
        }
        unset($copy['exceptions'][$key]);
        return [[$date], $copy];
    }

    /** "Qui 19/11: Home 10:30–20:30 → Banco de horas" por dia e os avisos do "1 disponivel". */
    private function effects(array $before, array $after, array $person, array $days): array {
        $changes = [];
        $warnings = [];
        foreach ($days as $date) {
            $old = $this->cell($before, $person, $date, true);
            $new = $this->cell($after, $person, $date, true);
            $changes[] = ['date' => $date, 'before' => $old['label'], 'after' => $new['label'], 'text' => self::dayLabel($date) . ': ' . $old['label'] . ' → ' . $new['label']];
            if (PresenceEngine::isBusinessDay($date) && !isset($after['holidays'][$date]) && $this->available($after, $date) === 0) {
                $warnings[] = ['date' => $date, 'text' => self::dayLabel($date) . ': o Crítico fica sem ninguém disponível.'];
            }
        }
        return ['changes' => $changes, 'warnings' => $warnings];
    }

    /** Pessoas do Critico disponiveis no dia pelo motor (presencial ou remoto, fora ausencia de dia inteiro). */
    private function available(array $data, string $date): int {
        $count = 0;
        foreach ($data['employees'] as $person) {
            $situation = $this->presence($data, $person, $date)['situacao'];
            if (in_array($situation, ['presencial', 'remoto'], true)) {
                $count++;
            }
        }
        return $count;
    }

    /** Grava pelo servico de sempre (as regras e a auditoria de cada um valem). */
    private function write(array $request, array $data, array $actor): void {
        $id = $request['employee_id'];
        $change = $request['change'];
        $role = (string)($actor['role'] ?? '');
        $username = (string)($actor['username'] ?? 'anonymous');
        switch ($change['type']) {
            case 'role':
                (new CriticalRoleService($this->pdo, $this->today))->addOverride(['employee_id' => $id, 'starts_on' => $request['from'], 'ends_on' => $request['to'], 'role_id' => $change['role_id'], 'reason' => 'troca'], $username, $role);
                return;
            case 'absence':
                (new AbsenceService($this->pdo, $this->today))->create(['employee_id' => $id, 'category' => $change['category'], 'starts_on' => $request['from'], 'ends_on' => $request['to'], 'period_type' => 'full_day'], $actor);
                return;
            case 'exception':
                $operational = new OperationalService($this->pdo, $this->today);
                foreach (self::dates($request['from'], $request['to'], true, $data['holidays']) as $date) {
                    $operational->saveScheduleException(['employee_id' => $id, 'exception_date' => $date, 'exception_type' => $change['exception_type']], $username, $role);
                }
                return;
            case 'undo':
                if ($change['target'] === 'troca') {
                    (new CriticalRoleService($this->pdo, $this->today))->removeOverride(['override_id' => $change['ref_id']], $role);
                } elseif ($change['target'] === 'ausencia') {
                    (new AbsenceService($this->pdo, $this->today))->cancel(['id' => $change['ref_id']], $actor);
                } else {
                    $this->removeException($change['ref_id'], $id, $request['from'], $role);
                }
                return;
        }
    }

    /** [D-ESC-10] Tira a excecao de trabalho daquele dia (data anterior a hoje: escalas.retroativa). */
    private function removeException(int $exceptionId, int $employeeId, string $date, string $role): void {
        if ($date < $this->today() && !portal_role_can($role, 'escalas.retroativa')) {
            throw new PortalForbiddenException('Somente administradores e Liderança alteram data anterior a hoje.');
        }
        $stmt = $this->pdo->prepare('DELETE FROM portal_schedule_exceptions WHERE id = :id AND employee_id = :employee_id AND exception_date = :exception_date');
        $stmt->execute([':id' => $exceptionId, ':employee_id' => $employeeId, ':exception_date' => $date]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Esta exceção não está mais no dia: recarregue a tela.');
        }
        $this->audit('EXCECAO_ESCALA_REMOVIDA', "excecao_id={$exceptionId}; employee_id={$employeeId}; data={$date}");
    }

    // ------------------------------------------------------------------
    // A celula do dia
    // ------------------------------------------------------------------

    /** Situacao do motor no dia (a mesma conta do engineSchedule e do PresenceService). */
    private function presence(array $data, array $person, string $date): array {
        $id = $person['id'];
        $exception = $data['exceptions'][$id . ':' . $date] ?? null;
        $absences = [];
        if (in_array($exception['exception_type'] ?? null, PresenceEngine::ABSENCE_EXCEPTIONS, true)) {
            $absences[] = ['origem' => 'excecao', 'period_type' => 'full_day'];
        }
        foreach ($this->absencesOn($data, $id, $date) as $absence) {
            $absences[] = ['origem' => 'ausencia', 'period_type' => $absence['period_type'], 'start_time' => $absence['start_time'], 'end_time' => $absence['end_time'], 'category' => $absence['category']];
        }
        $role = CriticalRolePlanner::dayRole($id, $date, $data['rotations'], $data['overrides'], $data['roles']);
        $shifted = PresenceRepository::shiftFor($person, $date, $role, $data['holidays'][$date] ?? null);
        return PresenceEngine::dayPresence($shifted, $date, $data['rules'][$id] ?? [], $exception === null ? null : ['exception_type' => $exception['exception_type']], $absences) + ['role' => $role, 'shift' => $shifted];
    }

    /** A celula: rotulo, tom, origem, jornada, marcador, autoria, refs (gestao) e versao. */
    private function cell(array $data, array $person, string $date, bool $manager): array {
        $id = $person['id'];
        $presence = $this->presence($data, $person, $date);
        $role = $presence['role'];
        $shift = $presence['shift'];
        $exception = $data['exceptions'][$id . ':' . $date] ?? null;
        $absences = $this->absencesOn($data, $id, $date);
        $override = $role !== null && $role['source'] === 'troca' ? CriticalRolePlanner::overrideAt($data['overrides'], $id, $date) : null;
        $rotation = CriticalRolePlanner::rotationAt($data['rotations'], $date);
        $holiday = $data['holidays'][$date] ?? null;
        $hours = substr((string)$shift['work_start'], 0, 5) . '–' . substr((string)$shift['work_end'], 0, 5);
        $fonte = $presence['escala']['fonte'];
        $cell = ['kind' => 'sem_escala', 'label' => 'Sem escala', 'tone' => 'sem', 'source' => 'nenhuma', 'marker' => null, 'presence_type' => null, 'hours' => null, 'detail' => null, 'role_id' => null, 'last_change' => null];
        if ($presence['situacao'] === 'ausente') {
            $full = array_values(array_filter($absences, static fn (array $a): bool => $a['period_type'] === 'full_day'));
            $category = $full[0]['category'] ?? (self::LEGACY_ABSENCE_LABELS[$exception['exception_type'] ?? ''] ?? null);
            $cell = ['kind' => 'ausencia', 'label' => self::absenceLabel($category, $manager), 'tone' => self::absenceTone($category), 'source' => 'ausencia']
                + ['last_change' => $full !== [] ? self::change($full[0]['updated_by'] ?? null ?: $full[0]['created_by'], $full[0]['updated_at'] ?? null) : self::change($exception['created_by'] ?? null, $exception['updated_at'] ?? null)] + $cell;
        } elseif ($fonte === 'feriado') {
            $cell = ['kind' => 'feriado', 'label' => 'Feriado', 'tone' => 'feriado', 'source' => 'feriado', 'detail' => self::holidayName($holiday)] + $cell;
        } elseif ($fonte === 'excecao') {
            $type = (string)$exception['exception_type'];
            $cell = ['kind' => 'excecao', 'label' => self::WORK_EXCEPTION_LABELS[$type] ?? 'Exceção', 'tone' => $type === 'remote' ? 'papel-lilas' : 'papel-azul', 'source' => 'excecao', 'marker' => 'excecao', 'presence_type' => $type === 'remote' ? 'remote' : 'onsite', 'hours' => $hours, 'last_change' => self::change($exception['created_by'] ?? null, $exception['updated_at'] ?? null)] + $cell;
        } elseif ($fonte === 'papel' && $role !== null) {
            $source = $role['source'];
            $cell = ['kind' => 'papel', 'label' => CriticalRolePlanner::label($role), 'tone' => 'papel-' . ($role['color_token'] ?? 'areia'), 'source' => $source, 'marker' => $source === 'troca' ? 'troca' : null, 'presence_type' => $role['presence_type'], 'hours' => $hours, 'role_id' => $role['id'],
                'last_change' => $source === 'troca' ? self::change($override['updated_by'] ?? null, $override['updated_at'] ?? null) : self::change($rotation['updated_by'] ?? null, $rotation['updated_at'] ?? null)] + $cell;
        } elseif ($fonte === 'regra' && in_array($presence['escala']['situacao'], ['presencial', 'remoto'], true)) {
            $onsite = $presence['escala']['situacao'] === 'presencial';
            $cell = ['kind' => 'regra', 'label' => ($onsite ? 'Presencial ' : 'Home ') . $hours, 'tone' => $onsite ? 'papel-azul' : 'papel-lilas', 'source' => 'regra', 'presence_type' => $onsite ? 'onsite' : 'remote', 'hours' => $hours] + $cell;
        }
        $partial = array_values(array_filter($absences, static fn (array $a): bool => $a['period_type'] !== 'full_day'));
        if ($partial !== [] && $cell['kind'] !== 'ausencia') {
            $span = substr((string)$partial[0]['start_time'], 0, 5) . ($partial[0]['end_time'] === null ? ' em diante' : '–' . substr((string)$partial[0]['end_time'], 0, 5));
            $cell['detail'] = 'Ausente ' . $span . ($manager ? ' (' . AbsenceRules::CATEGORIES[$partial[0]['category']] . ')' : '');
        }
        if ($cell['kind'] === 'feriado' && $holiday !== null) {
            $cell['detail'] = self::holidayName($holiday);
        }
        $refs = [];
        if ($override !== null) {
            $refs['troca'] = ['id' => $override['id'], 'starts_on' => $override['starts_on'], 'ends_on' => $override['ends_on']];
        }
        if ($absences !== []) {
            $refs['ausencia'] = ['id' => $absences[0]['id'], 'starts_on' => $absences[0]['starts_on'], 'ends_on' => $absences[0]['ends_on']];
        }
        if ($exception !== null && isset(self::WORK_EXCEPTION_LABELS[$exception['exception_type']])) {
            $refs['excecao'] = ['id' => $exception['id'], 'date' => $date];
        }
        $rule = ScheduleRulePlanner::ruleAt($data['rules'][$id] ?? [], $date);
        $cell['version'] = substr(sha1((string)json_encode([
            $role['id'] ?? null, $role['source'] ?? null, $override['id'] ?? null, $override['updated_at'] ?? null,
            array_map(static fn (array $a): array => [$a['id'], $a['updated_at'] ?? null], $absences),
            $exception === null ? null : [$exception['id'], $exception['exception_type'], $exception['updated_at'] ?? null],
            $holiday['id'] ?? null, $rotation['id'] ?? null, $rotation['updated_at'] ?? null, $rule['id'] ?? null,
        ])), 0, 12);
        return $cell + ['refs' => $manager ? $refs : []];
    }

    private static function absenceLabel(?string $category, bool $manager): string {
        if ($category === 'ferias') {
            return 'Férias';
        }
        if ($category === 'banco_horas') {
            return 'Banco de horas';
        }
        return $manager && $category !== null && isset(AbsenceRules::CATEGORIES[$category]) ? AbsenceRules::CATEGORIES[$category] : 'Ausente';
    }

    private static function absenceTone(?string $category): string {
        return match ($category) {
            'ferias' => 'ferias',
            'banco_horas' => 'banco',
            default => 'ausente',
        };
    }

    private static function change(?string $by, ?string $at): ?array {
        $by = trim((string)$by);
        return $by === '' ? null : ['by' => $by, 'at' => $at === null || $at === '' ? null : (string)$at];
    }

    /** Contagem do resumo do mes (D-ESC-4): dias por papel, home, presencial, ausencias e feriados. */
    private static function tally(array $row, array $cell): array {
        if ($cell['kind'] === 'papel' && $cell['role_id'] !== null) {
            $row['roles'][(string)$cell['role_id']] = ($row['roles'][(string)$cell['role_id']] ?? 0) + 1;
        }
        if ($cell['presence_type'] === 'remote') {
            $row['home']++;
        } elseif ($cell['presence_type'] === 'onsite') {
            $row['onsite']++;
        } elseif ($cell['kind'] === 'ausencia') {
            $row['absent']++;
        } elseif ($cell['kind'] === 'feriado') {
            $row['holiday']++;
        }
        return $row;
    }

    // ------------------------------------------------------------------
    // Carga, datas e pessoas
    // ------------------------------------------------------------------

    /**
     * Carga do intervalo, em 8 consultas fixas: cadastro (o do motor), regras,
     * excecoes, ausencias aprovadas, papeis, rodizios, trocas e feriados. Sem
     * observacao (note/reason) em nenhuma.
     */
    private function load(string $from, string $to): array {
        $repository = new PresenceRepository($this->pdo);
        $employees = [];
        foreach ($repository->employees() as $employee) {
            if ($employee['team'] === PortalTeams::CRITICAL && !$employee['outside_map']) {
                $employees[$employee['id']] = $employee;
            }
        }
        $stmt = $this->pdo->prepare('SELECT id, employee_id, exception_date, exception_type, created_by, updated_at FROM portal_schedule_exceptions WHERE exception_date BETWEEN :date_from AND :date_to');
        $stmt->execute([':date_from' => $from, ':date_to' => $to]);
        $exceptions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exceptions[(int)$row['employee_id'] . ':' . (string)$row['exception_date']] = ['id' => (int)$row['id'], 'employee_id' => (int)$row['employee_id'], 'exception_date' => (string)$row['exception_date'], 'exception_type' => (string)$row['exception_type'], 'created_by' => (string)$row['created_by'], 'updated_at' => $row['updated_at'] === null ? null : (string)$row['updated_at']];
        }
        $stmt = $this->pdo->prepare("SELECT id, employee_id, category, starts_on, ends_on, period_type, start_time, end_time, created_by, updated_by, updated_at FROM portal_absences WHERE status = 'approved' AND starts_on <= :date_to AND ends_on >= :date_from ORDER BY id");
        $stmt->execute([':date_from' => $from, ':date_to' => $to]);
        $absences = array_map(static fn (array $row): array => [
            'id' => (int)$row['id'], 'employee_id' => (int)$row['employee_id'], 'category' => (string)$row['category'],
            'starts_on' => (string)$row['starts_on'], 'ends_on' => (string)$row['ends_on'], 'period_type' => (string)$row['period_type'],
            'start_time' => $row['start_time'] === null ? null : (string)$row['start_time'], 'end_time' => $row['end_time'] === null ? null : (string)$row['end_time'],
            'created_by' => (string)$row['created_by'], 'updated_by' => $row['updated_by'] === null ? null : (string)$row['updated_by'], 'updated_at' => $row['updated_at'] === null ? null : (string)$row['updated_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $roles = [];
        foreach ($this->pdo->query('SELECT id, name, presence_type, work_start, work_end, active, sort_order, color_token FROM portal_critical_roles ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roles[(int)$row['id']] = CriticalRolePlanner::roleView($row);
        }
        $stmt = $this->pdo->prepare('SELECT id, effective_from, period, anchor_date, roles, employees, updated_by, updated_at FROM portal_critical_role_rotations WHERE effective_from <= :date_to ORDER BY effective_from');
        $stmt->execute([':date_to' => $to]);
        $rotations = array_map(static fn (array $row): array => CriticalRolePlanner::rotationView($row) + ['updated_by' => (string)$row['updated_by'], 'updated_at' => $row['updated_at'] === null ? null : (string)$row['updated_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare('SELECT id, employee_id, starts_on, ends_on, role_id, reason, updated_by, updated_at FROM portal_critical_role_overrides WHERE starts_on <= :date_to AND ends_on >= :date_from ORDER BY employee_id, starts_on');
        $stmt->execute([':date_from' => $from, ':date_to' => $to]);
        $overrides = array_map(static fn (array $row): array => CriticalRolePlanner::overrideView($row) + ['updated_by' => (string)$row['updated_by'], 'updated_at' => $row['updated_at'] === null ? null : (string)$row['updated_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return [
            'employees' => $employees,
            'rules' => $repository->rulesByEmployee($from, $to),
            'exceptions' => $exceptions,
            'absences' => $absences,
            'roles' => $roles,
            'rotations' => $rotations,
            'overrides' => $overrides,
            'holidays' => $repository->holidaysByDate($from, $to),
        ];
    }

    private function absencesOn(array $data, int $employeeId, string $date): array {
        return array_values(array_filter($data['absences'], static fn (array $a): bool => $a['employee_id'] === $employeeId && $a['starts_on'] <= $date && $a['ends_on'] >= $date));
    }

    /**
     * Pessoas na ordem do rodizio vigente no dia 1 do mes (sem ele, o do fim
     * da grade), depois pelo nome; nome curto (D-ESC-4).
     */
    private function orderedPeople(array $data, string $firstDay, string $lastDay): array {
        $rotation = CriticalRolePlanner::rotationAt($data['rotations'], $firstDay) ?? CriticalRolePlanner::rotationAt($data['rotations'], $lastDay);
        $order = $rotation === null ? [] : array_flip($rotation['employees']);
        $people = array_values($data['employees']);
        usort($people, static fn (array $a, array $b): int => [$order[$a['id']] ?? PHP_INT_MAX, $a['name'], $a['id']] <=> [$order[$b['id']] ?? PHP_INT_MAX, $b['name'], $b['id']]);
        $first = static fn (string $name): string => explode(' ', trim($name))[0] ?? $name;
        $counts = array_count_values(array_map(static fn (array $p): string => $first($p['name']), $people));
        return array_map(static function (array $person) use ($first, $counts): array {
            $parts = preg_split('/\s+/u', trim($person['name'])) ?: [$person['name']];
            $short = $first($person['name']);
            if (($counts[$short] ?? 0) > 1 && isset($parts[1])) {
                $short .= ' ' . (preg_match('/^./u', $parts[1], $initial) === 1 ? $initial[0] : '') . '.';
            }
            return $person + ['short_name' => $short];
        }, $people);
    }

    private function criticalPerson(array $data, int $employeeId): array {
        if (!isset($data['employees'][$employeeId])) {
            throw new DomainException('Só cadastros ativos do Crítico têm escala nesta tela.');
        }
        return $data['employees'][$employeeId];
    }

    private function lockEmployee(int $employeeId): void {
        $stmt = $this->pdo->prepare('SELECT id FROM funcionarios WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new DomainException('Só cadastros ativos do Crítico têm escala nesta tela.');
        }
    }

    /** Semanas (seg a sex) com algum dia util do mes (D-ESC-4). */
    public static function weeks(int $year, int $month): array {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'));
        $last = $first->modify('last day of this month');
        $monday = $first->modify('-' . ((int)$first->format('N') - 1) . ' days');
        if ((int)$first->format('N') > 5) {
            $monday = $monday->modify('+7 days');
        }
        $weeks = [];
        for ($day = $monday; $day <= $last; $day = $day->modify('+7 days')) {
            $days = [];
            for ($i = 0; $i < 5; $i++) {
                $days[] = $day->modify("+{$i} days")->format('Y-m-d');
            }
            $weeks[] = ['iso' => (int)$day->format('W'), 'start' => $days[0], 'days' => $days];
        }
        return $weeks;
    }

    /** Dias do intervalo; com $workOnly, so os uteis sem feriado. */
    private static function dates(string $from, string $to, bool $workOnly, array $holidays): array {
        $dates = [];
        for ($date = $from; $date <= $to; $date = ScheduleRulePlanner::addDays($date, 1)) {
            if (!$workOnly || (PresenceEngine::isBusinessDay($date) && !isset($holidays[$date]))) {
                $dates[] = $date;
            }
        }
        return $dates;
    }

    /** "Qui 19/11". */
    public static function dayLabel(string $date): string {
        $day = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $names = [1 => 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        return $names[(int)$day->format('N')] . ' ' . $day->format('d/m');
    }

    private function monthParam(mixed $value): array {
        $value = $value === null || $value === '' ? substr($this->today(), 0, 7) : $value;
        if (!is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) !== 1 || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            throw new InvalidArgumentException('Mês inválido (AAAA-MM).');
        }
        return [(int)$m[1], (int)$m[2]];
    }

    private static function shiftMonth(int $year, int $month, int $delta): string {
        $index = $year * 12 + ($month - 1) + $delta;
        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }

    private function positiveId(mixed $value, string $message): int {
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException($message);
        }
        return (int)$id;
    }

    private function assertCanManage(array $actor): void {
        if (!portal_role_can($actor['role'] ?? null, 'escalas.manage')) {
            throw new PortalForbiddenException(self::FORBIDDEN_MESSAGE);
        }
    }

    private function audit(string $action, string $details): void {
        if ($this->audit !== null) {
            ($this->audit)($action, $details, 'INFO');
        } elseif (function_exists('audit_log')) {
            audit_log($action, $details, 'INFO');
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
