declare module '*.mjs' {
  export interface PageComponentAllowlistEntry {
    keys: readonly string[]
    file: string
    reason: string
  }

  export interface PageComponentAuditResult {
    ok: boolean
    failures: string[]
    missingMappings: string[]
    missingFiles: string[]
    staleManifestKeys: string[]
    allowedSharedGroups: PageComponentAllowlistEntry[]
    byFile: Map<string, string[]>
  }

  export const DEFAULT_SHARED_ALLOWLIST: ReadonlyArray<PageComponentAllowlistEntry>
  export function parseRegistryComponentKeys(source: string): { keys: string[]; errors: string[] }
  export function discoverPageComponentManifest(options?: { root?: string }): {
    manifest: Record<string, string>
    moduleManifest: Record<string, string>
    baseManifest: Record<string, string>
    existingFiles: Set<string>
    errors: string[]
  }
  export function auditPageComponents(options: {
    registryKeys?: string[]
    manifest?: Record<string, string>
    existingFiles?: Set<string> | readonly string[]
    sharedAllowlist?: ReadonlyArray<PageComponentAllowlistEntry>
    expectedRegistryCount?: number
  }): PageComponentAuditResult
}
