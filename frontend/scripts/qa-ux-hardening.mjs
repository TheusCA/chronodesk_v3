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
const boundarySource = read('src/components/RouteErrorBoundary.jsx')
const loginSource = read('src/pages/LoginPage.jsx')
const layoutSource = read('src/components/PortalLayout.jsx')
const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')

assert.equal(packageJson.scripts['qa:ux-hardening'], 'node scripts/qa-ux-hardening.mjs', 'Script qa:ux-hardening deve estar registrado')
assert.equal(existsSync(join(srcRoot, 'components', 'RouteErrorBoundary.jsx')), true, 'RouteErrorBoundary deve existir')

assert.match(boundarySource, /export class RouteErrorBoundary extends Component/, 'Error Boundary deve ser class-based')
assert.match(boundarySource, /static getDerivedStateFromError\(\)/, 'Error Boundary deve capturar erros de renderizacao')
assert.match(boundarySource, /componentDidUpdate\(previousProps\)/, 'Error Boundary deve resetar por rota')
assert.match(boundarySource, /window\.location\.reload\(\)/, 'Botao de retry deve recarregar a pagina')
assert.match(boundarySource, /Nao foi possivel carregar este modulo/, 'Fallback deve ter mensagem profissional')
assert.match(boundarySource, /Tentar novamente/, 'Fallback deve ter acao de retry')
assert.match(boundarySource, /role="alert"/, 'Fallback deve ser anunciado como alerta')
assert.doesNotMatch(boundarySource, /console\.|fetch\(|post\(|api\(|stack|componentStack/, 'Boundary nao deve logar, chamar rede ou expor stack')

assert.match(appSource, /import \{ RouteErrorBoundary \} from '\.\/components\/RouteErrorBoundary'/, 'App deve importar RouteErrorBoundary')
assert.match(appSource, /<RouteErrorBoundary resetKey=\{router\.path\}>[\s\S]*<Suspense fallback=\{<LoadingState label="Carregando/, 'Conteudo lazy deve estar dentro do Error Boundary e Suspense')
assert.match(appSource, /<\/Suspense>[\s\S]*<\/RouteErrorBoundary>/, 'Error Boundary deve envolver o Suspense')
assert.match(appSource, /const lazyPage = \(loader, exportName\) => lazy\(/, 'Lazy loading da Fase 15 deve permanecer')

const creditText = 'Desenvolvido por Matheus Camargo'
assert.match(sourceText, new RegExp(creditText), 'Credito do desenvolvedor deve existir no frontend')
assert.match(loginSource, new RegExp(creditText), 'Credito deve aparecer na tela de login')
assert.match(layoutSource, new RegExp(creditText), 'Credito deve aparecer em area autenticada persistente')
assert.match(layoutSource, /title=\{compact \? 'Desenvolvido por Matheus Camargo'/, 'Sidebar compacta deve preservar identificacao por title')

for (const forbidden of [
  'dangerouslySetInnerHTML',
  'innerHTML',
  'localStorage',
]) {
  assert.doesNotMatch(sourceText, new RegExp(forbidden), `${forbidden} nao deve aparecer em frontend/src`)
}

for (const allowedDependency of [
  '@tanstack/react-query',
  '@tanstack/react-table',
  'react-hook-form',
  'zod',
]) {
  assert.equal(typeof packageJson.dependencies?.[allowedDependency], 'string', `${allowedDependency} deve permanecer instalado`)
}

for (const blockedDependency of [
  '@loadable/component',
  '@hookform/resolvers',
  'formik',
  'yup',
  'joi',
  '@playwright/test',
  'cypress',
  'sentry',
  'logrocket',
]) {
  assert.equal(packageJson.dependencies?.[blockedDependency], undefined, `Dependencia proibida instalada: ${blockedDependency}`)
  assert.equal(packageJson.devDependencies?.[blockedDependency], undefined, `DevDependency proibida instalada: ${blockedDependency}`)
  assert.doesNotMatch(packageLock, new RegExp(`"node_modules/${blockedDependency.replace('/', '\\/')}"`, 'i'), `Dependencia proibida no lock: ${blockedDependency}`)
}

for (const protectedPath of [
  'frontend/src/hooks/useLivePauses.js',
  'frontend/src/hooks/useResource.ts',
  'frontend/src/lib/api.ts',
  'frontend/src/lib/queryClient.ts',
  'frontend/src/lib/queryKeys.ts',
  'frontend/src/lib/operational.ts',
]) {
  assert.equal(execFileSync('git', ['diff', '--name-only', '--', protectedPath], { cwd: projectRoot, encoding: 'utf8' }).trim(), '', `${protectedPath} nao deve ser alterado`)
}

assert.doesNotMatch(sourceText, /\buseMutation\(/, 'Nenhuma mutation deve ser criada')

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

console.log('UX hardening QA OK')
