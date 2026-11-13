// [R1] Tokens de cor e tema (docs/design/DIRECAO_VISUAL.md, direção A;
// D-R0-2 e D-R1-1). Confere, sobre o src/index.css de verdade:
// - os três blocos de tokens (claro, escuro do sistema, escuro do botão), com
//   os mesmos nomes, só canais "R G B", e os dois escuros iguais;
// - o tailwind.config.js só com cores de token (nenhum hex solto);
// - o contraste WCAG 2.1 AA de cada par nos dois temas (texto 4,5:1; borda de
//   campo, foco e botão 3:1), a mesma conta do prototypes/r0/contraste.mjs;
// - o src/lib/theme.ts: segue o sistema sem escolha, guarda a escolha só na
//   sessão da aba e não quebra sem armazenamento.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'
import { APP_PAIRS, contrastRows, readThemeTokens } from './lib/contrast.mjs'

const frontendRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')
const cssSource = read('src/index.css')

// 1. Blocos de tokens.
const themes = readThemeTokens(cssSource)
for (const [name, tokens] of Object.entries(themes)) assert.ok(tokens && Object.keys(tokens).length > 0, `bloco de tokens ${name} no index.css`)
const names = Object.keys(themes.light).sort()
for (const [name, tokens] of Object.entries(themes)) {
  assert.deepEqual(Object.keys(tokens).sort(), names, `${name}: os mesmos tokens do tema claro`)
  for (const [token, value] of Object.entries(tokens)) assert.ok(Array.isArray(value) && value.every((part) => part >= 0 && part <= 255), `${name}: --${token} em canais R G B (sem alfa)`)
}
assert.deepEqual(themes.darkSystem, themes.darkChosen, 'escuro pelo sistema e escuro pelo botão com os mesmos valores')
assert.match(cssSource, /:root \{\s*color-scheme: light;/, 'claro declara color-scheme: light')
assert.match(cssSource, /:root:not\(\[data-theme='light'\]\) \{\s*color-scheme: dark;/, 'escuro do sistema declara color-scheme: dark')
assert.match(cssSource, /:root\[data-theme='dark'\] \{\s*color-scheme: dark;/, 'escuro do botão declara color-scheme: dark')

// 2. Tailwind só com cores de token.
const { default: tailwindConfig } = await import(pathToFileURL(join(frontendRoot, 'tailwind.config.js')).href)
assert.equal(tailwindConfig.theme.extend?.colors, undefined, 'cores em theme.colors (a paleta padrão do Tailwind fica de fora)')
const fixed = new Set(['transparent', 'currentColor', 'inherit', '#000000'])
const used = new Set()
const walk = (value, path) => {
  if (typeof value === 'object') { for (const [key, inner] of Object.entries(value)) walk(inner, `${path}.${key}`); return }
  if (fixed.has(value)) return
  const token = value.match(/^rgb\(var\(--([a-z0-9-]+)\) \/ <alpha-value>\)$/)?.[1]
  assert.ok(token, `${path}: cor por token, não solta (${value})`)
  assert.ok(names.includes(token), `${path}: token --${token} existe no index.css`)
  used.add(token)
}
walk(tailwindConfig.theme.colors, 'colors')
assert.equal(tailwindConfig.theme.colors.black, '#000000', 'preto só para a cortina dos diálogos')
for (const token of names) assert.ok(used.has(token), `token --${token} ligado a uma cor do Tailwind`)

// 3. Contraste AA nos dois temas.
let failures = 0
for (const [name, tokens] of [['claro', themes.light], ['escuro', themes.darkChosen]]) {
  const rows = contrastRows(tokens)
  assert.equal(rows.length, APP_PAIRS.length)
  for (const row of rows) {
    if (row.value < row.min) failures++
    assert.ok(row.value >= row.min, `${name}: ${row.label} ${row.fgHex} sobre ${row.bgHex} = ${row.value.toFixed(2)}:1 (mínimo ${row.min}:1)`)
  }
}
assert.equal(failures, 0)

// 4. Tema: src/lib/theme.ts num window simulado.
const themeSource = ts.transpileModule(read('src/lib/theme.ts'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
assert.doesNotMatch(read('src/lib/theme.ts'), /localStorage|document\.cookie|fetch\(|post\(/, 'tema só na sessão da aba: sem localStorage, cookie ou servidor')
const themeModule = await import(`data:text/javascript,${encodeURIComponent(themeSource)}`)
function fakeWindow({ dark = false, stored = undefined, broken = false } = {}) {
  const store = new Map(stored === undefined ? [] : [['chronodesk.tema', stored]])
  const root = { dataset: {} }
  globalThis.document = { documentElement: root }
  globalThis.window = {
    matchMedia: (query) => ({ matches: query === '(prefers-color-scheme: dark)' && dark }),
    sessionStorage: {
      getItem: (key) => { if (broken) throw new Error('bloqueado'); return store.has(key) ? store.get(key) : null },
      setItem: (key, value) => { if (broken) throw new Error('bloqueado'); store.set(key, value) },
    },
  }
  return { root, store }
}
{
  const { root } = fakeWindow({ dark: true })
  themeModule.restoreTheme()
  assert.equal(root.dataset.theme, undefined, 'sem escolha, a raiz fica sem data-theme (o CSS segue o sistema)')
  assert.equal(themeModule.activeTheme(), 'dark', 'sem escolha, o tema em uso é o do sistema (escuro)')
}
{
  const { root } = fakeWindow({ dark: false })
  assert.equal(themeModule.activeTheme(), 'light', 'sem escolha, o tema em uso é o do sistema (claro)')
  assert.equal(root.dataset.theme, undefined)
}
{
  const { root, store } = fakeWindow({ dark: false })
  themeModule.chooseTheme('dark')
  assert.equal(root.dataset.theme, 'dark', 'a escolha vai para a raiz')
  assert.equal(store.get('chronodesk.tema'), 'dark', 'a escolha fica na sessão da aba')
  assert.equal(themeModule.activeTheme(), 'dark')
}
{
  const { root } = fakeWindow({ dark: true, stored: 'light' })
  themeModule.restoreTheme()
  assert.equal(root.dataset.theme, 'light', 'a escolha da sessão vale na abertura, por cima do sistema')
}
{
  const { root } = fakeWindow({ stored: 'roxo' })
  themeModule.restoreTheme()
  assert.equal(root.dataset.theme, undefined, 'valor estranho na sessão é ignorado')
  assert.equal(themeModule.storedTheme(), null)
}
{
  const { root } = fakeWindow({ broken: true })
  themeModule.restoreTheme()
  themeModule.chooseTheme('dark')
  assert.equal(root.dataset.theme, 'dark', 'sem armazenamento, o tema troca e vale até recarregar')
}
assert.equal(themeModule.oppositeTheme('dark'), 'light')
assert.equal(themeModule.oppositeTheme('light'), 'dark')
delete globalThis.window
delete globalThis.document

// 5. Botão do topo e abertura.
const layoutSource = read('src/components/PortalLayout.jsx')
assert.match(layoutSource, /function ThemeToggle\(\)/, 'botão de tema na casca')
assert.match(layoutSource, /<Clock \/>\s*<ThemeToggle \/>/, 'botão de tema no topo, ao lado do relógio')
assert.match(layoutSource, /next === 'dark' \? 'Usar tema escuro' : 'Usar tema claro'/, 'o rótulo do botão diz a ação')
assert.match(layoutSource, /aria-label=\{label\}/, 'botão de tema com nome acessível')
assert.match(read('src/main.jsx'), /restoreTheme\(\)\n/, 'a escolha da sessão é aplicada antes do primeiro desenho')
assert.match(read('index.html'), /<meta name="color-scheme" content="light dark" \/>/, 'index.html declara os dois esquemas')

console.log(`Contraste e tema QA OK (${APP_PAIRS.length} pares em cada tema, ${names.length} tokens)`)
