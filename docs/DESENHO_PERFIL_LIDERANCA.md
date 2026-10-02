# Perfil de Liderança — desenho do Lote 5 (SEC-04, SEC-10)

**Data:** 2026-10-02
**Situação:** **aprovado em 2026-10-02**: decisões L1 a L9 como recomendadas (seção 9), com os acréscimos A1 a A5 (seção 9.1), que viram requisito. Sublote 5a implementado em commit local (seção 10); 5b e 5c aguardam. A P12 continua em aberto.
**Pedido (2026-09-30):** a Liderança mantém adicionar e remover funcionários, aprovar pausas, horas extras e correções, gerenciar escalas, Mapa PA e ausências. Não pode mexer em configurações, integrações e usuários locais, nem promover alguém a Liderança ou admin. Só um admin da allowlist `AD_ADMIN_USERS` promove, com auditoria CRITICAL.

---

## 1. Problema hoje

### 1.1 Duas fontes de verdade para "admin" (SEC-04)

| # | Onde | O que acontece |
|---|---|---|
| 1 | `api/adicionar_funcionario.php:48-50`, `api/atualizar_funcionario.php:46-48`, `config.php:759-761` (`normalizar_funcionario_array`) | `equipe = 'lideranca'` força `access_role = 'admin'`, em três pontos independentes |
| 2 | `api/login_ci.php:55-65` | Login CI de quem tem `access_role` `admin` grava `admin_logged_in = true`, **sem consultar `AD_ADMIN_USERS`** |
| 3 | `api/login_admin.php:52-58, 74-75` | Com `ENABLE_LOCAL_ADMIN=true`, usuário local com `role = 'admin'` vira admin, também fora da allowlist |
| 4 | `frontend/src/pages/AdminPage.jsx:296-306` | Selecionar a equipe "Liderança" no formulário marca o perfil Admin |

Efeito: o conjunto real de administradores é `AD_ADMIN_USERS ∪ {access_role = 'admin'} ∪ {admins locais}`. O segundo conjunto cresce sozinho: quem virou admin pela equipe pode promover outros do mesmo jeito. A promoção é um efeito colateral de mudar a equipe.

Os **3 colaboradores ativos da Liderança** são, hoje, **admins completos**: veem e alteram Configurações, Integrações, Usuários locais e Segurança, e promovem outros.

### 1.2 `AUTH_SOURCE` inerte (SEC-10)

`config.php:111` define `AUTH_SOURCE` com padrão `json_fallback`; os `.env.*.example` dizem `ad`. Nada ramifica por ela. O único consumo é a exibição em `api/configuracoes.php:16`, que o frontend usa para marcar "Autenticação AD/LDAP" como Parcial (`AdminPage.jsx:713`, `784`). O controle real da autenticação local é `ENABLE_LOCAL_ADMIN`.

### 1.3 Como o perfil é resolvido hoje

| Peça | Evidência |
|---|---|
| Valores de `access_role` | `tecnico`, `gestor`, `admin`, `somente_leitura` (`migrations/20260613_005...sql:15`, `security.php:146-151`) |
| Perfil da sessão | `current_portal_role()` (`security.php:864-879`): `admin_logged_in` vira `admin`; qualquer outra sessão de gestão vira `gestor` (**qualquer valor diferente cai em `gestor`**); sessão CI usa `ci_access_role` |
| Permissões | `portal_permissions_for_role()` (`security.php:881-896`), enviadas ao frontend por `api/session.php` |
| Guardas | Listas de perfis espalhadas: `require_portal_auth([...])`, `in_array($role, [...])` nos endpoints, `verificar_admin_login_api()` nos endpoints legados, e checagens dentro de serviços (`DocumentService`, `ShiftAttachmentService`, `OperationalService`, `NotificationService`) |

---

## 2. Proposta

1. **Admin vem só de `AD_ADMIN_USERS`.** `access_role = 'admin'` deixa de conceder administração. Única exceção: o admin local de emergência (decisão L4).
2. **Perfil novo `lideranca`** em `access_role`, entre `gestor` e `admin`.
3. **Equipe e perfil separados.** `equipe = 'lideranca'` passa a ser só a equipe organizacional (escala, Mapa PA, Visão Operacional, P12). Não concede permissão nenhuma.
4. **Promover a Liderança** (gravar `access_role = 'lideranca'`) e rebaixar quem é Liderança: só admin, sempre com auditoria `CRITICAL`. Promover a admin não existe no sistema: é editar `AD_ADMIN_USERS` no `.env`.
5. **Guardas por permissão.** Os pontos tocados por este lote passam a perguntar "tem a permissão X", não "o perfil está na lista Y". A tabela de permissões fica em um só lugar (`portal_permissions_for_role`), e o frontend já consome `permissions`.

### 2.1 Resolução do perfil na sessão

| Situação no login | Perfil da sessão | Observação |
|---|---|---|
| Login AD na allowlist (`login_ci.php` ou `login_admin.php`) | `admin` | Independe do `access_role` do cadastro |
| CI com `access_role = 'lideranca'` | `lideranca` | `admin_logged_in = false` |
| CI com `access_role = 'gestor'` | `gestor` | Sem mudança |
| CI com `access_role = 'admin'` **fora** da allowlist (valor legado) | `lideranca` se `equipe = 'lideranca'`; senão `gestor` | Regra de transição (seção 5); audita `ADMIN_ROLE_SEM_ALLOWLIST` (WARNING) a cada login |
| Login local (`ENABLE_LOCAL_ADMIN=true`) | `admin` ou `gestor`, conforme `usuarios.role` | Decisão L4 |

`current_portal_role()` passa a aceitar `lideranca` da sessão de gestão, em vez de rebaixá-la para `gestor`.

A allowlist é comparada pela mesma normalização do bind (A2), e o perfil é recalculado a cada requisição (A1, seção 9.1).

### 2.2 Permissões

| Permissão | admin | lideranca | gestor | tecnico | somente_leitura |
|---|---|---|---|---|---|
| `portal.read` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `pausas.use` | ✔ | ✔ | ✔ | ✔ | — |
| `metricas.read`, `relatorios.read` | ✔ | ✔ | ✔ | — | — |
| `operacao.approve` (pausas, horas extras, correções) | ✔ | ✔ | ✔ | — | — |
| `documentacao.edit`, `avisos.manage` | ✔ | ✔ | ✔ | — | — |
| **`funcionarios.manage`** (novo) | ✔ | ✔ (restrito, 2.3) | — | — | — |
| **`escalas.manage`** (novo: remover regra, publicar anexo de escala) | ✔ | ✔ | — | — | — |
| **`pa_map.manage`** (novo) | ✔ | ✔ | — | — | — |
| **`ausencias.manage`** (novo, para o F3) | ✔ | ✔ | ✔ (3.8 do desenho da Parte B) | — | — |
| **`pausas.force_end`** (novo) | ✔ | decisão L5 | — | — | — |
| **`perfis.promote`** (novo) | ✔ | — | — | — | — |
| `configuracoes.manage`, `integracoes.manage` | ✔ | — | — | — | — |
| **`usuarios_locais.manage`** (novo) | ✔ | — | — | — | — |
| `admin.manage` | ✔ | — | — | — | — |

Salvar regra de escala e importar planilha continuam com gestor e ganham a Liderança. O que é só da Liderança e do admin: remover regra, publicar anexo de escala e alterar o Mapa PA.

### 2.3 Funcionários: o que a Liderança pode

| Ação | admin | lideranca |
|---|---|---|
| Criar com perfil `tecnico`, `somente_leitura` | ✔ | ✔ |
| Criar ou mudar para `gestor` | ✔ | **decisão L2** (recomendado ✔, com `CRITICAL`) |
| Criar ou mudar para `lideranca` | ✔ (`CRITICAL`) | **403** |
| Gravar `access_role = 'admin'` | **400 para todos** (admin vem da allowlist; decisão L3) | 400 |
| Editar, desativar ou reativar cadastro cujo perfil atual é `lideranca` (inclusive o próprio) | ✔ | **403** |
| Editar cadastro com perfil `admin` legado | ✔ | **403** |
| Trocar `ad_login` de cadastro `gestor` | ✔ (`CRITICAL`) | ✔ (`CRITICAL`; L2 aprovada) |
| Trocar `ad_login` de cadastro `tecnico` ou `somente_leitura` | ✔ (`WARNING`) | ✔ (`WARNING`) |
| Equipe `lideranca` para qualquer cadastro | ✔ | ✔ (não concede permissão) |

A regra fica numa função pura, por exemplo `funcionario_change_allowed(string $actorRole, ?string $targetCurrentRole, string $targetNewRole, bool $changesAdLogin): ?string` (devolve o motivo da recusa ou `null`), usada pelos três endpoints de funcionário e testada sem banco. A checagem do perfil **atual** do alvo lê o banco, então o teste de ponta a ponta dessa parte só roda no servidor.

**Por que bloquear o `ad_login` de cadastros elevados:** quem troca o `ad_login` de um gestor entrega o perfil de gestor a outra conta do AD. Para a Liderança isso só é permitido nos perfis que ela mesma pode conceder.

---

## 3. Matriz atual × proposta, por endpoint

"Hoje admin" inclui os 3 da Liderança. **Negrito** marca mudança. Gestor, técnico e somente leitura não mudam em nenhuma linha, exceto onde indicado.

| Endpoint / ação | Hoje: admin | Hoje: gestor | Proposta: admin | Proposta: lideranca | Guarda proposta |
|---|---|---|---|---|---|
| `api/adicionar_funcionario.php` | ✔ | 403 | ✔ | **✔ com 2.3** | `funcionarios.manage` + função pura |
| `api/atualizar_funcionario.php` | ✔ | 403 | ✔ | **✔ com 2.3** | idem + perfil atual do alvo |
| `api/remover_funcionario.php` (desativa) | ✔ | 403 | ✔ | **✔ com 2.3** | idem |
| `api/listar_funcionarios.php` (inativos) | ✔ | só ativos | ✔ | **✔** | `funcionarios.manage` |
| `api/aprovar_pausa.php`, `rejeitar_pausa.php`, `solicitacoes_pendentes.php` | ✔ | ✔ | ✔ | ✔ | `operacao.approve` |
| `portal/overtime.php` e `portal/time_corrections.php` (`action=decision`) | ✔ | ✔ | ✔ | ✔ | `operacao.approve` |
| `OperationalService::listPendingWorkflowApprovals` | ✔ | ✔ | ✔ | ✔ | idem |
| Notificações de aprovação (`NotificationService:67`) | admin, gestor | — | admin, gestor | **+ lideranca** | `recipient_role` é `VARCHAR(30)`: sem migration |
| `portal/admin_force_end_break.php` | ✔ | 403 | ✔ | **L5** | `pausas.force_end` |
| `portal/schedules.php` salvar regra, importar planilha | ✔ | ✔ | ✔ | ✔ | lista atual (admin, gestor) **+ lideranca** |
| `portal/schedules.php` `remove_rule` | ✔ | 403 | ✔ | **✔** | `escalas.manage` |
| `portal/shift_attachments.php` POST (`ShiftAttachmentService:297`) | ✔ | 403 | ✔ | **✔** | `escalas.manage` |
| `portal/oncall.php` e `portal/calendar.php` POST | ✔ | ✔ | ✔ | ✔ | lista atual (admin, gestor) **+ lideranca** |
| `portal/pa_map.php` POST e `can_manage` | ✔ | 403 | ✔ | **✔** | `pa_map.manage` |
| Ausências (F3, ainda não existe) | — | — | ✔ | ✔; retroativa: **L6** | `ausencias.manage` |
| Regra de escala retroativa (P10, F1) | — | — | ✔ | **L6** | — |
| `portal/reports.php`, `api/metricas.php`, `api/download_relatorio.php` | ✔ | ✔ | ✔ | ✔ | `relatorios.read` / `metricas.read` |
| Documentos (`DocumentService:238, 275`) e chamados críticos (gestão e importação) | ✔ | ✔ | ✔ | ✔ | lista atual (admin, gestor) **+ lideranca** |
| `api/configuracoes.php` GET | ✔ | 403 | ✔ | **403** | `configuracoes.manage` |
| `api/salvar_configuracao.php` | ✔ | 403 | ✔ | **403** | idem |
| `portal/integrations.php` | ✔ | 403 | ✔ | **403** | `integracoes.manage` |
| `api/usuarios.php`, `api/alterar_senha_admin.php` | ✔ | 403 | ✔ | **403** | `usuarios_locais.manage` |
| Promover a Liderança ou rebaixar Liderança | ✔ (só `FUNCIONARIO_PERFIL_ALTERADO` CRITICAL em atualização) | 403 | ✔, **CRITICAL em criação e atualização** | **403** | `perfis.promote` |
| Páginas legadas `admin.php` (`verificar_admin_login`) | ✔ | redireciona | ✔ | **redireciona** | sem mudança (só admin) |
| `api/session.php` | `role`, `permissions` | idem | idem | **`role = 'lideranca'`** | — |

Frontend, nos pontos que hoje comparam o nome do perfil: `AdminPage.jsx:807-817` (abas por permissão: Liderança vê Aprovações e Funcionários; Configurações, Usuários e Segurança só admin), opções de perfil no formulário (`AdminPage.jsx:337`, `formSchemas.ts:42`), `OperationalPages.jsx:167, 226-227, 568, 759`, `CalendarPage.jsx:83`, `CriticalIncidentsPage.jsx:412`, `DashboardPage.jsx:41`, `App.jsx:139`. O Mapa PA já usa `can_manage` vindo do servidor. O formulário deixa de marcar Admin ao escolher a equipe Liderança (`AdminPage.jsx:296-306`).

---

## 4. Impacto nos 3 ativos da Liderança

| Hoje (admin completo) | Depois (perfil `lideranca`) |
|---|---|
| Funcionários, aprovações, escalas, Mapa PA, relatórios, documentos, chamados | **Mantém** |
| Configurações, Integrações, Usuários locais, aba Segurança | **Perde** |
| Promover alguém a Liderança ou admin | **Perde** |
| Editar o próprio cadastro ou o de outro líder | **Perde** (só admin) |
| Forçar fim de pausa; ausência e regra de escala retroativas | Conforme L5 e L6 |

Quem dos três precisar continuar admin entra em `AD_ADMIN_USERS`; a partir daí é admin por esse caminho, inclusive pelo login CI.

**Situação no servidor (2026-10-02, informada pelo responsável, só contagens):**

| Grupo | Ativos | Inativos | Na allowlist | Perfil efetivo depois do Lote 5 |
|---|---|---|---|---|
| Liderança (`equipe = 'lideranca'`, `access_role = 'admin'`) | 3 | 0 | 2 | **2 admin** (allowlist) e **1 `lideranca`** |
| N2 com `access_role = 'admin'` | 2 | 1 | 1 (o responsável) | 1 admin (allowlist); 1 **gestor** (regra de transição); o inativo vira `tecnico` no `016b` |
| Demais | — | — | 0 | Sem mudança (todos `tecnico`; nenhum outro perfil existe hoje) |

- `AD_ADMIN_USERS` tem 3 logins, todos com cadastro de funcionário.
- **Allowlist sem mudança por enquanto** (decisão do responsável); revisão depois do perfil `lideranca` no ar. Com isso, **só 1 dos 3 líderes** passa ao perfil `lideranca` no deploy; os outros 2 continuam admin pela allowlist.
- O N2 ativo com `admin` fora da allowlist passa a `gestor` pela regra de transição (2.1); o `016b` **não** o altera.

Comunicar antes do deploy: ao líder que passa a `lideranca` e ao N2 que passa a `gestor`, as abas que somem e o caminho para pedir uma configuração ao admin.

---

## 5. Migração

**Migration 016** (011 a 014 reservadas para a Parte B; 015 é o Lote 7), `migrations/20261002_016_lideranca_profile.sql`. Idempotente, começa com `SET time_zone = 'America/Sao_Paulo';`, sem `NOW()`, sem alteração de linhas:

0. **Checagem prévia (A3):** conta os `ad_login` não nulos repetidos (na collation da coluna, sem diferença de maiúsculas). Se houver algum, a migration para **antes de qualquer alteração**, com erro de tabela inexistente cujo nome explica o motivo (`migration_016_abortada_ad_login_duplicado`). O cliente `mysql` em lote para no primeiro erro.
1. Amplia o `ENUM` de `funcionarios.access_role` para `('tecnico', 'gestor', 'lideranca', 'admin', 'somente_leitura')`, só se `'lideranca'` não estiver no `COLUMN_TYPE`.
2. Cria `UNIQUE KEY uq_funcionarios_ad_login (ad_login)`, se não existir (A3). `NULL` repetido continua permitido. Convive com `uq_funcionarios_ad_login_ativo` (só ativos) e com `idx_ad_login`, que fica redundante e **não** é removido.

Pode ser aplicada **antes** do `git pull`, como a 015: ampliar o enum não altera linhas, e o código antigo não grava `lideranca`. Efeito do `UNIQUE` sobre o código antigo, até o `git pull`: cadastrar ou editar funcionário com o login de um cadastro **inativo** passa a falhar com erro 500 (antes era aceito). Com os dados de hoje (30 logins distintos) só acontece se alguém tentar isso na janela. O código do 5a confere o login em todos os cadastros, ativos e inativos, e responde 400 com mensagem.

Consequência permanente do `UNIQUE`: um login do AD fica preso ao cadastro, mesmo inativo. Recontratação reativa o cadastro antigo em vez de criar outro.

**Normalização dos dados, depois do código novo no ar** (`016b`, no 5b, idempotente):

| Passo | Linhas (dados de 2026-10-02) |
|---|---|
| `access_role = 'admin' AND equipe = 'lideranca'` → `lideranca` | 3 (os 2 da allowlist continuam admin pela allowlist) |
| `access_role = 'admin' AND ativo = 0 AND equipe <> 'lideranca'` → `tecnico` (**UPDATE aprovado em 2026-10-02**) | 1 |
| `access_role = 'admin' AND ativo = 1 AND equipe <> 'lideranca'` | **Não altera** (1; vira `gestor` na sessão pela regra de transição) |

Antes de alterar, o `016b` copia `id`, perfil anterior e perfil novo de cada linha para a tabela `funcionarios_perfil_016b` (criada por ele, `INSERT IGNORE`, idempotente). O `016b_down` restaura exatamente essas linhas a partir dela. Registra no `audit_log` um evento `CRITICAL` por passo, com a contagem (`LIDERANCA_PERFIL_MIGRADO`, `ADMIN_INATIVO_REBAIXADO`; data pelo `DEFAULT` da coluna, permitido pela regra do A4).

Por que em duas etapas: o código antigo lê `access_role` direto do banco no login CI (`validate_access_role` recusa `'lideranca'` e cai em `tecnico`). Se os dados mudassem antes do código, o líder fora da allowlist perderia o acesso durante a janela. O código novo trata o `admin` legado pela regra de transição (2.1), então a ordem 016 → código → 016b não tem janela sem acesso.

## 6. Rollback

Ordem inversa, cada passo reversível:

1. **Dados** (`016b_down`), com o código novo ainda no ar: devolve o perfil anterior só às linhas registradas em `funcionarios_perfil_016b` que ainda estão com o perfil que o `016b` gravou. Quem foi promovido a `lideranca` depois do deploy **não** é tocado: com o código antigo, `lideranca` não é reconhecido e o login cai em `tecnico` (menos privilégio, nunca mais). Registra `LIDERANCA_PERFIL_REVERTIDO` (`CRITICAL`).
2. **Código:** o rollback normal de `DEPLOY_LINUX.md`.
3. **Estrutura** (`016_down`, opcional): remove `uq_funcionarios_ad_login` e volta o enum. Para sem alterar nada se alguma linha usar `'lideranca'`.

Sem os passos 1 e 3, o código antigo funciona com o enum ampliado e o `UNIQUE` (com a ressalva da seção 5 sobre login de cadastro inativo).

---

## 7. Testes negativos (para o lote de implementação)

Seguem o padrão do `qa-security.php`: processo filho com sessão e CSRF simulados, banco e AD inexistentes, e toda recusa de perfil **antes** de tocar no banco.

| # | Caso | Esperado |
|---|---|---|
| 1 | Liderança em `configuracoes.php`, `salvar_configuracao.php`, `portal/integrations.php`, `usuarios.php`, `alterar_senha_admin.php` | 403 |
| 2 | Liderança criando ou alterando cadastro com `access_role = 'lideranca'` | 403, sem banco |
| 3 | Qualquer perfil gravando `access_role = 'admin'` | 400 (L3) |
| 4 | Função pura de 2.3: todas as combinações de perfil do ator × perfil atual × perfil novo × troca de `ad_login` | Tabela-verdade completa |
| 5 | `equipe = 'lideranca'` com `access_role = 'tecnico'` (`normalizar_funcionario_array` e endpoints) | Continua `tecnico` |
| 6 | Resolução do perfil da sessão (função pura): allowlist × `access_role` × equipe | Admin só com allowlist; `admin` legado vira `lideranca` ou `gestor` |
| 7 | Sessão de gestão com `portal_role = 'lideranca'` em `current_portal_role()` | `lideranca`, não `gestor` |
| 8 | Gestor em `pa_map.php` POST, `remove_rule`, upload de anexo de escala, endpoints de funcionário | 403 (sem regressão) |
| 9 | Técnico e somente leitura em todos os endpoints de gestão | 403 |
| 10 | Controle positivo: Liderança passa da checagem de perfil em `pa_map.php` POST e `remove_rule` | Chega à validação (400), não 403 |
| 11 | Matriz da seção 3 como dado do QA: cada endpoint × cada perfil | Status esperado; endpoint novo sem linha na matriz reprova |
| 12 | Autoaprovação de hora extra pela Liderança (`actorEmployeeId` pelo `ad_login`) | Recusada, como hoje para gestor |
| 13 | Promoção a Liderança por admin (criação e atualização) | Evento `CRITICAL`; teste estrutural impede outro caminho de gravar `lideranca` |
| 14 | Frontend (`qa:*`): abas e botões por permissão, formulário sem a opção Admin e sem o vínculo equipe ⇒ perfil | — |

Testes existentes que mudam: `scripts/qa-smoke.php:133-141` (hoje confere a regra `lideranca ⇒ admin`) passa a conferir a ausência dela. Mutação, como nos lotes anteriores.

**Sem MySQL local:** a leitura do perfil atual do alvo, as migrations e o `016b` só são provados no servidor; o roteiro manual entra junto com o lote (login de cada perfil, uma tentativa negativa por perfil, consulta da seção 8 antes e depois).

---

## 8. Consultas para o responsável (só contagens)

```sql
SET time_zone = 'America/Sao_Paulo';

-- Perfis por equipe e situacao
SELECT equipe, access_role, ativo, COUNT(*) AS funcionarios
FROM funcionarios
GROUP BY equipe, access_role, ativo
ORDER BY equipe, access_role, ativo;

-- Admin fora da equipe Lideranca (viram gestor pela regra de transicao)
SELECT ativo, COUNT(*) FROM funcionarios
WHERE access_role = 'admin' AND equipe <> 'lideranca'
GROUP BY ativo;

-- Lideranca ativa sem ad_login (nao consegue logar pelo CI)
SELECT COUNT(*) FROM funcionarios
WHERE equipe = 'lideranca' AND ativo = 1 AND (ad_login IS NULL OR ad_login = '');

-- Usuarios locais por perfil (relevante para L4)
SELECT role, COUNT(*) FROM usuarios GROUP BY role;
```

No servidor, sem exibir valores: quantos dos `ad_login` da Liderança ativa estão em `AD_ADMIN_USERS` (conferência manual por quem administra o `.env`).

**Resultado (2026-10-02, informado pelo responsável):**

| Consulta | Resultado |
|---|---|
| Perfis | `lideranca`/`admin`: 3 ativos; `n2`/`admin`: 2 ativos e 1 inativo; nenhum perfil além de `tecnico` e `admin` |
| Allowlist | 3 logins, todos com cadastro: 2 dos 3 líderes e 1 `n2`/`admin` (o responsável) |
| `ad_login` | 30 cadastros, 30 distintos, nenhum vazio (o `UNIQUE` da 016 passa na checagem prévia) |
| `usuarios` | Vazia; `ENABLE_LOCAL_ADMIN=false` (o preflight da L4 não bloqueia) |
| `ENABLE_AD_AUTO_LINK` | Ausente do `.env` (padrão desligado) |

---

## 9. Decisões — aprovadas em 2026-10-02, como recomendadas

| # | Decisão | Recomendação | Por quê |
|---|---|---|---|
| **L1** | Admin só pela allowlist `AD_ADMIN_USERS`; `access_role = 'admin'` deixa de conceder administração | **Aprovar** | Fecha o SEC-04: uma fonte de verdade, que não se autoexpande |
| **L2** | Liderança pode criar ou promover a **gestor** (e trocar `ad_login` de gestor) | **Aprovar, com `CRITICAL`** | O pedido só proíbe Liderança e admin; recusar obrigaria o admin a criar todo gestor |
| **L3** | `access_role = 'admin'` deixa de ser gravável (400 para todos); valores existentes ficam como legado | **Aprovar** | Gravar um valor que não concede nada só confunde; o formulário perde a opção |
| **L4** | Admin local (`ENABLE_LOCAL_ADMIN`, tabela `usuarios`) | **Manter como acesso de emergência**: continua admin, login passa a auditar `CRITICAL`, e o preflight dá `FALHA` com `ENABLE_LOCAL_ADMIN=true` em produção | É a única saída se o AD cair; a exceção à fonte única fica explícita e cara de usar |
| **L5** | Liderança pode forçar o fim de pausa (`admin_force_end_break.php`) | **Aprovar** | É operação de pausa; os líderes já fazem hoje como admin |
| **L6** | Liderança pode lançar ausência retroativa e regra de escala retroativa (3.8 da Parte B: hoje só admin) | **Aprovar** | Faz parte de "gerenciar escalas e ausências"; continua fora do gestor |
| **L7** | `AUTH_SOURCE` (SEC-10) | **Remover**: constante, campo `auth_source` de `configuracoes.php`, linha dos `.env.*.example`; o preflight avisa se ainda estiver no `.env`. A aba Segurança passa a mostrar "Autenticação AD/LDAP" como Parcial só quando `ENABLE_LOCAL_ADMIN` estiver ativo | Implementar de fato duplicaria o `ENABLE_LOCAL_ADMIN`. A alternativa (`AUTH_SOURCE=ad\|ad_local` mapeando para ele) cria duas chaves para o mesmo controle |
| **L8** | Migration 016 antes do código e normalização `016b` depois (seção 5) | **Aprovar** | Evita janela sem acesso para os líderes |

**Relação com a P12** (escala para a equipe Liderança, **em aberto**, bloqueia o F1). Com a equipe separada do perfil, a P12 vira decisão só de escala, sem efeito em permissão. Duas consequências: (1) se aprovada, um líder com escala aparece no Mapa PA e na Visão Operacional como qualquer colaborador, e a Liderança pode gerenciar a escala dos colegas de equipe, mas não a própria regra nem a de outro líder (**L9, aprovada**: a regra de 2.3 vale também para escalas); (2) se recusada, nada neste desenho muda. Nada de escala da Liderança é implementado antes da P12; a L9 entra no 5c.

### 9.1 Acréscimos aprovados em 2026-10-02 (requisitos)

**A1 — Revalidação por requisição (zero trust).** A cada requisição autenticada, o perfil é recalculado a partir das fontes atuais:

| Sessão | Fonte | Custo por requisição |
|---|---|---|
| CI (com ou sem sessão de gestão elevada) | `SELECT ativo, access_role, equipe, ad_login FROM funcionarios WHERE id = ?` (chave primária) + `AD_ADMIN_USERS` atual | 1 consulta por PK |
| Gestão por AD sem CI (`login_admin.php`, `admin_login.php`, `login.php`) | `AD_ADMIN_USERS` atual | Nenhuma consulta |
| Gestão local (`ENABLE_LOCAL_ADMIN`) | `usuarios` por `username` (único) + `ENABLE_LOCAL_ADMIN` atual | 1 consulta por chave única |

- Perfil recalculado **igual ou maior** que o da sessão: segue. Não há elevação no meio da sessão: quem foi promovido recebe o perfil novo no próximo login.
- **Menor**, cadastro inativo ou inexistente, `ad_login` trocado, fora da allowlist, usuário local removido ou `ENABLE_LOCAL_ADMIN` desligado: a sessão é **encerrada** na mesma requisição, que responde como sem sessão (401), com `SESSION_REVOKED` (WARNING): perfil anterior, perfil atual e o motivo. Encerrar em vez de rebaixar no lugar: as chaves da sessão CI e da sessão de gestão são separadas, e reescrever as duas abre espaço para combinação inconsistente; o novo login monta a sessão certa.
- Banco indisponível na revalidação: **503**, sem conceder nada e sem destruir a sessão (falha fechada; o usuário volta quando o banco voltar).
- Executa uma vez por requisição, nas portas por onde toda autorização passa: `ci_session_is_current()`, `usuario_pode_acessar_metricas()`, `verificar_login_api()` e `verificar_admin_login()`.
- **P11** (checagem de funcionário ativo em `require_portal_auth`, aprovada no desenho da Parte B): coberta pelo A1, que é mais amplo. Não precisa de implementação separada no F3.
- Custo: o polling atual (15 s por usuário) soma uma consulta por chave primária por requisição, ao lado do `init.php`, que já carrega todos os funcionários. Desprezível no volume de hoje (30 cadastros); não medido.

**A2 — Allowlist normalizada como o bind.** `AD_ADMIN_USERS` e o login são comparados por `ad_login_key()`, que usa a mesma `normalizar_login_ldap()` do bind: minúsculas, `@dominio` só com sufixo permitido. Entradas da allowlist no formato `DOMINIO\usuario` têm o prefixo retirado antes. Observação, sem alteração: o **bind** não aceita `DOMINIO\usuario` digitado no login (a normalização recusa a barra invertida); quem digitar assim não autentica. O preflight dá `FALHA` se `AD_ADMIN_USERS` estiver ausente, vazio ou só com separadores, em **qualquer** `APP_ENV` (antes, só fora de `development`).

**A3 — `ad_login` único e auditado.**
- 016: checagem de duplicados e `UNIQUE` (seção 5).
- Troca de `ad_login` em **qualquer** cadastro gera auditoria: `CRITICAL` se o perfil (atual ou novo) for `gestor` ou `lideranca`, `WARNING` nos demais (5b).
- O código grava `NULL`, nunca string vazia: `validate_ad_login()` já devolve `NULL` para vazio nos dois endpoints; o 5b acrescenta teste estrutural sobre todos os caminhos de escrita (`adicionar`, `atualizar`, vínculo automático do AD, script de migração do JSON).
- 5a: os endpoints de funcionário conferem o login em **todos** os cadastros, ativos e inativos (antes só ativos), e respondem 400 com mensagem, em vez de deixar o `UNIQUE` estourar em 500.

**A4 — Quem registra ausência e exceção de escala de um líder.** **Só admin.** A Liderança não registra para si nem para outro líder (L9, pelo mesmo motivo da autoaprovação); o gestor também não, porque está abaixo. Técnico e somente leitura nunca registram (3.8 da Parte B). Com a P12 recusada, líder não tem escala e a exceção de escala não se aplica; a ausência (F3) continua, registrada por admin. Com os dados de hoje, os 2 líderes que são admin pela allowlist registram para o terceiro; o registro de um admin para si mesmo é permitido (ausência não passa por aprovação) e fica auditado como qualquer outro.

**A5 — Procedimento de emergência da L4.** Documentado em `DEPLOY_LINUX.md`, seção "Acesso de Emergencia (admin local)": registrar, criar o usuário local com senha forte fora do repositório (lida sem eco), ligar `ENABLE_LOCAL_ADMIN`, recarregar, usar, desligar, remover o usuário, conferir e registrar o encerramento.

---

## 10. Plano de implementação

| Lote | Conteúdo | Rollback |
|---|---|---|
| **5a** (implementado) | Migration 016 e `016_down` (checagem de duplicados, enum, `UNIQUE`); permissões novas em `portal_permissions_for_role`; resolução do perfil (2.1) em função pura; logins CI e admin pela allowlist normalizada (A2), com `ADMIN_ROLE_SEM_ALLOWLIST`; `current_portal_role` aceita `lideranca`; revalidação por requisição (A1); preflight (A2); login AD único em ativos e inativos nos endpoints de funcionário (A3); **paridade mínima**: onde hoje admin e gestor passam, a Liderança também passa (`portal_role_is_manager()` no servidor, `isManagerRole()` no frontend, notificações de aprovação); QA 6, 7 e 11, A1 e A2 | Código + `016_down` |
| 5b | Endpoints de funcionário com a função de 2.3; L3; promoção com `CRITICAL`; auditoria de troca de `ad_login` e teste estrutural do `NULL` (A3); `016b` e `016b_down`; formulário e abas do frontend; QA 2 a 5, 9, 12 a 14 | `016b_down` + código |
| 5c | Escalas (remover regra, anexos), Mapa PA, forçar fim de pausa (L5), retroativos (L6), L9 (só depois da P12), `AUTH_SOURCE` (L7), admin local (L4); QA 1, 8, 10 | Código |

**Por que a paridade mínima entra no 5a:** a partir do 5a, a sessão do líder fora da allowlist passa a ser `lideranca`. Sem a paridade, as listas `['admin', 'gestor']` espalhadas pelos endpoints a deixariam abaixo de gestor. Com ela, o 5a sozinho deixa esse líder com os direitos de gestor; os direitos próprios da Liderança (funcionários, Mapa PA, remover regra, anexos, forçar fim de pausa) chegam no 5b e no 5c. **Recomendado:** levar 5a, 5b e 5c no mesmo deploy.

**Roteiro de deploy do 5a** (além da rotina de `DEPLOY_LINUX.md`):

1. **Antes do `git pull`:** confirmar que o login do responsável está em `AD_ADMIN_USERS` (hoje está). Sem isso, nenhum admin entra depois do deploy, e só resta o acesso de emergência.
2. Preflight da versão nova: `AD_ADMIN_USERS: OK`.
3. Migration 016 antes do `git pull` (como a 015). Se parar com `migration_016_abortada_ad_login_duplicado`, nada foi alterado: resolver os duplicados e repetir.
4. Conferir: `SHOW INDEX FROM funcionarios WHERE Key_name = 'uq_funcionarios_ad_login';` (1 linha) e `SHOW COLUMNS FROM funcionarios LIKE 'access_role';` (com `lideranca`).
5. Depois do deploy: login do responsável (admin), do líder fora da allowlist (`lideranca`, direitos de gestor no 5a) e do N2 fora da allowlist (`gestor`); conferir `ADMIN_ROLE_SEM_ALLOWLIST` no `audit_log` para os dois últimos.
6. Revalidação: com um usuário de teste, desativar o cadastro com a sessão aberta; a próxima requisição deve responder 401, com `SESSION_REVOKED` no `audit_log`. Reativar em seguida.

Cada sublote para no fim, com QA, mutação, registro e roteiro manual, como nos lotes anteriores. Documentação a atualizar na implementação: `API_CONTRACTS_FRONTEND.md`, `DOCUMENTACAO_TECNICA.md`, `DEPLOY_LINUX.md` (016 e 016b), `AUDITORIA_2026-09.md` (SEC-04, SEC-10).

## 11. Fora deste desenho

- LGPD-01 (lista de colaboradores no `config.php` e `INSERT` do `database_SECURED.sql`): fora, como pedido.
- `ENABLE_AD_AUTO_LINK` (vetor secundário do SEC-04): com L1, o vínculo automático deixa de poder conceder admin; ainda pode herdar `lideranca` ou `gestor` de um cadastro órfão. Confirmado ausente do `.env` do servidor em 2026-10-02 (padrão desligado); manter assim.
- Eventos de falha de login anteriores ao Lote 7 que gravavam o login digitado: tratados no Lote 7b (`cc1bfab`).
- `portal_modules.minimum_role` (`ENUM` da migration 001): sem consumidor no código; não precisa de `lideranca`.
