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
  contagens e medicao depois. Desligamento rapido: `STATUS_PRESENCE_ENABLED=0`
  no `/var/www/.env` e recarga do PHP-FPM. Entra no roteiro consolidado da
  Parte B (deploy unico depois do F9).
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
U=$(sudo grep -E '^DB_USER=' /var/www/chronodesk/.env | cut -d= -f2- | tr -d "\"'"); read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table -e "SELECT '$U' <> '' AS usuario_lido, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'DROP') AS concessoes_drop, (SELECT COUNT(*) FROM (SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES UNION ALL SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES) g WHERE g.GRANTEE LIKE CONCAT('''', '$U', '''@%') AND g.PRIVILEGE_TYPE = 'TRIGGER') AS concessoes_trigger;"; unset P U
```

   Esperado: `usuario_lido = 1`, `concessoes_drop = 0` e
   `concessoes_trigger = 0`. Com `usuario_lido = 0`, confira o nome da
   variavel no `.env`. Com `DROP` ou `TRIGGER` maior que `0`: **nao bloqueia
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

0a. **Aviso a gestao, junto com o do F6** (risco 1 do F7 e P13 do F6). Os
   numeros vem da Q-F6 (`pas_compartilhados_hoje`) e da Q-F7
   (`n1_n2_sem_regra_hoje`) mais recentes; em 2026-10-15 e 2026-10-26, **6** e
   **6**. Texto pronto para o Teams, a Lideranca e aos gestores (troque
   DD/MM e os numeros, se mudarem):

> Pessoal, a partir de DD/MM o ChronoDesk passa a usar a escala cadastrada de
> cada tecnico no Mapa PA e na Visao Operacional. No Mapa PA, o PA em que duas
> ou mais pessoas estao presenciais no mesmo dia passa a aparecer com contorno
> vermelho e os dias do conflito; pelos numeros de hoje, ate 6 PAs podem
> aparecer assim ja no primeiro dia. E um aviso, nao um bloqueio: a alocacao
> continua podendo ser salva, e o conflito aceito fica registrado. Na Visao
> Operacional, "CIs disponiveis" passa a contar pela escala do dia: conta quem
> esta escalado, dentro do expediente e sem pausa, almoco ou ausencia lancada.
> Almoco e fora do expediente nao contam como disponiveis. Os 6 tecnicos que
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
   sha256sum` e rodar `bash` nessa copia. **Referencia: PENDENTE** (o
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
   banco, sem rede e sem tocar em `/var/www/chronodesk`.

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
mudanca.

```bash
sudo grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/ ; echo "fim (esperado: nada acima)"
if sudo grep -q '^STATUS_PRESENCE_ENABLED=' /var/www/.env; then sudo sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=0/' /var/www/.env; else echo 'STATUS_PRESENCE_ENABLED=0' | sudo tee -a /var/www/.env >/dev/null; fi
cd /var/www/chronodesk && sudo sh scripts/preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
```

   Esperado: nada no `grep` do pool e `STATUS_PRESENCE_ENABLED: OK
   (desligado)` no preflight (que nunca mostra valores). Efeito: o
   `status.php` deixa de calcular a `presenca` (as 4 consultas saem do
   polling) e os cartoes de Pausas voltam ao calculo antigo; o
   `dashboard.php` responde sem o resumo e a Visao Operacional mostra "—" e
   o aviso "Situacao da equipe indisponivel" (nunca o numero antigo, DV5); a
   tela Tecnicos mostra "—" com aviso. **A DV9, a DV10 e a DV11 continuam
   valendo** (nao dependem do interruptor). Para religar: o mesmo comando com
   `STATUS_PRESENCE_ENABLED=1`, preflight com `OK (ligado)` e a recarga.

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
