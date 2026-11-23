# Lote NC e ícone novo (deploy 5)

**Estado:** pedido em 2026-11-23, depois do lote 8.8; plano e execução neste documento; entra no deploy 5. Sem push próprio: o push do `codex/modernizacao-ui-backend` vai junto da autorização do lote anterior, só com a bateria final verde (resposta do responsável em 2026-11-23).

**LGPD:** o NC é a matrícula da pessoa (cadastro Senior). Nenhum NC real entra no git, nos docs, nos QAs nem no chat: só valores fictícios e contagens.

---

## 1. Pedido

1. **NC obrigatório na hora extra e na correção de ponto.** O NC é um número fixo por pessoa; hoje a coluna NC dos dois CSVs sai vazia.
   - Migration 026 (a 023 fica reservada ao F10-4): o NC no cadastro (só dígitos, 1 a 12, NULL permitido, único; CHECK no banco) e uma cópia dele em cada lançamento (NULL nas linhas antigas). Idempotente, com rollback, verificação, linhas do ensaio e o ensaio (a) e (b) no MySQL do WSL.
   - Cadastro de Funcionários: campo NC (Liderança e admin), obrigatório no cadastro novo; na lista, o aviso "N cadastros ativos sem NC" e um filtro para eles; a auditoria registra que o NC mudou, por ID e sem o valor.
   - Formulários de hora extra e de correção de ponto: o NC da pessoa do lançamento, só leitura, vindo do cadastro. Com `NC_OBRIGATORIO=1` no `/var/www/.env`, o servidor recusa o lançamento de quem não tem NC ("Sua matrícula (NC) não está no cadastro. Peça à Liderança para incluir."), e a tela avisa e não envia. Sem a chave (padrão), só avisa. A cópia no lançamento vem do cadastro, nunca do navegador.
   - CSVs: a coluna NC com a cópia do lançamento; nas linhas antigas, o NC atual do cadastro. A ordem e os outros campos não mudam (o modelo novo é outro lote).
2. **Ícone novo:** a opção **B (chaves com pausa)** de `_referencias\icone\opcoes-2026-11-23\`, escolhida pelo responsável em 2026-11-23 (o pedido veio com o marcador `<A|B|C>`). Mesmo padrão do `2ace053`: `BrandMark.jsx` com SVG inline, sem cor fixa nem `style` (placa no token do `bg-action`, traços no `text-action-ink`, o detalhe âmbar no token da pausa, com contraste conferido nos dois temas); `favicon.svg`, `favicon-16.svg`, `favicon.ico` (16, 32 e 48), `favicon.png`, `apple-touch-icon.png` (180) e `icon-512.png` gerados dos SVGs; links com `?v=3` no `index.html` e nas 5 páginas PHP. O favicon não depende de tema.

## 2. Plano curto

| Commit | Conteúdo |
|---|---|
| 1 | Migration 026 (rollback, verificação, linhas do ensaio), `qa-nc.php` (estrutura) na bateria; no roteiro e na folha do deploy 5, a 026 depois da 022 e antes do merge, com o "antes", os ensaios, a verificação de novo e o rollback; a trava |
| 2 | Este documento |
| 3 | Servidor: o NC no cadastro (criar, editar, listar, auditoria), a cópia do NC no lançamento pelo servidor, `NC_OBRIGATORIO`, a leitura do NC para o formulário e os CSVs; QAs |
| 4 | Telas: campo NC no cadastro, aviso e filtro na lista; NC só leitura nos dois formulários, com o aviso e o bloqueio; QAs do frontend e do navegador |
| 5 | Ícone B |
| 6 | Roteiro e folha do deploy 5 (o que vai, conferência na tela, fumaça) e a trava |
| 7 | Docs: execução, validação e o registro de uso de IA |

## 3. Decisões de implementação (a aprovar)

| # | Decisão | Por quê |
|---|---|---|
| D-NC-1 | `funcionarios.matricula VARCHAR(12) NULL`, CHECK `REGEXP_LIKE(matricula, '^[0-9]{1,12}$', 'c')` e índice único (vários NULL); nos lançamentos, `employee_nc VARCHAR(12) NULL` com a mesma CHECK | Texto guarda zero à esquerda; NULL deixa o cadastro antigo valer até a gestão preencher |
| D-NC-2 | A cópia do NC é gravada pelo servidor, lida do cadastro na mesma transação do lançamento; `nc` ou `employee_nc` no corpo da requisição são ignorados | O servidor é a fonte da verdade |
| D-NC-3 | O NC só aparece e só muda para quem gerencia funcionários (`funcionarios.manage`: Liderança e admin); a lista de funcionários só traz o campo para eles; auditoria `FUNCIONARIO_NC_ALTERADO` (WARNING) só com o ID e "incluído", "alterado" ou "removido", nunca o valor | Dado pessoal; menor exposição |
| D-NC-4 | No cadastro novo, o NC é obrigatório (400). Na edição, ausente mantém o gravado; vazio num cadastro que já tem NC é recusado (400: o NC não se apaga pela tela) | Evita perder o número por engano; o cadastro antigo sem NC continua editável |
| D-NC-5 | `NC_OBRIGATORIO`: `1`/`true` liga; ausente ou vazio desliga; valor inválido desliga com aviso no log. Ligada, o lançamento de quem não tem NC é recusado com 409 e a mensagem do pedido | Falha aberta (só aviso) é o padrão pedido; a chave muda o comportamento sem deploy |
| D-NC-6 | O formulário lê o NC por `GET ...?nc=1&employee_id=<id>` nos dois endpoints: técnico e somente leitura só o próprio; gestão, qualquer ativo das equipes de lançamento. Resposta: o NC (ou nulo) e se a chave está ligada | Só leitura, sem expor a lista de NCs a quem não gerencia |
| D-NC-7 | CSV: `COALESCE(copia do lançamento, NC atual do cadastro)` na mesma consulta do export; a lista da tela não ganha o campo | O pedido: a cópia; nas linhas antigas, o NC atual |
| D-NC-8 | A fila de sincronização (SharePoint) não muda | Fora do pedido; o modelo novo do CSV é outro lote |
| D-NC-9 | Ícone: a opção B, escolhida pelo responsável | O pedido veio com o marcador `<A|B|C>` |
