export const IMPORT_LIMITS = Object.freeze({
  maxFileBytes: 2097152,
  maxPayloadBytes: 1048576,
  maxRows: 500,
  maxColumns: 6,
  maxCellChars: 500,
  acceptedExtensions: Object.freeze(['.csv', '.xlsx']),
  acceptedHeaders: Object.freeze([
    'id', 'employee_id',
    'login_ad', 'ad_login', 'email',
    'nome', 'name',
    'equipe', 'team',
    'regra', 'rule_type',
  ]),
})

export const CRITICAL_INCIDENT_IMPORT_LIMITS = Object.freeze({
  maxFileBytes: 2097152,
  maxPayloadBytes: 2097152,
  maxRows: 500,
  maxColumns: 39,
  maxCellChars: 4000,
  acceptedExtensions: Object.freeze(['.csv', '.xlsx']),
  acceptedHeaders: Object.freeze([
    'incident_number', 'room_date', 'incident_opened_at',
    'operation_reported_at', 'room_opened_at', 'normalized_at',
    'room_description', 'room_finalization_description',
    'room_opening_duration_minutes', 'room_duration_minutes',
    'sector', 'sdk_activity',
    'ticket_number', 'source', 'title', 'summary', 'severity', 'status',
    'opened_at', 'war_room_started_at', 'mitigated_at', 'resolved_at',
    'impact', 'affected_users', 'affected_services', 'responsible_area',
    'owner_name', 'owner_login', 'involved_teams', 'root_cause',
    'resolution', 'workaround', 'actions_taken', 'next_steps',
    'meeting_url', 'participants', 'notes',
  ]),
  requiredHeaders: Object.freeze(['incident_number', 'room_date']),
})

export function competencyFor(reference = new Date()) {
  const date = new Date(reference)
  const start = date.getDate() >= 16
    ? new Date(date.getFullYear(), date.getMonth(), 16)
    : new Date(date.getFullYear(), date.getMonth() - 1, 16)
  const end = new Date(start.getFullYear(), start.getMonth() + 1, 15)
  const labelDate = new Date(end.getFullYear(), end.getMonth(), 1)
  return {
    start: localDate(start),
    end: localDate(end),
    key: `${labelDate.getFullYear()}-${String(labelDate.getMonth() + 1).padStart(2, '0')}`,
    label: labelDate.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }),
  }
}

export function localDate(date = new Date()) {
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

export function queryString(filters) {
  const params = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) params.set(key, value)
  })
  const query = params.toString()
  return query ? `?${query}` : ''
}

export function parseCsv(text) {
  const rows = []
  let row = []
  let field = ''
  let quoted = false
  const input = String(text).replace(/^\uFEFF/, '')

  for (let index = 0; index < input.length; index += 1) {
    const char = input[index]
    if (char === '"') {
      if (quoted && input[index + 1] === '"') {
        field += '"'
        index += 1
      } else {
        quoted = !quoted
      }
    } else if ((char === ',' || char === ';') && !quoted) {
      row.push(field.trim())
      field = ''
    } else if ((char === '\n' || char === '\r') && !quoted) {
      if (char === '\r' && input[index + 1] === '\n') index += 1
      row.push(field.trim())
      if (row.some(Boolean)) rows.push(row)
      row = []
      field = ''
    } else {
      field += char
    }
  }
  row.push(field.trim())
  if (row.some(Boolean)) rows.push(row)
  if (quoted) throw new Error('O CSV possui aspas sem fechamento.')
  if (rows.length < 2) throw new Error('O CSV deve conter cabecalho e ao menos uma linha.')
  if (rows.length - 1 > IMPORT_LIMITS.maxRows) {
    throw new Error(`O CSV excede o limite de ${IMPORT_LIMITS.maxRows} linhas.`)
  }

  const headers = rows[0].map((header) => header.toLowerCase().trim())
  if (headers.length > IMPORT_LIMITS.maxColumns) {
    throw new Error(`O CSV excede o limite de ${IMPORT_LIMITS.maxColumns} colunas.`)
  }
  if (headers.some((header) => !header || !IMPORT_LIMITS.acceptedHeaders.includes(header))) {
    throw new Error('O CSV possui cabecalho vazio ou nao permitido.')
  }
  if (new Set(headers).size !== headers.length) {
    throw new Error('O CSV possui cabecalhos duplicados.')
  }
  const hasIdentity = headers.some((header) => [
    'id', 'employee_id', 'login_ad', 'ad_login', 'email', 'nome', 'name',
  ].includes(header))
  if (!hasIdentity || !headers.some((header) => ['regra', 'rule_type'].includes(header))) {
    throw new Error('O CSV deve identificar o colaborador e informar a regra.')
  }

  rows.slice(1).forEach((values, index) => {
    if (values.length > IMPORT_LIMITS.maxColumns || values.length > headers.length) {
      throw new Error(`A linha ${index + 2} possui colunas alem do cabecalho.`)
    }
    if (values.some((value) => value.length > IMPORT_LIMITS.maxCellChars)) {
      throw new Error(
        `A linha ${index + 2} possui celula acima de ${IMPORT_LIMITS.maxCellChars} caracteres.`,
      )
    }
  })

  return rows.slice(1).map((values) => Object.fromEntries(
    headers.map((header, index) => [header, values[index] ?? '']),
  ))
}

export function parseCriticalIncidentCsv(text) {
  const limits = CRITICAL_INCIDENT_IMPORT_LIMITS
  const rows = []
  let row = []
  let field = ''
  let quoted = false
  const input = String(text).replace(/^\uFEFF/, '')

  for (let index = 0; index < input.length; index += 1) {
    const char = input[index]
    if (char === '"') {
      if (quoted && input[index + 1] === '"') {
        field += '"'
        index += 1
      } else {
        quoted = !quoted
      }
    } else if ((char === ',' || char === ';') && !quoted) {
      row.push(field.trim())
      field = ''
    } else if ((char === '\n' || char === '\r') && !quoted) {
      if (char === '\r' && input[index + 1] === '\n') index += 1
      row.push(field.trim())
      if (row.some(Boolean)) rows.push(row)
      row = []
      field = ''
    } else {
      field += char
    }
  }
  row.push(field.trim())
  if (row.some(Boolean)) rows.push(row)

  if (quoted) throw new Error('O CSV possui aspas sem fechamento.')
  if (rows.length < 2) throw new Error('O CSV deve conter cabecalho e ao menos uma linha.')
  if (rows.length - 1 > limits.maxRows) {
    throw new Error(`O CSV excede o limite de ${limits.maxRows} linhas.`)
  }

  const headers = rows[0].map((header) => header.toLowerCase().trim())
  if (headers.length > limits.maxColumns) {
    throw new Error(`O CSV excede o limite de ${limits.maxColumns} colunas.`)
  }
  if (headers.some((header) => !header || !limits.acceptedHeaders.includes(header))) {
    throw new Error('O CSV possui cabecalho vazio ou nao permitido.')
  }
  if (new Set(headers).size !== headers.length) {
    throw new Error('O CSV possui cabecalhos duplicados.')
  }
  const legacyRequired = ['ticket_number', 'source', 'title', 'severity', 'status', 'opened_at']
  const hasOperationalHeaders = limits.requiredHeaders.every((header) => headers.includes(header))
  const hasLegacyHeaders = legacyRequired.every((header) => headers.includes(header))
  if (!hasOperationalHeaders && !hasLegacyHeaders) {
    throw new Error('Use incident_number e room_date, ou o conjunto legado completo.')
  }

  rows.slice(1).forEach((values, index) => {
    if (values.length > headers.length || values.length > limits.maxColumns) {
      throw new Error(`A linha ${index + 2} possui colunas alem do cabecalho.`)
    }
    if (values.some((value) => value.length > limits.maxCellChars)) {
      throw new Error(
        `A linha ${index + 2} possui celula acima de ${limits.maxCellChars} caracteres.`,
      )
    }
  })

  return rows.slice(1).map((values) => Object.fromEntries(
    headers.map((header, index) => [header, values[index] ?? '']),
  ))
}

export function minutesLabel(value) {
  const minutes = Number(value || 0)
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}min`
}
