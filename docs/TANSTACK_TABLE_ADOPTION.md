# Portal SDK - TanStack Table Adoption

## Status

Fase 12 introduziu `@tanstack/react-table` de forma limitada, sem alterar endpoints, payloads, CSRF, credenciais, RBAC, autenticacao, backend ou banco.

## Escopo executado

- Dependencia adicionada: `@tanstack/react-table`.
- Primeiro fluxo: `/relatorios`.
- Primeiro componente: `ReportsSummaryTable` em `frontend/src/pages/OperationalPages.jsx`.
- Recursos usados:
  - `useReactTable`
  - `getCoreRowModel`
  - `flexRender`
- Dados preservados: continuam vindo do `useQuery` da Fase 10, que usa `api()` como transporte.
- Export CSV preservado por `apiUrl`.

## Fora do escopo

- Nenhuma mutation foi migrada.
- Nenhum upload foi alterado.
- Nenhuma aprovacao foi alterada.
- `useLivePauses` nao foi alterado.
- `useResource.ts` nao foi alterado.
- Pausas, Dashboard, Admin, PA Map, chamados criticos, escalas e formularios ficaram fora.
- Nenhuma pagina foi migrada para TypeScript.
- Nenhum `.tsx` de runtime foi criado.

## QA

- `frontend/scripts/qa-table.mjs` valida:
  - `@tanstack/react-table` instalado;
  - `@tanstack/react-query` preservado;
  - dependencias proibidas ausentes;
  - `useReactTable` limitado a uma ocorrencia;
  - ausencia de `useMutation`;
  - `api.ts` como unico ponto com `fetch()`;
  - `useLivePauses` sem TanStack Query/Table;
  - POC isolada;
  - ausencia de paginas TS/TSX e TSX de runtime.

## Riscos residuais

- O arquivo `OperationalPages.jsx` continua denso e compartilha muitas telas operacionais; o QA limita o uso de TanStack Table ao componente de relatorios.
- Ainda nao existe padrao aprovado para tabelas com acoes, selecao, paginacao server-side ou colunas operacionais.
- Tipos de linha seguem permissivos porque a pagina permanece em JSX.

## Rollback

- Remover `@tanstack/react-table` de `frontend/package.json` e `frontend/package-lock.json`.
- Remover imports de `@tanstack/react-table` de `OperationalPages.jsx`.
- Restaurar o resumo de `/relatorios` para JSX simples.
- Remover `frontend/scripts/qa-table.mjs` e o script `qa:table`.

## Proximos passos

- Definir padrao de colunas para tabelas somente leitura.
- Avaliar uma segunda tabela GET simples antes de qualquer tabela com acoes.
- Manter mutacoes fora de TanStack Query/Table ate existir estrategia de invalidacao e smoke por perfil.
