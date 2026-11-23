<script setup lang="ts">
import { computed } from 'vue'
import type { TimelineEvent, TimelineLane } from '@/api/contracts'
import { formatDateTimeInTimezone } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    events: TimelineEvent[]
    timezone?: string
    showLane?: boolean
  }>(),
  { timezone: 'Asia/Shanghai', showLane: true },
)

const LANE_LABEL: Record<TimelineLane, string> = {
  APPLICATION: '申请',
  EFFECT: '生效',
  SYSTEM: '系统',
}

const ordered = computed(() =>
  [...props.events].sort((a, b) => new Date(a.occurredAt).getTime() - new Date(b.occurredAt).getTime()),
)
</script>

<template>
  <ol v-if="ordered.length" class="timeline" aria-label="时间线">
    <li
      v-for="event in ordered"
      :key="event.id"
      class="timeline__item"
      :class="`timeline__item--${event.lane.toLowerCase()}`"
    >
      <span class="timeline__marker" aria-hidden="true" />
      <div class="timeline__content">
        <div class="timeline__heading">
          <span v-if="showLane" class="timeline__lane">{{ LANE_LABEL[event.lane] }}</span>
          <span class="timeline__title">{{ event.title }}</span>
          <time class="timeline__time" :datetime="event.occurredAt">
            {{ formatDateTimeInTimezone(event.occurredAt, timezone) }}
          </time>
        </div>
        <p v-if="event.detail" class="timeline__detail">{{ event.detail }}</p>
        <p v-if="event.actor" class="timeline__actor">操作人：{{ event.actor }}</p>
      </div>
    </li>
  </ol>
  <p v-else class="empty-inline">暂无时间线记录。</p>
</template>
