import { useResource } from '../hooks/useResource'
import { formatDuration } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

const cards = [
  ['tecnicos_monitorados', 'Técnicos monitorados', 'text-blue-300'],
  ['pausas_ativas', 'Pausas ativas', 'text-amber-300'],
  ['solicitacoes_pendentes', 'Aprovações pendentes', 'text-violet-300'],
  ['alertas_operacionais', 'Alertas operacionais', 'text-red-300'],
  ['plantonistas_ativos', 'Plantonistas ativos', 'text-emerald-300'],
  ['sobreavisos_ativos', 'Sobreavisos ativos', 'text-cyan-300'],
]

export function DashboardPage({ navigate, livePauses }) {
  const resource = useResource('portal/dashboard.php')
  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  const activePauses = livePauses.employees.filter((item) => item.em_pausa)
  const summary = { ...(resource.data?.summary || {}), pausas_ativas: activePauses.length }

  return (
    <div className="space-y-6">
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {cards.map(([key, label, color]) => (
          <article className="card card-interactive" key={key}>
            <p className="text-sm text-slate-500">{label}</p>
            <p className={`mt-3 text-3xl font-black ${color}`}>{summary[key] ?? 0}</p>
          </article>
        ))}
      </section>
      <section className="card">
        <div className="mb-5 flex items-center justify-between gap-4">
          <div>
            <h2 className="font-bold text-white">Pausas em andamento</h2>
            <p className="text-sm text-slate-500">Polling a cada 15s e contador sincronizado pelo horario do servidor.</p>
          </div>
          <button className="btn-secondary" onClick={() => navigate('/pausas')} type="button">Abrir pausas</button>
        </div>
        {activePauses.length === 0
          ? <EmptyState title="Nenhuma pausa ativa" description="A equipe está sem pausas em andamento neste momento." />
          : (
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {activePauses.map((item) => (
                <article className="rounded-xl border border-white/5 bg-slate-950/30 p-4" key={item.id}>
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-semibold text-slate-200">{item.nome}</p>
                      <p className="text-xs uppercase tracking-wider text-slate-500">Equipe {item.equipe}</p>
                    </div>
                    <span className="status-badge status-warning">{item.motivo_pausa}</span>
                  </div>
                  <p className="mt-4 font-mono text-2xl font-bold text-amber-300">{formatDuration(livePauses.elapsedFor(item))}</p>
                </article>
              ))}
            </div>
          )}
      </section>
    </div>
  )
}
