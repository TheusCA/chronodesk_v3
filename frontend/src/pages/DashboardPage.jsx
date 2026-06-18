import { useResource } from '../hooks/useResource'
import { formatDuration, parseDate } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { MetricCard, SectionHeader, StatusDot, UserAvatar } from '../components/ui/Primitives'
import { Icon } from '../components/ui/Icon'

const metrics = [
  ['tecnicos_monitorados', 'Técnicos monitorados', 'info', 'users', 'Equipe operacional no painel'],
  ['cis_disponiveis', 'CIs disponíveis', 'success', 'shield', 'Aptos para atendimento agora'],
  ['pausas_ativas', 'Pausas ativas', 'warning', 'pause', 'Timers sincronizados pelo servidor'],
  ['solicitacoes_pendentes', 'Aprovações pendentes', 'warning', 'bell', 'Pausas, ponto e horas extras'],
  ['chamados_criticos_abertos', 'Chamados críticos', 'danger', 'alert', 'Incidentes abertos ou em guerra'],
  ['war_rooms_ativas', 'War rooms', 'danger', 'radio', 'Salas críticas em andamento'],
  ['plantonistas_ativos', 'Plantonistas ativos', 'success', 'phone', 'Cobertura operacional ativa'],
  ['sobreavisos_ativos', 'Sobreavisos ativos', 'info', 'radio', 'Cobertura de contingência'],
]

function updatedLabel(status, serverNow) {
  const updatedAt = parseDate(status?.server_now).getTime()
  if (!Number.isFinite(updatedAt) || !Number.isFinite(serverNow)) return 'Atualização automática ativa'
  const seconds = Math.max(0, Math.floor((serverNow - updatedAt) / 1000))
  return `Atualizado há ${seconds} segundo${seconds === 1 ? '' : 's'}`
}

export function DashboardPage({ navigate, session, livePauses, loading, onForceEndBreak }) {
  const resource = useResource('portal/dashboard.php', { intervalMs: 15000 })
  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  const activePauses = livePauses.employees.filter((item) => item.em_pausa)
  const pendingPauses = livePauses.employees.filter((item) => item.status_aprovacao === 'pendente').length
  const availableCis = livePauses.employees.filter((item) => !item.em_pausa && item.disponibilidade?.status === 'disponivel').length
  const meetingPauses = activePauses.filter((item) => String(item.motivo_pausa || '').toLowerCase().startsWith('reuni')).length
  const coffeePauses = activePauses.filter((item) => String(item.motivo_pausa || '').toLowerCase() === 'café').length
  const summary = {
    ...(resource.data?.summary || {}),
    cis_disponiveis: availableCis,
    pausas_ativas: activePauses.length,
    solicitacoes_pendentes: Math.max(resource.data?.summary?.solicitacoes_pendentes || 0, pendingPauses),
  }
  const canForceEndBreak = session.role === 'admin'
  const n1Available = (livePauses.status.n1 || []).filter((item) => !item.em_pausa && item.disponibilidade?.status === 'disponivel').length
  const n2Available = (livePauses.status.n2 || []).filter((item) => !item.em_pausa && item.disponibilidade?.status === 'disponivel').length

  return (
    <div className="space-y-7">
      <section className="card overflow-hidden border-cyan-400/15">
        <div className="grid gap-6 lg:grid-cols-[1.4fr_0.8fr] lg:items-center">
          <div>
            <p className="section-eyebrow">Portal SDK — Centro Operacional de TI</p>
            <h2 className="mt-2 text-2xl font-black tracking-tight text-white sm:text-3xl">Operação agora</h2>
            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-400">
              Painel de disponibilidade, pausas, aprovações e incidentes críticos da operação SDK.
            </p>
            <div className="mt-5 flex flex-wrap gap-2">
              <StatusDot tone={livePauses.connection === 'online' ? 'success' : 'warning'} label={livePauses.connection === 'online' ? 'Atualização automática ativa' : 'Conexão instável'} />
              <StatusDot tone="info" label={updatedLabel(livePauses.status, livePauses.serverNow)} />
            </div>
          </div>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
            <div className="rounded-card border border-white/10 bg-slate-950/45 p-4">
              <p className="metric-label">Disponíveis por equipe</p>
              <p className="mt-3 text-sm text-slate-300">N1 <strong className="ml-2 text-lg text-emerald-300">{n1Available}</strong></p>
              <p className="mt-2 text-sm text-slate-300">N2 <strong className="ml-2 text-lg text-emerald-300">{n2Available}</strong></p>
            </div>
            <div className="rounded-card border border-white/10 bg-slate-950/45 p-4">
              <p className="metric-label">Pausas por tipo</p>
              <p className="mt-3 text-sm text-slate-300">Reunião <strong className="ml-2 text-lg text-amber-300">{meetingPauses}</strong></p>
              <p className="mt-2 text-sm text-slate-300">Café <strong className="ml-2 text-lg text-cyan-200">{coffeePauses}</strong></p>
            </div>
          </div>
        </div>
      </section>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map(([key, label, tone, icon, detail]) => (
          <MetricCard detail={detail} icon={icon} key={key} label={label} tone={tone} value={summary[key] ?? 0} />
        ))}
      </section>

      <section className="card">
        <SectionHeader
          action={<button className="btn-secondary" onClick={() => navigate('/pausas')} type="button"><Icon className="h-4 w-4" name="pause" /> Abrir pausas</button>}
          description="Timers continuam sincronizados pelo horário do servidor e atualizados sem recarregar a tela."
          eyebrow="Monitoramento em tempo real"
          title="Pausas em andamento"
        />
        {activePauses.length === 0
          ? <div className="mt-5"><EmptyState title="Nenhuma pausa ativa no momento" description="A equipe está sem pausas em andamento neste momento." /></div>
          : (
            <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {activePauses.map((item) => (
                <article className="operator-card operator-card-paused" key={item.id}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                      <UserAvatar name={item.nome} />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-200">{item.nome}</p>
                        <p className="text-xs uppercase tracking-wider text-slate-500">Equipe {item.equipe}</p>
                      </div>
                    </div>
                    <span className="status-badge status-warning">{item.motivo_pausa}</span>
                  </div>
                  <p className="mt-5 rounded-card border border-amber-400/15 bg-amber-500/5 px-3 py-2 font-mono text-2xl font-bold text-amber-200">{formatDuration(livePauses.elapsedFor(item))}</p>
                  {String(item.motivo_pausa || '').toLowerCase().startsWith('reuni') && (
                    <p
                      className="mt-3 line-clamp-2 rounded-lg border border-white/5 bg-slate-950/40 px-3 py-2 text-xs leading-5 text-slate-400"
                      title={item.observacao_reuniao || 'Sem observação informada'}
                    >
                      {item.observacao_reuniao || 'Sem observação informada'}
                    </p>
                  )}
                  {canForceEndBreak && (
                    <button
                      className="btn-danger mt-4 min-h-9 px-3 py-1.5 text-xs"
                      disabled={loading}
                      onClick={() => onForceEndBreak(item)}
                      title="Derrubar pausa do CI"
                      type="button"
                    >
                      {loading ? 'Finalizando...' : 'Derrubar pausa'}
                    </button>
                  )}
                </article>
              ))}
            </div>
          )}
      </section>
    </div>
  )
}
