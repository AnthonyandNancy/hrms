<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { usePageError } from '@/composables/usePageError'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'
import { peoplePageConfig } from './catalog'
import { loadPeoplePage, type PeopleReadState } from './peopleService'
import { isRecord, rowText } from './peopleRead'
import type { PeopleForm, PeopleProfileResource, PeopleRow } from './types'
import PeopleDataTable from './PeopleDataTable.vue'
import DetailFacts from './DetailFacts.vue'
import PeopleGapNotice from './PeopleGapNotice.vue'
import ProfileResourceForm from './ProfileResourceForm.vue'
import MovementForms from './MovementForms.vue'
import ContractForm from './ContractForm.vue'
import DepartmentForms from './DepartmentForms.vue'
import PositionForms from './PositionForms.vue'
import EmployeeCreateForm from './EmployeeCreateForm.vue'

const props = defineProps<{
  componentKey?: string
}>()

const route = useRoute()
const router = useRouter()
const pageError = usePageError()
const loading = ref(false)
const loaded = ref(false)
const search = ref('')
const selectedId = ref('')
const state = ref<PeopleReadState>({ rows: [], total: 0, partial: false, primaryId: null, partialNote: '' })

const config = computed(() => {
  const metaRegistry = route.meta.registry
  if (isRecord(metaRegistry) && typeof metaRegistry.componentKey === 'string') {
    return peoplePageConfig(props.componentKey ?? metaRegistry.componentKey)
  }
  return props.componentKey === undefined ? null : peoplePageConfig(props.componentKey)
})

const routeId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? id : undefined
})

const employmentId = computed(() => {
  const id = route.params.id
  if (typeof id !== 'string' || !/^\d+$/.test(id)) return state.value.primaryId
  return Number(id)
})

const profileResource = computed<PeopleProfileResource | null>(() =>
  config.value?.read.kind === 'employee-profile' ? config.value.read.resource : null,
)

const selectedRow = computed<PeopleRow | null>(() => {
  if (selectedId.value !== '') {
    return state.value.rows.find((row) => String(row.id ?? row.case_id ?? row.employment_id ?? '') === selectedId.value) ?? null
  }
  return state.value.rows[0] ?? null
})

const filteredRows = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (term === '') return state.value.rows
  return state.value.rows.filter((row) =>
    Object.values(row).some((value) => {
      if (typeof value === 'string') return value.toLowerCase().includes(term)
      if (typeof value === 'number') return String(value).includes(term)
      return false
    }),
  )
})

const showTable = computed(() => (config.value?.columns.length ?? 0) > 0 && state.value.rows.length > 0)
const detailFields = computed(() => config.value?.detailFields ?? [])
const isPartial = computed(() => state.value.partial || state.value.partialNote !== '')

const movementForms: readonly PeopleForm[] = [
  'movement-onboarding',
  'movement-probation',
  'movement-transfer',
  'movement-salary',
  'movement-offboarding',
]

function isMovementForm(form: PeopleForm | undefined): form is PeopleForm {
  return form !== undefined && movementForms.includes(form)
}

async function load(): Promise<void> {
  const current = config.value
  if (current === null) return
  pageError.clear()
  loading.value = true
  loaded.value = false
  try {
    state.value = await loadPeoplePage(current, route.params)
    selectedId.value = ''
  } catch (reason) {
    state.value = { rows: [], total: 0, partial: false, primaryId: null, partialNote: '' }
    pageError.capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function refreshAfterWrite(): void {
  void load()
}

function openEmployment(id: number | null): void {
  if (id === null) {
    void router.push('/hr/employees')
    return
  }
  void router.push(`/hr/employees/${id}`)
}

function onNavClick(target: string): void {
  void router.push(target)
}

watch(
  () => props.componentKey,
  () => {
    search.value = ''
    void load()
  },
)

watch(
  () => route.fullPath,
  (next, previous) => {
    if (next === previous) return
    search.value = ''
    void load()
  },
)

onMounted(load)
</script>

<template>
  <div v-if="config === null" class="page people-page">
    <ErrorState
      title="页面组件未登记"
      description="当前路由没有对应的员工/组织页面配置，请检查页面注册表。"
      code="PEOPLE_CONFIG_MISSING"
    />
  </div>

  <div v-else class="page people-page" :data-page="config.key">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">{{ config.group }} · Wave 2</p>
        <h1 class="page-header__title">{{ config.title }}</h1>
        <p class="page-header__description">{{ config.description }}</p>
      </div>
      <div class="page-header__actions">
        <span class="people-page__scope">实时接口</span>
        <el-button :disabled="loading" @click="load">刷新</el-button>
      </div>
    </header>

    <nav class="people-local-nav" aria-label="员工与组织快捷入口">
      <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/employees') }" @click="onNavClick('/hr/employees')">员工档案</button>
      <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/departments') }" @click="onNavClick('/hr/departments')">部门管理</button>
      <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/positions') }" @click="onNavClick('/hr/positions')">岗位管理</button>
      <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/movements') }" @click="onNavClick('/hr/movements')">员工异动</button>
      <button type="button" class="people-local-nav__item" :class="{ 'people-local-nav__item--active': config.key.startsWith('hr/contracts') }" @click="onNavClick('/hr/contracts')">劳动合同</button>
    </nav>

    <NoActiveEmploymentState
      v-if="pageError.noActiveEmployment.value"
      :request-id="pageError.requestId.value"
      :links="pageError.safeLinks.value"
    />
    <ErrorState
      v-else-if="pageError.message.value"
      :title="`${config.title}暂时无法加载`"
      :description="pageError.message.value"
      :code="pageError.code.value"
      :request-id="pageError.requestId.value"
      @retry="load"
    />
    <LoadingState v-else-if="loading" :rows="5" :label="`正在加载${config.title}`" />

    <template v-else>
      <el-alert v-if="isPartial" type="warning" :closable="false" show-icon class="people-partial" data-testid="people-partial">
        <template #title>
          部分数据：{{ state.partialNote || '服务端或页面组合只返回了部分结果，请结合请求编号核实。' }}
        </template>
      </el-alert>

      <PeopleGapNotice v-if="config.gap" :gap="config.gap" />

      <EmptyState
        v-if="loaded && state.rows.length === 0 && config.columns.length > 0 && !config.gap"
        :title="`暂无${config.readLabel}`"
        :description="`${config.readLabel}接口当前没有记录。请先通过对应业务动作创建，再返回查看。`"
      />

      <DetailFacts
        v-if="detailFields.length > 0 && state.rows.length > 0"
        :fields="detailFields"
        :row="selectedRow"
        :title="config.title"
      />

      <PeopleDataTable
        v-if="showTable"
        :columns="config.columns"
        :rows="filteredRows"
        :total="state.total"
        :label="config.readLabel"
        :selectable="config.selectable === true"
        :selected-id="selectedId"
        :show-search="state.rows.length > 5 || config.selectable === true"
        :search-value="search"
        @update:search-value="search = $event"
        @update:selected-id="selectedId = $event"
      >
        <template #footer>
          <span v-if="config.read.kind === 'lifecycle-cases'" class="people-table-panel__hint">
            申请状态与生效状态分开显示；审批通过后仍按生效日执行。
          </span>
          <span v-else-if="config.read.kind === 'contract-expiring'" class="people-table-panel__hint">
            60 / 30 / 7 天预警区间按服务端剩余天数计算。
          </span>
          <span v-else-if="config.selectable" class="people-table-panel__hint">
            选择一个岗位后可在下方表单调整编制。
          </span>
        </template>
      </PeopleDataTable>

      <ProfileResourceForm
        v-if="profileResource !== null"
        :resource="profileResource"
        :employment-id="employmentId"
        :rows="state.rows"
        @saved="refreshAfterWrite"
      />

      <EmployeeCreateForm v-if="config.form === 'employee-create'" @created="openEmployment" />

      <DepartmentForms
        v-if="config.form === 'department-create' || config.form === 'department-member' || config.form === 'department-move'"
        :form="config.form"
        :rows="state.rows"
        :as-of="new Date().toISOString().slice(0, 10)"
        @saved="refreshAfterWrite"
      />

      <PositionForms
        v-if="config.form === 'position-editor' || config.form === 'position-headcount'"
        :form="config.form"
        :rows="state.rows"
        :position-id="selectedId"
        @saved="refreshAfterWrite"
      />

      <MovementForms
        v-if="isMovementForm(config.form)"
        :form="config.form"
        @created="refreshAfterWrite"
      />

      <ContractForm
        v-if="config.form === 'contract-editor' || config.form === 'contract-renewal' || config.form === 'contract-changes' || config.form === 'contract-template-editor'"
        :rows="state.rows"
        :employment-id="routeId === undefined ? null : employmentId"
        :form="config.form"
        @created="refreshAfterWrite"
        @saved="refreshAfterWrite"
      />

      <section v-if="config.read.kind === 'org-tree' && state.rows.length > 0" class="panel people-tree-panel">
        <header class="panel__header">
          <div>
            <h2 class="panel__title">组织层级</h2>
            <p class="panel__subtitle">按上级编号还原有效组织层级；服务端树接口返回扁平节点。</p>
          </div>
        </header>
        <div class="panel__body">
          <ul class="people-tree">
            <li v-for="row in state.rows" :key="String(row.id)" class="people-tree__item">
              <span class="people-tree__code">{{ rowText(row, 'code') }}</span>
              <span class="people-tree__name">{{ rowText(row, 'name') }}</span>
              <span class="people-tree__meta">上级 {{ rowText(row, 'parent_id') }}</span>
            </li>
          </ul>
        </div>
      </section>

      <section v-if="config.read.kind === 'lifecycle-cases' && state.rows.length > 0" class="panel people-calendar-panel">
        <header class="panel__header">
          <div>
            <h2 class="panel__title">按生效日查看</h2>
            <p class="panel__subtitle">仅展示接口返回的拟生效时间，不推算尚未发生的状态变化。</p>
          </div>
        </header>
        <div class="panel__body">
          <ul class="people-calendar">
            <li v-for="row in state.rows" :key="String(row.case_id)" class="people-calendar__item">
              <span class="people-calendar__date">{{ rowText(row, 'effective_at') }}</span>
              <span class="people-calendar__case">{{ rowText(row, 'case_no') }}</span>
              <span class="people-calendar__type">{{ rowText(row, 'case_type') }}</span>
            </li>
          </ul>
        </div>
      </section>

      <section v-if="config.read.kind === 'contract-expiring' && state.rows.length > 0" class="panel people-expiry-panel">
        <header class="panel__header">
          <div>
            <h2 class="panel__title">到期预警区间</h2>
            <p class="panel__subtitle">根据服务端 days_to_expiry 标记 60 / 30 / 7 天。</p>
          </div>
        </header>
        <div class="panel__body">
          <ul class="people-expiry">
            <li v-for="row in state.rows" :key="String(row.id)" class="people-expiry__item">
              <span class="people-expiry__no">{{ rowText(row, 'contract_no') }}</span>
              <span class="people-expiry__days" :class="{ 'people-expiry__days--urgent': Number(row.days_to_expiry ?? 999) <= 7 }">
                {{ rowText(row, 'days_to_expiry') }} 天
              </span>
              <span>{{ rowText(row, 'end_date') }}</span>
            </li>
          </ul>
        </div>
      </section>
    </template>
  </div>
</template>

<style>
@import './people.css';
</style>

<style scoped>
.people-page {
  gap: 16px;
}

.people-page__scope {
  padding: 4px 9px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}

.people-local-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 5px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.people-local-nav__item {
  padding: 7px 13px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  background: transparent;
  border: 0;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.people-local-nav__item:hover {
  color: var(--color-brand);
  background: var(--color-brand-soft);
}

.people-local-nav__item--active {
  color: #fff;
  font-weight: 600;
  background: var(--color-brand);
}

.people-partial {
  border-radius: var(--radius-md);
}

.people-tree-panel,
.people-calendar-panel,
.people-expiry-panel {
  overflow: hidden;
}

.people-tree,
.people-calendar,
.people-expiry {
  margin: 0;
  padding: 0;
  list-style: none;
}

.people-tree__item,
.people-calendar__item,
.people-expiry__item {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  align-items: baseline;
  padding: 9px 0;
  font-size: 12px;
  border-bottom: 1px solid var(--color-line);
}

.people-tree__item:last-child,
.people-calendar__item:last-child,
.people-expiry__item:last-child {
  border-bottom: 0;
}

.people-tree__code,
.people-calendar__date,
.people-expiry__no {
  color: var(--color-ink-muted);
  font-family: ui-monospace, "SFMono-Regular", Consolas, monospace;
  font-size: 11px;
}

.people-tree__name,
.people-calendar__case {
  color: var(--color-ink);
  font-weight: 600;
}

.people-tree__meta,
.people-calendar__type {
  margin-left: auto;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.people-expiry__days {
  color: var(--color-warning);
  font-weight: 650;
}

.people-expiry__days--urgent {
  color: var(--color-danger);
}
</style>
