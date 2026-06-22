import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const srcRoot = join(frontendRoot, 'src')

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

const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|css)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')
const appSource = read('src/App.jsx')
const navigationSource = read('src/lib/navigation.js')
const operationalSource = read('src/pages/OperationalPages.jsx')
const cssSource = read('src/index.css')
const statesSource = read('src/components/ui/States.jsx')
const packageJson = JSON.parse(read('package.json'))

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
  ['undefined', 'Sem escala definida'],
]) {
  assert.match(
    operationalSource,
    new RegExp(`\\{ value: '${value}', label: '${label}' \\}`),
    `Regra presencial preservada: ${value}`,
  )
}

const saveRuleSource = operationalSource.match(/async function saveRule\(event\) \{[\s\S]*?\n  \}/)?.[0] || ''
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

console.log('Visual frontend QA OK')
