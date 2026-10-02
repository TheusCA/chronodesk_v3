# Portal SDK - Frontend API Contracts

Mapa inicial dos contratos consumidos pelo frontend. Este documento descreve o uso atual sem alterar endpoints.

## Transporte comum

- Base: `apiUrl(path)` gera `/api/<path>`.
- Credenciais: `credentials: same-origin`.
- CSRF: enviado em metodos nao GET/HEAD via `X-CSRF-Token`.
- FormData: mantem `Content-Type` automatico do navegador.
- 401: dispara evento `chronodesk:unauthorized`, exceto `session.php`.
- Lote 5a: toda requisicao autenticada revalida o perfil no servidor. Sessao encerrada pela revalidacao responde como sem sessao (401). Com o banco fora, a revalidacao responde 503 ("Nao foi possivel validar a sessao agora") e a sessao continua.

## Contratos principais

| Endpoint | Metodo | Payload enviado | Resposta esperada | Permissao/tela | Risco | Teste atual | Teste recomendado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `session.php` | GET | nenhum | `csrf_token`, `role` (`admin`, `lideranca`, `gestor`, `tecnico`, `somente_leitura`), `permissions`, `ci`, `gestor` | `App.jsx` | Alto: sessao/RBAC | Build/lint | Matriz de perfis no `qa-security` (Lote 5a) |
| `login_ci.php` | POST | `login_ad`, `senha_ad` | `mensagem`, sessao valida | Login | Alto: AD | Backend smoke parcial | Teste manual AD |
| `logout.php` | POST | `{}` | `csrf_token`, `mensagem` | App/logout | Medio | Backend smoke | Smoke manual |
| `status.php` | GET | nenhum | `n1`, `n2`, `server_now` | Pausas/live | Alto: timers | QA operacional parcial | Contrato tipado |
| `iniciar_pausa.php` | POST | `funcionario_id`, `motivo_pausa` | `mensagem` | Pausas | Alto: regras de pausa | Backend smoke | Teste por motivo |
| `solicitar_pausa_com_aprovacao.php` | POST | `funcionario_id`, `motivo_pausa`, `observacao` | `mensagem` | Pausas | Alto: N1/N2 | Backend smoke | Teste por equipe |
| `finalizar_pausa.php` | POST | `funcionario_id` | `mensagem` | Pausas | Alto | Backend smoke | Smoke manual |
| `portal/admin_force_end_break.php` | POST | `funcionario_id`, `justificativa` | `mensagem` | Admin em pausas/dashboard | Alto: permissao | AppSec/RBAC visual | Teste admin/tecnico |
| `solicitacoes_pendentes.php` | GET | nenhum | pausas, overtime, adjustments | Admin | Alto | Lint/build | Contrato de resposta |
| `aprovar_pausa.php` / `rejeitar_pausa.php` | POST | `funcionario_id` | `mensagem` | Admin | Alto | Backend smoke | Smoke aprovacao |
| `portal/overtime.php` | GET/POST | GET filtros; POST `action=create`, form; `action=decision`, `id`, `decision` (409 ao aprovar pendente com entrada igual à saída; rejeitar continua permitido) | itens, resumo, `mensagem` | Horas extras/Admin | Alto: status e total | QA visual/operacional | Contrato Zod futuro |
| `portal/time_corrections.php` | GET/POST | GET filtros; POST `action=create`, form; `action=decision`, `id`, `decision` | itens, resumo, `mensagem` | Correcao/Admin | Alto | QA visual/operacional | Contrato Zod futuro |
| `portal/schedules.php` | GET/POST/FormData | filtros; `action=rule`, `employee_id`, `rule_type`, `effective_from`; `action=exception`; import | regras, gerados, preview, `mensagem` | Escala presencial | Muito alto: `rule_type` | `qa:operational`, `qa:visual` | Teste de payload |
| `portal/pa_map.php` | GET/POST | filtros; `action=save`, `pa_number`, `employee_id`, `valid_from`, `notes`, `confirm_remote_allocation`; `action=remove`, `id` | PAs, employees, can_manage, `mensagem`, warnings | Mapa de PA | Alto: permissao e remoto | Build/lint | Teste admin/tecnico |
| `portal/critical_incidents.php` | GET/POST | filtros; detalhe por `id`; `action=create/update/status` com campos de incidente | items, summary, item, `mensagem` | Chamados criticos | Muito alto: muitos campos | Build/lint | Schema e fixture |
| `portal/critical_incidents_import.php` | POST FormData/JSON | `spreadsheet`; depois `action=confirm`, `rows` | preview, contagens, `mensagem` | Chamados criticos | Alto: import | `qa:operational` parse | Fixture import |
| `portal/critical_incidents_export.php` | GET | filtros | CSV/download | Chamados criticos | Medio | Build | Smoke link |
| `portal/documents.php` | GET/POST FormData | document, title, category, description, visibility | limits, items, can_upload, `mensagem` | Documentacao | Alto: upload | Build/lint | Upload fixture controlada |
| `portal/documents_delete.php` | POST | `id` | `mensagem` | Documentacao | Alto: auditoria | Build/lint | Teste permissao |
| `portal/documents_download.php` | GET | `id` | arquivo | Documentacao | Alto: path interno | AppSec visual | Smoke download |
| `portal/shift_attachments.php` | GET/POST FormData | arquivo, title, reference_month, notes | limits, items, can_upload, `mensagem` | Escalas de Sabado | Alto: extensoes | Build/lint | Upload controlado |
| `portal/shift_attachments_download.php` | GET | `id`, opcional `preview=1` | arquivo/preview | Escalas de Sabado | Alto | Build/lint | Smoke preview |
| `portal/notifications.php` | GET/POST | POST `id` para marcar leitura | unread, items, `mensagem` | Layout | Medio | Build/lint | Query future |
| `portal/dashboard.php` | GET | nenhum | metricas e cards | Dashboard | Medio | Build/lint | Contrato tipado |
| `portal/calendar.php` | GET/POST | filtros; form de evento | items, `mensagem` | Calendario | Medio | Build/lint | Schema futuro |
| `listar_funcionarios.php` | GET | nenhum | `funcionarios` | Admin, filtros, selects | Alto | Backend smoke | Tipo Employee |
| `configuracoes.php` | GET | nenhum | `configuracoes` | Admin | Alto: admin only | Backend smoke | RBAC manual |
| `salvar_configuracao.php` | POST | configuracoes de pausa | `mensagem` | Admin | Alto | Backend smoke | Teste admin |
| `usuarios.php` | GET/POST | action listar/criar/atualizar/deletar | usuarios, `mensagem` | Admin local | Alto | Backend smoke | Teste local admin |
| `portal/reports.php` | GET | filtros, opcional `format=csv` | items/resumo ou CSV | Relatorios | Medio | Build/lint | Smoke CSV |

## Listas limitadas e exportacoes (Lote 6, PERF-02 e BIZ-01)

- Listas de tela com limite devolvem, junto de `items`, `truncated` (ha mais registros que os exibidos) e `limit`. Vale para `portal/overtime.php` e `portal/time_corrections.php` (500), `portal/critical_incidents.php` (500), `portal/documents.php` (300), `portal/shift_attachments.php` (200) e as listas genericas de `PortalService` (`portal/absences.php`, `portal/announcements.php`, `portal/schedules.php?type=`, `portal/standby.php`; 100). O frontend mostra o aviso com `TruncationNotice`.
- `solicitacoes_pendentes.php` acrescenta `overtime_truncated`, `time_adjustments_truncated` e `limit`.
- `metricas.php` acrescenta `solicitacoes_reuniao_truncadas` e `solicitacoes_reuniao_limite`.
- `portal/overtime.php` (GET) acrescenta `totals.approved_minutes` e `totals.pending_minutes`, calculados sobre o filtro inteiro, sem o filtro de status. Rejeitados nao entram.
- Sem `from`/`to`/`competency`, horas extras e correcao de ponto usam a competencia corrente (16 a 15), nao mais o mes calendario.
- `portal/reports.php`: `summary.overtime_minutes` e `overtime_minutes` por colaborador/equipe foram **substituidos** por `overtime_approved_minutes` e `overtime_pending_minutes`.
- Exportacoes CSV nao truncam: horas extras, correcao de ponto, chamados criticos e o ZIP de pausas (`download_relatorio.php`) percorrem o filtro inteiro em paginas.
- CSV de horas extras: a coluna `Total Realizado` (somava todos os status) foi substituida por `Total aprovado` e `Total pendente`, e entrou a coluna `Status` no fim. Detalhes em `docs/HORAS_EXTRAS_LOTE6.md`.

## Contratos propostos - Parte B (NAO IMPLEMENTADOS)

Desenho aprovado em 2026-09-30, com P2, P12 e o tratamento dos relatorios em aberto. Nada desta secao existe no codigo. A tabela acima continua sendo o contrato em uso; cada linha so muda no lote que implementar o endpoint. Especificacao completa em `docs/DESENHO_PA_ESCALA_AUSENCIA.md`, secao 3.

| Endpoint | Situacao | Mudanca proposta | Lote |
| --- | --- | --- | --- |
| `portal/presence.php` | Novo | GET `from`, `to`, `team`, `employee_id`; devolve `server_now`, `days[]` com `situacao`, `origem`, `detalhe`, `escala`, `ausencias` | F2 |
| `status.php` | Alterado | Cada item ganha `presenca` de hoje; deixa de incluir arquivados | F2, F4 |
| `portal/schedules.php` | Alterado | `rule_type=cycle` com `cycle{onsite_days, remote_days, starts_with, anchor_date}`; `fixed_weekdays` com `input_mode` e `home_weekdays`; `action=rule_preview`; regras com `is_current`, `is_future`, `config`; `include_history=1`; excecao sem tipos de ausencia | F1, F2B, F3 |
| `portal/absences.php` | Alterado | GET com filtros e escopo por perfil (tecnico e somente leitura so as proprias); POST `action=create/update/cancel` para admin e gestor; retroativo so admin | F3 |
| `portal/calendar.php` | Alterado | Escala vinda do motor; ausencia deixa de ser evento separado e de expor `reason` | F3, F5 |
| `remover_funcionario.php` | Alterado | Mesmo payload; executa o arquivamento completo e devolve `efeitos` | F4 |
| `restaurar_funcionario.php` | Novo | POST `funcionario_id`; so admin | F4 |
| `atualizar_funcionario.php` | Alterado | Mesmo payload; troca de `ativo` passa pelas rotinas de arquivar e restaurar | F4 |
| `portal/technicians.php` | Alterado | Acrescenta `presence` | F4 |
| `portal/pa_map.php` | Alterado | GET com `presence` por vinculo, `conflicts`, `has_conflict`, `absences_week`, `week`, `server_now`; `save` deixa de devolver 409 por conflito de escala e passa a devolver `warnings` e `conflicts` | F6 |
| `portal/dashboard.php` | Alterado | Acrescenta `server_now` e `presence_summary` por equipe | F7 |
| `portal/reports.php` | Alterado | Escala vinda do motor; `absence_days`, `no_schedule_days`; fim de semana e `undefined` deixam de contar como presencial/remoto | F2 |

## Campos que merecem tipos primeiro

- `role`, `permissions`.
- `ScheduleRuleType`.
- `WorkflowStatus`.
- `CriticalIncidentStatus` e `CriticalIncidentSeverity`.
- `EmployeeId` como number no payload final.
- `FormData` de uploads apenas com limites vindos do backend.

## Regras de seguranca a preservar

- Nunca renderizar HTML vindo do backend.
- Nunca exibir path fisico de arquivos.
- Nunca logar senha, token CSRF ou cookie.
- Manter POST com CSRF pelo transporte central.
- Manter RBAC no backend como autoridade; UI e apenas reducao de superficie.
