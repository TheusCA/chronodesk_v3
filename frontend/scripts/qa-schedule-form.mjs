import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

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
assert.match(schemaSource, /value\.rule_type === 'fixed_weekdays' && value\.input_mode === 'onsite' && value\.weekdays\.length === 0[\s\S]*Selecione ao menos um dia da semana\./, 'Schema deve exigir weekdays para fixed_weekdays')
// F2B: modo home e ciclo no schema, espelhando o servidor.
assert.match(schemaSource, /'fixed_weekdays',\n    'cycle',\n  \]/, 'Schema deve aceitar cycle')
assert.match(schemaSource, /input_mode: z\.enum\(\['onsite', 'home'\]/, 'Schema deve ter o modo de informar os dias')
assert.match(schemaSource, /home_weekdays\.length === 0[\s\S]*use a regra "Sempre presencial"[\s\S]*home_weekdays\.length === SCHEDULE_WEEKDAY_COUNT[\s\S]*use a regra "Sempre remoto"/, 'Schema deve recusar nenhum e todos os dias de home')
assert.match(schemaSource, /onsite \+ remote > CYCLE_MAX_DAYS[\s\S]*!isBusinessDay\(value\.effective_from\)/, 'Schema deve limitar o ciclo a 20 dias e exigir data inicial em dia util')
assert.match(schemaSource, /export const CYCLE_MAX_DAYS = 20/, 'Limite do ciclo igual ao do servidor')

assert.match(operationalSource, /import \{ reportFiltersSchema, scheduleRuleSchema \} from '\.\.\/lib\/formSchemas'/, 'OperationalPages deve importar schema da regra de escala')
assert.match(operationalSource, /useForm\(\{[\s\S]*defaultValues:\s*emptyScheduleRule\(\)/, 'Formulario de regra deve usar React Hook Form com emptyScheduleRule')
assert.match(operationalSource, /handleRuleSubmit\(saveRule\)/, 'Submit da regra deve passar por handleSubmit')
assert.match(operationalSource, /registerRule\('employee_id'\)/, 'Colaborador deve ser registrado no RHF')
assert.match(operationalSource, /registerRule\('rule_type'[\s\S]*setRuleValue\('weekdays', \[\], \{ shouldDirty: true \}\)/, 'Troca para regra nao fixa deve limpar weekdays')
assert.match(operationalSource, /registerRule\('effective_from'\)/, 'Vigencia deve ser registrada no RHF')
assert.match(operationalSource, /scheduleRuleSchema\.safeParse\(/, 'Submit deve validar com Zod manual via safeParse')
assert.match(operationalSource, /setRuleError\([\s\S]*type: 'zod'/, 'Erros do Zod devem ir para formState.errors')
assert.match(operationalSource, /ruleErrors\.employee_id[\s\S]*ruleErrors\.rule_type[\s\S]*ruleErrors\.weekdays[\s\S]*ruleErrors\.effective_from/, 'UI deve mostrar erros proximos aos campos')

// F2B: validacao em parseRule, payload em buildRulePayload (rule e rule_preview), gravacao em saveRule.
const ruleFlowSource = [
  /\n  function parseRule\(values\) \{[\s\S]*?\n  \}/,
  /\nfunction buildRulePayload\(data, action\) \{[\s\S]*?\n\}/,
  /async function saveRule\(values\) \{[\s\S]*?\n  \}/,
].map((pattern) => operationalSource.match(pattern)?.[0] || '').join('\n')
const saveRuleSource = ruleFlowSource
assert.match(saveRuleSource, /SCHEDULE_RULE_VALUES\.has\(ruleType\)/, 'Validacao canonica adicional de rule_type deve permanecer')
assert.match(saveRuleSource, /buildRulePayload\(data, 'rule'\)/, 'Payload deve manter action rule')
assert.match(saveRuleSource, /employee_id:\s*Number\(data\.employee_id\)/, 'Payload deve manter employee_id numerico (convertido com Number)')
assert.match(saveRuleSource, /rule_type:\s*ruleType/, 'Payload deve manter rule_type canonico')
assert.match(saveRuleSource, /effective_from:\s*data\.effective_from/, 'Payload deve usar effective_from validado')
assert.match(saveRuleSource, /if \(ruleType === 'fixed_weekdays'\) \{[\s\S]*payload\.weekdays = data\.weekdays/, 'Payload deve incluir weekdays apenas para fixed_weekdays')
assert.match(saveRuleSource, /payload\.input_mode = 'home'\s*payload\.home_weekdays = data\.home_weekdays/, 'Modo home envia input_mode e home_weekdays (F2B)')
assert.match(saveRuleSource, /if \(ruleType === 'cycle'\) \{\s*payload\.cycle = \{\s*onsite_days: Number\(data\.cycle_onsite_days\),\s*remote_days: Number\(data\.cycle_remote_days\),\s*starts_with: data\.cycle_starts_with,\s*anchor_date: data\.effective_from,/, 'Ciclo envia o contrato do servidor, com a data inicial = inicio da vigencia (F2B)')
assert.match(saveRuleSource, /post\('portal\/schedules\.php', buildRulePayload\(data, 'rule'\)\)/, 'Endpoint de regras de escala deve ser preservado')
assert.match(saveRuleSource, /ACTION_FEEDBACK\.scheduleSaved/, 'Feedback de escala salva deve ser preservado')
assert.match(saveRuleSource, /resetRule\(emptyScheduleRule\(data\.effective_from \|\| today\)\)/, 'Reset pos-sucesso deve limpar weekdays e preservar data util')
// F2B: Salvar so com previa do servidor para os valores atuais.
assert.match(saveRuleSource, /if \(!previewFresh\) \{[\s\S]*return\s*\}/, 'Salvar recusa sem previa atual')
assert.match(operationalSource, /const previewFresh = rulePreview !== null && rulePreview\.key === rulePreviewKey\(rule\)/, 'Previa desatualizada quando qualquer campo do payload muda')
assert.match(operationalSource, /<button className="btn-primary" disabled=\{!previewFresh\} type="submit">Salvar regra<\/button>/, 'Botao Salvar desabilitado sem previa atual')
assert.match(operationalSource, /title="Previa desatualizada"|title="Prévia desatualizada"/, 'Aviso de previa desatualizada')
assert.match(operationalSource, /include_history: 1/, 'Historico por colaborador com include_history=1')
assert.match(operationalSource, /ACTION_FEEDBACK\.scheduleRuleRemoved[\s\S]*resetRule\(emptyScheduleRule\(\)\)/, 'Remocao deve preservar feedback e reset quando aplicavel')
// F2B: os mesmos checkboxes servem aos dias presenciais (weekdays) e aos de home (home_weekdays).
assert.match(operationalSource, /const field = rule\.input_mode === 'home' \? 'home_weekdays' : 'weekdays'[\s\S]*name=\{field\}[\s\S]*type="checkbox"[\s\S]*value=\{option\.value\}/, 'Checkboxes de weekdays devem continuar existindo')
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

// Trava de escopo herdada da Fase 17: o transporte e os hooks de dados nao
// podiam ser tocados durante aquele trabalho de UX.
// 'frontend/src/lib/api.ts' foi REMOVIDO desta lista no Lote 2 (2026-09-28),
// que alterou api.ts de forma aprovada para unificar a mensagem de 403.
// O contrato de api.ts segue coberto por qa-api.
for (const protectedPath of [
  'frontend/src/hooks/useLivePauses.js',
  'frontend/src/hooks/useResource.ts',
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

// F2B: comportamento do schema (safeParse), espelhando as recusas do servidor.
const { scheduleRuleSchema, cycleDays, isBusinessDay } = await import(pathToFileURL(join(srcRoot, 'lib', 'formSchemas.ts')).href)
const baseRule = { employee_id: '1', rule_type: 'cycle', effective_from: '2026-10-05', cycle_onsite_days: '2', cycle_remote_days: '1', cycle_starts_with: 'remote' }
function ruleIssues(overrides) {
  const result = scheduleRuleSchema.safeParse({ ...baseRule, ...overrides })
  return result.success ? [] : result.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`)
}
assert.deepEqual(ruleIssues({}), [], 'Ciclo valido (2P/1R a partir de segunda)')
assert.deepEqual(ruleIssues({ effective_from: '2026-10-10' }), ['effective_from: A data inicial do ciclo precisa ser dia util (segunda a sexta).'], 'Ciclo com data inicial no sabado')
assert.deepEqual(ruleIssues({ cycle_onsite_days: '11', cycle_remote_days: '10' }), ['cycle_remote_days: O ciclo pode ter no maximo 20 dias.'], 'Ciclo com 21 dias')
assert.deepEqual(ruleIssues({ cycle_onsite_days: '10', cycle_remote_days: '10' }), [], 'Ciclo com 20 dias')
for (const invalid of ['0', '', '1.5', '-1', '20', 'abc']) {
  assert.deepEqual(ruleIssues({ cycle_onsite_days: invalid }), ['cycle_onsite_days: Dias presenciais do ciclo: informe um numero inteiro de 1 a 19.'], `Dias presenciais invalidos: "${invalid}"`)
}
assert.deepEqual(ruleIssues({ cycle_starts_with: 'home' }).length, 1, 'Comeca por valor desconhecido')
assert.deepEqual(ruleIssues({ rule_type: 'always_onsite', cycle_onsite_days: '', effective_from: '2026-10-10' }), [], 'Campos do ciclo e dia util so valem para o ciclo')
const home = { employee_id: '1', rule_type: 'fixed_weekdays', effective_from: '2026-10-05', input_mode: 'home' }
assert.deepEqual(scheduleRuleSchema.safeParse({ ...home, home_weekdays: ['tue', 'thu'] }).success, true, 'Modo home com ter e qui')
assert.deepEqual(scheduleRuleSchema.safeParse({ ...home, home_weekdays: [] }).error?.issues.map((issue) => issue.message), ['Nenhum dia de home office marcado: para presencial em todos os dias uteis, use a regra "Sempre presencial".'], 'Modo home sem nenhum dia')
assert.deepEqual(scheduleRuleSchema.safeParse({ ...home, home_weekdays: ['mon', 'tue', 'wed', 'thu', 'fri'] }).error?.issues.map((issue) => issue.message), ['Todos os dias em home office: use a regra "Sempre remoto".'], 'Modo home com todos os dias')
assert.deepEqual(scheduleRuleSchema.safeParse({ ...home, input_mode: 'onsite', weekdays: [] }).error?.issues.map((issue) => issue.message), ['Selecione ao menos um dia da semana.'], 'Dias presenciais sem nenhum dia')
assert.deepEqual(scheduleRuleSchema.safeParse({ ...home, input_mode: 'misto', home_weekdays: ['tue'] }).success, false, 'Modo desconhecido recusado')
assert.deepEqual(scheduleRuleSchema.safeParse({ employee_id: '1', rule_type: 'fixed_weekdays', effective_from: '2026-10-05', weekdays: ['mon'] }).data?.input_mode, 'onsite', 'Regra antiga sem input_mode continua como dias presenciais')
assert.deepEqual([cycleDays('1'), cycleDays('19'), cycleDays('20'), cycleDays('01')], [1, 19, null, 1], 'cycleDays: faixa 1 a 19')
assert.deepEqual(['2026-10-05', '2026-10-09', '2026-10-10', '2026-10-11'].map(isBusinessDay), [true, true, false, false], 'isBusinessDay: segunda a sexta')

console.log('Schedule form RHF/Zod QA OK')
