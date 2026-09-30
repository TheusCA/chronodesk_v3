# Horas extras e exportações — Lote 6 (BIZ-01, BIZ-04, PERF-02)

**Data:** 2026-09-30
**Situação:** commit local, aguardando validação. Deploy 5 da fila.

---

## 1. Decisões do negócio aplicadas

| Decisão | Como ficou |
|---|---|
| Competência de 16 de um mês a 15 do seguinte | Confirmada e mantida (`OperationalService::competencyRange`). Tela, lista, totais e CSV usam o período do filtro, que por padrão é a competência corrente |
| Total por colaborador em duas colunas: aprovado e pendente | `Total aprovado` e `Total pendente` no CSV, na tela de horas extras e no relatório operacional |
| Rejeitado nunca entra em total | Fica fora dos dois totais em todos os pontos; no CSV, a linha continua listada e identificada na coluna `Status` |
| Sem teto por lançamento | Nenhum teto foi criado |
| Recusar início igual ao fim | Recusado no servidor e na tela, com mensagem que explica a virada de dia |
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

Os totais ignoram o filtro de status da tela: filtrar "Pendente" não zera o total aprovado, porque o total já separa os status.

## 4. CSV de horas extras

| # | Antes | Agora |
|---|---|---|
| 1–7 | NC, Nome completo, Data da realizacao, Hora de entrada, Hora de saida, Descricao, Total de Horas | **Iguais** |
| 8 | `Total Realizado` — soma de **todos** os status do colaborador, inclusive rejeitados, e só das até 500 linhas exportadas | `Total aprovado` — do colaborador no filtro inteiro |
| 9 | — | `Total pendente` — do colaborador no filtro inteiro |
| 10 | — | `Status` — Aprovado, Pendente, Rejeitado |

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
- estrutura: nenhuma exportação volta a usar a lista limitada.

Mutação: 11 defeitos introduzidos, 11 detectados.

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

## 9. Rollback

Só código; nenhuma migration nem alteração de dados. Voltar ao commit anterior restaura a coluna `Total Realizado`, o limite de 500 linhas e a aceitação de entrada igual à saída.
