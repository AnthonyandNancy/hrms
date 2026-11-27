<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError } from '@/api/errors'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'
import LoadingState from '@/components/LoadingState.vue'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone, formatMoney, formatMonthLabel } from '@/utils/format'
import MyProfile from '../components/MyProfile.vue'
import MyTasks from '../components/MyTasks.vue'
import {
  loadMyTasks,
  loadSelfServiceWorkspace,
  type SelfServiceTask,
  type SelfServiceWorkspace,
} from '../api'

const session = useSessionStore()
const pageError = usePageError()

const canAccess = computed(() => session.can('iam.session.manage'))
const canReadWorkflow = computed(() => session.can('workflow.case.read'))
const canReadPayslips = computed(() => session.can('payroll.payslip.read.self'))

const loading = ref(false)
const workspace = ref<SelfServiceWorkspace | null>(null)
const tasks = ref<SelfServiceTask[]>([])
const tasksLoading = ref(false)
const tasksError = ref<{ message: string; requestId: string | null } | null>(null)

type Entry = {
  key: string
  label: string
  description: string
  capability: string
  routeCapability?: string
  disabledReason?: string
  path?: string
  pageCode?: string
  anchor?: string
}

const ENTRIES: readonly Entry[] = [
  {
    key: 'attendance',
    label: '我的考勤',
    description: '查看本人打卡、考勤异常与本月出勤汇总。',
    capability: 'time.attendance.read',
    path: '/attendance',
    pageCode: 'attendance',
  },
  {
    key: 'leave',
    label: '我的请假',
    description: '查看本人假期余额与请假申请，并提交新的请假申请。',
    capability: 'time.leave.read',
    path: '/leave',
    pageCode: 'leave',
  },
  {
    key: 'overtime',
    label: '我的加班',
    description: '查看本人加班记录与调休余额，发起或确认本人加班。',
    capability: 'time.overtime.read',
    path: '/overtime',
    pageCode: 'overtime',
  },
  {
    key: 'payslips',
    label: '我的工资条',
    description: '查看本人已发布工资条；金额按字段权限显示。',
    capability: 'payroll.payslip.read.self',
    routeCapability: 'payroll.run.read',
    disabledReason: '工资条页面仅对薪酬角色开放；当前角色只能在此查看最近工资条。',
    anchor: 'my-payslips',
  },
  {
    key: 'training',
    label: '我的培训',
    description: '查看本人培训项目、报名与培训记录。',
    capability: 'training.program.read',
    path: '/training',
    pageCode: 'training',
  },
]

const visibleEntries = computed(() =>
  ENTRIES.filter((entry) => session.can(entry.capability)).map((entry) => {
    const pageGranted = entry.pageCode === undefined || session.canPage(entry.pageCode)
    const routeCapabilityGranted = entry.routeCapability === undefined || session.can(entry.routeCapability)
    const enabled = pageGranted && routeCapabilityGranted
    const disabledReason = !routeCapabilityGranted
      ? entry.disabledReason ?? `当前角色缺少“${entry.label}”页面所需能力，无法进入。`
      : pageGranted
        ? ''
        : `当前角色未分配“${entry.label}”页面，请联系管理员调整角色页面权限。`

    return {
      ...entry,
      enabled,
      disabledReason,
    }
  }),
)

const pageStatus = computed(() => pageError.feedback.value.kind)
const showForbidden = computed(() => !canAccess.value || pageStatus.value === 'forbidden')
const showWorkspaceError = computed(
  () => canAccess.value && workspace.value === null && pageError.error.value !== null && !showForbidden.value,
)
const showEmpty = computed(
  () => !loading.value && workspace.value !== null && (!workspace.value.employmentLinked || workspace.value.profile === null),
)
const showContent = computed(() => !loading.value && workspace.value !== null && !showEmpty.value)
const isPartial = computed(() => showContent.value && tasksError.value !== null)

function requestIdOf(reason: unknown): string | null {
  return reason instanceof ApiError ? reason.requestId : null
}

function taskErrorMessage(reason: unknown): string {
  if (reason instanceof ApiError && reason.code === 'FORBIDDEN') {
    return '无权查看待办列表。当前账号没有流程查看能力。'
  }
  return '待办列表暂时无法加载，请稍后重试。'
}

async function load(): Promise<void> {
  if (!canAccess.value) {
    loading.value = false
    return
  }

  loading.value = true
  pageError.clear()
  workspace.value = null
  tasks.value = []
  tasksError.value = null

  const workspaceRead = loadSelfServiceWorkspace()
  const tasksRead = canReadWorkflow.value ? loadMyTasks() : Promise.resolve<SelfServiceTask[]>([])
  tasksLoading.value = canReadWorkflow.value

  const [workspaceResult, tasksResult] = await Promise.allSettled([workspaceRead, tasksRead])

  if (workspaceResult.status === 'fulfilled') {
    workspace.value = workspaceResult.value
  } else {
    pageError.reject(workspaceResult.reason)
  }

  if (tasksResult.status === 'fulfilled') {
    tasks.value = tasksResult.value
  } else {
    tasksError.value = {
      message: taskErrorMessage(tasksResult.reason),
      requestId: requestIdOf(tasksResult.reason),
    }
  }

  tasksLoading.value = false
  loading.value = false
}

function retryTasks(): void {
  if (!canReadWorkflow.value) return

  tasksLoading.value = true
  tasksError.value = null
  loadMyTasks()
    .then((result) => {
      tasks.value = result
    })
    .catch((reason: unknown) => {
      tasksError.value = {
        message: taskErrorMessage(reason),
        requestId: requestIdOf(reason),
      }
    })
    .finally(() => {
      tasksLoading.value = false
    })
}

onMounted(load)
</script>

<template>
  <div class="page self-service-page" data-testid="self-service-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">员工自助 · 仅本人</p>
        <h1 class="page-header__title">员工自助门户</h1>
        <p class="page-header__description">
          处理属于你的待办与个人资料。所有内容均按当前登录账号过滤；不提供员工选择器，也不展示其他员工的数据。
        </p>
      </div>
      <div v-if="workspace?.businessDate" class="page-header__actions">
        <span class="plain-chip">业务日期 {{ formatBusinessDate(workspace.businessDate) }}</span>
      </div>
    </header>

    <LoadingState v-if="loading" :rows="7" label="正在加载员工自助数据" />

    <section
      v-else-if="showForbidden"
      class="panel self-service-state"
      role="alert"
      data-testid="self-service-forbidden"
    >
      <div class="panel__body">
        <h2 class="panel__title">无权查看员工自助门户</h2>
        <p class="muted">
          当前账号没有员工自助访问能力。自助门户只对已登录且拥有本人会话能力的账号开放；如需使用，请联系系统管理员。
        </p>
        <p v-if="pageError.requestId.value" class="state-request">
          请求编号：<span class="mono">{{ pageError.requestId.value }}</span>
        </p>
      </div>
    </section>

    <section
      v-else-if="showWorkspaceError"
      class="panel self-service-state"
      role="alert"
      data-testid="self-service-error"
    >
      <div class="panel__body">
        <h2 class="panel__title">员工自助门户暂时无法加载</h2>
        <p class="muted">本人档案与自助入口读取失败。请稍后重试；若多次失败，请将请求编号提供给系统管理员。</p>
        <p v-if="pageError.requestId.value" class="state-request">
          请求编号：<span class="mono">{{ pageError.requestId.value }}</span>
        </p>
        <el-button type="primary" plain data-testid="self-service-retry" @click="load">重新加载</el-button>
      </div>
    </section>

    <template v-else-if="showEmpty && workspace">
      <NoActiveEmploymentState
        title="当前账号未绑定员工"
        description="员工自助只展示本人数据；当前账号没有有效任职，因此档案、考勤、假期、加班、工资条和培训均不可用。请联系人事管理员完成任职关联。"
      />
      <MyTasks
        :tasks="tasks"
        :loading="tasksLoading"
        :can-read-workflow="canReadWorkflow"
        :error-message="tasksError?.message ?? ''"
        :error-request-id="tasksError?.requestId ?? null"
        @retry="retryTasks"
      />
    </template>

    <template v-else-if="showContent && workspace">
      <p v-if="isPartial" class="partial-banner" role="status" data-testid="self-service-partial">
        部分数据暂不可用：个人信息已加载，但待办列表暂时无法加载。
      </p>

      <div class="self-service-grid">
        <MyProfile :profile="workspace.profile" :employment-linked="workspace.employmentLinked" />
        <MyTasks
          :tasks="tasks"
          :loading="tasksLoading"
          :can-read-workflow="canReadWorkflow"
          :error-message="tasksError?.message ?? ''"
          :error-request-id="tasksError?.requestId ?? null"
          @retry="retryTasks"
        />
      </div>

      <section class="panel self-entries" aria-labelledby="self-entries-title" data-testid="self-entries">
        <div class="panel__header">
          <div>
            <h2 id="self-entries-title" class="panel__title">我的自助入口</h2>
            <p class="panel__subtitle">缺少能力的入口不会显示；角色未分配页面的入口会禁用并说明原因，不提供会 403 的链接。</p>
          </div>
        </div>
        <div class="entry-grid">
          <RouterLink
            v-for="entry in visibleEntries.filter((candidate) => candidate.enabled && candidate.path !== undefined)"
            :key="entry.key"
            :to="entry.path ?? '/'"
            class="entry-card"
            :data-testid="`self-entry-${entry.key}`"
          >
            <span class="entry-card__label">{{ entry.label }}</span>
            <span class="entry-card__description">{{ entry.description }}</span>
            <span class="entry-card__action">进入 →</span>
          </RouterLink>

          <a
            v-for="entry in visibleEntries.filter((candidate) => candidate.enabled && candidate.anchor !== undefined)"
            :key="entry.key"
            :href="`#${entry.anchor}`"
            class="entry-card"
            :data-testid="`self-entry-${entry.key}`"
          >
            <span class="entry-card__label">{{ entry.label }}</span>
            <span class="entry-card__description">{{ entry.description }}</span>
            <span class="entry-card__action">查看 →</span>
          </a>

          <div
            v-for="entry in visibleEntries.filter((candidate) => !candidate.enabled)"
            :key="entry.key"
            class="entry-card entry-card--disabled"
            :data-testid="`self-entry-${entry.key}`"
            aria-disabled="true"
          >
            <span class="entry-card__label">{{ entry.label }}</span>
            <span class="entry-card__description">{{ entry.disabledReason }}</span>
            <span class="entry-card__action">不可进入</span>
          </div>
        </div>
      </section>

      <section
        v-if="canReadPayslips"
        id="my-payslips"
        class="panel self-payslips"
        aria-labelledby="my-payslips-title"
        data-testid="my-payslips"
      >
        <div class="panel__header">
          <div>
            <h2 id="my-payslips-title" class="panel__title">我的工资条</h2>
            <p class="panel__subtitle">仅显示服务端投影给本人的已发布工资条；金额缺失时显示“无权查看金额”，不会以零替代。</p>
          </div>
        </div>
        <ul v-if="workspace.payslips.length > 0" class="payslip-list">
          <li v-for="slip in workspace.payslips" :key="slip.id" class="payslip-list__item" :data-testid="`self-payslip-${slip.id}`">
            <div>
              <p class="payslip-list__period">{{ formatMonthLabel(slip.period) }}</p>
              <p class="payslip-list__meta">
                {{ slip.state === 'CORRECTION' ? '更正批次' : slip.state === 'PAID' ? '已发放' : '已发布' }}
                <template v-if="slip.payDate"> · 发放日 {{ formatBusinessDate(slip.payDate) }}</template>
              </p>
            </div>
            <span class="payslip-list__amount">
              {{ slip.netCents === null ? '无权查看金额' : `实发 ${formatMoney(slip.netCents)}` }}
            </span>
          </li>
        </ul>
        <div v-else class="panel__body">
          <p class="muted">还没有已发布工资条。薪酬专员发布后，这里会显示本人的工资条。</p>
        </div>
      </section>

      <p v-if="workspace.freshness" class="self-service-freshness" role="note">
        数据更新时间：{{ formatDateTimeInTimezone(workspace.freshness) }}
      </p>
    </template>
  </div>
</template>

<style scoped>
.self-service-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.self-service-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.self-service-state .panel__body {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
}

.state-request {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}

.partial-banner {
  margin: 0;
  padding: 10px 14px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  background: #fff8e6;
  border: 1px solid #f2d58a;
  border-radius: var(--radius-sm);
}

.entry-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  padding: 16px 18px;
}

.entry-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 14px;
  color: inherit;
  text-decoration: none;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.entry-card:hover,
.entry-card:focus-visible {
  border-color: var(--color-brand, #1d6a73);
  box-shadow: var(--shadow-soft);
}

.entry-card__label {
  color: var(--color-ink);
  font-size: 14px;
  font-weight: 680;
}

.entry-card__description {
  color: var(--color-ink-muted);
  font-size: 12px;
  line-height: 1.65;
}

.entry-card__action {
  margin-top: auto;
  color: var(--color-brand-deep, #0f4956);
  font-size: 12px;
  font-weight: 600;
}

.entry-card--disabled {
  color: var(--color-ink-muted);
  background: #f6f7f7;
  border-style: dashed;
}

.entry-card--disabled .entry-card__label,
.entry-card--disabled .entry-card__action {
  color: var(--color-ink-muted);
}

.payslip-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.payslip-list__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 13px 18px;
  border-top: 1px solid var(--color-line);
}

.payslip-list__item:first-child {
  border-top: 0;
}

.payslip-list__period {
  margin: 0;
  color: var(--color-ink);
  font-size: 14px;
  font-weight: 650;
}

.payslip-list__meta {
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}

.payslip-list__amount {
  color: var(--color-ink);
  font-size: 13px;
  font-weight: 650;
  white-space: nowrap;
}

.self-service-freshness {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}

@media (max-width: 960px) {
  .self-service-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
