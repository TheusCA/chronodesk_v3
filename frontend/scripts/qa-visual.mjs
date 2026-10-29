import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

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

// F2B: validacao em parseRule, payload em buildRulePayload (rule e rule_preview), gravacao em saveRule.
const ruleFlowSource = [
  /\n  function parseRule\(values\) \{[\s\S]*?\n  \}/,
  /\nfunction buildRulePayload\(data, action\) \{[\s\S]*?\n\}/,
  /async function saveRule\(values\) \{[\s\S]*?\n  \}/,
].map((pattern) => operationalSource.match(pattern)?.[0] || '').join('\n')
const saveRuleSource = ruleFlowSource
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
assert.equal(packageJson.scripts['qa:employee-form'], 'node scripts/qa-employee-form.mjs')
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

// ---------------------------------------------------------------------------
// [17e, item 1] Chamados criticos: a coluna Acoes fica fixa a direita
// (sticky, fundo opaco), so a tabela rola (o titulo do cartao nao) e os botoes
// ficam numa grade estreita. A largura vem da medicao no navegador (desenho
// 9.11); aqui, a regra do CSS e o HTML renderizado de verdade (JSX transpilado
// pelo TypeScript do projeto, react-dom/server, useResource simulado).
// ---------------------------------------------------------------------------
const stickyRule = cssSource.match(/\n\.data-table \.data-table-sticky-end \{([^}]*)\}/)?.[1] || ''
assert.notEqual(stickyRule, '', 'Regra .data-table .data-table-sticky-end fora do @layer (dois seletores vencem .data-table th/td)')
// Fora de qualquer bloco: profundidade de chaves 0 no seletor (comentarios removidos, mesmo tamanho).
const cssNoComments = cssSource.replace(/\/\*[\s\S]*?\*\//g, (comment) => ' '.repeat(comment.length))
const stickyAt = cssNoComments.indexOf('\n.data-table .data-table-sticky-end {')
const depthAt = (text, index) => [...text.slice(0, index)].reduce((depth, char) => depth + (char === '{') - (char === '}'), 0)
assert.equal(depthAt(cssNoComments, stickyAt), 0, 'Coluna fixa fora do @layer components (e de qualquer bloco)')
for (const [pattern, label] of [
  [/(^|\n)\s*position:\s*sticky;/, 'position: sticky'],
  [/(^|\n)\s*right:\s*0;/, 'right: 0'],
  [/(^|\n)\s*z-index:\s*10;/, 'z-index acima das outras celulas'],
  [/(^|\n)\s*white-space:\s*nowrap;/, 'botoes sem quebra no meio da palavra'],
  [/(^|\n)\s*background-color:\s*theme\('colors\.chrono\.card'\);/, 'fundo da cor do cartao'],
]) {
  assert.match(stickyRule, pattern, `Coluna fixa: ${label}`)
}
const tailwindSource = read('tailwind.config.js')
assert.match(tailwindSource, /chrono:\s*\{[\s\S]*?\bcard:\s*'#[0-9a-f]{6}'/i, 'Cor do cartao com 6 digitos: fundo opaco, sem transparencia')
assert.match(cssSource, /\n\.data-table th\.data-table-sticky-end \{[^}]*background-image:\s*linear-gradient\(rgba\(2, 6, 23, 0\.3\)/, 'Cabecalho fixo com o mesmo tom do cabecalho, por cima do fundo opaco')
assert.match(cssSource, /\n\.data-table tbody tr:hover > \.data-table-sticky-end \{[^}]*background-image:/, 'Realce da linha tambem na celula fixa, sem perder o fundo opaco')

const resourceStub = 'data:text/javascript,' + encodeURIComponent(
  'export function useResource(path) { const hit = Object.entries(globalThis.qaResources ?? {}).find(([key]) => path.startsWith(key)); return { loading: false, error: null, data: hit ? hit[1] : null, refresh: async () => null, setData: () => {} } }',
)
const srcUrl = pathToFileURL(srcRoot).href + '/'
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && /\/src\/pages\/CriticalIncidentsPage\.jsx(\?.*)?$/u.test(context.parentURL ?? '')) return { url: resourceStub, shortCircuit: true }
    if ((specifier.startsWith('./') || specifier.startsWith('../')) && !/\.[cm]?[jt]sx?$/u.test(specifier) && context.parentURL?.startsWith(srcUrl)) {
      for (const extension of ['.ts', '.tsx', '.js', '.jsx']) {
        const candidate = new URL(specifier + extension, context.parentURL)
        if (existsSync(fileURLToPath(candidate))) return nextResolve(candidate.href, context)
      }
    }
    return nextResolve(specifier, context)
  },
  load(url, context, nextLoad) {
    const path = url.split('?')[0]
    if (path.startsWith(srcUrl) && /\.(jsx|tsx|ts)$/u.test(path)) {
      const output = ts.transpileModule(readFileSync(fileURLToPath(path), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { CriticalIncidentsPage } = await import(pathToFileURL(join(srcRoot, 'pages', 'CriticalIncidentsPage.jsx')).href)

const incident = (id, status) => ({
  id, incident_number: `INC-QA-${id}`, title: `Incidente QA ${id}`, room_date: '2026-10-28', room_description: `Sala QA ${id}`,
  room_duration_minutes: 90, room_opening_duration_minutes: 15, severity: 'critical', status, sector: 'Carteira QA', responsible_area: 'CC QA',
  sdk_responsible_name: 'Tecnico QA', sdk_responsible_login: 'tecnico.qa', sdk_activity: 'Acompanhamento', opened_at: '2026-10-28 08:00:00', source: 'manual',
})
const INCIDENTS = [incident(1, 'open'), incident(2, 'mitigated'), incident(3, 'resolved'), incident(4, 'cancelled')]
function renderIncidents(role, items = INCIDENTS) {
  globalThis.qaResources = {
    'portal/critical_incidents.php': { sucesso: true, limit: 200, truncated: false, summary: {}, items },
    'portal/sdk_responsibles.php': { sucesso: true, items: [] },
  }
  try {
    return renderToStaticMarkup(h(CriticalIncidentsPage, { session: { role, permissions: [], ci: { funcionario_id: 0 }, gestor: {} }, notify: () => {} }))
  } finally {
    delete globalThis.qaResources
  }
}
const tableOf = (markup) => markup.slice(markup.indexOf('<table'), markup.indexOf('</table>') + 8)
const cellsOf = (row) => row.match(/<t[hd][\s>][\s\S]*?<\/t[hd]>/g) || []
const rowsOf = (table) => table.match(/<tr>[\s\S]*?<\/tr>/g) || []
const buttonsOf = (cell) => (cell.match(/<button[^>]*>([^<]*)<\/button>/g) || []).map((button) => button.replace(/<[^>]+>/g, ''))
const EXPECTED_BUTTONS = {
  gestor: [['Visualizar', 'Editar', 'Mitigar', 'Resolver'], ['Visualizar', 'Editar', 'Resolver'], ['Visualizar', 'Editar'], ['Visualizar', 'Editar']],
  tecnico: [['Visualizar'], ['Visualizar'], ['Visualizar'], ['Visualizar']],
}
for (const role of ['gestor', 'lideranca', 'admin', 'tecnico']) {
  const markup = renderIncidents(role)
  const table = tableOf(markup)
  const [head, ...body] = rowsOf(table)
  assert.equal(body.length, INCIDENTS.length, `${role}: uma linha por chamado`)
  const headCells = cellsOf(head)
  assert.equal(headCells.length, 9, `${role}: nove colunas`)
  assert.match(headCells.at(-1), /^<th class="data-table-sticky-end">Ações<\/th>$/u, `${role}: Ações e a ultima coluna e a fixa`)
  for (const cell of headCells.slice(0, -1)) assert.doesNotMatch(cell, /data-table-sticky-end/, `${role}: so a coluna Ações fica fixa no cabecalho`)
  body.forEach((row, index) => {
    const cells = cellsOf(row)
    assert.equal(cells.length, 9, `${role}: linha ${index + 1} com nove celulas`)
    assert.match(cells.at(-1), /^<td class="data-table-sticky-end"><div class="grid gap-1 sm:grid-cols-2">/, `${role}: linha ${index + 1}, celula de Ações fixa, com os botoes em grade (uma coluna no celular, duas de 640 px)`)
    for (const cell of cells.slice(0, -1)) assert.doesNotMatch(cell, /data-table-sticky-end|<button/, `${role}: linha ${index + 1}, nenhuma outra celula fixa ou com botao`)
    assert.deepEqual(buttonsOf(cells.at(-1)), EXPECTED_BUTTONS[role === 'tecnico' ? 'tecnico' : 'gestor'][index], `${role}: linha ${index + 1}, botoes da linha todos na celula fixa`)
  })
  assert.equal((markup.match(/data-table-sticky-end/g) || []).length, INCIDENTS.length + 1, `${role}: cabecalho e uma celula por linha, nada mais`)
  // So a tabela rola: o table-wrap e o bloco da tabela, depois do titulo; o cartao nao rola.
  // O SectionHeader tambem e um <section>: o cartao e o <section class="card..."> antes do titulo.
  const sectionStart = markup.lastIndexOf('<section class="card', markup.indexOf('Incidentes e salas críticas'))
  const section = markup.slice(sectionStart, markup.indexOf('</table>', sectionStart))
  assert.match(section, /^<section class="card">/, `${role}: o cartao da lista nao rola inteiro`)
  assert.match(section, /Incidentes e salas críticas[\s\S]*<div class="table-wrap mt-5"><table class="data-table min-w-\[1380px\]">/u, `${role}: o table-wrap envolve so a tabela, abaixo do titulo`)
  assert.equal((markup.match(/table-wrap/g) || []).length, 1, `${role}: um so bloco com rolagem`)
}
const emptyMarkup = renderIncidents('gestor', [])
assert.doesNotMatch(emptyMarkup, /<table|data-table-sticky-end/, 'Lista vazia sem tabela nem coluna fixa')
assert.match(emptyMarkup, /Nenhum chamado crítico encontrado/u, 'Lista vazia com o estado vazio')

console.log('Visual frontend QA OK')
