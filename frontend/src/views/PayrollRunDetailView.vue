<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getApi } from '@/api'
import type { PayrollLine, PayrollRunDetail } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone, formatMonthLabel } from '@/utils/format'
import { PAYROLL_STATE } from '@/utils/status'
import MoneyText from '@/components/MoneyText.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import FreshnessNote from '@/components/FreshnessNote.vue'

const props = defineProps<{ id: string }>()
const router = useRouter()
const session = useSessionStore()

const loading = ref(true)
const error = ref('')
const data = ref<PayrollRunDetail | null>(null)
const actionLoading = ref('')
const activeTab = ref<'results' | 'reconciliation' | 'history'>('results')

const canManage = computed(() => session.can('payroll.run.prepare'))

const ACTION_LABEL: Record<PayrollRunDetail['availableActions'][number], string> = {
  LOCK_INPUT: '锁定输入',
  CALCULATE: '重新计算',
  REVIEW: '标记复核',
  APPROVE: '审批通过',
  PUBLISH: '发布工资条',
  CREATE_CORRECTION: '创建更正批次',
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    data.value = await getApi().payroll.getRun(Number(props.id))
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

async function runAction(action: PayrollRunDetail['availableActions'][number]): Promise<void> {
  if (!data.value) return
  if (action === 'PUBLISH') {
    try {
      await ElMessageBox.confirm(
        '发布后工资条对员工可见，批次不可回退到草稿；后续修正必须新建更正批次。确认发布？',
        '发布工资批次',
        { confirmButtonText: '确认发布', cancelButtonText: '取消', type: 'warning' },
      )
    } catch {
      return
    }
  }
  if (action === 'APPROVE') {
    try {
      await ElMessageBox.confirm(
        '审批通过后进入发布准备。复核人与审批人需满足职责分离；确认继续？',
        '审批工资批次',
        { confirmButtonText: '审批通过', cancelButtonText: '取消', type: 'warning' },
      )
    } catch {
      return
    }
  }
  actionLoading.value = action
  try {
    data.value = await getApi().payroll.runAction(data.value.id, action)
    ElMessage.success(
      action === 'PUBLISH'
        ? '批次已发布，员工可在“我的工资条”查看。'
        : action === 'CREATE_CORRECTION'
          ? '更正批次草稿已创建；原批次保持不变。'
          : '批次状态已更新。',
    )
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    actionLoading.value = ''
  }
}

function lineVarianceTone(line: PayrollLine): string {
  if (line.status === 'FAILED') return 'danger'
  if (line.status === 'REVIEW') return 'warning'
  return 'success'
}

onMounted(load)
</script>

<template>
  <div class="page payroll-detail-page">
    <div class="row">
      <el-button text @click="router.push('/payroll')">← 返回工资批次</el-button>
    </div>

    <LoadingState v-if="loading" :rows="9" label="正在加载工资批次" />
    <ErrorState v-else-if="error" title="无法打开工资批次" :description="error" @retry="load" />

    <template v-else-if="data">
      <header class="payroll-hero">
        <div>
          <p class="payroll-hero__meta">
            {{ data.legalEntity }} · {{ formatMonthLabel(data.period) }}
            <span v-if="data.isCorrection" class="plain-chip">更正批次</span>
          </p>
          <h1>{{ data.runNo }}</h1>
          <p class="payroll-hero__sub">
            发薪日 {{ formatBusinessDate(data.payDate) }} · {{ data.employeeCount }} 人 · 更新于
            {{ formatDateTimeInTimezone(data.updatedAt) }}
          </p>
        </div>
        <div class="payroll-hero__state">
          <span class="payroll-hero__state-label">批次状态</span>
          <span class="payroll-state" :class="`payroll-state--${PAYROLL_STATE[data.state].tone}`">
            <span aria-hidden="true">{{ PAYROLL_STATE[data.state].symbol }}</span>
            {{ PAYROLL_STATE[data.state].label }}
          </span>
        </div>
      </header>

      <div class="payroll-kpis">
        <div class="payroll-kpi">
          <span>应发合计</span>
          <MoneyText :cents="data.grossCents" size="lg" tone="strong" />
        </div>
        <div class="payroll-kpi">
          <span>实发合计</span>
          <MoneyText :cents="data.netCents" size="lg" />
        </div>
        <div class="payroll-kpi">
          <span>人头匹配</span>
          <strong>{{ data.reconciliation.calculatedHeadcount }} / {{ data.reconciliation.sourceHeadcount }}</strong>
        </div>
        <div class="payroll-kpi">
          <span>阻断问题</span>
          <strong :class="{ 'text-danger': data.reconciliation.blockedIssues > 0 }">
            {{ data.reconciliation.blockedIssues }}
          </strong>
        </div>
      </div>

      <section v-if="data.availableActions.length" class="panel action-panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">批次操作</h2>
            <p class="panel__subtitle">
              当前页面按能力隐藏操作；后端仍会检查状态机、职责分离与法人范围。
            </p>
          </div>
          <span v-if="!canManage" class="plain-chip">只读</span>
        </div>
        <div class="panel__body action-row">
          <template v-if="canManage">
            <el-button
              v-for="action in data.availableActions"
              :key="action"
              :type="action === 'PUBLISH' || action === 'APPROVE' ? 'primary' : 'default'"
              :loading="actionLoading === action"
              @click="runAction(action)"
            >
              {{ ACTION_LABEL[action] }}
            </el-button>
          </template>
          <p v-else class="small muted">
            你拥有批次查看能力，但没有 payroll.run.prepare 能力。若需操作，请在权限治理中提交带时限的绑定申请。
          </p>
        </div>
      </section>

      <section class="panel">
        <el-tabs v-model="activeTab" class="payroll-tabs">
          <el-tab-pane label="员工结果" name="results">
            <div class="tab-toolbar">
              <p class="small muted">输入快照：合同与组织截至 {{ formatBusinessDate('2026-11-20') }}；金额为整数分。</p>
              <el-input placeholder="搜索员工或工号" size="small" style="max-width: 240px" aria-label="搜索工资行" />
            </div>
            <div class="payroll-lines-wrap">
              <table class="payroll-lines">
                <caption class="visually-hidden">员工工资计算结果</caption>
                <thead>
                  <tr>
                    <th scope="col">员工</th>
                    <th scope="col">组织</th>
                    <th scope="col" class="align-right">应发</th>
                    <th scope="col" class="align-right">扣款</th>
                    <th scope="col" class="align-right">实发</th>
                    <th scope="col" class="align-right">差异</th>
                    <th scope="col">核对</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="line in data.lines" :key="line.employmentId">
                    <td>
                      <span class="line-name">{{ line.employeeName }}</span>
                      <span class="line-no mono">{{ line.employeeNo }}</span>
                    </td>
                    <td class="small">{{ line.orgName }}</td>
                    <td class="align-right"><MoneyText :cents="line.grossCents" size="sm" /></td>
                    <td class="align-right"><MoneyText :cents="line.deductionsCents" size="sm" tone="muted" /></td>
                    <td class="align-right"><MoneyText :cents="line.netCents" size="sm" tone="strong" /></td>
                    <td class="align-right">
                      <MoneyText :cents="line.varianceCents" size="sm" :tone="line.varianceCents === 0 ? 'muted' : 'negative'" />
                    </td>
                    <td>
                      <span class="line-check" :class="`line-check--${lineVarianceTone(line)}`">
                        {{
                          line.status === 'OK' ? '已核对' : line.status === 'REVIEW' ? '待确认' : '失败'
                        }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </el-tab-pane>

          <el-tab-pane label="对账与差异" name="reconciliation">
            <div class="reconciliation">
              <div class="reconciliation__summary">
                <el-alert
                  :type="data.reconciliation.blockedIssues > 0 ? 'warning' : 'success'"
                  :title="data.reconciliation.blockedIssues > 0 ? '存在需要确认的差异' : '对账通过'"
                  :closable="false"
                  show-icon
                >
                  <p>{{ data.reconciliation.note }}</p>
                </el-alert>
              </div>
              <dl class="facts reconciliation__facts">
                <dt>来源人头</dt>
                <dd>{{ data.reconciliation.sourceHeadcount }}</dd>
                <dt>计算人头</dt>
                <dd>{{ data.reconciliation.calculatedHeadcount }}</dd>
                <dt>应发差异</dt>
                <dd><MoneyText :cents="data.reconciliation.grossVarianceCents" size="sm" /></dd>
                <dt>实发差异</dt>
                <dd><MoneyText :cents="data.reconciliation.netVarianceCents" size="sm" /></dd>
                <dt>阻断问题</dt>
                <dd>{{ data.reconciliation.blockedIssues }}</dd>
              </dl>
              <div class="reconciliation__notes">
                <p class="section-title">差异说明</p>
                <p class="small muted">
                  差异按行而不是按总额解释。总额相抵不能作为通过依据；每一条非零差异必须指定原因、责任人与处理动作。
                </p>
              </div>
            </div>
          </el-tab-pane>

          <el-tab-pane label="状态历史" name="history">
            <ol class="history-list">
              <li v-for="event in data.history" :key="`${event.state}-${event.occurredAt}`" class="history-row">
                <span class="history-row__state" :class="`payroll-state--${PAYROLL_STATE[event.state].tone}`">
                  {{ PAYROLL_STATE[event.state].label }}
                </span>
                <div>
                  <p class="history-row__note">{{ event.note }}</p>
                  <p class="small muted">
                    {{ event.actor }} · {{ formatDateTimeInTimezone(event.occurredAt) }}
                  </p>
                </div>
              </li>
            </ol>
          </el-tab-pane>
        </el-tabs>
      </section>

      <FreshnessNote :timestamp="data.freshAt" label="批次数据计算于" />
    </template>
  </div>
</template>

<style scoped>
.payroll-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  padding: 20px 22px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.payroll-hero__meta {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.payroll-hero h1 {
  margin: 3px 0;
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 23px;
  font-weight: 650;
  letter-spacing: -0.02em;
}

.payroll-hero__sub {
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.payroll-hero__state {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 5px;
}

.payroll-hero__state-label {
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.payroll-state {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  font-weight: 600;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
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
  background: #f5f7f6;
  border-color: var(--color-line);
}

.payroll-kpis {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.payroll-kpi {
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 13px 15px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.payroll-kpi > span {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.payroll-kpi > strong {
  font-size: 22px;
  font-weight: 680;
  font-variant-numeric: tabular-nums;
}

.text-danger {
  color: var(--color-danger) !important;
}

.action-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.tab-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 11px 16px;
  border-bottom: 1px solid var(--color-line);
}

.payroll-lines-wrap {
  overflow-x: auto;
}

.payroll-lines {
  width: 100%;
  min-width: 840px;
  border-collapse: collapse;
  font-size: 12px;
}

.payroll-lines th {
  padding: 9px 14px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.payroll-lines td {
  padding: 10px 14px;
  border-bottom: 1px solid #edf1ef;
}

.line-name {
  display: block;
  font-weight: 650;
}

.line-no {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.line-check {
  display: inline-flex;
  padding: 1px 7px;
  font-size: 10px;
  font-weight: 600;
  border-radius: 999px;
}

.line-check--success {
  color: var(--color-success);
  background: var(--color-success-soft);
}

.line-check--warning {
  color: var(--color-warning);
  background: var(--color-warning-soft);
}

.line-check--danger {
  color: var(--color-danger);
  background: var(--color-danger-soft);
}

.align-right {
  text-align: right !important;
}

.reconciliation {
  display: grid;
  gap: 14px;
  padding: 16px;
}

.reconciliation__facts {
  max-width: 480px;
}

.reconciliation__notes {
  padding-top: 10px;
  border-top: 1px solid var(--color-line);
}

.history-list {
  margin: 0;
  padding: 16px;
  list-style: none;
}

.history-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 9px 0;
  border-bottom: 1px solid #edf1ef;
}

.history-row:last-child {
  border-bottom: 0;
}

.history-row__state {
  flex: 0 0 auto;
  padding: 1px 7px;
  font-size: 10px;
  font-weight: 650;
  border-radius: 999px;
}

.history-row__note {
  font-size: 12px;
  font-weight: 550;
}

@media (max-width: 880px) {
  .payroll-kpis {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .payroll-hero {
    flex-direction: column;
  }

  .payroll-hero__state {
    align-items: flex-start;
  }
}
</style>
