// IMPORTANTE: este módulo não pode ter imports. O QA (`qa-api`, `qa-operational`)
// transpila e carrega este arquivo como módulo `data:` URL, e esse tipo de módulo
// não resolve especificadores relativos. Qualquer `import` aqui quebra o QA.
// Por isso as mensagens padrão de erro ficam definidas localmente.

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

/**
 * Mensagens padrão por status. Usadas quando o servidor não devolve mensagem
 * própria em JSON — por exemplo num 403 emitido pelo próprio Apache, em que o
 * corpo não é JSON e o usuário via o texto técnico
 * "Resposta inválida do servidor (HTTP 403)".
 * Fluem para o usuário pelo canal de feedback da Fase 17 (`runAction` → `notify`).
 */
export const ACTION_ERRORS = Object.freeze({
  forbidden: 'Seu perfil não possui permissão para esta ação.',
  unauthorized: 'Sessão expirada. Faça login novamente.',
})

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
  const isJsonResponse = contentType.includes('application/json')
  const payload = response.status === 204
    ? {}
    : isJsonResponse
      ? await response.json()
      : { sucesso: false, mensagem: `Resposta inválida do servidor (HTTP ${response.status}).` }

  if (!response.ok) {
    // Só a mensagem vinda de um corpo JSON do backend é tratada como texto de
    // negócio. Respostas não-JSON (403 do Apache, página de erro) caem no texto
    // padrão do status, para o usuário nunca ver código HTTP cru.
    const serverMessage = isJsonResponse ? (payload.mensagem || payload.error) : ''
    const statusMessage = response.status === 403
      ? ACTION_ERRORS.forbidden
      : response.status === 401
        ? ACTION_ERRORS.unauthorized
        : `Falha HTTP ${response.status}.`
    const error = new Error(serverMessage || statusMessage) as ApiError
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
