<script setup lang="ts">
import { computed } from 'vue'
import MoneyText from '@/components/MoneyText.vue'
import {
  payrollEmployeeStatusLabel,
  type PayrollEmployee,
  type PayrollRunSummary,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    run: PayrollRunSummary
    employee: PayrollEmployee | null
    loading?: boolean
    error?: string
  }>(),
  { loading: false, error: '' },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

type Line = { key: string; label: string; hint: string; cents: number; negative: boolean }

/**
 * The API exposes employee totals but no run-level payslip-lines endpoint, so
 * the drawer renders only the amounts that actually came back. It never
 * invents earning or deduction categories to fill a plausible-looking slip.
 */
const lines = computed<Line[]>(() => {
  const employee = props.employee
  if (employee === null) return []
  const rows: Line[] = []
  const push = (key: string, label: string, hint: string, cents: number | undefined, negative = false): void => {
    if (cents === undefined) return
    rows.push({ key, label, hint, cents, negative })
  }

  push('gross', '应发合计', '该员工本期全部计薪项目的合计（整数分）', employee.grossCents)
  push('employee_si', '个人社保', '从应发中代扣的个人社保部分', employee.employeeSiCents, true)
  push('employee_hf', '个人公积金', '从应发中代扣的个人公积金部分', employee.employeeHfCents, true)
  push('tax', '个人所得税', '本期代扣个人所得税', employee.taxCents, true)
  push('deductions', '扣款合计', '服务端记录的扣款总额', employee.deductionsCents, true)
  push('net', '实发合计', '应发减扣款后的实发金额', employee.netCents)
  push('employer_cost', '单位成本', '含单位社保公积金等雇主成本', employee.employerCostCents)

  return rows
})

const hasAmounts = computed(() => lines.value.length > 0)
const netCents = computed(() => props.employee?.netCents)

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <el-drawer
    :model-value="modelValue"
    title="工资条明细"
    size="min(560px, 96vw)"
    data-testid="payslip-drawer"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div v-if="loading" class="payslip-drawer__state" role="status">正在加载该员工工资条…</div>

    <div v-else-if="error !== ''" class="payslip-drawer__state payslip-drawer__state--error" role="alert">
      {{ error }}
    </div>

    <template v-else-if="employee !== null">
      <header class="payslip-drawer__head">
        <div>
          <p class="payslip-drawer__eyebrow">工资条</p>
          <h2 class="payslip-drawer__title">{{ run.period }} · 员工 #{{ employee.employmentId }}</h2>
        </div>
        <span class="payslip-drawer__status">{{ payrollEmployeeStatusLabel(employee.status) }}</span>
      </header>

      <p v-if="employee.failureReason !== null" class="payslip-drawer__warning" role="alert">
        计算失败原因：{{ employee.failureReason }}
      </p>

      <dl class="payslip-drawer__meta">
        <div>
          <dt>期间</dt>
          <dd>{{ run.period }}</dd>
        </div>
        <div>
          <dt>发放日</dt>
          <dd>{{ run.payDate }}</dd>
        </div>
        <div>
          <dt>批次状态</dt>
          <dd>{{ run.status }}</dd>
        </div>
      </dl>

      <section v-if="hasAmounts" class="payslip-drawer__lines" aria-label="工资条金额明细">
        <h3>金额明细</h3>
        <table>
          <thead>
            <tr>
              <th scope="col">项目</th>
              <th scope="col">说明</th>
              <th scope="col" class="is-numeric">金额</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in lines" :key="line.key" :data-testid="`payslip-line-${line.key}`">
              <th scope="row">{{ line.label }}</th>
              <td>{{ line.hint }}</td>
              <td class="is-numeric">
                <MoneyText
                  :cents="line.negative ? -Math.abs(line.cents) : line.cents"
                  size="sm"
                  :tone="line.key === 'net' ? 'strong' : 'default'"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-else class="payslip-drawer__masked" data-testid="payslip-masked">
        <h3>金额已按字段策略隐藏</h3>
        <p>
          当前账号可以查看该批次与员工状态，但不具备工资条金额字段策略。服务端没有返回任何金额键，
          页面不会用 0 或估算值代替。
        </p>
      </section>

      <p class="payslip-drawer__note">
        本抽屉只展示该批次员工记录的已返回金额，不构成工资条凭据；正式工资条以发布后的服务端记录为准。
      </p>

      <footer class="payslip-drawer__footer">
        <span>实发合计（已返回部分）：<MoneyText :cents="netCents ?? null" size="sm" tone="strong" /></span>
        <el-button @click="close">关闭</el-button>
      </footer>
    </template>

    <div v-else class="payslip-drawer__state">请选择一名员工查看工资条。</div>
  </el-drawer>
</template>

<style scoped>
.payslip-drawer__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.payslip-drawer__eyebrow {
  margin: 0;
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.payslip-drawer__title {
  margin: 4px 0 0;
  font-size: 17px;
}

.payslip-drawer__status {
  padding: 2px 10px;
  border-radius: 999px;
  background: #eef2f7;
  color: #475569;
  font-size: 11.5px;
  font-weight: 600;
}

.payslip-drawer__warning {
  margin: 12px 0 0;
  padding: 9px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  color: #b91c1c;
  font-size: 12px;
}

.payslip-drawer__meta {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin: 16px 0;
}

.payslip-drawer__meta dt {
  color: #64748b;
  font-size: 11px;
}

.payslip-drawer__meta dd {
  margin: 2px 0 0;
  font-size: 13px;
  font-weight: 600;
}

.payslip-drawer__lines h3,
.payslip-drawer__masked h3 {
  margin: 0 0 8px;
  font-size: 13px;
}

.payslip-drawer__lines table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.payslip-drawer__lines th,
.payslip-drawer__lines td {
  padding: 8px 10px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
}

.payslip-drawer__lines thead th {
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
}

.payslip-drawer__lines tbody th {
  font-weight: 600;
}

.payslip-drawer__lines td {
  color: #475569;
}

.payslip-drawer__lines .is-numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.payslip-drawer__masked {
  padding: 14px 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payslip-drawer__masked p {
  margin: 0;
  color: #475569;
  font-size: 12px;
  line-height: 1.6;
}

.payslip-drawer__note {
  margin: 16px 0 0;
  color: #64748b;
  font-size: 11px;
  line-height: 1.6;
}

.payslip-drawer__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid #e2e8f0;
  font-size: 12px;
}

.payslip-drawer__state {
  padding: 20px;
  color: #475569;
  font-size: 12.5px;
}

.payslip-drawer__state--error {
  color: #b91c1c;
}
</style>
