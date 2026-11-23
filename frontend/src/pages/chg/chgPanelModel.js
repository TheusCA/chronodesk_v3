// [Ajustes do CHG, D-CHG-35] Campos do painel "Abrir" que gravam ao sair:
// pendente = valor digitado diferente do carregado (sem os espacos das pontas).

const FIELD = '[data-chg-field]'

/** Campos do painel com valor digitado e ainda não gravado. */
export function pendingFields(root) {
  if (!root) return []
  return [...root.querySelectorAll(FIELD)].filter((field) => field.value.trim() !== field.defaultValue.trim())
}

/** Devolve o valor carregado a cada campo pendente (descartar). */
export function resetPendingFields(root) {
  for (const field of pendingFields(root)) field.value = field.defaultValue
}
