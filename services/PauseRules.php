<?php
declare(strict_types=1);

require_once __DIR__ . '/PortalTeams.php';

/**
 * Regras da pausa por motivo e equipe (F9; desenho principal, 9.15.4 e 9.15.5;
 * D-F9-5 a D-F9-9, com a D-F9-6 e a D-F9-16 revistas em 2026-11-16).
 *
 * Funcoes puras, sem banco nem relogio:
 * - motivos: Cafe, Pessoal e Reuniao para todos; "Almoço" so para o Critico;
 * - o limite simultaneo da equipe e os alertas de 15 e 20 min valem para o
 *   cafe (e outro motivo que nao seja Pessoal, Reuniao ou Almoco), como hoje;
 * - o limite de tempo aplicado: cafe com a configuracao do momento; almoco
 *   do Critico com a duracao do contrato (F8) ou, ate la, a do intervalo de
 *   almoco do cadastro; sem intervalo valido, sem limite (o caminho da DL17);
 *   Pessoal e Reuniao sem limite;
 * - "excedeu": com o limite gravado na pausa quando ha; as pausas antigas
 *   (sem o limite gravado) com a configuracao atual, como antes; o almoco sem
 *   limite gravado nunca excede;
 * - o almoco fica fora dos totais e medias por funcionario e por equipe (e
 *   continua em "por motivo").
 */
final class PauseRules {
    public const COFFEE = 'Café';
    public const PERSONAL = 'Pessoal';
    public const MEETING = 'Reunião';
    public const LUNCH = 'Almoço';

    /** Motivos de todas as equipes. */
    public const COMMON_REASONS = [self::COFFEE, self::PERSONAL, self::MEETING];

    public const LUNCH_ONLY_CRITICAL_MESSAGE = 'Motivo de pausa não permitido para esta equipe. A pausa "Almoço" é só da equipe Crítico.';

    /** Todos os motivos que o sistema grava (lista fechada, SEC-07b). */
    public const ALL_REASONS = [self::COFFEE, self::PERSONAL, self::MEETING, self::LUNCH];

    /** Motivos que a equipe pode lancar. */
    public static function reasonsFor(?string $team): array {
        return $team === PortalTeams::CRITICAL
            ? [...self::COMMON_REASONS, self::LUNCH]
            : self::COMMON_REASONS;
    }

    /**
     * Motivo valido. Sem equipe: a lista fechada inteira (mostrar o motivo no
     * Calendario e na presenca). Com equipe: so os motivos dela.
     */
    public static function validReason(mixed $reason, ?string $team = null, bool $teamKnown = false): ?string {
        if (!is_string($reason)) {
            return null;
        }
        $allowed = $teamKnown ? self::reasonsFor($team) : self::ALL_REASONS;
        return in_array($reason, $allowed, true) ? $reason : null;
    }

    public static function isLunch(mixed $reason): bool {
        return $reason === self::LUNCH;
    }

    public static function isMeeting(mixed $reason): bool {
        return strpos(strtolower((string)$reason), 'reuni') === 0;
    }

    /**
     * Conta no limite simultaneo da equipe e nos alertas de 15/20 min: nao
     * para Pessoal, Reuniao e Almoco (D-F9-5).
     */
    public static function countsForTeamLimit(mixed $reason): bool {
        $value = strtolower((string)$reason);
        return !($value === 'pessoal' || self::isMeeting($reason) || self::isLunch($reason));
    }

    /**
     * Duracao do intervalo de almoco do cadastro em segundos, ou null sem
     * intervalo valido (fim depois do inicio, no mesmo dia; HH:MM ou HH:MM:SS).
     */
    public static function lunchIntervalSeconds(mixed $start, mixed $end): ?int {
        $startSeconds = self::clockSeconds($start);
        $endSeconds = self::clockSeconds($end);
        if ($startSeconds === null || $endSeconds === null || $endSeconds <= $startSeconds) {
            return null;
        }
        return $endSeconds - $startSeconds;
    }

    /**
     * Limite de tempo aplicado a pausa, em segundos; null = sem limite.
     * $contractSeconds: duracao do contrato (F8; null enquanto nao existe).
     */
    public static function appliedLimitSeconds(mixed $reason, int $configuredMinutes, ?int $lunchIntervalSeconds = null, ?int $contractSeconds = null): ?int {
        if (self::isLunch($reason)) {
            if ($contractSeconds !== null && $contractSeconds > 0) {
                return $contractSeconds;
            }
            return $lunchIntervalSeconds !== null && $lunchIntervalSeconds > 0 ? $lunchIntervalSeconds : null;
        }
        if (!self::countsForTeamLimit($reason)) {
            return null;
        }
        return $configuredMinutes > 0 ? $configuredMinutes * 60 : null;
    }

    /** Alertas de 15 e 20 min gravados ao finalizar: [alerta_15min, alerta_20min]. */
    public static function alerts(mixed $reason, int $durationSeconds): array {
        $applies = self::countsForTeamLimit($reason);
        return [$applies && $durationSeconds >= 15 * 60, $applies && $durationSeconds >= 20 * 60];
    }

    /**
     * Excedeu o limite. $storedLimit: limite_segundos da pausa (null nas
     * antigas e nas sem limite). Pausa antiga: a configuracao atual.
     */
    public static function exceeded(mixed $reason, int $durationSeconds, mixed $storedLimit, int $configuredMinutes): bool {
        if ($storedLimit !== null && $storedLimit !== '') {
            $limit = (int)$storedLimit;
            return $limit > 0 && $durationSeconds > $limit;
        }
        if (self::isLunch($reason) || !self::countsForTeamLimit($reason)) {
            return false;
        }
        return $configuredMinutes > 0 && $durationSeconds > $configuredMinutes * 60;
    }

    /** Entra nos totais e medias por funcionario e por equipe (o almoco nao). */
    public static function countsInTotals(mixed $reason): bool {
        return !self::isLunch($reason);
    }

    /**
     * De onde o motor tira o almoco (D-F9-8; 11.3): 'pausa' no Critico (a
     * pausa "Almoço" em andamento), 'cadastro' nas outras (o intervalo).
     */
    public static function lunchSource(?string $team): string {
        return $team === PortalTeams::CRITICAL ? 'pausa' : 'cadastro';
    }

    /**
     * Reuniao precisa de aprovacao: so o N1 (e equipe desconhecida). N2,
     * Critico e Hibrido sem aprovacao (D-F9-9 revista, AD-F9-2).
     */
    public static function meetingNeedsApproval(?string $team): bool {
        return !in_array($team, [PortalTeams::N2, PortalTeams::CRITICAL, PortalTeams::HYBRID], true);
    }

    /**
     * [AD-F9-1] Equipes com a regra de pelo menos 1 disponivel no lugar do
     * limite simultaneo fixo: Critico e Hibrido (N1 e N2 nao mudam).
     */
    public static function usesAvailabilityRule(?string $team): bool {
        return in_array($team, [PortalTeams::CRITICAL, PortalTeams::HYBRID], true);
    }

    /** Dentro da jornada do dia: inicio <= agora < fim (HH:MM ou HH:MM:SS). */
    public static function inShift(mixed $start, mixed $end, string $time): bool {
        $from = self::clockSeconds($start);
        $to = self::clockSeconds($end);
        $now = self::clockSeconds($time);
        return $from !== null && $to !== null && $now !== null && $now >= $from && $now < $to;
    }

    /**
     * Situacao da equipe (9.15.17). $members: id => ['in_shift' => bool,
     * 'paused' => bool, 'absent' => bool]. No turno = na jornada e sem ausencia;
     * disponivel = no turno e fora de pausa (almoco incluido).
     */
    public static function teamAvailability(array $members): array {
        $onShift = 0;
        $available = 0;
        foreach ($members as $member) {
            if (($member['in_shift'] ?? false) === true && ($member['absent'] ?? false) !== true) {
                $onShift++;
                if (($member['paused'] ?? false) !== true) {
                    $available++;
                }
            }
        }
        return ['no_turno' => $onShift, 'disponiveis' => $available, 'sem_disponivel' => $onShift > 0 && $available === 0];
    }

    /**
     * Pode iniciar a pausa? Sobra pelo menos 1 disponivel sem quem pede, ou
     * quem pede e a unica pessoa da equipe no turno (liberada, com destaque).
     * Devolve ['allowed' => bool, 'alone' => bool].
     */
    public static function availabilityDecision(array $members, int $requesterId): array {
        $others = $members;
        unset($others[$requesterId]);
        $situation = self::teamAvailability($others);
        if ($situation['disponiveis'] >= 1) {
            return ['allowed' => true, 'alone' => false];
        }
        $alone = $situation['no_turno'] === 0;
        return ['allowed' => $alone, 'alone' => $alone];
    }

    public static function availabilityMessage(?string $team): string {
        return 'Precisa ficar pelo menos 1 disponível no ' . PortalTeams::label($team) . '.';
    }

    public static function noneAvailableLabel(?string $team): string {
        return PortalTeams::label($team) . ' sem ninguém disponível';
    }

    private static function clockSeconds(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $match) !== 1) {
            return null;
        }
        return (int)$match[1] * 3600 + (int)$match[2] * 60 + (int)($match[3] ?? 0);
    }
}
