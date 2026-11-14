// [R3] Iniciais do avatar: a primeira letra do primeiro e do último nome.
// Palavra sem letra (só número, como o "01" de "Técnico 01 Exemplo") não
// conta, e a letra pode ter acento ("Ângela" vira "Â"). Login com ponto ou
// sublinhado ("lideranca.exemplo") conta como nomes separados. Sem nenhuma
// letra, devolve o texto de reserva.
const SEPARATORS = /[\s._]+/u
const LETTER = /\p{L}/u

export function nameInitials(name: unknown, fallback = ''): string {
  const words = String(name ?? '')
    .split(SEPARATORS)
    .map((word) => word.match(LETTER)?.[0] ?? '')
    .filter((letter) => letter !== '')
  if (words.length === 0) return fallback
  const first = words[0]
  const last = words.length > 1 ? words[words.length - 1] : ''
  return `${first}${last}`.toLocaleUpperCase('pt-BR')
}
