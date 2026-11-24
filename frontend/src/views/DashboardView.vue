<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getApi } from '@/api'
import type { DashboardData, DashboardMetric } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import { metricLabel, metricUnitLabel } from '@/utils/status'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import FreshnessNote from '@/components/FreshnessNote.vue'

const router = useRouter()
const loading = ref(true)
const error = ref('')
const data = ref<DashboardData | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    data.value = await getApi().dashboard.getDashboard()
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

function openMetric(metric: DashboardMetric): void {
  void router.push(`/dashboard/metric/${metric.code}`)
}

function trendLabel(metric: DashboardMetric): string {
  const icon = metric.trendDirection === 'UP' ? '↑' : metric.trendDirection === 'DOWN' ? '↓' : '→'
  return `${icon} ${metric.trend ?? ''}`
}

onMounted(load)
</script>

<template>
  <div class="page dashboard-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">洞察</p>
        <h1 class="page-header__title">人力仪表盘</h1>
        <p class="page-header__description">
          所有指标都与下钻列表使用同一投影与同一授权谓词。数字、列表与导出不会各算一套口径。
        </p>
      </div>
      <div class="page-header__actions">
        <FreshnessNote v-if="data" :timestamp="data.freshness" />
      </div>
    </header>

    <ErrorState v-if="error" description="仪表盘数据暂时无法加载。" :code="error" @retry="load" />
    <LoadingState v-else-if="loading" :rows="8" label="正在加载仪表盘" />

    <template v-else-if="data">
      <section class="metric-grid" aria-label="关键指标">
        <button
          v-for="metric in data.metrics"
          :key="metric.code"
          type="button"
          class="metric-card"
          :aria-label="`${metricLabel(metric.code, metric.label)} ${metric.value}${metric.unit}，查看明细`"
          @click="openMetric(metric)"
        >
          <span class="metric-card__label">{{ metricLabel(metric.code, metric.label) }}</span>
          <span class="metric-card__value">
            {{ metric.value }}<span class="metric-card__unit">{{ metricUnitLabel(metric.unit) }}</span>
          </span>
          <span class="metric-card__footer">
            <span>{{ trendLabel(metric) }}</span>
            <span class="metric-card__drill">查看明细 →</span>
          </span>
        </button>
      </section>

      <div class="dashboard-grid">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">待我审批</h2>
              <p class="panel__subtitle">按提交时间排序；超过承诺时限会突出显示。</p>
            </div>
            <RouterLink to="/cases" class="small">进入审批中心</RouterLink>
          </div>
          <ul v-if="data.pendingApprovals.length" class="dashboard-list">
            <li v-for="item in data.pendingApprovals" :key="item.id">
              <RouterLink :to="`/cases/${item.id}`" class="dashboard-list__item">
                <span class="dashboard-list__main">
                  <span class="dashboard-list__title">{{ item.title }}</span>
                  <span class="dashboard-list__meta">
                    {{ item.caseNo }} · 已等待 {{ item.ageDays }} 天
                  </span>
                </span>
                <span class="dashboard-list__status">
                  <span class="plain-chip">{{ item.status }}</span>
                  <span v-if="item.dueAt" class="small muted">承诺 {{ formatDateTimeInTimezone(item.dueAt) }}</span>
                </span>
              </RouterLink>
            </li>
          </ul>
          <div v-else class="panel__body">
            <p class="empty-inline">当前没有待你审批的案例。</p>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">合同到期提醒</h2>
              <p class="panel__subtitle">未来 30 天 · 到期预警是投影，不是合同状态。</p>
            </div>
            <RouterLink to="/dashboard/metric/contract_expiring" class="small">查看全部</RouterLink>
          </div>
          <ul v-if="data.contractAlerts.length" class="contract-list">
            <li v-for="alert in data.contractAlerts" :key="alert.employmentId" class="contract-row">
              <div>
                <p class="contract-row__name">{{ alert.employeeName }}</p>
                <p class="contract-row__meta">{{ alert.orgName }} · {{ alert.employeeNo }}</p>
              </div>
              <div class="contract-row__days">
                <strong>{{ alert.daysRemaining }}</strong>
                <span>天后到期</span>
                <span class="small muted">{{ formatBusinessDate(alert.endDate) }}</span>
              </div>
            </li>
          </ul>
          <div v-else class="panel__body">
            <p class="empty-inline">未来 30 天内没有合同到期提醒。</p>
          </div>
        </section>

        <section class="panel dashboard-notices">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">最近公告</h2>
              <p class="panel__subtitle">需要确认的公告会出现在待办。</p>
            </div>
            <RouterLink to="/notices" class="small">全部公告</RouterLink>
          </div>
          <ul v-if="data.recentNotices.length" class="notice-list" style="padding: 0 18px">
            <li v-for="notice in data.recentNotices" :key="notice.id" class="notice-list__item">
              <RouterLink :to="`/notices/${notice.id}`" class="notice-list__title">
                {{ notice.title }}
                <span v-if="notice.requiresAck && !notice.acknowledged" class="plain-chip">待确认</span>
              </RouterLink>
              <p class="notice-list__meta">
                <template v-if="notice.authorName">{{ notice.authorName }} · </template>
                {{ formatDateTimeInTimezone(notice.publishedAt) }}
              </p>
            </li>
          </ul>
          <div v-else class="panel__body">
            <p class="empty-inline">当前没有可见的已发布公告。</p>
          </div>
        </section>

        <section class="panel dashboard-context">
          <div class="panel__header">
            <h2 class="panel__title">口径说明</h2>
          </div>
          <div class="panel__body stack--tight small muted">
            <p><strong>在职人数：</strong>as-of {{ formatBusinessDate(data.asOf) }} 存在有效任职，并落在授权组织范围。</p>
            <p><strong>主动离职率：</strong>统计年 2026 内主动离职人数 / 平均在职人数，排除退休与合同到期不续签。</p>
            <p><strong>待审批：</strong>当前节点指派给本人、申请状态为审批中的 Case，不含已通过待生效。</p>
            <p><strong>下钻：</strong>每个 KPI 的列表使用与数字相同的查询条件；导出再次使用同一授权约束。</p>
          </div>
          <div class="panel__footer">
            <span class="small muted">{{ data.freshness }}</span>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.dashboard-list,
.contract-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.dashboard-list__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 12px 18px;
  color: inherit;
  border-bottom: 1px solid #edf1ef;
}

.dashboard-list__item:hover {
  background: #fafcfb;
  text-decoration: none;
}

.dashboard-list__main {
  display: flex;
  min-width: 0;
  flex-direction: column;
}

.dashboard-list__title {
  font-size: 12.5px;
  font-weight: 600;
}

.dashboard-list__meta {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.dashboard-list__status {
  display: flex;
  flex: 0 0 auto;
  flex-direction: column;
  align-items: flex-end;
  gap: 3px;
}

.contract-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 11px 18px;
  border-bottom: 1px solid #edf1ef;
}

.contract-row__name {
  font-size: 12.5px;
  font-weight: 650;
}

.contract-row__meta {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.contract-row__days {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.contract-row__days strong {
  color: var(--color-warning);
  font-size: 21px;
  font-variant-numeric: tabular-nums;
}

.contract-row__days span {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

@media (max-width: 1080px) {
  .dashboard-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
