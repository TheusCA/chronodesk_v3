import { useMemo, useRef, useState } from 'react'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { useResource } from '../hooks/useResource'
import { apiUrl, post, postForm } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'
import {
  DetailPill,
  FilterBar,
  FormSection,
  InlineAlert,
  MetricCard,
  SectionHeader,
  UserAvatar,
} from '../components/ui/Primitives'
import {
  competencyFor,
  IMPORT_LIMITS,
  localDate,
  minutesLabel,
  queryString,
} from '../lib/operational'

const today = localDate()
const currentCompetency = competencyFor()
const SCHEDULE_RULE_OPTIONS = [
  { value: 'even_days', label: 'Dias pares' },
  { value: 'odd_days', label: 'Dias ímpares' },
  { value: 'always_onsite', label: 'Sempre presencial' },
  { value: 'always_remote', label: 'Sempre remoto' },
  { value: 'undefined', label: 'Sem escala definida' },
]
const SCHEDULE_RULE_VALUES = new Set(SCHEDULE_RULE_OPTIONS.map((option) => option.value))

function Status({ value }) {
  const tone = {
    approved: 'status-success',
    active: 'status-success',
    synced: 'status-success',
    onsite: 'status-success',
    pending: 'status-warning',
    remote: 'status-info',
    rejected: 'status-danger',
    sync_error: 'status-danger',
    cancelled: 'status-neutral',
  }[value] || 'status-neutral'
  return <span className={`status-badge ${tone}`}>{statusLabel(value)}</span>
}

function statusLabel(value) {
  return {
    approved: 'Aprovado',
    active: 'Ativo',
    synced: 'Sincronizado',
    onsite: 'Presencial',
    pending: 'Pendente',
    remote: 'Remoto',
    rejected: 'Rejeitado',
    sync_error: 'Erro de sincronização',
    cancelled: 'Cancelado',
    day_off: 'Folga',
    absence: 'Ausência',
  }[value] || String(value || 'Não informado').replaceAll('_', ' ')
}

function adjustmentTypeLabel(value) {
  return {
    entry: 'Entrada',
    lunch_out: 'Saída para almoço',
    lunch_return: 'Retorno do almoço',
    exit: 'Saída',
    absence: 'Ausência',
    other: 'Outro',
  }[value] || 'Ajuste'
}

function scheduleRuleLabel(value) {
  return SCHEDULE_RULE_OPTIONS.find((option) => option.value === value)?.label
    || String(value || 'Não informado').replaceAll('_', ' ')
}

function scheduleRuleTone(value) {
  return {
    always_onsite: 'status-success',
    always_remote: 'status-info',
    even_days: 'status-warning',
    odd_days: 'status-warning',
    undefined: 'status-neutral',
  }[value] || 'status-neutral'
}

function EmployeeSelect({ employees, value, onChange, disabled = false }) {
  return (
    <select className="field mt-2" disabled={disabled} required value={value} onChange={onChange}>
      <option value="">Selecione</option>
      {(employees || []).map((employee) => (
        <option key={employee.id} value={employee.id}>{employee.nome} - {employee.equipe.toUpperCase()}</option>
      ))}
    </select>
  )
}

function OperationalShell({ resource, children }) {
  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />
  return children
}

function useEmployees() {
  return useResource('listar_funcionarios.php')
}

async function submit(action, refresh, notify) {
  try {
    const result = await action()
    notify(result.mensagem || 'Operação concluída.')
    await refresh()
    return result
  } catch (error) {
    notify(error.message, 'error')
    return null
  }
}

function PeriodFilters({ filters, setFilters, extra = null }) {
  return (
    <FilterBar>
      <label className="label">Início<input className="field mt-2" type="date" value={filters.from} onChange={(event) => setFilters({ ...filters, from: event.target.value })} /></label>
      <label className="label">Fim<input className="field mt-2" min={filters.from} type="date" value={filters.to} onChange={(event) => setFilters({ ...filters, to: event.target.value })} /></label>
      <label className="label">Equipe<select className="field mt-2" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option></select></label>
      {extra}
    </FilterBar>
  )
}

export function CalendarPage({ session, notify }) {
  const canManage = session.role === 'admin' || session.role === 'gestor'
  const employees = useEmployees()
  const [filters, setFilters] = useState({ from: currentCompetency.start, to: currentCompetency.end, team: '', employee_id: '', type: '', status: '' })
  const resource = useResource(`portal/calendar.php${queryString(filters)}`)
  const [form, setForm] = useState({ title: '', description: '', type: 'manual', starts_at: `${today}T09:00`, ends_at: `${today}T10:00`, team: '' })

  async function create(event) {
    event.preventDefault()
    const result = await submit(() => post('portal/calendar.php', form), resource.refresh, notify)
    if (result) setForm({ ...form, title: '', description: '' })
  }

  return (
    <div className="space-y-5">
      <PeriodFilters filters={filters} setFilters={setFilters} extra={(
        <>
          <label className="label">Competência<input className="field mt-2" type="month" value={filters.to ? competencyFor(`${filters.to}T12:00:00`).key : ''} onChange={(event) => {
            if (!event.target.value) return
            const competency = competencyFor(`${event.target.value}-01T12:00:00`)
            setFilters({ ...filters, from: competency.start, to: competency.end })
          }} /></label>
          <label className="label">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={filters.employee_id} onChange={(event) => setFilters({ ...filters, employee_id: event.target.value })} /></label>
          <label className="label">Tipo<select className="field mt-2" value={filters.type} onChange={(event) => setFilters({ ...filters, type: event.target.value })}><option value="">Todos</option><option value="manual">Manual</option><option value="pause">Pausa</option><option value="schedule">Escala</option><option value="overtime">Hora extra</option><option value="time_adjustment">Ajuste</option><option value="oncall">Plantão</option></select></label>
          <label className="label">Status<input className="field mt-2" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })} /></label>
        </>
      )} />
      {canManage && (
        <form className="card space-y-4" onSubmit={create}>
          <h2 className="font-bold text-white">Novo evento operacional</h2>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="label xl:col-span-2">Título<input className="field mt-2" maxLength="160" required value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} /></label>
            <label className="label">Início<input className="field mt-2" required type="datetime-local" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} /></label>
            <label className="label">Fim<input className="field mt-2" type="datetime-local" value={form.ends_at} onChange={(event) => setForm({ ...form, ends_at: event.target.value })} /></label>
            <label className="label">Equipe<select className="field mt-2" value={form.team} onChange={(event) => setForm({ ...form, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option></select></label>
            <label className="label xl:col-span-3">Descrição<input className="field mt-2" maxLength="1000" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></label>
          </div>
          <button className="btn-primary" type="submit">Cadastrar evento</button>
        </form>
      )}
      <OperationalShell resource={resource}>
        {(resource.data?.items || []).length === 0 ? <EmptyState title="Nenhum evento no período" description="Ajuste os filtros ou cadastre um evento operacional." /> : (
          <section className="card table-wrap">
            <table className="data-table">
              <thead><tr><th>Data</th><th>Evento</th><th>Tipo</th><th>Equipe</th><th>Status</th></tr></thead>
              <tbody>{resource.data.items.map((item) => <tr key={item.id}><td>{formatDateTime(item.starts_at)}</td><td><strong>{item.title}</strong><small>{item.description || 'Sem descrição'}</small></td><td>{item.event_type}</td><td>{item.team?.toUpperCase() || 'Todas'}</td><td><Status value={item.status} /></td></tr>)}</tbody>
            </table>
          </section>
        )}
      </OperationalShell>
    </div>
  )
}

export function SchedulePage({ session, notify }) {
  const canManage = session.role === 'admin' || session.role === 'gestor'
  const canRemoveRule = session.role === 'admin'
  const employees = useEmployees()
  const [filters, setFilters] = useState({ from: currentCompetency.start, to: currentCompetency.end, team: '', employee_id: '' })
  const resource = useResource(`portal/schedules.php${queryString(filters)}`)
  const [rule, setRule] = useState({ employee_id: '', rule_type: 'undefined', effective_from: today })
  const [exception, setException] = useState({ employee_id: '', exception_date: today, exception_type: 'remote', note: '' })
  const [importRows, setImportRows] = useState([])
  const [preview, setPreview] = useState(null)
  const [importLoading, setImportLoading] = useState(false)
  const [dragging, setDragging] = useState(false)
  const importInputRef = useRef(null)

  async function saveRule(event) {
    event.preventDefault()
    const employeeId = Number(rule.employee_id)
    const ruleType = String(rule.rule_type || '')
    if (!SCHEDULE_RULE_VALUES.has(ruleType)) {
      notify('Selecione uma regra de escala válida.', 'error')
      return
    }
    await submit(
      () => post('portal/schedules.php', {
        action: 'rule',
        employee_id: employeeId,
        rule_type: ruleType,
        effective_from: rule.effective_from,
      }),
      resource.refresh,
      notify,
    )
  }

  async function removeRule(item) {
    if (!window.confirm('Deseja remover a regra de escala deste colaborador?')) return
    await submit(
      () => post('portal/schedules.php', { action: 'remove_rule', employee_id: Number(item.employee_id) }),
      resource.refresh,
      notify,
    )
  }

  async function saveException(event) {
    event.preventDefault()
    await submit(() => post('portal/schedules.php', { action: 'exception', ...exception, employee_id: Number(exception.employee_id) }), resource.refresh, notify)
  }

  async function readSpreadsheet(file) {
    if (!file) return
    setImportRows([])
    setPreview(null)
    const lowerName = file.name.toLowerCase()
    if (!IMPORT_LIMITS.acceptedExtensions.some((extension) => lowerName.endsWith(extension))) {
      notify('Use CSV ou XLSX. XLS legado deve ser convertido para XLSX.', 'error')
      return
    }
    if (file.size > IMPORT_LIMITS.maxFileBytes) {
      notify(
        `A planilha excede o limite de ${Math.floor(IMPORT_LIMITS.maxFileBytes / 1024)} KB.`,
        'error',
      )
      return
    }
    setImportLoading(true)
    try {
      const body = new FormData()
      body.append('spreadsheet', file)
      const result = await postForm('portal/schedules.php', body)
      setImportRows(result.parsed_rows)
      setPreview(result)
    } catch (error) {
      notify(error.message, 'error')
      setImportRows([])
      setPreview(null)
    } finally {
      setImportLoading(false)
    }
  }

  async function confirmImport() {
    const result = await submit(
      () => post('portal/schedules.php', { action: 'import_confirm', rows: importRows }),
      resource.refresh,
      notify,
    )
    if (result) {
      setImportRows([])
      setPreview(null)
    }
  }

  return (
    <div className="space-y-5">
      <PeriodFilters filters={filters} setFilters={setFilters} extra={<label className="label">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={filters.employee_id} onChange={(event) => setFilters({ ...filters, employee_id: event.target.value })} /></label>} />
      {canManage && (
        <div className="grid gap-5 xl:grid-cols-2">
          <form className="card space-y-4" onSubmit={saveRule}>
            <SectionHeader
              description="Valores canônicos preservados: par/ímpar, sempre presencial, sempre remoto ou sem escala."
              eyebrow="Escala fixa"
              title="Regra por colaborador"
            />
            <label className="label">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={rule.employee_id} onChange={(event) => setRule({ ...rule, employee_id: event.target.value })} /></label>
            <label className="label">Regra<select className="field mt-2" value={rule.rule_type} onChange={(event) => setRule({ ...rule, rule_type: event.target.value })}>{SCHEDULE_RULE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
            <label className="label">Vigência<input className="field mt-2" required type="date" value={rule.effective_from} onChange={(event) => setRule({ ...rule, effective_from: event.target.value })} /></label>
            <InlineAlert tone="info" title="Contrato preservado">
              O frontend envia `rule_type` com o valor canônico selecionado. Não há fallback silencioso para “Sem escala definida”.
            </InlineAlert>
            <button className="btn-primary" type="submit">Salvar regra</button>
          </form>
          <form className="card space-y-4" onSubmit={saveException}>
            <SectionHeader
              description="Use exceções para alterar a presença de uma data específica sem mudar a regra fixa."
              eyebrow="Ajuste pontual"
              title="Exceção por data"
            />
            <label className="label">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={exception.employee_id} onChange={(event) => setException({ ...exception, employee_id: event.target.value })} /></label>
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="label">Data<input className="field mt-2" required type="date" value={exception.exception_date} onChange={(event) => setException({ ...exception, exception_date: event.target.value })} /></label>
              <label className="label">Tipo<select className="field mt-2" value={exception.exception_type} onChange={(event) => setException({ ...exception, exception_type: event.target.value })}><option value="remote">Remoto excepcional</option><option value="onsite">Presencial excepcional</option><option value="day_off">Folga</option><option value="absence">Ausência</option><option value="training">Treinamento</option><option value="oncall">Plantão</option><option value="vacation">Férias</option><option value="leave">Licença</option></select></label>
            </div>
            <label className="label">Observação<input className="field mt-2" maxLength="1000" value={exception.note} onChange={(event) => setException({ ...exception, note: event.target.value })} /></label>
            <button className="btn-primary" type="submit">Salvar exceção</button>
          </form>
          <section className="card space-y-4 xl:col-span-2">
            <SectionHeader
              description="CSV ou XLSX. Cabeçalhos aceitos: id, login_ad, email, nome, equipe, regra."
              eyebrow="Importação assistida"
              title="Importar planilha de regras"
            />
            <button
              className={`drop-zone ${dragging ? 'drop-zone-active' : ''}`}
              onClick={() => importInputRef.current?.click()}
              onDragEnter={(event) => { event.preventDefault(); setDragging(true) }}
              onDragLeave={(event) => { event.preventDefault(); setDragging(false) }}
              onDragOver={(event) => event.preventDefault()}
              onDrop={(event) => { event.preventDefault(); setDragging(false); readSpreadsheet(event.dataTransfer.files?.[0]) }}
              type="button"
            >
              <strong className="text-sm text-slate-200">{importLoading ? 'Validando planilha...' : 'Clique ou arraste a planilha'}</strong>
              <span className="mt-1 text-xs text-slate-500">CSV ou XLSX; preview obrigatório</span>
            </button>
            <input ref={importInputRef} accept={IMPORT_LIMITS.acceptedExtensions.join(',')} className="hidden" type="file" onChange={(event) => { readSpreadsheet(event.target.files?.[0]); event.target.value = '' }} />
            {preview && (
              <div className="space-y-3">
                <p className="text-sm text-slate-300">{preview.valid_count} válida(s), {preview.invalid_count} inválida(s).</p>
                <div className="max-h-56 overflow-auto rounded-lg border border-white/5">
                  {preview.rows.map((row) => <div className="border-b border-white/5 px-3 py-2 text-sm" key={row.line}><span className={row.valid ? 'text-emerald-300' : 'text-red-300'}>Linha {row.line}</span> - {row.employee_name || row.ad_login || 'Não identificado'} - {row.rule_type || row.errors.join(' ')}</div>)}
                </div>
                <button className="btn-primary" disabled={preview.invalid_count > 0} onClick={confirmImport} type="button">Confirmar importação</button>
              </div>
            )}
          </section>
        </div>
      )}
      <OperationalShell resource={resource}>
        {(resource.data?.rules || []).filter((item) => !item.effective_until).length > 0 && (
          <section className="card table-wrap">
            <h2 className="mb-4 font-bold text-white">Regras ativas por colaborador</h2>
            <table className="data-table">
              <thead><tr><th>Colaborador</th><th>Equipe</th><th>Regra</th><th>Vigência</th><th>Ações</th></tr></thead>
              <tbody>{(resource.data?.rules || []).filter((item) => !item.effective_until).map((item) => (
                <tr key={item.id}>
                  <td><div className="flex min-w-0 items-start gap-3"><UserAvatar name={item.employee_name} /><div><strong>{item.employee_name}</strong><small>{item.ad_login || 'Sem login AD'}</small></div></div></td>
                  <td>{item.team.toUpperCase()}</td>
                  <td><span className={`status-badge ${scheduleRuleTone(item.rule_type)}`}>{scheduleRuleLabel(item.rule_type)}</span></td>
                  <td>{formatDate(item.effective_from)}</td>
                  <td>{canRemoveRule ? <button className="table-action text-red-300" onClick={() => removeRule(item)} type="button">Remover regra</button> : 'Restrito a admin'}</td>
                </tr>
              ))}</tbody>
            </table>
          </section>
        )}
        {(resource.data?.generated || []).length === 0 ? <EmptyState title="Nenhuma escala gerada" description="Cadastre regras fixas para gerar automaticamente os dias presenciais e remotos." /> : (
          <section className="card table-wrap">
            <table className="data-table">
              <thead><tr><th>Data</th><th>Colaborador</th><th>Equipe</th><th>Modalidade</th><th>Origem</th></tr></thead>
              <tbody>{resource.data.generated.map((item) => <tr key={`${item.employee_id}-${item.date}`}><td>{formatDate(item.date)}</td><td><strong>{item.employee_name}</strong><small>{scheduleRuleLabel(item.rule_type)}</small></td><td>{item.team.toUpperCase()}</td><td><Status value={item.presence_type} /></td><td>{item.source === 'exception' ? `Exceção: ${item.label}` : 'Regra fixa'}</td></tr>)}</tbody>
            </table>
          </section>
        )}
      </OperationalShell>
    </div>
  )
}

function overtimePreview(form) {
  const [startHour, startMinute] = String(form.start_time || '00:00').split(':').map(Number)
  const [endHour, endMinute] = String(form.end_time || '00:00').split(':').map(Number)
  if (![startHour, startMinute, endHour, endMinute].every(Number.isFinite)) return { minutes: 0, overnight: false }
  const start = startHour * 60 + startMinute
  let end = endHour * 60 + endMinute
  const overnight = end <= start
  if (overnight) end += 24 * 60
  return { minutes: Math.max(0, end - start), overnight }
}

function WorkflowPage({ kind, session, notify }) {
  const overtime = kind === 'overtime'
  const endpoint = overtime ? 'portal/overtime.php' : 'portal/time_corrections.php'
  const employees = useEmployees()
  const canApprove = session.role === 'admin' || session.role === 'gestor'
  const canCreate = session.role !== 'somente_leitura'
  const ownEmployee = session.ci.funcionario_id || ''
  const [filters, setFilters] = useState({ from: currentCompetency.start, to: currentCompetency.end, team: '', employee_id: '', status: '' })
  const resource = useResource(`${endpoint}${queryString(filters)}`)
  const [form, setForm] = useState(overtime
    ? { employee_id: ownEmployee, work_date: today, start_time: '18:00', end_time: '19:00', reason: '', justification: '' }
    : { employee_id: ownEmployee, adjustment_date: today, adjustment_type: 'entry', correct_time: '08:00', recorded_time: '', justification: '' })
  const preview = overtime ? overtimePreview(form) : null
  const exportUrl = apiUrl(`${endpoint}${queryString({ ...filters, format: 'csv' })}`)

  async function create(event) {
    event.preventDefault()
    const result = await submit(() => post(endpoint, { action: 'create', ...form, employee_id: Number(form.employee_id) }), resource.refresh, notify)
    if (result) {
      setForm(overtime
        ? { ...form, reason: '', justification: '' }
        : { ...form, justification: '', recorded_time: '' })
    }
  }

  async function decide(id, decision) {
    await submit(() => post(endpoint, { action: 'decision', id, decision }), resource.refresh, notify)
  }

  const items = resource.data?.items || []
  const pending = items.filter((item) => item.status === 'pending')
  const history = items.filter((item) => item.status !== 'pending')
  const title = overtime ? 'Horas extras' : 'Correção de ponto'
  const noun = overtime ? 'hora extra' : 'ajuste de ponto'

  function renderRows(rows) {
    return rows.map((item) => (
      <tr key={item.id}>
        <td>{formatDate(overtime ? item.work_date : item.adjustment_date)}</td>
        <td><div className="flex min-w-0 items-start gap-3"><UserAvatar name={item.employee_name} /><div><strong>{item.employee_name}</strong><small>Equipe {item.team.toUpperCase()}</small></div></div></td>
        {overtime ? (
          <td>
            <div className="flex flex-wrap gap-2">
              <DetailPill label="Período" value={`${item.start_time.slice(0, 5)} - ${item.end_time.slice(0, 5)}`} />
              <DetailPill label="Total" value={minutesLabel(item.total_minutes)} tone="warning" />
            </div>
            <small>{item.reason}</small>
            <small>{item.justification}</small>
          </td>
        ) : (
          <td>
            <div className="flex flex-wrap gap-2">
              <DetailPill label="Tipo" value={adjustmentTypeLabel(item.adjustment_type)} tone="warning" />
              <DetailPill label="Registrado" value={item.recorded_time?.slice(0, 5) || 'Não informado'} />
              <DetailPill label="Correto" value={item.correct_time?.slice(0, 5) || 'Sem horário'} />
            </div>
            <small>{item.justification}</small>
          </td>
        )}
        <td><Status value={item.status} /></td>
        <td>
          {canApprove && item.status === 'pending' ? (
            <div className="flex flex-wrap gap-2">
              <button className="table-action text-emerald-300" onClick={() => decide(item.id, 'approved')} type="button">Aprovar</button>
              <button className="table-action text-red-300" onClick={() => decide(item.id, 'rejected')} type="button">Rejeitar</button>
            </div>
          ) : (
            <>
              <strong>{item.approved_by || 'Aguardando decisão'}</strong>
              <small>{item.approved_at ? formatDateTime(item.approved_at) : statusLabel(item.status)}</small>
            </>
          )}
        </td>
      </tr>
    ))
  }

  return (
    <div className="space-y-5">
      <PeriodFilters filters={filters} setFilters={setFilters} extra={(
        <>
          <label className="label">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={filters.employee_id} onChange={(event) => setFilters({ ...filters, employee_id: event.target.value })} /></label>
          <label className="label">Status<select className="field mt-2" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">Todos</option><option value="pending">Pendente</option><option value="approved">Aprovado</option><option value="rejected">Rejeitado</option><option value="synced">Sincronizado</option><option value="sync_error">Erro de sincronização</option></select></label>
        </>
      )} />
      <SectionHeader
        action={<a className="btn-secondary gap-2" href={exportUrl}><Icon className="h-4 w-4" name="download" /> Exportar planilha</a>}
        description={`${formatDate(filters.from)} a ${formatDate(filters.to)}. Filtros, criação, aprovação e exportação CSV preservados.`}
        eyebrow="Workflow operacional"
        title={title}
      />
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard detail={`Total de ${noun}s nos filtros`} icon={overtime ? 'timer' : 'edit'} label="Registros" value={items.length} />
        <MetricCard detail="Aguardam decisão de gestor/admin" icon="bell" label="Pendentes" tone="warning" value={pending.length} />
        <MetricCard detail="Itens aprovados ou rejeitados" icon="file" label="Histórico" value={history.length} />
        <MetricCard detail={overtime ? 'Soma de horas extras listadas' : 'Ajustes de ponto listados'} icon="chart" label={overtime ? 'Total calculado' : 'Total filtrado'} tone="info" value={overtime ? minutesLabel(items.reduce((total, item) => total + Number(item.total_minutes || 0), 0)) : items.length} />
      </section>
      {!canCreate && <InlineAlert tone="info" title="Acesso somente leitura">Novos lançamentos e decisões estão desabilitados para seu perfil.</InlineAlert>}
      <form className={canCreate ? '' : 'hidden'} onSubmit={create}>
        <FormSection
          description={`Competência atual: ${currentCompetency.label}, de ${formatDate(currentCompetency.start)} a ${formatDate(currentCompetency.end)}.`}
          eyebrow="Nova solicitação"
          title={overtime ? 'Nova hora extra' : 'Novo ajuste de ponto'}
        >
        <div className="form-grid">
          <label className="label">Colaborador<EmployeeSelect disabled={!canApprove} employees={employees.data?.funcionarios} value={form.employee_id} onChange={(event) => setForm({ ...form, employee_id: event.target.value })} /></label>
          {overtime ? (
            <>
              <label className="label">Data da realização<input className="field mt-2" required type="date" value={form.work_date} onChange={(event) => setForm({ ...form, work_date: event.target.value })} /></label>
              <label className="label">Hora de entrada<input className="field mt-2" required type="time" value={form.start_time} onChange={(event) => setForm({ ...form, start_time: event.target.value })} /></label>
              <label className="label">Hora de saída<input className="field mt-2" required type="time" value={form.end_time} onChange={(event) => setForm({ ...form, end_time: event.target.value })} /></label>
              <label className="label xl:col-span-2">Descrição/Motivo<input className="field mt-2" maxLength="500" required value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} /></label>
              <div className="rounded-lg border border-white/10 bg-slate-950/40 px-3 py-2 text-sm text-slate-300">
                <span className="block text-xs font-bold uppercase tracking-wider text-slate-600">Total calculado</span>
                <strong className="mt-1 block text-white">{minutesLabel(preview.minutes)}</strong>
                {preview.overnight && <small className="mt-1 block text-amber-300">Virada de dia considerada no cálculo.</small>}
              </div>
            </>
          ) : (
            <>
              <label className="label">Data<input className="field mt-2" required type="date" value={form.adjustment_date} onChange={(event) => setForm({ ...form, adjustment_date: event.target.value })} /></label>
              <label className="label">Tipo de ajuste<select className="field mt-2" value={form.adjustment_type} onChange={(event) => setForm({ ...form, adjustment_type: event.target.value })}><option value="entry">Entrada</option><option value="lunch_out">Saída para almoço</option><option value="lunch_return">Retorno do almoço</option><option value="exit">Saída</option><option value="absence">Ausência</option><option value="other">Outro</option></select></label>
              <label className="label">Horário correto<input className="field mt-2" disabled={form.adjustment_type === 'absence'} required={form.adjustment_type !== 'absence'} type="time" value={form.correct_time} onChange={(event) => setForm({ ...form, correct_time: event.target.value })} /></label>
              <label className="label">Horário registrado<input className="field mt-2" type="time" value={form.recorded_time} onChange={(event) => setForm({ ...form, recorded_time: event.target.value })} /></label>
            </>
          )}
          <label className="label xl:col-span-2">Justificativa<textarea className="field mt-2 min-h-24" maxLength="2000" required value={form.justification} onChange={(event) => setForm({ ...form, justification: event.target.value })} /></label>
        </div>
          <div className="form-actions">
            <button className="btn-primary" type="submit">Enviar para aprovação</button>
          </div>
        </FormSection>
      </form>
      <OperationalShell resource={resource}>
        {items.length === 0 ? <EmptyState title={`Nenhum ${noun} encontrado`} description="Ajuste os filtros para consultar outro período ou crie uma nova solicitação." /> : (
          <>
            <section className="card table-wrap">
              <SectionHeader meta={<span className="status-badge status-warning">{pending.length} pendente(s)</span>} title="Pendentes de aprovação" />
              {pending.length === 0 ? <div className="mt-5"><InlineAlert tone="info" title="Nenhuma pendência neste filtro">Itens pendentes aparecerão aqui para decisão.</InlineAlert></div> : (
                <div className="mt-5">
                <table className="data-table">
                  <thead><tr><th>Data</th><th>Colaborador</th><th>Detalhe</th><th>Status</th><th>Aprovação</th></tr></thead>
                  <tbody>{renderRows(pending)}</tbody>
                </table>
                </div>
              )}
            </section>
            <section className="card table-wrap">
              <SectionHeader meta={<span className="status-badge status-neutral">{history.length} registro(s)</span>} title="Histórico" />
              {history.length === 0 ? <div className="mt-5"><InlineAlert tone="info" title="Nenhum histórico neste filtro">Aprovados e rejeitados aparecerão aqui.</InlineAlert></div> : (
                <div className="mt-5">
                <table className="data-table">
                  <thead><tr><th>Data</th><th>Colaborador</th><th>Detalhe</th><th>Status</th><th>Aprovação</th></tr></thead>
                  <tbody>{renderRows(history)}</tbody>
                </table>
                </div>
              )}
            </section>
          </>
        )}
      </OperationalShell>
    </div>
  )
}
export function OvertimePage(props) {
  return <WorkflowPage kind="overtime" {...props} />
}

export function TimeCorrectionPage(props) {
  return <WorkflowPage kind="time_correction" {...props} />
}

export function OncallPage({ session, notify }) {
  const canManage = session.role === 'admin' || session.role === 'gestor'
  const employees = useEmployees()
  const [filters, setFilters] = useState({ from: currentCompetency.start, to: currentCompetency.end, team: '' })
  const resource = useResource(`portal/oncall.php${queryString(filters)}`)
  const [form, setForm] = useState({ employee_id: '', starts_on: today, ends_on: today, start_time: '18:00', end_time: '08:00', shift_type: 'oncall', note: '' })

  async function create(event) {
    event.preventDefault()
    await submit(() => post('portal/oncall.php', { ...form, employee_id: Number(form.employee_id) }), resource.refresh, notify)
  }

  return (
    <div className="space-y-5">
      <PeriodFilters filters={filters} setFilters={setFilters} />
      {canManage && (
        <form className="card space-y-4" onSubmit={create}>
          <h2 className="font-bold text-white">Cadastrar plantão</h2>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="label xl:col-span-2">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={form.employee_id} onChange={(event) => setForm({ ...form, employee_id: event.target.value })} /></label>
            <label className="label">Data inicial<input className="field mt-2" required type="date" value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} /></label>
            <label className="label">Data final<input className="field mt-2" min={form.starts_on} required type="date" value={form.ends_on} onChange={(event) => setForm({ ...form, ends_on: event.target.value })} /></label>
            <label className="label">Hora inicial<input className="field mt-2" required type="time" value={form.start_time} onChange={(event) => setForm({ ...form, start_time: event.target.value })} /></label>
            <label className="label">Hora final<input className="field mt-2" required type="time" value={form.end_time} onChange={(event) => setForm({ ...form, end_time: event.target.value })} /></label>
            <label className="label">Tipo<select className="field mt-2" value={form.shift_type} onChange={(event) => setForm({ ...form, shift_type: event.target.value })}><option value="oncall">Plantão</option><option value="standby">Sobreaviso</option><option value="emergency">Emergência</option><option value="weekend">Fim de semana</option><option value="holiday">Feriado</option></select></label>
            <label className="label">Observação<input className="field mt-2" maxLength="1000" value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} /></label>
          </div>
          <button className="btn-primary" type="submit">Cadastrar plantão</button>
        </form>
      )}
      <OperationalShell resource={resource}>
        {(resource.data?.items || []).length === 0 ? <EmptyState title="Nenhum plantão no período" description="Ajuste os filtros ou cadastre um novo plantão para esta competência." /> : (
          <section className="card table-wrap">
            <table className="data-table"><thead><tr><th>Período</th><th>Colaborador</th><th>Horário</th><th>Tipo</th><th>Status</th></tr></thead><tbody>{resource.data.items.map((item) => <tr key={item.id}><td>{formatDate(item.starts_on)} a {formatDate(item.ends_on)}</td><td><strong>{item.employee_name}</strong><small>{item.team.toUpperCase()}</small></td><td>{item.start_time.slice(0, 5)} - {item.end_time.slice(0, 5)}</td><td>{item.shift_type}</td><td><Status value={item.status} /></td></tr>)}</tbody></table>
          </section>
        )}
      </OperationalShell>
    </div>
  )
}

export function OperationalReportsPage() {
  const [filters, setFilters] = useState({ competency: currentCompetency.key, team: '', employee_id: '' })
  const resource = useResource(`portal/reports.php${queryString(filters)}`)
  const employees = useEmployees()
  const indicators = useMemo(() => {
    const summary = resource.data?.summary || {}
    return [
      ['Horas extras', minutesLabel(summary.overtime_minutes)],
      ['Ajustes de ponto', summary.adjustments_count || 0],
      ['Plantões', summary.oncall_count || 0],
      ['Dias presenciais', summary.onsite_days || 0],
      ['Dias remotos', summary.remote_days || 0],
      ['Pendências', summary.pending || 0],
      ['Rejeitados', summary.rejected || 0],
      ['Erros de sync', summary.sync_errors || 0],
    ]
  }, [resource.data?.summary])

  return (
    <div className="space-y-5">
      <section className="card grid gap-3 md:grid-cols-3">
        <label className="label">Competência<input className="field mt-2" type="month" value={filters.competency} onChange={(event) => setFilters({ ...filters, competency: event.target.value })} /></label>
        <label className="label">Equipe<select className="field mt-2" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option></select></label>
        <label className="label">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={filters.employee_id} onChange={(event) => setFilters({ ...filters, employee_id: event.target.value })} /></label>
      </section>
      <OperationalShell resource={resource}>
        <>
          <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{indicators.map(([label, value]) => <article className="card" key={label}><p className="text-sm text-slate-500">{label}</p><p className="mt-3 text-2xl font-black text-white">{value}</p></article>)}</section>
          <section className="card flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 className="font-bold text-white">Competência {resource.data?.competency?.label}</h2><p className="mt-1 text-sm text-slate-500">{formatDate(resource.data?.period?.from)} a {formatDate(resource.data?.period?.to)}. O MySQL permanece como fonte principal.</p></div>
            <a className="btn-primary" href={apiUrl(`portal/reports.php${queryString({ ...filters, format: 'csv' })}`)}>Exportar CSV</a>
          </section>
          <section className="grid gap-5 lg:grid-cols-2">
            <SummaryTable title="Resumo por colaborador" values={resource.data?.by_employee} />
            <SummaryTable title="Resumo por equipe" values={resource.data?.by_team} />
          </section>
        </>
      </OperationalShell>
    </div>
  )
}

function SummaryTable({ title, values }) {
  const entries = Object.entries(values || {})
  return (
    <section className="card">
      <h2 className="mb-4 font-bold text-white">{title}</h2>
      {entries.length === 0 ? <p className="text-sm text-slate-500">Sem dados no período.</p> : entries.map(([label, value]) => (
        <div className="flex items-center justify-between gap-3 border-b border-white/5 py-3 text-sm" key={label}>
          <strong className="text-slate-200">{label}</strong>
          <span className="text-right text-slate-500">{minutesLabel(value.overtime_minutes)} HE, {value.adjustments || 0} ajuste(s), {value.oncall || 0} plantão(ões), {value.onsite_days || 0} presencial(is)</span>
        </div>
      ))}
    </section>
  )
}
