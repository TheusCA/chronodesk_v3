function MetricGroup({ title, values = {}, formatter = (value) => value }) {
  return (
    <section className="card">
      <h3 className="mb-4 font-bold">{title}</h3>
      <div className="space-y-2">
        {Object.keys(values).length === 0 && <p className="text-sm text-slate-500">Sem dados.</p>}
        {Object.entries(values).map(([label, value]) => (
          <div className="flex justify-between gap-4 text-sm" key={label}>
            <span className="truncate text-slate-400">{label}</span>
            <strong>{formatter(value)}</strong>
          </div>
        ))}
      </div>
    </section>
  )
}

const duration = (seconds) => `${Math.floor(Number(seconds) / 60)}m ${Math.round(Number(seconds) % 60)}s`

export function MetricasDashboard({ metrics }) {
  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold">Métricas</h2>
        <p className="text-slate-400">Indicadores consolidados de pausas.</p>
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <MetricGroup title="Pausas por equipe" values={metrics.total_pausas_equipe} />
        <MetricGroup title="Tempo por equipe" values={metrics.duracao_total_equipe} formatter={duration} />
        <MetricGroup title="Pausas por motivo" values={metrics.total_pausas_por_motivo} />
        <MetricGroup title="Pausas por colaborador" values={metrics.total_pausas_funcionario} />
        <MetricGroup title="Média por colaborador" values={metrics.duracao_media_funcionario} formatter={duration} />
        <MetricGroup title="Pausas excedidas" values={metrics.pausas_excedidas} />
      </div>
    </div>
  )
}
