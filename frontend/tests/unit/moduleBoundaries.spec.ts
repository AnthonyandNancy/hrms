import { spawnSync } from 'node:child_process'
import { mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const repoRoot = resolve(frontendDir, '..')
const script = resolve(repoRoot, 'scripts/audit_frontend_modules.mjs')

function run(args: string[] = []) {
  return spawnSync(process.execPath, [script, ...args], {
    cwd: frontendDir,
    encoding: 'utf8',
    env: { ...process.env, NO_COLOR: '1' },
  })
}

function parseReport(result: ReturnType<typeof run>): Record<string, unknown> {
  const output = `${result.stdout}\n${result.stderr}`.trim()
  expect(result.status, output).toBe(0)
  return JSON.parse(result.stdout) as Record<string, unknown>
}

describe('frontend module boundary gate', () => {
  it('runs against the real repository and emits a stable JSON report', () => {
    const report = parseReport(run())

    expect(report.ok).toBe(true)
    expect(report.failures).toEqual([])
    expect(typeof report.scanned).toBe('number')
    expect(typeof report.pagesScanned).toBe('number')
    expect(typeof report.moduleComponentsScanned).toBe('number')
    expect(typeof report.moduleApiScanned).toBe('number')
    expect(typeof report.sharedComponentsScanned).toBe('number')
  })

  it('reports ok with scanned=0 when the modules directory is absent', () => {
    const root = mkdtempSync(join(tmpdir(), 'factoryhr-module-boundary-cli-'))

    try {
      const report = parseReport(run(['--root', root]))

      expect(report.ok).toBe(true)
      expect(report.scanned).toBe(0)
      expect(report.sharedComponentsScanned).toBe(0)
      expect(report.failures).toEqual([])
    } finally {
      rmSync(root, { recursive: true, force: true })
    }
  })
})
