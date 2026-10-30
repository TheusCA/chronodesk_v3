import { useRef, useState } from 'react'
import type { RefObject } from 'react'
import { decisionFeedback } from '../lib/actionFeedback'
import { runAction } from '../lib/actionRunner'
import { post } from '../lib/api'
import { DECISION_ENDPOINTS } from '../lib/decisionConfirm'
import type { Decision, DecisionItem, DecisionKind } from '../lib/decisionConfirm'

type Refresh = () => Promise<unknown> | unknown
type Notify = (message: string, type?: string) => void
type DecisionRequest = { kind: DecisionKind; item: DecisionItem; decision: Decision }

// [17g] A mesma função para a linha, o cartão e a aba Aprovações: pergunta antes
// e só então envia. Recusa do servidor: o diálogo fica aberto, com o aviso por
// cima, e a lista atualiza por trás (a solicitação pode ter sido decidida por
// outra pessoa). O servidor confere tudo de novo.
export function useDecisionConfirm(notify: Notify, refresh: Refresh[]) {
  const [request, setRequest] = useState<DecisionRequest | null>(null)
  const [busy, setBusy] = useState(false)
  const origin = useRef<{ trigger: HTMLElement | null; sectionTitle: RefObject<HTMLElement | null> | null }>({ trigger: null, sectionTitle: null })
  // Lido ao fechar: com a decisão gravada, o botão já saiu da lista e o foco vai ao título da seção.
  const returnFocusRef = useRef({
    get current() {
      const { trigger, sectionTitle } = origin.current
      return trigger?.isConnected ? trigger : sectionTitle?.current ?? null
    },
  }).current

  function ask(kind: DecisionKind, item: DecisionItem, decision: Decision, trigger: HTMLElement, sectionTitle: RefObject<HTMLElement | null>) {
    origin.current = { trigger, sectionTitle }
    setRequest({ kind, item, decision })
  }

  async function onConfirm() {
    if (!request) return
    const { kind, item, decision } = request
    setBusy(true)
    await runAction({
      action: () => post(DECISION_ENDPOINTS[kind], { action: 'decision', id: item.id, decision }),
      refresh,
      notify,
      successMessage: decisionFeedback(decision),
      onSuccess: () => setRequest(null),
      onError: () => { for (const refreshList of refresh) void Promise.resolve(refreshList()).catch(() => {}) },
    })
    setBusy(false)
  }

  return { ask, dialogProps: { request, busy, onConfirm, onCancel: () => setRequest(null), returnFocusRef } }
}
