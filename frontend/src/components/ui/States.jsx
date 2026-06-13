export function LoadingState({ label = 'Carregando dados...' }) {
  return (
    <div className="grid gap-4 md:grid-cols-3" aria-label={label}>
      {[0, 1, 2].map((item) => <div className="skeleton h-28" key={item} />)}
    </div>
  )
}

export function EmptyState({ title = 'Nenhum dado disponível', description = 'Os registros aparecerão aqui quando forem cadastrados.' }) {
  return (
    <div className="card border-dashed py-12 text-center">
      <h3 className="font-semibold text-slate-200">{title}</h3>
      <p className="mx-auto mt-2 max-w-xl text-sm text-slate-400">{description}</p>
    </div>
  )
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="card border-red-500/40 bg-red-500/5">
      <h3 className="font-semibold text-red-200">Não foi possível carregar os dados</h3>
      <p className="mt-2 text-sm text-red-100/70">{message}</p>
      {onRetry && <button type="button" className="btn-secondary mt-4" onClick={onRetry}>Tentar novamente</button>}
    </div>
  )
}
