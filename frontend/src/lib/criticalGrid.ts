// [Escala do Crítico em grade, D-ESC-1 a D-ESC-20] Funções puras da tela. A
// regra (célula pelo motor, privacidade, versão, prévia) é do servidor
// (CriticalGridService); aqui só o que a tela monta: classes de cor, rótulos,
// teclado e seleção de dias.

export type GridCell = {
  kind: 'papel' | 'ausencia' | 'feriado' | 'excecao' | 'regra' | 'sem_escala'
  label: string
  tone: string
  source: string
  marker: 'troca' | 'excecao' | null
  presence_type: 'onsite' | 'remote' | null
  hours: string | null
  detail: string | null
  role_id: number | null
  last_change: { by: string, at: string | null } | null
  version: string
  in_month: boolean
  refs: Record<string, { id: number, starts_on?: string, ends_on?: string, date?: string }>
}

export type GridWeek = { iso: number, start: string, days: string[], current: boolean }

// [D-ESC-13] Paleta fechada (a mesma do servidor e da CHECK da 027).
export const COLOR_TOKENS = ['azul', 'verde', 'pessego', 'lilas', 'amarelo', 'turquesa', 'cinza', 'areia'] as const
export const COLOR_LABELS: Record<string, string> = {
  azul: 'Azul', verde: 'Verde', pessego: 'Pêssego', lilas: 'Lilás', amarelo: 'Amarelo', turquesa: 'Turquesa', cinza: 'Cinza', areia: 'Areia',
}
// [D-ESC-14] Cores fixas fora dos papéis.
export const FIXED_TONES = [
  { tone: 'banco', label: 'Banco de horas' },
  { tone: 'ferias', label: 'Férias' },
  { tone: 'feriado', label: 'Feriado' },
  { tone: 'ausente', label: 'Ausente' },
] as const
const KNOWN_TONES = new Set<string>([...COLOR_TOKENS.map((token) => `papel-${token}`), ...FIXED_TONES.map((item) => item.tone), 'sem'])

export const SOURCE_LABELS: Record<string, string> = {
  rodizio: 'Rodízio', troca: 'Troca', excecao: 'Exceção', ausencia: 'Ausência', feriado: 'Feriado', regra: 'Regra individual', nenhuma: 'Sem escala',
}
const WEEKDAYS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom']

// Classe da cor da célula ou da amostra: tom conhecido, senão "sem" (nunca um valor solto no class).
export function toneClass(tone: unknown): string {
  return `esc-tone-${KNOWN_TONES.has(String(tone)) ? String(tone) : 'sem'}`
}

// Classe da amostra de uma cor da paleta (formulário do papel); sem cor = areia.
export function colorClass(token: unknown): string {
  return toneClass(`papel-${COLOR_TOKENS.includes(token as never) ? String(token) : 'areia'}`)
}

// "Seg (16/11)".
export function dayHeader(date: string): string {
  const day = new Date(`${date}T12:00:00Z`)
  return `${WEEKDAYS[(day.getUTCDay() + 6) % 7]} (${date.slice(8, 10)}/${date.slice(5, 7)})`
}

// "Semana 47 (Início: 16/11/2026)".
export function weekTitle(week: Pick<GridWeek, 'iso' | 'start'>): string {
  return `Semana ${week.iso} (Início: ${week.start.slice(8, 10)}/${week.start.slice(5, 7)}/${week.start.slice(0, 4)})`
}

// "Qui 19/11".
export function dayLabel(date: string): string {
  const [weekday] = dayHeader(date).split(' ')
  return `${weekday} ${date.slice(8, 10)}/${date.slice(5, 7)}`
}

// [D-ESC-15] Vagas do rodízio na ordem, com o papel repetido contado: "Presencial x2".
export function slotsSummary(roleIds: number[], names: Map<number, string>): string[] {
  const counts = new Map<number, number>()
  for (const id of roleIds) counts.set(id, (counts.get(id) ?? 0) + 1)
  return [...counts.entries()].map(([id, count]) => `${names.get(id) ?? `ID ${id}`}${count > 1 ? ` x${count}` : ''}`)
}

// Tira a vaga da posição index (as outras na mesma ordem).
export function removeAt<T>(list: T[], index: number): T[] {
  return list.filter((_, position) => position !== index)
}

// [Teclado] Próxima célula pela seta: dentro da semana, coluna = dia (0 a 4), linha = pessoa.
export function moveFocus(row: number, column: number, key: string, rows: number, columns = 5): [number, number] {
  if (key === 'ArrowRight') return [row, Math.min(columns - 1, column + 1)]
  if (key === 'ArrowLeft') return [row, Math.max(0, column - 1)]
  if (key === 'ArrowDown') return [Math.min(rows - 1, row + 1), column]
  if (key === 'ArrowUp') return [Math.max(0, row - 1), column]
  if (key === 'Home') return [row, 0]
  if (key === 'End') return [row, columns - 1]
  return [row, column]
}

export type Selection = { employeeId: number, from: string, to: string }

// [D-ESC-12] Shift+clique ou arrastar: o intervalo de uma pessoa, do primeiro ao último dia (em qualquer ordem).
// Outra pessoa recomeça a seleção.
export function extendSelection(current: Selection | null, employeeId: number, date: string): Selection {
  if (!current || current.employeeId !== employeeId) return { employeeId, from: date, to: date }
  const anchor = current.from
  return anchor <= date ? { employeeId, from: anchor, to: date } : { employeeId, from: date, to: anchor }
}

export function inSelection(selection: Selection | null, employeeId: number, date: string): boolean {
  return selection !== null && selection.employeeId === employeeId && date >= selection.from && date <= selection.to
}

// Dias do intervalo (inclusive), em AAAA-MM-DD.
export function datesBetween(from: string, to: string): string[] {
  const dates: string[] = []
  const day = new Date(`${from}T12:00:00Z`)
  const end = new Date(`${to}T12:00:00Z`)
  while (day <= end && dates.length < 62) {
    dates.push(day.toISOString().slice(0, 10))
    day.setUTCDate(day.getUTCDate() + 1)
  }
  return dates
}

// Versões dos dias do intervalo, da grade carregada (o servidor confere: 409 se mudou).
export function versionsFor(cells: Record<string, GridCell>, employeeId: number, from: string, to: string): Record<string, string> {
  const versions: Record<string, string> = {}
  for (const date of datesBetween(from, to)) {
    const cell = cells[`${employeeId}:${date}`]
    if (cell) versions[date] = cell.version
  }
  return versions
}

export type GridChange =
  | { type: 'role', role_id: number }
  | { type: 'absence', category: string }
  | { type: 'exception', exception_type: string }
  | { type: 'undo', target: string, ref_id: number | undefined }

export type PreviewRequest = { employee_id: number, from: string, to: string, change: GridChange }

// [Achado das capturas, 2026-11-25] Chave do pedido da prévia, no mesmo formato
// de CriticalGridService::requestKey (pessoa|de|ate|tipo|valor). A prévia só
// vale (e o salvar só liga) quando a chave devolvida pelo servidor é a dos
// parâmetros atuais do diálogo.
export function previewKey(request: PreviewRequest): string {
  const { change } = request
  const value = change.type === 'role' ? String(change.role_id)
    : change.type === 'absence' ? change.category
      : change.type === 'exception' ? change.exception_type
        : `${change.target}:${change.ref_id ?? ''}`
  return [String(request.employee_id), request.from, request.to, change.type, value].join('|')
}

// Prévia que vale para o pedido atual: a devolvida para a chave atual, nunca a
// de outra aba, opção ou dia (nem uma resposta atrasada de um pedido antigo).
export function previewFor<T extends { request_key?: unknown }>(preview: T | null, currentKey: string): T | null {
  return preview !== null && preview.request_key === currentKey ? preview : null
}

// "Quem alterou por último": "qa.lider em 12/11/2026 10:00".
export function changeLabel(change: GridCell['last_change']): string {
  if (!change) return '—'
  const at = change.at && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(change.at)
    ? ` em ${change.at.slice(8, 10)}/${change.at.slice(5, 7)}/${change.at.slice(0, 4)} ${change.at.slice(11, 16)}`
    : ''
  return `${change.by}${at}`
}

// Mês seguinte/anterior em AAAA-MM (a tela usa os do servidor; esta é a de "Hoje").
export function monthOf(date: string): string {
  return date.slice(0, 7)
}
