# Horas extras e exportações — Lote 6 (BIZ-01, BIZ-04, PERF-02)

**Data:** 2026-09-30
**Situação:** aprovado em 2026-10-01 (`a68066a`); complemento de status e índices (`f4acb5a`) aprovado em 2026-10-02; recusa da aprovação de pendente de 24 h aprovada em 2026-10-02 e feita em commit separado (seção 5). Deploy 5 da fila.

---

## 1. Decisões do negócio aplicadas

| Decisão | Como ficou |
|---|---|
| Competência de 16 de um mês a 15 do seguinte | Confirmada e mantida (`OperationalService::competencyRange`). Tela, lista, totais e CSV usam o período do filtro, que por padrão é a competência corrente |
| Total por colaborador em duas colunas: aprovado e pendente | `Total aprovado` e `Total pendente` no CSV, na tela de horas extras e no relatório operacional |
| Rejeitado nunca entra em total | Fica fora dos dois totais em todos os pontos; no CSV, a linha continua listada e identificada na coluna `Status` |
| Sem teto por lançamento | Nenhum teto foi criado |
| Recusar início igual ao fim | Recusado no servidor e na tela, com mensagem que explica a virada de dia. Pendente antigo nessa situação não pode ser aprovado, só rejeitado (decisão de 2026-10-02) |
| Virada de dia com fim menor que início | Continua valendo (22:00 às 02:00 = 4 h) |
| Exportações não truncam | Todas as linhas do filtro, lidas em páginas |
| Listas da tela avisam quando cortam | Aviso "Exibindo os N registros mais recentes" |

## 2. Competência: confirmação

- Para qualquer data, `competencyRange()` devolve o período de 16 a 15 que a contém (testado na seção 7).
- A tela de horas extras já abria com a competência corrente e envia `from`/`to`. O CSV usa os mesmos filtros da tela.
- **Correção:** quando a API recebia horas extras ou correção de ponto **sem** período, usava o mês calendário (1 a 30). Agora usa a competência corrente. As demais telas não mudam.
- Os totais da tela e do CSV cobrem o período inteiro do filtro, e não mais só as 500 linhas exibidas.

## 3. Totais

Regra única em `OperationalService::overtimeTotals()`:

| Status | Total aprovado | Total pendente |
|---|---|---|
| `approved` | soma | — |
| `pending` | — | soma |
| `rejected` | — | — |
| `synced`, `sync_error` | — | — |

`synced` e `sync_error` existem no schema, mas nenhum código grava esses status hoje (a integração com SharePoint está inativa). Ficam fora dos totais até haver decisão. A consulta da seção 8 confirma se há alguma linha assim.

**Dívida técnica:** `synced` = aprovado e sincronizado; reavaliar a regra de totais se a integração com o SharePoint voltar. Até lá, essas linhas ficam fora dos dois totais, mas aparecem no CSV com o nome do status (seção 4).

Os totais ignoram o filtro de status da tela: filtrar "Pendente" não zera o total aprovado, porque o total já separa os status.

## 4. CSV de horas extras

| # | Antes | Agora |
|---|---|---|
| 1–7 | NC, Nome completo, Data da realizacao, Hora de entrada, Hora de saida, Descricao, Total de Horas | **Iguais** |
| 8 | `Total Realizado` — soma de **todos** os status do colaborador, inclusive rejeitados, e só das até 500 linhas exportadas | `Total aprovado` — do colaborador no filtro inteiro |
| 9 | — | `Total pendente` — do colaborador no filtro inteiro |
| 10 | — | `Status` — Aprovado, Pendente, Rejeitado; `synced` sai como Sincronizado e `sync_error` como Erro de sincronizacao. Nunca em branco: status vazio sai como Sem status |

**Atenção para quem consome a planilha (folha de pagamento):** a coluna 8 mudou de nome e de conteúdo, e há duas colunas novas no fim. Antes, o total vinha inflado sempre que havia lançamento pendente ou rejeitado no período, e o arquivo parava em 500 linhas sem aviso.

O CSV de correção de ponto não tinha totais: só ganhou a exportação completa.

## 5. Início igual ao fim

`OperationalService::overtimeMinutes()` substitui o cálculo anterior:

- entrada igual à saída: **recusado**, com a mensagem "Hora de entrada igual a hora de saida. Informe o horario real de saida; se a hora extra passou da meia-noite, a saida fica menor que a entrada." Antes, virava 1.440 minutos (24 h) e ia para aprovação;
- saída menor que a entrada: virada de dia;
- menos de um minuto: recusado, como antes;
- conta em segundos do dia, sem `DateTime` e sem fuso, o que também resolve a fragilidade apontada no BIZ-06.

A tela mostra o aviso no quadro "Total calculado" e não envia o formulário.

**Registros antigos com entrada igual à saída continuam no banco com 24 h.** O lote não altera dados. A seção 8 traz a consulta para encontrá-los.

**Aprovação de pendente antigo com entrada igual à saída (aprovado em 2026-10-02).** Antes, `decideWorkflow()` só conferia se o registro estava pendente, se não era do próprio aprovador e se a decisão era válida; aprovar um pendente antigo de 24 h somava 1.440 minutos no total aprovado. Agora:

- **aprovar** hora extra pendente com entrada igual à saída é recusado com 409 e a mensagem "Lancamento com hora de entrada igual a hora de saida, contado como 24 h. Nao e possivel aprova-lo: rejeite e peca ao colaborador para lancar de novo com o horario real de saida (se passou da meia-noite, a saida fica menor que a entrada)." Nada é gravado e nada vai para a fila de sincronização;
- **rejeitar** esse lançamento continua permitido;
- lançamentos já aprovados (ou em qualquer status diferente de pendente) não mudam, e nenhum dado é alterado;
- a regra olha os horários (`start_time` igual a `end_time`, com `HH:MM` e `HH:MM:SS` equivalentes), não o total gravado; correção de ponto não é afetada.

Não há edição de lançamento no sistema: corrigir significa rejeitar e lançar de novo. Implementação: `OperationalService::assertOvertimeApprovable()`, chamada em `decideWorkflow()` depois das checagens de status e de autoria, antes do `UPDATE`. A tela de Aprovações não mudou: o botão continua ativo e o erro aparece na notificação, com a mensagem do servidor (verificado por leitura do `actionRunner`, não no navegador).

## 6. PERF-02

### Exportações, sem truncar

| Exportação | Antes | Agora |
|---|---|---|
| Horas extras (CSV) | Até 500 linhas, sem aviso | Todas, em páginas de 500 |
| Correção de ponto (CSV) | Até 500 linhas, sem aviso | Todas, em páginas de 500 |
| Chamados críticos (CSV) | Até 500 linhas, sem aviso | Todas, em páginas de 500 |
| Pausas (ZIP de `download_relatorio.php`) | Até `METRICS_MAX_ROWS` (padrão 10.000), sem aviso | Todas, em páginas de 1.000, gravadas linha a linha; as métricas agregadas do ZIP passam a cobrir todas as pausas |
| Relatório operacional (CSV) | Já não truncava | Sem mudança |

As páginas são por chave (data, id), com `db_keyset_iterate()` em `db.php`: linhas com a mesma data não se repetem nem se perdem entre páginas, e a memória não cresce com o tamanho do período. Se a leitura das pausas falhar no meio, o ZIP não é gerado, em vez de sair incompleto.

### Listas da tela, com aviso

| Tela | Limite |
|---|---|
| Horas extras e Correção de ponto | 500 |
| Aprovações (horas extras e ponto pendentes) | 500 cada |
| Chamados críticos | 500 |
| Documentação | 300 |
| Escalas de Sábado | 200 |
| Ausências e Avisos (lista genérica) | 100 |
| Métricas — solicitações de reunião | 500 |

A API busca uma linha a mais que o limite só para saber se há outras e devolve `truncated`/`limit`. A tela de Métricas já tinha aviso para as pausas; ganhou o das solicitações de reunião.

## 7. Testes — `php scripts/qa-overtime.php`

Sem banco:

- minutos: período simples, virada de dia, 1 minuto, 23:59, entrada igual à saída (com e sem segundos), menos de um minuto, horário inválido;
- totais: aprovado, pendente, rejeitado de 24 h fora, colaborador só com rejeitado, status sem gravador, entrada por gerador;
- linha do CSV: cabeçalho, colunas, totais do colaborador, status;
- competência: dias 5, 15, 16, virada de ano, janeiro e fevereiro; padrão da API sem período;
- lista: corte com 501 linhas e sem corte com 500;
- exportação: 0, 1, 499, 500, 501, 1.000 e 1.203 linhas com muitas datas repetidas — todas, uma vez, na ordem, com o número esperado de páginas;
- estrutura: nenhuma exportação volta a usar a lista limitada;
- aprovação de pendente de 24 h (PDO simulado, chamando `decideOvertime()` e `decideTimeAdjustment()`): aprovar é recusado sem gravar nem sincronizar; rejeitar grava; `HH:MM` igual a `HH:MM:SS` é recusado; regra pelo horário, não pelo total; virada de dia e 1 minuto continuam aprováveis; já aprovado não muda; correção de ponto não é afetada.

Mutação: 11 defeitos introduzidos, 11 detectados. Recusa de 24 h (2026-10-02): 8 defeitos, 8 detectados (checagem removida, aplicada também à rejeição ou a qualquer tabela, sem normalizar `HH:MM`, comparação invertida, regra pelo total de 1.440, mensagem sem orientação, checagem depois do `UPDATE`). O defeito "regra pelo total" só foi detectado depois de um teste a mais: com dados reais é quase equivalente, porque horários diferentes dão no máximo 1.439 minutos.

**Não testado sem banco:** as consultas SQL de paginação (a condição de cursor foi testada sobre uma tabela em memória que reproduz a mesma regra) e a separação aprovado/pendente dentro de `report()`, que lê do banco.

## 8. Consultas para o responsável rodar no servidor

Só contagens.

```sql
SET time_zone = 'America/Sao_Paulo';

-- Status existentes: confirma que nao ha 'synced' nem 'sync_error'
SELECT 'horas_extras' AS tabela, status, COUNT(*) FROM portal_overtime_entries GROUP BY status
UNION ALL
SELECT 'correcao_ponto', status, COUNT(*) FROM portal_time_adjustments GROUP BY status;

-- Lancamentos antigos com entrada igual a saida (gravados como 24 h)
SELECT status, COUNT(*) AS lancamentos, SUM(total_minutes) AS minutos
FROM portal_overtime_entries
WHERE start_time = end_time
GROUP BY status;

-- Volumes que passavam do limite antigo de exportacao
SELECT 'horas_extras' AS tabela, COUNT(*) FROM portal_overtime_entries
UNION ALL SELECT 'correcao_ponto', COUNT(*) FROM portal_time_adjustments
UNION ALL SELECT 'chamados_criticos', COUNT(*) FROM portal_critical_incidents
UNION ALL SELECT 'pausas', COUNT(*) FROM pausas;
```

Se a segunda consulta trouxer linhas, a correção é decisão do negócio: o lote não mexe em lançamentos existentes.

### Índices das consultas paginadas (data, id)

No InnoDB, todo índice secundário termina com a chave primária (`id`). Assim, um índice só em `data` já vale como (`data`, `id`) e atende a ordem e o cursor sem ordenação extra; um índice (`data`, `outra_coluna`) atende o intervalo de datas, mas não a ordem por `id` dentro da mesma data.

| Exportação | Filtro e ordem | Índice que atende | Situação |
|---|---|---|---|
| Pausas (ZIP) | Tabela inteira; `ORDER BY inicio_pausa DESC, id DESC` | `idx_data (inicio_pausa)` = (`inicio_pausa`, `id`) | **Completo**: leitura do índice de trás para a frente, sem ordenação extra |
| Horas extras, por colaborador (inclui técnico e somente leitura, que veem só os próprios) | `employee_id = ?` e período; ordem (`work_date`, `id`) | `idx_overtime_employee (employee_id, work_date)` | **Completo** |
| Horas extras, por status | `status = ?` e período | `idx_overtime_status (status, work_date)` | **Completo** |
| Horas extras, só período ou período + equipe | `work_date BETWEEN` (no máximo 366 dias) | `idx_overtime_competency (work_date, team)` | **Parcial**: o índice limita as linhas ao período; a ordem por `id` dentro da mesma data exige ordenação em memória a cada página |
| Totais da tela e do CSV de horas extras | Igual às linhas acima, sem o status | Os mesmos | Igual às linhas acima |
| Correção de ponto | Mesmo desenho, sobre `adjustment_date` | `idx_adjustment_employee`, `idx_adjustment_status`, `idx_adjustment_competency` | Igual a horas extras |
| Chamados críticos | `COALESCE(room_date, DATE(opened_at)) BETWEEN`; ordem (`opened_at`, `id`) | Nenhum para o período: a expressão com `COALESCE` impede o uso de índice. O cursor pode usar `idx_critical_incident_period (opened_at, status, severity)` | **Sem índice para o filtro**: cada página percorre os chamados anteriores ao cursor e ordena em memória |

**Avaliação: não vale migration agora.** Nos casos parciais, o custo de cada página é ler e ordenar as linhas do período filtrado. Com o volume esperado (até alguns milhares de lançamentos por competência e algumas centenas de chamados críticos), a estimativa é de poucos milissegundos a dezenas de milissegundos por página (não medido), e a exportação é manual e eventual. Um índice novo (`work_date`, `id`) ou uma coluna gerada para o período dos chamados críticos só se paga com dezenas de milhares de linhas no período. Critério para reavaliar: a terceira consulta acima passar de **50.000** linhas em horas extras ou correção de ponto, ou de **10.000** em chamados críticos, ou o `EXPLAIN` abaixo mostrar `rows` nessa ordem. Se for o caso, a migration usa número fora da faixa 011–014 (reservada para a Parte B) e só é criada com aprovação.

Análise feita pela definição dos índices nas migrations; **não houve `EXPLAIN` em banco real**. Para confirmar no servidor (só o plano, sem dados):

```sql
EXPLAIN SELECT id FROM portal_overtime_entries
WHERE work_date BETWEEN '2026-09-16' AND '2026-10-15'
ORDER BY work_date DESC, id DESC LIMIT 500;

EXPLAIN SELECT id FROM portal_critical_incidents
WHERE COALESCE(room_date, DATE(opened_at)) BETWEEN '2026-09-16' AND '2026-10-15'
ORDER BY opened_at DESC, id DESC LIMIT 500;

EXPLAIN SELECT id FROM pausas ORDER BY inicio_pausa DESC, id DESC LIMIT 1000;
```

Esperado: a primeira com `key = idx_overtime_competency` e `Using filesort`; a segunda com varredura (`type = ALL` ou índice em `opened_at`) e `Using where`; a terceira com `key = idx_data` e sem `filesort`.

## 9. Rollback

Só código; nenhuma migration nem alteração de dados. Voltar ao commit anterior restaura a coluna `Total Realizado`, o limite de 500 linhas e a aceitação de entrada igual à saída. Reverter só o commit da recusa de 24 h volta a permitir a aprovação desses pendentes.
