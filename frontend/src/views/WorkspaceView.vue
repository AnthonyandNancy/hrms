<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getApi } from '@/api'
import type { WorkspaceProjection } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { formatBusinessDate, formatDateTimeInTimezone, formatMonthLabel } from '@/utils/format'
import MoneyText from '@/components/MoneyText.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import FreshnessNote from '@/components/FreshnessNote.vue'

const router = useRouter()
const loading = ref(true)
const error = ref('')
const data = ref<WorkspaceProjection | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    data.value = await getApi().workspace.get()
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

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
          <p class="workspace-hero__eyebrow">我的工作台 · {{ formatBusinessDate('2026-11-23') }}</p>
          <h1 class="workspace-hero__title">{{ data ? `${data.profile.name}，下午好` : '我的工作台' }}</h1>
          <p class="workspace-hero__meta">
            {{ data?.profile.jobTitle }} · {{ data?.profile.orgName }} · 工号 {{ data?.profile.employeeNo }}
          </p>
        </div>
      </div>
      <div class="workspace-hero__actions">
        <el-button type="primary" @click="go('/leave')">发起请假</el-button>
        <el-button @click="go('/payroll/my-payslips')">查看工资条</el-button>
      </div>
    </header>

    <el-alert v-if="error" type="error" :title="error" show-icon :closable="false">
      <el-button text size="small" @click="load">重试</el-button>
    </el-alert>

    <el-skeleton v-if="loading" :rows="8" animated />

    <template v-else-if="data">
      <div class="workspace-attention">
        <button class="attention-card attention-card--warning" type="button" @click="go('/attendance')">
          <span class="attention-card__kicker">待处理</span>
          <strong>昨日下班打卡缺失</strong>
          <span>考勤记录显示 11 月 23 日缺少下班打卡，请提交补正。</span>
          <span class="attention-card__action">去补正 →</span>
        </button>
        <button class="attention-card" type="button" @click="go('/notices')">
          <span class="attention-card__kicker">未读公告</span>
          <strong>{{ data.notices.filter((notice) => !notice.acknowledged).length }} 条需确认</strong>
          <span>包含薪酬发放安排与个人信息保护培训通知。</span>
          <span class="attention-card__action">查看公告 →</span>
        </button>
        <button class="attention-card" type="button" @click="go('/leave')">
          <span class="attention-card__kicker">年假余额</span>
          <strong>12 天可用</strong>
          <span>本年度发放 15 天，已使用 3 天，待审批占用 2 天。</span>
          <span class="attention-card__action">查看余额明细 →</span>
        </button>
      </div>

      <div class="workspace-grid">
        <section class="panel workspace-profile">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">我的档案</h2>
              <p class="panel__subtitle">当前有效任职与合同摘要</p>
            </div>
            <el-button text size="small" @click="go(`/employees?employee=5105`)">查看完整档案</el-button>
          </div>
          <div class="panel__body">
            <div class="detail-grid">
              <div class="detail-grid__item">
                <span class="detail-grid__label">岗位</span>
                <span class="detail-grid__value">{{ data.profile.jobTitle }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">组织</span>
                <span class="detail-grid__value">{{ data.profile.orgName }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">直属经理</span>
                <span class="detail-grid__value">{{ data.profile.managerName }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">工作地点</span>
                <span class="detail-grid__value">{{ data.profile.workLocation }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">工作邮箱</span>
                <span class="detail-grid__value">{{ data.profile.email }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">手机号</span>
                <span class="detail-grid__value">{{ data.profile.phone.display }}</span>
              </div>
            </div>
          </div>
          <div class="panel__footer workspace-contract">
            <div>
              <span class="workspace-contract__label">当前合同</span>
              <strong>{{ data.contract.contractNo }}</strong>
              <span class="muted"> · {{ data.contract.type }}</span>
            </div>
            <span class="muted small">
              {{ formatBusinessDate(data.contract.startDate) }} —
              {{ data.contract.endDate ? formatBusinessDate(data.contract.endDate) : '无固定期限' }}
            </span>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">本月考勤</h2>
              <p class="panel__subtitle">{{ formatMonthLabel(data.attendanceMonth) }} · Asia/Shanghai</p>
            </div>
            <el-button text size="small" @click="go('/attendance')">打开日历</el-button>
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
            <StatusBadge kind="application" status="IN_APPROVAL" :show-label-prefix="false" size="sm" />
          </div>
        </section>

        <section class="panel workspace-leave">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">假期余额</h2>
              <p class="panel__subtitle">账本汇总，不直接修改余额</p>
            </div>
            <el-button text size="small" @click="go('/leave')">账本明细</el-button>
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
            <el-button text size="small" @click="go('/cases')">全部申请</el-button>
          </div>
          <ul class="request-list">
            <li v-for="request in data.myRequests" :key="request.id" class="request-list__item">
              <button type="button" class="request-list__button" @click="go(`/cases/${request.id}`)">
                <span class="request-list__main">
                  <span class="request-list__title">{{ request.title }}</span>
                  <span class="request-list__meta">
                    {{ request.caseNo }} · 提交于 {{ formatDateTimeInTimezone(request.appliedAt) }}
                  </span>
                </span>
                <span class="status-pair">
                  <StatusBadge kind="application" :status="request.applicationStatus" size="sm" />
                  <StatusBadge kind="effect" :status="request.effectStatus" size="sm" />
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
            <el-button text size="small" @click="go('/payroll/my-payslips')">查看全部</el-button>
          </div>
          <ul class="payslip-list">
            <li v-for="slip in data.payslips.slice(0, 2)" :key="slip.id" class="payslip-list__item">
              <button type="button" class="payslip-list__button" @click="go(`/payroll/my-payslips/${slip.id}`)">
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
            <el-button text size="small" @click="go('/notices')">公告中心</el-button>
          </div>
          <ul class="notice-list" style="padding: 0 18px">
            <li v-for="notice in data.notices" :key="notice.id" class="notice-list__item">
              <button type="button" class="notice-link" @click="go(`/notices/${notice.id}`)">
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

      <FreshnessNote :timestamp="'2026-11-23T09:05:00Z'" />
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
