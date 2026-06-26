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
const installedDependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
}

for (const dependency of [
  '@tanstack/react-query',
  '@tanstack/react-table',
  'react-hook-form',
  'zod',
]) {
  assert.equal(typeof packageJson.dependencies?.[dependency], 'string', `${dependency} deve ser dependencia de runtime`)
  assert.match(packageLock, new RegExp(`"node_modules/${dependency.replace('/', '\\/')}"`), `${dependency} deve existir no package-lock`)
}

for (const blockedDependency of [
  '@hookform/resolvers',
  'formik',
  'yup',
  'joi',
  '@playwright/test',
  'cypress',
]) {
  assert.equal(installedDependencies[blockedDependency], undefined, `Dependencia proibida instalada: ${blockedDependency}`)
  assert.doesNotMatch(packageLock, new RegExp(`"node_modules/${blockedDependency.replace('/', '\\/')}"`), `Dependencia proibida no lock: ${blockedDependency}`)
}

assert.equal(packageJson.scripts['qa:forms'], 'node scripts/qa-forms.mjs', 'Script qa:forms deve estar registrado')

const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const runtimeSource = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')
const operationalSource = read('src/pages/OperationalPages.jsx')
const schemaSource = read('src/lib/formSchemas.ts')
const reportsPageSource = operationalSource.match(/export function OperationalReportsPage\(\) \{[\s\S]*?\n\}\r?\n\r?\nfunction ReportsSummaryTable/)?.[0] || ''

const rhfFiles = sourceFiles.filter((path) => readFileSync(path, 'utf8').includes('react-hook-form'))
assert.deepEqual(
  rhfFiles.map((path) => relative(frontendRoot, path).replaceAll('\\', '/')),
  ['src/pages/OperationalPages.jsx'],
  'react-hook-form deve continuar restrito a OperationalPages',
)

const zodFiles = sourceFiles.filter((path) => readFileSync(path, 'utf8').includes("from 'zod'") || readFileSync(path, 'utf8').includes('from "zod"'))
assert.deepEqual(
  zodFiles.map((path) => relative(frontendRoot, path).replaceAll('\\', '/')),
  ['src/lib/formSchemas.ts'],
  'zod deve ser importado apenas no schema pequeno da Fase 14',
)

assert.match(schemaSource, /export const reportFiltersSchema = z\.object\(\{/, 'Schema de filtros de relatorios deve existir')
assert.match(schemaSource, /export const scheduleRuleSchema = z\.object\(\{/, 'Schema de regra de escala deve existir')
assert.match(schemaSource, /competency:\s*z\.string\(\)\.min\(1/, 'Schema deve validar competencia obrigatoria')
assert.match(schemaSource, /team:\s*z\.enum\(\['', 'n1', 'n2'\]\)\.default\(''\)/, 'Schema deve validar equipe permitida')
assert.match(schemaSource, /employee_id:\s*z\.string\(\)\.default\(''\)/, 'Schema deve manter employee_id opcional')

assert.match(reportsPageSource, /useForm\(\{[\s\S]*defaultValues:\s*filters/, 'Relatorios deve controlar filtros com React Hook Form')
assert.match(reportsPageSource, /reportFiltersSchema\.safeParse\(values\)/, 'Relatorios deve validar submit com Zod manual')
assert.match(reportsPageSource, /setFilters\(parsed\.data\)/, 'Submit valido deve atualizar filters')
assert.match(reportsPageSource, /name="download"|Exportar CSV|apiUrl\(`portal\/reports\.php\$\{queryString\(\{ \.\.\.filters, format: 'csv' \}\)\}`\)/, 'Export CSV deve continuar presente')
assert.match(reportsPageSource, /useQuery\(\{/, 'Relatorios deve continuar usando useQuery')
assert.match(reportsPageSource, /queryKey:\s*queryKeys\.reports\(filters\)/, 'Relatorios deve continuar usando queryKeys.reports(filters)')
assert.match(reportsPageSource, /queryFn:\s*\(\) => api\(`portal\/reports\.php\$\{queryString\(filters\)\}`\)/, 'Relatorios deve continuar usando api() para GET')
assert.match(reportsPageSource, /queryString\(filters\)/, 'Querystring final deve ser baseada em filters')
assert.match(reportsPageSource, /queryString\(\{ \.\.\.filters, format: 'csv' \}\)/, 'Export CSV deve preservar filters e format=csv')
assert.doesNotMatch(reportsPageSource, /\bpost\(|\bpostForm\(|\buseMutation\(|method:\s*['"]POST['"]/, 'Relatorios nao deve criar POST ou mutation')

assert.doesNotMatch(runtimeSource, /\buseMutation\(/, 'Nenhuma mutation deve ser criada')
assert.equal(existsSync(join(srcRoot, 'hooks', 'useResource.ts')), true, 'useResource.ts deve continuar existindo')
assert.equal(existsSync(join(srcRoot, 'hooks', 'useLivePauses.js')), true, 'useLivePauses.js deve continuar existindo')
assert.equal(execFileSync('git', ['diff', '--name-only', '--', 'frontend/src/hooks/useLivePauses.js'], { cwd: projectRoot, encoding: 'utf8' }).trim(), '', 'useLivePauses.js nao deve ser alterado')

for (const path of sourceFiles) {
  const normalized = relative(frontendRoot, path).replaceAll('\\', '/')
  if (normalized === 'src/lib/api.ts') continue
  assert.doesNotMatch(readFileSync(path, 'utf8'), /\bfetch\(/, `Fetch direto fora de api.ts nao permitido: ${normalized}`)
}

const runtimeTsxFiles = sourceFiles.filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado')

const pageTsFiles = walk(pagesRoot).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript')

assert.doesNotMatch(runtimeSource, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

console.log('Forms frontend QA OK')
