<?php
declare(strict_types=1);

require_once __DIR__ . '/ScheduleRulePlanner.php';

/**
 * Efeitos do arquivamento de um tecnico (Parte B, F4; desenho, secao 9.6).
 *
 * Funcao pura: recebe o cadastro e as linhas JA carregadas (e travadas) de UM
 * colaborador, a entrada dele no estado de pausas e o instante; devolve o
 * plano de gravacao e as contagens de efeitos. Nao consulta banco, arquivo
 * nem relogio. "Hoje" e a data de $now no fuso de $now: quem chama passa
 * new DateTimeImmutable('now') (fuso do PHP, America/Sao_Paulo, A4), e a
 * virada da meia-noite vem dai, nunca de uma segunda leitura do relogio.
 *
 * Entrada ($input):
 *   employee   ['id' => int, 'ativo' => int|bool, 'arquivado_em' => ?string]
 *   rules      regras de escala no formato do ScheduleRulePlanner, com employee_id
 *   exceptions [['id', 'employee_id', 'exception_date', 'exception_type']]
 *   absences   [['id', 'employee_id', 'starts_on', 'ends_on', 'period_type', 'status']]
 *   pa_links   [['id', 'employee_id', 'valid_from', 'valid_until', 'active']]
 *   pause      ?array: entrada do estado.json (em_pausa, inicio_pausa,
 *              motivo_pausa, status_aprovacao), ou null
 *
 * Regras (D5 a D9, com o ajuste da D8 de 2026-10-13):
 *   - Cadastro ativo: arquiva. Inativo com arquivado_em nulo (anterior ao
 *     F4): arquiva de novo, com os efeitos e a data (regularizacao, D5).
 *     Inativo com data (arquivado pelo F4): DomainException (409).
 *   - PA aberto ou futuro: comecou antes de hoje -> valid_until = ontem;
 *     comeca hoje ou depois -> active = 0. Encerrado antes de hoje: nada.
 *   - Regras: ScheduleRulePlanner::planRemove (F1).
 *   - Excecoes de hoje em diante: apagadas.
 *   - Ausencia aprovada que termina hoje ou depois: comecou antes de hoje ->
 *     ends_on = ontem; comeca hoje ou depois (inclusive parcial e de varios
 *     dias) -> cancelada.
 *   - Pausa aprovada em andamento -> finalizada agora; solicitacao pendente ->
 *     descartada; entrada com pausa ilegivel -> limpa, sem historico.
 * Invariante, conferida antes de devolver: nenhum periodo gravado fica com
 * fim antes do inicio (ends_on < starts_on, valid_until < valid_from,
 * effective_until < effective_from). Violacao e LogicException: nada e
 * gravado. Dado fora do formato (data, id, outro colaborador) e
 * UnexpectedValueException, tambem antes de qualquer gravacao.
 */
final class EmployeeArchivePlanner {
    public const ALREADY_ARCHIVED_MESSAGE = 'Este cadastro já foi arquivado em %s. Restaure-o antes de arquivar de novo.';

    public static function plan(array $input, DateTimeImmutable $now): array {
        $today = $now->format('Y-m-d');
        $yesterday = ScheduleRulePlanner::addDays($today, -1);
        $employee = self::employee($input['employee'] ?? null);
        $employeeId = $employee['id'];

        $paRows = self::rows($input['pa_links'] ?? [], $employeeId, 'pa_links');
        $rules = self::rows($input['rules'] ?? [], $employeeId, 'rules');
        foreach ($rules as $rule) {
            self::date($rule['effective_from'] ?? null, 'rules.effective_from');
            if (!array_key_exists('effective_until', $rule)) {
                throw new UnexpectedValueException('Regra sem fim de vigencia no plano de arquivamento.');
            }
            if ($rule['effective_until'] !== null) {
                self::date($rule['effective_until'], 'rules.effective_until');
            }
        }
        $absenceRows = self::rows($input['absences'] ?? [], $employeeId, 'absences');

        $pa = self::paPlan($paRows, $today, $yesterday);
        $rulePlan = ScheduleRulePlanner::planRemove($rules, $today);
        $exceptions = self::exceptionPlan(self::rows($input['exceptions'] ?? [], $employeeId, 'exceptions'), $today);
        $absences = self::absencePlan($absenceRows, $today, $yesterday);
        $pause = self::pausePlan($input['pause'] ?? null, $now);

        $plan = [
            'today' => $today,
            'employee' => [
                'id' => $employeeId,
                'regularizacao' => $employee['regularizacao'],
                'arquivado_em' => $now->format('Y-m-d H:i:s'),
            ],
            'pa' => $pa,
            'rules' => $rulePlan,
            'exceptions' => $exceptions,
            'absences' => $absences,
            'pause' => $pause,
            'efeitos' => [
                'pa_encerrados' => count($pa['close']),
                'pa_removidos' => count($pa['remove']),
                'regras_encerradas' => $rulePlan['close'] !== null ? 1 : 0,
                'regras_removidas' => count($rulePlan['delete']),
                'excecoes_removidas' => count($exceptions['delete']),
                'ausencias_canceladas' => count($absences['cancel']),
                'ausencias_cortadas' => count($absences['cut']),
                'pausa_finalizada' => $pause['action'] === 'finalize',
                'solicitacao_descartada' => $pause['action'] === 'discard_request',
            ],
        ];
        self::assertValidPeriods($plan, self::indexById($paRows), self::indexById($rules), self::indexById($absenceRows));
        return $plan;
    }

    /**
     * Linhas do plano com o periodo gravado invertido, ou sem a linha de
     * origem (vazio = ok). Usada pela invariante e pelo QA.
     */
    public static function invertedPeriods(array $plan, array $paById, array $rulesById, array $absencesById): array {
        $problems = [];
        foreach ($plan['pa']['close'] as $close) {
            $from = $paById[$close['id']]['valid_from'] ?? null;
            if (!is_string($from) || $close['valid_until'] < $from) {
                $problems[] = "pa {$close['id']}: valid_until {$close['valid_until']} < valid_from {$from}";
            }
        }
        if ($plan['rules']['close'] !== null) {
            $close = $plan['rules']['close'];
            $from = $rulesById[$close['id']]['effective_from'] ?? null;
            if (!is_string($from) || $close['effective_until'] < $from) {
                $problems[] = "regra {$close['id']}: effective_until {$close['effective_until']} < effective_from {$from}";
            }
        }
        foreach ($plan['absences']['cut'] as $cut) {
            $from = $absencesById[$cut['id']]['starts_on'] ?? null;
            if (!is_string($from) || $cut['ends_on'] < $from) {
                $problems[] = "ausencia {$cut['id']}: ends_on {$cut['ends_on']} < starts_on {$from}";
            }
        }
        return $problems;
    }

    private static function assertValidPeriods(array $plan, array $paById, array $rulesById, array $absencesById): void {
        $problems = self::invertedPeriods($plan, $paById, $rulesById, $absencesById);
        if ($problems !== []) {
            throw new LogicException('Plano de arquivamento com periodo invertido: ' . implode('; ', $problems));
        }
    }

    private static function employee(mixed $employee): array {
        if (!is_array($employee)) {
            throw new UnexpectedValueException('Cadastro ausente no plano de arquivamento.');
        }
        $id = self::id($employee['id'] ?? null, 'employee.id');
        $active = (int)($employee['ativo'] ?? 0) === 1;
        $archivedAt = $employee['arquivado_em'] ?? null;
        if ($active) {
            return ['id' => $id, 'regularizacao' => false];
        }
        if ($archivedAt === null || $archivedAt === '') {
            return ['id' => $id, 'regularizacao' => true];
        }
        $when = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$archivedAt);
        throw new DomainException(sprintf(self::ALREADY_ARCHIVED_MESSAGE, $when ? $when->format('d/m/Y') : 'data registrada'));
    }

    private static function paPlan(array $links, string $today, string $yesterday): array {
        $close = [];
        $remove = [];
        foreach ($links as $link) {
            $from = self::date($link['valid_from'] ?? null, 'pa_links.valid_from');
            $until = ($link['valid_until'] ?? null) === null ? null : self::date($link['valid_until'], 'pa_links.valid_until');
            if ((int)($link['active'] ?? 0) !== 1 || ($until !== null && $until < $today)) {
                continue;
            }
            if ($from < $today) {
                $close[] = ['id' => (int)$link['id'], 'valid_until' => $yesterday, 'previous_until' => $until];
            } else {
                $remove[] = ['id' => (int)$link['id'], 'valid_from' => $from, 'valid_until' => $until];
            }
        }
        return ['close' => $close, 'remove' => $remove];
    }

    private static function exceptionPlan(array $exceptions, string $today): array {
        $delete = [];
        foreach ($exceptions as $exception) {
            $date = self::date($exception['exception_date'] ?? null, 'exceptions.exception_date');
            if ($date >= $today) {
                $delete[] = ['id' => (int)$exception['id'], 'exception_date' => $date, 'exception_type' => (string)($exception['exception_type'] ?? '')];
            }
        }
        return ['delete' => $delete];
    }

    private static function absencePlan(array $absences, string $today, string $yesterday): array {
        $cancel = [];
        $cut = [];
        foreach ($absences as $absence) {
            $from = self::date($absence['starts_on'] ?? null, 'absences.starts_on');
            $until = self::date($absence['ends_on'] ?? null, 'absences.ends_on');
            if (($absence['status'] ?? null) !== 'approved' || $until < $today) {
                continue;
            }
            if ($from >= $today) {
                $cancel[] = ['id' => (int)$absence['id'], 'starts_on' => $from, 'ends_on' => $until, 'period_type' => (string)($absence['period_type'] ?? 'full_day')];
            } else {
                $cut[] = ['id' => (int)$absence['id'], 'ends_on' => $yesterday, 'previous_ends_on' => $until];
            }
        }
        return ['cancel' => $cancel, 'cut' => $cut];
    }

    /**
     * Pausa do estado.json. Inicio so no formato que o gravador usa
     * (DATE_ATOM, format('c')): texto relativo como "now" nunca vira data.
     */
    private static function pausePlan(mixed $entry, DateTimeImmutable $now): array {
        if (!is_array($entry)) {
            return ['action' => 'none'];
        }
        $inPause = ($entry['em_pausa'] ?? false) === true;
        $status = $entry['status_aprovacao'] ?? null;
        if (!$inPause) {
            return $status === 'pendente' ? ['action' => 'discard_request'] : ['action' => 'none'];
        }
        $start = is_string($entry['inicio_pausa'] ?? null)
            ? DateTimeImmutable::createFromFormat(DATE_ATOM, $entry['inicio_pausa'])
            : false;
        if ($status !== 'aprovado' || $start === false || $start->format(DATE_ATOM) !== $entry['inicio_pausa']) {
            return ['action' => 'clear_invalid'];
        }
        return [
            'action' => 'finalize',
            'inicio' => $start->setTimezone($now->getTimezone())->format('Y-m-d H:i:s'),
            'fim' => $now->format('Y-m-d H:i:s'),
            'duracao_segundos' => max(0, $now->getTimestamp() - $start->getTimestamp()),
            'motivo_pausa' => is_string($entry['motivo_pausa'] ?? null) ? $entry['motivo_pausa'] : null,
        ];
    }

    private static function rows(mixed $rows, int $employeeId, string $label): array {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new UnexpectedValueException("Linhas invalidas no plano de arquivamento ({$label}).");
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new UnexpectedValueException("Linha invalida no plano de arquivamento ({$label}).");
            }
            $id = self::id($row['id'] ?? null, "{$label}.id");
            if (isset($seen[$id])) {
                throw new UnexpectedValueException("Linha repetida no plano de arquivamento ({$label}).");
            }
            $seen[$id] = true;
            if (self::id($row['employee_id'] ?? null, "{$label}.employee_id") !== $employeeId) {
                throw new UnexpectedValueException("Linha de outro colaborador no plano de arquivamento ({$label}).");
            }
        }
        return $rows;
    }

    private static function indexById(array $rows): array {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['id']] = $row;
        }
        return $indexed;
    }

    private static function id(mixed $value, string $label): int {
        $id = is_int($value) || (is_string($value) && preg_match('/^[1-9]\d{0,18}$/', $value)) ? (int)$value : 0;
        if ($id < 1) {
            throw new UnexpectedValueException("Identificador invalido no plano de arquivamento ({$label}).");
        }
        return $id;
    }

    private static function date(mixed $value, string $label): string {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new UnexpectedValueException("Data invalida no plano de arquivamento ({$label}).");
        }
        return $value;
    }
}
