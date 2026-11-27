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
import EmptyState from '@/components/EmptyState.vue'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'

const router = useRouter()
const loading = ref(true)
const pageError = usePageError()
const payslips = ref<Payslip[]>([])

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    payslips.value = await getApi().payroll.listPayslips()
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page payslips-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">薪酬 · 员工自助</p>
        <h1 class="page-header__title">我的工资条</h1>
        <p class="page-header__description">
          仅显示你本人的工资条。已发布工资条不可修改；更正通过新批次生成，并保留对原始批次的引用。
        </p>
      </div>
      <div class="page-header__actions">
        <el-button @click="router.push('/payroll')">工资批次</el-button>
      </div>
    </header>

    <NoActiveEmploymentState
      v-if="pageError.noActiveEmployment.value"
      :request-id="pageError.requestId.value"
      :links="pageError.safeLinks.value"
    />
    <ErrorState
      v-else-if="pageError.message.value"
      description="工资条暂时无法加载。"
      :code="pageError.code.value"
      :request-id="pageError.requestId.value"
      @retry="load"
    />
    <LoadingState v-else-if="loading" :rows="5" label="正在加载工资条" />
    <EmptyState
      v-else-if="payslips.length === 0"
      title="还没有已发布工资条"
      description="当薪酬专员发布工资批次后，你会在发布日看到本人工资条。"
    />

    <section v-else class="payslip-grid">
      <article v-for="slip in payslips" :key="slip.id" class="payslip-card">
        <div class="payslip-card__head">
          <div>
            <p class="payslip-card__period">{{ formatMonthLabel(slip.period) }}</p>
            <p class="payslip-card__meta">
              {{ slip.state === 'CORRECTION' ? '更正批次' : `发放日 ${formatBusinessDate(slip.payDate)}` }}
            </p>
          </div>
          <span class="plain-chip">{{ slip.state === 'PAID' ? '已发放' : slip.state === 'PUBLISHED' ? '已发布' : '更正' }}</span>
        </div>
        <div class="payslip-card__net">
          <span>实发工资</span>
          <MoneyText :cents="slip.netCents" size="lg" tone="strong" />
        </div>
        <dl class="payslip-card__facts">
          <div><dt>应发</dt><dd><MoneyText :cents="slip.grossCents" size="sm" /></dd></div>
          <div><dt>扣款</dt><dd><MoneyText :cents="slip.grossCents - slip.netCents" size="sm" tone="muted" /></dd></div>
          <div><dt>雇主成本</dt><dd><MoneyText :cents="slip.employerCostCents" size="sm" tone="muted" /></dd></div>
        </dl>
        <p v-if="slip.note" class="payslip-card__note">{{ slip.note }}</p>
        <div class="payslip-card__footer">
          <span class="small muted">工资条 #{{ slip.id }}</span>
          <el-button type="primary" plain size="small" @click="router.push('/payroll')">
            查看明细
          </el-button>
        </div>
      </article>
    </section>
  </div>
</template>

<style scoped>
.payslip-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.payslip-card {
  display: flex;
  flex-direction: column;
  padding: 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.payslip-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.payslip-card__period {
  font-size: 15px;
  font-weight: 680;
}

.payslip-card__meta {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.payslip-card__net {
  display: flex;
  flex-direction: column;
  gap: 3px;
  margin: 14px 0 10px;
  padding: 11px;
  background: var(--color-canvas);
  border-radius: 6px;
}

.payslip-card__net > span {
  color: var(--color-ink-muted);
  font-size: 10px;
}

.payslip-card__facts {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin: 0;
}

.payslip-card__facts div {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.payslip-card__facts dt {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.payslip-card__facts dd {
  margin: 0;
}

.payslip-card__note {
  margin-top: 10px;
  padding: 7px 9px;
  color: var(--color-warning);
  font-size: 10.5px;
  background: var(--color-warning-soft);
  border-radius: 5px;
}

.payslip-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: auto;
  padding-top: 12px;
}

@media (max-width: 1050px) {
  .payslip-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 680px) {
  .payslip-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
