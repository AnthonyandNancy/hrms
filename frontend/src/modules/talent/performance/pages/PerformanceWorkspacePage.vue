<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'
import {
  listPerformanceCycles,
  performanceCycleStage,
  type PerformanceCycleSummary,
} from '../api'

defineOptions({ name: 'PerformanceWorkspacePage' })

const session = useSessionStore()

const canRead = computed(() => session.can('performance.cycle.read'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')

const cycles = ref<PerformanceCycleSummary[]>([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const partial = ref(false)

const statusFilter = ref('')
const nameFilter = ref('')

const STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: '全部状态' },
  { value: DISPLAY_CODES.performanceCycleStatus.draft, label: translate('talent', 'performanceCycleStatus', DISPLAY_CODES.performanceCycleStatus.draft).label },
  { value: DISPLAY_CODES.performanceCycleStatus.active, label: translate('talent', 'performanceCycleStatus', DISPLAY_CODES.performanceCycleStatus.active).label },
  { value: DISPLAY_CODES.performanceCycleStatus.scoring, label: translate('talent', 'performanceCycleStatus', DISPLAY_CODES.performanceCycleStatus.scoring).label },
  { value: DISPLAY_CODES.performanceCycleStatus.published, label: translate('talent', 'performanceCycleStatus', DISPLAY_CODES.performanceCycleStatus.published).label },
]

const cycleColumns: DataTableColumn[] = [
  { key: 'name', label: '周期名称', minWidth: '160px' },
  { key: 'period', label: '考核期间', minWidth: '180px' },
  { key: 'stage', label: '状态', width: '120px' },
  { key: 'participants', label: '参与人数', width: '100px', align: 'right' },
  { key: 'submitted', label: '已提交', width: '100px', align: 'right' },
  { key: 'progress', label: '进度', minWidth: '150px' },
  { key: 'actions', label: '操作', width: '110px' },
]

const filtersActive = computed(() => statusFilter.value !== '' || nameFilter.value.trim() !== '')
const showEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && cycles.value.length === 0,
)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value =
      reason.code === 'FORBIDDEN'
        ? '无权读取绩效周期。请确认已获得 performance.cycle.read 能力，并且当前账号在授权范围内。'
        : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '绩效工作台暂时无法加载。'
  }
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  partial.value = false

  try {
    const result = await listPerformanceCycles(
      { status: statusFilter.value, q: nameFilter.value },
      page.value,
      pageSize.value,
    )
    cycles.value = result.items
    page.value = result.page
    pageSize.value = result.pageSize
    total.value = result.total
    partial.value = result.partial
  } catch (reason) {
    cycles.value = []
    total.value = 0
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function applyFilters(): void {
  page.value = 1
  void load()
}

function clearFilters(): void {
  statusFilter.value = ''
  nameFilter.value = ''
  page.value = 1
  void load()
}

function changePage(next: number): void {
  if (next < 1 || next > totalPages.value) return
  page.value = next
  void load()
}

function periodLabel(cycle: PerformanceCycleSummary): string {
  return `${cycle.periodFrom} 至 ${cycle.periodTo}`
}

function stageTone(cycle: PerformanceCycleSummary): string {
  const stage = performanceCycleStage(cycle)
  switch (stage) {
    case '草稿':
      return 'draft'
    case '进行中':
      return 'active'
    case '评分中':
      return 'scoring'
    case '已结束':
      return 'published'
    default:
      return 'active'
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="performance-workspace" data-page="performance-workspace">
    <header class="performance-workspace__header">
      <div>
        <p class="performance-workspace__breadcrumb">人才 · 绩效考核</p>
        <h1 class="performance-workspace__title">绩效考核工作台</h1>
        <p class="performance-workspace__description">
          按周期查看参与人数、已提交评估与完成进度。进入周期详情可为直接下属评分；最终等级与结果发布由服务端计算和控制。
        </p>
      </div>
      <div class="performance-workspace__header-actions">
        <button type="button" class="performance-workspace__secondary" :disabled="loading" @click="load">
          刷新
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="performance-workspace__state" role="alert" data-testid="performance-forbidden">
      <h2>无权访问绩效考核</h2>
      <p>当前账号缺少 performance.cycle.read 能力，页面不会发起任何绩效周期读取请求。</p>
    </section>

    <template v-else>
      <section class="performance-filters" aria-label="绩效周期筛选">
        <label>
          <span>周期名称</span>
          <el-input
            v-model="nameFilter"
            type="text"
            maxlength="120"
            placeholder="例如 2026"
            data-testid="performance-name-filter"
            @keyup.enter="applyFilters"
          />
        </label>
        <label>
          <span>状态</span>
          <el-select v-model="statusFilter" data-testid="performance-status-filter" @change="applyFilters">
            <el-option
              v-for="option in STATUS_OPTIONS"
              :key="option.value"
              :value="option.value"
              :label="option.label"
            />
          </el-select>
        </label>
        <div class="performance-filters__actions">
          <button type="button" class="performance-workspace__primary" :disabled="loading" data-testid="performance-filter-submit" @click="applyFilters">
            查询
          </button>
          <button type="button" class="performance-workspace__secondary" :disabled="loading || !filtersActive" @click="clearFilters">
            清除筛选
          </button>
        </div>
      </section>

      <ErrorState
        v-if="errorMessage !== ''"
        title="绩效考核工作台暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载绩效周期" />

      <template v-else>
        <p v-if="partial" class="performance-workspace__partial" role="status" data-testid="performance-partial">
          部分数据：服务端标记本次绩效周期投影不完整，页面只展示已返回的周期，不会补造行或人数。
        </p>

        <EmptyState
          v-if="showEmpty"
          :title="filtersActive ? '当前筛选没有绩效周期' : '还没有绩效周期'"
          :description="
            filtersActive
              ? '没有匹配该名称或状态的周期。清除筛选后可查看当前账号范围内的全部周期。'
              : '人力资源创建绩效周期后，这里会展示参与人数、提交进度与周期状态。'
          "
          :action-label="filtersActive ? '清除筛选' : undefined"
          @action="clearFilters"
        />

        <section v-else class="panel performance-table-panel" aria-label="绩效周期列表">
          <header class="panel__header">
            <div>
              <h2 class="panel__title">绩效周期</h2>
              <p class="panel__subtitle">共 {{ total }} 个周期 · 进度 = 已提交评估 / 参与人数（服务端聚合）</p>
            </div>
          </header>
          <div class="panel__body panel__body--flush">
            <div class="performance-table-wrap">
              <DataTable
                :columns="cycleColumns"
                :rows="cycles as unknown as Array<Record<string, unknown>>"
                :total="total"
                :page="page"
                :page-size="pageSize"
                row-key="id"
                :row-test-id="(row) => `performance-cycle-${String(row.id)}`"
                data-testid="performance-cycle-table"
                empty-title="还没有绩效周期"
                @update:page="changePage"
              >
                <template #cell-name="{ row }">{{ row.name }}</template>
                <template #cell-period="{ row }">
                  <span data-testid="performance-cycle-period">{{ periodLabel(row as unknown as PerformanceCycleSummary) }}</span>
                </template>
                <template #cell-stage="{ row }">
                  <span class="performance-stage" :data-tone="stageTone(row as unknown as PerformanceCycleSummary)">
                    {{ performanceCycleStage(row as unknown as PerformanceCycleSummary) }}
                  </span>
                </template>
                <template #cell-participants="{ row }">{{ row.participantCount }} 人</template>
                <template #cell-submitted="{ row }">{{ row.submittedCount }} 人</template>
                <template #cell-progress="{ row }">
                  <div class="performance-progress" :aria-label="`完成进度 ${String(row.progress)}%`">
                    <span class="performance-progress__bar" :style="{ width: `${Math.min(100, Math.max(0, Number(row.progress)))}%` }" />
                    <span class="performance-progress__text">{{ row.progress }}%</span>
                  </div>
                </template>
                <template #cell-actions="{ row }">
                  <router-link
                    class="performance-table__link"
                    :to="`/performance/cycles/${(row as unknown as PerformanceCycleSummary).id}`"
                    data-testid="performance-open-cycle"
                  >
                    查看详情
                  </router-link>
                </template>
              </DataTable>
            </div>
          </div>
          <footer class="panel__footer performance-pagination">
            <span>第 {{ page }} / {{ totalPages }} 页 · 每页 {{ pageSize }} 条</span>
            <div class="performance-pagination__actions">
              <button type="button" :disabled="page <= 1 || loading" @click="changePage(page - 1)">上一页</button>
              <button type="button" :disabled="page >= totalPages || loading" @click="changePage(page + 1)">下一页</button>
            </div>
          </footer>
        </section>
      </template>
    </template>
  </div>
</template>

<style scoped>
.performance-workspace {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 24px;
}

.performance-workspace__breadcrumb {
  margin: 0;
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.08em;
}

.performance-workspace__title {
  margin: 4px 0 0;
  font-size: 22px;
}

.performance-workspace__description {
  max-width: 760px;
  margin: 8px 0 0;
  color: #475569;
  font-size: 12.5px;
  line-height: 1.65;
}

.performance-workspace__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.performance-workspace__secondary,
.performance-workspace__primary {
  padding: 7px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  color: #334155;
  font-size: 12px;
  cursor: pointer;
}

.performance-workspace__primary {
  border-color: #1d4ed8;
  background: #1d4ed8;
  color: #fff;
}

.performance-workspace__secondary:disabled,
.performance-workspace__primary:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.performance-workspace__state {
  padding: 18px;
  border: 1px solid #fecaca;
  border-radius: 10px;
  background: #fef2f2;
  color: #991b1b;
}

.performance-workspace__state h2 {
  margin: 0 0 6px;
  font-size: 15px;
}

.performance-workspace__state p {
  margin: 0;
  font-size: 12px;
}

.performance-workspace__partial {
  margin: 0;
  padding: 9px 12px;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  background: #eff6ff;
  color: #1e40af;
  font-size: 12px;
}

.performance-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.performance-filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: #475569;
  font-size: 11.5px;
}

.performance-filters input,
.performance-filters select {
  min-width: 160px;
  padding: 7px 9px;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  font: inherit;
}

.performance-filters__actions {
  display: flex;
  gap: 8px;
}

.performance-table-wrap {
  overflow-x: auto;
}

.performance-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.performance-table th,
.performance-table td {
  padding: 9px 12px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  white-space: nowrap;
}

.performance-table thead th {
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
}

.performance-table tbody th {
  font-weight: 600;
}

.performance-table .is-numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.performance-stage {
  display: inline-flex;
  padding: 2px 9px;
  border-radius: 999px;
  background: #eef2f7;
  color: #475569;
  font-size: 11px;
  font-weight: 600;
}

.performance-stage[data-tone='active'] {
  background: #eff6ff;
  color: #1d4ed8;
}

.performance-stage[data-tone='scoring'] {
  background: #fef3c7;
  color: #92400e;
}

.performance-stage[data-tone='published'] {
  background: #ecfdf5;
  color: #047857;
}

.performance-progress {
  position: relative;
  min-width: 110px;
  height: 16px;
  border-radius: 999px;
  background: #f1f5f9;
  overflow: hidden;
}

.performance-progress__bar {
  display: block;
  height: 100%;
  background: #93c5fd;
}

.performance-progress__text {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: 600;
  color: #1e293b;
}

.performance-table__link {
  border: 0;
  background: none;
  color: #1d4ed8;
  font-size: 12px;
  cursor: pointer;
}

.performance-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  color: #64748b;
  font-size: 11.5px;
}

.performance-pagination__actions {
  display: flex;
  gap: 8px;
}

.performance-pagination__actions button {
  padding: 5px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  background: #fff;
  font-size: 11.5px;
  cursor: pointer;
}

.performance-pagination__actions button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
