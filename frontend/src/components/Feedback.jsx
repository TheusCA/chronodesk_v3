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

export function Feedback({ feedback, onClose }) {
  if (!feedback) return null
  const variant = variants[feedback.type] || variants.success

  return (
    <div
      className={`fixed bottom-4 left-4 right-4 z-50 flex max-w-md items-start gap-3 rounded-card border px-4 py-3 text-sm shadow-2xl backdrop-blur sm:left-auto ${variant.colors}`}
      role={variant.role}
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
          type="button"
        >
          <Icon className="h-4 w-4" name="close" />
        </button>
      )}
    </div>
  )
}
