#!/usr/bin/env node

/**
 * Destructive, repeatable synthetic demo bootstrap for the local Compose stack.
 *
 * The target MySQL database is dropped and rebuilt. Refuse to run unless
 * HR_DEMO_BOOTSTRAP_CONFIRM=YES is explicitly set and DEMO_SEED_PASSWORD is a
 * non-empty synthetic password.
 */
import { spawnSync } from 'node:child_process'

const container = process.env.HR_APP_CONTAINER ?? 'factoryhr-php-app-1'
const password = process.env.DEMO_SEED_PASSWORD ?? ''
const confirm = process.env.HR_DEMO_BOOTSTRAP_CONFIRM ?? ''

if (confirm !== 'YES') {
  console.error('Refusing destructive bootstrap: set HR_DEMO_BOOTSTRAP_CONFIRM=YES.')
  process.exit(2)
}

if (password.length < 12) {
  console.error('DEMO_SEED_PASSWORD must be at least 12 characters.')
  process.exit(2)
}

const steps = [
  ['migrate:fresh', ['--force']],
  ['demo:seed', ['--live']],
  ['iam:sync-capabilities', []],
  ['workflow:seed-templates', []],
  ['lifecycle:seed-templates', []],
  ['time:seed-calendar', []],
  ['time:seed-leave-types', ['--annual-days=10', '--legal-basis=Synthetic demo policy']],
  ['iam:seed-demo', []],
  ['talent:seed-demo', []],
  ['payroll:seed-parameters', ['--year=2026', '--approved-by=1']],
  ['payroll:seed-demo-run', ['--period=2026-10']],
  ['iam:seed-demo', []],
  ['demo:verify', []],
]

for (const [command, args] of steps) {
  console.log(`\n=== ${command} ===`)
  const result = spawnSync(
    'docker',
    ['exec', '-e', `DEMO_SEED_PASSWORD=${password}`, container, 'php', 'backend/artisan', command, ...args],
    { stdio: 'inherit', shell: false },
  )

  if (result.error || result.status !== 0) {
    console.error(`FAILED: ${command}`)
    process.exit(result.status ?? 1)
  }
}

console.log('\nDEMO BOOTSTRAP PASS')
