# Desenho — Módulo de CHGs (mudanças)

**Requisito:** `docs/REQUISITOS_CHG.md`
**Data:** 2026-10-28 (G0); 2026-10-29 (G0b)
**Base analisada:** `de93913` (G0) e `d2a9528` (G0b), no branch `feat/employee-form-rhf-zod`
**Situação:** **G0 aprovado como base em 2026-10-29** (C1 a C16, exceto a C4, trocada pela C4'; seção 15). **G0b (`258c6ce`) aprovado em 2026-10-29, com D-CHG-1 a D-CHG-12 como recomendadas e o ajuste A-CHG-1 (data do comitê na aprovação sem `agendado`), já incorporado.** G1 bloqueado até as respostas do 4.5 e dos itens A CONFIRMAR. Sem código, migration ou teste. **Nenhum código antes da decisão do 4.5 do requisito (branch e deploy), que continua em aberto.** Não toca no lote de acabamento (17e), no F8 nem no F9.

Convenções:
- **Fato** = lido no código, com `arquivo:linha`.
- **A DEFINIR** = depende de decisão (detalhe e consequências na seção 4 do requisito).
- **A CONFIRMAR** = há proposta, mas falta confirmação do responsável ou de dado externo.
- **C1, C2, …** = propostas do G0 (aprovadas, exceto a C4).
- **D-CHG-n** = decisões novas do G0b, com recomendação, a aprovar (0.2).

Nenhum dado real: carteira, local, técnico, IP e link aparecem só como marcador (`[CARTEIRA_NOME]`, `[LOCAL_n]`, `[URL_CONSOLE_ENDPOINT]`…). Nada de infraestrutura nova sem decisão (servidor, firewall, conta de serviço ficam como A DEFINIR).

---

## 0. Resumo para decisão

### 0.1 Propostas C1 a C16

**Aprovadas em 2026-10-29, exceto a C4, substituída pela C4'** (linha logo abaixo da C4). Listas e formatos que dependem de dado externo continuam A CONFIRMAR: motivo do cancelamento (C6), hosts permitidos (C10), formato real do número da CHG (C12).

| # | Proposta | Motivo |
|---|---|---|
| C1 | `CHG_MODULE_ENABLED` **ausente = desligado**; valor inválido = desligado, com aviso no log | Módulo novo: falha fechada. O `STATUS_PRESENCE_ENABLED` é o contrário (ausente = ligado) porque desliga uma melhoria de algo que já existia (`services/PresenceService.php:32-45`) |
| C2 | Código de carteira fora da base é **recusado** (400), com orientação de pedir a inclusão | A base fica no servidor (RF-CHG-15); texto livre de carteira traria nome de cliente digitado sem controle. O modelo só avisava (`_referencias/controle_atualizacoes_modelo.html:1177`) |
| C3 | Fim do Service Desk **estritamente depois** do início | O modelo aceita igual, mas a barra de SLA some (`:996`, `:649`) |
| ~~C4~~ | ~~Botões por etapa, sem arrastar e soltar nos lotes G3 e G4~~ — **substituída pela C4' na revisão de 2026-10-29** | — |
| **C4'** | **Arrastar e soltar entre as seis colunas**, como no modelo. O drop chama a **mesma ação do servidor** que o botão; o servidor valida perfil, estado e `row_version` e devolve a etapa; **o frontend nunca decide a etapa**. Atualização otimista só com reversão garantida. Alternativa equivalente por botão e teclado ("Mover para…"), foco visível e `aria-live`; no toque, a alternativa fica visível. Detalhe em 6.2; biblioteca em D-CHG-1 | Pedido B3 do responsável. Resolve o M13 mostrando a etapa real devolvida ("CHG aprovada. Entra em preparação às 16:00") em vez de deixar o card numa coluna que não é a final |
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

### 0.2 Decisões novas do G0b (D-CHG-1 a D-CHG-12), para aprovação

As quatro pedidas pelo responsável vêm primeiro (biblioteca de arrastar, concluir com tarefas abertas, comitê × `chg_status`, escopo do B4). As outras oito saíram do desenho do B1 a B4 e mudam estrutura ou regra; ficam aqui para não serem decididas no código.

| # | Decisão | Recomendação | Motivo |
|---|---|---|---|
| **D-CHG-1** | Biblioteca de arrastar e soltar (B3) | **Arrastar nativo do HTML5, sem dependência**, com o botão "Mover para…" (menu acessível) em todo card como caminho equivalente para teclado, leitor de tela e toque. Alternativa, se o responsável quiser arrastar também no toque e pelo teclado: `@dnd-kit/core` 6.3.1 (MIT), com a medição do bundle e o `npm audit` **antes** da aprovação final. Comparação em 6.2.4 | O "Mover para…" é obrigatório de qualquer jeito (o B3 proíbe arrastar como único caminho) e já cobre teclado e toque, que são o que uma biblioteca acrescentaria. O nativo não traz dependência, lockfile novo nem peso no chunk. O `@dnd-kit/core` está estável, mas a última versão é de 2024-12 e a documentação oficial já orienta a migração para o `@dnd-kit/react`, que está na 0.5.1 (antes da 1.0, publicada em 2026-10-29): uma escolha frágil para um módulo de controle operacional. Limite do nativo: arrastar no toque depende do navegador (A CONFIRMAR por aparelho); por isso o "Mover para…" fica sempre visível |
| **D-CHG-2** | Concluir a CHG com tarefas abertas | **Bloquear**: `finish` exige todas as tarefas `concluida` ou `cancelada`; senão, 409 "Há N tarefas abertas. Conclua ou cancele antes de concluir a CHG." Sem tarefas, conclui. Sem "forçar" | Uma CHG concluída com tarefa aberta é dado contraditório num controle que tem efeito em produção. A saída explícita existe (cancelar a tarefa, auditado), então a regra não trava o trabalho, só obriga a registrar a decisão. A checagem roda com a CHG travada, e toda escrita de tarefa trava a CHG antes (2.6): nenhuma tarefa nasce entre a checagem e a conclusão |
| **D-CHG-3** | Relação comitê × `chg_status` | **A aprovação sempre passa por um registro de comitê.** Resultado `aprovada` grava `chg_status = 'aprovada'` na mesma transação. `reprovada` e `adiada` deixam (ou devolvem) `pendente`; `adiada` pode trazer a nova data, que cria o próximo `agendado` na mesma transação. `retirada` não mexe no status. A ação `approve` (botão, "Mover para…" ou drop em Preparação e Execução) usa o `agendado` aberto com data de hoje ou anterior; **sem esse `agendado`, exige a data do comitê informada por quem aprova** (`committee_on`, de hoje para trás), sem nunca assumir a data de hoje sozinha (**ajuste A-CHG-1**, 2026-10-29). `back_to_committee` devolve `pendente` e mantém o registro aprovado como histórico | Um só caminho para "aprovada": não existe CHG aprovada sem registro de comitê, e "quem aprovou e quando" sai do resultado e do histórico, como pediu o 4.2. O drop continua registrando a aprovação, como no B3. A-CHG-1: na digitação das CHGs existentes, a data real do comitê é anterior a hoje |
| **D-CHG-4** | Escopo do B4 no módulo | **Todo formulário e toda edição do módulo**: formulário da CHG (criar, editar, duplicar), edição de planos no card, endpoints, tarefa (criar e editar), comitê (agendar, registrar resultado, remarcar), motivo do cancelamento. Formas de sair: X, Esc, clique fora, Cancelar, menu e qualquer `navigate`, voltar e avançar do navegador, recarregar ou fechar a aba, abrir outra CHG ou outra edição, **e o botão "Sair"** do menu | É o pedido B4 com dois acréscimos: o "Sair" (logout) também descarta o que foi digitado, e o voltar do navegador troca de rota sem passar pelo menu. A peça é única (6.3); o custo de cobrir tudo é o mesmo de cobrir parte |
| **D-CHG-5** | B4 no resto do sistema (pendência 17f) | **Antes do deploy único:** Chamados críticos (formulário longo em modal, que some no clique fora) e as três edições da Parte B que já usam React Hook Form (Funcionários, Ausências, Escala). **Depois do deploy:** Horas extras e Correção de ponto, Mapa PA, Calendário, Plantões e os envios de arquivo. A peça reutilizável nasce num commit próprio, **fora do módulo**, para poder entrar no deploy da Parte B sem o módulo. Levantamento em 14 | É onde a perda é maior (Chamados críticos) ou mais barata de evitar (formulários com `isDirty` pronto). Os de `useState` pedem calcular "sujo" à mão ou migrar para o React Hook Form: mais mudança, perda menor (formulários curtos). Depende da ordem com o 17e (Chamados críticos é o item 1 do 17e) e do 4.5 |
| **D-CHG-6** | Drop que conclui ou cancela | **Abre a mesma confirmação curta do botão** (na própria coluna, sem `window.confirm`; o cancelamento com o motivo da C6) antes de chamar o servidor. Aprovar por drop, com `agendado` aberto de hoje ou anterior, **não** pede confirmação: chama `approve` direto e mostra a etapa real. **Sem esse `agendado` (ajuste A-CHG-1), abre a confirmação curta pedindo a data do comitê** (o `Dialog` da 6.3), preenchida com a data de hoje do servidor e editável, com as regras da D-CHG-10 | Concluir e cancelar são decisões que fecham o trabalho; um soltar sem querer não pode registrá-las. Aprovar tem volta (`back_to_committee`) e foi pedido como efeito direto do drop, mas a data do comitê é um dado que só quem aprova conhece |
| **D-CHG-7** | Drop que exigiria duas ações (por exemplo, de Concluídas para Aguardando Comitê, que pediria reabrir e voltar ao comitê, ou de Aguardando retorno para Preparação, que pediria número e aprovação) | **Recusar sem chamar o servidor**, com a mensagem do caminho certo ("Reabra a execução antes de voltar ao comitê."). Um movimento = uma ação. De Canceladas, soltar em qualquer coluna é uma ação só (`uncancel`), com a etapa real mostrada depois | Duas ações num drop seriam duas transações ou uma ação composta nova, com auditoria ambígua. A tabela de 6.2.1 fica pequena e testável |
| **D-CHG-8** | O que o técnico faz nas tarefas | **Conclui e reabre só as tarefas das quais é o responsável** (`assignee_id = employee_id` do ator), e só em CHG que ele pode ler. Criar, editar, cancelar e reordenar: só a gestão (`chg.manage`) | "Técnico só na própria CHG e tarefa" (padrão de qualidade). Quem planeja as tarefas é quem lança a CHG (resposta 4.1) |
| **D-CHG-9** | Tarefas de CHG cancelada | **Ficam como estão, só leitura.** Descancelar a CHG devolve as tarefas no estado em que estavam. Concluída: só leitura até reabrir | Cancelar a CHG tem volta (`uncancel`); cancelar as tarefas junto apagaria a situação real delas e exigiria desfazer em lote |
| **D-CHG-10** | Datas de comitê no passado | **Permitidas, já com o resultado** (exceto `agendado`), para a digitação das CHGs existentes (4.6a). `agendado` só com data de hoje em diante. **No máximo um `agendado` por CHG**, imposto pelo serviço e por índice único no banco | A equipe vai digitar o histórico. O índice único garante a regra mesmo com duas abas abertas |
| **D-CHG-11** | Limites | Até **30 tarefas** por CHG, título com até **150** caracteres; até **20 registros de comitê** por CHG | Limite para payload, tela e consulta, no mesmo espírito da C8 (10 endpoints) |
| **D-CHG-12** | Concorrência das tarefas | `row_version` **por tarefa**; escrita de tarefa **não** muda o `row_version` da CHG (para não dar 409 em quem edita o formulário da CHG ao mesmo tempo); reordenar envia a lista inteira de `{id, row_version}` e recebe 409 se ela não bater com o banco | Mantém o 409 útil (só quando a mesma coisa foi alterada) sem coluna nova na CHG |

### 0.3 O que bloqueia cada lote (lotes renumerados; seção 10)

| Lote | Bloqueado por |
|---|---|
| **Qualquer código** | **4.5 do requisito (branch e deploy), em aberto** |
| G1 (dados) | Aprovação do G0b; D-CHG-10 e D-CHG-11 (entram na estrutura); listas da C6 e formato da C12 (A CONFIRMAR) |
| G2 (serviço e API das CHGs) | G1; leitura do técnico (4.1, A CONFIRMAR); 4.6c |
| G3 (tarefas e comitê no serviço) | G2; D-CHG-2, D-CHG-3, D-CHG-8, D-CHG-9, D-CHG-12 |
| G4 (peças de UX reutilizáveis) | Aprovação do G0b; D-CHG-4 e D-CHG-5 (se a peça nasce para o 17f, a ordem com o 17e) |
| G5 (telas) | G3 e G4; D-CHG-1, D-CHG-6, D-CHG-7; 4.6b |
| G6 e G7 (dashboard e CSV; documentação de deploy) | G5; 4.5 |

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
| ServiceNow | `services/integrations/IntegrationRegistry.php:13` (só cartão de situação) | Nada: digitado à mão (4.3 respondido) |
| Composer | Não há `composer.json` no repositório | Sem Excel (4.4 respondido) |
| **Acrescentados no G0b** | | |
| "No máximo um aberto" por índice único sobre coluna gerada (nulo não colide) | `migrations/20260617_008_dashboard_ci_pa_map.sql:43-50` (`active_pa_slot_key`, `active_employee_slot_key`); `migrations/20260612_001_portal_foundation.sql:50` (`pending_employee_id`) | Um só `agendado` por CHG (2.4.2) |
| Roteador próprio, sem React Router: `navigate` faz `pushState`; voltar e avançar chegam por `popstate` | `frontend/src/lib/router.js:13-30`; menu em `frontend/src/components/PortalLayout.jsx:45-48`; "Sair" em `:185` | Não há `useBlocker`: o bloqueio de rota do B4 entra no próprio `router.js` (6.3) |
| Ação que só limpa o formulário no sucesso | `frontend/src/lib/actionRunner.ts:21-58` (`onSuccess` só depois da ação; no erro, só `onError` e a mensagem) | Erro do servidor mantém o que foi digitado (B4) |
| Modais atuais: `div` fixa com um `button` de fundo que fecha no clique; **nenhum** trata Esc, prende o foco ou tem `role="dialog"` | Por exemplo `frontend/src/pages/CriticalIncidentsPage.jsx:205-206`, `frontend/src/pages/AdminPage.jsx:735-737`, `frontend/src/pages/PaMapPage.jsx:211-212`; busca por `keydown`, `Escape` e `focus()` em `frontend/src` sem resultado; o único `role="dialog"` com `aria-modal` é o menu do celular (`PortalLayout.jsx:142`) | O módulo traz um diálogo acessível próprio (6.3); o resto vira a pendência 17f (14) |
| `window.confirm` ainda em uso | `frontend/src/App.jsx:143`, `frontend/src/pages/AdminPage.jsx:778`, `:783`, `frontend/src/pages/DocumentsPage.jsx:197`, `frontend/src/pages/OperationalPages.jsx:447` | Proibido no módulo (B4); os existentes ficam fora do escopo (14) |
| React Hook Form já adotado | `frontend/package.json` (`react-hook-form` ^7.80.0, `zod` ^4.4.3); `AbsencesPage.jsx:2`, `AdminPage.jsx:2`, `OperationalPages.jsx:5` | `isDirty` para o B4 |
| Nenhuma biblioteca de arrastar | `frontend/package.json` (dependências: TanStack Query e Table, React, React Hook Form, Zod) | D-CHG-1 |

---

## 2. Modelo de dados

Regras para as três migrations (021, 022 e 023), as mesmas da Parte B:
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

**G0b:** o histórico também recebe as ações de tarefa e de comitê (eventos de 5, sem o prefixo). Para elas, a linha leva o id do objeto em `fields`, no formato `task:12` ou `committee:3`, mais os **nomes** dos campos alterados; nunca o título da tarefa. "Quem aprovou no comitê" é a linha `committee_result` com `aprovada`, mais o `actor` (resposta 4.2).

### 2.4 Migration 023 — tarefas e comitê (G0b; obrigatória pela resposta 4.2)

Mesmas regras da 021 e da 022. Sem `portal_chg_approvals` (4.2) e sem colunas de sincronização (4.3). A 023 depende da 022 (FK para `portal_chg_changes`).

#### 2.4.1 `portal_chg_tasks` (B1)

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | |
| `change_id` | `INT NOT NULL` | FK `portal_chg_changes(id)` `RESTRICT` |
| `title` | `VARCHAR(150) NOT NULL` | Texto livre (LGPD, 7): aviso no campo, fora do log e da auditoria (D-CHG-11) |
| `assignee_id` | `INT NULL` | Responsável. FK `funcionarios(id)` `RESTRICT`; ao gravar, só cadastro ativo, dentro do Mapa e de equipe permitida (4.6c). Nulo = "Sem responsável" |
| `status` | `ENUM('aberta','concluida','cancelada') NOT NULL DEFAULT 'aberta'` | Situação |
| `due_at` | `DATETIME NULL` | Prazo (data e hora, como a janela). "Atrasada" = `aberta` e `due_at < server_now`, calculado no servidor |
| `position` | `SMALLINT UNSIGNED NOT NULL` | Ordem. `UNIQUE uk_chg_task_position (change_id, position)`; `CHECK (position BETWEEN 1 AND 200)` (30 no serviço; a folga é para a troca em duas fases do `task_reorder`, 3.4.2) |
| `completed_at`, `completed_by` | `DATETIME NULL`, `VARCHAR(100) NULL` | |
| `cancelled_at`, `cancelled_by` | `DATETIME NULL`, `VARCHAR(100) NULL` | |
| `row_version` | `INT UNSIGNED NOT NULL DEFAULT 1` | D-CHG-12 |
| `created_at`, `created_by`, `updated_at`, `updated_by` | | |

Restrições (o banco espelha o serviço):
- `CHECK ((status = 'concluida') = (completed_at IS NOT NULL))`;
- `CHECK ((status = 'cancelada') = (cancelled_at IS NOT NULL))`;
- em cada par data e autor, os dois juntos ou nenhum.

Índices: `uk_chg_task_position` (atende a lista por CHG e a contagem por `change_id`); `idx_chg_task_assignee (assignee_id, status)` (tarefas do técnico; a FK pede).

#### 2.4.2 `portal_chg_committees` (B2)

Cada linha é **uma ida da CHG ao comitê**.

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | |
| `change_id` | `INT NOT NULL` | FK `portal_chg_changes(id)` `RESTRICT` |
| `committee_on` | `DATE NOT NULL` | Data do comitê (dia; a etapa não depende da hora do comitê) |
| `result` | `ENUM('agendado','aprovada','reprovada','adiada','retirada') NOT NULL DEFAULT 'agendado'` | Lista fechada. `retirada` = tirada da pauta ou registro feito por engano (nada é apagado) |
| `decided_at`, `decided_by` | `DATETIME NULL`, `VARCHAR(100) NULL` | Quando e por quem o resultado foi registrado no ChronoDesk |
| `open_marker` | `TINYINT GENERATED ALWAYS AS (CASE WHEN result = 'agendado' THEN 1 ELSE NULL END) STORED` | `UNIQUE uk_chg_committee_open (change_id, open_marker)`: **no máximo um `agendado` por CHG** (D-CHG-10), no padrão da 008 (seção 1) |
| `row_version` | `INT UNSIGNED NOT NULL DEFAULT 1` | |
| `created_at`, `created_by`, `updated_at`, `updated_by` | | |

Restrições:
- `CHECK ((result = 'agendado') = (decided_at IS NULL))`;
- `decided_at` e `decided_by` juntos ou nenhum.

Índices: `uk_chg_committee_open`; `idx_chg_committee_change (change_id, committee_on)` (lista por CHG e próxima data); `idx_chg_committee_open_date (result, committee_on)` (comitês agendados por data, para o aviso de "sem resultado").

**Relação com o `chg_status` (D-CHG-3):** o banco não amarra as duas tabelas (o `CHECK` do MySQL não lê outra tabela). Quem garante é o serviço, numa só transação com a CHG travada, e o QA confere cada resultado. A verificação da 023 no roteiro conta as incoerências (CHG `aprovada` sem nenhum comitê `aprovada`; mais de um `agendado`), que precisam dar 0.

#### 2.4.3 Rollback e verificação da 023

- Rollback: `DROP` das duas tabelas só se estiverem vazias; senão, para com `SIGNAL` (mesma regra da 021).
- Verificação só com contagens: tabelas e índices existem, FKs e `CHECK`s presentes, 0 linhas logo depois da migration, e as duas incoerências acima iguais a 0 em qualquer momento.

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

#### 2.5.1 Lote futuro: "incluir carteira" pela tela (nota da revisão do G0)

Para não depender de acesso ao banco a cada carteira nova (C2), um lote futuro, **fora deste plano**, acrescenta a ação de admin "incluir carteira" na tela:
- só admin (permissão nova, por exemplo `chg.references`);
- código só com dígitos e nome, com as mesmas regras da carga; código existente é recusado (a troca de nome continua pela carga);
- auditoria `CHG_PORTFOLIO_ADDED` com o `id` e o código, **sem o nome** (comercial confidencial, 7);
- a carga por arquivo continua sendo a fonte e não apaga o que foi incluído pela tela que ainda estiver no arquivo; o que não estiver é inativado, como hoje. **A DEFINIR** no desenho desse lote: se a inclusão pela tela também precisa entrar no arquivo para não ser inativada na carga seguinte.

### 2.6 Ordem de trava (G0b)

Toda escrita que lê antes trava nesta ordem, sempre: **a CHG** (`SELECT … FOR UPDATE` em `portal_chg_changes`), **depois** a tarefa ou o comitê. Nenhuma ação trava uma tarefa ou um comitê sem ter travado a CHG antes. Com isso:
- o `finish` (D-CHG-2) lê as tarefas com a CHG travada, e nenhuma tarefa é criada ou reaberta entre a leitura e a conclusão;
- duas ações de comitê na mesma CHG ficam em fila, e o índice único do `agendado` é a segunda barreira;
- não há espera circular entre CHG e tarefa (a causa clássica de deadlock).

O QA confere a ordem pelo SQL registrado no PDO simulado.

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
    "created_at": "…", "updated_at": "…",
    "tasks_summary": { "total": 5, "open": 2, "done": 2, "cancelled": 1, "overdue": 1 },
    "next_committee_on": "2026-11-04",
    "committee_overdue": false
  }]
}
```

Campos do G0b:
- `tasks_summary`: contagens por situação e as atrasadas (`aberta` com `due_at < server_now`);
- `next_committee_on`: data do `agendado` aberto (no máximo um, 2.4.2), ou `null`;
- `committee_overdue`: há `agendado` com data **antes** do dia de `server_now` e sem resultado. A coluna "Aguardando Comitê" mostra "Comitê em 04/11" ou o aviso "Comitê de 28/10 sem resultado registrado".

- `stage` ∈ `triagem`, `comite`, `prep`, `exec`, `done`, `cancel` (seção 4).
- Ordem: `COALESCE(window_start, suggested_at)`, depois `window_end` (nulo por último), depois `created_at` e `id`, como o `sortByWindow` (`:760-766`).
- Até 500 itens; os KPIs e o `filtered` cobrem o filtro inteiro (C16).
- Consultas fixas, sem N+1: (1) CHGs do filtro com carteira, local e técnico por `JOIN`; (2) endpoints das CHGs devolvidas, por `IN`; (3) total do escopo; **(4) resumo das tarefas** das CHGs devolvidas (`GROUP BY change_id`, por `IN`); **(5) próximo comitê** das CHGs devolvidas (`result = 'agendado'`, por `IN`). **Cinco consultas**, qualquer que seja o tamanho da lista; o QA confere o número pelo SQL registrado.

### 3.3 `GET portal/chg.php?id=N` — detalhe

O item da lista, mais:
- `history[]` (`event`, `stage_before`, `stage_after`, `fields`, `created_at` e, só para quem tem `chg.manage`, `actor`);
- **`tasks[]`** (G0b): `id`, `row_version`, `title`, `assignee` (`id`, `name`, `archived`), `status`, `due_at`, `overdue`, `position`, `completed_at`, `cancelled_at`, na ordem de `position`;
- **`committees[]`** (G0b): `id`, `row_version`, `committee_on`, `result`, `decided_at`, `overdue`, na ordem de `committee_on` e `id`; `decided_by` só para `chg.manage`.

Fora do escopo do técnico: **404**, para não revelar a existência. Consultas fixas: CHG, endpoints, histórico, tarefas e comitês (cinco).

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
| `approve` | `chg.approve` | Com número, `pendente`, não concluída, cancelada ou arquivada | **G0b (D-CHG-3, ajuste A-CHG-1):** registra o resultado `aprovada` no comitê e grava `aprovada` na CHG, na mesma transação, pela regra da data em 3.4.1. A etapa segue o relógio (comitê com "aguardando janela", preparação ou execução). É a ação do botão "Registrar aprovação", do "Mover para…" **e** do drop em Preparação e Execução |
| `back_to_committee` | `chg.approve` | `aprovada`, não concluída | `pendente`. O registro de comitê aprovado fica como histórico; a próxima ida ao comitê é um registro novo |
| `assign_technician` | `chg.execute` (gestão) | Não concluída, cancelada ou arquivada | `technician_id` ativo e de equipe permitida, ou `null`. Técnico se atribuir a si mesmo: A DEFINIR |
| `set_plans` | `chg.execute` | Etapa `comite`, `prep` ou `exec` | Os três planos (como na edição do card, requisito 1.5) |
| `set_endpoints` | `chg.execute` | Etapa `prep` ou `exec` | Troca a lista (até 10) |
| `finish` | `chg.execute` | Etapa **`exec`**, com técnico e, **se a D-CHG-2 for aprovada, sem tarefa `aberta`** (lida com a CHG travada, 2.6) | `completed_at`/`by` |
| `reopen` | `chg.execute` (gestão) | Concluída, não arquivada | Limpa a conclusão |
| `cancel` | `chg.manage` | Não concluída, cancelada ou arquivada (C5) | `cancelled_at`/`by` e o motivo (C6) |
| `uncancel` | `chg.manage` | Cancelada, não arquivada | Limpa o cancelamento |
| `archive` | `chg.manage` | Concluída ou cancelada | `archived_at`/`by` |
| `restore` | `chg.restore` | Arquivada | Limpa o arquivamento |

**Checagem por objeto do técnico** (perfil `tecnico`): `set_plans`, `set_endpoints` e `finish` só com `technician_id = employee_id` do ator. Fora disso, 403 e `CHG_WRITE_DENIED`. A gestão age em qualquer CHG do escopo.

Mensagens de 409 com o motivo e o que fazer. Exemplos:
- "Só atualizações em execução podem ser concluídas.";
- "Informe o técnico responsável antes de concluir.";
- "Há 2 tarefas abertas. Conclua ou cancele antes de concluir a CHG." (D-CHG-2);
- "Esta CHG foi alterada por outra pessoa. Recarregue e tente de novo."

**Resposta das ações que mudam etapa (G0b, para o B3):** além do item, a resposta traz `stage_before`, `stage_after` e `stage_message`, um texto pronto montado no servidor a partir da etapa e do `server_now`, por exemplo:
- "CHG aprovada. Entra em preparação às 16:00." (aprovada antes da janela);
- "CHG aprovada. Em preparação." (dentro das 4 h);
- "CHG aprovada. Em execução desde 20:00." (depois do início);
- "Execução concluída."

A tela mostra esse texto no aviso e no `aria-live`. O frontend não monta a frase a partir da etapa (seria decidir a etapa no cliente).

#### 3.4.1 Ações de comitê (B2)

Todas travam a CHG e depois o comitê (2.6), conferem o `row_version` **do comitê** (quando o comitê existe) e gravam histórico e auditoria.

| Ação | Permissão | Estado exigido | Efeito |
|---|---|---|---|
| `committee_add` | `chg.manage` (resultado `agendado`, `adiada` ou `retirada`); `chg.approve` (`aprovada` ou `reprovada`) | CHG com número, `pendente`, não concluída, cancelada ou arquivada; menos de 20 registros (D-CHG-11). `agendado`: data de hoje em diante e nenhum outro `agendado` (409; o banco também recusa). Outro resultado: data de hoje para trás (D-CHG-10) | Cria o registro. Com resultado, aplica o efeito da tabela abaixo na mesma transação. **201** |
| `committee_reschedule` | `chg.manage` | Registro `agendado` | Troca a data (de hoje em diante) |
| `committee_result` | Como no `committee_add`, pelo resultado | Registro `agendado`; CHG `pendente` | Grava o resultado, `decided_at`/`by` e o efeito abaixo. `adiada` aceita `next_on` opcional, que cria o próximo `agendado` na mesma transação |
| `committee_withdraw` | `chg.manage` | Registro `agendado` | Resultado `retirada` (tirada da pauta ou registro por engano) |

Efeito do resultado na CHG (D-CHG-3):

| Resultado | `chg_status` | Etapa |
|---|---|---|
| `aprovada` | `aprovada` (mesma transação; a falha de qualquer escrita desfaz as duas) | Pelo relógio (4) |
| `reprovada` | `pendente` | `comite` |
| `adiada` | `pendente` | `comite`, com a nova data se houver `next_on` |
| `retirada` | Sem mudança | Sem mudança |

**Data do comitê no `approve` (ajuste A-CHG-1, 2026-10-29).** O servidor nunca assume a data de hoje sozinho:

| Situação da CHG | `committee_on` no corpo | Efeito |
|---|---|---|
| `agendado` aberto com data **de hoje ou anterior** | Ausente | Aprova direto: o `agendado` recebe o resultado `aprovada`, com a data dele |
| `agendado` aberto com data de hoje ou anterior | Presente | 400 "A CHG já tem comitê agendado. Registre o resultado nele." (evita duas fontes para a data) |
| **Sem `agendado` aberto** | **Obrigatório** | Cria o registro `aprovada` com a data informada. Ausente: **400 "Informe a data do comitê."**. Depois do dia de `server_now`: 400 "Aprovação não pode ter data futura." (D-CHG-10). Formato inválido: 400 |
| `agendado` aberto com data **futura** (o comitê aconteceu antes do previsto) | Obrigatório, de hoje para trás | O `agendado` recebe a data informada e o resultado `aprovada`, na mesma transação; o histórico e a auditoria registram a troca de data (`CHG_COMMITTEE_RESCHEDULED` e `CHG_COMMITTEE_RESULT`). Ausente: 400, como acima |

A tela decide só **se pergunta** (6.2.1): com `agendado` de hoje ou anterior, chama `approve` direto; nos outros casos, abre a confirmação curta com a data. A tela lê a situação do comitê no `next_committee_on` que o servidor mandou, e o servidor confere tudo de novo: se a situação mudou entre a leitura e o envio (outra pessoa agendou ou registrou), a resposta é 400 ou 409 com a mensagem, e o card volta (6.2.2).

Outras regras:
- `set_chg` que apaga o número (volta para "Aguardando retorno") é recusado com 409 enquanto houver `agendado` ("Retire a CHG da pauta do comitê antes de apagar o número.").
- Data de comitê fora do formato `AAAA-MM-DD` ou inválida: 400.

#### 3.4.2 Ações de tarefa (B1)

Todas travam a CHG e depois a tarefa (2.6). A CHG precisa estar **não concluída, não cancelada e não arquivada** (D-CHG-9); senão, 409 "Reabra a CHG para mudar as tarefas." (ou "Descancele…"). Conferem o `row_version` **da tarefa** (D-CHG-12).

| Ação | Permissão | Estado da tarefa | Efeito |
|---|---|---|---|
| `task_create` | `chg.manage` | — | Título (1 a 150), responsável (opcional; ativo, dentro do Mapa, equipe permitida), prazo (opcional). Entra no fim da lista. Até 30 por CHG (D-CHG-11). **201** |
| `task_update` | `chg.manage` | `aberta` | Título, responsável e prazo |
| `task_complete` | `chg.execute` (gestão em qualquer tarefa do escopo; técnico só se `assignee_id = employee_id`, D-CHG-8) | `aberta` | `concluida`, `completed_at`/`by` |
| `task_reopen` | Igual ao `task_complete` | `concluida` ou `cancelada` (cancelada só pela gestão) | `aberta`; limpa a conclusão ou o cancelamento |
| `task_cancel` | `chg.manage` | `aberta` | `cancelada`, `cancelled_at`/`by` |
| `task_reorder` | `chg.manage` | Qualquer | Recebe a lista **inteira** de `{id, row_version}` na ordem nova. Lista diferente da do banco (tarefa a mais, a menos ou versão antiga): 409. Grava em duas fases na mesma transação (soma 100 a todas as posições e depois grava 1..N), para não violar o `UNIQUE (change_id, position)` no meio da troca; cada tarefa ganha `row_version + 1` |

Técnico fora da própria tarefa: 403 e `CHG_WRITE_DENIED`. Nenhuma ação apaga tarefa.

### 3.5 `GET portal/chg_referencias.php`

Permissão `chg.manage`. Devolve:
- `portfolios` (`id`, `code`, `name`, só ativas);
- `sites` (ativos);
- `technicians` (`id`, `name`, `team`; cadastro ativo, dentro do Mapa, equipes permitidas pelo 4.6c), usado também no seletor de responsável da tarefa;
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

### 3.8 Matriz de perfis (4.1 respondido em 2026-10-29; leitura do técnico A CONFIRMAR)

Resposta do 4.1: gestão, Liderança e admin lançam as CHGs e as tarefas, inclusive as existentes; solicitante é texto, sem login. **Leitura do técnico: a proposta é ver todas as CHGs (leitura igual à da gestão) e escrever só na própria; A CONFIRMAR.** O `somente_leitura` continua com leitura, também A CONFIRMAR.

| Ação | admin | lideranca | gestor | tecnico | somente_leitura |
|---|---|---|---|---|---|
| Ver o módulo, a lista e o detalhe (`chg.read`) | Sim | Sim | Sim | Sim, todas (A CONFIRMAR) | Sim (A CONFIRMAR) |
| Cadastrar, editar, duplicar, CHG, cancelar, descancelar, arquivar (`chg.manage`) | Sim | Sim | Sim | Não | Não |
| Agendar, remarcar e retirar do comitê; resultados `adiada` e `retirada` (`chg.manage`) | Sim | Sim | Sim | Não | Não |
| Registrar aprovação ou reprovação no comitê e voltar ao comitê (`chg.approve`) | Sim | Sim | Sim | Não | Não |
| Criar, editar, cancelar e reordenar tarefas (`chg.manage`) | Sim | Sim | Sim | Não | Não |
| Concluir e reabrir tarefa (`chg.execute`) | Sim | Sim | Sim | **Só aquela da qual é o responsável** (D-CHG-8) | Não |
| Atribuir técnico e reabrir (`chg.execute`, gestão) | Sim | Sim | Sim | Não | Não |
| Planos, endpoints e concluir (`chg.execute`) | Sim | Sim | Sim | **Só a atribuída a ele** | Não |
| Exportar CSV (`chg.export`) | Sim | Sim | Sim | Não | Não |
| Restaurar arquivada (`chg.restore`) | Sim | Não | Não | Não | Não |

Em código:
- `chg.read`, `chg.manage`, `chg.approve`, `chg.execute` e `chg.export` entram no `$gestor` (e com isso na Liderança e no admin);
- `chg.restore` só no admin;
- `tecnico` recebe `chg.read` e `chg.execute`. A diferença entre "gestão" e "técnico" no `chg.execute` é a checagem por objeto, decidida pelo perfil (`portal_role_is_manager`).

Com o 4.1 respondido (solicitante sem login), **nenhum perfil novo e nenhuma mudança na autenticação**. As ações de tarefa e de comitê usam as mesmas cinco permissões; nenhuma `chg.*` nova no G0b (a `chg.references` do 2.5.1 é do lote futuro).

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
| **G0b** | | | |
| `CHG_TASK_CREATED` | INFO | `task_create` | `id` da CHG, `task_id`, posição, com ou sem responsável e prazo |
| `CHG_TASK_UPDATED` | INFO | `task_update` | `id`, `task_id`, nomes dos campos alterados; `assignee_id` antes e depois |
| `CHG_TASK_COMPLETED` / `CHG_TASK_REOPENED` / `CHG_TASK_CANCELLED` | INFO | `task_complete` / `task_reopen` / `task_cancel` | `id`, `task_id`, situação antes e depois |
| `CHG_TASK_REORDERED` | INFO | `task_reorder` | `id`, quantidade de tarefas |
| `CHG_COMMITTEE_ADDED` | INFO | `committee_add` | `id`, `committee_id`, resultado (código), data no passado ou não |
| `CHG_COMMITTEE_RESCHEDULED` | INFO | `committee_reschedule` | `id`, `committee_id` |
| `CHG_COMMITTEE_RESULT` | INFO | `committee_result`, `committee_withdraw`, `approve` | `id`, `committee_id`, resultado (código), `chg_status` antes e depois |

Nos eventos de tarefa, **nunca o título**; nos de comitê, só códigos e ids. O `approve` grava `CHG_APPROVED` e `CHG_COMMITTEE_RESULT`. O `CHG_WRITE_DENIED` e o `CHG_CONFLICT` valem também para as ações de tarefa e de comitê (com `task_id` ou `committee_id`).

Erros inesperados vão para o `error_log` só com a classe da exceção (padrão do F7).

---

## 6. Telas (esboço)

- **`frontend/src/pages/ChgPage.jsx`** (sob demanda), com abas Quadro, Lista e Dashboard; KPIs no topo; barra de filtros. Arquivos de apoio:
  - `frontend/src/lib/chg.ts`: tipos, rótulos, cor e ícone por etapa (classes fora do `@layer components`, pela lição do F5 e do F6), cópia da tabela e agregação do dashboard;
  - esquema do formulário em `frontend/src/lib/formSchemas.ts`.
- **Quadro (C14):** colunas com título, contador, dica e texto de vazio (requisito 1.9); card com os mesmos blocos do modelo (requisito 1.5), botões por etapa, o botão "Mover para…" e arrastar e soltar (C4', 6.2); confirmação na própria coluna, sem `window.confirm`.
- **Lista:** as 12 colunas; cartões abaixo de 1280 px.
- **Dashboard:** visão geral por etapa, barra por técnico, "Sem técnico atribuído" (as 8 primeiras; abrem a edição) e tabela de detalhe. Calculado da lista recebida, com `stage` do servidor.
- **Formulário:** grupos "Solicitação", "Janela", "CHG e execução" e "Planos", como no modelo (`:329-357`).
  - Seletor de carteira pelo código, com o nome; seletor de local; seletor de técnico (`technicians`).
  - Preenchimento automático de início e fim igual ao do modelo.
  - Aviso nos campos de texto livre (seção 7).
- **Copiar tabela:** `chgCopyTable(item)` devolve `{ html, text }`. É função pura com escape de `& < > " '` e links só `https`. A cópia vai por `navigator.clipboard.write` com `text/html` e `text/plain`; a reserva é `writeText(text)`; a última reserva é uma caixa React com a tabela para selecionar. Sem `innerHTML`, sem `execCommand`.
- **Links:** `<a href target="_blank" rel="noopener noreferrer">`, só com o valor que o servidor aceitou.
- **Exportar:** `<a href={apiUrl('portal/chg_export.php?…')}>`, como os Chamados críticos.

### 6.1 Tarefas e comitê na tela (B1, B2)

- **No card:** "3 de 5 tarefas" e "1 atrasada" (texto, nunca só cor), do `tasks_summary`. Na coluna "Aguardando Comitê": "Comitê em 04/11", ou o aviso "Comitê de 28/10 sem resultado registrado" (`committee_overdue`), ou "Sem data de comitê".
- **No detalhe da CHG** (painel aberto pelo card), abas Dados, Tarefas, Comitê e Histórico.
- **Tarefas:**
  - lista na ordem, com responsável (ou "Sem responsável"), prazo e situação;
  - botões "Concluir", "Reabrir" e "Cancelar" por tarefa, conforme a matriz 3.8; o cancelamento confirma na própria linha;
  - reordenar por botões "Subir" e "Descer", acessíveis pelo teclado (sem arrastar dentro da lista: um só tipo de arrasto na tela, o das colunas);
  - formulário curto de criar e editar, com o B4 (6.3) e o aviso de texto livre no título.
- **Comitê:**
  - lista com data, resultado (selo com texto) e quando foi registrado;
  - ações "Agendar comitê", "Remarcar", "Registrar resultado" (lista fechada; em `adiada`, a nova data opcional) e "Retirar da pauta", com o B4 e a confirmação na linha para retirar;
  - na digitação de CHGs antigas, "Registrar comitê realizado" aceita data passada já com o resultado (D-CHG-10).
- Toda ação mostra carregamento no botão (desabilitado durante o envio), o sucesso e o erro do servidor; o 409 traz "Recarregar" no aviso.

### 6.2 Arrastar e soltar entre as fases (B3, C4')

#### 6.2.1 Uma ação por movimento

`frontend/src/lib/chg.ts` terá **uma tabela fixa**, `CHG_MOVES`, de (etapa de origem, coluna de destino) para a ação do servidor, com os mesmos nomes de ação dos botões. O drop, o "Mover para…" e o botão chamam a mesma função, `moveCard(card, destino)`, que consulta a tabela. A etapa de origem é a `stage` que o servidor mandou. A tabela só escolhe **qual ação pedir**, como um botão escolhe; a etapa final é sempre a `stage_after` da resposta.

| Destino ↓ / origem → | Aguardando retorno | Aguardando Comitê (`pendente`) | Aguardando Comitê (aprovada, aguardando janela) | Em preparação | Em execução | Concluídas | Canceladas |
|---|---|---|---|---|---|---|---|
| **Aguardando retorno** | — | Sem ação: "Para voltar a Aguardando retorno, apague o número da CHG na edição." | Igual | Igual | Igual | Igual | `uncancel` |
| **Aguardando Comitê** | Sem ação: "Informe o número da CHG na edição para ir ao comitê." | — | — | `back_to_committee` | `back_to_committee` | Sem ação (D-CHG-7): "Reabra a execução antes de voltar ao comitê." | `uncancel` |
| **Em preparação** | Sem ação: "Informe o número da CHG e registre a aprovação." | `approve` (direto com `agendado` de hoje ou anterior; senão, confirmação com a data do comitê, A-CHG-1) | Sem ação: "A CHG já está aprovada." + o `prep_at` do servidor | — | Sem ação: "A etapa segue o horário da janela." | `reopen` | `uncancel` |
| **Em execução** | Igual à linha de cima | `approve` (igual à linha de cima) | Igual à linha de cima | Sem ação: "A etapa segue o horário da janela." | — | `reopen` | `uncancel` |
| **Concluídas** | Sem ação: "Só atualizações em execução podem ser concluídas." | Igual | Igual | Igual | `finish` (com confirmação, D-CHG-6) | — | Sem ação: "Descancele a CHG antes." |
| **Canceladas** | `cancel` (com confirmação e motivo, D-CHG-6) | `cancel` | `cancel` | `cancel` | `cancel` | Sem ação (C5): "Reabra a execução antes de cancelar." | — |

- **De Canceladas**, soltar em qualquer coluna chama `uncancel`, uma só ação, e a CHG vai para a etapa que os dados dela dão; a tela mostra a etapa real, como no `approve`.
- **Aprovação sem `agendado` de hoje ou anterior (ajuste A-CHG-1):** o drop, o "Mover para…" e o botão abrem a mesma confirmação curta (o `Dialog` da 6.3): título "Registrar aprovação", campo "Data do comitê" preenchido com a data de hoje do **servidor** (dia de `server_now`) e editável, com `max` no dia de `server_now`, e os botões "Registrar aprovação" e "Cancelar". "Cancelar", Esc ou clique fora não chamam o servidor e o card não sai do lugar (o estado otimista só começa depois de confirmar). Data futura ou vazia: o botão fica desabilitado com a mensagem no campo, e o servidor recusa de novo (3.4.1). O foco inicial vai para o campo da data; ao fechar, volta ao "Mover para…" do card.
- **"Sem ação"** não chama o servidor: o card não sai do lugar e a mensagem aparece no aviso e no `aria-live`. Não é decisão de etapa: nenhuma etapa muda.
- **Com ação**, o servidor confere tudo de novo (perfil, objeto, estado, `row_version`, técnico, tarefas abertas pela D-CHG-2, número da CHG) e pode recusar com 403 ou 409; a mensagem do servidor é a que aparece.
- O QA gera a tabela inteira (7 origens × 6 destinos) e confere ação e mensagem em cada célula (9).

#### 6.2.2 Atualização otimista com reversão garantida

- Ao soltar (ou escolher no "Mover para…"), o card aparece na coluna de destino com "Salvando…" e `aria-busy="true"`, e não pode ser arrastado de novo até a resposta.
- O estado otimista é uma **camada à parte** (`{ id: destino }`), que **não altera** a lista recebida do servidor. Reverter é só retirar a entrada da camada, em qualquer caminho de erro (`finally`).
- **Sucesso:** a camada sai e o item da resposta substitui o da lista. Se `stage_after` for outra coluna (por exemplo, `approve` antes da janela fica em "Aguardando Comitê"), o card vai para ela e o `stage_message` explica.
- **403, 409, erro de rede ou tempo esgotado:** o card volta para a origem e a mensagem aparece. No 409, a lista é recarregada.
- **Atualização de 60 s durante o envio:** a camada otimista vale até a resposta; depois, a próxima atualização manda.
- O QA simula cada caminho e confere que o card termina na coluna certa (9).

#### 6.2.3 Acessibilidade e toque (WCAG 2.1 AA)

- **"Mover para…"** em todo card que o perfil pode mover: botão com `aria-haspopup="menu"` e `aria-expanded`, menu com as outras cinco colunas. Destinos "sem ação" aparecem com `aria-disabled="true"` e o motivo da tabela 6.2.1 como descrição, para quem usa teclado receber a mesma explicação de quem arrasta.
- **Teclado:** Tab chega ao botão; Enter ou Espaço abre; setas percorrem; Enter escolhe; Esc fecha e devolve o foco ao botão. A escolha chama `moveCard`, com as mesmas confirmações (D-CHG-6) e a mesma chamada. Arrastar pelo teclado não é oferecido: o menu é o caminho equivalente (critério 2.1.1 da WCAG 2.1; também atende o 2.5.7 da WCAG 2.2, "movimentos de arrastar").
- **Foco visível** em card, botão e itens do menu (`focus-visible`), com contraste. Depois de mover, o foco vai para o "Mover para…" do card na coluna nova; se falhar, fica no de origem.
- **Anúncio:** região `aria-live="polite"` com o resultado ("CHG movida. CHG aprovada, entra em preparação às 16:00.") e a recusa ("Não foi possível mover: …").
- **Durante o arrasto com o mouse:** a coluna de destino mostra, em texto, o que o drop fará ("Soltar para registrar a aprovação") ou "Não é possível soltar aqui", não só a cor.
- **Toque (celular e tablet):** o "Mover para…" fica **sempre visível** (nunca só no passar do mouse), com área de toque de pelo menos 44 × 44 px. Se o navegador do aparelho não arrastar com o dedo, nada se perde.
- O card só é `draggable` para quem pode fazer alguma ação nele (somente leitura não arrasta), e o arrasto não começa num campo ou botão dentro do card (como no modelo, `:1123-1124`). O servidor confere de novo em qualquer caso.

#### 6.2.4 Biblioteca (D-CHG-1)

Fontes: registro do npm (`npm view`, consultado em 2026-10-29) e documentação oficial do dnd kit (dndkit.com, consultada pelo Context7). Nada instalado.

| Critério | Arrastar nativo do HTML5 | `@dnd-kit/core` 6.3.1 | `@dnd-kit/react` 0.5.1 |
|---|---|---|---|
| Dependência nova | Nenhuma | `@dnd-kit/core`, `@dnd-kit/utilities` 3.2.2, `@dnd-kit/accessibility` 3.1.1 e `tslib` | `@dnd-kit/react`, `dom`, `state`, `abstract`, `geometry`, `collision` e `tslib` |
| Licença | — | MIT | MIT |
| Situação | Padrão da plataforma | Estável; última versão de 2024-12-05; a documentação oficial traz o guia de migração para o `@dnd-kit/react` | Antes da 1.0; versão publicada em 2026-10-29 |
| Mouse | Sim | Sim | Sim |
| Toque | Depende do navegador (A CONFIRMAR por aparelho) | Sim (sensores de toque e ponteiro) | Sim (`PointerSensor`) |
| Arrastar pelo teclado | Não | Sim (`KeyboardSensor`) | Sim (`KeyboardSensor`) |
| Anúncio durante o arrasto | Não (o módulo anuncia o resultado) | Sim | Sim (plugin `Accessibility`) |
| Peso no chunk da rota | 0 | **Não medido** (o tamanho do pacote no registro não é o peso no bundle) | Não medido |
| "Mover para…" | Obrigatório | Obrigatório | Obrigatório |

**Recomendação: nativo, sem dependência** (motivo na D-CHG-1). Se o responsável preferir o `@dnd-kit/core`, antes da aprovação final: versão fixa no `package.json` (sem `^`), `package-lock.json` versionado, `npm audit` sem achado, licença conferida e o peso no chunk lazy da rota medido pelo `qa:bundle`, com e sem a biblioteca. O `@dnd-kit/react` não é recomendado agora (antes da 1.0 e recém-publicado).

### 6.3 Proteção contra perda de alterações (B4, D-CHG-4)

Peça reutilizável, em quatro partes:

| Arquivo | O que faz |
|---|---|
| `frontend/src/components/ui/Dialog.jsx` | Diálogo base: `role="dialog"` ou `"alertdialog"`, `aria-modal="true"`, `aria-labelledby` e `aria-describedby`; foco inicial escolhido; **foco preso** (Tab e Shift+Tab circulam dentro); Esc; ao fechar, o foco volta ao elemento que abriu. Serve também às confirmações do D-CHG-6 |
| `frontend/src/components/ui/ConfirmDiscardDialog.jsx` | `alertdialog` com o título "Descartar alterações?" e o texto **"Há alterações não salvas. Se sair agora, elas serão perdidas."**; botões **"Continuar editando"** (padrão, com o foco) e **"Descartar alterações"**. Esc e clique fora = "Continuar editando" |
| `frontend/src/hooks/useUnsavedChangesGuard.ts` | Recebe `isDirty`. Devolve `requestLeave(acao)`: limpo, executa a ação; sujo, abre o diálogo e executa só no "Descartar". Enquanto sujo, registra o bloqueio de rota e o `beforeunload`; limpo ou desmontado, retira os dois. Vários formulários sujos ao mesmo tempo: um só diálogo |
| `frontend/src/lib/router.js` | Ganha um registro de bloqueios. O `navigate` (menu, abas, links internos) consulta os bloqueios antes do `pushState`. No `popstate` (voltar e avançar), com bloqueio, o endereço anterior é recolocado e o diálogo aparece; no "Descartar", a navegação pedida segue. O "Sair" do menu passa pelo mesmo bloqueio |

Regras:
- **"Sujo"** = `formState.isDirty` do React Hook Form, com `defaultValues` sempre definidos. Comportamento com `useFieldArray` (endpoints): A CONFIRMAR na documentação da versão em uso (7.80) no G4.
- **Salvar com sucesso** chama `reset(valoresDevolvidosPeloServidor)`, que limpa o estado, e só então fecha. **Erro do servidor** não chama `reset`: os dados ficam, como no `runAction` de hoje (seção 1).
- **Formas de sair cobertas:** X, Esc, clique fora e Cancelar do modal; menu e qualquer `navigate`; voltar e avançar; recarregar ou fechar a aba; abrir outra CHG ou outra edição (o painel passa a troca por `requestLeave`); "Sair".
- **`beforeunload`:** o navegador mostra o aviso **dele**, com texto genérico; o texto próprio não aparece nesse caso (comportamento dos navegadores atuais; A CONFIRMAR na documentação no G4). O diálogo próprio cobre todos os outros caminhos.
- **Não cobre:** sessão expirada e falha de rede ao salvar (os dados ficam na tela, mas uma nova autenticação recarrega a página). Fica declarado como limitação.
- **Sem `window.confirm`** no módulo; o QA procura no código do módulo e da peça.
- **QA da peça:** `qa:unsaved-changes`, novo, renderizando um formulário de teste: sujo e limpo; cada forma de sair; "Continuar editando" com o foco inicial; foco preso e devolvido; Esc no diálogo; sucesso limpando; erro mantendo; `navigate` e `popstate` bloqueados e liberados; `beforeunload` registrado só enquanto sujo. Com ele, a bateria passa a **18 + 2** `qa:*` (`qa:unsaved-changes` e `qa:chg`): **decidido na revisão do G0b**, separados, porque a peça também serve fora do módulo.

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
| Título da tarefa (texto livre, G0b) | Mesmo risco da descrição | Mesmo aviso, até 150 caracteres; nunca em log, auditoria ou histórico |
| Responsável da tarefa (G0b) | Pessoal, por referência | `assignee_id`; nome do cadastro; arquivado aparece como "(arquivado)" |
| Registro de comitê (G0b) | Interno (datas e resultado) | `decided_by` só para `chg.manage`, como o `actor` |
| CSV exportado | Confidencial (carteiras e nomes) | Permissão própria, auditoria com contagem, `no-store`, neutralização de fórmula |

Outras regras:
- **Retenção:** nenhuma exclusão pela aplicação. A anonimização segue a política de retenção LGPD, que está pendente para depois do deploy (`docs/DESENHO_PA_ESCALA_AUSENCIA.md:2696`).
- **Inventário:** linha nova na seção 5.3 da documentação técnica (`docs/DOCUMENTACAO_TECNICA.md:122`), no G5.
- **Carga inicial (requisito 4.6a, respondido em 2026-10-29):** sem importação; a equipe digita as CHGs existentes, e o quadro antigo vira só referência até o fim da digitação. O modelo é um artefato publicado no claude.ai, e o modo compartilhado grava no banco desse ambiente (`window.claude.use("db")`, `:508-521`): **os dados do quadro atual podem estar fora da infraestrutura da empresa.** A despublicação do artefato depois da migração é **decisão do responsável** (requisito, M3). O módulo não lê nem copia nada de lá.

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
   - **Tarefas (G0b):** matriz perfis × ações de tarefa × situação da tarefa × estado da CHG; técnico só na tarefa da qual é responsável (403 e `CHG_WRITE_DENIED`); CHG concluída, cancelada ou arquivada (409); limite de 30; título vazio ou com mais de 150; responsável inativo, arquivado ou de equipe não permitida; `task_reorder` com lista a mais, a menos e versão antiga (409) e a troca em duas fases no SQL registrado; `row_version` da CHG intacto depois de ação de tarefa (D-CHG-12).
   - **Concluir com tarefas (D-CHG-2):** com tarefa aberta, 409 com a contagem; com todas concluídas ou canceladas, ou sem tarefas, conclui; a leitura das tarefas acontece **depois** da trava da CHG (ordem no SQL registrado).
   - **Comitê (G0b):** cada resultado com o efeito no `chg_status` (tabela 3.4.1); `approve` nas quatro situações da tabela de data do comitê (A-CHG-1), inclusive sem `agendado` e sem `committee_on` (400, nada gravado), com `committee_on` futuro (400) e com `agendado` futuro (data trocada e auditada); `adiada` com `next_on` criando o próximo `agendado` na mesma transação; segundo `agendado` recusado; data passada com resultado aceita e `agendado` no passado recusado (D-CHG-10); `set_chg` sem número com `agendado` (409); falha simulada na segunda escrita desfazendo a primeira (rollback da transação); `next_committee_on` e `committee_overdue` pelo `server_now`.
   - **Ordem de trava (2.6):** em toda ação de tarefa e de comitê, o `FOR UPDATE` da CHG vem antes de qualquer leitura de tarefa ou comitê.
   - **Número fixo de consultas:** lista com 0, 1 e 50 CHGs faz as mesmas cinco consultas (3.2); detalhe, cinco (3.3).
   - **Auditoria do G0b:** um marcador injetado no título da tarefa não aparece em nenhum detalhe de auditoria, linha de histórico nem `error_log`.
   - **Mensagem de etapa:** `stage_message` para aprovada antes da janela, dentro das 4 h e depois do início, com instantes fixos.
2. **`scripts/qa-security.php`:** casos de ponta a ponta dos três endpoints por perfil, com o módulo ligado e desligado (o padrão dos processos filhos que já existe), incluindo as ações de tarefa e de comitê com CSRF ausente (403).
3. **`frontend/scripts/qa-chg.mjs`** (`qa:chg`, o 19º `qa:*`). Renderiza a `ChgPage` de verdade (`registerHooks`, TypeScript, `react-dom/server`, como o `qa:calendar-presence` do 17b) e confere:
   - colunas pela `stage` do servidor;
   - nenhum `new Date()` ou `Date.now()` no cálculo de etapa (checagem do código de `lib/chg.ts`);
   - KPIs vindos do servidor; colunas da lista; agregação do dashboard;
   - escape na cópia (`<img onerror>` na descrição sai escapado no HTML; tabulação e quebra de linha no texto);
   - link só `https` como `<a>` com `rel`;
   - item de menu só com `chg.read`;
   - as classes de largura (C14);
   - cores da etapa presentes no CSS compilado (a varredura genérica do `qa:calendar-presence` cobre o padrão `prefixo-${x}`);
   - **B3:** a tabela `CHG_MOVES` inteira (7 origens × 6 destinos), com a ação ou a mensagem de cada célula; drop válido termina na `stage_after` da resposta (inclusive `approve` antes da janela, que fica em "Aguardando Comitê" com o `stage_message`); drop "sem ação" não chama o servidor; 409, 403, erro de rede e tempo esgotado devolvem o card à origem e mostram a mensagem; 409 recarrega a lista; atualização de 60 s durante o envio não desfaz a camada otimista; confirmação antes de `finish` e `cancel`; `approve` direto com `agendado` de hoje ou anterior e, sem ele, a confirmação com a data do comitê preenchida pelo dia de `server_now`, editável, sem chamar o servidor no "Cancelar" e com data futura bloqueada (D-CHG-6, A-CHG-1); "Mover para…" pelo teclado faz a mesma chamada do drop; destinos sem ação com `aria-disabled` e o motivo; anúncio no `aria-live`; foco no card movido; somente leitura sem `draggable`; "Mover para…" sem classe que o esconda fora do passar do mouse;
   - **B4 no módulo:** cada formulário do módulo (CHG, planos, endpoints, tarefa, comitê, motivo do cancelamento) ligado ao `useUnsavedChangesGuard`; sujo e limpo em cada forma de sair; erro do servidor mantendo os valores; nenhum `window.confirm` no código do módulo.
4. **`frontend/scripts/qa-unsaved-changes.mjs`** (`qa:unsaved-changes`, G4): os testes da peça descritos em 6.3.
5. **Mutação:** em cada lote de código, numa cópia do repositório (scripts na pasta temporária da sessão). Todos os mutantes mortos ou justificados como equivalentes. Mutantes obrigatórios do G0b, entre outros: `finish` sem a checagem de tarefas; checagem de tarefas antes da trava; `approve` sem criar o registro de comitê; **`approve` sem `agendado` gravando a data de hoje sem perguntar** (no servidor: `committee_on` ausente virando o dia de `server_now`; na tela: drop ou "Mover para…" chamando `approve` sem abrir a confirmação da data) (A-CHG-1); `approve` aceitando `committee_on` futuro; confirmação da data preenchida pelo relógio do navegador em vez do `server_now`; `reprovada` sem voltar a `pendente`; reordenar sem conferir a lista; técnico concluindo tarefa de outro; camada otimista sem reversão no 409; drop chamando a ação errada numa célula; diálogo do B4 com o foco em "Descartar"; `reset` também no erro; `beforeunload` registrado com o formulário limpo.
6. **`scripts/test-local.ps1`:** bloco do `qa-chg.php` no mesmo padrão dos atuais (`:86-166`). `qa:unsaved-changes` e `qa:chg` entram no `package.json`.
7. **Ensaio no roteiro:**
   - 021, 022 e 023 pelo `ensaio-migration.sh`, com `VERIFICA` só de contagens (tabelas existem, 0 linhas, FKs, índices únicos e `CHECK` presentes; as duas incoerências da 2.4.2 iguais a 0; rollback de volta);
   - ensaio do código por `git archive` rodando o `qa-chg.php` como `www-data`, como no F7, com a conferência de que não há `/tmp/.env`;
   - carga das referências num banco de ensaio, com o arquivo real fora do repositório e saída só com contagens.
8. **Medição:** `medir-polling.sh` com `URL=…/api/portal/chg.php` como referência, depois de ligar o módulo, junto com `status.php` e `dashboard.php` (critério do F2), registrando **p50 e p95**. O `qa:bundle` registra o tamanho do chunk lazy da rota.

---

## 10. Lotes (renumerados no G0b)

O antigo G6 (tarefas e comitê) virou obrigatório e entrou nos lotes G1, G3 e G5; o antigo G7 (ServiceNow) e a carga inicial por script saíram (respostas 4.3 e 4.6a). Entraram a peça do B4 (G4) e o arrastar e soltar (no G5).

| Lote | Conteúdo | Commits de entrega (estimativa) | Antes (G0) | Depende de |
|---|---|---|---|---|
| G0 | `.gitignore` (`de93913`), requisito e desenho (`d2a9528`) | 1 de docs | G0 | — (aprovado) |
| G0b | Respostas, B1 a B4, padrão de qualidade, D-CHG, levantamento 17f (este) | 1 de docs | — | — |
| G1 — dados | Migrations **021, 022 e 023**, rollback, verificação, carga de referências (2.5), parte de estrutura do `qa-chg.php` | **3** (021 e 022; 023; carga) | G1 + parte do G6 | 4.5; aprovação do G0b; D-CHG-10, D-CHG-11 |
| G2 — serviço e API das CHGs | `ChgStagePolicy`, `ChgService` (repositório separado), `chg.php`, `chg_referencias.php`, permissões, interruptor (com preflight e `.env.example`), auditoria, `stage_message`, `qa-chg.php`, `qa-security`, `test-local.ps1` | 3 (política e serviço; endpoints, permissões e interruptor; ponta a ponta) | G2 | G1; 4.1 (leitura do técnico); 4.6c |
| G3 — tarefas e comitê no serviço e na API | Ações de 3.4.1 e 3.4.2, `approve` pelo comitê, `finish` com tarefas, ordem de trava, resumo e próximo comitê na lista, auditoria e histórico | **2** (comitê e `approve`; tarefas e `finish`) | G6 (agora obrigatório) | G2; D-CHG-2, 3, 8, 9, 12 |
| G4 — peça de UX reutilizável (B4) | `Dialog`, `ConfirmDiscardDialog`, `useUnsavedChangesGuard`, bloqueio no `router.js`, `beforeunload`, `qa:unsaved-changes` | **1** | — (novo) | Aprovação do G0b; D-CHG-4, D-CHG-5 |
| G5 — telas | (a) rota e menu, quadro com botões e "Mover para…", formulário com o B4; (b) arrastar e soltar (C4'); (c) tarefas e comitê no detalhe; (d) lista e copiar tabela; `qa:chg` | **4** (a, b, c, d) | G3 + B3 + parte do G6 | G3 e G4; D-CHG-1, 6, 7; 4.6b; C14 |
| G6 — dashboard e CSV | Dashboard por técnico, KPIs na tela, `chg_export.php` | 2 (dashboard; CSV) | G4 | G5 |
| G7 — documentação de deploy | `API_CONTRACTS_FRONTEND.md`, LGPD 5.3, seção do módulo no `DEPLOY_LINUX.md` com o ensaio da 021 a 023, travas do roteiro no QA, medição p50/p95 | 1 | G5 | G6; 4.5 |
| Futuro (fora deste plano) | "Incluir carteira" pela tela, auditada (2.5.1) | Desenho próprio | — (nota da revisão) | — |
| Saíram | Integração com o ServiceNow (antigo G7); carga inicial por script | — | G7; carga | 4.3; 4.6a |

**Total G1 a G7: cerca de 16 commits de entrega** (antes, cerca de 10, sem tarefas, comitê, arrastar e B4), cada um com o seu commit de docs e parada para revisão. Cada lote de código traz mutação, a bateria completa e a lista de conferência do padrão de qualidade (13).

Se a D-CHG-5 for aprovada com a peça entrando no deploy da Parte B, o G4 vira um item do lote de acabamento (pendência 17f), feito antes do G1, e o módulo só o reaproveita.

---

## 11. O que entra no roteiro único (pendência 16)

Só depois da resposta ao 4.5 do requisito, e **sem escrever o roteiro consolidado antes do fim do F9** (regra da pendência 16). O que o módulo acrescenta:

1. **Antes:** carteiras e locais num arquivo fora do repositório no servidor (diretório A DEFINIR), conferido pelo responsável. Nenhum valor no git.
2. **Migrations, na ordem:** depois da 020 (F9), a 021, a 022 e a 023, cada uma com ensaio, leitura pelo commit salvo do ensaio (`git cat-file -e` / `git show` do `fN-commit`, com `pipefail`) e verificação só com contagens (na 023, as incoerências da 2.4.2 iguais a 0).
3. **Merge único** (`--ff-only`) do commit ensaiado, com `CHG_MODULE_ENABLED` ausente ou `false`:
   - o preflight mostra `CHG_MODULE_ENABLED: OK (desligado)`;
   - `curl` só com o código HTTP confere **404** em `api/portal/chg.php`.
4. **Carga das referências:** `scripts/carregar-referencias-chg.php` com o arquivo fora do repositório. Saída só com contagens; a verificação confere as contagens (carteiras ativas, locais ativos, CHGs = 0, tarefas = 0, comitês = 0).
5. **Ligar:**
   - cópia do `.env` com `install -m 600` antes do `sed` (padrão do B3 do F7);
   - `CHG_MODULE_ENABLED=true` e recarga do PHP-FPM;
   - preflight com `OK (ligado)`;
   - conferência no navegador pelo responsável (menu, lista vazia, cadastro de teste **sem dado real**, com uma tarefa e um comitê, movido por arrastar e pelo "Mover para…", e depois cancelado e arquivado);
   - medição do polling, p50 e p95 (seção 9, item 8).
6. **Rollback, em degraus:**
   1. desligar o interruptor (rápido, sem perda);
   2. código, só com aprovação;
   3. migrations, na ordem inversa (023, 022, 021), só com as tabelas vazias ou com cópia `*_bkp_02N` e a guarda `SIGNAL`.
7. **Avisos:**
   - à gestão: começa a digitação das CHGs existentes (4.6a); o quadro antigo vira só referência até o fim dela; a despublicação do artefato depois disso é decisão do responsável (M3);
   - à equipe: o aviso de texto livre (seção 7) e a regra de concluir com tarefas (D-CHG-2, se aprovada).

Se a resposta ao 4.5 for "depois da Parte B", os mesmos passos viram a seção "Deploy que traz o módulo de CHGs" do `DEPLOY_LINUX.md`, num deploy próprio.

---

## 12. Riscos

| Risco | Mitigação |
|---|---|
| Volume maior que o esperado (sem paginação) | C16 (500 itens com aviso); arquivamento; paginação num lote seguinte se o volume pedir |
| Carga no PHP-FPM (`pm.max_children=5`, `docs/AUDITORIA_2026-09.md:108`) com mais um polling | 60 s só com a tela visível; três consultas fixas; medição no roteiro |
| Escopo maior com tarefas, comitê, arrastar e B4 (cerca de 16 commits em vez de 10) | Lotes separados (G3, G4, G5b e G5c) com parada em cada um; padrão de qualidade como critério de aceite (13) |
| Dados hoje fora da infraestrutura (requisito, achado M3): o modelo é um artefato publicado no claude.ai | Sem importação (4.6a): nada é lido de lá; despublicação depois da digitação, decisão do responsável |
| Digitação das CHGs existentes com erro (sem importação) | Mesmas validações do cadastro; histórico e auditoria; comitê com data passada só com resultado (D-CHG-10); o quadro antigo fica como referência até o fim |
| Incoerência entre comitê e `chg_status` (o banco não amarra as duas tabelas) | Uma transação com a CHG travada (D-CHG-3, 2.6); QA de cada resultado; contagem de incoerências na verificação da 023 |
| Deadlock entre CHG e tarefa ou comitê | Ordem de trava fixa (2.6), conferida no QA |
| Arrastar no toque não funcionar em algum aparelho (nativo) | "Mover para…" sempre visível; D-CHG-1 deixa a troca para o `@dnd-kit/core` como alternativa medida |
| Responsável de tarefa arquivado (F4) com tarefa aberta | Tarefa continua com o nome e "(arquivado)"; a gestão troca o responsável. A integração com a regularização do arquivamento fica fora do escopo e vira pendência se aparecer no uso |
| Peça do B4 alterar o `router.js`, usado por todas as telas | Bloqueio só existe com formulário sujo registrado; sem registro, o `navigate` é o de hoje; QA próprio e mutação |
| Lista de carteiras desatualizada | Governança do arquivo (A DEFINIR); carga idempotente que inativa sem apagar |
| Etapa "parada" na tela por até 60 s | Aceito (igual ao modelo); a ação seguinte usa sempre a etapa recalculada no servidor |
| Módulo entrar no deploy da Parte B sem decisão | 4.5 decide; com o código no branch atual, vai no merge desligado (C1) e sem migration aplicada não toca no banco (404 antes) |

---

## 13. Padrão de qualidade por lote (lista de conferência)

O padrão vem do requisito, seção 3 (responsável, 2026-10-29). Cada entrega de código traz esta lista preenchida (cumprido, ou exceção justificada). "—" = não se aplica ao lote.

| Item | G1 | G2 | G3 | G4 | G5 | G6 | G7 |
|---|---|---|---|---|---|---|---|
| Autorização no servidor por ação e por objeto, com `CHG_WRITE_DENIED` | — | Sim | Sim | — | Servidor já cobre; a tela só esconde | Sim (`chg.export`) | — |
| CSRF em todo POST | — | Sim | Sim | — | — | — | — |
| Entrada validada no servidor, listas fechadas | Carga | Sim | Sim | — | — | Filtros | — |
| Sem `innerHTML`/`dangerouslySetInnerHTML`; links só `https` | — | Validador | — | Sim | Sim | — | — |
| Nenhum dado real, segredo ou IP; varredura contra o modelo só com contagens | Sim | Sim | Sim | Sim | Sim | Sim | Sim |
| Auditoria sem texto livre (marcador injetado não aparece) | — | Sim | Sim | — | — | Sim | — |
| `FOR UPDATE` na ordem de 2.6; `row_version` com 409 | — | Sim | Sim | — | Reversão no 409 | — | — |
| FKs `RESTRICT`, `CHECK`, `ENUM` e índices únicos espelhando o serviço | Sim | — | Conferido | — | — | — | — |
| Nada apagado; duplo envio protegido (botão e servidor) | Rollback com guarda | Sim | Sim | — | Botão desabilitado | — | — |
| Datas em `America/Sao_Paulo`; etapa só pelo `server_now` | `SET time_zone` | Sim | Sim | — | Sem `new Date()` na etapa | Dia do servidor | — |
| Consultas em número fixo, indexadas (SQL registrado) | Índices | Sim | Sim | — | — | Sim | — |
| Lista limitada; polling de 60 s só com a tela visível | — | Sim | — | — | Sim | — | — |
| Chunk lazy com o tamanho no `qa:bundle` | — | — | — | Sim | Sim | Sim | — |
| p50/p95 do `chg.php` no roteiro | — | — | — | — | — | — | Sim |
| WCAG 2.1 AA (foco, teclado, `aria-live`, texto além da cor) | — | — | — | Sim | Sim | Sim | — |
| Feedback de sucesso, erro e carregamento; estados vazio, erro e degradado | — | Mensagens | Mensagens | Sim | Sim | Sim | — |
| Sem rolagem horizontal que esconda ação (C14), medido no navegador | — | — | — | — | Sim | Sim | — |
| Microcopy em PT-BR acentuado | — | Mensagens | Mensagens | Sim | Sim | Sim | Sim |
| `qa-chg.php`, `qa:chg`, `qa:unsaved-changes` conforme o lote | Sim | Sim | Sim | Sim | Sim | Sim | Travas do roteiro |
| Mutação com todos os mutantes mortos ou justificados | Sim | Sim | Sim | Sim | Sim | Sim | — |
| Bateria completa (`qa:*`, lint, typecheck, build, QAs PHP, `test-local.ps1`) | Sim | Sim | Sim | Sim | Sim | Sim | Sim |
| Nada fora do escopo; achados viram pendência | Sim | Sim | Sim | Sim | Sim | Sim | Sim |

---

## 14. Levantamento do B4 nas outras telas (pendência 17f; sem mudança de código)

Pedido do responsável em 2026-10-29: levantar, **sem mudar agora**, os formulários do sistema que perdem dados do mesmo jeito. Leitura do código em `d2a9528`; nada foi alterado.

**Fatos gerais (valem para todas as telas):**
- nenhum modal trata **Esc**, prende o foco ou tem `role="dialog"`: são uma `div` fixa com um `button` de fundo que fecha no clique (seção 1);
- nenhum formulário avisa antes de fechar, trocar de rota (`frontend/src/lib/router.js:13-30` não tem bloqueio) ou recarregar (não há `beforeunload` em `frontend/src`);
- **erro do servidor mantém os dados** em todos os levantados: a limpeza fica no `onSuccess` do `runAction` (`frontend/src/lib/actionRunner.ts:21-58`) ou depois do `await` bem-sucedido (por exemplo, `frontend/src/pages/PaMapPage.jsx:347-358`, `frontend/src/pages/CalendarPage.jsx:191-197`).

| Tela | Formulário | Onde e estado | Como perde hoje (além de trocar de rota e recarregar) | "Sujo" disponível? | Recomendação |
|---|---|---|---|---|---|
| **Chamados críticos** | Novo e editar chamado | Modal; `useState` (`frontend/src/pages/CriticalIncidentsPage.jsx:168-170`) | **Clique fora** (`:206`), "Cancelar" (`:218`, `:270`). Formulário longo, com as descrições da sala | Não (`useState`): comparar com o estado inicial ou migrar para RHF | **Antes do deploy** (maior perda). Depois do item 1 do 17e (mesma tela), para não conflitar |
| Chamados críticos | Importação de planilha | Modal (`:311`) | Clique fora (`:366`), "Fechar" (`:370`): perde o arquivo escolhido | Arquivo escolhido | Depois do deploy (refazer a escolha é barato) |
| **Funcionários** (Administração) | Editar funcionário | Modal; React Hook Form (`frontend/src/pages/AdminPage.jsx:735-749`) | Clique fora (`:737`), "Cancelar" (`:745`, `:749`); **Editar em outra linha troca o formulário sem perguntar** (`edit()`, `:495-503`) | Sim (`isDirty`) | **Antes do deploy** (Parte B; barato) |
| Funcionários | Adicionar funcionário | Na página; React Hook Form (`:647`) | Trocar de aba da Administração (`navigate`, `:991`) | Sim | Antes do deploy, junto com a edição |
| **Ausências** | Nova e editar ausência | Na página; React Hook Form (`frontend/src/pages/AbsencesPage.jsx:288-289`) | "Fechar" e "Cancelar edição" (`:369`, `closeForm`, `:124-130`); **Editar em outra linha troca sem perguntar** (`editAbsence`, `:133-148`; botão `:263`) | Sim | **Antes do deploy** (Parte B) |
| **Escala presencial** | Regra de escala | Na página; React Hook Form (`frontend/src/pages/OperationalPages.jsx:299-311`, formulário `:525`) | **Editar outra regra troca sem perguntar** (`editRule`, `:425-444`; botão `:698`); remover a regra do mesmo colaborador limpa (`:454`) | Sim | **Antes do deploy** (Parte B) |
| Escala presencial | Exceção | Na página; `useState` (`:322`, formulário `:630`) | Só rota e recarga | Não | Depois do deploy (formulário curto) |
| Horas extras e Correção de ponto | Nova solicitação | Na página; `useState` (`OperationalPages.jsx:774-776`, formulário `:884`) | Trocar entre as duas telas é troca de rota (`:951-957`) | Não | Depois do deploy (curto; pede "sujo" manual ou RHF) |
| Mapa PA | Vínculo da PA | Modal; `useState` (`frontend/src/pages/PaMapPage.jsx:194-196`) | Clique fora (`:212`), "Fechar" (`:220`) | Não | Depois do deploy (curto) |
| Calendário (fora da lista do pedido) | Novo evento | Na página; `useState` (`frontend/src/pages/CalendarPage.jsx:140-144`) | "Cancelar" (`:253`) só esconde; os dados ficam até sair da tela | Não | Depois do deploy |
| Plantões (fora da lista) | Novo plantão | Na página; `useState` (`OperationalPages.jsx:964`, formulário `:981`) | Só rota e recarga | Não | Depois do deploy |
| Documentos e Escalas de plantão, envio (fora da lista) | Envio de arquivo | Modais (`frontend/src/pages/DocumentsPage.jsx:36`, fundo `:95`; `frontend/src/pages/ShiftSchedulesPage.jsx:88`, fundo `:153`) | Clique fora, "Cancelar" e "Fechar" | Arquivo escolhido | Depois do deploy |
| Administração (fora da lista) | Usuário local; troca de senha local | Na página (`AdminPage.jsx:789`, `:946`) | Rota e aba | Não | Depois do deploy (o admin local é bloqueado fora de `development`) |

**Achados fora do B4 (sem mudança, registrados):**
- `window.confirm` ainda em cinco lugares (seção 1); o projeto vem retirando. Fica para depois do deploy, junto com o diálogo acessível.
- Os modais sem Esc, sem foco preso e sem `role="dialog"` ficam abaixo da WCAG 2.1 AA fora do módulo (critérios 2.1.2, 2.4.3 e 4.1.2). O `Dialog` do G4 resolve onde for aplicado.

**Pendência 17f (registrada):** proteção contra perda de alterações e diálogo acessível no resto do sistema. **Recomendação (D-CHG-5):**
- **antes do deploy único:** Chamados críticos (novo e editar), Funcionários (editar e adicionar), Ausências e Escala presencial (regra). Um commit para a peça (o G4, feito fora do módulo) e um por tela ou par de telas: **cerca de 4 commits de entrega**, cada um com docs e parada, **depois** dos três itens do 17e;
- **depois do deploy:** os demais da tabela e a troca dos `window.confirm`.

Se o responsável não quiser crescer o lote de acabamento, a alternativa é deixar todo o 17f para depois do deploy e construir a peça só no G4 do módulo.

---

## 15. Revisões

**Revisão do G0 (`de93913`, `d2a9528`) pelo responsável, 2026-10-29: requisito e desenho APROVADOS como base.** C1 a C16 aprovadas, **exceto a C4**, que muda para o arrastar e soltar (pedido B3; C4' em 0.1 e 6.2). Duas notas:
1. acrescentar ao desenho, como lote futuro, a ação de admin "incluir carteira" na tela, auditada (C2): **feito em 2.5.1 e na seção 10**;
2. no M3 e no 4.6a, registrar que o modelo é um artefato publicado no claude.ai (`window.claude.use("db")`), que os dados do quadro atual podem estar fora da infraestrutura da empresa e que a despublicação depois da migração é decisão do responsável: **feito no requisito (M3, 4.6a) e na seção 7 deste desenho**.

Respostas: 4.1 (a equipe lança; solicitante é texto; leitura do técnico A CONFIRMAR), 4.2 (tarefas e comitê; sem tabela de aprovadores), 4.3 (digitado à mão; o antigo G7 sai), 4.4 (CSV), 4.6a (sem importação). **4.5 em aberto: nenhum código antes da decisão.** Pedidos B1 a B4 e o padrão de qualidade incorporados no G0b.

**G0b (2026-10-29): entregue (`258c6ce`).**

**Revisão do G0b (`258c6ce`) pelo responsável, 2026-10-29: APROVADO, com D-CHG-1 a D-CHG-12 como recomendadas e um ajuste (A-CHG-1).**
- **QAs separados:** `qa:unsaved-changes` e `qa:chg`; a bateria vai a **18 + 2** `qa:*`, porque a peça do B4 também serve fora do módulo.
- **A-CHG-1 (médio), ajusta a D-CHG-3 e a D-CHG-6:** sem comitê `agendado`, a aprovação (botão, "Mover para…" ou drop em Preparação ou Execução) não pode criar o registro com a data de hoje sem perguntar, porque na digitação das CHGs existentes a data real do comitê é anterior. Com `agendado` aberto, o drop aprova direto; sem ele, abre a confirmação curta (o `Dialog` da 6.3) pedindo a data do comitê, preenchida com a data de hoje do servidor e editável, com as regras da D-CHG-10 (passado permitido com resultado; nunca no futuro para `aprovada`). **Feito em 0.2 (D-CHG-3 e D-CHG-6), 3.4 (`approve`), 3.4.1 (tabela da data do comitê), 6.2.1 (células e regra da confirmação), 9 (testes e mutantes obrigatórios).**
- **Uma leitura da IA, a conferir na próxima revisão:** um `agendado` com data **futura** não pode receber `aprovada` com essa data (D-CHG-10). Nesse caso, a aprovação também abre a confirmação, e o `agendado` recebe a data informada, com a troca auditada (3.4.1, última linha da tabela). A alternativa seria recusar com 409 e pedir a remarcação antes.

**G1 continua bloqueado:** o 4.5 (branch e deploy) e os itens A CONFIRMAR (leitura do técnico, 4.6c, listas da C6 e da C10, formato da C12, 4.6b) serão respondidos pelo responsável na próxima revisão.
