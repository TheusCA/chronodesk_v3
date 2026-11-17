<?php
declare(strict_types=1);

require_once __DIR__ . '/ChgRules.php';
require_once __DIR__ . '/ChgStore.php';
require_once __DIR__ . '/EmployeeGroups.php';
require_once __DIR__ . '/ChgTable.php';

/** 409 de concorrencia (versao da linha diferente; D-CHG-21). */
final class ChgConflictException extends DomainException {
}

/**
 * [9.17] Quem escreve no Controle de CHG: perfil com chg.manage (admin e
 * Lideranca) ou funcionario ativo da sessao no grupo Tools. Somente leitura
 * nunca escreve, nem no Tools (D-CHG-14). Importar: chg.import (admin).
 */
final class ChgAccess {
    public static function canManage(?string $role, ?int $employeeId, ChgStore $store): bool {
        if ($role === null || $role === 'somente_leitura') {
            return false;
        }
        if (portal_role_can($role, 'chg.manage')) {
            return true;
        }
        return $employeeId !== null && $employeeId > 0 && $store->inTools($employeeId);
    }

    public static function canImport(?string $role): bool {
        return portal_role_can($role, 'chg.import');
    }
}

/**
 * [9.17] Controle de CHG: leitura do quadro, formulario e as acoes do card,
 * com a versao da linha (409), as regras do v5.2 (ChgRules) e auditoria so
 * com IDs e a operacao (nada de solicitante, tecnico, link, caminho ou
 * numero da CHG no log).
 */
final class ChgService {
    public const FORBIDDEN_MESSAGE = 'Somente administradores, Liderança e o grupo Tools alteram o Controle de CHG.';
    public const CONFLICT_MESSAGE = 'Alguém alterou este card; recarregue.';
    public const LIMITS = ['carteiras' => 50, 'locais' => 20, 'plans' => 20, 'endpoints' => 20];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;
    /** @var Closure(string, string, string): void */
    private Closure $audit;

    public function __construct(private ChgStore $store, ?Closure $clock = null, ?Closure $audit = null) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
        $this->audit = $audit ?? static function (string $action, string $details, string $severity): void {
            if (function_exists('audit_log')) {
                audit_log($action, $details, $severity);
            }
        };
    }

    private function now(): DateTimeImmutable {
        return ($this->clock)();
    }

    /** GET: cards com a etapa e os rotulos prontos, catalogos, tecnicos e permissoes. */
    public function board(bool $canManage, bool $canImport): array {
        $now = $this->now();
        return [
            'now' => $now->format('Y-m-d H:i:s'),
            'cards' => array_map(fn (array $card): array => $this->present($card, $now), $this->store->cards()),
            'catalogos' => $this->store->catalogs(),
            'tecnicos' => $this->technicians(),
            'can_manage' => $canManage,
            'can_import' => $canImport,
        ];
    }

    /** Funcionarios ativos para o campo Tecnico: [{id, nome}]. */
    private function technicians(): array {
        $list = [];
        foreach ($this->store->activeEmployees() as $id => $nome) {
            $list[] = ['id' => (int)$id, 'nome' => $nome];
        }
        return $list;
    }

    /** O card como a tela recebe: os dados e a "view" calculada no agora do servidor. */
    public function present(array $card, ?DateTimeImmutable $now = null): array {
        $card = ChgRules::card($card);
        $card['qtd_pas'] = array_sum(array_map(static fn (array $c): int => (int)($c['pas'] ?? 0), $card['carteiras'] ?? []));
        $card += ['view' => ChgRules::view($card, $now ?? $this->now())];
        // A tabela para copiar (e-mail, Teams, Excel) sai pronta do servidor.
        $card['tabela'] = ChgTable::rows($card);
        return $card;
    }

    /** Base do "Duplicar" (o formulario abre com ela; nada e gravado). */
    public function duplicateBase(int $id): array {
        $card = $this->store->cards($id)[0] ?? null;
        if ($card === null) {
            throw new InvalidArgumentException('Card inexistente.');
        }
        return ChgRules::duplicateBase(ChgRules::card($card));
    }

    // ------------------------------------------------------------------
    // Formulario
    // ------------------------------------------------------------------

    public function create(array $data, string $actor): array {
        $form = $this->form($data, null);
        $now = $this->now();
        $card = ChgRules::card($form + [
            'concluida' => false, 'row_version' => 1,
            'created_at' => $now->format('Y-m-d H:i:s.v'), 'updated_at' => $now->format('Y-m-d H:i:s.v'),
            'attempts' => [['attempt_no' => 1, 'patch_status' => null, 'reagendando' => false, 'registered_at' => null, 'endpoints' => []]],
            'rollback' => null,
        ]);
        $id = $this->atomic(fn (): int => $this->store->insertCard($card, $actor));
        ($this->audit)('CHG_CRIADO', "Card ID {$id} criado", 'INFO');
        return $this->present($this->store->cards($id)[0]);
    }

    public function update(array $data, string $actor): array {
        return $this->write($data, $actor, 'update', function (array $card) use ($data): array {
            $form = $this->form($data, $card);
            $rollbackPlan = $card['rollback']['plan_position'] ?? null;
            if ($rollbackPlan !== null && $rollbackPlan >= count($form['plans'])) {
                throw new DomainException('Existe um rollback associado a um plano removido. Finalize ou exclua o rollback antes de remover o plano.');
            }
            return array_merge($card, $form);
        });
    }

    /**
     * Campos do formulario, validados (lista fechada de chaves; tipo e tamanho
     * de cada um). $current: o card de antes (edicao) ou null (novo).
     */
    private function form(array $data, ?array $current): array {
        $allowed = ['action', 'id', 'row_version', 'solicitante', 'tipo', 'carteiras', 'locais', 'versao', 'versao_anterior', 'descricao',
            'link_planilha', 'sugestao_at', 'service_status', 'sd_inicio', 'sd_fim', 'chg_numero', 'chg_status', 'tecnico_id', 'manter_tecnico_historico', 'plans'];
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Campo desconhecido no formulário.');
        }
        $solicitante = $this->text($data['solicitante'] ?? null, 100, 'Solicitante');
        if ($solicitante === '') {
            throw new InvalidArgumentException('Informe o solicitante.');
        }
        $sugestao = $this->dateTime($data['sugestao_at'] ?? null, 'Data sugestão');
        if ($sugestao === null) {
            throw new InvalidArgumentException('Informe a data sugestão.');
        }
        $inicio = $this->dateTime($data['sd_inicio'] ?? null, 'Início Service Desk');
        $fim = $this->dateTime($data['sd_fim'] ?? null, 'Fim Service Desk');
        if ($inicio !== null && $fim !== null && $fim < $inicio) {
            throw new InvalidArgumentException('O fim do Service Desk não pode ser antes do início.');
        }
        $numero = ChgRules::chgNumber($this->text($data['chg_numero'] ?? '', 40, 'CHG'));
        $status = $this->choice($data['chg_status'] ?? '', ['', ...ChgRules::CHG_STATUS], 'Status da CHG');
        if ($status === 'aprovada' && $numero === null) {
            throw new InvalidArgumentException('Informe o número da CHG antes de marcá-la como aprovada.');
        }
        $status = $numero === null ? null : ($status === '' ? 'pendente' : $status);
        $link = $this->text($data['link_planilha'] ?? '', 2048, 'Link da planilha');
        if (!ChgRules::validLink($link)) {
            throw new InvalidArgumentException('Link da planilha: use o endereço completo, com https://.');
        }
        [$tecnicoId, $tecnicoLegado] = $this->tecnico($data, $current);
        return [
            'solicitante' => $solicitante,
            'tipo' => $this->choice($data['tipo'] ?? 'padrao', ['padrao', 'emergencial'], 'Tipo de execução'),
            'carteiras' => $this->carteiras($data['carteiras'] ?? null),
            'locais' => $this->locais($data['locais'] ?? null),
            'versao' => $this->text($data['versao'] ?? '', 500, 'Versão/caminho'),
            'versao_anterior' => $this->text($data['versao_anterior'] ?? '', 500, 'Versão anterior'),
            'descricao' => $this->text($data['descricao'] ?? '', 300, 'Descrição'),
            'link_planilha' => $link,
            'sugestao_at' => $sugestao,
            'service_status' => $this->choice($data['service_status'] ?? 'aguardando', ChgRules::SERVICE_STATUS, 'Aprovação Service'),
            'sd_inicio' => $inicio,
            'sd_fim' => $fim,
            'chg_numero' => $numero,
            'chg_status' => $status,
            'tecnico_id' => $tecnicoId,
            'tecnico_nome_legado' => $tecnicoLegado,
            'plans' => $this->plans($data['plans'] ?? null),
        ];
    }

    /** Tecnico por ID (funcionario ativo); o nome do historico so fica se ja estava e o form pede para manter. */
    private function tecnico(array $data, ?array $current): array {
        $raw = $data['tecnico_id'] ?? null;
        if ($raw !== null && $raw !== '') {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new InvalidArgumentException('Técnico inválido.');
            }
            if ($id !== ($current['tecnico_id'] ?? null) && !array_key_exists($id, $this->store->activeEmployees())) {
                throw new InvalidArgumentException('Técnico inválido: escolha um funcionário ativo.');
            }
            return [$id, null];
        }
        $keep = ($data['manter_tecnico_historico'] ?? false) === true;
        $legacy = $current['tecnico_nome_legado'] ?? null;
        return [null, $keep && $legacy !== null ? $legacy : null];
    }

    private function carteiras(mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::LIMITS['carteiras']) {
            throw new InvalidArgumentException('Informe de 1 a ' . self::LIMITS['carteiras'] . ' carteiras.');
        }
        $list = [];
        foreach ($value as $i => $c) {
            $n = $i + 1;
            if (!is_array($c) || array_diff(array_keys($c), ['codigo', 'nome', 'sem_codigo', 'pas']) !== []) {
                throw new InvalidArgumentException("Carteira {$n} inválida.");
            }
            $semCodigo = ($c['sem_codigo'] ?? false) === true;
            $codigo = $semCodigo ? '' : $this->text($c['codigo'] ?? '', 10, "Código da carteira {$n}");
            $nome = $this->text($c['nome'] ?? '', 120, "Nome da carteira {$n}");
            if ($nome === '' || (!$semCodigo && $codigo === '')) {
                throw new InvalidArgumentException("Na carteira {$n}, informe " . ($nome === '' ? 'o nome' : "o código ou marque 'Sem código'") . '.');
            }
            if ($codigo !== '' && preg_match('/^[0-9]{1,10}$/', $codigo) !== 1) {
                throw new InvalidArgumentException("Código da carteira {$n}: só números.");
            }
            $pas = $c['pas'] ?? null;
            if ($pas === '' || $pas === null) {
                $pas = null;
            } elseif (!is_int($pas) || $pas < 0 || $pas > 99999) {
                throw new InvalidArgumentException("PAs da carteira {$n}: número de 0 a 99999.");
            }
            $list[] = ['codigo' => $codigo === '' ? null : $codigo, 'nome' => $nome, 'pas' => $pas];
        }
        return $list;
    }

    private function locais(mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::LIMITS['locais']) {
            throw new InvalidArgumentException('Informe de 1 a ' . self::LIMITS['locais'] . ' locais.');
        }
        $seen = [];
        $list = [];
        foreach ($value as $i => $raw) {
            $nome = $this->text($raw, 60, 'Local ' . ($i + 1));
            if ($nome === '') {
                throw new InvalidArgumentException('Informe o local ' . ($i + 1) . ' ou remova a linha vazia.');
            }
            $key = ChgRules::lower($nome);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("O local {$nome} foi informado mais de uma vez.");
            }
            $seen[$key] = true;
            $list[] = $nome;
        }
        return $list;
    }

    private function plans(mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::LIMITS['plans']) {
            throw new InvalidArgumentException('Informe de 1 a ' . self::LIMITS['plans'] . ' planos.');
        }
        $list = [];
        foreach ($value as $i => $p) {
            if (!is_array($p) || array_diff(array_keys($p), array_keys(ChgRules::emptyPlan())) !== []) {
                throw new InvalidArgumentException('Plano ' . ($i + 1) . ' inválido.');
            }
            $plan = ChgRules::emptyPlan();
            foreach (['implantacao', 'backup', 'rollback'] as $field) {
                $plan[$field] = strtoupper($this->text($p[$field] ?? '', 40, 'Plano ' . ($i + 1)));
            }
            foreach (['task_impl', 'task_bkp', 'task_roll'] as $field) {
                $plan[$field] = $this->choice($p[$field] ?? 'pendente', ['pendente', 'fechada'], 'Task do plano ' . ($i + 1));
            }
            $list[] = $plan;
        }
        return $list;
    }

    // ------------------------------------------------------------------
    // Acoes do card
    // ------------------------------------------------------------------

    /** Despacho das acoes do card (POST action=...). */
    public function action(string $action, array $data, string $actor): array {
        $now = $this->now();
        $handlers = [
            'set_field' => fn (array $c): array => $this->setField($c, $data),
            'plan_field' => fn (array $c): array => $this->planField($c, $data),
            'plan_task' => fn (array $c): array => $this->planTask($c, $data, $now),
            'add_plan' => fn (array $c): array => $this->addPlan($c),
            'delete_plan' => fn (array $c): array => $this->deletePlan($c, $data),
            'endpoint_field' => fn (array $c): array => $this->endpointField($c, $data),
            'add_endpoint' => fn (array $c): array => $this->addEndpoint($c, $now),
            'delete_endpoint' => fn (array $c): array => $this->deleteEndpoint($c, $data),
            'history_status' => fn (array $c): array => $this->historyStatus($c, $data),
            'new_patch' => fn (array $c): array => ['attempts' => ChgRules::newPatchAttempts($c, $now), 'stage_manual' => null, 'stage_manual_auto' => null] + $c,
            'delete_attempt' => fn (array $c): array => ['attempts' => ChgRules::deleteAttempt($c, $this->int($data['attempt_no'] ?? null, 'Tentativa'))] + $c,
            'rollback_field' => fn (array $c): array => $this->rollbackField($c, $data),
            'delete_rollback' => fn (array $c): array => $this->deleteRollback($c, $now),
            'move' => fn (array $c): array => $this->move($c, $data, $now),
            'auto' => fn (array $c): array => ['stage_manual' => null, 'stage_manual_auto' => null] + $c,
            'finish' => fn (array $c): array => $this->finish($c, $now),
            'reopen' => fn (array $c): array => ['concluida' => false, 'stage_manual' => null, 'stage_manual_auto' => null] + $c,
            'archive' => fn (array $c): array => ['archived_at' => $now->format('Y-m-d H:i:s')] + $c,
        ];
        if (!isset($handlers[$action])) {
            throw new InvalidArgumentException('Ação do Controle de CHG inválida.');
        }
        return $this->write($data, $actor, $action, $handlers[$action]);
    }

    /** Trava, confere a versao, aplica, grava, audita e devolve o card. */
    private function write(array $data, string $actor, string $action, callable $apply): array {
        $id = $this->int($data['id'] ?? null, 'Card');
        $version = $this->int($data['row_version'] ?? null, 'Versão');
        $saved = $this->atomic(function () use ($id, $version, $apply, $actor): array {
            $before = $this->store->lockCard($id);
            if ($before === null) {
                throw new InvalidArgumentException('Card inexistente ou arquivado.');
            }
            if ((int)$before['row_version'] !== $version) {
                throw new ChgConflictException(self::CONFLICT_MESSAGE);
            }
            $before = ChgRules::card($before);
            $after = $apply($before);
            if (!array_key_exists('archived_at', $after)) {
                $after['archived_at'] = null;
            }
            $after['row_version'] = $version + 1;
            $after['updated_at'] = $this->now()->format('Y-m-d H:i:s.v');
            $this->store->saveCard($after, $before, $actor);
            return $after;
        });
        ($this->audit)($action === 'archive' ? 'CHG_ARQUIVADO' : 'CHG_ALTERADO', "Card ID {$id} acao={$action} versao={$saved['row_version']}", 'INFO');
        return $action === 'archive' ? ['id' => $id, 'archived' => true] : $this->present($this->store->cards($id)[0]);
    }

    private function setField(array $card, array $data): array {
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;
        switch ($field) {
            case 'chg_numero':
                $numero = ChgRules::chgNumber($this->text($value ?? '', 40, 'CHG'));
                return ['chg_numero' => $numero, 'chg_status' => $numero === null ? null : ($card['chg_status'] ?? 'pendente')] + $card;
            case 'chg_status':
                $status = $this->choice($value ?? '', ['', ...ChgRules::CHG_STATUS], 'Status da CHG');
                if ($status === 'aprovada' && trim((string)$card['chg_numero']) === '') {
                    throw new InvalidArgumentException('Informe o número da CHG antes de aprová-la.');
                }
                if ($status !== '' && trim((string)$card['chg_numero']) === '') {
                    throw new InvalidArgumentException('Informe o número da CHG antes do status.');
                }
                return ['chg_status' => $status === '' ? null : $status] + $card;
            case 'service_status':
                return ['service_status' => $this->choice($value, ChgRules::SERVICE_STATUS, 'Aprovação Service')] + $card;
            case 'tecnico_id':
                [$id, $legacy] = $this->tecnico(['tecnico_id' => $value], $card);
                return ['tecnico_id' => $id, 'tecnico_nome_legado' => $legacy] + $card;
            case 'patch_status':
                $status = $this->choice($value ?? '', ['', ...ChgRules::PATCH_STATUS], 'Status do patch');
                $attempts = $card['attempts'];
                $attempts[count($attempts) - 1]['patch_status'] = $status === '' ? null : $status;
                // Erro prevalece sobre o movimento manual (v5.2).
                $manual = $status === 'erro' ? ['stage_manual' => null, 'stage_manual_auto' => null] : [];
                return $manual + ['attempts' => $attempts] + $card;
        }
        throw new InvalidArgumentException('Campo do card inválido.');
    }

    private function planIndex(array $card, mixed $value): int {
        $index = $this->int($value, 'Plano', 0);
        if ($index >= count($card['plans'])) {
            throw new InvalidArgumentException('Plano inexistente.');
        }
        return $index;
    }

    private function planField(array $card, array $data): array {
        $index = $this->planIndex($card, $data['plan'] ?? null);
        $field = $this->choice($data['field'] ?? null, ['implantacao', 'backup', 'rollback'], 'Campo do plano');
        $card['plans'][$index][$field] = strtoupper($this->text($data['value'] ?? '', 40, 'Plano'));
        return $card;
    }

    private function planTask(array $card, array $data, DateTimeImmutable $now): array {
        $index = $this->planIndex($card, $data['plan'] ?? null);
        $task = $this->choice($data['task'] ?? null, ['task_impl', 'task_bkp', 'task_roll'], 'Task');
        $value = $this->choice($data['value'] ?? null, ['pendente', 'fechada', 'iniciar_rollback'], 'Status da task');
        if ($value === 'iniciar_rollback') {
            if ($task !== 'task_roll') {
                throw new InvalidArgumentException('O rollback é iniciado pela task de rollback.');
            }
            if (ChgRules::rollbackActive($card)) {
                throw new DomainException('Já existe rollback em andamento.');
            }
            $stamp = $now->format('Y-m-d H:i:00');
            return ['rollback' => ['url' => '', 'start_at' => $stamp, 'started_at' => $stamp, 'status' => 'em_andamento', 'plan_position' => $index], 'stage_manual' => null, 'stage_manual_auto' => null] + $card;
        }
        $card['plans'][$index][$task] = $value;
        return $card;
    }

    private function addPlan(array $card): array {
        if (count($card['plans']) >= self::LIMITS['plans']) {
            throw new DomainException('Limite de ' . self::LIMITS['plans'] . ' planos neste card.');
        }
        $card['plans'][] = ChgRules::emptyPlan();
        return $card;
    }

    private function deletePlan(array $card, array $data): array {
        $index = $this->planIndex($card, $data['plan'] ?? null);
        if ($index === 0) {
            throw new DomainException('O Plano 1 não pode ser excluído. Edite os números se necessário.');
        }
        if (ChgRules::rollbackActive($card) && (int)$card['rollback']['plan_position'] === $index) {
            throw new DomainException('Existe um rollback associado a este plano. Finalize ou exclua o rollback antes de remover o plano.');
        }
        array_splice($card['plans'], $index, 1);
        if (ChgRules::rollbackActive($card) && (int)$card['rollback']['plan_position'] > $index) {
            $card['rollback']['plan_position']--;
        }
        return $card;
    }

    /** Indice do endpoint na tentativa atual; o card sem endpoint mostra uma linha vazia (a 0). */
    private function endpoints(array $card): array {
        $endpoints = ChgRules::currentAttempt($card)['endpoints'];
        if ($endpoints === []) {
            $endpoints = [ChgRules::reagendando($card)
                ? ['url' => '', 'start_at' => null, 'start_date' => $this->now()->format('Y-m-d')]
                : ['url' => '', 'start_at' => $card['sd_inicio'], 'start_date' => null]];
        }
        return $endpoints;
    }

    private function withEndpoints(array $card, array $endpoints): array {
        $card['attempts'][count($card['attempts']) - 1]['endpoints'] = array_values($endpoints);
        return $card;
    }

    private function endpointField(array $card, array $data): array {
        $endpoints = $this->endpoints($card);
        $index = $this->int($data['index'] ?? null, 'Endpoint', 0);
        if ($index >= count($endpoints)) {
            throw new InvalidArgumentException('Endpoint inexistente.');
        }
        $field = $this->choice($data['field'] ?? null, ['url', 'start'], 'Campo do endpoint');
        if ($field === 'url') {
            $url = $this->text($data['value'] ?? '', 2048, 'Link do endpoint');
            if (!ChgRules::validLink($url)) {
                throw new InvalidArgumentException('Link do endpoint: use o endereço completo, com https://.');
            }
            $endpoints[$index]['url'] = $url;
            return $this->withEndpoints($card, $endpoints);
        }
        [$at, $date] = $this->start($data['value'] ?? null);
        $endpoints[$index]['start_at'] = $at;
        $endpoints[$index]['start_date'] = $date;
        return $this->withEndpoints($card, $endpoints);
    }

    /** Inicio do patch: 'Y-m-d H:i' (data e hora) ou 'Y-m-d' (so a data: horario pendente). */
    private function start(mixed $value): array {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Data do patch inválida.');
            }
            return [null, $value];
        }
        $at = $this->dateTime($value, 'Início do patch');
        if ($at === null) {
            throw new InvalidArgumentException('Informe a data e a hora (dd/mm/aaaa hh:mm), ou deixe somente a data enquanto agenda o patch.');
        }
        return [$at, null];
    }

    private function addEndpoint(array $card, DateTimeImmutable $now): array {
        $endpoints = $this->endpoints($card);
        if (count($endpoints) >= self::LIMITS['endpoints']) {
            throw new DomainException('Limite de ' . self::LIMITS['endpoints'] . ' endpoints por tentativa.');
        }
        if (ChgRules::reagendando($card)) {
            $first = $endpoints[0];
            $date = $first['start_date'] ?? ($first['start_at'] !== null ? substr((string)$first['start_at'], 0, 10) : $now->format('Y-m-d'));
            $endpoints[] = ['url' => '', 'start_at' => null, 'start_date' => $date];
        } else {
            $endpoints[] = ['url' => '', 'start_at' => $card['sd_inicio'], 'start_date' => null];
        }
        return $this->withEndpoints($card, $endpoints);
    }

    private function deleteEndpoint(array $card, array $data): array {
        $endpoints = $this->endpoints($card);
        $index = $this->int($data['index'] ?? null, 'Endpoint', 0);
        if ($index >= count($endpoints)) {
            throw new InvalidArgumentException('Endpoint inexistente.');
        }
        array_splice($endpoints, $index, 1);
        return $this->withEndpoints($card, $endpoints);
    }

    private function historyStatus(array $card, array $data): array {
        $number = $this->int($data['attempt_no'] ?? null, 'Tentativa');
        if ($number >= count($card['attempts'])) {
            throw new InvalidArgumentException('Só o status das tentativas do histórico é editado aqui.');
        }
        $status = $this->choice($data['value'] ?? '', ['', ...ChgRules::PATCH_STATUS], 'Status do patch');
        $card['attempts'][$number - 1]['patch_status'] = $status === '' ? null : $status;
        return $card;
    }

    private function rollbackField(array $card, array $data): array {
        if (!ChgRules::rollbackActive($card)) {
            throw new DomainException('Este card não tem rollback ativo.');
        }
        $field = $this->choice($data['field'] ?? null, ['url', 'start', 'status'], 'Campo do rollback');
        if ($field === 'url') {
            $url = $this->text($data['value'] ?? '', 2048, 'Link do rollback');
            if (!ChgRules::validLink($url)) {
                throw new InvalidArgumentException('Link do rollback: use o endereço completo, com https://.');
            }
            $card['rollback']['url'] = $url;
            return $card;
        }
        if ($field === 'start') {
            $at = $this->dateTime($data['value'] ?? null, 'Início do rollback');
            if ($at === null) {
                throw new InvalidArgumentException('Data e hora do rollback inválidas. Use dd/mm/aaaa hh:mm.');
            }
            $card['rollback']['start_at'] = $at;
            return $card;
        }
        $status = $this->choice($data['value'] ?? null, ChgRules::ROLLBACK_STATUS, 'Situação do rollback');
        $card['rollback']['status'] = $status;
        // Rollback com sucesso prevalece sobre o movimento manual (v5.2).
        return $status === 'sucesso' ? ['stage_manual' => null, 'stage_manual_auto' => null] + $card : $card;
    }

    private function deleteRollback(array $card, DateTimeImmutable $now): array {
        if (!ChgRules::rollbackActive($card)) {
            throw new DomainException('Este card não tem rollback ativo.');
        }
        $after = ['rollback' => null] + $card;
        return ChgRules::keepStageChanges($card, $after, $now) + $after;
    }

    private function move(array $card, array $data, DateTimeImmutable $now): array {
        $stage = $this->choice($data['stage'] ?? null, ChgRules::STAGES, 'Etapa');
        $changes = ChgRules::moveChanges($card, $stage, $now);
        return $changes === null ? $card : $changes + $card;
    }

    private function finish(array $card, DateTimeImmutable $now): array {
        $blocker = ChgRules::finishBlocker($card);
        if ($blocker !== null) {
            throw new DomainException($blocker);
        }
        $changes = ChgRules::moveChanges($card, 'done', $now);
        return $changes === null ? $card : $changes + $card;
    }

    // ------------------------------------------------------------------
    // Catalogos
    // ------------------------------------------------------------------

    public function saveCatalog(array $data, string $actor): array {
        $kind = $this->choice($data['kind'] ?? null, ['carteira', 'local'], 'Catálogo');
        $id = ($data['item_id'] ?? null) === null ? null : $this->int($data['item_id'], 'Item');
        $nome = $this->text($data['nome'] ?? '', $kind === 'carteira' ? 120 : 60, 'Nome');
        if ($nome === '') {
            throw new InvalidArgumentException('Informe o nome.');
        }
        $row = ['id' => $id, 'nome' => $nome, 'ativo' => ($data['ativo'] ?? true) === true];
        if ($kind === 'carteira') {
            $row['codigo'] = $this->text($data['codigo'] ?? '', 10, 'Código');
            if (preg_match('/^[0-9]{1,10}$/', $row['codigo']) !== 1) {
                throw new InvalidArgumentException('Código da carteira: só números.');
            }
        }
        try {
            $saved = $this->atomic(fn (): int => $this->store->saveCatalog($kind, $row, $actor));
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new DomainException($kind === 'carteira' ? 'Já existe carteira com este código.' : 'Já existe local com este nome.', 0, $e);
            }
            throw $e;
        }
        ($this->audit)('CHG_CATALOGO', "Catalogo {$kind} ID {$saved} " . ($id === null ? 'criado' : 'alterado'), 'INFO');
        return ['id' => $saved, 'catalogos' => $this->store->catalogs()];
    }

    // ------------------------------------------------------------------
    // Validacao
    // ------------------------------------------------------------------

    private function text(mixed $value, int $max, string $label): string {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$label}: texto inválido.");
        }
        $value = trim($value);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1 || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("{$label}: caracteres inválidos.");
        }
        if (ChgRules::length($value) > $max) {
            throw new InvalidArgumentException("{$label}: até {$max} caracteres.");
        }
        return $value;
    }

    private function choice(mixed $value, array $allowed, string $label): string {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("{$label}: valor inválido.");
        }
        return $value;
    }

    private function int(mixed $value, string $label, int $min = 1): int {
        $int = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int === null || $int < $min || $int > 2147483647) {
            throw new InvalidArgumentException("{$label}: número inválido.");
        }
        return $int;
    }

    /** 'Y-m-d H:i' (ou com segundos, ou com T) -> 'Y-m-d H:i:00'; vazio = null. */
    private function dateTime(mixed $value, string $label): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("{$label}: data e hora inválidas.");
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $m[1] . ' ' . $m[2]);
        if ($at === false || $at->format('Y-m-d H:i') !== $m[1] . ' ' . $m[2]) {
            throw new InvalidArgumentException("{$label}: data e hora inválidas.");
        }
        return $at->format('Y-m-d H:i:00');
    }

    private function atomic(callable $callback): mixed {
        $owns = !$this->store->inTransaction();
        if ($owns) {
            $this->store->begin();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->store->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->store->inTransaction()) {
                $this->store->rollBack();
            }
            throw $e;
        }
    }
}
