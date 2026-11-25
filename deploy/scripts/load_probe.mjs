#!/usr/bin/env node
/**
 * Lightweight V1.1 read-path load evidence.
 *
 * Sends a bounded number of concurrent requests to readiness, meta and (when a
 * session cookie is supplied) an authenticated read. It is deliberately small:
 * the goal is a reproducible latency/error trace in evidence/, not a benchmark
 * that saturates the developer machine.
 *
 * Usage:
 *   node deploy/scripts/load_probe.mjs --base-url URL --requests 120 --concurrency 12 [--cookie "hrms_session=..."]
 */
import { writeFileSync, mkdirSync } from 'node:fs'
import { resolve } from 'node:path'
import process from 'node:process'

function arg(name, fallback) {
  const index = process.argv.indexOf(name)
  return index === -1 ? fallback : process.argv[index + 1] ?? fallback
}

const baseUrl = arg('--base-url', 'http://127.0.0.1:5184').replace(/\/$/, '')
const total = Number(arg('--requests', '120'))
const concurrency = Number(arg('--concurrency', '12'))
const cookie = arg('--cookie', '')

if (!Number.isInteger(total) || total <= 0 || !Number.isInteger(concurrency) || concurrency <= 0) {
  console.error('requests and concurrency must be positive integers')
  process.exit(2)
}

const targets = ['/v1/health/ready', '/v1/meta']
if (cookie !== '') targets.push('/v1/me')

async function one(path) {
  const started = performance.now()
  try {
    const response = await fetch(`${baseUrl}${path}`, {
      headers: { Accept: 'application/json', ...(cookie === '' ? {} : { Cookie: cookie }) },
    })
    return { path, status: response.status, ms: performance.now() - started }
  } catch (error) {
    return { path, status: 0, ms: performance.now() - started, error: String(error) }
  }
}

const queue = Array.from({ length: total }, (_, index) => targets[index % targets.length])
const results = []
let cursor = 0

async function worker() {
  while (cursor < queue.length) {
    const index = cursor
    cursor += 1
    results.push(await one(queue[index]))
  }
}

const startedAt = Date.now()
await Promise.all(Array.from({ length: Math.min(concurrency, queue.length) }, worker))
const elapsedMs = Date.now() - startedAt

const sorted = results.map((row) => row.ms).sort((a, b) => a - b)
const percentile = (p) => sorted[Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1))]
const failures = results.filter((row) => row.status < 200 || row.status >= 400)
const summary = {
  base_url: baseUrl,
  requests: results.length,
  concurrency,
  elapsed_ms: elapsedMs,
  throughput_rps: Number((results.length / (elapsedMs / 1000)).toFixed(2)),
  latency_ms: {
    p50: Number(percentile(50).toFixed(2)),
    p95: Number(percentile(95).toFixed(2)),
    p99: Number(percentile(99).toFixed(2)),
    max: Number(sorted[sorted.length - 1].toFixed(2)),
  },
  status_counts: results.reduce((acc, row) => {
    acc[String(row.status)] = (acc[String(row.status)] ?? 0) + 1
    return acc
  }, {}),
  failures: failures.length,
  failure_samples: failures.slice(0, 10),
}

const directory = resolve(process.cwd(), 'evidence')
mkdirSync(directory, { recursive: true })
const output = resolve(directory, `load-probe-${new Date().toISOString().replace(/[:.]/g, '-')}.json`)
writeFileSync(output, `${JSON.stringify(summary, null, 2)}\n`, 'utf8')
console.log(JSON.stringify(summary, null, 2))

// The acceptance budget is deliberately generous for a developer workstation:
// readiness must stay under 2s p95 and the error rate under 1%.
const errorRate = summary.failures / summary.requests
if (summary.latency_ms.p95 > 2000 || errorRate > 0.01) {
  console.error(`load probe failed: p95=${summary.latency_ms.p95}ms error_rate=${(errorRate * 100).toFixed(2)}%`)
  process.exit(1)
}
