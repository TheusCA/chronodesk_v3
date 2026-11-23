# Desenho do F10: escala mensal no portal (substitui o script e a planilha "Escala Home")

**Estado:** desenho aprovado em 2026-11-13 (`69f504e`), com as decisões D-F10-1 a D-F10-18 como recomendadas; a D-F10-19 fica com o responsável, a responder antes do F10-3. Lote F10-1 (`968e9b1`, `665b219`, `fdefa83`) aprovado em 2026-11-13, com a proposta da 8.4 (seção 8). **D-F10-19 revista em 2026-11-16** (Crítico com 2 PAs reservados, configurável, ocupados pelos papéis do 9.16; Híbrido na opção (c)). **F10-3 entregue em 2026-11-19, sem push** (plano revisto na 3.3.1, execução na 8.7; vai no deploy 5). **F10-3 aprovado em 2026-11-23**, com D-F10-32 a D-F10-41 como estão na 8.7 (D-F10-34 inclusive: o reservado vazio não empresta). **Lote 8.8 (papel do dia do Crítico numa fonte só) aprovado em 2026-11-23 e entregue no mesmo dia**: entra no deploy 5, junto do F10-3; retira a D-F10-36.

**Fontes lidas** (locais, fora do git, em `_referencias/`): o script `gerar_escala_home_V17_ARQUIVO_MENSAL.py` (1.660 linhas, lido inteiro) e a planilha `Escala Home - Base.xlsx`. A planilha tem nomes reais. Este documento traz só estrutura, regras e contagens: nenhum nome, login ou dado pessoal. As linhas citadas são do script V17.

**Como a planilha foi lida:** sem dependência nova (não há `openpyxl` na estação). Um leitor de xlsx com `zipfile` e XML da biblioteca padrão, no scratchpad da sessão, imprimiu só estrutura e contagens. A prova de equivalência da seção 2.2 roda o `PresenceEngine` do repositório contra índices gerados pelas funções do script, copiadas sem mudança.

---

## 1. O que o script e a planilha fazem hoje

### 1.1 Fluxo

1. O operador abre a base, ajusta a CONFIG (mês e ano) e roda o script no Windows, com Excel e `pywin32` (`main`, 1504-1656; COM em 1532).
2. O script apaga o arquivo mensal se ele existir (1565-1574), salva uma cópia da base como `Escala Home - <Mês> <ano>.xlsx` (1576) e gera as abas nessa cópia (`generate`, 1006-1428).
3. O HISTÓRICO do mês é reescrito na cópia (1387-1411) e copiado para a base (`sync_history_month`, 1432-1480): as linhas do mês saem e as novas entram no fim.
4. Na cópia ficam só as seis abas do mês (`cleanup_monthly_workbook`, 1483-1501). A base guarda só a configuração e o HISTÓRICO.

Gerar de novo o mesmo mês substitui o arquivo e o HISTÓRICO do mês, sem versão anterior, sem registro de quem gerou (só o carimbo "GERADO EM") e sem controle de dois operadores ao mesmo tempo.

### 1.2 Abas de entrada

| Aba | Leitura | Estrutura (contagens da base de 12/11/2026) |
|---|---|---|
| **CONFIG** | `load_config` (76-90), células fixas | B4 mês, B5 ano, B6 **âncora do ciclo** (texto `dd/mm/aaaa`; sem valor, cai em silêncio em 01/10/2026), B7 **PAs comuns** (padrão 12), B8 **Híbrido presencial/dia** (padrão 1), B9 **Crítico presencial/dia** (padrão 1), B10 a B12 **fase HOME** dos Times 1, 2 e 3 (padrões 0, 1, 2). A tabela das linhas 14-17 é só ilustrativa. Na base: mês 12/2026, PAs comuns 12, Híbrido 1, Crítico 2, fases 0, 1 e 2 |
| **EQUIPE** | `load_team` (93-110), da linha 4 | ATIVO? (`SIM`), NOME, TORRE, MODO, TIME, GRUPO COMUM?, OBSERVAÇÃO (não lida). Chave = nome normalizado (`norm`, 38-46: maiúsculas, sem acento). Na base: 21 ativos; torre N1 9, N2 9, HB 3; modo RODÍZIO 9 (3 por time), FIXO 3, CRÍTICO 5, HÍBRIDO 3, FULL HOME 1; grupo comum `SIM` 14 |
| **REGRAS FIXAS** | `load_fixed_rules` (140-163) | ATIVO?, NOME, TORRE (não lida), TIPO (`SEMANAL` ou `CICLO`), CICLO (número da semana, padrão 1), SEG a SEX (`HOME`/`PRESENCIAL`); âncora em M4 (padrão 28/09/2026, uma segunda). Uma linha por semana do ciclo; o TIPO da última linha da pessoa vale para todas (155-156). Na base: 3 pessoas, 2 `SEMANAL` e 1 `CICLO` de 3 semanas |
| **EXCECOES** | `load_exceptions` (113-130) | ATIVO?, NOME, DATA INICIAL, DATA FINAL, STATUS (`HOME`, `PRESENCIAL`, `FERIAS`), OBSERVAÇÃO e PRIORIDADE (**não lidas**). Na base: 0 linhas |
| **CONFIG PA** | `load_pa_config` (296-330) | PA (número), FILEIRA (`SUPERIOR`/`INFERIOR`), ORDEM, TIPO (`FIXO`, `FLEXIVEL`, `BLOQUEADO`), OCUPANTE FIXO (texto), PODE EMPRESTAR?, PREFERÊNCIAS (nomes separados por `;`), ATIVO? (linha sem `SIM` some), OBSERVAÇÃO (não lida). Na base: 16 PAs (8 por fileira, os mesmos números do inventário do portal), **5 FIXO** (nenhum empresta) e **11 FLEXÍVEL**; 8 PAs com preferências |
| **HISTORICO** | escrita (1387-1411) e cópia (1432-1480) | ANO, MÊS, DATA, NOME, MODO, TIME, STATUS, TORRE, GERADO EM; uma linha por pessoa do grupo comum por dia útil. Na base: out/2026 308 linhas (22 dias, 14 pessoas), nov 294 (21 dias, 14 pessoas), dez 276 (23 dias, 12 pessoas); `PRESENCIAL` 568, `HOME` 310 |
| **MAPA** | **não lida pelo script** | Mapa físico estático, feito à mão (2 fileiras de 8 PAs, com ocupantes em texto). Não tem efeito na geração |

Dos 5 PAs FIXO, um tem como ocupante o texto "Híbrido", outro "Crítico", dois têm nomes que não estão na EQUIPE (os dois "sempre presentes" do código, 1.5) e um é de uma pessoa do grupo comum (modo FIXO).

### 1.3 Status do dia por modo (`generate`, 1013-1041)

- **Dias do mês:** só segunda a sexta (`weekday() < 5`, 1013-1017). **Feriado não existe**: conta como dia útil no ciclo e na distribuição de PA.
- **Grupo comum** (1019-1022): `GRUPO COMUM? = SIM` **e** modo `RODIZIO`, `FIXO` ou `SEM HOME`. Quem tem `SIM` com outro modo fica fora sem aviso (na base, 2 do Crítico).
- **Precedência:** a exceção vem antes de tudo (1025-1027): a primeira linha ativa da EXCECOES que cobre a data (`exception_for`, 133-137, na ordem da aba; a PRIORIDADE não é usada). `FERIAS` vira `FÉRIAS`.
- **SEM HOME:** `PRESENCIAL` (1029-1030).
- **FIXO** (`fixed_status`, 166-178):
  - sem regra: `PRESENCIAL`, **sem alerta**;
  - `SEMANAL`: a semana 1;
  - `CICLO`: semana = `((segunda da data − âncora M4) // 7) mod N + 1`, com `N` = o maior número de semana da pessoa; semana sem linha usa a semana 1 (176);
  - valor fora de `HOME`/`PRESENCIAL`: `PRESENCIAL`.
- **RODÍZIO** (1035-1039): `HOME` quando `business_index(âncora, data) mod 3` = fase do time; senão `PRESENCIAL`. Time fora de `TIME 1..3`: `ATENÇÃO`. `business_index` (181-192) conta, dia a dia, os dias úteis em (âncora, data] (negativo antes da âncora). Ciclo de 3 fixo no código ("1 HOME + 2 PRESENCIAIS"); fase fora de 0..2 nunca dá HOME, sem aviso.
- **Fora do grupo comum** (só na aba TODOS, 1244-1257): exceção primeiro; `FULL HOME` → `HOME`; `HIBRIDO` e `CRITICO` → `OUTRA ESCALA`; `FORA` → `FORA`; qualquer outro → o texto do modo.

### 1.4 Distribuição de PA por dia (`build_pa_assignments`, 355-522)

Para cada dia útil do mês, na ordem:

1. **PAs fixos** (391-410). `BLOQUEADO` é ignorado. Num `FIXO`, se o ocupante está presente (`fixed_is_present`, 361-379), o PA é dele; senão, o PA entra na lista de disponíveis **só se** `PODE EMPRESTAR? = SIM`. Ocupante presente:
   - os textos `HIBRIDO` e `CRITICO` e **dois nomes de pessoas fixos no código**: sempre presentes (359);
   - pessoa do grupo comum: status `PRESENCIAL` (férias e HOME = ausente);
   - pessoa fora do grupo comum: `HIBRIDO`/`CRITICO` presente; `FULL HOME`, `FORA` e o resto, ausente.
2. **Flexíveis** entram na lista de disponíveis (409-410).
3. **Pessoas** (413-420): o grupo comum com `PRESENCIAL` que ainda não tem PA fixo, **ordenado pelo nome normalizado**. **PAs** (421-428): `SUPERIOR` antes, depois ORDEM, depois o número.
4. **Custo** de pôr a pessoa *i* no PA *j* (455-460): **0** se for o PA dela no dia útil anterior (`last_pa`); **2** se ela está nas preferências do PA; **12 + |j − i|** no resto (o termo |j − i| puxa a pessoa *i* para a *i*-ésima posição).
5. **Cabem todos** (`pessoas ≤ PAs`, 438-482): programação dinâmica exata sobre (pessoa, conjunto de PAs usados), com memória. Minimiza o custo total. No empate, fica o primeiro PA da ordem (`<` estrito, 465): o resultado é, entre os de custo mínimo, o de sequência de PAs lexicograficamente menor. Determinístico.
6. **Falta PA** (484-516): caminho guloso. Para cada PA, na ordem, a pessoa de menor (pontos, nome normalizado), com pontos 0/2/12 (sem o |j − i|). Quem sobra é o **"SEM PA"** do dia.
7. **Trocas** (479-480, 512-513): quem tinha `last_pa` e mudou de PA. O dono fixo atualiza o `last_pa`, mas não conta troca.
8. **Continuidade:** `last_pa` vale entre os dias do mês e **zera a cada geração** (384). O 1º dia do mês não sabe onde a pessoa sentou no fim do mês anterior.

### 1.5 Saídas

| Aba | Linhas | Conteúdo |
|---|---|---|
| `ESCALA MM-AAAA` | 1052-1222 | Grupo comum × dias úteis (status, cor por status), resumo lateral com o total de HOME por pessoa. **Indicadores por dia** (1088-1132): presenciais comuns, HOME comuns, % presencial (presenciais / (grupo − férias)), N1 e N2 presenciais, **PAs comuns (o número digitado)**, HOME por time, Híbrido e Crítico (os números digitados), total presencial + H/C e **STATUS**: `OK`, `ATENÇÃO CONFIG` (algum status fora de PRESENCIAL/HOME/FÉRIAS) e `PA INSUFICIENTE` (presenciais > PAs comuns digitados), juntos com " \| " |
| `TODOS MM-AAAA` | 1224-1307 | Todos os ativos × dias, com o status de quem está fora do grupo comum (1.3). Mostra `FÉRIAS` para quem abre o arquivo |
| `RESUMO MM-AAAA` | 1309-1385 | Uma linha por dia com os indicadores e o STATUS |
| `PA DIARIO MM-AAAA` | 704-832 | PA × dia com o **primeiro nome** do ocupante (`short_pa_name`, 333-352) ou `LIVRE`; linhas TROCAS FLEXÍVEIS e SEM PA |
| `MAPA PA MM-AAAA` | 837-992 | Por semana e dia, as duas fileiras com o ocupante |
| `RESUMO PA MM-AAAA` | 525-677 | Por PA: `EXCLUSIVO` (1 pessoa no mês), `COMPARTILHADO` (2), `ROTATIVO` (3 ou mais) ou `LIVRE`, com os nomes |

### 1.6 Pontos frágeis

1. **Nomes no código:** os dois "sempre presentes" (359) e três desambiguações de primeiro nome (345-350) em `short_pa_name`. Mudança de equipe exige editar o script.
2. **Casamento por nome normalizado** em todas as abas (EQUIPE, REGRAS FIXAS, EXCECOES, ocupante e preferências do CONFIG PA). Homônimos se sobrescrevem nos dicionários (356-357); erro de digitação faz o dono fixo parecer ausente e a pessoa ganhar outro PA, sem aviso.
3. **Feriados ignorados** (1.3): o feriado gasta o dia de HOME do time e entra na distribuição.
4. **Ocupantes "Híbrido" e "Crítico" como texto**, sempre presentes. Os números da CONFIG (B8 e B9) só entram no indicador "total"; **B9 = 2 com 1 PA do Crítico** no CONFIG PA.
5. **`last_pa` zera a cada mês** (384): a continuidade quebra na virada.
6. **Capacidade digitada:** o `PA INSUFICIENTE` compara com B7 (12), mas a distribuição usa os PAs reais disponíveis do dia (11 flexíveis; o FIXO do grupo comum não empresta). Pode sair `STATUS OK` com pessoa em "SEM PA", e o contrário.
7. **Padrões silenciosos:** âncora, PAs, Híbrido, Crítico e âncora das regras fixas caem em valores do código quando a célula está vazia ou inválida (81-89, 162). FIXO sem regra vira PRESENCIAL; fase fora de 0..2 nunca dá HOME; semana do CICLO sem linha usa a 1. E a célula com **0** também vale o padrão (`int(valor or padrão)`, 82-89 e 152): a fase 0 digitada para o Time 2 ou o 3 vira 1 ou 2, e a semana 0 das REGRAS FIXAS vira 1 (achado do F10-1, 8.4).
8. **Ciclo do RODÍZIO fixo em 3 e times fixos em 3** (1036-1039, 86-89).
9. **Exceção:** a primeira linha que cobre a data vence; a PRIORIDADE não é usada; só três status.
10. **GRUPO COMUM? = SIM ignorado** fora de RODÍZIO, FIXO e SEM HOME (2 casos na base), sem aviso.
11. **Truncamento:** o RESUMO PA mostra no máximo 8 PAs por fileira (608, 635) e só as fileiras `SUPERIOR` e `INFERIOR`; o MAPA PA também ignora outras fileiras.
12. **Sem versão nem trilha:** o arquivo do mês é apagado e refeito (1565-1576); o HISTORICO do mês é apagado e reescrito (1389-1396 e 1441-1449). Não há registro de quem gerou nem controle de concorrência.
13. **Dado pessoal exposto:** a aba TODOS mostra `FÉRIAS` para quem abre o arquivo; o arquivo mensal circula com todos os nomes; a CONFIG tem texto livre com nomes (linha 19).
14. **Dependência de ambiente:** Windows, Excel e `pywin32`; o arquivo do mês precisa estar fechado.
15. **HISTORICO não guarda a regra:** ele registra o status, não a regra que o produziu. Em out/2026 há linhas com modo `SEM HOME` de 3 pessoas que hoje têm outro modo (2 `CRITICO`, 1 `RODIZIO`), e em nov/2026 linhas com modo `RODIZIO` de 2 pessoas que hoje são `CRITICO` e 1 regra SEMANAL que trocou um dia de lugar depois de gerada (seção 4.3).

---

## 2. O que o portal já cobre

### 2.1 Mapa

| Na planilha | No portal | Onde |
|---|---|---|
| Regra por pessoa com vigência e histórico | `portal_schedule_rules` com `effective_from`/`effective_until`, uma regra aberta por pessoa (`uq_schedule_rule_open`) | migration 011; `ScheduleRulePlanner` (`ruleAt`, `planSave`); `OperationalService` (gravação, início retroativo só com `escalas.retroativa`, linha 471) |
| RODÍZIO | `cycle` (`pattern` de 2 a 20 posições `onsite`/`remote`, `anchor_date` em dia útil, `effective_from = anchor_date`) | `PresenceEngine::scheduleForDay` (97-106), `cycleConfig` (242-257), `cyclePosition` (211-214), `businessDaysBetween` (220-235) |
| FIXO SEMANAL | `fixed_weekdays` (dias presenciais; `input_mode` `home` só de exibição) | `PresenceEngine` (88-97) |
| FIXO CICLO | `cycle` com `5 × N` posições e âncora na segunda (2.2) | idem; **a tela não consegue gravar esse padrão** (2.3) |
| SEM HOME / FULL HOME | `always_onsite` / `always_remote` | `PresenceEngine` (80-83) |
| EXCECOES HOME e PRESENCIAL | `portal_schedule_exceptions` (`remote`, `onsite`), **uma por pessoa por data** | migration 003 (23-41); tela "Exceção por data" |
| EXCECOES FÉRIAS | Ausência do F3, de dia inteiro, com período e categoria (a categoria só para admin e gestor, P9) | migration 012; `AbsenceService`; `PresenceEngine::dayPresence` |
| FORA | "Fora do Mapa e da escala" (`funcionarios.fora_mapa_escala`, só admin, `funcionarios.fora_mapa`) | `OperationalService` (762, 1520); `PaMapService` (98) |
| CONFIG PA (parte) | `portal_pa_inventory`: os mesmos 16 números, `grid_row` (1 = superior, 2 = inferior), `grid_column`, `display_order`, `status` `active`/`unavailable`, `active` | migration 009 (4-17, carga 73-95) |
| Ocupante fixo (parte) | `portal_pa_assignments`: vínculo recorrente pessoa → PA, com vigência e histórico; conflito avisado, não bloqueado (F6) | migration 009 (97-119); `PaMapService` |
| Status do dia | Motor de presença: exceção de trabalho, dia não útil, Liderança presencial implícita, regra; ausência de dia inteiro por cima | `PresenceEngine::scheduleForDay` (61-109) e `dayPresence` (117-127) |
| Corte | O Calendário e os relatórios usam o motor a partir do corte (16/11/2026) | migration 019; `PresenceCutoff` |

**O que não existe no portal:** time de rodízio como cadastro, grupo comum, tipo de PA (fixo/flexível), "pode emprestar", preferências, distribuição diária de PA, mês gerado e publicado, indicadores do mês, exportação em Excel e assentos de Híbrido e Crítico.

### 2.2 Equivalência de cálculo (provada)

**RODÍZIO = `cycle` de 3 posições com a mesma âncora.**
- Script: `business_index` conta os dias úteis em (âncora, data].
- Portal: `businessDaysBetween` conta [âncora, data).
- Com âncora e data em dia útil, as duas contagens são iguais: as duas incluem os dias úteis entre as duas datas; o script soma a data e o portal soma a âncora, e as duas valem 1. Antes da âncora, as duas contam [data, âncora) com sinal negativo, e os dois normalizam o resto para 0..2 (o `%` do Python e `((c % s) + s) % s` no PHP).
- O portal exige âncora em dia útil (`cycleConfig`). A âncora da base (01/10/2026) é uma quinta.

Padrão por time, com a mesma âncora da CONFIG:
- Time 1 (fase 0): `[remote, onsite, onsite]`;
- Time 2 (fase 1): `[onsite, remote, onsite]`;
- Time 3 (fase 2): `[onsite, onsite, remote]`.

| Data | Script (`business_index mod 3`) | Portal (`cyclePosition`) | Dias úteis [âncora, data) | Time em HOME |
|---|---|---|---|---|
| 02/06/2025 (bem antes da âncora) | 0 | 0 | −348 | 1 |
| 28/09/2026 (segunda antes da âncora) | 0 | 0 | −3 | 1 |
| 30/09/2026 | 2 | 2 | −1 | 3 |
| 01/10/2026 (âncora) | 0 | 0 | 0 | 1 |
| 02/10/2026 | 1 | 1 | 1 | 2 |
| 05/10/2026 (segunda) | 2 | 2 | 2 | 3 |
| 06/10/2026 | 0 | 0 | 3 | 1 |
| 31/12/2026 | 2 | 2 | 65 | 3 |
| 04/01/2027 | 1 | 1 | 67 | 2 |

**FIXO CICLO de N semanas = `cycle` de 5 × N posições, âncora na segunda.**
- Para a data na semana *k* depois da segunda-âncora (*k* pode ser negativo) e dia *w* (0 = seg … 4 = sex), o portal conta 5*k* + *w* dias úteis.
- O resto por 5*N* é 5 × (*k* mod *N*) + *w*, o mesmo de "semana `(k mod N) + 1`, dia *w*" do script.
- O padrão é a concatenação das semanas 1..N (seg a sex); semana sem linha repete a semana 1, como no script.
- Exemplos com N = 3 e âncora 28/09/2026:
  - 21/09 (antes): posição 10 = semana 3, seg;
  - 28/09: posição 0 = semana 1, seg;
  - 02/10: posição 4 = semana 1, sex;
  - 05/10: posição 5 = semana 2, seg;
  - 12/10: posição 10 = semana 3, seg;
  - 19/10: posição 0 = semana 1, seg.
- **Âncora fora da segunda:** no script, a semana da própria âncora já é a última do ciclo. Uma âncora na quarta 30/09 equivale, no portal, à **segunda seguinte** (05/10), não a 30/09.
- O portal aceita até 20 posições, ou seja, **até 4 semanas**.

**Execução da prova:**
- Script de comparação no scratchpad da sessão. O Python gerou os índices com as funções do script copiadas sem mudança, e o PHP chamou o `PresenceEngine` do repositório.
- Período: todos os dias úteis de 02/06/2025 a 30/06/2027 (543).

| Caso | Iguais | Diferentes |
|---|---|---|
| RODÍZIO, âncoras 31/12/2025, 28/09, 01/10, 05/10 e 09/10/2026 | 543 cada | 0 |
| FIXO CICLO de 1, 2, 3 e 4 semanas, âncora 28/09/2026 | 543 cada | 0 |
| CICLO de 3 semanas com âncora na quarta 30/09: mesma data no portal | 0 | 543 |
| Idem, com a segunda seguinte (05/10) no portal | 543 | 0 |
| HISTORICO pelo motor (`scheduleForDay`), linhas cujo modo e time não mudaram, `cycle` | 638 | 0 |
| Idem, `fixed_weekdays` | 115 | 17 (a regra SEMANAL alterada depois de gerada; seção 4.3) |

### 2.3 O que a tela de regras não grava hoje

- **O servidor monta o ciclo em blocos:** N presenciais e M remotos, começando por um ou outro (`OperationalService::ruleDefinitionForPayload`, 2301-2330).
  - Qualquer ciclo de 3 com 1 HOME é rotação de `[remote, onsite, onsite]`. Então o RODÍZIO de qualquer time cabe, escolhendo como início (= âncora) um dia em que o time está em HOME (seção 7).
  - O **FIXO CICLO intercalado** (a semana com HOME na quarta e na sexta, por exemplo) **não cabe**. O motor aceita qualquer padrão, mas a API não recebe o padrão. É a lacuna do editor de ciclo (3.2).
- **A exceção é por data:** uma por pessoa e data. Um período da EXCECOES vira várias exceções, ou uma ausência do F3 quando for férias.
- **O motor aceita até 20 posições;** a planilha não tem limite de semanas.

---

## 3. Lacunas e proposta

**Numeração das migrations:** a 018 é do F8 e a 020 do F9 (reservadas); a 019 está aplicada. **O F10 começa na 021.** Toda migration segue a regra do A4: idempotente, com `information_schema`, rollback com cópia `*_bkp_<n>`, ensaio na cópia e verificação só com contagens.

### 3.1 Times de rodízio como cadastro (migration 021)

**Modelo:**
- **`portal_rotation_teams`** (`id`, `name` único, `active`, autoria) e **`portal_rotation_team_versions`** (`team_id`, `effective_from`, `pattern` JSON de 2 a 20 posições, `anchor_date` em dia útil, autoria; único por `team_id, effective_from`). A definição do time na data *d* é a versão de maior `effective_from` ≤ *d*. A fase da planilha vira parte do padrão (a rotação) ou da âncora; a tela oferece "copiar de outro time, deslocado em N dias úteis".
- **`portal_rotation_team_members`** (`team_id`, `employee_id`, `valid_from`, `valid_until`, autoria). Uma pertença aberta por pessoa, com coluna gerada e índice único, como o `uq_schedule_rule_open` da 011.
- **`portal_schedule_rules.rotation_team_id`** (nulo, FK): marca a regra gerada pelo time.
- **`funcionarios.grupo_escala`** `ENUM('comum','separado')`, padrão `comum`: o "grupo comum" da planilha. `separado` = Híbrido e Crítico até o F9 (3.8). O "fora do Mapa" continua como está, para quem não tem escala nenhuma.

**Comportamento: o time é a fonte, e a regra por pessoa é a cópia com vigência.**
- Entrar no time em *X*: o serviço grava na mesma transação a regra `cycle` da pessoa a partir de *X*, com o padrão e a âncora da versão vigente e o `rotation_team_id`, pelo `ScheduleRulePlanner::planSave`.
- Mudar o padrão ou a âncora do time a partir de *X*: cria uma versão e grava, na mesma transação, a regra nova de cada membro a partir de *X*. Se algum membro tiver regra futura incompatível, o serviço devolve 409 com a contagem e não grava nada.
- Sair do time: a regra fica como está, até outra regra.
- **O motor de presença não muda:** continua lendo `portal_schedule_rules`. O histórico da 011 fica intacto: as regras soltas de hoje continuam válidas, e o time só gera regras daqui para a frente.
- **Regra individual de membro de time:** 409 "Este técnico está no Time X; mude pelo time" (decisão de implementação, a aprovar). Evita a regra divergir do time sem registro.

**API:** `portal/rotation_teams.php`.
- `GET`: times, versões e membros vigentes, para quem pode ler escalas.
- `POST`: `save_team`, `save_version`, `add_member`, `move_member`, `end_member`, com `escalas.manage` e CSRF. A data retroativa exige `escalas.retroativa`, como hoje.

**Auditoria** (só IDs, nunca nome, como hoje):
- `TIME_RODIZIO_VERSAO_ALTERADA` (WARNING: time, data, tamanho do padrão, contagem de membros afetados);
- `TIME_RODIZIO_MEMBRO_ALTERADO` (INFO);
- `TIME_RODIZIO_CRIADO` (INFO).

### 3.2 Editor de ciclo semanal

- Uma grade **semana × dia** (1 a 4 semanas, seg a sex, cada célula Presencial/Home) gera o `pattern` de 5 × N posições e exige a âncora numa segunda. É o mesmo cálculo provado na 2.2.
- **Serve para a regra individual e para o time.** O servidor passa a aceitar o padrão explícito (`cycle.pattern`, validado: lista de 2 a 20 posições `onsite`/`remote`) além do modo em blocos de hoje. O motor já aceita qualquer padrão.
- Na tela: a prévia das próximas 4 semanas (o mesmo endpoint de prévia do F2B) e o rótulo "ciclo 3 semanas, 9P/6R".
- **Sem migration:** o `rule_config` já guarda o padrão.

### 3.3 Inventário de PA completo (migration 022)

- **Em `portal_pa_inventory`:**
  - `seat_type ENUM('flexible','fixed')`, padrão `flexible`;
  - `can_lend TINYINT(1)`, padrão 0, só com efeito em `fixed`;
  - `reserved_for ENUM('hybrid','critical')`, nulo: o assento do Híbrido e do Crítico até o F9, sempre ocupado, como no script.
  - Um CHECK impede `reserved_for` fora de `fixed`.
- **Fileira e ordem:** `grid_row` (1 = superior) e `grid_column`, que já existem. A ordem do motor é (`grid_row`, `grid_column`, número): a mesma do script com SUPERIOR = 1.
- **Bloqueado:** o `status = 'unavailable'` que já existe, sem valor novo. **Ativo:** o `active`.
- **Dono fixo:** o **vínculo recorrente** do `portal_pa_assignments`, que já tem vigência, histórico, autoria e conflito. Num PA `fixed`, no máximo um vínculo ativo por data (validado no serviço; o conflito da F6 vira 409 nesse tipo).
- **Preferências:** **`portal_pa_preferences`** (`pa_number` com FK para o inventário, `employee_id`, autoria; único por par). Sem ordem, como no script (custo 2 para qualquer preferência).

**Relação com o `portal_pa_assignments` atual:**
- **Continua manual:** o dono do PA fixo (vínculo recorrente) e o vínculo de quem está fora do grupo comum (Liderança e, até o F9, o Híbrido e o Crítico, se tiverem pessoa).
- **Vira dado da escala mensal:** a ocupação diária dos PAs flexíveis e dos fixos emprestados.
- **Na virada** (decisão D-F10-8): os vínculos recorrentes em PAs flexíveis viram preferências, e o vínculo é encerrado na véspera do 1º mês publicado.
- **O Mapa PA (F6)** passa a mostrar, nas datas de mês publicado, a distribuição publicada e, fora delas, os vínculos, como hoje. A legenda indica a fonte ("escala do mês" ou "vínculo").

**API:** `portal/pa_map.php` ganha `inventory_save` e `preferences_save`, com `pa_map.manage` e CSRF.

**Auditoria:**
- `PA_INVENTARIO_ALTERADO` (WARNING quando muda tipo, empréstimo ou reserva; INFO no resto);
- `PA_PREFERENCIA_ALTERADA` (INFO).

### 3.3.1 Revisão de 2026-11-19 (antes do F10-3)

**O que mudou desde o desenho de 2026-11-13:**
- **Grupo comum derivado da equipe (F9, D-F9-12):** comum = equipe N1 ou N2 **e** `grupo_escala = 'comum'`; Crítico e Híbrido ficam sempre `separado`. A distribuição, as preferências e as travas usam esse grupo; o `grupo_escala` à mão do 3.1 vale só dentro de N1 e N2.
- **D-F10-19 revista (2026-11-16):** o **Crítico tem N PAs reservados** (opção (b), N = 2, configurável) e **os papéis do 9.16 dizem quem ocupa**; o **Híbrido fica na opção (c)**: PA fixo por pessoa, pelo vínculo do Mapa PA, com o "pode emprestar".
- **Papéis do Crítico (9.16, deploy 4):** a presença do Crítico no dia vem do papel (troca pontual > rodízio > regra individual; ausência e exceção por data vencem o papel).

**Plano revisto do F10-3 (migration 022):**

| Item | Como fica |
|---|---|
| `portal_pa_inventory` | `seat_type ENUM('flexible','fixed')` (padrão `flexible`), `can_lend TINYINT(1)` (padrão 0) e `reserved_team ENUM('critico','hibrido') NULL` (no lugar do `reserved_for` do 3.3, com os valores do ENUM de equipe); CHECKs: reserva só em `fixed`; empréstimo só em `fixed` sem reserva. Nenhuma linha muda: todo PA continua flexível |
| N do Crítico | O número de PAs marcados como reservados ao Crítico (sem campo numérico separado): hoje 2, a gestão marca pela tela |
| Quem ocupa o reservado | As pessoas do Crítico presenciais no dia, na ordem do papel do dia (ordem do papel; sem papel por último; empate pelo nome normalizado e o ID). O 1º PA reservado (fileira, coluna, número) fica com a 1ª pessoa. PA sem pessoa fica reservado e vazio e **não empresta** (opção (b): "mais assentos parados em dia de pouca gente"). Pessoa sem PA: alerta `reserved_short` (motor: ATENÇÃO CONFIG; Mapa PA: aviso "Crítico sem PA reservado") |
| Híbrido | Sem reserva (o serviço recusa `hibrido`); PA `fixed` com o vínculo da pessoa e o "pode emprestar" |
| PA reservado | Não recebe vínculo (409) nem preferência (409). Reservar exige o PA sem vínculo vigente ou futuro e sem preferência (409 com a contagem; nada é encerrado por script) |
| PA fixo | Um dono por data: vínculo sobreposto dá 409; marcar como fixo exige 0 pares de vínculos sobrepostos de hoje em diante |
| Preferências | `portal_pa_preferences` (PA do inventário por ID, colaborador, autoria; única por par; FKs `RESTRICT`); só cadastros ativos do grupo comum derivado, fora do "fora do Mapa"; até 20 por PA; a gravação troca o conjunto |
| Motor | `reserved_people` por dia (opcional; sem ele, o reservado fica ocupado sem pessoa, como o script: a paridade não muda); `reservedSeating` e `orderReservedPeople` são funções puras do motor, a regra única do motor e do Mapa PA; versão `f10-pa-2` |
| Mapa PA | O tipo do PA no cartão (Fixo, Fixo · empresta, Reservado ao Crítico), o ocupante do reservado no dia com o papel, o aviso de quem ficou sem PA e o inventário no diálogo do PA (gestão). **A fonte "escala do mês" fica para o F10-4** (depende do mês publicado) |
| API | `portal/pa_map.php`: `inventory_save` e `preferences_save` (`pa_map.manage`, CSRF); auditoria `PA_INVENTARIO_ALTERADO` (WARNING) e `PA_PREFERENCIA_ALTERADA` (INFO), só com o PA e os IDs |

**Achado:** o `OperationalService::engineSchedule` (a leitura de presença da tela Escala, do Calendário e do `mapPresence` do Mapa PA) **não passa o papel do dia** do 9.16 ao motor; só o `PresenceService` (Visão operacional e status) passa. Nessas telas, a pessoa do Crítico com papel aparece pela regra individual. No Mapa PA do F10-3, o reservado do Crítico usa a presença corrigida pelo papel (a mesma precedência), e o vínculo de alguém do Crítico continua pela regra individual. A correção do `engineSchedule` fica para um lote próprio, a aprovar (mexe na Escala, no Calendário e nos relatórios). **Aprovado e feito no 8.8 (2026-11-23)**, que também retira a correção local do reservado (D-F10-36).

### 3.4 Motor puro de distribuição de PA

**`services/PaDistributionEngine.php`:** função pura, sem banco, arquivo nem relógio, como o `PresenceEngine`. Recebe tudo já carregado e devolve o resultado do mês.

- **Entrada:**
  - dias úteis;
  - assentos (número, fileira, coluna, tipo, empresta, reserva, dono fixo por data, ativo, indisponível);
  - por dia, a situação de cada pessoa do grupo comum, vinda do motor de presença;
  - a presença dos donos fixos de fora do grupo comum;
  - preferências;
  - travas do dia;
  - o `last_pa` inicial.
- **Saída por dia:**
  - ocupante por PA;
  - "sem PA";
  - trocas;
  - a fonte de cada ocupação (`fixed`, `lock`, `engine`);
  - alertas.

**Regra do script, igual:**
- dono fixo presente no seu PA; fixo de dono ausente só entra se emprestar; o reservado sempre ocupado;
- a ordem das pessoas e dos PAs;
- o custo 0 / 2 / 12 + |j − i|;
- a programação dinâmica quando cabem todos, com o desempate do `<` estrito;
- o guloso com pontos 0 / 2 / 12 e desempate pelo nome normalizado quando falta PA;
- trocas sem o dono fixo.

**Diferenças em relação ao script:**

| Diferença | Comportamento |
|---|---|
| **Continuidade** | O `last_pa` inicial é o do **último dia útil publicado** do mês anterior. Sem mês anterior publicado, vazio, como hoje. Na regeração a partir de *X*, o do dia útil publicado anterior a *X* |
| **Travas manuais por dia** | Antes do cálculo, a pessoa e o PA travados saem das listas e a ocupação fica com fonte `lock`. A trava atualiza o `last_pa` e conta troca. Trava de pessoa não presencial, em PA indisponível ou em fixo com dono presente: **ignorada com alerta** ("trava sem efeito") |
| **PA bloqueado ou inativo** | Nunca entra, nem por trava |
| **"Sem PA"** | Mesma regra do guloso. O dia sai com alerta `PA INSUFICIENTE` **pela contagem real**: a capacidade de 3.6 coincide com quem ficou sem PA |
| **Identidade** | Por `employee_id`. Na **ordem**, o nome normalizado, como no script (decisão D-F10-7), com o ID como desempate entre homônimos (no script eles se sobrescrevem) |

**Custo computacional:** a programação dinâmica visita até 2^m conjuntos (m = PAs disponíveis no dia).
- Hoje m = 11.
- Com m = 16, cerca de 1 milhão de passos por dia e 23 milhões por mês, alguns segundos em PHP. Não foi medido; a estimativa vem da contagem de estados.
- **Proposta:** a programação dinâmica até 16 PAs disponíveis; acima disso, alerta "distribuição pelo caminho guloso" e o guloso do script. A paridade só é exigida na escala de hoje.

**QA:** `qa-pa-distribution.php`, com casos sintéticos sem nomes:
- os exemplos de custo, desempate, empréstimo, reservado, travas, bloqueado, continuidade e falta de PA;
- a comparação com uma porta em PHP do algoritmo do script, para mil meses aleatórios de semente fixa.

### 3.5 Geração do mês (migration 023)

**Modelo:**

| Tabela | Colunas e restrições |
|---|---|
| **`portal_monthly_schedules`** | `ref_month` DATE (dia 1), único; `current_revision` |
| **`portal_monthly_schedule_revisions`** | `schedule_id`, `revision`, `from_date`, `input_hash` CHAR(64), `engine_version`, `reason` (`publicacao`/`regeracao`), `published_by`, `published_at`, `counts` JSON (só contagens); único por `schedule_id, revision` |
| **`portal_monthly_schedule_entries`** | `revision_id`, `work_date`, `employee_id`, `situation` (`onsite`/`remote`/`absent`), `source` (`rule`, `team`, `exception`, `absence`, `implicit`), `team_snapshot`, `pa_number` (nulo), `pa_source` (`fixed`/`lock`/`engine`, nulo), `no_pa`, `moved`; único por `revision_id, work_date, employee_id` |
| **`portal_pa_day_locks`** | `work_date`, `pa_number`, `employee_id`, autoria; únicos por (`work_date`, `pa_number`) e por (`work_date`, `employee_id`) |

- **Sem nome nem categoria de ausência nas linhas:** o nome vem do cadastro e a categoria fica na ausência, com a permissão de hoje (minimização).
- **Linha vigente da data *d*:** a da revisão de maior número com `from_date` ≤ *d*.
- **Nada é apagado:** cada publicação ou regeração cria uma revisão. Isso substitui o HISTÓRICO com trilha.

**Fluxo:**

1. **Prévia** (`POST action=preview`, `mes`, `from` opcional). O servidor calcula com os dados atuais:
   - situação pelo `PresenceEngine::dayPresence`;
   - PA pelo motor de 3.4.

   Devolve a grade, os indicadores e os alertas da planilha:
   - presenciais e HOME comuns, % presencial, N1/N2, HOME por time;
   - capacidade real, assentos H/C, trocas, sem PA;
   - `OK` / `PA INSUFICIENTE` / `ATENÇÃO CONFIG`. O `ATENÇÃO CONFIG` sai quando alguém do grupo comum está sem regra, com ciclo inválido ou com trava sem efeito.

   Devolve também o `input_hash`: SHA-256 da forma canônica das entradas (regras, exceções, ausências de dia inteiro, inventário, preferências, travas, `last_pa` inicial, versão do motor). Nada é gravado.
2. **Publicar** (`POST action=publish`, `mes`, `from_date`, `expected_revision`, `input_hash`), numa transação:
   - `SELECT … FOR UPDATE` na linha do mês (criada com a chave única; criação duplicada relê);
   - `expected_revision` diferente da `current_revision`: **409** "Outra publicação aconteceu; recarregue a prévia";
   - o servidor **recalcula** (nunca grava o que o cliente mandou) e compara o hash; diferente: **409** "Os dados mudaram desde a prévia; confira a prévia nova";
   - grava a revisão e as linhas a partir de `from_date` e incrementa a `current_revision`;
   - grava `ESCALA_MENSAL_PUBLICADA` (INFO) ou `ESCALA_MENSAL_REGERADA` (WARNING), com mês, revisão, data inicial e contagens.
3. **Regerar a partir do dia *X*:** a mesma publicação com `from_date = X`.
   - Regra (D-F10-2): *X* ≥ o próximo dia útil depois de hoje. **O passado nunca é reescrito.**
   - Na 1ª publicação de um mês futuro, *X* é o 1º dia útil do mês.
   - Mês passado: 409.
4. **Dois usuários publicando ao mesmo tempo:** o `FOR UPDATE` serializa. O segundo recebe 409 pela revisão e refaz a prévia; nenhum dos dois grava por cima do outro.

**Ausência ou exceção nova depois de publicar** (D-F10-14):
- A presença do dia continua certa, porque o motor de presença lê regras, exceções e ausências, e não a escala publicada.
- A escala publicada continua sendo o plano. A leitura compara as duas e mostra **"divergência"** na célula (pessoa ausente com PA na escala, por exemplo). No Mapa PA, o PA de quem se ausentou aparece "livre (ausência)".
- Nada é regerado sozinho: quem publica decide regerar a partir de amanhã. A prévia mostra quantas células mudariam.

### 3.6 Capacidade

**PAs comuns do dia, pelo inventário** e não por número digitado: flexíveis ativos e disponíveis, mais os fixos que emprestam com o dono ausente. É exatamente a lista do motor; o `PA INSUFICIENTE` passa a coincidir com o "sem PA".

**Na base de hoje:**
- 11 flexíveis e o fixo do grupo comum (sem empréstimo);
- o máximo de presenciais comuns num dia de out a dez/2026, pela regra atual, foi 9;
- nenhum dia sem PA;
- 12, 14 e 17 trocas no mês.

São contagens do ensaio da seção 4.4, com a porta do algoritmo.

**Híbrido e Crítico:** são os assentos `reserved_for` (1 de cada na base), mostrados como "assentos H/C" no indicador. O total presencial + H/C usa o número de assentos, não o digitado (a base tem B9 = 2 com 1 PA do Crítico: alerta na virada). Depois do F9, o assento passa a ser do time (3.8).

### 3.7 Telas e exportação

**Nova página "Escala do mês"** (menu Escalas), com o mês no topo e as abas:

1. **Grade** (pessoa × dia útil, como ESCALA e TODOS):
   - filtro por equipe, time e torre;
   - célula com Presencial / Home / Ausente e o PA do dia;
   - marcador de divergência (3.5);
   - resumo lateral com o total de Home por pessoa;
   - em 390 px, a grade rola dentro do cartão com a coluna do nome fixa, como as tabelas do 17e.
2. **Resumo diário** (RESUMO): os indicadores e o status por dia.
3. **PA diário** (PA × dia, como PA DIARIO):
   - ocupante, `LIVRE`, trocas e sem PA;
   - com `pa_map.manage`, clicar numa célula cria ou remove a **trava** do dia e refaz a prévia.
4. **Mapa por semana:** reaproveita o componente da grade do Mapa PA (F6), com a fonte "escala do mês".
5. **Resumo de PA do mês:** exclusivo, compartilhado, rotativo e livre, sem o corte de 8 por fileira.

Barra de ação, só para quem publica:
- "Gerar prévia";
- "Publicar" (com o resumo de alertas e de mudanças antes de confirmar);
- "Regerar a partir de…".

**Telas de cadastro:**
- **Times de rodízio** (Escalas > Times): o editor do 3.2, os membros com vigência e a prévia.
- **Inventário de PA** (Mapa PA > Inventário): tipo, dono fixo (vínculo), empréstimo, reserva H/C, preferências, ativo e indisponível.

**Exportação em Excel com as mesmas abas.** `GET portal/monthly_schedule_export.php?mes=`, com auditoria `ESCALA_MENSAL_EXPORTADA` (INFO, mês e perfil). Duas opções:

| | (a) Gerador mínimo próprio (`ZipArchive` + XML) | (b) Biblioteca nova (PhpSpreadsheet via Composer) |
|---|---|---|
| Dependência | Nenhuma. O PHP do servidor já tem `ZipArchive` (confirmado no deploy 2), e o repositório já lê xlsx assim (`SpreadsheetImportService`) e gera ZIP (`api/download_relatorio.php`) | O projeto **não tem Composer** hoje: entraria o Composer, o lockfile, o `vendor/` no deploy e as dependências transitivas da biblioteca |
| Segurança | Superfície pequena: só escrita, valores como texto ou número (nunca fórmula: sem injeção de fórmula), XML escapado. QA abre o arquivo com o leitor do próprio repositório | A biblioteca teve avisos de segurança publicados (XXE na leitura e XSS no gerador HTML, entre outros; **não conferidos agora**: conferir no GitHub Advisory e no `composer audit` antes de qualquer decisão) |
| Formatação | Estilos fixos: cor de fundo, negrito, borda, mescla, largura de coluna, painel congelado, formato de % (o que o script usa) | Completa |
| Custo | Um serviço de umas centenas de linhas e o QA | Aprovação, verificação de CVE, deploy com `composer install` |

**Recomendação: (a).** As abas do script usam um conjunto pequeno e fixo de formatação. A (b) só se pedirem gráfico ou fórmula.

### 3.8 Crítico e Híbrido

- **Dependência do F9:** a equipe Crítico (11.3 do desenho principal; o enum na 020). A proposta do responsável é o Híbrido (torre "HB") virar equipe própria no mesmo F9 (D-F10-1).
- **Antes do F9, o F10 faz como a planilha:** as pessoas do Híbrido e do Crítico ficam `grupo_escala = 'separado'`, fora do grupo comum. Na grade, aparecem com "Outra escala" ou com a regra delas, se tiverem; elas não entram na distribuição; os assentos são `reserved_for`.
- **Depois do F9:** o grupo comum continua N1/N2. O assento reservado passa a ter dono pela presença do time (proposta: o assento do Crítico fica ocupado quando alguém do Crítico está presencial), e o indicador usa a contagem real do time.

---

## 4. Plano de validação por paridade

### 4.1 Script de comparação

**`scripts/paridade-escala-mensal.php`**, no repositório e **sem dados**:
- Recebe três caminhos como argumento:
  - a planilha local (`_referencias/…xlsx`);
  - o arquivo de casamento `_referencias/casamento-nome-id.csv` (nome normalizado → ID; local, **nunca no git**: `_referencias/` já está no `.gitignore`);
  - o JSON do portal do mesmo mês.
- **O JSON do portal** vem de `GET portal/monthly_schedule.php?mes=AAAA-MM&formato=paridade`, só para admin: a prévia do mês por `employee_id`, com situação e PA, **sem nome**. Para meses sem publicação, usa a prévia, sem gravar.
- O script lê a planilha com o leitor do repositório (`SpreadsheetImportService`, com um método novo que recebe um caminho).
- **Compara pessoa a pessoa e dia a dia** e imprime só contagens: linhas iguais e divergentes por motivo:
  - sem casamento de ID;
  - sem regra no portal;
  - tipo de regra diferente;
  - mesma regra, dia diferente;
  - exceção ou ausência só de um lado;
  - fora do grupo comum de um lado;
  - dia não útil de um lado.
- **Nunca imprime nome,** nem na divergência. Com `--detalhe`, imprime o ID do portal e a data, para o responsável investigar no portal.

### 4.2 Meses e critério

- **Meses:** out, nov e dez/2026 (o HISTÓRICO da base).
- **Critério:** **0 divergências, ou cada uma explicada e aprovada** pelo responsável, registrada no desenho só com contagem e motivo.
- **Divergências esperadas:**
  - o HISTÓRICO não guarda a regra (1.6, item 15);
  - out e nov/2026 foram gerados com uma configuração diferente da atual;
  - o portal só passa a ter regra para todos a partir do corte (16/11).

  O portal deve reproduzir a regra **vigente em cada data**, pela vigência da 011: a paridade de outubro depende de as regras do portal terem a vigência certa.

### 4.3 Ensaio feito neste desenho (só contagens; porta do script, não o portal real)

| Mês | Linhas | Modo ou time mudou depois | Iguais | Diferentes |
|---|---|---|---|---|
| out/2026 | 308 | 66 | 233 | 9 |
| nov/2026 | 294 | 42 | 244 | 8 |
| dez/2026 | 276 | 0 | 276 | 0 |

- **Iguais e diferentes:** a configuração atual recalculada pela lógica do script e pelo motor do portal deu o mesmo resultado nas duas (2.2).
- **As 17 diferentes** são de uma única regra SEMANAL que trocou um dia de lugar depois da geração de out e nov: 2 dias por semana, um de cada sentido.
- **Dezembro**, gerado com a configuração atual, bate 276/276.

### 4.4 Distribuição de PA

**Duas etapas, para separar o algoritmo do cálculo da escala:**
1. **Escala (4.1).**
2. **PA com as mesmas entradas.** O script de paridade monta a entrada do `PaDistributionEngine` com a situação **da própria planilha** do mês, ou seja, a presença do HISTÓRICO, e com o CONFIG PA traduzido para o inventário (tipo, empréstimo, reserva, dono e preferências pelo casamento de ID). Depois compara com as abas `PA DIARIO MM-AAAA` (ocupante por PA e dia, TROCAS FLEXÍVEIS, SEM PA) e `MAPA PA MM-AAAA` de um arquivo mensal gerado pelo script.

**O PA DIARIO traz só o primeiro nome** (`short_pa_name`). O casamento local precisa da coluna "primeiro nome → ID" e marca como "ambíguo" o primeiro nome repetido; essas células contam à parte.

**Critério:** 0 divergências de ocupante e de trocas, com `last_pa` vazio no 1º dia, como no script.

**Pendência do responsável:** não há arquivo mensal na estação (só a base). É preciso gerar um mês pelo script, por exemplo dez/2026, e copiá-lo para `_referencias/`.

**Ensaio deste desenho:** a porta em Python do algoritmo, rodada sobre a base para out, nov e dez/2026, deu:
- programação dinâmica em todos os 66 dias;
- nenhum "sem PA";
- 12, 14 e 17 trocas.

A porta não foi comparada com um arquivo mensal real.

---

## 5. Decisões a confirmar

**Revisão de 2026-11-13:** D-F10-1 a D-F10-18 aprovadas como recomendadas na tabela abaixo (D-F10-4 e D-F10-9 na opção (a)). A D-F10-19 (assentos do Crítico) fica com o responsável, a responder antes do F10-3.

| # | Decisão | Recomendação | O que muda conforme a resposta |
|---|---|---|---|
| D-F10-1 | **Híbrido (torre "HB") vira equipe própria**, como o Crítico, no F9 (proposta do responsável) | Confirmar | Sim: a 020 do F9 amplia o enum com `hibrido`, e o assento reservado vira do time. Não: o Híbrido fica `separado` com assento reservado, sem equipe |
| D-F10-2 | **Mês publicado e congelado, com "regerar a partir do dia *X*"** (proposta do responsável) | Confirmar, com *X* ≥ o próximo dia útil depois de hoje | Sim: 3.5 como está. Se *X* puder ser hoje, a regeração reescreve um dia em andamento: o PA de quem já sentou pode mudar |
| D-F10-3 | **Admin e Liderança geram e publicam; os demais só leem** (proposta do responsável) | Confirmar; nova permissão `escala_mensal.publish` para os dois | Incluir o gestor: ele ganha publicar sem ter `escalas.manage`, uma combinação nova a desenhar |
| D-F10-4 | **Feriados** | (a) Ignorar, como o script, pela paridade; o feriado entra como ausência ou exceção coletiva | (b) Lista de feriados só para a distribuição de PA e o resumo, sem mexer no ciclo: tabela nova. (c) Feriado como dia não útil no motor: muda o rodízio (o ciclo deixa de contar o feriado), quebra a paridade e mexe no F2 a F7 |
| D-F10-5 | **Quem edita times e inventário** | Liderança e admin (`escalas.manage` e `pa_map.manage`, como hoje) | Só admin: uma permissão nova para os dois cadastros |
| D-F10-6 | **Limite de trocas** | Sem limite no motor (paridade); indicador por dia e alerta acima de N (configurável, padrão a definir) | Com limite: o motor deixa de ser o do script (outro custo), e a paridade de PA cai |
| D-F10-7 | **Ordem das pessoas no motor de PA** | Nome normalizado, como o script (paridade), com o ID no desempate | Por ID: a distribuição fica estável quando muda um nome, mas não bate com o script |
| D-F10-8 | **Vínculos recorrentes em PAs flexíveis na virada** | Viram preferências e são encerrados na véspera do 1º mês publicado | Continuam como ocupação: o PA precisa virar `fixed`, ou o vínculo e a escala disputam o mesmo PA |
| D-F10-9 | **Exportação em Excel** | (a) Gerador mínimo próprio | (b) Composer e PhpSpreadsheet, com aprovação e CVE conferidas |
| D-F10-10 | **O que o técnico vê** | A grade de todos, com a situação e o PA, como hoje no Calendário e no Mapa PA; sem categoria de ausência (P9) | Só a própria linha: a grade, o resumo diário e o mapa têm um modo restrito |
| D-F10-11 | **Quem exporta** | Admin, Liderança e gestor (`relatorios.read`), com auditoria; o técnico não | Incluir o técnico: o arquivo circula com todos os nomes, como hoje |
| D-F10-12 | **Continuidade no 1º mês** (o mês anterior não foi publicado no portal) | `last_pa` vazio, como o script | Semear pelos vínculos atuais: menos trocas no 1º dia, mas o 1º mês diverge do script |
| D-F10-13 | **PA fixo de dono ausente** | Respeitar o "pode emprestar" por PA, como o script | Sempre emprestar: mais capacidade, mas o dono pode chegar e achar o PA ocupado |
| D-F10-14 | **Ausência ou exceção depois de publicar** | Divergência marcada na leitura; regeração só manual | Regerar sozinho a partir de amanhã: menos trabalho, mas o plano muda sem ninguém decidir e sem a prévia |
| D-F10-15 | **Retenção dos meses publicados** | Seguir a política de retenção que está pendente (LGPD, seção 10 do desenho principal); até lá, nada é apagado | Prazo fixo agora (exemplo: 24 meses), com rotina de expurgo e auditoria |
| D-F10-16 | **HISTÓRICO da planilha** | Não importar. Ele fica como arquivo local, com retenção decidida pelo responsável, e serve só para a paridade | Importar: entra com o casamento nome → ID e cria revisões "importadas" para out a dez/2026 |
| D-F10-17 | **Limite da programação dinâmica** | Até 16 PAs disponíveis no dia; acima, o guloso com alerta | Outro algoritmo exato (Hungarian): mais rápido, mas o desempate muda e a paridade cai nos empates |
| D-F10-18 | **Os dois "sempre presentes" do código** | Cadastro normal: dono fixo pelo vínculo, presença pelo motor (a Liderança já é presencial implícita) | Manter sempre presentes: um assento reservado a mais por pessoa |
| D-F10-19 | **Assento do Crítico** (a base tem 2 presenciais por dia e 1 PA) | Na virada, conferir com a gestão quantos assentos o Crítico tem e cadastrar no inventário | 2 assentos: a capacidade comum cai em 1 |

---

## 6. Lotes, ordem, riscos, LGPD, rollback e deploy

### 6.1 Lotes e commits (estimativa)

Cada lote segue o padrão: um commit de entrega e um de docs, com parada para revisão.

| Lote | Conteúdo | Commits | Migration |
|---|---|---|---|
| F10-1 | Motor puro de distribuição de PA, QA e o script de paridade | 3 | — |
| F10-2 | Times de rodízio, editor de ciclo semanal e `grupo_escala` | 4 | 021 |
| F10-3 | Inventário completo e preferências; Mapa PA com a fonte | 3 | 022 |
| F10-4 | Mês: prévia, publicação, regeração, travas, concorrência, auditoria e leitura com divergência | 4 | 023 |
| F10-5 | Telas da "Escala do mês" | 4 | — |
| F10-6 | Exportação em Excel | 2 | — |
| F10-7 | Paridade de out a dez/2026, mês em paralelo com a planilha e virada | docs | — |

Total: perto de 20 commits.

### 6.2 Ordem em relação aos outros trabalhos

- **F8:** espera o AD1 e o AD2 da Liderança. **F9:** depois do F8. **Módulo de CHGs:** espera o G0c.
- **O F10 não depende deles,** exceto a parte do Híbrido e do Crítico (3.8).
- **Proposta:**
  - o F10-1 primeiro: não mexe em nada do sistema e prova a paridade de PA cedo;
  - depois F10-2 e F10-3;
  - se a Liderança responder o F8 no meio, o F8 entra no próximo intervalo entre lotes;
  - o F10-4 em diante, depois.
- **O corte do F5 (16/11) não espera o F10** (seção 7).

### 6.3 Riscos

| Risco | Mitigação |
|---|---|
| Paridade de out e nov/2026 com divergências que não são erro (configuração mudou depois) | Motivos separados na contagem; critério "explicada e aprovada" |
| Ordem por nome no motor de PA: renomear alguém muda a distribuição dali para a frente | Decisão D-F10-7; só afeta meses não publicados |
| Programação dinâmica exponencial com inventário maior | Limite de 16 (D-F10-17) |
| Escala publicada e presença real divergem (ausência nova) | Divergência visível; regeração manual com prévia |
| Dois cadastros da mesma coisa (regra do time e regra individual) | O time gera a regra; a regra individual de membro dá 409 |
| Mês paralelo: a planilha e o portal com escalas diferentes | Um mês inteiro em paralelo, comparado pelo 4.1, antes da virada; a planilha continua oficial até a decisão |
| Gerador de Excel próprio com arquivo que o Excel recusa | QA abre o arquivo com o leitor do repositório; conferência manual no Excel antes do deploy |

### 6.4 LGPD

- **A escala revela quem está em casa e em que dia,** e a ausência, a categoria (férias, por exemplo).
- **Proposta (D-F10-10 e D-F10-11):**
  - todos os perfis veem a situação (Presencial/Home/Ausente) e o PA, como hoje no Calendário e no Mapa PA;
  - a categoria da ausência só para admin e gestor (P9), igual hoje;
  - exportar só admin, Liderança e gestor, com auditoria;
  - o arquivo exportado não leva a categoria.
- **Minimização:**
  - as linhas guardam o ID, não o nome;
  - a auditoria só com IDs e contagens;
  - a observação de ausência nunca entra.
- **Retenção:** D-F10-15.
- **Fim da planilha:** o arquivo mensal deixa de circular, porque a tela e a exportação controlada o substituem. A base com o HISTÓRICO fica com o responsável, até a decisão D-F10-16.
- **Paridade:** o casamento nome → ID e a planilha ficam só em `_referencias/`, na estação. Nada com nome vai ao servidor nem ao git.

### 6.5 Rollback de cada lote

| Lote | Rollback |
|---|---|
| F10-1 | Reverter o commit: o motor não é chamado por nada em produção |
| F10-2 | Código anterior. A 021 tem rollback com cópia `*_bkp_021`. As regras geradas pelos times continuam regras válidas (o `rotation_team_id` sai com a coluna) |
| F10-3 | Código anterior. A 022 tem rollback com cópia. O inventário e os vínculos de hoje continuam |
| F10-4 a F10-6 | Código anterior. A 023 tem rollback com cópia das revisões e linhas. A presença não depende da escala publicada e não muda |
| F10-7 | A planilha volta a ser a fonte, enquanto o rollback for necessário |

### 6.6 Deploy

- **Roteiro e folha do dia para cada deploy**, como o deploy 2:
  - conferência do ambiente;
  - commit validado;
  - ensaio dos QAs em `/tmp`;
  - backup;
  - manutenção, `merge --ff-only`, build;
  - fumaça;
  - conferência só de leitura;
  - polling medido alguns minutos depois da reabertura, com 2 rodadas (aprovado em 2026-11-12);
  - rollback.
- **Migrations:** ensaio na cópia e verificação só com contagens.
- **Numeração pela ordem de execução:** o próximo deploy de produção é o 3. Se o F8 entrar antes, ele é o 3 e o primeiro do F10 é o 4.
- **Proposta de agrupamento:**
  - um deploy com F10-1 a F10-3 (021 e 022: cadastros, sem efeito na presença);
  - outro com F10-4 a F10-6 (023 e telas);
  - depois, o mês em paralelo e a virada, sem deploy.

---

## 7. Enquanto o F10 não entra

Como cadastrar hoje, pelo portal, cada modo da planilha, para resolver os 6 sem regra antes do corte de 16/11. O comando 115 da folha do deploy 1 conta a regra vigente na data do corte e já desconta quem está "fora do Mapa". As regras são gravadas pela Liderança ou pelo admin; o início antes de hoje exige `escalas.retroativa`, que os dois têm.

| Modo na planilha | No portal (Escalas, regra do técnico) |
|---|---|
| **RODÍZIO, Time 1, 2 ou 3** | Tipo **Ciclo**, **começa por remoto, 2 presenciais e 1 remoto**. **Data inicial = um dia em que o time está em HOME**, até 16/11 para cobrir o corte: Time 1 em **12/11** (ou 09/11), Time 2 em **13/11** (ou 10/11), Time 3 em **16/11** (ou 11/11), todas de 2026. Com a âncora da planilha (01/10/2026), o índice de 16/11 é 32 (resto 2: Time 3 em HOME). Prova pelo motor: cada time com essas datas de início reproduz a âncora original em todos os dias úteis até 31/12/2027 (de 295 a 300 dias por caso, 0 diferentes) |
| **FIXO SEMANAL** | Tipo **Dias fixos**, marcando os dias presenciais (ou "informar os dias de home") |
| **FIXO CICLO de N semanas** | **Não cabe na tela de hoje** (2.3). Proposta até o F10-2: **Dias fixos** com a semana mais frequente, mais **Exceção por data** nos dias que diferem, ao menos de 16/11 a 15/12 (a competência do corte). Para 3 semanas e âncora 28/09/2026, 16/11 cai na semana 2 |
| **SEM HOME** | **Sempre presencial** |
| **FULL HOME** | **Sempre remoto** |
| **HÍBRIDO e CRÍTICO** (fora do grupo comum) | Até o F9, a decisão é da gestão: a regra real da pessoa (se a escala separada for conhecida) ou **"Fora do Mapa e da escala"** (admin), que tira a pessoa da contagem do 115, do Mapa e do Calendário |
| **FORA** | **"Fora do Mapa e da escala"** (admin) |
| **EXCECOES HOME / PRESENCIAL** | **Exceção por data** (remoto ou presencial), uma por dia |
| **EXCECOES FÉRIAS** | **Ausência** (F3) de dia inteiro, com o período e a categoria |

Depois de cadastrar, rodar de novo os comandos 114 e 115 (`sem_regra_no_corte = 0` e `sem_regra_na_competencia = 0`) e seguir o 116 e o 117, até 15/11.

---

## 8. Execução

### 8.1 Lote F10-1: motor de distribuição de PA, QA e paridade (2026-11-13)

**Commits** (branch `feat/employee-form-rhf-zod`, sem push):

| Commit | Conteúdo |
|---|---|
| `968e9b1` | `services/PaDistributionEngine.php` (motor puro), `scripts/qa-pa-distribution.php`, o QA novo no `test-local.ps1` e no `test-linux.sh` e a lista de QAs posteriores ao deploy 2 na trava do roteiro |
| `665b219` | `scripts/paridade-escala-mensal.php` (paridade, só contagens), `scripts/qa-paridade-escala.php` e os mesmos registros |
| o de docs | esta seção, a aprovação do desenho e o `REGISTRO_USO_IA.md` |

**Nada em produção chama o motor nem a paridade.** O QA trava isso: fora do QA e da paridade, nenhum arquivo cita o `PaDistributionEngine`. O rollback é reverter os commits.

**Motor (`PaDistributionEngine::distribute`).**
- Entrada e saída como na 3.4. A saída traz, por dia:
  - o ocupante por PA, com a fonte `fixed`, `reserved`, `lock` ou `engine`;
  - o sem PA, as trocas e quem trocou;
  - o método (`dp`, `greedy` ou `none`);
  - a capacidade (PAs livres do dia) e os presenciais;
  - os alertas e o status.
- **Status do dia:** `ATENCAO CONFIG` e `PA INSUFICIENTE`, nessa ordem, a mesma do script (linhas 1121-1122). Lista vazia é o `OK`.
- **Alertas:**
  - `no_pa`;
  - `greedy_large`, acima de 16 PAs livres (não muda o status);
  - `lock_ignored`, com o motivo: `duplicate_seat`, `duplicate_person`, `unknown_seat`, `seat_not_available`, `person_not_common`, `person_has_fixed_seat` ou `person_not_onsite`;
  - `presence_missing`, `owner_overlap` e `owner_multiple_seats`.
- **Validação na fronteira:** entrada fora da forma dá `InvalidArgumentException` com o campo. Exemplos: data, PA repetido, tipo, reserva fora de `fixed`, vigência invertida, situação desconhecida, ID que não é inteiro positivo.
- **Diferenças em relação ao script, além das da 3.4:**
  - dono com dois PAs fixos no mesmo dia: só o primeiro da ordem; o outro empresta, se puder, com `owner_multiple_seats` (o script sentava a mesma pessoa nos dois);
  - o nome normalizado cobre ASCII e Latin-1 sem depender do `mbstring`. O resultado é o mesmo em qualquer servidor; um nome com caractere fora do Latin-1 pode ordenar diferente do Python.
- **Desempenho medido:** pior caso (16 PAs livres e 16 pessoas por dia, 23 dias) em 1,1 s por mês, com pico de 12 MB.

**QA do motor (`qa-pa-distribution.php`, 93 verificações).**
- Casos em tabela: custo, desempate, ordem, empréstimo, reservado, vigência, bloqueado e inativo, travas com cada motivo, guloso, limite de 16, continuidade, alertas, status, determinismo e validação.
- A porta em PHP do `build_pa_assignments` contra o motor, em 1000 meses de semente fixa (21.712 dias).
- A pureza do motor e a ausência de chamada em produção.

**Script de paridade (`paridade-escala-mensal.php`).**
- Ferramenta da estação, só linha de comando (fora dela, 404). A pasta `scripts/` já é bloqueada no `.htaccess`.
- Recebe por argumento a planilha, o casamento e o JSON do portal, todos em `_referencias/`.
- Imprime só contagens. Com `--detalhe`, o `employee_id` (ou um índice provisório `#n`) e a data, nunca o nome.
- O leitor de xlsx é próprio (zip e zlib, sem `ZipArchive`), com limites de tamanho e de entradas, e recusa XML com `DOCTYPE`.
- Três comandos:
  - `modelo-casamento <planilha> <_referencias/casamento-nome-id.csv>`: grava só numa pasta `_referencias` e nunca sobrescreve;
  - `escala <planilha> --meses=… [--casamento=… --portal=…] [--detalhe]`: sem `--portal`, recalcula o HISTÓRICO pela configuração atual da planilha, traduzida em regras do portal (2.2), no `PresenceEngine`; com `--portal`, compara com o JSON do portal pelos motivos da 4.1;
  - `pa <planilha> --mes=… [--mensal=…] [--casamento=…] [--fora-da-equipe=presente|ausente]`: monta a entrada do motor pela presença do HISTÓRICO e pelo CONFIG PA (4.4); com `--mensal`, compara com a aba `PA DIARIO MM-AAAA`.
- **QA (`qa-paridade-escala.php`, 79 verificações):** monta um xlsx sintético com nomes fictícios e confere:
  - o leitor e as conversões;
  - cada aba de entrada, com as regras do script;
  - a tradução de cada modo;
  - cada motivo, com e sem portal;
  - o modelo do casamento;
  - a entrada do motor e a comparação com o PA DIARIO;
  - que a linha de comando não imprime nenhum nome.

**Contrato do JSON do portal (`paridade-escala-v1`).** O `GET portal/monthly_schedule.php?mes=AAAA-MM&formato=paridade` do F10-4, só para admin, precisa cumprir:

```json
{"formato": "paridade-escala-v1", "mes": "2026-10",
 "linhas": [{"employee_id": 12, "data": "2026-10-05", "situacao": "presencial",
             "fonte": "regra", "rule_type": "cycle"}]}
```

- `situacao`: `presencial`, `remoto`, `ausente`, `sem_escala`, `dia_nao_util` ou `fora` (fora do Mapa e da escala).
- `fonte`: `regra`, `excecao`, `ausencia`, `implicita` ou `nenhuma`, as do `PresenceEngine`.
- Nenhum nome.

### 8.2 Resultados da paridade (só contagens)

**Algoritmo de PA, motor contra o script.**

| Comparação | Dias | Diferentes |
|---|---|---|
| Motor x `build_pa_assignments` **real** (copiado do V17 sem mudança), meses aleatórios com nomes fictícios, sementes 1 e 20261113, 1000 meses cada (scratchpad) | 43.566 | 0 |
| Motor x porta em PHP do QA, 1000 meses | 21.712 | 0 |
| Motor x `build_pa_assignments` real **na base**, com a presença do HISTÓRICO, out a dez/2026, comparado por hash do nome (scratchpad) | 66 dias (832 células) | 0; trocas e sem PA iguais em todos os dias |

**Escala (4.1) sem casamento e sem portal:** HISTÓRICO x configuração atual da planilha no motor do portal.

| Mês | Linhas | Iguais | Mesma regra, dia diferente | Modo ou time mudou depois |
|---|---|---|---|---|
| out/2026 | 308 | 233 | 9 | 66 |
| nov/2026 | 294 | 244 | 8 | 42 |
| dez/2026 | 276 | 276 | 0 | 0 |

São os números do ensaio da 4.3, agora pelo script do repositório. As 17 "mesma regra, dia diferente" são a regra SEMANAL alterada depois da geração.

**PA na base, pelo motor (`pa`):**

| Mês | Dias | Programação dinâmica | Sem PA | Trocas | Presenciais comuns (máx.) | PAs livres no dia |
|---|---|---|---|---|---|---|
| out/2026 | 22 | 22 | 0 | 13 | 11 | 11 |
| nov/2026 | 21 | 21 | 0 | 27 | 10 | 11 |
| dez/2026 | 23 | 23 | 0 | 17 | 9 | 11 |

**Por que out e nov diferem da 3.6 e da 4.4** (12 e 14 trocas, máximo de 9): aquele ensaio recalculou a presença pela regra atual. Aqui a presença é a do HISTÓRICO, como a 4.4 pede. Dezembro, gerado com a configuração atual, coincide (17 trocas, máximo 9).

**Casamento nome → ID:** `_referencias/casamento-nome-id.csv`, gerado na estação (fora do git, conferido com `git check-ignore`).
- 23 nomes (EQUIPE, HISTÓRICO, REGRAS FIXAS, EXCECOES e CONFIG PA), com a coluna `employee_id` vazia.
- **Primeiro nome do PA DIARIO:** preenchido localmente, no scratchpad, pelo `short_pa_name` real do script. Entraram as 3 desambiguações; sobrou 1 par de primeiros nomes ambíguo (2 linhas), que conta à parte.
- **Sem casamento de ID na entrada do `pa`:** 16 em out e nov e 14 em dez (pessoas do mês, donos e preferências).

### 8.3 O que falta para a paridade completa

1. **O responsável preenche o `employee_id`** dos 23 nomes no `_referencias/casamento-nome-id.csv`, conferindo na tela de funcionários. O arquivo nunca vai ao git.
2. **O JSON do portal** (`paridade-escala-v1`) de out, nov e dez/2026, que vem do endpoint do F10-4.
   - Até lá, a escala só se compara com a configuração da planilha (8.2), não com as regras gravadas no portal.
   - A paridade real depende de o portal ter as regras com a vigência certa: os 6 sem regra do corte de 16/11 e as datas de início da seção 7.
3. **Um arquivo mensal gerado pelo script** (ex.: dez/2026), copiado para `_referencias/`, para rodar o `pa --mensal`. Os itens 1 e 3 ficam com o responsável (aprovação de 2026-11-13).
   - O algoritmo já bateu com a função real na base (8.2).
   - O arquivo confere o resto da cadeia: a leitura da aba, o primeiro nome e a configuração do dia da geração.
   - Só um mês gerado com a base atual é comparável: out e nov foram gerados com outra configuração (4.3).
4. **A aba MAPA PA não é lida:** é a mesma distribuição em outro desenho (linhas 837-992). O PA DIARIO cobre ocupante, trocas e sem PA.

### 8.4 Achados

- **`int(valor or padrão)` no script** (linhas 82-89 e 152): a célula com **0** vale o padrão (fase 0 do Time 2 ou 3; semana 0 das REGRAS FIXAS). A paridade reproduz o comportamento (`paridade_int_or`); a base de hoje não tem o caso. Registrado também nos pontos frágeis (1.6, item 7).
- **O PHP da estação (8.2, WinGet) não tem `ZipArchive` nem `mbstring`.** O servidor tem `ZipArchive` (deploy 2). Para o F10-6 (gerador próprio, D-F10-9), o QA da exportação precisa rodar na estação sem a extensão: escrever o zip sem o `ZipArchive`, como o gerador do `qa-paridade-escala`, ou rodar o QA com `php -d extension=zip`.
- **A trava do roteiro exigia todo QA novo no roteiro dos deploys 1 e 2**, que já foram executados e ficam como estão.
  - A trava agora tira da comparação os QAs da lista `$qasAfterDeploy2` (`qa-pa-distribution` e `qa-paridade-escala`). QA novo fora da lista continua reprovando.
  - **O roteiro do próximo deploy (3) precisa rodar os dois**, e a trava dele substitui a lista. **Aprovado em 2026-11-13:** quando o roteiro do deploy 3 for escrito, ele roda o `qa-pa-distribution` e o `qa-paridade-escala`, e a trava passa a comparar com ele (a lista `$qasAfterDeploy2` sai).

### 8.5 Validação

- **Mutação** no worktree, com a trava `mut_guard.php`:
  - commit 1: 73/73 mortos (motor, travas do roteiro e baterias);
  - commit 2: 55/55 (leitor, abas, tradução, motivos, modelo, entrada do motor, PA DIARIO, linha de comando sem nome e travas).
- **Sobreviventes da 1ª rodada, todos tratados:**
  - commit 1: o custo 13 (passou a ser travado como constante do script); o NBSP explícito no `trim` (equivalente: trocado pelo `\s` com `/u`); a ordem de `moved` (caso novo);
  - commit 2: o método de compressão desconhecido (falso morto: o nome do arquivo de teste aparecia na mensagem; arquivo renomeado); o tamanho declarado e o limite de entradas do zip (casos novos); a exceção HOME (caso novo).
- **Os commits foram refeitos antes da entrega:** `ba17ac5` virou `968e9b1`; `2b691c4`, `c7ef561` e `d79a1cf` viraram `665b219` (o último ajuste foi o título da saída sem o JSON do portal).
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree):
  - `968e9b1`: saída 0, frontend 22/22, navegador 1029/1029;
  - `665b219` e o commit de docs: no resumo da entrega.

### 8.6 Lote F10-2: times de rodízio, ciclo semana a semana e grupo da escala (2026-11-15)

**Commits** (branch `feat/employee-form-rhf-zod`, sem push; o deploy 3 roda com o `cd0653b` e não leva nada daqui):

| Commit | Conteúdo |
|---|---|
| `f346051` | Migration 021, rollback, verificação e linhas do ensaio; `qa-rotation-teams.php` (estrutura) na bateria; a trava separa a 021 e o QA novo dos deploys já escritos |
| `4b714c7` | `CyclePattern`, `RotationTeamPlanner`, `RotationTeamService`, `api/portal/rotation_teams.php`; o 409 do membro no `OperationalService`; `grupo_escala` nos endpoints de funcionários; `scripts/plano-times-rodizio.php` |
| `a740998` | Telas (Escala, painel do time, regra semana a semana, grupo na edição de funcionário), `qa:rotation-teams`, bloco 27 do navegador e o plano `f10` das capturas |
| ``4e3d9ec`` | Roteiro e folha do deploy 4 (`DEPLOY_LINUX.md`, `docs/DEPLOY4_FOLHA_DO_DIA.md`) e a seção 16 da trava |
| o de docs | Esta seção, as capturas e o `REGISTRO_USO_IA.md` |

**Migration 021** (3.1, sem linha de dado alterada):
- `portal_rotation_teams` (nome único, ativo, autoria);
- `portal_rotation_team_versions` (padrão com vigência; CHECK no banco: lista de 2 a 20 posições `onsite`/`remote` por `JSON_SCHEMA_VALID`, âncora em dia útil e, no semana a semana, 1 a 4 semanas, 5 posições por semana e âncora na segunda; uma versão por time e data);
- `portal_rotation_team_members` (pertença com vigência; uma aberta por pessoa pela coluna gerada `open_employee_id`, como o `uq_schedule_rule_open` da 011; um início por pessoa; fim nunca antes do início);
- `portal_schedule_rules.rotation_team_id` (nulo, FK, índice) e `funcionarios.grupo_escala` (`comum` para todos).

Toda FK com `ON DELETE RESTRICT`. O rollback para antes de alterar se existir cópia `*_bkp_021`, copia o que existe (do cadastro, só o ID e o grupo de quem não está no `comum`) e não apaga regra: as regras gravadas pelos times continuam válidas (6.5).

**Ensaio da 021 no MySQL 8.0.46** (`scripts/ensaio-migration.sh` do repositório, contêiner local `mysql:8.0.46` no WSL da estação, autorizado pelo responsável em 2026-11-15; dados fictícios: 14 cadastros, 13 regras, 3 exceções; schema montado pelo `database_SECURED.sql` e as migrations na ordem). Só contagens e assinaturas:

| Variante | Etapa | Resultado |
|---|---|---|
| (a) `f10_021` | estado inicial | `tabelas_021 = 0`, `colunas_021 = 0`, "021 ausente"; regras 13 (assinatura 2116700938), cadastros 14 (454325012) |
| | migration 1ª e 2ª vez | OK e OK; `tabelas_021 = 3`, `colunas_021 = 2`, `restricoes_021 = 9`; times, versões, pertenças e regras de time em 0; 14 no grupo `comum`; assinaturas iguais |
| | rollback 1ª vez | OK; `tabelas_021 = 0`, `copias_de_rollback_021 = 5`, "021 ausente"; assinaturas iguais |
| | rollback 2ª vez | para de propósito (`ERROR 1644 ... rollback_021 abortado: ja existe copia *_bkp_021`); nada muda |
| (b) `f10_021_rollback` | linhas do ensaio 1ª e 2ª vez | `times = 1`, `versoes = 1`, `pertencas_abertas = 1`, `regras_de_time = 1`, `membros_abertos_sem_regra_aberta_do_time = 0`, `regras_de_time_sem_membro = 0`, 1 cadastro `separado`; iguais na 2ª vez |
| | rollback 1ª e 2ª vez | OK, 5 cópias, assinatura das regras igual à inicial; a 2ª para de propósito |

As duas variantes terminaram com o banco de ensaio removido (saída 0). **Restrições linha a linha** num banco de rascunho: 28/28 (12 gravações válidas aceitas, 16 inválidas recusadas: nome repetido ou vazio, versão repetida, valor fora da lista, 1 e 21 posições, padrão que não é lista, âncora no sábado, semanal na terça, com 9 posições ou 5 semanas, time ou cadastro inexistente, segunda pertença aberta, início repetido, fim antes do início, regra com time inexistente, apagar time com versão e membro, grupo fora do ENUM). As cópias do rollback guardaram 1 time, 3 versões, 2 pertenças, 1 regra de time e 1 grupo; as 13 regras ficaram.

**Serviço** (`RotationTeamService`, D-F10-5):
- leitura para quem lê escalas, só com o ID do membro;
- criar, renomear e desativar o time; versão nova ou correção da versão do mesmo início, com prévia (20 dias úteis, membros que mudam, bloqueados e arquivados);
- incluir, mover e encerrar membros;
- gravar exige `escalas.manage`; data passada, `escalas.retroativa`; L9 como na regra individual; a Liderança e o fora do Mapa não entram em time.
- **O time é a fonte:** entrar, mudar de time e mudar o padrão gravam, na mesma transação, a regra `cycle` de cada membro dentro da pertença, pelo mesmo `planSave` da regra individual (o dono entra na comparação: a mesma regra de outro dono passa a ser do time). Sair não muda a regra. Travas na ordem time, colaboradores por ID.
- Auditoria só com IDs: `TIME_RODIZIO_CRIADO`, `TIME_RODIZIO_VERSAO_ALTERADA` (WARNING: time, data, tamanho do padrão, membros afetados e os IDs), `TIME_RODIZIO_MEMBRO_ALTERADO`, mais `TIME_RODIZIO_ATUALIZADO` (nome e situação) e `TIME_RODIZIO_NEGADO` (perfil sem permissão).

**Ciclo semana a semana e padrão explícito** (3.2): `cycle.weeks` (1 a 4 semanas de seg a sex, âncora na segunda) ou `cycle.pattern` (2 a 20 posições), para a regra individual e para o time; sem essas chaves, os blocos de antes, sem mudança. O FIXO CICLO intercalado passa a caber na API. `rule_config` ganha `weeks` só no semana a semana (o motor não lê; o rótulo vira "Ciclo 3 semanas, 9P/6R"); a prévia do F2B mostra o ciclo inteiro no semana a semana (até 20 dias) e os 10 de sempre nos demais.

**409 da regra individual de membro** (3.1, aprovado na abertura do lote): gravar ou prever a regra numa data coberta por pertença, remover com pertença de hoje em diante e importar a linha de quem é membro hoje dão "Este técnico está no Time X; mude pelo time." Quem não é membro segue o caminho de antes.

**Grupo da escala:** a lista de funcionários traz `grupo_escala` para a gestão; a edição (Liderança e admin, `funcionarios.manage`) grava só quando o campo vem, em comando próprio, com `FUNCIONARIO_GRUPO_ESCALA_ALTERADO` (só o ID).

**Telas** (componentes do redesenho, sem classe CSS nova: CSS 68187 de 70000, igual): seção "Times de rodízio" na Escala presencial (gestão; Liderança e admin editam), painel do time no Drawer (versões, nova versão semana a semana, por lista de dias ou copiada de outro time deslocada em N dias úteis, salvar só depois da prévia do servidor; membros com vigência e histórico), a regra individual "Em blocos" ou "Semana a semana" (a data vai para a segunda) e o "Grupo da escala" na edição de funcionário. Membro aparece com o selo do time e "Muda pelo time", sem Editar nem Remover. Capturas antes e depois, claro e escuro, 1280 e 390 px, em `docs/design/capturas/f10-2/` (em 390 px: 0 alvos abaixo de 40 px e 0 rolagens laterais).

#### 8.6.1 Plano de migração das regras de hoje (não roda em produção neste lote)

**Objetivo:** cada técnico com a regra de RODÍZIO de hoje (ciclo de 3 posições com 1 remoto, cadastrada pela seção 7) vira membro do Time 1, 2 ou 3 sem mudar nenhum dia da escala.

**Por que dá certo:** qualquer ciclo de 3 posições com 1 remoto, com qualquer âncora em dia útil, é uma das três fases do RODÍZIO da planilha (2.2). Com os times de padrão `[R,P,P]`, `[P,R,P]` e `[P,P,R]` e a âncora da planilha (01/10/2026), a regra que o time grava dá o mesmo dia a dia da regra de hoje.

**Passos, depois de aprovados (pela tela, com a Liderança):**
1. Escolher a data *X* da conversão (sugestão: o início de uma competência, depois do deploy 4).
2. Rodar o plano, **só leitura**: `runuser -u www-data -- php scripts/plano-times-rodizio.php --a-partir=X` (transação READ ONLY, só contagens; `--detalhe` lista `employee_id`, motivo e time, nunca nome). Saída: técnicos por motivo (`convertivel`, `lideranca`, `fora_do_mapa`, `sem_regra`, `nao_e_ciclo`, `ciclo_invalido`, `ciclo_fora_do_rodizio`, `regra_individual_agendada_depois`, `sem_time_equivalente`), convertíveis por time e a prova pelo motor (dias comparados, iguais, diferentes). O script sai com 1 se algum dia mudar.
3. Criar o Time 1, 2 e 3 com a versão a partir de *X* (ou antes), padrões `[R,P,P]`, `[P,R,P]`, `[P,P,R]` e âncora 01/10/2026 ("Copiar de outro time, deslocado em 1 e 2 dias úteis" faz o 2 e o 3 a partir do 1).
4. Incluir cada convertível no time indicado, a partir de *X*. A regra de hoje encerra em *X* − 1 (histórico intacto) e a do time vale de *X* em diante, com o mesmo dia a dia.
5. Conferir: a verificação da 021 (`membros_abertos_sem_regra_aberta_do_time = 0`) e o plano de novo (os mesmos convertíveis, 0 diferentes).

Quem não é convertível fica como está, com a regra individual: FIXO SEMANAL, FIXO CICLO, SEM HOME, FULL HOME e ciclos de outro tamanho.

**Prova pelo motor** (dados fictícios; `RotationTeamPlanner::classifyForTeams` e `compareDays` sobre o `PresenceEngine` do repositório):
- QA: cada data de início da seção 7 (Time 1 em 12/11 ou 09/11, Time 2 em 13/11 ou 10/11, Time 3 em 16/11 ou 11/11) vira o time certo com 0 dias diferentes em mais de 250 dias úteis; cada motivo de não conversão tem caso próprio.
- No MySQL 8.0.46 local, o script pela linha de comando sobre a base fictícia (`--a-partir=2026-11-23`, horizonte de 730 dias): 13 técnicos ativos; 4 convertíveis (1 no Time 1, 2 no Time 2, 1 no Time 3); 4 `nao_e_ciclo`, 1 `ciclo_fora_do_rodizio`, 1 `regra_individual_agendada_depois`, 1 `sem_regra`, 1 `lideranca`, 1 `fora_do_mapa`; **2092 dias comparados, 2092 iguais, 0 diferentes**.

#### 8.6.2 Os resultados de quem não vira membro não mudam

- **No MySQL 8.0.46 local**, numa transação externa desfeita no fim (17/17, saídas não vazias: 541 linhas da escala, 12 pessoas no Calendário):
  - (a) depois da migração pelo plano (os 4 convertíveis nos times): F2B (tela Escala), F5 (Calendário e relatório a partir do corte de 16/11/2026), F6 (Mapa PA) e F7 (período e resumo da operação) **idênticos para todos**, inclusive os membros e os totais; a regra individual de membro dá 409;
  - (b) depois de mudar o padrão do Time 1: F2B e F5 idênticos para quem não é do Time 1; o membro segue o padrão novo, com o rótulo do semana a semana.
- **Na bateria (banco simulado):** em 60 operações aleatórias de time (entrar, mover, mudar padrão, sair), as regras de quem não é membro ficam byte a byte iguais, e na pertença a regra é sempre a do time; no `qa-schedule-history`, quem não é membro grava e remove como antes. Os QAs do F2B, F5, F6 e F7 (`qa-schedule-history`, `qa-schedule-legacy-output`, `qa-presence-cutoff`, `qa-pa-conflicts`, `qa-operational-view`, `qa-presence-engine`) passam sem nenhuma expectativa mudada; as três que mudaram são deste lote (o filtro do formulário da regra e a contagem de `qa:*` no frontend; a matriz de perfis com o endpoint novo).

#### 8.6.3 Decisões de implementação a aprovar

| # | Decisão | Por quê |
|---|---|---|
| D-F10-20 | O 409 do membro vale também na prévia, na remoção (pertença de hoje em diante) e na importação | Remover apagaria a regra agendada do time; a importação gravaria a regra individual |
| D-F10-21 | Mudar o padrão vale só dentro da pertença; depois da saída, volta a regra que vigorava antes (com o mesmo dono) | "Sair do time não muda a regra" (3.1) continua verdade depois de uma versão nova |
| D-F10-22 | Regra individual agendada dentro da pertença bloqueia (409 com a contagem); regra de outro time na faixa é substituída | O time não sobrescreve decisão individual sem registro; a de outro time é a pertença anterior |
| D-F10-23 | Os dias sem regra dentro da pertença recebem a regra do time | A pertença cobre todos os dias dela (o planSave sozinho deixaria o buraco) |
| D-F10-24 | "Encerrar" com data antes do início cancela a pertença futura e desfaz as regras dela (a anterior volta ao fim que tinha) | Desfazer um engano sem deixar regra de time sem membro |
| D-F10-25 | Cadastro arquivado com pertença aberta: a versão nova o ignora e conta; o painel mostra "Arquivado" e a Liderança encerra | O arquivamento (F4, já em produção) não encerra a pertença. **Proposta:** encerrar a pertença no próprio arquivamento, num lote do F4 (muda a prévia do arquivar) |
| D-F10-26 | `grupo_escala` na edição de funcionário (Liderança e admin), em comando próprio, auditado; quem está no `separado` pode ser membro de time (não é bloqueado) | O F10-3 usa o grupo; nenhuma regra do desenho bloqueia o separado no rodízio |
| D-F10-27 | Desativar o time só sem membro aberto ou futuro; time desativado não recebe membro | Sem regra de time órfã |
| D-F10-28 | O semana a semana guarda `weeks` no `rule_config` (o motor não lê) e a prévia mostra o ciclo inteiro (até 20 dias) | O rótulo "Ciclo 3 semanas" e a grade ao editar; os demais tipos ficam com a prévia de 10 dias |
| D-F10-29 | Ciclo só presencial ou só remoto é recusado ("use Sempre presencial/remoto") | Mesmo tratamento do modo home do F2B |
| D-F10-30 | "Copiar de outro time": o padrão rodado N dias úteis, com a mesma âncora; o semana a semana copiado vira lista de dias | Mesmo cálculo da 2.2 |
| D-F10-31 | Ex-membro que corrige a regra no mesmo início dela mantém o `rotation_team_id` antigo (só a marcação; a escala é a gravada) | O UPDATE da regra individual não foi mudado, para não mexer nos QAs do F1/F2B; a verificação só olha membros abertos |

#### 8.6.4 Validação

- **Mutação** no worktree, com a trava `mut_guard.php`, cada mutante morto pela conferência certa:
  - commit 1: 24/24 (migration, rollback, linhas do ensaio, verificação e travas);
  - commit 2: 34/34 (serviço, planejador, padrão, 409 do membro, endpoint, grupo e plano); na 1ª rodada, 33/34 e 3 mortos por exceção não capturada: o caso "ciclo só presencial" entrou no QA do time e as três chamadas passaram a ter conferência própria (commit refeito antes da entrega: `5127cc1` virou `4b714c7`);
  - commit 3: 23/23 (telas, schema, funções puras, bloco 27); na 1ª rodada, 21/23: a âncora padrão (o "hoje" do QA era segunda) e a largura das tabelas do painel em 1366 px ganharam conferência (`bbdc095` e `0ab3f17` viraram `a740998`);
  - commit 4: 14/14 (roteiro, folha e a seção 16 da trava).
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree): `f346051`: saída 0, frontend 26/26, navegador 1092/1092; `4b714c7`: 0, 26/26, 1092/1092; `a740998`: 0, 27/27, 1112/1112 (repetida sozinha: na 1ª, um QA de 1 segundo rodou em paralelo por engano; as duas deram o mesmo número); `4e3d9ec`: 0, 27/27, 1112/1112. O commit de docs tem a bateria rodada depois dele, com o resultado no estado da sessão.

### 8.7 Lote F10-3: inventário de PA completo, reservados do Crítico e preferências (2026-11-19)

**Pedido de 2026-11-19:** revisar a seção 3 com o que mudou (grupo comum derivado da equipe; D-F10-19 revista: Crítico com 2 PAs reservados, configurável, e os papéis do 9.16 dizendo quem ocupa; Híbrido na opção (c)) e seguir do plano direto para a implementação (migration 022, ensaio no MySQL do WSL, bateria sozinha e mutação por commit, roteiro do próximo deploy), com uma parada só, no fim. Sem push. O plano revisto é a 3.3.1.

**Commits** (branch `feat/employee-form-rhf-zod`, sem push):

| Commit | Conteúdo |
|---|---|
| `3983886` | Migration 022, rollback, verificação e linhas do ensaio; `qa-pa-inventory.php` (estrutura) na bateria; a trava separa a 022 e o QA novo do deploy 4 já executado |
| `ea61e39` | `PaInventoryRules`; o motor com os reservados pelos papéis (`f10-pa-2`); `PaMapService` (leitura do inventário, ocupante do reservado, 409 do reservado e do fixo, `saveSeat`, `savePreferences`); `api/portal/pa_map.php` (`inventory_save`, `preferences_save`); QAs (os simuladores do `qa-archive` e do `qa-schedule-legacy-output` respondem "sem a 022") |
| `f448249` | Telas do Mapa PA (selo do tipo, cartão do reservado, aviso do Crítico sem PA, inventário no diálogo), `qa:pa-map` e o bloco 32 do navegador |
| `5868acb` | Roteiro e folha do deploy 5 (`DEPLOY_LINUX.md`, `docs/DEPLOY5_FOLHA_DO_DIA.md`) e a seção 18 da trava |
| `cbd5598`, `04720eb` | Os casos novos da mutação: o conjunto de preferências fora de ordem dos dois lados (`qa:pa-map`) e um merge só no roteiro do deploy 5 (trava) |
| `3fab6cc` | O `qa:unsaved-changes` com o inventário na proteção do diálogo do Mapa PA (a bateria do `f448249` reprovou nele) |
| `5f6a0c3`, `cc8bac6` e o último | Registro do deploy 4, esta seção, a 3.3.1 e o `REGISTRO_USO_IA.md` |

**Ensaio da 022 no MySQL 8.0.46 local** (contêiner do WSL; base fictícia `qa_f103` = `qa_chg` com 3 vínculos fictícios; `scripts/ensaio-migration.sh` do repositório, com `DADOS=portal_pa_inventory,portal_pa_assignments`). Só contagens e assinaturas:

| Variante | Resultado |
|---|---|
| (a) `f10_022` | migration 2x `OK` (`colunas_022 = 3`, `checks_022 = 2`, `tabela_022 = 1`; 16 PAs ativos, 16 flexíveis, 0 fixos, 0 reservados, 0 preferências; problemas 0); rollback 1x `OK` (`copias_de_rollback_022 = 2`, "022 ausente"); a 2ª para de propósito (`ERROR 1644 ... rollback_022 abortado`); assinaturas do inventário (16 linhas) e dos vínculos (3) iguais nos cinco estados |
| (b) `f10_022_rollback` | linhas do ensaio 2x: 13 flexíveis, 1 fixo que empresta, 2 reservados ao Crítico, 1 preferência, problemas 0 (iguais na 2ª); rollback 1x `OK` (2 cópias), a 2ª para; assinaturas iguais às do estado inicial |

**Decisões de implementação (a aprovar):**

| # | Decisão | Por quê |
|---|---|---|
| D-F10-32 | `reserved_team ENUM('critico','hibrido')` no banco; o serviço só aceita `critico` enquanto o Híbrido estiver na opção (c) | Mudar o Híbrido para (a) ou (b) depois é só serviço e tela, sem migration |
| D-F10-33 | N = o número de PAs marcados como reservados ao Crítico (sem campo numérico) | Uma fonte só: o assento é um PA do inventário; "configurável" é marcar ou desmarcar pela tela |
| D-F10-34 | Ordem de quem ocupa o reservado: com papel do dia pela ordem do papel (e o ID do papel), sem papel por último; empate pelo nome normalizado e o ID. Pessoa sem PA: alerta `reserved_short` (ATENÇÃO CONFIG no motor; aviso no Mapa PA). PA reservado vazio não empresta | O papel diz quem ocupa (D-F10-19 revista); a opção (b) aceita assento parado |
| D-F10-35 | A regra do reservado mora no motor como funções puras (`reservedSeating`, `orderReservedPeople`); o Mapa PA chama só elas; o `distribute` continua fora de produção até o F10-4 (a trava do F10-1 passou a permitir só essas duas no `PaMapService`) | Uma regra para o mapa de hoje e para o mês publicado |
| D-F10-36 | No Mapa PA, a presença do Crítico no reservado segue a precedência do 9.16 (ausência e exceção por data > papel > regra), corrigindo o dia do `mapPresence`, que não aplica o papel (achado da 3.3.1) | O reservado tem de mostrar quem vem pelo papel; a correção geral do `engineSchedule` é outro lote |
| D-F10-37 | PA reservado não recebe vínculo nem preferência (409); reservar exige o PA sem vínculo vigente ou futuro e sem preferência (409 com a contagem); nada é encerrado por script | A gestão decide o que fazer com quem estava no PA |
| D-F10-38 | PA fixo: um dono por data (vínculo sobreposto 409, inclusive o que começa no último dia do anterior); marcar como fixo exige 0 pares sobrepostos de hoje em diante | A 3.3 ("no máximo um vínculo ativo por data") |
| D-F10-39 | Preferências só de cadastros ativos do grupo comum derivado (N1 e N2 com `comum`, fora do "fora do Mapa"), até 20 por PA; a gravação troca o conjunto e audita incluídos e retirados por ID | A preferência só tem efeito na distribuição do grupo comum |
| D-F10-40 | A fonte "escala do mês" no Mapa PA fica para o F10-4 | Depende do mês publicado |
| D-F10-41 | Nenhum dado é marcado no deploy; a gestão marca os 2 reservados, os fixos e as preferências pela tela depois (a virada da D-F10-8 continua no F10-7) | "Nada muda no deploy", como os deploys anteriores |

**Validação:**
- **Mutação** no worktree, com a trava `mut_guard.php`, cada mutante morto pela conferência certa:
  - commit 1 (`3983886`): 24/24 (migration, rollback, linhas do ensaio, verificação, QA na bateria e a trava);
  - commit 2 (`ea61e39`): 33/33. Na 1ª rodada (`fbb2eca`), 31/33: o reservado vazio passar a emprestar e a exceção por data deixar de vencer o papel não eram testados. Casos novos (motor com mais gente que flexíveis; Crítico com troca para remoto e exceção presencial) e o commit refeito antes da entrega;
  - commit 3 (`f448249`): 14/14 no `qa:pa-map` e 3/3 no bloco 32 do navegador; na 1ª rodada, 13/14 (o conjunto de preferências comparado sem ordenar um lado): caso novo em `cbd5598`;
  - commit 4 (`5868acb`): 16/16; na 1ª rodada, 14/16 (o merge repetido passava; um mutante não se aplicava na folha e foi trocado): conferência nova em `04720eb`.
- **Integração no MySQL 8.0.46 local** (`qa_f103i` = `qa_f103` + 022, dados fictícios, transação desfeita): 18/18. As restrições recusam linha a linha (reserva em flexível, empréstimo em flexível e no reservado, empréstimo 2: `3819`; tipo e reserva fora do ENUM: `1265`); preferência repetida `1062`, cadastro inexistente `1452`, PA com preferência não some `1451`; o serviço reserva e marca fixo, recusa reservar com vínculo e o Híbrido; preferências trocam o conjunto e recusam o Crítico e o reservado; a leitura põe no 1º reservado quem tem o papel presencial do dia (com regra remota) e deixa o 2º vazio; vínculo no reservado 409, o fixo aceita o 1º dono e recusa o 2º; nada fica gravado.
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree): `45420db` 0, 30/30, 1153/1153 (repetida; ver o registro); `3983886` 0, 30/30, 1153/1153; `ea61e39` 0, 30/30, 1153/1153; `f448249` **saída 1**: 29/30 (o `qa:unsaved-changes` conferia a forma antiga da proteção do diálogo; corrigido no commit seguinte), navegador 1162/1162; o último commit: no resumo da entrega e no estado da sessão.
- **Trava do roteiro:** 513 verificações; os 39 comandos do deploy 5 passam no `bash -n`.

### 8.8 Lote 8.8: papel do dia do Crítico numa fonte só (achado da 3.3.1; 2026-11-23)

**Pedido de 2026-11-23** (com a aprovação do F10-3 e das D-F10-32 a D-F10-41): corrigir o `OperationalService::engineSchedule` para passar o papel do dia (9.16) ao motor com a mesma precedência do `PresenceService` (ausência e exceção por data > papel > regra), para a Escala, o Calendário, os relatórios e o `mapPresence`; com a correção geral cobrindo o `mapPresence`, retirar a correção local do reservado no Mapa PA (D-F10-36), deixando uma fonte só. Casos que matem os mutantes: Crítico com papel presencial e regra remota, com papel remoto e regra presencial, exceção por data vencendo o papel e ausência vencendo tudo; e a mesma pessoa igual na Escala, no Calendário, na Visão operacional e no Mapa PA. Entra no deploy 5, sem migration nova. Push no fim, com a bateria verde.

**Plano curto:**

| Item | Como fica |
|---|---|
| `engineSchedule` | Lê os papéis, rodízios e trocas do período pelo `PresenceRepository::criticalRoleContext` (as mesmas três consultas do `PresenceService`, só com cadastro do Crítico na lista) e passa a jornada do dia ao motor (`shiftFor`). A precedência é a do motor, já usada pela Visão operacional: ausência de dia inteiro e exceção por data vencem o papel; o papel vence a regra |
| Origem | `role` quando o papel decide (tela Escala: "Papel do Crítico"; Calendário: "Papel do dia: <papel> <início>–<fim>") |
| `mapPresence` | Leva o papel do dia (`papel`: ID, ordem e rótulo) de quem tem papel, mesmo quando a exceção ou a ausência decidem; a Escala e os relatórios não levam o campo |
| Mapa PA | O reservado do Crítico lê a presença e o papel do `mapPresence`: sem `PresenceRepository`, sem `criticalOnsite` (D-F10-36 retirada). O vínculo de alguém do Crítico no Mapa PA passa a seguir o papel também |
| Antes do corte | O cálculo antigo do Calendário e dos relatórios (antes da data de corte) fica como está |
| Deploy 5 | Sem migration nova. A fumaça passa a ler também o `calendar.php` e o `reports.php` (seis GET); o passo 12 confere uma pessoa do Crítico com papel igual na Escala, no Calendário e na Visão operacional; o aviso e a folha juntos; a trava confere |

**Commits** (branch `feat/employee-form-rhf-zod`):

| Commit | Conteúdo |
|---|---|
| `df207c9` | `engineSchedule` com o papel do dia; `mapPresence` com o papel; Mapa PA numa fonte só; "Papel do Crítico" na tela Escala; `qa-pa-conflicts` seção 7 (a mesma pessoa em cinco leituras) e o texto no `qa-calendar-presence` |
| `24b1895` | Esta seção (plano e commit 1), a linha de estado e a nota na 3.3.1 |
| `9c4002f` | Roteiro e folha do deploy 5 com o 8.8 (o que vai, aviso, fumaça com seis GET, conferência da mesma pessoa nas telas, nota do polling); `smoke-deploy1.sh` com o `calendar.php` e o `reports.php`; a trava |
| O último | A validação abaixo e o `REGISTRO_USO_IA.md` |

**Decisões de implementação (a aprovar):**

| # | Decisão | Por quê |
|---|---|---|
| D-F10-42 | O `engineSchedule` lê o papel pelo `PresenceRepository` (as mesmas consultas e a mesma regra `CriticalRolePlanner::dayRole` do `PresenceService`) e entrega ao motor pelo `shiftFor`; a precedência é a do `PresenceEngine` | Uma regra para a Visão operacional e para as demais telas; nada de cópia da precedência |
| D-F10-43 | Origem `role` no dia decidido pelo papel; tela Escala "Papel do Crítico"; Calendário "Papel do dia: <papel>" | Hoje o dia com papel aparecia como regra ou "sem regra" |
| D-F10-44 | O papel do dia sai só no `mapPresence` (ID, ordem e rótulo), que o Mapa PA usa para a ordem do reservado; a Escala e os relatórios não ganham o campo | O Mapa PA lê papel e presença do mesmo cálculo (uma fonte só), sem campo novo nas outras respostas |
| D-F10-45 | A D-F10-36 sai: o Mapa PA não lê papel nem corrige o dia do motor | Pedido do responsável: uma fonte só |
| D-F10-46 | O cálculo antigo (antes da data de corte) não ganha o papel | Ele não muda desde o F5; os papéis do 9.16 existem desde o deploy 4 |
| D-F10-47 | A fumaça do deploy passa a ler o `calendar.php` e o `reports.php` (seis GET) | São as duas leituras novas do papel que a fumaça não cobria |

**Validação:**
- **Mutação do commit 1** no worktree, com a trava `mut_guard.php`: 21/21 mortos. Entre eles: o papel ignorado no motor, os papéis nunca lidos, o papel do 1º dia em todos os dias, a origem `role` perdida, o papel em todas as respostas, o `mapPresence` sem o papel, a ordem do papel trocada pelo ID, o papel antes da exceção, a ausência sem vencer o papel, o papel remoto virando presencial, o reservado com remoto e a correção local de volta. Na 1ª rodada, 18/19: o desempate pelo ID do papel (dois papéis com a mesma ordem) não era testado; caso novo no mesmo commit, refeito antes de qualquer envio.
- **Integração no MySQL 8.0.46 local** (`qa_f103i`, dados fictícios, transação desfeita): 26/26, com as 18 do F10-3 e oito novas. Dois cadastros fictícios do Crítico, um com papel presencial e regra remota e outro com papel remoto e regra presencial, aparecem iguais na Escala (origem `role`), no Calendário, nos relatórios, no `mapPresence` e na Visão operacional (10:00). No dia seguinte, a exceção remota vence o papel presencial; o `mapPresence` leva o papel. A base de ensaio tinha uma exceção de um dos cadastros no período; o script a retira na transação.
- **Mutação do commit 3** (`9c4002f`) no worktree: 15/15 mortos (a fumaça sem o Calendário ou sem os relatórios, o título e o que vai sem o 8.8, a D-F10-36 sem a retirada, o esperado com quatro GET ou sem o `reports.php`, o passo 12 e o PARE sem a mesma pessoa nas telas, o aviso sem o 8.8, a folha sem os seis GET, sem a conferência, com um e-mail ou com um comando a mais, e o roteiro com uma migration nova). Na 1ª rodada, 3 mutantes não se aplicaram (erro do script de mutação: `\n` entre aspas simples e um texto que aparece duas vezes); corrigidos, 3/3.
- **Trava do roteiro:** 514 verificações (513 + a do 8.8 no deploy 5). `qa-pa-conflicts`: 144 verificações (132 + 12 da seção 7).
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree do último commit): no resumo da entrega e no estado da sessão.
