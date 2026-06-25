import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const root = new URL('..', import.meta.url)
const srcRoot = new URL('../src/', import.meta.url)

function read(relativePath) {
  return readFileSync(new URL(relativePath, root), 'utf8')
}

function walk(dirUrl) {
  return readdirSync(dirUrl).flatMap((entry) => {
    const pathUrl = new URL(`${entry}${statSync(new URL(entry, dirUrl)).isDirectory() ? '/' : ''}`, dirUrl)
    if (statSync(pathUrl).isDirectory()) return walk(pathUrl)
    return pathUrl
  })
}

const packageJson = JSON.parse(read('package.json'))
const installedDependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
}

assert.equal(
  typeof packageJson.dependencies?.['@tanstack/react-query'],
  'string',
  '@tanstack/react-query deve continuar instalado',
)
assert.equal(
  typeof packageJson.dependencies?.['@tanstack/react-table'],
  'string',
  '@tanstack/react-table deve estar instalado nesta fase',
)

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

assert.equal(existsSync(new URL('../src/lib/api.ts', import.meta.url)), true, 'api.ts deve existir')
assert.equal(existsSync(new URL('../src/hooks/useResource.ts', import.meta.url)), true, 'useResource.ts deve existir')
assert.equal(existsSync(new URL('../src/lib/queryClient.ts', import.meta.url)), true, 'queryClient.ts deve existir')
assert.equal(existsSync(new URL('../src/lib/queryKeys.ts', import.meta.url)), true, 'queryKeys.ts deve existir')

const operationalPagesSource = read('src/pages/OperationalPages.jsx')
assert.match(
  operationalPagesSource,
  /from '@tanstack\/react-table'/,
  'TanStack Table deve ser usado apenas no fluxo operacional escolhido',
)
assert.match(operationalPagesSource, /function ReportsSummaryTable/, 'Tabela de relatorios deve ter componente dedicado')
assert.match(operationalPagesSource, /\buseReactTable\(\{/, 'Tabela de relatorios deve usar useReactTable')
assert.match(operationalPagesSource, /\bgetCoreRowModel\(\)/, 'Tabela deve usar getCoreRowModel')
assert.match(operationalPagesSource, /\bflexRender\(/, 'Tabela deve renderizar colunas com flexRender')

const reportsPageSource = operationalPagesSource.match(/export function OperationalReportsPage\(\) \{[\s\S]*?\n\}\r?\n\r?\nfunction ReportsSummaryTable/)?.[0] || ''
assert.match(reportsPageSource, /useQuery\(\{/, 'Relatorios deve manter TanStack Query existente')
assert.match(reportsPageSource, /queryFn:\s*\(\) => api\(`portal\/reports\.php\$\{queryString\(filters\)\}`\)/, 'Relatorios deve continuar usando api()')
assert.match(reportsPageSource, /href=\{apiUrl\(`portal\/reports\.php\$\{queryString\(\{ \.\.\.filters, format: 'csv' \}\)\}`\)\}/, 'Export CSV deve continuar por URL existente')
assert.doesNotMatch(reportsPageSource, /post\(|postForm\(|useMutation\(/, 'Relatorios nao deve migrar mutacoes')

const sourceFiles = walk(srcRoot).filter((pathUrl) => /\.(jsx?|tsx?|ts)$/.test(pathUrl.pathname))
const sourceText = sourceFiles.map((pathUrl) => readFileSync(pathUrl, 'utf8')).join('\n')

const tableImportOccurrences = (sourceText.match(/@tanstack\/react-table/g) || []).length
assert.equal(tableImportOccurrences, 1, 'TanStack Table deve aparecer em apenas um arquivo de runtime')

const useReactTableOccurrences = (sourceText.match(/\buseReactTable\(/g) || []).length
assert.equal(useReactTableOccurrences, 1, 'Apenas uma tabela deve usar useReactTable nesta fase')

assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutacao deve ser migrada para TanStack Query')
assert.doesNotMatch(read('src/hooks/useLivePauses.js'), /@tanstack\/react-query|@tanstack\/react-table|useQuery|useMutation|useReactTable/, 'useLivePauses nao deve ser alterado')
assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

for (const pathUrl of sourceFiles) {
  const normalized = pathUrl.pathname.replaceAll('\\', '/')
  if (normalized.endsWith('/src/lib/api.ts')) continue
  assert.doesNotMatch(readFileSync(pathUrl, 'utf8'), /\bfetch\(/, `Fetch direto fora de api.ts nao permitido: ${normalized}`)
}

const runtimeTsxFiles = sourceFiles.filter((pathUrl) => pathUrl.pathname.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado nesta fase')

const pageTsFiles = walk(new URL('../src/pages/', import.meta.url)).filter((pathUrl) => /\.(tsx?|ts)$/.test(pathUrl.pathname))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript nesta fase')

console.log('Table frontend QA OK')
