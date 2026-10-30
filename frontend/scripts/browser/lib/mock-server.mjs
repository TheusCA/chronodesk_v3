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

const workflow = (overtime) => ({
  sucesso: true, limit: 200, truncated: false, totals: { approved_minutes: 120, pending_minutes: 240 },
  items: each((employee_name, id, team) => ({
    id, employee_id: id, employee_name, team, status: id <= 2 ? 'pending' : 'approved', approved_by: id <= 2 ? null : 'gestor.qa', approved_at: id <= 2 ? null : `${DAY} 09:00:00`,
    justification: 'Justificativa de teste',
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
const summaryValue = { overtime_approved_minutes: 120, overtime_pending_minutes: 60, adjustments: 2, oncall: 1, onsite_days: 10, remote_days: 8 }

const GET = {
  'session.php': { sucesso: true, csrf_token: 'teste', role: 'admin', permissions: ADMIN_PERMISSIONS, ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' }, gestor: { autenticado: true, admin: true, username: 'admin.qa', role: 'admin' } },
  'listar_funcionarios.php': { sucesso: true, funcionarios: each((nome, id, equipe) => ({ id, nome, equipe, ativo: true, jornada_entrada: '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', ad_login: `tecnico.${id}`, access_role: 'tecnico', arquivado_em: null, fora_mapa_escala: false })) },
  'portal/overtime.php': workflow(true),
  'portal/time_corrections.php': workflow(false),
  'portal/schedules.php': { sucesso: true, rules: each((employee_name, id, team) => ({ id, employee_id: id, employee_name, ad_login: `tecnico.${id}`, team, rule_type: 'fixed_weekdays', weekdays: [1, 3, 5], rule_label: 'Presencial seg, qua e sex', is_current: true, is_future: false, effective_from: '2026-10-01', effective_until: null })), generated: [] },
  'portal/absences.php': { sucesso: true, limit: 200, truncated: false, items: each((employee_name, id, team) => ({ id, employee_id: id, employee_name, team, starts_on: '2099-01-10', ends_on: '2099-01-12', period_type: 'full_day', start_time: null, end_time: null, category: 'folga', status: 'approved', note: null, retroactive: 0 })) },
  'portal/oncall.php': { sucesso: true, items: [] },
  'portal/reports.php': { sucesso: true, summary: { overtime_approved_minutes: 0, overtime_pending_minutes: 0, adjustments_count: 0, oncall_count: 0, onsite_days: 0, remote_days: 0, pending: 0, rejected: 0, sync_errors: 0 }, by_employee: { [NAMES[0]]: summaryValue }, by_team: { N1: summaryValue } },
  'portal/critical_incidents.php': { sucesso: true, limit: 200, truncated: false, summary: {}, items: incidents },
  'portal/sdk_responsibles.php': { sucesso: true, items: [] },
  'portal/technicians.php': { sucesso: true, server_now: `${DAY}T10:00:00-03:00`, presence_meta: { disponivel: true, pausas_disponiveis: true }, items: [] },
  'portal/pa_map.php': { sucesso: true, server_now: `${DAY}T10:00:00-03:00`, week: { from: '2026-10-26', to: '2026-10-30' }, can_manage: true, employees: [], pas: [], absences_week: [] },
  'solicitacoes_pendentes.php': { solicitacoes: [], overtime: [], time_adjustments: [], overtime_truncated: false, time_adjustments_truncated: false, limit: 200 },
  'status.php': { n1: [], n2: [], server_now: `${DAY}T10:00:00-03:00`, presenca_disponivel: true },
  'portal/notifications.php': { sucesso: true, items: [], unread: 0 },
  'configuracoes.php': { sucesso: true, configuracoes: { local_admin_enabled: false } },
}

function apiResponse(method, key, search, body) {
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
  if (key === 'logout.php') return [200, { sucesso: true, csrf_token: 'teste' }]
  if (method === 'POST') return [200, { sucesso: true, mensagem: 'ok' }]
  return [200, GET[key] ?? { sucesso: true, items: [] }]
}

const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' }

// Sobe o servidor numa porta livre; devolve { port, requests, close }.
export function startMockServer(appDir) {
  const requests = []
  const server = createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1')
    if (url.pathname.startsWith('/api/')) {
      let raw = ''
      req.on('data', (chunk) => { raw += chunk })
      req.on('end', () => {
        const key = url.pathname.slice(5)
        let body = null
        try { body = raw ? JSON.parse(raw) : null } catch { body = null }
        requests.push({ method: req.method, key })
        const [status, payload] = apiResponse(req.method, key, url.searchParams, body)
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' })
        res.end(JSON.stringify(payload))
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
    server.listen(0, '127.0.0.1', () => resolve({ port: server.address().port, requests, close: () => new Promise((done) => server.close(done)) }))
  })
}

if (process.argv[1] && process.argv[1].endsWith('mock-server.mjs')) {
  const { port } = await startMockServer(process.argv[2])
  console.log(`servidor simulado em http://127.0.0.1:${port}/app/`)
}
