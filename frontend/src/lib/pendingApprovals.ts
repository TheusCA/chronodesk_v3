// [R3] Contador de pendências do item "Aprovações e admin" do menu, pela
// mesma resposta do solicitacoes_pendentes.php que a aba Aprovações usa
// (pausas e reuniões, horas extras e correções de ponto). Só o número sai
// daqui: nome, equipe e observação de quem pediu nunca chegam ao menu. Lista
// cortada pelo servidor (limite) vira "N+". Sem pendência ou sem resposta, null.
type PendingResponse = {
  solicitacoes?: unknown
  overtime?: unknown
  time_adjustments?: unknown
  overtime_truncated?: unknown
  time_adjustments_truncated?: unknown
} | null | undefined

const size = (value: unknown): number => (Array.isArray(value) ? value.length : 0)

export function pendingApprovalsLabel(data: PendingResponse): string | null {
  if (!data) return null
  const count = size(data.solicitacoes) + size(data.overtime) + size(data.time_adjustments)
  if (count === 0) return null
  const truncated = data.overtime_truncated === true || data.time_adjustments_truncated === true
  return `${count}${truncated ? '+' : ''}`
}
