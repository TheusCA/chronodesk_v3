<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceEngine.php';
require_once __DIR__ . '/ScheduleRulePlanner.php';

/**
 * Data de corte do motor de presenca (Parte B, F5; desenho, secao 9.7).
 *
 * Antes do corte, relatorio, CSV e Calendario ficam no calculo antigo, sem
 * nenhuma mudanca; a partir dele, no motor. A data fica em
 * portal_presence_cutoff (migration 019), gravada so pelo script de registro
 * do deploy e imutavel no banco (gatilhos da 019); a aplicacao so le.
 * Tabela ausente (019 nao aplicada) ou vazia (corte nao registrado): sem
 * corte, tudo no calculo antigo. O corte pode estar no futuro (D2): ate a
 * data, tudo no calculo antigo; periodo que cruza o corte e dividido nele.
 */
final class PresenceCutoff {
    /**
     * Corte (Y-m-d, sempre dia 16) ou null sem tabela ou sem linha. Valor
     * fora disso no banco (os CHECK da 019 impedem) para a requisicao, em vez
     * de cair em silencio no calculo antigo.
     */
    public static function read(PDO $pdo): ?string {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute([':table_name' => 'portal_presence_cutoff']);
        if ((int)$stmt->fetchColumn() === 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT cutoff_date FROM portal_presence_cutoff WHERE id = 1');
        $stmt->execute();
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        $value = (string)$value;
        if (!PresenceEngine::validDate($value) || substr($value, 8, 2) !== '16') {
            throw new UnexpectedValueException('Data de corte do motor de presenca invalida no banco.');
        }
        return $value;
    }

    /**
     * Funcao pura: divide [from, to] no corte. 'legacy' vai de from ate a
     * vespera do corte; 'engine', do corte ate to. Cada parte e
     * ['from' => Y-m-d, 'to' => Y-m-d] ou null. Sem corte, tudo e 'legacy'.
     */
    public static function split(string $from, string $to, ?string $cutoff): array {
        if (!PresenceEngine::validDate($from) || !PresenceEngine::validDate($to) || $to < $from
            || ($cutoff !== null && !PresenceEngine::validDate($cutoff))) {
            throw new InvalidArgumentException('Periodo ou data de corte invalida.');
        }
        if ($cutoff === null || $to < $cutoff) {
            return ['legacy' => ['from' => $from, 'to' => $to], 'engine' => null];
        }
        if ($from >= $cutoff) {
            return ['legacy' => null, 'engine' => ['from' => $from, 'to' => $to]];
        }
        return [
            'legacy' => ['from' => $from, 'to' => ScheduleRulePlanner::addDays($cutoff, -1)],
            'engine' => ['from' => $cutoff, 'to' => $to],
        ];
    }

    /** Texto da linha "aviso" do CSV quando o periodo cruza o corte (D4). */
    public static function splitNotice(string $cutoff): string {
        $day = static fn (string $date): string => substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
        return 'Escala pelo cálculo antigo até ' . $day(ScheduleRulePlanner::addDays($cutoff, -1))
            . ' e pelas regras novas a partir de ' . $day($cutoff);
    }
}
