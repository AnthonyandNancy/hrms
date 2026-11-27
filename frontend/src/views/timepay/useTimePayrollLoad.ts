import { computed, ref } from 'vue'
import { resourceGet } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import type { RelatedEndpoint, TimePayrollPageDefinition } from './types'
import { livePath, recordList } from './utils'

export function useTimePayrollLoad(definition: TimePayrollPageDefinition) {
  const pageError = usePageError()
  const loading = ref(false)
  const loaded = ref(false)
  const payload = ref<unknown>(null)
  const related = ref<Record<string, unknown>>({})
  const relatedErrors = ref<string[]>([])
  const selectedRunId = ref<number | null>(null)

  const rows = computed(() => recordList(payload.value))
  const gap = computed(() => definition.gap ?? null)
  const partial = computed(() => gap.value !== null || relatedErrors.value.length > 0)

  async function loadRelated(endpoints: RelatedEndpoint[]): Promise<void> {
    relatedErrors.value = []
    const values: Record<string, unknown> = {}
    const failures: string[] = []

    await Promise.all(
      endpoints.map(async (entry) => {
        try {
          values[entry.key] = await resourceGet(livePath(entry.endpoint), entry.query)
        } catch {
          failures.push(entry.endpoint)
        }
      }),
    )

    related.value = values
    relatedErrors.value = failures
  }
  async function loadPrimary(): Promise<void> {
    if (definition.panel === 'payroll-inputs') {
      const runsPayload = await resourceGet('/payroll/runs', {
        page: 1,
        page_size: 20,
      })
      const runs = recordList(runsPayload)
      const first = runs[0]
      selectedRunId.value =
        first !== undefined && typeof first.id === 'number' && Number.isSafeInteger(first.id) ? first.id : null
      payload.value =
        selectedRunId.value === null
          ? { items: [], page: 1, page_size: 20, total: 0 }
          : await resourceGet(`/payroll/runs/${selectedRunId.value}/inputs`)
      return
    }

    payload.value = await resourceGet(livePath(definition.endpoint), definition.query)
  }

  async function load(): Promise<void> {
    pageError.clear()
    loading.value = true
    loaded.value = false
    relatedErrors.value = []

    try {
      await loadPrimary()
      if (definition.related !== undefined && definition.related.length > 0) {
        await loadRelated(definition.related)
      }
    } catch (reason) {
      pageError.capture(reason)
    } finally {
      loading.value = false
      loaded.value = true
    }
  }

  function relatedItems(key: string): Record<string, unknown>[] {
    return recordList(related.value[key])
  }

  return {
    pageError,
    loading,
    loaded,
    payload,
    rows,
    related,
    relatedErrors,
    selectedRunId,
    gap,
    partial,
    load,
    relatedItems,
  }
}
