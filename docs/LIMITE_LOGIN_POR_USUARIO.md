# Limite de login por usuário e testes negativos — Lote 7 (SEC-03, QA-01)

**Data:** 2026-10-01
**Situação:** `5f5a5c1` **aprovado em 2026-10-02 com um ajuste** (auditoria só com login cadastrado, seção 2), feito em commit separado. Vai no deploy 5 da fila, junto com o Lote 6.
**Decisão aplicada (2026-09-30):** limite de tentativas por usuário, independente de IP, persistido no MySQL, mantendo o limite por IP; limiar no `.env`, padrão de 5 falhas em 15 min, abaixo do bloqueio de conta do domínio (TO CONFIRM); a senha nunca é registrada.

---

## 1. Problema

Todos os limites de login eram por IP (`rate_limit_file_path()` junta o IP a toda chave). Tentativas contra a mesma conta vindas de IPs diferentes não tinham limite, e cada uma vira um bind no AD. Passando do limite do domínio, o AD **bloqueia a conta corporativa** da pessoa: Windows, e-mail e o resto.

## 2. Regra

| Item | Como ficou |
|---|---|
| O que conta | Toda tentativa que chega à autenticação, contada **antes** do bind. Só o login concluído zera a contagem |
| Quando bloqueia | Quando a conta já tem `LOGIN_USER_MAX_FAILURES` tentativas sem sucesso (padrão 5). A tentativa seguinte é recusada sem consultar o AD |
| Quando zera sozinha | Depois de `LOGIN_USER_WINDOW_SECONDS` sem nenhuma tentativa (padrão 900 s = 15 min), contados da última tentativa aceita. Tentativa recusada pelo bloqueio não prolonga o bloqueio |
| Chave | O sAMAccountName com que o bind é feito, calculado por `normalizar_login_ldap()`, a mesma função do bind. `joao.silva`, `JOAO.SILVA`, `joao.silva@dominio` e `joao.silva@` contam juntos |
| Login que não normaliza | Não tem contador: a mesma função recusa o login antes do bind, então não há tentativa no AD |
| Limite por IP | Continua igual, verificado antes. Os dois precisam passar |
| Resposta ao bloqueio | 429, com a mesma mensagem do limite por IP. A regra vale para qualquer login, cadastrado ou não, e por isso não revela se a conta existe |
| Banco indisponível | **Falha fechada**: 503 ("Não foi possível validar o login agora"), sem consultar o AD |
| Senha | Nunca é passada às funções do contador nem gravada. O teste confere que ela não aparece na resposta nem no log |

"Sem sucesso" inclui senha errada, AD fora do ar, usuário autenticado no AD mas sem perfil (403) e credencial de outro colaborador na pausa. É mais estrito que o AD, que só conta senha errada, e mais simples de explicar: "5 logins sem sucesso".

### Pontos de entrada

Os cinco lugares que chamam o AD passam pelo contador antes do bind e o zeram no login concluído:

| Arquivo | Contexto na auditoria |
|---|---|
| `api/login_ci.php` | `ci_login` |
| `api/login_admin.php` | `admin_api` |
| `admin_login.php` (formulário legado) | `admin_login` |
| `login.php` (formulário legado de métricas) | `metricas_login` |
| `auth_ldap.php`, `exigir_autenticacao_ci_pausa()` (reautenticação AD em iniciar, finalizar e solicitar pausa) | `ad_iniciar_pausa`, `ad_finalizar_pausa`, `ad_solicitar_pausa` |

Um teste estrutural lista os arquivos que chamam `autenticar_ad()` ou `autenticar_ci_via_ad()`. Um ponto de entrada novo faz o QA falhar até passar pelo contador.

### Eventos de auditoria

| Evento | Severidade | Detalhe gravado |
|---|---|---|
| `LOGIN_USER_THROTTLED` | WARNING | Login cadastrado: `contexto=<ctx> login=<login normalizado>`. Fora do cadastro: `contexto=<ctx> login_cadastrado=false`, **sem o texto digitado** |
| `LOGIN_THROTTLE_UNAVAILABLE` | WARNING | Só `contexto=<ctx>`: com o banco fora nem o cadastro pode ser consultado (o registro cai no `error_log`) |

O IP fica na coluna `user_ip` do `audit_log`, em todos os casos.

**Ajuste pedido na aprovação (2026-10-02).** O login normalizado só vai para o `audit_log` se existir no cadastro: `funcionarios.ad_login` ou `usuarios.username`, comparados como no login local (collation `utf8mb4_unicode_ci`, sem diferença de maiúsculas). Motivo: a normalização corta no "@", então uma senha digitada no campo de usuário, como `Senha@`, viraria `senha` e ficaria gravada para sempre. Erro na consulta do cadastro conta como "não cadastrado". A resposta HTTP é a mesma nos dois casos: `login_user_throttle_json_response()` depende só do resultado do contador. Os dois eventos são gravados só por `login_user_throttle_audit()`; um teste estrutural reprova outro ponto que os grave.

Fora do escopo, sem alteração: eventos anteriores ao Lote 7 (`ADMIN_LOGIN_FAILURE`, `CI_LOGIN_FAILURE`, `CI_AD_LOGIN_FAILURE`, `CI_LOGIN_RATE_LIMIT`) gravam o login digitado normalizado, cadastrado ou não. Têm o mesmo risco e ficam como pendência para decisão.

### Decisões da aprovação (2026-10-02)

- `SELECT ... FOR UPDATE` com gravação do total calculado no PHP, no lugar do `+1` direto: aceito, porque a decisão precisa do valor lido sob trava.
- Nome `LOGIN_USER_WINDOW_SECONDS`: mantido.
- `DELETE` autorizado somente na `login_user_throttle`: no login concluído, na limpeza das linhas vencidas e no desbloqueio manual da seção 7.
- Chave sem HMAC: aceita, com o risco abaixo.
- Auditoria só com login cadastrado: ajuste feito (acima).

### Chave em texto legível na tabela (decisão de 2026-10-02)

A condição original pedia HMAC-SHA256 do login. Foi retirada na aprovação: a linha é transitória (apagada no sucesso ou na limpeza depois da janela) e o desbloqueio manual (seção 7) precisa do login legível.

**Risco residual aceito:** um texto digitado errado no campo de usuário fica na tabela até o fim da janela (padrão 15 min), inclusive uma senha digitada no lugar do login, desde que ela passe pela normalização (por exemplo `Senha2026` ou `Senha@`; `Senha@2026` é recusado, porque `2026` não é sufixo permitido, e não chega à tabela). Quem lê essa tabela no banco vê esse texto enquanto a linha existir. No `audit_log`, que é permanente, o texto só entra se for um login cadastrado (acima).

## 3. Por que fica abaixo do bloqueio do AD

O AD zera o contador de senha errada da conta quando passa a janela de observação sem erro (`Reset account lockout counter after`). A contagem do ChronoDesk segue a mesma regra. Daí:

- se a janela do ChronoDesk for **maior ou igual** à do AD, o contador do AD nunca zera sem que o do ChronoDesk zere também;
- então, em qualquer momento, o AD não viu mais tentativas vindas do ChronoDesk do que o ChronoDesk contou, e o ChronoDesk para em `LOGIN_USER_MAX_FAILURES`;
- com o limite **menor** que o do domínio, o ChronoDesk sozinho nunca leva a conta ao bloqueio.

**TO CONFIRM com a equipe do AD:** limite de bloqueio e janela de observação do domínio. Se a janela do AD for maior que 15 min, aumentar `LOGIN_USER_WINDOW_SECONDS` para pelo menos o valor dela. Tentativas por outros meios (estação, e-mail, VPN) também contam no AD e ficam fora do alcance do ChronoDesk: a margem entre os dois limites é o que absorve isso.

## 4. Configuração

```
LOGIN_USER_MAX_FAILURES=5        # 1 a 20
LOGIN_USER_WINDOW_SECONDS=900    # 60 a 86400
```

Ausente: o padrão. Fora da faixa, não numérico ou com zero à esquerda: o padrão, com aviso no `error_log`. `scripts/preflight-deploy.sh` reprova esses mesmos casos (`FALHA`) antes do deploy, para a configuração pretendida não ser trocada em silêncio pelo padrão.

## 5. Implementação

`security.php`, seção SEC-03:

- `login_user_throttle_decide()`: regra pura, sem banco.
- `login_user_throttle_acquire()`: reserva a tentativa. Protocolo:
  1. `INSERT ... ON DUPLICATE KEY UPDATE login = login`, fora da transação: garante a linha. Travar linha inexistente pegaria trava de intervalo, e duas primeiras tentativas simultâneas terminariam em deadlock.
  2. Transação: `SELECT ... FOR UPDATE` na linha do login, decisão e, se permitido, gravação do novo total. Requisições simultâneas para a mesma conta esperam a trava e não passam do limite juntas.
  3. Depois do `COMMIT`, apaga as linhas vencidas. Elas já valiam zero; a limpeza só mantém a tabela pequena.
  Qualquer erro desfaz a transação e devolve `unavailable`. Comando que devolve `false` em vez de lançar exceção também vira falha.
- `login_user_throttle_release()`: apaga a linha no login concluído. Se falhar, o login segue e a contagem expira ao fim da janela.
- Datas gravadas como valor explícito, no fuso do PHP (regra do BIZ-02); a tabela não usa `NOW()`.

Tabela (`migrations/20261001_015_login_user_throttle.sql`):

| Coluna | Tipo | Uso |
|---|---|---|
| `login` | `VARCHAR(100)`, chave primária | sAMAccountName minúsculo |
| `failed_attempts` | `SMALLINT UNSIGNED` | Tentativas sem sucesso na janela atual |
| `last_attempt_at` | `DATETIME`, indexado | Última tentativa aceita; o índice atende a limpeza |

Numeração: 011 a 014 estão reservadas para a Parte B (`docs/DESENHO_PA_ESCALA_AUSENCIA.md`). A 015 não depende delas e pode ser aplicada antes.

**CSV (QA-01, SEC-11):** as três exportações tinham cada uma sua cópia do regex de neutralização de fórmula. Agora usam `csv_neutralize_cell()`, em `security.php`. A unificação revelou um defeito: com UTF-8 inválido, `preg_match(.../u)` devolve `false`, e `=HYPERLINK(...)` seguido de um byte inválido saía sem o apóstrofo. Nesse caso a checagem agora é refeita byte a byte.

## 6. Deploy

Ordem dentro de "Atualização de Versão e Rollback" (`DEPLOY_LINUX.md`):

1. Item 2: o preflight da versão nova mostra `LOGIN_USER_MAX_FAILURES` e `LOGIN_USER_WINDOW_SECONDS` (`OK (padrao ...)` se ausentes).
2. Item 3: backup.
3. **Antes do `git pull` do item 4**, aplicar a migration lida da versão nova:

```bash
cd /var/www/chronodesk
sudo git show origin/<BRANCH>:migrations/20261001_015_login_user_throttle.sql | docker exec -i <NOME_CONTAINER_MYSQL> mysql -uroot -p sistema_pausas
```

4. Conferir a tabela (o resultado deve ser `1`):

```bash
docker exec -i <NOME_CONTAINER_MYSQL> mysql -uroot -p sistema_pausas -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle';"
```

5. Item 4 em diante, como sempre. No QA pós-deploy, `qa-security.php` deve terminar com `OK`.
6. Teste manual: um login real com sucesso, e conferir que a tabela continua sem linha para esse login (o sucesso apaga a linha).

Sem a tabela, o código novo recusa **todo** login com 503: é o comportamento de falha fechada, e o motivo de a migration vir antes do código. O código anterior ignora a tabela.

## 7. Operação

Desbloquear uma conta antes do fim da janela (por exemplo, a pessoa errou a senha e já confirmou a nova):

```sql
SET time_zone = 'America/Sao_Paulo';
DELETE FROM login_user_throttle WHERE login = '<login>';
```

Contagem para acompanhamento, sem expor logins:

```sql
SET time_zone = 'America/Sao_Paulo';
SELECT COUNT(*) AS linhas,
       SUM(failed_attempts >= 5) AS contas_no_limite
FROM login_user_throttle
WHERE last_attempt_at >= NOW() - INTERVAL 15 MINUTE;
```

(Ajuste o `5` e o `15` se o `.env` usar outros valores.)

## 8. Rollback

1. Código: o rollback normal (`git checkout --detach` do commit anterior, build e recarga).
2. Tabela: opcional, e **somente depois** do código antigo no ar: `migrations/rollback/20261001_015_login_user_throttle_down.sql`. Ela só guarda contadores temporários; apagá-la não perde dado de negócio. Com o código do Lote 7 no ar e sem a tabela, todo login é recusado.
3. As variáveis `LOGIN_USER_*` podem ficar no `.env`: o código antigo não as lê.

## 9. Testes — `php scripts/qa-security.php`

Não acessa banco nem AD, nem no servidor: o QA aponta o banco para `127.0.0.1:1` e o AD para um host `.invalid`, e essas variáveis têm precedência sobre o `.env`. Também não toca os arquivos reais de estado: `init.php` grava em `pausas.csv` e `estado.json`, e o processo filho aponta esses caminhos para o diretório do QA. Para isso, `config.php` passou a definir as quatro constantes de caminho só quando ainda não existem; em produção ninguém as define antes, e o comportamento não muda. O QA compara os arquivos reais antes e depois.

| Bloco | O que prova |
|---|---|
| Configuração | Faixas, padrão, zero à esquerda (igual ao preflight) |
| Chave | Grafias da mesma conta dão a mesma chave; login sem chave não chega ao bind (comportamental, contra `autenticar_ad()`), com controle positivo |
| Regra da janela | Quinta tentativa passa, sexta bloqueia, último segundo da janela, janela vencida, tentativas espaçadas acumulam, relógio adiantado, dados inválidos |
| Protocolo SQL | Contra uma tabela simulada: ordem garantir, travar, gravar, confirmar, limpar; bloqueio não grava; liberação no sucesso; limpeza só de linhas vencidas; nenhuma senha nos parâmetros |
| Falha fechada | Erro ao garantir, travar ou gravar e `execute` devolvendo `false`: `unavailable` e transação desfeita |
| Pontos de entrada | Os cinco chamam o contador antes do AD e o zeram no sucesso; ponto novo reprova |
| Auditoria do bloqueio | Login cadastrado grava a forma normalizada; fora do cadastro (`Senha@`, `Senha2026`, login inexistente com sufixo), sem chave ou com erro na consulta, grava `login_cadastrado=false` e nem a forma normalizada aparece; resposta 429/503 depende só do resultado; os eventos só saem de `login_user_throttle_audit()`; com o contador indisponível, o log tem o contexto e não tem o login |
| CSV | `=`, `+`, `-`, `@`, espaço ou controle antes da fórmula, TAB, CR, UTF-8 inválido; a exportação operacional grava a célula neutralizada; nenhuma outra cópia do regex |
| Endpoints (QA-01) | Processo PHP filho com sessão, token CSRF e corpo JSON simulados, executando o arquivo real |

Endpoints exercitados:

| Caso | Esperado |
|---|---|
| `api/login_ci.php` sem token CSRF e com token inválido | 403, sem AD |
| `api/login_ci.php` e `api/login_admin.php` com senha vazia | 400, sem AD e sem tocar o contador |
| `admin_login.php` com senha vazia | Mensagem "Informe seu login e senha do AD.", sem AD |
| `api/login_ci.php`, `api/login_admin.php` e a reautenticação de `api/iniciar_pausa.php` com o contador indisponível | 503, sem AD |
| `admin_login.php` e `login.php` com o contador indisponível | Mensagem de indisponibilidade, sem AD |
| `api/portal/reports.php` sem sessão | 401 |
| `api/portal/reports.php` e `api/portal/documents_delete.php` com sessão de técnico | 403 |
| `api/portal/documents_delete.php` com sessão de gestor, sem token e com token inválido | 403 |
| Controle: o mesmo com token válido | Passa da checagem e responde 400 ("Documento invalido.") |

Em todos: a senha de teste não aparece na resposta nem no log, e não há erro de PHP. Nos casos de contador indisponível, o log tem `LOGIN_THROTTLE_UNAVAILABLE: contexto=` e não tem o login digitado.

**Mutação:** 26 defeitos introduzidos à mão, 26 detectados. Entre eles: retirar o contador de cada ponto de entrada, chamá-lo depois do AD, `<=` na janela, `>` no limite, falhar aberto, voltar à chave por `normalizar_samaccountname()`, ignorar `execute` falso, tirar o `FOR UPDATE`, o `ROLLBACK` ou a limpeza, desligar CSRF e RBAC, aceitar senha vazia, escrever a senha no log, tirar TAB, CR ou `@` do CSV, voltar a neutralização ao código anterior (o caso de UTF-8 inválido reprova) devolver a uma exportação sua cópia do regex e deixar o processo filho usar os arquivos reais de estado.

**Mutação do ajuste de auditoria (2026-10-02):** 12 defeitos, 12 detectados: cadastro sempre verdadeiro ou invertido, erro na consulta tratado como cadastrado, detalhe ignorando o cadastro, gravar o texto digitado ou a chave normalizada fora do cadastro, consultar `usuarios` com outra chave, gravar o login no evento de indisponível, tirar esse evento, `login.php` voltando ao `audit_log` com o login, `admin_login.php` sem auditoria e bloqueio da API respondendo 503. O último não era detectado antes: a resposta foi extraída para `login_user_throttle_json_response()` e ganhou teste.

## 10. Limites e riscos

- **Sem MySQL local.** O SQL do contador nunca rodou em MySQL: o protocolo foi testado contra uma tabela simulada que interpreta os cinco comandos. A trava entre requisições simultâneas foi verificada por leitura, não por execução. Fica provado no servidor pelo teste manual da seção 6, item 6. Recomendado também ensaiar a migration no banco de teste de restauração.
- **Bloqueio provocado por terceiros.** Quem souber um login pode bloqueá-lo **no ChronoDesk** por 15 min com 5 tentativas. É o custo de qualquer limite por conta, e é bem menor que o que ele evita: hoje as mesmas tentativas bloqueiam a conta no AD inteiro. O evento `LOGIN_USER_THROTTLED` mostra a origem (IP) na auditoria.
- **Password spray** (poucas tentativas em muitas contas) não é contido pelo limite por conta. Continua contido só pelo limite por IP, como antes.
- **AD fora do ar** também conta tentativas: quem insistir 5 vezes durante a queda espera a janela depois que o AD voltar.
- **Login local** (`ENABLE_LOCAL_ADMIN`) usa a mesma chave do AD para o mesmo nome; as tentativas somam.
- **Texto digitado na tabela** até o fim da janela, inclusive senha digitada no campo de usuário: risco aceito em 2026-10-02 (seção 2). A consulta de cadastro do `audit_log` também nunca rodou em MySQL real.
- `qa-security.php` usa `proc_open`. Se o PHP de linha de comando do servidor desabilitar essa função, o QA falha com "nao iniciou o processo filho": falha visível, não aprovação falsa.
