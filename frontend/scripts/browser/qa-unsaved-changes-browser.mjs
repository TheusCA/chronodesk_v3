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
import { mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { launchChrome, openPage, sleep } from './lib/chrome.mjs'
import { FAILING_INCIDENT, startMockServer } from './lib/mock-server.mjs'

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
async function expectState(page, label, expected) {
  const state = await page.evaluate(STATE)
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
