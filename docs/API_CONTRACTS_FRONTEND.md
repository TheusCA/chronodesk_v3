# Portal SDK - Frontend API Contracts

Mapa inicial dos contratos consumidos pelo frontend. Este documento descreve o uso atual sem alterar endpoints.

## Transporte comum

- Base: `apiUrl(path)` gera `/api/<path>`.
- Credenciais: `credentials: same-origin`.
- CSRF: enviado em metodos nao GET/HEAD via `X-CSRF-Token`.
- FormData: mantem `Content-Type` automatico do navegador.
- 401: dispara evento `chronodesk:unauthorized`, exceto `session.php`.

## Contratos principais

| Endpoint | Metodo | Payload enviado | Resposta esperada | Permissao/tela | Risco | Teste atual | Teste recomendado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `session.php` | GET | nenhum | `csrf_token`, `role`, `permissions`, `ci`, `gestor` | `App.jsx` | Alto: sessao/RBAC | Build/lint | Smoke por perfil |
| `login_ci.php` | POST | `login_ad`, `senha_ad` | `mensagem`, sessao valida | Login | Alto: AD | Backend smoke parcial | Teste manual AD |
| `logout.php` | POST | `{}` | `csrf_token`, `mensagem` | App/logout | Medio | Backend smoke | Smoke manual |
| `status.php` | GET | nenhum | `n1`, `n2`, `server_now` | Pausas/live | Alto: timers | QA operacional parcial | Contrato tipado |
| `iniciar_pausa.php` | POST | `funcionario_id`, `motivo_pausa` | `mensagem` | Pausas | Alto: regras de pausa | Backend smoke | Teste por motivo |
| `solicitar_pausa_com_aprovacao.php` | POST | `funcionario_id`, `motivo_pausa`, `observacao` | `mensagem` | Pausas | Alto: N1/N2 | Backend smoke | Teste por equipe |
| `finalizar_pausa.php` | POST | `funcionario_id` | `mensagem` | Pausas | Alto | Backend smoke | Smoke manual |
| `portal/admin_force_end_break.php` | POST | `funcionario_id`, `justificativa` | `mensagem` | Admin em pausas/dashboard | Alto: permissao | AppSec/RBAC visual | Teste admin/tecnico |
| `solicitacoes_pendentes.php` | GET | nenhum | pausas, overtime, adjustments | Admin | Alto | Lint/build | Contrato de resposta |
| `aprovar_pausa.php` / `rejeitar_pausa.php` | POST | `funcionario_id` | `mensagem` | Admin | Alto | Backend smoke | Smoke aprovacao |
| `portal/overtime.php` | GET/POST | GET filtros; POST `action=create`, form; `action=decision`, `id`, `decision` | itens, resumo, `mensagem` | Horas extras/Admin | Alto: status e total | QA visual/operacional | Contrato Zod futuro |
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
