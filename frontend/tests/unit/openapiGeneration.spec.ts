import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it } from 'vitest'

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const repoRoot = resolve(frontendDir, '..')
const script = resolve(frontendDir, 'scripts/generate-api.mjs')
const spec = resolve(repoRoot, 'docs/openapi/openapi.yaml')
const committed = resolve(frontendDir, 'src/generated/api.ts')
const temporaryDirectories: string[] = []

function tempDirectory(): string {
  const directory = mkdtempSync(resolve(tmpdir(), 'factoryhr-openapi-'))
  temporaryDirectories.push(directory)

  return directory
}

function run(args: string[]) {
  return spawnSync(process.execPath, [script, ...args], {
    cwd: frontendDir,
    encoding: 'utf8',
    env: { ...process.env, NO_COLOR: '1' },
  })
}

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    rmSync(directory, { recursive: true, force: true })
  }
})

describe('OpenAPI TypeScript generation', () => {
  it('committed types are current and contain real paths and component schemas', () => {
    const result = run(['--check'])

    expect(result.status, `${result.stdout}\n${result.stderr}`).toBe(0)

    const generated = readFileSync(committed, 'utf8')

    expect(generated).toContain('export interface paths')
    expect(generated).toContain('"/v1/payroll/payslips"')
    expect(generated).toContain('"/v1/employees/{employmentId}/education"')
    expect(generated).toContain('"/v1/positions"')
    expect(generated).toContain('export interface components')
    expect(generated).toContain('PayslipDetail')
    expect(generated).toContain('Position')
    expect(generated).not.toMatch(/export type paths = Record<string, never>/)
  })

  it('generates actual openapi-typescript output and detects stale files', () => {
    const directory = tempDirectory()
    const output = resolve(directory, 'api.ts')

    const generated = run(['--source', spec, '--out', output])
    expect(generated.status, `${generated.stdout}\n${generated.stderr}`).toBe(0)

    const current = readFileSync(output, 'utf8')
    expect(current).toContain('"/v1/payroll/payslips"')
    expect(current).toContain('PayslipDetail')

    writeFileSync(output, `${current}\n// stale marker\n`, 'utf8')

    const checked = run(['--check', '--source', spec, '--out', output])
    expect(checked.status, `${checked.stdout}\n${checked.stderr}`).not.toBe(0)
    expect(`${checked.stdout}\n${checked.stderr}`).toMatch(/stale/i)
  })

  it('fails loudly when the source contract is missing', () => {
    const directory = tempDirectory()
    const missing = resolve(directory, 'missing-openapi.yaml')
    const output = resolve(directory, 'api.ts')

    const result = run(['--source', missing, '--out', output])

    expect(result.status, `${result.stdout}\n${result.stderr}`).not.toBe(0)
    expect(`${result.stdout}\n${result.stderr}`).toMatch(/missing|not found|does not exist/i)
  })
})
