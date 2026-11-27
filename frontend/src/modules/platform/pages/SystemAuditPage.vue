<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { ApiError } from '@/api/errors'
import { formatDateTimeInTimezone } from '@/utils/format'
import { isForbidden, platformErrorCopy } from '../api'
import {
  exportAuthEvents,
  listAuthEvents,
  type AuthEvent,
  type AuthEventQuery,
} from '../api/audit'
import {
  authEventLabel,
  authEventResource,
  authResultLabel,
  authResultTone,
  metadataRows,
} from '../labels'
import AuditHealthPanel from '../components/AuditHealthPanel.vue'

const session = useSessionStore()
const canRead = computed(() => session.can('iam.audit.read'))

const loading = ref(true)
const exporting = ref(false)
const loadError = ref<unknown>(null)
const exportError = ref('')
const exportMessage = ref('')
const events = ref<AuthEvent[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const selected = ref<AuthEvent | null>(null)

const RESULT_FILTERS = [
  { value: '', label: '全部结果' },
  { value: 'SUCCESS', label: '成功' },
  { value: 'FAILURE', label: '失败' },
  { value: 'DENIED', label: '已拒绝' },
]

const filters = reactive({
  actor: '',
  action: '',
  result: '',
  from: '',
  to: '',
})

function query(): AuthEventQuery {
  return {
    page: page.value,
    pageSize: pageSize.value,
    actor: filters.actor.trim(),
    action: filters.action.trim(),
    result: filters.result,
    from: filters.from,
    to: filters.to,
  }
}

const rows = computed(() => events.value.map((event) => ({
  ...event,
  actionLabel: authEventLabel(event.eventType),
  resourceLabel: authEventResource(event.eventType),
  resultLabel: authResultLabel(event.result),
  resultTone: `plain-chip--${authResultTone(event.result)}`,
  actorLabel: event.actorUserId === null ? '系统' : `用户 ${event.actorUserId}`,
  requestLabel: event.requestId ?? '—',
  timeLabel: formatDateTimeInTimezone(event.occurredAt),
})))

const isEmpty = computed(() => !loading.value && loadError.value === null && events.value.length === 0)
const emptyTitle = computed(() => '当前筛选下没有审计事件')

const errorRequestId = computed(() => {
  const error = loadError.value
  return error instanceof ApiError ? error.requestId ?? '' : ''
})

const columns: DataTableColumn[] = [
  { key: 'timeLabel', label: '发生时间', width: '150px' },
  { key: 'actorLabel', label: '操作人', width: '110px' },
  { key: 'actionLabel', label: '动作', minWidth: '150px' },
  { key: 'resourceLabel', label: '资源', width: '120px' },
  { key: 'resultLabel', label: '结果', width: '90px' },
  { key: 'requestLabel', label: '请求编号', minWidth: '170px' },
  { key: 'actions', label: '明细', width: '80px' },
]

const selectedMetadata = computed(() => (selected.value === null ? [] : metadataRows(selected.value.metadata)))

function resetFilters(): void {
  filters.actor = ''
  filters.action = ''
  filters.result = ''
  filters.from = ''
  filters.to = ''
  page.value = 1
  void loadEvents()
}

function applyFilters(): void {
  page.value = 1
  selected.value = null
  void loadEvents()
}

async function loadEvents(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const result = await listAuthEvents(query())
    events.value = result.items
    total.value = result.total
    page.value = result.page
    pageSize.value = result.pageSize
  } catch (error) {
    events.value = []
    total.value = 0
    loadError.value = error
  } finally {
    loading.value = false
  }
}

async function exportEvents(): Promise<void> {
  exporting.value = true
  exportError.value = ''
  exportMessage.value = ''
  try {
    const result = await exportAuthEvents(query())
    exportMessage.value = `已导出 ${result.rows.length} 条安全事件；导出字段：${result.fields.join('、')}。`
    ElMessage.success(exportMessage.value)
  } catch (error) {
    exportError.value = platformErrorCopy(error)
    ElMessage.error(exportError.value)
  } finally {
    exporting.value = false
  }
}

function inspect(event: AuthEvent): void {
  selected.value = event
}

function changePage(next: number): void {
  page.value = next
  selected.value = null
  void loadEvents()
}

function changePageSize(next: number): void {
  pageSize.value = next
  page.value = 1
  selected.value = null
  void loadEvents()
}

onMounted(() => {
  if (!canRead.value) {
    loading.value = false
    return
  }
  void loadEvents()
})
</script>

<template>
  <PageShell
    data-testid="system-audit-page"
    title="日志审计与运维"
    description="查阅登录与授权安全事件，按操作人、动作、结果与日期在服务端筛选；导出只包含白名单字段，并记录敏感访问。"
  >
    <template #actions>
      <el-button plain :disabled="loading" @click="loadEvents">刷新</el-button>
      <el-button
        v-if="canRead"
        type="primary"
        plain
        :loading="exporting"
        data-testid="audit-export"
        @click="exportEvents"
      >
        导出安全审计
      </el-button>
    </template>

    <section v-if="!canRead" class="panel" data-testid="audit-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看审计日志</h2>
        <p class="muted">审计日志包含敏感安全证据，仅授权审计人员可查看。如需访问，请提交授权申请。</p>
      </div>
    </section>

    <template v-else>
      <AuditHealthPanel />

      <section class="panel" data-testid="audit-filters">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">事件筛选</h2>
            <p class="panel__subtitle">筛选在服务端执行，作用于全部审计记录，而不是当前页。</p>
          </div>
          <el-button text @click="resetFilters">清除筛选</el-button>
        </div>
        <div class="audit-filters">
          <label class="audit-field">
            <span>操作人用户编号</span>
            <el-input v-model="filters.actor" data-testid="audit-filter-actor" placeholder="例如 15" clearable />
          </label>
          <label class="audit-field">
            <span>动作代码</span>
            <el-input v-model="filters.action" data-testid="audit-filter-action" placeholder="例如 login" clearable />
          </label>
          <div class="audit-field" role="group" aria-label="按结果筛选">
            <span>结果</span>
            <div class="audit-result-chips">
              <button
                v-for="option in RESULT_FILTERS"
                :key="option.value"
                type="button"
                class="audit-result-chip"
                :class="{ 'audit-result-chip--active': filters.result === option.value }"
                :aria-pressed="filters.result === option.value"
                :data-testid="`audit-filter-result-${option.value || 'all'}`"
                @click="filters.result = option.value"
              >
                {{ option.label }}
              </button>
            </div>
          </div>
          <label class="audit-field">
            <span>开始日期</span>
            <el-date-picker v-model="filters.from" data-testid="audit-filter-from" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" />
          </label>
          <label class="audit-field">
            <span>结束日期</span>
            <el-date-picker v-model="filters.to" data-testid="audit-filter-to" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" />
          </label>
          <div class="audit-field audit-field--action">
            <span>&nbsp;</span>
            <el-button type="primary" data-testid="audit-filter-apply" @click="applyFilters">应用服务端筛选</el-button>
          </div>
        </div>
      </section>

      <p v-if="exportMessage" class="inline-success" data-testid="audit-export-message">{{ exportMessage }}</p>
      <p v-if="exportError" class="inline-error" role="alert" data-testid="audit-export-error">{{ exportError }}</p>

      <section v-if="loading" class="panel" data-testid="audit-loading">
        <div class="panel__body">
          <el-skeleton :rows="5" animated />
          <span class="visually-hidden">正在加载审计日志</span>
        </div>
      </section>

      <section v-else-if="loadError !== null && isForbidden(loadError)" class="panel" data-testid="audit-forbidden">
        <div class="panel__body">
          <h2 class="panel__title">审计日志不可查看</h2>
          <p class="muted">当前账号没有审计读取权限。审计权限与普通系统管理权限分离，请联系安全管理员。</p>
        </div>
      </section>

      <section v-else-if="loadError !== null" class="panel" data-testid="audit-error">
        <div class="panel__body">
          <h2 class="panel__title">审计日志加载失败</h2>
          <p class="muted">{{ platformErrorCopy(loadError) }}</p>
          <p v-if="errorRequestId" class="muted small">问题反馈编号：<span class="mono">{{ errorRequestId }}</span></p>
          <el-button type="primary" plain @click="loadEvents">重试</el-button>
        </div>
      </section>

      <section v-else-if="isEmpty" class="panel" data-testid="audit-empty">
        <div class="panel__body">
          <h2 class="panel__title">{{ emptyTitle }}</h2>
          <p class="muted">调整操作人、动作、结果或日期后重新筛选。</p>
        </div>
      </section>

      <section v-else class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">安全审计事件</h2>
            <p class="panel__subtitle">服务端匹配 {{ total }} 条记录；当前页 {{ events.length }} 条。</p>
          </div>
        </div>
        <DataTable
          :columns="columns"
          :rows="rows as unknown as Array<Record<string, unknown>>"
          :total="total"
          :page="page"
          :page-size="pageSize"
          :loading="loading"
          row-key="id"
          :row-test-id="(row) => `audit-row-${row.id}`"
          data-testid="audit-table"
          empty-title="当前筛选下没有审计事件"
          @update:page="changePage"
          @update:page-size="changePageSize"
        >
          <template #cell-timeLabel="{ row }">
            <span class="mono">{{ row.timeLabel }}</span>
          </template>
          <template #cell-resultLabel="{ row }">
            <span class="plain-chip" :class="row.resultTone as string">{{ row.resultLabel }}</span>
          </template>
          <template #cell-requestLabel="{ row }">
            <span class="mono">{{ row.requestLabel }}</span>
          </template>
          <template #cell-actions="{ row }">
            <el-button text size="small" @click="inspect(events.find((entry) => entry.id === row.id) ?? (row as unknown as AuthEvent))">
              查看明细
            </el-button>
          </template>
        </DataTable>
      </section>

      <el-dialog
        :model-value="selected !== null"
        title="审计事件明细"
        width="560px"
        data-testid="audit-detail-dialog"
        @close="selected = null"
      >
        <div v-if="selected !== null" class="audit-detail">
          <el-descriptions :column="1" border>
            <el-descriptions-item label="发生时间">{{ formatDateTimeInTimezone(selected.occurredAt) }}</el-descriptions-item>
            <el-descriptions-item label="操作人">
              {{ selected.actorUserId === null ? '系统' : `用户 ${selected.actorUserId}` }}
            </el-descriptions-item>
            <el-descriptions-item label="动作">{{ authEventLabel(selected.eventType) }}</el-descriptions-item>
            <el-descriptions-item label="资源">{{ authEventResource(selected.eventType) }}</el-descriptions-item>
            <el-descriptions-item label="结果">{{ authResultLabel(selected.result) }}</el-descriptions-item>
            <el-descriptions-item label="请求编号">
              <span class="mono" data-testid="audit-detail-request-id">{{ selected.requestId ?? '—' }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="事件代码">
              <span class="mono" data-testid="audit-detail-event-code">{{ selected.eventType }}</span>
            </el-descriptions-item>
          </el-descriptions>
          <h3 class="panel__title">上下文</h3>
          <dl v-if="selectedMetadata.length > 0" class="audit-metadata">
            <div v-for="entry in selectedMetadata" :key="entry.label">
              <dt>{{ entry.label }}</dt>
              <dd>{{ entry.value }}</dd>
            </div>
          </dl>
          <p v-else class="muted">该事件没有可展示的上下文字段。</p>
        </div>
        <template #footer>
          <el-button @click="selected = null">关闭</el-button>
        </template>
      </el-dialog>
    </template>
  </PageShell>
</template>

<style scoped>
.audit-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 0 16px 16px;
}
.audit-result-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.audit-result-chip {
  padding: 3px 8px;
  color: var(--color-ink-secondary);
  font: inherit;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}
.audit-result-chip--active {
  color: var(--color-brand-deep, #0f4956);
  background: #eaf4f7;
  border-color: #a8d2dc;
}
.audit-field {
  display: flex;
  flex: 1 1 150px;
  flex-direction: column;
  gap: 5px;
  font-size: 12px;
  font-weight: 600;
}
.audit-field--action {
  flex: 0 0 auto;
}
.audit-detail {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.audit-metadata {
  display: grid;
  gap: 8px;
  margin: 0;
}
.audit-metadata div {
  display: flex;
  gap: 10px;
  font-size: 12px;
}
.audit-metadata dt {
  min-width: 90px;
  color: var(--color-ink-muted);
}
.audit-metadata dd {
  margin: 0;
}
.plain-chip--success {
  color: #146c43;
  background: #e8f6ee;
  border-color: #b7dcc7;
}
.plain-chip--danger {
  color: #a12828;
  background: #fdecec;
  border-color: #efc2c2;
}
.plain-chip--warning {
  color: #8a5a00;
  background: #fff6e5;
  border-color: #ead49f;
}
.plain-chip--muted {
  color: var(--color-ink-muted);
}
.inline-success,
.inline-error {
  padding: 9px 12px;
  margin: 0;
  font-size: 12px;
  border-radius: 7px;
}
.inline-success {
  color: #146c43;
  background: #e8f6ee;
  border: 1px solid #b7dcc7;
}
.inline-error {
  color: #a12828;
  background: #fdecec;
  border: 1px solid #efc2c2;
}
</style>
