#!/usr/bin/env node
/**
 * Generate TypeScript types from the canonical OpenAPI contract.
 *
 * Usage:
 *   node scripts/generate-api.mjs
 *   node scripts/generate-api.mjs --check
 *   node scripts/generate-api.mjs --source <spec> --out <file> [--check]
 *
 * The generator delegates to the repository-pinned `openapi-typescript`
 * executable. It never writes a placeholder document: a missing source, an
 * invalid OpenAPI root, or a generator failure exits non-zero.
 */
import { spawnSync } from 'node:child_process'
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseDocument } from 'yaml'

const here = dirname(fileURLToPath(import.meta.url))
const projectRoot = resolve(here, '..')
const repoRoot = resolve(projectRoot, '..')

function argument(name) {
  const index = process.argv.indexOf(name)

  if (index === -1) {
    return null
  }

  const value = process.argv[index + 1]

  if (value === undefined || value.startsWith('--')) {
    fail(`${name} requires a value`)
  }

  return value
}

function fail(message) {
  console.error(`[openapi:generate] ${message}`)
  process.exit(2)
}

const check = process.argv.includes('--check')
const source = resolve(argument('--source') ?? resolve(repoRoot, 'docs/openapi/openapi.yaml'))
const outFile = resolve(argument('--out') ?? resolve(projectRoot, 'src/generated/api.ts'))

if (!existsSync(source)) {
  fail(`OpenAPI source does not exist: ${source}`)
}

let document

try {
  document = parseDocument(readFileSync(source, 'utf8'), {
    prettyErrors: true,
    strict: true,
    uniqueKeys: true,
  })
} catch (error) {
  fail(`cannot read OpenAPI source ${source}: ${error.message}`)
}

if (document.errors.length > 0) {
  fail(`invalid YAML in ${source}:\n${document.errors.map((error) => error.message).join('\n')}`)
}

const spec = document.toJS()

if (spec === null || typeof spec !== 'object' || Array.isArray(spec)) {
  fail(`${source} must contain an OpenAPI mapping`)
}

if (typeof spec.openapi !== 'string' || !/^3\.1\.\d+$/.test(spec.openapi)) {
  fail(`${source} must declare OpenAPI 3.1.x, got ${JSON.stringify(spec.openapi)}`)
}

if (spec.paths === null || typeof spec.paths !== 'object' || Array.isArray(spec.paths)) {
  fail(`${source} must declare a paths mapping`)
}

if (Object.keys(spec.paths).length === 0) {
  fail(`${source} must declare at least one path`)
}

if (spec.components?.schemas === null || typeof spec.components?.schemas !== 'object') {
  fail(`${source} must declare components.schemas`)
}

function runGenerator(output) {
  const result = spawnSync('pnpm', ['exec', 'openapi-typescript', source, '--output', output], {
    cwd: projectRoot,
    encoding: 'utf8',
    env: { ...process.env, NO_COLOR: '1' },
    shell: process.platform === 'win32',
  })

  if (result.error) {
    fail(`failed to invoke openapi-typescript: ${result.error.message}`)
  }

  if (result.status !== 0) {
    const outputText = [result.stdout, result.stderr].filter(Boolean).join('\n').trim()
    fail(`openapi-typescript exited with ${result.status}${outputText ? `:\n${outputText}` : ''}`)
  }
}

if (!check) {
  runGenerator(outFile)

  if (!existsSync(outFile)) {
    fail(`generator reported success but did not write ${outFile}`)
  }

  console.log(`[openapi:generate] wrote ${outFile} from ${source}`)
  process.exit(0)
}

if (!existsSync(outFile)) {
  fail(`generated file is missing: ${outFile}. Run pnpm openapi:generate.`)
}

const temporaryDirectory = mkdtempSync(resolve(tmpdir(), 'factoryhr-openapi-check-'))
const generatedFile = resolve(temporaryDirectory, 'api.ts')

try {
  runGenerator(generatedFile)

  if (!existsSync(generatedFile)) {
    fail('generator reported success but did not produce a temporary file')
  }

  const expected = readFileSync(outFile, 'utf8')
  const actual = readFileSync(generatedFile, 'utf8')

  if (expected !== actual) {
    console.error('[openapi:generate] generated types are stale. Run pnpm openapi:generate.')
    console.error(`[openapi:generate] committed: ${outFile}`)
    console.error(`[openapi:generate] freshly generated: ${generatedFile}`)

    const expectedLines = expected.split(/\r?\n/)
    const actualLines = actual.split(/\r?\n/)
    const limit = Math.max(expectedLines.length, actualLines.length)
    let reported = 0

    for (let index = 0; index < limit && reported < 10; index += 1) {
      if (expectedLines[index] !== actualLines[index]) {
        console.error(`  line ${index + 1}: committed ${JSON.stringify(expectedLines[index] ?? '<missing>')}`)
        console.error(`  line ${index + 1}: generated ${JSON.stringify(actualLines[index] ?? '<missing>')}`)
        reported += 1
      }
    }

    process.exit(1)
  }

  console.log(`[openapi:generate] ${outFile} is current`)
} finally {
  rmSync(temporaryDirectory, { recursive: true, force: true })
}
