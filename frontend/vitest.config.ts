import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/unit/**/*.spec.ts'],
    restoreMocks: true,
    css: false,
    // Full-suite Element Plus mount costs (jsdom) put a few integration tests
    // close to the 5s default under load; keep unit behavior unchanged.
    testTimeout: 15000,
  },
})
