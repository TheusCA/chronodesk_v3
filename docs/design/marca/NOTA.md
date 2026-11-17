# Marca do Portal SDK (ícone, 2026-11-16)

**Conceito:** um mostrador de timer (o anel de 3/4) com o sinal de pausa (as duas barras) e o ponto âmbar da situação "Em pausa". É o que o portal faz: o tempo das pausas da equipe.

**Cores:** os tokens da direção A do redesenho (`frontend/src/index.css`). No favicon, fixas por tema (o SVG tem `@media (prefers-color-scheme: dark)` interno): placa `#1C1B19` e símbolos `#F4F3EF` no claro, invertidos no escuro (placa `#EEECE6`, símbolos `#1C1B19`); o ponto `#F2C27A` no claro e `#B86A00` no escuro. Na marca do topo (`BrandMark.jsx`, SVG inline, mesma geometria em 64 x 64), sem cor fixa: a placa no token do botão principal (`action`), os símbolos no `action-ink` e o ponto no token da situação "Em pausa" em `pause-line`. O `pause` do texto daria contraste de 2,4:1 no tema claro e 1,4:1 no escuro contra a placa; o `pause-line` dá 10,4:1 e 6,4:1.

**Arquivos:**

| Arquivo | Onde | Uso |
|---|---|---|
| `favicon.svg` | aqui e `frontend/public/` | Fonte e ícone da aba (navegadores atuais) |
| `favicon-16.svg` | só aqui | Fonte do 16 px do `.ico`: só o anel e o ponto (as barras somem em 16 px) |
| `icon-512.png` | só aqui | Fonte em alta (não vai para o build) |
| `favicon.ico` (16, 32 e 48), `favicon.png` (64), `apple-touch-icon.png` (180, opaco, sem cantos: o iOS arredonda) | `frontend/public/` | Navegadores antigos e iOS |

Os arquivos vieram prontos (feitos fora do repositório); não são gerados aqui. Os SVG têm só formas, um `<style>` interno e um bloco de metadados de procedência (C2PA): nenhum script nem referência externa. A CSP do portal (`style-src 'self'`) só vale nas respostas `.html` (`.htaccess`), e o `img-src 'self'` cobre os ícones.

**No HTML:** `/app/favicon.ico?v=2` (32 x 32), `/app/favicon.svg?v=2` (`image/svg+xml`) e `/app/apple-touch-icon.png?v=2`, nesta ordem, no `index.html` e nas páginas PHP que ainda servem HTML. O `?v=2` vence o cache teimoso de favicon.
