import { Icon } from './Icon'

export function LoadingState({ label = 'Carregando dados...' }) {
  return (
    <div className="space-y-4" aria-label={label} aria-live="polite" role="status">
      <div className="flex items-center gap-3 text-sm text-slate-400">
        <span className="h-2 w-2 rounded-full bg-cyan-300 shadow-[0_0_14px_rgba(103,232,249,0.55)]" aria-hidden="true" />
        {label}
      </div>
      <div className="grid gap-4 md:grid-cols-3">
        {[0, 1, 2].map((item) => <div className="skeleton h-28" key={item} />)}
      </div>
    </div>
  )
}

export function EmptyState({ title = 'Nenhum dado disponível', description = 'Os registros aparecerão aqui quando forem cadastrados.' }) {
  return (
    <div className="card border-dashed border-white/10 bg-slate-950/30 py-12 text-center">
      <span className="mx-auto grid h-12 w-12 place-items-center rounded-card border border-white/10 bg-slate-900 text-slate-400">
        <Icon className="h-5 w-5" name="file" />
      </span>
      <h3 className="mt-4 font-semibold text-slate-200">{title}</h3>
      <p className="mx-auto mt-2 max-w-xl text-sm text-slate-400">{description}</p>
    </div>
  )
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="card border-red-500/40 bg-red-500/5">
      <div className="flex items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-card border border-red-400/20 bg-red-500/10 text-red-200">
          <Icon className="h-5 w-5" name="alert" />
        </span>
        <div>
          <h3 className="font-semibold text-red-200">Não foi possível carregar os dados</h3>
          <p className="mt-2 text-sm text-red-100/70">{message}</p>
          {onRetry && <button type="button" className="btn-secondary mt-4" onClick={onRetry}>Tentar novamente</button>}
        </div>
      </div>
    </div>
  )
}
