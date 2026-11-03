/* global process */
// Servidor simulado para os roteiros de navegador (frontend/scripts/browser).
// Serve o build do frontend em /app e responde a API com DADOS FICTICIOS
// (nomes "Tecnico NN", "Carteira A", "CC 1234"). Nenhum dado real.
import { createServer } from 'node:http'
import { existsSync, readFileSync, statSync } from 'node:fs'
import { extname, join, normalize } from 'node:path'

const ADMIN_PERMISSIONS = ['portal.read', 'pausas.use', 'metricas.read', 'relatorios.read', 'documentacao.edit', 'avisos.manage', 'operacao.approve', 'ausencias.manage', 'funcionarios.manage', 'escalas.manage', 'pa_map.manage', 'pausas.force_end', 'escalas.retroativa', 'ausencias.retroativa', 'admin.manage', 'configuracoes.manage', 'integracoes.manage', 'usuarios_locais.manage', 'perfis.promote', 'escalas.lideranca', 'funcionarios.fora_mapa', 'funcionarios.restaurar']
const NAMES = ['Tecnico 01 Sobrenome', 'Tecnico 02 Com Um Nome Bem Comprido', 'Tecnico 03 Sobrenome', 'Tecnico 04 Sobrenome']
const TEAMS = ['n1', 'n2', 'n1', 'n2']
const DAY = '2026-10-28'
const each = (fn) => NAMES.map((name, index) => fn(name, index + 1, TEAMS[index]))

// Numero de incidente que faz o salvar falhar (erro do servidor simulado).
export const FAILING_INCIDENT = 'FALHA-QA'
// Nome de funcionario que faz o salvar falhar (erro do servidor simulado).
export const FAILING_EMPLOYEE = 'FALHA-QA'
// Observacao de ausencia que faz o salvar falhar (erro do servidor simulado).
export const FAILING_ABSENCE_NOTE = 'FALHA-QA'
// Colaborador cuja regra de escala falha ao salvar e ao remover (erro do servidor simulado).
export const FAILING_RULE_EMPLOYEE = 4
// Mensagem real do servidor para decidir o que nao esta mais pendente (OperationalService).
export const ALREADY_DECIDED = 'Somente registros pendentes podem ser decididos.'
// [DD3] Mensagens reais: pausa ja encerrada (GerenciadorPausas) e documento que ja saiu (DocumentService).
export const NOT_PAUSED = 'Tecnico 01 Sobrenome não está em pausa.'
export const DOCUMENT_NOT_FOUND = 'Documento nao encontrado.'

// Decisoes de horas extras e correcao de ponto: a situacao muda com o POST, como no servidor real.
const OVERTIME_KEY = 'portal/overtime.php'
const ADJUSTMENT_KEY = 'portal/time_corrections.php'
const decisions = new Map()
const refusals = new Map()
const decidedPauses = new Set()
const decisionStatus = (key, id) => decisions.get(`${key}:${id}`) ?? (id <= 2 ? 'pending' : 'approved')
const workflow = (overtime) => ({
  sucesso: true, limit: 200, truncated: false, totals: { approved_minutes: 120, pending_minutes: 240 },
  items: each((employee_name, id, team) => ({
    id, employee_id: id, employee_name, team, status: decisionStatus(overtime ? OVERTIME_KEY : ADJUSTMENT_KEY, id), approved_by: decisionStatus(overtime ? OVERTIME_KEY : ADJUSTMENT_KEY, id) === 'pending' ? null : 'gestor.qa', approved_at: decisionStatus(overtime ? OVERTIME_KEY : ADJUSTMENT_KEY, id) === 'pending' ? null : `${DAY} 09:00:00`,
    justification: 'Justificativa de teste', created_at: `${DAY} 08:00:00`,
    ...(overtime
      ? { work_date: DAY, start_time: '18:00:00', end_time: '20:00:00', total_minutes: 120, reason: 'Motivo de teste' }
      : { adjustment_date: DAY, adjustment_type: 'entry', recorded_time: '08:10:00', correct_time: '08:00:00' }),
  })),
})
const incidents = each((name, id) => ({
  id, incident_number: `INC-QA-00${id}`, ticket_number: `RITM-QA-00${id}`, title: `Incidente de teste ${id}`, summary: 'Resumo de teste', room_date: DAY,
  room_description: 'Sala de teste', room_duration_minutes: 90, room_opening_duration_minutes: 15, room_finalization_description: null,
  severity: 'critical', status: ['open', 'open', 'mitigated', 'resolved'][id - 1], sector: 'Carteira A', responsible_area: 'CC 1234',
  sdk_responsible_login: 'tecnico.1', sdk_responsible_name: NAMES[0], sdk_activity: 'Acompanhamento', opened_at: `${DAY} 08:00:00`,
  incident_opened_at: `${DAY} 07:50:00`, meeting_url: id === 4 ? 'https://sala.invalid/qa' : null, notes: null, source: 'manual',
}))
// Regras variadas de proposito: abrir para editar, sem mexer, nao pode contar como sujo.
const scheduleRules = [
  { rule_type: 'fixed_weekdays', weekdays: ['mon', 'wed', 'fri'], config: {}, rule_label: 'Presencial seg, qua e sex', is_current: true, is_future: false, effective_from: '2026-10-01' },
  { rule_type: 'fixed_weekdays', weekdays: ['tue', 'thu'], config: { input_mode: 'home' }, rule_label: 'Home seg, qua e sex', is_current: true, is_future: false, effective_from: '2026-10-01' },
  { rule_type: 'cycle', weekdays: [], config: { pattern: ['onsite', 'onsite', 'remote'] }, rule_label: 'Ciclo 2 e 1', is_current: false, is_future: true, effective_from: '2099-01-05' },
  { rule_type: 'always_onsite', weekdays: [], config: {}, rule_label: 'Sempre presencial', is_current: true, is_future: false, effective_from: '2026-10-01' },
].map((rule, index) => ({ ...rule, id: index + 1, employee_id: index + 1, employee_name: NAMES[index], ad_login: `tecnico.${index + 1}`, team: TEAMS[index], effective_until: null, updated_by: 'gestor.qa' }))
// Periodos variados de proposito, pelo mesmo motivo.
const absencePeriods = [
  { starts_on: '2099-01-10', ends_on: '2099-01-12', period_type: 'full_day', start_time: null, end_time: null, note: null },
  { starts_on: '2099-01-20', ends_on: '2099-01-20', period_type: 'from_time', start_time: '13:30:00', end_time: null, note: '' },
  { starts_on: '2099-01-21', ends_on: '2099-01-21', period_type: 'interval', start_time: '09:00:00', end_time: '10:30:00', note: 'Observacao de teste' },
  { starts_on: '2099-01-22', ends_on: '2099-01-22', period_type: 'full_day', start_time: null, end_time: null, note: null },
]
// Escala gerada: abaixo do Historico, a pagina continua, como na tela real.
const generatedDays = Array.from({ length: 20 }, (_, index) => ({ employee_id: 1, employee_name: NAMES[0], team: TEAMS[0], date: `2026-10-${String(index + 1).padStart(2, '0')}`, rule_type: 'always_onsite', weekdays: [], rule_label: 'Sempre presencial', presence_type: 'onsite', source: 'rule' }))
const removedRules = new Set()
// [DD3] Pausa em andamento (desligada por padrao: os outros blocos veem o painel vazio) e documentos.
let pausedEmployee = false
let forceEndRefusal = null
const documents = [1, 2].map((id) => ({ id, title: `Documento de teste ${id}`, original_name: `documento-${id}.pdf`, extension: 'pdf', size_bytes: 2048, category: 'Procedimento', description: null, visibility: 'internal', uploaded_by: 'gestor.qa', uploaded_at: `${DAY} 08:00:00` }))
const removedDocuments = new Set()
let documentRefusal = null
const pausedStatus = () => ({ ...GET['status.php'], n1: pausedEmployee ? [{ id: 1, nome: NAMES[0], equipe: 'n1', ativo: true, em_pausa: true, motivo_pausa: 'Café', inicio_pausa: `${DAY}T09:55:00-03:00`, elapsed_seconds: 300, jornada_entrada: '08:00', jornada_saida: '17:00' }] : [] })
const summaryValue = { overtime_approved_minutes: 120, overtime_pending_minutes: 60, adjustments: 2, oncall: 1, onsite_days: 10, remote_days: 8 }

const GET = {
  'session.php': { sucesso: true, csrf_token: 'teste', role: 'admin', permissions: ADMIN_PERMISSIONS, ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' }, gestor: { autenticado: true, admin: true, username: 'admin.qa', role: 'admin' } },
  // Cadastros variados de proposito: abrir e fechar sem mexer nao pode contar como sujo.
  'listar_funcionarios.php': { sucesso: true, funcionarios: each((nome, id, equipe) => ({ id, nome, equipe, ativo: true, jornada_entrada: id === 3 ? '08:00:00' : '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', ad_login: id === 3 ? null : `tecnico.${id}`, access_role: id === 4 ? 'somente_leitura' : 'tecnico', arquivado_em: null, fora_mapa_escala: id === 2 })) },
  'portal/absences.php': { sucesso: true, limit: 200, truncated: false, items: each((employee_name, id, team) => ({ id, employee_id: id, employee_name, team, ...absencePeriods[id - 1], category: 'folga', status: 'approved', retroactive: 0 })) },
  'portal/oncall.php': { sucesso: true, items: [] },
  'portal/reports.php': { sucesso: true, summary: { overtime_approved_minutes: 0, overtime_pending_minutes: 0, adjustments_count: 0, oncall_count: 0, onsite_days: 0, remote_days: 0, pending: 0, rejected: 0, sync_errors: 0 }, by_employee: { [NAMES[0]]: summaryValue }, by_team: { N1: summaryValue } },
  'portal/critical_incidents.php': { sucesso: true, limit: 200, truncated: false, summary: {}, items: incidents },
  'portal/sdk_responsibles.php': { sucesso: true, items: [] },
  'portal/technicians.php': { sucesso: true, server_now: `${DAY}T10:00:00-03:00`, presence_meta: { disponivel: true, pausas_disponiveis: true }, items: [] },
  'portal/pa_map.php': { sucesso: true, server_now: `${DAY}T10:00:00-03:00`, week: { from: '2026-10-26', to: '2026-10-30' }, can_manage: true, employees: [], pas: [], absences_week: [] },
  'status.php': { n1: [], n2: [], server_now: `${DAY}T10:00:00-03:00`, presenca_disponivel: true },
  'portal/notifications.php': { sucesso: true, items: [], unread: 0 },
  'configuracoes.php': { sucesso: true, configuracoes: { local_admin_enabled: false } },
}

function apiResponse(method, key, search, body) {
  if (key === 'status.php' && method === 'GET') return [200, pausedStatus()]
  if (key === 'portal/admin_force_end_break.php' && method === 'POST') {
    if (forceEndRefusal) return forceEndRefusal
    if (!pausedEmployee) return [409, { sucesso: false, mensagem: NOT_PAUSED }]
    pausedEmployee = false
    return [200, { sucesso: true, mensagem: `${NAMES[0]} finalizou a pausa às 10:00. Duração: 300 segundos.` }]
  }
  if (key === 'portal/documents.php' && method === 'GET') return [200, { sucesso: true, can_upload: true, limit: 200, truncated: false, items: documents.filter((item) => !removedDocuments.has(item.id)) }]
  if (key === 'portal/documents_delete.php' && method === 'POST') {
    if (documentRefusal) return documentRefusal
    if (removedDocuments.has(body?.id)) return [404, { sucesso: false, mensagem: DOCUMENT_NOT_FOUND }]
    removedDocuments.add(body?.id)
    return [200, { sucesso: true, mensagem: 'Documento removido da listagem.' }]
  }
  if (key === OVERTIME_KEY || key === ADJUSTMENT_KEY) {
    if (method === 'GET') return [200, workflow(key === OVERTIME_KEY)]
    if (body?.action === 'decision') {
      const refusal = refusals.get(`${key}:${body.id}`)
      if (refusal) return refusal
      if (decisionStatus(key, body.id) !== 'pending') return [409, { sucesso: false, mensagem: ALREADY_DECIDED }]
      decisions.set(`${key}:${body.id}`, body.decision)
      return [200, { sucesso: true, mensagem: 'Decisao registrada.' }]
    }
  }
  if (key === 'solicitacoes_pendentes.php') {
    return [200, {
      solicitacoes: decidedPauses.has(1) ? [] : [{ id: 1, nome: NAMES[0], equipe: 'n1', motivo: 'Reuniao', observacao: 'Observacao de teste', solicitacao_timestamp: `${DAY} 09:30:00` }],
      overtime: workflow(true).items.filter((item) => item.status === 'pending'),
      time_adjustments: workflow(false).items.filter((item) => item.status === 'pending'),
      overtime_truncated: false, time_adjustments_truncated: false, limit: 200,
    }]
  }
  if ((key === 'aprovar_pausa.php' || key === 'rejeitar_pausa.php') && method === 'POST') {
    decidedPauses.add(body?.funcionario_id)
    return [200, { sucesso: true, mensagem: 'ok' }]
  }
  if (key === 'portal/critical_incidents.php') {
    const id = Number(search.get('id'))
    if (method === 'GET' && id) return [200, { sucesso: true, item: incidents.find((item) => item.id === id) }]
    if (method === 'POST') {
      if (body?.incident_number === FAILING_INCIDENT) return [409, { sucesso: false, mensagem: 'Falha simulada do servidor.' }]
      return [200, { sucesso: true, mensagem: 'Registro salvo.', id: body?.id || 99 }]
    }
  }
  if (key === 'portal/critical_incidents_import.php' && method === 'POST') {
    if (body?.action === 'confirm') return [200, { sucesso: true, imported: 1 }]
    return [200, { sucesso: true, parsed_rows: [{ ticket_number: 'INC-QA-IMP' }], valid_count: 1, invalid_count: 0, rows: [{ line: 2, valid: true, ticket_number: 'INC-QA-IMP', title: 'Linha de teste', errors: [] }] }]
  }
  if ((key === 'adicionar_funcionario.php' || key === 'atualizar_funcionario.php') && method === 'POST' && body?.nome === FAILING_EMPLOYEE) return [409, { sucesso: false, mensagem: 'Falha simulada do servidor.' }]
  if (key === 'portal/absences.php' && method === 'POST' && body?.note === FAILING_ABSENCE_NOTE) return [409, { sucesso: false, mensagem: 'Falha simulada do servidor.' }]
  if (key === 'portal/schedules.php') {
    if (method === 'GET') return [200, { sucesso: true, rules: scheduleRules.filter((rule) => !removedRules.has(rule.employee_id)), generated: generatedDays }]
    if (body?.action === 'rule_preview') return [200, { sucesso: true, rule_label: 'Previa de teste', preview: [] }]
    if (['rule', 'remove_rule'].includes(body?.action) && body?.employee_id === FAILING_RULE_EMPLOYEE) return [409, { sucesso: false, mensagem: 'Falha simulada do servidor.' }]
    if (body?.action === 'remove_rule') removedRules.add(body.employee_id)
  }
  if (key === 'logout.php') return [200, { sucesso: true, csrf_token: 'teste' }]
  if (method === 'POST') return [200, { sucesso: true, mensagem: 'ok' }]
  return [200, GET[key] ?? { sucesso: true, items: [] }]
}

const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' }

// Sessao de Lideranca: sem as permissoes so de admin (por exemplo, funcionarios.fora_mapa).
const LEADERSHIP_SESSION = { sucesso: true, csrf_token: 'teste', role: 'lideranca', permissions: ['portal.read', 'pausas.use', 'metricas.read', 'relatorios.read', 'documentacao.edit', 'avisos.manage', 'operacao.approve', 'ausencias.manage', 'funcionarios.manage', 'escalas.manage', 'pa_map.manage'], ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' }, gestor: { autenticado: true, admin: false, username: 'lider.qa', role: 'lideranca' } }
const ADMIN_SESSION = GET['session.php']

// Sobe o servidor numa porta livre; devolve { port, requests, useLeadershipSession, useAdminSession, restoreRules, keepOnlyRule, decideElsewhere, refuseDecision, restoreDecisions, setDecisionDelay, setPausedEmployee, refuseForceEnd, restoreDocuments, removeDocumentElsewhere, refuseDocument, close }.
// setDecisionDelay tambem atrasa derrubar pausa e remover documento (DD3).
export function startMockServer(appDir) {
  const requests = []
  let decisionDelay = 0
  const server = createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1')
    if (url.pathname.startsWith('/api/')) {
      let raw = ''
      req.on('data', (chunk) => { raw += chunk })
      req.on('end', () => {
        const key = url.pathname.slice(5)
        let body = null
        try { body = raw ? JSON.parse(raw) : null } catch { body = null }
        requests.push({ method: req.method, key, body })
        const [status, payload] = apiResponse(req.method, key, url.searchParams, body)
        setTimeout(() => {
          res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' })
          res.end(JSON.stringify(payload))
        }, body?.action === 'decision' || ['portal/admin_force_end_break.php', 'portal/documents_delete.php'].includes(key) ? decisionDelay : 0)
      })
      return
    }
    const relative = normalize(decodeURIComponent(url.pathname.replace(/^\/app\/?/, ''))).replace(/^(\.\.[/\\])+/, '')
    let file = join(appDir, relative)
    if (!url.pathname.startsWith('/app/assets/') || !existsSync(file) || statSync(file).isDirectory()) file = join(appDir, 'index.html')
    res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' })
    res.end(readFileSync(file))
  })
  return new Promise((resolve, reject) => {
    server.once('error', reject)
    server.listen(0, '127.0.0.1', () => resolve({ port: server.address().port, requests, useLeadershipSession: () => { GET['session.php'] = LEADERSHIP_SESSION }, useAdminSession: () => { GET['session.php'] = ADMIN_SESSION }, restoreRules: () => removedRules.clear(), keepOnlyRule: (employeeId) => { for (const rule of scheduleRules) if (rule.employee_id !== employeeId) removedRules.add(rule.employee_id) }, decideElsewhere: (key, id) => decisions.set(`${key}:${id}`, 'approved'), refuseDecision: (key, id, status, mensagem) => refusals.set(`${key}:${id}`, [status, { sucesso: false, mensagem }]), restoreDecisions: () => { decisions.clear(); refusals.clear(); decidedPauses.clear(); decisionDelay = 0 }, setPausedEmployee: (value) => { pausedEmployee = value }, refuseForceEnd: (status, mensagem) => { forceEndRefusal = status ? [status, { sucesso: false, mensagem }] : null }, restoreDocuments: () => { removedDocuments.clear(); documentRefusal = null }, removeDocumentElsewhere: (id) => removedDocuments.add(id), refuseDocument: (status, mensagem) => { documentRefusal = status ? [status, { sucesso: false, mensagem }] : null }, setDecisionDelay: (ms) => { decisionDelay = ms }, close: () => new Promise((done) => server.close(done)) }))
  })
}

if (process.argv[1] && process.argv[1].endsWith('mock-server.mjs')) {
  const { port } = await startMockServer(process.argv[2])
  console.log(`servidor simulado em http://127.0.0.1:${port}/app/`)
}
