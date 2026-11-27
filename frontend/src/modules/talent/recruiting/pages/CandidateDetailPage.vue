<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ApiError, errorCopy } from '@/api/errors'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone, formatMoney } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import CandidateApplicationsPanel from '../components/CandidateApplicationsPanel.vue'
import CandidateCommunicationsPanel from '../components/CandidateCommunicationsPanel.vue'
import CandidateConversionPanel from '../components/CandidateConversionPanel.vue'
import CandidateEvaluationsPanel from '../components/CandidateEvaluationsPanel.vue'
import CandidateInterviewsPanel from '../components/CandidateInterviewsPanel.vue'
import CandidateOffersPanel from '../components/CandidateOffersPanel.vue'
import CandidateTalentPoolPanel from '../components/CandidateTalentPoolPanel.vue'
import {
  candidateStatusLabel,
  isForbidden,
  listCandidates,
  listInterviews,
  listOffers,
  listOrgUnits,
  type Candidate,
  type Interview,
  type Offer,
  type OrgUnitOption,
} from '../api'

defineOptions({ name: 'CandidateDetailPage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden' | 'not-found'
type DetailTab =
  | 'overview'
  | 'applications'
  | 'interviews'
  | 'evaluations'
  | 'offers'
  | 'communications'
  | 'talent-pool'
  | 'conversion'

const route = useRoute()
const session = useSessionStore()

const canRead = computed(() => session.can('recruitment.candidate.read'))
const canConvert = computed(() => session.can('recruitment.candidate.convert'))
const canSeeOffers = computed(() => session.can('recruitment.offer.manage'))
const canManageEvaluations = computed(() => session.can('recruitment.evaluation.manage'))
const canManageCommunications = computed(() => session.can('recruitment.communication.manage'))
const canManageTalentPool = computed(() => session.can('recruitment.talent-pool.manage'))
const canManageCandidate = computed(() => session.can('recruitment.candidate.manage'))

const candidateId = computed(() => {
  const raw = route.params.id
  const value = Array.isArray(raw) ? raw[0] : raw
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : 0
})

const activeTab = ref<DetailTab>('overview')
const state = ref<LoadState>('idle')
const candidate = ref<Candidate | null>(null)
const interviews = ref<Interview[]>([])
const offers = ref<Offer[]>([])
const orgUnits = ref<OrgUnitOption[]>([])
const pageError = ref<unknown>(null)
const interviewError = ref('')
const offerError = ref('')
const orgError = ref('')


let requestSequence = 0

const loading = computed(() => state.value === 'idle' || state.value === 'loading')
const forbidden = computed(() => !canRead.value || state.value === 'forbidden')
const notFound = computed(() => state.value === 'not-found')
const partial = computed(
  () => state.value === 'ready' && (interviewError.value !== '' || offerError.value !== '' || orgError.value !== ''),
)
const statusLabel = computed(() =>
  candidate.value === null ? '' : candidateStatusLabel(candidate.value.status),
)

const tabs = computed<ReadonlyArray<{ key: DetailTab; label: string }>>(() => {
  const items: Array<{ key: DetailTab; label: string }> = [
    { key: 'overview', label: '概览' },
    { key: 'applications', label: '应聘申请' },
    { key: 'interviews', label: '面试安排' },
  ]
  if (canManageEvaluations.value) items.push({ key: 'evaluations', label: '面试评价' })
  if (canSeeOffers.value) items.push({ key: 'offers', label: 'Offer' })
  if (canManageCommunications.value) items.push({ key: 'communications', label: '沟通记录' })
  if (canManageTalentPool.value) items.push({ key: 'talent-pool', label: '人才库' })
  if (canConvert.value) items.push({ key: 'conversion', label: '录用转入职' })
  return items
})

function applyRouteTab(value: unknown): void {
  const requested = typeof value === 'string' ? value : 'overview'
  activeTab.value = tabs.value.some((tab) => tab.key === requested) ? (requested as DetailTab) : 'overview'
}

async function load(): Promise<void> {
  if (!canRead.value) {
    state.value = 'forbidden'
    return
  }
  if (candidateId.value === 0) {
    state.value = 'not-found'
    return
  }

  const sequence = ++requestSequence
  state.value = 'loading'
  pageError.value = null
  interviewError.value = ''
  offerError.value = ''
  orgError.value = ''

  const candidateRead = listCandidates({ candidateId: candidateId.value }, 1, 1)
  const interviewRead = listInterviews(candidateId.value)
  const offerRead = canSeeOffers.value
    ? listOffers(candidateId.value)
    : Promise.reject(new ApiError('FORBIDDEN', '缺少 Offer 读取能力', { status: 403 }))
  const orgRead = canConvert.value
    ? listOrgUnits()
    : Promise.reject(new ApiError('FORBIDDEN', '缺少转入职能力', { status: 403 }))

  const [candidatePage, interviewPage, offerPage, orgPage] = await Promise.allSettled([
    candidateRead,
    interviewRead,
    offerRead,
    orgRead,
  ])

  if (sequence !== requestSequence) return

  if (candidatePage.status === 'rejected') {
    pageError.value = candidatePage.reason
    state.value = isForbidden(candidatePage.reason) ? 'forbidden' : 'error'
    return
  }

  const found = candidatePage.value.items.find((row) => row.id === candidateId.value) ?? null

  if (found === null) {
    candidate.value = null
    state.value = 'not-found'
    return
  }

  candidate.value = found

  if (interviewPage.status === 'fulfilled') {
    interviews.value = interviewPage.value
  } else {
    interviews.value = []
    interviewError.value = isForbidden(interviewPage.reason)
      ? '面试记录需要 recruitment.candidate.read 且服务端许可。'
      : '部分数据：面试记录加载失败。'
  }

  if (!canSeeOffers.value) {
    offers.value = []
    offerError.value = 'Offer 信息需要 recruitment.offer.manage 才能读取。'
  } else if (offerPage.status === 'fulfilled') {
    offers.value = offerPage.value
  } else {
    offers.value = []
    offerError.value = '部分数据：Offer 信息加载失败。'
  }

  if (!canConvert.value) {
    orgUnits.value = []
  } else if (orgPage.status === 'fulfilled') {
    orgUnits.value = orgPage.value
  } else {
    orgUnits.value = []
    orgError.value = '部分数据：组织单元目录加载失败，转入职前需恢复目录。'
  }

  state.value = 'ready'
}

function modeLabel(value: string): string {
  return value === 'VIDEO' ? '视频' : value === 'ONSITE' ? '现场' : value === 'PHONE' ? '电话' : value
}

function recommendationLabel(value: string | null): string {
  switch (value) {
    case 'STRONG_YES':
      return '强烈推荐'
    case 'YES':
      return '推荐'
    case 'NO':
      return '不推荐'
    case 'STRONG_NO':
      return '强烈不推荐'
    default:
      return '未填写'
  }
}

watch(
  () => route.query.tab,
  (value) => applyRouteTab(value),
  { immediate: true },
)

onMounted(load)
onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <PageShell title="候选人档案" description="查看候选人资料、应聘申请、面试、评价、Offer、沟通、人才库与录用转入职。">
    <template #actions>
      <button type="button" class="candidate-detail__secondary" :disabled="loading" @click="load">刷新</button>
    </template>

    <section v-if="forbidden" class="candidate-detail__forbidden" role="alert" data-testid="candidate-detail-forbidden">
      <h2>无权查看候选人档案</h2>
      <p>当前账号缺少 recruitment.candidate.read，页面不会发起候选人读取请求。</p>
    </section>

    <LoadingState v-else-if="loading" :rows="5" label="正在加载候选人档案" />

    <ErrorState
      v-else-if="state === 'error'"
      :title="'候选人档案暂时无法加载'"
      :description="pageError === null ? '候选人档案暂时无法加载。' : errorCopy(pageError)"
      :request-id="requestIdFromError(pageError)"
      @retry="load"
    />

    <EmptyState
      v-else-if="notFound"
      title="未找到该候选人"
      description="候选人不存在、已移出数据范围，或链接有误。页面不会用演示数据代替。"
    />

    <template v-else-if="candidate !== null">
      <p v-if="partial" class="candidate-detail__warning" role="status" data-testid="candidate-detail-partial">
        <template v-if="interviewError !== ''">{{ interviewError }} </template>
        <template v-if="offerError !== ''">{{ offerError }} </template>
        <template v-if="orgError !== ''">{{ orgError }}</template>
      </p>

      <el-tabs v-model="activeTab" data-testid="candidate-detail-tabs">
        <el-tab-pane v-for="tab in tabs" :key="tab.key" :label="tab.label" :name="tab.key" />
      </el-tabs>

      <div v-if="activeTab === 'overview'" class="candidate-detail__grid" data-testid="candidate-tab-overview">
        <section class="candidate-detail__card" data-testid="candidate-profile">
          <h2>候选人资料</h2>
          <dl>
            <div><dt>姓名</dt><dd>{{ candidate.displayName }}</dd></div>
            <div><dt>阶段</dt><dd>{{ statusLabel }}</dd></div>
            <div><dt>来源</dt><dd>{{ candidate.source ?? '未登记' }}</dd></div>
            <div>
              <dt>联系方式</dt>
              <dd>
                <template v-if="candidate.hasEmail">邮箱已登记（脱敏）</template>
                <template v-else>无邮箱</template>
                <template v-if="candidate.hasMobile"> · 手机已登记（脱敏）</template>
              </dd>
            </div>
            <div><dt>关联需求</dt><dd>{{ candidate.requisitionId === null ? '未关联' : `需求 #${candidate.requisitionId}` }}</dd></div>
            <div v-if="candidate.applicationId !== null"><dt>应聘申请</dt><dd>#{{ candidate.applicationId }}</dd></div>
            <div v-if="candidate.onboardingCaseId !== null"><dt>入职案件</dt><dd>#{{ candidate.onboardingCaseId }}</dd></div>
            <div v-if="candidate.convertedEmployeeNo !== null"><dt>已生成员工</dt><dd>{{ candidate.convertedEmployeeNo }}</dd></div>
          </dl>
          <p v-if="!canSeeOffers" class="candidate-detail__hint">Offer 信息需要 recruitment.offer.manage 才能读取。</p>
        </section>

        <section class="candidate-detail__card" data-testid="candidate-offers">
          <h2>Offer 信息</h2>
          <p v-if="offers.length === 0" class="candidate-detail__hint">当前没有可展示的 Offer；页面不会生成虚构金额。</p>
          <article v-for="offer in offers" :key="offer.id" class="candidate-detail__offer">
            <header><strong>第 {{ offer.version }} 版</strong><span>{{ offer.statusLabel }}</span></header>
            <p>基本薪资：{{ formatMoney(offer.baseSalaryMinor) }} · {{ offer.salaryCurrency }}</p>
            <p>预计入职：{{ formatBusinessDate(offer.startDate) }}</p>
            <p v-if="offer.onboardingCaseId !== null" class="candidate-detail__hint">
              入职案件 <a :href="`/workflow/cases/${offer.onboardingCaseId}`">#{{ offer.onboardingCaseId }}</a>
            </p>
            <p v-if="offer.acceptedAt !== null" class="candidate-detail__hint">接受时间 {{ formatDateTimeInTimezone(offer.acceptedAt) }}</p>
          </article>
        </section>

        <section class="candidate-detail__card" data-testid="candidate-interviews">
          <h2>面试记录</h2>
          <p v-if="interviewError !== ''" class="candidate-detail__hint">{{ interviewError }}</p>
          <p v-else-if="interviews.length === 0" class="candidate-detail__hint">还没有面试记录。</p>
          <article v-for="interview in interviews" :key="interview.id" class="candidate-detail__interview">
            <header>
              <strong>{{ formatDateTimeInTimezone(interview.scheduledAt) }}</strong>
              <span>{{ modeLabel(interview.mode) }} · {{ interview.durationMinutes }} 分钟</span>
            </header>
            <p>面试官：{{ interview.interviewer }}</p>
            <p v-if="interview.feedbackRestricted" class="candidate-detail__restricted" data-testid="interview-feedback-restricted">
              面试反馈仅授权可见（需要 recruitment.evaluation.manage）。
            </p>
            <template v-else>
              <p>录用建议：{{ recommendationLabel(interview.recommendation) }}</p>
              <p v-if="interview.feedback !== null">反馈：{{ interview.feedback }}</p>
            </template>
          </article>
        </section>
      </div>

      <CandidateApplicationsPanel
        v-else-if="activeTab === 'applications'"
        :candidate-id="candidate.id"
        :can-manage="canManageCandidate"
        @changed="load"
      />
      <CandidateInterviewsPanel
        v-else-if="activeTab === 'interviews'"
        :candidate-id="candidate.id"
        :can-manage="canManageCandidate || session.can('recruitment.interview.manage')"
        @changed="load"
      />
      <CandidateEvaluationsPanel
        v-else-if="activeTab === 'evaluations'"
        :candidate-id="candidate.id"
        :can-manage="canManageEvaluations"
      />
      <CandidateOffersPanel
        v-else-if="activeTab === 'offers'"
        :candidate-id="candidate.id"
        :can-manage="canSeeOffers"
        :can-accept="session.can('recruitment.offer.accept')"
      />
      <CandidateCommunicationsPanel
        v-else-if="activeTab === 'communications'"
        :candidate-id="candidate.id"
        :can-manage="canManageCommunications"
      />
      <CandidateTalentPoolPanel
        v-else-if="activeTab === 'talent-pool'"
        :candidate-id="candidate.id"
        :can-manage="canManageTalentPool"
      />
      <CandidateConversionPanel
        v-else-if="activeTab === 'conversion'"
        :candidate="candidate"
        :can-convert="canConvert"
      />
    </template>
  </PageShell>
</template>

<style scoped>
.candidate-detail__primary,
.candidate-detail__secondary { height: 34px; padding: 0 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #334155; cursor: pointer; }
.candidate-detail__primary { border-color: #1d4ed8; background: #1d4ed8; color: #fff; }
.candidate-detail__primary:disabled,
.candidate-detail__secondary:disabled { cursor: not-allowed; opacity: 0.55; }
.candidate-detail__forbidden { padding: 26px; border: 1px solid #fecaca; border-radius: 12px; background: #fef2f2; color: #7f1d1d; }
.candidate-detail__forbidden h2 { margin: 0 0 8px; font-size: 17px; }
.candidate-detail__warning { margin: 0; padding: 10px 12px; border: 1px solid #fed7aa; border-radius: 8px; background: #fff7ed; color: #9a3412; font-size: 12.5px; }
.candidate-detail__success { padding: 14px 16px; border: 1px solid #a7f3d0; border-radius: 10px; background: #ecfdf5; color: #065f46; }
.candidate-detail__success h2 { margin: 0 0 6px; font-size: 15px; }
.candidate-detail__success p { margin: 0 0 6px; font-size: 12.5px; }
.candidate-detail__success a { color: #047857; font-size: 12.5px; font-weight: 600; }
.candidate-detail__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; }
.candidate-detail__card { padding: 16px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
.candidate-detail__card h2 { margin: 0 0 12px; font-size: 15px; }
.candidate-detail__card dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin: 0; }
.candidate-detail__card dt { color: #64748b; font-size: 11.5px; }
.candidate-detail__card dd { margin: 4px 0 0; color: #0f172a; font-size: 13px; }
.candidate-detail__hint { margin: 8px 0 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.candidate-detail__offer,
.candidate-detail__interview { padding: 10px 0; border-top: 1px solid #eef2f7; }
.candidate-detail__offer header,
.candidate-detail__interview header { display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; }
.candidate-detail__offer p,
.candidate-detail__interview p { margin: 4px 0 0; color: #475569; font-size: 12px; }
.candidate-detail__restricted { color: #b45309 !important; font-weight: 600; }
.candidate-detail__modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; background: rgb(15 23 42 / 42%); }
.candidate-detail__modal section { width: min(480px, 92vw); display: flex; flex-direction: column; gap: 12px; padding: 22px; border-radius: 14px; background: #fff; box-shadow: 0 24px 60px rgb(15 23 42 / 25%); }
.candidate-detail__modal h2 { margin: 0; font-size: 17px; }
.candidate-detail__modal p { margin: 0; color: #475569; font-size: 12.5px; line-height: 1.7; }
.candidate-detail__modal label { display: flex; flex-direction: column; gap: 6px; color: #334155; font-size: 12.5px; }
.candidate-detail__modal footer { display: flex; justify-content: flex-end; gap: 8px; }
.candidate-detail__error { margin: 0; color: #b91c1c; font-size: 12.5px; }
</style>
