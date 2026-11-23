// [9.17] Controle de CHG (desenho principal, 9.17). Funções puras de
// src/lib/chg.ts e as telas desenhadas de verdade (JSX transpilado pelo
// TypeScript do projeto e react-dom/server), com os cards montados pelo
// próprio servidor (ChgService::present, chamado pelo PHP): a etapa, os selos
// e a tabela vêm do servidor, como na tela. Sem rede. Confere:
// 1. funções puras: filtros, ordem, KPIs, painel, máscara, links, texto da tabela, preferências;
// 2. card por etapa, com e sem permissão (somente leitura: nenhum editor);
// 3. formulário: forma do corpo e as recusas;
// 4. regras do redesenho: sem atributo style, classes do módulo no CSS da rota,
//    links com noopener, menu atrás do interruptor.
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
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
    if (path.startsWith(srcUrl) && path.endsWith('.css')) return { format: 'module', source: 'export default {}', shortCircuit: true }
    if (path.startsWith(srcUrl) && /\.(jsx|tsx|ts)$/u.test(path)) {
      const source = readFileSync(fileURLToPath(path), 'utf8')
      const output = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})

const lib = await import(pathToFileURL(join(srcRoot, 'lib', 'chg.ts')).href)
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const { ChgCard, ChgCardBody } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'ChgCard.jsx')).href)
const { pendingFields, resetPendingFields } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'chgPanelModel.js')).href)
const { formFromCard, payloadFromForm } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'chgFormModel.js')).href)
const { ChgDashboard, ChgList } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'ChgViews.jsx')).href)
const text = (html) => html.replace(/<!-- -->/gu, '').replace(/<[^>]+>/gu, ' ').replace(/\s+/gu, ' ').trim()
let checks = 0
const check = (fn) => { fn(); checks++ }

// Cards montados pelo servidor (ChgService::present no agora 2026-11-17 12:00).
const base = {
  solicitante: 'Solicitante Teste 01', tipo: 'padrao', versao: '\\\\servidor\\setups\\Versao Teste 01', versao_anterior: '', descricao: 'Descricao teste',
  link_planilha: 'https://planilha.teste/1', sugestao_at: '2026-11-18 20:00:00', service_status: 'aprovado',
  carteiras: [{ codigo: '9001', nome: 'Carteira Teste 01', pas: 12 }, { codigo: null, nome: 'Carteira Teste 02', pas: 3 }], locais: ['Local Teste 01'],
  chg_numero: 'CHG0000001', chg_status: 'aprovada', tecnico_id: 7, tecnico_nome: 'Tecnico 07', tecnico_nome_legado: null,
  created_at: '2026-11-10 10:00:00.000', updated_at: '2026-11-10 10:00:00.000', row_version: 3,
  plans: [{ implantacao: 'CTASK0000001', backup: 'N/A', rollback: 'CTASK0000002', task_impl: 'pendente', task_bkp: 'pendente', task_roll: 'pendente' }],
}
const ep = (url, at = null, date = null) => ({ url, start_at: at, start_date: date })
const att = (n, status = null, reag = false, endpoints = [], registered = null) => ({ attempt_no: n, patch_status: status, reagendando: reag, registered_at: registered, endpoints })
const FIXTURES = [
  { id: 1, ...base, sd_inicio: '2026-11-18 20:00:00', sd_fim: '2026-11-19 20:00:00', attempts: [att(1)], rollback: null },
  { id: 2, ...base, chg_numero: null, chg_status: null, tecnico_id: null, tecnico_nome: null, sd_inicio: '2026-11-18 20:00:00', sd_fim: '2026-11-19 20:00:00', attempts: [att(1)], rollback: null },
  { id: 3, ...base, sd_inicio: '2026-11-17 08:00:00', sd_fim: '2026-11-18 08:00:00', attempts: [att(1, 'erro', false, [ep('https://endpoint.teste/1', '2026-11-17 07:00:00')], '2026-11-17 09:00:00.000'), att(2, null, true, [ep('https://endpoint.teste/2', '2026-11-17 11:00:00')])], rollback: { id: 1, url: 'https://endpoint.teste/rb', start_at: '2026-11-17 11:30:00', started_at: '2026-11-17 11:30:00', status: 'em_andamento', plan_position: 0 } },
  { id: 4, ...base, sd_inicio: '2026-11-16 08:00:00', sd_fim: '2026-11-17 08:00:00', attempts: [att(1, 'parcial', false, [ep('https://endpoint.teste/4', '2026-11-16 09:00:00')])], rollback: null },
  { id: 5, ...base, concluida: true, concluida_em: '2026-11-15 10:00:00.000', sd_inicio: '2026-11-14 08:00:00', sd_fim: '2026-11-15 08:00:00', attempts: [att(1, 'sucesso')], rollback: null },
  { id: 6, ...base, service_status: 'cancelado', sd_inicio: '2026-11-20 08:00:00', sd_fim: '2026-11-21 08:00:00', attempts: [att(1)], rollback: null },
  { id: 7, ...base, tecnico_id: null, tecnico_nome: null, sd_inicio: '2026-11-17 08:00:00', sd_fim: '2026-11-17 11:00:00', attempts: [att(1)], rollback: null, stage_manual: null },
  { id: 8, ...base, sd_inicio: '2026-11-18 20:00:00', sd_fim: '2026-11-19 20:00:00', attempts: [att(1)], rollback: null, stage_manual: 'prep', stage_manual_auto: 'comite' },
]
const php = process.env.PHP_BIN || 'php'
const code = "require 'security.php'; require 'services/ChgService.php'; $s = (new ReflectionClass('ChgService'))->newInstanceWithoutConstructor(); $now = new DateTimeImmutable('2026-11-17 12:00:00'); echo json_encode(array_map(fn ($c) => $s->present($c, $now), json_decode(stream_get_contents(STDIN), true)));"
let cards
try {
  cards = JSON.parse(execFileSync(php, ['-r', code], { cwd: projectRoot, encoding: 'utf8', input: JSON.stringify(FIXTURES), stdio: ['pipe', 'pipe', 'ignore'] }))
} catch (error) {
  throw new Error(`PHP indisponível para montar os cards do servidor: ${error.message}`)
}
const byId = Object.fromEntries(cards.map((c) => [c.id, c]))
check(() => assert.deepEqual(cards.map((c) => c.view.stage), ['comite', 'comite', 'exec', 'valid', 'done', 'cancel', 'exec', 'prep'], 'servidor: as etapas dos cards de teste'))

// 1. Funções puras.
const all = { ...lib.EMPTY_FILTERS, fechadas: true }
check(() => assert.deepEqual(lib.filterCards(cards, lib.EMPTY_FILTERS).map((c) => c.id), [1, 2, 3, 4, 7, 8], 'filtro padrão: concluídas e canceladas escondidas'))
check(() => assert.deepEqual(lib.filterCards(cards, { ...all, chg: 'sem' }).map((c) => c.id), [2], 'filtro CHG: sem CHG'))
check(() => assert.deepEqual(lib.filterCards(cards, { ...all, tecnico: lib.NO_TECH }).map((c) => c.id), [2, 7], 'filtro técnico: sem técnico'))
check(() => assert.deepEqual(lib.filterCards(cards, { ...all, tecnico: '7' }).length, 6, 'filtro técnico: por ID'))
check(() => assert.deepEqual(lib.filterCards(cards, { ...all, q: 'endpoint.teste/rb' }).map((c) => c.id), [3], 'busca: inclui o link do rollback'))
check(() => assert.deepEqual(lib.filterCards(cards, { ...all, q: 'carteira teste 02' }).length, 8, 'busca: carteiras sem diferença de caixa'))
check(() => assert.deepEqual(lib.filterCards(cards, { ...all, de: '2026-11-18', ate: '2026-11-18' }).map((c) => c.id), [1, 2, 8], 'janela de/até pelo início do SD (ou a sugestão)'))
check(() => assert.deepEqual([...cards].sort(lib.sortByWindow).map((c) => c.id), [5, 4, 7, 3, 1, 2, 8, 6], 'ordem da janela: início, fim e criação'))
const cancelledToday = { ...byId[6], sd_inicio: '2026-11-17 08:00:00', sd_fim: '2026-11-18 08:00:00' }
check(() => assert.equal(Object.fromEntries(lib.kpis([...lib.filterCards(cards, all), cancelledToday], '2026-11-17 12:00:00').map((x) => [x.key, x.value])).hoje, 3, 'Janelas hoje: cancelada fora, mesmo com a janela hoje (v5.2)'))
const doneNoTech = { ...byId[5], id: 9, tecnico_id: null, tecnico_nome: null }
check(() => assert.deepEqual(lib.dashboard([...lib.filterCards(cards, all), doneNoTech], true).unassigned.map((c) => c.id), [7, 2], 'painel com concluídas: concluída sem técnico não entra em "Sem técnico"'))
const k = Object.fromEntries(lib.kpis(lib.filterCards(cards, lib.EMPTY_FILTERS), '2026-11-17 12:00:00').map((x) => [x.key, x]))
check(() => assert.deepEqual([k.andamento.value, k.comite.value, k.comite.detail, k.exec.value, k.exec.detail, k.rollback.detail, k.hoje.value, k.hoje.detail], [6, 2, '1 sem CHG · 1 aprovadas', 2, '1 sem técnico', '1 em andamento', 3, 'Janela ativa hoje, 45 PAs'], 'KPIs do v5.2 sobre a lista filtrada, com a data do servidor'))
const dash = lib.dashboard(lib.filterCards(cards, lib.EMPTY_FILTERS), false)
check(() => assert.deepEqual([dash.keys, dash.rows.map((r) => [r.name, r.total, r.pas]), dash.unassigned.map((c) => c.id)], [['comite', 'prep', 'exec', 'valid'], [['Tecnico 07', 4, 60], ['', 2, 30]], [7, 2]], 'painel por técnico: grupos, PAs ativas e "Sem técnico" pela janela'))
check(() => assert.deepEqual([lib.toMask('2026-11-17 08:05:00'), lib.toMask(null, '2026-11-17'), lib.toMask('')], ['17/11/2026 08:05', '17/11/2026 --:--', ''], 'máscara a partir do servidor (só data = --:--)'))
check(() => assert.deepEqual([lib.maskDigits('171120260805'), lib.maskDigits('17a11'), lib.maskDigits('1711202608051234')], ['17/11/2026 08:05', '17/11', '17/11/2026 08:05'], 'máscara enquanto digita'))
check(() => assert.deepEqual([
  lib.parseMask('17/11/2026 08:05'), lib.parseMask('17/11/2026 0805'), lib.parseMask('17/11/2026'), lib.parseMask('17/11/2026', true), lib.parseMask('17/11/2026 --:--', true),
  lib.parseMask('31/02/2026 10:00'), lib.parseMask('17/11/2026 24:00'), lib.parseMask('2026-11-17 10:00'),
], ['2026-11-17 08:05', '2026-11-17 08:05', null, '2026-11-17', '2026-11-17', null, null, null], 'leitura da máscara: data real, hora válida, só data quando permitido'))
check(() => assert.deepEqual(['https://a.teste/x', 'http://a.teste', 'javascript:alert(1)', 'data:text/html,x', 'www.a.teste', '\\\\srv\\pasta', 'https://', 'https://a b.teste', 'https://a.teste/\nx', 'ftp://a.teste'].map(lib.safeLink), ['https://a.teste/x', 'http://a.teste', null, null, null, null, null, null, null, null], 'link seguro: só http(s) com host (D-CHG-22)'))
check(() => assert.deepEqual([lib.plus24h('2026-11-30 22:00'), lib.plus24h('x')], ['2026-12-01 22:00', ''], 'fim automático: início + 24 h'))
const rows = [{ k: 'Solicitante', v: '<b>x</b>"', section: false, rollback: false, bold: false, color: null, link: false }, { k: 'Link', v: 'javascript:alert(1)', section: false, rollback: false, bold: false, color: null, link: true }, { k: 'Rollback', v: '', section: true, rollback: true, bold: true, color: null, link: false }, { k: 'Status', v: 'Erro', section: false, rollback: false, bold: false, color: 'C00000', link: false }]
check(() => assert.equal(lib.tableText(rows), 'Dados\nSolicitante\t<b>x</b>"\nLink\tjavascript:alert(1)\nRollback\nStatus\tErro', 'texto da tabela com tabulação'))
check(() => assert.equal(lib.tableText(byId[3].tabela).split('\n')[1], 'Solicitante\tSolicitante Teste 01', 'tabela do servidor vira texto'))
const store = new Map()
const fakeStorage = { getItem: (key) => store.get(key) ?? null, setItem: (key, value) => store.set(key, value) }
lib.savePrefs(fakeStorage, { ...lib.EMPTY_FILTERS, tecnico: '7', fechadas: true, q: 'segredo' })
check(() => assert.deepEqual([lib.loadPrefs(fakeStorage), JSON.parse(store.get('chronodesk_chg_prefs'))], [{ tecnico: '7', fechadas: true }, { tecnico: '7', fechadas: true }], 'preferências por pessoa: só técnico e "mostrar concluídas"'))
const broken = { getItem: () => { throw new Error('bloqueado') }, setItem: () => { throw new Error('cota') } }
check(() => assert.deepEqual([lib.loadPrefs(broken), lib.savePrefs(broken, lib.EMPTY_FILTERS), lib.loadPrefs({ getItem: () => '{x' }), lib.loadPrefs(null)], [{}, undefined, {}, {}], 'preferências: armazenamento bloqueado ou corrompido não quebra a tela'))

// 2. Card por etapa, com e sem permissão.
const noop = () => Promise.resolve(null)
// [D-CHG-35] O corpo inteiro (os editores da etapa) fica no painel "Abrir".
const render = (card, canManage) => renderToStaticMarkup(h(ChgCardBody, { card, canManage, technicians: [{ id: 7, nome: 'Tecnico 07' }, { id: 8, nome: 'Tecnico 08' }], onAct: noop, onCopy: noop, onCopyTable: noop, onConfirm: noop, onInvalid: noop }))
// [D-CHG-34] O card do quadro é compacto.
const compact = (card, canManage) => renderToStaticMarkup(h(ChgCard, { card, canManage, onAct: noop, onCopy: noop, onCopyTable: noop, onOpen: noop, drag: canManage ? { start: () => {} } : null }))
const editors = /<(input|select|textarea)\b|Mover para|Concluir execução|Reabrir execução|\+ Novo|Movido manualmente|aria-label="(Editar|Duplicar|Arquivar)"/u
for (const card of cards) {
  const readonly = render(card, false)
  check(() => assert.doesNotMatch(readonly, editors, `somente leitura (painel do card ${card.id}, ${card.view.stage}): nenhum editor, mover, concluir nem arquivar`))
  check(() => assert.ok(readonly.includes('aria-label="Copiar tabela"'), `somente leitura (painel do card ${card.id}): copiar liberado`))
  const small = compact(card, false)
  check(() => assert.doesNotMatch(small, editors, `somente leitura (card ${card.id} no quadro): nenhum editor nem mover`))
  check(() => assert.ok(small.includes('aria-label="Copiar tabela"') && small.includes('draggable="false"') && small.includes(`data-chg-open="${card.id}"`), `somente leitura (card ${card.id} no quadro): copiar e Abrir liberados; arrastar não`))
}
// Card compacto: carteira(s), CHG, tipo, prazos, situação, técnico e as ações
// rápidas; nada de planos, endpoints, rollback nem tarefas dentro da coluna.
for (const card of cards) {
  const small = compact(card, true)
  const inputs = (small.match(/<(input|textarea)\b/gu) || []).length
  const selects = (small.match(/<select\b/gu) || []).length
  check(() => assert.deepEqual([inputs, selects, /Planos da CHG|Tasks para encerramento|Link do endpoint|Endpoint do rollback|Início do rollback|Status do patch/u.test(small)], [0, 1, false], `card ${card.id} no quadro (${card.view.stage}): só o "Mover para"; sem planos, endpoints, rollback nem tarefas`))
  check(() => assert.ok(['Tipo', 'Técnico', 'Início Service Desk', 'Fim Service Desk', 'data-chg-badge', `data-chg-move="${card.id}"`, `data-chg-copy-table="${card.id}"`, `data-chg-open="${card.id}"`, 'draggable="true"'].every((s) => small.includes(s)), `card ${card.id} no quadro: tipo, técnico, prazos, situação, mover, copiar a tabela, Abrir e arrastar`))
}
check(() => assert.ok(compact(byId[1], true).includes('aria-label="Copiar número da CHG"') && compact(byId[2], true).includes('Sem CHG'), 'card no quadro: copiar o número da CHG (ou "Sem CHG")'))
check(() => assert.ok(/<h3 class="chg-card-title" title="9001 - Carteira Teste 01; [^"]+">9001 - Carteira Teste 01<span class="chg-col-count">\+1<\/span><\/h3>/u.test(compact(byId[1], true)), 'card no quadro: a 1ª carteira e "+N" (todas no title)'))
const comite = render(byId[1], true)
check(() => assert.ok(['Nº da CHG', 'Status da CHG', 'Técnico responsável', 'Aprovação Service', 'Planos da CHG · Plano 1', 'CHG aprovada. Inicia preparação 18/11 16h00 (faltam 28h00).', 'aria-label="Mover para outra coluna"', 'data-chg-detail="1"'].every((s) => comite.includes(s)), 'painel do Comitê: CHG, status, planos, técnico, aprovação Service, mover e o aviso do servidor'))
check(() => assert.ok(!/data-chg-(edit|move|card)=/u.test(comite) && (comite.match(/data-chg-field=""/gu) || []).length >= 4, 'painel: os campos que gravam ao sair marcados (data-chg-field); Editar, Duplicar e Arquivar ficam no rodapé do painel'))
const exec = render(byId[3], true)
check(() => assert.ok(exec.includes('<progress aria-label="Percentual do SLA" max="100" value="17">') && exec.includes('Rollback · Em andamento') && exec.includes('↶ Rollback · endpoint e início') && exec.includes('class="chg-tick chg-at-0 chg-tc-0 chg-tick-past"'), 'Execução: barra de SLA com marcas em 5 %, rollback primeiro no carrossel'))
check(() => assert.ok(text(exec).includes('restam 20h00') && text(exec).includes('Rollback (Em andamento) iniciado 17/11 11h30'), 'Execução: "restam" e as linhas da tentativa atual, do servidor'))
const late = render(byId[7], true)
check(() => assert.ok(late.includes('Janela encerrada') && late.includes('chg-card-late') && late.includes('encerrado há 1h00'), 'Execução com o fim no passado: Janela encerrada'))
const valid = render(byId[4], true)
check(() => assert.ok(valid.includes('Tasks para encerramento · Plano 1') && valid.includes('data-chg-finish="4"') && valid.includes('Status do patch') && !valid.includes('Link do endpoint 1'), 'Validação: endpoints só consulta, status do patch editável, tasks e Concluir'))
const done = render(byId[5], true)
check(() => assert.ok(done.includes('Reabrir execução') && !done.includes('data-chg-finish') && !/<input[^>]*CTASK/u.test(done), 'Concluída: planos só para consulta e Reabrir'))
const manual = render(byId[8], true)
check(() => assert.ok(manual.includes('Movido manualmente ↺') && manual.includes('voltar à etapa automática (Aguardando Comitê)'), 'manual: selo com a volta ao automático'))
check(() => assert.ok(render(byId[2], true).includes('Aguardando CHG') && render(byId[6], true).includes('Cancelado'), 'selos: Aguardando CHG e Cancelado'))
check(() => assert.ok(/<a aria-label="Abrir Planilha PAs" class="chg-icon-btn" href="https:\/\/planilha\.teste\/1" rel="noopener noreferrer" target="_blank"/u.test(comite), 'link da planilha: noopener noreferrer, nova aba'))
check(() => assert.ok(comite.includes('Versao Teste 01') && !/href="\\\\/u.test(comite), 'caminho UNC da versão é texto, nunca link'))
check(() => assert.ok(/Carteiras impactadas · 2: 9001 - Carteira Teste 01/u.test(text(comite)) && comite.includes('<details class="chg-details">'), 'carteiras expansíveis (details)'))

// [D-CHG-35] Painel "Abrir": sujo = campo digitado e ainda não gravado
// (diferente do carregado, sem os espaços das pontas); descartar devolve o
// carregado ao campo (nada grava na saída).
const fakeRoot = (fields) => ({ querySelectorAll: (selector) => (selector === '[data-chg-field]' ? fields : []) })
const typed = { value: 'CTASK9', defaultValue: 'CTASK1' }
const spaced = { value: ' CTASK2 ', defaultValue: 'CTASK2' }
const same = { value: 'X', defaultValue: 'X' }
check(() => assert.deepEqual([pendingFields(fakeRoot([typed, spaced, same])), pendingFields(null)], [[typed], []], 'painel: pendente só o campo com valor diferente do carregado'))
resetPendingFields(fakeRoot([typed, same]))
check(() => assert.deepEqual([typed.value, same.value], ['CTASK1', 'X'], 'painel: descartar devolve o valor carregado'))
const panelSource = read('src/pages/chg/ChgPanel.jsx')
check(() => assert.ok(panelSource.includes('const { requestLeave, dialogProps } = useUnsavedChangesGuard(dirty)') && panelSource.includes('<ConfirmDiscardDialog {...dialogProps} />') && panelSource.includes('size="max-w-4xl"') && panelSource.includes('onClose={() => leave(onClose)}') && panelSource.includes('resetPendingFields(bodyRef.current)'), 'painel: Drawer max-w-4xl com a guarda; fechar e Esc perguntam; descartar limpa os campos'))
const cardSource = read('src/pages/chg/ChgCard.jsx')
check(() => assert.ok(cardSource.includes(`const LEAVING = '[role="alertdialog"], [data-chg-leave], [aria-label="Fechar painel"]'`) && (cardSource.match(/onBlur=\{\(event\) => \{\n\s+if \(leaving\(event\)\) return/gu) || []).length === 2 && (cardSource.match(/event\.target\.defaultValue = event\.target\.value/gu) || []).length === 2 && (panelSource.match(/data-chg-leave=""/gu) || []).length === 3, 'painel: o campo não grava ao sair para o aviso, o X, Fechar, Editar ou Duplicar; gravando, o enviado vira o carregado'))
const pageSource = read('src/pages/ChgPage.jsx')
check(() => assert.ok(pageSource.includes("{ value: 'board', label: 'Quadro' }, { value: 'stage', label: 'Etapa' }, { value: 'list', label: 'Lista' }, { value: 'dash', label: 'Dashboard' }") && pageSource.includes('returnFocusRef={panelOpener}') && pageSource.includes('const panelCard = panelId === null ? null : cards.find((c) => c.id === panelId) || null'), 'seletor Quadro | Etapa | Lista | Dashboard; o painel lê o card do quadro (arquivado fecha) e devolve o foco a quem abriu'))
check(() => assert.ok(pageSource.includes('onDragOver={canManage ?') && pageSource.includes('onDrop={canManage ?') && pageSource.includes("if (event.target.closest('input,select,button,textarea,a,summary'))"), 'quadro: arrastar entre etapas continua (sem começar num controle)'))
const boardCss = read('src/pages/chg/chg.css')
check(() => assert.ok(boardCss.includes('grid-auto-flow: column; grid-auto-columns: minmax(18rem, 1fr);') && boardCss.includes('overflow: auto; max-height: calc(100dvh - 8rem);') && /\.chg-col-head \{ position: sticky; top: 0;/u.test(boardCss) && boardCss.includes('.chg-stage-grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));') && !boardCss.includes('--chg-cols'), 'quadro: colunas de no mínimo 18rem com rolagem e cabeçalho fixo; Etapa em grade de 18rem'))
const { ChgStageView } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'ChgViews.jsx')).href)
const stageHtml = renderToStaticMarkup(h(ChgStageView, { cards, stages: lib.STAGES, stage: 'exec', onStage: () => {}, cardProps: { canManage: true, onAct: noop, onCopy: noop, onCopyTable: noop, onOpen: noop }, drag: null }))
check(() => assert.ok((stageHtml.match(/data-chg-stage-tab=/gu) || []).length === lib.STAGES.length && stageHtml.includes('data-chg-stage-tab="exec"') && /aria-pressed="true"[^>]*data-chg-stage-tab="exec"/u.test(stageHtml) && [...stageHtml.matchAll(/data-chg-stage="([a-z]+)"/gu)].every((m) => m[1] === 'exec') && stageHtml.includes('class="chg-stage-grid"'), 'visão Etapa: uma aba por etapa com a contagem, só os cards da etapa escolhida, em grade'))

// 3. Formulário.
const form = formFromCard(byId[2])
check(() => assert.deepEqual([form.sugestao, form.inicio, form.fim, form.carteiras[1].sem_codigo, form.tecnico_id], ['18/11/2026 20:00', '18/11/2026 20:00', '19/11/2026 20:00', true, ''], 'formulário a partir do card: máscaras e "sem código"'))
const [payload] = payloadFromForm({ ...form, chg_numero: 'chg9', carteiras: [...form.carteiras, { codigo: '', nome: 'Carteira Teste 03', sem_codigo: true, pas: '' }] })
check(() => assert.deepEqual([payload.sugestao_at, payload.sd_inicio, payload.chg_numero, payload.carteiras.map((c) => [c.codigo, c.sem_codigo, c.pas]), payload.tecnico_id], ['2026-11-18 20:00', '2026-11-18 20:00', 'CHG9', [['9001', false, 12], ['', true, 3], ['', true, null]], null], 'corpo do formulário: datas do servidor, número em maiúsculas, carteiras e PAs'))
const refuse = (patch) => payloadFromForm({ ...form, ...patch })[1]
check(() => assert.deepEqual([
  refuse({ solicitante: ' ' }), refuse({ sugestao: '' }), refuse({ fim: '18/11/2026 19:00' }), refuse({ chg_numero: '', chg_status: 'aprovada' }),
  refuse({ locais: ['A', 'a'] }), refuse({ locais: ['A', ''] }), refuse({ carteiras: [{ codigo: '', nome: 'X', sem_codigo: false, pas: '' }] }),
], ['Informe o solicitante.', 'Informe a data sugestão (dd/mm/aaaa hh:mm).', 'O fim do Service Desk não pode ser antes do início.', 'Informe o número da CHG antes de marcá-la como aprovada.', 'O local a foi informado mais de uma vez.', 'Informe o local 2 ou remova a linha vazia.', "Na carteira 1, informe o código ou marque 'Sem código'."], 'formulário: as recusas do v5.2 antes de enviar (o servidor valida de novo)'))

// Lista e painel.
check(() => assert.ok(renderToStaticMarkup(h(ChgList, { cards })).includes('Lista de atualizações'), 'lista'))
const dashHtml = renderToStaticMarkup(h(ChgDashboard, { cards: lib.filterCards(cards, lib.EMPTY_FILTERS), withDone: false, onOpen: () => {} }))
check(() => assert.ok(dashHtml.includes('Sem técnico atribuído') && /class="chg-seg chg-tone-exec chg-w-\d+"/u.test(dashHtml) && !dashHtml.includes('style='), 'painel: barras em classes de 5 %, "Sem técnico" clicável'))

// 4. Regras do redesenho e do módulo.
const chgFiles = ['src/pages/ChgPage.jsx', 'src/pages/chg/ChgCard.jsx', 'src/pages/chg/ChgForm.jsx', 'src/pages/chg/ChgViews.jsx', 'src/pages/chg/ChgPanel.jsx']
const sources = chgFiles.map(read).join('\n')
check(() => assert.doesNotMatch(sources, /\bstyle=\{/u, 'sem atributo style no módulo'))
check(() => assert.doesNotMatch(sources, /dangerouslySetInnerHTML|innerHTML/u, 'nada de HTML montado entra no DOM (a tabela copiada vai só para a área de transferência)'))
check(() => assert.ok(read('src/pages/ChgPage.jsx').includes('await api(`${ENDPOINT}?tabela=${card.id}`)') && !/tableHtml/u.test(read('src/lib/chg.ts')), 'tabela para copiar montada pelo servidor (sem cor solta no frontend)'))
const css = read('src/pages/chg/chg.css')
const rendered = [comite, exec, late, valid, done, manual, dashHtml, ...cards.map((card) => compact(card, true))].join('\n')
const used = new Set([...rendered.matchAll(/class="([^"]+)"/gu)].flatMap((m) => m[1].split(/\s+/u)).filter((c) => c.startsWith('chg-')))
const missing = [...used].filter((c) => !new RegExp(`\\.${c}\\b`, 'u').test(css))
check(() => assert.deepEqual(missing, [], 'toda classe chg- desenhada existe no CSS da rota'))
check(() => assert.ok([0, 5, 50, 95, 100].every((n) => css.includes(`.chg-at-${n} {`)) && [5, 50, 100].every((n) => css.includes(`.chg-w-${n} {`)), 'posições e larguras de 5 em 5 % no CSS'))
check(() => assert.ok(css.includes(":root[data-theme='dark'] {") && css.includes("@media (prefers-color-scheme: dark) {\n  :root:not([data-theme='light']) {"), 'tokens do módulo nos dois temas'))
check(() => assert.ok(read('src/pages/ChgPage.jsx').includes("import './chg/chg.css'") && !read('src/index.css').includes('chg-'), 'CSS do módulo só na rota (D-CHG-26)'))
check(() => assert.ok(read('src/lib/navigation.ts').includes("{ path: '/chg', label: 'Controle de CHG', icon: 'file', module: 'chg' },") && read('src/components/PortalLayout.jsx').includes('if (item.module && session.modulos?.[item.module] !== true) return null') && read('src/App.jsx').includes("(!route?.module || session.modulos?.[route.module] === true)"), 'menu e rota atrás do interruptor (session.modulos.chg)'))
check(() => assert.ok(read('src/pages/ChgPage.jsx').includes('useResource(ENDPOINT, { intervalMs: REFRESH_MS })') && read('src/pages/ChgPage.jsx').includes('const REFRESH_MS = 30_000'), 'atualiza a cada 30 s (e ao voltar o foco, pelo useResource)'))
check(() => assert.ok(read('src/pages/chg/ChgForm.jsx').includes('useUnsavedChangesGuard(dirty)') && read('src/pages/chg/ChgForm.jsx').includes('<ConfirmDiscardDialog {...dialogProps} />'), 'formulário com a guarda de alteração não salva'))
check(() => assert.ok(existsSync(join(projectRoot, 'api', 'portal', 'chg.php')) && readdirSync(join(srcRoot, 'pages', 'chg')).sort().join(',') === 'ChgCard.jsx,ChgCatalogPanel.jsx,ChgForm.jsx,ChgImportPanel.jsx,ChgPanel.jsx,ChgViews.jsx,chg.css,chgFormModel.js,chgImportModel.js,chgPanelModel.js', 'arquivos do módulo'))

// 5. Importação do Controle v5 (só admin): prévia só com contagens.
const importModel = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'chgImportModel.js')).href)
const { ChgImportPanel } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'ChgImportPanel.jsx')).href)
check(() => assert.deepEqual(importModel.summaryRows({ contagens: { novos: 2, atualizados: 0, iguais: 0, invalidos: 1, desconhecida: 3 }, campos_descartados: { chamado: 6 } }), [['Cards novos', 2], ['Cards atualizados (o arquivo é mais novo)', 0], ['Inválidos (fora do esquema)', 1], ['desconhecida', 3], ['Campo descartado: chamado', 6]], 'prévia da importação: contagens acima de zero (novos e atualizados sempre) e os campos descartados'))
const tooBig = { size: importModel.IMPORT_MAX_BYTES + 1, text: async () => '{}' }
const notJson = { size: 2, text: async () => '{x' }
const okFile = { size: 2, text: async () => '{"items":[]}' }
const readResults = await Promise.all([tooBig, notJson].map((file) => importModel.readJsonFile(file).then(() => 'ok', (error) => error.message)))
check(() => assert.deepEqual(readResults, ['Arquivo acima de 2 MB.', 'O arquivo escolhido não é um JSON válido.'], 'arquivo da importação: limite de 2 MB e JSON válido'))
const readOk = [await importModel.readJsonFile(null), await importModel.readJsonFile(okFile)]
check(() => assert.deepEqual(readOk, [null, { items: [] }], 'arquivo da importação: sem arquivo = null; JSON lido'))
const importHtml = renderToStaticMarkup(h(ChgImportPanel, { notify: () => {}, onClose: () => {}, onDone: () => {} }))
check(() => assert.ok(importHtml.includes('Importar do Controle v5') && (importHtml.match(/type="file"/gu) || []).length === 2 && /data-chg-import-preview="" disabled=""/u.test(importHtml) && !importHtml.includes('data-chg-import-confirm'), 'painel da importação: dois arquivos; prévia desligada sem arquivo; confirmar só depois da prévia'))
check(() => assert.ok(read('src/pages/ChgPage.jsx').includes('{data?.can_import === true && <button className="btn-secondary chg-btn" data-chg-import-open=""'), 'importar só com can_import do servidor (admin)'))

// 6. [Ajustes do CHG, D-CHG-39 a D-CHG-41] Catálogo de carteiras pela
// planilha (só admin) e a busca reversa do formulário. Dados fictícios.
const { catalogCodeForName } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'chgFormModel.js')).href)
const catalogFixture = [
  { id: 1, codigo: '9001', nome: 'Carteira Teste 01', ativo: true },
  { id: 2, codigo: '9002', nome: 'Carteira Repetida', ativo: true },
  { id: 3, codigo: '9003', nome: 'Carteira Repetida', ativo: true },
  { id: 4, codigo: '9004', nome: 'Carteira Inativa', ativo: false },
]
check(() => assert.deepEqual([
  catalogCodeForName(catalogFixture, 'Carteira Teste 01'),
  catalogCodeForName(catalogFixture, '  carteira TESTE 01 '),
  catalogCodeForName(catalogFixture, 'Carteira Teste 0'),
  catalogCodeForName(catalogFixture, 'Carteira Repetida'),
  catalogCodeForName(catalogFixture, 'Carteira Inativa'),
  catalogCodeForName(catalogFixture, ''),
  catalogCodeForName(null, 'Carteira Teste 01'),
], ['9001', '9001', '', '', '', '', ''], 'busca reversa: o nome igual ao de uma carteira ativa (sem diferença de maiúsculas e espaços das pontas) dá o código; parcial, repetido ou inativo, nenhum'))
const formSource = read('src/pages/chg/ChgForm.jsx')
check(() => assert.ok(formSource.includes("const codigo = !c.sem_codigo && !c.codigo.trim() ? catalogCodeForName(catalog?.carteiras, nome) : ''; setCarteira(i, codigo ? { nome, codigo } : { nome })") && formSource.includes('const nome = catalogByCode.get(codigo); setCarteira(i, nome ? { codigo, nome } : { codigo })'), 'formulário: o nome preenche o código só com o código vazio e "sem código" desmarcado; o código continua preenchendo o nome'))
check(() => assert.deepEqual([importModel.catalogCanConfirm({ pode_confirmar: true, mudancas: 2 }), importModel.catalogCanConfirm({ pode_confirmar: true, mudancas: 0 }), importModel.catalogCanConfirm({ pode_confirmar: false, mudancas: 3 }), importModel.catalogCanConfirm(null), importModel.catalogCounts(catalogFixture)], [true, false, false, false, { ativas: 3, inativas: 1 }], 'catálogo: confirmar só com carteira válida e alguma mudança; as contagens de ativas e inativas'))
check(() => assert.deepEqual(importModel.catalogSummaryRows({ resumo: { linhas: 9, novas: 1, renomeadas: 2, reativadas: 0, sem_mudanca: 3, sairam: 1, recusadas: 2 } }), [['Linhas lidas', 9], ['Novas', 1], ['Renomeadas', 2], ['Reativadas', 0], ['Sem mudança', 3], ['Saíram (ficam inativas)', 1], ['Recusadas', 2]], 'catálogo: as linhas da prévia'))
const { ChgCatalogPanel } = await import(pathToFileURL(join(srcRoot, 'pages', 'chg', 'ChgCatalogPanel.jsx')).href)
const catalogHtml = renderToStaticMarkup(h(ChgCatalogPanel, { carteiras: catalogFixture, notify: () => {}, onClose: () => {}, onDone: () => {} }))
check(() => assert.ok(catalogHtml.includes('Catálogo de carteiras') && catalogHtml.includes('3 carteira(s) ativa(s) e 1 inativa(s) no catálogo.') && /accept="\.xlsx,\.csv"/u.test(catalogHtml) && /data-chg-catalog-preview="" disabled=""/u.test(catalogHtml) && !catalogHtml.includes('data-chg-catalog-confirm'), 'painel do catálogo: contagens, planilha .xlsx ou .csv, prévia desligada sem arquivo, confirmar só depois da prévia'))
check(() => assert.ok(read('src/pages/ChgPage.jsx').includes('{data?.can_import === true && <button className="btn-secondary chg-btn" data-chg-catalog-open=""') && read('src/pages/chg/ChgCatalogPanel.jsx').includes("const ENDPOINT = 'portal/chg_catalog_import.php'") && read('src/pages/chg/ChgCatalogPanel.jsx').includes('disabled={busy || !catalogCanConfirm(preview)}'), 'catálogo: só com can_import (admin); a planilha vai ao endpoint do catálogo; confirmar com a prévia válida'))

console.log(`QA do Controle de CHG (frontend) OK (${checks} conferências)`)
