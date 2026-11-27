<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getApi } from '@/api'
import type { Payslip } from '@/api/contracts'
import { usePageError } from '@/composables/usePageError'
import { formatBusinessDate, formatMonthLabel } from '@/utils/format'
import MoneyText from '@/components/MoneyText.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'

const props = defineProps<{ id: string }>()
const router = useRouter()
const loading = ref(true)
const pageError = usePageError()
const slip = ref<Payslip | null>(null)

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    slip.value = await getApi().payroll.getPayslip(Number(props.id))
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page payslip-detail-page">
    <div class="row">
      <el-button text @click="router.push('/payroll/my-payslips')">← 返回我的工资条</el-button>
    </div>

    <LoadingState v-if="loading" :rows="8" label="正在加载工资条" />
    <ErrorState v-else-if="pageError.message.value" title="无法查看工资条" :description="pageError.message.value" @retry="load" />

    <template v-else-if="slip">
      <header class="payslip-header">
        <div>
          <p class="payslip-header__eyebrow">工资条 · {{ formatMonthLabel(slip.period) }}</p>
          <h1>{{ slip.employeeName }} <span class="mono">{{ slip.employeeNo }}</span></h1>
          <p class="payslip-header__meta">
            {{ slip.orgName }} · 发放日 {{ formatBusinessDate(slip.payDate) }}
          </p>
        </div>
        <div class="payslip-header__net">
          <span>实发工资</span>
          <MoneyText :cents="slip.netCents" size="lg" tone="strong" />
          <span class="plain-chip">{{ slip.state === 'PAID' ? '已发放' : slip.state === 'PUBLISHED' ? '已发布' : '更正批次' }}</span>
        </div>
      </header>

      <el-alert
        v-if="slip.note"
        :title="slip.note"
        type="warning"
        :closable="false"
        show-icon
      />

      <div class="payslip-detail-grid">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">应发项目</h2>
              <p class="panel__subtitle">金额由整数分格式化，不做浮点换算。</p>
            </div>
          </div>
          <ul class="money-lines">
            <li v-for="line in slip.earningLines" :key="line.label">
              <span>{{ line.label }}</span>
              <MoneyText :cents="line.cents" size="sm" />
            </li>
            <li class="money-lines__total">
              <span>应发合计</span>
              <MoneyText :cents="slip.grossCents" size="md" tone="strong" />
            </li>
          </ul>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">扣款项目</h2>
              <p class="panel__subtitle">个人社保、公积金与个税明细。</p>
            </div>
          </div>
          <ul class="money-lines">
            <li v-for="line in slip.deductionLines" :key="line.label">
              <span>{{ line.label }}</span>
              <MoneyText :cents="line.cents" size="sm" tone="muted" />
            </li>
            <li class="money-lines__total">
              <span>扣款合计</span>
              <MoneyText :cents="slip.grossCents - slip.netCents" size="md" />
            </li>
          </ul>
        </section>
      </div>

      <section class="panel">
        <div class="panel__header">
          <h2 class="panel__title">核对摘要</h2>
        </div>
        <div class="panel__body">
          <dl class="facts payslip-facts">
            <dt>工资批次</dt>
            <dd class="mono">#{{ slip.runId }}</dd>
            <dt>工资期间</dt>
            <dd>{{ formatMonthLabel(slip.period) }}</dd>
            <dt>发放日期</dt>
            <dd>{{ formatBusinessDate(slip.payDate) }}</dd>
            <dt>应发合计</dt>
            <dd><MoneyText :cents="slip.grossCents" size="sm" /></dd>
            <dt>实发合计</dt>
            <dd><MoneyText :cents="slip.netCents" size="sm" tone="strong" /></dd>
            <dt>雇主成本</dt>
            <dd><MoneyText :cents="slip.employerCostCents" size="sm" tone="muted" /></dd>
          </dl>
          <p class="small muted payslip-note">
            如对工资条有异议，请在发放后 30 天内向人力资源部提交复核申请。复核不会直接修改已发布工资条；确认差异后将通过更正批次处理。
          </p>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.payslip-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  padding: 20px 22px;
  color: #edf6f3;
  background:
    radial-gradient(120% 160% at 100% 0%, rgb(236 199 112 / 16%), transparent 55%),
    linear-gradient(115deg, #104a57, #0c3a45);
  border-radius: var(--radius-md);
}

.payslip-header__eyebrow {
  color: #a8c6c2;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
}

.payslip-header h1 {
  margin: 5px 0 4px;
  font-size: 24px;
  font-weight: 680;
}

.payslip-header h1 span {
  color: #a9c5c1;
  font-size: 12px;
  font-weight: 500;
}

.payslip-header__meta {
  color: #b5ceca;
  font-size: 11.5px;
}

.payslip-header__net {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 5px;
}

.payslip-header__net > span:first-child {
  color: #b5ceca;
  font-size: 10.5px;
}

.payslip-header__net :deep(.money-text) {
  color: #f4dc9e;
}

.payslip-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.money-lines {
  margin: 0;
  padding: 0;
  list-style: none;
}

.money-lines li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 18px;
  font-size: 12px;
  border-bottom: 1px solid #edf1ef;
}

.money-lines__total {
  color: var(--color-ink);
  font-weight: 650;
  background: var(--color-canvas);
}

.money-lines__total:last-child {
  border-bottom: 0;
}

.payslip-facts {
  max-width: 480px;
}

.payslip-note {
  margin-top: 18px;
  padding-top: 12px;
  border-top: 1px solid var(--color-line);
  line-height: 1.7;
}

@media (max-width: 800px) {
  .payslip-detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .payslip-header {
    flex-direction: column;
  }

  .payslip-header__net {
    align-items: flex-start;
  }
}
</style>
