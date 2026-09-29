# Registro de Uso de Inteligência Artificial — ChronoDesk / Portal SDK

Registro exigido pelos requisitos corporativos para soluções desenvolvidas fora da esteira formal de Engenharia. Deve ser atualizado a cada lote de trabalho assistido por IA.

---

## 1. Identificação

| Item | Valor |
|---|---|
| Ferramenta | Claude Code (CLI da Anthropic) |
| Modelo | Claude Opus 5 — identificador `claude-opus-5[1m]` (Lotes 0 a 2B); Claude Opus 5.5 — `claude-opus-5-5` (a partir do Lote 3) |
| Responsável humano | Matheus Camargo |
| Projeto | ChronoDesk / Portal SDK |
| Repositório | Conta pessoal no GitHub (migração para Azure DevOps pendente) |
| Branch | `codex/modernizacao-ui-backend` |

## 2. Modo de operação e limites acordados

- A IA opera em **clone de desenvolvimento local (Windows)**. **Não possui acesso ao servidor de aplicação, ao container MySQL, ao Active Directory ou a qualquer ambiente produtivo.**
- Qualquer ação de infraestrutura é **entregue como comando para execução humana**, nunca executada pela IA.
- A IA faz **commits locais somente com autorização explícita** do responsável. O **push é sempre executado pelo responsável humano**.
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

### Lote 3 — Continuidade e observabilidade — 2026-09-29

| Campo | Conteudo |
|---|---|
| **Finalidade** | Entregar backup diario com procedimento de restauracao (OPS-01), endpoint de saude (OPS-02) e fluxo de deploy com rollback documentado. PERF-01 foi analisado e aguarda aprovacao antes de qualquer implementacao. |
| **O que a IA alterou** | `api/health.php` (novo): 200/503 com status do banco, sem sessao, sem `init.php`, conexao propria com timeout de 2 s, acesso restrito por `HEALTH_ALLOWED_IPS` (padrao loopback). `config.php`: constante `CHRONODESK_STATELESS` que evita abrir sessao, usada so pelo health. `security.php`: `ip_in_allowlist()` (IP exato ou CIDR, IPv4/IPv6). `scripts/backup-chronodesk.sh` (novo): dump via `docker exec` com usuario somente leitura e credencial entregue por stdin, arquivo dos estados e diretorios privados, SHA-256, publicacao atomica do diretorio e retencao restrita ao padrao de nome. `deploy/`: usuario MySQL de backup, modelo de configuracao, unidades systemd. `DEPLOY_LINUX.md`: secoes de monitoracao, backup, teste de restauracao, restauracao em incidente e atualizacao/rollback. `scripts/qa-smoke.php`: testes do allowlist e do health em subprocesso. `scripts/test-linux.sh`: sintaxe dos scripts shell e checagens HTTP novas. `.env.example`, `.env.production.example`, `docs/DOCUMENTACAO_TECNICA.md`. |
| **Validacao executada pela IA** | `php -l` em 83 arquivos; `qa-smoke.php` e `qa-auth.php` OK, inclusive em copia com `.env` de producao contendo valores hostis. Teste de mutacao do health: 6 defeitos introduzidos, 6 detectados. Backup: harness de 24 casos com stubs de `docker` e `php`, executado como root em WSL (Fedora), incluindo falhas de dump, credencial com permissao aberta, chave desconhecida, JSON truncado, concorrencia e injecao de opcao; mutacao reintroduzindo `grep -q` apos `gzip` derruba 11 casos, confirmando a correcao de um falso negativo por SIGPIPE. |
| **Validacao humana** | **Revisao aprovada em 2026-09-29, com ajustes:** (a) aviso de que parar o `apache2` na restauracao derruba tambem o outro site da VM; (b) bloco de permissoes `root:www-data` retirado da rotina de deploy e registrado como passo separado **TO CONFIRM**, porque o servidor funciona com `root:root 644`; a restauracao passou a preservar dono e modo existentes; (c) consulta de tabelas nao-InnoDB antes da primeira execucao do backup. **Execucao no servidor pendente.** O script de backup **nunca rodou contra MySQL real** (stubs substituem `docker`/`mysqldump`); a entrega do arquivo de opcoes por `--defaults-extra-file=/dev/stdin` (validada pela consulta InnoDB) e o teste de restauracao precisam ser confirmados na primeira execucao manual. O caminho 200 do health so e exercitado com banco real. |
| **Observacoes** | Modelo deste lote: Claude Opus 5.5 (`claude-opus-5-5`). O backup fica no disco da propria VM; copia externa depende de infraestrutura. |

### PERF-01 (itens A e D) — Estado de pausas — 2026-09-29

| Campo | Conteudo |
|---|---|
| **Finalidade** | Tirar o lock exclusivo global do caminho de leitura (polling de 15 s) e eliminar a leitura parcial de `estado.json`. **Deploy separado, posterior ao deploy dos Lotes 1 a 3** (que termina no commit `072ed16`). |
| **Aprovacao** | Itens A e D aprovados pelo responsavel em 2026-09-29. B (intervalos de polling) recusado por ora, a reavaliar com medicao apos A. C (carga preguicosa de funcionarios) recusado. |
| **O que a IA alterou** | `config.php`: `ler_estado_pausas()` le com `LOCK_SH`; `classificar_pausa_antiga()` e o criterio unico de limpeza; `limpar_pausas_abandonadas()` faz pre-checagem sem o lock exclusivo e so o pega quando ha pausa a limpar; a gravacao e decidida exclusivamente pela releitura sob lock. `limpar_estado_antigo()` mantem a assinatura. `security.php`: caminho do lock extraido para `pause_state_lock_path()` e parametro opcional de caminho em `with_pause_state_lock()`, sem mudar as chamadas existentes. `classes/GerenciadorPausas.php`: `carregar_estado()` usa o leitor com `LOCK_SH`. |
| **Decisao D** | `LOCK_SH` na leitura em vez de gravacao atomica (temporario + rename). O rename exigiria permissao de escrita do usuario do PHP na pasta de `estado.json`, que pertence a root (e cujo estado real esta TO CONFIRM); trocaria o inode e, com ele, dono e modo do arquivo; e quebraria o contrato de `LOCK_EX` no proprio arquivo usado por `file_put_contents`, que trava antes de truncar. |
| **Validacao executada pela IA** | Testes de comportamento em `qa-smoke.php` sobre arquivo e lock temporarios (nunca o estado real): gravador simulado segurando `LOCK_EX` com metade do JSON, e o leitor espera e ve o estado completo; previa invalida, previa desatualizada, releitura divergente, releitura invalida e arquivo truncado em disco nao gravam; com o lock exclusivo ocupado, estado sem pausa antiga retorna sem esperar, e o controle positivo espera e grava. Mutacao: 6 defeitos introduzidos, 6 detectados. |
| **Validacao humana** | **Pendente.** Medir a latencia do polling antes e depois do deploy, que tambem e a base para reavaliar o item B. |

---

## 5. Responsabilidade

O conteúdo gerado por IA é **proposta técnica sujeita a revisão humana**. A responsabilidade pela correção, pela adequação ao negócio e pela publicação em qualquer ambiente é do responsável humano identificado na seção 1. Nenhuma alteração gerada por IA foi promovida a ambiente produtivo sem revisão e execução manual.
