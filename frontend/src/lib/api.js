let csrfToken = ''

export function setCsrfToken(token) {
  csrfToken = token || ''
}

export async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase()
  const headers = new Headers(options.headers || {})
  if (method !== 'GET' && method !== 'HEAD') {
    headers.set('Content-Type', 'application/json')
    headers.set('X-CSRF-Token', csrfToken)
  }

  const response = await fetch(path, {
    credentials: 'same-origin',
    ...options,
    method,
    headers,
  })
  const contentType = response.headers.get('content-type') || ''
  const payload = contentType.includes('application/json')
    ? await response.json()
    : { sucesso: false, mensagem: `Resposta inválida do servidor (HTTP ${response.status}).` }

  if (!response.ok) {
    const error = new Error(payload.mensagem || `Falha HTTP ${response.status}.`)
    error.status = response.status
    error.payload = payload
    throw error
  }

  return payload
}

export const post = (path, body) => api(path, { method: 'POST', body: JSON.stringify(body) })
