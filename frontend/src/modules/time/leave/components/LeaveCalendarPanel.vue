<script setup lang="ts">
import { computed } from 'vue'
import {
  LEAVE_STATUS_LABEL,
  LEAVE_STATUS_TONE,
  leaveUnitLabel,
  type LeaveRequestItem,
  type LeaveTypeOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    requests: LeaveRequestItem[]
    types: LeaveTypeOption[]
  }>(),
  {},
)

interface CalendarDay {
  date: string
  day: number
  inMonth: boolean
  entries: LeaveRequestItem[]
}

const now = new Date()
const year = now.getFullYear()
const month = now.getMonth() + 1
const monthLabel = `${year} 年 ${month} 月`
const firstWeekday = new Date(year, month - 1, 1).getDay()
const daysInMonth = new Date(year, month, 0).getDate()
const leading = (firstWeekday + 6) % 7
const cells: CalendarDay[] = []
const pad = (value: number): string => String(value).padStart(2, '0')

for (let index = 0; index < leading; index += 1) {
  cells.push({ date: '', day: 0, inMonth: false, entries: [] })
}

for (let day = 1; day <= daysInMonth; day += 1) {
  const date = `${year}-${pad(month)}-${pad(day)}`
  cells.push({
    date,
    day,
    inMonth: true,
    entries: props.requests.filter(
      (request) =>
        request.fromDate <= date
        && request.toDate >= date
        && ['APPROVED', 'CONSUMED', 'COMPLETED'].includes(request.applicationStatus),
    ),
  })
}

const pendingCount = computed(
  () => props.requests.filter((request) => ['SUBMITTED', 'IN_REVIEW', 'IN_APPROVAL'].includes(request.applicationStatus)).length,
)

function typeName(request: LeaveRequestItem): string {
  return props.types.find((type) => type.id === request.leaveTypeId)?.name ?? '请假'
}

function unitLabel(request: LeaveRequestItem): string {
  const type = props.types.find((candidate) => candidate.id === request.leaveTypeId)
  return leaveUnitLabel(type?.unit ?? 'DAY')
}

function statusLabel(request: LeaveRequestItem): string {
  return LEAVE_STATUS_LABEL[request.applicationStatus] ?? '审批中'
}

function statusTone(request: LeaveRequestItem): string {
  return `leave-calendar__tag--${LEAVE_STATUS_TONE[request.applicationStatus] ?? 'closed'}`
}
</script>

<template>
  <section class="leave-calendar" aria-label="假期日历">
    <header class="leave-calendar__header">
      <div>
        <h2 class="leave-calendar__title">假期日历</h2>
        <p class="leave-calendar__subtitle">
          {{ monthLabel }}；只在通过审批后显示为休假。待审批申请不视为已休，避免把申请误当成缺勤。
        </p>
      </div>
      <div class="leave-calendar__legend">
        <span class="leave-calendar__legend-item">待审批 {{ pendingCount }} 条</span>
        <span class="leave-calendar__legend-item">已通过显示在日期格</span>
      </div>
    </header>

    <div class="leave-calendar__weekdays" aria-hidden="true">
      <span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span><span>日</span>
    </div>

    <div class="leave-calendar__grid" role="grid" aria-label="月度假期日历">
      <div
        v-for="(cell, index) in cells"
        :key="`${cell.date}-${index}`"
        class="leave-calendar__cell"
        :class="{ 'leave-calendar__cell--empty': !cell.inMonth }"
        role="gridcell"
      >
        <span v-if="cell.inMonth" class="leave-calendar__day">{{ cell.day }}</span>
        <button
          v-for="entry in cell.entries"
          :key="entry.caseId"
          type="button"
          class="leave-calendar__tag"
          :class="statusTone(entry)"
          :title="`${typeName(entry)} ${entry.fromDate} 至 ${entry.toDate}`"
        >
          {{ typeName(entry) }}
        </button>
      </div>
    </div>

    <p v-if="requests.length === 0" class="leave-calendar__empty">
      当前没有请假记录。发起申请并审批通过后，日历会按真实日期显示休假。
    </p>
    <ul v-else class="leave-calendar__list" aria-label="请假明细">
      <li v-for="request in requests" :key="request.caseId">
        <span class="mono">{{ request.caseNo }}</span>
        <span>{{ typeName(request) }}</span>
        <span>{{ request.fromDate }} 至 {{ request.toDate }}</span>
        <span>{{ request.minutes }} 分钟 / {{ unitLabel(request) }}</span>
        <span>{{ statusLabel(request) }}</span>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.leave-calendar {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.leave-calendar__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.leave-calendar__title {
  margin: 0;
  font-size: 15px;
  font-weight: 680;
}

.leave-calendar__subtitle {
  max-width: 720px;
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  line-height: 1.55;
}

.leave-calendar__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.leave-calendar__legend-item {
  padding: 3px 8px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}

.leave-calendar__weekdays,
.leave-calendar__grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
}

.leave-calendar__weekdays {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  text-align: center;
}

.leave-calendar__grid {
  gap: 4px;
}

.leave-calendar__cell {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-height: 78px;
  padding: 5px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 5px;
}

.leave-calendar__cell--empty {
  background: transparent;
  border-color: transparent;
}

.leave-calendar__day {
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 620;
}

.leave-calendar__tag {
  overflow: hidden;
  padding: 2px 4px;
  font-size: 9.5px;
  text-align: left;
  text-overflow: ellipsis;
  white-space: nowrap;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 4px;
}

.leave-calendar__tag--pending {
  background: #fffaf0;
  border-color: #e6c98d;
}

.leave-calendar__tag--rejected {
  background: #feecea;
  border-color: #f5c2bd;
}

.leave-calendar__tag--closed {
  background: #f3f4f6;
  border-color: #d1d5db;
}

.leave-calendar__empty,
.leave-calendar__list {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.leave-calendar__empty {
  padding: 18px;
  text-align: center;
  background: var(--color-canvas);
  border: 1px dashed var(--color-line-strong);
  border-radius: 6px;
}

.leave-calendar__list {
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 0;
  list-style: none;
}

.leave-calendar__list li {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 6px 8px;
  background: var(--color-canvas);
  border-radius: 5px;
}
</style>
