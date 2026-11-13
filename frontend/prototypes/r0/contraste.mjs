/* global process */
// R0: contraste WCAG 2.1 dos tokens do prototipo, nos quatro temas, lido do
// proprio prototipo.css. Texto 4.5:1; borda de campo, foco, preenchimento do
// botao principal e partes da barra 3:1 (1.4.11). Sai 1 se algum par falhar.
// Uso: node prototypes/r0/contraste.mjs [--md]
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'prototipo.css'), 'utf8')
const themes = {}
for (const match of css.matchAll(/\[data-direcao="([ab])"\]\[data-tema="(claro|escuro)"\]\s*\{([^}]*)\}/g)) {
  const tokens = {}
  for (const [, name, value] of match[3].matchAll(/--([a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})/g)) tokens[name] = value
  themes[`${match[1]}-${match[2]}`] = tokens
}

const channel = (value) => { const c = value / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
const luminance = (hex) => { const n = parseInt(hex.slice(1), 16); return 0.2126 * channel(n >> 16) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255) }
const ratio = (a, b) => { const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05) }

// [rotulo, frente, fundo, minimo]
const TEXT = 4.5
const UI = 3
const pairs = (t, direction) => [
  ['texto / fundo', 'ink', 'bg', TEXT], ['texto / painel', 'ink', 'surface', TEXT], ['texto 2 / painel', 'ink-2', 'surface', TEXT],
  ['apagado / painel', 'muted', 'surface', TEXT], ['apagado / fundo', 'muted', 'bg', TEXT], ['apagado / painel 2', 'muted', 'surface-2', TEXT],
  ['botao principal: rotulo', 'action-ink', 'action-bg', TEXT], ['erro / painel', 'danger', 'surface', TEXT], ['erro / tom de erro', 'danger', 'danger-bg', TEXT],
  ['Presencial', 'ok-fg', 'ok-bg', TEXT], ['Remoto', 'remote-fg', 'remote-bg', TEXT], ['Em pausa', 'pause-fg', 'pause-bg', TEXT],
  ['Almoco', 'lunch-fg', 'lunch-bg', TEXT], ['Ausente', 'absent-fg', 'absent-bg', TEXT], ['Fora do expediente', 'off-fg', 'off-bg', TEXT],
  ['Sem escala / painel', 'none-fg', 'surface', TEXT],
  ['borda do campo / painel', 'line-strong', 'surface', UI], ['foco / fundo', 'focus', 'bg', UI], ['foco / painel', 'focus', 'surface', UI],
  ['botao principal / fundo', 'action-bg', 'bg', UI],
  ...(direction === 'a'
    ? [['barra: disponiveis', 'ok-fg', 'surface', UI], ['barra: pausa', 'pause-fg', 'surface', UI], ['barra: almoco', 'lunch-fg', 'surface', UI], ['barra: ausentes', 'absent-fg', 'surface', UI]]
    : [['barra: disponiveis', 'ok-bg', 'surface', UI], ['barra: pausa', 'pause-bg', 'surface', UI], ['barra: almoco', 'lunch-bg', 'surface', UI], ['barra: ausentes', 'absent-bg', 'surface', UI]]),
].map(([label, fg, bg, min]) => ({ label, fg: t[fg], bg: t[bg], value: ratio(t[fg], t[bg]), min }))

let failures = 0
const markdown = process.argv.includes('--md')
for (const [name, tokens] of Object.entries(themes)) {
  const rows = pairs(tokens, name[0])
  const low = rows.filter((row) => row.value < row.min)
  failures += low.length
  if (markdown) {
    console.log(`\n**${name}** (menor: ${Math.min(...rows.map((r) => r.value)).toFixed(2)}:1)\n\n| Par | Cores | Contraste | Mínimo |\n|---|---|---|---|`)
    for (const row of rows) console.log(`| ${row.label} | \`${row.fg}\` sobre \`${row.bg}\` | ${row.value.toFixed(2)}:1 | ${row.min}:1 |`)
  } else {
    console.log(`${name}: ${rows.length} pares, ${low.length} abaixo do minimo${low.length ? ': ' + low.map((r) => `${r.label} ${r.value.toFixed(2)}`).join(', ') : ''}`)
  }
}
console.log(`${markdown ? '\n' : ''}temas: ${Object.keys(themes).length}, falhas: ${failures}`)
process.exit(failures === 0 && Object.keys(themes).length === 4 ? 0 : 1)
