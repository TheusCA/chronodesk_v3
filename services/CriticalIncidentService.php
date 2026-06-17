<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/OperationalService.php';

final class CriticalIncidentService {
    public const MAX_IMPORT_ROWS = 500;
    public const MAX_IMPORT_COLUMNS = 39;
    public const MAX_IMPORT_CELL_CHARS = 4000;
    public const MAX_IMPORT_PAYLOAD_BYTES = 2097152;
    public const ALLOWED_IMPORT_HEADERS = [
        'incident_number', 'room_date', 'incident_opened_at',
        'operation_reported_at', 'room_opened_at', 'normalized_at',
        'room_description', 'room_finalization_description',
        'room_opening_duration_minutes', 'room_duration_minutes',
        'sector', 'sdk_activity',
        'ticket_number', 'source', 'title', 'summary', 'severity', 'status',
        'opened_at', 'war_room_started_at', 'mitigated_at', 'resolved_at',
        'impact', 'affected_users', 'affected_services', 'responsible_area',
        'owner_name', 'owner_login', 'involved_teams', 'root_cause',
        'resolution', 'workaround', 'actions_taken', 'next_steps',
        'meeting_url', 'participants', 'notes',
        'incidente', 'data da sala', 'hora de abertura incidente',
        'hora report da operação', 'hora report da operacao',
        'hora abertura sala', 'hora de normalização', 'hora de normalizacao',
        'descrição da sala', 'descricao da sala',
        'descrição da finalização da sala', 'descricao da finalizacao da sala',
        'tempo de abertura da sala', 'tempo de sala', 'setor',
        'carteira - cc', 'area responsavel',
        'usuarios afetados', 'link da sala',
        'observação', 'observacao', 'atividade sdk',
    ];
    public const EXPORT_COLUMNS = [
        'INCIDENTE' => 'incident_number',
        'Data da Sala' => 'room_date',
        'Hora de abertura Incidente' => 'incident_opened_at',
        'Hora report da operação' => 'operation_reported_at',
        'Hora abertura sala' => 'room_opened_at',
        'Hora de normalização' => 'normalized_at',
        'Descrição da sala' => 'room_description',
        'Descrição da finalização da sala' => 'room_finalization_description',
        'Tempo de abertura da sala' => 'room_opening_duration_minutes',
        'Tempo de Sala' => 'room_duration_minutes',
        'Carteira - CC' => 'sector',
        'Área responsável' => 'responsible_area',
        'Usuários afetados' => 'affected_users',
        'Link da sala' => 'meeting_url',
        'Observação' => 'notes',
        'Atividade SDK' => 'sdk_activity',
    ];
    public const REQUIRED_IMPORT_HEADERS = [
        'incident_number', 'room_date',
    ];
    private const LEGACY_REQUIRED_IMPORT_HEADERS = [
        'ticket_number', 'source', 'title', 'severity', 'status', 'opened_at',
    ];
    private const IMPORT_HEADER_ALIASES = [
        'incidente' => 'incident_number',
        'data da sala' => 'room_date',
        'hora de abertura incidente' => 'incident_opened_at',
        'hora report da operação' => 'operation_reported_at',
        'hora report da operacao' => 'operation_reported_at',
        'hora abertura sala' => 'room_opened_at',
        'hora de normalização' => 'normalized_at',
        'hora de normalizacao' => 'normalized_at',
        'descrição da sala' => 'room_description',
        'descricao da sala' => 'room_description',
        'descrição da finalização da sala' => 'room_finalization_description',
        'descricao da finalizacao da sala' => 'room_finalization_description',
        'tempo de abertura da sala' => 'room_opening_duration_minutes',
        'tempo de sala' => 'room_duration_minutes',
        'setor' => 'sector',
        'carteira - cc' => 'sector',
        'area responsavel' => 'responsible_area',
        'usuarios afetados' => 'affected_users',
        'link da sala' => 'meeting_url',
        'observação' => 'notes',
        'observacao' => 'notes',
        'atividade sdk' => 'sdk_activity',
    ];

    private const SOURCES = ['servicenow', 'jira', 'teams', 'manual', 'other'];
    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    private const STATUSES = [
        'open', 'in_progress', 'war_room', 'mitigated', 'resolved', 'cancelled',
    ];

    private const LIST_COLUMNS = '
        id, incident_number, room_date, incident_opened_at,
        operation_reported_at, room_opened_at, normalized_at,
        room_opening_duration_minutes, room_duration_minutes, sector,
        room_description, sdk_activity,
        ticket_number, source, title, severity, status, opened_at,
        war_room_started_at, mitigated_at, resolved_at, responsible_area,
        owner_name, involved_teams, updated_at';

    private const DETAIL_COLUMNS = '
        id, incident_number, room_date, incident_opened_at,
        operation_reported_at, room_opened_at, normalized_at,
        room_description, room_finalization_description,
        room_opening_duration_minutes, room_duration_minutes, sector,
        sdk_activity,
        ticket_number, source, title, summary, severity, status, opened_at,
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
                 SET incident_number = :incident_number,
                     room_date = :room_date,
                     incident_opened_at = :incident_opened_at,
                     operation_reported_at = :operation_reported_at,
                     room_opened_at = :room_opened_at,
                     normalized_at = :normalized_at,
                     room_description = :room_description,
                     room_finalization_description = :room_finalization_description,
                     room_opening_duration_minutes = :room_opening_duration_minutes,
                     room_duration_minutes = :room_duration_minutes,
                     sector = :sector,
                     sdk_activity = :sdk_activity,
                     ticket_number = :ticket_number,
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
            $sets[] = 'room_opened_at = COALESCE(room_opened_at, war_room_started_at, CURRENT_TIMESTAMP)';
            $sets[] = 'room_date = COALESCE(room_date, CURRENT_DATE)';
        } elseif ($status === 'mitigated') {
            $sets[] = 'mitigated_at = COALESCE(mitigated_at, CURRENT_TIMESTAMP)';
            $sets[] = 'normalized_at = COALESCE(normalized_at, mitigated_at, CURRENT_TIMESTAMP)';
            $sets[] = 'room_duration_minutes = COALESCE(
                room_duration_minutes,
                TIMESTAMPDIFF(MINUTE, room_opened_at, COALESCE(normalized_at, CURRENT_TIMESTAMP))
            )';
        } elseif ($status === 'resolved') {
            $sets[] = 'mitigated_at = COALESCE(mitigated_at, CURRENT_TIMESTAMP)';
            $sets[] = 'resolved_at = COALESCE(resolved_at, CURRENT_TIMESTAMP)';
            $sets[] = 'normalized_at = COALESCE(normalized_at, mitigated_at, resolved_at, CURRENT_TIMESTAMP)';
            $sets[] = 'room_duration_minutes = COALESCE(
                room_duration_minutes,
                TIMESTAMPDIFF(MINUTE, room_opened_at, COALESCE(normalized_at, CURRENT_TIMESTAMP))
            )';
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
                $row = self::normalizeImportRow($row);
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
                'ticket_number' => is_array($row) && is_scalar($row['incident_number'] ?? $row['ticket_number'] ?? null)
                    ? (string)($row['incident_number'] ?? $row['ticket_number'])
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
        if ($rows === [] || count($rows) > self::MAX_IMPORT_ROWS || !self::isList($rows)) {
            throw new InvalidArgumentException('Quantidade ou estrutura de linhas invalida.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || self::isList($row)) {
                throw new InvalidArgumentException('Cada linha deve ser um objeto simples.');
            }
            if (count($row) > self::MAX_IMPORT_COLUMNS) {
                throw new InvalidArgumentException('A importacao excede o limite de colunas.');
            }
            $canonicalKeys = array_keys(self::normalizeImportRow($row));
            $detectedHeaders = implode(', ', array_map('strval', array_keys($row)));
            $hasOperationalHeaders = count(array_intersect(
                self::REQUIRED_IMPORT_HEADERS,
                $canonicalKeys
            )) === count(self::REQUIRED_IMPORT_HEADERS);
            $hasLegacyHeaders = count(array_intersect(
                self::LEGACY_REQUIRED_IMPORT_HEADERS,
                $canonicalKeys
            )) === count(self::LEGACY_REQUIRED_IMPORT_HEADERS);
            if (!$hasOperationalHeaders && !$hasLegacyHeaders) {
                throw new InvalidArgumentException(
                    'A importacao exige INCIDENTE e Data da Sala, ou o conjunto legado completo. Cabecalhos detectados: ' . $detectedHeaders . '.'
                );
            }
            foreach ($row as $key => $value) {
                $normalizedKey = is_string($key) ? self::canonicalImportKey($key) : $key;
                if (
                    !is_string($key)
                    || !in_array($normalizedKey, self::ALLOWED_IMPORT_HEADERS, true)
                ) {
                    throw new InvalidArgumentException('Cabecalho de importacao nao permitido: ' . (string)$key . '. Cabecalhos detectados: ' . $detectedHeaders . '.');
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
        $data = self::normalizeImportRow($data);
        $record = $this->normalizeRecord($data);
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_critical_incidents
                    (incident_number, room_date, incident_opened_at,
                     operation_reported_at, room_opened_at, normalized_at,
                     room_description, room_finalization_description,
                     room_opening_duration_minutes, room_duration_minutes,
                     sector, sdk_activity,
                     ticket_number, source, title, summary, severity, status,
                     opened_at, war_room_started_at, mitigated_at, resolved_at,
                     impact, affected_users, affected_services, responsible_area,
                     owner_name, owner_login, involved_teams, root_cause, resolution,
                     workaround, actions_taken, next_steps, meeting_url, participants,
                     notes, created_by, updated_by)
                 VALUES
                    (:incident_number, :room_date, :incident_opened_at,
                     :operation_reported_at, :room_opened_at, :normalized_at,
                     :room_description, :room_finalization_description,
                     :room_opening_duration_minutes, :room_duration_minutes,
                     :sector, :sdk_activity,
                     :ticket_number, :source, :title, :summary, :severity, :status,
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

    private static function normalizeImportRow(array $row): array {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalizedKey = self::canonicalImportKey((string)$key);
            $canonical = self::IMPORT_HEADER_ALIASES[$normalizedKey] ?? $normalizedKey;
            if (array_key_exists($canonical, $normalized) && $normalized[$canonical] !== '' && $value !== '') {
                throw new InvalidArgumentException('A importacao possui cabecalhos equivalentes duplicados.');
            }
            $normalized[$canonical] = $value;
        }
        return $normalized;
    }

    private static function canonicalImportKey(string $key): string {
        $key = preg_replace('/^\xEF\xBB\xBF/', '', trim($key));
        $key = strtr($key, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c',
        ]);
        return strtolower($key);
    }

    private static function isList(array $items): bool {
        $expected = 0;
        foreach ($items as $key => $_value) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private function normalizeRecord(array $data): array {
        $incidentNumber = $this->requiredText(
            $data['incident_number'] ?? $data['ticket_number'] ?? null,
            100,
            'INCIDENTE'
        );
        $roomDate = $this->date(
            $data['room_date']
                ?? (is_string($data['opened_at'] ?? null) ? substr($data['opened_at'], 0, 10) : null)
                ?? date('Y-m-d'),
            'Data da sala'
        );
        $incidentOpenedAt = $this->dateTime(
            $data['incident_opened_at'] ?? $data['opened_at'] ?? null,
            'Hora de abertura do incidente',
            true,
            $roomDate
        );
        $operationReportedAt = $this->dateTime(
            $data['operation_reported_at'] ?? null,
            'Hora do report da operacao',
            true,
            $roomDate
        );
        $roomOpenedAt = $this->dateTime(
            $data['room_opened_at'] ?? $data['war_room_started_at'] ?? null,
            'Hora de abertura da sala',
            true,
            $roomDate
        );
        $normalizedAt = $this->dateTime(
            $data['normalized_at'] ?? $data['mitigated_at'] ?? $data['resolved_at'] ?? null,
            'Hora de normalizacao',
            true,
            $roomDate
        );
        $roomDescription = $this->nullableText(
            $data['room_description'] ?? $data['summary'] ?? $data['title'] ?? null,
            4000
        );
        $roomFinalization = $this->nullableText(
            $data['room_finalization_description'] ?? $data['resolution'] ?? null,
            4000
        );
        $openingDuration = $this->durationMinutes(
            $data['room_opening_duration_minutes'] ?? null,
            $incidentOpenedAt,
            $roomOpenedAt,
            'Tempo de abertura da sala'
        );
        $roomDuration = $this->durationMinutes(
            $data['room_duration_minutes'] ?? null,
            $roomOpenedAt,
            $normalizedAt,
            'Tempo de sala'
        );
        $record = [
            ':incident_number' => $incidentNumber,
            ':room_date' => $roomDate,
            ':incident_opened_at' => $incidentOpenedAt,
            ':operation_reported_at' => $operationReportedAt,
            ':room_opened_at' => $roomOpenedAt,
            ':normalized_at' => $normalizedAt,
            ':room_description' => $roomDescription,
            ':room_finalization_description' => $roomFinalization,
            ':room_opening_duration_minutes' => $openingDuration,
            ':room_duration_minutes' => $roomDuration,
            ':sector' => $this->nullableText($data['sector'] ?? null, 160),
            ':sdk_activity' => $this->nullableText($data['sdk_activity'] ?? $data['actions_taken'] ?? null, 12000),
            ':ticket_number' => $incidentNumber,
            ':source' => $this->enum($data['source'] ?? 'manual', self::SOURCES, 'Origem'),
            ':title' => $this->requiredText(
                $data['title'] ?? $roomDescription ?? $incidentNumber,
                180,
                'Titulo'
            ),
            ':summary' => $this->nullableText($data['summary'] ?? $roomDescription, 4000),
            ':severity' => $this->enum($data['severity'] ?? 'high', self::SEVERITIES, 'Criticidade'),
            ':status' => $this->enum($data['status'] ?? 'open', self::STATUSES, 'Status'),
            ':opened_at' => $incidentOpenedAt ?? ($roomDate . ' 00:00:00'),
            ':war_room_started_at' => $roomOpenedAt,
            ':mitigated_at' => $normalizedAt,
            ':resolved_at' => $this->dateTime(
                $data['resolved_at']
                    ?? (($data['status'] ?? 'open') === 'resolved' ? $normalizedAt : null),
                'Resolucao',
                true,
                $roomDate
            ),
            ':impact' => $this->nullableText($data['impact'] ?? null, 4000),
            ':affected_users' => $this->nullableNonNegativeInt($data['affected_users'] ?? null),
            ':affected_services' => $this->nullableText($data['affected_services'] ?? null, 2000),
            ':responsible_area' => $this->nullableText($data['responsible_area'] ?? null, 120),
            ':owner_name' => $this->nullableText($data['owner_name'] ?? null, 160),
            ':owner_login' => $this->ownerLogin($data['owner_login'] ?? null),
            ':involved_teams' => $this->nullableText($data['involved_teams'] ?? null, 500),
            ':root_cause' => $this->nullableText($data['root_cause'] ?? null, 4000),
            ':resolution' => $this->nullableText($data['resolution'] ?? $roomFinalization, 4000),
            ':workaround' => $this->nullableText($data['workaround'] ?? null, 4000),
            ':actions_taken' => $this->nullableText($data['actions_taken'] ?? $data['sdk_activity'] ?? null, 12000),
            ':next_steps' => $this->nullableText($data['next_steps'] ?? null, 4000),
            ':meeting_url' => $this->meetingUrl($data['meeting_url'] ?? null),
            ':participants' => $this->nullableText($data['participants'] ?? null, 4000),
            ':notes' => $this->nullableText($data['notes'] ?? $data['observation'] ?? null, 12000),
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
                ROUND(AVG(room_opening_duration_minutes)) AS avg_mitigation_minutes,
                ROUND(AVG(room_duration_minutes)) AS avg_resolution_minutes,
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

        $where = 'WHERE COALESCE(room_date, DATE(opened_at)) BETWEEN :opened_from AND :opened_to';
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
            'ticket_number' => 'incident_number',
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
                incident_number LIKE :search_incident
                OR title LIKE :search_title
                OR summary LIKE :search_summary
                OR impact LIKE :search_impact
                OR affected_services LIKE :search_services
                OR root_cause LIKE :search_root_cause
            )';
            $searchValue = '%' . $search . '%';
            $params[':search_incident'] = $searchValue;
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
        $excelDate = $this->excelDateTime($value);
        if ($excelDate !== null) {
            return $excelDate->format('Y-m-d');
        }
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

    private function dateTime(
        $value,
        string $label,
        bool $nullable = false,
        ?string $baseDate = null
    ): ?string {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        $excelDate = $this->excelDateTime($value, $baseDate);
        if ($excelDate !== null) {
            return $excelDate->format('Y-m-d H:i:s');
        }
        if (
            $baseDate !== null
            && is_string($value)
            && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $value)
        ) {
            $value = $baseDate . ' ' . $value;
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

    private function excelDateTime($value, ?string $baseDate = null): ?DateTimeImmutable {
        if (
            (!is_int($value) && !is_float($value) && !is_string($value))
            || !is_numeric($value)
        ) {
            return null;
        }
        $serial = (float)$value;
        if (!is_finite($serial) || $serial < 0 || $serial > 2958465) {
            return null;
        }
        if ($serial < 1 && $baseDate !== null) {
            $base = DateTimeImmutable::createFromFormat('!Y-m-d', $baseDate);
            if (!$base) {
                return null;
            }
            return $base->modify('+' . (int)round($serial * 86400) . ' seconds');
        }
        if ($serial < 1) {
            return null;
        }
        $base = new DateTimeImmutable('1899-12-30 00:00:00');
        return $base->modify('+' . (int)round($serial * 86400) . ' seconds');
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

    private function durationMinutes($value, ?string $from, ?string $to, string $label): ?int {
        if ($from !== null && $to !== null) {
            $seconds = (new DateTimeImmutable($to))->getTimestamp()
                - (new DateTimeImmutable($from))->getTimestamp();
            if ($seconds < 0) {
                throw new InvalidArgumentException("{$label} nao pode ser negativo.");
            }
            return (int)floor($seconds / 60);
        }
        if ($value !== null && $value !== '') {
            if (is_string($value) && preg_match('/^(\d{1,4}):([0-5]\d)$/', trim($value), $match)) {
                $minutes = ((int)$match[1] * 60) + (int)$match[2];
                if ($minutes > 525600) {
                    throw new InvalidArgumentException("{$label} invalido.");
                }
                return $minutes;
            }
            $result = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 525600],
            ]);
            if ($result === false) {
                throw new InvalidArgumentException("{$label} invalido.");
            }
            return (int)$result;
        }
        return null;
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
