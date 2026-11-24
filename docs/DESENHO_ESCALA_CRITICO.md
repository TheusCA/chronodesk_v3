# Escala do Crítico em grade semanal (deploy 5)

**Estado:** pedido da Liderança em 2026-11-24; plano curto e execução neste documento; decisões D-ESC-1 em diante a aprovar. Parte do 9.16 (migration 024, `CriticalRolePlanner`, `CriticalRoleService`, `CriticalRoles.jsx`). Migration nova: **027** (a 023 fica com o F10-4). Entra no deploy 5 (ainda sem data). Sem push.

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
| 6 | Roteiro e folha do deploy 5 com a 027, e a trava |
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
