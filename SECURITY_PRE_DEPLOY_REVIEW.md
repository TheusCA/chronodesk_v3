# Security Pre-Deploy Review

Data: 2026-06-12

## Escopo

Revisao estatica dos commits de autenticacao, fluxo de pausas, interface
legada, frontend React/Vite e validacoes de release. Foram analisados PHP,
JavaScript/JSX, configuracoes Apache/MySQL, exemplos de ambiente, scripts e
documentacao de deploy.

## Comandos executados

```text
git status --short --branch
git log --oneline -8
git diff origin/main..HEAD --check
git grep (segredos, senhas, DDL, grants e funcoes perigosas)
git log -p --all (padroes de chaves, tokens e DB_PASS)
cd frontend && npm audit
cd frontend && npm run lint
cd frontend && npm run build
node --check em arquivos .js fora de node_modules e dist
node --check em .js/.jsx (JSX nao e suportado diretamente pelo Node)
Semgrep 1.166.0 com regras locais para PHP/JavaScript
```

## Resultados

- `npm audit`: 0 vulnerabilidades.
- ESLint: passou.
- Vite build: passou, 460 modulos transformados.
- `node --check`: 11 arquivos JavaScript passaram.
- Os 13 arquivos JSX nao podem ser validados por `node --check`; foram
  validados por ESLint e pelo build Vite.
- Semgrep local: 63 arquivos analisados, 4 regras, 0 achados.
- Rulesets remotos do Semgrep nao foram baixados por erro de certificado TLS
  corporativo. A ferramenta ficou somente em `%TEMP%`, fora do repositorio.
- Nenhuma chave privada, token ou senha real foi encontrada.
- DDL existe apenas em schemas e scripts de migracao, nao no fluxo normal.
- Nao foi encontrado grant irrestrito; o exemplo MySQL concede somente
  `SELECT, INSERT, UPDATE, DELETE`.

## Achados e correcoes

- Bloqueado inicio direto de `Reuniao`; solicitacao pendente nao inicia timer.
- Reforcado o invariante tambem em `GerenciadorPausas`.
- Serializadas mutacoes do estado para evitar ultrapassar limite de equipe ou
  perder atualizacoes concorrentes.
- Logout legado alterado para POST com CSRF.
- Endpoints de mutacao passaram a rejeitar metodo diferente de POST.
- Bloqueado todo o diretorio `/frontend`; somente `/app/` deve receber o build.
- Adicionada CSP para documentos HTML estaticos do build.
- Removidos IPs internos e administradores nominais da arvore atual.
- `AD_ADMIN_USERS` passou a ser obrigatorio em producao.
- `ENABLE_LOCAL_ADMIN` agora e desativado por padrao.
- `AD_SERVERS` nao possui fallback interno hardcoded.
- Redirecionamento HTTPS ficou condicionado a `FORCE_HTTPS`.
- Removido fallback MySQL `root`; exemplo restringido a
  `chronodesk_app@localhost` sem privilegios DDL.
- Reforcado rate limit global e por usuario nas APIs de login.
- Deploy passou a manter PHP sob `root:www-data`, liberando escrita apenas nos
  arquivos operacionais legados.
- Formulario React preserva os dados quando uma acao falha.

## Pendencias para a VM

- Executar `php -l` em todos os PHP.
- Validar Apache, `.htaccess`, CSP e bloqueios HTTP reais.
- Validar conexao MySQL e privilegios efetivos de `chronodesk_app`.
- Validar extensao LDAP, StartTLS/certificados e failover dos DCs.
- Testar login CI, admin e metricas com contas reais autorizadas.
- Testar iniciar, solicitar, aprovar, rejeitar e finalizar pausa.
- Confirmar auditoria no banco e ausencia de senha nos logs.
- Confirmar ownership/permissoes dos arquivos operacionais.
- Os IPs internos removidos da arvore atual ainda existem em commits antigos.
  Nao foi feita reescrita de historico nesta revisao.

## Comandos para a VM Linux

```bash
cd /var/www/chronodesk

git status --short --branch
git log --oneline -10

find . -type f -name '*.php' -not -path './.git/*' -print0 \
  | xargs -0 -n1 php -l

cd frontend
npm ci
npm audit
npm run lint
npm run build
cd ..

sudo apache2ctl configtest
php -m | grep -E 'ldap|pdo_mysql|mbstring|openssl|session'
php -r "require '/var/www/chronodesk/db.php'; var_dump((bool)get_db_connection());"

BASE_URL=http://127.0.0.1 bash /var/www/chronodesk/scripts/test-linux.sh

curl -I http://127.0.0.1/
curl -I http://127.0.0.1/index.php
curl -I http://127.0.0.1/login.php
curl -I http://127.0.0.1/admin_login.php
curl -I http://127.0.0.1/metricas.php
curl -I http://127.0.0.1/admin.php
curl -I http://127.0.0.1/api/status.php
curl -I http://127.0.0.1/.env
curl -I http://127.0.0.1/database_SECURED.sql
curl -I http://127.0.0.1/README.md
curl -I http://127.0.0.1/frontend/vite.config.js
curl -I http://127.0.0.1/frontend/package.json

docker exec -it <MYSQL_CONTAINER> mysql -uroot -p -e \
  "SHOW GRANTS FOR 'chronodesk_app'@'localhost';"

sudo find /var/www/chronodesk -maxdepth 1 \
  \( -name '*.php' -o -name 'estado.json' -o -name 'pausas.csv' \
  -o -name 'config_sistema.json' \) -printf '%M %u:%g %p\n'
```

Veredito desta etapa: apto para teste na VM, ainda nao homologado para
producao ate concluir as validacoes acima.
