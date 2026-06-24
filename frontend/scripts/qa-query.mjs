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
  '@tanstack/react-query deve ser dependencia de runtime nesta fase',
)

for (const blockedDependency of [
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

assert.equal(existsSync(new URL('../src/lib/queryClient.ts', import.meta.url)), true, 'queryClient.ts deve existir')
assert.equal(existsSync(new URL('../src/lib/queryKeys.ts', import.meta.url)), true, 'queryKeys.ts deve existir')

const mainSource = read('src/main.jsx')
assert.match(mainSource, /QueryClientProvider/, 'App deve ser envolvido por QueryClientProvider')
assert.match(mainSource, /client=\{queryClient\}/, 'QueryClientProvider deve usar queryClient centralizado')

const queryClientSource = read('src/lib/queryClient.ts')
assert.match(queryClientSource, /new QueryClient/, 'queryClient deve instanciar QueryClient')
assert.match(queryClientSource, /retry:\s*false/, 'QueryClient nao deve fazer retry por padrao')
assert.match(queryClientSource, /refetchOnWindowFocus:\s*false/, 'QueryClient nao deve refetch em foco por padrao')
assert.match(queryClientSource, /staleTime:\s*30_000/, 'QueryClient deve usar staleTime conservador')

const queryKeysSource = read('src/lib/queryKeys.ts')
assert.match(queryKeysSource, /reports:\s*\(filters/, 'queryKeys deve centralizar chave de relatorios')
assert.match(queryKeysSource, /\['reports', filters\] as const/, 'Chave de relatorios deve incluir filtros')

const operationalPagesSource = read('src/pages/OperationalPages.jsx')
const reportsPageSource = operationalPagesSource.match(/export function OperationalReportsPage\(\) \{[\s\S]*?\n\}\r?\n\r?\nfunction ReportsSummaryTable/)?.[0] || ''
assert.match(reportsPageSource, /useQuery\(\{/, 'Apenas relatorios deve usar useQuery nesta fase')
assert.match(reportsPageSource, /queryKey:\s*queryKeys\.reports\(filters\)/, 'Relatorios deve usar query key centralizada')
assert.match(reportsPageSource, /queryFn:\s*\(\) => api\(`portal\/reports\.php\$\{queryString\(filters\)\}`\)/, 'Relatorios deve usar api() como transporte')
assert.doesNotMatch(reportsPageSource, /post\(|postForm\(|useMutation\(/, 'Relatorios nao deve migrar mutacoes nesta fase')

const useQueryOccurrences = (operationalPagesSource.match(/\buseQuery\(/g) || []).length
assert.equal(useQueryOccurrences, 1, 'Apenas um fluxo deve usar useQuery nesta fase')

const sourceFiles = walk(srcRoot).filter((pathUrl) => /\.(jsx?|tsx?|ts)$/.test(pathUrl.pathname))
const sourceText = sourceFiles.map((pathUrl) => readFileSync(pathUrl, 'utf8')).join('\n')
assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutacao deve ser migrada para React Query nesta fase')
assert.doesNotMatch(read('src/hooks/useLivePauses.js'), /@tanstack\/react-query|useQuery|useMutation/, 'useLivePauses nao deve ser alterado para React Query')
assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

for (const pathUrl of sourceFiles) {
  const relativePath = pathUrl.pathname.replaceAll('\\', '/')
  if (relativePath.endsWith('/src/lib/api.ts')) continue
  assert.doesNotMatch(readFileSync(pathUrl, 'utf8'), /\bfetch\(/, `Fetch direto fora de api.ts nao permitido: ${relativePath}`)
}

const runtimeTsxFiles = sourceFiles.filter((pathUrl) => pathUrl.pathname.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado nesta fase')

const pageTsFiles = walk(new URL('../src/pages/', import.meta.url)).filter((pathUrl) => /\.(tsx?|ts)$/.test(pathUrl.pathname))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript nesta fase')

console.log('Query frontend QA OK')
