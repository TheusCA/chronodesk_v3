import { formatDate } from './format'
import { minutesLabel } from './operational'

// [17g] Decisão de hora extra ou correção de ponto: vai para a folha e nenhuma
// ação da aplicação a desfaz (docs/DESENHO_PA_ESCALA_AUSENCIA.md, seção 9.11).
export const DECISION_WARNING = 'Esta decisão não pode ser desfeita pelo sistema.'

export const DECISION_ENDPOINTS: Record<DecisionKind, string> = {
  overtime: 'portal/overtime.php',
  adjustment: 'portal/time_corrections.php',
}

export type DecisionKind = 'overtime' | 'adjustment'
export type Decision = 'approved' | 'rejected'
export type DecisionItem = {
  id: number
  employee_name?: string
  work_date?: string
  start_time?: string
  end_time?: string
  total_minutes?: number
  adjustment_date?: string
  adjustment_type?: string
  correct_time?: string | null
}

export function adjustmentTypeLabel(value?: string): string {
  return ({
    entry: 'Entrada',
    lunch_out: 'Saída para almoço',
    lunch_return: 'Retorno do almoço',
    exit: 'Saída',
    absence: 'Ausência',
    other: 'Outro',
  } as Record<string, string>)[value ?? ''] || 'Ajuste'
}

const hour = (value?: string | null) => String(value ?? '').slice(0, 5)

export function decisionDialogText(kind: DecisionKind, item: DecisionItem, decision: Decision) {
  const approving = decision === 'approved'
  const overtime = kind === 'overtime'
  return {
    title: `${approving ? 'Aprovar' : 'Rejeitar'} ${overtime ? 'hora extra' : 'ajuste de ponto'}?`,
    employee: item.employee_name ?? '',
    detail: overtime
      ? `${formatDate(item.work_date as string)}, ${hour(item.start_time)} - ${hour(item.end_time)}, total ${minutesLabel(item.total_minutes)}`
      : `${formatDate(item.adjustment_date as string)}, ${adjustmentTypeLabel(item.adjustment_type)}, horário correto ${item.correct_time ? hour(item.correct_time) : 'não informado'}`,
    confirmLabel: approving ? 'Aprovar' : 'Rejeitar',
    busyLabel: approving ? 'Aprovando...' : 'Rejeitando...',
    confirmClassName: approving ? 'btn-primary' : 'btn-danger',
  }
}
