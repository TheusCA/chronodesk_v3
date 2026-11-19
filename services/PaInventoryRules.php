<?php
declare(strict_types=1);

/**
 * Regras puras do inventario de PA do F10-3 (desenho do F10, 3.3 e 3.3.1).
 * Sem banco: valida o tipo, o emprestimo e a reserva de um PA e a lista de
 * preferencias. O PaMapService aplica, com as travas e as contagens do banco.
 *
 * D-F10-19 revista (2026-11-16): o Critico tem N PAs reservados (os marcados
 * como reservados ao Critico; N = 2 hoje, configuravel pela tela) e os papeis
 * do dia (9.16) dizem quem ocupa; o Hibrido fica na opcao (c), PA fixo por
 * pessoa pelo vinculo do Mapa PA: a reserva do Hibrido e recusada.
 */
final class PaInventoryRules
{
    public const SEAT_TYPES = ['flexible', 'fixed'];
    /** Reservas aceitas hoje (D-F10-19 revista: so o Critico). */
    public const RESERVED_TEAMS = ['critico'];
    public const MAX_PREFERENCES = 20;

    /**
     * Tipo, emprestimo e reserva do PA, ja validados:
     * ['seat_type' => 'flexible'|'fixed', 'can_lend' => bool, 'reserved_team' => ?string].
     * As mesmas regras das restricoes da 022: reserva so em fixo; emprestimo
     * so em fixo sem reserva. InvalidArgumentException com a mensagem da tela.
     */
    public static function seat(array $data): array
    {
        $type = $data['seat_type'] ?? null;
        if (!is_string($type) || !in_array($type, self::SEAT_TYPES, true)) {
            throw new InvalidArgumentException('Tipo do PA inválido: use flexível ou fixo.');
        }
        $reserved = $data['reserved_team'] ?? null;
        if ($reserved === '') {
            $reserved = null;
        }
        if ($reserved === 'hibrido') {
            throw new InvalidArgumentException('O Híbrido usa PA fixo por pessoa (vínculo do Mapa PA): a reserva por equipe é só do Crítico.');
        }
        if ($reserved !== null && (!is_string($reserved) || !in_array($reserved, self::RESERVED_TEAMS, true))) {
            throw new InvalidArgumentException('Reserva inválida.');
        }
        $lend = $data['can_lend'] ?? false;
        if (!is_bool($lend)) {
            throw new InvalidArgumentException('Empréstimo inválido.');
        }
        if ($reserved !== null && $type !== 'fixed') {
            throw new InvalidArgumentException('Reserva só em PA fixo.');
        }
        if ($lend && ($type !== 'fixed' || $reserved !== null)) {
            throw new InvalidArgumentException('Empréstimo só em PA fixo sem reserva: o dono ausente empresta o PA ao grupo comum.');
        }
        return ['seat_type' => $type, 'can_lend' => $lend, 'reserved_team' => $reserved];
    }

    /** Linha do banco no formato de seat() (o can_lend gravado como 0/1). */
    public static function storedSeat(array $row): array
    {
        $reserved = $row['reserved_team'] ?? null;
        return [
            'seat_type' => (string)($row['seat_type'] ?? 'flexible'),
            'can_lend' => (int)($row['can_lend'] ?? 0) === 1,
            'reserved_team' => $reserved === null || $reserved === '' ? null : (string)$reserved,
        ];
    }

    /** O que mudou entre dois estados do PA (nomes dos campos, na ordem). */
    public static function changedFields(array $before, array $after): array
    {
        return array_values(array_filter(['seat_type', 'can_lend', 'reserved_team'], static fn (string $field): bool => $before[$field] !== $after[$field]));
    }

    /**
     * Lista de preferencias do PA: de 0 a MAX_PREFERENCES IDs inteiros
     * positivos, sem repeticao, em ordem crescente.
     */
    public static function preferenceIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_PREFERENCES) {
            throw new InvalidArgumentException('Preferências: de 0 a ' . self::MAX_PREFERENCES . ' colaboradores por PA.');
        }
        $ids = [];
        foreach ($value as $id) {
            $parsed = is_int($id) ? $id : (is_string($id) && preg_match('/^[1-9]\d{0,9}$/', $id) === 1 ? (int)$id : 0);
            if ($parsed < 1 || isset($ids[$parsed])) {
                throw new InvalidArgumentException('Preferências: colaboradores inválidos ou repetidos.');
            }
            $ids[$parsed] = $parsed;
        }
        ksort($ids);
        return array_values($ids);
    }

    /**
     * Quem pode ter preferencia de PA: cadastro ativo do grupo comum (N1 ou
     * N2 com grupo 'comum', D-F9-12) e fora do "fora do Mapa". $row: equipe ja
     * normalizada ('team'), 'grupo_escala' e 'fora_mapa_escala'.
     */
    public static function canPrefer(array $row): bool
    {
        return in_array($row['team'] ?? null, ['n1', 'n2'], true)
            && ($row['grupo_escala'] ?? null) === 'comum'
            && (int)($row['fora_mapa_escala'] ?? 0) === 0;
    }

    /** Ordem do inventario (a do motor): fileira, coluna e numero. */
    public static function inventoryOrder(array $a, array $b): int
    {
        return [(int)($a['grid_row'] ?? 0), (int)($a['grid_column'] ?? 0), (string)$a['pa_number']] <=> [(int)($b['grid_row'] ?? 0), (int)($b['grid_column'] ?? 0), (string)$b['pa_number']];
    }
}
