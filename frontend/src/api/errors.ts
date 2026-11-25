import type { ApiErrorCode } from '@/api/contracts'

export class ApiError extends Error {
  readonly code: ApiErrorCode
  readonly status: number
  readonly requestId: string | null
  readonly fieldErrors: Record<string, string[]>
  readonly details: Record<string, unknown>

  constructor(
    code: ApiErrorCode,
    message: string,
    options: {
      status?: number
      requestId?: string | null
      fieldErrors?: Record<string, string[]>
      details?: Record<string, unknown>
    } = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = options.status ?? statusForCode(code)
    this.requestId = options.requestId ?? null
    this.fieldErrors = options.fieldErrors ?? {}
    this.details = options.details ?? {}
  }

  /** Stable business reason from the server envelope, e.g. REQUISITION_NOT_APPROVED. */
  get serverReason(): string | null {
    const reason = this.details.reason
    return typeof reason === 'string' && reason !== '' ? reason : null
  }
}

function statusForCode(code: ApiErrorCode): number {
  switch (code) {
    case 'UNAUTHENTICATED':
      return 401
    case 'FORBIDDEN':
      return 403
    case 'NOT_FOUND':
      return 404
    case 'METHOD_NOT_ALLOWED':
      return 405
    case 'CONFLICT':
    case 'NO_ACTIVE_EMPLOYMENT':
      return 409
    case 'VALIDATION_FAILED':
      return 422
    case 'RATE_LIMITED':
      return 429
    case 'SERVICE_UNAVAILABLE':
      return 503
    case 'NETWORK_ERROR':
      return 0
    case 'INTERNAL':
      return 500
  }
}

const ERROR_COPY: Record<ApiErrorCode, string> = {
  UNAUTHENTICATED: '登录状态已失效，请重新登录后继续。',
  FORBIDDEN: '你没有执行此操作的权限。如确需访问，请提交授权申请。',
  NOT_FOUND: '未找到该记录，可能已被撤回、移出你的数据范围，或链接有误。',
  METHOD_NOT_ALLOWED: '此操作方式不受支持，请刷新页面后重试。',
  CONFLICT: '数据状态已发生变化，请刷新后重试。',
  NO_ACTIVE_EMPLOYMENT: '当前账号未绑定员工，员工自助功能不可用。',
  VALIDATION_FAILED: '提交内容未通过校验，请检查标记的字段。',
  RATE_LIMITED: '操作过于频繁，请稍后再试。',
  INTERNAL: '系统暂时无法完成该操作，请稍后重试并联系管理员。',
  SERVICE_UNAVAILABLE: '服务暂时不可用，当前页面展示的是最近一次成功数据。',
  NETWORK_ERROR: '网络连接失败，请检查网络后重试。',
}

export function errorCopy(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.code === 'VALIDATION_FAILED' && Object.keys(error.fieldErrors).length > 0) {
      return error.message || ERROR_COPY.VALIDATION_FAILED
    }
    return ERROR_COPY[error.code]
  }
  if (error instanceof Error && error.message) {
    return `操作未完成：${error.message}`
  }
  return '操作未完成，请稍后重试。'
}

export function errorCode(error: unknown): ApiErrorCode | 'UNKNOWN' {
  return error instanceof ApiError ? error.code : 'UNKNOWN'
}
