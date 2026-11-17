import { useMemo, useState } from 'react'
import { parseDate } from '../lib/format'
import { InlineAlert, StatusDot } from '../components/ui/Primitives'
import { Segmented } from '../components/ui/Segmented'
import { Tag } from '../components/ui/Tag'
import { EmptyState } from '../components/ui/States'
import { hasPermission } from '../lib/roles'
import { nowLegendItem } from '../lib/presenceLegend'
import { clockDuration, isLunch, isMeeting, lunchIntervalSeconds, LUNCH_NO_LIMIT_NOTE, LUNCH_REASON, pauseLimitSeconds, pauseTiming, pauseTimingNote } from '../lib/pauseTimer'
import { isCriticalTeam, meetingNeedsApproval, noneAvailableLabels, WORK_TEAMS } from '../lib/teams'

// [R4a] Os três motivos ficam visíveis (D-R0-5): escolher e confirmar com um
// botão que diz o que vai acontecer. Dois toques, no lugar de três.
const REASONS = [
  { value: 'Café', action: 'Iniciar pausa de café' },
  { value: 'Pessoal', action: 'Iniciar pausa pessoal' },
  { value: 'Reunião', action: 'Pedir aprovação da reunião' },
]
// [F9, 11.3] "Almoço" só na sessão do Crítico (a recusa fica no servidor).
const LUNCH = { value: LUNCH_REASON, action: 'Iniciar almoço' }
// Regra das pausas, com as mesmas palavras de antes (D-R0-8).
const PAUSE_RULE = 'Café usa limite próprio; reunião segue o fluxo de aprovação configurado.'

// [F7, DV12] Situação de cada técnico pela presenca do status.php (motor:
// escala, ausência lançada, expediente, pausa e almoço), a mesma da Visão
// Operacional. O cálculo antigo (disponibilidade) só quando ela não vem:
// interruptor desligado, falha no cálculo ou cadastro fora do Mapa.
function operatorState(item) {
  if (item.status_aprovacao === 'pendente') return ['pending', 'Aguardando aprovação', 'status-warning', null]
  const now = nowLegendItem(item.presenca?.situacao)
  if (now !== null) {
    if (now.situacao === 'presencial' || now.situacao === 'remoto') return ['available', now.label, now.tone, now.icon]
    if (now.situacao === 'pausa') return ['paused', now.label, now.tone, now.icon]
    return ['offline', now.label, now.tone, now.icon]
  }
  if (item.em_pausa) return ['paused', 'Em pausa', 'status-warning', null]
  if (item.disponibilidade?.status === 'disponivel') return ['available', item.disponibilidade?.label || 'Disponível', 'status-success', null]
  return ['offline', item.disponibilidade?.label || 'Indisponível', 'status-neutral', null]
}

// Filtro da lista: Disponíveis, Em pausa e o resto (aprovação pendente,
// almoço, ausente, fora do expediente, sem escala).
const FILTERS = [
  { value: 'todos', label: 'Todos' },
  { value: 'available', label: 'Disponíveis' },
  { value: 'paused', label: 'Em pausa' },
  { value: 'outros', label: 'Outros' },
]
const filterOf = (state) => (state === 'available' || state === 'paused' ? state : 'outros')

function hourOf(value) {
  const date = parseDate(value)
  return Number.isNaN(date.getTime()) ? null : new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(date)
}

// Faixa do tempo usado: 20 partes (sem atributo style, pela CSP), em âmbar
// até o limite e em vermelho depois dele.
function PauseProgress({ ratio }) {
  const completed = Math.min(20, Math.ceil(ratio * 20))
  const tone = ratio > 1 ? 'bg-danger' : 'bg-pause'
  return (
    <div className="grid grid-cols-20 gap-1" aria-label={`Progresso da pausa: ${Math.round(ratio * 100)}%`} role="img">
      {Array.from({ length: 20 }, (_, index) => <span className={`h-1.5 rounded-full ${index < completed ? tone : 'bg-surface-2'}`} key={index} />)}
    </div>
  )
}

function timingTone(timing) {
  if (timing.remaining === null) return ['info', 'Sem limite']
  if (timing.overdue) return ['danger', 'Tempo excedido']
  return timing.ratio < 0.6 ? ['info', 'Dentro do tempo'] : ['warning', 'Próximo do limite']
}

function ActivePause({ current, elapsed, loading, onFinish }) {
  const timing = pauseTiming(current, elapsed)
  const [tone, status] = timingTone(timing)
  const since = hourOf(current.inicio_pausa)
  return (
    <div className="space-y-4" data-own-pause="active">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-ink-2">
          <strong className="font-semibold text-ink">{current.motivo_pausa}</strong>
          {since && <> desde <span className="font-mono tabular-nums">{since}</span></>}
        </p>
        <Tag tone={tone}>{status}</Tag>
      </div>
      <p className={`font-mono text-5xl font-medium tabular-nums ${timing.overdue ? 'text-danger' : 'text-ink'}`} data-pause-clock="">{clockDuration(timing.elapsed)}</p>
      {timing.limit > 0 && <PauseProgress ratio={timing.ratio} />}
      <p className={`text-sm ${timing.overdue ? 'font-medium text-danger' : 'text-muted'}`}>
        {timing.limit > 0 ? `${pauseTimingNote(timing)} · limite de ${Math.round(timing.limit / 60)} min` : 'Timer ativo sem limite automático'}
      </p>
      {isLunch(current.motivo_pausa) && timing.limit === 0 && <InlineAlert tone="warning">{LUNCH_NO_LIMIT_NOTE}</InlineAlert>}
      {isMeeting(current.motivo_pausa) && (
        <p className="line-clamp-3 rounded-md border border-line bg-surface-2 px-3 py-2 text-sm leading-6 text-ink-2" title={current.observacao_reuniao || 'Sem observação informada'}>
          {current.observacao_reuniao || 'Sem observação informada'}
        </p>
      )}
      <button className="btn-primary w-full" disabled={loading} onClick={onFinish} type="button">
        {loading ? 'Finalizando...' : 'Finalizar pausa'}
      </button>
    </div>
  )
}

function StartPause({ current, loading, onStart, onRequest }) {
  const [reason, setReason] = useState('')
  const [observation, setObservation] = useState('')
  // [AD-F9-2] Reunião sem aprovação no Crítico e no Híbrido (o servidor confere a regra de pelo menos 1 disponível).
  const meetingFree = current ? !meetingNeedsApproval(current.equipe) && current.equipe !== 'n2' : false
  const baseReasons = meetingFree ? REASONS.map((item) => (item.value === 'Reunião' ? { ...item, action: 'Iniciar reunião' } : item)) : REASONS
  const reasons = isCriticalTeam(current?.equipe) ? [...baseReasons, LUNCH] : baseReasons
  const chosen = reasons.find((item) => item.value === reason) ?? null
  const meeting = reason === 'Reunião'
  // Limite do café pela configuração que o servidor manda (sem pausa, o
  // status.php traz o limite de quem conta no tempo).
  const coffeeLimit = current && !current.em_pausa ? pauseLimitSeconds({ motivo_pausa: 'Café', duracao_limite_segundos: current.duracao_limite_segundos }) : 0
  // [F9, D-F9-6] Almoço do Crítico: até o F8, o intervalo do próprio cadastro.
  const lunchLimit = lunchIntervalSeconds(current?.almoco_inicio, current?.almoco_fim)
  const hints = {
    'Café': coffeeLimit > 0 ? `até ${Math.round(coffeeLimit / 60)} min` : 'com limite de tempo',
    'Pessoal': 'sem limite de tempo',
    'Reunião': meetingFree ? 'sem aprovação' : 'com aprovação',
    [LUNCH_REASON]: lunchLimit !== null ? `até ${Math.round(lunchLimit / 60)} min` : 'sem limite automático',
  }

  async function submit(event) {
    event.preventDefault()
    if (!chosen) return
    const result = meeting ? await onRequest(reason, observation) : await onStart(reason)
    if (result) {
      setReason('')
      setObservation('')
    }
  }

  return (
    <form className="space-y-4" data-own-pause="start" onSubmit={submit}>
      <fieldset className="space-y-2">
        <legend className="mb-2 text-sm font-medium text-ink-2">Motivo</legend>
        {reasons.map((item) => (
          <label className="pause-choice" key={item.value}>
            <input checked={reason === item.value} name="motivo_pausa" onChange={() => setReason(item.value)} required type="radio" value={item.value} />
            <span className="font-medium text-ink">{item.value}</span>
            <small className="text-xs text-muted">{hints[item.value]}</small>
          </label>
        ))}
      </fieldset>
      {reason === LUNCH_REASON && lunchLimit === null && <InlineAlert tone="warning">{LUNCH_NO_LIMIT_NOTE}</InlineAlert>}
      {meeting && (
        <label className="label">
          Contexto da reunião
          <textarea className="field mt-2 min-h-24" maxLength={500} onChange={(event) => setObservation(event.target.value)} required value={observation} />
          <span className="mt-1 block text-right text-xs text-muted">{observation.length}/500</span>
        </label>
      )}
      <button className="btn-primary w-full" disabled={loading || !chosen} type="submit">
        {loading ? 'Processando...' : chosen ? chosen.action : 'Escolha o motivo'}
      </button>
      <p className="text-xs leading-5 text-muted">{PAUSE_RULE}</p>
      {current && (
        <p className="text-xs text-muted" data-own-schedule="">
          Jornada <span className="font-mono tabular-nums">{current.jornada_entrada}</span> às <span className="font-mono tabular-nums">{current.jornada_saida}</span>
          {current.almoco_inicio && current.almoco_fim && <> · almoço <span className="font-mono tabular-nums">{current.almoco_inicio}</span> às <span className="font-mono tabular-nums">{current.almoco_fim}</span></>}
        </p>
      )}
    </form>
  )
}

function MyPause({ current, elapsed, loading, onStart, onRequest, onFinish }) {
  const state = current ? operatorState(current) : null
  return (
    <section aria-labelledby="sua-pausa" className="card space-y-4">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-ink" id="sua-pausa">Sua pausa</h2>
        {state && <Tag icon={state[3]} title={current.presenca?.detalhe || undefined} tone={state[2].replace(/^status-/u, '')}>{state[1]}</Tag>}
      </div>
      {current?.status_aprovacao === 'pendente' && (
        <InlineAlert tone="warning">Sua solicitação de reunião está aguardando aprovação.</InlineAlert>
      )}
      {current?.em_pausa
        ? <ActivePause current={current} elapsed={elapsed} loading={loading} onFinish={onFinish} />
        : <StartPause current={current} loading={loading} onRequest={onRequest} onStart={onStart} />}
    </section>
  )
}

function TeamRow({ item, elapsedFor, canForceEndBreak, loading, onForceEndBreak }) {
  const [state, label, badgeClass, icon] = operatorState(item)
  const timing = item.em_pausa ? pauseTiming(item, elapsedFor(item)) : null
  const meetingNote = isMeeting(item.motivo_pausa) && item.observacao_reuniao ? item.observacao_reuniao : null
  return (
    <tr data-overdue={timing?.overdue ? '' : undefined} data-row="" data-state={state}>
      <td className="min-w-[11rem]" data-col="name">
        <span className="block font-medium text-ink">{item.nome}</span>
        {/* Jornada embaixo do nome: com o painel "Sua pausa" ao lado, a lista cabe em 1280 px. */}
        <small className="block font-mono text-xs tabular-nums text-muted" data-schedule="">{item.jornada_entrada}–{item.jornada_saida}</small>
        {meetingNote && <small className="mt-0.5 line-clamp-2 block text-xs leading-5 text-muted" title={meetingNote}>{meetingNote}</small>}
      </td>
      <td className="w-px whitespace-nowrap" data-col="state">
        <Tag icon={icon} title={item.presenca?.detalhe || undefined} tone={badgeClass.replace(/^status-/u, '')}>{label}</Tag>
      </td>
      <td className="whitespace-nowrap text-right" data-col="meta" data-hide-sm={timing ? undefined : ''}>
        {timing ? (
          <span className="pause-time">
            <span><span className="text-muted">{item.motivo_pausa}</span> <span className={`font-mono font-medium tabular-nums ${timing.overdue ? 'text-danger' : 'text-ink'}`} data-pause-clock="">{clockDuration(timing.elapsed)}</span></span>
            <span className={`text-xs ${timing.overdue ? 'font-medium text-danger' : 'text-muted'}`}>{pauseTimingNote(timing)}</span>
          </span>
        ) : <span className="text-muted">—</span>}
      </td>
      {canForceEndBreak && (
        <td className="w-px whitespace-nowrap text-right" data-col="act" data-hide-sm={item.em_pausa ? undefined : ''}>
          {item.em_pausa && (
            <button className="btn-danger btn-sm" disabled={loading} onClick={() => onForceEndBreak(item)} title="Derrubar pausa do CI" type="button">
              {loading ? 'Finalizando...' : 'Derrubar pausa'}
            </button>
          )}
        </td>
      )}
    </tr>
  )
}

function TeamPanel({ teams, noneAvailable = [], livePauses, canForceEndBreak, loading, onForceEndBreak }) {
  const [filter, setFilter] = useState('todos')
  const all = teams.flatMap(([, items]) => items)
  const counts = { todos: all.length, available: 0, paused: 0, outros: 0 }
  for (const item of all) counts[filterOf(operatorState(item)[0])] += 1
  const visible = (item) => filter === 'todos' || filterOf(operatorState(item)[0]) === filter
  const shown = teams.map(([title, items]) => [title, items, items.filter(visible)])
  const columns = canForceEndBreak ? 4 : 3
  const nothing = shown.every(([, , rows]) => rows.length === 0)
  return (
    <section aria-labelledby="equipe-agora" className="card overflow-hidden p-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
          <h2 className="text-lg font-semibold text-ink" id="equipe-agora">Equipe agora</h2>
          <StatusDot tone={livePauses.connection === 'online' ? 'success' : 'warning'} label={livePauses.connection === 'online' ? 'Atualização em tempo real ativa' : 'Conexão instável; exibindo o último estado recebido'} />
        </div>
        <Segmented label="Filtrar a equipe" onChange={setFilter} options={FILTERS.map((option) => ({ ...option, count: counts[option.value] }))} value={filter} />
      </div>
      {nothing ? (
        <div className="p-5"><EmptyState title="Ninguém nesta situação agora" description="Escolha outro filtro para ver o resto da equipe." /></div>
      ) : (
        <div className="table-wrap">
          <table className="team-table">
            <caption className="sr-only">Equipe agora</caption>
            <thead>
              <tr>
                <th scope="col">Técnico</th>
                <th scope="col">Situação</th>
                <th className="text-right" scope="col">Pausa</th>
                {canForceEndBreak && <th className="text-right" scope="col"><span className="sr-only">Ações</span></th>}
              </tr>
            </thead>
            {shown.map(([title, items, rows]) => rows.length > 0 && (
              <tbody data-team={title} key={title}>
                <tr className="team-group">
                  <th colSpan={columns} scope="colgroup">
                    Equipe {title} · {items.filter((item) => operatorState(item)[0] === 'available').length} de {items.length} disponíveis
                    {/* [F9, D-F9-18] Destaque do Crítico por selo e rótulo, nunca só pela cor. */}
                    {isCriticalTeam(title) && <> <Tag tone="warning">Equipe crítica</Tag></>}
                    {/* [AD-F9-1] Destaque por selo e texto, não só pela cor. */}
                    {noneAvailable.includes(`${title} sem ninguém disponível`) && <> <Tag tone="danger">{`${title} sem ninguém disponível`}</Tag></>}
                  </th>
                </tr>
                {rows.map((item) => <TeamRow canForceEndBreak={canForceEndBreak} elapsedFor={livePauses.elapsedFor} item={item} key={item.id} loading={loading} onForceEndBreak={onForceEndBreak} />)}
              </tbody>
            ))}
          </table>
        </div>
      )}
    </section>
  )
}

export function PausasPage({ session, status, livePauses, loading, onStart, onRequest, onFinish, onForceEndBreak }) {
  // [F9] As quatro equipes de técnico (N1, N2, Crítico e Híbrido).
  const employees = useMemo(() => WORK_TEAMS.flatMap((team) => status[team.value] || []), [status])
  const current = employees.find((item) => Number(item.id) === Number(session.ci.funcionario_id))
  const canForceEndBreak = hasPermission(session, 'pausas.force_end')
  const teams = WORK_TEAMS.map((team) => [team.label, status[team.value] || []])
  const noneAvailable = noneAvailableLabels(status.disponibilidade_equipes)

  return (
    <div className="space-y-6">
      {noneAvailable.map((label) => (
        <InlineAlert key={label} title={label} tone="danger"><span data-none-available="">Quem está no turno está em pausa ou almoço.</span></InlineAlert>
      ))}
      {!session.ci.autenticado && (
        <InlineAlert tone="info">A sessão administrativa permite monitoramento. Entre como CI para iniciar ou finalizar sua própria pausa.</InlineAlert>
      )}
      <div className={session.ci.autenticado ? 'grid items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]' : ''}>
        {session.ci.autenticado && (
          <MyPause current={current} elapsed={current ? livePauses.elapsedFor(current) : 0} loading={loading} onFinish={onFinish} onRequest={onRequest} onStart={onStart} />
        )}
        <TeamPanel canForceEndBreak={canForceEndBreak} livePauses={livePauses} loading={loading} noneAvailable={noneAvailable} onForceEndBreak={onForceEndBreak} teams={teams} />
      </div>
    </div>
  )
}

