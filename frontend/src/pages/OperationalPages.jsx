import { useMemo, useRef, useState } from 'react'
import { hasPermission, isManagerRole } from '../lib/roles'
import { useQuery } from '@tanstack/react-query'
import { flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table'
import { useForm } from 'react-hook-form'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { PartialMarker, PresenceBadge } from '../components/ui/Presence'
import { useResource } from '../hooks/useResource'
import { api, apiUrl, post, postForm } from '../lib/api'
import { ACTION_FEEDBACK, decisionFeedback, importFeedback } from '../lib/actionFeedback'
import { runAction } from '../lib/actionRunner'
import { formatDate, formatDateTime } from '../lib/format'
import { reportFiltersSchema, scheduleRuleSchema } from '../lib/formSchemas'
import { presenceCutoffNotice, presenceCutoffTitle, presenceLegendItem, rowPartialTime } from '../lib/presenceLegend'
import { queryKeys } from '../lib/queryKeys'
import {
  DetailPill,
  FilterBar,
  FormSection,
  InlineAlert,
  MetricCard,
  SectionHeader,
  TruncationNotice,
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
  { value: 'fixed_weekdays', label: 'Dias fixos da semana' },
  { value: 'cycle', label: 'Ciclo presencial/remoto' },
  { value: 'undefined', label: 'Sem escala definida' },
]
// [P7, F3] Exceção de escala só para trabalho; folga, falta, férias e
// afastamento vão para a tela Ausências (o servidor recusa com 400).
const EXCEPTION_TYPE_OPTIONS = [
  { value: 'remote', label: 'Remoto excepcional' },
  { value: 'onsite', label: 'Presencial excepcional' },
  { value: 'training', label: 'Treinamento' },
  { value: 'oncall', label: 'Plantão' },
]
const SCHEDULE_RULE_VALUES = new Set(SCHEDULE_RULE_OPTIONS.map((option) => option.value))
const WEEKDAY_OPTIONS = [
  { value: 'mon', label: 'Segunda', short: 'seg' },
  { value: 'tue', label: 'Terça', short: 'ter' },
  { value: 'wed', label: 'Quarta', short: 'qua' },
  { value: 'thu', label: 'Quinta', short: 'qui' },
  { value: 'fri', label: 'Sexta', short: 'sex' },
]

function Status({ value }) {
  const tone = {
    approved: 'status-success',
    active: 'status-success',
    synced: 'status-success',
    onsite: 'status-success',
    pending: 'status-warning',
    remote: 'status-info',
    absence: 'status-danger',
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
    no_schedule: 'Sem escala',
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

function weekdaySummary(weekdays = []) {
  const labels = WEEKDAY_OPTIONS
    .filter((option) => weekdays.includes(option.value))
    .map((option) => option.short)
  if (labels.length === 0) return 'dias não definidos'
  if (labels.length === 1) return labels[0]
  const last = labels.at(-1)
  return `${labels.slice(0, -1).join(', ')} e ${last}`
}

function emptyScheduleRule(effectiveFrom = today) {
  return {
    employee_id: '',
    rule_type: 'undefined',
    effective_from: effectiveFrom,
    weekdays: [],
    input_mode: 'onsite',
    home_weekdays: [],
    cycle_onsite_days: '2',
    cycle_remote_days: '1',
    cycle_starts_with: 'onsite',
  }
}

// Dias de seg a sex que não estão na lista (home -> presenciais e vice-versa).
function otherWeekdays(weekdays = []) {
  return WEEKDAY_OPTIONS.map((option) => option.value).filter((value) => !weekdays.includes(value))
}

// F2B: payload comum ao "rule" e à prévia (mesmo contrato no servidor). No
// ciclo, a data inicial é o início da vigência; o servidor monta o pattern.
function buildRulePayload(data, action) {
  const ruleType = String(data.rule_type || '')
  const payload = {
    action,
    employee_id: Number(data.employee_id),
    rule_type: ruleType,
    effective_from: data.effective_from,
  }
  if (ruleType === 'fixed_weekdays') {
    if (data.input_mode === 'home') {
      payload.input_mode = 'home'
      payload.home_weekdays = data.home_weekdays
    } else {
      payload.weekdays = data.weekdays
    }
  }
  if (ruleType === 'cycle') {
    payload.cycle = {
      onsite_days: Number(data.cycle_onsite_days),
      remote_days: Number(data.cycle_remote_days),
      starts_with: data.cycle_starts_with,
      anchor_date: data.effective_from,
    }
  }
  return payload
}

// Chave da prévia: qualquer campo que muda o payload deixa a prévia desatualizada.
function rulePreviewKey(values) {
  return JSON.stringify(buildRulePayload(values, 'rule_preview'))
}

// Histórico de um colaborador: sem a regra implícita da Liderança e sem linha
// vazia (fim antes do início, gravada pelo código anterior ao F1), mais recente primeiro.
function scheduleHistoryRows(rules, employeeId) {
  return (rules || [])
    .filter((item) => !item.implicit && String(item.employee_id) === String(employeeId))
    .filter((item) => !item.effective_until || item.effective_until >= item.effective_from)
    .sort((a, b) => String(b.effective_from).localeCompare(String(a.effective_from)))
}

function scheduleSourceLabel(item) {
  if (item.presence_type === 'absence') return item.source === 'absence' ? 'Ausência lançada' : 'Exceção de ausência'
  if (item.source === 'exception') return `Exceção: ${item.label}`
  if (item.source === 'implicit') return 'Regra fixa da Liderança'
  if (item.source === 'none') return 'Sem regra cadastrada'
  // [F5, ajuste e] Qualquer regra (dias pares, ciclo, dias fixos...).
  return 'Regra de escala'
}

function scheduleRuleLabel(value, weekdays = []) {
  if (value === 'fixed_weekdays') return `Presencial: ${weekdaySummary(weekdays)}`
  return SCHEDULE_RULE_OPTIONS.find((option) => option.value === value)?.label
    || String(value || 'Não informado').replaceAll('_', ' ')
}

function scheduleRuleTone(value) {
  return {
    always_onsite: 'status-success',
    always_remote: 'status-info',
    even_days: 'status-warning',
    odd_days: 'status-warning',
    fixed_weekdays: 'status-info',
    cycle: 'status-info',
    undefined: 'status-neutral',
  }[value] || 'status-neutral'
}

// [P12] Equipe Liderança: escala fixa presencial, sem regra a cadastrar.
function isLeadershipTeam(team) {
  return ['lideranca', 'liderança'].includes(String(team || '').toLowerCase())
}

function teamLabel(team) {
  return isLeadershipTeam(team) ? 'Liderança' : String(team || '').toUpperCase()
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

async function submit(action, refresh, notify, successMessage = ACTION_FEEDBACK.changesSaved, onSuccess, onError) {
  return runAction({
    action,
    refresh,
    notify,
    successMessage,
    onSuccess,
    onError,
  })
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

// F2B: prévia calculada pelo servidor (próximos dias úteis), o que será
// encerrado e o aviso de prévia desatualizada.
function RulePreviewPanel({ preview, fresh }) {
  const days = preview?.preview || []
  return (
    <div className={`space-y-3 rounded-lg border p-3 ${fresh ? 'border-white/10 bg-slate-950/35' : 'border-amber-400/40 bg-amber-500/5'}`}>
      <p className="text-xs font-bold uppercase tracking-wider text-slate-500">Prévia das próximas 2 semanas (calculada pelo servidor)</p>
      {!fresh && (
        <InlineAlert tone="warning" title="Prévia desatualizada">
          Um campo mudou depois da prévia. Gere a prévia de novo para poder salvar.
        </InlineAlert>
      )}
      <p className="text-sm font-semibold text-slate-200">{preview.rule_label}</p>
      <div className="grid grid-cols-5 gap-1">
        {days.map((day) => (
          <span className={`status-badge justify-center ${day.presence_type === 'onsite' ? 'status-success' : day.presence_type === 'remote' ? 'status-info' : 'status-neutral'}`} key={day.date} title={day.rule_label}>
            {day.weekday} {day.date.slice(8, 10)} {day.presence_type === 'onsite' ? 'P' : day.presence_type === 'remote' ? 'R' : '—'}
          </span>
        ))}
      </div>
      <p className="text-xs text-slate-500">P = presencial, R = remoto, — = sem escala.</p>
      {preview.unchanged && <InlineAlert tone="info" title="Regra igual à vigente">Ao salvar, nada será alterado.</InlineAlert>}
      {!preview.unchanged && preview.closes && (
        <p className="text-sm text-slate-300">Ao salvar: a regra atual “{preview.closes.rule_label}” será encerrada em {formatDate(preview.closes.effective_until)}.</p>
      )}
      {!preview.unchanged && preview.effective_until && (
        <p className="text-sm text-slate-300">A nova regra vale até {formatDate(preview.effective_until)}, véspera da regra já agendada.</p>
      )}
      {preview.retroactive && <InlineAlert tone="warning" title="Início retroativo">Reescreve o histórico e fica auditado.</InlineAlert>}
    </div>
  )
}

export function SchedulePage({ session, notify }) {
  const canManage = isManagerRole(session.role)
  const canRemoveRule = hasPermission(session, 'escalas.manage')
  const canRetroactiveRule = hasPermission(session, 'escalas.retroativa')
  const employees = useEmployees()
  const [filters, setFilters] = useState({ from: currentCompetency.start, to: currentCompetency.end, team: '', employee_id: '' })
  const resource = useResource(`portal/schedules.php${queryString(filters)}`)
  const {
    clearErrors: clearRuleErrors,
    formState: { errors: ruleErrors },
    handleSubmit: handleRuleSubmit,
    register: registerRule,
    reset: resetRule,
    setError: setRuleError,
    setFocus: setRuleFocus,
    setValue: setRuleValue,
    watch: watchRule,
  } = useForm({
    defaultValues: emptyScheduleRule(),
  })
  const rule = watchRule()
  // F2B: Salvar só com a prévia do servidor para os valores atuais do formulário.
  const [rulePreview, setRulePreview] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const previewFresh = rulePreview !== null && rulePreview.key === rulePreviewKey(rule)
  // F2B: histórico de um colaborador, em painel recolhido (include_history=1).
  const [historyEmployee, setHistoryEmployee] = useState(null)
  const history = useResource(
    `portal/schedules.php${queryString({ from: filters.from, to: filters.to, employee_id: historyEmployee?.id ?? '', include_history: 1 })}`,
    { enabled: historyEmployee !== null },
  )
  const [exception, setException] = useState({ employee_id: '', exception_date: today, exception_type: 'remote', note: '' })
  const [exceptionError, setExceptionError] = useState('')
  // [F5, D13] Filtro só da tela, desligado por padrão; não muda o schedules.php.
  const [hideNoSchedule, setHideNoSchedule] = useState(false)
  const generatedRows = (resource.data?.generated || []).filter((item) => !hideNoSchedule || item.presence_type !== 'no_schedule')
  // Fora do Mapa PA e da escala: sem regra nem exceção (P12).
  const allEmployees = (employees.data?.funcionarios || []).filter((employee) => employee.fora_mapa_escala !== true)
  // Regra: só N1 e N2. Exceção de líder: só admin (L9), nunca remota.
  const ruleEmployees = allEmployees.filter((employee) => !isLeadershipTeam(employee.equipe))
  const exceptionEmployees = hasPermission(session, 'escalas.lideranca') ? allEmployees : ruleEmployees
  const exceptionIsLeadership = isLeadershipTeam(allEmployees.find((employee) => String(employee.id) === String(exception.employee_id))?.equipe)
  const [importRows, setImportRows] = useState([])
  const [preview, setPreview] = useState(null)
  const [importLoading, setImportLoading] = useState(false)
  const [dragging, setDragging] = useState(false)
  const importInputRef = useRef(null)

  function applyScheduleRuleErrors(error) {
    clearRuleErrors()
    const focusableFields = ['employee_id', 'rule_type', 'effective_from', 'cycle_onsite_days', 'cycle_remote_days']
    let firstField = null
    for (const issue of error.issues) {
      const field = issue.path?.[0] || 'employee_id'
      if (!firstField) firstField = field
      setRuleError(field, { type: 'zod', message: issue.message || 'Campo invalido.' })
    }
    if (focusableFields.includes(firstField)) setRuleFocus(firstField)
  }

  // Zod no cliente (o servidor valida de novo); devolve os dados ou null.
  function parseRule(values) {
    const parsed = scheduleRuleSchema.safeParse({
      ...values,
      weekdays: Array.isArray(values.weekdays) ? values.weekdays : [],
      home_weekdays: Array.isArray(values.home_weekdays) ? values.home_weekdays : [],
    })
    if (!parsed.success) {
      applyScheduleRuleErrors(parsed.error)
      return null
    }
    clearRuleErrors()
    const ruleType = String(parsed.data.rule_type || '')
    if (!SCHEDULE_RULE_VALUES.has(ruleType)) {
      setRuleError('rule_type', { type: 'validate', message: 'Tipo de escala invalido.' })
      setRuleFocus('rule_type')
      return null
    }
    return parsed.data
  }

  async function previewRule() {
    const values = watchRule()
    const data = parseRule(values)
    if (!data) return
    setPreviewLoading(true)
    try {
      await submit(
        () => post('portal/schedules.php', buildRulePayload(data, 'rule_preview')),
        null,
        notify,
        null,
        (result) => setRulePreview({ key: rulePreviewKey(values), data: result }),
      )
    } finally {
      setPreviewLoading(false)
    }
  }

  async function saveRule(values) {
    const data = parseRule(values)
    if (!data) return
    if (!previewFresh) {
      notify('Gere a prévia com os valores atuais antes de salvar a regra.', 'warning')
      return
    }
    await submit(
      () => post('portal/schedules.php', buildRulePayload(data, 'rule')),
      [resource.refresh, history.refresh],
      notify,
      null,
      (result) => {
        setRulePreview(null)
        resetRule(emptyScheduleRule(data.effective_from || today))
        // Regra igual à vigente: o servidor não grava nada e avisa.
        notify(result?.unchanged ? result.mensagem : ACTION_FEEDBACK.scheduleSaved, result?.unchanged ? 'info' : undefined)
      },
    )
  }

  // field: 'weekdays' (presenciais) ou 'home_weekdays' (modo home).
  function toggleRuleWeekday(weekday, field = 'weekdays') {
    const currentWeekdays = Array.isArray(watchRule(field)) ? watchRule(field) : []
    const enabled = currentWeekdays.includes(weekday)
    const selected = enabled
      ? currentWeekdays.filter((value) => value !== weekday)
      : [...currentWeekdays, weekday]
    const ordered = WEEKDAY_OPTIONS
      .map((option) => option.value)
      .filter((value) => selected.includes(value))
    setRuleValue(field, ordered, { shouldDirty: true })
    clearRuleErrors(field)
  }

  function editRule(item) {
    // Regra vigente: a edição vira uma versão nova a partir de hoje (o
    // histórico fica). Regra agendada: corrige no próprio início.
    const config = item.config || {}
    const pattern = Array.isArray(config.pattern) ? config.pattern : []
    const homeMode = item.rule_type === 'fixed_weekdays' && config.input_mode === 'home'
    setRulePreview(null)
    resetRule({
      ...emptyScheduleRule(),
      employee_id: String(item.employee_id),
      rule_type: item.rule_type || 'undefined',
      effective_from: item.effective_from && item.effective_from > today ? item.effective_from : today,
      weekdays: homeMode ? [] : item.weekdays || [],
      input_mode: homeMode ? 'home' : 'onsite',
      home_weekdays: homeMode ? otherWeekdays(item.weekdays || []) : [],
      cycle_onsite_days: pattern.length ? String(pattern.filter((value) => value === 'onsite').length) : '2',
      cycle_remote_days: pattern.length ? String(pattern.filter((value) => value === 'remote').length) : '1',
      cycle_starts_with: pattern[0] === 'remote' ? 'remote' : 'onsite',
    })
  }

  async function removeRule(item) {
    if (!window.confirm('Deseja remover a regra de escala deste colaborador?')) return
    await submit(
      () => post('portal/schedules.php', { action: 'remove_rule', employee_id: Number(item.employee_id) }),
      resource.refresh,
      notify,
      ACTION_FEEDBACK.scheduleRuleRemoved,
      () => {
        if (Number(rule.employee_id) === Number(item.employee_id)) resetRule(emptyScheduleRule())
      },
    )
  }

  async function saveException(event) {
    event.preventDefault()
    setExceptionError('')
    await submit(
      () => post('portal/schedules.php', { action: 'exception', ...exception, employee_id: Number(exception.employee_id) }),
      resource.refresh,
      notify,
      ACTION_FEEDBACK.scheduleExceptionSaved,
      () => setException({ employee_id: '', exception_date: exception.exception_date || today, exception_type: 'remote', note: '' }),
      // P7: a recusa do servidor (por exemplo, o 400 de tipo de ausência) fica junto do formulário.
      (error) => setExceptionError(error instanceof Error ? error.message : ''),
    )
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
    await submit(
      () => post('portal/schedules.php', { action: 'import_confirm', rows: importRows }),
      resource.refresh,
      notify,
      null,
      (result) => {
        setImportRows([])
        setPreview(null)
        if (importInputRef.current) importInputRef.current.value = ''
        notify(importFeedback(result))
      },
    )
  }

  return (
    <div className="space-y-5">
      <PeriodFilters filters={filters} setFilters={setFilters} extra={<label className="label">Colaborador<EmployeeSelect employees={employees.data?.funcionarios} value={filters.employee_id} onChange={(event) => setFilters({ ...filters, employee_id: event.target.value })} /></label>} />
      {canManage && (
        <div className="grid gap-5 xl:grid-cols-2">
          <form className="card space-y-4" onSubmit={handleRuleSubmit(saveRule)}>
            <SectionHeader
              description="Valores canônicos preservados: par/ímpar, sempre presencial, sempre remoto ou sem escala."
              eyebrow="Escala fixa"
              title="Regra por colaborador"
            />
            <label className="label">Colaborador
              <select aria-invalid={Boolean(ruleErrors.employee_id)} className="field mt-2" {...registerRule('employee_id')}>
                <option value="">Selecione</option>
                {ruleEmployees.map((employee) => (
                  <option key={employee.id} value={employee.id}>{employee.nome} - {teamLabel(employee.equipe)}</option>
                ))}
              </select>
              {ruleErrors.employee_id && <span className="mt-1 block text-xs text-red-300">{ruleErrors.employee_id.message}</span>}
            </label>
            <label className="label">Regra
              <select
                aria-invalid={Boolean(ruleErrors.rule_type)}
                className="field mt-2"
                {...registerRule('rule_type', {
                  onChange: (event) => {
                    if (event.target.value !== 'fixed_weekdays') {
                      setRuleValue('weekdays', [], { shouldDirty: true })
                      setRuleValue('home_weekdays', [], { shouldDirty: true })
                      setRuleValue('input_mode', 'onsite', { shouldDirty: true })
                      clearRuleErrors(['weekdays', 'home_weekdays'])
                    }
                  },
                })}
              >
                {SCHEDULE_RULE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </select>
              {ruleErrors.rule_type && <span className="mt-1 block text-xs text-red-300">{ruleErrors.rule_type.message}</span>}
            </label>
            {rule.rule_type === 'fixed_weekdays' && (
              <div className="space-y-3 rounded-lg border border-white/10 bg-slate-950/35 p-3">
                <fieldset className="flex flex-wrap gap-4 text-sm text-slate-300">
                  <legend className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Informar</legend>
                  <label className="inline-flex items-center gap-2"><input type="radio" value="onsite" {...registerRule('input_mode', { onChange: () => clearRuleErrors(['weekdays', 'home_weekdays']) })} />dias presenciais</label>
                  <label className="inline-flex items-center gap-2"><input type="radio" value="home" {...registerRule('input_mode', { onChange: () => clearRuleErrors(['weekdays', 'home_weekdays']) })} />dias de home office</label>
                </fieldset>
                <div className="flex flex-wrap gap-2">
                  {WEEKDAY_OPTIONS.map((option) => {
                    const field = rule.input_mode === 'home' ? 'home_weekdays' : 'weekdays'
                    const selected = (rule[field] || []).includes(option.value)
                    return (
                      <label className={`inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold transition ${selected ? 'border-cyan-400/40 bg-cyan-500/10 text-cyan-200' : 'border-white/10 bg-slate-900/60 text-slate-400 hover:border-white/20'}`} key={option.value}>
                        <input
                          checked={selected}
                          className="sr-only"
                          name={field}
                          onChange={() => toggleRuleWeekday(option.value, field)}
                          type="checkbox"
                          value={option.value}
                        />
                        {option.label}
                      </label>
                    )
                  })}
                </div>
                {ruleErrors.weekdays && <span className="block text-xs text-red-300">{ruleErrors.weekdays.message}</span>}
                {ruleErrors.home_weekdays && <span className="block text-xs text-red-300">{ruleErrors.home_weekdays.message}</span>}
                <p className="text-xs text-slate-500">
                  {rule.input_mode === 'home'
                    ? `Resultado: presencial ${weekdaySummary(otherWeekdays(rule.home_weekdays || []))} · home ${weekdaySummary(rule.home_weekdays || [])}.`
                    : `Resultado: presencial ${weekdaySummary(rule.weekdays || [])} · home ${weekdaySummary(otherWeekdays(rule.weekdays || []))}.`}
                </p>
              </div>
            )}
            {rule.rule_type === 'cycle' && (
              <div className="space-y-3 rounded-lg border border-white/10 bg-slate-950/35 p-3">
                <div className="grid gap-3 sm:grid-cols-2">
                  <label className="label">Dias presenciais
                    <input aria-invalid={Boolean(ruleErrors.cycle_onsite_days)} className="field mt-2" inputMode="numeric" max="19" min="1" type="number" {...registerRule('cycle_onsite_days')} />
                    {ruleErrors.cycle_onsite_days && <span className="mt-1 block text-xs text-red-300">{ruleErrors.cycle_onsite_days.message}</span>}
                  </label>
                  <label className="label">Dias remotos
                    <input aria-invalid={Boolean(ruleErrors.cycle_remote_days)} className="field mt-2" inputMode="numeric" max="19" min="1" type="number" {...registerRule('cycle_remote_days')} />
                    {ruleErrors.cycle_remote_days && <span className="mt-1 block text-xs text-red-300">{ruleErrors.cycle_remote_days.message}</span>}
                  </label>
                </div>
                <fieldset className="flex flex-wrap gap-4 text-sm text-slate-300">
                  <legend className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Começa por</legend>
                  <label className="inline-flex items-center gap-2"><input type="radio" value="onsite" {...registerRule('cycle_starts_with')} />Presencial</label>
                  <label className="inline-flex items-center gap-2"><input type="radio" value="remote" {...registerRule('cycle_starts_with')} />Remoto</label>
                </fieldset>
                <p className="text-xs text-slate-500">Conta só dias úteis (segunda a sexta); feriado conta como dia útil. Até 20 dias no ciclo.</p>
              </div>
            )}
            <label className="label">{rule.rule_type === 'cycle' ? 'Data inicial do ciclo' : 'Início da vigência'}
              <input aria-invalid={Boolean(ruleErrors.effective_from)} className="field mt-2" min={canRetroactiveRule ? undefined : today} required type="date" {...registerRule('effective_from')} />
              {ruleErrors.effective_from && <span className="mt-1 block text-xs text-red-300">{ruleErrors.effective_from.message}</span>}
              <span className="mt-1 block text-xs text-slate-500">
                {rule.rule_type === 'cycle' ? 'Precisa ser dia útil; o ciclo vale a partir dela. ' : ''}
                A regra vigente termina na véspera e o histórico fica guardado.
                {canRetroactiveRule ? ' Data anterior a hoje reescreve o histórico e fica auditada.' : ' Data anterior a hoje: só administradores e Liderança.'}
              </span>
            </label>
            {rulePreview && <RulePreviewPanel fresh={previewFresh} preview={rulePreview.data} />}
            <div className="flex flex-wrap gap-2">
              <button className="btn-secondary" disabled={previewLoading} onClick={previewRule} type="button">{previewLoading ? 'Calculando...' : 'Ver prévia'}</button>
              <button className="btn-primary" disabled={!previewFresh} type="submit">Salvar regra</button>
            </div>
            {!previewFresh && <p className="text-xs text-slate-500">Salvar fica disponível depois da prévia calculada pelo servidor.</p>}
          </form>
          <form className="card space-y-4" onSubmit={saveException}>
            <SectionHeader
              description="Use exceções para alterar a presença de uma data específica sem mudar a regra fixa."
              eyebrow="Ajuste pontual"
              title="Exceção por data"
            />
            <label className="label">Colaborador<EmployeeSelect employees={exceptionEmployees} value={exception.employee_id} onChange={(event) => {
              const leader = isLeadershipTeam(allEmployees.find((employee) => String(employee.id) === event.target.value)?.equipe)
              setException({ ...exception, employee_id: event.target.value, exception_type: leader && exception.exception_type === 'remote' ? 'onsite' : exception.exception_type })
            }} /></label>
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="label">Data<input className="field mt-2" required type="date" value={exception.exception_date} onChange={(event) => setException({ ...exception, exception_date: event.target.value })} /></label>
              <label className="label">Tipo<select className="field mt-2" value={exception.exception_type} onChange={(event) => setException({ ...exception, exception_type: event.target.value })}>{EXCEPTION_TYPE_OPTIONS.filter((option) => !(exceptionIsLeadership && option.value === 'remote')).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
            </div>
            <p className="text-xs text-slate-500">Folga, falta, férias e afastamento não são exceção de escala: lance na tela Ausências.</p>
            <label className="label">Observação<input className="field mt-2" maxLength="1000" value={exception.note} onChange={(event) => setException({ ...exception, note: event.target.value })} /></label>
            {exceptionError && <InlineAlert tone="danger" title="Exceção não salva">{exceptionError}</InlineAlert>}
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
                <p className="text-sm text-slate-300">{preview.valid_count} válida(s), {preview.invalid_count} inválida(s){preview.ignored_count ? `, ${preview.ignored_count} ignorada(s)` : ''}.</p>
                <div className="max-h-56 overflow-auto rounded-lg border border-white/5">
                  {preview.rows.map((row) => <div className="border-b border-white/5 px-3 py-2 text-sm" key={row.line}><span className={row.ignored ? 'text-amber-300' : row.valid ? 'text-emerald-300' : 'text-red-300'}>Linha {row.line}</span> - {row.employee_name || row.ad_login || 'Não identificado'} - {row.ignored ? (row.warnings || []).join(' ') : row.rule_type || row.errors.join(' ')}</div>)}
                </div>
                <button className="btn-primary" disabled={preview.invalid_count > 0 || preview.valid_count === 0} onClick={confirmImport} type="button">Confirmar importação</button>
              </div>
            )}
          </section>
        </div>
      )}
      <OperationalShell resource={resource}>
        {(resource.data?.rules || []).filter((item) => item.is_current || item.is_future).length > 0 && (
          <section className="card table-wrap">
            <h2 className="mb-4 font-bold text-white">Regras vigentes e agendadas</h2>
            <table className="data-table">
              <thead><tr><th>Colaborador</th><th>Equipe</th><th>Regra</th><th>Vigência</th><th>Ações</th></tr></thead>
              <tbody>{(resource.data?.rules || []).filter((item) => item.is_current || item.is_future).map((item) => (
                <tr key={item.id ?? `implicita-${item.employee_id}`}>
                  <td><div className="flex min-w-0 items-start gap-3"><UserAvatar name={item.employee_name} /><div><strong>{item.employee_name}</strong><small>{item.ad_login || 'Sem login AD'}</small></div></div></td>
                  <td className="whitespace-nowrap">{teamLabel(item.team)}</td>
                  <td><span className={`status-badge ${scheduleRuleTone(item.rule_type)}`}>{item.rule_label || scheduleRuleLabel(item.rule_type, item.weekdays || [])}</span></td>
                  <td>
                    <span className={`status-badge ${item.is_current ? 'status-success' : 'status-info'}`}>{item.is_current ? 'Vigente' : 'Agendada'}</span>
                    <small className="block">{item.implicit ? `Dias úteis, ${item.work_start}–${item.work_end}` : `${formatDate(item.effective_from)}${item.effective_until ? ` até ${formatDate(item.effective_until)}` : ''}`}</small>
                  </td>
                  <td>
                    {item.implicit ? <span>Regra fixa da Liderança; exceções só pelo admin</span> : (
                    <div className="flex flex-wrap gap-2">
                      {canManage && <button className="table-action" onClick={() => editRule(item)} type="button">Editar</button>}
                      {canRemoveRule ? <button className="table-action text-red-300" onClick={() => removeRule(item)} type="button">Remover regra</button> : <span>Restrito a admin</span>}
                      <button aria-expanded={String(historyEmployee?.id) === String(item.employee_id)} className="table-action" onClick={() => setHistoryEmployee(String(historyEmployee?.id) === String(item.employee_id) ? null : { id: item.employee_id, name: item.employee_name })} type="button">Histórico</button>
                    </div>
                    )}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </section>
        )}
        {historyEmployee && (
          <section className="card table-wrap">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-bold text-white">Histórico de {historyEmployee.name}</h2>
              <button className="table-action" onClick={() => setHistoryEmployee(null)} type="button">Fechar</button>
            </div>
            <OperationalShell resource={history}>
              <table className="data-table">
                <thead><tr><th>Regra</th><th>Vigência</th><th>Situação</th><th>Alterada por</th></tr></thead>
                <tbody>{scheduleHistoryRows(history.data?.rules, historyEmployee.id).map((item) => (
                  <tr key={item.id}>
                    <td><span className={`status-badge ${scheduleRuleTone(item.rule_type)}`}>{item.rule_label || scheduleRuleLabel(item.rule_type, item.weekdays || [])}</span></td>
                    <td>{formatDate(item.effective_from)}{item.effective_until ? ` até ${formatDate(item.effective_until)}` : ' em diante'}</td>
                    <td><span className={`status-badge ${item.is_current ? 'status-success' : item.is_future ? 'status-info' : 'status-neutral'}`}>{item.is_current ? 'Vigente' : item.is_future ? 'Agendada' : 'Encerrada'}</span></td>
                    <td>{item.updated_by || item.created_by || '—'}</td>
                  </tr>
                ))}</tbody>
              </table>
            </OperationalShell>
          </section>
        )}
        {(resource.data?.generated || []).length === 0 ? <EmptyState title="Nenhuma escala gerada" description="Cadastre regras fixas para gerar automaticamente os dias presenciais e remotos." /> : (
          <section className="card table-wrap">
            <label className="mb-4 inline-flex items-center gap-2 text-sm text-slate-300">
              <input checked={hideNoSchedule} className="h-4 w-4" type="checkbox" onChange={(event) => setHideNoSchedule(event.target.checked)} />
              Ocultar sem escala
            </label>
            <table className="data-table">
              <thead><tr><th>Data</th><th>Colaborador</th><th>Equipe</th><th>Modalidade</th><th>Origem</th></tr></thead>
              <tbody>{generatedRows.map((item) => <tr key={`${item.employee_id}-${item.date}`}><td>{formatDate(item.date)}</td><td><strong>{item.employee_name}</strong><small>{item.rule_label || scheduleRuleLabel(item.rule_type, item.weekdays || [])}</small></td><td className="whitespace-nowrap">{teamLabel(item.team)}</td><td>{presenceLegendItem(item.presence_type) ? <PresenceBadge status={item.presence_type} label={statusLabel(item.presence_type)} /> : <Status value={item.presence_type} />}{(item.presence_type === 'absence' || rowPartialTime(item) !== null) && item.detalhe && <small className="mt-1 flex items-center gap-1">{rowPartialTime(item) !== null && <PartialMarker time={rowPartialTime(item)} />}{item.detalhe}</small>}</td><td>{scheduleSourceLabel(item)}</td></tr>)}</tbody>
            </table>
            {generatedRows.length === 0 && <p className="mt-3 text-sm text-slate-500">Todos os dias do filtro estão sem escala.</p>}
            <p className="mt-3 text-xs text-slate-500">Dias úteis de segunda a sexta; sábado e domingo só aparecem com exceção de trabalho. Quem não tem regra aparece como “Sem escala”. Ausência aparece sem a categoria.</p>
          </section>
        )}
      </OperationalShell>
    </div>
  )
}

const SAME_TIME_MESSAGE = 'Hora de entrada igual à hora de saída. Informe o horário real de saída; se a hora extra passou da meia-noite, a saída fica menor que a entrada.'

// Espelha OperationalService::overtimeMinutes: saída menor que a entrada é virada
// de dia; entrada igual à saída é recusada (antes virava 24 h). O servidor valida.
function overtimePreview(form) {
  const [startHour, startMinute] = String(form.start_time || '00:00').split(':').map(Number)
  const [endHour, endMinute] = String(form.end_time || '00:00').split(':').map(Number)
  if (![startHour, startMinute, endHour, endMinute].every(Number.isFinite)) return { minutes: 0, overnight: false, sameTime: false }
  const start = startHour * 60 + startMinute
  let end = endHour * 60 + endMinute
  if (end === start) return { minutes: 0, overnight: false, sameTime: true }
  const overnight = end < start
  if (overnight) end += 24 * 60
  return { minutes: end - start, overnight, sameTime: false }
}

function WorkflowPage({ kind, session, notify }) {
  const overtime = kind === 'overtime'
  const endpoint = overtime ? 'portal/overtime.php' : 'portal/time_corrections.php'
  const employees = useEmployees()
  const canApprove = isManagerRole(session.role)
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
    if (overtime && preview.sameTime) {
      notify(SAME_TIME_MESSAGE, 'error')
      return
    }
    await submit(
      () => post(endpoint, { action: 'create', ...form, employee_id: Number(form.employee_id) }),
      resource.refresh,
      notify,
      ACTION_FEEDBACK.requestCreated,
      () => setForm(overtime
        ? { ...form, reason: '', justification: '' }
        : { ...form, justification: '', recorded_time: '' }),
    )
  }

  async function decide(id, decision) {
    await submit(() => post(endpoint, { action: 'decision', id, decision }), resource.refresh, notify, decisionFeedback(decision))
  }

  const items = resource.data?.items || []
  const pending = items.filter((item) => item.status === 'pending')
  const history = items.filter((item) => item.status !== 'pending')
  // Totais do servidor: cobrem o filtro inteiro e nunca somam rejeitados (BIZ-01).
  const totals = resource.data?.totals || {}
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
        {overtime ? (
          <>
            <MetricCard detail="Horas aprovadas no período; rejeitadas não entram" icon="chart" label="Total aprovado" tone="success" value={minutesLabel(totals.approved_minutes)} />
            <MetricCard detail="Horas aguardando decisão no período" icon="timer" label="Total pendente" tone="warning" value={minutesLabel(totals.pending_minutes)} />
          </>
        ) : (
          <>
            <MetricCard detail="Itens aprovados ou rejeitados" icon="file" label="Histórico" value={history.length} />
            <MetricCard detail="Ajustes de ponto listados" icon="chart" label="Total filtrado" tone="info" value={items.length} />
          </>
        )}
      </section>
      <TruncationNotice limit={resource.data?.limit} truncated={resource.data?.truncated}>
        Há mais {noun}s neste filtro do que os exibidos. Refine os filtros para vê-los; a planilha exportada traz todas as linhas{overtime ? ' e os totais acima já consideram o filtro inteiro' : ''}.
      </TruncationNotice>
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
                {preview.sameTime && <small className="mt-1 block text-red-300" role="alert">{SAME_TIME_MESSAGE}</small>}
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
  const canManage = isManagerRole(session.role)
  const employees = useEmployees()
  const [filters, setFilters] = useState({ from: currentCompetency.start, to: currentCompetency.end, team: '' })
  const resource = useResource(`portal/oncall.php${queryString(filters)}`)
  const [form, setForm] = useState({ employee_id: '', starts_on: today, ends_on: today, start_time: '18:00', end_time: '08:00', shift_type: 'oncall', note: '' })

  async function create(event) {
    event.preventDefault()
    await submit(
      () => post('portal/oncall.php', { ...form, employee_id: Number(form.employee_id) }),
      resource.refresh,
      notify,
      ACTION_FEEDBACK.oncallCreated,
      () => setForm({ ...form, employee_id: '', note: '' }),
    )
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
  const {
    clearErrors,
    formState: { errors },
    handleSubmit,
    register,
    setError,
    setFocus,
  } = useForm({
    defaultValues: filters,
  })
  const reportsQuery = useQuery({
    queryKey: queryKeys.reports(filters),
    queryFn: () => api(`portal/reports.php${queryString(filters)}`),
  })
  const resource = {
    data: reportsQuery.data,
    loading: reportsQuery.isLoading,
    error: reportsQuery.error,
    refresh: async () => {
      const result = await reportsQuery.refetch()
      return result.data ?? null
    },
  }
  const employees = useEmployees()
  const indicators = useMemo(() => {
    const summary = resource.data?.summary || {}
    return [
      ['Horas extras aprovadas', minutesLabel(summary.overtime_approved_minutes)],
      ['Horas extras pendentes', minutesLabel(summary.overtime_pending_minutes)],
      ['Ajustes de ponto', summary.adjustments_count || 0],
      ['Plantões', summary.oncall_count || 0],
      ['Dias presenciais', summary.onsite_days || 0],
      ['Dias remotos', summary.remote_days || 0],
      // [F5, D4] Só quando o período alcança o corte (o servidor manda os campos).
      ...('absence_days' in summary ? [['Dias de ausência', summary.absence_days || 0]] : []),
      ...('no_schedule_days' in summary ? [['Dias sem escala', summary.no_schedule_days || 0]] : []),
      ['Pendências', summary.pending || 0],
      ['Rejeitados', summary.rejected || 0],
      ['Erros de sync', summary.sync_errors || 0],
    ]
  }, [resource.data?.summary])
  const cutoffNotice = presenceCutoffNotice(resource.data?.cutoff)
  const cutoffTitle = presenceCutoffTitle(resource.data?.cutoff)
  function applyReportFilters(values) {
    const parsed = reportFiltersSchema.safeParse(values)
    if (!parsed.success) {
      clearErrors()
      const issue = parsed.error.issues[0]
      const field = issue?.path?.[0] || 'competency'
      setError(field, { type: 'zod', message: issue?.message || 'Filtro invalido.' })
      setFocus(field)
      return
    }
    clearErrors()
    setFilters(parsed.data)
  }

  return (
    <div className="space-y-5">
      <form className="card grid gap-3 md:grid-cols-[repeat(3,minmax(0,1fr))_auto] md:items-start" onSubmit={handleSubmit(applyReportFilters)}>
        <label className="label">Competência
          <input aria-invalid={Boolean(errors.competency)} className="field mt-2" required type="month" {...register('competency')} />
          {errors.competency && <span className="mt-1 block text-xs text-red-300">{errors.competency.message}</span>}
        </label>
        <label className="label">Equipe
          <select aria-invalid={Boolean(errors.team)} className="field mt-2" {...register('team')}>
            <option value="">Todas</option>
            <option value="n1">N1</option>
            <option value="n2">N2</option>
          </select>
          {errors.team && <span className="mt-1 block text-xs text-red-300">{errors.team.message}</span>}
        </label>
        <label className="label">Colaborador
          <select aria-invalid={Boolean(errors.employee_id)} className="field mt-2" {...register('employee_id')}>
            <option value="">Selecione</option>
            {(employees.data?.funcionarios || []).map((employee) => (
              <option key={employee.id} value={employee.id}>{employee.nome} - {employee.equipe.toUpperCase()}</option>
            ))}
          </select>
          {errors.employee_id && <span className="mt-1 block text-xs text-red-300">{errors.employee_id.message}</span>}
        </label>
        <button className="btn-secondary mt-7" type="submit">Aplicar filtros</button>
      </form>
      <OperationalShell resource={resource}>
        <>
          {cutoffNotice && <InlineAlert tone="info" title={cutoffTitle}>{cutoffNotice}</InlineAlert>}
          <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{indicators.map(([label, value]) => <article className="card" key={label}><p className="text-sm text-slate-500">{label}</p><p className="mt-3 text-2xl font-black text-white">{value}</p></article>)}</section>
          <section className="card flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 className="font-bold text-white">Competência {resource.data?.competency?.label}</h2><p className="mt-1 text-sm text-slate-500">{formatDate(resource.data?.period?.from)} a {formatDate(resource.data?.period?.to)}. O MySQL permanece como fonte principal.</p></div>
            <a className="btn-primary" href={apiUrl(`portal/reports.php${queryString({ ...filters, format: 'csv' })}`)}>Exportar CSV</a>
          </section>
          <section className="grid gap-5 lg:grid-cols-2">
            <ReportsSummaryTable title="Resumo por colaborador" values={resource.data?.by_employee} />
            <ReportsSummaryTable title="Resumo por equipe" values={resource.data?.by_team} />
          </section>
        </>
      </OperationalShell>
    </div>
  )
}

function ReportsSummaryTable({ title, values }) {
  const data = useMemo(
    () => Object.entries(values || {}).map(([label, value]) => ({ label, value })),
    [values],
  )
  const columns = useMemo(() => [
    {
      accessorKey: 'label',
      header: title,
      cell: ({ getValue }) => <strong className="text-slate-200">{getValue()}</strong>,
    },
    {
      accessorKey: 'value',
      header: 'Resumo',
      cell: ({ getValue }) => {
        const value = getValue() || {}
        return (
          <span className="text-right text-slate-500">
            {minutesLabel(value.overtime_approved_minutes)} HE aprovada(s), {minutesLabel(value.overtime_pending_minutes)} pendente(s), {value.adjustments || 0} ajuste(s), {value.oncall || 0} plantão(ões), {value.onsite_days || 0} presencial(is)
            {'absence_days' in value && `, ${value.absence_days} ausência(s)`}
            {'no_schedule_days' in value && `, ${value.no_schedule_days} sem escala`}
          </span>
        )
      },
    },
  ], [title])
  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
  })

  return (
    <section className="card">
      <h2 className="mb-4 font-bold text-white">{title}</h2>
      {data.length === 0 ? <p className="text-sm text-slate-500">Sem dados no período.</p> : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              {table.getHeaderGroups().map((headerGroup) => (
                <tr key={headerGroup.id}>
                  {headerGroup.headers.map((header) => (
                    <th key={header.id}>{flexRender(header.column.columnDef.header, header.getContext())}</th>
                  ))}
                </tr>
              ))}
            </thead>
            <tbody>
              {table.getRowModel().rows.map((row) => (
                <tr key={row.id}>
                  {row.getVisibleCells().map((cell) => (
                    <td key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
