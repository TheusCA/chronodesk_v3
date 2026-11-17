// [9.17] Controle de CHG: funções puras da tela (filtros, KPIs, ordem, painel
// por técnico, máscara de data e hora, links seguros, a tabela para copiar e
// as preferências por pessoa). A etapa, os selos, os rótulos de tempo e a
// tabela para copiar (HTML com as cores do v5.2) vêm prontos do servidor; aqui
// nada recalcula a regra do v5.2.

export type ChgStage = 'comite' | 'prep' | 'exec' | 'valid' | 'done' | 'cancel'

export type ChgCarteira = { codigo: string | null; nome: string; pas: number | null }
export type ChgEndpoint = { url: string; start_at: string | null; start_date: string | null }
export type ChgAttempt = { attempt_no: number; patch_status: string | null; reagendando: boolean; registered_at: string | null; endpoints: ChgEndpoint[] }
export type ChgPlan = { implantacao: string; backup: string; rollback: string; task_impl: string; task_bkp: string; task_roll: string }
export type ChgTableRow = { k: string; v: string; section: boolean; rollback: boolean; bold: boolean; color: string | null; link: boolean }
export type ChgView = {
  stage: ChgStage
  auto_stage: ChgStage
  is_manual: boolean
  late: boolean
  waiting_window: boolean
  prep_at: string | null
  badge: { text: string; tone: string }
  note: string | null
  sla: { percent: number; left: string; ticks: { step: number; color: number | string; state: string; label: string }[]; lines: { color: number | string; future: boolean; text: string }[] } | null
  open_tasks: string[]
  can_new_patch: boolean
}
export type ChgCard = {
  id: number
  row_version: number
  solicitante: string
  tipo: 'padrao' | 'emergencial'
  carteiras: ChgCarteira[]
  locais: string[]
  versao: string
  versao_anterior: string
  descricao: string
  link_planilha: string
  sugestao_at: string | null
  service_status: 'aguardando' | 'aprovado' | 'cancelado'
  sd_inicio: string | null
  sd_fim: string | null
  chg_numero: string | null
  chg_status: 'pendente' | 'aprovada' | 'cancelada' | null
  tecnico_id: number | null
  tecnico_nome: string | null
  tecnico_nome_legado: string | null
  concluida: boolean
  created_at: string
  qtd_pas: number
  plans: ChgPlan[]
  attempts: ChgAttempt[]
  rollback: { url: string; start_at: string | null; started_at: string; status: string; plan_position: number } | null
  view: ChgView
  tabela: ChgTableRow[]
}
export type ChgFilters = { q: string; solicitante: string; tecnico: string; local: string; tipo: string; chg: string; de: string; ate: string; fechadas: boolean }

export const STAGES: { key: ChgStage; title: string; hint: string; empty: string }[] = [
  { key: 'comite', title: 'Aguardando Comitê', hint: 'Solicitações sem CHG, pendentes de aprovação ou aguardando janela', empty: 'Nenhuma solicitação em análise. Use Nova atualização para cadastrar.' },
  { key: 'prep', title: 'Em preparação', hint: 'CHG aprovada ou novo patch após erro', empty: 'Nenhuma atualização em preparação.' },
  { key: 'exec', title: 'Em execução', hint: 'Patch em andamento ou rollback iniciado', empty: 'Nenhuma execução em andamento.' },
  { key: 'valid', title: 'Validação/Encerramento TASK', hint: 'Patch com sucesso ou parcial: validar e encerrar tasks', empty: 'Nenhuma atualização aguardando validação.' },
  { key: 'done', title: 'Concluídas', hint: 'Execução finalizada', empty: 'Nenhuma atualização concluída.' },
  { key: 'cancel', title: 'Canceladas', hint: 'Solicitações canceladas', empty: 'Nenhuma atualização cancelada.' },
]
export const STAGE_TITLE: Record<string, string> = Object.fromEntries(STAGES.map((s) => [s.key, s.title]))
export const SERVICE_LABELS: Record<string, string> = { aguardando: 'Aguardando retorno', aprovado: 'Aprovado', cancelado: 'Cancelado' }
export const CHG_LABELS: Record<string, string> = { '': 'Não solicitada', pendente: 'Pendente', aprovada: 'Aprovada', cancelada: 'Cancelada' }
export const PATCH_LABELS: Record<string, string> = { '': 'Sem status', sucesso: 'Sucesso', parcial: 'Parcial', erro: 'Erro' }
export const ROLLBACK_LABELS: Record<string, string> = { em_andamento: 'Em andamento', sucesso: 'Sucesso', erro: 'Erro' }
export const TIPO_LABELS: Record<string, string> = { padrao: 'Padrão', emergencial: 'Emergencial' }
export const TASKS: [keyof ChgPlan, keyof ChgPlan, string][] = [
  ['task_impl', 'implantacao', 'Task de Implantação'],
  ['task_bkp', 'backup', 'Task de Backup'],
  ['task_roll', 'rollback', 'Task de Rollback'],
]
export const PLAN_FIELDS: [keyof ChgPlan, string][] = [['implantacao', 'Plano de Implantação'], ['backup', 'Plano de Backup'], ['rollback', 'Plano de Rollback']]
export const EMPTY_FILTERS: ChgFilters = { q: '', solicitante: '', tecnico: '', local: '', tipo: '', chg: '', de: '', ate: '', fechadas: false }
export const NO_TECH = '__none'
const PREFS_KEY = 'chronodesk_chg_prefs'

export function carteiraLabel(carteira: ChgCarteira): string {
  const parts = [String(carteira.codigo || '').trim(), String(carteira.nome || '').trim()].filter(Boolean)
  return parts.length ? parts.join(' - ') : 'Carteira não informada'
}

export function tecnicoName(card: Pick<ChgCard, 'tecnico_nome' | 'tecnico_nome_legado'>): string {
  return card.tecnico_nome || card.tecnico_nome_legado || ''
}

/** Texto onde a busca procura (como o v5.2: solicitante, carteiras, locais, versões, CHG, técnico, tipo e o link do rollback). */
export function searchText(card: ChgCard): string {
  return [card.solicitante, ...card.carteiras.map(carteiraLabel), ...card.locais, card.versao, card.versao_anterior, card.chg_numero, tecnicoName(card), TIPO_LABELS[card.tipo], card.rollback?.url]
    .filter(Boolean).join(' ').toLowerCase()
}

function windowRef(card: ChgCard): string {
  return (card.sd_inicio || card.sugestao_at || '').replace('T', ' ')
}

export function filterCards(cards: ChgCard[], filters: ChgFilters): ChgCard[] {
  const q = filters.q.trim().toLowerCase()
  return cards.filter((card) => {
    if (q && !searchText(card).includes(q)) return false
    if (filters.solicitante && card.solicitante !== filters.solicitante) return false
    if (filters.tecnico === NO_TECH && tecnicoName(card)) return false
    if (filters.tecnico && filters.tecnico !== NO_TECH && String(card.tecnico_id ?? `h:${card.tecnico_nome_legado}`) !== filters.tecnico) return false
    if (filters.local && !card.locais.includes(filters.local)) return false
    if (filters.tipo && card.tipo !== filters.tipo) return false
    if (filters.chg === 'sem' && card.chg_numero) return false
    if (filters.chg && filters.chg !== 'sem' && card.chg_status !== filters.chg) return false
    const ref = windowRef(card)
    if (filters.de && (!ref || ref < `${filters.de} 00:00`)) return false
    if (filters.ate && (!ref || ref > `${filters.ate} 23:59:59`)) return false
    if (!filters.fechadas && ['done', 'cancel'].includes(card.view.stage)) return false
    return true
  })
}

/** Ordem da janela (sortByWindow do v5.2): início (ou sugestão), fim, criação. */
export function sortByWindow(a: ChgCard, b: ChgCard): number {
  const da = windowRef(a)
  const db = windowRef(b)
  if (da && db && da !== db) return da < db ? -1 : 1
  if (da && !db) return -1
  if (db && !da) return 1
  const fa = a.sd_fim || ''
  const fb = b.sd_fim || ''
  if (fa && fb && fa !== fb) return fa < fb ? -1 : 1
  if (fa && !fb) return -1
  if (fb && !fa) return 1
  return (a.created_at || '') < (b.created_at || '') ? -1 : (a.created_at || '') > (b.created_at || '') ? 1 : a.id - b.id
}

export type ChgKpi = { key: string; label: string; value: number; detail: string; tone: string }

/** KPIs (v5.2) sobre a lista filtrada; "hoje" é a data do servidor. */
export function kpis(list: ChgCard[], serverNow: string): ChgKpi[] {
  const by = (stage: ChgStage) => list.filter((card) => card.view.stage === stage)
  const active = list.filter((card) => !['done', 'cancel'].includes(card.view.stage))
  const today = serverNow.slice(0, 10)
  const todayList = list.filter((card) => {
    if (card.view.stage === 'cancel' || !card.sd_inicio) return false
    const start = card.sd_inicio.slice(0, 10)
    const end = (card.sd_fim || card.sd_inicio).slice(0, 10)
    return start <= today && end >= today
  })
  const comite = by('comite')
  const prep = by('prep')
  const exec = by('exec')
  const rollbacks = active.filter((card) => card.rollback)
  return [
    { key: 'andamento', label: 'Em andamento', value: active.length, detail: 'Solicitações abertas', tone: 'info' },
    { key: 'comite', label: 'Aguardando Comitê', value: comite.length, detail: `${comite.filter((c) => !c.chg_numero).length} sem CHG · ${comite.filter((c) => c.view.waiting_window).length} aprovadas`, tone: 'violet' },
    { key: 'prep', label: 'Em preparação', value: prep.length, detail: `${prep.filter((c) => c.attempts[c.attempts.length - 1]?.patch_status === 'erro').length} patch(s) com erro`, tone: 'info' },
    { key: 'exec', label: 'Em execução', value: exec.length, detail: `${exec.filter((c) => !tecnicoName(c)).length} sem técnico`, tone: 'cyan' },
    { key: 'rollback', label: 'Rollbacks', value: rollbacks.length, detail: `${rollbacks.filter((c) => c.rollback?.status === 'em_andamento').length} em andamento`, tone: 'pink' },
    { key: 'hoje', label: 'Janelas hoje', value: todayList.length, detail: `Janela ativa hoje, ${todayList.reduce((sum, c) => sum + (c.qtd_pas || 0), 0)} PAs`, tone: 'success' },
  ]
}

export type ChgTechRow = { key: string; name: string; total: number; by: Record<string, number>; pas: number; next: string }

/** Painel por técnico (renderDash do v5.2): etapas ativas (e concluídas, se pedidas). */
export function dashboard(list: ChgCard[], withDone: boolean): { keys: ChgStage[]; rows: ChgTechRow[]; totals: Record<string, number>; unassigned: ChgCard[]; count: number } {
  const keys: ChgStage[] = ['comite', 'prep', 'exec', 'valid', ...(withDone ? ['done' as ChgStage] : [])]
  const rows = list.filter((card) => keys.includes(card.view.stage))
  const groups = new Map<string, ChgCard[]>()
  for (const card of rows) {
    const name = tecnicoName(card).trim()
    groups.set(name, [...(groups.get(name) || []), card])
  }
  const techs: ChgTechRow[] = [...groups.entries()].map(([name, cards]) => {
    const open = cards.filter((c) => c.view.stage !== 'done')
    return {
      key: name || NO_TECH,
      name,
      total: cards.length,
      by: Object.fromEntries(keys.map((k) => [k, cards.filter((c) => c.view.stage === k).length])),
      pas: open.reduce((sum, c) => sum + (c.qtd_pas || 0), 0),
      next: open.map((c) => c.sd_inicio || '').filter(Boolean).sort()[0] || '',
    }
  })
  techs.sort((a, b) => Number(a.name === '') - Number(b.name === '') || b.total - a.total || a.name.localeCompare(b.name, 'pt-BR'))
  return {
    keys,
    rows: techs,
    totals: Object.fromEntries(keys.map((k) => [k, rows.filter((c) => c.view.stage === k).length])),
    unassigned: rows.filter((c) => !tecnicoName(c).trim() && c.view.stage !== 'done').sort(sortByWindow),
    count: rows.length,
  }
}

const pad = (n: number) => String(n).padStart(2, '0')

/** 'AAAA-MM-DD HH:MM[:SS]' -> 'dd/mm/aaaa hh:mm'; só data -> 'dd/mm/aaaa --:--'. */
export function toMask(value: string | null | undefined, dateOnly: string | null = null): string {
  const date = String(dateOnly || '')
  if (/^\d{4}-\d{2}-\d{2}$/.test(date)) return `${date.slice(8, 10)}/${date.slice(5, 7)}/${date.slice(0, 4)} --:--`
  const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : ''
}

/** Máscara enquanto digita: só dígitos, com / / espaço e : nos lugares (formatMaskDigits do v5.2). */
export function maskDigits(raw: string): string {
  const digits = String(raw).replace(/\D/g, '').slice(0, 12)
  let out = ''
  for (let i = 0; i < digits.length; i++) {
    if (i === 2 || i === 4) out += '/'
    if (i === 8) out += ' '
    if (i === 10) out += ':'
    out += digits[i]
  }
  return out
}

/**
 * 'dd/mm/aaaa hh:mm' -> 'AAAA-MM-DD HH:MM'; 'dd/mm/aaaa' ou 'dd/mm/aaaa --:--'
 * -> 'AAAA-MM-DD' (só a data, horário pendente; quando allowDateOnly). null se
 * inválida (data que não existe, hora fora da faixa).
 */
export function parseMask(raw: string, allowDateOnly = false): string | null {
  const text = String(raw || '').trim().replace(/^(\d{2}\/\d{2}\/\d{4})\s+(\d{2})(\d{2})$/, '$1 $2:$3')
  const m = text.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2})|\s+--:--)?$/)
  if (!m) return null
  const [, d, mo, y, h, mi] = m
  const date = new Date(Number(y), Number(mo) - 1, Number(d))
  if (date.getFullYear() !== Number(y) || date.getMonth() !== Number(mo) - 1 || date.getDate() !== Number(d)) return null
  if (h === undefined) return allowDateOnly ? `${y}-${mo}-${d}` : null
  if (Number(h) > 23 || Number(mi) > 59) return null
  return `${y}-${mo}-${d} ${h}:${mi}`
}

/** Link só http(s) absoluto com host (D-CHG-22); o resto vira null e o botão de abrir fica desligado. */
export function safeLink(raw: string | null | undefined): string | null {
  const value = String(raw || '').trim()
  // O parser de URL tira tab e quebra de linha sozinho: recuse antes. O
  // protocolo (só http e https) é conferido pelo próprio URL, abaixo.
  if (/[\s\u0000-\u001f\u007f]/.test(value)) return null
  try {
    const url = new URL(value)
    return ['http:', 'https:'].includes(url.protocol) && url.hostname ? value : null
  } catch {
    return null
  }
}

/** 'AAAA-MM-DD HH:MM' -> 'dd/mm HHhMM' (fmtShort do v5.2); '—' sem data. */
export function shortLabel(value: string | null | undefined): string {
  const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/)
  return m ? `${m[3]}/${m[2]} ${m[4]}h${m[5]}` : '—'
}

export function dateTimeLabel(value: string | null | undefined): string {
  const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}h${m[5]}` : ''
}

export function initials(name: string): string {
  const parts = String(name || '?').trim().split(/\s+/)
  return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
}

/** Texto com tabulação (tableText do v5.2). */
export function tableText(rows: ChgTableRow[]): string {
  return ['Dados', ...rows.map((row) => (row.section ? row.k : `${row.k}\t${row.v.replace(/\s*\n\s*/g, ' / ')}`))].join('\n')
}

/** Preferências por pessoa (técnico e "mostrar concluídas"), com try/catch. */
export function loadPrefs(storage: Pick<Storage, 'getItem'> | null): Partial<ChgFilters> {
  try {
    const raw = storage?.getItem(PREFS_KEY)
    const parsed = raw ? JSON.parse(raw) : {}
    const prefs: Partial<ChgFilters> = {}
    if (typeof parsed.tecnico === 'string' && parsed.tecnico.length <= 120) prefs.tecnico = parsed.tecnico
    if (typeof parsed.fechadas === 'boolean') prefs.fechadas = parsed.fechadas
    return prefs
  } catch {
    return {}
  }
}

export function savePrefs(storage: Pick<Storage, 'setItem'> | null, filters: ChgFilters): void {
  try {
    storage?.setItem(PREFS_KEY, JSON.stringify({ tecnico: filters.tecnico, fechadas: filters.fechadas }))
  } catch {
    // Sem armazenamento (modo privado, cota): a preferência só não fica guardada.
  }
}

/** Opções do filtro de técnico: os ativos (por ID) e os nomes do histórico importado. */
export function techFilterOptions(cards: ChgCard[], tecnicos: { id: number; nome: string }[]): { value: string; label: string }[] {
  const legacy = [...new Set(cards.map((c) => c.tecnico_nome_legado).filter((v): v is string => Boolean(v)))]
  return [
    ...tecnicos.map((t) => ({ value: String(t.id), label: t.nome })),
    ...legacy.map((name) => ({ value: `h:${name}`, label: `${name} (histórico)` })),
  ]
}

export function uniqueSorted(values: (string | null | undefined)[]): string[] {
  return [...new Set(values.map((v) => String(v || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'))
}

/** Soma dos PAs das carteiras do formulário (o total que o card mostra). */
export function pasSum(carteiras: { pas: string | number | null }[]): number {
  return carteiras.reduce((sum, c) => sum + (Number.parseInt(String(c.pas ?? ''), 10) || 0), 0)
}

/** Fim automático do formulário (v5.2): início + 24 h, em 'AAAA-MM-DD HH:MM'. */
export function plus24h(value: string): string {
  const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/)
  if (!m) return ''
  const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + 1, Number(m[4]), Number(m[5]))
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}
