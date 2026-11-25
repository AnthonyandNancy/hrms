import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { requestIdFromError } from '@/utils/renderErrors'

export type ApiFeedbackKind =
  | 'none'
  | 'authentication'
  | 'forbidden'
  | 'no_active_employment'
  | 'validation'
  | 'server'
  | 'client'

export type ApiSafeLink = {
  label: string
  path: string
}

/** Plain replay data; callers that need reactivity use the `feedback` ref. */
export type ApiFeedbackState = {
  kind: ApiFeedbackKind
  message: string
  requestId: string | null
  noActiveEmployment: boolean
  fieldErrors: Record<string, string[]>
  safeLinks: ApiSafeLink[]
}

const NO_ACTIVE_EMPLOYMENT_MESSAGE = '当前账号未绑定员工，员工自助功能不可用'
const NO_ACTIVE_EMPLOYMENT_LINKS: ApiSafeLink[] = [
  { label: '前往系统管理', path: '/system/users' },
  { label: '前往员工档案', path: '/hr/employees' },
]

function emptyFeedback(): ApiFeedbackState {
  return {
    kind: 'none',
    message: '',
    requestId: null,
    noActiveEmployment: false,
    fieldErrors: {},
    safeLinks: [],
  }
}

function feedbackFor(error: unknown): ApiFeedbackState {
  const requestId = requestIdFromError(error)
  const base: ApiFeedbackState = {
    ...emptyFeedback(),
    requestId,
  }

  if (error instanceof ApiError && error.code === 'NO_ACTIVE_EMPLOYMENT') {
    return {
      ...base,
      kind: 'no_active_employment',
      message: NO_ACTIVE_EMPLOYMENT_MESSAGE,
      noActiveEmployment: true,
      safeLinks: NO_ACTIVE_EMPLOYMENT_LINKS.map((link) => ({ ...link })),
    }
  }

  if (error instanceof ApiError && (error.status === 401 || error.code === 'UNAUTHENTICATED')) {
    return {
      ...base,
      kind: 'authentication',
      message: errorCopy(error),
    }
  }

  if (error instanceof ApiError && (error.status === 403 || error.code === 'FORBIDDEN')) {
    return {
      ...base,
      kind: 'forbidden',
      message: errorCopy(error),
    }
  }

  if (error instanceof ApiError && (error.status === 422 || error.code === 'VALIDATION_FAILED')) {
    return {
      ...base,
      kind: 'validation',
      message: errorCopy(error),
      fieldErrors: { ...error.fieldErrors },
    }
  }

  if (
    error instanceof ApiError &&
    (error.status >= 500 || error.code === 'INTERNAL' || error.code === 'SERVICE_UNAVAILABLE')
  ) {
    return {
      ...base,
      kind: 'server',
      message: errorCopy(error),
    }
  }

  return {
    ...base,
    kind: 'client',
    message: errorCopy(error),
  }
}

/**
 * Central API error classification for views and actions.
 *
 * The normalized feedback state is the single render source: it is deliberately
 * not turned into ElMessage.error here, because a page-level ErrorState or
 * EmptyState may be presenting the same failure. Navigation-level outcomes
 * (401 login, 403 safe page) happen once, with the request id preserved so the
 * user can quote it to support.
 */
export function useApiFeedback() {
  const session = useSessionStore()
  const router = useRouter()
  const feedback = ref<ApiFeedbackState>(emptyFeedback())

  function report(error: unknown): ApiFeedbackState {
    const normalized = feedbackFor(error)
    feedback.value = normalized

    if (normalized.kind === 'authentication') {
      session.handleApiError(error)
      if (router.currentRoute.value.name !== 'login') {
        void router.push({ name: 'login', query: { expired: '1' } })
      }
      return normalized
    }

    if (normalized.kind === 'forbidden' && router.currentRoute.value.name !== 'forbidden') {
      void router.push({
        name: 'forbidden',
        query: {
          from: router.currentRoute.value.fullPath,
          request_id: normalized.requestId ?? undefined,
        },
      })
    }

    return normalized
  }

  /** Classify without navigating or mutating the shared state. */
  function classify(error: unknown): ApiFeedbackState {
    return feedbackFor(error)
  }

  function reset(): void {
    feedback.value = emptyFeedback()
  }

  function requestIdOf(error: unknown): string | null {
    return requestIdFromError(error)
  }

  return { report, classify, feedback, reset, requestIdOf }
}
