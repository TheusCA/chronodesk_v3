export type HttpMethod = 'GET' | 'HEAD' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | string
export type JsonValue = string | number | boolean | null | JsonObject | JsonValue[]
export type JsonObject = { [key: string]: JsonValue }
export type ApiPayload = JsonObject | JsonValue[] | FormData | BodyInit | null | undefined
export type ApiResponse<T = JsonObject> = T
export type CsrfToken = string

export type ApiRequestOptions = Omit<RequestInit, 'method'> & {
  method?: HttpMethod
}

export type ApiError<T = unknown> = Error & {
  status?: number
  payload?: T
}

let csrfToken: CsrfToken = ''

export function setCsrfToken(token: CsrfToken | null | undefined): void {
  csrfToken = token || ''
}

export function apiUrl(path: string | number | boolean): string {
  return `/api/${String(path).replace(/^(\.\.\/api\/|\/?api\/|\/)/, '')}`
}

export async function api<T = JsonObject>(path: string, options: ApiRequestOptions = {}): Promise<ApiResponse<T>> {
  const method = (options.method || 'GET').toUpperCase()
  const headers = new Headers(options.headers || {})
  if (!['GET', 'HEAD'].includes(method)) {
    if (!(options.body instanceof FormData)) {
      headers.set('Content-Type', 'application/json')
    }
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
    const error = new Error(payload.mensagem || payload.error || `Falha HTTP ${response.status}.`) as ApiError
    error.status = response.status
    error.payload = payload
    if (response.status === 401 && !String(path).includes('session.php')) {
      window.dispatchEvent(new CustomEvent('chronodesk:unauthorized'))
    }
    throw error
  }

  return payload
}

export const post = <T = JsonObject>(path: string, body: ApiPayload): Promise<ApiResponse<T>> => (
  api<T>(path, { method: 'POST', body: JSON.stringify(body) })
)
export const postForm = <T = JsonObject>(path: string, body: FormData): Promise<ApiResponse<T>> => (
  api<T>(path, { method: 'POST', body })
)
