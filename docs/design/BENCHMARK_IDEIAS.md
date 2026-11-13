# Ideias para depois (backlog do redesenho, sem compromisso)

**Estado:** R0, só backlog (2026-11-13). Nenhuma ideia daqui entra num lote sem pedido, desenho e aprovação. As que mexem em regra, dado ou permissão passam pelo fluxo de sempre (desenho, decisões e LGPD), não por um lote R.

**De onde vêm:** padrões comuns em três tipos de produto, sem copiar nenhum:
- reserva de mesa e de posição de trabalho;
- gestão de força de trabalho de central de atendimento (aderência à escala);
- gestão de mudanças de TI (calendário de mudanças e comitê).

**Esforço:** P até 2 commits, M até 5, G um lote inteiro.

---

## 1. Escala e Mapa de PA

| Ideia | Módulo | O que resolve | Depende de | Esforço | LGPD |
|---|---|---|---|---|---|
| **"Quem está hoje" e localizador de colegas:** buscar um nome e ver o PA, a fileira e a situação de agora no mapa | Mapa de PA, Visão operacional | "Onde senta fulano hoje?" sem perguntar no chat | Distribuição publicada do F10 (F10-4) para os PAs flexíveis; antes disso, só os vínculos | M | Mostra o lugar e a situação, como o Mapa de hoje. A categoria da ausência segue só para admin e gestor (P9) |
| **Zonas por equipe no mapa:** N1, N2, Liderança e reservados (Híbrido e Crítico) com contorno e rótulo | Mapa de PA | Ler o mapa sem decorar números | Fileira e tipo de PA do F10-3 (migration 022) | P | Nenhuma |
| **Planejamento semanal:** grade pessoa × dia da semana, com a situação e o PA, para a Liderança ver a semana antes de publicar | Escala presencial, F10 | Ver o efeito de uma troca de regra antes de gravar | Prévia do mês do F10-4 | M | A grade de todos já é a decisão D-F10-10 |
| **Indicador de justiça:** trocas de PA por pessoa no mês e dias de home por pessoa contra a média do time | F10 (resumo de PA), Relatórios | Mostrar se o motor está trocando sempre as mesmas pessoas | `moved` do `PaDistributionEngine` (F10-1) gravado nas linhas do mês (F10-4) | P | Contagem por pessoa: só gestão e Liderança |
| **Check-in e liberação de PA (futuro):** o técnico confirma que sentou ou libera o PA quando sai mais cedo | Mapa de PA, F10 | O mapa do dia fica fiel ao real | Regra nova: quem libera, se o motor redistribui, o que vira registro. Pede desenho próprio | G | Registro de horário de chegada é dado de jornada: só com finalidade e retenção definidas |

---

## 2. Pausas

| Ideia | Módulo | O que resolve | Depende de | Esforço | LGPD |
|---|---|---|---|---|---|
| **Aderência em tempo real:** pausa e almoço reais contra o previsto (almoço por contrato do F8 e limite de café), com o desvio do dia por equipe | Visão operacional, Pausas | Ver na hora quem estourou e quanto, sem planilha | Almoço por contrato (F8, commits 2 a 6, esperando a Liderança) | M | Desvio individual só para gestão e Liderança; o técnico vê o próprio |
| **Mapa de calor por intervalo:** quantas pessoas em pausa ou almoço a cada 15 minutos, no dia e na média da semana | Métricas | Achar o horário com pouca gente disponível e mudar a regra de pausa | Histórico de pausas que o `metricas.php` já tem | M | Agregado por equipe, nunca por pessoa |
| **Regras de pausa automáticas (F8):** limite por equipe e por horário, aviso antes de estourar e sugestão de horário de almoço | Pausas, Configurações | Menos aprovação manual e menos estouro | F8 (regra), não redesenho | G | Regra de jornada: desenho do F8 |
| **Aviso de limite no próprio cartão:** a cor muda e o tempo restante aparece nos últimos 2 minutos | Pausas | O técnico não estoura sem perceber | Só tela (os limites já existem) | P | Nenhuma |

---

## 3. CHGs (módulo de mudanças)

| Ideia | Módulo | O que resolve | Depende de | Esforço | LGPD |
|---|---|---|---|---|---|
| **Calendário de mudanças com janelas de congelamento e de manutenção,** em faixas sobre os dias | CHGs | Ver de uma vez quando não pode haver mudança | Desenho do módulo (G0c, G1) | G | Nenhuma: mudança não é dado pessoal, o responsável pela CHG é |
| **Detecção de conflito:** duas CHGs na mesma carteira ou no mesmo local em datas que se sobrepõem, avisadas na hora de lançar | CHGs | Evitar duas janelas no mesmo sistema | Campos de carteira e de local no modelo da CHG | M | Nenhuma |
| **Calendário só de leitura** para quem não é Tools, Liderança nem admin | CHGs | Todos sabem das janelas; só quem pode lança | Permissões do grupo Tools (P13 do estado da sessão) | P | Nenhuma |
| **Reunião do comitê com pauta:** a lista das CHGs da semana vira a pauta, com a decisão de cada uma registrada na própria CHG | CHGs | A ata deixa de ser um documento à parte | Fluxo de aprovação do comitê no desenho do módulo | G | Decisão e autoria auditadas, como as outras aprovações |

---

## 4. Transversais

| Ideia | Módulo | O que resolve | Esforço |
|---|---|---|---|
| **Paleta de comandos** (Ctrl+K): ir para uma tela ou uma pessoa pelo nome | Casca | Menos cliques para quem usa o portal o dia todo | M |
| **Atalhos de teclado nas ações do dia:** iniciar e encerrar pausa, aprovar na lista | Pausas, Aprovações | Velocidade, com confirmação onde ela existe hoje | P |
| **Modo painel para a tela fixa** (direção, seção 4) | Visão operacional | Leitura a 3 metros | P, no R4 |
| **Notificação no navegador** quando uma aprovação chega, para quem aprova | Aprovações | Menos espera do técnico. Pede permissão do navegador e cuidado com o conteúdo da notificação (sem motivo nem observação) | M |
