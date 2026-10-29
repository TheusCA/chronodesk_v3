import { useRef } from 'react'
import { Dialog } from './Dialog'

// [17f] Saída com alterações não salvas (B4). "Continuar editando" é o padrão:
// tem o foco, e Esc ou clique fora fazem o mesmo. Sem window.confirm.
export const DISCARD_MESSAGE = 'Há alterações não salvas. Se sair agora, elas serão perdidas.'

export function ConfirmDiscardDialog({ open, onContinue, onDiscard }) {
  const continueRef = useRef(null)
  return (
    <Dialog description={DISCARD_MESSAGE} initialFocusRef={continueRef} onClose={onContinue} open={open} role="alertdialog" title="Descartar alterações?">
      <div className="mt-5 flex flex-wrap justify-end gap-2">
        <button className="btn-danger" onClick={onDiscard} type="button">Descartar alterações</button>
        <button className="btn-primary" onClick={onContinue} ref={continueRef} type="button">Continuar editando</button>
      </div>
    </Dialog>
  )
}
