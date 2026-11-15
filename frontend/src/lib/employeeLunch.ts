// Filtro de almoço da aba Funcionários (pedido da gestão, pós-deploy 1,
// desenho 9.14.3). Só leitura do que o listar_funcionarios.php já devolve
// (almoco_inicio e almoco_fim); sem API nem banco novos. Quando o F8 entrar,
// o almoço ganha contrato e exceção (desenho 9.10) e este filtro acompanha.

type EmployeeLunchLike = {
  almoco_inicio?: unknown
  almoco_fim?: unknown
}

export type LunchOption = {
  value: string
  label: string
  count: number
}

const TIME = /^([01]\d|2[0-3]):([0-5]\d)/u

function hhmm(value: unknown): string | null {
  const match = TIME.exec(String(value ?? '').trim())
  return match ? `${match[1]}:${match[2]}` : null
}

type EmployeeScheduleLike = {
  jornada_entrada?: unknown
  jornada_saida?: unknown
}

// [R4d, acabamento] Jornada da lista de Funcionários em HH:MM, com a mesma
// normalização do almoço ("08:00:00" vira "08:00"); '' quando falta um
// horário válido (a tela mostra "—").
export function scheduleText(item: EmployeeScheduleLike): string {
  const start = hhmm(item.jornada_entrada)
  const end = hhmm(item.jornada_saida)
  return start && end ? `${start} - ${end}` : ''
}

// "12:00-13:00"; '' quando o cadastro não tem os dois horários válidos.
export function lunchKey(item: EmployeeLunchLike): string {
  const start = hhmm(item.almoco_inicio)
  const end = hhmm(item.almoco_fim)
  return start && end ? `${start}-${end}` : ''
}

// Horários distintos dos cadastros recebidos (os da situação escolhida), com a
// contagem de cada um, ordenados pelo início e depois pelo fim.
export function lunchOptions(items: EmployeeLunchLike[]): LunchOption[] {
  const counts = new Map<string, number>()
  for (const item of items) {
    const key = lunchKey(item)
    if (key) counts.set(key, (counts.get(key) ?? 0) + 1)
  }
  return [...counts.entries()]
    .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
    .map(([value, count]) => ({ value, label: `${value} (${count})`, count }))
}

// '' (Todos os almoços) passa tudo; senão, só o horário escolhido.
export function matchesLunchFilter(item: EmployeeLunchLike, lunch: string): boolean {
  return !lunch || lunchKey(item) === lunch
}

// Ao trocar a situação (ou recarregar a lista), um horário que deixou de
// existir nas opções volta para "Todos os almoços".
export function keepLunchSelection(lunch: string, options: LunchOption[]): string {
  return lunch && !options.some((option) => option.value === lunch) ? '' : lunch
}
