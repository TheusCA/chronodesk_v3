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
// e cartões de Pausas pela presenca (DV12). Os componentes são renderizados
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
      const source = readFileSync(fileURLToPath(url), 'utf8')
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
// Um pedaço do HTML por cartão de técnico, na ordem dos itens.
const cards = (markup) => markup.split('<article class="operator-card').slice(1)
const NO_NOTE = 'Sem observação informada'

// ---------------------------------------------------------------------------
// 1. Visão Operacional: "Pausas em andamento"
// ---------------------------------------------------------------------------
const renderDashboard = (employees, role, id) => renderToStaticMarkup(h(DashboardPage, {
  navigate: noop, session: session(role, id), livePauses: livePauses(employees), loading: false, onForceEndBreak: noop,
}))
{
  const [own, colleague, coffee] = cards(renderDashboard(technicianView, 'tecnico', 1))
  assert.match(own, /QA pauta própria/u, 'DV11: a própria observação de reunião aparece')
  assert.doesNotMatch(colleague, /line-clamp-2|Sem observação informada/u, 'DV11: sem a chave, a caixa do colega não aparece (nem "Sem observação informada")')
  assert.match(colleague, /QA 2[\s\S]*Reunião/u, 'DV11: o cartão do colega continua, com o motivo da lista fechada')
  assert.doesNotMatch(coffee, /line-clamp-2/u, 'café: nunca a caixa, com ou sem a chave')
}
{
  const [own, colleague] = cards(renderDashboard(managerView, 'gestor', 0))
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
  const markup = renderPausas(technicianView, 'tecnico', 1)
  const [, colleague] = cards(markup)
  assert.match(markup.split('<article class="operator-card')[0], /Pausa ativa[\s\S]*QA pauta própria/u, 'Pausas: a própria pausa de reunião mostra a observação')
  assert.doesNotMatch(colleague, /line-clamp-2|Sem observação informada/u, 'Pausas: cartão do colega sem a chave fica sem a caixa (sem mudança de código)')
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
const columnOrder = ['escalados', 'disponiveis', 'em_pausa', 'almoco', 'ausentes', 'fora_expediente', 'sem_escala']
const expectedRow = (team, dash = []) => columnOrder.map((key) => (dash.includes(key) ? '—' : String(SUMMARY[team][key])))
const metricValue = (markup, label) => markup.match(new RegExp(`<p class="metric-label">${label}</p><p class="metric-value">([^<]*)</p>`, 'u'))?.[1]
{
  const markup = renderDashboardWith(dashboardData())
  const section = summarySection(markup)
  assert.deepEqual(rowValues(section, 'N1'), expectedRow('n1'), 'linha N1 pelo presence_summary, na ordem das colunas')
  assert.deepEqual(rowValues(section, 'N2'), expectedRow('n2'), 'linha N2 pelo presence_summary')
  assert.deepEqual(rowValues(section, 'Total (N1 + N2)'), expectedRow('total'), 'linha Total = total do servidor (N1 + N2)')
  assert.deepEqual(rowValues(section, 'Liderança'), expectedRow('lideranca'), 'Liderança separada, com o rótulo "fora dos totais"')
  assert.ok(section.includes('scope="row">Liderança<small class="font-normal normal-case tracking-normal">fora dos totais</small></th>'), 'Liderança com a nota "fora dos totais"')
  const bodies = section.split('<tbody')
  assert.equal(bodies.length, 3, 'Liderança num bloco à parte da tabela (N1, N2 e Total num, Liderança no outro)')
  assert.match(bodies[1], /N1[\s\S]*N2[\s\S]*Total \(N1 \+ N2\)/u, 'N1, N2 e Total juntos')
  assert.ok(!bodies[1].includes('Liderança') && bodies[2].includes('Liderança'), 'Liderança fora do bloco do total')
  // Cabeçalho: cada coluna com o ícone, a cor e o rótulo da legenda.
  const head = section.slice(section.indexOf('<thead>'), section.indexOf('</thead>'))
  const headers = head.split('<th ').slice(2)
  assert.equal(headers.length, OPERATIONAL_SUMMARY_COLUMNS.length, 'uma coluna por contador')
  OPERATIONAL_SUMMARY_COLUMNS.forEach((column, index) => {
    assert.ok(headers[index].includes(column.tone), `coluna ${column.label} com a cor da legenda (${column.tone})`)
    assert.match(headers[index], /<svg[^>]*aria-hidden="true"/u, `coluna ${column.label} com ícone`)
    assert.ok(headers[index].includes(`</svg>${column.label}</span>`), `coluna ${column.label} com rótulo`)
  })
  for (const label of ['Escalados', 'Disponíveis', 'Em pausa', 'Almoço', 'Ausentes', 'Fora do expediente', 'Sem escala']) {
    assert.ok(head.includes(`</svg>${label}</span>`), `coluna "${label}"`)
  }
  assert.ok(section.includes('Ausentes: somente ausências lançadas; pausas não entram.'), 'nota dos ausentes')
  assert.ok(section.includes('Situação às 10:32'), 'hora do servidor (server_now), sem o fuso do navegador')
  assert.doesNotMatch(section, /inline-alert/u, 'sem aviso com a presença e as pausas disponíveis')
  // Cartões pelo resumo, nunca pela disponibilidade do status.php.
  assert.equal(metricValue(markup, 'CIs disponíveis'), '11', '"CIs disponíveis" = total.disponiveis do resumo (o cálculo antigo daria 9)')
  assert.ok(markup.includes('Aptos para atendimento agora'), '"CIs disponíveis" com o texto de sempre')
  assert.match(markup, /N1 <strong class="ml-2 text-lg text-emerald-300">4<\/strong>/u, '"Disponíveis por equipe": N1 pelo resumo (o cálculo antigo daria 6)')
  assert.match(markup, /N2 <strong class="ml-2 text-lg text-emerald-300">7<\/strong>/u, '"Disponíveis por equipe": N2 pelo resumo (o cálculo antigo daria 3)')
  // Os cartões que continuam: pausas, aprovações e chamados críticos.
  for (const label of ['Técnicos monitorados', 'Pausas ativas', 'Aprovações pendentes', 'Chamados críticos', 'War rooms', 'Plantonistas ativos', 'Sobreavisos ativos']) {
    assert.ok(markup.includes(`<p class="metric-label">${label}</p>`), `cartão "${label}" continua`)
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
  for (const team of ['N1', 'N2', 'Total (N1 + N2)', 'Liderança']) {
    assert.deepEqual(rowValues(section, team), Array(7).fill('—'), `${label}: linha ${team} com "—"`)
  }
  assert.ok(section.includes('inline-alert inline-alert-warning') && section.includes('Situação da equipe indisponível'), `${label}: aviso da presença indisponível`)
  assert.equal(metricValue(markup, 'CIs disponíveis'), '—', `${label}: "CIs disponíveis" com "—" (nunca o cálculo antigo)`)
  assert.ok(markup.includes('Indisponível: presença não calculada'), `${label}: "CIs disponíveis" com o aviso`)
  assert.match(markup, /N1 <strong class="ml-2 text-lg text-emerald-300">—<\/strong>/u, `${label}: N1 por equipe com "—"`)
  assert.match(markup, /N2 <strong class="ml-2 text-lg text-emerald-300">—<\/strong>/u, `${label}: N2 por equipe com "—"`)
  assert.ok(section.includes('Ausentes: somente ausências lançadas'), `${label}: a nota continua`)
}
// Estado de pausas ilegível: "—" só em Em pausa, com aviso (DV6).
{
  const markup = renderDashboardWith(dashboardData({ presence_meta: { disponivel: true, pausas_disponiveis: false } }))
  const section = summarySection(markup)
  for (const [team, label] of [['n1', 'N1'], ['n2', 'N2'], ['total', 'Total (N1 + N2)'], ['lideranca', 'Liderança']]) {
    assert.deepEqual(rowValues(section, label), expectedRow(team, ['em_pausa']), `pausas ilegíveis: ${label} com "—" só em Em pausa`)
  }
  assert.ok(section.includes('Pausas indisponíveis') && !section.includes('Situação da equipe indisponível'), 'pausas ilegíveis: o aviso das pausas, não o da presença')
  assert.equal(metricValue(markup, 'CIs disponíveis'), '11', 'pausas ilegíveis: "CIs disponíveis" continua pelo resumo')
}
// Contador fora do formato (texto, negativo, fracionário, ausente): "—".
{
  const odd = { ...SUMMARY, n1: { ...SUMMARY.n1, escalados: '11', disponiveis: -1, almoco: 1.5, ausentes: undefined } }
  const section = summarySection(renderDashboardWith(dashboardData({ presence_summary: odd })))
  assert.deepEqual(rowValues(section, 'N1'), ['—', '—', '2', '—', '—', '1', '21'], 'contador fora do formato vira "—"')
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
  assert.match(source, /summaryCount\(presence\.total, 'disponiveis'\)/u, '"CIs disponíveis" pelo total do resumo')
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
  const markup = renderPausas(employees, 'gestor', 0)
  const list = cards(markup)
  assert.equal(list.length, employees.length, 'um cartão por técnico')
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
    const card = list[index]
    assert.ok(card.startsWith(` operator-card-${state}"`), `${label}: cartão ${state} pela presenca`)
    assert.ok(card.includes(tone), `${label}: cor da legenda`)
    assert.match(card, new RegExp(`<svg[^>]*aria-hidden="true"[\\s\\S]*?</svg>${label}</span>`, 'u'), `${label}: ícone e rótulo`)
    assert.ok(card.includes(`title="${employees[index].presenca.detalhe}"`), `${label}: detalhe no title`)
    if (label !== 'Presencial' && label !== 'Remoto') assert.ok(!card.includes('>Disponível<'), `${label}: nunca "Disponível" do cálculo antigo`)
  })
  assert.ok(list[7].startsWith(' operator-card-pending"') && list[7].includes('Aguardando aprovação'), 'aprovação pendente continua primeiro')
  assert.ok(list[8].startsWith(' operator-card-available"') && list[8].includes('status-success') && list[8].includes('>Disponível</span>'), 'presenca nula (fora do Mapa ou presença desligada): cálculo antigo')
  assert.ok(list[9].startsWith(' operator-card-offline"') && list[9].includes('Fora do horário'), 'sem presenca: cálculo antigo (indisponível)')
  assert.ok(list[10].startsWith(' operator-card-offline"') && list[10].includes('presence-absence') && list[10].includes('font-mono'), 'em pausa durante ausência: situação do motor, com o timer da pausa')
}
{
  // Interruptor desligado: o status.php não manda presenca; tudo como antes.
  const employees = [
    worker(31, undefined, { em_pausa: true, motivo_pausa: 'Café' }),
    worker(32, undefined),
    worker(33, undefined, { disponibilidade: { status: 'indisponivel', label: 'Almoço' } }),
  ]
  const list = cards(renderPausas(employees, 'gestor', 0))
  assert.ok(list[0].startsWith(' operator-card-paused"') && list[0].includes('status-warning') && list[0].includes('Em pausa'), 'sem presenca: em pausa como antes')
  assert.ok(list[1].startsWith(' operator-card-available"') && list[1].includes('status-success'), 'sem presenca: disponível como antes')
  assert.ok(list[2].startsWith(' operator-card-offline"') && list[2].includes('status-neutral') && list[2].includes('Almoço'), 'sem presenca: indisponível como antes')
  assert.doesNotMatch(list.join(''), /presence-/u, 'sem presenca: nenhuma cor da legenda nova')
}

console.log('QA operational view OK')
