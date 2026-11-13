# Fontes do portal

**Licença:** SIL Open Font License 1.1, com o texto ao lado (`LICENSE-ibm-plex-sans.txt` e `LICENSE-ibm-plex-mono.txt`). A OFL permite usar, embutir e redistribuir a fonte junto com o software; só não permite vender a fonte sozinha nem usar o nome reservado numa versão modificada. Os arquivos aqui não foram modificados.

**Origem:** os pacotes `@fontsource/ibm-plex-sans` e `@fontsource/ibm-plex-mono`, versão 5.3.0, publicados em 2026-07-19. Foram baixados do registro npm só como arquivo (`npm pack`), no R0, e copiados do protótipo (`frontend/prototypes/r0/fontes/`). **Nenhuma dependência entrou no `package.json` nem no lockfile.**

| Família | Pesos | Arquivo | Uso |
|---|---|---|---|
| IBM Plex Sans | 400, 500, 600 | `ibm-plex-sans-latin-<peso>-normal.woff2` | Todo o texto |
| IBM Plex Mono | 500 | `ibm-plex-mono-latin-500-normal.woff2` | Números, horários e cronômetros |

**Decisão:** D-R0-3 (`docs/design/DIRECAO_VISUAL.md`), aprovada em 13/11/2026: só o subconjunto latino, que cobre o português.

**Como chegam ao navegador:** o `@font-face` do `src/index.css` aponta para estes arquivos. O Vite copia cada um para `/app/assets/` com hash no nome, na mesma origem (`font-src 'self'` da CSP). Cada `@font-face` tem `font-display: swap`: o texto aparece na hora, na fonte do sistema, e troca quando a Plex chega.

**Orçamento:** os quatro arquivos somam 86 KB. O `qa:bundle` reprova acima de 100 KB (D-R1-2).
