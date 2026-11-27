<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ApiError } from '@/api/errors'
import { formatBusinessDate, formatDateTimeInTimezone, formatMoney } from '@/utils/format'
import RequisitionBoardPanel from './RequisitionBoardPanel.vue'
import RequisitionBudgetPanel from './RequisitionBudgetPanel.vue'
import RequisitionReportsPanel from './RequisitionReportsPanel.vue'
import {
  OPENING_CHANNELS,
  listRequisitionOpenings,
  publishRequisitionOpening,
  type Requisition,
  type RequisitionOpening,
} from '../api'

const route = useRoute()

const props = defineProps<{
  modelValue: boolean
  requisition: Requisition | null
  canManage: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [open: boolean]
}>()

type TabKey = 'overview' | 'approval' | 'openings' | 'budget' | 'board' | 'reports'

const activeTab = ref<TabKey>('overview')
const openings = ref<RequisitionOpening[]>([])
const openingsLoading = ref(false)
const openingsError = ref('')
const openingChannel = ref('EXTERNAL')
const openingSaving = ref(false)

const tabs: ReadonlyArray<{ key: TabKey; label: string }> = [
  { key: 'overview', label: '概览' },
  { key: 'approval', label: '审批' },
  { key: 'openings', label: '岗位开放' },
  { key: 'budget', label: '预算与编制' },
  { key: 'board', label: '进度看板' },
  { key: 'reports', label: '招聘统计' },
]

const workflowHref = computed(() =>
  props.requisition?.caseId === null || props.requisition?.caseId === undefined
    ? null
    : `/workflow/cases/${props.requisition.caseId}`,
)

async function loadOpenings(): Promise<void> {
  if (props.requisition === null) return
  openingsLoading.value = true
  openingsError.value = ''

  try {
    openings.value = await listRequisitionOpenings(props.requisition.id)
  } catch (error) {
    openings.value = []
    openingsError.value = error instanceof ApiError && error.message !== ''
      ? error.message
      : '岗位开放记录加载失败。'
  } finally {
    openingsLoading.value = false
  }
}

async function publishOpening(): Promise<void> {
  if (props.requisition === null || !props.canManage) return
  openingSaving.value = true
  openingsError.value = ''

  try {
    await publishRequisitionOpening(props.requisition.id, openingChannel.value)
    await loadOpenings()
  } catch (error) {
    openingsError.value = error instanceof ApiError && error.message !== ''
      ? error.message
      : '岗位开放未完成，请重试。'
  } finally {
    openingSaving.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const requested = typeof route.query.tab === 'string' ? route.query.tab : 'overview'
    activeTab.value = tabs.some((tab) => tab.key === requested) ? (requested as TabKey) : 'overview'
    openings.value = []
    openingsError.value = ''
    void loadOpenings()
  },
)

watch(activeTab, (tab) => {
  if (tab === 'openings' && openings.value.length === 0 && !openingsLoading.value) {
    void loadOpenings()
  }
})

function channelLabel(value: string): string {
  return OPENING_CHANNELS.find((entry) => entry.value === value)?.label ?? value
}
</script>

<template>
  <el-drawer
    :model-value="modelValue"
    size="760px"
    :with-header="false"
    data-testid="requisition-detail-drawer"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <section v-if="requisition !== null" class="requisition-detail" data-testid="requisition-detail-tabs">
      <header class="requisition-detail__header">
        <div>
          <p class="requisition-detail__eyebrow">{{ requisition.requisitionNo }}</p>
          <h2>{{ requisition.title }}</h2>
          <p class="requisition-detail__muted">
            {{ requisition.orgUnitName ?? `组织单元 #${requisition.orgUnitId}` }} ·
            {{ requisition.statusLabel }} · 编制 {{ requisition.headcount }} 人
          </p>
        </div>
        <button type="button" class="requisition-detail__close" @click="emit('update:modelValue', false)">关闭</button>
      </header>

      <el-tabs v-model="activeTab">
        <el-tab-pane v-for="tab in tabs" :key="tab.key" :label="tab.label" :name="tab.key" />
      </el-tabs>

      <section v-if="activeTab === 'overview'" data-testid="requisition-tab-overview">
        <dl class="requisition-detail__facts">
          <div><dt>需求编号</dt><dd>{{ requisition.requisitionNo }}</dd></div>
          <div><dt>岗位</dt><dd>{{ requisition.title }}</dd></div>
          <div><dt>组织单元</dt><dd>{{ requisition.orgUnitName ?? `组织单元 #${requisition.orgUnitId}` }}</dd></div>
          <div><dt>状态</dt><dd>{{ requisition.statusLabel }}</dd></div>
          <div><dt>编制</dt><dd>{{ requisition.headcount }} 人</dd></div>
          <div>
            <dt>预算</dt>
            <dd>{{ requisition.budgetMinor === null ? '未设置' : formatMoney(requisition.budgetMinor) }}</dd>
          </div>
          <div><dt>目标到岗</dt><dd>{{ requisition.targetStartDate === null ? '未设置' : formatBusinessDate(requisition.targetStartDate) }}</dd></div>
          <div><dt>开启时间</dt><dd>{{ requisition.openedAt === null ? '—' : formatDateTimeInTimezone(requisition.openedAt) }}</dd></div>
        </dl>
      </section>

      <section v-else-if="activeTab === 'approval'" data-testid="requisition-tab-approval">
        <h3>审批</h3>
        <p v-if="workflowHref === null" class="requisition-detail__muted">该需求尚未建立审批案件。</p>
        <p v-else>
          审批案件已建立。
          <a :href="workflowHref" data-testid="requisition-approval-case-link">查看审批案件</a>
        </p>
        <p class="requisition-detail__muted">审批由被指派的用人经理在审批中心完成；当前页面只读，不提交决定。</p>
      </section>

      <section v-else-if="activeTab === 'openings'" data-testid="requisition-tab-openings">
        <h3>岗位开放</h3>
        <p v-if="requisition.status !== 'APPROVED'" class="requisition-detail__muted">
          只有已批准需求可以发布岗位。当前状态：{{ requisition.statusLabel }}。
        </p>
        <form v-if="canManage" class="requisition-detail__inline" @submit.prevent="publishOpening">
          <label>
            <span>渠道</span>
            <el-select v-model="openingChannel" data-testid="opening-channel">
              <el-option v-for="channel in OPENING_CHANNELS" :key="channel.value" :value="channel.value" :label="channel.label" />
            </el-select>
          </label>
          <button type="submit" class="requisition-detail__primary" :disabled="openingSaving || requisition.status !== 'APPROVED'" data-testid="opening-submit">
            {{ openingSaving ? '发布中…' : '发布岗位' }}
          </button>
        </form>
        <p v-if="openingsError !== ''" class="requisition-detail__error" role="alert">{{ openingsError }}</p>
        <p v-if="openingsLoading" class="requisition-detail__muted">正在加载岗位开放记录。</p>
        <p v-else-if="openings.length === 0" class="requisition-detail__muted">还没有已发布的岗位渠道。</p>
        <ul v-else class="requisition-detail__list">
          <li v-for="opening in openings" :key="opening.id" :data-testid="`opening-row-${opening.id}`">
            <strong>{{ channelLabel(opening.channel) }}</strong>
            <span>{{ opening.status }}</span>
            <span>{{ opening.publishedAt === null ? '' : formatDateTimeInTimezone(opening.publishedAt) }}</span>
          </li>
        </ul>
      </section>

      <RequisitionBudgetPanel v-else-if="activeTab === 'budget'" />
      <RequisitionBoardPanel v-else-if="activeTab === 'board'" />
      <RequisitionReportsPanel v-else-if="activeTab === 'reports'" />
    </section>
  </el-drawer>
</template>

<style scoped>
.requisition-detail { display: flex; flex-direction: column; gap: 12px; }
.requisition-detail__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.requisition-detail__header h2 { margin: 2px 0; font-size: 18px; }
.requisition-detail__eyebrow { margin: 0; color: #64748b; font-family: monospace; font-size: 12px; }
.requisition-detail__muted { margin: 4px 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.requisition-detail__close { height: 30px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; cursor: pointer; }
.requisition-detail__facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin: 0; }
.requisition-detail__facts dt { color: #64748b; font-size: 11.5px; }
.requisition-detail__facts dd { margin: 4px 0 0; color: #0f172a; font-size: 13px; }
.requisition-detail__inline { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; margin: 10px 0; }
.requisition-detail__inline label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #475569; }
.requisition-detail__primary { height: 32px; padding: 0 12px; border: 1px solid #1d4ed8; border-radius: 7px; background: #1d4ed8; color: #fff; cursor: pointer; }
.requisition-detail__primary:disabled { opacity: 0.55; cursor: not-allowed; }
.requisition-detail__error { color: #b91c1c; font-size: 12px; }
.requisition-detail__list { display: flex; flex-direction: column; gap: 6px; margin: 8px 0; padding: 0; list-style: none; }
.requisition-detail__list li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12.5px; }
</style>
