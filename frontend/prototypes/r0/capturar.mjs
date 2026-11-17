/* global process, Buffer */
// R0 do redesenho: capturas "antes" (o app atual) e "depois" (o prototipo
// isolado desta pasta). Fora do build de producao: o Vite so empacota o que o
// src/ importa, e nada importa esta pasta.
//
// "antes": sobe o servidor simulado do roteiro de navegador
// (scripts/browser/lib/mock-server.mjs, DADOS FICTICIOS) e, na frente dele,
// um proxy que troca so a sessao (um perfil por vez) e enche a situacao da
// equipe com mais tecnicos ficticios. O mock-server.mjs nao muda.
// "depois": serve esta pasta e fotografa as telas do prototipo em cada
// direcao e tema.
//
// Uso (na pasta frontend, com o build feito em ../app):
//   CHROME_PATH="C:\...\chrome.exe" node prototypes/r0/capturar.mjs antes <pasta de saida>
//   CHROME_PATH="C:\...\chrome.exe" node prototypes/r0/capturar.mjs depois <pasta de saida>
// [R1] Opções do "antes" (o app), para os lotes R:
//   --tema=claro|escuro   emula prefers-color-scheme (o app segue o sistema)
//   --plano=r             só as telas principais (admin), o login e Pausas do
//                         técnico, em 1280 e 390 px; sem isso, o plano do R0
//   --medir               em 390 px, conta alvos de toque abaixo de 40 px e
//                         rolagem lateral em cada tela (sai 1 se houver)
// [F10-2] --plano=f10     a tela Escala (com os times de rodízio, o painel do
//                         time e a regra semana a semana) e a edição de
//                         funcionário (grupo da escala), em 1280 e 390 px
// [F9] --plano=f9       Pausas do técnico do Crítico (com o almoço escolhido),
//                         a lista com as quatro equipes, a Visão operacional
//                         (linhas Crítico e Híbrido) e a edição de funcionário,
//                         em 1280 e 390 px
// [Marca] --plano=marca   o login e o topo da casca, em 1280 e 390 px
// [9.16] --plano=pc      a tela Escala com os Papéis do Crítico (seção e
//                         painel) e a Visão operacional com o papel vago, em
//                         1280 e 390 px
// [9.17] --plano=chg     o Controle de CHG (Liderança com edição e o
//                         técnico só leitura, Quadro e o formulário) e a
//                         edição de funcionário (grupo Tools), em 1280 e 390 px
//   --app=<pasta>         fotografa outro build (o "antes" de um lote); sem
//                         isso, ../app
import { createServer, request } from 'node:http'
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, extname, join, normalize, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { launchChrome, openPage, sleep } from '../../scripts/browser/lib/chrome.mjs'
import { startMockServer } from '../../scripts/browser/lib/mock-server.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const positional = process.argv.slice(2).filter((arg) => !arg.startsWith('--'))
const option = (name) => process.argv.find((arg) => arg.startsWith(`--${name}=`))?.split('=')[1] ?? null
const [mode, outArg] = positional
const theme = option('tema')
const planName = option('plano')
const appOption = option('app')
const measureApp = process.argv.includes('--medir')
if (theme !== null && !['claro', 'escuro'].includes(theme)) { console.error('--tema=claro|escuro'); process.exit(2) }
if (!['antes', 'depois', 'verificar'].includes(mode) || (mode !== 'verificar' && !outArg)) {
  console.error('uso: node prototypes/r0/capturar.mjs antes|depois <pasta de saida> | verificar')
  process.exit(2)
}
const outDir = resolve(outArg || '.')
if (mode !== 'verificar') mkdirSync(outDir, { recursive: true })

const WIDTHS = [[1280, 900], [390, 844]]
// [R4b] 1920 x 1080: a tela fixa do modo painel (só no plano dos lotes R).
const HEIGHTS = new Map([...WIDTHS, [1920, 1080]])
const SERVER_NOW = '2026-10-28T10:42:00-03:00'

// ---------------------------------------------------------------------------
// Sessoes por perfil (as permissoes de portal_permissions_for_role, security.php)
// ---------------------------------------------------------------------------
const GESTOR = ['portal.read', 'pausas.use', 'metricas.read', 'relatorios.read', 'documentacao.edit', 'avisos.manage', 'operacao.approve', 'ausencias.manage']
const LIDERANCA = [...GESTOR, 'funcionarios.manage', 'escalas.manage', 'pa_map.manage', 'pausas.force_end', 'escalas.retroativa', 'ausencias.retroativa']
const ADMIN = [...LIDERANCA, 'admin.manage', 'configuracoes.manage', 'integracoes.manage', 'usuarios_locais.manage', 'perfis.promote', 'escalas.lideranca', 'funcionarios.fora_mapa', 'funcionarios.restaurar']
const manager = (role, permissions, username) => ({ sucesso: true, csrf_token: 'teste', role, permissions, ci: { autenticado: false, funcionario_id: 0, nome: '', username: '', role: null }, gestor: { autenticado: true, admin: role === 'admin', username, role } })
const SESSIONS = {
  anonimo: { sucesso: true, csrf_token: 'teste', role: null, permissions: [], ci: { autenticado: false, funcionario_id: 0, nome: '', username: '', role: null }, gestor: { autenticado: false, admin: false, username: '', role: null } },
  tecnico: { sucesso: true, csrf_token: 'teste', role: 'tecnico', permissions: ['portal.read', 'pausas.use'], ci: { autenticado: true, funcionario_id: 1, nome: 'Técnico 01 Exemplo', username: 'tecnico.01', role: 'tecnico' }, gestor: { autenticado: false, admin: false, username: '', role: null } },
  gestor: manager('gestor', GESTOR, 'gestor.exemplo'),
  lideranca: manager('lideranca', LIDERANCA, 'lideranca.exemplo'),
  admin: manager('admin', ADMIN, 'admin.exemplo'),
  // [F9] Técnico do Crítico (o 17 do plano f9).
  tecnico_critico: { sucesso: true, csrf_token: 'teste', role: 'tecnico', permissions: ['portal.read', 'pausas.use'], ci: { autenticado: true, funcionario_id: 17, nome: 'Técnico 17 Exemplo', username: 'tecnico.17', role: 'tecnico' }, gestor: { autenticado: false, admin: false, username: '', role: null } },
}

// ---------------------------------------------------------------------------
// Situacao da equipe mais cheia (ficticia): 16 tecnicos em N1 e N2
// ---------------------------------------------------------------------------
const SITUATIONS = ['presencial', 'presencial', 'remoto', 'pausa', 'presencial', 'almoco', 'remoto', 'presencial', 'ausente', 'presencial', 'pausa', 'remoto', 'fora_expediente', 'presencial', 'sem_escala', 'remoto']
// [R3] Café do 04 desde 10:18, como no protótipo: o estouro (24 min com limite de 20) aparece nas capturas dos lotes R.
const PAUSES = { 4: ['Café', '10:18'], 11: ['Reunião', '10:12'] }
const person = (index) => {
  const id = index + 1
  const situacao = SITUATIONS[index]
  const pause = PAUSES[id]
  return {
    id, nome: `Técnico ${String(id).padStart(2, '0')} Exemplo`, equipe: id <= 9 ? 'n1' : 'n2', ativo: true,
    em_pausa: Boolean(pause), motivo_pausa: pause ? pause[0] : null,
    inicio_pausa: pause ? `2026-10-28T${pause[1]}:00-03:00` : null,
    elapsed_seconds: 0, observacao_reuniao: pause && pause[0] === 'Reunião' ? 'Alinhamento da janela de mudança' : undefined,
    jornada_entrada: id % 3 === 0 ? '09:00' : '08:00', jornada_saida: id % 3 === 0 ? '18:00' : '17:00',
    presenca: { situacao, origem: 'regra_escala', detalhe: '' },
  }
}
const TEAM = Array.from({ length: 16 }, (_, index) => person(index))
const STATUS = { n1: TEAM.filter((item) => item.equipe === 'n1'), n2: TEAM.filter((item) => item.equipe === 'n2'), server_now: SERVER_NOW, presenca_disponivel: true }
// [F9] Plano f9: o Crítico (o técnico 17 é o da sessão, com o intervalo de almoço do
// cadastro 12:00 às 13:00; o 18 em almoço pela pausa) e o Híbrido, fictícios.
const f9Person = (id, equipe, situacao, pause = null) => ({ ...person(0), id, nome: `Técnico ${id} Exemplo`, equipe, em_pausa: Boolean(pause), motivo_pausa: pause ? pause[0] : null, inicio_pausa: pause ? `2026-10-28T${pause[1]}:00-03:00` : null, almoco_inicio: '12:00', almoco_fim: '13:00', duracao_limite_segundos: pause && pause[0] === 'Almoço' ? 3600 : 1200, presenca: { situacao, origem: pause ? 'pausa' : 'regra_escala', detalhe: pause && pause[0] === 'Almoço' ? `Almoço desde ${pause[1]}` : '' } })
const F9_TEAMS = { critico: [f9Person(17, 'critico', 'presencial'), f9Person(18, 'critico', 'almoco', ['Almoço', '10:05'])], hibrido: [f9Person(19, 'hibrido', 'pausa', ['Café', '10:30']), f9Person(20, 'hibrido', 'fora_expediente')] }
// [AD-F9-1] O Híbrido com o único do turno em pausa: o destaque "sem ninguém disponível".
const F9_AVAILABILITY = { critico: { no_turno: 2, disponiveis: 1, sem_disponivel: false }, hibrido: { no_turno: 1, disponiveis: 0, sem_disponivel: true } }
if (planName === 'f9') Object.assign(STATUS, F9_TEAMS, { disponibilidade_equipes: F9_AVAILABILITY })
const count = (items) => {
  const out = { escalados: 0, disponiveis: 0, em_pausa: 0, almoco: 0, ausentes: 0, fora_expediente: 0, sem_escala: 0 }
  for (const item of items) {
    const s = item.presenca.situacao
    if (s === 'sem_escala') { out.sem_escala++; continue }
    out.escalados++
    if (s === 'presencial' || s === 'remoto') out.disponiveis++
    else if (s === 'pausa') out.em_pausa++
    else if (s === 'almoco') out.almoco++
    else if (s === 'ausente') out.ausentes++
    else out.fora_expediente++
  }
  return out
}
const DASHBOARD = {
  sucesso: true, server_now: SERVER_NOW,
  summary: { tecnicos_monitorados: 16, solicitacoes_pendentes: 3, chamados_criticos_abertos: 1, war_rooms_ativas: 1, plantonistas_ativos: 2, sobreavisos_ativos: 1 },
  presence_meta: { disponivel: true, pausas_disponiveis: true },
  presence_summary: { n1: count(STATUS.n1), n2: count(STATUS.n2), total: count(TEAM), lideranca: { escalados: 2, disponiveis: 2, em_pausa: 0, almoco: 0, ausentes: 0, fora_expediente: 0, sem_escala: 0 } },
}
if (planName === 'f9') Object.assign(DASHBOARD.presence_summary, { critico: count(F9_TEAMS.critico), hibrido: count(F9_TEAMS.hibrido) })
if (planName === 'f9') DASHBOARD.disponibilidade_equipes = F9_AVAILABILITY
// [9.16] Papel vago no Crítico hoje (alerta da Visão operacional, AD-PC-2).
if (planName === 'pc') DASHBOARD.papeis_vagos_critico = [{ id: 2, label: 'Abertura home 07:00–17:00' }]

// ---------------------------------------------------------------------------
// Rotas por perfil
// ---------------------------------------------------------------------------
const ALL = ['/dashboard', '/calendario', '/tecnicos', '/mapa-pa', '/pausas', '/metricas', '/admin', '/admin?tab=funcionarios', '/escala-turnos', '/escala-presencial', '/ausencias', '/plantonistas', '/sobreavisos', '/horas-extras', '/correcao-ponto', '/avisos', '/chamados-criticos', '/documentacao', '/relatorios', '/configuracoes', '/integracoes']
const MAIN = ['/dashboard', '/pausas', '/admin', '/admin?tab=funcionarios', '/escala-presencial', '/mapa-pa', '/calendario', '/chamados-criticos']
const PLAN = [
  ['anonimo', ['/'], [1280, 390]],
  ['admin', ALL, [1280]],
  ['admin', MAIN, [390]],
  ['lideranca', ['/dashboard', '/pausas', '/admin', '/admin?tab=funcionarios', '/escala-presencial', '/mapa-pa'], [1280, 390]],
  ['gestor', ['/dashboard', '/pausas', '/admin', '/calendario', '/metricas'], [1280, 390]],
  ['tecnico', ['/pausas', '/dashboard', '/calendario', '/mapa-pa', '/ausencias'], [1280, 390]],
]
// [R1] Plano dos lotes R: as telas que mais mudam, nos dois tamanhos.
const PLAN_R = [
  ['anonimo', ['/'], [1280, 390]],
  ['admin', MAIN, [1280, 390]],
  ['tecnico', ['/pausas'], [1280, 390]],
  // [R4b] Modo painel da Visão operacional, também na tela fixa de 1920 px.
  ['admin', ['/dashboard?painel=1'], [1920, 1280, 390]],
  // [R4d] Funcionários com o painel lateral de adicionar aberto.
  ['admin', ['/admin?tab=funcionarios#adicionar'], [1280, 390]],
]
// [F10-2] Times de rodízio (Liderança edita) e o grupo da escala no cadastro.
const PLAN_F10 = [
  ['lideranca', ['/escala-presencial', '/escala-presencial#time', '/escala-presencial#semanal', '/admin?tab=funcionarios#editar'], [1280, 390]],
]
// [Marca] O login e o topo da casca (a marca ao lado de "Portal SDK"), nos dois tamanhos.
const PLAN_MARCA = [
  ['anonimo', ['/'], [1280, 390]],
  ['admin', ['/dashboard'], [1280, 390]],
]
// [9.16] Papéis do Crítico (Liderança edita) e o alerta de papel vago.
// [9.17] Controle de CHG: a Liderança edita; o técnico (fora do Tools) só lê.
const PLAN_CHG = [
  ['lideranca', ['/chg', '/chg#novo', '/admin?tab=funcionarios#editar'], [1280, 390]],
  ['tecnico', ['/chg'], [1280, 390]],
]
const PLAN_PC = [
  ['lideranca', ['/escala-presencial#papeis', '/escala-presencial#papeis-painel', '/dashboard'], [1280, 390]],
]
// [F9] Pausas do técnico do Crítico (sessão do técnico 17), a Visão operacional e a edição.
const PLAN_F9 = [
  ['tecnico_critico', ['/pausas', '/pausas#almoco'], [1280, 390]],
  ['admin', ['/dashboard', '/admin?tab=funcionarios#editar'], [1280, 390]],
]
const slug = (route) => route === '/' ? 'login' : route.replace(/^\//, '').replace('?tab=', '-').replace('?painel=1', '-painel').replace('#adicionar', '-adicionar').replace('#time', '-time').replace('#semanal', '-semanal').replace('#editar', '-editar').replace('#almoco', '-almoco').replace('#papeis-painel', '-papeis-painel').replace('#papeis', '-papeis').replace('#novo', '-novo')

// ---------------------------------------------------------------------------
// [R4d] Ações antes da foto, pelo marcador no fim da rota (só no plano dos lotes R).
const ACTIONS = {
  '#adicionar': ["document.querySelector('[data-employee-add]').click()", "!!document.querySelector('[data-employee-create]')"],
  // [F10-2] Ações tolerantes: no "antes" o painel novo não existe e a foto sai sem ele.
  '#time': ["document.querySelector('[data-team-open]')?.click()", "!!document.querySelector('[data-team-drawer]')", true],
  '#semanal': ["(() => { const select = document.querySelector('select[name=\"rule_type\"]'); Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, 'cycle'); select.dispatchEvent(new Event('change', { bubbles: true })); setTimeout(() => [...document.querySelectorAll('main form button')].find((b) => b.textContent.trim() === 'Semana a semana')?.click(), 200) })()", "(() => { const editor = document.querySelector('main form [data-weekly-editor]'); editor?.scrollIntoView({ block: 'center' }); return !!editor })()", true],
  // [F9] Almoço escolhido (tolerante: no "antes" a opção não existe).
  '#almoco': ["document.querySelector('[data-own-pause=\"start\"] input[value=\"Almoço\"]')?.click()", "!!document.querySelector('[data-own-pause=\"start\"] input[value=\"Almoço\"]:checked')", true],
  // [9.16] Seção dos papéis à vista e o painel aberto (tolerantes: no "antes" não existem).
  '#papeis': ["document.querySelector('[data-critical-roles]')?.scrollIntoView({ block: 'start' })", "!!document.querySelector('[data-critical-roles] table')", true],
  '#papeis-painel': ["document.querySelector('[data-critical-open]')?.click()", "!!document.querySelector('[data-critical-drawer]')", true],
  '#novo': ["document.querySelector('[data-chg-new]')?.click()", "!!document.querySelector('[data-chg-form]')"],
  '#editar': ["[...document.querySelectorAll('main button')].find((b) => b.offsetParent !== null && b.textContent.trim() === 'Editar')?.click()", "!!document.querySelector('[role=dialog] form')"],
}
async function shoot(page, url, width, height, file, ready) {
  const marker = Object.keys(ACTIONS).find((key) => url.endsWith(key))
  await page.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 })
  await page.send('Page.navigate', { url: marker ? url.slice(0, -marker.length) : url })
  await page.waitFor(ready, 10000)
  if (marker) {
    await page.evaluate(ACTIONS[marker][0])
    const optional = ACTIONS[marker][2] === true
    await page.waitFor(ACTIONS[marker][1], optional ? 2500 : 10000).catch((error) => { if (!optional) throw error })
  }
  await page.evaluate('document.fonts ? document.fonts.ready.then(() => true) : true')
  await sleep(900)
  const shot = await page.send('Page.captureScreenshot', { format: 'webp', quality: 72, captureBeyondViewport: false })
  writeFileSync(file, Buffer.from(shot.data, 'base64'))
}

async function before() {
  const appDir = appOption ? resolve(appOption) : resolve(here, '../../../app')
  if (!existsSync(join(appDir, 'index.html'))) throw new Error('falta o build em ../app (npm run build)')
  const mock = await startMockServer(appDir)
  // [9.16] Os técnicos 1 e 3 no Crítico, com o rodízio de papéis do servidor simulado.
  if (planName === 'pc') mock.useCriticalTeam(true)
  // [9.17] O módulo ligado na sessão; o servidor simulado devolve can_manage por perfil.
  if (planName === 'chg') mock.useChg({ on: true, canManage: true })
  let profile = 'admin'
  const overrides = { 'session.php': () => (planName === 'chg' ? { ...SESSIONS[profile], modulos: { chg: true } } : SESSIONS[profile]), 'status.php': () => STATUS, 'portal/dashboard.php': () => DASHBOARD }
  const proxy = createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1')
    const key = url.pathname.startsWith('/api/') ? url.pathname.slice(5) : null
    if (req.method === 'GET' && key && overrides[key]) {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' })
      res.end(JSON.stringify(overrides[key]()))
      return
    }
    const upstream = request({ host: '127.0.0.1', port: mock.port, path: req.url, method: req.method, headers: req.headers }, (answer) => {
      res.writeHead(answer.statusCode || 502, answer.headers)
      answer.pipe(res)
    })
    req.pipe(upstream)
  })
  await new Promise((done) => proxy.listen(0, '127.0.0.1', done))
  const base = `http://127.0.0.1:${proxy.address().port}/app`
  const chrome = await launchChrome()
  const page = await openPage(chrome.port)
  if (theme !== null) await page.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: theme === 'escuro' ? 'dark' : 'light' }] })
  const suffix = theme === null ? '' : `-${theme}`
  let total = 0
  let problems = 0
  try {
    for (const [who, routes, widths] of planName === 'r' ? PLAN_R : planName === 'f10' ? PLAN_F10 : planName === 'f9' ? PLAN_F9 : planName === 'marca' ? PLAN_MARCA : planName === 'pc' ? PLAN_PC : planName === 'chg' ? PLAN_CHG : PLAN) {
      profile = who
      if (planName === 'chg') mock.useChg({ on: true, canManage: who !== 'tecnico' })
      for (const width of widths) {
        const height = HEIGHTS.get(width)
        for (const route of routes) {
          const ready = who === 'anonimo' ? "document.querySelector('form') !== null" : "document.querySelector('[data-page-title]') !== null && !document.querySelector('[role=status][aria-label]')"
          await shoot(page, `${base}${route === '/' ? '/' : route}`, width, height, join(outDir, `${who}-${slug(route)}-${width}${suffix}.webp`), ready)
          total++
          if (measureApp && width === 390) {
            const result = await page.evaluate(MEASURE)
            problems += result.small.length + (result.overflow ? 1 : 0)
            if (result.small.length || result.overflow) console.log(`${who} ${route} 390: rolagem lateral ${result.overflow ? 'SIM' : 'nao'}, alvos < 40 px: ${result.small.length}${result.small.length ? ' (' + result.small.slice(0, 8).join(' | ') + ')' : ''}`)
          }
        }
      }
    }
  } finally {
    await page.close()
    await chrome.close()
    proxy.close()
  }
  console.log(`capturas antes: ${total}${measureApp ? `, problemas em 390 px: ${problems}` : ''}`)
  return problems
}

const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' }
// Em 390 px: alvo de toque abaixo de 40 px e rolagem lateral, por tela e tema.
const MEASURE = `(() => {
  const small = [...document.querySelectorAll('a, button, input, select, textarea, [tabindex="0"]')]
    .filter((el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' })
    .filter((el) => { const r = el.getBoundingClientRect(); if (Math.min(r.width, r.height) >= 40) return false; const label = el.matches('input[type=radio], input[type=checkbox]') ? el.closest('label') : null; return !(label && (el.matches('input[type=radio]') || label.getBoundingClientRect().height >= 40)) })
    .map((el) => (el.textContent || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 30))
  return { overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth, small }
})()`
async function verify(base, page) {
  let problems = 0
  for (const screen of ['pausas', 'visao-operacional', 'funcionarios']) {
    for (const [direction, theme] of [['a', 'claro'], ['a', 'escuro'], ['b', 'escuro'], ['b', 'claro']]) {
      await page.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
      await page.send('Page.navigate', { url: `${base}/${screen}.html?direcao=${direction}&tema=${theme}&congelar=1` })
      await page.waitFor("document.documentElement.dataset.pronto === '1'", 10000)
      const result = await page.evaluate(MEASURE)
      problems += result.small.length + (result.overflow ? 1 : 0)
      console.log(`${screen} ${direction}-${theme} 390: rolagem lateral ${result.overflow ? 'SIM' : 'nao'}, alvos < 40 px: ${result.small.length}${result.small.length ? ' (' + result.small.join(' | ') + ')' : ''}`)
    }
  }
  console.log(`problemas: ${problems}`)
  return problems
}

async function after() {
  const server = createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1')
    const relative = normalize(decodeURIComponent(url.pathname)).replace(/^([/\\])+/, '').replace(/^(\.\.[/\\])+/, '')
    const file = join(here, relative || 'pausas.html')
    if (!file.startsWith(here) || !existsSync(file) || statSync(file).isDirectory()) {
      res.writeHead(404)
      res.end()
      return
    }
    res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' })
    res.end(readFileSync(file))
  })
  await new Promise((done) => server.listen(0, '127.0.0.1', done))
  const base = `http://127.0.0.1:${server.address().port}`
  const chrome = await launchChrome()
  const page = await openPage(chrome.port)
  const screens = ['pausas', 'visao-operacional', 'funcionarios']
  if (mode === 'verificar') {
    const problems = await verify(base, page)
    await page.close()
    await chrome.close()
    server.close()
    process.exit(problems === 0 ? 0 : 1)
  }
  const looks = [['a', 'claro'], ['a', 'escuro'], ['b', 'escuro'], ['b', 'claro']]
  let total = 0
  try {
    for (const [direction, theme] of looks) {
      for (const [width, height] of WIDTHS) {
        for (const screen of screens) {
          await shoot(page, `${base}/${screen}.html?direcao=${direction}&tema=${theme}&congelar=1`, width, height, join(outDir, `${screen}-${direction}-${theme}-${width}.webp`), "document.documentElement.dataset.pronto === '1'")
          total++
        }
      }
    }
    // Estados que a tela inicial nao mostra: a pausa em andamento e o painel de edicao.
    for (const [screen, extra, name] of [['pausas', 'estado=ativa', 'pausas-ativa'], ['funcionarios', 'painel=editar', 'funcionarios-editar']]) {
      for (const [direction, theme] of [['a', 'claro'], ['b', 'escuro']]) {
        for (const [width, height] of WIDTHS) {
          await shoot(page, `${base}/${screen}.html?direcao=${direction}&tema=${theme}&congelar=1&${extra}`, width, height, join(outDir, `${name}-${direction}-${theme}-${width}.webp`), "document.documentElement.dataset.pronto === '1'")
          total++
        }
      }
    }
  } finally {
    await page.close()
    await chrome.close()
    server.close()
  }
  console.log(`capturas depois: ${total}`)
}

const appProblems = await (mode === 'antes' ? before() : after())
// O servidor simulado nao expoe close(): sai explicitamente.
process.exit(measureApp && appProblems > 0 ? 1 : 0)
