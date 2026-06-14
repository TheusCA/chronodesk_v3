<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/OperationalService.php';

final class CriticalIncidentService {
    public const MAX_IMPORT_ROWS = 500;
    public const MAX_IMPORT_COLUMNS = 26;
    public const MAX_IMPORT_CELL_CHARS = 4000;
    public const MAX_IMPORT_PAYLOAD_BYTES = 2097152;
    public const ALLOWED_IMPORT_HEADERS = [
        'ticket_number', 'source', 'title', 'summary', 'severity', 'status',
        'opened_at', 'war_room_started_at', 'mitigated_at', 'resolved_at',
        'impact', 'affected_users', 'affected_services', 'responsible_area',
        'owner_name', 'owner_login', 'involved_teams', 'root_cause',
        'resolution', 'workaround', 'actions_taken', 'next_steps',
        'meeting_url', 'participants', 'notes',
    ];
    public const REQUIRED_IMPORT_HEADERS = [
        'ticket_number', 'source', 'title', 'severity', 'status', 'opened_at',
    ];

    private const SOURCES = ['servicenow', 'jira', 'teams', 'manual', 'other'];
    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    private const STATUSES = [
        'open', 'in_progress', 'war_room', 'mitigated', 'resolved', 'cancelled',
    ];

    private const LIST_COLUMNS = '
        id, ticket_number, source, title, severity, status, opened_at,
        war_room_started_at, mitigated_at, resolved_at, responsible_area,
        owner_name, involved_teams, updated_at';

    private const DETAIL_COLUMNS = '
        id, ticket_number, source, title, summary, severity, status, opened_at,
        war_room_started_at, mitigated_at, resolved_at, impact, affected_users,
        affected_services, responsible_area, owner_name, owner_login,
        involved_teams, root_cause, resolution, workaround, actions_taken,
        next_steps, meeting_url, participants, notes, created_by, created_at,
        updated_by, updated_at';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? get_db_connection();
    }

    public function list(array $filters): array {
        [$where, $params, $period] = $this->filterSql($filters);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::LIST_COLUMNS . '
             FROM portal_critical_incidents
             ' . $where . '
             ORDER BY
                FIELD(severity, "critical", "high", "medium", "low"),
                FIELD(status, "war_room", "open", "in_progress", "mitigated", "resolved", "cancelled"),
                opened_at DESC, id DESC
             LIMIT 500'
        );
        $stmt->execute($params);

        return [
            'period' => $period,
            'items' => $stmt->fetchAll(),
            'summary' => $this->summary($where, $params),
        ];
    }

    public function get(int $id): array {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::DETAIL_COLUMNS . '
             FROM portal_critical_incidents
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $this->positiveInt($id)]);
        $item = $stmt->fetch();
        if (!$item) {
            throw new DomainException('Chamado critico nao encontrado.');
        }
        return $item;
    }

    public function create(array $data, string $actor): int {
        return $this->atomic(fn(): int => $this->createUnsafe($data, $actor));
    }

    public function update(int $id, array $data, string $actor): void {
        $record = $this->normalizeRecord($data);
        $id = $this->positiveInt($id);
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE portal_critical_incidents
                 SET ticket_number = :ticket_number,
                     source = :source,
                     title = :title,
                     summary = :summary,
                     severity = :severity,
                     status = :status,
                     opened_at = :opened_at,
                     war_room_started_at = :war_room_started_at,
                     mitigated_at = :mitigated_at,
                     resolved_at = :resolved_at,
                     impact = :impact,
                     affected_users = :affected_users,
                     affected_services = :affected_services,
                     responsible_area = :responsible_area,
                     owner_name = :owner_name,
                     owner_login = :owner_login,
                     involved_teams = :involved_teams,
                     root_cause = :root_cause,
                     resolution = :resolution,
                     workaround = :workaround,
                     actions_taken = :actions_taken,
                     next_steps = :next_steps,
                     meeting_url = :meeting_url,
                     participants = :participants,
                     notes = :notes,
                     updated_by = :updated_by
                 WHERE id = :id'
            );
            $stmt->execute($record + [
                ':updated_by' => $this->requiredText($actor, 100, 'Usuario'),
                ':id' => $id,
            ]);
        } catch (PDOException $error) {
            $this->translateDuplicate($error);
            throw $error;
        }
        if ($stmt->rowCount() === 0) {
            $this->get($id);
        }
    }

    public function changeStatus(int $id, string $status, string $actor): void {
        $id = $this->positiveInt($id);
        $status = $this->enum($status, self::STATUSES, 'Status');
        $sets = ['status = :status', 'updated_by = :updated_by'];
        if ($status === 'war_room') {
            $sets[] = 'war_room_started_at = COALESCE(war_room_started_at, CURRENT_TIMESTAMP)';
        } elseif ($status === 'mitigated') {
            $sets[] = 'mitigated_at = COALESCE(mitigated_at, CURRENT_TIMESTAMP)';
        } elseif ($status === 'resolved') {
            $sets[] = 'mitigated_at = COALESCE(mitigated_at, CURRENT_TIMESTAMP)';
            $sets[] = 'resolved_at = COALESCE(resolved_at, CURRENT_TIMESTAMP)';
        }
        $stmt = $this->pdo->prepare(
            'UPDATE portal_critical_incidents
             SET ' . implode(', ', $sets) . '
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':updated_by' => $this->requiredText($actor, 100, 'Usuario'),
            ':id' => $id,
        ]);
        if ($stmt->rowCount() !== 1) {
            $this->get($id);
        }
    }

    public function previewImport(array $rows): array {
        self::assertImportRowsShape($rows);
        $result = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $errors = [];
            $record = null;
            try {
                $record = $this->normalizeRecord($row);
                $duplicateKey = $record[':source'] . ':' . strtolower($record[':ticket_number']);
                if (isset($seen[$duplicateKey])) {
                    $errors[] = 'Chamado duplicado no arquivo.';
                } else {
                    $seen[$duplicateKey] = true;
                }
                if ($this->ticketExists($record[':source'], $record[':ticket_number'])) {
                    $errors[] = 'Chamado ja cadastrado.';
                }
            } catch (InvalidArgumentException | DomainException $error) {
                $errors[] = $error->getMessage();
            }
            $result[] = [
                'line' => $index + 2,
                'ticket_number' => is_array($row) && is_scalar($row['ticket_number'] ?? null)
                    ? (string)$row['ticket_number']
                    : '',
                'title' => is_array($row) && is_scalar($row['title'] ?? null)
                    ? (string)$row['title']
                    : '',
                'severity' => is_array($row) && is_scalar($row['severity'] ?? null)
                    ? (string)$row['severity']
                    : '',
                'status' => is_array($row) && is_scalar($row['status'] ?? null)
                    ? (string)$row['status']
                    : '',
                'valid' => count($errors) === 0,
                'errors' => $errors,
                '_record' => count($errors) === 0 ? $record : null,
            ];
        }

        return [
            'rows' => array_map(static function (array $row): array {
                unset($row['_record']);
                return $row;
            }, $result),
            'valid_count' => count(array_filter($result, static fn(array $row): bool => $row['valid'])),
            'invalid_count' => count(array_filter($result, static fn(array $row): bool => !$row['valid'])),
        ];
    }

    public function confirmImport(array $rows, string $actor): int {
        $preview = $this->previewImport($rows);
        if ($preview['invalid_count'] > 0 || $preview['valid_count'] === 0) {
            throw new InvalidArgumentException('A importacao possui linhas invalidas.');
        }
        return $this->atomic(function () use ($rows, $actor): int {
            foreach ($rows as $row) {
                $this->createUnsafe($row, $actor);
            }
            return count($rows);
        });
    }

    public function exportRows(array $filters): array {
        [$where, $params] = $this->filterSql($filters);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::DETAIL_COLUMNS . '
             FROM portal_critical_incidents
             ' . $where . '
             ORDER BY opened_at DESC, id DESC
             LIMIT 500'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function assertImportRowsShape(array $rows): void {
        if ($rows === [] || count($rows) > self::MAX_IMPORT_ROWS || !array_is_list($rows)) {
            throw new InvalidArgumentException('Quantidade ou estrutura de linhas invalida.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('Cada linha deve ser um objeto simples.');
            }
            if (count($row) > self::MAX_IMPORT_COLUMNS) {
                throw new InvalidArgumentException('A importacao excede o limite de colunas.');
            }
            foreach (self::REQUIRED_IMPORT_HEADERS as $requiredHeader) {
                if (!array_key_exists($requiredHeader, $row)) {
                    throw new InvalidArgumentException('A importacao nao possui todas as colunas obrigatorias.');
                }
            }
            foreach ($row as $key => $value) {
                if (
                    !is_string($key)
                    || !in_array($key, self::ALLOWED_IMPORT_HEADERS, true)
                ) {
                    throw new InvalidArgumentException('Cabecalho de importacao nao permitido.');
                }
                if (!is_scalar($value) && $value !== null) {
                    throw new InvalidArgumentException('A importacao contem estrutura aninhada.');
                }
                if (self::stringLength((string)$value) > self::MAX_IMPORT_CELL_CHARS) {
                    throw new InvalidArgumentException('A importacao contem celula acima do limite.');
                }
            }
        }
    }

    private function createUnsafe(array $data, string $actor): int {
        $record = $this->normalizeRecord($data);
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_critical_incidents
                    (ticket_number, source, title, summary, severity, status,
                     opened_at, war_room_started_at, mitigated_at, resolved_at,
                     impact, affected_users, affected_services, responsible_area,
                     owner_name, owner_login, involved_teams, root_cause, resolution,
                     workaround, actions_taken, next_steps, meeting_url, participants,
                     notes, created_by, updated_by)
                 VALUES
                    (:ticket_number, :source, :title, :summary, :severity, :status,
                     :opened_at, :war_room_started_at, :mitigated_at, :resolved_at,
                     :impact, :affected_users, :affected_services, :responsible_area,
                     :owner_name, :owner_login, :involved_teams, :root_cause, :resolution,
                     :workaround, :actions_taken, :next_steps, :meeting_url, :participants,
                     :notes, :created_by, :updated_by)'
            );
            $actor = $this->requiredText($actor, 100, 'Usuario');
            $stmt->execute($record + [
                ':created_by' => $actor,
                ':updated_by' => $actor,
            ]);
        } catch (PDOException $error) {
            $this->translateDuplicate($error);
            throw $error;
        }
        return (int)$this->pdo->lastInsertId();
    }

    private function normalizeRecord(array $data): array {
        $record = [
            ':ticket_number' => $this->requiredText($data['ticket_number'] ?? null, 100, 'Numero do chamado'),
            ':source' => $this->enum($data['source'] ?? 'manual', self::SOURCES, 'Origem'),
            ':title' => $this->requiredText($data['title'] ?? null, 180, 'Titulo'),
            ':summary' => $this->nullableText($data['summary'] ?? null, 4000),
            ':severity' => $this->enum($data['severity'] ?? null, self::SEVERITIES, 'Criticidade'),
            ':status' => $this->enum($data['status'] ?? 'open', self::STATUSES, 'Status'),
            ':opened_at' => $this->dateTime($data['opened_at'] ?? null, 'Abertura'),
            ':war_room_started_at' => $this->dateTime($data['war_room_started_at'] ?? null, 'Inicio da war room', true),
            ':mitigated_at' => $this->dateTime($data['mitigated_at'] ?? null, 'Mitigacao', true),
            ':resolved_at' => $this->dateTime($data['resolved_at'] ?? null, 'Resolucao', true),
            ':impact' => $this->nullableText($data['impact'] ?? null, 4000),
            ':affected_users' => $this->nullableNonNegativeInt($data['affected_users'] ?? null),
            ':affected_services' => $this->nullableText($data['affected_services'] ?? null, 2000),
            ':responsible_area' => $this->nullableText($data['responsible_area'] ?? null, 120),
            ':owner_name' => $this->nullableText($data['owner_name'] ?? null, 160),
            ':owner_login' => $this->ownerLogin($data['owner_login'] ?? null),
            ':involved_teams' => $this->nullableText($data['involved_teams'] ?? null, 500),
            ':root_cause' => $this->nullableText($data['root_cause'] ?? null, 4000),
            ':resolution' => $this->nullableText($data['resolution'] ?? null, 4000),
            ':workaround' => $this->nullableText($data['workaround'] ?? null, 4000),
            ':actions_taken' => $this->nullableText($data['actions_taken'] ?? null, 12000),
            ':next_steps' => $this->nullableText($data['next_steps'] ?? null, 4000),
            ':meeting_url' => $this->meetingUrl($data['meeting_url'] ?? null),
            ':participants' => $this->nullableText($data['participants'] ?? null, 4000),
            ':notes' => $this->nullableText($data['notes'] ?? null, 12000),
        ];

        $opened = new DateTimeImmutable($record[':opened_at']);
        foreach ([
            ':war_room_started_at' => 'Inicio da war room',
            ':mitigated_at' => 'Mitigacao',
            ':resolved_at' => 'Resolucao',
        ] as $key => $label) {
            if ($record[$key] !== null && new DateTimeImmutable($record[$key]) < $opened) {
                throw new InvalidArgumentException("{$label} nao pode ser anterior a abertura.");
            }
        }
        if (
            $record[':mitigated_at'] !== null
            && $record[':resolved_at'] !== null
            && new DateTimeImmutable($record[':resolved_at']) < new DateTimeImmutable($record[':mitigated_at'])
        ) {
            throw new InvalidArgumentException('Resolucao nao pode ser anterior a mitigacao.');
        }
        if ($record[':status'] === 'resolved' && $record[':resolved_at'] === null) {
            throw new InvalidArgumentException('Chamado resolvido exige data de resolucao.');
        }
        if ($record[':status'] === 'mitigated' && $record[':mitigated_at'] === null) {
            throw new InvalidArgumentException('Chamado mitigado exige data de mitigacao.');
        }
        return $record;
    }

    private function summary(string $where, array $params): array {
        $stmt = $this->pdo->prepare(
            'SELECT
                SUM(status IN ("open", "in_progress", "war_room", "mitigated")) AS open_count,
                SUM(status = "war_room") AS war_room_count,
                SUM(status = "resolved") AS resolved_count,
                ROUND(AVG(CASE WHEN mitigated_at IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, opened_at, mitigated_at) END)) AS avg_mitigation_minutes,
                ROUND(AVG(CASE WHEN resolved_at IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, opened_at, resolved_at) END)) AS avg_resolution_minutes,
                SUM(severity IN ("high", "critical")) AS high_critical_count,
                SUM(root_cause IS NULL OR TRIM(root_cause) = "") AS missing_root_cause_count
             FROM portal_critical_incidents ' . $where
        );
        $stmt->execute($params);
        $summary = $stmt->fetch() ?: [];

        $areaStmt = $this->pdo->prepare(
            'SELECT responsible_area, COUNT(*) AS total
             FROM portal_critical_incidents ' . $where . '
             AND responsible_area IS NOT NULL
             AND TRIM(responsible_area) <> ""
             GROUP BY responsible_area
             ORDER BY total DESC, responsible_area
             LIMIT 5'
        );
        $areaStmt->execute($params);
        $summary['top_areas'] = $areaStmt->fetchAll();
        return $summary;
    }

    private function filterSql(array $filters): array {
        if (!empty($filters['competency'])) {
            $competency = OperationalService::competencyRange((string)$filters['competency']);
            $from = $competency['start'];
            $to = $competency['end'];
        } else {
            $from = $this->date($filters['from'] ?? date('Y-m-01'), 'Data inicial');
            $to = $this->date($filters['to'] ?? date('Y-m-t'), 'Data final');
        }
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        if ($end < $start || (int)$start->diff($end)->format('%a') > 366) {
            throw new InvalidArgumentException('Periodo invalido ou acima de 366 dias.');
        }

        $where = 'WHERE opened_at >= :opened_from AND opened_at < DATE_ADD(:opened_to, INTERVAL 1 DAY)';
        $params = [':opened_from' => $from, ':opened_to' => $to];
        foreach ([
            'status' => [self::STATUSES, 'status'],
            'severity' => [self::SEVERITIES, 'severity'],
            'source' => [self::SOURCES, 'source'],
        ] as $filter => [$allowlist, $column]) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $value = $this->enum($filters[$filter], $allowlist, ucfirst($filter));
                $where .= " AND {$column} = :{$filter}";
                $params[":{$filter}"] = $value;
            }
        }
        foreach ([
            'responsible_area' => 'responsible_area',
            'owner' => 'owner_name',
            'team' => 'involved_teams',
            'ticket_number' => 'ticket_number',
        ] as $filter => $column) {
            $value = $this->nullableText($filters[$filter] ?? null, 120);
            if ($value !== null) {
                $where .= " AND {$column} LIKE :{$filter}";
                $params[":{$filter}"] = '%' . $value . '%';
            }
        }
        $search = $this->nullableText($filters['search'] ?? null, 120);
        if ($search !== null) {
            $where .= ' AND (
                title LIKE :search_title
                OR summary LIKE :search_summary
                OR impact LIKE :search_impact
                OR affected_services LIKE :search_services
                OR root_cause LIKE :search_root_cause
            )';
            $searchValue = '%' . $search . '%';
            $params[':search_title'] = $searchValue;
            $params[':search_summary'] = $searchValue;
            $params[':search_impact'] = $searchValue;
            $params[':search_services'] = $searchValue;
            $params[':search_root_cause'] = $searchValue;
        }
        return [$where, $params, ['from' => $from, 'to' => $to]];
    }

    private function ticketExists(string $source, string $ticketNumber): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM portal_critical_incidents
             WHERE source = :source AND ticket_number = :ticket_number
             LIMIT 1'
        );
        $stmt->execute([':source' => $source, ':ticket_number' => $ticketNumber]);
        return (bool)$stmt->fetchColumn();
    }

    private function translateDuplicate(PDOException $error): void {
        if ((string)$error->getCode() === '23000') {
            throw new DomainException('Ja existe chamado com esta origem e numero.', 0, $error);
        }
    }

    private function atomic(callable $callback) {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function date($value, string $label): string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        return $date->format('Y-m-d');
    }

    private function dateTime($value, string $label, bool $nullable = false): ?string {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/', $value)) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        $normalized = str_replace('T', ' ', trim($value));
        $format = strlen($normalized) === 16 ? '!Y-m-d H:i' : '!Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException("{$label} invalida.");
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function positiveInt($value): int {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new InvalidArgumentException('Identificador invalido.');
        }
        return (int)$result;
    }

    private function nullableNonNegativeInt($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 1000000000],
        ]);
        if ($result === false) {
            throw new InvalidArgumentException('Quantidade de usuarios afetados invalida.');
        }
        return (int)$result;
    }

    private function enum($value, array $allowed, string $label): string {
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$label} invalido.");
        }
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("{$label} invalido.");
        }
        return $value;
    }

    private function ownerLogin($value): ?string {
        $login = $this->nullableText($value, 100);
        if ($login === null) {
            return null;
        }
        if (preg_match('/^[a-zA-Z0-9._@-]+$/', $login) !== 1) {
            throw new InvalidArgumentException('Login do responsavel invalido.');
        }
        return strtolower($login);
    }

    private function meetingUrl($value): ?string {
        $url = $this->nullableText($value, 1000);
        if ($url === null) {
            return null;
        }
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new InvalidArgumentException('Link da sala deve usar HTTPS.');
        }
        return $url;
    }

    private function requiredText($value, int $max, string $label): string {
        $text = $this->nullableText($value, $max);
        if ($text === null) {
            throw new InvalidArgumentException("{$label} e obrigatorio.");
        }
        return $text;
    }

    private function nullableText($value, int $max): ?string {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Texto invalido.');
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (self::stringLength($text) > $max) {
            throw new InvalidArgumentException('Texto excede o limite permitido.');
        }
        return $text;
    }

    private static function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
