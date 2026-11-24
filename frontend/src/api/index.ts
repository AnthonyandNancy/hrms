import type { ApiAdapter } from '@/api/contracts'
import { createHttpAdapter } from '@/api/http'

let adapter: ApiAdapter | null = null

export const apiMode = import.meta.env.VITE_API_MODE === 'http' ? 'http' : 'mock'

/**
 * The HTTP adapter is statically imported because it is the production path.
 * The mock adapter is loaded lazily only in development/test mode, so a
 * production bundle never contains synthetic demo data. `vite.config.ts`
 * refuses a production build whose VITE_API_MODE is not `http`.
 */
export async function resolveApi(): Promise<ApiAdapter> {
  if (adapter) return adapter

  if (apiMode === 'mock' && import.meta.env.DEV) {
    const { createMockAdapter } = await import('@/mocks/adapter')
    adapter = createMockAdapter()
  } else {
    adapter = createHttpAdapter()
  }

  return adapter
}

/**
 * Synchronous accessor for components that already resolved the adapter.
 * Call `resolveApi()` during bootstrap before rendering data-driven views.
 */
export function getApi(): ApiAdapter {
  if (!adapter) {
    adapter = createHttpAdapter()
  }

  return adapter
}

/** Test seam. Components and stores always resolve the adapter through getApi(). */
export function setApi(next: ApiAdapter): void {
  adapter = next
}
