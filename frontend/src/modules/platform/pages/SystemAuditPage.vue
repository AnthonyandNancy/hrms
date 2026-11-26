<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone } from '@/utils/format'
import {
  isForbidden,
  listAuthEvents,
  platformErrorCopy,
  type AuthEvent,
} from '../api'
import {
  authEventLabel,
  authEventResource,
  authResultLabel,
  authResultTone,
  metadataRows,
} from '../labels'

const session = useSessionStore()
const canRead = computed(() => session.can('iam.audit.read'))

const loading = ref(true)
const loadError = ref<unknown>(null)
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

const visibleEvents = computed(() =>
  events.value.filter((event) => {
    if (filters.actor !== '' && String(event.actorUserId ?? '') !== filters.actor.trim()) return false
    if (filters.action !== '' && event.eventType !== filters.action) return false
    if (filters.result !== '' && event.result !== filters.result) return false
    if (filters.from !== '' && event.occurredAt.slice(0, 10) < filters.from) return false
    if (filters.to !== '' && event.occurredAt.slice(0, 10) > filters.to) return false
    return true
  }),
)

const rows = computed(() =>
  visibleEvents.value.map((event) => ({
    ...event,
    actionLabel: authEventLabel(event.eventType),
    resourceLabel: authEventResource(event.eventType),
    resultLabel: authResultLabel(event.result),
    resultTone: `plain-chip--${authResultTone(event.result)}`,
    actorLabel: event.actorUserId === null ? '系统' : `用户 #${event.actorUserId}`,
    requestLabel: event.requestId ?? '—',
    timeLabel: formatDateTimeInTimezone(event.occurredAt),
  })),
)

const isEmpty = computed(() => !loading.value && loadError.value === null && rows.value.length === 0)

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
}

async function loadEvents(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    // The endpoint exposes page/page_size only. Bounded server pagination is
    // read once; filters below operate on the loaded projection and say so.
    const result = await listAuthEvents()
    events.value = result.items
    total.value = result.total
  } catch (error) {
    events.value = []
    total.value = 0
    loadError.value = error
  } finally {
    loading.value = false
  }
}

function inspect(event: AuthEvent): void {
  selected.value = event
}

function closeDetail(): void {
  selected.value = null
}

function retry(): void {
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
    description="查阅账号与授权安全事件，包括操作人、动作、资源、结果与请求编号；审计记录不可修改或删除。"
  >
    <template #actions>
      <el-button plain :disabled="loading" @click="retry">刷新</el-button>
    </template>

    <section v-if="!canRead" class="panel" data-testid="audit-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看审计日志</h2>
        <p class="muted">审计日志包含敏感安全证据，仅授权审计人员可查看。如需访问，请提交授权申请。</p>
      </div>
    </section>

    <template v-else>
      <section class="panel" data-testid="audit-filters">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">事件筛选</h2>
            <p class="panel__subtitle">服务端当前仅按分页返回最近 100 条；筛选仅作用于已加载记录，明细固定显示请求编号。</p>
          </div>
          <el-button text @click="resetFilters">清除筛选</el-button>
        </div>
        <div class="audit-filters">
          <label class="audit-field">
            <span>操作人用户编号</span>
            <el-input v-model="filters.actor" data-testid="audit-filter-actor" placeholder="例如 15" clearable />
          </label>
          <label class="audit-field">
            <span>动作</span>
            <el-input v-model="filters.action" data-testid="audit-filter-action" placeholder="搜索动作" clearable />
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
        </div>
      </section>

      <p v-if="!loading && loadError === null" class="inline-warning" data-testid="audit-partial">
        筛选仅作用于已加载的 {{ events.length }} 条审计记录；服务端当前未开放日期、操作人与动作筛选，跨页筛选需等待接口补齐。
      </p>

      <section v-if="loading" class="panel" data-testid="audit-loading">
        <div class="panel__body">
          <el-skeleton :rows="5" animated />
          <span class="visually-hidden">正在加载审计日志</span>
        </div>
      </section>

      <section
        v-else-if="loadError !== null && isForbidden(loadError)"
        class="panel"
        data-testid="audit-forbidden"
      >
        <div class="panel__body">
          <h2 class="panel__title">审计日志不可查看</h2>
          <p class="muted">当前账号没有审计读取权限。审计权限与普通系统管理权限分离，请联系安全管理员。</p>
        </div>
      </section>

      <section v-else-if="loadError !== null" class="panel" data-testid="audit-error">
        <div class="panel__body">
          <h2 class="panel__title">审计日志加载失败</h2>
          <p class="muted">{{ platformErrorCopy(loadError) }}</p>
          <el-button type="primary" plain @click="retry">重试</el-button>
        </div>
      </section>

      <section v-else-if="isEmpty" class="panel" data-testid="audit-empty">
        <div class="panel__body">
          <h2 class="panel__title">当前筛选下没有审计事件</h2>
          <p class="muted">调整日期、操作人或动作筛选后重试；已加载记录数 {{ events.length }}。</p>
        </div>
      </section>

      <section v-else class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">安全审计事件</h2>
            <p class="panel__subtitle">共 {{ total }} 条记录；当前筛选可见 {{ rows.length }} 条。</p>
          </div>
        </div>
        <DataTable
          :columns="columns"
          :rows="rows as unknown as Array<Record<string, unknown>>"
          :total="rows.length"
          :page="page"
          :page-size="pageSize"
          row-key="id"
          :row-test-id="(row) => `audit-row-${row.id}`"
          data-testid="audit-table"
          empty-title="当前筛选下没有审计事件"
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
        <div class="audit-pagination">
          <span class="muted small">第 {{ page }} 页 · 每页 {{ pageSize }} 条 · 共 {{ total }} 条</span>
        </div>
      </section>

      <el-dialog
        :model-value="selected !== null"
        title="审计事件明细"
        width="560px"
        data-testid="audit-detail-dialog"
        @close="closeDetail"
      >
        <div v-if="selected !== null" class="audit-detail">
          <el-descriptions :column="1" border>
            <el-descriptions-item label="发生时间">{{ formatDateTimeInTimezone(selected.occurredAt) }}</el-descriptions-item>
            <el-descriptions-item label="操作人">
              {{ selected.actorUserId === null ? '系统' : `用户 #${selected.actorUserId}` }}
            </el-descriptions-item>
            <el-descriptions-item label="动作">{{ authEventLabel(selected.eventType) }}</el-descriptions-item>
            <el-descriptions-item label="资源">{{ authEventResource(selected.eventType) }}</el-descriptions-item>
            <el-descriptions-item label="结果">{{ authResultLabel(selected.result) }}</el-descriptions-item>
            <el-descriptions-item label="请求编号">
              <span class="mono">{{ selected.requestId ?? '—' }}</span>
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
          <el-button @click="closeDetail">关闭</el-button>
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
.audit-pagination {
  display: flex;
  justify-content: flex-end;
  padding: 10px 16px;
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
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
</style>
