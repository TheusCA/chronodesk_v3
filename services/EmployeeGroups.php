<?php
declare(strict_types=1);

/**
 * [9.17, D-CHG-13] Grupos por funcionario, separados da equipe e do perfil
 * (tabela funcionario_grupos). Hoje so o grupo Tools, que da a permissao
 * chg.manage (Controle de CHG). Quem marca: admin e Lideranca, pelo cadastro
 * de funcionarios (mesmas regras do cadastro). Auditoria so com IDs.
 *
 * D-CHG-14: o perfil somente leitura nunca escreve, nem no Tools: o cadastro
 * recusa Tools para esse perfil, e mudar o perfil para somente leitura tira
 * o Tools na mesma gravacao.
 */
final class EmployeeGroups {
    public const TOOLS = 'tools';
    public const READ_ONLY_MESSAGE = 'O perfil somente leitura não entra no grupo Tools (ele nunca altera dados).';

    public function __construct(private PDO $pdo) {
    }

    /** Valor do campo "Grupo Tools" vindo da tela: true, false ou null (invalido). */
    public static function flag(mixed $value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        return null;
    }

    /** null = pode; senao a mensagem (400). */
    public static function toolsDenied(bool $tools, ?string $role): ?string {
        return $tools && portal_role_value($role) === 'somente_leitura' ? self::READ_ONLY_MESSAGE : null;
    }

    /** O funcionario ativo esta no grupo? */
    public function inGroup(int $employeeId, string $group = self::TOOLS): bool {
        if ($employeeId < 1) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM funcionario_grupos g JOIN funcionarios f ON f.id = g.funcionario_id
              WHERE g.funcionario_id = :id AND g.grupo = :grupo AND f.ativo = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $employeeId, ':grupo' => $group]);
        return $stmt->fetchColumn() !== false;
    }

    /** IDs no grupo (para a lista de funcionarios). */
    public function members(string $group = self::TOOLS): array {
        $stmt = $this->pdo->prepare('SELECT funcionario_id FROM funcionario_grupos WHERE grupo = :grupo ORDER BY funcionario_id');
        $stmt->execute([':grupo' => $group]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Grava a pertenca dentro da transacao do cadastro (o cadastro ja esta
     * travado). Devolve 'entrou', 'saiu' ou null (sem mudanca).
     */
    public function setTools(int $employeeId, bool $tools, string $actor): ?string {
        $stmt = $this->pdo->prepare('SELECT 1 FROM funcionario_grupos WHERE funcionario_id = :id AND grupo = :grupo FOR UPDATE');
        $stmt->execute([':id' => $employeeId, ':grupo' => self::TOOLS]);
        $current = $stmt->fetchColumn() !== false;
        if ($current === $tools) {
            return null;
        }
        if ($tools) {
            $this->pdo->prepare('INSERT INTO funcionario_grupos (funcionario_id, grupo, created_by) VALUES (:id, :grupo, :actor)')
                ->execute([':id' => $employeeId, ':grupo' => self::TOOLS, ':actor' => $actor]);
            return 'entrou';
        }
        $this->pdo->prepare('DELETE FROM funcionario_grupos WHERE funcionario_id = :id AND grupo = :grupo')
            ->execute([':id' => $employeeId, ':grupo' => self::TOOLS]);
        return 'saiu';
    }

    /** Linha de auditoria (so IDs e o sentido). */
    public static function auditDetails(int $employeeId, string $change, string $actorRole): string {
        return "Grupo tools do funcionario ID {$employeeId}: {$change} por {$actorRole}";
    }
}
