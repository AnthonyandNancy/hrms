import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { ref } from 'vue'

/**
 * Central error presentation: session expiry is escalated to the shell, other
 * failures get human-readable Chinese copy and retain a request id for support.
 */
export function useApiFeedback() {
  const session = useSessionStore()
  const router = useRouter()
  const lastError = ref<string | null>(null)

  function report(error: unknown): void {
    if (session.handleApiError(error)) {
      void router.push({ name: 'login', query: { expired: '1' } })
      return
    }
    lastError.value = errorCopy(error)
    ElMessage.error(lastError.value)
  }

  function requestIdOf(error: unknown): string | null {
    return error instanceof ApiError ? error.requestId : null
  }

  return { report, lastError, requestIdOf }
}
