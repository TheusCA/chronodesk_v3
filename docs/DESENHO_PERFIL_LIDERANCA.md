# Perfil de Liderança — desenho do Lote 5 (SEC-04, SEC-10)

**Data:** 2026-10-02
**Situação:** **somente desenho, aguardando aprovação.** Nenhum código, migration ou teste foi escrito. Altera RBAC e autenticação: cada decisão da seção 9 precisa de aprovação explícita.
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
| Trocar `ad_login` de cadastro `gestor` | ✔ (`CRITICAL`) | ✔ (`CRITICAL`) se L2 aprovada; senão 403 |
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

Quem dos três precisar continuar admin entra em `AD_ADMIN_USERS`; a partir daí é admin por esse caminho, inclusive pelo login CI. **TO CONFIRM com o responsável:** quais dos três já estão na allowlist. A consulta da seção 8 traz só contagens; o cruzamento com a allowlist é feito no servidor, por quem tem acesso ao `.env`.

Comunicar antes do deploy: as abas que somem e o caminho para pedir uma configuração ao admin.

---

## 5. Migração

**Migration 016** (011 a 014 reservadas para a Parte B; 015 é o Lote 7). Idempotente, começa com `SET time_zone = 'America/Sao_Paulo';`, sem `NOW()`:

1. Amplia o `ENUM` de `funcionarios.access_role` para `('tecnico', 'gestor', 'lideranca', 'admin', 'somente_leitura')`, só se `'lideranca'` não estiver no `COLUMN_TYPE` (`information_schema`). Ampliar o enum não altera linhas, e o código antigo não grava esse valor: pode ser aplicada **antes** do `git pull`, como a 015.

**Normalização dos dados, depois do código novo no ar** (script separado, `016b`, idempotente):

```sql
UPDATE funcionarios SET access_role = 'lideranca'
WHERE equipe = 'lideranca' AND access_role = 'admin';
```

Por que em duas etapas: o código antigo lê `access_role` direto do banco no login CI (`validate_access_role` recusa `'lideranca'` e cai em `tecnico`). Se os dados mudassem antes do código, os líderes perderiam o acesso durante a janela. O código novo trata o `admin` legado pela regra de transição (2.1), então a ordem 016 → código → 016b não tem janela sem acesso.

O `016b` registra no `audit_log` um evento `LIDERANCA_PERFIL_MIGRADO` (`CRITICAL`) com a contagem de linhas alteradas (data pelo `DEFAULT` da coluna, permitido pela regra do A4).

Cadastros com `access_role = 'admin'` fora da equipe Liderança, se existirem, **não** são alterados pelo `016b`: viram `gestor` na sessão, pela regra de transição, e aparecem na consulta da seção 8 para decisão caso a caso.

## 6. Rollback

Ordem inversa, cada passo reversível:

1. **Dados** (`016b_down`), com o código novo ainda no ar: `lideranca` com `equipe = 'lideranca'` volta a `admin` (o código antigo faria isso de qualquer forma pela equipe); `lideranca` fora da equipe Liderança vai para `gestor`, **não** para `admin`, para o rollback não conceder administração a quem nunca a teve. Registra `LIDERANCA_PERFIL_REVERTIDO` (`CRITICAL`).
2. **Código:** o rollback normal de `DEPLOY_LINUX.md`.
3. **Enum** (`016_down`, opcional): só se nenhuma linha usar `'lideranca'`; o script confere e para se houver.

Sem os passos 1 e 3, o código antigo funciona com o enum ampliado.

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

---

## 9. Decisões pedidas

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

**Relação com a P12** (escala para a equipe Liderança, em aberto, bloqueia o F1). Com a equipe separada do perfil, a P12 vira decisão só de escala, sem efeito em permissão. Duas consequências: (1) se aprovada, um líder com escala aparece no Mapa PA e na Visão Operacional como qualquer colaborador, e a Liderança pode gerenciar a escala dos colegas de equipe, mas não a própria regra nem a de outro líder se a regra de 2.3 for estendida às escalas (**decisão L9**, recomendado: estender, pelo mesmo motivo da autoaprovação); (2) se recusada, nada neste desenho muda. A ordem sugerida é decidir L1 a L9 e a P12 juntas, porque o F1 e este lote mexem nos mesmos cadastros.

---

## 10. Plano de implementação (depois da aprovação)

| Lote | Conteúdo | Rollback |
|---|---|---|
| 5a | Migration 016 e rollback; `portal_permissions_for_role` com as permissões novas; resolução do perfil (2.1) em função pura; `current_portal_role` aceita `lideranca`; logins CI e admin pela allowlist; QA 6, 7 e 11 | Código + `016_down` |
| 5b | Endpoints de funcionário com a função de 2.3; promoção com `CRITICAL`; `016b` e `016b_down`; formulário e abas do frontend; QA 2 a 5, 9, 12 a 14 | `016b_down` + código |
| 5c | Escalas, Mapa PA, anexos, forçar fim de pausa e notificações; `AUTH_SOURCE` (L7); admin local (L4); QA 1, 8, 10 | Código |

Cada sublote para no fim, com QA, mutação, registro e roteiro manual, como nos lotes anteriores. Documentação a atualizar na implementação: `API_CONTRACTS_FRONTEND.md`, `DOCUMENTACAO_TECNICA.md`, `DEPLOY_LINUX.md` (016 e 016b), `AUDITORIA_2026-09.md` (SEC-04, SEC-10).

## 11. Fora deste desenho

- LGPD-01 (lista de colaboradores no `config.php` e `INSERT` do `database_SECURED.sql`): fora, como pedido.
- `ENABLE_AD_AUTO_LINK` (vetor secundário do SEC-04): com L1, o vínculo automático deixa de poder conceder admin; ainda pode herdar `lideranca` ou `gestor` de um cadastro órfão. Recomendado manter desligado (padrão atual) e confirmar no `.env` do servidor.
- Eventos de falha de login anteriores ao Lote 7 que gravam o login digitado (`ADMIN_LOGIN_FAILURE`, `CI_LOGIN_FAILURE`, `CI_AD_LOGIN_FAILURE`, `CI_LOGIN_RATE_LIMIT`): pendência registrada no Lote 7, não tratada aqui.
- `portal_modules.minimum_role` (`ENUM` da migration 001): sem consumidor no código; não precisa de `lideranca`.
