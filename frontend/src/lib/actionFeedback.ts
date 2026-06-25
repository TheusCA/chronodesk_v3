export const ACTION_FEEDBACK = Object.freeze({
  changesSaved: 'Alterações salvas com sucesso.',
  calendarCreated: 'Registro adicionado com sucesso.',
  employeeCreated: 'Funcionário adicionado com sucesso.',
  employeeUpdated: 'Funcionário atualizado com sucesso.',
  employeeRemoved: 'Funcionário removido com sucesso.',
  userCreated: 'Usuário criado com sucesso.',
  userUpdated: 'Usuário atualizado com sucesso.',
  userRemoved: 'Usuário removido com sucesso.',
  scheduleSaved: 'Escala salva com sucesso.',
  scheduleRuleRemoved: 'Regra de escala removida com sucesso.',
  scheduleExceptionSaved: 'Exceção salva com sucesso.',
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
