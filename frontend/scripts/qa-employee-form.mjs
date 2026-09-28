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
assert.match(adminSource, /register\('id'\)[\s\S]*register\('nome'\)[\s\S]*register\('jornada_entrada'\)[\s\S]*register\('jornada_saida'\)[\s\S]*register\('almoco_inicio'\)[\s\S]*register\('almoco_fim'\)[\s\S]*register\('ativo'\)/, 'Campos de funcionario devem ser registrados no RHF')
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

const deactivateSource = adminSource.match(/async function deactivate\(id\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(deactivateSource, /post\('remover_funcionario\.php', \{ funcionario_id: id \}\)/, 'Remocao deve preservar endpoint e payload')
assert.match(deactivateSource, /ACTION_FEEDBACK\.employeeRemoved/, 'Remocao deve preservar feedback')
assert.match(deactivateSource, /if \(editForm && Number\(editForm\.id\) === Number\(id\)\) setEditForm\(null\)/, 'Remocao deve fechar edicao quando aplicavel')

assert.match(adminSource, /const result = await runAction\(\{[\s\S]*action,[\s\S]*refresh: refreshers,[\s\S]*notify,[\s\S]*successMessage,[\s\S]*onSuccess/, 'perform deve continuar delegando ao runAction')
assert.match(actionFeedbackSource, /employeeCreated: 'Funcionário adicionado com sucesso\.'/u, 'ACTION_FEEDBACK.employeeCreated deve existir')
assert.match(actionFeedbackSource, /employeeUpdated: 'Funcionário atualizado com sucesso\.'/u, 'ACTION_FEEDBACK.employeeUpdated deve existir')
assert.match(actionFeedbackSource, /employeeRemoved: 'Funcionário removido com sucesso\.'/u, 'ACTION_FEEDBACK.employeeRemoved deve existir')
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
  'src/pages/AdminPage.jsx': 4,
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
  ativo: true,
}

function issuesFor(overrides) {
  const result = employeeFormSchema.safeParse({ ...validEmployee, ...overrides })
  return result.success ? [] : result.error.issues.map((issue) => ({ path: issue.path.join('.'), message: issue.message }))
}

const validResult = employeeFormSchema.safeParse(validEmployee)
assert.equal(validResult.success, true, 'Funcionario valido deve passar')
assert.equal(validResult.data.id, 12, 'ID deve ser convertido para numero')

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
for (const accessRole of ['root', 'Admin', '', null]) {
  assert.deepEqual(issuesFor({ access_role: accessRole }), [{ path: 'access_role', message: 'Perfil de acesso inválido.' }], `access_role=${String(accessRole)} deve falhar`)
}

for (const [overrides, path] of [
  [{ id: '' }, 'id'],
  [{ id: '0' }, 'id'],
  [{ id: '1000' }, 'id'],
  [{ id: '1.5' }, 'id'],
  [{ nome: '  ab ' }, 'nome'],
  [{ nome: null }, 'nome'],
  [{ ativo: 'sim' }, 'ativo'],
]) {
  assert.deepEqual(issuesFor(overrides).map((issue) => issue.path), [path], `${JSON.stringify(overrides)} deve falhar em ${path}`)
}

const allMessages = [
  { id: '' }, { id: '0' }, { id: 'x' }, { nome: '' }, { nome: null }, { ad_login: 5 }, { ad_login: 'a b' },
  { equipe: 'x' }, { access_role: 'x' }, { jornada_entrada: null }, { jornada_entrada: '99:99' }, { ativo: 'x' },
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

console.log('Employee form RHF/Zod QA OK')
