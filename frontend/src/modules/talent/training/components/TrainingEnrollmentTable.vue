<script setup lang="ts">
import { computed } from 'vue'
import {
  COMPLETION_RESULT_LABELS,
  enrollmentActionReason,
  enrollmentStatusLabel,
  isActiveEnrollment,
  type EnrollmentDisplayRow,
} from '../api'

const props = defineProps<{
  enrollments: readonly EnrollmentDisplayRow[]
  capabilities: readonly string[]
  busyEnrollmentId?: number | null
}>()

const emit = defineEmits<{
  complete: [row: EnrollmentDisplayRow]
  cancel: [row: EnrollmentDisplayRow]
}>()

defineOptions({ name: 'TrainingEnrollmentTable' })

const canManage = computed(() => props.capabilities.includes('training.enrollment.manage'))
const canComplete = computed(() => props.capabilities.includes('training.completion.manage'))

function completionReason(row: EnrollmentDisplayRow): string {
  if (!canComplete.value) return '缺少能力 training.completion.manage，仅授权人员可以登记完成。'
  return enrollmentActionReason(row.status)
}

function cancelReason(row: EnrollmentDisplayRow): string {
  if (!canManage.value) return '缺少能力 training.enrollment.manage，仅授权人员可以取消报名。'
  if (!isActiveEnrollment(row.status)) return `当前报名状态为${enrollmentStatusLabel(row.status)}，不能取消。`
  return ''
}

function resultLabel(row: EnrollmentDisplayRow): string {
  return row.completionResult === null ? '—' : COMPLETION_RESULT_LABELS[row.completionResult]
}
</script>

<template>
  <div class="training-enrollment-table" data-testid="training-enrollment-table">
    <p v-if="enrollments.length === 0" class="training-enrollment-table__empty" data-testid="training-enrollment-empty">
      该培训项目还没有报名记录。
    </p>

    <table v-else>
      <caption class="visually-hidden">培训报名与完成情况</caption>
      <thead>
        <tr>
          <th scope="col">员工</th>
          <th scope="col">场次</th>
          <th scope="col">状态</th>
          <th scope="col">完成结果</th>
          <th scope="col">分数</th>
          <th scope="col">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in enrollments"
          :key="row.id"
          :data-testid="`training-enrollment-row-${row.id}`"
        >
          <td>
            <a class="training-enrollment-table__employee" :href="`/hr/employees/${row.employmentId}`">
              {{ row.employeeName }}
            </a>
            <span v-if="row.employeeNo !== ''" class="training-enrollment-table__meta">{{ row.employeeNo }}</span>
          </td>
          <td>{{ row.sessionLabel }}</td>
          <td>
            <span class="training-enrollment-table__chip" :class="`is-${row.status.toLowerCase()}`">
              {{ enrollmentStatusLabel(row.status) }}
            </span>
          </td>
          <td>{{ resultLabel(row) }}</td>
          <td>{{ row.score === null ? '—' : row.score }}</td>
          <td class="training-enrollment-table__actions">
            <button
              type="button"
              class="training-enrollment-table__button"
              :disabled="completionReason(row) !== '' || busyEnrollmentId === row.id"
              :data-testid="`training-enrollment-complete-${row.id}`"
              @click="emit('complete', row)"
            >
              登记完成
            </button>
            <p
              v-if="completionReason(row) !== ''"
              class="training-enrollment-table__reason"
              :data-testid="`training-enrollment-reason-${row.id}`"
            >
              {{ completionReason(row) }}
            </p>
            <button
              type="button"
              class="training-enrollment-table__button is-danger"
              :disabled="cancelReason(row) !== ''"
              :data-testid="`training-enrollment-cancel-${row.id}`"
              @click="emit('cancel', row)"
            >
              取消报名
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.training-enrollment-table table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.training-enrollment-table th,
.training-enrollment-table td {
  padding: 8px 10px;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
  vertical-align: top;
}

.training-enrollment-table th {
  color: #64748b;
  font-weight: 600;
  background: #f8fafc;
}

.training-enrollment-table__employee {
  color: #1d4ed8;
  text-decoration: none;
  font-weight: 600;
}

.training-enrollment-table__meta {
  display: block;
  color: #94a3b8;
  font-size: 11px;
}

.training-enrollment-table__chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  background: #eff6ff;
  color: #1d4ed8;
}

.training-enrollment-table__chip.is-attended {
  background: #fef3c7;
  color: #92400e;
}

.training-enrollment-table__chip.is-completed {
  background: #dcfce7;
  color: #166534;
}

.training-enrollment-table__chip.is-cancelled {
  background: #f1f5f9;
  color: #475569;
}

.training-enrollment-table__actions {
  min-width: 168px;
}

.training-enrollment-table__button {
  margin-right: 6px;
  margin-bottom: 4px;
  padding: 4px 8px;
  font-size: 11.5px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
}

.training-enrollment-table__button.is-danger {
  color: #b91c1c;
  border-color: #fecaca;
}

.training-enrollment-table__button:disabled {
  color: #94a3b8;
  background: #f8fafc;
  border-color: #e2e8f0;
  cursor: not-allowed;
}

.training-enrollment-table__reason {
  margin: 0 0 4px;
  color: #94a3b8;
  font-size: 10.5px;
  line-height: 1.4;
}

.training-enrollment-table__empty {
  margin: 0;
  padding: 16px;
  color: #64748b;
  font-size: 12.5px;
}
</style>
