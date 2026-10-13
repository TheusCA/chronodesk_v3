import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const srcRoot = join(frontendRoot, 'src')
const pagesRoot = join(srcRoot, 'pages')

function read(relativePath) {
  return readFileSync(join(frontendRoot, relativePath), 'utf8')
}

function walk(dir) {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) return walk(path)
    return path
  })
}

const packageJson = JSON.parse(read('package.json'))
const packageLock = read('package-lock.json')
const schemaSource = read('src/lib/formSchemas.ts')
const adminSource = read('src/pages/AdminPage.jsx')
const operationalSource = read('src/pages/OperationalPages.jsx')
const actionFeedbackSource = read('src/lib/actionFeedback.ts')
const appSource = read('src/App.jsx')
const layoutSource = read('src/components/PortalLayout.jsx')
const loginSource = read('src/pages/LoginPage.jsx')
const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')

assert.equal(packageJson.scripts['qa:employee-form'], 'node scripts/qa-employee-form.mjs', 'Script qa:employee-form deve estar registrado')
assert.equal(existsSync(join(frontendRoot, 'scripts', 'qa-employee-form.mjs')), true, 'QA dedicado de formulario de funcionario deve existir')

// As regras do schema sao verificadas por comportamento (safeParse) mais abaixo, nao por regex no fonte.
assert.match(schemaSource, /export const employeeFormSchema = z\.object\(\{/, 'employeeFormSchema deve existir')

assert.match(adminSource, /import \{ useForm \} from 'react-hook-form'/, 'AdminPage deve usar React Hook Form')
assert.match(adminSource, /import \{ employeeFormSchema \} from '\.\.\/lib\/formSchemas'/, 'AdminPage deve importar employeeFormSchema')
assert.match(adminSource, /useForm\(\{[\s\S]*defaultValues:\s*emptyEmployee[\s\S]*\}\)/, 'Formulario de funcionario deve usar emptyEmployee como default')
assert.match(adminSource, /handleCreateEmployeeSubmit\(create\)/, 'Criacao deve usar handleSubmit')
assert.match(adminSource, /handleEditEmployeeSubmit\(update\)/, 'Edicao deve usar handleSubmit')
assert.match(adminSource, /employeeFormSchema\.safeParse\(values\)/, 'AdminPage deve validar funcionario com safeParse')
assert.match(adminSource, /setError\(field, \{ type: 'zod'/, 'Erros Zod devem ir para formState.errors')
assert.match(adminSource, /register\('id'\)[\s\S]*register\('nome'\)[\s\S]*register\('jornada_entrada'\)[\s\S]*register\('jornada_saida'\)[\s\S]*register\('almoco_inicio'\)[\s\S]*register\('almoco_fim'\)/, 'Campos de funcionario devem ser registrados no RHF')
// F4 (D4): sem o checkbox "Funcionário ativo"; ativo nao e campo do formulario.
assert.doesNotMatch(adminSource, /register\('ativo'\)|Funcionário ativo/, 'Formulario sem o campo ativo (arquivar e restaurar sao acoes da linha)')
assert.match(adminSource, /readOnly=\{editing\}[\s\S]*\{\.\.\.register\('id'\)\}/, 'Edicao deve preservar id no submit sem disabled')

const createSource = adminSource.match(/async function create\(values\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(createSource, /employeeFormSchema\.safeParse\(values\)/, 'Criacao deve validar schema')
assert.match(createSource, /post\('adicionar_funcionario\.php', \{ \.\.\.parsed\.data, id: Number\(parsed\.data\.id\) \}\)/, 'Criacao deve preservar endpoint e id numerico')
assert.match(createSource, /ACTION_FEEDBACK\.employeeCreated/, 'Criacao deve preservar feedback')
assert.match(createSource, /resetCreateEmployee\(emptyEmployee\)/, 'Criacao deve limpar formulario')

const updateSource = adminSource.match(/async function update\(values\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(updateSource, /employeeFormSchema\.safeParse\(values\)/, 'Edicao deve validar schema')
assert.match(updateSource, /post\('atualizar_funcionario\.php', \{ \.\.\.parsed\.data, id: Number\(parsed\.data\.id\), funcionario_id: Number\(parsed\.data\.id\) \}\)/, 'Edicao deve preservar endpoint, id e funcionario_id numericos')
assert.match(updateSource, /ACTION_FEEDBACK\.employeeUpdated/, 'Edicao deve preservar feedback')
assert.match(updateSource, /\(\) => setEditForm\(null\)/, 'Edicao deve fechar modo edicao apos sucesso')

// F4 (desenho 9.6): arquivar (remover_funcionario.php, mesmo endereco) e
// restaurar (so admin), confirmados na propria linha.
const archiveSource = adminSource.match(/async function archiveOrRestore\(item, kind\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(archiveSource, /post\(archiving \? 'remover_funcionario\.php' : 'restaurar_funcionario\.php', \{ funcionario_id: item\.id \}\)/, 'Arquivar e restaurar pelos endpoints do F4, com funcionario_id')
assert.match(archiveSource, /ACTION_FEEDBACK\.employeeArchived\} \$\{archiveEffectsSummary\(result\?\.efeitos\)\}/, 'Arquivar mostra a mensagem e o resumo dos efeitos')
assert.match(archiveSource, /ACTION_FEEDBACK\.employeeRestored/, 'Restaurar com feedback proprio')
assert.match(archiveSource, /for \(const warning of result\?\.avisos \|\| \[\]\) notify\(warning, 'warning'\)/, 'Avisos da resposta (allowlist, estado) aparecem como alerta')
assert.match(archiveSource, /if \(editForm && Number\(editForm\.id\) === Number\(item\.id\)\) setEditForm\(null\)/, 'Arquivar fecha a edicao do mesmo cadastro')
assert.match(archiveSource, /error: error\?\.message/, 'Recusa do servidor aparece no painel da linha')
const rowActionsSource = adminSource.match(/function rowActions\(item\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(rowActionsSource, /if \(state === 'ativo' && canManageRecord\(item\)\)[\s\S]*Editar/, 'Editar so em cadastro ativo (arquivado da 409 no servidor)')
assert.match(rowActionsSource, /if \(state !== 'arquivado' && canArchive\(item\)\)[\s\S]*state === 'inativo_antigo' \? 'Arquivar \(regularizar\)' : 'Arquivar'/, 'Arquivar no ativo e no inativo antigo sem data (D5); arquivado pelo F4 nao')
assert.match(rowActionsSource, /if \(state !== 'ativo' && canRestore\)[\s\S]*Restaurar/, 'Restaurar so com a permissao e so em inativo')
assert.match(adminSource, /const canRestore = permissions\.has\('funcionarios\.restaurar'\)/, 'Restaurar pela permissao do servidor (D1)')
assert.match(adminSource, /const canManageLeadershipTeam = permissions\.has\('escalas\.lideranca'\)/, 'Equipe Lideranca pela permissao da L9')
const canArchiveSource = adminSource.match(/function canArchive\(item\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(canArchiveSource, /canManageRecord\(item\)[\s\S]*canManageLeadershipTeam \|\| normalizeTeam\(item\.equipe\) !== 'lideranca'[\s\S]*!isOwnRecord\(item\)/, 'Arquivar: regra do 5b, L9 e nunca o proprio cadastro (D3)')
assert.match(adminSource, /\{pendingAction\?\.id === item\.id && \([\s\S]*<EmployeeActionConfirmation[\s\S]*onConfirm=\{\(\) => archiveOrRestore\(item, pendingAction\.kind\)\}/, 'Confirmacao na propria linha antes de arquivar ou restaurar')
assert.match(adminSource, /<option value="ativos">Ativos<\/option><option value="arquivados">Arquivados<\/option><option value="">Ativos e arquivados<\/option>/, 'Filtro Ativos/Arquivados')
assert.match(adminSource, /useState\('ativos'\)/, 'A lista abre nos ativos')
const archivePanelSource = adminSource.match(/function EmployeeActionConfirmation\([\s\S]*?\n}\n/)?.[0] || ''
assert.doesNotMatch(archivePanelSource + archiveSource + rowActionsSource, /\bconfirm\(|\balert\(|\bprompt\(/, 'Arquivar e restaurar sem dialogo do navegador')

assert.match(adminSource, /const result = await runAction\(\{[\s\S]*action,[\s\S]*refresh: refreshers,[\s\S]*notify,[\s\S]*successMessage,[\s\S]*onSuccess/, 'perform deve continuar delegando ao runAction')
assert.match(actionFeedbackSource, /employeeCreated: 'Funcionário adicionado com sucesso\.'/u, 'ACTION_FEEDBACK.employeeCreated deve existir')
assert.match(actionFeedbackSource, /employeeUpdated: 'Funcionário atualizado com sucesso\.'/u, 'ACTION_FEEDBACK.employeeUpdated deve existir')
assert.match(actionFeedbackSource, /employeeArchived: 'Funcionário arquivado\.'/u, 'ACTION_FEEDBACK.employeeArchived deve existir')
assert.doesNotMatch(actionFeedbackSource, /employeeRemoved/, 'Sem a mensagem de remocao (o cadastro e arquivado)')
assert.match(operationalSource, /scheduleRuleSchema\.safeParse\(/, 'Formulario de escala da Fase 20 deve continuar usando Zod')

for (const allowedDependency of [
  '@tanstack/react-query',
  '@tanstack/react-table',
  'react-hook-form',
  'zod',
]) {
  assert.equal(typeof packageJson.dependencies?.[allowedDependency], 'string', `${allowedDependency} deve permanecer instalado`)
}

const installedDependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
}
for (const blockedDependency of [
  '@hookform/resolvers',
  'formik',
  'yup',
  'joi',
  'sweetalert',
  'toastify',
  'sonner',
  'notistack',
  'radix',
  'headlessui',
  '@loadable/component',
  '@playwright/test',
  'cypress',
  'sentry',
  'logrocket',
]) {
  assert.equal(installedDependencies[blockedDependency], undefined, `Dependencia proibida instalada: ${blockedDependency}`)
  assert.doesNotMatch(packageLock, new RegExp(`"node_modules/${blockedDependency.replace('/', '\\/')}"`, 'i'), `Dependencia proibida no lock: ${blockedDependency}`)
}

assert.match(sourceText, /Desenvolvido por Matheus Camargo/, 'Credito do desenvolvedor deve continuar existindo')
assert.match(loginSource, /Desenvolvido por Matheus Camargo/, 'Credito deve permanecer no login')
assert.match(layoutSource, /Desenvolvido por Matheus Camargo/, 'Credito deve permanecer no layout autenticado')
assert.equal(existsSync(join(srcRoot, 'components', 'RouteErrorBoundary.jsx')), true, 'RouteErrorBoundary deve continuar existindo')
assert.match(appSource, /<RouteErrorBoundary resetKey=\{router\.path\}>[\s\S]*<Suspense/, 'Code splitting deve continuar protegido por RouteErrorBoundary e Suspense')

for (const forbidden of [
  'dangerouslySetInnerHTML',
  'innerHTML',
  'localStorage',
]) {
  assert.doesNotMatch(sourceText, new RegExp(forbidden), `${forbidden} nao deve aparecer em frontend/src`)
}

assert.doesNotMatch(sourceText, /\bwindow\.alert\b|\balert\(/, 'Nao deve haver window.alert ou alert()')

// Inventario fechado de window.confirm: vale com a arvore limpa (depois do commit),
// ao contrario de git diff da arvore de trabalho, que fica vazio e aprova qualquer coisa.
const confirmInventory = Object.fromEntries(sourceFiles
  .map((path) => [relative(frontendRoot, path).replaceAll('\\', '/'), readFileSync(path, 'utf8').match(/\bwindow\.confirm\(/g)?.length || 0])
  .filter(([, count]) => count > 0))
assert.deepEqual(confirmInventory, {
  'src/App.jsx': 1,
  // Lote 5b: saiu o aviso de "sair de Lideranca" (equipe nao define mais o perfil).
  // F4: saiu o confirm do "Desativar" (arquivar confirma na linha).
  'src/pages/AdminPage.jsx': 2,
  'src/pages/DocumentsPage.jsx': 1,
  'src/pages/OperationalPages.jsx': 1,
  'src/pages/PaMapPage.jsx': 1,
}, 'Fase 21 nao deve adicionar novo window.confirm')
assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutation deve ser criada')

// A antiga checagem "arquivos protegidos nao alterados" (git diff --name-only) foi removida:
// ela so enxergava mudancas nao commitadas e passava vazia depois do commit. Escopo do diff
// e verificacao de revisao (git diff --name-only <base>..HEAD), registrada em docs/EMPLOYEE_FORM_RHF_ZOD.md.

const { employeeFormSchema } = await import(pathToFileURL(join(srcRoot, 'lib', 'formSchemas.ts')).href)
const validEmployee = {
  id: '12',
  nome: 'Fulano de Tal',
  ad_login: 'fulano.tal',
  equipe: 'n1',
  access_role: 'tecnico',
  jornada_entrada: '08:00',
  jornada_saida: '17:00',
  almoco_inicio: '12:00',
  almoco_fim: '13:00',
}

function issuesFor(overrides) {
  const result = employeeFormSchema.safeParse({ ...validEmployee, ...overrides })
  return result.success ? [] : result.error.issues.map((issue) => ({ path: issue.path.join('.'), message: issue.message }))
}

const validResult = employeeFormSchema.safeParse(validEmployee)
assert.equal(validResult.success, true, 'Funcionario valido deve passar')
assert.equal(validResult.data.id, 12, 'ID deve ser convertido para numero')
// F4 (D4): ativo vindo da listagem (edicao) nao vai no corpo do salvar.
for (const ativo of [true, false, 'x']) {
  const result = employeeFormSchema.safeParse({ ...validEmployee, ativo, arquivado_em: '2026-10-13' })
  assert.equal(result.success, true, `ativo=${String(ativo)} da listagem nao invalida o formulario`)
  assert.equal('ativo' in result.data || 'arquivado_em' in result.data, false, 'ativo e arquivado_em fora do corpo enviado')
}

// F4: estado do cadastro e resumo dos efeitos (comportamento).
const { archiveEffectsSummary, employeeArchiveState, matchesArchiveFilter } = await import(pathToFileURL(join(srcRoot, 'lib', 'employeeArchive.ts')).href)
for (const [item, expected] of [
  [{ ativo: true }, 'ativo'],
  [{ ativo: true, arquivado_em: null }, 'ativo'],
  [{}, 'ativo'],
  [{ ativo: false, arquivado_em: '2026-10-13' }, 'arquivado'],
  [{ ativo: 0, arquivado_em: '2026-10-13' }, 'arquivado'],
  [{ ativo: false, arquivado_em: null }, 'inativo_antigo'],
  [{ ativo: false }, 'inativo_antigo'],
  [{ ativo: '0', arquivado_em: '' }, 'inativo_antigo'],
]) {
  assert.equal(employeeArchiveState(item), expected, `estado de ${JSON.stringify(item)}`)
}
const noEffects = { pa_encerrados: 0, pa_removidos: 0, regras_encerradas: 0, regras_removidas: 0, excecoes_removidas: 0, ausencias_canceladas: 0, ausencias_cortadas: 0, pausa_finalizada: false, solicitacao_descartada: false }
assert.equal(archiveEffectsSummary(noEffects), 'Nada em aberto para encerrar.', 'Sem efeitos')
assert.equal(archiveEffectsSummary(undefined), 'Nada em aberto para encerrar.', 'Resposta sem efeitos')
assert.equal(
  archiveEffectsSummary({ ...noEffects, pa_encerrados: 1, pa_removidos: 2, regras_encerradas: 1, regras_removidas: 1, excecoes_removidas: 3, ausencias_canceladas: 2, ausencias_cortadas: 1, pausa_finalizada: true }),
  '1 vínculo de PA encerrado; 2 vínculos de PA futuros removidos; 1 regra de escala encerrada; 1 regra de escala futura removida; 3 exceções de escala futuras removidas; 2 ausências futuras canceladas; 1 ausência em curso encerrada ontem; pausa aberta finalizada.',
  'Resumo com singular, plural e pausa',
)
assert.equal(archiveEffectsSummary({ ...noEffects, solicitacao_descartada: true, pa_encerrados: 'x', pa_removidos: 1.5 }), 'solicitação de pausa descartada.', 'Solicitacao descartada; contagem invalida ou fracionada ignorada')
assert.equal(archiveEffectsSummary({ ...noEffects, pausa_finalizada: 'false', solicitacao_descartada: 1 }), 'Nada em aberto para encerrar.', 'Pausa e solicitacao so com true (contrato booleano)')
const filterItems = [{ id: 1, ativo: true }, { id: 2, ativo: false, arquivado_em: '2026-10-10' }, { id: 3, ativo: false, arquivado_em: null }]
for (const [status, ids] of [['ativos', [1]], ['arquivados', [2, 3]], ['', [1, 2, 3]]]) {
  assert.deepEqual(filterItems.filter((item) => matchesArchiveFilter(item, status)).map((item) => item.id), ids, `filtro "${status}"`)
}
assert.match(adminSource, /&& matchesArchiveFilter\(item, status\)/, 'A lista usa o filtro testado')

for (const adLogin of [null, undefined, '']) {
  const result = employeeFormSchema.safeParse({ ...validEmployee, ad_login: adLogin })
  assert.equal(result.success, true, `ad_login ${String(adLogin)} deve validar (listar_funcionarios.php devolve null)`)
  assert.equal(result.data.ad_login, '', `ad_login ${String(adLogin)} deve virar string vazia`)
}
assert.deepEqual(issuesFor({ ad_login: 'fulano tal' }).map((issue) => issue.path), ['ad_login'], 'ad_login com espaco deve falhar')

for (const field of ['jornada_entrada', 'jornada_saida', 'almoco_inicio', 'almoco_fim']) {
  for (const invalidTime of ['24:00', '8:00', '08:60', 'abc', '', null]) {
    const paths = issuesFor({ [field]: invalidTime }).map((issue) => issue.path)
    assert.ok(paths.includes(field), `${field}=${String(invalidTime)} deve falhar no proprio campo`)
  }
}

assert.deepEqual(issuesFor({ jornada_entrada: '18:00', jornada_saida: '17:00' }), [
  { path: 'jornada_saida', message: 'Horário de saída deve ser posterior à entrada.' },
], 'Entrada depois da saida deve falhar em jornada_saida')
assert.deepEqual(issuesFor({ jornada_entrada: '17:00', jornada_saida: '17:00' }).map((issue) => issue.path), ['jornada_saida'], 'Entrada igual a saida deve falhar')
assert.deepEqual(issuesFor({ almoco_inicio: '13:30', almoco_fim: '13:00' }), [
  { path: 'almoco_fim', message: 'Fim do almoço deve ser posterior ao início.' },
], 'Almoco invertido deve falhar em almoco_fim')

for (const equipe of ['n3', 'N1', '', null, 'na']) {
  assert.deepEqual(issuesFor({ equipe }), [{ path: 'equipe', message: 'Equipe inválida.' }], `equipe=${String(equipe)} deve falhar`)
}
// Lote 5b: admin nao e gravado no cadastro (vem da allowlist AD_ADMIN_USERS).
for (const accessRole of ['root', 'Admin', 'admin', '', null]) {
  assert.deepEqual(issuesFor({ access_role: accessRole }), [{ path: 'access_role', message: 'Perfil de acesso inválido.' }], `access_role=${String(accessRole)} deve falhar`)
}

for (const [overrides, path] of [
  [{ id: '' }, 'id'],
  [{ id: '0' }, 'id'],
  [{ id: '1000' }, 'id'],
  [{ id: '1.5' }, 'id'],
  [{ nome: '  ab ' }, 'nome'],
  [{ nome: null }, 'nome'],
]) {
  assert.deepEqual(issuesFor(overrides).map((issue) => issue.path), [path], `${JSON.stringify(overrides)} deve falhar em ${path}`)
}

const allMessages = [
  { id: '' }, { id: '0' }, { id: 'x' }, { nome: '' }, { nome: null }, { ad_login: 5 }, { ad_login: 'a b' },
  { equipe: 'x' }, { access_role: 'x' }, { jornada_entrada: null }, { jornada_entrada: '99:99' },
].flatMap((overrides) => issuesFor(overrides).map((issue) => issue.message))
for (const message of allMessages) {
  assert.doesNotMatch(message, /\b(Invalid|expected|received|Required|Too small|Too big)\b/, `Mensagem de validacao deve estar em portugues: ${message}`)
}

for (const path of sourceFiles) {
  const normalized = relative(frontendRoot, path).replaceAll('\\', '/')
  if (normalized === 'src/lib/api.ts') continue
  assert.doesNotMatch(readFileSync(path, 'utf8'), /\bfetch\(/, `Fetch direto fora de api.ts nao permitido: ${normalized}`)
}

const runtimeTsxFiles = sourceFiles.filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado')

const pageTsFiles = walk(pagesRoot).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript')

assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

for (const accessRole of ['tecnico', 'gestor', 'lideranca', 'somente_leitura']) {
  assert.deepEqual(issuesFor({ access_role: accessRole }), [], `access_role=${accessRole} deve passar`)
}
assert.deepEqual(issuesFor({ equipe: 'lideranca', access_role: 'tecnico' }), [], 'equipe Lideranca nao exige perfil admin')

// Lote 5b: equipe e perfil independentes; Lideranca so para quem promove;
// acoes em cadastros de Lideranca/admin so para quem promove.
assert.doesNotMatch(adminSource, /leadershipDefaults|access_role: 'admin'/, 'Equipe Lideranca nao pode marcar perfil admin')
const updateTeamSource = adminSource.match(/function updateTeam\(team\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.ok(updateTeamSource.includes("setValue('equipe', team"), 'Le updateTeam')
assert.doesNotMatch(updateTeamSource, /access_role/, 'Trocar a equipe nao muda o perfil')
assert.doesNotMatch(adminSource, /disabled=\{form\.equipe === 'lideranca'\}/, 'Perfil nao pode ficar travado pela equipe')
assert.match(adminSource, /\{\(canPromote \|\| form\.access_role === 'lideranca'\) && <option disabled=\{!canPromote\} value="lideranca">/, 'Opcao Lideranca so para quem promove')
const employeeFieldsSource = adminSource.match(/function EmployeeFields\([\s\S]*?\n}\n/)?.[0] || ''
assert.ok(employeeFieldsSource.includes('Perfil de acesso'), 'Le o componente EmployeeFields')
assert.doesNotMatch(employeeFieldsSource, /<option value="admin">/, 'Formulario de funcionario nao oferece Admin (a aba de usuarios locais continua com admin)')
assert.match(adminSource, /canPromote \|\| !\['lideranca', 'admin'\]\.includes\(item\.access_role\)/, 'Editar e desativar Lideranca/admin so para quem promove')
assert.match(adminSource, /const canPromote = permissions\.has\('perfis\.promote'\)/, 'Promocao pela permissao do servidor')

console.log('Employee form RHF/Zod QA OK')
