// [R4c] Administração > Aprovações (docs/design/PLANO_LOTES_R.md, R4c): a aba
// desenhada de verdade (JSX transpilado pelo TypeScript do projeto e
// react-dom/server), sem rede. O useResource do AdminPage responde com o que o
// teste põe em globalThis.qaResources; o filtro inicial vem de
// globalThis.qaInitialKind (o render estático não clica).
// Confere:
// 1. o filtro por tipo com a contagem de cada um (no lugar dos 4 cartões);
// 2. a lista densa, com os títulos das seções e os botões de sempre;
// 3. um botão principal por vez: "Aprovar" secundário, o primeiro pedido
//    visível marcado (data-first) e a regra de CSS que o faz principal só no
//    pedido em foco ou, sem nenhum em foco, no primeiro;
// 4. o filtro mostrando só a seção escolhida;
// 5. a confirmação do 17g nas horas extras e nos ajustes; pausas sem ela.
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

const frontendRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const srcRoot = join(frontendRoot, 'src')
const srcUrl = pathToFileURL(srcRoot).href + '/'
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')
const resourceStub = 'data:text/javascript,' + encodeURIComponent(
  'export function useResource(path) { return { loading: false, error: null, data: globalThis.qaResources?.[path] ?? null, refresh: async () => null, setData: () => {} } }',
)
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && /\/src\/pages\/AdminPage\.jsx$/u.test(context.parentURL ?? '')) return { url: resourceStub, shortCircuit: true }
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
      if (path.endsWith('/src/pages/AdminPage.jsx')) {
        const from = "const [kind, setKind] = useState('todos')"
        if (!source.includes(from)) throw new Error('AdminPage sem o estado do filtro esperado pelo QA')
        source = source.replace(from, "const [kind, setKind] = useState(globalThis.qaInitialKind ?? 'todos')")
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { AdminPage } = await import(pathToFileURL(join(srcRoot, 'pages', 'AdminPage.jsx')).href)
const text = (html) => html.replace(/<!-- -->/gu, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()

const overtime = (id) => ({ id, employee_id: id, employee_name: `QA Hora ${id}`, team: 'n1', status: 'pending', work_date: '2026-10-28', start_time: '18:00:00', end_time: '20:00:00', total_minutes: 120, reason: 'Motivo QA', justification: 'Justificativa QA', created_at: '2026-10-28 08:00:00' })
const adjustment = (id) => ({ id, employee_id: id, employee_name: `QA Ajuste ${id}`, team: 'n2', status: 'pending', adjustment_date: '2026-10-28', adjustment_type: 'entry', recorded_time: '08:10:00', correct_time: '08:00:00', justification: 'Ajuste QA', created_at: '2026-10-28 08:00:00' })
const PENDING = {
  solicitacoes: [{ id: 1, nome: 'QA Pausa', equipe: 'n1', motivo: 'Reunião', observacao: 'Pauta QA', solicitacao_timestamp: '2026-10-28 09:30:00' }],
  overtime: [overtime(11), overtime(12)],
  time_adjustments: [adjustment(21), adjustment(22)],
  overtime_truncated: false,
  time_adjustments_truncated: false,
  limit: 200,
}
const SESSION = { role: 'gestor', permissions: ['operacao.approve'], ci: { funcionario_id: 0 }, gestor: { username: 'gestor.qa' } }
const render = (data = PENDING, kind) => {
  globalThis.qaResources = { 'solicitacoes_pendentes.php': data, 'configuracoes.php': { sucesso: true, configuracoes: {} }, 'listar_funcionarios.php': { sucesso: true, funcionarios: [] } }
  globalThis.qaInitialKind = kind
  try {
    return renderToStaticMarkup(h(AdminPage, { session: SESSION, search: '?tab=aprovacoes', navigate: () => {}, notify: () => {}, refreshStatus: () => {} }))
  } finally {
    delete globalThis.qaResources
    delete globalThis.qaInitialKind
  }
}
const items = (markup) => [...markup.matchAll(/<li class="approval-item"( data-first="")?>([\s\S]*?)<\/li>/gu)].map((match) => ({ first: Boolean(match[1]), html: match[2] }))
const titles = (markup) => [...markup.matchAll(/<h2 class="section-title"[^>]*>([^<]+)<\/h2>/gu)].map((match) => match[1])

// 1. Filtro por tipo com a contagem (no lugar dos 4 cartões).
{
  const markup = render()
  const filters = [...markup.matchAll(/<button aria-pressed="(true|false)" class="segmented-option" type="button"><span>([^<]+)<\/span><span class="segmented-count">(\d+)<\/span><\/button>/gu)].map((match) => [match[2], match[3], match[1]])
  assert.deepEqual(filters, [['Todos', '5', 'true'], ['Pausas e reuniões', '1', 'false'], ['Horas extras', '2', 'false'], ['Correção de ponto', '2', 'false']], 'filtro por tipo com a contagem de cada um; "Todos" escolhido')
  assert.match(markup, /<div aria-label="Filtrar por tipo" class="segmented" role="group">/u, 'filtro com nome')
  assert.doesNotMatch(markup, /metric-card|approval-card|Fila completa da central|detail-pill/u, 'saem os 4 cartões de contagem e os cartões de pedido')

  // 2. Lista densa: as seções de sempre, os botões de sempre, os detalhes numa linha.
  assert.deepEqual(titles(markup), ['Pausas/Reuniões pendentes', 'Horas extras pendentes', 'Ajustes de ponto pendentes'], 'as três seções, com os títulos de sempre (o roteiro do navegador as acha por eles)')
  assert.equal((markup.match(/<div class="section-header|<section class="section-header/gu) ?? []).length, 3, 'cada seção com o cabeçalho .section-header (escopo do roteiro do navegador)')
  const list = items(markup)
  assert.equal(list.length, 5, 'um item por pedido')
  assert.ok(list.every((item) => />Aprovar<\/button>/u.test(item.html) && />Rejeitar<\/button>/u.test(item.html)), 'cada pedido com "Aprovar" e "Rejeitar"')
  assert.ok(text(list[1].html).includes('2026-10-28 · 18:00 - 20:00 · total 2h 00min · Pendente'), 'hora extra: data, período, total e situação numa linha')
  assert.ok(text(list[3].html).includes('2026-10-28 · Entrada · horário correto 08:00 · Pendente'), 'ajuste: data, tipo e horário correto numa linha')
  assert.ok(text(list[0].html).includes('Reunião · solicitado em') && list[0].html.includes('Pauta QA'), 'pausa: motivo, quando e a observação')

  // 3. Um botão principal por vez.
  assert.deepEqual(list.map((item) => item.first), [true, false, false, false, false], 'sem nenhum em foco, só o primeiro pedido marcado')
  assert.ok(list.every((item) => item.html.includes('<button class="btn-secondary btn-sm btn-approve" type="button">Aprovar</button>')), '"Aprovar" é secundário em todos (vira principal pelo CSS)')
  assert.doesNotMatch(markup.slice(markup.indexOf('approvals')), /class="btn-primary/u, 'nenhum botão principal fixo na lista')
  const css = read('src/index.css')
  assert.match(css, /\.approvals \.approval-item:is\(:hover, :focus-within\) \.btn-approve,\n\.approvals:not\(:has\(\.approval-item:is\(:hover, :focus-within\)\)\) \.approval-item\[data-first\] \.btn-approve \{\n {2}border-color: rgb\(var\(--action\)\);\n {2}background-color: rgb\(var\(--action\)\);\n {2}color: rgb\(var\(--action-ink\)\);/u, 'CSS: principal só no pedido em foco ou, sem nenhum em foco, no primeiro')
}

// 4. O filtro mostra só a seção escolhida; o primeiro dela leva o botão principal.
for (const [kind, expected, count] of [['pausas', ['Pausas/Reuniões pendentes'], 1], ['horas', ['Horas extras pendentes'], 2], ['ajustes', ['Ajustes de ponto pendentes'], 2]]) {
  const markup = render(PENDING, kind)
  assert.deepEqual(titles(markup), expected, `filtro ${kind}: só a seção do tipo`)
  const list = items(markup)
  assert.equal(list.length, count, `filtro ${kind}: só os pedidos do tipo`)
  assert.ok(list[0].first && list.slice(1).every((item) => !item.first), `filtro ${kind}: o primeiro do tipo leva o botão principal`)
}
{
  const markup = render({ ...PENDING, solicitacoes: [] }, 'pausas')
  assert.ok(markup.includes('Não há itens desta categoria para decisão agora.') && items(markup).length === 0, 'tipo sem pedido: o vazio da seção')
  const none = render({ solicitacoes: [], overtime: [], time_adjustments: [], limit: 200 })
  assert.ok(none.includes('Nenhuma aprovação pendente') && !none.includes('data-first'), 'sem nada pendente: o vazio, sem botão')
  const truncated = render({ ...PENDING, overtime_truncated: true })
  assert.ok(truncated.includes('Há mais horas extras pendentes do que as exibidas.'), 'lista cortada pelo servidor: o aviso de sempre')
}

// 5. A confirmação do 17g fica nas horas extras e nos ajustes; pausas sem ela (decisão de 2026-10-29).
{
  const source = read('src/pages/AdminPage.jsx')
  const tab = source.slice(source.indexOf('function ApprovalsTab('), source.indexOf('function SettingsTab('))
  assert.equal((tab.match(/decision\.ask\('overtime'/gu) ?? []).length, 2, 'horas extras: Aprovar e Rejeitar pela confirmação')
  assert.equal((tab.match(/decision\.ask\('adjustment'/gu) ?? []).length, 2, 'ajustes: Aprovar e Rejeitar pela confirmação')
  assert.match(tab, /onApprove=\{\(\) => decidePause\(request\.id, 'aprovar'\)\} onReject=\{\(\) => decidePause\(request\.id, 'rejeitar'\)\}/u, 'pausas: sem confirmação, como decidido')
  assert.match(tab, /<DecisionConfirmDialog \{\.\.\.decision\.dialogProps\} \/>/u, 'o diálogo de confirmação continua na aba')
}

console.log('QA de Aprovações OK')
