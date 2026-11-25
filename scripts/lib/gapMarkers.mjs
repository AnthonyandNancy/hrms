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
 * IMPORTANT: this parser fails closed. It counts every raw marker occurrence
 * in a file and compares that to the markers it managed to attribute to an
 * entry. If they disagree, the file is reported as unparsed instead of
 * silently contributing zero gaps — a formatting change must never turn a
 * real backend gap into a green gate.
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

/**
 * Splits a catalog source into brace-balanced blocks. An entry starts at a line
 * whose first non-space character is `{` and whose indentation equals the
 * shallowest indentation observed among such lines. This tolerates 2-space and
 * 4-space formatters instead of hard-coding an indent width.
 */
export function entryBlocks(source) {
  const lines = source.split(/\r?\n/)
  const starts = []

  for (let i = 0; i < lines.length; i++) {
    const match = lines[i].match(/^( +)\{/)
    if (match) starts.push({ index: i, indent: match[1].length })
  }

  if (starts.length === 0) return []

  const minIndent = Math.min(...starts.map((entry) => entry.indent))
  const entryStarts = starts.filter((entry) => entry.indent === minIndent).map((entry) => entry.index)

  const blocks = []

  for (let i = 0; i < entryStarts.length; i++) {
    const start = entryStarts[i]
    const limit = i + 1 < entryStarts.length ? entryStarts[i + 1] : lines.length
    const block = []
    let depth = 0
    let opened = false

    for (let j = start; j < limit; j++) {
      block.push(lines[j])
      depth += countBraces(lines[j])
      if (lines[j].includes('{')) opened = true
      if (opened && depth <= 0) break
    }

    blocks.push(block.join(NEWLINE))
  }

  return blocks
}

/** Returns the entry-level key: the first `key:` in the block. */
function entryKey(block) {
  const match = block.match(/key: '([^']+)'/)
  return match ? match[1] : null
}

/** Counts raw marker occurrences (not entries) so nothing can be swallowed. */
function countMarkers(source, style) {
  const pattern = GAP_PATTERN[style]
  return source.split(/\r?\n/).filter((line) => pattern.test(line)).length
}

function scanFile(source, style) {
  const keys = new Set()
  const pattern = GAP_PATTERN[style]
  let attributed = 0

  for (const block of entryBlocks(source)) {
    if (!pattern.test(block)) continue
    const key = entryKey(block)
    if (key) {
      keys.add(key)
      attributed += countMarkers(block, style)
    }
  }

  return { keys, attributed, raw: countMarkers(source, style) }
}

/**
 * @returns {{
 *   keys: Set<string>,
 *   byStyle: Record<string, string[]>,
 *   total: number,
 *   hardcoded: string[],
 *   unparsed: Array<{file: string, raw: number, attributed: number}>,
 * }}
 */
export function readGapMarkers(root) {
  const keys = new Set()
  const byStyle = {}
  const unparsed = []

  for (const domain of DOMAIN_FILES) {
    const dir = join(root, domain.dir)
    if (!existsSync(dir)) continue
    const found = []

    for (const name of readdirSync(dir)) {
      if (!name.endsWith('.ts')) continue
      const file = `${domain.dir}/${name}`
      const source = readFileSync(join(dir, name), 'utf8')
      const { keys: fileKeys, attributed, raw } = scanFile(source, domain.style)

      for (const key of fileKeys) {
        keys.add(key)
        found.push(key)
      }

      if (attributed !== raw) {
        unparsed.push({ file, raw, attributed })
      }
    }

    byStyle[domain.style] = [...new Set(found)]
  }

  const hardcoded = []
  for (const domain of DOMAIN_FILES) {
    const dir = join(root, domain.dir)
    if (!existsSync(dir)) continue

    for (const name of readdirSync(dir)) {
      if (!name.endsWith('.vue')) continue
      const source = readFileSync(join(dir, name), 'utf8')
      const lines = source.split(/\r?\n/)
      const pageSpecific = lines.filter((line) => /后端接口待补[:：]/.test(line) && !line.includes('config.'))

      if (pageSpecific.length > 0) {
        hardcoded.push(`${domain.dir}/${name} (${pageSpecific.length} hardcoded panel(s))`)
      }
    }
  }

  return { keys, byStyle, total: keys.size, hardcoded, unparsed }
}
