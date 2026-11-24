// [Escala do Crítico em grade] Dados fictícios do GET critical_grid.php
// (novembro de 2026, 5 pessoas fictícias, rodízio semanal com Presencial x2),
// no formato do CriticalGridService::month. Usado pelo qa:critical-grid, pelo
// servidor simulado do roteiro de navegador e pelas capturas. Nenhum nome real.
// manager: true = Liderança (categoria da ausência e o que desfazer);
// false = quem só vê (D-ESC-5).

const ROLES = [
  { id: 1, name: 'Presencial', presence_type: 'onsite', work_start: '08:00', work_end: '18:00', active: true, color_token: 'azul', label: 'Presencial 08:00–18:00' },
  { id: 2, name: 'Abertura home', presence_type: 'remote', work_start: '07:00', work_end: '17:00', active: true, color_token: 'verde', label: 'Abertura home 07:00–17:00' },
  { id: 3, name: 'Fechamento home', presence_type: 'remote', work_start: '10:30', work_end: '20:30', active: true, color_token: 'pessego', label: 'Fechamento home 10:30–20:30' },
  { id: 4, name: 'Home', presence_type: 'remote', work_start: '08:00', work_end: '18:00', active: true, color_token: 'lilas', label: 'Home 08:00–18:00' },
]
const SLOTS = [1, 1, 2, 3, 4]
const PEOPLE = [
  { id: 901, short_name: 'Ana F.', name: 'Ana Ficticia' },
  { id: 902, short_name: 'Bruno', name: 'Bruno Ficticio' },
  { id: 903, short_name: 'Carla', name: 'Carla Ficticia' },
  { id: 904, short_name: 'Diego', name: 'Diego Ficticio' },
  { id: 905, short_name: 'Ana R.', name: 'Ana Ruiz Ficticia' },
]
const WEEKS = ['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30']

function addDays(date, days) {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

function roleCell(role, source = 'rodizio', by = 'qa.rodizio') {
  return {
    kind: 'papel', label: role.label, tone: `papel-${role.color_token || 'areia'}`, source, marker: source === 'troca' ? 'troca' : null,
    presence_type: role.presence_type, hours: `${role.work_start}–${role.work_end}`, detail: null, role_id: role.id,
    last_change: { by, at: '2026-11-01 09:00:00' },
  }
}

export function criticalGridFixture({ manager = true, today = '2026-11-19', version = 'v1' } = {}) {
  const cells = {}
  const summary = []
  for (const [position, person] of PEOPLE.entries()) {
    const row = { employee_id: person.id, roles: {}, home: 0, onsite: 0, absent: 0, holiday: 0 }
    for (const [k, monday] of WEEKS.entries()) {
      const role = ROLES.find((item) => item.id === SLOTS[(position + k) % SLOTS.length])
      for (let day = 0; day < 5; day++) {
        const date = addDays(monday, day)
        let cell = roleCell(role)
        let refs = {}
        if (date === '2026-11-20') {
          cell = { kind: 'feriado', label: 'Feriado', tone: 'feriado', source: 'feriado', marker: null, presence_type: null, hours: null, detail: 'Feriado: Feriado Ficticio', role_id: null, last_change: null }
        } else if (person.id === 901 && (date === '2026-11-18' || date === '2026-11-19')) {
          cell = { kind: 'ausencia', label: 'Banco de horas', tone: 'banco', source: 'ausencia', marker: null, presence_type: null, hours: null, detail: null, role_id: null, last_change: { by: 'qa.lider', at: '2026-11-10 10:00:00' } }
          refs = { ausencia: { id: 70, starts_on: '2026-11-18', ends_on: '2026-11-19' } }
        } else if (person.id === 902 && date === '2026-11-23') {
          cell = { kind: 'ausencia', label: 'Férias', tone: 'ferias', source: 'ausencia', marker: null, presence_type: null, hours: null, detail: null, role_id: null, last_change: { by: 'qa.lider', at: '2026-11-10 10:00:00' } }
          refs = { ausencia: { id: 71, starts_on: '2026-11-23', ends_on: '2026-11-23' } }
        } else if (person.id === 903 && date === '2026-11-24') {
          cell = { kind: 'ausencia', label: manager ? 'Folga' : 'Ausente', tone: 'ausente', source: 'ausencia', marker: null, presence_type: null, hours: null, detail: null, role_id: null, last_change: { by: 'qa.lider', at: '2026-11-10 10:00:00' } }
          refs = { ausencia: { id: 72, starts_on: '2026-11-24', ends_on: '2026-11-24' } }
        } else if (person.id === 905 && date === '2026-11-25') {
          cell = { kind: 'excecao', label: 'Home (exceção)', tone: 'papel-lilas', source: 'excecao', marker: 'excecao', presence_type: 'remote', hours: '08:00–18:00', detail: null, role_id: null, last_change: { by: 'qa.lider', at: '2026-11-11 10:00:00' } }
          refs = { excecao: { id: 80, date } }
        } else if (person.id === 903 && date === '2026-11-26') {
          cell = roleCell(ROLES[1], 'troca', 'qa.troca')
          refs = { troca: { id: 90, starts_on: '2026-11-26', ends_on: '2026-11-26' } }
        } else if (person.id === 904 && date === '2026-11-17') {
          cell = { ...roleCell(role), detail: manager ? 'Ausente 14:00–16:00 (Compromisso)' : 'Ausente 14:00–16:00' }
        }
        const inMonth = date.slice(0, 7) === '2026-11'
        cells[`${person.id}:${date}`] = { ...cell, version: `${version}-${person.id}-${date}`, in_month: inMonth, refs: manager ? refs : {} }
        if (!inMonth) continue
        if (cell.kind === 'papel') row.roles[String(cell.role_id)] = (row.roles[String(cell.role_id)] ?? 0) + 1
        if (cell.presence_type === 'remote') row.home++
        else if (cell.presence_type === 'onsite') row.onsite++
        else if (cell.kind === 'ausencia') row.absent++
        else if (cell.kind === 'feriado') row.holiday++
      }
    }
    summary.push({ ...row, onsite_pct: row.onsite + row.home === 0 ? null : Math.round(100 * row.onsite / (row.onsite + row.home)) })
  }
  return {
    sucesso: true,
    month: { key: '2026-11', label: 'Novembro 2026', prev: '2026-10', next: '2026-12' },
    today,
    can_manage: manager,
    roles: ROLES,
    weeks: WEEKS.map((start, index) => ({ iso: 45 + index, start, days: [0, 1, 2, 3, 4].map((day) => addDays(start, day)), current: today >= start && today <= addDays(start, 6) })),
    people: PEOPLE,
    cells,
    summary,
    absence_choices: manager ? [['ferias', 'Férias'], ['banco_horas', 'Banco de horas'], ['folga', 'Folga'], ['ausencia_justificada', 'Ausência justificada'], ['compromisso', 'Compromisso'], ['outro', 'Outro']].map(([value, label]) => ({ value, label })) : [],
    exception_choices: manager ? [['onsite', 'Presencial (exceção)'], ['remote', 'Home (exceção)'], ['training', 'Treinamento']].map(([value, label]) => ({ value, label })) : [],
  }
}

// Prévia fictícia do POST preview (o formato do CriticalGridService::preview).
export function criticalGridPreview(body) {
  const label = body?.change?.type === 'absence' ? 'Banco de horas' : body?.change?.type === 'exception' ? 'Presencial (exceção)' : 'Presencial 08:00–18:00'
  return {
    sucesso: true,
    changes: [{ date: body?.from || '2026-11-23', before: 'Abertura home 07:00–17:00', after: label, text: `Seg 23/11: Abertura home 07:00–17:00 → ${label}` }],
    warnings: body?.employee_id === 905 ? [{ date: '2026-11-27', text: 'Sex 27/11: o Crítico fica sem ninguém disponível.' }] : [],
  }
}
