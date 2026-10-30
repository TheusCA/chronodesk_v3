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

// [17f, BB2] Enquanto houver um diálogo aberto, a janela não rola. A barra some
// e a largura dela vira padding, para a página não pular. Sai com o último.
let scrollLocks = 0
let restoreScroll: (() => void) | null = null

export function lockPageScroll(doc: Document): () => void {
  scrollLocks += 1
  if (scrollLocks === 1) {
    const { body, documentElement } = doc
    const { overflow, paddingRight } = body.style
    const barWidth = (doc.defaultView?.innerWidth ?? documentElement.clientWidth) - documentElement.clientWidth
    if (barWidth > 0) {
      const current = Number.parseFloat(doc.defaultView?.getComputedStyle(body).paddingRight ?? '0') || 0
      body.style.paddingRight = `${current + barWidth}px`
    }
    body.style.overflow = 'hidden'
    restoreScroll = () => {
      body.style.overflow = overflow
      body.style.paddingRight = paddingRight
    }
  }
  let released = false
  return () => {
    if (released) return
    released = true
    scrollLocks -= 1
    if (scrollLocks === 0) {
      restoreScroll?.()
      restoreScroll = null
    }
  }
}

// [17f, BB3] Sem destino para o foco, ele vai ao título da página, nunca ao body.
// Sem rolar: quem está no meio da página continua vendo o mesmo lugar.
export function focusPageTitle(doc: Document): void {
  doc.querySelector<HTMLElement>('[data-page-title]')?.focus({ preventScroll: true })
}

export function focusPageTitleIfLost(doc: Document): void {
  const active = doc.activeElement
  if (!active || active === doc.body || !active.isConnected) focusPageTitle(doc)
}

type KeyEventLike = Pick<KeyboardEvent, 'key' | 'shiftKey' | 'preventDefault' | 'stopPropagation'>
type FocusableLike = { focus: () => void; getClientRects?: () => ArrayLike<unknown> }
type ContainerLike = FocusableLike & {
  querySelectorAll: (selector: string) => ArrayLike<FocusableLike>
  contains: (node: unknown) => boolean
  ownerDocument: { activeElement: unknown }
}

// Sem caixa na tela (display: none, como um input de arquivo escondido), o
// elemento não recebe foco e não pode ser o último da volta.
function isRendered(item: FocusableLike): boolean {
  return !item.getClientRects || item.getClientRects().length > 0
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
  const items = Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR)).filter(isRendered)
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
