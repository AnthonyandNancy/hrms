<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import MoneyText from '@/components/MoneyText.vue'
import type { TimePayrollPageDefinition } from '../types'
import { hasAnyMoney, moneyField, newIdempotencyKey, statusTone, textValue, todayIsoDate } from '../panelUtils'

const props = defineProps<{
  definition: TimePayrollPageDefinition
  rows: Record<string, unknown>[]
  selectedRunId: number | null
  related?: Record<string, unknown>
}>()

const emit = defineEmits<{ refresh: []; 'action-success': [message: string] }>()

const session = useSessionStore()
const actionError = usePageError()
const submitting = ref(false)
const actionMessage = ref('')

const MONEY_KEYS = ['gross_cents', 'deductions_cents', 'net_cents', 'employer_cost_cents', 'base_salary_cents', 'amount_cents', 'value_int']
const RUN_ACTIONS = [
  { action: 'lock-inputs', label: '锁定输入', states: ['DRAFT'], testId: 'lock-inputs', capability: 'payroll.run.prepare' },
  { action: 'calculate', label: '计算', states: ['INPUT_LOCKED'], testId: 'calculate', capability: 'payroll.run.prepare' },
  { action: 'review', label: '复核', states: ['CALCULATED'], testId: 'review', capability: 'payroll.run.review' },
  { action: 'approve', label: '批准', states: ['REVIEWED'], testId: 'approve', capability: 'payroll.run.approve' },
  { action: 'publish', label: '发布', states: ['APPROVED'], testId: 'publish', capability: 'payroll.run.publish' },
  { action: 'mark-paid', label: '登记发放', states: ['PUBLISHED'], testId: 'mark-paid', capability: 'payroll.run.close' },
  { action: 'close', label: '关账', states: ['PAID'], testId: 'close', capability: 'payroll.run.close' },
] as const

const showRunTable = computed(() =>
  ['payroll-overview', 'payroll-batches', 'payroll-reviews', 'payroll-disbursement', 'payroll-reports'].includes(
    props.definition.panel,
  ),
)

const compensationForm = reactive({
  employment_id: '',
  effective_from: todayIsoDate(),
  base_salary_cents: '',
  currency: 'CNY',
  salary_scheme_code: '',
})

const adjustmentForm = reactive({
  employment_id: '',
  case_id: '',
  effective_from: todayIsoDate(),
  base_salary_cents: '',
  currency: 'CNY',
  reason: '',
})

const canManageCompensation = computed(() => session.can('payroll.compensation.manage'))
const canManageAdjustment = computed(() => session.can('payroll.salary-adjustment.manage'))

const hasMoneyAuthorization = computed(() =>
  props.rows.some((row) => hasAnyMoney(row, MONEY_KEYS)),
)

function actionsFor(row: Record<string, unknown>) {
  const status = textValue(row.status)
  return RUN_ACTIONS.filter(
    (entry) =>
      entry.states.some((candidate) => candidate === status) && session.can(entry.capability),
  )
}

function actionDisabled(row: Record<string, unknown>, action: string): boolean {
  if (submitting.value) return true
  if (action === 'lock-inputs' && textValue(row.status) !== 'DRAFT') return true
  return false
}

async function runAction(row: Record<string, unknown>, action: string): Promise<void> {
  const id = typeof row.id === 'number' && Number.isSafeInteger(row.id) ? row.id : null
  if (id === null) return
  actionError.clear()
  actionMessage.value = ''
  submitting.value = true
  try {
    await resourcePost<Record<string, unknown>>(
      `/payroll/runs/${id}/${action}`,
      {},
      { idempotencyKey: newIdempotencyKey(`payroll-${action}`) },
    )
    actionMessage.value = '工资批次操作已提交'
    emit('action-success', actionMessage.value)
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

function money(record: Record<string, unknown>, key: string): number | null {
  return moneyField(record, key)
}

function positiveInteger(raw: string): number | null {
  const trimmed = raw.trim()
  if (!/^[1-9]\d*$/.test(trimmed)) return null
  const value = Number(trimmed)
  return Number.isSafeInteger(value) ? value : null
}

function minorUnits(raw: string): number | null {
  const trimmed = raw.trim()
  if (!/^(0|[1-9]\d*)$/.test(trimmed)) return null
  const value = Number(trimmed)
  return Number.isSafeInteger(value) ? value : null
}

function currencyCode(raw: string): string | null {
  const code = raw.trim().toUpperCase()
  return /^[A-Z]{3}$/.test(code) ? code : null
}

async function submitCompensation(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''

  const employmentId = positiveInteger(compensationForm.employment_id)
  const cents = minorUnits(compensationForm.base_salary_cents)
  const currency = currencyCode(compensationForm.currency)
  const effectiveFrom = compensationForm.effective_from.trim()
  const scheme = compensationForm.salary_scheme_code.trim()

  if (employmentId === null) {
    actionError.reject(new Error('员工 ID 必须是正整数。'))
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom)) {
    actionError.reject(new Error('请选择有效的生效日期。'))
    return
  }
  if (cents === null) {
    actionError.reject(new Error('基本工资必须是非负整数分，不能输入小数或货币符号。'))
    return
  }
  if (currency === null) {
    actionError.reject(new Error('币种必须是三位大写字母代码。'))
    return
  }

  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/payroll/employee-plans',
      {
        employment_id: employmentId,
        effective_from: effectiveFrom,
        base_salary_cents: cents,
        currency,
        ...(scheme === '' ? {} : { salary_scheme_code: scheme }),
      },
      { idempotencyKey: newIdempotencyKey('payroll-compensation') },
    )
    actionMessage.value = `薪酬档案版本已新增：${textValue(result.id, '已受理')}`
    compensationForm.base_salary_cents = ''
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

async function submitAdjustment(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''

  const employmentId = positiveInteger(adjustmentForm.employment_id)
  const caseId = positiveInteger(adjustmentForm.case_id)
  const cents = minorUnits(adjustmentForm.base_salary_cents)
  const currency = currencyCode(adjustmentForm.currency)
  const effectiveFrom = adjustmentForm.effective_from.trim()
  const reason = adjustmentForm.reason.trim()

  if (employmentId === null) {
    actionError.reject(new Error('员工 ID 必须是正整数。'))
    return
  }
  if (caseId === null) {
    actionError.reject(new Error('审批案例 ID 必须是正整数。'))
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom)) {
    actionError.reject(new Error('请选择有效的生效日期。'))
    return
  }
  if (cents === null) {
    actionError.reject(new Error('调薪金额必须是非负整数分，不能输入小数或货币符号。'))
    return
  }
  if (currency === null) {
    actionError.reject(new Error('币种必须是三位大写字母代码。'))
    return
  }
  if (reason.length < 5 || reason.length > 500) {
    actionError.reject(new Error('调薪原因需要 5 到 500 个字符。'))
    return
  }

  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/payroll/adjustments',
      {
        employment_id: employmentId,
        case_id: caseId,
        effective_from: effectiveFrom,
        base_salary_cents: cents,
        currency,
        reason,
      },
      { idempotencyKey: newIdempotencyKey('payroll-adjustment') },
    )
    actionMessage.value = `调薪记录已应用：${textValue(result.id, '已受理')}`
    adjustmentForm.base_salary_cents = ''
    adjustmentForm.reason = ''
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="timepay-panel" :data-testid="definition.testId">
    <section v-if="showRunTable" class="panel">
      <h2>{{ definition.title }}</h2>
      <table v-if="rows.length > 0" class="timepay-table">
        <caption class="sr-only">{{ definition.title }}批次列表</caption>
        <thead>
          <tr>
            <th scope="col">批次</th><th scope="col">期间</th><th scope="col">类型</th><th scope="col">状态</th>
            <th scope="col">发薪日</th><th scope="col" class="align-right">应发</th><th scope="col" class="align-right">实发</th><th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td class="mono">RUN-{{ textValue(row.id) }}</td>
            <td>{{ textValue(row.period) }}</td>
            <td>{{ textValue(row.run_type) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
            <td>{{ textValue(row.pay_date) }}</td>
            <td class="align-right">
              <MoneyText v-if="money(row, 'gross_cents') !== null" :cents="money(row, 'gross_cents')" size="sm" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td class="align-right">
              <MoneyText v-if="money(row, 'net_cents') !== null" :cents="money(row, 'net_cents')" size="sm" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td>
              <el-button
                v-for="entry in actionsFor(row)"
                :key="entry.action"
                text
                size="small"
                :disabled="actionDisabled(row, entry.action)"
                :loading="submitting"
                :data-testid="`payroll-action-${entry.testId}-${textValue(row.id)}`"
                @click="runAction(row, entry.action)"
              >
                {{ entry.label }}
              </el-button>
              <span v-if="actionsFor(row).length === 0" class="small muted">无可用动作</span>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!hasMoneyAuthorization" class="timepay-pii-note">
        当前账号仅能查看批次元数据。服务端未返回工资、个税或银行卡字段；页面不会合成任何金额。
      </p>
      <p class="small muted">金额由整数分传输，统一通过 MoneyText 渲染；已发布批次应通过更正批次处理。</p>
    </section>

    <section v-else-if="definition.panel === 'payroll-components'" class="panel">
      <h2>薪资项目目录</h2>
      <table class="timepay-table">
        <caption class="sr-only">薪资项目目录</caption>
        <thead><tr><th scope="col">编码</th><th scope="col">名称</th><th scope="col">类型</th><th scope="col">计算</th><th scope="col">计税</th><th scope="col">社保</th><th scope="col">状态</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td class="mono">{{ textValue(row.code) }}</td>
            <td>{{ textValue(row.name) }}</td>
            <td>{{ textValue(row.type) }}</td>
            <td>{{ textValue(row.calculation) }}</td>
            <td>{{ row.taxable === true ? '是' : '否' }}</td>
            <td>{{ row.insurable === true ? '是' : '否' }}</td>
            <td>{{ textValue(row.status) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else-if="definition.panel === 'payroll-plans'" class="panel">
      <h2>薪酬方案版本</h2>
      <table class="timepay-table">
        <caption class="sr-only">薪酬方案版本</caption>
        <thead><tr><th scope="col">编码</th><th scope="col">名称</th><th scope="col">版本</th><th scope="col">币种</th><th scope="col">取整</th><th scope="col">基数</th><th scope="col">状态</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="`${textValue(row.code)}-${textValue(row.version)}`">
            <td class="mono">{{ textValue(row.code) }}</td>
            <td>{{ textValue(row.name) }}</td>
            <td>{{ textValue(row.version) }}</td>
            <td>{{ textValue(row.currency) }}</td>
            <td>{{ textValue(row.rounding_mode) }}</td>
            <td>
              <MoneyText v-if="money(row, 'base_salary_cents') !== null" :cents="money(row, 'base_salary_cents')" size="sm" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td>{{ textValue(row.status) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else-if="definition.panel === 'payroll-statutory'" class="panel">
      <h2>社保公积金参数版本</h2>
      <table class="timepay-table">
        <caption class="sr-only">社保公积金参数版本</caption>
        <thead><tr><th scope="col">参数集</th><th scope="col">代码</th><th scope="col">数值</th><th scope="col">生效日</th><th scope="col">失效日</th><th scope="col">版本</th><th scope="col">证据引用</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td>{{ textValue(row.parameter_set) }}</td>
            <td class="mono">{{ textValue(row.code) }}</td>
            <td>
              <MoneyText v-if="money(row, 'value_int') !== null" :cents="money(row, 'value_int')" size="sm" />
              <span v-else>{{ textValue(row.value_decimal) }}</span>
            </td>
            <td>{{ textValue(row.effective_from) }}</td>
            <td>{{ textValue(row.effective_to) }}</td>
            <td>{{ textValue(row.version) }}</td>
            <td class="small">{{ textValue(row.evidence_ref, '未提供') }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else-if="definition.panel === 'payroll-inputs'" class="panel">
      <h2>不可变计薪输入快照</h2>
      <p class="small muted">
        当前批次：{{ selectedRunId === null ? '—' : `RUN-${selectedRunId}` }}。金额键由服务端按 P3 字段权限省略或返回。
      </p>
      <table class="timepay-table">
        <caption class="sr-only">计薪输入快照</caption>
        <thead><tr><th scope="col">员工</th><th scope="col">快照类型</th><th scope="col">版本</th><th scope="col">来源哈希</th><th scope="col">创建时间</th><th scope="col">金额</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td>{{ textValue(row.employment_id) }}</td>
            <td>{{ textValue(row.snapshot_type) }}</td>
            <td>{{ textValue(row.snapshot_version) }}</td>
            <td class="mono small">{{ textValue(row.source_hash) }}</td>
            <td>{{ textValue(row.created_at) }}</td>
            <td><span class="small muted">payload 已按字段权限投影</span></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else-if="definition.panel === 'payroll-employee-plans'" class="panel">
      <h2>员工薪资档案</h2>
      <p class="small muted">
        每个生效日期是一个不可覆盖的版本；金额由服务端按 P3 字段权限返回。服务端省略金额键时，页面不会补零或推算。
      </p>
      <table v-if="rows.length > 0" class="timepay-table">
        <caption class="sr-only">员工薪资档案版本</caption>
        <thead>
          <tr>
            <th scope="col">版本</th><th scope="col">员工</th><th scope="col">生效日</th>
            <th scope="col">币种</th><th scope="col">薪资方案</th><th scope="col" class="align-right">基本工资</th><th scope="col">创建时间</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)" :data-testid="`compensation-row-${textValue(row.id)}`">
            <td class="mono">#{{ textValue(row.id) }}</td>
            <td>{{ textValue(row.employment_id) }}</td>
            <td>{{ textValue(row.effective_from) }}</td>
            <td>{{ textValue(row.currency) }}</td>
            <td>{{ textValue(row.salary_scheme_code) }}</td>
            <td class="align-right">
              <MoneyText v-if="money(row, 'base_salary_cents') !== null" :cents="money(row, 'base_salary_cents')" size="sm" tone="strong" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td class="small">{{ textValue(row.created_at) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">当前筛选下没有薪酬档案版本。</p>

      <form v-if="canManageCompensation" class="timepay-form" data-testid="compensation-form" @submit.prevent="submitCompensation">
        <h2>新增档案版本</h2>
        <p class="small muted">同一员工同一生效日不能重复；历史版本保持只读。</p>
        <div class="timepay-form__grid">
          <label>员工 ID
            <input v-model="compensationForm.employment_id" data-testid="compensation-employment-id" inputmode="numeric" />
          </label>
          <label>生效日
            <input v-model="compensationForm.effective_from" data-testid="compensation-effective-from" type="date" />
          </label>
          <label>基本工资（整数分）
            <input v-model="compensationForm.base_salary_cents" data-testid="compensation-base-salary-cents" inputmode="numeric" />
          </label>
          <label>币种
            <input v-model="compensationForm.currency" data-testid="compensation-currency" maxlength="3" />
          </label>
          <label>薪资方案代码（可选）
            <input v-model="compensationForm.salary_scheme_code" data-testid="compensation-scheme-code" maxlength="64" />
          </label>
        </div>
        <el-button type="primary" native-type="submit" :loading="submitting" data-testid="compensation-submit">新增版本</el-button>
      </form>
      <p v-else class="small muted">当前账号没有 `payroll.compensation.manage`，仅可查看授权范围内的档案。</p>
    </section>

    <section v-else-if="definition.panel === 'payroll-adjustments'" class="panel">
      <h2>调薪记录</h2>
      <p class="small muted">
        仅展示已关联审批案例的调薪记录；服务端会在审批未通过、案例重复应用或金额不一致时拒绝写入。
      </p>
      <table v-if="rows.length > 0" class="timepay-table">
        <caption class="sr-only">调薪记录</caption>
        <thead>
          <tr>
            <th scope="col">记录</th><th scope="col">员工</th><th scope="col">生效日</th><th scope="col">审批案例</th>
            <th scope="col">原因</th><th scope="col" class="align-right">调薪后基本工资</th><th scope="col">创建时间</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)" :data-testid="`adjustment-row-${textValue(row.id)}`">
            <td class="mono">#{{ textValue(row.id) }}</td>
            <td>{{ textValue(row.employment_id) }}</td>
            <td>{{ textValue(row.effective_from) }}</td>
            <td class="mono">CASE-{{ textValue(row.case_id) }}</td>
            <td>{{ textValue(row.reason) }}</td>
            <td class="align-right">
              <MoneyText v-if="money(row, 'base_salary_cents') !== null" :cents="money(row, 'base_salary_cents')" size="sm" tone="strong" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td class="small">{{ textValue(row.created_at) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">当前筛选下没有调薪记录。</p>

      <form v-if="canManageAdjustment" class="timepay-form" data-testid="adjustment-form" @submit.prevent="submitAdjustment">
        <h2>应用已审批调薪</h2>
        <p class="small muted">必须引用状态为 APPROVED 的薪酬变更案例；同一案例只能应用一次。</p>
        <div class="timepay-form__grid">
          <label>员工 ID
            <input v-model="adjustmentForm.employment_id" data-testid="adjustment-employment-id" inputmode="numeric" />
          </label>
          <label>审批案例 ID
            <input v-model="adjustmentForm.case_id" data-testid="adjustment-case-id" inputmode="numeric" />
          </label>
          <label>生效日
            <input v-model="adjustmentForm.effective_from" data-testid="adjustment-effective-from" type="date" />
          </label>
          <label>调薪后基本工资（整数分）
            <input v-model="adjustmentForm.base_salary_cents" data-testid="adjustment-base-salary-cents" inputmode="numeric" />
          </label>
          <label>币种
            <input v-model="adjustmentForm.currency" data-testid="adjustment-currency" maxlength="3" />
          </label>
        </div>
        <label class="timepay-form__wide">调薪原因
          <textarea v-model="adjustmentForm.reason" maxlength="500" data-testid="adjustment-reason" rows="3" />
        </label>
        <el-button type="primary" native-type="submit" :loading="submitting" data-testid="adjustment-submit">应用调薪</el-button>
      </form>
      <p v-else class="small muted">当前账号没有 `payroll.salary-adjustment.manage`，仅可查看授权范围内的调薪记录。</p>
    </section>

    <section v-else-if="definition.panel === 'payroll-payslips'" class="panel">
      <h2>本人已发布工资条</h2>
      <table class="timepay-table">
        <caption class="sr-only">已发布工资条</caption>
        <thead><tr><th scope="col">工资条</th><th scope="col">批次</th><th scope="col">版本</th><th scope="col">应发</th><th scope="col">扣款</th><th scope="col">实发</th><th scope="col">雇主成本</th><th scope="col">发布时间</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td class="mono">#{{ textValue(row.id) }}</td>
            <td>RUN-{{ textValue(row.run_id) }}</td>
            <td>{{ textValue(row.version) }}</td>
            <td>
              <MoneyText v-if="money(row, 'gross_cents') !== null" :cents="money(row, 'gross_cents')" size="sm" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td>
              <MoneyText v-if="money(row, 'deductions_cents') !== null" :cents="money(row, 'deductions_cents')" size="sm" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td>
              <MoneyText v-if="money(row, 'net_cents') !== null" :cents="money(row, 'net_cents')" size="sm" tone="strong" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td>
              <MoneyText v-if="money(row, 'employer_cost_cents') !== null" :cents="money(row, 'employer_cost_cents')" size="sm" tone="muted" />
              <span v-else class="small muted">已按字段权限隐藏</span>
            </td>
            <td class="small">{{ textValue(row.published_at) }}</td>
          </tr>
        </tbody>
      </table>
      <p class="small muted">服务端省略的 P3 金额不会由页面补零或推算。</p>
    </section>

    <section v-else class="panel">
      <h2>{{ definition.title }}</h2>
      <p class="small muted">本页使用上方真实批次或目录数据；当前没有更多后端投影。</p>
    </section>

    <p v-if="actionMessage" class="timepay-action-success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError.message.value" class="timepay-action-error" role="alert">
      {{ actionError.message.value }}
      <span v-if="actionError.requestId.value">（请求编号：{{ actionError.requestId.value }}）</span>
    </p>
  </div>
</template>
