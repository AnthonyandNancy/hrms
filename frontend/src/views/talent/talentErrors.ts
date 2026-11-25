import { ApiError, errorCopy } from '@/api/errors'

export type TalentErrorInfo = {
  code: string
  status: number | null
  message: string
  requestId: string | null
  reason: string | null
  fieldErrors: Record<string, string[]>
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function stringValue(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null
}

function nestedReason(value: unknown): string | null {
  if (!isRecord(value)) return null

  const direct = stringValue(value.reason)
  if (direct !== null) return direct

  const data = value.data
  if (isRecord(data)) {
    const dataReason = stringValue(data.reason)
    if (dataReason !== null) return dataReason

    const details = data.details
    if (isRecord(details)) {
      const detailsReason = stringValue(details.reason)
      if (detailsReason !== null) return detailsReason
    }
  }

  const details = value.details
  if (isRecord(details)) {
    const detailsReason = stringValue(details.reason)
    if (detailsReason !== null) return detailsReason
  }

  const response = value.response
  if (isRecord(response)) {
    const responseData = response.data
    if (isRecord(responseData)) {
      const responseReason = nestedReason(responseData)
      if (responseReason !== null) return responseReason
    }
  }

  return null
}

/**
 * Normalise both the shared ApiError and a raw server envelope.
 *
 * `resourceClient` currently projects the envelope to ApiError. This helper
 * keeps the stable `data.reason` readable for clients/tests that retain the
 * original envelope, and is the single render source for form-level errors.
 */
export function talentErrorInfo(error: unknown): TalentErrorInfo {
  if (error instanceof ApiError) {
    return {
      code: error.code,
      status: error.status,
      message: errorCopy(error),
      requestId: error.requestId,
      reason: nestedReason(error),
      fieldErrors: { ...error.fieldErrors },
    }
  }

  const record = isRecord(error) ? error : null
  const code = (record !== null ? stringValue(record.code) : null) ?? 'UNKNOWN'
  const statusValue = record !== null ? record.status : null
  const status = typeof statusValue === 'number' ? statusValue : null
  const message =
    (record !== null ? stringValue(record.message) : null) ??
    (error instanceof Error ? `操作未完成：${error.message}` : '操作未完成，请稍后重试。')
  const requestId =
    (record !== null ? stringValue(record.request_id) ?? stringValue(record.requestId) : null) ?? null
  const reason = nestedReason(error)

  let fieldErrors: Record<string, string[]> = {}
  if (record !== null && isRecord(record.data) && isRecord(record.data.fields)) {
    fieldErrors = Object.fromEntries(
      Object.entries(record.data.fields).flatMap(([field, messages]) => {
        if (!Array.isArray(messages)) return []
        const strings = messages.filter((message): message is string => typeof message === 'string')
        return strings.length > 0 ? [[field, strings]] : []
      }),
    )
  }

  return { code, status, message, requestId, reason, fieldErrors }
}
