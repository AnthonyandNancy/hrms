<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate } from '@/utils/format'
import MoneyText from '@/components/MoneyText.vue'
import {
  PAYROLL_STATUS_LABELS,
  payrollRunTypeLabel,
  payrollStage,
  payrollStageTone,
  type PayrollRunSummary,
} from '../api'

const props = withDefaults(
  defineProps<{
    runs: PayrollRunSummary[]
    loading?: boolean
    total?: number
    page?: number
    pageSize?: number
  }>(),
  {
    loading: false,
    total: 0,
    page: 1,
    pageSize: 20,
  },
)

const emit = defineEmits<{
  open: [run: PayrollRunSummary]
  'page-change': [page: number]
}>()

const totalPages = computed(() => Math.max(1, Math.ceil(props.total / Math.max(1, props.pageSize))))

function canGoPrevious(): boolean {
  return props.page > 1 && !props.loading
}

function canGoNext(): boolean {
  return props.page < totalPages.value && !props.loading
}

function previous(): void {
  if (canGoPrevious()) emit('page-change', props.page - 1)
}

function next(): void {
  if (canGoNext()) emit('page-change', props.page + 1)
}

function moneyOrNull(value: number | undefined): number | null {
  return value === undefined ? null : value
}
</script>

<template>
  <section class="panel payroll-runs" data-testid="payroll-run-table">
    <div class="panel__header payroll-runs__header">
      <div>
        <h2 class="panel__title">工资批次</h2>
        <p class="panel__subtitle">只显示当前账号所属的批次；金额为服务端整数分，仅在渲染时格式化。</p>
      </div>
      <span class="plain-chip">共 {{ total }} 批</span>
    </div>

    <div class="payroll-runs__scroll">
      <table class="payroll-runs__table">
        <caption class="visually-hidden">工资批次列表</caption>
        <thead>
          <tr>
            <th scope="col">期间</th>
            <th scope="col">批次类型</th>
            <th scope="col">阶段</th>
            <th scope="col">明细状态</th>
            <th scope="col" class="is-numeric">人数</th>
            <th scope="col" class="is-numeric">应发合计</th>
            <th scope="col" class="is-numeric">扣款合计</th>
            <th scope="col" class="is-numeric">实发合计</th>
            <th scope="col">发放日</th>
            <th scope="col" class="is-actions">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="run in runs" :key="run.id" :data-testid="`payroll-run-${run.id}`">
            <th scope="row">
              <button type="button" class="payroll-runs__link" @click="emit('open', run)">
                {{ run.period }}
              </button>
              <span class="payroll-runs__period-note">{{ payrollRunTypeLabel(run.runType) }}</span>
            </th>
            <td>{{ payrollRunTypeLabel(run.runType) }}</td>
            <td>
              <span class="payroll-runs__stage" :data-tone="payrollStageTone(payrollStage(run.status))">
                {{ payrollStage(run.status) }}
              </span>
              <span class="payroll-runs__status">{{ PAYROLL_STATUS_LABELS[run.status] }}</span>
            </td>
            <td>
              <span class="payroll-runs__count">{{ run.employeeCount }} 人</span>
            </td>
            <td class="is-numeric">{{ run.employeeCount }}</td>
            <td class="is-numeric">
              <MoneyText v-if="run.grossCents !== undefined" :cents="moneyOrNull(run.grossCents)" size="sm" />
              <span v-else class="payroll-runs__masked">已按字段权限隐藏</span>
            </td>
            <td class="is-numeric">
              <MoneyText v-if="run.deductionsCents !== undefined" :cents="moneyOrNull(run.deductionsCents)" size="sm" />
              <span v-else class="payroll-runs__masked">已按字段权限隐藏</span>
            </td>
            <td class="is-numeric">
              <MoneyText v-if="run.netCents !== undefined" :cents="moneyOrNull(run.netCents)" size="sm" tone="strong" />
              <span v-else class="payroll-runs__masked">已按字段权限隐藏</span>
            </td>
            <td>{{ formatBusinessDate(run.payDate) }}</td>
            <td class="is-actions">
              <button type="button" class="payroll-runs__link" @click="emit('open', run)">
                打开详情
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="payroll-runs__footer">
      <span>第 {{ page }} / {{ totalPages }} 页</span>
      <div class="payroll-runs__pager">
        <button type="button" :disabled="!canGoPrevious()" @click="previous">上一页</button>
        <button type="button" :disabled="!canGoNext()" @click="next">下一页</button>
      </div>
    </footer>
  </section>
</template>

<style scoped>
.payroll-runs__header {
  align-items: flex-start;
}

.payroll-runs__scroll {
  overflow-x: auto;
}

.payroll-runs__table {
  width: 100%;
  min-width: 960px;
  border-collapse: collapse;
  font-size: 13px;
}

.payroll-runs__table th,
.payroll-runs__table td {
  padding: 11px 12px;
  border-bottom: 1px solid var(--color-line);
  text-align: left;
  vertical-align: middle;
}

.payroll-runs__table thead th {
  color: var(--color-muted);
  font-weight: 600;
  font-size: 12px;
  background: var(--color-surface-alt, #f8fafc);
  white-space: nowrap;
}

.payroll-runs__table tbody th {
  font-weight: 600;
}

.payroll-runs__table .is-numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.payroll-runs__table .is-actions {
  text-align: right;
  white-space: nowrap;
}

.payroll-runs__link {
  padding: 0;
  color: var(--color-primary, #2563eb);
  background: none;
  border: none;
  font-weight: 600;
  cursor: pointer;
}

.payroll-runs__link:hover {
  text-decoration: underline;
}

.payroll-runs__period-note,
.payroll-runs__status {
  display: block;
  margin-top: 2px;
  color: var(--color-muted);
  font-size: 11.5px;
}

.payroll-runs__count {
  font-variant-numeric: tabular-nums;
}

.payroll-runs__stage {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
}

.payroll-runs__stage[data-tone='draft'] {
  color: #475569;
  background: #eef2f7;
}

.payroll-runs__stage[data-tone='submitted'] {
  color: #92400e;
  background: #fef3c7;
}

.payroll-runs__stage[data-tone='approved'] {
  color: #1e40af;
  background: #dbeafe;
}

.payroll-runs__stage[data-tone='published'] {
  color: #166534;
  background: #dcfce7;
}

.payroll-runs__stage[data-tone='cancelled'] {
  color: #7f1d1d;
  background: #fee2e2;
}

.payroll-runs__masked {
  color: var(--color-muted);
  font-size: 11.5px;
}

.payroll-runs__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 16px;
  font-size: 12px;
}

.payroll-runs__pager {
  display: inline-flex;
  gap: 8px;
}

.payroll-runs__pager button {
  padding: 5px 12px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 6px;
  cursor: pointer;
}

.payroll-runs__pager button:disabled {
  color: var(--color-muted);
  cursor: not-allowed;
  opacity: 0.6;
}
</style>
