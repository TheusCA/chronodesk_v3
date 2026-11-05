// [9.12.4] "Exceção por data" da Escala: o formulário carrega a exceção já
// gravada para o colaborador e a data escolhidos. A fonte é o GET de
// portal/schedules.php (campo exceptions, com a observação): o da tela, quando
// o período e os filtros carregados cobrem a escolha, ou uma consulta só
// daquele colaborador e dia. Sem mudança no servidor.

export type ExceptionFormValues = { exception_type: string; note: string }

export type StoredException = {
  id?: number | string
  employee_id: number | string
  exception_date: string
  exception_type: string
  note?: string | null
}

type LoadedPeriod = { from?: string; to?: string } | null | undefined
type ScheduleFilters = { team?: string; employee_id?: string | number }

// Sem exceção gravada: o tipo padrão (presencial para a Liderança, que não
// tem remoto) e sem observação.
export function exceptionDefaults(isLeadership: boolean): ExceptionFormValues {
  return { exception_type: isLeadership ? 'onsite' : 'remote', note: '' }
}

// Os dados carregados pela tela servem só se trazem todas as exceções do
// colaborador naquela data: data dentro do período devolvido pelo servidor,
// sem filtro de equipe e sem filtro de outro colaborador.
export function exceptionLoadCovers(period: LoadedPeriod, filters: ScheduleFilters, employeeId: string, date: string): boolean {
  if (!period?.from || !period?.to || !employeeId || !date) return false
  if (date < period.from || date > period.to) return false
  if (filters.team) return false
  return !filters.employee_id || String(filters.employee_id) === String(employeeId)
}

export function storedExceptionFor(exceptions: unknown, employeeId: string, date: string): StoredException | null {
  if (!Array.isArray(exceptions)) return null
  const found = exceptions.find((item: StoredException) => String(item?.employee_id) === String(employeeId) && item?.exception_date === date)
  return found ?? null
}

// Valores com que o formulário abre: os gravados, ou os padrões.
export function exceptionFormValues(stored: StoredException | null, isLeadership: boolean): ExceptionFormValues {
  if (!stored) return exceptionDefaults(isLeadership)
  return { exception_type: stored.exception_type, note: stored.note ?? '' }
}

export function exceptionKey(employeeId: string, date: string): string {
  return employeeId && date ? `${employeeId}:${date}` : ''
}
