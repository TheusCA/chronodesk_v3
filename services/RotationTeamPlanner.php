<?php
declare(strict_types=1);

require_once __DIR__ . '/ScheduleRulePlanner.php';
require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/CyclePattern.php';

/**
 * Times de rodizio (F10-2; desenho do F10, secao 3.1): o time e a fonte, e a
 * regra por pessoa e a copia com vigencia.
 *
 * Funcoes puras: recebem regras, versoes e pertencas ja carregadas e a data de
 * hoje; nao consultam banco nem relogio. Quem grava (RotationTeamService)
 * trava o time e os colaboradores antes de ler.
 *
 * Regra: como em ScheduleRulePlanner, mais 'rotation_team_id' (?int; null =
 * regra individual). Versao: ['id', 'team_id', 'effective_from', 'pattern'
 * (list), 'anchor_date', 'weeks' (?int)]. Pertenca: ['id', 'team_id',
 * 'employee_id', 'valid_from', 'valid_until' (?'Y-m-d', inclusive)].
 *
 * Intencao de gravacao: ['from' => 'Y-m-d', 'rule_type', 'rule_config',
 * 'rotation_team_id' => ?int] ou ['from', 'close_at' => 'Y-m-d'] (encerra a
 * regra que vigora em 'from' no dia 'close_at').
 */
final class RotationTeamPlanner {
    /** Versao do time que vale na data: a de maior effective_from <= data. */
    public static function versionAt(array $versions, string $date): ?array {
        $found = null;
        foreach ($versions as $version) {
            if ($version['effective_from'] <= $date && ($found === null || $version['effective_from'] > $found['effective_from'])) {
                $found = $version;
            }
        }
        return $found;
    }

    public static function versionConfig(array $version): string {
        return CyclePattern::config($version['pattern'], $version['anchor_date'], $version['weeks'] ?? null);
    }

    /** Pertenca que cobre a data, ou null. */
    public static function membershipAt(array $memberships, string $date): ?array {
        foreach ($memberships as $membership) {
            if ($membership['valid_from'] <= $date && ($membership['valid_until'] === null || $membership['valid_until'] >= $date)) {
                return $membership;
            }
        }
        return null;
    }

    /** Pertencas que se sobrepoem a [from, until] (until null = sem fim). */
    public static function overlapping(array $memberships, string $from, ?string $until): array {
        return array_values(array_filter($memberships, static fn (array $m): bool =>
            $m['valid_from'] <= ($until ?? '9999-12-31') && ($m['valid_until'] ?? '9999-12-31') >= $from));
    }

    /**
     * Regras individuais (sem time) que comecam depois de $start e dentro da
     * pertenca: o time nao as sobrescreve, e a gravacao para com 409. Regra de
     * time (este ou outro) na mesma faixa e substituida pelo padrao do time.
     */
    public static function incompatibleRules(array $rules, string $start, ?string $until): array {
        return array_values(array_filter($rules, static fn (array $rule): bool =>
            !ScheduleRulePlanner::isEmpty($rule)
            && $rule['effective_from'] > $start
            && $rule['effective_from'] <= ($until ?? '9999-12-31')
            && ($rule['rotation_team_id'] ?? null) === null));
    }

    /**
     * Intencoes de gravacao das regras de um membro na pertenca [start, until]
     * (desenho 3.1: entrar no time, mudar o padrao do time):
     * 1. em $start e em cada inicio de versao do time e de regra de time dentro
     *    da faixa, a regra cycle com o padrao da versao daquela data e o
     *    rotation_team_id;
     * 2. com $until (pertenca encerrada), a regra que vigorava em until + 1 volta
     *    a valer a partir dele, com o mesmo dono: sair do time nao muda a regra
     *    (desenho 3.1), e o time so muda a escala dentro da pertenca; sem regra em
     *    until + 1, a regra gravada encerra em $until.
     * Sem versao do time em $start: DomainException (409).
     */
    public static function memberIntents(array $rules, array $versions, int $teamId, string $start, ?string $until): array {
        if (self::versionAt($versions, $start) === null) {
            throw new DomainException('O time não tem padrão em ' . self::dateLabel($start) . ': cadastre a versão do padrão antes.');
        }
        $dates = [$start => true];
        foreach ($versions as $version) {
            if ($version['effective_from'] > $start && $version['effective_from'] <= ($until ?? '9999-12-31')) {
                $dates[$version['effective_from']] = true;
            }
        }
        foreach ($rules as $rule) {
            if (ScheduleRulePlanner::isEmpty($rule)) {
                continue;
            }
            if (($rule['rotation_team_id'] ?? null) !== null
                && $rule['effective_from'] > $start && $rule['effective_from'] <= ($until ?? '9999-12-31')) {
                $dates[$rule['effective_from']] = true;
            }
            // Buraco no historico (dias sem regra) dentro da pertenca: o time
            // cobre todos os dias dela, entao o primeiro dia do buraco tambem.
            if ($rule['effective_until'] !== null) {
                $gap = ScheduleRulePlanner::addDays($rule['effective_until'], 1);
                if ($gap > $start && $gap <= ($until ?? '9999-12-31') && ScheduleRulePlanner::ruleAt($rules, $gap) === null) {
                    $dates[$gap] = true;
                }
            }
        }
        ksort($dates);
        $intents = [];
        foreach (array_keys($dates) as $date) {
            $intents[] = [
                'from' => (string)$date,
                'rule_type' => 'cycle',
                'rule_config' => self::versionConfig(self::versionAt($versions, (string)$date)),
                'rotation_team_id' => $teamId,
            ];
        }
        if ($until !== null) {
            $after = ScheduleRulePlanner::addDays($until, 1);
            $restore = ScheduleRulePlanner::ruleAt($rules, $after);
            $intents[] = $restore === null
                ? ['from' => $after, 'close_at' => $until]
                : [
                    'from' => $after,
                    'rule_type' => (string)$restore['rule_type'],
                    'rule_config' => $restore['rule_config'] ?? null,
                    'rotation_team_id' => isset($restore['rotation_team_id']) ? (int)$restore['rotation_team_id'] : null,
                ];
        }
        return $intents;
    }

    /**
     * Plano de uma intencao sobre o historico atual (o mesmo planSave da regra
     * individual; o dono entra na comparacao). Encerramento: ['action' =>
     * 'close' | 'unchanged', 'close' => ?[...]].
     */
    public static function planIntent(array $rules, array $intent, string $today): array {
        if (isset($intent['close_at'])) {
            $cover = ScheduleRulePlanner::ruleAt($rules, $intent['from']);
            if ($cover === null) {
                return ['action' => 'unchanged', 'close' => null, 'retroactive' => false];
            }
            return [
                'action' => 'close',
                'close' => ['id' => (int)$cover['id'], 'effective_until' => $intent['close_at'], 'previous_until' => $cover['effective_until']],
                'retroactive' => $intent['close_at'] < $today,
            ];
        }
        $cover = ScheduleRulePlanner::ruleAt($rules, $intent['from']);
        $sameOwner = $cover === null || self::owner($cover) === ($intent['rotation_team_id'] ?? null);
        return ScheduleRulePlanner::planSave($rules, $intent['from'], $intent['rule_type'], $intent['rule_config'], $today, $sameOwner);
    }

    /**
     * Historico depois do plano (o que o servico grava no banco), com o dono.
     * Linha inserida recebe id provisorio negativo ($nextTempId diminui).
     */
    public static function applyIntent(array $rules, array $intent, array $plan, int &$nextTempId): array {
        if ($plan['action'] === 'unchanged') {
            return $rules;
        }
        $result = [];
        foreach ($rules as $rule) {
            if ($plan['close'] !== null && (int)$rule['id'] === $plan['close']['id']) {
                $rule['effective_until'] = $plan['close']['effective_until'];
            }
            if ($plan['action'] === 'update' && (int)$rule['id'] === $plan['target_id']) {
                $rule['rule_type'] = $intent['rule_type'];
                $rule['rule_config'] = $intent['rule_config'];
                $rule['rotation_team_id'] = $intent['rotation_team_id'] ?? null;
                $rule['effective_until'] = $plan['effective_until'];
            }
            $result[] = $rule;
        }
        if ($plan['action'] === 'insert') {
            $result[] = [
                'id' => $nextTempId--,
                'rule_type' => $intent['rule_type'],
                'rule_config' => $intent['rule_config'],
                'rotation_team_id' => $intent['rotation_team_id'] ?? null,
                'effective_from' => $plan['effective_from'],
                'effective_until' => $plan['effective_until'],
            ];
        }
        return $result;
    }

    /**
     * Simulacao das intencoes (previa e prova): ['rules' => historico final,
     * 'plans' => list de [intent, plan] que mudam algo].
     */
    public static function simulate(array $rules, array $intents, string $today): array {
        $temp = -1;
        $plans = [];
        foreach ($intents as $intent) {
            $plan = self::planIntent($rules, $intent, $today);
            if ($plan['action'] !== 'unchanged') {
                $plans[] = [$intent, $plan];
            }
            $rules = self::applyIntent($rules, $intent, $plan, $temp);
        }
        return ['rules' => $rules, 'plans' => $plans];
    }

    /**
     * Prova pelo motor: a escala de cada dia util em [from, to] com os dois
     * historicos. So contagens.
     */
    public static function compareDays(array $employee, array $before, array $after, string $from, string $to): array {
        $result = ['dias' => 0, 'iguais' => 0, 'diferentes' => 0, 'primeiro_diferente' => null];
        for ($date = $from; $date <= $to; $date = ScheduleRulePlanner::addDays($date, 1)) {
            if (!PresenceEngine::isBusinessDay($date)) {
                continue;
            }
            $result['dias']++;
            $a = PresenceEngine::scheduleForDay($employee, $date, $before, null)['situacao'];
            $b = PresenceEngine::scheduleForDay($employee, $date, $after, null)['situacao'];
            if ($a === $b) {
                $result['iguais']++;
            } else {
                $result['diferentes']++;
                $result['primeiro_diferente'] ??= $date;
            }
        }
        return $result;
    }

    /**
     * Plano de migracao dos dados de hoje (desenho 8.6): a regra que vigora em
     * $date vira pertenca a qual time, sem mudar nenhum dia ate $horizon?
     * $teams: list de ['key' => string, 'id' => int, 'pattern' => list,
     * 'anchor_date' => 'Y-m-d'] (uma versao por time, valendo desde antes de
     * $date). Devolve ['status' => 'convertivel', 'team' => key, 'prova' =>
     * compareDays] ou ['status' => motivo]. Motivos, nessa ordem: lideranca,
     * fora_do_mapa, sem_regra, nao_e_ciclo, ciclo_invalido,
     * ciclo_fora_do_rodizio (nao e 3 posicoes com 1 remoto),
     * regra_individual_agendada_depois, sem_time_equivalente.
     */
    public static function classifyForTeams(array $employee, array $rules, array $teams, string $date, string $horizon, string $today): array {
        if (($employee['team'] ?? null) === 'lideranca') {
            return ['status' => 'lideranca'];
        }
        if ((int)($employee['fora_mapa_escala'] ?? 0) === 1) {
            return ['status' => 'fora_do_mapa'];
        }
        $rule = ScheduleRulePlanner::ruleAt($rules, $date);
        if ($rule === null) {
            return ['status' => 'sem_regra'];
        }
        if ($rule['rule_type'] !== 'cycle') {
            return ['status' => 'nao_e_ciclo'];
        }
        $config = is_array($rule['rule_config']) ? $rule['rule_config'] : json_decode((string)$rule['rule_config'], true);
        $cycle = PresenceEngine::cycleConfig(is_array($config) ? $config : []);
        if ($cycle === null) {
            return ['status' => 'ciclo_invalido'];
        }
        $counts = array_count_values($cycle['pattern']);
        if (count($cycle['pattern']) !== 3 || ($counts['remote'] ?? 0) !== 1) {
            return ['status' => 'ciclo_fora_do_rodizio'];
        }
        if (self::incompatibleRules($rules, $date, null) !== []) {
            return ['status' => 'regra_individual_agendada_depois'];
        }
        foreach ($teams as $team) {
            $version = ['effective_from' => '0001-01-01', 'pattern' => $team['pattern'], 'anchor_date' => $team['anchor_date'], 'weeks' => null];
            $simulated = self::simulate($rules, self::memberIntents($rules, [$version], (int)$team['id'], $date, null), $today);
            $proof = self::compareDays($employee, $rules, $simulated['rules'], $date, $horizon);
            if ($proof['diferentes'] === 0) {
                return ['status' => 'convertivel', 'team' => $team['key'], 'prova' => $proof];
            }
        }
        return ['status' => 'sem_time_equivalente'];
    }

    private static function owner(array $rule): ?int {
        return isset($rule['rotation_team_id']) ? (int)$rule['rotation_team_id'] : null;
    }

    private static function dateLabel(string $date): string {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }
}
