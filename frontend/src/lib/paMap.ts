// F6 (desenho 5.6 e 9.8): regras puras da tela do Mapa PA. A situação de
// cada técnico vem do motor (campo presence do servidor), com a legenda única
// do F5 (presenceLegend.ts); o conflito vem calculado do servidor (conflicts,
// has_conflict, conflict_days) e a tela só o mostra.

const WEEKDAY_SHORT = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']

function isIsoDate(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/u.test(value)
}

function utcNoon(iso: string): Date {
  return new Date(`${iso}T12:00:00Z`)
}

function isoOf(date: Date): string {
  return date.toISOString().slice(0, 10)
}

export function brDate(iso: unknown): string {
  return isIsoDate(iso) ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : String(iso ?? '')
}

// "seg 19"
export function dayLabel(iso: string): string {
  return `${WEEKDAY_SHORT[utcNoon(iso).getUTCDay()]} ${iso.slice(8, 10)}`
}

export type PaWeek = { from: string, to: string }

// Segunda a sexta da semana da data; no fim de semana, a semana que termina
// nele (mesma regra do servidor, PaMapService::week).
export function weekOf(date: string): PaWeek | null {
  if (!isIsoDate(date)) return null
  const day = utcNoon(date)
  const weekday = day.getUTCDay() === 0 ? 7 : day.getUTCDay()
  const monday = new Date(day.getTime() - (weekday - 1) * 86400000)
  return { from: isoOf(monday), to: isoOf(new Date(monday.getTime() + 4 * 86400000)) }
}

// Dias do seletor (seg a sex): a semana do servidor, ou a calculada da data
// enquanto a resposta não chega.
export function weekDays(week: unknown, date: string): { date: string, label: string }[] {
  const source = week as { from?: unknown, to?: unknown } | null | undefined
  const range = isIsoDate(source?.from) && isIsoDate(source?.to) ? { from: source.from, to: source.to } : weekOf(date)
  if (range === null) return []
  const days = []
  for (let day = utcNoon(range.from); isoOf(day) <= range.to && days.length < 7; day = new Date(day.getTime() + 86400000)) {
    days.push({ date: isoOf(day), label: dayLabel(isoOf(day)) })
  }
  return days
}

// "seg 19, qua 21" (dias de conflito do PA).
export function conflictDaysLabel(days: unknown): string {
  return (Array.isArray(days) ? days : []).filter(isIsoDate).map(dayLabel).join(', ')
}

const REASON_LABELS: Record<string, string> = {
  inativo: 'Inativo',
  arquivado: 'Arquivado',
  fim_de_semana: 'Fim de semana',
  sem_situacao: 'Sem situação',
}

// Vínculo sem dia no motor (presence nulo): o motivo do servidor (DF6).
export function presenceReasonLabel(reason: unknown): string {
  return REASON_LABELS[String(reason)] ?? 'Sem situação'
}

function hhmm(value: unknown): string {
  return typeof value === 'string' ? value.slice(0, 5) : ''
}

type AbsenceLike = { period_type?: unknown, start_time?: unknown, end_time?: unknown, origem?: unknown, category?: unknown }

export function absencePeriodLabel(item: AbsenceLike): string {
  if (item.period_type === 'from_time') return `A partir de ${hhmm(item.start_time)}`
  if (item.period_type === 'interval') return `${hhmm(item.start_time)}–${hhmm(item.end_time)}`
  return 'Dia todo'
}

export function absenceOriginLabel(origem: unknown): string {
  return origem === 'excecao' ? 'Exceção de escala' : 'Ausência'
}

// P9: a categoria só vem para a gestão e o próprio; a coluna só aparece se
// algum item a trouxer.
export function showsCategory(items: unknown): boolean {
  return Array.isArray(items) && items.some((item) => typeof (item as AbsenceLike)?.category === 'string' && (item as AbsenceLike).category !== '')
}

type SaveResult = {
  warnings?: unknown
  conflicts?: unknown
  conflicts_more?: unknown
}

type Conflict = { pa_number?: unknown, date?: unknown, employee_ids?: unknown }

export type SaveFeedback = { tone: 'success' | 'warning', title: string, lines: string[] }

// Retorno do save (P13): salvou sempre; avisos (warnings, texto do servidor)
// e conflito por dia (até 10 e quantos a mais), com os nomes dos outros
// técnicos quando a tela os tem (lista de elegíveis, só de quem edita).
export function saveFeedback(result: SaveResult | null | undefined, employeeId: unknown, names: Map<string, string> = new Map()): SaveFeedback {
  const warnings = (Array.isArray(result?.warnings) ? result.warnings : []).filter((item): item is string => typeof item === 'string' && item !== '')
  const conflicts = (Array.isArray(result?.conflicts) ? result.conflicts : []) as Conflict[]
  const more = Number(result?.conflicts_more ?? 0) > 0 ? Number(result?.conflicts_more) : 0
  const lines = [...warnings]
  if (conflicts.length > 0) {
    const total = conflicts.length + more
    const dates = conflicts.map((item) => brDate(item.date).slice(0, 5)).join(', ')
    lines.push(`Conflito no PA ${String(conflicts[0].pa_number ?? '')} em ${total} ${total === 1 ? 'dia' : 'dias'}: ${dates}${more > 0 ? ` e mais ${more}` : ''}.`)
    const others = new Set<string>()
    for (const conflict of conflicts) {
      for (const id of Array.isArray(conflict.employee_ids) ? conflict.employee_ids : []) {
        if (String(id) !== String(employeeId)) others.add(String(id))
      }
    }
    const known = [...others].map((id) => names.get(id)).filter((name): name is string => typeof name === 'string' && name !== '')
    if (known.length > 0) lines.push(`Presencial no mesmo PA: ${known.join(', ')}.`)
  }
  const title = conflicts.length > 0 ? 'Vínculo salvo com conflito' : warnings.length > 0 ? 'Vínculo salvo com aviso' : 'Vínculo salvo'
  return { tone: lines.length > 0 ? 'warning' : 'success', title, lines }
}
