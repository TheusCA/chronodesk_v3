<?php
declare(strict_types=1);

require_once __DIR__ . '/EscalaBaseWorkbook.php';
require_once __DIR__ . '/OperationalService.php';
require_once __DIR__ . '/RotationTeamService.php';
require_once __DIR__ . '/AbsenceService.php';
require_once __DIR__ . '/PaMapService.php';
require_once __DIR__ . '/PaInventoryRules.php';
require_once __DIR__ . '/CyclePattern.php';

/**
 * [F10] Importacao da planilha "Escala Home - Base" (so admin,
 * escala_mensal.import; desenho do F10, 8.9, D-F10-53 a D-F10-58).
 *
 * Le EQUIPE, REGRAS FIXAS, EXCECOES, CONFIG e CONFIG PA e casa nome -> ID pelo
 * casamento-nome-id.csv (nome normalizado do script). A previa e obrigatoria
 * e nada e gravado sem confirmar; a confirmacao refaz a previa e grava pelos
 * servicos de sempre (RotationTeamService, OperationalService, AbsenceService,
 * PaMapService), numa transacao: as regras, os 409 e a auditoria de cada um
 * valem. Idempotente: o que ja esta igual fica "igual" e nao e gravado de novo.
 *
 * Passos (cada item tem passo, situacao e a referencia "ABA linha N", nunca o
 * nome): times (a conversao do rodizio do plano-times-rodizio: Time 1, 2 e 3
 * pela fase da CONFIG, ancora da CONFIG, e os membros), regras (FIXO, SEM HOME
 * e FULL HOME), excecoes (HOME, PRESENCIAL e FERIAS), inventario (tipo,
 * emprestimo, reserva do Critico, dono fixo e preferencias) e virada (D-F10-8:
 * vinculo recorrente em PA flexivel vira preferencia e termina na vespera).
 * Situacoes: novo, muda, igual, conflito (a gestao ja marcou outra coisa pela
 * tela: nada e sobrescrito) e nao_casou.
 */
final class EscalaBaseImport
{
    public const STEPS = ['times', 'regras', 'excecoes', 'inventario', 'virada'];
    private const ACTOR_ROLE = 'admin';

    private PDO $pdo;
    private ?string $today;
    private string $actor;

    public function __construct(PDO $pdo, string $actor, ?string $today = null)
    {
        $this->pdo = $pdo;
        $this->actor = $actor;
        $this->today = $today;
    }

    /** Previa: o que entra, o que muda, o que ja esta igual, os conflitos e o que nao casou. */
    public function preview(string $workbook, string $matchCsv, mixed $from): array
    {
        $plan = $this->plan($workbook, $matchCsv, $this->fromDate($from));
        return ['a_partir' => $plan['from'], 'itens' => $plan['items'], 'contagens' => self::counts($plan['items'])];
    }

    /**
     * Confirma: refaz a previa e grava os itens novo/muda dos passos
     * escolhidos, numa transacao. Conflito e nao_casou nunca sao gravados.
     */
    public function confirm(string $workbook, string $matchCsv, mixed $from, mixed $steps): array
    {
        $from = $this->fromDate($from);
        if (!is_array($steps) || $steps === [] || array_diff($steps, self::STEPS) !== []) {
            throw new InvalidArgumentException('Escolha os passos da importação: ' . implode(', ', self::STEPS) . '.');
        }
        $plan = $this->plan($workbook, $matchCsv, $from);
        $done = [];
        $this->atomic(function () use ($plan, $steps, &$done): void {
            foreach (self::STEPS as $step) {
                if (!in_array($step, $steps, true)) {
                    continue;
                }
                foreach ($plan['items'] as $item) {
                    if ($item['passo'] === $step && in_array($item['situacao'], ['novo', 'muda'], true)) {
                        $this->apply($item, $plan);
                        $done[$step] = ($done[$step] ?? 0) + 1;
                    }
                }
            }
        });
        ksort($done);
        return ['a_partir' => $from, 'gravados' => $done, 'contagens' => self::counts($plan['items'])];
    }

    /** Detalhe da auditoria: so contagens. */
    public static function auditDetails(array $result, string $action): string
    {
        $parts = ["acao={$action}", 'a_partir=' . $result['a_partir']];
        foreach ($result['contagens'] as $step => $byStatus) {
            foreach ($byStatus as $status => $n) {
                $parts[] = "{$step}.{$status}={$n}";
            }
        }
        foreach ($result['gravados'] ?? [] as $step => $n) {
            $parts[] = "gravados.{$step}={$n}";
        }
        return implode('; ', $parts);
    }

    // ------------------------------------------------------------------
    // Plano
    // ------------------------------------------------------------------

    private function plan(string $workbook, string $matchCsv, string $from): array
    {
        try {
            $base = EscalaBaseWorkbook::base(EscalaBaseWorkbook::sheets($workbook));
        } catch (EscalaBaseErro $e) {
            throw new InvalidArgumentException('Planilha Base inválida: ' . $e->getMessage() . '.');
        }
        $match = $this->match($matchCsv);
        $employees = $this->employees();
        $rows = $this->rowRefs($base);
        $idOf = static function (string $key) use ($match, $employees): ?int {
            $id = $match[$key] ?? null;
            return $id !== null && isset($employees[$id]) ? $id : null;
        };
        $items = [];
        $team = EscalaBaseWorkbook::activeTeam($base);

        // 1. Times (conversao do rodizio) e regras individuais.
        $teams = $this->teamsByName();
        foreach (['TIME 1', 'TIME 2', 'TIME 3'] as $teamKey) {
            $phase = $base['config']['phase'][$teamKey];
            $pattern = array_map(static fn (int $i): string => $i === $phase ? 'remote' : 'onsite', [0, 1, 2]);
            $label = 'Time ' . substr($teamKey, -1);
            $current = $teams[EscalaBaseWorkbook::norm($label)] ?? null;
            $same = $current !== null && $this->teamVersionAt($current['id'], $from) === ['pattern' => $pattern, 'anchor_date' => $base['config']['anchor']];
            $items[] = ['passo' => 'times', 'tipo' => 'time', 'situacao' => $same ? 'igual' : ($current === null ? 'novo' : 'muda'), 'ref' => 'CONFIG ' . $label,
                'time' => $label, 'team_id' => $current['id'] ?? null, 'pattern' => $pattern, 'anchor_date' => $base['config']['anchor']];
        }
        foreach ($team as $key => $person) {
            $ref = $rows['EQUIPE'][$key] ?? 'EQUIPE';
            if (!EscalaBaseWorkbook::isCommon($person)) {
                continue;
            }
            $id = $idOf($key);
            if ($id === null) {
                $items[] = ['passo' => $person['mode'] === 'RODIZIO' ? 'times' : 'regras', 'tipo' => 'pessoa', 'situacao' => 'nao_casou', 'ref' => $ref];
                continue;
            }
            if ($person['mode'] === 'RODIZIO') {
                $label = 'Time ' . substr($person['team'], -1);
                $open = $this->openMembership($id, $from);
                $situation = $open === null ? 'novo' : (EscalaBaseWorkbook::norm($open) === EscalaBaseWorkbook::norm($label) ? 'igual' : 'conflito');
                $items[] = ['passo' => 'times', 'tipo' => 'membro', 'situacao' => in_array($person['team'], ['TIME 1', 'TIME 2', 'TIME 3'], true) ? $situation : 'conflito', 'ref' => $ref, 'employee_id' => $id, 'time' => $label];
                continue;
            }
            $rule = EscalaBaseWorkbook::portalRule($base, $person);
            if ($rule === null) {
                $items[] = ['passo' => 'regras', 'tipo' => 'regra', 'situacao' => 'conflito', 'ref' => $ref, 'employee_id' => $id];
                continue;
            }
            $rule = EscalaBaseWorkbook::ruleFrom($rule, $from);
            $items[] = ['passo' => 'regras', 'tipo' => 'regra', 'situacao' => $this->ruleSituation($id, $rule, $rule['effective_from']), 'ref' => $ref, 'employee_id' => $id, 'rule' => $rule];
        }

        // 2. Excecoes (so do dia inicial em diante; o passado nao e reescrito).
        foreach ($base['exceptions'] as $index => $exception) {
            $ref = 'EXCECOES item ' . ($index + 1);
            $id = $idOf($exception['key']);
            if ($id === null) {
                $items[] = ['passo' => 'excecoes', 'tipo' => 'excecao', 'situacao' => 'nao_casou', 'ref' => $ref];
                continue;
            }
            $start = max($exception['start'], $from);
            if ($start > $exception['end']) {
                continue;
            }
            if ($exception['status'] === 'FERIAS') {
                $items[] = ['passo' => 'excecoes', 'tipo' => 'ferias', 'situacao' => $this->absenceSituation($id, $start, $exception['end']), 'ref' => $ref, 'employee_id' => $id, 'starts_on' => $start, 'ends_on' => $exception['end']];
                continue;
            }
            $type = $exception['status'] === 'HOME' ? 'remote' : 'onsite';
            foreach (self::businessDays($start, $exception['end']) as $date) {
                $items[] = ['passo' => 'excecoes', 'tipo' => 'excecao', 'situacao' => $this->exceptionSituation($id, $date, $type), 'ref' => $ref, 'employee_id' => $id, 'date' => $date, 'exception_type' => $type];
            }
        }

        // 3. Inventario (tipo, emprestimo, reserva, dono e preferencias).
        $seats = $this->seats();
        $flexibleAfter = [];
        foreach ($base['pas'] as $index => $pa) {
            $ref = 'CONFIG PA item ' . ($index + 1);
            $number = (string)$pa['pa'];
            $current = $seats[$number] ?? null;
            if ($current === null) {
                $items[] = ['passo' => 'inventario', 'tipo' => 'pa', 'situacao' => 'conflito', 'ref' => $ref, 'pa' => $number, 'motivo' => 'pa_fora_do_inventario'];
                continue;
            }
            $occupant = EscalaBaseWorkbook::norm($pa['fixed']);
            $wanted = ['seat_type' => 'flexible', 'can_lend' => false, 'reserved_team' => null];
            $owner = null;
            $reason = null;
            if ($pa['type'] === 'FIXO') {
                if ($occupant === 'CRITICO') {
                    $wanted = ['seat_type' => 'fixed', 'can_lend' => false, 'reserved_team' => PortalTeams::CRITICAL];
                } elseif ($occupant === 'HIBRIDO') {
                    $reason = 'hibrido_opcao_c';
                } else {
                    $wanted = ['seat_type' => 'fixed', 'can_lend' => $pa['loan'], 'reserved_team' => null];
                    $owner = $idOf($occupant);
                    $reason = $owner === null ? 'dono_nao_casou' : null;
                }
            } elseif ($pa['type'] === 'BLOQUEADO') {
                $reason = 'bloqueado_pela_tela';
            }
            if ($wanted['seat_type'] === 'flexible') {
                $flexibleAfter[$number] = true;
            }
            $default = ['seat_type' => 'flexible', 'can_lend' => false, 'reserved_team' => null];
            $now = ['seat_type' => $current['seat_type'], 'can_lend' => $current['can_lend'], 'reserved_team' => $current['reserved_team']];
            $seatSituation = $reason !== null ? 'conflito' : ($now === $wanted ? 'igual' : ($now === $default ? 'novo' : 'conflito'));
            $paItems = [['passo' => 'inventario', 'tipo' => 'pa', 'situacao' => $seatSituation, 'ref' => $ref, 'pa' => $number, 'seat' => $wanted] + ($reason !== null ? ['motivo' => $reason] : [])];
            if ($owner !== null) {
                $currentOwner = $this->ownerAt($number, $from);
                $paItems[] = ['passo' => 'inventario', 'tipo' => 'dono', 'ref' => $ref, 'pa' => $number, 'employee_id' => $owner,
                    'situacao' => $currentOwner === null ? 'novo' : ($currentOwner === $owner ? 'igual' : 'conflito')];
            }
            $prefIds = [];
            $prefMissing = 0;
            foreach ($pa['prefs'] as $name) {
                $id = $idOf(EscalaBaseWorkbook::norm($name));
                $id === null ? $prefMissing++ : $prefIds[$id] = true;
            }
            $prefIds = array_keys($prefIds);
            sort($prefIds);
            if ($pa['prefs'] !== []) {
                $currentPrefs = $current['preferences'];
                sort($currentPrefs);
                $paItems[] = ['passo' => 'inventario', 'tipo' => 'preferencias', 'ref' => $ref, 'pa' => $number, 'employee_ids' => $prefIds, 'nao_casaram' => $prefMissing,
                    'situacao' => $prefMissing > 0 && $prefIds === [] ? 'nao_casou' : ($currentPrefs === $prefIds ? 'igual' : ($currentPrefs === [] ? 'novo' : 'conflito'))];
            }
            // [D-F10-55] O PA e um todo: conflito no tipo, no dono ou nas
            // preferencias deixa o PA inteiro em conflito (nada meio aplicado).
            if (in_array('conflito', array_column($paItems, 'situacao'), true)) {
                $paItems = array_map(static fn (array $item): array => in_array($item['situacao'], ['novo', 'muda'], true) ? ['situacao' => 'conflito', 'motivo' => $item['motivo'] ?? 'pa_em_conflito'] + $item : $item, $paItems);
            }
            array_push($items, ...$paItems);
        }
        // PA em conflito fica fora da virada tambem (D-F10-55).
        $conflictPas = [];
        foreach ($items as $item) {
            if ($item['passo'] === 'inventario' && $item['situacao'] === 'conflito') {
                $conflictPas[$item['pa']] = true;
            }
        }

        // 4. Virada (D-F10-8): vinculo recorrente em PA que fica flexivel vira
        // preferencia e termina na vespera do dia inicial.
        foreach ($this->openAssignments($from) as $assignment) {
            if (isset($flexibleAfter[$assignment['pa_number']]) && !isset($conflictPas[(string)$assignment['pa_number']])) {
                $items[] = ['passo' => 'virada', 'tipo' => 'vinculo', 'situacao' => 'novo', 'ref' => 'Mapa PA vinculo ' . $assignment['id'],
                    'assignment_id' => (int)$assignment['id'], 'pa' => (string)$assignment['pa_number'], 'employee_id' => (int)$assignment['employee_id']];
            }
        }
        return ['from' => $from, 'items' => $items];
    }

    // ------------------------------------------------------------------
    // Gravacao (pelos servicos de sempre)
    // ------------------------------------------------------------------

    private function apply(array $item, array $plan): void
    {
        $from = $plan['from'];
        switch ($item['tipo']) {
            case 'time':
                $version = ['effective_from' => $from, 'cycle' => ['pattern' => $item['pattern'], 'anchor_date' => $item['anchor_date']]];
                $teams = new RotationTeamService($this->pdo, $this->today);
                if ($item['team_id'] === null) {
                    $teams->saveTeam(['name' => $item['time'], 'version' => $version], $this->actor, self::ACTOR_ROLE);
                } else {
                    $teams->saveVersion(['team_id' => $item['team_id']] + $version, $this->actor, self::ACTOR_ROLE);
                }
                return;
            case 'membro':
                $team = $this->teamsByName()[EscalaBaseWorkbook::norm($item['time'])] ?? null;
                if ($team === null) {
                    throw new DomainException('Inclua o passo "times" com o ' . $item['time'] . ' antes dos membros.');
                }
                (new RotationTeamService($this->pdo, $this->today))->addMember(['team_id' => $team['id'], 'employee_id' => $item['employee_id'], 'valid_from' => $from], $this->actor, self::ACTOR_ROLE);
                return;
            case 'regra':
                $rule = $item['rule'];
                $payload = ['employee_id' => $item['employee_id'], 'rule_type' => $rule['rule_type'], 'effective_from' => $rule['effective_from']];
                if ($rule['rule_type'] === 'cycle') {
                    $payload['cycle'] = ['pattern' => $rule['rule_config']['pattern'], 'anchor_date' => $rule['rule_config']['anchor_date']];
                } elseif ($rule['rule_type'] === 'fixed_weekdays') {
                    $payload['weekdays'] = $rule['rule_config']['weekdays'];
                }
                $this->operational()->saveScheduleRule($payload, $this->actor, self::ACTOR_ROLE);
                return;
            case 'excecao':
                $this->operational()->saveScheduleException(['employee_id' => $item['employee_id'], 'exception_date' => $item['date'], 'exception_type' => $item['exception_type']], $this->actor, self::ACTOR_ROLE);
                return;
            case 'ferias':
                (new AbsenceService($this->pdo, $this->today))->create(['employee_id' => $item['employee_id'], 'starts_on' => $item['starts_on'], 'ends_on' => $item['ends_on'], 'period_type' => 'full_day', 'category' => 'ferias'],
                    ['role' => self::ACTOR_ROLE, 'username' => $this->actor]);
                return;
            case 'pa':
                $this->paMap()->saveSeat(['pa_number' => $item['pa']] + $item['seat'], $this->actor);
                return;
            case 'dono':
                $this->paMap()->save(['pa_number' => $item['pa'], 'employee_id' => $item['employee_id'], 'valid_from' => $from], $this->actor);
                return;
            case 'preferencias':
                $this->paMap()->savePreferences(['pa_number' => $item['pa'], 'employee_ids' => $item['employee_ids']], $this->actor);
                return;
            case 'vinculo':
                $prefs = $this->seats()[$item['pa']]['preferences'] ?? [];
                $prefs[] = $item['employee_id'];
                $prefs = array_values(array_unique($prefs));
                sort($prefs);
                $this->paMap()->savePreferences(['pa_number' => $item['pa'], 'employee_ids' => $prefs], $this->actor);
                $this->paMap()->save(['id' => $item['assignment_id'], 'pa_number' => $item['pa'], 'employee_id' => $item['employee_id'], 'valid_until' => ScheduleRulePlanner::addDays($from, -1)], $this->actor);
                return;
        }
        throw new LogicException('Item de importação desconhecido.');
    }

    // ------------------------------------------------------------------
    // Leituras e apoio
    // ------------------------------------------------------------------

    /**
     * Casamento nome normalizado -> employee_id (o CSV do F10-1, separado por
     * ";", com o cabecalho nome_normalizado e employee_id). Linha sem ID nao casa.
     */
    private function match(string $path): array
    {
        if (!is_file($path) || filesize($path) > 262144) {
            throw new InvalidArgumentException('Arquivo de casamento ausente ou maior que 256 KB.');
        }
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, 0, ';', '"', '\\');
        $header = is_array($header) ? array_map(static fn ($h): string => trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)), $header) : [];
        $keyCol = array_search('nome_normalizado', $header, true);
        $idCol = array_search('employee_id', $header, true);
        if ($keyCol === false || $idCol === false) {
            fclose($handle);
            throw new InvalidArgumentException('O casamento precisa das colunas nome_normalizado e employee_id.');
        }
        $match = [];
        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $id = filter_var(trim((string)($row[$idCol] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $match[EscalaBaseWorkbook::norm((string)($row[$keyCol] ?? ''))] = (int)$id;
            }
        }
        fclose($handle);
        return $match;
    }

    /** "ABA linha N" de cada pessoa da EQUIPE (para a previa, sem o nome). */
    private function rowRefs(array $base): array
    {
        $refs = [];
        foreach ($base['team'] as $index => $person) {
            $refs['EQUIPE'][$person['key']] ??= 'EQUIPE item ' . ($index + 1);
        }
        return $refs;
    }

    private function employees(): array
    {
        $rows = $this->pdo->query('SELECT id FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 0')->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys(array_map('intval', $rows), true);
    }

    private function teamsByName(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT id, name FROM portal_rotation_teams WHERE active = 1')->fetchAll() as $row) {
            $result[EscalaBaseWorkbook::norm((string)$row['name'])] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        return $result;
    }

    private function teamVersionAt(int $teamId, string $date): ?array
    {
        $stmt = $this->pdo->prepare('SELECT pattern, anchor_date FROM portal_rotation_team_versions WHERE team_id = :id AND effective_from <= :date ORDER BY effective_from DESC LIMIT 1');
        $stmt->execute([':id' => $teamId, ':date' => $date]);
        $row = $stmt->fetch();
        return $row === false ? null : ['pattern' => json_decode((string)$row['pattern'], true), 'anchor_date' => (string)$row['anchor_date']];
    }

    private function openMembership(int $employeeId, string $date): ?string
    {
        $stmt = $this->pdo->prepare('SELECT t.name FROM portal_rotation_team_members m JOIN portal_rotation_teams t ON t.id = m.team_id
            WHERE m.employee_id = :id AND m.valid_from <= :date_a AND (m.valid_until IS NULL OR m.valid_until >= :date_b) LIMIT 1');
        $stmt->execute([':id' => $employeeId, ':date_a' => $date, ':date_b' => $date]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string)$name;
    }

    /** igual (a regra vigente no dia ja e essa), novo (sem regra) ou muda. */
    private function ruleSituation(int $employeeId, array $rule, string $from): string
    {
        $stmt = $this->pdo->prepare('SELECT rule_type, rule_config, effective_from, effective_until FROM portal_schedule_rules WHERE employee_id = :id AND effective_from <= :date_a AND (effective_until IS NULL OR effective_until >= :date_b) ORDER BY effective_from DESC LIMIT 1');
        $stmt->execute([':id' => $employeeId, ':date_a' => $from, ':date_b' => $from]);
        $current = $stmt->fetch();
        if ($current === false) {
            return 'novo';
        }
        $config = json_decode((string)$current['rule_config'], true);
        $same = $current['rule_type'] === $rule['rule_type'] && match ($rule['rule_type']) {
            'cycle' => ($config['pattern'] ?? null) === $rule['rule_config']['pattern'] && self::sameCyclePhase($config, $rule['rule_config'], $from),
            'fixed_weekdays' => ($config['weekdays'] ?? null) === $rule['rule_config']['weekdays'],
            default => true,
        };
        return $same ? 'igual' : 'muda';
    }

    private static function sameCyclePhase(array $a, array $b, string $from): bool
    {
        $size = count($b['pattern']);
        return isset($a['anchor_date']) && PresenceEngine::cyclePosition((string)$a['anchor_date'], $from, $size) === PresenceEngine::cyclePosition((string)$b['anchor_date'], $from, $size);
    }

    private function exceptionSituation(int $employeeId, string $date, string $type): string
    {
        $stmt = $this->pdo->prepare('SELECT exception_type FROM portal_schedule_exceptions WHERE employee_id = :id AND exception_date = :date');
        $stmt->execute([':id' => $employeeId, ':date' => $date]);
        $current = $stmt->fetchColumn();
        return $current === false ? 'novo' : ($current === $type ? 'igual' : 'muda');
    }

    private function absenceSituation(int $employeeId, string $start, string $end): string
    {
        $stmt = $this->pdo->prepare("SELECT starts_on, ends_on, category FROM portal_absences WHERE employee_id = :id AND status = 'approved' AND starts_on <= :end AND ends_on >= :start");
        $stmt->execute([':id' => $employeeId, ':start' => $start, ':end' => $end]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return 'novo';
        }
        return count($rows) === 1 && $rows[0]['starts_on'] === $start && $rows[0]['ends_on'] === $end && $rows[0]['category'] === 'ferias' ? 'igual' : 'conflito';
    }

    private function seats(): array
    {
        $prefs = [];
        foreach ($this->pdo->query('SELECT i.pa_number, p.employee_id FROM portal_pa_preferences p JOIN portal_pa_inventory i ON i.id = p.pa_id ORDER BY p.employee_id')->fetchAll() as $row) {
            $prefs[(string)$row['pa_number']][] = (int)$row['employee_id'];
        }
        $seats = [];
        foreach ($this->pdo->query('SELECT pa_number, seat_type, can_lend, reserved_team FROM portal_pa_inventory WHERE active = 1')->fetchAll() as $row) {
            $seats[(string)$row['pa_number']] = PaInventoryRules::storedSeat($row) + ['preferences' => $prefs[(string)$row['pa_number']] ?? []];
        }
        return $seats;
    }

    private function ownerAt(string $pa, string $date): ?int
    {
        $stmt = $this->pdo->prepare('SELECT employee_id FROM portal_pa_assignments WHERE pa_number = :pa AND active = 1 AND deleted_at IS NULL AND valid_from <= :date_a AND (valid_until IS NULL OR valid_until >= :date_b) ORDER BY valid_from DESC LIMIT 1');
        $stmt->execute([':pa' => $pa, ':date_a' => $date, ':date_b' => $date]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function openAssignments(string $date): array
    {
        $stmt = $this->pdo->prepare('SELECT id, pa_number, employee_id FROM portal_pa_assignments WHERE active = 1 AND deleted_at IS NULL AND (valid_until IS NULL OR valid_until >= :date) ORDER BY pa_number, id');
        $stmt->execute([':date' => $date]);
        return $stmt->fetchAll();
    }

    private static function businessDays(string $from, string $to): array
    {
        $days = [];
        for ($date = $from; $date <= $to; $date = ScheduleRulePlanner::addDays($date, 1)) {
            if (PresenceEngine::isBusinessDay($date)) {
                $days[] = $date;
            }
        }
        return $days;
    }

    private static function counts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $counts[$item['passo']][$item['situacao']] = ($counts[$item['passo']][$item['situacao']] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    private function fromDate(mixed $value): string
    {
        if (!is_string($value) || !PresenceEngine::validDate($value)) {
            throw new InvalidArgumentException('Informe a data a partir da qual a importação vale.');
        }
        $today = $this->today ?? (new DateTimeImmutable('now'))->format('Y-m-d');
        if ($value <= $today) {
            throw new DomainException('A importação vale só do próximo dia em diante: o passado não é reescrito.');
        }
        return $value;
    }

    private function operational(): OperationalService
    {
        return new OperationalService($this->pdo, $this->today);
    }

    private function paMap(): PaMapService
    {
        return new PaMapService($this->pdo, $this->today);
    }

    private function atomic(callable $callback): void
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $callback();
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
