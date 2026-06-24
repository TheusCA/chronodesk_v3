# Portal SDK - Frontend Technical Roadmap

Fase 6 documentou uma evolucao gradual do frontend sem migrar telas criticas. Fase 7 iniciou TypeScript de forma permissiva, migrando apenas utilitarios pequenos e mantendo o runtime funcional sem mudancas de regra. Fase 8 migrou somente o utilitario operacional para TypeScript. Fase 9 migrou o transporte API base para TypeScript. Fase 10 iniciou TanStack Query de forma limitada em relatorios.

## Status da Fase 7

- TypeScript instalado como `devDependency`.
- `frontend/tsconfig.json` criado com `allowJs=true`, `checkJs=false`, `strict=false` e `noEmit=true`.
- `npm run typecheck` adicionado.
- Migrados: `src/lib/format.ts` e `src/lib/navigation.ts`.
- Nao migrados: `App.jsx`, paginas, hooks, formularios, tabelas e componentes complexos.
- TanStack Query/Table, React Hook Form, Zod, Playwright e Cypress continuam nao instalados.

## Status da Fase 8

- Migrado: `src/lib/operational.ts`.
- Removido: `src/lib/operational.js`.
- Mantidos sem migracao: `App.jsx`, paginas, hooks, formularios, tabelas e componentes.
- `qa:operational` passou a validar invariantes do utilitario operacional, incluindo limites de importacao, parser CSV, query string, valores canonicos de escala, ausencia de TS/TSX em paginas e POC isolada.
- Nenhuma dependencia nova foi instalada.

## Status da Fase 9

- Hotfix aplicado em `scripts/qa-smoke.php` para isolar `APP_BASE_URL` carregado do `.env` durante o smoke de Host header.
- Migrado: `src/lib/api.ts`.
- Removido: `src/lib/api.js`.
- Mantidos sem migracao: `App.jsx`, paginas, hooks, formularios, tabelas e componentes.
- `qa:api` valida CSRF, cookies/credentials, JSON, FormData, 401 e evento `chronodesk:unauthorized`.
- Nenhuma dependencia nova foi instalada.

## Status da Fase 10

- Dependencia adicionada: `@tanstack/react-query`.
- Criado `src/lib/queryClient.ts` com `retry=false`, `refetchOnWindowFocus=false` e `staleTime=30_000`.
- Criado `src/lib/queryKeys.ts` com chaves centralizadas simples.
- `QueryClientProvider` configurado em `src/main.jsx`.
- Primeiro fluxo com `useQuery`: `/relatorios`, usando `api()` como transporte e mantendo export CSV por link.
- Nao migrados: mutacoes, uploads, aprovacoes, pausas em tempo real, `useLivePauses`, Admin, Dashboard, PA Map, chamados criticos e escalas.
- `qa:query` valida provider, query client, query keys, dependencia permitida, ausencia de mutacoes e ausencia de fetch direto fora de `api.ts`.

## Diagnostico atual

| Arquivo | Responsabilidade | Complexidade | Risco | TypeScript | TanStack Query | TanStack Table | RHF/Zod | Prioridade |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `src/lib/format.ts` | Datas, parse e formatacao | Baixa | Baixo | Migrado na Fase 7 | Nao | Nao | Nao | Concluido |
| `src/lib/navigation.ts` | Rotas, labels e permissoes visuais | Baixa | Medio | Migrado na Fase 7 | Nao | Nao | Nao | Concluido |
| `src/lib/operational.ts` | CSV, limites, competencia e query string | Media | Alto em imports | Migrado na Fase 8 | Nao | Nao | Parcial | Concluido |
| `src/lib/api.ts` | Fetch, CSRF, 401 e payload JSON/FormData | Media | Alto | Migrado na Fase 9 | Transporte base na Fase 10 | Nao | Nao | Concluido |
| `src/lib/queryClient.ts` | Cliente TanStack Query | Baixa | Medio | Criado na Fase 10 | Base configurada | Nao | Nao | Concluido |
| `src/lib/queryKeys.ts` | Chaves de cache | Baixa | Medio | Criado na Fase 10 | Base configurada | Nao | Nao | Concluido |
| `src/hooks/useResource.js` | Fetch GET, loading/error/refetch | Media | Medio | Sim | Migrar gradualmente | Nao | Nao | P2 |
| `src/hooks/useLivePauses.js` | Polling de pausas e timers | Media | Alto | Sim tardio | Sim, com cuidado | Nao | Nao | P3 |
| `src/components/ui/States.jsx` | Loading, empty e error | Baixa | Baixo | Sim | Nao | Nao | Nao | P1 |
| `src/components/ui/Primitives.jsx` | UI compartilhada | Media | Baixo | Sim | Nao | Nao | Nao | P1 |
| `src/components/PortalLayout.jsx` | Shell, nav, notificacoes | Media | Medio | Sim tardio | Notificacoes | Nao | Nao | P3 |
| `src/App.jsx` | Sessao, roteamento e acoes globais | Alta | Alto | Ultimo | Query provider futuro | Nao | Nao | P5 |
| `src/pages/DashboardPage.jsx` | Dashboard e pausas ativas | Media | Medio | Depois dos hooks | Sim | Nao | Nao | P3 |
| `src/pages/PausasPage.jsx` | Pausas, timers e acoes | Alta | Alto | Tardio | Parcial | Nao | Reuniao | P4 |
| `src/pages/AdminPage.jsx` | Aprovacoes, funcionarios, config, usuarios | Alta | Alto | Tardio | Sim | Sim | Sim | P4 |
| `src/pages/OperationalPages.jsx` | Calendario, escala, workflows, relatorios | Muito alta | Muito alto | Ultimas telas | Somente `/relatorios` na Fase 10 | Sim | Sim | P5 |
| `src/pages/CriticalIncidentsPage.jsx` | War room, import, detalhe e tabela grande | Muito alta | Muito alto | Tardio | Sim | Sim | Sim | P4 |
| `src/pages/PaMapPage.jsx` | Mapa de PA e vinculos | Alta | Alto | Tardio | Sim | Nao | Sim | P4 |
| `src/pages/DocumentsPage.jsx` | Biblioteca, upload e filtros | Media | Alto em upload | Sim depois | Sim | Parcial | Sim | P3 |
| `src/pages/ShiftSchedulesPage.jsx` | Upload/feed de escalas | Media | Alto em upload | Sim depois | Sim | Nao | Sim | P3 |
| `src/pages/MetricasPage.jsx` | Metricas e tabela historica | Media | Medio | Sim depois | Sim | Sim | Nao | P3 |
| `src/pages/CalendarPage.jsx` | Calendario e evento manual | Media | Medio | Sim depois | Sim | Nao | Sim | P3 |

## Padroes atuais observados

- Fetch central passa por `api()`, `post()` e `postForm()` em `src/lib/api.ts`.
- CSRF e `credentials: same-origin` estao centralizados no transporte atual.
- GETs ainda usam majoritariamente `useResource()` com `loading`, `error`, `refresh`, `intervalMs` e cancelamento por request id.
- `/relatorios` usa TanStack Query desde a Fase 10, sempre via `api()`.
- Pausas usam hook proprio `useLivePauses()` com polling e timer local.
- Tabelas usam `data-table` e `table-wrap`, sem modelo unico de colunas.
- Formularios usam `useState` local, `required`, `maxLength`, conversoes manuais para `Number()` e `FormData`.
- Payloads criticos sao montados inline nas paginas, especialmente escala presencial, mapa de PA, chamados criticos, horas extras e correcao de ponto.

## Principais duplicacoes

- Loading/error/empty repetidos por pagina.
- Filtros e `queryString(filters)` repetidos.
- Acoes `post -> notify -> refresh` repetidas.
- Conversao manual de `employee_id`, `id`, datas e status.
- Tabelas com cabecalhos, celulas e acoes declaradas diretamente em JSX.
- Validacoes de formulario misturam UI, payload e regra de negocio.

## Payloads de maior risco

- `portal/schedules.php`: `rule_type` deve preservar `even_days`, `odd_days`, `always_onsite`, `always_remote`, `undefined`.
- `portal/pa_map.php`: `action`, `employee_id`, `pa_number`, `valid_from`, `confirm_remote_allocation`.
- `portal/critical_incidents.php`: muitos campos, aliases e acoes `create`, `update`, `status`.
- `portal/overtime.php` e `portal/time_corrections.php`: `action=create`, `action=decision`, horas, datas e status.
- `portal/documents.php` e `portal/shift_attachments.php`: `FormData`, limites e extensoes devem continuar no backend.
- Pausas: `funcionario_id`, `motivo_pausa`, `observacao`; nao reintroduzir bloqueios indevidos.

## Roadmap recomendado

1. Fase 7: instalar TypeScript em modo permissivo e migrar apenas `lib/format`, `lib/navigation`, exemplos de tipos e QA estatico. Concluido.
2. Fase 8: migrar `lib/operational`, mantendo `strict=false`. Concluido.
3. Fase 9: tipar transporte API sem mudar endpoints. Concluido.
4. Fase 10: introduzir TanStack Query em uma tela de baixo risco, mantendo `api()` e `post()`. Concluido em `/relatorios`.
5. Fase 11: introduzir TanStack Table em uma tabela nao critica ou somente leitura.
6. Fase 12: introduzir React Hook Form + Zod em formulario pequeno, sem alterar payload final.
7. Fase 13: migrar telas densas uma por vez com feature branch e smoke manual por perfil.

## Criterios para cada passo futuro

- Um modulo por PR.
- Build e QA estatico passando.
- Sem dependencia nova fora do plano aprovado.
- Payload final comparado com payload atual.
- Rollback por revert simples.
- Checklist manual por perfil para telas com RBAC.
