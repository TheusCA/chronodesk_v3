import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  base: '/app/',
  plugins: [react()],
  build: {
    outDir: '../app',
    emptyOutDir: true,
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1',
    },
  },
})
