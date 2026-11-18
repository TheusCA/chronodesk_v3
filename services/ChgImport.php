<?php
declare(strict_types=1);

require_once __DIR__ . '/ChgService.php';

/**
 * [9.17.7] Importar do Controle de CHG v5.2 (admin): o JSON de dados v2
 * (items e deleted) e o JSON do catalogo (carteiras e locais).
 *
 * - Previa so com contagens; a confirmacao refaz a previa na mesma transacao
 *   e grava.
 * - Idempotente pelo id legado; o updatedAt mais novo vence (igual = nada muda).
 * - Preserva createdAt, updatedAt e concluidaEm; normaliza como o norm() do
 *   v5.2; casa o tecnico por nome (sem acento e sem caixa) so com funcionario
 *   ativo de nome unico (D-CHG-27).
 * - Esquema estrito (tipo e tamanho de cada campo); campos desconhecidos
 *   descartados e contados (D-CHG-24); card em "deleted" nunca e apagado
 *   (D-CHG-28).
 * - Nenhum valor de card vai para log ou resposta: so contagens (e o nome dos
 *   campos desconhecidos, que e esquema, nao dado).
 */
final class ChgImport {
    public const MAX_BYTES = 2_097_152;
    public const MAX_ITEMS = 2000;
    private const KNOWN = [
        'id', 'solicitante', 'analista', 'descricao', 'carteira', 'carteiras', 'codigo', 'nomeCarteira', 'local', 'locais', 'qtdPas', 'tipo',
        'versao', 'versaoAnterior', 'link', 'sugestao', 'status', 'inicio', 'fim', 'chg', 'chgStatus', 'tecnico', 'planoImplantacao', 'planoBackup',
        'planoRollback', 'taskImplStatus', 'taskBkpStatus', 'taskRollStatus', 'planosExtras', 'endpoints', 'historico', 'patchStatus',
        'reagendandoPatch', 'rollback', 'stageManual', 'stageManualAuto', 'concluida', 'concluidaEm', 'createdAt', 'updatedAt',
    ];
    private const SERVICE = ['Aguardando retorno' => 'aguardando', 'Aprovado' => 'aprovado', 'Cancelado' => 'cancelado'];
    private const CHG = ['Pendente' => 'pendente', 'Aprovada' => 'aprovada', 'Cancelada' => 'cancelada'];
    private const PATCH = ['Sucesso' => 'sucesso', 'Parcial' => 'parcial', 'Erro' => 'erro'];
    private const ROLLBACK = ['Em andamento' => 'em_andamento', 'Sucesso' => 'sucesso', 'Erro' => 'erro'];

    private array $counts = [];

    public function __construct(private ChgStore $store, private DateTimeImmutable $now) {
    }

    /** Sem acento, sem caixa e sem espacos repetidos (para casar o tecnico por nome). */
    public static function fold(string $value): string {
        $map = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'];
        return trim((string)preg_replace('/\s+/u', ' ', strtr(ChgRules::lower($value), $map)));
    }

    private function count(string $key, int $by = 1): void {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    /**
     * A previa (ou, com $write, a gravacao) dos dois arquivos. $data e $catalog
     * ja decodificados (o endpoint limita o tamanho); qualquer um pode faltar.
     */
    public function run(?array $data, ?array $catalog, bool $write, string $actor): array {
        if ($data === null && $catalog === null) {
            throw new InvalidArgumentException('Envie o JSON de dados, o do catálogo ou os dois.');
        }
        $this->counts = [];
        $unknown = [];
        if ($catalog !== null) {
            $this->catalog($catalog, $write, $actor);
        }
        if ($data !== null) {
            $this->cards($data, $write, $actor, $unknown);
        }
        ksort($unknown);
        return ['contagens' => $this->counts + ['novos' => 0, 'atualizados' => 0, 'iguais' => 0], 'campos_descartados' => $unknown];
    }

    private function catalog(array $catalog, bool $write, string $actor): void {
        $carteiras = $catalog['carteiras'] ?? null;
        $locais = $catalog['locais'] ?? null;
        if (!is_array($carteiras) || !array_is_list($carteiras) || !is_array($locais) || !array_is_list($locais) || count($carteiras) > 5000 || count($locais) > 500) {
            throw new InvalidArgumentException('Catálogo inválido: esperado {"carteiras": [...], "locais": [...]}.');
        }
        $current = $this->store->catalogs();
        $codes = array_fill_keys(array_column($current['carteiras'], 'codigo'), true);
        $places = array_fill_keys(array_map([ChgRules::class, 'lower'], array_column($current['locais'], 'nome')), true);
        foreach ($carteiras as $row) {
            $codigo = is_array($row) && is_string($row['codigo'] ?? null) ? trim($row['codigo']) : '';
            $nome = is_array($row) && is_string($row['nome'] ?? null) ? trim($row['nome']) : '';
            if (preg_match('/^[0-9]{1,10}$/', $codigo) !== 1 || $nome === '' || ChgRules::length($nome) > 120) {
                $this->count('carteiras_invalidas');
                continue;
            }
            if (isset($codes[$codigo])) {
                $this->count('carteiras_existentes');
                continue;
            }
            $codes[$codigo] = true;
            $this->count('carteiras_novas');
            if ($write) {
                $this->store->saveCatalog('carteira', ['id' => null, 'codigo' => $codigo, 'nome' => $nome, 'ativo' => true], $actor);
            }
        }
        foreach ($locais as $raw) {
            $nome = is_string($raw) ? trim($raw) : '';
            if ($nome === '' || ChgRules::length($nome) > 60) {
                $this->count('locais_invalidos');
                continue;
            }
            if (isset($places[ChgRules::lower($nome)])) {
                $this->count('locais_existentes');
                continue;
            }
            $places[ChgRules::lower($nome)] = true;
            $this->count('locais_novos');
            if ($write) {
                $this->store->saveCatalog('local', ['id' => null, 'nome' => $nome, 'ativo' => true], $actor);
            }
        }
    }

    private function cards(array $data, bool $write, string $actor, array &$unknown): void {
        $items = $data['items'] ?? null;
        $deleted = $data['deleted'] ?? [];
        if (!is_array($items) || !array_is_list($items) || !is_array($deleted) || !array_is_list($deleted)) {
            throw new InvalidArgumentException('Dados inválidos: esperado o JSON v2 do Controle de CHG ({"items": [...], "deleted": [...]}).');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Arquivo com mais de ' . self::MAX_ITEMS . ' cards.');
        }
        $deletedIds = array_fill_keys(array_filter($deleted, static fn ($id): bool => is_string($id) && $id !== ''), true);
        $existing = $this->store->legacyIndex();
        foreach (array_keys($deletedIds) as $legacyId) {
            if (isset($existing[$legacyId])) {
                $this->count('em_deleted_e_no_portal');
            }
        }
        $employees = $this->employeeIndex();
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $item['id']) !== 1) {
                $this->count('invalidos');
                continue;
            }
            $legacyId = $item['id'];
            if (isset($seen[$legacyId])) {
                $this->count('repetidos_no_arquivo');
                continue;
            }
            $seen[$legacyId] = true;
            if (isset($deletedIds[$legacyId])) {
                $this->count('ignorados_em_deleted');
                continue;
            }
            foreach (array_diff(array_keys($item), self::KNOWN) as $field) {
                $unknown[(string)$field] = ($unknown[(string)$field] ?? 0) + 1;
            }
            try {
                $card = $this->card($item, $employees);
            } catch (InvalidArgumentException) {
                $this->count('invalidos');
                continue;
            }
            $current = $existing[$legacyId] ?? null;
            if ($current !== null) {
                if ($card['updated_at'] < $current['updated_at']) {
                    $this->count('mais_antigos_que_o_portal');
                    continue;
                }
                if ($card['updated_at'] === $current['updated_at']) {
                    $this->count('iguais');
                    continue;
                }
                $this->count('atualizados');
                if ($write) {
                    $before = $this->store->lockCard($current['id']);
                    if ($before === null) {
                        $this->count('arquivados_no_portal');
                        continue;
                    }
                    $this->store->saveCard(['id' => $current['id'], 'row_version' => $current['row_version'] + 1, 'archived_at' => null, 'rollback' => $card['rollback'] === null ? null : $card['rollback'] + (($before['rollback']['id'] ?? null) !== null ? ['id' => $before['rollback']['id']] : [])] + $card, ChgRules::card($before), $actor);
                }
                continue;
            }
            $this->count('novos');
            if ($write) {
                $this->store->insertCard(['row_version' => 1, 'legacy_id' => $legacyId] + $card, $actor);
            }
        }
    }

    /** Funcionarios ativos por nome dobrado; nome repetido = null (nao decide sozinho). */
    private function employeeIndex(): array {
        $index = [];
        foreach ($this->store->activeEmployees() as $id => $nome) {
            $key = self::fold($nome);
            $index[$key] = array_key_exists($key, $index) ? null : (int)$id;
        }
        return $index;
    }

    private function text(array $item, string $field, int $max): string {
        $value = $item[$field] ?? '';
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException($field);
        }
        $value = trim((string)$value);
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1 || ChgRules::length($value) > $max) {
            throw new InvalidArgumentException($field);
        }
        return $value;
    }

    /** 'AAAA-MM-DDTHH:MM' (datetime-local do v5.2) -> 'AAAA-MM-DD HH:MM:00'; vazio ou invalido = null. */
    private static function dateTime(mixed $value): ?string {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $value, $m) !== 1) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $m[1] . ' ' . $m[2]);
        return $at !== false && $at->format('Y-m-d H:i') === $m[1] . ' ' . $m[2] ? $m[1] . ' ' . $m[2] . ':00' : null;
    }

    /** Milissegundos do v5.2 -> 'AAAA-MM-DD HH:MM:SS.mmm' na hora local do portal. */
    private function millis(mixed $value): ?string {
        if (!is_int($value) || $value < 0 || $value > 4102444800000) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%03d000', intdiv($value, 1000), $value % 1000));
        return $at === false ? null : $at->setTimezone($this->now->getTimezone())->format('Y-m-d H:i:s.v');
    }

    /** Link do v5.2: http(s) fica; www. e dominio sem esquema ganham https:// (linkHref); o resto sai. */
    private function link(mixed $raw): string {
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            return '';
        }
        if (ChgRules::validLink($value)) {
            return $value;
        }
        $completed = null;
        if (preg_match('/^www\./i', $value) === 1 || (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i', $value) === 1 && preg_match('/\.(xlsx?|csv|docx?|pdf|txt|exe)$/i', $value) !== 1)) {
            $completed = 'https://' . $value;
        }
        if ($completed !== null && ChgRules::validLink($completed)) {
            $this->count('links_completados');
            return $completed;
        }
        $this->count('links_descartados');
        return '';
    }

    /** O card do v5.2 normalizado (norm()) e convertido para o portal. */
    private function card(array $item, array $employees): array {
        $solicitante = $this->text($item, isset($item['solicitante']) && $item['solicitante'] !== '' ? 'solicitante' : 'analista', 100);
        if ($solicitante === '') {
            $solicitante = '(não informado)';
            $this->count('sem_solicitante');
        }
        $inicio = self::dateTime($item['inicio'] ?? null);
        $fim = self::dateTime($item['fim'] ?? null);
        if ($inicio !== null && $fim !== null && $fim < $inicio) {
            $fim = null;
            $this->count('fim_antes_do_inicio_limpo');
        }
        $numero = ChgRules::chgNumber($this->text($item, 'chg', 40));
        $chgStatus = self::CHG[$item['chgStatus'] ?? ''] ?? null;
        if ($numero === null) {
            $chgStatus = null;
        }
        $tecnicoName = $this->text($item, 'tecnico', 100);
        $tecnicoId = $tecnicoName === '' ? null : ($employees[self::fold($tecnicoName)] ?? null);
        if ($tecnicoName !== '' && $tecnicoId === null) {
            $this->count('tecnicos_sem_correspondencia');
        }
        $carteiras = $this->carteiras($item);
        $locais = $this->locais($item);
        $plans = $this->plans($item);
        $attempts = $this->attempts($item, $inicio);
        $created = $this->millis($item['createdAt'] ?? null) ?? $this->now->format('Y-m-d H:i:s.v');
        $card = [
            'solicitante' => $solicitante,
            'tipo' => ($item['tipo'] ?? '') === 'Emergencial' ? 'emergencial' : 'padrao',
            'versao' => $this->text($item, 'versao', 500),
            'versao_anterior' => $this->text($item, 'versaoAnterior', 500),
            'descricao' => $this->text($item, 'descricao', 300),
            'link_planilha' => $this->link($item['link'] ?? ''),
            'sugestao_at' => self::dateTime($item['sugestao'] ?? null),
            'service_status' => self::SERVICE[$item['status'] ?? ''] ?? 'aguardando',
            'sd_inicio' => $inicio,
            'sd_fim' => $fim,
            'chg_numero' => $numero,
            'chg_status' => $chgStatus,
            'tecnico_id' => $tecnicoId,
            'tecnico_nome_legado' => $tecnicoId === null && $tecnicoName !== '' ? $tecnicoName : null,
            'concluida' => ($item['concluida'] ?? false) === true,
            'concluida_em' => ($item['concluida'] ?? false) === true ? $this->millis($item['concluidaEm'] ?? null) : null,
            'created_at' => $created,
            'updated_at' => $this->millis($item['updatedAt'] ?? null) ?? $created,
            'carteiras' => $carteiras,
            'locais' => $locais,
            'plans' => $plans,
            'attempts' => $attempts,
            'rollback' => $this->rollback($item, $inicio, count($plans)),
        ];
        [$card['stage_manual'], $card['stage_manual_auto']] = $this->manual($item, $card);
        return $card;
    }

    private function carteiras(array $item): array {
        $source = is_array($item['carteiras'] ?? null) && $item['carteiras'] !== []
            ? $item['carteiras']
            : [['codigo' => $item['codigo'] ?? '', 'nome' => $item['nomeCarteira'] ?? '', 'semCodigo' => ($item['codigo'] ?? '') === '']];
        $list = [];
        foreach ($source as $c) {
            if (!is_array($c)) {
                throw new InvalidArgumentException('carteiras');
            }
            $codigo = is_scalar($c['codigo'] ?? null) ? trim((string)$c['codigo']) : '';
            $nome = is_scalar($c['nome'] ?? ($c['nomeCarteira'] ?? null)) ? trim((string)($c['nome'] ?? $c['nomeCarteira'])) : '';
            if (($c['semCodigo'] ?? false) === true || preg_match('/^[0-9]{1,10}$/', $codigo) !== 1) {
                $codigo = '';
            }
            if ($nome === '' && $codigo === '') {
                continue;
            }
            if (ChgRules::length($nome) > 120) {
                throw new InvalidArgumentException('carteiras');
            }
            $pas = preg_replace('/\D/', '', (string)($c['pas'] ?? ''));
            $list[] = ['codigo' => $codigo === '' ? null : $codigo, 'nome' => $nome === '' ? 'Carteira ' . $codigo : $nome, 'pas' => $pas === '' ? null : min(99999, (int)$pas)];
        }
        if ($list === [] && is_string($item['carteira'] ?? null) && trim($item['carteira']) !== '') {
            $label = trim($item['carteira']);
            $list[] = preg_match('/^(\d+)\s*-\s*(.*)$/', $label, $m) === 1 ? ['codigo' => $m[1], 'nome' => trim($m[2]) ?: 'Carteira ' . $m[1], 'pas' => null] : ['codigo' => null, 'nome' => ChgRules::length($label) > 120 ? throw new InvalidArgumentException('carteira') : $label, 'pas' => null];
        }
        if ($list === []) {
            $list[] = ['codigo' => null, 'nome' => 'Carteira não informada', 'pas' => null];
            $this->count('sem_carteira');
        }
        if (count($list) > ChgService::LIMITS['carteiras']) {
            throw new InvalidArgumentException('carteiras');
        }
        // Total de PAs do v5.2 (qtdPas): com uma carteira sem PAs, vai para ela;
        // com varias e nenhuma com PAs, para a primeira (D-CHG-33).
        $total = preg_replace('/\D/', '', (string)($item['qtdPas'] ?? ''));
        if ($total !== '' && array_filter($list, static fn (array $c): bool => $c['pas'] !== null) === []) {
            $list[0]['pas'] = min(99999, (int)$total);
            if (count($list) > 1) {
                $this->count('pas_totais_na_primeira_carteira');
            }
        }
        return $list;
    }

    private function locais(array $item): array {
        $source = is_array($item['locais'] ?? null) && $item['locais'] !== [] ? $item['locais'] : [$item['local'] ?? ''];
        $list = [];
        $seen = [];
        foreach ($source as $raw) {
            $nome = is_string($raw) ? trim($raw) : '';
            if ($nome === '' || isset($seen[ChgRules::lower($nome)])) {
                continue;
            }
            if (ChgRules::length($nome) > 60) {
                throw new InvalidArgumentException('locais');
            }
            $seen[ChgRules::lower($nome)] = true;
            $list[] = $nome;
        }
        if ($list === []) {
            $list[] = 'Local não informado';
            $this->count('sem_local');
        }
        return array_slice($list, 0, ChgService::LIMITS['locais']);
    }

    private function plan(array $p): array {
        $plan = ChgRules::emptyPlan();
        foreach (['implantacao' => 'planoImplantacao', 'backup' => 'planoBackup', 'rollback' => 'planoRollback'] as $field => $legacy) {
            $value = $this->text($p, $legacy, 40);
            $plan[$field] = strtoupper($value);
        }
        foreach (['task_impl' => 'taskImplStatus', 'task_bkp' => 'taskBkpStatus', 'task_roll' => 'taskRollStatus'] as $field => $legacy) {
            $plan[$field] = ($p[$legacy] ?? '') === 'Fechada' ? 'fechada' : 'pendente';
        }
        return $plan;
    }

    private function plans(array $item): array {
        $plans = [$this->plan($item)];
        foreach (is_array($item['planosExtras'] ?? null) ? $item['planosExtras'] : [] as $extra) {
            if (is_array($extra) && !array_is_list($extra)) {
                $plans[] = $this->plan($extra);
            }
        }
        if (count($plans) > ChgService::LIMITS['plans']) {
            throw new InvalidArgumentException('planos');
        }
        return $plans;
    }

    /** Inicio do endpoint (patchDT do v5.2): data e hora, so a data, ou so a hora (relativa ao inicio do SD). */
    private function endpointStart(mixed $raw, ?string $inicio): array {
        $value = is_string($raw) ? trim($raw) : '';
        if (($at = self::dateTime($value)) !== null) {
            return [$at, null];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false) {
            return [null, $value];
        }
        if ($inicio !== null && preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) === 1 && (int)$m[1] < 24 && (int)$m[2] < 60) {
            $base = new DateTimeImmutable($inicio);
            $at = $base->setTime((int)$m[1], (int)$m[2]);
            return [($at < $base ? $at->modify('+1 day') : $at)->format('Y-m-d H:i:00'), null];
        }
        return [null, null];
    }

    private function endpoints(mixed $list, ?string $inicio): array {
        $endpoints = [];
        foreach (is_array($list) ? $list : [] as $e) {
            if (!is_array($e)) {
                continue;
            }
            [$at, $date] = $this->endpointStart($e['hora'] ?? '', $inicio);
            $endpoints[] = ['url' => $this->link($e['url'] ?? ''), 'start_at' => $at, 'start_date' => $date];
        }
        return array_slice($endpoints, 0, ChgService::LIMITS['endpoints']);
    }

    private function attempts(array $item, ?string $inicio): array {
        $attempts = [];
        foreach (is_array($item['historico'] ?? null) ? $item['historico'] : [] as $h) {
            if (!is_array($h)) {
                continue;
            }
            $attempts[] = [
                'attempt_no' => count($attempts) + 1,
                'patch_status' => self::PATCH[$h['patchStatus'] ?? ''] ?? null,
                'reagendando' => ($h['reagendandoPatch'] ?? false) === true,
                'registered_at' => $this->millis($h['registradoEm'] ?? null) ?? $this->now->format('Y-m-d H:i:s.v'),
                'endpoints' => $this->endpoints($h['endpoints'] ?? [], $inicio),
            ];
        }
        $attempts[] = [
            'attempt_no' => count($attempts) + 1,
            'patch_status' => self::PATCH[$item['patchStatus'] ?? ''] ?? null,
            'reagendando' => ($item['reagendandoPatch'] ?? false) === true,
            'registered_at' => null,
            'endpoints' => $this->endpoints($item['endpoints'] ?? [], $inicio),
        ];
        if (count($attempts) > ChgRules::MAX_ATTEMPTS) {
            throw new InvalidArgumentException('historico');
        }
        return $attempts;
    }

    private function rollback(array $item, ?string $inicio, int $plans): ?array {
        $rb = $item['rollback'] ?? null;
        if (!is_array($rb) || ($rb['ativo'] ?? false) !== true) {
            if (is_array($rb)) {
                $this->count('rollbacks_inativos_ignorados');
            }
            return null;
        }
        [$start] = $this->endpointStart($rb['hora'] ?? '', $inicio);
        $started = self::dateTime($rb['iniciadoEm'] ?? null) ?? $start ?? $this->now->format('Y-m-d H:i:00');
        return [
            'url' => $this->link($rb['url'] ?? ''),
            'start_at' => $start,
            'started_at' => $started,
            // norm() do v5.2: rollback ativo sem status = Em andamento.
            'status' => self::ROLLBACK[$rb['status'] ?? ''] ?? 'em_andamento',
            'plan_position' => is_int($rb['planoIndex'] ?? null) && $rb['planoIndex'] >= 0 && $rb['planoIndex'] < $plans ? $rb['planoIndex'] : 0,
        ];
    }

    /** Etapa manual (triagem = comite, como o norm() do v5.2); sem a base, a automatica de agora. */
    private function manual(array $item, array $card): array {
        $map = static fn (mixed $v): ?string => $v === 'triagem' ? 'comite' : (in_array($v, ChgRules::MANUAL_STAGES, true) ? $v : null);
        $manual = $map($item['stageManual'] ?? null);
        if ($manual === null) {
            return [null, null];
        }
        $base = $map($item['stageManualAuto'] ?? null);
        if ($base === null) {
            $base = ChgRules::autoStage(ChgRules::card($card), $this->now);
            $this->count('etapa_manual_sem_base');
        }
        return [$manual, $base];
    }
}
