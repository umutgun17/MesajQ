import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'https://mesajq.com',
        changeOrigin: true,
      },
    },
  },
  // HUB_BASE=/app-beta/ ile beta yoluna derlenir; varsayılan canlı /app/
  base: process.env.HUB_BASE || '/app/',
  build: {
    outDir: '../public/' + (process.env.HUB_BASE || '/app/').replace(/\//g, ''),
    emptyOutDir: true,
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      // İki entry: ana hub SPA'sı + hafif public iade sayfası (iade.html — routes/web.php
      // /iade/* isteklerinde bunu servis eder; müşteri tarafına 1.7MB admin bundle inmesin).
      input: {
        index: path.resolve(__dirname, 'index.html'),
      },
      output: {
        manualChunks: {
          'vendor-react': ['react', 'react-dom', 'react-router-dom'],
          'vendor-ui': ['lucide-react'],
        },
      },
    },
  },
})
