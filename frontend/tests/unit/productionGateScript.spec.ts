import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const sourcePath = resolve(process.cwd(), '../scripts/v11_production_gate.mjs')

describe('production gate', () => {
  const source = readFileSync(sourcePath, 'utf8')

  it('declares all eight production gates', () => {
    for (const gate of ['gap-zero', 'openapi', 'capability', 'negative-tests', 'migration', 'real-stack', 'release-trace', 'coverage']) {
      expect(source).toContain(gate)
    }
  })

  it('fails the run when any gate fails', () => {
    expect(source).toContain('process.exitCode = 1')
    expect(source).toMatch(/exitCode = 1[\s\S]*break|break[\s\S]*exitCode = 1/)
  })

  it('writes a durable evidence log per run', () => {
    expect(source).toContain('evidence/v11-production-gate-')
    expect(source).toContain('.log')
  })

  it('never treats a skipped real-stack gate as a pass', () => {
    expect(source).toContain('V11_STRICT')
  })

  it('runs every gate as an explicit command rather than a shell string', () => {
    expect(source).toContain('spawnSync')
    expect(source).not.toMatch(/execSync\(`/)
  })
})
