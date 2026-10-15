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
  hasPartialMarker,
  isEngineScheduleEvent,
  originLabel,
  partialMarkerInDetail,
  presenceCutoffNotice,
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
for (const [event, expected, label] of [
  [engine('p1', 'onsite', 'presencial', 'Presencial (sempre presencial) · Ausente a partir de 14:00'), true, 'a partir de'],
  [engine('p2', 'remote', 'remoto', 'Remoto (exceção: remoto) · Ausente 09:00–10:00'), true, 'intervalo'],
  [engine('p3', 'no_schedule', 'sem_escala', 'Sem escala definida · Ausente 15:00–16:00'), true, 'sem escala com parcial'],
  [engine('p4', 'absence', 'ausente', 'Ausente (dia todo)'), false, 'dia inteiro'],
  [engine('p6', 'absence', 'ausente', 'Ausente (dia todo) · Ausente 09:00–10:00'), false, 'situação ausente nunca leva o marcador'],
  [engine('p5', 'onsite', 'presencial', 'Presencial (dias pares)'), false, 'sem parcial'],
  [{ ...legacySchedule, detalhe: 'x · Ausente 09:00–10:00' }, false, 'evento antigo'],
]) {
  assert.equal(hasPartialMarker(event), expected, `marcador da parcial: ${label}`)
}
assert.equal(partialMarkerInDetail('presencial', 'Presencial (regra fixa) · Ausente a partir de 10:00'), true, 'linha da tela Escala com marcador')
assert.equal(partialMarkerInDetail(undefined, 'x · Ausente a partir de 10:00'), false, 'linha sem situacao (cálculo antigo): sem marcador')
assert.deepEqual(['presencial', 'remoto', 'ausente', 'sem_escala'].map(situationLabel), ['Presencial', 'Remoto', 'Ausente', 'Sem escala'])
assert.deepEqual(['ausencia', 'regra_escala', 'expediente', 'outra'].map(originLabel), ['Ausência', 'Escala', 'Expediente', 'outra'])

// ---------------------------------------------------------------------------
// 3. Resumo do dia: cada técnico uma vez, pela situação; parcial sem somar.
// ---------------------------------------------------------------------------
const day = [
  engine('s-1', 'onsite', 'presencial', 'Presencial (sempre presencial)'),
  engine('s-2', 'onsite', 'presencial', 'Presencial (dias pares) · Ausente 09:00–10:00'),
  engine('s-3', 'remote', 'remoto', 'Remoto (sempre remoto) · Ausente a partir de 15:00'),
  engine('s-4', 'absence', 'ausente', 'Ausente (dia todo)'),
  engine('s-5', 'no_schedule', 'sem_escala', 'Sem escala definida · Ausente 15:00–16:00'),
  engine('s-2', 'onsite', 'presencial', 'Presencial (dias pares) · Ausente 09:00–10:00'),
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
}

// ---------------------------------------------------------------------------
// 5. Calendário: tela igual sem corte; motor com resumo, cartões e legenda.
// ---------------------------------------------------------------------------
assert.match(calendarSource, /const otherEvents = engineEvents\.length === 0 \? dayEvents : dayEvents\.filter\(\(event\) => !isEngineScheduleEvent\(event\)\)/, 'sem evento do motor: o dia lista os mesmos eventos de antes')
assert.match(calendarSource, /const limit = view === 'week' \? 8 : 3/, 'limites de antes (3 no mês, 8 na semana) para os demais eventos')
assert.match(calendarSource, /\{view === 'month' && <PresenceDaySummary events=\{engineEvents\} \/>\}/, 'mês: resumo do dia por situação')
assert.match(calendarSource, /\{view === 'week' && engineEvents\.map\(\(event\) => <EventChip detailed event=\{event\}/, 'semana: um cartão por técnico, com o detalhe')
assert.match(calendarSource, /\{otherEvents\.slice\(0, limit\)\.map/, 'demais tipos continuam visíveis no mês e na semana')
assert.match(calendarSource, /\{hasEngineEvents && <PresenceLegend \/>\}/, 'legenda do motor só quando há evento do motor (sem corte, igual à atual)')
assert.match(calendarSource, /<PresenceDaySummary events=\{selectedEvents\} \/>/, 'detalhe do dia com o resumo')
assert.match(calendarSource, /\{situationLabel\(event\.situacao\)\} · Origem: \{originLabel\(event\.origem\)\}/, 'cartão com situação e origem')
assert.match(calendarSource, /\{hasPartialMarker\(event\) && <PartialMarker \/>\}\{event\.detalhe\}/, 'cartão com o detalhe e o marcador da parcial')
assert.match(calendarSource, /\{event\.description && <p className="mt-1 text-sm leading-5 text-slate-400">\{event\.description\}<\/p>\}/, 'categoria só quando o servidor manda (descrição)')
assert.doesNotMatch(calendarSource, /\b(reason|observacao|note|ausencias|category)\b/, 'o Calendário não lê observação, itens internos nem a categoria crua')
assert.doesNotMatch(calendarSource, /\brole\b.*situacao|isManagerRole\([^)]*\)[^\n]*description/, 'a tela não decide perfil para a categoria')
assert.match(calendarSource, /no_schedule: 'Sem escala',\n {2}day_off: 'Folga',/, 'rótulo de Sem escala e Folga mantida')
assert.match(calendarSource, /'onsite', 'remote', 'absence', 'no_schedule', 'day_off'\]\.map\(\(status\)/, 'filtro de status com Ausência, Sem escala e Folga')
assert.match(calendarSource, /war_room: 'border-red-500\/30 bg-red-500\/10 text-red-200'/, 'War Room no tom de hoje (ausência continua laranja)')
assert.match(calendarSource, /schedule: 'border-blue-500\/30 bg-blue-500\/10 text-blue-200'/, 'escala de antes do corte no tom de hoje')
assert.match(presenceSource, /role="img" title=\{PARTIAL_MARKER_LEGEND\.label\}/, 'marcador da parcial com nome acessível')
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
assert.doesNotMatch(operationalSource, /export function CalendarPage|portal\/calendar\.php/, 'L-13: sem a CalendarPage duplicada')
assert.match(appSource, /<LoadingState label="Carregando módulo\.\.\." \/>/, 'L-14: "Carregando módulo..." com acento')
assert.doesNotMatch(appSource, /Ã/, 'L-14: sem texto quebrado no App')

console.log('qa-calendar-presence OK')
