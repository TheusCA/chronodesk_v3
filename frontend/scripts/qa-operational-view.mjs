import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

// F7 (desenho 5.7 e 9.9): Visão Operacional. Commit 2 (DV11): a caixa da
// observação de reunião só aparece quando o servidor manda a chave
// observacao_reuniao (gestão e o próprio); os cartões de colegas da tela de
// Pausas não mudam. Os componentes são renderizados de verdade, como no
// qa:pa-map (JSX transpilado pelo TypeScript do projeto, react-dom/server).

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')

// O DashboardPage busca o dashboard.php pelo useResource; aqui ele responde
// pronto, sem rede. O resto do src é o código real.
const resourceStub = 'data:text/javascript,' + encodeURIComponent(
  'export function useResource() { return { loading: false, error: null, data: { summary: {} }, refresh: async () => null, setData: () => {} } }',
)
const srcUrl = pathToFileURL(join(frontendRoot, 'src')).href + '/'
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && context.parentURL?.endsWith('/src/pages/DashboardPage.jsx')) {
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

console.log('QA operational view OK')
