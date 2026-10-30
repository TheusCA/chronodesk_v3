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
import { FAILING_ABSENCE_NOTE, FAILING_EMPLOYEE, FAILING_INCIDENT, startMockServer } from './lib/mock-server.mjs'

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

// 5. Ausencias (tela real): o formulario fica na tela, sem Dialog.
const ABSENCE_FORM = `[...document.querySelectorAll('main form')].find((form) => form.querySelector('textarea'))`
const ABSENCE_NOTE = `${ABSENCE_FORM}.querySelector('textarea')`
const ABSENCE_SUBMIT = `${ABSENCE_FORM}.querySelector('button[type="submit"]')`
const ABSENCE_CLOSE = `${ABSENCE_FORM}.querySelector('button[type="button"]')`
const ROW_BUTTON = (text, index = 0) => `[...document.querySelectorAll('main button')].filter((b) => b.offsetParent !== null && b.textContent.trim() === ${JSON.stringify(text)})[${index}]`
const COMMON_STATE = `
    rota: location.pathname,
    descartar: document.querySelectorAll('[role="alertdialog"]').length,
    foco: !document.activeElement || document.activeElement === document.body ? 'body' : (document.activeElement.textContent || '').trim(),
    beforeunload: (getEventListeners(window).beforeunload || []).length,
    keydown: (getEventListeners(document).keydown || []).length,
    dialogos: document.querySelectorAll('[data-dialog-backdrop]').length,
    aviso_erro: [...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada')),`
const ABSENCE_STATE = `(() => {
  const form = ${ABSENCE_FORM}
  return {${COMMON_STATE}
    aberto: !!form,
    titulo: form?.querySelector('h2')?.textContent ?? null,
    tecnico: form?.querySelector('p.field')?.textContent ?? null,
    nota: form ? form.querySelector('textarea').value : null,
    categoria: form ? [...form.querySelectorAll('select')].at(-1).value : null,
  }
})()`

// Abre pelo menu: o "voltar" fica dentro do app (sem isso, sairia para a pagina anterior do navegador).
async function openFromMenu(page, port, label, width) {
  await goto(page, port, '/dashboard', width)
  if (width < 1024) { await page.evaluate(`document.querySelector('[aria-label="Abrir menu"]').click()`); await sleep(200) }
  await page.evaluate(`${NAV_BUTTON(label)}.click()`)
  await sleep(300)
}

async function leaveByRoute(page, mock, expectLocal, stayed) {
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await expectLocal('menu: pergunta e nao troca de tela', { descartar: 1, ...stayed })
  await page.click(button('Continuar editando', ALERT))
  await expectLocal('menu, Continuar: fica, dados mantidos', { descartar: 0, ...stayed })
  await page.evaluate('history.back()'); await sleep(400)
  await expectLocal('voltar: pergunta, endereco devolvido', { descartar: 1, ...stayed })
  await page.press('Escape')
  await expectLocal('voltar, Esc no aviso: fica', { descartar: 0, ...stayed })
  const logouts = mock.requests.filter((r) => r.key === 'logout.php').length
  await page.evaluate(`document.querySelector('[aria-label="Sair"]').click()`); await sleep(300)
  await expectLocal('Sair: pergunta', { descartar: 1 })
  await page.click(button('Continuar editando', ALERT))
  report('Sair, Continuar: nao sai (sem logout)', mock.requests.filter((r) => r.key === 'logout.php').length === logouts)
  page.events.length = 0
  await page.send('Page.reload').catch(() => {})
  await sleep(800)
  const prompt = page.events.find((e) => e.method === 'Page.javascriptDialogOpening')
  report('recarregar: aviso do navegador (beforeunload)', prompt?.params.type === 'beforeunload')
  if (prompt) { await page.send('Page.handleJavaScriptDialog', { accept: false }); await sleep(300) }
  await expectLocal('recarregar, ficar: dados mantidos', { descartar: 0, ...stayed })
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT)); await sleep(300)
  await expectLocal('menu, Descartar: troca de tela, sem listener', { rota: '/app/calendario', descartar: 0, beforeunload: 0, keydown: 0 })
}

async function absences(page, port, mock) {
  const expectAbsence = (label, expected) => expectState(page, `Ausencias, ${label}`, expected, ABSENCE_STATE)
  const openNew = async () => { await page.click(ROW_BUTTON('Nova ausência')); await page.waitFor(`!!${ABSENCE_FORM}`) }
  const openEdit = async (index) => { await page.click(ROW_BUTTON('Editar', index)); await sleep(200) }
  const dirty = async (text = 'Rascunho QA') => { await page.evaluate(`${ABSENCE_NOTE}.focus()`); await page.send('Input.insertText', { text }); await sleep(150) }
  const open = async (width = 1366) => {
    await openFromMenu(page, port, 'Ausências', width)
    await page.waitFor(`!!${ROW_BUTTON('Nova ausência')} && !!${ROW_BUTTON('Editar')}`)
  }
  await open()
  await expectAbsence('abrir a tela: sem listener', { aberto: false, beforeunload: 0, keydown: 0, dialogos: 0 })

  // Novo: limpo fecha direto; cada tipo de campo suja; voltar ao de abertura limpa.
  await openNew()
  await expectAbsence('novo, abrir: limpo', { aberto: true, titulo: 'Nova ausência', beforeunload: 0 })
  await page.click(ABSENCE_CLOSE)
  await expectAbsence('novo limpo, Fechar: fecha sem perguntar', { aberto: false, descartar: 0, beforeunload: 0 })
  await openNew()
  await dirty('x')
  await expectAbsence('novo, observacao mudou: sujo', { beforeunload: 1 })
  await page.evaluate(`${ABSENCE_NOTE}.select()`)
  await page.evaluate(`document.execCommand('delete')`); await sleep(150)
  await expectAbsence('novo, observacao apagada: limpo', { nota: '', beforeunload: 0 })
  await page.evaluate(setSelect(ABSENCE_FORM, 'Categoria', 'ferias')); await sleep(150)
  await expectAbsence('novo, categoria mudou (select): sujo', { categoria: 'ferias', beforeunload: 1 })
  await page.evaluate(setSelect(ABSENCE_FORM, 'Categoria', 'folga')); await sleep(150)
  await expectAbsence('novo, categoria de volta: limpo', { beforeunload: 0 })
  await page.click(`${ABSENCE_FORM}.querySelector('input[type="radio"][value="from_time"]')`)
  await expectAbsence('novo, periodo mudou (radio): sujo', { beforeunload: 1 })
  await page.click(`${ABSENCE_FORM}.querySelector('input[type="radio"][value="full_day"]')`)
  await expectAbsence('novo, periodo de volta: limpo', { beforeunload: 0 })

  // Novo sujo: Fechar pergunta, e cada resposta respeita os dados.
  await dirty()
  await page.click(ABSENCE_CLOSE)
  await expectAbsence('novo sujo, Fechar: pergunta, foco em "Continuar editando"', { aberto: true, descartar: 1, foco: 'Continuar editando', dialogos: 1 })
  await page.press('Escape')
  await expectAbsence('novo sujo, Esc no aviso: continua, foco volta, dados mantidos', { descartar: 0, foco: 'Fechar', nota: 'Rascunho QA' })
  await page.click(ABSENCE_CLOSE)
  await page.clickAt(5, 450)
  await expectAbsence('novo sujo, clique fora do aviso: continua', { descartar: 0, nota: 'Rascunho QA' })
  await page.click(ABSENCE_CLOSE)
  await page.click(button('Continuar editando', ALERT))
  await expectAbsence('novo sujo, "Continuar editando": dados mantidos', { descartar: 0, aberto: true, nota: 'Rascunho QA' })
  await page.click(ABSENCE_CLOSE)
  await page.click(button('Descartar alterações', ALERT))
  await expectAbsence('novo sujo, "Descartar": fecha, sem listener', { aberto: false, descartar: 0, beforeunload: 0, keydown: 0, dialogos: 0 })
  await openNew()
  await expectAbsence('novo, reabrir depois de descartar: vazio e limpo', { nota: '', beforeunload: 0 })
  await page.click(ABSENCE_CLOSE)

  // Editar: periodos diferentes, sem mexer, nao contam como sujo.
  for (const index of [0, 1, 2, 3]) {
    await openEdit(index)
    await expectAbsence(`editar, ausencia ${index + 1}, abrir: limpo`, { aberto: true, titulo: 'Editar ausência', tecnico: (value) => value?.startsWith(`Tecnico 0${index + 1}`), beforeunload: 0 })
    await page.click(ABSENCE_CLOSE)
    await expectAbsence(`editar, ausencia ${index + 1}, Cancelar edicao: fecha sem perguntar`, { aberto: false, descartar: 0 })
  }

  // "Editar outra" com o formulario sujo: alcancavel pela tela, com clique real.
  await openEdit(0)
  await dirty()
  await page.click(ROW_BUTTON('Editar', 1))
  await expectAbsence('editar outra, sujo: pergunta e nao troca', { descartar: 1, tecnico: (value) => value?.startsWith('Tecnico 01'), nota: 'Rascunho QA', foco: 'Continuar editando' })
  await page.click(button('Continuar editando', ALERT))
  await expectAbsence('editar outra, sujo, "Continuar editando": mesma ausencia, dados mantidos', { descartar: 0, tecnico: (value) => value?.startsWith('Tecnico 01'), nota: 'Rascunho QA', beforeunload: 1 })
  await page.click(ROW_BUTTON('Editar', 1))
  await page.press('Escape')
  await expectAbsence('editar outra, sujo, Esc no aviso: mesma ausencia', { descartar: 0, tecnico: (value) => value?.startsWith('Tecnico 01'), nota: 'Rascunho QA' })
  await page.click(ROW_BUTTON('Editar', 1))
  await page.click(button('Descartar alterações', ALERT))
  await expectAbsence('editar outra, sujo, "Descartar": abre a outra, limpa', { descartar: 0, tecnico: (value) => value?.startsWith('Tecnico 02'), nota: '', beforeunload: 0, dialogos: 0 })
  await page.click(ROW_BUTTON('Editar', 2))
  await expectAbsence('editar outra, limpo: troca sem perguntar', { descartar: 0, tecnico: (value) => value?.startsWith('Tecnico 03'), nota: 'Observacao de teste' })

  // Cancelar uma ausencia pela linha, com o formulario sujo.
  await dirty(' mais')
  await page.click(ROW_BUTTON('Cancelar', 0))
  await page.click(ROW_BUTTON('Confirmar'))
  await sleep(300)
  await expectAbsence('sujo, cancelar OUTRA ausencia pela linha: nao pergunta, formulario mantido', { descartar: 0, aberto: true, nota: 'Observacao de teste mais' })
  const cancels = mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/absences.php').length
  await page.click(ROW_BUTTON('Cancelar', 2))
  await page.click(ROW_BUTTON('Confirmar'))
  await sleep(300)
  await expectAbsence('sujo, cancelar a PROPRIA ausencia, depois de confirmar na linha: fecha sem perguntar', { descartar: 0, aberto: false, beforeunload: 0 })
  report('sujo, cancelar a propria ausencia: um POST', mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/absences.php').length === cancels + 1)

  // Erro do servidor mantem os dados; salvar com sucesso fecha sem perguntar.
  await openEdit(0)
  await dirty(FAILING_ABSENCE_NOTE)
  await page.click(ABSENCE_SUBMIT)
  await page.waitFor(`[...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada'))`)
  await expectAbsence('erro do servidor: aviso de erro, dados mantidos, ainda sujo', { aviso_erro: true, aberto: true, nota: FAILING_ABSENCE_NOTE, beforeunload: 1 })
  await page.click(ABSENCE_CLOSE)
  await expectAbsence('erro do servidor, Cancelar edicao: ainda pergunta', { descartar: 1 })
  await page.click(button('Continuar editando', ALERT))
  await page.evaluate(`${ABSENCE_NOTE}.select()`)
  await page.send('Input.insertText', { text: 'Salva QA' }); await sleep(150)
  const saves = mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/absences.php').length
  await page.click(ABSENCE_SUBMIT)
  await page.waitFor(`!${ABSENCE_FORM}`)
  await expectAbsence('salvar com sucesso: fecha sem perguntar, sem listener', { aberto: false, descartar: 0, beforeunload: 0, keydown: 0, dialogos: 0 })
  report('Ausencias, salvar com sucesso: um POST', mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/absences.php').length === saves + 1)

  // Rota com o formulario sujo.
  await openNew()
  await dirty()
  await leaveByRoute(page, mock, expectAbsence, { rota: '/app/ausencias', aberto: true, nota: 'Rascunho QA' })

  // Celular (390 px): cartoes; "Editar outra" sujo pergunta.
  await open(390)
  await openEdit(0)
  await dirty()
  await page.click(ROW_BUTTON('Editar', 1))
  await expectAbsence('390 px, editar outra, sujo: pergunta', { descartar: 1, foco: 'Continuar editando', tecnico: (value) => value?.startsWith('Tecnico 01') })
  await page.click(button('Descartar alterações', ALERT))
  await expectAbsence('390 px, editar outra, "Descartar": abre a outra, limpa', { descartar: 0, tecnico: (value) => value?.startsWith('Tecnico 02'), beforeunload: 0 })
  report('390 px: sem rolagem horizontal da pagina', await page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
}

// 6. Escala (tela real): formulario de regra, Remover regra e Historico.
const RULE_FORM = `[...document.querySelectorAll('main form')].find((form) => form.querySelector('select[name="rule_type"]'))`
const RULE_SELECT = (name) => `${RULE_FORM}.querySelector('select[name="${name}"]')`
const SCHEDULE_STATE = `(() => {
  const form = ${RULE_FORM}
  const alert = ${ALERT}
  const title = [...document.querySelectorAll('main h2')].find((h) => h.textContent.startsWith('Histórico de'))
  return {${COMMON_STATE}
    colaborador: form?.querySelector('select[name="employee_id"]').value ?? null,
    regra: form?.querySelector('select[name="rule_type"]').value ?? null,
    aviso: alert?.querySelector('h2')?.textContent ?? null,
    regras: [...document.querySelectorAll('main button')].filter((b) => b.offsetParent !== null && b.textContent.trim() === 'Remover regra').length,
    historico: title?.textContent ?? null,
    topo_historico: title ? Math.round(title.getBoundingClientRect().top) : null,
    rolagem: Math.round(scrollY),
  }
})()`

async function schedule(page, port, mock) {
  const expectRule = (label, expected) => expectState(page, `Escala, ${label}`, expected, SCHEDULE_STATE)
  const open = async (width = 1366) => {
    mock.restoreRules()
    await openFromMenu(page, port, 'Escala presencial', width)
    await page.waitFor(`!!${RULE_FORM} && !!${ROW_BUTTON('Editar')}`)
    await sleep(200)
  }
  const setRule = async (name, value) => { await page.evaluate(`(() => { const select = ${RULE_SELECT(name)}; Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, ${JSON.stringify(value)}); select.dispatchEvent(new Event('change', { bubbles: true })) })()`); await sleep(150) }
  const REMOVE_CONFIRM = button('Remover regra', ALERT)
  const REMOVE_CANCEL = button('Cancelar', ALERT)
  await open()
  await expectRule('abrir a tela: limpo, sem listener', { colaborador: '', regra: 'undefined', beforeunload: 0, keydown: 0, dialogos: 0 })

  // Editar: regras diferentes, sem mexer, nao contam como sujo, e trocar nao pergunta.
  for (const [index, type] of [[0, 'fixed_weekdays'], [1, 'fixed_weekdays'], [2, 'cycle'], [3, 'always_onsite']]) {
    await page.click(ROW_BUTTON('Editar', index))
    await sleep(200)
    await expectRule(`editar, regra ${index + 1}, abrir: limpo, sem perguntar`, { descartar: 0, colaborador: index === 1 ? (value) => value === '' || value === '2' : String(index + 1), regra: type, beforeunload: 0 })
  }

  // Cada tipo de campo suja; voltar ao valor de abertura limpa.
  await page.click(ROW_BUTTON('Editar', 0))
  await sleep(200)
  await setRule('rule_type', 'always_remote')
  await expectRule('regra mudou (select): sujo', { regra: 'always_remote', beforeunload: 1 })
  await page.click(ROW_BUTTON('Editar', 0))
  await page.click(button('Descartar alterações', ALERT)); await sleep(150)
  await expectRule('mesma regra, sujo, "Descartar": valores de abertura de novo, limpo', { regra: 'fixed_weekdays', beforeunload: 0 })
  const WEEKDAY = (value) => `${RULE_FORM}.querySelector('input[type="checkbox"][value="${value}"]').closest('label')`
  await page.click(WEEKDAY('tue'))
  await expectRule('dia da semana marcado: sujo', { beforeunload: 1 })
  await page.click(WEEKDAY('tue'))
  await expectRule('dia da semana desmarcado: limpo', { beforeunload: 0 })
  await page.click(`${RULE_FORM}.querySelector('input[type="radio"][value="home"]')`)
  await expectRule('modo home (radio): sujo', { beforeunload: 1 })
  await page.click(`${RULE_FORM}.querySelector('input[type="radio"][value="onsite"]')`)
  await expectRule('modo presencial de volta: limpo', { beforeunload: 0 })

  // "Editar outra" com o formulario sujo: clique real na lista.
  await setRule('rule_type', 'always_remote')
  await page.click(ROW_BUTTON('Editar', 3))
  await expectRule('editar outra, sujo: pergunta e nao troca', { descartar: 1, colaborador: '1', regra: 'always_remote', foco: 'Continuar editando', dialogos: 1 })
  await page.click(button('Continuar editando', ALERT))
  await expectRule('editar outra, sujo, "Continuar editando": mesma regra, dados mantidos', { descartar: 0, colaborador: '1', regra: 'always_remote', foco: 'Editar', beforeunload: 1 })
  await page.click(ROW_BUTTON('Editar', 3))
  await page.clickAt(5, 450)
  await expectRule('editar outra, sujo, clique fora do aviso: mesma regra', { descartar: 0, colaborador: '1', regra: 'always_remote' })
  await page.click(ROW_BUTTON('Editar', 3))
  await page.click(button('Descartar alterações', ALERT)); await sleep(150)
  await expectRule('editar outra, sujo, "Descartar": abre a outra, limpa', { descartar: 0, colaborador: '4', regra: 'always_onsite', beforeunload: 0, dialogos: 0 })

  // Erro do servidor mantem os dados; salvar com sucesso limpa sem perguntar.
  await setRule('rule_type', 'always_remote')
  await page.click(button('Ver prévia', RULE_FORM))
  await page.waitFor(`!${RULE_FORM}.querySelector('button[type="submit"]').disabled`)
  await page.click(`${RULE_FORM}.querySelector('button[type="submit"]')`)
  await page.waitFor(`[...document.querySelectorAll('[role="alert"],[role="status"]')].some((el) => el.textContent.includes('Falha simulada'))`)
  await expectRule('erro do servidor: dados mantidos, ainda sujo', { colaborador: '4', regra: 'always_remote', beforeunload: 1 })
  await page.click(ROW_BUTTON('Editar', 0))
  await page.click(button('Descartar alterações', ALERT)); await sleep(150)
  await setRule('rule_type', 'always_remote')
  await page.click(button('Ver prévia', RULE_FORM))
  await page.waitFor(`!${RULE_FORM}.querySelector('button[type="submit"]').disabled`)
  const ruleSaves = mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/schedules.php').length
  await page.click(`${RULE_FORM}.querySelector('button[type="submit"]')`)
  await page.waitFor(`${RULE_SELECT('employee_id')}.value === ''`)
  await expectRule('salvar com sucesso: limpo, sem perguntar, sem listener', { colaborador: '', regra: 'undefined', descartar: 0, beforeunload: 0, keydown: 0 })
  report('Escala, salvar com sucesso: um POST', mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/schedules.php').length === ruleSaves + 1)

  // Remover regra pelo Dialog, sem window.confirm.
  page.events.length = 0
  const removes = () => mock.requests.filter((r) => r.method === 'POST' && r.key === 'portal/schedules.php').length
  let before = removes()
  await page.click(ROW_BUTTON('Remover regra', 0))
  const removeDialog = await page.evaluate(`(() => { const el = ${ALERT}; return el ? { role: el.getAttribute('role'), modal: el.getAttribute('aria-modal'), texto: el.textContent } : null })()`)
  report('Escala, Remover regra: Dialog com role="alertdialog" e o nome do colaborador', removeDialog?.role === 'alertdialog' && removeDialog.modal === 'true' && removeDialog.texto.includes('Tecnico 01'))
  await expectRule('Remover regra, abrir: foco em "Cancelar", um keydown', { aviso: 'Remover regra de escala?', foco: 'Cancelar', keydown: 1, dialogos: 1 })
  report('Escala, Remover regra: sem window.confirm', !page.events.some((e) => e.method === 'Page.javascriptDialogOpening'))
  await page.press('Tab')
  await expectRule('Remover regra: Tab no ultimo vai ao primeiro', { foco: 'Remover regra' })
  await page.press('Tab', true)
  await expectRule('Remover regra: Shift+Tab volta ao "Cancelar"', { foco: 'Cancelar' })
  await page.press('Escape')
  await expectRule('Remover regra, Esc: fecha, foco volta ao botao, nada removido', { aviso: null, foco: 'Remover regra', regras: 4, keydown: 0, dialogos: 0 })
  await page.click(ROW_BUTTON('Remover regra', 0))
  await page.clickAt(5, 450)
  await expectRule('Remover regra, clique fora: fecha, foco volta, nada removido', { aviso: null, foco: 'Remover regra', regras: 4 })
  await page.click(ROW_BUTTON('Remover regra', 0))
  await page.click(REMOVE_CANCEL)
  await expectRule('Remover regra, "Cancelar": fecha, foco volta, nada removido', { aviso: null, foco: 'Remover regra', regras: 4 })
  report('Escala, Remover regra, Esc, clique fora e "Cancelar": nenhum POST', removes() === before)

  // [BB1] Falha do servidor: o dialogo fica aberto, o aviso aparece por cima e da para tentar de novo.
  before = removes()
  await page.click(ROW_BUTTON('Remover regra', 3))
  await page.click(REMOVE_CONFIRM)
  await page.waitFor(`document.body.textContent.includes('Falha simulada')`)
  await sleep(150)
  await expectRule('Remover regra, erro do servidor: continua aberto, aviso de erro, regra mantida, foco no "Cancelar"', { aviso: 'Remover regra de escala?', aviso_erro: true, regras: 4, foco: 'Cancelar', dialogos: 1, keydown: 1 })
  await page.click(REMOVE_CONFIRM)
  await sleep(300)
  report('Escala, Remover regra, tentar de novo: outro POST', removes() === before + 2)
  await page.press('Escape')
  await expectRule('Remover regra, erro do servidor, Esc: fecha, foco volta ao botao, regra mantida', { aviso: null, regras: 4, foco: 'Remover regra', dialogos: 0 })

  // Sucesso: sai da lista, foco no titulo; o formulario limpo daquele colaborador esvazia.
  await page.click(ROW_BUTTON('Editar', 2))
  await sleep(150)
  await expectRule('antes de remover: formulario com o colaborador 3', { colaborador: '3', beforeunload: 0 })
  before = removes()
  await page.click(ROW_BUTTON('Remover regra', 2))
  await page.click(REMOVE_CONFIRM)
  await page.waitFor(`!${ALERT}`)
  await expectRule('Remover regra, sucesso: sai da lista, foco no titulo das regras, formulario limpo esvazia', { aviso: null, regras: 3, foco: 'Regras vigentes e agendadas', colaborador: '', dialogos: 0 })
  report('Escala, Remover regra, sucesso: um POST', removes() === before + 1)

  // Remover nao e descartar: o formulario sujo fica, mesmo sendo do mesmo colaborador.
  await page.click(ROW_BUTTON('Editar', 0))
  await sleep(150)
  await setRule('rule_type', 'always_remote')
  await page.click(ROW_BUTTON('Remover regra', 1))
  await page.click(REMOVE_CONFIRM)
  await page.waitFor(`!${ALERT}`)
  await expectRule('sujo, remover a regra de OUTRO: formulario mantido', { regras: 2, colaborador: '1', regra: 'always_remote', beforeunload: 1, descartar: 0 })
  await page.click(ROW_BUTTON('Remover regra', 0))
  await page.click(REMOVE_CONFIRM)
  await page.waitFor(`!${ALERT}`)
  await expectRule('sujo, remover a regra do MESMO colaborador: formulario mantido, ainda sujo', { regras: 1, colaborador: '1', regra: 'always_remote', beforeunload: 1, descartar: 0 })

  // Rota com o formulario sujo.
  await leaveByRoute(page, mock, expectRule, { rota: '/app/escala-presencial', colaborador: '1', regra: 'always_remote' })

  // Historico: no celular, a tela vai ate o painel e o foco vai para o titulo.
  await open(390)
  const titleFocused = (name) => `Histórico de ${name}`
  const startScroll = (await page.evaluate(SCHEDULE_STATE)).rolagem
  await page.click(ROW_BUTTON('Histórico', 0))
  await page.waitFor(`!!document.querySelector('main h2[tabindex="-1"]') && document.activeElement.tagName === 'H2'`)
  await sleep(300)
  await expectRule('390 px, abrir Historico: foco no titulo do painel, titulo perto do topo, com folga', { historico: titleFocused('Tecnico 01 Sobrenome'), foco: titleFocused('Tecnico 01 Sobrenome'), topo_historico: (value) => value >= 80 && value < 200, rolagem: (value) => value > startScroll })
  await page.press('Tab')
  await expectRule('390 px, Historico: Tab a partir do titulo vai ao "Fechar"', { foco: 'Fechar' })
  const historyButtons = await page.evaluate(`${ROW_BUTTON('Histórico', 1)}.getBoundingClientRect().top`)
  report('390 px, Historico: botao de outra pessoa continua na pagina', typeof historyButtons === 'number')
  await page.click(ROW_BUTTON('Histórico', 1))
  await sleep(400)
  await expectRule('390 px, Historico de outra pessoa: titulo e foco trocam', { historico: titleFocused(`Tecnico 02 Com Um Nome Bem Comprido`), foco: titleFocused('Tecnico 02 Com Um Nome Bem Comprido'), topo_historico: (value) => value >= 80 && value < 200 })
  await page.click(ROW_BUTTON('Fechar'))
  await expectRule('390 px, Historico, Fechar: painel fecha, foco volta ao botao de quem abriu', { historico: null, foco: 'Histórico' })
  const reopen = await page.evaluate(`document.activeElement === ${ROW_BUTTON('Histórico', 1)}`)
  report('390 px, Historico, Fechar: e o botao da mesma linha', reopen)
  report('390 px: sem rolagem horizontal da pagina', await page.evaluate('document.documentElement.scrollWidth <= innerWidth'))

  // Fora do celular, nada muda: nao rola nem move o foco ao abrir.
  for (const width of [768, 1366]) {
    await open(width)
    await page.evaluate(`${ROW_BUTTON('Histórico', 0)}.scrollIntoView({ block: 'center' })`)
    await sleep(100)
    const scrollBefore = (await page.evaluate(SCHEDULE_STATE)).rolagem
    await page.click(ROW_BUTTON('Histórico', 0))
    await sleep(400)
    await expectRule(`${width} px, abrir Historico: painel abre, sem rolar nem mover o foco`, { historico: titleFocused('Tecnico 01 Sobrenome'), foco: 'Histórico', rolagem: scrollBefore })
    await page.click(ROW_BUTTON('Histórico', 0))
    await sleep(200)
    await expectRule(`${width} px, mesmo botao de novo: painel fecha, foco fica no botao`, { historico: null, foco: 'Histórico' })
  }
  mock.restoreRules()
}

// 7. [Z2] Com qualquer Dialog aberto, o topo e as bordas da janela caem no fundo
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
  // Ausencias e Escala: o aviso de descarte e o Remover regra.
  for (const width of [390, 1366]) {
    await goto(page, port, '/ausencias', width)
    await page.waitFor(`!!${ROW_BUTTON('Nova ausência')}`)
    await page.click(ROW_BUTTON('Nova ausência'))
    await page.waitFor(`!!${ABSENCE_FORM}`)
    await page.evaluate(`${ABSENCE_NOTE}.focus()`)
    await page.typeText('x')
    await page.click(ABSENCE_CLOSE)
    await expectCoverage(page, `${width} px, Ausencias, descarte`)
    await discard()
    await goto(page, port, '/escala-presencial', width)
    await page.waitFor(`!!${ROW_BUTTON('Remover regra')}`)
    await page.click(ROW_BUTTON('Remover regra'))
    await expectCoverage(page, `${width} px, Escala, Remover regra`)
    await closeAll()
    await page.evaluate(`(() => { const select = ${RULE_SELECT('rule_type')}; Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, 'always_remote'); select.dispatchEvent(new Event('change', { bubbles: true })) })()`)
    await sleep(150)
    await page.click(ROW_BUTTON('Editar'))
    await expectCoverage(page, `${width} px, Escala, descarte`)
    await discard()
  }

  // Clique de verdade na faixa do topo: fecha, em vez de alcancar a pagina.
  await goto(page, port, '/chamados-criticos')
  await page.click(button('Visualizar'))
  await page.waitFor(anyDialog)
  await page.clickAt(683, 10)
  await expectState(page, 'clique na faixa do topo (y = 10): fecha o dialogo, foco volta ao "Visualizar"', { dialogos: 0, foco: 'Visualizar' })
}

// 8. [BB1] O aviso fica acima de qualquer Dialog, da coluna fixa e do menu do celular.
const TOAST = `document.querySelector('body > [role="alert"], body > [role="status"]')`
const TOAST_STATE = `(() => {
  const toast = ${TOAST}
  if (!toast) return { aviso: false }
  const box = toast.getBoundingClientRect()
  const close = toast.querySelector('[aria-label="Fechar mensagem"]').getBoundingClientRect()
  const on = (rect) => toast.contains(document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2))
  return { aviso: true, centro: on(box), fechar: on(close), z: getComputedStyle(toast).zIndex, foco_dentro: !!document.activeElement.closest('[data-dialog-backdrop]') }
})()`
const ON_TOP = { aviso: true, centro: true, fechar: true, z: '70' }
const DIALOG_BUTTONS_FREE = `[...document.querySelector('[role="alertdialog"]').querySelectorAll('button')].every((b) => { const r = b.getBoundingClientRect(); return b.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)) })`

async function toastAboveLayers(page, port) {
  const failToast = `[...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent.includes('Falha simulada'))`
  for (const width of [390, 1366]) {
    // Chamados: formulario aberto, erro do servidor.
    await goto(page, port, '/chamados-criticos', width)
    await page.click(button('Novo chamado'))
    await page.waitFor(`!!${FORM_PANEL}`)
    await makeDirty(page, FAILING_INCIDENT)
    await page.evaluate(`${SUBMIT}.click()`)
    await page.waitFor(failToast)
    await expectState(page, `${width} px, Chamados, erro com o formulario aberto: aviso por cima`, { ...ON_TOP, foco_dentro: (value) => value === true || value === false }, TOAST_STATE)
    const covered = await page.evaluate(`(() => { const toast = ${TOAST}.getBoundingClientRect(); return [...${FORM_PANEL}.querySelectorAll('form button')].filter((b) => { const r = b.getBoundingClientRect(); return r.left < toast.right && r.right > toast.left && r.top < toast.bottom && r.bottom > toast.top }).length })()`)
    await page.evaluate(`document.querySelector('[data-dialog-backdrop]').scrollTop = 1e6`)
    await sleep(100)
    report(`${width} px, Chamados, erro: o aviso nao cobre os botoes do fim do formulario`, await page.evaluate(`(() => { const r = ${SUBMIT}.getBoundingClientRect(); return ${SUBMIT}.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)) })()`), `sobrepostos ${covered}`)
    await page.press('Escape')
    await expectState(page, `${width} px, Chamados, com o aviso na tela, Esc: o dialogo responde (pergunta)`, { descartar: true, formulario: true })
    await page.click(button('Continuar editando', ALERT))
    await page.evaluate(`${SUBMIT}.focus()`)
    await page.press('Tab')
    await expectState(page, `${width} px, Chamados, com o aviso na tela: Tab segue preso no dialogo`, { foco: 'Cancelar', formulario: true })
    await page.press('Escape')
    await page.click(button('Descartar alterações', ALERT))
    await expectState(page, `${width} px, Chamados, Descartar: fecha, aviso continua acima da pagina`, { aviso: true, z: '70' }, TOAST_STATE)

    // Coluna fixa de Chamados (z-10): uma celula passa embaixo do aviso.
    const sticky = await page.evaluate(`(() => {
      const toast = ${TOAST}
      if (!toast) return null
      const cells = [...document.querySelectorAll('td.data-table-sticky-end')].filter((cell) => cell.getClientRects().length)
      if (!cells.length) return 'sem celula'
      const cell = cells.at(-1)
      const box = toast.getBoundingClientRect()
      window.scrollBy(0, cell.getBoundingClientRect().top + cell.offsetHeight / 2 - (box.top + box.height / 2))
      const at = cell.getBoundingClientRect()
      const x = (Math.max(at.left, box.left) + Math.min(at.right, box.right)) / 2
      const y = (Math.max(at.top, box.top) + Math.min(at.bottom, box.bottom)) / 2
      return { cruzam: at.left < box.right && at.right > box.left && at.top < box.bottom && at.bottom > box.top, aviso: toast.contains(document.elementFromPoint(x, y)) }
    })()`)
    if (sticky === 'sem celula') report(`${width} px, sem a coluna fixa nesta largura (cartoes)`, true)
    else report(`${width} px, coluna fixa de Chamados embaixo do aviso: aviso por cima`, sticky?.cruzam && sticky.aviso, JSON.stringify(sticky))

    // Funcionarios: edicao aberta, erro do servidor.
    await goto(page, port, '/admin?tab=funcionarios', width)
    await page.waitFor(`!!${ROW_EDIT(0)}`)
    await page.click(ROW_EDIT(0))
    await page.waitFor(`!!${EMPLOYEE_PANEL}`)
    await page.evaluate(`${EMPLOYEE_NAME}.select()`)
    await page.typeText(FAILING_EMPLOYEE)
    await page.evaluate(`${EMPLOYEE_SUBMIT}.click()`)
    await page.waitFor(failToast)
    await expectState(page, `${width} px, Funcionarios, erro com a edicao aberta: aviso por cima`, { ...ON_TOP }, TOAST_STATE)
    await page.press('Escape')
    await expectState(page, `${width} px, Funcionarios, com o aviso na tela, Esc: o dialogo responde (pergunta)`, { descartar: 1, edicao: true }, EMPLOYEE_STATE)
    await page.click(button('Descartar alterações', ALERT))

    // Remover regra: o caso que a AA4 fechava.
    await goto(page, port, '/escala-presencial', width)
    await page.waitFor(`!!${ROW_BUTTON('Remover regra', 3)}`)
    await page.click(ROW_BUTTON('Remover regra', 3))
    await page.click(button('Remover regra', ALERT))
    await page.waitFor(failToast)
    await sleep(150)
    await expectState(page, `${width} px, Remover regra, erro: aviso por cima, foco no dialogo`, { ...ON_TOP, foco_dentro: true }, TOAST_STATE)
    report(`${width} px, Remover regra, erro: o aviso nao cobre os botoes do dialogo`, await page.evaluate(DIALOG_BUTTONS_FREE))
    const toastBox = await page.evaluate(`(() => { const r = ${TOAST}.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + 12 } })()`)
    await page.clickAt(toastBox.x, toastBox.y)
    await expectState(page, `${width} px, Remover regra, clique no texto do aviso: o dialogo nao fecha`, { dialogos: 1, descartar: 1 }, `(() => ({ dialogos: document.querySelectorAll('[data-dialog-backdrop]').length, descartar: document.querySelectorAll('[role="alertdialog"]').length }))()`)
    await page.evaluate(`document.querySelector('[role="alertdialog"] button:last-child').focus()`)
    await page.click(`${TOAST}.querySelector('[aria-label="Fechar mensagem"]')`)
    await expectState(page, `${width} px, Remover regra, fechar o aviso com o mouse: aviso sai, dialogo aberto, foco no "Cancelar"`, { aviso: false }, TOAST_STATE)
    await expectState(page, `${width} px, Remover regra, depois de fechar o aviso: foco continua no dialogo`, { foco: 'Cancelar', dialogos: 1 })
    await page.press('Escape')
    await expectState(page, `${width} px, Remover regra, Esc: o dialogo ainda responde`, { dialogos: 0, foco: 'Remover regra' })
  }

  // Menu do celular (z-50) aberto, com o aviso na tela.
  await goto(page, port, '/admin?tab=funcionarios', 390)
  await page.waitFor(`!!${CREATE_FORM}`)
  await page.evaluate(`${CREATE_ID}.focus()`)
  await page.typeText('900')
  await page.evaluate(`${CREATE_NAME}.focus()`)
  await page.typeText(FAILING_EMPLOYEE)
  await page.evaluate(`${CREATE_SUBMIT}.click()`)
  await page.waitFor(failToast)
  await page.evaluate(`document.querySelector('[aria-label="Abrir menu"]').click()`)
  await sleep(200)
  await expectState(page, '390 px, menu do celular aberto: aviso por cima', { ...ON_TOP }, TOAST_STATE)
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT))
}

// 9. [BB2] Com um Dialog aberto, a janela nao rola; ao fechar o ultimo, tudo volta.
const SCROLL_STATE = `(() => {
  const main = document.querySelector('main').getBoundingClientRect()
  return {
    rolagem: Math.round(scrollY),
    overflow: document.body.style.overflow,
    padding: document.body.style.paddingRight,
    esquerda: Math.round(main.left * 100) / 100,
    largura: Math.round(main.width * 100) / 100,
    barra: innerWidth - document.documentElement.clientWidth,
    dialogos: document.querySelectorAll('[data-dialog-backdrop]').length,
    fundo: document.querySelector('[data-dialog-backdrop]')?.scrollTop ?? null,
  }
})()`

async function scrollLock(page, port) {
  for (const [width, bar] of [[1366, 15], [390, 0]]) {
    const expectScroll = (label, expected) => expectState(page, `${width} px, ${label}`, expected, SCROLL_STATE)
    await goto(page, port, '/chamados-criticos', width)
    await page.waitFor(`!!${button('Visualizar')}`)
    await page.evaluate(`${button('Visualizar')}.scrollIntoView({ block: 'center' })`)
    await sleep(100)
    const start = await page.evaluate(SCROLL_STATE)
    report(`${width} px, rolagem: pagina rolada antes de abrir, barra de ${bar} px`, start.rolagem > 0 && start.barra === bar, `rolagem ${start.rolagem}, barra ${start.barra}`)
    const same = { rolagem: start.rolagem, esquerda: start.esquerda, largura: start.largura }
    await page.evaluate(`${button('Visualizar')}.click()`)
    await page.waitFor(`!!document.querySelector('[role="dialog"]')`)
    await sleep(100)
    await expectScroll('dialogo aberto: body travado, barra compensada, pagina nao pula', { ...same, overflow: 'hidden', padding: bar ? `${bar}px` : '', barra: 0, dialogos: 1 })
    await page.wheel(5, 450)
    await expectScroll('roda no fundo: a pagina nao muda', { ...same })
    const panel = await page.evaluate(`(() => { const r = document.querySelector('[role="dialog"]').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 } })()`)
    await page.wheel(panel.x, panel.y)
    await expectScroll('roda no painel: a pagina nao muda', { ...same })
    await page.press('Escape')
    await expectScroll('fechar: nada mudou (posicao, largura, estilo do body e barra)', { ...same, overflow: '', padding: '', barra: bar, dialogos: 0 })

    // Dois empilhados: a trava so sai com o ultimo.
    await page.click(button('Novo chamado'))
    await page.waitFor(`!!${FORM_PANEL}`)
    await makeDirty(page, 'x')
    await page.press('Escape')
    const stacked = await expectScroll('dois empilhados: travado', { overflow: 'hidden', padding: bar ? `${bar}px` : '', dialogos: 2 })
    await page.click(button('Continuar editando', ALERT))
    await expectScroll('fechou o de cima: continua travado', { overflow: 'hidden', padding: bar ? `${bar}px` : '', dialogos: 1, rolagem: stacked.rolagem })
    await page.press('Escape')
    await page.click(button('Descartar alterações', ALERT))
    await expectScroll('fechou o ultimo: destravado, mesma posicao', { overflow: '', padding: '', barra: bar, dialogos: 0, rolagem: stacked.rolagem })
  }

  // Formulario mais alto que a tela: o fundo do dialogo continua rolando.
  await goto(page, port, '/chamados-criticos', 390)
  await page.click(button('Novo chamado'))
  await page.waitFor(`!!${FORM_PANEL}`)
  await page.evaluate(`document.querySelector('[data-dialog-backdrop]').scrollTop = 0`)
  const tall = await page.evaluate(SCROLL_STATE)
  await page.wheel(195, 450)
  await expectState(page, '390 px, formulario alto, roda: o fundo do dialogo rola, a pagina nao', { rolagem: tall.rolagem, fundo: (value) => value > 0 }, SCROLL_STATE)
  await page.press('Escape')

  // Sair do dialogo trocando de tela tambem destrava.
  await goto(page, port, '/dashboard')
  await page.evaluate(`${NAV_BUTTON('Chamados críticos')}.click()`)
  await page.waitFor(`!!${button('Novo chamado')}`)
  await page.click(button('Novo chamado'))
  await page.waitFor(`!!${FORM_PANEL}`)
  await makeDirty(page, 'x')
  await page.evaluate(`${NAV_BUTTON('Calendário')}.click()`); await sleep(300)
  await page.click(button('Descartar alterações', ALERT)); await sleep(300)
  await expectState(page, 'menu, Descartar: troca de tela e destrava', { overflow: '', padding: '', dialogos: 0 }, SCROLL_STATE)
}

// 10. [BB3] O destino do foco sumiu: vai para o titulo, nunca para o body.
const FOCUS_STATE = `(() => {
  const active = document.activeElement
  return { titulo_da_pagina: !!active?.hasAttribute?.('data-page-title'), body: active === document.body, foco: (active?.textContent || '').trim().slice(0, 40), dialogos: document.querySelectorAll('[data-dialog-backdrop]').length }
})()`

async function focusFallback(page, port, mock) {
  for (const width of [1366, 390]) {
    mock.keepOnlyRule(1)
    await goto(page, port, '/escala-presencial', width)
    await page.waitFor(`!!${ROW_BUTTON('Remover regra')}`)
    await page.click(ROW_BUTTON('Remover regra'))
    await page.click(button('Remover regra', ALERT))
    await page.waitFor(`!${ALERT}`)
    await sleep(150)
    await expectState(page, `${width} px, ultima regra removida: foco no titulo da pagina`, { titulo_da_pagina: true, body: false, foco: 'Escala presencial', dialogos: 0 }, FOCUS_STATE)
    mock.restoreRules()

    const expectLost = (label) => expectState(page, `${width} px, Ausencias, ${label}: foco no titulo da pagina`, { titulo_da_pagina: true, body: false, dialogos: 0 }, FOCUS_STATE)
    const openNewAbsence = async () => { await page.click(ROW_BUTTON('Nova ausência')); await page.waitFor(`!!${ABSENCE_FORM}`) }
    await goto(page, port, '/ausencias', width)
    await page.waitFor(`!!${ROW_BUTTON('Nova ausência')} && !!${ROW_BUTTON('Editar')}`)
    await openNewAbsence()
    await page.click(ABSENCE_CLOSE)
    await expectLost('Fechar limpo')
    await openNewAbsence()
    await page.evaluate(`${ABSENCE_NOTE}.focus()`)
    await page.typeText('x')
    await page.click(ABSENCE_CLOSE)
    await page.click(button('Descartar alterações', ALERT))
    await expectLost('Fechar sujo, Descartar')
    await page.click(ROW_BUTTON('Editar', 0))
    await page.waitFor(`!!${ABSENCE_FORM}`)
    await page.click(ABSENCE_SUBMIT)
    await page.waitFor(`!${ABSENCE_FORM}`)
    await sleep(150)
    await expectLost('salvar com sucesso')
    await page.click(ROW_BUTTON('Editar', 0))
    await page.waitFor(`!!${ABSENCE_FORM}`)
    await page.click(ROW_BUTTON('Cancelar', 0))
    await page.click(ROW_BUTTON('Confirmar'))
    await page.waitFor(`!${ABSENCE_FORM}`)
    await sleep(150)
    // No servidor simulado a linha continua (a ausencia nao muda): o foco fica nela, sem cair no body.
    await expectState(page, `${width} px, Ausencias, cancelar a propria ausencia pela linha: foco nao cai no body`, { body: false, dialogos: 0 }, FOCUS_STATE)
  }
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
  console.log('== Ausencias')
  await absences(page, mock.port, mock)
  console.log('== Escala')
  await schedule(page, mock.port, mock)
  console.log('== Fundo dos dialogos (Z2)')
  await dialogCoverage(page, mock.port)
  console.log('== Avisos acima dos dialogos (BB1)')
  await toastAboveLayers(page, mock.port)
  console.log('== Trava de rolagem (BB2)')
  await scrollLock(page, mock.port)
  console.log('== Foco sem destino (BB3)')
  await focusFallback(page, mock.port, mock)
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
