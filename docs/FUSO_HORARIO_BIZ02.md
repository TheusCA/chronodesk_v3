# Fuso horário da sessão MySQL (BIZ-02)

**Lote:** A4 — fuso horário
**Data:** 2026-09-30
**Situação:** código e scripts em commit local (`9957496`), **aprovados em 2026-09-30, aguardando deploy** (quarto da fila). Nada foi executado no servidor. Os scripts SQL **não foram executados em MySQL** (não há banco no ambiente de desenvolvimento): a primeira execução é o ensaio da seção 7.3.

---

## 1. Diagnóstico confirmado no servidor

| Verificação (rodada pelo responsável) | Resultado |
|---|---|
| `@@global.time_zone` / `@@system_time_zone` | `SYSTEM` / `UTC` |
| `pausas`: `fim_pausa` (gravado pelo PHP) × `data_registro` (gravado pelo MySQL) | 180 minutos de diferença |
| `mysql.time_zone_name` | 1795 linhas: fuso nomeado disponível |
| Jornadas cadastradas | Há técnicos de 15:30 a 23:59 |

O PHP trabalha em `America/Sao_Paulo` (`config.php:53`) e a sessão MySQL, em UTC. Tudo o que o MySQL calcula com o próprio relógio sai 3 horas adiante, e das 21:00 às 00:00 `CURRENT_DATE` já é o dia seguinte. Para quem trabalha de 15:30 a 23:59, isso cobre as três últimas horas de **todo** turno.

Efeitos no código atual:

| Onde | Evidência | Efeito |
|---|---|---|
| Remover escala | `services/OperationalService.php:376` | Das 21:00 às 00:00, a escala é encerrada **no próprio dia** em vez da véspera |
| Aprovar hora extra e correção de ponto | `services/OperationalService.php:1007` | `approved_at` 3 h adiante; depois das 21:00, com a data do dia seguinte. Vai para o CSV de correção de ponto |
| Decidir pausa de reunião | `services/ApprovalRequestService.php:38` | `decided_at` 3 h adiante |
| Trocar status de chamado crítico | `services/CriticalIncidentService.php:238-254` | Horas preenchidas automaticamente 3 h adiante; `room_date` no dia seguinte; **tempo de sala inflado em 180 minutos** quando a abertura foi digitada e a normalização, automática |
| Remover vínculo de PA | `services/PaMapService.php:177` | `deleted_at` 3 h adiante |
| Marcar notificação como lida | `services/PortalService.php:143` | `read_at` 3 h adiante |
| Auditoria | `security.php:410`; `database_SECURED.sql:80` | `audit_log.timestamp` 3 h adiante em **todos** os eventos |
| Tudo o que é `TIMESTAMP` | Seção 3 | Gravado certo, **exibido** 3 h adiante (datas de criação, atualização, upload, último login) |

---

## 2. O que mudou no código

| Arquivo | Mudança |
|---|---|
| `db.php` | Nova `db_connect()`: abre a conexão com `SET time_zone` em `PDO::MYSQL_ATTR_INIT_COMMAND`. `get_db_connection()` passa a usá-la |
| `api/health.php` | Usa `db_connect()`, mantendo o timeout de 2 s. A sondagem passa a testar a mesma abertura da aplicação |
| `scripts/qa-timezone.php` | Novo. Testes da abertura e regressão em 23:30 e 00:30 (seção 8) |
| `scripts/test-local.ps1`, `scripts/test-linux.sh` | Incluem o `qa-timezone.php` |
| `migrations/correcao_dados/biz02_0{1..4}_*.sql` | Novos. Correção histórica (seção 7). **Não são migrations de deploy** |

Comportamento de `db_connect()`:

- **Fuso:** o nome vem de `date_default_timezone_get()` e o deslocamento de reserva, de `date('P')`. Hoje são `America/Sao_Paulo` e `-03:00`. Derivar do PHP impede que os dois lados voltem a divergir.
- **Fallback:** se o MySQL recusar o fuso nomeado (erro 1298, tabelas de fuso não carregadas), abre de novo com `-03:00` e registra `[DB] Fuso nomeado indisponível...` no log de erros. No servidor atual isso não deve acontecer.
- **Falha fechada:** se nenhum dos dois for aceito, a conexão não abre. Nunca existe conexão em uso com o fuso errado.
- **Sem segunda tentativa para outros erros:** banco fora do ar ou senha errada falham uma vez só, como antes.
- O fuso entra no texto do comando (o comando inicial não aceita parâmetro), então é validado contra um formato fechado antes de chegar ao banco.

**Backup (`scripts/backup-chronodesk.sh`): não se aplica, sem alteração.** O `mysqldump` usa `--tz-utc` por padrão: grava `SET TIME_ZONE='+00:00'` no dump, exporta `TIMESTAMP` em UTC e o restaura convertendo de volta. `DATETIME` é copiado literalmente. O dump é fiel com qualquer fuso de sessão. **Não acrescentar `--skip-tz-utc`.**

Nenhuma consulta foi alterada. `NOW()`, `CURRENT_DATE` e `CURRENT_TIMESTAMP` continuam onde estavam e passam a devolver a hora do PHP, porque a sessão mudou.

---

## 3. `TIMESTAMP` × `DATETIME`

| | `TIMESTAMP` | `DATETIME` |
|---|---|---|
| O que guarda | Um **instante** (internamente em UTC) | Um **texto de data e hora**, sem fuso |
| Na gravação | Converte do fuso da sessão para UTC | Grava o que recebeu |
| Na leitura | Converte de UTC para o fuso da sessão | Devolve o que está gravado |
| Com a sessão em UTC | Instante gravado **certo**, exibido 3 h adiante | Depende de quem forneceu o valor |
| Depois da correção da sessão | **Corrige sozinho**, inclusive o histórico | O que já está gravado **não muda** |

Um `DATETIME` é tão certo quanto quem o escreveu:

- **Valor formatado pelo PHP** (`date()`, `->format()`): já está em horário de Brasília. Sempre esteve certo.
- **Valor calculado pelo MySQL** (`NOW()`, `CURRENT_TIMESTAMP`, `DEFAULT CURRENT_TIMESTAMP`) com a sessão em UTC: ficou gravado o texto da hora UTC. Depois do deploy as linhas novas saem certas; **as antigas continuam 3 h adiante** até a correção histórica.

Uma coluna `TIMESTAMP` que recebesse um valor formatado pelo PHP teria o problema inverso. **Não há nenhuma:** todas as colunas `TIMESTAMP` do schema são preenchidas só pelo MySQL (padrão da coluna, `ON UPDATE`, ou `usuarios.last_login = NOW()` em `classes/Usuario.php:165`).

---

## 4. Inventário das colunas de data e hora

Levantado em `database_SECURED.sql`, `migrations/` e em todos os `INSERT`/`UPDATE` do PHP. Colunas `TIME` e colunas `DATE` informadas pelo usuário não dependem de fuso e não estão listadas.

### 4.1 Precisam de correção histórica (script 3)

| Coluna | Quem grava | Como o corte identifica a linha |
|---|---|---|
| `audit_log.timestamp` | `DEFAULT CURRENT_TIMESTAMP` (`security.php:410`) | Todas as linhas |
| `portal_notification_reads.read_at` | `CURRENT_TIMESTAMP` (`PortalService.php:143`) | Todas as linhas |
| `pause_approval_requests.decided_at` | `CURRENT_TIMESTAMP` (`ApprovalRequestService.php:38`) | Todas as preenchidas |
| `portal_overtime_entries.approved_at` | `NOW()` (`OperationalService.php:1007`) | Valor exatamente 3 h adiante do `updated_at` |
| `portal_time_adjustments.approved_at` | `NOW()` (`OperationalService.php:1007`) | Idem |
| `portal_pa_assignments.deleted_at` | `CURRENT_TIMESTAMP` (`PaMapService.php:177`) | Idem |
| `portal_pa_map.deleted_at` | Sem gravador no código atual (tabela legada) | Idem |

### 4.2 Correção parcial e revisão manual

| Coluna | Situação |
|---|---|
| `portal_critical_incidents.war_room_started_at`, `room_opened_at`, `mitigated_at`, `resolved_at`, `normalized_at` | **Origem mista na mesma coluna:** hora digitada na tela ou importada (PHP, certa) e hora preenchida na troca de status (`CURRENT_TIMESTAMP`, `CriticalIncidentService.php:238-251`, em UTC). O script 3 corrige só o que tem a assinatura da troca de status — valor exatamente 3 h adiante do `updated_at`. Se o chamado foi editado depois, a assinatura se perdeu: o script 2 lista os ids suspeitos (V8) para conferência na tela |
| `portal_critical_incidents.room_duration_minutes` | Calculado pelo MySQL misturando hora digitada com hora em UTC (`CriticalIncidentService.php:244-247`, `252-255`): **180 minutos a mais**. Não é recalculado por script. O script 2 lista os casos (V9) |
| `portal_schedule_rules.effective_until` | `DATE`, gravado com `CURRENT_DATE` (`OperationalService.php:376`). Errado em um dia só para escalas removidas entre 21:00 e 00:00. O script 2 conta os casos (V10); a correção é manual, caso a caso |
| `portal_critical_incidents.room_date` | `DATE`, preenchido com `CURRENT_DATE` só quando estava vazio (`CriticalIncidentService.php:240`). A criação sempre grava a data pelo PHP, então o caso é raro. Sem detecção automática |

### 4.3 `DATETIME` gravado pelo PHP — sempre esteve certo

`pausas.inicio_pausa` e `fim_pausa` (`classes/GerenciadorPausas.php:226-227`); `pause_approval_requests.requested_at` (`ApprovalRequestService.php:20-22`); `portal_calendar_events.starts_at` e `ends_at` (`OperationalService.php:272-273`); `portal_critical_incidents.opened_at`, `incident_opened_at` e `operation_reported_at`.

### 4.4 `DATETIME` sem gravador

`portal_notifications.read_at`, `portal_integrations.last_checked_at`, `portal_schedules.starts_at`/`ends_at`, `portal_sync_queue.next_attempt_at`/`last_attempt_at`/`synced_at` (só recebem `NULL`, `SharePointSyncService.php:25-26`). Nada a corrigir.

### 4.5 `TIMESTAMP` — corrigem sozinhos com o deploy

`funcionarios.criado_em`, `atualizado_em`; `pausas.data_registro`; `usuarios.created_at`, `last_login`; `created_at` e `updated_at` das tabelas `portal_*`; `pause_approval_requests.created_at`; `uploaded_at` de `portal_document_files` e `portal_shift_attachments`.

---

## 5. O que muda nas telas logo após o deploy

Sem nenhuma alteração de dados:

- Toda data de criação, atualização, upload e último login passa a aparecer **3 horas mais cedo** do que aparecia. É a hora certa.
- O filtro de documentos por data de upload (`services/DocumentService.php:112`) passa a usar o dia de Brasília.
- Aprovações, decisões, remoções e eventos de auditoria **novos** saem na hora certa.
- Os registros **antigos** das colunas da seção 4.1 continuam 3 h adiante até o script 3. Nesse intervalo o `audit_log` fica misto: a fotografia do corte é o que distingue os dois grupos.

---

## 6. Deploy deste lote

Acrescenta três passos à rotina "Atualizacao de Versao e Rollback" do `DEPLOY_LINUX.md`. O corte **precisa** ser registrado na mesma janela em que o código entra.

```bash
cd /var/www/chronodesk
C=<NOME_CONTAINER_MYSQL>

# 1. Parar a escrita (modo de manutencao) e aguardar as requisicoes em andamento.
sudo touch /var/www/chronodesk.maintenance && sleep 10

# 2. Rotina normal, itens 1 a 4: ponto de rollback, pre-checagem, backup (se ja
#    instalado) e git pull.

# 3. Registrar o corte. ANTES da recarga do PHP-FPM e de qualquer validacao.
sudo cat migrations/correcao_dados/biz02_01_registrar_corte.sql | docker exec -i "$C" sh -c 'umask 077; cat > /tmp/biz02.sql'
docker exec -it "$C" sh -c 'mysql -uroot -p sistema_pausas < /tmp/biz02.sql'
docker exec "$C" rm -f /tmp/biz02.sql
```

Esperado: `CORTE REGISTRADO`, com as três guardas em zero e a contagem por coluna. Se sair `ABORTADO PELAS GUARDAS`, **não siga**: desfaça o `git pull` (rollback de código) e me envie a saída.

```bash
# 4. Rotina normal, item 5: build do frontend e recarga de Apache e PHP-FPM.

# 5. Validar ainda em manutencao, pela propria VM.
sudo -u www-data php scripts/qa-timezone.php --db
curl -s -H 'Host: chronodesk.interno.local' http://127.0.0.1/api/health.php
```

Esperado: `sessao MySQL em America/Sao_Paulo`, `QA timezone (banco real) OK` e `{"status":"ok","database":"ok"}`. O `--db` só lê: `SELECT` de expressões e `SET` de variáveis de sessão.

```bash
# 6. Rotina normal, item 6, e sair da manutencao.
sudo rm -f /var/www/chronodesk.maintenance
```

Depois de reabrir, confira em uma tela qualquer que uma data de atualização recente bate com o relógio.

**Se este commit for ao ar antes do modo de manutenção** (que entra no segundo deploy da fila), não há como parar só o ChronoDesk. Faça fora do horário de uso, depois de 00:00, e registre o corte imediatamente após o `git pull`.

---

## 7. Correção histórica

Pasta `migrations/correcao_dados/`. **Não são migrations de deploy**: não entram no passo de migrations da rotina de atualização.

| Script | Altera dados? | Função |
|---|---|---|
| `biz02_01_registrar_corte.sql` | Não (cria duas tabelas de manutenção) | Fotografa os valores a corrigir. Roda no deploy (seção 6) |
| `biz02_02_verificar.sql` | Não | Consultas de verificação, antes e depois |
| `biz02_03_corrigir.sql` | **Sim** | Subtrai 3 h das linhas fotografadas |
| `biz02_04_rollback.sql` | **Sim** | Devolve os valores fotografados |

### 7.1 Por que há um "corte"

Depois que o código corrigido entra, as linhas novas já são gravadas certas. Um `UPDATE ... - INTERVAL 3 HOUR` aplicado mais tarde sobre a tabela inteira deslocaria essas linhas também. Como a correção só vai rodar depois do backup instalado e testado, é preciso saber exatamente quais linhas são anteriores ao código novo.

O script 1 resolve isso copiando, no momento do deploy, a chave e o valor de cada linha a corrigir para `manutencao_biz02_datetime`. Essa fotografia serve para três coisas:

- **lista exata** do que corrigir, não importa quando o script 3 rode;
- **idempotência**: cada linha é marcada (`corrigido_em`) e só é alterada se ainda tiver o valor fotografado;
- **rollback**: o valor original fica guardado.

`manutencao_marcadores` registra o corte (`biz02_corte`) e a aplicação (`biz02_correcao`).

Guardas do script 1 — qualquer uma impede a fotografia:

| Guarda | Detecta |
|---|---|
| G1 | Linha já no formato novo: o código corrigido gravou dados antes do corte |
| G2 | Salto para trás de mais de 2h30 no `audit_log`, na ordem dos ids: o fuso de gravação mudou no meio do histórico |
| G3 | Fuso padrão do servidor MySQL diferente de UTC: a premissa das 3 h deixa de valer |

### 7.2 Pré-requisitos do script 3

1. Backup instalado e teste de restauração aprovado (`DEPLOY_LINUX.md`).
2. Corte registrado (seção 6).
3. Ensaio no banco de teste (7.3).
4. Backup feito imediatamente antes.

### 7.3 Ensaio no banco de teste de restauração

No banco `sistema_pausas_restore_test` do procedimento "Teste de Restauracao", restaurado de um backup **posterior** ao corte (ele traz a fotografia junto):

```bash
for s in biz02_02_verificar biz02_03_corrigir biz02_02_verificar biz02_04_rollback biz02_02_verificar; do
  echo "=== $s"
  sudo cat "migrations/correcao_dados/$s.sql" | docker exec -i "$C" sh -c 'umask 077; cat > /tmp/biz02.sql'
  docker exec -it "$C" sh -c 'mysql -uroot -p -t sistema_pausas_restore_test < /tmp/biz02.sql'
done
docker exec "$C" rm -f /tmp/biz02.sql
```

Se o backup restaurado for **anterior** ao corte, o banco de teste não tem a fotografia: rode antes o `biz02_01_registrar_corte` nele.

É a **primeira execução real** desses scripts. Um erro de sintaxe ou de collation aparece aqui, sem risco para a produção. Guarde as três saídas do script 2.

### 7.4 O que conferir no script 2

| Consulta | Antes do script 3 | Depois do script 3 | Depois do rollback |
|---|---|---|---|
| V2 `a_corrigir` | = `fotografadas` (menos as alteradas depois) | 0 | Igual a "antes" |
| V2 `corrigida` | 0 | = `a_corrigir` de antes | 0 |
| V3 `no_futuro` | Pode ser > 0 | 0 | Pode ser > 0 |
| V4 auditoria por hora | Volume entre 11h e 02h | Volume entre 08h e 23h | Igual a "antes" |
| V5 `decisao_antes_do_pedido` | 0 | **0** — se > 0, houve correção indevida | 0 |
| V5 `espera_media_min` | Inflada em ~180 | ~180 menor | Igual a "antes" |
| V6 `utc_3h_adiante` | > 0 | 0 | > 0 |
| V6 `outra` | Revisar se > 0 | Igual | Igual |
| V7 `fora_de_2_min` | 0 ou residual | Igual | Igual |
| V8, V9, V10 | Listas para revisão manual | V9 mostra os tempos de sala a corrigir na tela | — |

### 7.5 Aplicação em produção

Pode ser com o portal no ar: a correção mexe só em linhas antigas. Fora do horário de uso, mesmo assim.

```bash
sudo systemctl start chronodesk-backup.service && journalctl -u chronodesk-backup -n 5 --no-pager
# script 2 (guardar a saida) -> script 3 -> script 2 (guardar a saida), como em 7.3, no banco sistema_pausas
```

Esperado no script 3: `CORRECAO EXECUTADA` e `nao_corrigidas = 0` (ou só as linhas que a aplicação regravou depois do corte).

---

## 8. Testes

### 8.1 Local, sem banco — `php scripts/qa-timezone.php`

| O que prova | Como |
|---|---|
| O comando inicial define `America/Sao_Paulo` | Fábrica de PDO simulada |
| Erro 1298 leva ao `-03:00`, com aviso no log | Idem |
| Os dois recusados: a conexão não abre | Idem |
| Acesso negado, conexão recusada e banco inexistente não disparam segunda tentativa | Idem |
| Fuso fora do formato nunca chega ao banco | Idem |
| Só o `db.php` abre conexão; o health usa `db_connect()` | Varredura do código |
| Regressão em **23:30 e 00:30**, mais 20:59:59, 21:00:00, 23:59:59, virada de mês e de ano | **Modelo** da sessão MySQL, com controle em UTC |

A regressão local é um modelo: o MySQL devolve, para um instante, esse instante convertido para o fuso da sessão. O teste confere que os fusos enviados pelo `db.php` dão a data e a hora do PHP em cada instante, e que a sessão em UTC reproduz o defeito — às 23:30 o MySQL já estaria no dia seguinte e a escala removida seria encerrada hoje, não ontem. **Não prova que o servidor aceita o comando.**

Mutação: 10 defeitos introduzidos no `db.php` e no `api/health.php`, 9 detectados. O não detectado é trocar o número do atributo (1002): sem o `pdo_mysql` no ambiente local a constante não existe para comparar. No servidor, o próprio script compara com `PDO::MYSQL_ATTR_INIT_COMMAND`, e o modo `--db` prova o efeito.

### 8.2 No servidor, contra o banco real — `php scripts/qa-timezone.php --db`

Somente leitura. É aqui que o que o ambiente local não alcança fica provado:

- a sessão aberta pela aplicação está em `America/Sao_Paulo`;
- `NOW()`, `CURRENT_DATE` e a leitura de `TIMESTAMP` batem com o PHP;
- **relógio do MySQL simulado** com `SET timestamp` nos mesmos instantes, 23:30 e 00:30 incluídos;
- fuso nomeado inexistente cai de fato para `-03:00`, e sem fuso válido a conexão não abre. Isso confirma que o MySQL responde com o erro que o `db.php` reconhece.

Se o usuário da aplicação não puder usar `SET timestamp`, o script **falha** em vez de pular. Use então a 8.3.

### 8.3 Alternativa manual, como root no container

```sql
SET time_zone = 'America/Sao_Paulo';
SET timestamp = UNIX_TIMESTAMP('2026-10-05 23:30:00');
SELECT NOW(), CURRENT_DATE, DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY);
-- esperado: 2026-10-05 23:30:00 | 2026-10-05 | 2026-10-04
SET timestamp = UNIX_TIMESTAMP('2026-10-06 00:30:00');
SELECT NOW(), CURRENT_DATE, DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY);
-- esperado: 2026-10-06 00:30:00 | 2026-10-06 | 2026-10-05
SET timestamp = DEFAULT;
```

---

## 9. Rollback

| Situação | O que fazer |
|---|---|
| Código, antes do script 3 | Rollback de código normal. Depois, apagar o corte, que deixou de valer: `DROP TABLE manutencao_biz02_datetime; DELETE FROM manutencao_marcadores WHERE chave LIKE 'biz02%';`. No próximo deploy o corte é registrado de novo |
| Só a correção | Script 4. A fotografia e o corte ficam; o script 3 pode ser reaplicado |
| Código, depois do script 3 | Script 4 **e** rollback de código, para o histórico voltar a ser coerente com a sessão em UTC |
| Restauração de backup anterior ao corte, com o código novo no ar | Registrar o corte de novo (script 1) na janela de manutenção da restauração, antes de reabrir |

As linhas gravadas entre o deploy e um rollback de código ficam no fuso certo no meio de um histórico em UTC. Num redeploy, a guarda G2 do script 1 tende a disparar por causa delas: a correção passa a exigir análise manual.

---

## 10. Sessões manuais e migrations futuras

A correção vale para as conexões abertas pela aplicação (`db_connect()`). Uma sessão aberta à mão (`docker exec ... mysql`) continua no fuso padrão do container, que é **UTC**: nela, `NOW()`, `CURRENT_DATE` e `CURRENT_TIMESTAMP` saem 3 h adiante, e colunas `TIMESTAMP` aparecem 3 h adiante.

Regras, decididas em 2026-09-30:

1. **Toda migration aplicada manualmente começa com** `SET time_zone = 'America/Sao_Paulo';`. As migrations novas (011 a 014 da Parte B) trazem essa linha no topo do arquivo.
2. **Em sessão interativa ou consulta avulsa**, execute o mesmo `SET` antes de qualquer comando, ou abra o cliente com `--init-command="SET time_zone = 'America/Sao_Paulo'"`.
3. **Migrations novas não usam `NOW()` nem `CURRENT_TIMESTAMP` em `INSERT` ou `UPDATE` de dados.** Quando precisarem de data, recebem o valor explícito (`'2026-10-05 00:00:00'`, ou uma variável definida no topo do arquivo com esse valor). Assim o resultado não depende do fuso da sessão que aplicou o script. `DEFAULT CURRENT_TIMESTAMP` e `ON UPDATE CURRENT_TIMESTAMP` na definição de coluna continuam permitidos: são avaliados pela sessão da aplicação no momento da gravação.
4. **Exceção:** os scripts de `migrations/correcao_dados/` definem `-03:00` de propósito, porque o cálculo deles é de 3 h fixas e não pode depender das tabelas de fuso do MySQL.

As migrations 001 a 010 não usam relógio do MySQL em dados (só em `DEFAULT` de coluna): reaplicá-las numa sessão em UTC não gera valor errado.

---

## 11. Limites e pendências

- **Scripts SQL nunca executados.** Revisados por leitura. Escritos para falhar sem alterar dados: uma transação por script, guardas e comparação com o valor fotografado. O ensaio da 7.3 é obrigatório.
- **O caminho de fallback do `db.php` não foi exercitado contra MySQL real.** O reconhecimento do erro 1298 foi testado com exceção simulada. O `--db` confirma no servidor.
- **`portal_notification_reads` e `pause_approval_requests` não têm coluna `TIMESTAMP` irmã**, e o `audit_log` também não: para essas três a fotografia depende de o corte ser registrado na janela do deploy. As demais são identificadas por assinatura e não dependem disso.
- **Dados anteriores a 17/02/2019** teriam horário de verão e deslocamento de 2 h em parte do ano. O script 1 mostra a data mais antiga de cada coluna; se alguma for anterior, avise antes de corrigir. As tabelas `portal_*` são de 2026.
- **Revisão manual:** chamados críticos editados depois da troca de status (V8), tempo de sala (V9), escalas encerradas um dia adiante (V10).
- **A fotografia copia o login** de quem leu cada notificação (`linha_chave`) para a tabela de manutenção, no mesmo banco. Depois de validada a correção, as duas tabelas `manutencao_*` podem ser removidas; essa decisão fica com o responsável.
- O aviso de fallback é registrado a cada conexão. Se o fuso nomeado deixar de existir no MySQL, o log de erros cresce até alguém recarregar as tabelas de fuso. É proposital: a situação dobra o custo de cada conexão e não deve passar despercebida.
