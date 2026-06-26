import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const projectRoot = join(frontendRoot, '..')
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
const runnerSource = read('src/lib/actionRunner.ts')
const actionFeedbackSource = read('src/lib/actionFeedback.ts')
const feedbackSource = read('src/components/Feedback.jsx')
const adminSource = read('src/pages/AdminPage.jsx')
const operationalSource = read('src/pages/OperationalPages.jsx')
const criticalSource = read('src/pages/CriticalIncidentsPage.jsx')
const appSource = read('src/App.jsx')
const layoutSource = read('src/components/PortalLayout.jsx')
const loginSource = read('src/pages/LoginPage.jsx')
const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')

assert.equal(packageJson.scripts['qa:action-runner'], 'node scripts/qa-action-runner.mjs', 'Script qa:action-runner deve estar registrado')
assert.equal(existsSync(join(srcRoot, 'lib', 'actionRunner.ts')), true, 'Action runner deve existir')

for (const required of [
  'action:',
  'refresh?',
  'notify:',
  'successMessage?',
  'onSuccess?',
  'onError?',
]) {
  assert.match(runnerSource, new RegExp(required.replaceAll('?', '\\?')), `Runner deve aceitar ${required}`)
}

assert.match(runnerSource, /export async function runAction<T>/, 'Runner deve exportar runAction generico')
assert.match(runnerSource, /const result = await action\(\)/, 'Runner deve executar action recebida')
assert.match(runnerSource, /const refreshers = Array\.isArray\(refresh\)/, 'Runner deve aceitar um ou mais refreshers')
assert.match(runnerSource, /await refreshFn\(\)/, 'Runner deve executar refresh apos sucesso')
assert.match(runnerSource, /await onSuccess\(result\)/, 'Runner deve executar onSuccess apos refresh')
assert.match(runnerSource, /successMessage !== null/, 'Runner deve permitir sucesso sem notificacao automatica')
assert.match(runnerSource, /notify\(successMessage \|\| fallback \|\| ACTION_FEEDBACK\.changesSaved\)/, 'Runner deve preservar fallback de mensagem de sucesso')
assert.match(runnerSource, /notify\(errorMessage\(error\), 'error'\)/, 'Runner deve notificar erro com tipo error')
assert.match(runnerSource, /return null/, 'Runner deve retornar null em erro')

for (const forbidden of [
  'fetch(',
  'innerHTML',
  'dangerouslySetInnerHTML',
  'localStorage',
  "from './api'",
  "from '../lib/api'",
  'post(',
  'postForm(',
]) {
  assert.doesNotMatch(runnerSource, new RegExp(forbidden.replaceAll(/[.*+?^${}()|[\]\\]/g, '\\$&')), `Runner nao deve usar ${forbidden}`)
}

assert.match(adminSource, /import \{ runAction \} from '\.\.\/lib\/actionRunner'/, 'AdminPage deve usar action runner')
assert.match(operationalSource, /import \{ runAction \} from '\.\.\/lib\/actionRunner'/, 'OperationalPages deve usar action runner')
assert.match(criticalSource, /import \{ runAction \} from '\.\.\/lib\/actionRunner'/, 'CriticalIncidentsPage deve usar action runner')
assert.match(adminSource, /const result = await runAction\(\{[\s\S]*action,[\s\S]*refresh: refreshers,[\s\S]*notify,[\s\S]*successMessage,[\s\S]*onSuccess,[\s\S]*\}\)[\s\S]*return result !== null/, 'Helper local de Admin deve delegar ao runner')
assert.match(operationalSource, /return runAction\(\{[\s\S]*action,[\s\S]*refresh,[\s\S]*notify,[\s\S]*successMessage,[\s\S]*onSuccess,[\s\S]*\}\)/, 'Helper local de OperationalPages deve delegar ao runner')

const criticalSaveSource = criticalSource.match(/async function save\(data\) \{[\s\S]*?\n  \}/)?.[0] || ''
const criticalStatusSource = criticalSource.match(/async function changeStatus\(id, status\) \{[\s\S]*?\n  \}/)?.[0] || ''
const criticalImportSource = criticalSource.match(/function ImportPanel\(\{ onClose, onImported \}\) \{[\s\S]*?\n\}\n\nexport function CriticalIncidentsPage/)?.[0] || ''

assert.match(criticalSaveSource, /await runAction\(\{/, 'Criar/editar chamado critico deve usar runAction')
assert.match(criticalSaveSource, /action: \(\) => post\('portal\/critical_incidents\.php', data\)/, 'Criar/editar chamado critico deve manter endpoint e payload')
assert.match(criticalSaveSource, /refresh: resource\.refresh/, 'Criar/editar chamado critico deve recarregar lista via runner')
assert.match(criticalSaveSource, /ACTION_FEEDBACK\.criticalUpdated/, 'Edicao de chamado critico deve preservar mensagem especifica')
assert.match(criticalSaveSource, /ACTION_FEEDBACK\.criticalCreated/, 'Criacao de chamado critico deve preservar mensagem especifica')
assert.match(criticalSaveSource, /onSuccess: \(\) => setFormItem\(null\)/, 'Criar/editar chamado critico deve fechar formulario apos sucesso')
assert.match(criticalStatusSource, /await runAction\(\{/, 'Alteracao de status deve usar runAction')
assert.match(criticalStatusSource, /action: \(\) => post\('portal\/critical_incidents\.php', \{ action: 'status', id, status \}\)/, 'Status deve manter endpoint e payload')
assert.match(criticalStatusSource, /refresh: resource\.refresh/, 'Status deve recarregar lista via runner')
assert.match(criticalStatusSource, /successMessage: ACTION_FEEDBACK\.criticalStatusUpdated/, 'Status deve preservar mensagem especifica')
assert.doesNotMatch(criticalImportSource, /runAction/, 'Importacao de chamados criticos deve ficar fora do action runner')
assert.match(criticalImportSource, /postForm\('portal\/critical_incidents_import\.php'/, 'Importacao deve continuar usando postForm para preview')
assert.match(criticalImportSource, /post\('portal\/critical_incidents_import\.php', \{ action: 'confirm', rows \}\)/, 'Confirmacao de importacao deve preservar endpoint e payload')
assert.match(criticalImportSource, /if \(inputRef\.current\) inputRef\.current\.value = ''/, 'Importacao deve continuar limpando input')

for (const [source, pattern, label] of [
  [adminSource, /ACTION_FEEDBACK\.employeeCreated[\s\S]*\(\) => setCreateForm\(emptyEmployee\)/, 'Funcionario criado deve manter reset via onSuccess'],
  [adminSource, /ACTION_FEEDBACK\.employeeUpdated[\s\S]*\(\) => setEditForm\(null\)/, 'Funcionario atualizado deve sair de edicao via onSuccess'],
  [adminSource, /ACTION_FEEDBACK\.userCreated[\s\S]*\(\) => setForm\(\{ username: '', password: '', role: 'gestor' \}\)/, 'Usuario criado deve limpar formulario via onSuccess'],
  [operationalSource, /ACTION_FEEDBACK\.scheduleSaved[\s\S]*\(\) => resetRule\(emptyScheduleRule\(parsed\.data\.effective_from \|\| today\)\)/, 'Escala salva deve limpar rule e weekdays via onSuccess'],
  [operationalSource, /ACTION_FEEDBACK\.scheduleRuleRemoved[\s\S]*resetRule\(emptyScheduleRule\(\)\)/, 'Remocao de escala deve limpar rule e weekdays quando aplicavel'],
  [operationalSource, /ACTION_FEEDBACK\.scheduleExceptionSaved[\s\S]*setException\(\{ employee_id: '', exception_date: exception\.exception_date \|\| today, exception_type: 'remote', note: '' \}\)/, 'Excecao deve limpar campos transacionais'],
  [operationalSource, /successMessage[\s\S]*null[\s\S]*notify\(importFeedback\(result\)\)/, 'Importacao deve preservar feedback resumido sem notificacao duplicada'],
  [operationalSource, /ACTION_FEEDBACK\.oncallCreated[\s\S]*setForm\(\{ \.\.\.form, employee_id: '', note: '' \}\)/, 'Plantao deve limpar campos transacionais'],
  [criticalSource, /ACTION_FEEDBACK\.criticalCreated/, 'Criacao de chamado critico deve preservar mensagem da Fase 17'],
  [criticalSource, /ACTION_FEEDBACK\.criticalUpdated/, 'Edicao de chamado critico deve preservar mensagem da Fase 17'],
  [criticalSource, /ACTION_FEEDBACK\.criticalStatusUpdated/, 'Status de chamado critico deve preservar mensagem da Fase 17'],
]) {
  assert.match(source, pattern, label)
}

assert.match(actionFeedbackSource, /scheduleSaved: 'Escala salva com sucesso\.'/u, 'Mensagens da Fase 17 devem permanecer')
assert.match(actionFeedbackSource, /employeeCreated: 'Funcionário adicionado com sucesso\.'/u, 'Mensagens de funcionario devem permanecer')

for (const type of ['success', 'error', 'warning', 'info']) {
  assert.match(feedbackSource, new RegExp(`${type}: \\{`), `Feedback deve continuar suportando ${type}`)
}

assert.match(sourceText, /Desenvolvido por Matheus Camargo/, 'Credito do desenvolvedor deve continuar existindo')
assert.match(loginSource, /Desenvolvido por Matheus Camargo/, 'Credito deve permanecer no login')
assert.match(layoutSource, /Desenvolvido por Matheus Camargo/, 'Credito deve permanecer no layout autenticado')
assert.equal(existsSync(join(srcRoot, 'components', 'RouteErrorBoundary.jsx')), true, 'RouteErrorBoundary deve continuar existindo')
assert.match(appSource, /<RouteErrorBoundary resetKey=\{router\.path\}>[\s\S]*<Suspense/, 'Code splitting deve continuar protegido por Error Boundary e Suspense')

for (const forbidden of [
  'dangerouslySetInnerHTML',
  'innerHTML',
  'localStorage',
]) {
  assert.doesNotMatch(sourceText, new RegExp(forbidden), `${forbidden} nao deve aparecer em frontend/src`)
}

assert.doesNotMatch(sourceText, /\bwindow\.alert\b|\balert\(/, 'Nao deve haver window.alert ou alert()')
const addedConfirmOrAlert = execFileSync('git', ['diff', '-U0', '--', 'frontend/src'], { cwd: projectRoot, encoding: 'utf8' })
  .split('\n')
  .filter((line) => line.startsWith('+') && !line.startsWith('+++'))
  .join('\n')
assert.doesNotMatch(addedConfirmOrAlert, /\bwindow\.confirm\b|\bconfirm\(|\bwindow\.alert\b|\balert\(/, 'Fase 18 nao deve adicionar novo confirm/alert')

for (const protectedPath of [
  'frontend/src/hooks/useLivePauses.js',
  'frontend/src/hooks/useResource.ts',
  'frontend/src/lib/api.ts',
  'frontend/src/lib/queryClient.ts',
  'frontend/src/lib/queryKeys.ts',
  'frontend/src/lib/operational.ts',
]) {
  assert.equal(execFileSync('git', ['diff', '--name-only', '--', protectedPath], { cwd: projectRoot, encoding: 'utf8' }).trim(), '', `${protectedPath} nao deve ser alterado`)
}

for (const allowedDependency of [
  '@tanstack/react-query',
  '@tanstack/react-table',
  'react-hook-form',
  'zod',
]) {
  assert.equal(typeof packageJson.dependencies?.[allowedDependency], 'string', `${allowedDependency} deve permanecer instalado`)
}

for (const blockedDependency of [
  'sweetalert',
  'toastify',
  'sonner',
  'notistack',
  'radix',
  'headlessui',
  '@loadable/component',
  '@hookform/resolvers',
  'formik',
  'yup',
  'joi',
  '@playwright/test',
  'cypress',
  'sentry',
  'logrocket',
]) {
  assert.equal(packageJson.dependencies?.[blockedDependency], undefined, `Dependencia proibida instalada: ${blockedDependency}`)
  assert.equal(packageJson.devDependencies?.[blockedDependency], undefined, `DevDependency proibida instalada: ${blockedDependency}`)
  assert.doesNotMatch(packageLock, new RegExp(`"node_modules/${blockedDependency.replace('/', '\\/')}"`, 'i'), `Dependencia proibida no lock: ${blockedDependency}`)
}

assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutation deve ser criada')

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

console.log('Action runner frontend QA OK')
