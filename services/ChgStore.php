<?php
declare(strict_types=1);

/**
 * [9.17] Acesso a dados do Controle de CHG. O servico (ChgService) so fala
 * com esta interface: o QA usa um banco em memoria e a integracao, o MySQL.
 * O card vai e volta no formato normalizado de ChgRules (com id, row_version,
 * os campos do formulario, carteiras, locais e tecnico_nome).
 */
interface ChgStore {
    /** Cards nao arquivados (ou um so, por id), com as partes. */
    public function cards(?int $id = null): array;

    /** O card travado (FOR UPDATE), com as partes; null se nao existe ou esta arquivado. */
    public function lockCard(int $id): ?array;

    /** Grava um card novo com as partes; devolve o id. */
    public function insertCard(array $card, string $actor): int;

    /** Grava o card (linha e partes), partindo do estado travado $before. */
    public function saveCard(array $card, array $before, string $actor): void;

    /** Funcionarios ativos: [id => nome]. */
    public function activeEmployees(): array;

    /** Catalogos: ['carteiras' => [{id, codigo, nome, ativo}], 'locais' => [{id, nome, ativo}]]. */
    public function catalogs(): array;

    /** Grava uma carteira (sem id = nova) ou um local; devolve o id. */
    public function saveCatalog(string $kind, array $row, string $actor): int;

    /** O funcionario ativo esta no grupo Tools? */
    public function inTools(int $employeeId): bool;

    public function begin(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function inTransaction(): bool;
}

final class PdoChgStore implements ChgStore {
    private const CARD_COLUMNS = 'u.id, u.legacy_id, u.solicitante, u.tipo, u.versao, u.versao_anterior, u.descricao, u.link_planilha,
        u.sugestao_at, u.service_status, u.sd_inicio, u.sd_fim, u.chg_numero, u.chg_status, u.tecnico_id, u.tecnico_nome_legado,
        u.stage_manual, u.stage_manual_auto, u.concluida, u.concluida_em, u.row_version, u.created_at, u.updated_at, f.nome AS tecnico_nome';

    public function __construct(private PDO $pdo) {
    }

    public function cards(?int $id = null): array {
        $sql = 'SELECT ' . self::CARD_COLUMNS . ' FROM portal_chg_updates u LEFT JOIN funcionarios f ON f.id = u.tecnico_id
                 WHERE u.archived_at IS NULL' . ($id !== null ? ' AND u.id = :id' : '') . ' ORDER BY u.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($id !== null ? [':id' => $id] : []);
        return $this->withParts($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function lockCard(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT ' . self::CARD_COLUMNS . ' FROM portal_chg_updates u LEFT JOIN funcionarios f ON f.id = u.tecnico_id
                 WHERE u.id = :id AND u.archived_at IS NULL FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $rows = $this->withParts($stmt->fetchAll(PDO::FETCH_ASSOC));
        return $rows[0] ?? null;
    }

    /** Linhas do card + carteiras, locais, planos, tentativas, endpoints e rollback, em lote. */
    private function withParts(array $rows): array {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int)$r['id'], $rows);
        $in = implode(',', $ids);
        $by = static function (array $list, string $key): array {
            $grouped = [];
            foreach ($list as $item) {
                $grouped[(int)$item[$key]][] = $item;
            }
            return $grouped;
        };
        $carteiras = $by($this->pdo->query("SELECT update_id, codigo, nome, pas FROM portal_chg_update_carteiras WHERE update_id IN ({$in}) ORDER BY update_id, position")->fetchAll(PDO::FETCH_ASSOC), 'update_id');
        $locais = $by($this->pdo->query("SELECT update_id, nome FROM portal_chg_update_locais WHERE update_id IN ({$in}) ORDER BY update_id, position")->fetchAll(PDO::FETCH_ASSOC), 'update_id');
        $plans = $by($this->pdo->query("SELECT update_id, implantacao, backup, rollback, task_impl, task_bkp, task_roll FROM portal_chg_plans WHERE update_id IN ({$in}) ORDER BY update_id, position")->fetchAll(PDO::FETCH_ASSOC), 'update_id');
        $attempts = $by($this->pdo->query("SELECT id, update_id, attempt_no, patch_status, reagendando, registered_at FROM portal_chg_attempts WHERE update_id IN ({$in}) ORDER BY update_id, attempt_no")->fetchAll(PDO::FETCH_ASSOC), 'update_id');
        $endpoints = $by($this->pdo->query("SELECT e.attempt_id, e.url, e.start_at, e.start_date FROM portal_chg_endpoints e JOIN portal_chg_attempts a ON a.id = e.attempt_id WHERE a.update_id IN ({$in}) ORDER BY e.attempt_id, e.position")->fetchAll(PDO::FETCH_ASSOC), 'attempt_id');
        $rollbacks = [];
        foreach ($this->pdo->query("SELECT id, update_id, url, start_at, started_at, status, plan_position FROM portal_chg_rollbacks WHERE update_id IN ({$in}) AND removed_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $rb) {
            $rollbacks[(int)$rb['update_id']] = ['id' => (int)$rb['id'], 'url' => (string)$rb['url'], 'start_at' => $rb['start_at'], 'started_at' => (string)$rb['started_at'], 'status' => (string)$rb['status'], 'plan_position' => (int)$rb['plan_position']];
        }
        $cards = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $cards[] = [
                'id' => $id,
                'legacy_id' => $row['legacy_id'],
                'row_version' => (int)$row['row_version'],
                'solicitante' => (string)$row['solicitante'],
                'tipo' => (string)$row['tipo'],
                'versao' => (string)$row['versao'],
                'versao_anterior' => (string)$row['versao_anterior'],
                'descricao' => (string)$row['descricao'],
                'link_planilha' => (string)$row['link_planilha'],
                'sugestao_at' => $row['sugestao_at'],
                'service_status' => (string)$row['service_status'],
                'sd_inicio' => $row['sd_inicio'],
                'sd_fim' => $row['sd_fim'],
                'chg_numero' => $row['chg_numero'],
                'chg_status' => $row['chg_status'],
                'tecnico_id' => $row['tecnico_id'] === null ? null : (int)$row['tecnico_id'],
                'tecnico_nome' => $row['tecnico_nome'] === null ? null : (string)$row['tecnico_nome'],
                'tecnico_nome_legado' => $row['tecnico_nome_legado'],
                'stage_manual' => $row['stage_manual'],
                'stage_manual_auto' => $row['stage_manual_auto'],
                'concluida' => (int)$row['concluida'] === 1,
                'concluida_em' => $row['concluida_em'],
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
                'carteiras' => array_map(static fn (array $c): array => ['codigo' => $c['codigo'], 'nome' => (string)$c['nome'], 'pas' => $c['pas'] === null ? null : (int)$c['pas']], $carteiras[$id] ?? []),
                'locais' => array_map(static fn (array $l): string => (string)$l['nome'], $locais[$id] ?? []),
                'plans' => array_map(static fn (array $p): array => ['implantacao' => (string)$p['implantacao'], 'backup' => (string)$p['backup'], 'rollback' => (string)$p['rollback'], 'task_impl' => (string)$p['task_impl'], 'task_bkp' => (string)$p['task_bkp'], 'task_roll' => (string)$p['task_roll']], $plans[$id] ?? []),
                'attempts' => array_map(static fn (array $a): array => [
                    'attempt_no' => (int)$a['attempt_no'],
                    'patch_status' => $a['patch_status'],
                    'reagendando' => (int)$a['reagendando'] === 1,
                    'registered_at' => $a['registered_at'],
                    'endpoints' => array_map(static fn (array $e): array => ['url' => (string)$e['url'], 'start_at' => $e['start_at'], 'start_date' => $e['start_date']], $endpoints[(int)$a['id']] ?? []),
                ], $attempts[$id] ?? []),
                'rollback' => $rollbacks[$id] ?? null,
            ];
        }
        return $cards;
    }

    private const MAIN_FIELDS = ['solicitante', 'tipo', 'versao', 'versao_anterior', 'descricao', 'link_planilha', 'sugestao_at', 'service_status',
        'sd_inicio', 'sd_fim', 'chg_numero', 'chg_status', 'tecnico_id', 'tecnico_nome_legado', 'stage_manual', 'stage_manual_auto', 'concluida_em'];

    private function mainParams(array $card): array {
        $params = [];
        foreach (self::MAIN_FIELDS as $field) {
            $params[':' . $field] = $card[$field] ?? null;
        }
        $params[':concluida'] = $card['concluida'] ? 1 : 0;
        $params[':row_version'] = (int)$card['row_version'];
        $params[':updated_at'] = (string)$card['updated_at'];
        return $params;
    }

    public function insertCard(array $card, string $actor): int {
        $columns = array_merge(self::MAIN_FIELDS, ['concluida', 'row_version', 'updated_at', 'legacy_id', 'created_at', 'created_by', 'updated_by']);
        $stmt = $this->pdo->prepare('INSERT INTO portal_chg_updates (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $stmt->execute($this->mainParams($card) + [':legacy_id' => $card['legacy_id'] ?? null, ':created_at' => (string)$card['created_at'], ':created_by' => $actor, ':updated_by' => $actor]);
        $id = (int)$this->pdo->lastInsertId();
        $this->replaceParts($id, $card);
        if ($card['rollback'] !== null) {
            $this->insertRollback($id, $card['rollback'], $actor);
        }
        return $id;
    }

    public function saveCard(array $card, array $before, string $actor): void {
        $sets = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", array_merge(self::MAIN_FIELDS, ['concluida', 'row_version', 'updated_at'])));
        $stmt = $this->pdo->prepare("UPDATE portal_chg_updates SET {$sets}, updated_by = :updated_by, archived_at = :archived_at, archived_by = :archived_by WHERE id = :id");
        $archived = $card['archived_at'] ?? null;
        $stmt->execute($this->mainParams($card) + [':updated_by' => $actor, ':archived_at' => $archived, ':archived_by' => $archived === null ? null : $actor, ':id' => (int)$card['id']]);
        $this->replaceParts((int)$card['id'], $card);
        $old = $before['rollback'];
        $new = $card['rollback'];
        if ($old !== null && ($new === null || ($new['id'] ?? null) !== $old['id'])) {
            $this->pdo->prepare('UPDATE portal_chg_rollbacks SET removed_at = :at, removed_by = :actor WHERE id = :id')
                ->execute([':at' => substr((string)$card['updated_at'], 0, 19), ':actor' => $actor, ':id' => (int)$old['id']]);
        }
        if ($new !== null && isset($new['id'])) {
            $this->pdo->prepare('UPDATE portal_chg_rollbacks SET url = :url, start_at = :start_at, status = :status, plan_position = :plan WHERE id = :id')
                ->execute([':url' => $new['url'], ':start_at' => $new['start_at'], ':status' => $new['status'], ':plan' => (int)$new['plan_position'], ':id' => (int)$new['id']]);
        } elseif ($new !== null) {
            $this->insertRollback((int)$card['id'], $new, $actor);
        }
    }

    private function insertRollback(int $updateId, array $rb, string $actor): void {
        $this->pdo->prepare('INSERT INTO portal_chg_rollbacks (update_id, url, start_at, started_at, status, plan_position, created_by)
                             VALUES (:update_id, :url, :start_at, :started_at, :status, :plan, :actor)')
            ->execute([':update_id' => $updateId, ':url' => $rb['url'], ':start_at' => $rb['start_at'], ':started_at' => $rb['started_at'], ':status' => $rb['status'], ':plan' => (int)$rb['plan_position'], ':actor' => $actor]);
    }

    /** Partes do card: apaga e grava de novo (o card nunca e apagado; D-CHG-20). */
    private function replaceParts(int $id, array $card): void {
        $attemptIds = $this->pdo->prepare('SELECT id FROM portal_chg_attempts WHERE update_id = :id');
        $attemptIds->execute([':id' => $id]);
        $old = array_map('intval', $attemptIds->fetchAll(PDO::FETCH_COLUMN));
        if ($old !== []) {
            $this->pdo->exec('DELETE FROM portal_chg_endpoints WHERE attempt_id IN (' . implode(',', $old) . ')');
        }
        foreach (['portal_chg_attempts', 'portal_chg_plans', 'portal_chg_update_locais', 'portal_chg_update_carteiras'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE update_id = :id")->execute([':id' => $id]);
        }
        $insert = $this->pdo->prepare('INSERT INTO portal_chg_update_carteiras (update_id, position, codigo, nome, pas) VALUES (:id, :pos, :codigo, :nome, :pas)');
        foreach (array_values($card['carteiras']) as $pos => $c) {
            $insert->execute([':id' => $id, ':pos' => $pos, ':codigo' => $c['codigo'], ':nome' => $c['nome'], ':pas' => $c['pas']]);
        }
        $insert = $this->pdo->prepare('INSERT INTO portal_chg_update_locais (update_id, position, nome) VALUES (:id, :pos, :nome)');
        foreach (array_values($card['locais']) as $pos => $nome) {
            $insert->execute([':id' => $id, ':pos' => $pos, ':nome' => $nome]);
        }
        $insert = $this->pdo->prepare('INSERT INTO portal_chg_plans (update_id, position, implantacao, backup, rollback, task_impl, task_bkp, task_roll)
                                       VALUES (:id, :pos, :implantacao, :backup, :rollback, :task_impl, :task_bkp, :task_roll)');
        foreach (array_values($card['plans']) as $pos => $p) {
            $insert->execute([':id' => $id, ':pos' => $pos, ':implantacao' => $p['implantacao'], ':backup' => $p['backup'], ':rollback' => $p['rollback'], ':task_impl' => $p['task_impl'], ':task_bkp' => $p['task_bkp'], ':task_roll' => $p['task_roll']]);
        }
        $attempt = $this->pdo->prepare('INSERT INTO portal_chg_attempts (update_id, attempt_no, patch_status, reagendando, registered_at) VALUES (:id, :no, :status, :reag, :reg)');
        $endpoint = $this->pdo->prepare('INSERT INTO portal_chg_endpoints (attempt_id, position, url, start_at, start_date) VALUES (:attempt, :pos, :url, :start_at, :start_date)');
        foreach ($card['attempts'] as $a) {
            $attempt->execute([':id' => $id, ':no' => (int)$a['attempt_no'], ':status' => $a['patch_status'], ':reag' => $a['reagendando'] ? 1 : 0, ':reg' => $a['registered_at']]);
            $attemptId = (int)$this->pdo->lastInsertId();
            foreach (array_values($a['endpoints']) as $pos => $e) {
                $endpoint->execute([':attempt' => $attemptId, ':pos' => $pos, ':url' => $e['url'], ':start_at' => $e['start_at'], ':start_date' => $e['start_date']]);
            }
        }
    }

    public function activeEmployees(): array {
        $list = [];
        foreach ($this->pdo->query('SELECT id, nome FROM funcionarios WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $list[(int)$row['id']] = (string)$row['nome'];
        }
        return $list;
    }

    public function catalogs(): array {
        return [
            'carteiras' => array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'codigo' => (string)$r['codigo'], 'nome' => (string)$r['nome'], 'ativo' => (int)$r['ativo'] === 1],
                $this->pdo->query('SELECT id, codigo, nome, ativo FROM portal_chg_carteiras ORDER BY CAST(codigo AS UNSIGNED), codigo')->fetchAll(PDO::FETCH_ASSOC)),
            'locais' => array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'nome' => (string)$r['nome'], 'ativo' => (int)$r['ativo'] === 1],
                $this->pdo->query('SELECT id, nome, ativo FROM portal_chg_locais ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    public function saveCatalog(string $kind, array $row, string $actor): int {
        $table = $kind === 'carteira' ? 'portal_chg_carteiras' : 'portal_chg_locais';
        $fields = $kind === 'carteira' ? ['codigo', 'nome', 'ativo'] : ['nome', 'ativo'];
        $params = [':ativo' => $row['ativo'] ? 1 : 0, ':nome' => $row['nome'], ':actor' => $actor] + ($kind === 'carteira' ? [':codigo' => $row['codigo']] : []);
        if (($row['id'] ?? null) === null) {
            $this->pdo->prepare("INSERT INTO {$table} (" . implode(', ', $fields) . ', created_by, updated_by) VALUES (:' . implode(', :', $fields) . ', :actor, :actor2)')
                ->execute($params + [':actor2' => $actor]);
            return (int)$this->pdo->lastInsertId();
        }
        $sets = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", $fields));
        $stmt = $this->pdo->prepare("UPDATE {$table} SET {$sets}, updated_by = :actor WHERE id = :id");
        $stmt->execute($params + [':id' => (int)$row['id']]);
        if ($stmt->rowCount() === 0) {
            $exists = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
            $exists->execute([':id' => (int)$row['id']]);
            if ($exists->fetchColumn() === false) {
                throw new InvalidArgumentException('Item do catálogo inexistente.');
            }
        }
        return (int)$row['id'];
    }

    public function inTools(int $employeeId): bool {
        return (new EmployeeGroups($this->pdo))->inGroup($employeeId);
    }

    public function begin(): void {
        $this->pdo->beginTransaction();
    }

    public function commit(): void {
        $this->pdo->commit();
    }

    public function rollBack(): void {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool {
        return $this->pdo->inTransaction();
    }
}
