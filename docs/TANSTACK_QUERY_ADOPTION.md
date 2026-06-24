# Portal SDK - TanStack Query Adoption

## Status

Fase 10 introduziu `@tanstack/react-query` de forma limitada, sem alterar endpoints, payloads, CSRF, credenciais, RBAC, autenticacao ou backend.

## Escopo executado

- Dependencia adicionada: `@tanstack/react-query`.
- `QueryClientProvider` configurado em `frontend/src/main.jsx`.
- `frontend/src/lib/queryClient.ts` criado com configuracao conservadora:
  - `retry: false`
  - `refetchOnWindowFocus: false`
  - `staleTime: 30_000`
- `frontend/src/lib/queryKeys.ts` criado com chaves simples:
  - `session`
  - `metrics`
  - `reports(filters)`
- Primeiro fluxo migrado: `/relatorios`.
- Transporte preservado: `api()` de `frontend/src/lib/api.ts`.

## Fora do escopo

- Nenhuma mutation foi migrada.
- `useLivePauses` nao foi alterado.
- Pausas em tempo real nao foram migradas.
- Admin, aprovacoes, uploads, PA Map, chamados criticos, escalas e Dashboard ficaram fora.
- Nenhuma pagina foi migrada para TypeScript.
- Nenhum `.tsx` de runtime foi criado.

## QA

- `frontend/scripts/qa-query.mjs` valida:
  - dependencia permitida;
  - dependencias proibidas ausentes;
  - `QueryClientProvider`;
  - configuracao conservadora do `QueryClient`;
  - query keys centralizadas;
  - `useQuery` limitado a `/relatorios`;
  - `api()` como transporte;
  - ausencia de `useMutation`;
  - ausencia de `fetch()` direto fora de `api.ts`;
  - POC isolada.

## Riscos residuais

- `/relatorios` ainda compartilha arquivo com outras telas operacionais em `OperationalPages.jsx`; o QA limita a ocorrencia de `useQuery` ao bloco de relatorios.
- Cache e invalidacao para mutacoes ainda nao foram definidos.
- Tipos de resposta continuam permissivos.

## Rollback

- Remover `@tanstack/react-query` de `package.json` e `package-lock.json`.
- Remover `queryClient.ts`, `queryKeys.ts` e `qa-query.mjs`.
- Remover o provider de `main.jsx`.
- Restaurar `/relatorios` para `useResource`.

## Proximos passos

- Migrar outro GET somente leitura de baixo risco.
- Definir padrao de query keys por dominio.
- Somente depois, planejar mutacoes com invalidacao explicita e smoke por perfil.
