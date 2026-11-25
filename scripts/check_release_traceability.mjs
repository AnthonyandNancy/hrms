#!/usr/bin/env node
/**
 * Release traceability check.
 *
 * Fails when /v1/meta does not expose a real commit/build_time/spa_build_sha,
 * or when the SPA build SHA differs from the API commit. In local development
 * both may legitimately be "unknown"; --allow-unknown exists for that case and
 * must never be used by the release gate.
 *
 * Usage: node scripts/check_release_traceability.mjs --base-url URL [--expect-commit SHA] [--allow-unknown]
 */
import process from 'node:process'

function argument(name) {
  const index = process.argv.indexOf(name)
  return index === -1 ? null : process.argv[index + 1] ?? null
}

const baseUrl = (argument('--base-url') ?? process.env.SMOKE_BASE_URL ?? 'http://127.0.0.1:5174').replace(/\/$/, '')
const expectedCommit = argument('--expect-commit') ?? process.env.SMOKE_EXPECT_COMMIT ?? null
const allowUnknown = process.argv.includes('--allow-unknown')

function fail(message) {
  console.error(`release-traceability: FAIL: ${message}`)
  process.exit(1)
}

let response
try {
  response = await fetch(`${baseUrl}/v1/meta`, { headers: { Accept: 'application/json' } })
} catch (error) {
  fail(`cannot reach ${baseUrl}/v1/meta: ${error instanceof Error ? error.message : String(error)}`)
}

if (!response.ok) {
  fail(`/v1/meta returned HTTP ${response.status}`)
}

const body = await response.json()
const data = body?.data ?? {}
const fields = ['commit', 'build_time', 'spa_build_sha']

for (const field of fields) {
  if (typeof data[field] !== 'string' || data[field].trim() === '') {
    fail(`missing data.${field}`)
  }
  if (!allowUnknown && data[field] === 'unknown') {
    fail(`data.${field} is "unknown"; build the release with GIT_SHA/BUILD_TIME`)
  }
}

if (data.api_version !== 'v1') {
  fail(`unexpected api_version ${String(data.api_version)}`)
}

if (data.commit !== 'unknown' && data.spa_build_sha !== 'unknown' && data.commit !== data.spa_build_sha) {
  fail(`SPA build SHA ${data.spa_build_sha} does not match API commit ${data.commit}`)
}

if (expectedCommit !== null && data.commit !== expectedCommit) {
  fail(`meta commit ${data.commit} does not match expected ${expectedCommit}`)
}

console.log(`release-traceability: OK commit=${data.commit} build_time=${data.build_time} spa=${data.spa_build_sha}`)
