// [Escala do Crítico em grade, D-ESC-1 a D-ESC-20] Telas e funções puras,
// sem rede (JSX transpilado pelo TypeScript do projeto e react-dom/server,
// com os dados fictícios de scripts/browser/lib/critical-grid-fixture.mjs).
// Confere:
// 1. funções puras de src/lib/criticalGrid.ts (cores, rótulos, teclado,
//    seleção de dias, versões);
// 2. a paleta: a mesma do servidor, os dois temas escuros iguais e o
//    contraste do texto (>= 4,5:1) de cada tom nos dois temas; CSS só da rota;
// 3. a tela: semanas, semana atual aberta, hoje destacado, legenda, ícones,
//    resumo, cartões do celular, exportar; quem só vê x Liderança;
// 4. o diálogo do dia: leitura, modos, desfazer só com o registro, salvar só
//    depois da prévia, proteção de alteração não salva e as versões no apply;
// 5. Configurações com os feriados (Liderança só os feriados); menu e rota.
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'
import { contrast } from './lib/contrast.mjs'
import { criticalGridFixture, criticalGridPreview } from './browser/lib/critical-grid-fixture.mjs'

const frontendRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const projectRoot = join(frontendRoot, '..')
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
      if (/\/src\/pages\/(CriticalGridPage|HolidaysPanel)\.jsx$/u.test(path)) {
        source = source.replace(/useResource\(/gu, 'globalThis.__qaResource(')
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})
globalThis.__qaResource = (path) => ({ loading: false, error: null, data: globalThis.__qaResources[path.split('?')[0]] ?? null, refresh: async () => null })

const lib = await import(pathToFileURL(join(srcRoot, 'lib', 'criticalGrid.ts')).href)
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { CriticalGridPage } = await import(pathToFileURL(join(srcRoot, 'pages', 'CriticalGridPage.jsx')).href)
const { DayDialog, DayReadout } = await import(pathToFileURL(join(srcRoot, 'pages', 'criticalGrid', 'DayDialog.jsx')).href)
const { SettingsPage } = await import(pathToFileURL(join(srcRoot, 'pages', 'ModulePage.jsx')).href)
const { navigation, routeAllowed } = await import(pathToFileURL(join(srcRoot, 'lib', 'navigation.ts')).href)
const text = (html) => html.replace(/<!-- -->/gu, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()
let checks = 0
const check = (fn) => { fn(); checks++ }

// ---------------------------------------------------------------------------
// 1. Funções puras
// ---------------------------------------------------------------------------
check(() => assert.deepEqual([lib.toneClass('papel-azul'), lib.toneClass('banco'), lib.toneClass('x" onclick="'), lib.toneClass(undefined)], ['esc-tone-papel-azul', 'esc-tone-banco', 'esc-tone-sem', 'esc-tone-sem'], 'cor: só tons conhecidos viram classe (nada solto no class)'))
check(() => assert.deepEqual([lib.colorClass('verde'), lib.colorClass(null), lib.colorClass('#ff0000')], ['esc-tone-papel-verde', 'esc-tone-papel-areia', 'esc-tone-papel-areia'], 'cor do papel: sem cor ou fora da paleta = areia'))
check(() => assert.deepEqual([lib.dayHeader('2026-11-16'), lib.dayHeader('2026-11-20'), lib.dayLabel('2026-11-19')], ['Seg (16/11)', 'Sex (20/11)', 'Qui 19/11'], 'cabeçalho do dia e rótulo curto'))
check(() => assert.equal(lib.weekTitle({ iso: 47, start: '2026-11-16' }), 'Semana 47 · 16/11 a 20/11', 'título da semana discreto (seg a sex)'))
check(() => assert.deepEqual(lib.slotsSummary([1, 1, 2, 3, 4], new Map([[1, 'Presencial'], [2, 'Abertura home'], [3, 'Fechamento home'], [4, 'Home']])), ['Presencial x2', 'Abertura home', 'Fechamento home', 'Home'], 'vagas: "Presencial x2" (D-ESC-15)'))
check(() => assert.deepEqual([lib.removeAt([1, 1, 2], 0), lib.removeAt([1, 1, 2], 2)], [[1, 2], [1, 1]], 'vagas: tirar pela posição (a outra vaga do mesmo papel fica)'))
check(() => assert.deepEqual([lib.moveFocus(0, 0, 'ArrowRight', 5), lib.moveFocus(0, 4, 'ArrowRight', 5), lib.moveFocus(0, 2, 'ArrowDown', 5), lib.moveFocus(4, 2, 'ArrowDown', 5), lib.moveFocus(2, 3, 'Home', 5), lib.moveFocus(2, 1, 'End', 5), lib.moveFocus(1, 1, 'ArrowUp', 5), lib.moveFocus(1, 1, 'ArrowLeft', 5), lib.moveFocus(1, 1, 'Tab', 5)], [[0, 1], [0, 4], [1, 2], [4, 2], [2, 0], [2, 4], [0, 1], [1, 0], [1, 1]], 'teclado: setas entre as células, sem sair da semana'))
check(() => assert.deepEqual([
  lib.extendSelection(null, 901, '2026-11-18'),
  lib.extendSelection({ employeeId: 901, from: '2026-11-18', to: '2026-11-18' }, 901, '2026-11-20'),
  lib.extendSelection({ employeeId: 901, from: '2026-11-18', to: '2026-11-18' }, 901, '2026-11-16'),
  lib.extendSelection({ employeeId: 901, from: '2026-11-18', to: '2026-11-20' }, 902, '2026-11-19'),
], [
  { employeeId: 901, from: '2026-11-18', to: '2026-11-18' },
  { employeeId: 901, from: '2026-11-18', to: '2026-11-20' },
  { employeeId: 901, from: '2026-11-16', to: '2026-11-18' },
  { employeeId: 902, from: '2026-11-19', to: '2026-11-19' },
], 'seleção: Shift+clique ou arrastar na linha; outra pessoa recomeça (D-ESC-12)'))
check(() => assert.deepEqual([lib.inSelection({ employeeId: 901, from: '2026-11-16', to: '2026-11-18' }, 901, '2026-11-17'), lib.inSelection({ employeeId: 901, from: '2026-11-16', to: '2026-11-18' }, 902, '2026-11-17'), lib.inSelection(null, 901, '2026-11-17')], [true, false, false], 'seleção: só os dias da pessoa'))
check(() => assert.deepEqual([lib.datesBetween('2026-11-30', '2026-12-02'), lib.datesBetween('2026-11-02', '2027-02-01').length], [['2026-11-30', '2026-12-01', '2026-12-02'], 62], 'dias do intervalo, com teto'))
const fixture = criticalGridFixture({ manager: true })
check(() => assert.deepEqual(lib.versionsFor(fixture.cells, 901, '2026-11-18', '2026-11-19'), { '2026-11-18': 'v1-901-2026-11-18', '2026-11-19': 'v1-901-2026-11-19' }, 'versões dos dias do intervalo, da grade carregada (D-ESC-6)'))
check(() => assert.deepEqual([lib.changeLabel({ by: 'qa.lider', at: '2026-11-12 10:05:00' }), lib.changeLabel({ by: 'qa', at: null }), lib.changeLabel(null)], ['qa.lider em 12/11/2026 10:05', 'qa', '—'], 'quem alterou por último'))

// ---------------------------------------------------------------------------
// 2. Paleta, contraste e CSS só da rota
// ---------------------------------------------------------------------------
const css = read('src/pages/criticalGrid/criticalGrid.css').replace(/\/\*[\s\S]*?\*\//gu, '')
const block = (pattern) => {
  const body = css.match(pattern)?.[1] ?? ''
  return Object.fromEntries([...body.matchAll(/--esc-([a-z-]+):\s*(\d+) (\d+) (\d+);/gu)].map(([, name, r, g, b]) => [name, [Number(r), Number(g), Number(b)]]))
}
const light = block(/(?:^|\n):root \{([^}]*)\}/u)
const darkSystem = block(/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme='light'\]\) \{([^}]*)\}/u)
const darkChosen = block(/(?:^|\n):root\[data-theme='dark'\] \{([^}]*)\}/u)
// [2026-11-25, "Mesa clara"] Cada tom só define a cor do papel (a faixa); a
// célula é a superfície do portal com --esc-tint dessa cor, e o texto é a
// tinta do portal. O contraste é medido no fundo misturado, com os tokens reais
// do index.css (superfície, ink, ink-2 e muted) nos dois temas.
const tones = [...lib.COLOR_TOKENS.map((token) => `papel-${token}`), ...lib.FIXED_TONES.map((item) => item.tone), 'sem']
const tint = (pattern) => Number(css.match(pattern)?.[1] ?? NaN)
const tintLight = tint(/(?:^|\n):root \{[^}]*--esc-tint: ([\d.]+);/u)
const tintDark = tint(/(?:^|\n):root\[data-theme='dark'\] \{[^}]*--esc-tint: ([\d.]+);/u)
const tintDarkSystem = tint(/:root:not\(\[data-theme='light'\]\) \{[^}]*--esc-tint: ([\d.]+);/u)
const portalCss = read('src/index.css').replace(/\/\*[\s\S]*?\*\//gu, '')
const portalBlock = (pattern) => Object.fromEntries([...(portalCss.match(pattern)?.[1] ?? '').matchAll(/--([a-z0-9-]+):\s*(\d+) (\d+) (\d+);/gu)].map(([, name, r, g, b]) => [name, [Number(r), Number(g), Number(b)]]))
const portalLight = portalBlock(/(?:^|\n):root \{\s*color-scheme: light;([^}]*)\}/u)
const portalDark = portalBlock(/(?:^|\n):root\[data-theme='dark'\] \{([^}]*)\}/u)
const mix = (base, color, alpha) => base.map((channel, index) => Math.round(channel * (1 - alpha) + color[index] * alpha))
check(() => assert.deepEqual(darkSystem, darkChosen, 'tema escuro pelo sistema e pelo botão iguais'))
check(() => assert.deepEqual([Object.keys(light).sort(), tintLight, tintDark, tintDarkSystem], [tones.sort(), 0.12, 0.22, 0.22], 'uma cor por tom; tinta de 12% no claro e 22% no escuro (sistema e botão)'))
check(() => assert.ok(['surface', 'ink', 'ink-2', 'muted'].every((name) => portalLight[name] && portalDark[name]), 'tokens do portal lidos nos dois temas'))
for (const [name, theme, portal, alpha] of [['claro', light, portalLight, tintLight], ['escuro', darkChosen, portalDark, tintDark]]) {
  for (const tone of tones) {
    // Pior caso: o feriado leva a hachura (mais 16% da cor nos fios).
    const background = tone === 'feriado' ? mix(mix(portal.surface, theme[tone], alpha), theme[tone], 0.16) : mix(portal.surface, theme[tone], alpha)
    const label = ['ausente', 'sem'].includes(tone) ? 'muted' : 'ink'
    for (const [text, role] of [[label, 'rótulo'], ['ink-2', 'detalhe']]) {
      check(() => {
        const ratio = contrast(portal[text], background)
        assert.ok(ratio >= 4.5, `${name}: ${role} (${text}) no tom ${tone} com ${ratio.toFixed(2)}:1 (mínimo 4,5:1)`)
      })
    }
  }
}
// Nada do preenchimento forte da planilha: tinta suave (até 25%), sem o
// amarelo neon do banco de horas nem a faixa azul do título e das semanas.
check(() => assert.ok(tintLight >= 0.1 && tintLight <= 0.15 && tintDark >= 0.18 && tintDark <= 0.25, 'tinta suave: 10-15% no claro, 18-25% no escuro'))
check(() => assert.doesNotMatch(css, /255 255 0|--esc-(title|week|day)-bg|--esc-bg:|--esc-ink:/u, 'sem o amarelo neon, sem faixa cheia de título/semana/dia e sem fundo ou texto colorido por tom'))
check(() => assert.match(css, /\.esc-cell, \.esc-chip \{\n  background: linear-gradient\(rgb\(var\(--esc-stripe\) \/ var\(--esc-tint\)\), rgb\(var\(--esc-stripe\) \/ var\(--esc-tint\)\)\), rgb\(var\(--surface\)\);\n  box-shadow: inset 3px 0 0 rgb\(var\(--esc-stripe\)\);\n  color: rgb\(var\(--ink\)\);\n\}/u, 'célula: superfície com a tinta do papel, faixa de 3px à esquerda e texto na tinta do portal'))
check(() => assert.match(css, /\.esc-cell\.esc-today \{ outline: 2px solid rgb\(var\(--action\)\); outline-offset: -2px; \}/u, 'hoje: contorno fino (2px) na cor de ação'))
check(() => assert.match(css, /\.esc-cell\.esc-tone-feriado, \.esc-chip\.esc-tone-feriado \{\n  background:\n    repeating-linear-gradient\(135deg,/u, 'feriado com hachura diagonal leve'))
check(() => assert.match(css, /\.esc-legend-item::before \{ content: ''; width: 0\.6rem; height: 0\.6rem; border-radius: 999px; background: rgb\(var\(--esc-stripe\)\); \}/u, 'legenda: ponto de cor com o rótulo'))
check(() => assert.match(css, /\.esc-summary tbody tr:nth-child\(even\) \{ background: rgb\(var\(--surface-2\) \/ 0\.5\); \}/u, 'resumo com zebra suave'))
check(() => assert.match(css, /\.esc-week > summary \{[^}]*color: rgb\(var\(--ink-2\)\); border-bottom: 1px solid rgb\(var\(--line\)\); \}/u, 'semana: cabeçalho discreto em ink-2 com fio fino'))
const planner = readFileSync(join(projectRoot, 'services', 'CriticalRolePlanner.php'), 'utf8')
check(() => assert.deepEqual([...lib.COLOR_TOKENS], JSON.parse(`[${planner.match(/public const COLORS = \[([^\]]*)\];/u)[1].replaceAll("'", '"')}]`), 'paleta da tela = paleta do servidor (D-ESC-13)'))
for (const tone of tones) {
  check(() => assert.match(css, new RegExp(`\\.esc-tone-${tone} \\{ --esc-stripe: var\\(--esc-${tone}\\); \\}`, 'u'), `classe do tom ${tone}`))
}
const newSources = ['src/pages/CriticalGridPage.jsx', 'src/pages/criticalGrid/DayDialog.jsx', 'src/pages/criticalGrid/RoleColor.jsx', 'src/pages/HolidaysPanel.jsx'].map(read).join('\n')
check(() => assert.doesNotMatch(newSources, /\sstyle=\{|dangerouslySetInnerHTML|#[0-9a-fA-F]{6}\b/u, 'sem atributo style, sem HTML cru e sem hex nas telas novas (CSP)'))
check(() => assert.doesNotMatch(read('src/index.css'), /esc-tone|esc-grid/u, 'CSS da Escala do Crítico fora do CSS principal'))
check(() => assert.deepEqual(['src/pages/CriticalGridPage.jsx', 'src/pages/criticalGrid/RoleColor.jsx'].map((path) => read(path).includes("criticalGrid.css'")), [true, true], 'o CSS da grade só na rota e na amostra de cor carregada sob demanda'))
check(() => assert.match(read('src/pages/CriticalRoles.jsx'), /const RoleColorPicker = lazy\(\(\) => import\('\.\/criticalGrid\/RoleColor'\)/u, 'tela de papéis carrega a cor sob demanda (CSS fora do principal)'))

// ---------------------------------------------------------------------------
// 3. A tela, por perfil
// ---------------------------------------------------------------------------
const render = (data) => {
  globalThis.__qaResources['portal/critical_grid.php'] = data
  return renderToStaticMarkup(h(CriticalGridPage, { notify: () => {} }))
}
const viewer = render(criticalGridFixture({ manager: false }))
const pageSource = read('src/pages/CriticalGridPage.jsx')
const leader = render(criticalGridFixture({ manager: true }))
check(() => assert.equal((viewer.match(/<details class="esc-week/gu) ?? []).length, 5, 'um bloco por semana'))
check(() => assert.match(viewer, /<details class="esc-week esc-week-current" data-esc-week="47" open="">/u, 'a semana atual aberta e destacada'))
// [Achado das capturas, 2026-11-25] No Mês, todas as semanas abertas, como na planilha.
check(() => assert.equal((viewer.match(/<details class="esc-week[^"]*" data-esc-week="\d+" open="">/gu) ?? []).length, 5, 'Mês: todas as semanas abertas por padrão'))
check(() => assert.match(viewer, /<button aria-expanded="true" class="btn-secondary" data-esc-toggle-weeks="" type="button">Recolher todas<\/button>/u, 'Mês: "Recolher todas" com aria-expanded'))
check(() => assert.match(pageSource, /\{weeksOpen \? 'Recolher todas' : 'Expandir todas'\}/u, 'Mês: o botão alterna "Recolher todas" e "Expandir todas"'))
check(() => assert.match(pageSource, /key=\{week\.start\} large=\{view === 'week'\} onOpen=\{open\} onSelect=\{setSelection\} open=\{view === 'week' \|\| weeksOpen\}/u, 'Mês: recolher e expandir valem para todas as semanas'))
check(() => assert.match(viewer, /<summary><span>Semana 47 · 16\/11 a 20\/11<\/span><span class="esc-week-badge">atual<\/span><\/summary>/u, 'título da semana discreto, com o marcador "atual" só na semana atual'))
check(() => assert.equal((viewer.match(/esc-week-badge/gu) ?? []).length, 1, 'um só marcador "atual"'))
check(() => assert.match(viewer, /<h2 class="section-title esc-title" data-esc-title="">Novembro 2026<\/h2>/u, 'título do mês no estilo dos títulos de página, sem faixa'))
check(() => assert.match(viewer, /class="esc-grid-head esc-today" role="columnheader">Qui \(19\/11\)/u, 'hoje destacado no cabeçalho'))
check(() => assert.match(viewer, /class="esc-cell esc-tone-banco esc-today"[^>]*data-esc-cell="0:3"/u, 'hoje destacado na célula'))
check(() => assert.match(viewer, /aria-label="Qui 26\/11: Abertura home 07:00–17:00, veio de troca"/u, 'ícone da troca com nome para leitor de tela'))
check(() => assert.match(viewer, /aria-label="Qua 25\/11: Home \(exceção\) 08:00–18:00, veio de exceção"/u, 'ícone da exceção'))
check(() => assert.deepEqual([(viewer.match(/<span aria-hidden="true" class="esc-cell-marker" title="veio de troca">⇄<\/span>/gu) ?? []).length, (viewer.match(/<span aria-hidden="true" class="esc-cell-marker" title="veio de exceção">✎<\/span>/gu) ?? []).length], [2, 2], 'ícone discreto na célula (grade e cartão do celular) quando o dia veio de troca ou exceção'))
check(() => assert.match(viewer, /class="esc-cell esc-tone-feriado"[^>]*><span class="esc-cell-label">Feriado<\/span><span class="esc-cell-detail">Feriado Ficticio<\/span><\/button>/u, 'feriado na cor própria: "Feriado" e o nome embaixo, sem repetir "Feriado:"'))
check(() => assert.doesNotMatch(viewer, /esc-cell-detail">Feriado:/u, 'feriado: o detalhe nunca repete "Feriado:"'))
check(() => assert.match(viewer, /class="esc-cell esc-tone-banco"[^>]*><span class="esc-cell-label">Banco de horas/u, 'banco de horas na cor própria'))
check(() => assert.match(viewer, /class="esc-cell esc-tone-ausente"[^>]*><span class="esc-cell-label">Ausente/u, 'quem só vê: a folga aparece como Ausente (o servidor manda assim)'))
check(() => assert.doesNotMatch(viewer, /Folga|Compromisso/u, 'quem só vê: nenhuma categoria escondida na tela'))
check(() => assert.match(viewer, /class="esc-cell esc-tone-papel-azul esc-outside"[^>]*disabled=""/u, 'dias fora do mês esmaecidos e sem clique'))
check(() => assert.equal((viewer.match(/class="esc-legend-item esc-tone-/gu) ?? []).length, 8, 'legenda: os 4 papéis com a cor e as 4 cores fixas'))
check(() => assert.match(text(viewer), /⇄ troca · ✎ exceção/u, 'legenda dos ícones'))
check(() => assert.match(viewer, /href="\/api\/portal\/critical_grid\.php\?month=2026-11&amp;format=xlsx"[^>]*>Exportar Excel/u, 'exportar o mês (o que cada um vê)'))
check(() => assert.match(viewer, /<section aria-label="Ana Ficticia, Semana 47 · 16\/11 a 20\/11" class="esc-card">/u, 'celular: um cartão por pessoa com os 5 dias'))
check(() => assert.match(viewer, /<th scope="col">Presencial<\/th><th scope="col">Abertura home<\/th>/u, 'resumo do mês com os papéis'))
check(() => assert.match(text(viewer), /Resumo do mês .* % presencial Ana F\./u, 'resumo por pessoa com o % presencial'))
check(() => assert.match(viewer, /<th scope="col">Home<\/th><th scope="col">Dias home<\/th><th scope="col">Dias presencial<\/th><th scope="col">% presencial<\/th>/u, 'resumo: a coluna do papel Home e as contagens de dias com nomes diferentes'))
check(() => assert.match(css, /\.esc-row > \[role='gridcell'\] \{ display: flex; \}\n\.esc-row > \[role='gridcell'\] > \.esc-cell \{ flex: 1; \}/u,'célula ocupa a altura toda da linha (a linha com ausência parcial fica mais alta)'))
check(() => assert.match(leader, /class="esc-cell esc-tone-ausente"[^>]*><span class="esc-cell-label">Folga/u, 'Liderança: a categoria da ausência'))
check(() => assert.match(leader, /aria-label="Ter 17\/11: Presencial 08:00–18:00, Ausente 14:00–16:00 \(Compromisso\)"/u, 'Liderança: ausência parcial com o tipo'))
check(() => assert.equal((viewer.match(/role="grid"/gu) ?? []).length, 5, 'grade com papel de grid por semana (teclado: setas)'))

// ---------------------------------------------------------------------------
// 4. O diálogo do dia e a leitura
// ---------------------------------------------------------------------------
const readout = renderToStaticMarkup(h(DayReadout, { cell: fixture.cells['903:2026-11-26'], date: '2026-11-26', personName: 'Carla Ficticia' }))
check(() => assert.match(text(readout), /Abertura home 07:00–17:00 Técnico Carla Ficticia Dia Qui 26\/11 Jornada 07:00–17:00 Origem Troca Alterado por qa\.troca em 01\/11\/2026 09:00/u, 'leitura: papel, jornada, origem e quem alterou'))
const dialog = (selection) => renderToStaticMarkup(h(DayDialog, { grid: fixture, notify: () => {}, onClose: () => {}, onSaved: () => {}, returnFocusRef: null, selection }))
let opened = ''
try {
  opened = dialog({ employeeId: 903, from: '2026-11-26', to: '2026-11-26' })
} catch (error) {
  opened = String(error)
}
const dialogSource = read('src/pages/criticalGrid/DayDialog.jsx')
check(() => assert.match(dialogSource, /\{ value: 'role', label: 'Trocar o papel' \},\s+\{ value: 'absence', label: 'Ausência' \},\s+\{ value: 'exception', label: 'Exceção' \},\s+\.\.\.\(undoTargets\.length > 0 \? \[\{ value: 'undo', label: 'Desfazer' \}\] : \[\]\),/u, 'diálogo: trocar o papel, ausência, exceção e desfazer só com registro no dia'))
check(() => assert.match(dialogSource, /const undoTargets = single \? Object\.keys\(cell\.refs \|\| \{\}\) : \[\]/u, 'desfazer só de um dia, com o que o servidor mandou'))
check(() => assert.match(dialogSource, /data-esc-save="" disabled=\{busy \|\| !preview\}/u, 'salvar só depois da prévia do servidor (D-ESC-11)'))
// [Achado das capturas, 2026-11-25] A prévia vale só para a chave do pedido atual.
check(() => assert.match(dialogSource, /const currentKey = incomplete \? '' : previewKey\(body\(\)\)\n  const preview = previewFor\(storedPreview, currentKey\)/u, 'diálogo: a prévia mostrada e o salvar dependem da chave do pedido atual'))
check(() => assert.match(dialogSource, /const requested = previewKey\(body\(\)\)[\s\S]*onSuccess: \(result\) => setPreview\(result\?\.request_key === requested \? result : null\),/u, 'diálogo: só guarda a prévia que o servidor devolveu para a chave pedida'))
check(() => assert.match(dialogSource, /async function save\(\) \{\n    if \(!preview\) return/u, 'diálogo: salvar sem a prévia da chave atual não grava'))
check(() => assert.match(pageSource, /<DayDialog grid=\{grid\} key=\{`\$\{dialog\.employeeId\}:\$\{dialog\.from\}:\$\{dialog\.to\}`\}/u, 'diálogo remonta (estado limpo) quando muda a pessoa ou o dia'))
const undoKey = lib.previewKey({ employee_id: 901, from: '2026-11-19', to: '2026-11-19', change: { type: 'undo', target: 'ausencia', ref_id: 70 } })
check(() => assert.deepEqual([
  undoKey,
  lib.previewKey({ employee_id: 905, from: '2026-11-23', to: '2026-11-24', change: { type: 'absence', category: 'banco_horas' } }),
  lib.previewKey({ employee_id: 901, from: '2026-11-20', to: '2026-11-24', change: { type: 'role', role_id: 1 } }),
  lib.previewKey({ employee_id: 902, from: '2026-11-16', to: '2026-11-17', change: { type: 'exception', exception_type: 'onsite' } }),
], ['901|2026-11-19|2026-11-19|undo|ausencia:70', '905|2026-11-23|2026-11-24|absence|banco_horas', '901|2026-11-20|2026-11-24|role|1', '902|2026-11-16|2026-11-17|exception|onsite'], 'chave do pedido no formato do servidor (as mesmas do qa-critical-grid.php)'))
const undoPreview = criticalGridPreview({ employee_id: 901, from: '2026-11-19', to: '2026-11-19', change: { type: 'undo', target: 'ausencia', ref_id: 70 } })
check(() => assert.deepEqual([lib.previewFor(undoPreview, undoKey) === undoPreview, lib.previewFor(undoPreview, '901|2026-11-19|2026-11-19|undo|troca:70'), lib.previewFor(undoPreview, '901|2026-11-23|2026-11-23|role|1'), lib.previewFor(null, undoKey), lib.previewFor({ changes: [] }, undoKey)], [true, null, null, null, null], 'prévia de outra aba, opção ou dia (ou sem chave) não vale'))
check(() => assert.deepEqual(undoPreview.changes.map((item) => item.text), ['Qua 18/11: Banco de horas → Abertura home 07:00–17:00', 'Qui 19/11: Banco de horas → Abertura home 07:00–17:00'], 'servidor simulado: desfazer a ausência mostra os dias dela, nunca outro dia'))
check(() => assert.match(dialogSource, /setConfirmUndo\(true\)/u, 'desfazer pede confirmação (D-ESC-10)'))
check(() => assert.match(dialogSource, /const guard = useUnsavedChangesGuard\(dirty\)/u, 'proteção de alteração não salva'))
check(() => assert.match(dialogSource, /action: 'apply', \.\.\.body\(\), versions \}/u, 'a gravação manda a versão de cada dia (409 se mudou)'))
check(() => assert.match(dialogSource, /onError: \(\) => onSaved\(\),/u, 'erro (409) recarrega a grade'))
check(() => assert.match(dialogSource, /max=\{addDays\(selection\.from, 30\)\}/u, 'até 31 dias por vez'))
check(() => assert.ok(opened.includes('data-esc-dialog') || opened.includes('document is not defined') || opened.includes('createPortal'), 'diálogo monta (ou depende do DOM do navegador, conferido no roteiro de navegador)'))
check(() => assert.match(pageSource, /\{dialog && grid\.can_manage && \(\s+<DayDialog/u, 'diálogo de edição só para quem pode (o servidor confere de novo)'))
check(() => assert.match(pageSource, /\{dialog && !grid\.can_manage && person && \(\s+<Dialog/u, 'quem só vê: painel de leitura'))
check(() => assert.match(pageSource, /if \(event\.shiftKey\) \{\s+const next = extendSelection\(selection, person\.id, date\)/u, 'Shift+clique estende a seleção'))
check(() => assert.match(pageSource, /onMouseEnter=\{\(event\) => \{ if \(event\.buttons === 1\) onDrag\('move'\) \}\}/u, 'arrastar na linha seleciona'))
check(() => assert.doesNotMatch(pageSource, /draggable|onDrop|onDragStart/u, 'sem arrastar e soltar papel entre pessoas'))

// ---------------------------------------------------------------------------
// 5. Configurações (feriados), menu e rota
// ---------------------------------------------------------------------------
globalThis.__qaResources['portal/holidays.php'] = { year: 2026, can_manage: true, items: [{ id: 30, date: '2026-11-20', name: 'Feriado Ficticio', scope: 'todos', row_version: 1 }] }
const settingsFor = (permissions) => renderToStaticMarkup(h(SettingsPage, { session: { role: 'x', permissions }, notify: () => {} }))
const asLeader = settingsFor(['escalas.manage', 'feriados.manage'])
const asAdmin = settingsFor(['configuracoes.manage', 'feriados.manage'])
check(() => assert.deepEqual([asLeader.includes('data-holidays=""'), asLeader.includes('Polling seguro'), asAdmin.includes('data-holidays=""'), asAdmin.includes('Polling seguro')], [true, false, true, true], 'Configurações: a Liderança vê só os feriados; o admin, tudo'))
check(() => assert.match(text(asLeader), /20\/11\/2026 · Feriado Ficticio Editar Remover/u, 'feriados do ano com editar e remover'))
const settingsRoute = navigation.find((item) => item.path === '/configuracoes')
check(() => assert.deepEqual([routeAllowed(settingsRoute, new Set(['feriados.manage'])), routeAllowed(settingsRoute, new Set(['configuracoes.manage'])), routeAllowed(settingsRoute, new Set(['escalas.manage']))], [true, true, false], 'Configurações abre com configuracoes.manage ou feriados.manage'))
const gridRoute = navigation.find((item) => item.path === '/escala-critico')
check(() => assert.deepEqual([gridRoute?.label, routeAllowed(gridRoute, new Set([]))], ['Escala do Crítico', true], 'Escala do Crítico no menu, para todos os perfis'))
check(() => assert.match(read('src/App.jsx'), /\} else if \(router\.path === '\/escala-critico'\) \{\s+content = <CriticalGridPage notify=\{notify\} \/>/u, 'rota mapeada no App'))
check(() => assert.match(read('src/lib/formSchemas.ts'), /\{ value: 'banco_horas', label: 'Banco de horas' \},\n\] as const/u, 'Ausências: banco de horas no fim da lista (D-ESC-18)'))
check(() => assert.match(read('src/lib/paMap.ts'), /feriado: 'Feriado',/u, 'Mapa PA: motivo feriado'))
check(() => assert.match(read('src/pages/CalendarPage.jsx'), /holiday: 'Feriado',/u, 'Calendário: evento do feriado'))

console.log(`QA da Escala do Critico (frontend) OK: ${checks} verificacoes`)
