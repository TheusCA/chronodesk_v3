# Portal SDK Frontend POC

Esta pasta contem exemplos nao importados pelo app e nao usados no runtime de producao.

Regras:

- Nao importar estes arquivos em `src`.
- Nao incluir no build real.
- Nao instalar dependencias nesta fase.
- Tratar `.example.ts` e `.example.tsx` como documentacao executavel futura, nao codigo ativo.

Conteudo:

- `typescript/types.example.ts`: tipos iniciais sugeridos.
- `query/queryKeys.example.ts`: query keys futuras para TanStack Query.
- `forms/scheduleSchema.example.ts`: exemplo de schema Zod para escala presencial.
- `table/criticalIncidentsColumns.example.tsx`: exemplo de colunas para TanStack Table.
