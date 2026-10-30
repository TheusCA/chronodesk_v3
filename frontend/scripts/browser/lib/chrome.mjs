/* global process, Buffer */
// Chrome sem janela e cliente do protocolo de depuracao (CDP), sem dependencia.
// O caminho do Chrome vem de CHROME_PATH. Perfil temporario, apagado no fim.
import { spawn, spawnSync } from 'node:child_process'
import { mkdtempSync, rmSync, existsSync } from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

function freePort() {
  return new Promise((resolve, reject) => {
    const probe = createServer()
    probe.once('error', reject)
    probe.listen(0, '127.0.0.1', () => { const { port } = probe.address(); probe.close(() => resolve(port)) })
  })
}

export async function launchChrome() {
  const chromePath = process.env.CHROME_PATH
  if (!chromePath || !existsSync(chromePath)) {
    throw new Error('Defina CHROME_PATH com o caminho do Chrome ou do Chromium (por exemplo, C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe).')
  }
  const port = await freePort()
  const profile = mkdtempSync(join(tmpdir(), 'chronodesk-qa-chrome-'))
  const child = spawn(chromePath, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--disable-extensions', 'about:blank'], { stdio: 'ignore' })
  for (let attempt = 0; attempt < 100; attempt++) {
    try {
      await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()
      break
    } catch {
      await sleep(100)
    }
  }
  const close = async () => {
    if (process.platform === 'win32') spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' })
    else child.kill('SIGKILL')
    await sleep(300)
    try { rmSync(profile, { recursive: true, force: true }) } catch { /* perfil preso pelo SO: fica no temporario */ }
  }
  return { port, close }
}

// Uma aba nova com helpers de avaliacao, clique e teclado reais.
export async function openPage(debugPort) {
  const target = await (await fetch(`http://127.0.0.1:${debugPort}/json/new?about:blank`, { method: 'PUT' })).json()
  const ws = new WebSocket(target.webSocketDebuggerUrl)
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject })
  let seq = 0
  const pending = new Map()
  const events = []
  ws.onmessage = (event) => {
    const message = JSON.parse(event.data)
    if (message.id && pending.has(message.id)) { pending.get(message.id)(message); pending.delete(message.id) } else if (message.method) events.push(message)
  }
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++seq
    pending.set(id, (message) => (message.error ? reject(new Error(`${method}: ${message.error.message}`)) : resolve(message.result)))
    ws.send(JSON.stringify({ id, method, params }))
  })
  const evaluate = async (expression) => {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, includeCommandLineAPI: true })
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || 'erro ao avaliar')
    return result.result.value
  }
  const waitFor = async (expression, timeoutMs = 8000) => {
    for (let waited = 0; waited < timeoutMs; waited += 100) {
      if (await evaluate(expression)) return true
      await sleep(100)
    }
    return false
  }
  const clickAt = async (x, y) => {
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 })
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 })
    await sleep(200)
  }
  // Roda do mouse real naquele ponto.
  const wheel = async (x, y, deltaY = 400) => {
    await send('Input.dispatchMouseEvent', { type: 'mouseWheel', x, y, deltaX: 0, deltaY })
    await sleep(250)
  }
  // Clique real no centro do elemento (expressao JS que devolve o elemento).
  const click = async (elementExpression) => {
    const point = await evaluate(`(() => { const el = ${elementExpression}; if (!el) return null; el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 } })()`)
    if (!point) throw new Error('elemento nao encontrado para o clique')
    await clickAt(point.x, point.y)
  }
  const KEYS = { Escape: 27, Tab: 9 }
  const press = async (key, shift = false) => {
    const base = { key, code: key, windowsVirtualKeyCode: KEYS[key], modifiers: shift ? 8 : 0 }
    await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...base })
    await send('Input.dispatchKeyEvent', { type: 'keyUp', ...base })
    await sleep(200)
  }
  const typeText = async (text) => { await send('Input.insertText', { text }); await sleep(150) }
  const screenshot = async () => Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).data, 'base64')
  const close = async () => { try { await send('Target.closeTarget', { targetId: target.id }) } catch { /* aba ja fechada */ } ws.close() }
  await send('Page.enable')
  await send('Runtime.enable')
  await send('Network.enable')
  return { send, evaluate, waitFor, click, clickAt, wheel, press, typeText, screenshot, events, close }
}
