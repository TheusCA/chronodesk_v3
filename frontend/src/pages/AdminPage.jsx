import { Fragment, useEffect, useMemo, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useResource } from '../hooks/useResource'
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard'
import { useDecisionConfirm } from '../hooks/useDecisionConfirm'
import { ConfirmDiscardDialog } from '../components/ui/ConfirmDiscardDialog'
import { DecisionConfirmDialog } from '../components/ui/DecisionConfirmDialog'
import { Drawer } from '../components/ui/Drawer'
import { post } from '../lib/api'
import { ACTION_FEEDBACK, employeeCreatedFeedback } from '../lib/actionFeedback'
import { adjustmentTypeLabel } from '../lib/decisionConfirm'
import { runAction } from '../lib/actionRunner'
import { formatDate, formatDateTime } from '../lib/format'
import { archiveEffectsSummary, employeeArchiveState, matchesArchiveFilter } from '../lib/employeeArchive'
import { keepLunchSelection, lunchKey, lunchOptions, matchesLunchFilter } from '../lib/employeeLunch'
import { employeeCreateSchema, employeeFormSchema } from '../lib/formSchemas'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'
import { Segmented } from '../components/ui/Segmented'
import {
  FilterBar,
  FormSection,
  InlineAlert,
  SectionHeader,
  TruncationNotice,
  UserAvatar,
} from '../components/ui/Primitives'

const tabs = [
  ['aprovacoes', 'Aprovações'],
  ['configuracoes', 'Configurações'],
  ['funcionarios', 'Funcionários'],
  ['usuarios', 'Usuários'],
  ['seguranca', 'Segurança'],
]

async function perform(action, refreshers, notify, successMessage = ACTION_FEEDBACK.changesSaved, onSuccess, onError) {
  const result = await runAction({
    action,
    refresh: refreshers,
    notify,
    successMessage,
    onSuccess,
    onError,
  })
  return result !== null
}

function approvalMinutes(value) {
  const minutes = Number(value || 0)
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}min`
}

function workflowStatusLabel(value) {
  return {
    pending: 'Pendente',
    approved: 'Aprovado',
    rejected: 'Rejeitado',
    synced: 'Sincronizado',
    sync_error: 'Erro de sincronização',
  }[value] || String(value || 'Não informado').replaceAll('_', ' ')
}

// [R4c] Aprovações: um botão principal por vez. "Aprovar" é secundário e
// vira o principal só no pedido em foco (passando o mouse ou com o foco
// dentro dele); sem nenhum em foco, o primeiro pedido da lista. As classes
// ficam no index.css (approvals, approval-item, btn-approve).
function ApprovalActions({ onApprove, onReject }) {
  return (
    <div className="flex shrink-0 flex-wrap gap-2 sm:justify-end">
      <button className="btn-secondary btn-sm btn-approve" onClick={onApprove} type="button">Aprovar</button>
      <button className="btn-danger btn-sm" onClick={onReject} type="button">Rejeitar</button>
    </div>
  )
}

function ApprovalSection({ title, count, titleRef = null, children }) {
  return (
    <section className="space-y-3">
      <SectionHeader
        meta={<span className="text-sm text-muted">{count} pendente(s)</span>}
        title={title}
        titleRef={titleRef}
      />
      {count === 0 ? <EmptyState title="Nenhuma aprovação pendente" description="Não há itens desta categoria para decisão agora." /> : <ul className="card divide-y divide-line p-0">{children}</ul>}
    </section>
  )
}

// Um pedido da lista: quem, o que e quando numa linha densa; as ações à direita.
function ApprovalItem({ first, name, team, details, note, created, actions }) {
  return (
    <li className="approval-item" data-first={first ? '' : undefined}>
      <div className="min-w-0 flex-1">
        <p className="flex flex-wrap items-baseline gap-x-2">
          <strong className="font-medium text-ink">{name}</strong>
          {team && <span className="text-sm text-muted">Equipe {team.toUpperCase()}</span>}
        </p>
        <p className="mt-0.5 text-sm text-ink-2">{details.filter(Boolean).join(' · ')}</p>
        {note && <p className="mt-1 line-clamp-2 text-sm leading-6 text-muted" title={note}>{note}</p>}
        {created && <p className="mt-0.5 text-xs text-muted">Criado em {created}</p>}
      </div>
      {actions}
    </li>
  )
}

// Filtro por tipo: a contagem de cada um é o próprio botão do filtro.
const APPROVAL_FILTERS = [
  ['todos', 'Todos'],
  ['pausas', 'Pausas e reuniões'],
  ['horas', 'Horas extras'],
  ['ajustes', 'Correção de ponto'],
]

function ApprovalsTab({ requests, refresh, refreshStatus, notify }) {
  const overtimeTitleRef = useRef(null)
  const adjustmentTitleRef = useRef(null)
  const [kind, setKind] = useState('todos')
  const decision = useDecisionConfirm(notify, [refresh, refreshStatus])

  // Pausas e reuniões ficam sem confirmação, por decisão de 2026-10-29: a aprovação
  // de reunião do N1 é em tempo real e o efeito de um erro é reversível na operação.
  async function decidePause(id, action) {
    await perform(
      () => post(`${action}_pausa.php`, { funcionario_id: id }),
      [refresh, refreshStatus],
      notify,
      action === 'aprovar' ? ACTION_FEEDBACK.requestApproved : ACTION_FEEDBACK.requestRejected,
    )
  }

  if (requests.loading) return <LoadingState />
  if (requests.error) return <ErrorState message={requests.error.message} onRetry={requests.refresh} />
  const pauses = requests.data?.solicitacoes || []
  const overtime = requests.data?.overtime || []
  const adjustments = requests.data?.time_adjustments || []
  const total = pauses.length + overtime.length + adjustments.length
  const counts = { todos: total, pausas: pauses.length, horas: overtime.length, ajustes: adjustments.length }
  const shows = (key) => kind === 'todos' || kind === key
  // O primeiro pedido visível leva o botão principal quando nenhum está em foco.
  const firstKind = ['pausas', 'horas', 'ajustes'].find((key) => shows(key) && counts[key] > 0)

  return (
    <div className="approvals space-y-6">
      {/* [R3] Sem repetir "Aprovações" (a aba já diz). [R4c] O total e a contagem por tipo ficam no filtro. */}
      <Segmented label="Filtrar por tipo" onChange={setKind} options={APPROVAL_FILTERS.map(([value, label]) => ({ value, label, count: counts[value] }))} value={kind} />
      <TruncationNotice limit={requests.data?.limit} truncated={requests.data?.overtime_truncated}>
        Há mais horas extras pendentes do que as exibidas. Decida as mais recentes ou filtre em Horas extras.
      </TruncationNotice>
      <TruncationNotice limit={requests.data?.limit} truncated={requests.data?.time_adjustments_truncated}>
        Há mais correções de ponto pendentes do que as exibidas. Decida as mais recentes ou filtre em Correção de ponto.
      </TruncationNotice>
      {total === 0 &&<EmptyState title="Nenhuma aprovação pendente" description="Novas solicitações operacionais aparecerão automaticamente nesta área." />}

      {total > 0 && (
        <>
          {shows('pausas') && (
            <ApprovalSection count={pauses.length} title="Pausas/Reuniões pendentes">
              {pauses.map((request, index) => (
                <ApprovalItem
                  actions={<ApprovalActions onApprove={() => decidePause(request.id, 'aprovar')} onReject={() => decidePause(request.id, 'rejeitar')} />}
                  details={[request.motivo, `solicitado em ${formatDateTime(request.solicitacao_timestamp)}`]}
                  first={firstKind === 'pausas' && index === 0}
                  key={`pause-${request.id}`}
                  name={request.nome}
                  note={request.observacao || 'Sem observação.'}
                  team={request.equipe}
                />
              ))}
            </ApprovalSection>
          )}

          {shows('horas') && (
            <ApprovalSection count={overtime.length} title="Horas extras pendentes" titleRef={overtimeTitleRef}>
              {overtime.map((item, index) => (
                <ApprovalItem
                  actions={<ApprovalActions onApprove={(event) => decision.ask('overtime', item, 'approved', event.currentTarget, overtimeTitleRef)} onReject={(event) => decision.ask('overtime', item, 'rejected', event.currentTarget, overtimeTitleRef)} />}
                  created={formatDateTime(item.created_at)}
                  details={[item.work_date, `${String(item.start_time).slice(0, 5)} - ${String(item.end_time).slice(0, 5)}`, `total ${approvalMinutes(item.total_minutes)}`, workflowStatusLabel(item.status)]}
                  first={firstKind === 'horas' && index === 0}
                  key={`overtime-${item.id}`}
                  name={item.employee_name}
                  note={`${item.reason} - ${item.justification}`}
                  team={item.team}
                />
              ))}
            </ApprovalSection>
          )}

          {shows('ajustes') && (
            <ApprovalSection count={adjustments.length} title="Ajustes de ponto pendentes" titleRef={adjustmentTitleRef}>
              {adjustments.map((item, index) => (
                <ApprovalItem
                  actions={<ApprovalActions onApprove={(event) => decision.ask('adjustment', item, 'approved', event.currentTarget, adjustmentTitleRef)} onReject={(event) => decision.ask('adjustment', item, 'rejected', event.currentTarget, adjustmentTitleRef)} />}
                  created={formatDateTime(item.created_at)}
                  details={[item.adjustment_date, adjustmentTypeLabel(item.adjustment_type), `horário correto ${item.correct_time ? String(item.correct_time).slice(0, 5) : 'sem horário'}`, workflowStatusLabel(item.status)]}
                  first={firstKind === 'ajustes' && index === 0}
                  key={`adjustment-${item.id}`}
                  name={item.employee_name}
                  note={item.justification}
                  team={item.team}
                />
              ))}
            </ApprovalSection>
          )}
        </>
      )}
      <DecisionConfirmDialog {...decision.dialogProps} />
    </div>
  )
}

function SettingsTab({ resource, notify }) {
  const [form, setForm] = useState(null)
  useEffect(() => {
    if (resource.data?.configuracoes) setForm(resource.data.configuracoes)
  }, [resource.data])

  if (resource.loading || !form) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  async function submit(event) {
    event.preventDefault()
    await perform(() => post('salvar_configuracao.php', form), [resource.refresh], notify, ACTION_FEEDBACK.changesSaved)
  }

  return (
    <form className="max-w-3xl" onSubmit={submit}>
      <FormSection
        description="Ajustes operacionais aplicados pelo backend e auditados no fluxo administrativo."
        eyebrow="Parâmetros"
        title="Configurações de pausas"
      >
        <div className="space-y-5">
      <div className="grid gap-5 md:grid-cols-2">
        <label className="label">Limite por equipe
          <input className="field mt-2" min="1" max="10" type="number" value={form.limite_pausa_por_equipe} onChange={(event) => setForm({ ...form, limite_pausa_por_equipe: Number(event.target.value) })} />
        </label>
        <label className="label">Duração máxima (minutos)
          <input className="field mt-2" min="5" max="60" type="number" value={form.duracao_pausa_minutos} onChange={(event) => setForm({ ...form, duracao_pausa_minutos: Number(event.target.value) })} />
        </label>
      </div>
      <label className="check-row">
        <input checked={form.alerta_15minutos} onChange={(event) => setForm({ ...form, alerta_15minutos: event.target.checked })} type="checkbox" />
        <span><strong>Alerta aos 15 minutos</strong><small>Destaca pausas próximas do limite.</small></span>
      </label>
      <label className="check-row">
        <input checked={form.alerta_20minutos} onChange={(event) => setForm({ ...form, alerta_20minutos: event.target.checked })} type="checkbox" />
        <span><strong>Alerta aos 20 minutos</strong><small>Marca pausas críticas e excedidas.</small></span>
      </label>
        <InlineAlert tone="info" title="Segurança operacional">
          Esta tela não altera regras de autenticação, permissões ou auditoria. O backend continua validando os limites recebidos.
        </InlineAlert>
        <div className="form-actions">
          <button className="btn-primary" type="submit">Salvar configurações</button>
        </div>
        </div>
      </FormSection>
    </form>
  )
}

const emptyEmployee = {
  nome: '', ad_login: '', equipe: 'n1', access_role: 'tecnico', jornada_entrada: '08:00',
  jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', fora_mapa_escala: false,
}

function teamLabel(value) {
  const normalized = normalizeTeamValue(value)
  return {
    n1: 'N1',
    n2: 'N2',
    na: 'Liderança',
    lideranca: 'Liderança',
    'nao se aplica': 'Liderança',
    nao_se_aplica: 'Liderança',
    sem_equipe: 'Liderança',
  }[normalized] || normalized.toUpperCase()
}

function normalizeTeam(value) {
  const normalized = normalizeTeamValue(value)
  return ['na', 'lideranca', 'nao se aplica', 'nao_se_aplica', 'sem_equipe'].includes(normalized) ? 'lideranca' : normalized
}

function normalizeTeamValue(value) {
  return String(value || 'n1').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
}

// Entrega o elemento do campo também a um ref próprio, além do React Hook Form.
function withFieldRef(field, fieldRef) {
  return { ...field, ref: (element) => { field.ref(element); if (fieldRef) fieldRef.current = element } }
}

// Lote 5b: equipe e perfil são independentes. Promover à Liderança é só do
// admin (perfis.promote); admin não é perfil de cadastro (allowlist AD_ADMIN_USERS).
function EmployeeFields({ form, register, setValue, errors = {}, editing = false, canPromote = false, canEditOutsideMap = false, nameRef = null }) {
  function updateRole(role) {
    setValue('access_role', role, { shouldDirty: true })
  }

  function updateTeam(team) {
    setValue('equipe', team, { shouldDirty: true })
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {/* [pós-deploy 1, D3] O ID vem do banco: não há campo na criação; na edição, só leitura (texto). */}
      {editing && (
        <div className="label">ID
          <p className="field mt-2 text-ink-2" data-employee-id-readonly="">{form.id}</p>
        </div>
      )}
      <label className="label sm:col-span-2">Nome
        <input aria-invalid={Boolean(errors.nome)} className="field mt-2" maxLength="100" minLength="3" required {...withFieldRef(register('nome'), nameRef)} />
        {errors.nome && <span className="mt-1 block text-xs text-danger">{errors.nome.message}</span>}
      </label>
      <label className="label">Login AD
        <input aria-invalid={Boolean(errors.ad_login)} className="field mt-2" maxLength="100" placeholder="nome.sobrenome" {...register('ad_login')} />
        {errors.ad_login && <span className="mt-1 block text-xs text-danger">{errors.ad_login.message}</span>}
      </label>
      <label className="label">Equipe operacional
        <input type="hidden" {...register('equipe')} />
        <select className="field mt-2" value={normalizeTeam(form.equipe)} onChange={(event) => updateTeam(event.target.value)}>
          <option value="n1">N1</option>
          <option value="n2">N2</option>
          <option value="lideranca">Liderança</option>
        </select>
      </label>
      <label className="label">Perfil de acesso
        <input type="hidden" {...register('access_role')} />
        <select className="field mt-2" value={form.access_role || 'tecnico'} onChange={(event) => updateRole(event.target.value)}>
          <option value="tecnico">Técnico</option>
          <option value="gestor">Gestor</option>
          {(canPromote || form.access_role === 'lideranca') && <option disabled={!canPromote} value="lideranca">Liderança</option>}
          <option value="somente_leitura">Somente leitura</option>
          {form.access_role === 'admin' && <option disabled value="admin">Admin (legado)</option>}
        </select>
        {form.access_role === 'admin' && <span className="mt-1 block text-xs text-warn">Admin vem da lista AD_ADMIN_USERS. Escolha outro perfil para salvar.</span>}
      </label>
      <label className="label">Entrada
        <input aria-invalid={Boolean(errors.jornada_entrada)} className="field mt-2" required type="time" {...register('jornada_entrada')} />
        {errors.jornada_entrada && <span className="mt-1 block text-xs text-danger">{errors.jornada_entrada.message}</span>}
      </label>
      <label className="label">Saída
        <input aria-invalid={Boolean(errors.jornada_saida)} className="field mt-2" required type="time" {...register('jornada_saida')} />
        {errors.jornada_saida && <span className="mt-1 block text-xs text-danger">{errors.jornada_saida.message}</span>}
      </label>
      <label className="label">Início almoço
        <input aria-invalid={Boolean(errors.almoco_inicio)} className="field mt-2" required type="time" {...register('almoco_inicio')} />
        {errors.almoco_inicio && <span className="mt-1 block text-xs text-danger">{errors.almoco_inicio.message}</span>}
      </label>
      <label className="label">Fim almoço
        <input aria-invalid={Boolean(errors.almoco_fim)} className="field mt-2" required type="time" {...register('almoco_fim')} />
        {errors.almoco_fim && <span className="mt-1 block text-xs text-danger">{errors.almoco_fim.message}</span>}
      </label>
      {editing && (
        <label className="check-row sm:col-span-2">
          <input disabled={!canEditOutsideMap} type="checkbox" {...register('fora_mapa_escala')} />
          <span>
            <strong>Fora do Mapa PA e da escala</strong>
            <small>
              Não aparece no Mapa PA, na escala nem nas contagens; login, permissões e ausências continuam.
              {canEditOutsideMap ? ' Com vínculo de PA ativo, remova o vínculo antes de marcar.' : ' Só administradores alteram.'}
            </small>
          </span>
        </label>
      )}
      {/* [R4d] Lugar reservado para o grupo Tools (módulo de CHGs, P13): desabilitado e fora do
          React Hook Form, então nada vai para o servidor. Vem depois do "Fora do Mapa PA". */}
      <label className="check-row sm:col-span-2" data-tools-reserved="">
        <input disabled type="checkbox" />
        <span>
          <strong>Grupo Tools</strong>
          <small>Chega com o módulo de CHGs (lança e edita CHGs). Ainda sem efeito: o cadastro não guarda esta marca.</small>
        </span>
      </label>
    </div>
  )
}

// [R4d] Rótulo do perfil na fileira de filtros ativos (os mesmos do filtro).
const ROLE_LABELS = { tecnico: 'Técnico', gestor: 'Gestor', lideranca: 'Liderança', admin: 'Admin (legado)', somente_leitura: 'Somente leitura' }

const archiveStateLabels = {
  ativo: ['Ativo', 'status-success'],
  arquivado: ['Arquivado', 'status-neutral'],
  inativo_antigo: ['Inativo sem data', 'status-warning'],
}

function EmployeeStatusBadge({ item }) {
  const state = employeeArchiveState(item)
  const [label, tone] = archiveStateLabels[state]
  return (
    <div>
      <span className={`status-badge ${tone}`}>{label}</span>
      {state === 'arquivado' && <small>Em {formatDate(item.arquivado_em)}</small>}
      {state === 'inativo_antigo' && <small>Desativado antes do arquivamento</small>}
    </div>
  )
}

// F4 (desenho 9.6): confirmação na própria linha, sem diálogo do navegador.
function EmployeeActionConfirmation({ item, kind, error, busy, onConfirm, onCancel }) {
  const legacy = employeeArchiveState(item) === 'inativo_antigo'
  const title = kind === 'restore'
    ? `Restaurar ${item.nome}?`
    : legacy ? `Regularizar e arquivar ${item.nome}?` : `Arquivar ${item.nome}?`
  const text = kind === 'restore'
    ? 'O cadastro volta a ativo e pode autenticar de novo. PA, escala e ausências não voltam: cadastre-os de novo depois.'
    : legacy
      ? 'O cadastro já está inativo, mas foi desativado antes do arquivamento. O que ainda estiver aberto é encerrado a partir de hoje: PA e escala encerrados, exceções futuras apagadas, ausências futuras canceladas e a ausência em curso termina ontem.'
      : 'A partir de hoje: PA e escala encerrados, exceções futuras apagadas, ausências futuras canceladas (a ausência em curso termina ontem) e pausa aberta finalizada. A sessão do colaborador é encerrada. O histórico fica; só um administrador restaura.'
  return (
    <div aria-label={title} className="space-y-3" role="group">
      <InlineAlert title={title} tone={kind === 'restore' ? 'info' : 'warning'}>{text}</InlineAlert>
      {error && <p className="text-sm text-danger" role="alert">{error}</p>}
      <div className="flex flex-wrap gap-2">
        <button className={kind === 'restore' ? 'btn-primary' : 'btn-danger'} disabled={busy} onClick={onConfirm} type="button">
          {busy ? 'Aguarde...' : kind === 'restore' ? 'Confirmar restauração' : 'Confirmar arquivamento'}
        </button>
        <button className="btn-secondary" disabled={busy} onClick={onCancel} type="button">Voltar</button>
      </div>
    </div>
  )
}

function EmployeesTab({
  resource,
  notify,
  canPromote = false,
  canEditOutsideMap = false,
  canRestore = false,
  canManageLeadershipTeam = false,
  ownEmployeeId = 0,
  ownLogins = [],
}) {
  const {
    clearErrors: clearCreateEmployeeErrors,
    formState: { errors: createEmployeeErrors, isDirty: createEmployeeDirty },
    handleSubmit: handleCreateEmployeeSubmit,
    register: registerCreateEmployee,
    reset: resetCreateEmployee,
    setError: setCreateEmployeeError,
    setFocus: setCreateEmployeeFocus,
    setValue: setCreateEmployeeValue,
    watch: watchCreateEmployee,
  } = useForm({
    defaultValues: emptyEmployee,
  })
  const {
    clearErrors: clearEditEmployeeErrors,
    formState: { errors: editEmployeeErrors, isDirty: editEmployeeDirty },
    handleSubmit: handleEditEmployeeSubmit,
    register: registerEditEmployee,
    reset: resetEditEmployee,
    setError: setEditEmployeeError,
    setFocus: setEditEmployeeFocus,
    setValue: setEditEmployeeValue,
    watch: watchEditEmployee,
  } = useForm({
    defaultValues: emptyEmployee,
  })
  const createForm = watchCreateEmployee()
  const editEmployeeForm = watchEditEmployee()
  const [editForm, setEditForm] = useState(null)
  // [17f] Uma proteção para cada formulário: fechar a edição limpa não pergunta
  // por causa de um cadastro sujo, e descartar a edição não apaga o cadastro.
  const createGuard = useUnsavedChangesGuard(createEmployeeDirty)
  const editGuard = useUnsavedChangesGuard(Boolean(editForm) && editEmployeeDirty)
  const editNameRef = useRef(null)
  const editTriggerRef = useRef(null)
  // [R4d] Adicionar no painel lateral: o rascunho só some com "Descartar".
  const [createOpen, setCreateOpen] = useState(false)
  const createNameRef = useRef(null)
  const createTriggerRef = useRef(null)
  const [submitting, setSubmitting] = useState(false)
  const [search, setSearch] = useState('')
  const [team, setTeam] = useState('')
  const [role, setRole] = useState('')
  // F4: a lista abre nos ativos; arquivados (e inativos antigos) num filtro próprio.
  const [status, setStatus] = useState('ativos')
  // Pós-deploy 1 (9.14.3): filtro de almoço; as opções saem dos cadastros da situação escolhida.
  const [lunch, setLunch] = useState('')
  const [pendingAction, setPendingAction] = useState(null)
  const [acting, setActing] = useState(false)
  const lunchChoices = useMemo(
    () => lunchOptions((resource.data?.funcionarios || []).filter((item) => matchesArchiveFilter(item, status))),
    [resource.data, status],
  )
  // Trocou a situação (ou a lista recarregou) e o horário escolhido sumiu: volta para "Todos os almoços".
  useEffect(() => {
    setLunch((current) => keepLunchSelection(current, lunchChoices))
  }, [lunchChoices])
  const filtered = useMemo(() => {
    const items = resource.data?.funcionarios || []
    const term = search.toLowerCase()
    return items.filter((item) => (
      (!term || item.nome.toLowerCase().includes(term) || (item.ad_login || '').toLowerCase().includes(term) || teamLabel(item.equipe).toLowerCase().includes(term))
      && (!team || normalizeTeam(item.equipe) === team)
      && (!role || item.access_role === role)
      && matchesArchiveFilter(item, status)
      && matchesLunchFilter(item, keepLunchSelection(lunch, lunchChoices))
    ))
  }, [lunch, lunchChoices, resource.data, role, search, status, team])
  const allEmployees = resource.data?.funcionarios || []

  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  function applyEmployeeErrors(error, setError, setFocus, editing = false) {
    // Campos que renderizam a mensagem de erro abaixo do input em EmployeeFields.
    const fieldsWithMessage = ['id', 'nome', 'ad_login', 'jornada_entrada', 'jornada_saida', 'almoco_inicio', 'almoco_fim']
    let firstField = null
    let hiddenMessage = ''
    for (const issue of error.issues) {
      const field = issue.path?.[0]
      const message = issue.message || 'Campo inválido.'
      if (fieldsWithMessage.includes(field)) {
        if (!firstField) firstField = field
        setError(field, { type: 'zod', message })
      } else if (!hiddenMessage) {
        hiddenMessage = message
      }
    }
    if (hiddenMessage) notify(hiddenMessage, 'error')
    if (!firstField || (editing && firstField === 'id')) return
    setFocus(firstField)
  }

  function openEdit(item, trigger) {
    editTriggerRef.current = trigger
    const nextForm = {
      ...emptyEmployee,
      ...item,
      ad_login: item.ad_login ?? '',
      access_role: item.access_role || 'tecnico',
      fora_mapa_escala: item.fora_mapa_escala === true,
      equipe: normalizeTeam(item.equipe),
    }
    setEditForm(nextForm)
    resetEditEmployee(nextForm)
  }

  // [17f] Com a edição suja, trocar de cadastro pergunta antes.
  function edit(item, trigger) {
    editGuard.requestLeave(() => openEdit(item, trigger))
  }

  function closeEdit() {
    editGuard.requestLeave(() => setEditForm(null))
  }

  function openCreate(trigger) {
    createTriggerRef.current = trigger
    setCreateOpen(true)
  }

  // [R4d] Fechar o painel de adicionar sujo pergunta; descartar limpa o rascunho.
  function closeCreate() {
    createGuard.requestLeave(() => {
      resetCreateEmployee(emptyEmployee)
      setCreateOpen(false)
    })
  }

  function validateUnique(form, editing = false) {
    const employees = resource.data?.funcionarios || []
    const login = String(form.ad_login || '').trim().toLowerCase()
    const duplicateLogin = login && employees.some((item) => (
      String(item.ad_login || '').trim().toLowerCase() === login
      && (!editing || Number(item.id) !== Number(form.id))
    ))
    if (duplicateLogin) return 'Este login AD já está vinculado a outro funcionário (ativo ou inativo).'
    if (login && !/^[a-z0-9._@-]+$/.test(login)) return 'O login AD contém caracteres inválidos.'
    return ''
  }

  async function create(values) {
    clearCreateEmployeeErrors()
    const parsed = employeeCreateSchema.safeParse(values)
    if (!parsed.success) {
      applyEmployeeErrors(parsed.error, setCreateEmployeeError, setCreateEmployeeFocus)
      return
    }
    const error = validateUnique(parsed.data)
    if (error) {
      notify(error, 'error')
      return
    }
    setSubmitting(true)
    await perform(
      // [D3] Sem ID: o servidor gera e devolve em funcionario.id, que vai na mensagem.
      () => post('adicionar_funcionario.php', parsed.data),
      [resource.refresh],
      notify,
      null,
      (result) => {
        resetCreateEmployee(emptyEmployee)
        notify(employeeCreatedFeedback(result))
        setCreateOpen(false)
      },
    )
    setSubmitting(false)
  }

  async function update(values) {
    clearEditEmployeeErrors()
    // [D3] O ID não é campo do formulário: vem do cadastro aberto.
    const parsed = employeeFormSchema.safeParse({ ...values, id: editForm?.id })
    if (!parsed.success) {
      applyEmployeeErrors(parsed.error, setEditEmployeeError, setEditEmployeeFocus, true)
      return
    }
    const error = validateUnique(parsed.data, true)
    if (error) {
      notify(error, 'error')
      return
    }
    setSubmitting(true)
    await perform(
      () => post('atualizar_funcionario.php', { ...parsed.data, id: Number(parsed.data.id), funcionario_id: Number(parsed.data.id) }),
      [resource.refresh],
      notify,
      ACTION_FEEDBACK.employeeUpdated,
      () => setEditForm(null),
    )
    setSubmitting(false)
  }

  function isOwnRecord(item) {
    if (ownEmployeeId && Number(item.id) === Number(ownEmployeeId)) return true
    const login = String(item.ad_login || '').trim().toLowerCase()
    return Boolean(login) && ownLogins.includes(login)
  }

  // Lote 5b: cadastro de Liderança/admin só para quem promove.
  function canManageRecord(item) {
    return canPromote || !['lideranca', 'admin'].includes(item.access_role)
  }

  // L9 no arquivamento (decisão 1 do commit 3): cadastro da equipe Liderança só
  // pelo admin; o próprio cadastro nunca (D3). O servidor confere de novo.
  function canArchive(item) {
    return canManageRecord(item)
      && (canManageLeadershipTeam || normalizeTeam(item.equipe) !== 'lideranca')
      && !isOwnRecord(item)
  }

  async function archiveOrRestore(item, kind) {
    const archiving = kind === 'archive'
    setActing(true)
    await perform(
      () => post(archiving ? 'remover_funcionario.php' : 'restaurar_funcionario.php', { funcionario_id: item.id }),
      [resource.refresh],
      notify,
      null,
      (result) => {
        setPendingAction(null)
        if (editForm && Number(editForm.id) === Number(item.id)) setEditForm(null)
        notify(archiving
          ? `${result?.mensagem || ACTION_FEEDBACK.employeeArchived} ${archiveEffectsSummary(result?.efeitos)}`
          : result?.mensagem || ACTION_FEEDBACK.employeeRestored)
        for (const warning of result?.avisos || []) notify(warning, 'warning')
      },
      (error) => setPendingAction((current) => (current && current.id === item.id ? { ...current, error: error?.message || 'Não foi possível concluir a ação.' } : current)),
    )
    setActing(false)
  }

  function rowActions(item) {
    const state = employeeArchiveState(item)
    const actions = []
    if (state === 'ativo' && canManageRecord(item)) {
      actions.push(<button className="table-action" key="edit" onClick={(event) => edit(item, event.currentTarget)} type="button">Editar</button>)
    }
    if (state !== 'arquivado' && canArchive(item)) {
      actions.push(<button className="table-action text-danger" key="archive" onClick={() => setPendingAction({ id: item.id, kind: 'archive', error: '' })} type="button">{state === 'inativo_antigo' ? 'Arquivar (regularizar)' : 'Arquivar'}</button>)
    }
    if (state !== 'ativo' && canRestore) {
      actions.push(<button className="table-action" key="restore" onClick={() => setPendingAction({ id: item.id, kind: 'restore', error: '' })} type="button">Restaurar</button>)
    }
    if (actions.length > 0) return <div className="flex flex-wrap gap-2">{actions}</div>
    if (state === 'ativo' && isOwnRecord(item)) return <span className="text-xs text-muted">Seu cadastro</span>
    return <span className="text-xs text-muted">{state === 'ativo' ? 'Só administradores' : 'Só administradores restauram'}</span>
  }

  // Confirmação de arquivar ou restaurar, a mesma na tabela e no cartão.
  function actionConfirmation(item) {
    if (pendingAction?.id !== item.id) return null
    return (
      <EmployeeActionConfirmation
        busy={acting}
        error={pendingAction.error}
        item={item}
        kind={pendingAction.kind}
        onCancel={() => setPendingAction(null)}
        onConfirm={() => archiveOrRestore(item, pendingAction.kind)}
      />
    )
  }

  // [R4d] Fileira de filtros ativos (no lugar do cartão "Filtros aplicados"):
  // cada filtro em uso, com o botão de tirar. Situação "Ativos" é o padrão e
  // não conta como filtro.
  const lunchSelected = keepLunchSelection(lunch, lunchChoices)
  const activeFilters = [
    search && ['busca', `Busca: ${search}`, () => setSearch('')],
    team && ['equipe', `Equipe: ${teamLabel(team)}`, () => setTeam('')],
    role && ['perfil', `Perfil: ${ROLE_LABELS[role] ?? role}`, () => setRole('')],
    status !== 'ativos' && ['situacao', `Situação: ${status === 'arquivados' ? 'Arquivados' : 'Ativos e arquivados'}`, () => { setStatus('ativos'); setPendingAction(null) }],
    lunchSelected && ['almoco', `Almoço: ${lunchSelected}`, () => setLunch('')],
  ].filter(Boolean)
  const activeCount = allEmployees.filter((item) => employeeArchiveState(item) === 'ativo').length
  const leadershipCount = filtered.filter((item) => item.access_role === 'lideranca').length

  return (
    <div className="space-y-5">
      {/* [R4d] A lista na primeira tela; adicionar abre o painel lateral (D-R0-6). */}
      <div className="flex flex-wrap items-center justify-end gap-3">
        <button className="btn-primary" data-employee-add="" onClick={(event) => openCreate(event.currentTarget)} type="button">
          <Icon className="h-4 w-4" name="users" /> Adicionar funcionário
        </button>
      </div>

      <div className="space-y-3">
        <FilterBar>
          <input className="field" placeholder="Buscar nome, login ou equipe" value={search} onChange={(event) => setSearch(event.target.value)} />
          <select className="field" value={team} onChange={(event) => setTeam(event.target.value)}><option value="">Todas as equipes</option><option value="n1">N1</option><option value="n2">N2</option><option value="lideranca">Liderança</option></select>
          <select className="field" value={role} onChange={(event) => setRole(event.target.value)}><option value="">Todos os perfis</option><option value="tecnico">Técnico</option><option value="gestor">Gestor</option><option value="lideranca">Liderança</option><option value="admin">Admin (legado)</option><option value="somente_leitura">Somente leitura</option></select>
          <select aria-label="Situação do cadastro" className="field" value={status} onChange={(event) => { setStatus(event.target.value); setPendingAction(null) }}><option value="ativos">Ativos</option><option value="arquivados">Arquivados</option><option value="">Ativos e arquivados</option></select>
          <select aria-label="Almoço" className="field" value={lunchSelected} onChange={(event) => setLunch(event.target.value)}><option value="">Todos os almoços</option>{lunchChoices.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
          {/* [R4d] Lugar reservado para o grupo Tools (módulo de CHGs, P13): ainda sem efeito, nada filtra nem é enviado. */}
          <select aria-describedby="filtro-tools-ajuda" aria-label="Grupo Tools" className="field" data-tools-reserved="" disabled value=""><option value="">Grupo Tools (em breve)</option></select>
          <p className="field-help self-center xl:col-span-2" id="filtro-tools-ajuda">Grupo Tools: chega com o módulo de CHGs; ainda sem efeito.</p>
        </FilterBar>
        <div aria-label="Filtros ativos" className="flex min-h-10 flex-wrap items-center gap-2" data-active-filters="" role="group">
          {activeFilters.length === 0 && <p className="text-sm text-muted">Sem filtros: todos os cadastros ativos.</p>}
          {activeFilters.map(([key, label, clear]) => (
            <button aria-label={`Tirar o filtro ${label}`} className="filter-chip" data-filter={key} key={key} onClick={clear} type="button">
              <span>{label}</span>
              <Icon className="h-3.5 w-3.5" name="close" />
            </button>
          ))}
          {activeFilters.length > 1 && (
            <button className="btn-ghost btn-sm" onClick={() => { setSearch(''); setTeam(''); setRole(''); setStatus('ativos'); setLunch(''); setPendingAction(null) }} type="button">Limpar filtros</button>
          )}
        </div>
      </div>

      <section className="card" data-employee-section="">
          <SectionHeader
            description="Editar, arquivar e restaurar continuam restritos e auditados pelo backend. Arquivados ficam fora do painel, do Mapa PA e da escala; o histórico fica."
            meta={<span className="status-badge status-neutral">{filtered.length} resultado(s)</span>}
            title="Funcionários"
          />
          {/* [R4d] Os números dos cartões que saíram: ativos no total e Liderança na lista (admin vem da lista AD_ADMIN_USERS). */}
          <p className="mt-2 text-sm text-muted" data-employee-counts="">{activeCount} ativos no total · {leadershipCount} com perfil Liderança na lista</p>
          {/* [17] Abaixo de 1280 px, cartões: a tabela (min-w-[760px] da .data-table) rolava dentro do cartão e deixava Editar e Arquivar fora da tela. */}
          <div className="mt-5 grid items-start gap-3 md:grid-cols-2 xl:hidden" data-employee-list="">
            {filtered.map((item) => (
              <article className="space-y-3 rounded-card border border-line bg-surface-2 p-4" data-employee={item.id} key={item.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="flex min-w-0 items-start gap-3">
                    <UserAvatar name={item.nome} />
                    <div className="min-w-0">
                      <strong className="block break-words text-ink-2">{item.nome}</strong>
                      <span className="block break-all text-xs text-muted">ID {item.id} · {item.ad_login || 'Sem login AD'}</span>
                    </div>
                  </div>
                  <div className="shrink-0 text-right [&_small]:mt-1 [&_small]:block [&_small]:text-xs [&_small]:text-muted"><EmployeeStatusBadge item={item} /></div>
                </div>
                <dl className="grid grid-cols-2 gap-3 text-sm">
                  <div><dt className="text-xs text-muted">Equipe</dt><dd className="mt-1"><span className="status-badge status-neutral">{teamLabel(item.equipe)}</span></dd></div>
                  <div><dt className="text-xs text-muted">Perfil</dt><dd className="mt-1"><span className="status-badge status-info">{(item.access_role || 'tecnico').replace('_', ' ')}</span></dd></div>
                  <div><dt className="text-xs text-muted">Jornada</dt><dd className="text-ink-2">{item.jornada_entrada} - {item.jornada_saida}</dd></div>
                  <div><dt className="text-xs text-muted">Almoço</dt><dd className="text-ink-2" data-employee-lunch="">{lunchKey(item) || '—'}</dd></div>
                </dl>
                <div className="border-t border-line pt-3">{rowActions(item)}</div>
                {actionConfirmation(item)}
              </article>
            ))}
          </div>
          <div className="table-wrap mt-5 hidden xl:block">
          <table className="data-table">
            <thead><tr><th>Funcionário</th><th>Equipe</th><th>Perfil</th><th>Jornada</th><th>Almoço</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              {filtered.map((item) => (
                <Fragment key={item.id}>
                <tr>
                  <td>
                    <div className="flex min-w-0 items-start gap-3">
                      <UserAvatar name={item.nome} />
                      <div className="min-w-0">
                        <strong>{item.nome}</strong>
                        <small>ID {item.id} · {item.ad_login || 'Sem login AD'}</small>
                      </div>
                    </div>
                  </td>
                  <td><span className="status-badge status-neutral">{teamLabel(item.equipe)}</span></td>
                  <td><span className="status-badge status-info">{(item.access_role || 'tecnico').replace('_', ' ')}</span></td>
                  <td>{item.jornada_entrada} - {item.jornada_saida}</td>
                  <td>{lunchKey(item) || '—'}</td>
                  <td><EmployeeStatusBadge item={item} /></td>
                  <td>{rowActions(item)}</td>
                </tr>
                {pendingAction?.id === item.id && (
                  <tr>
                    <td className="bg-surface-2" colSpan={7}>
                      <div className="max-w-3xl">{actionConfirmation(item)}</div>
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
            </tbody>
          </table>
          </div>
        {filtered.length === 0 && <p className="py-8 text-center text-sm text-muted">Nenhum funcionário corresponde aos filtros.</p>}
      </section>

      {createOpen && (
        <Drawer
          description="O perfil de acesso vem deste cadastro; admin vem só da lista AD_ADMIN_USERS. A equipe não concede permissão. Revise login AD e jornada antes de salvar."
          initialFocusRef={createNameRef}
          onClose={closeCreate}
          returnFocusRef={createTriggerRef}
          size="max-w-2xl"
          title="Adicionar funcionário"
        >
          <form className="space-y-5" data-employee-create="" onSubmit={handleCreateEmployeeSubmit(create)}>
            <EmployeeFields canPromote={canPromote} errors={createEmployeeErrors} form={createForm} nameRef={createNameRef} register={registerCreateEmployee} setValue={setCreateEmployeeValue} />
            <div className="drawer-footer">
              <button className="btn-secondary" disabled={submitting} onClick={closeCreate} type="button">Cancelar</button>
              <button className="btn-primary" disabled={submitting} type="submit">{submitting ? 'Adicionando...' : 'Adicionar funcionário'}</button>
            </div>
          </form>
        </Drawer>
      )}

      {editForm && (
        <Drawer
          description="Dados atuais do CI carregados. Alterações de perfil são restritas a administradores e registradas em auditoria."
          initialFocusRef={editNameRef}
          onClose={closeEdit}
          returnFocusRef={editTriggerRef}
          size="max-w-2xl"
          title="Editar funcionário"
        >
          <form className="space-y-5" onSubmit={handleEditEmployeeSubmit(update)}>
            <EmployeeFields canEditOutsideMap={canEditOutsideMap} canPromote={canPromote} editing errors={editEmployeeErrors} form={editEmployeeForm} nameRef={editNameRef} register={registerEditEmployee} setValue={setEditEmployeeValue} />
            <div className="drawer-footer">
              <button className="btn-secondary" disabled={submitting} onClick={closeEdit} type="button">Cancelar</button>
              <button className="btn-primary" disabled={submitting} type="submit">{submitting ? 'Salvando...' : 'Salvar alterações'}</button>
            </div>
          </form>
        </Drawer>
      )}
      <ConfirmDiscardDialog {...createGuard.dialogProps} />
      <ConfirmDiscardDialog {...editGuard.dialogProps} />
    </div>
  )
}

function UsersTab({ enabled, resource, notify }) {
  const [form, setForm] = useState({ username: '', password: '', role: 'gestor' })
  if (!enabled) return <EmptyState title="Admin local desabilitado" description="Usuários e senhas locais não são gerenciados enquanto ENABLE_LOCAL_ADMIN=false. O acesso administrativo usa a allowlist do Active Directory." />
  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />
  const users = resource.data?.usuarios || []

  async function create(event) {
    event.preventDefault()
    await perform(
      () => post('usuarios.php?action=criar', form),
      [resource.refresh],
      notify,
      ACTION_FEEDBACK.userCreated,
      () => setForm({ username: '', password: '', role: 'gestor' }),
    )
  }

  async function updateRole(id, role) {
    if (!window.confirm(`Confirma a alteração do perfil para ${role}?`)) return
    await perform(() => post('usuarios.php?action=atualizar_role', { id, role }), [resource.refresh], notify, ACTION_FEEDBACK.userUpdated)
  }

  async function remove(id) {
    if (!window.confirm('Confirma a remoção deste usuário local?')) return
    await perform(() => post('usuarios.php?action=deletar', { id }), [resource.refresh], notify, ACTION_FEEDBACK.userRemoved)
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[360px_1fr]">
      <form className="card space-y-4" onSubmit={create}>
        <h3 className="font-semibold text-ink">Criar usuário local</h3>
        <label className="label">Login<input className="field mt-2" minLength="3" required value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} /></label>
        <label className="label">Senha<input autoComplete="new-password" className="field mt-2" minLength="8" required type="password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} /></label>
        <label className="label">Perfil<select className="field mt-2" value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value })}><option value="gestor">Gestor</option><option value="admin">Admin</option></select></label>
        <button className="btn-primary w-full" type="submit">Criar usuário</button>
      </form>
      <div className="card table-wrap">
        <table className="data-table">
          <thead><tr><th>Usuário</th><th>Perfil</th><th>Último login</th><th>Ações</th></tr></thead>
          <tbody>
            {users.map((user) => (
              <tr key={user.id}>
                <td><strong>{user.username}</strong><small>Criado em {formatDateTime(user.created_at)}</small></td>
                <td><select className="field min-w-28 py-1.5" value={user.role} onChange={(event) => updateRole(user.id, event.target.value)}><option value="gestor">Gestor</option><option value="admin">Admin</option></select></td>
                <td>{formatDateTime(user.last_login)}</td>
                <td><button className="table-action text-danger" onClick={() => remove(user.id)} type="button">Remover</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

const securityProtections = [
  ['Autenticação AD/LDAP', 'Ativo', 'shield', 'Bind LDAP no backend, normalização de login e allowlist administrativa.'],
  ['Sessões seguras', 'Configurado', 'clock', 'Cookies HttpOnly/SameSite, regeneração de ID e timeouts absoluto e de inatividade.'],
  ['CSRF em escritas', 'Ativo', 'shield', 'Token obrigatório em operações POST e upload multipart.'],
  ['RBAC', 'Ativo', 'users', 'Perfis técnico, gestor, admin e somente leitura validados no backend.'],
  ['PDO e prepared statements', 'Ativo', 'settings', 'Consultas com parâmetros para dados controlados pelo usuário.'],
  ['Validação de entrada', 'Ativo', 'edit', 'Tipos, limites, formatos e estruturas inesperadas são rejeitados.'],
  ['Proteção contra XSS', 'Configurado', 'shield', 'React escapa conteúdo e o legado usa codificação de saída.'],
  ['Headers de segurança', 'Ativo', 'file', 'Aplicados pelo Apache sem revelar configuração sensível.'],
  ['Content Security Policy', 'Parcial', 'shield', "O React usa política restrita a 'self'; páginas legadas ainda dependem temporariamente de conteúdo inline."],
  ['Proteção contra framing', 'Ativo', 'shield', 'X-Frame-Options DENY e frame-ancestors none.'],
  ['X-Content-Type-Options', 'Ativo', 'file', 'nosniff reduz interpretação indevida de conteúdo.'],
  ['Referrer-Policy', 'Ativo', 'file', 'strict-origin-when-cross-origin configurado.'],
  ['Permissions-Policy', 'Ativo', 'settings', 'Geolocalização, microfone e câmera desabilitados.'],
  ['Arquivos sensíveis', 'Ativo', 'shield', '.env, .git, services, migrations e fontes bloqueados pelo Apache.'],
  ['Upload privado', 'Ativo', 'upload', 'Allowlist, MIME real, nome aleatório, hash e armazenamento fora do webroot.'],
  ['Limites de payload', 'Ativo', 'file', 'Limites independentes para JSON, importação e documentos.'],
  ['Validação de CSV', 'Ativo', 'file', 'Cabeçalhos, linhas, colunas, células e estruturas são limitados.'],
  ['Formula injection em CSV', 'Ativo', 'shield', 'Exportações neutralizam células iniciadas por operadores de fórmula.'],
  ['Auditoria sensível', 'Ativo', 'chart', 'Login, operações, importações, perfis e documentos geram eventos.'],
  ['Rate limit', 'Ativo', 'clock', 'Tentativas de login AD e administrativo possuem limitação.'],
  ['Logs sem conteúdo de arquivo', 'Configurado', 'file', 'Uploads não registram conteúdo, token, senha ou caminho físico.'],
  ['Microsoft Graph/SharePoint', 'Pendente', 'plug', 'Estrutura preparada; integração e sincronização reais permanecem desabilitadas.'],
  ['MySQL como fonte principal', 'Ativo', 'settings', 'Dados operacionais persistem em MySQL; fallbacks legados não são a fonte oficial.'],
  ['Fila de sincronização', 'Configurado', 'clock', 'Fila transacional existe, mas o consumidor Graph ainda não foi ativado.'],
  ['Redirects do legado', 'Ativo', 'logout', 'Somente GET/HEAD visual redireciona; APIs e POST legado ficam fora da regra.'],
  ['Erros HTTP controlados', 'Ativo', 'shield', 'Respostas públicas não retornam stack trace nem caminho físico.'],
  ['Antivírus de documentos', 'Pendente', 'shield', 'Não há scanner de malware integrado nesta fase; formatos permitidos não são executados.'],
]

const securityStatusClass = {
  Ativo: 'status-success',
  Configurado: 'status-info',
  Parcial: 'status-warning',
  Pendente: 'status-neutral',
}

const securityGroups = [
  ['Aplicação', ['PDO e prepared statements', 'Validação de entrada', 'Proteção contra XSS', 'Limites de payload', 'Validação de CSV', 'Formula injection em CSV', 'Erros HTTP controlados']],
  ['Autenticação e sessão', ['Autenticação AD/LDAP', 'Sessões seguras', 'CSRF em escritas', 'RBAC', 'Rate limit']],
  ['Upload e arquivos', ['Upload privado', 'Logs sem conteúdo de arquivo', 'Antivírus de documentos']],
  ['Infraestrutura e hardening', ['Headers de segurança', 'Content Security Policy', 'Proteção contra framing', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'Arquivos sensíveis', 'MySQL como fonte principal', 'Redirects do legado']],
  ['Auditoria e compliance', ['Auditoria sensível', 'Microsoft Graph/SharePoint', 'Fila de sincronização']],
]

function SecurityTab({ config, notify }) {
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const protections = securityProtections.map((item) => (
    item[0] === 'Autenticação AD/LDAP' && config?.local_admin_enabled
      ? [item[0], 'Parcial', item[2], 'O acesso local de emergência está ligado: desligue assim que terminar o uso.']
      : item
  ))
  const activeCount = protections.filter(([, status]) => ['Ativo', 'Configurado'].includes(status)).length
  const pendingCount = protections.filter(([, status]) => ['Parcial', 'Pendente'].includes(status)).length
  const groupedProtections = securityGroups.map(([group, titles]) => [
    group,
    titles.map((title) => protections.find(([itemTitle]) => itemTitle === title)).filter(Boolean),
  ])

  async function submit(event) {
    event.preventDefault()
    if (password !== confirmation) {
      notify('A confirmação da senha não confere.', 'error')
      return
    }
    await perform(
      () => post('alterar_senha_admin.php', { nova_senha: password }),
      [],
      notify,
      ACTION_FEEDBACK.changesSaved,
      () => {
        setPassword('')
        setConfirmation('')
      },
    )
  }

  return (
    <div className="space-y-5">
      <section className="card overflow-hidden border-info-line">
        <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
          <div>
            <p className="text-xs font-semibold text-info">Postura de segurança</p>
            <h2 className="mt-2 text-2xl font-semibold text-ink">Checklist de Segurança</h2>
            <p className="mt-2 max-w-2xl text-sm text-muted">Visão consolidada dos controles presentes no código e na configuração de deploy, sem expor segredos ou caminhos internos.</p>
          </div>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div className="rounded-xl border border-ok-line bg-ok-bg p-3"><strong className="block text-xl text-ok">{activeCount}</strong><span className="text-xs text-muted">Ativas/configuradas</span></div>
            <div className="rounded-xl border border-warn-line bg-warn-bg p-3"><strong className="block text-xl text-warn">{pendingCount}</strong><span className="text-xs text-muted">Parciais/pendentes</span></div>
            <div className="rounded-xl border border-danger-line bg-danger-bg p-3"><strong className="block text-xl text-danger">1</strong><span className="text-xs text-muted">Risco relevante</span></div>
            <div className="rounded-xl border border-info-line bg-info-bg p-3"><strong className="block text-sm text-info">13/06/2026</strong><span className="text-xs text-muted">Última revisão</span></div>
          </div>
        </div>
      </section>

      {groupedProtections.map(([group, items]) => (
        <section className="space-y-3" key={group}>
          <div className="flex items-center gap-3">
            <h3 className="text-sm font-semibold text-muted">{group}</h3>
            <span className="h-px flex-1 bg-ink/5" />
          </div>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {items.map(([title, status, icon, description]) => (
              <article className="card min-h-44" key={title}>
                <div className="flex items-start justify-between gap-3">
                  <div className="grid h-10 w-10 place-items-center rounded-xl bg-info-bg text-info"><Icon name={icon} /></div>
                  <span className={`status-badge ${securityStatusClass[status]}`}>{status}</span>
                </div>
                <h4 className="mt-4 font-semibold text-ink">{title}</h4>
                <p className="mt-2 text-sm leading-6 text-muted">{description}</p>
              </article>
            ))}
          </div>
        </section>
      ))}

      <section className="grid gap-5 md:grid-cols-2">
        <div className="card">
          <p className="text-sm text-muted">Modo de autenticação</p>
          <h3 className="mt-2 text-xl font-semibold text-ink">{config?.local_admin_enabled ? 'AD/LDAP e acesso local de emergência' : 'AD/LDAP'}</h3>
          <p className="mt-3 text-sm text-muted">Credenciais, servidores e segredos permanecem exclusivamente no backend.</p>
        </div>
        <div className="card">
          <p className="text-sm text-muted">Administração local</p>
          <h3 className="mt-2 text-xl font-semibold text-ink">{config?.local_admin_enabled ? 'Habilitada' : 'Desabilitada'}</h3>
          <p className="mt-3 text-sm text-muted">O acesso local só é permitido quando habilitado explicitamente por ambiente.</p>
        </div>
      </section>

      {config?.local_password_change_allowed && (
        <form className="card max-w-xl space-y-4" onSubmit={submit}>
          <h3 className="font-semibold text-ink">Alterar minha senha local</h3>
          <label className="label">Nova senha<input autoComplete="new-password" className="field mt-2" minLength="8" maxLength="128" required type="password" value={password} onChange={(event) => setPassword(event.target.value)} /></label>
          <label className="label">Confirmar senha<input autoComplete="new-password" className="field mt-2" minLength="8" maxLength="128" required type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
          <button className="btn-primary" type="submit">Alterar senha</button>
        </form>
      )}
    </div>
  )
}

export function AdminPage({ session, search, navigate, notify, refreshStatus }) {
  const isAdmin = session.role === 'admin'
  const permissions = new Set(session.permissions || [])
  // Lote 5b: a Liderança vê Aprovações e Funcionários; o resto é só do admin.
  const canManageEmployees = permissions.has('funcionarios.manage')
  const canPromote = permissions.has('perfis.promote')
  const canEditOutsideMap = permissions.has('funcionarios.fora_mapa')
  // F4: restaurar só admin (D1); arquivar cadastro da equipe Liderança segue a L9.
  const canRestore = permissions.has('funcionarios.restaurar')
  const canManageLeadershipTeam = permissions.has('escalas.lideranca')
  const ownLogins = [session.ci?.username, session.gestor?.username]
    .map((login) => String(login || '').trim().toLowerCase())
    .filter(Boolean)
  const availableTabs = isAdmin ? tabs : tabs.filter(([key]) => key === 'aprovacoes' || (key === 'funcionarios' && canManageEmployees))
  const rawRequestedTab = new URLSearchParams(search).get('tab')
  const requestedTab = rawRequestedTab === 'segurança' ? 'seguranca' : rawRequestedTab
  const activeTab = availableTabs.some(([key]) => key === requestedTab) ? requestedTab : 'aprovacoes'
  const requests = useResource('solicitacoes_pendentes.php')
  const refreshRequests = requests.refresh
  const config = useResource('configuracoes.php', { enabled: isAdmin })
  const employees = useResource('listar_funcionarios.php', { enabled: canManageEmployees })
  const users = useResource('usuarios.php?action=listar', {
    enabled: isAdmin && Boolean(config.data?.configuracoes?.local_admin_enabled),
  })

  useEffect(() => {
    const timer = window.setInterval(() => refreshRequests(), 30000)
    return () => window.clearInterval(timer)
  }, [refreshRequests])

  return (
    <div className="space-y-5">
      <div className="flex gap-2 overflow-x-auto border-b border-line pb-3">
        {availableTabs.map(([key, label]) => (
          <button className={`tab-button ${activeTab === key ? 'tab-button-active' : ''}`} key={key} onClick={() => navigate(`/admin?tab=${key}`)} type="button">{label}</button>
        ))}
      </div>
      {activeTab === 'aprovacoes' && <ApprovalsTab requests={requests} refresh={requests.refresh} refreshStatus={refreshStatus} notify={notify} />}
      {activeTab === 'configuracoes' && <SettingsTab resource={config} notify={notify} />}
      {activeTab === 'funcionarios' && (
        <EmployeesTab
          canEditOutsideMap={canEditOutsideMap}
          canManageLeadershipTeam={canManageLeadershipTeam}
          canPromote={canPromote}
          canRestore={canRestore}
          notify={notify}
          ownEmployeeId={session.ci?.funcionario_id || 0}
          ownLogins={ownLogins}
          resource={employees}
        />
      )}
      {activeTab === 'usuarios' && <UsersTab enabled={Boolean(config.data?.configuracoes?.local_admin_enabled)} resource={users} notify={notify} />}
      {activeTab === 'seguranca' && <SecurityTab config={config.data?.configuracoes} notify={notify} />}
    </div>
  )
}
