# Portal SDK - Frontend Technical Roadmap

Fase 6 documenta uma evolucao gradual do frontend sem migrar telas criticas agora. Nao instala dependencias, nao altera contratos de API e nao muda runtime de producao.

## Diagnostico atual

| Arquivo | Responsabilidade | Complexidade | Risco | TypeScript | TanStack Query | TanStack Table | RHF/Zod | Prioridade |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `src/lib/format.js` | Datas, parse e formatacao | Baixa | Baixo | Sim, primeiro | Nao | Nao | Nao | P1 |
| `src/lib/navigation.js` | Rotas, labels e permissoes visuais | Baixa | Medio | Sim | Nao | Nao | Nao | P1 |
| `src/lib/operational.js` | CSV, limites, competencia e query string | Media | Alto em imports | Sim | Nao | Nao | Parcial | P1 |
| `src/lib/api.js` | Fetch, CSRF, 401 e payload JSON/FormData | Media | Alto | Sim, apos tipos base | Sim, manter como transporte | Nao | Nao | P2 |
| `src/hooks/useResource.js` | Fetch GET, loading/error/refetch | Media | Medio | Sim | Migrar gradualmente | Nao | Nao | P2 |
| `src/hooks/useLivePauses.js` | Polling de pausas e timers | Media | Alto | Sim tardio | Sim, com cuidado | Nao | Nao | P3 |
| `src/components/ui/States.jsx` | Loading, empty e error | Baixa | Baixo | Sim | Nao | Nao | Nao | P1 |
| `src/components/ui/Primitives.jsx` | UI compartilhada | Media | Baixo | Sim | Nao | Nao | Nao | P1 |
| `src/components/PortalLayout.jsx` | Shell, nav, notificacoes | Media | Medio | Sim tardio | Notificacoes | Nao | Nao | P3 |
| `src/App.jsx` | Sessao, roteamento e acoes globais | Alta | Alto | Ultimo | Query provider futuro | Nao | Nao | P5 |
| `src/pages/DashboardPage.jsx` | Dashboard e pausas ativas | Media | Medio | Depois dos hooks | Sim | Nao | Nao | P3 |
| `src/pages/PausasPage.jsx` | Pausas, timers e acoes | Alta | Alto | Tardio | Parcial | Nao | Reuniao | P4 |
| `src/pages/AdminPage.jsx` | Aprovacoes, funcionarios, config, usuarios | Alta | Alto | Tardio | Sim | Sim | Sim | P4 |
| `src/pages/OperationalPages.jsx` | Calendario, escala, workflows, relatorios | Muito alta | Muito alto | Ultimas telas | Sim | Sim | Sim | P5 |
| `src/pages/CriticalIncidentsPage.jsx` | War room, import, detalhe e tabela grande | Muito alta | Muito alto | Tardio | Sim | Sim | Sim | P4 |
| `src/pages/PaMapPage.jsx` | Mapa de PA e vinculos | Alta | Alto | Tardio | Sim | Nao | Sim | P4 |
| `src/pages/DocumentsPage.jsx` | Biblioteca, upload e filtros | Media | Alto em upload | Sim depois | Sim | Parcial | Sim | P3 |
| `src/pages/ShiftSchedulesPage.jsx` | Upload/feed de escalas | Media | Alto em upload | Sim depois | Sim | Nao | Sim | P3 |
| `src/pages/MetricasPage.jsx` | Metricas e tabela historica | Media | Medio | Sim depois | Sim | Sim | Nao | P3 |
| `src/pages/CalendarPage.jsx` | Calendario e evento manual | Media | Medio | Sim depois | Sim | Nao | Sim | P3 |

## Padroes atuais observados

- Fetch central passa por `api()`, `post()` e `postForm()` em `src/lib/api.js`.
- CSRF e `credentials: same-origin` estao centralizados no transporte atual.
- GETs usam `useResource()` com `loading`, `error`, `refresh`, `intervalMs` e cancelamento por request id.
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

1. Fase 7: instalar TypeScript em modo permissivo e migrar apenas `lib/format`, `lib/navigation`, exemplos de tipos e QA estatico.
2. Fase 8: migrar `lib/operational`, componentes puros e `States/Primitives`.
3. Fase 9: tipar transporte API e criar tipos de resposta/payload sem mudar endpoints.
4. Fase 10: introduzir TanStack Query em uma tela de baixo risco, mantendo `api()` e `post()`.
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
