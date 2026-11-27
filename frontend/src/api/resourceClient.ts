import axios, { AxiosError, type AxiosInstance } from 'axios'
import { ApiError } from '@/api/errors'
import type { ApiEnvelope } from '@/api/contracts'
import { useSessionStore } from '@/stores/session'

/**
 * Contract-driven resource client for registry pages.
 *
 * The legacy ApiAdapter remains for the mature vertical slices. New V1.1
 * pages declare their canonical API operations in the registry and use this
 * client, which preserves the same session/CSRF/error envelope semantics.
 */
let client: AxiosInstance | null = null

function http(): AxiosInstance {
  client ??= axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL ?? '/v1',
    withCredentials: true,
    timeout: 20_000,
    headers: { Accept: 'application/json' },
  })

  client.interceptors.request.use((config) => {
    config.headers.set('X-Requested-With', 'XMLHttpRequest')

    const method = (config.method ?? 'get').toLowerCase()
    if (!['get', 'head', 'options'].includes(method)) {
      const csrfToken = useSessionStore().csrfToken
      if (csrfToken !== null && csrfToken !== '') {
        config.headers.set('X-CSRF-Token', csrfToken)
      }
    }

    return config
  })

  return client
}

/**
 * Registry pages declare absolute API paths (`/v1/...`) while the axios
 * instance already has `/v1` as its base URL. Normalise both spellings so a
 * page can never accidentally produce `/v1/v1/...` (a real 404 found by the
 * Compose route audit).
 */
function apiPath(path: string): string {
  const trimmed = path.startsWith('/v1/') ? path.slice(3) : path
  return trimmed.startsWith('/') ? trimmed : `/${trimmed}`
}

function translate(error: unknown): never {
  if (error instanceof ApiError) throw error
  const axiosError = error as AxiosError<{
    code?: string
    message?: string
    data?: { reason?: string; fields?: Record<string, string[]> }
    request_id?: string
  }>

  if (!axiosError.response) {
    throw new ApiError('NETWORK_ERROR', '网络连接失败', { status: 0 })
  }

  const body = axiosError.response.data
  const code = body?.code ?? (axiosError.response.status === 403 ? 'FORBIDDEN' : 'INTERNAL')
  throw new ApiError(code as ApiError['code'], body?.message ?? '请求未完成', {
    status: axiosError.response.status,
    requestId: body?.request_id ?? null,
    fieldErrors: body?.data?.fields ?? {},
    details: body?.data ?? {},
  })
}

export async function resourceGet<T = unknown>(path: string, params?: Record<string, unknown>): Promise<T> {
  try {
    const response = await http().get<ApiEnvelope<T>>(apiPath(path), { params })
    return response.data.data
  } catch (error) {
    translate(error)
  }
}

export type ResourceRequestOptions = {
  /** Required by every backend write command. */
  idempotencyKey?: string
  headers?: Record<string, string>
}

export async function resourcePost<T = unknown>(
  path: string,
  body?: Record<string, unknown>,
  options: ResourceRequestOptions = {},
): Promise<T> {
  try {
    const headers: Record<string, string> = { ...(options.headers ?? {}) }
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey

    const response = await http().post<ApiEnvelope<T>>(apiPath(path), body ?? {}, { headers })
    return response.data.data
  } catch (error) {
    translate(error)
  }
}

export async function resourcePatch<T = unknown>(
  path: string,
  body?: Record<string, unknown>,
  options: ResourceRequestOptions = {},
): Promise<T> {
  try {
    const headers: Record<string, string> = { ...(options.headers ?? {}) }
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey

    const response = await http().patch<ApiEnvelope<T>>(apiPath(path), body ?? {}, { headers })
    return response.data.data
  } catch (error) {
    translate(error)
  }
}

export async function resourcePut<T = unknown>(
  path: string,
  body?: Record<string, unknown>,
  options: ResourceRequestOptions = {},
): Promise<T> {
  try {
    const headers: Record<string, string> = { ...(options.headers ?? {}) }
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey

    const response = await http().put<ApiEnvelope<T>>(apiPath(path), body ?? {}, { headers })
    return response.data.data
  } catch (error) {
    translate(error)
  }
}

export async function resourceDelete<T = unknown>(
  path: string,
  options: ResourceRequestOptions = {},
): Promise<T> {
  try {
    const headers: Record<string, string> = { ...(options.headers ?? {}) }
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey

    const response = await http().delete<ApiEnvelope<T>>(apiPath(path), { headers })
    return response.data.data
  } catch (error) {
    translate(error)
  }
}

/** Test seam. */
export function setResourceClient(next: AxiosInstance | null): void {
  client = next
}
