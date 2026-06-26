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
const schemaSource = read('src/lib/formSchemas.ts')
const operationalSource = read('src/pages/OperationalPages.jsx')
const actionFeedbackSource = read('src/lib/actionFeedback.ts')
const appSource = read('src/App.jsx')
const layoutSource = read('src/components/PortalLayout.jsx')
const loginSource = read('src/pages/LoginPage.jsx')
const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')

assert.equal(packageJson.scripts['qa:schedule-form'], 'node scripts/qa-schedule-form.mjs', 'Script qa:schedule-form deve estar registrado')
assert.equal(existsSync(join(frontendRoot, 'scripts', 'qa-schedule-form.mjs')), true, 'QA dedicado de formulario de escala deve existir')

assert.match(schemaSource, /export const scheduleRuleSchema = z\.object\(\{/, 'scheduleRuleSchema deve existir')
assert.match(schemaSource, /employee_id:\s*z\.string\(\)[\s\S]*\.min\(1, 'Selecione um colaborador\.'\)[\s\S]*Number\(value\)[\s\S]*employeeId > 0/, 'Schema deve validar employee_id obrigatorio e numerico')
assert.match(schemaSource, /rule_type:\s*z\.enum\(\[[\s\S]*'undefined'[\s\S]*'even_days'[\s\S]*'odd_days'[\s\S]*'always_onsite'[\s\S]*'always_remote'[\s\S]*'fixed_weekdays'/, 'Schema deve validar rule_type canonico incluindo fixed_weekdays')
assert.match(schemaSource, /effective_from:\s*z\.string\(\)[\s\S]*\.min\(1, 'Informe a data de vigencia\.'\)[\s\S]*\.regex\(\/\^\\d\{4\}-\\d\{2\}-\\d\{2\}\$\//, 'Schema deve validar effective_from YYYY-MM-DD')
assert.match(schemaSource, /weekdays:\s*z\.array\(scheduleWeekdaySchema\)\.default\(\[\]\)/, 'Schema deve validar weekdays permitidos')
assert.match(schemaSource, /value\.rule_type === 'fixed_weekdays' && value\.weekdays\.length === 0[\s\S]*Selecione ao menos um dia da semana\./, 'Schema deve exigir weekdays para fixed_weekdays')

assert.match(operationalSource, /import \{ reportFiltersSchema, scheduleRuleSchema \} from '\.\.\/lib\/formSchemas'/, 'OperationalPages deve importar schema da regra de escala')
assert.match(operationalSource, /useForm\(\{[\s\S]*defaultValues:\s*emptyScheduleRule\(\)/, 'Formulario de regra deve usar React Hook Form com emptyScheduleRule')
assert.match(operationalSource, /handleRuleSubmit\(saveRule\)/, 'Submit da regra deve passar por handleSubmit')
assert.match(operationalSource, /registerRule\('employee_id'\)/, 'Colaborador deve ser registrado no RHF')
assert.match(operationalSource, /registerRule\('rule_type'[\s\S]*setRuleValue\('weekdays', \[\], \{ shouldDirty: true \}\)/, 'Troca para regra nao fixa deve limpar weekdays')
assert.match(operationalSource, /registerRule\('effective_from'\)/, 'Vigencia deve ser registrada no RHF')
assert.match(operationalSource, /scheduleRuleSchema\.safeParse\(/, 'Submit deve validar com Zod manual via safeParse')
assert.match(operationalSource, /setRuleError\([\s\S]*type: 'zod'/, 'Erros do Zod devem ir para formState.errors')
assert.match(operationalSource, /ruleErrors\.employee_id[\s\S]*ruleErrors\.rule_type[\s\S]*ruleErrors\.weekdays[\s\S]*ruleErrors\.effective_from/, 'UI deve mostrar erros proximos aos campos')

const saveRuleSource = operationalSource.match(/async function saveRule\(values\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(saveRuleSource, /SCHEDULE_RULE_VALUES\.has\(ruleType\)/, 'Validacao canonica adicional de rule_type deve permanecer')
assert.match(saveRuleSource, /action:\s*'rule'/, 'Payload deve manter action rule')
assert.match(saveRuleSource, /employee_id:\s*employeeId/, 'Payload deve manter employee_id numerico')
assert.match(saveRuleSource, /const employeeId = Number\(parsed\.data\.employee_id\)/, 'employee_id deve ser convertido com Number')
assert.match(saveRuleSource, /rule_type:\s*ruleType/, 'Payload deve manter rule_type canonico')
assert.match(saveRuleSource, /effective_from:\s*parsed\.data\.effective_from/, 'Payload deve usar effective_from validado')
assert.match(saveRuleSource, /if \(ruleType === 'fixed_weekdays'\) \{[\s\S]*payload\.weekdays = parsed\.data\.weekdays/, 'Payload deve incluir weekdays apenas para fixed_weekdays')
assert.match(saveRuleSource, /post\('portal\/schedules\.php', payload\)/, 'Endpoint de regras de escala deve ser preservado')
assert.match(saveRuleSource, /ACTION_FEEDBACK\.scheduleSaved/, 'Feedback de escala salva deve ser preservado')
assert.match(saveRuleSource, /resetRule\(emptyScheduleRule\(parsed\.data\.effective_from \|\| today\)\)/, 'Reset pos-sucesso deve limpar weekdays e preservar data util')
assert.match(operationalSource, /ACTION_FEEDBACK\.scheduleRuleRemoved[\s\S]*resetRule\(emptyScheduleRule\(\)\)/, 'Remocao deve preservar feedback e reset quando aplicavel')
assert.match(operationalSource, /type="checkbox"[\s\S]*name="weekdays"[\s\S]*value=\{option\.value\}|name="weekdays"[\s\S]*type="checkbox"[\s\S]*value=\{option\.value\}/, 'Checkboxes de weekdays devem continuar existindo')
assert.match(operationalSource, /return runAction\(\{[\s\S]*action,[\s\S]*refresh,[\s\S]*notify,[\s\S]*successMessage,[\s\S]*onSuccess/, 'submit local deve continuar delegando ao runAction')

assert.match(actionFeedbackSource, /scheduleSaved: 'Escala salva com sucesso\.'/u, 'ACTION_FEEDBACK.scheduleSaved deve existir')
assert.match(actionFeedbackSource, /scheduleRuleRemoved: 'Regra de escala removida com sucesso\.'/u, 'ACTION_FEEDBACK.scheduleRuleRemoved deve existir')
assert.match(sourceText, /Desenvolvido por Matheus Camargo/, 'Credito do desenvolvedor deve continuar existindo')
assert.match(loginSource, /Desenvolvido por Matheus Camargo/, 'Credito deve permanecer no login')
assert.match(layoutSource, /Desenvolvido por Matheus Camargo/, 'Credito deve permanecer no layout autenticado')
assert.equal(existsSync(join(srcRoot, 'components', 'RouteErrorBoundary.jsx')), true, 'RouteErrorBoundary deve continuar existindo')
assert.match(appSource, /<RouteErrorBoundary resetKey=\{router\.path\}>[\s\S]*<Suspense/, 'Code splitting deve continuar protegido por RouteErrorBoundary e Suspense')

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
assert.doesNotMatch(addedConfirmOrAlert, /\bwindow\.confirm\b|\bconfirm\(|\bwindow\.alert\b|\balert\(/, 'Fase 20 nao deve adicionar novo confirm/alert')
assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutation deve ser criada')

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

console.log('Schedule form RHF/Zod QA OK')
