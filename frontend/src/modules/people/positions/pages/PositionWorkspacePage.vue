<script setup lang="ts">
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  routeLocationKey,
  routerKey,
  type RouteLocationNormalizedLoaded,
  type Router,
} from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { todayBusinessDate } from '@/utils/format'
import {
  createPosition,
  getPosition,
  isForbidden,
  listOrgUnits,
  listPositionGrades,
  listPositions,
  orgUnitName,
  positionGradeLabel,
  positionStatusLabel,
  updatePosition,
  updatePositionStatus,
  type OrgUnitOption,
  type Position,
  type PositionCreateInput,
  type PositionGrade,
  type PositionPage,
  type PositionSort,
  type PositionUpdateInput,
} from '../api'
import PositionTable from '../components/PositionTable.vue'
import PositionFormDrawer from '../components/PositionFormDrawer.vue'
import PositionRequirementsPanel from '../components/PositionRequirementsPanel.vue'
import PositionStaffPanel from '../components/PositionStaffPanel.vue'
import PositionGradesPanel from '../components/PositionGradesPanel.vue'
import { DISPLAY_CODES } from '@/shared/display/dictionary'

defineOptions({ name: 'PositionWorkspacePage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden'
type PositionTab = 'catalog' | 'headcount' | 'grades'
type PositionDetailTab = 'overview' | 'staff' | 'requirements' | 'headcount'

const WORKSPACE_TABS: ReadonlyArray<{ name: PositionTab; label: string }> = [
  { name: 'catalog', label: '岗位目录' },
  { name: 'headcount', label: '编制' },
  { name: 'grades', label: '职级' },
]

const DETAIL_TABS: ReadonlyArray<{ name: PositionDetailTab; label: string }> = [
  { name: 'overview', label: '概览' },
  { name: 'staff', label: '在岗人员' },
  { name: 'requirements', label: '任职资格' },
  { name: 'headcount', label: '编制' },
]

const SORTS: ReadonlyArray<{ value: PositionSort; label: string }> = [
  { value: 'code', label: '岗位编码升序' },
  { value: 'code_desc', label: '岗位编码降序' },
  { value: 'title', label: '岗位名称' },
  { value: 'level', label: '职级代码' },
]

/**
 * Router injections are optional so the workspace can also be mounted in a
 * unit test without a full application shell. Without a router it still
 * renders, it just cannot publish deep-link query state.
 */
const injectedRoute = inject(routeLocationKey, null)
const injectedRouter = inject(routerKey, null)
const route: Pick<RouteLocationNormalizedLoaded, 'query'> = injectedRoute ?? {
  query: {},
}
const router: Pick<Router, 'replace'> = injectedRouter ?? {
  replace: async () => undefined,
}
const session = useSessionStore()

const canRead = computed(() => session.can('org.position.read'))
const canManage = computed(() => session.can('org.position.manage'))
const canManageQualifications = computed(() =>
  session.can('org.position.qualification.manage'),
)
const canReadStaff = computed(() => session.can('org.position.staff.read'))
const canManageGrades = computed(() => session.can('org.position.grade.manage'))
const canReadOrgUnits = computed(() => session.can('org.unit.read'))

const PAGE_SIZE = 10

function isWorkspaceTab(value: unknown): value is PositionTab {
  return typeof value === 'string' && WORKSPACE_TABS.some((tab) => tab.name === value)
}

function isDetailTab(value: unknown): value is PositionDetailTab {
  return typeof value === 'string' && DETAIL_TABS.some((tab) => tab.name === value)
}

function parsePositionId(value: unknown): number | null {
  const raw = Array.isArray(value) ? value[0] : value
  if (typeof raw !== 'string' || !/^\d+$/.test(raw)) return null
  const parsed = Number(raw)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

const activeTab = ref<PositionTab>(isWorkspaceTab(route.query.tab) ? route.query.tab : 'catalog')
const detailTab = ref<PositionDetailTab>(
  isDetailTab(route.query.detail) ? route.query.detail : 'overview',
)
const selectedId = ref<number | null>(parsePositionId(route.query.position))

const asOf = ref(todayBusinessDate())
const asOfInput = ref(asOf.value)
const qInput = ref('')
const appliedQ = ref('')
type StatusFilter = 'ALL' | typeof DISPLAY_CODES.positionStatus.active | typeof DISPLAY_CODES.positionStatus.disabled
const statusFilter = ref<StatusFilter>('ALL')
const orgUnitFilter = ref('')
const sort = ref<PositionSort>('code')
const page = ref(1)

const state = ref<LoadState>('idle')
const result = ref<PositionPage>({
  items: [],
  total: 0,
  page: 1,
  page_size: PAGE_SIZE,
  partial: false,
  partial_reason: null,
})
const loadError = ref<unknown>(null)
const requestSequence = ref(0)

const orgUnits = ref<OrgUnitOption[]>([])
const grades = ref<PositionGrade[]>([])
const gradeCatalogueUnavailable = ref(false)
const orgUnitsUnavailable = ref(false)
const externalSelected = ref<Position | null>(null)

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const drawerPosition = ref<Position | null>(null)
const saving = ref(false)
const saveError = ref<ApiError | null>(null)
const statusSaving = ref(false)

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

const selected = computed<Position | null>(() => {
  if (selectedId.value === null) return null
  const local = result.value.items.find((position) => position.id === selectedId.value)
  if (local !== undefined) return local
  return externalSelected.value?.id === selectedId.value ? externalSelected.value : null
})

const detailOpen = computed(() => selectedId.value !== null && activeTab.value !== 'grades')
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

const formPosition = computed(() => (drawerMode.value === 'edit' ? drawerPosition.value : null))

function currentQuery(overrides: Record<string, string | null> = {}): Record<string, string> {
  const query: Record<string, string> = {}
  const tab = (overrides.tab ?? activeTab.value) as string | null
  const position = overrides.position !== undefined
    ? overrides.position
    : selectedId.value === null
      ? null
      : String(selectedId.value)
  const detail = (overrides.detail ?? detailTab.value) as string | null

  if (tab !== null && tab !== '') query.tab = tab
  if (position !== null && position !== '') query.position = position
  if (position !== null && position !== '' && detail !== null && detail !== '') query.detail = detail
  return query
}

async function syncQuery(overrides: Record<string, string | null> = {}): Promise<void> {
  await router.replace({ query: currentQuery(overrides) })
}

function selectTab(name: string | number): void {
  if (!isWorkspaceTab(name)) return
  activeTab.value = name
  externalSelected.value = null
  if (name === 'grades') {
    selectedId.value = null
    void syncQuery({ tab: name, position: null, detail: null })
    return
  }
  void syncQuery({ tab: name })
}

function selectDetailTab(name: string | number): void {
  if (!isDetailTab(name)) return
  detailTab.value = name
  void syncQuery({ detail: name })
}

async function loadWorkspace(): Promise<void> {
  if (!canRead.value) {
    state.value = 'forbidden'
    result.value = {
      items: [],
      total: 0,
      page: 1,
      page_size: PAGE_SIZE,
      partial: false,
      partial_reason: null,
    }
    return
  }

  const sequence = ++requestSequence.value
  state.value = 'loading'
  loadError.value = null

  const lookups: Array<Promise<unknown>> = [
    listPositions({
      asOf: asOf.value,
      q: appliedQ.value,
      orgUnitId: orgUnitFilter.value === '' ? null : Number(orgUnitFilter.value),
      status: statusFilter.value === 'ALL' ? null : statusFilter.value,
      page: page.value,
      pageSize: PAGE_SIZE,
      sort: sort.value,
    }),
    canReadOrgUnits.value ? listOrgUnits(asOf.value) : Promise.resolve([]),
    canManageGrades.value ? listPositionGrades(asOf.value, { pageSize: 100 }) : Promise.resolve([]),
  ]

  const settled = await Promise.allSettled(lookups)
  const positionsResult = settled[0] as PromiseSettledResult<PositionPage>
  const orgUnitsResult = settled[1] as PromiseSettledResult<OrgUnitOption[]>
  const gradesResult = settled[2] as PromiseSettledResult<PositionGrade[]>

  if (sequence !== requestSequence.value) return

  if (positionsResult.status === 'rejected') {
    result.value = {
      items: [],
      total: 0,
      page: page.value,
      page_size: PAGE_SIZE,
      partial: false,
      partial_reason: null,
    }
    if (isForbidden(positionsResult.reason)) {
      state.value = 'forbidden'
    } else {
      loadError.value = positionsResult.reason
      state.value = 'error'
    }
    return
  }

  result.value = positionsResult.value as PositionPage
  orgUnits.value = orgUnitsResult.status === 'fulfilled' ? (orgUnitsResult.value as OrgUnitOption[]) : []
  orgUnitsUnavailable.value = orgUnitsResult.status === 'rejected'
  grades.value = gradesResult.status === 'fulfilled' ? (gradesResult.value as PositionGrade[]) : []
  gradeCatalogueUnavailable.value = gradesResult.status === 'rejected'

  await ensureSelectedPosition()
  state.value = 'ready'
}

async function ensureSelectedPosition(): Promise<void> {
  if (selectedId.value === null) {
    externalSelected.value = null
    return
  }

  if (result.value.items.some((row) => row.id === selectedId.value)) {
    externalSelected.value = null
    return
  }

  try {
    const row = await getPosition(selectedId.value, asOf.value)
    externalSelected.value = row
    if (row === null) selectedId.value = null
  } catch {
    externalSelected.value = null
    selectedId.value = null
  }
}

function applyServerFilters(): void {
  appliedQ.value = qInput.value.trim()
  asOf.value = asOfInput.value === '' ? todayBusinessDate() : asOfInput.value
  page.value = 1
  void loadWorkspace()
}

function onPageChange(next: number): void {
  if (next < 1 || next === page.value) return
  page.value = next
  void loadWorkspace()
}

function onSortChange(): void {
  page.value = 1
  void loadWorkspace()
}

function viewPosition(row: Position, requestedDetail?: PositionDetailTab): void {
  selectedId.value = row.id
  externalSelected.value = null
  const nextDetail = requestedDetail ?? (activeTab.value === 'headcount' ? 'headcount' : 'overview')
  detailTab.value = nextDetail
  void syncQuery({ position: String(row.id), detail: nextDetail })
}

function closeDetail(): void {
  selectedId.value = null
  externalSelected.value = null
  void syncQuery({ position: null, detail: null })
}

function openCreate(): void {
  if (!canManage.value) return
  drawerMode.value = 'create'
  drawerPosition.value = null
  saveError.value = null
  drawerOpen.value = true
}

function openEdit(position: Position | null = selected.value): void {
  if (!canManage.value || position === null) return
  selectedId.value = position.id
  drawerMode.value = 'edit'
  drawerPosition.value = position
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
      const current = drawerPosition.value ?? selected.value
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

async function toggleSelectedStatus(): Promise<void> {
  const position = selected.value
  if (!canManage.value || position === null || statusSaving.value) return

  const disabling = position.status === DISPLAY_CODES.positionStatus.active
  try {
    await ElMessageBox.confirm(
      disabling
        ? `确认停用岗位「${position.title}」？停用后岗位历史仍可查看，存在在岗人员时系统会拒绝。`
        : `确认重新启用岗位「${position.title}」？`,
      disabling ? '停用岗位' : '启用岗位',
      { confirmButtonText: '确认', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }

  statusSaving.value = true
  try {
    await updatePositionStatus(
      position.id,
      disabling ? DISPLAY_CODES.positionStatus.disabled : DISPLAY_CODES.positionStatus.active,
    )
    ElMessage.success(disabling ? '岗位已停用。' : '岗位已启用。')
    await loadWorkspace()
  } catch (error) {
    ElMessage.error(errorCopy(error))
  } finally {
    statusSaving.value = false
  }
}

watch(
  () => route.query.tab,
  (value) => {
    if (isWorkspaceTab(value) && value !== activeTab.value) {
      activeTab.value = value
      if (value === 'grades') selectedId.value = null
    }
  },
)

watch(
  () => route.query.position,
  (value) => {
    const next = parsePositionId(value)
    if (next !== selectedId.value) {
      selectedId.value = next
      externalSelected.value = null
    }
  },
)

watch(
  () => route.query.detail,
  (value) => {
    if (isDetailTab(value)) detailTab.value = value
  },
)

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
          一个入口维护岗位、编制、职级与任职资格；在岗人员按有效任职只读投影。
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
          当前账号没有岗位目录查看能力，或数据范围不覆盖岗位目录。联系权限管理员授权后再试。
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
      <nav class="position-workspace-tabs" aria-label="岗位工作区视图">
        <button
          v-for="tab in WORKSPACE_TABS"
          :key="tab.name"
          type="button"
          class="position-workspace-tabs__button"
          :class="{ 'position-workspace-tabs__button--active': activeTab === tab.name }"
          :data-testid="`position-tab-${tab.name}`"
          :aria-selected="activeTab === tab.name"
          role="tab"
          @click="selectTab(tab.name)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <template v-if="activeTab !== 'grades'">
        <section class="position-filter" aria-label="岗位筛选">
          <label class="position-filter__field position-filter__field--wide">
            <span>搜索</span>
            <el-input
              v-model="qInput"
              type="search"
              data-testid="position-filter-q"
              aria-label="搜索岗位"
              placeholder="岗位编码或名称"
            />
          </label>
          <label class="position-filter__field">
            <span>业务日期</span>
            <el-input
              v-model="asOfInput"
              type="date"
              data-testid="position-filter-as-of"
              aria-label="业务日期"
            />
          </label>
          <label class="position-filter__field">
            <span>状态</span>
            <el-select v-model="statusFilter" data-testid="position-filter-status" aria-label="按状态筛选">
              <el-option value="ALL" label="全部状态" />
              <el-option :value="DISPLAY_CODES.positionStatus.active" label="启用" />
              <el-option :value="DISPLAY_CODES.positionStatus.disabled" label="停用" />
            </el-select>
          </label>
          <label class="position-filter__field">
            <span>部门</span>
            <el-select v-model="orgUnitFilter" data-testid="position-filter-org-unit" aria-label="按部门筛选">
              <el-option value="" label="全部部门" />
              <el-option
                v-for="unit in orgUnits"
                :key="unit.id"
                :value="String(unit.id)"
                :label="`${unit.name}（${unit.code}）`"
              />
            </el-select>
          </label>
          <label class="position-filter__field">
            <span>排序</span>
            <el-select v-model="sort" data-testid="position-filter-sort" aria-label="排序" @change="onSortChange">
              <el-option v-for="item in SORTS" :key="item.value" :value="item.value" :label="item.label" />
            </el-select>
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

        <p
          v-if="orgUnitsUnavailable"
          class="position-inline-warning"
          data-testid="position-org-gap"
        >
          当前账号无权查看部门目录，部门列仅显示组织编号，不影响岗位查询。
        </p>
        <p
          v-if="gradeCatalogueUnavailable"
          class="position-inline-warning"
          data-testid="position-grade-gap"
        >
          当前账号无权查看职级目录，职级列保留岗位已保存的职级代码。
        </p>

        <p v-if="activeTab === 'headcount'" class="position-headcount-note" data-testid="position-headcount-note">
          核定编制由岗位编辑动作维护；在岗人数与空缺来自有效任职，不可在本页人工覆盖。
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
            <p class="state-panel__body">当前查询条件下没有岗位。可以调整业务日期、关键词、部门或状态。</p>
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
          :rows="result.items"
          :total="result.total"
          :page="result.page"
          :page-size="PAGE_SIZE"
          :org-units="orgUnits"
          :grades="grades"
          @update:page="onPageChange"
          @view="viewPosition"
        />
      </template>

      <PositionGradesPanel
        v-else
        :as-of="asOf"
        :can-manage="canManageGrades"
      />

      <section
        v-if="detailOpen && selected !== null && activeTab !== 'grades'"
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
              @click="openEdit(selected)"
            >
              编辑岗位
            </button>
            <button
              v-if="canManage"
              type="button"
              class="position-button"
              data-testid="position-toggle-status"
              :disabled="statusSaving"
              @click="toggleSelectedStatus"
            >
              {{ selected.status === DISPLAY_CODES.positionStatus.active ? '停用岗位' : '启用岗位' }}
            </button>
            <button type="button" class="position-button" data-testid="position-detail-close" @click="closeDetail">
              关闭
            </button>
          </div>
        </header>

        <nav class="position-detail-tabs" aria-label="岗位详情分区">
          <button
            v-for="tab in DETAIL_TABS"
            :key="tab.name"
            type="button"
            class="position-detail-tabs__button"
            :class="{ 'position-detail-tabs__button--active': detailTab === tab.name }"
            :data-testid="`position-detail-tab-${tab.name}`"
            :aria-selected="detailTab === tab.name"
            role="tab"
            @click="selectDetailTab(tab.name)"
          >
            {{ tab.label }}
          </button>
        </nav>

        <template v-if="detailTab === 'overview'">
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
            <div>
              <dt>生效日期</dt>
              <dd>{{ selected.valid_from }}</dd>
            </div>
            <div>
              <dt>失效日期</dt>
              <dd>{{ selected.valid_to === '9999-12-31' ? '长期' : selected.valid_to }}</dd>
            </div>
          </dl>
          <p v-if="selectedOverLimit" class="position-detail__variance" data-testid="position-detail-variance">
            {{ selectedVarianceText }}
          </p>
        </template>

        <PositionStaffPanel
          v-else-if="detailTab === 'staff'"
          :position-id="selected.id"
          :as-of="asOf"
          :can-read="canReadStaff"
        />

        <PositionRequirementsPanel
          v-else-if="detailTab === 'requirements'"
          :position-id="selected.id"
          :as-of="asOf"
          :can-manage="canManageQualifications"
        />

        <template v-else>
          <dl class="position-detail__facts">
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
          <p v-if="selectedOverLimit" class="position-detail__variance" data-testid="position-detail-headcount-variance">
            {{ selectedVarianceText }}
          </p>
          <p class="position-headcount-note">
            调整核定编制不会改变在岗人员；如实际在岗超过编制，请走员工异动流程。
          </p>
          <div>
            <button
              v-if="canManage"
              type="button"
              class="position-button position-button--primary"
              data-testid="position-headcount-edit"
              @click="openEdit(selected)"
            >
              调整编制
            </button>
          </div>
        </template>
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

.position-workspace-tabs,
.position-detail-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.position-workspace-tabs__button,
.position-detail-tabs__button {
  padding: 7px 14px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  font-weight: 600;
  background: transparent;
  border: 0;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.position-workspace-tabs__button--active,
.position-detail-tabs__button--active {
  color: var(--color-brand);
  background: #fff;
  box-shadow: 0 1px 2px rgb(15 23 42 / 8%);
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
  min-width: 150px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.position-filter__field--wide {
  flex: 1 1 220px;
}

.position-filter__field > span {
  font-weight: 600;
}

.position-inline-warning,
.position-headcount-note {
  margin: 0;
  padding: 7px 10px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}

.position-inline-warning {
  color: var(--color-warning);
  background: var(--color-warning-soft);
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
