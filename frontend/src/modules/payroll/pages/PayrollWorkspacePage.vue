<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import PayrollBatchesPanel from '../components/PayrollBatchesPanel.vue'
import PayrollConfigPanel from '../components/PayrollConfigPanel.vue'
import PayrollPayslipsPanel from '../components/PayrollPayslipsPanel.vue'
import PayrollReportsPanel from '../components/PayrollReportsPanel.vue'

defineOptions({ name: 'PayrollWorkspacePage' })

type PayrollTab = 'batches' | 'components' | 'payslips' | 'reports'
type ConfigSection = 'items' | 'plans' | 'compensations'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('payroll.run.read'))
const canPayslip = computed(() => session.can('payroll.payslip.read.self'))
const canReport = computed(() => session.can('payroll.report.read'))

const TABS: ReadonlyArray<{ key: PayrollTab; label: string }> = [
  { key: 'batches', label: '批次' },
  { key: 'components', label: '配置' },
  { key: 'payslips', label: '工资条' },
  { key: 'reports', label: '报表' },
]

const CONFIG_ALIASES: Readonly<Record<string, ConfigSection>> = {
  components: 'items',
  plans: 'plans',
  'employee-plans': 'compensations',
  adjustments: 'compensations',
}

function requestedTab(value: unknown): PayrollTab | null {
  if (typeof value !== 'string') return null
  if (TABS.some((tab) => tab.key === value)) return value as PayrollTab
  return value in CONFIG_ALIASES ? 'components' : null
}

function requestedConfigSection(value: unknown): ConfigSection {
  return typeof value === 'string' && CONFIG_ALIASES[value] !== undefined
    ? CONFIG_ALIASES[value]
    : 'items'
}

function isTabVisible(tab: PayrollTab): boolean {
  if (tab === 'payslips') return canPayslip.value
  if (tab === 'reports') return canReport.value
  return true
}

const activeTab = ref<PayrollTab>(requestedTab(route.query.tab) ?? 'batches')
const activeConfigSection = ref<ConfigSection>(requestedConfigSection(route.query.tab))
const visibleTabs = computed(() => TABS.filter((tab) => isTabVisible(tab.key)))
const currentTab = computed<PayrollTab>(() =>
  visibleTabs.value.some((tab) => tab.key === activeTab.value)
    ? activeTab.value
    : (visibleTabs.value[0]?.key ?? 'batches'),
)

function selectTab(tab: PayrollTab): void {
  activeTab.value = tab
  void router.replace({ query: { ...route.query, tab } })
}

function openStatutory(): void {
  void router.push('/benefits?tab=statutory')
}

watch(
  () => route.query.tab,
  (value) => {
    const next = requestedTab(value)
    if (next !== null && isTabVisible(next)) {
      activeTab.value = next
      if (next === 'components') activeConfigSection.value = requestedConfigSection(value)
    }
  },
)

watch(visibleTabs, (tabs) => {
  if (!tabs.some((tab) => tab.key === activeTab.value)) {
    activeTab.value = tabs[0]?.key ?? 'batches'
  }
})

onMounted(() => {
  const requested = requestedTab(route.query.tab)
  if (requested !== null && isTabVisible(requested)) {
    activeTab.value = requested
  }
})
</script>

<template>
  <div class="payroll-workspace" data-page="payroll-workspace">
    <header class="payroll-workspace__header">
      <div>
        <p class="payroll-workspace__breadcrumb">薪酬 · 工资核算</p>
        <h1 class="payroll-workspace__title">薪资工作台</h1>
        <p class="payroll-workspace__description">
          在一个工作台完成薪资配置、批次核算、工资条与汇总报表。金额由服务端以整数分返回，
          费率保持十进制字符串；准备、复核、批准与发布各自独立授权。
        </p>
      </div>
    </header>

    <section v-if="!canRead" class="payroll-workspace__state" role="alert" data-testid="payroll-forbidden">
      <h2>无权访问薪资工作台</h2>
      <p>当前账号缺少 payroll.run.read 能力，页面不会发起任何工资批次读取请求。</p>
    </section>

    <template v-else>
      <nav class="payroll-workspace__tabs" aria-label="薪资工作台分类" role="tablist">
        <button
          v-for="tab in visibleTabs"
          :key="tab.key"
          type="button"
          role="tab"
          :aria-selected="currentTab === tab.key"
          :data-testid="`payroll-tab-${tab.key}`"
          @click="selectTab(tab.key)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <PayrollBatchesPanel v-if="currentTab === 'batches'" />
      <PayrollConfigPanel
        v-else-if="currentTab === 'components'"
        :initial-tab="activeConfigSection"
        @open-statutory="openStatutory"
      />
      <PayrollPayslipsPanel v-else-if="currentTab === 'payslips'" />
      <PayrollReportsPanel v-else-if="currentTab === 'reports'" />
    </template>
  </div>
</template>

<style scoped>
.payroll-workspace {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 24px;
}

.payroll-workspace__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.payroll-workspace__breadcrumb {
  margin: 0;
  color: #64748b;
  font-size: 11.5px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.payroll-workspace__title {
  margin: 6px 0 0;
  font-size: 24px;
}

.payroll-workspace__description {
  max-width: 860px;
  margin: 8px 0 0;
  color: #475569;
  font-size: 12.5px;
  line-height: 1.65;
}

.payroll-workspace__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 6px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-workspace__tabs button {
  padding: 8px 18px;
  color: #475569;
  font-size: 12.5px;
  font-weight: 600;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 8px;
  cursor: pointer;
}

.payroll-workspace__tabs button[aria-selected='true'] {
  color: #1d4ed8;
  background: #eff6ff;
  border-color: #bfdbfe;
}

.payroll-workspace__state {
  padding: 20px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.payroll-workspace__state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.payroll-workspace__state p {
  margin: 0;
  color: #475569;
  font-size: 12px;
}
</style>
