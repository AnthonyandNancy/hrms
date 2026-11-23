import { defineConfig, devices } from '@playwright/test'

/**
 * Real-backend suite. It drives the actual Vue app against a running Laravel
 * instance (php artisan serve) with the synthetic demo tenant seeded.
 *
 * Prerequisites:
 *   cd backend && php artisan migrate && php artisan people:seed-demo
 *   DEMO_SEED_PASSWORD=... php artisan iam:seed-demo
 *   php artisan lifecycle:seed-templates
 *   php artisan serve --port=8000
 */
const backend = process.env.HRMS_BACKEND_URL ?? 'http://127.0.0.1:8000'
const frontend = process.env.HRMS_FRONTEND_URL ?? 'http://127.0.0.1:5174'

export default defineConfig({
  testDir: './tests/e2e/real',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report-real' }]],
  use: {
    baseURL: frontend,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    locale: 'zh-CN',
    timezoneId: 'Asia/Shanghai',
    extraHTTPHeaders: { Accept: 'application/json' },
  },
  projects: [
    {
      name: 'real-chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    command: 'pnpm dev --port 5174',
    url: frontend,
    reuseExistingServer: true,
    timeout: 120_000,
    env: {
      VITE_API_MODE: 'http',
      VITE_API_BASE_URL: backend + '/v1',
    },
  },
})
