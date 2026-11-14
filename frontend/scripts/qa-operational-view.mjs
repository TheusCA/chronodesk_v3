import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

// F7 (desenho 5.7 e 9.9): Visão Operacional. Commit 2 (DV11): a caixa da
// observação de reunião só aparece quando o servidor manda a chave
// observacao_reuniao (gestão e o próprio); os cartões de colegas da tela de
// Pausas não mudam. Commit 3: tabela da 5.7 pelo presence_summary, legenda,
// Liderança fora do total, nota, estado degradado, DV14, tela Técnicos (DV8)
// e cartões de Pausas pela presenca (DV12). Pendência 17d: abaixo de 1280 px,
// cartões por equipe no lugar da tabela. Os componentes são renderizados
// de verdade, como no qa:pa-map (JSX transpilado pelo TypeScript do projeto,
// react-dom/server).

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')

// O DashboardPage e a tela Técnicos buscam o dashboard.php e o
// technicians.php pelo useResource; aqui ele responde com o que o teste pôs
// em globalThis.qaResourceData, sem rede. O resto do src é o código real.
const resourceStub = 'data:text/javascript,' + encodeURIComponent(
  'export function useResource() { return { loading: false, error: null, data: globalThis.qaResourceData ?? { summary: {} }, refresh: async () => null, setData: () => {} } }',
)
const srcUrl = pathToFileURL(join(frontendRoot, 'src')).href + '/'
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && /\/src\/pages\/(DashboardPage|TechniciansPage)\.jsx$/u.test(context.parentURL ?? '')) {
      return { url: resourceStub, shortCircuit: true }
    }
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
      // [Ajuste A2] O render estático não digita: o teste dá o termo inicial
      // da busca da tela Técnicos (globalThis.qaInitialSearch).
      if (url.endsWith('/src/pages/TechniciansPage.jsx')) {
        const searchState = "const [search, setSearch] = useState('')"
        if (!source.includes(searchState)) throw new Error('TechniciansPage sem o estado da busca esperado pelo QA')
        source = source.replace(searchState, "const [search, setSearch] = useState(globalThis.qaInitialSearch ?? '')")
      }
      // [R4a] O render estático não clica: o teste dá o motivo escolhido e o
      // filtro da lista (globalThis.qaInitialReason e qaInitialFilter).
      if (url.endsWith('/src/pages/PausasPage.jsx')) {
        for (const [from, to] of [
          ["const [reason, setReason] = useState('')", "const [reason, setReason] = useState(globalThis.qaInitialReason ?? '')"],
          ["const [filter, setFilter] = useState('todos')", "const [filter, setFilter] = useState(globalThis.qaInitialFilter ?? 'todos')"],
        ]) {
          if (!source.includes(from)) throw new Error(`PausasPage sem o estado esperado pelo QA: ${from}`)
          source = source.replace(from, to)
        }
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(url) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { DashboardPage } = await import(pathToFileURL(join(frontendRoot, 'src', 'pages', 'DashboardPage.jsx')).href)
const { PausasPage } = await import(pathToFileURL(join(frontendRoot, 'src', 'pages', 'PausasPage.jsx')).href)
const { TechniciansPage } = await import(pathToFileURL(join(frontendRoot, 'src', 'pages', 'TechniciansPage.jsx')).href)
const { NOW_PRESENCE_LEGEND, OPERATIONAL_SUMMARY_COLUMNS, serverClock, summaryCount } = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'presenceLegend.ts')).href)
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')

const noop = () => {}
const pause = (id, motivo, extra = {}) => ({
  id, nome: `QA ${id}`, equipe: 'n1', em_pausa: true, motivo_pausa: motivo, status_aprovacao: 'aprovado',
  jornada_entrada: '08:00', jornada_saida: '17:00', ...extra,
})
// Como o status.php responde ao técnico 1 (DV11): a própria observação com a
// chave; a do colega 2, sem; o colega 3 em café, com a chave (motivo da lista).
const technicianView = [
  pause(1, 'Reunião', { observacao_reuniao: 'QA pauta própria' }),
  pause(2, 'Reunião'),
  pause(3, 'Café', { observacao_reuniao: null }),
]
// Como responde à gestão: a chave em todos, com valor ou nula.
const managerView = [
  pause(1, 'Reunião', { observacao_reuniao: 'QA pauta própria' }),
  pause(2, 'Reunião', { observacao_reuniao: null }),
  pause(3, 'Café', { observacao_reuniao: null }),
]
const livePauses = (employees) => ({
  employees, status: { n1: employees, n2: [] }, connection: 'online', serverNow: Date.now(), elapsedFor: () => 90,
})
const session = (role, funcionarioId) => ({
  role, permissions: [], ci: { autenticado: true, funcionario_id: funcionarioId, nome: 'QA', username: 'qa' },
})
// Um pedaço do HTML por cartão de técnico, na ordem dos itens (Visão operacional).
const cards = (markup) => markup.split('<article class="operator-card').slice(1)
// [R4a] Pausas: uma linha da lista densa por técnico, com o estado em data-state.
const teamRows = (markup) => [...markup.matchAll(/<tr (data-overdue="" )?data-row="" data-state="([a-z]+)">([\s\S]*?)<\/tr>/gu)].map((match) => ({ overdue: Boolean(match[1]), state: match[2], html: match[3] }))
const ownPause = (markup) => markup.slice(markup.indexOf('aria-labelledby="sua-pausa"'), markup.indexOf('aria-labelledby="equipe-agora"'))
const NO_NOTE = 'Sem observação informada'

// ---------------------------------------------------------------------------
// 1. Visão Operacional: "Pausas em andamento"
// ---------------------------------------------------------------------------
const renderDashboard = (employees, role, id) => renderToStaticMarkup(h(DashboardPage, {
  navigate: noop, session: session(role, id), livePauses: livePauses(employees), loading: false, onForceEndBreak: noop,
}))
{
  // [R4b] Os cartões viraram linhas da lista "Pausas em andamento"; a regra do DV11 é a mesma.
  const [own, colleague, coffee] = teamRows(renderDashboard(technicianView, 'tecnico', 1)).map((row) => row.html)
  assert.match(own, /QA pauta própria/u, 'DV11: a própria observação de reunião aparece')
  assert.doesNotMatch(colleague, /line-clamp-2|Sem observação informada/u, 'DV11: sem a chave, a observação do colega não aparece (nem "Sem observação informada")')
  assert.match(colleague, /QA 2[\s\S]*Reunião/u, 'DV11: a linha do colega continua, com o motivo da lista fechada')
  assert.doesNotMatch(coffee, /line-clamp-2/u, 'café: nunca a observação, com ou sem a chave')
}
{
  const [own, colleague] = teamRows(renderDashboard(managerView, 'gestor', 0)).map((row) => row.html)
  assert.match(own, /QA pauta própria/u, 'gestão: a observação de reunião aparece')
  assert.ok(colleague.includes(NO_NOTE), 'gestão: chave nula continua "Sem observação informada", como antes')
}

// ---------------------------------------------------------------------------
// 2. Pausas: cartões dos colegas sem mudança; a própria pausa continua
// ---------------------------------------------------------------------------
const renderPausas = (employees, role, id) => renderToStaticMarkup(h(PausasPage, {
  session: session(role, id), status: { n1: employees, n2: [] }, livePauses: livePauses(employees),
  loading: false, onStart: noop, onRequest: noop, onFinish: noop, onForceEndBreak: noop,
}))
{
  // [R4a] Os cartões viraram linhas da lista; a regra do DV11 é a mesma.
  const markup = renderPausas(technicianView, 'tecnico', 1)
  const [, colleague] = teamRows(markup)
  assert.match(ownPause(markup), /data-own-pause="active"[\s\S]*QA pauta própria/u, 'Pausas: a própria pausa de reunião mostra a observação')
  assert.doesNotMatch(colleague.html, /line-clamp-2|Sem observação informada/u, 'Pausas: linha do colega sem a chave fica sem a observação')
  assert.equal(markup.includes(NO_NOTE), false, 'Pausas: nenhum "Sem observação informada" para o técnico')
}

// ---------------------------------------------------------------------------
// 3. Visão Operacional: tabela da 5.7 pelo presence_summary (commit 3)
// ---------------------------------------------------------------------------
const group = (escalados, disponiveis, emPausa, almoco, ausentes, foraExpediente, semEscala) => ({
  escalados, disponiveis, em_pausa: emPausa, almoco, ausentes, fora_expediente: foraExpediente, sem_escala: semEscala,
})
// Números distintos por célula, para achar cada um no HTML.
const SUMMARY = {
  n1: group(11, 4, 2, 1, 3, 1, 21),
  n2: group(12, 7, 1, 2, 0, 2, 22),
  lideranca: group(5, 5, 0, 0, 0, 0, 23),
  total: group(23, 11, 3, 3, 3, 3, 43),
}
const dashboardData = (extra = {}) => ({
  sucesso: true,
  server_now: '2026-10-27T10:32:45-03:00',
  summary: { tecnicos_monitorados: 30, cis_disponiveis: 11, pausas_ativas: 3, solicitacoes_pendentes: 0 },
  presence_summary: SUMMARY,
  presence_meta: { disponivel: true, pausas_disponiveis: true },
  active_pauses: [],
  ...extra,
})
// O cálculo antigo do status.php diria 9 disponíveis (disponibilidade), 6 no N1 e 3 no N2.
const legacyAvailable = [
  ...Array.from({ length: 6 }, (_, index) => ({ id: 100 + index, nome: `QA L${index}`, equipe: 'n1', em_pausa: false, disponibilidade: { status: 'disponivel', label: 'Disponível' } })),
  ...Array.from({ length: 3 }, (_, index) => ({ id: 200 + index, nome: `QA M${index}`, equipe: 'n2', em_pausa: false, disponibilidade: { status: 'disponivel', label: 'Disponível' } })),
]
const renderDashboardWith = (data, live = {}) => {
  globalThis.qaResourceData = data
  const employees = live.employees ?? legacyAvailable
  try {
    return renderToStaticMarkup(h(DashboardPage, {
      navigate: noop, session: session('gestor', 0), loading: false, onForceEndBreak: noop,
      livePauses: { employees, status: live.status ?? { n1: employees.filter((item) => item.equipe === 'n1'), n2: employees.filter((item) => item.equipe === 'n2'), server_now: '2026-10-27T10:32:40-03:00' }, connection: live.connection ?? 'online', serverNow: live.serverNow ?? Date.parse('2026-10-27T10:32:50-03:00'), elapsedFor: () => 0 },
    }))
  } finally {
    delete globalThis.qaResourceData
  }
}
const summarySection = (markup) => {
  const start = markup.indexOf('Situação da equipe agora')
  assert.ok(start > 0, 'Visão Operacional com a seção "Situação da equipe agora"')
  return markup.slice(start, markup.indexOf('</section>', markup.indexOf('</table>', start)))
}
const rowValues = (section, label) => {
  const row = section.split('<tr').find((part) => part.includes(`scope="row">${label}<`))
  assert.ok(row, `linha ${label} na tabela`)
  return [...row.matchAll(/<strong>([^<]*)<\/strong>/gu)].map((match) => match[1])
}
// [17d] Cartão de uma equipe (abaixo de 1280 px): contadores e rótulos, na
// ordem das colunas da tabela.
const teamCard = (section, team) => {
  const start = section.indexOf(`data-team="${team}"`)
  assert.ok(start > 0, `cartão da equipe ${team}`)
  return section.slice(start, section.indexOf('</article>', start))
}
const cardValues = (section, team) => [...teamCard(section, team).matchAll(/<dd><strong[^>]*>([^<]*)<\/strong><\/dd>/gu)].map((match) => match[1])
const columnOrder = ['escalados', 'disponiveis', 'em_pausa', 'almoco', 'ausentes', 'fora_expediente', 'sem_escala']
const expectedRow = (team, dash = []) => columnOrder.map((key) => (dash.includes(key) ? '—' : String(SUMMARY[team][key])))
const textOf = (html) => html.replace(/^[^>]*>/u, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()
// [R4b] Os 8 cartões viraram a lista "Totais da operação" (mesmos rótulos).
const metricValue = (markup, label) => markup.match(new RegExp(`<dt class="text-muted">${label}</dt><dd class="[^"]*">([^<]*)</dd>`, 'u'))?.[1]
const teamAvailable = (markup, team) => markup.match(new RegExp(`data-team-now="${team}"[\\s\\S]*?data-available="([^"]*)"`, 'u'))?.[1]
{
  const markup = renderDashboardWith(dashboardData())
  const section = summarySection(markup)
  assert.deepEqual(rowValues(section, 'N1'), expectedRow('n1'), 'linha N1 pelo presence_summary, na ordem das colunas')
  assert.deepEqual(rowValues(section, 'N2'), expectedRow('n2'), 'linha N2 pelo presence_summary')
  assert.deepEqual(rowValues(section, 'Total'), expectedRow('total'), 'linha Total = total do servidor (N1 + N2)')
  assert.ok(section.includes('scope="row">Total<small class="font-normal normal-case tracking-normal">N1 + N2</small></th>'), 'Total com a nota "N1 + N2"')
  assert.deepEqual(rowValues(section, 'Liderança'), expectedRow('lideranca'), 'Liderança separada, com o rótulo "fora dos totais"')
  assert.ok(section.includes('scope="row">Liderança<small class="font-normal normal-case tracking-normal">fora dos totais</small></th>'), 'Liderança com a nota "fora dos totais"')
  const bodies = section.split('<tbody')
  assert.equal(bodies.length, 3, 'Liderança num bloco à parte da tabela (N1, N2 e Total num, Liderança no outro)')
  assert.match(bodies[1], /N1[\s\S]*N2[\s\S]*>Total<small/u, 'N1, N2 e Total juntos')
  assert.ok(!bodies[1].includes('Liderança') && bodies[2].includes('Liderança'), 'Liderança fora do bloco do total')
  // [17d] Cartões por equipe abaixo de 1280 px, com os mesmos números da tabela.
  for (const team of ['n1', 'n2', 'total', 'lideranca']) {
    assert.deepEqual(cardValues(section, team), expectedRow(team), `17d: cartão ${team} com os números da tabela, na ordem das colunas`)
    const labels = teamCard(section, team).split('<dt>').slice(1)
    assert.equal(labels.length, OPERATIONAL_SUMMARY_COLUMNS.length, `17d: cartão ${team} com um rótulo por contador`)
    OPERATIONAL_SUMMARY_COLUMNS.forEach((column, index) => {
      assert.ok(labels[index].includes(column.tone) && /<svg[^>]*aria-hidden="true"/u.test(labels[index]) && textOf(labels[index].slice(0, labels[index].indexOf('</dt>'))) === column.label, `17d: cartão ${team}, ${column.label} com cor, ícone e rótulo da legenda`)
    })
  }
  assert.ok(teamCard(section, 'total').includes('>Total<small class="ml-2 text-xs font-normal text-muted">N1 + N2</small></h3>'), '17d: cartão Total com a nota "N1 + N2"')
  assert.ok(teamCard(section, 'lideranca').includes('>Liderança<small class="ml-2 text-xs font-normal text-muted">fora dos totais</small></h3>'), '17d: cartão Liderança com a nota "fora dos totais"')
  const cardsStart = section.indexOf('<div class="mt-5 xl:hidden">')
  const divider = section.indexOf('border-t-2 border-line pt-3', cardsStart)
  assert.ok(cardsStart > 0 && cardsStart < section.indexOf('<table'), '17d: cartões só abaixo de 1280 px (xl:hidden), antes da tabela')
  assert.ok(['n1', 'n2', 'total'].every((team) => section.indexOf(`data-team="${team}"`) < divider) && section.indexOf('data-team="lideranca"') > divider, '17d: Liderança num bloco à parte, depois de N1, N2 e Total')
  assert.ok(section.includes('<div class="table-wrap mt-5 hidden xl:block">'), '17d: a tabela só de 1280 px para cima (hidden xl:block)')
  assert.equal((section.match(/data-team="/gu) ?? []).length, 4, '17d: quatro cartões')
  assert.equal((section.match(/<div class="(?:mt-3 )?grid gap-3 (?:border-t-2 border-line pt-3 )?sm:grid-cols-2 md:grid-cols-3">/gu) ?? []).length, 2, '17d: uma coluna no celular, duas de 640 px e três de 768 px, nos dois blocos (a Liderança com a largura dos outros)')
  // Cabeçalho: cada coluna com o ícone, a cor e o rótulo da legenda.
  const head = section.slice(section.indexOf('<thead>'), section.indexOf('</thead>'))
  const headers = head.split('<th ').slice(2)
  assert.equal(headers.length, OPERATIONAL_SUMMARY_COLUMNS.length, 'uma coluna por contador')
  OPERATIONAL_SUMMARY_COLUMNS.forEach((column, index) => {
    assert.ok(headers[index].includes(column.tone), `coluna ${column.label} com a cor da legenda (${column.tone})`)
    assert.match(headers[index], /<svg[^>]*aria-hidden="true"/u, `coluna ${column.label} com ícone`)
    assert.equal(textOf(headers[index]), column.label, `coluna ${column.label} com rótulo`)
    // [Ajuste A1] O rótulo pode quebrar em duas linhas, com o ícone preso à
    // primeira palavra; nada de whitespace-nowrap no selo do cabeçalho.
    const [first, ...rest] = column.label.split(' ')
    assert.ok(headers[index].includes(`<span class="inline-flex items-center gap-1 whitespace-nowrap"><svg`) && headers[index].includes(`</svg>${first}</span>`), `coluna ${column.label}: ícone junto da primeira palavra`)
    if (rest.length > 0) assert.ok(headers[index].includes(`</svg>${first}</span><span class="whitespace-nowrap">${rest.join(' ')}</span>`), `coluna ${column.label}: o resto do rótulo vai inteiro para a linha de baixo (no máximo duas linhas)`)
    const badge = headers[index].match(/<span class="(status-badge[^"]*)"/u)?.[1] ?? ''
    assert.match(badge, /\bwhitespace-normal\b/u, `coluna ${column.label}: selo do cabeçalho quebra linha`)
    assert.match(badge, /\bflex-wrap\b/u, `coluna ${column.label}: selo do cabeçalho em mais de uma linha`)
  })
  // [Ajuste A1] Espaçamento menor que o padrão da .data-table, com !px-*
  // (a regra .data-table th/td é mais específica que a utilidade px-*).
  assert.ok(headers.every((header) => header.startsWith('class="!px-2 text-center"')), 'A1: cabeçalhos com !px-2')
  assert.ok(head.includes('<th class="!px-3" scope="col">Equipe</th>'), 'A1: coluna Equipe com !px-3')
  const cells = section.slice(section.indexOf('<tbody')).match(/<td class="[^"]*"/gu) ?? []
  assert.equal(cells.length, 4 * OPERATIONAL_SUMMARY_COLUMNS.length, 'A1: uma célula por coluna nas quatro linhas')
  assert.ok(cells.every((cell) => cell === '<td class="!px-2 text-center"'), 'A1: células com !px-2')
  assert.equal((section.match(/<th class="whitespace-nowrap !px-3 text-left" scope="row">/gu) ?? []).length, 4, 'A1: rótulos das linhas com !px-3')
  assert.deepEqual(OPERATIONAL_SUMMARY_COLUMNS.map((column) => textOf(headers[OPERATIONAL_SUMMARY_COLUMNS.indexOf(column)])), ['Escalados', 'Disponíveis', 'Em pausa', 'Almoço', 'Ausentes', 'Fora do expediente', 'Sem escala'], 'rótulos das colunas')
  assert.ok(section.includes('Ausentes: somente ausências lançadas; pausas não entram.'), 'nota dos ausentes')
  assert.ok(section.includes('Situação às 10:32'), 'hora do servidor (server_now), sem o fuso do navegador')
  assert.doesNotMatch(markup, /inline-alert/u, 'sem aviso com a presença e as pausas disponíveis')
  // Cartões pelo resumo, nunca pela disponibilidade do status.php.
  assert.equal(metricValue(markup, 'CIs disponíveis'), '11', '"CIs disponíveis" = total.disponiveis do resumo (o cálculo antigo daria 9)')
  assert.match(markup, /data-now-available="">11</u, '[R4b] "Disponíveis agora" no topo = total.disponiveis do resumo')
  assert.equal(teamAvailable(markup, 'n1'), '4', 'N1 disponíveis pelo resumo (o cálculo antigo daria 6)')
  assert.equal(teamAvailable(markup, 'n2'), '7', 'N2 disponíveis pelo resumo (o cálculo antigo daria 3)')
  // Os totais que continuam, na lista do pé da tela.
  for (const label of ['Técnicos monitorados', 'Pausas ativas', 'Aprovações pendentes', 'Chamados críticos', 'War rooms', 'Plantonistas ativos', 'Sobreavisos ativos']) {
    assert.notEqual(metricValue(markup, label), undefined, `total "${label}" continua`)
  }
  assert.equal(metricValue(markup, 'Técnicos monitorados'), '30', 'demais cartões do summary como antes')
}
// Presença indisponível (interruptor desligado ou falha): "—" em tudo, com
// aviso, e nunca o cálculo antigo (DV5).
for (const [label, data] of [
  ['disponivel false', dashboardData({ presence_summary: null, presence_meta: { disponivel: false, pausas_disponiveis: true }, summary: { cis_disponiveis: null } })],
  ['disponivel false com resumo velho', dashboardData({ presence_meta: { disponivel: false, pausas_disponiveis: true } })],
  ['sem presence_meta (backend antigo)', dashboardData({ presence_meta: undefined })],
  ['resumo nulo com disponivel true', dashboardData({ presence_summary: null })],
]) {
  const markup = renderDashboardWith(data)
  const section = summarySection(markup)
  for (const team of ['N1', 'N2', 'Total', 'Liderança']) {
    assert.deepEqual(rowValues(section, team), Array(7).fill('—'), `${label}: linha ${team} com "—"`)
  }
  for (const team of ['n1', 'n2', 'total', 'lideranca']) {
    assert.deepEqual(cardValues(section, team), Array(7).fill('—'), `${label}: 17d, cartão ${team} com "—"`)
  }
  assert.ok(markup.includes('inline-alert inline-alert-warning') && markup.includes('Situação da equipe indisponível'), `${label}: aviso da presença indisponível (no topo da tela)`)
  assert.equal(metricValue(markup, 'CIs disponíveis'), '—', `${label}: "CIs disponíveis" com "—" (nunca o cálculo antigo)`)
  assert.ok(markup.includes('Indisponível: presença não calculada'), `${label}: "CIs disponíveis" com o aviso`)
  assert.equal(teamAvailable(markup, 'n1'), '—', `${label}: N1 por equipe com "—"`)
  assert.equal(teamAvailable(markup, 'n2'), '—', `${label}: N2 por equipe com "—"`)
  assert.match(markup, /data-now-available="">—</u, `${label}: "Disponíveis agora" com "—"`)
  assert.doesNotMatch(markup, /data-composition=/u, `${label}: sem a barra de composição (nada inventado)`)
  assert.ok(section.includes('Ausentes: somente ausências lançadas'), `${label}: a nota continua`)
}
// Estado de pausas ilegível: "—" só em Em pausa, com aviso (DV6).
{
  const markup = renderDashboardWith(dashboardData({ presence_meta: { disponivel: true, pausas_disponiveis: false } }))
  const section = summarySection(markup)
  for (const [team, label] of [['n1', 'N1'], ['n2', 'N2'], ['total', 'Total'], ['lideranca', 'Liderança']]) {
    assert.deepEqual(rowValues(section, label), expectedRow(team, ['em_pausa']), `pausas ilegíveis: ${label} com "—" só em Em pausa`)
    assert.deepEqual(cardValues(section, team), expectedRow(team, ['em_pausa']), `pausas ilegíveis: 17d, cartão ${label} com "—" só em Em pausa`)
  }
  assert.ok(markup.includes('Pausas indisponíveis') && !markup.includes('Situação da equipe indisponível'), 'pausas ilegíveis: o aviso das pausas, não o da presença')
  assert.equal(metricValue(markup, 'CIs disponíveis'), '11', 'pausas ilegíveis: "CIs disponíveis" continua pelo resumo')
}
// [Ajuste A3] Ninguém escalado (fim de semana, feriado ou sem regra): texto
// abaixo da tabela, só com a presença disponível e total.escalados = 0.
const NOBODY = 'Ninguém escalado neste momento (fim de semana, feriado ou sem regra de escala).'
{
  const zero = group(0, 0, 0, 0, 0, 0, 0)
  const weekend = { n1: zero, n2: { ...zero, sem_escala: 2 }, lideranca: zero, total: { ...zero, sem_escala: 2 } }
  const section = summarySection(renderDashboardWith(dashboardData({ presence_summary: weekend })))
  assert.ok(section.includes(NOBODY), 'A3: total.escalados = 0 com a presença disponível: aviso de ninguém escalado')
  assert.deepEqual(rowValues(section, 'Total'), ['0', '0', '0', '0', '0', '0', '2'], 'A3: a contagem não muda')
  assert.ok(!summarySection(renderDashboardWith(dashboardData())).includes(NOBODY), 'A3: com gente escalada, sem o aviso')
  for (const [label, data] of [
    ['presença indisponível', dashboardData({ presence_summary: weekend, presence_meta: { disponivel: false, pausas_disponiveis: true } })],
    ['total sem escalados', dashboardData({ presence_summary: { ...weekend, total: { ...weekend.total, escalados: undefined } } })],
    ['total ausente', dashboardData({ presence_summary: { ...weekend, total: undefined } })],
  ]) {
    assert.ok(!summarySection(renderDashboardWith(data)).includes(NOBODY), `A3: ${label}: sem o aviso`)
  }
  const onlyLeadership = { ...weekend, lideranca: group(2, 2, 0, 0, 0, 0, 0) }
  assert.ok(summarySection(renderDashboardWith(dashboardData({ presence_summary: onlyLeadership }))).includes(NOBODY), 'A3: só a Liderança escalada (fora do total) ainda é ninguém escalado no total')
}
// Contador fora do formato (texto, negativo, fracionário, ausente): "—".
{
  const odd = { ...SUMMARY, n1: { ...SUMMARY.n1, escalados: '11', disponiveis: -1, almoco: 1.5, ausentes: undefined } }
  const section = summarySection(renderDashboardWith(dashboardData({ presence_summary: odd })))
  assert.deepEqual(rowValues(section, 'N1'), ['—', '—', '2', '—', '—', '1', '21'], 'contador fora do formato vira "—"')
  assert.deepEqual(cardValues(section, 'n1'), ['—', '—', '2', '—', '—', '1', '21'], '17d: cartão com o mesmo "—" da tabela')
  const noLeadership = summarySection(renderDashboardWith(dashboardData({ presence_summary: { ...SUMMARY, lideranca: undefined } })))
  assert.deepEqual(rowValues(noLeadership, 'Liderança'), Array(7).fill('—'), 'Liderança sem dados: "—"')
}
assert.equal(summaryCount({ a: 0 }, 'a'), 0, 'zero é número, não "—"')
assert.equal(summaryCount(null, 'a'), null)
assert.equal(serverClock('2026-10-27T07:05:00-03:00'), '07:05', 'HH:MM do server_now, como veio')
for (const value of [null, undefined, '', '10:32', '2026-10-27 10:32:00', 1761572000]) {
  assert.equal(serverClock(value), null, `server_now fora do formato: sem hora (${JSON.stringify(value)})`)
}
{
  const section = summarySection(renderDashboardWith(dashboardData({ server_now: null })))
  assert.ok(!section.includes('Situação às'), 'sem server_now: sem a hora no título')
}
// DV14 (pendência 17c): sem server_now válido no status.php, "Atualização
// automática ativa", nunca "Atualizado há 1792178705 segundos".
for (const serverNow of [null, undefined, '', 'x', 0]) {
  const markup = renderDashboardWith(dashboardData(), { connection: 'stale', status: { n1: [], n2: [], server_now: serverNow }, employees: [] })
  assert.ok(markup.includes('Conexão instável'), `DV14 (${JSON.stringify(serverNow)}): conexão instável`)
  assert.ok(markup.includes('Atualização automática ativa'), `DV14 (${JSON.stringify(serverNow)}): "Atualização automática ativa"`)
  assert.doesNotMatch(markup, /Atualizado há/u, `DV14 (${JSON.stringify(serverNow)}): sem contagem de segundos`)
}
{
  const markup = renderDashboardWith(dashboardData(), { connection: 'stale' })
  assert.ok(markup.includes('Atualizado há 10 segundos'), 'com server_now válido, a contagem continua')
}
// O navegador não conta mais disponíveis (5.7).
{
  const source = read('src/pages/DashboardPage.jsx')
  assert.doesNotMatch(source, /\.disponibilidade\b/u, 'Visão Operacional não lê a disponibilidade do cálculo antigo')
  // [R4b] A conta passa por presenceOf (o mesmo summaryCount do resumo, com o "—" do DV5 e do DV6).
  assert.match(source, /const availableCis = count\('total', 'disponiveis'\)/u, '"CIs disponíveis" pelo total do resumo')
  assert.match(source, /return summaryCount\(presence\[team\], key\)/u, 'contadores do topo pelo summaryCount do resumo')
  assert.equal((source.match(/\{cell\(team, column\.key\)\}/gu) ?? []).length, 2, '17d: tabela e cartões pelo mesmo cell()')
}

// ---------------------------------------------------------------------------
// 4. Tela Técnicos: coluna "Situação agora" (DV8)
// ---------------------------------------------------------------------------
const technician = (id, name, presence, extra = {}) => ({ id, name, team: 'N1', shift: '08:00 - 17:00', status: 'Disponível', on_break: false, presence, ...extra })
const technicianItems = [
  technician(1, 'QA Presencial', { situacao: 'presencial', origem: 'regra_escala', detalhe: 'Presencial (sempre presencial)', parcial: 'a partir de 14:00' }),
  technician(2, 'QA Remoto', { situacao: 'remoto', origem: 'regra_escala', detalhe: 'Remoto (dias ímpares)' }),
  technician(3, 'QA Pausa', { situacao: 'pausa', origem: 'pausa', detalhe: 'Café' }),
  technician(4, 'QA Almoco', { situacao: 'almoco', origem: 'expediente', detalhe: 'Almoço 12:00–13:00' }),
  technician(5, 'QA Ausente', { situacao: 'ausente', origem: 'ausencia', detalhe: 'Ausente (dia todo)', categoria: 'Férias' }),
  technician(6, 'QA Fora', { situacao: 'fora_expediente', origem: 'expediente', detalhe: 'Expediente 08:00–17:00' }),
  technician(7, 'QA Sem escala', { situacao: 'sem_escala', origem: 'regra_escala', detalhe: 'Sem escala definida' }),
  technician(8, 'QA Oito', null),
]
const renderTechnicians = (data) => {
  globalThis.qaResourceData = data
  try {
    return renderToStaticMarkup(h(TechniciansPage))
  } finally {
    delete globalThis.qaResourceData
  }
}
const technicianRows = (markup) => markup.slice(markup.indexOf('<tbody>'), markup.indexOf('</tbody>')).split('<tr').slice(1)
{
  const markup = renderTechnicians({ sucesso: true, server_now: '2026-10-27T12:15:09-03:00', presence_meta: { disponivel: true, pausas_disponiveis: true }, items: technicianItems })
  const head = markup.slice(markup.indexOf('<thead>'), markup.indexOf('</thead>'))
  assert.deepEqual([...head.matchAll(/<th>([^<]*)/gu)].map((match) => match[1]), ['Nome', 'Equipe', 'Turno', 'Situação agora'], 'colunas da tela Técnicos: "Situação agora" no lugar de "Status"')
  assert.ok(head.includes('às 12:15'), '"às HH:MM" do server_now')
  const rows = technicianRows(markup)
  assert.equal(rows.length, technicianItems.length, 'uma linha por técnico')
  NOW_PRESENCE_LEGEND.forEach((item, index) => {
    const row = rows[index]
    assert.ok(row.includes(`status-badge gap-1 ${item.tone}`), `${item.label}: cor da legenda`)
    assert.match(row, new RegExp(`<svg[^>]*aria-hidden="true"[\\s\\S]*</svg>${item.label}</span>`, 'u'), `${item.label}: ícone e rótulo`)
    assert.ok(row.includes(`<small>${technicianItems[index].presence.detalhe}</small>`), `${item.label}: detalhe do motor`)
    assert.doesNotMatch(row, />Disponível</u, `${item.label}: o status antigo não aparece`)
  })
  assert.match(rows[0], /aria-label="Ausência parcial a partir de 14:00"/u, 'marcador da parcial com o horário')
  assert.ok(rows[4].includes('Categoria: Férias'), 'categoria quando o servidor manda (P9)')
  assert.ok(!rows[1].includes('Categoria') && !rows[1].includes('Ausência parcial'), 'sem categoria nem parcial quando o servidor não manda')
  assert.ok(rows[7].includes('Fora do Mapa') && !rows[7].includes('status-badge'), 'cadastro fora do Mapa (presence nula, presença disponível)')
  assert.doesNotMatch(markup, /inline-alert/u, 'sem aviso com a presença disponível')
  assert.ok(markup.includes('Legenda da situação agora'), 'legenda da situação de agora na tela')
  for (const item of NOW_PRESENCE_LEGEND) assert.ok(markup.split('Legenda da situação agora')[1].includes(`${item.tone}`), `legenda com ${item.label}`)
}
for (const [label, meta] of [['disponivel false', { disponivel: false, pausas_disponiveis: true }], ['sem presence_meta', undefined]]) {
  const markup = renderTechnicians({ sucesso: true, server_now: '2026-10-27T12:15:09-03:00', presence_meta: meta, items: technicianItems.map((item) => ({ ...item, presence: null })) })
  const rows = technicianRows(markup)
  assert.ok(rows.every((row) => row.includes('title="Situação indisponível">—</span>')), `${label}: "—" em todas as linhas`)
  assert.ok(!markup.includes('Fora do Mapa'), `${label}: sem presença não é "fora do Mapa" (I5)`)
  assert.ok(markup.includes('Situação agora indisponível'), `${label}: aviso`)
}
{
  const markup = renderTechnicians({ sucesso: true, server_now: '2026-10-27T12:15:09-03:00', presence_meta: { disponivel: true, pausas_disponiveis: false }, items: technicianItems.slice(0, 2) })
  assert.ok(markup.includes('Pausas indisponíveis') && !markup.includes('Situação agora indisponível'), 'pausas ilegíveis: aviso das pausas')
  assert.ok(technicianRows(markup)[0].includes('presence-onsite'), 'pausas ilegíveis: situação pela escala')
}
{
  const markup = renderTechnicians({ sucesso: true, presence_meta: { disponivel: true, pausas_disponiveis: true }, items: [technician(9, 'QA Desconhecida', { situacao: 'outra', detalhe: 'x' })] })
  assert.ok(technicianRows(markup)[0].includes('title="Situação indisponível">—</span>'), 'situação fora da legenda: "—"')
  assert.ok(!markup.includes('às '), 'sem server_now: sem a hora no cabeçalho')
}
// [Ajuste A2] Busca sem resultado × lista vazia de verdade. O campo de busca
// é estado do componente: o teste o preenche pelo useState real.
{
  const technicianMarkup = (items, term) => {
    globalThis.qaResourceData = { sucesso: true, server_now: '2026-10-27T12:15:09-03:00', presence_meta: { disponivel: true, pausas_disponiveis: true }, items }
    globalThis.qaInitialSearch = term
    try {
      return renderToStaticMarkup(h(TechniciansPage))
    } finally {
      delete globalThis.qaResourceData
      delete globalThis.qaInitialSearch
    }
  }
  const empty = technicianMarkup([], '')
  assert.ok(empty.includes('Nenhum técnico cadastrado') && !empty.includes('Nenhum técnico encontrado'), 'A2: lista vazia de verdade: "Nenhum técnico cadastrado"')
  const notFound = technicianMarkup(technicianItems, 'zzz-sem-resultado')
  assert.ok(notFound.includes('Nenhum técnico encontrado') && notFound.includes('Nenhum técnico corresponde à busca.'), 'A2: busca sem resultado: "Nenhum técnico encontrado"')
  assert.ok(!notFound.includes('Nenhum técnico cadastrado') && !notFound.includes('<table'), 'A2: busca sem resultado sem a mensagem de lista vazia nem a tabela')
  const blank = technicianMarkup([], '   ')
  assert.ok(blank.includes('Nenhum técnico cadastrado') && !blank.includes('Nenhum técnico encontrado'), 'A2: busca só com espaços conta como sem termo (só a mensagem de lista vazia)')
  const found = technicianMarkup(technicianItems, 'do expediente')
  assert.equal(technicianRows(found).length, 1, 'A2: busca pelo rótulo da situação ("do expediente" só está no selo)')
  assert.ok(technicianRows(found)[0].includes('QA Fora'), 'A2: o técnico achado é o fora do expediente')
  assert.ok(!found.includes('Nenhum técnico'), 'A2: com resultado, sem mensagem de vazio')
}
{
  const app = read('src/App.jsx')
  assert.match(app, /const TechniciansPage = lazyPage\(\(\) => import\('\.\/pages\/TechniciansPage'\), 'TechniciansPage'\)/u, 'tela Técnicos própria, com lazy loading')
  assert.match(app, /router\.path === '\/tecnicos'\) \{\n {4}content = <TechniciansPage \/>/u, 'rota /tecnicos na tela nova')
  assert.doesNotMatch(read('src/pages/ModulePage.jsx'), /tecnicos|technicians\.php/u, 'ModulePage sem a entrada antiga da tela Técnicos')
  assert.doesNotMatch(read('src/pages/TechniciansPage.jsx'), /intervalMs|\.status\b|role/u, 'tela Técnicos sem polling (P14), sem o status antigo e sem o perfil de acesso (DV9)')
}

// ---------------------------------------------------------------------------
// 5. Pausas: cartões pela presenca do status.php (DV12)
// ---------------------------------------------------------------------------
const legacyOk = { status: 'disponivel', label: 'Disponível' }
const worker = (id, presenca, extra = {}) => ({ id, nome: `QA ${id}`, equipe: 'n1', em_pausa: false, status_aprovacao: null, jornada_entrada: '08:00', jornada_saida: '17:00', disponibilidade: legacyOk, presenca, ...extra })
function presencaOf(situacao, detalhe) {
  return { situacao, origem: 'x', detalhe }
}
{
  const employees = [
    worker(11, presencaOf('presencial', 'Presencial (sempre presencial)')),
    worker(12, presencaOf('remoto', 'Remoto (sempre remoto)')),
    worker(13, presencaOf('pausa', 'Café'), { em_pausa: true, motivo_pausa: 'Café' }),
    worker(14, presencaOf('almoco', 'Almoço 12:00–13:00')),
    worker(15, presencaOf('ausente', 'Ausente (dia todo)')),
    worker(16, presencaOf('fora_expediente', 'Expediente 08:00–17:00')),
    worker(17, presencaOf('sem_escala', 'Sem escala definida')),
    worker(18, presencaOf('ausente', 'Ausente (dia todo)'), { status_aprovacao: 'pendente' }),
    worker(19, null),
    worker(20, undefined, { disponibilidade: { status: 'indisponivel', label: 'Fora do horário' } }),
    worker(21, presencaOf('ausente', 'Ausente 10:00–11:00'), { em_pausa: true, motivo_pausa: 'Café' }),
  ]
  // [R4a] Linhas da lista no lugar dos cartões: o estado em data-state.
  const markup = renderPausas(employees, 'gestor', 0)
  const list = teamRows(markup)
  assert.equal(list.length, employees.length, 'uma linha por técnico')
  const expected = [
    ['available', 'presence-onsite', 'Presencial'],
    ['available', 'presence-remote', 'Remoto'],
    ['paused', 'presence-pause', 'Em pausa'],
    ['offline', 'presence-lunch', 'Almoço'],
    ['offline', 'presence-absence', 'Ausente'],
    ['offline', 'presence-off-hours', 'Fora do expediente'],
    ['offline', 'presence-no-schedule', 'Sem escala'],
  ]
  expected.forEach(([state, tone, label], index) => {
    const row = list[index]
    assert.equal(row.state, state, `${label}: linha ${state} pela presenca`)
    assert.ok(row.html.includes(tone), `${label}: cor da legenda`)
    assert.match(row.html, new RegExp(`<svg[^>]*aria-hidden="true"[\\s\\S]*?</svg>${label}</span>`, 'u'), `${label}: ícone e rótulo`)
    assert.ok(row.html.includes(`title="${employees[index].presenca.detalhe}"`), `${label}: detalhe no title`)
    if (label !== 'Presencial' && label !== 'Remoto') assert.ok(!row.html.includes('>Disponível<'), `${label}: nunca "Disponível" do cálculo antigo`)
  })
  assert.ok(list[7].state === 'pending' && list[7].html.includes('Aguardando aprovação'), 'aprovação pendente continua primeiro')
  assert.ok(list[8].state === 'available' && list[8].html.includes('status-success') && list[8].html.includes('>Disponível</span>'), 'presenca nula (fora do Mapa ou presença desligada): cálculo antigo')
  assert.ok(list[9].state === 'offline' && list[9].html.includes('Fora do horário'), 'sem presenca: cálculo antigo (indisponível)')
  assert.ok(list[10].state === 'offline' && list[10].html.includes('presence-absence') && list[10].html.includes('data-pause-clock'), 'em pausa durante ausência: situação do motor, com o timer da pausa')
}
{
  // Interruptor desligado: o status.php não manda presenca; tudo como antes.
  const employees = [
    worker(31, undefined, { em_pausa: true, motivo_pausa: 'Café' }),
    worker(32, undefined),
    worker(33, undefined, { disponibilidade: { status: 'indisponivel', label: 'Almoço' } }),
  ]
  const list = teamRows(renderPausas(employees, 'gestor', 0))
  assert.ok(list[0].state === 'paused' && list[0].html.includes('status-warning') && list[0].html.includes('Em pausa'), 'sem presenca: em pausa como antes')
  assert.ok(list[1].state === 'available' && list[1].html.includes('status-success'), 'sem presenca: disponível como antes')
  assert.ok(list[2].state === 'offline' && list[2].html.includes('status-neutral') && list[2].html.includes('Almoço'), 'sem presenca: indisponível como antes')
  assert.doesNotMatch(list.map((row) => row.html).join(''), /presence-/u, 'sem presenca: nenhuma cor da legenda nova')
}

// ---------------------------------------------------------------------------
// 6. [R4a] Pausas: escolha visível, lista densa com filtro e o estouro
// ---------------------------------------------------------------------------
const renderPausasWith = (employees, { role = 'tecnico', id = 1, permissions = [], reason, filter, elapsed = () => 90, ci = true } = {}) => {
  globalThis.qaInitialReason = reason
  globalThis.qaInitialFilter = filter
  try {
    return renderToStaticMarkup(h(PausasPage, {
      session: { role, permissions, ci: { autenticado: ci, funcionario_id: id, nome: 'QA', username: 'qa' } },
      status: { n1: employees.filter((item) => item.equipe === 'n1'), n2: employees.filter((item) => item.equipe === 'n2') },
      livePauses: { employees, status: {}, connection: 'online', serverNow: Date.now(), elapsedFor: elapsed },
      loading: false, onStart: noop, onRequest: noop, onFinish: noop, onForceEndBreak: noop,
    }))
  } finally {
    delete globalThis.qaInitialReason
    delete globalThis.qaInitialFilter
  }
}
const free = (id, extra = {}) => worker(id, presencaOf('presencial', 'Presencial'), { duracao_limite_segundos: 900, ...extra })
const FILTER_LABEL = { available: 'Disponíveis', paused: 'Em pausa', outros: 'Outros' }
const textOfHtml = (html) => html.replace(/<!-- -->/gu, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()
{
  // Escolha visível (D-R0-5): os três motivos à vista, o botão diz a ação.
  const start = ownPause(renderPausasWith([free(1)]))
  const radios = [...start.matchAll(/<input [^>]*name="motivo_pausa"[^>]*value="([^"]+)"/gu)].map((match) => match[1])
  assert.deepEqual(radios, ['Café', 'Pessoal', 'Reunião'], 'os três motivos visíveis, como escolha (rádio)')
  assert.match(start, /<legend[^>]*>Motivo<\/legend>/u, 'grupo com nome "Motivo"')
  assert.match(start, /<button class="btn-primary w-full" disabled="" type="submit">Escolha o motivo<\/button>/u, 'sem escolha: botão desabilitado, "Escolha o motivo"')
  assert.ok(start.includes('até 15 min'), 'limite do café pela configuração do servidor (duracao_limite_segundos), não fixo')
  assert.ok(start.includes('sem limite de tempo') && start.includes('com aprovação'), 'Pessoal sem limite; Reunião com aprovação')
  assert.ok(start.includes('Café usa limite próprio; reunião segue o fluxo de aprovação configurado.'), 'a regra das pausas, com as mesmas palavras (D-R0-8)')
  assert.ok(!start.includes('<select'), 'sem o seletor (antes: abrir, escolher, confirmar)')
  for (const [reason, action] of [['Café', 'Iniciar pausa de café'], ['Pessoal', 'Iniciar pausa pessoal'], ['Reunião', 'Pedir aprovação da reunião']]) {
    const chosen = ownPause(renderPausasWith([free(1)], { reason }))
    assert.match(chosen, new RegExp(`<button class="btn-primary w-full" type="submit">${action}</button>`, 'u'), `${reason}: o botão diz a ação ("${action}")`)
    assert.match(chosen, new RegExp(`<input name="motivo_pausa" required="" type="radio" checked="" value="${reason}"/>`, 'u'), `${reason}: marcado`)
    assert.equal(chosen.includes('Contexto da reunião'), reason === 'Reunião', `${reason}: o contexto só na reunião`)
  }
  assert.match(ownPause(renderPausasWith([free(1)], { reason: 'Reunião' })), /<textarea [^>]*maxLength="500"[^>]*required=""/u, 'reunião: contexto obrigatório, até 500 caracteres')
  assert.ok(ownPause(renderPausasWith([free(1, { duracao_limite_segundos: undefined })])).includes('até 20 min'), 'sem o campo: os 20 min de antes')
  const pending = ownPause(renderPausasWith([free(1, { status_aprovacao: 'pendente' })]))
  assert.ok(pending.includes('Sua solicitação de reunião está aguardando aprovação.'), 'pedido de reunião pendente: o aviso de sempre')
  const admin = renderPausasWith([free(1)], { role: 'admin', id: 0, ci: false })
  assert.ok(admin.includes('A sessão administrativa permite monitoramento. Entre como CI para iniciar ou finalizar sua própria pausa.') && !admin.includes('sua-pausa'), 'sessão administrativa: o aviso de sempre, sem o bloco "Sua pausa"')
  assert.ok(!renderPausasWith([free(1)]).includes('Sessão do colaborador'), 'sai o cartão "Sessão do colaborador" (nome e login ficam no rodapé do menu)')
}
{
  // Pausa em andamento: relógio grande, o que falta, o estouro escrito.
  const coffee = (extra = {}) => free(1, { em_pausa: true, motivo_pausa: 'Café', inicio_pausa: '2026-10-28T10:31:00-03:00', presenca: presencaOf('pausa', 'Café'), ...extra })
  const within = ownPause(renderPausasWith([coffee()], { elapsed: () => 305 }))
  assert.match(within, /data-pause-clock="">5:05</u, 'relógio grande com o tempo da pausa')
  assert.ok(textOfHtml(within).includes('faltam 9:55 · limite de 15 min') && within.includes('Dentro do tempo'), 'o que falta e o limite do servidor')
  assert.ok(within.includes('aria-label="Progresso da pausa: 34%"'), 'faixa do tempo usado')
  assert.match(within, /<button class="btn-primary w-full" type="button">Finalizar pausa<\/button>/u, 'encerrar a própria pausa: a ação principal')
  const late = ownPause(renderPausasWith([coffee()], { elapsed: () => 1000 }))
  assert.ok(textOfHtml(late).includes('passou 1:40 · limite de 15 min') && late.includes('Tempo excedido'), 'estouro escrito ("passou"), não só a cor')
  assert.match(late, /text-danger[^"]*" data-pause-clock="">16:40</u, 'estouro: relógio em vermelho')
  const personal = ownPause(renderPausasWith([coffee({ motivo_pausa: 'Pessoal' })], { elapsed: () => 5000 }))
  assert.ok(personal.includes('Timer ativo sem limite automático') && personal.includes('Sem limite') && !personal.includes('passou'), 'pessoal: sem limite, nunca "passou"')
}
{
  // Lista densa: filtro com contagem, grupos por equipe, estouro na linha.
  const team = [
    free(1, { nome: 'QA Um' }),
    free(2, { em_pausa: true, motivo_pausa: 'Café', presenca: presencaOf('pausa', 'Café') }),
    free(3, { em_pausa: true, motivo_pausa: 'Reunião', presenca: presencaOf('pausa', 'Reunião') }),
    free(4, { presenca: presencaOf('almoco', 'Almoço') }),
    free(5, { equipe: 'n2', presenca: presencaOf('remoto', 'Remoto') }),
    free(6, { equipe: 'n2', status_aprovacao: 'pendente' }),
  ]
  const elapsed = (item) => (item.id === 2 ? 1000 : 120)
  const all = renderPausasWith(team, { role: 'lideranca', id: 0, ci: false, permissions: ['pausas.force_end'], elapsed })
  const filters = [...all.matchAll(/<button aria-pressed="(true|false)" class="segmented-option" type="button"><span>([^<]+)<\/span><span class="segmented-count">(\d+)<\/span><\/button>/gu)].map((match) => [match[2], match[3], match[1]])
  assert.deepEqual(filters, [['Todos', '6', 'true'], ['Disponíveis', '2', 'false'], ['Em pausa', '2', 'false'], ['Outros', '2', 'false']], 'filtro com a contagem de cada grupo, "Todos" escolhido')
  assert.match(all, /<div aria-label="Filtrar a equipe" class="segmented" role="group">/u, 'filtro com nome')
  assert.ok(textOfHtml(all).includes('Equipe N1 · 1 de 4 disponíveis') && textOfHtml(all).includes('Equipe N2 · 1 de 2 disponíveis'), 'linha de grupo por equipe, com os disponíveis')
  const rows = teamRows(all)
  assert.deepEqual(rows.map((row) => row.overdue), [false, true, false, false, false, false], 'só o café de 16:40 com limite de 15 min marcado como estouro')
  assert.ok(textOfHtml(rows[1].html).includes('Café 16:40 passou 1:40') && /text-danger[^"]*" data-pause-clock="">16:40</u.test(rows[1].html), 'estouro: tempo em vermelho e "passou" escrito na linha')
  assert.ok(textOfHtml(rows[2].html).includes('Reunião 2:00 sem limite'), 'reunião: sem limite')
  assert.equal((all.match(/>Derrubar pausa<\/button>/gu) ?? []).length, 2, 'Derrubar pausa só nas linhas em pausa (Liderança)')
  assert.ok(rows.filter((row) => row.state !== 'paused').every((row) => !row.html.includes('Derrubar pausa')), 'sem Derrubar pausa fora da pausa')
  assert.equal((renderPausasWith(team, { role: 'gestor', id: 0, ci: false, elapsed }).match(/Derrubar pausa/gu) ?? []).length, 0, 'sem pausas.force_end: sem Derrubar pausa nem a coluna')
  for (const [filter, states] of [['available', ['available', 'available']], ['paused', ['paused', 'paused']], ['outros', ['offline', 'pending']]]) {
    const filtered = renderPausasWith(team, { role: 'gestor', id: 0, ci: false, filter, elapsed })
    assert.deepEqual(teamRows(filtered).map((row) => row.state), states, `filtro ${filter}: só as linhas do grupo`)
    assert.match(filtered, new RegExp(`aria-pressed="true" class="segmented-option" type="button"><span>${FILTER_LABEL[filter]}<`, 'u'), `filtro ${filter} marcado`)
  }
  const empty = renderPausasWith([free(1)], { role: 'gestor', id: 0, ci: false, filter: 'paused' })
  assert.ok(empty.includes('Ninguém nesta situação agora') && !empty.includes('<table'), 'filtro sem ninguém: vazio com o próximo passo, sem a tabela')
  assert.ok(all.includes('<caption class="sr-only">Equipe agora</caption>') && all.includes('scope="colgroup"'), 'tabela com nome e linhas de grupo com escopo')
}

// ---------------------------------------------------------------------------
// 7. [R4b] Visão operacional: disponíveis e composição no topo, "Precisa de
// você", pausas em andamento com o estouro, totais e o modo painel
// ---------------------------------------------------------------------------
const renderDashboardR4b = (data, { permissions = [], role = 'gestor', employees = [], elapsed = () => 0, panel = false } = {}) => {
  globalThis.qaResourceData = data
  try {
    return renderToStaticMarkup(h(DashboardPage, {
      navigate: noop, loading: false, onForceEndBreak: noop, panel,
      panelHeader: panel ? h('header', { 'data-qa-panel-header': '' }, 'Topo QA') : null,
      session: { role, permissions, ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' } },
      livePauses: { employees, status: { n1: employees, n2: [], server_now: '2026-10-27T10:32:40-03:00' }, connection: 'online', serverNow: Date.parse('2026-10-27T10:32:50-03:00'), elapsedFor: elapsed },
    }))
  } finally {
    delete globalThis.qaResourceData
  }
}
const cellsOf = (markup, team) => {
  const bar = markup.match(new RegExp(`<div aria-label="([^"]*)" class="[^"]*" data-composition="${team}" role="img">([\\s\\S]*?)</div>`, 'u'))
  if (!bar) return null
  const counts = {}
  for (const [, key] of bar[2].matchAll(/data-cell="([a-z_]+)"/gu)) counts[key] = (counts[key] ?? 0) + 1
  return { label: bar[1], counts }
}
const MANAGER_PERMISSIONS = ['operacao.approve', 'pausas.force_end']
const livePause = (id, motivo, extra = {}) => ({ id, nome: `QA Pausa ${id}`, equipe: 'n1', em_pausa: true, motivo_pausa: motivo, inicio_pausa: '2026-10-27T10:00:00-03:00', status_aprovacao: 'aprovado', ...extra })
{
  const markup = renderDashboardR4b(dashboardData({ summary: { tecnicos_monitorados: 30, solicitacoes_pendentes: 3, chamados_criticos_abertos: 1, war_rooms_ativas: 1 } }), { permissions: MANAGER_PERMISSIONS })
  // Saem o cartão de abertura e os 8 cartões iguais.
  for (const gone of ['Operação agora', 'Portal SDK — Centro Operacional de TI', 'metric-card', 'Disponíveis por equipe', 'Pausas por tipo', 'Monitoramento em tempo real']) {
    assert.ok(!markup.includes(gone), `sai "${gone}"`)
  }
  // A primeira tela: disponíveis e composição, depois pausas e "Precisa de você"; o detalhe e os totais embaixo.
  const order = ['Disponíveis agora', 'Pausas em andamento', 'Precisa de você', 'Situação da equipe agora', 'Totais da operação'].map((text) => markup.indexOf(text))
  assert.ok(order.every((index, position) => index > 0 && (position === 0 || index > order[position - 1])), `ordem da tela: disponíveis, pausas, "Precisa de você", detalhe e totais (${order.join(', ')})`)
  // Composição: uma célula por pessoa escalada, na ordem e na cor da legenda.
  const total = cellsOf(markup, 'total')
  assert.deepEqual(total?.counts, { disponiveis: 11, em_pausa: 3, almoco: 3, ausentes: 3, fora_expediente: 3 }, 'composição do total: uma célula por escalado (23)')
  assert.equal(total.label, 'Equipe N1 + N2: 11 disponíveis, 3 em pausa, 3 almoço, 3 ausentes, 3 fora do expediente', 'composição com a frase para o leitor de tela')
  assert.ok(textOfHtml(markup).includes('11 de 23 escalados'), 'disponíveis de quantos escalados')
  assert.ok(textOfHtml(markup).includes('Sem escala, fora da soma: 43'), 'sem escala fora da soma, com o número')
  assert.deepEqual(cellsOf(markup, 'n1')?.counts, { disponiveis: 4, em_pausa: 2, almoco: 1, ausentes: 3, fora_expediente: 1 }, 'composição do N1')
  assert.ok(textOfHtml(markup).includes('4 de 11 disponíveis') && textOfHtml(markup).includes('7 de 12 disponíveis'), 'N1 e N2: disponíveis de quantos escalados')
  // "Precisa de você": aprovações e chamado crítico, com o caminho.
  const needs = markup.slice(markup.indexOf('data-needs-you'), markup.indexOf('</section>', markup.indexOf('data-needs-you')))
  assert.match(needs, /data-need="approvals"><button [^>]*type="button">[\s\S]*3 aprovações pendentes[\s\S]*Pausas, ponto e horas extras/u, 'aprovações pendentes, com o botão para a tela')
  assert.match(needs, /data-need="critical"><button [^>]*type="button">[\s\S]*1 chamado crítico aberto[\s\S]*1 war room ativa/u, 'chamado crítico aberto e war room')
  assert.equal(metricValue(markup, 'Aprovações pendentes'), '3', 'o total de aprovações continua na lista de totais')
  assert.ok(markup.includes('data-panel-open=""') && markup.includes('Modo painel'), 'botão "Modo painel" na tela normal')
}
{
  // Sem nada pendente: o vazio diz isso. Sem operacao.approve, sem o atalho de aprovações.
  const calm = renderDashboardR4b(dashboardData({ summary: { solicitacoes_pendentes: 0, chamados_criticos_abertos: 0 } }))
  assert.ok(calm.includes('Nada precisa de você agora.'), '"Precisa de você" vazio, com o texto')
  const noApprove = renderDashboardR4b(dashboardData({ summary: { solicitacoes_pendentes: 2 } }), { role: 'tecnico' })
  assert.ok(!noApprove.includes('data-need="approvals"'), 'sem operacao.approve: sem o atalho para Aprovações (a rota pede a permissão)')
}
{
  // Pausas em andamento: estouro marcado e escrito; "Precisa de você" com a pausa estourada.
  const employees = [livePause(1, 'Café', { duracao_limite_segundos: 1200 }), livePause(2, 'Reunião', { equipe: 'n2', observacao_reuniao: null })]
  const markup = renderDashboardR4b(dashboardData(), { permissions: MANAGER_PERMISSIONS, employees, elapsed: (item) => (item.id === 1 ? 1500 : 600) })
  const rows = teamRows(markup)
  assert.deepEqual(rows.map((row) => row.overdue), [true, false], 'café de 25 min com limite de 20: linha marcada; reunião nunca')
  assert.ok(textOfHtml(rows[0].html).includes('25:00 passou 5:00'), 'estouro escrito na linha ("passou")')
  assert.ok(textOfHtml(rows[1].html).includes('10:00 sem limite'), 'reunião sem limite')
  assert.ok(textOfHtml(markup).includes('2 em andamento · Café 1 · Reunião 1'), 'pausas por tipo no cabeçalho da lista')
  assert.match(markup, /data-need="overdue"><button [^>]*type="button">[\s\S]*Café passou do limite[\s\S]*QA Pausa 1, limite de 20 min · passou 5:00/u, '"Precisa de você": a pausa estourada, com quem e quanto')
  assert.equal((markup.match(/>Derrubar pausa<\/button>/gu) ?? []).length, 2, 'Derrubar pausa em cada pausa (com pausas.force_end)')
  assert.equal((renderDashboardR4b(dashboardData(), { employees, elapsed: () => 0 }).match(/Derrubar pausa/gu) ?? []).length, 0, 'sem pausas.force_end: sem Derrubar pausa')
  // Pausas ilegíveis: sem célula de pausa e "—" na legenda.
  const unreadable = renderDashboardR4b(dashboardData({ presence_meta: { disponivel: true, pausas_disponiveis: false } }))
  assert.equal(cellsOf(unreadable, 'total')?.counts.em_pausa, undefined, 'pausas ilegíveis: sem célula de pausa na composição')
  assert.ok(textOfHtml(unreadable).includes('Em pausa —'), 'pausas ilegíveis: "Em pausa" com "—" na legenda')
}
{
  // Modo painel: sem ações, sem o detalhe e os totais, números de destaque maiores.
  const employees = [livePause(1, 'Café', { duracao_limite_segundos: 1200 })]
  const panel = renderDashboardR4b(dashboardData({ summary: { solicitacoes_pendentes: 3, chamados_criticos_abertos: 1 } }), { permissions: MANAGER_PERMISSIONS, employees, elapsed: () => 1500, panel: true })
  assert.ok(panel.includes('data-dashboard="painel"') && panel.includes('data-qa-panel-header'), 'painel com o topo próprio')
  for (const gone of ['Derrubar pausa', 'Abrir pausas', 'Modo painel', 'Situação da equipe agora', 'Totais da operação']) assert.ok(!panel.includes(gone), `painel sem "${gone}"`)
  assert.doesNotMatch(panel.slice(panel.indexOf('data-needs-you')), /<button/u, 'painel: "Precisa de você" só como lista, sem botões')
  assert.ok(panel.includes('Café passou do limite') && panel.includes('3 aprovações pendentes'), 'painel: o que precisa de atenção continua visível')
  assert.match(panel, /class="font-mono font-medium leading-none tabular-nums text-ink text-\[7rem\]" data-now-available="">11</u, 'painel: disponíveis em 7rem')
  assert.match(panel, /text-\[4rem\]" data-available="4"/u, 'painel: equipes em 4rem')
  // DV11 no painel: a tela fixa fica à vista de todos; a observação de reunião (só da gestão) não vai para ela.
  const meeting = [livePause(5, 'Reunião', { observacao_reuniao: 'Pauta QA da gestão' })]
  assert.ok(renderDashboardR4b(dashboardData(), { permissions: MANAGER_PERMISSIONS, employees: meeting }).includes('Pauta QA da gestão'), 'tela normal, gestão: a observação de reunião aparece (DV11)')
  const panelMeeting = renderDashboardR4b(dashboardData(), { permissions: MANAGER_PERMISSIONS, employees: meeting, panel: true })
  assert.ok(!panelMeeting.includes('Pauta QA da gestão') && !panelMeeting.includes(NO_NOTE) && panelMeeting.includes('QA Pausa 5'), 'painel: a linha da reunião sem a observação (nem "Sem observação informada")')
  const unavailable = renderDashboardR4b(dashboardData({ presence_meta: { disponivel: false } }), { panel: true })
  assert.ok(unavailable.includes('Situação da equipe indisponível') && unavailable.includes('data-now-available="">—<'), 'painel: o "—" e o aviso de presença indisponível')
  const dashboardSource = read('src/pages/DashboardPage.jsx')
  assert.match(dashboardSource, /document\.documentElement\.dataset\.panelMode = ''[\s\S]*delete document\.documentElement\.dataset\.panelMode/u, 'painel: a fonte da raiz cresce enquanto ele está aberto, e volta ao sair')
  assert.match(read('src/index.css'), /:root\[data-panel-mode\] \{\s*font-size: 112\.5%;[\s\S]*@media \(min-width: 1600px\) \{\s*:root\[data-panel-mode\] \{\s*font-size: 137\.5%;/u, 'painel: fonte maior, e mais ainda na tela grande')
  assert.match(dashboardSource, /onClick=\{\(\) => navigate\('\/dashboard\?painel=1'\)\}/u, '"Modo painel" pelo navigate (endereço que a tela fixa guarda)')
  const app = read('src/App.jsx')
  assert.match(app, /const panelMode = authenticated && allowed && router\.path === '\/dashboard' && new URLSearchParams\(router\.search\)\.get\('painel'\) === '1'/u, 'painel só com sessão e a mesma permissão da tela')
  const branch = app.slice(app.indexOf('} else if (panelMode) {'), app.indexOf('} else {', app.indexOf('} else if (panelMode) {')))
  assert.ok(branch.includes('<DashboardPage') && branch.includes(' panel ') && !branch.includes('PortalLayout'), 'painel sem a casca (menu, topo e atalhos)')
  assert.match(app, /<h1 className="[^"]*" data-page-title="" tabIndex=\{-1\}>Visão operacional<\/h1>/u, 'painel com o título focável')
  assert.match(app, /<ThemeToggle \/>\s*<button className="btn-secondary" data-panel-exit="" onClick=\{onExit\} type="button">Sair do modo painel<\/button>/u, 'painel com o tema e a saída')
}

console.log('QA operational view OK')
