// [R1] Cores só por token (docs/design/DIRECAO_VISUAL.md, direção A). Os
// valores ficam no index.css, em :root (tema claro) e nos blocos do tema
// escuro, como canais "R G B" para o modificador de opacidade (bg-ink/5).
// A paleta padrão do Tailwind (slate, cyan...) não existe aqui de propósito:
// uma classe de cor fora dos tokens não gera CSS, e o qa:visual a reprova.
const token = (name) => `rgb(var(--${name}) / <alpha-value>)`
const tone = (name) => ({ DEFAULT: token(name), bg: token(`${name}-bg`), line: token(`${name}-line`) })

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    colors: {
      transparent: 'transparent',
      current: 'currentColor',
      inherit: 'inherit',
      // Só a cortina atrás dos diálogos, igual nos dois temas.
      black: '#000000',
      bg: token('bg'),
      surface: token('surface'),
      'surface-2': token('surface-2'),
      line: token('line'),
      'line-strong': token('line-strong'),
      ink: token('ink'),
      'ink-2': token('ink-2'),
      muted: token('muted'),
      focus: token('focus'),
      action: { DEFAULT: token('action'), ink: token('action-ink'), hover: token('action-hover') },
      // Situações (D-R0-4) e tons de aviso: texto, fundo e borda de cada uma.
      ok: tone('ok'),
      remote: tone('remote'),
      pause: tone('pause'),
      lunch: tone('lunch'),
      absent: tone('absent'),
      off: tone('off'),
      danger: tone('danger'),
      warn: tone('warn'),
      info: tone('info'),
    },
    extend: {
      borderRadius: {
        card: '10px',
      },
      ringColor: {
        DEFAULT: token('focus'),
      },
      gridTemplateColumns: {
        20: 'repeat(20, minmax(0, 1fr))',
      },
      fontFamily: {
        sans: ['"IBM Plex Sans"', 'system-ui', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', 'monospace'],
      },
    },
  },
  plugins: [],
}
