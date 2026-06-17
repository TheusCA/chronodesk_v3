import { useMemo, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { useResource } from '../hooks/useResource'
import { post } from '../lib/api'
import { queryString } from '../lib/operational'

const defaultPas = ['1725', '1726', '1727', '1728', '1729', '1730', '1731', '1732']
const statuses = [
  ['onsite', 'Presencial'],
  ['hybrid', 'Hibrido'],
  ['remote', 'Remoto'],
  ['critical', 'Critico'],
  ['unavailable', 'Indisponivel'],
]
const statusTone = {
  onsite: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-300',
  hybrid: 'border-blue-500/25 bg-blue-500/10 text-blue-300',
  remote: 'border-amber-500/25 bg-amber-500/10 text-amber-300',
  critical: 'border-red-500/25 bg-red-500/10 text-red-300',
  unavailable: 'border-slate-600/50 bg-slate-800 text-slate-400',
  free: 'border-slate-600/50 bg-slate-950/50 text-slate-500',
}

function labelFor(value) {
  return statuses.find(([key]) => key === value)?.[1] || 'Livre'
}

function normalizeForm(item, date) {
  return {
    id: item?.id || null,
    pa_number: item?.pa_number || '',
    work_date: item?.work_date || date,
    shift_label: item?.shift_label || 'integral',
    employee_id: item?.employee_id || '',
    status: item?.status || 'onsite',
    notes: item?.notes || '',
  }
}

function PaCard({ pa, allocation, canManage, onOpen }) {
  const occupied = Boolean(allocation)
  const status = allocation?.status || 'free'
  return (
    <button
      className={`group min-h-36 rounded-lg border p-4 text-left transition hover:-translate-y-0.5 hover:border-blue-400/40 ${occupied ? 'border-white/10 bg-slate-900/80' : 'border-dashed border-white/10 bg-slate-950/45'}`}
      onClick={() => onOpen(pa, allocation)}
      type="button"
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-slate-600">PA</p>
          <strong className="mt-1 block text-2xl font-black text-white">{pa}</strong>
        </div>
        <span className={`rounded-full border px-2.5 py-1 text-[11px] font-bold ${statusTone[status] || statusTone.free}`}>
          {labelFor(status)}
        </span>
      </div>
      {occupied ? (
        <div className="mt-5 min-w-0">
          <p className="truncate text-sm font-semibold text-slate-100">{allocation.employee_name}</p>
          <p className="mt-1 text-xs uppercase tracking-wider text-slate-500">Equipe {allocation.team}</p>
          <p className="mt-3 line-clamp-2 text-xs leading-5 text-slate-500">
            {allocation.schedule_label || 'Sem escala confirmada'}
          </p>
        </div>
      ) : (
        <div className="mt-7 flex items-center justify-between gap-3 text-sm text-slate-500">
          <span>Livre</span>
          {canManage && <span className="rounded-md border border-white/10 px-2 py-1 text-xs text-blue-300">Alocar</span>}
        </div>
      )}
    </button>
  )
}

function PaModal({ item, date, employees, canManage, onClose, onSave, onRemove, saving }) {
  const [form, setForm] = useState(normalizeForm(item, date))
  const update = (key, value) => setForm((current) => ({ ...current, [key]: value }))

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/75 p-4 backdrop-blur-sm">
      <button aria-label="Fechar mapa" className="fixed inset-0" onClick={onClose} type="button" />
      <form className="card relative mx-auto my-6 max-w-2xl border-blue-500/20" onSubmit={(event) => { event.preventDefault(); onSave(form) }}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-400">Mapa de PA</p>
            <h2 className="mt-2 text-xl font-bold text-white">PA {form.pa_number}</h2>
          </div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Fechar</button>
        </div>

        <div className="mt-6 grid gap-4 sm:grid-cols-2">
          <label className="label">PA<input className="field mt-2" disabled={!canManage} maxLength="20" value={form.pa_number} onChange={(event) => update('pa_number', event.target.value)} /></label>
          <label className="label">Data<input className="field mt-2" disabled={!canManage} type="date" value={form.work_date} onChange={(event) => update('work_date', event.target.value)} /></label>
          <label className="label">Turno<input className="field mt-2" disabled={!canManage} maxLength="40" value={form.shift_label} onChange={(event) => update('shift_label', event.target.value)} /></label>
          <label className="label">Status<select className="field mt-2" disabled={!canManage} value={form.status} onChange={(event) => update('status', event.target.value)}>{statuses.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
          <label className="label sm:col-span-2">Colaborador<select className="field mt-2" disabled={!canManage} required value={form.employee_id} onChange={(event) => update('employee_id', event.target.value)}><option value="">Selecione</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name} - {employee.team.toUpperCase()} ({employee.schedule_label})</option>)}</select></label>
          <label className="label sm:col-span-2">Observacao<textarea className="field mt-2 min-h-24" disabled={!canManage} maxLength="1000" value={form.notes} onChange={(event) => update('notes', event.target.value)} /></label>
        </div>

        {canManage ? (
          <div className="mt-6 flex flex-wrap justify-end gap-3">
            {form.id && <button className="btn-danger mr-auto" disabled={saving} onClick={() => onRemove(form.id)} type="button">Remover</button>}
            <button className="btn-secondary" disabled={saving} onClick={onClose} type="button">Cancelar</button>
            <button className="btn-primary" disabled={saving || !form.employee_id || !form.pa_number} type="submit">{saving ? 'Salvando...' : 'Salvar'}</button>
          </div>
        ) : (
          <p className="mt-6 rounded-lg border border-white/10 bg-slate-950/40 px-3 py-2 text-sm text-slate-400">Seu perfil permite apenas visualizar o mapa.</p>
        )}
      </form>
    </div>
  )
}

export function PaMapPage({ notify }) {
  const [filters, setFilters] = useState({ date: new Date().toISOString().slice(0, 10), team: '' })
  const resource = useResource(`portal/pa_map.php${queryString(filters)}`)
  const [selected, setSelected] = useState(null)
  const [saving, setSaving] = useState(false)
  const items = useMemo(() => resource.data?.items || [], [resource.data?.items])
  const employees = useMemo(() => resource.data?.employees || [], [resource.data?.employees])
  const canManage = Boolean(resource.data?.can_manage)
  const pas = useMemo(() => {
    const numbers = new Set(defaultPas)
    items.forEach((item) => numbers.add(item.pa_number))
    return Array.from(numbers).sort((a, b) => a.localeCompare(b, 'pt-BR', { numeric: true }))
  }, [items])
  const allocationByPa = useMemo(() => {
    const map = new Map()
    items.forEach((item) => map.set(item.pa_number, item))
    return map
  }, [items])

  async function save(form, confirmed = false) {
    setSaving(true)
    try {
      const result = await post('portal/pa_map.php', { ...form, action: 'save', confirm_remote_allocation: confirmed })
      notify(result.warnings?.[0] || result.mensagem)
      setSelected(null)
      await resource.refresh()
    } catch (error) {
      if (!confirmed && error.message.includes('remoto') && window.confirm(`${error.message}\n\nDeseja alocar mesmo assim?`)) {
        await save(form, true)
        return
      }
      notify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  async function remove(id) {
    setSaving(true)
    try {
      const result = await post('portal/pa_map.php', { action: 'remove', id })
      notify(result.mensagem)
      setSelected(null)
      await resource.refresh()
    } catch (error) {
      notify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  if (resource.loading) return <LoadingState label="Carregando Mapa de PA" />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  return (
    <div className="space-y-5">
      <section className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-blue-400">Mapa visual da operacao</p>
          <h2 className="mt-2 text-2xl font-black text-white">Mapa de PA</h2>
          <p className="mt-2 text-sm text-slate-500">Alocacao fisica por data, PA e turno, considerando escala hibrida/home office.</p>
        </div>
        <div className="card grid gap-3 p-4 sm:grid-cols-2 lg:w-[520px]">
          <label className="label">Data<input className="field mt-2" type="date" value={filters.date} onChange={(event) => setFilters({ ...filters, date: event.target.value })} /></label>
          <label className="label">Equipe<select className="field mt-2" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option></select></label>
        </div>
      </section>

      {items.length === 0 && <EmptyState title="Nenhuma alocacao cadastrada" description="Use os cards livres para montar o mapa do dia." />}

      <section className="overflow-x-auto rounded-lg border border-white/5 bg-slate-950/25 p-4">
        <div className="grid min-w-[760px] grid-cols-4 gap-3 lg:grid-cols-8">
          {pas.map((pa) => (
            <PaCard
              allocation={allocationByPa.get(pa)}
              canManage={canManage}
              key={pa}
              onOpen={(paNumber, allocation) => setSelected({ ...(allocation || {}), pa_number: paNumber, work_date: filters.date })}
              pa={pa}
            />
          ))}
        </div>
      </section>

      <section className="grid gap-3 md:grid-cols-3">
        <div className="rounded-lg border border-white/5 bg-slate-950/30 p-4"><strong className="text-lg text-white">{items.length}</strong><p className="text-xs text-slate-500">PAs ocupados no filtro</p></div>
        <div className="rounded-lg border border-white/5 bg-slate-950/30 p-4"><strong className="text-lg text-emerald-300">{items.filter((item) => item.schedule_status === 'onsite').length}</strong><p className="text-xs text-slate-500">Com escala presencial</p></div>
        <div className="rounded-lg border border-white/5 bg-slate-950/30 p-4"><strong className="text-lg text-amber-300">{items.filter((item) => item.schedule_status === 'remote' || item.schedule_status === 'no_schedule').length}</strong><p className="text-xs text-slate-500">Com alerta de escala</p></div>
      </section>

      {selected && (
        <PaModal
          canManage={canManage}
          date={filters.date}
          employees={employees}
          item={selected}
          onClose={() => setSelected(null)}
          onRemove={remove}
          onSave={save}
          saving={saving}
        />
      )}
    </div>
  )
}
