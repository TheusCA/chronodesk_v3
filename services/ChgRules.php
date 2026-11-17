<?php
declare(strict_types=1);

/**
 * [9.17] Regras puras do Controle de CHG (espelho do Controle de CHG v5.2).
 * Sem banco, sem relogio e sem sessao: o "agora" entra como parametro, e o
 * card e um array normalizado (ChgRules::card). A tela nao recalcula nada:
 * o servidor devolve a etapa e os rotulos prontos (ChgRules::view).
 *
 * Card normalizado:
 *   service_status: aguardando | aprovado | cancelado
 *   chg_numero: ?string; chg_status: null | pendente | aprovada | cancelada
 *   concluida: bool; concluida_em: ?string
 *   sd_inicio, sd_fim: ?string 'Y-m-d H:i:s' (hora local do portal)
 *   stage_manual, stage_manual_auto: null | comite | prep | exec | valid
 *   tecnico_id: ?int; tecnico_nome_legado: ?string
 *   plans: lista de {implantacao, backup, rollback, task_impl, task_bkp, task_roll}
 *   attempts: lista em ordem de {attempt_no, patch_status, reagendando,
 *             registered_at, endpoints: [{url, start_at, start_date}]};
 *             a ultima e a atual
 *   rollback: null | {url, start_at, started_at, status, plan_position}
 */
final class ChgRules {
    public const PREP_HOURS = 4;
    public const STAGES = ['comite', 'prep', 'exec', 'valid', 'done', 'cancel'];
    public const MANUAL_STAGES = ['comite', 'prep', 'exec', 'valid'];
    private const RANK = ['comite' => 0, 'prep' => 1, 'exec' => 2, 'valid' => 3];
    public const STAGE_TITLES = [
        'comite' => 'Aguardando Comitê',
        'prep' => 'Em preparação',
        'exec' => 'Em execução',
        'valid' => 'Validação/Encerramento TASK',
        'done' => 'Concluídas',
        'cancel' => 'Canceladas',
    ];
    /** [campo do status, campo do numero, rotulo] das tasks de cada plano. */
    public const TASKS = [
        ['task_impl', 'implantacao', 'Task de Implantação'],
        ['task_bkp', 'backup', 'Task de Backup'],
        ['task_roll', 'rollback', 'Task de Rollback'],
    ];
    public const SERVICE_STATUS = ['aguardando', 'aprovado', 'cancelado'];
    public const CHG_STATUS = ['pendente', 'aprovada', 'cancelada'];
    public const PATCH_STATUS = ['sucesso', 'parcial', 'erro'];
    public const ROLLBACK_STATUS = ['em_andamento', 'sucesso', 'erro'];

    public static function emptyPlan(): array {
        return ['implantacao' => '', 'backup' => '', 'rollback' => '', 'task_impl' => 'pendente', 'task_bkp' => 'pendente', 'task_roll' => 'pendente'];
    }

    /** Card com todos os campos (os ausentes com o padrao), para as regras. */
    public static function card(array $card): array {
        $base = [
            'service_status' => 'aguardando', 'chg_numero' => null, 'chg_status' => null,
            'concluida' => false, 'concluida_em' => null, 'sd_inicio' => null, 'sd_fim' => null,
            'stage_manual' => null, 'stage_manual_auto' => null, 'tecnico_id' => null,
            'tecnico_nome_legado' => null, 'plans' => [self::emptyPlan()],
            'attempts' => [['attempt_no' => 1, 'patch_status' => null, 'reagendando' => false, 'registered_at' => null, 'endpoints' => []]],
            'rollback' => null,
        ];
        $card = array_merge($base, $card);
        $card['concluida'] = (bool)$card['concluida'];
        if ($card['plans'] === []) {
            $card['plans'] = [self::emptyPlan()];
        }
        if ($card['attempts'] === []) {
            $card['attempts'] = $base['attempts'];
        }
        return $card;
    }

    public static function currentAttempt(array $card): array {
        return $card['attempts'][count($card['attempts']) - 1];
    }

    public static function patchStatus(array $card): ?string {
        return self::currentAttempt($card)['patch_status'] ?? null;
    }

    public static function reagendando(array $card): bool {
        return (bool)(self::currentAttempt($card)['reagendando'] ?? false);
    }

    public static function rollbackActive(array $card): bool {
        return $card['rollback'] !== null;
    }

    public static function rollbackStatus(array $card): string {
        return (string)($card['rollback']['status'] ?? 'em_andamento');
    }

    private static function at(?string $value): ?DateTimeImmutable {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $parsed === false ? null : $parsed;
    }

    public static function prepAt(array $card): ?DateTimeImmutable {
        $start = self::at($card['sd_inicio']);
        return $start?->modify('-' . self::PREP_HOURS . ' hours');
    }

    /** Menor inicio (com hora) dos endpoints da tentativa atual; "so a data" nao conta. */
    public static function patchStart(array $card): ?DateTimeImmutable {
        $starts = [];
        foreach (self::currentAttempt($card)['endpoints'] ?? [] as $endpoint) {
            $at = self::at($endpoint['start_at'] ?? null);
            if ($at !== null) {
                $starts[] = $at;
            }
        }
        if ($starts === []) {
            return null;
        }
        sort($starts);
        return $starts[0];
    }

    public static function autoStage(array $card, DateTimeImmutable $now): string {
        // Rollback com sucesso volta a Validacao, mesmo com o patch em Erro;
        // em andamento ou com erro fica em Execucao.
        if (self::rollbackActive($card)) {
            return self::rollbackStatus($card) === 'sucesso' ? 'valid' : 'exec';
        }
        // Sem numero de CHG ou sem aprovacao nao ha avanco automatico.
        if (trim((string)$card['chg_numero']) === '' || $card['chg_status'] !== 'aprovada') {
            return 'comite';
        }
        $patch = self::patchStatus($card);
        if ($patch === 'sucesso' || $patch === 'parcial') {
            return 'valid';
        }
        if ($patch === 'erro') {
            return 'prep';
        }
        if (self::reagendando($card)) {
            $at = self::patchStart($card);
            return $at !== null && $now >= $at ? 'exec' : 'prep';
        }
        $start = self::at($card['sd_inicio']);
        if ($start === null) {
            return 'prep';
        }
        if ($now >= $start) {
            return 'exec';
        }
        if ($now >= self::prepAt($card)) {
            return 'prep';
        }
        return 'comite';
    }

    public static function stageOf(array $card, DateTimeImmutable $now): string {
        if ($card['service_status'] === 'cancelado' || $card['chg_status'] === 'cancelada') {
            return 'cancel';
        }
        if ($card['concluida']) {
            return 'done';
        }
        $auto = self::autoStage($card, $now);
        $manual = $card['stage_manual'];
        if ($manual === null || !isset(self::RANK[$manual])) {
            return $auto;
        }
        $base = isset(self::RANK[$card['stage_manual_auto'] ?? '']) ? $card['stage_manual_auto'] : $auto;
        if (self::RANK[$manual] >= self::RANK[$base]) {
            return self::RANK[$auto] > self::RANK[$manual] ? $auto : $manual;
        }
        return $auto === $base ? $manual : $auto;
    }

    /** "Movido manualmente": a etapa vem do manual e difere da automatica. */
    public static function isManual(array $card, DateTimeImmutable $now): bool {
        $stage = self::stageOf($card, $now);
        return $card['stage_manual'] !== null
            && !in_array($stage, ['cancel', 'done'], true)
            && $stage === $card['stage_manual']
            && $stage !== self::autoStage($card, $now);
    }

    public static function waitingWindow(array $card, DateTimeImmutable $now): bool {
        return $card['chg_status'] === 'aprovada' && self::stageOf($card, $now) === 'comite';
    }

    /** "Janela encerrada": em Execucao com o fim do Service Desk no passado. */
    public static function isLate(array $card, DateTimeImmutable $now): bool {
        $end = self::at($card['sd_fim']);
        return self::stageOf($card, $now) === 'exec' && $end !== null && $end < $now;
    }

    /** Task necessaria: plano preenchido e diferente de N/A (ou NA). */
    public static function taskNeeded(string $planValue): bool {
        $value = strtoupper(trim($planValue));
        return $value !== '' && $value !== 'N/A' && $value !== 'NA';
    }

    /** Tasks necessarias ainda nao fechadas, como "Task de Backup (Plano 2)". */
    public static function openTasks(array $card): array {
        $open = [];
        foreach ($card['plans'] as $index => $plan) {
            foreach (self::TASKS as [$statusField, $planField, $label]) {
                if (self::taskNeeded((string)($plan[$planField] ?? '')) && ($plan[$statusField] ?? 'pendente') !== 'fechada') {
                    $open[] = $label . ' (Plano ' . ($index + 1) . ')';
                }
            }
        }
        return $open;
    }

    /** null = pode concluir; senao a mensagem do v5.2. */
    public static function finishBlocker(array $card): ?string {
        if (self::rollbackActive($card) && self::rollbackStatus($card) !== 'sucesso') {
            return 'Finalize o rollback com Sucesso antes de concluir.';
        }
        if ($card['tecnico_id'] === null && trim((string)$card['tecnico_nome_legado']) === '') {
            return 'Informe o técnico responsável antes de concluir.';
        }
        $open = self::openTasks($card);
        if ($open === []) {
            return null;
        }
        $last = array_pop($open);
        return 'Feche antes de concluir: ' . ($open === [] ? $last : implode(', ', $open) . ' e ' . $last) . '.';
    }

    /**
     * Campos que mudam ao mover para uma coluna (moveTo do v5.2). null = a
     * coluna ja e a atual (nada muda).
     */
    public static function moveChanges(array $card, string $stage, DateTimeImmutable $now): ?array {
        if (!in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Etapa inválida.');
        }
        if ($stage === self::stageOf($card, $now)) {
            return null;
        }
        $uncancelChg = $card['chg_status'] === 'cancelada'
            ? ['chg_status' => trim((string)$card['chg_numero']) !== '' ? 'pendente' : null]
            : [];
        if ($stage === 'done') {
            return ['concluida' => true, 'concluida_em' => $now->format('Y-m-d H:i:s.v')]
                + ($card['service_status'] === 'cancelado' ? ['service_status' => 'aprovado'] : [])
                + $uncancelChg;
        }
        if ($stage === 'cancel') {
            return ['service_status' => 'cancelado'];
        }
        $changes = ['concluida' => false]
            + ($card['service_status'] === 'cancelado' ? ['service_status' => 'aguardando'] : [])
            + $uncancelChg;
        $auto = self::autoStage(array_merge($card, $changes), $now);
        return $changes + ($stage === $auto
            ? ['stage_manual' => null, 'stage_manual_auto' => null]
            : ['stage_manual' => $stage, 'stage_manual_auto' => $auto]);
    }

    /**
     * Excluir o rollback mantem o card na etapa atual (grava o manual se
     * preciso), como no v5.2. Devolve os campos de etapa.
     */
    public static function keepStageChanges(array $before, array $after, DateTimeImmutable $now): array {
        $current = self::stageOf($before, $now);
        if (!isset(self::RANK[$current])) {
            return [];
        }
        $auto = self::autoStage($after, $now);
        return $auto === $current
            ? ['stage_manual' => null, 'stage_manual_auto' => null]
            : ['stage_manual' => $current, 'stage_manual_auto' => $auto];
    }

    public const MAX_ATTEMPTS = 50;

    /**
     * "+ Novo patch": a tentativa atual vai para o historico (so os endpoints
     * preenchidos, com a data de registro) e abre outra com um endpoint vazio
     * e so a data de hoje (horario pendente).
     */
    public static function newPatchAttempts(array $card, DateTimeImmutable $now): array {
        $attempts = $card['attempts'];
        if (count($attempts) >= self::MAX_ATTEMPTS) {
            throw new DomainException('Limite de ' . self::MAX_ATTEMPTS . ' tentativas de patch neste card.');
        }
        $last = count($attempts) - 1;
        $attempts[$last]['endpoints'] = array_values(array_filter(
            $attempts[$last]['endpoints'] ?? [],
            static fn (array $e): bool => ($e['url'] ?? '') !== '' || ($e['start_at'] ?? null) !== null || ($e['start_date'] ?? null) !== null
        ));
        $attempts[$last]['registered_at'] = $now->format('Y-m-d H:i:s.v');
        $attempts[] = [
            'attempt_no' => (int)$attempts[$last]['attempt_no'] + 1,
            'patch_status' => null,
            'reagendando' => true,
            'registered_at' => null,
            'endpoints' => [['url' => '', 'start_at' => null, 'start_date' => $now->format('Y-m-d')]],
        ];
        return $attempts;
    }

    /**
     * Excluir a tentativa n (1 = a primeira): a atual devolve a anterior como
     * atual; do historico, as outras sao renumeradas. A unica tentativa volta
     * vazia.
     */
    public static function deleteAttempt(array $card, int $number): array {
        $attempts = array_values($card['attempts']);
        if ($number < 1 || $number > count($attempts)) {
            throw new InvalidArgumentException('Tentativa inexistente.');
        }
        array_splice($attempts, $number - 1, 1);
        if ($attempts === []) {
            return [['attempt_no' => 1, 'patch_status' => null, 'reagendando' => false, 'registered_at' => null, 'endpoints' => []]];
        }
        foreach (array_keys($attempts) as $k) {
            $attempts[$k]['attempt_no'] = $k + 1;
        }
        $attempts[count($attempts) - 1]['registered_at'] = null;
        return $attempts;
    }

    /**
     * Duplicar: a copia sem CHG, tecnico, endpoints, rollback, historico,
     * status do patch, etapa manual e conclusao (aprovacao Service volta para
     * Aguardando retorno). Os planos ficam como estao, como no v5.2.
     */
    public static function duplicateBase(array $card): array {
        $copy = $card;
        foreach (['id', 'legacy_id', 'row_version', 'concluida_em', 'archived_at', 'created_at', 'updated_at'] as $field) {
            unset($copy[$field]);
        }
        $copy['chg_numero'] = null;
        $copy['chg_status'] = null;
        $copy['tecnico_id'] = null;
        $copy['tecnico_nome_legado'] = null;
        $copy['service_status'] = 'aguardando';
        $copy['concluida'] = false;
        $copy['stage_manual'] = null;
        $copy['stage_manual_auto'] = null;
        $copy['rollback'] = null;
        $copy['attempts'] = [['attempt_no' => 1, 'patch_status' => null, 'reagendando' => false, 'registered_at' => null, 'endpoints' => []]];
        $copy['plans'] = $card['plans'] ?? [self::emptyPlan()];
        return $copy;
    }

    /** Link aceito: so http(s) absoluto com host, sem espaco nem controle (D-CHG-22). */
    public static function validLink(string $url): bool {
        if ($url === '') {
            return true;
        }
        if (strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        if (preg_match('~^https?://~i', $url) !== 1) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }

    /** Numero da CHG em maiusculas, sem espacos nas pontas; vazio = null. */
    public static function chgNumber(?string $value): ?string {
        $value = strtoupper(trim((string)$value));
        return $value === '' ? null : $value;
    }

    /** Fim do Service Desk automatico (v5.2): inicio + 24 h. */
    public static function autoEnd(string $start): string {
        $at = self::at($start);
        if ($at === null) {
            throw new InvalidArgumentException('Início do Service Desk inválido.');
        }
        return $at->modify('+24 hours')->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Rotulos prontos para a tela (o servidor calcula; a tela so mostra)
    // ------------------------------------------------------------------

    public static function untilLabel(DateTimeImmutable $at, DateTimeImmutable $now): string {
        $seconds = $at->getTimestamp() - $now->getTimestamp();
        if ($seconds <= 0) {
            return 'agora';
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return $hours > 0 ? sprintf('%dh%02d', $hours, $minutes) : $minutes . ' min';
    }

    public static function durationLabel(int $seconds): string {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return $hours > 0 ? sprintf('%dh%02d', $hours, $minutes) : $minutes . ' min';
    }

    public static function shortLabel(?DateTimeImmutable $at): string {
        return $at === null ? '—' : $at->format('d/m H\hi');
    }

    public static function patchLabel(int $attemptNo, int $index, bool $multi): string {
        return ($attemptNo > 1 ? $attemptNo . 'º ' : '') . 'Patch' . ($multi ? ' ' . ($index + 1) : '');
    }

    /** Selo do topo do card, por etapa (textos do v5.2). */
    public static function badge(array $card, DateTimeImmutable $now): array {
        $stage = self::stageOf($card, $now);
        switch ($stage) {
            case 'comite':
                if (trim((string)$card['chg_numero']) === '') {
                    return ['text' => 'Aguardando CHG', 'tone' => 'amber'];
                }
                return $card['chg_status'] === 'aprovada'
                    ? ['text' => 'CHG aprovada', 'tone' => 'green']
                    : ['text' => 'Aguardando aprovação', 'tone' => 'violet'];
            case 'prep':
                if (self::patchStatus($card) === 'erro') {
                    return ['text' => 'Patch com erro · reagendar', 'tone' => 'red'];
                }
                if (self::reagendando($card)) {
                    $at = self::patchStart($card);
                    return ['text' => $at !== null ? 'Novo patch inicia em ' . self::untilLabel($at, $now) : 'Novo patch · horário pendente', 'tone' => 'blue'];
                }
                $start = self::at($card['sd_inicio']);
                return ['text' => $start !== null ? 'Inicia em ' . self::untilLabel($start, $now) : 'Sem início definido', 'tone' => 'blue'];
            case 'exec':
                if (self::rollbackActive($card)) {
                    return ['text' => 'Rollback · ' . self::rollbackStatusLabel(self::rollbackStatus($card)), 'tone' => 'pink'];
                }
                return self::isLate($card, $now) ? ['text' => 'Janela encerrada', 'tone' => 'red'] : ['text' => 'Em execução', 'tone' => 'cyan'];
            case 'valid':
                return ['text' => 'Validando TASKs', 'tone' => 'blue'];
            case 'done':
                return ['text' => 'Concluída', 'tone' => 'green'];
        }
        return ['text' => 'Cancelado', 'tone' => 'red'];
    }

    public static function rollbackStatusLabel(string $status): string {
        return ['em_andamento' => 'Em andamento', 'sucesso' => 'Sucesso', 'erro' => 'Erro'][$status] ?? 'Em andamento';
    }

    /** Aviso do Comite com a CHG aprovada: quando entra em preparacao. */
    public static function waitingNote(array $card, DateTimeImmutable $now): ?string {
        $prep = self::prepAt($card);
        if (!self::waitingWindow($card, $now) || $prep === null) {
            return null;
        }
        return 'CHG aprovada. Inicia preparação ' . self::shortLabel($prep) . ' (faltam ' . self::untilLabel($prep, $now) . ').';
    }

    /**
     * Barra de SLA (inicio ao fim do Service Desk): percentual, "restam" ou
     * "encerrado ha", as marcas dos patches e do rollback (posicao em passos
     * de 5 %, D-CHG-23) e as linhas da tentativa atual. null sem janela valida.
     */
    public static function slaBar(array $card, DateTimeImmutable $now): ?array {
        $start = self::at($card['sd_inicio']);
        $end = self::at($card['sd_fim']);
        if ($start === null || $end === null || $end <= $start) {
            return null;
        }
        $span = $end->getTimestamp() - $start->getTimestamp();
        $progress = min(1, max(0, ($now->getTimestamp() - $start->getTimestamp()) / $span));
        $left = $end->getTimestamp() - $now->getTimestamp();
        $points = [];
        $last = count($card['attempts']) - 1;
        foreach ($card['attempts'] as $k => $attempt) {
            $endpoints = $attempt['endpoints'] ?? [];
            $multi = count($endpoints) > 1;
            foreach ($endpoints as $i => $endpoint) {
                $at = self::at($endpoint['start_at'] ?? null);
                if ($at !== null) {
                    $points[] = ['at' => $at, 'color' => $i % 6, 'label' => self::patchLabel((int)$attempt['attempt_no'], $i, $multi), 'past' => $k < $last, 'status' => $attempt['patch_status'] ?? null, 'rollback' => false];
                }
            }
        }
        if (self::rollbackActive($card)) {
            $at = self::at($card['rollback']['start_at'] ?? null);
            if ($at !== null) {
                $points[] = ['at' => $at, 'color' => 'pink', 'label' => 'Rollback (' . self::rollbackStatusLabel(self::rollbackStatus($card)) . ')', 'past' => false, 'status' => null, 'rollback' => true];
            }
        }
        usort($points, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $ticks = [];
        foreach ($points as $point) {
            $position = min(100, max(0, ($point['at']->getTimestamp() - $start->getTimestamp()) / $span * 100));
            $ticks[] = [
                'step' => (int)(round($position / 5) * 5),
                'color' => $point['color'],
                'state' => $point['past'] ? 'past' : ($point['at'] > $now ? 'future' : 'now'),
                'label' => $point['label'] . ': ' . self::shortLabel($point['at']) . ', ' . (int)round($position) . '% do SLA'
                    . ($point['past'] && $point['status'] !== null ? ' (' . ucfirst($point['status']) . ')' : ''),
            ];
        }
        $current = array_values(array_filter($points, static fn (array $p): bool => !$p['past']));
        usort($current, static fn (array $a, array $b): int => ($b['rollback'] <=> $a['rollback']) ?: ($a['at'] <=> $b['at']));
        $lines = array_map(static fn (array $p): array => [
            'color' => $p['color'],
            'future' => $p['at'] > $now,
            'text' => $p['label'] . ' ' . ($p['at'] > $now ? 'inicia' : 'iniciado') . ' ' . self::shortLabel($p['at']),
        ], $current);
        return [
            'percent' => (int)round($progress * 100),
            'left' => $left > 0 ? 'restam ' . self::durationLabel($left) : 'encerrado há ' . self::durationLabel(-$left),
            'ticks' => $ticks,
            'lines' => $lines,
        ];
    }

    /** O que a tela mostra de etapa e rotulos, calculado no "agora" do servidor. */
    public static function view(array $card, DateTimeImmutable $now): array {
        $card = self::card($card);
        $stage = self::stageOf($card, $now);
        $auto = self::autoStage($card, $now);
        $prep = self::prepAt($card);
        return [
            'stage' => $stage,
            'auto_stage' => $auto,
            'is_manual' => self::isManual($card, $now),
            'late' => self::isLate($card, $now),
            'waiting_window' => self::waitingWindow($card, $now),
            'prep_at' => $prep?->format('Y-m-d H:i:s'),
            'badge' => self::badge($card, $now),
            'note' => self::waitingNote($card, $now),
            'sla' => $stage === 'exec' ? self::slaBar($card, $now) : null,
            'open_tasks' => self::openTasks($card),
            'can_new_patch' => !self::rollbackActive($card) && ($stage === 'exec' || self::patchStatus($card) === 'erro'),
        ];
    }
}
