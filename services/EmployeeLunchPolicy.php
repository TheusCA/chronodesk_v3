<?php
declare(strict_types=1);

/**
 * Almoco do cadastro por contrato (Parte B, F8; desenho, secao 9.10).
 *
 * Funcao pura: recebe o pedido, o estado gravado do cadastro, os contratos ja
 * carregados e a permissao do ator; devolve os valores a gravar e os eventos de
 * auditoria, ou a recusa. Nao consulta banco, arquivo nem relogio, e nao conhece
 * nenhuma duracao real: as duracoes vem da tabela de contratos (11.1; AD1 da
 * Lideranca ainda A DEFINIR).
 *
 * decide($input, $current, $contracts, $canMarkException, $reasons):
 *   $input    jornada_entrada, jornada_saida, almoco_inicio, almoco_fim ('HH:MM'
 *             ou 'HH:MM:SS'; o fim pode faltar) e, so quando vierem no corpo,
 *             contrato_id, almoco_excecao e almoco_excecao_motivo. Chave ausente
 *             = manter o que esta gravado (DL12; a tela legada nao as conhece).
 *   $current  null no cadastro novo; senao contrato_id (?int), almoco_excecao
 *             (bool) e almoco_excecao_motivo (?string) gravados.
 *   $contracts [id => ['ativo' => bool, 'almoco_minutos' => int]], com o
 *             contrato atual e o pedido (quem chama carrega os dois).
 *   $canMarkException  permissao funcionarios.almoco_excecao do ator (DL8).
 *   $reasons  null se a excecao nao exige motivo (AD2 = nao); senao a lista
 *             fechada de codigos (AD2 = sim). Nunca texto livre.
 *
 * Regras, nesta ordem:
 *   1. Jornada e inicio do almoco no formato; jornada com entrada antes da saida.
 *   2. Contrato: ausente mantem; null ou '' tira; inteiro positivo de contrato
 *      conhecido; inativo so se ja for o atual (DL4).
 *   3. Excecao: ausente mantem (novo: nao); booleano estrito. Mudar a marca, ou
 *      o motivo com a marca ligada, sem a permissao: 403, com o evento de negacao.
 *   4. Motivo: so com lista; obrigatorio com a excecao, nulo sem ela.
 *   5. Fim: com contrato e sem excecao, inicio + duracao (o fim enviado e
 *      ignorado, DL5); senao, o fim enviado (decisao 3 e excecao).
 *   6. Almoco dentro da jornada, para todos (DL7):
 *      jornada_entrada <= almoco_inicio < almoco_fim <= jornada_saida.
 *
 * Retorno: ['ok' => true, 'valores' => [...], 'fim_calculado' => bool,
 * 'eventos' => [...]] ou ['ok' => false, 'status' => 400|403|409, 'campo' => ...,
 * 'mensagem' => ..., 'negacao' => ?evento]. Dado de quem chama fora do formato
 * ($current, $contracts, $reasons) e UnexpectedValueException.
 */
final class EmployeeLunchPolicy {
    public const MINUTES_PER_DAY = 1440;

    public const MSG_SHIFT = 'Jornada inválida.';
    public const MSG_START = 'Horário de início do almoço inválido.';
    public const MSG_END = 'Horário de fim do almoço inválido.';
    public const MSG_END_MISSING = 'Informe o fim do almoço.';
    public const MSG_CONTRACT = 'Contrato inválido.';
    public const MSG_CONTRACT_INACTIVE = 'Contrato inativo não pode ser escolhido.';
    public const MSG_CONTRACT_DURATION = 'Contrato com duração inválida.';
    public const MSG_EXCEPTION = 'Marca de exceção inválida.';
    public const MSG_EXCEPTION_DENIED = 'Seu perfil não pode marcar ou desmarcar a exceção do almoço.';
    public const MSG_REASON = 'Motivo da exceção inválido.';
    public const MSG_REASON_MISSING = 'Informe o motivo da exceção.';
    public const MSG_PAST_MIDNIGHT = 'O fim do almoço pelo contrato passa da meia-noite.';
    public const MSG_START_BEFORE_END = 'O início do almoço deve ser anterior ao fim.';
    public const MSG_START_OUTSIDE = 'O almoço precisa começar dentro da jornada.';
    public const MSG_END_OUTSIDE = 'O almoço precisa terminar dentro da jornada.';

    /** 'HH:MM' ou 'HH:MM:SS' -> minutos do dia; fora do formato, null. */
    public static function minutes(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m) !== 1) {
            return null;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    public static function format(int $minutes): string {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Fim pela duracao; null se o inicio for invalido ou o fim passar de 23:59. */
    public static function endFor(string $start, int $durationMinutes): ?string {
        $startMinutes = self::minutes($start);
        if ($startMinutes === null || $durationMinutes < 1) {
            return null;
        }
        $end = $startMinutes + $durationMinutes;
        return $end < self::MINUTES_PER_DAY ? self::format($end) : null;
    }

    /** Almoco dentro da jornada (DL7): null se cabe; senao [campo, mensagem]. */
    public static function withinShift(string $shiftStart, string $shiftEnd, string $lunchStart, string $lunchEnd): ?array {
        $entrada = self::minutes($shiftStart);
        $saida = self::minutes($shiftEnd);
        $inicio = self::minutes($lunchStart);
        $fim = self::minutes($lunchEnd);
        if ($entrada === null || $saida === null || $entrada >= $saida) {
            return ['jornada_entrada', self::MSG_SHIFT];
        }
        if ($inicio === null) {
            return ['almoco_inicio', self::MSG_START];
        }
        if ($fim === null) {
            return ['almoco_fim', self::MSG_END];
        }
        if ($inicio < $entrada || $inicio >= $saida) {
            return ['almoco_inicio', self::MSG_START_OUTSIDE];
        }
        if ($inicio >= $fim) {
            return ['almoco_fim', self::MSG_START_BEFORE_END];
        }
        if ($fim > $saida) {
            return ['almoco_fim', self::MSG_END_OUTSIDE];
        }
        return null;
    }

    public static function decide(array $input, ?array $current, array $contracts, bool $canMarkException, ?array $reasons = null): array {
        $current = self::current($current);
        $contracts = self::contracts($contracts);
        $reasons = self::reasons($reasons);

        // 1. Jornada e inicio.
        $entrada = self::minutes($input['jornada_entrada'] ?? null);
        $saida = self::minutes($input['jornada_saida'] ?? null);
        if ($entrada === null || $saida === null || $entrada >= $saida) {
            return self::refuse(400, 'jornada_entrada', self::MSG_SHIFT);
        }
        $inicioMinutes = self::minutes($input['almoco_inicio'] ?? null);
        if ($inicioMinutes === null) {
            return self::refuse(400, 'almoco_inicio', self::MSG_START);
        }
        $inicio = self::format($inicioMinutes);

        // 2. Contrato.
        $contractId = $current['contrato_id'];
        if (array_key_exists('contrato_id', $input)) {
            $raw = $input['contrato_id'];
            if ($raw === null || $raw === '') {
                $contractId = null;
            } else {
                $parsed = self::positiveInt($raw);
                if ($parsed === null || !array_key_exists($parsed, $contracts)) {
                    return self::refuse(400, 'contrato_id', self::MSG_CONTRACT);
                }
                if (!$contracts[$parsed]['ativo'] && $parsed !== $current['contrato_id']) {
                    return self::refuse(400, 'contrato_id', self::MSG_CONTRACT_INACTIVE);
                }
                $contractId = $parsed;
            }
        }

        // 3. Excecao.
        $exception = $current['almoco_excecao'];
        if (array_key_exists('almoco_excecao', $input)) {
            $parsed = self::strictBool($input['almoco_excecao']);
            if ($parsed === null) {
                return self::refuse(400, 'almoco_excecao', self::MSG_EXCEPTION);
            }
            $exception = $parsed;
        }

        // 4. Motivo (lista fechada, so com AD2 = sim).
        $reason = null;
        $reasonInput = array_key_exists('almoco_excecao_motivo', $input) ? $input['almoco_excecao_motivo'] : null;
        if ($reasonInput !== null && $reasonInput !== '') {
            if ($reasons === null || !is_string($reasonInput) || !in_array($reasonInput, $reasons, true)) {
                return self::refuse(400, 'almoco_excecao_motivo', self::MSG_REASON);
            }
        }
        if ($exception && $reasons !== null) {
            $reason = ($reasonInput !== null && $reasonInput !== '') ? $reasonInput : ($current['almoco_excecao'] ? $current['almoco_excecao_motivo'] : null);
            if ($reason === null) {
                return self::refuse(400, 'almoco_excecao_motivo', self::MSG_REASON_MISSING);
            }
        }

        $exceptionChanged = $exception !== $current['almoco_excecao']
            || ($exception && $reason !== $current['almoco_excecao_motivo']);
        if ($exceptionChanged && !$canMarkException) {
            return self::refuse(403, 'almoco_excecao', self::MSG_EXCEPTION_DENIED, ['acao' => 'FUNCIONARIO_ALTERACAO_NEGADA', 'severidade' => 'WARNING', 'contexto' => 'almoco_excecao']);
        }

        // 5. Fim.
        $calculated = $contractId !== null && !$exception;
        if ($calculated) {
            $duration = $contracts[$contractId]['almoco_minutos'];
            if ($duration < 1 || $duration >= self::MINUTES_PER_DAY) {
                return self::refuse(409, 'contrato_id', self::MSG_CONTRACT_DURATION);
            }
            $fim = self::endFor($inicio, $duration);
            if ($fim === null) {
                return self::refuse(400, 'almoco_inicio', self::MSG_PAST_MIDNIGHT);
            }
        } else {
            $rawEnd = $input['almoco_fim'] ?? null;
            if ($rawEnd === null || $rawEnd === '') {
                return self::refuse(400, 'almoco_fim', self::MSG_END_MISSING);
            }
            $fimMinutes = self::minutes($rawEnd);
            if ($fimMinutes === null) {
                return self::refuse(400, 'almoco_fim', self::MSG_END);
            }
            $fim = self::format($fimMinutes);
        }

        // 6. Dentro da jornada.
        $outside = self::withinShift(self::format($entrada), self::format($saida), $inicio, $fim);
        if ($outside !== null) {
            return self::refuse(400, $outside[0], $outside[1]);
        }

        $events = [];
        if ($contractId !== $current['contrato_id']) {
            $events[] = ['acao' => 'FUNCIONARIO_CONTRATO_ALTERADO', 'severidade' => 'INFO', 'antes' => $current['contrato_id'], 'depois' => $contractId];
        }
        if ($exceptionChanged) {
            $events[] = [
                'acao' => 'FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO', 'severidade' => 'WARNING',
                'antes' => $current['almoco_excecao'], 'depois' => $exception,
                'motivo_antes' => $current['almoco_excecao'] ? $current['almoco_excecao_motivo'] : null, 'motivo_depois' => $reason,
                'almoco_inicio' => $inicio, 'almoco_fim' => $fim,
            ];
        }

        return [
            'ok' => true,
            'valores' => [
                'contrato_id' => $contractId,
                'almoco_excecao' => $exception,
                'almoco_excecao_motivo' => $reason,
                'almoco_inicio' => $inicio,
                'almoco_fim' => $fim,
            ],
            'fim_calculado' => $calculated,
            'eventos' => $events,
        ];
    }

    /**
     * Mensagens de auditoria dos eventos (DL10): so IDs, perfil, horarios e
     * codigos da lista fechada; nunca nome de pessoa nem de contrato.
     * Devolve [[acao, mensagem, severidade], ...].
     */
    public static function auditMessages(array $events, int $employeeId, string $actorRole): array {
        $role = preg_match('/^[a-z_]{1,30}$/', $actorRole) === 1 ? $actorRole : 'desconhecido';
        $contract = static fn ($id) => $id === null ? 'nenhum' : 'ID ' . (int)$id;
        $flag = static fn ($value) => $value ? 'sim' : 'nao';
        $messages = [];
        foreach ($events as $event) {
            $action = $event['acao'] ?? '';
            if ($action === 'FUNCIONARIO_CONTRATO_ALTERADO') {
                $message = "Contrato do funcionario ID {$employeeId} alterado de {$contract($event['antes'])} para {$contract($event['depois'])} por {$role}";
            } elseif ($action === 'FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO') {
                $message = "Excecao de almoco do funcionario ID {$employeeId} alterada de {$flag($event['antes'])} para {$flag($event['depois'])} por {$role}; almoco {$event['almoco_inicio']}-{$event['almoco_fim']}";
                if ($event['motivo_antes'] !== null || $event['motivo_depois'] !== null) {
                    $message .= '; motivo ' . ($event['motivo_antes'] ?? 'nenhum') . ' para ' . ($event['motivo_depois'] ?? 'nenhum');
                }
            } elseif ($action === 'FUNCIONARIO_ALTERACAO_NEGADA') {
                $message = "contexto={$event['contexto']} perfil_ator={$role} funcionario_id={$employeeId}";
            } else {
                throw new UnexpectedValueException('Evento de auditoria fora da lista do almoco.');
            }
            $messages[] = [$action, $message, $event['severidade']];
        }
        return $messages;
    }

    private static function refuse(int $status, string $field, string $message, ?array $denial = null): array {
        return ['ok' => false, 'status' => $status, 'campo' => $field, 'mensagem' => $message, 'negacao' => $denial];
    }

    private static function positiveInt(mixed $value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) === 1) {
            return (int)$value;
        }
        return null;
    }

    private static function strictBool(mixed $value): ?bool {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        return null;
    }

    private static function current(?array $current): array {
        if ($current === null) {
            return ['contrato_id' => null, 'almoco_excecao' => false, 'almoco_excecao_motivo' => null];
        }
        $contractId = $current['contrato_id'] ?? null;
        if ($contractId !== null && (!is_int($contractId) || $contractId < 1)) {
            throw new UnexpectedValueException('Contrato gravado fora do formato.');
        }
        if (!is_bool($current['almoco_excecao'] ?? null)) {
            throw new UnexpectedValueException('Marca de excecao gravada fora do formato.');
        }
        $reason = $current['almoco_excecao_motivo'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            throw new UnexpectedValueException('Motivo gravado fora do formato.');
        }
        return ['contrato_id' => $contractId, 'almoco_excecao' => $current['almoco_excecao'], 'almoco_excecao_motivo' => $reason];
    }

    private static function contracts(array $contracts): array {
        $normalized = [];
        foreach ($contracts as $id => $contract) {
            if (!is_int($id) || $id < 1 || !is_array($contract) || !is_bool($contract['ativo'] ?? null) || !is_int($contract['almoco_minutos'] ?? null)) {
                throw new UnexpectedValueException('Contrato carregado fora do formato.');
            }
            $normalized[$id] = ['ativo' => $contract['ativo'], 'almoco_minutos' => $contract['almoco_minutos']];
        }
        return $normalized;
    }

    private static function reasons(?array $reasons): ?array {
        if ($reasons === null) {
            return null;
        }
        foreach ($reasons as $code) {
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code) !== 1) {
                throw new UnexpectedValueException('Lista de motivos fora do formato.');
            }
        }
        return array_values($reasons);
    }
}
