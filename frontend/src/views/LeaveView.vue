<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getApi } from '@/api'
import type { CaseSummary, LeaveBalance, LeaveLedger, LeavePreview, LeaveRequestPayload } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { leaveRequestSchema, validate } from '@/utils/validation'
import StatusBadge from '@/components/StatusBadge.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'

const loading = ref(true)
const error = ref('')
const balances = ref<LeaveBalance[]>([])
const requests = ref<CaseSummary[]>([])
const selectedType = ref('ANNUAL')

function defaultLeaveStart(): string {
  // Hard-coded demo dates become past dates once real time moves on, and the
  // preview watch then fires an already-invalid request on page load.
  return todayBusinessDate()
}

const form = reactive<LeaveRequestPayload>({
  leaveTypeCode: 'ANNUAL',
  startDate: defaultLeaveStart(),
  endDate: defaultLeaveStart(),
  halfDay: false,
  reason: '',
})
const errors = ref<Record<string, string>>({})
const preview = ref<LeavePreview | null>(null)
const previewLoading = ref(false)
const submitting = ref(false)
const ledgerOpen = ref(false)
const ledgerLoading = ref(false)
const ledgerError = ref('')
const ledger = ref<LeaveLedger | null>(null)

const selectedBalance = computed(() => balances.value.find((balance) => balance.leaveTypeCode === form.leaveTypeCode) ?? null)

const weekdayGuard = computed(() => {
  const start = new Date(`${form.startDate}T00:00:00Z`)
  const end = new Date(`${form.endDate}T00:00:00Z`)
  return end >= start
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [loadedBalances, loadedRequests] = await Promise.all([
      getApi().leave.getLeaveBalances(),
      getApi().leave.getLeaveRequests(),
    ])
    balances.value = loadedBalances
    requests.value = loadedRequests
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

let previewTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => [form.leaveTypeCode, form.startDate, form.endDate, form.halfDay],
  () => {
    clearTimeout(previewTimer)
    if (!weekdayGuard.value) {
      preview.value = null
      return
    }
    previewTimer = setTimeout(() => void loadPreview(), 250)
  },
)

async function loadPreview(): Promise<void> {
  previewLoading.value = true
  try {
    preview.value = await getApi().leave.previewLeave({ ...form })
  } catch {
    preview.value = null
  } finally {
    previewLoading.value = false
  }
}

async function submit(): Promise<void> {
  errors.value = {}
  const parsed = validate(leaveRequestSchema, form)
  if (!parsed.success) {
    for (const issue of parsed.issues) errors.value[issue.path] = issue.message
    return
  }
  if (preview.value?.conflicts.some((conflict) => conflict.severity === 'BLOCKING')) {
    errors.value.conflicts = '存在阻断性排班冲突，不能提交。请调整日期后重试。'
    return
  }
  submitting.value = true
  try {
    const result = await getApi().leave.requestLeave(parsed.data!)
    ElMessage.success(`申请已提交：${result.caseNo}。申请状态为“已提交”，审批通过后才会占用余额。`)
    form.reason = ''
    preview.value = null
    await load()
  } catch (reason) {
    errors.value.form = errorCopy(reason)
  } finally {
    submitting.value = false
  }
}

async function cancel(item: CaseSummary): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `撤回申请「${item.title}」？撤回会释放待占用余额，并记录在时间线中。`,
      '撤回申请',
      { confirmButtonText: '撤回', cancelButtonText: '保留', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await getApi().leave.cancelLeave(item.id)
    ElMessage.success('申请已撤回，账本占用已释放。')
    await load()
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  }
}

async function openLedger(leaveTypeCode: string): Promise<void> {
  selectedType.value = leaveTypeCode
  ledgerOpen.value = true
  ledgerLoading.value = true
  ledgerError.value = ''
  ledger.value = null
  try {
    ledger.value = await getApi().leave.getLeaveLedger(leaveTypeCode)
  } catch (reason) {
    ledgerError.value = errorCopy(reason)
  } finally {
    ledgerLoading.value = false
  }
}

const LEDGER_LABEL: Record<LeaveLedger['entries'][number]['entryType'], string> = {
  GRANT: '发放',
  RESERVE: '占用',
  CONSUME: '结算使用',
  RELEASE: '释放占用',
  EXPIRE: '到期清零',
  ADJUST: '调整',
  REVERSE: '冲正',
}

onMounted(async () => {
  await load()
  await loadPreview()
})
</script>

<template>
  <div class="page leave-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">假勤</p>
        <h1 class="page-header__title">假期与余额</h1>
        <p class="page-header__description">
          余额由账本分录汇总而来，没有可直接修改的“余额字段”。每一笔数字都能下钻到发放、占用、结算或冲正记录。
        </p>
      </div>
    </header>

    <ErrorState v-if="error" description="假期数据暂时无法加载。" :code="error" @retry="load" />
    <LoadingState v-else-if="loading" :rows="7" label="正在加载假期数据" />

    <template v-else>
      <section class="balance-grid" aria-label="假期余额">
        <article
          v-for="balance in balances"
          :key="balance.leaveTypeCode"
          class="balance-card"
          :class="{ 'balance-card--active': selectedType === balance.leaveTypeCode }"
        >
          <div class="balance-card__top">
            <h2 class="balance-card__name">{{ balance.leaveTypeName }}</h2>
            <span class="plain-chip">{{ balance.unit === 'DAY' ? '天' : '小时' }}</span>
          </div>
          <p class="balance-card__value">
            {{ balance.available }}<span>{{ balance.unit === 'DAY' ? '天可用' : '小时可用' }}</span>
          </p>
          <div class="balance-card__bar" aria-hidden="true">
            <span
              :style="{
                width: `${balance.granted > 0 ? Math.min(100, (balance.used / balance.granted) * 100) : 0}%`,
              }"
            />
          </div>
          <dl class="balance-card__facts">
            <div><dt>发放</dt><dd>{{ balance.granted }}</dd></div>
            <div><dt>已用</dt><dd>{{ balance.used }}</dd></div>
            <div><dt>待占用</dt><dd>{{ balance.pending }}</dd></div>
          </dl>
          <div class="balance-card__footer">
            <span v-if="balance.expiresOn" class="small muted">有效至 {{ formatBusinessDate(balance.expiresOn) }}</span>
            <span v-else class="small muted">长期有效</span>
            <el-button text size="small" @click="openLedger(balance.leaveTypeCode)">为什么是这个数？</el-button>
          </div>
        </article>
      </section>

      <div class="leave-layout">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">发起请假</h2>
              <p class="panel__subtitle">提交前会检查余额与团队排班冲突；审批通过后才占用余额。</p>
            </div>
          </div>
          <div class="panel__body">
            <el-alert v-if="errors.form" type="error" :title="errors.form" :closable="false" show-icon class="leave-error" />
            <form class="leave-form" novalidate @submit.prevent="submit">
              <label class="leave-field">
                <span>假期类型</span>
                <el-select v-model="form.leaveTypeCode" aria-label="假期类型">
                  <el-option
                    v-for="balance in balances"
                    :key="balance.leaveTypeCode"
                    :label="`${balance.leaveTypeName}（可用 ${balance.available}）`"
                    :value="balance.leaveTypeCode"
                  />
                </el-select>
              </label>
              <div class="leave-form__row">
                <label class="leave-field">
                  <span>开始日期</span>
                  <el-date-picker
                    v-model="form.startDate"
                    type="date"
                    value-format="YYYY-MM-DD"
                    format="YYYY-MM-DD"
                    aria-label="请假开始日期"
                  />
                  <em v-if="errors.startDate" class="field-error">{{ errors.startDate }}</em>
                </label>
                <label class="leave-field">
                  <span>结束日期</span>
                  <el-date-picker
                    v-model="form.endDate"
                    type="date"
                    value-format="YYYY-MM-DD"
                    format="YYYY-MM-DD"
                    aria-label="请假结束日期"
                  />
                  <em v-if="errors.endDate" class="field-error">{{ errors.endDate }}</em>
                </label>
              </div>
              <el-checkbox v-model="form.halfDay">仅请半天（开始与结束日期必须相同）</el-checkbox>
              <label class="leave-field">
                <span>请假事由</span>
                <el-input
                  v-model="form.reason"
                  type="textarea"
                  :rows="3"
                  maxlength="200"
                  show-word-limit
                  placeholder="请填写具体事由，审批人会看到该说明"
                  :aria-invalid="Boolean(errors.reason)"
                />
                <em v-if="errors.reason" class="field-error">{{ errors.reason }}</em>
              </label>

              <div class="balance-preview" aria-live="polite">
                <div class="balance-preview__heading">
                  <span class="section-title">余额预览</span>
                  <span v-if="selectedBalance" class="small muted">{{ selectedBalance.leaveTypeName }}</span>
                </div>
                <LoadingState v-if="previewLoading" :rows="2" label="正在检查余额与冲突" />
                <template v-else-if="preview">
                  <div class="balance-preview__numbers">
                    <div><span>申请天数</span><strong>{{ preview.requestedDays }}</strong></div>
                    <div><span>当前可用</span><strong>{{ preview.availableBefore }}</strong></div>
                    <div>
                      <span>通过后可用</span>
                      <strong :class="{ 'text-danger': preview.availableAfter < 0 }">{{ preview.availableAfter }}</strong>
                    </div>
                  </div>
                  <p class="small muted">{{ preview.note }}</p>
                  <ul v-if="preview.conflicts.length" class="conflict-list">
                    <li
                      v-for="conflict in preview.conflicts"
                      :key="`${conflict.date}-${conflict.label}`"
                      :class="`conflict-list__item--${conflict.severity.toLowerCase()}`"
                    >
                      <span class="plain-chip">{{ conflict.severity === 'BLOCKING' ? '阻断' : '提醒' }}</span>
                      {{ conflict.date }}：{{ conflict.label }}
                    </li>
                  </ul>
                  <p v-if="errors.conflicts" class="field-error">{{ errors.conflicts }}</p>
                </template>
              </div>

              <div class="leave-form__actions">
                <el-button type="primary" native-type="submit" :loading="submitting">提交请假申请</el-button>
                <span class="small muted">提交即生成 Case；决定与生效分别记录。</span>
              </div>
            </form>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的请假申请</h2>
              <p class="panel__subtitle">可撤回的只有尚未进入不可撤回状态的申请。</p>
            </div>
          </div>
          <ul v-if="requests.length" class="request-list">
            <li v-for="item in requests" :key="item.id" class="request-row">
              <div class="request-row__main">
                <p class="request-row__title">{{ item.title }}</p>
                <p class="request-row__meta">
                  {{ item.caseNo }} · {{ formatDateTimeInTimezone(item.appliedAt) }}
                </p>
                <div class="status-pair">
                  <StatusBadge kind="application" :status="item.applicationStatus" show-label-prefix size="sm" />
                  <StatusBadge kind="effect" :status="item.effectStatus" show-label-prefix size="sm" />
                </div>
              </div>
              <div class="request-row__actions">
                <el-button
                  v-if="['SUBMITTED', 'IN_APPROVAL'].includes(item.applicationStatus)"
                  text
                  size="small"
                  @click="cancel(item)"
                >
                  撤回
                </el-button>
                <RouterLink :to="`/cases/${item.id}`" class="small">详情</RouterLink>
              </div>
            </li>
          </ul>
          <div v-else class="panel__body">
            <p class="empty-inline">还没有请假申请。使用左侧表单发起第一笔。</p>
          </div>
        </section>
      </div>
    </template>

    <el-drawer v-model="ledgerOpen" size="min(660px, 96vw)" title="假期账本明细">
      <div class="drawer-body">
        <LoadingState v-if="ledgerLoading" :rows="6" label="正在加载账本明细" />
        <ErrorState v-else-if="ledgerError" title="无法加载账本" :description="ledgerError" @retry="openLedger(selectedType)" />
        <template v-else-if="ledger">
          <div class="ledger-heading">
            <div>
              <p class="muted small">账户</p>
              <h2>{{ ledger.leaveTypeName }}</h2>
            </div>
            <div class="ledger-balance">
              <span>当前余额</span>
              <strong>{{ ledger.closing }} {{ ledger.unit === 'DAY' ? '天' : '小时' }}</strong>
            </div>
          </div>
          <p class="small muted">
            期初 {{ ledger.opening }} · 所有变动由不可变账本分录驱动；正负号已计入。余额 = 分录累计。
          </p>
          <div class="ledger-table-wrap">
            <table class="ledger-table">
              <caption class="visually-hidden">假期账本分录</caption>
              <thead>
                <tr>
                  <th scope="col">业务日期</th>
                  <th scope="col">类型</th>
                  <th scope="col" class="align-right">数量</th>
                  <th scope="col" class="align-right">余额</th>
                  <th scope="col">原因 / 来源</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="entry in ledger.entries" :key="entry.id">
                  <td class="tabular">{{ entry.businessDate }}</td>
                  <td><span class="plain-chip">{{ LEDGER_LABEL[entry.entryType] }}</span></td>
                  <td class="align-right tabular" :class="{ 'text-danger': entry.quantity < 0 }">
                    {{ entry.quantity > 0 ? '+' : '' }}{{ entry.quantity }}
                  </td>
                  <td class="align-right tabular">{{ entry.balanceAfter }}</td>
                  <td>
                    <span>{{ entry.reason }}</span>
                    <span v-if="entry.sourceCaseNo" class="ledger-case mono">{{ entry.sourceCaseNo }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="small muted">
            记账时间使用 UTC 存储，业务日期按租户时区 Asia/Shanghai 解释。历史分录不更新、不删除，撤销通过反向分录表达。
          </p>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped>
.balance-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.balance-card {
  display: flex;
  flex-direction: column;
  padding: 15px 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.balance-card--active {
  border-color: #9cbab4;
  box-shadow: inset 0 0 0 1px #9cbab4;
}

.balance-card__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.balance-card__name {
  font-size: 12.5px;
  font-weight: 650;
}

.balance-card__value {
  margin-top: 4px;
  font-size: 28px;
  font-weight: 700;
  letter-spacing: -0.03em;
  font-variant-numeric: tabular-nums;
}

.balance-card__value span {
  margin-left: 4px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 500;
  letter-spacing: 0;
}

.balance-card__bar {
  height: 4px;
  margin: 8px 0 10px;
  overflow: hidden;
  background: var(--color-canvas-deep);
  border-radius: 2px;
}

.balance-card__bar span {
  display: block;
  height: 100%;
  background: #4a8c7d;
}

.balance-card__facts {
  display: flex;
  gap: 14px;
  margin: 0;
}

.balance-card__facts div {
  display: flex;
  gap: 3px;
  font-size: 10.5px;
}

.balance-card__facts dt {
  color: var(--color-ink-muted);
}

.balance-card__facts dd {
  margin: 0;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.balance-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px solid #edf1ef;
}

.leave-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(320px, 1fr);
  gap: 16px;
  align-items: start;
}

.leave-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.leave-form__row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.leave-field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.leave-field :deep(.el-date-editor) {
  width: 100%;
}

.field-error {
  color: var(--color-danger);
  font-size: 11px;
  font-style: normal;
  font-weight: 400;
}

.balance-preview {
  padding: 12px;
  background: var(--color-canvas);
  border-radius: 7px;
}

.balance-preview__heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 9px;
}

.balance-preview__numbers {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px;
  margin-bottom: 7px;
}

.balance-preview__numbers div {
  display: flex;
  flex-direction: column;
  padding: 7px 9px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 5px;
}

.balance-preview__numbers span {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.balance-preview__numbers strong {
  font-size: 18px;
  font-variant-numeric: tabular-nums;
}

.text-danger {
  color: var(--color-danger) !important;
}

.conflict-list {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin: 8px 0 0;
  padding: 0;
  list-style: none;
  font-size: 11.5px;
}

.conflict-list li {
  display: flex;
  align-items: center;
  gap: 6px;
}

.conflict-list__item--blocking {
  color: var(--color-danger);
}

.conflict-list__item--warning {
  color: var(--color-warning);
}

.leave-form__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.leave-error {
  margin-bottom: 12px;
}

.request-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.request-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 13px 16px;
  border-bottom: 1px solid #edf1ef;
}

.request-row:last-child {
  border-bottom: 0;
}

.request-row__title {
  font-size: 12.5px;
  font-weight: 600;
}

.request-row__meta {
  margin-bottom: 5px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.request-row__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.drawer-body {
  padding: 20px 22px 30px;
}

.ledger-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 7px;
}

.ledger-heading h2 {
  font-size: 20px;
  font-weight: 680;
}

.ledger-balance {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  color: var(--color-ink-muted);
  font-size: 10px;
}

.ledger-balance strong {
  color: var(--color-ink);
  font-size: 22px;
  font-variant-numeric: tabular-nums;
}

.ledger-table-wrap {
  overflow-x: auto;
  margin: 14px 0;
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.ledger-table {
  width: 100%;
  min-width: 540px;
  border-collapse: collapse;
  font-size: 11.5px;
}

.ledger-table th {
  padding: 8px 10px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 650;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.ledger-table td {
  padding: 9px 10px;
  border-bottom: 1px solid #edf1ef;
}

.ledger-table tr:last-child td {
  border-bottom: 0;
}

.align-right {
  text-align: right !important;
}

.ledger-case {
  display: block;
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

@media (max-width: 1180px) {
  .balance-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 1000px) {
  .leave-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 560px) {
  .balance-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .leave-form__row {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
