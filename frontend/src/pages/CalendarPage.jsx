import { useMemo, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { formatDateTime } from '../lib/format'
import { localDate, queryString } from '../lib/operational'
import { post } from '../lib/api'

const typeLabels = {
  manual: 'Evento',
  pause: 'Pausa',
  schedule: 'Escala',
  overtime: 'Hora extra',
  time_adjustment: 'Ajuste de ponto',
  oncall: 'Plantao',
  war_room: 'War Room',
  absence: 'Ausencia',
}

const typeTones = {
  manual: 'border-violet-500/30 bg-violet-500/10 text-violet-200',
  pause: 'border-amber-500/30 bg-amber-500/10 text-amber-200',
  schedule: 'border-blue-500/30 bg-blue-500/10 text-blue-200',
  overtime: 'border-cyan-500/30 bg-cyan-500/10 text-cyan-200',
  time_adjustment: 'border-slate-500/30 bg-slate-500/10 text-slate-200',
  oncall: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
  war_room: 'border-red-500/30 bg-red-500/10 text-red-200',
  absence: 'border-orange-500/30 bg-orange-500/10 text-orange-200',
}

function dateKey(value) {
  return String(value || '').slice(0, 10)
}

function monthRange(anchor, view) {
  const year = anchor.getFullYear()
  const month = anchor.getMonth()
  if (view === 'week') {
    const start = new Date(anchor)
    start.setDate(anchor.getDate() - ((anchor.getDay() + 6) % 7))
    const end = new Date(start)
    end.setDate(start.getDate() + 6)
    return { start, end }
  }
  const start = new Date(year, month, 1)
  start.setDate(start.getDate() - ((start.getDay() + 6) % 7))
  const end = new Date(start)
  end.setDate(start.getDate() + 41)
  return { start, end }
}

function asLocalDate(date) {
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

function EventChip({ event }) {
  return (
    <div className={`truncate rounded-md border px-2 py-1 text-[11px] font-semibold ${typeTones[event.event_type] || typeTones.manual}`}>
      {event.event_type === 'schedule' ? `${event.status === 'onsite' ? 'Presencial' : 'Remoto'}: ` : ''}
      {event.title}
    </div>
  )
}

export function CalendarPage({ session, notify }) {
  const canManage = session.role === 'admin' || session.role === 'gestor'
  const [anchor, setAnchor] = useState(() => new Date())
  const [view, setView] = useState('month')
  const [selectedDay, setSelectedDay] = useState(localDate())
  const [filters, setFilters] = useState({ team: '', employee_id: '', type: '', status: '' })
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    title: '', description: '', type: 'manual',
    starts_at: `${localDate()}T09:00`, ends_at: `${localDate()}T10:00`, team: '',
  })
  const employees = useResource('listar_funcionarios.php')
  const range = useMemo(() => monthRange(anchor, view), [anchor, view])
  const query = queryString({
    from: asLocalDate(range.start),
    to: asLocalDate(range.end),
    ...filters,
  })
  const resource = useResource(`portal/calendar.php${query}`)
  const events = useMemo(() => resource.data?.items || [], [resource.data?.items])
  const byDay = useMemo(() => {
    const map = new Map()
    events.forEach((event) => {
      const start = new Date(`${dateKey(event.starts_at)}T12:00:00`)
      const end = new Date(`${dateKey(event.ends_at || event.starts_at)}T12:00:00`)
      for (const day = new Date(start); day <= end; day.setDate(day.getDate() + 1)) {
        const key = asLocalDate(day)
        if (!map.has(key)) map.set(key, [])
        map.get(key).push(event)
      }
    })
    return map
  }, [events])
  const days = useMemo(() => {
    const total = view === 'week' ? 7 : 42
    return Array.from({ length: total }, (_, index) => {
      const day = new Date(range.start)
      day.setDate(range.start.getDate() + index)
      return day
    })
  }, [range.start, view])
  const selectedEvents = byDay.get(selectedDay) || []

  function move(direction) {
    const next = new Date(anchor)
    if (view === 'week') next.setDate(next.getDate() + (direction * 7))
    else next.setMonth(next.getMonth() + direction)
    setAnchor(next)
  }

  async function create(event) {
    event.preventDefault()
    try {
      const result = await post('portal/calendar.php', form)
      notify(result.mensagem)
      setShowForm(false)
      setForm((current) => ({ ...current, title: '', description: '' }))
      await resource.refresh()
    } catch (error) {
      notify(error.message, 'error')
    }
  }

  if (resource.loading) return <LoadingState label="Montando calendario operacional" />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  return (
    <div className="space-y-5">
      <section className="card">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
          <div className="flex flex-wrap items-center gap-2">
            <button className="btn-secondary" onClick={() => setAnchor(new Date())} type="button">Hoje</button>
            <button className="btn-secondary px-3" onClick={() => move(-1)} type="button" aria-label="Periodo anterior">‹</button>
            <button className="btn-secondary px-3" onClick={() => move(1)} type="button" aria-label="Proximo periodo">›</button>
            <h2 className="ml-1 text-xl font-black capitalize text-white">
              {anchor.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })}
            </h2>
          </div>
          <div className="flex flex-wrap gap-2">
            <button className={view === 'month' ? 'btn-primary' : 'btn-secondary'} onClick={() => setView('month')} type="button">Mes</button>
            <button className={view === 'week' ? 'btn-primary' : 'btn-secondary'} onClick={() => setView('week')} type="button">Semana</button>
            {canManage && <button className="btn-secondary" onClick={() => setShowForm((value) => !value)} type="button">Novo evento</button>}
          </div>
        </div>
        <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <select className="field" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}>
            <option value="">Todas as equipes</option><option value="n1">N1</option><option value="n2">N2</option>
          </select>
          <select className="field" value={filters.employee_id} onChange={(event) => setFilters({ ...filters, employee_id: event.target.value })}>
            <option value="">Todos os colaboradores</option>
            {(employees.data?.funcionarios || []).map((employee) => <option key={employee.id} value={employee.id}>{employee.nome} - {employee.equipe.toUpperCase()}</option>)}
          </select>
          <select className="field" value={filters.type} onChange={(event) => setFilters({ ...filters, type: event.target.value })}>
            <option value="">Todos os eventos</option>
            {Object.entries(typeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
          <select className="field" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
            <option value="">Todos os status</option>
            {['active', 'pending', 'approved', 'rejected', 'open', 'in_progress', 'war_room', 'mitigated', 'resolved', 'completed', 'cancelled'].map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}
          </select>
        </div>
      </section>

      {showForm && (
        <form className="card space-y-4" onSubmit={create}>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="label xl:col-span-2">Titulo<input className="field mt-2" maxLength="160" required value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} /></label>
            <label className="label">Inicio<input className="field mt-2" required type="datetime-local" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} /></label>
            <label className="label">Fim<input className="field mt-2" type="datetime-local" value={form.ends_at} onChange={(event) => setForm({ ...form, ends_at: event.target.value })} /></label>
            <label className="label">Equipe<select className="field mt-2" value={form.team} onChange={(event) => setForm({ ...form, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option></select></label>
            <label className="label xl:col-span-3">Descricao<input className="field mt-2" maxLength="1000" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></label>
          </div>
          <div className="flex gap-2"><button className="btn-primary" type="submit">Cadastrar</button><button className="btn-secondary" onClick={() => setShowForm(false)} type="button">Cancelar</button></div>
        </form>
      )}

      <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div className="card overflow-hidden p-0">
          <div className="grid grid-cols-7 border-b border-white/10 bg-slate-950/40 text-center text-[11px] font-bold uppercase tracking-wider text-slate-500">
            {['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'].map((label) => <div className="p-3" key={label}>{label}</div>)}
          </div>
          <div className="grid grid-cols-7">
            {days.map((day) => {
              const key = asLocalDate(day)
              const dayEvents = byDay.get(key) || []
              const outside = view === 'month' && day.getMonth() !== anchor.getMonth()
              const selected = selectedDay === key
              return (
                <button
                  className={`min-h-28 border-b border-r border-white/[0.06] p-2 text-left align-top transition hover:bg-white/[0.03] sm:min-h-36 ${outside ? 'bg-slate-950/30 opacity-45' : ''} ${selected ? 'ring-1 ring-inset ring-blue-500/60' : ''}`}
                  key={key}
                  onClick={() => setSelectedDay(key)}
                  type="button"
                >
                  <span className={`grid h-7 w-7 place-items-center rounded-full text-xs font-bold ${key === localDate() ? 'bg-blue-600 text-white' : 'text-slate-400'}`}>{day.getDate()}</span>
                  <div className="mt-2 space-y-1">
                    {dayEvents.slice(0, view === 'week' ? 8 : 3).map((event) => <EventChip event={event} key={`${key}-${event.id}`} />)}
                    {dayEvents.length > (view === 'week' ? 8 : 3) && <p className="px-1 text-[10px] text-slate-500">+{dayEvents.length - (view === 'week' ? 8 : 3)} eventos</p>}
                  </div>
                </button>
              )
            })}
          </div>
        </div>
        <aside className="card h-fit xl:sticky xl:top-28">
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-400">Detalhes do dia</p>
          <h3 className="mt-2 text-lg font-bold capitalize text-white">{new Date(`${selectedDay}T12:00:00`).toLocaleDateString('pt-BR', { dateStyle: 'full' })}</h3>
          <div className="mt-5 space-y-3">
            {selectedEvents.length === 0 && <EmptyState title="Dia sem eventos" description="Selecione outro dia ou cadastre um evento manual." />}
            {selectedEvents.map((event) => (
              <article className="rounded-xl border border-white/[0.07] bg-slate-950/30 p-3" key={event.id}>
                <div className="flex items-start justify-between gap-2"><strong className="text-sm text-slate-200">{event.title}</strong><span className={`status-badge ${typeTones[event.event_type] || typeTones.manual}`}>{typeLabels[event.event_type] || event.event_type}</span></div>
                <p className="mt-2 text-xs text-slate-500">{formatDateTime(event.starts_at)}</p>
                {event.description && <p className="mt-2 text-sm leading-5 text-slate-400">{event.description}</p>}
              </article>
            ))}
          </div>
        </aside>
      </section>
      <section className="card flex flex-wrap gap-2">
        {Object.entries(typeLabels).map(([type, label]) => <span className={`status-badge ${typeTones[type]}`} key={type}>{label}</span>)}
      </section>
    </div>
  )
}
