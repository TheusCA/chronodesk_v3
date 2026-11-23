<?php
declare(strict_types=1);

/**
 * [Lote NC, 2026-11-23] NC: a matricula do cadastro Senior, um numero fixo
 * por pessoa (migration 026; docs/DESENHO_NC_E_ICONE.md). So digitos, de 1 a
 * 12, sem zero a esquerda, unico entre os cadastros; NULL = sem NC. A copia em cada lancamento de
 * hora extra e de correcao de ponto vem do cadastro, lida pelo servidor na
 * mesma transacao: nunca do navegador (D-NC-2). O valor nunca vai para log
 * nem auditoria (D-NC-3).
 */
final class EmployeeNc {
    public const REQUIRED_MESSAGE = 'Sua matrícula (NC) não está no cadastro. Peça à Liderança para incluir.';
    public const INVALID_MESSAGE = 'NC inválido: use só dígitos, de 1 a 12, sem zero à esquerda.';
    public const MISSING_MESSAGE = 'NC obrigatório: informe a matrícula do cadastro Senior (só dígitos, de 1 a 12, sem zero à esquerda).';
    public const IN_USE_MESSAGE = 'Este NC já está em outro cadastro (ativo ou arquivado).';
    public const CLEAR_MESSAGE = 'O NC não pode ser apagado pela tela: corrija o número.';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * NC do corpo da requisicao: texto so de digitos (de 1 a 12, sem zero a
     * esquerda) ou null (vazio). false = invalido. Numero inteiro e aceito como
     * texto. Zero a esquerda e recusado (o NC sai como numero na planilha do RH).
     */
    public static function normalize(mixed $value): string|null|false {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return preg_match('/^[1-9][0-9]{0,11}$/D', $value) === 1 ? $value : false;
    }

    /**
     * [D-NC-5] NC_OBRIGATORIO: 1/true liga; ausente ou vazio desliga; valor
     * invalido desliga, com aviso no log (sem o valor).
     */
    public static function required(): bool {
        $raw = getenv('NC_OBRIGATORIO');
        if ($raw === false || trim((string)$raw) === '') {
            return false;
        }
        $value = filter_var(trim((string)$raw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            error_log('[NC] NC_OBRIGATORIO com valor invalido; so aviso.');
            return false;
        }
        return $value;
    }

    /** [D-NC-3] Tipo da mudanca para a auditoria (nunca o valor): null quando nao muda. */
    public static function changeKind(?string $before, ?string $after): ?string {
        if ($before === $after) {
            return null;
        }
        if ($before === null) {
            return 'incluido';
        }
        return $after === null ? 'removido' : 'alterado';
    }

    /** NC gravado no cadastro (de qualquer situacao), ou null. */
    public function of(int $employeeId, bool $lock = false): ?string {
        $stmt = $this->pdo->prepare('SELECT matricula FROM funcionarios WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([':id' => $employeeId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string)$value;
    }

    /** O NC ja esta em outro cadastro (ativo ou arquivado)? */
    public function inUse(string $nc, ?int $exceptId = null): bool {
        $stmt = $this->pdo->prepare('SELECT id FROM funcionarios WHERE matricula = :matricula AND id <> :id LIMIT 1');
        $stmt->execute([':matricula' => $nc, ':id' => $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    /** Grava o NC do cadastro (validado e conferido antes por quem chama). */
    public function store(int $employeeId, ?string $nc): void {
        $this->pdo->prepare('UPDATE funcionarios SET matricula = :matricula WHERE id = :id')
            ->execute([':matricula' => $nc, ':id' => $employeeId]);
    }

    /** [D-NC-3] Texto da auditoria: so o ID e o tipo da mudanca. */
    public static function auditDetails(int $employeeId, string $kind, string $actorRole): string {
        return "NC do funcionario ID {$employeeId} {$kind} por {$actorRole}";
    }
}
