import { useEffect, useId, useRef } from 'react'
import { FOCUSABLE_SELECTOR, dialogKeyDown, isTopDialog, popDialog, pushDialog } from '../../lib/dialogFocus'

// [17f] Diálogo acessível (docs/DESENHO_CHG.md, seção 6.3): role="dialog" ou
// "alertdialog", aria-modal, título e descrição ligados por id, foco inicial
// escolhido, foco preso (Tab e Shift+Tab circulam dentro), Esc e clique fora
// pedem para fechar, e o foco volta para quem abriu. Com dois abertos (por
// exemplo, "Descartar alterações?" por cima de um formulário), só o de cima
// responde ao teclado.
// Opcionais para formulários longos: eyebrow acima do título, headerAction à
// direita dele (por exemplo, "Cancelar") e panelClassName para a borda.
// returnFocusRef: para onde o foco volta quando quem abriu perdeu o foco antes
// do diálogo montar (por exemplo, um botão que fica desabilitado enquanto carrega).
export function Dialog({ open = true, role = 'dialog', title, description = null, eyebrow = null, headerAction = null, onClose, initialFocusRef = null, returnFocusRef = null, size = 'max-w-lg', titleClassName = 'text-lg', panelClassName = '', children }) {
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
      // Intencional: vale quem abriu no momento em que o diálogo fecha.
      // eslint-disable-next-line react-hooks/exhaustive-deps
      const back = returnFocusRef?.current?.isConnected ? returnFocusRef.current : previous
      if (back && back.isConnected && typeof back.focus === 'function') back.focus()
    }
  }, [open, initialFocusRef, returnFocusRef])

  if (!open) return null
  return (
    <div
      className="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-black/75 p-4 backdrop-blur-sm"
      data-dialog-backdrop=""
      // Sem o preventDefault, o clique no fundo (que não recebe foco) leva o
      // foco para o body depois que o diálogo o devolveu.
      onMouseDown={(event) => { if (event.target === event.currentTarget) { event.preventDefault(); onClose?.() } }}
    >
      <div
        aria-describedby={description ? descriptionId : undefined}
        aria-labelledby={titleId}
        aria-modal="true"
        // my-auto centraliza quando cabe e, quando o conteúdo é mais alto que a
        // tela, vira 0 e deixa o topo alcançável pela rolagem do fundo.
        className={`card relative my-auto w-full ${size} ${panelClassName}`}
        ref={panelRef}
        role={role}
        tabIndex={-1}
      >
        <div className={headerAction ? 'flex items-start justify-between gap-4' : undefined}>
          <div className="min-w-0">
            {eyebrow && <p className="section-eyebrow">{eyebrow}</p>}
            <h2 className={`${eyebrow ? 'mt-2 ' : ''}${titleClassName} font-bold text-white`} id={titleId}>{title}</h2>
            {description && <p className="mt-2 text-sm leading-6 text-slate-300" id={descriptionId}>{description}</p>}
          </div>
          {headerAction}
        </div>
        {children}
      </div>
    </div>
  )
}
