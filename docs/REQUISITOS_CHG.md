# Requisitos — Módulo de CHGs (mudanças) no ChronoDesk

> **Situação:** requisito e plano, **sem código**. Desenho em `docs/DESENHO_CHG.md`. Parar para revisão do responsável antes de qualquer implementação.
> **Data:** 2026-10-28. **Base analisada:** `de93913` no branch `feat/employee-form-rhf-zod`.
> **Modelo de referência:** `_referencias/controle_atualizacoes_modelo.html` (1.296 linhas, lido inteiro). **Fora do git** (`.gitignore:45`, commit `de93913`; `git status` conferido com o arquivo no lugar). O modelo traz dados reais. Por isso este documento usa só **marcadores**: `[CARTEIRA_COD]`, `[CARTEIRA_NOME]`, `[LOCAL_n]`, `[TECNICO_n]`, `[SOLICITANTE]`, `[SISTEMA]` (o sistema que as atualizações instalam), `[IP_INTERNO]`, `[URL_CONSOLE_ENDPOINT]` e `[ID_ENDPOINT]`.

Convenções:
- **Fato** = lido no código, com `arquivo:linha` (do modelo ou do ChronoDesk).
- **A DEFINIR** = depende de decisão do responsável ou do solicitante. Para cada um, a seção 4 diz o que muda conforme a resposta.
- **Proposta** = posição da IA, a aprovar no desenho.
- Nenhum dado real (carteira, nome, IP, link ou ID) aparece aqui.

---

## 0. Resumo

O modelo é uma página HTML única. Ela controla atualizações de `[SISTEMA]` por carteira, do pedido até a execução pelo Service Desk, com o número da CHG no meio. A **etapa** de cada card é calculada no navegador, a partir do número e do status da CHG, da conclusão, do cancelamento e do relógio (preparação 4 h antes do início). Os dados ficam no `localStorage` do navegador ou num armazenamento compartilhado do ambiente onde a página é publicada.

Levar isso para o ChronoDesk significa, em resumo:
- banco MySQL, API PHP e tela React no lugar do `localStorage`;
- etapa calculada no servidor, com `server_now`;
- permissão por perfil, CSRF e auditoria em cada mudança;
- cancelar ou arquivar no lugar de excluir;
- técnico ligado a `funcionarios.id`;
- carteiras numa tabela carregada no servidor, fora do git;
- links só `https`, validados no servidor;
- exportação em CSV no padrão atual, sem CDN;
- interruptor `CHG_MODULE_ENABLED`.

**Achados do modelo** que pedem decisão ou correção na migração (detalhe em 1.12):
1. a etapa depende do relógio de quem abre a página;
2. a exclusão é física, "para todos";
3. o modelo pode gravar fora da infraestrutura da empresa;
4. dá para cancelar uma atualização já concluída;
5. "Duplicar" copia os planos da CHG;
6. o link aceita `http` e transforma texto solto em link.

---

## 1. Inventário do modelo

### 1.1 Estrutura e persistência

| Item | Fato |
|---|---|
| Página única, CSS e JS embutidos; três visões: Quadro, Lista e Dashboard | `_referencias/controle_atualizacoes_modelo.html:274-278` |
| Gravação no `localStorage`, chave `controle_atualizacoes_v1`; sem dados, grava 3 cards de exemplo (`SEED`) | `:401`, `:471-478` |
| Modo compartilhado: se o ambiente de publicação oferece um banco (`window.claude.use("db")`), lê e grava a coleção `atualizacoes` desse ambiente, com atualização ao vivo (`onSnapshot`) | `:508-521` |
| Somente leitura: decidido no navegador, pela permissão do ambiente (`user.can("data.write")`) ou por erro ao gravar | `:492-497`, `:522-525` |
| Exclusão física: remove do array e apaga o documento ("será removida do quadro para todos") | `:502-506`, `:1064`, `:1069` |
| A tela redesenha a cada 60 s (a etapa muda com o relógio) | `:1292` |
| Listas fixas no código: **168** carteiras (código e nome), **5** técnicos, **8** locais (`[LOCAL_1]` a `[LOCAL_8]`) | `:413`, `:417`, `:418` |
| Os 3 cards de exemplo trazem solicitante, caminho de rede com `[IP_INTERNO]` e um link de `[URL_CONSOLE_ENDPOINT]` com `[ID_ENDPOINT]` | `:471` |

### 1.2 Campos

| Campo (modelo) | Tipo e regra no modelo | Fato |
|---|---|---|
| `solicitante` | Texto livre, **obrigatório**; sugestões dos valores já usados | `:331`, `:560`, `:988` |
| `codigo` | Texto, **obrigatório**; ao digitar, procura na lista de carteiras e preenche o nome; fora da lista, avisa "Código fora da planilha base. Preencha o nome." | `:332`, `:1173-1178` |
| `nomeCarteira` | Texto, **obrigatório**; escolhido o nome, preenche o código se estiver vazio | `:333`, `:1179-1184` |
| `carteira` | Derivado: `"<código> - <nome>"` | `:998` |
| `local` | Lista: os 8 locais fixos e os já usados; **obrigatório** | `:334`, `:972` |
| `qtdPas` | Número, **obrigatório e maior que 0** (o campo aceita 0, a gravação recusa) | `:335`, `:989` |
| `tipo` | Texto com sugestões "Padrão" e "Emergencial"; padrão "Padrão"; aceita qualquer texto | `:336`, `:393`, `:970` |
| `versao` | Texto livre (versão e caminho de rede, exemplo com `[IP_INTERNO]`) | `:337` |
| `descricao` | Texto livre, até 300 caracteres | `:338` |
| `chamado` | Texto livre (chamado de homologação) | `:339` |
| `link` | Texto livre (planilha com PAs); vira link se parecer endereço (1.10) | `:340`, `:583-589` |
| `sugestao` | Data e hora, **obrigatória**; preenche o início (se vazio ou automático) e o fim 24 h depois | `:343`, `:1164-1169`, `:1160-1163` |
| `status` | "Aguardando retorno", "Aprovado" ou "Cancelado"; padrão "Aguardando retorno" | `:344-345`, `:974` |
| `inicio`, `fim` | Início e fim do Service Desk (data e hora); o fim não pode ser antes do início (igual passa) | `:346-347`, `:995-996` |
| `chg` | Texto, em maiúsculas; exemplo `CHG0012345` | `:350`, `:415`, `:1198-1200` |
| `chgStatus` | "" (não solicitada), "Pendente" ou "Aprovada"; com número e sem status, vira "Pendente"; sem número, fica "" | `:351-352`, `:1002-1003` |
| `tecnico` | Texto livre com sugestões (5 fixos e os já usados) | `:353`, `:561` |
| `planoImplantacao`, `planoBackup`, `planoRollback` | Texto, em maiúsculas ("Nº do plano") | `:355-357`, `:415-416` |
| `endpoints[]` | Lista de `{url, hora}`: link do endpoint e início do patch; pelo menos uma linha na tela | `:458`, `:600-615` |
| `concluida`, `concluidaEm` | Marcados ao concluir | `:1023` |
| `id`, `createdAt`, `updatedAt` | Gerados no navegador | `:433`, `:487`, `:1000` |

### 1.3 Etapas calculadas (`stageOf`)

`stageOf` (`:440-450`) decide a etapa nesta ordem; a primeira regra que vale ganha:

| # | Condição | Etapa (coluna) |
|---|---|---|
| 1 | `status = "Cancelado"` | Canceladas (`cancel`) |
| 2 | `concluida` | Concluídas (`done`) |
| 3 | sem número de CHG | Aguardando retorno (`triagem`) |
| 4 | CHG não aprovada | Aguardando Comitê (`comite`) |
| 5 | CHG aprovada e sem início | Em preparação (`prep`), "Sem início definido" |
| 6 | agora ≥ início | Em execução (`exec`) |
| 7 | agora ≥ início − 4 h | Em preparação (`prep`) |
| 8 | senão | Aguardando Comitê (`comite`), com o selo verde "CHG aprovada" e a nota "Entra em preparação em … (faltam …)" (`waitingWindow`) |

- **Preparação 4 h antes:** `PREP_HOURS = 4` e `prepAt = início − 4 h` (`:403`, `:439`).
- **Atraso na execução:** `isLate` = etapa "Em execução" **e** fim < agora (`:461`). O card ganha borda vermelha e o selo "Janela encerrada" (`:107`, `:700`). Ele **não sai sozinho** da execução: continua lá até alguém concluir.
- **Barra de SLA** (`:647-668`): percentual do tempo entre início e fim, "restam …" ou "encerrado há …", e uma marca por patch. Some quando falta início ou fim, ou quando fim ≤ início.
- **"agora" é o relógio do navegador** (`new Date()` em `:445`, `:453`, `:461`, `:650`).

### 1.4 Regras de movimento (`moveTo`)

`moveTo(card, etapa)` (`:1010-1026`) é chamado ao arrastar para uma coluna (`:1130-1135`) e pelos botões:

| Destino | Regra | Efeito |
|---|---|---|
| A mesma etapa | — | Nada |
| Aguardando retorno | **Recusa:** "Para voltar a Aguardando retorno, apague o número da CHG no card." | — |
| Aguardando Comitê | Exige número de CHG | CHG volta para "Pendente", desfaz a conclusão; cancelado volta para "Aguardando retorno" |
| Em preparação **ou** Em execução | Exige número de CHG | CHG vira "Aprovada", desfaz a conclusão; cancelado volta para "Aprovado". **A etapa final continua sendo a do relógio:** soltar em "Em execução" antes do início só aprova a CHG |
| Concluídas | Só a partir de "Em execução"; exige técnico | Marca concluída e a hora |
| Canceladas | Nenhuma (vale de qualquer etapa, **inclusive Concluídas**) | Status "Cancelado" |

Outros caminhos que mudam a etapa:
- **"Voltar para Comitê"**, em Em preparação, é `moveTo(comite)` e desaprova a CHG (`:698`, `:1066`).
- **"Reabrir execução"**, em Concluídas, desfaz a conclusão sem outra checagem (`:706`, `:1067`).
- **Edição no card:** apagar o número da CHG zera o status e volta para "Aguardando retorno"; preencher põe "Pendente" (`:1110`). O status da solicitação pode ser trocado no card cancelado, o que descancela (`:709`).
- **O formulário** grava qualquer combinação válida (1.6), em qualquer etapa.
- **Arrastar** fica desligado em somente leitura e quando o arrasto começa num campo (`:1123-1124`).

### 1.5 Edição no card, por etapa

| Etapa | O card permite editar | Fato |
|---|---|---|
| Aguardando retorno | Número da CHG; status | `:675-679` |
| Aguardando Comitê | Número e status da CHG; os três planos; status; nota de espera da janela | `:680-692` |
| Em preparação | Planos; endpoints e início do patch; técnico; "Voltar para Comitê" | `:693-698` |
| Em execução | Planos; endpoints; técnico; "Concluir execução"; barra de SLA | `:699-703` |
| Concluídas | Só leitura dos planos; "Reabrir execução" | `:704-706` |
| Canceladas | Status | `:707-709` |

Todo card mostra:
- carteira, descrição (3 linhas), local, PAs, tipo e a janela do Service Desk;
- versão e planilha com botão de copiar (a planilha também tem botão de abrir);
- solicitante, e Copiar tabela, Duplicar, Editar e Excluir (`:711-733`, `:572-579`).

**Endpoints** (`:600-615`, `:1053-1063`, `:1071-1088`):
- adicionar e remover linhas (sempre fica pelo menos uma);
- o link é gravado ao sair do campo;
- o início do patch usa a máscara `dd/mm/aaaa hh:mm`, com data conferida de verdade (`:633-638`), e é gravado ao sair do campo;
- formato antigo "HH:MM": a hora é contada a partir do início e passa para o dia seguinte se cair antes dele (`:622-631`);
- cada patch tem uma cor (6, em ciclo; `:457`).

### 1.6 Validações do formulário (`saveForm`, `:984-1007`)

1. **Obrigatórios:** solicitante, código, nome da carteira, local, quantidade de PAs (> 0) e data sugestão. Com um só faltando: "Informe …"; com vários: "Preencha os campos obrigatórios: …" (`:988-994`).
2. Fim antes do início: "O fim precisa ser depois do início." (`:996`). Fim igual ao início passa.
3. CHG "Aprovada" sem número: "Informe o número da CHG antes de marcá-la como aprovada." (`:997`).
4. Número da CHG e planos em maiúsculas (`:986`).
5. Com número e sem status: "Pendente"; sem número: status vazio (`:1002-1003`).
6. Não há validação de: formato do número da CHG, tamanho dos campos (fora a descrição), link, data no passado, local fora da lista, código fora da base (só o aviso) e endpoint fora da janela.

### 1.7 KPIs (`renderKPIs`, `:736-758`)

Calculados sobre a **lista filtrada**:

| KPI | Número | Linha de baixo |
|---|---|---|
| Em andamento | Etapa diferente de Concluídas e Canceladas | "Solicitações abertas" |
| Aguardando retorno | Etapa `triagem` | "Sem número de CHG" |
| Aguardando Comitê | Etapa `comite` | "N aprovadas aguardando janela" |
| Em preparação | Etapa `prep` | "N sem endpoint" |
| Em execução | Etapa `exec` | "N sem técnico" |
| Janelas hoje | Não canceladas cuja janela [início, fim] toca o dia de hoje (do navegador) | "Janela ativa hoje, N PAs" |

### 1.8 Filtros (`:297-312`, `:533-552`, `:1137-1144`)

| Filtro | Regra |
|---|---|
| Busca | Contém, sem diferença de maiúsculas, em solicitante, carteira, local, versão, CHG, técnico, chamado e tipo (**não** busca na descrição) |
| Solicitante, Local, Tipo | Igualdade; as opções vêm dos valores existentes (o local também da lista fixa) |
| CHG | Todas, Sem CHG, Pendente, Aprovada |
| Janela de / até | Pelo início, ou pela sugestão quando não há início; dia inteiro |
| Mostrar concluídas e canceladas | Marcado por padrão; desmarcado, some com as duas colunas do quadro e as linhas da lista |
| Limpar filtros | Volta ao padrão |

### 1.9 Quadro, lista e dashboard

- **Quadro** (`:768-777`): uma coluna por etapa, com título, contador, dica e texto de coluna vazia. A ordem dentro da coluna é a do `sortByWindow` (`:760-766`): início (ou sugestão), depois fim, depois data de criação.
- **Lista** (`:779-795`): 12 colunas na mesma ordem: etapa, solicitante, carteira, local, PAs, versão, início, fim, status, CHG (com o status), técnico e ações. Sem resultado: "Nenhuma atualização encontrada com esses filtros."
- **Dashboard por técnico** (`:797-845`). Considera as etapas Aguardando retorno, Comitê, Preparação e Execução, e Concluídas se o filtro mostrar fechadas; nunca as canceladas. Agrupa pelo **texto** do técnico ("Sem técnico atribuído" por último) e mostra:
  - visão geral, uma barra empilhada por etapa;
  - CHGs por técnico, uma barra por técnico;
  - "Sem técnico atribuído": as 8 mais próximas, fora as concluídas, que abrem a edição;
  - tabela de detalhe: contagem por etapa, total, PAs das abertas e próxima janela.

### 1.10 Links

`linkHref` (`:583-589`) vira link quando o texto:
- começa com `http://` ou `https://`;
- começa com `www.`, e aí ganha `https://` na frente;
- parece um domínio e não termina em extensão de arquivo.

Abre em nova aba com `rel="noopener"`. Vale para a planilha com PAs (`:597`) e para os endpoints (`:607`).

### 1.11 Copiar tabela, exportação, duplicar e excluir

- **Copiar tabela** (`:876-960`): monta a tabela do card com estes campos e o valor de cada um, em HTML com estilo e em texto separado por tabulação:
  - solicitante, descrição, carteira, local, PAs, versão, chamado, planilha (link), tipo e data sugestão;
  - início e fim, em negrito;
  - status e status da CHG, com cor;
  - etapa, CHG, técnico e os três planos;
  - "Concluída em", se concluída;
  - uma seção por endpoint, com o link e o início do patch.

  A cópia tenta, nesta ordem: o evento de cópia com `execCommand`, a API de área de transferência, e uma caixa de pré-visualização com a tabela já selecionada (montada por `innerHTML`, `:956`).
- **Exportação** (`:1204-1289`):
  - botão "Exportar Excel";
  - carrega uma biblioteca de planilha de **CDN público**, com uma segunda CDN de reserva, **sem verificação de integridade** (`:1206-1214`);
  - gera `.xlsx` com duas abas: "Cards" (um bloco vertical por card, com cores; `:1215-1247`) e "Base" (uma linha por card, colunas dinâmicas por endpoint, filtro automático e hiperlinks; `:1248-1268`);
  - exporta a lista filtrada, na ordem da janela;
  - nome do arquivo `Controle_Atualizacoes_AAAA-MM-DD.xlsx` (`:1278`).
- **Duplicar** (`:1052`): abre o formulário com cópia do card **sem** CHG, status da CHG, técnico e endpoints, com status "Aguardando retorno" e não concluída. **Mantém** os planos, a janela, a sugestão e o `concluidaEm`.
- **Excluir** (`:1064`, `:1069`): confirmação ("… será removida do quadro para todos.") e remoção física.

### 1.12 Achados do modelo (a corrigir ou decidir na migração)

| # | Achado | Fato | Tratamento proposto |
|---|---|---|---|
| M1 | Etapa e KPIs pelo relógio de quem abre a página; "hoje" é o dia do navegador | `:445`, `:740` | Etapa, atraso e KPIs no servidor, com `server_now` (RF-CHG-02) |
| M2 | Exclusão física, sem registro | `:502-506` | Cancelar e arquivar, sem exclusão (RF-CHG-13) |
| M3 | Os dados podem estar no armazenamento do ambiente onde a página é publicada, fora da infraestrutura da empresa | `:510-520` | MySQL do ChronoDesk; a carga inicial é A DEFINIR (4.6) |
| M4 | Cancelar vale até para concluída | `:1024` | Cancelar só antes da conclusão (proposta C5 do desenho) |
| M5 | Duplicar mantém os planos da CHG e o `concluidaEm` | `:1052` | Duplicar sem os planos e sem dados de conclusão (C9) |
| M6 | O link aceita `http` e transforma texto solto em `https://` | `:583-589` | Só `https://` absoluto, validado no servidor (RF-CHG-09) |
| M7 | Biblioteca de CDN sem verificação de integridade | `:1211-1212` | CSV gerado no servidor, sem CDN (RF-CHG-11) |
| M8 | Somente leitura só no navegador | `:492`, `:524` | Permissão no servidor, em cada endpoint e em cada CHG |
| M9 | Técnico, solicitante e local são texto livre; carteiras e técnicos ficam no código | `:413-418` | Técnico = `funcionarios.id`; carteiras e locais em tabela no servidor (RF-CHG-15) |
| M10 | Fim igual ao início passa na gravação, mas a barra de SLA some | `:996`, `:649` | Fim estritamente depois do início (C3) |
| M11 | "Status" da solicitação ("Aprovado") e status da CHG ("Aprovada") convivem; só "Cancelado" muda a etapa | `:441`, `:345` | A DEFINIR, sem prioridade: manter como informativo ou retirar (C7) |
| M12 | `data-id` entra sem escape no HTML montado por `innerHTML` (o id é gerado pelo navegador ou vem do armazenamento compartilhado) | `:574-577` | React escapa tudo; sem `innerHTML` (RF-CHG-17) |
| M13 | Soltar em "Em execução" antes do início só aprova a CHG; a coluna escolhida não é a final | `:1017-1019` | Ações explícitas, com o resultado da etapa devolvido pelo servidor (C4) |

---

## 2. Mapeamento para o ChronoDesk (requisitos)

### RF-CHG-01 — Cadastro
- Campos de 1.2, gravados no MySQL pela API (detalhe no desenho, seção 2).
- Formulário com React Hook Form e Zod (padrão de `frontend/src/lib/formSchemas.ts`). Mantém o preenchimento automático do modelo: código → nome, nome → código, sugestão → início, início → fim + 24 h.
- O servidor valida tudo de novo (1.6, mais o formato, o tamanho e o link). A tela nunca é a única barreira.

### RF-CHG-02 — Etapa calculada no servidor
- A regra de 1.3 vira uma função pura no PHP, que recebe o registro e o instante (`ChgStagePolicy`, desenho 4).
- Cada resposta traz `server_now`, como `dashboard.php` (`api/portal/dashboard.php:53`, `DATE_ATOM`) e `GerenciadorPausas` (`classes/GerenciadorPausas.php:357`).
- O frontend **não calcula etapa** nem usa o relógio do navegador para isso. Os textos "faltam …" e "restam …" saem da diferença entre `server_now` e as datas do registro.
- A lista é atualizada a cada 60 s com a tela aberta e visível, o mesmo intervalo do modelo (`:1292`), pelo `useResource` (`frontend/src/hooks/useResource.ts:7`, `:24`). Fato de contexto: o PHP-FPM tem `pm.max_children=5` (`docs/AUDITORIA_2026-09.md:108`). O intervalo entra na medição do roteiro.
- Datas guardadas em `America/Sao_Paulo`, a regra do A4 do projeto (`config.php:53`; `docs/FUSO_HORARIO_BIZ02.md:317`), não em UTC. Na API saem em ISO 8601 com deslocamento.

### RF-CHG-03 — Movimentação
- Cada movimento de 1.4 vira uma **ação explícita** no servidor (`approve`, `back_to_committee`, `finish`, `reopen`, `cancel`, …).
- Cada ação confere permissão, estado atual e versão do registro. Devolve o registro com a etapa recalculada e grava auditoria.
- Arrastar e soltar é A DEFINIR (proposta C4: botões primeiro).

### RF-CHG-04 — Quadro
Seis colunas na ordem do modelo, com contadores, dicas e textos de vazio. Abaixo de 1280 px, uma coluna por vez ou lista por etapa, conforme a lição do 17e (desenho 6).

### RF-CHG-05 — Lista
As 12 colunas de 1.9, com cartões abaixo de 1280 px (como no 17 e no 17d).

### RF-CHG-06 — Dashboard por técnico
- Agrupa por `tecnico_id`, não por texto, e mostra o nome do cadastro.
- Mesmas contagens, PAs abertas, próxima janela e "Sem técnico atribuído" (as 8 mais próximas).
- Calculado a partir da lista que o servidor devolveu, com a etapa já pronta.

### RF-CHG-07 — KPIs
Os seis de 1.7, **calculados no servidor** sobre o mesmo filtro. "Janelas hoje" usa o dia de `server_now`.

### RF-CHG-08 — Filtros
- Os de 1.8, aplicados no servidor (parâmetros GET validados por lista branca).
- A busca também procura na descrição (proposta).
- O filtro "fechadas" mantém o padrão marcado.
- Arquivadas ficam fora por padrão (RF-CHG-13).

### RF-CHG-09 — Links só `https`
- Planilha com PAs e endpoints só com URL `https://` absoluta, validada no servidor. Mesmo padrão de `services/CriticalIncidentService.php:981-992` (`FILTER_VALIDATE_URL` e esquema `https`), mais estas recusas: usuário e senha na URL, espaço, controle e mais de 1.000 caracteres.
- Valores como "N/A" ou "Aguardando" deixam de ser aceitos no campo de link: o campo fica vazio.
- Lista branca de domínios é A DEFINIR (proposta C10).
- Na tela: `<a target="_blank" rel="noopener noreferrer">` só com o valor que o servidor aceitou.

### RF-CHG-10 — Endpoints e início do patch
Lista por CHG, com link `https` e início do patch (data e hora completas; o formato antigo "HH:MM" não é aceito). Limite de linhas na proposta C8. As cores e as marcas na barra de SLA ficam como no modelo.

### RF-CHG-11 — Exportação
- CSV gerado no servidor, no padrão atual: BOM UTF-8, `;`, `csv_neutralize_cell` em toda célula, `Cache-Control: no-store`, auditoria com a contagem (`api/portal/critical_incidents_export.php:11-46`; `security.php:103-110`).
- Download por link montado com `apiUrl` (`frontend/src/lib/api.ts:40`), como em `frontend/src/pages/CriticalIncidentsPage.jsx:437`, `:492`.
- **Sem CDN.** As colunas são as da aba "Base" do modelo. A aba "Cards" não tem equivalente em CSV: a cópia da tabela cobre a visão por card.
- Excel no lugar do CSV é A DEFINIR (4.4).

### RF-CHG-12 — Duplicar
Abre o formulário com a cópia, sem CHG, status da CHG, técnico, endpoints, **planos** e dados de conclusão (corrige M5). A gravação é um `create` comum, auditado.

### RF-CHG-13 — Cancelar e arquivar, sem excluir
- Cancelar é lógico: data e autor; motivo por lista fechada é A DEFINIR (C6). Pode ser desfeito com auditoria.
- Arquivar tira da lista padrão as concluídas e canceladas antigas. Só concluída ou cancelada pode ser arquivada; restaurar é só para o admin (proposta).
- Nenhum endpoint apaga linha.

### RF-CHG-14 — Interruptor `CHG_MODULE_ENABLED`
- Mesmo mecanismo do `STATUS_PRESENCE_ENABLED` (`services/PresenceService.php:32-45`; `scripts/preflight-deploy.sh:127-130`; `.env.example:52`), com uma diferença: **ausente = desligado** (proposta C1).
- Desligado:
  - o módulo sai do menu;
  - a rota cai em "Acesso não autorizado" (`frontend/src/App.jsx:136-138`);
  - toda a API do módulo responde **404** antes de tocar no banco.

### RF-CHG-15 — Carteiras e locais
- Tabelas no MySQL, carregadas no servidor por um script que lê um arquivo **fora do repositório** (desenho 2.1 e 2.5).
- Nenhum valor real em migration, fixture, QA ou documento.
- Código fora da base é recusado com mensagem (proposta C2).

### RF-CHG-16 — Auditoria
- `audit_log` (`security.php:696-724`) em toda mudança e em toda recusa de perfil, com ids, campos e etapas, **sem texto livre, link ou nome** (desenho 5).
- Histórico por CHG em tabela própria, para a tela (desenho 2.3).

### RF-CHG-17 — Frontend seguro
- React sem `innerHTML` nem `dangerouslySetInnerHTML`, e sem `localStorage`. Já é travado por `frontend/scripts/qa-ux-hardening.mjs:56-62` para todo `frontend/src`.
- Rede só por `frontend/src/lib/api.ts` (`api`, `post`, `apiUrl`), que manda o `X-CSRF-Token` (`:51`).
- A cópia da tabela usa a API de área de transferência com HTML montado por função pura com escape. A reserva é uma caixa React com a tabela.

### RF-CHG-18 — Permissões
- Permissões novas (`chg.*`) na matriz de `portal_permissions_for_role` (`security.php:930-966`), conferidas por `portal_role_can` (`:1092`).
- Somente leitura barrado na escrita por `require_portal_write_access` (`api/portal/_bootstrap.php:43-51`).
- Técnico só age na CHG atribuída a ele (checagem por objeto).
- Matriz proposta no desenho 3.8; perfis A DEFINIR (4.1).

---

## 3. Requisitos não funcionais

- **Segurança:**
  - CSRF em toda escrita (`portal_json_input`: CSRF, JSON e 64 KB; `api/portal/_bootstrap.php:53-95`);
  - SQL parametrizado;
  - colunas de ordenação e filtro por lista branca;
  - erros pelo `portal_operational_error` (400, 403, 409, 413, 503; `:97-117`).
- **Concorrência:** o modelo deixava a última gravação vencer. Proposta: versão do registro, com 409 "alterado por outra pessoa, recarregue" (C11).
- **LGPD:** desenho 7 (nomes, links, texto livre, carteiras, exportação, retenção).
- **Acessibilidade:** etapa e status sempre com texto, nunca só cor (já é assim no modelo); quadro utilizável sem arrastar.
- **Desempenho:** consultas indexadas e sem N+1; atualização de 60 s só com a tela visível; medição no roteiro.
- **Testes:** QA PHP, `qa:chg`, mutação, `test-local.ps1` e ensaio no roteiro (desenho 8).

---

## 4. A DEFINIR (responsável e solicitante)

### 4.1 Perfis e login dos solicitantes

**Pergunta:** quem cadastra, quem registra a aprovação, quem executa e quem só lê? Os solicitantes têm login no AD e vão entrar no ChronoDesk?

Fato: os perfis hoje são `admin`, `lideranca`, `gestor`, `tecnico` e `somente_leitura` (`security.php:930-966`). Quem não está em `funcionarios` (ou na allowlist de admin) não tem perfil.

| Resposta | O que muda |
|---|---|
| **Solicitantes sem login** (a gestão cadastra por eles) | Solicitante é texto (nome), com sugestão dos valores já usados (lista distinta no servidor). Matriz do desenho 3.8 como está. Nada muda na autenticação |
| **Solicitantes com login no AD e acesso ao ChronoDesk** | Perfil novo (por exemplo `solicitante_chg`) e uma fonte para ele: grupo do AD ou allowlist no `.env`, A DEFINIR. Mexe no núcleo de autenticação (`current_portal_role`, `security.php:877`), que pede revisão de segurança e lote próprio antes do módulo. O solicitante passa a ser o login de quem cadastrou, com filtro "minhas solicitações" e checagem por objeto (só edita as próprias enquanto estiverem em "Aguardando retorno") |
| **Técnicos veem só as próprias CHGs** | Filtro por `tecnico_id` no servidor para o perfil `tecnico`; o dashboard só para a gestão |
| **Técnicos veem todas** | Leitura igual à da gestão; a escrita continua só na própria |
| **Aprovação registrada por pessoa específica** (não pela gestão em geral) | Permissão `chg.approve` separada e dada a quem for indicado |

### 4.2 Tarefas, atividades, aprovações e comitês

**Pergunta:** o que "tasks, atividades, aprovações e comitês" acrescenta ao modelo?

| Resposta | O que muda |
|---|---|
| **Nada além do modelo** | Lotes G1 a G5 como no desenho. O histórico da CHG (desenho 2.3) registra quem marcou "aprovada" no ChronoDesk e quando, mas não quem aprovou no comitê |
| **Tarefas por CHG** | Tabela `portal_chg_tasks` (título, responsável `funcionarios.id`, situação, prazo), migration 023 e lista de verificação no card. **Decidir:** concluir a CHG exige todas as tarefas concluídas? Mais um lote (G6) |
| **Quem aprovou e quando** | Tabela `portal_chg_approvals` (aprovador, decisão, data, origem manual ou integrada). A ação `approve` passa a exigir aprovador e data; aprovador com login vira FK, sem login vira texto (LGPD: nome de terceiro) |
| **Data e ata do comitê** | Tabela `portal_chg_committees` (data, link `https` da ata ou anexo) e vínculo CHG → comitê. Anexo reaproveita o `DocumentService` (validação de upload); link segue o RF-CHG-09. A coluna "Aguardando Comitê" mostra a data do comitê |
| **Algum desses vem do ServiceNow** | Depende de 4.3 (integrado); sem integração, digitação manual |

### 4.3 ServiceNow: digitado à mão ou integrado

Fato: o ChronoDesk só mostra a configuração do ServiceNow (`SERVICENOW_API_URL`) num cartão de situação (`services/integrations/IntegrationRegistry.php:13`; `services/integrations/EnvironmentIntegration.php:25-34`). **Não há cliente que chame o ServiceNow.**

| Resposta | O que muda |
|---|---|
| **Digitado à mão** | Número e status da CHG gravados pela tela; formato validado no servidor (proposta C12); nenhuma rede nova. É o que o desenho cobre |
| **Integrado** | Lote próprio, com desenho próprio:<br>• conta de serviço só leitura e segredo no `.env` ou cofre;<br>• liberação de saída HTTPS do servidor (regra de firewall, A DEFINIR com a infraestrutura);<br>• URL base fixa (sem SSRF), tempo limite e tratamento de indisponibilidade;<br>• sincronização agendada em vez de chamada por requisição (`pm.max_children=5`);<br>• mapeamento de status;<br>• quem é a fonte da verdade em divergência.<br>O campo vira só leitura para o que vier de lá |

### 4.4 CSV ou Excel

| Resposta | O que muda |
|---|---|
| **CSV** (proposta) | Padrão atual, sem dependência (RF-CHG-11). Uma visão em linhas (a aba "Base"). Abre no Excel pelo BOM e pelo `;` |
| **Excel (`.xlsx`)** | Fato: não há `composer.json` no repositório. O `ZipArchive` já é usado, mas só para **ler** `.xlsx` (`services/SpreadsheetImportService.php:84-89`). Duas saídas: (a) dependência nova (biblioteca de planilha via Composer), que exige aprovação, adoção do Composer, lockfile e checagem de CVE; ou (b) gerador mínimo próprio com `ZipArchive` e XML, com mais código e testes de formato. No frontend, nunca CDN. Com estilo e duas abas ("Cards" e "Base"), (b) cresce bastante |

### 4.5 Deploy único da Parte B ou depois

Fato: todos os lotes ficam no branch `feat/employee-form-rhf-zod`, e o deploy da Parte B é um `git merge --ff-only` único do commit ensaiado (pendência 16 do `ESTADO_SESSAO_CHRONODESK.md`).

| Resposta | O que muda |
|---|---|
| **No deploy único da Parte B** | As migrations 021 e 022 entram no roteiro consolidado depois da 020 (F9), com ensaio e verificação. A carga das carteiras entra depois da migration e antes de ligar o módulo. O código vai no mesmo merge, com `CHG_MODULE_ENABLED=false`, e o módulo só é ligado no fim, conferido. O roteiro cresce e a bateria também |
| **Depois da Parte B** | O desenvolvimento precisa de **branch próprio** (`feat/chg`, a partir do commit que for ao servidor) **ou** precisa começar depois do deploy. Se os commits do módulo entrarem no branch atual antes, vão junto no merge da Parte B (mesmo desligados). Roteiro próprio, "Deploy que traz o módulo de CHGs", no `DEPLOY_LINUX.md` |

### 4.6 Achados da leitura (decisão do responsável)

| # | Pergunta | O que muda |
|---|---|---|
| 4.6a | **Carga inicial:** começar vazio ou importar os cards em uso? (Fato: o modelo pode estar gravando no armazenamento do ambiente de publicação, `:510-520`.) | Vazio: nada a fazer. Importar: script de importação único, a partir de um arquivo exportado pelo responsável, com o técnico mapeado para `funcionarios.id` e o link validado; o que não passar fica de fora, com relatório só de contagens. E confirmar com o responsável onde esses dados estão hoje (LGPD) |
| 4.6b | **Nome do módulo no menu e rota** | Proposta "Mudanças (CHG)" em `/mudancas`, na seção "Fluxos operacionais" (`frontend/src/lib/navigation.ts:39-43`) |
| 4.6c | **Equipes que podem ser técnico da CHG** (N1, N2, Liderança?) | Filtro do seletor de técnico e checagem no servidor |

---

## 5. Critérios de aceite (mínimos)

1. **Etapa pelo relógio do servidor**, para uma CHG aprovada com início às 20:00:
   - às 15:59:59 está em "Aguardando Comitê" ("CHG aprovada");
   - às 16:00:00, em "Em preparação";
   - às 20:00:00, em "Em execução";
   - depois do fim, "Janela encerrada".

   Teste da função pura com instantes fixos e de ponta a ponta com `server_now` simulado.
2. Concluir fora de "Em execução" ou sem técnico: 409 com mensagem. Técnico concluindo CHG de outro: 403, com auditoria `CHG_WRITE_DENIED`.
3. Somente leitura tentando qualquer escrita: 403.
4. Link `http://…`, `javascript:…`, `https://usuario:senha@…` ou texto solto: 400.
5. Célula iniciada por `=`, `+`, `-` ou `@` no CSV sai com apóstrofo; o CSV não tem CDN nem biblioteca externa.
6. Com `CHG_MODULE_ENABLED` ausente ou `false`:
   - o menu fica sem o item;
   - a rota mostra "Acesso não autorizado";
   - os endpoints do módulo respondem 404.

   Ligado, tudo volta.
7. Nenhuma CHG é apagada: cancelar e arquivar deixam a linha e a auditoria.
8. Nenhum dado real de carteira, local, técnico, IP ou link em `git ls-files`. A pasta `_referencias/` fica ignorada.
9. Duas pessoas editando a mesma CHG: a segunda recebe 409.
10. Todos os `qa:*` e QAs PHP existentes continuam passando; `qa-chg.php` e `qa:chg` novos; mutação com todos os mutantes mortos ou justificados.

---

## 6. Fase de desenho

Entregue em `docs/DESENHO_CHG.md`, com:
- modelo de dados (migrations a partir da 021) e contratos da API;
- matriz de perfis e auditoria;
- LGPD e o interruptor;
- plano de testes e lotes;
- a parte do roteiro único.

**Parar para a revisão do responsável.**
