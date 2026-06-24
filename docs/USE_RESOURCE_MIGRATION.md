# Portal SDK - useResource Migration

## Status

Fase 11 migrou `frontend/src/hooks/useResource.js` para `frontend/src/hooks/useResource.ts`.

## Escopo executado

- Export preservado: `useResource`.
- Transporte preservado: `api()` de `frontend/src/lib/api.ts`.
- Consumidores preservados sem alterar imports.
- Nenhuma pagina foi migrada para TypeScript.
- Nenhum `.tsx` de runtime foi criado.
- Nenhuma dependencia nova foi instalada.

## Comportamento preservado

- `data`
- `loading`
- `error`
- `refresh`
- `setData`
- `enabled`
- `initialData`
- `intervalMs`
- `pauseWhenHidden`
- controle de request obsoleto por `requestRef`
- cleanup de intervalo
- cleanup de listener `visibilitychange`

## Relação com TanStack Query

`useResource` continua sendo o hook legado para GETs existentes. A Fase 11 nao substitui o hook por TanStack Query e nao migra consumidores. TanStack Query segue limitado ao fluxo `/relatorios` introduzido na Fase 10.

## QA

`frontend/scripts/qa-resource.mjs` valida:

- existencia de `useResource.ts`;
- ausencia de `useResource.js`;
- ausencia de import explicito para `useResource.js`;
- uso de `api()`;
- ausencia de `fetch()` direto;
- suporte a `enabled`, `initialData`, `intervalMs` e `pauseWhenHidden`;
- preservacao de `refresh`, controle de request obsoleto e cleanup;
- `useLivePauses` sem TanStack Query;
- POC isolada;
- ausencia de paginas TS/TSX e runtime TSX.

## Riscos residuais

- Os consumidores ainda sao JSX e recebem tipos permissivos.
- Erros continuam sendo objetos `Error`, como antes, para manter compatibilidade com `error.message`.
- A migracao ainda nao padroniza contratos de resposta por endpoint.

## Rollback

- Restaurar `frontend/src/hooks/useResource.js`.
- Remover `frontend/src/hooks/useResource.ts`.
- Remover `frontend/scripts/qa-resource.mjs`.
- Reverter ajustes em `frontend/package.json` e `frontend/scripts/qa-visual.mjs`.
