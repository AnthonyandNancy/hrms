import axios from 'axios'
import { ApiError } from '@/api/errors'
import { resourceGet } from '@/api/resourceClient'

/**
 * Employee-directory list actions that moved out of standalone pages:
 * import-batch results and the as-of CSV export. Reads never fabricate rows;
 * the export uses the same filter object as `listEmployees`.
 */
export type EmployeeImportBatch = {
  id: number
  status: string
  originalName: string
  totalRows: number
  validRows: number
  failedRows: number
  errors: unknown[]
  validatedAt: string | null
  committedAt: string | null
  createdAt: string | null
}

export type EmployeeImportBatchPage = {
  items: EmployeeImportBatch[]
  page: number
  page_size: number
  total: number
}

type RecordValue = Record<string, unknown>

function isRecord(value: unknown): value is RecordValue {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function numberOrNull(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return null
}

function stringOrNull(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function normaliseBatch(row: RecordValue): EmployeeImportBatch | null {
  const id = numberOrNull(row.id)
  if (id === null || id <= 0) return null

  return {
    id,
    status: stringOrNull(row.status) ?? 'UNKNOWN',
    originalName: stringOrNull(row.original_name) ?? `批次 #${id}`,
    totalRows: numberOrNull(row.total_rows) ?? 0,
    validRows: numberOrNull(row.valid_rows) ?? 0,
    failedRows: numberOrNull(row.failed_rows) ?? 0,
    errors: Array.isArray(row.errors) ? row.errors : [],
    validatedAt: stringOrNull(row.validated_at),
    committedAt: stringOrNull(row.committed_at),
    createdAt: stringOrNull(row.created_at),
  }
}

export async function listEmployeeImportBatches(page = 1, pageSize = 20): Promise<EmployeeImportBatchPage> {
  const raw = await resourceGet<unknown>('/v1/employee-imports', { page, page_size: pageSize })

  if (!isRecord(raw)) {
    return { items: [], page, page_size: pageSize, total: 0 }
  }

  const items = Array.isArray(raw.items)
    ? raw.items.filter(isRecord).map(normaliseBatch).filter((row): row is EmployeeImportBatch => row !== null)
    : []
  const total = numberOrNull(raw.total) ?? items.length

  return {
    items,
    page: numberOrNull(raw.page) ?? page,
    page_size: numberOrNull(raw.page_size) ?? pageSize,
    total,
  }
}

export type EmployeeExportFilter = {
  as_of: string
  org_unit_id?: number
  q?: string
  include_archived: boolean
}

export type EmployeeCsvExport = {
  filename: string
  content: string
}

/**
 * The server returns CSV bytes; the browser never reconstructs a row from the
 * paginated JSON projection. Authentication/CSRF stay on the axios client.
 */
export async function exportEmployees(filter: EmployeeExportFilter): Promise<EmployeeCsvExport> {
  const base = import.meta.env.VITE_API_BASE_URL ?? '/v1'
  const params: Record<string, string | number> = { as_of: filter.as_of }
  if (filter.org_unit_id !== undefined) params.org_unit_id = filter.org_unit_id
  if (filter.q !== undefined && filter.q.trim() !== '') params.q = filter.q.trim()
  if (filter.include_archived) params.include_archived = 1

  try {
    // The browser receives the server-produced CSV unchanged; no row is rebuilt
    // from the paginated JSON projection and no P3 field is requested.
    const response = await axios.get<string>(`${base}/employees/export`, {
      params,
      responseType: 'text',
      transformResponse: [(data: unknown) => data],
      withCredentials: true,
      timeout: 30_000,
      headers: { Accept: 'text/csv', 'X-Requested-With': 'XMLHttpRequest' },
    })

    return {
      filename: `employees-${filter.as_of}.csv`,
      content: typeof response.data === 'string' ? response.data : '',
    }
  } catch (error) {
    const axiosError = error as {
      response?: { status?: number; data?: { code?: string; message?: string; request_id?: string; data?: Record<string, unknown> } }
    }
    if (!axiosError.response) {
      throw new ApiError('NETWORK_ERROR', '网络连接失败', { status: 0 })
    }
    const body = axiosError.response.data ?? {}
    throw new ApiError((body.code ?? 'INTERNAL') as ApiError['code'], body.message ?? '员工导出未完成', {
      status: axiosError.response.status ?? 0,
      requestId: body.request_id ?? null,
      details: body.data ?? {},
    })
  }
}

/** Trigger a browser download from CSV bytes returned by the API. */
export function saveEmployeeExport(file: EmployeeCsvExport): void {
  const blob = new Blob([file.content], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = file.filename
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export function nextEmployeeExportKey(): string {
  return `people-employee-export-${globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)}`
}
