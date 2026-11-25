/**
 * Type declarations for the plain-JS gate libraries. They live next to the
 * libraries (not in frontend/) so both the Node scripts and the Vitest unit
 * tests see the same shapes, and the frontend app build stays strictly typed.
 */
declare module '*/scripts/lib/gapMarkers.mjs' {
  export function entryBlocks(source: string): string[]
  export function readGapMarkers(root: string): {
    keys: Set<string>
    byStyle: Record<string, string[]>
    total: number
    hardcoded: string[]
    unparsed: Array<{ file: string; raw: number; attributed: number }>
  }
}

declare module '*/scripts/lib/clusterMembership.mjs' {
  export const CLUSTER_MEMBERSHIP: Record<string, string>
  export const CLUSTER_COUNTS: Record<string, number>
}
