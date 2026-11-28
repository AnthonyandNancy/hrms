import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig(({ mode }) => {
  const apiMode = process.env.VITE_API_MODE ?? 'mock'

  if (mode === 'production' && apiMode !== 'http') {
    throw new Error(
      'VITE_API_MODE must be "http" for a production build: shipping the mock adapter would serve synthetic data as if it were real.',
    )
  }

  return {
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/v1': {
        target:
          process.env.VITE_DEV_API_PROXY_TARGET ??
          process.env.VITE_PROXY_TARGET ??
          'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  preview: {
    port: 4173,
    strictPort: true,
  },
  build: {
    // Production bundles must not leak the mock adapter source or demo data.
    sourcemap: mode !== 'production',
    chunkSizeWarningLimit: 1200,
  },
  }
})
