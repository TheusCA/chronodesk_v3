// [F10-5] Escala do mês: funções puras da tela. A regra (motor, distribuição
// de PA, RESUMO, hash, divergência) é do servidor (MonthlyScheduleService);
// aqui só o que a tela monta: rótulos, tons (os da grade do Crítico, direção
// "Mesa clara"), semanas e as linhas do RESUMO.

export type MonthCell = {
  situation: 'onsite' | 'remote' | 'absent'
  pa: string | null
  pa_source?: string | null
  no_pa: boolean
  moved: boolean
  revision?: number
  divergence?: 'ausente_com_pa' | 'situacao_mudou'
}

export type DaySummary = {
  onsite: number
  remote: number
  absent: number
  n1: number
  n2: number
  hybrid: number
  critical: number
  home_by_team: Record<string, number>
  percent_onsite: number | null
  capacity: number
  reserved_seats: number
  moves: number
  no_pa: number
  status: string[]
}

export const SITUATION_LABELS: Record<MonthCell['situation'], string> = { onsite: 'Presencial', remote: 'Home', absent: 'Ausente' }

// Tom da célula pela paleta da grade do Crítico (criticalGrid.css): presencial
// ardósia, home sálvia, ausente cinza; sem PA no âmbar.
export function cellTone(cell: MonthCell | null | undefined): string {
  if (!cell) return 'sem'
  if (cell.no_pa) return 'banco'
  return { onsite: 'papel-azul', remote: 'papel-verde', absent: 'ausente' }[cell.situation] ?? 'sem'
}

export function cellLabel(cell: MonthCell | null | undefined, inCommonGroup = true): string {
  if (!cell) return inCommonGroup ? 'Sem escala' : 'Outra escala'
  return SITUATION_LABELS[cell.situation]
}

// Segunda linha da célula: o PA do dia, "Sem PA" ou nada.
export function cellDetail(cell: MonthCell | null | undefined): string {
  if (!cell) return ''
  if (cell.no_pa) return 'Sem PA'
  return cell.pa ? `PA ${cell.pa}` : ''
}

export const DIVERGENCE_LABELS: Record<string, string> = {
  ausente_com_pa: 'Ausente depois de publicar (o PA fica livre)',
  situacao_mudou: 'A situação de hoje difere da publicada',
}

// Dias úteis agrupados por semana (segunda como chave).
export function weeksOf(days: string[]): { start: string, days: string[] }[] {
  const weeks: { start: string, days: string[] }[] = []
  for (const day of days) {
    const date = new Date(`${day}T12:00:00Z`)
    date.setUTCDate(date.getUTCDate() - ((date.getUTCDay() + 6) % 7))
    const start = date.toISOString().slice(0, 10)
    const last = weeks[weeks.length - 1]
    if (last && last.start === start) last.days.push(day)
    else weeks.push({ start, days: [day] })
  }
  return weeks
}

// "Semana de 16/11 a 20/11" (os dias úteis do mês naquela semana).
export function weekLabel(week: { days: string[] }): string {
  const first = week.days[0]
  const last = week.days[week.days.length - 1] ?? first
  return `Semana de ${first.slice(8, 10)}/${first.slice(5, 7)} a ${last.slice(8, 10)}/${last.slice(5, 7)}`
}

// Linhas do RESUMO do script, na ordem da aba ESCALA (linhas 1088-1132).
export function summaryRows(days: string[], summary: Record<string, DaySummary>): { label: string, values: (string | number)[] }[] {
  const teams = [...new Set(days.flatMap((day) => Object.keys(summary[day]?.home_by_team ?? {})))].sort()
  const value = (day: string, pick: (s: DaySummary) => string | number) => (summary[day] ? pick(summary[day]) : '—')
  return [
    { label: 'Presenciais comuns', values: days.map((d) => value(d, (s) => s.onsite)) },
    { label: 'Home comuns', values: days.map((d) => value(d, (s) => s.remote)) },
    { label: '% presencial', values: days.map((d) => value(d, (s) => (s.percent_onsite === null ? '—' : `${s.percent_onsite}%`))) },
    { label: 'N1 presencial', values: days.map((d) => value(d, (s) => s.n1)) },
    { label: 'N2 presencial', values: days.map((d) => value(d, (s) => s.n2)) },
    { label: 'PAs do dia', values: days.map((d) => value(d, (s) => s.capacity)) },
    ...teams.map((team) => ({ label: `${team} home`, values: days.map((d) => value(d, (s) => s.home_by_team[team] ?? 0)) })),
    { label: 'Híbrido presencial', values: days.map((d) => value(d, (s) => s.hybrid)) },
    { label: 'Crítico presencial', values: days.map((d) => value(d, (s) => s.critical)) },
    { label: 'Sem PA', values: days.map((d) => value(d, (s) => s.no_pa)) },
    { label: 'Trocas', values: days.map((d) => value(d, (s) => s.moves)) },
    { label: 'Status', values: days.map((d) => value(d, (s) => statusLabel(s.status))) },
  ]
}

const STATUS_LABELS: Record<string, string> = { OK: 'OK', 'PA INSUFICIENTE': 'PA insuficiente', 'ATENCAO CONFIG': 'Atenção config' }

export function statusLabel(status: string[]): string {
  return status.map((item) => STATUS_LABELS[item] ?? item).join(' · ')
}

export function statusAlert(status: string[] | undefined): boolean {
  return Array.isArray(status) && status.some((item) => item !== 'OK')
}

// Dia da semana curto: "Seg 16/11".
export function shortDay(date: string): string {
  const names = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']
  return `${names[new Date(`${date}T12:00:00Z`).getUTCDay()]} ${date.slice(8, 10)}/${date.slice(5, 7)}`
}

// Contagens da prévia da importação: [passo, situação, n].
export const IMPORT_STEPS = ['times', 'regras', 'excecoes', 'inventario', 'virada'] as const
export const IMPORT_STEP_LABELS: Record<string, string> = { times: 'Times de rodízio', regras: 'Regras', excecoes: 'Exceções e férias', inventario: 'Inventário de PA', virada: 'Virada dos vínculos' }
export const IMPORT_STATUS_LABELS: Record<string, string> = { novo: 'Entra', muda: 'Muda', igual: 'Já igual', conflito: 'Conflito', nao_casou: 'Não casou' }
