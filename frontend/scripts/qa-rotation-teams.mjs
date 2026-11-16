// [F10-2] Times de rodízio e ciclo semana a semana (desenho do F10, 3.1 e 3.2).
// Funções puras de src/lib/rotationTeams.ts, com paridade contra o servidor
// (services/CyclePattern.php, chamado pelo PHP: mesmo rótulo e mesmas
// mensagens), o schema da regra e as telas desenhadas de verdade (JSX
// transpilado pelo TypeScript do projeto e react-dom/server), sem rede.
// Confere:
// 1. grade semana a semana: semanas, troca do dia, padrão, rótulo e a volta;
// 2. "copiar de outro time, deslocado em N dias úteis": Time 1 -> 2 -> 3;
// 3. paridade com o servidor (rótulo e mensagens de erro);
// 4. schema da regra: semana a semana valida semanas e a segunda-feira;
// 5. editor e seção "Times de rodízio" (Liderança edita; gestor só lê);
// 6. tela Escala: membro de time sem "Editar" e fora do formulário; payload;
// 7. cadastro: grupo da escala só na edição e só com o campo da lista.
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
const resourceStub = 'data:text/javascript,' + encodeURIComponent(
  'export function useResource(path) { return { loading: false, error: null, data: globalThis.qaResources?.[path] ?? null, refresh: async () => null, setData: () => {} } }',
)
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '../hooks/useResource' && /\/src\/pages\/(OperationalPages|RotationTeams)\.jsx$/u.test(context.parentURL ?? '')) return { url: resourceStub, shortCircuit: true }
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
      if (path.endsWith('/src/pages/RotationTeams.jsx')) {
        const from = 'const [openId, setOpenId] = useState(null)'
        if (!source.includes(from)) throw new Error('RotationTeams sem o estado do painel esperado pelo QA')
        source = source.replace(from, 'const [openId, setOpenId] = useState(globalThis.qaOpenTeam ?? null)')
      }
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const lib = await import(pathToFileURL(join(srcRoot, 'lib', 'rotationTeams.ts')).href)
const { scheduleRuleSchema, employeeFormSchema } = await import(pathToFileURL(join(srcRoot, 'lib', 'formSchemas.ts')).href)
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { RotationTeamsSection, WeeklyCycleEditor } = await import(pathToFileURL(join(srcRoot, 'pages', 'RotationTeams.jsx')).href)
const { SchedulePage } = await import(pathToFileURL(join(srcRoot, 'pages', 'OperationalPages.jsx')).href)
const text = (html) => html.replace(/<!-- -->/gu, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()
const P = 'onsite'
const R = 'remote'
const wk = (letters) => [...letters].map((letter) => (letter === 'P' ? P : R))
let checks = 0
const check = (fn) => { fn(); checks++ }

// 1. Grade semana a semana.
check(() => assert.deepEqual(lib.defaultWeek(), wk('PPPPR'), 'semana nova: presencial de seg a qui, home na sexta'))
check(() => assert.deepEqual(lib.resizeWeeks([wk('PRPPP')], 3), [wk('PRPPP'), wk('PRPPP'), wk('PRPPP')], 'semanas novas copiam a semana 1 (como a planilha)'))
check(() => assert.deepEqual(lib.resizeWeeks([wk('PRPPP'), wk('RPPPP'), wk('PPRPP')], 2), [wk('PRPPP'), wk('RPPPP')], 'diminuir mantém as primeiras'))
check(() => assert.deepEqual([lib.resizeWeeks([], 9).length, lib.resizeWeeks([], 0).length], [4, 1], 'de 1 a 4 semanas'))
check(() => assert.deepEqual(lib.toggleDay([wk('PPPPR'), wk('PPPPR')], 1, 2), [wk('PPPPR'), wk('PPRPR')], 'trocar o dia muda só aquele dia'))
const original = [wk('PPPPR')]
lib.toggleDay(original, 0, 0)
check(() => assert.deepEqual(original, [wk('PPPPR')], 'trocar o dia não altera a grade anterior (imutável)'))
check(() => assert.deepEqual(lib.weeksToPattern([wk('PPRPR'), wk('RPPPP')]), wk('PPRPRRPPPP'), 'padrão = semanas concatenadas (desenho 2.2)'))
check(() => assert.deepEqual(lib.patternToWeeks(wk('PPRPRRPPPP'), 2), [wk('PPRPR'), wk('RPPPP')], 'padrão gravado volta para a grade'))
for (const [pattern, weeks] of [[wk('PPRPR'), 2], [wk('PPRPRRPPPP'), null], [wk('PPRPRRPPPP'), 5], [['onsite', 'home', 'onsite', 'onsite', 'onsite'], 1], ['PPRPR', 1]]) {
  check(() => assert.equal(lib.patternToWeeks(pattern, weeks), null, `padrão sem weeks válido não vira grade (${JSON.stringify([pattern, weeks])})`))
}

// 2. Copiar de outro time, deslocado em N dias úteis.
check(() => assert.deepEqual([lib.shiftPattern(wk('RPP'), 1), lib.shiftPattern(wk('RPP'), 2), lib.shiftPattern(wk('RPP'), 3), lib.shiftPattern(wk('RPP'), -1)], [wk('PRP'), wk('PPR'), wk('RPP'), wk('PPR')], 'deslocar 1 dia útil faz o Time 1 virar o Time 2; 2, o Time 3; 3 volta'))
check(() => assert.deepEqual(lib.shiftPattern([], 2), [], 'padrão vazio fica vazio'))
check(() => assert.deepEqual(['2026-10-28', '2026-11-02', '2026-11-01', '2026-11-07'].map(lib.nextMonday), ['2026-11-02', '2026-11-02', '2026-11-02', '2026-11-09'], 'âncora padrão do semana a semana: a própria segunda ou a próxima'))
check(() => assert.deepEqual([lib.isMonday('2026-11-16'), lib.isMonday('2026-11-17'), lib.isMonday('2026-02-30x'), lib.isBusinessDay('2026-11-21'), lib.isBusinessDay('2026-11-20')], [true, false, false, false, true], 'segunda e dia útil'))

// 3. Paridade com o servidor: o mesmo rótulo e as mesmas mensagens do CyclePattern.php.
const cases = [
  ['weekly', [wk('PPRPR'), wk('RPPPP')], '2026-11-16'],
  ['weekly', [wk('PPPPR')], '2026-11-16'],
  ['weekly', [wk('PPRPR'), wk('RPPPP'), wk('PRPPP'), wk('PPPPR')], '2026-11-16'],
  ['weekly', [wk('PPPPP')], '2026-11-16'],
  ['weekly', [wk('RRRRR')], '2026-11-16'],
  ['weekly', [wk('PPRPR')], '2026-11-17'],
  ['pattern', wk('RPP'), '2026-10-01'],
  ['pattern', wk('RR'), '2026-10-01'],
  ['pattern', wk('R'), '2026-10-01'],
  ['pattern', wk('RPP'), '2026-10-03'],
  ['pattern', Array.from({ length: 21 }, (_, index) => (index === 0 ? R : P)), '2026-10-01'],
]
const php = `require ${JSON.stringify(join(projectRoot, 'services', 'CyclePattern.php'))};
$out = [];
foreach (json_decode(stream_get_contents(STDIN), true) as [$mode, $value, $anchor]) {
    try {
        $parsed = CyclePattern::fromPayload([$mode === 'weekly' ? 'weeks' : 'pattern' => $value, 'anchor_date' => $anchor]);
        $out[] = ['ok', CyclePattern::label($parsed['pattern'], $parsed['weeks'])];
    } catch (InvalidArgumentException $e) {
        $out[] = ['erro', $e->getMessage()];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);`
const server = JSON.parse(execFileSync('php', ['-r', php], { input: JSON.stringify(cases), encoding: 'utf8' }))
cases.forEach(([mode, value, anchor], index) => {
  const pattern = mode === 'weekly' ? lib.weeksToPattern(value) : value
  const error = lib.cycleError(pattern, anchor, mode === 'weekly')
  const client = error === null ? ['ok', lib.cycleLabel(pattern, mode === 'weekly' ? value.length : null)] : ['erro', error]
  check(() => assert.deepEqual(client, server[index], `paridade com o servidor (caso ${index + 1}: ${mode} ${anchor})`))
})

// 4. Schema da regra: semana a semana.
const ruleBase = { employee_id: '1', rule_type: 'cycle', effective_from: '2026-11-16', cycle_mode: 'weekly' }
check(() => assert.equal(scheduleRuleSchema.safeParse({ ...ruleBase, cycle_weeks: [wk('PPRPR'), wk('RPPPP')] }).success, true, 'schema: semana a semana válido'))
for (const [label, values, message] of [
  ['terça', { ...ruleBase, effective_from: '2026-11-17', cycle_weeks: [wk('PPRPR')] }, 'No ciclo semana a semana, a data inicial precisa ser uma segunda-feira.'],
  ['5 semanas', { ...ruleBase, cycle_weeks: Array.from({ length: 5 }, () => wk('PPRPR')) }, 'O ciclo semana a semana tem de 1 a 4 semanas, de segunda a sexta.'],
  ['semana de 4 dias', { ...ruleBase, cycle_weeks: [wk('PPRP')] }, 'O ciclo semana a semana tem de 1 a 4 semanas, de segunda a sexta.'],
  ['só presencial', { ...ruleBase, cycle_weeks: [wk('PPPPP')] }, 'Ciclo sem nenhum dia remoto: use a regra "Sempre presencial".'],
  ['só home', { ...ruleBase, cycle_weeks: [wk('RRRRR')] }, 'Ciclo sem nenhum dia presencial: use a regra "Sempre remoto".'],
]) {
  const parsed = scheduleRuleSchema.safeParse(values)
  check(() => assert.deepEqual([parsed.success, parsed.error?.issues.map((issue) => issue.message)], [false, [message]], `schema: semana a semana recusa (${label})`))
}
check(() => assert.equal(scheduleRuleSchema.safeParse({ employee_id: '1', rule_type: 'cycle', effective_from: '2026-11-17', cycle_onsite_days: '2', cycle_remote_days: '1', cycle_starts_with: 'remote' }).success, true, 'schema: ciclo em blocos como antes (terça vale)'))
check(() => assert.deepEqual([employeeFormSchema.safeParse({ id: 1, nome: 'Pessoa QA', equipe: 'n1', ad_login: '', jornada_entrada: '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', grupo_escala: 'separado' }).success, employeeFormSchema.safeParse({ id: 1, nome: 'Pessoa QA', equipe: 'n1', ad_login: '', jornada_entrada: '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', grupo_escala: 'outro' }).success, 'grupo_escala' in (employeeFormSchema.safeParse({ id: 1, nome: 'Pessoa QA', equipe: 'n1', ad_login: '', jornada_entrada: '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00' }).data ?? {})], [true, false, false], 'cadastro: grupo comum ou separado; ausente não vai para o servidor'))

// 5. Editor e seção "Times de rodízio".
{
  const markup = renderToStaticMarkup(h(WeeklyCycleEditor, { onChange: () => {}, weeks: [wk('PPRPR'), wk('RPPPP')] }))
  const days = [...markup.matchAll(/<button aria-label="([^"]+)" class="(btn-primary|btn-secondary) btn-sm" data-day="([a-z]+)" data-value="(onsite|remote)" type="button">([^<]+)<\/button>/gu)]
  check(() => assert.equal(days.length, 10, 'editor: 2 semanas x 5 dias, um botão por dia'))
  check(() => assert.deepEqual(days.slice(0, 5).map((match) => [match[3], match[4], match[2], match[5]]), [['seg', P, 'btn-primary', 'seg P'], ['ter', P, 'btn-primary', 'ter P'], ['qua', R, 'btn-secondary', 'qua H'], ['qui', P, 'btn-primary', 'qui P'], ['sex', R, 'btn-secondary', 'sex H']], 'editor: P preenchido, H contornado, com o texto (nunca só a cor)'))
  check(() => assert.match(days[2][1], /^Quarta da semana 1: home\. Trocar\.$/u, 'editor: nome do botão com o dia, a semana e o estado'))
  check(() => assert.match(markup, /<div aria-label="Semanas no ciclo" class="segmented" role="group">/u, 'editor: número de semanas no filtro segmentado'))
  check(() => assert.match(text(markup), /Ciclo 2 semanas, 7P\/3R\. P = presencial, H = home\./u, 'editor: rótulo do ciclo (desenho 3.2)'))
  check(() => assert.equal([...markup.matchAll(/aria-pressed="true"/gu)].length, 1, 'editor: a opção de semanas escolhida marcada'))
}
const TEAMS = {
  today: '2026-11-18',
  teams: [
    { id: 1, name: 'Time 1', active: true, current_version: { id: 1, effective_from: '2026-10-01', pattern: wk('RPP'), anchor_date: '2026-10-01', weeks: null, label: 'Ciclo 2P/1R' }, versions: [{ id: 1, effective_from: '2026-10-01', pattern: wk('RPP'), anchor_date: '2026-10-01', weeks: null, label: 'Ciclo 2P/1R' }], members: [{ id: 5, employee_id: 1, valid_from: '2026-11-02', valid_until: null, employee_active: true, is_current: true }, { id: 6, employee_id: 99, valid_from: '2026-11-02', valid_until: null, employee_active: false, is_current: true }] },
    { id: 2, name: 'Time 2', active: false, current_version: null, versions: [], members: [] },
  ],
}
const EMPLOYEES = [{ id: 1, nome: 'Pessoa Um', equipe: 'n1' }, { id: 2, nome: 'Pessoa Dois', equipe: 'n1' }, { id: 3, nome: 'Pessoa Tres', equipe: 'lideranca' }, { id: 4, nome: 'Pessoa Quatro', equipe: 'n2', fora_mapa_escala: true }]
const LIDER = { role: 'lideranca', permissions: ['escalas.manage', 'escalas.retroativa'] }
const GESTOR = { role: 'gestor', permissions: ['operacao.approve'] }
const section = (session, openTeam) => {
  globalThis.qaOpenTeam = openTeam
  try {
    return renderToStaticMarkup(h(RotationTeamsSection, { employees: EMPLOYEES, notify: () => {}, resource: { loading: false, error: null, data: TEAMS, refresh: () => {} }, session }))
  } finally {
    delete globalThis.qaOpenTeam
  }
}
{
  const markup = section(LIDER)
  check(() => assert.match(markup, /<section class="card space-y-4" data-rotation-teams="">/u, 'seção dos times na tela Escala'))
  check(() => assert.match(markup, /<table class="data-table data-table--cards">/u, 'tabela do redesenho, em cartões no celular'))
  check(() => assert.match(text(markup), /Time 1 Ciclo 2P\/1R H P P Pessoa Um, ID 99 Abrir/u, 'linha do time: padrão vigente, letras e membros vigentes'))
  check(() => assert.match(text(markup), /Time 2 Desativado Sem padrão Nenhum/u, 'time desativado e sem padrão'))
  check(() => assert.match(markup, /data-team-new=""[^>]*>Novo time<\/button>/u, 'Liderança cria time'))
  const gestor = section(GESTOR)
  check(() => assert.doesNotMatch(gestor, /Novo time|>Abrir</u, 'gestor só lê: sem criar nem abrir para editar'))
  check(() => assert.match(gestor, /data-team-open="1"[^>]*>Ver<\/button>/u, 'gestor vê o time'))
  check(() => assert.doesNotMatch(text(markup), /pessoa\.|ad_login|@/u, 'nenhum login na seção'))
}
{
  const markup = section(LIDER, 1)
  check(() => assert.match(markup, /role="dialog"/u, 'painel do time no Drawer (Dialog do 17f)'))
  check(() => assert.match(markup, /data-team-drawer="1"/u, 'painel do time 1'))
  check(() => assert.match(text(markup), /Nova versão a partir de/u, 'nova versão do padrão'))
  check(() => assert.match(markup, /<div aria-label="Montar o padrão" class="segmented" role="group">/u, 'modo do padrão: semana a semana, lista ou copiar'))
  check(() => assert.match(text(markup), /Semana a semana Lista de dias Copiar de outro time/u, 'os três modos'))
  check(() => assert.match(markup, /data-weekly-editor=""/u, 'grade semana a semana no padrão do time'))
  check(() => assert.match(markup, /<button class="btn-primary" disabled="" type="button">Salvar padrão<\/button>/u, 'salvar só depois da prévia do servidor'))
  check(() => assert.match(text(markup), /Cadastro arquivado \(ID 99\) .*Arquivado/u, 'membro arquivado aparece com o selo'))
  check(() => assert.match(markup, /data-member-add=""/u, 'incluir técnico no time'))
  check(() => assert.equal([...markup.matchAll(/<table class="data-table data-table--cards min-w-0">/gu)].length, 2, 'versões e membros sem a largura mínima de 760 px (cabem no painel, sem rolagem lateral)'))
  const anchor = markup.match(/Data inicial do ciclo \(âncora\)<\/label><input[^>]*value="(\d{4}-\d{2}-\d{2})"/u)?.[1]
  check(() => assert.equal(anchor, '2026-11-23', 'âncora padrão: a próxima segunda-feira (hoje do servidor, quarta 18/11)'))
  const options = [...(markup.match(/<label class="label" for="[^"]+">Incluir técnico<\/label><select[\s\S]*?<\/select>/u)?.[0] ?? '').matchAll(/<option value="(\d+)">/gu)].map((match) => match[1])
  check(() => assert.deepEqual(options, ['2'], 'elegíveis: N1 e N2 fora de outro time, sem Liderança nem fora do Mapa'))
  const gestorDrawer = section(GESTOR, 1)
  check(() => assert.doesNotMatch(gestorDrawer, /data-version-form|data-member-add|>Mover<|>Encerrar</u, 'gestor no painel: só leitura'))
}

// 6. Tela Escala: membro do time muda pelo time; payload do semana a semana.
{
  const session = { role: 'lideranca', permissions: ['escalas.manage', 'escalas.retroativa'] }
  globalThis.qaResources = {
    'listar_funcionarios.php': { sucesso: true, funcionarios: [{ id: 1, nome: 'Pessoa Um', equipe: 'n1' }, { id: 2, nome: 'Pessoa Dois', equipe: 'n1' }] },
    'portal/rotation_teams.php': TEAMS,
  }
  const rules = [
    { id: 10, employee_id: 1, employee_name: 'Pessoa Um', team: 'n1', rule_type: 'cycle', rule_label: 'Ciclo 2P/1R', is_current: true, is_future: false, effective_from: '2026-11-02', effective_until: null },
    { id: 11, employee_id: 2, employee_name: 'Pessoa Dois', team: 'n1', rule_type: 'always_onsite', rule_label: 'Sempre presencial', is_current: true, is_future: false, effective_from: '2026-10-01', effective_until: null },
  ]
  const markup = (() => {
    // A tela Escala pede portal/schedules.php com o período na query: qualquer uma devolve as regras do teste.
    const handler = { get: (target, path) => (typeof path === 'string' && path.startsWith('portal/schedules.php') ? { period: { from: '2026-11-16', to: '2026-12-15' }, rules, exceptions: [], generated: [] } : target[path]) }
    globalThis.qaResources = new Proxy(globalThis.qaResources, handler)
    try {
      return renderToStaticMarkup(h(SchedulePage, { session, notify: () => {} }))
    } finally {
      delete globalThis.qaResources
    }
  })()
  const rows = [...markup.matchAll(/<article [^>]*data-rule="(\d+)"[\s\S]*?<\/article>/gu)].map((match) => [match[1], text(match[0])])
  check(() => assert.match(rows.find((row) => row[0] === '10')?.[1] ?? '', /Ciclo 2P\/1R Time 1 .*Muda pelo Time 1 \(Times de rodízio\) Histórico/u, 'membro: selo do time e "Muda pelo time" no lugar de Editar e Remover'))
  check(() => assert.doesNotMatch(rows.find((row) => row[0] === '10')?.[1] ?? '', /Editar|Remover regra/u, 'membro: sem Editar nem Remover'))
  check(() => assert.match(rows.find((row) => row[0] === '11')?.[1] ?? '', /Editar Remover regra Histórico/u, 'quem não é membro: Editar e Remover como antes'))
  const select = markup.match(/<label class="label">Colaborador<select aria-invalid="false" class="field mt-2" name="employee_id">[\s\S]*?<\/select>/u)?.[0] ?? ''
  check(() => assert.deepEqual([...select.matchAll(/<option value="(\d+)">/gu)].map((match) => match[1]), ['2'], 'formulário da regra sem o membro do time'))
  check(() => assert.match(markup, /data-rotation-teams=""/u, 'seção dos times na tela Escala da gestão'))
}
const page = read('src/pages/OperationalPages.jsx')
const payload = page.match(/\nfunction buildRulePayload\(data, action\) \{[\s\S]*?\n\}/u)?.[0] ?? ''
check(() => assert.match(payload, /if \(ruleType === 'cycle' && data\.cycle_mode === 'weekly'\) \{\s*\/\/[^\n]*\n\s*payload\.cycle = \{ weeks: data\.cycle_weeks, anchor_date: data\.effective_from \}/u, 'payload do semana a semana: semanas e a segunda inicial (o servidor monta o pattern)'))
check(() => assert.match(payload, /\} else if \(ruleType === 'cycle'\) \{\s*payload\.cycle = \{\s*onsite_days: Number\(data\.cycle_onsite_days\),/u, 'payload em blocos como antes'))
check(() => assert.match(page, /const weeks = item\.rule_type === 'cycle' \? patternToWeeks\(pattern, config\.weeks\) : null/u, 'Editar um semana a semana abre a grade'))
check(() => assert.match(page, /if \(mode === 'weekly' && !isMonday\(rule\.effective_from\)\) setRuleValue\('effective_from', nextMonday\(rule\.effective_from\), \{ shouldDirty: true \}\)/u, 'trocar para semana a semana leva a data para a próxima segunda'))

// 7. Cadastro: grupo da escala.
const admin = read('src/pages/AdminPage.jsx')
check(() => assert.match(admin, /\{editing && \['comum', 'separado'\]\.includes\(form\.grupo_escala\) && \(\s*<label className="label sm:col-span-2" data-employee-group="">Grupo da escala/u, 'grupo da escala só na edição e só com o campo da lista'))
check(() => assert.match(admin, /grupo_escala: \['comum', 'separado'\]\.includes\(item\.grupo_escala\) \? item\.grupo_escala : undefined,/u, 'sem o campo na lista (021 ausente), nada vai para o servidor'))

console.log(`QA times de rodizio (frontend) OK: ${checks} verificacoes`)
