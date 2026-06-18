import { useMemo, useState } from 'react'
import { formatDuration } from '../lib/format'

const reasons = ['Café', 'Pessoal', 'Reunião']

function PauseProgress({ elapsed, limit }) {
  const ratio = limit > 0 ? elapsed / limit : 0
  const completed = Math.min(20, Math.ceil(ratio * 20))
  const tone = ratio < 0.6 ? 'progress-blue' : ratio < 0.85 ? 'progress-yellow' : ratio <= 1 ? 'progress-orange' : 'progress-red'
  return (
    <div className="grid grid-cols-20 gap-1" aria-label={`Progresso da pausa: ${Math.round(ratio * 100)}%`}>
      {Array.from({ length: 20 }, (_, index) => <span className={`h-2 rounded-full ${index < completed ? tone : 'bg-slate-800'}`} key={index} />)}
    </div>
  )
}

function ActivePauseCard({ current, elapsed, loading, onFinish }) {
  const pauseReason = String(current.motivo_pausa || '').toLowerCase()
  const unlimited = pauseReason === 'pessoal' || pauseReason.startsWith('reuni')
  const limit = unlimited ? 0 : Number(current.duracao_limite_segundos || 1200)
  const remaining = limit - elapsed
  const ratio = limit > 0 ? elapsed / limit : 0
  const status = unlimited ? 'Sem limite' : ratio < 0.6 ? 'Dentro do tempo' : ratio < 0.85 ? 'Próximo do limite' : ratio <= 1 ? 'Próximo do limite' : 'Tempo excedido'
  const statusClass = unlimited ? 'status-info' : ratio < 0.6 ? 'status-info' : ratio < 0.85 ? 'status-warning' : 'status-danger'

  return (
    <section className="card border-amber-500/20">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-sm text-slate-500">Pausa ativa</p>
          <h2 className="mt-1 text-xl font-bold text-white">{current.motivo_pausa}</h2>
        </div>
        <span className={`status-badge ${statusClass}`}>{status}</span>
      </div>
      <div className="my-7 text-center">
        <p className="font-mono text-4xl font-black tracking-tight text-white sm:text-5xl">{formatDuration(elapsed)}</p>
        <p className="mt-2 text-sm text-slate-500">
          {unlimited ? 'Timer ativo sem limite automatico' : `Limite ${formatDuration(limit)} · ${remaining >= 0 ? `${formatDuration(remaining)} restantes` : `${formatDuration(Math.abs(remaining))} excedidos`}`}
        </p>
      </div>
      {!unlimited && <PauseProgress elapsed={elapsed} limit={limit} />}
      {pauseReason.startsWith('reuni') && (
        <p
          className="mt-5 line-clamp-3 rounded-lg border border-white/5 bg-slate-950/40 px-3 py-2 text-sm leading-6 text-slate-400"
          title={current.observacao_reuniao || 'Sem observacao informada'}
        >
          {current.observacao_reuniao || 'Sem observacao informada'}
        </p>
      )}
      <button className="btn-danger mt-6 w-full" disabled={loading} onClick={onFinish} type="button">
        {loading ? 'Finalizando...' : 'Finalizar pausa'}
      </button>
    </section>
  )
}

function StartPauseCard({ loading, onStart, onRequest }) {
  const [reason, setReason] = useState('')
  const [observation, setObservation] = useState('')
  const meeting = reason === 'Reunião'

  async function submit(event) {
    event.preventDefault()
    const result = meeting ? await onRequest(reason, observation) : await onStart(reason)
    if (result) {
      setReason('')
      setObservation('')
    }
  }

  return (
    <form className="card space-y-5" onSubmit={submit}>
      <div>
        <p className="text-sm text-slate-500">Nova pausa</p>
        <h2 className="mt-1 text-xl font-bold text-white">Iniciar pausa</h2>
      </div>
      <label className="label">
        Motivo
        <select className="field mt-2" value={reason} onChange={(event) => setReason(event.target.value)} required>
          <option value="">Selecione o motivo</option>
          {reasons.map((item) => <option value={item} key={item}>{item}{item === 'Reunião' ? ' com aprovação' : ''}</option>)}
        </select>
      </label>
      {meeting && (
        <label className="label">
          Contexto da reunião
          <textarea className="field mt-2 min-h-28" maxLength={500} onChange={(event) => setObservation(event.target.value)} required value={observation} />
          <span className="mt-1 block text-right text-xs text-slate-600">{observation.length}/500</span>
        </label>
      )}
      <button className="btn-primary w-full" disabled={loading || !reason} type="submit">
        {loading ? 'Processando...' : meeting ? 'Solicitar aprovação' : 'Iniciar pausa'}
      </button>
    </form>
  )
}

function TeamStatus({ title, items, elapsedFor, canForceEndBreak = false, loading = false, onForceEndBreak }) {
  return (
    <section>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">{title}</h2>
        <span className="text-xs text-slate-600">{items.length} técnicos</span>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {items.map((item) => {
          const pending = item.status_aprovacao === 'pendente'
          const label = pending ? 'Aguardando aprovação' : item.em_pausa ? 'Em pausa' : item.disponibilidade?.label
          return (
            <article className="card card-interactive" key={item.id}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h3 className="font-semibold text-slate-200">{item.nome}</h3>
                  <p className="mt-1 text-xs text-slate-600">{item.jornada_entrada} - {item.jornada_saida}</p>
                </div>
                <span className={`status-badge ${item.em_pausa || pending ? 'status-warning' : item.disponibilidade?.status === 'disponivel' ? 'status-success' : 'status-neutral'}`}>{label}</span>
              </div>
              {item.em_pausa && <p className="mt-4 font-mono text-sm text-amber-300">{item.motivo_pausa} · {formatDuration(elapsedFor(item))}</p>}
              {canForceEndBreak && item.em_pausa && (
                <button
                  className="table-action mt-4 border border-red-500/20 text-red-300 hover:bg-red-500/10"
                  disabled={loading}
                  onClick={() => onForceEndBreak(item)}
                  title="Derrubar pausa do CI"
                  type="button"
                >
                  {loading ? 'Finalizando...' : 'Derrubar pausa'}
                </button>
              )}
            </article>
          )
        })}
      </div>
    </section>
  )
}

export function PausasPage({ session, status, livePauses, loading, onStart, onRequest, onFinish, onForceEndBreak }) {
  const employees = useMemo(() => [...(status.n1 || []), ...(status.n2 || [])], [status])
  const current = employees.find((item) => Number(item.id) === Number(session.ci.funcionario_id))
  const canForceEndBreak = session.role === 'admin'

  return (
    <div className="space-y-7">
      {session.ci.autenticado ? (
        <div className="grid gap-5 lg:grid-cols-2">
          {current?.em_pausa
            ? <ActivePauseCard current={current} elapsed={livePauses.elapsedFor(current)} loading={loading} onFinish={onFinish} />
            : <StartPauseCard loading={loading} onStart={onStart} onRequest={onRequest} />}
          <section className="card flex flex-col justify-center">
            <p className="text-sm text-slate-500">Sessão do colaborador</p>
            <h2 className="mt-2 text-2xl font-bold text-white">{session.ci.nome}</h2>
            <p className="mt-1 text-sm text-slate-400">{session.ci.username}</p>
            {current?.status_aprovacao === 'pendente' && (
              <div className="mt-5 rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 text-sm text-amber-200">
                Sua solicitação de reunião está aguardando aprovação.
              </div>
            )}
          </section>
        </div>
      ) : (
        <div className="card border-blue-500/20 bg-blue-500/5 text-sm text-blue-200">
          A sessão administrativa permite monitoramento. Entre como CI para iniciar ou finalizar sua própria pausa.
        </div>
      )}
      <div className={`text-xs ${livePauses.connection === 'online' ? 'text-emerald-400' : 'text-amber-400'}`}>
        {livePauses.connection === 'online' ? 'Atualizacao em tempo real ativa' : 'Conexao instavel; exibindo o ultimo estado recebido'}
      </div>
      <TeamStatus title="Equipe N1" items={status.n1 || []} elapsedFor={livePauses.elapsedFor} canForceEndBreak={canForceEndBreak} loading={loading} onForceEndBreak={onForceEndBreak} />
      <TeamStatus title="Equipe N2" items={status.n2 || []} elapsedFor={livePauses.elapsedFor} canForceEndBreak={canForceEndBreak} loading={loading} onForceEndBreak={onForceEndBreak} />
    </div>
  )
}
