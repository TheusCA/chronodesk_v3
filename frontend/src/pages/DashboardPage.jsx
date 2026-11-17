import { useEffect } from 'react'
import { useResource } from '../hooks/useResource'
import { parseDate } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { InlineAlert, SectionHeader, StatusDot } from '../components/ui/Primitives'
import { Icon } from '../components/ui/Icon'
import { hasPermission } from '../lib/roles'
import { OPERATIONAL_SUMMARY_COLUMNS, serverClock, summaryCount } from '../lib/presenceLegend'
import { clockDuration, isLunch, isMeeting, pauseTiming, pauseTimingNote } from '../lib/pauseTimer'
import { noneAvailableLabels, teamShort, WORK_TEAMS } from '../lib/teams'

// [R4b] Os totais do dashboard.php, numa lista compacta no pé da tela (saem os
// 8 cartões iguais). Os rótulos são os de antes; o número vem do servidor.
const TOTALS = [
  ['tecnicos_monitorados', 'Técnicos monitorados'],
  ['cis_disponiveis', 'CIs disponíveis'],
  ['pausas_ativas', 'Pausas ativas'],
  ['solicitacoes_pendentes', 'Aprovações pendentes'],
  ['chamados_criticos_abertos', 'Chamados críticos'],
  ['war_rooms_ativas', 'War rooms'],
  ['plantonistas_ativos', 'Plantonistas ativos'],
  ['sobreavisos_ativos', 'Sobreavisos ativos'],
]

// Partes da composição da equipe, na ordem da legenda: cada uma com a cor e o
// rótulo da situação. Sem escala fica fora (não está escalado).
const PARTS = [
  ['disponiveis', 'Disponíveis', 'bg-ok'],
  ['em_pausa', 'Em pausa', 'bg-pause'],
  ['almoco', 'Almoço', 'bg-lunch'],
  ['ausentes', 'Ausentes', 'bg-absent'],
  ['fora_expediente', 'Fora do expediente', 'bg-line-strong'],
]
// Classes da composição, só com utilitários (o CSS do portal tem orçamento).
const BAR = 'flex h-3 gap-0.5 overflow-hidden rounded-full bg-surface-2'
const LEGEND = 'flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-2'
const LEGEND_NUMBER = 'ml-1 font-mono font-medium tabular-nums text-ink'

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
// [F9, D-F9-10] Crítico e Híbrido em linhas próprias, fora do total (o grupo
// comum é N1 + N2), junto da Liderança.
const SEPARATE_ROWS = [['critico', 'Crítico', 'fora dos totais'], ['hibrido', 'Híbrido', 'fora dos totais'], ['lideranca', 'Liderança', 'fora dos totais']]

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

// Presença do servidor: só com presence_meta.disponivel (DV5); pausas
// ilegíveis deixam "Em pausa" com "—" (DV6).
function presenceOf(data) {
  const presence = data?.presence_meta?.disponivel === true ? data?.presence_summary ?? null : null
  const pausesAvailable = data?.presence_meta?.pausas_disponiveis === true
  const count = (team, key) => {
    if (presence === null || (key === 'em_pausa' && !pausesAvailable)) return null
    return summaryCount(presence[team], key)
  }
  return { presence, pausesAvailable, count }
}

const shown = (value) => (value === null ? '—' : value)

// [R4b] Composição: uma célula por pessoa escalada, na cor e na ordem da
// legenda (sem largura em style, pela CSP). O leitor de tela ouve a frase.
function CompositionBar({ count, team, label }) {
  const parts = PARTS.map(([key, name, cell]) => [key, name, cell, count(team, key)]).filter(([, , , value]) => value !== null && value > 0)
  if (parts.length === 0) return <div aria-hidden="true" className={BAR} />
  const sentence = parts.map(([, name, , value]) => `${value} ${name.toLowerCase()}`).join(', ')
  return (
    <div aria-label={`${label}: ${sentence}`} className={BAR} data-composition={team} role="img">
      {parts.flatMap(([key, , cell, value]) => Array.from({ length: value }, (_, index) => <span className={`h-full min-w-0 flex-1 ${cell}`} data-cell={key} key={`${key}-${index}`} />))}
    </div>
  )
}

function NowTotal({ data, panel }) {
  const { count } = presenceOf(data)
  const clock = serverClock(data?.server_now)
  const available = count('total', 'disponiveis')
  const scheduled = count('total', 'escalados')
  return (
    <section aria-labelledby="disponiveis-agora" className="card md:col-span-2 xl:col-span-1" data-now-total="">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold text-ink" id="disponiveis-agora">Disponíveis agora</h2>
        {clock && <span className="text-sm text-muted">Situação às <time className="font-mono tabular-nums">{clock}</time></span>}
      </div>
      <p className="mt-3 flex flex-wrap items-baseline gap-x-3">
        <strong className={`font-mono font-medium leading-none tabular-nums text-ink ${panel ? 'text-[7rem]' : 'text-5xl'}`} data-now-available="">{shown(available)}</strong>
        <span className="text-lg text-muted">{scheduled === null ? 'escalados: —' : `de ${scheduled} escalados`}</span>
      </p>
      <div className="mt-4"><CompositionBar count={count} label="Equipe N1 + N2" team="total" /></div>
      <ul className={`${LEGEND} mt-4`}>
        {PARTS.map(([key, name, cell]) => (
          <li key={key}><span aria-hidden="true" className={`mr-1.5 inline-block h-2.5 w-2.5 rounded-sm ${cell}`} />{name} <strong className={LEGEND_NUMBER}>{shown(count('total', key))}</strong></li>
        ))}
        <li className="text-muted">Sem escala, fora da soma: <strong className={LEGEND_NUMBER}>{shown(count('total', 'sem_escala'))}</strong></li>
      </ul>
    </section>
  )
}

function NowTeam({ data, team, title, panel }) {
  const { count } = presenceOf(data)
  const available = count(team, 'disponiveis')
  const scheduled = count(team, 'escalados')
  return (
    <section aria-labelledby={`agora-${team}`} className="card" data-team-now={team}>
      <h2 className="text-lg font-semibold text-ink" id={`agora-${team}`}>{title}</h2>
      <p className="mt-2 flex flex-wrap items-baseline gap-x-2">
        <strong className={`font-mono font-medium leading-none tabular-nums text-ink ${panel ? 'text-[4rem]' : 'text-3xl'}`} data-available={shown(available)}>{shown(available)}</strong>
        <span className="text-muted">{scheduled === null ? 'disponíveis' : `de ${scheduled} disponíveis`}</span>
      </p>
      <div className="mt-3"><CompositionBar count={count} label={`Equipe ${title}`} team={team} /></div>
      <ul className={`${LEGEND} mt-3`}>
        {PARTS.slice(1).map(([key, name]) => <li key={key}>{name} <strong className={LEGEND_NUMBER}>{shown(count(team, key))}</strong></li>)}
        <li>Sem escala <strong className={LEGEND_NUMBER}>{shown(count(team, 'sem_escala'))}</strong></li>
      </ul>
    </section>
  )
}

// [R4b] Pausas em andamento: lista densa, o estouro marcado na linha e
// escrito ("passou"). Derrubar pausa só para quem pode, e nunca no painel.
function ActivePauses({ pauses, livePauses, canForceEndBreak, loading, onForceEndBreak, navigate, panel }) {
  const coffee = pauses.filter((item) => String(item.motivo_pausa || '').toLowerCase() === 'café').length
  const meetings = pauses.filter((item) => isMeeting(item.motivo_pausa)).length
  // [F9] Almoço do Crítico: só aparece no resumo quando há (o texto de antes não muda).
  const lunches = pauses.filter((item) => isLunch(item.motivo_pausa)).length
  const withActions = canForceEndBreak && !panel
  return (
    <section aria-labelledby="pausas-andamento" className="card overflow-hidden p-0" data-active-pauses="">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
        <div>
          <h2 className="text-lg font-semibold text-ink" id="pausas-andamento">Pausas em andamento</h2>
          <p className="text-sm text-muted" data-pause-types="">{pauses.length} em andamento · Café {coffee} · Reunião {meetings}{lunches > 0 && <> · Almoço {lunches}</>}</p>
        </div>
        {!panel && <button className="btn-ghost" onClick={() => navigate('/pausas')} type="button"><Icon className="h-4 w-4" name="pause" /> Abrir pausas</button>}
      </div>
      {pauses.length === 0 ? (
        <div className="p-5"><EmptyState title="Nenhuma pausa ativa no momento" description="A equipe está sem pausas em andamento neste momento." /></div>
      ) : (
        <div className="table-wrap">
          <table className="team-table">
            <caption className="sr-only">Pausas em andamento</caption>
            <thead>
              <tr>
                <th scope="col">Técnico</th>
                <th className="text-right" scope="col">Tempo</th>
                {withActions && <th className="text-right" scope="col"><span className="sr-only">Ações</span></th>}
              </tr>
            </thead>
            <tbody>
              {pauses.map((item) => {
                const timing = pauseTiming(item, livePauses.elapsedFor(item))
                const since = parseDate(item.inicio_pausa)
                const sinceText = Number.isNaN(since.getTime()) ? null : new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(since)
                return (
                  <tr data-overdue={timing.overdue ? '' : undefined} data-row="" data-state="paused" key={item.id}>
                    {/* [Ajuste 1 do R4b] O nome numa linha só: a coluna ocupa o que sobra
                        e o nome longo termina em reticências (inteiro no title). */}
                    <td className="w-full max-w-0" data-col="name">
                      <span className="block truncate font-medium text-ink" data-pause-name="" title={item.nome}>{item.nome}</span>
                      <small className="block truncate text-xs text-muted">Equipe {teamShort(item.equipe)}{sinceText && <> · desde <span className="font-mono tabular-nums">{sinceText}</span></>}</small>
                      {/* [F7, DV11] Sem a chave (colega, para quem não é gestão), sem a observação.
                          No modo painel (tela fixa na sala, à vista de todos), nunca: só a gestão a vê. */}
                      {!panel && isMeeting(item.motivo_pausa) && 'observacao_reuniao' in item && (
                        <small className="mt-1 line-clamp-2 block text-xs leading-5 text-ink-2" title={item.observacao_reuniao || 'Sem observação informada'}>
                          {item.observacao_reuniao || 'Sem observação informada'}
                        </small>
                      )}
                    </td>
                    <td className="w-px whitespace-nowrap text-right" data-col="meta">
                      {/* [Ajuste 1 do R4b] O motivo vai junto do tempo (como em Pausas): a coluna do nome ganha a largura. */}
                      <span className="pause-time">
                        <span><span className="text-muted" data-pause-reason="">{item.motivo_pausa}</span> <span className={`font-mono font-medium tabular-nums ${panel ? 'text-2xl' : ''} ${timing.overdue ? 'text-danger' : 'text-ink'}`} data-pause-clock="">{clockDuration(timing.elapsed)}</span></span>
                        <span className={`text-xs ${timing.overdue ? 'font-medium text-danger' : 'text-muted'}`}>{pauseTimingNote(timing)}</span>
                      </span>
                    </td>
                    {withActions && (
                      <td className="w-px whitespace-nowrap text-right" data-col="act">
                        <button className="btn-danger btn-sm" disabled={loading} onClick={() => onForceEndBreak(item)} title="Derrubar pausa do CI" type="button">
                          {loading ? 'Finalizando...' : 'Derrubar pausa'}
                        </button>
                      </td>
                    )}
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

// [R4b] "Precisa de você": o que pede ação, com o caminho até ela. No painel,
// só a lista (sem ações). Alvo de 56 px.
const NEED = 'flex min-h-14 w-full items-center gap-3 px-5 py-3 text-left'
function NeedsYou({ items, navigate, panel }) {
  return (
    <section aria-labelledby="precisa-de-voce" className="card overflow-hidden p-0" data-needs-you="">
      <h2 className="border-b border-line px-5 py-4 text-lg font-semibold text-ink" id="precisa-de-voce">Precisa de você</h2>
      {items.length === 0 ? (
        <p className="px-5 py-6 text-sm text-muted">Nada precisa de você agora.</p>
      ) : (
        <ul>
          {items.map((item) => {
            const content = (
              <>
                <Icon className="h-5 w-5 shrink-0 text-muted" name={item.icon} />
                <span className="min-w-0 flex-1">
                  <strong className="block font-medium text-ink">{item.title}</strong>
                  <span className="block text-sm text-muted">{item.detail}</span>
                </span>
                {!panel && <Icon className="h-4 w-4 shrink-0 text-muted" name="chevron" />}
              </>
            )
            return (
              <li className="border-b border-line last:border-b-0" data-need={item.kind} key={item.key}>
                {panel
                  ? <div className={NEED}>{content}</div>
                  : <button className={`${NEED} transition-colors hover:bg-surface-2`} onClick={() => navigate(item.path)} type="button">{content}</button>}
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}

// [F7, desenho 5.7] Situação da equipe agora, pelo presence_summary do
// dashboard.php (o navegador não conta). Presença indisponível (interruptor
// desligado ou falha no cálculo): "—" em tudo, nunca o cálculo antigo (DV5).
// Estado de pausas ilegível: "—" em Em pausa (DV6). [R4b] Os avisos foram
// para o topo da tela; esta tabela fica como o detalhe por equipe.
function OperationalSummary({ data }) {
  const { presence, count } = presenceOf(data)
  const clock = serverClock(data?.server_now)
  const cell = (team, key) => shown(count(team, key))
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
    <article className="rounded-card border border-line bg-surface-2 p-4" data-team={team} key={team}>
      <h3 className="text-sm font-semibold text-ink">{label}{note && <small className="ml-2 text-xs font-normal text-muted">{note}</small>}</h3>
      <dl className="mt-3 space-y-2">
        {OPERATIONAL_SUMMARY_COLUMNS.map((column) => (
          <div className="flex items-center justify-between gap-3" key={column.key}>
            <dt><span className={`status-badge gap-1 normal-case tracking-normal ${column.tone}`}><Icon className="h-3 w-3 shrink-0" name={column.icon} />{column.label}</span></dt>
            <dd><strong className="text-lg text-ink">{cell(team, column.key)}</strong></dd>
          </div>
        ))}
      </dl>
    </article>
  )
  return (
    <section className="card" data-summary-table="">
      <SectionHeader
        description="Contagem do servidor pela escala, ausências lançadas, pausas, almoço e expediente."
        meta={clock && <span className="status-badge status-neutral">Situação às {clock}</span>}
        title="Situação da equipe agora"
      />
      <div className="mt-5 xl:hidden">
        <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">{TEAM_ROWS.map((team) => card(team))}</div>
        <div className="mt-3 grid gap-3 border-t-2 border-line pt-3 sm:grid-cols-2 md:grid-cols-3">{SEPARATE_ROWS.map((team) => card(team))}</div>
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
          <tbody>{SEPARATE_ROWS.map((team, index) => row(team, index === 0 ? 'border-t-2 border-line' : ''))}</tbody>
        </table>
      </div>
      {/* [Ajuste A3] Tabela toda em zero por falta de escala (fim de semana, feriado ou sem regra), não por falha. */}
      {presence !== null && summaryCount(presence.total, 'escalados') === 0 && (
        <p className="mt-3 text-sm leading-6 text-ink-2">Ninguém escalado neste momento (fim de semana, feriado ou sem regra de escala).</p>
      )}
      <p className="mt-3 text-xs leading-5 text-muted">Ausentes: somente ausências lançadas; pausas não entram. Sem escala fica fora de Escalados.</p>
    </section>
  )
}

// [R4b] Modo painel: a fonte cresce com a tela fixa (classe na raiz enquanto
// ele estiver aberto).
function usePanelRoot(panel) {
  useEffect(() => {
    if (!panel) return undefined
    document.documentElement.dataset.panelMode = ''
    return () => { delete document.documentElement.dataset.panelMode }
  }, [panel])
}

export function DashboardPage({ navigate, session, livePauses, loading, onForceEndBreak, panel = false, panelHeader = null }) {
  const resource = useResource('portal/dashboard.php', { intervalMs: 15000 })
  usePanelRoot(panel)
  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  const data = resource.data
  const { presence, pausesAvailable, count } = presenceOf(data)
  // [F9] As quatro equipes de técnico do status.php (o useLivePauses junta só N1 e N2).
  const liveEmployees = WORK_TEAMS.flatMap((team) => livePauses.status?.[team.value] || [])
  const activePauses = liveEmployees.filter((item) => item.em_pausa)
  const pendingPauses = liveEmployees.filter((item) => item.status_aprovacao === 'pendente').length
  // [F7] Disponíveis pelo resumo do servidor; sem presença, "—" (DV5).
  const availableCis = count('total', 'disponiveis')
  const summary = {
    ...(data?.summary || {}),
    cis_disponiveis: shown(availableCis),
    pausas_ativas: activePauses.length,
    solicitacoes_pendentes: Math.max(data?.summary?.solicitacoes_pendentes || 0, pendingPauses),
  }
  const canForceEndBreak = hasPermission(session, 'pausas.force_end')
  const canApprove = hasPermission(session, 'operacao.approve')

  const needs = []
  const pending = Number(summary.solicitacoes_pendentes) || 0
  if (pending > 0) needs.push({ key: 'aprovacoes', kind: 'approvals', icon: 'shield', path: '/admin', title: pending === 1 ? '1 aprovação pendente' : `${pending} aprovações pendentes`, detail: 'Pausas, ponto e horas extras' })
  for (const item of activePauses) {
    const timing = pauseTiming(item, livePauses.elapsedFor(item))
    if (!timing.overdue) continue
    needs.push({ key: `pausa-${item.id}`, kind: 'overdue', icon: 'timer', path: '/pausas', title: `${item.motivo_pausa} passou do limite`, detail: `${item.nome}, limite de ${Math.round(timing.limit / 60)} min · ${pauseTimingNote(timing)}` })
  }
  const critical = Number(summary.chamados_criticos_abertos) || 0
  if (critical > 0) {
    const rooms = Number(summary.war_rooms_ativas) || 0
    needs.push({ key: 'criticos', kind: 'critical', icon: 'alert', path: '/chamados-criticos', title: critical === 1 ? '1 chamado crítico aberto' : `${critical} chamados críticos abertos`, detail: rooms === 1 ? '1 war room ativa' : `${rooms} war rooms ativas` })
  }
  // Aprovações só levam à tela para quem decide (a rota pede operacao.approve).
  const reachable = needs.filter((item) => item.kind !== 'approvals' || canApprove || panel)

  return (
    <div className={`space-y-6 ${panel ? 'panel-mode' : ''}`} data-dashboard={panel ? 'painel' : 'tela'}>
      {panelHeader}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          <StatusDot tone={livePauses.connection === 'online' ? 'success' : 'warning'} label={livePauses.connection === 'online' ? 'Atualização automática ativa' : 'Conexão instável'} />
          <StatusDot tone="info" label={updatedLabel(livePauses.status, livePauses.serverNow)} />
        </div>
        {!panel && <button className="btn-secondary" data-panel-open="" onClick={() => navigate('/dashboard?painel=1')} type="button"><Icon className="h-4 w-4" name="dashboard" /> Modo painel</button>}
      </div>
      {presence === null && (
        <InlineAlert tone="warning" title="Situação da equipe indisponível">
          A presença de agora não foi calculada (desligada ou com falha no servidor). Os números aparecem como "—" até a próxima atualização que os traga.
        </InlineAlert>
      )}
      {/* [AD-F9-1] Crítico ou Híbrido sem ninguém disponível: selo e texto, não só cor. */}
      {noneAvailableLabels(livePauses.status?.disponibilidade_equipes ?? data?.disponibilidade_equipes).map((label) => (
        <InlineAlert key={label} title={label} tone="danger"><span data-none-available="">Quem está no turno está em pausa ou almoço.</span></InlineAlert>
      ))}
      {/* [9.16, AD-PC-2] Papel do rodízio do Crítico sem ninguém hoje. */}
      {(data?.papeis_vagos_critico || []).length > 0 && (
        <InlineAlert title="Papel vago no Crítico hoje" tone="warning"><span data-vacant-roles="">{data.papeis_vagos_critico.map((role) => role.label).join(', ')}</span></InlineAlert>
      )}
      {presence !== null && !pausesAvailable && (
        <InlineAlert tone="warning" title="Pausas indisponíveis">
          O estado das pausas não pôde ser lido: Em pausa aparece como "—", e quem está em pausa conta pela escala até a leitura voltar.
        </InlineAlert>
      )}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)]">
        <NowTotal data={data} panel={panel} />
        <NowTeam data={data} panel={panel} team="n1" title="N1" />
        <NowTeam data={data} panel={panel} team="n2" title="N2" />
      </div>

      <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <ActivePauses canForceEndBreak={canForceEndBreak} livePauses={livePauses} loading={loading} navigate={navigate} onForceEndBreak={onForceEndBreak} panel={panel} pauses={activePauses} />
        <NeedsYou items={reachable} navigate={navigate} panel={panel} />
      </div>

      {!panel && (
        <>
          <OperationalSummary data={data} />
          <section aria-labelledby="totais-servidor" className="card" data-totals="">
            <h2 className="text-lg font-semibold text-ink" id="totais-servidor">Totais da operação</h2>
            <dl className="mt-3 grid gap-x-6 text-sm sm:grid-cols-2 xl:grid-cols-4">
              {TOTALS.map(([key, label]) => (
                <div className="flex items-baseline justify-between gap-3 border-b border-line py-2" key={key}>
                  <dt className="text-muted">{label}</dt>
                  <dd className="font-mono font-medium tabular-nums text-ink">{summary[key] ?? 0}</dd>
                </div>
              ))}
            </dl>
            {availableCis === null && <p className="mt-2 text-xs text-muted">CIs disponíveis — Indisponível: presença não calculada</p>}
          </section>
        </>
      )}
    </div>
  )
}
