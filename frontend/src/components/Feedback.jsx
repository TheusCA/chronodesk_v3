import { createPortal } from 'react-dom'
import { Icon } from './ui/Icon'

const variants = {
  success: {
    colors: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100',
    icon: 'shield',
    title: 'Sucesso',
    role: 'status',
  },
  error: {
    colors: 'border-red-400/45 bg-red-500/15 text-red-100',
    icon: 'alert',
    title: 'Atenção',
    role: 'alert',
  },
  warning: {
    colors: 'border-amber-400/45 bg-amber-500/15 text-amber-100',
    icon: 'alert',
    title: 'Aviso',
    role: 'status',
  },
  info: {
    colors: 'border-blue-400/40 bg-blue-500/15 text-blue-100',
    icon: 'bell',
    title: 'Informação',
    role: 'status',
  },
}

// [17f, DD2] Duas regiões vivas ficam no body o tempo todo, vazias sem aviso:
// erro em role="alert" (assertive) e os demais em role="status" (polite). O
// aviso entra dentro da região já existente, e o leitor de tela o anuncia; uma
// região criada junto com o texto pode não ser lida. A key (id do aviso) faz a
// mesma mensagem repetida entrar de novo e ser lida outra vez.
export function Feedback({ feedback, onClose }) {
  const variant = feedback ? variants[feedback.type] || variants.success : null

  const toast = feedback && (
    <div
      className={`portal-toast fixed bottom-4 left-4 right-4 z-[70] flex max-w-md items-start gap-3 rounded-card border px-4 py-3 text-sm shadow-2xl backdrop-blur sm:left-auto ${variant.colors}`}
      data-feedback-type={feedback.type || 'success'}
      key={feedback.id ?? feedback.message}
    >
      <Icon className="mt-0.5 h-4 w-4 shrink-0" name={variant.icon} />
      <div className="min-w-0 flex-1">
        <strong className="block text-xs uppercase tracking-[0.14em]">{variant.title}</strong>
        <span className="mt-1 block leading-5 text-slate-100">{feedback.message}</span>
      </div>
      {onClose && (
        <button
          aria-label="Fechar mensagem"
          className="-mr-1 rounded-md p-1 text-current/70 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
          onClick={onClose}
          // Fechar o aviso não tira o foco de quem está com ele (por exemplo, um diálogo aberto).
          onMouseDown={(event) => event.preventDefault()}
          type="button"
        >
          <Icon className="h-4 w-4" name="close" />
        </button>
      )}
    </div>
  )
  const regions = (
    <>
      <div aria-atomic="true" aria-live="polite" data-feedback-region="polite" role="status">{variant?.role === 'status' && toast}</div>
      <div aria-atomic="true" aria-live="assertive" data-feedback-region="assertive" role="alert">{variant?.role === 'alert' && toast}</div>
    </>
  )
  // [17f, BB1] No body e acima do Dialog (z-[60]): dentro da página, o isolate da
  // .portal-tech-shell deixaria o aviso abaixo de qualquer diálogo. As regiões
  // não têm posição nem z-index: o aviso fixo continua no contexto do body.
  // Sem document (QA no Node), sem portal.
  return typeof document === 'undefined' ? regions : createPortal(regions, document.body)
}
