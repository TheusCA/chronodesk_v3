// [F10-2] Times de rodízio e ciclo semana a semana (desenho do F10, 3.1 e 3.2).
// Funções puras da tela; o servidor (services/CyclePattern.php) valida tudo de
// novo e decide. Mesmo cálculo da 2.2 do desenho: o padrão do ciclo semana a
// semana é a concatenação das semanas (5 posições cada, de segunda a sexta).

export type CyclePosition = 'onsite' | 'remote'

export const CYCLE_MAX_WEEKS = 4
export const CYCLE_MIN_POSITIONS = 2
export const CYCLE_MAX_POSITIONS = 20
export const WEEK_DAYS = ['seg', 'ter', 'qua', 'qui', 'sex'] as const

// Semana nova: presencial de segunda a quinta, home na sexta (já válida).
export function defaultWeek(): CyclePosition[] {
  return ['onsite', 'onsite', 'onsite', 'onsite', 'remote']
}

// Muda o número de semanas: as que ficam não mudam; as novas copiam a
// semana 1, como a planilha ("semana sem linha repete a semana 1").
export function resizeWeeks(weeks: CyclePosition[][], count: number): CyclePosition[][] {
  const size = Math.min(Math.max(Math.trunc(count) || 1, 1), CYCLE_MAX_WEEKS)
  const first = weeks[0] ? [...weeks[0]] : defaultWeek()
  return Array.from({ length: size }, (_, index) => (weeks[index] ? [...weeks[index]] : [...first]))
}

export function toggleDay(weeks: CyclePosition[][], week: number, day: number): CyclePosition[][] {
  return weeks.map((row, rowIndex) => row.map((value, dayIndex) => (
    rowIndex === week && dayIndex === day ? (value === 'onsite' ? 'remote' : 'onsite') : value
  )))
}

export function weeksToPattern(weeks: CyclePosition[][]): CyclePosition[] {
  return weeks.flat()
}

// Padrão gravado com weeks (5 x N) de volta para a grade; senão, null.
export function patternToWeeks(pattern: unknown, weeks: unknown): CyclePosition[][] | null {
  if (!Array.isArray(pattern) || !Number.isInteger(weeks)) return null
  const count = weeks as number
  if (count < 1 || count > CYCLE_MAX_WEEKS || pattern.length !== 5 * count) return null
  if (!pattern.every((value) => value === 'onsite' || value === 'remote')) return null
  return Array.from({ length: count }, (_, index) => pattern.slice(index * 5, index * 5 + 5) as CyclePosition[])
}

// "Ciclo 3 semanas, 9P/6R" (semana a semana) ou "Ciclo 2P/1R" (os demais), o
// mesmo rótulo de CyclePattern::label.
export function cycleLabel(pattern: CyclePosition[], weeks: number | null = null): string {
  const onsite = pattern.filter((value) => value === 'onsite').length
  const counts = `${onsite}P/${pattern.length - onsite}R`
  if (weeks === null) return `Ciclo ${counts}`
  return `Ciclo ${weeks} ${weeks === 1 ? 'semana' : 'semanas'}, ${counts}`
}

// "Copiar de outro time, deslocado em N dias úteis" (desenho 3.1): com a
// mesma âncora, cada dia do padrão novo é o de N dias úteis antes no outro.
// Deslocar 1 dia o Time 1 (R P P) dá o Time 2 (P R P).
export function shiftPattern(pattern: CyclePosition[], days: number): CyclePosition[] {
  const size = pattern.length
  if (size === 0) return []
  const shift = ((Math.trunc(days) % size) + size) % size
  return pattern.map((_, index) => pattern[(index - shift + size) % size])
}

export function isMonday(date: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return false
  const value = new Date(`${date}T12:00:00Z`)
  return !Number.isNaN(value.getTime()) && value.getUTCDay() === 1
}

// A própria data se for segunda; senão, a próxima segunda (âncora padrão do semana a semana).
export function nextMonday(date: string): string {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return date
  const value = new Date(`${date}T12:00:00Z`)
  if (Number.isNaN(value.getTime())) return date
  value.setUTCDate(value.getUTCDate() + ((8 - value.getUTCDay()) % 7))
  return value.toISOString().slice(0, 10)
}

export function isBusinessDay(date: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return false
  const value = new Date(`${date}T12:00:00Z`)
  const day = value.getUTCDay()
  return !Number.isNaN(value.getTime()) && day >= 1 && day <= 5
}

// Mensagens do servidor (CyclePattern::fromPayload), na mesma ordem; null = válido.
export function cycleError(pattern: CyclePosition[], anchor: string, weekly: boolean): string | null {
  if (weekly && !isMonday(anchor)) return 'No ciclo semana a semana, a data inicial precisa ser uma segunda-feira.'
  if (!weekly && !isBusinessDay(anchor)) return 'A data inicial do ciclo precisa ser dia útil (segunda a sexta).'
  if (pattern.length < CYCLE_MIN_POSITIONS || pattern.length > CYCLE_MAX_POSITIONS) {
    return `O padrão do ciclo tem de ${CYCLE_MIN_POSITIONS} a ${CYCLE_MAX_POSITIONS} dias úteis.`
  }
  if (!pattern.includes('remote')) return 'Ciclo sem nenhum dia remoto: use a regra "Sempre presencial".'
  if (!pattern.includes('onsite')) return 'Ciclo sem nenhum dia presencial: use a regra "Sempre remoto".'
  return null
}

export type RotationMember = { id: number, employee_id: number, valid_from: string, valid_until: string | null, employee_active: boolean, is_current: boolean }
export type RotationVersion = { id: number, effective_from: string, pattern: CyclePosition[], anchor_date: string, weeks: number | null, label: string }
export type RotationTeam = { id: number, name: string, active: boolean, current_version: RotationVersion | null, versions: RotationVersion[], members: RotationMember[] }

// Time de cada técnico em que a pertença cobre a data ou ainda vai começar:
// a regra dele vem do time (a tela troca "Editar" por "Pelo time").
export function teamByEmployee(teams: RotationTeam[] | null | undefined, today: string): Map<number, string> {
  const result = new Map<number, string>()
  for (const team of teams || []) {
    for (const member of team.members || []) {
      if (member.valid_until === null || member.valid_until >= today) result.set(Number(member.employee_id), team.name)
    }
  }
  return result
}
