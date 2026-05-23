# ChronoDesk - Checklist de Homologacao da Fase 5

## Antes do Deploy

- [ ] `git status --short` revisado.
- [ ] `git diff --check` sem erros.
- [ ] Lint PHP executado em todos os arquivos PHP.
- [ ] `node --check` executado em todos os arquivos JS.
- [ ] `.env` real nao esta versionado.
- [ ] `APP_ENV=production` configurado no ambiente de producao.
- [ ] `APP_DEBUG=false` em producao.
- [ ] `SECRET_KEY` forte definido fora do codigo.
- [ ] `DB_USER` dedicado e `DB_PASS` definida em producao.
- [ ] `AD_SERVERS`, `AD_DOMAIN`, `AD_UPN_SUFFIX` e TLS revisados.
- [ ] `ALLOWED_ORIGINS` restrito as origens reais.
- [ ] `SESSION_COOKIE_SECURE=true` em HTTPS.
- [ ] Backup de banco e arquivos operacionais realizado.

## Producao

- [ ] Apache com `.htaccess` habilitado (`AllowOverride` efetivo).
- [ ] Diretory listing desativado.
- [ ] HTTPS ativo.
- [ ] HSTS retornado em HTTPS.
- [ ] Headers `X-Content-Type-Options`, `X-Frame-Options`, `CSP`, `Referrer-Policy` presentes.
- [ ] PHP `display_errors=Off`.
- [ ] Logs de PHP/Apache gravando em local restrito.
- [ ] Permissoes de escrita limitadas aos arquivos/diretorios necessarios.
- [ ] Extensoes PHP `pdo_mysql`, `ldap` e `zip` habilitadas.
- [ ] Conta MySQL da aplicacao sem privilegios administrativos.

## Rollback

- [ ] Commit anterior identificado.
- [ ] Backup do banco antes da alteracao validado.
- [ ] Procedimento para restaurar `.env` documentado.
- [ ] Procedimento para restaurar arquivos operacionais documentado.
- [ ] Responsavel por aprovar rollback definido.
- [ ] Criterios de rollback definidos: falha login AD, falha pausa, falha admin, erro 5xx recorrente.

## Validacao Manual no Navegador

- [ ] `index.php` carrega sem erro de console.
- [ ] Login CI via AD funciona.
- [ ] Logout CI retorna para tela de login.
- [ ] CI nao consegue agir por outro funcionario.
- [ ] Iniciar pausa funciona para Cafe/Pessoal.
- [ ] Solicitar Reuniao cria pendencia.
- [ ] Finalizar pausa registra historico.
- [ ] Login gestor abre metricas.
- [ ] Gestor aprova/rejeita solicitacoes.
- [ ] Download de relatorio entrega ZIP.
- [ ] Login admin AD/local funciona conforme configuracao.
- [ ] CRUD funcionarios preserva validacoes.
- [ ] CRUD usuarios preserva protecao do ultimo admin.
- [ ] Logout gestor/admin encerra sessao.

## Validacao no DevTools

- [ ] Cookies de sessao com `HttpOnly`.
- [ ] `SameSite` conforme politica configurada.
- [ ] `Secure` presente em HTTPS.
- [ ] POSTs possuem `X-CSRF-Token`.
- [ ] Respostas de falha CSRF retornam JSON e HTTP 403.
- [ ] Requisicoes sem permissao nao retornam dados sensiveis.
- [ ] Console sem erros JavaScript persistentes.
- [ ] Network sem chamadas para origens inesperadas.
- [ ] Headers CORS ausentes para origem nao permitida.

## Logs

- [ ] Falhas de login admin registradas.
- [ ] Rate limit registrado quando acionado.
- [ ] Login admin bem-sucedido registrado.
- [ ] Login CI bem-sucedido registrado.
- [ ] Tentativa CI para outro funcionario registrada como critica.
- [ ] CRUD admin relevante registrado.
- [ ] Download de relatorio registrado.
- [ ] Erros internos nao exibem stack trace ao usuario.
- [ ] Logs nao contem senhas, tokens CSRF, cookies ou secrets.
