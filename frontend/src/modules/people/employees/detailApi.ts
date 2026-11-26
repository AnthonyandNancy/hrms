import axios, { AxiosError } from 'axios'
import { ApiError } from '@/api/errors'
import { resourceGet, resourcePost } from '@/api/resourceClient'
import { useSessionStore } from '@/stores/session'
import { nextIdempotencyKey } from '../peopleRead'

/**
 * Employee detail read model.
 *
 * The detail page is a same-object workspace (ADR-0035): every section is
 * scoped to the employment id in the route. This adapter never fabricates a
 * field. A missing property stays missing so the view can distinguish "not
 * returned by the field projection" from "returned as empty" and render
 * 无权查看 instead of an invented value.
 */

export type EmployeeRecord = {
  employment_id: number
  person_id: number
  person_name: string | null
  employee_no?: string | null
  status: string
  employment_type?: string | null
  hire_date?: string | null
  valid_from?: string | null
  valid_to?: string | null
  archived_at?: string | null
  archived_by?: number | null
  archive_reason?: string | null
  manager_employment_id?: number | null
  current_assignment?: {
    id?: number
    org_unit_id?: number | null
    org_unit_name?: string | null
    position_id?: number | null
    position_title?: string | null
    assignment_type?: string | null
    is_primary?: boolean
    valid_from?: string | null
    valid_to?: string | null
  } | null
  person?: {
    id?: number
    legal_name?: string | null
    preferred_name?: string | null
    email?: unknown
  } | null
  terms?: {
    id?: number
    version?: number
    effective_from?: string | null
    effective_to?: string | null
    work_location?: string | null
    work_schedule?: string | null
    notice_period_days?: number | null
    probation_end_date?: string | null
  } | null
  active_contracts?: Array<Record<string, unknown>>
  tabs?: string[]
  [key: string]: unknown
}

export type EmployeeTabKey =
  | 'overview'
  | 'employment'
  | 'contracts'
  | 'credentials'
  | 'education'
  | 'experience'
  | 'contacts'
  | 'changes'

export type EmployeeTabSection = {
  key: string
  kind: string
  label: string
  rows: Array<Record<string, unknown>>
  total: number
}

export type EmployeeTabPayload = {
  sections: EmployeeTabSection[]
}

export type ArchiveResult = {
  employment_id: number
  archived_at: string | null
  archived_by?: number | null
  archive_reason?: string | null
}

export type RestoreResult = {
  employment_id: number
  archived_at: null
}

type ListEnvelope = {
  items?: unknown
  total?: unknown
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function rowsOf(payload: unknown): Array<Record<string, unknown>> {
  if (Array.isArray(payload)) return payload.filter(isRecord)
  if (isRecord(payload) && Array.isArray((payload as ListEnvelope).items)) {
    return ((payload as ListEnvelope).items as unknown[]).filter(isRecord)
  }
  return []
}

function totalOf(payload: unknown, fallback: number): number {
  if (isRecord(payload) && typeof (payload as ListEnvelope).total === 'number') {
    return (payload as ListEnvelope).total as number
  }
  return fallback
}

function section(key: string, kind: string, label: string, payload: unknown): EmployeeTabSection {
  const rows = rowsOf(payload)
  return { key, kind, label, rows, total: totalOf(payload, rows.length) }
}

export async function getEmployee(employmentId: number): Promise<EmployeeRecord> {
  const payload = await resourceGet<unknown>(`/v1/employees/${employmentId}`)
  if (!isRecord(payload)) {
    throw new Error('员工详情返回了无法识别的数据结构')
  }
  return payload as EmployeeRecord
}

async function employmentSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employments/${employmentId}/timeline`)
  return section('timeline', 'timeline', '任职与异动记录', payload)
}

async function contractsSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employments/${employmentId}/contracts`)
  return section('contracts', 'contracts', '合同版本', payload)
}

async function certificatesSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employees/${employmentId}/certificates`)
  return section('certificates', 'certificates', '证照与资格', payload)
}

async function documentsSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employments/${employmentId}/document-files`, {
    page: 1,
    page_size: 20,
  })
  return section('documents', 'documents', '员工附件', payload)
}

async function educationSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employees/${employmentId}/education`)
  return section('education', 'education', '教育经历', payload)
}

async function experienceSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employees/${employmentId}/experiences`)
  return section('experiences', 'experiences', '工作经历', payload)
}

async function contactsSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employees/${employmentId}/contacts`)
  return section('contacts', 'contacts', '联系方式', payload)
}

async function changesSection(employmentId: number): Promise<EmployeeTabSection> {
  const payload = await resourceGet<unknown>(`/v1/employees/${employmentId}/change_requests`)
  return section('changes', 'changes', '变更申请', payload)
}

/**
 * Load exactly the endpoint(s) owned by one tab. Callers must load a tab only
 * when it is activated; a rejection belongs to that tab and must never blank
 * the employee summary.
 */
export async function loadEmployeeTab(
  employmentId: number,
  tab: EmployeeTabKey,
): Promise<EmployeeTabPayload> {
  switch (tab) {
    case 'overview':
      return { sections: [] }
    case 'employment':
      return { sections: [await employmentSection(employmentId)] }
    case 'contracts':
      return { sections: [await contractsSection(employmentId)] }
    case 'credentials': {
      // Both endpoints belong to this tab. A failure is rendered by this tab's
      // own error state; it must not be disguised as an honest empty list.
      const [certificates, documents] = await Promise.all([
        certificatesSection(employmentId),
        documentsSection(employmentId),
      ])
      return { sections: [certificates, documents] }
    }
    case 'education':
      return { sections: [await educationSection(employmentId)] }
    case 'experience':
      return { sections: [await experienceSection(employmentId)] }
    case 'contacts':
      return { sections: [await contactsSection(employmentId)] }
    case 'changes':
      return { sections: [await changesSection(employmentId)] }
  }
}

/**
 * Archive is a logical delete; the reason is part of the command contract.
 * DELETE carries a JSON body, so this uses the same axios semantics as the
 * resource client rather than the client's body-less `resourceDelete` helper.
 */
function writeHeaders(extra: Record<string, string> = {}): Record<string, string> {
  const token = useSessionStore().csrfToken
  return {
    ...extra,
    ...(token ? { 'X-CSRF-Token': token } : {}),
  }
}

export async function archiveEmployee(employmentId: number, reason: string): Promise<ArchiveResult> {
  const client = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL ?? '/v1',
    withCredentials: true,
    timeout: 20_000,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })

  try {
    const response = await client.delete<{ data: ArchiveResult }>(`/employees/${employmentId}`, {
      data: { reason },
      headers: writeHeaders({
        'Content-Type': 'application/json',
        'Idempotency-Key': nextIdempotencyKey('employee-archive'),
      }),
    })
    return response.data.data
  } catch (error) {
    const axiosError = error as AxiosError<{
      code?: string
      message?: string
      request_id?: string
      data?: { fields?: Record<string, string[]>; reason?: string }
    }>
    if (!axiosError.response) {
      throw new ApiError('NETWORK_ERROR', '网络连接失败', { status: 0 })
    }
    const body = axiosError.response.data
    throw new ApiError((body?.code ?? 'INTERNAL') as ApiError['code'], body?.message ?? '归档未完成', {
      status: axiosError.response.status,
      requestId: body?.request_id ?? null,
      fieldErrors: body?.data?.fields ?? {},
      details: body?.data ?? {},
    })
  }
}

/** Restore is idempotent and must never re-create history. */
export async function restoreEmployee(employmentId: number): Promise<RestoreResult> {
  return resourcePost<RestoreResult>(
    `/v1/employees/${employmentId}/restore`,
    {},
    {
      idempotencyKey: nextIdempotencyKey('employee-restore'),
      headers: writeHeaders(),
    },
  )
}
