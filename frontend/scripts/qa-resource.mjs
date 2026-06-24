import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const srcRoot = new URL('../src/', import.meta.url)
const hookTsUrl = new URL('../src/hooks/useResource.ts', import.meta.url)
const hookJsUrl = new URL('../src/hooks/useResource.js', import.meta.url)
const livePausesUrl = new URL('../src/hooks/useLivePauses.js', import.meta.url)

function walk(dirUrl) {
  return readdirSync(dirUrl).flatMap((entry) => {
    const path = join(fileURLToPath(dirUrl), entry)
    if (statSync(path).isDirectory()) return walk(new URL(`${entry}/`, dirUrl))
    return path
  })
}

assert.equal(existsSync(hookTsUrl), true, 'src/hooks/useResource.ts deve existir')
assert.equal(existsSync(hookJsUrl), false, 'src/hooks/useResource.js deve ter sido migrado')

const hookSource = readFileSync(hookTsUrl, 'utf8')
assert.match(hookSource, /export function useResource/, 'useResource deve continuar exportado')
assert.match(hookSource, /import \{ api \} from '\.\.\/lib\/api'/, 'useResource deve usar api() como transporte')
assert.doesNotMatch(hookSource, /\bfetch\(/, 'useResource nao deve usar fetch direto')
assert.match(hookSource, /enabled = true/, 'useResource deve preservar enabled')
assert.match(hookSource, /initialData = null/, 'useResource deve preservar initialData')
assert.match(hookSource, /intervalMs = 0/, 'useResource deve preservar intervalMs')
assert.match(hookSource, /pauseWhenHidden = true/, 'useResource deve preservar pauseWhenHidden')
assert.match(hookSource, /const refresh = useCallback\(async \(\) => \{/, 'useResource deve preservar refresh')
assert.match(hookSource, /requestRef\.current !== requestId/, 'useResource deve preservar controle de request obsoleto')
assert.match(hookSource, /window\.setInterval/, 'useResource deve preservar polling por intervalo')
assert.match(hookSource, /document\.addEventListener\('visibilitychange'/, 'useResource deve preservar visibilitychange')
assert.match(hookSource, /window\.clearInterval/, 'useResource deve limpar intervalo')
assert.match(hookSource, /document\.removeEventListener\('visibilitychange'/, 'useResource deve remover listener')
assert.match(hookSource, /return \{ data, loading, error, refresh, setData \}/, 'useResource deve preservar shape de retorno')

const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')

assert.doesNotMatch(sourceText, /from ['"][^'"]*useResource\.js['"]/, 'Nenhum import deve apontar para useResource.js')
assert.doesNotMatch(sourceText, /import\([^)]*useResource\.js['"]\)/, 'Nenhum import dinamico deve apontar para useResource.js')
assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')
assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutacao deve ser migrada para TanStack Query nesta fase')

const livePausesSource = readFileSync(livePausesUrl, 'utf8')
assert.doesNotMatch(livePausesSource, /@tanstack\/react-query|useQuery|useMutation/, 'useLivePauses nao deve ser alterado para TanStack Query')

assert.equal(existsSync(new URL('../src/lib/api.ts', import.meta.url)), true, 'api.ts deve continuar existindo')
assert.equal(existsSync(new URL('../src/lib/queryClient.ts', import.meta.url)), true, 'queryClient.ts deve continuar existindo')
assert.equal(existsSync(new URL('../src/lib/queryKeys.ts', import.meta.url)), true, 'queryKeys.ts deve continuar existindo')

for (const path of sourceFiles) {
  const normalized = path.replaceAll('\\', '/')
  if (normalized.endsWith('/src/lib/api.ts')) continue
  assert.doesNotMatch(readFileSync(path, 'utf8'), /\bfetch\(/, `Fetch direto fora de api.ts nao permitido: ${normalized}`)
}

const runtimeTsxFiles = sourceFiles.filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado nesta fase')

const pageTsFiles = walk(new URL('../src/pages/', import.meta.url)).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript nesta fase')

const packageJson = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'))
const installedDependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
}

assert.equal(typeof packageJson.dependencies?.['@tanstack/react-query'], 'string', '@tanstack/react-query deve permanecer como unica dependencia autorizada da Fase 10')
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

console.log('Resource hook QA OK')
