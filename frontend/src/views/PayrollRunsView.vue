<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getApi } from '@/api'
import type { PayrollRunSummary } from '@/api/contracts'
import { usePageError } from '@/composables/usePageError'
import { formatBusinessDate, formatDateTimeInTimezone, formatMonthLabel } from '@/utils/format'
import { PAYROLL_STATE } from '@/utils/status'
import MoneyText from '@/components/MoneyText.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'

const router = useRouter()
const loading = ref(true)
const pageError = usePageError()
const runs = ref<PayrollRunSummary[]>([])

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    runs.value = await getApi().payroll.listRuns()
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
}

function openRun(id: number): void {
  void router.push(`/payroll/runs/${id}`)
}

onMounted(load)
</script>

<template>
  <div class="page payroll-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">薪酬</p>
        <h1 class="page-header__title">工资批次</h1>
        <p class="page-header__description">
          工资状态按 DRAFT → INPUT_LOCKED → CALCULATED → REVIEWED → APPROVED → PUBLISHED → PAID → CLOSED
          推进。已发布批次不可修改，更正必须新建批次。
        </p>
      </div>
      <div class="page-header__actions">
        <el-button @click="router.push('/payroll/my-payslips')">我的工资条</el-button>
      </div>
    </header>

    <ErrorState v-if="pageError.message.value" description="工资批次暂时无法加载。" :code="pageError.code.value" @retry="load" />
    <LoadingState v-else-if="loading" :rows="6" label="正在加载工资批次" />
    <EmptyState
      v-else-if="runs.length === 0"
      title="还没有工资批次"
      description="薪酬专员创建批次后，这里会展示输入锁定、计算、复核、审批与发布状态。"
    />
    <template v-else>
      <section class="payroll-state-flow" aria-label="工资批次状态流程">
        <span
          v-for="(state, index) in ['DRAFT', 'INPUT_LOCKED', 'CALCULATED', 'REVIEWED', 'APPROVED', 'PUBLISHED', 'PAID', 'CLOSED']"
          :key="state"
          class="payroll-state-flow__step"
        >
          <span class="payroll-state-flow__index">{{ index + 1 }}</span>
          {{ PAYROLL_STATE[state as keyof typeof PAYROLL_STATE].label }}
        </span>
      </section>

      <section class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">批次列表</h2>
            <p class="panel__subtitle">薪酬成本按法人隔离；输入来自合同快照，不直接读取考勤或请假表。</p>
          </div>
          <span class="plain-chip">金额单位：分</span>
        </div>
        <div class="payroll-table-wrap">
          <table class="payroll-table">
            <caption class="visually-hidden">工资批次列表</caption>
            <thead>
              <tr>
                <th scope="col">批次</th>
                <th scope="col">期间</th>
                <th scope="col">状态</th>
                <th scope="col" class="align-right">人数</th>
                <th scope="col" class="align-right">应发合计</th>
                <th scope="col" class="align-right">实发合计</th>
                <th scope="col">发薪日</th>
                <th scope="col">最后更新</th>
                <th scope="col"><span class="visually-hidden">操作</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="run in runs" :key="run.id" class="payroll-row" @click="openRun(run.id)">
                <td>
                  <button type="button" class="run-link" @click.stop="openRun(run.id)">
                    <span class="mono">{{ run.runNo }}</span>
                    <span v-if="run.isCorrection" class="plain-chip">更正批次</span>
                  </button>
                </td>
                <td class="tabular">{{ formatMonthLabel(run.period) }}</td>
                <td>
                  <span class="payroll-state" :class="`payroll-state--${PAYROLL_STATE[run.state].tone}`">
                    <span aria-hidden="true">{{ PAYROLL_STATE[run.state].symbol }}</span>
                    {{ PAYROLL_STATE[run.state].label }}
                  </span>
                </td>
                <td class="align-right tabular">{{ run.employeeCount }}</td>
                <td class="align-right"><MoneyText :cents="run.grossCents" size="sm" /></td>
                <td class="align-right"><MoneyText :cents="run.netCents" size="sm" /></td>
                <td>{{ formatBusinessDate(run.payDate) }}</td>
                <td class="small muted">{{ formatDateTimeInTimezone(run.updatedAt) }}</td>
                <td>
                  <el-button text size="small" @click.stop="openRun(run.id)">打开</el-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="payroll-note-grid">
        <div class="payroll-note">
          <p class="payroll-note__title">制单 / 复核 / 发布分离</p>
          <p>同一人不能完成从计算到发布的全部步骤；后端按能力与职责分离规则强制校验。</p>
        </div>
        <div class="payroll-note">
          <p class="payroll-note__title">已发批次只更正</p>
          <p>原批次与工资条保留；更正批次显示差额与原始批次引用，不覆盖历史发放记录。</p>
        </div>
        <div class="payroll-note">
          <p class="payroll-note__title">整数分与追溯来源</p>
          <p>金额使用整数最小单位；每一工资行可追溯到合同、考勤汇总、请假账本与规则版本。</p>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.payroll-state-flow {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  padding: 10px 12px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.payroll-state-flow__step {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  border-radius: 999px;
}

.payroll-state-flow__step:not(:last-child)::after {
  margin-left: 4px;
  color: var(--color-line-strong);
  content: "→";
}

.payroll-state-flow__index {
  display: grid;
  width: 16px;
  height: 16px;
  place-items: center;
  color: var(--color-ink-muted);
  font-size: 8.5px;
  font-weight: 700;
  background: var(--color-canvas);
  border-radius: 50%;
}

.payroll-table-wrap {
  overflow-x: auto;
}

.payroll-table {
  width: 100%;
  min-width: 1080px;
  border-collapse: collapse;
  font-size: 12px;
}

.payroll-table th {
  padding: 9px 14px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  letter-spacing: 0.03em;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.payroll-table td {
  padding: 11px 14px;
  border-bottom: 1px solid #edf1ef;
  vertical-align: middle;
}

.payroll-row {
  cursor: pointer;
}

.payroll-row:hover td {
  background: #fafcfb;
}

.run-link {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  color: var(--color-ink);
  font-size: 12px;
  font-weight: 650;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.run-link:hover {
  color: var(--color-brand);
  text-decoration: underline;
}

.run-entity {
  display: block;
  margin-top: 2px;
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.align-right {
  text-align: right !important;
}

.payroll-state {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 7px;
  color: var(--color-ink-secondary);
  font-size: 10.5px;
  font-weight: 600;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
  white-space: nowrap;
}

.payroll-state--success {
  color: var(--color-success);
  background: var(--color-success-soft);
  border-color: #b7dcc7;
}

.payroll-state--info {
  color: var(--color-info);
  background: var(--color-info-soft);
  border-color: #b3d1e2;
}

.payroll-state--warning {
  color: var(--color-warning);
  background: var(--color-warning-soft);
  border-color: #ead49f;
}

.payroll-state--neutral,
.payroll-state--muted {
  color: var(--color-ink-muted);
}

.payroll-note-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.payroll-note {
  padding: 12px 14px;
  color: var(--color-ink-secondary);
  font-size: 11.5px;
  line-height: 1.65;
  background: #fff;
  border: 1px solid var(--color-line);
  border-top: 2px solid var(--color-line-strong);
  border-radius: var(--radius-md);
}

.payroll-note__title {
  margin-bottom: 3px;
  color: var(--color-ink);
  font-size: 12px;
  font-weight: 650;
}

@media (max-width: 900px) {
  .payroll-note-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
