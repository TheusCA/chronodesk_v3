type DateInput = string | number | Date | null | undefined

export function formatDuration(totalSeconds: unknown = 0): string {
  const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0))
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const remaining = seconds % 60
  if (hours > 0) return `${hours}h ${minutes}m ${remaining}s`
  return `${minutes}m ${String(remaining).padStart(2, '0')}s`
}

export function formatDateTime(value: DateInput): string {
  if (!value) return 'Não informado'
  const date = parseDate(value)
  if (Number.isNaN(date.getTime())) return 'Data inválida'
  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'medium',
  }).format(date)
}

export function formatDate(value: DateInput): string {
  if (!value) return 'Não informado'
  const date = parseDate(value)
  if (Number.isNaN(date.getTime())) return 'Data inválida'
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(date)
}

export function parseDate(value: DateInput): Date {
  if (value instanceof Date) return value
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [year, month, day] = value.split('-').map(Number)
    return new Date(year, month - 1, day)
  }
  const normalized = typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(value)
    ? value.replace(' ', 'T')
    : value
  return new Date(normalized as string | number)
}

export function sumValues(values: Record<string, unknown> = {}): number {
  return Object.values(values).reduce<number>((total, value) => total + Number(value || 0), 0)
}
