import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const srcRoot = new URL('../src/', import.meta.url)
const operationalTsUrl = new URL('../src/lib/operational.ts', import.meta.url)
const operationalJsUrl = new URL('../src/lib/operational.js', import.meta.url)

function walk(dirUrl) {
  return readdirSync(dirUrl).flatMap((entry) => {
    const path = join(fileURLToPath(dirUrl), entry)
    if (statSync(path).isDirectory()) return walk(new URL(`${entry}/`, dirUrl))
    return path
  })
}

async function importTypeScriptModule(moduleUrl) {
  const source = readFileSync(moduleUrl, 'utf8')
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      isolatedModules: true,
      module: ts.ModuleKind.ES2020,
      target: ts.ScriptTarget.ES2020,
    },
  }).outputText
  const encoded = Buffer.from(transpiled, 'utf8').toString('base64')
  return import(`data:text/javascript;base64,${encoded}`)
}

assert.equal(existsSync(operationalTsUrl), true, 'src/lib/operational.ts deve existir')
assert.equal(existsSync(operationalJsUrl), false, 'src/lib/operational.js deve ter sido migrado')

const {
  competencyFor,
  CRITICAL_INCIDENT_IMPORT_LIMITS,
  IMPORT_LIMITS,
  parseCriticalIncidentCsv,
  parseCsv,
  queryString,
} = await importTypeScriptModule(operationalTsUrl)

assert.equal(IMPORT_LIMITS.maxFileBytes, 2097152)
assert.deepEqual(IMPORT_LIMITS.acceptedExtensions, ['.csv', '.xlsx'])
assert.equal(CRITICAL_INCIDENT_IMPORT_LIMITS.maxFileBytes, 2097152)
assert.deepEqual(CRITICAL_INCIDENT_IMPORT_LIMITS.acceptedExtensions, ['.csv', '.xlsx'])
assert.equal(queryString({ a: '1', empty: '', missing: null, absent: undefined, zero: 0 }), '?a=1&zero=0')

const rows = parseCsv('id;equipe;regra\n1;n1;par')
assert.equal(rows.length, 1)
assert.deepEqual(rows[0], { id: '1', equipe: 'n1', regra: 'par' })

for (const invalidCsv of [
  'id;regra;extra\n1;par;x',
  'id;regra\n1;"x',
  'id;regra\n1;par;overflow',
]) {
  assert.throws(() => parseCsv(invalidCsv))
}

const oversizedCell = `id;regra\n1;${'x'.repeat(IMPORT_LIMITS.maxCellChars + 1)}`
assert.throws(() => parseCsv(oversizedCell))

const competency = competencyFor('2026-04-01T12:00:00')
assert.equal(competency.start, '2026-03-16')
assert.equal(competency.end, '2026-04-15')

const criticalRows = parseCriticalIncidentCsv(
  'ticket_number;source;title;severity;status;opened_at\n'
  + 'INC001;servicenow;Falha critica;critical;open;2026-06-14 10:00',
)
assert.equal(criticalRows.length, 1)
assert.equal(criticalRows[0].ticket_number, 'INC001')

const operationalCriticalRows = parseCriticalIncidentCsv(
  'incident_number;room_date;operation_reported_at;room_opened_at\n'
  + 'INC002;2026-06-15;2026-06-15 10:00;2026-06-15 10:12',
)
assert.equal(operationalCriticalRows[0].incident_number, 'INC002')

for (const invalidCriticalCsv of [
  'ticket_number;title;severity;status;opened_at\nINC001;Falha;critical;open;2026-06-14 10:00',
  'ticket_number;source;title;severity;status;opened_at;payload\nINC001;manual;Falha;high;open;2026-06-14 10:00;x',
  'ticket_number;source;title;severity;status;opened_at\nINC001;manual;"Falha;high;open;2026-06-14 10:00',
]) {
  assert.throws(() => parseCriticalIncidentCsv(invalidCriticalCsv))
}

const oversizedCriticalCell = [
  'ticket_number;source;title;severity;status;opened_at',
  `INC001;manual;${'x'.repeat(CRITICAL_INCIDENT_IMPORT_LIMITS.maxCellChars + 1)};high;open;2026-06-14 10:00`,
].join('\n')
assert.throws(() => parseCriticalIncidentCsv(oversizedCriticalCell))

const navigationSource = readFileSync(new URL('../src/lib/navigation.ts', import.meta.url), 'utf8')
assert.match(navigationSource, /path: '\/admin'.*permission: 'operacao\.approve'/)

const adminSource = readFileSync(new URL('../src/pages/AdminPage.jsx', import.meta.url), 'utf8')
assert.match(adminSource, /availableTabs = isAdmin \? tabs : tabs\.filter\(\(\[key\]\) => key === 'aprovacoes' \|\| \(key === 'funcionarios' && canManageEmployees\)\)/)
assert.match(adminSource, /const canManageEmployees = permissions\.has\('funcionarios\.manage'\)/)
assert.match(adminSource, /useResource\('listar_funcionarios\.php', \{ enabled: canManageEmployees \}\)/)
assert.match(adminSource, /useResource\('configuracoes\.php', \{ enabled: isAdmin \}\)/)

const operationalPagesSource = readFileSync(new URL('../src/pages/OperationalPages.jsx', import.meta.url), 'utf8')
for (const [value, label] of [
  ['even_days', 'Dias pares'],
  ['odd_days', 'Dias .mpares'],
  ['always_onsite', 'Sempre presencial'],
  ['always_remote', 'Sempre remoto'],
  ['fixed_weekdays', 'Dias fixos da semana'],
  ['undefined', 'Sem escala definida'],
]) {
  assert.match(
    operationalPagesSource,
    new RegExp(`\\{ value: '${value}', label: '${label}' \\}`),
    `regra fixa exibe ${label} com value canonico ${value}`,
  )
}

// F2B: validacao em parseRule, payload em buildRulePayload (rule e rule_preview), gravacao em saveRule.
const ruleFlowSource = [
  /\n  function parseRule\(values\) \{[\s\S]*?\n  \}/,
  /\nfunction buildRulePayload\(data, action\) \{[\s\S]*?\n\}/,
  /async function saveRule\(values\) \{[\s\S]*?\n  \}/,
].map((pattern) => operationalPagesSource.match(pattern)?.[0] || '').join('\n')
const saveRuleSource = ruleFlowSource
assert.match(saveRuleSource, /SCHEDULE_RULE_VALUES\.has\(ruleType\)/)
assert.match(saveRuleSource, /scheduleRuleSchema\.safeParse\(/)
assert.match(readFileSync(new URL('../src/lib/formSchemas.ts', import.meta.url), 'utf8'), /value\.rule_type === 'fixed_weekdays' && value\.input_mode === 'onsite' && value\.weekdays\.length === 0/)
assert.match(saveRuleSource, /payload\.weekdays = data\.weekdays/)
assert.match(saveRuleSource, /post\('portal\/schedules\.php', buildRulePayload\(data, 'rule'\)\)/)
assert.doesNotMatch(saveRuleSource, /\.\.\.rule/)
assert.doesNotMatch(saveRuleSource, /\brule:\s*/)
assert.doesNotMatch(saveRuleSource, /\bschedule_rule:\s*/)
assert.doesNotMatch(saveRuleSource, /\bstatus:\s*/)
assert.doesNotMatch(saveRuleSource, /rule_type:\s*['"]undefined['"]/)

const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')
assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

const runtimeTsxFiles = sourceFiles.filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado nesta fase')

const pageTsFiles = walk(new URL('../src/pages/', import.meta.url)).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript nesta fase')

const packageJson = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'))
const installedDependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
}

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

// ---------------------------------------------------------------------------
// [17e, item 2] Horas extras e Correcao de ponto, pendentes abaixo de 1280 px:
// um cartao por solicitacao, com o mesmo detalhe e a mesma decisao da linha
// da tabela (detailContent e decisionContent). A pagina e renderizada de
// verdade (JSX transpilado pelo TypeScript do projeto, react-dom/server); o
// useResource responde com globalThis.qaResources, sem rede.
// ---------------------------------------------------------------------------
const pageSource = readFileSync(new URL('../src/pages/OperationalPages.jsx', import.meta.url), 'utf8')
const workflowSource = pageSource.slice(pageSource.indexOf('function WorkflowPage('), pageSource.indexOf('export function OvertimePage('))
assert.match(workflowSource, /function renderRows\(rows\) \{[\s\S]*?<td>\{detailContent\(item\)\}<\/td>[\s\S]*?<td>\{decisionContent\(item\)\}<\/td>/, 'Linha da tabela com os blocos compartilhados')
assert.match(workflowSource, /function renderCards\(rows\) \{[\s\S]*?\{detailContent\(item\)\}[\s\S]*?\{decisionContent\(item\)\}/, 'Cartao com os mesmos blocos da linha')
const decisionSource = workflowSource.match(/function decisionContent\(item\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(decisionSource, /onClick=\{\(\) => decide\(item\.id, 'approved'\)\}[\s\S]*onClick=\{\(\) => decide\(item\.id, 'rejected'\)\}/, 'Aprovar e Rejeitar chamam a mesma decisao, na linha e no cartao')
assert.doesNotMatch(workflowSource, /\bconfirm\(|\balert\(|\bprompt\(/, 'Sem dialogo do navegador nas decisoes')

const srcHref = new URL('../src/', import.meta.url).href
const resourceStub = 'data:text/javascript,' + encodeURIComponent(
  'export function useResource(path) { const hit = Object.entries(globalThis.qaResources ?? {}).find(([key]) => path.startsWith(key)); return { loading: false, error: null, data: hit ? hit[1] : null, refresh: async () => null, setData: () => {} } }',
)
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && /\/src\/pages\/OperationalPages\.jsx(\?.*)?$/u.test(context.parentURL ?? '')) return { url: resourceStub, shortCircuit: true }
    if ((specifier.startsWith('./') || specifier.startsWith('../')) && !/\.[cm]?[jt]sx?$/u.test(specifier) && context.parentURL?.startsWith(srcHref)) {
      for (const extension of ['.ts', '.tsx', '.js', '.jsx']) {
        const candidate = new URL(specifier + extension, context.parentURL)
        if (existsSync(fileURLToPath(candidate))) return nextResolve(candidate.href, context)
      }
    }
    return nextResolve(specifier, context)
  },
  load(url, context, nextLoad) {
    const path = url.split('?')[0]
    if (path.startsWith(srcHref) && /\.(jsx|tsx|ts)$/u.test(path)) {
      const output = ts.transpileModule(readFileSync(fileURLToPath(path), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { OvertimePage, TimeCorrectionPage } = await import(new URL('../src/pages/OperationalPages.jsx', import.meta.url).href)

const request = (overtimeKind, id, status, extra = {}) => ({
  id, employee_id: id, employee_name: `QA Pessoa ${id}`, team: id % 2 ? 'n1' : 'n2', status,
  approved_by: status === 'pending' ? null : 'gestor.qa', approved_at: status === 'pending' ? null : '2026-10-28 09:00:00',
  justification: `Justificativa QA ${id}`,
  ...(overtimeKind
    ? { work_date: `2026-10-2${id}`, start_time: '18:00:00', end_time: '20:30:00', total_minutes: 150, reason: `Motivo QA ${id}` }
    : { adjustment_date: `2026-10-2${id}`, adjustment_type: id === 2 ? 'absence' : 'entry', recorded_time: id === 2 ? null : '08:10:00', correct_time: id === 2 ? null : '08:00:00' }),
  ...extra,
})
const between = (text, start, end) => { const i = text.indexOf(start); return i < 0 ? '' : text.slice(i, text.indexOf(end, i + start.length) + end.length) }
const sectionOf = (markup, title) => {
  const at = markup.indexOf(`>${title}</h2>`)
  const start = markup.lastIndexOf('<section class="card', at)
  const next = markup.indexOf('<section class="card', at)
  return at < 0 ? '' : markup.slice(start, next < 0 ? undefined : next)
}
const rowsOfTable = (html) => (html.match(/<tbody>([\s\S]*?)<\/tbody>/)?.[1].match(/<tr>[\s\S]*?<\/tr>/g)) || []
const cellsOfRow = (row) => (row.match(/<td>([\s\S]*?)<\/td>(?=<td>|<\/tr>)/g) || []).map((cell) => cell.slice(4, -5))
const cardsOf = (html) => html.match(/<article [^>]*data-request="\d+"[\s\S]*?<\/article>/g) || []
const cardParts = (card) => {
  const blocks = card.match(/<div class="min-w-0 text-sm[^"]*">([\s\S]*)<\/div><div class="border-t[^"]*">([\s\S]*)<\/div><\/article>$/)
  return { detail: blocks?.[1] ?? null, decision: blocks?.[2] ?? null }
}
const sessionFor = (role) => ({ role, permissions: [], ci: { funcionario_id: 1, username: 'qa' }, gestor: { username: 'qa' } })
function renderWorkflow(Page, overtimeKind, role, items) {
  const endpoint = overtimeKind ? 'portal/overtime.php' : 'portal/time_corrections.php'
  globalThis.qaResources = {
    [endpoint]: { sucesso: true, limit: 200, truncated: false, totals: { approved_minutes: 150, pending_minutes: 300 }, items },
    'listar_funcionarios.php': { sucesso: true, funcionarios: [] },
  }
  try {
    return renderToStaticMarkup(h(Page, { session: sessionFor(role), notify: () => {} }))
  } finally {
    delete globalThis.qaResources
  }
}

for (const [Page, overtimeKind, label] of [[OvertimePage, true, 'Horas extras'], [TimeCorrectionPage, false, 'Correcao de ponto']]) {
  const items = [request(overtimeKind, 1, 'pending'), request(overtimeKind, 2, 'pending'), request(overtimeKind, 3, 'approved'), request(overtimeKind, 4, 'rejected')]
  for (const role of ['gestor', 'lideranca', 'admin', 'tecnico', 'somente_leitura']) {
    const approver = ['gestor', 'lideranca', 'admin'].includes(role)
    const markup = renderWorkflow(Page, overtimeKind, role, items)
    const pendingSection = sectionOf(markup, 'Pendentes de aprovação')
    assert.match(pendingSection, /^<section class="card">/, `${label}/${role}: o cartao dos pendentes nao rola inteiro`)
    assert.match(pendingSection, /<div class="mt-5 grid items-start gap-3 md:grid-cols-2 xl:hidden" data-request-list="">/, `${label}/${role}: cartoes abaixo de 1280 px, uma coluna e duas de 768 px`)
    assert.match(pendingSection, /<div class="table-wrap mt-5 hidden xl:block"><table class="data-table">/, `${label}/${role}: tabela de 1280 px para cima, so ela rola`)
    const cards = cardsOf(pendingSection)
    const rows = rowsOfTable(between(pendingSection, '<table', '</table>'))
    assert.deepEqual(cards.map((card) => Number(card.match(/data-request="(\d+)"/)[1])), [1, 2], `${label}/${role}: um cartao por pendente, na ordem da tabela, sem o historico`)
    assert.equal(rows.length, 2, `${label}/${role}: a tabela dos pendentes com as mesmas duas linhas`)
    cards.forEach((card, index) => {
      const cells = cellsOfRow(rows[index])
      assert.equal(cells.length, 5, `${label}/${role}: linha ${index + 1} com cinco celulas`)
      const { detail, decision } = cardParts(card)
      assert.equal(detail, cells[2], `${label}/${role}: cartao ${index + 1} com o mesmo detalhe da linha`)
      assert.equal(decision, cells[4], `${label}/${role}: cartao ${index + 1} com a mesma decisao da linha`)
      assert.ok(card.includes(cells[3]), `${label}/${role}: cartao ${index + 1} com o mesmo status da linha`)
      const item = items[index]
      assert.ok(card.includes(`>QA Pessoa ${item.id}</strong>`) && card.includes(`Equipe ${item.team.toUpperCase()}</span>`), `${label}/${role}: cartao ${index + 1} com nome e equipe`)
      const date = (overtimeKind ? item.work_date : item.adjustment_date).split('-').reverse().join('/')
      assert.ok(card.includes(`Data: ${date}</span>`) && cells[0] === date, `${label}/${role}: cartao ${index + 1} com a data da linha`)
      const buttons = (decision.match(/<button[^>]*>([^<]*)<\/button>/g) || []).map((button) => button.replace(/<[^>]+>/g, ''))
      assert.deepEqual(buttons, approver ? ['Aprovar', 'Rejeitar'] : [], `${label}/${role}: cartao ${index + 1}, Aprovar e Rejeitar so para quem decide`)
      if (!approver) assert.match(decision, /Aguardando decisão/u, `${label}/${role}: sem decisao, o cartao mostra o aguardo, como a linha`)
    })
    assert.match(cards[0], overtimeKind ? /Motivo QA 1[\s\S]*Justificativa QA 1/ : /Justificativa QA 1/, `${label}/${role}: motivo e justificativa no cartao`)
    const historySection = sectionOf(markup, 'Histórico')
    assert.match(historySection, /^<section class="card table-wrap">/, `${label}/${role}: o historico (sem botao) nao muda`)
    assert.equal(cardsOf(historySection).length, 0, `${label}/${role}: historico sem cartoes`)
  }
  const noPending = renderWorkflow(Page, overtimeKind, 'gestor', [request(overtimeKind, 3, 'approved')])
  assert.match(sectionOf(noPending, 'Pendentes de aprovação'), /Nenhuma pendência neste filtro/u, `${label}: sem pendentes, o aviso`)
  assert.doesNotMatch(noPending, /data-request/, `${label}: sem pendentes, sem cartoes`)
}

console.log('Operational frontend QA OK')
