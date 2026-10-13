// F4 (desenho 9.6): estado do cadastro na aba Funcionários e resumo dos
// efeitos devolvidos por remover_funcionario.php (contrato das nove chaves).

export type EmployeeArchiveState = 'ativo' | 'arquivado' | 'inativo_antigo'

type EmployeeLike = {
  ativo?: unknown
  arquivado_em?: string | null
}

// Inativo com data: arquivado pelo F4. Inativo sem data: desativado antes do
// F4 (D5); ainda pode ser arquivado para encerrar PA, escala e ausências.
export function employeeArchiveState(item: EmployeeLike): EmployeeArchiveState {
  if (item.ativo !== false && item.ativo !== 0 && item.ativo !== '0') return 'ativo'
  return item.arquivado_em ? 'arquivado' : 'inativo_antigo'
}

// Filtro da lista: 'ativos', 'arquivados' (arquivado e inativo antigo) ou '' (todos).
export function matchesArchiveFilter(item: EmployeeLike, status: string): boolean {
  if (!status) return true
  return (status === 'ativos') === (employeeArchiveState(item) === 'ativo')
}

const COUNT_LABELS: Array<[string, string, string]> = [
  ['pa_encerrados', 'vínculo de PA encerrado', 'vínculos de PA encerrados'],
  ['pa_removidos', 'vínculo de PA futuro removido', 'vínculos de PA futuros removidos'],
  ['regras_encerradas', 'regra de escala encerrada', 'regras de escala encerradas'],
  ['regras_removidas', 'regra de escala futura removida', 'regras de escala futuras removidas'],
  ['excecoes_removidas', 'exceção de escala futura removida', 'exceções de escala futuras removidas'],
  ['ausencias_canceladas', 'ausência futura cancelada', 'ausências futuras canceladas'],
  ['ausencias_cortadas', 'ausência em curso encerrada ontem', 'ausências em curso encerradas ontem'],
]

export function archiveEffectsSummary(efeitos: Record<string, unknown> | null | undefined): string {
  const parts: string[] = []
  for (const [key, singular, plural] of COUNT_LABELS) {
    const count = Number(efeitos?.[key] ?? 0)
    if (Number.isInteger(count) && count > 0) parts.push(`${count} ${count === 1 ? singular : plural}`)
  }
  if (efeitos?.pausa_finalizada === true) parts.push('pausa aberta finalizada')
  if (efeitos?.solicitacao_descartada === true) parts.push('solicitação de pausa descartada')
  return parts.length > 0 ? `${parts.join('; ')}.` : 'Nada em aberto para encerrar.'
}
