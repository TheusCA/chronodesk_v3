import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
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
assert.match(adminSource, /availableTabs = isAdmin \? tabs : tabs\.filter/)
assert.match(adminSource, /useResource\('configuracoes\.php', \{ enabled: isAdmin \}\)/)

const operationalPagesSource = readFileSync(new URL('../src/pages/OperationalPages.jsx', import.meta.url), 'utf8')
for (const [value, label] of [
  ['even_days', 'Dias pares'],
  ['odd_days', 'Dias .mpares'],
  ['always_onsite', 'Sempre presencial'],
  ['always_remote', 'Sempre remoto'],
  ['undefined', 'Sem escala definida'],
]) {
  assert.match(
    operationalPagesSource,
    new RegExp(`\\{ value: '${value}', label: '${label}' \\}`),
    `regra fixa exibe ${label} com value canonico ${value}`,
  )
}

const saveRuleSource = operationalPagesSource.match(/async function saveRule\(event\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(saveRuleSource, /SCHEDULE_RULE_VALUES\.has\(ruleType\)/)
assert.match(saveRuleSource, /notify\('Selecione uma regra de escala v.lida\.', 'error'\)/)
assert.match(saveRuleSource, /post\('portal\/schedules\.php', \{\s*action: 'rule',\s*employee_id: employeeId,\s*rule_type: ruleType,\s*effective_from: rule\.effective_from,\s*\}/)
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
  '@tanstack/react-query',
  '@tanstack/react-table',
  'react-hook-form',
  'zod',
  '@playwright/test',
  'cypress',
]) {
  assert.equal(
    installedDependencies[blockedDependency],
    undefined,
    `Dependencia nao autorizada nesta fase: ${blockedDependency}`,
  )
}

console.log('Operational frontend QA OK')
