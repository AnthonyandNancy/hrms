import assert from 'node:assert/strict'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
  buildBaselineReport,
  buildCapabilityInventory,
  sanitizeOutput,
  summarizeFailedGates,
} from '../collect_v2_baseline.mjs'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

test('baseline report records command, exit code and output tail', () => {
  const report = buildBaselineReport([
    { name: 'node-version', command: 'node --version', exitCode: 0, stdout: 'v24.19.0\n', stderr: '' },
    { name: 'php-test', command: 'php artisan test', exitCode: 1, stdout: 'FAILED\n', stderr: '1 failure\n' },
    { name: 'mysql-rehearsal', command: 'mysql --version', exitCode: 127, stdout: '', stderr: '' },
  ])

  assert.match(report, /## node-version/)
  assert.match(report, /exit code: 0/)
  assert.match(report, /v24\.19\.0/)
  assert.match(report, /## php-test/)
  assert.match(report, /exit code: 1/)
  assert.match(report, /1 failure/)
  assert.match(report, /## mysql-rehearsal/)
  assert.match(report, /NOT RUN/)
})

test('capability inventory is a deterministic table with a measured count', () => {
  const report = buildCapabilityInventory([
    {
      code: 'people.employee.read',
      resource: 'employee',
      actions: ['read'],
      anchorTypes: ['SELF', 'COMPANY'],
      fieldProfile: 'P2_SENSITIVE',
      risk: 'high',
      sodGroup: null,
      description: 'Read employee records.',
    },
    {
      code: 'iam.role.manage',
      resource: 'role',
      actions: ['read', 'create', 'update'],
      anchorTypes: ['COMPANY'],
      fieldProfile: 'P1_INTERNAL',
      risk: 'critical',
      sodGroup: 'identity_governance',
      description: 'Manage role assignments and templates. | audited',
    },
  ])

  assert.match(report, /Capability count: 2/)
  assert.match(report, /\| `people\.employee\.read` \| `employee` \| `read` \| `SELF`, `COMPANY` \| `P2_SENSITIVE` \| `high` \| — \|/)
  assert.match(report, /\| `iam\.role\.manage` \| `role` \| `read`, `create`, `update` \| `COMPANY` \| `P1_INTERNAL` \| `critical` \| `identity_governance` \|/)
  assert.match(report, /Manage role assignments and templates\. \\\| audited/)
  assert.doesNotMatch(report, /Generated:/)
})

test('commands are recorded without host absolute paths', () => {
  const report = buildBaselineReport([
    {
      name: 'backend-test',
      command: 'cd backend && ../.tools/php/php.exe vendor/bin/phpunit --colors=never',
      cwd: repoRoot,
      exitCode: 0,
      stdout: 'OK\n',
      stderr: '',
    },
  ])

  assert.doesNotMatch(report, new RegExp(repoRoot.replaceAll('\\', '\\\\'), 'i'))
  assert.doesNotMatch(report, /[A-Za-z]:[\\/]/)
})

test('output tails are sanitised of host paths while keeping the command result', () => {
  const output = sanitizeOutput([
    'FAILED at D:\\code\\ai\\factoryHR\\.worktrees\\v2.1-remediation\\backend\\tests\\Contract\\NoTenantNoLegalEntityTest.php:37',
    'tmp C:\\Users\\example\\AppData\\Local\\Temp\\factoryhr-openapi-check-abc\\api.ts',
    'actual /home/runner/work/factoryHR/backend/app/Foo.php',
    'Tests: 1123, Assertions: 51283, Failures: 1.',
  ].join('\n'))

  assert.doesNotMatch(output, /D:\\code|C:\\Users|\/home\/runner/)
  assert.match(output, /<worktree:v2\.1-remediation>\\backend\\tests\\Contract\\NoTenantNoLegalEntityTest\.php:37/)
  assert.match(output, /Tests: 1123, Assertions: 51283, Failures: 1\./)
})

test('failed gate summary separates environment probes from failing gates', () => {
  const summary = summarizeFailedGates([
    { name: 'docker-info', command: 'docker info', exitCode: 1 },
    { name: 'backend-test', command: 'phpunit', exitCode: 1 },
    { name: 'frontend-lint', command: 'pnpm lint', exitCode: 0 },
  ])

  assert.doesNotMatch(summary, /docker-info/)
  assert.match(summary, /backend-test/)
  assert.doesNotMatch(summary, /frontend-lint/)
})
