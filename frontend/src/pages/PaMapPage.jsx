import { useMemo, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { post } from '../lib/api'
import { queryString } from '../lib/operational'
import {
  FilterBar,
  InlineAlert,
  MetricCard,
  OperationalLegend,
  SectionHeader,
  UserAvatar,
} from '../components/ui/Primitives'

const fallbackPas = [
  '1732', '1731', '1730', '1729', '1728', '1727', '1726', '1725',
  '1724', '1723', '1722', '1721', '1720', '1719', '1718', '1717',
]

const ruleTone = {
  even_days: 'border-blue-500/25 bg-blue-500/10 text-blue-300',
  odd_days: 'border-violet-500/25 bg-violet-500/10 text-violet-300',
  always_onsite: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-300',
  always_remote: 'border-amber-500/25 bg-amber-500/10 text-amber-300',
  undefined: 'border-slate-600/50 bg-slate-800 text-slate-400',
}

function defaultPaObjects() {
  return fallbackPas.map((pa, index) => ({
    pa_number: pa,
    display_order: index + 1,
    row_number: Math.floor(index / 8) + 1,
    column_number: (index % 8) + 1,
    assignments: [],
  }))
}

function emptyForm(paNumber, date) {
  return {
    id: null,
    pa_number: paNumber,
    employee_id: '',
    valid_from: date,
    notes: '',
  }
}

function formFromAssignment(assignment, date) {
  return {
    id: assignment.id,
    pa_number: assignment.pa_number,
    employee_id: assignment.employee_id,
    valid_from: assignment.valid_from || date,
    notes: assignment.notes || '',
  }
}

function teamLabel(team) {
  if (team === 'n1') return 'N1'
  if (team === 'n2') return 'N2'
  if (team === 'lideranca') return 'Liderança'
  return String(team || '').toUpperCase()
}

function AssignmentChip({ item }) {
  const tone = ruleTone[item.schedule_rule_type] || ruleTone.undefined
  return (
    <div className={`rounded-lg border px-2.5 py-2 ${item.active_on_date ? 'bg-opacity-100 ring-1 ring-white/10' : 'opacity-55'} ${tone}`}>
      <div className="flex items-center justify-between gap-2">
        <span className="min-w-0 truncate text-xs font-semibold">{item.employee_name}</span>
        <span className="shrink-0 rounded-full border border-current/20 px-2 py-0.5 text-[10px] font-bold">
          {item.schedule_rule_label}
        </span>
      </div>
      <p className="mt-1 text-[10px] uppercase tracking-[0.14em] opacity-75">Equipe {teamLabel(item.team)} {item.ad_login ? `· ${item.ad_login}` : ''}</p>
    </div>
  )
}

function PaCard({ pa, canManage, onOpen }) {
  const assignments = pa.assignments || []
  const activeCount = assignments.filter((item) => item.active_on_date).length
  return (
    <button
      className={`pa-cell ${assignments.length ? 'border-white/10 bg-slate-900/80' : 'border-dashed border-white/10 bg-slate-950/45'}`}
      onClick={() => onOpen(pa)}
      type="button"
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-cyan-300/70">PA</p>
          <strong className="mt-1 block text-2xl font-black text-white">{pa.pa_number}</strong>
        </div>
        <span className={`rounded-full border px-2.5 py-1 text-[11px] font-bold ${activeCount ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-300' : 'border-slate-600/50 bg-slate-950/60 text-slate-500'}`}>
          {assignments.length ? `${activeCount}/${assignments.length} hoje` : 'Livre'}
        </span>
      </div>

      {assignments.length ? (
        <div className="mt-4 space-y-2">
          {assignments.slice(0, 3).map((item) => <AssignmentChip item={item} key={`${item.source}-${item.id}`} />)}
          {assignments.length > 3 && <p className="text-xs text-slate-500">+{assignments.length - 3} vínculo(s)</p>}
        </div>
      ) : (
        <div className="mt-8 flex items-center justify-between gap-3 text-sm text-slate-500">
          <span>Livre</span>
          {canManage && <span className="rounded-md border border-white/10 px-2 py-1 text-xs text-cyan-300">Alocar</span>}
        </div>
      )}
    </button>
  )
}

function AssignmentList({ assignments, canManage, onEdit, onRemove, saving }) {
  if (assignments.length === 0) {
    return <InlineAlert title="PA livre">Nenhum colaborador vinculado a este PA.</InlineAlert>
  }
  return (
    <div className="space-y-2">
      {assignments.map((item) => (
        <div className="rounded-lg border border-white/5 bg-slate-950/35 p-3" key={`${item.source}-${item.id}`}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="flex items-start gap-3">
                <UserAvatar name={item.employee_name} />
                <div>
                  <p className="truncate text-sm font-semibold text-slate-100">{item.employee_name}</p>
                  <p className="mt-1 text-xs uppercase tracking-wider text-slate-600">Equipe {teamLabel(item.team)} {item.ad_login ? `· ${item.ad_login}` : ''}</p>
                </div>
              </div>
            </div>
            <span className={`rounded-full border px-2.5 py-1 text-[11px] font-bold ${ruleTone[item.schedule_rule_type] || ruleTone.undefined}`}>
              {item.schedule_rule_label}
            </span>
          </div>
          <p className="mt-2 text-xs text-slate-500">
            Desde {item.valid_from}
            {item.active_on_date ? ' - presencial na data filtrada' : ' - fora da escala presencial da data'}
          </p>
          {item.notes && <p className="mt-2 line-clamp-2 text-xs leading-5 text-slate-400" title={item.notes}>{item.notes}</p>}
          {canManage && item.source === 'assignment' && (
            <div className="mt-3 flex gap-2">
              <button className="table-action" disabled={saving} onClick={() => onEdit(item)} type="button">Editar</button>
              <button className="table-action text-red-300" disabled={saving} onClick={() => onRemove(item.id)} type="button">Remover</button>
            </div>
          )}
          {item.source !== 'assignment' && <p className="mt-2 text-xs text-slate-600">Vinculo legado por data.</p>}
        </div>
      ))}
    </div>
  )
}

function PaModal({ pa, date, employees, canManage, onClose, onSave, onRemove, saving }) {
  const [form, setForm] = useState(emptyForm(pa.pa_number, date))
  const [editing, setEditing] = useState(false)
  const update = (key, value) => setForm((current) => ({ ...current, [key]: value }))
  const assignments = pa.assignments || []

  function edit(item) {
    setForm(formFromAssignment(item, date))
    setEditing(true)
  }

  function reset() {
    setForm(emptyForm(pa.pa_number, date))
    setEditing(false)
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/75 p-4 backdrop-blur-sm">
      <button aria-label="Fechar mapa" className="fixed inset-0" onClick={onClose} type="button" />
      <form className="card relative mx-auto my-6 max-w-3xl border-blue-500/20" onSubmit={(event) => { event.preventDefault(); onSave(form, reset) }}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="section-eyebrow">Mapa de PA</p>
            <h2 className="mt-2 text-xl font-bold text-white">PA {pa.pa_number}</h2>
            <p className="mt-1 text-sm text-slate-500">Vínculos recorrentes por regra de escala.</p>
          </div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Fechar</button>
        </div>

        <div className="mt-6">
          <SectionHeader title="Colaboradores vinculados" />
          <AssignmentList assignments={assignments} canManage={canManage} onEdit={edit} onRemove={onRemove} saving={saving} />
        </div>

        {canManage ? (
          <div className="mt-6 rounded-card border border-white/5 bg-slate-950/25 p-4">
            <div className="flex items-center justify-between gap-3">
              <h3 className="text-sm font-bold uppercase tracking-[0.14em] text-slate-500">{editing ? 'Editar vínculo' : 'Adicionar colaborador'}</h3>
              {editing && <button className="table-action" onClick={reset} type="button">Novo vínculo</button>}
            </div>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <label className="label sm:col-span-2">Colaborador<select className="field mt-2" required value={form.employee_id} onChange={(event) => update('employee_id', event.target.value)}><option value="">Selecione</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name} - {teamLabel(employee.team)} ({employee.schedule_rule_label})</option>)}</select></label>
              <label className="label">Início da validade<input className="field mt-2" required type="date" value={form.valid_from} onChange={(event) => update('valid_from', event.target.value)} /></label>
              <label className="label sm:col-span-2">Observação<textarea className="field mt-2 min-h-24" maxLength="1000" value={form.notes} onChange={(event) => update('notes', event.target.value)} /></label>
            </div>
            <div className="mt-5 flex justify-end gap-3">
              <button className="btn-secondary" disabled={saving} onClick={reset} type="button">Limpar</button>
              <button className="btn-primary" disabled={saving || !form.employee_id} type="submit">{saving ? 'Salvando...' : 'Salvar vínculo'}</button>
            </div>
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
  const pas = useMemo(() => resource.data?.pas || defaultPaObjects(), [resource.data?.pas])
  const employees = useMemo(() => resource.data?.employees || [], [resource.data?.employees])
  const canManage = Boolean(resource.data?.can_manage)
  const assignments = useMemo(() => pas.flatMap((pa) => pa.assignments || []), [pas])
  const activeToday = assignments.filter((item) => item.active_on_date).length

  async function save(form, resetForm, confirmed = false) {
    setSaving(true)
    try {
      const result = await post('portal/pa_map.php', { ...form, action: 'save', confirm_remote_allocation: confirmed })
      notify(result.warnings?.[0] || result.mensagem)
      resetForm?.()
      await resource.refresh()
    } catch (error) {
      if (!confirmed && error.message.includes('remoto') && window.confirm(`${error.message}\n\nDeseja alocar mesmo assim?`)) {
        await save(form, resetForm, true)
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
      <SectionHeader
        description="Vínculos recorrentes por escala híbrida/home office, com destaque para a data filtrada."
        eyebrow="Mapa visual da operação"
        title="Mapa de PA"
      />
      <FilterBar>
          <label className="label">Data<input className="field mt-2" type="date" value={filters.date} onChange={(event) => setFilters({ ...filters, date: event.target.value })} /></label>
          <label className="label">Equipe<select className="field mt-2" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option><option value="lideranca">Liderança</option></select></label>
      </FilterBar>

      {assignments.length === 0 && <EmptyState title="Nenhum vínculo cadastrado" description="Todos os PAs continuam visíveis e livres para montagem do mapa." />}

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard detail="PAs no inventário visual" icon="building" label="PAs" value={pas.length} />
        <MetricCard detail="Vínculos recorrentes carregados" icon="users" label="Vínculos" value={assignments.length} />
        <MetricCard detail="Alocações presenciais na data" icon="shield" label="Presenciais" tone="success" value={activeToday} />
        <MetricCard detail={canManage ? 'Admin/Gestor pode editar' : 'Seu perfil é somente leitura'} icon="settings" label="Permissão" tone={canManage ? 'warning' : 'info'} value={canManage ? 'Edição' : 'Leitura'} />
      </section>

      <OperationalLegend items={[
        { label: 'PA livre', className: 'bg-slate-500' },
        { label: 'Ocupado/presencial', className: 'bg-emerald-400' },
        { label: 'Regra par/ímpar', className: 'bg-amber-400' },
        { label: 'Remoto ou sem escala na data', className: 'bg-slate-700' },
      ]} />

      <section className="pa-grid-shell">
        <div className="grid min-w-[760px] grid-cols-4 gap-3 lg:grid-cols-8">
          {pas.map((pa) => (
            <PaCard canManage={canManage} key={pa.pa_number} onOpen={(item) => setSelected(item)} pa={pa} />
          ))}
        </div>
      </section>

      {selected && (
        <PaModal
          canManage={canManage}
          date={filters.date}
          employees={employees}
          onClose={() => setSelected(null)}
          onRemove={remove}
          onSave={save}
          pa={selected}
          saving={saving}
        />
      )}
    </div>
  )
}
