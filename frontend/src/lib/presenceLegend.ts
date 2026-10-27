// F5 (desenho 5.1, 5.5 e 9.7): legenda única da situação do técnico no
// Calendário e na tela Escala. Nenhuma situação só pela cor: cada uma tem
// rótulo, letra, ícone e forma (sem escala com contorno tracejado). As cores
// ficam em classes do index.css (presence-*), porque o Tailwind não lê .ts;
// lá ficam fora de @layer, senão o Tailwind as descarta (qa:calendar-presence
// compila o CSS e confere).

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

// F7 (desenho 5.7 e 9.9, DV7): situação de agora, com relógio (o campo
// situacao do motor no status.php e no technicians.php). Em pausa violeta,
// Almoço amarelo (bem distinto do laranja de ausente) e Fora do expediente
// cinza preenchido; as demais com a cor da legenda do F5. Cada uma com rótulo
// e ícone: nenhuma só pela cor.
export type NowSituation = 'presencial' | 'remoto' | 'pausa' | 'almoco' | 'ausente' | 'fora_expediente' | 'sem_escala'

export type NowLegendItem = {
  situacao: NowSituation
  label: string
  icon: string
  tone: string
}

export const NOW_PRESENCE_LEGEND: NowLegendItem[] = [
  { situacao: 'presencial', label: 'Presencial', icon: 'building', tone: 'presence-onsite' },
  { situacao: 'remoto', label: 'Remoto', icon: 'home', tone: 'presence-remote' },
  { situacao: 'pausa', label: 'Em pausa', icon: 'pause', tone: 'presence-pause' },
  { situacao: 'almoco', label: 'Almoço', icon: 'utensils', tone: 'presence-lunch' },
  { situacao: 'ausente', label: 'Ausente', icon: 'userMinus', tone: 'presence-absence' },
  { situacao: 'fora_expediente', label: 'Fora do expediente', icon: 'clock', tone: 'presence-off-hours' },
  { situacao: 'sem_escala', label: 'Sem escala', icon: 'help', tone: 'presence-no-schedule' },
]

const NOW_BY_SITUATION = new Map(NOW_PRESENCE_LEGEND.map((item) => [item.situacao, item]))

export function nowLegendItem(situacao: unknown): NowLegendItem | null {
  return NOW_BY_SITUATION.get(situacao as NowSituation) ?? null
}

export type OperationalSummaryKey = 'escalados' | 'disponiveis' | 'em_pausa' | 'almoco' | 'ausentes' | 'fora_expediente' | 'sem_escala'

export type OperationalSummaryColumn = {
  key: OperationalSummaryKey
  label: string
  icon: string
  tone: string
}

const nowColumn = (key: OperationalSummaryKey, situacao: NowSituation, label: string): OperationalSummaryColumn => {
  const item = NOW_BY_SITUATION.get(situacao) as NowLegendItem
  return { key, label, icon: item.icon, tone: item.tone }
}

// Colunas da tabela da 5.7, na ordem do presence_summary; Disponíveis é
// presencial ou remoto (escalados = disponíveis + em pausa + almoço +
// ausentes + fora do expediente; sem escala fora da soma).
export const OPERATIONAL_SUMMARY_COLUMNS: OperationalSummaryColumn[] = [
  { key: 'escalados', label: 'Escalados', icon: 'users', tone: 'presence-scheduled' },
  { key: 'disponiveis', label: 'Disponíveis', icon: 'shield', tone: 'presence-available' },
  nowColumn('em_pausa', 'pausa', 'Em pausa'),
  nowColumn('almoco', 'almoco', 'Almoço'),
  nowColumn('ausentes', 'ausente', 'Ausentes'),
  nowColumn('fora_expediente', 'fora_expediente', 'Fora do expediente'),
  nowColumn('sem_escala', 'sem_escala', 'Sem escala'),
]

// Contador do resumo: número inteiro não negativo, senão null ("—").
export function summaryCount(group: unknown, key: OperationalSummaryKey): number | null {
  const value = (group as Record<string, unknown> | null | undefined)?.[key]
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null
}

// "HH:MM" do server_now (DATE_ATOM, hora do servidor), sem converter para o
// fuso do navegador; fora do formato, null.
export function serverClock(serverNow: unknown): string | null {
  return typeof serverNow === 'string' ? serverNow.match(/^\d{4}-\d{2}-\d{2}T(\d{2}:\d{2})/u)?.[1] ?? null : null
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
  title?: unknown
  status?: unknown
  situacao?: unknown
  detalhe?: unknown
  parcial?: unknown
}

// Prefixo do título do evento do motor (scheduleStatusLabel no backend).
const TITLE_PREFIX: Record<PresenceStatus, string> = {
  onsite: 'Presencial',
  remote: 'Remoto',
  absence: 'Ausência',
  no_schedule: 'Sem escala',
}

// Evento de escala do motor (do corte em diante): o único com situacao.
// Antes do corte, e sem corte registrado, nenhum evento tem esse campo.
export function isEngineScheduleEvent(event: CalendarEventLike): boolean {
  return event.event_type === 'schedule' && typeof event.situacao === 'string'
}

// Horário do marcador da parcial, do campo parcial do servidor (ausente sem
// marcador e no dia de ausência de dia inteiro); só em dia do motor (com
// situacao). Linha da tela Escala (schedules.php, commit 5).
export function rowPartialTime(row: { situacao?: unknown, parcial?: unknown }): string | null {
  return typeof row.situacao === 'string' && typeof row.parcial === 'string' && row.parcial !== '' ? row.parcial : null
}

// [Ajuste b] Calendário: o mesmo campo, só no evento de escala do motor.
export function partialTime(event: CalendarEventLike): string | null {
  return isEngineScheduleEvent(event) ? rowPartialTime(event) : null
}

export function hasPartialMarker(event: CalendarEventLike): boolean {
  return partialTime(event) !== null
}

// [Ajuste a] Nome do técnico no cartão da semana, sem o prefixo da situação
// (ícone e cor já a dão); título fora do formato sai inteiro.
export function engineEventName(event: CalendarEventLike): string {
  const title = String(event.title ?? '')
  const item = presenceLegendItem(event.status)
  const prefix = item === null ? null : `${TITLE_PREFIX[item.status]}: `
  return prefix !== null && title.startsWith(prefix) && title.length > prefix.length ? title.slice(prefix.length) : title
}

// Texto completo do cartão da semana (atributo title): título, detalhe e a
// categoria quando o servidor manda.
export function engineEventFullText(event: CalendarEventLike & { categoria?: unknown }): string {
  return [event.title, event.detalhe, event.categoria]
    .filter((part): part is string => typeof part === 'string' && part !== '')
    .join(' · ')
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

// [Ajuste d] Título do aviso pelo split: "dividida" só quando o período
// cruza o corte; todo depois dele, "pelas regras novas".
export function presenceCutoffTitle(cutoff: CutoffLike): string | null {
  if (presenceCutoffNotice(cutoff) === null) return null
  return cutoff?.split === true ? 'Escala dividida na data de corte' : 'Escala pelas regras novas'
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
