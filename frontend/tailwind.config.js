/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        chrono: {
          bg: '#0f172a',
          card: '#1e2a3a',
          border: '#334155',
          green: '#22c55e',
          yellow: '#eab308',
          red: '#ef4444',
        },
      },
      borderRadius: {
        card: '12px',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
