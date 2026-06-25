import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const srcRoot = new URL('../src/', import.meta.url)
const apiTsUrl = new URL('../src/lib/api.ts', import.meta.url)
const apiJsUrl = new URL('../src/lib/api.js', import.meta.url)

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

assert.equal(existsSync(apiTsUrl), true, 'src/lib/api.ts deve existir')
assert.equal(existsSync(apiJsUrl), false, 'src/lib/api.js deve ter sido migrado')

const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?|ts)$/.test(path))
const sourceText = sourceFiles.map((path) => readFileSync(path, 'utf8')).join('\n')
assert.doesNotMatch(sourceText, /from ['"][^'"]*api\.js['"]/, 'Nenhum runtime deve importar api.js explicitamente')
assert.doesNotMatch(sourceText, /import\([^)]*api\.js['"]\)/, 'Nenhum import dinamico deve apontar para api.js')
assert.doesNotMatch(sourceText, /frontend\/poc|\.example\.tsx?|from ['"][^'"]*\/poc/, 'POC deve continuar fora do runtime')

const runtimeTsxFiles = sourceFiles.filter((path) => path.endsWith('.tsx'))
assert.deepEqual(runtimeTsxFiles, [], 'Nenhum TSX de runtime deve ser criado nesta fase')

const pageTsFiles = walk(new URL('../src/pages/', import.meta.url)).filter((path) => /\.(tsx?|ts)$/.test(path))
assert.deepEqual(pageTsFiles, [], 'Nenhuma pagina deve ser migrada para TypeScript nesta fase')

const apiSource = readFileSync(apiTsUrl, 'utf8')
for (const expectedExport of [
  'setCsrfToken',
  'apiUrl',
  'api',
  'post',
  'postForm',
]) {
  assert.match(apiSource, new RegExp(`export .*\\b${expectedExport}\\b`), `Export preservado: ${expectedExport}`)
}
assert.match(apiSource, /credentials:\s*'same-origin'/, 'Fetch deve preservar credentials same-origin')
assert.match(apiSource, /headers\.set\('X-CSRF-Token', csrfToken\)/, 'CSRF deve continuar sendo enviado em metodos mutaveis')
assert.match(apiSource, /headers\.set\('Content-Type', 'application\/json'\)/, 'JSON deve manter Content-Type application/json')
assert.match(apiSource, /!\(options\.body instanceof FormData\)/, 'FormData nao deve receber Content-Type manual')
assert.match(apiSource, /chronodesk:unauthorized/, 'Evento de unauthorized deve ser preservado')

const {
  api,
  apiUrl,
  post,
  postForm,
  setCsrfToken,
} = await importTypeScriptModule(apiTsUrl)

assert.equal(apiUrl('../api/portal/status.php'), '/api/portal/status.php')
assert.equal(apiUrl('/api/session.php'), '/api/session.php')
assert.equal(apiUrl('/portal/schedules.php'), '/api/portal/schedules.php')

const fetchCalls = []
const dispatchedEvents = []
globalThis.window = {
  dispatchEvent(event) {
    dispatchedEvents.push(event.type)
    return true
  },
}
globalThis.fetch = async (url, init = {}) => {
  fetchCalls.push({ url, init })
  if (String(url).includes('unauthorized.php')) {
    return new Response(JSON.stringify({ mensagem: 'Sessao expirada.' }), {
      status: 401,
      headers: { 'content-type': 'application/json' },
    })
  }
  if (String(url).includes('session.php')) {
    return new Response(JSON.stringify({ mensagem: 'Sessao expirada.' }), {
      status: 401,
      headers: { 'content-type': 'application/json' },
    })
  }
  return new Response(JSON.stringify({ sucesso: true }), {
    status: 200,
    headers: { 'content-type': 'application/json' },
  })
}

setCsrfToken('qa-csrf-token')
await api('status.php')
const getCall = fetchCalls.at(-1)
assert.equal(getCall.url, '/api/status.php')
assert.equal(getCall.init.credentials, 'same-origin')
assert.equal(getCall.init.method, 'GET')
assert.equal(getCall.init.headers.has('X-CSRF-Token'), false)
assert.equal(getCall.init.headers.has('Content-Type'), false)

await post('portal/schedules.php', { action: 'rule', rule_type: 'even_days' })
const postCall = fetchCalls.at(-1)
assert.equal(postCall.url, '/api/portal/schedules.php')
assert.equal(postCall.init.credentials, 'same-origin')
assert.equal(postCall.init.method, 'POST')
assert.equal(postCall.init.headers.get('X-CSRF-Token'), 'qa-csrf-token')
assert.equal(postCall.init.headers.get('Content-Type'), 'application/json')
assert.equal(postCall.init.body, JSON.stringify({ action: 'rule', rule_type: 'even_days' }))

const formData = new FormData()
formData.set('arquivo', 'fixture')
await postForm('portal/documents.php', formData)
const formCall = fetchCalls.at(-1)
assert.equal(formCall.init.method, 'POST')
assert.equal(formCall.init.headers.get('X-CSRF-Token'), 'qa-csrf-token')
assert.equal(formCall.init.headers.has('Content-Type'), false)
assert.equal(formCall.init.body, formData)

await assert.rejects(
  () => api('unauthorized.php'),
  (error) => {
    assert.equal(error instanceof Error, true)
    assert.equal(error.status, 401)
    assert.equal(error.message, 'Sessao expirada.')
    return true
  },
)
assert.deepEqual(dispatchedEvents, ['chronodesk:unauthorized'])

await assert.rejects(() => api('session.php'))
assert.deepEqual(dispatchedEvents, ['chronodesk:unauthorized'], 'session.php 401 nao deve disparar evento global')

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

console.log('API frontend QA OK')
