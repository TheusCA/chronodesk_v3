# Marca do Portal SDK (ícone)

**Ícone atual (2026-11-23): opção B, "chaves com pausa".** As chaves de código `{ }` com o sinal de pausa (as duas barras âmbar) entre elas: o suporte de TI e o tempo das pausas da equipe. Escolhida pelo responsável entre as três opções de `_referencias\icone\opcoes-2026-11-23\` (fora do git). Substitui o mostrador de timer de 2026-11-16 (`2ace053`).

**Cores:** os tokens da direção A do redesenho (`frontend/src/index.css`).
- **Favicon** (`favicon.svg` e os PNG/ICO): cores fixas, **sem depender do tema** (sem `<style>` nem `prefers-color-scheme`): placa `#1C1B19`, chaves `#F4F3EF` (15,5:1 contra a placa) e barras `#F2C27A` (10,5:1).
- **Marca do topo** (`BrandMark.jsx`, SVG inline, mesma geometria em 64 x 64), sem cor fixa: a placa no token do botão principal (`action`), as chaves no `action-ink` (17,2:1 no tema claro e 15,6:1 no escuro) e as barras no token da situação "Em pausa" em `pause-line` (10,4:1 no claro e 6,4:1 no escuro; o `pause` do texto daria 2,4:1 e 1,4:1).

**Arquivos:**

| Arquivo | Onde | Uso |
|---|---|---|
| `favicon.svg` | aqui e `frontend/public/` | Fonte e ícone da aba (navegadores atuais) |
| `favicon-16.svg` | só aqui | Fonte do 16 px do `.ico`: desenho próprio, mais simples (as chaves retas, as barras mais curtas) |
| `icon-512.png` | só aqui | Fonte em alta (não vai para o build) |
| `favicon.ico` (16, 32 e 48, entradas PNG), `favicon.png` (64, com transparência), `apple-touch-icon.png` (180, opaco, sem cantos: o iOS arredonda) | `frontend/public/` | Navegadores antigos e iOS |

**Como os PNG e o ICO são gerados:** dos SVG deste diretório e do `frontend/public/favicon.svg`, pelo Chrome headless (cada SVG numa página do tamanho exato, fundo transparente), e o `.ico` montado com as três entradas PNG (16 do `favicon-16.svg`; 32 e 48 do `favicon.svg`). O `apple-touch-icon.png` sai do `favicon.svg` com a placa sem cantos. Sem dependência nova. Os SVG do repositório não levam os metadados de procedência (C2PA) das opções: só formas, sem script nem referência externa. A CSP do portal (`style-src 'self'`) só vale nas respostas `.html` (`.htaccess`), e o `img-src 'self'` cobre os ícones.

**No HTML:** `/app/favicon.ico?v=3` (32 x 32), `/app/favicon.svg?v=3` (`image/svg+xml`) e `/app/apple-touch-icon.png?v=3`, nesta ordem, no `index.html` e nas páginas PHP que ainda servem HTML (`index`, `login`, `admin_login`, `admin` e `metricas`). O `?v=3` vence o cache teimoso de favicon.
