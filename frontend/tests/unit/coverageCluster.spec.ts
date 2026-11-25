import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY, pagesInCluster, type ClusterCode } from '@/router/registry'

const CLUSTERS = {
  K1: 4, K2: 2, K3: 1, K4: 4, K5: 4, K6: 3,
  K7: 2, K8: 6, K9: 10, K10: 7, K11: 15, K12: 5,
} as const satisfies Record<ClusterCode, number>

describe('cluster coverage', () => {
  it('declares exactly 63 gap pages across K1–K12', () => {
    const clustered = PAGE_REGISTRY.filter((page) => page.cluster !== undefined)

    expect(clustered).toHaveLength(63)
  })

  it('matches the documented page count for every cluster', () => {
    for (const [cluster, expected] of Object.entries(CLUSTERS) as Array<[ClusterCode, number]>) {
      expect(pagesInCluster(cluster), `${cluster} page count`).toHaveLength(expected)
    }
  })

  it('never assigns an unknown cluster code', () => {
    for (const page of PAGE_REGISTRY) {
      if (page.cluster === undefined) continue
      expect(page.cluster).toMatch(/^K([1-9]|1[0-2])$/)
      expect(Object.keys(CLUSTERS)).toContain(page.cluster)
    }
  })

  it('keeps base and non-gap pages out of the cluster map', () => {
    for (const page of PAGE_REGISTRY) {
      if (page.layer === 'base') {
        expect(page.cluster, `${page.path} must not belong to a cluster`).toBeUndefined()
      }
    }
  })

  it('exposes a stable path per clustered page', () => {
    const clustered = PAGE_REGISTRY.filter((page) => page.cluster !== undefined)
    expect(new Set(clustered.map((page) => page.path)).size).toBe(clustered.length)
  })
})
