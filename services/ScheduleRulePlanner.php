<?php
declare(strict_types=1);

/**
 * Historico e vigencia da escala (Parte B, F1; desenho, secao 2.1).
 *
 * Funcoes puras: recebem as regras ja carregadas de UM colaborador e a data de
 * hoje (do PHP, America/Sao_Paulo) e devolvem o plano de gravacao. Nao
 * consultam banco nem relogio. Quem grava (OperationalService) trava o
 * colaborador antes de ler as regras.
 *
 * Regra: ['id' => int, 'rule_type' => string, 'rule_config' => ?string,
 *         'effective_from' => 'Y-m-d', 'effective_until' => ?'Y-m-d'].
 * Linha com fim antes do inicio ("vazia": escala removida antes de comecar,
 * gravada pelo codigo anterior ao F1) nao vigora em data nenhuma.
 */
final class ScheduleRulePlanner {
    public static function isEmpty(array $rule): bool {
        return $rule['effective_until'] !== null && $rule['effective_until'] < $rule['effective_from'];
    }

    public static function covers(array $rule, string $date): bool {
        return !self::isEmpty($rule)
            && $rule['effective_from'] <= $date
            && ($rule['effective_until'] === null || $rule['effective_until'] >= $date);
    }

    /** Regra que vigora na data, ou null. */
    public static function ruleAt(array $rules, string $date): ?array {
        foreach ($rules as $rule) {
            if (self::covers($rule, $date)) {
                return $rule;
            }
        }
        return null;
    }

    public static function isCurrent(array $rule, string $today): bool {
        return self::covers($rule, $today);
    }

    public static function isFuture(array $rule, string $today): bool {
        return !self::isEmpty($rule) && $rule['effective_from'] > $today;
    }

    /**
     * Plano para gravar a regra ($ruleType, $ruleConfig) a partir de $from.
     *
     * - Regra que vigora em $from comecando nela: atualizada no lugar
     *   (correcao), com o mesmo fim.
     * - Regra igual a que vigora em $from: nada muda ("unchanged"), para a
     *   reimportacao nao criar historico falso.
     * - Senao: a regra que vigora em $from fecha em $from - 1 e a nova vale
     *   ate o fim dela; sem regra em $from, a nova vale ate a vespera da
     *   proxima regra (ou fica aberta). Linha vazia com o mesmo inicio e
     *   reaproveitada (a UNIQUE de inicio nao deixa inserir outra).
     *
     * Devolve: action ('unchanged' | 'update' | 'insert'), target_id (linha
     * atualizada ou igual), effective_from, effective_until (da linha gravada),
     * close (null ou ['id', 'effective_until', 'previous_until']), previous (a
     * regra que vigorava em $from) e retroactive ($from antes de hoje).
     */
    public static function planSave(array $rules, string $from, string $ruleType, ?string $ruleConfig, string $today): array {
        $cover = self::ruleAt($rules, $from);
        $plan = [
            'action' => 'insert',
            'target_id' => null,
            'effective_from' => $from,
            'effective_until' => null,
            'close' => null,
            'previous' => $cover,
            'retroactive' => $from < $today,
        ];
        if ($cover !== null && self::sameRule($cover, $ruleType, $ruleConfig)) {
            return ['action' => 'unchanged', 'target_id' => (int)$cover['id'], 'effective_until' => $cover['effective_until']] + $plan;
        }
        if ($cover !== null && $cover['effective_from'] === $from) {
            return ['action' => 'update', 'target_id' => (int)$cover['id'], 'effective_until' => $cover['effective_until']] + $plan;
        }
        if ($cover !== null) {
            $plan['close'] = [
                'id' => (int)$cover['id'],
                'effective_until' => self::addDays($from, -1),
                'previous_until' => $cover['effective_until'],
            ];
            $plan['effective_until'] = $cover['effective_until'];
        } else {
            $next = self::nextStart($rules, $from);
            $plan['effective_until'] = $next === null ? null : self::addDays($next, -1);
        }
        foreach ($rules as $rule) {
            if ($rule['effective_from'] === $from) {
                // So pode ser linha vazia: uma que vigorasse em $from seria $cover.
                $plan['action'] = 'update';
                $plan['target_id'] = (int)$rule['id'];
            }
        }
        return $plan;
    }

    /**
     * Plano para remover a escala a partir de hoje: a regra que vigora hoje e
     * comecou antes fecha ontem, sem trocar o tipo; regra que comeca hoje ou
     * depois e apagada (nunca vigorou). Sem nada a fazer: close null e delete
     * vazio. Devolve ['close' => ?[...], 'delete' => list<regra>, 'current' => ?regra].
     */
    public static function planRemove(array $rules, string $today): array {
        $current = self::ruleAt($rules, $today);
        $close = null;
        if ($current !== null && $current['effective_from'] < $today) {
            $close = [
                'id' => (int)$current['id'],
                'effective_until' => self::addDays($today, -1),
                'previous_until' => $current['effective_until'],
            ];
        }
        $delete = array_values(array_filter(
            $rules,
            static fn (array $rule): bool => !self::isEmpty($rule) && $rule['effective_from'] >= $today
        ));
        return ['close' => $close, 'delete' => $delete, 'current' => $current];
    }

    /**
     * Invariantes do historico de um colaborador: no maximo uma regra aberta,
     * um inicio por data e nenhuma sobreposicao entre linhas nao vazias.
     * Devolve a lista de violacoes (vazia = ok). Espelha a consulta de
     * migrations/verificacao/20261005_011_schedule_history_check.sql.
     */
    public static function violations(array $rules): array {
        $problems = [];
        $open = array_filter($rules, static fn (array $rule): bool => $rule['effective_until'] === null);
        if (count($open) > 1) {
            $problems[] = 'mais de uma regra aberta';
        }
        $starts = array_count_values(array_map(static fn (array $rule): string => $rule['effective_from'], $rules));
        foreach ($starts as $start => $count) {
            if ($count > 1) {
                $problems[] = "inicio repetido em {$start}";
            }
        }
        $filled = array_values(array_filter($rules, static fn (array $rule): bool => !self::isEmpty($rule)));
        for ($i = 0; $i < count($filled); $i++) {
            for ($j = $i + 1; $j < count($filled); $j++) {
                $a = $filled[$i];
                $b = $filled[$j];
                if ($a['effective_from'] <= ($b['effective_until'] ?? '9999-12-31')
                    && $b['effective_from'] <= ($a['effective_until'] ?? '9999-12-31')) {
                    $problems[] = "sobreposicao entre {$a['id']} e {$b['id']}";
                }
            }
        }
        return $problems;
    }

    /**
     * [F2B] Historico como ficaria depois de gravar o plano (o que a
     * gravacao do OperationalService faz no banco), para a previa da regra.
     * $newRule: ['rule_type' => string, 'rule_config' => ?string]. A linha
     * inserida tem id 0.
     */
    public static function applyPlan(array $rules, array $plan, array $newRule): array {
        if ($plan['action'] === 'unchanged') {
            return $rules;
        }
        $result = [];
        foreach ($rules as $rule) {
            if ($plan['close'] !== null && (int)$rule['id'] === $plan['close']['id']) {
                $rule['effective_until'] = $plan['close']['effective_until'];
            }
            if ($plan['action'] === 'update' && (int)$rule['id'] === $plan['target_id']) {
                $rule = [
                    'rule_type' => $newRule['rule_type'],
                    'rule_config' => $newRule['rule_config'],
                    'effective_until' => $plan['effective_until'],
                ] + $rule;
            }
            $result[] = $rule;
        }
        if ($plan['action'] === 'insert') {
            $result[] = [
                'id' => 0,
                'rule_type' => $newRule['rule_type'],
                'rule_config' => $newRule['rule_config'],
                'effective_from' => $plan['effective_from'],
                'effective_until' => $plan['effective_until'],
            ];
        }
        return $result;
    }

    public static function sameRule(array $rule, string $ruleType, ?string $ruleConfig): bool {
        return $rule['rule_type'] === $ruleType && self::config($rule['rule_config']) === self::config($ruleConfig);
    }

    public static function addDays(string $date, int $days): string {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($value === false || $value->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Data invalida no historico de escala.');
        }
        return $value->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
    }

    /** Inicio da proxima regra nao vazia depois de $date, ou null. */
    private static function nextStart(array $rules, string $date): ?string {
        $next = null;
        foreach ($rules as $rule) {
            if (!self::isEmpty($rule) && $rule['effective_from'] > $date && ($next === null || $rule['effective_from'] < $next)) {
                $next = $rule['effective_from'];
            }
        }
        return $next;
    }

    /**
     * Configuracao comparavel: JSON decodificado (null quando vazio ou
     * invalido). [F2B] Sem input_mode, que e so de exibicao (desenho, secao
     * 2.2): os mesmos dias informados como presenciais ou como home sao a
     * mesma regra e nao criam historico.
     */
    private static function config(?string $config): mixed {
        if ($config === null || trim($config) === '') {
            return null;
        }
        $decoded = json_decode($config, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $config;
        }
        if (is_array($decoded)) {
            unset($decoded['input_mode']);
        }
        return $decoded;
    }
}
