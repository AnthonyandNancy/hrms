import type { ApiAdapter } from '@/api/contracts'
import { createHttpAdapter } from '@/api/http'
import { createMockAdapter } from '@/mocks/adapter'

let adapter: ApiAdapter | null = null

export const apiMode = import.meta.env.VITE_API_MODE === 'http' ? 'http' : 'mock'

export function getApi(): ApiAdapter {
  if (!adapter) {
    adapter = apiMode === 'http' ? createHttpAdapter() : createMockAdapter()
  }
  return adapter
}

/** Test seam. Components and stores always resolve the adapter through getApi(). */
export function setApi(next: ApiAdapter): void {
  adapter = next
}
