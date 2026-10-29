// [17f] "Sujo" para formulários com useState (sem o isDirty do React Hook
// Form): algum campo diferente do valor com que o formulário abriu. Vazio,
// nulo e indefinido contam como iguais; números e textos comparam pelo texto
// (o campo devolve "12" para o 12 que veio do servidor).
type FormValues = Record<string, unknown>

function asText(value: unknown): string {
  return value === null || value === undefined ? '' : String(value)
}

export function formValuesChanged(initial: FormValues, current: FormValues): boolean {
  const keys = new Set([...Object.keys(initial), ...Object.keys(current)])
  for (const key of keys) {
    if (asText(initial[key]) !== asText(current[key])) return true
  }
  return false
}
