<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatMoney } from '@/utils/format'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import { businessDate, listReports, trainingActionErrorCopy, type TrainingReport } from '../api'

defineOptions({ name: 'TrainingReportsPanel' })

const session = useSessionStore()
const canRead = computed(() => session.can('training.report.read'))
const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TrainingReport[]>([])

const empty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && rows.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取培训报表。请确认已获得 training.report.read 能力。'
      : trainingActionErrorCopy(reason) || errorCopy(reason)
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  try {
    rows.value = await listReports(null, businessDate())
  } catch (reason) {
    rows.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function rate(bps: number): string {
  return `${(bps / 100).toFixed(2)}%`
}

onMounted(load)
</script>

<template>
  <section class="training-panel" data-testid="training-reports-panel">
    <header class="training-panel__header">
      <div>
        <h2>培训报表</h2>
        <p>按计划实时聚合报名、完成、考试通过、学时与反馈；比率以基点返回并在页面格式化。</p>
      </div>
      <p class="training-panel__meta">报表中心保留培训类型入口，本 Tab 只展示同一真实聚合。</p>
    </header>

    <section v-if="!canRead" class="training-panel__forbidden" role="alert" data-testid="training-reports-forbidden">
      <h3>无权查看培训报表</h3>
      <p>当前账号缺少 training.report.read 能力，不会发起报表读取。</p>
    </section>
    <template v-else>
      <LoadingState v-if="loading" :rows="4" label="正在加载培训报表" />
      <ErrorState
        v-else-if="errorMessage !== ''"
        title="培训报表暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <EmptyState
        v-else-if="empty"
        title="还没有培训计划报表"
        description="报表只统计真实计划、课程、场次与报名；没有数据时保持为空。"
      />
      <div v-else class="training-panel__table-wrap">
        <table class="training-panel__table">
          <thead>
            <tr>
              <th>计划</th>
              <th>年度</th>
              <th>预算</th>
              <th>成本</th>
              <th>课程/场次</th>
              <th>报名/完成</th>
              <th>完成率</th>
              <th>考试通过</th>
              <th>学时</th>
              <th>反馈</th>
              <th>平均评分</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.planId" :data-testid="`training-report-row-${row.planId}`">
              <td>{{ row.code }} · {{ row.title }}</td>
              <td>{{ row.year }}</td>
              <td>{{ formatMoney(row.budgetCents) }}</td>
              <td>{{ formatMoney(row.trainingCostCents) }}</td>
              <td>{{ row.courseCount }} / {{ row.sessionCount }}</td>
              <td>{{ row.enrolledCount }} / {{ row.completedCount }}</td>
              <td>{{ rate(row.completionRateBps) }}</td>
              <td>{{ row.examPassCount }} · {{ rate(row.examPassRateBps) }}</td>
              <td>{{ row.studyMinutesTotal }} 分钟</td>
              <td>{{ row.feedbackCount }}</td>
              <td>{{ rate(row.averageFeedbackRatingBps) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.training-panel { display: flex; flex-direction: column; gap: 12px; }
.training-panel__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.training-panel__header h2 { margin: 0 0 4px; font-size: 15px; color: #0f172a; }
.training-panel__header p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.training-panel__meta { margin: 0; color: #64748b; font-size: 12px; }
.training-panel__table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.training-panel__table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.training-panel__table th, .training-panel__table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.training-panel__table th { background: #f8fafc; color: #64748b; font-weight: 600; }
.training-panel__forbidden { padding: 20px; border: 1px solid #fecaca; border-radius: 10px; background: #fef2f2; color: #991b1b; }
.training-panel__forbidden h3 { margin: 0 0 6px; font-size: 14px; }
.training-panel__forbidden p { margin: 0; font-size: 12px; }
</style>
