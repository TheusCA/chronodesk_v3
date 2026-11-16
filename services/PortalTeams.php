<?php
declare(strict_types=1);

/**
 * Equipes do portal (F9; desenho principal, 9.15; D-F9-1, D-F9-2 e D-F9-12).
 *
 * Funcoes puras: a unica lista de equipes do servidor. Toda validacao, filtro
 * e painel usa esta classe, para equipe nova nao sumir em silencio de uma
 * lista fixa esquecida.
 * - Equipes de tecnico (painel, pausas, escala): N1, N2, Critico e Hibrido.
 * - Lideranca: equipe de cadastro, fora do painel (como hoje).
 * - Grupo comum (Total da Visao operacional, grupo comum dos PAs, times de
 *   rodizio): so N1 e N2 (D-F9-10 a D-F9-12).
 */
final class PortalTeams {
    public const N1 = 'n1';
    public const N2 = 'n2';
    public const LEADERSHIP = 'lideranca';
    public const CRITICAL = 'critico';
    public const HYBRID = 'hibrido';

    /** Equipes de tecnico, na ordem do painel. */
    public const WORK_TEAMS = [self::N1, self::N2, self::CRITICAL, self::HYBRID];

    /** Todas as equipes de cadastro. */
    public const ALL = [self::N1, self::N2, self::LEADERSHIP, self::CRITICAL, self::HYBRID];

    /** Grupo comum da planilha: N1 e N2. */
    public const COMMON_GROUP = [self::N1, self::N2];

    public const LABELS = [
        self::N1 => 'N1',
        self::N2 => 'N2',
        self::LEADERSHIP => 'Liderança',
        self::CRITICAL => 'Crítico',
        self::HYBRID => 'Híbrido',
    ];

    /** Apelidos aceitos na entrada (os da Lideranca ja existiam; D-F9-1). */
    private const ALIASES = [
        'na' => self::LEADERSHIP,
        'não se aplica' => self::LEADERSHIP,
        'nao se aplica' => self::LEADERSHIP,
        'nao_se_aplica' => self::LEADERSHIP,
        'sem_equipe' => self::LEADERSHIP,
        'liderança' => self::LEADERSHIP,
        'crítico' => self::CRITICAL,
        'híbrido' => self::HYBRID,
    ];

    /** Equipe do cadastro normalizada, ou null se desconhecida. */
    public static function normalize(mixed $team): ?string {
        if (!is_string($team) && !is_int($team)) {
            return null;
        }
        $value = self::lower(trim((string)$team));
        $value = self::ALIASES[$value] ?? $value;
        return in_array($value, self::ALL, true) ? $value : null;
    }

    /** Equipe de tecnico (aparece no painel e conta pausas). */
    public static function isWorkTeam(?string $team): bool {
        return in_array($team, self::WORK_TEAMS, true);
    }

    /** N1 ou N2: o grupo comum da planilha. */
    public static function inCommonGroup(?string $team): bool {
        return in_array($team, self::COMMON_GROUP, true);
    }

    /**
     * Grupo da escala gravado para a equipe (D-F9-12): Critico e Hibrido
     * sempre 'separado'; nas outras, o pedido ('comum' por padrao).
     * null = combinacao recusada (Critico ou Hibrido no 'comum').
     */
    public static function scheduleGroupFor(?string $team, ?string $requested): ?string {
        $requested = $requested === null || $requested === '' ? null : $requested;
        if ($requested !== null && !in_array($requested, ['comum', 'separado'], true)) {
            return null;
        }
        if ($team === self::CRITICAL || $team === self::HYBRID) {
            return $requested === 'comum' ? null : 'separado';
        }
        return $requested ?? 'comum';
    }

    public static function label(?string $team): string {
        return self::LABELS[$team] ?? strtoupper((string)$team);
    }

    /** Lista para o frontend: valor, rotulo, se e de tecnico e se e do grupo comum. */
    public static function catalog(): array {
        return array_map(static fn (string $team): array => [
            'value' => $team,
            'label' => self::LABELS[$team],
            'work_team' => self::isWorkTeam($team),
            'common_group' => self::inCommonGroup($team),
        ], self::ALL);
    }

    /**
     * strtolower sem mbstring, com o 'Í' maiusculo de CRÍTICO e HÍBRIDO.
     * So ele: 'LIDERANÇA' continua nao reconhecida, como antes do F9 (risco
     * registrado na 9.9; a Visao operacional nao muda).
     */
    private static function lower(string $value): string {
        return strtr(strtolower($value), ['Í' => 'í']);
    }
}
