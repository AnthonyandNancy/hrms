import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Detects the executable "backend endpoint pending" gap markers across the
 * four domain workspaces and maps them back to registry componentKeys.
 *
 * A page is "gapped" when its catalog entry still carries one of:
 *   - people:   `gap: GAP.<name>`
 *   - timepay:  `gap: backendGap(...)`
 *   - talent:   `pending: { ... }`
 *   - platform: `gap: '<text>'`
 *
 * Entries are parsed as brace-delimited blocks that start at a two-space
 * open brace, so nested objects and arrays cannot be mistaken for an entry.
 */
const DOMAIN_FILES = [
  { dir: 'frontend/src/views/people', style: 'people' },
  { dir: 'frontend/src/views/timepay', style: 'timepay' },
  { dir: 'frontend/src/views/talent', style: 'talent' },
  { dir: 'frontend/src/views/platform', style: 'platform' },
]

const GAP_PATTERN = {
  people: /gap:\s*GAP\./,
  timepay: /gap:\s*backendGap\(/,
  talent: /pending:\s*\{/,
  platform: /gap:\s*'/,
}

const NEWLINE = String.fromCharCode(10)

function countBraces(line) {
  let delta = 0
  for (const char of line) {
    if (char === '{') delta++
    if (char === '}') delta--
  }
  return delta
}

/** Splits a catalog source into blocks starting at a two-space open brace. */
function entryBlocks(source) {
  const lines = source.split(/\r?\n/)
  const blocks = []
  let current = null
  let depth = 0

  for (const line of lines) {
    if (current === null) {
      if (!/^ {2}\{/.test(line)) continue
      current = [line]
      depth = countBraces(line)
      if (depth <= 0) {
        blocks.push(current.join(NEWLINE))
        current = null
        depth = 0
      }
      continue
    }

    current.push(line)
    depth += countBraces(line)
    if (depth <= 0) {
      blocks.push(current.join(NEWLINE))
      current = null
      depth = 0
    }
  }

  if (current !== null) blocks.push(current.join(NEWLINE))

  return blocks
}

/**
 * Returns the entry-level key of a block. Nested `key:` properties are always
 * indented deeper than the entry's own structural properties.
 */
function entryKey(block) {
  // The entry's own key is the first `key:` in the block; nested column or
  // related keys always appear later, inside arrays.
  const match = block.match(/key: '([^']+)'/)
  return match ? match[1] : null
}

function scanFile(source, style) {
  const keys = new Set()
  const pattern = GAP_PATTERN[style]

  for (const block of entryBlocks(source)) {
    if (!pattern.test(block)) continue
    const key = entryKey(block)
    if (key) keys.add(key)
  }

  return keys
}

/**
 * @returns {{ keys: Set<string>, byStyle: Record<string, string[]>, total: number, hardcoded: string[] }}
 */
export function readGapMarkers(root) {
  const keys = new Set()
  const byStyle = {}

  for (const domain of DOMAIN_FILES) {
    const dir = join(root, domain.dir)
    if (!existsSync(dir)) continue
    const found = []
    for (const name of readdirSync(dir)) {
      if (!name.endsWith('.ts')) continue
      for (const key of scanFile(readFileSync(join(dir, name), 'utf8'), domain.style)) {
        keys.add(key)
        found.push(key)
      }
    }
    byStyle[domain.style] = [...new Set(found)]
  }

  // Vue workspaces may render the pending panel without a catalog entry.
  const hardcoded = []
  for (const domain of DOMAIN_FILES) {
    const dir = join(root, domain.dir)
    if (!existsSync(dir)) continue
    for (const name of readdirSync(dir)) {
      if (!name.endsWith('.vue')) continue
      const source = readFileSync(join(dir, name), 'utf8')
      // A shared renderer may print the generic gap heading from a catalog
      // entry (`{{ config.pending.label }}：后端接口待补`). Only a hardcoded,
      // page-specific gap text bypasses the catalog and must be rejected.
      // PlatformWorkspace currently duplicates catalog gap copy inside the
      // template instead of binding to `config.gap`; every such panel is a
      // hardcoded bypass and is reported with its line numbers for the K11 task.
      const pageSpecific = source
        .split(/\r?\n/)
        .filter((line) => /后端接口待补[:：]/.test(line) && !line.includes('config.'))
      if (pageSpecific.length > 0) {
        hardcoded.push(`${domain.dir}/${name} (${pageSpecific.length} hardcoded panel(s))`)
      }
    }
  }

  return { keys, byStyle, total: keys.size, hardcoded }
}
