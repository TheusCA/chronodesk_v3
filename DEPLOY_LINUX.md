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
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20261001_015_login_user_throttle.sql
docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas < /var/www/chronodesk/migrations/20261002_016_lideranca_profile.sql
unset P
```

Senha por variavel: sem terminal (`docker exec -i` sem `-t`), o `mysql -p` le a
senha da entrada padrao, que aqui e o proprio arquivo SQL, e consome a primeira
linha dele. Por isso a senha e lida antes, sem eco, e passada em `MYSQL_PWD`
so para o comando. `MYSQL_PWD` e obsoleta no MySQL 8.0, mas funciona na imagem
`mysql:8.0`; a alternativa e um arquivo de opcoes, como o do backup.

A numeracao pula de 010 para 015 de proposito: 011 a 014 estao reservadas para a
Parte B (`docs/DESENHO_PA_ESCALA_AUSENCIA.md`) e ainda nao existem. Quando
existirem, entram nesta lista na ordem numerica. Sem a 015, o codigo atual
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
vezes, e mostra a cada etapa: tipo das colunas de `funcionarios`, indices,
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
`ENABLE_LOCAL_ADMIN=true` em producao (L4, depois do Lote 5c).

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
