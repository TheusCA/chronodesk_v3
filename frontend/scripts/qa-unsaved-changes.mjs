import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

// [17f, item 1] Peca reutilizavel da protecao contra perda de alteracoes
// (B4; docs/DESENHO_CHG.md, secao 6.3): bloqueios de navegacao (navigate,
// voltar e avancar, "Sair"), beforeunload so com formulario sujo, Dialog
// acessivel e ConfirmDiscardDialog. Sem formulario sujo, nada muda: a
// navegacao segue na hora, sem dialogo e sem listener.

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const srcRoot = join(frontendRoot, 'src')
const read = (relativePath) => readFileSync(join(frontendRoot, relativePath), 'utf8')
const walk = (dir) => readdirSync(dir).flatMap((entry) => { const path = join(dir, entry); return statSync(path).isDirectory() ? walk(path) : [path] })

const packageJson = JSON.parse(read('package.json'))
assert.equal(packageJson.scripts['qa:unsaved-changes'], 'node scripts/qa-unsaved-changes.mjs', 'Script qa:unsaved-changes registrado')
assert.equal(Object.keys(packageJson.scripts).filter((name) => name.startsWith('qa:')).length, 19, 'Bateria com 19 qa:*')

// Navegador simulado: location e history que se atualizam no pushState, e
// listeners contados por tipo.
const listeners = new Map()
function setUrl(url) {
  const parsed = new URL(url, 'http://qa.local')
  Object.assign(globalThis.window.location, { href: parsed.href, pathname: parsed.pathname, search: parsed.search })
}
const historyCalls = []
globalThis.window = {
  location: { href: 'http://qa.local/app/dashboard', pathname: '/app/dashboard', search: '' },
  history: {
    pushState: (_state, _title, url) => { historyCalls.push(['push', new URL(url, 'http://qa.local').href]); setUrl(url) },
    replaceState: (_state, _title, url) => { historyCalls.push(['replace', new URL(url, 'http://qa.local').href]); setUrl(url) },
  },
  addEventListener: (type, fn) => { listeners.set(type, [...(listeners.get(type) || []), fn]) },
  removeEventListener: (type, fn) => { listeners.set(type, (listeners.get(type) || []).filter((item) => item !== fn)) },
}
const listenerCount = (type) => (listeners.get(type) || []).length

const srcUrl = pathToFileURL(srcRoot).href + '/'
registerHooks({
  resolve(specifier, context, nextResolve) {
    if ((specifier.startsWith('./') || specifier.startsWith('../')) && !/\.[cm]?[jt]sx?$/u.test(specifier) && context.parentURL?.startsWith(srcUrl)) {
      for (const extension of ['.ts', '.tsx', '.js', '.jsx']) {
        const candidate = new URL(specifier + extension, context.parentURL)
        if (existsSync(fileURLToPath(candidate))) return nextResolve(candidate.href, context)
      }
    }
    return nextResolve(specifier, context)
  },
  load(url, context, nextLoad) {
    const path = url.split('?')[0]
    if (path.startsWith(srcUrl) && /\.(jsx|tsx|ts)$/u.test(path)) {
      const output = ts.transpileModule(readFileSync(fileURLToPath(path), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})
const importSrc = (relativePath) => import(pathToFileURL(join(srcRoot, relativePath)).href)
const { guardLeave, guardNavigation, navigationBlockerCount, registerNavigationBlocker } = await importSrc('lib/navigationGuard.ts')
const { createPopStateHandler, useRouter } = await importSrc('lib/router.js')
const { attachUnsavedChangesGuard, onBeforeUnloadWhileDirty } = await importSrc('hooks/useUnsavedChangesGuard.ts')
const { dialogKeyDown, isTopDialog, openDialogCount, popDialog, pushDialog } = await importSrc('lib/dialogFocus.ts')
const { Dialog } = await importSrc('components/ui/Dialog.jsx')
const { ConfirmDiscardDialog, DISCARD_MESSAGE } = await importSrc('components/ui/ConfirmDiscardDialog.jsx')
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')

// ---------------------------------------------------------------------------
// 1. Registro de bloqueios.
// ---------------------------------------------------------------------------
{
  let ran = 0
  assert.equal(navigationBlockerCount(), 0, 'Comeca sem bloqueio')
  assert.equal(guardNavigation(() => { ran++ }), true, 'Sem bloqueio: segue')
  assert.equal(ran, 1, 'Sem bloqueio: a acao roda na hora, uma vez')
  const asked = []
  const first = registerNavigationBlocker({ ask: (proceed) => asked.push(['first', proceed]) })
  const second = registerNavigationBlocker({ ask: (proceed) => asked.push(['second', proceed]) })
  assert.equal(navigationBlockerCount(), 2, 'Dois formularios sujos, dois bloqueios')
  assert.equal(guardNavigation(() => { ran++ }), false, 'Com bloqueio: espera')
  assert.equal(ran, 1, 'Com bloqueio: a acao nao roda antes da resposta')
  assert.deepEqual(asked.map(([name]) => name), ['second'], 'Um dialogo so, do bloqueio mais recente')
  asked[0][1]()
  assert.equal(ran, 2, '"Descartar" roda a acao pedida')
  second()
  first()
  assert.equal(navigationBlockerCount(), 0, 'Desregistrar tira os bloqueios')
  let logout = 0
  guardLeave(() => { logout++ })
  assert.equal(logout, 1, '"Sair" sem bloqueio: sai na hora')
  const leaveAsked = []
  const off = registerNavigationBlocker({ ask: (proceed) => leaveAsked.push(proceed) })
  guardLeave(() => { logout++ })
  assert.equal(logout, 1, '"Sair" com formulario sujo: espera a resposta')
  leaveAsked[0]()
  assert.equal(logout, 2, '"Sair" depois de "Descartar"')
  off()
}

// ---------------------------------------------------------------------------
// 2. Roteador: navigate (capturado de um render real) e voltar ou avancar.
// ---------------------------------------------------------------------------
{
  let router
  function Probe() { router = useRouter(); return null }
  renderToStaticMarkup(h(Probe))
  assert.equal(router.path, '/dashboard', 'Rota inicial lida do endereco')
  historyCalls.length = 0
  router.navigate('/ausencias')
  assert.deepEqual(historyCalls, [['push', 'http://qa.local/app/ausencias']], 'Sem bloqueio: navigate faz o pushState na hora, como antes')
  router.navigate('/admin?tab=funcionarios', { replace: true })
  assert.deepEqual(historyCalls.at(-1), ['replace', 'http://qa.local/app/admin?tab=funcionarios'], 'Sem bloqueio: replace e search como antes')
  const asked = []
  const off = registerNavigationBlocker({ ask: (proceed) => asked.push(proceed) })
  historyCalls.length = 0
  router.navigate('/escala-presencial')
  assert.deepEqual(historyCalls, [], 'Com formulario sujo: navigate nao muda o endereco antes da resposta')
  assert.equal(asked.length, 1, 'Com formulario sujo: navigate pede confirmacao')
  asked[0]()
  assert.deepEqual(historyCalls, [['push', 'http://qa.local/app/escala-presencial']], 'Depois de "Descartar", navega para onde foi pedido')
  off()

  // Voltar: o navegador ja trocou o endereco quando o popstate chega.
  const locations = []
  const onPopState = createPopStateHandler((location) => locations.push(location))
  historyCalls.length = 0
  setUrl('/app/ausencias')
  onPopState()
  assert.deepEqual(locations, [{ path: '/ausencias', search: '' }], 'Sem bloqueio: voltar troca a tela na hora')
  assert.deepEqual(historyCalls, [], 'Sem bloqueio: voltar nao mexe no historico')
  const backAsked = []
  const offBack = registerNavigationBlocker({ ask: (proceed) => backAsked.push(proceed) })
  setUrl('/app/horas-extras')
  onPopState()
  assert.equal(locations.length, 1, 'Com formulario sujo: voltar nao troca a tela antes da resposta')
  assert.deepEqual(historyCalls, [['push', 'http://qa.local/app/ausencias']], 'Com formulario sujo: o endereco volta para a ultima rota aceita')
  assert.equal(backAsked.length, 1, 'Com formulario sujo: voltar pede confirmacao')
  backAsked[0]()
  assert.deepEqual(historyCalls.at(-1), ['push', 'http://qa.local/app/horas-extras'], 'Depois de "Descartar", vai para o endereco do voltar')
  assert.deepEqual(locations.at(-1), { path: '/horas-extras', search: '' }, 'Depois de "Descartar", a tela troca')
  offBack()
}

// ---------------------------------------------------------------------------
// 3. beforeunload e bloqueio so enquanto sujo, sem listener sobrando.
// ---------------------------------------------------------------------------
{
  assert.equal(listenerCount('beforeunload'), 0, 'Sem formulario sujo: nenhum beforeunload')
  const detach = attachUnsavedChangesGuard(globalThis.window, () => {})
  assert.equal(listenerCount('beforeunload'), 1, 'Formulario sujo: um beforeunload')
  assert.equal(navigationBlockerCount(), 1, 'Formulario sujo: um bloqueio de navegacao')
  detach()
  assert.equal(listenerCount('beforeunload'), 0, 'Limpo ou desmontado: o beforeunload sai')
  assert.equal(navigationBlockerCount(), 0, 'Limpo ou desmontado: o bloqueio sai')
  const event = { prevented: false, returnValue: undefined, preventDefault() { this.prevented = true } }
  onBeforeUnloadWhileDirty(event)
  assert.equal(event.prevented, true, 'beforeunload: preventDefault (aviso do navegador)')
  assert.equal(event.returnValue, '', 'beforeunload: returnValue para navegadores antigos')
}

// ---------------------------------------------------------------------------
// 4. Teclado do Dialog: Esc, foco preso e pilha.
// ---------------------------------------------------------------------------
{
  const focused = []
  const item = (name) => ({ name, focus: () => focused.push(name) })
  const [a, b, c] = [item('a'), item('b'), item('c')]
  const container = (active, items = [a, b, c]) => ({ name: 'painel', focus: () => focused.push('painel'), querySelectorAll: () => items, contains: (node) => items.includes(node), ownerDocument: { activeElement: active } })
  const key = (keyName, shiftKey = false) => ({ key: keyName, shiftKey, prevented: false, stopped: false, preventDefault() { this.prevented = true }, stopPropagation() { this.stopped = true } })
  let closed = 0
  const esc = key('Escape')
  dialogKeyDown(esc, container(b), () => { closed++ })
  assert.equal(closed, 1, 'Esc pede para fechar')
  assert.equal(esc.prevented && esc.stopped, true, 'Esc nao vaza para a pagina')
  const tabLast = key('Tab')
  dialogKeyDown(tabLast, container(c))
  assert.deepEqual([focused.at(-1), tabLast.prevented], ['a', true], 'Tab no ultimo volta ao primeiro')
  const shiftFirst = key('Tab', true)
  dialogKeyDown(shiftFirst, container(a))
  assert.deepEqual([focused.at(-1), shiftFirst.prevented], ['c', true], 'Shift+Tab no primeiro vai ao ultimo')
  const tabMiddle = key('Tab')
  const before = focused.length
  dialogKeyDown(tabMiddle, container(b))
  assert.deepEqual([focused.length, tabMiddle.prevented], [before, false], 'Tab no meio segue o navegador')
  dialogKeyDown(key('Tab'), container({ name: 'fora' }))
  assert.equal(focused.at(-1), 'a', 'Foco fora do dialogo volta para dentro')
  dialogKeyDown(key('Tab'), container(null, []))
  assert.equal(focused.at(-1), 'painel', 'Sem item focavel, o foco fica no painel')
  closed = 0
  dialogKeyDown(key('Enter'), container(b), () => { closed++ })
  assert.equal(closed, 0, 'Outras teclas nao fecham')
  const form = {}
  const discard = {}
  pushDialog(form)
  pushDialog(discard)
  assert.deepEqual([isTopDialog(discard), isTopDialog(form), openDialogCount()], [true, false, 2], 'Com dois abertos, so o de cima responde')
  popDialog(discard)
  assert.deepEqual([isTopDialog(form), openDialogCount()], [true, 1], 'Fechado o de cima, o de baixo volta a responder')
  popDialog(form)
  assert.equal(openDialogCount(), 0, 'Nenhum dialogo aberto sobrando')
}

// ---------------------------------------------------------------------------
// 5. Dialog e ConfirmDiscardDialog renderizados.
// ---------------------------------------------------------------------------
{
  const markup = renderToStaticMarkup(h(Dialog, { title: 'Titulo QA', description: 'Descricao QA', onClose: () => {} }, h('button', { type: 'button' }, 'Ok')))
  assert.match(markup, /^<div class="fixed inset-0 z-\[60\][^"]*" data-dialog-backdrop="">/, 'Fundo fixo acima dos modais (z-60)')
  const titleId = markup.match(/<h2 class="[^"]*" id="([^"]+)">Titulo QA<\/h2>/)?.[1]
  const descriptionId = markup.match(/<p class="[^"]*" id="([^"]+)">Descricao QA<\/p>/)?.[1]
  assert.ok(titleId && descriptionId, 'Titulo e descricao com id')
  assert.ok(markup.includes(`aria-describedby="${descriptionId}" aria-labelledby="${titleId}" aria-modal="true"`), 'Painel ligado ao titulo e a descricao, aria-modal')
  assert.match(markup, /role="dialog" tabindex="-1">/, 'role="dialog" e o painel focavel')
  assert.doesNotMatch(renderToStaticMarkup(h(Dialog, { title: 'Sem descricao', onClose: () => {} })), /aria-describedby/, 'Sem descricao, sem aria-describedby')
  assert.equal(renderToStaticMarkup(h(Dialog, { open: false, title: 'Fechado', onClose: () => {} })), '', 'Fechado nao renderiza nada')
  assert.match(renderToStaticMarkup(h(Dialog, { role: 'alertdialog', title: 'Alerta', onClose: () => {} })), /role="alertdialog"/, 'role="alertdialog" quando pedido')

  assert.equal(DISCARD_MESSAGE, 'Há alterações não salvas. Se sair agora, elas serão perdidas.', 'Texto do pedido B4')
  assert.equal(renderToStaticMarkup(h(ConfirmDiscardDialog, { open: false, onContinue: () => {}, onDiscard: () => {} })), '', 'Fechado nao renderiza nada')
  const discard = renderToStaticMarkup(h(ConfirmDiscardDialog, { open: true, onContinue: () => {}, onDiscard: () => {} }))
  assert.match(discard, /role="alertdialog"/, 'Descartar e um alertdialog')
  assert.match(discard, />Descartar alterações\?<\/h2><p [^>]*>Há alterações não salvas\. Se sair agora, elas serão perdidas\.<\/p>/u, 'Titulo e texto do pedido')
  assert.match(discard, /<button class="btn-danger" type="button">Descartar alterações<\/button><button class="btn-primary" type="button">Continuar editando<\/button>/u, '"Continuar editando" e o botao principal; "Descartar" em vermelho')
}

// ---------------------------------------------------------------------------
// 6. Fiacao no codigo (efeitos nao rodam no render do servidor).
// ---------------------------------------------------------------------------
const dialogSource = read('src/components/ui/Dialog.jsx')
assert.match(dialogSource, /const target = initialFocusRef\?\.current \|\| panel\?\.querySelector\(FOCUSABLE_SELECTOR\) \|\| panel\s*\n\s*target\?\.focus\(\)/, 'Dialog: foco inicial no pedido, senao no primeiro focavel')
assert.match(dialogSource, /if \(isTopDialog\(token\)\) dialogKeyDown\(event, panelRef\.current, \(\) => onCloseRef\.current\?\.\(\)\)/, 'Dialog: teclado so no de cima')
assert.match(dialogSource, /return \(\) => \{\s*document\.removeEventListener\('keydown', onKeyDown\)\s*popDialog\(token\)\s*if \(previous && previous\.isConnected && typeof previous\.focus === 'function'\) previous\.focus\(\)/, 'Dialog: ao fechar, tira o listener, sai da pilha e devolve o foco')
assert.match(dialogSource, /onMouseDown=\{\(event\) => \{ if \(event\.target === event\.currentTarget\) \{ event\.preventDefault\(\); onClose\?\.\(\) \} \}\}/, 'Dialog: clique fora (no fundo) pede para fechar, sem levar o foco para o body')
const confirmSource = read('src/components/ui/ConfirmDiscardDialog.jsx')
assert.match(confirmSource, /initialFocusRef=\{continueRef\} onClose=\{onContinue\}/, 'Descartar: foco inicial e Esc ou clique fora = Continuar editando')
assert.match(confirmSource, /onClick=\{onContinue\} ref=\{continueRef\}/, 'Descartar: o foco inicial e o "Continuar editando"')
const hookSource = read('src/hooks/useUnsavedChangesGuard.ts')
assert.match(hookSource, /useEffect\(\(\) => \{\s*dirtyRef\.current = isDirty\s*if \(!isDirty\) return undefined\s*return attachUnsavedChangesGuard\(window, \(proceed\) => setPending\(\{ proceed \}\)\)\s*\}, \[isDirty\]\)/, 'Hook: bloqueio e beforeunload so enquanto sujo, desfeitos na limpeza')
assert.match(hookSource, /if \(!dirtyRef\.current\) \{\s*action\(\)\s*return\s*\}\s*setPending\(\{ proceed: action \}\)/, 'Hook: limpo sai na hora; sujo pergunta')
assert.match(hookSource, /onContinue: \(\) => setPending\(null\),\s*onDiscard: \(\) => \{\s*const next = pending\s*setPending\(null\)\s*next\?\.proceed\(\)/, 'Hook: Continuar so fecha; Descartar fecha e segue')
const routerSource = read('src/lib/router.js')
assert.match(routerSource, /const onPopState = createPopStateHandler\(setLocation\)\s*window\.addEventListener\('popstate', onPopState\)\s*return \(\) => window\.removeEventListener\('popstate', onPopState\)/, 'Roteador: um popstate, retirado na desmontagem')
const layoutSource = read('src/components/PortalLayout.jsx')
assert.match(layoutSource, /onClick=\{\(\) => guardLeave\(onLogout\)\} type="button" aria-label="Sair"/, '"Sair" passa pelo bloqueio')
const sourceFiles = walk(srcRoot).filter((path) => /\.(jsx?|tsx?)$/u.test(path))
const beforeUnloadFiles = sourceFiles.filter((path) => readFileSync(path, 'utf8').includes('beforeunload')).map((path) => path.slice(srcRoot.length + 1).replaceAll('\\', '/'))
assert.deepEqual(beforeUnloadFiles, ['hooks/useUnsavedChangesGuard.ts'], 'beforeunload so no hook (so com formulario sujo)')
const popstateFiles = sourceFiles.filter((path) => readFileSync(path, 'utf8').includes("'popstate'")).map((path) => path.slice(srcRoot.length + 1).replaceAll('\\', '/'))
assert.deepEqual(popstateFiles, ['lib/router.js'], 'popstate so no roteador')
for (const file of ['src/components/ui/Dialog.jsx', 'src/components/ui/ConfirmDiscardDialog.jsx', 'src/hooks/useUnsavedChangesGuard.ts', 'src/lib/navigationGuard.ts', 'src/lib/dialogFocus.ts', 'src/lib/router.js']) {
  const code = read(file).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
  assert.doesNotMatch(code, /window\.confirm|\bconfirm\(|\balert\(|\bprompt\(/, `${file}: sem dialogo do navegador`)
}

console.log('Unsaved changes QA OK')
