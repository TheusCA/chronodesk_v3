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
// [9.17, D-CHG-26] O CSS da rota do Controle de CHG (src/pages/chg/chg.css,
// importado só pela página) sai num arquivo à parte, com limite próprio; o
// CSS do resto do portal continua com o limite de 70 KB.
const chgCss = assets.filter((name) => /^ChgPage-[\w-]+\.css$/.test(name))
assert.equal(chgCss.length, 1, 'Orçamento: o CSS do Controle de CHG num arquivo só, da rota')
// [D-ESC-14] O CSS da Escala do Crítico (src/pages/criticalGrid/criticalGrid.css,
// importado pela página e pela amostra de cor carregada sob demanda) também
// sai num arquivo à parte, com limite próprio.
const gridCss = assets.filter((name) => /^criticalGrid-[\w-]+\.css$/.test(name))
assert.equal(gridCss.length, 1, 'Orçamento: o CSS da Escala do Crítico num arquivo só')
const measured = {
  entryJs: sizeOf(entryJs),
  css: assets.filter((name) => /\.css$/.test(name) && !chgCss.includes(name) && !gridCss.includes(name)).reduce((total, name) => total + sizeOf(name), 0),
  fonts: sumOf(/\.woff2$/),
  chgCss: sizeOf(chgCss[0]),
  gridCss: sizeOf(gridCss[0]),
}
for (const [key, limit] of Object.entries({ ...BUDGET, chgCss: 25_000, gridCss: 15_000 })) {
  assert.ok(measured[key] <= limit, `Orçamento do build: ${key} com ${measured[key]} bytes (limite ${limit})`)
}
assert.doesNotMatch(readFileSync(join(appRoot, 'assets', entryJs), 'utf8'), /chg-kpi|chg-board/, 'Orçamento: as classes do Controle de CHG fora do JS de entrada')
assert.doesNotMatch(readFileSync(join(appRoot, 'assets', entryJs), 'utf8'), /esc-tone-|esc-grid/, 'Orçamento: as classes da Escala do Crítico fora do JS de entrada')
assert.ok(measured.fonts > 0, 'Orçamento: as fontes do portal estão no build')
assert.deepEqual(assets.filter((name) => /\.(woff|ttf|otf|eot)$/.test(name)), [], 'Fontes só em woff2')

// [Marca] Os quatro ícones do public/ no build, com os links ?v=3 na ordem
// pedida; o sdk-icon.png (retirado no R4d) e o icon-512.png (só fonte, em
// docs/design/marca) fora do build.
const ICONS = ['favicon.ico', 'favicon.svg', 'favicon.png', 'apple-touch-icon.png']
for (const icon of ICONS) {
  assert.ok(existsSync(join(appRoot, icon)) && statSync(join(appRoot, icon)).size > 0, `Marca: ${icon} no build`)
}
assert.deepEqual(['sdk-icon.png', 'icon-512.png', 'favicon-16.svg'].filter((name) => existsSync(join(appRoot, name))), [], 'Marca: sdk-icon.png, icon-512.png e favicon-16.svg fora do build')
const iconLinks = [...appIndex.matchAll(/<link rel="(icon|apple-touch-icon)"[^>]*>/g)].map((match) => match[0])
assert.deepEqual(iconLinks, [
  '<link rel="icon" href="/app/favicon.ico?v=3" sizes="32x32" />',
  '<link rel="icon" href="/app/favicon.svg?v=3" type="image/svg+xml" />',
  '<link rel="apple-touch-icon" href="/app/apple-touch-icon.png?v=3" />',
], 'Marca: os links do ícone no index.html do build, nesta ordem, com ?v=3 (ícone B, 2026-11-23)')
// As páginas PHP que ainda servem HTML apontam para os mesmos ícones (a 503 é texto puro).
for (const page of ['index.php', 'login.php', 'admin_login.php', 'admin.php', 'metricas.php']) {
  const html = readFileSync(join(projectRoot, page), 'utf8')
  assert.deepEqual([html.includes("/app/favicon.ico?v=3'); ?>\" sizes=\"32x32\">"), html.includes("/app/favicon.svg?v=3'); ?>\" type=\"image/svg+xml\">"), html.includes("/app/apple-touch-icon.png?v=3'); ?>\">"), html.includes('favicon.png'), html.includes('?v=2')], [true, true, true, false, false], `Marca: ${page} com os ícones novos (?v=3)`)
}
const svgIcon = readFileSync(join(appRoot, 'favicon.svg'), 'utf8')
assert.doesNotMatch(svgIcon, /<script|href=|xlink:|@import|url\(|on[a-z]+=/i, 'Marca: favicon.svg sem script, link externo, import ou evento')
const ico = readFileSync(join(appRoot, 'favicon.ico'))
assert.deepEqual([ico.readUInt16LE(0), ico.readUInt16LE(2), ico.readUInt16LE(4), [0, 1, 2].map((i) => ico[6 + 16 * i])], [0, 1, 3, [16, 32, 48]], 'Marca: favicon.ico com 16, 32 e 48 px')
// [Marca B, 2026-11-23] O favicon não depende de tema: cores fixas, sem
// <style> nem prefers-color-scheme; a placa, as chaves e a pausa da opção B.
assert.deepEqual([/prefers-color-scheme|<style|<metadata/i.test(svgIcon), (svgIcon.match(/<path /g) || []).length, (svgIcon.match(/<rect [^>]*fill="#F2C27A"/g) || []).length, svgIcon.includes('fill="#1C1B19"'), svgIcon.includes('stroke="#F4F3EF"')], [false, 2, 2, true, true], 'Marca: favicon.svg da opção B, com cores fixas (sem tema, sem estilo nem metadados)')
const pngInfo = (name) => { const png = readFileSync(join(appRoot, name)); return [png.readUInt32BE(16), png.readUInt32BE(20), png[25]] }
assert.deepEqual([pngInfo('favicon.png'), pngInfo('apple-touch-icon.png')], [[64, 64, 6], [180, 180, 2]], 'Marca: favicon.png 64 px com transparência; apple-touch-icon.png 180 px opaco (o iOS arredonda)')
assert.deepEqual([0, 1, 2].map((i) => ico.readUInt32BE(ico.readUInt32LE(6 + 16 * i + 12))), [0x89504e47, 0x89504e47, 0x89504e47], 'Marca: as três entradas do favicon.ico em PNG')

console.log(`Bundle frontend QA OK (JS de entrada ${measured.entryJs} de ${BUDGET.entryJs} bytes, CSS ${measured.css} de ${BUDGET.css}, CSS do CHG ${measured.chgCss} de 25000, CSS da Escala do Crítico ${measured.gridCss} de 15000, fontes ${measured.fonts} de ${BUDGET.fonts})`)
