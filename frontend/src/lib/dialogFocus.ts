// [17f] Teclado do Dialog (src/components/ui/Dialog.jsx): foco preso e Esc,
// e a pilha de diálogos abertos (só o de cima responde ao teclado).

export const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

const openDialogs: object[] = []

export function pushDialog(token: object): void {
  openDialogs.push(token)
}

export function popDialog(token: object): void {
  const index = openDialogs.lastIndexOf(token)
  if (index >= 0) openDialogs.splice(index, 1)
}

export function isTopDialog(token: object): boolean {
  return openDialogs.length > 0 && openDialogs[openDialogs.length - 1] === token
}

export function openDialogCount(): number {
  return openDialogs.length
}

type KeyEventLike = Pick<KeyboardEvent, 'key' | 'shiftKey' | 'preventDefault' | 'stopPropagation'>
type FocusableLike = { focus: () => void }
type ContainerLike = FocusableLike & {
  querySelectorAll: (selector: string) => ArrayLike<FocusableLike>
  contains: (node: unknown) => boolean
  ownerDocument: { activeElement: unknown }
}

// Esc pede para fechar; Tab e Shift+Tab circulam dentro do diálogo (e trazem
// de volta o foco que estiver fora dele).
export function dialogKeyDown(event: KeyEventLike, container: ContainerLike | null, onClose?: () => void): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    onClose?.()
    return
  }
  if (event.key !== 'Tab' || !container) return
  const items = Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR))
  const active = container.ownerDocument.activeElement
  if (items.length === 0) {
    event.preventDefault()
    container.focus()
    return
  }
  const first = items[0]
  const last = items[items.length - 1]
  const outside = !container.contains(active)
  if (event.shiftKey && (active === first || outside)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (active === last || outside)) {
    event.preventDefault()
    first.focus()
  }
}
