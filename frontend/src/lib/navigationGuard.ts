// [17f] Registro de bloqueios de navegação (proteção contra perda de
// alterações, docs/DESENHO_CHG.md, seção 6.3). Sem bloqueio registrado, toda
// saída segue na hora, como antes. Com um formulário sujo, a saída espera a
// resposta do diálogo: "Descartar alterações" chama o proceed; "Continuar
// editando" não chama nada.

export type NavigationBlocker = {
  // Pede confirmação; chama proceed só se a pessoa descartar as alterações.
  ask: (proceed: () => void) => void
}

const blockers = new Set<NavigationBlocker>()

export function registerNavigationBlocker(blocker: NavigationBlocker): () => void {
  blockers.add(blocker)
  return () => {
    blockers.delete(blocker)
  }
}

export function navigationBlockerCount(): number {
  return blockers.size
}

// Segue na hora (e devolve true) ou deixa a decisão com o bloqueio mais
// recente (e devolve false). Um diálogo só, mesmo com vários formulários sujos.
export function guardNavigation(proceed: () => void): boolean {
  const pending = [...blockers]
  if (pending.length === 0) {
    proceed()
    return true
  }
  pending[pending.length - 1].ask(proceed)
  return false
}

// "Sair" do menu e qualquer outra saída que não passa pelo navigate.
export function guardLeave(action: () => void): void {
  guardNavigation(action)
}
