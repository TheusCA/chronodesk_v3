import { useEffect, useId, useRef } from 'react'
import { FOCUSABLE_SELECTOR, dialogKeyDown, isTopDialog, popDialog, pushDialog } from '../../lib/dialogFocus'

// [17f] Diálogo acessível (docs/DESENHO_CHG.md, seção 6.3): role="dialog" ou
// "alertdialog", aria-modal, título e descrição ligados por id, foco inicial
// escolhido, foco preso (Tab e Shift+Tab circulam dentro), Esc e clique fora
// pedem para fechar, e o foco volta para quem abriu. Com dois abertos (por
// exemplo, "Descartar alterações?" por cima de um formulário), só o de cima
// responde ao teclado.
export function Dialog({ open = true, role = 'dialog', title, description = null, onClose, initialFocusRef = null, size = 'max-w-lg', children }) {
  const titleId = useId()
  const descriptionId = useId()
  const panelRef = useRef(null)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    if (!open) return undefined
    const token = {}
    const previous = document.activeElement
    pushDialog(token)
    const panel = panelRef.current
    const target = initialFocusRef?.current || panel?.querySelector(FOCUSABLE_SELECTOR) || panel
    target?.focus()
    const onKeyDown = (event) => {
      if (isTopDialog(token)) dialogKeyDown(event, panelRef.current, () => onCloseRef.current?.())
    }
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      popDialog(token)
      if (previous && previous.isConnected && typeof previous.focus === 'function') previous.focus()
    }
  }, [open, initialFocusRef])

  if (!open) return null
  return (
    <div
      className="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-black/75 p-4 backdrop-blur-sm sm:items-center"
      data-dialog-backdrop=""
      // Sem o preventDefault, o clique no fundo (que não recebe foco) leva o
      // foco para o body depois que o diálogo o devolveu.
      onMouseDown={(event) => { if (event.target === event.currentTarget) { event.preventDefault(); onClose?.() } }}
    >
      <div
        aria-describedby={description ? descriptionId : undefined}
        aria-labelledby={titleId}
        aria-modal="true"
        className={`card relative my-6 w-full ${size}`}
        ref={panelRef}
        role={role}
        tabIndex={-1}
      >
        <h2 className="text-lg font-bold text-white" id={titleId}>{title}</h2>
        {description && <p className="mt-2 text-sm leading-6 text-slate-300" id={descriptionId}>{description}</p>}
        {children}
      </div>
    </div>
  )
}
