let csrfToken = ''

export function setCsrfToken(token) {
  csrfToken = token || ''
}

export function apiUrl(path) {
  const marker = '/app'
  const appIndex = window.location.pathname.indexOf(marker)
  const basePath = appIndex >= 0 ? window.location.pathname.slice(0, appIndex) : ''
  return `${basePath}/api/${String(path).replace(/^(\.\.\/api\/|\/?api\/|\/)/, '')}`
}

export async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase()
  const headers = new Headers(options.headers || {})
  if (!['GET', 'HEAD'].includes(method)) {
    headers.set('Content-Type', 'application/json')
    headers.set('X-CSRF-Token', csrfToken)
  }

  const response = await fetch(apiUrl(path), {
    credentials: 'same-origin',
    ...options,
    method,
    headers,
  })
  const contentType = response.headers.get('content-type') || ''
  const payload = response.status === 204
    ? {}
    : contentType.includes('application/json')
      ? await response.json()
      : { sucesso: false, mensagem: `Resposta inválida do servidor (HTTP ${response.status}).` }

  if (!response.ok) {
    const error = new Error(payload.mensagem || payload.error || `Falha HTTP ${response.status}.`)
    error.status = response.status
    error.payload = payload
    if (response.status === 401 && !String(path).includes('session.php')) {
      window.dispatchEvent(new CustomEvent('chronodesk:unauthorized'))
    }
    throw error
  }

  return payload
}

export const post = (path, body) => api(path, { method: 'POST', body: JSON.stringify(body) })
