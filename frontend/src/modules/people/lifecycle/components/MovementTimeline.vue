<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import {
  decisionActionLabel,
  effectStatusLabel,
  statusLabel,
  workflowEventLabel,
  type TimelineItem,
  type UserOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    items: readonly TimelineItem[]
    users?: readonly UserOption[]
    applicationStatus?: string
    effectStatus?: string
    currentNodeKey?: string | null
    awaitingDecision?: boolean
    loading?: boolean
    error?: string
  }>(),
  {
    users: () => [],
    applicationStatus: '',
    effectStatus: '',
    currentNodeKey: null,
    awaitingDecision: false,
    loading: false,
    error: '',
  },
)

const userById = computed(() => {
  const map = new Map<number, UserOption>()
  for (const user of props.users) map.set(user.id, user)
  return map
})

function actorLabel(userId: number | null): string {
  if (userId === null) return '系统'
  return userById.value.get(userId)?.displayName ?? `用户 #${userId}`
}

function eventTitle(item: TimelineItem): string {
  if (item.type === 'DECISION') {
    return item.action === null ? '审批决定' : decisionActionLabel(item.action)
  }
  if (item.type === 'EFFECT') {
    return item.effectType === null ? '生效执行' : `生效执行 · ${item.effectType}`
  }
  return workflowEventLabel(item.eventType)
}

function eventDetail(item: TimelineItem): string {
  if (item.type === 'DECISION') {
    return item.comment ?? '未填写意见'
  }
  if (item.type === 'EFFECT') {
    const parts = [
      item.status === null ? '' : effectStatusLabel(item.status),
      item.scheduledFor === null ? '' : `计划生效 ${formatBusinessDate(item.scheduledFor)}`,
      item.attempts === null ? '' : `尝试 ${item.attempts} 次`,
    ].filter((part) => part !== '')
    return parts.join(' · ')
  }
  const node = item.payload.node_key
  return typeof node === 'string' && node !== '' ? `节点：${node}` : ''
}

function marker(item: TimelineItem): string {
  if (item.type === 'DECISION') return '审'
  if (item.type === 'EFFECT') return '效'
  return '流'
}

function itemClass(item: TimelineItem): string {
  if (item.type === 'DECISION') return 'is-decision'
  if (item.type === 'EFFECT') return 'is-effect'
  return 'is-event'
}
</script>

<template>
  <section class="movement-timeline" aria-labelledby="movement-timeline-title" data-testid="movement-timeline">
    <header class="movement-timeline__header">
      <div>
        <h2 id="movement-timeline-title">审批与生效时间线</h2>
        <p>按发生时间排序，展示工作流事件、不可变审批决定与生效结果。</p>
      </div>
      <div class="movement-timeline__status">
        <span v-if="props.applicationStatus !== ''">申请：{{ statusLabel(props.applicationStatus) }}</span>
        <span v-if="props.effectStatus !== ''">生效：{{ effectStatusLabel(props.effectStatus) }}</span>
      </div>
    </header>

    <p v-if="props.error !== ''" class="movement-timeline__error" role="alert">{{ props.error }}</p>
    <p v-else-if="props.loading" class="movement-timeline__muted" role="status">正在加载审批时间线…</p>
    <p v-else-if="props.items.length === 0" class="movement-timeline__muted" role="status">
      暂无时间线记录。服务端尚未记录工作流事件或审批决定。
    </p>

    <ol v-else class="movement-timeline__list">
      <li
        v-for="(item, index) in props.items"
        :key="`${item.type}-${item.decisionId ?? item.eventType}-${index}`"
        class="movement-timeline__item"
        :class="itemClass(item)"
      >
        <span class="movement-timeline__marker" aria-hidden="true">{{ marker(item) }}</span>
        <div class="movement-timeline__body">
          <div class="movement-timeline__title-row">
            <strong>{{ eventTitle(item) }}</strong>
            <time :datetime="item.occurredAt ?? undefined">{{ formatDateTimeInTimezone(item.occurredAt) }}</time>
          </div>
          <p class="movement-timeline__actor">{{ actorLabel(item.actorUserId) }}</p>
          <p v-if="eventDetail(item) !== ''" class="movement-timeline__detail">{{ eventDetail(item) }}</p>
          <p v-if="item.nodeKey !== null" class="movement-timeline__node">审批节点：{{ item.nodeKey }}</p>
          <p v-if="item.requiresEscalation" class="movement-timeline__escalation">需要人工处理生效失败。</p>
        </div>
      </li>
    </ol>

    <div
      v-if="props.awaitingDecision && props.currentNodeKey !== null"
      class="movement-timeline__pending"
      data-testid="movement-pending-approval"
    >
      <strong>待审批</strong>
      <p>当前节点 {{ props.currentNodeKey }} 等待处理；批准后仍会按案例生效日期执行。</p>
      <p class="movement-timeline__muted">可用动作：批准 / 驳回。已结束的案例不再展示审批动作。</p>
    </div>
  </section>
</template>

<style scoped>
.movement-timeline {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.movement-timeline__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid #e2e8f0;
}

.movement-timeline__header h2 {
  margin: 0;
  font-size: 15px;
}

.movement-timeline__header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11px;
  line-height: 1.5;
}

.movement-timeline__status {
  display: flex;
  gap: 8px;
  align-self: flex-start;
  color: #334155;
  font-size: 11px;
}

.movement-timeline__list {
  display: grid;
  gap: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}

.movement-timeline__item {
  display: flex;
  gap: 10px;
  padding: 12px 16px;
  border-bottom: 1px solid #f1f5f9;
}

.movement-timeline__marker {
  display: grid;
  flex: 0 0 24px;
  width: 24px;
  height: 24px;
  place-items: center;
  color: #fff;
  font-size: 11px;
  background: #64748b;
  border-radius: 999px;
}

.movement-timeline__item.is-decision .movement-timeline__marker {
  background: #1d4ed8;
}

.movement-timeline__item.is-effect .movement-timeline__marker {
  background: #047857;
}

.movement-timeline__body {
  flex: 1;
  min-width: 0;
}

.movement-timeline__title-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 12px;
}

.movement-timeline__title-row time {
  color: #64748b;
  font-size: 10.5px;
  font-variant-numeric: tabular-nums;
}

.movement-timeline__actor,
.movement-timeline__detail,
.movement-timeline__node,
.movement-timeline__escalation {
  margin: 3px 0 0;
  color: #475569;
  font-size: 11px;
  line-height: 1.5;
}

.movement-timeline__detail {
  color: #334155;
}

.movement-timeline__escalation {
  color: #b91c1c;
}

.movement-timeline__pending {
  margin: 12px 16px 16px;
  padding: 12px;
  color: #1e3a8a;
  font-size: 11.5px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
}

.movement-timeline__pending p {
  margin: 4px 0 0;
  line-height: 1.5;
}

.movement-timeline__error {
  margin: 12px 16px;
  color: #b91c1c;
  font-size: 11.5px;
}

.movement-timeline__muted {
  margin: 12px 16px;
  color: #64748b;
  font-size: 11.5px;
}
</style>
