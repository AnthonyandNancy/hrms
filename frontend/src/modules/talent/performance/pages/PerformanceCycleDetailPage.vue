<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PerformanceScoreDrawer from '../components/PerformanceScoreDrawer.vue'
import {
  canScoreReview,
  isCycleReadOnly,
  isDecidedReview,
  listPerformanceCycles,
  listPerformanceReviews,
  performanceActionErrorCopy,
  performanceCycleStage,
  appealPerformanceReview,
  calibratePerformanceReview,
  performancePeriodLabel,
  publishPerformanceCycle,
  ratingLabel,
  reviewStatusLabel,
  savePerformanceReview,
  submitPerformanceReview,
  type PerformanceCycleSummary,
  type PerformanceReviewRow,
} from '../api'

defineOptions({ name: 'PerformanceCycleDetailPage' })

const props = withDefaults(
  defineProps<{
    /** Used when this detail is embedded in the /performance workspace. */
    cycleIdOverride?: number | null
  }>(),
  { cycleIdOverride: null },
)

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('performance.cycle.read'))
const canManage = computed(() => session.can('performance.review.manage'))
const canSubmit = computed(() => session.can('performance.review.submit'))
const canPublish = computed(() => session.can('performance.result.publish'))
const canCalibrate = computed(() => session.can('performance.calibration.manage'))
const canAppeal = computed(() => session.can('performance.appeal.manage'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')
const partialNote = ref('')

const cycle = ref<PerformanceCycleSummary | null>(null)
const reviews = ref<PerformanceReviewRow[]>([])
const reviewPageTotal = ref(0)

const drawerOpen = ref(false)
const selectedReview = ref<PerformanceReviewRow | null>(null)
const saving = ref(false)
const actionError = ref('')
const actionSuccess = ref('')

const publishConfirmOpen = ref(false)
const publishing = ref(false)
const calibrationOpen = ref(false)
const calibrationTarget = ref<PerformanceReviewRow | null>(null)
const calibrationRating = ref('')
const calibrationReason = ref('')
const calibrationError = ref('')
const appealTarget = ref<PerformanceReviewRow | null>(null)
const appealOpen = ref(false)
const appealReason = ref('')
const appealError = ref('')

const cycleId = computed(() => {
  if (props.cycleIdOverride !== null && Number.isSafeInteger(props.cycleIdOverride) && props.cycleIdOverride > 0) {
    return props.cycleIdOverride
  }

  const queryValue = route.query.cycle_id
  if (typeof queryValue === 'string' && /^\d+$/.test(queryValue)) {
    const parsedQuery = Number(queryValue)
    if (Number.isSafeInteger(parsedQuery) && parsedQuery > 0) return parsedQuery
  }

  const value = route.params.id
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})

const readOnlyCycle = computed(() => (cycle.value === null ? false : isCycleReadOnly(cycle.value.status)))
const cycleStage = computed(() => (cycle.value === null ? '' : performanceCycleStage(cycle.value)))
const showEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && reviews.value.length === 0,
)

const pendingCount = computed(
  () => reviews.value.filter((review) => !isDecidedReview(review.status)).length,
)
const publishDisabledReason = computed(() => {
  if (readOnlyCycle.value) return '周期已发布，不能重复发布。'
  if (!canPublish.value) return '缺少能力 performance.result.publish。'
  if (reviews.value.length === 0) return '周期没有评估，无法发布。'
  if (reviews.value.some((review) => review.status.toUpperCase() === 'DRAFT')) {
    return '仍有草稿评估未提交，需全部提交后才能发布。'
  }
  return ''
})

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value =
      reason.code === 'FORBIDDEN'
        ? '无权读取该绩效周期或其评估。当前账号必须持有对应能力，并且评估在授权范围内。'
        : reason.code === 'NOT_FOUND'
          ? '未找到该绩效周期，可能已被移出你的数据范围或链接有误。'
          : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '绩效周期详情暂时无法加载。'
  }
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }
  const id = cycleId.value
  if (id === null) {
    loaded.value = true
    errorCodeValue.value = 'NOT_FOUND'
    errorMessage.value = '绩效周期编号无效。'
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  partialNote.value = ''
  actionError.value = ''
  actionSuccess.value = ''
  reviews.value = []
  reviewPageTotal.value = 0

  try {
    // The route list is the verified cycle read; there is no
    // GET /v1/performance/cycles/{id} route today. Match the requested cycle
    // by walking the real paginated list instead of inventing a URL.
    let requested: PerformanceCycleSummary | null = null
    let pageNumber = 1
    let pages = 1
    do {
      const result = await listPerformanceCycles({}, pageNumber, 100)
      requested ??= result.items.find((item) => item.id === id) ?? null
      pages = Math.max(1, Math.ceil(result.total / Math.max(1, result.pageSize)))
      pageNumber += 1
    } while (requested === null && pageNumber <= pages && pageNumber <= 50)

    cycle.value = requested
    if (requested === null) {
      errorCodeValue.value = 'NOT_FOUND'
      errorMessage.value = '未找到该绩效周期，可能已被移出你的数据范围或链接有误。'
      return
    }

    if (!canManage.value) {
      return
    }

    const result = await listPerformanceReviews({ cycleId: id }, 1, 100)
    reviews.value = result.items
    reviewPageTotal.value = result.total
    if (result.partial) {
      partialNote.value = '服务端标记本次评估投影不完整，页面只展示已返回的行。'
    }
  } catch (reason) {
    cycle.value = null
    reviews.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openScoreDrawer(review: PerformanceReviewRow): void {
  if (!canManage.value) return
  // Decided reviews still open in the drawer, but every control is read-only;
  // this is how an allowed reviewer inspects the goals and stored scores.
  if (!readOnlyCycle.value && canScoreReview(review.status)) {
    selectedReview.value = review
  } else {
    selectedReview.value = review
  }
  drawerOpen.value = true
  actionError.value = ''
  actionSuccess.value = ''
}

async function saveReview(payload: {
  selfRating: string
  selfAssessment: string
  managerRating: string
  managerAssessment: string
  goals: import('../api').PerformanceGoalDraft[]
}): Promise<void> {
  const current = selectedReview.value
  if (current === null) return
  saving.value = true
  actionError.value = ''
  actionSuccess.value = ''
  try {
    await savePerformanceReview({
      cycleId: current.cycleId,
      employmentId: current.employmentId,
      managerEmploymentId: current.managerEmploymentId,
      selfRating: payload.selfRating,
      selfAssessment: payload.selfAssessment,
      managerRating: payload.managerRating,
      managerAssessment: payload.managerAssessment,
      goals: payload.goals,
    })
    drawerOpen.value = false
    actionSuccess.value = `员工 #${current.employmentId} 的评分与目标已保存。`
    await load()
  } catch (reason) {
    actionError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

async function submitReview(payload: { managerRating: string; managerAssessment: string }): Promise<void> {
  const current = selectedReview.value
  if (current === null) return
  saving.value = true
  actionError.value = ''
  actionSuccess.value = ''
  try {
    // The saved row is authoritative, so submit the server id and only the
    // real submit contract fields.
    await submitPerformanceReview(current.id, {
      managerRating: payload.managerRating,
      managerAssessment: payload.managerAssessment,
    })
    drawerOpen.value = false
    actionSuccess.value = `员工 #${current.employmentId} 的评估已提交主管复核。`
    await load()
  } catch (reason) {
    actionError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

function requestPublish(): void {
  if (publishDisabledReason.value !== '') return
  publishConfirmOpen.value = true
}

async function confirmPublish(): Promise<void> {
  const current = cycle.value
  if (current === null) return
  publishConfirmOpen.value = false
  publishing.value = true
  actionError.value = ''
  actionSuccess.value = ''
  try {
    await publishPerformanceCycle(current.id)
    actionSuccess.value = '绩效周期已发布，全部评估转为只读。'
    await load()
  } catch (reason) {
    actionError.value = performanceActionErrorCopy(reason)
  } finally {
    publishing.value = false
  }
}

function openCalibration(review: PerformanceReviewRow): void {
  calibrationTarget.value = review
  calibrationRating.value = review.calibratedRating
  calibrationReason.value = ''
  calibrationError.value = ''
  calibrationOpen.value = true
}

async function confirmCalibration(): Promise<void> {
  const target = calibrationTarget.value
  if (target === null) return
  if (calibrationRating.value.trim() === '' || calibrationReason.value.trim().length < 3) {
    calibrationError.value = '请填写校准等级和至少 3 个字符的校准原因。'
    return
  }
  calibrationError.value = ''
  saving.value = true
  try {
    await calibratePerformanceReview(target.id, calibrationRating.value.trim(), calibrationReason.value.trim())
    calibrationOpen.value = false
    actionSuccess.value = `员工 #${target.employmentId} 的校准等级已记录；原始自评与主管分数不变。`
    await load()
  } catch (reason) {
    calibrationError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

function openAppeal(review: PerformanceReviewRow): void {
  appealTarget.value = review
  appealReason.value = ''
  appealError.value = ''
  appealOpen.value = true
}

async function confirmAppeal(): Promise<void> {
  const target = appealTarget.value
  if (target === null) return
  if (appealReason.value.trim().length < 10) {
    appealError.value = '申诉原因至少需要 10 个字符。'
    return
  }
  appealError.value = ''
  saving.value = true
  try {
    await appealPerformanceReview(target.id, appealReason.value.trim())
    appealOpen.value = false
    actionSuccess.value = `员工 #${target.employmentId} 的绩效申诉已提交为审批案例。`
    await load()
  } catch (reason) {
    appealError.value = performanceActionErrorCopy(reason)
  } finally {
    saving.value = false
  }
}

function backToWorkspace(): void {
  if (route.query.cycle_id !== undefined) {
    void router.push({ path: '/performance' })
    return
  }
  void router.push('/performance')
}

watch(cycleId, () => {
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="performance-detail" data-page="performance-cycle-detail">
    <header class="performance-detail__header">
      <div>
        <button type="button" class="performance-detail__back" @click="backToWorkspace">← 返回绩效考核工作台</button>
        <p class="performance-detail__breadcrumb">人才 · 绩效周期详情</p>
        <h1 class="performance-detail__title">
          <template v-if="cycle !== null">{{ cycle.name }}</template>
          <template v-else>绩效周期详情</template>
        </h1>
        <p v-if="cycle !== null" class="performance-detail__description">
          {{ performancePeriodLabel(cycle.periodFrom, cycle.periodTo) }} · 周期 #{{ cycle.id }} ·
          {{ cycle.participantCount }} 名参与者
        </p>
      </div>
      <div v-if="cycle !== null" class="performance-detail__header-actions">
        <span class="performance-detail__stage">{{ cycleStage }}</span>
        <button
          type="button"
          class="performance-detail__primary"
          :disabled="publishDisabledReason !== '' || publishing"
          :title="publishDisabledReason"
          data-testid="performance-publish"
          @click="requestPublish"
        >
          发布结果
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="performance-detail__state" role="alert" data-testid="performance-detail-forbidden">
      <h2>无权读取绩效周期</h2>
      <p>当前账号缺少 performance.cycle.read 能力，页面不会发起任何周期或评估读取请求。</p>
    </section>

    <ErrorState
      v-else-if="errorMessage !== ''"
      title="绩效周期详情暂时无法加载"
      :description="errorMessage"
      :code="errorCodeValue"
      :request-id="errorRequestId"
      @retry="load"
    />

    <LoadingState v-else-if="loading" :rows="7" label="正在加载绩效周期详情" />

    <template v-else-if="cycle !== null">
      <p v-if="readOnlyCycle" class="performance-detail__readonly" role="status" data-testid="performance-readonly">
        该周期已发布，全部评估为只读：评分、保存、提交与再次发布均已禁用。最终等级仅展示服务端结果。
      </p>

      <p v-if="partialNote !== ''" class="performance-detail__warning" role="status" data-testid="performance-partial">
        部分数据：{{ partialNote }}
      </p>

      <p v-if="actionSuccess !== ''" class="performance-detail__success" role="status" data-testid="performance-action-success">
        {{ actionSuccess }}
      </p>
      <p v-if="actionError !== ''" class="performance-detail__error" role="alert" data-testid="performance-action-error">
        {{ actionError }}
      </p>

      <section class="performance-detail__facts" aria-label="绩效周期基本信息">
        <div>
          <dt>周期名称</dt>
          <dd>{{ cycle.name }}</dd>
        </div>
        <div>
          <dt>考核期间</dt>
          <dd>{{ performancePeriodLabel(cycle.periodFrom, cycle.periodTo) }}</dd>
        </div>
        <div>
          <dt>参与人数</dt>
          <dd>{{ cycle.participantCount }} 人</dd>
        </div>
        <div>
          <dt>已提交</dt>
          <dd>{{ cycle.submittedCount }} 人</dd>
        </div>
        <div>
          <dt>待提交</dt>
          <dd>{{ pendingCount }} 人</dd>
        </div>
        <div>
          <dt>进度</dt>
          <dd>{{ cycle.progress }}%</dd>
        </div>
      </section>

      <section v-if="!canManage" class="performance-detail__state" role="alert" data-testid="performance-review-forbidden">
        <h2>无权查看评估明细</h2>
        <p>当前账号缺少 performance.review.manage 能力，只展示周期汇总，不请求员工评估行。</p>
      </section>

      <template v-else>
        <section class="panel" aria-label="评估参与者">
          <header class="panel__header">
            <div>
              <h2 class="panel__title">参与者评估</h2>
              <p class="panel__subtitle">共 {{ reviewPageTotal }} 条评估 · 每条评估显示目标与评分状态</p>
            </div>
          </header>

          <EmptyState
            v-if="showEmpty"
            title="该周期还没有评估"
            description="主管或人力资源为参与者创建评估后，这里会显示目标和评分状态。"
          />

          <div v-else class="panel__body panel__body--flush">
            <div class="performance-detail__wrap">
              <table class="performance-detail__table" data-testid="performance-review-table">
                <caption class="visually-hidden">参与者的绩效评估</caption>
                <thead>
                  <tr>
                    <th scope="col">员工</th>
                    <th scope="col">主管</th>
                    <th scope="col">状态</th>
                    <th scope="col">目标</th>
                    <th scope="col">权重合计</th>
                    <th scope="col">自评分数</th>
                    <th scope="col">主管分数</th>
                    <th scope="col">最终等级</th>
                    <th scope="col">操作</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="review in reviews" :key="review.id" :data-testid="`performance-review-${review.id}`">
                    <th scope="row">员工 #{{ review.employmentId }}</th>
                    <td>{{ review.managerEmploymentId === null ? '—' : `员工 #${review.managerEmploymentId}` }}</td>
                    <td>
                      <span class="performance-review-status" :data-decided="isDecidedReview(review.status)">{{ isDecidedReview(review.status) ? `${reviewStatusLabel(review.status)}（只读）` : reviewStatusLabel(review.status) }}</span>
                    </td>
                    <td>
                      <ul v-if="review.goals.length > 0" class="performance-detail__goals">
                        <li v-for="goal in review.goals" :key="goal.title">
                          {{ goal.title }} · {{ goal.weight }}%
                        </li>
                      </ul>
                      <span v-else>—</span>
                    </td>
                    <td class="is-numeric">
                      {{ review.goals.reduce((total, goal) => total + goal.weight, 0) }}%
                      <template v-if="review.goals.length > 0 && review.goals.reduce((total, goal) => total + goal.weight, 0) === 100">
                        · 100% 权重已分配
                      </template>
                    </td>
                    <td class="is-numeric">{{ review.selfRating === '' ? '—' : `${review.selfRating} 分` }}</td>
                    <td class="is-numeric">{{ review.managerRating === '' ? '—' : `${review.managerRating} 分` }}</td>
                    <td data-testid="performance-row-final-rating">
                      {{ review.finalRating === '' ? '待服务端计算' : ratingLabel(review.finalRating) }}
                    </td>
                    <td>
                      <button
                        type="button"
                        class="performance-detail__link"
                        :disabled="!canManage"
                        :data-testid="`performance-score-${review.id}`"
                        @click="openScoreDrawer(review)"
                      >
                        {{ !readOnlyCycle && canScoreReview(review.status) ? '评分' : '查看' }}
                      </button>
                      <button
                        v-if="canCalibrate && !readOnlyCycle && isDecidedReview(review.status)"
                        type="button"
                        class="performance-detail__link"
                        :data-testid="`performance-calibrate-${review.id}`"
                        @click="openCalibration(review)"
                      >
                        校准
                      </button>
                      <button
                        v-if="canAppeal && review.status === 'SUBMITTED'"
                        type="button"
                        class="performance-detail__link"
                        :data-testid="`performance-appeal-${review.id}`"
                        @click="openAppeal(review)"
                      >
                        申诉
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </template>

      <el-dialog
        v-model="calibrationOpen"
        title="校准绩效等级"
        width="480px"
        :close-on-click-modal="false"
        data-testid="performance-calibration-dialog"
      >
        <p>校准只写入校准等级和原因，不覆盖自评或主管原始分数。</p>
        <label class="performance-dialog__field">
          <span>校准等级</span>
          <input v-model="calibrationRating" type="text" maxlength="16" placeholder="例如 MEETS" data-testid="performance-calibration-rating" />
        </label>
        <label class="performance-dialog__field">
          <span>校准原因</span>
          <textarea v-model="calibrationReason" rows="3" maxlength="4000" data-testid="performance-calibration-reason" />
        </label>
        <p v-if="calibrationError !== ''" class="performance-dialog__error" role="alert">{{ calibrationError }}</p>
        <template #footer>
          <el-button data-testid="performance-calibration-cancel" @click="calibrationOpen = false">取消</el-button>
          <el-button type="primary" :loading="saving" data-testid="performance-calibration-submit" @click="confirmCalibration">
            确认校准
          </el-button>
        </template>
      </el-dialog>

      <el-dialog
        v-model="appealOpen"
        title="发起绩效申诉"
        width="480px"
        :close-on-click-modal="false"
        data-testid="performance-appeal-dialog"
      >
        <p>申诉将创建审批案例，原评估保持只读。</p>
        <label class="performance-dialog__field">
          <span>申诉原因</span>
          <textarea v-model="appealReason" rows="3" maxlength="4000" data-testid="performance-appeal-reason" />
        </label>
        <p v-if="appealError !== ''" class="performance-dialog__error" role="alert">{{ appealError }}</p>
        <template #footer>
          <el-button data-testid="performance-appeal-cancel" @click="appealOpen = false">取消</el-button>
          <el-button type="primary" :loading="saving" data-testid="performance-appeal-submit" @click="confirmAppeal">
            提交申诉
          </el-button>
        </template>
      </el-dialog>

      <el-dialog
        v-model="publishConfirmOpen"
        title="发布绩效结果"
        width="480px"
        :close-on-click-modal="false"
        data-testid="performance-publish-confirm"
      >
        <p>将发布 {{ cycle.name }} 的绩效结果，并把全部评估转为只读。</p>
        <p>当前已提交 {{ cycle.submittedCount }} / {{ cycle.participantCount }} 人，待提交 {{ pendingCount }} 人。</p>
        <template #footer>
          <el-button data-testid="performance-publish-cancel" @click="publishConfirmOpen = false">取消</el-button>
          <el-button type="primary" :loading="publishing" data-testid="performance-publish-confirm-submit" @click="confirmPublish">
            确认发布
          </el-button>
        </template>
      </el-dialog>

      <PerformanceScoreDrawer
        v-model="drawerOpen"
        :cycle-name="cycle.name"
        :review="selectedReview"
        :read-only="readOnlyCycle || (selectedReview !== null && isDecidedReview(selectedReview.status))"
        :can-manage="canManage"
        :can-submit="canSubmit"
        :saving="saving"
        @save="saveReview"
        @submit="submitReview"
      />
    </template>
  </div>
</template>

<style scoped>
.performance-detail {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 24px;
}

.performance-detail__back {
  padding: 0;
  border: 0;
  background: none;
  color: #1d4ed8;
  font-size: 12px;
  cursor: pointer;
}

.performance-detail__breadcrumb {
  margin: 6px 0 0;
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.08em;
}

.performance-detail__title {
  margin: 4px 0 0;
  font-size: 22px;
}

.performance-detail__description {
  margin: 6px 0 0;
  color: #475569;
  font-size: 12.5px;
}

.performance-detail__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.performance-detail__header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.performance-detail__stage {
  padding: 3px 10px;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 11.5px;
  font-weight: 600;
}

.performance-detail__primary {
  padding: 7px 12px;
  border: 1px solid #1d4ed8;
  border-radius: 8px;
  background: #1d4ed8;
  color: #fff;
  font-size: 12px;
  cursor: pointer;
}

.performance-detail__primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.performance-detail__state {
  padding: 18px;
  border: 1px solid #fecaca;
  border-radius: 10px;
  background: #fef2f2;
  color: #991b1b;
}

.performance-detail__state h2 {
  margin: 0 0 6px;
  font-size: 15px;
}

.performance-detail__state p {
  margin: 0;
  font-size: 12px;
}

.performance-detail__readonly,
.performance-detail__warning {
  margin: 0;
  padding: 10px 12px;
  border: 1px solid #fde68a;
  border-radius: 8px;
  background: #fffbeb;
  color: #92400e;
  font-size: 12px;
}

.performance-detail__warning {
  border-color: #bfdbfe;
  background: #eff6ff;
  color: #1e40af;
}

.performance-detail__success {
  margin: 0;
  padding: 9px 12px;
  border: 1px solid #a7f3d0;
  border-radius: 8px;
  background: #ecfdf5;
  color: #047857;
  font-size: 12px;
}

.performance-detail__error {
  margin: 0;
  padding: 9px 12px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 12px;
}

.performance-detail__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.performance-detail__facts dt {
  color: #64748b;
  font-size: 11px;
}

.performance-detail__facts dd {
  margin: 3px 0 0;
  font-size: 13px;
  font-weight: 600;
}

.performance-detail__wrap {
  overflow-x: auto;
}

.performance-detail__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.performance-detail__table th,
.performance-detail__table td {
  padding: 9px 12px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: top;
}

.performance-detail__table thead th {
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}

.performance-detail__table .is-numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.performance-detail__goals {
  margin: 0;
  padding: 0;
  list-style: none;
  color: #475569;
}

.performance-review-status {
  display: inline-flex;
  padding: 2px 9px;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}

.performance-review-status[data-decided='true'] {
  background: #f1f5f9;
  color: #475569;
}

.performance-detail__link {
  border: 0;
  background: none;
  color: #1d4ed8;
  font-size: 12px;
  cursor: pointer;
}

.performance-detail__link:disabled {
  color: #94a3b8;
  cursor: not-allowed;
}

.performance-detail__readonly-label {
  color: #94a3b8;
  font-size: 11.5px;
}

.performance-dialog__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 10px;
  color: #475569;
  font-size: 12px;
}

.performance-dialog__field input,
.performance-dialog__field textarea {
  padding: 7px 9px;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  font: inherit;
}

.performance-dialog__error {
  margin: 8px 0 0;
  color: #b91c1c;
  font-size: 11.5px;
}
</style>
