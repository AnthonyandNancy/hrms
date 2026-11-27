<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import LoadingState from '@/components/LoadingState.vue'
import { formatDateTimeInTimezone } from '@/utils/format'
import type { SelfServiceTask } from '../api'

const props = defineProps<{
  tasks: SelfServiceTask[]
  loading: boolean
  canReadWorkflow: boolean
  errorMessage: string
  errorRequestId: string | null
}>()

const emit = defineEmits<{
  retry: []
}>()

const session = useSessionStore()

/**
 * Prefer the dedicated workflow detail route. Detail pages are gated by the
 * page projection, so fall back to the capability-only case detail route when
 * the role has not been granted the workflow detail page. Never emit a link
 * that the route guard would turn into a forbidden page.
 */
function casePath(caseId: number): string {
  const detailGranted =
    session.canPage('workflow-case-detail') || session.canPage('/workflow/cases/:id')
  return detailGranted ? `/workflow/cases/${caseId}` : `/cases/${caseId}`
}

const dueLabel = (value: string | null): string => (value === null ? '未设置截止时间' : formatDateTimeInTimezone(value))

function slaLabel(state: string): string {
  if (state === 'BREACHED') return '已超时'
  if (state === 'ON_TRACK') return '处理中'
  return '无时限'
}

function slaTone(state: string): string {
  if (state === 'BREACHED') return 'plain-chip--danger'
  if (state === 'ON_TRACK') return 'plain-chip--info'
  return 'plain-chip--muted'
}

const isPartial = computed(() => !props.loading && props.errorMessage !== '' && props.tasks.length === 0)
</script>

<template>
  <section class="panel my-tasks" data-testid="my-tasks" aria-labelledby="my-tasks-title">
    <div class="panel__header">
      <div>
        <h2 id="my-tasks-title" class="panel__title">我的待办</h2>
        <p class="panel__subtitle">只显示指派给当前账号的待处理审批；每项可直接进入所属流程案例。</p>
      </div>
      <span v-if="canReadWorkflow" class="plain-chip">{{ loading ? '加载中' : `${tasks.length} 项` }}</span>
    </div>

    <div v-if="!canReadWorkflow" class="panel__body" data-testid="tasks-unavailable">
      <p class="task-note">无权查看待办。当前账号未获得流程查看能力；如确有审批职责，请联系系统管理员授予流程查看能力。</p>
    </div>

    <LoadingState v-else-if="loading" :rows="3" label="正在加载我的待办" />

    <div v-else-if="errorMessage !== ''" class="panel__body" data-testid="tasks-error">
      <p class="task-note task-note--error">{{ errorMessage }}</p>
      <p v-if="errorRequestId" class="task-request">请求编号：<span class="mono">{{ errorRequestId }}</span></p>
      <el-button plain size="small" data-testid="tasks-retry" @click="emit('retry')">重试待办</el-button>
    </div>

    <EmptyState
      v-else-if="tasks.length === 0"
      title="当前没有待处理任务"
      description="新的审批或待确认事项到达后，会出现在这里。"
    />

    <ul v-else class="task-list">
      <li
        v-for="task in tasks"
        :key="task.taskId"
        class="task-list__item"
        :data-testid="`self-task-${task.caseId}`"
      >
        <RouterLink :to="casePath(task.caseId)" class="task-link" :data-testid="`self-task-link-${task.caseId}`">
          <span class="task-link__main">
            <span class="task-link__title">{{ task.title ?? '未命名审批任务' }}</span>
            <span class="task-link__meta">{{ task.caseNo ?? `案例 #${task.caseId}` }}</span>
            <span class="task-link__due">截止时间：{{ dueLabel(task.dueAt) }}</span>
          </span>
          <span class="task-link__side">
            <span class="plain-chip" :class="slaTone(task.slaState)">{{ slaLabel(task.slaState) }}</span>
            <span class="task-link__enter">进入案例 →</span>
          </span>
        </RouterLink>
      </li>
    </ul>

    <p v-if="isPartial" class="task-partial" role="status">部分数据暂不可用：待办列表暂时无法加载。</p>
  </section>
</template>

<style scoped>
.task-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.task-list__item {
  border-top: 1px solid var(--color-line);
}

.task-list__item:first-child {
  border-top: 0;
}

.task-link {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 18px;
  color: inherit;
  text-decoration: none;
}

.task-link:hover,
.task-link:focus-visible {
  background: var(--color-surface-muted, #f7f9f9);
}

.task-link__main {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.task-link__title {
  color: var(--color-ink);
  font-size: 14px;
  font-weight: 650;
}

.task-link__meta,
.task-link__due {
  color: var(--color-ink-muted);
  font-size: 12px;
}

.task-link__side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
  white-space: nowrap;
}

.task-link__enter {
  color: var(--color-brand-deep, #0f4956);
  font-size: 12px;
  font-weight: 600;
}

.task-note {
  margin: 0 0 10px;
  color: var(--color-ink-secondary);
  font-size: 13px;
  line-height: 1.7;
}

.task-note--error {
  color: var(--color-danger, #b42318);
}

.task-request {
  margin: 0 0 10px;
  color: var(--color-ink-muted);
  font-size: 12px;
}

.task-partial {
  margin: 0;
  padding: 10px 18px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  background: #fff8e6;
  border-top: 1px solid #f2d58a;
}
</style>
