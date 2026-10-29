import { useCallback, useEffect, useRef, useState } from 'react'
import { registerNavigationBlocker } from '../lib/navigationGuard'

// [17f] Proteção contra perda de alterações (B4; docs/DESENHO_CHG.md, 6.3).
// "Sujo" vem do formulário (por exemplo, formState.isDirty do React Hook
// Form). Enquanto sujo: bloqueio de rota (menu, navigate, voltar e avançar,
// "Sair") e o aviso do navegador ao recarregar ou fechar a aba. Limpo ou
// desmontado: os dois saem, sem listener sobrando.

type ListenerTarget = Pick<Window, 'addEventListener' | 'removeEventListener'>

export function onBeforeUnloadWhileDirty(event: BeforeUnloadEvent): void {
  // O navegador mostra o aviso dele, com texto genérico.
  event.preventDefault()
  event.returnValue = ''
}

export function attachUnsavedChangesGuard(target: ListenerTarget, ask: (proceed: () => void) => void): () => void {
  const unregister = registerNavigationBlocker({ ask })
  target.addEventListener('beforeunload', onBeforeUnloadWhileDirty)
  return () => {
    unregister()
    target.removeEventListener('beforeunload', onBeforeUnloadWhileDirty)
  }
}

export function useUnsavedChangesGuard(isDirty: boolean) {
  const [pending, setPending] = useState<{ proceed: () => void } | null>(null)
  const dirtyRef = useRef(isDirty)

  useEffect(() => {
    dirtyRef.current = isDirty
    if (!isDirty) return undefined
    return attachUnsavedChangesGuard(window, (proceed) => setPending({ proceed }))
  }, [isDirty])

  // Saídas locais (fechar o modal, abrir outra edição). Troca de rota já passa
  // pelo bloqueio do roteador: não chame navigate por aqui. Depois de salvar
  // com sucesso, limpe o formulário (reset) e feche direto, sem requestLeave.
  const requestLeave = useCallback((action: () => void) => {
    if (!dirtyRef.current) {
      action()
      return
    }
    setPending({ proceed: action })
  }, [])

  const dialogProps = {
    open: pending !== null,
    onContinue: () => setPending(null),
    onDiscard: () => {
      const next = pending
      setPending(null)
      next?.proceed()
    },
  }

  return { requestLeave, dialogProps }
}
