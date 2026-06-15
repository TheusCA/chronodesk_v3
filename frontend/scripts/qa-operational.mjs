import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  competencyFor,
  CRITICAL_INCIDENT_IMPORT_LIMITS,
  IMPORT_LIMITS,
  parseCriticalIncidentCsv,
  parseCsv,
} from '../src/lib/operational.js'

const rows = parseCsv('id;equipe;regra\n1;n1;par')
assert.equal(rows.length, 1)
assert.deepEqual(rows[0], { id: '1', equipe: 'n1', regra: 'par' })

for (const invalidCsv of [
  'id;regra;extra\n1;par;x',
  'id;regra\n1;"x',
  'id;regra\n1;par;overflow',
]) {
  assert.throws(() => parseCsv(invalidCsv))
}

const oversizedCell = `id;regra\n1;${'x'.repeat(IMPORT_LIMITS.maxCellChars + 1)}`
assert.throws(() => parseCsv(oversizedCell))

const competency = competencyFor('2026-04-01T12:00:00')
assert.equal(competency.start, '2026-03-16')
assert.equal(competency.end, '2026-04-15')

const criticalRows = parseCriticalIncidentCsv(
  'ticket_number;source;title;severity;status;opened_at\n'
  + 'INC001;servicenow;Falha critica;critical;open;2026-06-14 10:00',
)
assert.equal(criticalRows.length, 1)
assert.equal(criticalRows[0].ticket_number, 'INC001')

const operationalCriticalRows = parseCriticalIncidentCsv(
  'incident_number;room_date;operation_reported_at;room_opened_at\n'
  + 'INC002;2026-06-15;2026-06-15 10:00;2026-06-15 10:12',
)
assert.equal(operationalCriticalRows[0].incident_number, 'INC002')

for (const invalidCriticalCsv of [
  'ticket_number;title;severity;status;opened_at\nINC001;Falha;critical;open;2026-06-14 10:00',
  'ticket_number;source;title;severity;status;opened_at;payload\nINC001;manual;Falha;high;open;2026-06-14 10:00;x',
  'ticket_number;source;title;severity;status;opened_at\nINC001;manual;"Falha;high;open;2026-06-14 10:00',
]) {
  assert.throws(() => parseCriticalIncidentCsv(invalidCriticalCsv))
}

const oversizedCriticalCell = [
  'ticket_number;source;title;severity;status;opened_at',
  `INC001;manual;${'x'.repeat(CRITICAL_INCIDENT_IMPORT_LIMITS.maxCellChars + 1)};high;open;2026-06-14 10:00`,
].join('\n')
assert.throws(() => parseCriticalIncidentCsv(oversizedCriticalCell))

const navigationSource = readFileSync(new URL('../src/lib/navigation.js', import.meta.url), 'utf8')
assert.match(navigationSource, /path: '\/admin'.*permission: 'operacao\.approve'/)

const adminSource = readFileSync(new URL('../src/pages/AdminPage.jsx', import.meta.url), 'utf8')
assert.match(adminSource, /availableTabs = isAdmin \? tabs : tabs\.filter/)
assert.match(adminSource, /useResource\('configuracoes\.php', \{ enabled: isAdmin \}\)/)

console.log('Operational frontend QA OK')
