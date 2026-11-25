import { computed, ref } from 'vue'
import type { ApiFeedbackState } from '@/composables/useApiFeedback'
import { useApiFeedback } from '@/composables/useApiFeedback'
import { errorCopy } from '@/api/errors'
import { requestIdFromError } from '@/utils/renderErrors'

/**
 * Page-load error state that preserves the original error.
 *
 * A page must not reduce an ApiError to a string: the request id and the stable
 * business code are part of the user-facing recovery contract. `report` also
 * runs the shared 401/403 routing once, while `feedback` lets the page render a
 * dedicated NO_ACTIVE_EMPLOYMENT state without duplicating the classification.
 */
export function usePageError() {
  const feedbackApi = useApiFeedback()
  const error = ref<unknown>(null)
  const message = computed(() => (error.value === null ? '' : errorCopy(error.value)))
  const requestId = computed(() => (error.value === null ? null : requestIdFromError(error.value)))
  const code = computed(() => {
    if (error.value instanceof Error && 'code' in error.value && typeof error.value.code === 'string') {
      return error.value.code
    }
    return 'UNKNOWN'
  })
  const feedback = computed<ApiFeedbackState>(() => {
    if (error.value === null) {
      return {
        kind: 'none',
        message: '',
        requestId: null,
        noActiveEmployment: false,
        fieldErrors: {},
        safeLinks: [],
      }
    }

    return feedbackApi.classify(error.value)
  })
  const noActiveEmployment = computed(() => feedback.value.kind === 'no_active_employment')

  function clear(): void {
    error.value = null
    feedbackApi.reset()
  }

  function capture(reason: unknown): void {
    error.value = reason
    // 401/403 navigate once; 409/422/5xx stay on the page for its own state.
    feedbackApi.report(reason)
  }

  function reject(reason: unknown): void {
    error.value = reason
  }

  const safeLinks = computed(() => feedback.value.safeLinks)
  const fieldErrors = computed(() => feedback.value.fieldErrors)

  return {
    error,
    message,
    requestId,
    code,
    feedback,
    noActiveEmployment,
    safeLinks,
    fieldErrors,
    clear,
    capture,
    reject,
  }
}
