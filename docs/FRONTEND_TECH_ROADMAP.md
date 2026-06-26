# Portal SDK - Frontend Technical Roadmap

Fase 6 documentou uma evolucao gradual do frontend sem migrar telas criticas. Fase 7 iniciou TypeScript de forma permissiva, migrando apenas utilitarios pequenos e mantendo o runtime funcional sem mudancas de regra. Fase 8 migrou somente o utilitario operacional para TypeScript. Fase 9 migrou o transporte API base para TypeScript. Fase 10 iniciou TanStack Query de forma limitada em relatorios. Fase 11 migrou o hook legado `useResource` para TypeScript sem alterar consumidores. Fase 12 iniciou TanStack Table apenas no resumo somente leitura de `/relatorios`. Fase 13 adicionou a regra `fixed_weekdays` para escala fixa por dias da semana. Fase 14 introduziu React Hook Form + Zod somente nos filtros de `/relatorios`. Fase 15 adicionou code splitting controlado com `React.lazy` e `Suspense`. Fase 16 adicionou Error Boundary para chunks lazy e credito discreto do desenvolvedor. Fase 17 padronizou feedback pos-acao e limpeza de formularios transacionais. Fase 18 criou um action runner leve para reduzir duplicidade em fluxos transacionais selecionados. Fase 19 expandiu o runner para acoes simples de Chamados Criticos. Fase 20 aplicou React Hook Form + Zod somente ao formulario de regra da escala presencial.

## Status da Fase 7

- TypeScript instalado como `devDependency`.
- `frontend/tsconfig.json` criado com `allowJs=true`, `checkJs=false`, `strict=false` e `noEmit=true`.
- `npm run typecheck` adicionado.
- Migrados: `src/lib/format.ts` e `src/lib/navigation.ts`.
- Nao migrados: `App.jsx`, paginas, hooks, formularios, tabelas e componentes complexos.
- Nesta fase, TanStack Query/Table, React Hook Form, Zod, Playwright e Cypress ainda nao estavam instalados.

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

## Status da Fase 11

- Migrado: `src/hooks/useResource.ts`.
- Removido: `src/hooks/useResource.js`.
- Preservados: `data`, `loading`, `error`, `refresh`, `setData`, `enabled`, `initialData`, `intervalMs`, `pauseWhenHidden`, controle de request obsoleto e cleanup de polling.
- `api()` continua sendo o transporte.
- Consumidores JSX nao foram migrados.
- `useLivePauses` nao foi alterado.
- Nenhuma dependencia nova foi instalada.
- `qa:resource` valida invariantes do hook, ausencia de `fetch()` fora de `api.ts`, POC isolada, ausencia de paginas TS/TSX e ausencia de mutacoes TanStack Query.

## Status da Fase 12

- Dependencia adicionada: `@tanstack/react-table`.
- Primeiro uso: componente `ReportsSummaryTable` do fluxo `/relatorios`, somente leitura.
- Dados continuam vindo do `useQuery` da Fase 10, que usa `api()` como transporte.
- Export CSV, filtros, endpoints e payloads foram preservados.
- Nao migrados: mutacoes, uploads, aprovacoes, pausas, Dashboard, Admin, PA Map, chamados criticos, escalas e formularios.
- `qa:table` valida dependencia permitida, escopo unico, ausencia de `useMutation`, ausencia de `fetch()` direto fora de `api.ts`, POC isolada e ausencia de paginas TS/TSX.

## Status da Fase 13

- Novo `rule_type`: `fixed_weekdays`.
- Dias aceitos inicialmente: `mon`, `tue`, `wed`, `thu`, `fri`.
- Persistencia: `portal_schedule_rules.rule_config` com JSON serializado, por exemplo `{"weekdays":["mon","wed","fri"]}`.
- Mapa de PA guarda snapshot em `portal_pa_assignments.schedule_rule_config`.
- UI de escala presencial ganhou seletor "Dias fixos da semana" e checkboxes de segunda a sexta.
- Calculo presencial/remoto preserva regras antigas e usa dias fixos para calendario, escala gerada e Mapa de PA.
- `qa:schedule-rules` valida payload, validacao backend, migration, escopo e ausencia de novas dependencias.

## Status da Fase 14

- Dependencias adicionadas: `react-hook-form` e `zod`.
- Primeiro uso restrito aos filtros de `/relatorios` em `OperationalReportsPage`.
- Schema criado em `src/lib/formSchemas.ts` para `competency`, `team` e `employee_id`.
- Validacao Zod e manual via `safeParse`, sem `@hookform/resolvers`.
- Querystring preservada: `queryString(filters)` para consulta e `queryString({ ...filters, format: 'csv' })` para export CSV.
- Transporte preservado: `api()` para GET e `apiUrl()` para exportacao.
- Nao migrados: POSTs, mutations, uploads, aprovacoes, Pausas, Admin, PA Map, Critical Incidents, Escalas e paginas TypeScript.
- `qa:forms` valida dependencias, escopo, ausencia de dependencias proibidas, ausencia de mutation/POST novo, `fetch()` centralizado e POC isolada.

## Status da Fase 15

- `App.jsx` passou a carregar paginas autenticadas com `React.lazy`.
- `Suspense` envolve o conteudo renderizado dentro do `PortalLayout`.
- Fallback usa `LoadingState`, sem criar biblioteca nova.
- Login, sessao, logout, layout, feedback, router e `useLivePauses` continuam carregados diretamente.
- `vite.config.js` nao foi alterado e `chunkSizeWarningLimit` nao foi usado.
- JS principal reduziu de `500.74 kB` para `199.16 kB`.
- O warning de chunk acima de 500 kB foi eliminado.
- `qa:bundle` valida lazy loading, Suspense, ausencia de dependencia nova, POC isolada, ausencia de mutation e `fetch()` centralizado.

## Status da Fase 16

- Criado `RouteErrorBoundary` para proteger falhas de renderizacao/carregamento de paginas lazy.
- `App.jsx` envolve `Suspense` com o boundary e usa `resetKey` por rota.
- Fallback de erro exibe mensagem profissional e botao `Tentar novamente`.
- Credito visual adicionado: `Desenvolvido por Matheus Camargo`.
- O credito aparece na tela de login e no rodape da sidebar autenticada.
- Nenhuma dependencia nova foi instalada.
- Nenhum backend, endpoint, payload, querystring, RBAC, CSRF ou regra de negocio foi alterado.
- `qa:ux-hardening` valida boundary, credito, AppSec, dependencias e arquivos protegidos.

## Status da Fase 17

- `Feedback.jsx` passou a suportar `success`, `error`, `warning` e `info`, com `role` adequado, fechamento manual e layout responsivo.
- Criado `src/lib/actionFeedback.ts` com mensagens padronizadas para acoes de criar, salvar, remover, aprovar, rejeitar, vincular e importar.
- Fluxos transacionais ajustados em Admin, Escalas/Operacional, PA Map, Chamados Criticos, Documentos e Escala de Sabado.
- Formularios passam a limpar campos transacionais apos sucesso, preservando filtros, datas/competencias uteis e contexto operacional quando apropriado.
- Listas continuam atualizando via `refresh()`/`resource.refresh()` apos sucesso.
- Nenhuma dependencia nova foi instalada.
- Nenhum backend, endpoint, payload, querystring, RBAC, CSRF ou regra de negocio foi alterado.
- `qa:action-feedback` valida mensagens, resets, refresh, ausencia de alert novo, AppSec, dependencias e hardenings anteriores.

## Status da Fase 18

- Criado `src/lib/actionRunner.ts` para centralizar execucao de `action`, `refresh`, `onSuccess`, feedback de sucesso e tratamento de erro.
- `AdminPage.jsx` passou a delegar o helper local `perform` ao action runner.
- `OperationalPages.jsx` passou a delegar o helper local `submit` ao action runner.
- Resets representativos foram movidos para `onSuccess`: funcionarios, usuarios, senha, calendario, escala, excecao, importacao, workflows e plantao.
- Ordem preservada: executar acao, atualizar dados/listas, limpar estado transacional e exibir feedback.
- Fora do escopo nesta fase: PA Map, chamados criticos, documentos, escala de sabado e pausas em tempo real.
- Nenhuma dependencia nova foi instalada.
- Nenhum endpoint, payload, querystring, RBAC, CSRF, autenticacao ou regra de negocio foi alterado.
- `qa:action-runner` valida assinatura, escopo, AppSec, arquivos protegidos, resets e preservacao dos hardenings anteriores.

## Status da Fase 19

- `CriticalIncidentsPage.jsx` passou a usar `runAction` nos fluxos simples de criar, editar e alterar status.
- O payload de `portal/critical_incidents.php` foi preservado para `create`, `update` e `status`.
- `setFormItem(null)` continua fechando o formulario apos sucesso.
- `resource.refresh` passou a ser executado pelo runner antes do reset e do toast.
- Mensagens preservadas: `criticalCreated`, `criticalUpdated` e `criticalStatusUpdated`.
- Importacao de chamados criticos continua fora do runner, mantendo `postForm`, preview, linhas, limpeza de input e resumo de importacao.
- Nenhuma dependencia nova foi instalada.
- Nenhum endpoint, payload, querystring, RBAC, CSRF, autenticacao ou regra de negocio foi alterado.
- `qa:action-runner` foi ampliado para validar Chamados Criticos e preservar a importacao fora do escopo.

## Status da Fase 20

- Criado `scheduleRuleSchema` em `src/lib/formSchemas.ts` para `employee_id`, `rule_type`, `effective_from` e `weekdays`.
- O formulario de regra em `SchedulePage` passou a usar React Hook Form com validacao manual via `scheduleRuleSchema.safeParse`.
- `fixed_weekdays` continua exigindo ao menos um dia da semana; ao trocar para uma regra comum, `weekdays` e limpo no formulario.
- Payload preservado: `action: 'rule'`, `employee_id` convertido com `Number`, `rule_type`, `effective_from` e `weekdays` somente para `fixed_weekdays`.
- Reset pos-sucesso preserva a data de vigencia util e limpa colaborador, regra e weekdays por `resetRule(emptyScheduleRule(...))`.
- Excecoes de escala, importacao, PA Map, chamados criticos, documentos, escala de sabado, pausas, Admin, backend e banco ficaram fora do escopo.
- Criado `qa:schedule-form` para validar schema, RHF/Zod, payload, dependencia proibida, AppSec e arquivos protegidos.
- Nenhuma dependencia nova foi instalada.

## Diagnostico atual

| Arquivo | Responsabilidade | Complexidade | Risco | TypeScript | TanStack Query | TanStack Table | RHF/Zod | Prioridade |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `src/lib/format.ts` | Datas, parse e formatacao | Baixa | Baixo | Migrado na Fase 7 | Nao | Nao | Nao | Concluido |
| `src/lib/navigation.ts` | Rotas, labels e permissoes visuais | Baixa | Medio | Migrado na Fase 7 | Nao | Nao | Nao | Concluido |
| `src/lib/operational.ts` | CSV, limites, competencia e query string | Media | Alto em imports | Migrado na Fase 8 | Nao | Nao | Nao | Concluido |
| `src/lib/api.ts` | Fetch, CSRF, 401 e payload JSON/FormData | Media | Alto | Migrado na Fase 9 | Transporte base na Fase 10 | Nao | Nao | Concluido |
| `src/lib/queryClient.ts` | Cliente TanStack Query | Baixa | Medio | Criado na Fase 10 | Base configurada | Nao | Nao | Concluido |
| `src/lib/queryKeys.ts` | Chaves de cache | Baixa | Medio | Criado na Fase 10 | Base configurada | Nao | Nao | Concluido |
| `src/hooks/useResource.ts` | Fetch GET, loading/error/refetch | Media | Medio | Migrado na Fase 11 | Legado preservado | Nao | Nao | Concluido |
| `src/hooks/useLivePauses.js` | Polling de pausas e timers | Media | Alto | Sim tardio | Sim, com cuidado | Nao | Nao | P3 |
| `src/components/ui/States.jsx` | Loading, empty e error | Baixa | Baixo | Sim | Nao | Nao | Nao | P1 |
| `src/components/ui/Primitives.jsx` | UI compartilhada | Media | Baixo | Sim | Nao | Nao | Nao | P1 |
| `src/components/PortalLayout.jsx` | Shell, nav, notificacoes | Media | Medio | Sim tardio | Notificacoes | Nao | Nao | P3 |
| `src/App.jsx` | Sessao, roteamento, lazy loading, Error Boundary e acoes globais | Alta | Alto | Ultimo | Query provider futuro | Nao | Nao | P5 |
| `src/pages/DashboardPage.jsx` | Dashboard e pausas ativas | Media | Medio | Depois dos hooks | Sim | Nao | Nao | P3 |
| `src/pages/PausasPage.jsx` | Pausas, timers e acoes | Alta | Alto | Tardio | Parcial | Nao | Reuniao | P4 |
| `src/pages/AdminPage.jsx` | Aprovacoes, funcionarios, config, usuarios | Alta | Alto | Tardio | Sim | Sim | Sim | P4 |
| `src/pages/OperationalPages.jsx` | Calendario, escala, workflows, relatorios | Muito alta | Muito alto | Ultimas telas | Somente `/relatorios` na Fase 10 | Somente resumo de `/relatorios` na Fase 12 | Filtros de `/relatorios` na Fase 14 e regra de escala na Fase 20 | P5 |
| `src/pages/CriticalIncidentsPage.jsx` | War room, import, detalhe e tabela grande | Muito alta | Muito alto | Tardio | Sim | Sim | Sim | P4 |
| `src/pages/PaMapPage.jsx` | Mapa de PA e vinculos | Alta | Alto | Tardio | Sim | Nao | Sim | P4 |
| `src/pages/DocumentsPage.jsx` | Biblioteca, upload e filtros | Media | Alto em upload | Sim depois | Sim | Parcial | Sim | P3 |
| `src/pages/ShiftSchedulesPage.jsx` | Upload/feed de escalas | Media | Alto em upload | Sim depois | Sim | Nao | Sim | P3 |
| `src/pages/MetricasPage.jsx` | Metricas e tabela historica | Media | Medio | Sim depois | Sim | Sim | Nao | P3 |
| `src/pages/CalendarPage.jsx` | Calendario e evento manual | Media | Medio | Sim depois | Sim | Nao | Sim | P3 |

## Padroes atuais observados

- Fetch central passa por `api()`, `post()` e `postForm()` em `src/lib/api.ts`.
- CSRF e `credentials: same-origin` estao centralizados no transporte atual.
- GETs ainda usam majoritariamente `useResource()` com `loading`, `error`, `refresh`, `intervalMs` e cancelamento por request id; o hook foi tipado na Fase 11.
- `/relatorios` usa TanStack Query desde a Fase 10, sempre via `api()`.
- O resumo de `/relatorios` usa TanStack Table desde a Fase 12 apenas como motor de tabela client-side.
- Paginas autenticadas sao carregadas sob demanda com `React.lazy` desde a Fase 15.
- Falhas de chunk lazy sao cobertas por `RouteErrorBoundary` desde a Fase 16.
- Pausas usam hook proprio `useLivePauses()` com polling e timer local.
- Tabelas usam `data-table` e `table-wrap`, sem modelo unico de colunas.
- Formularios ainda usam majoritariamente `useState` local; excecoes controladas: filtros de `/relatorios` usam React Hook Form + Zod desde a Fase 14 e regra de escala presencial desde a Fase 20.
- Payloads criticos sao montados inline nas paginas, especialmente escala presencial, mapa de PA, chamados criticos, horas extras e correcao de ponto.

## Principais duplicacoes

- Loading/error/empty repetidos por pagina.
- Filtros e `queryString(filters)` repetidos.
- Acoes `post -> refresh -> reset -> notify` usam runner em Admin, Operacional e fluxos simples de Chamados Criticos; uploads, PA Map e pausas ainda mantem fluxo local por escopo.
- Conversao manual de `employee_id`, `id`, datas e status.
- Tabelas com cabecalhos, celulas e acoes declaradas diretamente em JSX.
- Validacoes de formulario misturam UI, payload e regra de negocio.

## Payloads de maior risco

- `portal/schedules.php`: `rule_type` deve preservar `even_days`, `odd_days`, `always_onsite`, `always_remote`, `undefined` e `fixed_weekdays`.
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
5. Fase 11: tipar `useResource` sem trocar consumidores por TanStack Query. Concluido.
6. Fase 12: introduzir TanStack Table em uma tabela nao critica ou somente leitura. Concluido em `/relatorios`.
7. Fase 13: adicionar `fixed_weekdays` com QA dedicado. Concluido.
8. Fase 14: introduzir React Hook Form + Zod em formulario pequeno, sem alterar querystring final. Concluido em `/relatorios`.
9. Fase 15: reduzir bundle inicial com code splitting nativo. Concluido.
10. Fase 16: adicionar UX hardening pos-code splitting. Concluido.
11. Fase 17: padronizar feedback pos-acao e limpeza de formularios transacionais. Concluido.
12. Fase 18: criar action runner leve para fluxos transacionais selecionados. Concluido.
13. Fase 19: expandir action runner para acoes simples de Chamados Criticos. Concluido.
14. Fase 20: migrar somente o formulario de regra de escala presencial para RHF/Zod, preservando payload e runner. Concluido.
15. Proxima fase: continuar formularios um por vez com schema pequeno, QA dedicado e smoke manual por perfil.

## Criterios para cada passo futuro

- Um modulo por PR.
- Build e QA estatico passando.
- Sem dependencia nova fora do plano aprovado.
- Payload final comparado com payload atual.
- Rollback por revert simples.
- Checklist manual por perfil para telas com RBAC.
