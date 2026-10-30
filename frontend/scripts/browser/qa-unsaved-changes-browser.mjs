/* global process */
// [17f, V1] Conferencia no navegador da protecao contra perda de alteracoes,
// nas TELAS REAIS que usam a peca, com servidor simulado e dados ficticios.
// Fora dos qa:* (precisa de navegador). Saida so com contagens: cada
// conferencia "ok" ou "FALHOU" (com os nomes dos campos que divergiram).
//
// Uso (na pasta frontend):
//   CHROME_PATH="C:\Program Files\Google\Chrome\Application\chrome.exe" node scripts/browser/qa-unsaved-changes-browser.mjs
//   ... --app <pasta-do-build>   usa um build pronto (sem isso, gera um build numa pasta temporaria; nunca em ../app)
// Requer Node 22 ou mais novo (WebSocket nativo).
import { spawnSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { launchChrome, openPage, sleep } from './lib/chrome.mjs'
import { FAILING_EMPLOYEE, FAILING_INCIDENT, startMockServer } from './lib/mock-server.mjs'

const frontendRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const appArg = process.argv.indexOf('--app')
let appDir = appArg > 0 ? process.argv[appArg + 1] : null
let tempBuild = null

let passed = 0
let failed = 0
function report(label, ok, detail = '') {
  if (ok) passed++
  else failed++
  console.log(`${ok ? 'ok     ' : 'FALHOU '} ${label}${ok || !detail ? '' : ` (${detail})`}`)
}
// Compara so os campos pedidos; na falha, imprime os NOMES dos campos.
async function expectState(page, label, expected, stateExpression = STATE) {
  const state = await page.evaluate(stateExpression)
  const wrong = Object.entries(expected).filter(([key, value]) => (typeof value === 'function' ? !value(state[key]) : state[key] !== value)).map(([key]) => key)
  report(label, wrong.length === 0, wrong.length ? `campos: ${wrong.join(', ')}` : '')
  return state
}

const STATE = `(() => {
  const active = document.activeElement
  const panel = [...document.querySelectorAll('[role="dialog"]')].find((el) => /chamado crítico/.test(el.querySelector('h2')?.textContent || ''))
  return {
    rota: location.pathname,
    formulario: !!panel,
    titulo: document.querySelector('[role="dialog"] h2')?.textContent ?? null,
    modal: document.querySelector('[role="dialog"]')?.getAttribute('aria-modal') ?? null,
    descartar: !!document.querySelector('[role="alertdialog"]'),
    foco: !active || active === document.body ? 'body' : active.tagName === 'INPUT' ? 'campo:' + (active.closest('label')?.childNodes[0]?.textContent.trim() || '') : (active.textContent || '').trim(),
    incidente: panel ? panel.querySelector('input[maxlength="100"]').value : null,
    beforeunload: (getEventListeners(window).beforeunload || []).length,
    popstate: (getEventListeners(window).popstate || []).length,
    keydown: (getEventListeners(document).keydown || []).length,
    dialogos: document.querySelectorAll('[data-dialog-backdrop]').length,
    aviso_erro: [...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada')),
  }
})()`

const button = (text, scope = 'document') => `[...${scope}.querySelectorAll('button')].filter((b) => b.textContent.trim() === ${JSON.stringify(text)}).at(-1)`
const FORM_PANEL = `[...document.querySelectorAll('[role="dialog"]')].find((el) => /chamado crítico/.test(el.querySelector('h2')?.textContent || ''))`
const HEADER_CANCEL = `${FORM_PANEL}.firstElementChild.querySelector(':scope > button')`
const BOTTOM_CANCEL = button('Cancelar', `${FORM_PANEL}.querySelector('form')`)
const SUBMIT = `${FORM_PANEL}.querySelector('form button[type="submit"]')`
const INCIDENT_FIELD = `${FORM_PANEL}.querySelector('input[maxlength="100"]')`
const ALERT = `document.querySelector('[role="alertdialog"]')`
const NAV_BUTTON = (label) => `[...document.querySelectorAll('nav[aria-label="Módulos do portal"] button')].filter((b) => b.offsetParent !== null).find((b) => b.textContent.trim() === ${JSON.stringify(label)})`

async function goto(page, port, path, width = 1366) {
  await page.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 500 })
  await page.send('Page.navigate', { url: `http://127.0.0.1:${port}/app${path}` })
  await page.waitFor(`!!document.querySelector('main') && !document.querySelector('.skeleton')`)
  await sleep(500)
}

// 1. Sem formulario sujo, a navegacao de todas as telas segue igual.
async function navigationWithoutDirtyForm(page, port, mock) {
  for (const width of [1366, 390]) {
    await goto(page, port, '/dashboard', width)
    await expectState(page, `${width} px, inicio: sem dialogo, sem beforeunload, um popstate`, { dialogos: 0, beforeunload: 0, popstate: 1, keydown: 0 })
    const labels = await page.evaluate(`[...document.querySelectorAll('nav[aria-label="Módulos do portal"] button')].map((b) => b.textContent.trim())`)
    const visited = []
    let navigationOk = 0
    for (const label of labels) {
      if (width < 500) { await page.evaluate(`document.querySelector('[aria-label="Abrir menu"]').click()`); await sleep(200) }
      await page.evaluate(`${NAV_BUTTON(label)}.click()`)
      await sleep(400)
      const state = await page.evaluate(STATE)
      visited.push(state.rota)
      if (state.dialogos === 0 && state.beforeunload === 0 && state.popstate === 1) navigationOk++
    }
    report(`${width} px, ${labels.length} itens do menu: troca de tela sem dialogo nem listener (${navigationOk}/${labels.length})`, navigationOk === labels.length && new Set(visited).size >= labels.length - 1)
    for (let step = 1; step <= 2; step++) {
      await page.evaluate('history.back()'); await sleep(400)
      await expectState(page, `${width} px, voltar ${step}: rota anterior, sem dialogo`, { rota: visited[visited.length - 1 - step], dialogos: 0 })
    }
    await page.evaluate('history.forward()'); await sleep(400)
    await expectState(page, `${width} px, avancar: sem dialogo`, { rota: visited[visited.length - 2], dialogos: 0 })
  }
  const before = mock.requests.filter((r) => r.key === 'logout.php').length
  await page.evaluate(`document.querySelector('[aria-label="Sair"]').click()`)
  await sleep(500)
  report('"Sair" sem formulario sujo: sai na hora (logout chamado uma vez), sem dialogo', mock.requests.filter((r) => r.key === 'logout.php').length === before + 1 && (await page.evaluate(STATE)).dialogos === 0)
  page.events.length = 0
  await page.send('Page.reload'); await sleep(1000)
  report('recarregar sem formulario sujo: sem aviso do navegador', !page.events.some((e) => e.method === 'Page.javascriptDialogOpening'))
}

async function openNew(page) {
  await page.click(button('Novo chamado'))
  await page.waitFor(`!!${FORM_PANEL}`)
}
async function makeDirty(page, text = ' editado') {
  await page.evaluate(`${INCIDENT_FIELD}.focus()`)
  await page.typeText(text)
}

// 2. Chamados criticos (tela real): novo e editar.
async function criticalIncidents(page, port, mock) {
  await goto(page, port, '/dashboard')
  await page.evaluate(`${NAV_BUTTON('Chamados críticos')}.click()`)
  await page.waitFor(`!!${button('Novo chamado')}`)
  const exits = [
    ['Esc', () => page.press('Escape')],
    ['Cancelar do cabecalho', () => page.click(HEADER_CANCEL)],
    ['Cancelar do fim', () => page.click(BOTTOM_CANCEL)],
    ['clique fora', () => page.clickAt(5, 450)],
  ]
  for (const [how, exit] of exits) {
    await openNew(page)
    await expectState(page, `novo, abrir: foco no INCIDENTE, um keydown, sem beforeunload`, { formulario: true, foco: 'campo:INCIDENTE *', keydown: 1, beforeunload: 0 })
    await exit()
    await expectState(page, `novo limpo, ${how}: fecha sem perguntar, foco volta ao "Novo chamado"`, { formulario: false, descartar: false, foco: 'Novo chamado', keydown: 0, dialogos: 0 })
  }
  for (const [how, exit] of exits) {
    await openNew(page)
    await makeDirty(page, 'INC-QA-RASCUNHO')
    await expectState(page, `novo sujo (${how}): beforeunload registrado`, { beforeunload: 1 })
    await exit()
    await expectState(page, `novo sujo, ${how}: pergunta, foco em "Continuar editando"`, { formulario: true, descartar: true, foco: 'Continuar editando', dialogos: 2 })
    await page.press('Escape')
    await expectState(page, `novo sujo, ${how}, Esc no aviso: continua, dados mantidos`, { formulario: true, descartar: false, incidente: 'INC-QA-RASCUNHO' })
    await exit()
    await page.clickAt(5, 450)
    await expectState(page, `novo sujo, ${how}, clique fora do aviso: continua`, { formulario: true, descartar: false, incidente: 'INC-QA-RASCUNHO' })
    await exit()
    await page.click(button('Continuar editando', ALERT))
    await expectState(page, `novo sujo, ${how}, "Continuar editando": dados mantidos`, { formulario: true, descartar: false, incidente: 'INC-QA-RASCUNHO' })
    await exit()
    await page.click(button('Descartar alterações', ALERT))
    await expectState(page, `novo sujo, ${how}, "Descartar": fecha, foco volta, sem listener`, { formulario: false, descartar: false, foco: 'Novo chamado', beforeunload: 0, keydown: 0, dialogos: 0 })
  }

  // Foco preso no formulario e no aviso.
  await openNew(page)
  await page.evaluate(`${SUBMIT}.focus()`)
  await page.press('Tab')
  await expectState(page, 'formulario: Tab no ultimo volta ao primeiro (Cancelar do cabecalho)', { foco: 'Cancelar' })
  await page.press('Tab', true)
  await expectState(page, 'formulario: Shift+Tab no primeiro vai ao ultimo', { foco: 'Cadastrar chamado' })
  await makeDirty(page, 'x')
  await page.press('Escape')
  await page.press('Tab')
  await expectState(page, 'aviso: Tab no ultimo vai ao primeiro', { foco: 'Descartar alterações' })
  await page.press('Tab', true)
  await expectState(page, 'aviso: Shift+Tab volta ao "Continuar editando"', { foco: 'Continuar editando' })
  await page.click(button('Descartar alterações', ALERT))

  // Erro do servidor mantem os dados; salvar com sucesso fecha sem perguntar.
  await openNew(page)
  await makeDirty(page, FAILING_INCIDENT)
  await page.click(SUBMIT)
  await page.waitFor(`[...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada'))`)
  await expectState(page, 'erro do servidor: aviso de erro, formulario aberto, dados mantidos, ainda sujo', { aviso_erro: true, formulario: true, incidente: FAILING_INCIDENT, beforeunload: 1 })
  await page.press('Escape')
  await expectState(page, 'erro do servidor, Esc: ainda pergunta (os dados nao se perdem)', { descartar: true })
  await page.click(button('Continuar editando', ALERT))
  await page.evaluate(`${INCIDENT_FIELD}.select()`)
  await page.typeText('INC-QA-NOVO')
  const posts = mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/critical_incidents.php').length
  await page.click(SUBMIT)
  await page.waitFor(`!${FORM_PANEL}`)
  await expectState(page, 'salvar com sucesso: fecha sem perguntar, sem listener', { formulario: false, descartar: false, beforeunload: 0, keydown: 0, dialogos: 0 })
  report('salvar com sucesso: um POST', mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/critical_incidents.php').length === posts + 1)

  // Editar: valores carregados; limpo fecha direto; sujo pergunta.
  await page.click(button('Editar'))
  await page.waitFor(`!!${FORM_PANEL} && ${INCIDENT_FIELD}.value !== ''`)
  await expectState(page, 'editar, abrir: valores carregados, foco no INCIDENTE', { formulario: true, incidente: (value) => /^INC-QA-00\d$/.test(value), foco: 'campo:INCIDENTE *' })
  await page.press('Escape')
  await expectState(page, 'editar limpo, Esc: fecha sem perguntar, foco volta ao "Editar"', { formulario: false, descartar: false, foco: 'Editar' })
  await page.click(button('Editar'))
  await page.waitFor(`!!${FORM_PANEL} && ${INCIDENT_FIELD}.value !== ''`)
  await makeDirty(page, '-sujo')
  await page.click(BOTTOM_CANCEL)
  await expectState(page, 'editar sujo, Cancelar: pergunta', { descartar: true })
  await page.click(button('Descartar alterações', ALERT))
  await expectState(page, 'editar sujo, Descartar: fecha', { formulario: false, descartar: false, beforeunload: 0 })

  // Rota com o formulario sujo: menu, voltar e "Sair".
  await openNew(page); await makeDirty(page)
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await expectState(page, 'sujo, menu: pergunta e nao troca de tela', { descartar: true, rota: '/app/chamados-criticos' })
  await page.click(button('Continuar editando', ALERT))
  await expectState(page, 'sujo, menu, Continuar: fica, dados mantidos', { rota: '/app/chamados-criticos', formulario: true, incidente: ' editado' })
  await page.evaluate('history.back()'); await sleep(400)
  await expectState(page, 'sujo, voltar: pergunta, endereco devolvido, tela igual', { descartar: true, rota: '/app/chamados-criticos', formulario: true })
  await page.click(button('Continuar editando', ALERT))
  await expectState(page, 'sujo, voltar, Continuar: fica', { rota: '/app/chamados-criticos', formulario: true })
  const logouts = mock.requests.filter((r) => r.key === 'logout.php').length
  await page.evaluate(`document.querySelector('[aria-label="Sair"]').click()`); await sleep(300)
  await expectState(page, 'sujo, Sair: pergunta', { descartar: true })
  await page.click(button('Continuar editando', ALERT))
  report('sujo, Sair, Continuar: nao sai (sem logout)', mock.requests.filter((r) => r.key === 'logout.php').length === logouts)
  page.events.length = 0
  await page.send('Page.reload').catch(() => {})
  await sleep(800)
  const prompt = page.events.find((e) => e.method === 'Page.javascriptDialogOpening')
  report('sujo, recarregar: aviso do navegador (beforeunload)', prompt?.params.type === 'beforeunload')
  if (prompt) { await page.send('Page.handleJavaScriptDialog', { accept: false }); await sleep(300) }
  await expectState(page, 'sujo, recarregar, ficar: dados mantidos', { formulario: true, incidente: ' editado' })
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT))
  await expectState(page, 'sujo, menu, Descartar: troca de tela, sem listener', { rota: '/app/calendario', formulario: false, beforeunload: 0, keydown: 0 })
  await page.evaluate('history.back()'); await sleep(500)
  await openNew(page); await makeDirty(page)
  await page.evaluate('history.back()'); await sleep(400)
  await page.click(button('Descartar alterações', ALERT))
  await expectState(page, 'sujo, voltar, Descartar: vai para a tela anterior', { rota: '/app/dashboard', formulario: false, beforeunload: 0 })
  await page.evaluate(`${NAV_BUTTON('Chamados críticos')}.click()`)
  await page.waitFor(`!!${button('Novo chamado')}`)
  await openNew(page); await makeDirty(page)
  await page.evaluate(`document.querySelector('[aria-label="Sair"]').click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT)); await sleep(400)
  report('sujo, Sair, Descartar: sai (logout chamado)', mock.requests.filter((r) => r.key === 'logout.php').length === logouts + 1)

  // Celular (390 px): o topo do formulario longo alcancavel e o fim tambem.
  await goto(page, port, '/chamados-criticos', 390)
  await page.waitFor(`!!${button('Novo chamado')}`)
  await openNew(page)
  const layout = await page.evaluate(`(() => { const backdrop = document.querySelector('[data-dialog-backdrop]'); backdrop.scrollTop = 0; const top = ${FORM_PANEL}.querySelector('h2').getBoundingClientRect().top; backdrop.scrollTop = backdrop.scrollHeight; const submit = ${SUBMIT}.getBoundingClientRect(); return { titleTop: top, submitVisible: submit.top >= 0 && submit.bottom <= innerHeight, pageScroll: document.documentElement.scrollWidth > innerWidth } })()`)
  report('390 px: titulo visivel no topo do formulario longo', layout.titleTop >= 0 && layout.titleTop < 200)
  report('390 px: rolando o fundo, o botao de salvar aparece inteiro', layout.submitVisible)
  report('390 px: sem rolagem horizontal da pagina', !layout.pageScroll)
  await makeDirty(page)
  await page.press('Escape')
  await expectState(page, '390 px, sujo, Esc: pergunta', { descartar: true, foco: 'Continuar editando' })
  await page.click(button('Descartar alterações', ALERT))
  await expectState(page, '390 px, Descartar: fecha, sem listener', { formulario: false, beforeunload: 0, keydown: 0 })
}

// 3. Chamados criticos: Visualizar e Importar (X1). Sem a protecao de alteracoes:
// Esc e clique fora fecham direto, inclusive com um arquivo escolhido.
async function criticalIncidentsViewAndImport(page, port, mock) {
  await goto(page, port, '/chamados-criticos')
  await page.waitFor(`!!${button('Visualizar')}`)
  const openView = async () => { await page.click(button('Visualizar')); await page.waitFor(`!!document.querySelector('[role="dialog"]')`) }
  const FECHAR = button('Fechar', `document.querySelector('[role="dialog"]')`)
  const sem = { descartar: false, beforeunload: 0 }
  for (const [how, exit] of [['Esc', () => page.press('Escape')], ['Fechar', () => page.click(FECHAR)], ['clique fora', () => page.clickAt(5, 450)]]) {
    await openView()
    await expectState(page, `Visualizar, abrir: role="dialog", foco no Fechar, um keydown`, { ...sem, titulo: 'INC-QA-004', modal: 'true', foco: 'Fechar', keydown: 1, dialogos: 1 })
    await exit()
    await expectState(page, `Visualizar, ${how}: fecha sem perguntar, foco volta ao "Visualizar"`, { ...sem, titulo: null, foco: 'Visualizar', keydown: 0, dialogos: 0 })
  }
  await openView()
  await page.press('Tab', true)
  await expectState(page, 'Visualizar: Shift+Tab no primeiro vai ao ultimo (link da sala)', { foco: 'Abrir sala de crise' })
  await page.press('Tab')
  await expectState(page, 'Visualizar: Tab no ultimo volta ao primeiro', { foco: 'Fechar' })
  const viewWidth = await page.evaluate(`document.querySelector('[role="dialog"]').getBoundingClientRect().width`)
  report('Visualizar: largura do painel (896 px em 1366)', viewWidth === 896, `largura ${viewWidth}`)
  await page.press('Escape')

  const openImport = async () => { await page.click(button('Importar planilha')); await page.waitFor(`!!document.querySelector('[role="dialog"]')`) }
  for (const [how, exit] of [['Esc', () => page.press('Escape')], ['Fechar', () => page.click(FECHAR)], ['clique fora', () => page.clickAt(5, 450)]]) {
    await openImport()
    await expectState(page, `Importar, abrir: role="dialog", foco no Fechar, um keydown`, { ...sem, titulo: 'Importar planilha War Room', modal: 'true', foco: 'Fechar', keydown: 1, dialogos: 1 })
    await exit()
    await expectState(page, `Importar, ${how}: fecha sem perguntar, foco volta ao "Importar planilha"`, { ...sem, titulo: null, foco: 'Importar planilha', keydown: 0, dialogos: 0 })
  }
  await openImport()
  await page.press('Tab')
  await expectState(page, 'Importar: Tab vai a area de arquivo', { foco: 'Clique ou arraste CSV/XLSX' })
  await page.press('Tab')
  await expectState(page, 'Importar: Tab na area de arquivo volta ao Fechar (o input escondido nao conta, o foco nao sai)', { foco: 'Fechar' })
  await page.press('Tab', true)
  await expectState(page, 'Importar: Shift+Tab no primeiro vai a area de arquivo', { foco: 'Clique ou arraste CSV/XLSX' })
  const importLayout = await page.evaluate(`(() => { const r = document.querySelector('[role="dialog"]').getBoundingClientRect(); return { width: r.width, right: r.right === document.documentElement.clientWidth, bottom: r.bottom === innerHeight } })()`)
  report('Importar: painel lateral colado a direita e ao fim da tela (672 px em 1366)', importLayout.width === 672 && importLayout.right && importLayout.bottom, `largura ${importLayout.width}`)

  const chooseFile = async (path) => {
    const { result } = await page.send('Runtime.evaluate', { expression: `document.querySelector('[role="dialog"] input[type="file"]')` })
    await page.send('DOM.setFileInputFiles', { files: [path], objectId: result.objectId })
    await page.waitFor(`document.querySelector('[role="dialog"]').textContent.includes('válida(s)')`)
  }
  const dir = mkdtempSync(join(tmpdir(), 'chronodesk-qa-import-'))
  try {
    const csv = join(dir, 'planilha-ficticia.csv')
    writeFileSync(csv, 'INCIDENTE;Data da Sala\nINC-QA-IMP;2026-10-28\n')
    await chooseFile(csv)
    report('Importar, arquivo escolhido: preview na tela', await page.evaluate(`document.querySelector('[role="dialog"]').textContent.includes('INC-QA-IMP')`))
    await page.press('Escape')
    await expectState(page, 'Importar, arquivo escolhido, Esc: fecha sem perguntar (so perde a escolha)', { ...sem, titulo: null, foco: 'Importar planilha', keydown: 0 })
    await openImport()
    report('Importar, reabrir: sem a escolha anterior', !(await page.evaluate(`document.querySelector('[role="dialog"]').textContent.includes('INC-QA-IMP')`)))
    await chooseFile(csv)
    const confirms = mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/critical_incidents_import.php').length
    await page.click(button('Confirmar importação', `document.querySelector('[role="dialog"]')`))
    await page.waitFor(`!document.querySelector('[role="dialog"]')`)
    await expectState(page, 'Importar, confirmar: fecha, foco volta ao "Importar planilha"', { ...sem, titulo: null, foco: 'Importar planilha', keydown: 0 })
    report('Importar, confirmar: um POST', mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/critical_incidents_import.php').length === confirms + 1)
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }

  await goto(page, port, '/chamados-criticos', 390)
  await page.waitFor(`!!${button('Visualizar')}`)
  await openView()
  report('390 px, Visualizar: sem rolagem horizontal da pagina', await page.evaluate(`document.documentElement.scrollWidth <= innerWidth`))
  await page.press('Escape')
  await expectState(page, '390 px, Visualizar, Esc: fecha, foco volta', { ...sem, titulo: null, foco: 'Visualizar', keydown: 0 })
  await openImport()
  const importSmall = await page.evaluate(`document.querySelector('[role="dialog"]').getBoundingClientRect().width`)
  report('390 px, Importar: painel na largura toda', importSmall === 390, `largura ${importSmall}`)
  await page.press('Escape')
  await expectState(page, '390 px, Importar, Esc: fecha, foco volta', { ...sem, titulo: null, foco: 'Importar planilha', keydown: 0 })
}

// 4. Funcionarios (tela real): editar (Dialog) e adicionar (formulario na tela).
const EMPLOYEE_PANEL = `[...document.querySelectorAll('[role="dialog"]')].find((el) => el.querySelector('h2')?.textContent === 'Editar funcionário')`
const EMPLOYEE_NAME = `${EMPLOYEE_PANEL}.querySelector('input[minlength="3"]')`
const EMPLOYEE_HEADER_CANCEL = `${EMPLOYEE_PANEL}.firstElementChild.querySelector(':scope > button')`
const EMPLOYEE_BOTTOM_CANCEL = button('Cancelar', `${EMPLOYEE_PANEL}.querySelector('form')`)
const EMPLOYEE_SUBMIT = `${EMPLOYEE_PANEL}.querySelector('form button[type="submit"]')`
const CREATE_FORM = `[...document.querySelectorAll('form')].find((form) => !form.closest('[data-dialog-backdrop]') && form.querySelector('input[minlength="3"]'))`
const CREATE_NAME = `${CREATE_FORM}.querySelector('input[minlength="3"]')`
const CREATE_ID = `${CREATE_FORM}.querySelector('input[type="number"]')`
const CREATE_SUBMIT = `${CREATE_FORM}.querySelector('button[type="submit"]')`
const ROW_EDIT = (index) => `[...document.querySelectorAll('button')].filter((b) => b.offsetParent !== null && b.textContent.trim() === 'Editar')[${index}]`
const TAB = (label) => `[...document.querySelectorAll('button.tab-button')].find((b) => b.textContent.trim() === ${JSON.stringify(label)})`
const EMPLOYEE_STATE = `(() => {
  const active = document.activeElement
  const panel = ${EMPLOYEE_PANEL}
  const edits = [...document.querySelectorAll('button')].filter((b) => b.offsetParent !== null && b.textContent.trim() === 'Editar')
  const create = ${CREATE_FORM}
  return {
    rota: location.pathname,
    busca: location.search,
    edicao: !!panel,
    modal: panel?.getAttribute('aria-modal') ?? null,
    descartar: document.querySelectorAll('[role="alertdialog"]').length,
    foco: !active || active === document.body ? 'body' : active.tagName === 'INPUT' ? 'campo:' + (active.closest('label')?.childNodes[0]?.textContent.trim() || '') : (active.textContent || '').trim(),
    linha_do_foco: edits.indexOf(active),
    id: panel ? panel.querySelector('input[type="number"]').value : null,
    nome: panel ? panel.querySelector('input[minlength="3"]').value : null,
    novo_nome: create ? create.querySelector('input[minlength="3"]').value : null,
    beforeunload: (getEventListeners(window).beforeunload || []).length,
    keydown: (getEventListeners(document).keydown || []).length,
    dialogos: document.querySelectorAll('[data-dialog-backdrop]').length,
    aviso_erro: [...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada')),
  }
})()`
// Troca um select controlado como o usuario troca (o React so ve o evento change).
const setSelect = (scope, label, value) => `(() => { const select = [...${scope}.querySelectorAll('label')].find((item) => item.childNodes[0]?.textContent.trim() === ${JSON.stringify(label)}).querySelector('select'); Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, ${JSON.stringify(value)}); select.dispatchEvent(new Event('change', { bubbles: true })) })()`

async function employees(page, port, mock) {
  const expectEmployee = (label, expected) => expectState(page, label, expected, EMPLOYEE_STATE)
  const openFromList = async () => {
    await goto(page, port, '/dashboard')
    await page.evaluate(`${NAV_BUTTON('Aprovações e admin')}.click()`)
    await page.waitFor(`!!${TAB('Funcionários')}`)
    await page.evaluate(`${TAB('Funcionários')}.click()`)
    await page.waitFor(`!!${ROW_EDIT(0)}`)
    await sleep(200)
  }
  const openEdit = async (index = 0) => {
    await page.click(ROW_EDIT(index))
    await page.waitFor(`!!${EMPLOYEE_PANEL}`)
  }
  const dirtyEdit = async (text = ' editado') => {
    await page.evaluate(`${EMPLOYEE_NAME}.focus()`)
    await page.typeText(text)
  }
  const dirtyCreate = async (text = 'Rascunho QA') => {
    await page.evaluate(`${CREATE_NAME}.focus()`)
    await page.typeText(text)
  }
  await openFromList()
  const originalName = await page.evaluate(`[...document.querySelectorAll('tbody strong')][0].textContent`)

  // Abrir e fechar sem mexer nao conta como sujo, em cadastros diferentes.
  for (const index of [0, 1, 2, 3]) {
    await openEdit(index)
    await expectEmployee(`editar, cadastro ${index + 1}, abrir: role="dialog", foco no Nome, um keydown, nao esta sujo`, { edicao: true, modal: 'true', foco: 'campo:Nome', keydown: 1, beforeunload: 0, id: String(index + 1) })
    await page.press('Escape')
    await expectEmployee(`editar, cadastro ${index + 1}, Esc: fecha sem perguntar, foco volta ao Editar dele`, { edicao: false, descartar: 0, foco: 'Editar', linha_do_foco: index, keydown: 0, dialogos: 0 })
  }

  const exits = [
    ['Esc', () => page.press('Escape')],
    ['Cancelar do cabecalho', () => page.click(EMPLOYEE_HEADER_CANCEL)],
    ['Cancelar do fim', () => page.click(EMPLOYEE_BOTTOM_CANCEL)],
    ['clique fora', () => page.clickAt(5, 450)],
  ]
  for (const [how, exit] of exits) {
    await openEdit()
    await exit()
    await expectEmployee(`editar limpo, ${how}: fecha sem perguntar, foco volta ao Editar`, { edicao: false, descartar: 0, foco: 'Editar', linha_do_foco: 0, keydown: 0, dialogos: 0 })
  }
  const dirtyName = `${originalName} editado`
  for (const [how, exit] of exits) {
    await openEdit()
    await dirtyEdit()
    await expectEmployee(`editar sujo (${how}): beforeunload registrado`, { beforeunload: 1 })
    await exit()
    await expectEmployee(`editar sujo, ${how}: pergunta, foco em "Continuar editando"`, { edicao: true, descartar: 1, foco: 'Continuar editando', dialogos: 2 })
    await page.press('Escape')
    await expectEmployee(`editar sujo, ${how}, Esc no aviso: continua, dados mantidos`, { edicao: true, descartar: 0, nome: dirtyName })
    await exit()
    await page.clickAt(5, 450)
    await expectEmployee(`editar sujo, ${how}, clique fora do aviso: continua`, { edicao: true, descartar: 0, nome: dirtyName })
    await exit()
    await page.click(button('Continuar editando', ALERT))
    await expectEmployee(`editar sujo, ${how}, "Continuar editando": dados mantidos`, { edicao: true, descartar: 0, nome: dirtyName })
    await exit()
    await page.click(button('Descartar alterações', ALERT))
    await expectEmployee(`editar sujo, ${how}, "Descartar": fecha, foco volta, sem listener`, { edicao: false, descartar: 0, foco: 'Editar', beforeunload: 0, keydown: 0, dialogos: 0 })
  }

  // Sujo acompanha o valor: voltar ao de abertura limpa de novo.
  await openEdit()
  await dirtyEdit('x')
  await expectEmployee('editar, texto mudou: sujo', { beforeunload: 1 })
  await page.evaluate(`${EMPLOYEE_NAME}.select()`)
  await page.typeText(originalName)
  await expectEmployee('editar, texto de volta ao de abertura: limpo', { beforeunload: 0 })
  await page.evaluate(setSelect(EMPLOYEE_PANEL, 'Equipe operacional', 'n2'))
  await sleep(150)
  await expectEmployee('editar, equipe mudou (select): sujo', { beforeunload: 1 })
  await page.evaluate(setSelect(EMPLOYEE_PANEL, 'Equipe operacional', 'n1'))
  await sleep(150)
  await expectEmployee('editar, equipe de volta: limpo', { beforeunload: 0 })
  await page.evaluate(setSelect(EMPLOYEE_PANEL, 'Perfil de acesso', 'gestor'))
  await sleep(150)
  await expectEmployee('editar, perfil mudou (select): sujo', { beforeunload: 1 })
  await page.evaluate(setSelect(EMPLOYEE_PANEL, 'Perfil de acesso', 'tecnico'))
  await sleep(150)
  await expectEmployee('editar, perfil de volta: limpo', { beforeunload: 0 })
  await page.click(`${EMPLOYEE_PANEL}.querySelector('input[type="checkbox"]')`)
  await expectEmployee('editar, "Fora do Mapa PA" marcado: sujo', { beforeunload: 1 })
  await page.click(`${EMPLOYEE_PANEL}.querySelector('input[type="checkbox"]')`)
  await expectEmployee('editar, "Fora do Mapa PA" desmarcado: limpo', { beforeunload: 0 })
  await page.press('Escape')
  await expectEmployee('editar, limpo de novo, Esc: fecha sem perguntar', { edicao: false, descartar: 0 })

  // Foco preso no formulario.
  await openEdit()
  await page.evaluate(`${EMPLOYEE_SUBMIT}.focus()`)
  await page.press('Tab')
  await expectEmployee('editar: Tab no ultimo volta ao primeiro (Cancelar do cabecalho)', { foco: 'Cancelar' })
  await page.press('Tab', true)
  await expectEmployee('editar: Shift+Tab no primeiro vai ao ultimo', { foco: 'Salvar alterações' })

  // Erro do servidor mantem os dados; salvar com sucesso fecha sem perguntar.
  await page.evaluate(`${EMPLOYEE_NAME}.select()`)
  await page.typeText(FAILING_EMPLOYEE)
  await page.click(EMPLOYEE_SUBMIT)
  await page.waitFor(`[...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada'))`)
  await expectEmployee('editar, erro do servidor: aviso de erro, formulario aberto, dados mantidos, ainda sujo', { aviso_erro: true, edicao: true, nome: FAILING_EMPLOYEE, beforeunload: 1 })
  await page.press('Escape')
  await expectEmployee('editar, erro do servidor, Esc: ainda pergunta', { descartar: 1 })
  await page.click(button('Continuar editando', ALERT))
  await page.evaluate(`${EMPLOYEE_NAME}.select()`)
  await page.typeText('Tecnico Salvo QA')
  const updates = mock.requests.filter((r) => r.method === 'POST' && r.key === 'atualizar_funcionario.php').length
  await page.click(EMPLOYEE_SUBMIT)
  await page.waitFor(`!${EMPLOYEE_PANEL}`)
  await expectEmployee('editar, salvar com sucesso: fecha sem perguntar, foco volta, sem listener', { edicao: false, descartar: 0, foco: 'Editar', linha_do_foco: 0, beforeunload: 0, keydown: 0, dialogos: 0 })
  report('editar, salvar com sucesso: um POST', mock.requests.filter((r) => r.method === 'POST' && r.key === 'atualizar_funcionario.php').length === updates + 1)

  // "Editar outro funcionario" com o formulario sujo. Com o dialogo aberto, o
  // mouse nao alcanca a lista (o fundo cobre) e o Tab nao sai dele; o caminho
  // do botao e exercitado pelo clique no elemento.
  await openEdit(0)
  await dirtyEdit()
  const reachable = await page.evaluate(`(() => { const target = ${ROW_EDIT(1)}; const r = target.getBoundingClientRect(); return document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === target })()`)
  report('editar outro, sujo: com o dialogo aberto, o mouse nao alcanca o Editar de outra linha', !reachable)
  await page.evaluate(`${ROW_EDIT(1)}.click()`)
  await sleep(200)
  await expectEmployee('editar outro, sujo: pergunta e nao troca de cadastro', { edicao: true, descartar: 1, id: '1', nome: dirtyName, foco: 'Continuar editando' })
  await page.click(button('Continuar editando', ALERT))
  await expectEmployee('editar outro, sujo, "Continuar editando": mesmo cadastro, dados mantidos', { edicao: true, descartar: 0, id: '1', nome: dirtyName, beforeunload: 1 })
  await page.evaluate(`${ROW_EDIT(1)}.click()`)
  await sleep(200)
  await page.click(button('Descartar alterações', ALERT))
  const secondName = await page.evaluate(`[...document.querySelectorAll('tbody strong')][1].textContent`)
  await expectEmployee('editar outro, sujo, "Descartar": abre o outro cadastro, limpo', { edicao: true, descartar: 0, id: '2', nome: secondName, beforeunload: 0, dialogos: 1 })
  await page.evaluate(`${ROW_EDIT(2)}.click()`)
  await sleep(200)
  await expectEmployee('editar outro, limpo: troca sem perguntar', { edicao: true, descartar: 0, id: '3', beforeunload: 0 })
  await page.press('Escape')
  await expectEmployee('editar outro, Esc: foco volta ao Editar da ultima linha aberta', { edicao: false, foco: 'Editar', linha_do_foco: 2, keydown: 0 })

  // Rota com a edicao suja: menu, aba, voltar, "Sair" e recarregar.
  await page.evaluate(`${TAB('Aprovações')}.click()`); await sleep(250)
  await page.evaluate(`${TAB('Funcionários')}.click()`)
  await page.waitFor(`!!${ROW_EDIT(0)}`)
  await openEdit(); await dirtyEdit()
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await expectEmployee('editar sujo, menu: pergunta e nao troca de tela', { descartar: 1, rota: '/app/admin', busca: '?tab=funcionarios' })
  await page.click(button('Continuar editando', ALERT))
  await expectEmployee('editar sujo, menu, Continuar: fica, dados mantidos', { rota: '/app/admin', edicao: true, nome: dirtyName })
  await page.evaluate('history.back()'); await sleep(400)
  await expectEmployee('editar sujo, voltar: pergunta, endereco devolvido', { descartar: 1, rota: '/app/admin', busca: '?tab=funcionarios', edicao: true })
  await page.click(button('Continuar editando', ALERT))
  const logouts = mock.requests.filter((r) => r.key === 'logout.php').length
  await page.evaluate(`document.querySelector('[aria-label="Sair"]').click()`); await sleep(300)
  await expectEmployee('editar sujo, Sair: pergunta', { descartar: 1 })
  await page.click(button('Continuar editando', ALERT))
  report('editar sujo, Sair, Continuar: nao sai (sem logout)', mock.requests.filter((r) => r.key === 'logout.php').length === logouts)
  page.events.length = 0
  await page.send('Page.reload').catch(() => {})
  await sleep(800)
  const editPrompt = page.events.find((e) => e.method === 'Page.javascriptDialogOpening')
  report('editar sujo, recarregar: aviso do navegador (beforeunload)', editPrompt?.params.type === 'beforeunload')
  if (editPrompt) { await page.send('Page.handleJavaScriptDialog', { accept: false }); await sleep(300) }
  await expectEmployee('editar sujo, recarregar, ficar: dados mantidos', { edicao: true, nome: dirtyName })
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT))
  await expectEmployee('editar sujo, menu, Descartar: troca de tela, sem listener', { rota: '/app/calendario', edicao: false, beforeunload: 0, keydown: 0 })

  // Adicionar: o formulario fica na tela, sem dialogo.
  await openFromList()
  await expectEmployee('adicionar, abrir: limpo, sem dialogo nem listener', { novo_nome: '', beforeunload: 0, dialogos: 0, keydown: 0 })
  await page.evaluate(`${TAB('Aprovações')}.click()`); await sleep(250)
  await expectEmployee('adicionar limpo, trocar de aba: sem perguntar', { busca: '?tab=aprovacoes', descartar: 0 })
  await page.evaluate(`${TAB('Funcionários')}.click()`)
  await page.waitFor(`!!${CREATE_FORM}`)
  await dirtyCreate()
  await expectEmployee('adicionar sujo: beforeunload registrado', { novo_nome: 'Rascunho QA', beforeunload: 1 })
  await page.evaluate(`${CREATE_NAME}.select()`)
  await page.send('Input.insertText', { text: '' }); await page.evaluate(`document.execCommand('delete')`); await sleep(150)
  await expectEmployee('adicionar, texto apagado: limpo', { novo_nome: '', beforeunload: 0 })
  await dirtyCreate()
  await page.evaluate(`${TAB('Configurações')}.click()`); await sleep(300)
  await expectEmployee('adicionar sujo, trocar de aba: pergunta, fica na aba', { descartar: 1, busca: '?tab=funcionarios', foco: 'Continuar editando' })
  await page.click(button('Continuar editando', ALERT))
  await expectEmployee('adicionar sujo, aba, Continuar: dados mantidos', { descartar: 0, busca: '?tab=funcionarios', novo_nome: 'Rascunho QA' })
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await expectEmployee('adicionar sujo, menu: pergunta e nao troca de tela', { descartar: 1, rota: '/app/admin' })
  await page.press('Escape')
  await expectEmployee('adicionar sujo, menu, Esc no aviso: dados mantidos', { descartar: 0, rota: '/app/admin', novo_nome: 'Rascunho QA' })
  await page.evaluate('history.back()'); await sleep(400)
  await expectEmployee('adicionar sujo, voltar: pergunta, endereco devolvido', { descartar: 1, rota: '/app/admin', busca: '?tab=funcionarios' })
  await page.click(button('Continuar editando', ALERT))
  const logoutsCreate = mock.requests.filter((r) => r.key === 'logout.php').length
  await page.evaluate(`document.querySelector('[aria-label="Sair"]').click()`); await sleep(300)
  await expectEmployee('adicionar sujo, Sair: pergunta', { descartar: 1 })
  await page.click(button('Continuar editando', ALERT))
  report('adicionar sujo, Sair, Continuar: nao sai (sem logout)', mock.requests.filter((r) => r.key === 'logout.php').length === logoutsCreate)
  page.events.length = 0
  await page.send('Page.reload').catch(() => {})
  await sleep(800)
  const createPrompt = page.events.find((e) => e.method === 'Page.javascriptDialogOpening')
  report('adicionar sujo, recarregar: aviso do navegador (beforeunload)', createPrompt?.params.type === 'beforeunload')
  if (createPrompt) { await page.send('Page.handleJavaScriptDialog', { accept: false }); await sleep(300) }
  await expectEmployee('adicionar sujo, recarregar, ficar: dados mantidos', { novo_nome: 'Rascunho QA' })

  // Os dois formularios: cada um com a sua protecao.
  await openEdit(1)
  await expectEmployee('adicionar sujo, Editar: abre sem perguntar (nada se perde)', { edicao: true, descartar: 0, id: '2', beforeunload: 1 })
  await page.press('Escape')
  await expectEmployee('adicionar sujo, edicao limpa, Esc: fecha sem perguntar, adicionar mantido', { edicao: false, descartar: 0, novo_nome: 'Rascunho QA', beforeunload: 1 })
  await openEdit(1)
  await dirtyEdit()
  await expectEmployee('os dois sujos: dois beforeunload', { beforeunload: 2 })
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await expectEmployee('os dois sujos, menu: um aviso so', { descartar: 1, rota: '/app/admin' })
  await page.click(button('Continuar editando', ALERT))
  await page.click(EMPLOYEE_BOTTOM_CANCEL)
  await expectEmployee('os dois sujos, Cancelar a edicao: pergunta', { descartar: 1, edicao: true })
  await page.click(button('Descartar alterações', ALERT))
  await expectEmployee('os dois sujos, Descartar a edicao: fecha so a edicao, adicionar mantido', { edicao: false, descartar: 0, novo_nome: 'Rascunho QA', beforeunload: 1 })

  // Erro do servidor mantem o adicionar; sucesso limpa, sem perguntar.
  await page.evaluate(`${CREATE_ID}.focus()`)
  await page.typeText('900')
  await page.evaluate(`${CREATE_NAME}.select()`)
  await page.typeText(FAILING_EMPLOYEE)
  await page.click(CREATE_SUBMIT)
  await page.waitFor(`[...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada'))`)
  await expectEmployee('adicionar, erro do servidor: aviso de erro, dados mantidos, ainda sujo', { aviso_erro: true, novo_nome: FAILING_EMPLOYEE, beforeunload: 1 })
  await page.evaluate(`${CREATE_NAME}.select()`)
  await page.typeText('Tecnico Novo QA')
  const creates = mock.requests.filter((r) => r.method === 'POST' && r.key === 'adicionar_funcionario.php').length
  await page.click(CREATE_SUBMIT)
  await page.waitFor(`${CREATE_NAME}.value === ''`)
  await expectEmployee('adicionar com sucesso: formulario limpo, sem perguntar, sem listener', { novo_nome: '', descartar: 0, beforeunload: 0, dialogos: 0 })
  report('adicionar com sucesso: um POST', mock.requests.filter((r) => r.method === 'POST' && r.key === 'adicionar_funcionario.php').length === creates + 1)
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await expectEmployee('depois de adicionar, menu: troca de tela sem perguntar', { rota: '/app/calendario', descartar: 0 })
  await page.evaluate('history.back()'); await page.waitFor(`!!${CREATE_FORM}`)
  await dirtyCreate()
  await page.evaluate(`${TAB('Aprovações')}.click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT))
  await expectEmployee('adicionar sujo, aba, Descartar: troca de aba, sem listener', { busca: '?tab=aprovacoes', descartar: 0, beforeunload: 0 })
  await page.evaluate(`${TAB('Funcionários')}.click()`)
  await page.waitFor(`!!${CREATE_FORM}`)
  await expectEmployee('adicionar, voltar a aba depois de descartar: vazio', { novo_nome: '', beforeunload: 0 })
  await dirtyCreate()
  const logoutsDiscard = mock.requests.filter((r) => r.key === 'logout.php').length
  await page.evaluate(`document.querySelector('[aria-label="Sair"]').click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT)); await sleep(400)
  report('adicionar sujo, Sair, Descartar: sai (logout chamado)', mock.requests.filter((r) => r.key === 'logout.php').length === logoutsDiscard + 1)

  // Celular (390 px): cartoes, topo e fim do formulario alcancaveis.
  await goto(page, port, '/admin?tab=funcionarios', 390)
  await page.waitFor(`!!${ROW_EDIT(0)}`)
  await openEdit()
  await expectEmployee('390 px, abrir: foco no Nome', { edicao: true, foco: 'campo:Nome', beforeunload: 0 })
  const layout = await page.evaluate(`(() => { const backdrop = document.querySelector('[data-dialog-backdrop]'); backdrop.scrollTop = 0; const top = ${EMPLOYEE_PANEL}.querySelector('h2').getBoundingClientRect().top; backdrop.scrollTop = backdrop.scrollHeight; const submit = ${EMPLOYEE_SUBMIT}.getBoundingClientRect(); return { titleTop: top, submitVisible: submit.top >= 0 && submit.bottom <= innerHeight, pageScroll: document.documentElement.scrollWidth > innerWidth } })()`)
  report('390 px: titulo visivel no topo do formulario', layout.titleTop >= 0 && layout.titleTop < 200)
  report('390 px: rolando o fundo, o botao de salvar aparece inteiro', layout.submitVisible)
  report('390 px: sem rolagem horizontal da pagina', !layout.pageScroll)
  await dirtyEdit()
  await page.press('Escape')
  await expectEmployee('390 px, sujo, Esc: pergunta', { descartar: 1, foco: 'Continuar editando' })
  await page.click(button('Descartar alterações', ALERT))
  await expectEmployee('390 px, Descartar: fecha, foco volta ao Editar, sem listener', { edicao: false, foco: 'Editar', linha_do_foco: 0, beforeunload: 0, keydown: 0 })

  // Lideranca: o "Fora do Mapa PA" fica desabilitado e nao pode contar como sujo.
  mock.useLeadershipSession()
  try {
    await goto(page, port, '/admin?tab=funcionarios')
    await page.waitFor(`!!${ROW_EDIT(0)}`)
    const editable = await page.evaluate(`[...document.querySelectorAll('button')].filter((b) => b.offsetParent !== null && b.textContent.trim() === 'Editar').length`)
    for (let index = 0; index < editable; index++) {
      await openEdit(index)
      const disabled = await page.evaluate(`${EMPLOYEE_PANEL}.querySelector('input[type="checkbox"]').disabled`)
      await expectEmployee(`Lideranca, cadastro ${index + 1}, abrir: nao esta sujo`, { edicao: true, beforeunload: 0 })
      report(`Lideranca, cadastro ${index + 1}: "Fora do Mapa PA" desabilitado`, disabled)
      await page.press('Escape')
      await expectEmployee(`Lideranca, cadastro ${index + 1}, Esc: fecha sem perguntar`, { edicao: false, descartar: 0, keydown: 0 })
    }
  } finally {
    mock.useAdminSession()
  }
}

// 5. [Z2] Com qualquer Dialog aberto, o topo e as bordas da janela caem no fundo
// ou no painel, nunca na pagina (tambem com a pagina rolada ate o fim).
const COVERAGE = `(() => {
  const width = document.documentElement.clientWidth
  const height = innerHeight
  const backdrop = [...document.querySelectorAll('[data-dialog-backdrop]')].at(-1)
  const points = []
  for (const y of [2, 10, 19]) for (const x of [2, Math.floor(width / 2), width - 3]) points.push([x, y])
  for (const y of [Math.floor(height / 2), height - 3]) for (const x of [2, width - 3]) points.push([x, y])
  points.push([Math.floor(width / 2), height - 3])
  const wrong = points.filter(([x, y]) => { const el = document.elementFromPoint(x, y); return !el || !(el === backdrop || el.closest('[role="dialog"],[role="alertdialog"]')) }).map(([x, y]) => x + ',' + y)
  const rect = backdrop.getBoundingClientRect()
  return { pontos: wrong, cobre: rect.top === 0 && rect.left === 0 && rect.right === width && rect.bottom === height, no_body: backdrop.parentElement === document.body }
})()`
async function expectCoverage(page, label) {
  const covered = await page.evaluate(COVERAGE)
  report(`${label}: 14 pontos do topo e das bordas no fundo ou no painel`, covered.pontos.length === 0, `pontos: ${covered.pontos.join(' ')}`)
  report(`${label}: fundo do tamanho da janela, direto no body`, covered.cobre && covered.no_body)
  await page.evaluate('window.scrollTo(0, document.documentElement.scrollHeight)')
  await sleep(100)
  const scrolled = await page.evaluate(COVERAGE)
  report(`${label}, pagina rolada: 14 pontos no fundo ou no painel`, scrolled.pontos.length === 0, `pontos: ${scrolled.pontos.join(' ')}`)
}

async function dialogCoverage(page, port) {
  const anyDialog = `!!document.querySelector('[role="dialog"]')`
  const dirtyThenAsk = (field) => async () => {
    await page.evaluate(`document.querySelector('[role="dialog"] ${field}').focus()`)
    await page.typeText('x')
    await page.press('Escape')
    await page.waitFor(`!!${ALERT}`)
  }
  const discard = async () => { await page.click(button('Descartar alterações', ALERT)); await sleep(200) }
  const closeAll = async () => { await page.press('Escape'); await sleep(200) }
  const scenarios = [
    ['Chamados, novo', '/chamados-criticos', 'Novo chamado', null, closeAll],
    ['Chamados, editar', '/chamados-criticos', 'Editar', null, closeAll],
    ['Chamados, Visualizar', '/chamados-criticos', 'Visualizar', null, closeAll],
    ['Chamados, Importar', '/chamados-criticos', 'Importar planilha', null, closeAll],
    ['Chamados, descarte', '/chamados-criticos', 'Novo chamado', dirtyThenAsk('input[maxlength="100"]'), discard],
    ['Funcionarios, editar', '/admin?tab=funcionarios', 'Editar', null, closeAll],
    ['Funcionarios, descarte', '/admin?tab=funcionarios', 'Editar', dirtyThenAsk('input[minlength="3"]'), discard],
  ]
  for (const width of [390, 1366]) {
    for (const [name, path, opener, afterOpen, close] of scenarios) {
      await goto(page, port, path, width)
      await page.waitFor(`!!${button(opener)}`)
      await page.click(`[...document.querySelectorAll('button')].filter((b) => b.getClientRects().length && b.textContent.trim() === ${JSON.stringify(opener)})[0]`)
      await page.waitFor(anyDialog)
      await sleep(150)
      if (afterOpen) await afterOpen()
      await expectCoverage(page, `${width} px, ${name}`)
      await close()
    }
  }
  // Clique de verdade na faixa do topo: fecha, em vez de alcancar a pagina.
  await goto(page, port, '/chamados-criticos')
  await page.click(button('Visualizar'))
  await page.waitFor(anyDialog)
  await page.clickAt(683, 10)
  await expectState(page, 'clique na faixa do topo (y = 10): fecha o dialogo, foco volta ao "Visualizar"', { dialogos: 0, foco: 'Visualizar' })
}

let chrome = null
let mock = null
let page = null
try {
  if (!appDir) {
    tempBuild = mkdtempSync(join(tmpdir(), 'chronodesk-qa-build-'))
    const build = spawnSync(process.execPath, [join(frontendRoot, 'node_modules', 'vite', 'bin', 'vite.js'), 'build', '--outDir', tempBuild, '--emptyOutDir', '--logLevel', 'error'], { cwd: frontendRoot, stdio: 'inherit' })
    if (build.status !== 0) throw new Error('build do frontend falhou')
    appDir = tempBuild
  }
  mock = await startMockServer(appDir)
  chrome = await launchChrome()
  page = await openPage(chrome.port)
  console.log('== Navegacao sem formulario sujo')
  await navigationWithoutDirtyForm(page, mock.port, mock)
  console.log('== Chamados criticos (novo e editar)')
  await criticalIncidents(page, mock.port, mock)
  console.log('== Chamados criticos (Visualizar e Importar)')
  await criticalIncidentsViewAndImport(page, mock.port, mock)
  console.log('== Funcionarios (editar e adicionar)')
  await employees(page, mock.port, mock)
  console.log('== Fundo dos dialogos (Z2)')
  await dialogCoverage(page, mock.port)
} catch (error) {
  failed++
  console.log(`FALHOU  execucao interrompida: ${error.message}`)
} finally {
  if (page) await page.close()
  if (chrome) await chrome.close()
  if (mock) await mock.close()
  if (tempBuild) rmSync(tempBuild, { recursive: true, force: true })
}
console.log(`conferencias: ${passed + failed}, passaram: ${passed}, falharam: ${failed}`)
process.exit(failed ? 1 : 0)
