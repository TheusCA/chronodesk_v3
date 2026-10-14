# Desenho — Mapa PA, Escalas, Ausências, Técnicos, Calendário e Visão Operacional

**Fase:** A6 — Fase de Desenho da Parte B (`REQUISITOS_PA_ESCALA_AUSENCIA.md`, seção 9)
**Data:** 2026-09-30
**Base analisada:** `beff99d` no branch `feat/employee-form-rhf-zod` (= `c049868` + registro do A5)
**Situação:** **aprovado em 2026-09-30**; os três pontos em aberto (P2, P12 e o tratamento dos relatórios) foram **decididos em 2026-10-02** — ver seção 0.6. **F1 entregue, revisado em MySQL 8.0.46 pelo responsável, corrigido e aprovado em 2026-10-05** (seção 9.1). **F2 entregue e aprovado em 2026-10-06**, com escopo reduzido, após revisão e teste do responsável em MySQL 8.0.46 (seção 9.2). **F2B: plano aprovado em 2026-10-07 com ajustes; backend aprovado em 2026-10-07 após teste do responsável em MySQL 8.0.46 estrito; frontend aprovado em 2026-10-07; F2B completo, deploy 10 da fila** (seção 9.3). **F3: plano aprovado em 2026-10-07 (seção 9.4); commit 1, filtro "só aprovada" e migration 012 aprovados; commit 3 aprovado; commit 4 (ausência parcial no motor) aprovado em 2026-10-08, com o ajuste do marcador; commits 5 a 7 aprovados em 2026-10-08; F3 completo, deploy 11 da fila** (seção 9.4). **SEC-07b (texto livre do calendário): plano aprovado em 2026-10-09 com ajuste na D2; implementado e aprovado em 2026-10-09; deploy 12 da fila** (seção 9.5). **F4 (arquivamento): plano aprovado em 2026-10-13 com ajuste na D8; commit 1 (migration 014) aprovado em 2026-10-13; commit 2 (planejador puro) aprovado em 2026-10-13; commit 3 (serviço e endpoints) aprovado em 2026-10-13; commit 4 (L-07) aprovado em 2026-10-13, com a decisão 8 (conflito do Mapa PA sem inativos); commit 5 (tela de Funcionários) aprovado em 2026-10-13; commit 6 (ponta a ponta e documentação) aprovado em 2026-10-13: **F4 completo**, no deploy único da Parte B** (seção 9.6). **F5: plano aprovado em 2026-10-13, com ajuste na D2 (corte no 1º dia da competência seguinte ao deploy); commit 1 (migration 019) entregue em 2026-10-13, aguardando teste do responsável** (seção 9.7). F5 a F7 ainda não têm código. Os lotes F são desenvolvidos antes do deploy; o deploy do A4 virou regra de **ordem de deploy** (os F entram na fila depois do deploy 6), não pré-requisito de desenvolvimento.

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

P2, P12 e o tratamento dos relatórios foram decididos em 2026-10-02: seção 0.6.

### 0.6 Decisões do responsável (2026-10-02)

| Item | Decisão | Como entra no desenho |
|---|---|---|
| **P12** | Liderança participa do Mapa PA com escala **sempre presencial** (regra fixa, sem home) e PA como os técnicos | Ver "P12 e cadastros fora do Mapa e da escala", abaixo |
| **L9** (Lote 5) | Escala da Liderança: só o admin edita | Regra, exceção e ausência de quem é da equipe Liderança: só admin (também A4 do Lote 5). A Liderança gerencia o Mapa PA (Lote 5c) |
| **P2** | Almoço é estado próprio ("Almoço") e **não** conta como disponível | Seção 4.3: `almoco` entra na precedência depois de `pausa`; seção 4.5: `almoco` vira parcela da soma; a Visão Operacional ganha a coluna "Almoço" |
| **Relatórios de períodos fechados** | Regras novas só a partir da **data de corte do deploy**; períodos anteriores mantêm a regra antiga | Seção 6: o motor tem os dois modos e o relatório divide o período no corte |
| **SEC-08** | Anexo de escala restrito à própria equipe; gestor, Liderança e admin veem todos | Lote 4 (fora da Parte B) |
| **Ordem** | Desenvolver tudo antes; implantar a fila em etapas. "F1 só depois do deploy do A4" vira regra de ordem de deploy | Os lotes F entram na fila de deploy depois do deploy 6. Toda migration nova: regra do A4, rollback e ensaio em `scripts/ensaio-migration.sh` |

#### P12 e cadastros fora do Mapa e da escala

**Propostas abaixo aprovadas em 2026-10-02**, com estes esclarecimentos:
- escala implícita da Liderança, sem linha em `portal_schedule_rules`; os dias e horários vêm da jornada do próprio cadastro (P1); sábado não é implícito, só por exceção; exceção remota recusada; linha de líder na planilha de importação recebe aviso e é ignorada;
- `funcionarios.fora_mapa_escala` como na tabela: só admin edita (permissão `funcionarios.fora_mapa`); auditoria WARNING com valor anterior, novo e ator; 409 se houver vínculo de PA ativo ou se tentarem regra ou exceção para o cadastro marcado; fora de todas as contagens; ausências continuam registráveis; nenhum nome ou ID no código, nas migrations ou nos commits.

**Escala da Liderança.** Proposta: escala **implícita** para quem é da equipe Liderança, sempre presencial em dia útil, sem linha em `portal_schedule_rules`. O motor (F2) resolve "equipe Liderança, sem exceção no dia → presencial nos dias e horários da jornada do cadastro (P1); sábado só por exceção"; a tela de escala mostra a Liderança como "Presencial (regra fixa)", sem botão de editar regra. Não há regra a cadastrar nem importar para a Liderança: linha de líder na planilha recebe aviso e é ignorada. O que o admin edita (L9) são as **exceções** (treinamento, plantão; a exceção remota é recusada, porque a regra é sem home) e as **ausências** (A4 do Lote 5). O passo 2.6 continua na migration 011 (`team` com `lideranca` nas tabelas de regra e de exceção), porque a exceção de líder precisa do valor. Alternativa, se o responsável preferir dado explícito: gravar regra `always_onsite` para cada líder e aceitar só esse tipo para a equipe; custa cadastro manual e abre a chance de um líder sem regra aparecer como "Sem escala".

**Cadastros fora do Mapa e da escala.** O desenho aprovado só tinha o arquivamento (`ativo = 0`), que bloqueia o login e não serve. Proposta:

| Item | Proposta |
|---|---|
| Dado | `funcionarios.fora_mapa_escala TINYINT(1) NOT NULL DEFAULT 0`, na migration 011 (F1). Vale para qualquer equipe; hoje o uso previsto é em 2 cadastros da Liderança. Nenhum nome ou ID no código, nas migrations ou nos commits: o responsável marca pela tela depois do deploy |
| Quem edita | Só admin (permissão nova `funcionarios.fora_mapa`, só do admin), no formulário de funcionário (caixa "Fora do Mapa PA e da escala") e em `atualizar_funcionario.php`. A Liderança vê o valor, não altera |
| Auditoria | `FUNCIONARIO_FORA_MAPA_ALTERADO` (WARNING), com o valor anterior, o novo e o ator |
| Mapa PA (F6) | Não aparece. Marcar é **recusado** (409) enquanto houver vínculo de PA ativo, com mensagem: o admin remove o vínculo antes. Menos destrutivo que encerrar vínculos em silêncio |
| Escala (F1, F2) | Não tem escala nem aparece na tela de escala. Regra ou exceção para o cadastro marcado: 409 |
| Visão Operacional (F7) e contadores (4.5) | Fora de todas as contagens, inclusive "Sem escala" |
| Ausências (F3) | Continuam registráveis (por admin, quando for líder) e aparecem na lista de ausências com a mesma regra de leitura (P9). Não entram em contagem nenhuma |
| Calendário (F5) | Sem eventos de escala (não há escala); as ausências aparecem na camada de ausências, como as dos demais; eventos operacionais sem mudança |
| Login, permissões e pausas | Sem mudança |
| Relatórios | Fora da seção de escala do relatório operacional a partir do corte; horas extras, ponto e pausas sem mudança |

#### P2 — almoço

`almoco` é uma `situacao` própria, com o rótulo "Almoço": dentro do intervalo `almoco_inicio`–`almoco_fim` da jornada do colaborador, em dia útil, depois de ausência, fora do expediente e pausa na precedência (pausa durante o almoço continua "Em pausa"). Não conta como disponível. Contadores: `escalados = disponiveis + em_pausa + almoco + ausentes + fora_expediente`.

#### Relatórios — data de corte

A data de corte é registrada no deploy do F2, como valor explícito (mesmo padrão do corte do BIZ-02). O relatório operacional divide o período no corte: antes, regra antiga (sem P3, P4, ausência e arquivado); a partir dele, o motor. O CSV ganha uma linha de aviso quando o período cruza o corte.

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

**Dispensada em 2026-10-07** (Q4 com 0 linhas; decisão do responsável): vale só a restrição de tipos no validador. O texto abaixo fica como registro do desenho original.

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

**Revalidação antes do F3 (responsável, servidor, 2026-10-07)**, com `SET time_zone = 'America/Sao_Paulo'` e a Q4 ampliada (equipe, futuras, com observação) e uma Q4b (exceções de inativos e de cadastros fora do Mapa):

| Consulta | Resultado | Consequência (decisão do responsável, 2026-10-07) |
|---|---|---|
| Q3 (`portal_absences` por tipo e status; órfãs e de inativos) | **0 linhas**; nenhum texto de motivo gravado | A 012 não tem dados a tratar; a FK entra (0 órfãs) |
| Q4 (`portal_schedule_exceptions` por tipo e equipe) | **0 linhas** | **A 013 fica dispensada**; vale só a restrição de tipos no validador (P7) |
| Q4b | Erro 1054 em `f.fora_mapa_escala`: a 011 ainda não foi aplicada no servidor (deploy 8 da fila). Com 0 exceções, total 0 | Nenhuma |

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
| 3a | Intervalo de almoço da jornada (P2, decidido em 2026-10-02) | `almoco` | `expediente` | "Almoço 12:00–13:00" |
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
| Almoço | `situacao = almoco` (P2 decidida em 2026-10-02): estado próprio, não conta como disponível |
| Virada de mês em `even_days`/`odd_days` | Sem mudança (BIZ-05 continua em aberto). Um ciclo `["onsite","remote"]` resolve a alternância para quem quiser migrar |
| Feriado | Tratado como dia útil comum (D2). Pendência registrada |
| Horário de verão | Não existe no Brasil desde 2019. Horários são comparados em minutos do dia, na mesma data |

### 4.5 Contadores (RF-10)

População por equipe: técnicos ativos cuja **escala do dia** é presencial ou remota (`escalados`). Cada um cai em exatamente uma situação:

```
escalados = disponiveis (presencial + remoto) + em_pausa + almoco + ausentes + fora_expediente
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
- **Dia atual:** ~~"Fora do expediente" em cinza, antes e depois da jornada.~~ **Mudado pela D9 do F5 (aprovada em 2026-10-13):** o Calendário mostra o dia atual sem relógio, como os demais (escala do dia, ausência e marcador da parcial); "Fora do expediente" e "Em pausa" ficam no painel ao vivo e na Visão Operacional (F7), porque o Calendário não se atualiza sozinho.
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
              Escalados  Disponíveis  Em pausa  Almoço  Ausentes  Fora do expediente  Sem escala
N1                8          4           1         1       1            1                 0
N2                9          7           0         0       2            0                 1
Liderança         3          3           0         0       0            0                 0     (fora dos totais)
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

Os itens 2 e 3 alteram números de períodos já fechados. **Decisão de 2026-10-02:** as regras novas valem só a partir da data de corte do deploy; períodos anteriores mantêm a regra antiga (seção 0.6). O motor tem os dois modos.

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
| **F1** Dados e histórico | Migration 011 (com o passo 2.6 e `fora_mapa_escala`); salvar, remover e importar com vigência; Liderança com escala implícita e edição só por admin (P12, L9); campo "fora do Mapa e da escala" no cadastro, só admin; `GET` com `is_current`/`is_future`; atualizar `DEPLOY_LINUX.md` | Planejador de mudança de regra como função pura (fecha, insere, atualiza, regra futura, idêntica, retroativa); consulta de não sobreposição; mutação | Script de rollback da 011 + voltar o código | P10, P12 (decidida); deploy depois do A4 |
| **F2** Motor | `PresenceEngine`, `PresenceRepository`, cálculo de ciclo, `portal/presence.php`; `generatedSchedule` e `status.php` delegando | `qa-presence-engine`: ciclo em 3 semanas, precedência, 13:59/14:00, 23:30/00:30, identidade da soma, contagem de consultas | Código apenas. Com linha `cycle` gravada, rodar antes o rollback da 011 | F1; P1–P4, P14 |
| **F2B** Escalas na tela | Ciclo com prévia, dias de home, histórico (RF-01, RF-02) | `qa:schedule-form` e `qa:schedule-rules` ampliados; "home ter e qui" grava seg/qua/sex; regras `fixed_weekdays` antigas intactas | Frontend + validador | F2 |
| **F3** Ausências | **Primeiro** o escopo de leitura (SEC-07 e L-01); migrations 012 e 013; serviço; página; inventário LGPD | `qa-absences`: validações, sobreposição, retroativo, matriz de perfis; negativo de CI → 403 em subprocesso | Scripts da 012 e 013 + código | F2; aprovação de RBAC; P5–P9 |
| **F4** Técnicos e PA | Migration 014; arquivar e restaurar; sincronização do PA (RF-06); sessão (P11); `status.php` sem arquivados | Plano de efeitos do arquivamento como função pura; negativo de gestor e CI → 403; histórico preservado | Script da 014 + código | F3; P11, P16 |
| **F5** Calendário | RF-09; L-13 e L-14; pendências da tela Escala anotadas no F2B (seção 9.3: filtro "ocultar sem escala", acentos dos rótulos, quebra de "Liderança") | QA de legenda e rótulos; nenhuma cor sem texto | Frontend | F2, F3 |
| **F6** Mapa PA | RF-05 e RF-08; motor no lugar da cópia; fim do N+1; data local | `qa-pa-conflicts`: dois presenciais → conflito; um ausente o dia todo → sem conflito; parcial → mantém; `fixed_weekdays` disjuntos → sem conflito | Código. As colunas de cópia continuam gravadas | F2, F3, F4; P13 |
| **F7** Visão Operacional | RF-10; `presence_summary` | Identidade da soma pelo endpoint; Liderança fora dos totais | Código | F2, F3, F4 |

F5, F6 e F7 são independentes entre si e podem mudar de ordem.

### 9.1 F1 — entrega de 2026-10-05 (aprovado em 2026-10-05)

Commits `f91c850` (ensaio com `DADOS` e `VERIFICA`), `cc16dc2` (migration 011, rollback e verificação), `aa15d65` (vigência), `5f1fdf0` (escala implícita da Liderança e L9), `2b82eef` (ajuste do `qa:action-runner`), `8a97e5d` (fora do Mapa PA e da escala), `696e541` (testes) e o commit de documentação.

| Item | Como ficou |
|---|---|
| Migration 011 | Como na seção 2.1, com os passos 2.6 e `fora_mapa_escala` (`migrations/20261005_011_schedule_history.sql`). Regra do A4. Nenhuma linha muda. Rollback com cópias `*_bkp_011`; verificação em `migrations/verificacao/` (só contagens) |
| Vigência | `services/ScheduleRulePlanner.php` (funções puras) + `OperationalService`. Colaborador travado (`FOR UPDATE`) antes de ler o histórico; invariantes conferidos antes do commit (violação nova desfaz tudo) |
| Remover | Fecha a vigente em hoje − 1 sem trocar o tipo; apaga a que começa hoje ou depois, com o conteúdo na auditoria; sem vigente nem agendada: 409 |
| Importação | Data do PHP; linha igual à vigente não grava; linha de líder com aviso, ignorada; cadastro marcado: linha inválida |
| P10 com L6 | Início anterior a hoje: admin e Liderança (`escalas.retroativa`); gestor 403 antes de travar; auditoria com `retroativa=sim` |
| GET | `today`; regras com `is_current`, `is_future`, `config`, `implicit`; padrão = vigentes no período e agendadas; `include_history=1` = tudo |
| Liderança (P12) | Sem linha no banco: regra implícita "Presencial (regra fixa)", dias úteis, `work_start`/`work_end` da jornada; sábado e domingo só por exceção. Regra para líder: 409 (mesmo admin). Exceção de líder só admin (L9, agora permissão `escalas.lideranca`); remota: 409 |
| Fora do Mapa PA e da escala | Campo no formulário (só na edição), editável com `funcionarios.fora_mapa` (admin); Liderança vê e reenvia o valor. Marcar com vínculo de PA ativo: 409. Auditoria `FUNCIONARIO_FORA_MAPA_ALTERADO` (WARNING). Marcado: 409 em regra, exceção e vínculo de PA; fora da tela de escala e da lista de elegíveis do Mapa PA |

**Decisões de implementação a aprovar:**

1. **Regra no meio de um período já encerrado** (escala removida e depois regra retroativa dentro dele): a nova termina onde a encerrada terminava, em vez de ficar aberta. A seção 2.1 não tratava o caso; a escolha não reabre uma remoção sem pedido.
2. **Linha vazia** (fim antes do início, gravada pela remoção antiga quando a regra começava no futuro): não vigora em data nenhuma; se a nova regra começar na mesma data, a linha é reaproveitada (a UNIQUE de início não deixa inserir outra).
3. **Regra igual à vigente com regra agendada depois:** nada muda (a agendada continua). Para cancelar a agendada, remover a escala e cadastrar de novo.
4. **Exceção de líder:** aceita os tipos de hoje, menos remoto (os de ausência saem no F3, para todos). Exceção em fim de semana gera o dia; sem exceção, sábado e domingo ficam sem linha só para a Liderança (para N1 e N2 a regra P3 é do F2).
5. **Cadastro marcado com regra antiga:** a regra fica no banco, some da tela e volta se o cadastro for desmarcado; remover continua permitido. Calendário e relatório mudam no motor (F2 e F5).
6. **Mapa PA:** além do 409 ao marcar com vínculo ativo (pedido), o vínculo novo para cadastro marcado é recusado (409) e o cadastro sai da lista de elegíveis; sem isso a marcação podia ser contornada logo depois. As duas operações travam o mesmo cadastro.
7. **Listagem de funcionários:** `fora_mapa_escala` vai para gestor, Liderança e admin (telas de escala); sem a coluna (011 não aplicada), o campo fica de fora e a lista continua.
8. **Criação de funcionário:** o campo não é aceito em `adicionar_funcionario.php` (nasce 0); marcar é só na edição, como na seção 0.6.

**Revisão de 2026-10-05 (testes do responsável em MySQL 8.0.46 real) e correções:**

| # | Achado na revisão | Correção |
|---|---|---|
| 1 | Duas gravações simultâneas no mesmo colaborador (regras agendadas a partir de 10/10 e de 20/10) commitaram sobreposição (verificação: `sobreposicoes=2`). No REPEATABLE READ, a leitura simples do cadastro antes da trava fixava o snapshot; a gravação que esperou a trava lia o histórico antigo, e a conferência de invariantes também | `0b1b67b`: leitura do histórico com `FOR UPDATE` (no teste do responsável, 0 sobreposições). O banco simulado do `qa-schedule-history` passou a emular o REPEATABLE READ e um teste faz a outra gravação acontecer durante a espera da trava; sem o `FOR UPDATE`, reprova com a sobreposição |
| 2 | No segundo rollback da 011, a cópia era pulada (já existia), o histórico gravado depois do primeiro rollback era apagado e a cópia continuava a antiga | `d58c070`: com qualquer `*_bkp_011` existente, o rollback para antes de alterar (`SIGNAL SQLSTATE '45000'`, "rollback_011 abortado: ..."), por um procedimento temporário; as cópias deixaram de ser condicionais. No ensaio, o 2º rollback agora falha de propósito |
| 3 | O `CHECKSUM TABLE` das regras muda depois da 011 (coluna gerada `STORED`) e volta depois do rollback; o `DEPLOY_LINUX.md` dizia que ficava igual | `d9d4703`: "o que conferir" corrigido; a verificação ganhou a assinatura das colunas originais (CRC32 por linha, XOR), que não muda com a 011 |

Validação em MySQL real: feita pelo responsável nesta revisão (ensaio da 011 e do rollback e o teste de concorrência).

**Aprovado em 2026-10-05**, depois do reteste das correções em MySQL 8.0.46 real (concorrência, ensaio completo da 011 com o 2º rollback parando, assinatura estável). Commits da revisão: `0b1b67b`, `d58c070`, `d9d4703`, `abe5a11`. Deploy 8 da fila.

### 9.2 F2 — plano aprovado (2026-10-05), base da medição e entrega (2026-10-06)

**Plano do F2 aprovado em 2026-10-05, com escopo reduzido:** `generatedSchedule` continua no cálculo antigo (a troca fica no F2B, F5 e F6). Decisões do responsável:

1. Data de corte dos relatórios: registrada no deploy do F5, não no F2.
2. Até o F3, as exceções `day_off`, `absence`, `vacation` e `leave` contam como ausente o dia inteiro (origem `ausencia`); `training` e `oncall` não são ausência.
3. Interruptor `STATUS_PRESENCE_ENABLED` no `.env`, **ligado por padrão no deploy** (variável ausente = ligado). Critério de piora: o p95 sobe **mais de 50 ms e mais de 20%** em relação à base, as duas condições ao mesmo tempo. Com piora, desligar o interruptor e recarregar o PHP-FPM, sem voltar o código.

Medição: `scripts/medir-polling.sh` (`f91d2d0`), roteiro em `DEPLOY_LINUX.md`, "Medição do Polling".

**Base (antes do F2), medida pelo responsável:**

| Item | Valor |
|---|---|
| Data e hora | 2026-10-06 10:07 (-03), horário de uso normal |
| Condições | Conta do responsável; sem `CONSULTAS=1` (sem senha root disponível); `HOST_HEADER` = ServerName do vhost; `N=200`, aquecimento 5 |
| Respostas | HTTP 200 em 200/200 |
| Tempo | p50 4,3 ms; **p95 26,8 ms**; máx. 39,7 ms; média 8,6 ms |
| Tamanho médio da resposta | 12.208 bytes |
| Limite de piora | p95 acima de 76,8 ms (26,8 + 50; os 20% dariam 32,2 ms, então vale o maior). **Só referência:** o limite do critério é recalculado sobre a medição repetida logo antes do deploy do F2 (decisão de 2026-10-06) |

A medição depois do deploy do F2 repete as mesmas condições: mesma conta, sem `CONSULTAS`, `N=200`, horário parecido. A base também é o "antes" da medição pendente do PERF-01 (pendência 10 do estado da sessão).

**Entrega do F2 (2026-10-06; aprovada em 2026-10-06):** commits `60da301` (motor, repositório, serviço, `portal/presence.php`, `status.php`, `qa-presence-engine`), `f204ca6` (interruptor nos exemplos e no preflight) e o de documentação.

| Item | Como ficou |
|---|---|
| Motor | `services/PresenceEngine.php`: funções puras, relógio por parâmetro. Ciclo pela forma fechada (seção 4.2); vigência por `ScheduleRulePlanner::ruleAt` (a do F1) |
| Carga | `services/PresenceRepository.php`: 4 consultas por chamada (funcionários, regras, exceções, ausências aprovadas), sem relógio do banco, sem observação, motivo ou categoria |
| `presence.php` | Contrato da seção 3.2, qualquer perfil autenticado; 400 para parâmetro inválido; fora do Mapa e equipe inválida ficam de fora; Liderança entra |
| `status.php` | `presenca` em cada item de N1/N2 e `presenca_disponivel`, atrás do interruptor; falha no cálculo não derruba o status |
| Fora do escopo (plano aprovado) | `generatedSchedule` (Calendário, Escala, Relatórios), Mapa PA e `technicians.php` continuam no cálculo antigo |

**Decisões de implementação (aprovadas em 2026-10-06):**

1. **Exceção de trabalho no fim de semana vale para todos.** Remoto, presencial, treinamento e plantão num sábado ou domingo geram o dia também para N1 e N2; sem exceção, fim de semana é dia não útil (P3). A seção 4.2 punha o fim de semana antes da exceção; inverti para coincidir com a regra da Liderança (seção 0.6) e com a decisão 4 do F1.
2. **Ausência vence fim de semana e fora do expediente**, pela precedência literal da seção 4.3.
3. **Dado ruim não lança exceção.** Tipo desconhecido, `undefined` e ciclo mal configurado viram "Sem escala definida"; horário de jornada ou almoço inválido ou nulo desliga só a checagem correspondente. O `presenceForRule` antigo lança exceção (400); o motor não, para o `status.php` não cair por uma linha.
4. **Fim de semana em outra data** sai como `fora_expediente`, origem `expediente`, "Fim de semana", igual a hoje; `escala.situacao` fica `dia_nao_util`.
5. **`status.php`:** `presenca` é `null` para inativo e para cadastro fora do Mapa PA e da escala; campo novo `presenca_disponivel` (`false` se o cálculo falhar, com log só da classe do erro); **4 consultas a mais** por requisição, não 3 como estimado na seção 4.6: a quarta é a de funcionários, para jornada e marcação "fora do Mapa" virem da mesma fonte do `presence.php`.
6. **Sem categoria nem observação de ausência para nenhum perfil até o F3.** `ausencias[]` só tem `origem` (`excecao` ou `ausencia`), `period_type` e `ativa`; a regra P9 (gestão vê a categoria) entra com as ausências no F3.
7. **Ausências aprovadas de `portal_absences`** (0 linhas hoje) já contam como dia inteiro, como o `generatedSchedule` atual faz.
8. **Exceção remota de líder gravada antes do F1** aparece como remoto: o F1 recusa criar, o motor mostra o dado que existe.
9. **`meta.pausas_disponiveis`** é `false` só quando o período inclui hoje e o `estado.json` existe mas não pôde ser lido; arquivo ausente conta como sem pausas.
10. **Interruptor com valor fora da lista** fica ligado no PHP, com aviso no log; o preflight reprova o valor.
11. **Contadores** (seção 4.5): `PresenceEngine::summarize` já está no motor e testado; o `dashboard.php` passa a usá-lo no F7.

Validação pela IA: `qa-presence-engine` com 126 verificações (critério de aceite do ciclo, forma fechada contra contagem dia a dia em 4.000 pares, precedência por instante, 23:30/00:30, identidade da soma em mais de 200 instantes, 4 consultas para 1 e 50 colaboradores e para 1 e 93 dias); mutação **23/23**; `presence.php` na matriz de perfis do `qa-security` (sem sessão 401; todos os perfis passam). Limitações: consultas nunca rodaram em MySQL real; `presence.php` com dados só pelo serviço (o endpoint, só pela matriz); nenhuma tela usa a presença ainda.

**Aprovado em 2026-10-06** pelo responsável, após revisão e teste em MySQL 8.0.46 real: ciclo, troca de regra, exceções, ausências, Liderança, fora do Mapa, horários no `status.php` e 93 dias em 5 ms. As onze decisões de implementação acima ficam aprovadas. Deploy 9 da fila.

**Ressalva (registrada pela IA; aceita pelo responsável em 2026-10-06):** a base foi tomada antes do deploy 2. Entre ela e o deploy do F2 (depois do deploy 8 da fila) entram os deploys 2 a 8, e dois deles mudam o mesmo caminho do `status.php`: o PERF-01 (trava do estado de pausas, deploy 2) e a revalidação da sessão por requisição (Lote 5a, deploy 6). Comparada com esta base, a medição depois do F2 soma o efeito de todos. Para isolar o F2, a recomendação é repetir a medição logo antes do deploy do F2, nas mesmas condições, e usá-la como base do critério; para fechar o PERF-01, medir de novo logo depois do deploy 2. **Decisão:** a base do critério do F2 é a medição repetida logo antes do deploy do F2, nas mesmas condições; o limite de piora é recalculado sobre ela (o maior entre p95 + 50 ms e p95 + 20%). A base de 2026-10-06 fica como referência e como o "antes" do PERF-01.

**Roteiro manual por lote no servidor** (o QA local não tem MySQL, Apache nem AD): aplicar a migration, rodar a consulta de verificação, exercitar a tela por perfil. Entregue junto de cada lote, como nos anteriores.

### 9.3 F2B — plano aprovado (2026-10-07) e entrega

**Ponto que define o escopo.** A partir do F2B dá para gravar regra `cycle`, e o `presenceForRule` antigo lança exceção para esse tipo: Calendário, Escala e Relatórios dariam 400 com a primeira regra de ciclo. Por isso o F2B cobre esses consumidores, mesmo que Calendário e Relatórios só troquem de vez no F5.

**Plano** (seções 2.2, 3.4 e 5.3):

| Item | Plano |
|---|---|
| Ciclo | Payload `cycle: { onsite_days, remote_days, starts_with, anchor_date }`; o servidor monta `pattern` (`starts_with` define o bloco que vem primeiro); cada número ≥ 1, total de 2 a 20; `anchor_date` em dia útil; `effective_from = anchor_date`; `rule_config` em ordem fixa (`pattern`, `anchor_date`, `business_days_only: true`), para salvar de novo a mesma regra não criar histórico. P10/L6 vale para a data inicial |
| Dias fixos em modo home | `input_mode: "home"` + `home_weekdays`; presenciais = seg a sex menos os dias de home; `weekdays` continua sendo a lista de presenciais; regras antigas sem `input_mode` ficam como estão |
| `action=rule_preview` | Mesmo payload, validações e permissões do `rule`; não grava; devolve `preview[]` dos próximos 10 dias úteis pelo motor, `rule_label` e `closes` (pelo planejador do F1) |
| Rótulos | "Ciclo 2P/1R"; "Presencial: seg, qua e sex · home ter e qui" |
| Tela Escala | `generated` pelo motor (`dayPresence` por data, sem relógio), com os campos antigos e mais `situacao`, `origem`, `detalhe` e `escala` |
| Calendário e Relatórios | Cálculo antigo até o F5; só o ciclo muda: dia útil pela posição do motor, fim de semana sem linha |
| Importação | Linha com ciclo recusada ("cadastre pela tela"): a planilha não tem colunas de configuração |
| Fila `portal_sync_queue` | Payload da regra de ciclo leva o `pattern` (não existe consumidor da fila hoje) |
| Rollback | Script reversível no lugar do rollback da 011 (que apagaria o histórico): ida `cycle` → `undefined` mantendo `rule_config`; volta reaplica `cycle` onde `rule_config` tem `pattern`; ensaio, regra do A4 e verificação só com contagens |
| Fora do F2B | Exceção só com remoto, presencial, treinamento e plantão (F3); Mapa PA no motor (F6); Calendário e Relatórios no motor, com a data de corte (F5) |

**Aprovado em 2026-10-07, com ajustes:**

1. **Decisão 6 corrigida:** `rule_preview` espelha exatamente as permissões do `rule`. Gestor, Liderança e admin passam (`portal_role_is_manager` no `schedules.php`); técnico e somente leitura, 403; prévia com início retroativo segue `escalas.retroativa` (Liderança e admin). Linha do `qa-security` ajustada.
2. **Decisão 1 aprovada, com teste obrigatório:** relatório operacional, CSV e Calendário com o mesmo resultado de antes para todas as regras que não são `cycle`, comparando a saída antes e depois do F2B. P3, P4 e ausência valem só na tela Escala neste lote.
3. **Decisão 2 ajustada:** no `PaMapService`, `ruleActiveOnDate` usa o motor para `cycle` (dia presencial ou não, para exibição e para o aviso de alocação remota). A checagem de conflito continua conservadora até o F6. Teste: pessoa com ciclo aparece presencial no dia certo e não gera aviso de remoto nesse dia.
4. **Decisões 3, 4 e 5 aprovadas** (importação recusa ciclo; modo home sem nenhum dia de home: 400 sugerindo "Sempre presencial"; rollback reversível). Na 4, todos os dias como home também dá 400, sugerindo "Sempre remoto".
5. **`pattern` no payload da `portal_sync_queue`:** aprovado.

**Entrega do backend (2026-10-07):** `fb53485` (referência da saída antiga, gravada **antes** de qualquer mudança, com o código de `c941f6c`), `2f8201e` (ciclo, modo home, prévia, tela Escala no motor, Calendário/Relatórios/Mapa PA com ciclo, importação, fila), `ee59899` (cópia do ciclo no Mapa PA dentro do ENUM) e `86c8d92` (rollback reversível, ensaio e verificação).

| Item | Como ficou |
|---|---|
| Teste obrigatório (ajuste 2) | `scripts/qa-schedule-legacy-output.php`: massa fictícia com todos os tipos antigos, histórico (troca no meio do período, escala removida, linha vazia), exceções de todos os tipos (inclusive no sábado), ausências aprovadas e pendentes, inativo, fora do Mapa e Liderança. Catorze saídas (relatório em quatro filtros, os quatro CSVs, Calendário em seis filtros) comparadas byte a byte, por sha256, tamanho e linhas, com a referência de `fb53485`. `--despejar DIR` grava as saídas para comparar arquivo a arquivo com o commit `c941f6c` |
| Prévia | `previewScheduleRule`: o mesmo `scheduleRuleRequest` do `rule` (L9, Liderança 409, tipo, configuração, retroativo, trava do colaborador e 409 de fora do Mapa) e o mesmo planejador; o histórico "como ficaria" vem de `ScheduleRulePlanner::applyPlan` (função pura, testada contra a gravação em 800 passos aleatórios) |
| Tela Escala | `engineSchedule`: todos os cadastros ativos de N1, N2 e Liderança fora da marcação "fora do Mapa", inclusive os sem regra; `presence_type` ganha `absence` (ausente, dia todo) e `no_schedule` (sem escala) |
| Mapa PA | Ciclo pelo motor na lista de elegíveis, na exibição do vínculo (`active_on_date`, `presence_status`, rótulo) e no aviso/confirmação de alocação remota; conflito pelo tipo, conservador (ciclo conflita com tudo, menos sempre remoto) |
| Rollback | `migrations/rollback/20261007_f2b_cycle_down.sql` e `_up.sql`, preparo do ensaio (só muda banco `ensaio_*`) e `migrations/verificacao/20261007_f2b_cycle_check.sql` |

**Decisões de implementação (aprovadas em 2026-10-07):**

1. **Cópia do ciclo no Mapa PA.** O ENUM de `portal_pa_assignments.schedule_rule_type` (migrations 009 e 010) não tem `cycle`: gravar o vínculo de quem tem ciclo daria erro 1265 no MySQL estrito (500). Sem migration nova, a cópia grava `undefined` com a configuração do ciclo, e a leitura reconhece o ciclo pelo `pattern` válido; vínculo `undefined` antigo, sem `pattern`, continua "Sem escala". Alternativa: migration ampliando o ENUM (custa ensaio e rollback, e o F6 tira a cópia de cena).
2. **Ciclo em dia remoto no Mapa PA** recebe a mesma confirmação e o mesmo aviso do "sempre remoto" (a mensagem já diz "nesta data"). Dias fixos e pares/ímpares em dia remoto continuam sem confirmação, como hoje, até o F6.
3. **`input_mode` não conta na comparação de regra igual.** Os mesmos dias informados como presenciais ou como home são a mesma regra: "nada foi alterado", sem histórico novo; o modo exibido continua o gravado.
4. **`effective_from` enviado junto com ciclo é ignorado:** vale a data inicial (seção 2.2).
5. **Limites do ciclo:** cada bloco de 1 a 19 dias, total até 20; números como inteiro ou texto só com dígitos (booleano e fração: 400).
6. **Prévia:** sem exceções (é a prévia da regra); começa no primeiro dia útil a partir do início; com regra agendada depois, os dias dela saem com o rótulo dela; trava o colaborador como o `rule` (para os 409 serem os mesmos); sem auditoria (não grava).
7. **Tela Escala:** fim de semana só aparece com exceção de trabalho (plantão no sábado), como a Liderança já fazia; ausência em fim de semana não aparece; ausência de `portal_absences` sai com `source = "absence"` e a de exceção com `source = "exception"`.
8. **Calendário e Relatórios com ciclo:** exceção vence o ciclo também no fim de semana (como nos outros tipos); ciclo com configuração inválida no banco não gera linha (sem 400) e aparece na tela como "Ciclo com configuracao invalida" (rótulo do código, sem acento como os demais).
9. **Fila:** a chave `pattern` só entra no payload do ciclo; os demais tipos mantêm o payload de antes.

Validação pela IA (backend): `qa-schedule-history`, `qa-schedule-legacy-output`, `qa-security` (matriz com `POST rule_preview`, prévia no serviço e de ponta a ponta: L9 e retroativo com 403) e a bateria completa; mutação **49/49** no backend; três mutantes de sanidade no caminho antigo (rótulo, ausência, `undefined`) mortos pela referência. Limitações: nada rodou em MySQL real (consultas, `JSON_VALID`/`JSON_CONTAINS_PATH` dos scripts de rollback e o ensaio); frontend do F2B em andamento.

**Backend aprovado em 2026-10-07** (`fb53485` a `6b078cd`) pelo responsável, após teste em MySQL 8.0.46 em modo estrito: prévia sem gravar; ciclo salvo e "sem alteração" na repetição; recusas (âncora no sábado, 21 dias, retroativo do gestor); modo home; tela Escala com ciclo; relatório antigo com ciclo sem 400; vínculo de PA de quem tem ciclo (cópia `undefined` + configuração) e confirmação no dia remoto; scripts down/up do F2B idempotentes, com a verificação batendo. **As nove decisões de implementação acima ficam aprovadas.**

**Entrega do frontend (2026-10-07; aprovada em 2026-10-07):** `2339988` (tela) e o commit de documentação (contratos, `DEPLOY_LINUX.md`, registro).

| Item | Como ficou |
|---|---|
| Formulário | Tipo "Ciclo presencial/remoto" com dias presenciais, dias remotos e "começa por"; o campo de data vira "Data inicial do ciclo". "Dias fixos" com "Informar: dias presenciais / dias de home office" e o resultado "presencial … · home …". Schema Zod espelha as recusas do servidor (mesmas mensagens) |
| Prévia | "Ver prévia" chama `rule_preview` pelo `runAction` (sem mensagem de sucesso); mostra a semana em P/R, o rótulo, a regra que será encerrada, "regra igual à vigente", o fim antes de uma regra agendada e o aviso de retroativo. **Salvar só habilita com a prévia dos valores atuais**: a chave da prévia é o payload; mudar qualquer campo marca "Prévia desatualizada" |
| Regras | Botão "Histórico" por colaborador abre um painel com todas as versões (`include_history=1`): vigente, agendada e encerrada, com quem alterou; "Editar" de um ciclo ou de dias em modo home volta com os campos preenchidos |
| Escala gerada | Status "Ausência" (com "Ausente (dia todo)") e "Sem escala"; origem "Ausência lançada", "Exceção de ausência", "Sem regra cadastrada"; nota sobre fim de semana e sem escala |
| Mapa PA | Tom visual do ciclo; o aviso de remoto do ciclo usa a confirmação que a tela já tem |
| QAs | `qa:schedule-form` (estrutura do fluxo, prévia obrigatória, histórico e testes de comportamento do schema por `safeParse`), `qa:schedule-rules` (ciclo e modo home na tela e no Mapa PA); `qa:operational`, `qa:visual`, `qa:action-runner` e `qa:action-feedback` atualizados de forma deliberada: o fluxo saiu de `saveRule` para `parseRule` (validação) e `buildRulePayload` (payload do `rule` e da prévia), com as mesmas garantias (rule_type canônico, Zod, payload sem objeto cru) |

**Decisões de implementação do frontend (aprovadas em 2026-10-07):**

1. **Prévia obrigatória para todos os tipos**, não só ciclo e modo home (a seção 5.3 a mostra no ciclo; o plano dizia "Salvar só fica habilitado depois da prévia"). O servidor não exige a prévia: é conveniência da tela.
2. **`src/lib/operational.ts` não mudou:** o tipo `ScheduleRuleType` não tem `cycle`, mas não é usado em lugar nenhum e o arquivo é protegido pelo `qa:schedule-form` (trava por `git diff`, dívida técnica registrada). Os cinco lugares da lista de tipos ficaram quatro com `cycle` (serviço, Mapa PA, schema e tela) mais esse tipo sem uso.
3. **Histórico** abre um colaborador por vez, com o período do filtro da tela (o `include_history=1` traz todas as versões do colaborador independentemente do período); linhas vazias antigas (fim antes do início) não aparecem.
4. **Legenda única da seção 5.1** fica para o F5 (Calendário); no F2B a tela Escala usa os status com texto ("Presencial", "Remoto", "Ausência", "Sem escala").

Validação pela IA (frontend): `lint`, `typecheck`, `build` e os 14 `qa:*`; mutação **17/17** (prévia sempre atual, salvar sem prévia, botão sempre habilitado, data inicial e dias do ciclo no payload, modo home, prévia gravando, prévia não limpa, opção do ciclo, histórico, cinco regras do schema, tom do Mapa PA). Limitação: **a tela não foi aberta no navegador** (sem PHP com MySQL local); comportamento visual e de interação só pelo roteiro "Deploy que traz o F2B" no servidor.

**Frontend aprovado em 2026-10-07** (`2339988`, `6354c8d`), após validação do responsável no navegador, com a tela ligada ao backend real e MySQL 8.0.46: prévia obrigatória (Salvar trava sem prévia e com "Prévia desatualizada"), ciclo com prévia e aviso de encerramento, sábado recusado na tela, modo home com resumo e prévia corretos, payload no formato do contrato, lista com Vigente/Agendada, Editar de ciclo recarregando os campos, histórico e regra fixa da Liderança; sem erro de JavaScript. **As quatro decisões do frontend ficam aprovadas. F2B completo**; entra na fila como deploy 10, depois do F2.

**Pendências anotadas para o F5** (sem mudança no F2B): (1) filtro "ocultar sem escala" na escala gerada (a lista cresce com a P4); (2) rótulos sem acento ("Dias impares", "Ciclo com configuracao invalida"), junto com o L-14; (3) quebra da palavra "Liderança" na coluna Equipe.

### 9.4 F3 — plano aprovado (2026-10-07), entrega e F3 completo (2026-10-08; deploy 11)

**Ponto que define o escopo.** Hoje `portal_absences` não tem caminho de escrita (0 linhas no servidor, Q3 de 2026-10-07), mas o motivo vaza para qualquer perfil por dois caminhos: `portal/absences.php` (SEC-07) e o bloco de ausências do `portal/calendar.php` (L-01). O F3 fecha a leitura antes de criar a escrita.

**Ordem dos commits:**

1. **Escopo de leitura (SEC-07, L-01), compatível com a tabela de hoje** (pode ir ao ar sozinho): `absences.php` com técnico e somente leitura vendo só as próprias (o `employee_id` vem da sessão); `calendar.php` sem o texto do motivo para nenhum perfil e com o tipo da ausência só para a gestão e para o próprio técnico (P9 e decisão 4).
2. **Migration 012**, com rollback, ensaio e verificação só com contagens (abaixo).
3. **Serviço e endpoint `absences.php`:** `GET` com filtros, escopo por perfil e limite de 500 com `truncated`; `POST` `create`, `update` e `cancel` (seção 3.3).
4. **Motor:** ausência parcial ("a partir de" e "intervalo") em `nowPresence` e `dayPresence`, com os testes de 13:59/14:00 (seção 4.4); `status.php` passa a mostrar "Ausente desde 14:00"; `presence.php` com a categoria (P9).
5. **P7:** a exceção de escala aceita só remoto, presencial, treinamento e plantão; tipo de ausência dá 400 apontando a tela de Ausências.
6. **Frontend:** página Ausências no lugar da tabela genérica, em React Hook Form e Zod (seção 5.4), com o aviso fixo no campo de observação; exceção de escala sem os tipos de ausência; subtítulo do menu sem "atestados".
7. **QA e documentação:** `qa-absences` (validações, sobreposição, retroativo, matriz de perfis, negativo em subprocesso), motor, matriz do `qa-security`, mutação; contratos, inventário LGPD (`DOCUMENTACAO_TECNICA.md`, 5.3), `DEPLOY_LINUX.md` e registro.

**Migration 012, com a tabela vazia (P8):**

| Item | Proposta |
|---|---|
| `absence_type` | **Removida** (sai o valor `atestado`, sem mapeamento) |
| `category` | `ENUM('folga','ferias','ausencia_justificada','compromisso','outro') NOT NULL` (Folga, Férias, Ausência justificada, Compromisso, Outro) |
| `status` | `ENUM('approved','cancelled') NOT NULL DEFAULT 'approved'` (`pending` e `rejected` só existiam para linhas legadas, que não há) |
| Colunas da 013 | **Não entram** (`source`, `legacy_exception_id` e a UNIQUE): a 013 foi dispensada |
| Colunas que ficam (seção 2.3) | `team ENUM('n1','n2','lideranca')`, `period_type`, `start_time`, `end_time`, `retroactive`, `created_by`, `updated_by`, `cancelled_by`, `cancelled_at`; índice `idx_absence_employee_range`; FK `fk_absence_employee` (`ON DELETE RESTRICT`; 0 órfãs) |
| Observação | Continua na coluna `reason` (500); na API, `note` |
| Premissa da tabela vazia | Conferida pela própria migration: com qualquer linha em `portal_absences`, para com `SIGNAL` antes de alterar (padrão do rollback da 011) |
| Rollback | Copia para `portal_absences_bkp_012`; recria `absence_type` a partir de `category`; `cancelled` vira `rejected`; o código anterior trata toda ausência aprovada como dia inteiro |

**Regras do serviço (seção 3.3, com o que mudou desde o desenho):**

| Regra | Proposta |
|---|---|
| Lançar, editar, cancelar | `ausencias.manage` (gestor, Liderança, admin); técnico e somente leitura: 403 antes de tocar no banco |
| Retroativa | Permissão nova `ausencias.retroativa` (Liderança e admin, L6). Lançar com início anterior a hoje, ou editar e cancelar ausência já iniciada: gestor 403; quem tem a permissão grava com `retroactive = 1` e auditoria WARNING |
| Ausência de cadastro da Liderança, ou de perfil Liderança ou admin | Só admin (A4 do Lote 5), pela mesma regra da L9 (`escalas.lideranca`) |
| Validações (400) | Fim ≥ início; no máximo 366 dias; horário só com um dia (P5); `from_time` exige início; `interval` exige início < fim; horário dentro da jornada do técnico (P1); categoria da lista; observação até 500 caracteres; cadastro inativo. Cadastro fora do Mapa continua com ausência (seção 0.6) |
| **Sobreposição (P6)** — aprovada em 2026-10-07 | **409** com o período em conflito na mensagem. Duas ausências aprovadas do mesmo colaborador se sobrepõem quando os períodos de datas se cruzam **e**: (a) alguma delas é de dia inteiro ou de mais de um dia, ou (b) as duas são parciais no mesmo dia e os horários se cruzam. "A partir de HH:MM" ocupa de HH:MM até o fim da jornada. Ausência cancelada não conta; na edição, a própria ausência não conta |
| Concorrência — aprovada em 2026-10-07 | Trava do colaborador (`FOR UPDATE`) e leitura das ausências dele também com trava (lição do F1: leitura que decide gravação depois da trava é leitura com trava) |
| Edição | Não troca o colaborador (cancelar e lançar de novo); ausência cancelada não é editada nem cancelada de novo (409) |
| Auditoria | `ABSENCE_CREATED` (INFO; WARNING se retroativa), `ABSENCE_UPDATED`, `ABSENCE_CANCELLED` e `ABSENCE_WRITE_DENIED` (WARNING): IDs, datas, período e categoria, **nunca o texto da observação** |

**Decisões:**

1. **(Aprovada em 2026-10-07)** P8: remover `absence_type` e `atestado`; `category` com as cinco categorias; `status` só `approved`/`cancelled`; sem as colunas da 013; a migration para com `SIGNAL` se houver linha.
2. **(Aprovada em 2026-10-07)** Exceções de ausência antigas (`day_off`, `absence`, `vacation`, `leave`) continuam lidas pelo motor como ausência de dia inteiro, só leitura; **repetir a Q4 logo antes do deploy do F3**.
3. **(Aprovada em 2026-10-07)** Retroativo de escala e de ausência para Liderança e admin (L6), não só admin como dizia a D3.
4. **(Aprovada em 2026-10-07)** Observação só na lista de Ausências (gestão e o próprio técnico); **nunca** na auditoria, no `presence.php` nem no calendário; aviso fixo no campo de que não se deve informar dado de saúde.
5. **(Aprovada em 2026-10-07)** Calendário até o F5: o evento de ausência mostra a categoria (hoje, o tipo) só para a gestão e para o próprio técnico; para os demais, só "Ausência" e o período; **só ausência aprovada** aparece (hoje aparece qualquer status). O commit 1 aplica só a parte já coberta pela P9 e pela decisão 4 (sem motivo; tipo só para gestão e o próprio); o filtro de status espera esta decisão.
6. **(Aprovada em 2026-10-07)** Teste da saída antiga do F2B: a referência inclui as ausências no Calendário, e a mudança SEC-07/L-01 altera esse bloco. Proposta: comparar o Calendário **sem os eventos de ausência** (a escala segue idêntica byte a byte), com as assinaturas novas geradas pelo código de `c941f6c` e as antigas mantidas sem alteração no arquivo; o bloco de ausências ganha testes próprios. O commit 1 já usa esta forma, porque sem ela o teste reprovaria por uma mudança de RBAC aprovada em 2026-09-30.
7. **(Aprovada em 2026-10-07)** Relatório e Calendário até o F5: ausência parcial **não** tira o dia inteiro da escala (o cálculo antigo tira qualquer ausência aprovada). Com a tabela vazia, nenhum número de hoje muda.
8. **(Aprovada em 2026-10-07)** O `team` da ausência nasce com `n1`, `n2` e `lideranca`; a equipe Crítico entra no F9, com os demais ENUMs.

**Commit 1 entregue (2026-10-07, `faaea06`; aprovado em 2026-10-07):** `PortalService::absences` com escopo por perfil e `absences.php` fora da listagem genérica; `listCalendar` recebe o ator, não lê nem envia o motivo e mostra o tipo só para a gestão e o próprio (com "atestado" como "Ausência justificada"). `qa-absences` (novo), casos de ponta a ponta no `qa-security` (gestor, Liderança, técnico pedindo outro colaborador pelo parâmetro, somente leitura) e a forma da decisão 6 no `qa-schedule-legacy-output` (as 14 assinaturas antigas regravadas com o código de `c941f6c` saíram idênticas; entraram 6 "sem ausência"). Mutação 10/10. **Aprovado em 2026-10-07** após teste do responsável em MySQL 8.0.46 real: lista com escopo por perfil (gestor vê todas com observação; técnico só as próprias; somente leitura sem colaborador, lista vazia sem consulta), calendário sem motivo para nenhum perfil, tipo só para gestão e o próprio, atestado como "Ausência justificada". As decisões 5 a 8, a regra de sobreposição e a trava com leitura `FOR UPDATE` foram aprovadas no mesmo dia.

**Achado fora do escopo → lote SEC-07b (decisão de 2026-10-07):** o `calendar.php` também envia a todos os perfis o texto livre de hora extra (`reason`), de correção de ponto (`justification`) e de plantão (`note`) como `description`, além do motivo das pausas. **SEC-07b, logo depois do F3 e antes do F4:** esses textos só para a gestão (gestor, Liderança, admin) e para o próprio colaborador, com o mesmo padrão de teste do commit 1 (serviço, ponta a ponta no `qa-security`, mutação).

**Filtro "só aprovada" e migration 012 (2026-10-07; aprovados em 2026-10-07):** `0b0f951` (calendário só com ausência aprovada; `status` de outro valor não traz ausência nem consulta a tabela) e `c69c5ec` (012, rollback, verificação, ensaio).

| Item | Como ficou |
|---|---|
| Migration `20261007_012_absences.sql` | Como na tabela da P8 acima. Parada por `SIGNAL` (procedimento temporário `migration_012_confere_vazia`) **se a coluna antiga `absence_type` existe e a tabela tem linha**: aplicada, repete sem mudar nada (idempotente). `team`, `category` e `created_by` `NOT NULL` (a tabela está vazia e o serviço sempre grava). Índice antes da FK |
| Rollback | Para se `portal_absences_bkp_012` já existir; copia tudo; `absence_type` a partir de `category` (`folga`, `ferias`; o resto `justificada`; `atestado` nunca é recriado), `NOT NULL` depois de preencher; amplia o `status`, `cancelled` vira `rejected`, depois encolhe; tira colunas, FK, índice e CHECKs |
| Verificação | `migrations/verificacao/20261007_012_absences_check.sql`: estrutura em qualquer estado; dados (aprovadas, canceladas, parciais, retroativas, órfãs, período inválido, sobreposições aprovadas pela regra da P6) só com as colunas da 012, por consulta preparada; nenhum nome nem observação |
| Ensaio | Três variantes, com os comandos nos cabeçalhos: (a) tabela vazia (012 duas vezes; o 2º rollback para de propósito); (b) `..._ensaio_linha_antiga.sql` como PREPARO: a 012 deve parar nas duas vezes sem alterar; (c) a 012 como PREPARO e `..._ensaio_linhas_f3.sql` no lugar da migration: rollback com quatro ausências no formato do F3 (uma cancelada, duas parciais). Os dois arquivos só gravam em banco `ensaio_*` |
| Leitura | Lista e calendário passaram a `category` (o HEAD fica coerente com o esquema da 012); a tela genérica de `/ausencias` mostra a categoria até a página própria (commit 6) |

**Decisões de implementação da 012 (aprovadas em 2026-10-07):**

1. **CHECKs no banco** (`ck_absence_range`: fim ≥ início; `ck_absence_period`: horário só com um dia, `from_time` com início e sem fim, `interval` com início < fim, dia inteiro sem horário). Não estavam na tabela da P8; seguem a regra de integridade no banco. A sobreposição (P6) continua no serviço.
2. **`team`, `category` e `created_by` `NOT NULL`** (a seção 2.3 previa `team` e `category` nulos por causa do legado, que não existe).
3. **Janela entre a 012 e o `git pull`:** o código anterior ao F3 lê `absence_type` na lista genérica de `/ausencias`; nesse intervalo a tela responde 503 ("A base do portal ainda não está disponível"). Calendário e escala não usam a coluna.
4. **Parada condicionada à coluna antiga:** a 012 só para com linha **antes** de ser aplicada; depois, repete sem parar (senão a 2ª execução falharia assim que o F3 gravasse a primeira ausência).
5. **`approved_by`** fica como está (sem uso; remover não estava no plano).

Validação pela IA: `qa-absences` seção 4 (ordem da parada, idempotência por `information_schema`, regra do A4, ENUMs, FK, CHECKs, ordem do rollback, toda variável de `PREPARE` atribuída antes, verificação só leitura e sem nome nem observação, ensaio só em `ensaio_*` e idempotente); mutação **16/16** nos arquivos SQL (um sobrevivente na 1ª rodada, `PREPARE` de variável sem `SET`, levou à checagem genérica) e **12/12** na leitura.

**Aprovados em 2026-10-07** (`0b0f951`, `c69c5ec`) pelo responsável, após os três ensaios no MySQL 8.0.46 com o `ensaio-migration.sh`: (a) tabela vazia: migration duas vezes idêntica, rollback volta tudo, 2º rollback para de propósito; (b) linha antiga: a 012 para nas duas execuções sem alterar nada; (c) rollback com 4 ausências do F3: volta com a cancelada como `rejected`, 2º para de propósito. Conferido também que o banco recusa fim antes do início, horário em vários dias, intervalo invertido, categoria `atestado`, colaborador inexistente (FK) e status `pending`. **As cinco decisões da 012 ficam aprovadas.** No roteiro de deploy do F3: **a 012 e o `git pull` do F3 rodam em sequência, sem pausa** (janela de 503 na tela de ausências, decisão 3).

**Commit 3 entregue (2026-10-07, `833c0cf`; aprovado em 2026-10-07):** serviço e endpoint de ausências.

| Item | Como ficou |
|---|---|
| Regras puras | `services/AbsenceRules.php`: validação do payload (datas, até 366 dias, P5, `from_time` com início, `interval` com início < fim, categoria, observação até 500 caracteres), jornada (P1), sobreposição (P6, como aprovada), mensagem do 409 só com o período |
| Serviço | `services/AbsenceService.php`: `list` (filtros e escopo da seção 3.3), `create`, `update`, `cancel`. Ordem nas gravações: retroativo recusado antes do banco (quando decidível pelo payload) → transação → trava do colaborador → (edição e cancelamento) releitura da ausência com `FOR UPDATE` → A4 → status → retroativo pela ausência já iniciada → jornada → sobreposição com leitura `FOR UPDATE` → gravação → auditoria |
| Endpoint | `GET` = lista; `POST` nega por perfil (`ausencias.manage`) antes de ler o corpo e de abrir o banco, com `ABSENCE_WRITE_DENIED`; recusa do serviço (403) também auditada; `create` 201 |
| Permissões | `ausencias.retroativa` (Liderança e admin); `absence_change_denied` (A4) com `escalas.lideranca`, como a L9 |
| Removido | Lista genérica de ausências do `PortalService` (substituída pelo serviço) |

**Decisões de implementação do commit 3 (aprovadas em 2026-10-07):**

1. **Ausência que começa hoje já está iniciada:** editar ou cancelar é retroativo (gestor 403; Liderança e admin gravam marcada). Lançar começando hoje não é retroativo.
2. **`retroactive` nunca volta a 0:** editar ou cancelar mantém a marca e a acrescenta quando a operação é retroativa.
3. **Ausência de cadastro desativado:** lançar nova dá 400; **editar e cancelar continuam** (o F4 cancela as futuras no arquivamento; até lá, a gestão consegue corrigir).
4. **Autorização antes do estado do registro:** na edição e no cancelamento, a regra A4 é conferida antes de dizer se a ausência está cancelada ou se o colaborador mudou.
5. **Equipe sem suporte** (por exemplo, `critico`, antes do F9) e **cadastro sem jornada válida com ausência por horário**: 400 com mensagem; dia inteiro continua para quem não tem jornada.
6. **A lista não é auditada** (leitura); as recusas de escrita são.
7. **Observação contada em caracteres UTF-8 sem depender da `mbstring`.** Achado: esta estação não tem a extensão, e o padrão do projeto (`function_exists('mb_strlen') ? ... : strlen(...)`) conta bytes no fallback, em sete serviços; texto acentuado perto do limite seria recusado à toa. Só o `AbsenceRules` mudou; **confirmar se o PHP do servidor tem `mbstring`** (se não tiver, os outros seis ficam como pendência).

Validação pela IA: `qa-absences` (lista com filtros, escopo e limite; regras; escrita com retroativo, A4, cadastro, jornada, P6, edição, cancelamento; concorrência com o REPEATABLE READ emulado: a edição que esperou a trava vê a ausência gravada por outra transação e a cancelada por outra) e `qa-security` (matriz com `POST`, permissões, A4 em tabela-verdade, ponta a ponta: técnico e somente leitura 403 com auditoria, retroativa do gestor 403, sem CSRF 403). Mutação **43/45**; os dois restantes são equivalentes (dia inteiro e vários dias já cobrem a janela inteira na comparação de horário; ausência de vários dias é sempre de dia inteiro pela validação e pelo CHECK).

**Aprovado em 2026-10-07** (`833c0cf`) pelo responsável, após teste em MySQL 8.0.46 com a 012 aplicada: permissões (gestor lança; retroativa do gestor 403 e da Liderança gravada como retroativa; ausência de líder só pelo admin), sobreposição (dia inteiro × parcial 409; "a partir de 14:00" × 10–12 grava; × 13–15 409; relançar depois de cancelar), jornada e categoria `atestado` recusadas, lista do técnico só com as próprias, duas gravações simultâneas (a segunda viu a primeira e recebeu 409) e auditoria sem o texto da observação. **As sete decisões do commit 3 ficam aprovadas.** **`mbstring`:** presente no PHP-FPM 8.4 e no CLI 8.4 do servidor (conferido pelo responsável em 2026-10-07); a falta da extensão é só da estação de desenvolvimento, e os outros seis serviços não viram pendência.

**Commit 4 entregue (2026-10-07, `f89c443`; aprovado em 2026-10-08 com um ajuste):** ausência parcial no motor, no `status.php`, no `presence.php` e na tela Escala.

| Item | Como ficou |
|---|---|
| Motor | "A partir de" ativa do horário até o fim do expediente; "intervalo" em início ≤ agora < fim. Precedência da seção 4.3: ausência ativa vence fim de semana, fora do expediente, pausa e almoço; dia inteiro vence parcial; entre parciais ativas, a primeira por id. Ativa: "Ausente desde 14:00" ou "Ausente 10:00–12:00" |
| Marcador | Parcial que ainda vai começar hoje, ou de outra data: a escala do dia continua e o detalhe ganha " · Ausente a partir de 14:00" ou " · Ausente 10:00–12:00"; em `ausencias[]`, inativa e com horário. **Qual parcial (ajuste de 2026-10-08):** a de menor início que ainda vai começar (início > agora; em outra data, a de menor início do dia), com desempate por id; a entrega pegava a primeira por id |
| P9 | `presence.php` recebe quem consulta: categoria para gestor, Liderança, admin e o próprio colaborador; para os demais e sem quem consulta, nenhuma. `status.php` nunca leva categoria. Observação nunca entra no motor |
| Repositório | Ausências com período e categoria, ordenadas por id; continua com 4 consultas e sem a observação |
| Tela Escala | Parcial como marcador no detalhe; dia inteiro continua "Ausência" |
| Cálculo antigo (decisão 7) | Relatório, CSV e Calendário até o F5: só ausência de dia inteiro tira o dia; a saída antiga continua idêntica à referência |

**Decisões de implementação do commit 4 (aprovadas em 2026-10-08):**

1. **Marcador acrescentado ao detalhe da escala** ("Presencial (sempre presencial) · Ausente a partir de 14:00"), em vez de substituí-lo, para a tela mostrar a escala e a ausência futura juntas.
2. **"A partir de" sem jornada válida** no cadastro vale até o fim do dia (o serviço só deixa lançar parcial com jornada válida; o caso é de dado antigo ou jornada apagada depois).
3. **Parcial com horário inválido no banco** nunca fica ativa e não gera marcador (o CHECK da 012 impede esse dado; o motor não falha).
4. **Fim de semana:** parcial ativa vence ("Ausente 10:00–12:00", como o dia inteiro na decisão 2 do F2); parcial futura no fim de semana mostra só "Fim de semana", sem marcador.
5. **Intervalo encerrado** não deixa marcador: volta à precedência seguinte (almoço, escala).
6. **`ausencias[]`:** `start_time`/`end_time` só nas parciais, `category` só quando visível; o formato do dia inteiro não mudou (contrato do F2).

Validação pela IA: `qa-presence-engine` com **162 verificações** (36 novas: bordas 13:59/14:00, 09:59/10:00/11:59/12:00, 16:59/17:00 e 07:30, precedência, fim de semana, sem jornada, horário inválido, outra data, contadores, P9 por perfil, `status.php`, 4 consultas), `qa-schedule-history` (tela com parcial) e `qa-schedule-legacy-output` (decisão 7 no relatório antigo, referência idêntica). Mutação **18/20**; os dois restantes são equivalentes (colaborador de id 0 nunca está na carga; a exceção de ausência vem sempre primeiro e as parciais têm a mesma origem das de dia inteiro, por isso a função auxiliar que criei para isso foi removida). Nada rodou em MySQL real.

**Aprovado em 2026-10-08** (`f89c443`) pelo responsável, após teste em MySQL 8.0.46 com a 012 (162 verificações): bordas 09:59/10:00/11:59/12:00/13:59/14:00/16:59/17:00, precedência sobre pausa e almoço, dia inteiro com parcial, parcial de sábado, categoria por perfil (gestor, Liderança, admin, próprio, outro técnico, somente leitura) e `status.php` sem categoria; a observação não vazou em nenhum caso. **As seis decisões do commit 4 ficam aprovadas como estão.**

**Ajuste pedido na aprovação (2026-10-08):** o marcador pegava a primeira parcial pela ordem de id, não a próxima no horário. Caso real: "a partir de 14:00" com id menor e intervalo 10:00–12:00 com id maior no mesmo dia; às 09:59 o detalhe mostrava " · Ausente a partir de 14:00", quando o próximo evento é o das 10:00. Agora o marcador é a parcial de menor início que ainda vai começar (início > agora; em outra data, a de menor início do dia), com desempate por id. **Não mudam:** a ordem de `ausencias[]` na resposta (por id) e a regra "a primeira vale" da ausência ativa. `qa-presence-engine` com **166 verificações** (4 novas: hoje às 09:59 e outra data com o id da parcial das 14:00 menor que o da parcial das 10:00; às 13:30, com o intervalo já encerrado, o marcador passa às 14:00; mesmo início, vale a de menor id). Mutação **7/7**: volta à primeira por id (morta pelos dois casos, também separadamente: só hoje e só outra data), maior início, empate pelo último, sem o filtro do horário passado, fim do intervalo lido da parcial errada. Nada rodou em MySQL real.

**Commit 5 entregue (2026-10-08, `393c35c`; aprovado em 2026-10-08):** P7 no validador da exceção de escala (`OperationalService::saveScheduleException`, único caminho de gravação, via `schedules.php` `action=exception`).

| Item | Como ficou |
|---|---|
| Tipos aceitos | `remote`, `onsite`, `training`, `oncall` |
| Tipos de ausência | `day_off`, `absence`, `vacation`, `leave`: 400 "Folga, falta, férias e afastamento não são exceção de escala: lance na tela Ausências." (constante `ABSENCE_EXCEPTION_MESSAGE`) |
| Tipo desconhecido | 400 "Tipo de excecao invalido.", como antes |
| Ordem | Cadastro → autorização (L9) → data → tipo → Liderança remota (409) → trava → gravação; a recusa não trava nem grava |
| Leitura | Sem mudança: exceções desses tipos já gravadas continuam ausência de dia inteiro no motor, no Mapa PA e no cálculo antigo (decisão 2) |

**Decisões de implementação do commit 5 (aprovadas em 2026-10-08):**

1. **Autorização antes do tipo:** gestor lançando folga para cadastro da Liderança recebe 403 (L9), não 400, como na decisão 4 do commit 3.
2. **Lista de tipos de ausência vem de `PresenceEngine::ABSENCE_EXCEPTIONS`** (mesma lista que o motor lê como ausência), para leitura e recusa não divergirem.
3. **Sem auditoria própria da recusa:** é validação de payload (400), como os demais 400 da escala; a gravação válida continua com `SCHEDULE_EXCEPTION_SAVED`.
4. **Sobrescrever exceção antiga de ausência com tipo de trabalho na mesma data continua permitido** (o `ON DUPLICATE KEY UPDATE` não mudou); a Q4 deu 0 linhas, então hoje não há caso.
5. **Janela entre o commit 5 e o 6:** a tela de exceção ainda oferece os tipos de ausência até o commit 6 (frontend) e, se escolhidos, recebe o 400 com a mensagem. Os dois vão no mesmo deploy (11).

Validação pela IA: `qa-schedule-history` (os quatro tipos de ausência para gestor e admin com 400, sem gravar nem travar; L9 antes do tipo; tipo desconhecido; os quatro tipos de trabalho gravados) e `qa-security` (ponta a ponta: gestor com `day_off` pela rota real recebe 400 com a mensagem). Bateria PHP completa OK. Mutação **10/10** (sem a checagem, lista antiga de volta, tipo antes da autorização, cada tipo de trabalho removido, 409 em vez de 400, mensagem sem a tela, só `day_off`). Nada rodou em MySQL real.

**Aprovado em 2026-10-08** (`393c35c`) pelo responsável, após teste em MySQL 8.0.46 com a 012: `day_off`, `absence`, `vacation` e `leave` dão 400 com a mensagem e não gravam; "folga" continua tipo inválido; `remote` e `oncall` gravam (o segundo sobrescreve); gestor lançando `day_off` na Liderança recebe 403 antes do tipo; a `vacation` antiga gravada continua "Ausente (dia todo)". `qa-presence-engine` com 166 verificações e `qa-schedule-history` OK. O ajuste do marcador (`883f7a4`) foi testado no mesmo dia (às 09:59, "· Ausente 10:00–12:00"; às 13:59, "· Ausente a partir de 14:00") e **aprovado**. **As cinco decisões do commit 5 ficam aprovadas como estão.** Pedido na aprovação: o espaço que faltava em `OUTSIDE_MAP_MESSAGE =` (`OperationalService.php`) entra no commit 6.

**Commit 6 entregue (2026-10-08, `dcfa24c`; aprovado em 2026-10-08):** página de Ausências e exceção de escala sem os tipos de ausência.

| Item | Como ficou |
|---|---|
| Página | `frontend/src/pages/AbsencesPage.jsx` (carregada sob demanda), no lugar da tabela genérica de `/ausencias`. Filtros: período (padrão: mês corrente, como o servidor), equipe e técnico (só gestão) e situação (Ativas, Canceladas, Todas). "Nova ausência" abre o formulário; "Editar" abre com os dados da linha |
| Formulário | React Hook Form + `absenceFormSchema` (Zod, `formSchemas.ts`), erro junto de cada campo. Limites do `AbsenceRules`: fim igual ou depois do início; até 366 dias; horário só com um dia (P5: "A partir de" e "Intervalo" desabilitados com vários dias, que voltam a "Dia todo"); intervalo com fim depois do início; horário dentro da jornada do técnico (P1); início anterior a hoje só com `ausencias.retroativa` (data mínima no campo para os demais e marca "Lançamento retroativo (auditado)" para Liderança e admin); categoria da lista; observação até 500 caracteres |
| Aviso | "⚠ Não registre informação médica ou de saúde neste campo." fixo logo abaixo da observação, ligado ao campo por `aria-describedby`, com contador de caracteres |
| P9 | Categoria e observação só para a gestão (gestor, Liderança, admin) e para o próprio técnico; para os demais, "Ausência". Técnico e somente leitura: só a lista (o servidor já devolve só as próprias), sem formulário, sem ações e sem os filtros de equipe e técnico |
| Ações | Editar e Cancelar; cancelar pede confirmação na própria linha (sem `window.confirm`). Recusa do servidor (400, 403, 409) também junto do formulário |
| Exceção de escala (P7) | Só Remoto excepcional, Presencial excepcional, Treinamento e Plantão (Liderança continua sem remoto), com a nota "Folga, falta, férias e afastamento não são exceção de escala: lance na tela Ausências". Se o 400 do servidor chegar, a mensagem aparece junto do formulário (além do aviso flutuante) |
| Menu | Subtítulo "Folgas, férias, ausências justificadas e compromissos." (sem "atestados") |
| Ajuste pedido | `OUTSIDE_MAP_MESSAGE = '...'` com o espaço |

**Decisões de implementação do commit 6 (aprovadas em 2026-10-08):**

1. **Celular em cartões.** A tabela (largura mínima de 760 px) escondia categoria, situação e ações atrás da rolagem lateral; abaixo de `md` a lista vira cartões com os mesmos campos e as mesmas regras de P9. Não estava na seção 5.4.
2. **Jornada do Zod vem de `listar_funcionarios.php`.** Esse endpoint devolve 08:00–17:00 quando o cadastro não tem jornada, e o servidor recusa ausência por horário com jornada inválida: nesse caso raro a tela deixa passar e o 400 do servidor aparece junto do formulário. Sem a jornada na tela (cadastro desativado, na edição), o Zod não confere o horário e o servidor decide.
3. **Ações da linha antecipam o 403:** ausência já iniciada (início até hoje) sem `ausencias.retroativa` e ausência da Liderança sem `escalas.lideranca` mostram o motivo em vez dos botões. Cadastro N1/N2 com perfil de acesso Liderança ou admin o gestor não enxerga (a lista de funcionários não devolve o perfil ao gestor): o 403 vem do servidor e aparece junto do formulário.
4. **P9 também na tela**, além do servidor: categoria e observação só quando quem vê é gestão ou o próprio (`ci.funcionario_id`), mesmo que a resposta traga a linha.
5. **Mensagem do servidor duas vezes** (junto do formulário e no aviso flutuante do `runAction`), de propósito: o aviso some em 5 s; o texto do 409 com o período em conflito fica no formulário até a próxima tentativa.
6. **Edição não troca o técnico:** o campo vira texto com a orientação "cancele e lance de novo" (o servidor recusa a troca).
7. **Observação sem `maxLength` no navegador:** o atributo conta unidades UTF-16 e recusaria antes do servidor texto com emoji; o contador e o Zod contam pontos de código, como o `AbsenceRules`.
8. **Lançamento só para cadastro ativo**; cadastro fora do Mapa continua na lista (seção 0.6).
9. **Data de hoje da tela é a do navegador** (`localDate`); o servidor usa `America/Sao_Paulo`. Estação em outro fuso pode ver a data mínima diferente; o servidor decide.
10. **Dois QAs anteriores ajustados:** em `qa-schedule-rules` (F1), "exceção remota não é oferecida para líder" procurava a marcação antiga do `<select>` e passou a procurar o filtro sobre `EXCEPTION_TYPE_OPTIONS` (mesmo comportamento); `qa-forms` passou a aceitar `AbsencesPage.jsx` entre os arquivos com React Hook Form.

Validação pela IA: `qa:absences-form` (novo): limites comparados com `AbsenceRules.php` (categorias e rótulos, 366 dias, 500 caracteres, tipos de período), schema em casos de borda (366/367 dias, 29/02/2028, hoje não é retroativo, 07:59/08:00/16:59/17:00, intervalo até 17:00 e 17:01, jornada invertida, 500 emojis, observação aparada, data inexistente, `atestado`), estrutura da página (RHF, Zod, erro junto de cada campo, aviso ligado ao campo, P9 num único ramo usado pela tabela e pelos cartões, confirmação na linha, permissões pela sessão) e P7 (tipos da tela iguais aos aceitos por `saveScheduleException`; nenhum de `PresenceEngine::ABSENCE_EXCEPTIONS` no formulário). Todos os `qa:*` do frontend, lint, typecheck e build OK; bateria PHP completa OK. Mutação **30/30**. Prints em servidor simulado (dados fictícios, escopo por perfil igual ao do serviço): gestor e técnico, desktop (1366 px) e celular (390 px), incluindo a validação do Zod e o 409 do servidor junto do formulário. **Nada rodou contra o backend real nem em MySQL.**

**Aprovado em 2026-10-08** (`dcfa24c`) pelo responsável, após teste no navegador com o build ligado ao `AbsenceService` real e ao MySQL local com a 012 (sessão simulada por perfil). Gestor: erro de jornada e de data retroativa junto do campo; vários dias desabilitam "A partir de" e "Intervalo" e voltam a "Dia todo"; lançar, editar e cancelar (com confirmação na linha) funcionaram com o payload certo; o 409 de sobreposição apareceu no formulário e no aviso flutuante; ausência já iniciada e da Liderança mostram o motivo no lugar dos botões; no celular, cartões sem rolagem lateral. Exceção de escala só com os quatro tipos de trabalho, com a nota. Técnico vê só as próprias, sem formulário; `POST` de técnico e de somente leitura recebe 403. **As dez decisões do commit 6 ficam aprovadas como estão.** Pedidos para o commit 7: mensagens de `AbsenceRules` e `AbsenceService` que chegam ao usuário com acento (logs e `audit_log` como estão) e conferir a segunda camada da P9 na tela para somente leitura recebendo linha alheia.

**Commit 7 entregue (2026-10-08; aprovado em 2026-10-08):** ajustes pedidos (`d184596`), QA final do F3, documentação e roteiro do deploy 11 (commit de documentação).

| Item | Como ficou |
|---|---|
| Mensagens acentuadas (`d184596`) | Todas as mensagens de `AbsenceRules` e `AbsenceService` que viram 400 ou 409 ("Já existe ausência aprovada do colaborador...", "Horário só pode ser informado em ausência de um dia...", "A jornada do cadastro é inválida...", "Ausência não encontrada.", "Colaborador não encontrado ou inativo.", "Não é possível trocar o colaborador...", filtros da lista e validações do payload), mais "Ação de ausência inválida." do endpoint. O período da mensagem de conflito é o mesmo texto da auditoria, sem mudança; nenhuma linha de `audit_log` ou `error_log` mudou |
| Regressão das mensagens | `qa-absences` varre os textos literais dos três arquivos (sem comentários, sem SQL e sem chaves `x=y` da auditoria) e reprova palavra sem acento ("nao", "invalido", "ausencia", "horario"...). `qa-security`: `create` com `atestado` e ação desconhecida pela rota real devolvem 400 com a mensagem acentuada inteira no JSON, sem auditoria |
| P9 na tela (`d184596`) | A regra sai da página para `canSeeAbsenceDetails(session, item)` em `roles.ts` (sem mudança de comportamento) e ganha teste por perfil: gestão vê tudo; técnico e somente leitura só a própria linha (também com id em texto); linha alheia, sessão sem colaborador, linha sem colaborador, sessão sem o bloco `ci` e perfil desconhecido: nada. A tabela e os cartões usam o mesmo ramo |
| Contratos | `API_CONTRACTS_FRONTEND.md`: `portal/absences.php` na tabela de contratos em uso (página de Ausências, erros acentuados); limite da lista corrigido para 500 (a lista genérica de 100 não vale mais para ausências) |
| Inventário LGPD | `DOCUMENTACAO_TECNICA.md` 5.3: controles do F3 (sem `absence_type`/`atestado`, observação só na lista para gestão e próprio, aviso junto do campo, SEC-07 fechado) e o que continua a definir (base legal e retenção); perfis com `ausencias.retroativa` e o alcance de `ausencias.manage` |
| Deploy 11 | `DEPLOY_LINUX.md`, roteiro "Deploy 11 - Parte B, F3 (ausencias)": pré-requisitos (deploys 1 a 10; conferência da 011), backup, ensaio da 012 nas três variantes (`DADOS`, `VERIFICA`, `PREPARO`) com os números esperados, Q4 antes da 012, 012 e `git pull` no mesmo comando, build do frontend, recarga do PHP-FPM, verificação só com contagens, o que fazer em cada falha e rollback da 012. Remissões nos itens 4, "Rollback" e "Ensaio de Migration" |

**Decisões de implementação do commit 7 (aprovadas em 2026-10-08):**

1. **"Ação de ausência inválida." também acentuada** (`api/portal/absences.php`): não estava entre os dois arquivos pedidos, mas chega ao usuário pela mesma rota.
2. **P9 da tela numa função de `roles.ts`**, para ser testada por comportamento (o pedido era conferir; o teste antigo era só de estrutura). A regra é a mesma do commit 6.
3. **Ensaio antes do `git pull` com os arquivos da versão nova** exportados por `git archive origin/<BRANCH> migrations` para um diretório novo em `/root/ensaio-f3.*` (o script do servidor não mudou no F3). Apagar o diretório no fim fica no roteiro, com a conferência do caminho.
4. **Q4 com a contagem de `portal_absences` junto:** antecipa a parada da 012 (premissa da tabela vazia). Resultado da Q4 com tipos de ausência: o deploy segue (decisão 2) e o número vai para o registro; a mesma Q4 no pós-deploy tem que dar as mesmas contagens (a P7 não deixa crescer).
5. **012 e `git pull` num só comando, com `&&`:** o `pull` só roda se a 012 terminar sem erro (o cliente `mysql` sai com erro no `SIGNAL`). O commit do F3 fica gravado em `/var/backups/chronodesk/f3-commit`, de onde o rollback da 012 lê os arquivos depois do `git checkout` do código anterior.
6. **Sem recarga do Apache:** o F3 não muda vhost, `.htaccess` nem `.env` (conferido no `git diff` do F3).
7. **Pós-deploy com gravação só opcional:** lançar e cancelar uma ausência futura de um cadastro de teste (o registro fica como cancelado; nada é apagado).
8. **Rollback com ausências gravadas (achado):** o código anterior ao F3 mostra a observação (`reason`) a qualquer perfil no Calendário (lê a coluna sem filtro de perfil nem de situação), e a lista de Ausências também, se a 012 for revertida. O roteiro recomenda corrigir para frente e deixa a decisão sobre as observações com o responsável; com `linhas = 0`, o rollback do código é seguro.

**Achados sem alteração (arquivos aprovados):** o comando de ensaio no cabeçalho de `migrations/20261007_012_absences.sql` saiu numa linha só, com `--` no lugar das quebras `\` (copiado assim, não roda; o roteiro traz o comando certo); o cabeçalho de `migrations/verificacao/20261007_012_absences_check.sql` diz "fk, indice e checks = 1 cada", mas `checks` devolve **2** depois da 012 (duas constraints); o roteiro usa 2.

Validação pela IA (QA final do F3): bateria PHP completa (`qa-absences`, `qa-auth`, `qa-overtime`, `qa-presence-engine` com 166 verificações, `qa-schedule-history`, `qa-schedule-legacy-output`, `qa-security`, `qa-smoke`, `qa-timezone`), os 15 `qa:*` do frontend, lint, typecheck e build. Mutação: mensagens 4/4 (três arquivos e "posterior a inicial") e 1/1 ponta a ponta; P9 da tela 7/7; página e exceção do commit 6 29/29 (a mutação "P9 aberta" do commit 6 virou as 7 da função). Nada rodou em MySQL real nem no servidor; o roteiro do deploy 11 não foi executado.

**Aprovado em 2026-10-08** (`d184596`, `5fb5349`, `f1b28a2`) pelo responsável: mensagens acentuadas e P9 da tela aprovadas; **as oito decisões do commit 7 ficam aprovadas**. Na decisão 8 vale o que o roteiro diz: com `linhas = 0` o rollback do código é seguro; com ausências gravadas, corrigir para frente, e **qualquer rollback só com aprovação prévia do responsável**.

**Ajustes do roteiro do deploy 11 pedidos na aprovação (commit de documentação):**

| Ajuste | Como ficou |
|---|---|
| 1. `git show ... \| mysql ... && git pull` deixava o `pull` rodar se o `git show` falhasse (o `mysql` recebe entrada vazia e sai com 0; o responsável reproduziu) | Passo 5 e os dois comandos do rollback da 012: `git cat-file -e` confere o arquivo antes, e a aplicação roda em `bash -o pipefail -c '...'`; o `merge` (ou a mensagem "rollback da 012 aplicado") só vem se o `git show` e o `mysql` derem certo. Simulado na estação com `git` e `mysql` falsos: só o caso sem falha chega ao `merge`; `git show` com falha, `mysql` com erro e arquivo inexistente param; sem `pipefail`, o caso do `git show` com falha chega ao `merge` (o defeito) |
| 2. `git pull --ff-only` trocado por `git merge --ff-only origin/<BRANCH>` | O código aplicado é o commit do ensaio, sem novo `fetch` no meio. O passo 3 confere o branch atual (`git rev-parse --abbrev-ref HEAD`; para se não for o `<BRANCH>`) e grava `origin/<BRANCH>` em `f3-commit`; depois do `merge`, o passo 5 confere `HEAD = f3-commit`. O item "a 012 terminou mas o `git merge` falhou" manda resolver sem `fetch` nem `pull`, refazer só o `merge` e parar se `origin/<BRANCH>` tiver mudado |
| 3. Conferência da 011 com `-p` interativo dentro do `docker exec -i` | `read -rsp`, `MYSQL_PWD` e `unset P`, como o resto do roteiro |

As variáveis `P` e `F3` vão para o `bash -c` por `export` (o `unset P` no fim de cada comando continua).

**F3 completo** (2026-10-08): commits `faaea06` a `d184596`, mais `5fb5349` (QA) e a documentação; **deploy 11 da fila**, pelo roteiro "Deploy 11 - Parte B, F3 (ausencias)" do `DEPLOY_LINUX.md`. Repetir a Q4 logo antes da 012 (passo 4). Próximo lote: **SEC-07b**, antes do F4.

**Fora do F3:** arquivamento e cancelamento de ausências futuras (F4); legenda única e ausência como situação no Calendário (F5); conflito e ausências da semana no Mapa PA (F6); contadores (F7).

### 9.5 SEC-07b — texto livre no Calendário e na lista de plantões: plano aprovado (2026-10-09) e entrega

Achado do F3 (seção 9.4, decisão de 2026-10-07). O plano foi entregue no chat em 2026-10-08 e reenviado em 2026-10-09; fica gravado aqui com o ajuste da aprovação.

**Escopo.** (a) `listCalendar`: hoje o `calendar.php` envia a todos os perfis, em `description`, o motivo da hora extra (`reason`), a justificativa do ajuste de ponto (`justification`), a observação do plantão (`note`) e o motivo da pausa (`motivo_pausa`). (b) `oncall.php` GET: `listOncall($_GET)` devolve a todos `note`, `created_by` e `updated_by` (a tela não mostra, o JSON traz). **Fica como está:** eventos manuais e sala de guerra (texto da gestão para divulgar), relatórios e CSV (já só da gestão, `PORTAL_MANAGER_ROLES`), escala gerada.

**Arquivos.** `services/OperationalService.php` (`listCalendar`, `listOncall`, `seesPersonalText`), `api/portal/oncall.php` (passa o ator), `scripts/qa-calendar-text.php` (novo, nos dois runners), `scripts/qa-security.php`; frontend sem mudança (`CalendarPage.jsx` já esconde a descrição vazia).

**Riscos.** Saída antiga do F2B: o `qa-schedule-legacy-output` chama o Calendário como gestor e a chamada sem ator é só `type=onsite`, sem texto livre; referência não regenerada. Técnico sem `ci_funcionario_id` na sessão não vê nem os próprios textos (falha fechada, como nas ausências). Rollback: só código, sem migration; reabre o vazamento, sem efeito no banco.

**Decisões (aprovadas em 2026-10-09):**

| # | Decisão | Situação |
|---|---|---|
| D1 | Evento de hora extra, ajuste e plantão de outro colaborador continua visível para todos (nome, período, status), sem o texto, como a P9 das ausências | Aprovada |
| D2 | Motivo da pausa | **Aprovada com ajuste:** o motivo da lista fechada de `validate_motivo_pausa` (`security.php`: Café, Pessoal, Reunião) **não** é escondido, porque já aparece para todos no painel ao vivo e escondê-lo no Calendário não protege nada. Para quem não é gestão nem o próprio, valor fora da lista (dado antigo, possível texto livre) vira "Pausa". A gestão e o próprio veem o valor gravado. Comparação pela mesma função, sem lista duplicada (comparação estrita: "café" minúsculo vira "Pausa") |
| D3 | `oncall.php` tira `note`, `created_by` e `updated_by` de quem não é gestão nem o próprio (minimização, LGPD) | Aprovada |
| D4 | "Próprio" só pelo `employee_id` da sessão, sem a busca por `ad_login` do `actorEmployeeId`; mesmo critério das ausências | Aprovada |
| D5 | Motivo da pausa no `presence.php` | **Resolvida sem mudança:** o motivo ali vem da pausa ao vivo, que só aceita a lista fechada (`iniciar_pausa.php` e `solicitar_pausa_com_aprovacao.php` validam com `validate_motivo_pausa`). Não é pendência |
| D6 | Um commit de código e testes e outro de documentação; deploy 12 da fila, só código; com o deploy único da Parte B, entra no merge único do roteiro consolidado (pendência 16 do estado da sessão) | Aprovada |

**Entrega (2026-10-09, `93d659d`):**

| Item | Como ficou |
|---|---|
| Regra | `OperationalService::seesPersonalText($actor, $employeeId)`: gestão (`portal_role_is_manager`) ou `employee_id` da sessão maior que zero e igual ao do registro. O bloco de ausências do F3 passou a usar a mesma função (sem mudança de comportamento) |
| Calendário | Hora extra, ajuste e plantão: `description = ''` para os demais. Pausa: para os demais, `validate_motivo_pausa($motivo) ?? 'Pausa'`; motivo vazio continua vazio (nada a esconder; a gestão também vê vazio) |
| Plantões | `listOncall($filters, $actor)` (ator obrigatório; único chamador é o `oncall.php`): as três chaves saem da linha (não vêm como `null`) |
| Registro sem colaborador | `employee_id` 0 ou nulo nunca é "o próprio", nem para sessão sem colaborador |
| F9 | Quando o motivo "Almoço" entrar em `validate_motivo_pausa` (seção 11.3), passa a aparecer para todos no Calendário, coerente com o painel; nenhuma mudança aqui |

**Testes.** `qa-calendar-text` (novo): os quatro tipos por perfil (gestor, Liderança, admin, técnico com e sem colaborador, somente leitura com e sem colaborador, sem ator), pausa com motivo fora da lista (técnico vê "Pausa"; gestão e o próprio veem o valor), motivo vazio, registro sem colaborador, técnico pedindo outro colaborador pelo `employee_id` (eventos aparecem, textos vazios), `listOncall` por perfil (chaves presentes só para gestão e o próprio) e estrutura (ator no `oncall.php`, lista de motivos não duplicada). `qa-security`, ponta a ponta pelos endpoints reais: `calendar.php` por tipo (`pause`, `overtime`, `time_adjustment`, `oncall`) para gestor, Liderança, técnico, técnico pedindo o colaborador 7 e somente leitura; `oncall.php` para os mesmos perfis, sem `note`, `created_by` e `updated_by` de terceiros. **Mutação 19/19** nos ramos de perfil (gestão, `> 0`, igualdade do próprio, negações, lista fechada, guarda do vazio, chaves do plantão, ator do `oncall.php`); o `qa-security` sozinho também mata os mutantes do `oncall.php`, da negação do bloco de hora extra e do vazamento fora da lista. Bateria PHP completa, 15 `qa:*` do frontend e lint passando.

**Aprovado em 2026-10-09**, com as decisões de implementação como estão, após teste do responsável no MySQL local com dados reais nas quatro tabelas: gestor, Liderança e admin veem todos os textos; o técnico vê só os próprios, inclusive filtrando o `employee_id` de outro (o evento aparece, o texto vem vazio); o técnico sem colaborador na sessão não vê nenhum; motivo fora da lista vira "Pausa"; o somente leitura com colaborador vê só os próprios; "Pessoal" aparece para todos; no `oncall.php`, quem não é gestão nem o próprio recebe a linha sem `note`, `created_by` e `updated_by`.

**Observação aceita (2026-10-09):** pausas antigas com encoding quebrado (VULN-023) não batem com a lista fechada e aparecem como "Pausa" para quem não é gestão nem o próprio. É falha fechada e fica aceita.

### 9.6 F4 — arquivamento de técnicos: plano aprovado (2026-10-13)

O plano foi entregue no chat em 2026-10-09 e se perdeu (ficou só no chat). Reenviado em 2026-10-13 a partir do código de então e **aprovado em 2026-10-13** com o ajuste da D8 e a regra de período válido nos efeitos. Fica gravado aqui antes de qualquer código.

**Fatos lidos no código (base `bf07c3e`):**

| Fato | Evidência |
|---|---|
| A revalidação do A1 (Lote 5a, deploy 6) roda em **toda requisição**; sessão CI com cadastro `ativo <> 1` é encerrada (motivo `cadastro_inativo`, `SESSION_REVOKED`). Custo: 1 consulta por chave primária por requisição | `security.php`, `session_revalidate()` e `session_current_role()`; chamada por `ci_session_is_current()` e `verificar_*` (`config.php`) |
| Sessão do login administrativo confere só a allowlist: arquivar o cadastro não a encerra nem bloqueia login novo por esse caminho. Sessão CI de login da allowlist depende do cadastro ativo e é encerrada | `session_current_role()`; `api/login_admin.php`; `api/login_ci.php` |
| Desativar hoje só faz `ativo = 0` e regrava o `estado.json` inteiro fora do lock (L-11); editar faz a mesma gravação. O estado só guarda campos de pausa | `api/remover_funcionario.php`; `GerenciadorPausas::remover_funcionario`, `atualizar_funcionario`, `salvar_estado` |
| Desativar e editar aceitam Liderança para cadastros fora da Liderança e do admin (Lote 5b); 3.6 e 3.8 diziam só admin | `funcionario_change_denied()` (`security.php`) |
| `ativo` ausente no `atualizar` vira `true` (editar um inativo o reativa); `adicionar` aceita criar inativo | `api/atualizar_funcionario.php:34`; `api/adicionar_funcionario.php:32` |
| `pausas` não tem coluna de origem; `admin_force_end` vai como texto em `observacao_reuniao`; `finalizar_pausa` cai no CSV se o INSERT falhar; `admin_force_end_break.php` recusa inativo (409) | `GerenciadorPausas::finalizar_pausa`, `observacao_historico_pausa` |
| `obter_status()` não filtra inativo: `status.php` e `dashboard.php` contam desativados (L-07). No Mapa PA só `assignmentsForMap` não cruza com `funcionarios`; elegíveis, motor, escala e relatórios já filtram `ativo = 1` | `GerenciadorPausas::obter_status`; `PaMapService::assignmentsForMap` |
| `ScheduleRulePlanner::planRemove` (puro, F1) já fecha a vigente em ontem e apaga as que começam hoje ou depois; `get_db_connection()` é singleton (o INSERT da pausa entra na mesma transação) | `services/ScheduleRulePlanner.php`; `db.php` |
| `ad_login_ativo` é coluna gerada STORED com UNIQUE | `database_SECURED.sql` |

**Escopo.** Migration 014; arquivar no lugar de remover, com os efeitos calculados por função pura e o plano montado antes de aplicar; restaurar só por admin; L-11; `status.php`, contadores e Mapa PA de hoje sem arquivados (L-07); tela de Funcionários; P11 pela D2. **Fora:** histórico até `arquivado_em` em relatórios e Calendário (F5); `presence` no `technicians.php` (F7, D14); política de retenção e anonimização (pendência da D7 do desenho).

**Commits:**

| # | Commit | Arquivos |
|---|---|---|
| 1 | Migration 014, rollback, verificação e dados de ensaio | `migrations/20261013_014_employee_archive.sql`, `migrations/rollback/20261013_014_employee_archive_down.sql`, `migrations/rollback/20261013_014_ensaio_linhas_f4.sql`, `migrations/verificacao/20261013_014_employee_archive_check.sql`; `scripts/qa-archive.php` (novo, seção da estrutura SQL; nos dois runners) |
| 2 | Plano do arquivamento como função pura | `services/EmployeeArchivePlanner.php` (novo); `qa-archive` com a tabela de casos |
| 3 | Serviço e endpoints | `services/EmployeeArchiveService.php` (novo); `api/remover_funcionario.php` (mesmo contrato, com `efeitos`); `api/restaurar_funcionario.php` (novo); `api/atualizar_funcionario.php` e `api/adicionar_funcionario.php` (D4); `classes/GerenciadorPausas.php` (L-11; encerramento sem CSV); `security.php` (permissão `funcionarios.restaurar`, só admin) |
| 4 | L-07 | `obter_status()` sem inativos (cobre `status.php` e `dashboard.php`); `assignmentsForMap` sem inativos em datas a partir de hoje |
| 5 | Frontend | `AdminPage.jsx` (aba Funcionários: painel de confirmação na linha, sem `window.confirm`; filtro Ativos/Arquivados; sai o checkbox "Funcionário ativo"); `formSchemas.ts`; QAs estruturais afetados |
| 6 | Ponta a ponta e documentação | `qa-security`, mutação; `API_CONTRACTS_FRONTEND.md`, `DEPLOY_LINUX.md` ("Deploy que traz o F4"), LGPD 5.3, registro, esta seção |

**Migration 014:**

| Item | Proposta aprovada |
|---|---|
| Estrutura | `funcionarios.arquivado_em DATETIME NULL` e `arquivado_por VARCHAR(100) NULL`; `ck_funcionario_arquivado`: `ativo = 0 OR (arquivado_em IS NULL AND arquivado_por IS NULL)` (D11) |
| Dados | Nenhuma linha alterada; inativos de hoje ficam com data nula ("data desconhecida", seção 2.5) |
| Regras | A4: `SET time_zone`, sem relógio do banco; a data do arquivamento é gravada pelo PHP como valor explícito; cada passo confere `information_schema` (idempotente) |
| Ordem | Antes do `git pull` (o código do F4 lê as colunas); o código anterior funciona sobre o esquema novo |
| Rollback | Passo 0: **para com `SIGNAL`** se `funcionarios_bkp_014` já existir; 0b: copia `id`, `ativo`, `arquivado_em`, `arquivado_por` para `funcionarios_bkp_014`; depois CHECK e colunas saem. Os arquivados continuam com `ativo = 0` |
| Verificação | Só contagens, sem nome: estrutura (colunas, check, cópia) em qualquer estado; inativos, inativos sem data, ativos com data (0), arquivados sem autor (0); efeitos pendentes de inativos (vínculo de PA, regra, exceção, ausência de hoje em diante) |
| Ensaio | (a) estrutura: 014 duas vezes, rollback duas vezes (o 2º para de propósito); (b) `DADOS` com as tabelas de PA, escala, exceção e ausência e `VERIFICA`; (c) 014 como `PREPARO` e `..._ensaio_linhas_f4.sql` no lugar da migration (arquiva cadastros, só em banco `ensaio_*`): o rollback guarda os arquivados na cópia; o 2º para |

**Q-F4 no servidor (só contagens; sem depender da 011 nem da 012; para a D5).** Colunas desde as migrations 003 e 009:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0) AS inativos, (SELECT COUNT(DISTINCT a.employee_id) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())) AS inativos_com_pa, (SELECT COUNT(DISTINCT r.employee_id) FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 0 WHERE (r.effective_until IS NULL OR r.effective_until >= CURDATE()) AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from)) AS inativos_com_regra, (SELECT COUNT(*) FROM portal_schedule_exceptions e JOIN funcionarios f ON f.id = e.employee_id AND f.ativo = 0 WHERE e.exception_date >= CURDATE()) AS excecoes_de_inativos;"; unset P
```

**Resultado da Q-F4 (responsável, servidor, 2026-10-13):** `inativos = 5`, `inativos_com_pa = 1`, `inativos_com_regra = 0`, `excecoes_de_inativos = 0`. **D5 fechada: sem regularização em massa.** Só o cadastro com vínculo de PA aberto é arquivado de novo, pela tela, depois do deploy (passo pós-deploy abaixo); os outros 4 inativos ficam com `arquivado_em` nulo ("data desconhecida", seção 2.5). Consequência para os commits 3 e 5: o arquivamento de inativo antigo (data nula) tem de estar disponível no endpoint e na tela de Funcionários, só para esse caso; inativo com data (arquivado pelo F4) continua 409.

**Efeitos do arquivamento — função pura, plano antes de aplicar.** `EmployeeArchivePlanner::plan(cadastro, regras, exceções, ausências, vínculos de PA, entrada do estado de pausa, hoje, agora)` devolve o plano e as contagens de `efeitos`; não consulta banco, arquivo nem relógio.

| Efeito | Regra aprovada |
|---|---|
| PA (RF-06) | Vínculo aberto ou futuro (`valid_until` nulo ou ≥ hoje): começou **antes de hoje** → `valid_until = ontem`; começa **hoje ou depois** → `active = 0`. Vínculo já encerrado não muda. **Nunca `valid_until < valid_from`** (D6) |
| Regras de escala | `planRemove` do F1: a vigente que começou antes de hoje fecha ontem; as que começam hoje ou depois são apagadas, com o conteúdo na auditoria; fila de sincronização `rule_removed`, como no F1. Nunca `effective_until < effective_from` |
| Exceções de escala | As de hoje em diante são apagadas; tipo e data na auditoria, sem a `note` (D7) |
| Ausências aprovadas (**D8 com o ajuste de 2026-10-13**) | Começou **antes de hoje** e termina hoje ou depois → `ends_on = ontem` (sempre de dia inteiro: horário só existe em ausência de um dia, `ck_absence_period`). Começa **hoje ou depois** — inclusive a parcial de hoje e a de vários dias começando hoje — → `cancelled`, com `cancelled_by` = ator, como as futuras. Terminada antes de hoje: não muda. **Nunca `ends_on < starts_on`**: senão o `ck_absence_range` da 012 recusa o UPDATE e a transação inteira cai. `retroactive` não muda (o efeito fica na auditoria do arquivamento) |
| Pausa (P16, D9) | Aprovada em andamento → linha em `pausas` com `Encerrada pelo arquivamento do cadastro (admin_archive) por <usuário>` em `observacao_reuniao` (sem coluna nova); solicitação pendente → descartada, sem histórico; **sem fallback para CSV** |
| Cadastro | `ativo = 0`, `arquivado_em` = agora do PHP, `arquivado_por` = ator |

O `qa-archive` confere, em todos os planos da tabela de casos, que nenhum gera `ends_on < starts_on`, `valid_until < valid_from` ou `effective_until < effective_from`. Casos obrigatórios das ausências: dia inteiro que começa hoje, parcial de hoje (`from_time` e `interval`), vários dias começando hoje (canceladas); vários dias iniciados antes de hoje e terminando hoje ou depois (cortadas em ontem); terminada ontem e cancelada (sem mudança). No PA: vínculo que começa hoje (`active = 0`), iniciado ontem e aberto (`valid_until = ontem`), futuro, encerrado ontem.

**Aplicação, na ordem (D10):** (1) `with_pause_state_lock` (arquivo, depois banco: mesma ordem dos endpoints de pausa); (2) transação; trava do cadastro e leituras `FOR UPDATE` (lição do F1); `carregar_estado()` dentro do lock; (3) plano e gravações; auditoria `FUNCIONARIO_ARQUIVADO` (`CRITICAL`, só IDs e contagens) na mesma conexão; (4) `commit`; (5) `estado.json` **dentro do lock** (L-11), só a entrada do colaborador. Falha antes do commit desfaz tudo, inclusive a pausa (banco fora: 503, nada muda). Falha ao gravar o estado depois do commit: 200 com aviso e log; o arquivado já está fora do painel, a limpeza de 24 h zera a entrada e restaurar também a limpa. L-11 nos outros caminhos: sai o `salvar_estado()` de `GerenciadorPausas::atualizar_funcionario`, e `remover_funcionario` (sem uso) sai.

**L-07.** `obter_status()` pula inativos: `status.php` e `dashboard.php` (`tecnicos_monitorados`, `pausas_ativas`, `active_pauses`, pendentes), sem mudar contrato. Mapa PA: datas a partir de hoje sem vínculos de inativos; passado sem mudança até F5/F6 (D13).

**P11 (D2, decisão do responsável).** Fica a revalidação do A1 por requisição, **sem mudar código de autenticação**; só o teste de regressão: arquivar → a próxima requisição da sessão CI é encerrada com `cadastro_inativo` e auditada (`SESSION_REVOKED`). Alternativa recusada: checagem no máximo uma vez por minuto (menos consultas, mas janela de até 60 s e afrouxamento do A1 para perfil rebaixado e saída da allowlist). **Admin da allowlist:** a sessão do login administrativo não é atingida pelo arquivamento (o acesso vem de `AD_ADMIN_USERS`); para cortar, tirar o login do `.env` e recarregar o PHP-FPM (D3).

**Riscos.** Rollback de código com arquivados: no código anterior, editar um arquivado manda `ativo = true` e o CHECK recusa (500); falha fechada; para editar, aplicar também o rollback da 014. Ordem de trava arquivo → banco igual à das pausas; o `atualizar` deixa de gravar o estado. Encerramento da sessão depende do A1 no ar (deploy 6). QAs por `git diff` podem disparar em `AdminPage.jsx` e `formSchemas.ts` (dívida técnica registrada). LGPD: `arquivado_por` guarda o usuário do operador, como `created_by`; auditoria só com IDs e contagens. Sem MySQL na estação: 014, consultas e concorrência só ficam provadas no ensaio e no teste do responsável.

**Testes.** `qa-archive` (novo): estrutura SQL da 014 (commit 1); tabela de casos do plano (PA, regras, exceções, ausências com os casos da D8, pausa aprovada/pendente/sem pausa, já arquivado 409, legado pela D5, virada 23:59/00:00 com "hoje" injetado) e a invariante de período válido (commit 2); serviço com PDO espião e estado em arquivo temporário: falha no meio não muda nada e mantém o estado, sem CSV, auditoria sem texto livre; L-11 sem gravação de estado (commit 3). `qa-security` ponta a ponta: arquivar 403 para gestor, técnico e somente leitura; Liderança arquiva N1 e recebe 403 em cadastro da Liderança; restaurar 403 para todos menos admin; CSRF; sessão CI do arquivado encerrada na requisição seguinte; `status.php`, `dashboard.php` e Mapa PA de hoje sem o arquivado; `atualizar` com mudança de `ativo` → 409. Frontend: `qa:employee-form`, `qa:forms` (sem `confirm`), os 15 `qa:*` e lint. Mutação no plano, na permissão e na ordem de aplicação. Validação humana em MySQL 8.0.46.

**Decisões (aprovadas em 2026-10-13):**

| # | Decisão | Situação |
|---|---|---|
| D1 | Arquivar pela regra do Lote 5b (admin; Liderança só cadastros fora da Liderança e do admin); **restaurar só admin** (`funcionarios.restaurar`); lista de arquivados para quem gerencia funcionários | Aprovada |
| D2 | P11: revalidação do A1 por requisição, sem código novo de autenticação; só o teste de regressão | Aprovada (opção a) |
| D3 | Admin da allowlist: arquivar permitido, com aviso na resposta de que o acesso administrativo continua pela allowlist; arquivar o **próprio** cadastro → 409 | Aprovada |
| D4 | `ativo` ausente mantém o valor; mudar `ativo` pelo formulário → 409 apontando Arquivar/Restaurar; editar arquivado → 409 ("restaure antes"); cadastro novo sempre ativo | Aprovada |
| D5 | Arquivar de novo um inativo antigo (data nula) aplica os efeitos e grava a data (regularização) | Aprovada; **fechada com a Q-F4 de 2026-10-13**: sem regularização em massa; só o cadastro com PA aberto, pela tela, no pós-deploy |
| D6 | PA: iniciado antes de hoje fecha ontem; de hoje ou futuro sai (`active = 0`) | Aprovada |
| D7 | Exceções de hoje em diante apagadas, tipo e data na auditoria, sem a `note` | Aprovada |
| D8 | Ausências: iniciada antes de hoje e em curso → `ends_on = ontem`; começa hoje ou depois (inclusive parcial e de vários dias) → cancelada; nenhum plano gera período invertido | **Aprovada com ajuste** |
| D9 | Pausa: `admin_archive` como texto em `observacao_reuniao`; pendente descartada; sem CSV | Aprovada |
| D10 | Lock → transação com a auditoria → commit → `estado.json` dentro do lock; falha do estado depois do commit: 200 com aviso | Aprovada |
| D11 | `ck_funcionario_arquivado` na 014, com o risco de rollback acima | Aprovada |
| D12 | Restaurar: `ativo = 1`, limpa as duas colunas e a entrada do estado; não devolve PA, escala nem ausências; 409 com login AD em uso por outro ativo; `FUNCIONARIO_RESTAURADO` `CRITICAL` | Aprovada |
| D13 | Mapa PA de hoje e futuro sem inativos; passado sem mudança até F5/F6 | Aprovada |
| D14 | `presence` no `technicians.php` vai para o F7 | Aprovada |

**Contrato de `efeitos` (proposta para o commit 3, a aprovar na entrega dele):** `{ pa_encerrados, pa_removidos, regras_encerradas, regras_removidas, excecoes_removidas, ausencias_canceladas, ausencias_cortadas, pausa_finalizada, solicitacao_descartada }` (contagens e booleanos), no lugar da lista curta da 3.6, para cada efeito da tabela acima ter a sua contagem.

**Deploy.** Entra no deploy único da Parte B (pendência 16 do estado da sessão): no roteiro consolidado, Q-F4 e 014 antes do `git pull`, com o ensaio nas três variantes e a verificação. **Passo pós-deploy obrigatório (D5, pedido em 2026-10-13)**, no item "Deploy que traz o F4" do `DEPLOY_LINUX.md` (escrito no commit 6) e depois no roteiro consolidado: (1) rodar a verificação da 014 e conferir `inativos_com_pa = 1` (se o número for outro, parar e avisar o responsável antes de arquivar); (2) arquivar de novo esse cadastro pela tela de Funcionários (admin); (3) rodar a verificação de novo e conferir `inativos_com_pa = 0`, `arquivados_f4 = 1` e `inativos_sem_data = 4`. Os outros inativos não são tocados. **Ritmo combinado:** depois do commit 1, parar para revisão e teste do responsável no MySQL local antes do commit 2.

**Commit 1 entregue (2026-10-13, `1abb37a`; aprovado em 2026-10-13):**

| Arquivo | Como ficou |
|---|---|
| `migrations/20261013_014_employee_archive.sql` | Três passos condicionais (`information_schema`): `arquivado_em DATETIME NULL`, `arquivado_por VARCHAR(100) NULL` (com `COMMENT`) e `ck_funcionario_arquivado`. Sem `DEFAULT`, sem `AFTER`, sem relógio, sem alterar linha. Repetida, não muda nada |
| `migrations/rollback/20261013_014_employee_archive_down.sql` | Passo 0: procedimento temporário `rollback_014_confere_copia` para com `SIGNAL 45000` se `funcionarios_bkp_014` existe; 0b: cópia de `id, ativo, arquivado_em, arquivado_por` (sem as colunas, `id, ativo`); depois o CHECK e as duas colunas, cada um condicional. Nenhuma linha muda |
| `migrations/verificacao/20261013_014_employee_archive_check.sql` | Três consultas, só contagens: estrutura (`colunas_014`, `check_014`, `copia_rollback`, `cadastros`, `inativos`); cadastros com as colunas da 014 (`arquivados_f4`, `inativos_sem_data`, `ativos_com_data`, `arquivados_sem_autor`; antes da 014, aviso); efeitos pendentes de inativos de hoje em diante (`inativos_com_pa`, `inativos_com_regra`, `excecoes_de_inativos`, `ausencias_de_inativos`) e `pa_periodo_invertido`, cada um `NULL` se a tabela não existe no banco |
| `migrations/rollback/20261013_014_ensaio_linhas_f4.sql` | Um `UPDATE` em `funcionarios`, só em banco `ensaio_*`, idempotente (marca `arquivado_por = 'qa.ensaio'`): arquiva o ativo de menor id e regulariza o inativo de menor id (D5), com data explícita |
| `scripts/qa-archive.php` (novo; nos dois runners) | 103 verificações da estrutura: A4 (`SET time_zone` primeiro; sem relógio na migration, no rollback e no ensaio), sem id fixo, idempotência pela condição de cada `IF` (`= 0` para acrescentar, `> 0` para remover), todo `PREPARE` com variável atribuída antes, `EXECUTE` e `DEALLOCATE`, parada antes de qualquer alteração e mensagem até 128 caracteres, cópia mínima e nunca pulada, CHECK antes das colunas, verificação só leitura e sem nome, ensaio só em `ensaio_*` |

**Decisões de implementação do commit 1 (aprovadas em 2026-10-13, como estão):**

1. **Colunas no fim da tabela, sem `AFTER fora_mapa_escala`:** a 014 não depende da 011 e pode ser ensaiada no servidor de hoje.
2. **"Hoje" na verificação:** `SET @hoje := CURDATE()` uma vez, logo depois do `SET time_zone`. A regra do A4 proíbe relógio do banco em gravação; a verificação só lê. O `qa-archive` aceita um único uso, nessa posição, só na verificação.
3. **Cópia do rollback mínima** (`id`, `ativo`, data e autor; nunca nome nem login, LGPD) e **nunca pulada**: sem as colunas da 014, copia `id, ativo`, para a parada do 2º rollback valer em qualquer estado.
4. **`pa_periodo_invertido` na verificação:** vínculo com fim antes do início (invariante da D6). Diferente de 0 **antes** da 014 é dado antigo: anotar.
5. **Ensaio (c) também regulariza um inativo antigo** (o de menor id, se houver), para exercitar a D5 no rollback: esperado `arquivados_f4 = 2` (1 sem inativo na cópia).
6. **Comandos de ensaio nos cabeçalhos em várias linhas com `\`** (o da 012 saiu numa linha só com `--`, achado de 2026-10-08).

**Como ensaiar e testar.** Ensaio no servidor: comandos nos cabeçalhos da 014 (variantes a e b) e de `..._ensaio_linhas_f4.sql` (variante c). Números esperados: (a) depois da 014, `colunas_014 = 2`, `check_014 = 1`, `inativos_sem_data` = inativos; a 2ª 014 igual; depois do 1º rollback, `colunas_014 = 0`, `check_014 = 0`, `copia_rollback = 1`; o 2º rollback para com `rollback_014 abortado`. (b) `inativos`, `inativos_com_pa`, `inativos_com_regra` e `excecoes_de_inativos` iguais aos da Q-F4 no mesmo dia; `ausencias_de_inativos` só existe na verificação; nada muda entre a 1ª e a 2ª 014. (c) Como no cabeçalho do arquivo.

**Validação pela IA:** `qa-archive` (103 verificações); **mutação 27/27** nos arquivos SQL (28 mutantes; o único sobrevivente é equivalente de propósito, uma atribuição sem uso no rollback); bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** a 014, o rollback, a verificação e o ensaio não rodaram; ficam provados no ensaio e no teste do responsável.

**Aprovado em 2026-10-13**, com as seis decisões de implementação como estão, após teste do responsável no MySQL local com o `ensaio-migration.sh` nas três variantes e dois inativos de teste (um com PA aberto, regra, exceção futura e ausência futura; outro com PA encerrado): (a) a 014 duas vezes no mesmo estado (`colunas_014 = 2`, `check_014 = 1`, `inativos_sem_data = 2`); o 1º rollback voltou com `copia_rollback = 1`; o 2º parou com `rollback_014 abortado`. (b) Efeitos pendentes = 1 em cada contagem; o PA encerrado não contou; iguais em todos os estados. (c) `arquivados_f4 = 2`, idempotente; o rollback manteve os inativos; o 2º parou. CHECK: reativar arquivado sem limpar as colunas dá 3819; restaurar limpando funciona; o código antigo desativa, reativa e insere normalmente. A Q-F4 roda. **Cosmético, sem mudança:** a 2ª execução imprime "1" pelos `SELECT 1`, como nas migrations anteriores.

**Commit 2 entregue (2026-10-13, `3944cc7`; aprovado em 2026-10-13):** `services/EmployeeArchivePlanner.php` (novo) e a seção 2 do `qa-archive`.

| Item | Como ficou |
|---|---|
| Assinatura | `EmployeeArchivePlanner::plan(array $input, DateTimeImmutable $now): array`. Entrada: `employee` (`id`, `ativo`, `arquivado_em`), `rules`, `exceptions`, `absences`, `pa_links` (todas com `employee_id`) e `pause` (entrada do `estado.json` ou `null`) |
| Saída | `today`; `employee` (`id`, `regularizacao`, `arquivado_em`); `pa` (`close` com `valid_until = ontem` e o fim anterior, `remove`); `rules` (resultado do `planRemove`); `exceptions.delete` (id, data, tipo); `absences` (`cancel`, `cut` com `ends_on = ontem` e o fim anterior); `pause` (`finalize` com início, fim, duração e motivo; `discard_request`; `clear_invalid`; `none`); `efeitos` com as nove chaves propostas acima |
| Regras | As da tabela de efeitos desta seção, com a D8 ajustada. PA e ausência que terminam **hoje** e começaram antes contam como em curso (fecham ontem) |
| Invariante | Antes de devolver: nenhum `valid_until`, `effective_until` ou `ends_on` gravado antes do início da própria linha, e toda linha do plano tem a linha de origem. Violação: `LogicException` (nada é gravado) |
| Testes | `qa-archive` seção 2 (381 verificações no total): cadastro ativo, inativo antigo (nulo e vazio) e arquivado pelo F4 (409 com a data); PA em nove situações; regras (vigente, futura, de hoje, vazia, encerrada); exceções de ontem, hoje e amanhã; ausências em doze situações, com os casos pedidos (dia inteiro de hoje, parcial de hoje `from_time` e `interval`, vários dias começando hoje); pausa aprovada, pendente, sem pausa e cinco entradas ilegíveis; virada 23:59:59/00:00:00 com o instante injetado; varredura da invariante (início e fim de −3 a +3 dias, fim aberto, três instantes do dia, mais de 100 planos); dado fora do formato (12 casos); pureza (sem banco, arquivo, relógio nem entrada externa; datas só por formato explícito) |

**Decisões de implementação do commit 2 (aprovadas em 2026-10-13, como estão):**

1. **"Hoje" vem de `$now`**, sem um `$today` separado: as duas datas nunca divergem e a virada da meia-noite é testada só pelo instante. O serviço passa `new DateTimeImmutable('now')` (fuso do PHP, A4).
2. **Tipos de erro:** `DomainException` só para "já arquivado" (409, mensagem acentuada com a data); dado fora do formato (data inexistente ou com hora, id inválido ou repetido, linha de outro colaborador, regra sem a chave `effective_until`) é `UnexpectedValueException`, e invariante violada é `LogicException`. Os dois viram 500 genérico no `portal_operational_error`, e não 400, que culparia quem pediu.
3. **Pausa ilegível → `clear_invalid`:** `em_pausa` com aprovação diferente de `aprovado`, início fora do formato que o gravador usa (`DATE_ATOM`; texto relativo como `now` nunca vira data) ou hora que transborda (24:30). O estado é limpo sem histórico, e o caso não entra em `efeitos` (o serviço registra no log e na auditoria, commit 3). `em_pausa` em texto (`"true"`) não é pausa.
4. **Duração nunca negativa:** início depois de agora dá 0. O início sai no fuso de agora (pausa gravada com outro fuso fica certa no histórico).
5. **`regularizacao` no plano** (D5), para a auditoria distinguir arquivamento novo de regularização; `arquivado_em` vazio conta como nulo.
6. **Ids aceitos como inteiro ou texto sem zero à esquerda**, como o PDO devolve; `ativo` como inteiro, texto ou booleano.

**Validação pela IA:** `qa-archive` com 381 verificações; **mutação 36/36** no planejador (na 1ª rodada sobreviveram dois mutantes, por teste fraco: a hora que transborda e a checagem estrutural com `strpos` falso; os dois testes foram reforçados); mutação do SQL repetida (27/27 nos não equivalentes); bateria PHP completa, 15 `qa:*` do frontend e lint. O planejador ainda não é chamado por nenhum endpoint (commit 3).

**Aprovado em 2026-10-13**, com as seis decisões como estão, após revisão do responsável: `qa-archive` rodado na estação dele (381 verificações) e um caso com os dados no formato real (ids e `ativo` em texto, como o PDO devolve; início da pausa com `format('c')`, como o `salvar_estado` grava): pausa das 23:50 arquivada às 00:05 finaliza com 900 s e o início no dia anterior; a parcial do próprio dia é cancelada; a ausência de vários dias iniciada ontem é cortada em ontem; o PA que começa hoje sai. Conferido no `GerenciadorPausas` que as pausas automáticas (Café, Pessoal) já nascem com `status_aprovacao = 'aprovado'` e que o estado grava `inicio_pausa` com `format('c')`: o `clear_invalid` não pega pausa real.

**Pedidos para o commit 3 (2026-10-13):** o serviço passa `new DateTimeImmutable('now')` no fuso do PHP, com teste que falha se o instante vier em UTC ou em outro fuso; `clear_invalid` no `error_log` e na auditoria do arquivamento (só ids e a ação); `UnexpectedValueException` e `LogicException` do planejador viram 500 genérico, com `error_log` da classe e da mensagem, rollback e estado intacto; leituras com `FOR UPDATE` na ordem `estado.json` → banco; regularização (D5) no endpoint e 409 com a mensagem do planejador para arquivado pelo F4; `efeitos` com as nove chaves.

**Commit 3 entregue (2026-10-13, `dfc6b28`; aprovado em 2026-10-13):** serviço, endpoints, L-11 e `funcionarios.restaurar`.

| Arquivo | Como ficou |
|---|---|
| `services/EmployeeArchiveService.php` (novo) | `archive()` e `restore()`. Ordem do arquivamento: instante conferido (fuso `America/Sao_Paulo`, senão `LogicException` antes de qualquer leitura); `with_pause_state_lock`; `estado.json` lido dentro do lock; transação com o cadastro e as linhas do colaborador lidos com `FOR UPDATE` (cadastro primeiro); plano pelo `EmployeeArchivePlanner`; gravações (cada `UPDATE`/`DELETE` tem de afetar exatamente uma linha); histórico da pausa em `pausas`; fila de sincronização `rule_removed` (origem `arquivamento`) quando a escala muda; conferência do histórico de escala (só violação nova); auditoria `FUNCIONARIO_ARQUIVADO` na mesma conexão; `commit`; `estado.json` gravado ainda dentro do lock, só a entrada do colaborador e só se o plano mexe na pausa. `httpError()` traduz a falha em status e mensagem; `sessionActor()` monta o ator |
| `api/remover_funcionario.php` | Mesmo endereço e corpo. Perfil antes do CSRF e do banco (regra do 5b); o serviço confere o perfil atual com o cadastro travado. Resposta: `sucesso`, `mensagem`, `efeitos` (nove chaves), `regularizacao`, `avisos`. Recusa de perfil auditada (`FUNCIONARIO_ALTERACAO_NEGADA`, depois do rollback). Não carrega mais o `init.php` |
| `api/restaurar_funcionario.php` (novo) | `POST { funcionario_id }`, só admin (`funcionarios.restaurar`), conferido antes do CSRF e do banco. Resposta: `sucesso`, `mensagem`, `avisos` |
| `api/atualizar_funcionario.php` (D4) | `ativo` ausente mantém; `false` dá 409 ("use o botão Arquivar"); cadastro inativo (arquivado ou antigo) dá 409 ("restaure antes de editar"), conferido na leitura travada (`SELECT fora_mapa_escala, ativo ... FOR UPDATE`); o `UPDATE` não grava mais `ativo` |
| `api/adicionar_funcionario.php` (D4) | `ativo: false` dá 400 ("Cadastro novo é sempre ativo"); sem a gravação do `estado.json` fora do lock |
| `classes/GerenciadorPausas.php` (L-11) | `atualizar_funcionario` sem `salvar_estado()`; `remover_funcionario` (sem uso) saiu; `motivo_conta_para_limite()` público e estático, usado pelo serviço para os alertas de 15/20 min |
| `security.php` | `funcionarios.restaurar` só no admin |

**Status HTTP:** 403 (perfil, 5b, L9), 404 (cadastro inexistente), 409 (já arquivado, com a mensagem do planejador; próprio cadastro; já ativo; login AD em uso), 503 (banco indisponível), 500 genérico ("Não foi possível concluir a operação. Nada foi alterado.") para dado fora do formato, invariante e gravação que não afetou uma linha, com `error_log` da classe e da mensagem.

**Decisões de implementação do commit 3 (aprovadas em 2026-10-13):**

1. **L9 também no arquivamento:** a Liderança não arquiva cadastro da equipe Liderança, mesmo com perfil técnico (a regra do 5b olha só o perfil; os efeitos mexem na escala que a L9 reserva ao admin). Mensagem própria, 403.
2. **Próprio cadastro (D3) pela sessão CI ou pelo login AD:** o admin da allowlist que tenta arquivar o cadastro com o próprio login recebe 409, mesmo entrando pelo login administrativo.
3. **`adicionar_funcionario.php` também deixa de regravar o `estado.json` fora do lock:** é o mesmo padrão do L-11, achado no arquivo que já mudava pela D4. Cadastro novo não tem pausa.
4. **`atualizar`:** `ativo` conferido na leitura travada; inativo antigo (data nula) também não é editado (409). Para editar, restaurar (admin) ou arquivar de novo (regularização).
5. **Toda gravação do arquivamento tem de afetar exatamente uma linha**; senão `RuntimeException`, rollback e 500 (linha mudada no meio, apesar das travas).
6. **Conferência do histórico de escala só com violação nova** (critério do F1): dado antigo com sobreposição não bloqueia o arquivamento.
7. **Estado de pausas:** ilegível, o arquivamento segue sem pausa e registra no `error_log`; ausente, nenhum arquivo é criado; o arquivo só é regravado quando o plano mexe na pausa.
8. **Histórico da pausa:** a observação anterior (pausa de reunião) é mantida antes do texto `admin_archive`; alertas de 15/20 min pela mesma regra do `GerenciadorPausas` (Pessoal e Reunião não contam).
9. **Fila de sincronização:** `rule_removed` com `origem = arquivamento`, sem o nome do colaborador no payload (o F1 levava o nome).
10. **Restaurar limpa a entrada velha do restaurado no `estado.json`**, se houver, para uma pausa antiga não voltar.
11. **Respostas e eventos:** cadastro inexistente 404 (o contrato antigo já dava 404); `FUNCIONARIO_ARQUIVADO` substitui `FUNCIONARIO_REMOVIDO`; a recusa de perfil é auditada pelo endpoint depois do rollback; `avisos` é uma lista.
12. **O teste de regressão da D2 entrou já neste commit** (o plano o punha no commit 6): no `qa-security`, a sessão CI do cadastro arquivado recebe 401 na requisição seguinte e o `SESSION_REVOKED` sai com `cadastro_inativo`.

**Até o commit 5 (frontend):** a tela ainda tem o checkbox "Funcionário ativo"; desmarcá-lo na edição dá 409 e no cadastro novo dá 400, com a mensagem do backend. O "Desativar" continua com `window.confirm` e passa a arquivar. `API_CONTRACTS_FRONTEND.md` muda no commit 6, como no plano.

**Validação pela IA:** `qa-archive` com 518 verificações (seção 3: instante em UTC, deslocamento fixo e outro fuso recusados sem leitura nem gravação; 21:30 locais pelo dia local; arquivamento completo com todos os efeitos, histórico da pausa e alertas por motivo e limiar; estado só com a entrada do colaborador; pendente, `clear_invalid` no log e na auditoria, sem pausa sem regravar o arquivo, sem arquivo sem criar; falha injetada no histórico, no cadastro, na fila e "0 linhas", dado fora do formato e violação nova de escala: rollback, nada gravado, estado intacto; falha só do estado depois do commit: aviso; permissões, próprio cadastro, arquivado, inexistente, regularização, allowlist; restaurar e suas recusas; `httpError`; ordem lock → estado → transação → commit → estado; toda leitura da transação com `FOR UPDATE`; L-11 e estrutura dos endpoints). `qa-security` com 24 casos novos de ponta a ponta e a matriz de perfis com o endpoint novo. **Mutação 40/40** no serviço, nos quatro endpoints e no `GerenciadorPausas` (na 1ª rodada sobreviveram a conferência do histórico de escala e a regra do motivo da pausa; entraram uma violação injetada e pausas Pessoal e Café de 14 e 16 min). Bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** o SQL do serviço só rodou contra o banco simulado.

**Aprovado em 2026-10-13**, com as doze decisões como estão, após teste do responsável no MySQL local com a 014 aplicada, chamando o `EmployeeArchiveService` real com um técnico de teste (regra vigente e futura, exceções de ontem e amanhã, quatro ausências, dois PA e pausa de Café aberta no `estado.json`): regra vigente fechada em ontem e futura apagada; exceção de amanhã apagada e a de ontem mantida; ausência de vários dias iniciada em 09/10 cortada em 12/10; futura e parcial do dia 16 canceladas; a de 01/10 intacta; PA aberto fechado em ontem e o futuro com `active = 0`; pausa virou histórico (720 s, "Café" em UTF-8 correto, `admin_archive` na observação); só a entrada do técnico limpa no `estado.json`; auditoria e fila sem nome nem observação. 2º arquivamento: 409 com a data. Restaurar com login em uso: 409. Restaurar: ok, sem devolver PA, escala nem ausências; restaurar de novo: 409. Dois arquivamentos simultâneos: um ok, um 409. Regularização de inativo antigo: `regularizacao = true` e PA fechado. Falha injetada (trigger com `SIGNAL` no `UPDATE` de `portal_absences`): rollback completo (cadastro ativo, PA aberto, sem histórico de pausa nem auditoria) e `estado.json` idêntico byte a byte.

**Pedidos para o commit 4 (2026-10-13):** L-07 como no plano (`obter_status` sem inativos; dashboard e Mapa PA de hoje em diante sem arquivados). Ajuste pequeno junto: em `EmployeeArchiveService::httpError`, quando o erro for `PDOException`, o `error_log` passa a trazer também o SQLSTATE e o código do driver (por exemplo 45000/1644, 1205, 1213), **sem a mensagem do erro**, que pode trazer dados; o usuário continua recebendo o 503 de hoje.

**Commit 4 entregue (2026-10-13, `85813e1`; aprovado em 2026-10-13, com a decisão 8):** L-07 e o ajuste do `httpError`.

| Arquivo | Como ficou |
|---|---|
| `classes/GerenciadorPausas.php` | `obter_status()` pula cadastro inativo. Cobre o `status.php` e o `dashboard.php` (`tecnicos_monitorados`, `pausas_ativas`, `active_pauses` e as pendentes de pausa), que contam a partir dele. Contrato sem mudança: o campo `ativo` continua em cada item |
| `api/solicitacoes_pendentes.php` | Mesma regra: solicitação pendente de inativo não entra na lista |
| `services/PaMapService.php` | Data de hoje em diante: sem vínculo de cadastro inativo, tanto no recorrente (`portal_pa_assignments`) quanto na tabela antiga (`portal_pa_map`), por `EXISTS` em `funcionarios` com `ativo = 1`. Data passada: sem mudança (D13). O nome da tabela no filtro só entra por lista branca |
| `services/EmployeeArchiveService.php` | `httpError`: na `PDOException` (também dentro do `DatabaseUnavailableException` da conexão), o `error_log` sai como `[ARCHIVE] Banco indisponivel: PDOException sqlstate=45000 driver=1644`, sem a mensagem do banco; o usuário continua com o 503 |

**Decisões de implementação do commit 4 (1 a 7 aprovadas em 2026-10-13; 8 pedida na aprovação):**

1. **`solicitacoes_pendentes.php` também sem inativos:** fora do texto do plano, mas a lista tem de bater com o contador de pendentes do dashboard, que já sai sem eles.
2. **Tabela antiga do Mapa PA (`portal_pa_map`) com o mesmo filtro:** o plano citava só o `assignmentsForMap`; a D13 fala do Mapa PA de hoje e do futuro como um todo. Ninguém grava mais nessa tabela; o filtro só evita que uma linha antiga mostre o arquivado.
3. **"Hoje" pelo relógio do PHP** (`date('Y-m-d')`, o mesmo padrão da data padrão do `list()`): hoje já é filtrado; ontem não.
4. **Nulo conta como ativo**, como no `"ativo" => $funcionario->ativo ?? true` que já existia na saída; `false`, `0` e `'0'` contam como inativo.
5. **Filtro por `EXISTS` em `funcionarios`:** as duas tabelas têm `employee_id NOT NULL` com FK `RESTRICT` (migrations 008 e 009), então não há vínculo órfão que o filtro esconderia por engano.
6. **Códigos da `PDOException`:** o `errorInfo` prevalece; o prefixo `SQLSTATE[...]` da mensagem só serve quando o `errorInfo` vem vazio (falha de conexão, `[HY000] [2002]`). Só entra no log o que tem formato de código (cinco caracteres `[0-9A-Z]` e número); o resto vira `desconhecido`, para nenhum texto do banco chegar ao log.
7. **Ponta a ponta no `qa-security` fica no commit 6, como no plano:** os processos filhos do `qa-security` dividem o mesmo `estado.json` e rodam em paralelo; o caso com pausa de arquivado no `status.php` e no `dashboard.php` precisa de estado por caso, que entra junto com os outros casos do commit 6.
8. **Conferência de conflito do `save()` do Mapa PA sem vínculo de inativo de hoje em diante** (pedida na aprovação; `d15f132`): a única consulta do `save()` que confere a ocupação do PA (a segunda do `assertNoConflict`) recebe o mesmo `EXISTS ... ativo = 1` do `activeEmployeeFilter`, pela data de início do vínculo novo. Vínculo que começa hoje ou depois ignora o de inativo (o PA que o mapa mostra livre aceita o vínculo, e a resposta não traz o nome do arquivado); vínculo que começa no passado continua conferido contra todos, porque cobre dias em que o inativo ocupava o PA. A primeira consulta (outro PA do mesmo colaborador) fica como está: o `save()` só aceita colaborador ativo.

**Observação na entrega (resolvida pela decisão 8):** a conferência de conflito do `save()` do Mapa PA (`assertNoConflict`) continuava vendo vínculo aberto de inativo. Hoje isso só afeta o inativo antigo com PA aberto da Q-F4 (`inativos_com_pa = 1`): depois do deploy, o PA dele aparece livre no mapa de hoje, mas alocar outro colaborador nesse PA dá 409 de conflito até o passo pós-deploy da D5 (arquivar de novo pela tela). Arquivamentos do F4 fecham o PA e não têm esse efeito. Se preferir, a conferência pode ignorar inativo de hoje em diante (mudança pequena no mesmo arquivo); deixei como está porque o passo pós-deploy já resolve.

**Validação pela IA:** `qa-archive` com 563 verificações (seção 4: `obter_status` com ativos, inativos `false` e `0` e ativo nulo, pausa aprovada e pendente de inativo fora da lista e dos contadores, campo `ativo` mantido; `status.php`, `dashboard.php` e `solicitacoes_pendentes.php` só pela regra nova (estrutura); Mapa PA de ontem, hoje e amanhã com vínculos recorrentes e da tabela antiga de um ativo e de dois inativos, com o banco simulado aplicando o filtro só quando o SQL traz a cláusula exata; lista branca da tabela; `httpError` com SIGNAL 45000/1644, 1205, 1213, conexão sem `errorInfo` (2002, e 1045 dentro do `DatabaseUnavailableException`), `errorInfo` sem o texto, `errorInfo` prevalecendo, `errorInfo` vazio ou com texto no lugar do código, e o nome fictício da mensagem nunca no log). **Mutação 24/24** (na 1ª rodada sobreviveram seis, por teste fraco: inativo como `0` e casos em que o `errorInfo` e a mensagem sempre concordavam; os testes foram reforçados). Bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** o `EXISTS` só rodou contra o banco simulado.

**Aprovado em 2026-10-13**, com as sete decisões como estão, após teste do responsável no MySQL local com a 014: um técnico ativo e um inativo, cada um com PA aberto desde 01/09. Mapa PA de 12/10 (passado) mostra os dois; 13/10 e 14/10 só o ativo (o `EXISTS` funciona no MySQL real). `obter_status`: ativo dentro, inativo fora.

**Pedido na aprovação (decisão 8 do commit 4):** o ponto em aberto da conferência de conflito foi reproduzido pelo responsável: o mapa de hoje mostra o PA do inativo como livre, mas alocar outro colaborador nele dá "Conflito de escala no PA com <nome do inativo>". A tela contradiz o salvamento e a mensagem expõe o nome de um cadastro arquivado. Corrigir agora, no mesmo arquivo: no `assertNoConflict` (e onde mais o `save()` consulta ocupação do PA), ignorar vínculos de cadastro inativo para datas de hoje em diante, com o mesmo `EXISTS ... ativo = 1` e a mesma regra de data do `activeEmployeeFilter`; passado sem mudança. Teste no `qa-archive` (PA ocupado por inativo aceita vínculo novo de hoje em diante; ocupado por ativo continua em conflito) e mutação no ramo novo.

**Decisão 8 entregue (2026-10-13, `d15f132`):** `qa-archive` com 571 verificações: PA ocupado por inativo (`ativo` 0 e `'0'`) aceita vínculo novo de hoje e de amanhã; ocupado por ativo continua em conflito, com o nome do ativo; vínculo que começa ontem continua em conflito com o inativo e com o ativo; a conferência continua com `FOR UPDATE`. **Mutação 6/6** no ramo novo (filtro retirado, data errada no filtro, hoje sem filtro, trava retirada, cláusula que aceita inativo). Bateria PHP completa, 15 `qa:*` do frontend e lint.

**Pedidos para o commit 5 (2026-10-13):** tela de Funcionários com Arquivar e Restaurar confirmados na linha, filtro Ativos/Arquivados, sem o checkbox "Funcionário ativo", Arquivar também para o inativo antigo sem data; prints de admin, Liderança e gestor, desktop e celular.

**Commit 5 entregue (2026-10-13, `47f4412`; aprovado em 2026-10-13):** aba Funcionários.

| Arquivo | Como ficou |
|---|---|
| `frontend/src/pages/AdminPage.jsx` | Sai o "Desativar" com `window.confirm`; entram **Arquivar** e **Restaurar**, confirmados num painel logo abaixo da linha, com o texto dos efeitos (arquivar, regularizar o inativo antigo ou restaurar) e "Confirmar"/"Voltar". Situação na coluna Status: Ativo; Arquivado (com a data); Inativo sem data ("Desativado antes do arquivamento"). Filtro Ativos / Arquivados / Ativos e arquivados, abrindo em Ativos. Sai o checkbox "Funcionário ativo" do formulário |
| `frontend/src/lib/employeeArchive.ts` (novo) | Funções puras: situação do cadastro, filtro da lista e resumo dos efeitos (as nove chaves) para a mensagem de sucesso |
| `frontend/src/lib/formSchemas.ts` | `ativo` sai do schema: editar e criar não mandam o campo (o servidor mantém, D4) |
| `frontend/src/lib/actionFeedback.ts` | `employeeRemoved` sai; entram `employeeArchived` e `employeeRestored` |
| `api/listar_funcionarios.php` | `arquivado_em` (só a data, sem o autor) para quem tem `funcionarios.manage`; nulo no ativo; sem a coluna (014 não aplicada), o campo fica de fora |

**Decisões de implementação do commit 5 (aprovadas em 2026-10-13):**

1. **`arquivado_em` na listagem** (mudança de backend neste commit de tela): sem ele a tela não separa o arquivado pelo F4 do inativo antigo, que ainda pode ser arquivado (D5). Só a data, nunca o `arquivado_por`, e só para quem gerencia funcionários (o gestor e o técnico não recebem).
2. **A lista abre em "Ativos"**; "Arquivados" inclui o inativo antigo, marcado "Inativo sem data". O card "Ativos" conta todos os ativos (não só os filtrados) e "Filtros aplicados" não conta o filtro padrão.
3. **Botões pelas regras do servidor**, que confere de novo: Editar só no ativo (arquivado dá 409); Arquivar no ativo e no inativo antigo (rótulo "Arquivar (regularizar)"), nunca no arquivado pelo F4; Restaurar só com `funcionarios.restaurar` (admin); cadastro de Liderança/admin só para quem promove (5b); equipe Liderança só com `escalas.lideranca` (L9, decisão 1 do commit 3); próprio cadastro sem Arquivar (D3), reconhecido pelo id da sessão CI ou pelo login da sessão (CI ou administrativa).
4. **Painel de confirmação numa linha própria, abaixo do cadastro**; no celular a tabela rola na horizontal e o painel fica preso à esquerda, na largura da tela. A recusa do servidor (por exemplo, login em uso ao restaurar) aparece no painel e no aviso; o painel continua aberto.
5. **Sucesso do arquivamento:** a mensagem do servidor mais o resumo dos efeitos ("1 vínculo de PA encerrado; 1 regra de escala encerrada; ..."); cada item de `avisos` (allowlist, estado de pausas) vira um alerta à parte. Restaurar mostra a mensagem do servidor ("PA e escala precisam ser cadastrados de novo").
6. **Perfil gestor sem mudança:** não tem `funcionarios.manage`, então a aba Funcionários já não aparece para ele (nem pelo endereço); o print mostra a Administração só com Aprovações.
7. **QAs estruturais atualizados de propósito:** inventário de `window.confirm` do `AdminPage.jsx` de 3 para 2; `employeeRemoved` trocado pelas duas mensagens novas; o campo `ativo` deixa de ser exigido no formulário e passa a ser proibido; o comentário novo não cita `window.confirm` (os QAs por `git diff` da árvore pegam o texto). Nenhuma checagem foi afrouxada.

**Fora do escopo, só apontado:** o texto do "Adicionar funcionário" ainda diz "Equipe Liderança aplica automaticamente o perfil Admin", o que deixou de valer no Lote 5b. No celular, o título da seção "Funcionários" fica dentro da área que rola com a tabela (já era assim).

**Validação pela IA:** `qa:employee-form` com testes de comportamento novos (situação do cadastro com `ativo` booleano, numérico e em texto; filtro; resumo com singular, plural, contagem inválida e booleano estrito; `ativo` e `arquivado_em` fora do corpo enviado) e de estrutura (endpoints e corpo, permissões, regra do botão, confirmação na linha, sem diálogo do navegador, filtro padrão); `qa-security` com a listagem (`arquivado_em` para o admin, ausente para gestor e técnico); `qa-archive` com 573 verificações. **Mutação 18/18** na lógica nova da tela (na 1ª rodada sobreviveram três, por teste fraco: contagem fracionada, booleano estrito e o filtro, que foi para função pura com teste). Bateria PHP completa, 15 `qa:*` do frontend, lint e build. Prints com servidor simulado e dados fictícios (16: admin, Liderança e gestor, desktop 1366 px e celular 390 px).

**Aprovado em 2026-10-13**, com as sete decisões como estão, após teste do responsável no navegador com o `EmployeeArchiveService` real e o MySQL local com a 014 (sessão simulada por perfil). **Admin:** painel de arquivar abaixo da linha com o texto dos efeitos; mensagem com o resumo ("1 vínculo de PA encerrado; 1 ausência futura cancelada") e o cadastro saindo de Ativos; "Arquivar (regularizar)" no inativo antigo com "regularizado e arquivado. 1 vínculo de PA encerrado"; restaurar com login em uso mostrou a recusa no painel; o cadastro com o login do próprio admin sem Arquivar (D3); arquivado "Em 10/10/2026" e inativo antigo "Inativo sem data"; edição sem o checkbox. **Liderança:** só Aprovações e Funcionários; cadastro da equipe Liderança sem Arquivar; arquivados sem Restaurar ("Só administradores restauram"). **Gestor:** só Aprovações.

**Pedidos para o commit 6 (2026-10-13):** corrigir o texto do "Adicionar funcionário" ("Equipe Liderança aplica automaticamente o perfil Admin", que não vale desde o Lote 5b) pela regra atual: o perfil vem do cadastro e da allowlist, e a equipe não concede permissão. Registrar como pendência, sem mudar agora: no celular, a tabela de Funcionários rola na horizontal e Editar/Arquivar ficam fora da tela de 390 px; candidata a cartões, como na tela de Ausências, num lote de acabamento. Depois o commit 6 do plano: ponta a ponta no `qa-security` (`status.php`, `dashboard.php` e Mapa PA com arquivado, com estado por caso), mutação, `API_CONTRACTS_FRONTEND.md`, LGPD 5.3, `DEPLOY_LINUX.md` ("Deploy que traz o F4", com o ensaio da 014 nas três variantes, a Q-F4 antes, a 014 antes do merge com `cat-file` e `pipefail`, a verificação e o passo pós-deploy da D5), registro e esta seção com o F4 completo.

**Commit 6 entregue (2026-10-13, `7389af2`; aprovado em 2026-10-13):** ponta a ponta e documentação. **Com ele o F4 fica completo.**

| Arquivo | Como ficou |
|---|---|
| `frontend/src/pages/AdminPage.jsx` | Texto do "Adicionar funcionário" pela regra do Lote 5b: "O perfil de acesso vem deste cadastro; admin vem só da lista AD_ADMIN_USERS. A equipe não concede permissão." (com checagem no `qa:employee-form`) |
| `scripts/qa-security.php` | Estado por caso (`state` no caso: diretório próprio com `estado.json` e `funcionarios.json` semeados) e leitura do Mapa PA no banco simulado só nos casos que a pedem (`db['pa_map']`). Ponta a ponta: `status.php` (técnico, com e sem estado de pausas) sem os arquivados, a pausa e a solicitação deles; `dashboard.php` (gestor e técnico) com `tecnicos_monitorados`, `pausas_ativas`, `solicitacoes_pendentes` e `active_pauses` só dos ativos; Mapa PA de hoje sem o vínculo do arquivado e o de ontem com ele (D13); nome do arquivado fora das respostas |
| `docs/API_CONTRACTS_FRONTEND.md` | Contratos em uso: `status.php`, `solicitacoes_pendentes.php`, `portal/pa_map.php` (GET e `save`), `portal/dashboard.php`, `listar_funcionarios.php` (`arquivado_em`), cadastro (D4), `remover_funcionario.php` (arquivar: `efeitos`, `regularizacao`, `avisos` e os status) e `restaurar_funcionario.php` (novo); contratos propostos do F4 marcados como implementados; `presence` do `technicians.php` no F7 (D14) |
| `docs/DOCUMENTACAO_TECNICA.md` (LGPD 5.3) | Linha "Cadastro arquivado e seu histórico": o que o F4 encerra e o que fica, `arquivado_por` como `created_by`, listagem só com a data, auditoria e fila só com ids e contagens; retenção e anonimização continuam A DEFINIR (D7) |
| `DEPLOY_LINUX.md` | Roteiro "Deploy que traz o F4" (pré-requisitos, janela, ponto de rollback, backup, ensaio da 014 nas três variantes, Q-F4, 014 e `git merge` no mesmo comando com `cat-file` e `pipefail`, build, recarga, verificação, passo pós-deploy da D5, falhas, rollback da 014 e operação) e o item na lista de "Atualização de Versão". Entra no roteiro consolidado da Parte B (pendência 16) |

**Decisões de implementação do commit 6 (aprovadas em 2026-10-13):**

1. **Estado por caso no `qa-security`:** o caso com `state` ganha diretório próprio, com os arquivos semeados; os demais continuam dividindo o do QA. Os cadastros do `status.php` e do `dashboard.php` vêm do `funcionarios.json` semeado (o banco simulado não lista cadastros; é o mesmo caminho de fallback do `config.php`); a sessão continua revalidada pelo `QA_DB`.
2. **Mapa PA no banco simulado só com `db['pa_map']`:** os outros casos continuam vendo o banco fora, como antes; o filtro de ativo só vale com a cláusula exata do serviço.
3. **`solicitacoes_pendentes.php` sem ponta a ponta:** com a gestão ele chama o `OperationalService`, que no banco simulado responde 503; fica a checagem estrutural do `qa-archive` (commit 4) e o contador de pendentes do `dashboard.php`, que é ponta a ponta.
4. **Roteiro, passo 9:** a consulta que acha o cadastro a regularizar devolve **só o ID interno** (sem nome nem login), para localizar a linha na tela ("ID n"). É a única exceção à regra "só contagens" do roteiro; a alternativa é cruzar, na tela, o Mapa PA de ontem com a lista de arquivados.
5. **Roteiro, Q-F4 diferente da referência:** não bloqueia a 014 (ela não altera linha), mas o passo 9 só roda com os números de 2026-10-13 (`inativos_com_pa = 1`, regra e exceção `0`); com outro número, parar e avisar o responsável.
6. **Roteiro, 014 e `merge` no mesmo comando** (como a 012), embora o código anterior funcione sobre a 014 e não haja janela de 503: o `&&` garante que o código do F4 nunca rode sem as colunas.
7. **Roteiro, rollback da 014:** a cópia `funcionarios_bkp_014` guarda o usuário de quem arquivou; o roteiro manda apagá-la à mão depois de conferir, com aprovação (LGPD). PA, escala e ausências encerrados não voltam com o rollback.

**Pendência registrada (pedido de 2026-10-13, sem mudança agora):** no celular, a tabela de Funcionários rola na horizontal e Editar/Arquivar ficam fora da tela de 390 px; candidata a cartões, como na tela de Ausências, num lote de acabamento (pendência 17 do estado da sessão; seção 10).

**Validação pela IA:** `qa-security` com 6 casos novos de ponta a ponta (status, dashboard e Mapa PA com arquivado; 15 verificações novas). **Mutação 14/14** contra o `qa-security` (L-07 no `obter_status` e no `dashboard.php`, D13 com hoje e ontem, perfil no arquivar, permissão e lista de perfis do restaurar, `arquivado_em` na listagem, D4 no atualizar e no adicionar, D3 no serviço). Bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** o roteiro não foi executado; os comandos seguem os do deploy 11, já executados pelo responsável, e as consultas da 014 foram testadas no MySQL local dele (commit 1).

**F4 completo (2026-10-13):** commit 1 `1abb37a` (014), commit 2 `3944cc7` (planejador), commit 3 `dfc6b28` (serviço e endpoints), commit 4 `85813e1` e decisão 8 `d15f132` (L-07 e conflito do Mapa PA), commit 5 `47f4412` (tela), commit 6 `7389af2` (ponta a ponta e documentação); documentação de cada passo nos commits seguintes. Deploy: dentro do deploy único da Parte B, depois do F9, pelo roteiro "Deploy que traz o F4" do `DEPLOY_LINUX.md`.

**Aprovado em 2026-10-13**, com as sete decisões como estão, após revisão do responsável do commit 6 e do roteiro "Deploy que traz o F4": conferência de branch e `f4-commit`, ensaio com os arquivos da versão nova, Q-F4 antes, 014 e `merge` com `cat-file` e `pipefail`, `HEAD` conferido, passo da D5 só com o ID interno, rollback e verificação com a mesma proteção. **F4 completo.**

### 9.7 F5 — Calendário e relatórios no motor, com a data de corte: plano aprovado (2026-10-13)

Plano entregue em 2026-10-13 e gravado aqui antes de qualquer código (lição do F4: plano só no chat se perde). **Aprovado em 2026-10-13 com o ajuste da D2 e o complemento da D3** (texto abaixo já ajustado); D1 e D4 a D14 aprovadas como estão; a mudança da D9 está registrada na seção 5.5.

**Fatos lidos no código (2026-10-13):**

| Fato | Onde |
|---|---|
| Calendário e relatório ainda usam o cálculo antigo: `generatedSchedule` (fim de semana conta, `undefined` vira remoto pelo `presenceForRule`, só ausência de dia inteiro tira o dia) | `OperationalService::listCalendar` (`OperationalService.php:260`), `report` (`:1027`), `generatedSchedule` (`:1629`) |
| A tela Escala já está no motor desde o F2B (`engineSchedule`, `PresenceEngine::dayPresence`): P3, P4, ausência, Liderança implícita, fora do Mapa | `OperationalService.php:1714` |
| O cálculo antigo só lê cadastro ativo (`JOIN funcionarios ... ativo = 1`): o arquivado some de todo o histórico de escala e de ausências | `scheduleRules` (`:1548`), `scheduleBlockingAbsences` (`:1866`), `scheduleScreenEmployees` (`:1788`) |
| No Calendário, a ausência é um evento à parte (`event_type = absence`), só aprovada; categoria só para a gestão e o próprio (P9, F3); observação nunca | `listCalendar` (`:199-225`) |
| Os rótulos sem acento ("Dias impares", "Ciclo com configuracao invalida", "Regra nao informada", "Ausencia") vêm do backend e entram nas saídas antigas | `scheduleRuleLabel` (`:2083`), `scheduleStatusLabel` (`:1905`) |
| `qa-schedule-legacy-output` compara **byte a byte** (sha256, bytes, linhas) 17 saídas de `report()`, `streamReportCsv()` e `listCalendar()` com a referência gravada antes do F2B, com o "hoje" injetado (`2026-10-07`) e dados de 2026 | `scripts/qa-schedule-legacy-output.php`, `scripts/fixtures/schedule-legacy-output.json` |
| Não existe data de corte no código nem no banco. O corte do BIZ-02 fica em `manutencao_marcadores`, tabela da manutenção do fuso | `migrations/correcao_dados/biz02_01_registrar_corte.sql` |
| Frontend: tons do Calendário locais, War Room em vermelho e ausência em laranja; `CalendarPage` duplicada sem uso (L-13); "Carregando mÃ³dulo..." (L-14); relatório mostra só "Dias presenciais" e "Dias remotos" | `CalendarPage.jsx:20-28`, `OperationalPages.jsx:255`, `App.jsx:226`, `OperationalPages.jsx:1087` |
| Pendência 8a da tela Escala (F2B): filtro "ocultar sem escala", rótulos sem acento, quebra de "Liderança" na coluna Equipe | Seção 9.3 |
| Migrations: 011, 012, 014 a 017 usadas; 013 dispensada; 018 reservada ao contrato de almoço (F8, depois da Liderança). Próximo número livre: **019** | `migrations/` |

**Escopo.** (1) Data de corte do motor: guardada no banco, registrada no deploy com valor explícito, auditada e somente leitura para a aplicação. (2) Relatório operacional (JSON) e CSV: dias antes do corte pelo cálculo antigo, sem nenhuma mudança; dias a partir do corte pelo motor (P3, P4, ausência, histórico do arquivado). (3) Calendário: a partir do corte, escala pelo motor e ausência como situação do técnico (P9 mantida); antes do corte, como hoje. (4) Histórico do arquivado até `arquivado_em` no relatório e no Calendário (F4). (5) Frontend: legenda única (seção 5.1) no Calendário e na tela Escala, mês com resumo por dia (P15), War Room em outro tom, relatório com os campos novos e o aviso do corte, L-13, L-14 e a pendência 8a. **Fora:** Mapa PA no motor e datas passadas do Mapa PA (F6, ver D12); contadores e Visão Operacional (F7); `presence` no `technicians.php` (F7, D14 do F4); legenda no Mapa PA (F6) e na Visão Operacional (F7).

**Commits:**

| # | Commit | Arquivos |
|---|---|---|
| 1 | Migration 019, registro do corte, rollback, verificação e ensaio | `migrations/20261013_019_presence_cutoff.sql`; `migrations/20261013_019_registrar_corte.sql` (script do deploy, com a data explícita); `migrations/rollback/20261013_019_presence_cutoff_down.sql`; `migrations/verificacao/20261013_019_presence_cutoff_check.sql`; `scripts/qa-presence-cutoff.php` (novo, estrutura SQL; nos dois runners) |
| 2 | Corte e relatório | `services/PresenceCutoff.php` (novo: leitura do corte e divisão do período, função pura para a divisão); `services/OperationalService.php` (`report`, `streamReportCsv`, escala do motor com o histórico do arquivado, rótulos com acento só no caminho do motor); `qa-presence-cutoff` (divisão, relatório, CSV) e `qa-schedule-legacy-output` (seção nova: saída antiga com o corte depois do período e com o corte no meio) |
| 3 | Calendário | `OperationalService::listCalendar` (escala do motor a partir do corte, ausência como situação, P9, fora do Mapa); `qa-presence-cutoff` (Calendário por perfil); `qa-schedule-legacy-output` |
| 4 | Frontend | `frontend/src/lib/presenceLegend.ts` (novo, legenda única); `CalendarPage.jsx` (mês com resumo, semana e dia com cartão por técnico, legenda, War Room); `OperationalPages.jsx` (tela Escala com a legenda, "Ocultar sem escala", "Liderança" sem quebra; relatório com ausência, sem escala e aviso do corte; sai a `CalendarPage` duplicada, L-13); `App.jsx` (L-14); QAs estruturais e de comportamento |
| 5 | Ponta a ponta e documentação | `qa-security` (Calendário e relatório por perfil com o corte), mutação; `API_CONTRACTS_FRONTEND.md`, `DEPLOY_LINUX.md` ("Deploy que traz o F5": ensaio da 019, 019 antes do `merge`, registro do corte depois da recarga, verificação), LGPD 5.3 se couber, registro, esta seção |

**Migration 019 (tabela do corte):**

| Item | Proposta |
|---|---|
| Tabela | `portal_presence_cutoff`: `id TINYINT` (PK, `CHECK (id = 1)`: no máximo uma linha), `cutoff_date DATE NOT NULL` (`CHECK` dia 16), `registered_by VARCHAR(100) NOT NULL`. O quando fica no `audit_log` (ver decisão 1 do commit 1). Regra do A4 (`SET time_zone`, sem `NOW()` nas gravações), idempotente pelo `information_schema` |
| Migration | Só cria a tabela, **vazia**. Sem linha, a aplicação usa o cálculo antigo em todas as datas: nada muda até o registro |
| Registro do corte | Script à parte, rodado no dia do deploy depois da recarga, com a data e o autor explícitos (`SET @corte := 'AAAA-MM-DD'; SET @autor := '<login>';` antes do arquivo). A data é o **1º dia da competência seguinte ao deploy** (D2), que pode estar no futuro. Para com `SIGNAL` se `@corte` ou `@autor` faltarem, se a data for inválida, **anterior ao dia em que roda** (nunca reescrever dia passado), **diferente de dia 16** ou se já houver corte (imutável). Grava a linha e um evento `PRESENCE_CUTOFF_REGISTERED` (`CRITICAL`) no `audit_log` com a data (sem outro dado) |
| Rollback | Para com `SIGNAL` se a cópia `portal_presence_cutoff_bkp_019` existir; copia a linha e remove a tabela. Sem a tabela, a aplicação volta ao cálculo antigo em todas as datas |
| Verificação | Só contagens e a data: `tabela_019`, `cortes` (0 ou 1), `corte` (a data), `eventos_registro` no `audit_log` |
| Ensaio | Três variantes no `ensaio-migration.sh`: (a) 019 duas vezes, rollback, 2º rollback para; (b) registro do corte duas vezes (o 2º para); (c) rollback com o corte registrado (a cópia guarda a linha). As recusas do registro (sem data, data passada, dia diferente de 16) são conferidas à parte, no banco de ensaio |

**Como a aplicação usa o corte.** `PresenceCutoff::date()` lê a linha uma vez por requisição; tabela ausente ou vazia dá `null`. `PresenceCutoff::split(from, to, corte)`, pura, devolve a parte antiga (`from` até a véspera do corte) e a parte nova (do corte até `to`), cada uma podendo ser vazia. Com `null`, tudo é parte antiga. **Corte no futuro** (D2): até a data, tudo no cálculo antigo; período inteiro antes do corte sai byte a byte igual; período que cruza o corte futuro é dividido nele, como qualquer outro.

**Relatório e CSV:**

| Período | Saída |
|---|---|
| Inteiro antes do corte (ou sem corte) | **Byte a byte igual à de hoje**: mesmas chaves, mesmas linhas, mesmos rótulos (é o que o `qa-schedule-legacy-output` confere). Nenhum campo novo |
| Cruza o corte | Linhas da escala antes do corte iguais às do cálculo antigo; a partir do corte, do motor. Totais somam as duas partes. JSON ganha `cutoff` (`{ date, split: true }`), `absence_days` e `no_schedule_days` (em `summary`, `by_employee` e `by_team`). CSV ganha, logo depois do cabeçalho, uma linha `aviso` com o texto "Escala pelo cálculo antigo até DD/MM/AAAA e pelas regras novas a partir de DD/MM/AAAA" |
| Inteiro a partir do corte | Só o motor; `cutoff` com `split: false`; sem linha de aviso no CSV |

Regras novas (a partir do corte): fim de semana não conta, salvo exceção de trabalho (P3); sem regra é "Sem escala" e conta em `no_schedule_days`, não como remoto (P4); ausência aprovada de dia inteiro conta em `absence_days` e sai no CSV com o rótulo "Ausência", **sem a categoria**; ausência parcial não tira o dia (o dia conta pela escala); fora do Mapa fica fora da seção de escala; Liderança como "Presencial (regra fixa)"; arquivado até a véspera de `arquivado_em` (D7). Horas extras, ajustes e plantões não mudam.

**Calendário:**

| Período | Saída |
|---|---|
| Antes do corte | Como hoje: escala do cálculo antigo e ausência como evento à parte, com a regra de leitura do F3 |
| A partir do corte | Um evento `schedule` por técnico e dia útil, com os campos de hoje (`title`, `description`, `status` = `onsite`, `remote`, `absence` ou `no_schedule`) e mais `situacao`, `origem` e `detalhe` do motor. Ausência deixa de ser evento à parte: vira a situação ("Ausente (dia todo)") ou o marcador da parcial ("Ausente a partir de HH:MM"). Categoria só para a gestão e o próprio (P9); observação nunca. Fim de semana sem evento, salvo exceção de trabalho. Fora do Mapa: sem escala, e a ausência dele continua como evento `absence` (tabela da P12) |

Ausência que cruza o corte: o evento `absence` antigo vai até a véspera do corte; do corte em diante, vira situação. Filtros: `type=absence` traz também as situações de ausência; `type=onsite` e `type=remote` filtram pela situação; `status` segue o mesmo mapa. Os demais tipos (manual, pausa, War Room, hora extra, ajuste, plantão) não mudam.

**Frontend:**

| Tela | Mudança |
|---|---|
| Legenda única | `frontend/src/lib/presenceLegend.ts`: situação → rótulo, ícone, cor e forma (seção 5.1); nenhuma situação só pela cor; usada no Calendário e na tela Escala neste lote |
| Calendário | Mês com resumo por dia ("P 9 · R 6 · A 2 · S 1", com ícone e cor da legenda; P15); semana e detalhe do dia com um cartão por técnico; legenda fixa no rodapé; War Room em outro tom (violeta), para não se confundir com ausente; dias antes do corte com os cartões de hoje |
| Relatório | "Dias de ausência" e "Dias sem escala" quando o JSON trouxer os campos; aviso do corte quando o período cruzar a data |
| Tela Escala (pendência 8a) | Filtro "Ocultar sem escala" (desligado por padrão); rótulos com acento; "Liderança" sem quebra na coluna Equipe; status com a legenda única |
| L-13 e L-14 | Sai a `CalendarPage` duplicada de `OperationalPages.jsx`; "Carregando módulo..." corrigido |

**Riscos.** (1) Números do relatório mudam a partir do corte (P3, P4, ausência, arquivado): validar com quem consome o CSV antes do deploy (risco já registrado na seção 10). (2) Rollback do código depois do corte: o relatório volta ao cálculo antigo em todas as datas, sem perda de dado; a 019 pode ficar (o código anterior não a lê). (3) Corte registrado na data errada: o registro é imutável e recusa data passada e dia diferente de 16; corrigir exige o rollback do registro e um registro novo, os dois auditados e com aprovação. Com o corte no futuro, até lá o código novo só muda o que não depende dele (tela Escala, já no motor; legenda; pendência 8a). (4) Carga: relatório de até 366 dias passa pelo motor (cadastros × dias, sem consulta a mais por dia); medir no teste do responsável com um ano de dados. (5) O contrato do relatório tem campos opcionais (só aparecem quando o período alcança o corte): o frontend trata a ausência. (6) QAs com `git diff` podem disparar em `CalendarPage.jsx` e `OperationalPages.jsx` (dívida técnica registrada). (7) Sem MySQL na estação: 019 e registro do corte só ficam provados no ensaio e no teste do responsável.

**Testes.** `qa-presence-cutoff` (novo): estrutura SQL da 019, do registro, do rollback e da verificação (regra do A4, `SIGNAL`, auditoria sem dado pessoal); `split` em tabela de casos (sem corte, período antes, depois, cruzando, corte no primeiro e no último dia, um dia, **corte no futuro**: período inteiro antes dele com a saída antiga byte a byte e período que cruza dividido no corte); registro com data passada, dia diferente de 16, sem data e repetido recusados; relatório e CSV com o corte no meio (linhas antes idênticas às do cálculo antigo, totais, aviso, campos novos só quando o período alcança o corte); arquivado até a véspera de `arquivado_em` e inativo antigo fora; fora do Mapa fora; Calendário por perfil (gestão, próprio, outro técnico, somente leitura): categoria só para quem pode, observação nunca, ausência sem evento duplicado. `qa-schedule-legacy-output`: as 17 saídas continuam idênticas à referência **sem corte e com o corte depois de cada período**; seção nova com o corte no meio, conferindo que a parte antiga é igual linha a linha. `qa-security`: matriz sem mudança; Calendário e relatório ponta a ponta com o corte. Frontend: legenda (toda situação com rótulo e ícone), Calendário, relatório, tela Escala, os 15 `qa:*` e lint. Mutação no `split`, no relatório, no Calendário e na legenda. Validação humana em MySQL 8.0.46.

**Decisões a aprovar:**

| # | Decisão | Recomendação |
|---|---|---|
| D1 | Onde fica o corte | Tabela própria (019), uma linha, gravada só pelo script de registro do deploy, auditada; a aplicação só lê. Recusadas: variável no `.env` (sem trilha no banco, muda sem rastro) e configuração pela tela (um admin poderia mover o corte e reescrever períodos fechados) |
| D2 | Qual data | **Ajustada na aprovação:** o 1º dia da competência seguinte ao deploy da Parte B (competência de 16 a 15, pela `competencyRange`), para nenhuma competência do relatório ou do CSV misturar as duas regras. O registro roda no dia do deploy com essa data explícita, que pode estar no futuro |
| D3 | Corte imutável | O registro para se já houver corte; trocar só com rollback do registro e registro novo, os dois auditados e com aprovação. **Complemento da aprovação:** o registro também recusa (`SIGNAL`) data anterior ao dia em que roda e data que não seja dia 16; o código trata corte no futuro (até a data, tudo no cálculo antigo) |
| D4 | Contrato do relatório | Período inteiro antes do corte: saída byte a byte igual (sem campo novo). Período que alcança o corte: `cutoff`, `absence_days` e `no_schedule_days`; CSV com linha `aviso` só quando cruza |
| D5 | Regras do relatório a partir do corte | P3, P4, ausência de dia inteiro em `absence_days` (CSV "Ausência", sem categoria), parcial não tira o dia, fora do Mapa fora, Liderança regra fixa |
| D6 | Período que cruza o corte | Divide no corte: antes pelo cálculo antigo (linhas idênticas), depois pelo motor; totais somados |
| D7 | Histórico do arquivado (F4) | No relatório e no Calendário, a partir do corte, o cadastro com `arquivado_em` entra nas datas anteriores ao arquivamento; inativo antigo (data nula) não entra depois do corte; antes do corte, como hoje (sem inativo). A tela Escala continua só com ativos |
| D8 | Calendário a partir do corte | Um evento por técnico e dia útil com a situação do motor; ausência vira situação (categoria pela P9, observação nunca); fora do Mapa mantém o evento de ausência; filtros `type` e `status` pelo mapa da situação |
| D9 | Dia atual no Calendário | **Sem relógio** (`dayPresence`): o Calendário não se atualiza sozinho, então "Fora do expediente" e "Em pausa" ficam no painel ao vivo e na Visão Operacional (F7). **Muda a seção 5.5**, que previa "Fora do expediente" no dia atual |
| D10 | Legenda e mês | Legenda única em `frontend/src/lib/presenceLegend.ts`, no Calendário e na tela Escala neste lote; mês com resumo por dia, incluindo "S" (sem escala); War Room em violeta |
| D11 | Acentos (pendência 8a) | Só no caminho do motor (tela Escala; relatório e Calendário a partir do corte). O cálculo antigo fica com os rótulos de hoje, para a saída antiga continuar idêntica |
| D12 | Datas passadas do Mapa PA (D13 do F4) | Nada no F5: fica no F6, que põe o Mapa PA no motor. O passado já é consistente: o arquivamento fecha o PA em ontem, e o inativo antigo com PA aberto deixa de existir no passo da D5 |
| D13 | "Ocultar sem escala" (pendência 8a) | Filtro só da tela, desligado por padrão, sem mudar o contrato do `schedules.php` |
| D14 | Registro do corte no deploy | No dia do deploy, depois da recarga do PHP-FPM, com a data da D2; antes dele o código novo funciona no cálculo antigo (tabela vazia), então não há janela |

**Commit 1 entregue (2026-10-13, `03359b5`; aprovado em 2026-10-14, ver abaixo):** migration 019, registro do corte, rollback, verificação e ensaio.

| Arquivo | Como ficou |
|---|---|
| `migrations/20261013_019_presence_cutoff.sql` | Um passo condicional (`information_schema`): `CREATE TABLE portal_presence_cutoff` (`id`, `cutoff_date`, `registered_by`; `CHECK (id = 1)` e `CHECK (DAYOFMONTH(cutoff_date) = 16)`), vazia. Sem `DEFAULT` de data, sem linha, sem relógio do banco |
| `migrations/20261013_019_registrar_corte.sql` | Não é migration de deploy (o cabeçalho avisa). Procedimento temporário `registrar_corte_019(@corte, @autor)`: sete recusas na ordem (sem a 019; sem `@corte` ou `@autor`; fora do formato `AAAA-MM-DD`; dia diferente de 16; mês ou ano inválido; data anterior ao dia em que roda; corte já registrado), todas antes da transação e com "Nada foi alterado."; depois, a linha e o evento `PRESENCE_CUTOFF_REGISTERED` (`CRITICAL`, só a data) na mesma transação, com `ROLLBACK` e `RESIGNAL` em qualquer erro. O cabeçalho traz a consulta (só leitura) que dá a data da D2 no dia do deploy e os comandos de ensaio (b) e (c) |
| `migrations/rollback/20261013_019_presence_cutoff_down.sql` | Para se `portal_presence_cutoff_bkp_019` existir; com a tabela presente: copia `id`, `cutoff_date`, `registered_by`, grava `PRESENCE_CUTOFF_REMOVED` (`CRITICAL`, só a data) se havia corte e remove a tabela |
| `migrations/verificacao/20261013_019_presence_cutoff_check.sql` | Só leitura: `tabela_019`, `checks_019`, `copia_rollback`, `procedimentos_soltos`; com a tabela, `cortes`, `corte`, `corte_dia_16`, `corte_no_futuro`, `sem_autor`; com o `audit_log`, `eventos_registro` e `eventos_remocao`. O autor nunca sai |
| `scripts/qa-presence-cutoff.php` (novo, nos dois runners) | Seção 1, estrutura: regra do A4 (o relógio só na recusa de data passada do registro e no "hoje" da verificação), `PREPARE`/`EXECUTE`/`DEALLOCATE`, idempotência, as sete recusas na ordem e antes da transação, mensagens de até 128 caracteres, gravação e auditoria juntas, sem conversão de texto em data, sem nome nem login, consulta da D2 conferida contra a `competencyRange` (deploy em 13, 15, 16 e 31 de outubro, 20 de dezembro e 31 de janeiro), comandos de ensaio nos cabeçalhos |

**Decisões de implementação do commit 1 (a aprovar):**

1. **`registered_at` saiu da tabela:** o quando do registro fica na coluna `timestamp` do `audit_log` (pelo `DEFAULT` dela, como na 016b), para nenhum valor gravado pelo script vir do relógio do banco (regra do A4).
2. **Data montada, não convertida:** com o formato conferido por expressão regular e o dia exigido como `16`, a data é montada (`MAKEDATE` + mês + 15 dias; dia 16 existe em todo mês). Sem `STR_TO_DATE`, que em modo estrito pode virar erro no lugar da recusa clara.
3. **Uma recusa a mais:** sem a 019, o registro também para, com mensagem própria.
4. **Procedimento que sobra depois de uma recusa** (o `DROP` do fim não roda depois do erro): a próxima execução o recria; a verificação conta (`procedimentos_soltos`); para limpar, `DROP PROCEDURE IF EXISTS registrar_corte_019`.
5. **Ensaio do registro sem arquivo novo no repositório:** as variantes (b) e (c) montam um arquivo temporário com `@corte` e `@autor` na frente do script (2099-12-16 válido; 2020-01-16 passado); as demais recusas trocando só o `printf`. A 019 entra como `PREPARO` e o `audit_log` como tabela extra.
6. **Rollback audita a remoção** (`PRESENCE_CUTOFF_REMOVED`, só a data) e a cópia guarda o autor do registro (apagar à mão depois de conferir, como a do F4).
7. **Usuário no `audit_log`:** `registro_019` e `rollback_019` (como `migration_016b`); o login do operador fica só na tabela.
8. **Nome dos arquivos com a data do commit** (`20261013_019`), não `20261014` do plano.

**Como testar no MySQL local (sugestão):** num banco de teste com `funcionarios` e `audit_log`: (1) a 019 duas vezes e a verificação (`tabela_019 = 1`, `checks_019 = 2`, `cortes = 0`); (2) o registro com `SET @corte := '<próximo dia 16>'; SET @autor := 'qa.teste';` na frente (`cortes = 1`, `corte_no_futuro = 1`, `eventos_registro = 1`); (3) o registro de novo (para: corte já registrado); (4) num banco sem corte, as recusas: sem as variáveis, `'2020-01-16'` (data passada), `'2026-11-15'` (dia 15), `'2026-13-16'` (mês 13), `'16/11/2026'` (formato), e sem a 019; depois de cada recusa, `cortes = 0` e `procedimentos_soltos = 1`; (5) `INSERT` direto com dia 15 ou `id = 2`: recusado pelos `CHECK` (3819); (6) o rollback (`copia_rollback = 1`, `tabela_019 = 0`, `eventos_remocao = 1`) e o 2º rollback (para). Ensaio no servidor nas três variantes pelos cabeçalhos.

**Validação pela IA:** `qa-presence-cutoff` com 131 verificações; **mutação 24/24** no SQL (na 1ª rodada sobreviveram dois mutantes, por teste fraco: a falta do `DROP PROCEDURE IF EXISTS` e o autor exibido por `MAX(registered_by)` na verificação; os testes foram reforçados); bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** a 019, o registro, o rollback e a verificação não foram executados.

**Commit 1 aprovado em 2026-10-14**, com as oito decisões como estão, após teste do responsável no MySQL local, num banco de teste:

| Caso | Resultado |
|---|---|
| Registro antes da 019 | Parou ("aplique a migration 019 antes") |
| 019 duas vezes | Mesmo estado (`tabela_019 = 1`, `checks_019 = 2`) |
| Recusas | Sem `@corte`, autor em branco, `2020-01-16`, `2026-11-15`, `2026-13-16`, `16/11/2026`: nada gravado |
| Data futura | Aceita: `cortes = 1`, `corte_no_futuro = 1`, `PRESENCE_CUTOFF_REGISTERED` no `audit_log` |
| 2º registro | Parou ("corte já registrado") |
| `INSERT` direto com `id = 2` ou dia 15 | Recusado pelos `CHECK` (3819) |
| Três registros simultâneos | Um gravou e os outros falharam; nunca dois cortes |
| Rollback | Cópia, `PRESENCE_CUTOFF_REMOVED` e tabela fora; o 2º rollback parou |

**Ajuste pedido antes do commit 2:** a imutabilidade (D3) dependia só do script de registro: `DELETE` seguido de `INSERT` direto funcionava no banco e só foi barrado porque o dia não era 16. A 019 ganha dois gatilhos em `portal_presence_cutoff`, `BEFORE UPDATE` e `BEFORE DELETE`, que sempre param com `SIGNAL 45000` ("corte imutável: use o rollback da 019"), criados de forma condicional para a 019 continuar idempotente. O rollback continua com `DROP TABLE` (não passa pelos gatilhos). A verificação passa a contar os gatilhos (esperado 2). Testes no `qa-presence-cutoff`: gatilhos presentes, idempotência, rollback sem depender deles; mutação.

**Ajuste dos gatilhos entregue (2026-10-14, `b2c9bc5`; aprovado em 2026-10-14, ver abaixo):**

| Arquivo | Como ficou |
|---|---|
| `migrations/20261013_019_presence_cutoff.sql` | Passo 2, depois da tabela: `CREATE TRIGGER IF NOT EXISTS trg_presence_cutoff_no_update BEFORE UPDATE` e `trg_presence_cutoff_no_delete BEFORE DELETE ON portal_presence_cutoff FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'corte imutavel: use o rollback da 019'`. Sem condição, sem gatilho de `INSERT` (o registro grava; PK e `CHECK (id = 1)` barram a 2ª linha), sem `DROP TRIGGER`. O cabeçalho explica a idempotência, a versão mínima e o caso do `TRUNCATE` |
| `migrations/rollback/20261013_019_presence_cutoff_down.sql` | Sem mudança de comando: `DROP TABLE` leva os gatilhos junto; nenhum `UPDATE` nem `DELETE` na tabela do corte. Só o cabeçalho |
| `migrations/verificacao/20261013_019_presence_cutoff_check.sql` | Coluna nova `gatilhos_019` em `information_schema.TRIGGERS`: conta os dois pelo nome, evento (`UPDATE`, `DELETE`), momento (`BEFORE`), tabela e corpo (a mensagem). Esperado 2 depois da 019 e 0 antes e depois do rollback |
| `scripts/qa-presence-cutoff.php` | Gatilhos com o texto exato, depois da tabela, só os dois e ambos com `IF NOT EXISTS`; sem `DROP TRIGGER`, sem gatilho de `INSERT` nem `AFTER`; mensagem dentro de 128; registro e rollback sem `TRIGGER`; rollback com um único `DROP TABLE` e sem `UPDATE`/`DELETE`; `gatilhos_019` na verificação |

**Decisões de implementação do ajuste (a aprovar):**

1. **Condicional por `CREATE TRIGGER IF NOT EXISTS`, não por `IF` sobre o `information_schema.TRIGGERS`.** O MySQL não aceita `CREATE TRIGGER` em `PREPARE` (erro 1295) nem dentro de procedimento (erro 1303), que são os dois caminhos de `IF` usados nas migrations; o `IF NOT EXISTS` do próprio `CREATE TRIGGER` existe desde o **MySQL 8.0.29**. O `information_schema.TRIGGERS` fica na verificação (`gatilhos_019`). O cabeçalho pede `SELECT VERSION()` antes do ensaio (o servidor usa a imagem `mysql:8.0`; o teste local, 8.0.46). Em versão anterior, a 019 pararia com erro de sintaxe depois de criar a tabela, e rodá-la de novo numa versão nova completa os gatilhos.
2. **Mensagem sem acento** ("corte imutavel: use o rollback da 019"), como todas as mensagens dos scripts SQL do projeto: o cliente `mysql` do `docker exec` não garante o charset do arquivo.
3. **`gatilhos_019` confere o corpo** (a mensagem): gatilho com o mesmo nome e outro corpo não conta.
4. **Sem `DROP TRIGGER` na 019:** recriar abriria uma janela sem proteção. Um gatilho com o mesmo nome criado antes, por outro caminho, não é trocado; a verificação mostra menos de 2.
5. **`TRUNCATE` não passa por gatilho** (comportamento do MySQL). Ele exige o privilégio `DROP`, que o usuário da aplicação não tem; fica registrado no cabeçalho como resíduo. `REPLACE` e `INSERT ... ON DUPLICATE KEY UPDATE` sobre a linha param (passam pelo `DELETE` ou pelo `UPDATE`).

**Como testar no MySQL local (sugestão):** num banco de teste: (1) `SELECT VERSION()` (8.0.29 ou mais novo); (2) a 019 duas vezes e a verificação (`gatilhos_019 = 2`); (3) o registro com um dia 16 futuro; (4) `UPDATE portal_presence_cutoff SET registered_by = 'x'`, `DELETE FROM portal_presence_cutoff`, `REPLACE INTO portal_presence_cutoff VALUES (1, '<outro dia 16>', 'x')` e `INSERT ... ON DUPLICATE KEY UPDATE`: todos com 45000 "corte imutavel: use o rollback da 019" e a linha intacta; (5) o rollback (`tabela_019 = 0`, `gatilhos_019 = 0`, cópia com a linha, `eventos_remocao = 1`); (6) a 019 de novo: tabela e gatilhos de volta.

**Validação pela IA:** `qa-presence-cutoff` com 143 verificações nesse commit; **mutação 20/20** (gatilho sem `IF NOT EXISTS`, sem o de `DELETE`, `AFTER`, de `INSERT`, em outra tabela, condicional, mensagem trocada, `DROP TRIGGER`, gatilho a mais, antes da tabela, cabeçalho sem a versão, rollback com `DELETE`, com `DROP TRIGGER` e sem `DROP TABLE`, registro com `UPDATE`, verificação com evento, corpo, momento, tabela ou coluna errados); bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** os gatilhos não foram executados.

**Commit 2 entregue (2026-10-14, `7e08b03`; aprovado em 2026-10-14, ver abaixo):** corte e relatório.

| Arquivo | Como ficou |
|---|---|
| `services/PresenceCutoff.php` (novo) | `read(PDO)`: confere a tabela no `information_schema` (mesma consulta do `PaMapService`) e lê `cutoff_date` da linha 1; tabela ausente ou vazia dá `null` (sem consultar a tabela ausente); valor que não é data válida de dia 16 lança `UnexpectedValueException` (o endpoint responde 500 genérico e loga a classe). `split(from, to, corte)`, pura: `legacy` (de `from` até a véspera do corte) e `engine` (do corte até `to`), cada uma podendo ser `null`. `splitNotice(corte)`: o texto do aviso do CSV |
| `services/OperationalService.php` | `report`: corte lido uma vez por instância (uma por requisição); parte antiga pelo `generatedSchedule` de sempre, parte nova pelo `reportEngineSchedule` (motor da tela Escala com o histórico do arquivado); totais somados; `absence_days` e `no_schedule_days` só da parte do motor; `cutoff` e os campos novos só quando o período alcança o corte. `streamReportCsv`: linha `aviso` depois do cabeçalho só com `split: true`. `scheduleRules`, `scheduleScreenEmployees` e `scheduleBlockingAbsences` com `$withArchived` (`ativo = 1 OR arquivado_em IS NOT NULL`; a consulta da tela não muda); `engineSchedule` pula o arquivado a partir da data de `arquivado_em`. Rótulos com acento por padrão e `LEGACY_LABELS` devolvendo os de hoje no cálculo antigo (D11) |
| `scripts/qa-presence-cutoff.php` | Seção 2: `split` em tabela de casos (sem corte, antes, véspera, dia do corte, cruzando, começando no corte, depois, um dia, janeiro, corte no futuro, ano inteiro) e recusas; aviso; leitura (sem a 019 sem consultar a tabela, vazia, válida, seis valores inválidos). Seção 3, banco simulado próprio: parte antiga idêntica ao cálculo antigo; motor com P3 (sábado só com plantão), P4 (sem regra e `undefined`), ausência que cruza o corte, folga por exceção, parcial como marcador, Liderança, arquivado até a véspera, inativo antigo e fora do Mapa fora, pendente ignorada; rótulos; sem categoria nem observação; resumo, por colaborador e por equipe; CSV (BOM, cabeçalho, aviso, ausência sem categoria); período a partir do corte e começando depois dele; período antes, corte no futuro e tabela vazia idênticos ao sem corte (JSON e CSV); filtros de equipe e de colaborador arquivado; corte lido uma vez |
| `scripts/qa-schedule-legacy-output.php` | Banco simulado com a tabela do corte e as consultas com arquivado. Seção 3: as 17 saídas idênticas com a tabela vazia, com o corte depois de todos os períodos e no futuro distante; corte em 16/10/2026: as competências inteiras antes dele idênticas e o relatório do ano dividido (parte antiga igual linha a linha no JSON e no CSV, aviso, ordem das chaves, totais) |
| `scripts/qa-schedule-history.php` | Rótulo do ciclo inválido na tela Escala com acento (D11) |

**Decisões de implementação do commit 2 (a aprovar):**

1. **Leitura do corte em duas consultas** (`information_schema` e a linha), no padrão do `PaMapService`, em vez de capturar o erro 1146: nenhuma exceção engolida. Uma vez por requisição, guardada na instância do serviço.
2. **Corte inválido no banco para o relatório** (500 genérico, classe no log) em vez de cair em silêncio no cálculo antigo. Os `CHECK` e os gatilhos da 019 tornam o caso quase impossível.
3. **Rótulos com acento por padrão**, com o mapa `LEGACY_LABELS` (4 rótulos: "Ausência", "Dias ímpares", "Ciclo com configuração inválida", "Regra não informada") no cálculo antigo. Na tela Escala mudam a lista de regras, a escala gerada e a prévia (D11, caminho do motor); o Mapa PA tem rótulos próprios e fica para o F6.
4. **Linha do motor no relatório com o mesmo formato da tela Escala** (`situacao`, `origem`, `detalhe`, `escala`, além dos campos de hoje). Carga medida com banco simulado, 80 cadastros e 366 dias: cálculo antigo 75 ms, 6,1 MB de JSON, pico de 40 MB; tudo no motor 134 ms, 8,2 MB, pico de 74 MB. Se o tamanho pesar, `escala` pode sair do relatório (o frontend do commit 4 não precisa dele).
5. **Campos novos:** `absence_days` e `no_schedule_days` no `summary` logo depois de `remote_days` (sempre, quando o período alcança o corte); em `by_employee` e `by_team` só quando há contagem, como as chaves de hoje; `cutoff` logo depois de `period`.
6. **Linha `aviso` do CSV:** `tipo` = `aviso`, `data` = a data do corte, `detalhe` = o texto; as demais colunas vazias.
7. **Liderança na parte do motor:** equipe `LIDERANCA` no CSV e em `by_team` (código da equipe em maiúsculas, como `N1` e `N2`).
8. **Arquivado:** entra até a véspera da data de `arquivado_em`. Cadastro restaurado volta a ter `arquivado_em` nulo e entra em todas as datas; no intervalo em que esteve arquivado não tem regra e aparece como "Sem escala".
9. **Período que cruza o corte mistura as duas regras de cadastro, como manda a D6:** antes do corte, o fora do Mapa entra e a Liderança não (como hoje); a partir dele, o contrário.

**Como testar no MySQL local (sugestão):** com a 019 e os dados de teste. (1) Sem a 019 e com a tabela vazia: relatório e CSV de uma competência iguais aos do código anterior (`b2c9bc5`), byte a byte. (2) Corte registrado num dia 16 futuro: as competências até ele sem campo novo. (3) **Só no banco de teste**, para ver a divisão com dados passados (o registro recusa data passada e os gatilhos não barram `INSERT` em tabela vazia): `INSERT INTO portal_presence_cutoff (id, cutoff_date, registered_by) VALUES (1, '2026-10-16', 'qa.teste');` e o relatório de 16/09 a 15/11: `cutoff.split = true`, linhas antes de 16/10 iguais às do código anterior, aviso no CSV, ausência como "Ausência" sem categoria, sem escala em `no_schedule_days`, fim de semana só com exceção de trabalho, Liderança presente, fora do Mapa só antes do corte, arquivado até a véspera. (4) Período só depois do corte (`split = false`, sem aviso). (5) Relatório de 366 dias, para o tempo e a memória com os cadastros reais.

**Validação pela IA:** `qa-presence-cutoff` com 208 verificações; `qa-schedule-legacy-output` com a seção 3; **mutação 37/37** no `PresenceCutoff` e no relatório (na 1ª rodada sobreviveram dois mutantes, por teste fraco: a validação de data da leitura, porque todos os valores inválidos do teste já falhavam no dia 16, e `cutoff.date` pelo início da parte do motor, igual ao corte nos períodos testados; os testes foram reforçados); a mutação dos gatilhos continuou 20/20; bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** as consultas novas só rodaram contra o banco simulado.

**Gatilhos e commit 2 aprovados em 2026-10-14**, com as cinco decisões dos gatilhos e as nove do commit 2 como estão, após teste do responsável em MySQL 8.0.46 local:

| Caso | Resultado |
|---|---|
| Gatilhos (`b2c9bc5`) | A 019 duas vezes, `gatilhos_019 = 2`; com o corte registrado, `UPDATE`, `DELETE`, `REPLACE` e `INSERT ... ON DUPLICATE KEY UPDATE` param com "corte imutavel" |
| **Achado: `TRUNCATE`** | Apaga o corte sem disparar gatilho: o MySQL não dispara gatilho no `TRUNCATE`, que exige o privilégio `DROP` |
| Relatório cruzando o corte | 16/09 a 15/11 com corte em 16/10: as 31 linhas antes do corte idênticas às do relatório sem corte |
| Período inteiro antes do corte | Idêntico byte a byte (JSON e CSV) sem a tabela, com ela vazia e com o corte depois |
| A partir do corte | Fim de semana fora; ausência como "Ausência" sem categoria; Liderança "Presencial (regra fixa)"; arquivado em 25/10 até 23/10 (24 e 25 são sábado e domingo); fora do Mapa fora |
| CSV e período só depois do corte | Linha `aviso` no CSV; `split: false` no período só depois do corte |
| Carga | 10 ms para dois meses |

**Ajuste pedido junto do commit 3:** (1) na verificação da 019, coluna `inconsistente`: 1 quando `eventos_registro - eventos_remocao` for diferente de `cortes` (corte apagado por `TRUNCATE`: registro sem linha; linha gravada fora do script: linha sem registro); no roteiro do deploy (commit 5), `inconsistente = 1` manda parar e avisar; (2) registrar aqui e na documentação de segurança que o `TRUNCATE` escapa dos gatilhos e que a proteção contra ele é de privilégio: o usuário do banco da aplicação não deveria ter `DROP` (pendência ligada à VULN-002, usuário dedicado com privilégio mínimo).

**Ajuste da verificação e registro do `TRUNCATE` (2026-10-14):**

| Item | Como ficou |
|---|---|
| `migrations/verificacao/20261013_019_presence_cutoff_check.sql` | Coluna `inconsistente` na consulta da auditoria (só com o `audit_log`): `IF(eventos_registro - eventos_remocao <> cortes, 1, 0)`, com `cortes` = contagem da tabela, ou 0 sem ela (antes da 019 e depois do rollback). Esperado 0 sempre. Cobre o corte apagado sem o rollback (`TRUNCATE`: registro sem linha) e a linha gravada fora do script (linha sem registro). Como os eventos são de todos os tempos, depois de um apagamento a coluna continua 1: a correção é decisão do responsável |
| Roteiro do deploy (commit 5) | `inconsistente = 1` em qualquer verificação da 019 (ensaio, depois do registro, pós-deploy): **parar e avisar o responsável** |
| `TRUNCATE` | O MySQL não dispara gatilho no `TRUNCATE`; os gatilhos da 019 não o barram. A proteção é de privilégio: o usuário do banco da aplicação não deve ter `DROP` (exigido pelo `TRUNCATE`) nem `TRIGGER` (permite remover os gatilhos). **Pendência ligada à VULN-002** (usuário dedicado com privilégio mínimo): conferir os privilégios no servidor com `SHOW GRANTS`. Registrado no cabeçalho da 019 e na limitação 11 de `docs/DOCUMENTACAO_TECNICA.md` |

Entregue em `96758ae`. **Validação pela IA:** `qa-presence-cutoff` com a expressão exata, a contagem pela tabela (0 sem ela), a coluna só com o `audit_log` e o cabeçalho; **mutação 9/9** (igualdade no lugar de diferença, remoções menos registros, só registros, 1 corte sem a tabela, cortes sempre 0, coluna sempre 0, variável errada, cabeçalho sem a parada e sem o `TRUNCATE`). Não executado em MySQL. **Como testar:** num banco de teste, depois do registro, `inconsistente = 0`; `TRUNCATE portal_presence_cutoff` dá 1; numa base sem registro, `INSERT` direto dá 1; o caminho normal (registro, rollback, 019 e registro novo) fica em 0.

**Commit 3 entregue (2026-10-14, `5f20dd8`; aguardando o teste do responsável antes do commit 4):** Calendário.

| Arquivo | Como ficou |
|---|---|
| `services/OperationalService.php` | `listCalendar`: o corte só é lido quando o bloco de escala ou o de ausências roda (`type` vazio, `schedule`, `onsite`, `remote` ou `absence`). Escala antiga só até a véspera do corte; do corte em diante, `calendarEngineEvents` (motor com o histórico do arquivado e a categoria). Bloco de ausências: para quem está no motor (cadastro ativo ou arquivado, fora do Mapa não, sem o filtro de equipe), o evento vai até a véspera do corte, e a ausência que começa no corte ou depois não vira evento; os demais continuam com o evento inteiro. `scheduleBlockingAbsences` lê `a.category` só no Calendário; `engineSchedule` devolve os itens do motor (`ausencias`) só para o `calendarEngineEvents`, que nunca os repassa |
| `scripts/qa-presence-cutoff.php` | Seção 4: escala antes do corte igual, evento a evento; motor igual ao do relatório; ausência cortada na véspera, inteira para fora do Mapa e inativo antigo; título, status, situação, origem e detalhe; marcador da parcial; descrição por perfil (gestor, próprio, outro técnico, somente leitura), sem observação, sem os itens internos e sem a categoria crua; `type` (`absence`, `onsite`, `remote`, `schedule`), `status` (`absence`, `approved`), equipe, colaborador arquivado, fora do Mapa; período depois do corte; sem relógio; período antes, corte no futuro e tabela vazia idênticos ao sem corte por perfil; outro `type` sem ler o corte; corte lido uma vez com Calendário e relatório |
| `scripts/qa-schedule-legacy-output.php` | Calendário de outubro com corte em 16/10: eventos antes do corte iguais um a um; depois, só escala do motor, sem fim de semana, sem inativo antigo nem fora do Mapa. As saídas inteiras antes do corte continuam idênticas |
| `scripts/qa-absences.php` | Banco simulado responde à checagem da tabela do corte (sem a 019); o teste do bloco de ausências confere a checagem e depois a consulta de ausências (antes, a consulta de ausências era a primeira) |

**Decisões de implementação do commit 3 (a aprovar):**

1. **O corte só é lido quando a escala ou as ausências entram na resposta.** Pausa, hora extra, ajuste, plantão, War Room e eventos manuais não consultam a tabela.
2. **Quem tem a ausência como situação** é quem está no motor pelo cadastro (ativo ou arquivado, fora do Mapa não, equipes N1, N2 e Liderança), **sem o filtro de equipe**: uma consulta a mais. O evento de ausência dele vai até a véspera do corte, com o mesmo `id`; a que começa no corte ou depois não vira evento; com o período inteiro depois do corte, o evento cortado fica de fora. Fora do Mapa, inativo antigo e equipe fora das três continuam com o evento inteiro.
3. **Filtro de equipe:** do corte em diante, a ausência de quem é de outra equipe some junto com a escala dele. Antes do corte, e para quem não está no motor, o bloco de ausências continua sem filtro de equipe, como hoje.
4. **Filtros do corte em diante:** `type` `onsite`, `remote` e `absence` pela situação; `status` pela igualdade com o status do evento (`onsite`, `remote`, `absence`, `no_schedule`). `status=approved` continua trazendo os eventos de ausência, mas não as situações do motor; para elas, `type=absence` ou `status=absence`. Antes do corte, a escala continua ignorando `status`, como hoje.
5. **Descrição:** regra (com acento, D11) ou "Exceção de escala"; ausência de dia inteiro: só a categoria, para a gestão e o próprio (vazio para os demais); parcial: regra mais " · " e a categoria, para a gestão e o próprio. A categoria é a da ausência que decide o dia: a primeira de dia inteiro; sem ela, a da parcial do marcador. Exceção de ausência antiga (`day_off` e afins) não tem categoria.
6. **`situacao`, `origem` e `detalhe` para todos os perfis** (P9: todos veem quem está ausente e o período, inclusive o horário da parcial).
7. **Status novos no Calendário:** `absence` e `no_schedule` em eventos `schedule`, com título "Ausência: Nome" e "Sem escala: Nome". A tela atual mostra os eventos com o título e a descrição; a legenda e os cartões ficam no commit 4.

**Como testar no MySQL local (sugestão):** no banco de teste com o corte em 16/10 (`INSERT` direto, só em teste, como no commit 2) e o Calendário de 01/10 a 31/10: (1) por perfil (gestor, Liderança, admin, técnico próprio, outro técnico, somente leitura): até 15/10, os mesmos eventos do código anterior (`7e08b03`); (2) de 16/10 em diante, um evento por técnico e dia útil (sábado só com exceção de trabalho), ausência como "Ausência: Nome" com a categoria só para a gestão e o próprio, parcial no `detalhe`, fora do Mapa só com o evento de ausência, arquivado até a véspera, Liderança "Presencial (regra fixa)"; (3) ausência que cruza 16/10 vai até 15/10 como evento; (4) `type=absence`, `onsite`, `remote`, `schedule`; `status=absence` e `approved`; equipe N1; (5) `type=pause`, `overtime` e os demais iguais aos de antes; (6) a observação nunca aparece; (7) a tela atual do Calendário abre sem erro com os eventos novos.

**Validação pela IA:** `qa-presence-cutoff` com 262 verificações; `qa-schedule-legacy-output` e `qa-absences` atualizados; **mutação 24/24** no Calendário (na 1ª rodada sobreviveu um mutante por falta de caso na massa: a categoria da parcial junto da exceção de trabalho; o caso entrou na massa); as suítes anteriores continuam mortas (relatório 37/37, gatilhos 20/20, consistência 9/9); bateria PHP completa, 15 `qa:*` do frontend e lint. **Sem MySQL na estação:** as consultas só rodaram contra o banco simulado; a tela do Calendário com os eventos novos não foi aberta.

---

## 10. Riscos e pendências

| Risco | Mitigação |
|---|---|
| Fuso do MySQL (A4): correção implementada, ainda não validada nem implantada | Regra de ordem de deploy (2026-10-02): os lotes F entram na fila depois do deploy 6, que inclui o A4. O desenvolvimento não espera. Código novo não usa relógio do banco |
| Números de relatório mudam (P3, P4, ausência, arquivado) | Seção 6; validar com quem consome o CSV antes de F2 |
| Rollback de código com regra `cycle` gravada quebra três telas | Documentado em 2.1; o script de rollback converte antes |
| Migrations dependem de nomes de índice e FK do servidor | Q1 antes de F1; todas as migrations checam `information_schema` |
| Sem MySQL no ambiente de desenvolvimento: migrations não são executadas pela IA | Lógica de decisão em funções puras testadas; SQL fino; roteiro de verificação por lote; primeira execução acompanhada pelo responsável |
| QAs antigos com checagem por `git diff` e "arquivos protegidos" podem disparar durante F2B–F7 | Dívida técnica já registrada; tratar caso a caso, com aprovação, como no Lote 2 |
| `qa:schedule-rules` fixa a lista de tipos de regra e dias | Atualização deliberada em F1 e F2B, descrita na entrega |
| `AdminPage.jsx` denso, alterado de novo em F4 | Mudança restrita à aba Funcionários; sem refatoração junto |
| Carga extra no polling de 15 s (P14) | Referência de 2026-10-06 (p95 26,8 ms; seção 9.2). Base do critério: medição repetida logo antes do deploy do F2 (decisão de 2026-10-06); medir de novo depois, nas mesmas condições; piora (p95 + 50 ms **e** + 20% sobre essa base) desliga `STATUS_PRESENCE_ENABLED` |

**Pendências fora desta entrega:** feriados; política de retenção e anonimização (LGPD); BIZ-05 (paridade na virada do mês); SEC-04 (Liderança ⇒ admin); PERF-02 (listas truncadas); remoção das tabelas legadas `portal_schedules` e `portal_pa_map`; horário de jornada que cruza a meia-noite. Tabela de Funcionários no celular (cartões no lugar da tabela que rola na horizontal; lote de acabamento, pedido de 2026-10-13).

---

## 11. Requisitos novos de 2026-10-06 (item 2: b, c, d)

Respostas da Liderança aos pedidos do item 2, registradas como requisito. **Sem código nesta data.** Levantamento de impacto feito por leitura do código em 2026-10-06.

### 11.1 b) Almoço por início e duração contratual (desenho aprovado em 2026-10-06; migration só depois da Liderança)

**Requisito.** No cadastro, o usuário informa só o início do almoço; o fim é calculado pela duração do contrato do técnico. A opção "Exceção (jornada pré-acordada)" libera o fim manual. A duração **varia por contrato** e fica numa tabela configurável; a lista de contratos e durações vem da Liderança. **Liderança e admin** marcam a exceção. A marcação fica em coluna própria (migration nova, fora da 012 a 014), é auditada, e o almoço tem de ficar dentro da jornada.

**Desenho:**

| Ponto | Desenho |
|---|---|
| Onde guardar a duração | **Tipo de contrato**, não carga horária: a carga sozinha não define a duração (dois contratos de 40 h podem ter intervalos diferentes por acordo) e a lista da Liderança já é de contratos |
| Migration 018 | Tabela `contratos_trabalho` (`id`, `nome` único, `almoco_minutos` com faixa no banco, `ativo`, autoria); em `funcionarios`, `contrato_id` (nulo, FK `RESTRICT`) e `almoco_excecao` (0/1, padrão 0). Regra do A4, idempotente, rollback com cópia `*_bkp_018`, ensaio e verificação só com contagens (sem contrato, com exceção, almoço fora da jornada, almoço diferente da duração). **Nenhuma duração no código nem na migration** |
| Cálculo | Sem exceção: `almoco_fim = almoco_inicio + duração`, calculado no servidor; o fim enviado é ignorado e a tela o mostra só para leitura. Com exceção: fim manual, validado |
| Validação nova, para todos | `jornada_entrada ≤ almoco_inicio < almoco_fim ≤ jornada_saida` (400). Cadastros que hoje violam a regra só são barrados ao editar; a verificação conta quantos antes do deploy |
| Permissão | `funcionarios.almoco_excecao` (Liderança e admin). Hoje coincide com quem tem `funcionarios.manage` (o gestor não edita cadastro), mas fica separada para RBAC e auditoria claros. A Liderança continua sem alterar cadastro de Liderança ou admin (Lote 5b) |
| Auditoria | `FUNCIONARIO_ALMOCO_EXCECAO_ALTERADO` (WARNING, como `FUNCIONARIO_FORA_MAPA_ALTERADO`: ID, antes e depois, perfil do ator, horários; sem nome); `FUNCIONARIO_CONTRATO_ALTERADO` (INFO); `CONTRATO_DURACAO_ALTERADA` (WARNING) |
| Motor (F2, P2) | **Sem mudança.** O motor lê `almoco_inicio`/`almoco_fim` de `funcionarios` (`PresenceRepository::employees`), como a tela de Pausas (`Funcionario::esta_disponivel`); o fim calculado é gravado nessas colunas |

**Decisões do responsável (2026-10-06):** (1) **tipo de contrato** (tabela `contratos_trabalho`); (2) **só o admin** edita a tabela de durações; (3) cadastro **sem contrato mantém o fim manual, com aviso** na tela; (4) mudar a duração de um contrato **recalcula na mesma transação** o fim dos cadastros sem exceção daquele contrato; se algum ficar fora da jornada, **409 com a contagem e nada é alterado**. A permissão `funcionarios.almoco_excecao` e os três eventos de auditoria seguem o desenho acima e são conferidos item a item na entrega do F8 (regra de aprovação de RBAC e auditoria).

**Pendente da Liderança:** lista de contratos e durações; se a exceção exige motivo. Se exigir, **lista fechada de motivos, sem texto livre** (decisão do responsável, 2026-10-06; texto livre tende a receber dado de saúde, LGPD). A coluna do motivo entra na mesma 018: **a migration 018 não é escrita antes dessas duas respostas.**

### 11.2 c) Excluir = arquivar

**Requisito aprovado:** "excluir" técnico na tela é arquivar (RF-07, F4, seção 2.5). Hoje `api/remover_funcionario.php` já só faz `ativo = 0`; o F4 completa o arquivamento (colunas `arquivado_em`/`arquivado_por`, efeitos no PA e na escala, restauração). Nenhuma exclusão física.

### 11.3 d) Equipe Crítico

**Requisito decidido:**

- A equipe Crítico aparece no painel do `status.php` junto com N1 e N2, com destaque visual.
- O almoço do Crítico fica **fora** do almoço automático do cadastro: o cadastro guarda só um horário previsto, informativo. Eles lançam o próprio almoço no painel por um motivo de pausa novo, **"Almoço", só para a equipe Crítico** (hoje: Café, Pessoal e Reunião com aprovação).
- No motor (P2): para o Crítico, `almoco` vem da pausa de almoço em andamento, não do intervalo do cadastro.

**Impacto levantado:**

| Área | Hoje | Impacto |
|---|---|---|
| Equipe | `validate_funcionario_equipe` aceita `n1`, `n2`, `lideranca`; `funcionarios.equipe` é `VARCHAR(10)` | Aceitar `critico` (cabe na coluna); formulário, schema Zod e filtros |
| Painel | `obter_status()` só monta `n1` e `n2` e ignora as outras equipes: **um técnico do Crítico sumiria do painel**; `useLivePauses`, `PausasPage` e `DashboardPage` só têm N1 e N2 | Chave `critico` no `status.php`, seção própria com destaque, contagens do dashboard |
| Tabelas com `team ENUM` | `portal_overtime_entries`, `portal_time_adjustments`, `portal_oncall_shifts` e `portal_pa_map` (`n1`, `n2`); regras e exceções de escala e `portal_pa_assignments` (`n1`, `n2`, `lideranca`) | Hora extra e correção de ponto de um técnico do Crítico falhariam ao gravar: ampliar o enum numa migration nova (019) nas tabelas que o Crítico usar. Escala e Mapa PA dependem da resposta sobre a jornada do Crítico |
| Motivo de pausa | `validate_motivo_pausa` aceita `Café`, `Pessoal`, `Reunião`; `iniciar_pausa.php`, `PausasPage.jsx` e telas legadas (`index.php`, `static/js`) com a lista fixa | Ver tratamento (4) abaixo |
| Limite simultâneo | `pausa_conta_para_limite`: tudo conta, menos Pessoal e Reunião. **"Almoço" contaria** e ganharia o limite de tempo de `duracao_pausa_minutos` (20 min) no status | Ver tratamento (1) e (2) |
| Alertas 15/20 min | Gravados em `finalizar_pausa` (`pausas.alerta_15min`/`alerta_20min`, fixos em 15 e 20 min) para motivos que contam no limite; contados nas métricas. As chaves `alerta_15minutos`/`alerta_20minutos` do `config.json` são gravadas pela tela de configurações mas **nenhum código as lê** | Ver tratamento (1) |
| Métricas | `processar_linha_metrica` soma toda pausa no total e na média por funcionário e equipe | Ver tratamento (3) |

**Tratamento (aprovado em 2026-10-06; o item 2 sujeito à confirmação da Liderança):**

1. **Alertas.** Para "Almoço", `alerta_15min` e `alerta_20min` ficam sempre 0. O limite passa a ser a duração do contrato (11.1): o item do `status.php` leva `duracao_limite_segundos` = duração do contrato, e a tela já marca "Tempo excedido" acima dele. Ao finalizar, a pausa grava o limite aplicado numa coluna nova `pausas.limite_segundos` (nula para os outros motivos), para o excesso ser calculado depois mesmo que a duração do contrato mude. Sem contrato: sem limite automático, com aviso na tela. Ajuste necessário no frontend: hoje `PausasPage.jsx:23` trata limite 0 como 1.200 s (`|| 1200`) e só "Pessoal" e "Reunião" ficam sem limite. **Mecanismo** (registrado a pedido do responsável): `GerenciadorPausas::pausa_conta_para_limite` já decide, por motivo, os alertas de 15/20 min gravados em `finalizar_pausa`; devolver `false` para "Almoço" basta para a pausa de almoço do Crítico não disparar esses alertas. Atenção: a mesma função decide também o limite simultâneo da equipe (`iniciar_pausa`) e o `duracao_limite_segundos` do `status.php` (0 para quem não conta). Se a Liderança decidir que o almoço **entra** no limite simultâneo (pendente), a função precisa ser dividida em duas (limite simultâneo e alerta de tempo).
2. **Limite simultâneo.** Proposta: "Almoço" **não** conta no limite da equipe (o limite existe para cobrir pausa de café). O limite próprio do Crítico e a confirmação dependem da Liderança (pendente).
3. **Métricas.** "Almoço" sai do total e da média por funcionário e por equipe e das contagens de alerta; continua em "por motivo" (quantidade e duração), para a gestão ver, e no ZIP de pausas (todas as linhas, com o motivo).
4. **Validação do motivo, só no servidor como regra.** `validate_motivo_pausa($motivo, $equipe)` aceita "Almoço" só com equipe `critico`; a equipe vem do cadastro (sessão e banco), nunca do cliente. Conferência repetida em `GerenciadorPausas::iniciar_pausa`, que tem o cadastro carregado. O frontend mostra a opção só para a sessão do Crítico (conveniência, não controle). Teste negativo: técnico de N1 com "Almoço" → 400. Telas legadas a conferir (se ainda servidas, recebem a mesma regra).

**Motor preparado, sem implementar agora.** Mudança prevista em `PresenceEngine::nowPresence`, isolada: o colaborador passa a ter `lunch_source` (`cadastro` ou `pausa`, derivado da equipe no repositório). Com `pausa`, o intervalo do cadastro não gera `almoco`, e pausa em andamento com motivo "Almoço" vira `situacao = almoco`, `origem = pausa`, detalhe com o início. A precedência da seção 4.3 e a identidade da soma (4.5) não mudam: `almoco` continua parcela própria. Hoje, sem a mudança, uma pausa "Almoço" apareceria como `pausa` com detalhe "Almoço". Testes a acrescentar no `qa-presence-engine`: Crítico às 12:30 sem pausa não fica em almoço; com pausa "Almoço" fica `almoco`/`pausa`; N1 com pausa "Café" no intervalo do cadastro continua `pausa`.

**Pendente da Liderança:** limite de pausas do Crítico e se o almoço entra nele; origem da jornada do Crítico (qual planilha; muda por dia ou não). Jornada por dia seria uma fonte nova de expediente para o motor (hoje a jornada é fixa no cadastro) e decide se o Crítico entra em escala e Mapa PA.

### 11.4 Encaixe proposto nos lotes

| Lote proposto | Conteúdo | Depende de |
|---|---|---|
| F8 — Almoço por contrato | 11.1: migration 018, tabela de contratos e tela de durações (só admin), cadastro, validação, auditoria | Decisões de 11.1 tomadas em 2026-10-06; **lista de contratos e durações e resposta sobre o motivo (Liderança): sem elas, a 018 não é escrita** |
| F9 — Equipe Crítico | 11.3: equipe, painel, motivo "Almoço", limite, alertas, métricas, motor (`lunch_source`), migration 019 (enums e `pausas.limite_segundos`) | F2 (aprovado em 2026-10-06); F8 (duração do contrato para o alerta); pendências da Liderança |
| SEC-07b — Texto livre no calendário (decisão de 2026-10-07) | `calendar.php` sem o texto livre de hora extra, correção de ponto, plantão e motivo de pausa para quem não é gestão nem o próprio colaborador (seção 9.4) | Logo depois do F3, antes do F4. **Implementado e aprovado em 2026-10-09** (seção 9.5; `oncall.php` junto, D3) |

O item c) já está no F4. **Ordem decidida em 2026-10-06:** F2B agora; F3 a F7 na ordem do desenho; F8 assim que a Liderança mandar contratos e a resposta sobre o motivo; F9 depois do F8.

---

**Fim da Fase de Desenho.** Aprovado em 2026-09-30; P2, P12 e o tratamento dos relatórios decididos em 2026-10-02 (seção 0.6). As propostas de 0.6 para a escala implícita da Liderança e para o atributo `fora_mapa_escala` foram aprovadas em 2026-10-02, com os esclarecimentos registrados na seção.
