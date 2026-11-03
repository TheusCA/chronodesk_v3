import { useEffect, useRef } from 'react'
import { Dialog } from './Dialog'

// [17f, BB1] Confirmação de uma ação no servidor. O foco começa em "Cancelar".
// Enquanto envia, os botões ficam desabilitados e Esc ou clique fora não fecham.
// Se o servidor recusar, quem chamou mantém o diálogo aberto (o aviso fica por
// cima) e o foco volta para "Cancelar", porque o botão desabilitado o perdeu.
// children (opcional): campos acima dos botões, por exemplo uma justificativa.
export function ConfirmActionDialog({ title, description, confirmLabel, busyLabel, confirmClassName = 'btn-danger', busy = false, onConfirm, onCancel, returnFocusRef = null, children = null }) {
  const cancelRef = useRef(null)
  const wasBusy = useRef(false)

  useEffect(() => {
    if (wasBusy.current && !busy) cancelRef.current?.focus()
    wasBusy.current = busy
  }, [busy])

  return (
    <Dialog
      description={description}
      initialFocusRef={cancelRef}
      onClose={() => { if (!busy) onCancel() }}
      returnFocusRef={returnFocusRef}
      role="alertdialog"
      title={title}
    >
      {children}
      <div className="mt-5 flex flex-wrap justify-end gap-2">
        <button className={confirmClassName} disabled={busy} onClick={onConfirm} type="button">{busy ? busyLabel : confirmLabel}</button>
        <button className="btn-secondary" disabled={busy} onClick={onCancel} ref={cancelRef} type="button">Cancelar</button>
      </div>
    </Dialog>
  )
}
