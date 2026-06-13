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
  id: '', nome: '', ad_login: '', equipe: 'n1', access_role: 'tecnico', jornada_entrada: '08:00',
  jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', ativo: true,
}

function EmployeeFields({ form, setForm, editing = false }) {
  function updateRole(role) {
    const equipe = role === 'tecnico' && form.equipe === 'na' ? 'n1' : form.equipe
    setForm({ ...form, access_role: role, equipe })
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
        <select className="field mt-2" value={form.equipe} onChange={(event) => setForm({ ...form, equipe: event.target.value })}>
          <option value="n1">N1</option>
          <option value="n2">N2</option>
          <option disabled={form.access_role === 'tecnico'} value="na">Não se aplica</option>
        </select>
      </label>
      <label className="label">Perfil de acesso
        <select className="field mt-2" value={form.access_role || 'tecnico'} onChange={(event) => updateRole(event.target.value)}>
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
      (!term || item.nome.toLowerCase().includes(term) || (item.ad_login || '').toLowerCase().includes(term) || item.equipe.toLowerCase().includes(term))
      && (!team || item.equipe === team)
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
    if (form.access_role === 'tecnico' && form.equipe === 'na') return 'Técnicos devem pertencer à equipe N1 ou N2.'
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
          <p className="mt-1 text-sm text-slate-500">Equipe operacional e perfil de acesso são controles independentes.</p>
        </div>
        <EmployeeFields form={createForm} setForm={setCreateForm} />
        <button className="btn-primary" disabled={submitting} type="submit">{submitting ? 'Adicionando...' : 'Adicionar funcionário'}</button>
      </form>

      <div className="card">
        <div className="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <input className="field" placeholder="Buscar nome, login ou equipe" value={search} onChange={(event) => setSearch(event.target.value)} />
          <select className="field" value={team} onChange={(event) => setTeam(event.target.value)}><option value="">Todas as equipes</option><option value="n1">N1</option><option value="n2">N2</option><option value="na">Não se aplica</option></select>
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
                  <td>{item.equipe === 'na' ? 'Não se aplica' : item.equipe.toUpperCase()}</td>
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
  ['Content Security Policy', 'Ativo', 'shield', "Política restrita a 'self', sem unsafe-inline."],
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

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {protections.map(([title, status, icon, description]) => (
          <article className="card min-h-44" key={title}>
            <div className="flex items-start justify-between gap-3">
              <div className="grid h-10 w-10 place-items-center rounded-xl bg-blue-500/10 text-blue-300"><Icon name={icon} /></div>
              <span className={`status-badge ${securityStatusClass[status]}`}>{status}</span>
            </div>
            <h3 className="mt-4 font-bold text-white">{title}</h3>
            <p className="mt-2 text-sm leading-6 text-slate-400">{description}</p>
          </article>
        ))}
      </div>

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

export function AdminPage({ search, navigate, notify, refreshStatus }) {
  const rawRequestedTab = new URLSearchParams(search).get('tab')
  const requestedTab = rawRequestedTab === 'segurança' ? 'seguranca' : rawRequestedTab
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
