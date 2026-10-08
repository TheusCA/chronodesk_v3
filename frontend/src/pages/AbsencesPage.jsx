import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { FilterBar, InlineAlert, SectionHeader, TruncationNotice, UserAvatar } from '../components/ui/Primitives'
import { useResource } from '../hooks/useResource'
import { post } from '../lib/api'
import { ACTION_FEEDBACK } from '../lib/actionFeedback'
import { runAction } from '../lib/actionRunner'
import { formatDate } from '../lib/format'
import {
  ABSENCE_CATEGORIES,
  ABSENCE_NOTE_MAX_CHARS,
  ABSENCE_RETROACTIVE_MESSAGE,
  absenceFormSchema,
} from '../lib/formSchemas'
import { localDate, queryString } from '../lib/operational'
import { hasPermission, isManagerRole } from '../lib/roles'

// [F3, RF-04] Ausências (docs/DESENHO_PA_ESCALA_AUSENCIA.md, 5.4 e 9.4).
// A tela só antecipa as regras do servidor; quem decide é portal/absences.php
// (perfil, retroativo, Liderança, jornada atual, sobreposição).

const today = localDate()
const CATEGORY_LABELS = Object.fromEntries(ABSENCE_CATEGORIES.map((option) => [option.value, option.label]))
const FORM_FIELDS = ['employee_id', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time', 'category', 'note']
const NOTE_WARNING_ID = 'absence-note-warning'

function monthPeriod(date) {
  const [year, month] = date.split('-').map(Number)
  return { from: `${date.slice(0, 8)}01`, to: localDate(new Date(year, month, 0)) }
}

function emptyAbsence() {
  return { employee_id: '', starts_on: today, ends_on: today, period_type: 'full_day', start_time: '', end_time: '', category: 'folga', note: '' }
}

function isLeadershipTeam(team) {
  return ['lideranca', 'liderança'].includes(String(team || '').toLowerCase())
}

function teamLabel(team) {
  return isLeadershipTeam(team) ? 'Liderança' : String(team || '').toUpperCase()
}

const hhmm = (value) => String(value || '').slice(0, 5)

function periodLabel(item) {
  if (item.period_type === 'from_time') return `A partir de ${hhmm(item.start_time)}`
  if (item.period_type === 'interval') return `${hhmm(item.start_time)}–${hhmm(item.end_time)}`
  return 'Dia todo'
}

function datesLabel(item) {
  return item.starts_on === item.ends_on ? formatDate(item.starts_on) : `${formatDate(item.starts_on)} a ${formatDate(item.ends_on)}`
}

function buildAbsencePayload(data, editingId) {
  const payload = {
    action: editingId ? 'update' : 'create',
    employee_id: Number(data.employee_id),
    starts_on: data.starts_on,
    ends_on: data.ends_on,
    period_type: data.period_type,
    category: data.category,
    note: data.note,
  }
  if (editingId) payload.id = editingId
  if (data.period_type !== 'full_day') payload.start_time = data.start_time
  if (data.period_type === 'interval') payload.end_time = data.end_time
  return payload
}

function FieldError({ error }) {
  return error ? <span className="mt-1 block text-xs text-red-300">{error.message}</span> : null
}

export function AbsencesPage({ session, notify }) {
  const canManage = hasPermission(session, 'ausencias.manage')
  const canRetroactive = hasPermission(session, 'ausencias.retroativa')
  const canManageLeadership = hasPermission(session, 'escalas.lideranca')
  // P9: categoria e observação só para a gestão e para o próprio técnico.
  const seesAllDetails = isManagerRole(session.role)
  const ownEmployeeId = Number(session.ci?.funcionario_id || 0)
  const canSeeDetails = (item) => seesAllDetails || (ownEmployeeId > 0 && Number(item.employee_id) === ownEmployeeId)

  const [filters, setFilters] = useState({ ...monthPeriod(today), team: '', employee_id: '', status: 'approved' })
  const resource = useResource(`portal/absences.php${queryString(filters)}`)
  const employees = useResource('listar_funcionarios.php', { enabled: canManage })
  const allEmployees = employees.data?.funcionarios || []
  // Lançamento: cadastro ativo; Liderança só para quem tem a permissão da L9.
  const formEmployees = allEmployees.filter((employee) => employee.ativo !== false && (canManageLeadership || !isLeadershipTeam(employee.equipe)))

  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)
  const [confirmCancelId, setConfirmCancelId] = useState(null)
  const {
    clearErrors,
    formState: { errors },
    handleSubmit,
    register,
    reset,
    setError,
    setFocus,
    setValue,
    watch,
  } = useForm({ defaultValues: emptyAbsence() })
  const values = watch()
  const singleDay = Boolean(values.starts_on) && values.starts_on === values.ends_on
  const noteLength = Array.from(String(values.note || '')).length
  const selectedEmployee = allEmployees.find((employee) => String(employee.id) === String(values.employee_id))
  const editingStarted = editing !== null && editing.starts_on <= today
  const retroactiveMark = canRetroactive && (Boolean(values.starts_on && values.starts_on < today) || editingStarted)

  function openNew() {
    setEditing(null)
    setFormError('')
    clearErrors()
    reset(emptyAbsence())
    setFormOpen(true)
  }

  function closeForm() {
    setEditing(null)
    setFormError('')
    clearErrors()
    reset(emptyAbsence())
    setFormOpen(false)
  }

  function editAbsence(item) {
    setConfirmCancelId(null)
    setEditing(item)
    setFormError('')
    clearErrors()
    reset({
      employee_id: String(item.employee_id),
      starts_on: item.starts_on,
      ends_on: item.ends_on,
      period_type: item.period_type || 'full_day',
      start_time: hhmm(item.start_time),
      end_time: hhmm(item.end_time),
      category: item.category || 'folga',
      note: item.note || '',
    })
    setFormOpen(true)
  }

  // Zod no cliente (o servidor valida de novo); devolve os dados ou null.
  function parseAbsence(formValues) {
    const workday = selectedEmployee ? { start: selectedEmployee.jornada_entrada, end: selectedEmployee.jornada_saida } : null
    const parsed = absenceFormSchema({ today, canRetroactive, workday }).safeParse(formValues)
    if (parsed.success) {
      clearErrors()
      return parsed.data
    }
    clearErrors()
    let firstField = null
    const marked = new Set()
    for (const issue of parsed.error.issues) {
      const field = FORM_FIELDS.includes(issue.path?.[0]) ? issue.path[0] : 'employee_id'
      if (!firstField) firstField = field
      // Uma mensagem por campo: a primeira, na ordem do schema.
      if (marked.has(field)) continue
      marked.add(field)
      setError(field, { type: 'zod', message: issue.message || 'Campo inválido.' })
    }
    if (firstField && firstField !== 'period_type' && !(editing && firstField === 'employee_id')) setFocus(firstField)
    return null
  }

  async function saveAbsence(formValues) {
    setFormError('')
    const data = parseAbsence(formValues)
    if (!data) return
    setSaving(true)
    try {
      await runAction({
        action: () => post('portal/absences.php', buildAbsencePayload(data, editing?.id)),
        refresh: resource.refresh,
        notify,
        successMessage: editing ? ACTION_FEEDBACK.absenceUpdated : ACTION_FEEDBACK.absenceCreated,
        onSuccess: () => closeForm(),
        // A mensagem do servidor (409 de sobreposição, 403, 400) fica também junto do formulário.
        onError: (error) => setFormError(error instanceof Error ? error.message : ''),
      })
    } finally {
      setSaving(false)
    }
  }

  async function cancelAbsence(item) {
    await runAction({
      action: () => post('portal/absences.php', { action: 'cancel', id: item.id }),
      refresh: resource.refresh,
      notify,
      successMessage: ACTION_FEEDBACK.absenceCancelled,
      onSuccess: () => {
        setConfirmCancelId(null)
        if (editing?.id === item.id) closeForm()
      },
    })
  }

  // Ações por linha: só antecipam a recusa do servidor (403), que continua valendo.
  function rowRestriction(item) {
    if (item.status !== 'approved') return 'Cancelada'
    if (isLeadershipTeam(item.team) && !canManageLeadership) return 'Liderança: só administradores'
    if (item.starts_on <= today && !canRetroactive) return 'Já iniciada: só administradores e Liderança'
    return null
  }

  function onDateChange(field) {
    return (event) => {
      const value = event.target.value
      const startsOn = field === 'starts_on' ? value : values.starts_on
      let endsOn = field === 'ends_on' ? value : values.ends_on
      if (field === 'starts_on' && (!endsOn || endsOn < value)) {
        endsOn = value
        setValue('ends_on', value, { shouldDirty: true })
      }
      // P5: vários dias é sempre dia inteiro.
      if (startsOn !== endsOn && values.period_type !== 'full_day') setValue('period_type', 'full_day', { shouldDirty: true })
      clearErrors(['starts_on', 'ends_on', 'period_type'])
    }
  }

  // P9: o único lugar que mostra categoria e observação (tabela e cartões).
  function categoryCell(item) {
    if (!canSeeDetails(item)) return <span>Ausência</span>
    return (
      <>
        <span>{CATEGORY_LABELS[item.category] || 'Ausência'}</span>
        {item.note && <small>{item.note}</small>}
      </>
    )
  }

  function statusCell(item) {
    return (
      <span className="shrink-0 text-right">
        <span className={`status-badge ${item.status === 'approved' ? 'status-success' : 'status-neutral'}`}>{item.status === 'approved' ? 'Ativa' : 'Cancelada'}</span>
        {Number(item.retroactive) === 1 && <small className="mt-1 block text-xs text-slate-500">Retroativa</small>}
      </span>
    )
  }

  function actionsCell(item) {
    const restriction = rowRestriction(item)
    if (restriction) return <span className="text-xs">{restriction}</span>
    if (confirmCancelId === item.id) {
      return (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs text-slate-300">Cancelar esta ausência?</span>
          <button className="table-action text-red-300" onClick={() => cancelAbsence(item)} type="button">Confirmar</button>
          <button className="table-action" onClick={() => setConfirmCancelId(null)} type="button">Voltar</button>
        </div>
      )
    }
    return (
      <div className="flex flex-wrap gap-2">
        <button className="table-action" onClick={() => editAbsence(item)} type="button">Editar</button>
        <button className="table-action text-red-300" onClick={() => setConfirmCancelId(item.id)} type="button">Cancelar</button>
      </div>
    )
  }

  const items = resource.data?.items || []

  return (
    <div className="space-y-5">
      <FilterBar actions={canManage && !formOpen ? <button className="btn-primary" onClick={openNew} type="button">Nova ausência</button> : null}>
        <label className="label">De<input className="field mt-2" type="date" value={filters.from} onChange={(event) => setFilters({ ...filters, from: event.target.value })} /></label>
        <label className="label">Até<input className="field mt-2" min={filters.from} type="date" value={filters.to} onChange={(event) => setFilters({ ...filters, to: event.target.value })} /></label>
        {seesAllDetails && (
          <>
            <label className="label">Equipe<select className="field mt-2" value={filters.team} onChange={(event) => setFilters({ ...filters, team: event.target.value })}><option value="">Todas</option><option value="n1">N1</option><option value="n2">N2</option><option value="lideranca">Liderança</option></select></label>
            <label className="label">Técnico<select className="field mt-2" value={filters.employee_id} onChange={(event) => setFilters({ ...filters, employee_id: event.target.value })}>
              <option value="">Todos</option>
              {allEmployees.map((employee) => <option key={employee.id} value={employee.id}>{employee.nome} - {teamLabel(employee.equipe)}</option>)}
            </select></label>
          </>
        )}
        <label className="label">Situação<select className="field mt-2" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="approved">Ativas</option><option value="cancelled">Canceladas</option><option value="all">Todas</option></select></label>
      </FilterBar>

      {canManage && formOpen && (
        <form className="card space-y-4" noValidate onSubmit={handleSubmit(saveAbsence)}>
          <SectionHeader
            description="Lançada já aprovada. Folga, férias, falta e afastamento ficam aqui, não na exceção de escala."
            eyebrow={editing ? 'Edição' : 'Lançamento'}
            title={editing ? 'Editar ausência' : 'Nova ausência'}
          />
          <div className="grid gap-4 md:grid-cols-3">
            {editing ? (
              <div className="label">Técnico
                <p className="field mt-2 cursor-default">{editing.employee_name} - {teamLabel(editing.team)}</p>
                <span className="mt-1 block text-xs text-slate-500">O técnico não muda na edição: para trocar, cancele e lance de novo.</span>
              </div>
            ) : (
              <label className="label">Técnico
                <select aria-invalid={Boolean(errors.employee_id)} className="field mt-2" {...register('employee_id', { onChange: () => clearErrors(['employee_id', 'start_time', 'end_time']) })}>
                  <option value="">Selecione</option>
                  {formEmployees.map((employee) => <option key={employee.id} value={employee.id}>{employee.nome} - {teamLabel(employee.equipe)}</option>)}
                </select>
                <FieldError error={errors.employee_id} />
                {selectedEmployee && <span className="mt-1 block text-xs text-slate-500">Jornada: {hhmm(selectedEmployee.jornada_entrada)}–{hhmm(selectedEmployee.jornada_saida)}</span>}
              </label>
            )}
            <label className="label">De
              <input aria-invalid={Boolean(errors.starts_on)} className="field mt-2" min={canRetroactive ? undefined : today} type="date" {...register('starts_on', { onChange: onDateChange('starts_on') })} />
              <FieldError error={errors.starts_on} />
            </label>
            <label className="label">Até
              <input aria-invalid={Boolean(errors.ends_on)} className="field mt-2" min={values.starts_on || undefined} type="date" {...register('ends_on', { onChange: onDateChange('ends_on') })} />
              <FieldError error={errors.ends_on} />
            </label>
          </div>
          {retroactiveMark && (
            <InlineAlert tone="warning" title="Lançamento retroativo (auditado)">
              {editingStarted ? 'A ausência já começou: a alteração fica marcada como retroativa e registrada na auditoria.' : 'A data inicial é anterior a hoje: o lançamento fica marcado como retroativo e registrado na auditoria.'}
            </InlineAlert>
          )}
          {!canRetroactive && <p className="text-xs text-slate-500">{ABSENCE_RETROACTIVE_MESSAGE}</p>}
          <fieldset className="space-y-3">
            <legend className="label">Período</legend>
            <div className="flex flex-wrap gap-4 text-sm text-slate-300">
              <label className="inline-flex items-center gap-2"><input type="radio" value="full_day" {...register('period_type', { onChange: () => clearErrors(['period_type', 'start_time', 'end_time']) })} />Dia todo</label>
              <label className={`inline-flex items-center gap-2 ${singleDay ? '' : 'opacity-50'}`}><input disabled={!singleDay} type="radio" value="from_time" {...register('period_type', { onChange: () => clearErrors(['period_type', 'start_time', 'end_time']) })} />A partir de</label>
              <label className={`inline-flex items-center gap-2 ${singleDay ? '' : 'opacity-50'}`}><input disabled={!singleDay} type="radio" value="interval" {...register('period_type', { onChange: () => clearErrors(['period_type', 'start_time', 'end_time']) })} />Intervalo</label>
            </div>
            <FieldError error={errors.period_type} />
            {!singleDay && <p className="text-xs text-slate-500">Horário só em ausência de um dia (De igual a Até).</p>}
            {singleDay && values.period_type !== 'full_day' && (
              <div className="grid gap-4 sm:grid-cols-2 md:max-w-md">
                <label className="label">{values.period_type === 'interval' ? 'Das' : 'A partir de'}
                  <input aria-invalid={Boolean(errors.start_time)} className="field mt-2" type="time" {...register('start_time', { onChange: () => clearErrors(['start_time', 'end_time']) })} />
                  <FieldError error={errors.start_time} />
                </label>
                {values.period_type === 'interval' && (
                  <label className="label">Até as
                    <input aria-invalid={Boolean(errors.end_time)} className="field mt-2" type="time" {...register('end_time', { onChange: () => clearErrors('end_time') })} />
                    <FieldError error={errors.end_time} />
                  </label>
                )}
              </div>
            )}
          </fieldset>
          <div className="grid gap-4 md:grid-cols-3">
            <label className="label">Categoria
              <select aria-invalid={Boolean(errors.category)} className="field mt-2" {...register('category')}>
                {ABSENCE_CATEGORIES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </select>
              <FieldError error={errors.category} />
            </label>
            <label className="label md:col-span-2">Observação (opcional)
              <textarea aria-describedby={NOTE_WARNING_ID} aria-invalid={Boolean(errors.note)} className="field mt-2 min-h-20" rows="3" {...register('note', { onChange: () => clearErrors('note') })} />
              <span className="mt-1 flex items-start justify-between gap-3 text-xs">
                <span className="font-semibold text-amber-300" id={NOTE_WARNING_ID}>⚠ Não registre informação médica ou de saúde neste campo.</span>
                <span className={noteLength > ABSENCE_NOTE_MAX_CHARS ? 'text-red-300' : 'text-slate-500'}>{noteLength}/{ABSENCE_NOTE_MAX_CHARS}</span>
              </span>
              <FieldError error={errors.note} />
            </label>
          </div>
          {formError && <InlineAlert tone="danger" title="Não foi possível salvar">{formError}</InlineAlert>}
          <div className="flex flex-wrap gap-2">
            <button className="btn-primary" disabled={saving} type="submit">{saving ? 'Salvando...' : editing ? 'Salvar alteração' : 'Lançar ausência'}</button>
            <button className="btn-secondary" disabled={saving} onClick={closeForm} type="button">{editing ? 'Cancelar edição' : 'Fechar'}</button>
          </div>
        </form>
      )}

      {resource.loading ? <LoadingState /> : resource.error ? <ErrorState message={resource.error.message} onRetry={resource.refresh} /> : (
        <>
          <TruncationNotice limit={resource.data?.limit} truncated={resource.data?.truncated}>
            Refine o período, a equipe ou o técnico para ver os demais.
          </TruncationNotice>
          {items.length === 0 ? (
            <EmptyState
              description={seesAllDetails ? 'Ajuste o período ou a situação nos filtros.' : 'Aqui aparecem só as suas ausências.'}
              title={seesAllDetails ? 'Nenhuma ausência no período' : 'Você não tem ausências no período'}
            />
          ) : (
            <>
              {/* Celular: cartões (a tabela larga escondia categoria, situação e ações). */}
              <section className="space-y-3 md:hidden">
                {items.map((item) => (
                  <article className="card space-y-3" key={item.id}>
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex min-w-0 items-start gap-3">
                        <UserAvatar name={item.employee_name} />
                        <div className="min-w-0"><strong className="block text-slate-200">{item.employee_name}</strong><span className="text-xs text-slate-500">{teamLabel(item.team)}</span></div>
                      </div>
                      {statusCell(item)}
                    </div>
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                      <div><dt className="text-xs text-slate-500">Data(s)</dt><dd className="text-slate-300">{datesLabel(item)}</dd></div>
                      <div><dt className="text-xs text-slate-500">Período</dt><dd className="text-slate-300">{periodLabel(item)}</dd></div>
                      <div className="col-span-2"><dt className="text-xs text-slate-500">Categoria</dt><dd className="text-slate-300 [&_small]:block [&_small]:text-xs [&_small]:text-slate-500">{categoryCell(item)}</dd></div>
                    </dl>
                    {canManage && <div className="border-t border-white/5 pt-3">{actionsCell(item)}</div>}
                  </article>
                ))}
              </section>
              <section className="card table-wrap hidden md:block">
                <table className="data-table">
                  <thead><tr><th>Técnico</th><th className="whitespace-nowrap">Equipe</th><th>Data(s)</th><th>Período</th><th>Categoria</th><th className="whitespace-nowrap">Situação</th>{canManage && <th>Ações</th>}</tr></thead>
                  <tbody>{items.map((item) => (
                    <tr key={item.id}>
                      <td><div className="flex min-w-0 items-start gap-3"><UserAvatar name={item.employee_name} /><strong>{item.employee_name}</strong></div></td>
                      <td className="whitespace-nowrap">{teamLabel(item.team)}</td>
                      <td>{datesLabel(item)}</td>
                      <td>{periodLabel(item)}</td>
                      <td>{categoryCell(item)}</td>
                      <td>{statusCell(item)}</td>
                      {canManage && <td>{actionsCell(item)}</td>}
                    </tr>
                  ))}</tbody>
                </table>
              </section>
            </>
          )}
        </>
      )}
    </div>
  )
}
