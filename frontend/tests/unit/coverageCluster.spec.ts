import { describe, expect, it } from 'vitest'
import { PAGE_REGISTRY, pagesInCluster, type ClusterCode } from '@/router/registry'

const CLUSTERS = {
  K5: 1, K8: 1, K10: 1, K11: 6,
} as const satisfies Partial<Record<ClusterCode, number>>

describe('cluster coverage', () => {
  it('retains only the V2.3 detail-page cluster markers', () => {
    const clustered = PAGE_REGISTRY.filter((page) => page.cluster !== undefined)

    // M19 deletes four IAM entries and adds /system/pages, so K11 drops to 10.
    expect(clustered).toHaveLength(9)
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
