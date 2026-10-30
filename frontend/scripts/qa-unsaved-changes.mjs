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
// Telas que usam a peca: o useResource responde com globalThis.qaResources, sem rede.
const resourceStub = 'data:text/javascript,' + encodeURIComponent(
  'export function useResource(path) { const hit = Object.entries(globalThis.qaResources ?? {}).find(([key]) => path.startsWith(key)); return { loading: false, error: null, data: hit ? hit[1] : null, refresh: async () => null, setData: () => {} } }',
)
// Estado inicial que o teste escolhe (formulario aberto), trocado no carregamento.
const INITIAL_STATE = {
  '/src/pages/CriticalIncidentsPage.jsx': [
    ['const [formItem, setFormItem] = useState(null)', 'const [formItem, setFormItem] = useState(globalThis.qaFormItem ?? null)'],
    ['const [viewItem, setViewItem] = useState(null)', 'const [viewItem, setViewItem] = useState(globalThis.qaViewItem ?? null)'],
    ['const [importOpen, setImportOpen] = useState(false)', 'const [importOpen, setImportOpen] = useState(globalThis.qaImportOpen ?? false)'],
  ],
  '/src/pages/AdminPage.jsx': [['const [editForm, setEditForm] = useState(null)', 'const [editForm, setEditForm] = useState(globalThis.qaEditForm ?? null)']],
}
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && /\/src\/pages\/[A-Za-z]+\.jsx(\?.*)?$/u.test(context.parentURL ?? '')) return { url: resourceStub, shortCircuit: true }
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
      let source = readFileSync(fileURLToPath(path), 'utf8')
      for (const [suffix, replacements] of Object.entries(INITIAL_STATE)) {
        if (!path.endsWith(suffix)) continue
        for (const [from, to] of replacements) {
          if (!source.includes(from)) throw new Error(`${suffix} sem o estado esperado pelo QA: ${from}`)
          source = source.replace(from, to)
        }
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})
const importSrc = (relativePath) => import(pathToFileURL(join(srcRoot, relativePath)).href)
const { guardLeave, guardNavigation, navigationBlockerCount, registerNavigationBlocker } = await importSrc('lib/navigationGuard.ts')
const { createPopStateHandler, useRouter } = await importSrc('lib/router.js')
const { attachUnsavedChangesGuard, onBeforeUnloadWhileDirty } = await importSrc('hooks/useUnsavedChangesGuard.ts')
const { dialogKeyDown, focusPageTitle, focusPageTitleIfLost, isTopDialog, lockPageScroll, openDialogCount, popDialog, pushDialog } = await importSrc('lib/dialogFocus.ts')
const { Dialog } = await importSrc('components/ui/Dialog.jsx')
const { ConfirmActionDialog } = await importSrc('components/ui/ConfirmActionDialog.jsx')
const { Feedback } = await importSrc('components/Feedback.jsx')
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
  const first = attachUnsavedChangesGuard(globalThis.window, () => {})
  const second = attachUnsavedChangesGuard(globalThis.window, () => {})
  const [firstListener, secondListener] = listeners.get('beforeunload')
  assert.notEqual(firstListener, secondListener, 'Dois formularios sujos: funcoes diferentes (o navegador registra a mesma funcao uma vez so)')
  second()
  assert.equal(listenerCount('beforeunload'), 1, 'Um formulario sai: o aviso de quem ainda esta sujo fica')
  first()
  assert.equal(listenerCount('beforeunload'), 0, 'Os dois saem: nenhum beforeunload')
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
  const notRendered = { name: 'arquivo', focus: () => focused.push('arquivo'), getClientRects: () => [] }
  const rendered = { name: 'botao', focus: () => focused.push('botao'), getClientRects: () => [{}] }
  const withHidden = key('Tab')
  dialogKeyDown(withHidden, container(rendered, [a, rendered, notRendered]))
  assert.deepEqual([focused.at(-1), withHidden.prevented], ['a', true], 'Elemento sem caixa na tela (input de arquivo escondido) nao conta como o ultimo: Tab no botao volta ao primeiro')
  const backHidden = key('Tab', true)
  dialogKeyDown(backHidden, container(a, [a, rendered, notRendered]))
  assert.deepEqual([focused.at(-1), backHidden.prevented], ['botao', true], 'Shift+Tab no primeiro vai ao ultimo que aparece')
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
  assert.match(markup, /class="card relative my-auto w-full max-w-lg "/, 'Painel com my-auto: centraliza quando cabe e deixa o topo alcancavel quando nao cabe')
  assert.doesNotMatch(markup, /sm:items-center/, 'Fundo sem items-center (cortaria o topo de formulario longo)')
  const header = renderToStaticMarkup(h(Dialog, { title: 'Titulo QA', eyebrow: 'Eyebrow QA', headerAction: h('button', { type: 'button' }, 'Cancelar'), titleClassName: 'text-xl', panelClassName: 'border-red-500/20', size: 'max-w-6xl', onClose: () => {} }))
  assert.match(header, /<div class="flex items-start justify-between gap-4"><div class="min-w-0"><p class="section-eyebrow">Eyebrow QA<\/p><h2 class="mt-2 text-xl font-bold text-white" id="[^"]+">Titulo QA<\/h2><\/div><button type="button">Cancelar<\/button><\/div>/, 'Cabecalho com eyebrow, titulo e acao a direita')
  assert.match(header, /class="card relative my-auto w-full max-w-6xl border-red-500\/20"/, 'Tamanho e borda do painel pelas props')
  const lead = renderToStaticMarkup(h(Dialog, { title: 'Titulo QA', headerLead: h('span', null, 'Selo QA'), titleClassName: 'mt-4 text-xl', onClose: () => {} }))
  assert.match(lead, /<div class="min-w-0"><span>Selo QA<\/span><h2 class="mt-4 text-xl font-bold text-white" id="[^"]+">Titulo QA<\/h2>/, 'headerLead acima do titulo')
  const lateral = renderToStaticMarkup(h(Dialog, { title: 'Titulo QA', placement: 'end', size: 'max-w-2xl', onClose: () => {} }))
  assert.match(lateral, /^<div class="fixed inset-0 z-\[60\] flex justify-end bg-black\/75 backdrop-blur-sm" data-dialog-backdrop="">/, 'placement="end": fundo alinhado a direita')
  assert.match(lateral, /class="relative h-full w-full max-w-2xl overflow-y-auto border-l border-white\/10 bg-slate-950 p-6 "/, 'placement="end": painel lateral na altura toda, sem o card')

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
assert.match(dialogSource, /return \(\) => \{\s*document\.removeEventListener\('keydown', onKeyDown\)\s*popDialog\(token\)\s*unlockScroll\(\)\s*(?:\/\/[^\n]*\n\s*)*const back = returnFocusRef\?\.current\?\.isConnected \? returnFocusRef\.current : previous\s*if \(back && back\.isConnected && back !== document\.body && typeof back\.focus === 'function'\) back\.focus\(\)\s*else if \(openDialogCount\(\) === 0\) focusPageTitleIfLost\(document\)/, 'Dialog: ao fechar, tira o listener, sai da pilha, destrava a janela e devolve o foco (a quem abriu, ao returnFocusRef ou, sem destino e sem outro dialogo, ao titulo da pagina)')
assert.match(dialogSource, /pushDialog\(token\)\s*const unlockScroll = lockPageScroll\(document\)/, 'Dialog: aberto, trava a rolagem da janela')
assert.match(dialogSource, /onMouseDown=\{\(event\) => \{ if \(event\.target === event\.currentTarget\) \{ event\.preventDefault\(\); onClose\?\.\(\) \} \}\}/, 'Dialog: clique fora (no fundo) pede para fechar, sem levar o foco para o body')
// [17f, Z2] No body: dentro da pagina, a margem do space-y-* do pai descia o fundo.
assert.match(dialogSource, /import \{ createPortal \} from 'react-dom'/, 'Dialog: importa o portal')
assert.match(dialogSource, /return typeof document === 'undefined' \? backdrop : createPortal\(backdrop, document\.body\)/, 'Dialog: renderiza no body')
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

// ---------------------------------------------------------------------------
// 7. "Sujo" para formularios com useState (formValuesChanged).
// ---------------------------------------------------------------------------
{
  const { formValuesChanged } = await importSrc('lib/formDirty.ts')
  const opened = { nome: 'A', total: 12, nota: null, data: '' }
  assert.equal(formValuesChanged(opened, { ...opened }), false, 'Igual ao que abriu: limpo')
  assert.equal(formValuesChanged(opened, { ...opened, total: '12' }), false, 'Numero e texto iguais: limpo (o campo devolve texto)')
  assert.equal(formValuesChanged(opened, { ...opened, nota: '' }), false, 'Nulo e vazio: limpo')
  assert.equal(formValuesChanged(opened, { ...opened, nome: 'B' }), true, 'Campo mudou: sujo')
  assert.equal(formValuesChanged(opened, { ...opened, data: '2026-10-29' }), true, 'Campo vazio preenchido: sujo')
  assert.equal(formValuesChanged(opened, { ...opened, extra: 'x' }), true, 'Campo novo preenchido: sujo')
  assert.equal(formValuesChanged(opened, { ...opened, nome: 'B' }) && formValuesChanged(opened, { ...opened, nome: 'A' }) === false, true, 'Voltar ao valor de abertura: limpo de novo')
}

// ---------------------------------------------------------------------------
// 8. Telas que usam a peca. [17f, item 2] Chamados criticos: novo e editar.
// ---------------------------------------------------------------------------
{
  const { CriticalIncidentsPage } = await importSrc('pages/CriticalIncidentsPage.jsx')
  const renderCritical = (formItem, initial = {}) => {
    globalThis.qaResources = {
      'portal/critical_incidents.php': { sucesso: true, limit: 200, truncated: false, summary: {}, items: [] },
      'portal/sdk_responsibles.php': { sucesso: true, items: [] },
    }
    globalThis.qaFormItem = formItem
    Object.assign(globalThis, initial)
    try {
      return renderToStaticMarkup(h(CriticalIncidentsPage, { session: { role: 'gestor', permissions: [], ci: { funcionario_id: 0 }, gestor: {} }, notify: () => {} }))
    } finally {
      delete globalThis.qaResources
      delete globalThis.qaFormItem
      delete globalThis.qaViewItem
      delete globalThis.qaImportOpen
    }
  }
  for (const [label, formItem, eyebrow, title, submitLabel] of [
    ['novo', {}, 'Novo registro', 'Cadastrar chamado crítico', 'Cadastrar chamado'],
    ['editar', { id: 7, incident_number: 'INC-QA-7', room_date: '2026-10-28', status: 'open', severity: 'critical', source: 'manual' }, 'Registro #7', 'Editar chamado crítico', 'Salvar alterações'],
  ]) {
    const markup = renderCritical(formItem)
    const panel = markup.slice(markup.indexOf('data-dialog-backdrop'))
    assert.match(markup, /<div class="fixed inset-0 z-\[60\][^"]*" data-dialog-backdrop="">/, `Chamados (${label}): formulario no Dialog, acima dos modais`)
    assert.match(panel, /aria-modal="true" class="card relative my-auto w-full max-w-6xl border-red-500\/20" role="dialog" tabindex="-1">/, `Chamados (${label}): role="dialog", aria-modal, largura e borda de antes`)
    assert.ok(panel.includes(`<p class="section-eyebrow">${eyebrow}</p>`) && panel.includes(`>${title}</h2>`), `Chamados (${label}): eyebrow e titulo`)
    assert.match(panel, /Alterações ficam vinculadas ao usuário autenticado e registradas em auditoria\./u, `Chamados (${label}): descricao`)
    assert.equal((panel.match(/>Cancelar<\/button>/g) || []).length, 2, `Chamados (${label}): Cancelar no cabecalho e no fim`)
    assert.ok(panel.includes(`>${submitLabel}</button>`), `Chamados (${label}): botao de salvar`)
    assert.doesNotMatch(markup, /aria-label="Fechar formulário"|fixed inset-0 z-50 overflow-y-auto/u, `Chamados (${label}): sem o fundo antigo (botao que fechava no clique, sem perguntar)`)
    assert.doesNotMatch(markup, /role="alertdialog"/, `Chamados (${label}): o aviso de descarte so abre ao sair com o formulario sujo`)
    if (formItem.id) assert.match(panel, /value="INC-QA-7"/, 'Chamados (editar): campos com os valores carregados')
  }
  const criticalSource = read('src/pages/CriticalIncidentsPage.jsx')
  const formSource = criticalSource.slice(criticalSource.indexOf('function IncidentForm('), criticalSource.indexOf('function IncidentDetails('))
  assert.match(formSource, /const \[openedWith\] = useState\(\(\) => normalizeForForm\(initial\)\)\s*const \[form, setForm\] = useState\(openedWith\)\s*const guard = useUnsavedChangesGuard\(formValuesChanged\(openedWith, form\)\)\s*const close = \(\) => guard\.requestLeave\(onClose\)/, 'Chamados: sujo = diferente do que abriu; fechar pergunta antes de descartar')
  assert.match(formSource, /onClose=\{close\}/, 'Chamados: Esc e clique fora pelo close (pergunta)')
  assert.match(formSource, /initialFocusRef=\{firstFieldRef\}\s*returnFocusRef=\{returnFocusRef\}/, 'Chamados: foco inicial no primeiro campo; ao fechar, volta a quem abriu')
  assert.match(criticalSource, /onClick=\{\(event\) => \{ dialogTriggerRef\.current = event\.currentTarget; setFormItem\(\{\}\) \}\} type="button">Novo chamado<\/button>/, 'Chamados: Novo chamado guarda quem abriu')
  assert.match(criticalSource, /onClick=\{\(event\) => \{ dialogTriggerRef\.current = event\.currentTarget; openItem\(item\.id, 'edit'\) \}\} type="button">Editar<\/button>/, 'Chamados: Editar guarda quem abriu (ele fica desabilitado enquanto carrega)')
  assert.match(criticalSource, /<IncidentForm [^\n]*returnFocusRef=\{dialogTriggerRef\}/, 'Chamados: o formulario recebe quem abriu')
  assert.match(formSource, /INCIDENTE \*<input className="field mt-2" maxLength="100" ref=\{firstFieldRef\}/, 'Chamados: o primeiro campo e o INCIDENTE')
  assert.equal((formSource.match(/onClick=\{close\} type="button">Cancelar<\/button>/g) || []).length, 2, 'Chamados: os dois Cancelar pelo close (pergunta)')
  assert.doesNotMatch(formSource, /onClick=\{onClose\}/, 'Chamados: nenhum botao fecha direto sem perguntar')
  assert.match(formSource, /<ConfirmDiscardDialog \{\.\.\.guard\.dialogProps\} \/>/, 'Chamados: aviso de descarte ligado ao guard')
  assert.match(criticalSource, /onSuccess: \(\) => setFormItem\(null\),/, 'Chamados: salvar com sucesso fecha direto, sem perguntar; erro mantem o formulario aberto')

  // [17f, X1] Visualizar e Importar pelo Dialog, sem a protecao de alteracoes.
  const viewMarkup = renderCritical(null, { qaViewItem: { id: 7, incident_number: 'INC-QA-7', room_date: '2026-10-28', severity: 'critical', status: 'open', source: 'teams', room_description: 'Sala QA', meeting_url: 'https://sala.invalid/qa' } })
  const viewPanel = viewMarkup.slice(viewMarkup.indexOf('data-dialog-backdrop'))
  assert.match(viewPanel, /aria-modal="true" class="card relative my-auto w-full max-w-4xl border-red-500\/20" role="dialog" tabindex="-1">/, 'Visualizar: role="dialog", aria-modal, largura e borda de antes')
  assert.match(viewPanel, /<div class="flex flex-wrap gap-2"><span class="status-badge[^"]*">Crítica<\/span><span class="status-badge[^"]*">Aberto<\/span><\/div><h2 class="mt-4 text-xl font-bold text-white" id="[^"]+">INC-QA-7<\/h2>/u, 'Visualizar: selos acima do titulo')
  assert.match(viewPanel, /Sala em 2026-10-28 \/ origem Teams \/ incidente aberto em/u, 'Visualizar: linha da sala como descricao')
  assert.match(viewPanel, />Fechar<\/button>/, 'Visualizar: botao Fechar')
  assert.match(viewPanel, /href="https:\/\/sala\.invalid\/qa"/, 'Visualizar: link da sala')
  assert.doesNotMatch(viewMarkup, /aria-label="Fechar detalhes"|fixed inset-0 z-50/, 'Visualizar: sem o fundo antigo')
  const importMarkup = renderCritical(null, { qaImportOpen: true })
  const importPanel = importMarkup.slice(importMarkup.indexOf('data-dialog-backdrop'))
  assert.match(importMarkup, /<div class="fixed inset-0 z-\[60\] flex justify-end bg-black\/75 backdrop-blur-sm" data-dialog-backdrop="">/, 'Importar: fundo do painel lateral')
  assert.match(importPanel, /aria-modal="true" class="relative h-full w-full max-w-2xl overflow-y-auto border-l border-white\/10 bg-slate-950 p-6 " role="dialog" tabindex="-1">/, 'Importar: role="dialog", aria-modal e o painel lateral de antes')
  assert.ok(importPanel.includes('>Preview obrigatório</p>') && importPanel.includes('>Importar planilha War Room</h2>') && importPanel.includes('>Fechar</button>'), 'Importar: eyebrow, titulo e Fechar')
  assert.doesNotMatch(importMarkup, /aria-label="Fechar importação"|<aside/u, 'Importar: sem o fundo antigo')
  const detailsSource = criticalSource.slice(criticalSource.indexOf('function IncidentDetails('), criticalSource.indexOf('export function CriticalIncidentsPage('))
  assert.equal((detailsSource.match(/^\s*onClose=\{onClose\}$/gm) || []).length, 2, 'Visualizar e Importar: Esc e clique fora fecham direto')
  assert.doesNotMatch(detailsSource, /useUnsavedChangesGuard|ConfirmDiscardDialog/, 'Visualizar e Importar: sem a protecao de alteracoes (decisao X1)')
  assert.equal((detailsSource.match(/onClick=\{onClose\} type="button">Fechar<\/button>/g) || []).length, 2, 'Visualizar e Importar: o botao Fechar fecha')
  assert.match(detailsSource, /returnFocusRef=\{returnFocusRef\}/, 'Visualizar: foco de volta a quem abriu')
  assert.match(criticalSource, /<IncidentDetails [^\n]*returnFocusRef=\{dialogTriggerRef\}/, 'Visualizar: recebe quem abriu')
  assert.match(criticalSource, /onClick=\{\(event\) => \{ dialogTriggerRef\.current = event\.currentTarget; openItem\(item\.id, 'view'\) \}\}/, 'Visualizar: guarda quem abriu (fica desabilitado enquanto carrega)')
}

// ---------------------------------------------------------------------------
// 9. [17f, item 3] Funcionarios: editar (Dialog) e adicionar (formulario na tela).
// ---------------------------------------------------------------------------
{
  const { AdminPage } = await importSrc('pages/AdminPage.jsx')
  const renderEmployees = (editForm) => {
    globalThis.qaResources = {
      'listar_funcionarios.php': { sucesso: true, funcionarios: [{ id: 3, nome: 'Tecnico QA', equipe: 'n1', ativo: true, jornada_entrada: '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', ad_login: 'tecnico.qa', access_role: 'tecnico', arquivado_em: null, fora_mapa_escala: false }] },
      'solicitacoes_pendentes.php': { solicitacoes: [], overtime: [], time_adjustments: [] },
      'configuracoes.php': { sucesso: true, configuracoes: {} },
    }
    globalThis.qaEditForm = editForm
    try {
      return renderToStaticMarkup(h(AdminPage, { session: { role: 'admin', permissions: ['funcionarios.manage', 'perfis.promote', 'funcionarios.fora_mapa', 'funcionarios.restaurar', 'escalas.lideranca'], ci: { funcionario_id: 0 }, gestor: { username: 'admin.qa' } }, search: '?tab=funcionarios', navigate: () => {}, notify: () => {}, refreshStatus: () => {} }))
    } finally {
      delete globalThis.qaResources
      delete globalThis.qaEditForm
    }
  }
  const closed = renderEmployees(null)
  assert.ok(closed.includes('>Adicionar funcionário</h2>') || closed.includes('Adicionar funcionário'), 'Funcionarios: formulario de adicionar na tela')
  assert.doesNotMatch(closed, /data-dialog-backdrop/, 'Funcionarios: sem edicao aberta, nenhum dialogo')
  const opened = renderEmployees({ id: 3, nome: 'Tecnico QA' })
  const panel = opened.slice(opened.indexOf('data-dialog-backdrop'))
  assert.match(opened, /<div class="fixed inset-0 z-\[60\][^"]*" data-dialog-backdrop="">/, 'Funcionarios: edicao no Dialog, acima dos modais')
  assert.match(panel, /aria-modal="true" class="card relative my-auto w-full max-w-5xl border-cyan-500\/20" role="dialog" tabindex="-1">/, 'Funcionarios: role="dialog", aria-modal, largura e borda de antes')
  assert.ok(panel.includes('<p class="section-eyebrow">Alteração auditada</p>') && panel.includes('>Editar funcionário</h2>'), 'Funcionarios: eyebrow e titulo')
  assert.match(panel, /Dados atuais do CI carregados\./u, 'Funcionarios: descricao')
  assert.equal((panel.match(/>Cancelar<\/button>/g) || []).length, 2, 'Funcionarios: Cancelar no cabecalho e no fim')
  assert.ok(panel.includes('>Salvar alterações</button>'), 'Funcionarios: botao de salvar')
  assert.ok(panel.includes('Fora do Mapa PA e da escala'), 'Funcionarios: campo so da edicao')
  assert.doesNotMatch(opened, /aria-label="Cancelar edição"|fixed inset-0 z-50/u, 'Funcionarios: sem o fundo antigo (botao que fechava no clique, sem perguntar)')
  assert.doesNotMatch(opened, /role="alertdialog"/, 'Funcionarios: o aviso de descarte so abre ao sair com o formulario sujo')

  const adminSource = read('src/pages/AdminPage.jsx')
  const tabSource = adminSource.slice(adminSource.indexOf('function EmployeesTab('), adminSource.indexOf('function UsersTab('))
  assert.match(tabSource, /formState: \{ errors: createEmployeeErrors, isDirty: createEmployeeDirty \}/, 'Funcionarios: sujo do adicionar pelo React Hook Form')
  assert.match(tabSource, /formState: \{ errors: editEmployeeErrors, isDirty: editEmployeeDirty \}/, 'Funcionarios: sujo do editar pelo React Hook Form')
  assert.match(tabSource, /const createGuard = useUnsavedChangesGuard\(createEmployeeDirty\)/, 'Funcionarios: protecao propria do adicionar')
  assert.match(tabSource, /const editGuard = useUnsavedChangesGuard\(Boolean\(editForm\) && editEmployeeDirty\)/, 'Funcionarios: protecao da edicao so com a edicao aberta')
  assert.match(tabSource, /function edit\(item, trigger\) \{\s*editGuard\.requestLeave\(\(\) => openEdit\(item, trigger\)\)\s*\}/, 'Funcionarios: "Editar outro" com a edicao suja pergunta antes')
  assert.match(tabSource, /function closeEdit\(\) \{\s*editGuard\.requestLeave\(\(\) => setEditForm\(null\)\)\s*\}/, 'Funcionarios: fechar a edicao pergunta com ela suja')
  assert.match(tabSource, /function openEdit\(item, trigger\) \{\s*editTriggerRef\.current = trigger/, 'Funcionarios: guarda quem abriu')
  assert.match(tabSource, /onClick=\{\(event\) => edit\(item, event\.currentTarget\)\} type="button">Editar<\/button>/, 'Funcionarios: o Editar passa pelo edit com quem abriu')
  assert.match(tabSource, /initialFocusRef=\{editNameRef\}\s*onClose=\{closeEdit\}/, 'Funcionarios: foco inicial no Nome; Esc e clique fora pelo closeEdit')
  assert.match(tabSource, /returnFocusRef=\{editTriggerRef\}/, 'Funcionarios: foco de volta a quem abriu')
  assert.match(tabSource, /nameRef=\{editNameRef\}/, 'Funcionarios: o Nome da edicao recebe o ref do foco inicial')
  assert.equal((tabSource.match(/onClick=\{closeEdit\} type="button">Cancelar<\/button>/g) || []).length, 2, 'Funcionarios: os dois Cancelar pelo closeEdit (pergunta)')
  assert.doesNotMatch(tabSource, /onClick=\{\(\) => setEditForm\(null\)\}/, 'Funcionarios: nenhum botao fecha a edicao direto sem perguntar')
  assert.match(tabSource, /<ConfirmDiscardDialog \{\.\.\.createGuard\.dialogProps\} \/>\s*<ConfirmDiscardDialog \{\.\.\.editGuard\.dialogProps\} \/>/, 'Funcionarios: um aviso de descarte para cada formulario')
  assert.match(tabSource, /ACTION_FEEDBACK\.employeeCreated,\s*\(\) => resetCreateEmployee\(emptyEmployee\),/, 'Funcionarios: adicionar com sucesso limpa o formulario (deixa de estar sujo)')
  assert.match(tabSource, /ACTION_FEEDBACK\.employeeUpdated,\s*\(\) => setEditForm\(null\),/, 'Funcionarios: salvar com sucesso fecha direto, sem perguntar; erro mantem o formulario')
  const fieldsSource = adminSource.slice(adminSource.indexOf('function EmployeeFields('), adminSource.indexOf('const archiveStateLabels'))
  assert.match(fieldsSource, /\{\.\.\.withFieldRef\(register\('nome'\), nameRef\)\}/, 'Funcionarios: o Nome continua registrado no React Hook Form e entrega o ref')
  assert.match(adminSource, /ref: \(element\) => \{ field\.ref\(element\); if \(fieldRef\) fieldRef\.current = element \}/, 'Funcionarios: withFieldRef chama o ref do React Hook Form e o proprio')
}

// ---------------------------------------------------------------------------
// 10. [17f, item 4] Ausencias e Escala: protecao, Remover regra e Historico.
// ---------------------------------------------------------------------------
{
  const absenceSource = read('src/pages/AbsencesPage.jsx')
  assert.match(absenceSource, /formState: \{ errors, isDirty \}/, 'Ausencias: sujo vem do React Hook Form')
  assert.match(absenceSource, /const guard = useUnsavedChangesGuard\(isDirty\)/, 'Ausencias: protecao ligada ao formulario')
  assert.match(absenceSource, /function requestCloseForm\(\) \{\s*guard\.requestLeave\(closeForm\)\s*\}/, 'Ausencias: fechar passa pela protecao')
  assert.match(absenceSource, /disabled=\{saving\} onClick=\{requestCloseForm\} type="button">/, 'Ausencias: o botao de fechar pergunta quando sujo')
  assert.match(absenceSource, /onClick=\{\(\) => guard\.requestLeave\(\(\) => editAbsence\(item\)\)\} type="button">Editar<\/button>/, 'Ausencias: "Editar outra" passa pela protecao')
  assert.match(absenceSource, /onSuccess: \(\) => closeForm\(\),/, 'Ausencias: salvar com sucesso fecha direto, sem perguntar')
  assert.match(absenceSource, /if \(editing\?\.id === item\.id\) closeForm\(\)/, 'Ausencias: cancelar a propria ausencia fecha direto (ja confirmado na linha)')
  assert.match(absenceSource, /<ConfirmDiscardDialog \{\.\.\.guard\.dialogProps\} \/>/, 'Ausencias: aviso de descarte ligado')
  assert.ok(absenceSource.indexOf('<ConfirmDiscardDialog') > absenceSource.indexOf('</form>'), 'Ausencias: aviso fora do formulario')
  assert.doesNotMatch(absenceSource, /window\.confirm/, 'Ausencias: sem window.confirm')

  const operationalSource = read('src/pages/OperationalPages.jsx')
  const scheduleSource = operationalSource.slice(operationalSource.indexOf('export function SchedulePage('), operationalSource.indexOf('function WorkflowPage('))
  assert.match(scheduleSource, /formState: \{ errors: ruleErrors, isDirty: ruleDirty \}/, 'Escala: sujo vem do React Hook Form')
  assert.match(scheduleSource, /const ruleGuard = useUnsavedChangesGuard\(ruleDirty\)/, 'Escala: protecao ligada ao formulario de regra')
  assert.match(scheduleSource, /onClick=\{\(\) => ruleGuard\.requestLeave\(\(\) => editRule\(item\)\)\} type="button">Editar<\/button>/, 'Escala: "Editar outra" passa pela protecao')
  assert.match(scheduleSource, /setRuleValue\(field, ordered, \{ shouldDirty: true \}\)/, 'Escala: dias da semana contam como alteracao')
  assert.match(scheduleSource, /<ConfirmDiscardDialog \{\.\.\.ruleGuard\.dialogProps\} \/>/, 'Escala: aviso de descarte ligado')
  assert.doesNotMatch(operationalSource, /window\.confirm/, 'Escala: sem window.confirm')
  // Remover regra
  assert.match(scheduleSource, /removeReturnRef\.current = trigger\s*setRemoveTarget\(item\)/, 'Remover regra: guarda quem abriu')
  assert.match(scheduleSource, /<ConfirmActionDialog\s+busy=\{removing\}/, 'Remover regra: pela confirmacao compartilhada')
  assert.match(scheduleSource, /title="Remover regra de escala\?"/, 'Remover regra: titulo')
  assert.match(scheduleSource, /onCancel=\{\(\) => setRemoveTarget\(null\)\}/, 'Remover regra: Cancelar fecha sem remover')
  assert.match(scheduleSource, /returnFocusRef=\{removeReturnRef\}/, 'Remover regra: devolve o foco')
  assert.match(scheduleSource, /resetRule\(emptyScheduleRule\(\)\)\s*setRemoveTarget\(null\)\s*\},\s*\)\s*(?:\/\/[^\n]*\n\s*)*setRemoving\(false\)/, 'Remover regra: so fecha com sucesso; com erro, fica aberto (BB1)')
  assert.match(scheduleSource, /removeReturnRef\.current = rulesTitleRef\.current/, 'Remover regra: com sucesso, foco no titulo da lista')
  assert.match(scheduleSource, /if \(!ruleDirty && Number\(rule\.employee_id\) === Number\(item\.employee_id\)\) resetRule\(emptyScheduleRule\(\)\)/, 'Remover regra: formulario sujo nao se perde')
  assert.match(scheduleSource, /action: 'remove_rule', employee_id: Number\(item\.employee_id\)/, 'Remover regra: mesmo payload de antes')
  // Historico
  assert.match(operationalSource, /const PHONE_MEDIA_QUERY = '\(max-width: 767px\)'/, 'Historico: celular abaixo do md')
  assert.match(scheduleSource, /if \(historyEmployeeId === null \|\| !window\.matchMedia\(PHONE_MEDIA_QUERY\)\.matches\) return\s*historyTitleRef\.current\?\.scrollIntoView\(\{ block: 'start' \}\)\s*historyTitleRef\.current\?\.focus\(\{ preventScroll: true \}\)\s*\}, \[historyEmployeeId\]\)/, 'Historico: so no celular, rola e foca o titulo')
  assert.match(scheduleSource, /<h2 className="scroll-mt-28 font-bold text-white" ref=\{historyTitleRef\} tabIndex=\{-1\}>Histórico de/, 'Historico: titulo focavel, com folga do topo ao rolar')
  assert.match(scheduleSource, /if \(historyTriggerRef\.current\?\.isConnected\) historyTriggerRef\.current\.focus\(\)/, 'Historico: Fechar devolve o foco a quem abriu')
  assert.match(scheduleSource, /onClick=\{closeHistory\} type="button">Fechar<\/button>/, 'Historico: Fechar ligado')
  // Formulario de excecao e importacao ficam fora da protecao (pedido do item 4).
  assert.equal((scheduleSource.match(/useUnsavedChangesGuard\(/g) || []).length, 1, 'Escala: uma protecao so, a da regra')
}

// ---------------------------------------------------------------------------
// 11. [17f, BB] Aviso acima dos dialogos, trava de rolagem e foco sem destino.
// ---------------------------------------------------------------------------
{
  // BB1: camada do aviso e o portal.
  const toast = renderToStaticMarkup(h(Feedback, { feedback: { message: 'Mensagem QA', type: 'error' }, onClose: () => {} }))
  assert.match(toast, /^<div class="portal-toast fixed bottom-4 left-4 right-4 z-\[70\] /, 'Aviso: z-[70], acima do Dialog (z-[60])')
  assert.match(read('src/index.css'), /body:has\(\[data-dialog-backdrop\]\) \.portal-toast \{\s*top: 1rem;\s*bottom: auto;/, 'Aviso: com dialogo aberto, sobe (embaixo cobriria os botoes do fim do formulario)')
  assert.match(toast, /role="alert"/, 'Aviso de erro continua com role="alert"')
  const feedbackSource = read('src/components/Feedback.jsx')
  assert.match(feedbackSource, /return typeof document === 'undefined' \? toast : createPortal\(toast, document\.body\)/, 'Aviso: no body (dentro da pagina, o isolate o deixaria abaixo do Dialog)')
  assert.match(feedbackSource, /onMouseDown=\{\(event\) => event\.preventDefault\(\)\}/, 'Aviso: o botao de fechar nao tira o foco do dialogo')

  // Confirmacao compartilhada.
  const confirm = renderToStaticMarkup(h(ConfirmActionDialog, { title: 'Titulo QA', description: 'Descricao QA', confirmLabel: 'Confirmar QA', busyLabel: 'Enviando QA', onConfirm: () => {}, onCancel: () => {} }))
  assert.match(confirm, /role="alertdialog"/, 'Confirmacao: alertdialog')
  assert.match(confirm, /<button class="btn-danger" type="button">Confirmar QA<\/button><button class="btn-secondary" type="button">Cancelar<\/button>/, 'Confirmacao: botoes')
  const busy = renderToStaticMarkup(h(ConfirmActionDialog, { title: 'Titulo QA', confirmLabel: 'Confirmar QA', busyLabel: 'Enviando QA', busy: true, onConfirm: () => {}, onCancel: () => {} }))
  assert.match(busy, /<button class="btn-danger" disabled="" type="button">Enviando QA<\/button><button class="btn-secondary" disabled="" type="button">Cancelar<\/button>/, 'Confirmacao: enviando, os dois botoes desabilitados')
  const confirmSource = read('src/components/ui/ConfirmActionDialog.jsx')
  assert.match(confirmSource, /initialFocusRef=\{cancelRef\}/, 'Confirmacao: foco inicial no Cancelar')
  assert.match(confirmSource, /onClose=\{\(\) => \{ if \(!busy\) onCancel\(\) \}\}/, 'Confirmacao: Esc e clique fora nao fecham durante o envio')
  assert.match(confirmSource, /if \(wasBusy\.current && !busy\) cancelRef\.current\?\.focus\(\)/, 'Confirmacao: ao terminar o envio aberto, o foco volta ao Cancelar')

  // BB2: trava com contagem, largura da barra e restauracao.
  const fakeDocument = ({ innerWidth, clientWidth, overflow = '', paddingRight = '', computedPadding = '0px' }) => {
    const body = { style: { overflow, paddingRight } }
    return { body, documentElement: { clientWidth }, defaultView: { innerWidth, getComputedStyle: () => ({ paddingRight: computedPadding }) } }
  }
  const doc = fakeDocument({ innerWidth: 1366, clientWidth: 1351 })
  const releaseFirst = lockPageScroll(doc)
  assert.deepEqual(doc.body.style, { overflow: 'hidden', paddingRight: '15px' }, 'Trava: overflow hidden e a largura da barra em padding')
  const releaseSecond = lockPageScroll(doc)
  assert.deepEqual(doc.body.style, { overflow: 'hidden', paddingRight: '15px' }, 'Trava: o segundo dialogo nao soma padding')
  releaseSecond()
  assert.deepEqual(doc.body.style, { overflow: 'hidden', paddingRight: '15px' }, 'Trava: sai um, continua travado')
  releaseSecond()
  assert.equal(doc.body.style.overflow, 'hidden', 'Trava: soltar duas vezes conta uma so')
  releaseFirst()
  assert.deepEqual(doc.body.style, { overflow: '', paddingRight: '' }, 'Trava: sai o ultimo, tudo volta')
  const noBar = fakeDocument({ innerWidth: 390, clientWidth: 390 })
  const releaseNoBar = lockPageScroll(noBar)
  assert.deepEqual(noBar.body.style, { overflow: 'hidden', paddingRight: '' }, 'Trava: sem barra, sem padding')
  releaseNoBar()
  const styled = fakeDocument({ innerWidth: 1000, clientWidth: 985, overflow: 'scroll', paddingRight: '4px', computedPadding: '4px' })
  const releaseStyled = lockPageScroll(styled)
  assert.equal(styled.body.style.paddingRight, '19px', 'Trava: soma ao padding que ja existe')
  releaseStyled()
  assert.deepEqual(styled.body.style, { overflow: 'scroll', paddingRight: '4px' }, 'Trava: volta o estilo que estava')

  // BB3: foco sem destino.
  const focused = []
  const title = { focus: (options) => focused.push(options) }
  const focusDoc = (active) => ({ body: 'corpo', activeElement: active, querySelector: (selector) => (selector === '[data-page-title]' ? title : null) })
  focusPageTitle(focusDoc(null))
  assert.deepEqual(focused, [{ preventScroll: true }], 'Foco: vai ao titulo da pagina, sem rolar')
  focusPageTitleIfLost(focusDoc('corpo'))
  assert.equal(focused.length, 2, 'Foco no body: vai ao titulo')
  focusPageTitleIfLost(focusDoc({ isConnected: false }))
  assert.equal(focused.length, 3, 'Foco em quem saiu da tela: vai ao titulo')
  focusPageTitleIfLost(focusDoc({ isConnected: true }))
  assert.equal(focused.length, 3, 'Foco em quem esta na tela: fica')
  assert.match(read('src/components/PortalLayout.jsx'), /<h1 className="[^"]*" data-page-title="" tabIndex=\{-1\}>\{metadata\[0\]\}<\/h1>/, 'Titulo da pagina focavel')
  assert.match(read('src/pages/AbsencesPage.jsx'), /if \(formWasOpen\.current && !formOpen\) focusPageTitleIfLost\(document\)/, 'Ausencias: fechar o formulario nao deixa o foco no body')
}

// ---------------------------------------------------------------------------
// 12. [17g] Aprovar e Rejeitar: a mesma confirmacao na linha, no cartao e na aba.
// ---------------------------------------------------------------------------
{
  const { DECISION_ENDPOINTS, DECISION_WARNING, adjustmentTypeLabel, decisionDialogText } = await importSrc('lib/decisionConfirm.ts')
  const { DecisionConfirmDialog } = await importSrc('components/ui/DecisionConfirmDialog.jsx')
  assert.equal(DECISION_WARNING, 'Esta decisão não pode ser desfeita pelo sistema.', 'Texto do pedido')
  assert.deepEqual(DECISION_ENDPOINTS, { overtime: 'portal/overtime.php', adjustment: 'portal/time_corrections.php' }, 'Endpoints de antes')
  const overtimeItem = { id: 7, employee_name: 'Tecnico QA', work_date: '2026-10-28', start_time: '18:00:00', end_time: '20:30:00', total_minutes: 150 }
  const adjustmentItem = { id: 8, employee_name: 'Tecnico QA', adjustment_date: '2026-10-27', adjustment_type: 'lunch_out', correct_time: '12:05:00' }
  assert.deepEqual(decisionDialogText('overtime', overtimeItem, 'approved'), { title: 'Aprovar hora extra?', employee: 'Tecnico QA', detail: '28/10/2026, 18:00 - 20:30, total 2h 30min', confirmLabel: 'Aprovar', busyLabel: 'Aprovando...', confirmClassName: 'btn-primary' }, 'Hora extra, aprovar')
  assert.deepEqual(decisionDialogText('overtime', overtimeItem, 'rejected'), { title: 'Rejeitar hora extra?', employee: 'Tecnico QA', detail: '28/10/2026, 18:00 - 20:30, total 2h 30min', confirmLabel: 'Rejeitar', busyLabel: 'Rejeitando...', confirmClassName: 'btn-danger' }, 'Hora extra, rejeitar')
  assert.equal(decisionDialogText('adjustment', adjustmentItem, 'approved').title, 'Aprovar ajuste de ponto?', 'Ajuste, aprovar')
  assert.equal(decisionDialogText('adjustment', adjustmentItem, 'rejected').title, 'Rejeitar ajuste de ponto?', 'Ajuste, rejeitar')
  assert.equal(decisionDialogText('adjustment', adjustmentItem, 'approved').detail, '27/10/2026, Saída para almoço, horário correto 12:05', 'Ajuste: data, tipo e horario')
  assert.equal(decisionDialogText('adjustment', { ...adjustmentItem, adjustment_type: 'absence', correct_time: null }, 'approved').detail, '27/10/2026, Ausência, horário correto não informado', 'Ajuste sem horario')
  assert.equal(adjustmentTypeLabel('desconhecido'), 'Ajuste', 'Tipo desconhecido')
  assert.equal(renderToStaticMarkup(h(DecisionConfirmDialog, { request: null })), '', 'Sem pedido, sem dialogo')
  const dialog = renderToStaticMarkup(h(DecisionConfirmDialog, { request: { kind: 'overtime', item: overtimeItem, decision: 'approved' }, busy: false, onConfirm: () => {}, onCancel: () => {} }))
  assert.match(dialog, /role="alertdialog"/, 'alertdialog')
  assert.match(dialog, />Aprovar hora extra\?<\/h2>/, 'Titulo')
  assert.ok(dialog.includes('>Tecnico QA</span>') && dialog.includes('28/10/2026, 18:00 - 20:30, total 2h 30min') && dialog.includes(DECISION_WARNING), 'Nome, detalhe e aviso na descricao (lida pelo leitor de tela)')
  assert.match(dialog, /<button class="btn-primary" type="button">Aprovar<\/button><button class="btn-secondary" type="button">Cancelar<\/button>/, 'Botoes')
  const rejecting = renderToStaticMarkup(h(DecisionConfirmDialog, { request: { kind: 'overtime', item: overtimeItem, decision: 'rejected' }, busy: true, onConfirm: () => {}, onCancel: () => {} }))
  assert.match(rejecting, /<button class="btn-danger" disabled="" type="button">Rejeitando\.\.\.<\/button>/, 'Rejeitar em vermelho; enviando, desabilitado')

  const hookSource = read('src/hooks/useDecisionConfirm.ts')
  assert.match(hookSource, /action: \(\) => post\(DECISION_ENDPOINTS\[kind\], \{ action: 'decision', id: item\.id, decision \}\)/, 'Payload de antes')
  assert.match(hookSource, /onSuccess: \(\) => setRequest\(null\)/, 'So fecha com sucesso')
  assert.match(hookSource, /onError: \(\) => \{ for \(const refreshList of refresh\) void Promise\.resolve\(refreshList\(\)\)\.catch\(\(\) => \{\}\) \}/, 'Recusa: a lista atualiza por tras')
  assert.match(hookSource, /return trigger\?\.isConnected \? trigger : sectionTitle\?\.current \?\? null/, 'Foco: o botao, ou o titulo da secao se ele saiu da lista')
  assert.match(hookSource, /successMessage: decisionFeedback\(decision\)/, 'Aviso de sucesso de antes')

  const operationalSource = read('src/pages/OperationalPages.jsx')
  const workflowSource = operationalSource.slice(operationalSource.indexOf('function WorkflowPage('), operationalSource.indexOf('export function OvertimePage('))
  assert.match(workflowSource, /const decision = useDecisionConfirm\(notify, \[resource\.refresh\]\)/, 'Linha e cartao: a mesma funcao')
  for (const value of ['approved', 'rejected']) {
    assert.ok(workflowSource.includes(`onClick={(event) => decision.ask(overtime ? 'overtime' : 'adjustment', item, '${value}', event.currentTarget, pendingTitleRef)}`), `Linha e cartao: ${value} pergunta antes`)
  }
  assert.equal((workflowSource.match(/\{decisionContent\(item\)\}/g) || []).length, 2, 'Linha e cartao usam o mesmo bloco de decisao')
  assert.match(workflowSource, /<DecisionConfirmDialog \{\.\.\.decision\.dialogProps\} \/>/, 'Dialogo ligado')
  assert.match(workflowSource, /title="Pendentes de aprovação" titleRef=\{pendingTitleRef\}/u, 'Titulo da secao recebe o foco')
  assert.doesNotMatch(workflowSource, /action: 'decision'/, 'Nenhuma decisao direta em Horas extras e Correcao')

  const adminSource = read('src/pages/AdminPage.jsx')
  const approvalsSource = adminSource.slice(adminSource.indexOf('function ApprovalsTab('), adminSource.indexOf('function SettingsTab('))
  assert.match(approvalsSource, /const decision = useDecisionConfirm\(notify, \[refresh, refreshStatus\]\)/, 'Aba: a mesma funcao')
  for (const [kind, ref] of [['overtime', 'overtimeTitleRef'], ['adjustment', 'adjustmentTitleRef']]) {
    for (const value of ['approved', 'rejected']) {
      assert.ok(approvalsSource.includes(`decision.ask('${kind}', item, '${value}', event.currentTarget, ${ref})`), `Aba: ${kind} ${value} pergunta antes`)
    }
  }
  assert.match(approvalsSource, /<DecisionConfirmDialog \{\.\.\.decision\.dialogProps\} \/>/, 'Aba: dialogo ligado')
  assert.doesNotMatch(approvalsSource, /action: 'decision'/, 'Aba: nenhuma decisao direta')
  assert.match(approvalsSource, /onApprove=\{\(\) => decidePause\(request\.id, 'aprovar'\)\} onReject=\{\(\) => decidePause\(request\.id, 'rejeitar'\)\}/, 'Pausas e reunioes sem confirmacao (decisao de 2026-10-29)')
  for (const code of [hookSource, workflowSource, approvalsSource, read('src/components/ui/DecisionConfirmDialog.jsx')]) {
    assert.doesNotMatch(code, /window\.confirm/, 'Sem window.confirm')
  }
}

console.log('Unsaved changes QA OK')
