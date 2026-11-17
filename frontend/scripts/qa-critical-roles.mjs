// [9.16] Rodízio de papéis do Crítico (desenho principal, 9.16). Funções
// puras de src/lib/criticalRoles.ts, com paridade de rótulo contra o
// servidor (CriticalRolePlanner::label, chamado pelo PHP) e as telas
// desenhadas de verdade (JSX transpilado pelo TypeScript do projeto e
// react-dom/server), sem rede. Confere:
// 1. funções puras: rótulo, jornada, âncora (semana e mês), ordem, prévia;
// 2. paridade do rótulo com o servidor;
// 3. seção "Papéis do Crítico": papel de hoje, troca, avisos, gestor só lê;
// 4. painel: papéis, rodízio com a prévia obrigatória e trocas pontuais;
// 5. tela Escala e Visão operacional (o alerta de papel vago).
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

const frontendRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const projectRoot = join(frontendRoot, '..')
const srcRoot = join(frontendRoot, 'src')
const srcUrl = pathToFileURL(srcRoot).href + '/'
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')
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
    const path = url.split('?')[0]
    if (path.startsWith(srcUrl) && /\.(jsx|tsx|ts)$/u.test(path)) {
      let source = readFileSync(fileURLToPath(path), 'utf8')
      if (path.endsWith('/src/pages/CriticalRoles.jsx')) {
        const from = 'const [open, setOpen] = useState(false)'
        if (!source.includes(from)) throw new Error('CriticalRoles sem o estado do painel esperado pelo QA')
        source = source.replace(from, 'const [open, setOpen] = useState(globalThis.qaOpenCritical ?? false)')
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const lib = await import(pathToFileURL(join(srcRoot, 'lib', 'criticalRoles.ts')).href)
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { CriticalRolesSection } = await import(pathToFileURL(join(srcRoot, 'pages', 'CriticalRoles.jsx')).href)
const text = (html) => html.replace(/<!-- -->/gu, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()
let checks = 0
const check = (fn) => { fn(); checks++ }

// 1. Funções puras.
check(() => assert.equal(lib.roleLabel({ name: 'Abertura home', work_start: '07:00:00', work_end: '17:00' }), 'Abertura home 07:00–17:00', 'rótulo: nome e jornada em HH:MM'))
check(() => assert.deepEqual([lib.validShift('07:00', '17:00'), lib.validShift('17:00', '07:00'), lib.validShift('08:00', '08:00'), lib.validShift('7:00', '17:00'), lib.validShift('24:00', '25:00')], [true, false, false, false, false], 'jornada: HH:MM, início antes do fim, sem cruzar a meia-noite'))
check(() => assert.deepEqual(['2026-11-16', '2026-11-18', '2026-11-22', '2026-11-01'].map((date) => lib.anchorFor('week', date)), ['2026-11-16', '2026-11-16', '2026-11-16', '2026-10-26'], 'âncora semanal: a segunda da semana (domingo fica na semana que começou na segunda)'))
check(() => assert.deepEqual([lib.anchorFor('month', '2026-11-18'), lib.anchorFor('month', '2026-12-01'), lib.anchorFor('week', 'x')], ['2026-11-01', '2026-12-01', 'x'], 'âncora mensal: dia 1 do mês (AD-PC-1); data inválida volta igual'))
const list = [1, 2, 3]
check(() => assert.deepEqual([lib.moveItem(list, 0, 1), lib.moveItem(list, 2, -1), lib.moveItem(list, 0, -1), lib.moveItem(list, 2, 1)], [[2, 1, 3], [1, 3, 2], [1, 2, 3], [1, 2, 3]], 'ordem: sobe e desce sem sair da lista'))
check(() => assert.deepEqual(list, [1, 2, 3], 'ordem: não muta a lista original'))
check(() => assert.deepEqual([lib.toggleId([1, 2], 3), lib.toggleId([1, 2, 3], 2)], [[1, 2, 3], [1, 3]], 'marcar entra no fim; desmarcar mantém a ordem dos outros'))
const days = [
  { date: '2026-11-16', roles: { 11: 1, 12: 3 }, vacant: [] },
  { date: '2026-11-17', roles: { 11: 1, 12: 3 }, vacant: [] },
  { date: '2026-11-23', roles: { 11: 3, 12: 1 }, vacant: [] },
  { date: '2026-11-24', roles: { 11: 3, 12: 1 }, vacant: [4] },
]
check(() => assert.deepEqual(lib.changePoints(days).map((day) => day.date), ['2026-11-16', '2026-11-23', '2026-11-24'], 'prévia: só os dias em que algum papel (ou vago) muda'))
check(() => assert.deepEqual([lib.REASON_LABELS.troca, lib.REASON_LABELS.cobertura, lib.REASON_LABELS.outro, lib.OVERRIDE_MAX_DAYS], ['Troca entre pessoas', 'Cobertura de ausência', 'Outro motivo', 31], 'motivos em lista fechada e o limite da troca, como o servidor'))

// 2. Paridade do rótulo com o servidor.
{
  const php = process.env.PHP_BIN || 'php'
  const code = "require 'services/CriticalRolePlanner.php'; echo CriticalRolePlanner::label(['name' => 'Fechamento home', 'work_start' => '10:30:00', 'work_end' => '20:30:00']);"
  let server = null
  try {
    server = execFileSync(php, ['-r', code], { cwd: projectRoot, encoding: 'utf8' })
  } catch (error) {
    throw new Error(`PHP indisponível para a paridade do rótulo: ${error.message}`)
  }
  check(() => assert.equal(lib.roleLabel({ name: 'Fechamento home', work_start: '10:30:00', work_end: '20:30:00' }), server, 'rótulo igual ao do servidor'))
}

// 3 e 4. Seção e painel.
const DATA = {
  today: '2026-11-18',
  roles: [
    { id: 1, name: 'Presencial A', presence_type: 'onsite', work_start: '08:00', work_end: '17:00', active: true, sort_order: 1 },
    { id: 3, name: 'Abertura home', presence_type: 'remote', work_start: '07:00', work_end: '17:00', active: true, sort_order: 3 },
    { id: 4, name: 'Fechamento home', presence_type: 'remote', work_start: '10:30', work_end: '20:30', active: true, sort_order: 4 },
    { id: 5, name: 'Antigo', presence_type: 'remote', work_start: '09:00', work_end: '18:00', active: false, sort_order: 5 },
  ],
  rotations: [{ id: 7, effective_from: '2026-11-16', period: 'week', anchor_date: '2026-11-16', roles: [1, 3, 4], employees: [11, 12, 99], is_current: true, invalid_people: 1 }],
  overrides: [{ id: 9, employee_id: 12, starts_on: '2026-11-18', ends_on: '2026-11-19', role_id: 4, reason: 'cobertura' }],
  today_roles: { 11: { role_id: 1, source: 'rodizio' }, 12: { role_id: 4, source: 'troca' }, 13: null },
  vacant_today: [3],
  reasons: ['troca', 'cobertura', 'outro'],
}
const EMPLOYEES = [{ id: 11, nome: 'Pessoa Onze', equipe: 'critico' }, { id: 12, nome: 'Pessoa Doze', equipe: 'Crítico' }, { id: 13, nome: 'Pessoa Treze', equipe: 'critico' }, { id: 14, nome: 'Pessoa Arquivada', equipe: 'critico', ativo: false }, { id: 21, nome: 'Pessoa N1', equipe: 'n1' }]
const LIDER = { role: 'lideranca', permissions: ['escalas.manage', 'escalas.retroativa'] }
const GESTOR = { role: 'gestor', permissions: ['operacao.approve'] }
const section = (session, open = false, data = DATA) => {
  globalThis.qaOpenCritical = open
  try {
    return renderToStaticMarkup(h(CriticalRolesSection, { employees: EMPLOYEES, notify: () => {}, resource: { loading: false, error: null, data, refresh: () => {} }, session }))
  } finally {
    delete globalThis.qaOpenCritical
  }
}
{
  const markup = section(LIDER)
  check(() => assert.match(markup, /<section class="card space-y-4" data-critical-roles="">/u, 'seção "Papéis do Crítico" na tela Escala'))
  check(() => assert.match(text(markup), /Pessoa Onze Presencial A 08:00–17:00 Presencial/u, 'papel de hoje pelo rodízio, com a presença'))
  check(() => assert.match(text(markup), /Pessoa Doze Fechamento home 10:30–20:30 Remoto Troca/u, 'troca pontual marcada como Troca (vence o rodízio)'))
  check(() => assert.match(text(markup), /Pessoa Treze Pelo cadastro/u, 'sem papel: pelo cadastro'))
  check(() => assert.doesNotMatch(text(markup), /Pessoa Arquivada|Pessoa N1/u, 'só cadastros ativos do Crítico (com e sem acento)'))
  check(() => assert.match(text(markup), /Rodízio com quem saiu do Crítico 1 pessoa\(s\) do rodízio arquivada\(s\) ou fora do Crítico: salve uma versão nova sem elas\./u, 'aviso de rodízio com quem saiu (9.16.4)'))
  check(() => assert.match(text(markup), /Papel vago hoje Abertura home 07:00–17:00/u, 'papel vago hoje (AD-PC-2)'))
  check(() => assert.match(markup, /data-critical-open=""[^>]*>Papéis e rodízio<\/button>/u, 'Liderança abre o painel para editar'))
  check(() => assert.match(section(GESTOR), /data-critical-open=""[^>]*>Ver papéis<\/button>/u, 'gestor só vê'))
  check(() => assert.match(text(section(LIDER, false, { ...DATA, rotations: [], today_roles: { 11: null, 12: null, 13: null }, vacant_today: [] })), /Nenhum rodízio vigente: a jornada de cada um vem do cadastro\./u, 'sem rodízio: tudo pelo cadastro'))
}
{
  const markup = section(LIDER, true)
  check(() => assert.match(markup, /data-critical-drawer=""/u, 'painel lateral (Drawer) com papéis, rodízio e trocas'))
  check(() => assert.match(text(markup), /Presencial A Presencial 08:00–17:00 Editar/u, 'papéis: lista com presença e jornada'))
  check(() => assert.match(text(markup), /Antigo Desativado/u, 'papel desativado marcado'))
  check(() => assert.match(markup, /data-role-new=""/u, 'Liderança cria papel'))
  check(() => assert.match(text(markup), /16\/11\/2026 Por semana Pessoa Onze, Pessoa Doze, ID 99 1 fora do Crítico/u, 'versões do rodízio com quem saiu marcado'))
  check(() => assert.match(markup, /data-ordered-picker="pessoas"/u, 'pessoas em ordem'))
  check(() => assert.match(markup, /data-ordered-picker="papeis"/u, 'papéis em ordem'))
  check(() => assert.doesNotMatch(markup, /data-picked="99"/u, 'quem saiu do Crítico não volta para a versão nova'))
  check(() => assert.doesNotMatch(markup.slice(markup.indexOf('data-ordered-picker="papeis"')), /Antigo 09:00/u, 'papel desativado fora do rodízio novo'))
  check(() => assert.match(markup, /<button class="btn-primary" data-rotation-save="" disabled=""/u, 'salvar o rodízio só depois da prévia do servidor (9.16.4)'))
  check(() => assert.match(text(markup), /Salvar fica disponível depois da prévia calculada pelo servidor\./u, 'aviso da prévia obrigatória'))
  check(() => assert.match(text(markup), /Pessoa Doze 18\/11\/2026 a 19\/11\/2026 Fechamento home 10:30–20:30 Cobertura de ausência Remover/u, 'trocas: pessoa, período, papel, motivo e remover'))
  check(() => assert.match(text(markup), /Troca entre pessoas Cobertura de ausência Outro motivo/u, 'motivo em lista fechada (sem texto livre)'))
  check(() => assert.doesNotMatch(markup, /<textarea/u, 'nenhum texto livre no painel (LGPD)'))
  const reader = section(GESTOR, true)
  check(() => assert.doesNotMatch(reader, /data-role-new=""|data-rotation-form=""|data-override-form=""|data-role-edit=/u, 'gestor: painel só de leitura'))
}

// 5. Tela Escala e Visão operacional.
const pages = read('src/pages/OperationalPages.jsx')
check(() => assert.match(pages, /const criticalRoles = useResource\('portal\/critical_roles\.php', \{ enabled: canManage \}\)/u, 'Escala: papéis lidos pela gestão'))
check(() => assert.match(pages, /\{canManage && \(\s*<CriticalRolesSection employees=\{employees\.data\?\.funcionarios\} notify=\{notify\} resource=\{criticalRoles\} session=\{session\} \/>/u, 'Escala: a seção depois dos times de rodízio, só para a gestão'))
const dashboard = read('src/pages/DashboardPage.jsx')
check(() => assert.match(dashboard, /\(data\?\.papeis_vagos_critico \|\| \[\]\)\.length > 0 && \(\s*<InlineAlert title="Papel vago no Crítico hoje" tone="warning"><span data-vacant-roles="">\{data\.papeis_vagos_critico\.map\(\(role\) => role\.label\)\.join\(', '\)\}<\/span><\/InlineAlert>/u, 'Visão operacional: alerta de papel vago, com o rótulo do servidor (AD-PC-2)'))
const component = read('src/pages/CriticalRoles.jsx')
check(() => assert.doesNotMatch(component, /style=\{/u, 'sem atributo style (CSP)'))
check(() => assert.equal((component.match(/post\(ENDPOINT, /gu) || []).length, 4, 'quatro chamadas ao endpoint: papel, prévia, rodízio e trocas (incluir e remover)'))

console.log(`QA rodizio de papeis do Critico (frontend) OK: ${checks} verificacoes`)
