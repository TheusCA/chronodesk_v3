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
| `status.php` | GET | nenhum | `n1`, `n2`, `server_now`; desde o F2, `presenca` em cada item (`{ situacao, origem, detalhe }` de hoje, ou `null` para inativo e fora do Mapa) e `presenca_disponivel` (ausente com `STATUS_PRESENCE_ENABLED` desligado; `false` se o calculo falhar) | Pausas/live | Alto: timers | QA operacional parcial; `qa-presence-engine` | Contrato tipado |
| `portal/presence.php` | GET | `from`, `to` (`AAAA-MM-DD`; padrao hoje; maximo 93 dias), `team` (`n1`, `n2`, `lideranca`), `employee_id` | `server_now`, `today`, `period`, `employees[]` (`id`, `name`, `team`), `days[]` (`employee_id`, `date`, `situacao`, `origem`, `detalhe`, `escala`, `ausencias[]` com `origem`, `period_type`, `ativa`, e desde o F3 `start_time`/`end_time` nas parciais e `category` so para gestor, Lideranca, admin e o proprio colaborador (P9); nunca a observacao; `em_almoco`). F3: ausencia parcial ativa = `ausente` com detalhe "Ausente desde 14:00" ou "Ausente 10:00–12:00"; parcial que ainda vai comecar (ou de outra data) fica como marcador no detalhe (" · Ausente a partir de 14:00"), `meta.pausas_disponiveis`; 400 para parametro invalido | Nenhuma tela ainda (F5 a F7) | Medio | `qa-presence-engine`; matriz de perfis | Contrato tipado ao entrar na tela |
| `iniciar_pausa.php` | POST | `funcionario_id`, `motivo_pausa` | `mensagem` | Pausas | Alto: regras de pausa | Backend smoke | Teste por motivo |
| `solicitar_pausa_com_aprovacao.php` | POST | `funcionario_id`, `motivo_pausa`, `observacao` | `mensagem` | Pausas | Alto: N1/N2 | Backend smoke | Teste por equipe |
| `finalizar_pausa.php` | POST | `funcionario_id` | `mensagem` | Pausas | Alto | Backend smoke | Smoke manual |
| `portal/admin_force_end_break.php` | POST | `funcionario_id`, `justificativa` | `mensagem` | Admin em pausas/dashboard | Alto: permissao | AppSec/RBAC visual | Teste admin/tecnico (Lote 5c: admin e Lideranca, `pausas.force_end`) |
| `solicitacoes_pendentes.php` | GET | nenhum | pausas, overtime, adjustments | Admin | Alto | Lint/build | Contrato de resposta |
| `aprovar_pausa.php` / `rejeitar_pausa.php` | POST | `funcionario_id` | `mensagem` | Admin | Alto | Backend smoke | Smoke aprovacao |
| `portal/overtime.php` | GET/POST | GET filtros; POST `action=create`, form; `action=decision`, `id`, `decision` (409 ao aprovar pendente com entrada igual à saída; rejeitar continua permitido) | itens, resumo, `mensagem` | Horas extras/Admin | Alto: status e total | QA visual/operacional | Contrato Zod futuro |
| `portal/time_corrections.php` | GET/POST | GET filtros; POST `action=create`, form; `action=decision`, `id`, `decision` | itens, resumo, `mensagem` | Correcao/Admin | Alto | QA visual/operacional | Contrato Zod futuro |
| `portal/schedules.php` | GET/POST/FormData | filtros (`include_history=1` traz as regras encerradas); `action=rule`, `employee_id`, `rule_type`, `effective_from`; F2B: `rule_type=cycle` com `cycle{onsite_days, remote_days, starts_with, anchor_date}` (o servidor monta o `pattern`; inicio = `anchor_date`, dia util; 2 a 20 dias, cada bloco de 1 a 19), `fixed_weekdays` com `weekdays` (presenciais) ou `input_mode: "home"` + `home_weekdays` (nenhum ou todos os dias de home: 400); `action=rule_preview` (mesmo payload e permissoes do `rule`, nao grava); `action=exception`; import (linha com ciclo recusada) | `today`; regras com `is_current`, `is_future`, `config` (no ciclo, `pattern`, `anchor_date`, `business_days_only`; nos dias fixos em modo home, `input_mode`) e `implicit` (Lideranca: `rule_label` "Presencial (regra fixa)", `work_start`, `work_end`, sem `id`); F2B: gerados pelo motor, so dias uteis (fim de semana so com excecao de trabalho), um por cadastro e dia, inclusive sem regra; `presence_type` `onsite`, `remote`, `absence` (dia todo, sem categoria) ou `no_schedule`; `source` `rule`, `exception`, `implicit`, `absence` ou `none`; mais `situacao`, `origem`, `detalhe` e `escala`; `rule` devolve `id`, `unchanged`, `closed_rule_id`, `closed_until`, `retroactive`; `rule_preview` devolve `employee_id`, `rule_type`, `rule_label`, `effective_from`, `effective_until`, `unchanged`, `retroactive`, `closes{rule_id, rule_type, rule_label, effective_until}` ou null e `preview[]` (10 dias uteis: `date`, `weekday`, `situacao`, `presence_type`, `rule_label`); preview de importacao com `ignored`, `warnings`, `ignored_count`; `mensagem` | Escala presencial | Muito alto: `rule_type` | `qa:operational`, `qa:visual`, `qa:schedule-rules`, `qa:schedule-form`, `qa-schedule-history`, `qa-schedule-legacy-output` | Teste de payload (Lote 5c: `remove_rule` para admin e Lideranca; escala de cadastro da equipe Lideranca ou com perfil Lideranca/admin so pelo admin, 403). F1: historico (regra anterior fecha na vespera; `remove_rule` fecha ontem e apaga a agendada); inicio retroativo so admin e Lideranca (403 para gestor); regra para a Lideranca 409; excecao remota de lider 409; regra ou excecao de cadastro "fora do Mapa PA e da escala" 409; linha de lider na importacao ignorada com aviso. F2B: `rule_preview` com as mesmas recusas (403 de perfil, L9 e retroativo; 409 de Lideranca e fora do Mapa); Calendario e Relatorios com saida antiga identica para toda regra que nao e ciclo |
| `portal/pa_map.php` | GET/POST | filtros; `action=save`, `pa_number`, `employee_id`, `valid_from`, `notes`, `confirm_remote_allocation`; `action=remove`, `id` | PAs, employees, can_manage, `mensagem`, warnings | Mapa de PA | Alto: permissao e remoto | Build/lint, `qa-schedule-legacy-output` (ciclo) | Teste admin/tecnico (Lote 5c: escrita e `can_manage` para admin e Lideranca). F2B: `schedule_rule_type` `cycle` com rotulo "Ciclo 2P/1R", `active_on_date` e `presence_status` pelo dia do ciclo; ciclo em dia remoto pede `confirm_remote_allocation` como o sempre remoto; conflito do ciclo conservador (so nao conflita com sempre remoto) ate o F6 |
| `portal/critical_incidents.php` | GET/POST | filtros; detalhe por `id`; `action=create/update/status` com campos de incidente | items, summary, item, `mensagem` | Chamados criticos | Muito alto: muitos campos | Build/lint | Schema e fixture |
| `portal/critical_incidents_import.php` | POST FormData/JSON | `spreadsheet`; depois `action=confirm`, `rows` | preview, contagens, `mensagem` | Chamados criticos | Alto: import | `qa:operational` parse | Fixture import |
| `portal/critical_incidents_export.php` | GET | filtros | CSV/download | Chamados criticos | Medio | Build | Smoke link |
| `portal/documents.php` | GET/POST FormData | document, title, category, description, visibility | limits, items, can_upload, `mensagem` | Documentacao | Alto: upload | Build/lint | Upload fixture controlada |
| `portal/documents_delete.php` | POST | `id` | `mensagem` | Documentacao | Alto: auditoria | Build/lint | Teste permissao |
| `portal/documents_download.php` | GET | `id` | arquivo | Documentacao | Alto: path interno | AppSec visual | Smoke download |
| `portal/shift_attachments.php` | GET/POST FormData | arquivo, title, reference_month, notes | limits, items, can_upload, `mensagem` | Escalas de Sabado | Alto: extensoes | Build/lint | Upload controlado (Lote 5c: upload e `can_upload` para admin e Lideranca; perfil conferido antes do arquivo) |
| `portal/shift_attachments_download.php` | GET | `id`, opcional `preview=1` | arquivo/preview | Escalas de Sabado | Alto | Build/lint | Smoke preview |
| `portal/notifications.php` | GET/POST | POST `id` para marcar leitura | unread, items, `mensagem` | Layout | Medio | Build/lint | Query future |
| `portal/dashboard.php` | GET | nenhum | metricas e cards | Dashboard | Medio | Build/lint | Contrato tipado |
| `portal/calendar.php` | GET/POST | filtros; form de evento | items, `mensagem`. F3 commit 1 (SEC-07, L-01): evento de ausencia sem o motivo para nenhum perfil; `description` com a categoria ("Folga", "Ferias", "Ausencia justificada", "Compromisso", "Outro"; desde a 012) so para gestor, Lideranca, admin e o proprio colaborador; para os demais, vazia (nome e periodo continuam, P9). So ausencia aprovada aparece; `status` diferente de `approved` nao traz ausencia (decisao 5 do F3) | Calendario | Medio | Build/lint, `qa-absences` | Schema futuro |
| `listar_funcionarios.php` | GET | nenhum | `funcionarios` (com `ativo` falso, `ad_login` e `access_role` so para quem tem `funcionarios.manage`: admin e Lideranca; `fora_mapa_escala` para gestor, Lideranca e admin, F1) | Admin, filtros, selects | Alto | Backend smoke | Tipo Employee |
| `adicionar_funcionario.php` / `atualizar_funcionario.php` / `remover_funcionario.php` | POST | cadastro (`access_role`: `tecnico`, `gestor`, `lideranca`, `somente_leitura`) / `funcionario_id` | `mensagem` | Admin (aba Funcionarios) | Alto: RBAC | `qa-security` secoes 5 e 9 | Lote 5b: admin e Lideranca; 400 para `access_role = 'admin'` e para login AD de outro cadastro (ativo ou inativo); 403 para Lideranca promovendo a `lideranca` ou mexendo em cadastro de Lideranca/admin; 401 sem sessao, 403 para os demais perfis. F1: `atualizar_funcionario.php` aceita `fora_mapa_escala` (booleano; ausente mantem o valor; invalido 400); mudar o valor so com `funcionarios.fora_mapa` (admin; 403 para os demais); marcar com vinculo de PA ativo 409 |
| `configuracoes.php` | GET | nenhum | `configuracoes` | Admin | Alto: admin only | Backend smoke | RBAC manual (Lote 5c: sem `auth_source`) |
| `salvar_configuracao.php` | POST | configuracoes de pausa | `mensagem` | Admin | Alto | Backend smoke | Teste admin |
| `usuarios.php` | GET/POST | action listar/criar/atualizar/deletar | usuarios, `mensagem` | Admin local | Alto | Backend smoke | Teste local admin |
| `portal/reports.php` | GET | filtros, opcional `format=csv` | items/resumo ou CSV | Relatorios | Medio | Build/lint | Smoke CSV |

## Listas limitadas e exportacoes (Lote 6, PERF-02 e BIZ-01)

- Lote 4: `portal/announcements.php` filtra por publico (`audience`): gestao (gestor, Lideranca, admin) ve todos; tecnico e somente leitura, os de todos e os da propria equipe. `portal/shift_attachments.php` e `portal/shift_attachments_download.php` filtram por equipe (anexo de outra equipe: 404); o upload exige `team` (`n1`, `n2`, `lideranca` ou `todas`). Banco indisponivel: 503 com mensagem.
- Listas de tela com limite devolvem, junto de `items`, `truncated` (ha mais registros que os exibidos) e `limit`. Vale para `portal/overtime.php` e `portal/time_corrections.php` (500), `portal/critical_incidents.php` (500), `portal/documents.php` (300), `portal/shift_attachments.php` (200) e as listas genericas de `PortalService` (`portal/absences.php`, `portal/announcements.php`, `portal/schedules.php?type=`, `portal/standby.php`; 100). O frontend mostra o aviso com `TruncationNotice`.
- `solicitacoes_pendentes.php` acrescenta `overtime_truncated`, `time_adjustments_truncated` e `limit`.
- `metricas.php` acrescenta `solicitacoes_reuniao_truncadas` e `solicitacoes_reuniao_limite`.
- `portal/overtime.php` (GET) acrescenta `totals.approved_minutes` e `totals.pending_minutes`, calculados sobre o filtro inteiro, sem o filtro de status. Rejeitados nao entram.
- Sem `from`/`to`/`competency`, horas extras e correcao de ponto usam a competencia corrente (16 a 15), nao mais o mes calendario.
- `portal/reports.php`: `summary.overtime_minutes` e `overtime_minutes` por colaborador/equipe foram **substituidos** por `overtime_approved_minutes` e `overtime_pending_minutes`.
- Exportacoes CSV nao truncam: horas extras, correcao de ponto, chamados criticos e o ZIP de pausas (`download_relatorio.php`) percorrem o filtro inteiro em paginas.
- CSV de horas extras: a coluna `Total Realizado` (somava todos os status) foi substituida por `Total aprovado` e `Total pendente`, e entrou a coluna `Status` no fim. Detalhes em `docs/HORAS_EXTRAS_LOTE6.md`.

## Contratos propostos - Parte B (NAO IMPLEMENTADOS)

Desenho aprovado em 2026-09-30; P2, P12 e o tratamento dos relatorios decididos em 2026-10-02. **F2 implementado (2026-10-06):** `portal/presence.php` e `presenca` no `status.php` (tabela acima); Calendario, Relatorios e Mapa PA continuam no calculo antigo ate F5 e F6 (a Escala passou ao motor no F2B). **F1 implementado (2026-10-05):** em `portal/schedules.php`, `is_current`, `is_future`, `config` e `include_history=1` (tabela acima). **F2B implementado (2026-10-07):** ciclo, dias de home, `rule_preview` e a escala gerada da tela pelo motor (tabela acima); Calendario e Relatorios so com o ciclo pela posicao do motor (o resto no calculo antigo ate o F5); Mapa PA com ciclo pelo motor na exibicao e no aviso de remoto, copia gravada como `undefined` com a configuracao (o ENUM nao tem `cycle`). Continua proposta so a excecao sem tipos de ausencia (F3). Fora isso, nada desta secao existe no codigo. A tabela acima continua sendo o contrato em uso; cada linha so muda no lote que implementar o endpoint. Especificacao completa em `docs/DESENHO_PA_ESCALA_AUSENCIA.md`, secao 3.

| Endpoint | Situacao | Mudanca proposta | Lote |
| --- | --- | --- | --- |
| `portal/presence.php` | **Implementado no F2; ausencia parcial e categoria (P9) no F3** (tabela acima). A observacao nunca sai (decisao 4 do F3) | — | F2, F3 |
| `status.php` | Alterado | `presenca` de hoje **implementada no F2** (tabela acima); deixa de incluir arquivados no F4 | F4 |
| `portal/schedules.php` | Alterado | Ciclo, dias de home e `rule_preview` **implementados no F2B** (tabela acima); falta a excecao sem tipos de ausencia | F3 |
| `portal/absences.php` | Alterado | **Implementado no F3 commit 3:** `GET` com `from`, `to` (padrao mes corrente; ate 366 dias), `team`, `employee_id` (so a gestao; tecnico e somente leitura sempre as proprias), `status` (`approved` padrao, `cancelled`, `all`); itens com `id, employee_id, employee_name, team, category, starts_on, ends_on, period_type, start_time, end_time, note, status, retroactive, created_by, created_at, updated_by, updated_at, cancelled_by, cancelled_at`, mais `can_manage`, `can_retroactive`, `categories`, `period`, `truncated`, `limit` (500). `POST` `action=create` (201 `{id, retroactive}`), `update` (`id` + mesmos campos; colaborador nao muda) e `cancel` (`id`), para gestor, Lideranca e admin (403 antes do banco para os demais); retroativo (inicio anterior a hoje, editar ou cancelar ausencia ja iniciada) so Lideranca e admin; ausencia de lider ou de perfil Lideranca/admin so admin; sobreposicao 409 com o periodo em conflito | F3 |
| `portal/calendar.php` | Alterado | `reason` fora do evento de ausencia **implementado no F3 commit 1**; escala vinda do motor e ausencia como situacao do tecnico no F5 | F5 |
| `remover_funcionario.php` | Alterado | Mesmo payload; executa o arquivamento completo e devolve `efeitos` | F4 |
| `restaurar_funcionario.php` | Novo | POST `funcionario_id`; so admin | F4 |
| `atualizar_funcionario.php` | Alterado | Mesmo payload; troca de `ativo` passa pelas rotinas de arquivar e restaurar | F4 |
| `portal/technicians.php` | Alterado | Acrescenta `presence` | F4 |
| `portal/pa_map.php` | Alterado | GET com `presence` por vinculo, `conflicts`, `has_conflict`, `absences_week`, `week`, `server_now`; `save` deixa de devolver 409 por conflito de escala e passa a devolver `warnings` e `conflicts` | F6 |
| `portal/dashboard.php` | Alterado | Acrescenta `server_now` e `presence_summary` por equipe | F7 |
| `portal/reports.php` | Alterado | Escala vinda do motor; `absence_days`, `no_schedule_days`; fim de semana e `undefined` deixam de contar como presencial/remoto | F5 (decisao de 2026-10-05; data de corte registrada no deploy do F5) |

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
