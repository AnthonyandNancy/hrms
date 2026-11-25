<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import type { PageSpec } from '@/router/registry'
import { resourceGet } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'

const props = withDefaults(
  defineProps<{
    page: PageSpec
    title?: string
    description?: string
    query?: Record<string, unknown>
  }>(),
  { title: '', description: '', query: () => ({}) },
)

const route = useRoute()
const pageError = usePageError()
const loading = ref(false)
const items = ref<unknown[]>([])
const payload = ref<unknown>(null)
const total = ref(0)
const loaded = ref(false)

const readOperation = computed(() => props.page.requiredApis.find((operation) => operation.startsWith('GET ')))
const endpoint = computed(() => readOperation.value?.slice(4).trim() ?? '')
const pageTitle = computed(() => props.title || props.page.menu?.label || props.page.name)
const pageDescription = computed(
  () => props.description || `页面资源 ${props.page.componentKey}，按控件能力 ${props.page.capability} 访问。`,
)

const queryParams = computed<Record<string, unknown>>(() => ({
  ...(route.params.id ? { employment_id: Number(route.params.id) } : {}),
  ...props.query,
}))

const columns = computed<string[]>(() => {
  const first = items.value.find((item) => item !== null && typeof item === 'object' && !Array.isArray(item))
  if (first === undefined) return []
  return Object.keys(first as Record<string, unknown>).slice(0, 12)
})

function cellValue(row: unknown, key: string): string {
  if (row === null || typeof row !== 'object') return '—'
  const value = (row as Record<string, unknown>)[key]
  if (value === null || value === undefined) return '—'
  if (typeof value === 'object') {
    const record = value as Record<string, unknown>
    if ('display' in record) return typeof record.display === 'string' ? record.display : '已脱敏'
    if ('name' in record) return String(record.name)
    return '—'
  }
  return String(value)
}

function normalise(data: unknown): void {
  payload.value = data
  if (Array.isArray(data)) {
    items.value = data
    total.value = data.length
    return
  }

  if (data !== null && typeof data === 'object' && 'items' in data && Array.isArray((data as { items?: unknown[] }).items)) {
    const list = (data as { items: unknown[]; total?: number; page?: number; page_size?: number })
    items.value = list.items
    total.value = list.total ?? list.items.length
    return
  }

  items.value = []
  total.value = 0
}

async function load(): Promise<void> {
  pageError.clear()
  loaded.value = false

  if (endpoint.value === '') {
    items.value = []
    payload.value = null
    loaded.value = true
    return
  }

  loading.value = true
  try {
    normalise(await resourceGet(endpoint.value, queryParams.value))
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

onMounted(load)
</script>

<template>
  <div class="page resource-page" :data-page="page.name">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">{{ page.menu?.group ?? '工作台' }}</p>
        <h1 class="page-header__title">{{ pageTitle }}</h1>
        <p class="page-header__description">{{ pageDescription }}</p>
      </div>
      <div class="page-header__actions">
        <el-button @click="load">刷新</el-button>
      </div>
    </header>

    <NoActiveEmploymentState
      v-if="pageError.noActiveEmployment.value"
      :request-id="pageError.requestId.value"
      :links="pageError.safeLinks.value"
    />
    <ErrorState
      v-else-if="pageError.message.value"
      :title="`${pageTitle}暂时无法加载`"
      :description="pageError.message.value"
      :code="pageError.code.value"
      :request-id="pageError.requestId.value"
      @retry="load"
    />
    <LoadingState v-else-if="loading" :rows="5" :label="`正在加载${pageTitle}`" />
    <EmptyState
      v-else-if="loaded && items.length === 0"
      :title="readOperation ? '当前没有数据' : '此页面暂无可展示的列表接口'"
      :description="readOperation ? '当前筛选条件下没有记录。' : '请通过本页面的操作入口维护数据。'"
    />
    <section v-else-if="items.length > 0" class="resource-page__content">
      <p class="resource-page__summary">共 {{ total }} 条记录</p>
      <div class="resource-page__table-wrap">
        <table class="resource-page__table">
          <caption class="sr-only">{{ pageTitle }}数据表</caption>
          <thead>
            <tr>
              <th v-for="column in columns" :key="column" scope="col">{{ column }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in items" :key="index">
              <td v-for="column in columns" :key="column">{{ cellValue(row, column) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.resource-page__summary {
  margin-bottom: 8px;
  color: var(--color-ink-muted);
  font-size: 12px;
}

.resource-page__table-wrap {
  overflow: auto;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.resource-page__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.resource-page__table th,
.resource-page__table td {
  padding: 9px 11px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line);
}

.resource-page__table th {
  color: var(--color-ink-muted);
  font-weight: 600;
  background: var(--color-canvas);
}
</style>
