import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

// F9 (desenho principal, 9.15): equipes Crítico e Híbrido no frontend.
// 1. Lista única de equipes (lib/teams) e o timer do almoço (lib/pauseTimer).
// 2. Pausas renderizada de verdade (react-dom/server): as quatro equipes, o
//    selo do Crítico (D-F9-18), "Almoço" só na sessão do Crítico, com o limite
//    do intervalo do cadastro ou o aviso de sem limite (D-F9-6 revista).
// 3. Telas com as equipes pela lista única (filtros e formulários).

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const srcUrl = pathToFileURL(join(frontendRoot, 'src')).href + '/'
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
    if (url.startsWith('file:') && /\.(jsx|tsx|ts)$/u.test(url)) {
      let source = readFileSync(fileURLToPath(url), 'utf8')
      // O render estático não clica: o teste dá o motivo escolhido.
      if (url.endsWith('/src/pages/PausasPage.jsx')) {
        const from = "const [reason, setReason] = useState('')"
        if (!source.includes(from)) throw new Error('PausasPage sem o estado do motivo esperado pelo QA')
        source = source.replace(from, "const [reason, setReason] = useState(globalThis.qaInitialReason ?? '')")
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(url) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const teams = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'teams.ts')).href)
const timer = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'pauseTimer.ts')).href)
const { employeeFormSchema } = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'formSchemas.ts')).href)
const { lunchOptions } = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'employeeLunch.ts')).href)
const { PausasPage } = await import(pathToFileURL(join(frontendRoot, 'src', 'pages', 'PausasPage.jsx')).href)
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')
let checks = 0
const check = (fn) => { fn(); checks += 1 }

// ---------------------------------------------------------------------------
// 1. Lista única e timer
// ---------------------------------------------------------------------------
check(() => assert.deepEqual(teams.WORK_TEAMS.map((team) => team.value), ['n1', 'n2', 'critico', 'hibrido'], 'equipes de técnico'))
check(() => assert.deepEqual(teams.ALL_TEAMS.map((team) => [team.value, team.label]), [['n1', 'N1'], ['n2', 'N2'], ['lideranca', 'Liderança'], ['critico', 'Crítico'], ['hibrido', 'Híbrido']], 'equipes de cadastro, com os rótulos'))
check(() => assert.deepEqual(teams.COMMON_GROUP, ['n1', 'n2'], 'grupo comum: N1 e N2'))
check(() => assert.deepEqual(['critico', 'Crítico', 'CRÍTICO', 'hibrido', 'n1', 'x'].map(teams.teamLabel), ['Crítico', 'Crítico', 'Crítico', 'Híbrido', 'N1', 'X'], 'rótulo pela lista única'))
check(() => assert.deepEqual(['n1', 'n2', 'lideranca', 'critico', 'Crítico', 'hibrido'].map(teams.teamShort), ['N1', 'N2', 'LIDERANCA', 'Crítico', 'Crítico', 'Híbrido'], 'rótulo curto: o de antes para N1, N2 e Liderança; o da lista única para Crítico e Híbrido'))
check(() => assert.deepEqual(['critico', 'Crítico', ' crítico ', 'hibrido', 'n1', null].map(teams.isCriticalTeam), [true, true, true, false, false, false], 'equipe Crítico'))
check(() => assert.deepEqual([
  timer.pauseLimitSeconds({ motivo_pausa: 'Almoço', duracao_limite_segundos: 0 }),
  timer.pauseLimitSeconds({ motivo_pausa: 'Almoço' }),
  timer.pauseLimitSeconds({ motivo_pausa: 'Almoço', duracao_limite_segundos: 3600 }),
  timer.pauseLimitSeconds({ motivo_pausa: 'Café', duracao_limite_segundos: 0 }),
  timer.pauseLimitSeconds({ motivo_pausa: 'Café', duracao_limite_segundos: 1500 }),
  timer.pauseLimitSeconds({ motivo_pausa: 'Pessoal', duracao_limite_segundos: 1500 }),
], [0, 0, 3600, 1200, 1500, 0], 'limite: almoço sem limite do servidor fica sem limite (nunca os 20 min); café como antes'))
check(() => assert.deepEqual(timer.pauseTiming({ motivo_pausa: 'Almoço', duracao_limite_segundos: 0 }, 99999), { limit: 0, elapsed: 99999, overdue: false, remaining: null, ratio: 0 }, 'almoço sem limite nunca excede'))
check(() => assert.equal(timer.pauseTiming({ motivo_pausa: 'Almoço', duracao_limite_segundos: 3600 }, 3601).overdue, true, 'almoço com limite excede como o café'))
for (const [start, end, expected] of [['12:00', '13:00', 3600], ['11:30', '12:45', 4500], ['12:00:00', '12:00:30', 30], ['13:00', '12:00', null], ['12:00', '12:00', null], ['24:00', '24:30', null], ['', '13:00', null], [null, null, null]]) {
  check(() => assert.equal(timer.lunchIntervalSeconds(start, end), expected, `intervalo do almoço ${start} a ${end}`))
}
check(() => assert.deepEqual([timer.isLunch('Almoço'), timer.isLunch('almoço'), timer.isLunch('Café')], [true, false, false], 'almoço pelo motivo exato'))

// ---------------------------------------------------------------------------
// 2. Pausas
// ---------------------------------------------------------------------------
const noop = () => {}
const worker = (id, equipe, extra = {}) => ({
  id, nome: `QA ${id}`, equipe, em_pausa: false, motivo_pausa: null, status_aprovacao: null,
  jornada_entrada: '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', duracao_limite_segundos: 1200, ...extra,
})
const byTeam = (items) => Object.fromEntries(teams.WORK_TEAMS.map((team) => [team.value, items.filter((item) => item.equipe === team.value)]))
const renderPausas = (items, id, reason, extraStatus = {}) => {
  globalThis.qaInitialReason = reason
  try {
    return renderToStaticMarkup(h(PausasPage, {
      session: { role: 'tecnico', permissions: [], ci: { autenticado: true, funcionario_id: id, nome: 'QA', username: 'qa' } },
      status: { ...byTeam(items), ...extraStatus }, livePauses: { status: { ...byTeam(items), ...extraStatus }, connection: 'online', serverNow: Date.now(), elapsedFor: () => 90 },
      loading: false, onStart: noop, onRequest: noop, onFinish: noop, onForceEndBreak: noop,
    }))
  } finally {
    delete globalThis.qaInitialReason
  }
}
const ownPause = (markup) => markup.slice(markup.indexOf('aria-labelledby="sua-pausa"'), markup.indexOf('aria-labelledby="equipe-agora"'))
const everyone = [worker(1, 'n1'), worker(2, 'n2'), worker(3, 'critico'), worker(4, 'hibrido'), worker(5, 'critico', { almoco_inicio: '13:00', almoco_fim: '13:00' })]
{
  const markup = renderPausas(everyone, 3)
  check(() => assert.deepEqual([...markup.matchAll(/<tbody data-team="([^"]+)">/gu)].map((match) => match[1]), ['N1', 'N2', 'Crítico', 'Híbrido'], 'Pausas: as quatro equipes, na ordem'))
  const critical = markup.slice(markup.indexOf('<tbody data-team="Crítico">'), markup.indexOf('<tbody data-team="Híbrido">'))
  check(() => assert.match(critical, /Equipe Crítico · \d+ de 2 disponíveis[\s\S]*Equipe crítica/u, 'Pausas: o Crítico com o selo e o rótulo (D-F9-18)'))
  check(() => assert.equal((markup.match(/Equipe crítica/gu) ?? []).length, 1, 'Pausas: o selo só no Crítico'))
  const own = ownPause(markup)
  check(() => assert.match(own, /value="Almoço"[\s\S]*Almoço<\/span><small class="text-xs text-muted">até 60 min<\/small>/u, 'Pausas: o Crítico vê "Almoço" com o limite do intervalo do cadastro'))
  check(() => assert.equal((own.match(/name="motivo_pausa"/gu) ?? []).length, 4, 'Pausas: quatro motivos para o Crítico'))
}
for (const id of [1, 2, 4]) {
  const own = ownPause(renderPausas(everyone, id))
  check(() => assert.equal(own.includes('Almoço'), false, `Pausas: sem "Almoço" para a equipe ${everyone[id - 1].equipe}`))
  check(() => assert.equal((own.match(/name="motivo_pausa"/gu) ?? []).length, 3, `Pausas: três motivos para a equipe ${everyone[id - 1].equipe}`))
}
{
  const own = ownPause(renderPausas(everyone, 5, 'Almoço'))
  check(() => assert.match(own, /Almoço<\/span><small class="text-xs text-muted">sem limite automático<\/small>/u, 'Pausas: sem intervalo válido, "sem limite automático"'))
  check(() => assert.ok(own.includes(timer.LUNCH_NO_LIMIT_NOTE) && own.includes('Iniciar almoço'), 'Pausas: aviso da DL17 e o botão do almoço'))
  const withInterval = ownPause(renderPausas(everyone, 3, 'Almoço'))
  check(() => assert.equal(withInterval.includes(timer.LUNCH_NO_LIMIT_NOTE), false, 'Pausas: com intervalo válido, sem o aviso'))
}
{
  const lunchNoLimit = [worker(5, 'critico', { em_pausa: true, motivo_pausa: 'Almoço', status_aprovacao: 'aprovado', inicio_pausa: '2026-11-16T12:00:00-03:00', duracao_limite_segundos: 0 })]
  const own = ownPause(renderPausas(lunchNoLimit, 5))
  check(() => assert.ok(own.includes('Timer ativo sem limite automático') && own.includes(timer.LUNCH_NO_LIMIT_NOTE), 'Pausas: almoço em andamento sem limite mostra o aviso'))
  const lunchLimit = [worker(3, 'critico', { em_pausa: true, motivo_pausa: 'Almoço', status_aprovacao: 'aprovado', inicio_pausa: '2026-11-16T12:00:00-03:00', duracao_limite_segundos: 3600 })]
  const withLimit = ownPause(renderPausas(lunchLimit, 3))
  check(() => assert.ok(withLimit.includes('limite de 60 min') && !withLimit.includes(timer.LUNCH_NO_LIMIT_NOTE), 'Pausas: almoço com limite mostra o limite, sem o aviso'))
}

// [AD-F9-2] Reunião sem aprovação no Crítico e no Híbrido; N1 e N2 com o texto de antes.
check(() => assert.deepEqual(['n1', 'n2', 'critico', 'Crítico', 'hibrido', 'lideranca'].map(teams.meetingNeedsApproval), [true, false, false, false, false, true], 'reunião sem aprovação no N2, no Crítico e no Híbrido'))
for (const [id, hint, action] of [[3, 'sem aprovação', 'Iniciar reunião'], [4, 'sem aprovação', 'Iniciar reunião'], [1, 'com aprovação', 'Pedir aprovação da reunião'], [2, 'com aprovação', 'Pedir aprovação da reunião']]) {
  const own = ownPause(renderPausas(everyone, id, 'Reunião'))
  check(() => assert.ok(own.includes(`Reunião</span><small class="text-xs text-muted">${hint}</small>`) && own.includes(`>${action}</button>`), `Pausas: reunião da equipe ${everyone[id - 1].equipe}: "${hint}" e "${action}"`))
}
// [AD-F9-1] Destaque "sem ninguém disponível" pelo servidor (selo e texto).
check(() => assert.deepEqual([
  teams.noneAvailableLabels({ critico: { sem_disponivel: true }, hibrido: { sem_disponivel: false } }),
  teams.noneAvailableLabels({ critico: { sem_disponivel: true }, hibrido: { sem_disponivel: true } }),
  teams.noneAvailableLabels([]), teams.noneAvailableLabels(null), teams.noneAvailableLabels({ n1: { sem_disponivel: true } }),
], [['Crítico sem ninguém disponível'], ['Crítico sem ninguém disponível', 'Híbrido sem ninguém disponível'], [], [], []], 'destaque pela disponibilidade das equipes'))
{
  const markup = renderPausas(everyone, 1, undefined, { disponibilidade_equipes: { critico: { no_turno: 2, disponiveis: 0, sem_disponivel: true } } })
  check(() => assert.match(markup, /inline-alert-danger[\s\S]*Crítico sem ninguém disponível[\s\S]*data-none-available=""/u, 'Pausas: o aviso "Crítico sem ninguém disponível" no topo'))
  const critical = markup.slice(markup.indexOf('<tbody data-team="Crítico">'), markup.indexOf('<tbody data-team="Híbrido">'))
  check(() => assert.ok(critical.includes('Crítico sem ninguém disponível') && !markup.slice(markup.indexOf('<tbody data-team="Híbrido">')).includes('sem ninguém disponível'), 'Pausas: o selo no grupo do Crítico, não no Híbrido'))
  check(() => assert.equal(renderPausas(everyone, 1).includes('sem ninguém disponível'), false, 'Pausas: sem o destaque quando há disponível'))
}
check(() => assert.match(read('src/pages/DashboardPage.jsx'), /noneAvailableLabels\(livePauses\.status\?\.disponibilidade_equipes \?\? data\?\.disponibilidade_equipes\)\.map\(\(label\) => \(\s*<InlineAlert key=\{label\} title=\{label\} tone="danger">/u, 'Visão operacional: o destaque pelo status ao vivo (ou o do dashboard.php)'))
// [AD-F9-4] Híbrido almoça pelo cadastro às 18:00: o formulário aceita e o filtro mostra.
check(() => assert.equal(employeeFormSchema.safeParse({ id: 7, nome: 'Tecnico Hibrido', ad_login: '', equipe: 'hibrido', access_role: 'tecnico', jornada_entrada: '10:30', jornada_saida: '20:30', almoco_inicio: '18:00', almoco_fim: '19:00', fora_mapa_escala: false }).success, true, 'cadastro: Híbrido com almoço 18:00-19:00 na jornada 10:30-20:30'))
check(() => assert.ok(lunchOptions([{ almoco_inicio: '18:00:00', almoco_fim: '19:00:00' }, { almoco_inicio: '12:00', almoco_fim: '13:00' }]).some((option) => option.value === '18:00-19:00'), 'filtro "Almoço": a opção 18:00-19:00 sai do cadastro'))

// ---------------------------------------------------------------------------
// 3. Telas pela lista única
// ---------------------------------------------------------------------------
const WORK_OPTS = '{WORK_TEAMS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}'
const ALL_OPTS = '{ALL_TEAMS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}'
for (const [file, needle, count] of [
  ['src/pages/CalendarPage.jsx', WORK_OPTS, 2],
  ['src/pages/MetricasPage.jsx', WORK_OPTS, 1],
  ['src/pages/OperationalPages.jsx', WORK_OPTS, 2],
  ['src/pages/AbsencesPage.jsx', ALL_OPTS, 1],
  ['src/pages/AdminPage.jsx', ALL_OPTS, 2],
  ['src/pages/PaMapPage.jsx', ALL_OPTS, 1],
  ['src/pages/ShiftSchedulesPage.jsx', ALL_OPTS, 1],
]) {
  check(() => assert.equal(read(file).split(needle).length - 1, count, `${file}: equipes pela lista única`))
  check(() => assert.equal(/<option value="n1">N1<\/option>/u.test(read(file)), false, `${file}: sem lista fixa de equipe`))
}
check(() => assert.match(read('src/pages/RotationTeams.jsx'), /COMMON_GROUP\.includes\(String\(employee\.equipe\)\.toLowerCase\(\)\)/u, 'Times de rodízio: só o grupo comum (D-F9-11)'))
check(() => assert.match(read('src/pages/AdminPage.jsx'), /if \(SEPARATE_GROUP_TEAMS\.includes\(team\) && \['comum', 'separado'\]\.includes\(form\.grupo_escala\)\) setValue\('grupo_escala', 'separado', \{ shouldDirty: true \}\)/u, 'Funcionários: Crítico e Híbrido levam o grupo para separado (D-F9-12)'))
check(() => assert.match(read('src/pages/AdminPage.jsx'), /else if \(SEPARATE_GROUP_TEAMS\.includes\(previous\) && COMMON_GROUP\.includes\(team\) && form\.grupo_escala === 'separado'\) setValue\('grupo_escala', 'comum', \{ shouldDirty: true \}\)/u, 'Funcionários: de Crítico ou Híbrido para N1 ou N2, o grupo vem comum (D-F9-25 revista)'))
check(() => assert.match(read('src/pages/AdminPage.jsx'), /<option disabled=\{SEPARATE_GROUP_TEAMS\.includes\(normalizeTeam\(form\.equipe\)\)\} value="comum">/u, 'Funcionários: grupo comum desabilitado para Crítico e Híbrido'))
check(() => assert.match(read('src/pages/DashboardPage.jsx'), /const liveEmployees = WORK_TEAMS\.flatMap\(\(team\) => livePauses\.status\?\.\[team\.value\] \|\| \[\]\)/u, 'Visão operacional: pausas em andamento das quatro equipes'))
check(() => assert.match(read('src/pages/DashboardPage.jsx'), /\{lunches > 0 && <> · Almoço \{lunches\}<\/>\}/u, 'Visão operacional: o almoço no resumo das pausas só quando há'))
check(() => assert.equal(/\.toUpperCase\(\)/u.test(read('src/pages/DashboardPage.jsx').split('function ActivePauses')[1].split('\nfunction ')[0]), false, 'Visão operacional: equipe da pausa pelo rótulo curto (Crítico com acento)'))
check(() => assert.match(read('src/pages/DashboardPage.jsx'), /const SEPARATE_ROWS = \[\['critico', 'Crítico', 'fora dos totais'\], \['hibrido', 'Híbrido', 'fora dos totais'\], \['lideranca', 'Liderança', 'fora dos totais'\]\]/u, 'Visão operacional: Crítico e Híbrido fora dos totais (D-F9-10)'))

console.log(`QA critico e hibrido (frontend) OK (${checks} verificacoes)`)
