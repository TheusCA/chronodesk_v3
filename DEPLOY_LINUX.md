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
