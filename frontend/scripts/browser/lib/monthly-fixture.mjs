// [F10-5] Dados fictícios do GET/POST monthly_schedule.php (dezembro de 2026,
// 6 pessoas fictícias), no formato do MonthlyScheduleService. Usado pelo
// qa:monthly-schedule, pelo servidor simulado do roteiro de navegador e pelas
// capturas. Nenhum nome real.

const PEOPLE = [
  { id: 801, name: 'Alice Ficticia', team: 'n1' },
  { id: 802, name: 'Bruno Ficticio', team: 'n1' },
  { id: 803, name: 'Clara Ficticia', team: 'n2' },
  { id: 804, name: 'Davi Ficticio', team: 'n2' },
  { id: 805, name: 'Elisa Ficticia', team: 'n1' },
  { id: 806, name: 'Fabio Critico', team: 'critico' },
]
const TEAM_OF = { 801: 'Time 1', 802: 'Time 2', 805: 'Time 3' }

function businessDays(month) {
  const days = []
  const date = new Date(`${month}-01T12:00:00Z`)
  while (date.toISOString().slice(0, 7) === month) {
    const weekday = date.getUTCDay()
    if (weekday >= 1 && weekday <= 5) days.push(date.toISOString().slice(0, 10))
    date.setUTCDate(date.getUTCDate() + 1)
  }
  return days
}

// Rodízio fictício de 3 dias (um time em home por dia) e PAs 1721-1724.
function compute(month, from) {
  const days = businessDays(month).filter((day) => !from || day >= from)
  const cells = {}
  const summary = {}
  days.forEach((day, index) => {
    const homeTeam = `Time ${(index % 3) + 1}`
    let pa = 1721
    let onsite = 0
    let remote = 0
    let n1 = 0
    let n2 = 0
    const homeByTeam = {}
    for (const person of PEOPLE) {
      if (person.team === 'critico') {
        cells[`${person.id}:${day}`] = { situation: 'onsite', pa: '1732', pa_source: 'reserved', no_pa: false, moved: false }
        continue
      }
      let situation = TEAM_OF[person.id] === homeTeam ? 'remote' : 'onsite'
      if (person.id === 804 && day >= '2026-12-14' && day <= '2026-12-18') situation = 'absent'
      const noPa = person.id === 803 && day === '2026-12-10'
      const seat = situation === 'onsite' && !noPa ? String(pa++) : null
      cells[`${person.id}:${day}`] = { situation, pa: seat, pa_source: seat ? 'engine' : null, no_pa: noPa, moved: person.id === 802 && day === '2026-12-08' }
      if (situation === 'onsite') { onsite += 1; if (person.team === 'n1') n1 += 1; if (person.team === 'n2') n2 += 1 }
      if (situation === 'remote') { remote += 1; homeByTeam[TEAM_OF[person.id]] = (homeByTeam[TEAM_OF[person.id]] ?? 0) + 1 }
    }
    const noPa = day === '2026-12-10' ? 1 : 0
    summary[day] = { onsite, remote, absent: day >= '2026-12-14' && day <= '2026-12-18' ? 1 : 0, n1, n2, hybrid: 0, critical: 1, home_by_team: homeByTeam,
      percent_onsite: onsite + remote === 0 ? null : Math.round((100 * onsite) / (onsite + remote)), capacity: 3, reserved_seats: 2, moves: day === '2026-12-08' ? 1 : 0, no_pa: noPa,
      status: noPa ? ['PA INSUFICIENTE'] : ['OK'] }
  })
  return { days, cells, summary }
}

export function monthlyRead({ month = '2026-12', published = true, manager = true, admin = false, divergence = true } = {}) {
  const { days, cells, summary } = compute(month)
  if (published && divergence) cells[`801:2026-12-02`] = { ...cells['801:2026-12-02'], divergence: 'ausente_com_pa' }
  return {
    sucesso: true,
    mes: month,
    label: month === '2026-12' ? 'Dezembro 2026' : 'Janeiro 2027',
    prev: month === '2026-12' ? '2026-11' : '2026-12',
    next: month === '2026-12' ? '2027-01' : '2027-02',
    today: '2026-12-02',
    days: businessDays(month),
    holidays: ['2026-12-25'],
    current_revision: published ? 1 : 0,
    row_version: published ? 2 : 0,
    revisions: published ? [{ revision: 1, from_date: `${month}-01`, reason: 'publicacao', by: 'qa.lider', at: '2026-11-26 10:00:00' }] : [],
    people: published ? PEOPLE : [],
    cells: published ? cells : {},
    summary: published ? summary : {},
    divergences: published && divergence ? 1 : 0,
    can_publish: manager,
    can_lock: manager,
    earliest_from: '2026-12-03',
    ...(admin ? {} : {}),
  }
}

export function monthlyPreview(body) {
  const { days, cells, summary } = compute(body?.mes ?? '2026-12', body?.from)
  const published = body?.mes === '2026-12'
  return {
    sucesso: true,
    mes: body?.mes ?? '2026-12',
    from_date: body?.from ?? days[0],
    days,
    cells,
    summary,
    people: PEOPLE,
    counts: { dias: days.length, linhas: Object.keys(cells).length, pessoas: PEOPLE.length, presenciais: Object.values(cells).filter((c) => c.situation === 'onsite').length, com_pa: 0, sem_pa: 1, trocas: 1, ok: days.length - 1, pa_insuficiente: 1, atencao_config: 0 },
    input_hash: 'a'.repeat(64),
    engine_version: 'f10-pa-2',
    current_revision: published ? 1 : 0,
    row_version: published ? 2 : 0,
    changed_cells: published ? 4 : 0,
  }
}

export const MONTHLY_IMPORT_PREVIEW = {
  sucesso: true,
  acao: 'preview',
  a_partir: '2027-01-04',
  contagens: { times: { muda: 3, novo: 9 }, regras: { novo: 3 }, excecoes: { novo: 2 }, inventario: { novo: 6, igual: 9, conflito: 1 }, virada: { novo: 2 } },
  itens: [
    { passo: 'inventario', tipo: 'pa', situacao: 'conflito', ref: 'CONFIG PA item 3', pa: '1730', motivo: 'hibrido_opcao_c' },
    { passo: 'times', tipo: 'pessoa', situacao: 'nao_casou', ref: 'EQUIPE item 12' },
  ],
}
