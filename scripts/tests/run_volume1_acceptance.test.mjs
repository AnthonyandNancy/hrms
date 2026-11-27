import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { spawnSync } from 'node:child_process'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
  GATES,
  buildAcceptanceReport,
  runAcceptance,
} from '../run_volume1_acceptance.mjs'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const reportPath = resolve(repoRoot, 'docs/audit/2026-11-26-v2-volume-1-acceptance.md')

function gate(name, command, exitCode, stdout = '', stderr = '') {
  return { name, command, exitCode, stdout, stderr }
}

test('all-green product gates produce a strict YES even when docker is unavailable', () => {
  const entries = [
    gate('backend-phpunit', 'cd backend && ../.tools/php/php.exe vendor/bin/phpunit', 0, 'OK (1080 tests)\n'),
    gate('frontend-typecheck', 'pnpm typecheck', 0, 'vue-tsc: no errors\n'),
    gate('node-tests', 'node --test scripts/tests/*.test.mjs', 0, 'pass 42\n'),
    gate('docker-info', 'docker info', 1, '', 'Cannot connect to the Docker daemon\n'),
  ]

  const report = buildAcceptanceReport(entries, { snapshotCommit: '0123456789abcdef' })

  assert.match(report, /Snapshot commit: `0123456789abcdef`/)
  assert.match(report, /\| gate \| exit code \| result \| evidence tail \|/)
  assert.match(report, /\| `backend-phpunit` \| 0 \| PASS \| OK \(1080 tests\) \|/)
  assert.match(report, /## Environment probes/)
  assert.match(report, /\| `docker-info` \| 1 \| NOT RUN \(environment\) \|/)
  assert.match(report, /\*\*YES\*\*/)
  assert.doesNotMatch(report, /NO — blocked/)
  assert.doesNotMatch(report, /Generated:/)
})

test('one red product gate produces NO — blocked with a bullet for each red gate', () => {
  const report = buildAcceptanceReport(
    [
      gate('backend-phpunit', 'phpunit', 0, 'OK\n'),
      gate('backend-contract', 'php artisan test --filter=...', 1, 'FAILED\n'),
      gate('frontend-lint', 'pnpm lint', 2, '', 'eslint failed\n'),
    ],
    { snapshotCommit: 'deadbeef' },
  )

  assert.match(report, /\*\*NO — blocked\*\*/)
  // backend-contract is a plan-approved carried gate; frontend-lint is not, so
  // only the unexpected red gate is listed as a blocker.
  assert.match(report, /- `frontend-lint`: exit code 2/)
  assert.doesNotMatch(report, /- `backend-contract`: exit code 1/)
  assert.doesNotMatch(report, /- `backend-phpunit`:/)
  assert.doesNotMatch(report, /\*\*YES\*\*/)
})

test('only plan-approved carried gates produce YES with named owners', () => {
  const report = buildAcceptanceReport(
    [
      gate('backend-phpunit', 'phpunit', 1, 'NoTenantNoLegalEntityTest failed\n'),
      gate('audit_user_copy', 'node scripts/audit_user_copy.mjs', 1, 'legacy copy\n'),
    ],
    { snapshotCommit: 'deadbeef' },
  )

  assert.match(report, /\*\*YES — Volume 1 contracts closed; carried gates owned by Volume 2\/3\*\*/)
  assert.match(report, /audit_user_copy.*Volume 2 Task 6-8/)
  assert.doesNotMatch(report, /\*\*NO — blocked\*\*/)
})

test('an environment failure is NOT RUN, listed separately, and is not a product red', () => {
  const report = buildAcceptanceReport(
    [
      gate('frontend-typecheck', 'pnpm typecheck', 0, 'clean\n'),
      gate('docker-info', 'docker info', 1, '', 'daemon unavailable\n'),
    ],
    { snapshotCommit: 'abc1234' },
  )

  assert.match(report, /## Environment probes/)
  assert.match(report, /NOT RUN \(environment\)/)
  assert.match(report, /daemon unavailable/)
  assert.match(report, /\*\*YES\*\*/)
  assert.doesNotMatch(report, /NO — blocked/)
  assert.doesNotMatch(report, /- `docker-info`:/)
})

test('the report is deterministic for the same entries and snapshot commit', () => {
  const entries = [
    gate('backend-phpunit', 'phpunit', 1, 'FAILED\n', 'one failure\n'),
    gate('docker-info', 'docker info', 1, '', 'unavailable\n'),
  ]

  const first = buildAcceptanceReport(entries, { snapshotCommit: 'feedface' })
  const second = buildAcceptanceReport(entries, { snapshotCommit: 'feedface' })

  assert.equal(first, second)
  assert.match(first, /```text\nFAILED\none failure\n```/)
})

test('runAcceptance executes selected gates sequentially and records failures without throwing', () => {
  const calls = []
  const fakeExecFile = (command, args, options) => {
    calls.push({ command, args, options })
    if (command === 'php-under-test') {
      throw Object.assign(new Error('pint failed'), {
        status: 3,
        stdout: 'pint stdout\n',
        stderr: 'pint stderr\n',
      })
    }
    return 'ok\n'
  }

  const entries = runAcceptance({
    cwd: repoRoot,
    php: 'php-under-test',
    env: { ACCEPTANCE_TEST_MARKER: 'yes' },
    only: ['backend-pint', 'docker-info'],
    execFile: fakeExecFile,
  })

  assert.deepEqual(entries.map((entry) => entry.name), ['backend-pint', 'docker-info'])
  assert.equal(entries[0].exitCode, 3)
  assert.equal(entries[0].stdout, 'pint stdout\n')
  assert.equal(entries[0].stderr, 'pint stderr\n')
  assert.equal(entries[1].exitCode, 0)
  assert.equal(calls.length, 2)
  assert.match(calls[0].args.join(' '), /-d memory_limit=1G vendor\/bin\/pint --test/)
  assert.equal(calls[0].options.timeout, 300_000)
  assert.equal(calls[0].options.env.ACCEPTANCE_TEST_MARKER, 'yes')
  assert.equal(calls[0].options.cwd, resolve(repoRoot, 'backend'))
})

test('a timed-out gate is recorded as TIMEOUT rather than throwing', () => {
  const fakeExecFile = () => {
    throw Object.assign(new Error('spawn timed out'), {
      code: 'ETIMEDOUT',
      status: null,
      signal: 'SIGTERM',
      stdout: 'partial output\n',
      stderr: '',
    })
  }

  const [entry] = runAcceptance({
    php: 'php-under-test',
    only: ['backend-phpunit'],
    execFile: fakeExecFile,
  })

  assert.equal(entry.exitCode, 'TIMEOUT')
  assert.equal(entry.stdout, 'partial output\n')
  assert.match(entry.stderr, /timed out/)
})

test('a real subprocess failure captures both stdout and stderr', () => {
  const entries = runAcceptance({
    only: ['audit_page_components', 'openapi:validate'],
    execFile: undefined,
    cwd: repoRoot,
  })
  assert.equal(entries.length, 2)

  const [pageComponents, openapiValidate] = entries
  assert.equal(pageComponents.exitCode, 0, pageComponents.stderr)
  assert.match(pageComponents.command, /^node scripts\/audit_page_components\.mjs$/)
  assert.equal(openapiValidate.exitCode, 0, openapiValidate.stderr)
  assert.match(openapiValidate.stdout, /docs\/openapi\/openapi\.yaml/)
})

test('the gate list uses the frozen contract gate name openapi:validate', () => {
  assert.ok(GATES.some((gate) => gate.name === 'openapi:validate'))
  assert.ok(!GATES.some((gate) => gate.name === 'openapi-validate'))
})

test('--dry-run lists every gate and does not write the acceptance report', () => {
  const before = existsSync(reportPath) ? readFileSync(reportPath, 'utf8') : null
  const result = spawnSync(process.execPath, ['scripts/run_volume1_acceptance.mjs', '--dry-run'], {
    cwd: repoRoot,
    encoding: 'utf8',
  })
  const after = existsSync(reportPath) ? readFileSync(reportPath, 'utf8') : null

  assert.equal(result.status, 0, result.stderr)
  assert.match(result.stdout, /backend-phpunit/)
  assert.match(result.stdout, /openapi:validate/)
  assert.match(result.stdout, /node-tests/)
  assert.match(result.stdout, /docker-info/)
  assert.match(result.stdout, /19 gate/)
  assert.equal(after, before)
})
