import { Icon } from './Icon'

export function LoadingState({ label = 'Carregando dados...' }) {
  return (
    <div className="space-y-4" aria-label={label} aria-live="polite" role="status">
      <div className="flex items-center gap-3 text-sm text-muted">
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
    <div className="card break-words border-dashed border-line bg-surface-2 py-12 text-center">
      <span className="mx-auto grid h-12 w-12 place-items-center rounded-card border border-line bg-surface text-muted" aria-hidden="true">
        <Icon className="h-5 w-5" name="file" />
      </span>
      <h3 className="mt-4 font-semibold text-ink-2">{title}</h3>
      <p className="mx-auto mt-2 max-w-xl text-sm text-muted">{description}</p>
    </div>
  )
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="card break-words border-danger-line bg-danger-bg" role="alert">
      <div className="flex items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-card border border-danger-line bg-danger-bg text-danger" aria-hidden="true">
          <Icon className="h-5 w-5" name="alert" />
        </span>
        <div>
          <h3 className="font-semibold text-danger">Não foi possível carregar os dados</h3>
          <p className="mt-2 text-sm text-danger">{message}</p>
          {onRetry && <button type="button" className="btn-secondary mt-4" onClick={onRetry}>Tentar novamente</button>}
        </div>
      </div>
    </div>
  )
}
