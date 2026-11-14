// [R4a] Tempo de uma pausa em andamento, para Pausas e para a Visão
// operacional. A regra é a do servidor (GerenciadorPausas): Pessoal e
// Reunião não contam no limite; os outros motivos usam o limite que o
// status.php manda em duracao_limite_segundos (a configuração de pausas).
// Sem o campo, os 20 min de antes (o mesmo padrão da tela até o R4a).
type PauseLike = {
  motivo_pausa?: unknown
  duracao_limite_segundos?: unknown
} | null | undefined

const DEFAULT_LIMIT_SECONDS = 1200

export function pauseHasNoLimit(motivo: unknown): boolean {
  const reason = String(motivo ?? '').toLowerCase()
  return reason === 'pessoal' || reason.startsWith('reuni')
}

export function isMeeting(motivo: unknown): boolean {
  return String(motivo ?? '').toLowerCase().startsWith('reuni')
}

// Limite em segundos; 0 = sem limite.
export function pauseLimitSeconds(item: PauseLike): number {
  if (pauseHasNoLimit(item?.motivo_pausa)) return 0
  const limit = Number(item?.duracao_limite_segundos)
  return Number.isFinite(limit) && limit > 0 ? limit : DEFAULT_LIMIT_SECONDS
}

export type PauseTiming = {
  limit: number
  elapsed: number
  overdue: boolean
  // Segundos que faltam (positivo) ou que passaram do limite (negativo); null sem limite.
  remaining: number | null
  ratio: number
}

export function pauseTiming(item: PauseLike, elapsedSeconds: number): PauseTiming {
  const limit = pauseLimitSeconds(item)
  const elapsed = Math.max(0, Math.floor(Number(elapsedSeconds) || 0))
  if (limit === 0) return { limit, elapsed, overdue: false, remaining: null, ratio: 0 }
  return { limit, elapsed, overdue: elapsed > limit, remaining: limit - elapsed, ratio: elapsed / limit }
}

// "12:05" (minutos e segundos), "1:02:05" de uma hora para cima.
export function clockDuration(totalSeconds: number): string {
  const total = Math.max(0, Math.floor(Number(totalSeconds) || 0))
  const hours = Math.floor(total / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const seconds = String(total % 60).padStart(2, '0')
  return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`
}

// O que falta ou o que passou, sempre em palavras (nunca só a cor).
export function pauseTimingNote(timing: PauseTiming): string {
  if (timing.remaining === null) return 'sem limite'
  return timing.remaining >= 0 ? `faltam ${clockDuration(timing.remaining)}` : `passou ${clockDuration(-timing.remaining)}`
}
