import { useEffect, useMemo, useState } from 'react'
import { useResource } from '../hooks/useResource'
import { post } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { Icon } from '../components/ui/Icon'

const tabs = [
  ['aprovacoes', 'Aprovações'],
  ['configuracoes', 'Configurações'],
  ['funcionarios', 'Funcionários'],
  ['usuarios', 'Usuários'],
  ['seguranca', 'Segurança'],
]

async function perform(action, refreshers, notify) {
  try {
    const result = await action()
    notify(result.mensagem || result.message || 'Operação concluída.')
    await Promise.all(refreshers.map((refresh) => refresh()))
    return true
  } catch (error) {
    notify(error.message, 'error')
    return false
  }
}

function approvalMinutes(value) {
  const minutes = Number(value || 0)
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}min`
}

function ApprovalActions({ onApprove, onReject }) {
  return (
    <div className="flex gap-2">
      <button className="btn-primary" onClick={onApprove} type="button">Aprovar</button>
      <button className="btn-danger" onClick={onReject} type="button">Reprovar</button>
    </div>
  )
}

function ApprovalSection({ title, count, children }) {
  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-400">{title}</h3>
        <span className="status-badge status-warning">{count} pendente(s)</span>
      </div>
      {count === 0 ? <div className="card py-6 text-sm text-slate-500">Nenhuma pendencia neste grupo.</div> : children}
    </section>
  )
}

function ApprovalsTab({ requests, refresh, refreshStatus, notify }) {
  async function decidePause(id, action) {
    await perform(
      () => post(`${action}_pausa.php`, { funcionario_id: id }),
      [refresh, refreshStatus],
      notify,
    )
  }

  async function decideWorkflow(endpoint, id, decision) {
    await perform(
      () => post(endpoint, { action: 'decision', id, decision }),
      [refresh, refreshStatus],
      notify,
    )
  }

  if (requests.loading) return <LoadingState />
  if (requests.error) return <ErrorState message={requests.error.message} onRetry={requests.refresh} />
  const pauses = requests.data?.solicitacoes || []
  const overtime = requests.data?.overtime || []
  const adjustments = requests.data?.time_adjustments || []
  const total = pauses.length + overtime.length + adjustments.length

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-400">Decisoes operacionais pendentes centralizadas.</p>
        <span className="status-badge status-warning">{total} pendente(s)</span>
      </div>
      {total === 0 && <EmptyState title="Nenhuma aprovacao pendente" description="Novas solicitacoes operacionais aparecerao automaticamente nesta area." />}

      <ApprovalSection count={pauses.length} title="Pausas/Reunioes pendentes">
        {pauses.map((request) => (
          <article className="card flex flex-col justify-between gap-5 lg:flex-row lg:items-center" key={`pause-${request.id}`}>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h4 className="font-semibold text-white">{request.nome}</h4>
                <span className="status-badge status-neutral">Equipe {request.equipe?.toUpperCase()}</span>
                <span className="status-badge status-warning">Pendente</span>
              </div>
              <p className="mt-2 text-sm text-slate-300">{request.motivo}</p>
              <p className="mt-1 text-sm text-slate-500">{request.observacao || 'Sem observacao.'}</p>
              <p className="mt-3 text-xs text-slate-600">Solicitado em {formatDateTime(request.solicitacao_timestamp)}</p>
            </div>
            <ApprovalActions onApprove={() => decidePause(request.id, 'aprovar')} onReject={() => decidePause(request.id, 'rejeitar')} />
          </article>
        ))}
      </ApprovalSection>

      <ApprovalSection count={overtime.length} title="Horas extras pendentes">
        {overtime.map((item) => (
          <article className="card flex flex-col justify-between gap-5 lg:flex-row lg:items-center" key={`overtime-${item.id}`}>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h4 className="font-semibold text-white">{item.employee_name}</h4>
                <span className="status-badge status-neutral">Equipe {item.team?.toUpperCase()}</span>
                <span className="status-badge status-warning">{item.status}</span>
              </div>
              <p className="mt-2 text-sm text-slate-300">Hora extra em {item.work_date} das {String(item.start_time).slice(0, 5)} as {String(item.end_time).slice(0, 5)} ({approvalMinutes(item.total_minutes)})</p>
              <p className="mt-1 text-sm text-slate-500">{item.reason} - {item.justification}</p>
              <p className="mt-3 text-xs text-slate-600">Criado em {formatDateTime(item.created_at)}</p>
            </div>
            <ApprovalActions onApprove={() => decideWorkflow('portal/overtime.php', item.id, 'approved')} onReject={() => decideWorkflow('portal/overtime.php', item.id, 'rejected')} />
          </article>
        ))}
      </ApprovalSection>

      <ApprovalSection count={adjustments.length} title="Ajustes de ponto pendentes">
        {adjustments.map((item) => (
          <article className="card flex flex-col justify-between gap-5 lg:flex-row lg:items-center" key={`adjustment-${item.id}`}>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h4 className="font-semibold text-white">{item.employee_name}</h4>
                <span className="status-badge status-neutral">Equipe {item.team?.toUpperCase()}</span>
                <span className="status-badge status-warning">{item.status}</span>
              </div>
              <p className="mt-2 text-sm text-slate-300">Ajuste em {item.adjustment_date}: {item.adjustment_type} {item.correct_time ? `- ${String(item.correct_time).slice(0, 5)}` : ''}</p>
              <p className="mt-1 text-sm text-slate-500">{item.justification}</p>
              <p className="mt-3 text-xs text-slate-600">Criado em {formatDateTime(item.created_at)}</p>
            </div>
            <ApprovalActions onApprove={() => decideWorkflow('portal/time_corrections.php', item.id, 'approved')} onReject={() => decideWorkflow('portal/time_corrections.php', item.id, 'rejected')} />
          </article>
        ))}
      </ApprovalSection>
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
    await perform(() => post('salvar_configuracao.php', form), [resource.refresh], notify)
  }

  return (
    <form className="card max-w-3xl space-y-6" onSubmit={submit}>
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
      <button className="btn-primary" type="submit">Salvar configurações</button>
    </form>
  )
}

const emptyEmployee = {
  id: '', nome: '', ad_login: '', equipe: 'n1', access_role: 'tecnico', jornada_entrada: '08:00',
  jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', ativo: true,
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

function EmployeeFields({ form, setForm, editing = false }) {
  function updateRole(role) {
    if (form.equipe === 'lideranca') return
    setForm({ ...form, access_role: role })
  }

  function updateTeam(team) {
    if (team === 'lideranca') {
      setForm({ ...form, equipe: 'lideranca', access_role: 'admin' })
      return
    }
    if (form.equipe === 'lideranca' && form.access_role === 'admin') {
      const confirmed = window.confirm('Ao sair de Liderança, revise manualmente o perfil de acesso se este colaborador não deve permanecer como Admin.')
      if (!confirmed) return
    }
    setForm({ ...form, equipe: team })
  }

  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <label className="label">ID
        <input className="field mt-2" disabled={editing} max="999" min="1" required type="number" value={form.id} onChange={(event) => setForm({ ...form, id: event.target.value })} />
      </label>
      <label className="label xl:col-span-2">Nome
        <input className="field mt-2" maxLength="100" minLength="3" required value={form.nome} onChange={(event) => setForm({ ...form, nome: event.target.value })} />
      </label>
      <label className="label">Login AD
        <input className="field mt-2" maxLength="100" placeholder="nome.sobrenome" value={form.ad_login || ''} onChange={(event) => setForm({ ...form, ad_login: event.target.value })} />
      </label>
      <label className="label">Equipe operacional
        <select className="field mt-2" value={normalizeTeam(form.equipe)} onChange={(event) => updateTeam(event.target.value)}>
          <option value="n1">N1</option>
          <option value="n2">N2</option>
          <option value="lideranca">Liderança</option>
        </select>
      </label>
      <label className="label">Perfil de acesso
        <select className="field mt-2" disabled={form.equipe === 'lideranca'} value={form.access_role || 'tecnico'} onChange={(event) => updateRole(event.target.value)}>
          <option value="tecnico">Técnico</option>
          <option value="gestor">Gestor</option>
          <option value="admin">Admin</option>
          <option value="somente_leitura">Somente leitura</option>
        </select>
      </label>
      <label className="label">Entrada
        <input className="field mt-2" required type="time" value={form.jornada_entrada} onChange={(event) => setForm({ ...form, jornada_entrada: event.target.value })} />
      </label>
      <label className="label">Saída
        <input className="field mt-2" required type="time" value={form.jornada_saida} onChange={(event) => setForm({ ...form, jornada_saida: event.target.value })} />
      </label>
      <label className="label">Início almoço
        <input className="field mt-2" required type="time" value={form.almoco_inicio} onChange={(event) => setForm({ ...form, almoco_inicio: event.target.value })} />
      </label>
      <label className="label">Fim almoço
        <input className="field mt-2" required type="time" value={form.almoco_fim} onChange={(event) => setForm({ ...form, almoco_fim: event.target.value })} />
      </label>
      <label className="check-row md:col-span-2">
        <input checked={Boolean(form.ativo)} onChange={(event) => setForm({ ...form, ativo: event.target.checked })} type="checkbox" />
        <span><strong>Funcionário ativo</strong><small>Permite autenticação e aplica o perfil configurado.</small></span>
      </label>
    </div>
  )
}

function EmployeesTab({ resource, notify }) {
  const [createForm, setCreateForm] = useState(emptyEmployee)
  const [editForm, setEditForm] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [search, setSearch] = useState('')
  const [team, setTeam] = useState('')
  const [role, setRole] = useState('')
  const [active, setActive] = useState('')
  const filtered = useMemo(() => {
    const items = resource.data?.funcionarios || []
    const term = search.toLowerCase()
    return items.filter((item) => (
      (!term || item.nome.toLowerCase().includes(term) || (item.ad_login || '').toLowerCase().includes(term) || teamLabel(item.equipe).toLowerCase().includes(term))
      && (!team || normalizeTeam(item.equipe) === team)
      && (!role || item.access_role === role)
      && (!active || String(item.ativo) === active)
    ))
  }, [active, resource.data, role, search, team])

  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  function edit(item) {
    setEditForm({
      ...emptyEmployee,
      ...item,
      access_role: item.access_role || 'tecnico',
      equipe: normalizeTeam(item.equipe),
    })
  }

  function validateUnique(form, editing = false) {
    const employees = resource.data?.funcionarios || []
    const duplicateId = !editing && employees.some((item) => Number(item.id) === Number(form.id))
    const login = String(form.ad_login || '').trim().toLowerCase()
    const duplicateLogin = login && employees.some((item) => (
      String(item.ad_login || '').trim().toLowerCase() === login
      && (!editing || Number(item.id) !== Number(form.id))
      && item.ativo
    ))
    if (duplicateId) return 'Já existe um funcionário com este ID.'
    if (duplicateLogin) return 'Este login AD já está vinculado a outro funcionário ativo.'
    if (login && !/^[a-z0-9._@-]+$/.test(login)) return 'O login AD contém caracteres inválidos.'
    return ''
  }

  async function create(event) {
    event.preventDefault()
    const error = validateUnique(createForm)
    if (error) {
      notify(error, 'error')
      return
    }
    setSubmitting(true)
    const success = await perform(
      () => post('adicionar_funcionario.php', { ...createForm, id: Number(createForm.id) }),
      [resource.refresh],
      notify,
    )
    setSubmitting(false)
    if (success) setCreateForm(emptyEmployee)
  }

  async function update(event) {
    event.preventDefault()
    const error = validateUnique(editForm, true)
    if (error) {
      notify(error, 'error')
      return
    }
    setSubmitting(true)
    const success = await perform(
      () => post('atualizar_funcionario.php', { ...editForm, funcionario_id: Number(editForm.id) }),
      [resource.refresh],
      notify,
    )
    setSubmitting(false)
    if (success) setEditForm(null)
  }

  async function deactivate(id) {
    if (!window.confirm('Confirma a desativação deste funcionário?')) return
    await perform(() => post('remover_funcionario.php', { funcionario_id: id }), [resource.refresh], notify)
  }

  return (
    <div className="space-y-5">
      <form className="card space-y-5" onSubmit={create}>
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-400">Novo cadastro</p>
          <h3 className="mt-2 font-bold text-white">Adicionar funcionário</h3>
          <p className="mt-1 text-sm text-slate-500">Equipe Liderança aplica automaticamente o perfil Admin.</p>
        </div>
        <EmployeeFields form={createForm} setForm={setCreateForm} />
        <button className="btn-primary" disabled={submitting} type="submit">{submitting ? 'Adicionando...' : 'Adicionar funcionário'}</button>
      </form>

      <div className="card">
        <div className="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <input className="field" placeholder="Buscar nome, login ou equipe" value={search} onChange={(event) => setSearch(event.target.value)} />
          <select className="field" value={team} onChange={(event) => setTeam(event.target.value)}><option value="">Todas as equipes</option><option value="n1">N1</option><option value="n2">N2</option><option value="lideranca">Liderança</option></select>
          <select className="field" value={role} onChange={(event) => setRole(event.target.value)}><option value="">Todos os perfis</option><option value="tecnico">Técnico</option><option value="gestor">Gestor</option><option value="admin">Admin</option><option value="somente_leitura">Somente leitura</option></select>
          <select className="field" value={active} onChange={(event) => setActive(event.target.value)}><option value="">Ativos e inativos</option><option value="true">Ativos</option><option value="false">Inativos</option></select>
        </div>
        <div className="table-wrap">
          <table className="data-table">
            <thead><tr><th>Funcionário</th><th>Equipe</th><th>Perfil</th><th>Jornada</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              {filtered.map((item) => (
                <tr key={item.id}>
                  <td><strong>{item.nome}</strong><small>ID {item.id} · {item.ad_login || 'Sem login AD'}</small></td>
                  <td>{teamLabel(item.equipe)}</td>
                  <td><span className="status-badge status-info">{(item.access_role || 'tecnico').replace('_', ' ')}</span></td>
                  <td>{item.jornada_entrada} - {item.jornada_saida}</td>
                  <td><span className={`status-badge ${item.ativo ? 'status-success' : 'status-neutral'}`}>{item.ativo ? 'Ativo' : 'Inativo'}</span></td>
                  <td><div className="flex gap-2"><button className="table-action" onClick={() => edit(item)} type="button">Editar</button>{item.ativo && <button className="table-action text-red-300" onClick={() => deactivate(item.id)} type="button">Desativar</button>}</div></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {filtered.length === 0 && <p className="py-8 text-center text-sm text-slate-500">Nenhum funcionário corresponde aos filtros.</p>}
      </div>

      {editForm && (
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/70 p-4 backdrop-blur-sm">
          <button aria-label="Cancelar edição" className="absolute inset-0" onClick={() => setEditForm(null)} type="button" />
          <form className="card relative w-full max-w-5xl space-y-5 border-blue-500/20" onSubmit={update}>
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.16em] text-amber-400">Alteração auditada</p>
                <h3 className="mt-2 text-xl font-bold text-white">Editar funcionário</h3>
                <p className="mt-1 text-sm text-slate-500">Dados atuais do CI carregados. Alterações de perfil são restritas a administradores e registradas em auditoria.</p>
              </div>
              <button className="text-sm text-slate-400 hover:text-white" onClick={() => setEditForm(null)} type="button">Cancelar</button>
            </div>
            <EmployeeFields editing form={editForm} setForm={setEditForm} />
            <div className="flex justify-end gap-3">
              <button className="btn-secondary" disabled={submitting} onClick={() => setEditForm(null)} type="button">Cancelar</button>
              <button className="btn-primary" disabled={submitting} type="submit">{submitting ? 'Salvando...' : 'Salvar alterações'}</button>
            </div>
          </form>
        </div>
      )}
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
    const success = await perform(() => post('usuarios.php?action=criar', form), [resource.refresh], notify)
    if (success) setForm({ username: '', password: '', role: 'gestor' })
  }

  async function updateRole(id, role) {
    if (!window.confirm(`Confirma a alteração do perfil para ${role}?`)) return
    await perform(() => post('usuarios.php?action=atualizar_role', { id, role }), [resource.refresh], notify)
  }

  async function remove(id) {
    if (!window.confirm('Confirma a remoção deste usuário local?')) return
    await perform(() => post('usuarios.php?action=deletar', { id }), [resource.refresh], notify)
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[360px_1fr]">
      <form className="card space-y-4" onSubmit={create}>
        <h3 className="font-bold text-white">Criar usuário local</h3>
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
                <td><button className="table-action text-red-300" onClick={() => remove(user.id)} type="button">Remover</button></td>
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
    item[0] === 'Autenticação AD/LDAP' && config?.auth_source !== 'ad'
      ? [item[0], 'Parcial', item[2], 'O ambiente atual não informa AD como fonte principal de autenticação.']
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
    const success = await perform(
      () => post('alterar_senha_admin.php', { nova_senha: password }),
      [],
      notify,
    )
    if (success) {
      setPassword('')
      setConfirmation('')
    }
  }

  return (
    <div className="space-y-5">
      <section className="card overflow-hidden border-blue-500/15">
        <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-blue-400">Postura de segurança</p>
            <h2 className="mt-2 text-2xl font-black text-white">Checklist de Segurança</h2>
            <p className="mt-2 max-w-2xl text-sm text-slate-400">Visão consolidada dos controles presentes no código e na configuração de deploy, sem expor segredos ou caminhos internos.</p>
          </div>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div className="rounded-xl border border-emerald-500/15 bg-emerald-500/5 p-3"><strong className="block text-xl text-emerald-300">{activeCount}</strong><span className="text-xs text-slate-500">Ativas/configuradas</span></div>
            <div className="rounded-xl border border-amber-500/15 bg-amber-500/5 p-3"><strong className="block text-xl text-amber-300">{pendingCount}</strong><span className="text-xs text-slate-500">Parciais/pendentes</span></div>
            <div className="rounded-xl border border-red-500/15 bg-red-500/5 p-3"><strong className="block text-xl text-red-300">1</strong><span className="text-xs text-slate-500">Risco relevante</span></div>
            <div className="rounded-xl border border-blue-500/15 bg-blue-500/5 p-3"><strong className="block text-sm text-blue-300">13/06/2026</strong><span className="text-xs text-slate-500">Última revisão</span></div>
          </div>
        </div>
      </section>

      {groupedProtections.map(([group, items]) => (
        <section className="space-y-3" key={group}>
          <div className="flex items-center gap-3">
            <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-400">{group}</h3>
            <span className="h-px flex-1 bg-white/5" />
          </div>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {items.map(([title, status, icon, description]) => (
              <article className="card min-h-44" key={title}>
                <div className="flex items-start justify-between gap-3">
                  <div className="grid h-10 w-10 place-items-center rounded-xl bg-blue-500/10 text-blue-300"><Icon name={icon} /></div>
                  <span className={`status-badge ${securityStatusClass[status]}`}>{status}</span>
                </div>
                <h4 className="mt-4 font-bold text-white">{title}</h4>
                <p className="mt-2 text-sm leading-6 text-slate-400">{description}</p>
              </article>
            ))}
          </div>
        </section>
      ))}

      <section className="grid gap-5 md:grid-cols-2">
        <div className="card">
          <p className="text-sm text-slate-500">Modo de autenticação</p>
          <h3 className="mt-2 text-xl font-bold uppercase text-white">{config?.auth_source || 'Não informado'}</h3>
          <p className="mt-3 text-sm text-slate-400">Credenciais, servidores e segredos permanecem exclusivamente no backend.</p>
        </div>
        <div className="card">
          <p className="text-sm text-slate-500">Administração local</p>
          <h3 className="mt-2 text-xl font-bold text-white">{config?.local_admin_enabled ? 'Habilitada' : 'Desabilitada'}</h3>
          <p className="mt-3 text-sm text-slate-400">O acesso local só é permitido quando habilitado explicitamente por ambiente.</p>
        </div>
      </section>

      {config?.local_password_change_allowed && (
        <form className="card max-w-xl space-y-4" onSubmit={submit}>
          <h3 className="font-bold text-white">Alterar minha senha local</h3>
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
  const availableTabs = isAdmin ? tabs : tabs.filter(([key]) => key === 'aprovacoes')
  const rawRequestedTab = new URLSearchParams(search).get('tab')
  const requestedTab = rawRequestedTab === 'segurança' ? 'seguranca' : rawRequestedTab
  const activeTab = availableTabs.some(([key]) => key === requestedTab) ? requestedTab : 'aprovacoes'
  const requests = useResource('solicitacoes_pendentes.php')
  const refreshRequests = requests.refresh
  const config = useResource('configuracoes.php', { enabled: isAdmin })
  const employees = useResource('listar_funcionarios.php', { enabled: isAdmin })
  const users = useResource('usuarios.php?action=listar', {
    enabled: isAdmin && Boolean(config.data?.configuracoes?.local_admin_enabled),
  })

  useEffect(() => {
    const timer = window.setInterval(() => refreshRequests(), 30000)
    return () => window.clearInterval(timer)
  }, [refreshRequests])

  return (
    <div className="space-y-5">
      <div className="flex gap-2 overflow-x-auto border-b border-white/5 pb-3">
        {availableTabs.map(([key, label]) => (
          <button className={`tab-button ${activeTab === key ? 'tab-button-active' : ''}`} key={key} onClick={() => navigate(`/admin?tab=${key}`)} type="button">{label}</button>
        ))}
      </div>
      {activeTab === 'aprovacoes' && <ApprovalsTab requests={requests} refresh={requests.refresh} refreshStatus={refreshStatus} notify={notify} />}
      {activeTab === 'configuracoes' && <SettingsTab resource={config} notify={notify} />}
      {activeTab === 'funcionarios' && <EmployeesTab resource={employees} notify={notify} />}
      {activeTab === 'usuarios' && <UsersTab enabled={Boolean(config.data?.configuracoes?.local_admin_enabled)} resource={users} notify={notify} />}
      {activeTab === 'seguranca' && <SecurityTab config={config.data?.configuracoes} notify={notify} />}
    </div>
  )
}
