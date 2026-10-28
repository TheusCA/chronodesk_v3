import { useResource } from '../hooks/useResource'
import { formatDuration, parseDate } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { InlineAlert, MetricCard, SectionHeader, StatusDot, UserAvatar } from '../components/ui/Primitives'
import { Icon } from '../components/ui/Icon'
import { hasPermission } from '../lib/roles'
import { OPERATIONAL_SUMMARY_COLUMNS, serverClock, summaryCount } from '../lib/presenceLegend'

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

// [F7, DV14] Sem server_now válido (o 1º status.php falhou), sem contagem:
// parseDate(null) é 1970 e daria "Atualizado há 1792178705 segundos".
function updatedLabel(status, serverNow) {
  const updatedAt = typeof status?.server_now === 'string' ? parseDate(status.server_now).getTime() : Number.NaN
  if (!Number.isFinite(updatedAt) || !Number.isFinite(serverNow)) return 'Atualização automática ativa'
  const seconds = Math.max(0, Math.floor((serverNow - updatedAt) / 1000))
  return `Atualizado há ${seconds} segundo${seconds === 1 ? '' : 's'}`
}

// [F7] Linhas da tabela da 5.7: N1, N2 e o total N1 + N2; a Liderança fica
// à parte e fora do total (D10). Rótulo e nota em linhas separadas.
const TEAM_ROWS = [['n1', 'N1'], ['n2', 'N2'], ['total', 'Total', 'N1 + N2']]
const LEADERSHIP_ROW = ['lideranca', 'Liderança', 'fora dos totais']

// [Ajuste A1] Cabeçalho que quebra em duas linhas quando falta largura: o
// ícone fica preso à primeira palavra e o resto vai para a linha de baixo.
// No máximo duas linhas. Assim a tabela cabe de 1280 px para cima sem
// depender da largura da fonte. O espaçamento das células usa !px-*: a regra
// .data-table th/td do index.css é mais específica que a utilidade px-*.
function SummaryHeader({ column }) {
  const [first, ...rest] = column.label.split(' ')
  return (
    <span className={`status-badge flex-wrap justify-center gap-x-1 whitespace-normal rounded-xl px-2 text-center normal-case leading-4 tracking-normal ${column.tone}`}>
      <span className="inline-flex items-center gap-1 whitespace-nowrap"><Icon className="h-3 w-3 shrink-0" name={column.icon} />{first}</span>
      {rest.length > 0 && <span className="whitespace-nowrap">{rest.join(' ')}</span>}
    </span>
  )
}

// [F7, desenho 5.7] Situação da equipe agora, pelo presence_summary do
// dashboard.php (o navegador não conta). Presença indisponível (interruptor
// desligado ou falha no cálculo): "—" em tudo, com aviso, nunca o cálculo
// antigo (DV5). Estado de pausas ilegível: "—" em Em pausa, com aviso (DV6).
function OperationalSummary({ data }) {
  const presence = data?.presence_meta?.disponivel === true ? data?.presence_summary ?? null : null
  const pausesAvailable = data?.presence_meta?.pausas_disponiveis === true
  const clock = serverClock(data?.server_now)
  const cell = (team, key) => {
    if (presence === null || (key === 'em_pausa' && !pausesAvailable)) return '—'
    return summaryCount(presence[team], key) ?? '—'
  }
  const row = ([team, label, note = null], className = '') => (
    <tr className={className} key={team}>
      <th className="whitespace-nowrap !px-3 text-left" scope="row">{label}{note && <small className="font-normal normal-case tracking-normal">{note}</small>}</th>
      {OPERATIONAL_SUMMARY_COLUMNS.map((column) => <td className="!px-2 text-center" key={column.key}><strong>{cell(team, column.key)}</strong></td>)}
    </tr>
  )
  // [17d] Abaixo de 1280 px, um cartão por equipe no lugar da tabela, que só
  // cabe sem rolagem de 1280 px para cima (medição do A1). Mesmo cell() da
  // tabela; a Liderança num bloco à parte, fora dos totais.
  const card = ([team, label, note = null]) => (
    <article className="rounded-card border border-white/10 bg-slate-950/45 p-4" data-team={team} key={team}>
      <h3 className="text-sm font-bold text-white">{label}{note && <small className="ml-2 text-xs font-normal text-slate-400">{note}</small>}</h3>
      <dl className="mt-3 space-y-2">
        {OPERATIONAL_SUMMARY_COLUMNS.map((column) => (
          <div className="flex items-center justify-between gap-3" key={column.key}>
            <dt><span className={`status-badge gap-1 normal-case tracking-normal ${column.tone}`}><Icon className="h-3 w-3 shrink-0" name={column.icon} />{column.label}</span></dt>
            <dd><strong className="text-lg text-white">{cell(team, column.key)}</strong></dd>
          </div>
        ))}
      </dl>
    </article>
  )
  return (
    <section className="card">
      <SectionHeader
        description="Contagem do servidor pela escala, ausências lançadas, pausas, almoço e expediente."
        eyebrow="Visão Operacional"
        meta={clock && <span className="status-badge status-neutral">Situação às {clock}</span>}
        title="Situação da equipe agora"
      />
      {presence === null && (
        <InlineAlert className="mt-5" tone="warning" title="Situação da equipe indisponível">
          A presença de agora não foi calculada (desligada ou com falha no servidor). Os números aparecem como "—" até a próxima atualização que os traga.
        </InlineAlert>
      )}
      {presence !== null && !pausesAvailable && (
        <InlineAlert className="mt-5" tone="warning" title="Pausas indisponíveis">
          O estado das pausas não pôde ser lido: Em pausa aparece como "—", e quem está em pausa conta pela escala até a leitura voltar.
        </InlineAlert>
      )}
      <div className="mt-5 xl:hidden">
        <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">{TEAM_ROWS.map((team) => card(team))}</div>
        <div className="mt-3 grid gap-3 border-t-2 border-white/10 pt-3 sm:grid-cols-2 md:grid-cols-3">{card(LEADERSHIP_ROW)}</div>
      </div>
      <div className="table-wrap mt-5 hidden xl:block">
        <table className="data-table">
          <thead>
            <tr>
              <th className="!px-3" scope="col">Equipe</th>
              {OPERATIONAL_SUMMARY_COLUMNS.map((column) => (
                <th className="!px-2 text-center" key={column.key} scope="col"><SummaryHeader column={column} /></th>
              ))}
            </tr>
          </thead>
          <tbody>{TEAM_ROWS.map((team) => row(team))}</tbody>
          <tbody>{row(LEADERSHIP_ROW, 'border-t-2 border-white/10')}</tbody>
        </table>
      </div>
      {/* [Ajuste A3] Tabela toda em zero por falta de escala (fim de semana, feriado ou sem regra), não por falha. */}
      {presence !== null && summaryCount(presence.total, 'escalados') === 0 && (
        <p className="mt-3 text-sm leading-6 text-slate-300">Ninguém escalado neste momento (fim de semana, feriado ou sem regra de escala).</p>
      )}
      <p className="mt-3 text-xs leading-5 text-slate-500">Ausentes: somente ausências lançadas; pausas não entram. Sem escala fica fora de Escalados.</p>
    </section>
  )
}

export function DashboardPage({ navigate, session, livePauses, loading, onForceEndBreak }) {
  const resource = useResource('portal/dashboard.php', { intervalMs: 15000 })
  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  const activePauses = livePauses.employees.filter((item) => item.em_pausa)
  const pendingPauses = livePauses.employees.filter((item) => item.status_aprovacao === 'pendente').length
  // [F7] Disponíveis pelo resumo do servidor; sem presença, "—" (DV5).
  const presence = resource.data?.presence_meta?.disponivel === true ? resource.data?.presence_summary ?? null : null
  const availableCis = presence === null ? null : summaryCount(presence.total, 'disponiveis')
  const meetingPauses = activePauses.filter((item) => String(item.motivo_pausa || '').toLowerCase().startsWith('reuni')).length
  const coffeePauses = activePauses.filter((item) => String(item.motivo_pausa || '').toLowerCase() === 'café').length
  const summary = {
    ...(resource.data?.summary || {}),
    cis_disponiveis: availableCis ?? '—',
    pausas_ativas: activePauses.length,
    solicitacoes_pendentes: Math.max(resource.data?.summary?.solicitacoes_pendentes || 0, pendingPauses),
  }
  const canForceEndBreak = hasPermission(session, 'pausas.force_end')
  const n1Available = presence === null ? '—' : summaryCount(presence.n1, 'disponiveis') ?? '—'
  const n2Available = presence === null ? '—' : summaryCount(presence.n2, 'disponiveis') ?? '—'

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
          <MetricCard detail={key === 'cis_disponiveis' && availableCis === null ? 'Indisponível: presença não calculada' : detail} icon={icon} key={key} label={label} tone={tone} value={summary[key] ?? 0} />
        ))}
      </section>

      <OperationalSummary data={resource.data} />

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
                  {/* [F7, DV11] Sem a chave (colega, para quem não é gestão), sem a caixa. */}
                  {String(item.motivo_pausa || '').toLowerCase().startsWith('reuni') && 'observacao_reuniao' in item && (
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
