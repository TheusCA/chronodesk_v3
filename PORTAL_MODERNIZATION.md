# Portal Operacional SDK / ChronoDesk

## Escopo implementado

- Layout React responsivo com sidebar, topbar, notificações e rotas reais em `/app`.
- Proteção visual de rotas baseada nas permissões retornadas pelo backend.
- Pausas com cronômetro reconstruído por `inicio_pausa`, limite, tempo restante e progresso.
- Admin React com aprovações, configurações, funcionários, usuários locais e segurança.
- Métricas com filtros, intervalo personalizado, indicadores, rankings, tabela e exportação.
- Notificação de aprovação por SMTP ou `mail()`, configurada exclusivamente por ambiente.
- Fundação autenticada para calendário, técnicos, escalas, ausências, horas extras,
  correções de ponto, avisos, plantões, sobreavisos, documentação, relatórios e integrações.
- Migration incremental para módulos, notificações, histórico de aprovações e dados do portal.

## Implantação

1. Configure as variáveis novas usando `.env.production.example` como referência.
2. Aplique `migrations/20260612_001_portal_foundation.sql` com um usuário de migration.
3. Execute `npm ci`, `npm run lint`, `npm audit` e `npm run build` em `frontend/`.
4. Publique somente o conteúdo gerado em `/app`; `/frontend` continua bloqueado.
5. Valide o fallback do Apache para `/app/*` e os bloqueios de arquivos sensíveis.

O usuário runtime da aplicação precisa apenas das permissões necessárias nas tabelas usadas
pelo portal. Não use `root` nem conceda privilégios de DDL ao runtime.

## E-mail

`MAIL_ENABLED=false` mantém o envio desabilitado. Quando habilitado:

- `MAIL_FROM` e todos os itens de `MAIL_APPROVERS` devem ser endereços válidos.
- `SMTP_ENCRYPTION` aceita `tls`, `ssl`, `none` ou vazio.
- `SMTP_TIMEOUT` é limitado pelo serviço entre 2 e 30 segundos.
- Falhas de e-mail são auditadas e não cancelam a solicitação.
- O link direciona ao Admin autenticado; não existe aprovação por link público.

## Compatibilidade

`/admin.php` e `/metricas.php` permanecem disponíveis. Nenhum redirecionamento legado foi
ativado. A migration não remove nem altera tabelas existentes.

## Validação dependente do ambiente

Login AD, escrita no MySQL, envio SMTP e os testes autenticados exigem a infraestrutura
corporativa. Use `scripts/test-local.ps1` para os checks HTTP básicos e execute os cenários
autenticados descritos em `TEST_PLAN.md` com contas de técnico, gestor e admin.
