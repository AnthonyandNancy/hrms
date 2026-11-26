<script setup lang="ts">
import { computed } from 'vue'
import type { StatusTone } from '@/utils/status'
import { formatBusinessDate } from '@/utils/format'
import type { EmployeeRow } from '../api'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'

const props = withDefaults(
  defineProps<{
    rows: EmployeeRow[]
    total: number
    page: number
    pageSize: number
    loading?: boolean
    archivedVisible?: boolean
    canArchive?: boolean
    canRestore?: boolean
  }>(),
  { loading: false, archivedVisible: true, canArchive: false, canRestore: false },
)

const emit = defineEmits<{
  'update:page': [page: number]
  'update:pageSize': [pageSize: number]
  view: [row: EmployeeRow]
  archive: [row: EmployeeRow]
  restore: [row: EmployeeRow]
}>()

const STATUS_TONES: Record<string, StatusTone> = {
  [DISPLAY_CODES.employeeStatus.active]: 'success',
  [DISPLAY_CODES.employeeStatus.probation]: 'info',
  [DISPLAY_CODES.employeeStatus.leave]: 'warning',
  [DISPLAY_CODES.employeeStatus.onLeave]: 'warning',
  [DISPLAY_CODES.employeeStatus.suspended]: 'warning',
  [DISPLAY_CODES.employeeStatus.terminated]: 'muted',
}

const pageCount = computed(() => Math.max(1, Math.ceil(props.total / props.pageSize)))

function statusLabel(row: EmployeeRow): string {
  return translate('people', 'employeeStatus', row.status, '未知状态').label
}

function statusTone(row: EmployeeRow): StatusTone {
  if (row.archived_at !== null) return 'muted'
  return STATUS_TONES[row.status] ?? 'muted'
}

function typeLabel(row: EmployeeRow): string {
  return translate('people', 'employmentType', row.employment_type, '未设置').label
}

function hireDate(row: EmployeeRow): string {
  return formatBusinessDate(row.hire_date ?? row.valid_from)
}

function isArchived(row: EmployeeRow): boolean {
  return row.archived_at !== null
}
</script>

<template>
  <section class="employee-table" data-testid="employee-table">
    <div class="employee-table__scroller" role="region" aria-label="员工列表，可横向滚动" tabindex="0">
      <table class="employee-table__table">
        <caption class="visually-hidden">
          员工列表，共 {{ total }} 条，第 {{ page }} 页，共 {{ pageCount }} 页
        </caption>
        <thead>
          <tr>
            <th scope="col">员工编号</th>
            <th scope="col">姓名</th>
            <th scope="col">部门</th>
            <th scope="col">岗位</th>
            <th scope="col">状态</th>
            <th scope="col">入职日期</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody v-if="loading" data-testid="employee-table-loading" aria-live="polite">
          <tr v-for="index in Math.min(pageSize, 6)" :key="`skeleton-${index}`" class="employee-table__skeleton">
            <td colspan="7"><span class="employee-table__skeleton-line" /></td>
          </tr>
        </tbody>
        <tbody v-else>
          <tr
            v-for="row in rows"
            :key="row.employment_id"
            :data-testid="`employee-row-${row.employment_id}`"
            :class="{ 'employee-table__row--archived': isArchived(row) }"
            @click="emit('view', row)"
          >
            <td class="mono">{{ row.employee_no }}</td>
            <td>
              <span>{{ row.person_name }}</span>
              <span v-if="isArchived(row)" class="employee-table__archived-mark">已归档</span>
            </td>
            <td>{{ row.department_name ?? '—' }}</td>
            <td>{{ row.position_name ?? '—' }}</td>
            <td>
              <span class="employee-table__status" :class="`employee-table__status--${statusTone(row)}`">
                {{ statusLabel(row) }}
              </span>
              <small class="employee-table__type">{{ typeLabel(row) }}</small>
            </td>
            <td>{{ hireDate(row) }}</td>
            <td>
              <div class="employee-table__actions" @click.stop>
                <a :href="`/hr/employees/${row.employment_id}`" class="employee-table__link">查看</a>
                <button
                  v-if="canArchive && !isArchived(row)"
                  type="button"
                  class="employee-table__action"
                  data-testid="employee-archive"
                  @click="emit('archive', row)"
                >
                  归档
                </button>
                <button
                  v-if="canRestore && isArchived(row)"
                  type="button"
                  class="employee-table__action"
                  data-testid="employee-restore"
                  @click="emit('restore', row)"
                >
                  恢复
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="employee-table__footer">
      <span>
        共 {{ total }} 条记录
        <template v-if="archivedVisible">（含归档记录）</template>
        <template v-else>（已归档记录默认不显示，可勾选“包含已归档”查看）</template>
      </span>
      <el-pagination
        :current-page="page"
        :page-size="pageSize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="sizes, prev, pager, next"
        background
        size="small"
        prev-text="上一页"
        next-text="下一页"
        @current-change="emit('update:page', $event)"
        @size-change="emit('update:pageSize', $event)"
      />
    </footer>
  </section>
</template>

<style scoped>
.employee-table {
  overflow: hidden;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.employee-table__scroller {
  overflow-x: auto;
}

.employee-table__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.employee-table__table th,
.employee-table__table td {
  padding: 9px 11px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line);
}

.employee-table__table th {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
  background: var(--color-canvas);
}

.employee-table__table tbody tr {
  cursor: pointer;
}

.employee-table__table tbody tr:hover {
  background: var(--color-brand-soft);
}

.employee-table__row--archived {
  color: var(--color-ink-muted);
  background: color-mix(in srgb, var(--color-canvas) 65%, #fff);
}

.employee-table__archived-mark {
  margin-left: 6px;
  padding: 1px 6px;
  color: var(--color-ink-muted);
  font-size: 10px;
  border: 1px solid var(--color-line-strong);
  border-radius: 999px;
}

.employee-table__status {
  display: inline-block;
  padding: 2px 7px;
  font-size: 11px;
  border-radius: 999px;
}

.employee-table__status--success {
  color: var(--color-success);
  background: var(--color-success-soft);
}

.employee-table__status--info {
  color: var(--color-brand);
  background: var(--color-brand-soft);
}

.employee-table__status--warning {
  color: var(--color-warning);
  background: var(--color-warning-soft);
}

.employee-table__status--danger {
  color: var(--color-danger);
  background: var(--color-danger-soft);
}

.employee-table__type {
  margin-left: 6px;
  color: var(--color-ink-muted);
  font-size: 10px;
}

.employee-table__status--muted {
  color: var(--color-ink-muted);
  background: var(--color-canvas);
}

.employee-table__actions {
  display: flex;
  gap: 10px;
  align-items: center;
}

.employee-table__link,
.employee-table__action {
  color: var(--color-brand);
  font-size: 12px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.employee-table__link {
  text-decoration: none;
}

.employee-table__link:hover,
.employee-table__action:hover {
  text-decoration: underline;
}

.employee-table__footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.employee-table__skeleton-line {
  display: block;
  width: 82%;
  height: 13px;
  background: linear-gradient(90deg, var(--color-canvas), #fff, var(--color-canvas));
  border-radius: 4px;
  animation: employee-table-pulse 1.4s ease-in-out infinite;
}

@keyframes employee-table-pulse {
  0%, 100% { opacity: 0.55; }
  50% { opacity: 1; }
}
</style>
