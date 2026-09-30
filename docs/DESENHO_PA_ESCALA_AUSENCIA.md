# Desenho — Mapa PA, Escalas, Ausências, Técnicos, Calendário e Visão Operacional

**Fase:** A6 — Fase de Desenho da Parte B (`REQUISITOS_PA_ESCALA_AUSENCIA.md`, seção 9)
**Data:** 2026-09-30
**Base analisada:** `beff99d` no branch `feat/employee-form-rhf-zod` (= `c049868` + registro do A5)
**Situação:** **aprovado em 2026-09-30, com três pontos em aberto** (P2, P12 e o tratamento dos relatórios) — ver seção 0.5. Nenhum código, migration ou teste da Parte B foi escrito. A implementação começa pelo F1, depois da validação e do deploy do lote de fuso (A4).

Convenções: **Fato** = lido no código, com `arquivo:linha`. **TO CONFIRM** = depende de dado do servidor, que a IA não acessa. As consultas para confirmar estão na seção 2.8 e só devolvem contagens, nunca nomes.

---

## 0. Resumo para decisão

### 0.1 O que o levantamento mudou em relação ao requisito

1. **Hoje existem quatro cálculos de presença independentes**, e nenhum enxerga os outros (seção 1.6). O Mapa PA, em particular, exibe uma **cópia da regra de escala tirada no dia em que o vínculo foi salvo**, não a regra vigente. O motor único não é só organização: corrige divergência real entre telas.
2. **`portal_absences` não tem caminho de escrita.** Nenhum endpoint insere nela; a tela `/ausencias` é uma tabela genérica somente leitura. O que a operação usa hoje como ausência são as **exceções de escala** (`day_off`, `absence`, `vacation`, `leave`), lançadas pela tela de Escala presencial. São duas estruturas paralelas que o desenho unifica.
3. **O vazamento SEC-07 também existe no calendário.** `calendar.php` devolve o motivo (`reason`) das ausências de todos os colaboradores a qualquer perfil autenticado. A correção precisa cobrir os dois endpoints.
4. **Arquivamento já existe** como `funcionarios.ativo = 0`, só que sem os efeitos do RF-07: não tira o técnico do PA, não encerra escala nem ausência, não derruba a sessão aberta e não finaliza a pausa em andamento.
5. **Expediente global não existe.** O que existe é jornada **por técnico** (`funcionarios.jornada_entrada/saida`), já usada na tela de Pausas. Por isso a D9 tem contraproposta.
6. **Três comportamentos atuais mudam com o motor** e afetam números de relatório: `undefined` hoje conta como remoto, fim de semana hoje gera dia presencial/remoto, e o técnico com ausência aprovada hoje simplesmente some da escala do dia em vez de aparecer como ausente.

### 0.2 Decisões D1 a D10 (detalhe na seção 7)

| Decisão | Posição |
|---|---|
| D1 ciclo só em dias úteis | Confirmo |
| D2 ausência e feriado não deslocam o ciclo | Confirmo |
| D3 retroativo só Admin, auditado | Confirmo, estendido a editar e cancelar ausência passada |
| D4 categorias sem dado de saúde | Confirmo, com migração do legado `atestado` (P8) |
| D5 permissões de ausência | Confirmo, com uma precisão sobre o que o CI vê dos colegas (P9) |
| D6 conflito = contorno, ausente = preenchimento | Confirmo |
| D7 arquivamento lógico | Confirmo, reaproveitando `ativo = 0` em vez de criar novo estado |
| D8 "Fora do expediente" em cinza | Confirmo |
| D9 expediente global configurável | **Contraproposta:** usar a jornada por técnico que já existe (P1) |
| D10 Liderança separada | Confirmo, mas exige habilitar escala para Liderança (P12) |

### 0.3 Decisões novas que o levantamento exigiu (detalhe na seção 8)

P1 a P16. As que bloqueiam o primeiro lote (F1) são **P10** e **P12**. As demais bloqueiam só o lote em que aparecem. O resultado de cada uma está na seção 0.5.

### 0.4 Pré-requisitos do requisito (seção 0) — situação

| Item | Situação |
|---|---|
| 0.1 Lotes 1, 2, 2B e 3 | Aprovados; deploy pendente |
| 0.1 Lote de fuso (BIZ-02 / A4) | Desbloqueado em 2026-09-30 pelas consultas no servidor e **implementado em commit local (`9957496`), aprovado em 2026-09-30, aguardando deploy** (`docs/FUSO_HORARIO_BIZ02.md`). **A implementação da Parte B não começa antes disso.** O desenho reduz a dependência: todo código novo usa a hora do PHP (`America/Sao_Paulo`, `config.php:53`) e passa datas como parâmetro, sem `NOW()`/`CURRENT_DATE` no SQL |
| 0.2 Formulário de funcionário (A5) | Aprovado (`c049868`); deploy é o terceiro da fila. F4 altera `AdminPage.jsx` e `formSchemas.ts` a partir dele |
| 0.3 Corrigir escopo de `absences` antes de ampliar | Primeiro passo de F3. Altera RBAC; **aprovado em 2026-09-30** (seção 3.3) |
| 0.4 Histórico de escala (BIZ-03) | F1 |

### 0.5 Decisões do responsável (2026-09-30)

| Item | Decisão | Consequência |
|---|---|---|
| D1 a D10 | **Aprovadas**, a D9 na forma da contraproposta P1 | — |
| P1 (expediente = jornada do técnico) | **Aprovada** | Confirmado no servidor que há jornadas diferentes, inclusive 15:30–23:59. Sem configuração global |
| P3, P4 | **Aprovadas** para o motor | O efeito nos **relatórios** fica em aberto (linha abaixo) |
| P5, P6, P7 | **Aprovadas** | — |
| P8 (legado `atestado`) | **Sem efeito** | `portal_absences` está vazia: 0 linhas, 0 órfãs. Não há legado a mapear nem texto a apagar, e a **FK entra** na migration 012. Revalidar Q3 antes do F3; continuando vazia, proponho em F3 remover a coluna `absence_type` em vez de mantê-la |
| P9, P10, P11 | **Aprovadas** | P11 altera autenticação: aprovação explícita registrada |
| P13 a P16 | **Aprovadas** | — |
| Leitura de ausências (SEC-07 e calendário) | **Aprovada** | Primeiro passo de F3 |
| Q1 | Estrutura confirmada com os nomes do repositório | A migration 011 pode usar os nomes de índice e de FK como estão |
| Q4 | Exceções de escala: 0 linhas | **Revalidar antes do F3.** Continuando vazia, a migration 013 não tem o que copiar e pode ser dispensada; a restrição de tipos no validador (P7) vale de qualquer forma |
| **P2** (almoço) | **Em aberto** | O motor pode expor `em_almoco` sem depender disso. A contagem de "disponíveis" (F7) e a criação ou não de um estado de almoço dependem da decisão: **F2 não fecha a especificação de situação sem ela** |
| **P12** (Liderança com escala) | **Em aberto** | F1 segue **sem** o passo 2.6. Se aprovada depois, vira migration própria. Até lá, Liderança aparece no Mapa PA como "Sem escala" |
| **Tratamento dos relatórios** (seção 6) | **Em aberto** | Falta decidir se os números de períodos já fechados podem mudar ou se as regras novas valem só a partir da data de corte. **Bloqueia a troca do relatório operacional para o motor** em F2; o restante de F2 não depende disso |

---

## 1. Levantamento do que já existe

### 1.1 Escalas

| Aspecto | Evidência | Observação |
|---|---|---|
| Tabela `portal_schedule_rules` | `migrations/20260613_003_operational_modules.sql:4-21`; `rule_config` em `20260624_010_schedule_fixed_weekdays.sql:27-42` | `team ENUM('n1','n2')`; `rule_config TEXT` |
| Uma regra por colaborador | `003:17` (`UNIQUE KEY uq_schedule_rule_employee (employee_id)`) | BIZ-03. A UNIQUE também é o índice que sustenta a FK `fk_schedule_rule_employee` (`003:19-20`) |
| Tipos de regra | `services/OperationalService.php:10-12` | A mesma lista está repetida em `services/PaMapService.php:12-14`, `frontend/src/lib/formSchemas.ts:75-82`, `frontend/src/lib/operational.ts:25-31` e `frontend/src/pages/OperationalPages.jsx:33-40`. Um tipo novo exige alterar os cinco |
| Cálculo | `OperationalService.php:74-85` (`presenceForRule`) | `undefined` devolve `remote` (linha 79) |
| Salvar | `OperationalService.php:320-337` | *Upsert* com `ON DUPLICATE KEY UPDATE`, que zera `effective_until`. Sobrescreve a regra anterior |
| Remover | `OperationalService.php:365-395` | Grava `rule_type = "undefined"` e `rule_config = NULL` (linhas 374-375): **apaga o registro de qual era a regra**. Usa `CURRENT_DATE` do MySQL (linha 376, BIZ-02) |
| Leitura "da última regra" | `OperationalService.php:1452-1456`, `1458-1469` | Assumem uma linha por colaborador |
| Geração por período | `OperationalService.php:1157-1214` | Já filtra por vigência (linhas 1178-1183), então tolera várias linhas. Não filtra fim de semana. Pula o dia com ausência aprovada (linhas 1185-1187) |
| Lista de regras | `OperationalService.php:1124-1143` | `JOIN funcionarios ... f.ativo = 1` (linha 1129) e só N1/N2 (linha 1130): inativo some da escala histórica; Liderança nunca entra |
| Exceções por data | `003:23-41`; `OperationalService.php:401-440`; tipos na linha 405; mapeamento em `1216-1224` | Uma por colaborador e data. Inclui tipos de ausência |
| Importação | `OperationalService.php:555-570`; `1112-1122` | Usa a data de hoje do PHP; não conhece `fixed_weekdays` |
| Permissões | `api/portal/schedules.php:6`, `19-21`, `51-55` | Leitura: qualquer autenticado. Escrita: admin e gestor. Remover regra: só admin |
| Tela | `OperationalPages.jsx:224` (`SchedulePage`), rota em `App.jsx:180-181` | `fixed_weekdays` só aceita marcar **dias presenciais** |
| Legado | `portal_schedules` (`20260612_001_portal_foundation.sql:107-122`), lido por `services/PortalService.php:61-86` | Sem caminho de escrita. Serve `?type=` e `standby.php` |

### 1.2 Ausências

| Aspecto | Evidência | Observação |
|---|---|---|
| Tabela `portal_absences` | `001:124-138` | Sem FK para `funcionarios`, sem equipe, sem horário. `absence_type` inclui **`atestado`**. `status` = `pending/approved/rejected` |
| Leitura | `api/portal/absences.php:3` → `api/portal/_bootstrap.php:18-33` → `PortalService.php:40-59` | Últimas 100 linhas, de todos, para qualquer perfil, com `reason` (**SEC-07**) |
| Escrita | — | **Não existe.** As únicas referências à tabela são `SELECT` (`OperationalService.php:175`, `1228`; `PortalService.php:19`). Só entra dado por SQL direto. **TO CONFIRM** se há linhas em produção (consulta Q3) |
| Calendário | `OperationalService.php:170-191`; `api/portal/calendar.php:5`, `9-11` | Devolve ausência de qualquer `status`, com `reason AS description`, a qualquer autenticado. **Mesmo vazamento do SEC-07 por outro endpoint** |
| Efeito na escala | `OperationalService.php:1226-1262`, `1185-1187` | Só `approved`. O colaborador é **omitido** do dia, não marcado como ausente |
| Ausência via exceção | `OperationalService.php:405`, `1221` | `day_off`, `absence`, `vacation`, `leave`. É o caminho que de fato tem tela. Sempre dia inteiro |
| Mapa PA | `PaMapService.php:391-406` | Considera só a exceção, e só para **bloquear a alocação**. Ignora `portal_absences` |
| Tela | `frontend/src/pages/ModulePage.jsx:12`; `frontend/src/lib/navigation.ts:36`, `60` | Tabela genérica somente leitura. O subtítulo cita "atestados" |

`portal_time_adjustments` tem um tipo `absence` (`OperationalService.php:644`), mas é correção de ponto, não ausência. Fica fora do motor.

### 1.3 Mapa PA

| Aspecto | Evidência | Observação |
|---|---|---|
| Inventário | `migrations/20260617_009_pa_map_recurring_assignments.sql:4-17`, carga em `73-95` | 16 PAs. Lista fixa de reserva em `PaMapService.php:8-11` e `PaMapPage.jsx:16-19` |
| Vínculos | `009:97-119`; `010:57-82` | Exclusão lógica (`active`, `deleted_at`). **Sem restrição de unicidade no banco** |
| Cópia da regra | `PaMapService.php:125-126` (grava), `286-300` (exibe a partir da cópia, linha 287) | **O mapa não reflete mudança de escala feita depois do vínculo** |
| Conflito | `PaMapService.php:302-352`, `354-367` | **Bloqueia** com 409. Compara só o *tipo* de regra: `fixed_weekdays` × `fixed_weekdays` sempre conflita, mesmo com dias disjuntos |
| Bloqueio por ausência | `PaMapService.php:103-105` | Exceção de ausência na data de início impede criar o vínculo recorrente |
| Confirmação de remoto | `PaMapService.php:106-108`; `PaMapPage.jsx:234` | O frontend decide pelo texto da mensagem (`error.message.includes('remoto')`) |
| Remover vínculo | `PaMapService.php:170-184`; `api/portal/pa_map.php:31-39` | Exclusão lógica; auditoria `INFO` só com o ID do vínculo |
| Permissão | `api/portal/pa_map.php:13`, `17-23` | Escrita **só admin**. O cartão da tela diz "Admin/Gestor pode editar" (`PaMapPage.jsx:278`) |
| Inativos | `PaMapService.php:216-243` | Não cruza com `funcionarios`: **técnico desativado continua no mapa** |
| Desempenho | `PaMapService.php:50-84`, `390-467`, `624-632` | Por colaborador: 2 consultas a `information_schema` + 2 consultas. N+1 em toda abertura do mapa |
| Data padrão | `PaMapPage.jsx:215` | `new Date().toISOString()` é UTC: **das 21:00 às 00:00 o mapa abre no dia seguinte**. As outras telas usam `localDate()` (`operational.ts:124-127`) |
| Legado por data | `migrations/20260617_008_dashboard_ci_pa_map.sql:24-63`; `PaMapService.php:245-284` | Somente leitura. **TO CONFIRM** se há linhas (Q5) |

`docs/DOCUMENTACAO_TECNICA.md` (7.4) afirma unicidade garantida no banco. Isso vale só para a tabela legada `portal_pa_map`; nos vínculos atuais a checagem é da aplicação.

### 1.4 Arquivamento de técnicos

| Aspecto | Evidência | Observação |
|---|---|---|
| Estado | `database_SECURED.sql:25` (`ativo`), `26-37` (`ad_login_ativo` com UNIQUE) | Exclusão lógica já existe |
| Desativar | `api/remover_funcionario.php:6`, `32`, `39`, `42` | Só admin; `UPDATE ... ativo = 0`; auditoria `CRITICAL`. **Nenhum efeito em PA, escala, ausência ou pausa** |
| Reativar / desativar pelo formulário | `api/atualizar_funcionario.php:32`, `110`, `137` | O campo `ativo` do formulário faz a mesma troca com auditoria comum, sem evento próprio |
| Tela | `AdminPage.jsx:351-352`, `500-503`, `540`, `567` | Botão "Desativar" com `window.confirm`; checkbox "Funcionário ativo"; filtro ativos/inativos |
| Login novo bloqueado | `auth_ldap.php:286-291`; `api/login_ci.php:36-39` | Fato |
| Sessão aberta **não** é derrubada | `security.php:575-590`, `609-632` | `require_portal_auth` não consulta `ativo`. A sessão CI vale até 8 h (`security.php:544-545`); a elevada, até 4 h (`config.php:366-371`). Só as ações de pausa checam (`auth_ldap.php:475-483`) |
| Admin por allowlist | `docs/DOCUMENTACAO_TECNICA.md`, seção 8; SEC-04 | `AD_ADMIN_USERS` independe de `funcionarios`: arquivar não bloqueia esse caminho |
| Listas | `api/listar_funcionarios.php:14-21`; `api/portal/technicians.php:11-16`; `OperationalService.php:1070` | Já escondem inativo |
| Contadores | `classes/GerenciadorPausas.php:355-393`; `api/portal/dashboard.php:11`, `36` | `obter_status()` não filtra inativo: **"Técnicos monitorados" conta desativados** |
| Histórico | Tabelas de hora extra, ponto e plantão guardam `employee_name`; FKs `ON DELETE RESTRICT` (`003:20,40,65,89,111`; `008:62`; `009:118`) | Exclusão física já é impossível pelo banco. Relatórios desses módulos continuam mostrando o inativo; o de escala não (seção 1.1) |
| Gravação do estado | `GerenciadorPausas.php:672-679`, `647-665` | Desativar regrava o `estado.json` inteiro a partir da memória, **fora** de `with_pause_state_lock`. O estado nem guarda `ativo` (linhas 650-657): a gravação é desnecessária e pode desfazer uma pausa iniciada no mesmo instante. Fato por leitura; não reproduzido |

### 1.5 Perfis

| Aspecto | Evidência |
|---|---|
| Perfis: `tecnico`, `gestor`, `admin`, `somente_leitura` | `migrations/20260613_005_documents_and_employee_roles.sql:15`; `security.php:592-607` |
| Resolução do perfil | `security.php:575-590` |
| Ator e colaborador vinculado | `api/portal/_bootstrap.php:35-44` (só técnico e somente leitura carregam `employee_id`); `OperationalService.php:1031-1048` |
| Escopo "só os próprios" já usado | `OperationalService.php:952-954`, `1050-1064` |
| Bloqueio de escrita para somente leitura | `api/portal/_bootstrap.php:46-54` |
| `lideranca` vira `admin` | `config.php:758-760`; `api/atualizar_funcionario.php:46-48` (SEC-04, em aberto) |
| Liderança fora do módulo de escala | `OperationalService.php:1074`, `1619-1628`; `003:9` |

No requisito, "CI" corresponde a `tecnico`. `somente_leitura` recebe o mesmo tratamento de leitura e nenhuma escrita.

### 1.6 Presença e disponibilidade hoje: quatro implementações

| # | Onde | Usa | Alimenta |
|---|---|---|---|
| 1 | `OperationalService::presenceForRule` e `generatedSchedule` (`74-85`, `1157-1214`) | Regra vigente, exceção, ausência aprovada | Calendário, Escala presencial, Relatórios |
| 2 | `PaMapService::scheduleForEmployee`, `ruleActiveOnDate`, `assignmentView` (`390-467`, `494-513`, `286-300`) | Regra vigente para a lista de elegíveis; **cópia** para o que está no mapa; exceção | Mapa PA |
| 3 | `Funcionario::status_disponibilidade` (`classes/Funcionario.php:103-125`) | Jornada e almoço do técnico | `status.php`, `technicians.php`, `listar_funcionarios.php` |
| 4 | `DashboardPage.jsx:30-43` | Conta no navegador a partir do item 3 | Visão Operacional |

Nenhuma considera pausa e ausência junto com escala. `plantonistas_ativos` e `sobreavisos_ativos` são zero fixo (`api/portal/dashboard.php:42-43`).

### 1.7 Expediente e polling

- **Expediente:** não há configuração global. `config.json` guarda só parâmetros de pausa (`config.php:448-455`). A jornada é por técnico, padrão 08:00–17:00 (`database_SECURED.sql:21-24`). A documentação cita "jornada visual 08:00–18:00" (`DOCUMENTACAO_TECNICA.md`, 7.2), sem correspondência no código.
- **Polling existente:** `status.php` a cada 15 s, global (`frontend/src/hooks/useLivePauses.js:7`, `37`; `App.jsx:56`); `portal/dashboard.php` a cada 15 s, só na Visão Operacional (`DashboardPage.jsx:26`); notificações a cada 60 s (`PortalLayout.jsx:41`); aprovações a cada 30 s (`AdminPage.jsx:814`). Calendário, Mapa PA e Escala não têm polling.

### 1.8 Achados novos do levantamento

| ID | Achado | Evidência | Tratamento |
|---|---|---|---|
| L-01 | Motivo de ausência exposto pelo calendário a qualquer perfil | `OperationalService.php:170-191` | F3, junto com SEC-07 |
| L-02 | Mapa PA exibe cópia da regra, não a vigente | `PaMapService.php:286-300` | F6 (motor) |
| L-03 | Mapa PA abre em data UTC | `PaMapPage.jsx:215` | F6 |
| L-04 | `undefined` conta como remoto em relatório | `OperationalService.php:79`, `826-827` | F2 (P4) |
| L-05 | Fim de semana gera dia presencial/remoto | `OperationalService.php:1175` | F2 (P3) |
| L-06 | Sessão de técnico desativado continua válida | `security.php:609-632` | F4 (P11) |
| L-07 | Desativado continua no mapa e nos contadores | `PaMapService.php:216-243`; `dashboard.php:36` | F4 |
| L-08 | Remover escala apaga qual era a regra | `OperationalService.php:374-375` | F1 |
| L-09 | Dois `fixed_weekdays` sempre conflitam | `PaMapService.php:354-367` | F6 |
| L-10 | N+1 com `information_schema` no mapa | `PaMapService.php:50-84`, `624-632` | F6 |
| L-11 | Gravação de `estado.json` fora do lock ao desativar/editar funcionário | `GerenciadorPausas.php:672-700` | F4 |
| L-12 | Categoria `atestado` no schema | `001:128` | F3 (P8) |
| L-13 | `CalendarPage` duplicada e sem uso | `OperationalPages.jsx:165` (a rota usa `pages/CalendarPage.jsx`, `App.jsx:18`) | F5 |
| L-14 | Texto corrompido "Carregando mÃ³dulo..." | `App.jsx:222` | Cosmético; corrigir em F5 |
| L-15 | `DEPLOY_LINUX.md` lista migrations só até a 007 | `DEPLOY_LINUX.md:139-146` | **TO CONFIRM** que 008 a 010 estão aplicadas (Q1); atualizar o documento em F1 |

---

## 2. Modelo de dados proposto

Princípios: estender o que existe; toda migration idempotente, no padrão de `20260624_010` (checagem em `information_schema` + `PREPARE`); cada uma com script de rollback em `migrations/rollback/`; aplicação manual pelo responsável. Os nomes finais dos arquivos levam a data da implementação. **Fuso (decisão de 2026-09-30):** toda migration e todo rollback começam com `SET time_zone = 'America/Sao_Paulo';` e não usam `NOW()` nem `CURRENT_TIMESTAMP` em `INSERT`/`UPDATE` de dados — data necessária vai como valor explícito (`docs/FUSO_HORARIO_BIZ02.md`, seção 10).

### 2.1 Histórico de escala (RF-03) — migration 011

```sql
-- 1. Índice que passa a sustentar a FK, ANTES de derrubar a UNIQUE atual
ALTER TABLE portal_schedule_rules
  ADD INDEX idx_schedule_rule_employee_period (employee_id, effective_from, effective_until);

-- 2. Derruba a restrição de uma regra por colaborador
ALTER TABLE portal_schedule_rules DROP INDEX uq_schedule_rule_employee;

-- 3. Invariantes que o banco consegue garantir
ALTER TABLE portal_schedule_rules
  ADD UNIQUE KEY uq_schedule_rule_employee_from (employee_id, effective_from),
  ADD COLUMN open_employee_id INT
      GENERATED ALWAYS AS (CASE WHEN effective_until IS NULL THEN employee_id ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_schedule_rule_open (open_employee_id);

-- 4. Novo tipo (RF-01)
ALTER TABLE portal_schedule_rules
  MODIFY COLUMN rule_type ENUM('even_days','odd_days','always_remote','always_onsite',
                               'undefined','fixed_weekdays','cycle') NOT NULL DEFAULT 'undefined';
```

- **Não sobrepor vigências:** o MySQL 8.0 não tem restrição de exclusão. O banco garante "no máximo uma regra aberta por colaborador" (coluna gerada, mesmo padrão de `funcionarios.ad_login_ativo`) e "no máximo uma regra por data de início". A ausência de sobreposição entre linhas fechadas fica no serviço, dentro de transação, serializada por `SELECT id FROM funcionarios WHERE id = :id FOR UPDATE`, e é conferida por uma consulta de verificação que entra no `qa` e no pós-deploy.
- **Dados atuais:** nenhuma linha é alterada. A regra aberta continua aberta. As linhas de escala removida (`rule_type = 'undefined'` com `effective_until` preenchido) ficam como estão: representam um período em que a regra original foi perdida (L-08) e o motor as lê como `sem_escala`.
- **Data de corte:** o histórico passa a ser confiável a partir da data de aplicação da migration. Antes dela, o relatório mostra a regra que estava gravada no corte. Não proponho reconstruir o passado a partir do `audit_log`: os dias de `fixed_weekdays` não foram registrados lá.
- **Compatibilidade:** o código atual funciona sobre o schema novo enquanto não existir linha `cycle`, com uma diferença inofensiva: recadastrar a escala de quem teve a regra removida passa a criar uma linha nova em vez de reaproveitar a antiga. Isso permite aplicar a migration antes do código. Análise por leitura; a confirmar na primeira execução no servidor.
- **Rollback (script):** copia as linhas para `portal_schedule_rules_bkp_011`, mantém por colaborador só a linha aberta (ou a de maior `effective_from`), converte `cycle` em `undefined`, remove coluna gerada e índices novos e recria `uq_schedule_rule_employee`. **Atenção:** voltar o código sem rodar esse script, havendo linha `cycle`, quebra Calendário, Escala e Relatórios com erro 400, porque `presenceForRule` lança exceção para tipo desconhecido (`OperationalService.php:83`).

**Semântica no serviço:**

| Operação | Comportamento |
|---|---|
| Salvar regra com início `D` | Trava o colaborador. Se existe regra com `effective_from = D`: atualiza no lugar (correção no mesmo dia). Senão: fecha em `D − 1` a regra que cobre `D` e insere a nova. Se houver regra futura começando em `F > D`, a nova nasce com `effective_until = F − 1` |
| Salvar regra idêntica à vigente | Não cria linha (evita histórico falso em reimportação) |
| Remover escala | Fecha a regra vigente em `hoje − 1` (data do PHP), **sem** trocar `rule_type`. Regra que começaria hoje ou no futuro é apagada, com o conteúdo completo na auditoria, porque nunca vigorou |
| Início retroativo (`D < hoje`) | Reescreve histórico. Proposta P10: só Admin, com auditoria `WARNING` |
| Importação | Igual a "salvar", linha a linha, na mesma transação de hoje |

### 2.2 Configuração das regras (`rule_config`)

```json
// cycle (RF-01)
{ "pattern": ["remote","onsite","onsite"], "anchor_date": "2026-10-05", "business_days_only": true }

// fixed_weekdays (RF-02): "weekdays" continua sendo os dias PRESENCIAIS
{ "weekdays": ["mon","wed","fri"], "input_mode": "home" }
```

- `cycle`: `pattern` com 2 a 20 posições, contendo os dois valores. `anchor_date` precisa ser dia útil e o servidor força `effective_from = anchor_date`, para que "antes da âncora vale a regra anterior" seja consequência do histórico e não um caso à parte. `business_days_only` é gravado, mas nesta entrega só `true` é aceito (D1).
- `fixed_weekdays`: `input_mode` é opcional e só de exibição. As regras existentes não têm a chave e continuam lidas como `onsite`. `scheduleRuleConfigWeekdays` (`OperationalService.php:1318-1327`) lê só `weekdays`, então nada quebra.

### 2.3 Ausências (RF-04) — migration 012

```sql
ALTER TABLE portal_absences
  ADD COLUMN team ENUM('n1','n2','lideranca') NULL AFTER employee_name,
  ADD COLUMN category ENUM('folga','ferias','ausencia_justificada','compromisso','outro') NULL AFTER absence_type,
  ADD COLUMN period_type ENUM('full_day','from_time','interval') NOT NULL DEFAULT 'full_day' AFTER ends_on,
  ADD COLUMN start_time TIME NULL AFTER period_type,
  ADD COLUMN end_time TIME NULL AFTER start_time,
  ADD COLUMN retroactive TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN source ENUM('manual','exception_migration','legacy') NOT NULL DEFAULT 'legacy',
  ADD COLUMN legacy_exception_id BIGINT NULL,
  ADD COLUMN created_by VARCHAR(100) NULL,
  ADD COLUMN updated_by VARCHAR(100) NULL,
  ADD COLUMN cancelled_by VARCHAR(100) NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  MODIFY COLUMN absence_type ENUM('ferias','atestado','treinamento','folga','justificada') NULL,
  MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  ADD UNIQUE KEY uq_absence_legacy_exception (legacy_exception_id),
  ADD INDEX idx_absence_employee_range (employee_id, status, starts_on, ends_on);

-- Só se Q3 confirmar que não há employee_id órfão
ALTER TABLE portal_absences
  ADD CONSTRAINT fk_absence_employee FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT;
```

- **Estado:** ausência lançada por Gestor/Admin nasce `approved` (não há fluxo de aprovação no RF-04). Cancelar grava `cancelled`, `cancelled_by`, `cancelled_at`; o registro permanece. `pending` e `rejected` ficam no enum só para linhas legadas.
- **Observação:** reaproveita a coluna `reason` (500 caracteres). Na API o campo se chama `note`.
- **Legado:** `category` é preenchida a partir de `absence_type`: `ferias → ferias`, `folga → folga`, `justificada → ausencia_justificada`, `treinamento → compromisso`, `atestado → ausencia_justificada`. O que fazer com o valor `atestado` e com o texto de `reason` das linhas legadas é a decisão P8.
- **Rollback:** remove colunas, índice e FK novos e devolve os dois enums. Linhas `cancelled` voltam como `rejected`; as criadas pela tela nova são preservadas em `portal_absences_bkp_012`.

### 2.4 Exceções com tipo de ausência — migration 013 (P7)

Copia para `portal_absences` cada exceção `day_off`, `absence`, `vacation` ou `leave` como ausência de dia inteiro `approved`, com `source = 'exception_migration'` e `legacy_exception_id` (a UNIQUE torna a cópia idempotente). Mapeamento: `day_off → folga`, `vacation → ferias`, `absence` e `leave → ausencia_justificada`. Não copia se já houver ausência aprovada cobrindo o dia.

As linhas de exceção **não são apagadas** e o enum da tabela não muda. O motor passa a ignorar esses quatro tipos e o validador deixa de aceitá-los em lançamentos novos. Rollback: apagar as linhas com `source = 'exception_migration'` que não foram editadas depois (`updated_by IS NULL`); o código antigo volta a ler as exceções, que continuam lá.

### 2.5 Arquivamento (RF-07) — migration 014

```sql
ALTER TABLE funcionarios
  ADD COLUMN arquivado_em DATETIME NULL,
  ADD COLUMN arquivado_por VARCHAR(100) NULL;
```

`ativo = 0` continua sendo o estado de arquivado. As duas colunas registram quando e por quem, e permitem ao motor incluir o técnico nos relatórios de datas anteriores ao arquivamento. Inativos atuais ficam com `arquivado_em` nulo e são tratados como "arquivado em data desconhecida": aparecem no histórico de todo o período. Rollback: remover as duas colunas.

### 2.6 Liderança no módulo de escala — dentro da 011 (P12)

Se P12 for aprovada: `team ENUM('n1','n2','lideranca')` em `portal_schedule_rules` e `portal_schedule_exceptions`. Ampliar enum não altera linha existente. Se recusada, esse passo sai da migration.

### 2.7 Sem alteração de schema

- `portal_pa_assignments`: as colunas `schedule_rule_type` e `schedule_rule_config` deixam de ser **lidas**. Continuam gravadas (com `undefined` para `cycle`) para o código antigo funcionar num rollback. Conflito e ocupação são **calculados na leitura e nunca armazenados**: é isso que garante que remover um técnico "recalcula" o PA (RF-06) sem rotina extra.
- `portal_pa_inventory`, `portal_schedule_exceptions` (fora o enum de 2.6), `portal_pa_map`, `portal_schedules`: sem mudança.
- Expediente: sem tabela nem chave nova se P1 for aprovada. Se a D9 original for mantida, entram `expediente_inicio` e `expediente_fim` no `config.json`, pela mesma rotina de `api/salvar_configuracao.php`.

### 2.8 Consultas de verificação para rodar no servidor antes de F1

Só contagens. Rodar no banco `sistema_pausas`. `CURDATE()` aqui serve apenas para separar passado de futuro; um dia de diferença por fuso não muda a decisão.

```sql
-- Q1. Estrutura esperada (migrations 008 a 010 aplicadas; nomes de índice e FK)
SELECT 'col rule_config' item, COUNT(*) n FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_config'
UNION ALL SELECT 'tab pa_assignments', COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_assignments'
UNION ALL SELECT 'tab pa_inventory', COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_inventory'
UNION ALL SELECT 'idx uq_schedule_rule_employee', COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee'
UNION ALL SELECT 'fk fk_schedule_rule_employee', COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND CONSTRAINT_NAME = 'fk_schedule_rule_employee';

-- Q2. Regras de escala por tipo; "aberta = 0" com undefined são escalas removidas
SELECT rule_type, effective_until IS NULL AS aberta, COUNT(*) n
  FROM portal_schedule_rules GROUP BY rule_type, aberta;

-- Q3. Ausências: há dado? há atestado? há texto de motivo? há órfão?
SELECT absence_type, status, COUNT(*) n, SUM(reason IS NOT NULL AND reason <> '') com_motivo,
       MIN(starts_on) primeira, MAX(ends_on) ultima
  FROM portal_absences GROUP BY absence_type, status;
SELECT COUNT(*) orfas FROM portal_absences a LEFT JOIN funcionarios f ON f.id = a.employee_id WHERE f.id IS NULL;

-- Q4. Exceções de escala por tipo, e quantas são de hoje em diante
SELECT exception_type, COUNT(*) n, SUM(exception_date >= CURDATE()) futuras
  FROM portal_schedule_exceptions GROUP BY exception_type;

-- Q5. Mapa PA: vínculos de inativos, cópia de regra divergente, PAs com mais de um vínculo, legado
SELECT COUNT(*) vinculos_ativos, SUM(f.ativo = 0) de_inativos
  FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1;
SELECT COUNT(*) copia_divergente
  FROM portal_pa_assignments a
  LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_until IS NULL
  WHERE a.active = 1
    AND (a.schedule_rule_type <> COALESCE(r.rule_type, 'undefined')
         OR COALESCE(a.schedule_rule_config, '') <> COALESCE(r.rule_config, ''));
SELECT COUNT(*) pas_compartilhados FROM (
  SELECT pa_number FROM portal_pa_assignments WHERE active = 1 GROUP BY pa_number HAVING COUNT(*) > 1) t;
SELECT (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') pa_map_legado,
       (SELECT COUNT(*) FROM portal_schedules) schedules_legado;

-- Q6. Funcionários por equipe e situação; distribuição de jornadas (base da P1)
SELECT LOWER(equipe) equipe, ativo, COUNT(*) n FROM funcionarios GROUP BY LOWER(equipe), ativo;
SELECT jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, COUNT(*) n
  FROM funcionarios WHERE ativo = 1 GROUP BY 1, 2, 3, 4;
```

O que cada resposta decide: Q1 define se a 011 pode usar os nomes do repositório; Q3 define P8 e se a FK entra; Q4 dimensiona a 013; Q5 diz quantos cartões do mapa vão mudar de aparência no deploy de F6; Q6 sustenta P1 e P12.

---

## 3. Contratos de API

### 3.1 Convenções mantidas

- Envelope `{ "sucesso": bool, "mensagem": string, ... }`; escrita por `POST` com `action`, CSRF e JSON via `portal_json_input()` (`_bootstrap.php:56-98`).
- Códigos, conforme `portal_operational_error()` (`_bootstrap.php:100-118`): **400** validação, **401** sem sessão, **403** perfil, **405** método, **409** regra de domínio (inclusive "registro não encontrado", como já é hoje), **413** payload, **415** tipo de conteúdo, **500**, **503**.
- Todo endpoint novo nega por perfil **antes** de tocar no banco, para permitir teste negativo em subprocesso sem MySQL, como já se faz com `api/health.php`.
- Os campos do motor usam os nomes do requisito (`situacao`, `origem`, `detalhe`). Os campos antigos (`presence_type`, `active_on_date`) continuam sendo devolvidos, derivados do motor, até o frontend deixar de usá-los.

### 3.2 `GET portal/presence.php` (novo) — F2

| | |
|---|---|
| Parâmetros | `from`, `to` (`YYYY-MM-DD`; padrão hoje; máximo 93 dias), `team`, `employee_id` |
| Perfil | Qualquer autenticado. Para `tecnico` e `somente_leitura`, `category` e `note` só vêm nas próprias ausências (P9) |

```json
{
  "sucesso": true,
  "server_now": "2026-10-05T14:03:11-03:00",
  "today": "2026-10-05",
  "period": { "from": "2026-10-05", "to": "2026-10-09" },
  "employees": [{ "id": 12, "name": "…", "team": "n1" }],
  "days": [{
    "employee_id": 12, "date": "2026-10-05",
    "situacao": "ausente", "origem": "ausencia", "detalhe": "Ausente desde 14:00",
    "escala": { "situacao": "presencial", "fonte": "regra", "rule_type": "cycle", "rule_label": "Ciclo 2P/1R" },
    "ausencias": [{ "id": 31, "period_type": "from_time", "start_time": "14:00", "end_time": null, "ativa": true }],
    "em_almoco": false
  }],
  "meta": { "pausas_disponiveis": true }
}
```

Em `date = today`, `situacao` é a do instante `server_now`. Nas outras datas é a situação do dia (seção 4.3).

### 3.3 `portal/absences.php` — F3

**`GET`** (substitui a listagem genérica; **corrige SEC-07**)

| | |
|---|---|
| Parâmetros | `from`, `to` (padrão mês corrente; máximo 366 dias), `team`, `employee_id`, `status` (`approved` padrão, `cancelled`, `all`) |
| Perfil | `admin` e `gestor`: todos. `tecnico` e `somente_leitura`: **só as próprias**; `employee_id` é forçado para o da sessão, como em `OperationalService.php:952-954` |
| Resposta | `items[]` com `id, employee_id, employee_name, team, starts_on, ends_on, period_type, start_time, end_time, category, note, status, retroactive, created_by, created_at, updated_by, updated_at, cancelled_by, cancelled_at`; `can_manage`; `can_retroactive`; `categories[]` |

**`POST`**

| `action` | Payload | Perfil | Resposta |
|---|---|---|---|
| `create` | `employee_id, starts_on, ends_on, period_type, start_time?, end_time?, category, note?` | `admin`, `gestor` | 201 `{ id }` |
| `update` | `id` + os mesmos campos | `admin`, `gestor` | 200 |
| `cancel` | `id` | `admin`, `gestor` | 200 |

Validações (400, salvo indicação):

- `ends_on ≥ starts_on`; intervalo máximo de 366 dias.
- `period_type = from_time` exige `start_time`; `interval` exige `start_time < end_time`; horário só quando `starts_on = ends_on` (P5).
- Horário dentro do expediente do técnico (P1).
- `category` dentro da lista; `note` até 500 caracteres.
- Sem sobreposição com outra ausência `approved` do mesmo técnico: **409** com o período em conflito na mensagem (P6).
- Data inicial anterior a hoje, ou edição/cancelamento de ausência já iniciada: **403** para `gestor`; permitido a `admin`, gravando `retroactive = 1`.
- Técnico arquivado: 400.

Auditoria: `ABSENCE_CREATED` (`INFO`; `WARNING` se retroativa), `ABSENCE_UPDATED` e `ABSENCE_CANCELLED` (`WARNING`), `ABSENCE_WRITE_DENIED` (`WARNING`). O detalhe registra IDs, datas, período e categoria, **nunca o texto da observação**.

**Aprovação explícita pedida (altera RBAC):** restringir a leitura de `portal/absences.php` e do bloco de ausências de `portal/calendar.php` conforme a tabela acima.

### 3.4 `portal/schedules.php` — F1 e F2B

| Mudança | Detalhe |
|---|---|
| `GET` | Cada regra ganha `is_current`, `is_future` e `config`. Por padrão vêm as regras que vigoram no período e as futuras; `include_history=1` traz as fechadas. A tela deixa de inferir "ativa" por `effective_until` vazio, o que ficaria errado com regra futura agendada |
| `action=rule`, `rule_type=cycle` | `cycle: { onsite_days, remote_days, starts_with: "onsite"\|"remote", anchor_date }`. O servidor monta `pattern` |
| `action=rule`, `rule_type=fixed_weekdays` | `weekdays` (presenciais), **ou** `input_mode: "home"` com `home_weekdays`. O servidor converte: presenciais = seg–sex menos os dias de home. Todos os dias em home: 400, sugerindo "Sempre remoto" |
| `action=rule_preview` (novo) | Mesmo payload de `rule`. Não grava. Devolve `preview[]` dos próximos 10 dias úteis, `rule_label`, e o que será fechado (`closes`) |
| `action=rule` (resposta) | Acrescenta `closed_rule_id` e `closed_until` |
| `action=remove_rule` | Contrato igual; semântica da seção 2.1 |
| `action=exception` | Depois de F3, aceita só `remote`, `onsite`, `training`, `oncall`. Tipo de ausência: 400 apontando a tela de Ausências |
| Perfis | Sem mudança, exceto início retroativo só para `admin` (P10) |

### 3.5 `portal/pa_map.php` — F6

| Mudança | Detalhe |
|---|---|
| `GET` | Acrescenta `server_now`, `week: { from, to }` (segunda a sexta da data filtrada), `presence` em cada vínculo (do motor, para a data filtrada), `conflicts[]` (`{ pa_number, date, employees[] }`), `has_conflict` por PA e `absences_week[]` |
| `POST save` | **Deixa de devolver 409 por conflito de escala.** Salva e devolve `warnings[]` e `conflicts[]`. Continua 409 para "colaborador já vinculado a outro PA no período". A ausência na data de início vira aviso, não bloqueio |
| `POST remove` | Contrato igual. Passa a rodar em transação e a auditar PA e colaborador |
| Auditoria | `PA_MAP_CONFLICT_ACCEPTED` (`WARNING`) com PA, IDs dos técnicos e datas em conflito |
| Perfil | Escrita continua **só `admin`**. O texto "Admin/Gestor" da tela é corrigido |

### 3.6 Técnicos — F4

| Endpoint | Mudança |
|---|---|
| `remover_funcionario.php` (`POST { funcionario_id }`, só admin) | Contrato igual. Passa a executar o arquivamento completo (seção 5.2) e devolve `efeitos: { pa_removidos, regras_encerradas, ausencias_canceladas, pausa_finalizada }`. Auditoria `FUNCIONARIO_ARQUIVADO` (`CRITICAL`) |
| `restaurar_funcionario.php` (novo, `POST { funcionario_id }`, só admin) | `ativo = 1`, limpa `arquivado_em`. 409 se o login AD já pertence a outro ativo. Não devolve PA, escala nem ausências. Auditoria `FUNCIONARIO_RESTAURADO` (`CRITICAL`) |
| `atualizar_funcionario.php` | Contrato igual. A troca de `ativo` passa pelas mesmas rotinas, para o formulário não ser um atalho sem efeitos |
| `portal/technicians.php` (`GET`) | Acrescenta `presence` do motor. `status` continua, por compatibilidade |

Teste negativo: `gestor`, `tecnico` e `somente_leitura` recebem 403 em arquivar e restaurar.

### 3.7 `status.php` e `portal/dashboard.php` — F2 e F7

| Endpoint | Mudança |
|---|---|
| `status.php` | Cada item de `n1`/`n2` ganha `presenca: { situacao, origem, detalhe }` de hoje (P14). Depois de F4 deixa de incluir arquivados |
| `portal/dashboard.php` | Acrescenta `server_now` e `presence_summary` por equipe: `{ escalados, disponiveis, em_pausa, ausentes, fora_expediente, sem_escala }`. `tecnicos_monitorados` passa a contar só ativos |

### 3.8 Permissões por perfil

| Ação | admin | gestor | tecnico | somente_leitura |
|---|---|---|---|---|
| Ver presença (situação e período) | ✔ | ✔ | ✔ | ✔ |
| Ver categoria e observação de ausência alheia | ✔ | ✔ | ✘ | ✘ |
| Listar ausências | todas | todas | só as próprias | só as próprias |
| Lançar, editar, cancelar ausência | ✔ | ✔ | 403 | 403 |
| Ausência retroativa | ✔ | 403 | 403 | 403 |
| Salvar regra ou exceção de escala | ✔ | ✔ | 403 | 403 |
| Regra com início retroativo (P10) | ✔ | 403 | 403 | 403 |
| Remover regra de escala | ✔ | 403 | 403 | 403 |
| Alterar Mapa PA | ✔ | 403 | 403 | 403 |
| Arquivar ou restaurar técnico | ✔ | 403 | 403 | 403 |

### 3.9 `API_CONTRACTS_FRONTEND.md`

Recebeu uma seção "Contratos propostos — Parte B", marcada como **não implementada** e apontando para este documento. A tabela de contratos em uso não foi tocada; cada linha muda no lote que implementar o endpoint.

---

## 4. Motor de presença

### 4.1 Estrutura

| Peça | Responsabilidade |
|---|---|
| `PresenceEngine` (novo, `services/PresenceEngine.php`) | Funções **puras**: recebem dados já carregados e o instante; não consultam banco, arquivo nem relógio. É o que o `qa-presence-engine` testa |
| `PresenceRepository` (novo) | Carga em lote para um conjunto de colaboradores e um intervalo de datas |
| Consumidores | `generatedSchedule` (Calendário, Escala, Relatórios), `PaMapService`, `status.php`, `dashboard.php`, `technicians.php` passam a delegar. `presenceForRule` permanece como função interna do motor |

O relógio entra por parâmetro (`DateTimeImmutable` em `America/Sao_Paulo`). Nenhum consumidor chama `new DateTime()` para decidir presença, e nenhum SQL do motor usa `NOW()` ou `CURRENT_DATE`.

### 4.2 Escala do dia

Para colaborador e data, na ordem:

1. Sábado ou domingo → `dia_nao_util` (P3).
2. Exceção `remote`, `onsite`, `training` ou `oncall` na data → `remoto` ou `presencial`, fonte `excecao`.
3. Regra em vigor na data (`effective_from ≤ data` e `effective_until` vazio ou `≥ data`). Sem regra, ou `undefined` → `sem_escala` (P4).
4. Pelo tipo: `always_onsite`, `always_remote`, `even_days`/`odd_days` (dia do mês), `fixed_weekdays`, `cycle`.

**Ciclo:** `posição = dias_úteis_em[âncora, data) mod tamanho(pattern)`. A contagem é em forma fechada (semanas inteiras × 5 + resto), sem laço por dia. Conferi a fórmula contra a contagem dia a dia em 4.000 combinações, sem divergência. Para 1R/2P com âncora na segunda 05/10/2026:

```
05/10 R  06 P  07 P  08 R  09 P | 12 P  13 R  14 P  15 P  16 R | 19 P  20 P  21 R  22 P  23 P
```

Esse é o primeiro critério de aceite e vira teste automatizado.

### 4.3 Situação

**No instante `agora`, para a data de hoje** — precedência do requisito:

| Ordem | Condição | `situacao` | `origem` | `detalhe` (exemplos) |
|---|---|---|---|---|
| 1 | Ausência aprovada ativa em `agora` | `ausente` | `ausencia` | "Ausente (dia todo)", "Ausente desde 14:00", "Ausente 10:00–12:00" |
| 2 | Fora do expediente, ou dia não útil | `fora_expediente` | `expediente` | "Expediente 08:00–17:00", "Fim de semana" |
| 3 | Pausa em andamento | `pausa` | `pausa` | Motivo da pausa |
| 4 | Escala presencial ou remota | `presencial` / `remoto` | `regra_escala` | "Presencial (ciclo 2P/1R)"; com ausência parcial ainda não iniciada: "Ausente a partir de 14:00" |
| 5 | Sem regra | `sem_escala` | `regra_escala` | "Sem escala definida" |

Valores de `origem` na API: `ausencia`, `expediente`, `pausa`, `regra_escala` (os quatro do requisito, sem acento nem espaço).

Ausência ativa: `full_day` o dia todo; `from_time` de `start_time` até o fim do expediente; `interval` em `start_time ≤ agora < end_time`.

**Para qualquer outra data** (passada ou futura): `ausente` se houver ausência de dia inteiro; senão, a escala do dia, com as ausências parciais como marcadores em `ausencias[]`. Pausa e expediente não entram.

### 4.4 Casos de borda

| Caso | Comportamento |
|---|---|
| 23:30 e 00:30 | A data é a de `agora` no fuso do PHP. Teste com relógio injetado nos dois instantes |
| Ausência "a partir de 14:00" às 13:59 e às 14:00 | 13:59: escala do dia com marcador. 14:00: `ausente`. Teste nos dois instantes |
| Fim de ausência por intervalo | Em `end_time` volta para a situação seguinte da precedência |
| Ausência cancelada | Ignorada |
| Duas ausências no mesmo instante (só possível em dado legado) | Vale a primeira por `id`; o motor não falha |
| Mudança de regra no meio do período | Cada data usa a regra que vigorava nela |
| Período de escala removida antes do corte | `sem_escala` |
| Técnico arquivado | Fora de qualquer consulta de hoje ou futura. Em consulta histórica, entra nas datas até `arquivado_em` |
| Pausa fora do expediente (pausa esquecida) | `fora_expediente`, pela precedência |
| Sem escala e fora do expediente | `fora_expediente` |
| `estado.json` ilegível | Trata como sem pausa e devolve `meta.pausas_disponiveis = false`; a tela avisa em vez de mostrar zero |
| Almoço | Não muda `situacao`. Sai como `em_almoco: true`, informativo (P2) |
| Virada de mês em `even_days`/`odd_days` | Sem mudança (BIZ-05 continua em aberto). Um ciclo `["onsite","remote"]` resolve a alternância para quem quiser migrar |
| Feriado | Tratado como dia útil comum (D2). Pendência registrada |
| Horário de verão | Não existe no Brasil desde 2019. Horários são comparados em minutos do dia, na mesma data |

### 4.5 Contadores (RF-10)

População por equipe: técnicos ativos cuja **escala do dia** é presencial ou remota (`escalados`). Cada um cai em exatamente uma situação:

```
escalados = disponiveis (presencial + remoto) + em_pausa + ausentes + fora_expediente
```

`sem_escala` é contador à parte, fora da soma: quem não tem escala não é "escalado", mesmo que tenha ausência lançada. `ausentes` só conta ausência lançada. O teste confere a identidade para cada equipe em vários instantes do dia.

### 4.6 Desempenho

- **Carga:** 4 consultas por chamada, independentemente do número de colaboradores e de dias — funcionários, regras que cruzam o intervalo, exceções do intervalo, ausências aprovadas que cruzam o intervalo. Mais uma leitura de `estado.json` com `LOCK_SH` (`ler_estado_pausas`, `config.php:210-225`) quando o intervalo inclui hoje. Sem `information_schema`.
- **Índices:** `idx_schedule_rule_employee_period` (novo), `uq_schedule_exception_employee_date` e `idx_schedule_exception_date` (existentes), `idx_absence_employee_range` (novo).
- **Volume:** cerca de 20 colaboradores × 93 dias = 1.860 células na tela de escala; 366 dias no relatório = 7.320. Cálculo em memória.
- **Teste de N+1:** o repositório é exercitado com um PDO espião (no molde de `QaTransactionPdo`, `scripts/qa-smoke.php:36-67`) e o número de comandos tem de ser o mesmo para 1 e para 50 colaboradores.
- **Tempo real sem polling novo (P14):** a presença de hoje viaja dentro do `status.php`, que já é consultado a cada 15 s por toda sessão. Custo: 3 consultas indexadas a mais por requisição, porque funcionários e estado de pausas o `init.php` já carrega. Calendário e Mapa PA leem desse mesmo estado para o dia atual e consultam `presence.php` só ao abrir ou trocar filtro. A latência do polling entra na medição que já está pendente do PERF-01; se piorar, a alternativa é levar a presença só no `dashboard.php`.

---

## 5. Telas e estados

### 5.1 Legenda única (todas as telas)

| Situação | Cor | Forma | Ícone | Rótulo |
|---|---|---|---|---|
| Presencial | Verde suave | Preenchido, em destaque | Prédio | "(Presencial)" |
| Remoto | Laranja | Preenchido, esmaecido no Mapa PA | Casa | "(Remoto)" |
| Ausente | Vermelho | **Preenchido** | Pessoa com sinal de menos | "(Ausente)" + período |
| Fora do expediente | Cinza | Preenchido | Relógio | "(Fora do expediente)" |
| Em pausa | Azul | Preenchido | Pausa | "(Pausa)" + motivo |
| Sem escala | Cinza | **Contorno tracejado** | Interrogação | "(Sem escala)" |
| Conflito de PA | Vermelho | **Só contorno**, no cartão do PA | Alerta | "Conflito" + dias |
| Ausência parcial futura | Cor da escala | Marcador no canto | Relógio | "Ausente a partir de HH:MM" |

Acessibilidade: nenhuma situação depende só da cor. Toda ocorrência tem rótulo de texto e ícone com `aria-label`; a legenda fica sempre visível; preenchido, contorno e tracejado distinguem os estados para quem não diferencia vermelho de verde; contraste mínimo AA sobre o tema escuro. As cores viram um único mapa em `frontend/src/lib/`, usado pelas quatro telas, no lugar das tabelas de tom de hoje (`CalendarPage.jsx:19-28`, `PaMapPage.jsx:21-28`, `OperationalPages.jsx:112-121`).

Pausa em azul, e não âmbar como nos cartões de pausa atuais, para não se confundir com o laranja de remoto.

### 5.2 Técnicos (RF-07) — F4

Em Aprovações e admin > Funcionários:

```
Nome            Equipe  Perfil   Situação        Ações
Técnico A       N1      Técnico  Ativo           [Editar] [Arquivar]
Técnico B       N2      Técnico  Arquivado 12/10 [Restaurar]
```

- **Arquivar** abre um painel de confirmação na própria linha com os efeitos: sai do Mapa PA, escala encerrada, ausências futuras canceladas, pausa finalizada, login bloqueado, histórico mantido. Substitui o `window.confirm` atual; nenhum `confirm` novo.
- **Restaurar:** painel equivalente, avisando que PA e escala precisam ser recadastrados.
- Arquivados ficam visíveis só com o filtro "Arquivados", e só para admin.

Sequência do arquivamento: (1) finaliza a pausa em andamento dentro de `with_pause_state_lock`, com origem `admin_archive` (P16); (2) em uma transação: `ativo = 0` com data e autor, exclusão lógica dos vínculos de PA, fechamento da regra de escala em `hoje − 1`, cancelamento das ausências futuras e corte em hoje da ausência em curso; (3) auditoria com as contagens. A gravação de `estado.json` fora do lock (L-11) sai desse caminho.

### 5.3 Escala presencial (RF-01, RF-02, RF-03) — F2B

```
Regra:  [ Ciclo ▾ ]
  Dias presenciais [2]   Dias remotos [1]   Começa por ( ) Presencial (•) Remoto
  Data inicial [05/10/2026]   (precisa ser dia útil)
  Prévia das próximas 2 semanas        (calculada pelo servidor)
    seg 05 R | ter 06 P | qua 07 P | qui 08 R | sex 09 P
    seg 12 P | ter 13 R | qua 14 P | qui 15 P | sex 16 R
  Ao salvar: a regra atual "Dias pares" será encerrada em 04/10/2026.
  [Salvar regra]   (habilitado depois da prévia)

Regra:  [ Dias fixos da semana ▾ ]
  Informar (•) dias presenciais  ( ) dias de home office
  [Seg] [Ter] [Qua] [Qui] [Sex]
  Resultado: presencial seg, qua e sex · home ter e qui
```

- Tabela de regras com **Vigente**, **Agendada** e, em painel recolhido por colaborador, **Histórico**.
- Exceção por data: só remoto, presencial, treinamento e plantão. Um aviso leva à tela de Ausências.
- Estados: carregando, vazio, erro por campo (padrão do `scheduleRuleSchema`), erro do servidor pelo canal de feedback, prévia desatualizada quando um campo muda depois dela.

### 5.4 Ausências (RF-04) — F3

Página própria, no lugar da tabela genérica.

```
Filtros: [Período] [Equipe ▾] [Técnico ▾] [Situação: Ativas ▾]        [Nova ausência]

Nova ausência
  Técnico [▾]   De [ ] até [ ]
  Período (•) Dia todo  ( ) A partir de [HH:MM]  ( ) Intervalo [HH:MM]–[HH:MM]
  Categoria [Folga ▾]      (Folga, Férias, Ausência justificada, Compromisso, Outro)
  Observação [                ]
    ⚠ Não registre informação médica ou de saúde neste campo.
  [Salvar]

Técnico    Equipe  Data(s)       Período           Categoria   Ações
Técnico A  N1      06/10         A partir de 14:00 Compromisso [Editar] [Cancelar]
Técnico B  N2      13/10–17/10   Dia todo          Férias      [Editar] [Cancelar]
```

- Os campos de horário só ficam habilitados quando "De" e "até" são iguais (P5).
- Técnico e somente leitura veem só a própria lista, sem formulário e sem ações.
- Data anterior a hoje: desabilitada para gestor; para admin, com a marca "Lançamento retroativo (auditado)".
- Cancelar pede confirmação na linha; o registro passa para o filtro "Canceladas".
- Formulário em React Hook Form com schema Zod, no padrão do A5.

### 5.5 Calendário (RF-09) — F5

- **Mês:** cada dia mostra um **resumo** — "P 9 · R 6 · A 2", com ícone e cor da legenda — em vez de um cartão por técnico (P15). Hoje o mês exibe só 3 cartões e "+N eventos" (`CalendarPage.jsx:224-225`); com 17 técnicos por dia, a escala já não cabe.
- **Semana e detalhe do dia:** um cartão por técnico, com cor, ícone e rótulo. Ausência parcial em dia futuro mantém a cor da escala e ganha o marcador; no dia atual fica vermelho quando o horário chega.
- **Dia atual:** "Fora do expediente" em cinza, antes e depois da jornada.
- O cartão separado de "Ausência" deixa de existir: passa a ser a situação do técnico. War Room troca o vermelho por outro tom, para não se confundir com ausente (P15).
- Os demais tipos (evento manual, hora extra, ajuste de ponto, plantão) não mudam.
- Legenda fixa no rodapé, como hoje (`CalendarPage.jsx:247-249`).

### 5.6 Mapa PA (RF-05, RF-06, RF-08) — F6

```
Dia: [seg 05] [ter 06] [qua 07] [qui 08] [sex 09]      (hoje por padrão; data no fuso local)

┌─ PA 1730 ─────────────┐   ┏━ PA 1729 ━━ ⚠ Conflito ┓   ┌─ PA 1728 ─────────────┐
│ ▣ Técnico A           │   ┃ ▣ Técnico C            ┃   │ ▣ Técnico E           │
│   (Presencial)        │   ┃   (Presencial)         ┃   │   (Ausente) dia todo  │
│ ▢ Técnico B           │   ┃ ▣ Técnico D            ┃   │ ▣ Técnico F           │
│   (Remoto)            │   ┃   (Presencial)         ┃   │   (Presencial)        │
│                       │   ┃ Conflito: seg, qua     ┃   │   ⏱ ausente a partir  │
└───────────────────────┘   ┗━━━━━━━━━━━━━━━━━━━━━━━━┛   │     de 14:00          │
                                                         └───────────────────────┘
[ Mapa ]  [ Ausências da semana ]
Técnico    Equipe  Data   Período            Categoria
```

- **Conflito:** na mesma data, dois ou mais técnicos do mesmo PA com escala presencial, descontado quem tem ausência de **dia inteiro**. Ausência parcial não desfaz o conflito, porque a pessoa ocupa o posto em parte do dia. A janela é a semana (segunda a sexta) da data selecionada.
- O contorno fica no cartão do **PA**; o preenchimento vermelho, no cartão do **técnico**. São níveis diferentes e convivem sem ambiguidade (D6).
- **Salvar com conflito:** salva, mostra o aviso com técnicos e dias, e audita. Sem etapa de confirmação (P13).
- **Remover vínculo ou arquivar técnico:** a lista e o conflito do PA se atualizam na recarga que já segue a ação, porque nada disso é armazenado.
- **Aba "Ausências da semana":** ordenada por data. A coluna Categoria só aparece para admin e gestor (P9).
- Saem a legenda por tipo de regra e a decisão por texto de mensagem (`PaMapPage.jsx:234`, `281-286`).

### 5.7 Visão Operacional (RF-10) — F7

```
              Escalados  Disponíveis  Em pausa  Ausentes  Fora do expediente  Sem escala
N1                8          5           1         1            1                 0
N2                9          7           0         2            0                 1
Liderança         3          3           0         0            0                 0     (fora dos totais)
```

- Números vindos de `presence_summary`. O navegador deixa de contar (`DashboardPage.jsx:30-43`).
- "Ausentes" com a nota "somente ausências lançadas".
- Os cartões atuais de pausas, aprovações e chamados críticos permanecem.
- Estado degradado: com `pausas_disponiveis = false`, a coluna "Em pausa" mostra "—" e um aviso.

---

## 6. Impacto em relatórios e exports

| Relatório | Origem | Impacto |
|---|---|---|
| Relatório operacional, JSON e CSV (`portal/reports.php`) | `OperationalService::report` (`772-839`), `streamReportCsv` (`841-863`) | **Muda.** Ver abaixo |
| Calendário (`portal/calendar.php`) | `listCalendar` (`97-247`) | **Muda.** Eventos de escala vêm do motor; ausência deixa de ser evento separado e de expor `reason` (L-01) |
| Escala presencial (`portal/schedules.php`) | `scheduleData` (`281-293`) | **Muda.** `generated` vem do motor; `rules` ganha histórico |
| CSV de horas extras | `streamOvertimeCsv` (`865-903`) | Não muda |
| CSV de correção de ponto | `streamTimeAdjustmentsCsv` (`905-943`) | Não muda |
| CSV de pausas e métricas | `api/download_relatorio.php`, `api/metricas.php` | Não muda |
| Chamados críticos | `api/portal/critical_incidents_export.php` | Não muda |
| Fila de sincronização | `portal_sync_queue` (`003:126-149`) | Não muda. Ausência **não** entra na fila: o enum `record_type` não tem o tipo e nenhuma integração está ativa |

**Mudanças no relatório operacional, a validar com quem o consome:**

1. **Histórico real.** Um período passado usa a regra que vigorava em cada data, a partir da data de corte. Antes do corte, o relatório mostra um aviso.
2. **`undefined` deixa de contar como remoto** (P4). `remote_days` cai para quem está sem escala; surge `no_schedule_days`.
3. **Fim de semana deixa de contar** como dia presencial ou remoto (P3). `onsite_days` e `remote_days` caem em todos os períodos, inclusive passados.
4. **Ausência aparece.** Hoje o dia de ausência aprovada some do relatório. Passa a contar em `absence_days`, e o CSV ganha a linha com o rótulo "Ausência". A categoria não vai para o CSV.
5. **Técnico arquivado aparece nos períodos anteriores ao arquivamento**, o que hoje não acontece na seção de escala (`OperationalService.php:1129`).

Os itens 2 e 3 alteram números de períodos já fechados. Se isso não for aceitável, a alternativa é aplicar as regras novas só a partir da data de corte, ao custo de o motor ter dois modos.

O limite de 500 linhas das listas (PERF-02) não é tratado aqui.

---

## 7. Decisões D1 a D10

| # | Posição | Fundamento e ajustes |
|---|---|---|
| **D1** | **Confirmo** | Contagem só de segunda a sexta. A data inicial precisa ser dia útil. `business_days_only` é gravado, mas só `true` é aceito agora |
| **D2** | **Confirmo** | O ciclo é função só da âncora e da data. Feriado conta como dia útil e consome posição. Pendência: cadastro de feriados |
| **D3** | **Confirmo, estendido** | Vale também para editar e cancelar ausência que já começou: alterar o passado é a mesma operação. Marca `retroactive` e auditoria `WARNING` |
| **D4** | **Confirmo, com ajuste** | As cinco categorias sugeridas. O schema atual tem `atestado` (L-12): ver P8. Aviso fixo no campo de observação. A auditoria nunca grava o texto da observação. O inventário de dados (`DOCUMENTACAO_TECNICA.md`, 5.3) é atualizado em F3 |
| **D5** | **Confirmo, com precisão** | `somente_leitura` segue a regra do CI. "Visualiza só as próprias" vale para a **lista** de ausências; ver P9 sobre o que aparece no mapa e no calendário. Teste negativo em subprocesso, sem banco |
| **D6** | **Confirmo** | Contorno no PA, preenchimento no técnico: níveis distintos |
| **D7** | **Confirmo, reaproveitando `ativo = 0`** | Criar um estado "arquivado" ao lado de "inativo" seria a estrutura paralela que o requisito pede para evitar. Entram só data e autor. A exclusão física já é barrada pelas FKs. Pendência: política de retenção e anonimização |
| **D8** | **Confirmo** | Só no dia atual. Fim de semana recebe o mesmo estado (P3) |
| **D9** | **Contraproposta** | Ver P1 |
| **D10** | **Confirmo, com dependência** | Liderança hoje não pode ter escala: o motor devolveria `sem_escala` para todos. Ver P12 |

---

## 8. Decisões novas

| # | Proposta | Por quê | Se recusada | Bloqueia |
|---|---|---|---|---|
| **P1** | Expediente = **jornada do próprio técnico** (`funcionarios.jornada_entrada/saida`), sem configuração global | O dado já existe, é editável no formulário do A5 e já governa a tela de Pausas. Um expediente global 08:00–18:00 ao lado de jornadas 08:00–17:00 faria a Visão Operacional dizer "disponível" às 17:30 enquanto Pausas diz "Após a jornada". Q6 mostra se as jornadas cadastradas são reais | Entram duas chaves no `config.json` e um campo em Configurações; a divergência com Pausas fica documentada | F2 |
| **P2** | Almoço não muda a situação: conta como disponível e sai como indicador | O requisito não tem estado de almoço, e a soma do RF-10 não fecha com um quinto estado. **Muda o número atual:** hoje "CIs disponíveis" exclui quem está no almoço (`Funcionario.php:72-77`) | Criar o estado `almoco` e incluí-lo na soma | F2 |
| **P3** | Sábado e domingo são dia não útil para todos os tipos de regra | Coerente com D1 e com `fixed_weekdays`, que já só conhece seg–sex. **Muda contagens de relatório** (seção 6) | O motor mantém par/ímpar no fim de semana e só o ciclo pula | F2 |
| **P4** | `undefined` e ausência de regra viram `sem_escala`, não remoto | É o que o requisito define. **Muda `remote_days`** | Manter como remoto contradiz a seção 1 do requisito | F2 |
| **P5** | Ausência com horário só para um dia; intervalo de datas é sempre dia inteiro | "De 01 a 03 a partir de 14:00" é ambíguo. Um dia com horário mais um intervalo de dia inteiro cobre os casos reais | Definir a semântica do horário em vários dias | F3 |
| **P6** | Ausência sobreposta é **rejeitada** (409), não mesclada | Mesclar categorias ou períodos diferentes exige escolher qual vence, e a escolha fica invisível | Definir regra de mescla | F3 |
| **P7** | Exceções com tipo de ausência migram para `portal_absences`; a tela de exceção fica só com remoto, presencial, treinamento e plantão | Uma só fonte de ausência, com permissão e auditoria únicas | O motor lê as duas fontes, e ausência continua lançável por dois caminhos com regras diferentes | F3 |
| **P8** | Legado `atestado` vira "Ausência justificada"; se Q3 mostrar linhas, **apagar** o valor antigo e o texto de `reason` das linhas legadas | Minimização de dado de saúde. **Irreversível**, a não ser por backup | Manter os valores, ocultos da API, até a política de retenção | F3 |
| **P9** | CI vê de todos **a situação "ausente" e o período**; categoria e observação só das próprias | Mapa e calendário precisam mostrar quem está ausente para cumprir o RF-08. O que é sensível é o motivo | CI vê os colegas ausentes como "Remoto/Presencial", o que desfaz o RF-08 para esse perfil | F3 |
| **P10** | Regra de escala com início **retroativo só para Admin**, auditada. Histórico anterior à data de corte não é reconstruído | Sem isso o histórico recém-criado pode ser reescrito por qualquer gestor. Espelha a D3 | Gestor continua podendo gravar qualquer data, como hoje | **F1** |
| **P11** | `require_portal_auth` passa a conferir, no máximo uma vez por minuto por sessão, se o funcionário da sessão CI continua ativo; se não, encerra a sessão. **Altera autenticação: precisa de aprovação explícita.** Administrador que entra pela allowlist `AD_ADMIN_USERS` não é atingido pelo arquivamento | "Login bloqueado" hoje só vale para login novo; a sessão aberta dura até 8 h (L-06) | O bloqueio fica documentado como "até o fim da sessão" | F4 |
| **P12** | Habilitar escala, exceção e ausência para a equipe Liderança | Sem isso D10 e o RF-08 mostram a Liderança sempre como "Sem escala" | Liderança aparece só como contagem de pessoas, fora do motor | **F1** |
| **P13** | Salvar vínculo com conflito **direto, com aviso**, sem confirmação. Ausência parcial não desfaz conflito. Edição do mapa continua só Admin | "Sem bloquear" do RF-05; evita novo `window.confirm` | Etapa de confirmação em dois passos | F6 |
| **P14** | Presença de hoje embutida no `status.php` (15 s) | Único polling global; nenhum polling novo | Só no `dashboard.php`; Calendário e Mapa atualizam ao abrir | F2 |
| **P15** | Mês do calendário com resumo por dia; War Room muda de tom | Cabe na tela e não colide com a cor de ausente | Um cartão por técnico também no mês | F5 |
| **P16** | Arquivar finaliza a pausa em andamento, registrada com origem `admin_archive` | Senão o arquivado fica "em pausa" no estado até a limpeza de 24 h | Recusar o arquivamento enquanto houver pausa aberta | F4 |

---

## 9. Divisão em lotes

Cada lote: commit local por tema, sem push, parada para aprovação, atualização do `REGISTRO_USO_IA.md`. Comum a todos: `php -l`, `qa-smoke`, `qa-auth`, `lint`, `typecheck` e os `qa:*` existentes continuam passando.

| Lote | Escopo | Testes novos | Rollback | Depende de |
|---|---|---|---|---|
| **F0** | Rodar Q1–Q6; decidir D1–D10 e P1–P16 | — | — | Este documento |
| **F1** Dados e histórico | Migration 011; salvar, remover e importar com vigência; `GET` com `is_current`/`is_future`; atualizar `DEPLOY_LINUX.md` | Planejador de mudança de regra como função pura (fecha, insere, atualiza, regra futura, idêntica, retroativa); consulta de não sobreposição; mutação | Script de rollback da 011 + voltar o código | BIZ-02 fechado; P10, P12 |
| **F2** Motor | `PresenceEngine`, `PresenceRepository`, cálculo de ciclo, `portal/presence.php`; `generatedSchedule` e `status.php` delegando | `qa-presence-engine`: ciclo em 3 semanas, precedência, 13:59/14:00, 23:30/00:30, identidade da soma, contagem de consultas | Código apenas. Com linha `cycle` gravada, rodar antes o rollback da 011 | F1; P1–P4, P14 |
| **F2B** Escalas na tela | Ciclo com prévia, dias de home, histórico (RF-01, RF-02) | `qa:schedule-form` e `qa:schedule-rules` ampliados; "home ter e qui" grava seg/qua/sex; regras `fixed_weekdays` antigas intactas | Frontend + validador | F2 |
| **F3** Ausências | **Primeiro** o escopo de leitura (SEC-07 e L-01); migrations 012 e 013; serviço; página; inventário LGPD | `qa-absences`: validações, sobreposição, retroativo, matriz de perfis; negativo de CI → 403 em subprocesso | Scripts da 012 e 013 + código | F2; aprovação de RBAC; P5–P9 |
| **F4** Técnicos e PA | Migration 014; arquivar e restaurar; sincronização do PA (RF-06); sessão (P11); `status.php` sem arquivados | Plano de efeitos do arquivamento como função pura; negativo de gestor e CI → 403; histórico preservado | Script da 014 + código | F3; P11, P16 |
| **F5** Calendário | RF-09; L-13 e L-14 | QA de legenda e rótulos; nenhuma cor sem texto | Frontend | F2, F3 |
| **F6** Mapa PA | RF-05 e RF-08; motor no lugar da cópia; fim do N+1; data local | `qa-pa-conflicts`: dois presenciais → conflito; um ausente o dia todo → sem conflito; parcial → mantém; `fixed_weekdays` disjuntos → sem conflito | Código. As colunas de cópia continuam gravadas | F2, F3, F4; P13 |
| **F7** Visão Operacional | RF-10; `presence_summary` | Identidade da soma pelo endpoint; Liderança fora dos totais | Código | F2, F3, F4 |

F5, F6 e F7 são independentes entre si e podem mudar de ordem.

**Roteiro manual por lote no servidor** (o QA local não tem MySQL, Apache nem AD): aplicar a migration, rodar a consulta de verificação, exercitar a tela por perfil. Entregue junto de cada lote, como nos anteriores.

---

## 10. Riscos e pendências

| Risco | Mitigação |
|---|---|
| Fuso do MySQL (A4): correção implementada, ainda não validada nem implantada | Implementação da Parte B só depois do deploy do A4. Código novo não usa relógio do banco |
| Números de relatório mudam (P3, P4, ausência, arquivado) | Seção 6; validar com quem consome o CSV antes de F2 |
| Rollback de código com regra `cycle` gravada quebra três telas | Documentado em 2.1; o script de rollback converte antes |
| Migrations dependem de nomes de índice e FK do servidor | Q1 antes de F1; todas as migrations checam `information_schema` |
| Sem MySQL no ambiente de desenvolvimento: migrations não são executadas pela IA | Lógica de decisão em funções puras testadas; SQL fino; roteiro de verificação por lote; primeira execução acompanhada pelo responsável |
| QAs antigos com checagem por `git diff` e "arquivos protegidos" podem disparar durante F2B–F7 | Dívida técnica já registrada; tratar caso a caso, com aprovação, como no Lote 2 |
| `qa:schedule-rules` fixa a lista de tipos de regra e dias | Atualização deliberada em F1 e F2B, descrita na entrega |
| `AdminPage.jsx` denso, alterado de novo em F4 | Mudança restrita à aba Funcionários; sem refatoração junto |
| Carga extra no polling de 15 s (P14) | Medir antes e depois, com a medição pendente do PERF-01 |

**Pendências fora desta entrega:** feriados; política de retenção e anonimização (LGPD); BIZ-05 (paridade na virada do mês); SEC-04 (Liderança ⇒ admin); PERF-02 (listas truncadas); remoção das tabelas legadas `portal_schedules` e `portal_pa_map`; horário de jornada que cruza a meia-noite.

---

**Fim da Fase de Desenho.** Aprovado em 2026-09-30 com P2, P12 e o tratamento dos relatórios em aberto (seção 0.5).
