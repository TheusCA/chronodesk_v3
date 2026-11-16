# Portal SDK - Deploy Linux Interno

Este guia prepara o Portal SDK para deploy em VM Linux interna usando Apache + PHP na VM e MySQL em container Docker.

## Arquitetura Recomendada

- VM Linux interna na rede da empresa.
- Apache servindo o projeto PHP diretamente em `/var/www/chronodesk`.
- PHP 8 executado pelo modulo do Apache ou pela pilha padrao da distribuicao.
- MySQL rodando em container Docker ja provisionado na mesma VM ou em rede Docker acessivel.
- `DocumentRoot` apontando para `/var/www/chronodesk`.
- Arquivo `.env` criado manualmente no servidor, preferencialmente em `/var/www/.env`, um nivel acima do projeto.
- `.htaccess` mantido ativo com `AllowOverride All`.
- Logs do Apache separados para o VirtualHost do Portal SDK.
- Backups do banco e dos arquivos operacionais mantidos fora do `DocumentRoot`.

Apache e recomendado neste momento porque o projeto ja usa `.htaccess` para hardening, bloqueio de arquivos sensiveis, headers e regras de rewrite. Usar Apache no deploy inicial reduz a chance de divergencia entre desenvolvimento, seguranca ja implementada e producao.

Nginx nao e obrigatorio para uso interno. Ele pode ser adotado futuramente com PHP-FPM, mas isso exigira replicar no bloco `server` todas as regras hoje cobertas por `.htaccess`, incluindo bloqueio de `.env`, `.git`, SQL, Markdown, scripts operacionais, diretorios internos e headers de seguranca.

Mesmo em rede interna, mantenha HTTPS, headers de seguranca, controle de acesso, logs, auditoria e backups. Rede interna reduz exposicao, mas nao substitui hardening.

## Pacotes Necessarios

Exemplo para Debian/Ubuntu:

```bash
sudo apt update
sudo apt install -y apache2 php php-cli php-mysql php-ldap php-curl php-mbstring php-xml php-zip unzip git curl docker.io
```

Extensoes PHP recomendadas:

- `pdo_mysql`
- `ldap`
- `mbstring`
- `curl`
- `xml`
- `zip`
- `fileinfo`
- `json`
- `openssl`
- `session`

Modulos Apache necessarios:

- `rewrite`
- `headers`
- `ssl` quando HTTPS estiver habilitado

```bash
sudo a2enmod rewrite headers ssl
sudo systemctl reload apache2
```

## Estrutura de Arquivos

```text
/var/www/
  .env                         # recomendado, fora do DocumentRoot
  chronodesk/                  # clone do repositorio
    index.php
    .htaccess
    api/
    static/
    classes/
```

O loader atual procura `.env` primeiro em `dirname(__DIR__) . '/.env'`, ou seja, `/var/www/.env` quando o projeto esta em `/var/www/chronodesk`. Se nao encontrar, tambem aceita `/var/www/chronodesk/.env`, protegido por `.htaccess`. A opcao fora do projeto e preferida.

## Passo a Passo

1. Instalar pacotes:

```bash
sudo apt update
sudo apt install -y apache2 php php-cli php-mysql php-ldap php-curl php-mbstring php-xml php-zip unzip git curl
sudo a2enmod rewrite headers ssl
```

2. Clonar o repositorio:

```bash
sudo git clone <URL_DO_REPOSITORIO> /var/www/chronodesk
cd /var/www/chronodesk
```

3. Criar `.env` manualmente:

```bash
sudo cp /var/www/chronodesk/.env.production.example /var/www/.env
sudo nano /var/www/.env
```

Preencha somente valores reais no servidor. Nao versione `.env` real.

4. Permissoes: **TO CONFIRM.** Veja a secao "Permissoes de Arquivos (TO CONFIRM)".
   Nao aplique o bloco de la sem confirmar o estado real do servidor.

Configure os limites do PHP usados pelo upload privado:

```bash
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
sudo tee "/etc/php/${PHP_VERSION}/apache2/conf.d/99-chronodesk.ini" >/dev/null <<'EOF'
upload_max_filesize=10M
post_max_size=12M
max_file_uploads=1
display_errors=Off
display_startup_errors=Off
expose_php=Off
EOF
sudo systemctl reload apache2
```

5. Configurar Apache:

```bash
sudo cp /var/www/chronodesk/deploy/apache/chronodesk.conf.example /etc/apache2/sites-available/chronodesk.conf
sudo nano /etc/apache2/sites-available/chronodesk.conf
sudo a2ensite chronodesk.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

6. Configurar MySQL no Docker:

O container do MySQL no servidor e o `Chrono_Desk_DB` (imagem `mysql:8.0`).
Confirme se o container publica a porta local:

```bash
docker ps
docker exec -it Chrono_Desk_DB mysql -uroot -p
```

Dentro do MySQL, execute uma copia ajustada de `deploy/mysql/setup-production.sql.example`. Troque somente os placeholders no servidor.

Importe o schema da aplicacao:

```bash
read -rsp 'Senha root do MySQL: ' P; echo
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/database_SECURED.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260612_001_portal_foundation.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260612_002_notification_reads.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260613_003_operational_modules.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260613_004_operational_hardening.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260613_005_documents_and_employee_roles.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260614_006_critical_incidents.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260615_007_war_room_and_shift_feed.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260617_008_dashboard_ci_pa_map.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260617_009_pa_map_recurring_assignments.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20260624_010_schedule_fixed_weekdays.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20261005_011_schedule_history.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20261001_015_login_user_throttle.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20261002_016_lideranca_profile.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20261002_017_shift_attachment_team.sql
unset P
```

Senha por variavel: sem terminal (`docker exec -i` sem `-t`), o `mysql -p` le a
senha da entrada padrao, que aqui e o proprio arquivo SQL, e consome a primeira
linha dele. Por isso a senha e lida antes, sem eco, e passada em `MYSQL_PWD`
so para o comando. `MYSQL_PWD` e obsoleta no MySQL 8.0, mas funciona na imagem
`mysql:8.0`; a alternativa e um arquivo de opcoes, como o do backup.

A numeracao pula de 011 para 015 de proposito: 011 a 014 sao da Parte B
(`docs/DESENHO_PA_ESCALA_AUSENCIA.md`); a 011 (F1, historico de escala) ja
existe e 012 a 014 entram nesta lista, na ordem numerica, quando existirem. Sem a 015, o codigo atual
recusa todo login com 503 (`docs/LIMITE_LOGIN_POR_USUARIO.md`). A 016 (perfil de
Lideranca e login AD unico) para sem alterar nada se houver login AD repetido
(`docs/DESENHO_PERFIL_LIDERANCA.md`, secao 5). Na instalacao nova, `AD_ADMIN_USERS`
precisa ter ao menos um login: admin vem so dessa lista (o preflight reprova
se estiver vazia).

**Fuso horario em sessao manual.** A aplicacao abre a conexao em
`America/Sao_Paulo` (`db.php`, lote BIZ-02), mas uma sessao aberta a mao
(`docker exec ... mysql`) continua no fuso do container, que e UTC. Nela,
`NOW()`, `CURRENT_DATE` e `CURRENT_TIMESTAMP` saem 3 h adiante, e colunas
`TIMESTAMP` sao exibidas 3 h adiante.

- Toda migration aplicada manualmente deve comecar com
  `SET time_zone = 'America/Sao_Paulo';`. As migrations a partir da 011 ja
  trazem essa linha no topo do arquivo.
- Em sessao interativa ou consulta avulsa, execute o mesmo `SET` antes de
  qualquer comando, ou abra o cliente com
  `--init-command="SET time_zone = 'America/Sao_Paulo'"`.
- Migrations novas nao usam `NOW()` nem `CURRENT_TIMESTAMP` em `INSERT` ou
  `UPDATE` de dados: data necessaria vai como valor explicito. `DEFAULT
  CURRENT_TIMESTAMP` na definicao de coluna continua permitido.
- Excecao: os scripts de `migrations/correcao_dados/` usam `-03:00` de
  proposito (seu calculo e de 3 h fixas). Ver `docs/FUSO_HORARIO_BIZ02.md`.

O login LDAP atual faz bind com a credencial informada pelo usuario e nao exige
senha de conta de servico. Para preparar uma futura integracao com cofre, siga
`AD_CREDENTIALS.md`; nao chame scripts Bash a partir de requisicoes PHP.

7. Testar conectividade PHP/MySQL:

```bash
php -r "require '/var/www/chronodesk/db.php'; var_dump((bool)get_db_connection());"
```

8. Executar o QA pós-deploy como o usuário do Apache:

```bash
cd /var/www/chronodesk
sudo -u www-data php scripts/qa-auth.php
sudo -u www-data php scripts/qa-smoke.php
sudo -u www-data php scripts/qa-timezone.php
sudo -u www-data php scripts/qa-overtime.php
sudo -u www-data php scripts/qa-security.php
```

Todos devem terminar com `OK` e código de saída `0`. Rodar como `www-data`
confirma que o código é legível pelo usuário do Apache. Não valida o `.env`:
se ele estiver ilegível, o QA passa mesmo assim, apenas com aviso de `file()`
na saída; trate qualquer aviso como falha. Os scripts não
abrem conexão com o banco nem com o AD e só escrevem no diretório temporário
do sistema. `qa-auth.php` exige `php-ldap` e falha se a extensão estiver
ausente. `qa-security.php` executa endpoints reais em processos PHP filhos
(exige `proc_open` na linha de comando), com banco e AD apontados para
endereços inexistentes e os arquivos de estado no diretório temporário. Qualquer falha bloqueia o deploy: acionar o rollback.

Fuso da sessão MySQL contra o banco real (somente leitura; ver
`docs/FUSO_HORARIO_BIZ02.md`, seção 8.2):

```bash
sudo -u www-data php scripts/qa-timezone.php --db
```

Esperado: `sessao MySQL em America/Sao_Paulo` e `QA timezone (banco real) OK`.

9. Testar URLs:

```bash
curl -I http://chronodesk.interno.local/
curl -I http://chronodesk.interno.local/index.php
curl -I http://chronodesk.interno.local/api/status.php
```

Sem cookie de sessao, `/api/status.php` deve responder `401`.

10. Validar bloqueio de arquivos sensiveis:

```bash
curl -I http://chronodesk.interno.local/.env
curl -I http://chronodesk.interno.local/.git/config
curl -I http://chronodesk.interno.local/database_SECURED.sql
curl -I http://chronodesk.interno.local/README.md
```

As respostas para arquivos sensiveis devem ser `403` ou `404`.

11. Validar autenticacao e fluxos:

- Login AD para CI.
- Login admin/gestor autorizado pelo AD.
- Fluxo de inicio/finalizacao de pausa.
- Fluxo de aprovacao/rejeicao.
- Metricas e relatorios.
- Logout e expiracao de sessao.

12. Validar logs:

```bash
sudo tail -f /var/log/apache2/chronodesk_access.log
sudo tail -f /var/log/apache2/chronodesk_error.log
```

## Configuracao do servidor feita no deploy 1 (10/11/2026)

Mudancas feitas no servidor no dia do deploy 1, fora do repositorio,
registradas aqui sem valor secreto (desenho 9.14, commit C do pos-deploy
1). Elas valem para os deploys seguintes.

- **Log do PHP da aplicacao:** `/var/log/chronodesk/php_error.log`, dono
  `www-data:adm`, modo `640`. E o `<log de erros do PHP>` do teste de
  fumaca (`PHPLOG=` do `scripts/smoke-deploy1.sh`).
- **Rotacao:** `/etc/logrotate.d/chronodesk-php`, com `su www-data adm`
  (o logrotate roda como root e recusa diretorio que nao e do root sem o
  `su`). Conteudo conferido em 12/11/2026 (sem segredo): `su www-data adm`,
  `daily`, `rotate 30`, `compress`, `delaycompress`, `missingok`,
  `notifempty` e `create 640 www-data adm`.
- **`/var/www/.env`:** `PHP_ERROR_LOG` apontando para o arquivo acima e
  `APP_ENV=production` (antes `staging`; o passo 3c do roteiro). Antes de
  cada mudanca, uma copia do `.env` em `/var/backups/chronodesk`, modo `600`,
  dono `root`: `env-antes-php-log-20261110-105724` (antes do
  `PHP_ERROR_LOG`) e `env-antes-app-env-20261110-110942` (antes do
  `APP_ENV`). As copias guardam os segredos do `.env`: saem com aprovacao
  do responsavel (passo 9e do deploy 1). A do log do PHP **nao** casa com o
  `find` da limpeza do 8a (que procura `env-antes-interruptor-*` e
  `env-antes-app-env-*`): apagar pelo nome, conferido.

Conferencia so de leitura (shell root, sem `sudo`):

```bash
stat -c '%n %U:%G %a' /var/log/chronodesk/php_error.log; echo "su no logrotate: $(grep -c '^[[:space:]]*su www-data adm' /etc/logrotate.d/chronodesk-php)"; echo "PHP_ERROR_LOG no .env: $(sed -n 's/^PHP_ERROR_LOG=//p' /var/www/.env | tr -d "\"'")"; awk -F= '/^APP_ENV=/{print "app_env=" $2}' /var/www/.env; logrotate -d /etc/logrotate.d/chronodesk-php 2>&1 | tail -n 3
```

Esperado: `/var/log/chronodesk/php_error.log www-data:adm 640`,
`su no logrotate: 1`, o caminho do log, `app_env=production` e o
`logrotate -d` sem `error`.

## Permissoes de Arquivos (TO CONFIRM)

**Divergencia registrada em 2026-09-29.** O servidor em funcionamento esta com o
clone em `root:root` e arquivos `644`. O bloco abaixo e o endurecimento
recomendado originalmente (`root:www-data`, `640`/`750`) e **nao** corresponde ao
estado atual. Ele foi retirado da rotina de deploy e so deve ser aplicado como
mudanca propria, planejada, depois de confirmar:

- dono, grupo e modo reais de `estado.json`, `pausas.csv`, `config_sistema.json`
  e dos diretorios privados, e como o PHP consegue grava-los hoje;
- o usuario efetivo do PHP-FPM/Apache.

```bash
sudo stat -c '%U:%G %a %n' /var/www/chronodesk /var/www/chronodesk/estado.json /var/www/chronodesk/pausas.csv /var/www/chronodesk/config_sistema.json /var/www/.env
ps -eo user=,comm= | grep -E 'php-fpm|apache2' | sort -u
```

Bloco original, **nao aplicar sem a confirmacao acima**:

```bash
sudo chown -R root:www-data /var/www/chronodesk
sudo find /var/www/chronodesk -type d -exec chmod 750 {} \;
sudo find /var/www/chronodesk -type f -exec chmod 640 {} \;
sudo chmod 640 /var/www/.env
sudo chown root:www-data /var/www/.env

sudo install -d -o www-data -g www-data -m 700 /var/lib/chronodesk/documents

sudo touch /var/www/chronodesk/estado.json \
  /var/www/chronodesk/pausas.csv \
  /var/www/chronodesk/config_sistema.json
sudo chown www-data:www-data \
  /var/www/chronodesk/estado.json \
  /var/www/chronodesk/pausas.csv \
  /var/www/chronodesk/config_sistema.json
sudo chmod 660 \
  /var/www/chronodesk/estado.json \
  /var/www/chronodesk/pausas.csv \
  /var/www/chronodesk/config_sistema.json
```

Nao entregue a propriedade dos arquivos PHP ao usuario do Apache. A escrita fica
limitada aos arquivos operacionais legados acima. Se o PHP nao puder usar o
diretorio de sessao do sistema, crie `sessions/` com dono `www-data`, modo `700`
e mantenha o bloqueio HTTP ja existente.

## Modo de Manutencao

Tira do ar **somente o ChronoDesk**, sem parar o Apache e sem afetar os outros
sites da VM. Disponivel a partir do deploy que inclui a regra no `.htaccess`
(mesmo deploy do PERF-01).

Com o arquivo-sinal `/var/www/chronodesk.maintenance` presente (fora do
DocumentRoot, ao lado do clone), toda requisicao ao ChronoDesk recebe `503`
com o texto `ChronoDesk temporariamente indisponivel.`, sem chegar ao PHP.
Caminhos sensiveis continuam `403`. Requisicoes da propria VM (loopback)
passam, para validar antes de reabrir.

Entrar em manutencao:

```bash
sudo touch /var/www/chronodesk.maintenance
curl -s -o /dev/null -w '%{http_code}\n' http://chronodesk.interno.local/
```

Esperado: `503`. Aguarde alguns segundos: requisicoes que ja estavam em
andamento terminam normalmente.

Validar pela propria VM durante a manutencao (passa pelo bloqueio):

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: chronodesk.interno.local' http://127.0.0.1/api/health.php
```

Sair da manutencao:

```bash
sudo rm -f /var/www/chronodesk.maintenance
curl -s -o /dev/null -w '%{http_code}\n' http://chronodesk.interno.local/
```

Esperado: diferente de `503`.

O arquivo-sinal fica fora do clone: `git pull`, rollback e restauracao nao o
removem. Confira que ele nao ficou para tras (`ls /var/www/chronodesk.maintenance`
deve dizer que nao existe).

O monitoramento ve `503` durante a manutencao, como deve. Um `503` fora de
janela com esse mesmo texto pode ser o arquivo-sinal esquecido **ou** o
PHP-FPM fora do ar: o texto e neutro de proposito. Verifique o arquivo primeiro.

Teste automatizado (derruba o portal por alguns segundos; rode como root, fora
do horario de uso):

```bash
cd /var/www/chronodesk && sudo MAINTENANCE_TEST=1 sh scripts/test-linux.sh
```

## Monitoracao (health check)

`api/health.php` responde `200 {"status":"ok","database":"ok"}` ou
`503 {"status":"fail","database":"fail"}`, sem versao, caminho, host ou
mensagem de erro. Nao abre sessao e nao toca no estado de pausas. A conexao
com o banco tem timeout de 2 segundos.

O acesso e restrito a `HEALTH_ALLOWED_IPS` no `.env` (IPs ou CIDR separados por
virgula). Sem a variavel, apenas loopback. Fora da lista a resposta e `403`.
Inclua somente o IP ou a rede do sistema de monitoracao.

Teste a partir da propria VM (loopback, com o `Host` do VirtualHost):

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: chronodesk.interno.local' http://127.0.0.1/api/health.php
```

Esperado: `200`. A partir de uma estacao fora da allowlist, o esperado e `403`.
Com `FORCE_HTTPS=true`, a resposta sobre HTTP e `301`: aponte a monitoracao
para a URL HTTPS.

## Backup

`scripts/backup-chronodesk.sh` gera, em `/var/backups/chronodesk/AAAAMMDD-HHMMSS/`:

- `database.sql.gz`: dump do MySQL (`mysqldump --single-transaction`), feito
  com usuario somente leitura;
- `files.tar.gz`: `estado.json`, `pausas.csv`, `config_sistema.json`,
  `funcionarios.json` e os diretorios privados (documentos e anexos de escala);
- `SHA256SUMS`.

O `.env` nao entra no backup: ele contem segredos e deve ser recuperavel do
cofre. O diretorio so recebe o nome final depois de todas as verificacoes, e a
retencao so roda apos um backup bem-sucedido.

**Limitacao:** o backup fica no mesmo disco da VM e nao protege contra perda da
VM. A copia para armazenamento externo depende de decisao de infraestrutura.

Instalacao (uma vez):

1. Criar o usuario de backup no MySQL com uma copia ajustada de
   `deploy/mysql/setup-backup-user.sql.example` (senha forte, registrada no cofre):

```bash
docker exec -it Chrono_Desk_DB mysql -uroot -p
```

2. Criar a configuracao e o arquivo de credenciais (root, modo 600):

```bash
sudo install -d -o root -g root -m 700 /etc/chronodesk
sudo install -o root -g root -m 600 /var/www/chronodesk/deploy/backup/backup.env.example /etc/chronodesk/backup.env
sudo nano /etc/chronodesk/backup.env
sudo install -o root -g root -m 600 /dev/null /etc/chronodesk/backup-mysql.cnf
sudo nano /etc/chronodesk/backup-mysql.cnf
```

Conteudo de `backup-mysql.cnf`:

```ini
[client]
user=chronodesk_backup
password=<SENHA_DO_COFRE>
```

Em `backup.env`, confira que `PRIVATE_DIRS` cobre `DOCUMENT_STORAGE_PATH` e
`SHIFT_STORAGE_PATH` do `/var/www/.env`.

3. Instalar o script fora do clone. Ele roda como root, entao a copia em uso
   precisa ter dono root e so muda por acao explicita, nunca por `git pull`:

```bash
sudo install -o root -g root -m 700 /var/www/chronodesk/scripts/backup-chronodesk.sh /usr/local/sbin/chronodesk-backup
```

4. Antes da primeira execucao, confirmar que todas as tabelas sao InnoDB:
   `--single-transaction` so garante um dump consistente para InnoDB. A consulta
   usa a credencial do backup, o que tambem valida a entrega do arquivo de
   opcoes pela entrada padrao, do mesmo jeito que o script faz:

```bash
sudo cat /etc/chronodesk/backup-mysql.cnf | docker exec -i Chrono_Desk_DB mysql --defaults-extra-file=/dev/stdin -e "SELECT COUNT(*) AS tabelas, SUM(engine IS NULL OR engine <> 'InnoDB') AS nao_innodb, GROUP_CONCAT(CASE WHEN engine IS NULL OR engine <> 'InnoDB' THEN CONCAT(table_name, '=', IFNULL(engine, 'NULL')) END) AS quais FROM information_schema.tables WHERE table_schema = 'sistema_pausas' AND table_type = 'BASE TABLE';"
```

Esperado: `tabelas` maior que zero, `nao_innodb` = `0`, `quais` = `NULL`.

- `Access denied`: credencial ou usuario de backup incorretos.
- `tabelas` = `0`: o usuario de backup nao enxerga o banco (grant ausente); nao prossiga.
- `nao_innodb` > `0`: **nao agende o backup.** O dump dessas tabelas nao seria
  consistente. Converter para InnoDB e mudanca propria, com aprovacao.

5. Primeira execucao manual:

```bash
sudo /usr/local/sbin/chronodesk-backup
sudo ls -l /var/backups/chronodesk/
```

Esperado: ultima linha `backup concluido: /var/backups/chronodesk/<AAAAMMDD-HHMMSS> (<tamanho>)`.

6. Agendar (diario as 02:30, com atraso aleatorio de ate 15 minutos):

```bash
sudo cp /var/www/chronodesk/deploy/systemd/chronodesk-backup.service /var/www/chronodesk/deploy/systemd/chronodesk-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now chronodesk-backup.timer
systemctl list-timers chronodesk-backup.timer
```

Acompanhamento:

```bash
journalctl -u chronodesk-backup --since today --no-pager
systemctl status chronodesk-backup.service
```

Quando o script mudar no repositorio, revise o diff antes de reinstalar:

```bash
diff -u /usr/local/sbin/chronodesk-backup /var/www/chronodesk/scripts/backup-chronodesk.sh
```

## Teste de Restauracao

Backup sem teste de restauracao nao e backup. Execute apos a instalacao e
depois periodicamente (sugestao: mensal). O teste restaura em um banco
separado, `sistema_pausas_restore_test`, e nao toca no banco em uso.

```bash
B=/var/backups/chronodesk/<AAAAMMDD-HHMMSS>
C=Chrono_Desk_DB
sudo sh -c 'cd "$1" && sha256sum -c SHA256SUMS' _ "$B"
```

Os diretorios de backup sao de root com modo 700; por isso todo acesso a eles
passa por `sudo`.

Banco:

```bash
docker exec -it "$C" mysql -uroot -p -e "CREATE DATABASE sistema_pausas_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo gunzip -c "$B/database.sql.gz" | docker exec -i "$C" sh -c 'umask 077; cat > /tmp/restore.sql'
docker exec -it "$C" sh -c 'mysql -uroot -p sistema_pausas_restore_test < /tmp/restore.sql'
docker exec -it "$C" mysql -uroot -p -e "SELECT 'tabelas', (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='sistema_pausas'), (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='sistema_pausas_restore_test') UNION ALL SELECT 'funcionarios', (SELECT COUNT(*) FROM sistema_pausas.funcionarios), (SELECT COUNT(*) FROM sistema_pausas_restore_test.funcionarios) UNION ALL SELECT 'audit_log', (SELECT COUNT(*) FROM sistema_pausas.audit_log), (SELECT COUNT(*) FROM sistema_pausas_restore_test.audit_log);"
```

Esperado: mesmo numero de tabelas; `funcionarios` igual (salvo cadastro feito
depois do backup); `audit_log` restaurado menor ou igual ao atual.

Arquivos:

```bash
T=$(sudo mktemp -d)
sudo tar -xzf "$B/files.tar.gz" -C "$T"
sudo ls -l "$T/app-state"
sudo diff -rq "$T/var/lib/chronodesk" /var/lib/chronodesk
```

Esperado: arquivos de estado presentes; `diff` lista apenas o que mudou depois
do backup.

Limpeza (obrigatoria: o dump contem dados pessoais):

```bash
docker exec "$C" rm -f /tmp/restore.sql
docker exec -it "$C" mysql -uroot -p -e "DROP DATABASE sistema_pausas_restore_test;"
sudo rm -rf -- "$T"
```

Registre data, backup usado e resultado.

## Restauracao em Incidente

Somente com decisao do responsavel. Tudo o que foi gravado depois do backup
escolhido sera perdido.

1. Parar a escrita com o Modo de Manutencao (secao acima):
   `sudo touch /var/www/chronodesk.maintenance`.

   > **Antes do deploy que traz o modo de manutencao**, a unica forma de parar a
   > escrita e `sudo systemctl stop apache2`, que derruba **todos** os sites da
   > VM, nao so o ChronoDesk. Nesse caso, combine a janela com o responsavel pelo
   > outro site antes, e no passo 8 use `sudo systemctl start apache2`.

2. Se o backup escolhido tiver mais de `RETENTION_DAYS` dias, copie-o para fora
   de `/var/backups/chronodesk` antes do passo 3: a retencao o removeria.
3. Preservar o estado atual antes de sobrescrever:
   `sudo /usr/local/sbin/chronodesk-backup`.
4. Conferir o backup escolhido:
   `sudo sh -c 'cd "$1" && sha256sum -c SHA256SUMS' _ "$B"`.
5. Banco. O dump recria cada tabela (`DROP TABLE IF EXISTS` + `CREATE TABLE`):

```bash
sudo gunzip -c "$B/database.sql.gz" | docker exec -i "$C" sh -c 'umask 077; cat > /tmp/restore.sql'
docker exec -it "$C" sh -c 'mysql -uroot -p sistema_pausas < /tmp/restore.sql'
docker exec "$C" rm -f /tmp/restore.sql
```

6. Arquivos. Extrair em diretorio temporario e copiar o conteudo por cima dos
   arquivos existentes. `cp` sobre arquivo existente preserva dono e modo do
   destino, entao as permissoes atuais do servidor nao mudam (ver secao
   "Permissoes de Arquivos (TO CONFIRM)"). Anote dono e modo antes e confira depois:

```bash
sudo stat -c '%U:%G %a %n' /var/www/chronodesk/estado.json /var/www/chronodesk/pausas.csv /var/www/chronodesk/config_sistema.json
T=$(sudo mktemp -d)
sudo tar -xzf "$B/files.tar.gz" -C "$T"
sudo sh -c 'for f in "$1"/app-state/*; do cp -- "$f" /var/www/chronodesk/; done' _ "$T"
sudo tar -xzf "$B/files.tar.gz" -C / var/lib/chronodesk
sudo rm -rf -- "$T"
sudo stat -c '%U:%G %a %n' /var/www/chronodesk/estado.json /var/www/chronodesk/pausas.csv /var/www/chronodesk/config_sistema.json
```

Um arquivo que nao existia no destino e criado com dono `root`; ajuste-o para o
dono e modo anotados.

Arquivos privados criados depois do backup nao sao apagados pela extracao;
ficam orfaos, sem registro no banco.

7. Se o backup restaurado for anterior ao corte do fuso horario (BIZ-02) e o
   codigo no ar ja for o corrigido, registre o corte de novo antes de reabrir:
   `docs/FUSO_HORARIO_BIZ02.md`, secao 9.

8. Validar ainda em manutencao, pela propria VM: QA pos-deploy (passo 8) e
   health check por loopback. Depois sair da manutencao
   (`sudo rm -f /var/www/chronodesk.maintenance`) e validar o login.

## Ensaio de Migration

Antes de aplicar uma migration (ou script de dados) em producao, ensaie em banco
descartavel no mesmo MySQL. `scripts/ensaio-migration.sh` cria `ensaio_<nome>`,
copia `funcionarios` (estrutura e dados, so colunas nao geradas) e a estrutura
das tabelas extras indicadas, roda a migration duas vezes e o rollback duas
vezes, e mostra a cada etapa: tipo, nulo, padrao e comentario das colunas de
`funcionarios`, indices,
tabelas, `CHECKSUM TABLE` e contagens por equipe, perfil e situacao (com
`audit_log` nas extras, tambem os eventos gravados). Ao fim, ou se for
interrompido, apaga **so** o `ensaio_<nome>`. Se o banco de ensaio ja existir,
para sem apagar nada.

```bash
cd /var/www/chronodesk
sudo bash scripts/ensaio-migration.sh m016 migrations/20261002_016_lideranca_profile.sql migrations/rollback/20261002_016_lideranca_profile_down.sql
sudo env PREPARO=migrations/20261002_016_lideranca_profile.sql bash scripts/ensaio-migration.sh m016b migrations/20261002_016b_lideranca_profile_data.sql migrations/rollback/20261002_016b_lideranca_profile_data_down.sql audit_log
```

`PREPARO` aplica uma migration pre-requisito uma vez, antes do estado inicial: o
`016b` exige a 016 e, sem ela, para com `migration_016b_exige_016` sem alterar
nada.

O que conferir: a 1a e a 2a execucao da migration terminam no mesmo estado
(idempotencia); a 1a e a 2a do rollback tambem; o rollback volta ao checksum e as
contagens do estado inicial. A senha do root e lida sem eco (`MYSQL_PWD` so nos
comandos). Nada de dado pessoal e exibido; a copia fica no mesmo servidor e some
no `DROP` do ensaio. Variaveis opcionais: `CONTAINER` (padrao `Chrono_Desk_DB`),
`ORIGEM` (padrao `sistema_pausas`) e `PREPARO`.

`DADOS` (F1) copia tabelas com estrutura, chave estrangeira e dados, pelo
`mysqldump` direto para o banco de ensaio (nada e exibido); as tabelas extras do
quarto argumento continuam so com a estrutura (`CREATE TABLE ... LIKE` nao copia
FK). `VERIFICA` roda, em cada estado, um arquivo de consultas que so devolvem
contagens. Ensaio da 011:

```bash
cd /var/www/chronodesk
sudo env DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA=migrations/verificacao/20261005_011_schedule_history_check.sql bash scripts/ensaio-migration.sh m011 migrations/20261005_011_schedule_history.sql migrations/rollback/20261005_011_schedule_history_down.sql
```

O que conferir na 011 (revisado com o ensaio em MySQL 8.0.46):

- **Depois da migration:** os indices `idx_schedule_rule_employee_period`,
  `uq_schedule_rule_employee_from` e `uq_schedule_rule_open` e a coluna
  `open_employee_id`, sem `uq_schedule_rule_employee`; `fora_mapa_escala` em
  `funcionarios`; as duas execucoes iguais. Na verificacao,
  `colaboradores_com_mais_de_uma_aberta`, `sobreposicoes` e `inicio_repetido`
  em 0, e as **contagens** e a **assinatura das colunas originais** (ultima
  consulta) das regras e das excecoes iguais as do estado inicial: a migration
  nao muda linha. O `CHECKSUM TABLE` de `portal_schedule_rules` **muda** depois
  da migration, e isso e esperado: a coluna gerada `open_employee_id` e
  `STORED` e entra no checksum. O `CHECKSUM TABLE` de `funcionarios` tambem
  **muda**, pela coluna nova `fora_mapa_escala`. Nao use o `CHECKSUM TABLE` das
  regras nem o de `funcionarios` para comparar com o estado inicial nesta
  etapa; para `funcionarios`, compare as contagens por equipe, perfil e
  situacao.
- **Depois do 1o rollback:** `uq_schedule_rule_employee` de volta, sem os
  indices novos, sem `open_employee_id` e sem `fora_mapa_escala`, e as tres
  tabelas `*_bkp_011` criadas. Com os dados atuais (uma regra por colaborador,
  nenhuma excecao de Lideranca) o rollback nao apaga linha: contagens,
  assinatura e `CHECKSUM TABLE` (das regras e de `funcionarios`) voltam aos do
  estado inicial.
- **2o rollback:** **para** com `rollback_011 abortado: ja existe copia
  *_bkp_011 ...` (FALHOU no ensaio, de proposito) e o estado fica igual ao do
  1o rollback. Esse e o comportamento esperado: um segundo rollback apagaria o
  historico gravado depois do primeiro.

Ensaio da 012 (F3): tres variantes, com `DADOS`, `VERIFICA` e `PREPARO`, e os
resultados esperados no passo 3 do roteiro "Deploy 11 - Parte B, F3
(ausencias)". O comando do cabecalho de `migrations/20261007_012_absences.sql`
saiu numa linha so, com `--` no lugar das quebras: use o do roteiro.

## Medicao do Polling (F2, P14 e PERF-01)

O F2 acrescenta a presenca de hoje ao `api/status.php`, que toda sessao consulta
a cada 15 s. `scripts/medir-polling.sh` mede o tempo de resposta visto da propria
VM (loopback, sem rede no meio) **antes** e **depois** do deploy do F2, nas mesmas
condicoes. O script nao depende do codigo da aplicacao, entao a base pode ser
medida antes do deploy, lendo o script da versao nova.

**Referencia registrada** (`docs/DESENHO_PA_ESCALA_AUSENCIA.md`, secao 9.2):
2026-10-06, horario de uso normal, conta do responsavel, sem `CONSULTAS=1`,
`HOST_HEADER` = ServerName do vhost, `N=200`, aquecimento 5; **p95 26,8 ms**
(limite dessa medicao: 76,8 ms). Ela e o "antes" do PERF-01.

**Base do criterio do F2 (decisao de 2026-10-06):** a medicao repetida **logo
antes do deploy do F2**, nas mesmas condicoes (mesma conta, sem `CONSULTAS`,
`N=200`, horario parecido), porque os deploys 2 a 8 mudam o mesmo caminho do
`status.php`. O limite de piora e recalculado sobre ela: o maior entre p95 +
50 ms e p95 + 20%. A medicao depois do deploy repete as mesmas condicoes.

**De onde vem a sessao.** O script nao faz login, nao pede senha de AD e nao
cria sessao. A sessao nasce no login normal do ChronoDesk feito no navegador:
o PHP grava o arquivo da sessao no servidor (no `session.save_path` do PHP-FPM
ou, se ele nao for gravavel, em `sessions/` do projeto) e o navegador guarda so
o ID no cookie `PHPSESSID`. O script reaproveita esse ID, enviado do loopback,
como se fosse a aba do navegador.

Sobre a conta: login de tecnico (`api/login_ci.php`) exige conta no AD **e**
cadastro ativo em `funcionarios`, e todo cadastro ativo de N1/N2 aparece no
proprio `status.php` (painel de todos). Por isso nao criar um "tecnico de
teste" so para a medicao, e nunca usar a credencial de um tecnico real. Usar
a propria conta do responsavel: o `status.php` devolve o mesmo conteudo para
todos os perfis, e o que importa e repetir a mesma conta e o mesmo tipo de
login na base e na medicao depois do deploy (anotar qual foi). Se ja existir
conta de teste com cadastro de tecnico (**TO CONFIRM**), pode ser ela.

1. Entrar no ChronoDesk no navegador **imediatamente antes** de medir. O ID da
   sessao troca a cada 30 min de idade da sessao (`security.php`) e a sessao
   de tecnico cai com 30 min sem uso; se o ID trocar no meio, o script recebe
   401 e avisa no fim.
2. F12 > Aplicativo (Application) > Cookies > o endereco do ChronoDesk > copiar
   o **valor** de `PHPSESSID` (so o valor). Fechar a aba do ChronoDesk: o
   polling dela entraria na conta e poderia disparar a troca do ID.
3. Na VM, rodar o script e colar o valor quando ele pedir (nao aparece na tela).
4. Ao terminar: abrir o ChronoDesk de novo e **sair** (o logout apaga a sessao
   no servidor, entao o valor copiado deixa de valer) e limpar a area de
   transferencia (no Windows, Win+V > Limpar tudo).

O valor do cookie vale como a senha enquanto a sessao estiver aberta: nao colar
em chat, chamado ou documento.

**O que o script faz com o cookie.** Le do terminal sem eco; aceita so os
caracteres de um ID de sessao do PHP; entrega ao `curl` pela entrada padrao
(`curl -K -`), nunca como argumento (nao aparece no `ps`); nao exporta a
variavel; apaga a variavel no fim. Nada e gravado: o `curl` roda sem `-c`
(sem arquivo de cookies), o corpo das respostas vai para `/dev/null` e a saida
tem so numeros. O xtrace fica desligado dentro do script, entao nem
`bash -x scripts/medir-polling.sh` mostra o valor. O cookie nao passa pelo
git: o script e versionado, o valor nunca. Se a primeira requisicao nao der
HTTP 200 (sessao expirada ou valor errado), o script para sem medir. Com o
redirecionamento HTTP para HTTPS ligado no VirtualHost, a primeira resposta e
301/302: usar `URL=https://chronodesk.interno.local/api/status.php`.

Teste do script (na estacao ou na VM, com `php` e `curl`; sobe um servidor PHP
falso no loopback com um cookie ficticio): `bash scripts/qa-medir-polling.sh`.

```bash
cd /var/www/chronodesk
# Base, antes do deploy do F2 (script lido da versao nova, sem gravar arquivo):
bash <(sudo git show origin/<BRANCH>:scripts/medir-polling.sh)
# Depois do deploy:
bash scripts/medir-polling.sh
# Opcional: consultas ao MySQL por requisicao (performance_schema; pede a senha do root):
CONSULTAS=1 bash scripts/medir-polling.sh
```

Variaveis: `N` (padrao 200), `AQUECIMENTO` (5, fora da conta), `INTERVALO`
(segundos entre requisicoes, 0), `URL` (padrao `http://127.0.0.1/api/status.php`),
`HOST_HEADER` (padrao `chronodesk.interno.local`), `COOKIE_NOME` (padrao
`PHPSESSID`). Rodar cada medicao duas ou tres vezes e anotar p50, p95, maximo,
tamanho medio e, com `CONSULTAS=1`, consultas por requisicao (o numero inclui
outro trafego no banco; so compara medicoes feitas no mesmo horario).

**Criterio (decisao de 2026-10-05):** ha piora quando o p95 sobe **mais de
50 ms e mais de 20%** em relacao a base, as duas condicoes ao mesmo tempo. Com
piora, desligar `STATUS_PRESENCE_ENABLED` no `.env` e recarregar o PHP-FPM (a
presenca sai do `status.php` sem voltar o codigo), medir de novo e trazer os
numeros para decidir a alternativa do desenho (presenca so no `dashboard.php`).
O interruptor entra no F2; o valor padrao no deploy e **ligado** (variavel
ausente = ligado), para a medicao depois do deploy ja incluir a presenca.

## Acesso de Emergencia (admin local)

Admin vem do `AD_ADMIN_USERS` (Lote 5, L1). O admin local (`ENABLE_LOCAL_ADMIN`,
tabela `usuarios`) e so a saida de emergencia, por exemplo com o AD fora do ar
e uma configuracao urgente a fazer. Fica desligado e sem usuario no dia a dia
(em 2026-10-02: `usuarios` vazia e `ENABLE_LOCAL_ADMIN=false`).

1. Registrar antes de comecar: quem, quando, por que e o numero do chamado.

2. Gerar uma senha forte (gerenciador de senhas ou cofre; 20 caracteres ou mais,
   aleatoria) e guarda-la so no cofre, nunca no repositorio, no chamado ou no
   terminal compartilhado. Criar o usuario local; a senha e lida sem eco e passa
   ao PHP pela entrada padrao (nao aparece em `ps` nem no historico):

```bash
cd /var/www/chronodesk
read -r -p 'Usuario de emergencia: ' U; read -rs -p 'Senha: ' P; echo
printf '%s\n%s\n' "$U" "$P" | sudo -u www-data php -r 'require "config.php"; require "classes/Usuario.php"; $u = trim((string)fgets(STDIN)); $p = rtrim((string)fgets(STDIN), "\r\n"); (new Usuario())->criar($u, $p, "admin"); echo "usuario criado\n";'
unset P
```

   Use um nome que nao seja login do AD (por exemplo `emergencia.chronodesk`):
   o limite de tentativas por usuario conta os dois juntos.

3. Ligar o acesso local e recarregar:

```bash
sudo sed -i 's/^ENABLE_LOCAL_ADMIN=.*/ENABLE_LOCAL_ADMIN=true/' /var/www/.env
grep -q '^ENABLE_LOCAL_ADMIN=true' /var/www/.env && echo OK
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

4. Usar: login administrativo com o usuario local, so para o que motivou o
   acesso.

5. Desligar e recarregar, assim que terminar:

```bash
sudo sed -i 's/^ENABLE_LOCAL_ADMIN=.*/ENABLE_LOCAL_ADMIN=false/' /var/www/.env
grep -q '^ENABLE_LOCAL_ADMIN=false' /var/www/.env && echo OK
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

6. Remover o usuario e conferir (o resultado deve ser `0`):

```bash
docker exec -it Chrono_Desk_DB mysql -uroot -p sistema_pausas -e "DELETE FROM usuarios WHERE username = '<usuario de emergencia>'; SELECT COUNT(*) FROM usuarios;"
```

7. Registrar o encerramento no mesmo chamado: horario de inicio e fim, o que foi
   feito e a contagem de eventos de login local no periodo
   (`` SELECT action, COUNT(*) FROM audit_log WHERE action LIKE 'ADMIN_LOGIN%' AND `timestamp` >= '<inicio>' GROUP BY action; ``).
   Descartar a senha do cofre.

Se o passo 5 for esquecido, o preflight do proximo deploy da `FALHA` com
`ENABLE_LOCAL_ADMIN=true` em qualquer `APP_ENV` diferente de `development`
(L4, Lote 5c). Todo login local fica no `audit_log` como `ADMIN_LOGIN_SUCCESS`
com severidade `CRITICAL`.

## Atualizacao de Versao e Rollback

1. Registrar o ponto de rollback e conferir que nao ha alteracao local:

```bash
cd /var/www/chronodesk
sudo git rev-parse HEAD | sudo tee /var/backups/chronodesk/pre-deploy-commit
sudo git status --short
```

O clone pertence a root: `git` sem `sudo` recusa o repositorio (*dubious ownership*).

`git status` deve sair vazio. Alteracao local na VM precisa ser tratada antes
(copiar para fora, `git checkout -- <arquivo>`), senao o `pull` falha ou a
sobrescreve.

2. Buscar a versao nova e rodar a pre-checagem **da versao nova** contra o
   `.env`, antes de trocar o codigo:

```bash
sudo git fetch origin
sudo git show origin/<BRANCH>:scripts/preflight-deploy.sh | sudo sh -s -- /var/www/.env
```

Esperado: `RESULTADO=0`. Qualquer `FALHA` interrompe o deploy.

3. Backup imediatamente antes da troca:

```bash
sudo systemctl start chronodesk-backup.service
journalctl -u chronodesk-backup -n 5 --no-pager
```

Esperado: `backup concluido`. Anote o diretorio: e o ponto de restauracao do banco.

   **Migration 015 (Lote 7), antes do `git pull`.** Se a versao nova traz
   `migrations/20261001_015_login_user_throttle.sql` e ela ainda nao foi aplicada
   no banco, aplique agora, lida da versao nova (o arquivo comeca com o
   `SET time_zone`), e confira a tabela (resultado `1`):

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; sudo git show origin/<BRANCH>:migrations/20261001_015_login_user_throttle.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
docker exec -i Chrono_Desk_DB mysql -uroot -p sistema_pausas -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle';"
```

   A migration e idempotente: repetir nao altera nada. A tabela nao afeta o
   codigo anterior, que continua no ar ate o item 4.

   **Migration 016 (Lote 5a), antes do `git pull`.** Primeiro, confirme que o
   login do responsavel esta em `AD_ADMIN_USERS` no `/var/www/.env`: a partir do
   Lote 5, admin vem so dessa lista, e sem ela ninguem entra como admin (so o
   acesso de emergencia). Depois aplique a 016, lida da versao nova, e confira
   (indice: 1 linha; coluna: tipo com `lideranca`):

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; sudo git show origin/<BRANCH>:migrations/20261002_016_lideranca_profile.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
docker exec -i Chrono_Desk_DB mysql -uroot -p sistema_pausas -e "SHOW INDEX FROM funcionarios WHERE Key_name = 'uq_funcionarios_ad_login'; SHOW COLUMNS FROM funcionarios LIKE 'access_role';"
```

   Se parar com `Table ... migration_016_abortada_ad_login_duplicado doesn't
   exist`, ha login AD repetido entre cadastros e **nada foi alterado**: resolver
   os duplicados (consulta so de contagem:
   `SELECT COUNT(*) FROM (SELECT ad_login FROM funcionarios WHERE ad_login IS NOT NULL GROUP BY ad_login HAVING COUNT(*) > 1) d;`)
   e repetir. Idempotente. Ate o item 4, o codigo anterior recebe erro ao gravar
   num cadastro o login AD de outro cadastro inativo (antes era aceito).

   **Migration 011 (Parte B, F1), antes do `git pull`.** Historico de escala,
   Lideranca nas tabelas de escala e `funcionarios.fora_mapa_escala`. O codigo
   anterior funciona sobre o schema novo; o codigo do F1 exige a 011 (le
   `fora_mapa_escala` e grava varias regras por colaborador). Ensaie antes
   (secao Ensaio de Migration, com `DADOS` e `VERIFICA`) e aplique:

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; sudo git show origin/<BRANCH>:migrations/20261005_011_schedule_history.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
read -rsp 'Senha root do MySQL: ' P; echo; sudo git show origin/<BRANCH>:migrations/verificacao/20261005_011_schedule_history_check.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas; unset P
```

   Esperado: as tres primeiras contagens em 0. A 011 nao altera linha.

   **Migration 017 (Lote 4, SEC-08), antes do `git pull`.** Equipe do anexo de
   escala; o codigo anterior nao le a coluna. Ensaie antes (secao Ensaio de
   Migration, com `portal_shift_attachments` nas extras) e aplique:

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; sudo git show origin/<BRANCH>:migrations/20261002_017_shift_attachment_team.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
docker exec -i Chrono_Desk_DB mysql -uroot -p sistema_pausas -e "SHOW COLUMNS FROM portal_shift_attachments LIKE 'team'; SHOW INDEX FROM portal_shift_attachments WHERE Key_name = 'idx_shift_attachment_team';"
```

4. Trocar o codigo e revisar o que mudou em pontos sensiveis:

```bash
sudo git pull --ff-only
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
```

- Migration nova: aplicar como no passo 6 do Passo a Passo, com a sessao em
  `America/Sao_Paulo` (ver "Fuso horario em sessao manual", no mesmo passo).
  As migrations ate a 010 nao tem script de reversao; desfazer uma delas exige
  restaurar o backup do item 3.
- `scripts/backup-chronodesk.sh` alterado: revisar e reinstalar (secao Backup).
- Arquivos em `migrations/correcao_dados/` **nao** sao migrations de deploy: sao
  scripts de correcao de dados, cada um com procedimento proprio. Nao aplicar
  junto com as migrations numeradas.
- Deploy que traz o lote de fuso horario (BIZ-02): ha passos adicionais, entre
  eles o registro do corte **antes** da recarga do item 5. Siga
  `docs/FUSO_HORARIO_BIZ02.md`, secao 6.
- Deploy que traz o Lote 7 (limite de login por usuario, SEC-03): a migration
  015 ja deve ter sido aplicada no item 3, **antes** do `git pull`. Sem a
  tabela, o codigo novo recusa todo login com 503. Siga
  `docs/LIMITE_LOGIN_POR_USUARIO.md`, secao 6.
- Deploy que traz o Lote 5a (perfil de Lideranca): a 016 ja deve ter sido
  aplicada no item 3. Depois da recarga, siga o roteiro de
  `docs/DESENHO_PERFIL_LIDERANCA.md`, secao 10 (login de cada perfil,
  `ADMIN_ROLE_SEM_ALLOWLIST` e teste da revalidacao). Toda sessao aberta antes do
  deploy com perfil maior que o atual e encerrada na primeira requisicao
  (`SESSION_REVOKED`): o N2 e o lider fora da allowlist precisam entrar de novo.
- Deploy que traz o Lote 5b (cadastro de funcionarios por perfil): o `016b`
  (normalizacao dos perfis) vai **depois** da recarga do item 5, com o codigo
  novo no ar; o codigo anterior leria `lideranca` como tecnico. Ensaie antes
  (secao Ensaio de Migration, com `PREPARO` da 016) e aplique:

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < migrations/20261002_016b_lideranca_profile_data.sql; unset P
docker exec -i Chrono_Desk_DB mysql -uroot -p sistema_pausas -e "SELECT equipe, access_role, ativo, COUNT(*) FROM funcionarios GROUP BY equipe, access_role, ativo; SELECT action, COUNT(*) FROM audit_log WHERE action IN ('LIDERANCA_PERFIL_MIGRADO', 'ADMIN_INATIVO_REBAIXADO') GROUP BY action;"
```

  Esperado com os dados de 2026-10-02: `lideranca`/`lideranca` ativos 3;
  `n2`/`admin` ativos 2 (inalterados); o `n2` inativo como `tecnico`; um evento
  de cada. Repetir o `016b` nao muda nada. Sessoes nao sao afetadas: o perfil
  efetivo dos tres lideres nao muda (2 admin pela allowlist, 1 `lideranca`).
- Deploy que traz o Lote 4 (SEC-08, SEC-07 e banco indisponivel). **Checagem
  previa**, antes do item 3, so contagem:
  `docker exec -it Chrono_Desk_DB mysql -uroot -p sistema_pausas -e "SELECT audience, COUNT(*) FROM portal_announcements GROUP BY audience;"`.
  Valor fora do padrao (`all`, `todos`, vazio, `n1`, `n2`, `lideranca`,
  `gestao` ou lista deles separada por virgula) fica visivel so para a gestao;
  decidir antes do deploy se o valor deve ser corrigido. A 017 ja deve ter
  sido aplicada no item 3. Depois da recarga: publicar um anexo de escala para
  N1 e conferir que um tecnico da N2 nao o lista nem o baixa (404) e que um
  gestor ve os dois; um tecnico ve os avisos de todos e os da propria equipe,
  e um gestor ve todos. Com o banco fora, as telas recebem 503 com "Banco de dados
  indisponivel no momento" em vez de erro 500 (`docs/DESENHO_LOTE4.md`).
- Deploy que traz o F1 (Parte B: historico de escala, escala implicita da
  Lideranca e cadastro fora do Mapa PA e da escala): a 011 ja deve ter sido
  aplicada no item 3. Depois da recarga, com um tecnico de teste: (1) trocar a
  regra a partir de hoje e conferir na tela a anterior encerrada ontem e a nova
  vigente; agendar uma regra futura e ver "Agendada"; (2) remover a escala e
  conferir que a regra antiga continua com o tipo (`include_history=1`); (3) gestor
  com data anterior a hoje recebe 403; Lideranca e admin gravam, com
  `retroativa=sim` no `audit_log`; (4) a Lideranca aparece na escala como
  "Presencial (regra fixa)", sem editar; regra para lider da 409; excecao de
  lider so pelo admin, remota recusada; linha de lider na planilha vem com aviso
  e e ignorada; (5) no cadastro, so o admin marca "Fora do Mapa PA e da
  escala" (`FUNCIONARIO_FORA_MAPA_ALTERADO`); com vinculo de PA ativo da 409;
  cadastro marcado some da escala e do Mapa PA e recebe 409 em regra, excecao e
  vinculo. Os cadastros a marcar sao escolhidos pelo responsavel na tela; nenhum
  nome ou ID vai no codigo. Rodar a verificacao da 011 de novo no fim.
- Deploy que traz o F2 (Parte B: motor de presenca, `portal/presence.php` e
  presenca no `status.php`): sem migration, mas exige a 011 (deploy do F1). Sem
  ela, o `status.php` continua respondendo com `presenca_disponivel: false` e
  o `presence.php` falha. **Antes do `git pull`:** repetir a medicao do
  polling e anota-la como **base do criterio** (secao "Medicao do Polling";
  decisao de 2026-10-06, secao 9.2 do desenho). O preflight mostra `STATUS_PRESENCE_ENABLED` (ligado se ausente).
  Depois da recarga, logado no navegador: (1) abrir
  `/api/portal/presence.php?from=AAAA-MM-DD&to=AAAA-MM-DD` de uma semana com fim
  de semana e conferir que sabado e domingo vem como "Fim de semana", que
  quem tem regra `undefined` ou nenhuma vem "Sem escala definida" e que a
  Lideranca vem "Presencial (regra fixa)"; (2) quem tem excecao de folga,
  ausencia, ferias ou licenca no dia vem "Ausente (dia todo)", sem a categoria;
  (3) `/api/status.php` traz `presenca` em cada item e `presenca_disponivel:
  true`; (4) medir o polling nas condicoes da base. Piora (p95 sobe mais de
  50 ms **e** mais de 20%): `STATUS_PRESENCE_ENABLED=false` no `.env`, recarga
  do PHP-FPM e nova medicao. Calendario, Escala, Relatorios e Mapa PA nao mudam
  neste deploy. Rollback: so codigo (o F2 nao grava nada).
- Deploy que traz o F2B (Parte B: ciclo, dias de home, previa e tela Escala no
  motor): sem migration, mas exige a 011 (deploy do F1: o ENUM de
  `rule_type` com `cycle`) e o F2 (motor). **Antes do `git pull`, opcional:**
  ensaio do rollback do ciclo em banco descartavel (comando no cabecalho de
  `migrations/rollback/20261007_f2b_cycle_ensaio_preparo.sql`; esperado:
  `regras_cycle` 1 -> 0 -> 1 e `undefined_com_pattern` 0 -> 1 -> 0, iguais na
  2a execucao de cada script). Depois da recarga, logado como gestor:
  (1) Escala: nova regra "Ciclo presencial/remoto" 2 presenciais, 1 remoto,
  comeca por remoto, data inicial numa segunda; "Ver previa" mostra
  R P P R P | P R P P R e a regra que sera encerrada; Salvar so habilita depois
  da previa e volta a desabilitar se um campo mudar; salvar e repetir da
  "nada foi alterado"; (2) data inicial num sabado: recusada; 11 + 10 dias:
  recusado; data passada como gestor: 403 (Lideranca e admin passam);
  (3) "Dias fixos" informando home ter e qui grava presencial seg, qua e sex;
  todos os dias como home: recusado sugerindo "Sempre remoto"; (4) tela
  Escala: sabado e domingo so com excecao de trabalho; quem nao tem regra
  aparece "Sem escala"; folga, ausencia, ferias e licenca aparecem
  "Ausência" com "Ausente (dia todo)"; (5) Relatorios, CSV e Calendario de um periodo com o
  ciclo: sem erro, ciclo so nos dias uteis; os demais colaboradores com os
  mesmos numeros de antes; (6) "Historico" na linha da regra mostra as
  versoes; (7) Mapa PA (admin ou Lideranca): vincular quem tem ciclo num dia
  presencial nao pede confirmacao; num dia remoto pede, como o sempre remoto;
  o cartao mostra "Ciclo 2P/1R" e fica presencial so nos dias certos; (8)
  importacao com linha "ciclo": recusada com a orientacao de usar a tela;
  (9) `migrations/verificacao/20261007_f2b_cycle_check.sql`:
  `cycle_sem_pattern = 0` e `undefined_com_pattern = 0`. Rollback: ver
  "Rollback", Parte B, F2B.
- Deploy que traz o F3 (Parte B, ausencias; deploy 11): siga o roteiro
  "Deploy 11 - Parte B, F3 (ausencias)", abaixo. A 012 e o `git merge` do
  commit ensaiado rodam juntos no passo 5 dele (sem pausa), depois do ensaio e
  da Q4; nao use o `git pull` deste item.
- Deploy que traz o SEC-07b (texto livre do Calendario; deploy 12): so
  codigo, sem migration nem build do frontend. Depois da recarga: (1) como
  tecnico, o Calendario do mes mostra horas extras, ajustes e plantoes dos
  colegas sem o texto e os proprios com o texto; (2) pausa com motivo fora de
  Cafe, Pessoal e Reuniao aparece como "Pausa" para o tecnico e com o valor
  gravado para a gestao; (3) `portal/oncall.php` (DevTools) sem `note`,
  `created_by` e `updated_by` dos plantoes de outros; (4) gestor e Lideranca
  sem mudanca. Rollback: so codigo; reabre o envio dos textos a todos.
- Deploy que traz o F4 (Parte B, arquivamento de tecnicos): siga o roteiro
  "Deploy que traz o F4", abaixo. Ensaio da 014 nas tres variantes, Q-F4, a
  014 e o `git merge` do commit ensaiado juntos (com `cat-file` e `pipefail`),
  a verificacao e o passo pos-deploy da D5; nao use o `git pull` deste item.
  Entra no roteiro consolidado da Parte B (deploy unico depois do F9).
- Deploy que traz o F5 (Parte B, Calendario e relatorios no motor a partir da
  data de corte): siga o roteiro "Deploy que traz o F5", abaixo. Ensaio da 019
  nas tres variantes, a 019 e o `git merge` do commit ensaiado juntos (com
  `cat-file` e `pipefail`), a verificacao da 019 em cada etapa
  (`inconsistente = 1` = PARAR), a consulta da pendencia 16c com as duas
  colunas em 0 e so entao o registro do corte; nao use o `git pull` deste
  item. Entra no roteiro consolidado da Parte B (deploy unico depois do F9).
- Deploy que traz o F6 (Parte B, Mapa PA no motor): so codigo, sem
  migration. Siga o roteiro "Deploy que traz o F6", abaixo: aviso a gestao
  antes, Q-F6 logo antes do `git merge` do commit conferido (sem `pull`),
  build, recarga e verificacao so com contagens. Entra no roteiro consolidado
  da Parte B (deploy unico depois do F9).
- Deploy que traz o F7 (Parte B, Visao Operacional no motor): so codigo, sem
  migration. Siga o roteiro "Deploy que traz o F7", abaixo: aviso a gestao
  (junto com o do F6), referencia do `dashboard.php`, ensaio do commit gravado
  com `git archive`, Q-F7 e rotulo de equipe, base do criterio, `git merge`
  do commit conferido (sem `pull`), build, recarga, verificacao so com
  contagens e medicao depois. Desligamento rapido: `STATUS_PRESENCE_ENABLED=false`
  no `/var/www/.env` (com copia antes) e recarga do PHP-FPM. Entra no roteiro consolidado da
  Parte B (deploy unico depois do F9).
- Deploy que retira as paginas legadas (Parte B, desenho 9.12; so `.htaccess`
  e testes, sem migration nem build): o `.htaccess` e lido a cada requisicao,
  sem recarga. Depois do `merge`, conferir so com o codigo HTTP (sem IP; use a
  URL base do vhost, `<URL>`):
  `for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "<URL>$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "<URL>/static/js/$j")"; done`
  Esperado: `GET 302` e `POST 410` em todas as paginas, menos o `/logout.php`
  (logout das Metricas legadas, HH5), com `GET 410` e `POST 410`; `403` nos dois JS. Ou
  `sh scripts/test-linux.sh`, que confere o mesmo. `GET 200` numa pagina
  legada = o `.htaccess` nao vale no vhost (`AllowOverride`): PARAR e avisar o
  responsavel. Rollback: o `.htaccess` anterior (git). Os arquivos das paginas
  sao apagados depois de um ciclo estavel, em lote proprio, pelo responsavel
  (passo 5 da 9.12). Entra no roteiro consolidado da Parte B (deploy unico
  depois do F9).
- **Deploy 1 da Parte B (decisao de 2026-11-06: F1 a F7, acabamento,
  retirada das paginas legadas e 9.12.1 a 9.12.5; F8 e F9 no deploy 2,
  que desde 2026-11-12 se chama deploy 3):**
  siga o roteiro "Deploy 1 da Parte B (roteiro unico ...)", abaixo, e nao os
  itens por lote desta lista nem o `git pull` deste item. As mencoes a
  "deploy unico depois do F9" nos itens e roteiros por lote valem, desde
  2026-11-06, para o deploy 1. **Desde 2026-11-09, o servidor esta no
  `596db67`** (a fila antiga nunca foi aplicada): o roteiro do deploy 1 leva
  tudo desde ele, inclusive os itens dos lotes 1 a 7 e do BIZ-02 desta lista
  (inventario em `docs/DEPLOY1_INVENTARIO.md`).
- Deploy que traz o Lote 5c: o preflight passa a reprovar `ENABLE_LOCAL_ADMIN`
  ligado fora de `development` e avisa se `AUTH_SOURCE` ainda estiver no
  `.env` (a variavel foi removida do codigo; pode ser apagada do `.env`). Depois
  da recarga: a Lideranca passa a remover regra de escala, publicar anexo de
  escala, alterar o Mapa PA e derrubar pausa; regra ou excecao de escala de
  cadastro da equipe Lideranca, ou com perfil Lideranca ou admin, so pelo admin
  (L9).

5. Frontend e recarga:

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
```

Recarregue Apache e PHP-FPM (este ultimo limpa o OPcache). `reload` e gracioso:
nao derruba conexoes do outro site da VM. A rotina **nao** altera permissoes;
veja "Permissoes de Arquivos (TO CONFIRM)".

```bash
sudo systemctl reload apache2
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

6. Validar: QA pos-deploy (passo 8), health check, passos 9 a 12 e a checagem
   HTTP de `scripts/test-linux.sh`. Qualquer falha: rollback.

### Rollback

Codigo (nao perde dados):

```bash
cd /var/www/chronodesk
sudo git checkout --detach "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)"
```

Em seguida repita o item 5 (build e recarga) e o item 6 (validacao).
O clone fica em *detached HEAD*; no proximo deploy, volte ao branch com
`sudo git checkout <BRANCH>` antes do `pull`.

Banco: so e necessario se o deploy aplicou migration incompativel com o codigo
anterior. Nesse caso, siga a Restauracao em Incidente com o backup do item 3,
ciente de que o que foi gravado depois dele sera perdido.

Lote 5 (perfil de Lideranca), sem restaurar backup e nesta ordem:
1. Com o codigo novo **ainda no ar**, reverter os dados:
   `read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < migrations/rollback/20261002_016b_lideranca_profile_data_down.sql; unset P`
   (so as linhas registradas pelo `016b` que ainda estao com o perfil gravado por
   ele; quem foi promovido a `lideranca` depois fica como esta e, com o codigo
   anterior, entra como tecnico).
2. Rollback do codigo, como acima.
3. Opcional: `migrations/rollback/20261002_016_lideranca_profile_down.sql` (para
   sem alterar nada se ainda houver `lideranca`).

Parte B, F1: depois do rollback do codigo, opcional,
`migrations/rollback/20261005_011_schedule_history_down.sql`. O codigo anterior
funciona com a 011 aplicada enquanto nao houver regra `cycle` (o F1 nao grava
esse tipo), com uma ressalva lida no codigo e nao testada em banco: depois que o
F1 gravou historico, o codigo anterior pode receber erro de chave duplicada ao
salvar regra com o mesmo inicio de uma regra ja encerrada (a 011 permite uma so
regra aberta). Se o codigo anterior for ficar no ar mais que o tempo de
corrigir o problema, aplique o rollback da 011. O rollback guarda tudo em `portal_schedule_rules_bkp_011`,
`portal_schedule_exceptions_bkp_011` e `funcionarios_fora_mapa_bkp_011` e deixa
uma regra por colaborador (a aberta ou a de inicio mais recente): o historico
gravado pelo F1 so fica na copia, e as marcacoes "fora do Mapa" se perdem
(refazer pela tela depois de reaplicar). Apagar as copias a mao, depois de
conferir. Se ja existir qualquer copia `*_bkp_011` (rollback anterior), o
rollback **para antes de alterar**, com `rollback_011 abortado: ja existe copia
*_bkp_011 ...`: conferir as copias, renomea-las (`RENAME TABLE
portal_schedule_rules_bkp_011 TO portal_schedule_rules_bkp_011_<data>`, e as
demais) e repetir. Depois dessa parada, o procedimento temporario
`rollback_011_confere_copias` fica no banco ate a proxima execucao do rollback,
que o recria e o apaga no fim (ou `DROP PROCEDURE rollback_011_confere_copias;`).

Parte B, F2B (so se houver regra `cycle` gravada; sem ela, so o codigo), nesta
ordem:
1. Com o codigo do F2B **ainda no ar**, trocar `cycle` por `undefined`
   mantendo a configuracao:
   `read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < migrations/rollback/20261007_f2b_cycle_down.sql; unset P`.
   O codigo do F2B le essas linhas como "Sem escala", sem erro. Conferir com
   `migrations/verificacao/20261007_f2b_cycle_check.sql`: `regras_cycle = 0`.
2. Rollback do codigo, como acima. O codigo anterior le `undefined` como antes
   (Sem escala na tela, remoto nos relatorios antigos). Vinculos de PA de quem
   tinha ciclo ja estao gravados como `undefined`: nada a fazer.
3. Volta, quando o F2B voltar ao ar: primeiro o codigo, depois
   `migrations/rollback/20261007_f2b_cycle_up.sql` (reaplica `cycle` onde a
   configuracao tem `pattern`); conferir `undefined_com_pattern = 0`.
Os dois scripts so fazem UPDATE, sao idempotentes e nao apagam historico (o
rollback da 011 apagaria; nao use para isso). Aplicar o `up` com o codigo
anterior no ar quebra Calendario, Escala e Relatorios (400).

Parte B, F3: ver "Rollback da 012" e o aviso de LGPD no roteiro "Deploy 11 -
Parte B, F3 (ausencias)". Com ausencias ja gravadas, o rollback do codigo
expoe as observacoes no Calendario do codigo anterior.

Parte B, F6: so codigo, sem passo de banco; ver "Rollback do F6" no roteiro
"Deploy que traz o F6". O codigo anterior volta a enviar logins, observacoes
e autores dos vinculos do Mapa PA a qualquer perfil.

Lote 4 (SEC-08): depois do rollback do codigo, opcional,
`migrations/rollback/20261002_017_shift_attachment_team_down.sql` (remove a
coluna `team`; os anexos voltam a ser visiveis para todos).

### Deploy 11 - Parte B, F3 (ausencias)

Roteiro completo, na ordem. O F3 traz a migration 012 (`portal_absences` no
formato novo), o servico e o endpoint de ausencias, a ausencia parcial no motor,
a P7 (excecao de escala so com remoto, presencial, treinamento e plantao) e a
pagina de Ausencias. Nao traz variavel nova no `.env`, nem mudanca em
`.htaccess`, vhost ou dependencia do frontend. Desenho:
`docs/DESENHO_PA_ESCALA_AUSENCIA.md`, secao 9.4.

Todas as consultas deste roteiro devolvem **so contagens e estrutura**, nunca
nome nem texto de observacao. Onde aparece `<BRANCH>`, use o branch do deploy;
onde aparece `<ServerName do vhost>`, o `ServerName` do VirtualHost do portal.
A senha do root do MySQL e sempre lida sem eco (`read -rsp`) e apagada no fim
do comando (`unset P`); nunca a escreva no comando nem neste documento.

**Pre-requisitos.** Deploys 1 a 10 no ar, nesta ordem: o F3 usa a 011 (deploy 8)
e o motor do F2 e do F2B (deploys 9 e 10). Conferencia da 011 (esperado `1`):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas -e "SELECT COUNT(*) AS migration_011 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'fora_mapa_escala';"; unset P
```

**Janela.** Entre a 012 (passo 5) e o `git merge` (no mesmo comando) a tela de
Ausencias do codigo anterior responde 503 ("A base do portal ainda nao esta
disponivel"); o resto do portal continua no ar. Entre o `git merge` e o build do
frontend (passo 6), o navegador ainda carrega a tela anterior: a lista generica
de ausencias aparece sem a coluna de tipo e a excecao de escala ainda oferece
folga, falta, ferias e licenca, que o servidor novo recusa com 400 e a mensagem
"lance na tela Ausencias". Prefira horario de pouco uso.

1. **Ponto de rollback e pre-checagem**: itens 1 e 2 de "Atualizacao de Versao
   e Rollback" (anota `pre-deploy-commit`, `git status` vazio, `git fetch` e
   preflight da versao nova com `RESULTADO=0`).

2. **Backup**: item 3 da mesma secao. Esperado `backup concluido`; anote o
   diretorio (ponto de restauracao do banco).

3. **Ensaio da 012** em banco descartavel, com os arquivos da versao nova (antes
   do `git merge` eles ainda nao estao na arvore). O `scripts/ensaio-migration.sh`
   do servidor ja tem `DADOS`, `VERIFICA` e `PREPARO` (deploy 8) e nao mudou no
   F3. Antes do ensaio, confira que o clone esta no `<BRANCH>` (o `git merge` do
   passo 5 vale para o branch atual; depois de um rollback o clone fica em
   *detached HEAD* e a saida e `HEAD`) e grave o commit ensaiado: o passo 5
   aplica exatamente esse commit, sem novo `fetch` no meio.

```bash
cd /var/www/chronodesk
B=$(sudo git rev-parse --abbrev-ref HEAD); [ "$B" = "<BRANCH>" ] && echo "branch OK: $B" || echo "PARE: o clone esta em $B, nao em <BRANCH>"
sudo git rev-parse origin/<BRANCH> | sudo tee /var/backups/chronodesk/f3-commit
E=$(sudo mktemp -d /root/ensaio-f3.XXXXXX); sudo git archive origin/<BRANCH> migrations | sudo tar -x -C "$E"
M=$E/migrations/20261007_012_absences.sql; R=$E/migrations/rollback/20261007_012_absences_down.sql; V=$E/migrations/verificacao/20261007_012_absences_check.sql
sudo env DADOS=portal_absences VERIFICA="$V" bash scripts/ensaio-migration.sh f3 "$M" "$R"
sudo env DADOS=portal_absences PREPARO="$E/migrations/rollback/20261007_012_ensaio_linha_antiga.sql" VERIFICA="$V" bash scripts/ensaio-migration.sh f3_parada "$M" "$R"
sudo env DADOS=portal_absences PREPARO="$M" VERIFICA="$V" bash scripts/ensaio-migration.sh f3_rollback "$E/migrations/rollback/20261007_012_ensaio_linhas_f3.sql" "$R"
```

   O que conferir (os mesmos tres ensaios aprovados em 2026-10-07):

   - **(a) `f3`, tabela vazia:** a 1a e a 2a execucao da migration terminam no
     mesmo estado, com `colunas_012 = 10`, `absence_type = 0`,
     `status_tipo = enum('approved','cancelled')`, `fk = 1`, `indice = 1`,
     `checks = 2` (as duas constraints `ck_absence_range` e
     `ck_absence_period`), `copia_rollback = 0`, e na segunda consulta tudo `0`.
     O 1o rollback volta a `colunas_012 = 0`, `absence_type = 1`,
     `fk = indice = checks = 0` e `copia_rollback = 1`. O **2o rollback para**
     com `rollback_012 abortado: ja existe portal_absences_bkp_012 ...` (FALHOU
     no ensaio, de proposito) e o estado fica igual ao do 1o.
   - **(b) `f3_parada`, linha no formato antigo:** a 012 **para nas duas
     execucoes** com `migration_012 abortada: portal_absences tem linhas ...`,
     sem alterar nada (`colunas_012 = 0` e `absence_type = 1` em todos os
     estados).
   - **(c) `f3_rollback`, rollback com linhas do F3:** depois do arquivo de
     linhas, `linhas = 4`, `aprovadas = 3`, `canceladas = 1`, `parciais = 2`,
     `sobreposicoes_aprovadas = 0` (igual na 2a vez); depois do 1o rollback,
     `colunas_012 = 0`, `absence_type = 1` e `linhas = 4` (a cancelada como
     `rejected`); o 2o rollback para de proposito.

   Qualquer resultado diferente: **pare o deploy** (nada foi alterado no banco
   real) e envie as contagens. No fim, apague o diretorio temporario
   (`sudo rm -r "$E"`; confira antes que `$E` comeca com `/root/ensaio-f3.`).

4. **Q4 imediatamente antes da 012** (decisao 2 do F3: excecoes de ausencia
   gravadas continuam lidas como ausencia de dia inteiro, so leitura; a 013 foi
   dispensada com a Q4 de 2026-10-07 em 0) e contagem de `portal_absences`:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT COUNT(*) AS ausencias FROM portal_absences;"; unset P
```

   Anote as contagens: a Q4 se repete na verificacao (passo 8).
   - `day_off`, `absence`, `vacation` ou `leave` com linhas: o deploy pode seguir
     (decisao 2); essas datas continuam "Ausente (dia todo)", sem categoria, e
     nao podem mais ser lancadas como excecao. As futuras podem ser relancadas
     na tela de Ausencias depois do deploy, se a gestao quiser categoria e
     observacao. Registre o numero no registro do deploy.
   - `ausencias` maior que `0`: a 012 vai parar no passo 5 sem alterar nada
     (premissa da tabela vazia). Pare aqui e envie a contagem.

5. **012 e `git merge` em sequencia, sem pausa** (decisao 3 da 012), no mesmo
   comando. O `git cat-file -e` confere antes que o arquivo existe na versao
   nova, e a aplicacao roda com `pipefail`: sem ele, se o `git show` falhasse, o
   `mysql` receberia entrada vazia, sairia com `0` e o `merge` rodaria sem a
   012. Assim o `merge` so acontece se o `git show` e o `mysql` derem certo. O
   `merge --ff-only` de `origin/<BRANCH>` aplica o commit do ensaio (sem
   `fetch`); a linha seguinte confirma. Em seguida, a verificacao estrutural:

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e origin/<BRANCH>:migrations/20261007_012_absences.sql && bash -o pipefail -c 'sudo git show origin/<BRANCH>:migrations/20261007_012_absences.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && sudo git merge --ff-only origin/<BRANCH>; unset P
[ "$(sudo git rev-parse HEAD)" = "$(sudo cat /var/backups/chronodesk/f3-commit)" ] && echo "HEAD = commit do ensaio" || echo "PARE: HEAD diferente do commit do ensaio"
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261007_012_absences_check.sql; unset P
```

   Esperado: `colunas_012 = 10`, `absence_type = 0`,
   `status_tipo = enum('approved','cancelled')`, `fk = 1`, `indice = 1`,
   `checks = 2`, `copia_rollback = 0`, `linhas = 0`; na segunda consulta, tudo
   `0`. O `diff --stat` lista so os arquivos da 012 em `migrations/`. O
   `f3-commit` (passo 3) guarda a versao do F3, de onde o rollback da 012 le os
   arquivos.

6. **Build do frontend**:

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
```

7. **Recarga do PHP-FPM** (limpa o OPcache; `reload` e gracioso). O F3 nao muda
   vhost nem `.htaccess`: a recarga do Apache nao e necessaria.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

8. **Verificacao pos-deploy, so com contagens.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261007_012_absences_check.sql; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT action, COUNT(*) AS n FROM audit_log WHERE action LIKE 'ABSENCE\_%' AND timestamp >= CURDATE() GROUP BY action;"; unset P
```

   Esperado: health `200`; a verificacao da 012 como no passo 5 (com ausencias
   lancadas depois do deploy, `linhas`, `aprovadas` e `canceladas` crescem, e
   `orfas`, `periodo_invalido` e `sobreposicoes_aprovadas` continuam `0`); na
   Q4, os quatro tipos de ausencia com as **mesmas** contagens do passo 4 (a P7
   nao deixa gravar novos); no `audit_log`, so os eventos `ABSENCE_*` das
   acoes feitas depois do deploy. Em seguida, a checagem HTTP de
   `scripts/test-linux.sh` (item 6 de "Atualizacao de Versao").

   Pelo navegador, sem gravar: como gestor, a tela Ausencias abre com filtros,
   "Nova ausencia" e o aviso de nao registrar informacao de saude junto da
   observacao; varios dias desabilitam "A partir de" e "Intervalo"; a excecao
   de escala oferece so os quatro tipos de trabalho, com a nota. Como tecnico:
   so as proprias ausencias, sem formulario e sem acoes. Opcional, com gravacao
   (o registro fica, como cancelado): lancar e cancelar uma ausencia futura de
   um cadastro de teste e conferir no `audit_log` um `ABSENCE_CREATED` e um
   `ABSENCE_CANCELLED` e, na verificacao da 012, `canceladas = 1`.

**Se a verificacao falhar:**

- **A 012 parou com `migration_012 abortada`** (passo 5): nada foi alterado e o
  `git merge` nao rodou (o `pipefail` e o `&&` impedem). O mesmo vale se o
  `git cat-file` ou o `git show` falharem: nada e aplicado nem mesclado. O
  codigo anterior continua no ar sem janela de 503. Envie a contagem de
  `portal_absences` (passo 4); o deploy espera a decisao.
- **A 012 terminou mas o `git merge` falhou** (alteracao local na arvore, ou o
  branch local divergiu e o `--ff-only` recusa): a tela de Ausencias fica em 503
  ate o `merge`. Resolva a causa (item 1 de "Atualizacao de Versao"), **sem
  `fetch` e sem `pull`**, rode so `sudo git merge --ff-only origin/<BRANCH>`,
  confira que `HEAD` e o `f3-commit` (linha seguinte do passo 5) e siga a partir
  da verificacao do passo 5; a 012 ja aplicada repete sem mudar nada, se for
  preciso. Se o `origin/<BRANCH>` tiver mudado (alguem rodou `fetch`), pare: o
  codigo novo nao foi ensaiado.
- **A 012 falhou por outro motivo, ou a verificacao estrutural do passo 5 nao
  bate:** se o `git merge` nao rodou, o codigo anterior esta no ar e so a tela
  de Ausencias responde 503. A 012 confere o `information_schema` antes de cada
  alteracao e pode ser repetida depois de corrigida a causa; para desistir,
  aplique o rollback da 012 (abaixo). Nao rode o build nem a recarga com a
  estrutura incompleta.
- **Erro 500 ou 503 nas telas, health diferente de `200`, ou falha no build:**
  rollback do codigo ("Rollback", acima: `git checkout --detach` do
  `pre-deploy-commit`, build e recarga). A 012 pode ficar aplicada: com ela, o
  codigo anterior so perde a lista de Ausencias (503); calendario, escala,
  motor e relatorios nao usam as colunas que sairam. **Antes, confira
  `linhas` na verificacao da 012:** com `0` (nenhuma ausencia lancada), o
  rollback do codigo e seguro; com ausencias gravadas, leia o aviso de LGPD
  abaixo.
- **`orfas`, `periodo_invalido` ou `sobreposicoes_aprovadas` maior que `0`:** a
  FK, os CHECKs e a trava do servico deveriam impedir. Nao apague nem altere
  linha; mantenha o codigo no ar, envie as contagens e trate como incidente.
- **Q4 com contagem maior nos tipos de ausencia depois do deploy:** a P7 nao
  esta valendo (OPcache sem recarga ou codigo errado). Confira o `git log -1`
  e repita o passo 7.
- Restaurar o backup do passo 2 so como ultimo recurso (secao "Restauracao em
  Incidente"): perde o que foi gravado depois dele.

**Rollback da 012** (so depois do rollback do codigo; o codigo do F3 le as
colunas novas):

```bash
cd /var/www/chronodesk
export F3=$(sudo cat /var/backups/chronodesk/f3-commit)
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$F3:migrations/rollback/20261007_012_absences_down.sql" && bash -o pipefail -c 'sudo git show "$F3:migrations/rollback/20261007_012_absences_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 012 aplicado"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$F3:migrations/verificacao/20261007_012_absences_check.sql" && bash -o pipefail -c 'sudo git show "$F3:migrations/verificacao/20261007_012_absences_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

Os arquivos sao lidos da versao do F3 (`f3-commit`, passo 3): depois do
`git checkout` do codigo anterior eles nao estao na arvore. O `git cat-file -e`
e o `pipefail` impedem que uma falha do `git show` passe calada (entrada vazia
no `mysql`, que sairia com `0`): sem a mensagem "rollback da 012 aplicado", o
rollback nao rodou. Esperado: `colunas_012 = 0`,
`absence_type = 1`, `fk = indice = checks = 0`, `copia_rollback = 1`. O rollback
copia tudo em `portal_absences_bkp_012` (apagar a mao depois de conferir), volta
`absence_type` a partir da categoria (`folga`, `ferias`; o resto
`justificada`), troca `cancelled` por `rejected` e tira as colunas novas: no
codigo anterior, ausencia parcial vira ausencia do dia inteiro. Se ja existir
`portal_absences_bkp_012` (rollback anterior), **para antes de alterar**: confira
a copia, renomeie (`RENAME TABLE portal_absences_bkp_012 TO
portal_absences_bkp_012_<data>`) e repita.

**Atencao antes de qualquer rollback com ausencias gravadas (LGPD, SEC-07 e
L-01):** o codigo anterior ao F3 mostra a observacao da ausencia (`reason`) a
**qualquer perfil autenticado** em dois lugares: no Calendario (descricao do
evento de ausencia, sem filtro de perfil nem de situacao) e na lista de
Ausencias. O escopo de leitura so entrou no F3. Com a 012 aplicada, a lista
responde 503, mas o **Calendario continua lendo `reason`**: o rollback so do
codigo ja expoe as observacoes gravadas depois do deploy; o rollback da 012
expoe tambem pela lista. Com `linhas = 0` nao ha o que expor. Com ausencias
gravadas, prefira corrigir para frente com o codigo do F3 no ar; se o rollback
for inevitavel, o responsavel decide antes como tratar as observacoes (qualquer
`UPDATE` em producao exige aprovacao explicita e backup verificado).

### Deploy que traz o F4 (Parte B, arquivamento de tecnicos)

Roteiro completo, na ordem. O F4 traz a migration 014 (`arquivado_em`,
`arquivado_por` e o CHECK `ck_funcionario_arquivado` em `funcionarios`), o
arquivamento no lugar da remocao (PA, escala, excecoes futuras, ausencias
futuras e pausa aberta encerrados de hoje em diante; o historico fica),
restaurar so pelo admin, `status.php`, contadores do dashboard, solicitacoes
pendentes e Mapa PA de hoje em diante sem arquivados, e a aba Funcionarios com
Arquivar e Restaurar. Nao traz variavel nova no `.env`, nem mudanca em
`.htaccess`, vhost ou dependencia do frontend. Desenho:
`docs/DESENHO_PA_ESCALA_AUSENCIA.md`, secao 9.6. **A Parte B entra num deploy
unico depois do F9:** este roteiro vira a parte do F4 do roteiro consolidado,
na mesma ordem (Q-F4 e 014 antes do `merge`; o passo da D5 depois da recarga).

Todas as consultas deste roteiro devolvem **so contagens e estrutura**, nunca
nome, login nem texto; a unica excecao e a consulta do passo 9, que devolve so
o ID interno do cadastro a regularizar. Onde aparece `<BRANCH>`, use o branch
do deploy; onde aparece `<ServerName do vhost>`, o `ServerName` do VirtualHost
do portal. A senha do root do MySQL e sempre lida sem eco (`read -rsp`) e
apagada no fim do comando (`unset P`); nunca a escreva no comando nem neste
documento.

**Pre-requisitos.** Deploys 1 a 12 no ar. O codigo do F4 le as colunas da 012
(ausencias do F3) e da 011 (`fora_mapa_escala`, Mapa PA); o encerramento da
sessao CI do arquivado depende da revalidacao do A1 (Lote 5a, deploy 6), sem
codigo novo de autenticacao (D2). Conferencia (esperado `migration_011 = 1` e
`migration_012 = 3`):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'fora_mapa_escala') AS migration_011, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS migration_012;"; unset P
```

**Janela.** Sem 503: o codigo anterior funciona sobre o esquema da 014 (nao le
as colunas novas e so grava `ativo = 0`, que o CHECK aceita com as colunas
nulas). Entre o `git merge` (passo 5) e o build do frontend (passo 6), o
navegador ainda carrega a tela anterior: o botao "Desativar" passa a arquivar
(com os efeitos do F4) e desmarcar "Funcionario ativo" na edicao recebe 409
com a mensagem "use o botao Arquivar". Prefira horario de pouco uso e avise
quem cuida dos cadastros para nao desativar ninguem durante o deploy.

1. **Ponto de rollback e pre-checagem**: itens 1 e 2 de "Atualizacao de Versao
   e Rollback" (anota `pre-deploy-commit`, `git status` vazio, `git fetch` e
   preflight da versao nova com `RESULTADO=0`).

2. **Backup**: item 3 da mesma secao. Esperado `backup concluido`; anote o
   diretorio (ponto de restauracao do banco).

3. **Ensaio da 014** em banco descartavel, nas tres variantes, com os arquivos
   da versao nova (antes do `git merge` eles ainda nao estao na arvore). O
   `scripts/ensaio-migration.sh` do servidor ja tem `DADOS`, `VERIFICA` e
   `PREPARO` (deploy 8) e nao mudou no F4. Confira que o clone esta no
   `<BRANCH>` e grave o commit ensaiado: o passo 5 aplica exatamente esse
   commit, sem novo `fetch` no meio.

```bash
cd /var/www/chronodesk
B=$(sudo git rev-parse --abbrev-ref HEAD); [ "$B" = "<BRANCH>" ] && echo "branch OK: $B" || echo "PARE: o clone esta em $B, nao em <BRANCH>"
sudo git rev-parse origin/<BRANCH> | sudo tee /var/backups/chronodesk/f4-commit
E=$(sudo mktemp -d /root/ensaio-f4.XXXXXX); sudo git archive origin/<BRANCH> migrations | sudo tar -x -C "$E"
M=$E/migrations/20261013_014_employee_archive.sql; R=$E/migrations/rollback/20261013_014_employee_archive_down.sql; V=$E/migrations/verificacao/20261013_014_employee_archive_check.sql
sudo env VERIFICA="$V" bash scripts/ensaio-migration.sh f4 "$M" "$R"
sudo env DADOS=portal_pa_assignments,portal_schedule_rules,portal_schedule_exceptions,portal_absences VERIFICA="$V" bash scripts/ensaio-migration.sh f4_dados "$M" "$R"
sudo env PREPARO="$M" VERIFICA="$V" bash scripts/ensaio-migration.sh f4_rollback "$E/migrations/rollback/20261013_014_ensaio_linhas_f4.sql" "$R"
```

   O que conferir (os mesmos tres ensaios aprovados em 2026-10-13; o numero
   de inativos e o da origem no dia, 5 na Q-F4 de 2026-10-13):

   - **(a) `f4`, cadastros:** a 1a e a 2a execucao da migration terminam no
     mesmo estado, com `colunas_014 = 2`, `check_014 = 1`, `copia_rollback = 0`,
     `arquivados_f4 = 0`, `inativos_sem_data` igual a `inativos`,
     `ativos_com_data = 0` e `arquivados_sem_autor = 0`; as contagens de efeitos
     pendentes vem `NULL` (as tabelas de PA, escala e ausencia nao sao copiadas
     nesta variante). A 2a execucao imprime `1` pelos `SELECT 1` (cosmetico,
     como nas migrations anteriores). O 1o rollback volta a `colunas_014 = 0`,
     `check_014 = 0`, `copia_rollback = 1`, com `inativos` igual. O **2o
     rollback para** com `rollback_014 abortado: ja existe funcionarios_bkp_014
     ...` (FALHOU no ensaio, de proposito) e o estado fica igual ao do 1o.
   - **(b) `f4_dados`, linhas reais de PA, escala, excecao e ausencia:**
     `inativos`, `inativos_com_pa`, `inativos_com_regra` e
     `excecoes_de_inativos` iguais aos da Q-F4 (passo 4) e iguais em todos os
     estados (a 014 nao altera linha); `ausencias_de_inativos` so existe na
     verificacao (anote); `pa_periodo_invertido = 0` (diferente de `0` e dado
     antigo: anote e envie, nao bloqueia).
   - **(c) `f4_rollback`, rollback com cadastros arquivados:** depois do
     arquivo de linhas, `arquivados_f4 = 2` (1 se nao houver inativo),
     `inativos` = inativos da origem + 1, `ativos_com_data = 0` e
     `arquivados_sem_autor = 0` (igual na 2a vez); depois do 1o rollback,
     `colunas_014 = 0`, `check_014 = 0`, `copia_rollback = 1` e `inativos`
     igual ao de antes do rollback (`ativo = 0` fica); o 2o rollback para de
     proposito.

   Qualquer resultado diferente: **pare o deploy** (nada foi alterado no banco
   real) e envie as contagens. No fim, apague o diretorio temporario
   (`sudo rm -r "$E"`; confira antes que `$E` comeca com `/root/ensaio-f4.`).

4. **Q-F4 imediatamente antes da 014** (D5: so contagens, sem depender da 011
   nem da 012):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0) AS inativos, (SELECT COUNT(DISTINCT a.employee_id) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())) AS inativos_com_pa, (SELECT COUNT(DISTINCT r.employee_id) FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 0 WHERE (r.effective_until IS NULL OR r.effective_until >= CURDATE()) AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from)) AS inativos_com_regra, (SELECT COUNT(*) FROM portal_schedule_exceptions e JOIN funcionarios f ON f.id = e.employee_id AND f.ativo = 0 WHERE e.exception_date >= CURDATE()) AS excecoes_de_inativos;"; unset P
```

   Referencia (2026-10-13): `inativos = 5`, `inativos_com_pa = 1`,
   `inativos_com_regra = 0`, `excecoes_de_inativos = 0`. Anote os numeros: o
   passo 9 depende deles. Numeros diferentes **nao bloqueiam a 014** (ela nao
   altera linha), mas mudam o passo 9: com `inativos_com_pa` diferente de `1`,
   ou `inativos_com_regra` ou `excecoes_de_inativos` maior que `0`, envie as
   contagens ao responsavel antes do passo 9 (D5 fechada so para o caso de
   2026-10-13: um cadastro com PA aberto).

5. **014 e `git merge` em sequencia**, no mesmo comando. O `git cat-file -e`
   confere antes que o arquivo existe na versao nova, e a aplicacao roda com
   `pipefail`: sem ele, se o `git show` falhasse, o `mysql` receberia entrada
   vazia, sairia com `0` e o `merge` rodaria sem a 014 (o codigo do F4 le as
   colunas novas). Assim o `merge` so acontece se o `git show` e o `mysql`
   derem certo. O `merge --ff-only` de `origin/<BRANCH>` aplica o commit do
   ensaio (sem `fetch`); a linha seguinte confirma. Em seguida, a verificacao:

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e origin/<BRANCH>:migrations/20261013_014_employee_archive.sql && bash -o pipefail -c 'sudo git show origin/<BRANCH>:migrations/20261013_014_employee_archive.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && sudo git merge --ff-only origin/<BRANCH>; unset P
[ "$(sudo git rev-parse HEAD)" = "$(sudo cat /var/backups/chronodesk/f4-commit)" ] && echo "HEAD = commit do ensaio" || echo "PARE: HEAD diferente do commit do ensaio"
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
```

   Esperado: `colunas_014 = 2`, `check_014 = 1`, `copia_rollback = 0`,
   `arquivados_f4 = 0`, `inativos_sem_data` igual a `inativos` (5 na
   referencia), `ativos_com_data = 0`, `arquivados_sem_autor = 0`; os efeitos
   pendentes iguais aos do passo 4 e `pa_periodo_invertido` como no ensaio. O
   `diff --stat` lista so os arquivos da 014 em `migrations/` (os do F4: a
   migration, o rollback, as linhas de ensaio e a verificacao). O `f4-commit`
   (passo 3) guarda a versao do F4, de onde o rollback da 014 le os arquivos.

6. **Build do frontend**:

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
```

7. **Recarga do PHP-FPM** (limpa o OPcache; `reload` e gracioso). O F4 nao muda
   vhost nem `.htaccess`: a recarga do Apache nao e necessaria.

```bash
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

8. **Verificacao pos-deploy, so com contagens.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT action, COUNT(*) AS n FROM audit_log WHERE action LIKE 'FUNCIONARIO\_%' AND timestamp >= CURDATE() GROUP BY action;"; unset P
```

   Esperado: health `200`; a verificacao da 014 como no passo 5; no
   `audit_log`, so os eventos das acoes feitas hoje (antes do passo 9, nenhum
   `FUNCIONARIO_ARQUIVADO`). Em seguida, a checagem HTTP de
   `scripts/test-linux.sh` (item 6 de "Atualizacao de Versao").

   Pelo navegador, sem gravar: como admin, Aprovacoes e admin > Funcionarios
   abre no filtro "Ativos", sem o checkbox "Funcionario ativo" no formulario;
   no filtro "Arquivados", os inativos de antes do F4 aparecem como "Inativo
   sem data", com "Arquivar (regularizar)" e "Restaurar"; clicar em "Arquivar"
   num ativo abre o painel na linha com o texto dos efeitos (clique em
   "Voltar", sem confirmar). Como Lideranca: o mesmo, sem "Restaurar" e sem
   "Arquivar" em cadastro da equipe Lideranca. Como gestor: Administracao so
   com Aprovacoes. O painel de pausas, o dashboard e o Mapa PA de hoje nao
   mostram inativo.

9. **Passo pos-deploy obrigatorio (D5): regularizar o inativo com PA aberto.**
   So com os numeros do passo 4 iguais a referencia (`inativos_com_pa = 1`,
   `inativos_com_regra = 0`, `excecoes_de_inativos = 0`); com outros numeros,
   pare e avise o responsavel antes de arquivar. Os outros inativos **nao** sao
   tocados (ficam "Inativo sem data").

   (1) Confira a verificacao do passo 8: `inativos_com_pa = 1`. (2) Descubra o
   cadastro: a consulta devolve **so o ID interno**, sem nome nem login:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT DISTINCT a.employee_id AS id_cadastro FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE());"; unset P
```

   (3) Como admin, em Funcionarios > filtro "Arquivados", procure a linha com
   esse ID ("ID n" abaixo do nome), clique em "Arquivar (regularizar)" e
   confirme. Esperado: "Cadastro inativo regularizado e arquivado. 1 vinculo de
   PA encerrado." (ou "removido", se o vinculo comecar depois de hoje).
   (4) Rode a verificacao da 014 de novo:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
```

   Esperado: `inativos_com_pa = 0`, `arquivados_f4 = 1`,
   `inativos_sem_data = 4`, `ativos_com_data = 0`, `arquivados_sem_autor = 0`;
   no `audit_log` (consulta do passo 8), um `FUNCIONARIO_ARQUIVADO`. Registre
   os numeros no registro do deploy.

**Se a verificacao falhar:**

- **A 014 falhou** (passo 5; ou o `git cat-file` ou o `git show` falharam):
  nada foi mesclado (o `pipefail` e o `&&` impedem) e o codigo anterior
  continua no ar, sem janela. A 014 confere o `information_schema` antes de
  cada alteracao e pode ser repetida depois de corrigida a causa; envie a
  mensagem de erro.
- **A 014 terminou mas o `git merge` falhou** (alteracao local na arvore, ou o
  branch local divergiu e o `--ff-only` recusa): o codigo anterior continua no
  ar e funciona sobre o esquema novo. Resolva a causa (item 1 de "Atualizacao
  de Versao"), **sem `fetch` e sem `pull`**, rode so
  `sudo git merge --ff-only origin/<BRANCH>`, confira que `HEAD` e o
  `f4-commit` (linha seguinte do passo 5) e siga a partir da verificacao do
  passo 5. Se o `origin/<BRANCH>` tiver mudado (alguem rodou `fetch`), pare: o
  codigo novo nao foi ensaiado.
- **`ativos_com_data` ou `arquivados_sem_autor` maior que `0`:** o CHECK e o
  servico deveriam impedir. Nao apague nem altere linha; mantenha o codigo no
  ar, envie as contagens e trate como incidente.
- **Erro 500 ou 503 nas telas, health diferente de `200`, ou falha no build:**
  rollback do codigo ("Rollback", acima: `git checkout --detach` do
  `pre-deploy-commit`, build e recarga). A 014 pode ficar aplicada: o codigo
  anterior funciona sobre ela. **Limite:** no codigo anterior, editar um
  cadastro arquivado pelo F4 manda `ativo = true` com a data preenchida e o
  CHECK recusa (500, falha fechada); para editar esse cadastro, aplique tambem
  o rollback da 014 (abaixo). Com `arquivados_f4 = 0` isso nao acontece.
- **Passo 9 com resultado diferente** (por exemplo `inativos_com_pa` continua
  `1`, ou a tela recusou): nao repita nem arquive outro cadastro; envie a
  verificacao e a mensagem da tela.
- Restaurar o backup do passo 2 so como ultimo recurso (secao "Restauracao em
  Incidente"): perde o que foi gravado depois dele.

**Rollback da 014** (so depois do rollback do codigo; o codigo do F4 le e grava
as colunas novas):

```bash
cd /var/www/chronodesk
export F4=$(sudo cat /var/backups/chronodesk/f4-commit)
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$F4:migrations/rollback/20261013_014_employee_archive_down.sql" && bash -o pipefail -c 'sudo git show "$F4:migrations/rollback/20261013_014_employee_archive_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 014 aplicado"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$F4:migrations/verificacao/20261013_014_employee_archive_check.sql" && bash -o pipefail -c 'sudo git show "$F4:migrations/verificacao/20261013_014_employee_archive_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

Os arquivos sao lidos da versao do F4 (`f4-commit`, passo 3): depois do
`git checkout` do codigo anterior eles nao estao na arvore. Sem a mensagem
"rollback da 014 aplicado", o rollback nao rodou. Esperado: `colunas_014 = 0`,
`check_014 = 0`, `copia_rollback = 1`, `inativos` igual ao de antes. Nenhuma
linha muda: quem foi arquivado continua com `ativo = 0` (inativo no codigo
anterior); PA, escala e ausencias encerrados pelo arquivamento **nao voltam**
(o rollback so tira colunas). A data e o autor ficam na copia
`funcionarios_bkp_014` (so `id`, `ativo`, `arquivado_em`, `arquivado_por`) e na
auditoria. A copia guarda o usuario de quem arquivou (LGPD): apague-a a mao
(`DROP TABLE funcionarios_bkp_014`) depois de conferir, com aprovacao. Se ja
existir `funcionarios_bkp_014` (rollback anterior), o rollback **para antes de
alterar** (`rollback_014 abortado`): confira a copia, renomeie
(`RENAME TABLE funcionarios_bkp_014 TO funcionarios_bkp_014_<data>`) e repita.

**Operacao depois do deploy.** Arquivar o cadastro de um login que esta em
`AD_ADMIN_USERS` nao corta o acesso administrativo (a tela avisa): para cortar,
tire o login do `.env` e recarregue o PHP-FPM (D3). A sessao CI do arquivado e
encerrada na requisicao seguinte (`SESSION_REVOKED` com `cadastro_inativo`).
Restaurar nao devolve PA, escala nem ausencias: cadastre-os de novo.

### Deploy que traz o F5 (Parte B, Calendario e relatorios no motor a partir da data de corte)

Roteiro completo, na ordem. O F5 traz a migration 019 (tabela
`portal_presence_cutoff`, vazia, com os gatilhos de imutabilidade), o script
de registro da data de corte (rodado so no fim, depois da consulta da 16c), o
relatorio, o CSV e o Calendario divididos no corte (antes dele, o calculo
antigo sem nenhuma mudanca; a partir dele, o motor), a legenda unica no
Calendario e na tela Escala, o filtro "Ocultar sem escala", os campos
`categoria` e `parcial` (aditivos) no Calendario e `parcial` na tela Escala.
Nao traz variavel nova no `.env`, nem mudanca em `.htaccess`, vhost ou
dependencia do frontend. Desenho: `docs/DESENHO_PA_ESCALA_AUSENCIA.md`, secao
9.7. **A Parte B entra num deploy unico depois do F9:** este roteiro vira a
parte do F5 do roteiro consolidado, na mesma ordem (019 antes do `merge`; o
registro do corte depois da recarga e da consulta da 16c).

Todas as consultas deste roteiro devolvem **so contagens, estrutura e a data
do corte**, nunca nome, login nem texto (a verificacao da 019 nao mostra
`registered_by`). Onde aparece `<BRANCH>`, use o branch do deploy; onde
aparece `<ServerName do vhost>`, o `ServerName` do VirtualHost do portal; onde
aparece `<login do operador>`, o login AD de quem roda o registro (fica em
`registered_by`, como autor). A senha do root do MySQL e sempre lida sem eco
(`read -rsp`) e apagada no fim do comando (`unset P`); nunca a escreva no
comando nem neste documento.

**Regra de parada em todo o roteiro: a verificacao da 019 com
`inconsistente = 1` manda PARAR e avisar o responsavel** (corte apagado sem o
rollback, por exemplo por `TRUNCATE`, ou linha gravada fora do script de
registro). Nao apague nem altere linha; nao rode o registro. Uma vez `1`,
continua `1` (os eventos de auditoria sao de todos os tempos).

**Pre-requisitos.** Deploys 1 a 12 no ar e o F4 aplicado (no roteiro
consolidado, a parte do F4 vem antes desta). O codigo do F5 le as colunas da
011 (`fora_mapa_escala`), da 012 (ausencias do F3) e da 014 (`arquivado_em`,
historico do arquivado ate a vespera). Os gatilhos da 019 usam
`CREATE TRIGGER IF NOT EXISTS`: **MySQL 8.0.29 ou mais novo**. Conferencia
(esperado `mysql` 8.0.29 ou maior, `migrations_011_014 = 3` e
`migration_012 = 3`):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT VERSION() AS mysql, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS migrations_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS migration_012;"; unset P
```

**Janela.** Sem 503 e sem mudanca de numeros ate o registro: o codigo anterior
nao le a tabela da 019, e o codigo do F5 sem corte registrado (tabela ausente
ou vazia) usa o calculo antigo em todas as datas. Entre o `git merge` (passo
6) e o build (passo 7), o navegador ainda carrega a tela anterior, que mostra
os campos novos so pelo titulo (sem perda). O que muda ja na recarga, mesmo
sem corte: a tela Escala com a legenda e o "Ocultar sem escala"; o filtro de
status de presenca do Calendario (`Presencial`, `Remoto`, `Ausencia`,
`Sem escala`, `Folga`) passa a filtrar a escala antiga pela modalidade.

1. **Ponto de rollback e pre-checagem**: itens 1 e 2 de "Atualizacao de Versao
   e Rollback" (anota `pre-deploy-commit`, `git status` vazio, `git fetch` e
   preflight da versao nova com `RESULTADO=0`).

2. **Backup**: item 3 da mesma secao. Esperado `backup concluido`; anote o
   diretorio (ponto de restauracao do banco).

3. **Branch, commit do ensaio, arquivos da versao nova e verificacao da 019
   antes de tudo.** Os arquivos da 019 ainda nao estao na arvore (antes do
   `git merge`): saem do commit com `git archive` para um diretorio
   temporario. O `f5-commit` guarda o commit ensaiado: o passo 6 aplica
   exatamente esse commit, sem novo `fetch` no meio.

```bash
cd /var/www/chronodesk
B=$(sudo git rev-parse --abbrev-ref HEAD); [ "$B" = "<BRANCH>" ] && echo "branch OK: $B" || echo "PARE: o clone esta em $B, nao em <BRANCH>"
sudo git rev-parse origin/<BRANCH> | sudo tee /var/backups/chronodesk/f5-commit
E=$(sudo mktemp -d /root/ensaio-f5.XXXXXX); sudo git archive origin/<BRANCH> migrations | sudo tar -x -C "$E"; M=$E/migrations/20261013_019_presence_cutoff.sql; R=$E/migrations/rollback/20261013_019_presence_cutoff_down.sql; V=$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql; G=$E/migrations/20261013_019_registrar_corte.sql; sudo ls "$M" "$R" "$V" "$G"
read -rsp 'Senha root do MySQL: ' P; echo; sudo cat "$V" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas; unset P
```

   Esperado (estado inicial, banco real): `tabela_019 = 0`, `checks_019 = 0`,
   `gatilhos_019 = 0`, `copia_rollback = 0`, `procedimentos_soltos = 0`, o
   aviso de tabela ausente no lugar do corte, `eventos_registro = 0`,
   `eventos_remocao = 0` e `inconsistente = 0`. Diferente disso (por exemplo
   `copia_rollback = 1` ou eventos de um registro anterior): **pare** e envie
   as contagens.

4. **Ensaio da 019** em banco descartavel, nas tres variantes, com os
   arquivos do passo 3. O `scripts/ensaio-migration.sh` do servidor ja tem
   `PREPARO` e `VERIFICA` (deploy 8) e nao mudou no F5; `audit_log` entra
   como tabela extra (o registro e o rollback auditam). Cada variante roda a
   migration (ou o registro) duas vezes e o rollback duas vezes, com a
   verificacao em cada estado.

```bash
cd /var/www/chronodesk; sudo env VERIFICA="$V" bash scripts/ensaio-migration.sh f5 "$M" "$R" audit_log
{ printf "SET @corte := '2099-12-16'; SET @autor := 'qa.ensaio';\n"; sudo cat "$G"; } | sudo tee "$E/registro_f5.sql" > /dev/null; sudo env PREPARO="$M" VERIFICA="$V" bash scripts/ensaio-migration.sh f5_registro "$E/registro_f5.sql" "$R" audit_log
{ printf "SET @corte := '2020-01-16'; SET @autor := 'qa.ensaio';\n"; sudo cat "$G"; } | sudo tee "$E/registro_f5_passado.sql" > /dev/null; sudo env PREPARO="$M" VERIFICA="$V" bash scripts/ensaio-migration.sh f5_passado "$E/registro_f5_passado.sql" "$R" audit_log
```

   O que conferir:

   - **(a) `f5`, a migration:** a 1a e a 2a execucao terminam no mesmo
     estado, com `tabela_019 = 1`, `checks_019 = 2`, `gatilhos_019 = 2`,
     `copia_rollback = 0`, `procedimentos_soltos = 0`, `cortes = 0`,
     `sem_autor = 0` e `inconsistente = 0`. O 1o rollback volta a
     `tabela_019 = 0` e `gatilhos_019 = 0`, com `copia_rollback = 1`. O **2o
     rollback para** com `rollback_019 abortado: ja existe
     portal_presence_cutoff_bkp_019 ...` (FALHOU no ensaio, de proposito) e o
     estado fica igual ao do 1o.
   - **(b) `f5_registro`, registro do corte com a 019 aplicada (PREPARO):** o
     1o registro grava (`cortes = 1`, `corte = 2099-12-16`,
     `corte_dia_16 = 1`, `corte_no_futuro = 1`, `sem_autor = 0`,
     `eventos_registro = 1`, `inconsistente = 0`); o 2o **para** com
     `registro_019 abortado: corte ja registrado (imutavel)` e o estado fica
     igual (o procedimento recusado fica no banco de ensaio:
     `procedimentos_soltos = 1` e esperado aqui). O 1o rollback copia a linha
     e remove a tabela (`tabela_019 = 0`, `copia_rollback = 1`,
     `eventos_remocao = 1`, `inconsistente = 0`); o 2o para de proposito.
   - **(c) `f5_passado`, data passada:** as duas execucoes **param** com
     `registro_019 abortado: data anterior a hoje` (`cortes = 0`,
     `eventos_registro = 0`, `inconsistente = 0`; `procedimentos_soltos = 1`,
     como em (b)); o rollback remove a tabela vazia.

   Qualquer resultado diferente, ou `inconsistente = 1` em qualquer estado:
   **pare o deploy** (nada foi alterado no banco real) e envie as contagens.
   O diretorio temporario fica ate o fim do passo 6 (o `$V` e usado de novo
   antes do `merge`); apague-o no passo 8.

5. **Privilegios do usuario do banco da aplicacao (pendencia 16b, VULN-002).**
   O `TRUNCATE` nao passa pelos gatilhos da 019 (o MySQL nao dispara gatilho
   nele) e exige `DROP`; com `TRIGGER`, os gatilhos podem ser removidos. O
   usuario da aplicacao (`DB_USER` do `.env`) nao deve ter nenhum dos dois. A
   consulta devolve so a contagem de concessoes desses dois privilegios
   (global, por banco e por tabela), sem senha:

```bash
U=$(sudo grep -E '^DB_USER=' /var/www/.env | cut -d= -f2- | tr -d "\"'"); read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT '$U' <> '' AS usuario_lido, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'DROP') AS concessoes_drop, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'TRIGGER') AS concessoes_trigger;"; unset P U
```

   Esperado: `usuario_lido = 1`, `concessoes_drop = 0` e
   `concessoes_trigger = 0`. Com `usuario_lido = 0`, confira o nome da
   variavel no `.env`. (Corrigido em 2026-11-06: a linha lia o `DB_USER`
   de `/var/www/chronodesk/.env`; o `.env` em uso e o `/var/www/.env`.) Com `DROP` ou `TRIGGER` maior que `0`: **nao bloqueia
   este deploy por si** (a verificacao da 019 acusa o `TRUNCATE` com
   `inconsistente = 1`), mas anote e avise o responsavel; a correcao (tirar
   os dois privilegios do usuario da aplicacao) e decisao dele, ligada a
   VULN-002. Para conferir a mao, `SHOW GRANTS FOR` o usuario e o host
   (mostra os privilegios, nunca a senha).

6. **019 e `git merge` em sequencia**, no mesmo comando. O `git cat-file -e`
   confere antes que o arquivo existe na versao nova, e a aplicacao roda com
   `pipefail`: sem ele, se o `git show` falhasse, o `mysql` receberia entrada
   vazia, sairia com `0` e o `merge` rodaria sem a 019. O codigo do F5
   funciona sem a tabela (calculo antigo em todas as datas), mas o registro do
   corte depende dela. O `merge --ff-only` de `origin/<BRANCH>` aplica o
   commit do ensaio (sem `fetch`); a linha seguinte confirma. Em seguida, a
   verificacao da 019:

```bash
cd /var/www/chronodesk
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e origin/<BRANCH>:migrations/20261013_019_presence_cutoff.sql && bash -o pipefail -c 'sudo git show origin/<BRANCH>:migrations/20261013_019_presence_cutoff.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && sudo git merge --ff-only origin/<BRANCH>; unset P
[ "$(sudo git rev-parse HEAD)" = "$(sudo cat /var/backups/chronodesk/f5-commit)" ] && echo "HEAD = commit do ensaio" || echo "PARE: HEAD diferente do commit do ensaio"
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
```

   Esperado: `tabela_019 = 1`, `checks_019 = 2`, `gatilhos_019 = 2`,
   `copia_rollback = 0`, `procedimentos_soltos = 0`, `cortes = 0`,
   `sem_autor = 0`, `eventos_registro = 0`, `eventos_remocao = 0` e
   **`inconsistente = 0`**. O `diff --stat` lista em `migrations/` so os
   arquivos da 019 (a migration, o registro, o rollback e a verificacao). A
   2a execucao da 019 (se repetida) imprime `1` pelos `SELECT 1`
   (cosmetico).

7. **Build do frontend e recarga do PHP-FPM** (limpa o OPcache; `reload` e
   gracioso). O F5 nao muda vhost nem `.htaccess`: a recarga do Apache nao e
   necessaria.

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

8. **Verificacao pos-deploy, ainda sem corte, so com contagens.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < /var/www/chronodesk/migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
```

   Esperado: health `200`; a verificacao da 019 como no passo 6
   (`inconsistente = 0`). Em seguida, a checagem HTTP de
   `scripts/test-linux.sh` (item 6 de "Atualizacao de Versao"). No fim,
   apague o diretorio temporario do passo 3 (`sudo rm -r "$E"`; confira antes
   que `$E` comeca com `/root/ensaio-f5.`).

   Pelo navegador, sem gravar e sem anotar nomes: o Calendario e o relatorio
   de qualquer competencia **iguais aos de antes** (sem corte, nada muda); a
   tela Escala com a legenda (icone, cor e forma), o "Ocultar sem escala"
   (desligado) e a coluna Origem com "Regra de escala"; no Calendario, o
   filtro de status "Sem escala" sem nenhum evento e "Presencial" so com
   escalas presenciais.

9. **Pendencia 16c: nenhum tecnico ativo de N1 ou N2 sem regra vigente em
   nenhum dia da primeira competencia do motor.** Com o corte, cada dia sem
   regra aparece como "Sem escala: Nome" no Calendario, para todos os
   perfis, e conta como dia sem escala no relatorio (em 2026-10-14 a contagem
   no dia do corte deu 6 no servidor, com 22 ativos). Primeiro, a data do
   corte (D2: o 1o dia da competencia seguinte ao deploy, competencia de 16 a
   15; pode estar no futuro), guardada em `C` para os passos 9 e 10:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; C=$(docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT IF(DAYOFMONTH(CURDATE()) >= 16, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16'));"); unset P; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

   As duas colunas tem de dar `0`: `sem_regra_no_corte` (sem regra vigente
   no dia do corte) e `sem_regra_na_competencia` (algum dia de `@corte` ate
   `@corte` + 1 mes - 1 dia sem regra vigente: regra que termina dentro da
   competencia, que comeca depois do corte ou buraco entre duas regras).
   **Com qualquer das duas maior que 0, o registro do corte nao roda**
   (decisao do responsavel): a gestao cadastra a regra de cada um ou marca o
   cadastro como "fora do Mapa" pela tela, e a consulta roda de novo. O
   deploy em si fica no ar (sem corte, tudo no calculo antigo): o registro
   pode ser feito depois, no mesmo dia ou noutro antes da data, repetindo os
   passos 9 e 10. Regra `undefined` conta como sem regra (P4).
   **Limitacao declarada:** regra `cycle` com configuracao invalida conta
   como regra na consulta, mas o motor mostra o dia como "Sem escala"; a
   consulta nao a acusa.

   Validacao da consulta: no MySQL local do responsavel (2026-10-14), com
   regras de teste (uma que comeca em 20/10, uma que termina em 31/10, uma
   com buraco de 26/10 a 04/11 e uma aberta), 13 ativos,
   `sem_regra_no_corte = 8` e `sem_regra_na_competencia = 10`, os dois
   corretos. **No servidor: pendente** (preencher: `ativos_n1_n2 = 22` e
   `sem_regra_no_corte = 6` reproduzem a contagem do responsavel;
   `sem_regra_na_competencia = N`).

10. **Registro do corte**, so com as duas colunas do passo 9 em `0`, a
    verificacao da 019 com `inconsistente = 0` (passo 8 ou repetida agora) e
    o `C` do passo 9 na mesma sessao do shell. O script recusa (SIGNAL,
    nada alterado) data passada, dia diferente de 16, data invalida e corte
    ja registrado (imutavel). Grava a linha e o evento
    `PRESENCE_CUTOFF_REGISTERED` (so a data) na mesma transacao.

```bash
cd /var/www/chronodesk; echo "corte a registrar: $C"; read -rsp 'Senha root do MySQL: ' P; echo; { printf "SET @corte := '%s'; SET @autor := '%s';\n" "$C" '<login do operador>'; cat migrations/20261013_019_registrar_corte.sql; } | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
```

    Esperado: `cortes = 1`, `corte` = a data do passo 9, `corte_dia_16 = 1`,
    `corte_no_futuro = 1` (o corte e sempre depois do dia do deploy),
    `sem_autor = 0`, `eventos_registro = 1`, `eventos_remocao = 0`,
    `procedimentos_soltos = 0` e **`inconsistente = 0`**. Com
    `registro_019 abortado: ...`, nada foi gravado: confira `C` e a
    mensagem, remova o procedimento que ficou
    (`DROP PROCEDURE IF EXISTS registrar_corte_019`, a verificacao conta) e
    envie a mensagem; nao tente gravar a linha a mao.

    Pelo navegador, sem gravar e sem anotar nomes (o corte vale por data, nao
    por hoje: competencias a partir dele ja mostram o motor):
    - Calendario: a competencia anterior ao corte igual a de antes; a partir
      da data do corte, o resumo do dia ("P · R · A · S") no Mes, um cartao
      por tecnico na Semana, a legenda do motor no rodape e ausencia como
      situacao; a categoria so como gestor e como o proprio; observacao
      nunca.
    - Relatorio da competencia que comeca no corte: aviso "Escala pelas
      regras novas (corte em DD/MM/AAAA)." e "Dias de ausencia" e "Dias sem
      escala"; o da competencia anterior sem aviso e com os numeros de antes;
      o CSV da competencia anterior sem linha `aviso`.
    - Anote no registro do deploy so as contagens da verificacao e a data do
      corte.

**Se a verificacao falhar:**

- **`inconsistente = 1` em qualquer passo:** pare, nao rode o registro, nao
  apague nem altere linha e avise o responsavel com as contagens.
- **A 019 falhou** (passo 6; ou o `git cat-file` ou o `git show` falharam):
  nada foi mesclado (o `pipefail` e o `&&` impedem) e o codigo anterior
  continua no ar. A 019 confere o `information_schema` antes de criar a
  tabela e usa `IF NOT EXISTS` nos gatilhos: pode ser repetida depois de
  corrigida a causa; envie a mensagem de erro. Erro de sintaxe no
  `CREATE TRIGGER IF NOT EXISTS`: o MySQL e anterior ao 8.0.29 (pre-requisito).
- **A 019 terminou mas o `git merge` falhou**: como no F4 (o codigo anterior
  continua no ar e nao le a tabela). Resolva a causa, **sem `fetch` e sem
  `pull`**, rode so `sudo git merge --ff-only origin/<BRANCH>`, confira que
  `HEAD` e o `f5-commit` e siga a partir da verificacao do passo 6.
- **Consulta da 16c maior que 0:** nao e falha do deploy; o registro espera
  (passo 9).
- **Erro 500 ou 503 nas telas, health diferente de `200`, ou falha no build:**
  rollback do codigo ("Rollback", acima: `git checkout --detach` do
  `pre-deploy-commit`, build e recarga). A 019 e o corte podem ficar: o
  codigo anterior nao le a tabela, e relatorio e Calendario voltam ao
  calculo antigo em todas as datas, sem perda de dado.
- **Corte registrado na data errada:** o corte e imutavel (os gatilhos barram
  `UPDATE` e `DELETE`). Trocar so com aprovacao do responsavel: rollback da
  019 (abaixo, audita `PRESENCE_CUTOFF_REMOVED`), a 019 de novo (passo 6, so
  a parte da migration, com `cat-file` e `pipefail`) e os passos 9 e 10.
- Restaurar o backup do passo 2 so como ultimo recurso (secao "Restauracao em
  Incidente"): perde o que foi gravado depois dele.

**Rollback da 019** (so com aprovacao; antes, o rollback do codigo se o
objetivo for voltar ao calculo antigo, ou nao, se for so trocar a data do
corte):

```bash
cd /var/www/chronodesk; export F5=$(sudo cat /var/backups/chronodesk/f5-commit)
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$F5:migrations/rollback/20261013_019_presence_cutoff_down.sql" && bash -o pipefail -c 'sudo git show "$F5:migrations/rollback/20261013_019_presence_cutoff_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 019 aplicado"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; export P; sudo git cat-file -e "$F5:migrations/verificacao/20261013_019_presence_cutoff_check.sql" && bash -o pipefail -c 'sudo git show "$F5:migrations/verificacao/20261013_019_presence_cutoff_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

Os arquivos sao lidos da versao do F5 (`f5-commit`, passo 3): depois de um
`git checkout` do codigo anterior eles nao estao na arvore. Sem a mensagem
"rollback da 019 aplicado", o rollback nao rodou. Esperado: `tabela_019 = 0`,
`gatilhos_019 = 0`, `copia_rollback = 1`, `eventos_remocao` = 1 se havia corte
e `inconsistente = 0`. Sem a tabela, o codigo do F5 volta ao calculo antigo
em todas as datas. A copia `portal_presence_cutoff_bkp_019` guarda a data e o
login de quem registrou (LGPD): apague-a a mao
(`DROP TABLE portal_presence_cutoff_bkp_019`) depois de conferir, com
aprovacao. Se ela ja existir (rollback anterior), o rollback **para antes de
alterar** (`rollback_019 abortado`): confira a copia, renomeie
(`RENAME TABLE portal_presence_cutoff_bkp_019 TO portal_presence_cutoff_bkp_019_<data>`)
e repita.

### Deploy que traz o F6 (Parte B, Mapa PA no motor)

Roteiro completo, na ordem. O F6 traz o Mapa PA pelo motor de presenca: a
situacao de cada vinculo pela regra vigente (nao mais pela copia gravada no
vinculo), o conflito por PA e dia util da semana (dois ou mais presenciais),
calculado na leitura e mostrado como aviso, a gravacao sem 409 por conflito,
remoto, ausencia ou sem escala (salva, avisa e audita
`PA_MAP_CONFLICT_ACCEPTED`), a aba "Ausencias da semana", a DF7 (logins,
observacao, autores e lista de elegiveis so para admin e Lideranca) e a tela
nova; junto, os tons de `inline-alert`, `metric-card`, `status-dot`,
`detail-pill` e `operator-card` que faltavam no CSS gerado. **So codigo: sem
migration**, sem variavel nova no `.env`, sem mudanca em `.htaccess`, vhost ou
dependencia do frontend (o `package.json` so ganhou o script `qa:pa-map`; o
`package-lock.json` nao mudou). Contrato: `docs/API_CONTRACTS_FRONTEND.md`,
linha `portal/pa_map.php`. Desenho: `docs/DESENHO_PA_ESCALA_AUSENCIA.md`,
secao 9.8. **A Parte B entra num deploy unico depois do F9:** este roteiro vira
a parte do F6 do roteiro consolidado (Q-F6 depois das migrations e de novo
depois do passo da D5 do F4; ver o passo 3).

Todas as consultas deste roteiro devolvem **so contagens**, nunca nome, login
nem texto. Onde aparece `<BRANCH>`, use o branch do deploy; onde aparece
`<ServerName do vhost>`, o `ServerName` do VirtualHost do portal. A senha do
root do MySQL e sempre lida sem eco (`read -rsp`) e apagada no fim do comando
(`unset P`); nunca a escreva no comando nem neste documento.

**Pre-requisitos.** Deploys 1 a 12 no ar e o F4 e o F5 aplicados (no roteiro
consolidado, as partes do F4 e do F5 vem antes desta), inclusive o passo da
D5 do F4. O codigo do F6 le as colunas da 011 (`fora_mapa_escala`), da 012
(ausencias do F3) e da 014 (`arquivado_em`, arquivado ate a vespera no
motor); **nao le a 019** (o mapa nao segue a data de corte, DF1). Conferencia
(esperado `migrations_011_014 = 3` e `migration_012 = 3`):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS migrations_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS migration_012;"; unset P
```

**Antes do dia do deploy: aviso a gestao (P13).** O conflito do F6 olha cada
dia, e PAs compartilhados que hoje nao aparecem em conflito passam a aparecer
no primeiro dia. Pela Q-F6 de 2026-10-15 (`pas_compartilhados_hoje = 6`, com
`vinculos_lideranca = 2`), **ate 6 PAs** podem aparecer em conflito; use o
numero da Q-F6 mais recente (passo 3, ou a medicao antecipada). E aviso, nao
bloqueio. Texto sugerido, a quem cuida do Mapa PA (Lideranca e gestores):

> A partir de DD/MM, o Mapa PA passa a mostrar a situacao de cada tecnico pela
> escala do dia e a marcar, com contorno vermelho e os dias da semana, o PA em
> que duas ou mais pessoas estao presenciais no mesmo dia. Pelos numeros de
> hoje, ate N PAs podem aparecer em conflito ja no primeiro dia. O conflito e
> um aviso: o sistema nao impede salvar a alocacao e registra o conflito
> aceito na auditoria. Para resolver, mova um dos tecnicos de PA ou ajuste a
> escala. Gestores, tecnicos e perfis de leitura deixam de ver no mapa os
> logins e as observacoes dos vinculos; so admin e Lideranca editam o mapa.

**Janela.** Sem 503 e sem alteracao de dado: o F6 nao muda o esquema nem
regrava linha (a copia da regra continua gravada a cada `save`). A partir do
`git merge` (passo 4): a gravacao no mapa deixa de recusar conflito, remoto,
ausencia e sem escala (salva e avisa), e o `GET` deixa de enviar logins,
observacao, autores e elegiveis a quem nao edita. Entre o `git merge` e o
build (passo 5), o navegador ainda carrega a tela anterior: as cores seguem
o tipo da regra, o conflito nao aparece e salvar com remoto nao pede mais a
confirmacao (o servidor nao devolve mais 409; a tela mostra so o primeiro
aviso). Prefira horario de pouco uso e avise quem edita o mapa para nao
alterar vinculos durante o deploy.

1. **Ponto de rollback, pre-checagem e commit conferido**: itens 1 e 2 de
   "Atualizacao de Versao e Rollback" (anota `pre-deploy-commit`,
   `git status` vazio, `git fetch` e preflight da versao nova com
   `RESULTADO=0`). Em seguida, confira o branch e grave o commit que passou
   pelo preflight: o passo 4 aplica exatamente esse commit, sem novo `fetch`
   no meio (o `merge` le o `f6-commit`, nao `origin/<BRANCH>`).

```bash
cd /var/www/chronodesk; B=$(sudo git rev-parse --abbrev-ref HEAD); if [ "$B" = "<BRANCH>" ]; then echo "branch OK: $B"; sudo git rev-parse origin/<BRANCH> | sudo tee /var/backups/chronodesk/f6-commit; else echo "PARE: o clone esta em $B, nao em <BRANCH>"; fi
```

2. **Backup**: item 3 da mesma secao. Esperado `backup concluido`; anote o
   diretorio (ponto de restauracao do banco; o F6 nao altera o banco, mas o
   backup e o padrao de todo deploy).

3. **Q-F6 imediatamente antes do `git merge`** (so contagens). Com a 011
   aplicada (pre-requisito), rode a **versao completa**, as duas consultas:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.fora_mapa_escala = 1) AS vinculos_fora_do_mapa, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

   **Medicao antecipada, antes das migrations** (sem a 011 no banco, como em
   2026-10-15): a 1a consulta acima da erro 1054 em `fora_mapa_escala`; use
   esta variante, sem `vinculos_fora_do_mapa` (a 2a consulta nao muda). No
   deploy, a variante nao serve: a 011 e pre-requisito.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
```

   Referencia (servidor, 2026-10-15, variante): `pas_inventario = 16`,
   `vinculos_ativos = 20`, `vinculos_vigentes_ou_futuros = 20`,
   `pas_compartilhados_hoje = 6`, `vinculos_de_inativos = 1` (o caso da D5
   do F4), `vinculos_lideranca = 2`, `sobreposicoes_mesmo_colaborador = 0`,
   `periodo_invertido = 0`, `vinculos_fora_do_inventario = 0`,
   `copia_tipo_divergente_hoje = 3`, `vinculos_com_observacao = 0`,
   `legado_total = 1`, `legado_futuro = 0`. O que cada numero decide no
   deploy:

   - **`vinculos_de_inativos` e `vinculos_fora_do_mapa`: esperado `0`**
     (o passo da D5 do F4 ja arquivou o inativo com PA e fechou o vinculo;
     cadastro "fora do Mapa" nao recebe vinculo desde o F1). Diferente de
     `0`: **pare e avise o responsavel** (nada foi alterado). **No roteiro
     consolidado**, o F6 entra no mesmo `merge` das migrations: rode a Q-F6
     completa depois das migrations (esperado `vinculos_de_inativos = 1`, o
     caso da D5, e `vinculos_fora_do_mapa = 0`) e de novo depois do passo da
     D5 do F4 (esperado os dois em `0`); diferente disso, pare e avise.
   - `sobreposicoes_mesmo_colaborador`, `periodo_invertido` e
     `vinculos_fora_do_inventario`: esperado `0`. Maior que `0` e dado
     antigo, a decidir caso a caso (desenho 9.8): envie as contagens ao
     responsavel antes do passo 4.
   - `pas_compartilhados_hoje` (com `vinculos_lideranca`): quantos PAs podem
     aparecer em conflito no primeiro dia. **Maior que o numero do aviso a
     gestao**: atualize o aviso antes de seguir (nao bloqueia).
   - `copia_tipo_divergente_hoje`: quantos cartoes mudam de aparencia so por
     trocar a copia pelo motor (L-02). Anote; nao bloqueia.
   - `vinculos_com_observacao`: observacoes que a DF7 deixa de enviar a quem
     nao edita (e que um rollback reabre). Anote; nao bloqueia.
   - `legado_futuro`: maior que `0` nao bloqueia (a tabela antiga continua
     lida, DF10); anote para o lote de retirada dela.
   - Erro 1146 em `portal_pa_map` ou `portal_pa_inventory`: a tabela nao
     existe; anote (o codigo trata). Erro 1054 em `fora_mapa_escala` no
     deploy: a 011 nao esta aplicada, pre-requisito falhou: **pare**.

4. **`git merge` do commit conferido** (sem `fetch` e sem `pull`), conferencia
   do `HEAD` e do que mudou em pontos sensiveis:

```bash
cd /var/www/chronodesk; F6=$(sudo cat /var/backups/chronodesk/f6-commit); sudo git merge --ff-only "$F6" && [ "$(sudo git rev-parse HEAD)" = "$F6" ] && echo "HEAD = f6-commit" || echo "PARE: merge falhou ou HEAD diferente do f6-commit"
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
```

   Esperado: `HEAD = f6-commit` e o `diff --stat` **vazio** (o F6 nao traz
   migration nem muda backup ou `.htaccess`). No roteiro consolidado, o
   `diff --stat` lista so os arquivos das migrations dos outros lotes.

5. **Build do frontend e recarga do PHP-FPM** (limpa o OPcache; `reload` e
   gracioso). O F6 nao muda vhost nem `.htaccess`: a recarga do Apache nao e
   necessaria.

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

6. **Verificacao pos-deploy, so com contagens.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT action, severity, COUNT(*) AS n FROM audit_log WHERE action LIKE 'PA\_MAP\_%' AND timestamp >= CURDATE() GROUP BY action, severity;"; unset P
```

   Esperado: health `200`; no `audit_log`, so os eventos das acoes feitas hoje
   no mapa (sem nenhuma gravacao, nenhuma linha), com
   `PA_MAP_CONFLICT_ACCEPTED` sempre em `WARNING`. Repita as duas consultas
   da Q-F6 do passo 3: **os mesmos numeros** (o deploy nao altera linha; se
   alguem gravou no mapa durante a janela, as diferencas batem com os
   `PA_MAP_SAVED` e `PA_MAP_REMOVED` do `audit_log`). Em seguida, a checagem
   HTTP de `scripts/test-linux.sh` (item 6 de "Atualizacao de Versao").

   Pelo navegador, sem gravar e sem anotar nomes:
   - Como Lideranca ou admin, Mapa PA: abre na data de hoje (tambem depois das
     21:00); o seletor de seg a sex troca a data; cartoes na cor da legenda
     (presencial verde, ausente laranja, sem escala tracejado, parcial com o
     relogio); PA em conflito com contorno vermelho, selo "Conflito" e os
     dias. Anote **so o numero** do cartao "Conflitos" (esperado ate o numero
     do aviso a gestao; mais que isso nao e falha, avise a gestao). O modal
     abre sem `window.confirm`; o cartao de permissao diz "Admin/Lideranca
     podem editar". A aba "Ausencias da semana" mostra a coluna Categoria.
   - Como gestor (ou tecnico): sem formulario, Editar ou Remover; cartao
     "Leitura". No DevTools, a resposta de `pa_map.php` **sem** `employees` e,
     nos vinculos, sem `employee_login`, `notes`, `created_by` e
     `updated_by` (DF7); como tecnico, a categoria so nas proprias
     ausencias. Anote o tempo de resposta de `pa_map.php` (risco 4 da 9.8;
     no MySQL local, de 5 a 10 ms).
   - CSS que veio junto: o indicador de conexao no topo com o ponto colorido;
     em Pausas, o tecnico disponivel com a barra verde; cartoes de metrica com
     a borda do tom.

**Se a verificacao falhar:**

- **Q-F6 do passo 3 fora do esperado** (as regras de parada acima): nada foi
  alterado e o codigo anterior continua no ar; envie as contagens.
- **O `git merge` falhou ou o `HEAD` difere do `f6-commit`** (alteracao local
  na arvore, ou o branch local divergiu e o `--ff-only` recusa): o codigo
  anterior continua no ar. Resolva a causa (item 1 de "Atualizacao de
  Versao"), **sem `fetch` e sem `pull`**, e repita so a 1a linha do passo 4.
  Se o `HEAD` mudou para outro commit, pare: o codigo no ar nao e o
  conferido; rollback do codigo (abaixo) e avise o responsavel.
- **Erro 500 ou 503 no Mapa PA ou em outras telas, health diferente de
  `200`, ou falha no build:** rollback do codigo (abaixo).
- **Mais PAs em conflito que o aviso:** nao e falha do deploy; avise a
  gestao com o numero (o conflito e aviso, P13).
- Restaurar o backup do passo 2 nao e necessario: o F6 nao altera o banco.

**Rollback do F6 (so codigo, sem passo de banco):** `git checkout --detach` do
`pre-deploy-commit`, build e recarga ("Rollback", acima). Nao ha migration a
desfazer nem dado a corrigir: a copia da regra continua gravada a cada `save`
do F6, e o codigo anterior a le como antes. O que volta com o codigo anterior:
(1) a situacao pela copia gravada (os `copia_tipo_divergente_hoje` cartoes da
Q-F6 voltam a aparencia antiga, L-02) e o conflito por tipo de regra, que
**bloqueia** com 409 (dois dias fixos sempre conflitam, L-09) e pede
confirmacao no remoto; (2) os vinculos salvos com conflito durante o F6 ficam
gravados e aparecem normalmente, mas editar um deles pode receber o 409 do
conflito por tipo; (3) **a DF7 cai: o `GET` volta a enviar a qualquer perfil
os logins, a observacao e os autores dos vinculos e a lista de elegiveis com
login** (LGPD, `docs/DOCUMENTACAO_TECNICA.md`, 5.3). Por isso, como no F3,
qualquer rollback do F6 so com aprovacao previa do responsavel; prefira
corrigir para frente. No roteiro consolidado, o rollback do codigo e o do
consolidado (volta toda a Parte B ao `pre-deploy-commit`); o F6 nao acrescenta
passo de banco a ele.

### Deploy que traz o F7 (Parte B, Visao Operacional no motor)

Roteiro completo, na ordem. O F7 traz a Visao Operacional pelo motor de
presenca: o `portal/dashboard.php` passa a mandar `presence_summary` (por
equipe, com relogio: disponiveis, em pausa, almoco, ausentes, fora do
expediente e sem escala), `presence_meta` e `server_now`, e "CIs disponiveis"
passa a vir do servidor; o `portal/technicians.php` passa a mandar a situacao
de agora de cada tecnico (`presence`); a tela Tecnicos ganha a coluna
"Situacao agora" e os cartoes de Pausas seguem a mesma situacao. Junto, tres
controles de exposicao de dado (LGPD, `docs/DOCUMENTACAO_TECNICA.md`, 5.3):
`role` no `technicians.php` so para admin e Lideranca (DV9); motivo de pausa
fora da lista como "Pausa" para quem nao e gestao nem o proprio (DV10); e a
`observacao_reuniao` no `status.php` e no `dashboard.php` so para a gestao e o
proprio (DV11). **So codigo: sem migration**, sem variavel nova no `.env` (o
`STATUS_PRESENCE_ENABLED`, do F2, passa a desligar tambem o painel e a tela
Tecnicos; nos `.env` de exemplo e no preflight so mudou o texto), sem mudanca
em `.htaccess`, vhost ou dependencia do frontend (o `package.json` so ganhou o
script `qa:operational-view`; o `package-lock.json` nao mudou). Contrato:
`docs/API_CONTRACTS_FRONTEND.md`, linhas `status.php`, `portal/dashboard.php`
e `portal/technicians.php`. Desenho: `docs/DESENHO_PA_ESCALA_AUSENCIA.md`,
secao 9.9. **A Parte B entra num deploy unico depois do F9:** este roteiro
vira a parte do F7 do roteiro consolidado (pendencia 16); o que muda na
consolidacao esta no fim, em "No roteiro consolidado".

Todas as consultas deste roteiro devolvem **so contagens**, nunca nome, login
nem texto. Onde aparece `<BRANCH>`, use o branch do deploy; onde aparece
`<ServerName do vhost>`, o `ServerName` do VirtualHost do portal. A senha do
root do MySQL e sempre lida sem eco (`read -rsp`) e apagada no fim do comando
(`unset P`); nunca a escreva no comando nem neste documento. O valor do
cookie da medicao (passos 0b, 5 e 8) vale como senha: nunca em chat,
chamado ou documento.

**Pre-requisitos.** Deploys 1 a 12 no ar e o F2, o F4, o F5 e o F6 aplicados
(no roteiro consolidado, as partes deles vem antes desta), inclusive o passo
da D5 do F4. O codigo do F7 le as colunas da 011 (`fora_mapa_escala`), da 012
(ausencias do F3) e da 014 (`arquivado_em`); **nao le a 019** (o painel nao
segue a data de corte, DV3). Conferencia (esperado `migrations_011_014 = 3` e
`migration_012 = 3`):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS migrations_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS migration_012;"; unset P
```

**0. Antes do dia do deploy.**

0a. **Aviso a gestao, junto com o do F6** (risco 1 do F7 e P13 do F6). O
   texto leva marcadores, preenchidos **no dia do envio** com as consultas
   rodadas nesse dia (nunca com os numeros de uma medicao antiga): `[N_PAS]`
   e o `pas_compartilhados_hoje` da Q-F6 (passo 3 do roteiro do F6; antes das
   migrations, a variante da medicao antecipada) e `[N_SEM_REGRA]` e o
   `n1_n2_sem_regra_hoje` da Q-F7 (passo 0c, variante; depois das
   migrations, a completa do passo 4). Troque tambem DD/MM pela data do
   deploy; nao envie o texto com marcador. Texto para o Teams, a Lideranca e
   aos gestores:

> Pessoal, a partir de DD/MM o ChronoDesk passa a usar a escala cadastrada de
> cada tecnico no Mapa PA e na Visao Operacional. No Mapa PA, o PA em que duas
> ou mais pessoas estao presenciais no mesmo dia passa a aparecer com contorno
> vermelho e os dias do conflito; pelos numeros de hoje, ate [N_PAS] PAs podem
> aparecer assim ja no primeiro dia. E um aviso, nao um bloqueio: a alocacao
> continua podendo ser salva, e o conflito aceito fica registrado. Na Visao
> Operacional, "CIs disponiveis" passa a contar pela escala do dia: conta quem
> esta escalado, dentro do expediente e sem pausa, almoco ou ausencia lancada.
> Almoco e fora do expediente nao contam como disponiveis. Os [N_SEM_REGRA] tecnicos que
> ainda nao tem regra de escala aparecem como "Sem escala" e deixam de contar
> ate a regra ser cadastrada na tela Escala presencial; por isso o numero pode
> ficar menor que o de hoje, sem que isso seja falha. A Lideranca aparece numa
> linha a parte, fora dos totais. Tecnicos e perfis de leitura deixam de ver
> as observacoes de reuniao dos colegas e, no Mapa PA, os logins e as
> observacoes dos vinculos; a gestao continua vendo. Duvidas, falem comigo.

0b. **Referencia do `dashboard.php` (DV15), com o codigo atual no ar.** O
   `scripts/medir-polling.sh` ainda nao existe no diretorio da aplicacao no
   servidor: ele e lido do branch remoto depois do `git fetch`, sem gravar
   arquivo e sem implantar nada (a medicao nao depende do codigo do F7). O
   script le o cookie de `/dev/tty`, entao funciona por `<(...)`. Condicoes da
   base do F2 (secao "Medicao do Polling"): conta do responsavel, sem
   `CONSULTAS`, `N=200`, aquecimento 5, horario de uso normal, aba do
   ChronoDesk fechada; **a mesma conta** em todas as medicoes do F7 (a gestao
   consulta as aprovacoes pendentes no `dashboard.php`: o perfil muda o
   trabalho). Anote p50 e p95 de cada endpoint, duas ou tres rodadas:

```bash
cd /var/www/chronodesk; sudo git fetch origin && sudo git cat-file -e origin/<BRANCH>:scripts/medir-polling.sh && bash <(sudo git show origin/<BRANCH>:scripts/medir-polling.sh)
sudo git cat-file -e origin/<BRANCH>:scripts/medir-polling.sh && URL=http://127.0.0.1/api/portal/dashboard.php bash <(sudo git show origin/<BRANCH>:scripts/medir-polling.sh)
```

   A 1a linha mede o `status.php` (padrao do script); a 2a, o
   `dashboard.php`. Alternativa, se `<(...)` nao funcionar no shell do
   servidor: copiar o arquivo para um diretorio temporario, conferir com
   `sha256sum` contra `sudo git show origin/<BRANCH>:scripts/medir-polling.sh |
   sha256sum` e rodar `bash <copia>` (nunca `./<copia>`: o `/tmp` da VM e
   montado com `noexec`). **Referencia: PENDENTE** (o
   responsavel mede no servidor e registra na secao 9.9 do desenho).

0c. **Q-F7 e rotulo de equipe, antecipados** (so contagens; num dia util, em
   horario de expediente). Antes das migrations da Parte B (sem a 011), use a
   variante da Q-F7, sem `fora_do_mapa`:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

   E a contagem dos rotulos de equipe que o PHP nao reconhece (fica fora do
   motor, do painel, dos contadores e do Mapa PA; I7):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

   As regras de leitura sao as do passo 4.

**Janela.** Sem 503 e sem alteracao de dado: o F7 nao muda o esquema nem grava
linha. A partir do `git merge` (passo 6), o `status.php`, o `dashboard.php` e
o `technicians.php` passam a responder no formato novo (aditivo; a DV9 a DV11
valem desde ja). Entre o `git merge` e o build (passo 7), o navegador ainda
carrega as telas anteriores: a Visao Operacional continua contando no
navegador pelo calculo antigo, a tela Tecnicos mostra a coluna Status antiga e,
nas pausas de reuniao dos colegas, o tecnico ve "Sem observacao informada" (a
chave nao vem mais; nada vaza). Prefira horario de pouco uso.

1. **Ponto de rollback, pre-checagem e commit conferido**: itens 1 e 2 de
   "Atualizacao de Versao e Rollback" (anota `pre-deploy-commit`,
   `git status` vazio, `git fetch` e preflight da versao nova com
   `RESULTADO=0`; o preflight mostra `STATUS_PRESENCE_ENABLED: OK (ligado)`).
   Em seguida, confira o branch e grave o commit que passou pelo preflight:
   os passos 3 a 6 usam exatamente esse commit, sem novo `fetch` no meio (o
   `merge` le o `f7-commit`, nao `origin/<BRANCH>`).

```bash
cd /var/www/chronodesk; B=$(sudo git rev-parse --abbrev-ref HEAD); if [ "$B" = "<BRANCH>" ]; then echo "branch OK: $B"; sudo git rev-parse origin/<BRANCH> | sudo tee /var/backups/chronodesk/f7-commit; else echo "PARE: o clone esta em $B, nao em <BRANCH>"; fi
```

2. **Backup**: item 3 da mesma secao. Esperado `backup concluido`; anote o
   diretorio (o F7 nao altera o banco, mas o backup e o padrao de todo
   deploy).

3. **Ensaio do commit gravado, fora da aplicacao no ar.** O `git cat-file -e`
   confere que o `f7-commit` existe e traz os arquivos que os passos seguintes
   usam; o `git archive` extrai esse commit num diretorio temporario, com
   `pipefail` (sem ele, um `git archive` com falha passaria em silencio pelo
   `tar`); os QAs do F7 rodam ali com o PHP do servidor, como `www-data`, sem
   banco, sem rede e sem tocar em `/var/www/chronodesk`. Permissao: o
   `sudo mktemp -d` cria o diretorio com dono `root` e modo `0700`, que o
   `www-data` nao le; por isso a mesma linha da extracao passa **so esse
   diretorio** para o `www-data` (`chown -R www-data: "$E"`), antes do
   `php -l` e dos QAs (nada mais no `/tmp` muda). O `/tmp` da VM e montado
   com `noexec`: o ensaio so chama `php arquivo.php` (o `php -l`, os cinco
   QAs e os processos filhos do `qa-security`, que rodam o mesmo binario do
   PHP com o arquivo como argumento) e nunca executa arquivo do ensaio
   direto (`./x.sh`).

```bash
cd /var/www/chronodesk; F7=$(sudo cat /var/backups/chronodesk/f7-commit); sudo git cat-file -e "$F7^{commit}" && sudo git cat-file -e "$F7:scripts/qa-operational-view.php" && sudo git cat-file -e "$F7:scripts/medir-polling.sh" && echo "f7-commit OK" || echo "PARE: f7-commit ausente ou incompleto"
E=$(sudo mktemp -d /tmp/ensaio-f7.XXXXXX); export F7 E; bash -o pipefail -c 'sudo git archive "$F7" | sudo tar -x -C "$E"' && sudo chown -R www-data: "$E" && echo "extraido em $E" || echo "PARE: falha ao extrair o f7-commit"
sudo find "$E" -name '*.php' -not -path '*/node_modules/*' -exec sudo -u www-data php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
for q in qa-presence-engine qa-operational-view qa-pa-conflicts qa-archive qa-security; do printf '%s: ' "$q"; sudo -u www-data php "$E/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
case "$E" in /tmp/ensaio-f7.*) sudo rm -r "$E" && echo "ensaio removido";; *) echo "PARE: $E fora do padrao, nada apagado";; esac; unset E F7
```

   Esperado: `f7-commit OK`, `extraido em /tmp/ensaio-f7.<sufixo>`, nenhuma
   linha entre o `php -l` e `fim do php -l`, os cinco QAs com `OK` e
   `ensaio removido`. Qualquer `PARE` ou `FALHOU`: **pare** (nada foi
   alterado no ar) e envie ao responsavel so o nome do QA e a ultima linha
   da saida dele (`sudo -u www-data php "$E/scripts/<qa>.php" | tail -n 1`,
   antes de remover o ensaio). O `qa-security` precisa do `php-ldap`
   (`php -m | grep -x ldap`), que o checklist de validacao ja exige.

4. **Q-F7 completa e rotulo de equipe, imediatamente antes do `git merge`**
   (so contagens). Com a 011 aplicada (pre-requisito), rode a **versao
   completa**:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 1) AS fora_do_mapa, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
```

   E a contagem dos rotulos de equipe (a mesma do passo 0c):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

   Referencia (servidor, 2026-10-26, variante): `ativos_n1 = 13`,
   `ativos_n2 = 9`, `ativos_lideranca = 3`, `ativos_outras_equipes = 0`,
   `n1_n2_sem_regra_hoje = 6`, `jornada_invertida = 0`,
   `almoco_fora_da_jornada = 0`, `ausencias_aprovadas_hoje = 0`,
   `excecoes_de_ausencia_hoje = 0`; rotulo: contagem local 0 (2026-10-27),
   **a do servidor pendente** (pendencia 16). O que cada numero decide:

   - **`ativos_outras_equipes`, `jornada_invertida`, `almoco_fora_da_jornada`
     e `rotulo_que_o_php_nao_reconhece`: esperado `0`.** Maior que `0`:
     **pare e avise o responsavel** (nada foi alterado). O primeiro e
     cadastro que some do painel (equipe fora de N1, N2 e Lideranca, o caso
     do Critico, F9); o segundo, tecnico sempre "Fora do expediente"; o
     terceiro, almoco que nunca aparece ou aparece fora do expediente; o
     quarto, rotulo que o MySQL aceita e o PHP nao (corrigir o cadastro pela
     tela de Funcionarios, dado e nao codigo).
   - `n1_n2_sem_regra_hoje`: quantos deixam de contar como "CIs disponiveis"
     no primeiro dia. **Maior que o numero do aviso a gestao**: atualize o
     aviso antes de seguir (nao bloqueia). Pela 16c, o ideal e cadastrar a
     regra de todos antes do deploy.
   - `fora_do_mapa`: sai de todas as contagens (0.6). Anote.
   - `ativos_n1`, `ativos_n2`, `ativos_lideranca`, `ausencias_aprovadas_hoje`
     e `excecoes_de_ausencia_hoje`: dimensionam a tabela. Anote; o passo 8
     compara com o painel.
   - Erro 1054 em `fora_mapa_escala`: a 011 nao esta aplicada, pre-requisito
     falhou: **pare**.

5. **Base do criterio, logo antes do `git merge`.** Repita a medicao do passo
   0b nas mesmas condicoes (mesma conta, mesmo tipo de login, `N=200`, aba
   fechada), com o script do commit gravado. Anote p50 e p95 do `status.php`
   e do `dashboard.php`: e a base contra a qual o passo 8 compara (decisao de
   2026-10-06, secao 9.2 do desenho).

```bash
cd /var/www/chronodesk; F7=$(sudo cat /var/backups/chronodesk/f7-commit); sudo git cat-file -e "$F7:scripts/medir-polling.sh" && bash <(sudo git show "$F7:scripts/medir-polling.sh")
sudo git cat-file -e "$F7:scripts/medir-polling.sh" && URL=http://127.0.0.1/api/portal/dashboard.php bash <(sudo git show "$F7:scripts/medir-polling.sh"); unset F7
```

6. **`git merge` do commit conferido** (sem `fetch` e sem `pull`), conferencia
   do `HEAD` e do que mudou em pontos sensiveis:

```bash
cd /var/www/chronodesk; F7=$(sudo cat /var/backups/chronodesk/f7-commit); sudo git merge --ff-only "$F7" && [ "$(sudo git rev-parse HEAD)" = "$F7" ] && echo "HEAD = f7-commit" || echo "PARE: merge falhou ou HEAD diferente do f7-commit"
sudo git diff --stat "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
```

   Esperado: `HEAD = f7-commit` e o `diff --stat` **vazio** (o F7 nao traz
   migration nem muda backup ou `.htaccess`). No roteiro consolidado, o
   `diff --stat` lista so os arquivos das migrations dos outros lotes.

7. **Build do frontend e recarga do PHP-FPM** (limpa o OPcache; `reload` e
   gracioso). O F7 nao muda vhost nem `.htaccess`: a recarga do Apache nao e
   necessaria.

```bash
cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

8. **Verificacao pos-deploy, so com contagens.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
```

   Esperado: health `200`. Repita a Q-F7 e a contagem de rotulo do passo 4:
   **os mesmos numeros** (o deploy nao altera linha; diferenca so se alguem
   cadastrou, arquivou ou lancou ausencia na janela). Em seguida, a checagem
   HTTP de `scripts/test-linux.sh` (item 6 de "Atualizacao de Versao").

   **Medicao depois do deploy**, nas condicoes do passo 5, agora com o script
   do proprio clone (`bash scripts/medir-polling.sh` e
   `URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh`).
   **Criterio (o do F2, para os dois endpoints):** ha piora quando o p95 sobe
   **mais de 50 ms e mais de 20%** sobre a base do passo 5, as duas
   condicoes ao mesmo tempo. O `status.php` nao ganhou consulta (DV1); a DV11
   so filtra em memoria e o `status.php` passou a carregar o `_bootstrap.php`
   do portal (J2): o efeito, se houver, aparece aqui. Com piora no
   `dashboard.php` ou no `status.php`: desligamento rapido (abaixo), medir de
   novo e trazer os numeros. Anote p50 e p95 na secao 9.9 do desenho.

   Pelo navegador, sem gravar e sem anotar nomes:
   - Como gestor, Visao Operacional: secao "Situacao da equipe agora" com
     N1, N2, Total e, a parte, "Lideranca, fora dos totais"; em cada linha,
     `escalados = disponiveis + em pausa + almoco + ausentes + fora do
     expediente`; Total = N1 + N2; "CIs disponiveis" igual ao Disponiveis do
     Total. No DevTools, `portal/dashboard.php` com `presence_meta.disponivel`
     e `pausas_disponiveis` `true` e `escalados + sem_escala` de N1 e N2 igual
     a `ativos_n1 + ativos_n2 - fora_do_mapa` da Q-F7 num dia util (salvo
     equipe fora do Mapa por outro motivo). Anote **so os numeros** do Total.
     Anote o tempo de resposta do `dashboard.php` no DevTools.
   - Como gestor, tela Tecnicos: coluna "Situacao agora as HH:MM" com selo,
     icone e rotulo; no DevTools, `portal/technicians.php` sem `role`
     (gestor nao tem `funcionarios.manage`); como admin ou Lideranca, com
     `role`.
   - Como tecnico (a propria conta, nunca a de um colega): Visao Operacional
     sem a caixa de observacao nas reunioes dos colegas; no DevTools,
     `status.php` e `dashboard.php` sem a chave `observacao_reuniao` nos itens
     dos colegas; `technicians.php` sem `role` e sem `categoria` das
     ausencias dos colegas; tela Pausas sem "Disponivel" para quem esta
     ausente ou no almoco.
   - Em 1280 px ou mais, a tabela da Visao Operacional sem rolagem dentro do
     cartao; em 390 px, a tabela rola dentro do cartao e a pagina nao.

**Desligamento rapido (`STATUS_PRESENCE_ENABLED`).** Para piora de desempenho
ou falha no calculo, sem voltar o codigo. A variavel fica no `.env` em uso,
`/var/www/.env` (o mesmo que o preflight le). Ela so vale se o pool do
PHP-FPM nao a definir: variavel exportada no pool (`env[...]` no `www.conf`)
vence o `.env`. O PHP-FPM guarda o valor no processo (o `config.php` so grava
o que o ambiente ainda nao tem), entao **so a recarga do PHP-FPM aplica** a
mudanca. Valores: `false` desliga e `true` liga (os do `.env.example` e os
que o preflight pede); o PHP (`PresenceService::statusPresenceEnabled`) e o
preflight aceitam as mesmas listas (`0`/`false`/`off`/`no` desligam), mas o
roteiro usa so `false` e `true`. Antes de alterar, a 2a linha copia o `.env`
para `/var/backups/chronodesk` com modo `600` (o `install -m 600` ja cria a
copia nesse modo; ela tem os segredos do `.env`, como o backup) e so troca a
linha se a copia der certo.

```bash
sudo grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
K=/var/backups/chronodesk/env-antes-interruptor-$(date +%Y%m%d-%H%M%S); if sudo install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(sudo stat -c '%a %U' "$K"))"; if sudo grep -q '^STATUS_PRESENCE_ENABLED=' /var/www/.env; then sudo sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=false/' /var/www/.env; else echo 'STATUS_PRESENCE_ENABLED=false' | sudo tee -a /var/www/.env >/dev/null; fi; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
cd /var/www/chronodesk && sudo sh scripts/preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

   Esperado: nada no `grep` do pool, `copia do .env: ... (600 root)` e
   `STATUS_PRESENCE_ENABLED: OK (desligado)` no preflight (que nunca mostra
   valores). Com `PARE`, o `.env` nao mudou: nao recarregue. Efeito: o
   `status.php` deixa de calcular a `presenca` (as 4 consultas saem do
   polling) e os cartoes de Pausas voltam ao calculo antigo; o
   `dashboard.php` responde sem o resumo e a Visao Operacional mostra "—" e
   o aviso "Situacao da equipe indisponivel" (nunca o numero antigo, DV5); a
   tela Tecnicos mostra "—" com aviso. **A DV9, a DV10 e a DV11 continuam
   valendo** (nao dependem do interruptor). Para religar: o mesmo comando com
   `STATUS_PRESENCE_ENABLED=true` (faz outra copia antes), preflight com
   `OK (ligado)` e a recarga.

**Se a verificacao falhar:**

- **Ensaio (passo 3) ou Q-F7 (passo 4) fora do esperado:** nada foi alterado
  e o codigo anterior continua no ar; envie as contagens (ou o nome do QA).
- **O `git merge` falhou ou o `HEAD` difere do `f7-commit`** (alteracao local
  na arvore, ou o branch local divergiu e o `--ff-only` recusa): o codigo
  anterior continua no ar. Resolva a causa (item 1 de "Atualizacao de
  Versao"), **sem `fetch` e sem `pull`**, e repita so a 1a linha do passo 6.
  Se o `HEAD` mudou para outro commit, pare: o codigo no ar nao e o
  conferido; rollback do codigo (abaixo) e avise o responsavel.
- **Piora na medicao, ou "—" com aviso na Visao Operacional** (falha no
  calculo; o log do PHP traz `[PRESENCE]` so com a classe do erro):
  desligamento rapido (acima) e avise o responsavel com os numeros e a
  classe do erro. Nao e rollback.
- **Erro 500 ou 503 nas telas, health diferente de `200`, ou falha no
  build:** rollback do codigo (abaixo).
- **Numeros do Total diferentes do esperado pela Q-F7:** nao reverta; anote
  as contagens e avise o responsavel (o motor e o mesmo do F2, validado; a
  diferenca costuma ser regra de ciclo invalida ou excecao de trabalho, 9.9).
- Restaurar o backup do passo 2 nao e necessario: o F7 nao altera o banco.

**Rollback do F7 (so codigo, sem passo de banco):** `git checkout --detach` do
`pre-deploy-commit`, build e recarga ("Rollback", acima). Nao ha migration a
desfazer nem dado a corrigir. O que volta com o codigo anterior: (1) a Visao
Operacional conta no navegador pelo calculo antigo (jornada e almoco, sem
escala nem ausencia lancada) e a tela Tecnicos volta a coluna Status; (2)
**a DV9, a DV10 e a DV11 caem: o `technicians.php` volta a enviar o perfil de
acesso de cada tecnico a qualquer perfil, e o `status.php` e o
`dashboard.php` voltam a enviar a observacao de reuniao de todos a qualquer
perfil** (LGPD, `docs/DOCUMENTACAO_TECNICA.md`, 5.3). Por isso, como no F3 e
no F6, qualquer rollback do F7 so com aprovacao previa do responsavel; para
desempenho ou falha no calculo, use o desligamento rapido; prefira corrigir
para frente.

**No roteiro consolidado (pendencia 16, deploy unico depois do F9):** o aviso
a gestao e um so (o do passo 0a, com os numeros do dia); a referencia do
`dashboard.php` (0b) continua antes de tudo; o commit gravado e um so para
todos os lotes (`partes-b-commit`, no lugar de `f6-commit` e `f7-commit`), e
o ensaio do passo 3 roda nele, junto com os QAs dos outros lotes; a Q-F7
completa e a contagem de rotulo rodam **depois das migrations** e do passo da
D5 do F4 (a 011 vem no mesmo deploy); a base do criterio (passo 5) e medida
uma vez, logo antes do `git merge` unico; build e recarga uma vez; a
verificacao do F7 vem depois da do F6. O rollback e o do consolidado (volta
toda a Parte B ao `pre-deploy-commit`); o F7 nao acrescenta passo de banco a
ele, e o desligamento rapido continua valendo sem rollback.

### Deploy 1 da Parte B (roteiro unico e consolidado: tudo desde o 596db67, com a fila antiga, F1 a F7, acabamento, paginas legadas e 9.12)

**Decisao do responsavel (2026-11-06):** o deploy da Parte B passa a ser em
**duas etapas**, porque o F8 e o F9 dependem da Lideranca, sem prazo. O
**deploy 1** (este roteiro) leva tudo o que ja esta aprovado no branch; o
**deploy 2** (depois, com roteiro proprio) leva o F8 e o F9 (desde
2026-11-12, numeracao pela ordem de execucao: o deploy 2 foi o do
pos-deploy 1, e o do F8 e do F9 passa a ser o deploy 3). Desenho:
`docs/DESENHO_PA_ESCALA_AUSENCIA.md`, secoes 9.13 e 10.

**Correcao de 2026-11-09 (desenho 9.13.4): o servidor esta no `596db67`.** O
pre-requisito 0a do roteiro anterior deu PARE: o codigo no ar e o `596db67`
(2026-06-26), com o `.htaccess` alterado localmente, e o banco nao tem a
015, a 016, a 017, os scripts do BIZ-02 nem nada da Parte B. A fila antiga
(deploys 1 a 12, de setembro e outubro) **nunca foi aplicada**. Este roteiro
passa a levar **tudo desde o `596db67`**: os lotes 1 a 7, o BIZ-02, o modo de
manutencao, o F1 a o F7, o lote de acabamento (17, 17b, 17d, 17e, 17f, 17g e
DD), a retirada das paginas legadas (9.12.2) e as correcoes das 9.12.1 a
9.12.5. O inventario do que muda, com arquivo, linha e commit, o "antes" de
cada migration e as decisoes da fila antiga que ainda valem esta em
`docs/DEPLOY1_INVENTARIO.md`.

Este roteiro substitui, para o deploy 1, os roteiros por lote acima ("Deploy
11 - Parte B, F3", "Deploy que traz o F4" a "o F7", os itens por lote de
"Atualizacao de Versao", a secao 6 de `docs/FUSO_HORARIO_BIZ02.md` e a secao 6
de `docs/LIMITE_LOGIN_POR_USUARIO.md`). Eles continuam como referencia do
detalhe de cada lote (o que cada numero esperado quer dizer); **a ordem e os
comandos que valem sao os daqui**. O que vai no deploy 1 e nao tem efeito: o
commit 1 do F8 (`services/EmployeeLunchPolicy.php`, regra pura) so e chamado
pelo `scripts/qa-employee-lunch.php`; nenhum endpoint, tela ou carregador o
usa (conferido em 2026-11-06). As migrations 018 (F8) e 020 (F9) nao existem
no commit; a 013 foi dispensada (Q3 e Q4 de 2026-10-07 em 0).

**Data, janela, vespera, indisponibilidade e quem acompanha** (respostas do
responsavel, 2026-11-09). A folha do dia `docs/DEPLOY1_FOLHA_DO_DIA.md`
repete estas quatro linhas. A trava exige a data, confere o corte pela D2,
a vespera como o dia anterior e a indisponibilidade dentro da janela, e
confere que o aviso a gestao traz a mesma data e o mesmo horario.
- **Data e janela do deploy**, fora do horario de operacao: 11/11/2026, das 19:00 as 23:00 (horario de Brasilia); corte do F5 pela D2: 16/11/2026.
- **Vespera** (pre-requisitos do passo 0, ensaio do BIZ-02 e ensaios das migrations): 10/11/2026.
- **Indisponibilidade prevista** (manutencao, passos 6b a 6g; inventario, secao 9): cerca de 30 minutos, entre 20:00 e 21:00, com o turno da noite em operacao.
- **Quem acompanha** (alem de quem executa): o responsavel pela infraestrutura da VM.

**Dados do servidor** (lidos pelo responsavel em 2026-11-09, so contagens e
tamanhos):
- banco `sistema_pausas` com **1,8 MB**;
- **backup pre-dia** completo em
  `/root/backups_chronodesk/pre_deploy1_20261109_1549.sql.gz`
  (`mysqldump --single-transaction --routines --triggers --events`, modo
  `600`, com "Dump completed"). Ele **nao substitui** o backup do passo 3d,
  porque o banco muda ate o dia. Tem dados pessoais e sai com aprovacao no
  passo 9e;
- **copia de ensaio `sistema_pausas_ensaio`** no mesmo container,
  restaurada desse backup, com 33 tabelas (igual a producao). E a origem do
  ensaio do BIZ-02 (0n) e dos ensaios das migrations (0q), os dois na
  vespera. Tem dados pessoais: o ultimo comando do 0q a apaga, com
  confirmacao, tambem se um ensaio parar; o 7m confere que ela nao existe
  mais;
- disco: `/var` com 88% de uso (1,2 GB livres; o codigo e o Docker estao
  nele) e `/` com 599 MB livres. O espaco que o `npm ci` e o build pedem e
  os limites minimos estao no passo 0m.

Todas as consultas devolvem **so contagens, estrutura e datas de controle**
(corte do F5, fotografia do BIZ-02), nunca nome, login nem texto; a unica
excecao e a consulta da D5 (passo 7f), que devolve so o ID interno do
cadastro a regularizar. A senha do root do MySQL e sempre lida sem eco
(`read -rsp`) e apagada no fim do comando (`unset P`); nunca a escreva no
comando nem neste documento. O valor do cookie da medicao (passos 0d, 6a e
7j) vale como senha: nunca em chat, chamado ou documento. Os `curl` que
olham o portal "de fora" (0l, 6b, 6g, 7b e 7c) leem o `ServerName` do
`/etc/apache2/sites-enabled/chronodesk.conf` na hora e usam `http://`
(ate o deploy 1 havia aqui o marcador `<URL>`; trocado no commit C do
pos-deploy 1, desenho 9.14). Nunca `https`: na VM, o `*:443` e **outro
site**, que responde `200` de outro sistema. Onde aparece
`<ServerName do vhost>`, o
`ServerName` do VirtualHost do portal; onde aparece `<login do operador>`, o
login AD de quem registra o corte; onde aparece `<commit validado na
estacao>`, o hash completo anotado no passo 0o. Cada comando Linux esta em
**uma linha**.

**Shell root, sem `sudo` (achado do responsavel em 2026-11-10; desenho
9.13.7).** O `/etc/sudoers` da VM tem `Defaults noexec` e nao sera mudado
(endurecimento da VM): todo programa chamado por `sudo` fica impedido de
executar outro programa. `sudo sh -c` nao roda o `awk`, `sudo npm` nao roda
o `node`, `sudo tar -z` nao roda o `gzip`, `sudo -u www-data php` nao abre o
PHP filho do `qa-security`. Por isso, **na vespera e no dia, o operador abre
um shell root com `sudo -i` no inicio e roda todos os comandos nele, sem o
prefixo `sudo`**. O que roda como `www-data` usa
`runuser -u www-data --`, que nao passa pelo sudoers. A conferencia do
ambiente (item 00 na vespera e passo 1 no dia) prova isso antes de qualquer
outro comando. Um terminal que caiu e foi reaberto: `sudo -i` e a
conferencia de novo; as variaveis do shell (`E`, `Q`, `B`, `C`) se perdem e
sao refeitas a partir do comando que as cria.

**Branches.** O clone do servidor fica no `codex/modernizacao-ui-backend`
(branch local, nao muda de nome); o commit do deploy vem do
`feat/employee-form-rhf-zod` do `origin`, publicado pelo responsavel antes
do passo 0a. O `git merge --ff-only` leva o branch local do servidor do
`596db67` ate esse commit, sem commit de merge.

**Regras de parada em todo o roteiro.**
- Qualquer linha com `PARE`, ou `FALHOU` num ensaio onde nao era esperado:
  pare e envie so a contagem ou a mensagem.
- Verificacao da 019 com **`inconsistente = 1`** (pendencia 16a): pare, nao
  rode o registro do corte, nao apague nem altere linha e avise o
  responsavel.
- Corte do BIZ-02 com **`ABORTADO PELAS GUARDAS`** (passo 6c): pare, fique em
  manutencao, nao rode a 016b nem a recarga e avise o responsavel.
- Teste de fumaca do passo 6f sem **`FUMACA OK`** na ultima linha: nao
  reabra (6g), fique em manutencao e avise o responsavel.
- Linha local do `.htaccess` que o commit gravado nao tem, alem do `^$`
  (passo 2): pare antes de qualquer alteracao.
- Nada de `git pull`, e nenhum `git fetch` depois do passo 2: os passos 3 a
  7 usam so o commit gravado em `/var/backups/chronodesk/deploy1-commit`.

**Janela (o que o usuario ve).** As migrations 015, 016, 017, 011, 014 e 019
(passo 4) nao mudam o que o codigo anterior faz, com uma ressalva da 016: ate
o `merge`, o codigo anterior recebe erro do banco ao gravar num cadastro o
login AD de outro. O portal so sai do ar da manutencao ligada (passo 6b) ate
a reabertura (passo 6g), uns 25 a 30 minutos: toda requisicao de fora da VM
recebe `503` com "ChronoDesk temporariamente indisponivel.", inclusive o
registro de pausas. O arquivo-sinal e criado antes do `merge`, mas so passa
a valer quando o `.htaccess` novo entra (no `merge`): a regra nao existe no
`.htaccess` do `596db67`. Depois da reabertura, toda sessao aberta antes e
encerrada (perfil revalidado por requisicao, Lote 5) e todos entram de novo.
Avise quem cuida dos cadastros, da escala e do Mapa PA para nao gravar
durante a janela.

**0. Antes do dia do deploy, com o responsavel.** Cada item tem o comando e
o esperado, so com contagens. Anote os numeros: os passos seguintes
comparam. O responsavel publica o `feat/employee-form-rhf-zod` antes do 0a.

00. **Shell root e conferencia do ambiente**, logo no inicio da vespera:
   `sudo -i` no terminal da VM e, nele, a conferencia. A saida tem so `OK`
   ou `BLOQUEADO` por item:
   - `id -u` igual a `0`;
   - `bash -c`, `tar` com `gzip`, `find -exec`;
   - `runuser -u www-data -- php -v` e um PHP filho aberto por `proc_open`
     como `www-data`, o mesmo mecanismo do `qa-security`;
   - `npm -v` (que executa o `node`);
   - o `git` no clone.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

   Esperado: oito linhas `OK` e `ambiente: OK`. **Qualquer `BLOQUEADO` e
   PARE:** nada roda na vespera ate a causa ser resolvida com a
   infraestrutura (o sudoers nao muda; a saida e um shell root sem
   `noexec`).

0a. **Codigo, alteracao local e banco do servidor: `596db67` no ar; 015, 019
   e `manutencao_*` ausentes.**

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod; H=$(git rev-parse HEAD); echo "branch: $(git rev-parse --abbrev-ref HEAD)"; if [ "$H" = "596db67c67a9cd84e53b28aaef4c3316191fe87c" ]; then echo "HEAD no ar: 596db67 (OK)"; elif git merge-base --is-ancestor "$H" origin/feat/employee-form-rhf-zod; then echo "HEAD no ar: ancestral do branch do deploy (OK; hash curto $(git rev-parse --short HEAD))"; else echo "PARE: o HEAD do servidor nao e o 596db67 nem ancestral do branch do deploy"; fi; echo "commits a aplicar: $(git rev-list --count "$H"..origin/feat/employee-form-rhf-zod)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; echo "alteracoes fora do .htaccess: $(git status --porcelain | grep -vc '^ M \.htaccess$')"; echo ".htaccess, linhas acrescentadas e removidas: $(git diff --numstat -- .htaccess | cut -f1,2)"; unset H
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT VERSION() AS mysql, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff') AS tabela_019, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'manutencao\_%') AS tabelas_manutencao, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) AS tabelas;"; unset P
echo "rotina de backup instalada: $(systemctl list-unit-files chronodesk-backup.service 2>/dev/null | grep -c '^chronodesk-backup')"; echo "diretorio /var/backups/chronodesk: $(test -d /var/backups/chronodesk && echo existe || echo ausente)"; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo PRESENTE || echo ausente)"
```

   Esperado: `branch: codex/modernizacao-ui-backend`, `HEAD no ar: 596db67
   (OK)` (ou ancestral, com o hash anotado), `commits a aplicar` maior que
   `280`, `alteracoes locais: 1`, `alteracoes fora do .htaccess: 0` e
   `.htaccess` com `6` e `0` (as seis linhas locais, nenhuma removida);
   `mysql` 8.0.29 ou mais novo (os gatilhos da 019 usam `CREATE TRIGGER IF
   NOT EXISTS`), `migration_015 = 0`, `tabela_019 = 0`,
   `tabelas_manutencao = 0` e `tabelas = 33` (as 29 do `596db67` e as 4
   copias de junho; inventario, secao 1); `rotina de backup instalada: 0`
   (veio no `072ed16`, nunca aplicado), o diretorio de backup como estiver e
   `arquivo de manutencao: ausente`. Qualquer diferenca: **pare** e traga os
   numeros.

0b. **O "antes" de cada migration** (estrutura e contagens; inventario,
   secao 3.1):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COLUMN_TYPE = 'enum(''tecnico'',''gestor'',''admin'',''somente_leitura'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_005, (SELECT COUNT(*) FROM (SELECT ad_login FROM funcionarios WHERE ad_login IS NOT NULL GROUP BY ad_login HAVING COUNT(*) > 1) d) AS ad_login_repetidos, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_perfil_016b') AS perfil_016b, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS anexo_team, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'reference_month') AS anexo_reference_month, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee') AS uq_regra_por_colaborador, (SELECT LOCATE('fixed_weekdays', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_type') AS rule_type_010, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_config') AS rule_config_010, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'team') AS team_regras_n1_n2, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions' AND COLUMN_NAME = 'team') AS team_excecoes_n1_n2, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS colunas_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type') AS absence_type, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS colunas_012, (SELECT COUNT(*) FROM portal_absences) AS ausencias, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_0%') AS copias_de_rollback, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '_[0-9]{8}_[0-9]{6}$') AS copias_de_junho, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')) AS nao_innodb, ABS(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())) AS minutos_fora_de_utc, (SELECT COUNT(*) FROM mysql.time_zone_name) AS fusos_nomeados;"; unset P
```

   Esperado: `access_role_005 = 1`, `ad_login_repetidos = 0`,
   `uq_ad_login = 0`, `perfil_016b = 0`, `anexo_team = 0`,
   `anexo_reference_month = 1`, `uq_regra_por_colaborador = 1`,
   `rule_type_010 = 1`, `rule_config_010 = 1`, `team_regras_n1_n2 = 1`,
   `team_excecoes_n1_n2 = 1`, `colunas_011_014 = 0`, `absence_type = 1`,
   `colunas_012 = 0`, `ausencias = 0`, `copias_de_rollback = 0`,
   `copias_de_junho = 4` (pendencia LGPD, inventario, secao 8),
   `nao_innodb = 0` (o `mysqldump --single-transaction` do passo 3d so e
   consistente com InnoDB), `minutos_fora_de_utc = 0` (guarda G3 do BIZ-02:
   a sessao do root fica no fuso padrao do servidor, UTC) e
   `fusos_nomeados` maior que `0` (2026-09-30: 1795). Diferente: **pare**;
   cada coluna diz qual migration nao encontraria o "antes" que espera
   (`ad_login_repetidos` maior que `0`: a 016 para sem alterar nada; resolver
   os cadastros pela tela antes do dia).

0c. **Consultas da fila antiga que ainda valem** (Lote 4: publico dos
   avisos; Lote 6: secao 8 de `docs/HORAS_EXTRAS_LOTE6.md`):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT audience, COUNT(*) AS avisos FROM portal_announcements GROUP BY audience; SELECT 'horas_extras' AS tabela, status, COUNT(*) AS n FROM portal_overtime_entries GROUP BY status UNION ALL SELECT 'correcao_ponto', status, COUNT(*) FROM portal_time_adjustments GROUP BY status; SELECT status, COUNT(*) AS lancamentos_de_24h FROM portal_overtime_entries WHERE start_time = end_time GROUP BY status;"; unset P
```

   Esperado: `audience` so com `all`, `todos`, vazio, `n1`, `n2`,
   `lideranca`, `gestao` ou lista deles separada por virgula (outro valor
   fica visivel so para a gestao depois do deploy: decidir antes do dia se
   corrige); nenhum `synced` nem `sync_error` nas duas tabelas; nenhum
   lancamento de 24 h (2026-10-02: nenhum; com algum pendente, a aprovacao
   dele passa a dar 409 e a gestao rejeita e pede novo lancamento).

0d. **Referencia do `status.php` e do `dashboard.php` (0b do F7, DV15)**, com
   o codigo atual no ar, o script lido do branch remoto sem gravar arquivo
   (condicoes da "Medicao do Polling": conta do responsavel, sem
   `CONSULTAS`, `N=200`, aba fechada, **a mesma conta** em todas as
   medicoes). Anote p50 e p95 de cada endpoint, duas ou tres rodadas:

```bash
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && bash <(git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
git cat-file -e origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh && URL=http://127.0.0.1/api/portal/dashboard.php bash <(git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)
```

   Esperado: p50 e p95 de cada um (referencia do F2: p95 26,8 ms no
   `status.php`, 2026-10-06; o `596db67` ainda le o estado com trava
   exclusiva, PERF-01, entao a base de hoje tende a ser maior). Registre na
   secao 9.9 do desenho. Erro `unknown revision` ou `Not a valid object
   name` no `origin/feat/employee-form-rhf-zod`: o clone so busca o branch
   do servidor (refspec do `origin`); avise o responsavel antes do dia (o
   passo 2 depende desse `fetch`).

0e. **Q-F6, variante sem a 011** (marcador `[N_PAS]` do aviso; so
   contagens), as duas consultas:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
```

   Referencia (2026-10-15): `pas_compartilhados_hoje = 6`,
   `vinculos_de_inativos = 1` (o caso da D5), `vinculos_lideranca = 2`,
   `sobreposicoes_mesmo_colaborador = 0`, `periodo_invertido = 0`,
   `vinculos_fora_do_inventario = 0`. Os tres ultimos maiores que `0`: dado
   antigo, decidir antes do dia (roteiro do F6, passo 3).

0f. **Q-F7, variante sem a 011, e rotulo de equipe** (marcador
   `[N_SEM_REGRA]`; num dia util, em horario de expediente):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
```

   Referencia (2026-10-26): `ativos_n1 = 13`, `ativos_n2 = 9`,
   `ativos_lideranca = 3`, `n1_n2_sem_regra_hoje = 6`; esperado `0` em
   `ativos_outras_equipes`, `jornada_invertida`, `almoco_fora_da_jornada` e
   `rotulo_que_o_php_nao_reconhece` (maior que `0`: corrigir o cadastro pela
   tela antes do dia; regras de leitura no roteiro do F7, passo 4).

0g. **Pendencia 16a: verificacao da 019 antes de tudo** (arquivo lido do
   branch remoto, sem gravar):

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql && bash -o pipefail -c 'git show origin/feat/employee-form-rhf-zod:migrations/verificacao/20261013_019_presence_cutoff_check.sql | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
```

   Esperado: `tabela_019 = 0`, `gatilhos_019 = 0`, `copia_rollback = 0`,
   `eventos_registro = 0`, `eventos_remocao = 0` e **`inconsistente = 0`**
   (o aviso de tabela ausente no lugar do corte). Lembrete da regra: a
   partir daqui, `inconsistente = 1` em qualquer verificacao da 019 manda
   parar.

0h. **Pendencia 16b: privilegios do usuario do banco da aplicacao** (sem
   `DROP` nem `TRIGGER`; VULN-002). O `DB_USER` e lido do `/var/www/.env`, o
   arquivo que a aplicacao e o preflight usam; a consulta devolve so
   contagens, sem senha:

```bash
U=$(grep -E '^DB_USER=' /var/www/.env | cut -d= -f2- | tr -d "\"'"); read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT '$U' <> '' AS usuario_lido, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'DROP') AS concessoes_drop, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'TRIGGER') AS concessoes_trigger;"; unset P U
```

   Esperado: `usuario_lido = 1`, `concessoes_drop = 0` e
   `concessoes_trigger = 0`. Maior que `0`: nao bloqueia por si (a
   verificacao da 019 acusa o `TRUNCATE`), mas a correcao (tirar os dois
   privilegios) e decisao do responsavel, ligada a VULN-002, de preferencia
   antes do dia. Para conferir a mao, `SHOW GRANTS FOR` o usuario e o host
   (mostra os privilegios, nunca a senha).

0i. **Pendencia 16c: tecnicos de N1 e N2 sem regra na primeira competencia
   do motor**, na data do corte planejada (D2: o 1o dia da competencia
   seguinte ao deploy, dia 16). Antes da 011, a variante e sem o filtro
   `fora_mapa_escala` (conta tambem quem vier a ser marcado fora do Mapa):

```bash
D='2026-11-11'; if [ "$(date -d "$D" +%d)" -ge 16 ]; then C=$(date -d "$(date -d "$D" +%Y-%m-01) +1 month" +%Y-%m-16); else C=$(date -d "$D" +%Y-%m-16); fi; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

   Referencia (2026-10-14): `ativos_n1_n2 = 22`, `sem_regra_no_corte = 6`;
   `sem_regra_na_competencia` a anotar. O ideal e a gestao cadastrar a regra
   de todos antes do dia: **com qualquer das duas colunas maior que 0 no dia,
   o registro do corte nao roda** (passo 7l); o deploy fica no ar sem corte.

0j. **0a2: `APP_ENV` do servidor.** A decisao de 2026-10-02 e trocar
   `staging` por `production` na janela de deploy final; neste roteiro a
   troca entra no deploy 1 (passo 3c, decisao NN7, aprovada em 2026-11-09). Em
   `production`, a aplicacao **deixa de subir** com `SECRET_KEY` ausente,
   com menos de 32 caracteres ou com texto de exemplo, e o preflight nao
   confere a chave. A linha mostra o ambiente e so o tamanho da chave e se
   ela tem texto de exemplo (`1`) ou nao (`0`), nunca o valor:

```bash
awk -F= '/^APP_ENV=/{print "app_env=" $2} /^SECRET_KEY=/{v=substr($0, 12); gsub(/["\047]/, "", v); print "secret_key_caracteres=" length(v); print "secret_key_texto_de_exemplo=" (tolower(v) ~ /gere_uma_chave|troque|changeme|change_me/)}' /var/www/.env
```

   Esperado: `app_env=staging`, `secret_key_caracteres` 32 ou mais e
   `secret_key_texto_de_exemplo=0`. Diferente: **nao troque o `APP_ENV`**
   no dia (pule o passo 3c) ate a chave ser gerada de novo pelo responsavel.
   As demais travas (`APP_DEBUG`, `DB_USER`, `DB_PASS`, `AD_ADMIN_USERS`,
   `FORCE_HTTPS`) sao conferidas pelo preflight do commit validado no 0r.
   Junto, confira no `.env` (a olho, sem copiar valor): `AD_ADMIN_USERS` com
   o login do responsavel (sem ela, ninguem entra como admin; o preflight
   reprova), `ENABLE_LOCAL_ADMIN` desligado e `HEALTH_ALLOWED_IPS` com o
   endereco da monitoracao, se houver uma (sem ela, o health responde `403`
   para fora da VM; inventario, secao 4).

0k. **`gitleaks` instalado na estacao** (verificacao de segredos dos commits
   do deploy; instalar e decisao do responsavel, dependencia nova da
   estacao). Na estacao (PowerShell 5.1), no clone do repositorio, depois de
   publicar o branch:

```powershell
git fetch origin; gitleaks version; gitleaks git --redact --log-opts="596db67c67a9cd84e53b28aaef4c3316191fe87c..origin/feat/employee-form-rhf-zod" .
```

   Esperado: a versao (8.19 ou mais nova, que tem o subcomando `git`; nas
   anteriores, `gitleaks detect --redact --log-opts=...`: conferir na
   versao instalada) e `no leaks found`. Achado: **nao siga**; segredo
   exposto segue a regra de sempre (revogar, gerar outro, tirar do
   historico). Os commits do `596db67` ao `d1cbbc5` ja tiravam o dominio do
   AD dos exemplos: um achado no historico anterior a ele e conhecido e vai
   para o responsavel decidir.

0l. **Logs e `curl` das paginas legadas** (9.12; so contagens, sem IP nem
   usuario), com o codigo atual no ar: quantos usaram as paginas nos ultimos
   dias e o que elas respondem hoje.

```bash
sh -c 'cat /var/log/apache2/chronodesk_access.log /var/log/apache2/chronodesk_access.log.1 2>/dev/null; zcat /var/log/apache2/chronodesk_access.log.*.gz 2>/dev/null' | awk '{print $6, $7, $9}' | sed -E 's/\?[^ ]*//' | grep -E '^"(GET|POST) /(admin|admin_login|admin_logout|login|logout|metricas|index)\.php |^"GET /static/js/admin(_users)?\.js ' | sort | uniq -c
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "http://$S$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "http://$S$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "http://$S/static/js/$j")"; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado hoje: so contagens por metodo, caminho e codigo (anote; uso
   maior que `0` nas paginas `POST` e alguem que precisa ser avisado no
   aviso a gestao); no `curl`, `GET 302` no `/` (regra local do `^$`) e as
   demais respostas as de hoje, antes da retirada (anote). Registre os
   numeros na secao 9.12 do desenho. O passo 7c repete o `curl` com o
   esperado novo.

0m. **Espaco livre, com o limite minimo** (so tamanhos). O passo 3d repete
   o mesmo comando no dia, antes do backup.

   **O que o deploy ocupa.** Medido em 2026-11-09 com o
   `frontend/package-lock.json` do commit, num cache vazio. Os pacotes
   nativos do Linux (`@rolldown/binding-linux-x64-*` e
   `lightningcss-linux-x64-*`) tem o tamanho dos do Windows.
   - `npm ci`: `node_modules` com **114 MB**. Se a versao do npm do servidor
     instalar as variantes `gnu` e `musl` juntas, chega a uns **150 MB**
     (TO CONFIRM pela versao).
   - Cache do npm: **27 MB** com o cache vazio. Com o cache que o build do
     `596db67` deixou, cresce menos. No shell root, o cache e o do root (em
     geral `/root/.npm`, na `/`); o comando confere o caminho.
   - Build (`app/`): **0,9 MB**.
   - Backup do dia (passo 3d): menos de **2 MB** (banco de 1,8 MB, com
     `gzip`).
   - Ensaios das migrations (0q, na vespera): poucos MB no datadir do
     MySQL, um banco de cada vez, apagado no fim de cada ensaio.
   - Extracoes do commit: **7 MB** em `/tmp` (passo 5) e **1 MB** em `/root`
     (0q e passo 4a).

   O `npm ci` apaga o `node_modules` antigo antes de instalar, mas o minimo
   nao conta com isso. Somando tudo no pior caso, o deploy pede uns
   **175 MB em `/var`** (codigo, Docker e `/var/backups`) e uns **50 MB em
   `/`** (cache do npm, `/root` e, se estiver nela, `/tmp`).

   **Limites minimos** (perto de tres vezes o pior caso):
   - codigo (`/var/www/chronodesk`): **500 MB**;
   - cache do npm: **200 MB**;
   - `/var/backups`: **100 MB**;
   - raiz do Docker: **200 MB**;
   - `/tmp`: **50 MB**;
   - `/root`: **50 MB**.

   Caminhos no mesmo disco aparecem com o mesmo numero livre. Os 500 MB de
   `/var` cobrem a soma dos tres caminhos que estao nele.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
```

   Esperado: seis linhas `OK` e `espaco livre: OK`. Com os numeros de
   2026-11-09 (1,2 GB livres em `/var`, 599 MB em `/`), passa. Qualquer
   `BAIXO`, ou `PARE: espaco livre abaixo do minimo`: **pare** e avise. Quem
   libera espaco e a infraestrutura; nada se apaga de improviso (logs,
   imagens do Docker, backups).

0n. **Ensaio do BIZ-02 na copia `sistema_pausas_ensaio`, na propria VM** (so
   contagens).
   - **A copia.** O responsavel a criou em 2026-11-09, no mesmo container,
     restaurando o backup pre-dia das 15:49 (33 tabelas). O `mysqldump`
     padrao grava os `TIMESTAMP` em UTC e os restaura convertendo, e os
     `DATETIME` vao literais: a copia e fiel com qualquer fuso de sessao.
   - **O ensaio.** A correcao do fuso (`migrations/correcao_dados/`) nunca
     rodou em MySQL. O ensaio roda os quatro scripts na copia, na ordem da
     secao 7.3 de `docs/FUSO_HORARIO_BIZ02.md`. O banco de producao so e
     lido.
   - **Depois do ensaio, a copia fica.** Ela e a origem dos ensaios das
     migrations, logo depois, na vespera (0q, `ORIGEM=sistema_pausas_ensaio`).
     O script 4 devolve os dados ao "antes"; ficam nela so as duas tabelas
     `manutencao_*`, que os ensaios do 0q nao copiam.
   - **Para repetir o 0n**, e preciso uma copia nova: o script 1 nao
     fotografa duas vezes.
   - **LGPD.** A copia tem dados pessoais. O ultimo comando do 0q a apaga,
     com confirmacao, na propria vespera.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT 'tabelas' AS item, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas') AS producao, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas_ensaio') AS copia UNION ALL SELECT 'manutencao_*', (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas' AND TABLE_NAME LIKE 'manutencao%'), (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas_ensaio' AND TABLE_NAME LIKE 'manutencao%') UNION ALL SELECT 'gatilhos, rotinas e eventos', (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'sistema_pausas') + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'sistema_pausas') + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = 'sistema_pausas'), (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'sistema_pausas_ensaio') + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'sistema_pausas_ensaio') + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = 'sistema_pausas_ensaio') UNION ALL SELECT 'funcionarios', (SELECT COUNT(*) FROM sistema_pausas.funcionarios), (SELECT COUNT(*) FROM sistema_pausas_ensaio.funcionarios) UNION ALL SELECT 'pausas', (SELECT COUNT(*) FROM sistema_pausas.pausas), (SELECT COUNT(*) FROM sistema_pausas_ensaio.pausas) UNION ALL SELECT 'audit_log', (SELECT COUNT(*) FROM sistema_pausas.audit_log), (SELECT COUNT(*) FROM sistema_pausas_ensaio.audit_log);"; unset P
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; export P; for S in biz02_01_registrar_corte biz02_02_verificar biz02_03_corrigir biz02_02_verificar biz02_04_rollback biz02_02_verificar; do export S; echo "=== $S"; git cat-file -e "origin/feat/employee-form-rhf-zod:migrations/correcao_dados/$S.sql" && bash -o pipefail -c 'git show "origin/feat/employee-form-rhf-zod:migrations/correcao_dados/$S.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas_ensaio' || { echo "PARE: $S falhou"; break; }; done; unset P; unset S
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas_ensaio' AND TABLE_NAME LIKE 'manutencao%') AS manutencao_na_copia, (SELECT COUNT(*) FROM sistema_pausas_ensaio.manutencao_marcadores WHERE chave = 'biz02_corte') AS corte_na_copia, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sistema_pausas' AND TABLE_NAME LIKE 'manutencao%') AS manutencao_na_producao;"; unset P
```

   O que conferir (tabela da secao 7.4 do BIZ-02; anote **so contagens**: o
   V8 e o V9 listam IDs internos de chamado critico, sem dado pessoal, e
   deles se anota so quantas linhas vieram):

   - **Copia, antes:**
     - `tabelas` 33 e 33;
     - `manutencao_*` 0 e 0;
     - `gatilhos, rotinas e eventos` 0 e 0 (o backup leva gatilhos,
       rotinas e eventos, e o `596db67` nao tem nenhum: um evento na copia
       rodaria sozinho);
     - `funcionarios`, `pausas` e `audit_log` na copia iguais ou menores que
       na producao (a copia e das 15:49 de 09/11, e a producao continua
       gravando).

     Copia ausente (`copia = 0`), `manutencao_*` na copia maior que 0 (o 0n
     ja rodou nela) ou gatilho, rotina ou evento maior que 0: **pare**.
   - **Script 1 (`biz02_01`):** `CORTE REGISTRADO` com
     `g1_linhas_formato_novo = 0`, `g2_saltos_auditoria = 0` e
     `g3_minutos_fora_de_utc = 0`, e a contagem por coluna (anote: e a
     referencia do passo 6c). **`ABORTADO PELAS GUARDAS` aqui = pare**: a
     causa (dado antigo ja no fuso novo, salto no `audit_log`, servidor fora
     de UTC) precisa de analise antes do deploy, e e ela que pode empurrar a
     data, nao o tempo do ensaio.
   - **Script 2, antes:** V2 `corrigida = 0` e `a_corrigir` igual a
     `fotografadas`; V5 `decisao_antes_do_pedido = 0`; V7 `fora_de_2_min =
     0` (ou residual); V6 `outra`, V8, V9 e V10: anote as quantidades (sao a
     revisao manual depois da correcao).
   - **Script 3:** `CORRECAO EXECUTADA` e `nao_corrigidas = 0`.
   - **Script 2, depois:** V2 `a_corrigir = 0` e `corrigida` igual ao
     `a_corrigir` de antes; V3 `no_futuro = 0`; **V5
     `decisao_antes_do_pedido = 0`** (maior que `0` = correcao indevida:
     pare); V6 `utc_3h_adiante = 0`; V4 com o volume entre 08h e 23h.
   - **Script 4:** `ROLLBACK EXECUTADO`. **Script 2, por fim:** igual ao
     "antes".
   - **Copia, depois:** `manutencao_na_copia = 2`, `corte_na_copia = 1` e
     `manutencao_na_producao = 0` (nada foi para a producao). A copia **nao**
     e apagada aqui: o 0q le dela e a apaga no fim. Se o 0n parar, o deploy
     nao segue: apague a copia com o ultimo comando do 0q e trate a causa.

   E o primeiro uso real desses scripts: erro de sintaxe ou de collation
   aparece aqui, sem risco para a producao. Na producao, so o script 1 roda
   no dia (passo 6c); a correcao (scripts 2 e 3) fica para depois do backup
   instalado e testado (passo 9c).

0o. **Roteiro de navegador `-Browser`, OBRIGATORIO, na estacao, sobre o
   commit que vai ser gravado** (decisao de 2026-10-30). O responsavel
   publica o `feat/employee-form-rhf-zod` e roda a validacao completa numa
   copia limpa desse commit (worktree), na estacao (PowerShell 5.1, uma
   linha):

```powershell
git fetch origin; git worktree add --detach ..\chronodesk-deploy1 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy1\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD
```

   Esperado: `Validacao local concluida com sucesso`, a linha do roteiro de
   navegador com `falharam: 0` e o hash completo do `git rev-parse HEAD`:
   anote-o como `<commit validado na estacao>` (o passo 2 so grava esse
   commit). Qualquer falha: nao ha deploy com esse commit. Depois do deploy,
   o worktree sai com `git worktree remove ..\chronodesk-deploy1` (feito pelo
   responsavel).

0p. **Aviso a gestao, UM so para o deploy 1, em prosa, para o Teams** (a
   Lideranca e aos gestores), enviado antes do dia. Os marcadores sao
   preenchidos **no dia do envio**, com as consultas rodadas nesse dia
   (nunca com os numeros de uma medicao antiga): `[N_PAS]` e o
   `pas_compartilhados_hoje` da Q-F6 (passo 0e); `[N_SEM_REGRA]` e o
   `n1_n2_sem_regra_hoje` da Q-F7 (passo 0f); `[DATA_CORTE]` e o `corte
   planejado` do passo 0i, em DD/MM. A data, a janela e o horario da
   indisponibilidade sao os das linhas do inicio deste roteiro (a trava
   confere). Nao envie o texto com marcador. Nada do F8 nem do F9 entra no aviso. Quem usa o CSV
   de horas extras em outra ferramenta recebe o aviso tambem (Lote 6).

> Pessoal, na quarta-feira, 11/11, das 19:00 as 23:00, o ChronoDesk recebe
> uma atualizacao grande. Ele fica fora do ar por cerca de 30 minutos,
> previstos entre 20:00 e 21:00, com o turno da noite em operacao, inclusive
> o registro de pausas: combinem com a Lideranca como registrar as pausas
> desse intervalo. O que voces vao perceber logo na volta: e preciso
> recarregar a pagina e entrar de novo, porque as sessoes abertas antes sao
> encerradas; e as datas de criacao, de atualizacao e de aprovacao passam a
> aparecer no horario de Brasilia, tres horas mais cedo do que aparecem
> hoje: e a hora certa, nao um erro. O que muda para voces. Varias senhas erradas seguidas bloqueiam o login daquele usuario
> por alguns minutos. Os anexos de escala passam a ser publicados para uma
> equipe ou para todas, e cada tecnico ve os da propria equipe. No CSV de
> horas extras, as colunas de total mudam: quem usa a planilha em outra
> ferramenta precisa ajustar. Na Escala presencial, cada troca de regra
> passa a guardar o historico: a regra anterior termina na vespera e a nova
> vale a partir da data escolhida. Entra o ciclo presencial e remoto, com
> previa obrigatoria antes de salvar, e a Lideranca passa a aparecer como
> presencial por regra fixa. Datas passadas so podem ser alteradas pela
> Lideranca e pelo admin. Hoje, [N_SEM_REGRA] tecnicos de N1 e N2 ainda nao
> tem regra de escala vigente: eles aparecem como "Sem escala" ate a regra
> ser cadastrada. Ferias, folgas, licencas e ausencias passam a ser lancadas
> so na tela Ausencias, tambem por parte do dia; a excecao de escala fica so
> para trabalho remoto, presencial, treinamento e plantao. Nao registrem
> informacao de saude na observacao. No Mapa PA, a situacao de cada tecnico
> passa a seguir a escala do dia, e o PA em que duas ou mais pessoas estao
> presenciais no mesmo dia aparece com contorno vermelho e os dias do
> conflito. E um aviso, nao um bloqueio: a alocacao continua podendo ser
> salva, e o conflito aceito fica registrado.
> Pelos numeros de hoje, ate [N_PAS] PAs podem aparecer assim ja no
> primeiro dia, e so admin e Lideranca editam o mapa. Na Visao Operacional, "CIs disponiveis" passa a
> contar pela escala do dia: conta quem esta escalado, dentro do expediente
> e sem pausa, almoco ou ausencia lancada, entao o numero pode ficar menor
> que o de hoje sem que isso seja falha. A Lideranca aparece numa linha a
> parte, fora dos totais, e a tela Tecnicos mostra a situacao de agora de
> cada um. O Calendario e os relatorios passam a usar a mesma escala a
> partir da competencia que comeca em [DATA_CORTE]; as competencias
> anteriores ficam como estao. No cadastro, o "Desativar" vira "Arquivar": o
> tecnico arquivado sai da escala, do Mapa PA e das ausencias futuras e o
> historico fica; restaurar e so com o admin, e o PA e a escala de quem
> volta sao cadastrados de novo. Tecnicos e perfis de leitura deixam de ver
> as observacoes e os textos livres dos colegas (ausencias, reunioes, horas
> extras, ajustes e plantoes) e, no Mapa PA, os logins e as observacoes dos
> vinculos; a gestao continua vendo. Na Escala presencial, a observacao das
> excecoes de escala continua visivel a todos os perfis: escrevam nela so o
> necessario ao trabalho, nunca informacao pessoal. As telas antigas de administracao e de
> metricas foram retiradas, e os enderecos antigos levam as telas novas.
> Duvidas, falem comigo.

0q. **Ensaios das migrations na vespera, na copia `sistema_pausas_ensaio`**
   (so contagens). Pela decisao RR7, revista em 2026-11-09, os 14 ensaios
   saem do dia. Cada ensaio:
   - le **so** da copia (`ORIGEM=sistema_pausas_ensaio`), nunca da
     producao;
   - cria o proprio `ensaio_<nome>`, roda a migration duas vezes e o
     rollback duas vezes, e apaga o `ensaio_<nome>` no fim.

   **Quando rodar.** Na vespera, depois do 0n e do 0o. O commit e o
   `<commit validado na estacao>`, o mesmo que o passo 2 grava no dia (la, a
   gravacao para se o branch apontar para outro commit). O commit precisa
   estar no clone: o `fetch` do 0a o traz. Se o 0o validou um commit
   publicado depois do 0a, rode o 0a de novo antes.

   **O ultimo comando apaga a copia**, com confirmacao: depois dos ensaios
   ela nao serve a mais nada, e tem dados pessoais.

   **O que muda com a copia como origem.**
   - O `funcionarios` do ensaio fica no estado do `596db67`, sem o indice e
     o ENUM da 016 nem a coluna da 011.
   - Isso nao afeta os ensaios (conferido nos arquivos): a 011, a 012, a 014
     e a 019 so mexem nas proprias tabelas, e a 014 declara que nao depende
     da 011; o `lideranca` da 011 e da 012 e o ENUM de `team` nas tabelas
     delas; a 016b tem a 016 como `PREPARO`.
   - Os esperados dos roteiros por lote valem. Muda so a lista de colunas de
     `funcionarios` no ensaio, e as contagens podem diferir das de hoje pelo
     que a producao gravou desde 09/11.
   - O "antes" da producao do dia e conferido no dia: o 0b de novo no passo
     4a e as verificacoes do passo 4.
   - Sem a copia, o ensaio para no `CREATE TABLE ... LIKE` e apaga o banco de
     ensaio que acabou de criar.

```bash
cd /var/www/chronodesk; export K='<commit validado na estacao>'; EV=$(mktemp -d /root/ensaio-vespera.XXXXXX); export EV; git cat-file -e "$K^{commit}" && bash -o pipefail -c 'git archive "$K" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV ($(git rev-parse --short "$K"))" || echo "PARE: o commit validado nao esta no clone (rode o 0a de novo) ou a extracao falhou"; unset K
env ORIGEM=sistema_pausas_ensaio bash "$EV/scripts/ensaio-migration.sh" d1_015 "$EV/migrations/20261001_015_login_user_throttle.sql" "$EV/migrations/rollback/20261001_015_login_user_throttle_down.sql"
env ORIGEM=sistema_pausas_ensaio bash "$EV/scripts/ensaio-migration.sh" d1_016 "$EV/migrations/20261002_016_lideranca_profile.sql" "$EV/migrations/rollback/20261002_016_lideranca_profile_down.sql"
env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261002_016_lideranca_profile.sql" bash "$EV/scripts/ensaio-migration.sh" d1_016b "$EV/migrations/20261002_016b_lideranca_profile_data.sql" "$EV/migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" audit_log
env ORIGEM=sistema_pausas_ensaio bash "$EV/scripts/ensaio-migration.sh" d1_017 "$EV/migrations/20261002_017_shift_attachment_team.sql" "$EV/migrations/rollback/20261002_017_shift_attachment_team_down.sql" portal_shift_attachments
env ORIGEM=sistema_pausas_ensaio DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261005_011_schedule_history_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_011 "$EV/migrations/20261005_011_schedule_history.sql" "$EV/migrations/rollback/20261005_011_schedule_history_down.sql"
env ORIGEM=sistema_pausas_ensaio VERIFICA="$EV/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_014 "$EV/migrations/20261013_014_employee_archive.sql" "$EV/migrations/rollback/20261013_014_employee_archive_down.sql"
env ORIGEM=sistema_pausas_ensaio DADOS=portal_pa_assignments,portal_schedule_rules,portal_schedule_exceptions,portal_absences VERIFICA="$EV/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_014_dados "$EV/migrations/20261013_014_employee_archive.sql" "$EV/migrations/rollback/20261013_014_employee_archive_down.sql"
env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261013_014_employee_archive.sql" VERIFICA="$EV/migrations/verificacao/20261013_014_employee_archive_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_014_rollback "$EV/migrations/rollback/20261013_014_ensaio_linhas_f4.sql" "$EV/migrations/rollback/20261013_014_employee_archive_down.sql"
env ORIGEM=sistema_pausas_ensaio VERIFICA="$EV/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_019 "$EV/migrations/20261013_019_presence_cutoff.sql" "$EV/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
{ printf "SET @corte := '2099-12-16'; SET @autor := 'qa.ensaio';\n"; cat "$EV/migrations/20261013_019_registrar_corte.sql"; } | tee "$EV/registro_d1.sql" > /dev/null; env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$EV/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_019_registro "$EV/registro_d1.sql" "$EV/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
{ printf "SET @corte := '2020-01-16'; SET @autor := 'qa.ensaio';\n"; cat "$EV/migrations/20261013_019_registrar_corte.sql"; } | tee "$EV/registro_d1_passado.sql" > /dev/null; env ORIGEM=sistema_pausas_ensaio PREPARO="$EV/migrations/20261013_019_presence_cutoff.sql" VERIFICA="$EV/migrations/verificacao/20261013_019_presence_cutoff_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_019_passado "$EV/registro_d1_passado.sql" "$EV/migrations/rollback/20261013_019_presence_cutoff_down.sql" audit_log
env ORIGEM=sistema_pausas_ensaio DADOS=portal_absences VERIFICA="$EV/migrations/verificacao/20261007_012_absences_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_012 "$EV/migrations/20261007_012_absences.sql" "$EV/migrations/rollback/20261007_012_absences_down.sql"
env ORIGEM=sistema_pausas_ensaio DADOS=portal_absences PREPARO="$EV/migrations/rollback/20261007_012_ensaio_linha_antiga.sql" VERIFICA="$EV/migrations/verificacao/20261007_012_absences_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_012_parada "$EV/migrations/20261007_012_absences.sql" "$EV/migrations/rollback/20261007_012_absences_down.sql"
env ORIGEM=sistema_pausas_ensaio DADOS=portal_absences PREPARO="$EV/migrations/20261007_012_absences.sql" VERIFICA="$EV/migrations/verificacao/20261007_012_absences_check.sql" bash "$EV/scripts/ensaio-migration.sh" d1_012_rollback "$EV/migrations/rollback/20261007_012_ensaio_linhas_f3.sql" "$EV/migrations/rollback/20261007_012_absences_down.sql"
case "$EV" in /root/ensaio-vespera.*) rm -r "$EV" && echo "ensaio da vespera removido";; *) echo "PARE: $EV fora do padrao, nada apagado";; esac; unset EV
read -rp 'Apagar a copia de ensaio? Digite o nome dela (sistema_pausas_ensaio): ' NC; if [ "$NC" = "sistema_pausas_ensaio" ]; then read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "DROP DATABASE IF EXISTS sistema_pausas_ensaio; SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'sistema_pausas_ensaio') AS copia_restante, (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'sistema_pausas') AS producao;"; unset P; else echo "nada apagado: nome diferente de sistema_pausas_ensaio"; fi; unset NC
```

   O que conferir (anote so contagens):
   - **Extracao:** `extraido em /root/ensaio-vespera.<sufixo> (<hash
     curto>)`, com o hash curto igual ao comeco do commit validado.
   - **015 (`d1_015`):** a tabela `login_user_throttle` aparece depois das
     duas execucoes da migration e some depois dos dois rollbacks (`DROP
     TABLE IF EXISTS`: o 2o rollback nao falha); `funcionarios` sem mudanca.
   - **016 (`d1_016`)**, como na secao "Ensaio de Migration":
     - as duas execucoes no mesmo estado, `access_role` com `lideranca` no
       fim do ENUM e o indice `uq_funcionarios_ad_login`;
     - o rollback volta ao checksum e as contagens do estado inicial, e o
       2o rollback tambem.
   - **016b (`d1_016b`):**
     - depois da 1a execucao, os `admin` da equipe Lideranca como
       `lideranca` e o `admin` inativo fora dela como `tecnico`, com um
       evento de cada no `audit_log` do ensaio (2026-10-02: Lideranca
       ativos 3, `n2`/`admin` ativos 2 inalterados, um `n2` inativo como
       `tecnico`);
     - a 2a execucao nao muda nada nem gera evento;
     - o rollback volta as contagens iniciais.

     **Anote as contagens por equipe, perfil e situacao depois da 1a
     execucao**: o passo 6d do dia tem de dar as mesmas, salvo cadastro
     mudado na producao desde 09/11.
   - **017 (`d1_017`):** a coluna `team` e o indice aparecem nas duas
     execucoes e somem nos dois rollbacks; as contagens de
     `portal_shift_attachments` nao mudam (o ensaio so copia a estrutura
     dela).
   - **011 (`d1_011`)**, como na secao "Ensaio de Migration" (011):
     - as duas execucoes iguais;
     - `colaboradores_com_mais_de_uma_aberta`, `sobreposicoes` e
       `inicio_repetido` em 0;
     - contagens e assinatura das colunas originais iguais as do estado
       inicial;
     - o 1o rollback de volta e o **2o rollback parando** de proposito.
   - **014 (`d1_014`, `d1_014_dados`, `d1_014_rollback`):** os tres do
     roteiro do F4, passo 3, com os mesmos esperados (a 014 nao depende da
     011 nem da 012; a verificacao trata tabela ausente).
   - **019 (`d1_019`, `d1_019_registro`, `d1_019_passado`):** os tres do
     roteiro do F5, passo 4, com os mesmos esperados (o registro de 2099
     grava e o 2o para; a data passada para nas duas).
   - **012 (`d1_012`, `d1_012_parada`, `d1_012_rollback`):** os tres do
     roteiro do F3, passo 3, com os mesmos esperados:
     - a tabela vazia migra;
     - a linha antiga faz a 012 parar sem alterar nada;
     - o rollback com linhas do F3 volta e o 2o para.
   - **Limpeza:** `ensaio da vespera removido`. Depois de digitar o nome da
     copia: `copia_restante = 0` e `producao = 1`. `nada apagado`: repita o
     ultimo comando com o nome certo.

   **Quando parar.** Parece com qualquer um destes: `FALHOU` onde nao era
   esperado, `migration_016_abortada_ad_login_duplicado` ou outro
   esperado diferente.
   - **O deploy do dia seguinte nao acontece** sem a causa resolvida. Nada
     foi alterado na producao.
   - **Mesmo com `PARE`, rode os dois ultimos comandos** (limpeza e copia).
   - **Repetir os ensaios** pede uma copia nova, criada do backup como em
     09/11, e o 0n de novo nela.

0r. **Conferencias da auditoria pre-deploy** (`docs/AUDITORIA_PRE_DEPLOY1.md`,
   2026-11-09; desenho 9.13.7), na vespera, depois do 0q, so leitura e so
   contagens:
   - **C1, preflight do commit validado contra o `/var/www/.env` real.** No
     dia o `APP_ENV` passa a `production` no passo 3c, com o codigo
     anterior no ar, e vale na hora (o `config.php` le o `.env` a cada
     requisicao). As checagens do preflight valem para qualquer `APP_ENV`
     diferente de `development` (`scripts/preflight-deploy.sh:61-90`), entao
     ja respondem com `staging`. O script so le o arquivo e imprime `OK` ou
     `FALHA`;
   - **C2, a VM alcanca o registro do npm** (o `npm ci` do passo 6e baixa
     os pacotes; o cache do root e o mesmo do dia);
   - **M9, C3 e A1:** admin com rotulo antigo de equipe (a 016b nao o
     reconheceria como Lideranca), a coluna `ad_login_ativo` (sem ela, todo
     login cai no caminho de reserva sem aviso) e o tamanho de
     `audit_log.username`, e as excecoes de escala com observacao, por tipo;
   - **Risco 6:** processos fora do Apache que gravam no banco (a manutencao
     nao os barra);
   - **Log de erros do PHP**, para o teste de fumaca do 6f: onde os erros
     do PHP vao parar. O `PHP_ERROR_LOG` do `.env` vence
     (`config.php:64-66`); sem ele, o `error_log` do pool ou do `php.ini`;
     sem os dois, o log de erros do vhost do Apache.

```bash
cd /var/www/chronodesk; export K='<commit validado na estacao>'; git cat-file -e "$K:scripts/preflight-deploy.sh" && bash -o pipefail -c 'git show "$K:scripts/preflight-deploy.sh" | sh -s -- /var/www/.env'; unset K
cd /var/www/chronodesk/frontend; npm ping && echo "registro do npm alcancado" || echo "PARE: a VM nao alcanca o registro do npm"
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM funcionarios WHERE access_role = 'admin' AND LOWER(TRIM(equipe)) IN ('na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS admin_rotulo_antigo, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'ad_login_ativo') AS ad_login_ativo, (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'username') AS audit_username_tamanho; SELECT exception_type, COUNT(*) AS n, SUM(note IS NOT NULL AND note <> '') AS com_observacao FROM portal_schedule_exceptions GROUP BY exception_type;"; unset P
echo "timers com chronodesk: $(systemctl list-timers --all 2>/dev/null | grep -ci chronodesk)"; echo "cron do root com chronodesk: $(crontab -l 2>/dev/null | grep -ci chronodesk)"; echo "cron do www-data, linhas ativas: $(crontab -l -u www-data 2>/dev/null | grep -v '^#' | grep -c .)"; echo "arquivos em /etc/cron* com chronodesk: $(grep -rlis chronodesk /etc/cron* 2>/dev/null | wc -l)"
echo "PHP_ERROR_LOG no .env: $(sed -n 's/^PHP_ERROR_LOG=//p' /var/www/.env | tr -d "\"'")"; grep -HE '^[[:space:]]*(error_log|php_admin_value\[error_log\]|php_value\[error_log\]|catch_workers_output)[[:space:]]*=' /etc/php/*/fpm/php.ini /etc/php/*/fpm/pool.d/*.conf; ls -l /var/log/apache2/*error*.log /var/log/php*-fpm.log 2>/dev/null
```

   Referencia (lida pelo responsavel em 2026-11-10, como root no `sudo -i`):
   preflight com `RESULTADO=0` (`APP_DEBUG`, `DB_USER`, `DB_PASS`,
   `ENABLE_LOCAL_ADMIN`, `AD_ADMIN_USERS` e `FORCE_HTTPS` `OK`; `AUTH_SOURCE`
   so aviso; `mod_headers` ativo), `npm ping` com `PONG` em 334 ms, `node`
   v22.23.4 e `npm` 10.9.9. Com `sudo` na frente, o mesmo preflight deu
   `FALHA` falsa em tudo (o `awk` nao rodou).

   Esperado:
   - **Preflight:** `RESULTADO=0`, com as mesmas linhas `OK` do passo 3b do
     dia. Qualquer `FALHA`: **o deploy nao acontece com esse `.env`**. O
     codigo novo aplica as mesmas travas tambem em `staging`
     (`config.php:126`). O responsavel corrige o `.env` antes do dia (sem
     copiar valor para chat ou documento) e o preflight roda de novo.
   - **npm:** `registro do npm alcancado` (o `npm ping` mostra `PONG` ou
     `Ping success`, conforme a versao). `PARE`: a infraestrutura libera o
     acesso antes do dia. O `ping` nao baixa pacote: falha de rede no 6e
     ainda e possivel, e o degrau 2 volta a copia do `app/` (passo 3a) sem
     `npm`.
   - **Contagens:**
     - `admin_rotulo_antigo = 0`. Maior: **pare**; a 016b rebaixaria esse
       admin, se inativo, a tecnico. O responsavel corrige a equipe pela
       tela antes do dia.
     - `ad_login_ativo = 1`. `0`: **pare**; o login do codigo novo cairia
       sempre no caminho de reserva. Decisao do responsavel antes do dia.
     - `audit_username_tamanho`: anote (o schema tem `50`; ampliar e o M10,
       depois do deploy; nao bloqueia).
     - Por tipo de excecao, `n` e `com_observacao`: anote. E a medida do A1
       (o `GET` da escala entrega a observacao a qualquer perfil); a
       correcao vem depois do deploy, e o aviso do 0p ja nao promete o
       contrario. Nao bloqueia.
   - **Processos:** as quatro contagens em `0`. Maior: **pare** e avise.
     Esse processo grava no banco durante a manutencao; o responsavel decide
     como para-lo na janela.
   - **Log do PHP:** o caminho do arquivo que recebe os erros do PHP, pela
     ordem acima. Anote-o como `<log de erros do PHP>` (marcador do 6f).
     Nenhum identificado: **pare** e combine com o responsavel antes do
     dia; sem ele, o 6f nao confere o log.

**1. No dia, antes de comecar.** Confirme:
- a data e a janela (**11/11/2026, das 19:00 as 23:00**) e quem acompanha
  presente;
- itens 0a a 0r feitos na vespera, com os numeros anotados;
- o ensaio do BIZ-02 (0n) sem guarda disparada e os 14 ensaios das
  migrations (0q) como esperado, sobre o mesmo commit validado, com a copia
  apagada (`copia_restante = 0`);
- aviso enviado;
- o `<commit validado na
  estacao>` e o `<log de erros do PHP>` (0r) em maos;
- ninguem gravando cadastro, escala, ausencia ou Mapa PA durante a janela;
- o shell root aberto com `sudo -i` e a conferencia do ambiente (o mesmo
  comando do 00) com oito linhas `OK` e `ambiente: OK`. Qualquer
  `BLOQUEADO`: **pare**, nada foi alterado.

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
```

Para os passos seguintes, o shell e um so (o shell root do `sudo -i`), na ordem (as variaveis `E`, `Q`,
`B` e `C` passam de um comando ao outro).

**2. Branch, commit gravado, fast-forward e regras locais do `.htaccess`.** Um
`git fetch` so do branch do deploy, a conferencia de que ele aponta para o
commit validado na estacao e a gravacao desse commit: os passos 3 a 7 usam
exatamente ele, sem novo `fetch`. Em seguida, o commit e conferido, a
conferencia de fast-forward (o commit no ar, `HEAD`, tem de ser ancestral do
commit gravado) e a das regras locais do `.htaccess` no commit gravado. O
diretorio `/var/backups/chronodesk` e criado aqui, no 1o comando (M1 da
auditoria): a gravacao do commit ja escreve nele.

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk && echo "diretorio de backup: $(stat -c '%a %U' /var/backups/chronodesk)"; B=$(git rev-parse --abbrev-ref HEAD); [ "$B" = "codex/modernizacao-ui-backend" ] && echo "branch OK: $B" || echo "PARE: o clone esta em $B, nao em codex/modernizacao-ui-backend"; unset B
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy1-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); git cat-file -e "$D1^{commit}" && git cat-file -e "$D1:scripts/ensaio-migration.sh" && git cat-file -e "$D1:migrations/20261013_019_registrar_corte.sql" && git cat-file -e "$D1:migrations/correcao_dados/biz02_01_registrar_corte.sql" && echo "deploy1-commit OK" || echo "PARE: deploy1-commit ausente ou incompleto"; unset D1
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); git merge-base --is-ancestor HEAD "$D1" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D1") commits a aplicar" || echo "PARE: o HEAD nao e ancestral do deploy1-commit (o merge --ff-only recusaria)"; echo "migrations 013, 018 ou 020 no commit: $(git ls-tree -r --name-only "$D1" migrations/ | grep -cE '_(013|018|020)_')"; unset D1
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); echo "regras locais no .htaccess do commit: $(git show "$D1:.htaccess" | grep -cxF -e 'RewriteBase /' -e 'RewriteRule ^var/www/chronodesk(/.*)?$ /app/ [R=302,L]' -e 'RewriteRule ^chronodesk(/.*)?$ /app/ [R=302,L]')"; git diff -U0 -- .htaccess | sed -n 's/^+\([^+].*\)$/\1/p' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | grep -v -e '^$' -e '^#' | grep -vxF --file=<(git show "$D1:.htaccess" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'); echo "fim das linhas locais que o commit nao tem"; unset D1
```

   Esperado: `diretorio de backup: 700 root`,
   `branch OK: codex/modernizacao-ui-backend`, o hash gravado (o
   mesmo do passo 0o), `deploy1-commit OK`, `fast-forward OK: N commits a
   aplicar` (o mesmo `N` do 0a), `migrations 013, 018 ou 020 no commit: 0`,
   `regras locais no .htaccess do commit: 3` e, antes de `fim das linhas
   locais que o commit nao tem`, **nenhuma linha ou so uma regra `^$` para
   `/app/`** (o commit a cobre na linha 122 do `.htaccess`: `^$` para `app/`
   em GET e HEAD, que com o `RewriteBase /` e o mesmo `/app/`; inventario,
   secao 6). Outra linha listada: **pare** (o `merge` descartaria uma regra
   que o servidor usa; o responsavel decide se ela entra no branch).

   **Se o `HEAD` nao for ancestral do commit gravado: PARE** (nada foi
   alterado). Quer dizer que o branch do servidor tem commits que o
   `feat/employee-form-rhf-zod` nao tem (feitos ou mesclados direto no
   servidor, ou um commit da fila que nao veio para o branch de trabalho).
   Conte-os, sem mostrar conteudo:
   `cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); echo "commits so no servidor: $(git rev-list --count "$D1"..HEAD)"; unset D1`.
   Opcoes, decididas pelo responsavel: (a) os commits ja estao no branch de
   trabalho com outro hash (o mesmo conteudo trazido por `cherry-pick`):
   confirmar com `git cherry`, na estacao, e, se for so isso, levar o
   servidor ao commit gravado com aprovacao explicita (o que estiver so no
   servidor se perde do branch local dele); (b) o conteudo falta no branch
   de trabalho: trazer os commits para o `feat/employee-form-rhf-zod` na
   estacao (merge ou `cherry-pick`, sem reescrever commit ja enviado),
   repetir a validacao do passo 0o e o deploy com o commit novo; (c) adiar o
   deploy. Nunca `merge` sem `--ff-only` no servidor: o commit no ar seria um
   que ninguem validou.

   **Depois do deploy, o branch `codex/modernizacao-ui-backend` no GitHub**
   (decisao NN10 do responsavel, 2026-11-09; a IA nao faz push): o branch
   local do servidor passa a apontar para o commit gravado, e o `origin`
   continua onde estiver ate o passo 9a. Decidido: opcao (1), **atualizar o
   `origin`** com um push sem `--force` do commit gravado para esse branch,
   da estacao, depois de um ciclo estavel, para o GitHub mostrar o que esta
   no ar; o push e do responsavel (passo 9a). Ate la, o `git status` do
   servidor diz "a frente do origin", sem efeito no deploy.

**3. Ponto de rollback, copias do `.htaccess` local e do `app/`, preflight,
`APP_ENV` e backup do banco.**

3a. Ponto de rollback, copia do `.htaccess` modificado no servidor (a
   mudanca local e descartada so no passo 6b, no mesmo comando do `merge`, e
   a copia volta no rollback de codigo, degrau 2) e copia do `app/` no ar
   (C2 da auditoria). O `app/` e o `node_modules/` nao sao versionados: sem
   a copia, a volta do codigo dependeria de um `npm ci` e de um build do
   commit anterior, justamente o que pode ter falhado no 6e. A copia volta
   no degrau 2, sem `npm`:

```bash
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; git rev-parse HEAD | tee /var/backups/chronodesk/pre-deploy-commit; echo "alteracoes locais: $(git status --porcelain | wc -l); fora do .htaccess: $(git status --porcelain | grep -vc '^ M \.htaccess$')"
cd /var/www/chronodesk; H=/var/backups/chronodesk/htaccess-local-antes-deploy1; if test -e "$H"; then cmp -s .htaccess "$H" && echo "copia do .htaccess local ja existe e e igual: $H" || echo "PARE: $H existe e e diferente do .htaccess atual"; else cp -p .htaccess "$H" && cmp -s .htaccess "$H" && echo "copia do .htaccess local: $H ($(stat -c '%a %U' "$H"))" || echo "PARE: copia do .htaccess falhou"; fi; unset H
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy1; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
```

   Esperado: o hash do codigo no ar (o `596db67` completo),
   `alteracoes locais: 1; fora do .htaccess: 0`, `copia do .htaccess local:
   /var/backups/chronodesk/htaccess-local-antes-deploy1 (... root)` e
   `copia do app/: /var/backups/chronodesk/app-antes-deploy1 (<tamanho>;
   <N> arquivos)`, perto de 1 MB (o build medido tem 0,9 MB). Uma linha do
   `diff -rq` ou `PARE`: **pare** (nada foi alterado; sem a copia, o degrau
   2 depende do `npm`).

3b. **Preflight da versao nova, ANTES da troca do `APP_ENV`** (C1 da
   auditoria), lido do commit gravado (o `596db67` nao tem o script). Ele so
   le o `.env`; o 0r ja o rodou na vespera com o mesmo commit:

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); git cat-file -e "$D1:scripts/preflight-deploy.sh" && bash -o pipefail -c 'git show "$D1:scripts/preflight-deploy.sh" | sh -s -- /var/www/.env'; unset D1
```

   Esperado: `RESULTADO=0`, com `AD_DOMAIN: OK`, `AD_SERVERS: OK`,
   `APP_DEBUG: OK`, `DB_USER: OK`, `DB_PASS: OK`, `ENABLE_LOCAL_ADMIN: OK`,
   `AD_ADMIN_USERS: OK`, `LOGIN_USER_MAX_FAILURES` e
   `LOGIN_USER_WINDOW_SECONDS` `OK` (ou `OK (padrao ...)`),
   `STATUS_PRESENCE_ENABLED: OK (ligado)` e `FORCE_HTTPS: OK`;
   `AUTH_SOURCE: AVISO` nao reprova (a variavel pode sair do `.env` depois).
   Qualquer `FALHA`: **pare**; nada foi alterado (o `APP_ENV` ainda e
   `staging`) e o 3c nao roda.

3c. **0a2: `APP_ENV` para `production`** (decisao NN7, aprovada em
   2026-11-09; so com o passo 0j e o 3b como esperado; senao, pule). Copia
   do `.env` antes, modo `600`, em `/var/backups/chronodesk`; sem a copia,
   nada muda. Tenha o comando de volta pronto no terminal antes de rodar
   este:

```bash
K=/var/backups/chronodesk/env-antes-app-env-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; sed -i 's/^APP_ENV=staging$/APP_ENV=production/' /var/www/.env; echo "app_env_production: $(grep -c '^APP_ENV=production$' /var/www/.env)"; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
```

   Esperado: `copia do .env: ... (600 root)` e `app_env_production: 1`. Com
   `app_env_production: 0`, a linha tem outro formato (aspas, espaco) e nada
   mudou: pare e avise.

   **O valor vale na hora, com o codigo anterior no ar**: o `config.php` le
   o `.env` a cada requisicao (`load_env()`; no `596db67` tambem), sem
   esperar a recarga do 6e. A partir daqui o `596db67` aplica as travas de
   `production`, inclusive a `SECRET_KEY` (0j) e o `FORCE_HTTPS`. Por isso o
   preflight vem antes (3b). Logo depois, de uma estacao, abra o portal e
   entre. Erro, tela em branco ou redirecionamento para `https://`: volte
   **na hora** com
   `sed -i 's/^APP_ENV=production$/APP_ENV=staging/' /var/www/.env`
   (vale na proxima requisicao, sem recarga), confira o portal de novo e
   **pare**.

3d. **Backup do banco e do estado da aplicacao, imediatamente antes das
   migrations.** A rotina de backup (`072ed16`) nao esta instalada (0a): o
   backup do dia e um `mysqldump` do root, com `pipefail`, conferido com
   `gzip -t` e `SHA256SUMS`, num diretorio do root (modo `700`), no mesmo
   formato que a "Restauracao em Incidente" le (`database.sql.gz`). Antes
   dele, o espaco livre de novo (o mesmo comando e os mesmos limites do 0m;
   o `npm ci` do passo 6e depende dele). O backup pre-dia de 09/11 nao
   substitui este: o banco mudou desde entao.

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
B=/var/backups/chronodesk/deploy1-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, as migrations nao rodam)"; unset P
```

   Esperado: seis linhas `OK` e `espaco livre: OK` (qualquer `BAIXO` ou
   `PARE`: **pare** antes do backup, nada foi alterado) e
   `backup concluido: /var/backups/chronodesk/deploy1-<data>
   (<tamanho>)`. Anote o diretorio: e o ponto de restauracao do banco (so
   como ultimo recurso). O dump tem dados pessoais (inclusive as copias de
   junho): fica so em `/var/backups/chronodesk`, root, `700`, e sai depois
   de um ciclo estavel, com aprovacao (passo 9e).

**4. Migrations antes do codigo, em ordem: o "antes", a aplicacao e a
verificacao de cada uma, so com contagens e regra de parada.**
- **Ordem**, a da fila antiga:
  - **015, 016, 017, 011, 014 e 019** aqui;
  - a **012** com o `git merge` (passo 6b; decisao 3 do F3: sem pausa
    entre a 012 e o codigo que a le);
  - a **016b** depois do `merge` (passo 6d: o codigo anterior leria
    `lideranca` como tecnico).

  A 013 foi dispensada; 018 e 020 ficam FORA (conferido no passo 2).
  Nenhuma destas muda o que o codigo anterior faz (ressalva da 016 na
  "Janela").
- **Os 14 ensaios foram na vespera** (0q, decisao RR7 revista): no dia nao
  ha ensaio.
- **No dia**, a conferencia do "antes":
  - o 0b de novo, no 4a;
  - a Q-F4 antes da 014;
  - a verificacao da 019 antes dela;
  - a Q4 antes da 012.

  Depois, a aplicacao e a verificacao de cada uma.
- **Os arquivos** saem do commit gravado, o mesmo dos ensaios da vespera.

4a. Extracao do commit gravado (so `migrations/`: as verificacoes e o
   rollback leem dela) e o "antes" de todas as migrations de novo (o mesmo
   comando do 0b), agora, imediatamente antes da 1a aplicacao:

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); E=$(mktemp -d /root/ensaio-deploy1.XXXXXX); export E; bash -o pipefail -c 'git archive "$D1" migrations | tar -x -C "$E"' && echo "extraido em $E" || echo "PARE: falha ao extrair o deploy1-commit"
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COLUMN_TYPE = 'enum(''tecnico'',''gestor'',''admin'',''somente_leitura'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_005, (SELECT COUNT(*) FROM (SELECT ad_login FROM funcionarios WHERE ad_login IS NOT NULL GROUP BY ad_login HAVING COUNT(*) > 1) d) AS ad_login_repetidos, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_perfil_016b') AS perfil_016b, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS anexo_team, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'reference_month') AS anexo_reference_month, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee') AS uq_regra_por_colaborador, (SELECT LOCATE('fixed_weekdays', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_type') AS rule_type_010, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_config') AS rule_config_010, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'team') AS team_regras_n1_n2, (SELECT COLUMN_TYPE = 'enum(''n1'',''n2'')' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions' AND COLUMN_NAME = 'team') AS team_excecoes_n1_n2, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('fora_mapa_escala', 'arquivado_em', 'arquivado_por')) AS colunas_011_014, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type') AS absence_type, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME IN ('category', 'period_type', 'cancelled_by')) AS colunas_012, (SELECT COUNT(*) FROM portal_absences) AS ausencias, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_0%') AS copias_de_rollback, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '_[0-9]{8}_[0-9]{6}$') AS copias_de_junho, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')) AS nao_innodb, ABS(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())) AS minutos_fora_de_utc, (SELECT COUNT(*) FROM mysql.time_zone_name) AS fusos_nomeados;"; unset P
```

   Esperado:
   - `extraido em /root/ensaio-deploy1.<sufixo>`. O diretorio fica ate o
     passo 7 (as verificacoes e o rollback leem dele); apague-o no 7m.
   - O "antes": o mesmo esperado do 0b (`ad_login_repetidos = 0`,
     `colunas_011_014 = 0`, `colunas_012 = 0`, `ausencias = 0`,
     `copias_de_rollback = 0`, `minutos_fora_de_utc = 0` e os demais).
     Diferente: **pare** antes de qualquer migration. Nada foi alterado: o
     backup do 3d fica e o portal segue no ar com o codigo anterior. Um
     cadastro com login AD repetido, gravado desde a vespera, faria a 016
     parar.

4b. **015 (Lote 7, SEC-03: limite de login por usuario)**: aplicacao e
   verificacao (ensaio `d1_015` na vespera). Sem a tabela, o codigo novo recusa **todo** login com 503.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261001_015_login_user_throttle.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261001_015_login_user_throttle.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "015 aplicada" || echo "PARE: a 015 nao foi aplicada"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM login_user_throttle) AS linhas_015;"; unset P
```

   Aplicacao: `015 aplicada`. Verificacao: `migration_015 = 1`, `linhas_015 = 0`.
   Diferente: **pare**.

4c. **016 (Lote 5a: perfil `lideranca` e login AD unico)**: aplicacao e
   verificacao (ensaios `d1_016` e `d1_016b` na vespera; a 016b so e
   aplicada no passo 6d).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261002_016_lideranca_profile.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261002_016_lideranca_profile.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "016 aplicada" || echo "PARE: a 016 nao foi aplicada"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login, (SELECT LOCATE('''lideranca''', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'access_role') AS access_role_com_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE access_role = 'lideranca') AS cadastros_lideranca;"; unset P
```

   Aplicacao: `016 aplicada`. `migration_016_abortada_ad_login_duplicado`:
   nada foi alterado; pare (o 0b do 4a devia ter dado
   `ad_login_repetidos = 0`). Verificacao: `uq_ad_login = 1`,
   `access_role_com_lideranca = 1`, `cadastros_lideranca = 0`.

4d. **017 (Lote 4, SEC-08: equipe do anexo de escala)**: aplicacao e
   verificacao (ensaio `d1_017` na vespera).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261002_017_shift_attachment_team.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261002_017_shift_attachment_team.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "017 aplicada" || echo "PARE: a 017 nao foi aplicada"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS migration_017, (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND INDEX_NAME = 'idx_shift_attachment_team') AS indice_017, (SELECT COUNT(*) FROM portal_shift_attachments WHERE team IS NOT NULL) AS anexos_com_equipe;"; unset P
```

   Aplicacao: `017 aplicada`.
   Verificacao: `migration_017 = 1`, `indice_017 = 1`,
   `anexos_com_equipe = 0` (os anexos de hoje ficam como "todas as
   equipes"). Diferente: **pare**.

4e. **011 (F1: historico de escala, Lideranca, `fora_mapa_escala`)**:
   aplicacao e verificacao (ensaio `d1_011` na vespera).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261005_011_schedule_history.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261005_011_schedule_history.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "011 aplicada" || echo "PARE: a 011 nao foi aplicada"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261005_011_schedule_history_check.sql"); unset P
```

   Aplicacao: `011 aplicada`. Verificacao: as tres
   primeiras contagens em `0`. Diferente: **pare** (o codigo anterior
   funciona sobre a 011; desfazer, so com o rollback dela, passo 8c).

4f. **Q-F4 imediatamente antes da 014** (D5; so contagens):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0) AS inativos, (SELECT COUNT(DISTINCT a.employee_id) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())) AS inativos_com_pa, (SELECT COUNT(DISTINCT r.employee_id) FROM portal_schedule_rules r JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 0 WHERE (r.effective_until IS NULL OR r.effective_until >= CURDATE()) AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from)) AS inativos_com_regra, (SELECT COUNT(*) FROM portal_schedule_exceptions e JOIN funcionarios f ON f.id = e.employee_id AND f.ativo = 0 WHERE e.exception_date >= CURDATE()) AS excecoes_de_inativos;"; unset P
```

   Referencia (2026-10-13): `inativos = 5`, `inativos_com_pa = 1`,
   `inativos_com_regra = 0`, `excecoes_de_inativos = 0`. Outros numeros nao
   bloqueiam a 014, mas mudam o passo 7f (D5): envie as contagens ao
   responsavel antes dele.

4g. **014 (F4: arquivamento)**: aplicacao e verificacao (os tres ensaios
   na vespera).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261013_014_employee_archive.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261013_014_employee_archive.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "014 aplicada" || echo "PARE: a 014 nao foi aplicada"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261013_014_employee_archive_check.sql"); unset P
```

   Aplicacao: `014 aplicada`. Verificacao: `colunas_014 = 2`,
   `check_014 = 1`, `copia_rollback = 0`, `arquivados_f4 = 0`,
   `inativos_sem_data` igual a `inativos` do passo 4f,
   `ativos_com_data = 0`, `arquivados_sem_autor = 0` e os efeitos
   pendentes iguais aos do 4f. Diferente: **pare**.

4h. **019 (F5: data de corte)**: verificacao antes, aplicacao e
   verificacao (os tres ensaios na vespera). O registro do corte so roda no passo
   7l.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261013_019_presence_cutoff.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261013_019_presence_cutoff.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "019 aplicada" || echo "PARE: a 019 nao foi aplicada"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < <(cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P
```

   Antes: como no passo 0g (`inconsistente = 0`). Aplicacao:
   `019 aplicada`. Verificacao: `tabela_019 = 1`, `checks_019 = 2`,
   `gatilhos_019 = 2`, `copia_rollback = 0`, `procedimentos_soltos = 0`,
   `cortes = 0`, `sem_autor = 0`, `eventos_registro = 0`,
   `eventos_remocao = 0` e **`inconsistente = 0`**. Diferente: **pare**.

4i. **Q4, o "antes" da 012 (F3: ausencias).** Os tres ensaios foram na
   vespera; a aplicacao fica para o passo 6b, no mesmo comando do
   `git merge`.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT COUNT(*) AS ausencias FROM portal_absences;"; unset P
```

   Q4: anote as contagens por
   tipo (se repetem no passo 7a); **`ausencias` tem de ser `0`** (maior que
   `0`: a 012 vai parar sem alterar nada; pare aqui e envie a contagem).

**5. Ensaio do codigo do commit gravado, fora da aplicacao no ar.** O commit
inteiro e extraido com `git archive` e `pipefail` num diretorio do `/tmp`,
passado so ele ao `www-data`, e todos os QAs PHP rodam ali com o PHP do
servidor, como `www-data`, sem banco, sem rede e sem tocar em
`/var/www/chronodesk`. O `/tmp` da VM tem `noexec`: o ensaio so chama
`php arquivo.php`. **Observacao 1 da pendencia 16:** o `config.php` procura o
`.env` um nivel acima do diretorio do ensaio, que e o proprio `/tmp`; por
isso o ensaio confere `[ ! -e /tmp/.env ]` antes dos QAs, para nao ler um
`.env` de fora.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); Q=$(mktemp -d /tmp/ensaio-deploy1-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D1" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy1-commit"
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
case "$Q" in /tmp/ensaio-deploy1-qa.*) rm -r "$Q" && echo "ensaio removido";; *) echo "PARE: $Q fora do padrao, nada apagado";; esac; unset Q
```

   Esperado: `/tmp/.env ausente: OK`, `extraido em
   /tmp/ensaio-deploy1-qa.<sufixo>`, nenhuma linha entre o `php -l` e `fim
   do php -l`, os 16 QAs com `OK` e `ensaio removido`. Qualquer `PARE` ou
   `FALHOU`: **pare** (o codigo no ar nao mudou; as migrations ja aplicadas
   nao mudam o que ele faz) e envie so o nome do QA e a ultima linha da
   saida dele (`runuser -u www-data -- php "$Q/scripts/<qa>.php" | tail -n 1`,
   antes de remover o ensaio). O `qa-auth` e o `qa-security` precisam do
   `php-ldap` (`php -m | grep -x ldap`). O `php -l` com o PHP do servidor e
   a primeira checagem do codigo novo nessa versao do PHP.

   **Falso negativo do `qa-security` no dia do deploy 1 (10/11/2026).** O
   servidor tem `ZipArchive` na CLI: o `api/download_relatorio.php`
   respondeu com o ZIP binario, o `json_encode` do processo filho do QA
   devolveu `false` e a linha `QA_RESULT` saiu vazia ("nao devolveu
   resultado"). Na estacao, sem `ZipArchive`, a resposta era o 503 em JSON
   e o QA passava. O contorno do dia foi um ajuste **so na copia temporaria
   do ensaio** (`$Q`, apagada no fim do passo), com `qa-security OK` nela;
   nada mudou em `/var/www/chronodesk`. A correcao entrou no commit A do
   pos-deploy 1 (`28fb746`, desenho 9.14.1): o deploy seguinte roda o
   `qa-security` sem contorno.

**6. Q-F6 e Q-F7 completas, base do criterio, manutencao, 012 com o `git
merge`, corte do BIZ-02, 016b, build, recarga, validacao e reabertura.**

**Shell root obrigatorio do 6b em diante.** No deploy 1, o `touch` do
arquivo de manutencao (6b; comando 89 da folha) falhou num terminal de
usuario comum. Antes do 6b: o prompt termina em `#` e `id -u` da `0`; se
nao, `sudo -i` e a conferencia do ambiente (passo 1) de novo. As variaveis
do terminal anterior se perdem: os comandos daqui leem o commit de
`/var/backups/chronodesk/deploy1-commit`, e a limpeza do 7m varre pelo
padrao do `mktemp`.

6a. **Imediatamente antes do `git merge`**, com a 011 e a 014 aplicadas: a
   Q-F6 completa (as duas consultas), a Q-F7 completa e o rotulo, e a base do
   criterio (medicao do `status.php` e do `dashboard.php` com o script do
   commit gravado, nas condicoes do 0d).

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_inventory WHERE active = 1) AS pas_inventario, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1) AS vinculos_ativos, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_vigentes_ou_futuros, (SELECT COUNT(*) FROM (SELECT pa_number FROM portal_pa_assignments WHERE active = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE()) GROUP BY pa_number HAVING COUNT(*) > 1) t) AS pas_compartilhados_hoje, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.ativo = 0) AS vinculos_de_inativos, (SELECT COUNT(*) FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND f.fora_mapa_escala = 1) AS vinculos_fora_do_mapa, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND team = 'lideranca' AND (valid_until IS NULL OR valid_until >= CURDATE())) AS vinculos_lideranca;"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM portal_pa_assignments a JOIN portal_pa_assignments b ON b.employee_id = a.employee_id AND b.id > a.id AND b.active = 1 AND b.valid_from <= COALESCE(a.valid_until, '9999-12-31') AND COALESCE(b.valid_until, '9999-12-31') >= a.valid_from WHERE a.active = 1) AS sobreposicoes_mesmo_colaborador, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND valid_until < valid_from) AS periodo_invertido, (SELECT COUNT(*) FROM portal_pa_assignments a WHERE a.active = 1 AND NOT EXISTS (SELECT 1 FROM portal_pa_inventory i WHERE i.active = 1 AND i.pa_number = a.pa_number)) AS vinculos_fora_do_inventario, (SELECT COUNT(*) FROM portal_pa_assignments a LEFT JOIN portal_schedule_rules r ON r.employee_id = a.employee_id AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()) WHERE a.active = 1 AND a.valid_from <= CURDATE() AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()) AND a.team <> 'lideranca' AND a.schedule_rule_type <> COALESCE(IF(r.rule_type = 'cycle', 'undefined', r.rule_type), 'undefined')) AS copia_tipo_divergente_hoje, (SELECT COUNT(*) FROM portal_pa_assignments WHERE active = 1 AND notes IS NOT NULL AND notes <> '') AS vinculos_com_observacao, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active') AS legado_total, (SELECT COUNT(*) FROM portal_pa_map WHERE record_status = 'active' AND work_date >= CURDATE()) AS legado_futuro;"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n1') AS ativos_n1, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) = 'n2') AS ativos_n2, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_lideranca, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND LOWER(TRIM(equipe)) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe')) AS ativos_outras_equipes, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND fora_mapa_escala = 1) AS fora_do_mapa, (SELECT COUNT(*) FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(TRIM(f.equipe)) IN ('n1', 'n2') AND NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= CURDATE() AND (r.effective_until IS NULL OR r.effective_until >= CURDATE()))) AS n1_n2_sem_regra_hoje, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND jornada_entrada >= jornada_saida) AS jornada_invertida, (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND NOT (jornada_entrada <= almoco_inicio AND almoco_inicio < almoco_fim AND almoco_fim <= jornada_saida)) AS almoco_fora_da_jornada, (SELECT COUNT(*) FROM portal_absences WHERE status = 'approved' AND starts_on <= CURDATE() AND ends_on >= CURDATE()) AS ausencias_aprovadas_hoje, (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE exception_date = CURDATE() AND exception_type IN ('day_off', 'absence', 'vacation', 'leave')) AS excecoes_de_ausencia_hoje;"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT COUNT(*) AS rotulo_que_o_php_nao_reconhece FROM funcionarios WHERE ativo = 1 AND (CAST(LOWER(TRIM(equipe)) AS BINARY) NOT IN ('n1', 'n2', 'lideranca', 'liderança', 'na', 'não se aplica', 'nao se aplica', 'nao_se_aplica', 'sem_equipe') OR LOCATE(CAST('Ç' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0 OR LOCATE(CAST('Ã' AS BINARY), CAST(TRIM(equipe) AS BINARY)) > 0);"; unset P
cd /var/www/chronodesk; D1=$(cat /var/backups/chronodesk/deploy1-commit); git cat-file -e "$D1:scripts/medir-polling.sh" && bash <(git show "$D1:scripts/medir-polling.sh")
git cat-file -e "$D1:scripts/medir-polling.sh" && URL=http://127.0.0.1/api/portal/dashboard.php bash <(git show "$D1:scripts/medir-polling.sh"); unset D1
```

   Q-F6: **`vinculos_de_inativos = 1`** (o caso da D5, que o passo 7f
   regulariza) e **`vinculos_fora_do_mapa = 0`**; os demais, como no 0e;
   diferente disso, **pare e avise** (nada do codigo mudou). Q-F7 e rotulo:
   `ativos_outras_equipes`, `jornada_invertida`, `almoco_fora_da_jornada` e
   `rotulo_que_o_php_nao_reconhece` em `0` (maior: **pare**);
   `n1_n2_sem_regra_hoje` e `pas_compartilhados_hoje` maiores que os do
   aviso: avise a gestao com o numero novo (nao bloqueia). Base: anote p50 e
   p95 dos dois endpoints (o passo 7j compara). A base e medida com o
   `596db67` no ar; o PERF-01 entra junto com o motor, entao a comparacao do
   7j mede os dois juntos (decisao a registrar na 9.9 com os numeros).

6b. **Manutencao, 012 e `git merge --ff-only` do commit gravado.** O
   arquivo-sinal e criado antes e passa a valer no `merge` (a regra vem no
   `.htaccess` novo). No mesmo comando, com `pipefail`: o `git cat-file -e`
   confere o arquivo, a 012 roda, a mudanca local do `.htaccess` e
   descartada (a copia esta no passo 3a; as regras estao no commit, passo 2)
   e o `merge` roda, cada um so se o anterior der certo. Sem `pull` e sem
   `fetch`. Depois, a conferencia do `HEAD` e a da manutencao vista de fora
   da VM:

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261007_012_absences.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261007_012_absences.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && git checkout -- .htaccess && git merge --ff-only "$D1"; unset P
[ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/deploy1-commit)" ] && echo "HEAD = deploy1-commit" || echo "PARE: HEAD diferente do deploy1-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
B2=/var/backups/chronodesk/deploy1-manutencao-$(date +%Y%m%d-%H%M%S); export B2; read -rsp 'Senha root do MySQL: ' P; echo; export P; echo "aguardando 10 s (requisicoes em andamento terminam)"; sleep 10; install -d -o root -g root -m 700 "$B2" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B2/database.sql.gz" > /dev/null' && gzip -t "$B2/database.sql.gz" && tar -czf "$B2/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B2" && echo "backup em manutencao concluido: $B2 ($(du -sh "$B2" | cut -f1))" || echo "PARE: backup em manutencao falhou (o corte do BIZ-02 e a 016b nao rodam)"; unset B2; unset P
```

   Esperado: `arquivo de manutencao: presente`; a 012 aplicada e o `merge`
   em fast-forward, sem erro; `HEAD = deploy1-commit`,
   `alteracoes locais: 0`; `portal visto de fora (manutencao): 503`. O
   `curl` usa `http://` com o `ServerName`: com `https`, a VM responde `200`
   de outro site (o `*:443` nao e o portal), o que parece "a manutencao nao
   vale" sem ser. Sem `503` no `http` (o `ServerName` resolve para o
   loopback na VM, que passa pela manutencao de proposito): confira de uma estacao,
   no navegador, que o portal mostra "ChronoDesk temporariamente
   indisponivel."; se mostrar a tela, a manutencao nao vale e o corte do
   BIZ-02 corre risco de linha nova: **pare** e avise.

   **Espera de 10 s e backup com o portal parado** (A2 e risco 5 da
   auditoria). A manutencao so passa a valer no `merge`: e a partir dele que
   nenhuma requisicao nova entra. A espera de 10 s fica logo depois, antes
   do corte do BIZ-02, como no procedimento original do BIZ-02
   (`docs/FUSO_HORARIO_BIZ02.md`, secao 6). Assim, uma requisicao do codigo
   anterior que ainda esteja gravando termina antes da fotografia. Em
   seguida, o segundo `mysqldump`, o unico com o portal parado. Ele cobre o
   que o turno gravou entre o 3d e o `merge`, ja com as migrations 015 a
   019 e a 012, antes do corte do BIZ-02 e da 016b. Esperado:
   `backup em manutencao concluido:
   /var/backups/chronodesk/deploy1-manutencao-<data> (<tamanho>)`; anote o
   diretorio. `PARE`: fique em manutencao, nao rode o 6c nem a 016b e avise
   o responsavel (o ponto de restauracao seria so o do 3d).

6c. **Corte do BIZ-02 (`biz02_01`), logo depois do `merge` e antes de
   qualquer gravacao do codigo novo** (antes da 016b, da recarga, das
   validacoes e do registro do corte da 019, que gravam no `audit_log` no
   fuso novo; inventario, secao 3.2). Depois, o que mudou em pontos
   sensiveis e a verificacao da 012 (agora da arvore):

```bash
cd /var/www/chronodesk; read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/correcao_dados/biz02_01_registrar_corte.sql; unset P
git diff --stat "$(cat /var/backups/chronodesk/pre-deploy-commit)" HEAD -- migrations/ scripts/backup-chronodesk.sh .htaccess
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261007_012_absences_check.sql; unset P
```

   Esperado no corte: `CORTE REGISTRADO`, `g1_linhas_formato_novo = 0`,
   `g2_saltos_auditoria = 0`, `g3_minutos_fora_de_utc = 0`, o marcador
   `biz02_corte` e a contagem por coluna proxima da do ensaio (0n), com
   alguma linha a mais do que foi gravado desde entao. **`ABORTADO PELAS
   GUARDAS`: pare**, fique em manutencao, nao rode a 016b nem a recarga e
   envie a saida ao responsavel. Ele decide entre analisar a causa e seguir
   com `@ignorar_guardas = 1` (secao 7.1 do BIZ-02) ou o degrau 2. No
   `diff --stat`: o `.htaccess`; em `migrations/`, os arquivos da 011, 012,
   014, 015, 016, 016b, 017 e 019 (com rollback e verificacao), do ciclo do
   F2B e de `correcao_dados/` (BIZ-02); o `scripts/backup-chronodesk.sh`
   novo (a rotina e instalada depois, passo 9c). Verificacao da 012:
   `colunas_012 = 10`, `absence_type = 0`,
   `status_tipo = enum('approved','cancelled')`, `fk = 1`, `indice = 1`,
   `checks = 2`, `copia_rollback = 0`, `linhas = 0`; na segunda consulta,
   tudo `0`.

6d. **016b (Lote 5b: normalizacao dos perfis)**, com o codigo novo na
   arvore e o portal em manutencao, depois do corte do BIZ-02 (grava no
   `audit_log`):

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/20261002_016b_lideranca_profile_data.sql" && bash -o pipefail -c 'git show "$D1:migrations/20261002_016b_lideranca_profile_data.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "016b aplicada" || echo "PARE: a 016b nao foi aplicada"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT equipe, access_role, ativo, COUNT(*) AS cadastros FROM funcionarios GROUP BY equipe, access_role, ativo ORDER BY equipe, access_role, ativo; SELECT action, COUNT(*) AS eventos FROM audit_log WHERE action IN ('LIDERANCA_PERFIL_MIGRADO', 'ADMIN_INATIVO_REBAIXADO') GROUP BY action; SELECT passo, COUNT(*) AS registrados FROM funcionarios_perfil_016b GROUP BY passo;"; unset P
```

   Esperado: `016b aplicada`; as contagens por equipe, perfil e situacao
   iguais as do ensaio da 016b (0q, na vespera, depois da 1a execucao;
   um cadastro mudado desde 09/11 explica diferenca de uma linha, que vai
   anotada); um evento
   de cada tipo (so dos passos com linhas alteradas); `funcionarios_perfil_016b`
   com as linhas de cada passo. Diferente: **pare** (com o codigo novo, a
   reversao e o `016b_down`, degrau 2). Repetir a 016b nao muda nada.

6e. **Build do frontend e recarga do PHP-FPM** (limpa o OPcache; `reload` e
   gracioso). Nenhum lote muda o vhost; o `.htaccess` e lido a cada
   requisicao: a recarga do Apache nao e necessaria. Espaco: o `npm ci`
   ocupa uns 114 MB (ate 150) em `/var` e ate 27 MB de cache na `/`; o
   build, 0,9 MB. O minimo foi conferido no passo 3d.
   - **Se o `npm ci` ou o build falhar por falta de espaco** (`ENOSPC`): o
     `node_modules` fica incompleto e o `app/` pode ficar antigo ou pela
     metade. Fique em manutencao, nao recarregue o PHP-FPM e avise a
     infraestrutura. Com o espaco liberado, repita a linha.
   - **Se o `npm ci` falhar por rede** (registro fora do ar, proxy ou DNS:
     `ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED`, `EAI_AGAIN`, `ENOTFOUND`,
     `E403`, `E404`, `E5xx` do registro ou `EINTEGRITY`): **pare**, fique em
     manutencao e nao recarregue o PHP-FPM. Repita a linha **uma vez** so
     depois de a infraestrutura confirmar o acesso (o 0r conferiu na
     vespera).
   - **Sem como resolver** (espaco ou rede) dentro da janela: degrau 2, com
     aprovacao. A volta do `app/` e a copia do 3a, sem `npm` (C2 da
     auditoria).

```bash
cd /var/www/chronodesk/frontend && npm ci && npm run build
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

6f. **Validacao ainda em manutencao, pela propria VM** (o loopback passa
   pelo bloqueio): health, o QA do fuso contra o banco real (so le:
   `SELECT` de expressoes e `SET` de variaveis de sessao) e o **teste de
   fumaca** (C3 da auditoria). Ate aqui o codigo novo nunca fez um login nem
   uma gravacao num MySQL de verdade: os QAs usam banco simulado. Nao ha
   homologacao.

```bash
curl -s -o /dev/null -w 'health: %{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
cd /var/www/chronodesk; env PHPLOG='<log de erros do PHP>' bash scripts/smoke-deploy1.sh '<ServerName do vhost>'
```

   Esperado: `health: 200`; `sessao MySQL em America/Sao_Paulo` e `QA
   timezone (banco real) OK`. Se o usuario da aplicacao nao puder usar `SET
   timestamp`, o QA falha em vez de pular: use a alternativa manual da secao
   8.3 do BIZ-02 (como root) e siga se ela der o esperado. Health
   diferente de `200` ou o fuso errado: **pare** (degrau 2, com aprovacao).

   **Teste de fumaca** (`scripts/smoke-deploy1.sh`, do commit gravado, ja na
   arvore). Pede o login e a senha do AD do responsavel (admin pela
   allowlist; a senha sem eco). So mostra codigos e contagens:
   - `login: 200`;
   - `perfil da sessao: admin; cadastro identificado: sim`;
   - `GET /api/status.php: 200`, `GET /api/portal/schedules.php: 200`,
     `GET /api/portal/pa_map.php: 200` e `GET /api/portal/absences.php: 200`;
   - `ausencia de teste lancada: 201` e `ausencia de teste cancelada: 200`
     (uma folga de dia inteiro no cadastro do proprio admin, daqui a 30
     dias, sem observacao; o registro fica como cancelado, com autoria, e
     conta em `linhas` da verificacao da 012);
   - `logout: 200`;
   - `linhas novas no log do PHP, fora o login: 0` e `logins bem-sucedidos no
     log: 1` (o `auth_ldap.php` grava uma linha a cada login; M11 da
     auditoria);
   - na ultima linha, `FUMACA OK`.

   **Qualquer `PARE`: nao reabra.** Fique em manutencao e envie so a saida
   do script, que tem so codigos e contagens; o conteudo do log fica na VM,
   para o responsavel ler. Com `PARE: a ausencia de teste ficou lancada`,
   cancele-a pela tela antes de qualquer outra coisa. Login recusado
   (`401`) por senha digitada errada: uma nova tentativa; o limite por
   usuario da 015 conta. Outros codigos (`500`, `503`) ou linha nova no log:
   degrau 2, com aprovacao.

6g. **Reabertura**: sai a manutencao, so com o 6f como esperado (health,
   fuso e `FUMACA OK`).

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: `manutencao encerrada` e `portal visto de fora: 302` (o `/`
   leva a `/app/`). Depois de reabrir, confira numa tela qualquer que uma
   data de atualizacao recente bate com o relogio (BIZ-02).

**7. Verificacao pos-deploy, so com contagens.**

7a. Health, as verificacoes das migrations, o corte do BIZ-02, a Q4 de novo
   e os eventos gravados desde o corte:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <ServerName do vhost>' http://127.0.0.1/api/health.php
cd /var/www/chronodesk; for v in 20261005_011_schedule_history_check 20261007_012_absences_check 20261013_014_employee_archive_check 20261013_019_presence_cutoff_check; do echo "== $v"; read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < "migrations/verificacao/$v.sql"; unset P; done
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_user_throttle') AS migration_015, (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_ad_login') AS migration_016, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_shift_attachments' AND COLUMN_NAME = 'team') AS migration_017, (SELECT COUNT(*) FROM funcionarios_perfil_016b) AS registrados_016b, (SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_corte') AS corte_biz02, (SELECT COUNT(*) FROM login_user_throttle) AS logins_com_falha;"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT exception_type, COUNT(*) AS n, SUM(exception_date >= CURDATE()) AS futuras FROM portal_schedule_exceptions GROUP BY exception_type; SELECT action, COUNT(*) AS n FROM audit_log WHERE id > (SELECT COALESCE(MAX(linha_id), 0) FROM manutencao_biz02_datetime WHERE tabela = 'audit_log') GROUP BY action ORDER BY action;"; unset P
```

   Esperado: health `200`; as quatro verificacoes como nos passos 4e, 6c,
   4g e 4h (019 com **`inconsistente = 0`**), salvo `linhas = 1` na da 012
   (a folga de teste do 6f, cancelada; mais, so se a gestao ja lancou
   ausencia depois da reabertura); `migration_015 = 1`,
   `migration_016 = 1`, `migration_017 = 1`, `registrados_016b` como no 6d,
   `corte_biz02 = 1`; a Q4 com as **mesmas** contagens do 4i; desde o
   corte, so os eventos das acoes feitas hoje (os dois da 016b, os do teste
   de fumaca do 6f, como `CI_LOGIN_SUCCESS`, `ABSENCE_CREATED`,
   `ABSENCE_CANCELLED` e `LOGOUT`, e os dos logins de teste). `LOGIN_THROTTLE_UNAVAILABLE` em qualquer contagem: a
   015 nao esta valendo para a aplicacao; **pare** (todo login daria 503).

7b. **Checagem HTTP** de `scripts/test-linux.sh` (agora na arvore; confere os
   redirects, os bloqueios e as paginas legadas), sem o `MAINTENANCE_TEST`:
   o teste de manutencao derruba o portal por alguns segundos e fica para um
   horario sem uso, depois (fila 2). **So o bloco HTTP**: o script inteiro
   roda `npm run build` de novo e os QAs como root dentro de `/var/www`.
   O bloco vai do `check_status() {` ate a linha `# Modo de manutencao`,
   com o `BASE_URL` do `ServerName` (http), e para na 1a falha (`set -eu`):
   `cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then { echo 'set -eu'; sed -n '/^check_status() {/,/^# Modo de manutencao/p' scripts/test-linux.sh; echo 'echo "checagem HTTP: OK"'; } | env BASE_URL="http://$S" sh; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S`
   (fica fora da lista numerada da folha para nao mudar a numeracao do dia).
   Esperado: 51 linhas de codigo (43 caminhos e os 8 `POST` das paginas
   legadas) e, na ultima, `checagem HTTP: OK` (no deploy 1: **51/51**).
   Qualquer linha `Unexpected status`, `not blocked` ou `must return 410`:
   **pare e avise**.

7c. **`curl` das paginas legadas** (9.12.2; so o codigo HTTP):

```bash
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do echo "$p GET $(curl -k -s -o /dev/null -w '%{http_code}' "http://$S$p") POST $(curl -k -s -o /dev/null -w '%{http_code}' -X POST "http://$S$p")"; done; for j in admin.js admin_users.js; do echo "$j $(curl -k -s -o /dev/null -w '%{http_code}' "http://$S/static/js/$j")"; done; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: `GET 302` e `POST 410` em todas as paginas, menos o
   `/logout.php` (`GET 410` e `POST 410`, HH5); `403` nos dois JS. `GET 200`
   numa pagina legada: o `.htaccess` nao vale no vhost (`AllowOverride`):
   **pare e avise** (o rollback e o `.htaccess` anterior).

7d. **Q-F6 completa de novo** (as duas consultas do 6a): os mesmos numeros
   (se alguem gravou no mapa durante a janela, as diferencas batem com os
   `PA_MAP_*` do 7a).

7e. **Q-F7 completa e rotulo de novo** (as duas consultas do 6a): os mesmos
   numeros.

7f. **D5 do F4: regularizar o inativo com PA aberto**, so com os numeros do
   4f iguais a referencia (`inativos_com_pa = 1`, `inativos_com_regra = 0`,
   `excecoes_de_inativos = 0`; com outros, pare e avise antes de arquivar).
   A consulta devolve so o ID interno; como admin, em Funcionarios > filtro
   "Arquivados", "Arquivar (regularizar)" na linha com esse ID; depois, a
   verificacao da 014 de novo:

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT DISTINCT a.employee_id AS id_cadastro FROM portal_pa_assignments a JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0 WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= CURDATE());"; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < /var/www/chronodesk/migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
```

   Esperado: a tela com "Cadastro inativo regularizado e arquivado. 1
   vinculo de PA encerrado." (ou "removido"); na verificacao,
   `inativos_com_pa = 0`, `arquivados_f4 = 1`, `inativos_sem_data = 4`,
   `ativos_com_data = 0`, `arquivados_sem_autor = 0`.

7g. **Q-F6 completa depois da D5** (a 1a consulta do 6a): esperado
   **`vinculos_de_inativos = 0` e `vinculos_fora_do_mapa = 0`**; diferente,
   **pare e avise**.

7h. **F1: cadastros fora do Mapa.** O responsavel marca pela tela os
   cadastros que ficam fora do Mapa PA e da escala (so admin; nenhum nome no
   roteiro) e roda a Q-F7 completa de novo (`fora_do_mapa` cresce na mesma
   conta; `n1_n2_sem_regra_hoje` pode cair).

7i. **Pelo navegador, por perfil**, sem gravar (salvo onde dito) e sem anotar
   nomes. O detalhe de cada item esta nos roteiros por lote e no
   inventario, secao 7.
   - **Login de cada perfil (Lote 5):** o responsavel (admin pela
     allowlist), um lider fora da allowlist (`lideranca`) e um N2 fora dela
     (`gestor`); depois, no 7a de novo, `ADMIN_ROLE_SEM_ALLOWLIST` so para
     quem era `admin` legado fora da lista. **Revalidacao:** com um usuario
     de teste, desativar o cadastro com a sessao aberta; a proxima
     requisicao responde 401 e o 7a mostra `SESSION_REVOKED`; reativar em
     seguida. **Lote 7:** um login real com sucesso e
     `logins_com_falha` sem linha para ele (7a).
   - **Admin:** Funcionarios abre em "Ativos", sem o checkbox "Funcionario
     ativo"; "Arquivados" com "Inativo sem data", "Arquivar (regularizar)" e
     "Restaurar"; "Arquivar" num ativo mostra os efeitos ("Voltar", sem
     confirmar). Editar e salvar sem mudanca um funcionario **sem login AD**
     (fila 3). Configuracoes de pausa abrem com os valores gravados. Mapa
     PA editavel, com o `Dialog`, a protecao contra perda de alteracoes e o
     "Fim da validade". `technicians.php` (DevTools) com `role`.
   - **Lideranca:** Escala presencial com a Lideranca "Presencial (regra
     fixa)", sem editar; nova regra "Ciclo presencial/remoto" com "Ver
     previa" obrigatoria (sem salvar); "Historico" na linha da regra. Mapa
     PA na data de hoje, cartoes na cor da legenda, PA em conflito com
     contorno vermelho e os dias; anote **so o numero** do cartao
     "Conflitos" (ate o `[N_PAS]` do aviso). Publicar um anexo de escala
     para N1 (Lote 4) e conferir com um tecnico de N2 que ele nao o lista
     nem o baixa (404) e com um gestor que ve os dois.
   - **Gestor:** Visao Operacional com "Situacao da equipe agora" (N1, N2,
     Total e a Lideranca a parte); `escalados = disponiveis + em pausa +
     almoco + ausentes + fora do expediente`; "CIs disponiveis" igual ao
     Disponiveis do Total; anote so os numeros do Total. Tela Tecnicos com
     "Situacao agora" e, no DevTools, sem `role`. Ausencias com filtros,
     "Nova ausencia" e o aviso de nao registrar saude; a excecao de escala
     so com os quatro tipos de trabalho e, ao escolher colaborador e data
     com excecao, o tipo e a observacao gravados. Mapa PA so leitura, sem
     logins, observacoes e autores no `pa_map.php`. Chamados criticos: abrir
     e fechar um registro em edicao sem salvar. Horas extras: totais por
     situacao e o CSV com as colunas novas (Lote 6). Calendario e relatorio
     da competencia atual **iguais aos de antes** (sem corte registrado).
   - **Tecnico** (a propria conta): Calendario sem o texto dos eventos dos
     colegas (hora extra, ajuste, plantao; pausa fora da lista como
     "Pausa"); `oncall.php` sem `note`, `created_by` e `updated_by` dos
     colegas; Visao Operacional sem a observacao de reuniao dos colegas;
     Ausencias so com as proprias, sem formulario; `status.php` e
     `dashboard.php` sem `observacao_reuniao` dos colegas; avisos de todos e
     os da propria equipe (Lote 4).
   - Em 1280 px ou mais, as tabelas sem rolagem dentro do cartao; em 390 px,
     a tabela rola dentro do cartao e a pagina nao.

7j. **Medicao depois do deploy**, nas condicoes do 6a, com o script do
   proprio clone:

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

   **Criterio do F2, para os dois endpoints:** ha piora quando o p95 sobe
   **mais de 50 ms e mais de 20%** sobre a base do 6a, as duas condicoes ao
   mesmo tempo. Com piora: desligamento rapido (passo 8a), medir de novo e
   trazer os numeros. Anote p50 e p95 na secao 9.9 do desenho.

7k. **Pendencia 16c, agora com a 011** (a versao completa, com o filtro
   `fora_mapa_escala`; `C` calculado no dia):

```bash
read -rsp 'Senha root do MySQL: ' P; echo; C=$(docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N sistema_pausas -e "SET time_zone = 'America/Sao_Paulo'; SELECT IF(DAYOFMONTH(CURDATE()) >= 16, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16'));"); unset P; [[ "$C" =~ ^[0-9]{4}-[0-9]{2}-16$ ]] && echo "corte planejado: $C" || echo "PARE: data do corte invalida ($C)"
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET @corte := '$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(f.equipe) IN ('n1', 'n2');"; unset P
```

   Esperado: `corte planejado` igual ao do 0i (diferente: o deploy passou
   do dia 16; a data nova vale, avise a gestao). **As duas colunas tem de
   dar `0`.** Com qualquer uma maior que `0`, o registro do corte **nao
   roda** (decisao do responsavel): a gestao cadastra a regra ou marca
   "fora do Mapa", e a consulta roda de novo, no mesmo dia ou noutro antes
   da data (o deploy fica no ar sem corte, tudo no calculo antigo).

7l. **Registro do corte**, so com as duas colunas do 7k em `0`, a
   verificacao da 019 com `inconsistente = 0` e o `C` do 7k na mesma
   sessao:

```bash
cd /var/www/chronodesk; echo "corte a registrar: $C"; read -rsp 'Senha root do MySQL: ' P; echo; { printf "SET @corte := '%s'; SET @autor := '%s';\n" "$C" '<login do operador>'; cat migrations/20261013_019_registrar_corte.sql; } | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
```

   Esperado: `cortes = 1`, `corte` = o `C`, `corte_dia_16 = 1`,
   `corte_no_futuro = 1`, `sem_autor = 0`, `eventos_registro = 1`,
   `eventos_remocao = 0`, `procedimentos_soltos = 0` e **`inconsistente =
   0`**. Com `registro_019 abortado: ...`, nada foi gravado: confira o `C`,
   `DROP PROCEDURE IF EXISTS registrar_corte_019` e envie a mensagem. Pelo
   navegador: a competencia anterior ao corte igual a de antes; a partir do
   corte, o Calendario com o resumo do dia e o relatorio com o aviso
   "Escala pelas regras novas (corte em DD/MM/AAAA)."

7m. **Limpeza.** O diretorio da extracao do passo 4a, so se o caminho for o
   esperado. Depois, a conferencia de que a copia `sistema_pausas_ensaio`
   nao existe mais: o ultimo comando do 0q a apagou na vespera. O backup
   pre-dia de 09/11 fica (passo 9e).

```bash
for E in /root/ensaio-deploy1.?????? /tmp/ensaio-deploy1-qa.??????; do [ -d "$E" ] && rm -r -- "$E" && echo "removido: $E"; done; echo "ensaios restantes: $(ls -d /root/ensaio-deploy1.?????? /tmp/ensaio-deploy1-qa.?????? 2>/dev/null | wc -l)"; unset E D1
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT COUNT(*) AS copia_restante FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'sistema_pausas_ensaio';"; unset P
```

   A varredura e pelo padrao do `mktemp`, nao pelo `$E`: um `sudo -i` novo
   no meio do dia perde a variavel (achado do dia do deploy 1: o comando
   anterior dependia do `$E`). Ela pega tambem o ensaio de codigo do passo
   5, se ele ficou.
   Esperado: `removido: /root/ensaio-deploy1.<sufixo>`,
   `ensaios restantes: 0` e `copia_restante = 0`. Com
   `1`, a copia ficou da vespera: apague-a com o ultimo comando do 0q (com o
   nome digitado) e registre o atraso. A copia nao pode ficar no MySQL
   depois do dia.

**8. Desligamentos rapidos e rollback em degraus.** Sempre do degrau mais
baixo para o mais alto; os degraus 2 e 3 so com **aprovacao previa do
responsavel**.

8a. **Degrau 1: desligamento rapido (`STATUS_PRESENCE_ENABLED=false`)**, sem
   voltar o codigo, para piora de desempenho ou falha no calculo da
   presenca. A variavel fica no `/var/www/.env` e so a recarga do PHP-FPM
   aplica a mudanca (a variavel no pool do PHP-FPM venceria o `.env`; o
   `grep` confere). A copia do `.env` vem antes, modo `600`, e a linha so
   troca se a copia der certo:

```bash
grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
K=/var/backups/chronodesk/env-antes-interruptor-$(date +%Y%m%d-%H%M%S); if install -m 600 /var/www/.env "$K"; then echo "copia do .env: $K ($(stat -c '%a %U' "$K"))"; if grep -q '^STATUS_PRESENCE_ENABLED=' /var/www/.env; then sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=false/' /var/www/.env; else echo 'STATUS_PRESENCE_ENABLED=false' | tee -a /var/www/.env >/dev/null; fi; else echo "PARE: copia do .env falhou, nada alterado"; fi; unset K
cd /var/www/chronodesk && sh scripts/preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

   Esperado: nada no `grep` do pool, `copia do .env: ... (600 root)` e
   `STATUS_PRESENCE_ENABLED: OK (desligado)`. Efeito: o `status.php` deixa
   de calcular a presenca, a Visao Operacional e a tela Tecnicos mostram
   "—" com aviso (nunca o numero antigo), os cartoes de Pausas voltam ao
   calculo antigo. **A DV9, a DV10, a DV11, a DF7, o SEC-07b e o F3
   continuam valendo** (nao dependem do interruptor). Para religar: o mesmo
   comando com `STATUS_PRESENCE_ENABLED=true`, preflight com `OK (ligado)` e
   a recarga.

   **Observacao 2 da pendencia 16: limpeza das copias do `.env`.** Cada
   desligamento (e o passo 3c) deixa uma copia com os segredos do `.env` em
   `/var/backups/chronodesk`. Depois de conferidas e **com aprovacao do
   responsavel**, liste (so nome, modo e dono, nunca o conteudo) e apague:

```bash
find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -printf '%f %m %u\n'
find /var/backups/chronodesk -maxdepth 1 -type f \( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \) -print -delete
```

   Esperado: a 1a linha lista as copias (modo `600`, dono `root`); a 2a
   lista as mesmas ao apagar; rodar a 1a de novo nao mostra nada.

8b. **Degrau 2: codigo (so com aprovacao previa do responsavel).** Antes,
   so contagens: `linhas` na verificacao da 012, `regras_cycle` na
   verificacao do ciclo do F2B e `arquivados_f4` na da 014. Nesta ordem,
   **com o codigo do deploy 1 ainda no ar**: o `f2b_cycle_down` (so com
   `regras_cycle` maior que `0`) e o `016b_down` (o codigo anterior leria
   `lideranca` como tecnico); depois o codigo, com o `.htaccess` local de
   volta (sem ele, o `596db67` fica sem o `RewriteBase`) e o `app/` do 3a
   de volta, sem `npm` (C2 da auditoria; o build que estava no ar vai para
   `/var/backups/chronodesk/app-deploy1-retirado-<data>`, nada e apagado);
   por fim, o corte do BIZ-02 sai, **so se a correcao historica nao rodou**
   (A3 da auditoria; secao 9 do BIZ-02: rollback de codigo antes da
   correcao historica). Com a correcao ja feita (passo 9c), apagar a
   fotografia a tornaria irreversivel: o comando para e o script 4 do
   BIZ-02 roda antes, com o responsavel.

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" && bash -o pipefail -c 'git show "$D1:migrations/verificacao/20261007_f2b_cycle_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261007_f2b_cycle_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "ciclo trocado por undefined"; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261002_016b_lideranca_profile_data_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "perfis da 016b revertidos"; unset P
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy-commit)" && cp -p /var/backups/chronodesk/htaccess-local-antes-deploy1 .htaccess && echo "codigo anterior no ar: $(git rev-parse --short HEAD); .htaccess local de volta: $(git diff --numstat -- .htaccess | cut -f1,2)"
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy1; X=/var/backups/chronodesk/app-deploy1-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
read -rsp 'Senha root do MySQL: ' P; echo; X=$(docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot -N sistema_pausas -e "SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_correcao';"); if [ "$X" = "0" ]; then docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "DROP TABLE IF EXISTS manutencao_biz02_datetime; DELETE FROM manutencao_marcadores WHERE chave LIKE 'biz02%'; SELECT COUNT(*) AS marcadores_biz02 FROM manutencao_marcadores WHERE chave LIKE 'biz02%';"; else echo "PARE: biz02_correcao = ${X:-erro na consulta}; a correcao historica ja rodou: o script 4 do BIZ-02 vem antes, com o responsavel; nada apagado"; fi; unset X; unset P
```

   Esperado: `ciclo trocado por undefined` (so se havia ciclo), `perfis da
   016b revertidos`, `codigo anterior no ar: 596db67; .htaccess local de
   volta: 6 0`, `app/ anterior de volta (...)` e `marcadores_biz02 = 0`.
   `PARE` na volta do `app/`: fique em manutencao e avise (o codigo
   anterior sem o `app/` dele nao abre as telas). `PARE: biz02_correcao =
   1`: nada foi apagado; o script 4 do BIZ-02 (secao 9 de
   `docs/FUSO_HORARIO_BIZ02.md`) devolve os valores, e so depois o comando
   roda de novo. Se o arquivo de manutencao ainda existir, ele nao faz nada
   com o `.htaccess` anterior; tire-o como no 6g. O build retirado (`X`)
   sai na limpeza do 9e, com aprovacao.

   O codigo anterior funciona sobre a 015 (ignora a tabela), a 016 (erro ao
   gravar o login AD de outro cadastro), a 017 (ignora a coluna), a 011
   (enquanto nao houver `cycle`), a 014 (editar um arquivado do F4 da 500;
   o CHECK recusa) e a 019 (nao le a tabela); com a 012, a lista de
   Ausencias responde 503. O `APP_ENV` nao precisa voltar (a chave foi
   conferida no 0j). O BIZ-02: o codigo anterior volta a gravar em UTC, e as
   linhas gravadas entre o deploy e o rollback ficam no fuso certo no meio
   do historico em UTC; num novo deploy, o corte e registrado de novo e a G2
   tende a disparar por causa delas (a correcao passa a exigir analise).
   **Risco LGPD de voltar o codigo** (`docs/DOCUMENTACAO_TECNICA.md`, 5.3),
   por isso so com aprovacao e, sempre que der, corrigir para frente:
   - **F3:** o Calendario do codigo anterior mostra a observacao de cada
     ausencia a qualquer perfil (com `linhas = 0` nao ha o que expor).
   - **SEC-07b:** o texto livre de horas extras, ajustes e plantoes dos
     colegas volta a ir a todos, e o `oncall.php` volta a mandar `note`,
     `created_by` e `updated_by`.
   - **DF7 (F6):** o `pa_map.php` volta a enviar logins, observacoes e
     autores dos vinculos e a lista de elegiveis a qualquer perfil.
   - **DV9 a DV11 (F7):** o `technicians.php` volta a enviar o perfil de
     acesso, e o `status.php` e o `dashboard.php`, o motivo de pausa e a
     observacao de reuniao de todos, a qualquer perfil.
   - **Lotes 1 a 7:** saem o limite de login por usuario (SEC-03), o perfil
     revalidado por requisicao e o admin so pela allowlist (Lote 5), o
     anexo de escala por equipe (SEC-08), o health restrito por IP e as
     travas fora de producao.
   - **9.12:** as paginas legadas voltam a responder e as correcoes da
     9.12.1 a 9.12.5 saem (campo ausente volta a sobrescrever o gravado).
   O clone fica em *detached HEAD*, com o `.htaccess` local de volta; antes
   de um novo deploy, `git checkout codex/modernizacao-ui-backend`
   (com a mesma mudanca local, tratada como no passo 3a).

8c. **Degrau 3: migrations (so com aprovacao, so com as tabelas vazias ou
   com a copia de seguranca que o proprio rollback faz)**, depois do degrau
   2 e na ordem inversa: 019, 014, 012, 011, 017, 016 e 015. Cada uma lida
   do commit gravado (depois do `checkout`, os arquivos nao estao na
   arvore), com `cat-file` e `pipefail`; sem a mensagem "rollback da ...
   aplicado", o rollback nao rodou. Os rollbacks da 019, 014, 012 e 011
   param antes de alterar se a copia `*_bkp_0NN` ja existir (renomear e
   repetir; roteiros por lote).

```bash
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261013_019_presence_cutoff_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 019 aplicado"; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261013_014_employee_archive_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 014 aplicado"; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261007_012_absences_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261007_012_absences_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 012 aplicado"; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261005_011_schedule_history_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 011 aplicado"; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261002_017_shift_attachment_team_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261002_017_shift_attachment_team_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 017 aplicado"; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261002_016_lideranca_profile_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261002_016_lideranca_profile_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 016 aplicado"; unset P
cd /var/www/chronodesk; export D1=$(cat /var/backups/chronodesk/deploy1-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D1:migrations/rollback/20261001_015_login_user_throttle_down.sql" && bash -o pipefail -c 'git show "$D1:migrations/rollback/20261001_015_login_user_throttle_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 015 aplicado"; unset P
```

   - **019:** guarda a data e o autor do corte em
     `portal_presence_cutoff_bkp_019` (LGPD: apagar a mao depois de
     conferir, com aprovacao); audita `PRESENCE_CUTOFF_REMOVED`.
   - **014:** nenhuma linha volta (arquivado continua `ativo = 0`; PA,
     escala e ausencias encerrados nao voltam); data e autor ficam em
     `funcionarios_bkp_014` (LGPD: apagar a mao, com aprovacao).
   - **012:** so sem ausencia alem da folga de teste do 6f, cancelada
     (`linhas = 1`; `0` se o 6f nao chegou a lancar); com ausencias
     gravadas, o responsavel decide antes (o rollback expoe as observacoes
     tambem pela lista do codigo anterior; ausencia parcial vira dia
     inteiro).
   - **011:** o historico gravado pelo deploy 1 so fica nas copias
     `*_bkp_011`; uma regra por colaborador volta; as marcacoes "fora do
     Mapa" se perdem.
   - **017:** os anexos voltam a ser visiveis para todos (a equipe gravada
     se perde).
   - **016:** para sem alterar nada se ainda houver cadastro `lideranca`
     (promovido depois da 016b); a `funcionarios_perfil_016b` fica (LGPD:
     IDs e perfis; apagar a mao, com aprovacao).
   - **015:** a tabela sai com os contadores de tentativas (logins; sem
     senha).
   - **Backups do dia, so como ultimo recurso** ("Restauracao em
     Incidente", com o `database.sql.gz` do diretorio anotado):
     - o do **6b** (`deploy1-manutencao-<data>`, A2 da auditoria), tirado
       com o portal parado, ja com as migrations 015 a 019 e a 012, antes do
       corte do BIZ-02 e da 016b. E o preferido: nao perde o que o turno
       gravou ate o `merge`;
     - o do **3d** (`deploy1-<data>`), antes de qualquer migration. Perde o
       que foi gravado depois dele, pausas inclusive.

     Os dois perdem o que foi gravado depois da reabertura.

**Se a verificacao falhar (resumo):**
- **Passos 0 a 5:** nada do codigo mudou. Ate o 3d, nada mudou no banco (o
  3c so troca o `APP_ENV`, que vale na hora: com o portal com erro depois
  dele, o `sed` inverso do 3c volta sem recarga); do 4b ao 4h, as migrations ja aplicadas nao
  mudam o que o codigo anterior faz (pare e envie as contagens; desfazer, so
  com o degrau 3 e aprovacao).
- **6b, a 012 parou (`migration_012 abortada`) ou o `cat-file`/`git show`
  falharam:** o `checkout` do `.htaccess` e o `merge` nao rodaram (o
  `pipefail` e o `&&` impedem); o codigo anterior continua no ar, e o
  arquivo de manutencao nao vale com o `.htaccess` dele: tire-o como no 6g.
  Envie a contagem de `portal_absences`.
- **6b, a 012 terminou e o `merge` falhou** (alteracao local fora do
  `.htaccess`, branch divergente): o `.htaccess` ja foi descartado; volte a
  copia (`cp -p /var/backups/chronodesk/htaccess-local-antes-deploy1 /var/www/chronodesk/.htaccess`)
  e a tela de Ausencias do codigo anterior fica em 503 ate o `merge`.
  Resolva a causa **sem `fetch` e sem `pull`**, repita so
  `cd /var/www/chronodesk; git checkout -- .htaccess && git merge --ff-only "$(cat /var/backups/chronodesk/deploy1-commit)"`
  e siga da conferencia do `HEAD`. Se o `HEAD` foi para outro commit:
  degrau 2 e avise o responsavel.
- **6c, `ABORTADO PELAS GUARDAS`:** em manutencao, sem 016b nem recarga;
  o responsavel decide (analise com `@ignorar_guardas = 1` ou degrau 2).
- **6b, backup em manutencao com `PARE`:** em manutencao, sem corte do
  BIZ-02 nem 016b; o responsavel decide.
- **6e, `npm ci` sem rede ou sem espaco:** em manutencao, sem recarga;
  resolvido, repita uma vez; senao, degrau 2 (o `app/` volta da copia do
  3a, sem `npm`).
- **6f, teste de fumaca sem `FUMACA OK`:** nao reabra; em manutencao,
  degrau 2 com aprovacao (senha digitada errada: uma nova tentativa).
- **Erro 500 ou 503 nas telas, health diferente de `200`, falha no build:**
  degrau 2 (com aprovacao).
- **"—" na Visao Operacional ou piora na medicao:** degrau 1; nao e
  rollback.
- **Mais PAs em conflito que o aviso, numeros do Total diferentes da
  Q-F7:** nao reverta; anote e avise o responsavel.
- **`orfas`, `periodo_invalido`, `sobreposicoes_aprovadas` (012),
  `ativos_com_data`, `arquivados_sem_autor` (014) maiores que `0`, ou
  `inconsistente = 1` (019):** nao apague nem altere linha; mantenha o
  codigo no ar e trate como incidente.

**9. Depois do deploy, com o responsavel, depois de um ciclo estavel** (uma
competencia inteira sem rollback, sugestao): o branch `codex` no GitHub
(NN10), o passo 5 da 9.12 (apagar as paginas legadas), a rotina de backup e
a correcao historica do BIZ-02, a pendencia LGPD das copias de junho e a
limpeza do que o deploy deixou.

9a. **Branch `codex/modernizacao-ui-backend` no GitHub (NN10, opcao 1; o
   push e do responsavel, na estacao, PowerShell 5.1).** Push **sem
   `--force`** do commit gravado: so funciona se for fast-forward do que
   esta no `origin`.

```powershell
git push origin <commit validado na estacao>:codex/modernizacao-ui-backend
```

   Esperado: a linha `<antigo>..<novo>` com
   `-> codex/modernizacao-ui-backend`. Recusa (`rejected`,
   `non-fast-forward`): **pare**; o `origin` tem commit que o deploy nao
   tem. Nunca `--force`; o responsavel decide (como as opcoes do passo 2).

9b. **Passo 5 da 9.12 (apagar as paginas legadas).** Primeiro, a contagem
   dos logs do passo 0l de novo:
esperado so `302`/`410`/`403` desde o deploy. A remocao e feita pelo
responsavel **no repositorio, num commit proprio** (nunca apagando no
servidor, para a arvore continuar igual ao git), e vai ao ar num deploy so
de codigo: `admin.php`, `admin_login.php`, `admin_logout.php`, `login.php`,
`metricas.php`, `index.php`, `logout.php` (da raiz), `static/js/admin.js`,
`static/js/admin_users.js`, `static/css/admin.css` e o resto de `static/`
que so as paginas legadas usam (como `static/js/metricas.js`; a levantar
nesse lote). As regras do `.htaccess` continuam (302 e 410 nao dependem do
arquivo existir). Antes do commit, o `test-local.ps1` e o `qa-security`
(lista de quem chama o AD) sao ajustados de proposito.

9c. **Rotina de backup e correcao historica do BIZ-02.** Instalar a rotina
   de backup pela secao "Backup" (itens 1 a 6; o usuario de backup no MySQL
   e um usuario novo, com senha do cofre: decisao do responsavel), fazer o
   "Teste de Restauracao" e so entao a correcao historica (secoes 7.2 a 7.5
   de `docs/FUSO_HORARIO_BIZ02.md`: backup imediatamente antes, script 2,
   script 3, script 2, com os esperados do ensaio do 0n), fora do horario de
   uso. Depois dela, a revisao manual (V8, V9 e V10) pela tela e, com
   aprovacao, a remocao das tabelas `manutencao_*` (a fotografia copia o
   login de quem leu cada notificacao).

9d. **Pendencia LGPD: as quatro copias de junho no banco de producao**
   (`funcionarios_backup_20260616_082511`,
   `funcionarios_cleanup_backup_20260616_092509`,
   `funcionarios_pre_horario_20260616_111259` e
   `pausas_cleanup_backup_20260616_092509`; dados pessoais, sem retencao
   definida, sem uso pelo codigo). O deploy 1 nao toca nelas. Recomendacao
   e criterio no inventario, secao 8.

9e. **Limpeza do que o deploy deixou, com aprovacao:** o diretorio do backup
   do dia e o do 6b (`/var/backups/chronodesk/deploy1-<data>` e
   `deploy1-manutencao-<data>`, com dados pessoais), a copia do `app/`
   (`app-antes-deploy1`) e, se o degrau 2 rodou, o build retirado
   (`app-deploy1-retirado-<data>`), o
   backup pre-dia (`/root/backups_chronodesk/pre_deploy1_20261109_1549.sql.gz`,
   com dados pessoais, na `/`; o deploy nao depende dele), a
   copia do `.htaccess` local (`htaccess-local-antes-deploy1`), as copias
   do `.env` (8a) e a `funcionarios_perfil_016b` (base do `016b_down`; sem
   ela, a reversao da 016b deixa de existir). Listar antes, so nome, modo,
   dono e tamanho.

### Deploy 2 da Parte B (pos-deploy 1: A, A2b, B, E, D e F; so codigo, sem migration)

**O que vai** (desenho 9.14; tudo depois do `2e27926`, que esta no ar desde
10/11/2026): A (`qa-security` com corpo binario, `fputcsv` explicito,
`.gitleaksignore` e `npm audit`), A2b (`fgetcsv` explicito), B (filtro e
coluna de almoco em Administracao > Funcionarios), E (frontend na bateria
completa do `test-local.ps1`; so a estacao), D (ID do funcionario gerado
pelo banco) e F (faixa do ID ate 2147483647), mais os commits de docs.
**Nenhuma migration** (o passo 1 confere) e **lockfile mudou** (`vite`,
`zod` e as transitivas do A4): `npm ci` e obrigatorio no passo 6. O F8 e o
F9 nao vao neste deploy: a decisao de 2026-11-06 chamava de "deploy 2" o
do F8 e do F9; desde 2026-11-12 a numeracao segue a ordem de execucao, e o
deles passa a ser o **deploy 3**, com roteiro proprio (desenho 9.14.7).

A folha do dia e `docs/DEPLOY2_FOLHA_DO_DIA.md`: os mesmos comandos, na
mesma ordem (a trava `scripts/qa-deploy-runbook.php` confere). Mudou um,
muda o outro no mesmo commit.

**Shell root, sem `sudo`**, como no deploy 1: o operador abre o terminal da
VM com `sudo -i` e roda todos os comandos nele, sem o prefixo `sudo`; o que
roda como `www-data` usa `runuser -u www-data --`. O 1o comando e a
conferencia do ambiente (a mesma do deploy 1); **qualquer `BLOQUEADO` e
PARE**. Terminal reaberto: `sudo -i` e a conferencia de novo; os comandos
deste roteiro leem o commit e o `ServerName` de arquivos, nao de variaveis
de um terminal anterior.

**Sem valor real**: a senha do root do MySQL e lida sem eco
(`read -rsp`) e apagada no fim do comando; o `ServerName` e lido do
`/etc/apache2/sites-enabled/chronodesk.conf` na hora (nunca escrito aqui);
as consultas devolvem so contagens e estrutura. O `https` da VM responde
`200` de **outro site** (o `*:443` nao e o portal): todo `curl` deste
roteiro usa `http://` com o `ServerName`, ou o loopback com o `Host`.
Onde aparece `<commit validado na estacao>`, o hash completo anotado no
item 0. Cada comando em **uma linha**.

**Regras de parada:** qualquer linha com `PARE`, ou saida diferente do
"Esperado": pare e envie so a contagem ou a mensagem. Nada de `git pull`;
o unico `git fetch` e o do passo 1, so do branch.

0. **Antes do dia, na estacao.** O responsavel publica o
   `feat/employee-form-rhf-zod`. Depois, a bateria completa sobre o commit
   do branch, sozinha (nada pesado em paralelo; desenho 9.14.4):
   `git fetch origin; git worktree add --detach ..\chronodesk-deploy2 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy2\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`.
   Esperado: `Validacao local concluida com sucesso`, a linha
   `frontend: 22 scripts npm rodados (...): 22 OK, 0 com falha`, o roteiro
   de navegador com `falharam: 0` e o hash completo no fim: anote como
   `<commit validado na estacao>`. Qualquer falha: nao ha deploy com esse
   commit.

**1. Conferencia do ambiente, commit validado e producao no `2e27926`.**

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "2e27926ebdd14c40e6bb173938e767a774fe640a" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy2-commit >/dev/null; echo "HEAD de producao: 2e27926 (OK, gravado em pre-deploy2-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o 2e27926"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy2-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
cd /var/www/chronodesk; D2=$(cat /var/backups/chronodesk/deploy2-commit); git cat-file -e "$D2^{commit}" && git cat-file -e "$D2:scripts/smoke-deploy1.sh" && git merge-base --is-ancestor HEAD "$D2" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D2") commits a aplicar" || echo "PARE: deploy2-commit ausente, sem o smoke-deploy1.sh ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D2" -- migrations | wc -l)"; echo "lockfile alterado: $(git diff --name-only HEAD "$D2" -- frontend/package-lock.json | wc -l)"; unset D2
```

   Esperado: `ambiente: OK`; `HEAD de producao: 2e27926 (OK, ...)`,
   `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`; o hash
   do commit validado gravado em `deploy2-commit`; `fast-forward OK` com o
   numero de commits da entrega (anote), `migrations alteradas: 0` e
   `lockfile alterado: 1`. **PARE** com qualquer `PARE`, alteracao local,
   migration alterada (este roteiro nao aplica migration) ou outro HEAD
   (algo mudou em producao desde o deploy 1: traga o hash curto).

**2. Ensaio do codigo do commit gravado**, como no passo 5 do deploy 1: o
commit inteiro extraido em `/tmp`, dono `www-data`, e todos os QAs PHP do
repositorio como `www-data`. O `qa-security` roda **sem contorno**: o falso
negativo do dia do deploy 1 (corpo ZIP binario no `QA_RESULT`) foi
corrigido no A (`28fb746`). Nenhum QA do ensaio toca o banco real (o
`qa-timezone` roda sem `--db`); o unico QA contra o banco real e o
`qa-timezone --db` do passo 7.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
cd /var/www/chronodesk; export D2=$(cat /var/backups/chronodesk/deploy2-commit); Q=$(mktemp -d /tmp/ensaio-deploy2-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D2" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy2-commit"
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
for Q in /tmp/ensaio-deploy2-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy2-qa.?????? 2>/dev/null | wc -l)"; unset Q D2
```

   Esperado: `/tmp/.env ausente: OK`; `extraido em
   /tmp/ensaio-deploy2-qa.<sufixo>`; nenhuma linha antes de
   `fim do php -l`; os 16 QAs com `OK`; `removido: ...` e
   `ensaios restantes: 0`. **PARE** com qualquer `FALHOU`: antes da
   limpeza, rode o QA de novo sem o `>/dev/null` e envie so o nome e a
   ultima linha (o deploy nao segue com esse commit).

**3. Espaco livre e backup rapido**, com o portal no ar (o mesmo limite do
0m do deploy 1, por causa do `npm ci`; o mesmo dump do 3d).

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
B=/var/backups/chronodesk/deploy2-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

   Esperado: seis linhas `OK` e `espaco livre: OK`; `backup concluido:
   /var/backups/chronodesk/deploy2-<data> (<tamanho>)` (anote; o do deploy
   1 tinha 32K). **PARE** com `BAIXO` (liberar espaco e da infraestrutura;
   nada se apaga de improviso) ou backup que falhou.

**4. Manutencao e copia do `app/`.** O deploy leva poucos minutos, mas o
build do passo 6 troca o `app/` enquanto os usuarios usam: com o
arquivo-sinal, o portal responde 503 ("ChronoDesk temporariamente
indisponivel.") ate o passo 9. A copia do `app/` e o ponto de volta do
passo 11, sem `npm`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy2; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: `arquivo de manutencao: presente`; `copia do app/: ...`
   (anote o tamanho e os arquivos); `portal visto de fora (manutencao):
   503`. Sem `503` (o `ServerName` pode resolver para o loopback na VM, que
   passa pela manutencao de proposito): confira de uma estacao, no
   navegador, que aparece "ChronoDesk temporariamente indisponivel.".
   **PARE** com `AUSENTE`, copia que falhou, ou a estacao vendo a tela do
   portal: tire o arquivo-sinal (comando do passo 9) e avise.

**5. `git merge --ff-only` do commit gravado** (sem `fetch`, sem `pull`) e
o `HEAD` conferido:

```bash
cd /var/www/chronodesk; D2=$(cat /var/backups/chronodesk/deploy2-commit); git merge --ff-only "$D2"; [ "$(git rev-parse HEAD)" = "$D2" ] && echo "HEAD = deploy2-commit" || echo "PARE: HEAD diferente do deploy2-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D2
```

   Esperado: o `merge` em fast-forward, `HEAD = deploy2-commit` e
   `alteracoes locais: 0`. **PARE**: fique em manutencao, nada de `fetch`
   nem `pull`; o codigo anterior volta pelo passo 11 so se o `HEAD` mudou.

**6. Build do frontend.** O lockfile mudou: `npm ci` (nunca `npm install`).

```bash
cd /var/www/chronodesk/frontend && npm ci && npm run build
```

   Esperado: build sem erro (o do deploy 1 levou 880 ms). **PARE** com as
   mesmas regras do passo 6e do deploy 1: falha por falta de espaco
   (`ENOSPC`) ou de rede (`ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED`,
   `EAI_AGAIN`, `ENOTFOUND`, `E403`, `E404`, `E5xx`, `EINTEGRITY`): fique em
   manutencao, sem recarga, e avise a infraestrutura; resolvido, repita uma
   vez; senao, passo 11 (o `app/` volta da copia do passo 4, sem `npm`).
   Outra falha no build: passo 11, com aprovacao.

**7. Recarga, health, fuso e teste de fumaca**, ainda em manutencao (o
loopback passa pela manutencao de proposito):

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: recarga sem saida; `health: 200`; `sessao MySQL em
   America/Sao_Paulo` e `QA timezone (banco real) OK`; o teste de fumaca
   pede login e senha do AD do responsavel (admin; senha sem eco) e
   imprime `login: 200`, perfil `admin`, os quatro `GET` com `200`,
   `ausencia de teste lancada: 201`, `ausencia de teste cancelada: 200`,
   `logout: 200`, `linhas novas no log do PHP, fora o login: 0`,
   `logins bem-sucedidos no log: 1` e, na ultima linha, `FUMACA OK`. O log
   do PHP e o `/var/log/chronodesk/php_error.log` configurado no dia do
   deploy 1 (secao "Configuracao do servidor feita no deploy 1").
   **PARE** com qualquer `PARE` ou codigo diferente: nao reabra; fique em
   manutencao e envie so a saida (codigos e contagens). Ausencia de teste
   que ficou lancada: cancele pela tela. Senha errada (`401`): uma nova
   tentativa. Outro codigo ou linha nova no log: passo 11, com aprovacao.

**8. `AUTO_INCREMENT` de `funcionarios`, so leitura** (D). Nenhum cadastro
de teste: a criacao pela tela fica para o primeiro cadastro real.

```bash
read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas -e "SET SESSION information_schema_stats_expiry = 0; SELECT (SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'id') AS id_extra, (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'id') AS id_tipo, (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios') AS proximo_id, (SELECT MAX(id) FROM funcionarios) AS maior_id, (SELECT COUNT(*) FROM funcionarios) AS cadastros;"; unset P
```

   O `SET SESSION information_schema_stats_expiry = 0` faz o
   `information_schema` ler o `AUTO_INCREMENT` atual (no MySQL 8 ele vem de
   um cache de ate 24 h). Esperado: `id_extra = auto_increment`,
   `id_tipo = int`, `proximo_id` maior que `maior_id` (em 10/11/2026: 334,
   333 e 30 cadastros; diferente so se alguem cadastrou pela tela antiga,
   com o ID digitado; anote). **PARE** com `id_extra` sem
   `auto_increment` ou `proximo_id` menor ou igual a `maior_id` (o INSERT
   do D falharia na chave). **No primeiro cadastro real** (gestao, pela
   tela): a mensagem traz o ID gerado ("Funcionario '...' adicionado com o
   ID N."), e este comando de novo mostra `maior_id = N`, `proximo_id`
   igual ao anotado mais 1 e `cadastros` mais 1. Um 409 ou 500 na criacao
   tambem consome um ID (o InnoDB nao devolve o numero no rollback).

**9. Reabertura e conferencia na tela.**

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   So com o passo 7 como esperado (`FUMACA OK`). Esperado:
   `manutencao encerrada` e `portal visto de fora: 302`. Na tela, como
   admin, em Administracao > Funcionarios: o filtro "Almoco" com as
   contagens e a coluna "Almoco" entre Jornada e Status (em 390 px, os
   cartoes com Jornada e Almoco); "Adicionar funcionario" **sem** o campo
   ID; na edicao, o ID so como texto. **PARE** com `PARE` ou outro codigo:
   avise.

**10. Medicao do polling** contra a base do deploy 1 (passo 7j de
10/11/2026: p95 de **11,0 ms** no `status.php` e de **9,9 ms** no
`dashboard.php`), nas mesmas condicoes (2 ou 3 rodadas, a mesma conta, sem
`CONSULTAS`, `N=200`, aba fechada):

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
```

   Esperado: p50 e p95 dos dois (anote no desenho, 9.14). **PARE se** o p95
   passar de 50 ms **e** de 20% acima da base: o deploy 2 nao muda o motor
   nem o polling; meca de novo e avise antes de qualquer reversao.

**11. Rollback de codigo (so se preciso, com aprovacao do responsavel).**
Quando usar: build que nao se resolve (passo 6), `health` diferente de
`200`, `qa-timezone --db` com falha ou teste de fumaca com `PARE` ou linha
nova no log (passo 7). Nao ha migration a desfazer. O `app/` volta da copia
do passo 4, sem `npm` (o `node_modules` novo fica: o `app/` e estatico).
Os cadastros criados pelo codigo novo continuam validos para o codigo
anterior enquanto o ID ficar ate 999 (o proximo era 334 em 10/11/2026).

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy2-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy2; X=/var/backups/chronodesk/app-deploy2-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

   Esperado: `codigo anterior no ar: 2e27926`; `app/ anterior de volta
   (...)` (nada e apagado); recarga sem saida. Depois, o `health` e o
   teste de fumaca do passo 7 e a reabertura do passo 9. **PARE** com
   `PARE` no `app/`: fique em manutencao e avise.

**Depois de um ciclo estavel, com o responsavel:** o push do commit
validado para o `codex/modernizacao-ui-backend` (como o 9a do deploy 1,
nunca `--force`) e, com aprovacao, a limpeza do que o deploy deixou em
`/var/backups/chronodesk` (`deploy2-<data>`, com dados pessoais,
`app-antes-deploy2`, `pre-deploy2-commit` e `deploy2-commit`; listar antes
so nome, modo, dono e tamanho).

#### Registro da execucao do deploy 2 (12/11/2026, no `cf73984`)

Executado pela `docs/DEPLOY2_FOLHA_DO_DIA.md`, com a manutencao das 14:10
as 14:13, aproximadamente. So numeros, pela numeracao da folha (detalhe no
desenho, 9.14.8):

- **0 (estacao):** o hash do `origin` conferido igual ao `cf73984`, onde a
  bateria completa ja tinha rodado sozinha (saida 0, frontend 22/22,
  navegador 1029/1029).
- **1 a 4:** ambiente OK; producao no `2e27926`
  (`codex/modernizacao-ui-backend`); `fetch` com o hash certo;
  fast-forward com 14 commits, migrations alteradas 0, lockfile alterado 1.
- **5 a 9:** `php -l` limpo; os 16 QAs OK, o `qa-security` sem contorno (1a
  vez com `ZipArchive` no PHP 8.4 do servidor); ensaios restantes 0.
- **10 e 11:** espaco livre OK, com a menor folga em `/var` (763 MB) e em
  `/` (589 MB); backup `deploy2-20261112-141024` (52K).
- **12 a 22:** 503 de fora; copia do `app/` com 868K e 28 arquivos; `merge`
  em fast-forward `2e27926..cf73984` (32 arquivos); `npm ci` com 0
  vulnerabilidades; build em 1,22 s; recarga; health 200; `qa-timezone
  --db` OK; `FUMACA OK` com 0 linhas novas no log; `AUTO_INCREMENT` com
  `id_extra = auto_increment`, `id_tipo = int`, `proximo_id` 334,
  `maior_id` 333, `cadastros` 30; reabertura com 302.
- **23 e 24:** `status.php` na 1a rodada, logo depois da reabertura, com
  p50 10,8, p95 46,0 e max 129,3 ms (todas as telas voltando juntas, cache
  do PHP frio); na 2a, 1 minuto depois, p50 6,4, p95 9,6 e max 16,5 ms;
  `dashboard.php` com p50 8,1 e p95 14,6 ms. Dentro do criterio.
- **25 a 27:** rollback nao usado.

**Para as folhas dos proximos deploys (aprovado em 2026-11-12):** a medicao
do polling comeca so alguns minutos depois da reabertura, com 2 rodadas por
endpoint, valendo a melhor. Os comandos deste roteiro ficam como foram
executados. Tambem aprovada: a limpeza dos backups e das copias do `app/`
dos deploys 1 e 2 depois de um ciclo estavel, so com aprovacao, listando
antes so nome, modo, dono e tamanho (inclui a copia `env-antes-php-log-*`,
pelo nome).

### Deploy 3 (F10-1 sem efeito em producao, redesenho R1 a R4d e limpeza do sdk-icon.png; so codigo, sem migration)

**O que vai** (tudo depois do `cf73984`, que esta no ar desde 12/11/2026):

- **F10-1** (`968e9b1`, `665b219`): o motor puro de distribuicao de PA e o
  script de paridade da escala mensal. **Sem efeito em producao**: nenhuma
  tela, endpoint ou cron chama o motor; o script so roda a mao, com
  arquivos do responsavel. Entram no ensaio os dois QAs dele
  (`qa-pa-distribution` e `qa-paridade-escala`).
- **Redesenho R1 a R4d** (`docs/design/PLANO_LOTES_R.md`): tokens, dois
  temas e fontes auto-hospedadas (R1), primitivos (R2), casca (R3), Pausas
  (R4a), Visao operacional e modo painel (R4b), Aprovacoes (R4c) e
  Funcionarios (R4d). So apresentacao: nenhuma regra, API, permissao ou
  texto de seguranca mudou. **O visual muda para todos**: vai junto o aviso
  a gestao (passo 0b).
- **Limpeza do `frontend/public/sdk-icon.png`**: o app deixou de usa-lo no
  R3 (marca em texto). O `favicon.png` e o `apple-touch-icon.png` ficam.
- Os commits de docs de cada lote.

**Nenhuma migration** (o passo 1 confere) e **o lockfile nao mudou** desde o
`cf73984` (o `package.json` ganhou so scripts `qa:*`): o build do passo 6 roda
sem `npm ci`. Se o passo 1 mostrar `lockfile alterado: 1`, o mesmo comando do
passo 6 faz o `npm ci` antes do build. O F8 e o F9 continuam fora: esperam a
Lideranca e passam a ser o **deploy 4**, com roteiro proprio (a numeracao
segue a ordem de execucao, como decidido em 2026-11-12).

**Chamada nova no polling:** o contador do menu (R3) busca o
`api/solicitacoes_pendentes.php` a cada 60 s, com a aba visivel, e a cada
troca de tela, so nas sessoes de gestao (`operacao.approve`). O passo 10 mede
o custo dele junto com o `status.php` e o `dashboard.php`.

A folha do dia e `docs/DEPLOY3_FOLHA_DO_DIA.md`: os mesmos comandos, na
mesma ordem. Mudou um, muda o outro no mesmo commit.

**Data, janela e quem acompanha: A DEFINIR** com o responsavel. A janela
fica com a manutencao de poucos minutos do deploy 2 (cerca de 3 min no dia).

**Shell root, sem `sudo`**, como nos deploys 1 e 2: o operador abre o
terminal da VM com `sudo -i` e roda todos os comandos nele, sem o prefixo
`sudo`; o que roda como `www-data` usa `runuser -u www-data --`. O 1o
comando e a conferencia do ambiente (a mesma dos deploys 1 e 2); **qualquer
`BLOQUEADO` e PARE**. Terminal reaberto: `sudo -i` e a conferencia de novo;
os comandos leem o commit e o `ServerName` de arquivos, nao de variaveis de
um terminal anterior.

**Sem valor real**: a senha do root do MySQL e lida sem eco (`read -rsp`) e
apagada no fim do comando; o `ServerName` e lido do
`/etc/apache2/sites-enabled/chronodesk.conf` na hora (nunca escrito aqui);
as consultas devolvem so contagens. Todo `curl` usa `http://` com o
`ServerName`, ou o loopback com o `Host`. Onde aparece `<commit validado na
estacao>`, o hash completo anotado no item 0. Cada comando em **uma linha**.

**Regras de parada:** qualquer linha com `PARE`, ou saida diferente do
"Esperado": pare e envie so a contagem ou a mensagem. Nada de `git pull`; o
unico `git fetch` e o do passo 1, so do branch.

0. **Antes do dia, na estacao.** O responsavel publica o
   `feat/employee-form-rhf-zod`. Depois, a bateria completa sobre o commit
   do branch, sozinha (nada pesado em paralelo, nem outra bateria nem
   capturas):
   `git fetch origin; git worktree add --detach ..\chronodesk-deploy3 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy3\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`.
   Esperado: `Validacao local concluida com sucesso`, a linha
   `frontend: 26 scripts npm rodados (...): 26 OK, 0 com falha` (lint,
   typecheck, build e 23 `qa:*`), o roteiro de navegador com `falharam: 0`
   e o hash completo no fim: anote como `<commit validado na estacao>`.
   Qualquer falha: nao ha deploy com esse commit.

0b. **Aviso a gestao, na vespera** (pelo canal de sempre; sem nome, sem
   hostname, sem endereco): "Em <data>, entre <inicio> e <fim>, o portal
   fica indisponivel por alguns minutos para uma atualizacao. Depois dela, o
   visual muda para todos: tema claro por padrao (o escuro continua no
   botao do topo), Pausas com os motivos a vista, a Visao operacional com
   os disponiveis no topo e um modo painel para a tela da sala
   (/app/dashboard?painel=1), Aprovacoes com um filtro por tipo e
   Funcionarios com adicionar e editar num painel lateral. Nenhuma regra,
   permissao ou dado muda. Duvidas: <contato>."

**1. Conferencia do ambiente, commit validado e producao no `cf73984`.**

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "cf73984b26197bafe492a3671c1a80468848b83e" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy3-commit >/dev/null; echo "HEAD de producao: cf73984 (OK, gravado em pre-deploy3-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o cf73984"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy3-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
cd /var/www/chronodesk; D3=$(cat /var/backups/chronodesk/deploy3-commit); git cat-file -e "$D3^{commit}" && git cat-file -e "$D3:scripts/smoke-deploy1.sh" && git merge-base --is-ancestor HEAD "$D3" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D3") commits a aplicar" || echo "PARE: deploy3-commit ausente, sem o smoke-deploy1.sh ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D3" -- migrations | wc -l)"; echo "lockfile alterado: $(git diff --name-only HEAD "$D3" -- frontend/package-lock.json | wc -l)"; echo "sdk-icon.png no commit: $(git cat-file -e "$D3:frontend/public/sdk-icon.png" 2>/dev/null && echo presente || echo removido)"; unset D3
```

   Esperado: `ambiente: OK`; `HEAD de producao: cf73984 (OK, ...)`,
   `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`; o hash
   do commit validado gravado em `deploy3-commit`; `fast-forward OK` com o
   numero de commits da entrega (anote), `migrations alteradas: 0`,
   `lockfile alterado: 0` e `sdk-icon.png no commit: removido`. **PARE** com
   qualquer `PARE`, alteracao local, migration alterada (este roteiro nao
   aplica migration) ou outro HEAD (algo mudou em producao desde o deploy
   2: traga o hash curto). `lockfile alterado: 1` nao e PARE: o passo 6
   faz o `npm ci` (anote).

**2. Ensaio do codigo do commit gravado**, como no deploy 2: o commit
inteiro extraido em `/tmp`, dono `www-data`, e todos os QAs PHP do
repositorio como `www-data`, agora com os dois do F10-1. Nenhum QA do
ensaio toca o banco real (o `qa-timezone` roda sem `--db`); o unico QA
contra o banco real e o `qa-timezone --db` do passo 7.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
cd /var/www/chronodesk; export D3=$(cat /var/backups/chronodesk/deploy3-commit); Q=$(mktemp -d /tmp/ensaio-deploy3-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D3" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy3-commit"
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
for Q in /tmp/ensaio-deploy3-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy3-qa.?????? 2>/dev/null | wc -l)"; unset Q D3
```

   Esperado: `/tmp/.env ausente: OK`; `extraido em
   /tmp/ensaio-deploy3-qa.<sufixo>`; nenhuma linha antes de
   `fim do php -l`; os 18 QAs com `OK`; `removido: ...` e
   `ensaios restantes: 0`. **PARE** com qualquer `FALHOU`: antes da limpeza,
   rode o QA de novo sem o `>/dev/null` e envie so o nome e a ultima linha
   (o deploy nao segue com esse commit).

**3. Espaco livre e backup rapido**, com o portal no ar (os mesmos limites
e o mesmo dump do deploy 2).

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
B=/var/backups/chronodesk/deploy3-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

   Esperado: seis linhas `OK` e `espaco livre: OK`; `backup concluido:
   /var/backups/chronodesk/deploy3-<data> (<tamanho>)` (anote; o do deploy
   2 tinha 52K). **PARE** com `BAIXO` (liberar espaco e da infraestrutura;
   nada se apaga de improviso) ou backup que falhou.

**4. Manutencao e copia do `app/`.** O build do passo 6 troca o `app/`
enquanto os usuarios usam: com o arquivo-sinal, o portal responde 503 ate o
passo 9. A copia do `app/` e o ponto de volta do passo 11, sem `npm`.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy3; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: `arquivo de manutencao: presente`; `copia do app/: ...` (anote
   o tamanho e os arquivos; no deploy 2, 868K e 28 arquivos);
   `portal visto de fora (manutencao): 503`. Sem `503`: confira de uma
   estacao, no navegador, que aparece "ChronoDesk temporariamente
   indisponivel.". **PARE** com `AUSENTE`, copia que falhou, ou a estacao
   vendo a tela do portal: tire o arquivo-sinal (comando do passo 9) e
   avise.

**5. `git merge --ff-only` do commit gravado** (sem `fetch`, sem `pull`) e o
`HEAD` conferido:

```bash
cd /var/www/chronodesk; D3=$(cat /var/backups/chronodesk/deploy3-commit); git merge --ff-only "$D3"; [ "$(git rev-parse HEAD)" = "$D3" ] && echo "HEAD = deploy3-commit" || echo "PARE: HEAD diferente do deploy3-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D3
```

   Esperado: o `merge` em fast-forward, `HEAD = deploy3-commit` e
   `alteracoes locais: 0`. **PARE**: fique em manutencao, nada de `fetch`
   nem `pull`; o codigo anterior volta pelo passo 11 so se o `HEAD` mudou.

**6. Build do frontend e o QA do bundle.** Sem lockfile alterado, so o build;
com ele, o `npm ci` antes (nunca `npm install`). Depois, o `qa:bundle`
confere o build que vai ao ar: orcamento (JS de entrada, CSS de 70 KB,
fontes) e as rotas. E o QA do frontend que roda no servidor (so le o
`app/`, sem dependencia de desenvolvimento nem navegador); os outros 22
`qa:*` e o roteiro do navegador rodam na estacao, no item 0.

```bash
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy3-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
cd /var/www/chronodesk/frontend && npm run -s qa:bundle; test -e ../app/sdk-icon.png && echo "PARE: sdk-icon.png ainda no build" || echo "sdk-icon.png fora do build"
```

   Esperado: `lockfile sem mudanca: so o build` e o build sem erro (o do
   deploy 2 levou 1,22 s); `Bundle frontend QA OK (...)` com o CSS abaixo de
   70000 bytes; `sdk-icon.png fora do build`. **PARE** com as mesmas regras
   do passo 6 do deploy 2: falha por falta de espaco (`ENOSPC`) ou de rede
   (`ETIMEDOUT`, `ECONNRESET`, `ECONNREFUSED`, `EAI_AGAIN`, `ENOTFOUND`,
   `E403`, `E404`, `E5xx`, `EINTEGRITY`): fique em manutencao, sem recarga,
   e avise; resolvido, repita uma vez; senao, passo 11. `qa:bundle` com
   falha ou outra falha no build: passo 11, com aprovacao.

**7. Recarga, health, fuso e teste de fumaca**, ainda em manutencao (o
loopback passa pela manutencao de proposito):

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: o mesmo do passo 7 do deploy 2: recarga sem saida;
   `health: 200`; `QA timezone (banco real) OK`; o teste de fumaca com
   `login: 200`, perfil `admin`, os quatro `GET` com `200`, a ausencia de
   teste lancada (`201`) e cancelada (`200`), `logout: 200`, `linhas novas
   no log do PHP, fora o login: 0` e `FUMACA OK`. **PARE** com qualquer
   `PARE` ou codigo diferente: nao reabra; fique em manutencao e envie so a
   saida. Ausencia de teste que ficou lancada: cancele pela tela. Senha
   errada (`401`): uma nova tentativa. Outro codigo ou linha nova no log:
   passo 11, com aprovacao.

**8. Nada a conferir no banco.** O deploy 3 nao muda tabela, coluna nem
dado (passo 1: `migrations alteradas: 0`). O F10-1 nao grava nada.

**9. Reabertura e conferencia na tela.**

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   So com o passo 7 como esperado (`FUMACA OK`). Esperado:
   `manutencao encerrada` e `portal visto de fora: 302`. **Na tela**, como
   admin, de uma estacao (recarregue com Ctrl+F5 uma vez: o `index.html`
   nao tem cache, os `assets/` mudam de nome):

   - **Pausas:** os tres motivos a vista ("Escolha o motivo" no botao), a
     lista "Equipe agora" com o filtro e as contagens, e "Derrubar pausa"
     so nas linhas em pausa (sem clicar).
   - **Visao operacional:** "Disponiveis agora" e N1/N2 no topo, "Pausas
     em andamento", "Precisa de voce", a tabela "Situacao da equipe agora"
     e os "Totais da operacao" no pe; o contador ao lado de "Aprovacoes e
     admin" no menu, so com o numero.
   - **Modo painel:** o botao "Modo painel" abre `/app/dashboard?painel=1`
     sem o menu e sem acoes, com a fonte maior; "Sair do modo painel"
     volta. A observacao de reuniao nao aparece no painel.
   - **Aprovacoes:** o filtro por tipo com as contagens e um so "Aprovar"
     destacado (sem aprovar nada).
   - **Funcionarios:** a lista na primeira tela; o filtro "Almoco" com as
     contagens e a coluna "Almoco"; escolher um horario mostra o filtro na
     fileira de filtros ativos; "Adicionar funcionario" e "Editar" abrem o
     painel lateral (feche sem salvar); o "Grupo Tools" aparece desabilitado.
   - **Tema escuro:** o botao do topo troca para o escuro e de volta;
     nenhuma tela some ou fica sem contraste.

   **PARE** com `PARE`, outro codigo, ou tela em branco ou quebrada: avise
   (o passo 11 volta o `app/` em segundos).

**10. Medicao do polling**, **alguns minutos depois da reabertura** (a 1a
rodada do deploy 2, logo depois, pegou todas as telas voltando juntas), com
**2 rodadas por endpoint, valendo a melhor**, a mesma conta, sem
`CONSULTAS`, `N=200`, aba fechada. A sessao e de **gestao**: o
`solicitacoes_pendentes.php` responde `403` a tecnico. Base: deploy 2, 2a
rodada (`status.php` p50 6,4 e p95 9,6 ms; `dashboard.php` p50 8,1 e p95
14,6 ms); o `solicitacoes_pendentes.php` nao tem base (anote como base).

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
cd /var/www/chronodesk; URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh
```

   Rode cada comando duas vezes. Esperado: p50 e p95 de cada um (anote a
   melhor rodada). **PARE se** o p95 do `status.php` ou do `dashboard.php`
   passar de 50 ms **e** de 20% acima da base, ou o do
   `solicitacoes_pendentes.php` passar de 50 ms: o deploy 3 nao muda o
   motor; meca de novo e avise antes de qualquer reversao. Codigo diferente
   de `200` no `solicitacoes_pendentes.php`: a sessao nao e de gestao (abra
   uma de gestao e repita).

**11. Rollback de codigo (so se preciso, com aprovacao do responsavel).**
Quando usar: build ou `qa:bundle` que nao se resolve (passo 6), `health`
diferente de `200`, `qa-timezone --db` com falha ou teste de fumaca com
`PARE` ou linha nova no log (passo 7), ou tela quebrada no passo 9. Nao ha
migration a desfazer. O `app/` volta da copia do passo 4, sem `npm`.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy3-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy3; X=/var/backups/chronodesk/app-deploy3-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

   Esperado: `codigo anterior no ar: cf73984`; `app/ anterior de volta
   (...)` (nada e apagado); recarga sem saida. Depois, o `health` e o teste
   de fumaca do passo 7 e a reabertura do passo 9. **PARE** com `PARE` no
   `app/`: fique em manutencao e avise. Com o visual antigo de volta, a
   gestao recebe um aviso curto de que a atualizacao foi desfeita.

**Depois de um ciclo estavel, com o responsavel:** o push do commit
validado para o `codex/modernizacao-ui-backend` (como depois dos deploys 1
e 2, nunca `--force`) e, com aprovacao, a limpeza do que o deploy deixou em
`/var/backups/chronodesk` (`deploy3-<data>`, com dados pessoais,
`app-antes-deploy3`, `pre-deploy3-commit` e `deploy3-commit`; listar antes
so nome, modo, dono e tamanho).

#### Registro da execucao do deploy 3 (16/11/2026, no `cd0653b`)

Executado pela `docs/DEPLOY3_FOLHA_DO_DIA.md`, sem nenhum PARE, com a
manutencao das 10:30 as 10:33. So numeros, pela numeracao da folha:

- **0 (estacao):** o `cd0653b`, onde a bateria completa ja tinha rodado
  sozinha (saida 0, navegador com `falharam: 0`).
- **1:** ambiente OK; fast-forward com 37 commits; migrations alteradas 0;
  lockfile alterado 0; o `sdk-icon.png` fora do commit.
- **2:** `php -l` limpo; os 18 QAs PHP OK.
- **3:** espaco livre OK, com a menor folga em `/tmp` (448 MB), `/` (575 MB)
  e `/var` (3241 MB); backup de 52K.
- **4:** copia do `app/` com 892K e 28 arquivos.
- **5 e 6:** `merge --ff-only`; build em 1,43 s, sem `npm ci` (lockfile sem
  mudanca); `qa:bundle` OK, com o JS de entrada em 199217 bytes, o CSS em
  68187 e as fontes em 85912.
- **7:** health 200; `qa-timezone --db` OK; `FUMACA OK`, sem linha nova no
  log e com 1 login.
- **9:** reabertura com 302.
- **10:** 1 rodada por endpoint, logo depois da reabertura (a folha pedia
  alguns minutos depois e 2 rodadas; dentro do criterio mesmo assim):
  `status.php` p50 9,2 e p95 18,2 ms; `dashboard.php` p50 7,4 e p95 12,2 ms;
  `solicitacoes_pendentes.php` p50 4,9 e p95 7,2 ms (primeira medicao deste
  endpoint: vira a base).
- **11:** rollback nao usado.

**Pendente, so com aprovacao:** a limpeza dos backups e da copia
`app-antes-deploy3` (listar antes so nome, modo, dono e tamanho). O push do
`cd0653b` para o `codex/modernizacao-ui-backend` foi autorizado pelo
responsavel em 16/11/2026.

### Deploy 4 (F10-2: times de rodizio, ciclo semana a semana e grupo da escala; migration 021)

**O que vai** (tudo depois do `cd0653b`, o commit do deploy 3):

- **Migration 021** (`f346051`): tres tabelas novas (times, versoes do padrao
  e membros com vigencia), `portal_schedule_rules.rotation_team_id` (nulo) e
  `funcionarios.grupo_escala` (`comum` para todos). Nenhuma linha de dado
  muda. Idempotente; o rollback para se ja existir copia `*_bkp_021`.
- **Servico, API e telas do F10-2** (`4b714c7`, `a740998`): os times de
  rodizio na tela Escala (Lideranca e admin editam; o gestor so le), o ciclo
  semana a semana na regra individual e no time, o 409 da regra individual
  de quem e membro de time e o "Grupo da escala" na edicao de funcionario.
  **Ninguem e membro de time depois do deploy**: a escala de todos fica
  exatamente como estava (as regras de hoje nao mudam). A conversao das
  regras de rodizio em membros e uma decisao a parte (desenho do F10, 8.6):
  o plano de migracao (so leitura) nao roda neste deploy.
- O roteiro e a trava deste deploy e os commits de docs.

**O lockfile nao mudou** desde o `cd0653b` (o `package.json` ganhou so o
`qa:rotation-teams`): o build roda sem `npm ci`; com `lockfile alterado: 1`
no passo 1, o mesmo comando do passo 7 faz o `npm ci`. **Numeracao:** o F8 e
o F9, que esperam a Lideranca, passam a ser o deploy 5 em diante (a ordem e a
de execucao).

**Pre-requisito: o deploy 3 executado com o `cd0653b`.** Se producao estiver
em outro commit, este roteiro muda antes (o passo 1 da PARE).

A folha do dia e `docs/DEPLOY4_FOLHA_DO_DIA.md`: os mesmos comandos, na
mesma ordem. Mudou um, muda o outro no mesmo commit.

**Data, janela e quem acompanha: A DEFINIR** com o responsavel. A janela e a
do deploy 3 mais a migration (segundos: tabelas novas vazias e duas colunas).

**Shell root, sem `sudo`**, como nos deploys 1 a 3: `sudo -i` e todos os
comandos nele; o que roda como `www-data` usa `runuser -u www-data --`. O 1o
comando e a conferencia do ambiente; **qualquer `BLOQUEADO` e PARE**.

**Sem valor real**: a senha do root do MySQL e lida sem eco e apagada no fim
do comando; o `ServerName` e lido do `chronodesk.conf` na hora; as consultas
devolvem so contagens e assinaturas. Onde aparece `<commit validado na
estacao>`, o hash completo anotado no item 0. Cada comando em **uma linha**.

**Regras de parada:** qualquer linha com `PARE`, ou saida diferente do
"Esperado": pare e envie so a contagem ou a mensagem. Nada de `git pull`; o
unico `git fetch` e o do passo 1, so do branch.

0. **Antes do dia, na estacao.** O responsavel publica o
   `feat/employee-form-rhf-zod`. Depois, a bateria completa sobre o commit do
   branch, sozinha (nada pesado em paralelo):
   `git fetch origin; git worktree add --detach ..\chronodesk-deploy4 origin/feat/employee-form-rhf-zod; Set-Location ..\chronodesk-deploy4\frontend; npm ci; Set-Location ..; $env:CHROME_PATH='C:\Program Files\Google\Chrome\Application\chrome.exe'; powershell -NoProfile -ExecutionPolicy Bypass -File scripts\test-local.ps1 -SkipHttp -Browser; git rev-parse HEAD`.
   Esperado: `Validacao local concluida com sucesso`, a linha
   `frontend: 27 scripts npm rodados (...): 27 OK, 0 com falha` (lint,
   typecheck, build e 24 `qa:*`), o roteiro de navegador com `falharam: 0` e
   o hash completo no fim: anote como `<commit validado na estacao>`.
   Qualquer falha: nao ha deploy com esse commit.

0b. **Aviso a Lideranca e a gestao, na vespera** (pelo canal de sempre; sem
   nome, sem hostname, sem endereco): "Em <data>, entre <inicio> e <fim>, o
   portal fica indisponivel por alguns minutos para uma atualizacao. Depois
   dela, a tela Escala presencial ganha a secao Times de rodizio (Lideranca e
   admin) e a regra de ciclo pode ser montada semana a semana. A escala de
   ninguem muda. Duvidas: <contato>."

**1. Conferencia do ambiente, commit validado e producao no `cd0653b`.**

```bash
R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0" || { echo "BLOQUEADO id -u (o shell nao e root)"; R=PARE; }; bash -c 'exit 0' && echo "OK bash -c" || { echo "BLOQUEADO bash -c"; R=PARE; }; tar -czf - -C /etc hostname 2>/dev/null | tar -tzf - >/dev/null 2>&1 && echo "OK tar com gzip" || { echo "BLOQUEADO tar com gzip"; R=PARE; }; [ "$(find /etc/hostname -maxdepth 0 -exec echo ok \; 2>/dev/null)" = ok ] && echo "OK find -exec" || { echo "BLOQUEADO find -exec"; R=PARE; }; runuser -u www-data -- php -v >/dev/null 2>&1 && echo "OK runuser -u www-data -- php -v" || { echo "BLOQUEADO runuser -u www-data -- php -v"; R=PARE; }; [ "$(runuser -u www-data -- php -r '$p = proc_open([PHP_BINARY, "-v"], [1 => ["pipe", "w"]], $x); if (!is_resource($p)) { exit(1); } stream_get_contents($x[1]); echo proc_close($p) === 0 ? "ok" : "falha";' 2>/dev/null)" = ok ] && echo "OK php filho como www-data (proc_open, como no qa-security)" || { echo "BLOQUEADO php filho como www-data"; R=PARE; }; npm -v >/dev/null 2>&1 && echo "OK npm -v" || { echo "BLOQUEADO npm -v"; R=PARE; }; git -C /var/www/chronodesk rev-parse --git-dir >/dev/null 2>&1 && echo "OK git no clone" || { echo "BLOQUEADO git no clone"; R=PARE; }; [ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R
cd /var/www/chronodesk; install -d -o root -g root -m 700 /var/backups/chronodesk; H=$(git rev-parse HEAD); if [ "$H" = "cd0653b22a528cb3cb700b57c05a729cdf6f6f5e" ]; then echo "$H" | tee /var/backups/chronodesk/pre-deploy4-commit >/dev/null; echo "HEAD de producao: cd0653b (OK, gravado em pre-deploy4-commit)"; else echo "PARE: HEAD de producao $(git rev-parse --short HEAD), nao o cd0653b"; fi; echo "branch: $(git rev-parse --abbrev-ref HEAD)"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset H
cd /var/www/chronodesk; git fetch origin feat/employee-form-rhf-zod && K=$(git rev-parse origin/feat/employee-form-rhf-zod) && if [ "$K" = "<commit validado na estacao>" ]; then echo "$K" | tee /var/backups/chronodesk/deploy4-commit; else echo "PARE: origin/feat/employee-form-rhf-zod ($K) nao e o commit validado na estacao"; fi; unset K
cd /var/www/chronodesk; D4=$(cat /var/backups/chronodesk/deploy4-commit); git cat-file -e "$D4^{commit}" && git cat-file -e "$D4:scripts/smoke-deploy1.sh" && git cat-file -e "$D4:migrations/20261115_021_rotation_teams.sql" && git merge-base --is-ancestor HEAD "$D4" && echo "fast-forward OK: $(git rev-list --count HEAD.."$D4") commits a aplicar" || echo "PARE: deploy4-commit ausente, sem o smoke-deploy1.sh, sem a 021 ou o HEAD nao e ancestral dele"; echo "migrations alteradas: $(git diff --name-only HEAD "$D4" -- migrations | sort | tr '\n' ' ')"; echo "lockfile alterado: $(git diff --name-only HEAD "$D4" -- frontend/package-lock.json | wc -l)"; unset D4
```

   Esperado: `ambiente: OK`; `HEAD de producao: cd0653b (OK, ...)`,
   `branch: codex/modernizacao-ui-backend`, `alteracoes locais: 0`; o hash do
   commit validado gravado em `deploy4-commit`; `fast-forward OK` (anote o
   numero de commits); `migrations alteradas:` com exatamente os quatro
   arquivos da 021 (`migrations/20261115_021_rotation_teams.sql`, o rollback,
   as linhas do ensaio e a verificacao); `lockfile alterado: 0`. **PARE** com
   qualquer `PARE`, alteracao local, outra migration na lista ou outro HEAD
   (o deploy 3 nao terminou com o `cd0653b`: traga o hash curto).

**2. Ensaio do codigo do commit gravado**, como no deploy 3: o commit inteiro
em `/tmp`, dono `www-data`, e **todos** os QAs PHP do repositorio, agora com
o `qa-rotation-teams`. Nenhum QA do ensaio toca o banco real.

```bash
[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE: existe /tmp/.env; o ensaio leria esse arquivo"
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); Q=$(mktemp -d /tmp/ensaio-deploy4-qa.XXXXXX); export Q; bash -o pipefail -c 'git archive "$D4" | tar -x -C "$Q"' && chown -R www-data: "$Q" && echo "extraido em $Q" || echo "PARE: falha ao extrair o deploy4-commit"
find "$Q" -name '*.php' -not -path '*/node_modules/*' -exec runuser -u www-data -- php -l {} \; | grep -v '^No syntax errors' ; echo "fim do php -l"
[ ! -e /tmp/.env ] && for q in qa-smoke qa-auth qa-timezone qa-overtime qa-schedule-history qa-schedule-legacy-output qa-presence-engine qa-absences qa-calendar-text qa-archive qa-presence-cutoff qa-pa-conflicts qa-operational-view qa-employee-lunch qa-pa-distribution qa-paridade-escala qa-rotation-teams qa-security qa-deploy-runbook; do printf '%s: ' "$q"; runuser -u www-data -- php "$Q/scripts/$q.php" >/dev/null 2>&1 && echo OK || echo FALHOU; done
for Q in /tmp/ensaio-deploy4-qa.??????; do [ -d "$Q" ] && rm -r -- "$Q" && echo "removido: $Q"; done; echo "ensaios restantes: $(ls -d /tmp/ensaio-deploy4-qa.?????? 2>/dev/null | wc -l)"; unset Q D4
```

   Esperado: `/tmp/.env ausente: OK`; `extraido em ...`; nenhuma linha antes
   de `fim do php -l`; os 19 QAs com `OK`; `removido: ...` e `ensaios
   restantes: 0`. **PARE** com qualquer `FALHOU` (rode o QA de novo sem o
   `>/dev/null` e envie so o nome e a ultima linha).

**3. Ensaio da 021 no MySQL do servidor**, com o portal no ar, num banco
descartavel `ensaio_<nome>` que o proprio script cria e apaga (copia
`funcionarios`, as regras e as excecoes; mostra so estrutura, contagens e
assinaturas). Duas variantes: (a) migration 2x, rollback 2x; (b) com linhas
dos times (as linhas do ensaio so gravam em banco `ensaio_*`), para o
rollback com dados. O script e as migrations vem do commit gravado.

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); EV=$(mktemp -d /root/ensaio-deploy4.XXXXXX); export EV; git cat-file -e "$D4^{commit}" && bash -o pipefail -c 'git archive "$D4" migrations scripts/ensaio-migration.sh | tar -x -C "$EV"' && echo "extraido em $EV" || echo "PARE: falha ao extrair as migrations do deploy4-commit"; unset D4
env DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261115_021_rotation_teams_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_021 "$EV/migrations/20261115_021_rotation_teams.sql" "$EV/migrations/rollback/20261115_021_rotation_teams_down.sql" audit_log
env PREPARO="$EV/migrations/20261115_021_rotation_teams.sql" DADOS=portal_schedule_rules,portal_schedule_exceptions VERIFICA="$EV/migrations/verificacao/20261115_021_rotation_teams_check.sql" bash "$EV/scripts/ensaio-migration.sh" d4_021_rollback "$EV/migrations/rollback/20261115_021_ensaio_linhas_f10.sql" "$EV/migrations/rollback/20261115_021_rotation_teams_down.sql" audit_log
case "$EV" in /root/ensaio-deploy4.*) rm -r "$EV" && echo "removido: $EV";; *) echo "PARE: diretorio do ensaio inesperado";; esac; unset EV
```

   Esperado (a mesma forma do ensaio local, registrado no desenho do F10,
   8.6): **(a)** `-> migration, 1a vez: OK` e `2a vez: OK`, com
   `tabelas_021 = 3`, `colunas_021 = 2`, `restricoes_021 = 9`, as contagens
   dos times em 0 e todos no grupo `comum`; `-> rollback, 1a vez: OK`, com
   `tabelas_021 = 0`, `colunas_021 = 0`, `copias_de_rollback_021 = 5` e
   "021 ausente"; a 2a vez para de proposito (`rollback_021 abortado: ja
   existe copia *_bkp_021 ...`). As assinaturas de `regras` e
   `funcionarios` iguais nos cinco estados (anote as contagens de linhas).
   **(b)** depois das linhas, `times = 1`, `versoes = 1`, `pertencas_abertas
   = 1`, `regras_de_time = 1`, `membros_abertos_sem_regra_aberta_do_time = 0`,
   `regras_de_time_sem_membro = 0` e um cadastro no grupo `separado` (os tres
   primeiros em 0 se a copia nao tiver N1 ativo com regra aberta); o rollback
   volta e a assinatura das regras fica igual a do estado inicial. As duas
   terminam com `Removendo o banco de ensaio ...` e `Removido.`; no fim,
   `removido: /root/ensaio-deploy4.<sufixo>`. **PARE** com `FALHOU` em
   qualquer etapa que nao seja o 2o rollback, ou assinatura diferente:
   envie so as contagens (o deploy nao segue).

**4. Espaco livre e backup rapido**, com o portal no ar (o mesmo do deploy 3).

```bash
cd /var/www/chronodesk/frontend; NPMC=$(npm config get cache); test -e "$NPMC" || NPMC=$(dirname "$NPMC"); DROOT=$(docker info --format '{{.DockerRootDir}}'); ESP=OK; for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do DIR=${LIM%:*}; MIN=${LIM##*:}; LIVRE=$(df -BM --output=avail "$DIR" | tail -n 1 | tr -dc '0-9'); if [ "${LIVRE:-0}" -ge "$MIN" ]; then echo "OK $DIR (disco $(df --output=target "$DIR" | tail -n 1)): $LIVRE MB livres, minimo $MIN"; else echo "BAIXO $DIR: ${LIVRE:-?} MB livres, minimo $MIN"; ESP=PARE; fi; done; [ "$ESP" = OK ] && echo "espaco livre: OK" || echo "PARE: espaco livre abaixo do minimo"; unset NPMC DROOT ESP LIM DIR MIN LIVRE
B=/var/backups/chronodesk/deploy4-$(date +%Y%m%d-%H%M%S); export B; read -rsp 'Senha root do MySQL: ' P; echo; export P; install -d -o root -g root -m 700 "$B" && bash -o pipefail -c 'docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz" > /dev/null' && gzip -t "$B/database.sql.gz" && tar -czf "$B/estado.tar.gz" -C /var/www/chronodesk --ignore-failed-read estado.json pausas.csv config_sistema.json funcionarios.json && sh -c 'cd "$1" && sha256sum database.sql.gz estado.tar.gz > SHA256SUMS' _ "$B" && echo "backup concluido: $B ($(du -sh "$B" | cut -f1))" || echo "PARE: backup falhou (sem ponto de restauracao, o deploy nao segue)"; unset P
```

   Esperado: seis linhas `OK` e `espaco livre: OK`; `backup concluido:
   /var/backups/chronodesk/deploy4-<data> (<tamanho>)` (anote). **PARE** com
   `BAIXO` ou backup que falhou.

**5. Manutencao e copia do `app/`.** O portal responde 503 ate o passo 10.

```bash
touch /var/www/chronodesk.maintenance; echo "arquivo de manutencao: $(test -e /var/www/chronodesk.maintenance && echo presente || echo AUSENTE)"
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy4; if test -e "$A"; then diff -rq app "$A" && echo "copia do app/ ja existe e e igual: $A" || echo "PARE: $A existe e e diferente do app/ atual"; else cp -a app "$A" && diff -rq app "$A" && echo "copia do app/: $A ($(du -sh "$A" | cut -f1); $(find "$A" -type f | wc -l) arquivos)" || echo "PARE: copia do app/ falhou"; fi; unset A
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora (manutencao): %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: `arquivo de manutencao: presente`; `copia do app/: ...` (anote);
   `portal visto de fora (manutencao): 503`. **PARE** como no deploy 3.

**6. Migration 021, antes do merge** (o codigo do deploy 3 nao le nada da 021;
o do F10-2 exige ela), do commit gravado, e a verificacao logo depois:

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; git cat-file -e "$D4:migrations/20261115_021_rotation_teams.sql" && bash -o pipefail -c 'git show "$D4:migrations/20261115_021_rotation_teams.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "021 aplicada" || echo "PARE: a 021 nao foi aplicada"; unset P D4
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

   Esperado: `021 aplicada`; na verificacao, `tabelas_021 = 3`, `colunas_021
   = 2`, `restricoes_021 = 9`, `copias_de_rollback_021 = 0`, as contagens
   dos times em 0, todos no grupo `comum` e as assinaturas iguais as do
   estado inicial do ensaio (a). **PARE** com `PARE` ou outro numero: fique
   em manutencao; o codigo ainda e o do deploy 3, que nao le a 021 (o
   rollback da 021, passo 13, so com aprovacao).

**7. `git merge --ff-only` do commit gravado**, o build e o QA do bundle:

```bash
cd /var/www/chronodesk; D4=$(cat /var/backups/chronodesk/deploy4-commit); git merge --ff-only "$D4"; [ "$(git rev-parse HEAD)" = "$D4" ] && echo "HEAD = deploy4-commit" || echo "PARE: HEAD diferente do deploy4-commit"; echo "alteracoes locais: $(git status --porcelain | wc -l)"; unset D4
cd /var/www/chronodesk/frontend && if [ "$(git diff --name-only "$(cat /var/backups/chronodesk/pre-deploy4-commit)" HEAD -- package-lock.json | wc -l)" = 0 ]; then echo "lockfile sem mudanca: so o build"; npm run build; else echo "lockfile alterado: npm ci e build"; npm ci && npm run build; fi
cd /var/www/chronodesk/frontend && npm run -s qa:bundle
```

   Esperado: o `merge` em fast-forward, `HEAD = deploy4-commit` e
   `alteracoes locais: 0`; `lockfile sem mudanca: so o build` e o build sem
   erro; `Bundle frontend QA OK (...)` com o CSS abaixo de 70000 bytes (o
   F10-2 nao acrescentou CSS: 68187). **PARE** com as mesmas regras dos
   passos 5 e 6 do deploy 3.

**8. Recarga, health, fuso e teste de fumaca**, ainda em manutencao:

```bash
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'health: %{http_code}\n' -H "Host: $S" http://127.0.0.1/api/health.php; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
cd /var/www/chronodesk; runuser -u www-data -- php scripts/qa-timezone.php --db
cd /var/www/chronodesk; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then env PHPLOG=/var/log/chronodesk/php_error.log bash scripts/smoke-deploy1.sh "$S"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   Esperado: o mesmo do passo 7 do deploy 3 (`health: 200`, `QA timezone
   (banco real) OK`, `FUMACA OK`). **PARE** com qualquer `PARE` ou codigo
   diferente: nao reabra.

**9. Conferencia no banco, so leitura:** a mesma verificacao do passo 6,
agora com o codigo novo no ar (a fumaca nao grava time nem regra).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

   Esperado: os mesmos numeros do passo 6. **PARE** com numero diferente.

**10. Reabertura e conferencia na tela.**

```bash
[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance; [ -e /var/www/chronodesk.maintenance ] && echo "PARE: arquivo de manutencao ainda presente" || echo "manutencao encerrada"; S=$(awk 'tolower($1) == "servername" {print $2; exit}' /etc/apache2/sites-enabled/chronodesk.conf); if [ -n "$S" ]; then curl -s -o /dev/null -w 'portal visto de fora: %{http_code}\n' "http://$S/"; else echo "PARE: ServerName nao encontrado no chronodesk.conf"; fi; unset S
```

   So com o passo 8 como esperado. Esperado: `manutencao encerrada` e
   `portal visto de fora: 302`. **Na tela**, como admin (Ctrl+F5 uma vez),
   **sem salvar nada**:

   - **Escala presencial:** a secao "Times de rodizio" com "Nenhum time de
     rodizio" e o botao "Novo time" (abra e feche sem criar); as regras de
     sempre, com "Editar" em todas (ninguem e membro de time).
   - **Regra por colaborador:** escolher "Ciclo presencial/remoto" mostra
     "Em blocos" e "Semana a semana"; no semana a semana, a grade de 1 a 4
     semanas e a data vai para a segunda (troque de volta para "Sem escala
     definida").
   - **Funcionarios:** "Editar" mostra "Grupo da escala: Comum" (feche sem
     salvar).

   **PARE** com `PARE`, outro codigo, ou tela em branco ou quebrada: avise.

**11. Medicao do polling**, alguns minutos depois da reabertura, 2 rodadas
por endpoint, valendo a melhor, sessao de gestao (a mesma do deploy 3; base:
os numeros do deploy 3, anotados no registro dele).

```bash
cd /var/www/chronodesk; bash scripts/medir-polling.sh
cd /var/www/chronodesk; URL=http://127.0.0.1/api/portal/dashboard.php bash scripts/medir-polling.sh
cd /var/www/chronodesk; URL=http://127.0.0.1/api/solicitacoes_pendentes.php bash scripts/medir-polling.sh
```

   Esperado: p50 e p95 de cada um (anote a melhor rodada). O F10-2 nao muda
   esses endpoints. **PARE se** o p95 passar de 50 ms **e** de 20% acima da
   base do deploy 3: meca de novo e avise antes de qualquer reversao.

**12. Rollback de codigo (so se preciso, com aprovacao do responsavel).** O
`app/` volta da copia do passo 5, sem `npm`. A 021 fica: o codigo do deploy 3
nao le nada dela.

```bash
cd /var/www/chronodesk; git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy4-commit)" && echo "codigo anterior no ar: $(git rev-parse --short HEAD)"
cd /var/www/chronodesk; A=/var/backups/chronodesk/app-antes-deploy4; X=/var/backups/chronodesk/app-deploy4-retirado-$(date +%Y%m%d-%H%M%S); if test -d "$A"; then { ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && echo "app/ anterior de volta (o build retirado esta em $X)" || echo "PARE: a volta do app/ falhou"; else echo "PARE: copia $A ausente; nada mudou no app/"; fi; unset A X
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

   Esperado: `codigo anterior no ar: cd0653b`; `app/ anterior de volta
   (...)`; recarga sem saida. Depois, o passo 8 e a reabertura do passo 10.

**13. Rollback da 021 (so depois do passo 12, e so com aprovacao: a tabela
dos times, as versoes e os membros gravados vao para as copias
`*_bkp_021`).** As regras gravadas pelos times continuam regras validas (so
a marcacao `rotation_team_id` sai).

```bash
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; [ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/pre-deploy4-commit)" ] && git cat-file -e "$D4:migrations/rollback/20261115_021_rotation_teams_down.sql" && bash -o pipefail -c 'git show "$D4:migrations/rollback/20261115_021_rotation_teams_down.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas' && echo "rollback da 021 aplicado" || echo "PARE: o codigo no ar nao e o do deploy 3 ou o rollback da 021 falhou"; unset P D4
cd /var/www/chronodesk; export D4=$(cat /var/backups/chronodesk/deploy4-commit); read -rsp 'Senha root do MySQL: ' P; echo; export P; bash -o pipefail -c 'git show "$D4:migrations/verificacao/20261115_021_rotation_teams_check.sql" | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas'; unset P D4
```

   Esperado: `rollback da 021 aplicado`; a verificacao com `tabelas_021 = 0`,
   `colunas_021 = 0`, `copias_de_rollback_021 = 5`, "021 ausente" e as
   assinaturas das regras e dos cadastros iguais as do passo 6 se nenhum
   time foi usado. **PARE** com `PARE`: fique em manutencao e avise.

**Depois de um ciclo estavel, com o responsavel:** o push do commit validado
para o `codex/modernizacao-ui-backend` (nunca `--force`); com aprovacao, a
limpeza de `/var/backups/chronodesk` (`deploy4-<data>`, com dados pessoais,
`app-antes-deploy4`, `pre-deploy4-commit` e `deploy4-commit`). **A
conversao das regras de rodizio em membros de time** segue o plano do
desenho do F10 (8.6), depois de aprovada: primeiro o plano so de leitura,
depois os times e as entradas pela tela.

## HTTPS Interno

Use HTTPS mesmo na rede interna, com certificado corporativo ou self-signed distribuido de forma controlada. Quando HTTPS estiver ativo:

- Ajuste `ALLOWED_ORIGINS=https://chronodesk.interno.local`.
- Ajuste `SESSION_COOKIE_SECURE=true`.
- Ajuste `FORCE_HTTPS=true` somente depois que o VirtualHost HTTPS responder.
- Use redirecionamento HTTP para HTTPS no VirtualHost.
- Monitore validade e renovacao do certificado.

## Observacoes Sobre Docker MySQL

Para deploy simples, publique o MySQL apenas em loopback da VM:

```bash
docker run --name mysql-server -p 127.0.0.1:3306:3306 ...
```

Nesse caso, use `DB_HOST=127.0.0.1` no `.env`. Se Apache/PHP tambem rodar em container futuramente, use o nome do servico/container em uma rede Docker dedicada.

O codigo atual usa `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS`; a porta padrao 3306 e suficiente para o desenho recomendado. Se a producao exigir porta diferente, aplicar ajuste minimo em `db.php` para ler `DB_PORT`.

## Compatibilidade de Configuracao Atual

- `DB_PORT` e `DB_CHARSET` estao no exemplo de producao para documentar a intencao operacional, mas o codigo atual usa porta padrao do MySQL e charset fixo `utf8mb4`.
- `AUTH_SOURCE` e carregado em `config.php`, mas o fluxo AD atual esta implementado diretamente em `auth_ldap.php` e chamadas relacionadas.
- `AD_SERVERS` deve receber apenas hostnames ou IPs dos DCs, separados por virgula, por exemplo `servidor-ad-1.dominio.exemplo.local,servidor-ad-2.dominio.exemplo.local`.
- Nao inclua `ldap://` ou `ldaps://` em `AD_SERVERS`, porque `auth_ldap.php` monta a URI internamente como `{AD_SCHEME}://{servidor}:{porta}`. O esquema vem de `AD_SCHEME` (`ldap` padrao, `ldaps` opcional).
- Use `AD_USE_TLS=true` quando o ambiente AD suportar StartTLS e a cadeia de certificados estiver configurada no servidor Linux.

## Checklist de Validacao

- [ ] Apache responde pelo hostname interno correto.
- [ ] `AllowOverride All` ativo e `.htaccess` aplicado.
- [ ] `mod_rewrite` e `mod_headers` habilitados.
- [ ] HTTPS interno configurado ou excecao formal registrada.
- [ ] `.env` real criado manualmente e fora do Git.
- [ ] `DOCUMENT_STORAGE_PATH=/var/lib/chronodesk/documents` configurado.
- [ ] Permissoes de arquivos conferidas (secao "Permissoes de Arquivos (TO CONFIRM)").
- [ ] `fileinfo`, `zip`, `pdo_mysql` e `ldap` presentes em `php -m`.
- [ ] `upload_max_filesize=10M`, `post_max_size=12M` e `max_file_uploads=1`.
- [ ] `LimitRequestBody 12582912` aplicado no VirtualHost.
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false`.
- [ ] `SECRET_KEY` forte e unica.
- [ ] Usuario MySQL dedicado, sem uso de `root` pela aplicacao.
- [ ] Usuario MySQL runtime sem `CREATE`, `ALTER`, `DROP` ou `INDEX`.
- [ ] Banco `sistema_pausas` importado.
- [ ] `sudo -u www-data php scripts/qa-auth.php`, `sudo -u www-data php scripts/qa-smoke.php`, `sudo -u www-data php scripts/qa-timezone.php`, `sudo -u www-data php scripts/qa-overtime.php` e `sudo -u www-data php scripts/qa-security.php` terminam com `OK`.
- [ ] `sudo -u www-data php scripts/qa-timezone.php --db` informa `sessao MySQL em America/Sao_Paulo` e termina com `OK`.
- [ ] `api/status.php` responde.
- [ ] Arquivos sensiveis retornam `403` ou `404`.
- [ ] Login AD validado.
- [ ] Fluxos CI/admin/gestor validados.
- [ ] Logs do Apache revisados.
- [ ] `curl` de loopback em `api/health.php` responde `200`; fora da allowlist, `403`.
- [ ] `chronodesk-backup.timer` ativo (`systemctl list-timers chronodesk-backup.timer`).
- [ ] Teste de restauracao executado e registrado.
- [ ] Modo de manutencao testado (`MAINTENANCE_TEST=1`) e `/var/www/chronodesk.maintenance` ausente ao final.
- [ ] Rollback documentado e testado.
# Frontend React (implantação paralela)

A interface legada continua sendo servida por `index.php`, `admin.php` e
`metricas.php`. Para publicar a nova interface sem substituir o fluxo atual:

```bash
cd /var/www/chronodesk/frontend
npm ci
npm run build
```

A configuração Vite grava o build diretamente em `/var/www/chronodesk/app`.
A nova interface ficará disponível em `/app/` e consumirá os endpoints PHP na
mesma origem. Não publique `frontend/src` como aplicação final e não injete
credenciais ou variáveis LDAP no build Vite.
