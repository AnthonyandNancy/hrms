import type { ApiAdapter } from '@/api/contracts'
import { createHttpAdapter } from '@/api/http'

let adapter: ApiAdapter | null = null

export const apiMode = import.meta.env.VITE_API_MODE === 'http' ? 'http' : 'mock'

export function getApi(): ApiAdapter {
  if (!adapter) {
    // The HTTP adapter is the only statically imported adapter. The mock is
    // loaded lazily so a production (http) bundle never contains synthetic
    // demo data; a non-http production build is refused by vite.config.ts.
    adapter = createHttpAdapter()
  }
  return adapter
}

/** Test seam. Components and stores always resolve the adapter through getApi(). */
export function setApi(next: ApiAdapter): void {
  adapter = next
}

/** Development-only lazy mock adapter. */
export async function getMockApi(): Promise<ApiAdapter> {
  const { createMockAdapter } = await import('@/mocks/adapter')
  adapter = createMockAdapter()
  return adapter
}
