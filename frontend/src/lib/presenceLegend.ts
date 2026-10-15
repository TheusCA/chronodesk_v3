// F5 (desenho 5.1, 5.5 e 9.7): legenda única da situação do técnico no
// Calendário e na tela Escala. Nenhuma situação só pela cor: cada uma tem
// rótulo, letra, ícone e forma (sem escala com contorno tracejado). As cores
// ficam em classes do index.css (presence-*), porque o Tailwind não lê .ts.

export type PresenceStatus = 'onsite' | 'remote' | 'absence' | 'no_schedule'

export type PresenceLegendItem = {
  status: PresenceStatus
  label: string
  letter: string
  icon: string
  tone: string
  shape: string
}

// Ausência com o mesmo laranja do evento de ausência de antes do corte.
export const PRESENCE_LEGEND: PresenceLegendItem[] = [
  { status: 'onsite', label: 'Presencial', letter: 'P', icon: 'building', tone: 'presence-onsite', shape: 'Preenchido' },
  { status: 'remote', label: 'Remoto', letter: 'R', icon: 'home', tone: 'presence-remote', shape: 'Preenchido' },
  { status: 'absence', label: 'Ausente', letter: 'A', icon: 'userMinus', tone: 'presence-absence', shape: 'Preenchido' },
  { status: 'no_schedule', label: 'Sem escala', letter: 'S', icon: 'help', tone: 'presence-no-schedule', shape: 'Contorno tracejado' },
]

export const PARTIAL_MARKER_LEGEND = { label: 'Ausência parcial', icon: 'clock', shape: 'Marcador no cartão, na cor da escala' }

const BY_STATUS = new Map(PRESENCE_LEGEND.map((item) => [item.status, item]))

export function presenceLegendItem(status: unknown): PresenceLegendItem | null {
  return BY_STATUS.get(status as PresenceStatus) ?? null
}

const SITUATION_LABELS: Record<string, string> = {
  presencial: 'Presencial',
  remoto: 'Remoto',
  ausente: 'Ausente',
  sem_escala: 'Sem escala',
}

const ORIGIN_LABELS: Record<string, string> = {
  ausencia: 'Ausência',
  regra_escala: 'Escala',
  expediente: 'Expediente',
}

export function situationLabel(value: unknown): string {
  return SITUATION_LABELS[String(value)] ?? String(value ?? '')
}

export function originLabel(value: unknown): string {
  return ORIGIN_LABELS[String(value)] ?? String(value ?? '')
}

type CalendarEventLike = {
  id?: unknown
  event_type?: unknown
  status?: unknown
  situacao?: unknown
  detalhe?: unknown
}

// Evento de escala do motor (do corte em diante): o único com situacao.
// Antes do corte, e sem corte registrado, nenhum evento tem esse campo.
export function isEngineScheduleEvent(event: CalendarEventLike): boolean {
  return event.event_type === 'schedule' && typeof event.situacao === 'string'
}

// Marcador da parcial no detalhe do motor (" · Ausente a partir de HH:MM" ou
// " · Ausente HH:MM–HH:MM"); o dia continua pela situação.
// Também serve à linha da tela Escala (mesmos campos do motor).
export function partialMarkerInDetail(situacao: unknown, detalhe: unknown): boolean {
  return typeof situacao === 'string' && situacao !== 'ausente' && /· Ausente /u.test(String(detalhe ?? ''))
}

export function hasPartialMarker(event: CalendarEventLike): boolean {
  return isEngineScheduleEvent(event) && partialMarkerInDetail(event.situacao, event.detalhe)
}

export type DaySummary = Record<PresenceStatus, number> & { partial: number, total: number }

// Resumo do dia pela situação (P15): cada evento do motor conta uma vez, pelo
// status; o dia com parcial conta na situação dele e soma só em "partial".
// Eventos repetidos (mesmo id) contam uma vez.
export function presenceDaySummary(events: CalendarEventLike[]): DaySummary {
  const summary: DaySummary = { onsite: 0, remote: 0, absence: 0, no_schedule: 0, partial: 0, total: 0 }
  const seen = new Set<string>()
  for (const event of events) {
    if (!isEngineScheduleEvent(event)) continue
    const key = String(event.id)
    if (seen.has(key)) continue
    seen.add(key)
    const item = presenceLegendItem(event.status)
    if (item === null) continue
    summary[item.status] += 1
    summary.total += 1
    if (hasPartialMarker(event)) summary.partial += 1
  }
  return summary
}

// "P 9 · R 6 · A 2 · S 1", sem as situações zeradas.
export function daySummaryText(summary: DaySummary): string {
  return PRESENCE_LEGEND
    .filter((item) => summary[item.status] > 0)
    .map((item) => `${item.letter} ${summary[item.status]}`)
    .join(' · ')
}

type CutoffLike = { date?: unknown, split?: unknown } | null | undefined

function brDate(iso: string): string {
  return `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`
}

// Aviso do relatório quando o período alcança o corte (campo cutoff, D4);
// mesmo texto da linha "aviso" do CSV (PresenceCutoff::splitNotice).
export function presenceCutoffNotice(cutoff: CutoffLike): string | null {
  const date = String(cutoff?.date ?? '')
  if (!/^\d{4}-\d{2}-\d{2}$/u.test(date)) return null
  if (cutoff?.split !== true) return `Escala pelas regras novas (corte em ${brDate(date)}).`
  const dayBefore = new Date(`${date}T12:00:00Z`)
  dayBefore.setUTCDate(dayBefore.getUTCDate() - 1)
  return `Escala pelo cálculo antigo até ${brDate(dayBefore.toISOString().slice(0, 10))} e pelas regras novas a partir de ${brDate(date)}.`
}

// Texto para leitor de tela: "9 presenciais, 6 remotos, 2 ausentes, 1 sem
// escala"; a parcial só como aviso, sem número (não soma de novo).
export function daySummaryAriaLabel(summary: DaySummary): string {
  const words: Record<PresenceStatus, [string, string]> = {
    onsite: ['presencial', 'presenciais'],
    remote: ['remoto', 'remotos'],
    absence: ['ausente', 'ausentes'],
    no_schedule: ['sem escala', 'sem escala'],
  }
  const parts = PRESENCE_LEGEND
    .filter((item) => summary[item.status] > 0)
    .map((item) => `${summary[item.status]} ${words[item.status][summary[item.status] === 1 ? 0 : 1]}`)
  if (summary.partial > 0) parts.push('inclui ausência parcial')
  return parts.join(', ')
}
