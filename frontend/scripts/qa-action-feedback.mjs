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
const feedbackSource = read('src/components/Feedback.jsx')
const appSource = read('src/App.jsx')
const actionFeedbackSource = read('src/lib/actionFeedback.ts')
const adminSource = read('src/pages/AdminPage.jsx')
const operationalSource = read('src/pages/OperationalPages.jsx')
const paMapSource = read('src/pages/PaMapPage.jsx')
const criticalSource = read('src/pages/CriticalIncidentsPage.jsx')
const documentsSource = read('src/pages/DocumentsPage.jsx')
const shiftSource = read('src/pages/ShiftSchedulesPage.jsx')
const pausasSource = read('src/pages/PausasPage.jsx')
const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')

assert.equal(packageJson.scripts['qa:action-feedback'], 'node scripts/qa-action-feedback.mjs', 'Script qa:action-feedback deve estar registrado')
assert.equal(existsSync(join(srcRoot, 'lib', 'actionFeedback.ts')), true, 'Helper de mensagens de acao deve existir')

for (const type of ['success', 'error', 'warning', 'info']) {
  assert.match(feedbackSource, new RegExp(`${type}: \\{`), `Feedback deve suportar tipo ${type}`)
}
assert.match(feedbackSource, /role=\{variant\.role\}/, 'Feedback deve usar role por tipo')
assert.match(feedbackSource, /role: 'alert'/, 'Feedback de erro deve usar role alert')
assert.match(feedbackSource, /role: 'status'/, 'Feedback de sucesso/info/aviso deve usar role status')
assert.match(feedbackSource, /aria-label="Fechar mensagem"/, 'Feedback deve permitir fechamento manual acessivel')
assert.match(feedbackSource, /sm:left-auto/, 'Feedback deve funcionar em mobile sem estourar largura')
assert.match(appSource, /<Feedback feedback=\{feedback\} onClose=\{closeFeedback\}/, 'App deve conectar fechamento manual do feedback')

for (const forbidden of [
  'dangerouslySetInnerHTML',
  'innerHTML',
  'localStorage',
]) {
  assert.doesNotMatch(sourceText, new RegExp(forbidden), `${forbidden} nao deve aparecer em frontend/src`)
  assert.doesNotMatch(feedbackSource, new RegExp(forbidden), `Feedback nao deve usar ${forbidden}`)
}

assert.match(actionFeedbackSource, /employeeCreated: 'Funcionário adicionado com sucesso\.'/u, 'Mensagem de funcionario criado deve existir')
assert.match(actionFeedbackSource, /employeeUpdated: 'Funcionário atualizado com sucesso\.'/u, 'Mensagem de funcionario atualizado deve existir')
assert.match(actionFeedbackSource, /employeeRemoved: 'Funcionário removido com sucesso\.'/u, 'Mensagem de funcionario removido deve existir')
assert.match(actionFeedbackSource, /scheduleSaved: 'Escala salva com sucesso\.'/u, 'Mensagem de escala salva deve existir')
assert.match(actionFeedbackSource, /paLinked: 'Vínculo realizado com sucesso\.'/u, 'Mensagem de vinculo criado deve existir')
assert.match(actionFeedbackSource, /paUnlinked: 'Vínculo removido com sucesso\.'/u, 'Mensagem de vinculo removido deve existir')
assert.match(actionFeedbackSource, /requestApproved: 'Solicitação aprovada com sucesso\.'/u, 'Mensagem de aprovacao deve existir')
assert.match(actionFeedbackSource, /requestRejected: 'Solicitação rejeitada com sucesso\.'/u, 'Mensagem de rejeicao deve existir')
assert.match(actionFeedbackSource, /importFeedback/, 'Resumo de importacao deve ser padronizado')

for (const [source, messageKey] of [
  [adminSource, 'ACTION_FEEDBACK.employeeCreated'],
  [adminSource, 'ACTION_FEEDBACK.employeeUpdated'],
  [adminSource, 'ACTION_FEEDBACK.employeeRemoved'],
  [adminSource, 'decisionFeedback(decision)'],
  [operationalSource, 'ACTION_FEEDBACK.scheduleSaved'],
  [operationalSource, 'ACTION_FEEDBACK.scheduleRuleRemoved'],
  [operationalSource, 'ACTION_FEEDBACK.scheduleExceptionSaved'],
  [operationalSource, 'decisionFeedback(decision)'],
  [paMapSource, 'ACTION_FEEDBACK.paLinked'],
  [paMapSource, 'ACTION_FEEDBACK.paUnlinked'],
  [criticalSource, 'ACTION_FEEDBACK.criticalCreated'],
  [criticalSource, 'ACTION_FEEDBACK.criticalUpdated'],
  [documentsSource, 'ACTION_FEEDBACK.documentUploaded'],
  [documentsSource, 'ACTION_FEEDBACK.documentRemoved'],
  [shiftSource, 'ACTION_FEEDBACK.shiftUploaded'],
]) {
  assert.match(source, new RegExp(messageKey.replaceAll(/[.*+?^${}()|[\]\\]/g, '\\$&')), `Acao principal deve usar ${messageKey}`)
}

for (const [source, pattern, label] of [
  [adminSource, /setCreateForm\(emptyEmployee\)/, 'Cadastro de funcionario deve limpar formulario'],
  [adminSource, /setEditForm\(null\)/, 'Edicao de funcionario deve sair do modo edicao'],
  [adminSource, /setForm\(\{ username: '', password: '', role: 'gestor' \}\)/, 'Cadastro de usuario deve limpar formulario'],
  [operationalSource, /function emptyScheduleRule\(effectiveFrom = today\)[\s\S]*weekdays: \[\]/, 'Helper de reset de escala deve limpar weekdays'],
  [operationalSource, /resetRule\(emptyScheduleRule\(parsed\.data\.effective_from \|\| today\)\)/, 'Salvar escala deve limpar colaborador e weekdays'],
  [operationalSource, /resetRule\(emptyScheduleRule\(\)\)/, 'Remover escala deve limpar weekdays quando aplicavel'],
  [operationalSource, /setException\(\{ employee_id: '', exception_date: exception\.exception_date \|\| today, exception_type: 'remote', note: '' \}\)/, 'Excecao deve limpar campos transacionais'],
  [operationalSource, /if \(importInputRef\.current\) importInputRef\.current\.value = ''/, 'Importacao de escala deve limpar input de arquivo'],
  [paMapSource, /resetForm\?\.\(\)/, 'PA Map deve limpar formulario apos salvar vinculo'],
  [criticalSource, /setFormItem\(null\)/, 'Chamado critico deve sair do modo edicao'],
  [criticalSource, /if \(inputRef\.current\) inputRef\.current\.value = ''/, 'Importacao de chamados deve limpar input de arquivo'],
  [documentsSource, /setMetadata\(\{ title: '', category: 'Procedimento', description: '', visibility: 'internal' \}\)/, 'Upload de documentos deve limpar metadados'],
  [shiftSource, /setMetadata\(\{ title: '', reference_month: metadata\.reference_month, notes: '' \}\)/, 'Upload de escala de sabado deve limpar campos transacionais preservando mes'],
  [pausasSource, /setReason\(''\)[\s\S]*setObservation\(''\)/, 'Formulario de pausa deve limpar motivo e observacao apos sucesso'],
]) {
  assert.match(source, pattern, label)
}

for (const source of [adminSource, operationalSource, paMapSource, criticalSource, documentsSource, shiftSource]) {
  assert.match(source, /resource\.refresh|refresh\(/, 'Acoes principais devem atualizar listas/dados apos sucesso')
}

assert.doesNotMatch(sourceText, /\bwindow\.alert\b|\balert\(/, 'Nao deve haver window.alert ou alert() no frontend')
const addedConfirm = execFileSync('git', ['diff', '-U0', '--', 'frontend/src'], { cwd: projectRoot, encoding: 'utf8' })
  .split('\n')
  .filter((line) => line.startsWith('+') && !line.startsWith('+++'))
  .join('\n')
assert.doesNotMatch(addedConfirm, /\bwindow\.confirm\b|\bconfirm\(/, 'Fase 17 nao deve adicionar novo window.confirm/confirm()')

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
assert.match(sourceText, /Desenvolvido por Matheus Camargo/, 'Credito do desenvolvedor deve continuar existindo')
assert.equal(existsSync(join(srcRoot, 'components', 'RouteErrorBoundary.jsx')), true, 'RouteErrorBoundary deve continuar existindo')

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

console.log('Action feedback frontend QA OK')
