<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { ApiError } from '@/api/errors'
import {
  MISSING_MEMBER_REMOVAL_ENDPOINT,
  addDepartmentMember,
  assignmentTypeLabel,
  errorMessage,
  type DepartmentMember,
  type MemberPage,
} from '../api'

const props = defineProps<{
  departmentId: number | null
  members: MemberPage | null
  loading: boolean
  error: string
  partialReason: string
  canManage: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

defineOptions({ name: 'DepartmentMembersPanel' })

type AddDraft = {
  employmentId: string
  effectiveFrom: string
  reason: string
}

const draft = reactive<AddDraft>({ employmentId: '', effectiveFrom: '', reason: '' })
const adding = ref(false)
const addError = ref<ApiError | null>(null)
const localError = ref('')

const pendingRemoval = ref<DepartmentMember | null>(null)
const removalDate = ref('')
const removalConfirmed = ref(false)

const fieldErrors = computed<Record<string, string[]>>(() => addError.value?.fieldErrors ?? {})

const countLabel = computed(() => {
  if (props.loading || props.error !== '') return '—'
  return `${props.members?.total ?? 0} 人`
})

function firstFieldError(field: string): string {
  return fieldErrors.value[field]?.[0] ?? ''
}

watch(
  () => props.departmentId,
  () => {
    draft.employmentId = ''
    draft.effectiveFrom = ''
    draft.reason = ''
    addError.value = null
    localError.value = ''
    pendingRemoval.value = null
    removalConfirmed.value = false
  },
)

function validateAdd(): string {
  const employmentId = Number(draft.employmentId)
  if (!Number.isSafeInteger(employmentId) || employmentId <= 0) {
    return '请填写有效的员工关系编号。'
  }
  if (draft.effectiveFrom === '') {
    return '请填写生效日期。'
  }
  if (draft.reason.trim().length < 3) {
    return '请填写至少 3 个字的调整原因。'
  }
  return ''
}

async function submitAdd(): Promise<void> {
  localError.value = ''
  addError.value = null
  if (props.departmentId === null) {
    localError.value = '请先选择部门。'
    return
  }
  const validation = validateAdd()
  if (validation !== '') {
    localError.value = validation
    return
  }

  adding.value = true
  try {
    await addDepartmentMember(props.departmentId, {
      employment_id: Number(draft.employmentId),
      effective_from: draft.effectiveFrom,
      reason: draft.reason.trim(),
    })
    ElMessage.success('部门成员已调入；历史任职保留。')
    draft.employmentId = ''
    draft.effectiveFrom = ''
    draft.reason = ''
    emit('refresh')
  } catch (error) {
    addError.value = error instanceof ApiError ? error : null
    if (addError.value === null) localError.value = errorMessage(error)
  } finally {
    adding.value = false
  }
}

function askRemove(member: DepartmentMember): void {
  removalConfirmed.value = false
  removalDate.value = ''
  pendingRemoval.value = member
}

function closeRemoval(): void {
  pendingRemoval.value = null
  removalConfirmed.value = false
  removalDate.value = ''
}

function confirmRemoval(): void {
  if (removalDate.value === '') return
  removalConfirmed.value = true
}

function memberLabel(member: DepartmentMember | null): string {
  return member === null ? '' : `员工关系 #${member.employment_id}（任职 #${member.assignment_id}）`
}
</script>

<template>
  <section class="panel" data-testid="department-members">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">部门成员</h2>
        <p class="panel__subtitle">
          成员来自实时任职投影；调整通过主任职迁移完成，历史任职不会被覆盖。
        </p>
      </div>
      <span class="department-members__count">共 {{ countLabel }}</span>
    </header>

    <div class="panel__body">
      <p v-if="loading" class="department-members__state" data-testid="members-loading">正在加载部门成员…</p>
      <p v-else-if="error !== ''" class="department-members__state department-members__state--error" data-testid="members-error">
        {{ error }}
        <button type="button" class="department-link" @click="emit('refresh')">重试</button>
      </p>
      <template v-else>
        <p v-if="partialReason !== ''" class="department-members__partial" data-testid="members-partial">
          部分数据：{{ partialReason }}
        </p>

        <div v-if="(members?.items.length ?? 0) === 0" class="department-members__empty" data-testid="members-empty">
          当前部门暂无生效成员。可通过下方调入员工建立主任职。
        </div>

        <table v-else class="department-members__table">
          <caption class="visually-hidden">部门成员列表</caption>
          <thead>
            <tr>
              <th scope="col">员工关系</th>
              <th scope="col">任职类型</th>
              <th scope="col">生效日期</th>
              <th scope="col">失效日期</th>
              <th scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in members?.items ?? []" :key="member.assignment_id">
              <td>员工关系 #{{ member.employment_id }}</td>
              <td>
                {{ assignmentTypeLabel(member.assignment_type) }}
                <span v-if="member.is_primary">（主）</span>
              </td>
              <td>{{ member.valid_from }}</td>
              <td>{{ member.valid_to === '9999-12-31' ? '长期' : member.valid_to }}</td>
              <td>
                <button
                  v-if="canManage"
                  type="button"
                  class="department-link department-link--danger"
                  :data-testid="`member-remove-${member.assignment_id}`"
                  @click="askRemove(member)"
                >
                  移除
                </button>
                <span v-else class="department-members__readonly">只读</span>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </div>

    <footer v-if="canManage" class="panel__footer department-members__footer">
      <form class="department-member-form" @submit.prevent="submitAdd">
        <label class="department-field">
          <span>员工关系编号</span>
          <input
            v-model="draft.employmentId"
            inputmode="numeric"
            data-testid="member-employment-id"
            autocomplete="off"
          />
          <small v-if="firstFieldError('employment_id')" class="department-field__error" data-testid="member-field-error-employment">
            {{ firstFieldError('employment_id') }}
          </small>
        </label>
        <label class="department-field">
          <span>生效日期</span>
          <input v-model="draft.effectiveFrom" type="date" data-testid="member-effective-date" />
          <small v-if="firstFieldError('effective_from')" class="department-field__error" data-testid="member-field-error-date">
            {{ firstFieldError('effective_from') }}
          </small>
        </label>
        <label class="department-field department-field--reason">
          <span>调整原因</span>
          <input v-model="draft.reason" maxlength="255" data-testid="member-reason" autocomplete="off" />
          <small v-if="firstFieldError('reason')" class="department-field__error" data-testid="member-field-error-reason">
            {{ firstFieldError('reason') }}
          </small>
        </label>
        <button type="button" class="department-button" :disabled="adding" data-testid="member-add" @click="submitAdd">
          {{ adding ? '提交中…' : '调入成员' }}
        </button>
      </form>

      <p v-if="addError" class="department-form__error" role="alert" data-testid="member-add-error">
        提交内容未通过校验：{{ addError.message }}
        <span v-if="addError.requestId">（请求编号 {{ addError.requestId }}）</span>
      </p>
      <p v-else-if="localError !== ''" class="department-form__error" role="alert" data-testid="member-add-error">
        {{ localError }}
      </p>
    </footer>

    <el-dialog
      :model-value="pendingRemoval !== null"
      title="确认移除部门成员"
      width="460px"
      :close-on-click-modal="false"
      data-testid="member-remove-dialog"
      @close="closeRemoval"
    >
      <div class="department-confirm">
        <p>
          将从当前部门移除 <strong>{{ memberLabel(pendingRemoval) }}</strong>。
          历史任职记录会按生效日期结束，不会物理删除。
        </p>
        <label class="department-field">
          <span>生效日期 *</span>
          <input v-model="removalDate" type="date" data-testid="member-remove-effective-date" />
        </label>
        <p v-if="removalConfirmed" class="department-form__error" role="alert" data-testid="member-remove-blocker">
          {{ MISSING_MEMBER_REMOVAL_ENDPOINT }} 已确认移除，但不会发起伪请求。
        </p>
      </div>
      <template #footer>
        <el-button @click="closeRemoval">取消</el-button>
        <el-button
          type="danger"
          :disabled="removalDate === ''"
          data-testid="member-remove-confirm-final"
          @click="confirmRemoval"
        >
          确认移除
        </el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.department-members__count {
  color: var(--color-ink-muted);
  font-size: 12px;
}
.department-members__state,
.department-members__empty {
  padding: 12px 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}
.department-members__state--error {
  color: var(--color-danger);
}
.department-members__partial {
  margin: 0 0 10px;
  padding: 7px 9px;
  color: #8a5a00;
  font-size: 11px;
  background: #fff7e6;
  border-radius: var(--radius-sm);
}
.department-members__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.department-members__table th,
.department-members__table td {
  padding: 8px 10px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}
.department-members__table th {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}
.department-members__readonly {
  color: var(--color-ink-muted);
  font-size: 11px;
}
.department-members__footer {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: flex-end;
}
.department-member-form {
  display: flex;
  flex: 1;
  flex-wrap: wrap;
  gap: 10px;
  align-items: flex-end;
}
.department-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 150px;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.department-field--reason {
  flex: 1;
}
.department-field > span {
  font-weight: 600;
}
.department-field input {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}
.department-field__error {
  color: var(--color-danger);
  font-size: 10px;
}
.department-form__error {
  flex-basis: 100%;
  padding: 8px 10px;
  color: var(--color-danger);
  font-size: 12px;
  background: var(--color-danger-soft);
  border-radius: var(--radius-sm);
}
.department-button {
  padding: 7px 14px;
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  background: var(--color-brand);
  border: 1px solid var(--color-brand);
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.department-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.department-link {
  padding: 0;
  color: var(--color-brand);
  font-size: 12px;
  background: transparent;
  border: 0;
  cursor: pointer;
}
.department-link--danger {
  color: var(--color-danger);
}
.department-confirm {
  display: flex;
  flex-direction: column;
  gap: 12px;
  font-size: 12px;
}
</style>
