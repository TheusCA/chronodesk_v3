# Escala do Crítico em grade semanal (deploy 6)

**Estado:** pedido da Liderança em 2026-11-24; plano curto e execução neste documento; decisões D-ESC-1 em diante a aprovar. Parte do 9.16 (migration 024, `CriticalRolePlanner`, `CriticalRoleService`, `CriticalRoles.jsx`). Migration nova: **027** (a 023 fica com o F10-4). Entra no **deploy 6** (sem data): o deploy 5 ficou congelado no `babcdd9` (decisão do responsável, 2026-11-24), sem nada deste lote. Sem push.

**LGPD e sigilo:** as referências (`_referencias/critico/`, planilha e captura) têm nomes reais: só a estrutura e as cores foram usadas; nada delas no git, nos docs, nos QAs nem nas capturas. Os testes e as capturas usam nomes fictícios. A categoria da ausência segue a P9: quem não é Liderança nem admin vê só "Férias", "Banco de horas", "Feriado" ou "Ausente".

---

## 1. Pedido

1. Tela "Escala do Crítico" (rota própria no menu Escalas), para todos os perfis logados; editar só Liderança e admin (`escalas.manage`), conferido no servidor. Mês com navegação, um bloco por semana ("Semana <ISO> (Início: dd/mm/aaaa)"), uma linha por pessoa, Seg a Sex; semana atual aberta, hoje destacado; "Mês | Semana". Célula = botão do dia com papel e jornada, na cor do papel; ausência, banco de horas, férias e feriado no lugar do papel; ícone quando veio de troca ou exceção; legenda; resumo do mês por pessoa; cartões no celular; setas, Enter e foco visível.
2. Cor por papel (`color_token`, paleta fechada, sem hex no banco nem `style`), padrão pelo nome na 027; cores fixas de banco de horas, férias, feriado e ausente.
3. Clique no dia: leitura para quem só vê; diálogo do dia para Liderança e admin (trocar o papel, marcar ausência, exceção, desfazer), com prévia do efeito e aviso do "pelo menos 1 disponível"; vários dias de uma pessoa (Shift+clique ou arrastar); versão/409, proteção de alteração não salva, auditoria só com IDs.
4. Papel em mais de uma vaga no rodízio ("Presencial x2"), sem mudar o passado.
5. Migration 027: ausência `banco_horas`; cadastro de feriados (motor: dia sem expediente para todos, mas dia útil no ciclo).
6. "Exportar Excel" do mês no formato da planilha, com a mesma regra de privacidade.

## 2. Plano curto (commits)

| # | Commit |
|---|---|
| 1 | Este plano (D-ESC-1 a D-ESC-20) |
| 2 | Migration 027 (cor do papel, vagas no rodízio, `banco_horas`, `portal_holidays`), rollback com parada, verificação só com contagens, linhas de ensaio; ensaio a e b no MySQL 8.0.46 local |
| 3 | Servidor: vagas no planner e no serviço, cor do papel, `banco_horas`, feriados (serviço, endpoint, permissão `feriados.manage`) e o feriado no motor e em todas as telas que leem o motor |
| 4 | Servidor: grade do mês (`critical_grid.php`: GET do mês, prévia e gravação do dia, desfazer, `.xlsx`) |
| 5 | Telas: Escala do Crítico, cor e vagas nos papéis, feriados em Configurações, banco de horas em Ausências; QA do frontend e bloco do navegador |
| 6 | Roteiro e folha do deploy 5 com a 027, e a trava (desfeito: a 027 foi para o deploy 6, abaixo) |
| 7 | Docs: execução, validação e registro |

Mutação por commit (worktree, `mut_guard.php`); bateria completa sozinha no último commit; capturas com dados fictícios em 1366 e 1920 px, nos dois temas, no celular, como técnico e como Liderança, com o diálogo do dia aberto.

## 3. Telas que mudam com o feriado

| Tela / endpoint | O que muda | Onde no QA |
|---|---|---|
| Visão operacional (`dashboard.php`) | Ninguém escalado no feriado: os contadores não contam o dia; nenhum papel vago; nenhum "sem disponível" | `qa-holidays.php` (operationalView) |
| Status e pausas (`status.php`) | Presença "fora do expediente", detalhe "Feriado: <nome>"; o "pelo menos 1 disponível" não conta o dia | `qa-holidays.php` (nowPresence e a regra de pausa) |
| Calendário (`calendar.php`) | Sem evento de escala no feriado; um evento "Feriado: <nome>" no dia | `qa-holidays.php` (calendarEngineEvents) |
| Escala presencial e relatórios (`schedules.php`, `reports.php`) | O feriado sai como dia sem expediente (fora da contagem, como o fim de semana) | `qa-holidays.php` (engineSchedule) |
| Mapa PA (`pa_map.php`) | Vínculo no feriado com o motivo `feriado` (no lugar de "sem situação"); ninguém nos reservados | `qa-holidays.php` (mapPresence e assignmentView) |
| Escala do Crítico (nova) | Célula "Feriado" na cor própria | `qa-critical-grid.php` |
| Presença (`presence.php`) | Situação do dia como fim de semana, com o detalhe do feriado | `qa-holidays.php` (dayPresence) |

## 4. Decisões de implementação (a aprovar)

| # | Decisão | Por quê |
|---|---|---|
| D-ESC-1 | Rota `/escala-critico` no menu Escalas, sem permissão de rota; o GET da grade pede só sessão; gravar pede `escalas.manage` no endpoint e de novo em cada serviço | Pedido: todos veem, Liderança e admin editam |
| D-ESC-2 | Precedência da célula: ausência de dia inteiro > exceção de trabalho por data > feriado > troca > rodízio > regra individual. Ausência parcial deixa o papel com o marcador | A do 9.16 com o feriado como dia sem expediente; a exceção de trabalho (plantão) vale no feriado, como no fim de semana |
| D-ESC-3 | A grade vem do motor (`PresenceEngine::dayPresence` com `shiftFor`), numa carga fixa: cadastro, regras, exceções, ausências, papéis/rodízios/trocas e feriados; nenhuma consulta por pessoa ou dia | Uma fonte só (8.8) e sem N+1 |
| D-ESC-4 | O mês são as semanas (seg a sex) com algum dia do mês; os dias de fora aparecem esmaecidos, sem clique e fora do resumo. Pessoas: ativas do Crítico, na ordem do rodízio vigente no 1º dia do mês e depois pelo nome; nome curto = 1º nome, mais a inicial do 2º quando o 1º se repete | Como a planilha |
| D-ESC-5 | Privacidade: só `escalas.manage` (Liderança e admin) vê a categoria da ausência; os demais veem "Férias", "Banco de horas" ou "Ausente" (e "Feriado"). Observação nunca vai na grade nem no `.xlsx`. "Quem alterou por último" (login) para todos | Pedido; a mesma regra na tela e no Excel |
| D-ESC-6 | Versão por célula: hash curto do que decide o dia (troca, ausências, exceção, feriado, papel do rodízio). A gravação manda a versão de cada dia; o servidor recalcula com o colaborador travado e devolve 409 com a contagem de dias que mudaram. Os feriados têm `row_version` na tabela | O dia junta três tabelas; o hash cobre todas sem coluna nova nelas |
| D-ESC-7 | "Trocar o papel" grava a troca pontual do 9.16 (motivo `troca`), só este dia ou até dd/mm (até 31 dias); com troca no período, 409 "N troca(s) já cobrem o período" | Reaproveita a regra e a auditoria do 9.16 |
| D-ESC-8 | "Marcar ausência" grava pelo `AbsenceService::create` (dia inteiro, aprovada, mesmas regras de conflito e de retroativa). Categorias: Férias, Banco de horas, Folga, Ausência justificada, Compromisso, Outro. **A confirmar:** "Atestado" é lançado como Ausência justificada (sem dado de saúde no portal) e "Treinamento" é a exceção `training` que já existe (dia trabalhado, presencial), não uma ausência | O pedido cita atestado e treinamento, que não existem como categoria; a 027 só traz o banco de horas |
| D-ESC-9 | "Exceção do dia": presencial ou home forçado (e treinamento, D-ESC-8), pela exceção por data que já existe, uma por dia útil do intervalo | Mesmas regras (L9, P7) |
| D-ESC-10 | "Desfazer" no dia: a troca que cobre o dia sai inteira (a prévia mostra o intervalo dela); a ausência é cancelada inteira (fica no histórico como cancelada); a exceção daquele dia sai. Com confirmação | O registro é um só; desfazer metade criaria outro sem pedido |
| D-ESC-11 | Prévia do servidor antes de salvar: por dia, "antes → depois" ("Qui 19/11: Home 10:30–20:30 → Banco de horas") e o aviso quando o Crítico fica sem ninguém disponível no dia (ninguém presencial ou remoto, fora ausência de dia inteiro); salva com o aviso | Pedido; o aviso não bloqueia, como hoje |
| D-ESC-12 | Vários dias: Shift+clique ou arrastar na mesma linha, dentro do mês mostrado; troca e exceção valem só nos dias úteis sem feriado do intervalo; a ausência cobre o intervalo corrido, como na tela de Ausências | Fim de semana e feriado não têm papel |
| D-ESC-13 | `color_token` em `portal_critical_roles`, `VARCHAR(16)` nulo com CHECK na lista fechada (azul, verde, pessego, lilas, amarelo, turquesa, cinza, areia); nulo = areia na tela. Padrão da 027 pelo começo do nome, só onde está nulo: Presencial azul, Abertura verde, Fechamento pêssego, Home lilás | Sem hex no banco; CSP sem `style` |
| D-ESC-14 | Classes `esc-cor-<token>` num CSS só da rota (como o do CHG), com fundo, texto e borda nos dois temas; contraste do texto ≥ 4,5:1 conferido no QA nos dois temas. Cores fixas: banco de horas amarelo, férias amarelo-claro (areia quente), feriado turquesa, ausente cinza. Os tons aproximam os da planilha (Presencial #C5D9F1, Abertura #EBF1DE, Fechamento #FCE4D6, Home #CCC0DA, Banco #FFFF00, Feriado #92CDDC, Férias #FFEB9C; título #1F4E78, faixa da semana #5B9BD5, cabeçalho do dia #D9EAF7) | Reconhecível e legível nos dois temas |
| D-ESC-15 | Vagas: a lista de papéis do rodízio aceita o mesmo papel mais de uma vez (a CHECK da 024 sai sem `uniqueItems` na 027); pessoas continuam sem repetir. A regra de hoje continua, contando vagas: posições = max(pessoas, vagas), pessoa a mais fica pelo cadastro, vaga sem pessoa é alerta. O papel vago passa a ser contado por vaga. Versão nova com vigência, sem mudar o passado. A tela mostra "Presencial x2" | Pedido; o rodízio salvo antes não muda |
| D-ESC-16 | `portal_holidays` (data única, nome, abrangência `todos`, `row_version`, autoria). Permissão nova `feriados.manage` (Liderança e admin); Configurações passa a abrir com `configuracoes.manage` ou `feriados.manage`, e a Liderança vê só os feriados | Pedido: tela simples em Configurações para Liderança e admin |
| D-ESC-17 | Feriado no motor: dia sem expediente para todos (`dia_nao_util`, origem `feriado`, detalhe "Feriado: <nome>"), salvo exceção de trabalho por data; não é falta, não entra no "1 disponível", não gera papel vago nem alerta; o ciclo (D-F9-30) e o rodízio (D-PC-11) continuam contando o dia como útil. O aviso de conflito do Mapa PA (20 dias úteis) continua contando o feriado | Pedido; o ciclo não muda |
| D-ESC-18 | `banco_horas` no fim do ENUM de `portal_absences.category`, rótulo "Banco de horas"; a tela de Ausências ganha a opção | Pedido |
| D-ESC-19 | Exportar: `GET critical_grid.php?format=xlsx&month=AAAA-MM`, todos os perfis, o que cada um vê; uma aba "<Mês> <Ano>", título, blocos por semana, cabeçalho "Seg (dd/mm)", cores da planilha, legenda e o resumo abaixo; auditoria só com o mês e contagens | Pedido |
| D-ESC-20 | Auditoria: cada serviço audita como hoje (IDs); a grade acrescenta `ESCALA_CRITICO_DIA` com `employee_id`, datas, ação e contagens; feriado `FERIADO_*` com o ID e a data | Só IDs |

## 5. Execução (2026-11-24)

| Commit | Conteúdo |
|---|---|
| `6054177` | Este plano (D-ESC-1 a D-ESC-20) |
| `3e128bb` | Migration 027, rollback com as duas paradas (cópia anterior; rodízio com papel repetido), verificação só com contagens e linhas de ensaio; `qa-critical-grid` seção 1 |
| `b1f4485` | Servidor: feriado no motor (`scheduleForDay`, `hasShiftOn`, `shiftFor` com o feriado) e em todas as telas que o leem (presença, `status.php`, Visão operacional sem papel vago, regra de pausa, Escala, relatórios, Calendário com o evento do feriado, Mapa PA com o motivo `feriado`); `HolidayService` e `holidays.php` com `row_version`; permissão `feriados.manage`; vagas no planner e no serviço (o papel vago contado por vaga); cor do papel (ausente na edição mantém); `banco_horas`. Uma consulta de feriados a mais por carga: os QAs de contagem passam de 4 para 5 (presença, status, Visão operacional) e o do Mapa PA de 13 para 15 |
| `a0c263c` | Servidor: `CriticalGridService` e `critical_grid.php` (mês em 8 consultas fixas, célula pelo motor, privacidade, versão, prévia, gravação com 409, desfazer) e `CriticalGridSheet` (o `.xlsx`) |
| `e0f9a54` | Telas: Escala do Crítico, cor e vagas nos papéis, feriados em Configurações, banco de horas em Ausências, feriado no Calendário e no Mapa PA; `qa:critical-grid`, bloco 34 do navegador, servidor simulado com a grade fictícia |
| `c6cbab9` | Roteiro e folha do deploy 5 com a 027 e a trava; **desfeito no `39a7e45`** (`git revert`, sem reescrever nada), quando o deploy 5 ficou congelado no `babcdd9` |
| `d0cce66` | Achados das capturas: o resumo repetia "Home" e "Presencial" (papel e contagem) e a célula não ocupava a altura da linha com ausência parcial |
| `86b4f9b` | Esta seção, os contratos dos dois endpoints novos e o `REGISTRO_USO_IA.md` |
| `39a7e45` | `git revert` do `c6cbab9`: o roteiro, a folha e a trava do deploy 5 voltam a ser os do `babcdd9` |
| `4d70149` | Deploy 5 congelado no `babcdd9` e lido do `origin/codex/modernizacao-ui-backend` (item 0 e passo 1; o `feat` segue no `f0844fd`); a trava confere o congelamento |
| `10d6d37` | Roteiro, folha e trava do **deploy 6** (seção nova depois do deploy 5; 39 comandos, os do deploy 5 com `D6` e a base `babcdd9`, só a 027); a 027 e o `qa-critical-grid` fora das listas dos deploys 1 a 5; a contagem de scripts npm dos deploys 4 e 5 fixa em 30 |
| `bb4d9d1` | Trava: o deploy 5 congelado também recusa a 027 e os feriados citados na prosa (sobrevivente da mutação) |
| O último | A ida para o deploy 6 neste documento e no registro |

**Ensaio da 027 no MySQL 8.0.46 local** (base `qa_esc`: cópia da `qa_nci` com 5 pessoas fictícias do Crítico, 5 papéis, um rodízio e uma troca): (a) migration 2x e rollback 2x, saída 0: depois da 027, `colunas_027 = 1`, `checks_027 = 2`, `tabela_027 = 1`, `enum_027 = 1`, `papeis_com_cor = 4` (de 5: o papel "Plantao extra" fica sem cor), assinaturas iguais nos cinco estados, o 2º rollback para de propósito; (b) com as linhas de ensaio, `feriados = 1`, `ausencias_banco_horas = 1`, `papeis_com_cor = 5`; o rollback leva a ausência a `outro` (só a assinatura das ausências muda) e o 2º para. À parte: o rollback para com rodízio de papel repetido sem mudar nada; sem a 027, o papel repetido e a cor fora da paleta dão erro 3819 (CHECK). Na 1ª rodada, a mensagem do `SIGNAL` passou de 128 caracteres (erro 1648): encurtada antes do commit.

**Integração no MySQL 8.0.46 local** (`qa_esci` = `qa_esc` + 027; transação desfeita no fim; serviços de verdade): 26/26. Rodízio com Presencial x2 aceito; cor fora da paleta recusada; feriado cadastrado e a data repetida 409; Visão operacional sem escalados nem papel vago no feriado; um evento de feriado no Calendário; a grade com 5 semanas, o feriado e a cor; gravar banco de horas (ausência aprovada, dia inteiro, com autoria), a versão velha 409 com a contagem, a troca (motivo `troca`), a troca sobreposta 409, a exceção presencial, os três desfazer (a exceção sai, a troca sai inteira, a ausência fica cancelada), o gestor 403, a privacidade do técnico e o `.xlsx`.

**QAs:** `qa-critical-grid` 141 conferências (seções 1 a 3); `qa-critical-roles` 159; `qa:critical-grid` 113 (contraste da paleta ≥ 4,5:1 nos dois temas para os 16 tons); `qa:critical-roles` 41; trava do roteiro 556 (depois do deploy 6); bloco 34 do navegador 17/17.

**Mutação** (worktree, `mut_guard.php`): `3e128bb` 21/21 (na 1ª rodada 19/21: a condição da parada das vagas e a guarda da cópia da cor não eram conferidas; casos novos, commit refeito antes de qualquer envio); `b1f4485` 31/31; `a0c263c` 34/34 (na 1ª rodada 30/34: ausência no feriado, troca para o mesmo papel do rodízio e duas conferências estruturais frouxas; casos novos e um mutante equivalente trocado, commit refeito antes de qualquer envio); `e0f9a54` 29/29 (na 1ª rodada 28/29: o ícone visível da troca; caso novo, commit refeito); `c6cbab9` 17/17 (desfeito); `d0cce66` 3/3; `4d70149`, `10d6d37` e `bb4d9d1` juntos 21/21 (na 1ª rodada 19/21: a prosa do deploy 5 citando a 027 passava, caso novo no `bb4d9d1`; um mutante não aplicou por erro do próprio script de mutação e foi refeito).

**Capturas** (dados fictícios, servidor simulado): 32, em 1366 e 1920 px nos dois temas, no celular (390 px), como técnico e como Liderança, com o diálogo do dia aberto, mais o resumo, a visão Semana, os feriados em Configurações e a cor do papel. Ficam no scratchpad da sessão (`esc/capturas`).

**Bateria completa** (`test-local.ps1 -SkipHttp -Browser`, sozinha, no worktree do último commit): no resumo da entrega e no estado da sessão.

**Decisões a confirmar:** D-ESC-8 (atestado como Ausência justificada; treinamento como a exceção `training`). **Achados fora do escopo, não corrigidos:** (1) a remoção da exceção pelo diálogo (D-ESC-10) não entra na fila de sincronização (`portal_sync_queue`), como a gravação; o consumidor da fila segue A CONFIRMAR (17h); (2) no `db.php`, a senha do banco passa como argumento comum numa chamada interna e aparece truncada no stack trace de um erro de conexão sem tratamento (visto no ensaio local; o portal trata o erro e não mostra o trace). Proposta: `#[\SensitiveParameter]` nessa chamada, num lote próprio.
