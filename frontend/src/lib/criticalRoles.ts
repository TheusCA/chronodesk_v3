// [9.16] Rodízio de papéis do Crítico: rótulos e funções puras da tela. A
// regra (papel do dia, vagos, prévia) é do servidor (CriticalRolePlanner);
// aqui só o que a tela monta antes de pedir a prévia.

export type CriticalRole = {
  id: number
  name: string
  presence_type: 'onsite' | 'remote'
  work_start: string
  work_end: string
  active: boolean
  sort_order: number
}

export type PreviewDay = { date: string; roles: Record<string, number | null>; vacant: number[] }

export const ROLE_MAX_ITEMS = 20
export const OVERRIDE_MAX_DAYS = 31

export const PRESENCE_LABELS: Record<string, string> = { onsite: 'Presencial', remote: 'Remoto' }
export const PERIOD_OPTIONS = [
  { value: 'week', label: 'Por semana' },
  { value: 'month', label: 'Por mês' },
]
export const REASON_LABELS: Record<string, string> = {
  troca: 'Troca entre pessoas',
  cobertura: 'Cobertura de ausência',
  outro: 'Outro motivo',
}

// "Abertura home 07:00–17:00", como o servidor (CriticalRolePlanner::label).
export function roleLabel(role: Pick<CriticalRole, 'name' | 'work_start' | 'work_end'>): string {
  return `${role.name} ${role.work_start.slice(0, 5)}–${role.work_end.slice(0, 5)}`
}

// Jornada HH:MM sem cruzar a meia-noite (início antes do fim), como o servidor.
export function validShift(start: string, end: string): boolean {
  const clock = /^([01]\d|2[0-3]):[0-5]\d$/
  return clock.test(start) && clock.test(end) && start < end
}

// Âncora do período a partir de uma data: a segunda-feira da semana, ou o dia 1 do mês (AD-PC-1).
export function anchorFor(period: string, date: string): string {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return date
  if (period === 'month') return `${date.slice(0, 8)}01`
  const value = new Date(`${date}T12:00:00Z`)
  if (Number.isNaN(value.getTime())) return date
  value.setUTCDate(value.getUTCDate() - ((value.getUTCDay() + 6) % 7))
  return value.toISOString().slice(0, 10)
}

// Move o item da posição index em delta (-1 sobe, 1 desce), sem mutar a lista.
export function moveItem<T>(list: T[], index: number, delta: number): T[] {
  const target = index + delta
  if (index < 0 || index >= list.length || target < 0 || target >= list.length) return list
  const copy = [...list]
  const [item] = copy.splice(index, 1)
  copy.splice(target, 0, item)
  return copy
}

// Liga ou desliga o ID na lista ordenada (entra no fim; sai sem mudar a ordem dos outros).
export function toggleId(list: number[], id: number): number[] {
  return list.includes(id) ? list.filter((item) => item !== id) : [...list, id]
}

// Dias da prévia em que algum papel muda (o primeiro sempre entra): a tabela
// mostra só as trocas, não os 40 dias úteis das 8 semanas.
export function changePoints(days: PreviewDay[]): PreviewDay[] {
  let previous = ''
  return days.filter((day) => {
    const key = JSON.stringify([day.roles, day.vacant])
    if (key === previous) return false
    previous = key
    return true
  })
}
