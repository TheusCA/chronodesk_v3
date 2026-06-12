export function Header({ session, activeView, onViewChange, onLogout }) {
  const authenticated = session.ci.autenticado || session.gestor.autenticado
  const views = [
    ['pausas', 'Pausas'],
    ...(session.gestor.autenticado ? [['admin', 'Admin'], ['metricas', 'Métricas']] : []),
  ]

  return (
    <header className="sticky top-0 z-40 border-b border-chrono-border bg-chrono-bg/90 backdrop-blur">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8">
        <div>
          <h1 className="text-xl font-bold tracking-tight">ChronoDesk</h1>
          <p className="text-xs text-slate-400">Gestão de pausas da equipe</p>
        </div>
        <nav className="flex flex-wrap items-center gap-2">
          {views.map(([value, label]) => (
            <button
              key={value}
              type="button"
              className={activeView === value ? 'btn-primary' : 'btn-secondary'}
              onClick={() => onViewChange(value)}
            >
              {label}
            </button>
          ))}
          {authenticated && <button type="button" className="btn-danger" onClick={onLogout}>Sair</button>}
        </nav>
      </div>
    </header>
  )
}
