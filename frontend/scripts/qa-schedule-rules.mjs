import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const projectRoot = join(frontendRoot, '..')
const srcRoot = join(frontendRoot, 'src')

function readFrontend(relativePath) {
  return readFileSync(join(frontendRoot, relativePath), 'utf8')
}

function readProject(relativePath) {
  return readFileSync(join(projectRoot, relativePath), 'utf8')
}

function walk(dir) {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) return walk(path)
    return path
  })
}

const operationalPage = readFrontend('src/pages/OperationalPages.jsx')
const paMapPage = readFrontend('src/pages/PaMapPage.jsx')
const operationalLib = readFrontend('src/lib/operational.ts')
const operationalService = readProject('services/OperationalService.php')
const paMapService = readProject('services/PaMapService.php')
const migration = readProject('migrations/20260624_010_schedule_fixed_weekdays.sql')
const packageJson = JSON.parse(readFrontend('package.json'))
const installedDependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
}

for (const ruleType of [
  'even_days',
  'odd_days',
  'always_onsite',
  'always_remote',
  'undefined',
  'fixed_weekdays',
]) {
  assert.match(operationalPage, new RegExp(`value: '${ruleType}'|${ruleType}`), `Frontend preserva rule_type ${ruleType}`)
  assert.match(operationalService, new RegExp(ruleType), `Backend preserva rule_type ${ruleType}`)
}

assert.match(operationalPage, /\{ value: 'fixed_weekdays', label: 'Dias fixos da semana' \}/, 'UI deve oferecer Dias fixos da semana')
assert.match(operationalPage, /const WEEKDAY_OPTIONS = \[/, 'UI deve declarar dias permitidos')
for (const weekday of ['mon', 'tue', 'wed', 'thu', 'fri']) {
  assert.match(operationalPage, new RegExp(`value: '${weekday}'`), `UI deve permitir ${weekday}`)
  assert.match(operationalService, new RegExp(`'${weekday}'`), `Backend deve permitir ${weekday}`)
}
assert.doesNotMatch(operationalPage, /value: 'sat'|value: 'sun'/, 'UI inicial nao deve expor fim de semana')

const saveRuleSource = operationalPage.match(/async function saveRule\(event\) \{[\s\S]*?\n  \}/)?.[0] || ''
assert.match(saveRuleSource, /SCHEDULE_RULE_VALUES\.has\(ruleType\)/, 'Frontend deve validar rule_type canonico')
assert.match(saveRuleSource, /ruleType === 'fixed_weekdays' && rule\.weekdays\.length === 0/, 'Frontend deve rejeitar fixed_weekdays sem dias')
assert.match(saveRuleSource, /payload\.weekdays = rule\.weekdays/, 'Frontend deve enviar weekdays apenas para fixed_weekdays')
assert.match(saveRuleSource, /post\('portal\/schedules\.php', payload\)/, 'Frontend deve manter endpoint de escala')
assert.doesNotMatch(saveRuleSource, /fetch\(/, 'Frontend nao deve criar fetch direto')
assert.doesNotMatch(saveRuleSource, /postForm\(/, 'Regra fixa nao deve usar upload/formdata')

assert.match(operationalService, /private const RULE_TYPES = \[[\s\S]*'fixed_weekdays'/, 'Backend deve aceitar fixed_weekdays na lista canonica')
assert.match(operationalService, /weekdaysForRulePayload\(string \$rule, \$value\)/, 'Backend deve validar weekdays do payload')
assert.match(operationalService, /throw new InvalidArgumentException\('Selecione ao menos um dia presencial\.'\)/, 'Backend deve rejeitar fixed_weekdays sem dias')
assert.match(operationalService, /throw new InvalidArgumentException\('Dia da semana invalido\.'\)/, 'Backend deve rejeitar dia invalido')
assert.match(operationalService, /throw new InvalidArgumentException\('Dias da semana duplicados\.'\)/, 'Backend deve rejeitar duplicidade de dias')
assert.match(operationalService, /presenceForRule\(string \$rule, string \$date, array \$weekdays = \[\]\)/, 'Calculo deve receber weekdays')
assert.match(operationalService, /'fixed_weekdays' => in_array\(strtolower\(\$dateValue->format\('D'\)\), \$weekdays, true\) \? 'onsite' : 'remote'/, 'Calculo deve transformar dias selecionados em presencial/remoto')
assert.match(operationalService, /rule_config = VALUES\(rule_config\)/, 'UPSERT deve persistir rule_config')
assert.match(operationalService, /SET rule_type = "undefined",\s*rule_config = NULL/s, 'Remocao deve limpar rule_config')

assert.match(paMapService, /'fixed_weekdays'/, 'Mapa de PA deve reconhecer fixed_weekdays')
assert.match(paMapService, /schedule_rule_config/, 'Mapa de PA deve persistir snapshot da configuracao')
assert.match(paMapService, /OperationalService::presenceForRule\(\$ruleType, \$date, \$weekdays\)/, 'Mapa de PA deve calcular fixed_weekdays por data')
assert.match(paMapPage, /fixed_weekdays: 'border-cyan/, 'Mapa de PA deve ter tom visual para fixed_weekdays')

assert.match(migration, /ADD COLUMN rule_config TEXT DEFAULT NULL AFTER rule_type/, 'Migration deve adicionar rule_config')
assert.match(migration, /fixed_weekdays/, 'Migration deve incluir fixed_weekdays nos ENUMs')
assert.match(migration, /ADD COLUMN schedule_rule_config TEXT DEFAULT NULL AFTER schedule_rule_type/, 'Migration deve adicionar schedule_rule_config')
assert.match(migration, /information_schema\.COLUMNS/, 'Migration deve ser idempotente por information_schema')
assert.match(migration, /NOT LIKE '%fixed_weekdays%'/, 'Migration deve evitar ALTER repetido de ENUM')

assert.match(operationalLib, /\| 'fixed_weekdays'/, 'Tipo frontend deve incluir fixed_weekdays')
assert.equal(packageJson.scripts['qa:schedule-rules'], 'node scripts/qa-schedule-rules.mjs')

const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')
assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutacao deve ser migrada para TanStack Query')
assert.doesNotMatch(readFrontend('src/hooks/useLivePauses.js'), /fixed_weekdays|@tanstack\/react-query|@tanstack\/react-table|useQuery|useMutation/, 'useLivePauses nao deve ser alterado')
assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

for (const file of sourceFiles) {
  const normalized = file.replaceAll('\\', '/')
  if (normalized.endsWith('/src/lib/api.ts')) continue
  assert.doesNotMatch(readFileSync(file, 'utf8'), /\bfetch\(/, `Fetch direto fora de api.ts nao permitido: ${normalized}`)
}

const runtimeTsxFiles = sourceFiles.filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado')

const pageTsFiles = walk(join(srcRoot, 'pages')).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript')

for (const blockedDependency of [
  'react-hook-form',
  'zod',
  '@playwright/test',
  'cypress',
]) {
  assert.equal(installedDependencies[blockedDependency], undefined, `Dependencia nao autorizada: ${blockedDependency}`)
}

console.log('Schedule rules QA OK')
