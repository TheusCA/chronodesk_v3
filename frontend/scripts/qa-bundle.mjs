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
const appSource = read('src/App.jsx')
const viteSource = read('vite.config.js')
const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')

assert.equal(packageJson.scripts['qa:bundle'], 'node scripts/qa-bundle.mjs', 'Script qa:bundle deve estar registrado')

for (const dependency of [
  '@tanstack/react-query',
  '@tanstack/react-table',
  'react-hook-form',
  'zod',
]) {
  assert.equal(typeof packageJson.dependencies?.[dependency], 'string', `${dependency} deve permanecer instalado`)
}

for (const blockedDependency of [
  '@loadable/component',
  '@hookform/resolvers',
  'formik',
  'yup',
  'joi',
  '@playwright/test',
  'cypress',
]) {
  assert.equal(packageJson.dependencies?.[blockedDependency], undefined, `Dependencia proibida instalada: ${blockedDependency}`)
  assert.equal(packageJson.devDependencies?.[blockedDependency], undefined, `DevDependency proibida instalada: ${blockedDependency}`)
  assert.doesNotMatch(packageLock, new RegExp(`"node_modules/${blockedDependency.replace('/', '\\/')}"`), `Dependencia proibida no lock: ${blockedDependency}`)
}

assert.match(appSource, /import \{ lazy, Suspense,/, 'App deve importar lazy e Suspense do React')
assert.match(appSource, /const lazyPage = \(loader, exportName\) => lazy\(/, 'App deve usar helper lazyPage')
assert.match(appSource, /<Suspense fallback=\{<LoadingState label="Carregando/, 'Fallback do Suspense deve usar LoadingState')
assert.match(appSource, /import \{ LoadingState \} from '\.\/components\/ui\/States'/, 'Fallback deve reutilizar LoadingState existente')

const lazyPageOccurrences = (appSource.match(/lazyPage\(\(\) => import\('\.\/pages\//g) || []).length
assert.ok(lazyPageOccurrences >= 10, 'Lazy loading deve ser aplicado em multiplas paginas principais')

for (const staticImport of [
  "import { AdminPage } from './pages/AdminPage'",
  "import { DashboardPage } from './pages/DashboardPage'",
  "import { DocumentsPage } from './pages/DocumentsPage'",
  "import { CriticalIncidentsPage } from './pages/CriticalIncidentsPage'",
  "import { CalendarPage } from './pages/CalendarPage'",
  "import { ShiftSchedulesPage } from './pages/ShiftSchedulesPage'",
  "import { MetricasPage } from './pages/MetricasPage'",
  "import { PaMapPage } from './pages/PaMapPage'",
  "import { PausasPage } from './pages/PausasPage'",
]) {
  assert.doesNotMatch(appSource, new RegExp(staticImport.replaceAll(/[.*+?^${}()|[\]\\]/g, '\\$&')), `Import estatico removido: ${staticImport}`)
}

assert.doesNotMatch(viteSource, /chunkSizeWarningLimit/, 'Nao deve mascarar warning com chunkSizeWarningLimit')
assert.doesNotMatch(viteSource, /manualChunks|rollupOptions|rolldownOptions/, 'Fase 15 deve priorizar code splitting nativo por import dinamico')

assert.equal(existsSync(join(srcRoot, 'hooks', 'useResource.ts')), true, 'useResource.ts deve continuar existindo')
assert.equal(existsSync(join(srcRoot, 'lib', 'api.ts')), true, 'api.ts deve continuar existindo')
assert.equal(existsSync(join(srcRoot, 'lib', 'queryClient.ts')), true, 'queryClient.ts deve continuar existindo')
assert.equal(existsSync(join(srcRoot, 'lib', 'queryKeys.ts')), true, 'queryKeys.ts deve continuar existindo')

// Trava de escopo herdada da Fase 17: o transporte e os hooks de dados nao
// podiam ser tocados durante aquele trabalho de UX.
// 'frontend/src/lib/api.ts' foi REMOVIDO desta lista no Lote 2 (2026-09-28),
// que alterou api.ts de forma aprovada para unificar a mensagem de 403.
// O contrato de api.ts segue coberto por qa-api.
for (const protectedPath of [
  'frontend/src/hooks/useLivePauses.js',
  'frontend/src/hooks/useResource.ts',
  'frontend/src/lib/queryClient.ts',
  'frontend/src/lib/queryKeys.ts',
  'frontend/src/lib/operational.ts',
]) {
  assert.equal(execFileSync('git', ['diff', '--name-only', '--', protectedPath], { cwd: projectRoot, encoding: 'utf8' }).trim(), '', `${protectedPath} nao deve ser alterado`)
}

assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutation deve ser criada')
assert.doesNotMatch(appSource, /\bpost\([^)]*portal\/reports|method:\s*['"]POST['"]/, 'Nenhum POST novo deve ser criado no App')

for (const path of sourceFiles) {
  const normalized = relative(frontendRoot, path).replaceAll('\\', '/')
  if (normalized === 'src/lib/api.ts') continue
  assert.doesNotMatch(readFileSync(path, 'utf8'), /\bfetch\(/, `Fetch direto fora de api.ts nao permitido: ${normalized}`)
}

const runtimeTsxFiles = sourceFiles.filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado')

const pageTsFiles = walk(pagesRoot).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript')

assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

// [R1, D-R1-2] Orçamento do build (../app, gerado pelo "npm run build", que a
// bateria roda antes dos qa:*). Linha de base do JS de entrada: 201 KB no
// fdefa83 (docs/design/PLANO_LOTES_R.md). KB aqui é 1000 bytes, como no Vite.
const BUDGET = { entryJs: Math.round(201_000 * 1.05), css: 70_000, fonts: 100_000 }
const appRoot = join(projectRoot, 'app')
assert.ok(existsSync(join(appRoot, 'index.html')), 'Orçamento: falta o build em ../app (rode npm run build antes)')
const appIndex = readFileSync(join(appRoot, 'index.html'), 'utf8')
const entryJs = appIndex.match(/<script type="module" crossorigin src="\/app\/assets\/([^"]+\.js)"><\/script>/)?.[1]
assert.ok(entryJs, 'Orçamento: index.html do build com o JS de entrada')
const assets = readdirSync(join(appRoot, 'assets'))
const sizeOf = (name) => statSync(join(appRoot, 'assets', name)).size
const sumOf = (pattern) => assets.filter((name) => pattern.test(name)).reduce((total, name) => total + sizeOf(name), 0)
const measured = { entryJs: sizeOf(entryJs), css: sumOf(/\.css$/), fonts: sumOf(/\.woff2$/) }
for (const [key, limit] of Object.entries(BUDGET)) {
  assert.ok(measured[key] <= limit, `Orçamento do build: ${key} com ${measured[key]} bytes (limite ${limit})`)
}
assert.ok(measured.fonts > 0, 'Orçamento: as fontes do portal estão no build')
assert.deepEqual(assets.filter((name) => /\.(woff|ttf|otf|eot)$/.test(name)), [], 'Fontes só em woff2')

console.log(`Bundle frontend QA OK (JS de entrada ${measured.entryJs} de ${BUDGET.entryJs} bytes, CSS ${measured.css} de ${BUDGET.css}, fontes ${measured.fonts} de ${BUDGET.fonts})`)
