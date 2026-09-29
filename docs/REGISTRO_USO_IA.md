# Registro de Uso de Inteligência Artificial — ChronoDesk / Portal SDK

Registro exigido pelos requisitos corporativos para soluções desenvolvidas fora da esteira formal de Engenharia. Deve ser atualizado a cada lote de trabalho assistido por IA.

---

## 1. Identificação

| Item | Valor |
|---|---|
| Ferramenta | Claude Code (CLI da Anthropic) |
| Modelo | Claude Opus 5 — identificador `claude-opus-5[1m]` |
| Responsável humano | Matheus Camargo |
| Projeto | ChronoDesk / Portal SDK |
| Repositório | Conta pessoal no GitHub (migração para Azure DevOps pendente) |
| Branch | `codex/modernizacao-ui-backend` |

## 2. Modo de operação e limites acordados

- A IA opera em **clone de desenvolvimento local (Windows)**. **Não possui acesso ao servidor de aplicação, ao container MySQL, ao Active Directory ou a qualquer ambiente produtivo.**
- Qualquer ação de infraestrutura é **entregue como comando para execução humana**, nunca executada pela IA.
- A IA **não faz commit nem push**. Todo versionamento é ato humano.
- Alterações em autenticação, RBAC, CSRF, auditoria ou regra de negócio exigem **aprovação humana explícita item a item** antes da implementação.
- Segredos (`.env`, senhas, `SECRET_KEY`, tokens, dumps reais) **não são lidos, exibidos nem transmitidos**. Quando um segredo é localizado, reporta-se arquivo e linha, nunca o valor.

## 3. Confirmação sobre tráfego de dados sensíveis

**Nenhum segredo, credencial, dado pessoal de colaborador ou dump de produção foi transmitido à ferramenta de IA.**

Base da afirmação:

- O arquivo `.env` real reside em `/var/www/.env`, no servidor, fora do alcance do ambiente de trabalho da IA. O clone local não contém `.env` (coberto pelo `.gitignore`).
- Não houve conexão com o banco de dados; nenhum registro de colaborador foi lido de produção.
- As varreduras por segredos (`HEAD` e `git log -p --all`) retornaram **apenas** a fixture de teste `SECRET_KEY=qa-only-secret-key` em `scripts/qa-smoke.php:6`. Nenhuma credencial real existe no repositório.
- Nomes de colaboradores presentes em `config.php` e `database_SECURED.sql` foram processados por estarem **versionados no repositório** — são, eles próprios, um achado registrado na auditoria (LGPD-01), com remediação pendente de aprovação.
- Valores corporativos sensíveis (domínio AD, sufixo UPN, hostnames de DC) foram identificados no repositório e **substituídos por placeholders no Lote 1**. Nenhum documento gerado pela IA reproduz esses valores.

---

## 4. Registro por lote

### Lote 0 — Auditoria (somente leitura) — 2026-09-28

| Campo | Conteúdo |
|---|---|
| **Finalidade** | Auditoria técnica completa de segurança (OWASP Top 10 / ASVS), regras de negócio, concorrência, desempenho, QA e aderência aos requisitos corporativos. |
| **O que a IA gerou** | `docs/AUDITORIA_2026-09.md`: resumo executivo, 30 achados classificados por severidade com evidência `arquivo:linha` e distinção explícita entre fato e hipótese, matriz de aderência aos requisitos corporativos, plano de execução em lotes e perguntas em aberto. |
| **O que a IA executou** | Somente leitura: leitura de arquivos, `git log`, `git ls-files`, `npm run lint`, `npm run typecheck`, `npm audit`, `php -l` em 81 arquivos e 13 scripts de QA estático do frontend. Nenhum comando contra o servidor. |
| **Validação humana** | Revisão dos achados pelo responsável. Decisões registradas: (a) a ausência dos documentos de contexto no repositório é intencional e foi removida da auditoria; (b) solicitada verificação adicional do achado SEC-04 (perfis que podem alterar a equipe de um colaborador); (c) aprovação dos Lotes 1, 2, 2B e 3, com ajustes de escopo. |
| **Limitações declaradas** | PHP local 8.2 × servidor 8.4 (`php -l` valida sintaxe, não runtime). `scripts/qa-smoke.php` não foi executado nesta fase por se supor que exigia banco — **suposição incorreta, corrigida em 2026-09-29**: o script não abre conexão com o MySQL e roda localmente (`php scripts/qa-smoke.php`, EXIT 0). `gitleaks` indisponível — usada varredura por padrões. `qa:visual` falha por depender do build em `app/`, não versionado. |

### Lote 1 — Governança e sanitização — 2026-09-28

| Campo | Conteúdo |
|---|---|
| **Finalidade** | Remover do repositório os identificadores de infraestrutura corporativa (domínio AD, sufixo UPN, hostnames de DC) e produzir a documentação técnica mínima exigida. |
| **O que a IA gerou/alterou** | Sanitização de `.env.example`, `.env.production.example`, `README.md`, `DEPLOY_LINUX.md`, `PROMPT_CHATGPT.md`, `auth_ldap.php`, `login.php`, `admin_login.php` e `frontend/src/components/LoginCI.jsx`. Remoção do fallback literal de domínio em `auth_ldap.php`, substituído por falha fechada com auditoria. Criação de `docs/DOCUMENTACAO_TECNICA.md` e deste registro. |
| **Validação humana** | **Pendente.** Requer conferência do responsável antes de commit, com atenção especial ao pré-requisito de deploy: `AD_DOMAIN` deve estar presente em `/var/www/.env`, sob pena de falha de autenticação. |
| **Observações** | A remoção do arquivo `ersmmdcamargochronodesk_v3` foi **bloqueada por hook de política de Segurança da Informação** que impede comandos destrutivos. Fica pendente de execução manual pelo responsável. O histórico do git **não foi reescrito**, por decisão do responsável — os valores permanecem acessíveis em commits anteriores. |

### Lote 2 — Correcoes de baixo risco e alto retorno — 2026-09-28

| Campo | Conteudo |
|---|---|
| **Finalidade** | Corrigir o redirect que expunha caminho de filesystem, preservar os redirects legados no repositorio, habilitar cache de assets, estender as travas de seguranca para fora de `production`, fechar a lacuna de TAB/CR na neutralizacao de CSV e unificar a mensagem de 403. |
| **O que a IA alterou** | `.htaccess` (`RewriteBase /`, redirects legados, cache de `app/assets/*` e `app/index.html`); `config.php` (travas passam a valer fora de `development`, exceto `SECRET_KEY`); `.env.production.example` (alerta de indisponibilidade com `FORCE_HTTPS=true`); `services/OperationalService.php`, `api/portal/critical_incidents_export.php`, `api/download_relatorio.php` (TAB/CR no CSV); `frontend/src/lib/api.ts` (mensagem padrao de 401/403); 5 scripts de QA (remocao da trava de escopo da Fase 17 sobre `api.ts`). |
| **Validacao humana** | **Pendente.** Requer conferencia, com atencao a: (a) alteracao deliberada de 5 scripts de QA; (b) pre-checagens obrigatorias antes do deploy conjunto 1+2; (c) `npm audit fix` nao aplicado. |
| **Observacoes** | O `npm audit fix` foi **bloqueado pelo ambiente**: o endpoint de advisories do npm passou a falhar com `self-signed certificate in certificate chain` (interceptacao TLS corporativa). A IA **nao** usou `--strict-ssl=false` para contornar, por ser desativacao de verificacao de certificado em um lote cujo proposito e justamente endurecer a seguranca. O `package-lock.json` permaneceu intacto e o item segue pendente de decisao do responsavel. |

### Lote 2B — Preparacao de LDAPS — 2026-09-29

| Campo | Conteudo |
|---|---|
| **Finalidade** | Tornar o esquema de conexao LDAP configuravel por ambiente, preparando LDAPS/StartTLS sem alterar o comportamento atual, e cobrir com teste negativo a rejeicao de senha vazia no bind. |
| **O que a IA alterou** | `auth_ldap.php`: nova variavel `AD_SCHEME` (`ldap` padrao, `ldaps` opcional), validacao com fallback seguro, aviso quando `AD_PORT=636` com esquema `ldap`, neutralizacao de `AD_USE_TLS` sob `ldaps` e exigencia de certificado valido (`LDAP_OPT_X_TLS_REQUIRE_CERT = demand`) sempre que houver TLS. `scripts/qa-auth.php` (extraido de `qa-smoke.php`, sem dependencia de banco): testes negativos estruturais e comportamentais de senha vazia, senha `"0"` e login vazio, com verificacao de que nenhuma conexao e tentada e controle positivo. Incluido em `scripts/test-local.ps1` e `scripts/test-linux.sh`. `.env.example` e `.env.production.example`: documentacao das variaveis e do pre-requisito de CA confiavel na VM. |
| **Validacao humana** | **Pendente.** `scripts/qa-auth.php` **exige a extensao `ldap` do PHP** e falha (nao pula) quando ela esta ausente, para nao reportar sucesso sem testar. No Windows, habilitar `extension=ldap` no `php.ini`; no servidor, `php-ldap`. Executar com `php scripts/qa-auth.php` localmente e no servidor. |
| **Observacoes** | Nenhuma alteracao de comportamento no servidor sem edicao do `.env`: o padrao `AD_SCHEME=ldap` reproduz exatamente a URI anterior. A exigencia de certificado valido so tem efeito quando TLS e habilitado. |

---

## 5. Responsabilidade

O conteúdo gerado por IA é **proposta técnica sujeita a revisão humana**. A responsabilidade pela correção, pela adequação ao negócio e pela publicação em qualquer ambiente é do responsável humano identificado na seção 1. Nenhuma alteração gerada por IA foi promovida a ambiente produtivo sem revisão e execução manual.
