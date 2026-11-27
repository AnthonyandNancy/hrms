<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import {
  assignmentTypeLabel,
  listPositionStaff,
  type PositionStaff,
  type PositionStaffPage,
} from '../api'

const props = defineProps<{
  positionId: number | null
  asOf: string
  canRead: boolean
}>()

defineOptions({ name: 'PositionStaffPanel' })

const loading = ref(false)
const page = ref<PositionStaffPage | null>(null)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)

let requestSequence = 0

async function load(): Promise<void> {
  if (props.positionId === null || !props.canRead) {
    page.value = null
    errorMessage.value = ''
    return
  }

  const sequence = ++requestSequence
  loading.value = true
  errorMessage.value = ''
  errorRequestId.value = null

  try {
    const next = await listPositionStaff(props.positionId, props.asOf)
    if (sequence !== requestSequence) return
    page.value = next
  } catch (error) {
    if (sequence !== requestSequence) return
    page.value = null
    errorMessage.value = error instanceof Error ? error.message : '在岗人员加载失败。'
    errorRequestId.value = error instanceof ApiError ? error.requestId : null
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

function typeLabel(row: PositionStaff): string {
  return assignmentTypeLabel(row.assignment_type)
}

watch(
  () => [props.positionId, props.asOf, props.canRead] as const,
  () => {
    void load()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <section class="staff-panel" data-testid="position-staff-panel">
    <header class="staff-panel__header">
      <div>
        <h3 class="staff-panel__title">在岗人员</h3>
        <p class="staff-panel__subtitle">
          人数与人员来自有效任职（assignment）投影，按业务日期读取；本页不提供人工改写入口。
        </p>
      </div>
      <button
        type="button"
        class="staff-panel__refresh"
        data-testid="position-staff-refresh"
        :disabled="loading || positionId === null || !canRead"
        @click="load"
      >
        刷新
      </button>
    </header>

    <p v-if="!canRead" class="staff-panel__state" data-testid="position-staff-gap">
      当前账号缺少 org.position.staff.read 能力，无法查看在岗人员。
    </p>
    <p v-else-if="positionId === null" class="staff-panel__state">请先选择一个岗位。</p>
    <p v-else-if="loading" class="staff-panel__state" data-testid="position-staff-loading">
      正在加载在岗人员…
    </p>
    <p
      v-else-if="errorMessage !== ''"
      class="staff-panel__state staff-panel__state--error"
      data-testid="position-staff-error"
    >
      {{ errorMessage }}
      <span v-if="errorRequestId">（请求编号 {{ errorRequestId }}）</span>
      <button type="button" class="staff-panel__link" @click="load">重试</button>
    </p>

    <template v-else>
      <p v-if="(page?.items.length ?? 0) === 0" class="staff-panel__state" data-testid="position-staff-empty">
        该岗位在所选业务日期没有在岗任职。
      </p>
      <div v-else class="staff-panel__table-wrap">
        <table class="staff-panel__table" data-testid="position-staff-table">
          <caption class="visually-hidden">在岗人员，共 {{ page?.total ?? 0 }} 条</caption>
          <thead>
            <tr>
              <th scope="col">工号</th>
              <th scope="col">姓名</th>
              <th scope="col">任职类型</th>
              <th scope="col">是否主岗</th>
              <th scope="col">生效日期</th>
              <th scope="col">失效日期</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in page?.items ?? []" :key="row.assignment_id">
              <td class="mono">{{ row.employee_no }}</td>
              <td>{{ row.person_name }}</td>
              <td>{{ typeLabel(row) }}</td>
              <td>{{ row.is_primary ? '是' : '否' }}</td>
              <td>{{ row.valid_from }}</td>
              <td>{{ row.valid_to === '9999-12-31' ? '长期' : row.valid_to }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.staff-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.staff-panel__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.staff-panel__title {
  margin: 0;
  font-size: 13px;
  font-weight: 650;
}

.staff-panel__subtitle {
  margin: 3px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.staff-panel__refresh,
.staff-panel__link {
  color: var(--color-brand);
  font-size: 11px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.staff-panel__refresh {
  padding: 4px 9px;
}

.staff-panel__link {
  padding: 0;
  border: 0;
  text-decoration: underline;
}

.staff-panel__state {
  margin: 0;
  padding: 10px 12px;
  color: var(--color-ink-muted);
  font-size: 12px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}

.staff-panel__state--error {
  color: var(--color-danger);
  background: var(--color-danger-soft);
}

.staff-panel__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.staff-panel__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.staff-panel__table th,
.staff-panel__table td {
  padding: 8px 10px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line);
}

.staff-panel__table th {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
  background: var(--color-canvas);
}
</style>
