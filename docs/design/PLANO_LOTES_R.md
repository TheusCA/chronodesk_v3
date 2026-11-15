# Plano dos lotes do redesenho (R1 em diante)

**Estado:** aprovado em 2026-11-13, com a direção A e as decisões D-R1-1 a D-R1-3 como recomendadas. R1 e R2 aprovados em 2026-11-14; R3, R4a e R4b aprovados em 2026-11-14 (D-R4-1 a D-R4-3). R4c e R4d em execução, com uma parada para revisão no fim do R4d e do roteiro do deploy 3, que leva o redesenho. **Cada lote para para revisão.**

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

### R3 · Casca do app (14/11/2026)

**Commits:** `8dec4a9` (topo, marca, iniciais, "Ir para o conteúdo" e contador) e `8348df8` (atalhos do celular e páginas sem repetir o título), mais um de docs. Sem push. O R1 e o R2 não vão sozinhos: o deploy do redesenho fica para depois do R4 completo (decisão do responsável de 14/11/2026).

**Ajustes do responsável para o R3 (14/11/2026):**

| # | Pedido | Como ficou |
|---|---|---|
| 1 | Marca do topo e do login sem imagem raster | `BrandMark` em texto ("SDK" no quadrado de ação, que segue o tema), com "Portal SDK" escrito ao lado. Nada fora de `/app/assets/` |
| 2 | Iniciais pela primeira letra do primeiro e do último nome, sem número | `src/lib/initials.ts`, usado pelo `UserAvatar` e pelo rodapé do menu. "Técnico 01 Exemplo" vira "TE" (antes "T0") |
| 3 | "Desenvolvido por Matheus Camargo" no rodapé do menu e do login | Mantido nos dois; o `qa:shell` e o `qa:action-runner` conferem |

**Como o logo é servido em produção hoje** (conferido no repositório, sem acesso ao servidor):
- o arquivo é `frontend/public/sdk-icon.png` (versionado, PNG de 256 × 256, 100 KB);
- o Vite copia o `public/` para a raiz do build, e o arquivo vai para `app/sdk-icon.png`;
- no Apache, ele é arquivo de verdade: a regra do SPA (`.htaccess`, `RewriteCond %{REQUEST_FILENAME} !-f`) não o reescreve para o `index.html`;
- cai no ramo sem cache do `.htaccess` (tudo em `/app/` fora de `/app/assets/` leva `no-store`), e a CSP o permite (`img-src 'self'`);
- em produção ele carrega; quebrava só no servidor simulado, que serve apenas `/app/assets/` (auditoria, seção 1).
- **Depois do R3, nada do `src/` usa o `sdk-icon.png`.** O arquivo continua no `public/` e no build (100 KB copiados a cada deploy). Proposta: tirar numa limpeza à parte, com a sua aprovação. O `favicon.png` e o `apple-touch-icon.png` continuam em uso pelo `index.html`.

**Commit 1 (`8dec4a9`):**
- **Topo com um título só.** Sai o "Portal SDK" de sobretítulo e a descrição da tela (D-R0-8). Ficam:
  - o relógio, agora com a hora do servidor (a diferença é acertada a cada `status.php`), só hora e minuto, com o rótulo "Servidor";
  - o tema, o sino e Sair, que continua passando pela guarda do formulário sujo.
- **"Ir para o conteúdo"** é o primeiro Tab. Leva o foco ao `<main id="conteudo">` sem mudar o endereço: um `#conteudo` no endereço dispararia o `popstate`, que o roteador trata como navegação.
- **Menu:** item atual com `aria-current` e a barra; grupos em caixa normal (desde o R1).
- **Contador de pendências em "Aprovações e admin":**
  - soma pausas e reuniões, horas extras e correções de ponto, pela mesma resposta do `solicitacoes_pendentes.php` que a aba Aprovações usa;
  - mostra **só o número** ("N+" quando a lista vem cortada pelo limite do servidor); nome, equipe e observação de quem pediu nunca chegam ao menu;
  - fica fora do botão, ligado como descrição (`aria-describedby`): o nome do item continua só o rótulo;
  - só para quem tem `operacao.approve`, que é quem o endpoint aceita;
  - busca a cada 60 s com a aba visível e a cada troca de tela.
- **Custo da chamada nova: não medido aqui** (sem MySQL local). O `scripts/medir-polling.sh` aceita outra URL. Proposta para o roteiro do deploy do redesenho, com uma sessão de gestão, antes de liberar: `URL=http://127.0.0.1/api/solicitacoes_pendentes.php N=50 bash scripts/medir-polling.sh`.

**Commit 2 (`8348df8`):**
- **Barra de atalhos no celular** (abaixo de 640 px, D-R0-7): Agora (Visão operacional), Pausas (só com `pausas.use`, a mesma permissão do menu), Escala (Escala presencial) e Menu.
  - O alvo tem 64 px, e o atalho atual leva `aria-current`.
  - Cada atalho passa pelo `navigate` do roteador, com a guarda do formulário sujo do 17f.
- **Menu do celular em folha inteira** abaixo de 640 px. De 640 px para cima, fica a coluna de 320 px.
- **O conteúdo ganha folga embaixo** no celular, para não ficar atrás da barra. O aviso sobe acima dela; com um diálogo aberto, continua valendo a regra do BB1.
- **A página deixa de repetir o título do topo:**
  - Mapa de PA: a frase da regra do conflito fica, com as mesmas palavras;
  - Chamados críticos: ficam só as ações;
  - Aprovações: o total continua no cartão "Total pendente".

**O que mudou em relação ao plano e ao protótipo:**
- **Sair fica no topo,** como o plano pede. No protótipo ele estava no rodapé do menu.
- **O atalho "Escala" abre a Escala presencial.** A de sábado continua no menu.
- **O texto de apresentação do login** ("Operações, métricas e aprovações em um único lugar.") fica para o R6, que redesenha o login. No R3 só a marca mudou.

**QA:**
- **`qa:shell` (novo; a bateria passa de 21 para 22 `qa:*`).** Desenha o `PortalLayout` e o login de verdade e confere:
  - o primeiro focável e o destino do "Ir para o conteúdo";
  - um `<h1>` só, sem sobretítulo nem descrição;
  - a marca sem imagem e o crédito;
  - o contador: só o número, fora do botão, como descrição, sem nomes, e só para quem decide;
  - o item atual e o relógio pela hora do servidor;
  - as iniciais: nome composto, acento, uma palavra, número e login com ponto;
  - a barra de atalhos (itens, permissão, alvo de 64 px fora de `@media`, `goTo` pelo roteador), a folha inteira e as páginas sem o título repetido.
- **Roteiro do navegador, bloco 22 (novo, 17 conferências):**
  - primeiro Tab e Enter, sem mudar o endereço;
  - o contador só com o número e, na árvore de acessibilidade do Chrome, o rótulo como nome e "5 pendentes" como descrição;
  - os atalhos e a folha em 390 px;
  - com o formulário sujo, o atalho pergunta; Continuar fica; Descartar sai sem POST.
  - **As 1029 conferências de antes passam sem mudança; o total vai a 1046.**

**Expectativas que mudaram:**

| QA | O que mudou | Por quê |
|---|---|---|
| `qa:unsaved-changes` | Contagem de `qa:*` de 21 para 22 | Entrou o `qa:shell` |
| `qa:contrast` | O relógio do topo é `<Clock serverNow={serverNow} />`; o botão de tema continua logo depois dele | O relógio passou a mostrar a hora do servidor |

**Validação:**
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`), sozinha no worktree:
  - `8dec4a9`: saída 0, frontend 25/25 (lint, typecheck, build e 22 `qa:*`), navegador 1029/1029;
  - `8348df8`: saída 0, frontend 25/25, navegador 1046/1046.
- **Mutação** com o `mut_guard.php`, cada mutante morto pela conferência certa:
  - commit 1: 15/15 ("Ir para o conteúdo" depois do menu ou mudando o endereço; contador com o nome de quem pediu, dentro do botão, buscado para todos ou sem o "+"; "Portal SDK" e a descrição de volta no topo; marca de volta como imagem; relógio pela hora do navegador; iniciais com número, pelas duas primeiras palavras ou sem acento; sem o crédito; item atual sem `aria-current`);
  - commit 2: 11/11 no `qa:shell` (atalho sem a guarda, sem Pausas ou sem a permissão dela, com 40 px, sem `aria-current`; menu de volta à coluna; conteúdo sem folga; as três páginas repetindo o título; o Mapa de PA sem a regra do conflito) e 3/3 no navegador (atalho sem a guarda, atalho com 40 px, menu em coluna).
- **Medição em 390 px** (`capturar.mjs antes --plano=r --medir`, nos dois temas): 0 alvos abaixo de 40 px e 0 rolagens laterais.
- **Orçamento no `8348df8`:**
  - JS de entrada: 199 670 de 211 050 bytes;
  - CSS: 63 626 de 70 000 (no R2, 59 625);
  - fontes: 85 912 de 100 000.

**Capturas:**
- **"Antes":** as do R2, em `capturas/r2/`.
- **"Depois", no git:** `capturas/r3/`, o mesmo plano (login, 8 telas do admin e Pausas do técnico, 1280 e 390 px, claro e escuro). São 40 arquivos, 1,2 MB.
- **O servidor das capturas** (`capturar.mjs`) passou a pôr o café do técnico 04 às 10:18, como no protótipo: o estouro (24 min com limite de 20) aparece nas capturas do R3 em diante. No R2, o café era às 10:31.

### R4a · Pausas (14/11/2026)

**Commits:** `9711d2b` (escolha visível e lista densa), duas correções achadas pelas capturas (`d16c9a8` e `906ae83`), a trava da segunda com dados de lista cheia (`a584367`) e um de docs. Sem push.

**O que entrou (protótipo aprovado, D-R0-5):**
- **"Sua pausa", sem pausa:**
  - os três motivos visíveis, como escolha (rádio), cada um com o limite ao lado;
  - o limite do café vem do servidor (`duracao_limite_segundos`, a configuração de pausas), não fica fixo; sem o campo, os 20 min de antes;
  - um botão que diz a ação: "Iniciar pausa de café", "Iniciar pausa pessoal" ou "Pedir aprovação da reunião". **Duas ações no lugar de três** (o seletor saiu);
  - reunião continua pedindo o contexto (obrigatório, até 500 caracteres);
  - a regra das pausas fica, com as mesmas palavras ("Café usa limite próprio; reunião segue o fluxo de aprovação configurado.").
- **"Sua pausa", em andamento:**
  - relógio grande, "faltam" ou "passou" escrito, o limite do servidor e a faixa do tempo usado;
  - o estouro em vermelho, com "Tempo excedido";
  - "Finalizar pausa" passa a ser a ação principal.
- **"Equipe agora":** lista densa no lugar dos cartões de 3 colunas.
  - Filtro com contagem: Todos, Disponíveis, Em pausa e Outros.
  - Linha de grupo por equipe, com os disponíveis ("Equipe N1 · 6 de 9 disponíveis").
  - A situação pela mesma regra de antes (`operatorState`, DV12), com ícone e rótulo.
  - O tempo da pausa com o motivo e "faltam" ou "passou"; no estouro, a linha ganha a borda vermelha.
  - A observação de reunião pela regra do DV11, e "Derrubar pausa" só nas linhas em pausa, com `pausas.force_end`.
  - Abaixo de 640 px, cada linha vira um cartão compacto, sem rolagem lateral.
- **A conta do tempo** saiu para `src/lib/pauseTimer.ts`, com a regra do servidor (Pessoal e Reunião sem limite), para a Visão operacional usar a mesma.

**O que saiu da tela e onde ficou:**

| Saiu | Onde ficou |
|---|---|
| Cartão "Sessão do colaborador" (nome e login) | Rodapé do menu: nome, login e perfil |
| Seletor de motivo | Os três motivos à vista |
| Selo "N técnicos" de cada equipe | Linha de grupo ("Equipe N1 · 6 de 9 disponíveis") e o filtro "Todos N" |
| Coluna "Jornada" (só na correção `906ae83`) | Embaixo do nome, em cada linha |
| Bloco do tempo dentro do cartão do colega | Coluna "Pausa", com o motivo e o "faltam" ou "passou" |
| Barra de 20 partes em quatro cores | Faixa em âmbar até o limite e em vermelho depois, com o texto |

**O que mudou em relação ao protótipo:**
- **"Finalizar pausa" e "Derrubar pausa" ficam com o texto de hoje.** O protótipo dizia "Encerrar pausa" nos dois. "Derrubar pausa" é o verbo da confirmação e da auditoria (DD3); mudar o texto de uma ação auditada fica para decisão sua.
- **Jornada embaixo do nome,** e não numa coluna: com o painel "Sua pausa" ao lado, a lista cabe em 1280 px.

**As duas correções (achadas nas capturas do próprio lote):**
- **`d16c9a8`:** no cartão do celular, a célula de ações herdava a largura de 1 px que encolhe a coluna no computador, e "Derrubar pausa" ficava abaixo de 40 px de alvo. A medição em 390 px (`capturar.mjs --medir`) achou; o bloco 23 só contava os botões. Trava nova: alvo de 40 px no cartão.
- **`906ae83` e `a584367`:** com "Sua pausa" ao lado (sessão de técnico), as quatro colunas passavam de 1280 px, e a coluna Pausa ficava cortada. A captura achou. A trava em 1280 px do `906ae83` rodava com um técnico só no servidor simulado e não pegava o defeito (a mutação com o `PausasPage.jsx` de antes sobreviveu); o `a584367` dá ao servidor simulado uma lista cheia, e a mesma mutação passou a morrer.

**QA:**
- **`qa:operational-view`:**
  - seções 2 e 5: a lista no lugar dos cartões, com o estado em `data-state` na linha (as mesmas situações, cores, ícones e detalhe; DV11 e DV12 como antes);
  - seção 6 (nova): escolha visível, botão que diz a ação, limite do servidor, contexto só na reunião, estouro escrito, Pessoal sem limite, filtro com contagem e marcado, grupos, Derrubar pausa só na pausa e só com a permissão, vazio do filtro e o aviso da sessão administrativa.
- **`qa:shell`:** o login do técnico no rodapé do menu.
- **Navegador, bloco 23 (novo, 19 conferências):**
  - o técnico inicia em 2 ações (um POST com o motivo e o técnico da sessão) em 1366 e 390 px;
  - reunião com o contexto (um POST com a observação);
  - em 1280 px, a lista cheia cabe ao lado de "Sua pausa";
  - em 390 px, cartões sem rolagem lateral, filtro e "Derrubar pausa" com 40 px.
  - O servidor simulado ganhou a sessão de técnico e `setStatusEmployees` (dados fictícios).

**Expectativas que mudaram:**

| QA | O que mudou | Por quê |
|---|---|---|
| `qa:operational-view` (seções 2 e 5) | As conferências que achavam o técnico pelo cartão (`operator-card-${state}`) o acham pela linha (`data-state`) | Os cartões viraram a lista densa; o estado e as regras conferidas são os mesmos |
| `qa:calendar-presence` | Deixa de exigir o molde `operator-card-${state}` na varredura de classes montadas | O molde saiu com os cartões; a varredura genérica continua pegando qualquer molde novo sem regra no CSS |

**Validação:**
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`), sozinha no worktree:
  - `9711d2b`: saída 0, frontend 25/25, navegador 1063/1063;
  - `d16c9a8`: saída 0, frontend 25/25, navegador 1064/1064;
  - `906ae83`: saída 0, frontend 25/25, navegador 1075/1075;
  - `a584367`: saída 0, frontend 25/25, navegador 1075/1075.
- **Mutação** com o `mut_guard.php`, cada mutante morto pela conferência certa:
  - 17/17 no QA (botão sem dizer a ação ou habilitado sem escolha; limite do café fixo; reunião sem contexto obrigatório ou contexto em qualquer motivo; sem a regra das pausas; estouro só pela cor ou sem marcar a linha; Pessoal com limite; filtro sem contagem ou que não filtra; Derrubar pausa fora da pausa ou sem a permissão; observação do colega sem a chave; situação pelo cálculo antigo; "Sessão do colaborador" de volta; login fora do rodapé);
  - 3/3 no navegador (lista sem o modo cartão, botão sem dizer a ação, filtro que não filtra);
  - correções: 1/1 (`d16c9a8`, célula de 1 px) e 1/1 (`906ae83`, o `PausasPage.jsx` de antes, com a lista cheia do `a584367`).
- **Medição em 390 px,** nos dois temas: 0 alvos abaixo de 40 px e 0 rolagens laterais.
- **Orçamento:** no `9711d2b`, JS de entrada 198 009, CSS 67 172 e fontes 85 912 bytes; no `a584367`, 199 158, 67 012 e 85 912 (o CSS desce com a saída do CSS morto no R4b).

**Capturas:**
- **"Antes":** as do R3, `capturas/r3/*-pausas-*`.
- **"Depois", no git:** `capturas/r4a/`: Pausas do técnico e da gestão, 1280 e 390 px, claro e escuro. São 8 arquivos, tirados do build do `a584367` (o código de Pausas do `906ae83`).

### R4b · Visão operacional e modo painel (14/11/2026)

**Commits:** `34f7de6` (a tela e o modo painel), `2da62d4` (correção: o painel sem a observação de reunião) e um de docs. Sem push.

**O que entrou (protótipo aprovado):**
- **Primeira tela:**
  - "Disponíveis agora": o total do `presence_summary`, de quantos escalados, e a hora do servidor;
  - a composição da equipe: uma célula por pessoa escalada, na cor e na ordem da legenda, com a frase para o leitor de tela (sem largura calculada em `style`, pela CSP);
  - N1 e N2 lado a lado, cada um com o número, a composição e a legenda.
- **"Pausas em andamento"** em lista densa:
  - o estouro marcado na linha e escrito ("passou");
  - os tipos no cabeçalho ("2 em andamento · Café 1 · Reunião 1");
  - a observação de reunião pela regra do DV11, e "Derrubar pausa" só com `pausas.force_end`.
- **"Precisa de você":**
  - aprovações pendentes, com o atalho só para quem tem `operacao.approve`;
  - cada pausa que passou do limite, com quem e quanto;
  - os chamados críticos abertos, com as war rooms;
  - cada item leva à tela dele; vazio, "Nada precisa de você agora.".
- **Sai o cartão "Operação agora" e os 8 cartões iguais.**
- **Modo painel para a tela fixa:**
  - endereço `/app/dashboard?painel=1` (a tela fixa guarda o endereço), aberto pelo botão "Modo painel";
  - sem a casca (menu, topo, sino e atalhos) e sem ações (sem Derrubar pausa, sem "Abrir pausas", "Precisa de você" só como lista, sem a tabela de detalhe e sem os totais);
  - o título, a hora do servidor, o tema (o escuro disponível pelo botão, como no resto do portal, D-R1-1) e "Sair do modo painel";
  - a fonte da raiz cresce (112,5%, e 137,5% de 1600 px para cima), e os números de destaque vão a 7rem e 4rem;
  - mesma sessão, mesma permissão e mesmos dados da tela; nenhum endereço novo no servidor.
- **CSS:** sai o CSS morto dos cartões de Pausas (`operator-card-*`, fora de camada); as classes da tela usam os utilitários que o build já tem. Ícone "chevron" novo no `Icon.jsx`, com o traço do protótipo.

**O que saiu da tela e onde ficou:**

| Saiu | Onde ficou |
|---|---|
| Cartão "Operação agora" (sobretítulo, título e frase) | Saiu (texto de apresentação, D-R0-8). Os dois indicadores de atualização ficam na linha do topo, com o mesmo texto (DV14) |
| "Disponíveis por equipe" | Painéis N1 e N2 |
| "Pausas por tipo" | Cabeçalho de "Pausas em andamento" |
| Cartão "CIs disponíveis" | "Disponíveis agora" (o número grande) e a lista "Totais da operação"; sem presença, "—" e "Indisponível: presença não calculada" |
| Cartões "Técnicos monitorados", "Pausas ativas", "Aprovações pendentes", "Chamados críticos", "War rooms", "Plantonistas ativos" e "Sobreavisos ativos" | Lista "Totais da operação", no pé da tela, com os mesmos rótulos e números do `dashboard.php`. Aprovações, chamados críticos e war rooms também em "Precisa de você", quando há |
| Frases sob os números ("Aptos para atendimento agora", "Timers sincronizados pelo servidor"...) | Saíram (apresentação, S9) |
| Avisos "Situação da equipe indisponível" e "Pausas indisponíveis" | No topo da tela, com o mesmo texto |
| Sobretítulo "Visão Operacional" da tabela | Saiu. A tabela "Situação da equipe agora" fica como o detalhe por equipe, com a Liderança fora dos totais e as notas |
| Cartões de "Pausas em andamento" | Linhas da lista, com o mesmo conteúdo |

**A correção (`2da62d4`):** o modo painel é para a TV da sala, à vista de todos. Numa sessão de gestão, a lista mostrava a observação de reunião (DV11: só a gestão e o próprio a veem), e ela iria para a TV. No painel ela não aparece; na tela normal, nada muda. Achado na revisão das capturas do painel.

**Para decidir (não implementado):** o painel mostra os nomes de quem está em pausa e de quem passou do limite, como a tela de sempre. Numa TV de sala, isso é exposição maior que a da tela de cada um (LGPD: o mínimo necessário). Se preferir, o painel pode mostrar só os números e o tempo, sem nomes; é uma linha de código.

**QA:**
- **`qa:operational-view`:**
  - seção 1: a lista "Pausas em andamento" no lugar dos cartões (DV11 como antes);
  - seção 3: os contadores e o "—" continuam conferidos, agora no topo ("Disponíveis agora", N1 e N2), na tabela e na lista de totais; os avisos, no topo;
  - seção 7 (nova): o que saiu, a ordem da tela, a composição (uma célula por escalado e a frase), disponíveis de quantos escalados, "Precisa de você" (aprovações só para quem decide, estouro, chamado crítico, vazio), o estouro na linha, os tipos no cabeçalho, pausas ilegíveis, o modo painel (sem ações, sem o detalhe, números maiores, o "—", a fonte da raiz, o endereço, a permissão, sem a casca, o título, o tema e a saída) e o painel sem a observação de reunião.
- **Navegador, bloco 24 (novo, 10 conferências):** "Disponíveis agora" e "Precisa de você" na primeira tela (1366 px) e "Disponíveis agora" em 390 px; entrar no painel pelo botão (endereço, sem casca, sem Derrubar pausa, fonte de 18 px), trocar o tema, sair (volta o menu e a fonte de 16 px); abrir pelo endereço em 1920 px (fonte de 22 px) e em 390 px, sem rolagem lateral.

**Expectativas que mudaram:**

| QA | O que mudou | Por quê |
|---|---|---|
| `qa:operational-view` (seção 1) | Acha cada pausa pela linha da lista, não pelo cartão | Os cartões viraram a lista densa; a regra do DV11 conferida é a mesma |
| `qa:operational-view` (seção 3) | "CIs disponíveis" e os demais totais lidos da lista "Totais da operação" (antes, dos cartões); N1 e N2 lidos dos painéis (antes, de "Disponíveis por equipe"); os avisos procurados na tela toda (antes, só na seção da tabela); a conferência de código do total de disponíveis segue o `presenceOf` (o mesmo `summaryCount`) | Mesmos números, com o mesmo "—", em outro lugar da tela |
| `qa:operational-view` (seção 3) | Sai a conferência do texto "Aptos para atendimento agora" | Texto de apresentação sob o número (S9, D-R0-8) |

**Validação:**
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`), sozinha no worktree:
  - `34f7de6`: saída 0, frontend 25/25, navegador 1074/1074;
  - `2da62d4`: saída 0, frontend 25/25, navegador 1075/1075, na bateria repetida sozinha. A primeira rodada, com capturas e mutação rodando em paralelo na mesma máquina, falhou numa conferência de tempo do bloco 13 ("Pausas, 390 px, derrubar pausa, enviando, Esc e clique fora: não fecha"): ela tem uma janela de 900 ms, o atraso do servidor simulado, e sob carga a resposta chegou antes. O commit só muda o `DashboardPage.jsx`; o bloco 13 sozinho passou 3 vezes em 3 e a bateria repetida passou inteira. Fica anotado: rodar as baterias sem nada em paralelo.
- **Mutação** com o `mut_guard.php`, cada mutante morto pela conferência certa:
  - 19/19 no QA ("Operação agora" de volta; composição por situação e não por pessoa, ou sem a frase; "—" virando 0; disponíveis pelo cálculo antigo; aviso de presença fora da tela; "Precisa de você" sem o estouro; atalho de aprovações para quem não aprova; estouro sem marcar a linha; painel com Derrubar pausa, com o detalhe, com botões, sem a fonte maior ou sem a de 137,5%; painel sem conferir a permissão, sem o modo na página ou sem a saída; totais sem um contador; tipos fora do cabeçalho);
  - 3/3 no navegador (painel sem a fonte maior, disponíveis fora da primeira tela, painel com Derrubar pausa);
  - correção: 1/1 (painel com a observação de reunião).
- **Medição em 390 px,** nos dois temas, inclusive o painel: 0 alvos abaixo de 40 px e 0 rolagens laterais.
- **Orçamento no `2da62d4`:** JS de entrada 199 158 de 211 050 bytes, CSS 67 012 de 70 000, fontes 85 912 de 100 000. Sem tirar o CSS morto dos cartões e sem trocar as classes novas por utilitários, o CSS passava do teto (70 257); o teto de 70 KB (D-R1-2) não mudou.

**Capturas:**
- **"Antes":** as do R3, `capturas/r3/admin-dashboard-*`.
- **"Depois", no git:** `capturas/r4b/`, do build do `2da62d4`:
  - a Visão operacional em 1280 e 390 px;
  - o modo painel em 1920 (a tela fixa), 1280 e 390 px;
  - claro e escuro. São 10 arquivos.
- **O `capturar.mjs`** ganhou o modo painel no plano dos lotes R (`--plano=r`), com a largura de 1920 × 1080 só para ele; o plano do R0 e o protótipo ficam como estavam.

**Para o deploy do redesenho (depois do R4 completo):** só frontend (`npm ci` e build), sem migration. O roteiro ganha: o aviso curto à gestão (o visual muda para todos), a medição do contador do menu (R3) e, se a sala for usar o modo painel, o endereço `/app/dashboard?painel=1` numa sessão que tenha acesso à Visão operacional.

### Revisão do R3, do R4a e do R4b (14/11/2026)

**Aprovados:** R3 (`8dec4a9`, `8348df8`, `2e7dc24`), R4a (`9711d2b`, `d16c9a8`, `906ae83`, `a584367`, `1f42666`) e R4b (`34f7de6`, `2da62d4`, `5ab0326`).

**Decisões do responsável:**

| # | Decisão | Registro |
|---|---|---|
| D-R4-1 | **Modo painel mostra os nomes** de quem está em pausa e de quem passou do limite | É a mesma informação que qualquer técnico já vê na tela de Pausas. LGPD, mínimo necessário: o painel não expõe nada além da tela de Pausas. A observação de reunião (DV11, só a gestão e o próprio) continua fora do painel (`2da62d4`) |
| D-R4-2 | **Tirar o `sdk-icon.png` do `public/`** | Nada no app o usa desde o R3 (conferido: só docs, um comentário do `BrandMark.jsx` e os pacotes de revisão antigos, fora do git). O `favicon.png` e o `apple-touch-icon.png` ficam. A trava de Segurança da Informação da estação bloqueia `git rm` pela IA; a remoção é feita pelo responsável e o commit entra no deploy 3 |
| D-R4-3 | **"Finalizar pausa" e "Derrubar pausa" ficam com o texto de hoje** | O protótipo dizia "Encerrar pausa" nos dois; fica como está |

**Ajustes pedidos (`f9790b5`):**
1. **Visão operacional, "Pausas em andamento":** em 1280 px o nome quebrava em duas linhas. O motivo foi para junto do tempo (como na tela de Pausas: "Café 23:59", embaixo "passou 3:59"), e a coluna "Motivo" saiu. A coluna do nome ocupa o que sobra, numa linha só; nome longo termina em reticências e aparece inteiro no `title`. Equipe e "desde" continuam embaixo.
2. **Menu:** "Aprovações e admin" aparecia cortado ao lado do contador. A coluna do menu passou de 256 para 272 px, e o rótulo deixou de ter reticências (se faltar largura, quebra a linha). O texto do item não mudou.
- **Navegador:** bloco 22, o item inteiro, sem reticências e sem encostar no contador, em 1366 e 1280 px; bloco 24, em 1280 px e com nomes longos, o nome numa linha, sem rolagem lateral, inteiro no `title`, e um nome comum sem reticências. Total 1079.

### R4c · Aprovações (14/11/2026)

**Commit:** `a900eef`, mais um de docs. Sem push.

**O que entrou:**
- **Lista densa** no lugar dos cartões: quem e a equipe numa linha; o que e quando na de baixo; a observação e o "Criado em"; as ações à direita.
  - hora extra: data, período, total e situação;
  - ajuste: data, tipo e horário correto;
  - pausa ou reunião: motivo e quando foi pedida.
- **Um botão principal por vez:** "Aprovar" é secundário e vira o principal só no pedido em foco (mouse ou foco do teclado dentro dele); sem nenhum em foco, o primeiro pedido visível. Antes, com cinco pedidos, eram cinco botões cheios.
- **A contagem por tipo é o filtro** (Todos, Pausas e reuniões, Horas extras, Correção de ponto). Saem os 4 cartões de contagem; o total fica em "Todos".
- **A confirmação do 17g fica** nas horas extras e nos ajustes; pausas e reuniões sem ela, como decidido em 2026-10-29. Os títulos das seções, o texto dos botões e os avisos de lista cortada ficam.
- Em 1280 × 900, o primeiro pedido sobe de cerca de 500 px para cerca de 290 px do topo.

**O que saiu da tela e onde ficou:**

| Saiu | Onde ficou |
|---|---|
| Cartões "Pausas/Reuniões", "Horas extras", "Correção de ponto" e "Total pendente" | O filtro por tipo, com a contagem em cada botão ("Todos" é o total) |
| Frases dos cartões ("Fila completa da central"...) | Saíram (apresentação, S9) |
| Avatar, selo "Pendente" e pílulas de detalhe | O nome e a equipe na primeira linha; os detalhes numa linha só, com a situação escrita |

**QA:**
- **`qa:approvals` (novo; a bateria passa de 22 para 23 `qa:*`):** a aba desenhada de verdade. Confere o filtro com a contagem, a saída dos cartões, as seções e os botões de sempre, os detalhes numa linha, o primeiro pedido marcado (`data-first`), "Aprovar" secundário em todos e a regra de CSS do pedido em foco; o filtro mostrando só o tipo, os vazios, o aviso de lista cortada e a confirmação do 17g.
- **Navegador, bloco 25 (novo, 5 conferências):** um principal só em repouso, com o mouse sobre o 3º pedido e com o foco do teclado no 5º; o filtro "Horas extras"; 390 px sem rolagem lateral. As esperas de 400 ms passam da transição de cor do botão (com 150 ms, a cor era lida no meio da transição). O bloco 11 (17g) passa sem mudança. Total 1084.

**Expectativas que mudaram:**

| QA | O que mudou | Por quê |
|---|---|---|
| `qa:unsaved-changes` | Contagem de `qa:*` de 22 para 23 | Entrou o `qa:approvals` |
| `qa:shell` | O total de Aprovações é procurado no filtro ("Todos" e a soma), e não no cartão "Total pendente" | Os cartões de contagem saíram |

**Validação:**
- **Bateria completa** (`test-local.ps1 -SkipHttp -Browser`), no worktree:
  - `f9790b5` (ajustes): saída 0, frontend 25/25, navegador 1079/1079;
  - `a900eef`: saída 0, frontend 26/26, navegador 1084/1084.
  - Nos primeiros segundos da bateria do `f9790b5`, o lint e o build da estação rodaram em paralelo. As duas são refeitas sozinhas na varredura final, antes da revisão.
- **Mutação** com o `mut_guard.php`, cada mutante morto pela conferência certa:
  - R4c: 8/8 no QA (todos os pedidos marcados como o primeiro; "Aprovar" principal fixo; CSS sem o pedido em foco; filtro sem a contagem ou que não filtra; horas extras sem a confirmação do 17g; seção com outro título; os cartões de volta) e 2/2 no navegador (principal sem acompanhar o mouse; "Aprovar" principal em todos);
  - ajustes: 2/2 no navegador (nome quebrando em duas linhas; rótulo do menu com reticências de novo).
- **Medição em 390 px,** nos dois temas: 0 alvos abaixo de 40 px e 0 rolagens laterais.
- **Orçamento no `a900eef`:** JS de entrada 199 217 de 211 050 bytes, CSS 67 278 de 70 000, fontes 85 912 de 100 000. A primeira versão passava de 69 000 (o `.btn-approve` com `@apply btn` duplicava as regras do botão); ficou com o `btn-secondary` no botão e só a cor do principal em CSS.

**Capturas:**
- **"Antes":** as do R3, `capturas/r3/admin-admin-*`.
- **"Depois", no git:** `capturas/r4c/`: Aprovações em 1280 e 390 px e a Visão operacional em 1280 px (o ajuste 1 e o menu do ajuste 2), claro e escuro. São 6 arquivos, do build do `77c4940` (o código de Aprovações é o mesmo do `a900eef`).

### R4d · Funcionários (14/11/2026)

**Commits:** `c837dc5` (lista primeiro e painel lateral), `77c4940` (correção: a lista na primeira tela do celular) e um de docs. Sem push.

**O que entrou (D-R0-6 e a correção do responsável de 13/11/2026, auditoria 3.5):**
- **A lista na primeira tela:** o botão "Adicionar funcionário", os filtros e a lista. O formulário de adicionar sai da tela.
- **Adicionar e Editar no painel lateral** (o `Drawer` do R2, sobre o `Dialog` do 17f):
  - foco no Nome; Esc, clique fora e o x pedem para fechar pela mesma guarda do formulário sujo;
  - descartar o adicionar limpa o rascunho; salvar com sucesso fecha o painel, e o foco volta a quem abriu (o aviso continua mostrando o ID gerado, D3);
  - as ações ficam no rodapé fixo do painel;
  - a regra do perfil ("O perfil de acesso vem deste cadastro; admin vem só da lista AD_ADMIN_USERS...") e a descrição da edição ficam com as mesmas palavras; sai o sobretítulo "Alteração auditada".
- **O filtro e a coluna de Almoço do deploy 2 ficam.**
- **A fileira de filtros ativos no lugar do cartão "Filtros aplicados":**
  - cada filtro em uso (busca, equipe, perfil, situação diferente de "Ativos" e almoço), com o x de tirar;
  - "Limpar filtros" com mais de um; sem filtro, "Sem filtros: todos os cadastros ativos.".
- **Saem os 4 cartões de contagem.** Os números de "Ativos" e "Liderança" ficam numa linha da lista ("N ativos no total · M com perfil Liderança na lista"); "Listados" é o "N resultado(s)" de sempre.
- **O lugar reservado para o grupo Tools** (P13, módulo de CHGs), **ainda sem efeito:**
  - um filtro desabilitado, "Grupo Tools (em breve)", com a ajuda "chega com o módulo de CHGs; ainda sem efeito";
  - no cadastro (adicionar e editar), a marca desabilitada, depois do "Fora do Mapa PA";
  - a marca fica fora do React Hook Form: nada vai ao servidor; o schema e o corpo enviado não mudam (o navegador confere o corpo do POST).
- **No celular** (`77c4940`): os filtros ficam atrás do botão "Filtros", com a contagem dos que estão em uso; a fileira de filtros ativos continua à vista. Sem isso, os seis filtros empilhados ocupavam a primeira tela.
- O cabeçalho da tabela não quebra mais no meio da palavra ("Equip/e" em 1280 px, com o menu de 272 px).

**O que saiu da tela e onde ficou:**

| Saiu | Onde ficou |
|---|---|
| Formulário "Adicionar funcionário" na tela | Painel lateral, pelo botão "Adicionar funcionário" |
| Edição no diálogo central | Painel lateral |
| Cartão "Filtros aplicados" | Fileira de filtros ativos (e a contagem no botão "Filtros" do celular) |
| Cartões "Listados", "Ativos" e "Liderança" | "N resultado(s)" e a linha "N ativos no total · M com perfil Liderança na lista" |
| Sobretítulos "Novo cadastro" e "Alteração auditada" | Saíram (D-R0-8); a descrição da edição, que fala da auditoria, fica |

**QA:**
- **`qa:employee-form`:**
  - a contagem do filtro de Almoço passa a vir da fileira de filtros ativos;
  - o adicionar é lido no painel;
  - a seção nova do R4d: lista primeiro, a ordem da tela, a saída dos cartões, os números da linha, a fileira (cada filtro, o x com nome, "Limpar filtros", situação padrão sem filtro), o grupo Tools reservado (desabilitado, fora do React Hook Form e do schema), o painel de adicionar (a regra do perfil, o rodapé, a guarda, o foco e o sucesso fechando) e o botão "Filtros" do celular.
- **`qa:unsaved-changes`:** a edição no painel lateral (o x do cabeçalho pelo `onClose`, o Cancelar do fim pelo `closeEdit`).
- **Navegador:**
  - **bloco 4**, de 112 para 114 conferências: o adicionar no painel (abrir com o foco no Nome, Esc limpo, guarda de aba, menu, voltar, Sair e recarregar, Esc e Cancelar sujos, o painel cobrindo a lista, erro do servidor, sucesso fechando o painel, o corpo sem `id` e sem nada do grupo Tools);
  - **bloco 8:** o aviso do erro sai no painel, que é descartado antes de abrir o menu do celular;
  - **bloco 22:** o atalho do celular com formulário sujo usa o de Ausências (o painel cobre a barra);
  - **bloco 26 (novo, 5 conferências):** a lista na primeira tela em 1366 px, o filtro de Almoço na fileira e saindo pelo x, e, em 390 px, o primeiro cadastro na primeira tela com os filtros fechados e o botão "Filtros" abrindo.
  - Total: 1091.

**Expectativas que mudaram:**

| QA | O que mudou | Por quê |
|---|---|---|
| Navegador, bloco 4 (adicionar) | O formulário é procurado no painel (`form[data-employee-create]`), aberto pelo botão; sucesso fecha o painel em vez de limpar o formulário na tela; o foco volta ao botão | O adicionar foi para o painel lateral (D-R0-6) |
| Navegador, bloco 4 (editar) | O fechar do cabeçalho é o x "Fechar painel"; o foco nele é conferido pelo nome acessível (o estado lê o `aria-label` quando o botão não tem texto) | O `Drawer` do R2 fecha pelo x |
| Navegador, bloco 4 ("os dois formulários") | Sai a sequência com o adicionar e a edição sujos ao mesmo tempo; entra a conferência de que, com o painel aberto, o mouse não alcança o Editar da lista | Os dois painéis são modais: um cobre a lista. As duas guardas continuam separadas no código (o `qa:unsaved-changes` confere) |
| Navegador, blocos 8 e 22 | O 8 descarta o painel antes de abrir o menu; o 22 usa o formulário de Ausências | O formulário de adicionar saiu da tela |
| `qa:employee-form` | A contagem dos filtros vem da fileira (antes, do cartão "Filtros aplicados"); o texto do cartão sai; o adicionar é lido no painel aberto; a contagem de `role="group"` passa a contar só as confirmações, pelo título | O cartão saiu; a fileira de filtros ativos também é um grupo |
| `qa:unsaved-changes` | Painel lateral no lugar do diálogo central, sem o sobretítulo; um Cancelar (o do fim) e o x do painel | A edição foi para o painel lateral |

**Validação:**
- **Bateria completa:** na varredura final, sozinha, antes da revisão (`c837dc5` e `77c4940`).
- **Mutação** com o `mut_guard.php`, cada mutante morto pela conferência certa:
  - 11/11 no QA (formulário de adicionar aberto sem pedir; marca do grupo Tools no React Hook Form; filtro Tools habilitado; filtro ativo sem o botão com nome; situação padrão contando como filtro; almoço fora da fileira; sem "Limpar filtros"; fechar o adicionar sem a guarda; sucesso sem fechar o painel; sem a regra do perfil; os cartões de volta);
  - 2/2 no navegador (fechar o adicionar sujo sem perguntar; o x do filtro de Almoço que não tira o filtro);
  - correção `77c4940`: 3/3 (filtros sempre à vista no celular, no QA e no navegador; botão "Filtros" sem a contagem).
- **Medição em 390 px,** nos dois temas: 0 alvos abaixo de 40 px e 0 rolagens laterais.
- **Orçamento no `77c4940`:** JS de entrada 199 217 de 211 050 bytes, CSS 68 187 de 70 000, fontes 85 912 de 100 000.

**Capturas:**
- **"Antes":** as do R3, `capturas/r3/admin-admin-funcionarios-*`.
- **"Depois", no git:** `capturas/r4d/`: Funcionários e o painel de adicionar aberto, 1280 e 390 px, claro e escuro. São 8 arquivos, do build do `77c4940`. O `capturar.mjs` abre o painel pelo marcador `#adicionar`, só no plano dos lotes R.

**O R4 está completo** (R4a a R4d). O deploy do redesenho vai no deploy 3, junto com o F10-1 (sem efeito em produção) e a limpeza do `sdk-icon.png`: roteiro e folha no `DEPLOY_LINUX.md` e em `docs/DEPLOY3_FOLHA_DO_DIA.md`.

### Revisão do R4c, do R4d e do roteiro do deploy 3; acabamento do R4d (15/11/2026)

**Aprovados:** os ajustes do R3-R4b (`f9790b5`), o R4c (`a900eef`), o R4d (`c837dc5`, `77c4940`), os docs (`906caa8`, `9d22316`) e o roteiro e a folha do deploy 3 (`9815213`, `48d27e1`).

**Acabamento da lista de Funcionários (`e627fb3`), pedido na revisão, só apresentação:**
1. **Jornada e almoço numa linha só** em 1280 px, na tabela e no cartão (antes, "08:00 - / 17:00" e "12:00- / 13:00" quebravam).
2. **Horários sempre em HH:MM** (um cadastro mostrava "08:00:00"), com a mesma normalização do filtro de Almoço: `scheduleText` no `employeeLunch.ts`, ao lado do `lunchKey`. Sem horário válido, "—".
3. **Perfil pelo rótulo do filtro** ("Técnico", "Gestor", "Liderança", "Admin (legado)", "Somente leitura"), e não pelo valor cru ("tecnico").

- **QA:** `qa:employee-form` confere:
  - a jornada pura (segundos, espaços, horário inválido);
  - os cinco perfis na linha e no cartão;
  - HH:MM nos dois, nenhum horário com segundos e as células sem quebra.
- **Navegador, bloco 26:** em 1280 px, jornada e almoço numa linha, sem segundos (o servidor simulado manda "08:00:00" para o 3º cadastro) e com o rótulo. Total 1092.
- **Mutação:** 8/8, cada mutante morto pela conferência certa: sem o "sem quebra", no QA e no navegador; a jornada crua com segundos, no QA e no navegador; a jornada sem normalizar no `employeeLunch.ts`; o perfil cru na tabela, no QA e no navegador; o perfil cru no cartão.
- **Expectativa que mudou:** a conferência 17 do `qa:employee-form` (o cartão com o texto da linha) passa a esperar o rótulo do perfil ("Técnico"), e não o valor cru, porque esse é o pedido.
- **Capturas:** `capturas/r4d/` refeitas do build do `e627fb3` (Funcionários e o painel de adicionar, 1280 e 390 px, claro e escuro): 0 alvos abaixo de 40 px e 0 rolagens laterais em 390 px.

**Baterias completas registradas** (`test-local.ps1 -SkipHttp -Browser`, cada uma **sozinha** no worktree de validação, sem nada em paralelo; saída, frontend e navegador):

| Commit | O que é | Saída | Frontend (lint, typecheck, build e `qa:*`) | Navegador |
|---|---|---|---|---|
| `f9790b5` | Ajustes do R3-R4b (varredura final) | 0 | 25/25 | 1079/1079 |
| `a900eef` | R4c (varredura final) | 0 | 26/26 | 1084/1084 |
| `c837dc5` | R4d | 0 | 26/26 | 1089/1089 |
| `77c4940` | R4d, correção do celular | 0 | 26/26 | 1091/1091 |
| `906caa8` | Docs da revisão e do R4c | 0 | 26/26 | 1091/1091 |
| `9d22316` | Docs do R4d | 0 | 26/26 | 1091/1091 |
| `9815213` | Roteiro e folha do deploy 3 | 0 | 26/26 | 1091/1091 |
| `48d27e1` | Trava do roteiro do deploy 3 | 0 | 26/26 | 1091/1091 |
| `e627fb3` | Acabamento do R4d | 0 | 26/26 | 1092/1092 |

As oito primeiras são da varredura final de 14/11/2026, rodada em sequência depois de todos os commits, sem nada em paralelo. O `906caa8` vem depois do `77c4940` no histórico, por isso já soma 1091. Este commit de docs tem a bateria rodada depois dele, com o resultado no estado da sessão.

**Folha do deploy 3:** a contagem de scripts npm não mudou (26: lint, typecheck, build e 23 `qa:*`), e a folha não fixa o número de conferências do navegador (pede `falharam: 0`). Não muda.
