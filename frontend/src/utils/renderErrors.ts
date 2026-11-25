import { ApiError } from '@/api/errors'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

/**
 * Vue can capture non-Error values thrown from setup/render. Keep a real
 * Error at the boundary so the fallback always has a safe message.
 */
export function toError(value: unknown): Error {
  if (value instanceof Error) return value
  if (typeof value === 'string') return new Error(value)
  try {
    return new Error(JSON.stringify(value))
  } catch {
    return new Error(String(value))
  }
}

/**
 * The API error class is the primary source, but router/loader errors may wrap
 * the envelope. Read both snake_case and camelCase without trusting shapes.
 */
export function requestIdFromError(value: unknown): string | null {
  if (value instanceof ApiError) return value.requestId
  if (!isRecord(value)) return null

  for (const key of ['requestId', 'request_id']) {
    const candidate = value[key]
    if (typeof candidate === 'string' && candidate.length > 0) return candidate
  }

  const response = value.response
  if (isRecord(response) && isRecord(response.data)) {
    const candidate = response.data.request_id
    if (typeof candidate === 'string' && candidate.length > 0) return candidate
  }

  return null
}

/**
 * Browsers and Vite do not share one chunk-load error class. Match both the
 * standard WebKit/Chromium messages and webpack/Vite names.
 */
export function isChunkLoadError(value: unknown): boolean {
  const error = toError(value)
  const signature = `${error.name} ${error.message}`.toLowerCase()

  return (
    signature.includes('chunkloaderror') ||
    signature.includes('failed to fetch dynamically imported module') ||
    signature.includes('error loading dynamically imported module') ||
    signature.includes('importing a module script failed') ||
    signature.includes('loading chunk') ||
    signature.includes('loading css chunk')
  )
}
