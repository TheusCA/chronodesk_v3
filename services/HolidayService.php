<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/PresenceEngine.php';

/**
 * [D-ESC-16] Cadastro de feriados (migration 027): data unica, nome e
 * abrangencia 'todos'. No motor, o feriado e dia sem expediente para todos
 * (D-ESC-17); o ciclo e o rodizio continuam contando o dia como util.
 *
 * Leitura: qualquer sessao do portal (o nome do feriado nao e dado de pessoa).
 * Gravacao: feriados.manage (Lideranca e admin), com row_version (409 quando
 * outra pessoa mudou antes). Auditoria so com o ID e a data.
 */
final class HolidayService {
    public const NAME_MAX = 80;
    public const FORBIDDEN_MESSAGE = 'Apenas administradores e Liderança alteram os feriados.';
    public const CONFLICT_MESSAGE = 'Este feriado foi alterado por outra pessoa: recarregue a lista e repita.';

    private PDO $pdo;
    private ?Closure $audit;

    /** $audit: function (string $action, string $details, string $severity), so para teste; padrao e o audit_log. */
    public function __construct(?PDO $pdo = null, ?Closure $audit = null) {
        $this->pdo = $pdo ?? get_db_connection();
        $this->audit = $audit;
    }

    /** Feriados do ano (padrao: o ano de hoje), em ordem de data. */
    public function list(array $query, string $role): array {
        $year = $query['year'] ?? date('Y');
        if (!is_scalar($year) || preg_match('/^(19|20)\d{2}$/', (string)$year) !== 1) {
            throw new InvalidArgumentException('Ano inválido.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, holiday_date, name, scope, row_version, updated_by, updated_at FROM portal_holidays
             WHERE holiday_date BETWEEN :date_from AND :date_to ORDER BY holiday_date'
        );
        $stmt->execute([':date_from' => $year . '-01-01', ':date_to' => $year . '-12-31']);
        return [
            'year' => (int)$year,
            'can_manage' => portal_role_can($role, 'feriados.manage'),
            'items' => array_map(static fn (array $row): array => [
                'id' => (int)$row['id'],
                'date' => (string)$row['holiday_date'],
                'name' => (string)$row['name'],
                'scope' => (string)$row['scope'],
                'row_version' => (int)$row['row_version'],
                'updated_by' => (string)$row['updated_by'],
                'updated_at' => $row['updated_at'] === null ? null : (string)$row['updated_at'],
            ], $stmt->fetchAll()),
        ];
    }

    /** Cria (sem id) ou altera (id e row_version) o feriado. */
    public function save(array $data, string $actor, string $role): array {
        $this->assertCanManage($role);
        $date = $data['date'] ?? null;
        if (!is_string($date) || !PresenceEngine::validDate($date)) {
            throw new InvalidArgumentException('Data do feriado inválida.');
        }
        $name = is_string($data['name'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $data['name'])) : '';
        if ($name === '' || preg_match_all('/./us', $name) > self::NAME_MAX) {
            throw new InvalidArgumentException('Nome do feriado: de 1 a ' . self::NAME_MAX . ' caracteres.');
        }
        $id = $this->optionalId($data['id'] ?? null);
        return $this->atomic(function () use ($id, $date, $name, $data, $actor): array {
            $other = $this->pdo->prepare('SELECT id FROM portal_holidays WHERE holiday_date = :holiday_date FOR UPDATE');
            $other->execute([':holiday_date' => $date]);
            $found = $other->fetchColumn();
            if ($found !== false && (int)$found !== $id) {
                throw new DomainException('Já existe feriado em ' . (new DateTimeImmutable($date))->format('d/m/Y') . '.');
            }
            if ($id === null) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO portal_holidays (holiday_date, name, scope, row_version, created_by, updated_by)
                     VALUES (:holiday_date, :name, :scope, 1, :created_by, :updated_by)'
                );
                $stmt->execute([':holiday_date' => $date, ':name' => $name, ':scope' => 'todos', ':created_by' => $actor, ':updated_by' => $actor]);
                $id = (int)$this->pdo->lastInsertId();
                $this->audit('FERIADO_CRIADO', "feriado_id={$id}; data={$date}");
                return ['id' => $id, 'row_version' => 1, 'created' => true];
            }
            $version = $this->lockVersion($id, $data['row_version'] ?? null);
            $stmt = $this->pdo->prepare(
                'UPDATE portal_holidays SET holiday_date = :holiday_date, name = :name, row_version = row_version + 1, updated_by = :updated_by
                 WHERE id = :id AND row_version = :row_version'
            );
            $stmt->execute([':holiday_date' => $date, ':name' => $name, ':updated_by' => $actor, ':id' => $id, ':row_version' => $version]);
            $this->audit('FERIADO_ALTERADO', "feriado_id={$id}; data={$date}");
            return ['id' => $id, 'row_version' => $version + 1, 'created' => false];
        });
    }

    /** Remove o feriado (id e row_version). */
    public function delete(array $data, string $role): array {
        $this->assertCanManage($role);
        $id = $this->optionalId($data['id'] ?? null);
        if ($id === null) {
            throw new InvalidArgumentException('Feriado inválido.');
        }
        return $this->atomic(function () use ($id, $data): array {
            $version = $this->lockVersion($id, $data['row_version'] ?? null);
            $date = $this->pdo->prepare('SELECT holiday_date FROM portal_holidays WHERE id = :id');
            $date->execute([':id' => $id]);
            $day = (string)$date->fetchColumn();
            $this->pdo->prepare('DELETE FROM portal_holidays WHERE id = :id AND row_version = :row_version')->execute([':id' => $id, ':row_version' => $version]);
            $this->audit('FERIADO_REMOVIDO', "feriado_id={$id}; data={$day}");
            return ['id' => $id];
        });
    }

    /** Trava a linha e confere a versao enviada (409 se outra pessoa mudou). */
    private function lockVersion(int $id, mixed $sent): int {
        $version = is_scalar($sent) ? filter_var($sent, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($version === false) {
            throw new InvalidArgumentException('Versão do feriado inválida.');
        }
        $stmt = $this->pdo->prepare('SELECT row_version FROM portal_holidays WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new DomainException(self::CONFLICT_MESSAGE);
        }
        if ((int)$current !== (int)$version) {
            throw new DomainException(self::CONFLICT_MESSAGE);
        }
        return (int)$version;
    }

    private function assertCanManage(string $role): void {
        if (!portal_role_can($role, 'feriados.manage')) {
            throw new PortalForbiddenException(self::FORBIDDEN_MESSAGE);
        }
    }

    private function optionalId(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Feriado inválido.');
        }
        return (int)$id;
    }

    private function audit(string $action, string $details): void {
        if ($this->audit !== null) {
            ($this->audit)($action, $details, 'INFO');
        } elseif (function_exists('audit_log')) {
            audit_log($action, $details, 'INFO');
        }
    }

    private function atomic(callable $callback): mixed {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
