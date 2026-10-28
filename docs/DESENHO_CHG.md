# Desenho — Módulo de CHGs (mudanças)

**Requisito:** `docs/REQUISITOS_CHG.md`
**Data:** 2026-10-28
**Base analisada:** `de93913` no branch `feat/employee-form-rhf-zod`
**Situação:** **proposta, aguardando revisão do responsável.** Sem código, migration ou teste. Não toca no lote de acabamento em andamento, no F8 nem no F9.

Convenções:
- **Fato** = lido no código, com `arquivo:linha`.
- **A DEFINIR** = depende de decisão (detalhe e consequências na seção 4 do requisito).
- **C1, C2, …** = propostas de desenho, a aprovar.

Nenhum dado real: carteira, local, técnico, IP e link aparecem só como marcador (`[CARTEIRA_NOME]`, `[LOCAL_n]`, `[URL_CONSOLE_ENDPOINT]`…). Nada de infraestrutura nova sem decisão (servidor, firewall, conta de serviço ficam como A DEFINIR).

---

## 0. Resumo para decisão

### 0.1 Propostas C1 a C16

| # | Proposta | Motivo |
|---|---|---|
| C1 | `CHG_MODULE_ENABLED` **ausente = desligado**; valor inválido = desligado, com aviso no log | Módulo novo: falha fechada. O `STATUS_PRESENCE_ENABLED` é o contrário (ausente = ligado) porque desliga uma melhoria de algo que já existia (`services/PresenceService.php:32-45`) |
| C2 | Código de carteira fora da base é **recusado** (400), com orientação de pedir a inclusão | A base fica no servidor (RF-CHG-15); texto livre de carteira traria nome de cliente digitado sem controle. O modelo só avisava (`_referencias/controle_atualizacoes_modelo.html:1177`) |
| C3 | Fim do Service Desk **estritamente depois** do início | O modelo aceita igual, mas a barra de SLA some (`:996`, `:649`) |
| C4 | **Botões por etapa**, sem arrastar e soltar nos lotes G3 e G4 | Soltar numa coluna não garante a etapa final, que depende do relógio (`:1017-1019`); botões são acessíveis e testáveis. Arrastar pode vir depois, chamando as mesmas ações |
| C5 | Cancelar só **antes de concluir**; a concluída precisa ser reaberta antes | O modelo cancela até a concluída (`:1024`) |
| C6 | Motivo do cancelamento **opcional, em lista fechada** (A DEFINIR a lista), sem texto livre | Sem texto livre, sem risco de dado pessoal (mesmo critério do F8, `docs/DESENHO_PA_ESCALA_AUSENCIA.md:3001`) |
| C7 | O **status da solicitação** ("Aguardando retorno" / "Aprovado") fica **informativo**, sem efeito na etapa; "Cancelado" vira o cancelamento lógico (C5) | Hoje só "Cancelado" muda a etapa (`:441`). Retirar o campo também é opção (A DEFINIR, sem prioridade) |
| C8 | Até **10 endpoints** por CHG | Limite para payload, tela e CSV; o modelo não tem limite e usa 6 cores em ciclo (`:457`) |
| C9 | **Duplicar** sem planos, endpoints, CHG, técnico e dados de conclusão | Os planos são da CHG; o modelo copiava (`:1052`) |
| C10 | Links **só `https://`** absoluto. Lista branca de domínios opcional em `CHG_LINK_HOSTS` (vazio = qualquer host `https`), **A DEFINIR** | O validador existente só exige `https` (`services/CriticalIncidentService.php:981-992`). A lista branca fecha o link para fora |
| C11 | **Versão do registro** (`row_version`) em toda escrita; diferente da atual = **409** | O modelo deixava a última gravação vencer (`:486-501`) |
| C12 | Número da CHG em maiúsculas, formato `^CHG[0-9]{4,12}$` (**A DEFINIR** com o formato real do ServiceNow). Editável só **enquanto a CHG não está aprovada** | Depois de aprovada, mudar o número exige "Voltar para Comitê" (auditado) |
| C13 | Tipo de execução **fechado**: `padrao` e `emergencial` | O modelo aceita qualquer texto (`:336`), mas só sugere os dois (`:393`) |
| C14 | Layout do quadro por largura: seis colunas de 1536 px para cima; de 1280 a 1535 px, duas linhas de três colunas; abaixo de 1280 px, uma seção por etapa, empilhadas. Medido no G3 | Lição do 17e: nada importante escondido em rolagem horizontal |
| C15 | Preparação **4 h antes**, constante no código (`ChgStagePolicy::PREP_HOURS`) | Igual ao modelo (`:403`); mudar = commit com teste, sem `.env` |
| C16 | Lista com até **500 itens** por resposta, na ordem da janela, com `truncated: true` e aviso; **KPIs sobre o filtro inteiro** | Sem paginação nos primeiros lotes. O arquivamento mantém a lista curta; o volume real é A DEFINIR |

### 0.2 O que bloqueia cada lote

| Lote | Bloqueado por |
|---|---|
| G1 (dados) | Aprovação do desenho; 4.5 do requisito (onde o código vive até o deploy); C2, C6 e C12 (entram na estrutura) |
| G2 (serviço e API) | 4.1 (perfis), C1, C4, C5, C11 |
| G3 e G4 (telas, CSV) | 4.4 (CSV ou Excel), 4.6b (nome e rota), C14 |
| G6 e G7 (condicionais) | 4.2 e 4.3 |

---

## 1. O que o ChronoDesk já tem e o módulo reaproveita

| Necessidade | Fato | Uso no módulo |
|---|---|---|
| Rede do frontend com CSRF | `frontend/src/lib/api.ts:44-88` (`api`), `:51` (`X-CSRF-Token`), `:40` (`apiUrl`), `:90-92` (`post`) | Toda chamada do módulo |
| CSRF no servidor | `security.php:202-219` (`require_csrf_token`); `api/portal/_bootstrap.php:53-95` (`portal_json_input`: CSRF, JSON, 64 KB) | Toda escrita |
| Perfis e permissões | `security.php:930-966` (`portal_permissions_for_role`), `:1092` (`portal_role_can`), `:898` (`PORTAL_MANAGER_ROLES`) | Permissões `chg.*` (3.8) |
| Somente leitura barrado | `api/portal/_bootstrap.php:43-51` | Toda escrita |
| Ator da requisição | `api/portal/_bootstrap.php:32-41` (`portal_actor`: perfil, usuário, `employee_id` do técnico) | Checagem por objeto do técnico |
| Mapa de exceção para HTTP | `api/portal/_bootstrap.php:97-117` (400, 403, 409, 413, 503) | Igual |
| Endpoint com ações em POST | `api/portal/absences.php:9-34` | Modelo do `chg.php` |
| Auditoria | `security.php:696-724` (`audit_log`, detalhe até 5.000 caracteres); tabela em `database_SECURED.sql:78-89` | Eventos da seção 5 |
| CSV | `api/portal/critical_incidents_export.php:11-46` (BOM, `;`, `no-store`, auditoria); `security.php:103-110` (`csv_neutralize_cell`); `services/OperationalService.php:1187-1213` | `chg_export.php` |
| Link `https` | `services/CriticalIncidentService.php:981-992` | Validador de link (ampliado, 3.1) |
| Hora do servidor | `api/portal/dashboard.php:53` (`server_now`, `DATE_ATOM`); `config.php:53` (`America/Sao_Paulo`) | `server_now` em toda resposta |
| Regra de fuso das migrations | `docs/FUSO_HORARIO_BIZ02.md:317` (`SET time_zone = 'America/Sao_Paulo';` no topo) | Migrations 021 e 022 |
| Interruptor no `.env` e no preflight | `services/PresenceService.php:32-45`; `scripts/preflight-deploy.sh:127-130`; `.env.example:52` | `CHG_MODULE_ENABLED` (seção 8) |
| Menu por permissão | `frontend/src/lib/navigation.ts:1-18`, `:23-49`; filtro em `frontend/src/components/PortalLayout.jsx:98`; rota negada em `frontend/src/App.jsx:136-138`; permissões na sessão por `api/session.php:14` | Item "Mudanças (CHG)" |
| Página sob demanda | `frontend/src/App.jsx:13-33` (`lazyPage`) | `ChgPage` |
| Atualização periódica | `frontend/src/hooks/useResource.ts:7`, `:24` (`intervalMs`) | 60 s com a tela visível |
| Proibição de `innerHTML` e `localStorage` | `frontend/scripts/qa-ux-hardening.mjs:56-62` | Vale para o módulo sem mudança |
| Cadastro de funcionários | `database_SECURED.sql:16-25` (`funcionarios.id INT`, `equipe`, `ativo`); arquivamento pela 014 (`migrations/20261013_014_employee_archive.sql:4`) | `technician_id` com FK |
| FK com `RESTRICT` para `funcionarios` | `migrations/20261007_012_absences.sql:183-189` | Igual |
| Ensaio de migration | `scripts/ensaio-migration.sh:5-8` (`DADOS`, `VERIFICA`) | Ensaio da 021 e da 022 |
| Bateria local | `scripts/test-local.ps1:63-166` (só QAs PHP; os `qa:*` rodam pelo `npm`) | `qa-chg.php` na bateria |
| Numeração das migrations | `docs/DESENHO_PA_ESCALA_AUSENCIA.md:1571` (018 do F8, 019 usada, 020 do F9) | **021 e 022** |
| ServiceNow | `services/integrations/IntegrationRegistry.php:13` (só cartão de situação) | Nada no G1 a G5 (4.3) |
| Composer | Não há `composer.json` no repositório | Excel exigiria dependência (4.4) |

---

## 2. Modelo de dados

Regras para as duas migrations, as mesmas da Parte B:
- `SET time_zone = 'America/Sao_Paulo';` no topo;
- idempotentes (`information_schema` antes de criar), InnoDB, `utf8mb4`;
- rollback em `migrations/rollback/` e verificação em `migrations/verificacao/` **só com contagens**;
- ensaio pelo `scripts/ensaio-migration.sh`.

Datas em `DATETIME`, na hora de `America/Sao_Paulo` (regra do A4, não UTC); na API, ISO 8601 com deslocamento. Autoria em `VARCHAR(100)` com o usuário da sessão, como `portal_critical_incidents.created_by` (`migrations/20260614_006_critical_incidents.sql:33`) e `portal_absences` (`migrations/20261007_012_absences.sql:126-127`). Nenhuma linha é apagada pela aplicação.

### 2.1 Migration 021 — referências (carteiras e locais)

`portal_chg_portfolios`:

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | |
| `code` | `VARCHAR(10) NOT NULL` | `UNIQUE uk_chg_portfolio_code`; só dígitos (conferido na carga) |
| `name` | `VARCHAR(150) NOT NULL` | |
| `active` | `TINYINT(1) NOT NULL DEFAULT 1` | Código que sumiu do arquivo fica inativo, nunca apagado (é referenciado por CHG) |
| `updated_at` | `DATETIME NOT NULL` | |
| `updated_by` | `VARCHAR(100) NOT NULL` | Quem rodou a carga |

`portal_chg_sites` (locais): `id`, `name VARCHAR(60) NOT NULL` com `UNIQUE`, `active`, `updated_at`, `updated_by`. A mesma carga preenche as duas.

A migration **não insere nenhuma linha** (os valores são reais e ficam fora do git). Rollback: `DROP` só se as duas estiverem vazias **e** a 022 não estiver aplicada; senão, para com `SIGNAL` (lição do F1: rollback que encontra dado não segue sozinho).

### 2.2 Migration 022 — CHGs e endpoints

`portal_chg_changes`:

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | |
| `requester` | `VARCHAR(100) NOT NULL` | Solicitante (nome). Com 4.1 "login no AD": coluna `requester_login` e regra própria |
| `portfolio_id` | `INT NOT NULL` | FK `portal_chg_portfolios(id)` `RESTRICT`; só carteira ativa ao gravar (C2) |
| `site_id` | `INT NOT NULL` | FK `portal_chg_sites(id)` `RESTRICT` |
| `pa_count` | `SMALLINT UNSIGNED NOT NULL` | `CHECK (pa_count BETWEEN 1 AND 9999)` |
| `execution_type` | `ENUM('padrao','emergencial') NOT NULL DEFAULT 'padrao'` | C13 |
| `version_path` | `VARCHAR(500) NULL` | Versão e caminho de rede (texto; não é link) |
| `description` | `VARCHAR(300) NULL` | Texto livre (LGPD, seção 7) |
| `homologation_ticket` | `VARCHAR(60) NULL` | Chamado de homologação |
| `pa_sheet_url` | `VARCHAR(1000) NULL` | Só `https` (C10) |
| `suggested_at` | `DATETIME NOT NULL` | Data sugestão |
| `request_status` | `ENUM('aguardando_retorno','aprovado') NOT NULL DEFAULT 'aguardando_retorno'` | Informativo (C7) |
| `window_start` | `DATETIME NULL` | Início do Service Desk |
| `window_end` | `DATETIME NULL` | `CHECK (window_start IS NULL OR window_end IS NULL OR window_end > window_start)` (C3) |
| `chg_number` | `VARCHAR(20) NULL` | Maiúsculas, formato no serviço (C12); índice não único (uma CHG pode cobrir mais de um card; A DEFINIR) |
| `chg_status` | `ENUM('nao_solicitada','pendente','aprovada') NOT NULL DEFAULT 'nao_solicitada'` | `CHECK ((chg_number IS NULL AND chg_status = 'nao_solicitada') OR (chg_number IS NOT NULL AND chg_status IN ('pendente','aprovada')))` |
| `plan_deploy`, `plan_backup`, `plan_rollback` | `VARCHAR(40) NULL` | Números dos planos, em maiúsculas |
| `technician_id` | `INT NULL` | FK `funcionarios(id)` `RESTRICT`; só cadastro ativo e de equipe permitida ao gravar (4.6c) |
| `completed_at`, `completed_by` | `DATETIME NULL`, `VARCHAR(100) NULL` | |
| `cancelled_at`, `cancelled_by` | `DATETIME NULL`, `VARCHAR(100) NULL` | |
| `cancel_reason` | `ENUM(...) NULL` | C6, lista A DEFINIR; sem a lista, a coluna não entra |
| `archived_at`, `archived_by` | `DATETIME NULL`, `VARCHAR(100) NULL` | |
| `row_version` | `INT UNSIGNED NOT NULL DEFAULT 1` | C11 |
| `created_at`, `created_by`, `updated_at`, `updated_by` | | |

Restrições extras:
- `CHECK (NOT (completed_at IS NOT NULL AND cancelled_at IS NOT NULL))`;
- `CHECK (archived_at IS NULL OR completed_at IS NOT NULL OR cancelled_at IS NOT NULL)`;
- em cada par data e autor, os dois juntos ou nenhum.

Índices:
- `idx_chg_window (archived_at, window_start)`: lista padrão e "Janelas hoje";
- `idx_chg_technician (technician_id, archived_at)`: escopo do técnico e dashboard;
- `idx_chg_number (chg_number)`;
- `idx_chg_portfolio (portfolio_id)` e `idx_chg_site (site_id)` (as FKs pedem).

`portal_chg_endpoints`:

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | |
| `change_id` | `INT NOT NULL` | FK `portal_chg_changes(id)` `RESTRICT` |
| `position` | `TINYINT UNSIGNED NOT NULL` | `UNIQUE (change_id, position)`; 1 a 10 (C8) |
| `url` | `VARCHAR(1000) NULL` | Só `https` |
| `patch_start` | `DATETIME NULL` | Início do patch (data e hora completas) |

`CHECK (url IS NOT NULL OR patch_start IS NOT NULL)`. A lista é trocada inteira pela ação `set_endpoints`, na mesma transação, com a CHG travada; a remoção física das linhas antigas é auditada como alteração da CHG.

### 2.3 Histórico por CHG (na 022)

`portal_chg_history`:

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | |
| `change_id` | `INT NOT NULL` | FK `RESTRICT`; índice `(change_id, created_at)` |
| `event` | `VARCHAR(40) NOT NULL` | Lista fechada no código (os mesmos nomes da seção 5, sem o prefixo) |
| `stage_before`, `stage_after` | `VARCHAR(10) NULL` | Etapa calculada no instante da ação |
| `fields` | `VARCHAR(500) NULL` | Só **nomes** dos campos alterados, nunca valores |
| `actor` | `VARCHAR(100) NOT NULL` | |
| `created_at` | `DATETIME NOT NULL` | |

Mostra na tela "quem fez o quê e quando", sem ler o `audit_log`. Mudança de etapa só pelo relógio (comitê → preparação → execução) **não** gera linha: a etapa é derivada.

### 2.4 Migrations 023 em diante (condicionais)

Só com a resposta do 4.2 do requisito: `portal_chg_tasks`, `portal_chg_approvals` e `portal_chg_committees`. A integração com o ServiceNow (4.3), se vier, pode pedir colunas de sincronização (`external_id`, `synced_at`). Desenho próprio em cada caso.

### 2.5 Carga de carteiras e locais (fora do git)

Script `scripts/carregar-referencias-chg.php`:
- roda no servidor, **por quem tem acesso ao banco**, como as migrations;
- recebe o caminho de um CSV (`codigo;nome` para carteiras e `nome` para locais);
- o arquivo fica **fora do repositório**, num diretório escolhido pelo responsável (A DEFINIR).

Regras:
1. Recusa arquivo dentro da árvore do repositório (`realpath` com o prefixo do repositório), para ninguém versioná-lo por engano.
2. Valida cada linha: código só com dígitos, nome não vazio, tamanhos, UTF-8 válido. A primeira linha inválida faz **parar sem gravar nada** (transação).
3. Inclui o que é novo e atualiza o nome do que existe. Inativa o que sumiu do arquivo e reativa o que voltou. **Nunca apaga.**
4. Saída **só com contagens** (incluídas, atualizadas, inativadas, reativadas); auditoria `CHG_REFERENCES_LOADED` com as contagens.
5. Idempotente: rodar duas vezes com o mesmo arquivo dá 0 alterações na segunda.

Quem mantém o arquivo e com que frequência é A DEFINIR (governança da lista de carteiras).

---

## 3. Contratos da API

### 3.1 Convenções

- Todos em `api/portal/`.
- Primeira linha de cada endpoint: `require_chg_module_enabled()`. Desligado, responde **404** `{"sucesso":false,"mensagem":"Módulo indisponível."}` antes de autenticar e de tocar no banco (seção 8).
- Depois: `require_http_method`, `require_portal_auth`, `portal_actor`.
- Escrita: `require_portal_write_access` e `portal_json_input` (CSRF, JSON, 64 KB).
- Erros por `portal_operational_error`:
  - **400**: validação;
  - **403**: perfil ou objeto;
  - **404**: CHG inexistente ou módulo desligado;
  - **409**: estado não permite a ação, ou `row_version` diferente;
  - **413**: payload grande;
  - **503**: banco.
- Toda resposta de sucesso traz `server_now` (`DATE_ATOM`).
- **Validador de link:**
  - aceita só `FILTER_VALIDATE_URL` com esquema `https`;
  - recusa usuário ou senha na URL, espaço ou caractere de controle e mais de 1.000 caracteres;
  - recusa host fora de `CHG_LINK_HOSTS` quando a lista existir (C10);
  - vazio vira `null`.
- Texto: `trim`, tamanho máximo e UTF-8 válido. Campos em maiúsculas: número da CHG e planos.

### 3.2 `GET portal/chg.php` — lista

Parâmetros, todos opcionais e validados por lista branca:

| Parâmetro | Regra |
|---|---|
| `q` | Até 100 caracteres. Contém em solicitante, carteira (código e nome), local, versão, CHG, técnico, chamado, tipo e descrição. SQL com `LIKE` parametrizado e `%`/`_` escapados |
| `requester` | Igualdade, até 100 |
| `site_id`, `technician_id` | Inteiro positivo |
| `execution_type` | `padrao` ou `emergencial` |
| `chg` | `sem`, `pendente` ou `aprovada` |
| `from`, `to` | `AAAA-MM-DD`; compara com `COALESCE(window_start, suggested_at)`; dia inteiro |
| `closed` | `1` (padrão) ou `0` |
| `archived` | `0` (padrão) ou `1` (só com `chg.manage`) |

Escopo: com a resposta de 4.1 "técnico vê só as próprias", o perfil `tecnico` recebe só `technician_id = employee_id`, imposto no servidor.

Resposta:

```json
{
  "sucesso": true,
  "server_now": "2026-10-28T14:00:00-03:00",
  "prep_hours": 4,
  "kpis": {
    "em_andamento": 0, "aguardando_retorno": 0,
    "aguardando_comite": 0, "aprovadas_aguardando_janela": 0,
    "em_preparacao": 0, "sem_endpoint": 0,
    "em_execucao": 0, "sem_tecnico": 0,
    "janelas_hoje": 0, "pas_hoje": 0
  },
  "total": 0,
  "filtered": 0,
  "truncated": false,
  "items": [{
    "id": 1, "row_version": 1,
    "stage": "comite", "late": false, "waiting_window": true, "prep_at": "…",
    "requester": "[SOLICITANTE]",
    "portfolio": { "id": 1, "code": "[CARTEIRA_COD]", "name": "[CARTEIRA_NOME]" },
    "site": { "id": 1, "name": "[LOCAL_1]" },
    "pa_count": 12, "execution_type": "padrao",
    "version_path": "…", "description": "…", "homologation_ticket": "…",
    "pa_sheet_url": "https://…", "suggested_at": "…", "request_status": "aprovado",
    "window_start": "…", "window_end": "…",
    "chg_number": "CHG…", "chg_status": "aprovada",
    "plans": { "deploy": "…", "backup": "…", "rollback": "…" },
    "technician": { "id": 7, "name": "[TECNICO_1]" },
    "endpoints": [{ "position": 1, "url": "https://[URL_CONSOLE_ENDPOINT]", "patch_start": "…" }],
    "completed_at": null, "cancelled_at": null, "archived_at": null,
    "created_at": "…", "updated_at": "…"
  }]
}
```

- `stage` ∈ `triagem`, `comite`, `prep`, `exec`, `done`, `cancel` (seção 4).
- Ordem: `COALESCE(window_start, suggested_at)`, depois `window_end` (nulo por último), depois `created_at` e `id`, como o `sortByWindow` (`:760-766`).
- Até 500 itens; os KPIs e o `filtered` cobrem o filtro inteiro (C16).
- Consultas fixas, sem N+1: (1) CHGs do filtro com carteira, local e técnico por `JOIN`; (2) endpoints das CHGs devolvidas, por `IN`; (3) total do escopo.

### 3.3 `GET portal/chg.php?id=N` — detalhe

O item da lista, mais `history[]` (`event`, `stage_before`, `stage_after`, `fields`, `created_at` e, só para quem tem `chg.manage`, `actor`). Fora do escopo do técnico: **404**, para não revelar a existência.

### 3.4 `POST portal/chg.php` — ações

Corpo: `{ "action": "...", "id": N, "row_version": V, ... }` (sem `id` e `row_version` no `create`).

Toda ação, exceto `create`:
1. abre transação;
2. lê a CHG com `SELECT … FOR UPDATE` (lição do F1: leitura que decide escrita depois da trava também trava);
3. confere `row_version`, escopo, permissão e estado;
4. grava com `row_version + 1` e a linha de histórico;
5. faz o commit e a auditoria.

Resposta: o item atualizado, com a etapa recalculada.

| Ação | Permissão | Estado exigido | Efeito |
|---|---|---|---|
| `create` | `chg.manage` | — | Cria. Com número: `pendente`; sem número: `nao_solicitada`. **201** |
| `update` | `chg.manage` | Não cancelada, não arquivada | Campos do formulário (requisito 1.2). Número e status da CHG seguem as regras de `set_chg` |
| `set_chg` | `chg.manage` | `chg_status` ≠ `aprovada`; não concluída, cancelada ou arquivada | Grava o número (vazio volta para "Aguardando retorno" e zera o status) e o status `pendente` |
| `approve` | `chg.approve` | Com número, `pendente`, não concluída, cancelada ou arquivada | `aprovada`. A etapa segue o relógio (comitê com "aguardando janela", preparação ou execução) |
| `back_to_committee` | `chg.approve` | `aprovada`, não concluída | `pendente` |
| `assign_technician` | `chg.execute` (gestão) | Não concluída, cancelada ou arquivada | `technician_id` ativo e de equipe permitida, ou `null`. Técnico se atribuir a si mesmo: A DEFINIR |
| `set_plans` | `chg.execute` | Etapa `comite`, `prep` ou `exec` | Os três planos (como na edição do card, requisito 1.5) |
| `set_endpoints` | `chg.execute` | Etapa `prep` ou `exec` | Troca a lista (até 10) |
| `finish` | `chg.execute` | Etapa **`exec`** e com técnico | `completed_at`/`by` |
| `reopen` | `chg.execute` (gestão) | Concluída, não arquivada | Limpa a conclusão |
| `cancel` | `chg.manage` | Não concluída, cancelada ou arquivada (C5) | `cancelled_at`/`by` e o motivo (C6) |
| `uncancel` | `chg.manage` | Cancelada, não arquivada | Limpa o cancelamento |
| `archive` | `chg.manage` | Concluída ou cancelada | `archived_at`/`by` |
| `restore` | `chg.restore` | Arquivada | Limpa o arquivamento |

**Checagem por objeto do técnico** (perfil `tecnico`): `set_plans`, `set_endpoints` e `finish` só com `technician_id = employee_id` do ator. Fora disso, 403 e `CHG_WRITE_DENIED`. A gestão age em qualquer CHG do escopo.

Mensagens de 409 com o motivo e o que fazer. Exemplos:
- "Só atualizações em execução podem ser concluídas.";
- "Informe o técnico responsável antes de concluir.";
- "Esta CHG foi alterada por outra pessoa. Recarregue e tente de novo."

### 3.5 `GET portal/chg_referencias.php`

Permissão `chg.manage`. Devolve:
- `portfolios` (`id`, `code`, `name`, só ativas);
- `sites` (ativos);
- `technicians` (`id`, `name`, `team`; cadastro ativo, dentro do Mapa, equipes permitidas pelo 4.6c);
- `requesters` (solicitantes distintos já usados, para sugestão; só para quem cadastra).

Quatro consultas fixas.

### 3.6 `GET portal/chg_export.php` — CSV

- Permissão `chg.export`; mesmos filtros e escopo da lista; **sem o limite de 500**.
- Padrão do `critical_incidents_export.php`: BOM, `;`, `csv_neutralize_cell` em toda célula, `Cache-Control: private, no-store`.
- Nome `chg_AAAA-MM-DD.csv`, com a data do servidor.
- Auditoria `CHG_EXPORTED` com a contagem e **os nomes** dos filtros usados (sem o texto da busca).

Colunas, na ordem da aba "Base" do modelo (`:1248-1268`), com a etapa na frente:
- Etapa, Solicitante, Descrição, Carteira/código, Local, Quantidade de PAs;
- Versão/caminho, Chamado de homologação, Link planilha com PAs, Tipo de execução;
- Data sugestão, Início Service Desk, Fim Service Desk, Status, CHG, Status CHG;
- Técnico responsável, Plano de Implantação, Plano de Backup, Plano de RollBack, Concluída em;
- depois, para cada endpoint até o maior número do conjunto: Endpoint N e Início do patch N.

Datas `dd/mm/aaaa hh:mm`.

### 3.7 Sessão e menu

- Com o módulo ligado, `portal_permissions_for_role` acrescenta as `chg.*` do perfil. O `session.php` já devolve as permissões (`api/session.php:14`).
- No frontend:
  - `Permission` ganha `chg.read` (`frontend/src/lib/navigation.ts:1-7`);
  - o item `{ path: '/mudancas', label: 'Mudanças (CHG)', icon: …, permission: 'chg.read' }` entra em "Fluxos operacionais" (4.6b);
  - `pageMetadata` ganha a entrada;
  - o `App.jsx` ganha a rota para `ChgPage`.
- Desligado: sem `chg.read`, o menu esconde o item (`PortalLayout.jsx:98`) e a rota cai em "Acesso não autorizado" (`App.jsx:136-138`).

### 3.8 Matriz de perfis (proposta; A DEFINIR em 4.1)

| Ação | admin | lideranca | gestor | tecnico | somente_leitura |
|---|---|---|---|---|---|
| Ver o módulo, a lista e o detalhe (`chg.read`) | Sim | Sim | Sim | Sim (todas ou só as próprias: A DEFINIR) | Sim (A DEFINIR) |
| Cadastrar, editar, duplicar, CHG, cancelar, descancelar, arquivar (`chg.manage`) | Sim | Sim | Sim | Não | Não |
| Registrar aprovação e voltar ao comitê (`chg.approve`) | Sim | Sim | Sim (A DEFINIR) | Não | Não |
| Atribuir técnico e reabrir (`chg.execute`, gestão) | Sim | Sim | Sim | Não | Não |
| Planos, endpoints e concluir (`chg.execute`) | Sim | Sim | Sim | **Só a atribuída a ele** | Não |
| Exportar CSV (`chg.export`) | Sim | Sim | Sim | Não | Não |
| Restaurar arquivada (`chg.restore`) | Sim | Não | Não | Não | Não |

Em código:
- `chg.read`, `chg.manage`, `chg.approve`, `chg.execute` e `chg.export` entram no `$gestor` (e com isso na Liderança e no admin);
- `chg.restore` só no admin;
- `tecnico` recebe `chg.read` e `chg.execute`. A diferença entre "gestão" e "técnico" no `chg.execute` é a checagem por objeto, decidida pelo perfil (`portal_role_is_manager`).

Se os solicitantes tiverem login (4.1), entra um perfil novo com `chg.read` e um subconjunto de `chg.manage` restrito às próprias solicitações, em lote de autenticação separado.

---

## 4. Etapa no servidor (`ChgStagePolicy`)

`services/ChgStagePolicy.php` (`declare(strict_types=1)`, como os serviços da Parte B):
- classe final, sem banco;
- `stage(array $change, DateTimeImmutable $now): array` devolve `stage`, `late`, `waiting_window` e `prep_at`.

Regras, na ordem do modelo (`:440-450`):

| # | Condição | `stage` | Extras |
|---|---|---|---|
| 1 | `cancelled_at` não nulo | `cancel` | |
| 2 | `completed_at` não nulo | `done` | |
| 3 | `chg_number` nulo | `triagem` | |
| 4 | `chg_status` ≠ `aprovada` | `comite` | |
| 5 | `window_start` nulo | `prep` | "Sem início definido" |
| 6 | `now ≥ window_start` | `exec` | `late = window_end ≠ null && window_end < now` |
| 7 | `now ≥ window_start − 4 h` | `prep` | |
| 8 | senão | `comite` | `waiting_window = true`, `prep_at = window_start − 4 h` |

- Arquivada mantém a etapa (concluída ou cancelada) e sai da lista padrão pelo filtro.
- `− 4 h` é subtração de intervalo absoluto (`sub(new DateInterval('PT4H'))`) sobre a hora de `America/Sao_Paulo`.
- `now` vem de quem chama: na requisição, um único `new DateTimeImmutable('now')` usado para todos os itens e devolvido em `server_now`; no QA, instantes fixos.
- O frontend mostra `stage` e calcula "faltam" e "restam" só pela diferença entre `server_now` e as datas do item. Com a atualização de 60 s, o atraso máximo de uma mudança de etapa na tela é de 60 s.
- **KPIs** no servidor, sobre o filtro inteiro e com o mesmo `now`. "Janelas hoje" é a CHG não cancelada cuja janela [`window_start`, `window_end` ou `window_start`] toca o dia de `now`, de 00:00 a 00:00 do dia seguinte.

---

## 5. Eventos de auditoria

Todos por `audit_log`. O detalhe leva só ids, etapas, nomes de campos e contagens. **Nunca:** descrição, versão/caminho, links, solicitante, chamado, nome de carteira ou de técnico, nem o texto da busca.

| Evento | Severidade | Quando | Detalhe |
|---|---|---|---|
| `CHG_CREATED` | INFO | `create` | `id`, etapa |
| `CHG_UPDATED` | INFO | `update`, `set_plans` | `id`, campos alterados (nomes) |
| `CHG_NUMBER_SET` | INFO | `set_chg` | `id`, com ou sem número, etapa antes e depois |
| `CHG_APPROVED` | INFO | `approve` | `id`, etapa resultante |
| `CHG_BACK_TO_COMMITTEE` | INFO | `back_to_committee` | `id` |
| `CHG_TECHNICIAN_ASSIGNED` | INFO | `assign_technician` | `id`, `technician_id` antes e depois |
| `CHG_ENDPOINTS_UPDATED` | INFO | `set_endpoints` | `id`, quantidade antes e depois |
| `CHG_FINISHED` / `CHG_REOPENED` | INFO | `finish` / `reopen` | `id` |
| `CHG_CANCELLED` / `CHG_UNCANCELLED` | INFO | `cancel` / `uncancel` | `id`, motivo (código da lista) |
| `CHG_ARCHIVED` / `CHG_RESTORED` | INFO | `archive` / `restore` | `id` |
| `CHG_EXPORTED` | INFO | CSV | contagem, nomes dos filtros |
| `CHG_WRITE_DENIED` | WARNING | Perfil sem permissão ou técnico fora da própria CHG | ação, perfil, `id` |
| `CHG_CONFLICT` | INFO | `row_version` diferente | ação, `id` |
| `CHG_REFERENCES_LOADED` | INFO | Carga de carteiras e locais | contagens |

Erros inesperados vão para o `error_log` só com a classe da exceção (padrão do F7).

---

## 6. Telas (esboço)

- **`frontend/src/pages/ChgPage.jsx`** (sob demanda), com abas Quadro, Lista e Dashboard; KPIs no topo; barra de filtros. Arquivos de apoio:
  - `frontend/src/lib/chg.ts`: tipos, rótulos, cor e ícone por etapa (classes fora do `@layer components`, pela lição do F5 e do F6), cópia da tabela e agregação do dashboard;
  - esquema do formulário em `frontend/src/lib/formSchemas.ts`.
- **Quadro (C14):** colunas com título, contador, dica e texto de vazio (requisito 1.9); card com os mesmos blocos do modelo (requisito 1.5) e botões por etapa (C4); confirmação na própria linha, sem `window.confirm`.
- **Lista:** as 12 colunas; cartões abaixo de 1280 px.
- **Dashboard:** visão geral por etapa, barra por técnico, "Sem técnico atribuído" (as 8 primeiras; abrem a edição) e tabela de detalhe. Calculado da lista recebida, com `stage` do servidor.
- **Formulário:** grupos "Solicitação", "Janela", "CHG e execução" e "Planos", como no modelo (`:329-357`).
  - Seletor de carteira pelo código, com o nome; seletor de local; seletor de técnico (`technicians`).
  - Preenchimento automático de início e fim igual ao do modelo.
  - Aviso nos campos de texto livre (seção 7).
- **Copiar tabela:** `chgCopyTable(item)` devolve `{ html, text }`. É função pura com escape de `& < > " '` e links só `https`. A cópia vai por `navigator.clipboard.write` com `text/html` e `text/plain`; a reserva é `writeText(text)`; a última reserva é uma caixa React com a tabela para selecionar. Sem `innerHTML`, sem `execCommand`.
- **Links:** `<a href target="_blank" rel="noopener noreferrer">`, só com o valor que o servidor aceitou.
- **Exportar:** `<a href={apiUrl('portal/chg_export.php?…')}>`, como os Chamados críticos.

---

## 7. LGPD

| Dado | Classificação | Tratamento |
|---|---|---|
| Solicitante (nome; ou login, com 4.1) | Pessoal (colaborador) | Só o necessário (nome). Visível a quem tem `chg.read`. Sugestões só para `chg.manage`. Fora da auditoria |
| Técnico | Pessoal, por referência | `technician_id`; o nome vem do cadastro. Arquivado continua no histórico, como nos relatórios do F4 |
| Autoria (`created_by`, `actor` do histórico) | Pessoal (usuário) | `actor` só para `chg.manage` |
| Carteiras (código e nome) | Comercial confidencial (clientes), não pessoal | Tabela no servidor, carregada de arquivo fora do git (2.5); nunca em migration, fixture, QA ou doc |
| Locais | Interno | Igual às carteiras |
| Versão/caminho | Infraestrutura (pode conter `[IP_INTERNO]`) | Só no módulo e no CSV; nunca em log ou auditoria |
| Link da planilha com PAs | Pode levar a lista com pessoas | Só `https`, lista branca opcional (C10); fora da auditoria |
| Endpoints (link e ID do console) | Infraestrutura | Igual ao link da planilha |
| Descrição e chamado (texto livre) | Risco de dado pessoal de cliente digitado | Aviso no campo: "Não inclua dados de clientes ou devedores (nome, CPF, contrato)." Tamanho máximo. Nunca em log ou auditoria |
| CSV exportado | Confidencial (carteiras e nomes) | Permissão própria, auditoria com contagem, `no-store`, neutralização de fórmula |

Outras regras:
- **Retenção:** nenhuma exclusão pela aplicação. A anonimização segue a política de retenção LGPD, que está pendente para depois do deploy (`docs/DESENHO_PA_ESCALA_AUSENCIA.md:2696`).
- **Inventário:** linha nova na seção 5.3 da documentação técnica (`docs/DOCUMENTACAO_TECNICA.md:122`), no G5.
- **Carga inicial (requisito 4.6a):** se os cards do modelo estiverem no armazenamento do ambiente de publicação (`:510-520`), a decisão de importar e de apagar a cópia de lá é do responsável.

---

## 8. Interruptor `CHG_MODULE_ENABLED`

- **Leitura:** `ChgModule::enabled()`, no padrão de `PresenceService::statusPresenceEnabled()`, com o padrão invertido (C1):
  - ausente ou vazio = desligado;
  - `1`, `true`, `on`, `yes` = ligado;
  - `0`, `false`, `off`, `no` = desligado;
  - outro valor = desligado, com aviso no `error_log`.
- **Desligado:**
  - `require_chg_module_enabled()` responde 404 nos três endpoints (antes da autenticação e do banco);
  - `portal_permissions_for_role` não acrescenta `chg.*`, então o menu esconde o item e a rota nega.
- **Preflight:** linha `CHG_MODULE_ENABLED: OK (ligado|desligado)` ou `FALHA (use true ou false)`, como `scripts/preflight-deploy.sh:127-130`.
- **`.env.example` e `.env.production.example`:** `CHG_MODULE_ENABLED=false`.
- **Desligar não apaga dado:** é o primeiro passo de qualquer rollback do módulo.

---

## 9. Plano de testes

1. **`scripts/qa-chg.php`** (PHP, sem banco real; PDO simulado como os QAs da Parte B):
   - **Etapa:** a tabela da seção 4 com instantes fixos, inclusive os limites exatos (início − 4 h às 16:00:00, início às 20:00:00 e fim igual a agora, que não atrasa); a ordem de precedência (cancelada e concluída antes de tudo); sem início.
   - **Transições × perfis:** matriz completa gerada (5 perfis × 14 ações × estados), com 403 do técnico em CHG de outro e do somente leitura, e 409 por estado e por `row_version`. A trava `FOR UPDATE` é conferida no SQL registrado pelo PDO simulado.
   - **Validações:** obrigatórios; tamanhos; `pa_count`; C3; C12; carteira inativa ou fora da base; local inativo; técnico inativo, arquivado ou de equipe não permitida. Links maliciosos: `http://`, `javascript:`, `data:`, usuário e senha, espaço, `https:` sem host, mais de 1.000 caracteres e host fora da lista.
   - **Auditoria:** eventos esperados por ação. Um marcador injetado na descrição, no caminho, nos links e no solicitante **não aparece** em nenhum detalhe de auditoria nem no `error_log`.
   - **CSV:** BOM, `;`, neutralização, colunas na ordem e endpoints dinâmicos.
   - **Interruptor:** desligado, 404 em **todo** `api/portal/chg*.php` (lista lida do diretório, para um endpoint novo não escapar) e permissões sem `chg.*`; valores inválidos.
   - **Migrations:** `SET time_zone` no topo, idempotência pelo texto, rollback com as guardas.
   - **Carga de referências:** recusa arquivo dentro do repositório; para na primeira linha inválida sem gravar; inativa sem apagar; segunda execução com 0 alterações; saída só com contagens.
2. **`scripts/qa-security.php`:** casos de ponta a ponta dos três endpoints por perfil, com o módulo ligado e desligado (o padrão dos processos filhos que já existe).
3. **`frontend/scripts/qa-chg.mjs`** (`qa:chg`, o 19º `qa:*`). Renderiza a `ChgPage` de verdade (`registerHooks`, TypeScript, `react-dom/server`, como o `qa:calendar-presence` do 17b) e confere:
   - colunas pela `stage` do servidor;
   - nenhum `new Date()` ou `Date.now()` no cálculo de etapa (checagem do código de `lib/chg.ts`);
   - KPIs vindos do servidor; colunas da lista; agregação do dashboard;
   - escape na cópia (`<img onerror>` na descrição sai escapado no HTML; tabulação e quebra de linha no texto);
   - link só `https` como `<a>` com `rel`;
   - item de menu só com `chg.read`;
   - as classes de largura (C14);
   - cores da etapa presentes no CSS compilado (a varredura genérica do `qa:calendar-presence` cobre o padrão `prefixo-${x}`).
4. **Mutação:** em cada lote de código, numa cópia do repositório (scripts na pasta temporária da sessão). Todos os mutantes mortos ou justificados como equivalentes.
5. **`scripts/test-local.ps1`:** bloco do `qa-chg.php` no mesmo padrão dos atuais (`:86-166`). O `qa:chg` entra no `package.json`.
6. **Ensaio no roteiro:**
   - 021 e 022 pelo `ensaio-migration.sh`, com `VERIFICA` só de contagens (tabelas existem, 0 linhas, FKs e `CHECK` presentes; rollback de volta);
   - ensaio do código por `git archive` rodando o `qa-chg.php` como `www-data`, como no F7, com a conferência de que não há `/tmp/.env`;
   - carga das referências num banco de ensaio, com o arquivo real fora do repositório e saída só com contagens.
7. **Medição:** `medir-polling.sh` com `URL=…/api/portal/chg.php` como referência, depois de ligar o módulo, junto com `status.php` e `dashboard.php` (critério do F2).

---

## 10. Lotes

| Lote | Conteúdo | Commits de entrega (estimativa) | Depende de |
|---|---|---|---|
| G0 | `.gitignore` (`de93913`), requisito e desenho (este) | 1 de docs | — |
| G1 — dados | Migrations 021 e 022, rollback, verificação, carga de referências (2.5), parte de estrutura do `qa-chg.php` | 2 (migrations; carga) | Aprovação; 4.5; C2, C6, C12 |
| G2 — serviço e API | `ChgStagePolicy`, `ChgService` (repositório separado), `chg.php`, `chg_referencias.php`, permissões, interruptor (com preflight e `.env.example`), auditoria, `qa-chg.php`, `qa-security`, `test-local.ps1` | 3 (política e serviço; endpoints, permissões e interruptor; ponta a ponta) | G1; 4.1 |
| G3 — telas | Rota e menu, quadro, lista, formulário, ações, copiar tabela, `qa:chg` | 2 (quadro e formulário; lista e cópia) | G2; 4.6b; C14 |
| G4 — dashboard e CSV | Dashboard por técnico, KPIs na tela, `chg_export.php` | 2 (dashboard; CSV) | G3; 4.4 |
| G5 — documentação de deploy | `API_CONTRACTS_FRONTEND.md`, LGPD 5.3, seção "módulo de CHGs" do `DEPLOY_LINUX.md` com o ensaio, travas do roteiro no QA | 1 | G4; 4.5 |
| G6 (condicional) | Tarefas, aprovações, comitê | 2 a 3, conforme a resposta | 4.2 |
| G7 (condicional) | Integração com o ServiceNow | Desenho próprio antes | 4.3 |
| Carga inicial (condicional) | Importação dos cards em uso | 1 | 4.6a |

**Total G1 a G5: cerca de 10 commits de entrega**, cada um com o seu commit de docs e parada para revisão. Cada lote de código traz mutação e a bateria completa.

---

## 11. O que entra no roteiro único (pendência 16)

Só depois da resposta ao 4.5 do requisito, e **sem escrever o roteiro consolidado antes do fim do F9** (regra da pendência 16). O que o módulo acrescenta:

1. **Antes:** carteiras e locais num arquivo fora do repositório no servidor (diretório A DEFINIR), conferido pelo responsável. Nenhum valor no git.
2. **Migrations, na ordem:** depois da 020 (F9), a 021 e a 022, cada uma com ensaio, leitura pelo commit salvo do ensaio (`git cat-file -e` / `git show` do `fN-commit`, com `pipefail`) e verificação só com contagens.
3. **Merge único** (`--ff-only`) do commit ensaiado, com `CHG_MODULE_ENABLED` ausente ou `false`:
   - o preflight mostra `CHG_MODULE_ENABLED: OK (desligado)`;
   - `curl` só com o código HTTP confere **404** em `api/portal/chg.php`.
4. **Carga das referências:** `scripts/carregar-referencias-chg.php` com o arquivo fora do repositório. Saída só com contagens; a verificação confere as contagens (carteiras ativas, locais ativos, CHGs = 0).
5. **Ligar:**
   - cópia do `.env` com `install -m 600` antes do `sed` (padrão do B3 do F7);
   - `CHG_MODULE_ENABLED=true` e recarga do PHP-FPM;
   - preflight com `OK (ligado)`;
   - conferência no navegador pelo responsável (menu, lista vazia, cadastro de teste **sem dado real**, que depois é cancelado e arquivado);
   - medição do polling (seção 9, item 7).
6. **Rollback, em degraus:**
   1. desligar o interruptor (rápido, sem perda);
   2. código, só com aprovação;
   3. migrations, só com as tabelas vazias ou com cópia `*_bkp_022` e a guarda `SIGNAL`.
7. **Avisos:**
   - à gestão: o quadro antigo deixa de ser a fonte, junto com a decisão da carga inicial (4.6a);
   - à equipe: o aviso de texto livre (seção 7).

Se a resposta ao 4.5 for "depois da Parte B", os mesmos passos viram a seção "Deploy que traz o módulo de CHGs" do `DEPLOY_LINUX.md`, num deploy próprio.

---

## 12. Riscos

| Risco | Mitigação |
|---|---|
| Volume maior que o esperado (sem paginação) | C16 (500 itens com aviso); arquivamento; paginação num lote seguinte se o volume pedir |
| Carga no PHP-FPM (`pm.max_children=5`, `docs/AUDITORIA_2026-09.md:108`) com mais um polling | 60 s só com a tela visível; três consultas fixas; medição no roteiro |
| Escopo crescer com 4.2 e 4.3 | Lotes condicionais G6 e G7, com desenho próprio; G1 a G5 não dependem deles |
| Dados hoje fora da infraestrutura (requisito, achado M3) | Decisão da carga inicial (4.6a) antes do G5 |
| Lista de carteiras desatualizada | Governança do arquivo (A DEFINIR); carga idempotente que inativa sem apagar |
| Etapa "parada" na tela por até 60 s | Aceito (igual ao modelo); a ação seguinte usa sempre a etapa recalculada no servidor |
| Módulo entrar no deploy da Parte B sem decisão | 4.5 decide; com o código no branch atual, vai no merge desligado (C1) e sem migration aplicada não toca no banco (404 antes) |
