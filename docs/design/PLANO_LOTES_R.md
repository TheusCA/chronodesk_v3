# Plano dos lotes do redesenho (R1 em diante)

**Estado:** aprovado em 2026-11-13, com a direção A e as decisões D-R1-1 a D-R1-3 como recomendadas. R1 e R2 em execução, nessa ordem, com uma parada para revisão no fim do R2. **Cada lote para para revisão.**

## Regras de todos os lotes R

1. **Só apresentação e fluxo de tela.** Nenhuma mudança de regra de negócio, de API, de permissão ou de texto de segurança. Uma ideia que precise de qualquer um deles vai para o backlog (`BENCHMARK_IDEIAS.md`) e segue o fluxo próprio.
2. **CSP do portal** (`style-src 'self'`, `script-src 'self'`, `font-src 'self'`):
   - nada de CDN, Google Fonts ou script externo;
   - fonte só em woff2 no repositório, com licença OFL registrada e `font-display: swap`.
3. **Nenhuma dependência nova sem aprovação,** nem de ícone nem de animação.
4. **Movimento:**
   - só cor e pressão, como na direção;
   - `prefers-reduced-motion` respeitado (a regra global do `index.css:468-477` fica).
5. **Os `data-*` e os papéis usados pelos QAs ficam.**
   - Teste que mudar de expectativa leva a justificativa caso a caso, no commit e no registro.
   - Os 1029 checks do navegador e os 22 scripts do frontend continuam passando.
6. **Acessibilidade:** contraste AA nos dois temas, conferido por QA; foco visível; alvo de 40 px ou mais em 390 px.
7. **Nada de dado real** em captura, protótipo ou documento.
8. **Validação:**
   - a bateria completa (`test-local.ps1 -SkipHttp -Browser`) roda sozinha em cada commit;
   - mutação nas travas novas, com o `mut_guard.php`;
   - capturas antes e depois pelo `frontend/prototypes/r0/capturar.mjs antes`, que fotografa o app pelo servidor simulado.
9. **Um commit de entrega e um de docs por lote.** Sem push pela IA.
10. **As telas novas do F10 e das CHGs** são feitas já nos componentes novos. Nenhuma tela nova usa `.card`, `.status-badge` nem `MetricCard`.

**Risco comum a todos:** cada lote muda o visual para todos os usuários. Vai junto com um aviso curto à gestão no roteiro do deploy, como no deploy 1.

**Linha de base do build** (13/11/2026, no `fdefa83`):
- JS de entrada: 201 KB (63 KB gzip);
- CSS: 55 KB (9,9 KB gzip);
- 23 arquivos JS, 626 KB no total (180 KB gzip).

---

## R1 · Tokens, tipografia e limpeza do fundo

**O que entra:**
- **Tokens da direção como variáveis CSS** em `:root`, para os dois temas:
  - o tema segue `prefers-color-scheme`, mais um botão no topo que vale na sessão (sem `localStorage`, que o `qa:visual` proíbe);
  - guardar a escolha na conta do usuário pede API: fica para decisão (D-R1-1).
- **O `tailwind.config.js` lê os tokens:**
  - cores semânticas (`bg`, `surface`, `ink`, `muted`, `line`, as situações) no lugar de `chrono.*`;
  - fonte IBM Plex Sans e Plex Mono.
- **Fontes em `frontend/src/assets/fontes/`,** que o Vite serve de `/app/assets/` (`font-src 'self'`), com o registro OFL.
- **Sai a camada decorativa** (sinais S1 a S3 e S10 a S12 da auditoria):
  - a grade e o gradiente do fundo;
  - o fundo do login;
  - o vidro, a sombra e o brilho dos cartões;
  - o halo do carregando;
  - o `backdrop-blur`;
  - o "Operação online" fixo.
- **Contraste:** `slate-500` e `slate-600` em texto viram `--muted`; a borda de campo vira `--line-strong`; nada abaixo de 13 px.
- **Cores das situações** pela D-R0-4: as classes `presence-*` e as variantes de tom continuam com o mesmo nome, só muda a cor.

**Arquivos:** `src/index.css`, `tailwind.config.js`, `index.html` (`color-scheme`), `src/pages/LoginPage.jsx` (sai o enfeite), `src/components/PortalLayout.jsx` (só classes), `src/assets/fontes/*`.

**Testes afetados:**
- **`qa:visual`:** travas novas para nenhum `cyan-*` nem `slate-500`/`slate-600` em texto, nenhum `text-[10px]`/`text-[11px]`, nenhum `uppercase` com `tracking`, `@font-face` com `font-display: swap` e arquivo presente;
- **`qa:calendar-presence`:** compila o CSS e confere as classes `presence-*`; o nome fica, a cor muda;
- **`qa:bundle`:** ganha o orçamento que hoje não existe:
  - JS de entrada até a linha de base mais 5%;
  - CSS até 70 KB;
  - fontes até 100 KB;
- **QA novo de contraste:** a lógica do `prototypes/r0/contraste.mjs`, lendo os tokens do `index.css`;
- **navegador (1029):** sem mudança esperada (nenhum `data-*`, papel ou texto muda).

**Mutação:** reintroduzir um `cyan-400`, um `uppercase tracking-[0.18em]`, um `text-[11px]`, uma cor de texto com 3:1, o `@font-face` sem `swap` e um JS acima do orçamento. Cada um tem de reprovar.

**Risco:** médio. Muda a cara de todas as telas de uma vez, mas não muda estrutura. **Rollback:** reverter o commit e fazer o build.

**Estimativa:** 2 commits (tokens e fontes; limpeza e contraste) e 1 de docs.

## R2 · Primitivos

**O que entra:**
- **Componentes em `src/components/ui/`,** cada um com os estados (normal, hover, foco, desabilitado, carregando):
  - `Button` (principal, secundário, discreto, perigo);
  - `Field` (rótulo acima, ajuda, erro sob o campo);
  - `Table` (densa, linha de grupo, modo cartão em 390 px);
  - `Tag` (situação com ícone e rótulo);
  - `Segmented` (filtro com contagem);
  - `Drawer` (painel lateral, sobre o `Dialog` que já existe);
  - `Toast` (o `Feedback.jsx` de hoje, sem maiúsculas);
  - `EmptyState`, `LoadingState` (esqueleto com a forma do conteúdo) e `ErrorState`.
- **As classes de hoje** (`.btn-primary`, `.field`, `.data-table`, `.status-badge`...) passam a desenhar o novo visual. As páginas mudam o mínimo.
- **O `MetricCard`** fica só onde o número é a informação. Os cartões "Filtros aplicados" e "Permissão" saem (S7).

**Testes afetados:**
- `qa:table`, `qa:ux-hardening`, `qa:action-feedback` (texto e papel do aviso ficam), `qa:forms` e `qa:employee-form` (rótulo e erro sob o campo);
- navegador: diálogos, foco sem destino, trava de rolagem e aviso acima dos diálogos (BB1 a BB3, 17f e 17g) ficam como estão;
- o `Drawer` reaproveita o `Dialog`.

**Mutação:** o `Tag` sem rótulo (só cor); o `Button` com alvo de 36 px em 390 px; o `Drawer` sem devolver o foco; o esqueleto sem `role="status"`.

**Risco:** médio. **Rollback:** reverter. **Estimativa:** 3 commits e 1 de docs.

## R3 · Casca do app

**O que entra:**
- **Menu lateral:**
  - grupos em caixa normal;
  - contador de pendências em "Aprovações e admin", pelo `solicitacoes_pendentes.php`, que já existe (hoje chamado pela Administração e pela Visão operacional). Mais uma chamada por tela: medir o custo no polling antes (a folha do deploy já mede);
  - item atual marcado com barra.
- **Topo:** um título só (sai o "PORTAL SDK" e a descrição), o relógio do servidor, o tema, o sino e sair.
- **Link "Ir para o conteúdo"** como primeiro Tab.
- **No celular:** a barra de atalhos com 4 itens (D-R0-7) e o menu em folha inteira.
- **A página deixa de repetir o título do topo** (Mapa de PA, Chamados críticos, Aprovações).

**Testes afetados:**
- navegador: a navegação com formulário sujo (`guardLeave`, `qa:unsaved-changes`) e o foco do título (`data-page-title` fica);
- `qa:visual` (rotas e metadados ficam).

**Mutação:**
- o atalho do celular sem `guardLeave`;
- o contador com o nome de quem pediu (só o número pode aparecer);
- o "Ir para o conteúdo" fora do primeiro Tab.

**Risco:** médio a alto, porque a guarda do formulário sujo passa por aqui. **Rollback:** reverter. **Estimativa:** 2 commits e 1 de docs.

## R4 · Telas, pela ordem de uso

Cada tela é um commit com as capturas antes e depois. Os QAs e os `data-*` dela ficam.

| Lote | Tela | O que muda (protótipo R0 e auditoria) | Testes que olham a tela | Risco |
|---|---|---|---|---|
| R4a | Pausas | Escolha visível com um botão que diz a ação (D-R0-5); lista densa com filtro; tempo restante e estouro destacado | `qa:operational-view`, `qa:action-feedback`, navegador (bloco 13, derrubar pausa, DD3) | Médio: tela mais usada |
| R4b | Visão operacional e modo painel | Disponíveis e composição na primeira tela; "Precisa de você"; pausas em andamento; sai o cartão de abertura e os 8 cartões iguais; modo painel para a tela fixa | `qa:operational-view`, `qa-operational-view.php` (contadores e "—" ficam) | Médio |
| R4c | Aprovações | Lista com um botão principal por vez (o pedido em foco); contador por tipo como filtro; a confirmação fica | navegador (Aprovar e Rejeitar, 17g; já decidido em outra aba) | Médio |
| R4d | Funcionários | Lista na primeira tela; Adicionar e Editar no painel lateral (D-R0-6). Mantém o filtro e a coluna de Almoço do deploy 2 e reserva lugar para o grupo Tools (marca no cadastro e filtro, do módulo de CHGs): correção do responsável de 13/11/2026 (`AUDITORIA_UX.md`, 3.5) | `qa:employee-form` (inclui o filtro de Almoço), navegador (bloco 4, editar e adicionar) | Médio |

## R5 · Escala, Mapa de PA e ausências

| Tela | O que muda | Testes |
|---|---|---|
| Escala presencial | Escolher a pessoa uma vez e ver a regra e as exceções dela num painel; importação numa aba própria | `qa:schedule-form`, `qa:schedule-rules`, navegador (blocos 6, 19 e 21) |
| Mapa de PA | O mapa na primeira tela; legenda compacta; sai o cartão "Permissão" | `qa:pa-map`, navegador (blocos 14, 16, 17 e 20) |
| Ausências | Mesmo painel lateral | `qa:absences-form`, navegador (bloco 5) |

**Ponto de encontro com o F10:** as telas do F10-2 (times de rodízio, editor de ciclo) e do F10-5 (escala do mês) nascem nos primitivos do R2. Se o F10-2 vier antes do R2, a tela de times fica mínima e passa para os primitivos junto com o R5.

## R6 · O resto

- **Calendário:** eventos com 13 px ou mais.
- **Chamados críticos:** lista primeiro e contadores como filtro.
- **Documentação, Relatórios, Métricas, Configurações, Integrações, Avisos, Plantonistas, Sobreavisos, Horas extras e Correção de ponto:** só primitivos e casca, um commit por grupo.
- **Login:** o formulário sobre o fundo neutro, sem a cena.

## Ordem proposta em relação aos outros trabalhos

1. **R1 e R2 primeiro:** não dependem de nada e dão a base às telas novas.
2. **R3.**
3. **Depois, intercalado com o F10 e o módulo de CHGs:**
   - **R4a a R4d,** um por intervalo entre lotes;
   - **R5,** junto com as telas do F10;
   - **R6** por último.
4. **O F8 e o F9, quando a Liderança responder,** entram no próximo intervalo, como já está combinado.
5. **Deploy:** os lotes R são só frontend (`npm ci` e build) e podem ir juntos no deploy seguinte, com o aviso à gestão. Nenhum precisa de migration.

## Decisões que o plano levanta (aprovadas em 2026-11-13, como recomendadas)

| # | Decisão | Recomendação |
|---|---|---|
| D-R1-1 | Guardar o tema escolhido | Seguir o sistema e manter a escolha na sessão. Guardar na conta pede API: fica para depois, com desenho próprio |
| D-R1-2 | Orçamento do bundle no `qa:bundle` | JS de entrada até a linha de base mais 5%; CSS até 70 KB; fontes até 100 KB |
| D-R1-3 | Onde mora a fonte | `frontend/src/assets/fontes/`, servida pelo Vite em `/app/assets/` (mesma origem) |

---

## Execução

### R1 · Tokens, tipografia e limpeza do fundo (13/11/2026)

**Commits:** `af453f7` (tokens, dois temas e fontes) e `00651ff` (tipografia e orçamento do build), mais um de docs. Sem push.

**O que mudou na divisão dos commits:**
- O plano punha a limpeza do fundo no segundo commit. Ela foi para o **primeiro**: a grade, o gradiente, a cena do login, o vidro e o brilho estavam escritos na paleta do Tailwind que o primeiro commit tira (uma classe fora dos tokens não gera CSS).
- O segundo ficou com a tipografia, as travas de texto e o orçamento do `qa:bundle`.

**Commit 1 (`af453f7`):**
- **Tokens** no `index.css`, em canais R G B, em três blocos:
  - claro, que é o padrão;
  - escuro pela preferência do sistema;
  - escuro pelo botão do topo, guardado só na sessão da aba (`src/lib/theme.ts`, D-R1-1).
- **`tailwind.config.js` só com tokens.** A paleta padrão sai, e as 613 classes de cor das telas foram trocadas por uma tabela única:
  - `text-white` vira `text-ink`; `slate-200`/`300` viram `ink-2`; `slate-400` a `600` viram `muted`;
  - ciano e azul viram `info`; verde vira `ok`; âmbar vira `warn`; vermelho vira `danger`.
- **D-R0-4:** as classes `presence-*` mantêm o nome, e só a cor muda.
- **Calendário:**
  - a ausência fica no vermelho de ausente, o mesmo da legenda;
  - o War Room fica no vermelho de erro com a borda cheia;
  - a escala de antes do corte fica no azul do remoto;
  - a hora extra fica só com o contorno azul.
- **Fontes:** Plex Sans 400, 500 e 600 e Plex Mono 500 em `src/assets/fontes/` (86 KB, OFL, swap). O registro está no README de lá.

**Commit 2 (`00651ff`):**
- **Tamanho e peso:** `text-xs` passa a 13 px, o corpo a 15 px e os pesos ficam em 400, 500 e 600.
- **Sem maiúsculas espaçadas:** saem os `uppercase` e os `tracking` largos (sinais S4 e S5).
- **Topo:** sai o "Operação online" fixo (S12).

**QA novo e travas:**
- **`qa:contrast` (novo; a bateria passa de 22 para 23 scripts npm):**
  - os três blocos de tokens, com os dois escuros iguais;
  - o tailwind sem cor solta;
  - 31 pares AA em cada tema;
  - o comportamento do tema: sem escolha, segue o sistema; a escolha fica na sessão; o armazenamento bloqueado é tolerado.
- **`qa:visual`:**
  - nenhuma classe fora dos tokens e nenhuma cor solta no CSS ou no código;
  - fontes: só as aprovadas, woff2 local, com swap e licença;
  - texto ≥ 13 px, sem `uppercase` e sem `tracking` largo;
  - peso ≤ 600;
  - sem "Operação online".
- **`qa:bundle`:** orçamento (D-R1-2). JS de entrada até 211 050 bytes, CSS até 70 KB, fontes até 100 KB. No `00651ff`: 197 638, 52 874 e 85 912 bytes.

**Expectativas que mudaram (justificativa por caso):**

| QA | O que mudou | Por quê |
|---|---|---|
| `qa:absences-form`, `qa:operational-view`, `qa:pa-map`, `qa:schedule-rules`, `qa:unsaved-changes`, `qa:calendar-presence` (texto) | A classe de cor esperada no HTML: `border-white/5` → `border-line`, `text-slate-400` → `text-muted`, `ring-red-400/60` → `ring-danger` e semelhantes | Só a classe: a estrutura conferida é a mesma |
| `qa:unsaved-changes` | Fundo dos diálogos sem `backdrop-blur-sm`; painel lateral `bg-surface`; contagem de `qa:*` de 19 para 20 | Vidro saiu (S3); entrou o `qa:contrast` |
| `qa:unsaved-changes`, `qa:schedule-rules` (commit 2) | Título dos diálogos e das regras com `font-semibold` | O peso 700 deixou de existir |
| `qa:employee-form` | A linha de confirmação do almoço era achada pela classe `bg-slate-950/40`; agora pelo `colSpan`: toda célula com `colSpan` na lista ocupa as 7 colunas | Com o token, a classe `bg-surface-2` aparece em outras partes da lista |
| `qa:visual` | Fundo da coluna fixa pelo token `--surface` (opaco nos três blocos), no lugar de `theme('colors.chrono.card')` | A cor `chrono` saiu |
| `qa:calendar-presence` (cores) | As cores são resolvidas pelos tokens nos dois temas (antes, rgb fixo e só o escuro). Matiz da D-R0-4: pausa 25° a 50°, almoço 160° a 200°, ausente até 15°. Almoço × ausente separados pelo matiz (≥ 90°), e não pela claridade do fundo | A regra mudou com a D-R0-4; a garantia (não confundir almoço com ausente) continua, por outro caminho |

**Validação:**
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`), sozinha no worktree:
  - `af453f7`: saída 0, frontend 23/23, navegador 1029/1029;
  - `00651ff`: saída 0, frontend 23/23, navegador 1029/1029.
- **Mutação** com o `mut_guard.php`:
  - commit 1: 21/21 mortos (contraste nos dois temas, blocos e tokens, cor solta, matiz e contraste das situações, classes fora dos tokens, fontes, coluna fixa, tema na sessão, botão de tema);
  - commit 2: 12/12 mortos (texto de 11 px, maiúsculas e espaçamento por classe e por CSS, peso 700 na config, numa tela e no CSS, `text-xs` de 12 px, "Operação online" de volta, e os três orçamentos do build, cada um reprovado pelo próprio orçamento e não por erro de build).
- **Contraste dos tokens do app,** menor valor de texto em cada tema:
  - claro: `--muted` sobre `--surface-2`, 5,16:1 (borda de campo sobre `--surface-2`, 3,20:1);
  - escuro: `--muted` sobre `--surface-2`, 5,75:1 (borda de campo, 3,40:1).
  - Hoje, antes do R1: `slate-500` 3,81:1, `slate-600` 2,39:1 e borda de campo 1,31:1 (auditoria, 5.1).
- **Medição em 390 px** (`prototypes/r0/capturar.mjs antes --medir`, nos dois temas):
  - 0 rolagens laterais;
  - 69 alvos abaixo de 40 px, os mesmos de antes: o botão do menu, as abas da Administração, as ações de tabela, Aprovar e Rejeitar, Derrubar pausa e as setas do Calendário.
  - Eles são do R2, que leva o alvo de 40 px para os primitivos.

**Capturas:**
- **"Antes":** as do R0, `capturas/antes/<perfil>-<tela>-<largura>.webp`, só o escuro, porque o app só tinha ele. O código das telas não mudou entre o R0 e o R1.
- **"Depois", no git:** `capturas/r1/<perfil>-<tela>-<largura>-<tema>.webp`, 40 arquivos (1,2 MB):
  - o login;
  - as 8 telas principais do admin;
  - Pausas do técnico;
  - em 1280 e 390 px, claro e escuro.
- **Capturas do commit intermediário** (`af453f7`, as mesmas 40): ficaram fora do git, só na estação, como prevê a D-R0-9 para o que não é o fim do lote.

**Movido para os lotes das telas:**
- **"Filtros aplicados":** sai no R4d, junto com a tela de Funcionários. A contagem dele é o que o `qa:employee-form` usa para conferir o filtro de Almoço do deploy 2; ela sai junto com a fileira de filtros nova, que mostra o filtro ativo.
- **"Permissão" do Mapa de PA:** sai no R5, onde o plano já o tirava também.
- **O texto de permissão** ("só Admin e Liderança editam o mapa") fica, sem mudar uma palavra, como linha da tela.

### R2 · Primitivos (13/11/2026)

**Commits:** `936d866` (botão, selo e filtro segmentado, alvo de 40 px), `6b6b209` (campo, tabela e painel lateral), `3365fc7` (aviso e estados) e um de docs. Sem push.

**Componentes novos em `src/components/ui/`:**

| Componente | O que faz | Onde já está |
|---|---|---|
| `Button` | Principal, secundário, discreto e perigo sobre as classes `.btn-*`; `type="button"` por padrão; ocupado com `aria-busy`, desabilitado e o rótulo de envio; compacto (`btn-sm`) só encolhe de 640 px para cima | As classes `.btn-*` de todas as telas já desenham o botão novo; Aprovar, Rejeitar e Derrubar pausa usam o compacto |
| `Tag` | Selo de estado ou de situação, sempre com rótulo e, quando há, o ícone da legenda | A classe `.status-badge` de todas as telas já desenha o selo novo |
| `Segmented` | Filtro com contagem, grupo com nome, escolha em `aria-pressed` | Telas do R4 (Pausas, Visão operacional), do F10 e das CHGs |
| `Field` | Rótulo acima (com `for`), ajuda e erro abaixo ligados por `aria-describedby`; com erro, `aria-invalid`, ícone e texto | Formulários novos; os de hoje mudam na tela de cada um (R4 a R6). A classe `.field` já tem 40 px e borda de erro |
| `Table` | Densa, cabeçalho com `scope`, linha de grupo e modo cartão abaixo de 640 px | Telas novas; a classe `.data-table` de hoje já ficou mais densa |
| `Drawer` | Painel lateral (D-R0-6) sobre o `Dialog` do 17f: foco preso, Esc, trava de rolagem e foco de volta vêm dele; ações fixas no rodapé | R4d (Funcionários), F10 e CHGs |
| Aviso (`Feedback.jsx`) | Fundo do cartão, barra e ícone na cor do tipo, título escrito, fechar com 40 px; regiões vivas e posição do 17f como estavam | Todas as telas |
| `LoadingState`, `EmptyState`, `ErrorState` | Esqueleto com a forma do conteúdo (`shape`: cartões, tabela ou lista), vazio com o próximo passo (`action`), erro com `role="alert"` e "Tentar novamente" | Todas as telas (a forma padrão continua cartões) |

**Alvos de toque em 390 px:** corrigidos os que a medição do R1 achou, pelos primitivos:
- as classes `.btn-*` e `.btn-sm`, `.table-action`, `.tab-button` e `.segmented-option` com 40 px fora de `@media`;
- Aprovar, Rejeitar e Derrubar pausa sem o `min-h-9`;
- as setas do Calendário com 40 px de largura;
- abrir e fechar o menu e fechar a prévia de documento com `p-2.5`;
- fechar o aviso com 40 px.
- **Medição no fim do R2** (`capturar.mjs antes --plano=r --medir`, nos dois temas): **0 alvos abaixo de 40 px e 0 rolagens laterais** nas 10 telas em 390 px (no R1, 69 por tema). O último foi o rótulo "Ocultar sem escala" da Escala presencial, que passou a ter 40 px; a medição passou a aceitar caixa de marcar dentro de rótulo com 40 px, como já aceitava o rádio.

**O que o plano previa e mudou:**
- **Os cartões "Filtros aplicados" e "Permissão"** saem no R4d e no R5 (seção do R1, acima).
- **As telas não trocaram as classes pelos componentes.** As classes de hoje (`.btn-*`, `.status-badge`, `.field`, `.data-table`) passaram a desenhar o visual novo, como o plano pedia ("as páginas mudam o mínimo"). A troca acontece tela a tela, nos lotes R4 a R6.

**QA:**
- **`qa:primitives` (novo; a bateria passa de 23 para 24 scripts npm):**
  - cada componente desenhado com o React do projeto (`react-dom/server`), nos seus estados e ligações de acessibilidade;
  - o CSS gerado com a configuração do projeto, com a altura mínima de 40 px de cada controle fora de `@media`.
- **Expectativa que mudou:** `qa:unsaved-changes`, a contagem de `qa:*` de 20 para 21, porque entrou o `qa:primitives`. Nenhuma outra expectativa mudou: os `data-*`, os papéis e os textos são os mesmos.

**Validação:**
- **Bateria completa,** sozinha no worktree, em cada commit:
  - `936d866`, `6b6b209` e `3365fc7`: saída 0 nos três, frontend 24/24 (lint, typecheck, build e 21 `qa:*`), navegador 1029/1029;
  - o commit de docs do R1 (`1609e98`): saída 0, 23/23 e 1029/1029.
- **Mutação** com o `mut_guard.php`:
  - commit 1: 14/14 mortos (Tag sem rótulo ou com tom solto, Button sem `type`, ocupado clicável ou sem `aria-busy`, os 40 px de botão, compacto, ação de tabela, aba e filtro, o grupo e a escolha do Segmented, Aprovar e Derrubar pausa com 36 px);
  - commit 2: 14/14 (Field sem as ligações de ajuda e erro, sem `for`, sem `aria-invalid` ou com erro só na borda; campo com 36 px e sem borda de erro; Table sem `scope`, sem rótulo da coluna, grupo de uma coluna e cartões com largura mínima; Drawer sem devolver o foco, fechar sem nome e ações que rolam);
  - commit 3: 10/10 (esqueleto sem `role="status"`, lido pelo leitor de tela ou vazio; erro sem `role="alert"`; vazio sem o próximo passo; aviso sem título, sem a barra, fechar com 24 px, erro na região educada; "Ocultar sem escala" com 20 px).
- **Orçamento no `3365fc7`:**
  - JS de entrada 197 893 de 211 050 bytes;
  - CSS 59 625 de 70 000;
  - fontes 85 912 de 100 000.

**Capturas:**
- **"Antes":** as do R1, em `capturas/r1/`.
- **"Depois", no git:** `capturas/r2/`, o mesmo plano (login, 8 telas do admin e Pausas do técnico, 1280 e 390 px, claro e escuro).
  - São 40 arquivos, 1,3 MB.
- **Capturas dos commits intermediários** (`936d866` e `6b6b209`): fora do git (D-R0-9).
