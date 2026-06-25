import { useMemo, useRef, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { useResource } from '../hooks/useResource'
import { api, apiUrl, post, postForm } from '../lib/api'
import { ACTION_FEEDBACK, importFeedback } from '../lib/actionFeedback'
import { formatDateTime } from '../lib/format'
import {
  DetailPill,
  FileTypeBadge,
  FilterBar,
  InlineAlert,
  MetricCard,
  SectionHeader,
} from '../components/ui/Primitives'
import {
  competencyFor,
  CRITICAL_INCIDENT_IMPORT_LIMITS,
  queryString,
} from '../lib/operational'

const competency = competencyFor()
const severityOptions = ['low', 'medium', 'high', 'critical']
const statusOptions = ['open', 'in_progress', 'war_room', 'mitigated', 'resolved', 'cancelled']
const sourceOptions = [
  { value: 'servicenow', label: 'ServiceNow' },
  { value: 'teams', label: 'Teams' },
  { value: 'other', label: 'Outros' },
]

const emptyIncident = {
  incident_number: '',
  room_date: new Date().toISOString().slice(0, 10),
  incident_opened_at: '',
  operation_reported_at: '',
  room_opened_at: '',
  normalized_at: '',
  room_description: '',
  room_finalization_description: '',
  room_opening_duration_minutes: '',
  room_duration_minutes: '',
  sector: '',
  sdk_activity: '',
  ticket_number: '',
  source: 'servicenow',
  title: '',
  summary: '',
  severity: 'high',
  status: 'open',
  opened_at: '',
  war_room_started_at: '',
  mitigated_at: '',
  resolved_at: '',
  impact: '',
  affected_users: '',
  affected_services: '',
  responsible_area: '',
  sdk_responsible_employee_id: '',
  owner_name: '',
  owner_login: '',
  involved_teams: '',
  root_cause: '',
  resolution: '',
  workaround: '',
  actions_taken: '',
  next_steps: '',
  meeting_url: '',
  participants: '',
  notes: '',
}

const labels = {
  low: 'Baixa',
  medium: 'Média',
  high: 'Alta',
  critical: 'Crítica',
  open: 'Aberto',
  in_progress: 'Em andamento',
  war_room: 'Em war room',
  mitigated: 'Mitigado',
  resolved: 'Resolvido',
  cancelled: 'Cancelado',
}

function normalizeForForm(item) {
  const form = { ...emptyIncident, ...item }
  ;[
    'incident_opened_at', 'operation_reported_at', 'room_opened_at', 'normalized_at',
    'opened_at', 'war_room_started_at', 'mitigated_at', 'resolved_at',
  ].forEach((key) => {
    form[key] = item?.[key] ? String(item[key]).replace(' ', 'T').slice(0, 16) : ''
  })
  form.incident_number = item?.incident_number || item?.ticket_number || ''
  form.room_date = item?.room_date || String(item?.opened_at || '').slice(0, 10) || emptyIncident.room_date
  form.room_opening_duration_minutes = minutesToClock(item?.room_opening_duration_minutes)
  form.room_duration_minutes = minutesToClock(item?.room_duration_minutes)
  form.affected_users = item?.affected_users ?? ''
  form.sdk_responsible_employee_id = item?.sdk_responsible_employee_id ?? ''
  return form
}

function minutesBetween(from, to) {
  if (!from || !to) return ''
  const start = new Date(from).getTime()
  const end = new Date(to).getTime()
  if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) return ''
  return minutesToClock(Math.floor((end - start) / 60000))
}

function minutesToClock(value) {
  if (value === null || value === undefined || value === '') return ''
  const minutes = Number(value)
  if (!Number.isFinite(minutes) || minutes < 0) return ''
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

function badgeTone(value) {
  return {
    critical: 'status-danger',
    high: 'status-warning',
    medium: 'status-info',
    low: 'status-neutral',
    resolved: 'status-success',
    mitigated: 'status-info',
    war_room: 'status-danger',
    open: 'status-warning',
    in_progress: 'status-warning',
    cancelled: 'status-neutral',
  }[value] || 'status-neutral'
}

function IncidentBadge({ value }) {
  return <span className={`status-badge ${badgeTone(value)}`}>{labels[value] || value}</span>
}

function sourceLabel(value) {
  if (value === 'servicenow') return 'ServiceNow'
  if (value === 'teams') return 'Teams'
  return 'Outros'
}

function SummaryCard({ label, value, tone = 'text-white' }) {
  const metricTone = tone.includes('red') ? 'danger' : tone.includes('amber') ? 'warning' : tone.includes('emerald') ? 'success' : 'info'
  return <MetricCard detail="Indicador operacional filtrado" icon="alert" label={label} tone={metricTone} value={value} />
}

function minutesLabel(value) {
  if (value === null || value === undefined || value === '') return 'Não calculado'
  const minutes = Number(value)
  if (!Number.isFinite(minutes) || minutes < 0) return 'Sem dados'
  return minutesToClock(minutes)
}

function CalculatedTimeField({ label, value }) {
  return (
    <div className="label">
      {label}
      <div className="field mt-2 flex items-center text-slate-300" aria-live="polite">
        {value || '--:--'}
      </div>
    </div>
  )
}

function IncidentForm({ initial, onClose, onSave, submitting, canManage, sdkResponsibles }) {
  const editing = Boolean(initial?.id)
  const [form, setForm] = useState(normalizeForForm(initial))
  const update = (key, value) => setForm((current) => {
    const next = { ...current, [key]: value }
    if (['incident_opened_at', 'room_opened_at'].includes(key)) {
      next.room_opening_duration_minutes = minutesBetween(
        next.incident_opened_at,
        next.room_opened_at,
      )
    }
    if (['room_opened_at', 'normalized_at'].includes(key)) {
      next.room_duration_minutes = minutesBetween(next.room_opened_at, next.normalized_at)
    }
    return next
  })

  function submit(event) {
    event.preventDefault()
    onSave({
      ...form,
      id: initial?.id,
      action: editing ? 'update' : 'create',
      ticket_number: form.incident_number,
      opened_at: form.incident_opened_at || '',
      war_room_started_at: form.room_opened_at,
      mitigated_at: form.normalized_at,
      sdk_responsible_employee_id: form.sdk_responsible_employee_id || null,
      responsible_area: form.responsible_area,
      summary: form.room_description,
      resolution: form.room_finalization_description,
      actions_taken: form.sdk_activity,
      affected_users: form.affected_users === '' ? null : Number(form.affected_users),
    })
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/75 p-4 backdrop-blur-sm">
      <button aria-label="Fechar formulário" className="fixed inset-0" onClick={onClose} type="button" />
      <form className="card relative mx-auto my-4 w-full max-w-6xl space-y-6 border-red-500/20" onSubmit={submit}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="section-eyebrow">
              {editing ? `Registro #${initial.id}` : 'Novo registro'}
            </p>
            <h2 className="mt-2 text-xl font-bold text-white">
              {editing ? 'Editar chamado crítico' : 'Cadastrar chamado crítico'}
            </h2>
            <p className="mt-1 text-sm text-slate-500">Alterações ficam vinculadas ao usuário autenticado e registradas em auditoria.</p>
          </div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Cancelar</button>
        </div>

        <section className="rounded-card border border-white/5 bg-slate-950/20 p-4">
          <SectionHeader description="Dados principais do incidente e estado atual da sala crítica." eyebrow="Identificação" title="Identificação" />
          <div className="mt-5">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <label className="label">INCIDENTE *<input className="field mt-2" maxLength="100" required value={form.incident_number} onChange={(event) => update('incident_number', event.target.value)} /></label>
          <label className="label">Data da Sala *<input className="field mt-2" required type="date" value={form.room_date} onChange={(event) => update('room_date', event.target.value)} /></label>
          <label className="label">Origem<select className="field mt-2" value={sourceLabel(form.source) === 'Outros' ? 'other' : form.source} onChange={(event) => update('source', event.target.value)}>{sourceOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
          <label className="label">Criticidade<select className="field mt-2" value={form.severity} onChange={(event) => update('severity', event.target.value)}>{severityOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
          {canManage ? (
            <label className="label">Status<select className="field mt-2" value={form.status} onChange={(event) => update('status', event.target.value)}>{statusOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
          ) : (
            <div className="label">Status<div className="field mt-2 flex items-center text-slate-300">Aberto</div></div>
          )}
          </div>
          </div>
        </section>

        <section className="rounded-card border border-white/5 bg-slate-950/20 p-4">
          <SectionHeader description="Tempos são calculados no cliente apenas para orientar o preenchimento; o backend continua validando e persistindo." eyebrow="Horários" title="Linha do tempo" />
          <div className="mt-5">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="label">Hora de abertura Incidente<input className="field mt-2" type="datetime-local" value={form.incident_opened_at} onChange={(event) => update('incident_opened_at', event.target.value)} /></label>
            <label className="label">Hora report da operação<input className="field mt-2" type="datetime-local" value={form.operation_reported_at} onChange={(event) => update('operation_reported_at', event.target.value)} /></label>
            <label className="label">Hora abertura sala<input className="field mt-2" type="datetime-local" value={form.room_opened_at} onChange={(event) => update('room_opened_at', event.target.value)} /></label>
            <label className="label">Hora de normalização<input className="field mt-2" type="datetime-local" value={form.normalized_at} onChange={(event) => update('normalized_at', event.target.value)} /></label>
            <CalculatedTimeField label="Tempo de abertura da sala" value={form.room_opening_duration_minutes} />
            <CalculatedTimeField label="Tempo de Sala" value={form.room_duration_minutes} />
          </div>
          </div>
        </section>

        <section className="rounded-card border border-white/5 bg-slate-950/20 p-4">
          <SectionHeader description="Impacto, sala, responsáveis e documentação operacional da tratativa." eyebrow="Impacto e tratativa" title="Detalhes operacionais" />
          <div className="mt-5">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="label md:col-span-2">Descrição da sala<textarea className="field mt-2 min-h-24" maxLength="4000" value={form.room_description} onChange={(event) => update('room_description', event.target.value)} /></label>
            <label className="label md:col-span-2">Descrição da finalização da sala<textarea className="field mt-2 min-h-24" maxLength="4000" value={form.room_finalization_description} onChange={(event) => update('room_finalization_description', event.target.value)} /></label>
            <label className="label">Carteira - CC<input className="field mt-2" maxLength="160" value={form.sector} onChange={(event) => update('sector', event.target.value)} /></label>
            <label className="label">Técnico SDK responsável<select className="field mt-2" value={form.sdk_responsible_employee_id} onChange={(event) => update('sdk_responsible_employee_id', event.target.value)}><option value="">Não definido</option>{sdkResponsibles.map((item) => <option key={item.id} value={item.id}>{item.name}{item.ad_login ? ` (${item.ad_login})` : ''}</option>)}</select></label>
            <label className="label">Área responsável<input className="field mt-2" maxLength="120" placeholder="Telecom, SRE - Netsec, DBA" value={form.responsible_area} onChange={(event) => update('responsible_area', event.target.value)} /></label>
            <label className="label">Usuários afetados<input className="field mt-2" min="0" type="number" value={form.affected_users} onChange={(event) => update('affected_users', event.target.value)} /></label>
            <label className="label">Link da sala<input className="field mt-2" maxLength="1000" placeholder="https://..." type="url" value={form.meeting_url} onChange={(event) => update('meeting_url', event.target.value)} /></label>
            <label className="label md:col-span-2">Observação<textarea className="field mt-2 min-h-24" maxLength="12000" value={form.notes} onChange={(event) => update('notes', event.target.value)} /></label>
            <label className="label md:col-span-2">Atividade SDK<textarea className="field mt-2 min-h-24" maxLength="12000" value={form.sdk_activity} onChange={(event) => update('sdk_activity', event.target.value)} /></label>
          </div>
          </div>
        </section>

        <div className="flex justify-end gap-3">
          <button className="btn-secondary" disabled={submitting} onClick={onClose} type="button">Cancelar</button>
          <button className="btn-primary" disabled={submitting} type="submit">{submitting ? 'Salvando...' : editing ? 'Salvar alterações' : 'Cadastrar chamado'}</button>
        </div>
      </form>
    </div>
  )
}

function IncidentDetails({ item, onClose }) {
  const fields = [
    ['Descrição da sala', item.room_description || item.summary],
    ['Descrição da finalização da sala', item.room_finalization_description],
    ['Atividade SDK', item.sdk_activity],
    ['Observações', item.notes],
  ]
  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/75 p-4 backdrop-blur-sm">
      <button aria-label="Fechar detalhes" className="fixed inset-0" onClick={onClose} type="button" />
      <article className="card relative mx-auto my-6 max-w-4xl border-red-500/20">
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="flex flex-wrap gap-2"><IncidentBadge value={item.severity} /><IncidentBadge value={item.status} /></div>
            <h2 className="mt-4 text-xl font-bold text-white">{item.incident_number || item.ticket_number}</h2>
            <p className="mt-2 text-sm text-slate-500">Sala em {item.room_date} / origem {sourceLabel(item.source)} / incidente aberto em {formatDateTime(item.incident_opened_at || item.opened_at)}</p>
          </div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Fechar</button>
        </div>
        <div className="mt-6 grid gap-4 sm:grid-cols-2">
          <DetailPill label="Carteira - CC" value={item.sector || 'Não informado'} />
          <DetailPill label="Técnico SDK" value={item.sdk_responsible_name || 'Não definido'} tone="info" />
          <DetailPill label="Área responsável" value={item.responsible_area || 'Não informado'} />
          <DetailPill label="Tempo para abrir sala" value={minutesLabel(item.room_opening_duration_minutes)} tone="warning" />
          <DetailPill label="Tempo de sala" value={minutesLabel(item.room_duration_minutes)} tone="warning" />
          {fields.map(([label, value]) => value && <div className="rounded-xl border border-white/5 bg-slate-950/30 p-4 sm:col-span-2" key={label}><small className="text-slate-600">{label}</small><p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">{value}</p></div>)}
        </div>
        {item.meeting_url && <a className="btn-secondary mt-5" href={item.meeting_url} rel="noreferrer" target="_blank">Abrir sala de crise</a>}
      </article>
    </div>
  )
}

function ImportPanel({ onClose, onImported }) {
  const inputRef = useRef(null)
  const [rows, setRows] = useState([])
  const [preview, setPreview] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [dragging, setDragging] = useState(false)

  async function choose(files) {
    const file = files?.[0]
    setRows([])
    setPreview(null)
    setError('')
    if (!file) return
    if (!CRITICAL_INCIDENT_IMPORT_LIMITS.acceptedExtensions.some((extension) => file.name.toLowerCase().endsWith(extension))) {
      setError('Use CSV ou XLSX. XLS legado deve ser convertido para XLSX.')
      return
    }
    if (file.size > CRITICAL_INCIDENT_IMPORT_LIMITS.maxFileBytes) {
      setError('A planilha excede o limite de 2 MB.')
      return
    }
    setLoading(true)
    try {
      const body = new FormData()
      body.append('spreadsheet', file)
      const result = await postForm('portal/critical_incidents_import.php', body)
      setRows(result.parsed_rows)
      setPreview(result)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }

  async function confirm() {
    setLoading(true)
    setError('')
    try {
      const result = await post('portal/critical_incidents_import.php', { action: 'confirm', rows })
      setRows([])
      setPreview(null)
      if (inputRef.current) inputRef.current.value = ''
      await onImported(importFeedback(result))
      onClose()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/75 backdrop-blur-sm">
      <button aria-label="Fechar importação" className="absolute inset-0" onClick={onClose} type="button" />
      <aside className="relative h-full w-full max-w-2xl overflow-y-auto border-l border-white/10 bg-slate-950 p-6">
        <div className="flex items-start justify-between gap-4">
          <div><p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-400">Preview obrigatório</p><h2 className="mt-2 text-xl font-bold text-white">Importar planilha War Room</h2></div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Fechar</button>
        </div>
        <p className="mt-3 text-sm leading-6 text-slate-500">CSV ou XLSX com cabeçalhos da planilha War Room, como INCIDENTE e Data da Sala. Campos incompletos podem ser ajustados depois. Limite de 500 linhas e 2 MB.</p>
        <div className="mt-4 flex flex-wrap gap-2">
          <FileTypeBadge extension="csv" />
          <FileTypeBadge extension="xlsx" />
        </div>
        <button
          className={`mt-6 grid w-full place-items-center rounded-2xl border border-dashed px-5 py-10 ${dragging ? 'border-blue-400 bg-blue-500/10' : 'border-white/15 bg-slate-900/50'}`}
          onClick={() => inputRef.current?.click()}
          onDragEnter={(event) => { event.preventDefault(); setDragging(true) }}
          onDragLeave={(event) => { event.preventDefault(); setDragging(false) }}
          onDragOver={(event) => event.preventDefault()}
          onDrop={(event) => { event.preventDefault(); setDragging(false); choose(event.dataTransfer.files) }}
          type="button"
        >
          <Icon className="h-8 w-8 text-blue-400" name="upload" />
          <strong className="mt-3 text-sm text-slate-200">Clique ou arraste CSV/XLSX</strong>
        </button>
        <input ref={inputRef} accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" className="hidden" onChange={(event) => choose(event.target.files)} type="file" />
        {loading && <InlineAlert title="Processando arquivo">Validando planilha antes da confirmação.</InlineAlert>}
        {error && <InlineAlert tone="danger" title="Falha na importação">{error}</InlineAlert>}
        {preview && (
          <div className="mt-5 space-y-4">
            <p className="text-sm text-slate-300">{preview.valid_count} válida(s), {preview.invalid_count} inválida(s).</p>
            <div className="max-h-80 overflow-auto rounded-xl border border-white/5">
              {preview.rows.map((row) => (
                <div className="border-b border-white/5 px-3 py-3 text-sm" key={row.line}>
                  <span className={row.valid ? 'text-emerald-300' : 'text-red-300'}>Linha {row.line}</span>
                  <p className="mt-1 text-slate-400">{row.ticket_number || 'Sem chamado'} / {row.title || 'Sem título'}</p>
                  {row.errors.length > 0 && <p className="mt-1 text-xs text-red-300">{row.errors.join(' ')}</p>}
                </div>
              ))}
            </div>
            <button className="btn-primary w-full" disabled={loading || preview.invalid_count > 0 || rows.length === 0} onClick={confirm} type="button">Confirmar importação</button>
          </div>
        )}
      </aside>
    </div>
  )
}

export function CriticalIncidentsPage({ session, notify }) {
  const canManage = session.role === 'admin' || session.role === 'gestor'
  const canCreate = session.role !== 'somente_leitura'
  const [filters, setFilters] = useState({
    competency: competency.key,
    from: '',
    to: '',
    status: '',
    severity: '',
    source: '',
    responsible_area: '',
    team: '',
    ticket_number: '',
    search: '',
    owner: '',
  })
  const resource = useResource(`portal/critical_incidents.php${queryString(filters)}`)
  const responsibleResource = useResource('portal/sdk_responsibles.php')
  const [formItem, setFormItem] = useState(null)
  const [viewItem, setViewItem] = useState(null)
  const [importOpen, setImportOpen] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [loadingItemId, setLoadingItemId] = useState(null)
  const summary = resource.data?.summary || {}
  const sdkResponsibles = responsibleResource.data?.items || []
  const exportUrl = apiUrl(`portal/critical_incidents_export.php${queryString(filters)}`)
  const topAreas = useMemo(() => summary.top_areas || [], [summary.top_areas])

  async function save(data) {
    setSubmitting(true)
    try {
      await post('portal/critical_incidents.php', data)
      notify(data.action === 'update' ? ACTION_FEEDBACK.criticalUpdated : ACTION_FEEDBACK.criticalCreated)
      setFormItem(null)
      await resource.refresh()
    } catch (error) {
      notify(error.message, 'error')
    } finally {
      setSubmitting(false)
    }
  }

  async function changeStatus(id, status) {
    try {
      await post('portal/critical_incidents.php', { action: 'status', id, status })
      notify(ACTION_FEEDBACK.criticalStatusUpdated)
      await resource.refresh()
    } catch (error) {
      notify(error.message, 'error')
    }
  }

  async function openItem(id, mode) {
    setLoadingItemId(id)
    try {
      const result = await api(`portal/critical_incidents.php?id=${encodeURIComponent(id)}`)
      if (mode === 'edit') setFormItem(result.item)
      else setViewItem(result.item)
    } catch (error) {
      notify(error.message, 'error')
    } finally {
      setLoadingItemId(null)
    }
  }

  if (resource.loading) return <LoadingState label="Carregando chamados críticos" />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />
  const items = resource.data?.items || []

  return (
    <div className="space-y-5">
      <section className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-red-400">War room e sala de crise</p>
          <h2 className="mt-2 text-2xl font-black text-white">Chamados críticos</h2>
          <p className="mt-2 text-sm text-slate-500">Registro central de impacto, resposta, mitigação, causa raiz e resolução.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <a className="btn-secondary gap-2" href={exportUrl}><Icon className="h-4 w-4" name="download" /> Exportar planilha CSV</a>
          {canManage && <button className="btn-secondary gap-2" onClick={() => setImportOpen(true)} type="button"><Icon className="h-4 w-4" name="upload" /> Importar planilha</button>}
          {canCreate && <button className="btn-primary" onClick={() => setFormItem({})} type="button">Novo chamado</button>}
        </div>
      </section>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard label="Críticos abertos" tone="text-red-300" value={summary.open_count || 0} />
        <SummaryCard label="Em war room" tone="text-amber-300" value={summary.war_room_count || 0} />
        <SummaryCard label="Resolvidos no período" tone="text-emerald-300" value={summary.resolved_count || 0} />
        <SummaryCard label="Alta / crítica" value={summary.high_critical_count || 0} />
        <SummaryCard label="Tempo médio para mitigar" value={minutesLabel(summary.avg_mitigation_minutes)} />
        <SummaryCard label="Tempo médio para resolver" value={minutesLabel(summary.avg_resolution_minutes)} />
        <SummaryCard label="Sem causa raiz" tone="text-amber-300" value={summary.missing_root_cause_count || 0} />
        <SummaryCard label="Principal área responsável" value={topAreas[0]?.responsible_area || 'Sem dados'} />
      </section>

      <FilterBar>
        <label className="label">Competência 16-15<input className="field mt-2" type="month" value={filters.competency} onChange={(event) => setFilters({ ...filters, competency: event.target.value, from: '', to: '' })} /></label>
        <label className="label">Início<input className="field mt-2" type="date" value={filters.from} onChange={(event) => setFilters({ ...filters, competency: '', from: event.target.value })} /></label>
        <label className="label">Fim<input className="field mt-2" min={filters.from} type="date" value={filters.to} onChange={(event) => setFilters({ ...filters, competency: '', to: event.target.value })} /></label>
        <label className="label">Status<select className="field mt-2" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">Todos</option>{statusOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
        <label className="label">Criticidade<select className="field mt-2" value={filters.severity} onChange={(event) => setFilters({ ...filters, severity: event.target.value })}><option value="">Todas</option>{severityOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
        <label className="label">Origem<select className="field mt-2" value={filters.source} onChange={(event) => setFilters({ ...filters, source: event.target.value })}><option value="">Todas</option>{sourceOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
        <label className="label">Chamado<input className="field mt-2" maxLength="100" value={filters.ticket_number} onChange={(event) => setFilters({ ...filters, ticket_number: event.target.value })} /></label>
        <label className="label">Área responsável<input className="field mt-2" maxLength="120" value={filters.responsible_area} onChange={(event) => setFilters({ ...filters, responsible_area: event.target.value })} /></label>
        <label className="label">Equipe<input className="field mt-2" maxLength="120" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })} /></label>
        <label className="label">Responsável legado<input className="field mt-2" maxLength="120" value={filters.owner} onChange={(event) => setFilters({ ...filters, owner: event.target.value })} /></label>
        <label className="label md:col-span-2">Texto livre<input className="field mt-2" maxLength="120" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} /></label>
      </FilterBar>

      {items.length === 0 ? <EmptyState title="Nenhum chamado crítico encontrado" description="Ajuste os filtros ou cadastre o primeiro registro do período." /> : (
        <section className="card table-wrap">
          <SectionHeader
            description="Priorize chamados críticos abertos, war rooms ativas e registros sem causa raiz."
            meta={<span className="status-badge status-neutral">{items.length} registro(s)</span>}
            title="Incidentes e salas críticas"
          />
          <div className="mt-5">
          <table className="data-table min-w-[1380px]">
            <thead><tr><th>INCIDENTE</th><th>Data da sala</th><th>Descrição</th><th>Tempos</th><th>Criticidade</th><th>Status</th><th>Carteira - CC</th><th>Técnico SDK</th><th>Ações</th></tr></thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td><strong>{item.incident_number || item.ticket_number}</strong><small>{sourceLabel(item.source)}</small></td>
                  <td>{item.room_date || String(item.opened_at).slice(0, 10)}</td>
                  <td><strong>{item.room_description || item.title}</strong><small>{item.sdk_activity || 'Sem atividade SDK'}</small></td>
                  <td><strong>Abertura: {minutesLabel(item.room_opening_duration_minutes)}</strong><small>Sala: {minutesLabel(item.room_duration_minutes)}</small></td>
                  <td><IncidentBadge value={item.severity} /></td>
                  <td><IncidentBadge value={item.status} /></td>
                  <td><strong>{item.sector || 'Não informado'}</strong><small>{item.responsible_area || 'Sem área responsável'}</small></td>
                  <td><strong>{item.sdk_responsible_name || 'Não definido'}</strong><small>{item.sdk_responsible_login || 'Sem login'}</small></td>
                  <td>
                    <div className="flex flex-wrap gap-1">
                      <button className="table-action" disabled={loadingItemId === item.id} onClick={() => openItem(item.id, 'view')} type="button">{loadingItemId === item.id ? 'Carregando' : 'Visualizar'}</button>
                      {canManage && <button className="table-action" disabled={loadingItemId === item.id} onClick={() => openItem(item.id, 'edit')} type="button">Editar</button>}
                      {canManage && !['mitigated', 'resolved', 'cancelled'].includes(item.status) && <button className="table-action text-blue-300" onClick={() => changeStatus(item.id, 'mitigated')} type="button">Mitigar</button>}
                      {canManage && item.status !== 'resolved' && item.status !== 'cancelled' && <button className="table-action text-emerald-300" onClick={() => changeStatus(item.id, 'resolved')} type="button">Resolver</button>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </section>
      )}

      {formItem && <IncidentForm canManage={canManage} initial={formItem.id ? formItem : null} onClose={() => setFormItem(null)} onSave={save} sdkResponsibles={sdkResponsibles} submitting={submitting} />}
      {viewItem && <IncidentDetails item={viewItem} onClose={() => setViewItem(null)} />}
      {importOpen && <ImportPanel onClose={() => setImportOpen(false)} onImported={async (message) => { notify(message); await resource.refresh() }} />}
    </div>
  )
}
