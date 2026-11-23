import { computed, ref } from 'vue'

export type AsyncState<T> = {
  data: T | null
  loading: boolean
  error: unknown
  empty: boolean
}

/**
 * Small async state primitive shared by every page. Keeps loading, empty and
 * error semantics consistent and exposes a retry action for error boundaries.
 */
export function useAsync<T>(loader: () => Promise<T>, options: { immediate?: boolean } = {}) {
  const data = ref<T | null>(null) as { value: T | null }
  const loading = ref(false)
  const error = ref<unknown>(null)
  const empty = computed(() => {
    if (data.value === null) return false
    if (Array.isArray(data.value)) return data.value.length === 0
    return false
  })

  let requestSeq = 0

  async function run(): Promise<T | null> {
    const seq = ++requestSeq
    loading.value = true
    error.value = null
    try {
      const value = await loader()
      if (seq === requestSeq) data.value = value
      return value
    } catch (reason) {
      if (seq === requestSeq) error.value = reason
      return null
    } finally {
      if (seq === requestSeq) loading.value = false
    }
  }

  if (options.immediate !== false) {
    void run()
  }

  return { data, loading, error, empty, run }
}
