<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElButton, ElDrawer } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import { requestIdFromError } from '@/utils/renderErrors'
import { listEmployeeImportBatches, type EmployeeImportBatch } from '../listActionsApi'

const props = defineProps<{ modelValue: boolean }>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

defineOptions({ name: 'EmployeeImportDrawer' })

const batches = ref<EmployeeImportBatch[]>([])
const total = ref(0)
const loading = ref(false)
const forbidden = ref(false)
const error = ref<string | null>(null)
const requestId = ref<string | null>(null)
const loaded = ref(false)

const statusLabels: Readonly<Record<string, string>> = {
  VALIDATING: '校验中',
  VALIDATED: '校验完成',
  COMMITTED: '已提交',
  PARTIAL: '部分成功',
  FAILED: '失败',
}

const isEmpty = computed(
  () => loaded.value && !loading.value && !forbidden.value && error.value === null && batches.value.length === 0,
)

function statusLabel(status: string): string {
  return statusLabels[status] ?? '未知状态'
}

function dateTime(value: string | null): string {
  if (value === null) return '—'
  const date = value.slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return '—'
  const [year, month, day] = date.split('-')
  const base = `${year}年${Number(month)}月${Number(day)}日`
  const time = value.slice(11, 16)
  return /^\d{2}:\d{2}$/.test(time) ? `${base} ${time}` : base
}

async function load(): Promise<void> {
  loading.value = true
  forbidden.value = false
  error.value = null
  requestId.value = null

  try {
    const page = await listEmployeeImportBatches(1, 50)
    batches.value = page.items
    total.value = page.total
  } catch (reason) {
    batches.value = []
    total.value = 0
    if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      forbidden.value = true
    } else {
      error.value = errorCopy(reason)
      requestId.value = requestIdFromError(reason)
    }
  } finally {
    loaded.value = true
    loading.value = false
  }
}

function close(): void {
  emit('update:modelValue', false)
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void load()
  },
  { immediate: true },
)
</script>

<template>
  <ElDrawer
    :model-value="modelValue"
    title="导入校验结果"
    size="640px"
    @close="close"
  >
    <section class="import-batches" data-testid="employee-import-batches">
      <header>
        <h3>导入批次</h3>
        <p>这里只展示服务端真实保存的导入批次及其校验汇总，不推测未返回的行级错误。</p>
      </header>

      <div v-if="loading" class="import-batches__state" role="status" data-testid="import-batches-loading">
        正在加载导入批次…
      </div>

      <section v-else-if="forbidden" class="import-batches__state import-batches__state--forbidden" data-testid="import-batches-forbidden">
        无权查看导入批次。
      </section>

      <section v-else-if="error" class="import-batches__state import-batches__state--error" role="alert">
        <p>{{ error }}</p>
        <p v-if="requestId">请求编号：{{ requestId }}</p>
        <ElButton size="small" @click="load">重试</ElButton>
      </section>

      <p v-else-if="isEmpty" class="import-batches__state" data-testid="import-batches-empty">
        暂无导入批次。
      </p>

      <div v-else class="import-batches__table-wrap">
        <table class="import-batches__table">
          <caption class="import-batches__caption">共 {{ total }} 个导入批次</caption>
          <thead>
            <tr>
              <th scope="col">文件名</th>
              <th scope="col">状态</th>
              <th scope="col">总行数</th>
              <th scope="col">有效</th>
              <th scope="col">失败</th>
              <th scope="col">校验时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="batch in batches" :key="batch.id" :data-testid="`import-batch-${batch.id}`">
              <td>{{ batch.originalName }}</td>
              <td>{{ statusLabel(batch.status) }}</td>
              <td>{{ batch.totalRows }}</td>
              <td>{{ batch.validRows }}</td>
              <td>{{ batch.failedRows }}</td>
              <td>{{ dateTime(batch.validatedAt) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <template #footer>
      <ElButton :loading="loading" @click="load">刷新</ElButton>
      <ElButton type="primary" @click="close">关闭</ElButton>
    </template>
  </ElDrawer>
</template>

<style scoped>
.import-batches {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.import-batches h3 {
  margin: 0;
  font-size: 15px;
}
.import-batches p {
  margin: 6px 0 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}
.import-batches__state {
  padding: 18px;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 13px;
  text-align: center;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}
.import-batches__state--forbidden,
.import-batches__state--error {
  color: #991b1b;
  background: #fef2f2;
}
.import-batches__table-wrap {
  overflow-x: auto;
}
.import-batches__table {
  width: 100%;
  font-size: 12px;
  border-collapse: collapse;
}
.import-batches__table th,
.import-batches__table td {
  padding: 8px 9px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line, #e5e7eb);
}
.import-batches__table th {
  color: var(--color-ink-muted, #6b7280);
  background: var(--color-canvas, #f9fafb);
}
.import-batches__caption {
  margin-bottom: 6px;
  color: var(--color-ink-muted, #6b7280);
  font-size: 11px;
  text-align: left;
}
</style>
