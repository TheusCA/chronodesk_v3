/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        chrono: {
          bg: '#070b14',
          sidebar: '#0a101c',
          card: '#101827',
          border: '#243044',
          green: '#22c55e',
          yellow: '#eab308',
          red: '#ef4444',
        },
      },
      borderRadius: {
        card: '12px',
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
