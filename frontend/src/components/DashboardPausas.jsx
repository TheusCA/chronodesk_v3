import { FinalizarPausaCard } from './FinalizarPausaCard'
import { IniciarPausaCard } from './IniciarPausaCard'
import { StatusColaboradorCard } from './StatusColaboradorCard'

export function DashboardPausas({ session, status, actionLoading, onStart, onRequest, onFinish }) {
  const funcionarios = [...(status.n1 || []), ...(status.n2 || [])]
  const current = funcionarios.find((item) => item.id === session.ci.funcionario_id)

  return (
    <div className="space-y-6">
      <section>
        <p className="text-sm text-slate-400">Logado como</p>
        <h2 className="text-2xl font-bold">{session.ci.nome}</h2>
      </section>
      <div className="grid gap-4 md:grid-cols-2">
        <IniciarPausaCard onStart={onStart} onRequest={onRequest} loading={actionLoading} />
        <FinalizarPausaCard canFinish={Boolean(current?.em_pausa)} onFinish={onFinish} loading={actionLoading} />
      </div>
      {['n1', 'n2'].map((team) => (
        <section key={team}>
          <h2 className="mb-3 text-sm font-bold uppercase tracking-widest text-slate-400">Equipe {team}</h2>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {(status[team] || []).map((funcionario) => (
              <StatusColaboradorCard key={funcionario.id} funcionario={funcionario} />
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}
