// [F10-5] Escala do mês: telas e funções puras, sem rede (JSX transpilado pelo
// TypeScript do projeto e react-dom/server, com os dados fictícios de
// scripts/browser/lib/monthly-fixture.mjs). Confere:
// 1. funções puras de src/lib/monthlySchedule.ts (tons, rótulos, semanas,
//    linhas do RESUMO, status);
// 2. a tela: título, semanas, hoje só no cabeçalho, PA do dia, troca,
//    divergência, feriado marcado, RESUMO; quem só vê x Liderança e admin;
// 3. o Mapa PA com a fonte "escala do mês" (D-F10-40);
// 4. menu, rota e a estética da grade do Crítico (o mesmo CSS, sem style).
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'
import { monthlyPreview, monthlyRead } from './browser/lib/monthly-fixture.mjs'

const frontendRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const srcRoot = join(frontendRoot, 'src')
const srcUrl = pathToFileURL(srcRoot).href + '/'
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')
globalThis.__qaResources = {}
registerHooks({
  resolve(specifier, context, nextResolve) {
    if ((specifier.startsWith('./') || specifier.startsWith('../')) && !/\.[cm]?[jt]sx?$|\.css$/u.test(specifier) && context.parentURL?.startsWith(srcUrl)) {
      for (const extension of ['.ts', '.tsx', '.js', '.jsx']) {
        const candidate = new URL(specifier + extension, context.parentURL)
        if (existsSync(fileURLToPath(candidate))) return nextResolve(candidate.href, context)
      }
    }
    return nextResolve(specifier, context)
  },
  load(url, context, nextLoad) {
    const path = url.split('?')[0]
    if (path.endsWith('.css')) return { format: 'module', source: 'export default {}', shortCircuit: true }
    if (path.startsWith(srcUrl) && /\.(jsx|tsx|ts)$/u.test(path)) {
      let source = readFileSync(fileURLToPath(path), 'utf8')
      if (/\/src\/pages\/(MonthlySchedulePage|PaMapPage)\.jsx$/u.test(path)) {
        source = source.replace(/useResource\(/gu, 'globalThis.__qaResource(')
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})
globalThis.__qaResource = (path) => ({ loading: false, error: null, data: globalThis.__qaResources[path.split('?')[0]] ?? null, refresh: async () => null })

const lib = await import(pathToFileURL(join(srcRoot, 'lib', 'monthlySchedule.ts')).href)
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { MonthlySchedulePage } = await import(pathToFileURL(join(srcRoot, 'pages', 'MonthlySchedulePage.jsx')).href)
const { MonthlyOccupant } = await import(pathToFileURL(join(srcRoot, 'pages', 'PaMapPage.jsx')).href)
const { navigation, routeAllowed } = await import(pathToFileURL(join(srcRoot, 'lib', 'navigation.ts')).href)
const text = (html) => html.replace(/<!-- -->/gu, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()
let checks = 0
const check = (fn) => { fn(); checks++ }

// 1. Funções puras
check(() => assert.deepEqual([lib.cellTone({ situation: 'onsite', no_pa: false }), lib.cellTone({ situation: 'remote', no_pa: false }), lib.cellTone({ situation: 'absent', no_pa: false }), lib.cellTone({ situation: 'onsite', no_pa: true }), lib.cellTone(null)], ['papel-azul', 'papel-verde', 'ausente', 'banco', 'sem'], 'tons da grade do Crítico: presencial, home, ausente, sem PA (âmbar), sem escala'))
check(() => assert.deepEqual([lib.cellLabel({ situation: 'onsite' }), lib.cellLabel(null), lib.cellLabel(null, false), lib.cellDetail({ situation: 'onsite', pa: '1721', no_pa: false }), lib.cellDetail({ situation: 'onsite', pa: null, no_pa: true }), lib.cellDetail({ situation: 'remote', pa: null, no_pa: false })], ['Presencial', 'Sem escala', 'Outra escala', 'PA 1721', 'Sem PA', ''], 'rótulo e detalhe da célula'))
check(() => assert.deepEqual(lib.weeksOf(['2026-11-30', '2026-12-01', '2026-12-04', '2026-12-07']).map((w) => [w.start, w.days.length]), [['2026-11-30', 3], ['2026-12-07', 1]], 'semanas pela segunda'))
check(() => assert.equal(lib.weekLabel({ days: ['2026-12-01', '2026-12-04'] }), 'Semana de 01/12 a 04/12', 'título da semana discreto'))
check(() => assert.deepEqual([lib.statusLabel(['ATENCAO CONFIG', 'PA INSUFICIENTE']), lib.statusAlert(['OK']), lib.statusAlert(['PA INSUFICIENTE'])], ['Atenção config · PA insuficiente', false, true], 'STATUS do script'))
const sample = monthlyRead()
check(() => assert.deepEqual(lib.summaryRows(sample.days.slice(0, 3), sample.summary).map((r) => r.label), ['Presenciais comuns', 'Home comuns', '% presencial', 'N1 presencial', 'N2 presencial', 'PAs do dia', 'Time 1 home', 'Time 2 home', 'Time 3 home', 'Híbrido presencial', 'Crítico presencial', 'Sem PA', 'Trocas', 'Status'], 'RESUMO: os indicadores do script, com home por time'))

// 2. A tela
const render = (data, session) => {
  globalThis.__qaResources['portal/monthly_schedule.php'] = data
  return renderToStaticMarkup(h(MonthlySchedulePage, { notify: () => {}, session }))
}
const tecnico = render(monthlyRead({ manager: false }), { permissions: ['portal.read'] })
const lider = render(monthlyRead(), { permissions: ['portal.read', 'relatorios.read', 'escala_mensal.publish'] })
const admin = render(monthlyRead(), { permissions: ['portal.read', 'relatorios.read', 'escala_mensal.publish', 'escala_mensal.import'] })
const empty = render(monthlyRead({ published: false, manager: false }), { permissions: ['portal.read'] })
check(() => assert.match(tecnico, /<h2 class="section-title esc-title" data-month-title="">Dezembro 2026<\/h2>/u, 'título do mês como os títulos de página'))
check(() => assert.equal((tecnico.match(/<details class="esc-week" data-month-week=/gu) ?? []).length, 5, 'uma semana por bloco, todas abertas'))
check(() => assert.match(tecnico, /<summary><span>Semana de 01\/12 a 04\/12<\/span><\/summary>/u, 'cabeçalho de semana discreto'))
check(() => assert.match(tecnico, /class="esc-grid-head esc-today" role="columnheader">Qua 02\/12</u, 'hoje só no cabeçalho do dia'))
check(() => assert.match(tecnico, /role="columnheader">Sex 25\/12 · feriado</u, 'feriado só marcado (o rodízio conta o dia, D-F10-4 a)'))
check(() => assert.match(tecnico, /class="esc-cell esc-tone-papel-azul[^"]*"[^>]*data-month-cell="2026-12-01"[^>]*><span class="esc-cell-label">Presencial<\/span><span class="esc-cell-detail">PA 17\d\d<\/span>/u, 'célula presencial com o PA do dia'))
check(() => assert.match(tecnico, /aria-label="Ter 08\/12: Presencial, PA \d+, trocou de PA"/u, 'troca de PA com nome para leitor de tela'))
check(() => assert.match(tecnico, /aria-label="Qua 02\/12: [^"]*Ausente depois de publicar \(o PA fica livre\)"/u, 'divergência marcada (D-F10-14)'))
check(() => assert.match(tecnico, /class="esc-cell esc-tone-banco[^"]*"[^>]*data-month-cell="2026-12-10"/u, 'sem PA no âmbar'))
check(() => assert.match(tecnico, /1 célula\(s\) divergem da presença de hoje/u, 'contagem de divergências'))
check(() => assert.match(tecnico, /data-month-status="2026-12-10">PA insuficiente/u, 'RESUMO com o STATUS por dia'))
check(() => assert.doesNotMatch(tecnico, /data-month-preview|data-month-publish|data-month-export|data-month-import-open/u, 'quem só vê: sem prévia, publicar, exportar nem importar'))
check(() => assert.match(lider, /data-month-preview=""[^>]*>Prévia a partir de amanhã</u, 'Liderança: prévia a partir de amanhã no mês publicado'))
check(() => assert.match(lider, /data-month-regen-date="" min="2026-12-03"/u, 'regerar só do próximo dia útil (D-F10-2)'))
check(() => assert.match(lider, /data-month-export="" download="" href="\/api\/portal\/monthly_schedule\.php\?mes=2026-12&amp;formato=xlsx"/u, 'exportar o mês publicado'))
check(() => assert.doesNotMatch(lider, /data-month-import-open/u, 'Liderança não importa a planilha Base'))
check(() => assert.match(admin, /data-month-import-open=""/u, 'admin importa a planilha Base'))
const unpublishedLeader = render(monthlyRead({ published: false }), { permissions: ['portal.read', 'relatorios.read', 'escala_mensal.publish'] })
check(() => assert.deepEqual([/data-month-export/u.test(unpublishedLeader), /data-month-preview=""[^>]*>Gerar prévia</u.test(unpublishedLeader)], [false, true], 'mês sem publicação: gerar prévia, sem exportar'))
check(() => assert.match(empty, /Mês sem escala publicada/u, 'mês sem publicação'))
check(() => assert.doesNotMatch(text(tecnico), /f[ée]rias|folga|categoria/iu, 'sem categoria de ausência'))
const pageSource = read('src/pages/MonthlySchedulePage.jsx')
check(() => assert.match(pageSource, /action: 'publish', mes: data\.mes, from_date: preview\.from_date, expected_revision: preview\.current_revision, input_hash: preview\.input_hash/u, 'publicar manda a revisão esperada e o hash da prévia (409 no servidor)'))
check(() => assert.match(pageSource, /data-month-publish="" disabled=\{busy\} onClick=\{\(\) => setConfirmPublish\(true\)\}/u, 'o botão Publicar só abre a confirmação'))
check(() => assert.match(pageSource, /<ConfirmActionDialog[\s\S]*onConfirm=\{publish\}/u, 'publicar pede confirmação com o resumo de alertas'))
check(() => assert.match(pageSource, /onError: \(\) => \{ setConfirmPublish\(false\); setPreview\(null\) \}/u, '409: a prévia é descartada e precisa ser refeita'))
check(() => assert.doesNotMatch(pageSource, /\sstyle=\{|dangerouslySetInnerHTML|#[0-9a-fA-F]{6}\b/u, 'sem style, sem HTML cru, sem hex (CSP)'))
check(() => assert.match(pageSource, /import '\.\/criticalGrid\/criticalGrid\.css'/u, 'a mesma estética da grade do Crítico (Mesa clara), CSS só da rota'))
check(() => assert.doesNotMatch(read('src/index.css'), /data-month/u, 'nada da tela no CSS principal'))
const preview = monthlyPreview({ mes: '2027-01' })
check(() => assert.deepEqual([preview.current_revision, preview.people.length > 0, preview.input_hash.length], [0, true, 64], 'prévia fictícia: mês novo, com pessoas e hash'))

check(() => assert.match(tecnico, /<div class="esc-grid esc-grid-scroll esc-days-4" role="table" aria-label="Semana de 01\/12 a 04\/12">/u, 'semana com 4 dias úteis: a grade com 4 colunas de dia (sem desalinhar)'))
check(() => assert.match(tecnico, /<div class="esc-grid esc-grid-scroll" role="table" aria-label="Semana de 07\/12 a 11\/12">/u, 'semana cheia: a grade padrão'))
const css = read('src/pages/criticalGrid/criticalGrid.css')
check(() => assert.match(css, /\.esc-grid\.esc-days-4 \{ grid-template-columns: minmax\(8\.5rem, 11rem\) repeat\(4, minmax\(8\.5rem, 1fr\)\); \}/u, 'CSS: colunas para semana curta'))
check(() => assert.match(css, /@media \(max-width: 767px\) \{\n  \.esc-grid\.esc-grid-scroll \{ display: grid;[\s\S]*position: sticky; left: 0;/u, 'celular: a grade do mês rola no bloco com o nome fixo (a do Crítico segue nos cartões)'))
check(() => assert.deepEqual([sample.summary['2026-12-01'].n1 + sample.summary['2026-12-01'].n2, sample.summary['2026-12-01'].onsite], [sample.summary['2026-12-01'].onsite, sample.summary['2026-12-01'].onsite], 'servidor simulado: N1 + N2 = presenciais comuns'))

// 3. Mapa PA com a fonte do mês
check(() => assert.equal(text(renderToStaticMarkup(h(MonthlyOccupant, { occupant: { employee_id: 801, employee_name: 'Alice Ficticia', absent_now: false } }))), 'Escala do mês: Alice Ficticia', 'Mapa PA: quem a escala do mês senta no PA'))
check(() => assert.equal(text(renderToStaticMarkup(h(MonthlyOccupant, { occupant: { employee_id: 801, employee_name: 'Alice Ficticia', absent_now: true } }))), 'Escala do mês: livre (ausência)', 'Mapa PA: ausência depois de publicar deixa o PA livre'))
check(() => assert.equal(text(renderToStaticMarkup(h(MonthlyOccupant, { occupant: null }))), 'Escala do mês: livre', 'Mapa PA: PA livre no mês'))
check(() => assert.match(read('src/pages/PaMapPage.jsx'), /\{'monthly_occupant' in pa && <MonthlyOccupant occupant=\{pa\.monthly_occupant\} \/>\}/u, 'Mapa PA: a linha só com o mês publicado'))

// 4. Menu e rota
const route = navigation.find((item) => item.path === '/escala-mes')
check(() => assert.deepEqual([route?.label, routeAllowed(route, new Set([]))], ['Escala do mês', true], 'Escala do mês no menu Escalas, para todos'))
check(() => assert.match(read('src/App.jsx'), /\} else if \(router\.path === '\/escala-mes'\) \{\s+content = <MonthlySchedulePage notify=\{notify\} session=\{session\} \/>/u, 'rota no App'))

console.log(`QA da escala do mes (frontend) OK: ${checks} verificacoes`)
