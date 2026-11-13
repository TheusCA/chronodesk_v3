// [R1] Tokens de cor do app (src/index.css) e contraste WCAG 2.1.
// Usado pelo qa:contrast, pelo qa:calendar-presence (cor das situações no CSS
// gerado) e pelo prototypes/r0/contraste.mjs --app.

// Blocos de tokens: :root (claro), o escuro pela preferência do sistema e o
// escuro pelo botão. Cada token é "R G B" (canais, sem alfa).
export function readThemeTokens(cssSource) {
  const css = cssSource.replace(/\/\*[\s\S]*?\*\//g, '')
  const block = (pattern) => {
    const body = css.match(pattern)?.[1]
    if (body === undefined) return null
    const tokens = {}
    for (const [, name, value] of body.matchAll(/--([a-z0-9-]+):\s*([^;]+);/g)) {
      const channels = value.trim().match(/^(\d{1,3}) (\d{1,3}) (\d{1,3})$/)
      tokens[name] = channels ? channels.slice(1).map(Number) : value.trim()
    }
    return tokens
  }
  return {
    light: block(/(?:^|\n):root \{([^}]*)\}/),
    darkSystem: block(/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme='light'\]\) \{([^}]*)\}/),
    darkChosen: block(/(?:^|\n):root\[data-theme='dark'\] \{([^}]*)\}/),
  }
}

const channel = (value) => { const c = value / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
export const luminance = ([r, g, b]) => 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
export const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05) }
export const blend = ({ rgb, alpha }, base) => rgb.map((value, index) => value * alpha + base[index] * (1 - alpha))
export const hex = (rgb) => `#${rgb.map((value) => Math.round(value).toString(16).padStart(2, '0')).join('').toUpperCase()}`

// Cor de uma propriedade numa regra do CSS gerado pelo Tailwind, resolvida no
// tema: rgb(var(--ok-line) / var(--tw-border-opacity)) ou rgb(var(--ink) / 0.05).
// Sem a propriedade, null.
export function resolveColor(rule, property, tokens) {
  const found = rule.match(new RegExp(`(?:^|[;\\s{])${property}: rgb\\(var\\(--([a-z0-9-]+)\\)(?: / ([\\d.]+|var\\([^)]*\\)))?\\)`))
  if (!found) return null
  const rgb = tokens[found[1]]
  if (!Array.isArray(rgb)) throw new Error(`token --${found[1]} sem valor no tema`)
  const alpha = found[2] === undefined || found[2].startsWith('var') ? 1 : Number(found[2])
  return { token: found[1], rgb, alpha }
}

export const hue = ([r, g, b]) => {
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  const d = max - min
  if (d === 0) return 0
  const h = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4
  return (h * 60 + 360) % 360
}

export const hueDistance = (a, b) => { const d = Math.abs(a - b) % 360; return d > 180 ? 360 - d : d }

export const TEXT = 4.5
export const UI = 3

// Pares conferidos em cada tema: texto 4,5:1; borda de campo, foco e partes
// de controle 3:1 (WCAG 1.4.11). [rótulo, frente, fundo, mínimo]
export const APP_PAIRS = [
  ['texto / fundo', 'ink', 'bg', TEXT],
  ['texto / cartão', 'ink', 'surface', TEXT],
  ['texto / cartão 2', 'ink', 'surface-2', TEXT],
  ['texto 2 / cartão', 'ink-2', 'surface', TEXT],
  ['texto 2 / cartão 2', 'ink-2', 'surface-2', TEXT],
  ['apagado / cartão', 'muted', 'surface', TEXT],
  ['apagado / fundo', 'muted', 'bg', TEXT],
  ['apagado / cartão 2', 'muted', 'surface-2', TEXT],
  ['botão principal: rótulo', 'action-ink', 'action', TEXT],
  ['botão principal: rótulo no hover', 'action-ink', 'action-hover', TEXT],
  ['Presencial', 'ok', 'ok-bg', TEXT],
  ['Remoto', 'remote', 'remote-bg', TEXT],
  ['Em pausa', 'pause', 'pause-bg', TEXT],
  ['Almoço', 'lunch', 'lunch-bg', TEXT],
  ['Ausente', 'absent', 'absent-bg', TEXT],
  ['Fora do expediente', 'off', 'off-bg', TEXT],
  ['erro / tom de erro', 'danger', 'danger-bg', TEXT],
  ['aviso / tom de aviso', 'warn', 'warn-bg', TEXT],
  ['informação / tom de informação', 'info', 'info-bg', TEXT],
  ['erro / cartão', 'danger', 'surface', TEXT],
  ['erro / cartão 2', 'danger', 'surface-2', TEXT],
  ['aviso / cartão', 'warn', 'surface', TEXT],
  ['informação / cartão', 'info', 'surface', TEXT],
  ['disponível / cartão', 'ok', 'surface', TEXT],
  ['borda do campo / cartão', 'line-strong', 'surface', UI],
  ['borda do campo / cartão 2', 'line-strong', 'surface-2', UI],
  ['foco / fundo', 'focus', 'bg', UI],
  ['foco / cartão', 'focus', 'surface', UI],
  ['foco / cartão 2', 'focus', 'surface-2', UI],
  ['botão principal / fundo', 'action', 'bg', UI],
  ['botão principal / cartão', 'action', 'surface', UI],
]

export function contrastRows(tokens, pairs = APP_PAIRS) {
  return pairs.map(([label, fg, bg, min]) => {
    if (!Array.isArray(tokens[fg]) || !Array.isArray(tokens[bg])) throw new Error(`par ${label}: token --${fg} ou --${bg} ausente`)
    return { label, fg, bg, fgHex: hex(tokens[fg]), bgHex: hex(tokens[bg]), value: contrast(tokens[fg], tokens[bg]), min }
  })
}
