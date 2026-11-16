import { useRef, useState } from 'react'
import { ConfirmDiscardDialog } from '../components/ui/ConfirmDiscardDialog'
import { Drawer } from '../components/ui/Drawer'
import { Field } from '../components/ui/Field'
import { Segmented } from '../components/ui/Segmented'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Table } from '../components/ui/Table'
import { Tag } from '../components/ui/Tag'
import { InlineAlert, SectionHeader } from '../components/ui/Primitives'
import { useResource } from '../hooks/useResource'
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard'
import { post } from '../lib/api'
import { runAction } from '../lib/actionRunner'
import { formatDate } from '../lib/format'
import { hasPermission } from '../lib/roles'
import {
  CYCLE_MAX_POSITIONS,
  CYCLE_MAX_WEEKS,
  WEEK_DAYS,
  cycleError,
  cycleLabel,
  defaultWeek,
  isMonday,
  nextMonday,
  resizeWeeks,
  shiftPattern,
  toggleDay,
  weeksToPattern,
} from '../lib/rotationTeams'

const WEEK_DAY_NAMES = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta']
const VERSION_MODES = [
  { value: 'weekly', label: 'Semana a semana' },
  { value: 'pattern', label: 'Lista de dias' },
  { value: 'copy', label: 'Copiar de outro time' },
]

function letters(pattern) {
  return (pattern || []).map((value) => (value === 'onsite' ? 'P' : 'H')).join(' ')
}

// [F10-2] Grade semana x dia (desenho do F10, 3.2): de 1 a 4 semanas, seg a sex;
// cada dia alterna entre presencial (P) e home (H). Serve para a regra
// individual e para o time; o padrão é a concatenação das semanas.
export function WeeklyCycleEditor({ weeks, onChange, label = 'Semanas no ciclo' }) {
  return (
    <div className="space-y-3" data-weekly-editor="">
      <Segmented
        label={label}
        onChange={(value) => onChange(resizeWeeks(weeks, Number(value)))}
        options={Array.from({ length: CYCLE_MAX_WEEKS }, (_, index) => ({ value: String(index + 1), label: index === 0 ? '1 semana' : `${index + 1} semanas` }))}
        value={String(weeks.length)}
      />
      {weeks.map((row, week) => (
        <div aria-label={`Semana ${week + 1}`} data-week={week + 1} key={week} role="group">
          <p className="text-xs font-semibold text-muted">Semana {week + 1}</p>
          <div className="mt-1 grid grid-cols-5 gap-1">
            {row.map((value, day) => (
              <button
                aria-label={`${WEEK_DAY_NAMES[day]} da semana ${week + 1}: ${value === 'onsite' ? 'presencial' : 'home'}. Trocar.`}
                className={value === 'onsite' ? 'btn-primary btn-sm' : 'btn-secondary btn-sm'}
                data-day={WEEK_DAYS[day]}
                data-value={value}
                key={day}
                onClick={() => onChange(toggleDay(weeks, week, day))}
                type="button"
              >
                {WEEK_DAYS[day]} {value === 'onsite' ? 'P' : 'H'}
              </button>
            ))}
          </div>
        </div>
      ))}
      <p className="text-xs text-muted" data-weekly-label="">{cycleLabel(weeksToPattern(weeks), weeks.length)}. P = presencial, H = home. A semana 1 começa na data inicial, que precisa ser uma segunda-feira.</p>
    </div>
  )
}

// Lista de dias úteis (2 a 20), para o padrão livre do time (o RODÍZIO de 3).
function PatternEditor({ pattern, onChange }) {
  return (
    <div className="space-y-3" data-pattern-editor="">
      <Field help={`De 2 a ${CYCLE_MAX_POSITIONS} dias úteis; o ciclo recomeça depois do último.`} label="Dias no ciclo">
        <input inputMode="numeric" max={CYCLE_MAX_POSITIONS} min="2" onChange={(event) => {
          const size = Math.min(Math.max(Number(event.target.value) || 2, 2), CYCLE_MAX_POSITIONS)
          onChange(Array.from({ length: size }, (_, index) => pattern[index] || 'onsite'))
        }} type="number" value={pattern.length} />
      </Field>
      <div className="grid grid-cols-5 gap-1">
        {pattern.map((value, index) => (
          <button
            aria-label={`Dia ${index + 1} do ciclo: ${value === 'onsite' ? 'presencial' : 'home'}. Trocar.`}
            className={value === 'onsite' ? 'btn-primary btn-sm' : 'btn-secondary btn-sm'}
            data-position={index + 1}
            key={index}
            onClick={() => onChange(pattern.map((item, position) => (position === index ? (item === 'onsite' ? 'remote' : 'onsite') : item)))}
            type="button"
          >
            {index + 1} {value === 'onsite' ? 'P' : 'H'}
          </button>
        ))}
      </div>
    </div>
  )
}

function emptyVersion(today) {
  return { effective_from: today, mode: 'weekly', weeks: [defaultWeek()], pattern: ['remote', 'onsite', 'onsite'], anchor_date: nextMonday(today), copy_team_id: '', copy_shift: '1' }
}

// Padrão e âncora que a versão vai gravar, pelo modo escolhido.
function versionPayload(version, teams) {
  if (version.mode === 'weekly') {
    return { cycle: { weeks: version.weeks, anchor_date: version.anchor_date }, pattern: weeksToPattern(version.weeks), weeks: version.weeks.length, anchor: version.anchor_date }
  }
  if (version.mode === 'copy') {
    const source = teams.find((team) => String(team.id) === String(version.copy_team_id))?.current_version
    if (!source) return null
    const pattern = shiftPattern(source.pattern, Number(version.copy_shift) || 0)
    return { cycle: { pattern, anchor_date: source.anchor_date }, pattern, weeks: null, anchor: source.anchor_date }
  }
  return { cycle: { pattern: version.pattern, anchor_date: version.anchor_date }, pattern: version.pattern, weeks: null, anchor: version.anchor_date }
}

function VersionForm({ teamId, teams, today, canRetroactive, notify, onSaved, onDirty, creating = false, name = '' }) {
  const [version, setVersion] = useState(() => emptyVersion(today))
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState(false)
  const update = (patch) => {
    setVersion((current) => ({ ...current, ...patch }))
    onDirty(true)
  }
  const payload = versionPayload(version, teams.filter((team) => team.id !== teamId))
  const clientError = payload === null ? 'Escolha o time de origem (com padrão vigente).' : cycleError(payload.pattern, payload.anchor, version.mode === 'weekly')
  const key = JSON.stringify([teamId, version.effective_from, payload?.cycle ?? null])
  const fresh = preview !== null && preview.key === key

  async function runPreview() {
    if (clientError || creating) return
    setBusy(true)
    await runAction({
      action: () => post('portal/rotation_teams.php', { action: 'preview_version', team_id: teamId, effective_from: version.effective_from, cycle: payload.cycle }),
      notify,
      successMessage: null,
      onSuccess: (result) => setPreview({ key, data: result }),
    })
    setBusy(false)
  }

  async function save() {
    setBusy(true)
    const body = creating
      ? { action: 'save_team', name, version: { effective_from: version.effective_from, cycle: payload.cycle } }
      : { action: 'save_version', team_id: teamId, effective_from: version.effective_from, cycle: payload.cycle }
    await runAction({
      action: () => post('portal/rotation_teams.php', body),
      notify,
      successMessage: null,
      onSuccess: (result) => {
        notify(result?.mensagem || 'Padrão do time salvo.')
        setPreview(null)
        setVersion(emptyVersion(today))
        onDirty(false)
        onSaved(result)
      },
    })
    setBusy(false)
  }

  return (
    <div className="space-y-4" data-version-form="">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field help={canRetroactive ? 'Data anterior a hoje reescreve a escala dos membros e fica auditada.' : 'A partir de hoje.'} label={creating ? 'Padrão vale a partir de' : 'Nova versão a partir de'}>
          <input min={canRetroactive ? undefined : today} onChange={(event) => update({ effective_from: event.target.value })} required type="date" value={version.effective_from} />
        </Field>
        {version.mode !== 'copy' && (
          <Field error={version.mode === 'weekly' && !isMonday(version.anchor_date) ? 'Escolha uma segunda-feira.' : null} help={version.mode === 'weekly' ? 'Segunda-feira da semana 1.' : 'Dia útil da posição 1.'} label="Data inicial do ciclo (âncora)">
            <input onChange={(event) => update({ anchor_date: event.target.value })} required type="date" value={version.anchor_date} />
          </Field>
        )}
      </div>
      <Segmented label="Montar o padrão" onChange={(mode) => update({ mode })} options={creating ? VERSION_MODES.filter((mode) => mode.value !== 'copy' || teams.length > 0) : VERSION_MODES} value={version.mode} />
      {version.mode === 'weekly' && <WeeklyCycleEditor onChange={(weeks) => update({ weeks })} weeks={version.weeks} />}
      {version.mode === 'pattern' && <PatternEditor onChange={(pattern) => update({ pattern })} pattern={version.pattern} />}
      {version.mode === 'copy' && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Time de origem">
            <select onChange={(event) => update({ copy_team_id: event.target.value })} value={version.copy_team_id}>
              <option value="">Selecione</option>
              {teams.filter((team) => team.id !== teamId && team.current_version).map((team) => <option key={team.id} value={team.id}>{team.name} ({team.current_version.label})</option>)}
            </select>
          </Field>
          <Field help="Com a mesma âncora; 1 dia útil faz o Time 1 (H P P) virar o Time 2 (P H P)." label="Deslocado em (dias úteis)">
            <input inputMode="numeric" max="19" min="0" onChange={(event) => update({ copy_shift: event.target.value })} type="number" value={version.copy_shift} />
          </Field>
        </div>
      )}
      {payload && <p className="text-sm text-ink-2" data-version-result="">Resultado: {letters(payload.pattern)} · {cycleLabel(payload.pattern, payload.weeks)}</p>}
      {clientError && <InlineAlert tone="warning" title="Padrão incompleto">{clientError}</InlineAlert>}
      {preview && (
        <div className={`space-y-3 rounded-lg border p-3 ${fresh ? 'border-line bg-surface-2' : 'border-warn-line bg-warn-bg'}`} data-version-preview="">
          <p className="text-xs font-semibold text-muted">Prévia das próximas 4 semanas (calculada pelo servidor)</p>
          {!fresh && <InlineAlert tone="warning" title="Prévia desatualizada">Um campo mudou depois da prévia. Gere a prévia de novo para poder salvar.</InlineAlert>}
          <div className="grid grid-cols-5 gap-1">
            {preview.data.preview.map((day) => (
              <span className={`status-badge justify-center ${day.presence_type === 'onsite' ? 'status-success' : 'status-info'}`} key={day.date}>{day.date.slice(8, 10)}/{day.date.slice(5, 7)} {day.presence_type === 'onsite' ? 'P' : 'H'}</span>
            ))}
          </div>
          <p className="text-sm text-ink-2" data-version-impact="">{preview.data.members_changed} membro(s) mudam a partir de {formatDate(preview.data.effective_from)}.{preview.data.replaces ? ' Corrige a versão que começa nessa data.' : ''}</p>
          {preview.data.members_blocked > 0 && <InlineAlert tone="danger" title="Não dá para salvar">{preview.data.members_blocked} membro(s) com regra individual agendada depois dessa data: o time não sobrescreve.</InlineAlert>}
          {preview.data.members_inactive > 0 && <p className="text-xs text-muted">{preview.data.members_inactive} cadastro(s) arquivado(s) no time: não recebem regra.</p>}
          {preview.data.retroactive && <InlineAlert tone="warning" title="Início retroativo">Reescreve a escala dos membros desde essa data e fica auditado.</InlineAlert>}
        </div>
      )}
      <div className="flex flex-wrap gap-2">
        {!creating && <button className="btn-secondary" disabled={busy || Boolean(clientError)} onClick={runPreview} type="button">{busy ? 'Calculando...' : 'Ver prévia'}</button>}
        <button className="btn-primary" disabled={busy || Boolean(clientError) || (!creating && (!fresh || preview.data.members_blocked > 0)) || (creating && !name.trim())} onClick={save} type="button">{creating ? 'Criar time' : 'Salvar padrão'}</button>
      </div>
      {!creating && !fresh && <p className="text-xs text-muted">Salvar fica disponível depois da prévia calculada pelo servidor.</p>}
    </div>
  )
}

function memberState(member, today) {
  if (member.valid_until !== null && member.valid_until < today) return ['Encerrada', 'neutral']
  if (member.valid_from > today) return ['Agendada', 'info']
  return ['Vigente', 'success']
}

function MembersPanel({ team, teams, employees, today, canManage, canRetroactive, notify, onChanged, onDirty }) {
  const [showHistory, setShowHistory] = useState(false)
  const history = useResource('portal/rotation_teams.php?historico=1', { enabled: showHistory })
  const [add, setAdd] = useState({ employee_id: '', valid_from: today })
  const [action, setAction] = useState(null)
  const [busy, setBusy] = useState(false)
  const members = showHistory ? (history.data?.teams || []).find((item) => item.id === team.id)?.members || [] : team.members
  const byId = new Map((employees || []).map((employee) => [Number(employee.id), employee]))
  const busyIds = new Set(teams.flatMap((item) => item.members.map((member) => Number(member.employee_id))))
  const eligible = (employees || []).filter((employee) => ['n1', 'n2'].includes(String(employee.equipe).toLowerCase()) && employee.fora_mapa_escala !== true && employee.ativo !== false && !busyIds.has(Number(employee.id)))

  async function submit(body, done) {
    setBusy(true)
    await runAction({
      action: () => post('portal/rotation_teams.php', body),
      notify,
      successMessage: null,
      onSuccess: (result) => {
        notify(result?.mensagem || 'Time atualizado.')
        done()
        onDirty(false)
        onChanged()
      },
    })
    setBusy(false)
  }

  const columns = [
    { key: 'nome', label: 'Técnico', render: (member) => {
      const employee = byId.get(Number(member.employee_id))
      return employee ? <strong>{employee.nome}</strong> : <span className="text-muted">Cadastro arquivado (ID {member.employee_id})</span>
    } },
    { key: 'desde', label: 'Desde', className: 'whitespace-nowrap', render: (member) => formatDate(member.valid_from) },
    { key: 'ate', label: 'Até', className: 'whitespace-nowrap', render: (member) => (member.valid_until ? formatDate(member.valid_until) : 'em diante') },
    { key: 'situacao', label: 'Situação', render: (member) => {
      const [label, tone] = memberState(member, today)
      return <span className="flex flex-wrap gap-2"><Tag tone={tone}>{label}</Tag>{!member.employee_active && <Tag tone="warning">Arquivado</Tag>}</span>
    } },
    { key: 'acoes', label: 'Ações', render: (member) => {
      if (!canManage || (member.valid_until !== null && member.valid_until < today)) return <span className="text-xs text-muted">—</span>
      if (action?.memberId === member.id) {
        return (
          <div className="space-y-2" data-member-action={action.kind}>
            {action.kind === 'move' && (
              <select aria-label="Time de destino" className="field" onChange={(event) => setAction({ ...action, team_id: event.target.value })} value={action.team_id}>
                <option value="">Time de destino</option>
                {teams.filter((item) => item.id !== team.id && item.active).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            )}
            <input aria-label={action.kind === 'move' ? 'Início no time novo' : 'Último dia no time'} className="field" min={canRetroactive ? undefined : today} onChange={(event) => setAction({ ...action, date: event.target.value })} type="date" value={action.date} />
            <div className="flex flex-wrap gap-2">
              <button className="btn-primary btn-sm" disabled={busy || !action.date || (action.kind === 'move' && !action.team_id)} onClick={() => submit(
                action.kind === 'move'
                  ? { action: 'move_member', team_id: Number(action.team_id), employee_id: member.employee_id, valid_from: action.date }
                  : { action: 'end_member', member_id: member.id, valid_until: action.date },
                () => setAction(null),
              )} type="button">{action.kind === 'move' ? 'Mover' : 'Encerrar'}</button>
              <button className="btn-secondary btn-sm" onClick={() => { setAction(null); onDirty(false) }} type="button">Cancelar</button>
            </div>
            {action.kind === 'end' && <p className="text-xs text-muted">A regra fica como está até outra regra. Antes do início, cancela a entrada.</p>}
          </div>
        )
      }
      return (
        <div className="flex flex-wrap gap-2">
          {member.employee_active && <button className="table-action" onClick={() => { setAction({ kind: 'move', memberId: member.id, team_id: '', date: today }); onDirty(true) }} type="button">Mover</button>}
          <button className="table-action" onClick={() => { setAction({ kind: 'end', memberId: member.id, date: today }); onDirty(true) }} type="button">Encerrar</button>
        </div>
      )
    } },
  ]

  return (
    <section className="space-y-3" data-team-members="">
      <h3 className="font-semibold text-ink">Membros</h3>
      <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm text-ink-2">
        <input checked={showHistory} className="h-4 w-4" onChange={(event) => setShowHistory(event.target.checked)} type="checkbox" />
        Mostrar as pertenças encerradas
      </label>
      {showHistory && history.loading && <LoadingState />}
      {members.length === 0 ? <p className="text-sm text-muted">Nenhum técnico no time.</p> : <Table caption={`Membros do ${team.name}`} cards className="min-w-0" columns={columns} rowKey={(member) => member.id} rows={members} />}
      {canManage && team.active && (
        <div className="space-y-3 rounded-lg border border-line bg-surface-2 p-3" data-member-add="">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field help="Técnicos N1 e N2 fora de outro time." label="Incluir técnico">
              <select onChange={(event) => { setAdd({ ...add, employee_id: event.target.value }); onDirty(event.target.value !== '') }} value={add.employee_id}>
                <option value="">Selecione</option>
                {eligible.map((employee) => <option key={employee.id} value={employee.id}>{employee.nome}</option>)}
              </select>
            </Field>
            <Field help="A regra do técnico passa a vir do time nessa data." label="No time a partir de">
              <input min={canRetroactive ? undefined : today} onChange={(event) => setAdd({ ...add, valid_from: event.target.value })} type="date" value={add.valid_from} />
            </Field>
          </div>
          <button className="btn-primary" disabled={busy || !add.employee_id || !add.valid_from || !team.current_version} onClick={() => submit({ action: 'add_member', team_id: team.id, employee_id: Number(add.employee_id), valid_from: add.valid_from }, () => setAdd({ employee_id: '', valid_from: today }))} type="button">Incluir no time</button>
          {!team.current_version && <p className="text-xs text-muted">Cadastre o padrão do time antes de incluir técnicos.</p>}
        </div>
      )}
    </section>
  )
}

function TeamDrawer({ team, teams, employees, today, canManage, canRetroactive, notify, onClose, onChanged, triggerRef }) {
  const [name, setName] = useState(team?.name ?? '')
  const [active, setActive] = useState(team?.active ?? true)
  const [dirtyParts, setDirtyParts] = useState({})
  const nameRef = useRef(null)
  const creating = team === null
  const dirty = Object.values(dirtyParts).some(Boolean) || name !== (team?.name ?? '') || active !== (team?.active ?? true)
  const guard = useUnsavedChangesGuard(dirty)
  const markDirty = (part) => (value) => setDirtyParts((current) => ({ ...current, [part]: value }))

  async function saveName() {
    await runAction({
      action: () => post('portal/rotation_teams.php', { action: 'save_team', team_id: team.id, name, active }),
      notify,
      onSuccess: () => onChanged(),
    })
  }

  return (
    <Drawer
      description={creating ? 'O time é a fonte da escala dos membros: ao incluir um técnico, a regra dele passa a vir do time.' : 'Mudar o padrão grava a regra de cada membro a partir da data escolhida; o histórico fica.'}
      initialFocusRef={nameRef}
      onClose={() => guard.requestLeave(onClose)}
      returnFocusRef={triggerRef}
      size="max-w-2xl"
      title={creating ? 'Novo time de rodízio' : team.name}
    >
      <div className="space-y-6" data-team-drawer={creating ? 'novo' : team.id}>
        <div className="space-y-3">
          <Field label="Nome do time">
            <input disabled={!canManage} maxLength="60" onChange={(event) => setName(event.target.value)} ref={nameRef} value={name} />
          </Field>
          {!creating && canManage && (
            <div className="flex flex-wrap items-center gap-3">
              <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm text-ink-2">
                <input checked={active} className="h-4 w-4" onChange={(event) => setActive(event.target.checked)} type="checkbox" />
                Time ativo
              </label>
              <button className="btn-secondary btn-sm" disabled={!name.trim() || (name === team.name && active === team.active)} onClick={saveName} type="button">Salvar nome e situação</button>
            </div>
          )}
        </div>
        {!creating && (
          <section className="space-y-3">
            <h3 className="font-semibold text-ink">Padrão do time</h3>
            {team.versions.length === 0 ? <p className="text-sm text-muted">Sem padrão cadastrado.</p> : (
              <Table caption={`Versões do padrão do ${team.name}`} cards className="min-w-0" columns={[
                { key: 'desde', label: 'Desde', className: 'whitespace-nowrap', render: (version) => formatDate(version.effective_from) },
                { key: 'padrao', label: 'Padrão', render: (version) => <span className="flex flex-wrap items-center gap-2"><Tag tone={team.current_version?.id === version.id ? 'success' : 'neutral'}>{version.label}</Tag><span className="text-xs text-muted">{letters(version.pattern)}</span></span> },
                { key: 'ancora', label: 'Âncora', className: 'whitespace-nowrap', render: (version) => formatDate(version.anchor_date) },
              ]} rowKey={(version) => version.id} rows={team.versions} />
            )}
          </section>
        )}
        {canManage && (
          <section className="space-y-3">
            {!creating && <h3 className="font-semibold text-ink">Nova versão do padrão</h3>}
            <VersionForm canRetroactive={canRetroactive} creating={creating} name={name} notify={notify} onDirty={markDirty('version')} onSaved={() => { onChanged(); if (creating) onClose() }} teamId={team?.id ?? null} teams={teams} today={today} />
          </section>
        )}
        {!creating && <MembersPanel canManage={canManage} canRetroactive={canRetroactive} employees={employees} notify={notify} onChanged={onChanged} onDirty={markDirty('members')} team={team} teams={teams} today={today} />}
      </div>
      <ConfirmDiscardDialog {...guard.dialogProps} />
    </Drawer>
  )
}

// [F10-2] Seção "Times de rodízio" da tela Escala (desenho do F10, 3.1): leitura
// para a gestão; criar, mudar o padrão e os membros com escalas.manage
// (D-F10-5: Liderança e admin). O servidor confere tudo de novo.
export function RotationTeamsSection({ session, notify, employees, resource, onChanged }) {
  const canManage = hasPermission(session, 'escalas.manage')
  const canRetroactive = hasPermission(session, 'escalas.retroativa')
  const [openId, setOpenId] = useState(null)
  const triggerRef = useRef(null)
  const teams = resource.data?.teams || []
  const today = resource.data?.today || ''
  const open = openId === 'novo' ? null : teams.find((team) => team.id === openId) ?? undefined
  const byId = new Map((employees || []).map((employee) => [Number(employee.id), employee.nome]))

  function openTeam(id, trigger) {
    triggerRef.current = trigger
    setOpenId(id)
  }

  const columns = [
    { key: 'nome', label: 'Time', render: (team) => <span className="flex flex-wrap items-center gap-2"><strong>{team.name}</strong>{!team.active && <Tag tone="neutral">Desativado</Tag>}</span> },
    { key: 'padrao', label: 'Padrão vigente', render: (team) => (team.current_version ? <span className="flex flex-wrap items-center gap-2"><Tag tone="info">{team.current_version.label}</Tag><span className="text-xs text-muted">{letters(team.current_version.pattern)}</span></span> : <span className="text-muted">Sem padrão</span>) },
    { key: 'membros', label: 'Membros', render: (team) => {
      const current = team.members.filter((member) => member.is_current)
      return current.length === 0 ? <span className="text-muted">Nenhum</span> : <span>{current.map((member) => byId.get(Number(member.employee_id)) || `ID ${member.employee_id}`).join(', ')}</span>
    } },
    { key: 'acoes', label: 'Ações', render: (team) => <button className="table-action" data-team-open={team.id} onClick={(event) => openTeam(team.id, event.currentTarget)} type="button">{canManage ? 'Abrir' : 'Ver'}</button> },
  ]

  return (
    <section className="card space-y-4" data-rotation-teams="">
      <SectionHeader
        action={canManage ? <button className="btn-secondary" data-team-new="" onClick={(event) => openTeam('novo', event.currentTarget)} type="button">Novo time</button> : null}
        description="A regra de quem está num time vem do time: muda pelo time, com histórico. Exceção por data continua por técnico."
        eyebrow="Escala"
        title="Times de rodízio"
      />
      {resource.loading && !resource.data ? <LoadingState /> : resource.error ? <ErrorState message={resource.error.message} onRetry={resource.refresh} /> : teams.length === 0 ? (
        <EmptyState description={canManage ? 'Crie o primeiro time e cadastre o padrão dele.' : 'Nenhum time cadastrado.'} title="Nenhum time de rodízio" />
      ) : (
        <Table caption="Times de rodízio" cards columns={columns} rowKey={(team) => team.id} rows={teams} />
      )}
      {openId !== null && open !== undefined && (
        <TeamDrawer canManage={canManage} canRetroactive={canRetroactive} employees={employees} key={openId} notify={notify} onChanged={() => { resource.refresh(); onChanged?.() }} onClose={() => setOpenId(null)} team={open} teams={teams} today={today} triggerRef={triggerRef} />
      )}
    </section>
  )
}

