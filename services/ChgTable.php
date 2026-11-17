<?php
declare(strict_types=1);

require_once __DIR__ . '/ChgRules.php';

/**
 * [9.17] A "tabela" de um card (tableRows do v5.2): as linhas que a tela
 * copia (HTML e texto, para e-mail, Teams e Excel) e que o Excel exportado
 * mostra (aba Cards, um bloco por card; aba Base, uma linha por card). O
 * servidor monta uma vez; a tela so usa.
 *
 * Linha: ['k' => rotulo, 'v' => valor, 'section' => bool, 'rollback' => bool,
 *         'bold' => bool, 'color' => ?string (hex do v5.2), 'link' => bool]
 */
final class ChgTable {
    public const SERVICE_LABELS = ['aguardando' => 'Aguardando retorno', 'aprovado' => 'Aprovado', 'cancelado' => 'Cancelado'];
    public const CHG_LABELS = ['pendente' => 'Pendente', 'aprovada' => 'Aprovada', 'cancelada' => 'Cancelada'];
    public const PATCH_LABELS = ['sucesso' => 'Sucesso', 'parcial' => 'Parcial', 'erro' => 'Erro'];
    public const TIPO_LABELS = ['padrao' => 'Padrão', 'emergencial' => 'Emergencial'];
    private const SERVICE_COLORS = ['Aprovado' => '548235', 'Cancelado' => 'C00000', 'Aguardando retorno' => 'BF8F00'];
    private const CHG_COLORS = ['Aprovada' => '548235', 'Pendente' => 'BF8F00', 'Cancelada' => 'C00000'];
    private const PATCH_COLORS = ['Sucesso' => '548235', 'Parcial' => 'BF8F00', 'Erro' => 'C00000'];
    private const ROLLBACK_COLORS = ['Sucesso' => '548235', 'Erro' => 'C00000', 'Em andamento' => '863873'];
    private const PLAN_LABELS = ['implantacao' => 'Plano de Implantação', 'backup' => 'Plano de Backup', 'rollback' => 'Plano de Rollback'];

    /** dd/mm/aaaa HHhMM (fmtDT do v5.2); vazio sem data. */
    public static function dateTimeLabel(?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($value, 0, 19));
        return $at === false ? '' : $at->format('d/m/Y H\hi');
    }

    public static function carteiraLabel(array $carteira): string {
        $parts = array_filter([trim((string)($carteira['codigo'] ?? '')), trim((string)($carteira['nome'] ?? ''))], static fn (string $v): bool => $v !== '');
        return $parts === [] ? 'Carteira não informada' : implode(' - ', $parts);
    }

    /** Carteiras com os PAs entre parenteses quando ha mais de uma (allCarteirasPas). */
    public static function carteirasLabel(array $carteiras): array {
        $multi = count($carteiras) > 1;
        return array_map(static fn (array $c): string => self::carteiraLabel($c) . ($multi && ($c['pas'] ?? null) !== null ? ' (' . $c['pas'] . ' PAs)' : ''), $carteiras);
    }

    private static function row(string $k, ?string $v, array $extra = []): array {
        return array_merge(['k' => $k, 'v' => $v ?? '', 'section' => false, 'rollback' => false, 'bold' => false, 'color' => null, 'link' => false], $extra);
    }

    private static function section(string $k, bool $rollback = false): array {
        return ['k' => $k, 'v' => '', 'section' => true, 'rollback' => $rollback, 'bold' => true, 'color' => null, 'link' => false];
    }

    /** Linhas do card, na ordem do v5.2. $card: o card apresentado (com 'view'). */
    public static function rows(array $card): array {
        $service = self::SERVICE_LABELS[$card['service_status']] ?? '';
        $chgStatus = self::CHG_LABELS[$card['chg_status'] ?? ''] ?? '';
        $patch = self::PATCH_LABELS[ChgRules::patchStatus($card) ?? ''] ?? '';
        $tecnico = $card['tecnico_nome'] ?? $card['tecnico_nome_legado'] ?? '';
        $pas = (int)($card['qtd_pas'] ?? 0);
        $rows = [
            self::row('Solicitante', $card['solicitante']),
            self::row('Descrição', $card['descricao']),
            self::row('Carteiras e códigos', implode('; ', self::carteirasLabel($card['carteiras'] ?? []))),
            self::row('Locais impactados', implode('; ', $card['locais'] ?? [])),
            self::row('Quantidade de Pas', $pas > 0 ? (string)$pas : ''),
            self::row('Versão/caminho', $card['versao']),
            self::row('Versão anterior do software', $card['versao_anterior']),
            self::row('Link Planilha com Pas', $card['link_planilha'], ['link' => true]),
            self::row('Tipo de Execução', self::TIPO_LABELS[$card['tipo']] ?? ''),
            self::row('Data sugestão', self::dateTimeLabel($card['sugestao_at'] ?? null)),
            self::row('Inicio ServiceDesk', self::dateTimeLabel($card['sd_inicio']), ['bold' => true]),
            self::row('Fim ServiceDesk', self::dateTimeLabel($card['sd_fim']), ['bold' => true]),
            self::row('Aprovação Service', $service, ['color' => self::SERVICE_COLORS[$service] ?? null]),
            self::row('Etapa', ChgRules::STAGE_TITLES[$card['view']['stage']] ?? '', ['bold' => true]),
            self::row('CHG', $card['chg_numero'], ['bold' => true]),
            self::row('Status CHG', $chgStatus, ['color' => self::CHG_COLORS[$chgStatus] ?? null]),
            self::row('Tecnico Responsavel', (string)$tecnico),
            self::row('Status do Patch', $patch, ['color' => self::PATCH_COLORS[$patch] ?? null]),
        ];
        foreach ($card['plans'] as $index => $plan) {
            $rows[] = self::section('Plano ' . ($index + 1) . ' da CHG');
            foreach (self::PLAN_LABELS as $field => $label) {
                $rows[] = self::row($label, $plan[$field]);
            }
            foreach (ChgRules::TASKS as [$statusField, $planField, $label]) {
                $closed = $plan[$statusField] === 'fechada';
                $rows[] = self::row($label, ChgRules::taskNeeded($plan[$planField]) ? ($closed ? 'Fechada' : 'Pendente') : '', ['color' => $closed ? '548235' : null]);
            }
        }
        if ($card['view']['stage'] === 'done' && ($card['concluida_em'] ?? null) !== null) {
            $rows[] = self::row('Concluída em', self::dateTimeLabel($card['concluida_em']));
        }
        if (ChgRules::rollbackActive($card)) {
            $status = ChgRules::rollbackStatusLabel(ChgRules::rollbackStatus($card));
            $rows[] = self::section('Rollback', true);
            $rows[] = self::row('Rollback – Endpoint', $card['rollback']['url'] ?? '', ['link' => true]);
            $rows[] = self::row('Rollback – Início', self::dateTimeLabel($card['rollback']['start_at'] ?? null), ['bold' => true]);
            $rows[] = self::row('Rollback – Situação', $status, ['color' => self::ROLLBACK_COLORS[$status] ?? null]);
        }
        $last = count($card['attempts']) - 1;
        $attempts = [];
        foreach ($card['attempts'] as $k => $attempt) {
            $endpoints = array_values(array_filter($attempt['endpoints'], static fn (array $e): bool => $e['url'] !== '' || $e['start_at'] !== null || $e['start_date'] !== null));
            if ($endpoints !== [] || $k < $last) {
                $attempts[] = ['n' => (int)$attempt['attempt_no'], 'past' => $k < $last, 'status' => $attempt['patch_status'], 'endpoints' => $endpoints];
            }
        }
        $total = array_sum(array_map(static fn (array $a): int => count($a['endpoints']), $attempts));
        if ($total > 0) {
            $rows[] = self::section('Endpoints e início do patch' . (count($attempts) > 1 ? ' (' . count($attempts) . ' tentativas)' : " ({$total})"));
            foreach ($attempts as $attempt) {
                $multi = count($attempt['endpoints']) > 1;
                foreach ($attempt['endpoints'] as $i => $endpoint) {
                    $label = ChgRules::patchLabel($attempt['n'], $i, $multi);
                    $start = $endpoint['start_at'] !== null
                        ? self::dateTimeLabel($endpoint['start_at'])
                        : ($endpoint['start_date'] !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', $endpoint['start_date'])->format('d/m/Y') . ' (horário pendente)' : '');
                    $rows[] = self::row($label . ' – Endpoint', $endpoint['url'], ['link' => true]);
                    $rows[] = self::row($label . ' – Início', $start, ['bold' => true]);
                }
                if ($attempt['past']) {
                    $status = self::PATCH_LABELS[$attempt['status'] ?? ''] ?? 'Sem status';
                    $rows[] = self::row($attempt['n'] . 'ª tentativa – Status', $status, ['color' => self::PATCH_COLORS[$status] ?? null]);
                }
            }
        }
        return $rows;
    }

    /** Texto com tabulacao (tableText do v5.2), para colar em qualquer lugar. */
    public static function text(array $rows): string {
        $lines = ['Dados'];
        foreach ($rows as $row) {
            $lines[] = $row['section'] ? $row['k'] : $row['k'] . "\t" . preg_replace('/\s*\n\s*/', ' / ', $row['v']);
        }
        return implode("\n", $lines);
    }
}
