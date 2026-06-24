# Portal SDK - API Lib Migration

## Status

Fase 9 migrou `frontend/src/lib/api.js` para `frontend/src/lib/api.ts`.

## Escopo executado

- Hotfix aplicado em `scripts/qa-smoke.php` para limpar `APP_BASE_URL` apos carregar `config.php`.
- Preservados os exports publicos existentes:
  - `setCsrfToken`
  - `apiUrl`
  - `api`
  - `post`
  - `postForm`
- Adicionados tipos permissivos no proprio modulo:
  - `HttpMethod`
  - `JsonValue`
  - `JsonObject`
  - `ApiPayload`
  - `ApiResponse<T>`
  - `ApiRequestOptions`
  - `ApiError<T>`
  - `CsrfToken`
- Nenhuma tela, hook, formulario, tabela ou componente foi migrado.
- Nenhum `.tsx` de runtime foi criado.
- Nenhuma dependencia nova foi instalada.

## Invariantes protegidas

- `apiUrl()` continua normalizando caminhos relativos para `/api/...`.
- Requests mutaveis continuam enviando `X-CSRF-Token`.
- Requests mutaveis com JSON continuam enviando `Content-Type: application/json`.
- Requests com `FormData` continuam sem `Content-Type` manual.
- `fetch()` continua usando `credentials: 'same-origin'`.
- Resposta `204` continua retornando objeto vazio.
- Resposta JSON continua sendo parseada por `response.json()`.
- Erro HTTP continua usando `Error` nativo com `status` e `payload` anexados.
- `401` fora de `session.php` continua disparando `chronodesk:unauthorized`.

## QA criado

- `frontend/scripts/qa-api.mjs` valida a existencia de `api.ts` e ausencia de `api.js`.
- O script usa mocks locais de `fetch` e `window.dispatchEvent`, sem rede.
- O script valida CSRF, credentials, JSON, FormData, erro 401 e dependencias proibidas.
- `frontend/scripts/qa-visual.mjs` foi atualizado para exigir `qa:api` e `src/lib/api.ts`.

## Riscos residuais

- Tipos seguem permissivos para nao forcar migracao das telas JSX.
- Contratos de resposta ainda dependem do backend PHP e devem ser tipados gradualmente.
- TanStack Query ainda nao foi introduzido; o transporte `api()` deve permanecer como camada base.

## Proximos candidatos

- Criar tipos compartilhados para `Session`, `ApiResponse<T>` especifico por endpoint e erros.
- Introduzir TanStack Query em tela de baixo risco preservando `api()` e `post()`.
- Migrar `useResource.js` depois de estabilizar tipos de resposta.
