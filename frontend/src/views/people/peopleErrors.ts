import { ApiError } from '@/api/errors'

export type PeopleErrorInfo = {
  code: string
  message: string
  requestId: string | null
  reason: string | null
  fieldErrors: Record<string, string[]>
}

/**
 * Preserve stable business codes and request ids from write failures.
 * Page-load failures still use usePageError; this only feeds inline form
 * feedback so the user does not have to leave a half-filled form.
 */
export function peopleErrorInfo(error: unknown): PeopleErrorInfo {
  if (error instanceof ApiError) {
    const reason = error.serverReason
    return {
      code: reason ?? error.code,
      message: error.message || errorCopyFallback(error.code),
      requestId: error.requestId,
      reason,
      fieldErrors: { ...error.fieldErrors },
    }
  }

  if (error instanceof Error) {
    return { code: 'UNKNOWN', message: error.message, requestId: null, reason: null, fieldErrors: {} }
  }

  return { code: 'UNKNOWN', message: '操作未完成，请稍后重试。', requestId: null, reason: null, fieldErrors: {} }
}

function errorCopyFallback(code: string): string {
  const copy: Record<string, string> = {
    FORBIDDEN: '你没有执行此操作的权限。',
    CONFLICT: '数据状态已发生变化，请刷新后重试。',
    VALIDATION_FAILED: '提交内容未通过校验，请检查标记的字段。',
    INTERNAL: '系统暂时无法完成此操作，请稍后重试。',
    SERVICE_UNAVAILABLE: '服务暂时不可用，请稍后重试。',
    NETWORK_ERROR: '网络连接失败，请检查网络后重试。',
  }
  return copy[code] ?? '操作未完成，请稍后重试。'
}

export function firstFieldError(fieldErrors: Record<string, string[]>, field: string): string {
  const messages = fieldErrors[field]
  return Array.isArray(messages) && messages.length > 0 ? messages[0] ?? '' : ''
}
