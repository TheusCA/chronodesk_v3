<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceEngine.php';

/**
 * Padrao explicito do ciclo (F10-2; desenho do F10, secao 3.2).
 *
 * Funcoes puras. O ciclo em blocos de hoje (N presenciais e M remotos) fica
 * no OperationalService, sem mudanca; aqui ficam os dois modos novos, que
 * servem para a regra individual e para o time de rodizio:
 * - padrao: lista de 2 a 20 posicoes 'onsite'/'remote' (o limite do motor),
 *   ancora em dia util;
 * - semana a semana: 1 a 4 semanas de seg a sex (5 posicoes cada), ancora na
 *   segunda. O padrao e a concatenacao das semanas, o mesmo calculo provado
 *   na 2.2 do desenho (FIXO CICLO = ciclo de 5 x N posicoes).
 * O motor de presenca nao muda: le pattern e anchor_date como sempre; weeks
 * so muda o rotulo.
 */
final class CyclePattern {
    public const MIN_POSITIONS = 2;
    public const MAX_POSITIONS = 20;
    public const MAX_WEEKS = 4;
    public const WEEK_DAYS = 5;

    /** O payload do ciclo usa um modo novo (padrao ou semanas), e nao os blocos. */
    public static function isExplicit(array $cycle): bool {
        return array_key_exists('pattern', $cycle) || array_key_exists('weeks', $cycle);
    }

    /**
     * Padrao validado do payload: ['pattern' => list, 'weeks' => ?int,
     * 'anchor_date' => 'Y-m-d']. Entrada invalida: InvalidArgumentException
     * com a mensagem do campo (400).
     */
    public static function fromPayload(array $cycle): array {
        if (array_key_exists('pattern', $cycle) && array_key_exists('weeks', $cycle)) {
            throw new InvalidArgumentException('Informe o ciclo por padrão ou por semanas, não pelos dois.');
        }
        $anchor = $cycle['anchor_date'] ?? null;
        if (!is_string($anchor) || !PresenceEngine::validDate($anchor)) {
            throw new InvalidArgumentException('Data inicial do ciclo inválida.');
        }
        $weeks = null;
        if (array_key_exists('weeks', $cycle)) {
            $rows = $cycle['weeks'];
            if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > self::MAX_WEEKS) {
                throw new InvalidArgumentException('O ciclo semana a semana tem de 1 a ' . self::MAX_WEEKS . ' semanas.');
            }
            $pattern = [];
            foreach ($rows as $index => $row) {
                if (!is_array($row) || !array_is_list($row) || count($row) !== self::WEEK_DAYS) {
                    throw new InvalidArgumentException('Semana ' . ($index + 1) . ' do ciclo: informe os 5 dias, de segunda a sexta.');
                }
                foreach ($row as $value) {
                    $pattern[] = self::position($value);
                }
            }
            if (self::weekday($anchor) !== 1) {
                throw new InvalidArgumentException('No ciclo semana a semana, a data inicial precisa ser uma segunda-feira.');
            }
            $weeks = count($rows);
        } else {
            $raw = $cycle['pattern'];
            if (!is_array($raw) || !array_is_list($raw) || count($raw) < self::MIN_POSITIONS || count($raw) > self::MAX_POSITIONS) {
                throw new InvalidArgumentException('O padrão do ciclo tem de ' . self::MIN_POSITIONS . ' a ' . self::MAX_POSITIONS . ' dias úteis.');
            }
            $pattern = array_map(static fn ($value): string => self::position($value), $raw);
            if (!PresenceEngine::isBusinessDay($anchor)) {
                throw new InvalidArgumentException('A data inicial do ciclo precisa ser dia útil (segunda a sexta).');
            }
        }
        if (!in_array('remote', $pattern, true)) {
            throw new InvalidArgumentException('Ciclo sem nenhum dia remoto: use a regra "Sempre presencial".');
        }
        if (!in_array('onsite', $pattern, true)) {
            throw new InvalidArgumentException('Ciclo sem nenhum dia presencial: use a regra "Sempre remoto".');
        }
        return ['pattern' => $pattern, 'weeks' => $weeks, 'anchor_date' => $anchor];
    }

    /**
     * rule_config gravado: JSON em ordem fixa (salvar de novo a mesma regra
     * nao cria historico), igual ao dos blocos mais 'weeks' no semana a semana.
     */
    public static function config(array $pattern, string $anchor, ?int $weeks): string {
        $config = ['pattern' => array_values($pattern), 'anchor_date' => $anchor, 'business_days_only' => true];
        if ($weeks !== null) {
            $config['weeks'] = $weeks;
        }
        return (string)json_encode($config, JSON_UNESCAPED_SLASHES);
    }

    /** Semanas do ciclo semana a semana na configuracao, ou null (blocos, padrao livre ou dado ruim). */
    public static function weeksFromConfig(?array $config): ?int {
        $weeks = $config['weeks'] ?? null;
        $pattern = $config['pattern'] ?? null;
        if (!is_int($weeks) || $weeks < 1 || $weeks > self::MAX_WEEKS || !is_array($pattern) || count($pattern) !== self::WEEK_DAYS * $weeks) {
            return null;
        }
        return $weeks;
    }

    /** Rotulo: "Ciclo 3 semanas, 9P/6R" no semana a semana; senao o do motor ("Ciclo 2P/1R"). */
    public static function label(array $pattern, ?int $weeks): string {
        $base = PresenceEngine::cycleLabel($pattern);
        if ($weeks === null) {
            return ucfirst($base);
        }
        return 'Ciclo ' . $weeks . ($weeks === 1 ? ' semana, ' : ' semanas, ') . substr($base, strlen('ciclo '));
    }

    private static function position(mixed $value): string {
        if ($value !== 'onsite' && $value !== 'remote') {
            throw new InvalidArgumentException('Cada dia do ciclo é presencial (onsite) ou remoto (remote).');
        }
        return $value;
    }

    private static function weekday(string $date): int {
        return (int)(new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->format('N');
    }
}
