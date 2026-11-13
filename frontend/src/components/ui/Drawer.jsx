import { Dialog } from './Dialog'
import { Icon } from './Icon'

// [R2] Painel lateral (D-R0-6): o Dialog do 17f com placement="end", então
// herda tudo dele: role="dialog", aria-modal, título e descrição ligados, foco
// preso, Esc e clique fora pedem para fechar (onClose decide, por exemplo,
// perguntando se descarta), trava de rolagem e o foco de volta para quem abriu
// (returnFocusRef quando quem abriu pode ter perdido o foco). footer: as ações,
// fixas no fim do painel.
export function Drawer({ title, description = null, onClose, initialFocusRef = null, returnFocusRef = null, footer = null, size = 'max-w-xl', children }) {
  return (
    <Dialog
      description={description}
      headerAction={(
        <button aria-label="Fechar painel" className="btn-ghost shrink-0 px-2.5" onClick={onClose} type="button">
          <Icon className="h-5 w-5" name="close" />
        </button>
      )}
      initialFocusRef={initialFocusRef}
      onClose={onClose}
      placement="end"
      returnFocusRef={returnFocusRef}
      size={size}
      title={title}
    >
      <div className="mt-6">{children}</div>
      {footer && <div className="drawer-footer">{footer}</div>}
    </Dialog>
  )
}
