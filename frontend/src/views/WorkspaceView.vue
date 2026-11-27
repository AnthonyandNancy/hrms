<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import { resourceGet } from '@/api/resourceClient'
import { ApiError } from '@/api/errors'
import type { WorkspaceProjection } from '@/api/contracts'
import { usePageError } from '@/composables/usePageError'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'
import { formatBusinessDate, formatDateTimeInTimezone, formatMonthLabel } from '@/utils/format'
import MoneyText from '@/components/MoneyText.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import FreshnessNote from '@/components/FreshnessNote.vue'
import MyTasks from '@/modules/platform/self-service/components/MyTasks.vue'
import { loadMyTasks, type SelfServiceTask } from '@/modules/platform/self-service/api'
import { translate } from '@/shared/display/dictionary'
import { INTERNAL_DETAIL_ROUTES, INTERNAL_ROUTES } from '@/shared/navigation/internalRoutes'

const router = useRouter()
const session = useSessionStore()
const loading = ref(true)
const pageError = usePageError()
const data = ref<WorkspaceProjection | null>(null)
const tasks = ref<SelfServiceTask[]>([])
const tasksLoading = ref(false)
const tasksError = ref<{ message: string; requestId: string | null } | null>(null)
const canReadWorkflow = computed(() => session.can('workflow.case.read'))

function requestIdOf(reason: unknown): string | null {
  return reason instanceof ApiError ? reason.requestId : null
}

function taskErrorMessage(reason: unknown): string {
  if (reason instanceof ApiError && reason.code === 'FORBIDDEN') {
    return '无权查看待办列表。当前账号没有流程查看能力。'
  }
  return '待办列表暂时无法加载，请稍后重试。'
}

async function loadTasks(): Promise<void> {
  if (!canReadWorkflow.value) {
    tasks.value = []
    tasksError.value = null
    tasksLoading.value = false
    return
  }

  tasksLoading.value = true
  tasksError.value = null
  try {
    tasks.value = await loadMyTasks()
  } catch (reason) {
    tasks.value = []
    tasksError.value = {
      message: taskErrorMessage(reason),
      requestId: requestIdOf(reason),
    }
  } finally {
    tasksLoading.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    data.value = await resourceGet<WorkspaceProjection>('/v1/me/workspace')
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
  await loadTasks()
}

const annualBalance = computed(() => {
  return (
    data.value?.leaveBalances.find((balance) => balance.leaveTypeCode === 'ANNUAL') ??
    data.value?.leaveBalances[0] ??
    null
  )
})

function go(path: string): void {
  void router.push(path)
}

onMounted(load)
</script>

<template>
  <div class="page workspace">
    <header class="workspace-hero">
      <div class="workspace-hero__identity">
        <span class="workspace-hero__avatar" aria-hidden="true">{{ data?.profile.initials ?? '—' }}</span>
        <div>
          <p class="workspace-hero__eyebrow">
            我的工作台 · {{ formatBusinessDate(data?.businessDate ?? '') }}
          </p>
          <h1 class="workspace-hero__title">{{ data ? `${data.profile.name}，下午好` : '我的工作台' }}</h1>
          <p class="workspace-hero__meta">
            {{ data?.profile.jobTitle ?? '岗位待维护' }} · {{ data?.profile.orgName ?? '组织待维护' }} · 工号
            {{ data?.profile.employeeNo || '—' }}
          </p>
        </div>
      </div>
      <div class="workspace-hero__actions">
        <el-button
          v-if="data === null || data.employment_linked !== false"
          type="primary"
          data-testid="workspace-start-leave"
          @click="go(INTERNAL_ROUTES.leave)"
        >
          发起请假
        </el-button>
        <el-button
          v-if="data === null || data.employment_linked !== false"
          data-testid="workspace-payslip-link"
          @click="go(INTERNAL_ROUTES.payroll)"
        >
          查看工资条
        </el-button>
      </div>
    </header>

    <NoActiveEmploymentState
      v-if="pageError.noActiveEmployment.value"
      :request-id="pageError.requestId.value"
      :links="pageError.safeLinks.value"
    />
    <el-alert v-else-if="pageError.message.value" type="error" :title="pageError.message.value" show-icon :closable="false">
      <el-button text size="small" @click="load">重试</el-button>
      <span v-if="pageError.requestId.value" class="mono">请求编号：{{ pageError.requestId.value }}</span>
    </el-alert>

    <el-skeleton v-if="loading" :rows="8" animated />

    <template v-else-if="data">
      <NoActiveEmploymentState
        v-if="data.employment_linked === false"
        data-testid="workspace-no-employment"
        title="当前账号未绑定员工"
        description="你仍可处理管理待办。本人请假、考勤、工资条和培训入口在完成任职关联后才可用。"
        :links="[{ label: '查看我的待办', path: INTERNAL_ROUTES.workspace }]"
      />

      <MyTasks
        v-if="canReadWorkflow"
        data-testid="workspace-tasks"
        :tasks="tasks"
        :loading="tasksLoading"
        :can-read-workflow="canReadWorkflow"
        :error-message="tasksError?.message ?? ''"
        :error-request-id="tasksError?.requestId ?? null"
        @retry="loadTasks"
      />

      <div v-if="data.employment_linked !== false" class="workspace-attention">
        <button
          v-if="data.attendanceException"
          class="attention-card attention-card--warning"
          type="button"
          @click="go(INTERNAL_ROUTES.attendance)"
        >
          <span class="attention-card__kicker">
            待处理 · {{ formatBusinessDate(data.attendanceException.date) }}
          </span>
          <strong>{{ data.attendanceException.label }}</strong>
          <span>考勤异常需要补正后才能进入工资计算。</span>
          <span class="attention-card__action">去补正 →</span>
        </button>
        <button v-else class="attention-card" type="button" @click="go(INTERNAL_ROUTES.attendance)">
          <span class="attention-card__kicker">本月考勤</span>
          <strong>暂无待处理异常</strong>
          <span>
            本月已记录出勤 {{ data.attendanceSummary.presentDays }} 天，异常以打卡与日历为准。
          </span>
          <span class="attention-card__action">查看考勤 →</span>
        </button>
        <button class="attention-card" type="button" @click="go(INTERNAL_ROUTES.notices)">
          <span class="attention-card__kicker">未读公告</span>
          <strong>{{ data.notices.filter((notice) => !notice.acknowledged).length }} 条需确认</strong>
          <span>需确认公告会进入待办；公告可见范围由受众规则决定。</span>
          <span class="attention-card__action">查看公告 →</span>
        </button>
        <button v-if="annualBalance" class="attention-card" type="button" @click="go(INTERNAL_ROUTES.leave)">
          <span class="attention-card__kicker">{{ annualBalance.leaveTypeName }}</span>
          <strong>{{ annualBalance.available }} {{ annualBalance.unit === 'DAY' ? '天可用' : '小时可用' }}</strong>
          <span>
            发放 {{ annualBalance.granted }} · 已用 {{ annualBalance.used }} · 待占用
            {{ annualBalance.pending }}
          </span>
          <span class="attention-card__action">查看余额明细 →</span>
        </button>
        <button v-else class="attention-card" type="button" @click="go(INTERNAL_ROUTES.leave)">
          <span class="attention-card__kicker">假期余额</span>
          <strong>尚无假期账户</strong>
          <span>假期余额来自不可变账本，需先开通账户并发放额度。</span>
          <span class="attention-card__action">查看假期 →</span>
        </button>
      </div>

      <div v-if="data.employment_linked !== false" class="workspace-grid">
        <section class="panel workspace-profile">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的档案</h2>
              <p class="panel__subtitle">当前有效任职与合同摘要</p>
            </div>
            <el-button
              v-if="data.profile.employmentId"
              text
              size="small"
              @click="go(INTERNAL_DETAIL_ROUTES.employee(data.profile.employmentId))"
            >
              查看完整档案
            </el-button>
          </div>
          <div class="panel__body">
            <div class="detail-grid">
              <div class="detail-grid__item">
                <span class="detail-grid__label">岗位</span>
                <span class="detail-grid__value">{{ data.profile.jobTitle ?? '—' }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">组织</span>
                <span class="detail-grid__value">{{ data.profile.orgName ?? '—' }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">直属经理</span>
                <span class="detail-grid__value">{{ data.profile.managerName ?? '—' }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">工作地点</span>
                <span class="detail-grid__value">{{ data.profile.workLocation ?? '—' }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">工作邮箱</span>
                <span class="detail-grid__value">{{ data.profile.email ?? '—' }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">手机号</span>
                <span class="detail-grid__value">{{ data.profile.phone.display }}</span>
              </div>
            </div>
          </div>
          <div v-if="data.contract" class="panel__footer workspace-contract">
            <div>
              <span class="workspace-contract__label">当前条款版本</span>
              <strong>{{ data.contract.contractNo }}</strong>
              <span class="muted"> · {{ translate('people', 'employmentType', data.contract.type).label }}</span>
            </div>
            <span class="muted small">
              {{ formatBusinessDate(data.contract.startDate) }} —
              {{ data.contract.endDate ? formatBusinessDate(data.contract.endDate) : '无固定期限' }}
            </span>
          </div>
          <div v-else class="panel__footer workspace-contract">
            <div>
              <span class="workspace-contract__label">当前条款版本</span>
              <strong>尚无生效条款</strong>
            </div>
            <span class="muted small">联系 HR 补齐合同条款后，工资与合同预警才能复算。</span>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">本月考勤</h2>
              <p class="panel__subtitle">{{ formatMonthLabel(data.attendanceMonth) }} · Asia/Shanghai</p>
            </div>
            <el-button text size="small" @click="go(INTERNAL_ROUTES.attendance)">打开日历</el-button>
          </div>
          <div class="panel__body attendance-mini">
            <div class="attendance-mini__stat">
              <strong>{{ data.attendanceSummary.presentDays }}</strong>
              <span>出勤天数</span>
            </div>
            <div class="attendance-mini__stat">
              <strong>{{ data.attendanceSummary.lateCount }}</strong>
              <span>迟到</span>
            </div>
            <div class="attendance-mini__stat">
              <strong>{{ data.attendanceSummary.absentCount }}</strong>
              <span>缺勤</span>
            </div>
            <div class="attendance-mini__stat">
              <strong>{{ data.attendanceSummary.remoteDays }}</strong>
              <span>远程</span>
            </div>
          </div>
          <div class="panel__footer">
            <span class="small muted">应出勤 {{ data.attendanceSummary.expectedDays }} 天 · 休息日不计入</span>
            <span class="muted small">数据源：考勤投影</span>
          </div>
        </section>

        <section class="panel workspace-leave">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">假期余额</h2>
              <p class="panel__subtitle">账本汇总，不直接修改余额</p>
            </div>
            <el-button text size="small" @click="go(INTERNAL_ROUTES.leave)">账本明细</el-button>
          </div>
          <ul class="balance-list">
            <li v-for="balance in data.leaveBalances" :key="balance.leaveTypeCode" class="balance-list__item">
              <div>
                <p class="balance-list__name">{{ balance.leaveTypeName }}</p>
                <p class="balance-list__meta">
                  发放 {{ balance.granted }}{{ balance.unit === 'DAY' ? ' 天' : ' 小时' }} · 已用
                  {{ balance.used }}{{ balance.unit === 'DAY' ? ' 天' : ' 小时' }}
                  <template v-if="balance.pending > 0"> · 待占用 {{ balance.pending }}</template>
                </p>
              </div>
              <div class="balance-list__value">
                <strong>{{ balance.available }}</strong>
                <span>{{ balance.unit === 'DAY' ? '天可用' : '小时可用' }}</span>
              </div>
            </li>
          </ul>
        </section>

        <section class="panel workspace-requests">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的申请</h2>
              <p class="panel__subtitle">申请状态与生效状态分开显示</p>
            </div>
            <el-button text size="small" @click="go(INTERNAL_ROUTES.workflow)">全部申请</el-button>
          </div>
          <ul class="request-list">
            <li v-for="request in data.myRequests" :key="request.case_id" class="request-list__item">
              <button type="button" class="request-list__button" @click="go(INTERNAL_DETAIL_ROUTES.workflowCase(request.case_id))">
                <span class="request-list__main">
                  <span class="request-list__title">
                    {{ request.state === 'APPROVED' ? '请假申请（已批准）' : '请假申请' }}
                  </span>
                  <span class="request-list__meta">
                    {{ formatBusinessDate(request.from_date) }} — {{ formatBusinessDate(request.to_date) }}
                  </span>
                </span>
                <span class="status-pair">
                  <StatusBadge kind="application" :status="request.state" size="sm" />
                </span>
              </button>
            </li>
          </ul>
        </section>

        <section class="panel workspace-payslips">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的工资条</h2>
              <p class="panel__subtitle">已发布工资条 · 金额为整数分计算</p>
            </div>
            <el-button text size="small" @click="go(INTERNAL_ROUTES.payroll)">查看全部</el-button>
          </div>
          <ul class="payslip-list">
            <li v-for="slip in data.payslips.slice(0, 2)" :key="slip.id" class="payslip-list__item">
              <button type="button" class="payslip-list__button" @click="go(INTERNAL_ROUTES.payroll)">
                <div>
                  <p class="payslip-list__period">{{ formatMonthLabel(slip.period) }}</p>
                  <p class="payslip-list__meta">{{ slip.state === 'CORRECTION' ? '更正批次' : `发放日 ${formatBusinessDate(slip.payDate)}` }}</p>
                </div>
                <MoneyText :cents="slip.netCents" label="实发" size="md" />
              </button>
            </li>
          </ul>
        </section>

        <section class="panel workspace-notices">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">最新公告</h2>
              <p class="panel__subtitle">需确认公告会进入待办</p>
            </div>
            <el-button text size="small" @click="go(INTERNAL_ROUTES.notices)">公告中心</el-button>
          </div>
          <ul class="notice-list" style="padding: 0 18px">
            <li v-for="notice in data.notices" :key="notice.id" class="notice-list__item">
              <button type="button" class="notice-link" @click="go(INTERNAL_ROUTES.notices)">
                <span class="notice-list__title">
                  {{ notice.title }}
                  <span v-if="!notice.acknowledged" class="plain-chip">待确认</span>
                </span>
                <span class="notice-list__meta">{{ notice.authorName }} · {{ formatDateTimeInTimezone(notice.publishedAt) }}</span>
              </button>
            </li>
          </ul>
        </section>
      </div>

      <section
        v-if="data.employment_linked !== false"
        class="panel workspace-quick"
        data-testid="workspace-quick-actions"
        aria-labelledby="workspace-quick-title"
      >
        <div class="panel__header">
          <div>
            <h2 id="workspace-quick-title" class="panel__title">快捷业务</h2>
            <p class="panel__subtitle">员工自助入口已并入工作台；缺少能力的入口仍由服务端拒绝。</p>
          </div>
        </div>
        <div class="workspace-quick__grid">
          <button type="button" class="workspace-quick__item" data-testid="quick-account" @click="go(INTERNAL_ROUTES.account)">
            <strong>账号安全</strong><span>密码、登录会话与可选双因素认证</span>
          </button>
          <button type="button" class="workspace-quick__item" data-testid="quick-overtime" @click="go(INTERNAL_ROUTES.overtime)">
            <strong>我的加班</strong><span>查看加班记录与调休余额</span>
          </button>
          <button type="button" class="workspace-quick__item" data-testid="quick-travel" @click="go(INTERNAL_ROUTES.travel)">
            <strong>我的出差</strong><span>查看出差申请与行程</span>
          </button>
          <button type="button" class="workspace-quick__item" data-testid="quick-training" @click="go(INTERNAL_ROUTES.training)">
            <strong>我的培训</strong><span>查看培训项目与报名记录</span>
          </button>
        </div>
      </section>

      <FreshnessNote :timestamp="data.freshness" />
    </template>
  </div>
</template>

<style scoped>
.workspace-hero {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 22px;
  padding: 22px 24px;
  background:
    radial-gradient(120% 160% at 100% 0%, rgb(236 199 112 / 16%), transparent 55%),
    linear-gradient(112deg, #104a57, #0c3a45);
  border-radius: var(--radius-md);
  color: #edf6f3;
}

.workspace-hero__identity {
  display: flex;
  gap: 16px;
  align-items: center;
}

.workspace-hero__avatar {
  display: grid;
  flex: 0 0 58px;
  width: 58px;
  height: 58px;
  place-items: center;
  color: #17333b;
  font-size: 24px;
  font-weight: 700;
  background: linear-gradient(140deg, #f2d48b, #d7a640);
  border-radius: 50%;
}

.workspace-hero__eyebrow {
  color: #a8c6c2;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.07em;
}

.workspace-hero__title {
  margin-top: 2px;
  font-size: 24px;
  font-weight: 680;
  letter-spacing: -0.02em;
}

.workspace-hero__meta {
  margin-top: 3px;
  color: #b5ceca;
  font-size: 12.5px;
}

.workspace-hero__actions {
  display: flex;
  gap: 8px;
}

.workspace-hero__actions :deep(.el-button:not(.el-button--primary)) {
  color: #eef6f3;
  background: rgb(255 255 255 / 7%);
  border-color: rgb(255 255 255 / 22%);
}

.workspace-attention {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.attention-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 13px 15px;
  color: var(--color-ink-secondary);
  text-align: left;
  background: #fff;
  border: 1px solid var(--color-line);
  border-left: 3px solid var(--color-line-strong);
  border-radius: var(--radius-md);
  cursor: pointer;
}

.attention-card:hover {
  border-color: #9db6b0;
}

.attention-card--warning {
  border-left-color: var(--color-warning);
  background: linear-gradient(100deg, #fffaf0, #fff);
}

.attention-card__kicker {
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.attention-card strong {
  color: var(--color-ink);
  font-size: 13.5px;
  font-weight: 650;
}

.attention-card > span:not(.attention-card__kicker, .attention-card__action) {
  font-size: 11.5px;
}

.attention-card__action {
  margin-top: 3px;
  color: var(--color-brand);
  font-size: 11px;
  font-weight: 600;
}

.workspace-quick__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 10px;
  padding: 14px 18px 18px;
}

.workspace-quick__item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 13px 14px;
  color: var(--color-ink-secondary);
  text-align: left;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.workspace-quick__item strong {
  color: var(--color-ink);
  font-size: 13px;
}

.workspace-quick__item span {
  font-size: 11.5px;
}

.workspace-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.workspace-profile,
.workspace-leave {
  grid-column: span 1;
}

.workspace-requests,
.workspace-payslips,
.workspace-notices {
  grid-column: span 1;
}

.workspace-contract {
  align-items: center;
}

.workspace-contract__label {
  display: block;
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.attendance-mini {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.attendance-mini__stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px;
  background: var(--color-canvas);
  border-radius: 6px;
}

.attendance-mini__stat strong {
  font-size: 22px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.attendance-mini__stat span {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.balance-list,
.request-list,
.payslip-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.balance-list__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 11px 18px;
  border-bottom: 1px solid #edf1ef;
}

.balance-list__item:last-child {
  border-bottom: 0;
}

.balance-list__name {
  font-size: 12.5px;
  font-weight: 650;
}

.balance-list__meta {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.balance-list__value {
  display: flex;
  align-items: baseline;
  gap: 4px;
}

.balance-list__value strong {
  font-size: 18px;
  font-variant-numeric: tabular-nums;
}

.balance-list__value span {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.request-list__item {
  border-bottom: 1px solid #edf1ef;
}

.request-list__item:last-child {
  border-bottom: 0;
}

.request-list__button,
.payslip-list__button,
.notice-link {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 11px 18px;
  color: inherit;
  text-align: left;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.request-list__button:hover,
.payslip-list__button:hover,
.notice-link:hover {
  background: #fafcfb;
}

.request-list__main {
  display: flex;
  min-width: 0;
  flex-direction: column;
}

.request-list__title {
  overflow: hidden;
  font-size: 12.5px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.request-list__meta,
.payslip-list__meta {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.payslip-list__period {
  font-size: 12.5px;
  font-weight: 600;
}

.notice-link {
  display: block;
  padding: 10px 0;
}

@media (max-width: 1100px) {
  .workspace-quick__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 10px;
  padding: 14px 18px 18px;
}

.workspace-quick__item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 13px 14px;
  color: var(--color-ink-secondary);
  text-align: left;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.workspace-quick__item strong {
  color: var(--color-ink);
  font-size: 13px;
}

.workspace-quick__item span {
  font-size: 11.5px;
}

.workspace-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .workspace-attention {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 720px) {
  .workspace-hero {
    flex-direction: column;
    align-items: flex-start;
  }

  .workspace-hero__actions {
    width: 100%;
  }

  .workspace-hero__actions .el-button {
    flex: 1;
  }

  .attendance-mini {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
