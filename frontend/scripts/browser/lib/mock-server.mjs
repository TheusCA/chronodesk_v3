/* global process */
// Servidor simulado para os roteiros de navegador (frontend/scripts/browser).
// Serve o build do frontend em /app e responde a API com DADOS FICTICIOS
// (nomes "Tecnico NN", "Carteira A", "CC 1234"). Nenhum dado real.
import { execFileSync } from 'node:child_process'
import { createServer } from 'node:http'
import { existsSync, readFileSync, statSync } from 'node:fs'
import { dirname, extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { criticalGridFixture, criticalGridPreview } from './critical-grid-fixture.mjs'
import { MONTHLY_IMPORT_PREVIEW, monthlyPreview, monthlyRead } from './monthly-fixture.mjs'

const projectRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..')

const ADMIN_PERMISSIONS = ['portal.read', 'pausas.use', 'metricas.read', 'relatorios.read', 'documentacao.edit', 'avisos.manage', 'operacao.approve', 'ausencias.manage', 'funcionarios.manage', 'escalas.manage', 'pa_map.manage', 'pausas.force_end', 'feriados.manage', 'escala_mensal.publish', 'escala_mensal.import', 'escalas.retroativa', 'ausencias.retroativa', 'admin.manage', 'configuracoes.manage', 'integracoes.manage', 'usuarios_locais.manage', 'perfis.promote', 'escalas.lideranca', 'funcionarios.fora_mapa', 'funcionarios.restaurar']
const NAMES = ['Tecnico 01 Sobrenome', 'Tecnico 02 Com Um Nome Bem Comprido', 'Tecnico 03 Sobrenome', 'Tecnico 04 Sobrenome']
const TEAMS = ['n1', 'n2', 'n1', 'n2']
export const DAY = '2026-10-28'
const each = (fn) => NAMES.map((name, index) => fn(name, index + 1, TEAMS[index]))

// Numero de incidente que faz o salvar falhar (erro do servidor simulado).
export const FAILING_INCIDENT = 'FALHA-QA'
// Nome de funcionario que faz o salvar falhar (erro do servidor simulado).
export const FAILING_EMPLOYEE = 'FALHA-QA'
// Observacao de ausencia que faz o salvar falhar (erro do servidor simulado).
export const FAILING_ABSENCE_NOTE = 'FALHA-QA'
// Observacao de vinculo de PA que faz o salvar falhar (erro do servidor simulado).
export const FAILING_PA_NOTE = 'FALHA-QA'
// [II3] Recusa real do fim antes do inicio (PaMapService::save), amarrada no qa-pa-conflicts.php.
export const PA_END_BEFORE_START = 'Fim da validade não pode ser anterior ao início.'
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
// [R4a] Tecnicos a mais no status.php (lista cheia, como a de producao), por equipe.
let extraStatus = null
let forceEndRefusal = null
const documents = [1, 2].map((id) => ({ id, title: `Documento de teste ${id}`, original_name: `documento-${id}.pdf`, extension: 'pdf', size_bytes: 2048, category: 'Procedimento', description: null, visibility: 'internal', uploaded_by: 'gestor.qa', uploaded_at: `${DAY} 08:00:00` }))
const removedDocuments = new Set()
let documentRefusal = null
// [9.12.1] Vinculos de PA removidos (somem do GET) e a recusa do remover.
const removedPaAssignments = new Set()
let paRemoveRefusal = null
// [9.12.4] Excecoes de escala gravadas (o GET filtra pelo periodo e pelo
// colaborador da consulta, como o servidor) e a recusa da consulta.
let scheduleExceptions = []
let scheduleLookupRefusal = null
const pausedStatus = () => ({ ...GET['status.php'], n1: [...(pausedEmployee ? [{ id: 1, nome: NAMES[0], equipe: 'n1', ativo: true, em_pausa: true, motivo_pausa: 'Café', inicio_pausa: `${DAY}T09:55:00-03:00`, elapsed_seconds: 300, jornada_entrada: '08:00', jornada_saida: '17:00' }] : []), ...(extraStatus?.n1 ?? [])], n2: extraStatus?.n2 ?? [], critico: extraStatus?.critico ?? [], hibrido: extraStatus?.hibrido ?? [], disponibilidade_equipes: extraStatus?.disponibilidade_equipes ?? [] })
// [9.12.1, II3] Um vinculo de PA com fim da validade: o formulario carrega o fim na edicao.
export const PA_ASSIGNMENT = { id: 7, pa_number: '1726', employee_id: 1, employee_name: NAMES[0], team: TEAMS[0], schedule_rule_label: 'Sempre presencial', source: 'assignment', valid_from: '2026-10-01', valid_until: '2026-12-31', notes: 'Observacao do vinculo', presence: { status: 'onsite', situacao: 'presencial', categoria: null, detalhe: null, parcial: null } }
// [17f] Outro vinculo no mesmo PA (remoto no dia, sem fim e sem observacao): "Editar outro vinculo" com o formulario sujo.
export const PA_OTHER_ASSIGNMENT = { id: 8, pa_number: '1726', employee_id: 3, employee_name: NAMES[2], team: TEAMS[2], schedule_rule_label: 'Ciclo 2 e 1', source: 'assignment', valid_from: '2026-09-01', valid_until: null, notes: null, presence: { status: 'remote', situacao: 'remoto', categoria: null, detalhe: null, parcial: null } }
// [F10-2] Times de rodizio: o Time 1 com padrao e sem membros (os blocos antigos
// da Escala continuam com "Editar" em todas as regras); POST muda o estado.
const rotationVersion = (id, effective_from, pattern, anchor_date, weeks = null) => {
  const onsite = pattern.filter((value) => value === 'onsite').length
  const counts = `${onsite}P/${pattern.length - onsite}R`
  return { id, team_id: 1, effective_from, pattern, anchor_date, weeks, label: weeks ? `Ciclo ${weeks} ${weeks === 1 ? 'semana' : 'semanas'}, ${counts}` : `Ciclo ${counts}` }
}
let rotationTeams = []
const restoreRotationTeams = () => {
  const version = rotationVersion(1, '2026-10-01', ['remote', 'onsite', 'onsite'], '2026-10-01')
  rotationTeams = [{ id: 1, name: 'Time 1', active: true, current_version: version, versions: [version], members: [] }]
}
restoreRotationTeams()

// [9.16] Papeis do Critico: os tecnicos 1 e 3 passam ao Critico so com
// useCriticalTeam(true) (os outros blocos continuam com N1 e N2); o POST muda o
// estado como o servidor (sem a regra: a previa vem pronta, em ordem).
// restoreCriticalRoles(true): o rodizio vigente tambem com o tecnico 2, que
// nao e do Critico (quem saiu), como o servidor devolve (invalid_people).
let criticalTeam = false
// [F10-3] Mapa PA com a 022: o PA dos vinculos fica flexivel com preferencia,
// e dois PAs reservados ao Critico (um com a pessoa do papel do dia, o outro
// vazio), com uma pessoa sem PA reservado. useSeatSetup(true) liga.
let seatSetup = false
// [F10-5] Escala do mes: publicada ou nao (monthlyPublished) e a fonte no Mapa PA.
let monthlyPublished = true
let monthlyOnMap = false
let monthlyPublishRefusal = null
const SEAT_RESERVED_OCCUPANT = { employee_id: 3, employee_name: NAMES[2], role_label: 'Presencial A 08:00–17:00' }
function paMapWithSeats(map) {
  return {
    ...map,
    seat_setup: true,
    critical_seating: { seats: 2, onsite: 2, without_seat: [{ employee_id: 1, employee_name: NAMES[0], role_label: null }] },
    pas: [
      ...map.pas.map((pa) => ({ ...pa, seat_type: 'flexible', can_lend: false, reserved_team: null, preference_ids: [1] })),
      { pa_number: '1723', display_order: 10, row_number: 2, column_number: 1, has_conflict: false, conflict_days: [], assignments: [], seat_type: 'fixed', can_lend: false, reserved_team: 'critico', preference_ids: [], reserved_occupant: SEAT_RESERVED_OCCUPANT },
      { pa_number: '1722', display_order: 11, row_number: 2, column_number: 2, has_conflict: false, conflict_days: [], assignments: [], seat_type: 'fixed', can_lend: false, reserved_team: 'critico', preference_ids: [], reserved_occupant: null },
    ],
  }
}
let criticalRoles = null
let vacantCriticalRoles = null
const restoreCriticalRoles = (withLeftPerson = false) => {
  criticalRoles = {
    today: DAY,
    roles: [
      { id: 1, name: 'Presencial A', presence_type: 'onsite', work_start: '08:00', work_end: '17:00', active: true, sort_order: 1 },
      { id: 2, name: 'Abertura home', presence_type: 'remote', work_start: '07:00', work_end: '17:00', active: true, sort_order: 2 },
    ],
    rotations: [{ id: 1, effective_from: '2026-10-26', period: 'week', anchor_date: '2026-10-26', roles: [1, 2], employees: withLeftPerson ? [1, 3, 2] : [1, 3], is_current: true, invalid_people: withLeftPerson ? 1 : 0 }],
    overrides: [],
    today_roles: { 1: { role_id: 1, source: 'rodizio' }, 3: { role_id: 2, source: 'rodizio' } },
    vacant_today: [],
    reasons: ['troca', 'cobertura', 'outro'],
  }
}
restoreCriticalRoles()
function criticalRolesResponse(body) {
  const action = body?.action
  if (action === 'save_role') {
    const id = Number(body.role_id) || criticalRoles.roles.length + 1
    const role = { id, name: body.name, presence_type: body.presence_type, work_start: body.work_start, work_end: body.work_end, active: body.active !== false, sort_order: Number(body.sort_order) || 0 }
    criticalRoles.roles = [...criticalRoles.roles.filter((item) => item.id !== id), role]
    return [200, { sucesso: true, mensagem: body.role_id ? 'Papel salvo.' : 'Papel criado.', role_id: id, created: !body.role_id }]
  }
  if (action === 'preview_rotation') {
    const people = body.employees ?? []
    const roles = body.roles ?? []
    const size = Math.max(people.length, roles.length)
    const preview = [0, 1].map((week) => ({
      date: week === 0 ? body.effective_from : '2026-11-09',
      roles: Object.fromEntries(people.map((id, index) => [String(id), roles[(index + week) % size] ?? null])),
      vacant: [],
    }))
    return [200, { sucesso: true, effective_from: body.effective_from, replaces: false, retroactive: false, extra_people: Math.max(0, people.length - roles.length), vacant_roles: Math.max(0, roles.length - people.length), preview }]
  }
  if (action === 'save_rotation') {
    const id = criticalRoles.rotations.length + 1
    criticalRoles.rotations = [...criticalRoles.rotations.map((item) => ({ ...item, is_current: false })), { id, effective_from: body.effective_from, period: body.period, anchor_date: body.anchor_date, roles: body.roles, employees: body.employees, is_current: true, invalid_people: 0 }]
    return [200, { sucesso: true, mensagem: 'Rodízio de papéis salvo.', rotation_id: id, replaced: false }]
  }
  if (action === 'add_override') {
    const id = 100 + criticalRoles.overrides.length
    criticalRoles.overrides = [...criticalRoles.overrides, { id, employee_id: body.employee_id, starts_on: body.starts_on, ends_on: body.ends_on, role_id: body.role_id, reason: body.reason }]
    return [200, { sucesso: true, mensagem: 'Troca de papel registrada.', override_id: id }]
  }
  if (action === 'remove_override') {
    criticalRoles.overrides = criticalRoles.overrides.filter((item) => item.id !== Number(body.override_id))
    return [200, { sucesso: true, mensagem: 'Troca de papel removida.', override_id: Number(body.override_id) }]
  }
  return [400, { sucesso: false, mensagem: 'Ação de papel do Crítico inválida.' }]
}
const rotationPreview = (body) => {
  const pattern = body?.cycle?.weeks ? body.cycle.weeks.flat() : body?.cycle?.pattern ?? []
  const days = []
  for (let date = new Date(`${body?.effective_from}T12:00:00Z`), position = 0; days.length < 20 && pattern.length; date.setUTCDate(date.getUTCDate() + 1)) {
    if (date.getUTCDay() === 0 || date.getUTCDay() === 6) continue
    days.push({ date: date.toISOString().slice(0, 10), presence_type: pattern[position++ % pattern.length] })
  }
  return days
}
function rotationResponse(body) {
  const team = rotationTeams.find((item) => item.id === Number(body?.team_id))
  if (body?.action === 'preview_version') {
    return [200, { sucesso: true, team_id: team?.id, effective_from: body.effective_from, label: 'Previa de teste', replaces: false, retroactive: false, members_changed: team?.members.length ?? 0, members_blocked: 0, members_inactive: 0, preview: rotationPreview(body) }]
  }
  if (body?.action === 'save_version' && team) {
    const pattern = body.cycle.weeks ? body.cycle.weeks.flat() : body.cycle.pattern
    const version = rotationVersion(team.versions.length + 1, body.effective_from, pattern, body.cycle.anchor_date, body.cycle.weeks?.length ?? null)
    team.versions = [...team.versions, version]
    team.current_version = version
    return [200, { sucesso: true, mensagem: 'Padrão do time salvo.', version_id: version.id, members_affected: team.members.length, members_changed: team.members.length, rules_written: team.members.length }]
  }
  if (body?.action === 'add_member' && team) {
    const member = { id: 100 + team.members.length, employee_id: Number(body.employee_id), valid_from: body.valid_from, valid_until: null, employee_active: true, is_current: body.valid_from <= DAY }
    team.members = [...team.members, member]
    return [200, { sucesso: true, mensagem: 'Técnico incluído no time.', member_id: member.id, rules_written: 1 }]
  }
  if (body?.action === 'end_member') {
    for (const item of rotationTeams) item.members = item.members.map((member) => (member.id === Number(body.member_id) ? { ...member, valid_until: body.valid_until } : member))
    return [200, { sucesso: true, mensagem: 'Saída do time registrada.', member_id: Number(body.member_id), cancelled: false }]
  }
  if (body?.action === 'save_team') {
    if (team) {
      team.name = body.name
      team.active = body.active !== false
      return [200, { sucesso: true, mensagem: 'Time salvo.', team_id: team.id, created: false, version: null }]
    }
    const id = rotationTeams.length + 1
    const version = body.version ? rotationVersion(1, body.version.effective_from, body.version.cycle.weeks ? body.version.cycle.weeks.flat() : body.version.cycle.pattern, body.version.cycle.anchor_date, body.version.cycle.weeks?.length ?? null) : null
    rotationTeams = [...rotationTeams, { id, name: body.name, active: true, current_version: version, versions: version ? [{ ...version, team_id: id }] : [], members: [] }]
    return [200, { sucesso: true, mensagem: 'Time criado.', team_id: id, created: true, version: null }]
  }
  return [400, { sucesso: false, mensagem: 'Ação de time de rodízio inválida.' }]
}
// [9.17] Controle de CHG: desligado por padrao (os outros blocos nao veem o
// menu). useChg({ on, canManage }) liga; os cards e o mover sao montados pelo
// PHP do projeto (ChgService::present e ChgRules::moveChanges no agora do
// DAY), entao a tela recebe o que o servidor devolveria. Dados ficticios.
let chgOn = false
let chgCanManage = false
let chgCanImport = false
let chgCards = null
const CHG_NOW = `${DAY} 12:00:00`
const chgBase = {
  solicitante: 'Solicitante Teste 01', tipo: 'padrao', versao: 'Versao Teste 01', versao_anterior: '', descricao: 'Descricao teste', link_planilha: 'https://planilha.teste/1',
  sugestao_at: '2026-10-29 20:00:00', service_status: 'aprovado', carteiras: [{ codigo: '9001', nome: 'Carteira Teste 01', pas: 12 }], locais: ['Local Teste 01'],
  chg_numero: 'CHG0000001', chg_status: 'aprovada', tecnico_id: 1, tecnico_nome: NAMES[0], tecnico_nome_legado: null, created_at: `${DAY} 08:00:00.000`, updated_at: `${DAY} 08:00:00.000`, row_version: 1,
  plans: [{ implantacao: 'CTASK0000001', backup: 'N/A', rollback: 'CTASK0000002', task_impl: 'pendente', task_bkp: 'pendente', task_roll: 'pendente' }], rollback: null,
}
const chgFixtures = () => [
  { id: 1, ...chgBase, sd_inicio: '2026-10-29 20:00:00', sd_fim: '2026-10-30 20:00:00', attempts: [{ attempt_no: 1, patch_status: null, reagendando: false, registered_at: null, endpoints: [] }] },
  { id: 2, ...chgBase, chg_numero: null, chg_status: null, tecnico_id: null, tecnico_nome: null, sd_inicio: '2026-10-30 20:00:00', sd_fim: '2026-10-31 20:00:00', attempts: [{ attempt_no: 1, patch_status: null, reagendando: false, registered_at: null, endpoints: [] }] },
  { id: 3, ...chgBase, sd_inicio: `${DAY} 08:00:00`, sd_fim: '2026-10-29 08:00:00', attempts: [{ attempt_no: 1, patch_status: null, reagendando: false, registered_at: null, endpoints: [{ url: 'https://endpoint.teste/3', start_at: `${DAY} 09:00:00`, start_date: null }] }] },
  { id: 4, ...chgBase, sd_inicio: '2026-10-27 08:00:00', sd_fim: '2026-10-28 08:00:00', attempts: [{ attempt_no: 1, patch_status: 'parcial', reagendando: false, registered_at: null, endpoints: [{ url: 'https://endpoint.teste/4', start_at: '2026-10-27 09:00:00', start_date: null }] }] },
]
// [Ajustes do CHG, layout] Um card por etapa com 3 carteiras, 3 locais, 3
// planos e 3 endpoints de links longos (e o rollback na execucao): o pior caso
// do quadro. useChg({ rich: true }). Dados ficticios.
const chgLong = (n) => `https://endpoint-de-teste-com-nome-comprido.exemplo.invalid/caminho/longo/sem-espaco/${n}`
const chgRich = {
  ...chgBase,
  descricao: 'Descricao de teste mais longa, com varias palavras para ocupar duas linhas no card do quadro',
  versao: 'servidor-de-teste/pasta-de-versoes/versao-de-teste-com-nome-comprido-2026.11.23',
  carteiras: [{ codigo: '9001', nome: 'Carteira Teste 01 com Nome Comprido', pas: 12 }, { codigo: '9002', nome: 'Carteira Teste 02', pas: 8 }, { codigo: '9003', nome: 'Carteira Teste 03 Segunda Fase', pas: 4 }],
  locais: ['Local Teste 01', 'Local Teste 02', 'Local Teste 03'],
  plans: [1, 2, 3].map((n) => ({ implantacao: `CTASK000010${n}`, backup: `CTASK000020${n}`, rollback: `CTASK000030${n}`, task_impl: 'pendente', task_bkp: 'pendente', task_roll: 'pendente' })),
  attempts: [{ attempt_no: 1, patch_status: null, reagendando: false, registered_at: null, endpoints: [1, 2, 3].map((n) => ({ url: chgLong(n), start_at: `${DAY} 0${n + 7}:00:00`, start_date: null })) }],
}
const chgRichFixtures = () => [
  { id: 11, ...chgRich, chg_status: 'pendente', sd_inicio: '2026-10-30 20:00:00', sd_fim: '2026-10-31 20:00:00' },
  { id: 12, ...chgRich, chg_status: 'pendente', stage_manual: 'prep', stage_manual_auto: 'comite', sd_inicio: '2026-10-30 20:00:00', sd_fim: '2026-10-31 20:00:00' },
  { id: 13, ...chgRich, sd_inicio: `${DAY} 08:00:00`, sd_fim: '2026-10-29 08:00:00', rollback: { url: chgLong('rollback'), start_at: `${DAY} 11:00:00`, status: 'em_andamento' } },
  { id: 14, ...chgRich, sd_inicio: '2026-10-27 08:00:00', sd_fim: '2026-10-28 08:00:00', attempts: [{ ...chgRich.attempts[0], patch_status: 'parcial' }] },
  { id: 15, ...chgRich, concluida: true, concluida_em: `${DAY} 10:00:00`, sd_inicio: '2026-10-27 08:00:00', sd_fim: '2026-10-28 08:00:00', attempts: [{ ...chgRich.attempts[0], patch_status: 'sucesso' }] },
  { id: 16, ...chgRich, chg_status: 'cancelada', sd_inicio: '2026-10-30 20:00:00', sd_fim: '2026-10-31 20:00:00' },
]
let chgUseRich = false
function chgPhp(script, input) {
  const code = `require 'security.php'; require 'services/ChgService.php'; $s = (new ReflectionClass('ChgService'))->newInstanceWithoutConstructor(); $now = new DateTimeImmutable('${CHG_NOW}'); $in = json_decode(stream_get_contents(STDIN), true); ${script}`
  return JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['-r', code], { cwd: projectRoot, encoding: 'utf8', input: JSON.stringify(input), stdio: ['pipe', 'pipe', 'ignore'] }))
}
const chgPresent = (cards) => chgPhp('echo json_encode(array_map(fn ($c) => $s->present($c, $now), $in));', cards)
function chgResponse(method, body) {
  if (!chgOn) return [404, { sucesso: false, mensagem: 'Recurso não encontrado.' }]
  chgCards ??= chgPresent(chgUseRich ? chgRichFixtures() : chgFixtures())
  if (method === 'GET') return [200, { sucesso: true, now: CHG_NOW, cards: chgCards, catalogos: { carteiras: [{ id: 1, codigo: '9001', nome: 'Carteira Teste 01', ativo: true }], locais: [{ id: 1, nome: 'Local Teste 01', ativo: true }] }, tecnicos: each((nome, id) => ({ id, nome })), can_manage: chgCanManage, can_import: chgCanImport }]
  if (!chgCanManage) return [403, { sucesso: false, mensagem: 'Somente administradores, Liderança e o grupo Tools alteram o Controle de CHG.' }]
  const card = chgCards.find((item) => item.id === Number(body?.id))
  if (body?.action === 'move' && card) {
    if (Number(body.row_version) !== card.row_version) return [409, { sucesso: false, mensagem: 'Alguém alterou este card; recarregue.' }]
    const moved = chgPhp("$c = ChgRules::card($in['card']); $ch = ChgRules::moveChanges($c, $in['stage'], $now) ?? []; $c = $ch + $c; $c['row_version']++; echo json_encode($s->present($c, $now));", { card, stage: body.stage })
    chgCards = chgCards.map((item) => (item.id === card.id ? moved : item))
    return [200, { sucesso: true, card: moved }]
  }
  return [200, { sucesso: true, mensagem: 'ok', card }]
}

const summaryValue = { overtime_approved_minutes: 120, overtime_pending_minutes: 60, adjustments: 2, oncall: 1, onsite_days: 10, remote_days: 8 }

const GET = {
  'session.php': { sucesso: true, csrf_token: 'teste', role: 'admin', permissions: ADMIN_PERMISSIONS, ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' }, gestor: { autenticado: true, admin: true, username: 'admin.qa', role: 'admin' } },
  // Cadastros variados de proposito: abrir e fechar sem mexer nao pode contar como sujo.
  'listar_funcionarios.php': { sucesso: true, funcionarios: each((nome, id, equipe) => ({ id, nome, equipe, ativo: true, jornada_entrada: id === 3 ? '08:00:00' : '08:00', jornada_saida: '17:00', almoco_inicio: '12:00', almoco_fim: '13:00', ad_login: id === 3 ? null : `tecnico.${id}`, access_role: id === 4 ? 'somente_leitura' : 'tecnico', arquivado_em: null, fora_mapa_escala: id === 2, grupo_escala: id === 4 ? 'separado' : 'comum' })) },
  'portal/absences.php': { sucesso: true, limit: 200, truncated: false, items: each((employee_name, id, team) => ({ id, employee_id: id, employee_name, team, ...absencePeriods[id - 1], category: 'folga', status: 'approved', retroactive: 0 })) },
  'portal/oncall.php': { sucesso: true, items: [] },
  'portal/reports.php': { sucesso: true, summary: { overtime_approved_minutes: 0, overtime_pending_minutes: 0, adjustments_count: 0, oncall_count: 0, onsite_days: 0, remote_days: 0, pending: 0, rejected: 0, sync_errors: 0 }, by_employee: { [NAMES[0]]: summaryValue }, by_team: { N1: summaryValue } },
  'portal/critical_incidents.php': { sucesso: true, limit: 200, truncated: false, summary: {}, items: incidents },
  'portal/sdk_responsibles.php': { sucesso: true, items: [] },
  'portal/technicians.php': { sucesso: true, server_now: `${DAY}T10:00:00-03:00`, presence_meta: { disponivel: true, pausas_disponiveis: true }, items: [] },
  'portal/pa_map.php': { sucesso: true, server_now: `${DAY}T10:00:00-03:00`, week: { from: '2026-10-26', to: '2026-10-30' }, can_manage: true, employees: [{ id: 1, name: NAMES[0], team: TEAMS[0], schedule_rule_label: 'Sempre presencial' }, { id: 3, name: NAMES[2], team: TEAMS[2], schedule_rule_label: 'Ciclo 2 e 1' }], pas: [{ pa_number: PA_ASSIGNMENT.pa_number, display_order: 1, row_number: 1, column_number: 1, has_conflict: false, conflict_days: [], assignments: [PA_ASSIGNMENT, PA_OTHER_ASSIGNMENT] }], absences_week: [] },
  'status.php': { n1: [], n2: [], server_now: `${DAY}T10:00:00-03:00`, presenca_disponivel: true },
  'portal/notifications.php': { sucesso: true, items: [], unread: 0 },
  // Os campos do api/configuracoes.php real: a tela de Configuracoes manda o objeto carregado.
  'configuracoes.php': { sucesso: true, configuracoes: { limite_pausa_por_equipe: 2, duracao_pausa_minutos: 20, alerta_15minutos: true, alerta_20minutos: true, local_admin_enabled: false, local_password_change_allowed: false } },
}

// [Escala do Crítico em grade] A grade fictícia (critical-grid-fixture.mjs):
// quem tem escalas.manage recebe a versão da Liderança. gridApplyRefusal: o
// próximo apply responde com ele (409 de versão velha, por exemplo).
let gridApplyRefusal = null
// Atraso da próxima prévia (ms): a resposta chega depois de trocar a opção.
let gridPreviewDelay = 0
const gridHolidays = [{ id: 30, date: '2026-11-20', name: 'Feriado Ficticio', scope: 'todos', row_version: 1 }]
function criticalGridResponse(method, key, body) {
  const permissions = GET['session.php'].permissions || []
  if (key === 'portal/holidays.php') {
    if (method === 'GET') return [200, { sucesso: true, year: 2026, can_manage: permissions.includes('feriados.manage'), items: gridHolidays }]
    return permissions.includes('feriados.manage') ? [200, { sucesso: true, mensagem: 'Feriado salvo.', id: 31, row_version: 1, created: true }] : [403, { sucesso: false, mensagem: 'Apenas administradores e Liderança alteram os feriados.' }]
  }
  if (method === 'GET') return [200, criticalGridFixture({ manager: permissions.includes('escalas.manage') })]
  if (!permissions.includes('escalas.manage')) return [403, { sucesso: false, mensagem: 'Apenas administradores e Liderança alteram a escala do Crítico.' }]
  if (body?.action === 'preview') return [200, criticalGridPreview(body)]
  if (body?.action === 'apply') {
    if (gridApplyRefusal) { const refusal = gridApplyRefusal; gridApplyRefusal = null; return refusal }
    return [200, { ...criticalGridPreview(body), mensagem: 'Escala do dia salva.' }]
  }
  return [400, { sucesso: false, mensagem: 'Ação da Escala do Crítico inválida.' }]
}

function monthlyResponse(method, search, body) {
  const permissions = GET['session.php'].permissions || []
  const month = search.get('mes') || '2026-12'
  if (method === 'GET') return [200, monthlyRead({ month, published: monthlyPublished && month === '2026-12', manager: permissions.includes('escala_mensal.publish') })]
  if (!permissions.includes('escala_mensal.publish')) return [403, { sucesso: false, mensagem: 'Apenas administradores e Liderança geram e publicam a escala do mês.' }]
  if (body?.action === 'preview') return [200, monthlyPreview(body)]
  if (body?.action === 'publish') {
    if (monthlyPublishRefusal) { const refusal = monthlyPublishRefusal; monthlyPublishRefusal = null; return refusal }
    return [200, { sucesso: true, mensagem: 'Escala do mês publicada.', revision: body.expected_revision + 1 }]
  }
  return [400, { sucesso: false, mensagem: 'Ação da escala do mês inválida.' }]
}

function apiResponse(method, key, search, body) {
  if (key === 'status.php' && method === 'GET') return [200, pausedStatus()]
  if (key === 'portal/critical_grid.php' || key === 'portal/holidays.php') return criticalGridResponse(method, key, body)
  if (key === 'portal/monthly_schedule.php') return monthlyResponse(method, search, body)
  if (key === 'portal/monthly_schedule_import.php') return (GET['session.php'].permissions || []).includes('escala_mensal.import') ? [200, MONTHLY_IMPORT_PREVIEW] : [403, { sucesso: false, mensagem: 'Somente administradores importam a planilha Base.' }]
  if (key === 'portal/chg.php') return chgResponse(method, body)
  // [9.17.7] Importar: so contagens, como o servidor.
  if (key === 'portal/chg_import.php') return chgCanImport ? [200, { sucesso: true, mensagem: 'Importação concluída.', contagens: { novos: (body?.dados?.items ?? []).length, atualizados: 0, iguais: 0 }, campos_descartados: {} }] : [403, { sucesso: false, mensagem: 'Somente administradores importam do Controle v5.' }]
  if (key === 'session.php' && method === 'GET' && chgOn) return [200, { ...GET[key], modulos: { chg: true } }]
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
    // [Lote NC] Leitura do NC do formulario (so leitura); NC ficticio.
    if (method === 'GET' && search.get('nc') === '1') return [200, { sucesso: true, employee_id: Number(search.get('employee_id') || 1), nc: '900000001', nc_required: false }]
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
  // [pos-deploy 1, D3] Criacao: o servidor gera o ID e o devolve em funcionario.id.
  if (key === 'adicionar_funcionario.php' && method === 'POST') return [200, { sucesso: true, mensagem: 'Funcionário adicionado.', funcionario: { id: 334 } }]
  if (key === 'portal/absences.php' && method === 'POST' && body?.note === FAILING_ABSENCE_NOTE) return [409, { sucesso: false, mensagem: 'Falha simulada do servidor.' }]
  if (key === 'portal/schedules.php') {
    if (method === 'GET') {
      const from = search.get('from') || ''
      const to = search.get('to') || ''
      const employeeId = search.get('employee_id') || ''
      if (scheduleLookupRefusal && from && from === to && employeeId) return scheduleLookupRefusal
      const exceptions = scheduleExceptions.filter((item) => (!from || item.exception_date >= from) && (!to || item.exception_date <= to) && (!employeeId || String(item.employee_id) === employeeId))
      return [200, { sucesso: true, period: { from, to }, rules: scheduleRules.filter((rule) => !removedRules.has(rule.employee_id)), exceptions, generated: generatedDays }]
    }
    // Como o servidor (9.12.4, KK6): na data ja gravada, note ausente mantem a observacao.
    if (body?.action === 'exception') {
      const index = scheduleExceptions.findIndex((item) => String(item.employee_id) === String(body.employee_id) && item.exception_date === body.exception_date)
      const previous = index >= 0 ? scheduleExceptions[index] : null
      const note = Object.hasOwn(body, 'note') ? (String(body.note ?? '').trim() || null) : (previous?.note ?? null)
      const saved = { id: previous?.id ?? 900 + scheduleExceptions.length, employee_id: Number(body.employee_id), exception_date: body.exception_date, exception_type: body.exception_type, note }
      if (index >= 0) scheduleExceptions[index] = saved
      else scheduleExceptions.push(saved)
      return [200, { sucesso: true, id: saved.id, mensagem: 'Excecao de escala salva.' }]
    }
    if (body?.action === 'rule_preview') return [200, { sucesso: true, rule_label: 'Previa de teste', preview: [] }]
    if (['rule', 'remove_rule'].includes(body?.action) && body?.employee_id === FAILING_RULE_EMPLOYEE) return [409, { sucesso: false, mensagem: 'Falha simulada do servidor.' }]
    if (body?.action === 'remove_rule') removedRules.add(body.employee_id)
  }
  if (key === 'portal/pa_map.php' && method === 'GET') {
    const map = GET['portal/pa_map.php']
    const current = { ...map, pas: map.pas.map((pa) => ({ ...pa, assignments: pa.assignments.filter((item) => !removedPaAssignments.has(item.id)) })) }
    const withSeats = seatSetup ? paMapWithSeats(current) : current
    if (!monthlyOnMap) return [200, withSeats]
    return [200, { ...withSeats, monthly_published: true, pas: withSeats.pas.map((pa, index) => ({ ...pa, monthly_occupant: index === 0 ? { employee_id: 801, employee_name: 'Alice Ficticia', source: 'engine', absent_now: false } : null })) }]
  }
  if (key === 'portal/pa_map.php' && method === 'POST' && body?.action === 'inventory_save') {
    if (body.reserved_team === 'critico' && body.pa_number === PA_ASSIGNMENT.pa_number) return [409, { sucesso: false, mensagem: 'PA com 2 vínculo(s) vigente(s) ou futuro(s): encerre antes de reservar ao Crítico.' }]
    return [200, { sucesso: true, pa_number: body.pa_number, changed: ['seat_type'], mensagem: 'Inventário do PA salvo.' }]
  }
  if (key === 'portal/pa_map.php' && method === 'POST' && body?.action === 'preferences_save') {
    return [200, { sucesso: true, pa_number: body.pa_number, total: (body.employee_ids || []).length, mensagem: 'Preferências do PA salvas.' }]
  }
  if (key === 'portal/pa_map.php' && method === 'POST' && body?.action === 'remove') {
    if (paRemoveRefusal) return paRemoveRefusal
    if (removedPaAssignments.has(body.id)) return [409, { sucesso: false, mensagem: 'Vínculo de PA não encontrado.' }]
    removedPaAssignments.add(body.id)
    return [200, { sucesso: true, mensagem: 'ok' }]
  }
  if (key === 'portal/pa_map.php' && method === 'POST' && body?.action === 'save') {
    if (body.notes === FAILING_PA_NOTE) return [409, { sucesso: false, mensagem: 'Falha simulada do servidor.' }]
    // Como o servidor real: fim antes do inicio da 400 (a tela confere antes e nao chega a mandar).
    if (body.valid_until && body.valid_from && body.valid_until < body.valid_from) return [400, { sucesso: false, mensagem: PA_END_BEFORE_START }]
    return [200, { sucesso: true, id: body.id || 99, warnings: [], conflicts: [], conflicts_more: 0 }]
  }
  if (key === 'portal/critical_roles.php') {
    if (method === 'GET') return [200, { sucesso: true, ...criticalRoles }]
    return criticalRolesResponse(body)
  }
  if (key === 'listar_funcionarios.php' && method === 'GET' && criticalTeam) {
    return [200, { ...GET[key], funcionarios: GET[key].funcionarios.map((employee) => (employee.id === 1 || employee.id === 3 ? { ...employee, equipe: 'critico', grupo_escala: 'separado' } : employee)) }]
  }
  if (key === 'portal/dashboard.php' && method === 'GET' && vacantCriticalRoles) {
    return [200, { ...(GET[key] ?? { sucesso: true }), papeis_vagos_critico: vacantCriticalRoles }]
  }
  if (key === 'portal/rotation_teams.php') {
    if (method === 'GET') return [200, { sucesso: true, today: DAY, teams: rotationTeams }]
    return rotationResponse(body)
  }
  if (key === 'logout.php') return [200, { sucesso: true, csrf_token: 'teste' }]
  if (method === 'POST') return [200, { sucesso: true, mensagem: 'ok' }]
  return [200, GET[key] ?? { sucesso: true, items: [] }]
}

const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' }

// Sessao de Lideranca: sem as permissoes so de admin (por exemplo, funcionarios.fora_mapa).
const LEADERSHIP_SESSION = { sucesso: true, csrf_token: 'teste', role: 'lideranca', permissions: ['portal.read', 'pausas.use', 'metricas.read', 'relatorios.read', 'documentacao.edit', 'avisos.manage', 'operacao.approve', 'ausencias.manage', 'funcionarios.manage', 'escalas.manage', 'pa_map.manage', 'feriados.manage', 'escala_mensal.publish'], ci: { autenticado: false, funcionario_id: 0, nome: '', username: '' }, gestor: { autenticado: true, admin: false, username: 'lider.qa', role: 'lideranca' } }
const ADMIN_SESSION = GET['session.php']
// [R4a] Sessao de tecnico (CI), para iniciar a propria pausa na tela de Pausas.
const TECHNICIAN_SESSION = { sucesso: true, csrf_token: 'teste', role: 'tecnico', permissions: ['portal.read', 'pausas.use'], ci: { autenticado: true, funcionario_id: 3, nome: NAMES[2], username: 'tecnico.3' }, gestor: { autenticado: false, admin: false, username: '', role: null } }

// Sobe o servidor numa porta livre; devolve { port, requests, restoreRotationTeams, restoreCriticalRoles, useCriticalTeam, setVacantCriticalRoles, useLeadershipSession, useAdminSession, useTechnicianSession, restoreRules, keepOnlyRule, decideElsewhere, refuseDecision, restoreDecisions, setDecisionDelay, setPausedEmployee, refuseForceEnd, restoreDocuments, removeDocumentElsewhere, refuseDocument, restorePaAssignments, refusePaRemove, setScheduleExceptions, scheduleExceptions, refuseScheduleLookup, close }.
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
        requests.push({ method: req.method, key, body, query: Object.fromEntries(url.searchParams) })
        const [status, payload] = apiResponse(req.method, key, url.searchParams, body)
        setTimeout(() => {
          res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' })
          res.end(JSON.stringify(payload))
        }, body?.action === 'decision' || ['portal/admin_force_end_break.php', 'portal/documents_delete.php'].includes(key) ? decisionDelay : key === 'portal/critical_grid.php' && body?.action === 'preview' ? gridPreviewDelay : 0)
      })
      return
    }
    const relative = normalize(decodeURIComponent(url.pathname.replace(/^\/app\/?/, ''))).replace(/^(\.\.[/\\])+/, '')
    let file = join(appDir, relative)
    // [Marca] Como o Apache: arquivo que existe em app/ (assets e os ícones do public/) é
    // entregue; o resto de /app/ (rotas do SPA) cai no index.html.
    if (!url.pathname.startsWith('/app/') || !existsSync(file) || statSync(file).isDirectory()) file = join(appDir, 'index.html')
    res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' })
    res.end(readFileSync(file))
  })
  return new Promise((resolve, reject) => {
    server.once('error', reject)
    server.listen(0, '127.0.0.1', () => resolve({ port: server.address().port, requests, useChg: ({ on = false, canManage = false, canImport = false, rich = false } = {}) => { chgOn = on; chgCanManage = canManage; chgCanImport = canImport; chgUseRich = rich; chgCards = null }, restoreRotationTeams, restoreCriticalRoles, useCriticalTeam: (on) => { criticalTeam = on }, useSeatSetup: (on) => { seatSetup = on }, useMonthly: ({ published = true, onMap = false } = {}) => { monthlyPublished = published; monthlyOnMap = onMap }, refuseMonthlyPublish: (status, mensagem) => { monthlyPublishRefusal = status ? [status, { sucesso: false, mensagem }] : null }, setVacantCriticalRoles: (items) => { vacantCriticalRoles = items }, useLeadershipSession: () => { GET['session.php'] = LEADERSHIP_SESSION }, useAdminSession: () => { GET['session.php'] = ADMIN_SESSION }, useTechnicianSession: () => { GET['session.php'] = TECHNICIAN_SESSION }, refuseGridApply: (status, mensagem) => { gridApplyRefusal = status ? [status, { sucesso: false, mensagem }] : null }, setGridPreviewDelay: (ms) => { gridPreviewDelay = ms }, setStatusEmployees: (value) => { extraStatus = value }, restoreRules: () => removedRules.clear(), keepOnlyRule: (employeeId) => { for (const rule of scheduleRules) if (rule.employee_id !== employeeId) removedRules.add(rule.employee_id) }, decideElsewhere: (key, id) => decisions.set(`${key}:${id}`, 'approved'), refuseDecision: (key, id, status, mensagem) => refusals.set(`${key}:${id}`, [status, { sucesso: false, mensagem }]), restoreDecisions: () => { decisions.clear(); refusals.clear(); decidedPauses.clear(); decisionDelay = 0 }, setPausedEmployee: (value) => { pausedEmployee = value }, refuseForceEnd: (status, mensagem) => { forceEndRefusal = status ? [status, { sucesso: false, mensagem }] : null }, restoreDocuments: () => { removedDocuments.clear(); documentRefusal = null }, removeDocumentElsewhere: (id) => removedDocuments.add(id), refuseDocument: (status, mensagem) => { documentRefusal = status ? [status, { sucesso: false, mensagem }] : null }, setScheduleExceptions: (items) => { scheduleExceptions = items.map((item) => ({ ...item })); scheduleLookupRefusal = null }, scheduleExceptions: () => scheduleExceptions, refuseScheduleLookup: (status, mensagem) => { scheduleLookupRefusal = status ? [status, { sucesso: false, mensagem }] : null }, restorePaAssignments: () => { removedPaAssignments.clear(); paRemoveRefusal = null }, refusePaRemove: (status, mensagem) => { paRemoveRefusal = status ? [status, { sucesso: false, mensagem }] : null }, setDecisionDelay: (ms) => { decisionDelay = ms }, close: () => new Promise((done) => server.close(done)) }))
  })
}

if (process.argv[1] && process.argv[1].endsWith('mock-server.mjs')) {
  const { port } = await startMockServer(process.argv[2])
  console.log(`servidor simulado em http://127.0.0.1:${port}/app/`)
}
