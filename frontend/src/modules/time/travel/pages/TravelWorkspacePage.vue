<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import TravelApprovalPanel from '../components/TravelApprovalPanel.vue'
import TravelExpensesPanel from '../components/TravelExpensesPanel.vue'
import TravelPoliciesPanel from '../components/TravelPoliciesPanel.vue'
import TravelReportPanel from '../components/TravelReportPanel.vue'
import TravelRequestsPanel from '../components/TravelRequestsPanel.vue'

defineOptions({ name: 'TravelWorkspacePage' })

type TravelTab = 'mine' | 'requests' | 'policies' | 'approval' | 'reports' | 'expenses'

const session = useSessionStore()
const route = useRoute()
const router = useRouter()

const canRead = computed(() => session.can('time.travel.read'))
const canRequest = computed(() => session.can('time.travel.request'))
const canApprove = computed(() => session.can('time.travel.approve'))
const canPolicyRead = computed(() => session.can('time.travel.policy.read'))
const canPolicyManage = computed(() => session.can('time.travel.policy.manage'))
const canExpense = computed(() => session.can('time.travel.expense'))
const canExpenseManage = computed(() => session.can('time.travel.expense.manage'))
const canReport = computed(() => session.can('time.travel.report.read'))

const VALID_TABS: TravelTab[] = ['mine', 'requests', 'policies', 'approval', 'reports', 'expenses']

const tab = ref<TravelTab>('mine')
const message = ref('')
const detailTravelId = ref<number | null>(null)

const availableTabs = computed(() => {
  const tabs: TravelTab[] = ['mine']
  if (canRead.value) tabs.push('requests')
  if (canPolicyRead.value) tabs.push('policies')
  if (canApprove.value) tabs.push('approval')
  if (canReport.value) tabs.push('reports')
  if (canExpense.value) tabs.push('expenses')
  return tabs
})

const tabMeta: Record<TravelTab, { label: string; testId: string }> = {
  mine: { label: '我的出差', testId: 'travel-tab-mine' },
  requests: { label: '出差台账', testId: 'travel-tab-requests' },
  policies: { label: '差旅政策', testId: 'travel-tab-policies' },
  approval: { label: '出差审批', testId: 'travel-tab-approval' },
  reports: { label: '出差统计', testId: 'travel-tab-reports' },
  expenses: { label: '费用交接', testId: 'travel-tab-expenses' },
}

function parseTab(value: unknown): TravelTab {
  const raw = Array.isArray(value) ? String(value[0] ?? '') : String(value ?? '')
  return VALID_TABS.includes(raw as TravelTab) ? (raw as TravelTab) : 'mine'
}

function parseTravelId(value: unknown): number | null {
  const raw = Array.isArray(value) ? String(value[0] ?? '') : String(value ?? '')
  if (!/^\d+$/.test(raw)) return null
  const parsed = Number(raw)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function syncFromRoute(): void {
  const requested = parseTab(route.query.tab)
  tab.value = availableTabs.value.includes(requested) ? requested : 'mine'
  detailTravelId.value = parseTravelId(route.query.travel_id)
}

function selectTab(next: TravelTab): void {
  if (!availableTabs.value.includes(next)) return
  tab.value = next
  message.value = ''
  void router.replace({
    path: route.path,
    query: {
      ...(next === 'mine' ? {} : { tab: next }),
      ...(detailTravelId.value !== null ? { travel_id: String(detailTravelId.value) } : {}),
    },
  })
}

function onSuccess(text: string): void {
  message.value = text
}

function openTravelFromReport(travelId: number): void {
  if (!canRead.value || travelId <= 0) return
  detailTravelId.value = travelId
  tab.value = 'requests'
  void router.replace({ path: route.path, query: { tab: 'requests', travel_id: String(travelId) } })
}

function clearDetailTravelId(): void {
  if (detailTravelId.value === null) return
  detailTravelId.value = null
  const query = { ...route.query }
  delete query.travel_id
  void router.replace({ path: route.path, query })
}

watch(
  () => [route.query.tab, route.query.travel_id, availableTabs.value.join(',')] as const,
  () => syncFromRoute(),
  { immediate: true },
)
</script>

<template>
  <div class="travel-workspace" data-page="travel" data-testid="travel-workspace">
    <header class="travel-workspace__header">
      <div>
        <p class="travel-workspace__breadcrumb">假勤与薪酬 · 出差管理</p>
        <h1 class="travel-workspace__title">出差工作台</h1>
        <p class="travel-workspace__description">
          申请、审批、政策、台账、统计与费用统一入口；审批只由真实 Workflow 待办完成，考勤仅接收已批准行程的日期关联。
        </p>
      </div>
      <div class="travel-workspace__header-actions">
        <span v-if="detailTravelId !== null" class="travel-workspace__detail-flag" data-testid="travel-detail-query">
          明细细单 #{{ detailTravelId }}
          <button type="button" class="travel-workspace__link" @click="clearDetailTravelId">清除</button>
        </span>
      </div>
    </header>

    <section v-if="!canRead" class="travel-workspace__state" data-testid="travel-forbidden" role="status">
      <h2>无权查看出差</h2>
      <p>当前账号没有 <code>time.travel.read</code> 权限。请通过角色授权申请后重试。</p>
    </section>

    <template v-else>
      <p v-if="message" class="travel-workspace__success" role="status" data-testid="travel-success">{{ message }}</p>

      <nav class="travel-workspace__tabs" data-testid="travel-tabs" aria-label="出差工作台标签">
        <button
          v-for="key in availableTabs"
          :key="key"
          type="button"
          class="travel-workspace__tab"
          :class="{ 'travel-workspace__tab--active': tab === key }"
          :aria-selected="tab === key"
          :data-testid="tabMeta[key].testId"
          role="tab"
          @click="selectTab(key)"
        >
          {{ tabMeta[key].label }}
        </button>
      </nav>

      <TravelRequestsPanel
        v-if="tab === 'mine'"
        mode="mine"
        :can-request="canRequest"
        :can-change="canRequest"
        :can-cancel="canRequest"
        :open-travel-id="detailTravelId"
        @success="onSuccess"
        @detail-closed="clearDetailTravelId"
      />
      <TravelRequestsPanel
        v-else-if="tab === 'requests'"
        mode="requests"
        :can-request="false"
        :can-change="canRequest"
        :can-cancel="canRequest"
        :open-travel-id="detailTravelId"
        @success="onSuccess"
        @detail-closed="clearDetailTravelId"
      />
      <TravelPoliciesPanel v-else-if="tab === 'policies'" :can-publish="canPolicyManage" @success="onSuccess" />
      <TravelApprovalPanel v-else-if="tab === 'approval'" @success="onSuccess" />
      <TravelReportPanel v-else-if="tab === 'reports'" @success="onSuccess" @open-travel="openTravelFromReport" />
      <TravelExpensesPanel v-else-if="tab === 'expenses'" :can-submit="canExpenseManage" @success="onSuccess" />
    </template>
  </div>
</template>

<style scoped>
.travel-workspace { display: grid; gap: 16px; padding: 4px; }
.travel-workspace__header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.travel-workspace__breadcrumb { margin: 0 0 4px; color: #64748b; font-size: 11px; }
.travel-workspace__title { margin: 0 0 5px; font-size: 22px; }
.travel-workspace__description { margin: 0; max-width: 780px; color: #475569; font-size: 12px; line-height: 1.6; }
.travel-workspace__header-actions { display: flex; gap: 8px; }
.travel-workspace__tabs { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 10px; }
.travel-workspace__tab { padding: 8px 13px; color: #475569; font-size: 12px; background: transparent; border: 1px solid transparent; border-radius: 7px; cursor: pointer; }
.travel-workspace__tab--active { color: #1d4ed8; font-weight: 600; background: #fff; border-color: #bfdbfe; box-shadow: 0 1px 2px rgb(15 23 42 / 6%); }
.travel-workspace__detail-flag { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; color: #1e3a8a; font-size: 11px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 999px; }
.travel-workspace__link { padding: 0; color: #1d4ed8; font-size: 11px; background: transparent; border: 0; cursor: pointer; text-decoration: underline; }
.travel-workspace__state { padding: 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.travel-workspace__state h2 { margin: 0 0 4px; font-size: 16px; }
.travel-workspace__state p { margin: 0; color: #64748b; font-size: 12px; }
.travel-workspace__success { margin: 0; padding: 9px 12px; color: #065f46; font-size: 11.5px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; }
@media (max-width: 720px) { .travel-workspace__header { flex-direction: column; } }
</style>
