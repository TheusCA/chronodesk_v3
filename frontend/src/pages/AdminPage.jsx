import { useEffect, useMemo, useState } from 'react'
import { useResource } from '../hooks/useResource'
import { post } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'

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

function ApprovalsTab({ requests, refresh, refreshStatus, notify }) {
  async function decide(id, action) {
    await perform(
      () => post(`${action}_pausa.php`, { funcionario_id: id }),
      [refresh, refreshStatus],
      notify,
    )
  }

  if (requests.loading) return <LoadingState />
  if (requests.error) return <ErrorState message={requests.error.message} onRetry={requests.refresh} />
  const items = requests.data?.solicitacoes || []

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-400">Solicitações de reunião aguardando decisão.</p>
        <span className="status-badge status-warning">{items.length} pendente(s)</span>
      </div>
      {items.length === 0 && <EmptyState title="Nenhuma aprovação pendente" description="Novas solicitações de reunião aparecerão automaticamente nesta área." />}
      {items.map((request) => (
        <article className="card flex flex-col justify-between gap-5 lg:flex-row lg:items-center" key={request.id}>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="font-semibold text-white">{request.nome}</h3>
              <span className="status-badge status-neutral">Equipe {request.equipe?.toUpperCase()}</span>
            </div>
            <p className="mt-2 text-sm text-slate-300">{request.motivo}</p>
            <p className="mt-1 text-sm text-slate-500">{request.observacao || 'Sem observação.'}</p>
            <p className="mt-3 text-xs text-slate-600">Solicitado em {formatDateTime(request.solicitacao_timestamp)}</p>
          </div>
          <div className="flex gap-2">
            <button className="btn-primary" onClick={() => decide(request.id, 'aprovar')} type="button">Aprovar</button>
            <button className="btn-danger" onClick={() => decide(request.id, 'rejeitar')} type="button">Rejeitar</button>
          </div>
        </article>
      ))}
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
  id: '', nome: '', ad_login: '', equipe: 'n1', jornada_entrada: '08:00',
  jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', ativo: true,
}

function EmployeesTab({ resource, notify }) {
  const [form, setForm] = useState(emptyEmployee)
  const [editing, setEditing] = useState(false)
  const [search, setSearch] = useState('')
  const [team, setTeam] = useState('')
  const [active, setActive] = useState('')
  const filtered = useMemo(() => {
    const items = resource.data?.funcionarios || []
    const term = search.toLowerCase()
    return items.filter((item) => (
      (!term || item.nome.toLowerCase().includes(term) || (item.ad_login || '').toLowerCase().includes(term))
      && (!team || item.equipe === team)
      && (!active || String(item.ativo) === active)
    ))
  }, [active, resource.data, search, team])

  if (resource.loading) return <LoadingState />
  if (resource.error) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />

  function edit(item) {
    setEditing(true)
    setForm({ ...item })
  }

  function reset() {
    setEditing(false)
    setForm(emptyEmployee)
  }

  async function submit(event) {
    event.preventDefault()
    const path = editing ? 'atualizar_funcionario.php' : 'adicionar_funcionario.php'
    const body = editing ? { ...form, funcionario_id: Number(form.id) } : { ...form, id: Number(form.id) }
    const success = await perform(() => post(path, body), [resource.refresh], notify)
    if (success) reset()
  }

  async function deactivate(id) {
    if (!window.confirm('Confirma a desativação deste funcionário?')) return
    await perform(() => post('remover_funcionario.php', { funcionario_id: id }), [resource.refresh], notify)
  }

  return (
    <div className="space-y-5">
      <form className="card space-y-5" onSubmit={submit}>
        <div className="flex items-center justify-between">
          <h3 className="font-bold text-white">{editing ? 'Editar funcionário' : 'Adicionar funcionário'}</h3>
          {editing && <button className="text-sm text-slate-400 hover:text-white" onClick={reset} type="button">Cancelar edição</button>}
        </div>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <label className="label">ID<input className="field mt-2" disabled={editing} max="999" min="1" required type="number" value={form.id} onChange={(event) => setForm({ ...form, id: event.target.value })} /></label>
          <label className="label xl:col-span-2">Nome<input className="field mt-2" maxLength="100" required value={form.nome} onChange={(event) => setForm({ ...form, nome: event.target.value })} /></label>
          <label className="label">Equipe<select className="field mt-2" value={form.equipe} onChange={(event) => setForm({ ...form, equipe: event.target.value })}><option value="n1">N1</option><option value="n2">N2</option></select></label>
          <label className="label xl:col-span-2">Login AD<input className="field mt-2" value={form.ad_login || ''} onChange={(event) => setForm({ ...form, ad_login: event.target.value })} /></label>
          <label className="label">Entrada<input className="field mt-2" type="time" value={form.jornada_entrada} onChange={(event) => setForm({ ...form, jornada_entrada: event.target.value })} /></label>
          <label className="label">Saída<input className="field mt-2" type="time" value={form.jornada_saida} onChange={(event) => setForm({ ...form, jornada_saida: event.target.value })} /></label>
          <label className="label">Início almoço<input className="field mt-2" type="time" value={form.almoco_inicio} onChange={(event) => setForm({ ...form, almoco_inicio: event.target.value })} /></label>
          <label className="label">Fim almoço<input className="field mt-2" type="time" value={form.almoco_fim} onChange={(event) => setForm({ ...form, almoco_fim: event.target.value })} /></label>
          <label className="check-row xl:col-span-2"><input checked={form.ativo} onChange={(event) => setForm({ ...form, ativo: event.target.checked })} type="checkbox" /><span><strong>Funcionário ativo</strong><small>Permite autenticação e ações de pausa.</small></span></label>
        </div>
        <button className="btn-primary" type="submit">{editing ? 'Salvar alterações' : 'Adicionar funcionário'}</button>
      </form>

      <div className="card">
        <div className="mb-5 grid gap-3 md:grid-cols-3">
          <input className="field" placeholder="Buscar nome ou login AD" value={search} onChange={(event) => setSearch(event.target.value)} />
          <select className="field" value={team} onChange={(event) => setTeam(event.target.value)}><option value="">Todas as equipes</option><option value="n1">N1</option><option value="n2">N2</option></select>
          <select className="field" value={active} onChange={(event) => setActive(event.target.value)}><option value="">Ativos e inativos</option><option value="true">Ativos</option><option value="false">Inativos</option></select>
        </div>
        <div className="table-wrap">
          <table className="data-table">
            <thead><tr><th>Funcionário</th><th>Equipe</th><th>Jornada</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              {filtered.map((item) => (
                <tr key={item.id}>
                  <td><strong>{item.nome}</strong><small>{item.ad_login || 'Sem login AD'}</small></td>
                  <td>{item.equipe.toUpperCase()}</td>
                  <td>{item.jornada_entrada} - {item.jornada_saida}</td>
                  <td><span className={`status-badge ${item.ativo ? 'status-success' : 'status-neutral'}`}>{item.ativo ? 'Ativo' : 'Inativo'}</span></td>
                  <td><div className="flex gap-2"><button className="table-action" onClick={() => edit(item)} type="button">Editar</button>{item.ativo && <button className="table-action text-red-300" onClick={() => deactivate(item.id)} type="button">Desativar</button>}</div></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
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

function SecurityTab({ config, notify }) {
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')

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
    <div className="grid gap-5 md:grid-cols-2">
      <section className="card">
        <p className="text-sm text-slate-500">Modo de autenticação</p>
        <h3 className="mt-2 text-xl font-bold uppercase text-white">{config?.auth_source || 'AD'}</h3>
        <p className="mt-3 text-sm text-slate-400">Credenciais, servidores e segredos permanecem exclusivamente no backend.</p>
      </section>
      <section className="card">
        <p className="text-sm text-slate-500">Administração local</p>
        <h3 className="mt-2 text-xl font-bold text-white">{config?.local_admin_enabled ? 'Habilitada' : 'Desabilitada'}</h3>
        <p className="mt-3 text-sm text-slate-400">A alteração de senha local só fica disponível quando explicitamente habilitada por ambiente.</p>
      </section>
      {config?.local_password_change_allowed && (
        <form className="card space-y-4 md:col-span-2 md:max-w-xl" onSubmit={submit}>
          <h3 className="font-bold text-white">Alterar minha senha local</h3>
          <label className="label">Nova senha<input autoComplete="new-password" className="field mt-2" minLength="8" maxLength="128" required type="password" value={password} onChange={(event) => setPassword(event.target.value)} /></label>
          <label className="label">Confirmar senha<input autoComplete="new-password" className="field mt-2" minLength="8" maxLength="128" required type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
          <button className="btn-primary" type="submit">Alterar senha</button>
        </form>
      )}
    </div>
  )
}

export function AdminPage({ search, navigate, notify, refreshStatus }) {
  const requestedTab = new URLSearchParams(search).get('tab')
  const activeTab = tabs.some(([key]) => key === requestedTab) ? requestedTab : 'aprovacoes'
  const requests = useResource('solicitacoes_pendentes.php')
  const refreshRequests = requests.refresh
  const config = useResource('configuracoes.php')
  const employees = useResource('listar_funcionarios.php')
  const users = useResource('usuarios.php?action=listar', { enabled: Boolean(config.data?.configuracoes?.local_admin_enabled) })

  useEffect(() => {
    const timer = window.setInterval(() => refreshRequests(), 30000)
    return () => window.clearInterval(timer)
  }, [refreshRequests])

  return (
    <div className="space-y-5">
      <div className="flex gap-2 overflow-x-auto border-b border-white/5 pb-3">
        {tabs.map(([key, label]) => (
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
