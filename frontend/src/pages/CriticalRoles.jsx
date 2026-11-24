import { lazy, Suspense, useRef, useState } from 'react'
import { ConfirmDiscardDialog } from '../components/ui/ConfirmDiscardDialog'
import { Drawer } from '../components/ui/Drawer'
import { Field } from '../components/ui/Field'
import { Segmented } from '../components/ui/Segmented'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Table } from '../components/ui/Table'
import { Tag } from '../components/ui/Tag'
import { InlineAlert, SectionHeader } from '../components/ui/Primitives'
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard'
import { post } from '../lib/api'
import { runAction } from '../lib/actionRunner'
import { formatDate } from '../lib/format'
import { hasPermission } from '../lib/roles'
import { OVERRIDE_MAX_DAYS, PERIOD_OPTIONS, PRESENCE_LABELS, REASON_LABELS, anchorFor, changePoints, moveItem, roleLabel, toggleId, validShift } from '../lib/criticalRoles'
import { isCriticalTeam } from '../lib/teams'
import { removeAt, slotsSummary } from '../lib/criticalGrid'

// [D-ESC-13] Cor do papel: componentes carregados sob demanda (CSS da paleta fora do CSS principal).
const RoleColorPicker = lazy(() => import('./criticalGrid/RoleColor').then((module) => ({ default: module.RoleColorPicker })))
const RoleSwatch = lazy(() => import('./criticalGrid/RoleColor').then((module) => ({ default: module.RoleSwatch })))

const ENDPOINT = 'portal/critical_roles.php'

function emptyRole() {
  return { role_id: null, name: '', presence_type: 'remote', work_start: '07:00', work_end: '17:00', active: true, sort_order: 0, color_token: '' }
}

// Papéis: lista e formulário (criar ou editar).
function RolesPanel({ roles, canManage, notify, onChanged, onDirty }) {
  const [form, setForm] = useState(null)
  const [busy, setBusy] = useState(false)
  const shiftOk = form ? validShift(form.work_start, form.work_end) : true
  const update = (patch) => { setForm((current) => ({ ...current, ...patch })); onDirty(true) }

  async function save() {
    setBusy(true)
    await runAction({
      action: () => post(ENDPOINT, { action: 'save_role', ...form, sort_order: Number(form.sort_order) || 0, color_token: form.color_token || '' }),
      notify,
      onSuccess: () => { setForm(null); onDirty(false); onChanged() },
    })
    setBusy(false)
  }

  const columns = [
    { key: 'nome', label: 'Papel', render: (role) => <span className="flex flex-wrap items-center gap-2"><strong>{role.name}</strong>{!role.active && <Tag tone="neutral">Desativado</Tag>}</span> },
    { key: 'tipo', label: 'Presença', render: (role) => <Tag tone={role.presence_type === 'onsite' ? 'success' : 'info'}>{PRESENCE_LABELS[role.presence_type]}</Tag> },
    { key: 'jornada', label: 'Jornada', className: 'whitespace-nowrap', render: (role) => `${role.work_start}–${role.work_end}` },
    { key: 'cor', label: 'Cor', render: (role) => <Suspense fallback={<span className="text-xs text-muted">…</span>}><RoleSwatch token={role.color_token} /></Suspense> },
    { key: 'acoes', label: 'Ações', render: (role) => (canManage ? <button className="table-action" data-role-edit={role.id} onClick={() => setForm({ ...role, role_id: role.id })} type="button">Editar</button> : <span className="text-xs text-muted">—</span>) },
  ]

  return (
    <section className="space-y-3" data-critical-roles-list="">
      <h3 className="font-semibold text-ink">Papéis</h3>
      {roles.length === 0 ? <p className="text-sm text-muted">Nenhum papel cadastrado.</p> : <Table caption="Papéis do Crítico" cards className="min-w-0" columns={columns} rowKey={(role) => role.id} rows={roles} />}
      {canManage && !form && <button className="btn-secondary" data-role-new="" onClick={() => setForm(emptyRole())} type="button">Novo papel</button>}
      {form && (
        <div className="space-y-3 rounded-lg border border-line bg-surface-2 p-3" data-role-form="">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Nome do papel">
              <input maxLength="60" onChange={(event) => update({ name: event.target.value })} value={form.name} />
            </Field>
            <Field label="Presença">
              <select onChange={(event) => update({ presence_type: event.target.value })} value={form.presence_type}>
                <option value="onsite">Presencial</option>
                <option value="remote">Remoto</option>
              </select>
            </Field>
            <Field error={shiftOk ? null : 'Início antes do fim, no mesmo dia.'} label="Início da jornada">
              <input onChange={(event) => update({ work_start: event.target.value })} type="time" value={form.work_start} />
            </Field>
            <Field label="Fim da jornada">
              <input onChange={(event) => update({ work_end: event.target.value })} type="time" value={form.work_end} />
            </Field>
            <Suspense fallback={<span className="text-xs text-muted">Carregando cores…</span>}>
              <RoleColorPicker onChange={(color_token) => update({ color_token })} value={form.color_token} />
            </Suspense>
            <Field help="Menor aparece primeiro." label="Ordem">
              <input inputMode="numeric" max="999" min="0" onChange={(event) => update({ sort_order: event.target.value })} type="number" value={form.sort_order} />
            </Field>
            {form.role_id !== null && (
              <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 self-end text-sm text-ink-2">
                <input checked={form.active} className="h-4 w-4" onChange={(event) => update({ active: event.target.checked })} type="checkbox" />
                Papel ativo
              </label>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
            <button className="btn-primary" disabled={busy || !form.name.trim() || !shiftOk} onClick={save} type="button">{form.role_id === null ? 'Criar papel' : 'Salvar papel'}</button>
            <button className="btn-secondary" onClick={() => { setForm(null); onDirty(false) }} type="button">Cancelar</button>
          </div>
        </div>
      )}
    </section>
  )
}

// Lista ordenada com marcação e setas (pessoas ou papéis do rodízio).
function OrderedPicker({ legend, items, selected, onChange, kind }) {
  const chosen = selected.map((id) => items.find((item) => item.id === id)).filter(Boolean)
  const others = items.filter((item) => !selected.includes(item.id))
  return (
    <fieldset className="space-y-2" data-ordered-picker={kind}>
      <legend className="text-sm font-semibold text-ink">{legend}</legend>
      <ol className="space-y-1">
        {chosen.map((item, index) => (
          <li className="flex flex-wrap items-center gap-2 rounded-md border border-line px-2 py-1" data-picked={item.id} key={item.id}>
            <span className="min-w-6 text-xs text-muted">{index + 1}.</span>
            <span className="flex-1 text-sm">{item.label}</span>
            <button aria-label={`Subir ${item.label}`} className="btn-secondary btn-sm" disabled={index === 0} onClick={() => onChange(moveItem(selected, index, -1))} type="button">↑</button>
            <button aria-label={`Descer ${item.label}`} className="btn-secondary btn-sm" disabled={index === chosen.length - 1} onClick={() => onChange(moveItem(selected, index, 1))} type="button">↓</button>
            <button aria-label={`Tirar ${item.label}`} className="btn-secondary btn-sm" onClick={() => onChange(toggleId(selected, item.id))} type="button">Tirar</button>
          </li>
        ))}
      </ol>
      {others.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {others.map((item) => <button className="btn-secondary btn-sm" data-pick={item.id} key={item.id} onClick={() => onChange(toggleId(selected, item.id))} type="button">+ {item.label}</button>)}
        </div>
      )}
    </fieldset>
  )
}

// [D-ESC-15] Vagas do rodízio: o mesmo papel pode entrar mais de uma vez
// ("Presencial x2"); as vagas ficam na ordem, com setas e "Tirar" pela posição.
function SlotPicker({ legend, roles, slots, onChange }) {
  const byId = new Map(roles.map((role) => [role.id, role]))
  return (
    <fieldset className="space-y-2" data-ordered-picker="vagas">
      <legend className="text-sm font-semibold text-ink">{legend}</legend>
      <ol className="space-y-1">
        {slots.map((id, index) => {
          const label = byId.get(id)?.label ?? `ID ${id}`
          return (
            <li className="flex flex-wrap items-center gap-2 rounded-md border border-line px-2 py-1" data-picked-slot={id} key={`${id}-${index}`}>
              <span className="min-w-6 text-xs text-muted">{index + 1}.</span>
              <span className="flex-1 text-sm">{label}</span>
              <button aria-label={`Subir a vaga ${index + 1} (${label})`} className="btn-secondary btn-sm" disabled={index === 0} onClick={() => onChange(moveItem(slots, index, -1))} type="button">↑</button>
              <button aria-label={`Descer a vaga ${index + 1} (${label})`} className="btn-secondary btn-sm" disabled={index === slots.length - 1} onClick={() => onChange(moveItem(slots, index, 1))} type="button">↓</button>
              <button aria-label={`Tirar a vaga ${index + 1} (${label})`} className="btn-secondary btn-sm" onClick={() => onChange(removeAt(slots, index))} type="button">Tirar</button>
            </li>
          )
        })}
      </ol>
      <div className="flex flex-wrap gap-2">
        {roles.map((role) => <button className="btn-secondary btn-sm" data-pick-slot={role.id} key={role.id} onClick={() => onChange([...slots, role.id])} type="button">+ {role.label}</button>)}
      </div>
      {slots.length > 0 && <p className="text-xs text-muted" data-slots-summary="">Vagas: {slotsSummary(slots, new Map(roles.map((role) => [role.id, role.name]))).join(', ')}</p>}
    </fieldset>
  )
}

// Rodízio: versões e a versão nova, com a prévia obrigatória do servidor (9.16.4).
function RotationPanel({ data, people, canManage, canRetroactive, notify, onChanged, onDirty }) {
  const activeRoles = data.roles.filter((role) => role.active)
  const current = data.rotations.find((rotation) => rotation.is_current) ?? null
  const [form, setForm] = useState(() => ({
    effective_from: data.today,
    period: current?.period ?? 'week',
    anchor_date: anchorFor(current?.period ?? 'week', data.today),
    employees: (current?.employees ?? []).filter((id) => people.some((person) => person.id === id)),
    roles: (current?.roles ?? []).filter((id) => activeRoles.some((role) => role.id === id)),
  }))
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState(false)
  const roleById = new Map(data.roles.map((role) => [role.id, role]))
  const nameById = new Map(people.map((person) => [person.id, person.label]))
  const key = JSON.stringify(form)
  const fresh = preview !== null && preview.key === key
  const update = (patch) => { setForm((value) => ({ ...value, ...patch })); onDirty(true) }
  const incomplete = form.employees.length === 0 || form.roles.length === 0 || !form.effective_from || !form.anchor_date

  async function runPreview() {
    setBusy(true)
    await runAction({
      action: () => post(ENDPOINT, { action: 'preview_rotation', ...form }),
      notify,
      successMessage: null,
      onSuccess: (result) => setPreview({ key, data: result }),
    })
    setBusy(false)
  }

  async function save() {
    setBusy(true)
    await runAction({
      action: () => post(ENDPOINT, { action: 'save_rotation', ...form }),
      notify,
      onSuccess: () => { setPreview(null); onDirty(false); onChanged() },
    })
    setBusy(false)
  }

  return (
    <section className="space-y-3" data-critical-rotation="">
      <h3 className="font-semibold text-ink">Rodízio</h3>
      {data.rotations.length === 0 ? <p className="text-sm text-muted">Nenhum rodízio salvo: a jornada de cada um vem do cadastro.</p> : (
        <Table caption="Versões do rodízio de papéis" cards className="min-w-0" columns={[
          { key: 'desde', label: 'Desde', className: 'whitespace-nowrap', render: (rotation) => formatDate(rotation.effective_from) },
          { key: 'periodo', label: 'Troca', render: (rotation) => <Tag tone={rotation.is_current ? 'success' : 'neutral'}>{rotation.period === 'week' ? 'Por semana' : 'Por mês'}</Tag> },
          { key: 'pessoas', label: 'Pessoas', render: (rotation) => rotation.employees.map((id) => nameById.get(id) || `ID ${id}`).join(', ') },
          { key: 'vagas', label: 'Vagas', render: (rotation) => slotsSummary(rotation.roles, new Map(data.roles.map((role) => [role.id, role.name]))).join(', ') },
          { key: 'aviso', label: 'Situação', render: (rotation) => (rotation.invalid_people > 0 ? <Tag tone="danger">{rotation.invalid_people} fora do Crítico</Tag> : <span className="text-xs text-muted">—</span>) },
        ]} rowKey={(rotation) => rotation.id} rows={data.rotations} />
      )}
      {canManage && (
        <div className="space-y-3 rounded-lg border border-line bg-surface-2 p-3" data-rotation-form="">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field help={canRetroactive ? 'Data anterior a hoje muda a jornada do passado e fica auditada.' : 'A partir de hoje.'} label="Nova versão a partir de">
              <input min={canRetroactive ? undefined : data.today} onChange={(event) => update({ effective_from: event.target.value, anchor_date: anchorFor(form.period, event.target.value) })} type="date" value={form.effective_from} />
            </Field>
            <Field help={form.period === 'week' ? 'Segunda-feira do período 1.' : 'Dia 1 do mês do período 1.'} label="Início da contagem (âncora)">
              <input onChange={(event) => update({ anchor_date: event.target.value })} type="date" value={form.anchor_date} />
            </Field>
          </div>
          <Segmented label="Troca de papel" onChange={(period) => update({ period, anchor_date: anchorFor(period, form.effective_from) })} options={PERIOD_OPTIONS} value={form.period} />
          <div className="grid gap-4 sm:grid-cols-2">
            <OrderedPicker items={people} kind="pessoas" legend="Pessoas, na ordem" onChange={(employees) => update({ employees })} selected={form.employees} />
            <SlotPicker legend="Vagas (papéis), na ordem" onChange={(roles) => update({ roles })} roles={activeRoles.map((role) => ({ id: role.id, name: role.name, label: roleLabel(role) }))} slots={form.roles} />
          </div>
          <p className="text-xs text-muted">No período 1, a 1ª pessoa fica com a 1ª vaga; a cada período, todos avançam uma posição. O mesmo papel pode ocupar mais de uma vaga (Presencial x2). Pessoa a mais fica pelo cadastro; vaga sem pessoa aparece como papel vago.</p>
          {preview && (
            <div className={`space-y-3 rounded-lg border p-3 ${fresh ? 'border-line bg-surface' : 'border-warn-line bg-warn-bg'}`} data-rotation-preview="">
              <p className="text-xs font-semibold text-muted">Prévia das próximas 8 semanas (calculada pelo servidor; só os dias em que algum papel muda)</p>
              {!fresh && <InlineAlert tone="warning" title="Prévia desatualizada">Um campo mudou depois da prévia. Gere a prévia de novo para poder salvar.</InlineAlert>}
              <Table caption="Prévia do rodízio" cards className="min-w-0" columns={[
                { key: 'data', label: 'A partir de', className: 'whitespace-nowrap', render: (day) => formatDate(day.date) },
                ...form.employees.map((id) => ({ key: `p${id}`, label: nameById.get(id) || `ID ${id}`, render: (day) => {
                  const role = roleById.get(day.roles[String(id)])
                  return role ? roleLabel(role) : <span className="text-muted">Pelo cadastro</span>
                } })),
                { key: 'vagos', label: 'Vagos', render: (day) => (day.vacant.length === 0 ? '—' : day.vacant.map((id) => roleById.get(id)?.name || `ID ${id}`).join(', ')) },
              ]} rowKey={(day) => day.date} rows={changePoints(preview.data.preview)} />
              {preview.data.replaces && <p className="text-xs text-muted">Corrige a versão que começa nessa data.</p>}
              {preview.data.retroactive && <InlineAlert tone="warning" title="Início retroativo">Muda a jornada do passado e fica auditado.</InlineAlert>}
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <button className="btn-secondary" data-rotation-preview-run="" disabled={busy || incomplete} onClick={runPreview} type="button">{busy ? 'Calculando...' : 'Ver prévia'}</button>
            <button className="btn-primary" data-rotation-save="" disabled={busy || incomplete || !fresh} onClick={save} type="button">Salvar rodízio</button>
          </div>
          {!fresh && <p className="text-xs text-muted">Salvar fica disponível depois da prévia calculada pelo servidor.</p>}
        </div>
      )}
    </section>
  )
}

// Trocas pontuais (AD-PC-3: só a gestão), com motivo em lista fechada.
function OverridesPanel({ data, people, canManage, canRetroactive, notify, onChanged, onDirty }) {
  const empty = { employee_id: '', starts_on: data.today, ends_on: data.today, role_id: '', reason: 'troca' }
  const [form, setForm] = useState(empty)
  const [busy, setBusy] = useState(false)
  const roleById = new Map(data.roles.map((role) => [role.id, role]))
  const nameById = new Map(people.map((person) => [person.id, person.label]))
  const update = (patch) => { setForm((value) => ({ ...value, ...patch })); onDirty(true) }

  async function submit(body, done) {
    setBusy(true)
    await runAction({ action: () => post(ENDPOINT, body), notify, onSuccess: () => { done(); onDirty(false); onChanged() } })
    setBusy(false)
  }

  return (
    <section className="space-y-3" data-critical-overrides="">
      <h3 className="font-semibold text-ink">Trocas pontuais</h3>
      {data.overrides.length === 0 ? <p className="text-sm text-muted">Nenhuma troca de hoje em diante.</p> : (
        <Table caption="Trocas pontuais de papel" cards className="min-w-0" columns={[
          { key: 'nome', label: 'Técnico', render: (item) => nameById.get(item.employee_id) || `ID ${item.employee_id}` },
          { key: 'periodo', label: 'Período', className: 'whitespace-nowrap', render: (item) => (item.starts_on === item.ends_on ? formatDate(item.starts_on) : `${formatDate(item.starts_on)} a ${formatDate(item.ends_on)}`) },
          { key: 'papel', label: 'Papel', render: (item) => (roleById.get(item.role_id) ? roleLabel(roleById.get(item.role_id)) : `ID ${item.role_id}`) },
          { key: 'motivo', label: 'Motivo', render: (item) => REASON_LABELS[item.reason] || item.reason },
          { key: 'acoes', label: 'Ações', render: (item) => (canManage && (canRetroactive || item.starts_on >= data.today)
            ? <button className="table-action" data-override-remove={item.id} disabled={busy} onClick={() => submit({ action: 'remove_override', override_id: item.id }, () => {})} type="button">Remover</button>
            : <span className="text-xs text-muted">—</span>) },
        ]} rowKey={(item) => item.id} rows={data.overrides} />
      )}
      {canManage && (
        <div className="space-y-3 rounded-lg border border-line bg-surface-2 p-3" data-override-form="">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Técnico">
              <select onChange={(event) => update({ employee_id: event.target.value })} value={form.employee_id}>
                <option value="">Selecione</option>
                {people.map((person) => <option key={person.id} value={person.id}>{person.label}</option>)}
              </select>
            </Field>
            <Field label="Papel no período">
              <select onChange={(event) => update({ role_id: event.target.value })} value={form.role_id}>
                <option value="">Selecione</option>
                {data.roles.filter((role) => role.active).map((role) => <option key={role.id} value={role.id}>{roleLabel(role)}</option>)}
              </select>
            </Field>
            <Field label="De">
              <input min={canRetroactive ? undefined : data.today} onChange={(event) => update({ starts_on: event.target.value, ends_on: form.ends_on < event.target.value ? event.target.value : form.ends_on })} type="date" value={form.starts_on} />
            </Field>
            <Field help={`Até ${OVERRIDE_MAX_DAYS} dias; para mais, salve uma versão do rodízio.`} label="Até">
              <input min={form.starts_on} onChange={(event) => update({ ends_on: event.target.value })} type="date" value={form.ends_on} />
            </Field>
            <Field label="Motivo">
              <select onChange={(event) => update({ reason: event.target.value })} value={form.reason}>
                {data.reasons.map((reason) => <option key={reason} value={reason}>{REASON_LABELS[reason] || reason}</option>)}
              </select>
            </Field>
          </div>
          <button className="btn-primary" disabled={busy || !form.employee_id || !form.role_id || !form.starts_on || !form.ends_on} onClick={() => submit({ action: 'add_override', ...form, employee_id: Number(form.employee_id), role_id: Number(form.role_id) }, () => setForm(empty))} type="button">Registrar troca</button>
          <p className="text-xs text-muted">Só em dia útil: no fim de semana e no feriado, o papel vem só por exceção por data.</p>
        </div>
      )}
    </section>
  )
}

function CriticalRolesDrawer({ data, people, canManage, canRetroactive, notify, onClose, onChanged, triggerRef }) {
  const [dirtyParts, setDirtyParts] = useState({})
  const headingRef = useRef(null)
  const guard = useUnsavedChangesGuard(Object.values(dirtyParts).some(Boolean))
  const markDirty = (part) => (value) => setDirtyParts((current) => ({ ...current, [part]: value }))
  const shared = { canManage, canRetroactive, notify, onChanged }
  return (
    <Drawer
      description="O papel do dia dá a jornada e a presença de cada um do Crítico; a troca pontual vence o rodízio. Ausência e exceção por data continuam valendo antes."
      initialFocusRef={headingRef}
      onClose={() => guard.requestLeave(onClose)}
      returnFocusRef={triggerRef}
      size="max-w-3xl"
      title="Papéis do Crítico"
    >
      <div className="space-y-6" data-critical-drawer="" ref={headingRef} tabIndex={-1}>
        <RolesPanel {...shared} onDirty={markDirty('roles')} roles={data.roles} />
        <RotationPanel {...shared} data={data} key={JSON.stringify(data.rotations.map((rotation) => rotation.id))} onDirty={markDirty('rotation')} people={people} />
        <OverridesPanel {...shared} data={data} onDirty={markDirty('overrides')} people={people} />
      </div>
      <ConfirmDiscardDialog {...guard.dialogProps} />
    </Drawer>
  )
}

// [9.16] Seção "Papéis do Crítico" da tela Escala: hoje, por pessoa, e os
// avisos (papel vago, rodízio com quem saiu do Crítico); gravação com
// escalas.manage (Liderança e admin). O servidor confere tudo de novo.
export function CriticalRolesSection({ session, notify, employees, resource }) {
  const canManage = hasPermission(session, 'escalas.manage')
  const canRetroactive = hasPermission(session, 'escalas.retroativa')
  const [open, setOpen] = useState(false)
  const triggerRef = useRef(null)
  const data = resource.data
  const people = (employees || []).filter((employee) => isCriticalTeam(employee.equipe) && employee.ativo !== false).map((employee) => ({ id: Number(employee.id), label: employee.nome }))
  const roleById = new Map((data?.roles || []).map((role) => [role.id, role]))
  const current = (data?.rotations || []).find((rotation) => rotation.is_current) ?? null
  const invalid = (data?.rotations || []).reduce((total, rotation) => total + rotation.invalid_people, 0)

  const columns = [
    { key: 'nome', label: 'Técnico', render: (person) => <strong>{person.label}</strong> },
    { key: 'papel', label: 'Papel hoje', render: (person) => {
      const today = data?.today_roles?.[String(person.id)]
      const role = today ? roleById.get(today.role_id) : null
      if (!role) return <span className="text-muted">Pelo cadastro</span>
      return <span className="flex flex-wrap items-center gap-2">{roleLabel(role)}<Tag tone={role.presence_type === 'onsite' ? 'success' : 'info'}>{PRESENCE_LABELS[role.presence_type]}</Tag>{today.source === 'troca' && <Tag tone="warning">Troca</Tag>}</span>
    } },
  ]

  return (
    <section className="card space-y-4" data-critical-roles="">
      <SectionHeader
        action={<button className="btn-secondary" data-critical-open="" disabled={!data} onClick={(event) => { triggerRef.current = event.currentTarget; setOpen(true) }} type="button">{canManage ? 'Papéis e rodízio' : 'Ver papéis'}</button>}
        description="Abertura, fechamento e presencial rodam entre as pessoas do Crítico. O papel do dia muda a jornada e a presença; fora de dia útil, nenhum papel."
        eyebrow="Escala"
        title="Papéis do Crítico"
      />
      {resource.loading && !data ? <LoadingState /> : resource.error ? <ErrorState message={resource.error.message} onRetry={resource.refresh} /> : (
        <>
          {invalid > 0 && <InlineAlert tone="danger" title="Rodízio com quem saiu do Crítico">{invalid} pessoa(s) do rodízio arquivada(s) ou fora do Crítico: salve uma versão nova sem elas.</InlineAlert>}
          {(data?.vacant_today || []).length > 0 && <InlineAlert tone="warning" title="Papel vago hoje">{data.vacant_today.map((id) => (roleById.get(id) ? roleLabel(roleById.get(id)) : `ID ${id}`)).join(', ')}</InlineAlert>}
          {people.length === 0 ? <EmptyState description="Ninguém ativo na equipe Crítico." title="Sem pessoas no Crítico" /> : (
            <>
              {!current && <p className="text-sm text-muted">Nenhum rodízio vigente: a jornada de cada um vem do cadastro.</p>}
              <Table caption="Papel de hoje no Crítico" cards columns={columns} rowKey={(person) => person.id} rows={people} />
            </>
          )}
        </>
      )}
      {open && data && <CriticalRolesDrawer canManage={canManage} canRetroactive={canRetroactive} data={data} notify={notify} onChanged={resource.refresh} onClose={() => setOpen(false)} people={people} triggerRef={triggerRef} />}
    </section>
  )
}
