# Plano dos lotes do redesenho (R1 em diante)

**Estado:** R0, proposta (2026-11-13). Os lotes valem para a direção que o responsável escolher (`DIRECAO_VISUAL.md`, D-R0-1). A estimativa abaixo é para a recomendada (A, dois temas). **Cada lote para para revisão.**

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
| R4d | Funcionários | Lista na primeira tela; Adicionar e Editar no painel lateral (D-R0-6) | `qa:employee-form`, navegador (bloco 4, editar e adicionar) | Médio |

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

## Decisões que o plano levanta

| # | Decisão | Recomendação |
|---|---|---|
| D-R1-1 | Guardar o tema escolhido | Seguir o sistema e manter a escolha na sessão. Guardar na conta pede API: fica para depois, com desenho próprio |
| D-R1-2 | Orçamento do bundle no `qa:bundle` | JS de entrada até a linha de base mais 5%; CSS até 70 KB; fontes até 100 KB |
| D-R1-3 | Onde mora a fonte | `frontend/src/assets/fontes/`, servida pelo Vite em `/app/assets/` (mesma origem) |
