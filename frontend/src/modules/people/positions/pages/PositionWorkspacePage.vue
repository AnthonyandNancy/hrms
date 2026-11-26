<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { todayBusinessDate } from '@/utils/format'
import {
  POSITION_LIST_CAPABILITY_NOTE,
  createPosition,
  isForbidden,
  listOrgUnits,
  listPositionGrades,
  listPositions,
  orgUnitName,
  positionGradeLabel,
  positionStatusLabel,
  updatePosition,
  type OrgUnitOption,
  type Position,
  type PositionCreateInput,
  type PositionGrade,
  type PositionPage,
  type PositionUpdateInput,
} from '../api'
import PositionTable from '../components/PositionTable.vue'
import PositionFormDrawer from '../components/PositionFormDrawer.vue'
import PositionRequirementsPanel from '../components/PositionRequirementsPanel.vue'

defineOptions({ name: 'PositionWorkspacePage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden'

const session = useSessionStore()

const canRead = computed(() => session.can('org.position.read'))
const canManage = computed(() => session.can('org.position.manage'))
const canManageQualifications = computed(() =>
  session.can('org.position.qualification.manage'),
)

const PAGE_SIZE = 10

const asOf = ref(todayBusinessDate())
const asOfInput = ref(asOf.value)
const qInput = ref('')
const appliedQ = ref('')
const statusFilter = ref<'ALL' | 'ACTIVE' | 'DISABLED'>('ALL')
const orgUnitFilter = ref('')
const page = ref(1)

const state = ref<LoadState>('idle')
const result = ref<PositionPage>({
  items: [],
  total: 0,
  partial: false,
  partial_reason: null,
})
const loadError = ref<unknown>(null)
const requestSequence = ref(0)

const orgUnits = ref<OrgUnitOption[]>([])
const grades = ref<PositionGrade[]>([])
const gradeCatalogueForbidden = ref(false)
const orgUnitsForbidden = ref(false)

const selectedId = ref<number | null>(null)
const detailOpen = ref(false)
const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const saving = ref(false)
const saveError = ref<ApiError | null>(null)

const loading = computed(() => state.value === 'loading')
const forbidden = computed(() => !canRead.value || state.value === 'forbidden')
const isEmpty = computed(() => state.value === 'ready' && result.value.items.length === 0)
const isPartial = computed(() => state.value === 'ready' && result.value.partial)
const errorMessage = computed(() =>
  loadError.value === null ? '岗位目录暂时无法加载，请稍后重试。' : errorCopy(loadError.value),
)
const errorRequestId = computed(() =>
  loadError.value instanceof ApiError ? loadError.value.requestId : null,
)

const filteredPositions = computed(() => {
  const unitId = orgUnitFilter.value === '' ? null : Number(orgUnitFilter.value)
  return result.value.items.filter((position) => {
    if (statusFilter.value !== 'ALL' && position.status !== statusFilter.value) return false
    if (unitId !== null && position.org_unit_id !== unitId) return false
    return true
  })
})

const selected = computed<Position | null>(() => {
  if (selectedId.value === null) return null
  return result.value.items.find((position) => position.id === selectedId.value) ?? null
})

const selectedOverLimit = computed(() => {
  const position = selected.value
  return (
    position !== null &&
    position.headcount_limit !== null &&
    position.occupancy !== null &&
    position.occupancy > position.headcount_limit
  )
})

const selectedVarianceText = computed(() => {
  const position = selected.value
  if (position === null || position.headcount_limit === null || position.occupancy === null) {
    return ''
  }
  const excess = position.occupancy - position.headcount_limit
  return `在岗 ${position.occupancy} 人，超过核定编制 ${excess} 人；请通过员工异动流程调整现任岗位，不要直接覆盖历史任职。`
})

const formPosition = computed(() => (drawerMode.value === 'edit' ? selected.value : null))

async function loadWorkspace(): Promise<void> {
  if (!canRead.value) {
    state.value = 'forbidden'
    result.value = { items: [], total: 0, partial: false, partial_reason: null }
    return
  }

  const sequence = ++requestSequence.value
  state.value = 'loading'
  loadError.value = null

  const [positionsResult, orgUnitsResult, gradesResult] = await Promise.allSettled([
    listPositions(asOf.value, appliedQ.value),
    listOrgUnits(asOf.value),
    listPositionGrades(asOf.value),
  ])

  if (sequence !== requestSequence.value) return

  if (positionsResult.status === 'rejected') {
    result.value = { items: [], total: 0, partial: false, partial_reason: null }
    selectedId.value = null
    detailOpen.value = false
    if (isForbidden(positionsResult.reason)) {
      state.value = 'forbidden'
    } else {
      loadError.value = positionsResult.reason
      state.value = 'error'
    }
    return
  }

  result.value = positionsResult.value
  orgUnits.value = orgUnitsResult.status === 'fulfilled' ? orgUnitsResult.value : []
  orgUnitsForbidden.value =
    orgUnitsResult.status === 'rejected' && isForbidden(orgUnitsResult.reason)
  grades.value = gradesResult.status === 'fulfilled' ? gradesResult.value : []
  gradeCatalogueForbidden.value =
    gradesResult.status === 'rejected' && isForbidden(gradesResult.reason)

  if (selectedId.value !== null && !result.value.items.some((row) => row.id === selectedId.value)) {
    selectedId.value = null
    detailOpen.value = false
  }

  state.value = 'ready'
}

function applyServerFilters(): void {
  appliedQ.value = qInput.value.trim()
  asOf.value = asOfInput.value === '' ? todayBusinessDate() : asOfInput.value
  page.value = 1
  selectedId.value = null
  detailOpen.value = false
  void loadWorkspace()
}

function onStatusFilterChange(): void {
  page.value = 1
}

function onOrgUnitFilterChange(): void {
  page.value = 1
}

function onPageChange(next: number): void {
  if (next < 1) return
  page.value = next
}

function viewPosition(position: Position): void {
  selectedId.value = position.id
  detailOpen.value = true
}

function closeDetail(): void {
  detailOpen.value = false
  selectedId.value = null
}

function openCreate(): void {
  if (!canManage.value) return
  drawerMode.value = 'create'
  saveError.value = null
  drawerOpen.value = true
}

function openEdit(): void {
  if (!canManage.value || selected.value === null) return
  drawerMode.value = 'edit'
  saveError.value = null
  drawerOpen.value = true
}

async function savePosition(input: PositionCreateInput | PositionUpdateInput): Promise<void> {
  if (!canManage.value) return
  saving.value = true
  saveError.value = null
  try {
    if (drawerMode.value === 'create') {
      await createPosition(input as PositionCreateInput)
      ElMessage.success('岗位已创建。')
    } else {
      const current = selected.value
      if (current === null) return
      await updatePosition(current.id, input as PositionUpdateInput)
      ElMessage.success('岗位已更新。')
    }
    drawerOpen.value = false
    await loadWorkspace()
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : null
    if (saveError.value === null) {
      ElMessage.error(error instanceof Error ? error.message : '岗位保存失败。')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (!canRead.value) {
    state.value = 'forbidden'
    return
  }
  void loadWorkspace()
})

onBeforeUnmount(() => {
  requestSequence.value += 1
})
</script>

<template>
  <div class="position-workspace-page">
    <header class="position-page-header">
      <div>
        <p class="position-page-header__breadcrumb">员工与组织 · 岗位管理</p>
        <h1 class="position-page-header__title">岗位工作台</h1>
        <p class="position-page-header__description">
          查看生效岗位、职级、部门与实时编制占用；新增与编辑岗位，资格版本在详情内维护。
        </p>
      </div>
      <div class="position-page-header__actions">
        <button
          type="button"
          class="position-button"
          data-testid="position-refresh"
          :disabled="loading"
          @click="loadWorkspace"
        >
          刷新
        </button>
        <button
          v-if="canManage && state !== 'forbidden'"
          type="button"
          class="position-button position-button--primary"
          data-testid="position-create"
          @click="openCreate"
        >
          新增岗位
        </button>
      </div>
    </header>

    <section v-if="forbidden" class="state-panel error-state" role="alert" data-testid="position-forbidden">
      <div class="state-panel__mark" aria-hidden="true">!</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权限查看岗位工作台</h2>
        <p class="state-panel__body">
          当前账号缺少 org.position.read 能力，或数据范围不覆盖岗位目录。联系权限管理员授权后再试。
        </p>
      </div>
    </section>

    <section v-else-if="state === 'error'" class="state-panel error-state" role="alert" data-testid="position-error">
      <div class="state-panel__mark" aria-hidden="true">!</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">岗位目录暂时无法加载</h2>
        <p class="state-panel__body">{{ errorMessage }}</p>
        <p v-if="errorRequestId" class="state-panel__request">
          请求编号：<span class="mono">{{ errorRequestId }}</span>
        </p>
      </div>
      <button type="button" class="position-button" @click="loadWorkspace">重试</button>
    </section>

    <template v-else>
      <section class="position-filter" aria-label="岗位筛选">
        <label class="position-filter__field position-filter__field--wide">
          <span>搜索</span>
          <input
            v-model="qInput"
            type="search"
            data-testid="position-filter-q"
            aria-label="搜索岗位"
            placeholder="岗位编码或名称"
          />
        </label>
        <label class="position-filter__field">
          <span>业务日期</span>
          <input
            v-model="asOfInput"
            type="date"
            data-testid="position-filter-as-of"
            aria-label="业务日期"
          />
        </label>
        <label class="position-filter__field">
          <span>状态</span>
          <select
            v-model="statusFilter"
            data-testid="position-filter-status"
            aria-label="按状态筛选"
            @change="onStatusFilterChange"
          >
            <option value="ALL">全部状态</option>
            <option value="ACTIVE">启用</option>
            <option value="DISABLED">停用</option>
          </select>
        </label>
        <label class="position-filter__field">
          <span>部门</span>
          <select
            v-model="orgUnitFilter"
            data-testid="position-filter-org-unit"
            aria-label="按部门筛选"
            @change="onOrgUnitFilterChange"
          >
            <option value="">全部部门</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
              {{ unit.name }}（{{ unit.code }}）
            </option>
          </select>
        </label>
        <button
          type="button"
          class="position-button position-button--primary"
          data-testid="position-filter-submit"
          @click="applyServerFilters"
        >
          查询
        </button>
      </section>

      <p class="position-capability-note" data-testid="position-filter-note">
        {{ POSITION_LIST_CAPABILITY_NOTE }}
      </p>

      <el-alert
        v-if="isPartial"
        type="warning"
        :closable="false"
        show-icon
        data-testid="position-partial"
      >
        <template #title>
          部分数据：{{ result.partial_reason || '服务端只返回了部分岗位，请缩小筛选范围后重试。' }}
        </template>
      </el-alert>

      <p v-if="orgUnitsForbidden" class="position-inline-warning" data-testid="position-org-gap">
        部门目录不可读（org.unit.read），部门列显示岗位返回的组织编号，不虚构名称。
      </p>
      <p
        v-if="gradeCatalogueForbidden"
        class="position-inline-warning"
        data-testid="position-grade-gap"
      >
        职级目录不可读（org.position.grade.manage），职级列保留岗位返回的职级代码。
      </p>

      <div v-if="loading" class="position-loading" data-testid="position-loading" role="status">
        <span class="position-loading__line" />
        <span class="position-loading__line" />
        <span class="position-loading__line" />
        <span class="visually-hidden">正在加载岗位目录</span>
      </div>

      <section v-if="isEmpty" class="state-panel" data-testid="position-empty">
        <div class="state-panel__mark" aria-hidden="true">—</div>
        <div class="state-panel__content">
          <h2 class="state-panel__title">暂无岗位</h2>
          <p class="state-panel__body">
            当前查询条件下没有生效岗位。可以调整业务日期或关键词，或新增一个岗位。
          </p>
        </div>
        <button
          v-if="canManage"
          type="button"
          class="position-button position-button--primary"
          @click="openCreate"
        >
          新增岗位
        </button>
      </section>

      <PositionTable
        v-if="!loading && !isEmpty && state === 'ready'"
        :rows="filteredPositions"
        :total="filteredPositions.length"
        :page="page"
        :page-size="PAGE_SIZE"
        :org-units="orgUnits"
        :grades="grades"
        @update:page="onPageChange"
        @view="viewPosition"
      />

      <section
        v-if="detailOpen && selected !== null"
        class="position-detail"
        data-testid="position-detail"
      >
        <header class="position-detail__header">
          <div>
            <p class="position-page-header__breadcrumb">岗位详情</p>
            <h2 class="position-detail__title">{{ selected.title }}</h2>
            <p class="position-detail__subtitle">{{ selected.code }}</p>
          </div>
          <div class="position-detail__actions">
            <button
              v-if="canManage"
              type="button"
              class="position-button position-button--primary"
              data-testid="position-edit"
              @click="openEdit"
            >
              编辑岗位
            </button>
            <button type="button" class="position-button" data-testid="position-detail-close" @click="closeDetail">
              关闭
            </button>
          </div>
        </header>

        <dl class="position-detail__facts">
          <div>
            <dt>部门</dt>
            <dd>{{ orgUnitName(selected.org_unit_id, orgUnits) }}</dd>
          </div>
          <div>
            <dt>职级</dt>
            <dd>{{ positionGradeLabel(selected.grade, grades) }}</dd>
          </div>
          <div>
            <dt>状态</dt>
            <dd>{{ positionStatusLabel(selected.status) }}</dd>
          </div>
          <div>
            <dt>核定编制</dt>
            <dd>{{ selected.headcount_limit === null ? '未设置' : `${selected.headcount_limit} 人` }}</dd>
          </div>
          <div>
            <dt>在岗人数</dt>
            <dd>{{ selected.occupancy === null ? '—' : `${selected.occupancy} 人` }}</dd>
          </div>
          <div>
            <dt>空缺</dt>
            <dd>{{ selected.vacancies === null ? '—' : `${selected.vacancies} 人` }}</dd>
          </div>
        </dl>

        <p
          v-if="selectedOverLimit"
          class="position-detail__variance"
          data-testid="position-detail-variance"
        >
          {{ selectedVarianceText }}
        </p>

        <PositionRequirementsPanel
          :position-id="selected.id"
          :as-of="asOf"
          :can-manage="canManageQualifications"
        />
      </section>
    </template>

    <PositionFormDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :position="formPosition"
      :org-units="orgUnits"
      :grades="grades"
      :positions="result.items"
      :saving="saving"
      :server-error="saveError"
      @save="savePosition"
    />
  </div>
</template>

<style scoped>
.position-workspace-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.position-page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.position-page-header__breadcrumb {
  margin: 0 0 2px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.position-page-header__title {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
}

.position-page-header__description {
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}

.position-page-header__actions,
.position-detail__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.position-button {
  padding: 7px 14px;
  color: var(--color-brand);
  font-size: 12px;
  font-weight: 600;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.position-button--primary {
  color: #fff;
  background: var(--color-brand);
  border-color: var(--color-brand);
}

.position-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.position-filter {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  align-items: flex-end;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.position-filter__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 160px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.position-filter__field--wide {
  flex: 1 1 220px;
}

.position-filter__field > span {
  font-weight: 600;
}

.position-filter__field input,
.position-filter__field select {
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.position-capability-note,
.position-inline-warning {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.position-inline-warning {
  padding: 7px 10px;
  color: var(--color-warning);
  background: var(--color-warning-soft);
  border-radius: var(--radius-sm);
}

.position-loading {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.position-loading__line {
  height: 14px;
  background: linear-gradient(90deg, var(--color-canvas), #fff, var(--color-canvas));
  border-radius: 4px;
}

.position-loading__line:nth-child(2) {
  width: 82%;
}

.position-loading__line:nth-child(3) {
  width: 64%;
}

.position-detail {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.position-detail__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.position-detail__title {
  margin: 0;
  font-size: 16px;
  font-weight: 650;
}

.position-detail__subtitle {
  margin: 2px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.position-detail__facts {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 10px 16px;
  margin: 0;
}

.position-detail__facts dt {
  margin-bottom: 2px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 600;
}

.position-detail__facts dd {
  margin: 0;
  font-size: 12px;
}

.position-detail__variance {
  margin: 0;
  padding: 9px 11px;
  color: var(--color-warning);
  font-size: 12px;
  background: var(--color-warning-soft);
  border-radius: var(--radius-sm);
}
</style>
