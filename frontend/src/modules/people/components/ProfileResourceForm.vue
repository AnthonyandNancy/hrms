<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { PeopleProfileResource, PeopleRow } from '../types'
import { peopleErrorInfo, type PeopleErrorInfo } from '../peopleErrors'
import { patchRow, postRow } from '../write'

const props = withDefaults(
  defineProps<{
    resource: PeopleProfileResource
    employmentId?: number | null
    rows?: PeopleRow[]
  }>(),
  { employmentId: null, rows: () => [] },
)

const emit = defineEmits<{ saved: [] }>()

type Draft = {
  school: string
  degree: string
  major: string
  start_date: string
  end_date: string
  notes: string
  company: string
  title: string
  leave_reason: string
  name: string
  relationship: string
  phone: string
  email: string
  is_emergency: string
  is_primary: string
  certificate_type: string
  certificate_no: string
  issuer: string
  issued_on: string
  expires_on: string
  document_id: string
  reason: string
  original_name: string
  total_rows: string
}

const labels: Record<PeopleProfileResource, string> = {
  education: '教育经历',
  experiences: '工作经历',
  contacts: '家庭/紧急联系人',
  certificates: '证照与资格',
  change_requests: '档案变更申请',
  imports: '导入校验批次',
}

const defaults: Record<PeopleProfileResource, Partial<Draft>> = {
  education: { school: '', degree: '', major: '', start_date: '', end_date: '', notes: '' },
  experiences: { company: '', title: '', start_date: '', end_date: '', leave_reason: '', notes: '' },
  contacts: { name: '', relationship: '', phone: '', email: '', is_emergency: 'false', is_primary: 'false' },
  certificates: { certificate_type: '', certificate_no: '', issuer: '', issued_on: '', expires_on: '', document_id: '' },
  change_requests: { reason: '' },
  imports: { original_name: '', total_rows: '0' },
}

const draft = reactive<Draft>({ ...(defaults[props.resource] ?? {}) } as Draft)
const employmentInput = ref(props.employmentId === null ? '' : String(props.employmentId))
const editingId = ref('')
const submitting = ref(false)
const formError = ref<PeopleErrorInfo | null>(null)
const successMessage = ref('')

function resetDraft(resource: PeopleProfileResource): void {
  for (const key of Object.keys(draft) as Array<keyof Draft>) delete draft[key]
  Object.assign(draft, defaults[resource])
}

watch(
  () => props.resource,
  (resource) => {
    resetDraft(resource)
    editingId.value = ''
    formError.value = null
    successMessage.value = ''
  },
)

watch(
  () => props.employmentId,
  (value) => {
    employmentInput.value = value === null ? '' : String(value)
  },
)

watch(editingId, (id) => {
  if (id === '') {
    resetDraft(props.resource)
    return
  }
  const row = props.rows.find((item) => String(item.id ?? '') === id)
  if (!row) {
    resetDraft(props.resource)
    return
  }
  for (const key of Object.keys(defaults[props.resource]) as Array<keyof Draft>) {
    const value = row[key]
    draft[key] = typeof value === 'string' || typeof value === 'number' ? String(value) : ''
  }
})

const editingRow = computed(() => props.rows.find((row) => String(row.id ?? '') === editingId.value) ?? null)
const requiresEmployment = computed(() => props.employmentId === null)

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function text(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

function bodyFor(resource: PeopleProfileResource): Record<string, unknown> | null {
  if (resource === 'education') {
    if (draft.school.trim() === '' || draft.degree.trim() === '' || draft['start_date'] === '') return null
    return {
      school: draft.school.trim(),
      degree: draft.degree.trim(),
      major: text(draft.major),
      start_date: draft.start_date,
      end_date: text(draft.end_date),
      notes: text(draft.notes),
    }
  }
  if (resource === 'experiences') {
    if (draft.company.trim() === '' || draft.title.trim() === '' || draft['start_date'] === '') return null
    return {
      company: draft.company.trim(),
      title: draft.title.trim(),
      start_date: draft.start_date,
      end_date: text(draft.end_date),
      leave_reason: text(draft.leave_reason),
      notes: text(draft.notes),
    }
  }
  if (resource === 'contacts') {
    if (draft.name.trim() === '' || draft.relationship.trim() === '') return null
    return {
      name: draft.name.trim(),
      relationship: draft.relationship.trim(),
      phone: text(draft.phone),
      email: text(draft.email),
      is_emergency: draft['is_emergency'] === 'true',
      is_primary: draft['is_primary'] === 'true',
    }
  }
  if (resource === 'certificates') {
    if (draft.certificate_type.trim() === '') return null
    return {
      certificate_type: draft.certificate_type.trim(),
      certificate_no: text(draft.certificate_no),
      issuer: text(draft.issuer),
      issued_on: text(draft.issued_on),
      expires_on: text(draft.expires_on),
      document_id: numeric(draft.document_id),
    }
  }
  if (resource === 'change_requests') {
    if (draft.reason.trim().length < 3) return null
    return { reason: draft.reason.trim(), diff: {} }
  }
  if (draft.original_name.trim() === '') return null
  return { original_name: draft.original_name.trim(), total_rows: numeric(draft.total_rows) ?? 0, errors: [] }
}

async function submit(): Promise<void> {
  formError.value = null
  successMessage.value = ''
  const employmentId = props.employmentId ?? numeric(employmentInput.value)
  if (employmentId === null || employmentId <= 0) {
    formError.value = {
      code: 'VALIDATION',
      message: '请填写有效的员工关系编号。',
      requestId: null,
      reason: null,
      fieldErrors: {},
    }
    return
  }
  const body = bodyFor(props.resource)
  if (body === null) {
    formError.value = {
      code: 'VALIDATION',
      message: `请完整填写${labels[props.resource]}的必填项。`,
      requestId: null,
      reason: null,
      fieldErrors: {},
    }
    return
  }

  submitting.value = true
  try {
    const base = `/v1/employees/${employmentId}/${props.resource}`
    if (editingRow.value !== null) {
      await patchRow(`${base}/${editingId.value}`, body, props.resource)
      successMessage.value = `${labels[props.resource]}已修订；旧版本保留在历史中。`
    } else {
      await postRow(base, body, props.resource)
      successMessage.value = `${labels[props.resource]}已保存。`
    }
    ElMessage.success(successMessage.value)
    resetDraft(props.resource)
    editingId.value = ''
    emit('saved')
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="panel people-form-panel" data-testid="profile-resource-form">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">{{ editingId === '' ? `新增${labels[resource]}` : `修订${labels[resource]}` }}</h2>
        <p class="panel__subtitle">
          {{ resource === 'imports' ? '仅登记校验批次元数据；文件二进制上传接口尚未开放。' : '提交走真实 Profile 子资源接口；编辑会生成新版本，旧版本不会覆盖。' }}
        </p>
      </div>
    </header>
    <div class="panel__body">
      <form class="people-form" @submit.prevent="submit">
        <div class="people-form__grid">
          <label v-if="requiresEmployment" class="people-field">
            <span>员工关系编号 *</span>
            <input v-model="employmentInput" data-testid="profile-employment-id" inputmode="numeric" placeholder="employment_id" />
          </label>

          <label v-if="rows.length > 0" class="people-field people-field--wide">
            <span>编辑已有记录</span>
            <select v-model="editingId" data-testid="profile-editing-id">
              <option value="">新建记录</option>
              <option v-for="row in rows" :key="String(row.id)" :value="String(row.id)">
                #{{ row.id }} ·
                {{ String(row.school ?? row.company ?? row.name ?? row.certificate_type ?? row.reason ?? row.original_name ?? '记录') }}
              </option>
            </select>
          </label>

          <template v-if="resource === 'education'">
            <label class="people-field"><span>院校 *</span><input v-model="draft.school" data-testid="education-school" /></label>
            <label class="people-field"><span>学历 *</span><input v-model="draft.degree" data-testid="education-degree" /></label>
            <label class="people-field"><span>专业</span><input v-model="draft.major" data-testid="education-major" /></label>
            <label class="people-field"><span>开始日期 *</span><input v-model="draft.start_date" data-testid="education-start" type="date" /></label>
            <label class="people-field"><span>结束日期</span><input v-model="draft.end_date" data-testid="education-end" type="date" /></label>
            <label class="people-field people-field--wide"><span>备注</span><textarea v-model="draft.notes" data-testid="education-notes" rows="2" /></label>
          </template>

          <template v-else-if="resource === 'experiences'">
            <label class="people-field"><span>任职单位 *</span><input v-model="draft.company" data-testid="profile-company" /></label>
            <label class="people-field"><span>岗位 *</span><input v-model="draft.title" data-testid="profile-title" /></label>
            <label class="people-field"><span>开始日期 *</span><input v-model="draft.start_date" data-testid="profile-start" type="date" /></label>
            <label class="people-field"><span>结束日期</span><input v-model="draft.end_date" data-testid="profile-end" type="date" /></label>
            <label class="people-field"><span>离职原因</span><input v-model="draft.leave_reason" data-testid="profile-leave-reason" /></label>
            <label class="people-field people-field--wide"><span>备注</span><textarea v-model="draft.notes" data-testid="profile-notes" rows="2" /></label>
          </template>

          <template v-else-if="resource === 'contacts'">
            <label class="people-field"><span>联系人 *</span><input v-model="draft.name" data-testid="profile-contact-name" /></label>
            <label class="people-field"><span>关系 *</span><input v-model="draft.relationship" data-testid="profile-contact-relationship" /></label>
            <label class="people-field"><span>联系电话（P2）</span><input v-model="draft.phone" data-testid="profile-contact-phone" type="password" autocomplete="off" /></label>
            <label class="people-field"><span>邮箱</span><input v-model="draft.email" data-testid="profile-contact-email" type="email" /></label>
            <label class="people-field"><span>紧急联系人</span><select v-model="draft.is_emergency" data-testid="profile-contact-emergency"><option value="false">否</option><option value="true">是</option></select></label>
            <label class="people-field"><span>主要联系人</span><select v-model="draft.is_primary" data-testid="profile-contact-primary"><option value="false">否</option><option value="true">是</option></select></label>
          </template>

          <template v-else-if="resource === 'certificates'">
            <label class="people-field"><span>证照类型 *</span><input v-model="draft.certificate_type" data-testid="profile-certificate-type" /></label>
            <label class="people-field"><span>证照编号</span><input v-model="draft.certificate_no" data-testid="profile-certificate-no" /></label>
            <label class="people-field"><span>签发机构</span><input v-model="draft.issuer" data-testid="profile-certificate-issuer" /></label>
            <label class="people-field"><span>签发日期</span><input v-model="draft.issued_on" data-testid="profile-certificate-issued" type="date" /></label>
            <label class="people-field"><span>到期日期</span><input v-model="draft.expires_on" data-testid="profile-certificate-expires" type="date" /></label>
            <label class="people-field"><span>文档编号</span><input v-model="draft.document_id" data-testid="profile-certificate-document" inputmode="numeric" /></label>
          </template>

          <template v-else-if="resource === 'change_requests'">
            <label class="people-field people-field--wide"><span>变更原因 *</span><textarea v-model="draft.reason" data-testid="profile-change-reason" rows="3" /></label>
          </template>

          <template v-else>
            <label class="people-field"><span>文件名 *</span><input v-model="draft.original_name" data-testid="profile-import-name" placeholder="employees.xlsx" /></label>
            <label class="people-field"><span>总行数</span><input v-model="draft.total_rows" data-testid="profile-import-rows" inputmode="numeric" /></label>
          </template>
        </div>

        <p v-if="formError" class="people-form__error" role="alert" data-testid="profile-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>
        <p v-else-if="successMessage" class="people-form__success" role="status">{{ successMessage }}</p>

        <div class="people-form__actions">
          <button
            type="button"
            class="people-button"
            :disabled="submitting"
            :data-testid="resource === 'education' ? 'education-submit' : `profile-${resource}-submit`"
            @click="submit"
          >
            {{ submitting ? '提交中…' : editingId === '' ? '创建记录' : '保存新版本' }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>
