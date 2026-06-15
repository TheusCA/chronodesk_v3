# Portal SDK - Go-Live Checklist

## Pre-Deploy

- [ ] Repositorio em `main` revisado.
- [ ] Janela de implantacao aprovada.
- [ ] VM Linux provisionada e acessivel pela rede interna.
- [ ] Hostname interno definido, por exemplo `chronodesk.interno.local`.
- [ ] Apache, PHP 8 e extensoes necessarias instalados.
- [ ] `fileinfo`, `zip`, `pdo_mysql` e `ldap` confirmados em `php -m`.
- [ ] Container Docker MySQL criado e operacional.
- [ ] Backup inicial do MySQL validado.
- [ ] Certificado HTTPS interno disponivel ou excecao formal registrada.
- [ ] `.env` de producao preparado fora do Git.
- [ ] Nenhuma senha, token ou secret real em arquivos versionados.

## Deploy

- [ ] Projeto clonado em `/var/www/chronodesk`.
- [ ] `.env` real criado manualmente em `/var/www/.env`.
- [ ] Permissoes aplicadas em `/var/www/chronodesk`.
- [ ] VirtualHost Apache configurado.
- [ ] `AllowOverride All` habilitado para preservar `.htaccess`.
- [ ] `rewrite`, `headers` e `ssl` habilitados no Apache.
- [ ] Schema SQL importado no MySQL Docker.
- [ ] Migration `20260614_006_critical_incidents.sql` aplicada.
- [ ] Migration `20260615_007_war_room_and_shift_feed.sql` aplicada.
- [ ] Usuario MySQL dedicado criado.
- [ ] Aplicacao configurada para nao usar `root` no banco.
- [ ] Usuario runtime sem permissoes DDL apos as migrations.
- [ ] `upload_max_filesize=10M`, `post_max_size=12M` e `max_file_uploads=1`.
- [ ] `LimitRequestBody 12582912` aplicado no Apache.
- [ ] Storage privado de documentos fora do webroot com modo `700`.
- [ ] Storage privado de escalas fora do webroot com modo `700`.

## Pos-Deploy

- [ ] `apache2ctl configtest` sem erro.
- [ ] Apache recarregado.
- [ ] `/` responde.
- [ ] `/index.php` responde.
- [ ] `/api/status.php` sem sessao responde `401`.
- [ ] Logs separados do VirtualHost recebendo eventos.
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false`.

## Rollback

- [ ] Commit/versao anterior identificada.
- [ ] Backup do banco antes do deploy concluido.
- [ ] Comando de rollback documentado.
- [ ] Plano para restaurar dump MySQL validado.
- [ ] Plano para reverter VirtualHost ou apontamento DNS validado.
- [ ] Responsavel por decisao de rollback definido.

## Seguranca

- [ ] `.htaccess` presente e aplicado.
- [ ] HTTPS interno ativo quando possivel.
- [ ] `SESSION_COOKIE_SECURE=true` quando HTTPS estiver ativo.
- [ ] `FORCE_HTTPS=true` apos validar o VirtualHost HTTPS.
- [ ] `ALLOWED_ORIGINS` restrito ao hostname real.
- [ ] `SECRET_KEY` forte e unica definida no servidor.
- [ ] `ENABLE_LOCAL_ADMIN=false` em producao.
- [ ] Acesso ao servidor restrito a administradores autorizados.
- [ ] Firewall local liberando somente portas necessarias.

## Logs

- [ ] `/var/log/apache2/chronodesk_access.log` criado.
- [ ] `/var/log/apache2/chronodesk_error.log` criado.
- [ ] Logs de auditoria da aplicacao revisados.
- [ ] Retencao e rotacao de logs configuradas.
- [ ] Falhas de login e erros PHP monitorados.

## Backup

- [ ] Backup automatico do MySQL configurado.
- [ ] Retencao de backups definida.
- [ ] Restore testado em ambiente controlado.
- [ ] Backups armazenados fora do `DocumentRoot`.
- [ ] Permissoes dos backups restritas.

## Validacao Funcional

- [ ] Login CI validado.
- [ ] Inicio de pausa validado.
- [ ] Finalizacao de pausa validada.
- [ ] Solicitacao de pausa com aprovacao validada.
- [ ] Aprovacao/rejeicao por gestor validada.
- [ ] Gestor/admin consegue decidir a propria solicitacao de pausa.
- [ ] Tecnico recebe `403` ao tentar aprovar ou rejeitar pausa.
- [ ] Chamados criticos: criar, editar, filtrar e alterar status validados.
- [ ] CSV de chamados criticos: preview, confirmacao e exportacao validados.
- [ ] CSV invalido, acima do limite ou com coluna desconhecida rejeitado.
- [ ] Metricas validadas.
- [ ] Relatorio/download validado.
- [ ] Logout validado.
- [ ] Timeout de sessao validado.

## Validacao AD

- [ ] DNS resolve os servidores AD.
- [ ] Conectividade com portas LDAP/LDAPS validada.
- [ ] `AD_DOMAIN` confirmado.
- [ ] `AD_UPN_SUFFIX` confirmado.
- [ ] `AD_SERVERS` confirmado.
- [ ] `AD_USE_TLS` validado.
- [ ] `AD_CREDENTIAL_PROVIDER=none` enquanto nao houver contrato real com o cofre.
- [ ] Usuarios admin em `AD_ADMIN_USERS` revisados.
- [ ] Usuario nao autorizado bloqueado.

## Validacao de Banco

- [ ] Banco `sistema_pausas` existe.
- [ ] Tabelas `funcionarios`, `pausas`, `usuarios` e `audit_log` existem.
- [ ] Usuario `chronodesk_app` autentica.
- [ ] Usuario `chronodesk_app` nao possui privilegios administrativos globais.
- [ ] Aplicacao consegue ler e gravar dados esperados.
- [ ] Erros de conexao nao exibem detalhes sensiveis ao usuario.

## Arquivos Sensiveis

- [ ] `/.env` retorna `403` ou `404`.
- [ ] `/.git/config` retorna `403` ou `404`.
- [ ] `/database_SECURED.sql` retorna `403` ou `404`.
- [ ] `/README.md` retorna `403` ou `404`.
- [ ] Arquivos `.sql`, `.csv`, `.json`, `.log`, `.bak` e scripts operacionais nao sao publicados.
- [ ] Documentos enviados nao sao acessiveis diretamente pelo Apache.
- [ ] `classes/`, `sessions/`, `cache/`, `tmp/`, `vendor/` e `node_modules/` bloqueados quando existirem.

