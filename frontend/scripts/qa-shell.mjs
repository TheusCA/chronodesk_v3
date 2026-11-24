// [R3] Casca do app (docs/design/PLANO_LOTES_R.md, R3): o PortalLayout e o
// login desenhados de verdade (JSX transpilado pelo TypeScript do projeto e
// react-dom/server), sem rede. O useResource da casca responde com o que o
// teste põe em globalThis.qaResources e anota cada chamada.
// Confere:
// 1. "Ir para o conteúdo" como primeiro elemento focável, levando ao <main>;
// 2. um título só no topo (sem o "Portal SDK" de sobretítulo nem a descrição);
// 3. a marca sem imagem, com o nome "Portal SDK" escrito, e o crédito;
// 4. o contador de pendências do menu: só o número, fora do botão (o nome do
//    item continua o rótulo), ligado como descrição, e só para quem decide;
// 5. o item atual com aria-current e a barra;
// 6. o relógio do topo pela hora do servidor;
// 7. as iniciais do avatar: primeiro e último nome, sem número.
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
  'export function useResource(path, options = {}) {'
  + ' const enabled = options.enabled ?? true;'
  + ' (globalThis.qaResourceCalls ??= []).push({ path, enabled, intervalMs: options.intervalMs ?? 0 });'
  + ' return { loading: false, error: null, data: enabled ? (globalThis.qaResources?.[path] ?? null) : null, refresh: async () => null, setData: () => {} } }',
)
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && /\/src\/components\/PortalLayout\.jsx$/u.test(context.parentURL ?? '')) {
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
    const path = url.split('?')[0]
    if (path.startsWith(srcUrl) && /\.(jsx|tsx|ts)$/u.test(path)) {
      const output = ts.transpileModule(readFileSync(fileURLToPath(path), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const src = (path) => import(pathToFileURL(join(srcRoot, path)).href)
const { PortalLayout } = await src('components/PortalLayout.jsx')
const { LoginPage } = await src('pages/LoginPage.jsx')
const { UserAvatar } = await src('components/ui/Primitives.jsx')
const { nameInitials } = await src('lib/initials.ts')
const { pendingApprovalsLabel } = await src('lib/pendingApprovals.ts')
const { navigation, pageMetadata } = await src('lib/navigation.ts')
const text = (markup) => markup.replace(/<[^>]+>/gu, '').replace(/\s+/gu, ' ').trim()

const MANAGER = ['portal.read', 'pausas.use', 'metricas.read', 'relatorios.read', 'documentacao.edit', 'avisos.manage', 'operacao.approve', 'ausencias.manage']
const managerSession = { role: 'gestor', permissions: MANAGER, ci: { autenticado: false, nome: '' }, gestor: { autenticado: true, username: 'gestor.exemplo' } }
const technicianSession = { role: 'tecnico', permissions: ['portal.read', 'pausas.use'], ci: { autenticado: true, nome: 'Técnico 01 Exemplo', username: 'tecnico.01' }, gestor: { autenticado: false, username: '' } }
// Nomes e observação fictícios, para provar que não chegam ao menu.
const PENDING = {
  solicitacoes: [{ id: 1, nome: 'Pessoa Pedido Exemplo', equipe: 'n1', motivo: 'Reunião', observacao: 'Pauta QA reservada' }],
  overtime: [{ id: 2, employee_name: 'Outra Pessoa Exemplo' }],
  time_adjustments: [{ id: 3, employee_name: 'Terceira Pessoa Exemplo' }],
  overtime_truncated: false,
  time_adjustments_truncated: false,
}
const SERVER_NOW = Date.parse('2026-10-28T10:42:05-03:00')
const renderLayout = ({ session = managerSession, path = '/pausas', resources = {}, serverNow = SERVER_NOW } = {}) => {
  globalThis.qaResources = resources
  globalThis.qaResourceCalls = []
  try {
    const markup = renderToStaticMarkup(h(PortalLayout, { session, path, navigate: () => {}, onLogout: () => {}, serverNow }, h('p', null, 'Conteúdo QA')))
    return { markup, calls: globalThis.qaResourceCalls }
  } finally {
    delete globalThis.qaResources
    delete globalThis.qaResourceCalls
  }
}
const section = (markup, open, close) => {
  const start = markup.indexOf(open)
  assert.ok(start >= 0, `trecho ${open}`)
  return markup.slice(start, markup.indexOf(close, start) + close.length)
}

// ---------------------------------------------------------------------------
// 1. "Ir para o conteúdo": o primeiro focável, e o conteúdo recebe o foco
// ---------------------------------------------------------------------------
{
  const { markup } = renderLayout()
  const focusable = markup.match(/<(a|button|input|select|textarea)\b[^>]*>|<[a-z]+\b[^>]*tabindex="0"[^>]*>/u)?.[0] ?? ''
  assert.match(focusable, /^<a [^>]*href="#conteudo"/u, '"Ir para o conteúdo" é o primeiro elemento focável da casca')
  assert.ok(markup.includes('>Ir para o conteúdo</a>'), '"Ir para o conteúdo" com o texto')
  assert.match(markup, /<main [^>]*id="conteudo"[^>]*tabindex="-1"/u, 'o <main> é o destino (id="conteudo") e recebe foco por script')
  assert.equal((markup.match(/id="conteudo"/gu) ?? []).length, 1, 'um destino só')
  const layoutSource = read('src/components/PortalLayout.jsx')
  assert.match(layoutSource, /function skipToContent\(event\) \{\n {2}event\.preventDefault\(\)\n {2}document\.getElementById\('conteudo'\)\?\.focus\(\)/u, '"Ir para o conteúdo" leva o foco sem mudar o endereço (o roteador trataria o #conteudo como navegação)')
}

// ---------------------------------------------------------------------------
// 2. Um título só no topo
// ---------------------------------------------------------------------------
for (const path of ['/pausas', '/dashboard', '/admin', '/mapa-pa', '/chamados-criticos']) {
  const { markup } = renderLayout({ path })
  const header = section(markup, '<header', '</header>')
  const [title, description] = pageMetadata[path]
  assert.equal((markup.match(/<h1\b/gu) ?? []).length, 1, `${path}: um <h1> só`)
  assert.match(header, new RegExp(`<h1 [^>]*data-page-title=""[^>]*tabindex="-1"[^>]*>${title}</h1>`, 'u'), `${path}: o título da tela, focável (data-page-title)`)
  assert.ok(!header.includes('Portal SDK'), `${path}: sem o "Portal SDK" de sobretítulo no topo`)
  assert.ok(!markup.includes(description), `${path}: sem a descrição da tela (D-R0-8)`)
}

// ---------------------------------------------------------------------------
// 3. Marca sem imagem, nome escrito e crédito
// ---------------------------------------------------------------------------
{
  const { markup } = renderLayout()
  const login = renderToStaticMarkup(h(LoginPage, { onLoginCI: async () => null, loading: false }))
  for (const [label, html] of [['casca', markup], ['login', login]]) {
    assert.doesNotMatch(html, /<img\b/u, `${label}: marca sem imagem (nada de arquivo fora do build)`)
    assert.match(html, /<svg aria-hidden="true" class="[^"]*" data-brand-mark="" focusable="false" viewBox="0 0 64 64" xmlns="http:\/\/www\.w3\.org\/2000\/svg">/u, `${label}: marca em SVG inline, decorativa (viewBox 64 x 64)`)
    assert.doesNotMatch(html.slice(html.indexOf('data-brand-mark'), html.indexOf('</svg>', html.indexOf('data-brand-mark'))), /style=|#[0-9a-f]{3,6}\b/iu, `${label}: marca sem atributo style nem cor fixa (CSP e os dois temas)`)
    assert.ok(html.includes('Portal SDK'), `${label}: o nome "Portal SDK" escrito`)
    assert.ok(html.includes('Desenvolvido por Matheus Camargo'), `${label}: crédito do desenvolvedor`)
  }
  assert.doesNotMatch(read('src/components/BrandMark.jsx').replace(/^\s*\/\/.*$/gmu, ''), /\.png|<img|src=|style=/u, 'BrandMark sem imagem nem style (fora os comentários)')
  // [Marca] A mesma geometria do favicon.svg, pelos tokens: placa bg-action (fill),
  // anel e barras em currentColor do text-action-ink, ponto na cor da situação "Em pausa".
  const brand = read('src/components/BrandMark.jsx')
  const favicon = read('public/favicon.svg')
  // [Marca B, 2026-11-23] As chaves (dois paths) e as duas barras da pausa, com
  // o mesmo traço e as mesmas medidas do favicon.svg.
  const attr = (tag, name) => tag.match(new RegExp(`\\b${name}="([^"]+)"`, 'u'))?.[1]
  const shapes = (source) => ({
    braces: [...source.matchAll(/<path [^>]*\bd="([^"]+)"/gu)].map((match) => match[1]),
    stroke: source.match(/stroke-?[wW]idth="([\d.]+)"/u)?.[1],
    bars: [...source.matchAll(/<rect [^>]*\by="23"[^>]*>/gu)].map((match) => ['x', 'y', 'width', 'height', 'rx'].map((name) => attr(match[0], name)).join(',')),
    tile: (source.match(/<rect [^>]*\bheight="64"[^>]*>/u) || [''])[0].includes('rx="14"'),
  })
  assert.deepEqual(shapes(brand), shapes(favicon), 'BrandMark: as chaves, o traço, as duas barras e a placa do favicon.svg (opção B)')
  assert.deepEqual([shapes(brand).braces.length, shapes(brand).bars.length, shapes(brand).tile], [2, 2, true], 'BrandMark: duas chaves, duas barras e a placa com cantos')
  assert.match(brand, /<rect className="fill-action" height="64" rx="14" width="64" \/>/u, 'BrandMark: a placa 64 x 64 com rx 14 no token do bg-action')
  assert.match(brand, /<g className="text-action-ink" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="4\.5">[\s\S]*?<\/g>/u, 'BrandMark: as chaves em currentColor do text-action-ink')
  assert.match(brand, /<g className="fill-pause-line" data-brand-pause="">\s*<rect height="18" rx="2\.5" width="5" x="25\.5" y="23" \/>\s*<rect height="18" rx="2\.5" width="5" x="33\.5" y="23" \/>\s*<\/g>/u, 'BrandMark: as barras da pausa no token da situação "Em pausa" (pause-line)')
}

// ---------------------------------------------------------------------------
// 4. Contador de pendências: só o número, fora do botão, só para quem decide
// ---------------------------------------------------------------------------
assert.equal(pendingApprovalsLabel(PENDING), '3', 'pausas + horas extras + correções de ponto')
assert.equal(pendingApprovalsLabel({ ...PENDING, overtime_truncated: true }), '3+', 'lista cortada pelo servidor: "N+"')
assert.equal(pendingApprovalsLabel({ solicitacoes: [], overtime: [], time_adjustments: [] }), null, 'sem pendência: sem contador')
assert.equal(pendingApprovalsLabel(null), null, 'sem resposta: sem contador')
assert.equal(pendingApprovalsLabel({ solicitacoes: 'x', overtime: 2 }), null, 'campos fora do formato não contam')
{
  const { markup, calls } = renderLayout({ resources: { 'solicitacoes_pendentes.php': PENDING } })
  const pendingCall = calls.find((call) => call.path === 'solicitacoes_pendentes.php')
  assert.ok(pendingCall?.enabled === true && pendingCall.intervalMs === 60000, 'gestão: busca as pendências a cada 60 s')
  const nav = section(markup, '<nav class="sidebar-scroll', '</nav>')
  const item = nav.split('<div class="relative">').find((part) => part.includes('Aprovações e admin'))
  assert.ok(item, 'item "Aprovações e admin" no menu')
  const button = item.slice(0, item.indexOf('</button>') + '</button>'.length)
  assert.equal(text(button), 'Aprovações e admin', 'o nome do item continua só o rótulo (o número fica fora do botão)')
  const countId = button.match(/aria-describedby="([^"]+)"/u)?.[1]
  assert.ok(countId, 'o botão aponta para o contador como descrição')
  const badge = item.match(new RegExp(`<span class="nav-count[^"]*" data-nav-count="" id="${countId}">([\\s\\S]*?)</span></span>`, 'u'))
  assert.ok(badge, 'contador ao lado do botão, com o id da descrição')
  const visible = badge[1].match(/<span aria-hidden="true">([^<]*)<\/span>/u)?.[1]
  assert.match(visible ?? '', /^\d+\+?$/u, 'o contador mostra só o número')
  assert.equal(visible, '3')
  assert.ok(badge[0].replaceAll('<!-- -->', '').includes('<span class="sr-only">3 pendentes</span>'), 'o leitor de tela ouve "3 pendentes"')
  for (const secret of ['Pessoa Pedido Exemplo', 'Outra Pessoa Exemplo', 'Terceira Pessoa Exemplo', 'Pauta QA reservada', 'Reunião']) {
    assert.ok(!markup.includes(secret), `nada de quem pediu no menu (${secret})`)
  }
  assert.equal((markup.match(/data-nav-count=""/gu) ?? []).length, 1, 'um contador só, no item de Aprovações')
  const truncated = renderLayout({ resources: { 'solicitacoes_pendentes.php': { ...PENDING, time_adjustments_truncated: true } } }).markup
  assert.match(truncated, /<span aria-hidden="true">3\+<\/span>/u, 'lista cortada: "3+"')
  const none = renderLayout({ resources: { 'solicitacoes_pendentes.php': { solicitacoes: [], overtime: [], time_adjustments: [] } } }).markup
  assert.ok(!none.includes('data-nav-count') && !none.includes('aria-describedby="nav-lateral-pending-count"'), 'sem pendência: sem contador e sem descrição')
}
{
  const { markup, calls } = renderLayout({ session: technicianSession, resources: { 'solicitacoes_pendentes.php': PENDING } })
  const pendingCall = calls.find((call) => call.path === 'solicitacoes_pendentes.php')
  assert.equal(pendingCall?.enabled, false, 'técnico: não busca as pendências (o endpoint é da gestão)')
  assert.ok(!markup.includes('data-nav-count') && !markup.includes('Aprovações e admin'), 'técnico: sem o item e sem contador')
}

// ---------------------------------------------------------------------------
// 5. Item atual
// ---------------------------------------------------------------------------
{
  const { markup } = renderLayout({ path: '/dashboard' })
  const nav = section(markup, '<nav class="sidebar-scroll', '</nav>')
  const current = nav.match(/<button aria-current="page"[^>]*>([\s\S]*?)<\/button>/gu) ?? []
  assert.equal(current.length, 1, 'um item atual')
  assert.ok(current[0].includes('sidebar-link-active') && text(current[0]) === 'Visão operacional', 'item atual com a barra (sidebar-link-active) e aria-current')
  // [9.17] Módulo atrás de interruptor (session.modulos) fica fora sem o módulo ligado.
  const routes = navigation.filter((entry) => entry.path && !entry.module && (!entry.permission || MANAGER.includes(entry.permission)) && (!entry.anyPermission || entry.anyPermission.some((permission) => MANAGER.includes(permission))))
  assert.equal((nav.match(/<button /gu) ?? []).length, routes.length, 'um botão por rota permitida')
  for (const entry of navigation.filter((item) => item.section)) assert.ok(nav.includes(`>${entry.section}</p>`), `grupo "${entry.section}" em caixa normal`)
}

// ---------------------------------------------------------------------------
// 6. Relógio do topo pela hora do servidor
// ---------------------------------------------------------------------------
{
  const format = (instant) => new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(new Date(instant))
  for (const serverNow of [SERVER_NOW, SERVER_NOW + 3 * 3600_000 + 17 * 60_000]) {
    const header = section(renderLayout({ serverNow }).markup, '<header', '</header>')
    const clock = header.match(/<p [^>]*data-server-clock="">([\s\S]*?)<\/p>/u)?.[1] ?? ''
    assert.equal(clock.match(/<time [^>]*>([^<]*)<\/time>/u)?.[1], format(serverNow), `relógio do topo pela hora do servidor (${format(serverNow)})`)
    assert.ok(text(clock).startsWith('Servidor'), 'relógio com o rótulo "Servidor"')
  }
}

// ---------------------------------------------------------------------------
// 7. Iniciais do avatar
// ---------------------------------------------------------------------------
for (const [name, expected, label] of [
  ['Técnico 01 Exemplo', 'TE', 'número no meio não conta (antes, "T0")'],
  ['Maria da Conceição Souza', 'MS', 'nome composto: primeiro e último'],
  ['Ângela Úrsula', 'ÂÚ', 'acento mantido'],
  ['élcio pereira', 'ÉP', 'minúscula com acento vira maiúscula'],
  ['Admin', 'A', 'uma palavra: uma letra'],
  ['Técnico 01', 'T', 'nome com número no fim: só o primeiro'],
  ['01 02', '', 'só números: texto de reserva'],
  ['lideranca.exemplo', 'LE', 'login com ponto: os dois nomes'],
  ['   ', '', 'vazio: texto de reserva'],
  [null, '', 'nulo: texto de reserva'],
]) {
  assert.equal(nameInitials(name), expected, `iniciais de ${JSON.stringify(name)}: ${label}`)
}
assert.equal(nameInitials('42', 'SDK'), 'SDK', 'reserva escolhida por quem chama')
assert.equal(renderToStaticMarkup(h(UserAvatar, { name: 'Técnico 01 Exemplo' })), '<span class="user-avatar " aria-hidden="true">TE</span>', 'UserAvatar com as iniciais novas')
assert.equal(renderToStaticMarkup(h(UserAvatar, { name: '' })), '<span class="user-avatar " aria-hidden="true">SDK</span>', 'UserAvatar sem nome: "SDK", como antes')
{
  const { markup } = renderLayout({ session: technicianSession })
  assert.match(markup, /<span aria-hidden="true" class="user-avatar h-9 w-9">TE<\/span>/u, 'rodapé do menu com as iniciais de quem entrou')
  // [R4a] O login do técnico saiu do cartão "Sessão do colaborador" de Pausas e fica aqui.
  assert.match(markup, /<span class="font-mono" data-user-login="">tecnico\.01 · <\/span>/u, 'rodapé do menu com o login do técnico')
  assert.ok(!renderLayout().markup.includes('data-user-login'), 'gestão (sem CI): sem a linha do login de técnico')
}

// ---------------------------------------------------------------------------
// 8. [Commit 2] Celular: barra de atalhos (D-R0-7) e menu em folha inteira
// ---------------------------------------------------------------------------
const { default: postcss } = await import('postcss')
const { default: tailwindcss } = await import('tailwindcss')
const { default: tailwindConfig } = await import(pathToFileURL(join(frontendRoot, 'tailwind.config.js')).href)
const compiled = (await postcss([tailwindcss({ ...tailwindConfig, content: tailwindConfig.content.map((glob) => join(frontendRoot, glob).replaceAll('\\', '/')) })]).process(read('src/index.css'), { from: join(srcRoot, 'index.css') })).css
const ruleOf = (className) => compiled.match(new RegExp(`(?:^|[\\n,])\\s*\\.${className}\\s*\\{([^}]*)\\}`, 'u'))?.[1] ?? ''
{
  const tabbar = (session, path = '/dashboard') => {
    const nav = section(renderLayout({ session, path }).markup, '<nav aria-label="Atalhos"', '</nav>')
    return { nav, items: [...nav.matchAll(/<button ([^>]*)>([\s\S]*?)<\/button>/gu)].map((match) => ({ attrs: match[1], label: text(match[2]) })) }
  }
  const manager = tabbar(managerSession)
  assert.match(manager.nav, /^<nav aria-label="Atalhos" class="tabbar sm:hidden" data-tabbar="">/u, 'barra de atalhos só abaixo de 640 px')
  assert.deepEqual(manager.items.map((item) => item.label), ['Agora', 'Pausas', 'Escala', 'Menu'], '4 atalhos: Agora, Pausas, Escala e Menu')
  assert.ok(manager.items.every((item) => /type="button"/u.test(item.attrs) && /class="tabbar-item"/u.test(item.attrs)), 'atalhos são botões com o alvo da barra')
  assert.ok(/aria-current="page"/u.test(manager.items[0].attrs) && manager.items.slice(1).every((item) => !/aria-current/u.test(item.attrs)), 'o atalho da tela atual com aria-current')
  assert.match(manager.items[3].attrs, /aria-expanded="false" aria-haspopup="dialog"/u, '"Menu" abre o menu (diálogo)')
  assert.ok(/aria-current="page"/u.test(tabbar(managerSession, '/escala-presencial').items[2].attrs), 'Escala atual na Escala presencial')
  const readOnly = tabbar({ ...managerSession, role: 'somente_leitura', permissions: ['portal.read'] })
  assert.deepEqual(readOnly.items.map((item) => item.label), ['Agora', 'Escala', 'Menu'], 'sem pausas.use, sem o atalho de Pausas (a mesma permissão do menu)')
  const layoutSource = read('src/components/PortalLayout.jsx')
  const tabbarSource = layoutSource.slice(layoutSource.indexOf('<nav aria-label="Atalhos"'), layoutSource.indexOf('</nav>', layoutSource.indexOf('<nav aria-label="Atalhos"')))
  assert.match(tabbarSource, /onClick=\{\(\) => goTo\(item\.path\)\}/u, 'atalho pelo goTo (navigate do roteador, com a guarda do formulário sujo)')
  assert.doesNotMatch(tabbarSource, /history\.|location\.|href=/u, 'atalho sem atalho de navegação fora do roteador')
  assert.match(layoutSource, /function goTo\(target\) \{\n {4}navigate\(target\)/u, 'goTo continua pelo navigate')
  // Alvo de 64 px fora de @media (vale em 390 px) e a barra fixa embaixo.
  assert.match(ruleOf('tabbar-item'), /min-height:\s*4rem/u, 'atalho com 64 px de alto')
  assert.match(ruleOf('tabbar'), /position:\s*fixed[\s\S]*bottom:\s*0px/u, 'barra fixa embaixo')
  // Menu em folha inteira no celular; de 640 px para cima, a coluna de 320 px.
  assert.match(layoutSource, /<aside className="relative flex h-full w-full flex-col bg-surface sm:w-80[^"]*" role="dialog" aria-label="Menu principal" aria-modal="true">/u, 'menu do celular em folha inteira')
  // O conteúdo não fica escondido atrás da barra: folga embaixo no celular.
  assert.match(renderLayout().markup, /<main class="[^"]*\bpb-28\b[^"]*\bsm:pb-6\b/u, 'folga de 112 px embaixo do conteúdo no celular')
}

// ---------------------------------------------------------------------------
// 9. [Commit 2] A página não repete o título do topo
// ---------------------------------------------------------------------------
{
  const pa = read('src/pages/PaMapPage.jsx')
  assert.doesNotMatch(pa, /title="Mapa de PA"|eyebrow="Mapa visual da operação"/u, 'Mapa de PA sem repetir o título')
  assert.ok(pa.includes('Situação de cada técnico pela escala e pelas ausências, com conflito de PA na semana (segunda a sexta).'), 'Mapa de PA: a regra do conflito fica, com as mesmas palavras')
  const critical = read('src/pages/CriticalIncidentsPage.jsx')
  assert.doesNotMatch(critical, />Chamados críticos<\/h2>|War room e sala de crise|Registro central de impacto/u, 'Chamados críticos sem repetir o título nem a apresentação')
  assert.match(critical, /Exportar planilha CSV[\s\S]*Importar planilha[\s\S]*Novo chamado/u, 'Chamados críticos: as ações ficam')
  const admin = read('src/pages/AdminPage.jsx')
  assert.doesNotMatch(admin, /title="Aprovações"|eyebrow="Central operacional"/u, 'Aprovações sem repetir o nome da aba')
  // [R4c] O total saiu do cartão "Total pendente" e foi para o filtro por tipo ("Todos N").
  assert.match(admin, /\['todos', 'Todos'\]/u, 'Aprovações: o total continua no filtro ("Todos")')
  assert.match(admin, /const counts = \{ todos: total,/u, 'Aprovações: "Todos" conta todos os pedidos')
}

console.log('QA da casca OK')
