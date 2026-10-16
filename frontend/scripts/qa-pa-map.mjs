import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

// F6, commit 3 (desenho 5.6 e 9.8): tela do Mapa PA pelo motor. Regras puras
// (src/lib/paMap.ts), os componentes renderizados de verdade (o JSX é
// transpilado pelo TypeScript do projeto, sem dependência nova, e renderizado
// com react-dom/server), verificações de fonte e o CSS gerado pelo Tailwind.

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')

// .jsx, .ts e .tsx do src transpilados na carga; import sem extensão resolve
// como o Vite (.ts, .tsx, .js, .jsx).
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
      const source = readFileSync(fileURLToPath(url), 'utf8')
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(url) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const paMap = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'paMap.ts')).href)
const page = await import(pathToFileURL(join(frontendRoot, 'src', 'pages', 'PaMapPage.jsx')).href)
const { localDate } = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'operational.ts')).href)

// ---------------------------------------------------------------------------
// 1. Regras puras
// ---------------------------------------------------------------------------
assert.deepEqual(paMap.weekOf('2026-11-18'), { from: '2026-11-16', to: '2026-11-20' }, 'semana de segunda a sexta da data')
assert.deepEqual(paMap.weekOf('2026-11-22'), { from: '2026-11-16', to: '2026-11-20' }, 'domingo: a semana que termina nele (como o servidor)')
assert.deepEqual(paMap.weekOf('2026-11-16'), { from: '2026-11-16', to: '2026-11-20' }, 'segunda: a própria semana')
assert.equal(paMap.weekOf('18/11/2026'), null, 'data fora do formato: sem semana')
assert.deepEqual(paMap.weekDays(null, '2026-11-18').map((day) => day.label), ['seg 16', 'ter 17', 'qua 18', 'qui 19', 'sex 20'], 'seletor de seg a sex, calculado da data enquanto a resposta não chega')
assert.deepEqual(paMap.weekDays({ from: '2026-10-19', to: '2026-10-23' }, '2026-11-18').map((day) => day.date), ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'], 'seletor pela semana do servidor quando vem')
assert.equal(paMap.conflictDaysLabel(['2026-11-16', '2026-11-18', 'x']), 'seg 16, qua 18', 'dias do conflito por extenso curto')
assert.equal(paMap.brDate('2026-11-05'), '05/11/2026')
assert.deepEqual(['inativo', 'arquivado', 'fim_de_semana', 'sem_situacao', undefined].map(paMap.presenceReasonLabel), ['Inativo', 'Arquivado', 'Fim de semana', 'Sem situação', 'Sem situação'], 'motivo sem situação (DF6)')
assert.deepEqual([
  paMap.absencePeriodLabel({ period_type: 'full_day' }),
  paMap.absencePeriodLabel({ period_type: 'from_time', start_time: '14:00:00' }),
  paMap.absencePeriodLabel({ period_type: 'interval', start_time: '09:00:00', end_time: '10:30:00' }),
], ['Dia todo', 'A partir de 14:00', '09:00–10:30'], 'período da ausência')
assert.deepEqual([paMap.absenceOriginLabel('ausencia'), paMap.absenceOriginLabel('excecao')], ['Ausência', 'Exceção de escala'])
assert.deepEqual([paMap.showsCategory([{ category: 'Férias' }]), paMap.showsCategory([{}, { category: '' }]), paMap.showsCategory(null)], [true, false, false], 'coluna Categoria só quando o servidor manda (P9)')
// Retorno do save (P13): salvou sempre; aviso e conflito por dia.
assert.deepEqual(paMap.saveFeedback({ warnings: [], conflicts: [], conflicts_more: 0 }, 5), { tone: 'success', title: 'Vínculo salvo', lines: [] }, 'save sem aviso')
assert.deepEqual(paMap.saveFeedback({ warnings: ['Colaborador em trabalho remoto em 19/10/2026, início do vínculo.'] }, 5), { tone: 'warning', title: 'Vínculo salvo com aviso', lines: ['Colaborador em trabalho remoto em 19/10/2026, início do vínculo.'] }, 'save com aviso do servidor')
const conflictResult = {
  warnings: [],
  conflicts: [
    { pa_number: '1730', date: '2026-10-19', employee_ids: [5, 7] },
    { pa_number: '1730', date: '2026-10-21', employee_ids: [5, 7, 9] },
  ],
  conflicts_more: 12,
}
assert.deepEqual(paMap.saveFeedback(conflictResult, '5', new Map([['5', 'Técnico A'], ['7', 'Técnico B'], ['9', 'Técnico C']])), {
  tone: 'warning',
  title: 'Vínculo salvo com conflito',
  lines: ['Conflito no PA 1730 em 14 dias: 19/10, 21/10 e mais 12.', 'Presencial no mesmo PA: Técnico B, Técnico C.'],
}, 'save com conflito: dias, quantos a mais e os outros técnicos pelo nome que a tela já tem')
assert.deepEqual(paMap.saveFeedback({ conflicts: [{ pa_number: '1730', date: '2026-10-19', employee_ids: [5, 7] }] }, 5).lines, ['Conflito no PA 1730 em 1 dia: 19/10.'], 'conflito de um dia, sem nome desconhecido')

// L-03: data local, não UTC (21:00 em São Paulo ainda é o mesmo dia).
{
  const late = new Date(2026, 9, 15, 23, 30)
  assert.equal(localDate(late), '2026-10-15', 'data local às 23:30 é o próprio dia')
}

// ---------------------------------------------------------------------------
// 2. Componentes renderizados
// ---------------------------------------------------------------------------
const render = (component, props) => renderToStaticMarkup(h(component, props))
const assignment = (overrides) => ({
  id: 1, source: 'assignment', pa_number: '1730', employee_id: 5, employee_name: 'Técnico A', team: 'n1',
  schedule_rule_label: 'Sempre presencial', valid_from: '2026-09-01', valid_until: null,
  presence: { situacao: 'presencial', origem: 'regra_escala', detalhe: 'Presencial (sempre presencial)', status: 'onsite' },
  ...overrides,
})
const chips = {
  onsite: assignment({}),
  remote: assignment({ presence: { situacao: 'remoto', origem: 'regra_escala', detalhe: 'Remoto', status: 'remote' } }),
  absence: assignment({ presence: { situacao: 'ausente', origem: 'ausencia', detalhe: 'Ausente (dia todo)', status: 'absence' } }),
  no_schedule: assignment({ presence: { situacao: 'sem_escala', origem: 'regra_escala', detalhe: 'Sem escala definida', status: 'no_schedule' } }),
}
const tones = { onsite: 'presence-onsite', remote: 'presence-remote', absence: 'presence-absence', no_schedule: 'presence-no-schedule' }
const labels = { onsite: 'Presencial', remote: 'Remoto', absence: 'Ausente', no_schedule: 'Sem escala' }
for (const [status, item] of Object.entries(chips)) {
  const html = render(page.AssignmentChip, { item })
  assert.match(html, new RegExp(`class="[^"]*\\b${tones[status]}\\b`), `cartão do técnico ${status}: cor da legenda única`)
  assert.match(html, new RegExp(`data-presence="${status}"`), `cartão do técnico ${status}: situação marcada`)
  assert.match(html, new RegExp(`>${labels[status]}</span>`), `cartão do técnico ${status}: rótulo, não só cor`)
  assert.match(html, /<svg/, `cartão do técnico ${status}: ícone`)
}
const partialChip = render(page.AssignmentChip, { item: assignment({ presence: { ...chips.onsite.presence, detalhe: 'Presencial · Ausente a partir de 14:00', parcial: 'a partir de 14:00' } }) })
assert.match(partialChip, /aria-label="Ausência parcial a partir de 14:00"/, 'marcador da parcial com o horário')
assert.doesNotMatch(render(page.AssignmentChip, { item: chips.onsite }), /Ausência parcial/, 'sem marcador sem parcial')
const weekendChip = render(page.AssignmentChip, { item: assignment({ presence: null, presence_reason: 'fim_de_semana' }) })
assert.match(weekendChip, /data-presence="none"/, 'sem dia no motor: sem situação')
assert.match(weekendChip, />Fim de semana<\/span>/, 'sem dia no motor: o motivo do servidor')

const conflictPa = { pa_number: '1730', has_conflict: true, conflict_days: ['2026-10-19', '2026-10-21'], assignments: [chips.onsite, chips.absence] }
const conflictCard = render(page.PaCard, { pa: conflictPa, canManage: false, onOpen: () => {} })
assert.match(conflictCard, /data-conflict="true"/, 'PA em conflito marcado')
assert.match(conflictCard, /class="pa-cell [^"]*ring-2 ring-red-400\/60/, 'PA em conflito com contorno (D6)')
assert.match(conflictCard, /Conflito: seg 19, qua 21/, 'PA em conflito com os dias')
assert.match(conflictCard, /aria-label="PA 1730, conflito: seg 19, qua 21"/, 'conflito no nome acessível, não só na cor')
assert.match(conflictCard, /presence-absence/, 'técnico ausente preenchido na cor da legenda dentro do PA em conflito (níveis diferentes, D6)')
const freeCard = render(page.PaCard, { pa: { pa_number: '1729', has_conflict: false, conflict_days: [], assignments: [chips.onsite, chips.remote] }, canManage: false, onOpen: () => {} })
assert.match(freeCard, /data-conflict="false"/)
assert.doesNotMatch(freeCard, /ring-red|Conflito/, 'PA sem conflito sem contorno')
assert.match(freeCard, /1\/2 presencial/, 'contagem de presenciais pela situação do motor')

const managerItem = assignment({ notes: 'nota do vínculo', employee_login: 'qa.login' })
const readerItem = assignment({ presence: { situacao: 'ausente', origem: 'ausencia', detalhe: 'Ausente (dia todo)', status: 'absence', categoria: 'Férias' } })
const modalProps = { pa: { ...conflictPa, assignments: [readerItem] }, date: '2026-10-19', employees: [], feedback: null, onClose: () => {}, onSave: () => {}, onRemove: () => {}, saving: false }
const readerModal = render(page.PaModal, { ...modalProps, canManage: false })
assert.doesNotMatch(readerModal, /<select|<textarea|Salvar vínculo|Remover|Editar/, 'gestor, técnico e somente leitura: sem formulário nem ações')
assert.match(readerModal, /Seu perfil permite apenas visualizar o mapa\./)
assert.match(readerModal, /Conflito na semana/, 'modal mostra o conflito do PA')
assert.match(readerModal, /Ausente \(dia todo\)/, 'modal com o detalhe do motor')
assert.match(readerModal, /Categoria: Férias/, 'modal com a categoria quando vem (P9)')
assert.doesNotMatch(render(page.AssignmentList, { assignments: [assignment({})], canManage: false, onEdit: () => {}, onRemove: () => {}, saving: false }), /Categoria/, 'sem categoria quando não vem')
assert.doesNotMatch(readerModal, /qa\.login|nota do vínculo|login/i, 'sem login nem observação na tela de quem não edita (DF7)')
const managerModal = render(page.PaModal, { ...modalProps, pa: { ...conflictPa, assignments: [managerItem] }, canManage: true, employees: [{ id: 9, name: 'Técnico C', team: 'n2', schedule_rule_label: 'Dias pares' }] })
assert.match(managerModal, /<select/, 'quem edita: formulário')
assert.match(managerModal, /Técnico C - N2 \(Dias pares\)/)
assert.match(managerModal, /nota do vínculo/, 'quem edita vê a observação')
assert.doesNotMatch(managerModal, /qa\.login/, 'login do vínculo não aparece nem para quem edita')
assert.match(managerModal, /não impedem o vínculo/, 'formulário explica que nada bloqueia (P13)')
const feedbackModal = render(page.PaModal, { ...modalProps, canManage: true, feedback: paMap.saveFeedback(conflictResult, 5, new Map([['7', 'Técnico B']])) })
assert.match(feedbackModal, /Vínculo salvo com conflito/, 'aviso do save no modal')
assert.match(feedbackModal, /Conflito no PA 1730 em 14 dias: 19\/10, 21\/10 e mais 12\./, 'aviso com os dias e quantos a mais')

const absences = [
  { employee_id: 4, employee_name: 'Técnico D', team: 'n1', date: '2026-10-20', origem: 'ausencia', period_type: 'full_day', start_time: null, end_time: null },
  { employee_id: 4, employee_name: 'Técnico D', team: 'n1', date: '2026-10-22', origem: 'excecao', period_type: 'from_time', start_time: '14:00:00', end_time: null },
]
const plainTable = render(page.AbsencesTable, { items: absences })
assert.doesNotMatch(plainTable, /Categoria/, 'aba de ausências sem a coluna Categoria quando não vem')
assert.match(plainTable, /20\/10\/2026.*Dia todo.*Ausência.*22\/10\/2026.*A partir de 14:00.*Exceção de escala/s, 'ausências por data, período e origem')
const categoryTable = render(page.AbsencesTable, { items: [{ ...absences[0], category: 'Férias' }, absences[1]] })
assert.match(categoryTable, /<th>Categoria<\/th>/, 'coluna Categoria quando vem')
assert.match(categoryTable, /Férias.*—/s, 'categoria do item que a traz; traço no que não traz')
assert.match(render(page.AbsencesTable, { items: [] }), /Nenhuma ausência na semana/)
for (const tab of ['map', 'absences']) {
  const tabs = render(page.MapTabs, { tab, absences: 3, onSelect: () => {} })
  assert.match(tabs, /role="tablist"/)
  assert.match(tabs, new RegExp(`aria-selected="true"[^>]*>${tab === 'map' ? 'Mapa' : 'Ausências da semana \\(3\\)'}<`), `aba ${tab} marcada`)
  assert.equal((tabs.match(/aria-selected="true"/g) || []).length, 1, `só a aba ${tab} marcada`)
}
// Cartão de permissão: quem não edita (gestor, técnico e o perfil somente
// leitura) não lê "somente leitura", que é o nome de um perfil só.
const managerPermission = render(page.PermissionCard, { canManage: true })
assert.match(managerPermission, /metric-card-warning/)
assert.match(managerPermission, /Edição.*Admin\/Liderança podem editar/s, 'quem edita: Admin/Liderança')
const readerPermission = render(page.PermissionCard, { canManage: false })
assert.match(readerPermission, /metric-card-info/)
assert.match(readerPermission, /Leitura.*Visualização: só Admin e Liderança editam o mapa/s, 'quem não edita: quem edita o mapa')
assert.doesNotMatch(readerPermission, /somente leitura/i, 'sem "somente leitura" (confunde com o perfil)')
const selector = render(page.DaySelector,{ days: paMap.weekDays(null, '2026-10-21'), date: '2026-10-21', onSelect: () => {} })
assert.equal((selector.match(/<button/g) || []).length, 5, 'seletor com cinco dias')
assert.match(selector, /aria-pressed="true"[^>]*>qua 21</, 'dia selecionado marcado')

// ---------------------------------------------------------------------------
// 3. Fonte da tela
// ---------------------------------------------------------------------------
const source = read('src/pages/PaMapPage.jsx')
assert.doesNotMatch(source, /window\.confirm|confirm\(/, 'sem window.confirm (P13)')
assert.doesNotMatch(source, /confirm_remote_allocation/, 'sem confirmação de remoto em dois passos')
assert.doesNotMatch(source, /error\.message\.includes/, 'sem decisão pelo texto da mensagem')
assert.doesNotMatch(source, /toISOString/, 'sem data padrão em UTC (L-03)')
assert.match(source, /useState\(\{ date: localDate\(\), team: '' \}\)/, 'data padrão local (L-03)')
assert.doesNotMatch(source, /ruleTone|OperationalLegend|Regra par\/ímpar/, 'sem a legenda por tipo de regra')
assert.match(source, /<PresenceLegend \/>/, 'legenda única do F5')
assert.match(source, /Admin\/Liderança podem editar/, 'texto de quem edita')
assert.match(source, /<PermissionCard canManage=\{canManage\} \/>/, 'cartão de permissão na página')
assert.doesNotMatch(source, /Seu perfil é somente leitura/, 'sem o texto antigo do cartão de permissão')
assert.doesNotMatch(source, /Admin\/Gestor/, 'sem o texto antigo')
assert.doesNotMatch(source, /ad_login|employee_login/, 'a tela não mostra login (DF7)')
assert.match(source, /<MapTabs absences=\{absences\.length\} onSelect=\{setTab\} tab=\{tab\} \/>/, 'abas Mapa e Ausências da semana na página')
assert.match(source, /Ausências da semana/)
assert.match(source, /saveFeedback\(result, form\.employee_id, names\)/, 'aviso do save pelo conflicts e pelos warnings do servidor')
assert.match(source, /pas\.find\(\(pa\) => pa\.pa_number === selectedPa\)/, 'modal lê o PA da última resposta (lista e conflito atualizados na recarga)')

// ---------------------------------------------------------------------------
// 4. CSS gerado: as classes da tela existem (Tailwind descarta o que não vê).
// ---------------------------------------------------------------------------
{
  const { default: postcss } = await import('postcss')
  const { default: tailwindcss } = await import('tailwindcss')
  const { default: tailwindConfig } = await import(pathToFileURL(join(frontendRoot, 'tailwind.config.js')).href)
  const compiled = (await postcss([tailwindcss({ ...tailwindConfig, content: tailwindConfig.content.map((glob) => join(frontendRoot, glob).replaceAll('\\', '/')) })]).process(read('src/index.css'), { from: join(frontendRoot, 'src', 'index.css') })).css
  // Os tons metric-card-* e inline-alert-* (montados por template no
  // Primitives) são conferidos no qa:calendar-presence, com os das outras telas.
  // No CSS gerado, a barra do modificador de opacidade vem escapada: .ring-red-400\/60.
  for (const name of ['presence-onsite', 'presence-remote', 'presence-absence', 'presence-no-schedule', 'pa-cell', 'data-table', 'status-neutral', 'ring-red-400/60', 'border-red-400/80', 'bg-red-500/15', 'ring-2']) {
    assert.match(compiled, new RegExp(`\\.${name.split('/').join(String.raw`\\\/`)}[\\s,{:]`), `CSS gerado com .${name}`)
  }
}

console.log('PA map QA OK')
