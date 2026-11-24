# Lote NC e ícone novo (deploy 5)

**Estado:** pedido em 2026-11-23, depois do lote 8.8; plano e execução neste documento; **entregue em 2026-11-23** (seção 4); pedido revisto entregue (seção 6). **D-NC-1 a D-NC-9 e D-NC-10 a D-NC-18 aprovadas em 2026-11-24, como estão**; acertos de 2026-11-24 na seção 7. Entra no deploy 5. Sem push próprio: o push do `codex/modernizacao-ui-backend` vai junto da autorização do lote anterior, só com a bateria final verde (resposta do responsável em 2026-11-23).

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

## 3. Decisões de implementação (aprovadas em 2026-11-24, como estão)

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

## 4. Execução (2026-11-23)

**Commits** (branch `feat/employee-form-rhf-zod`):

| Commit | Conteúdo |
|---|---|
| `806a73c` | Migration 026 (rollback, verificação, linhas do ensaio); `qa-nc.php` seção 1 na bateria (`test-local.ps1` e `test-linux.sh`); deploy 5 com a 026 (a lista das 8 migrations, o "antes", os ensaios (a) e (b), a 026 logo depois da 022, a verificação de novo, o rollback no passo 16; 24 QAs); roteiro e folha regerados; a trava |
| `62738e7` | Este documento (plano e decisões) |
| `2116c4d` | Servidor: `services/EmployeeNc.php`; NC no criar, editar e listar funcionário (auditoria `FUNCIONARIO_NC_ALTERADO` só com o ID e o tipo); a cópia do NC no lançamento; `NC_OBRIGATORIO`; `?nc=1` nos dois endpoints; a coluna NC dos dois CSVs; `.env.example`; `qa-nc.php` seção 2 e os casos do NC no `qa-security` |
| `0bc74c3` | Telas: campo NC no cadastro, aviso e filtro "Sem NC" na lista; o NC só leitura, o aviso e o bloqueio nos formulários; `qa:employee-form`, `qa:operational` e o navegador |
| `4a33925` | Ícone B: `BrandMark.jsx`, `favicon.svg` (sem tema), `favicon-16.svg`, `favicon.ico` (16, 32 e 48), `favicon.png`, `apple-touch-icon.png`, `icon-512.png` (gerados dos SVG pelo Chrome headless), `?v=3` no `index.html` e nas 5 páginas PHP; `docs/design/marca/NOTA.md`; `qa:bundle`, `qa:shell` e o bloco da marca no navegador |
| `ee09eb5` | Roteiro e folha do deploy 5 com o código do NC e o ícone (a chave desligada), a fumaça com os dois CSVs (oito GET), os ícones `?v=3` logo depois da reabertura, as conferências na tela e o depois do deploy; a trava |
| O último | Esta seção e o `REGISTRO_USO_IA.md` |

**Ensaio da 026 no MySQL 8.0.46 local** (contêiner do WSL; base fictícia `qa_nc` = `qa_f103` mais 4 horas extras e 3 correções fictícias; `scripts/ensaio-migration.sh` do repositório, com `DADOS=portal_overtime_entries,portal_time_adjustments`). Só contagens e assinaturas:

| Variante | Resultado |
|---|---|
| (a) `nc_026` | migration 2x `OK` (`colunas_026 = 3`, `checks_026 = 3`, `indice_026 = 1`; 13 cadastros ativos, 0 com NC; 4 horas extras e 3 correções, 0 com cópia; problemas 0); rollback 1x `OK` (`copias_de_rollback_026 = 3`, "026 ausente"); a 2ª para de propósito (`rollback_026 abortado`); assinaturas do cadastro (14 linhas), das horas extras (4) e das correções (3) iguais nos cinco estados |
| (b) `nc_026_rollback` | linhas do ensaio 2x: 3 cadastros com NC fictício, 3 horas extras e 3 correções com a cópia, problemas 0 (iguais na 2ª); rollback 1x `OK` (3 cópias), a 2ª para; assinaturas iguais às do estado inicial |

A verificação mudou depois da 1ª rodada (a contagem `nc_repetido` passou a não listar o NC, por causa da conferência da mutação); as duas variantes rodaram de novo com o arquivo final, com o mesmo resultado.

**Integração no MySQL 8.0.46 local** (`qa_nci` = `qa_nc` + 026; dados e NC fictícios; transação desfeita): 16/16. As restrições recusam linha a linha (letra, vazio, espaço, 13 dígitos e as cópias fora do formato: `3819`/`1406`); NC repetido `1062`, vários sem NC; `EmployeeNc` grava, lê com zero à esquerda e confere a unicidade; o lançamento grava a cópia do cadastro (o `nc` e o `employee_nc` do corpo ignorados) e NULL sem NC; com `NC_OBRIGATORIO=1`, sem NC 409 e com NC grava; os dois CSVs com o NC da cópia ou, nas linhas antigas, o atual do cadastro; a leitura do formulário pela gestão; nada fica gravado.

**Mutação por commit** (no worktree, com a trava `mut_guard.php`):
- `806a73c`: 24/24. Na 1ª rodada, 22/24: a verificação podia exibir o NC (por exemplo, com `GROUP_CONCAT`) sem reprovar; o `qa-nc` passou a uma lista branca dos usos do NC na verificação (commit refeito antes de qualquer envio); e um mutante do roteiro não se aplicava na folha (erro do script).
- `2116c4d`: 29/29. Na 1ª rodada, 28/29: a leitura `?nc=1` sem sessão não era testada; casos novos no `qa-security` (sem sessão 401; o técnico lê o próprio NC), commit refeito antes de qualquer envio.
- `0bc74c3`: 21/21.
- `4a33925`: 12/12.
- `ee09eb5`: 15/15. Na 1ª rodada, 11/15: 4 mutantes não se aplicaram (erro do script: `\n` entre aspas simples e textos repetidos em outros deploys); reescritos com alvos únicos do deploy 5, 4/4.

**Navegador:** com as telas do NC, 1163/1163 (+1: o corpo do cadastro novo leva o NC). Na 1ª rodada, 1162/1163: um segundo roteiro de "Adicionar funcionário" não digitava o NC (obrigatório agora); corrigido antes do commit.

**Ícone:** contraste das barras da pausa contra a placa de 10,4:1 no tema claro e 6,4:1 no escuro (marca do topo); no favicon fixo, 10,5:1; as chaves, 17,2:1, 15,6:1 e 15,5:1.

**Trava do roteiro:** 519 verificações; os 48 comandos do deploy 5 passam no `bash -n`. `qa-nc`: 50 verificações.

**Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree do último commit): no resumo da entrega e no estado da sessão.

**Não verificado aqui:** o NC real dos cadastros (fica com a gestão, pela tela) e a chave `NC_OBRIGATORIO` ligada em produção (passo combinado depois, com `ativos_sem_nc = 0`).

## 5. Pedido revisto (2026-11-23, à tarde)

O responsável reenviou o pedido com três mudanças sobre o lote já entregue (`806a73c` a `f93f789`, enviado): o NC **sem zero à esquerda**; a **importação de NCs com prévia**; e a **exportação de hora extra no modelo do RH** (`.xlsx` no lugar do CSV de horas extras). O CSV da correção de ponto continua, com o NC. O ícone veio de novo com o marcador `<A|B|C>`: fica a opção B, já escolhida e entregue (`4a33925`). Nada do que foi enviado é reescrito: as correções vão em commits novos.

**Plano curto:**

| Commit | Conteúdo |
|---|---|
| 1 | NC sem zero à esquerda: as três CHECK da 026 e a verificação (`^[1-9][0-9]{0,11}$`), o `EmployeeNc` e o schema da tela; ensaio e integração de novo |
| 2 | Este plano |
| 3 | Importação de NCs com prévia (servidor, tela e QA) |
| 4 | Exportação de hora extra no modelo do RH: o `XlsxWriter` com formato de número, fórmula e fonte; o centro de custo configurável; a prévia na tela; o QA que abre o `.xlsx` |
| 5 | Roteiro, folha e trava do deploy 5 (a fumaça passa a ler a prévia da exportação) |
| 6 | Docs: execução, validação e registro |

**Decisões de implementação (aprovadas em 2026-11-24, como estão):**

| # | Decisão | Por quê |
|---|---|---|
| D-NC-10 | NC só com dígitos, de 1 a 12, **sem zero à esquerda**, na 026 (CHECK), no servidor e na tela. Revê a D-NC-1 ("texto guarda zero à esquerda") | Pedido revisto: o NC sai como número na planilha do RH |
| D-NC-11 | Importação: `.xlsx` ou `.csv` (até 2 MB e 5000 linhas, o leitor de planilha do portal); a 1ª linha com as colunas "NC" e "Nome completo" (sem diferenciar acento, caixa e espaço) é o cabeçalho; o modelo do RH serve (várias linhas da mesma pessoa com o mesmo NC contam uma vez). Casa pelo nome normalizado (sem acento, caixa e espaços repetidos) com os cadastros **ativos**; nome de mais de um cadastro ativo não casa | O nome é a única chave comum com o arquivo do RH; arquivado não recebe NC por importação |
| D-NC-12 | A prévia separa: entra (cadastro sem NC), muda (cadastro com outro NC), igual (nada a fazer), não casou (nome sem cadastro ativo ou com mais de um), NC inválido e NC repetido (o mesmo NC para nomes diferentes no arquivo, ou já de outro cadastro que não muda). Confirmar reenvia o arquivo; o servidor refaz a prévia e grava só "entra" e "muda", numa transação, com os cadastros travados. Rodar de novo o mesmo arquivo dá tudo "igual" | Nada gravado sem confirmar; idempotente; o que está em conflito nunca é gravado |
| D-NC-13 | Auditoria da importação `FUNCIONARIO_NC_IMPORTADO` (WARNING) com as contagens e os IDs incluídos e alterados, nunca o NC | LGPD |
| D-NC-14 | Exportação do RH: só `approved` (`pending`, `rejected`, `synced` e `sync_error` ficam fora, como pedido); o período e os filtros da tela (equipe e colaborador); o técnico só o próprio; o NC da cópia ou, nas linhas antigas, o do cadastro. Lançamento aprovado sem NC: a exportação é recusada (409 com a contagem); a prévia lista quem falta, pelo nome, só na tela | Pedido; `synced` fora a confirmar com o RH |
| D-NC-15 | A prévia (`GET ...?export=preview`) mostra quantos aprovados vão, quantos pendentes e outros ficaram fora, o total geral, quem está sem NC e o nome do arquivo; a auditoria `OVERTIME_EXPORTED` leva o formato e as contagens | Pedido |
| D-NC-16 | O centro de custo fica no `config_sistema.json` (`centro_custo_horas_extras`, padrão `1.123.01  Suporte Tecnico`, com os dois espaços), editado pelo admin na aba Configurações (1 a 60 caracteres, sem caractere de controle); a auditoria da configuração leva o valor (não é dado pessoal) | Mesmo lugar das configurações de pausa |
| D-NC-17 | O `XlsxWriter` ganha formato de número, fórmula, fonte (nome e tamanho) e a borda preta, só quando usados: a saída do Controle de CHG não muda | Uma dependência a menos; o QA confere a saída do CHG |
| D-NC-18 | O nome em maiúsculas por uma tabela própria das letras acentuadas do português (sem o `mbstring`) | Pedido |

## 6. Execução do pedido revisto (2026-11-23)

**Commits** (branch `feat/employee-form-rhf-zod`, depois do `f93f789` enviado; nada enviado foi reescrito):

| Commit | Conteúdo |
|---|---|
| `47e8b2d` | NC sem zero à esquerda: as três CHECK da 026, a verificação, o `EmployeeNc` e o schema da tela (D-NC-10) |
| `8d66b45` | O plano da seção 5 |
| `1b8f39f` | Importação de NCs com prévia: `SpreadsheetImportService::parseUploadMatrix`, `services/EmployeeNcImport.php`, `api/portal/employee_nc_import.php` (Liderança e admin, CSRF), `NcImport.jsx`; `qa-nc` seção 3, `qa-security` e `qa:employee-form` (D-NC-11 a D-NC-13) |
| `c5998bb` | Planilha de horas extras do RH: `services/OvertimeRhSheet.php`, a prévia (`?export=preview`) e o `.xlsx` (`?format=xlsx`) no `overtime.php` (o `?format=csv` de horas extras dá 400), o centro de custo em Configurações, a prévia na tela (`overtimeRh.ts`); o `XlsxWriter` com formato de número, fórmula, fonte, borda preta e centro vertical; `qa-overtime` abre o `.xlsx` gerado (D-NC-14 a D-NC-18) |
| `c074112` | Roteiro, folha e fumaça do deploy 5: a fumaça lê a prévia da planilha (a planilha dá 409 enquanto houver aprovado sem NC); as conferências da importação, da planilha e do centro de custo sem enviar nem salvar; o depois do deploy; a trava |
| O último | Esta seção e o `REGISTRO_USO_IA.md` |

**Ensaio da 026 com a regra nova** (MySQL 8.0.46 local, base fictícia `qa_nc`, as duas variantes): o mesmo resultado da seção 4 (migration 2x, rollback 1x e a 2ª parando, assinaturas iguais), agora com o NC fictício sem zero à esquerda.

**Integração no MySQL 8.0.46 local** (`qa_nci`; dados e NC fictícios; transação desfeita): 21/21. Além dos 16 da seção 4: o NC com zero à esquerda recusado pelas CHECK; a importação grava só "entra" e "muda", e a 2ª vez com o mesmo arquivo não grava nada; a prévia da planilha do RH confere com as contagens do banco (aprovados, pendentes fora, outros, minutos); a planilha tem uma linha por aprovado e o total até a última linha; aprovado sem NC recusa a planilha (409).

**O `.xlsx` no QA:** o `qa-overtime` abre o arquivo gerado (o ZIP sem compressão do `XlsxWriter`) e confere a aba única "Plan1", os seis cabeçalhos na ordem e com acento, H1 e a fórmula `SUM(E2:E<última>)` em `[h]:mm:ss`, o recálculo ao abrir, o estilo do cabeçalho (Aptos Narrow 11, negrito, fundo `00B0F0`, centralizado, borda fina preta), a ordem por data e nome, o nome em maiúsculas com acento, o centro de custo com os dois espaços, o NC como número, a data como número de data do Excel em `dd/mm/yyyy`, o total como fração do dia em `[h]:mm:ss`, a descrição com a justificativa quando o motivo é vazio (texto, nunca fórmula), o autofiltro `A1:F<última>`, as larguras, os formatos próprios a partir do id 164, que só os aprovados entram e que a saída do Controle de CHG continua igual byte a byte. O modelo do RH (com dados reais) só serviu para conferir o layout: nada dele está no git, nos docs ou nos QAs.

**Mutação por commit** (no worktree, com a trava `mut_guard.php`):
- `47e8b2d`: 7/7.
- `1b8f39f`: 20/20. Na 1ª rodada, 17/20: o cabeçalho depois de linhas de título não era testado; um mutante não mudava nada (o grupo "igual" não existe em `todos`) e foi trocado por um que leva o "igual" para a gravação; a ordem da checagem de permissão do endpoint passava com `strpos` falso. Casos novos; commit refeito antes de qualquer envio.
- `c5998bb`: 29/29. Na 1ª rodada, 28/29: o id do formato próprio abaixo de 164 (que o Excel lê como formato embutido) não era conferido; caso novo, commit refeito antes de qualquer envio.
- `c074112`: 18/18. Um mutante não se aplicava na 1ª rodada (erro do script: fim de linha CRLF); corrigido, morto (pela paridade da folha).

**Navegador:** 1163/1163. **Trava do roteiro:** 520 verificações; o pipeline que regera o roteiro e a folha do deploy 5 reproduz os arquivos do commit byte a byte. `qa-nc`: 63 verificações.

**Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree do último commit): no resumo da entrega e no estado da sessão.

**Fica para decidir:** ~~o `streamOvertimeCsv` e o `overtimeCsvRow` sem chamador~~ (retirados em 2026-11-24, seção 7). O status `synced` fora da planilha (D-NC-14) a confirmar com o RH.

**Não verificado aqui:** a planilha aberta no Excel do RH (o QA confere o XML; o layout foi comparado com o modelo pela estrutura) e o NC real dos cadastros (fica com a gestão, pela tela ou pela importação).

## 7. Acertos do lote NC (2026-11-24)

Pedido do responsável em 2026-11-24, antes do lote do CHG: dois acertos pequenos, cada um num commit, e o roteiro do deploy 5; a mesma bateria no fim. Na mesma resposta, D-F10-42 a D-F10-47, D-NC-1 a D-NC-9 e D-NC-10 a D-NC-18 aprovadas como estão, e autorizada a remoção dos worktrees `wt-mut`, `chronodesk-88-c1` e `chronodesk-88-c3`.

| Commit | Conteúdo |
|---|---|
| `2cd4f7d` | Cabeçalho da coluna C da planilha do RH igual ao modelo, **"Nome completo " com o espaço no fim** (o pedido de 2026-11-23 trazia sem o espaço; o modelo é a referência). `OvertimeRhSheet::HEADERS`; o `qa-overtime` confere o texto exato, a célula C1 do `.xlsx` gerado e o `xml:space="preserve"` que faz o Excel guardar o espaço. A importação de NCs já aceitava o cabeçalho com o espaço (compara sem acento, caixa e espaço) |
| `d74a9be` | Removidos o `streamOvertimeCsv`, o `OVERTIME_CSV_HEADER`, o `overtimeCsvRow` e o `minutesText`, que só existiam para eles. O `?format=csv` do `overtime.php` continua dando 400 com a mensagem. Nos QAs: saem os casos do CSV de horas extras (`qa-overtime` e `qa-nc`); o rótulo do status, que segue no CSV de correção de ponto, passa a ser testado direto; a conferência estrutural "percorre o filtro inteiro, sem a lista da tela" passa para a planilha do RH; o `qa-security` conta 8 `fputcsv` |
| `3f890ba` | Roteiro e folha do deploy 5, "Depois do deploy": **primeiro**, logo depois da reabertura (passo 11) e das conferências do passo 12, que não salvam nada, e antes de qualquer exportação de horas extras, importar os NCs em Funcionários com a planilha de horas extras do RH, **a prévia primeiro**; depois, a verificação da 026 de novo (a mesma dos passos 7 e 10, só leitura) conta `ativos_sem_nc` e confere `nc_repetido = 0`. Na folha, o item 49 com o comando. A trava confere o item no começo do "Depois do deploy" e a terceira verificação da 026 (522 conferências) |
| O último | Esta seção, as aprovações e o `REGISTRO_USO_IA.md` |

**Mutação** (worktree `chronodesk-nc-acertos`, trava `mut_guard.php`): `2cd4f7d` 32/32 (os 29 do commit 4 do pedido revisto, com o texto do cabeçalho ajustado, e três novos: sem o espaço, com dois espaços e a célula sem `xml:space="preserve"`); `d74a9be` 9/9 (o cabeçalho e o método do CSV de volta, a planilha pela lista da tela, o `?format=csv` sem o 400, três rótulos de status, o CSV de correção sem o NC e um `fputcsv` a mais); `3f890ba` 11/11 (no roteiro e na folha: depois da exportação, sem o "Primeiro", sem a prévia primeiro, sem conferir o `nc_repetido`, o comando sem o `PARE`, a planilha no chat, o item sem "PARE se" e o comando fora da folha).

**Decisão pequena, a confirmar:** "logo depois da reabertura" ficou depois das conferências do passo 12, que não salvam nada. Com a importação antes delas, o aviso "N cadastro(s) ativo(s) sem NC" do passo 12 deixaria de mostrar todos os ativos, como a folha espera.

**Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree do último commit): no resumo da entrega e no estado da sessão.
