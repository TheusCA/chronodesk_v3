# Portal SDK - TypeScript Migration Plan

Objetivo: adotar TypeScript gradualmente sem bloquear deploy e sem migrar telas criticas em bloco.

## Principios

- Manter `.jsx` funcionando durante toda a migracao.
- Usar `allowJs` no inicio para convivencia entre JS e TS.
- Comecar por utilitarios puros e componentes sem efeito colateral.
- Tipar contratos de API antes de refatorar chamadas.
- Tratar erros de tipo como guia de melhoria, nao como big bang.
- Deixar `App.jsx` e paginas densas por ultimo.

## Ordem recomendada

1. `frontend/src/lib/format.js`.
2. `frontend/src/lib/navigation.js`.
3. `frontend/src/lib/operational.ts`.
4. `frontend/src/lib/api.ts`.
5. Componentes puros de UI.
6. `States.jsx`.
7. `Primitives.jsx`.
8. `frontend/src/hooks/useResource.ts`.
9. Paginas pequenas ou somente leitura.
10. Paginas densas.
11. `App.jsx` por ultimo.

## Status da Fase 7

Concluido nesta fase:

- `typescript` instalado somente como `devDependency`.
- `frontend/tsconfig.json` criado com perfil permissivo.
- Script `npm run typecheck` adicionado.
- `frontend/src/lib/format.js` migrado para `frontend/src/lib/format.ts`.
- `frontend/src/lib/navigation.js` migrado para `frontend/src/lib/navigation.ts`.
- POC em `frontend/poc` continua isolada e fora do runtime.

Nao migrado nesta fase:

- `App.jsx`.
- `frontend/src/pages/*.jsx`.
- Componentes compartilhados complexos.
- Hooks.
- Formularios.
- Tabelas.
- Payloads de APIs.

## Dependencias

Instalada na Fase 7:

```bash
cd frontend
npm install -D typescript
```

Nao instaladas nesta fase:

- `@tanstack/react-table`.
- `react-hook-form`.
- `zod`.
- Playwright/Cypress.

## Dependencias futuras recomendadas

Ja instalada na Fase 7:

```bash
cd frontend
npm install -D typescript
```

O projeto ja possui `@types/react` e `@types/react-dom`, entao a primeira instalacao pode ser menor que em um projeto novo.

## Configuracao futura sugerida

`tsconfig.json` inicial permissivo, aplicado na Fase 7:

```json
{
  "compilerOptions": {
    "allowJs": true,
    "checkJs": false,
    "jsx": "react-jsx",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "noEmit": true,
    "strict": false,
    "target": "ES2020"
  },
  "include": ["src", "scripts", "poc"]
}
```

Evolucao depois:

- `strict: true` somente apos tipos base.
- `checkJs: true` apenas se o ruido for aceitavel.
- `noUncheckedIndexedAccess` em fase posterior.

## Vite e ESLint

- Vite aceita TS/TSX quando `typescript` esta instalado.
- ESLint atual pode continuar para JS; regras TypeScript devem entrar em fase propria.
- Nao converter configs em conjunto com telas criticas.

## Tipos iniciais recomendados

- `Session`.
- `ApiError`.
- `ApiResponse<T>`.
- `Employee`.
- `ScheduleRuleType`.
- `WorkflowStatus`.
- `CriticalIncident`.
- `DocumentItem`.
- `ShiftAttachment`.

## Beneficios esperados

- Menos bug de payload (`rule_type`, `employee_id`, status).
- Melhor autocomplete em endpoints.
- Refatoracao mais segura em tabelas e formularios.
- Contratos frontend documentados em codigo.

## Riscos

- Tipos frouxos podem dar falsa sensacao de seguranca.
- Tipos rigidos demais podem atrasar deploy.
- Converter telas densas cedo aumenta risco de regressao.
- Tipos de resposta podem divergir do PHP se nao houver smoke.

## Rollback

- Reverter arquivos `.ts/.tsx` criados e `tsconfig.json`.
- Manter `.jsx` originais ate cada migracao estar aprovada.
- Nao misturar migracao TS com alteracao funcional.
- Para a Fase 7 especificamente, rollback e remover `typescript`, restaurar `format.js`/`navigation.js` e remover `typecheck`.

## Status da Fase 8

Concluido nesta fase:

- `frontend/src/lib/operational.js` migrado para `frontend/src/lib/operational.ts`.
- Exports publicos preservados: `IMPORT_LIMITS`, `CRITICAL_INCIDENT_IMPORT_LIMITS`, `competencyFor`, `localDate`, `queryString`, `parseCsv`, `parseCriticalIncidentCsv` e `minutesLabel`.
- Tipos permissivos adicionados para limites de importacao, linhas CSV, query string, competencia e valores canonicos de escala.
- `qa:operational` atualizado para carregar o modulo TypeScript em memoria e validar invariantes de importacao, CSV, query string, POC isolada, paginas nao migradas e dependencias proibidas.
- `qa:visual` atualizado para exigir `operational.ts` e a remocao de `operational.js`.

Nao migrado nesta fase:

- `App.jsx`.
- `frontend/src/pages/*.jsx`.
- Componentes, hooks, formularios e tabelas.
- Nenhum `.tsx` de runtime.

Rollback da Fase 8:

- Restaurar `frontend/src/lib/operational.js`.
- Remover `frontend/src/lib/operational.ts`.
- Reverter ajustes em `frontend/scripts/qa-operational.mjs` e `frontend/scripts/qa-visual.mjs`.

## Status da Fase 9

Concluido nesta fase:

- Hotfix em `scripts/qa-smoke.php` para limpar `APP_BASE_URL` imediatamente apos carregar `config.php`.
- `frontend/src/lib/api.js` migrado para `frontend/src/lib/api.ts`.
- Exports publicos preservados: `setCsrfToken`, `apiUrl`, `api`, `post` e `postForm`.
- Tipos permissivos adicionados para metodo HTTP, payload JSON/FormData, opcoes de request, resposta de API, erro de API e CSRF token.
- `qa:api` criado para validar CSRF, `credentials: same-origin`, JSON, FormData sem `Content-Type` manual, erro 401 e evento `chronodesk:unauthorized`.
- `qa:visual` atualizado para exigir `api.ts`, remocao de `api.js` e script `qa:api`.

Nao migrado nesta fase:

- `App.jsx`.
- `frontend/src/pages/*.jsx`.
- Componentes, hooks, formularios e tabelas.
- Nenhum `.tsx` de runtime.

Rollback da Fase 9:

- Restaurar `frontend/src/lib/api.js`.
- Remover `frontend/src/lib/api.ts`.
- Reverter `frontend/scripts/qa-api.mjs`, `frontend/package.json` e ajustes em `frontend/scripts/qa-visual.mjs`.
- Reverter o hotfix em `scripts/qa-smoke.php` somente se o ambiente de smoke nao depender mais da limpeza de `APP_BASE_URL`.

## Status da Fase 10

Concluido nesta fase:

- `@tanstack/react-query` instalado como dependencia de runtime.
- `frontend/src/lib/queryClient.ts` criado com configuracao conservadora.
- `frontend/src/lib/queryKeys.ts` criado com chaves centralizadas simples.
- `QueryClientProvider` adicionado em `frontend/src/main.jsx`.
- `OperationalReportsPage` passou a usar `useQuery` apenas para o GET de `portal/reports.php`.
- `api()` continua sendo o transporte base.
- `qa:query` criado para validar escopo, provider, query client, query keys, ausencia de mutacoes e dependencias proibidas.

Nao migrado nesta fase:

- Mutacoes.
- `useLivePauses`.
- Pausas, Dashboard, Admin, PA Map, Critical Incidents, uploads e aprovacoes.
- Paginas para TypeScript.
- Nenhum `.tsx` de runtime.

Rollback da Fase 10:

- Remover `@tanstack/react-query` de `package.json` e `package-lock.json`.
- Remover `frontend/src/lib/queryClient.ts`, `frontend/src/lib/queryKeys.ts` e `frontend/scripts/qa-query.mjs`.
- Remover `QueryClientProvider` de `frontend/src/main.jsx`.
- Restaurar `OperationalReportsPage` para `useResource`.
- Reverter ajustes em `frontend/package.json`, `frontend/scripts/qa-api.mjs`, `frontend/scripts/qa-operational.mjs` e `frontend/scripts/qa-visual.mjs`.

## Status da Fase 11

Concluido nesta fase:

- `frontend/src/hooks/useResource.js` migrado para `frontend/src/hooks/useResource.ts`.
- Export `useResource` preservado.
- Tipos permissivos adicionados para opcoes, erro, resposta e retorno.
- Comportamento preservado: `enabled`, `initialData`, `intervalMs`, `pauseWhenHidden`, `refresh`, controle de request obsoleto, cleanup de intervalo e listener de visibilidade.
- `api()` continua sendo o transporte.
- `qa:resource` criado para validar a migracao do hook e o escopo da fase.
- `qa:visual` atualizado para exigir `useResource.ts` e ausencia de `useResource.js`.

Nao migrado nesta fase:

- Consumidores JSX do hook.
- `useLivePauses`.
- Paginas.
- Componentes.
- Mutacoes.
- Nenhum `.tsx` de runtime.

Rollback da Fase 11:

- Restaurar `frontend/src/hooks/useResource.js`.
- Remover `frontend/src/hooks/useResource.ts`.
- Remover `frontend/scripts/qa-resource.mjs`.
- Reverter ajustes em `frontend/package.json` e `frontend/scripts/qa-visual.mjs`.

## Status da Fase 12

Concluido nesta fase:

- `@tanstack/react-table` instalado como dependencia de runtime.
- `ReportsSummaryTable` em `frontend/src/pages/OperationalPages.jsx` passou a usar `useReactTable`, `getCoreRowModel` e `flexRender`.
- Uso limitado ao fluxo `/relatorios`, em resumo somente leitura alimentado pelo `useQuery` da Fase 10.
- `api()` continua sendo o transporte base por meio da query existente.
- `qa:table` criado para validar dependencia permitida, escopo unico, ausencia de mutacoes, ausencia de `fetch()` direto fora de `api.ts` e POC isolada.

Nao migrado nesta fase:

- Paginas para TypeScript.
- Componentes para TypeScript.
- Mutacoes.
- Uploads.
- Aprovacoes.
- Telas criticas.
- Nenhum `.tsx` de runtime.

Rollback da Fase 12:

- Remover `@tanstack/react-table` de `frontend/package.json` e `frontend/package-lock.json`.
- Restaurar `ReportsSummaryTable` para renderizacao JSX simples.
- Remover `frontend/scripts/qa-table.mjs`.
- Reverter ajustes em `frontend/package.json`, `frontend/scripts/qa-api.mjs`, `frontend/scripts/qa-operational.mjs`, `frontend/scripts/qa-query.mjs`, `frontend/scripts/qa-resource.mjs` e `frontend/scripts/qa-visual.mjs`.

## Status da Fase 13

Concluido nesta fase:

- Tipo `ScheduleRuleType` atualizado para incluir `fixed_weekdays`.
- Exemplos POC de tipos/formulario atualizados, mantendo POC isolada.
- Nenhuma pagina foi migrada para TypeScript.
- Nenhum `.tsx` de runtime foi criado.
- Nenhuma dependencia nova foi instalada.

Fora da trilha TypeScript, mas registrado aqui por contrato:

- `fixed_weekdays` usa payload `weekdays: ['mon', 'wed', 'fri']`.
- A UI continua em JSX e valida a selecao antes de chamar `post('portal/schedules.php', payload)`.
- Backend valida dias permitidos, rejeita lista vazia, rejeita duplicidade e persiste configuracao em JSON serializado.

Rollback da Fase 13:

- Reverter `ScheduleRuleType` para remover `fixed_weekdays`.
- Reverter UI de dias fixos em `OperationalPages.jsx`.
- Reverter `qa:schedule-rules` e ajustes de QA.
- Aplicar rollback de banco apenas se necessario em janela controlada, removendo `fixed_weekdays` de registros antes de alterar ENUM/colunas.

## Status da Fase 14

Concluido nesta fase:

- `react-hook-form` e `zod` instalados como dependencias de runtime.
- Criado `frontend/src/lib/formSchemas.ts` com schema permissivo e pequeno para filtros de relatorios.
- `OperationalReportsPage` usa React Hook Form apenas em `competency`, `team` e `employee_id`.
- Zod valida manualmente via `safeParse`, sem `@hookform/resolvers`.
- Querystring e export CSV preservados.
- Criado `qa:forms`.
- Nenhuma pagina foi migrada para TypeScript.
- Nenhum `.tsx` de runtime foi criado.

Nao migrado nesta fase:

- Formularios com POST.
- Uploads.
- Pausas.
- Admin.
- PA Map.
- Escalas.
- Critical Incidents.
- Mutations.

Rollback da Fase 14:

- Remover `react-hook-form` e `zod` de `frontend/package.json` e `frontend/package-lock.json`.
- Remover `frontend/src/lib/formSchemas.ts`.
- Restaurar filtros de `/relatorios` para `useState` direto.
- Remover `frontend/scripts/qa-forms.mjs` e o script `qa:forms`.
- Reverter ajustes nos QAs que passaram a aceitar as dependencias autorizadas.

## Status da Fase 15

Concluido nesta fase:

- `App.jsx` passou a usar `React.lazy` e `Suspense` para paginas autenticadas.
- O fallback reutiliza `LoadingState`.
- Nenhuma pagina foi migrada para TypeScript.
- Nenhum `.tsx` de runtime foi criado.
- Nenhuma dependencia nova foi instalada.
- Criado `qa:bundle`.

Fora da trilha TypeScript, mas registrado aqui por contrato:

- Code splitting reduziu o JS principal de `500.74 kB` para `199.16 kB`.
- O warning de chunk acima de 500 kB foi eliminado sem `chunkSizeWarningLimit`.
- `useLivePauses`, `api.ts`, `useResource.ts`, `queryClient.ts`, `queryKeys.ts` e `operational.ts` nao foram alterados.

Rollback da Fase 15:

- Restaurar imports estaticos das paginas em `App.jsx`.
- Remover helper `lazyPage` e `Suspense` do conteudo autenticado.
- Remover `frontend/scripts/qa-bundle.mjs` e script `qa:bundle`.
- Reverter ajustes em `frontend/scripts/qa-visual.mjs` e docs.
