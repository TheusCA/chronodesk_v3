# Lote 4 restante — SEC-08, SEC-07 (avisos) e banco indisponível (0d)

**Data:** 2026-10-02
**Situação:** SEC-08, 0d e SEC-07 (avisos) **implementados** em commits locais. S7a, S7b e S7c aprovadas em 2026-10-02, como recomendadas. As ausências e o calendário do SEC-07 ficam no F3, como no desenho da Parte B.

---

## 1. SEC-08 — anexo de escala por equipe (implementado)

**Decisão (2026-10-02):** anexo de escala restrito à própria equipe; gestor, Liderança e admin veem todos.

| Item | Como ficou |
|---|---|
| Dado | Migration 017: `portal_shift_attachments.team ENUM('n1','n2','lideranca') NULL`, com índice `(status, team, reference_month)`. `NULL` = todas as equipes. Nenhuma linha alterada: os anexos existentes ficam como "todas as equipes", que é o comportamento de hoje |
| Upload | Equipe **obrigatória**: N1, N2, Liderança ou "Todas as equipes". Sem escolha, 400 |
| Quem vê | Gestor, Liderança e admin: todos. Técnico e somente leitura: os da própria equipe (do cadastro, ativo) e os de todas as equipes. Sem equipe identificada: só os de todas as equipes |
| Listagem | Filtro no SQL (`team IS NULL OR team = :equipe`), antes do limite da lista |
| Download | Fora do escopo responde **404**, como anexo inexistente, para não confirmar o ID; auditado como `SHIFT_ATTACHMENT_DOWNLOAD_DENIED` |
| Tela | Seleção "Equipe que verá" no upload; selo da equipe em cada anexo |
| Rollback | `migrations/rollback/20261002_017_shift_attachment_team_down.sql`, depois do código: remove índice e coluna; a classificação se perde e todos voltam a ver tudo |

Reclassificar um anexo antigo: hoje só republicando com a equipe certa (não há edição de anexo). Se for preciso em volume, um `UPDATE` com aprovação, fora deste lote.

## 2. SEC-07 — escopo dos avisos (`portal/announcements.php`): **aprovado e implementado**

**Hoje.** `portal_announcements` tem a coluna `audience` (`VARCHAR(100)`, padrão `'all'`), mas a listagem (`PortalService::list`) a ignora: todo perfil autenticado vê todos os avisos. Não há tela nem endpoint de cadastro; os avisos entram direto no banco.

**Proposta.**

| Item | Proposta |
|---|---|
| Valores de `audience` | `all` (também `NULL`, vazio e `todos`), `n1`, `n2`, `lideranca` (equipe) e `gestao` (perfis gestor, Liderança e admin). Mais de um público separado por vírgula (`n1,n2`) |
| Gestor, Liderança, admin | Veem **todos** os avisos (para conferir o que foi publicado), como no SEC-08 |
| Técnico e somente leitura | Veem `all` e os que citam a própria equipe; nunca `gestao` |
| Valor desconhecido | Só a gestão vê (falha fechada) |
| Onde | Filtro no SQL de `portal/announcements.php` (não no `PortalService::list` genérico), antes do limite |
| Dados | Sem migration (a coluna já existe). **Antes de implementar**, uma consulta só de contagem para ver o que está gravado: `SELECT audience, COUNT(*) FROM portal_announcements GROUP BY audience;` |
| Fora do escopo | Tela de cadastro de avisos (a permissão `avisos.manage` existe, mas não há endpoint) |
| Testes | Tabela-verdade perfil × público; endpoint por perfil com o banco simulado; valor desconhecido; vírgula; maiúsculas e espaços |

**Decisões (aprovadas em 2026-10-02, como recomendadas):** S7a, a regra acima; S7b, gestão vê todos os avisos; S7c, valor desconhecido só para a gestão.

**Implementação.** `security.php`: `portal_viewer_scope()` (compartilhada com o SEC-08), `announcement_audience_tokens()` e `announcement_visible()` (regra em PHP). `PortalService::announcements()`: filtro no SQL (`REPLACE(LOWER(COALESCE(audience, '')), ' ', '')` com `FIND_IN_SET` para `all`, `todos` e a equipe do ator), antes do limite. `portal/announcements.php` deixa de usar a listagem genérica sem escopo. O QA compara o resultado do endpoint (com o SQL emulado no banco simulado) com a regra em PHP para gestor, Liderança, técnico N1, técnico N2 e somente leitura.

**Checagem prévia no deploy:** `SELECT audience, COUNT(*) FROM portal_announcements GROUP BY audience;` (só contagem; o responsável está rodando). Valor fora do padrão (`all`, `todos`, vazio, `n1`, `n2`, `lideranca`, `gestao`, ou lista deles) fica **visível só para a gestão**; o responsável decide antes do deploy se corrige o valor no banco.

## 3. 0d — banco indisponível (implementado)

**Antes:** `api/solicitacoes_pendentes.php` e `api/portal/standby.php` deixavam a exceção subir (erro fatal no log, 500 no PHP-FPM); `download_relatorio.php`, `portal/documents.php` e `portal/shift_attachments.php` capturavam a falha de banco e respondiam 500 com mensagem genérica.

| Peça | O que faz |
|---|---|
| `DatabaseUnavailableException` (`db.php`) | Falha de **conexão** passa a ter exceção própria (antes, `Exception` genérica) |
| `database_unavailable_error()` (`security.php`) | Reconhece falha de consulta (`PDOException`) e de conexão, inclusive encadeada |
| `portal_operational_error()` | Falha de banco: **503** com "Banco de dados indisponível no momento. Tente novamente em instantes." |
| Rede de segurança (`chronodesk_uncaught_exception`, registrada no `config.php` só em requisição web) | Exceção que ninguém capturou: uma linha `[UNCAUGHT]` no log, sem "PHP Fatal error"; 503 com a mensagem para falha de banco, 500 genérico (sem detalhe) para o resto |
| Endpoints | Os dois da pendência tratam explicitamente (503 com mensagem); os três que davam 500 passam a 503 para falha de banco; nove endpoints do portal criavam o serviço (e conectavam) fora do `try` e agora criam dentro |

**Levantamento (2026-10-02):** todos os endpoints de `api/` e `api/portal/`, GET e POST, em dois modos: falha de consulta (banco simulado) e falha de conexão (banco real inexistente). Depois da correção: nenhum erro fatal, nenhum `[UNCAUGHT]`, nenhum 500. Sessões que dependem do banco para revalidar (CI e local) já recebem 503 da revalidação (A1) antes de chegar ao endpoint; por isso o levantamento usa sessão de admin por AD, que revalida só pela allowlist.

## 4. Deploy e rollback

- **Checagem prévia (SEC-07):** `SELECT audience, COUNT(*) FROM portal_announcements GROUP BY audience;`. Valor fora do padrão fica visível só para a gestão.
- Migration 017 **antes** do `git pull` (o código anterior não lê a coluna). Ensaio: `sudo bash scripts/ensaio-migration.sh m017 migrations/20261002_017_shift_attachment_team.sql migrations/rollback/20261002_017_shift_attachment_team_down.sql portal_shift_attachments`.
- Depois da recarga: publicar um anexo para N1 e conferir que um técnico da N2 não o lista nem o baixa (404), e que um gestor vê os dois. Avisos: um técnico vê os de todos e os da própria equipe; um gestor vê todos.
- Rollback: código primeiro; depois, opcional, o `017_down`.
