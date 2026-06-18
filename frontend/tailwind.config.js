/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        chrono: {
          bg: '#050914',
          sidebar: '#07101f',
          card: '#0d1625',
          border: '#233044',
          green: '#22c55e',
          yellow: '#eab308',
          red: '#ef4444',
        },
      },
      borderRadius: {
        card: '8px',
      },
      gridTemplateColumns: {
        20: 'repeat(20, minmax(0, 1fr))',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
