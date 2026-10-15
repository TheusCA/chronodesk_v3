import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

// F5, commit 4 (desenho 5.1, 5.5 e 9.7): legenda única, resumo do dia no
// Calendário, tela Escala (pendência 8a), relatório com o corte, L-13 e L-14.

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const projectRoot = join(frontendRoot, '..')
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')

const legend = await import(pathToFileURL(join(frontendRoot, 'src', 'lib', 'presenceLegend.ts')).href)
const {
  PRESENCE_LEGEND,
  daySummaryAriaLabel,
  daySummaryText,
  engineEventFullText,
  engineEventName,
  hasPartialMarker,
  isEngineScheduleEvent,
  originLabel,
  partialTime,
  rowPartialTime,
  presenceCutoffNotice,
  presenceCutoffTitle,
  presenceDaySummary,
  presenceLegendItem,
  situationLabel,
} = legend

const calendarSource = read('src/pages/CalendarPage.jsx')
const operationalSource = read('src/pages/OperationalPages.jsx')
const presenceSource = read('src/components/ui/Presence.jsx')
const iconSource = read('src/components/ui/Icon.jsx')
const appSource = read('src/App.jsx')
const cssSource = read('src/index.css')
const legendSource = read('src/lib/presenceLegend.ts')

// ---------------------------------------------------------------------------
// 1. Legenda única: rótulo, letra, ícone, cor e forma; nenhuma só pela cor.
// ---------------------------------------------------------------------------
assert.deepEqual(PRESENCE_LEGEND.map((item) => item.status), ['onsite', 'remote', 'absence', 'no_schedule'], 'legenda com as quatro situações do motor, na ordem do resumo')
assert.deepEqual(PRESENCE_LEGEND.map((item) => item.letter), ['P', 'R', 'A', 'S'], 'letras do resumo')
assert.deepEqual(PRESENCE_LEGEND.map((item) => item.label), ['Presencial', 'Remoto', 'Ausente', 'Sem escala'], 'rótulos com acento')
for (const field of ['letter', 'icon', 'tone']) {
  assert.equal(new Set(PRESENCE_LEGEND.map((item) => item[field])).size, PRESENCE_LEGEND.length, `legenda: ${field} distinto por situação`)
}
for (const item of PRESENCE_LEGEND) {
  assert.match(iconSource, new RegExp(`\\b${item.icon}: \\[`), `ícone ${item.icon} existe no Icon`)
  assert.match(cssSource, new RegExp(`\\.${item.tone} \\{`), `classe ${item.tone} no index.css`)
}
assert.match(iconSource, /\bclock: \[/, 'ícone do marcador da parcial existe')
const cssRule = (name) => cssSource.match(new RegExp(`\\.${name} \\{\\s*@apply ([^;]+);`))?.[1]
const absenceEventTone = calendarSource.match(/const typeTones = \{[\s\S]*?\n {2}absence: '([^']+)'/)?.[1]
assert.equal(cssRule('presence-absence'), absenceEventTone, 'ausência do motor com a mesma cor do evento de ausência de antes do corte')
assert.match(cssRule('presence-no-schedule'), /border-dashed/, 'sem escala com contorno tracejado (forma, não só cor)')
assert.match(legendSource, /Tailwind não lê \.ts/, 'cores fora do .ts: o Tailwind só varre js e jsx')
assert.match(read('tailwind.config.js'), /content: \['\.\/index\.html', '\.\/src\/\*\*\/\*\.\{js,jsx\}'\]/, 'Tailwind continua sem varrer .ts (por isso as classes presence-* no CSS)')
// Defeito achado na revisão do commit 4: dentro de @layer components, o
// Tailwind descartava as presence-* (nomes só no .ts) e a legenda saía sem cor.
// Compila o index.css com a configuração do projeto e confere cada classe.
{
  const { default: postcss } = await import('postcss')
  const { default: tailwindcss } = await import('tailwindcss')
  const { default: tailwindConfig } = await import(pathToFileURL(join(frontendRoot, 'tailwind.config.js')).href)
  const compiled = (await postcss([tailwindcss({ ...tailwindConfig, content: tailwindConfig.content.map((glob) => join(frontendRoot, glob).replaceAll('\\', '/')) })]).process(cssSource, { from: join(frontendRoot, 'src', 'index.css') })).css
  for (const item of PRESENCE_LEGEND) {
    const rule = compiled.match(new RegExp(`\\.${item.tone} \\{([^}]*)\\}`))?.[1] ?? ''
    assert.match(rule, /border-color/, `CSS gerado com a classe ${item.tone} (borda)`)
    assert.match(rule, /color: rgb/, `CSS gerado com a classe ${item.tone} (texto)`)
  }
  assert.match(compiled.match(/\.presence-no-schedule \{([^}]*)\}/)?.[1] ?? '', /border-style: dashed/, 'CSS gerado: sem escala tracejado')
}
assert.equal(presenceLegendItem('day_off'), null, 'folga (antes do corte) fora da legenda do motor')
assert.equal(presenceLegendItem('absence').tone, 'presence-absence')

// ---------------------------------------------------------------------------
// 2. Evento do motor, marcador da parcial e rótulos.
// ---------------------------------------------------------------------------
const engine = (id, status, situacao, detalhe, extra = {}) => ({ id, event_type: 'schedule', status, situacao, origem: situacao === 'ausente' ? 'ausencia' : 'regra_escala', detalhe, ...extra })
const legacySchedule = { id: 'schedule-1-2026-10-15', event_type: 'schedule', status: 'onsite', title: 'Presencial: Tecnico A' }
assert.equal(isEngineScheduleEvent(legacySchedule), false, 'escala antes do corte (sem situacao) não é do motor')
assert.equal(isEngineScheduleEvent({ id: 'absence-1', event_type: 'absence', status: 'approved' }), false, 'evento de ausência não é do motor')
assert.equal(isEngineScheduleEvent({ id: 'x', event_type: 'manual', situacao: 'presencial' }), false, 'só eventos schedule')
assert.equal(isEngineScheduleEvent(engine('a', 'onsite', 'presencial', 'Presencial (regra fixa)')), true)
// [Ajuste b] No Calendário, o marcador vem do campo parcial do servidor, não
// do texto do detalhe.
for (const [event, expected, label] of [
  [engine('p1', 'onsite', 'presencial', 'Presencial (sempre presencial) · Ausente a partir de 14:00', { parcial: 'a partir de 14:00' }), 'a partir de 14:00', 'a partir de'],
  [engine('p2', 'remote', 'remoto', 'Remoto (exceção: remoto) · Ausente 09:00–10:00', { parcial: '09:00–10:00' }), '09:00–10:00', 'intervalo'],
  [engine('p3', 'no_schedule', 'sem_escala', 'Sem escala definida · Ausente 15:00–16:00', { parcial: '15:00–16:00' }), '15:00–16:00', 'sem escala com parcial'],
  [engine('p4', 'absence', 'ausente', 'Ausente (dia todo)'), null, 'dia inteiro'],
  [engine('p6', 'onsite', 'presencial', 'Presencial (dias pares) · Ausente 09:00–10:00'), null, 'texto do marcador no detalhe sem o campo parcial'],
  [engine('p7', 'onsite', 'presencial', 'Presencial (dias pares)', { parcial: '' }), null, 'parcial vazio'],
  [engine('p5', 'onsite', 'presencial', 'Presencial (dias pares)'), null, 'sem parcial'],
  [{ ...legacySchedule, parcial: '09:00–10:00' }, null, 'evento antigo'],
  [{ id: 'm1', event_type: 'manual', situacao: 'presencial', parcial: '09:00–10:00' }, null, 'evento que não é escala, mesmo com situacao e parcial'],
]) {
  assert.equal(partialTime(event), expected, `horário da parcial: ${label}`)
  assert.equal(hasPartialMarker(event), expected !== null, `marcador da parcial: ${label}`)
}
// [Ajuste a] Nome no cartão da semana, sem o prefixo da situação.
for (const [event, expected, label] of [
  [engine('n1', 'onsite', 'presencial', '', { title: 'Presencial: Tecnico A' }), 'Tecnico A', 'presencial'],
  [engine('n2', 'remote', 'remoto', '', { title: 'Remoto: Tecnico B' }), 'Tecnico B', 'remoto'],
  [engine('n3', 'absence', 'ausente', '', { title: 'Ausência: Tecnico C' }), 'Tecnico C', 'ausência'],
  [engine('n4', 'no_schedule', 'sem_escala', '', { title: 'Sem escala: Tecnico D' }), 'Tecnico D', 'sem escala'],
  [engine('n5', 'onsite', 'presencial', '', { title: 'Remoto: Tecnico E' }), 'Remoto: Tecnico E', 'prefixo de outra situação fica'],
  [engine('n6', 'onsite', 'presencial', '', { title: 'Presencial: ' }), 'Presencial: ', 'sem nome depois do prefixo'],
  [engine('n7', 'onsite', 'presencial', '', { title: 'Tecnico F' }), 'Tecnico F', 'título fora do formato'],
]) {
  assert.equal(engineEventName(event), expected, `nome no cartão da semana: ${label}`)
}
assert.equal(engineEventFullText(engine('t1', 'remote', 'remoto', 'Remoto (dias pares) · Ausente 09:00–10:00', { title: 'Remoto: Tecnico B', categoria: 'Compromisso' })), 'Remoto: Tecnico B · Remoto (dias pares) · Ausente 09:00–10:00 · Compromisso', 'texto completo do cartão (title): título, detalhe e categoria')
assert.equal(engineEventFullText(engine('t2', 'onsite', 'presencial', 'Presencial (regra fixa)', { title: 'Presencial: Lider A' })), 'Presencial: Lider A · Presencial (regra fixa)', 'texto completo sem categoria')
// [Commit 5] Linha da tela Escala: o marcador vem do campo parcial do schedules.php.
for (const [row, expected, label] of [
  [{ situacao: 'presencial', detalhe: 'Presencial (regra fixa) · Ausente a partir de 10:00', parcial: 'a partir de 10:00' }, 'a partir de 10:00', 'com parcial'],
  [{ situacao: 'sem_escala', detalhe: 'Sem escala definida · Ausente 11:00–11:30', parcial: '11:00–11:30' }, '11:00–11:30', 'sem escala com parcial'],
  [{ situacao: 'presencial', detalhe: 'Presencial (regra fixa) · Ausente a partir de 10:00' }, null, 'texto do marcador sem o campo parcial'],
  [{ detalhe: 'x', parcial: '09:00–10:00' }, null, 'linha sem situacao (cálculo antigo)'],
  [{ situacao: 'presencial', parcial: '' }, null, 'parcial vazio'],
  [{ situacao: 'presencial', parcial: 10 }, null, 'parcial que não é texto'],
]) {
  assert.equal(rowPartialTime(row), expected, `linha da tela Escala: ${label}`)
}
assert.doesNotMatch(legendSource + operationalSource + calendarSource, /partialMarkerInDetail|· Ausente \//, 'o marcador não é mais lido do texto do detalhe em nenhuma tela')
// [Commit 5, item b] Célula do dia (botão): conteúdo no topo, não centralizado
// na vertical (o número do dia alinhado em todas as colunas).
assert.match(calendarSource, /className=\{`flex min-h-28 flex-col justify-start border-b/, 'célula do dia com o conteúdo no topo')
assert.match(operationalSource, /\{rowPartialTime\(item\) !== null && <PartialMarker time=\{rowPartialTime\(item\)\} \/>\}\{item\.detalhe\}/, 'tela Escala: marcador e horário pelo campo parcial')
assert.deepEqual(['presencial', 'remoto', 'ausente', 'sem_escala'].map(situationLabel), ['Presencial', 'Remoto', 'Ausente', 'Sem escala'])
assert.deepEqual(['ausencia', 'regra_escala', 'expediente', 'outra'].map(originLabel), ['Ausência', 'Escala', 'Expediente', 'outra'])

// ---------------------------------------------------------------------------
// 3. Resumo do dia: cada técnico uma vez, pela situação; parcial sem somar.
// ---------------------------------------------------------------------------
const day = [
  engine('s-1', 'onsite', 'presencial', 'Presencial (sempre presencial)'),
  engine('s-2', 'onsite', 'presencial', 'Presencial (dias pares) · Ausente 09:00–10:00', { parcial: '09:00–10:00' }),
  engine('s-3', 'remote', 'remoto', 'Remoto (sempre remoto) · Ausente a partir de 15:00', { parcial: 'a partir de 15:00' }),
  engine('s-4', 'absence', 'ausente', 'Ausente (dia todo)'),
  engine('s-5', 'no_schedule', 'sem_escala', 'Sem escala definida · Ausente 15:00–16:00', { parcial: '15:00–16:00' }),
  engine('s-2', 'onsite', 'presencial', 'Presencial (dias pares) · Ausente 09:00–10:00', { parcial: '09:00–10:00' }),
  legacySchedule,
  { id: 'absence-9', event_type: 'absence', status: 'approved' },
  { id: 'war-1', event_type: 'war_room', status: 'open' },
  { id: 'oncall-1', event_type: 'oncall', status: 'approved' },
]
const summary = presenceDaySummary(day)
assert.deepEqual(summary, { onsite: 2, remote: 1, absence: 1, no_schedule: 1, partial: 3, total: 5 }, 'resumo: um por técnico (id repetido conta uma vez), só eventos do motor, parcial à parte')
assert.equal(summary.onsite + summary.remote + summary.absence + summary.no_schedule, summary.total, 'resumo: a parcial não soma de novo no total')
assert.equal(daySummaryText(summary), 'P 2 · R 1 · A 1 · S 1')
assert.equal(daySummaryText({ onsite: 9, remote: 6, absence: 2, no_schedule: 1, partial: 0, total: 18 }), 'P 9 · R 6 · A 2 · S 1', 'formato do plano (P15)')
assert.equal(daySummaryText({ onsite: 3, remote: 0, absence: 0, no_schedule: 2, partial: 0, total: 5 }), 'P 3 · S 2', 'situações zeradas fora do texto')
assert.equal(daySummaryText(presenceDaySummary([legacySchedule])), '', 'sem evento do motor: sem resumo (tela igual à de antes do corte)')
assert.equal(daySummaryAriaLabel(summary), '2 presenciais, 1 remoto, 1 ausente, 1 sem escala, inclui ausência parcial', 'leitor de tela: singular, plural e a parcial sem número')
// Filtro "Ausência" (type=absence) do corte em diante: dias com parcial vêm com a situação deles.
const absenceFilter = [day[1], day[2], day[3], day[4]]
assert.deepEqual(presenceDaySummary(absenceFilter), { onsite: 1, remote: 1, absence: 1, no_schedule: 1, partial: 3, total: 4 }, 'filtro Ausência: presencial, remoto e sem escala com parcial contam pela situação, uma vez')

// ---------------------------------------------------------------------------
// 4. Aviso do corte no relatório (mesmo texto do CSV).
// ---------------------------------------------------------------------------
const phpNotice = readFileSync(join(projectRoot, 'services', 'PresenceCutoff.php'), 'utf8')
assert.match(phpNotice, /'Escala pelo cálculo antigo até ' \. \$day\(/, 'texto do CSV (PresenceCutoff::splitNotice)')
assert.match(phpNotice, /' e pelas regras novas a partir de ' \. \$day\(\$cutoff\)/, 'texto do CSV (PresenceCutoff::splitNotice)')
assert.equal(presenceCutoffNotice({ date: '2026-10-16', split: true }), 'Escala pelo cálculo antigo até 15/10/2026 e pelas regras novas a partir de 16/10/2026.')
assert.equal(presenceCutoffNotice({ date: '2027-01-16', split: true }), 'Escala pelo cálculo antigo até 15/01/2027 e pelas regras novas a partir de 16/01/2027.')
assert.equal(presenceCutoffNotice({ date: '2026-10-16', split: false }), 'Escala pelas regras novas (corte em 16/10/2026).', 'período todo depois do corte')
for (const value of [undefined, null, {}, { date: '' }, { date: '16/10/2026', split: true }]) {
  assert.equal(presenceCutoffNotice(value), null, `sem corte no período: sem aviso (${JSON.stringify(value)})`)
  assert.equal(presenceCutoffTitle(value), null, `sem corte no período: sem título (${JSON.stringify(value)})`)
}
// [Ajuste d] Título do aviso pelo split.
assert.equal(presenceCutoffTitle({ date: '2026-10-16', split: true }), 'Escala dividida na data de corte', 'título com o período cruzando o corte')
assert.equal(presenceCutoffTitle({ date: '2026-10-16', split: false }), 'Escala pelas regras novas', 'título com o período todo depois do corte')

// ---------------------------------------------------------------------------
// 5. Calendário: tela igual sem corte; motor com resumo, cartões e legenda.
// ---------------------------------------------------------------------------
assert.match(calendarSource, /const otherEvents = engineEvents\.length === 0 \? dayEvents : dayEvents\.filter\(\(event\) => !isEngineScheduleEvent\(event\)\)/, 'sem evento do motor: o dia lista os mesmos eventos de antes')
assert.match(calendarSource, /const limit = view === 'week' \? 8 : 3/, 'limites de antes (3 no mês, 8 na semana) para os demais eventos')
assert.match(calendarSource, /\{view === 'month' && <PresenceDaySummary events=\{engineEvents\} \/>\}/, 'mês: resumo do dia por situação')
assert.match(calendarSource, /\{view === 'week' && engineEvents\.map\(\(event\) => <EventChip event=\{event\}/, 'semana: um cartão por técnico')
// Ramo do motor do EventChip (os demais eventos continuam com o título inteiro).
const chipSource = calendarSource.slice(calendarSource.indexOf('function EventChip'), calendarSource.indexOf('\n  }\n  return (', calendarSource.indexOf('function EventChip')))
assert.ok(chipSource.length > 200 && chipSource.includes('isEngineScheduleEvent(event)'), 'trecho do cartão do motor localizado')
assert.match(chipSource, /title=\{engineEventFullText\(event\)\}/, 'semana: texto completo no title do cartão (ajuste a)')
assert.match(chipSource, /<span className="line-clamp-2 min-w-0 break-words">\{engineEventName\(event\)\}<\/span>/, 'semana: nome do técnico sem o prefixo da situação, em até duas linhas (ajuste a)')
assert.match(chipSource, /<span className="sr-only">\{item\.label\}: <\/span>/, 'semana: situação para leitor de tela')
assert.match(chipSource, /\{hasPartialMarker\(event\) && <PartialMarker time=\{partialTime\(event\)\} \/>\}/, 'semana: marcador da parcial pelo campo parcial')
assert.doesNotMatch(chipSource, /event\.detalhe|event\.title\}/, 'semana: sem o detalhe nem o título com prefixo no cartão')
assert.match(calendarSource, /\{otherEvents\.slice\(0, limit\)\.map/, 'demais tipos continuam visíveis no mês e na semana')
assert.match(calendarSource, /\{hasEngineEvents && <PresenceLegend \/>\}/, 'legenda do motor só quando há evento do motor (sem corte, igual à atual)')
assert.match(calendarSource, /<PresenceDaySummary events=\{selectedEvents\} \/>/, 'detalhe do dia com o resumo')
assert.match(calendarSource, /\{situationLabel\(event\.situacao\)\} · Origem: \{originLabel\(event\.origem\)\}/, 'cartão com situação e origem')
const engineCardSource = calendarSource.slice(calendarSource.indexOf('[F5, ajuste b] Cartão do técnico'), calendarSource.indexOf(') : (', calendarSource.indexOf('[F5, ajuste b] Cartão do técnico')))
assert.match(engineCardSource, /\{hasPartialMarker\(event\) && <PartialMarker time=\{partialTime\(event\)\} \/>\}\{event\.detalhe\}/, 'cartão com o detalhe e o marcador do campo parcial')
assert.match(engineCardSource, /\{event\.categoria && <p className="mt-1 text-sm leading-5 text-slate-400">Categoria: \{event\.categoria\}<\/p>\}/, 'categoria só quando o servidor manda (campo categoria)')
assert.doesNotMatch(engineCardSource, /event\.description/,'cartão do motor sem a description (sem texto repetido, ajuste b)')
assert.doesNotMatch(calendarSource, /\b(reason|observacao|note|ausencias|category)\b/, 'o Calendário não lê observação, itens internos nem a categoria crua')
assert.doesNotMatch(calendarSource, /\brole\b.*situacao|isManagerRole\([^)]*\)[^\n]*description/, 'a tela não decide perfil para a categoria')
assert.match(calendarSource, /no_schedule: 'Sem escala',\n {2}day_off: 'Folga',/, 'rótulo de Sem escala e Folga mantida')
assert.match(calendarSource, /'onsite', 'remote', 'absence', 'no_schedule', 'day_off'\]\.map\(\(status\)/, 'filtro de status com Ausência, Sem escala e Folga')
assert.match(calendarSource, /war_room: 'border-red-500\/30 bg-red-500\/10 text-red-200'/, 'War Room no tom de hoje (ausência continua laranja)')
assert.match(calendarSource, /schedule: 'border-blue-500\/30 bg-blue-500\/10 text-blue-200'/, 'escala de antes do corte no tom de hoje')
assert.match(presenceSource, /const label = time \? `\$\{PARTIAL_MARKER_LEGEND\.label\} \$\{time\}` : PARTIAL_MARKER_LEGEND\.label/, 'marcador da parcial: horário no nome acessível quando vem')
assert.match(presenceSource, /<span aria-label=\{label\} className="inline-flex shrink-0" role="img" title=\{label\}>/, 'marcador da parcial com nome acessível')
assert.match(presenceSource, /aria-label=\{daySummaryAriaLabel\(summary\)\} className="flex flex-wrap gap-1" role="group"/, 'resumo com nome acessível')
for (const source of [calendarSource, presenceSource, legendSource]) {
  assert.doesNotMatch(source, /\bconfirm\(/, 'sem confirm() no Calendário e na legenda')
}
assert.equal(operationalSource.match(/\bconfirm\(/g)?.length, 1, 'OperationalPages: só o confirm que já existia (remover regra), nenhum novo')

// ---------------------------------------------------------------------------
// 6. Tela Escala (8a), relatório, L-13 e L-14.
// ---------------------------------------------------------------------------
assert.match(operationalSource, /const \[hideNoSchedule, setHideNoSchedule\] = useState\(false\)/, '"Ocultar sem escala" desligado por padrão')
assert.match(operationalSource, /filter\(\(item\) => !hideNoSchedule \|\| item\.presence_type !== 'no_schedule'\)/, '"Ocultar sem escala" só filtra na tela')
assert.match(operationalSource, /Ocultar sem escala/, 'rótulo do filtro')
assert.doesNotMatch(operationalSource, /hide_no_schedule|no_schedule=/, 'o filtro não vai para o schedules.php (D13)')
assert.equal(operationalSource.match(/<td className="whitespace-nowrap">\{teamLabel\(item\.team\)\}<\/td>/g)?.length, 2, '"Liderança" sem quebra nas duas tabelas da tela Escala')
assert.match(operationalSource, /<PresenceBadge status=\{item\.presence_type\} label=\{statusLabel\(item\.presence_type\)\} \/>/, 'tela Escala com a legenda única')
assert.match(operationalSource, /\.\.\.\('absence_days' in summary \? \[\['Dias de ausência', summary\.absence_days \|\| 0\]\] : \[\]\)/, 'relatório: Dias de ausência só com o campo do servidor')
assert.match(operationalSource, /\.\.\.\('no_schedule_days' in summary \? \[\['Dias sem escala', summary\.no_schedule_days \|\| 0\]\] : \[\]\)/, 'relatório: Dias sem escala só com o campo do servidor')
assert.match(operationalSource, /const cutoffNotice = presenceCutoffNotice\(resource\.data\?\.cutoff\)/, 'relatório: aviso do corte')
assert.match(operationalSource, /<InlineAlert tone="info" title=\{cutoffTitle\}>\{cutoffNotice\}<\/InlineAlert>/, 'relatório: título do aviso pelo split (ajuste d)')
assert.doesNotMatch(operationalSource, /title="Escala dividida na data de corte"/, 'relatório: sem o título fixo')
assert.match(operationalSource, /return 'Regra de escala'\n\}/, 'tela Escala: origem "Regra de escala" para qualquer regra (ajuste e)')
assert.match(operationalSource, /if \(item\.source === 'implicit'\) return 'Regra fixa da Liderança'/, 'tela Escala: "Regra fixa da Liderança" mantida')
assert.doesNotMatch(operationalSource, /export function CalendarPage|portal\/calendar\.php/, 'L-13: sem a CalendarPage duplicada')
assert.match(appSource, /<LoadingState label="Carregando módulo\.\.\." \/>/, 'L-14: "Carregando módulo..." com acento')
assert.doesNotMatch(appSource, /Ã/, 'L-14: sem texto quebrado no App')

console.log('qa-calendar-presence OK')
