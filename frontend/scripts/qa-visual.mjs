import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const srcRoot = join(frontendRoot, 'src')
const pocRoot = join(frontendRoot, 'poc')
const projectRoot = join(frontendRoot, '..')

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

const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|css)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')
const appSource = read('src/App.jsx')
const navigationSource = read('src/lib/navigation.ts')
const operationalSource = read('src/pages/OperationalPages.jsx')
const cssSource = read('src/index.css')
const statesSource = read('src/components/ui/States.jsx')
const packageJson = JSON.parse(read('package.json'))
const tsconfig = JSON.parse(read('tsconfig.json'))
const installedDependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
}

for (const forbidden of [
  'dangerouslySetInnerHTML',
  'innerHTML',
  'localStorage',
]) {
  assert.doesNotMatch(sourceText, new RegExp(forbidden), `AppSec frontend: ${forbidden} nao deve aparecer em frontend/src`)
}

for (const forbidden of [
  '/var/www',
  'C:\\',
  'SQLSTATE',
  'APP_DEBUG',
  'SECRET_KEY',
]) {
  assert.doesNotMatch(sourceText, new RegExp(forbidden.replaceAll('\\', '\\\\'), 'i'), `UI nao deve expor ${forbidden}`)
}

for (const term of [
  'Plantao',
  'Alocacao',
  'posicoes fisicas',
  'Vinculo legado',
]) {
  assert.doesNotMatch(sourceText, new RegExp(term), `Microcopy residual sem acento: ${term}`)
}

const expectedRoutes = [
  '/dashboard',
  '/calendario',
  '/pausas',
  '/admin',
  '/metricas',
  '/escala-turnos',
  '/escala-presencial',
  '/mapa-pa',
  '/horas-extras',
  '/correcao-ponto',
  '/plantonistas',
  '/chamados-criticos',
  '/documentacao',
  '/relatorios',
  '/configuracoes',
]

for (const route of expectedRoutes) {
  assert.match(navigationSource, new RegExp(`path: '${route}'`), `Rota registrada na navegacao: ${route}`)
  assert.match(navigationSource, new RegExp(`'${route}': \\[`), `Metadados de pagina registrados: ${route}`)
}

for (const route of [
  '/dashboard',
  '/pausas',
  '/admin',
  '/metricas',
  '/calendario',
  '/escala-presencial',
  '/mapa-pa',
  '/escala-turnos',
  '/horas-extras',
  '/correcao-ponto',
  '/plantonistas',
  '/chamados-criticos',
  '/documentacao',
]) {
  assert.match(appSource, new RegExp(`router\\.path === '${route}'`), `Tela principal mapeada no App: ${route}`)
}

for (const [route, permission] of [
  ['/pausas', 'pausas.use'],
  ['/metricas', 'metricas.read'],
  ['/admin', 'operacao.approve'],
  ['/relatorios', 'relatorios.read'],
  ['/configuracoes', 'configuracoes.manage'],
]) {
  assert.match(
    navigationSource,
    new RegExp(`path: '${route}'.*permission: '${permission}'`),
    `RBAC preservado na navegacao: ${route} exige ${permission}`,
  )
}

for (const [value, label] of [
  ['even_days', 'Dias pares'],
  ['odd_days', 'Dias ímpares'],
  ['always_onsite', 'Sempre presencial'],
  ['always_remote', 'Sempre remoto'],
  ['fixed_weekdays', 'Dias fixos da semana'],
  ['undefined', 'Sem escala definida'],
]) {
  assert.match(
    operationalSource,
    new RegExp(`\\{ value: '${value}', label: '${label}' \\}`),
    `Regra presencial preservada: ${value}`,
  )
}

const saveRuleSource = operationalSource.match(/async function saveRule\(values\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(saveRuleSource, /SCHEDULE_RULE_VALUES\.has\(ruleType\)/, 'Validacao canonica de rule_type preservada')
assert.match(saveRuleSource, /rule_type: ruleType/, 'Payload de escala envia rule_type canonico')
assert.doesNotMatch(saveRuleSource, /\.\.\.rule/, 'Payload de escala nao deve enviar objeto rule cru')
assert.doesNotMatch(saveRuleSource, /rule_type:\s*['"]undefined['"]/, 'Payload nao deve forcar undefined ao salvar dias pares/impares')

for (const cssInvariant of [
  [/focus-visible:ring-2/, 'Foco visivel global'],
  [/\.table-wrap\s*\{[\s\S]*overflow-x-auto/, 'Tabelas com scroll horizontal'],
  [/\.data-table\s*\{[\s\S]*min-w-\[760px\]/, 'Tabelas com largura minima estavel'],
  [/\.field\s*\{[\s\S]*min-w-0/, 'Campos podem encolher em grids responsivos'],
  [/overflow-wrap:\s*anywhere/, 'Textos longos quebram sem overflow'],
]) {
  assert.match(cssSource, cssInvariant[0], cssInvariant[1])
}

assert.match(statesSource, /role="alert"/, 'Estado de erro e anunciado como alerta')
assert.equal(packageJson.scripts['qa:operational'], 'node scripts/qa-operational.mjs')
assert.equal(packageJson.scripts['qa:visual'], 'node scripts/qa-visual.mjs')
assert.equal(packageJson.scripts['qa:api'], 'node scripts/qa-api.mjs')
assert.equal(packageJson.scripts['qa:action-feedback'], 'node scripts/qa-action-feedback.mjs')
assert.equal(packageJson.scripts['qa:action-runner'], 'node scripts/qa-action-runner.mjs')
assert.equal(packageJson.scripts['qa:bundle'], 'node scripts/qa-bundle.mjs')
assert.equal(packageJson.scripts['qa:forms'], 'node scripts/qa-forms.mjs')
assert.equal(packageJson.scripts['qa:query'], 'node scripts/qa-query.mjs')
assert.equal(packageJson.scripts['qa:resource'], 'node scripts/qa-resource.mjs')
assert.equal(packageJson.scripts['qa:schedule-form'], 'node scripts/qa-schedule-form.mjs')
assert.equal(packageJson.scripts['qa:schedule-rules'], 'node scripts/qa-schedule-rules.mjs')
assert.equal(packageJson.scripts['qa:table'], 'node scripts/qa-table.mjs')
assert.equal(packageJson.scripts['qa:ux-hardening'], 'node scripts/qa-ux-hardening.mjs')
assert.equal(packageJson.scripts.typecheck, 'tsc --noEmit')
assert.equal(packageJson.devDependencies.typescript?.startsWith('^'), true, 'TypeScript deve estar em devDependencies')
assert.equal(tsconfig.compilerOptions.strict, false, 'TypeScript deve iniciar permissivo com strict=false')
assert.equal(tsconfig.compilerOptions.allowJs, true, 'TypeScript deve manter allowJs=true')
assert.equal(tsconfig.compilerOptions.checkJs, false, 'TypeScript deve manter checkJs=false')
assert.equal(tsconfig.compilerOptions.noEmit, true, 'TypeScript deve manter noEmit=true')
assert.equal(statSync(join(frontendRoot, 'src/App.jsx')).isFile(), true, 'App.jsx deve continuar existindo')

for (const blockedDependency of [
  '@hookform/resolvers',
  'formik',
  'yup',
  'joi',
  '@playwright/test',
  'cypress',
]) {
  assert.equal(
    installedDependencies[blockedDependency],
    undefined,
    `Dependencia nao autorizada nesta fase: ${blockedDependency}`,
  )
}

for (const forbiddenImport of [
  '../poc',
  './poc',
  'frontend/poc',
  'poc/',
  '.example.ts',
  '.example.tsx',
]) {
  assert.doesNotMatch(sourceText, new RegExp(forbiddenImport.replaceAll('.', '\\.')), `POC nao deve ser importada no runtime: ${forbiddenImport}`)
}

const pocFiles = walk(pocRoot).map((path) => path.replaceAll('\\', '/'))
assert.ok(pocFiles.length > 0, 'POC isolada deve existir apenas como exemplo')
for (const file of pocFiles) {
  assert.match(file, /frontend\/poc\/|\/poc\//, `Arquivo POC deve permanecer em frontend/poc: ${file}`)
  assert.match(file, /\.(md|example\.tsx?|example\.ts)$/, `POC deve ser documentacao ou exemplo: ${file}`)
}

const runtimeTsxFiles = walk(srcRoot).filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado nesta fase')

const pageTsFiles = walk(join(srcRoot, 'pages')).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript nesta fase')

for (const runtimeFile of [
  'src/hooks/useResource.ts',
  'src/lib/api.ts',
  'src/lib/format.ts',
  'src/lib/navigation.ts',
  'src/lib/operational.ts',
  'src/lib/queryClient.ts',
  'src/lib/queryKeys.ts',
]) {
  assert.equal(statSync(join(frontendRoot, runtimeFile)).isFile(), true, `${runtimeFile} deve existir`)
}

for (const removedJsFile of [
  'src/hooks/useResource.js',
  'src/lib/api.js',
  'src/lib/format.js',
  'src/lib/navigation.js',
  'src/lib/operational.js',
]) {
  assert.throws(() => statSync(join(frontendRoot, removedJsFile)), `${removedJsFile} deve ter sido migrado para .ts`)
}

assert.equal(statSync(join(projectRoot, 'app/index.html')).isFile(), true, '/app/index.html deve existir apos build')

console.log('Visual frontend QA OK')
