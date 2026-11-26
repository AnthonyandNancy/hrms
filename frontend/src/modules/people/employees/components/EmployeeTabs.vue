<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { requestIdFromError } from '@/utils/renderErrors'
import { loadEmployeeTab, type EmployeeTabKey, type EmployeeTabPayload } from '../detailApi'

const props = withDefaults(
  defineProps<{
    employmentId: number
    initialTab?: string
  }>(),
  { initialTab: 'overview' },
)

const emit = defineEmits<{
  'update:tab': [tab: EmployeeTabKey]
}>()

type TabDefinition = { key: EmployeeTabKey; label: string }

const TABS: readonly TabDefinition[] = [
  { key: 'overview', label: '基本信息' },
  { key: 'employment', label: '任职与异动' },
  { key: 'contracts', label: '合同' },
  { key: 'credentials', label: '证照与附件' },
  { key: 'education', label: '教育经历' },
  { key: 'experience', label: '工作经历' },
  { key: 'contacts', label: '联系方式' },
  { key: 'changes', label: '变更申请' },
]

const TAB_ALIASES: Record<string, EmployeeTabKey> = {
  overview: 'overview',
  employment: 'employment',
  contracts: 'contracts',
  credentials: 'credentials',
  files: 'credentials',
  education: 'education',
  experience: 'experience',
  'work-history': 'experience',
  contacts: 'contacts',
  changes: 'changes',
}

type TabState = {
  loaded: boolean
  loading: boolean
  forbidden: boolean
  error: string | null
  requestId: string | null
  payload: EmployeeTabPayload | null
}

function emptyState(): TabState {
  return { loaded: false, loading: false, forbidden: false, error: null, requestId: null, payload: null }
}

function initialState(): Record<EmployeeTabKey, TabState> {
  return {
    overview: { ...emptyState(), loaded: true },
    employment: emptyState(),
    contracts: emptyState(),
    credentials: emptyState(),
    education: emptyState(),
    experience: emptyState(),
    contacts: emptyState(),
    changes: emptyState(),
  }
}

function normaliseTab(value: string | undefined): EmployeeTabKey {
  if (value === undefined) return 'overview'
  return TAB_ALIASES[value] ?? 'overview'
}

const activeTab = ref<EmployeeTabKey>(normaliseTab(props.initialTab))
const states = reactive<Record<EmployeeTabKey, TabState>>(initialState())

const activeState = computed(() => states[activeTab.value])
const panelTestId = computed(() => `tab-${activeTab.value}-panel`)

const HIDDEN = '无权查看'
const EMPTY = '—'

function isForbidden(reason: unknown): boolean {
  return reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')
}

async function load(tab: EmployeeTabKey, force = false): Promise<void> {
  if (tab === 'overview') return
  const state = states[tab]
  if (state.loading) return
  if (!force && state.loaded) return

  state.loading = true
  state.loaded = false
  state.error = null
  state.requestId = null
  state.forbidden = false

  try {
    state.payload = await loadEmployeeTab(props.employmentId, tab)
    state.loaded = true
  } catch (reason) {
    if (isForbidden(reason)) {
      state.forbidden = true
    } else {
      state.error = errorCopy(reason)
      state.requestId = requestIdFromError(reason)
    }
    state.loaded = true
  } finally {
    state.loading = false
  }
}

function selectTab(tab: EmployeeTabKey): void {
  activeTab.value = tab
  emit('update:tab', tab)
  void load(tab)
}

watch(
  () => props.initialTab,
  (value) => {
    const next = normaliseTab(value)
    activeTab.value = next
    void load(next)
  },
  { immediate: true },
)

watch(
  () => props.employmentId,
  () => {
    Object.assign(states, initialState())
    activeTab.value = normaliseTab(props.initialTab)
    void load(activeTab.value)
  },
)

type ColumnKind = 'text' | 'date' | 'boolean' | 'enum' | 'bytes'

type Column = {
  key: string
  label: string
  kind?: ColumnKind
  labels?: Record<string, string>
}

const CONTRACT_TYPE: Record<string, string> = {
  FIXED_TERM: '固定期限',
  OPEN_ENDED: '无固定期限',
  INTERNSHIP: '实习协议',
  CONSULTANT: '顾问协议',
}

const CONTRACT_STATUS: Record<string, string> = {
  DRAFT: '草稿',
  ACTIVE: '生效中',
  EXPIRING: '即将到期',
  RENEWED: '已续签',
  TERMINATED: '已终止',
  EXPIRED: '已到期',
}

const TIMELINE_EVENT: Record<string, string> = {
  EMPLOYMENT_CREATED: '员工关系创建',
  ASSIGNMENT_STARTED: '任职开始',
  TERMS_VERSION: '雇佣条款版本',
  DOCUMENT_ATTACHED: '附件登记',
}

const TIMELINE_TYPE: Record<string, string> = {
  EMPLOYMENT: '员工关系',
  ASSIGNMENT: '任职',
  TERMS: '雇佣条款',
  DOCUMENT: '附件',
}

const CERTIFICATE_TYPE: Record<string, string> = {
  PROFESSIONAL: '职业资格',
  ID: '身份证明',
  EDUCATION: '教育证明',
  OTHER: '其他',
}

const DOCUMENT_CATEGORY: Record<string, string> = {
  CONTRACT: '合同',
  ID: '身份证明',
  EDUCATION: '教育材料',
  CERTIFICATE: '证照材料',
  OTHER: '其他',
}

const EDUCATION_DEGREE: Record<string, string> = {
  ASSOCIATE: '大专',
  BACHELOR: '本科',
  MASTER: '硕士',
  DOCTOR: '博士',
  HIGH_SCHOOL: '高中',
  OTHER: '其他',
}

const CONTACT_RELATIONSHIP: Record<string, string> = {
  SPOUSE: '配偶',
  PARENT: '父母',
  CHILD: '子女',
  SIBLING: '兄弟姐妹',
  FRIEND: '朋友',
  OTHER: '其他',
}

const CHANGE_STATUS: Record<string, string> = {
  DRAFT: '草稿',
  SUBMITTED: '已提交',
  IN_APPROVAL: '审批中',
  APPROVED: '已通过',
  REJECTED: '已驳回',
  WITHDRAWN: '已撤回',
}

const SECTION_COLUMNS: Record<string, readonly Column[]> = {
  timeline: [
    { key: 'effective_date', label: '生效日期', kind: 'date' },
    { key: 'event', label: '事件', kind: 'enum', labels: TIMELINE_EVENT },
    { key: 'type', label: '记录类型', kind: 'enum', labels: TIMELINE_TYPE },
  ],
  contracts: [
    { key: 'contract_no', label: '合同编号' },
    { key: 'contract_type', label: '合同类型', kind: 'enum', labels: CONTRACT_TYPE },
    { key: 'start_date', label: '开始日期', kind: 'date' },
    { key: 'end_date', label: '结束日期', kind: 'date' },
    { key: 'status', label: '状态', kind: 'enum', labels: CONTRACT_STATUS },
    { key: 'version', label: '版本' },
  ],
  certificates: [
    { key: 'certificate_type', label: '证照类型', kind: 'enum', labels: CERTIFICATE_TYPE },
    { key: 'certificate_no', label: '证照编号' },
    { key: 'issuer', label: '签发机构' },
    { key: 'issued_on', label: '签发日期', kind: 'date' },
    { key: 'expires_on', label: '到期日期', kind: 'date' },
  ],
  documents: [
    { key: 'original_name', label: '文件名' },
    { key: 'category', label: '分类', kind: 'enum', labels: DOCUMENT_CATEGORY },
    { key: 'version', label: '版本' },
    { key: 'size_bytes', label: '大小', kind: 'bytes' },
    { key: 'legal_hold', label: '法律保留', kind: 'boolean' },
    { key: 'created_at', label: '上传时间', kind: 'date' },
  ],
  education: [
    { key: 'school', label: '院校' },
    { key: 'degree', label: '学历', kind: 'enum', labels: EDUCATION_DEGREE },
    { key: 'major', label: '专业' },
    { key: 'start_date', label: '开始日期', kind: 'date' },
    { key: 'end_date', label: '结束日期', kind: 'date' },
  ],
  experiences: [
    { key: 'company', label: '任职单位' },
    { key: 'title', label: '岗位' },
    { key: 'start_date', label: '开始日期', kind: 'date' },
    { key: 'end_date', label: '结束日期', kind: 'date' },
    { key: 'leave_reason', label: '离职原因' },
  ],
  contacts: [
    { key: 'name', label: '联系人' },
    { key: 'relationship', label: '关系', kind: 'enum', labels: CONTACT_RELATIONSHIP },
    { key: 'phone', label: '联系电话' },
    { key: 'email', label: '邮箱' },
    { key: 'is_emergency', label: '紧急联系人', kind: 'boolean' },
  ],
  changes: [
    { key: 'status', label: '状态', kind: 'enum', labels: CHANGE_STATUS },
    { key: 'reason', label: '变更原因' },
    { key: 'submitted_at', label: '提交时间', kind: 'date' },
  ],
}

function columnsFor(kind: string): readonly Column[] {
  return SECTION_COLUMNS[kind] ?? []
}

function has(row: Record<string, unknown>, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(row, key)
}

function businessDate(value: unknown): string {
  if (typeof value !== 'string') return HIDDEN
  if (value === '') return EMPTY
  const date = value.slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return HIDDEN
  const [year, month, day] = date.split('-')
  const base = `${year}年${Number(month)}月${Number(day)}日`
  if (value.includes('T')) {
    const time = value.slice(11, 16)
    return /^\d{2}:\d{2}$/.test(time) ? `${base} ${time}` : base
  }
  return base
}

function bytes(value: unknown): string {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) return HIDDEN
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  return `${(value / (1024 * 1024)).toFixed(1)} MB`
}

function cell(row: Record<string, unknown>, column: Column): string {
  if (!has(row, column.key)) return HIDDEN
  const value = row[column.key]
  if (value === null || value === undefined) return EMPTY

  if (column.kind === 'enum') {
    if (typeof value !== 'string' || value === '') return HIDDEN
    return column.labels?.[value] ?? '未知'
  }
  if (column.kind === 'boolean') {
    if (typeof value !== 'boolean') return HIDDEN
    return value ? '是' : '否'
  }
  if (column.kind === 'date') return businessDate(value)
  if (column.kind === 'bytes') return bytes(value)

  if (typeof value === 'object') {
    const record = value as Record<string, unknown>
    return record.masked === true ? HIDDEN : HIDDEN
  }
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'string') return value.trim() === '' ? EMPTY : value
  if (typeof value === 'number') return String(value)
  return HIDDEN
}
</script>

<template>
  <section class="employee-tabs" data-testid="employee-tabs">
    <nav class="employee-tabs__nav" role="tablist" aria-label="员工详情页签">
      <button
        v-for="tab in TABS"
        :key="tab.key"
        type="button"
        role="tab"
        class="employee-tabs__tab"
        :class="{ 'employee-tabs__tab--active': activeTab === tab.key }"
        :aria-selected="activeTab === tab.key"
        :data-testid="`tab-${tab.key}`"
        @click="selectTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </nav>

    <div class="employee-tabs__panel" role="tabpanel" :data-testid="panelTestId">
      <template v-if="activeTab === 'overview'">
        <header class="employee-tabs__section-header">
          <h3>基本信息</h3>
          <p>姓名、员工编号、部门、岗位、状态与入职日期已在上方摘要展示。</p>
        </header>
        <div class="employee-tabs__notice" data-testid="tab-overview-note">
          本页签只呈现服务端已返回的字段。缺失或被字段策略隐藏的属性显示“无权查看”，
          不根据姓名、编号或组织标识推断任何值。合同、证照、经历、联系人与变更申请分别在各自页签按需加载。
        </div>
      </template>

      <div v-else-if="activeState.loading" class="employee-tabs__state" role="status" data-testid="tab-loading">
        正在加载该页签数据…
      </div>

      <section v-else-if="activeState.forbidden" class="employee-tabs__state employee-tabs__state--forbidden" data-testid="tab-forbidden">
        <h3>无权查看</h3>
        <p>你没有查看该页签数据的权限。页面摘要与其他页签仍可使用。</p>
      </section>

      <section v-else-if="activeState.error" class="employee-tabs__state employee-tabs__state--error" role="alert" data-testid="tab-error">
        <h3>该页签暂时无法加载</h3>
        <p>{{ activeState.error }}</p>
        <p v-if="activeState.requestId">
          请求编号：<span class="employee-tabs__mono">{{ activeState.requestId }}</span>
        </p>
        <button type="button" class="employee-tabs__retry" @click="load(activeTab, true)">重试</button>
      </section>

      <template v-else>
        <div v-if="activeState.payload === null || activeState.payload.sections.length === 0" class="employee-tabs__state" data-testid="tab-empty">
          <h3>暂无数据</h3>
          <p>该页签对应的接口当前没有返回记录。</p>
        </div>

        <div v-for="section in activeState.payload?.sections ?? []" :key="section.key" class="employee-tabs__section">
          <header class="employee-tabs__section-header">
            <h3>{{ section.label }}</h3>
            <span class="employee-tabs__count">共 {{ section.total }} 条</span>
          </header>
          <div v-if="section.rows.length === 0" class="employee-tabs__state employee-tabs__state--empty" data-testid="tab-empty">
            <p>暂无{{ section.label }}记录。</p>
          </div>
          <div v-else class="employee-tabs__table-wrap">
            <table class="employee-tabs__table">
              <caption class="employee-tabs__caption">{{ section.label }}</caption>
              <thead>
                <tr>
                  <th v-for="column in columnsFor(section.kind)" :key="column.key" scope="col">{{ column.label }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in section.rows" :key="String(row.id ?? index)">
                  <td
                    v-for="column in columnsFor(section.kind)"
                    :key="column.key"
                    :class="{ 'employee-tabs__hidden': cell(row, column) === HIDDEN }"
                  >
                    {{ cell(row, column) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>
  </section>
</template>

<style scoped>
.employee-tabs {
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-line, #e5e7eb);
  border-radius: var(--radius-md, 10px);
}

.employee-tabs__nav {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  padding: 8px 10px 0;
  border-bottom: 1px solid var(--color-line, #e5e7eb);
}

.employee-tabs__tab {
  padding: 10px 14px;
  font: inherit;
  font-size: 13px;
  color: var(--color-ink-secondary, #4b5563);
  cursor: pointer;
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
}

.employee-tabs__tab:hover {
  color: var(--color-ink, #111827);
}

.employee-tabs__tab--active {
  color: var(--color-brand, #2563eb);
  border-bottom-color: var(--color-brand, #2563eb);
}

.employee-tabs__panel {
  padding: 16px 18px 20px;
}

.employee-tabs__section + .employee-tabs__section {
  margin-top: 18px;
}

.employee-tabs__section-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.employee-tabs__section-header h3 {
  margin: 0;
  font-size: 15px;
}

.employee-tabs__section-header p {
  margin: 6px 0 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}

.employee-tabs__count {
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}

.employee-tabs__notice {
  margin-top: 12px;
  padding: 12px;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 13px;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}

.employee-tabs__state {
  margin-top: 12px;
  padding: 20px;
  text-align: center;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}

.employee-tabs__state h3 {
  margin: 0 0 6px;
  font-size: 15px;
}

.employee-tabs__state p {
  margin: 4px 0;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 13px;
}

.employee-tabs__state--forbidden {
  background: #fef2f2;
}

.employee-tabs__state--forbidden h3 {
  color: #b91c1c;
}

.employee-tabs__state--error {
  background: #fff7ed;
}

.employee-tabs__retry {
  margin-top: 10px;
  padding: 6px 14px;
  font: inherit;
  cursor: pointer;
  background: #fff;
  border: 1px solid var(--color-line, #d1d5db);
  border-radius: 6px;
}

.employee-tabs__table-wrap {
  margin-top: 10px;
  overflow-x: auto;
}

.employee-tabs__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.employee-tabs__table th,
.employee-tabs__table td {
  padding: 9px 10px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line, #e5e7eb);
}

.employee-tabs__table th {
  color: var(--color-ink-muted, #6b7280);
  font-weight: 600;
  background: var(--color-canvas, #f9fafb);
}

.employee-tabs__hidden {
  color: var(--color-ink-muted, #6b7280);
  font-style: italic;
}

.employee-tabs__caption {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}

.employee-tabs__mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
</style>
