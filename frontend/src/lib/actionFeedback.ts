// [17f, DD2] Tempo do aviso na tela, por tipo: erro e aviso pedem leitura e
// ficam 10 s; sucesso e informação, 5 s, como antes. O X fecha antes.
export const FEEDBACK_DURATION_MS: Readonly<Record<string, number>> = Object.freeze({
  error: 10000,
  warning: 10000,
  success: 5000,
  info: 5000,
})

export const ACTION_FEEDBACK = Object.freeze({
  changesSaved: 'Alterações salvas com sucesso.',
  calendarCreated: 'Registro adicionado com sucesso.',
  employeeCreated: 'Funcionário adicionado com sucesso.',
  employeeUpdated: 'Funcionário atualizado com sucesso.',
  employeeArchived: 'Funcionário arquivado.',
  employeeRestored: 'Cadastro restaurado. PA e escala precisam ser cadastrados de novo.',
  userCreated: 'Usuário criado com sucesso.',
  userUpdated: 'Usuário atualizado com sucesso.',
  userRemoved: 'Usuário removido com sucesso.',
  scheduleSaved: 'Escala salva com sucesso.',
  scheduleRuleRemoved: 'Regra de escala removida com sucesso.',
  scheduleExceptionSaved: 'Exceção salva com sucesso.',
  absenceCreated: 'Ausência lançada com sucesso.',
  absenceUpdated: 'Ausência alterada com sucesso.',
  absenceCancelled: 'Ausência cancelada com sucesso.',
  importCompleted: 'Importação concluída com sucesso.',
  requestCreated: 'Solicitação registrada com sucesso.',
  requestApproved: 'Solicitação aprovada com sucesso.',
  requestRejected: 'Solicitação rejeitada com sucesso.',
  oncallCreated: 'Plantão cadastrado com sucesso.',
  paLinked: 'Vínculo realizado com sucesso.',
  paUnlinked: 'Vínculo removido com sucesso.',
  criticalCreated: 'Chamado crítico registrado com sucesso.',
  criticalUpdated: 'Chamado crítico atualizado com sucesso.',
  criticalStatusUpdated: 'Chamado crítico atualizado com sucesso.',
  documentUploaded: 'Documento adicionado com sucesso.',
  documentRemoved: 'Documento removido com sucesso.',
  shiftUploaded: 'Escala de sábado publicada com sucesso.',
})

export function decisionFeedback(decision: string): string {
  return decision === 'approved' || decision === 'aprovar'
    ? ACTION_FEEDBACK.requestApproved
    : ACTION_FEEDBACK.requestRejected
}

export function importFeedback(result?: Record<string, unknown> | null): string {
  const processed = Number(
    result?.processed_count
    ?? result?.processed
    ?? result?.imported_count
    ?? result?.imported
    ?? result?.total
    ?? 0,
  )

  if (processed > 0) {
    return `Importação concluída: ${processed} registros processados.`
  }

  return ACTION_FEEDBACK.importCompleted
}
