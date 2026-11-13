# Auditoria de UX e de visual do portal (R0 do redesenho)

**Estado:** R0, só documentação, capturas e protótipo isolado (2026-11-13). Nada do app em produção mudou. A direção proposta está em `DIRECAO_VISUAL.md`, as ideias em `BENCHMARK_IDEIAS.md` e os lotes em `PLANO_LOTES_R.md`.

---

## 1. Método

**Capturas "antes":** 63 imagens em `capturas/antes/` (WebP, 1,9 MB), na janela de 1280×900 e de 390×844.
- O build atual do app roda no servidor simulado do roteiro de navegador (`frontend/scripts/browser/lib/mock-server.mjs`, só dados fictícios).
- Na frente dele, um proxy troca a sessão por perfil e põe 16 técnicos fictícios na situação da equipe (`frontend/prototypes/r0/capturar.mjs antes`). O `mock-server.mjs` não mudou.

**Perfis**, com as permissões de `portal_permissions_for_role` (`security.php`):

| Perfil | Telas | Larguras |
|---|---|---|
| Sem sessão | login | 1280 e 390 |
| Admin | as 20 telas do menu e a aba Funcionários | 1280 |
| Admin | as 8 principais | 390 |
| Liderança | 6 telas | 1280 e 390 |
| Gestor | 5 telas | 1280 e 390 |
| Técnico | 5 telas | 1280 e 390 |

O nome do arquivo é `<perfil>-<tela>-<largura>.webp`.

**Artefatos do servidor simulado, que não são defeito do app:**
- a marca quebrada no canto: o simulado só serve `/app/assets/`, e o `sdk-icon.png` fica fora disso;
- o relógio do topo em 13h e a situação às 10:42: o relógio é o do navegador, e o servidor simulado está parado em 10:42.

**O que entra além das capturas:**
- leitura do `src/index.css`, do `tailwind.config.js`, de `src/components` e de `src/pages`;
- contraste calculado com a fórmula da WCAG 2.1;
- contagem por busca no código.

---

## 2. Sinais de "cara de IA" no visual de hoje

| # | Sinal | Onde | Por que lê como gerado |
|---|---|---|---|
| S1 | Fundo com gradiente e grade de linhas ciano em todas as telas | `src/index.css:14-19` (corpo), `:267-298` (`.portal-tech-shell::before` com grade de 64 px e brilho diagonal, `::after` com linha ciano no topo) | A "grade tecnológica" é o fundo padrão dos painéis gerados. Não informa nada e baixa o contraste das bordas |
| S2 | Login com grade, horizonte curvo, nós brilhantes ligados por linhas e halos | `src/index.css:199-265`, `src/pages/LoginPage.jsx:7-13` | Cena decorativa de "rede". É a primeira tela que todo mundo vê |
| S3 | Vidro e brilho em cartão | `.card` (`index.css:35-38`): sombra de 50 px e gradiente branco por cima; `.metric-card` (`:384-387`); `drop-shadow` ciano na marca (`PortalLayout.jsx:72`, `LoginPage.jsx:16`); ponto com halo no carregando (`States.jsx:7`); `backdrop-blur` no topo e nos diálogos (6 ocorrências) | Profundidade sem função: nada flutua sobre nada |
| S4 | Ciano sobre ardósia em tudo | 68 usos de `cyan-*` em `src/`. Botão principal, aba ativa, link ativo, rótulo, ícone, avatar e borda de foco no hover, todos ciano (`index.css:49, 88-89, 120-121, 305, 403, 407`) | Paleta padrão de "dashboard escuro". Um acento usado em tudo deixa de marcar o que importa |
| S5 | Rótulo em maiúsculas com espaçamento largo | 25 ocorrências: `.section-eyebrow` (`index.css:304-306`, 0,18em), `.metric-label` (`:390`), cabeçalho de tabela (`:149`), `.status-badge` (`:93`), seções do menu (`PortalLayout.jsx:97`, 10 px a 0,2em), "Portal SDK" acima de todo título (`PortalLayout.jsx:160`) | O "sobretítulo" acima de cada título é a assinatura mais reconhecível de interface gerada. Aqui ele aparece em quase todo cartão |
| S6 | Pílula em maiúsculas para tudo | `.status-badge` com 11 px, negrito e espaçamento (`index.css:92-94`): "PRESENCIAL", "PENDENTE", "EQUIPE N1", "9 TÉCNICOS", "SITUAÇÃO ÀS 10:42" | Situação, contagem, rótulo e metadado no mesmo formato. Nada se distingue |
| S7 | Grade de cartões iguais com ícone em caixa | `MetricCard` (`Primitives.jsx:23-38`). Visão operacional com 8 cartões iguais (`DashboardPage.jsx:9-18`), Aprovações com 4, Funcionários com 4 (um deles "Filtros aplicados 0"), Mapa de PA com 5 (um deles "Permissão: Edição"), Chamados críticos com 4 | Número grande, rótulo em maiúsculas e ícone num quadrado repetidos. Os números menos úteis ganham o mesmo peso dos importantes |
| S8 | Ícone genérico como enfeite | caixa de ícone no `MetricCard`, no `EmptyState` (`States.jsx:22`) e no avatar de iniciais (`Primitives.jsx:40-50`; com os nomes do simulado, todos viram "T0") | Ícone que não ajuda a reconhecer nada, só preenche o canto |
| S9 | Texto de interface explicando o óbvio | 38 `description=` nas páginas e 23 sobretítulos (`eyebrow=`). Exemplos: "Painel de disponibilidade, pausas, aprovações e incidentes críticos da operação SDK." (`DashboardPage.jsx:158`); "Decisões operacionais pendentes, separadas por tipo para reduzir erro e acelerar a triagem." (`AdminPage.jsx:117`); "Timers sincronizados pelo servidor" e "Aptos para atendimento agora" sob os números (`DashboardPage.jsx:10-17`); "Registro central de impacto, resposta, mitigação, causa raiz e resolução." (`CriticalIncidentsPage.jsx:500`) | Frase que vende a tela em vez de servir a quem já está nela. Cada uma toma uma linha da primeira tela |
| S10 | Sinal de "ao vivo" decorativo | "Operação online", fixo e sempre verde (`PortalLayout.jsx:166`), e "Atualização automática ativa" e "Atualizado há 0 segundos" na mesma tela | Indicador que não muda com o estado real perde a confiança de quem olha |
| S11 | Fonte declarada que não chega | `tailwind.config.js:24` pede Inter, mas não há `@font-face` nem arquivo. A tela usa a fonte do sistema (Segoe UI no Windows) | Sem tipografia própria, o que dá o tom é a paleta (S4) |
| S12 | Peso preto (`font-black`) e número gigante | 12 usos: valores de métrica, título "Operação agora", avatar | Ênfase máxima em tudo |
| S13 | Violeta para "Em pausa" | `index.css:505-507` (decisão DV7 do F7) | Violeta é o tom mais associado a interface gerada. Proposta de troca em `DIRECAO_VISUAL.md`, decisão D-R0-4 |

---

## 3. Problemas de UX por tela

### 3.1 Casca (menu lateral, topo, celular)

- **O título se repete:** o topo mostra "PORTAL SDK", o título e uma descrição (`PortalLayout.jsx:160-162`), e a página repete o título com sobretítulo e descrição (Mapa de PA, Chamados críticos, Aprovações). São três linhas de cabeçalho antes do conteúdo.
- **O menu tem 20 itens em 5 grupos,** com rótulos de 10 px em maiúsculas. Num 1280×900 ele rola. O rodapé do menu repete o usuário e mostra "Desenvolvido por" em 11 px.
- **Nenhum contador de pendência no menu:** "Aprovações e admin" não diz que há 3 itens esperando. Só o sino do topo mostra um ponto.
- **No celular,** o menu é uma gaveta com os mesmos 20 itens, e não há atalho para as 3 ou 4 telas de todo dia. O botão de menu e o de fechar têm cerca de 36 px.
- **O topo é `sticky` com `backdrop-blur`:** custo de renderização sem ganho, porque o fundo já é opaco a 95%.

### 3.2 Visão operacional (`capturas/antes/*-dashboard-*`)

- **O que importa está fora da primeira tela.** Em 1280×900, o primeiro quadro é o cartão "Operação agora" (sobretítulo, título, frase, dois indicadores) e oito cartões iguais. A tabela "Situação da equipe agora", que responde "quem está disponível", começa só no pé da tela.
- **O mesmo número aparece três vezes:** "Disponíveis por equipe" no cartão grande, "CIs disponíveis" no cartão pequeno e a coluna Disponíveis da tabela.
- **Não há fila do que precisa de ação.** "Aprovações pendentes 3" é um número sem link; a pausa que passou do limite não se destaca.
- **No celular,** a primeira tela é só o cartão de abertura. O número de disponíveis da equipe fica a quatro rolagens.
- **Tela fixa (TV):** não existe modo para ela. Letra de 11 a 14 px em cinza sobre quase preto não se lê a 3 metros.

### 3.3 Pausas (`capturas/antes/*-pausas-*`)

- **Iniciar uma pausa custa 3 ações:** abrir o seletor, escolher o motivo e confirmar. A rota inicial do técnico já é Pausas (`App.jsx:135`), então essa é a ação mais repetida do portal.
- **Cartão sem função:** "Sessão do colaborador" ocupa metade da largura com o nome e o login de quem está logado, informação que já está no menu.
- **Equipe em cartões de 3 colunas:** o nome é cortado ("Técnico 01 ...") e a jornada quebra em duas linhas. Para ver os 16 da equipe são duas telas de rolagem. Não há filtro "só disponíveis" nem "só em pausa".
- **O tempo de pausa** fica num bloco com rótulo em maiúsculas dentro do cartão. Passar do limite não muda nada na lista.
- **Para a gestão,** "Derrubar pausa" fica dentro de cada cartão com 36 px de altura.

### 3.4 Administração > Aprovações (`capturas/antes/*-admin-1280*`)

- **Cabeçalho de 4 camadas antes do primeiro pedido:** abas, sobretítulo, título com descrição e quatro cartões de contagem. O primeiro pedido começa perto de 500 px do topo, em 1280×900.
- **Cada pedido tem botão "Aprovar" cheio em ciano.** Com cinco pedidos, são cinco botões principais na tela. A ação certa não se destaca da errada.
- **Aprovar custa 3 ações** a partir da Visão operacional: menu, Aprovar e confirmar no diálogo. A confirmação é decisão de segurança e fica; o caminho até ela pode encurtar.

### 3.5 Administração > Funcionários (`capturas/antes/*-admin-funcionarios-*`)

- **O formulário "Adicionar funcionário" toma a primeira tela inteira.** A lista, que serve à tarefa comum (achar alguém e editar), fica abaixo da dobra.
- **Quatro cartões de contagem** antes do formulário, inclusive "Filtros aplicados 0".
- **Texto com nome de configuração interna:** "admin vem só da lista AD_ADMIN_USERS" (`AdminPage.jsx:675` e `:681`). É texto de segurança e só muda com decisão (restrição dos lotes R); fica anotado.
- **Editar custa 4 ações:** a aba, rolar até a lista, Editar no diálogo e Salvar.
- **Correção do responsável (13/11/2026), para o R4d:** o protótipo R0 de Funcionários não tinha o **filtro de Almoço** que a tela real tem desde o deploy 2, com a coluna Almoço na lista. A tela nova do R4d:
  - mantém o filtro e a coluna;
  - já reserva lugar para o **grupo Tools** (a marca no cadastro e o filtro), que vem do módulo de CHGs.

### 3.6 Escala presencial, Mapa de PA e Calendário

- **Escala presencial:** três formulários grandes empilhados (regra, exceção e importação), com explicação longa em cinza pequeno. Ajustar a escala de uma pessoa exige escolher a pessoa duas vezes, uma em cada cartão.
- **Mapa de PA:** cinco cartões de contagem (um deles "Permissão: Edição"), uma legenda de 7 pílulas em maiúsculas e só então o mapa. Em 1280×900, o primeiro PA aparece no pé da tela.
- **Calendário:** eventos de 10 e 11 px (`CalendarPage.jsx:94-103, 314`).

### 3.7 Chamados críticos (`capturas/antes/*-chamados-criticos-*`)

No celular, o título aparece duas vezes, depois três botões e quatro cartões de contagem empilhados (todos com "Indicador operacional filtrado"). A lista começa depois de cinco telas de rolagem.

### 3.8 Estados vazio, carregando e erro (`States.jsx`)

- **Vazio:** o texto padrão "Os registros aparecerão aqui quando forem cadastrados." não diz o que fazer. O ícone em caixa repete o S8.
- **Carregando:** três blocos com animação de varredura (`index.css:176-181`) que não têm a forma do conteúdo que vai chegar, e o ponto com halo. A troca do esqueleto pela tela real pula de altura.
- **Erro:** é o mais completo dos três (mensagem do servidor e "Tentar novamente"). Fica, com o visual novo.
- **Retorno das ações:** o aviso flutuante (`Feedback.jsx`) é bom e foi ajustado no 17f; o título dele em maiúsculas é S5.

---

## 4. Tarefas mais comuns: ações hoje e no protótipo

| Tarefa | Quem | Hoje | No protótipo R0 | Como |
|---|---|---|---|---|
| Iniciar pausa de café ou pessoal | Técnico | 3 (seletor, motivo, confirmar) | 2 (motivo, botão "Iniciar pausa de café") | Os três motivos ficam visíveis, com o limite de cada um ao lado, e o botão diz o que vai acontecer |
| Encerrar a própria pausa | Técnico | 1 | 1 | O botão fica sob o relógio grande, com o tempo restante |
| Ver quem está disponível | Todos | Rolar até a tabela ou abrir Pausas e rolar duas telas | 0 na Visão operacional (número e composição na primeira tela); 1 em Pausas (filtro "Disponíveis") | Lista densa com filtro e contagem |
| Aprovar uma pausa ou reunião | Gestão | 3 a partir da Visão operacional (menu, Aprovar, confirmar) | 3, com o caminho visível: "Precisa de você" na Visão operacional e o contador no menu | O diálogo de confirmação fica (decisão de segurança) |
| Encerrar a pausa de outro técnico | Liderança | 2 (Derrubar pausa, confirmar) | 2, com a linha marcada quando passa do limite | A confirmação fica |
| Ajustar a escala de uma pessoa | Liderança | 5 ou mais | Fora do R0 | Proposta no R5 (`PLANO_LOTES_R.md`) |
| Editar um funcionário | Liderança, admin | 4 (aba, rolar, Editar, Salvar) | 3 (aba, Editar, Salvar), com a lista na primeira tela e a edição num painel lateral | Adicionar vira um botão; o formulário só abre quando pedido |

---

## 5. Acessibilidade (WCAG 2.1 AA)

### 5.1 Contraste de hoje

Calculado sobre as cores do Tailwind e os tokens do `tailwind.config.js`.

| Par | Contraste | AA (texto 4,5:1; borda 3:1) |
|---|---|---|
| Texto principal `slate-100` sobre o fundo | 18,16:1 | passa |
| Descrições `slate-400` sobre o cartão | 7,07:1 | passa |
| **`slate-500`** sobre o cartão: detalhe das métricas, rótulos, jornada | **3,81:1** | **falha** (96 usos de `text-slate-500`) |
| **`slate-600`** sobre o cartão: jornada no cartão de Pausas, "Desenvolvido por" | **2,39:1** | **falha** (12 usos) |
| **`slate-600`** nas seções do menu | **2,51:1** | **falha** |
| **Placeholder `slate-600`** no campo | **2,66:1** | **falha** |
| **Borda do campo** (`white/10`) sobre o cartão | **1,31:1** | **falha** (1.4.11: o limite do campo some) |
| Borda do cartão (`white/8`) sobre o fundo | 1,18:1 | decorativa, mas o cartão depende dela |
| Anel de foco `blue-500` sobre o fundo | 5,41:1 | passa |
| Rótulo do botão principal sobre o ciano | 8,31:1 | passa |
| Situações (texto 200 sobre tom a 10-15%) | 11 a 12:1 | passa |

### 5.2 Foco, teclado e alvo de toque

- **Foco:** o anel global é bom (`index.css:21-27`), visível e com 5,41:1. O hover dos cartões de PA sobe 2 px e muda a borda (`pa-cell`); o foco usa o anel, o que basta.
- **Teclado:**
  - o roteiro de navegador já cobre diálogos, formulário sujo e foco sem destino (17f, BB3);
  - falta um link "Ir para o conteúdo": hoje o Tab passa pelos 20 itens do menu antes do conteúdo;
  - não há atalho para iniciar ou encerrar pausa.
- **Alvo de toque em 390 px:**
  - menu, fechar e sino com `p-2` e ícone de 20 px dão cerca de 36 px;
  - `table-action` com `min-h-8` (32 px);
  - "Derrubar pausa" com `min-h-9` (36 px).
  - Todos abaixo de 40 px.
- **Texto pequeno:** 16 ocorrências de 10 e 11 px (seções do menu, pílulas, cabeçalhos de tabela, eventos do Calendário, sobretítulos).

### 5.3 O protótipo R0 nos mesmos pontos

- **Contraste:** `node prototypes/r0/contraste.mjs` nos 24 pares de cada um dos 4 temas: 0 falhas. A menor relação está na `DIRECAO_VISUAL.md`.
- **Alvo de toque e rolagem lateral:** `node prototypes/r0/capturar.mjs verificar`, em 390 px: 0 alvos abaixo de 40 px e 0 rolagens laterais nas 12 combinações (3 telas, 2 direções, 2 temas).
- **As duas travas pegam o erro:** mutação com 9 mutantes (texto apagado, borda, foco, tema faltando, controle de 36 px, rolagem lateral...), 9 mortos.
- **Foco e texto:**
  - "Ir para o conteúdo" é o primeiro Tab, com 44 px;
  - anel de 2 px em `--focus`, com 3:1 ou mais nos quatro temas;
  - nenhum texto abaixo de 13 px;
  - rótulos em caixa normal.

---

## 6. Prioridades que saem da auditoria

1. **Contraste:**
   - trocar `slate-500` e `slate-600` em texto por tokens com 4,5:1;
   - borda de campo com 3:1;
   - nada abaixo de 13 px.

   É o único item com falha de norma; entra no R1 (tokens).
2. **Primeira tela de cada tarefa:**
   - Visão operacional com a situação da equipe no topo;
   - Pausas com a escolha visível e a lista densa;
   - Funcionários com a lista primeiro.
3. **Tirar os sinais S1 a S12,** que somem quase todos com os tokens e os primitivos novos (R1 e R2), sem tocar em regra.
4. **Casca:**
   - um título por tela;
   - contador de pendência no menu;
   - atalhos no celular;
   - link "Ir para o conteúdo" (R3).
5. **Estados vazio e carregando** com a forma do conteúdo e uma ação (R2).
