import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { entryBlocks, readGapMarkers } from '../../../scripts/lib/gapMarkers.mjs'
import { CLUSTER_MEMBERSHIP, CLUSTER_COUNTS } from '../../../scripts/lib/clusterMembership.mjs'

const root = resolve(process.cwd(), '..')

describe('gap marker parser', () => {
  it('finds only clusters that still have an executable gap marker', () => {
    const result = readGapMarkers(root)

    // Every marker must belong to a frozen cluster page, and every clustered
    // page that still declares a backend gap must be found. This keeps the
    // invariant "gap markers are exactly the unfinished cluster pages" while
    // letting the count fall to zero as clusters complete.
    for (const key of result.keys) {
      expect(Object.keys(CLUSTER_MEMBERSHIP)).toContain(key)
    }

    expect(result.unparsed).toHaveLength(0)
    expect(result.total).toBe(result.keys.size)
  })

  it('attributes every raw marker to an entry (fails closed)', () => {
    const result = readGapMarkers(root)

    expect(result.unparsed).toHaveLength(0)
    expect(result.total).toBe(
      Object.values(result.byStyle).reduce((total, keys) => total + keys.length, 0),
    )
  })

  it('parses 4-space indented catalogs, not just 2-space ones', () => {
    const source = [
      'export const X = [',
      '    {',
      "        key: 'a/one',",
      '        gap: backendGap(\'GET\', \'/x\', \'todo\'),',
      '    },',
      '    {',
      "        key: 'a/two',",
      '        related: [{ key: \'nested\', endpoint: \'/y\' }],',
      '        gap: backendGap(\'GET\', \'/z\', \'todo\'),',
      '    },',
      ']',
    ].join('\n')

    const blocks = entryBlocks(source)
    expect(blocks).toHaveLength(2)
    expect(blocks[0]).toContain("key: 'a/one'")
    expect(blocks[1]).toContain("key: 'a/two'")
  })

  it('does not mistake a nested key for the entry key', () => {
    const source = [
      'const X = [',
      '  {',
      "    key: 'entry/key',",
      "    related: [{ key: 'nested', endpoint: '/v1/x' }],",
      "    gap: backendGap('GET', '/v1/x', 'todo'),",
      '  },',
      ']',
    ].join('\n')

    const blocks = entryBlocks(source)
    expect(blocks).toHaveLength(1)

    const firstKey = blocks[0]?.match(/key: '([^']+)'/)

    expect(firstKey?.[1]).toBe('entry/key')
  })

  it('keeps a brace-balanced entry whole when it contains arrays of objects', () => {
    const source = [
      'const X = [',
      '  {',
      "    key: 'a/one',",
      '    columns: [',
      "      { key: 'col1', label: 'One' },",
      "      { key: 'col2', label: 'Two' },",
      '    ],',
      "    gap: GAP.one,",
      '  },',
      ']',
    ].join('\n')

    const blocks = entryBlocks(source)

    expect(blocks).toHaveLength(1)
    expect(blocks[0]).toContain('gap: GAP.one')
  })
})

describe('frozen cluster membership', () => {
  it('pins every one of the 62 pages to a cluster', () => {
    const entries = Object.entries(CLUSTER_MEMBERSHIP)

    expect(entries).toHaveLength(62)
  })

  it('matches the documented 12-cluster counts', () => {
    expect(CLUSTER_COUNTS).toEqual({
      K1: 4,
      K2: 2,
      K3: 1,
      K4: 4,
      K5: 4,
      K6: 3,
      K7: 2,
      K8: 6,
      K9: 10,
      K10: 7,
      K11: 14,
      K12: 5,
    })
  })

  it('matches the registry exactly, in both directions', () => {
    const registry = readFileSync(resolve(root, 'frontend/src/router/registry.ts'), 'utf8')
    const actual = new Map()

    for (const match of registry.matchAll(/\{cluster: '(K(?:[1-9]|1[0-2]))', path: '[^']*', name: '[^']*', componentKey: '([^']+)'/g)) {
      actual.set(match[2], match[1])
    }

    expect(actual.size).toBe(62)
    expect(Object.fromEntries([...actual.entries()].sort())).toEqual(
      Object.fromEntries(Object.entries(CLUSTER_MEMBERSHIP).sort()),
    )
  })
})
