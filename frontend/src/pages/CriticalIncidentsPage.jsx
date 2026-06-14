import { useMemo, useRef, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { useResource } from '../hooks/useResource'
import { api, apiUrl, post } from '../lib/api'
import { formatDateTime } from '../lib/format'
import {
  competencyFor,
  CRITICAL_INCIDENT_IMPORT_LIMITS,
  parseCriticalIncidentCsv,
  queryString,
} from '../lib/operational'

const competency = competencyFor()
const severityOptions = ['low', 'medium', 'high', 'critical']
const statusOptions = ['open', 'in_progress', 'war_room', 'mitigated', 'resolved', 'cancelled']
const sourceOptions = ['servicenow', 'jira', 'teams', 'manual', 'other']

function localDateTime(date = new Date()) {
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

const emptyIncident = {
  ticket_number: '',
  source: 'servicenow',
  title: '',
  summary: '',
  severity: 'high',
  status: 'open',
  opened_at: localDateTime(),
  war_room_started_at: '',
  mitigated_at: '',
  resolved_at: '',
  impact: '',
  affected_users: '',
  affected_services: '',
  responsible_area: '',
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
  medium: 'Media',
  high: 'Alta',
  critical: 'Critica',
  open: 'Aberto',
  in_progress: 'Em andamento',
  war_room: 'Em war room',
  mitigated: 'Mitigado',
  resolved: 'Resolvido',
  cancelled: 'Cancelado',
}

function normalizeForForm(item) {
  const form = { ...emptyIncident, ...item }
  ;['opened_at', 'war_room_started_at', 'mitigated_at', 'resolved_at'].forEach((key) => {
    form[key] = item?.[key] ? String(item[key]).replace(' ', 'T').slice(0, 16) : ''
  })
  form.affected_users = item?.affected_users ?? ''
  return form
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

function SummaryCard({ label, value, tone = 'text-white' }) {
  return (
    <article className="card">
      <p className="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">{label}</p>
      <p className={`mt-3 text-2xl font-black ${tone}`}>{value}</p>
    </article>
  )
}

function minutesLabel(value) {
  const minutes = Number(value)
  if (!Number.isFinite(minutes) || minutes < 0) return 'Sem dados'
  if (minutes < 60) return `${minutes} min`
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}min`
}

function IncidentForm({ initial, onClose, onSave, submitting }) {
  const editing = Boolean(initial?.id)
  const [form, setForm] = useState(normalizeForForm(initial))
  const update = (key, value) => setForm((current) => ({ ...current, [key]: value }))

  function submit(event) {
    event.preventDefault()
    onSave({
      ...form,
      id: initial?.id,
      action: editing ? 'update' : 'create',
      affected_users: form.affected_users === '' ? null : Number(form.affected_users),
    })
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/75 p-4 backdrop-blur-sm">
      <button aria-label="Fechar formulario" className="fixed inset-0" onClick={onClose} type="button" />
      <form className="card relative mx-auto my-4 w-full max-w-6xl space-y-6 border-blue-500/20" onSubmit={submit}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-400">
              {editing ? `Registro #${initial.id}` : 'Novo registro'}
            </p>
            <h2 className="mt-2 text-xl font-bold text-white">
              {editing ? 'Editar chamado critico' : 'Cadastrar chamado critico'}
            </h2>
            <p className="mt-1 text-sm text-slate-500">Alteracoes ficam vinculadas ao usuario autenticado e registradas em auditoria.</p>
          </div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Cancelar</button>
        </div>

        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <label className="label">Numero do chamado<input className="field mt-2" maxLength="100" required value={form.ticket_number} onChange={(event) => update('ticket_number', event.target.value)} /></label>
          <label className="label">Origem<select className="field mt-2" value={form.source} onChange={(event) => update('source', event.target.value)}>{sourceOptions.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
          <label className="label">Criticidade<select className="field mt-2" value={form.severity} onChange={(event) => update('severity', event.target.value)}>{severityOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
          <label className="label">Status<select className="field mt-2" value={form.status} onChange={(event) => update('status', event.target.value)}>{statusOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
          <label className="label md:col-span-2 xl:col-span-4">Tema / titulo<input className="field mt-2" maxLength="180" required value={form.title} onChange={(event) => update('title', event.target.value)} /></label>
          <label className="label md:col-span-2 xl:col-span-4">Resumo<textarea className="field mt-2 min-h-24" maxLength="4000" value={form.summary} onChange={(event) => update('summary', event.target.value)} /></label>
        </section>

        <section>
          <h3 className="mb-3 text-sm font-bold uppercase tracking-[0.14em] text-slate-500">Linha do tempo</h3>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="label">Abertura<input className="field mt-2" required type="datetime-local" value={form.opened_at} onChange={(event) => update('opened_at', event.target.value)} /></label>
            <label className="label">Inicio da war room<input className="field mt-2" type="datetime-local" value={form.war_room_started_at} onChange={(event) => update('war_room_started_at', event.target.value)} /></label>
            <label className="label">Mitigacao<input className="field mt-2" type="datetime-local" value={form.mitigated_at} onChange={(event) => update('mitigated_at', event.target.value)} /></label>
            <label className="label">Resolucao<input className="field mt-2" type="datetime-local" value={form.resolved_at} onChange={(event) => update('resolved_at', event.target.value)} /></label>
          </div>
        </section>

        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <label className="label md:col-span-2">Area responsavel<input className="field mt-2" maxLength="120" value={form.responsible_area} onChange={(event) => update('responsible_area', event.target.value)} /></label>
          <label className="label">Responsavel principal<input className="field mt-2" maxLength="160" value={form.owner_name} onChange={(event) => update('owner_name', event.target.value)} /></label>
          <label className="label">Login do responsavel<input className="field mt-2" maxLength="100" value={form.owner_login} onChange={(event) => update('owner_login', event.target.value)} /></label>
          <label className="label md:col-span-2">Equipes envolvidas<input className="field mt-2" maxLength="500" placeholder="N1, Infra, Redes, Seguranca" value={form.involved_teams} onChange={(event) => update('involved_teams', event.target.value)} /></label>
          <label className="label">Usuarios afetados<input className="field mt-2" min="0" type="number" value={form.affected_users} onChange={(event) => update('affected_users', event.target.value)} /></label>
          <label className="label">Link da sala<input className="field mt-2" maxLength="1000" placeholder="https://..." type="url" value={form.meeting_url} onChange={(event) => update('meeting_url', event.target.value)} /></label>
          <label className="label md:col-span-2">Servicos afetados<textarea className="field mt-2 min-h-20" maxLength="2000" value={form.affected_services} onChange={(event) => update('affected_services', event.target.value)} /></label>
          <label className="label md:col-span-2">Impacto<textarea className="field mt-2 min-h-20" maxLength="4000" value={form.impact} onChange={(event) => update('impact', event.target.value)} /></label>
        </section>

        <section className="grid gap-4 md:grid-cols-2">
          {[
            ['root_cause', 'Causa raiz', 4000],
            ['resolution', 'Resolucao aplicada', 4000],
            ['workaround', 'Workaround', 4000],
            ['actions_taken', 'Acoes realizadas', 12000],
            ['next_steps', 'Proximos passos', 4000],
            ['participants', 'Participantes', 4000],
            ['notes', 'Observacoes', 12000],
          ].map(([key, label, max]) => (
            <label className={`label ${key === 'notes' ? 'md:col-span-2' : ''}`} key={key}>
              {label}<textarea className="field mt-2 min-h-24" maxLength={max} value={form[key]} onChange={(event) => update(key, event.target.value)} />
            </label>
          ))}
        </section>

        <div className="flex justify-end gap-3">
          <button className="btn-secondary" disabled={submitting} onClick={onClose} type="button">Cancelar</button>
          <button className="btn-primary" disabled={submitting} type="submit">{submitting ? 'Salvando...' : editing ? 'Salvar alteracoes' : 'Cadastrar chamado'}</button>
        </div>
      </form>
    </div>
  )
}

function IncidentDetails({ item, onClose }) {
  const fields = [
    ['Resumo', item.summary],
    ['Impacto', item.impact],
    ['Servicos afetados', item.affected_services],
    ['Equipes envolvidas', item.involved_teams],
    ['Causa raiz', item.root_cause],
    ['Resolucao', item.resolution],
    ['Workaround', item.workaround],
    ['Acoes realizadas', item.actions_taken],
    ['Proximos passos', item.next_steps],
    ['Participantes', item.participants],
    ['Observacoes', item.notes],
  ]
  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/75 p-4 backdrop-blur-sm">
      <button aria-label="Fechar detalhes" className="fixed inset-0" onClick={onClose} type="button" />
      <article className="card relative mx-auto my-6 max-w-4xl border-blue-500/20">
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="flex flex-wrap gap-2"><IncidentBadge value={item.severity} /><IncidentBadge value={item.status} /></div>
            <h2 className="mt-4 text-xl font-bold text-white">{item.ticket_number} - {item.title}</h2>
            <p className="mt-2 text-sm text-slate-500">{item.source} / aberto em {formatDateTime(item.opened_at)}</p>
          </div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Fechar</button>
        </div>
        <div className="mt-6 grid gap-4 sm:grid-cols-2">
          <div className="rounded-xl border border-white/5 bg-slate-950/30 p-4"><small className="text-slate-600">Area responsavel</small><p className="mt-1 text-sm text-slate-200">{item.responsible_area || 'Nao informada'}</p></div>
          <div className="rounded-xl border border-white/5 bg-slate-950/30 p-4"><small className="text-slate-600">Responsavel</small><p className="mt-1 text-sm text-slate-200">{item.owner_name || 'Nao informado'} {item.owner_login ? `(${item.owner_login})` : ''}</p></div>
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
    if (!file.name.toLowerCase().endsWith('.csv')) {
      setError('Use um arquivo CSV nesta etapa.')
      return
    }
    if (file.size > CRITICAL_INCIDENT_IMPORT_LIMITS.maxFileBytes) {
      setError('O CSV excede o limite de 1 MB.')
      return
    }
    setLoading(true)
    try {
      const parsed = parseCriticalIncidentCsv(await file.text())
      const result = await post('portal/critical_incidents_import.php', { action: 'preview', rows: parsed })
      setRows(parsed)
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
      await onImported(result.mensagem)
      onClose()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/75 backdrop-blur-sm">
      <button aria-label="Fechar importacao" className="absolute inset-0" onClick={onClose} type="button" />
      <aside className="relative h-full w-full max-w-2xl overflow-y-auto border-l border-white/10 bg-slate-950 p-6">
        <div className="flex items-start justify-between gap-4">
          <div><p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-400">Preview obrigatorio</p><h2 className="mt-2 text-xl font-bold text-white">Importar chamados por CSV</h2></div>
          <button className="text-sm text-slate-400 hover:text-white" onClick={onClose} type="button">Fechar</button>
        </div>
        <p className="mt-3 text-sm leading-6 text-slate-500">Campos obrigatorios: ticket_number, source, title, severity, status e opened_at. Limite de 500 linhas e 1 MB.</p>
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
          <strong className="mt-3 text-sm text-slate-200">Clique ou arraste o CSV</strong>
        </button>
        <input ref={inputRef} accept=".csv,text/csv" className="hidden" onChange={(event) => choose(event.target.files)} type="file" />
        {loading && <p className="mt-4 text-sm text-blue-300">Validando arquivo...</p>}
        {error && <p className="mt-4 rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-200" role="alert">{error}</p>}
        {preview && (
          <div className="mt-5 space-y-4">
            <p className="text-sm text-slate-300">{preview.valid_count} valida(s), {preview.invalid_count} invalida(s).</p>
            <div className="max-h-80 overflow-auto rounded-xl border border-white/5">
              {preview.rows.map((row) => (
                <div className="border-b border-white/5 px-3 py-3 text-sm" key={row.line}>
                  <span className={row.valid ? 'text-emerald-300' : 'text-red-300'}>Linha {row.line}</span>
                  <p className="mt-1 text-slate-400">{row.ticket_number || 'Sem chamado'} / {row.title || 'Sem titulo'}</p>
                  {row.errors.length > 0 && <p className="mt-1 text-xs text-red-300">{row.errors.join(' ')}</p>}
                </div>
              ))}
            </div>
            <button className="btn-primary w-full" disabled={loading || preview.invalid_count > 0 || rows.length === 0} onClick={confirm} type="button">Confirmar importacao</button>
          </div>
        )}
      </aside>
    </div>
  )
}

export function CriticalIncidentsPage({ session, notify }) {
  const canManage = session.role === 'admin' || session.role === 'gestor'
  const [filters, setFilters] = useState({
    competency: competency.key,
    from: '',
    to: '',
    status: '',
    severity: '',
    responsible_area: '',
    team: '',
    ticket_number: '',
    search: '',
    owner: '',
  })
  const resource = useResource(`portal/critical_incidents.php${queryString(filters)}`)
  const [formItem, setFormItem] = useState(null)
  const [viewItem, setViewItem] = useState(null)
  const [importOpen, setImportOpen] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [loadingItemId, setLoadingItemId] = useState(null)
  const summary = resource.data?.summary || {}
  const exportUrl = apiUrl(`portal/critical_incidents_export.php${queryString(filters)}`)
  const topAreas = useMemo(() => summary.top_areas || [], [summary.top_areas])

  async function save(data) {
    setSubmitting(true)
    try {
      const result = await post('portal/critical_incidents.php', data)
      notify(result.mensagem)
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
      const result = await post('portal/critical_incidents.php', { action: 'status', id, status })
      notify(result.mensagem)
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

  if (resource.loading) return <LoadingState label="Carregando chamados criticos" />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />
  const items = resource.data?.items || []

  return (
    <div className="space-y-5">
      <section className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-red-400">War room e sala de crise</p>
          <h2 className="mt-2 text-2xl font-black text-white">Chamados criticos</h2>
          <p className="mt-2 text-sm text-slate-500">Registro central de impacto, resposta, mitigacao, causa raiz e resolucao.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <a className="btn-secondary gap-2" href={exportUrl}><Icon className="h-4 w-4" name="download" /> Exportar CSV</a>
          {canManage && <button className="btn-secondary gap-2" onClick={() => setImportOpen(true)} type="button"><Icon className="h-4 w-4" name="upload" /> Importar planilha</button>}
          {canManage && <button className="btn-primary" onClick={() => setFormItem({})} type="button">Novo chamado</button>}
        </div>
      </section>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard label="Criticos abertos" tone="text-red-300" value={summary.open_count || 0} />
        <SummaryCard label="Em war room" tone="text-amber-300" value={summary.war_room_count || 0} />
        <SummaryCard label="Resolvidos no periodo" tone="text-emerald-300" value={summary.resolved_count || 0} />
        <SummaryCard label="Alta / critica" value={summary.high_critical_count || 0} />
        <SummaryCard label="Tempo medio para mitigar" value={minutesLabel(summary.avg_mitigation_minutes)} />
        <SummaryCard label="Tempo medio para resolver" value={minutesLabel(summary.avg_resolution_minutes)} />
        <SummaryCard label="Sem causa raiz" tone="text-amber-300" value={summary.missing_root_cause_count || 0} />
        <SummaryCard label="Principal area" value={topAreas[0]?.responsible_area || 'Sem dados'} />
      </section>

      <section className="card grid gap-3 md:grid-cols-2 xl:grid-cols-5">
        <label className="label">Competencia 16-15<input className="field mt-2" type="month" value={filters.competency} onChange={(event) => setFilters({ ...filters, competency: event.target.value, from: '', to: '' })} /></label>
        <label className="label">Inicio<input className="field mt-2" type="date" value={filters.from} onChange={(event) => setFilters({ ...filters, competency: '', from: event.target.value })} /></label>
        <label className="label">Fim<input className="field mt-2" min={filters.from} type="date" value={filters.to} onChange={(event) => setFilters({ ...filters, competency: '', to: event.target.value })} /></label>
        <label className="label">Status<select className="field mt-2" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">Todos</option>{statusOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
        <label className="label">Criticidade<select className="field mt-2" value={filters.severity} onChange={(event) => setFilters({ ...filters, severity: event.target.value })}><option value="">Todas</option>{severityOptions.map((item) => <option key={item} value={item}>{labels[item]}</option>)}</select></label>
        <label className="label">Chamado<input className="field mt-2" maxLength="100" value={filters.ticket_number} onChange={(event) => setFilters({ ...filters, ticket_number: event.target.value })} /></label>
        <label className="label">Area<input className="field mt-2" maxLength="120" value={filters.responsible_area} onChange={(event) => setFilters({ ...filters, responsible_area: event.target.value })} /></label>
        <label className="label">Equipe<input className="field mt-2" maxLength="120" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })} /></label>
        <label className="label">Responsavel<input className="field mt-2" maxLength="120" value={filters.owner} onChange={(event) => setFilters({ ...filters, owner: event.target.value })} /></label>
        <label className="label md:col-span-2">Texto livre<input className="field mt-2" maxLength="120" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} /></label>
      </section>

      {items.length === 0 ? <EmptyState title="Nenhum chamado critico encontrado" description="Ajuste os filtros ou cadastre o primeiro registro do periodo." /> : (
        <section className="card table-wrap">
          <table className="data-table min-w-[1100px]">
            <thead><tr><th>Chamado</th><th>Tema</th><th>Abertura</th><th>Criticidade</th><th>Status</th><th>Responsavel</th><th>Acoes</th></tr></thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td><strong>{item.ticket_number}</strong><small>{item.source}</small></td>
                  <td><strong>{item.title}</strong><small>{item.responsible_area || 'Area nao informada'}</small></td>
                  <td>{formatDateTime(item.opened_at)}</td>
                  <td><IncidentBadge value={item.severity} /></td>
                  <td><IncidentBadge value={item.status} /></td>
                  <td>{item.owner_name || 'Nao informado'}<small>{item.involved_teams || 'Sem equipes'}</small></td>
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
        </section>
      )}

      {formItem && <IncidentForm initial={formItem.id ? formItem : null} onClose={() => setFormItem(null)} onSave={save} submitting={submitting} />}
      {viewItem && <IncidentDetails item={viewItem} onClose={() => setViewItem(null)} />}
      {importOpen && <ImportPanel onClose={() => setImportOpen(false)} onImported={async (message) => { notify(message); await resource.refresh() }} />}
    </div>
  )
}
