import { useMemo, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { PartialMarker, PresenceLegend } from '../components/ui/Presence'
import { useResource } from '../hooks/useResource'
import { post } from '../lib/api'
import { ACTION_FEEDBACK } from '../lib/actionFeedback'
import { localDate, queryString } from '../lib/operational'
import {
  absenceOriginLabel,
  absencePeriodLabel,
  brDate,
  conflictDaysLabel,
  presenceReasonLabel,
  saveFeedback,
  showsCategory,
  weekDays,
} from '../lib/paMap'
import { presenceLegendItem, situationLabel } from '../lib/presenceLegend'
import {
  FilterBar,
  InlineAlert,
  MetricCard,
  SectionHeader,
  UserAvatar,
} from '../components/ui/Primitives'

// F6 (desenho 5.6 e 9.8): mapa pelo motor. A situação do técnico usa a
// legenda única do F5 (classes presence-*); o PA em conflito na semana (seg a
// sex) ganha contorno com os dias (D6); sem confirmação ao salvar (P13).

const fallbackPas = [
  '1732', '1731', '1730', '1729', '1728', '1727', '1726', '1725',
  '1724', '1723', '1722', '1721', '1720', '1719', '1718', '1717',
]

function defaultPaObjects() {
  return fallbackPas.map((pa, index) => ({
    pa_number: pa,
    display_order: index + 1,
    row_number: Math.floor(index / 8) + 1,
    column_number: (index % 8) + 1,
    assignments: [],
    has_conflict: false,
    conflict_days: [],
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

// Situação do vínculo na data: o item da legenda (motor) ou o motivo sem
// situação (inativo, arquivado, fim de semana).
function assignmentStatus(item) {
  const legend = presenceLegendItem(item.presence?.status)
  if (legend !== null) {
    return { tone: legend.tone, icon: legend.icon, label: situationLabel(item.presence.situacao) || legend.label }
  }
  return { tone: 'border-slate-600/50 bg-slate-900/40 text-slate-400', icon: 'help', label: presenceReasonLabel(item.presence_reason) }
}

// Cartão do técnico: preenchido na cor da situação (ausente no laranja da
// legenda, D6), sem escala tracejado; marcador da parcial com o horário.
export function AssignmentChip({ item }) {
  const status = assignmentStatus(item)
  return (
    <div className={`min-w-0 rounded-lg border px-2.5 py-2 ${status.tone}`} data-presence={item.presence?.status ?? 'none'}>
      <div className="flex min-w-0 items-center justify-between gap-2">
        <span className="flex min-w-0 items-center gap-1.5 text-xs font-semibold">
          <Icon name={status.icon} className="h-3 w-3 shrink-0" />
          <span className="truncate" title={item.employee_name}>{item.employee_name}</span>
        </span>
        <span className="flex shrink-0 items-center gap-1 text-[10px] font-bold">
          {item.presence?.parcial && <PartialMarker time={item.presence.parcial} />}
          {status.label}
        </span>
      </div>
      <p className="mt-1 truncate text-[10px] uppercase tracking-[0.14em] opacity-75">Equipe {teamLabel(item.team)} · {item.schedule_rule_label}</p>
    </div>
  )
}

export function PaCard({ pa, canManage, onOpen }) {
  const assignments = pa.assignments || []
  const onsiteCount = assignments.filter((item) => item.presence?.status === 'onsite').length
  const conflictDays = conflictDaysLabel(pa.conflict_days)
  const outline = pa.has_conflict
    ? 'border-red-400/80 bg-slate-900/80 ring-2 ring-red-400/60'
    : assignments.length ? 'border-white/10 bg-slate-900/80' : 'border-dashed border-white/10 bg-slate-950/45'
  return (
    <button
      aria-label={`PA ${pa.pa_number}${pa.has_conflict ? `, conflito: ${conflictDays}` : ''}`}
      className={`pa-cell ${outline}`}
      data-conflict={pa.has_conflict ? 'true' : 'false'}
      onClick={() => onOpen(pa)}
      type="button"
    >
      <div className="flex min-w-0 items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-cyan-300/70">PA</p>
          <strong className="mt-1 block text-2xl font-black text-white">{pa.pa_number}</strong>
        </div>
        {pa.has_conflict ? (
          <span className="flex shrink-0 items-center gap-1 rounded-full border border-red-400/50 bg-red-500/15 px-2.5 py-1 text-[11px] font-bold text-red-200">
            <Icon name="alert" className="h-3 w-3" />Conflito
          </span>
        ) : (
          <span className={`shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-bold ${onsiteCount ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-300' : 'border-slate-600/50 bg-slate-950/60 text-slate-500'}`}>
            {assignments.length ? `${onsiteCount}/${assignments.length} presencial` : 'Livre'}
          </span>
        )}
      </div>
      {pa.has_conflict && <p className="mt-2 text-xs font-semibold text-red-200">Conflito: {conflictDays}</p>}

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

export function AssignmentList({ assignments, canManage, onEdit, onRemove, saving }) {
  if (assignments.length === 0) {
    return <InlineAlert title="PA livre">Nenhum colaborador vinculado a este PA na data.</InlineAlert>
  }
  return (
    <div className="space-y-2">
      {assignments.map((item) => (
        <div className="rounded-lg border border-white/5 bg-slate-950/35 p-3" key={`${item.source}-${item.id}`}>
          <div className="flex items-start gap-3">
            <UserAvatar name={item.employee_name} />
            <div className="min-w-0 flex-1">
              <AssignmentChip item={item} />
              {item.presence?.detalhe && <p className="mt-2 text-xs text-slate-400">{item.presence.detalhe}</p>}
              {item.presence?.categoria && <p className="mt-1 text-xs text-slate-400">Categoria: {item.presence.categoria}</p>}
              <p className="mt-2 text-xs text-slate-500">Desde {brDate(item.valid_from)}{item.valid_until ? ` até ${brDate(item.valid_until)}` : ''}</p>
              {item.notes && <p className="mt-2 line-clamp-2 text-xs leading-5 text-slate-400" title={item.notes}>{item.notes}</p>}
            </div>
          </div>
          {canManage && item.source === 'assignment' && (
            <div className="mt-3 flex gap-2">
              <button className="table-action" disabled={saving} onClick={() => onEdit(item)} type="button">Editar</button>
              <button className="table-action text-red-300" disabled={saving} onClick={() => onRemove(item.id)} type="button">Remover</button>
            </div>
          )}
          {item.source !== 'assignment' && <p className="mt-2 text-xs text-slate-600">Vínculo legado por data.</p>}
        </div>
      ))}
    </div>
  )
}

export function SaveFeedbackAlert({ feedback }) {
  if (!feedback || feedback.lines.length === 0) return null
  return (
    <InlineAlert className="mt-4" title={feedback.title} tone="warning">
      <ul className="space-y-1">{feedback.lines.map((line) => <li key={line}>{line}</li>)}</ul>
    </InlineAlert>
  )
}

export function PaModal({ pa, date, employees, canManage, feedback, onClose, onSave, onRemove, saving }) {
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
            <p className="mt-1 text-sm text-slate-500">Situação de cada técnico em {brDate(date)}, pela escala e pelas ausências.</p>
          </div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Fechar</button>
        </div>

        {pa.has_conflict && (
          <InlineAlert className="mt-4" title="Conflito na semana" tone="danger">
            Dois ou mais técnicos presenciais neste PA em: {conflictDaysLabel(pa.conflict_days)}.
          </InlineAlert>
        )}
        <SaveFeedbackAlert feedback={feedback} />

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
            <p className="mt-3 text-xs text-slate-500">Conflito, remoto, ausência e falta de escala não impedem o vínculo: o mapa salva e mostra o aviso.</p>
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

// Aba "Ausências da semana" (DF9): ordenada pelo servidor (data e nome); a
// coluna Categoria só aparece quando o servidor a manda (P9).
export function AbsencesTable({ items }) {
  if (!items.length) {
    return <EmptyState title="Nenhuma ausência na semana" description="Nenhum técnico com vínculo de PA tem ausência aprovada de segunda a sexta." />
  }
  const withCategory = showsCategory(items)
  return (
    <div className="overflow-x-auto rounded-card border border-white/5">
      <table className="data-table">
        <thead>
          <tr><th>Técnico</th><th>Equipe</th><th>Data</th><th>Período</th><th>Origem</th>{withCategory && <th>Categoria</th>}</tr>
        </thead>
        <tbody>
          {items.map((item, index) => (
            <tr key={`${item.employee_id}-${item.date}-${item.start_time ?? ''}-${index}`}>
              <td><strong>{item.employee_name}</strong></td>
              <td className="whitespace-nowrap">{teamLabel(item.team)}</td>
              <td className="whitespace-nowrap">{brDate(item.date)}</td>
              <td className="whitespace-nowrap">{absencePeriodLabel(item)}</td>
              <td>{absenceOriginLabel(item.origem)}</td>
              {withCategory && <td>{item.category || '—'}</td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function MapTabs({ tab, absences, onSelect }) {
  return (
    <div aria-label="Visão do mapa" className="flex gap-2" role="tablist">
      <button aria-selected={tab === 'map'} className={tab === 'map' ? 'btn-primary' : 'btn-secondary'} onClick={() => onSelect('map')} role="tab" type="button">Mapa</button>
      <button aria-selected={tab === 'absences'} className={tab === 'absences' ? 'btn-primary' : 'btn-secondary'} onClick={() => onSelect('absences')} role="tab" type="button">Ausências da semana ({absences})</button>
    </div>
  )
}

export function DaySelector({ days, date, onSelect }) {
  return (
    <div aria-label="Dia da semana" className="flex flex-wrap gap-2" role="group">
      {days.map((day) => (
        <button aria-pressed={day.date === date} className={day.date === date ? 'btn-primary' : 'btn-secondary'} key={day.date} onClick={() => onSelect(day.date)} type="button">
          {day.label}
        </button>
      ))}
    </div>
  )
}

// Quem não edita é o gestor, o técnico e o perfil somente leitura: o texto não
// fala em "somente leitura" para não confundir com o nome desse perfil.
export function PermissionCard({ canManage }) {
  return canManage
    ? <MetricCard detail="Admin/Liderança podem editar" icon="settings" label="Permissão" tone="warning" value="Edição" />
    : <MetricCard detail="Visualização: só Admin e Liderança editam o mapa" icon="settings" label="Permissão" tone="info" value="Leitura" />
}

export function PaMapPage({ notify }) {
  // L-03: a data padrão é a de hoje no fuso local, não em UTC.
  const [filters, setFilters] = useState({ date: localDate(), team: '' })
  const resource = useResource(`portal/pa_map.php${queryString(filters)}`)
  const [selectedPa, setSelectedPa] = useState(null)
  const [tab, setTab] = useState('map')
  const [saving, setSaving] = useState(false)
  const [feedback, setFeedback] = useState(null)
  const pas = useMemo(() => resource.data?.pas || defaultPaObjects(), [resource.data?.pas])
  const employees = useMemo(() => resource.data?.employees || [], [resource.data?.employees])
  const canManage = Boolean(resource.data?.can_manage)
  const assignments = useMemo(() => pas.flatMap((pa) => pa.assignments || []), [pas])
  const onsiteCount = assignments.filter((item) => item.presence?.status === 'onsite').length
  const conflictCount = pas.filter((pa) => pa.has_conflict).length
  const absences = resource.data?.absences_week || []
  const days = weekDays(resource.data?.week, filters.date)
  // O modal lê o PA da última resposta: a lista e o conflito se atualizam na recarga.
  const selected = selectedPa === null ? null : pas.find((pa) => pa.pa_number === selectedPa) || null
  const names = useMemo(() => new Map([
    ...employees.map((employee) => [String(employee.id), employee.name]),
    ...assignments.map((item) => [String(item.employee_id), item.employee_name]),
  ]), [employees, assignments])

  function open(pa) {
    setFeedback(null)
    setSelectedPa(pa.pa_number)
  }

  async function save(form, resetForm) {
    setSaving(true)
    try {
      // P13: sem confirmação; conflito, remoto, ausência e sem escala voltam como aviso.
      const result = await post('portal/pa_map.php', { ...form, action: 'save' })
      const outcome = saveFeedback(result, form.employee_id, names)
      setFeedback(outcome)
      notify(outcome.lines.length ? `${outcome.title}. ${outcome.lines[0]}` : ACTION_FEEDBACK.paLinked, outcome.lines.length ? 'warning' : 'success')
      resetForm?.()
      await resource.refresh()
    } catch (error) {
      notify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  async function remove(id) {
    setSaving(true)
    try {
      await post('portal/pa_map.php', { action: 'remove', id })
      setFeedback(null)
      notify(ACTION_FEEDBACK.paUnlinked)
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
    <div className="min-w-0 space-y-5">
      <SectionHeader
        description="Situação de cada técnico pela escala e pelas ausências, com conflito de PA na semana (segunda a sexta)."
        eyebrow="Mapa visual da operação"
        title="Mapa de PA"
      />
      <FilterBar>
          <label className="label">Data<input className="field mt-2" type="date" value={filters.date} onChange={(event) => setFilters({ ...filters, date: event.target.value })} /></label>
          <label className="label">Equipe<select className="field mt-2" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option><option value="lideranca">Liderança</option></select></label>
      </FilterBar>
      <DaySelector date={filters.date} days={days} onSelect={(date) => setFilters({ ...filters, date })} />

      <section className="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <MetricCard detail="PAs no inventário visual" icon="building" label="PAs" value={pas.length} />
        <MetricCard detail="Vínculos na data" icon="users" label="Vínculos" value={assignments.length} />
        <MetricCard detail="Técnicos presenciais na data" icon="shield" label="Presenciais" tone="success" value={onsiteCount} />
        <MetricCard detail="PAs com dois ou mais presenciais num dia da semana" icon="alert" label="Conflitos" tone={conflictCount ? 'danger' : 'info'} value={conflictCount} />
        <PermissionCard canManage={canManage} />
      </section>

      <section aria-label="Legenda do mapa" className="card flex flex-wrap gap-2">
        <PresenceLegend />
        <span className="status-badge gap-1 border-red-400/60 bg-red-500/15 text-red-200"><Icon name="alert" className="h-3 w-3" />PA em conflito (contorno)</span>
        <span className="status-badge status-neutral border-dashed">PA livre</span>
      </section>

      <MapTabs absences={absences.length} onSelect={setTab} tab={tab} />

      {tab === 'map' ? (
        <>
          {assignments.length === 0 && <EmptyState title="Nenhum vínculo na data" description="Todos os PAs continuam visíveis e livres para montagem do mapa." />}
          <section className="pa-grid-shell">
            <div className="grid w-full min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-8">
              {pas.map((pa) => (
                <PaCard canManage={canManage} key={pa.pa_number} onOpen={open} pa={pa} />
              ))}
            </div>
          </section>
        </>
      ) : (
        <AbsencesTable items={absences} />
      )}

      {selected && (
        <PaModal
          canManage={canManage}
          date={filters.date}
          employees={employees}
          feedback={feedback}
          onClose={() => setSelectedPa(null)}
          onRemove={remove}
          onSave={save}
          pa={selected}
          saving={saving}
        />
      )}
    </div>
  )
}
