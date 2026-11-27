<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { resourceGet } from '@/api/resourceClient'
import type { PeopleForm, PeopleRow } from '../types'
import { departmentLabel, rowText } from '../peopleRead'
import { peopleErrorInfo, type PeopleErrorInfo } from '../peopleErrors'
import { postRow } from '../write'

const props = defineProps<{
  form: PeopleForm
  rows: PeopleRow[]
  asOf: string
}>()

const emit = defineEmits<{ saved: [] }>()

type Draft = {
  parent_id: string
  code: string
  name: string
  type: string
  valid_from: string
  valid_to: string
  department_id: string
  employment_id: string
  effective_from: string
  reason: string
  new_parent_id: string
  preview_key: string
}

const defaults: Record<string, Partial<Draft>> = {
  'department-create': {
    parent_id: '',
    code: '',
    name: '',
    type: 'DEPARTMENT',
    valid_from: '',
    valid_to: '',
  },
  'department-member': { department_id: '', employment_id: '', effective_from: '', reason: '' },
  'department-move': { department_id: '', new_parent_id: '', effective_from: '', preview_key: '' },
}

const draft = reactive<Draft>({ ...(defaults[props.form] ?? {}) } as Draft)
const submitting = ref(false)
const previewing = ref(false)
const formError = ref<PeopleErrorInfo | null>(null)
const impact = ref<Record<string, unknown> | null>(null)

watch(
  () => props.form,
  (form) => {
    for (const key of Object.keys(draft) as Array<keyof Draft>) delete draft[key]
    Object.assign(draft, defaults[form])
    formError.value = null
    impact.value = null
  },
)

const departmentOptions = computed(() =>
  props.rows.filter((row) => typeof row.id === 'number').map((row) => ({ id: Number(row.id), label: departmentLabel(row) })),
)

const canSubmit = computed(() => !submitting.value && !previewing.value)

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function validation(message: string): void {
  formError.value = { code: 'VALIDATION', message, requestId: null, reason: null, fieldErrors: {} }
}

async function submit(): Promise<void> {
  formError.value = null
  if (props.form === 'department-create') {
    if (draft.code.trim() === '' || draft.name.trim() === '') {
      validation('请填写部门编码与部门名称。')
      return
    }
    submitting.value = true
    try {
      await postRow(
        '/v1/org-units',
        {
          parent_id: numeric(draft.parent_id),
          code: draft.code.trim(),
          name: draft.name.trim(),
          type: draft.type,
          valid_from: draft['valid_from'] === '' ? null : draft.valid_from,
          valid_to: draft['valid_to'] === '' ? null : draft.valid_to,
        },
        'department-create',
      )
      ElMessage.success('组织节点已创建。')
      draft['code'] = ''
      draft['name'] = ''
      emit('saved')
    } catch (error) {
      formError.value = peopleErrorInfo(error)
    } finally {
      submitting.value = false
    }
    return
  }

  if (props.form === 'department-member') {
    const departmentId = numeric(draft.department_id)
    const employmentId = numeric(draft.employment_id)
    if (departmentId === null || employmentId === null || draft['effective_from'] === '' || draft.reason.trim().length < 3) {
      validation('请选择部门、填写员工关系编号、生效日期和至少 3 个字的调整原因。')
      return
    }
    submitting.value = true
    try {
      await postRow(
        `/v1/departments/${departmentId}/members`,
        { employment_id: employmentId, effective_from: draft.effective_from, reason: draft.reason.trim() },
        'department-member',
      )
      ElMessage.success('部门成员主任职迁移已提交；历史任职保留。')
      draft['employment_id'] = ''
      draft['reason'] = ''
      emit('saved')
    } catch (error) {
      formError.value = peopleErrorInfo(error)
    } finally {
      submitting.value = false
    }
    return
  }

  const departmentId = numeric(draft.department_id)
  if (departmentId === null || draft['effective_from'] === '') {
    validation('请选择要迁移的部门并填写生效日期。')
    return
  }
  if (impact.value === null) {
    validation('请先预览迁移影响，再执行迁移。')
    return
  }

  submitting.value = true
  try {
    await postRow(
      `/v1/org-units/${departmentId}/move`,
      { new_parent_id: numeric(draft.new_parent_id), effective_from: draft.effective_from },
      'department-move',
    )
    ElMessage.success('部门迁移已提交；历史组织关系保留。')
    impact.value = null
    emit('saved')
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    submitting.value = false
  }
}

async function preview(): Promise<void> {
  formError.value = null
  impact.value = null
  const departmentId = numeric(draft.department_id)
  if (departmentId === null) {
    validation('请选择要迁移的部门。')
    return
  }
  previewing.value = true
  try {
    const data = await resourceGet<unknown>(`/v1/org-units/${departmentId}/impact`, {
      new_parent_id: numeric(draft.new_parent_id),
      as_of: props.asOf,
    })
    impact.value = typeof data === 'object' && data !== null && !Array.isArray(data) ? (data as Record<string, unknown>) : null
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    previewing.value = false
  }
}
</script>

<template>
  <section class="panel people-form-panel">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">
          {{ form === 'department-create' ? '新建组织节点' : form === 'department-member' ? '调整部门成员' : '预览并执行部门迁移' }}
        </h2>
        <p class="panel__subtitle">
          {{ form === 'department-create' ? '后端当前只开放新建；编辑/停用组织接口尚未提供。' : form === 'department-member' ? '迁移员工主任职会新增任职版本，不覆盖历史。' : '迁移前必须查看影响预览；跨法人移动会被服务端拒绝。' }}
        </p>
      </div>
    </header>
    <div class="panel__body">
      <form class="people-form" @submit.prevent="submit">
        <div class="people-form__grid">
          <template v-if="form === 'department-create'">
            <label class="people-field"><span>上级部门</span><select v-model="draft.parent_id" data-testid="department-parent"><option value="">无（根节点）</option><option v-for="option in departmentOptions" :key="option.id" :value="String(option.id)">{{ option.label }}</option></select></label>
            <label class="people-field"><span>部门编码 *</span><input v-model="draft.code" data-testid="department-code" /></label>
            <label class="people-field"><span>部门名称 *</span><input v-model="draft.name" data-testid="department-name" /></label>
            <label class="people-field"><span>组织类型 *</span><select v-model="draft.type" data-testid="department-type"><option value="COMPANY">公司</option><option value="DIVISION">事业部</option><option value="DEPARTMENT">部门</option><option value="TEAM">团队</option><option value="COST_CENTER">成本中心</option><option value="SHARED_SERVICE">共享服务</option></select></label>
            <label class="people-field"><span>生效日期</span><input v-model="draft.valid_from" type="date" /></label>
            <label class="people-field"><span>失效日期</span><input v-model="draft.valid_to" type="date" /></label>
          </template>

          <template v-else>
            <label class="people-field"><span>部门 *</span><select v-model="draft.department_id" data-testid="department-select"><option value="">请选择部门</option><option v-for="option in departmentOptions" :key="option.id" :value="String(option.id)">{{ option.label }}</option></select></label>
            <template v-if="form === 'department-member'">
              <label class="people-field"><span>员工关系编号 *</span><input v-model="draft.employment_id" data-testid="department-employment-id" inputmode="numeric" /></label>
              <label class="people-field"><span>生效日期 *</span><input v-model="draft.effective_from" data-testid="department-effective-date" type="date" /></label>
              <label class="people-field people-field--wide"><span>调整原因 *</span><textarea v-model="draft.reason" data-testid="department-member-reason" rows="2" /></label>
            </template>
            <template v-else>
              <label class="people-field"><span>迁移到上级部门</span><select v-model="draft.new_parent_id" data-testid="department-new-parent"><option value="">设为根节点</option><option v-for="option in departmentOptions" :key="option.id" :value="String(option.id)">{{ option.label }}</option></select></label>
              <label class="people-field"><span>生效日期 *</span><input v-model="draft.effective_from" data-testid="department-effective-date" type="date" /></label>
            </template>
          </template>
        </div>

        <div v-if="form === 'department-move' && impact" class="people-impact" data-testid="department-impact">
          <p class="people-impact__title">影响预览</p>
          <dl class="people-facts">
            <div class="people-facts__item"><dt>受影响员工</dt><dd>{{ rowText(impact, 'affected_employee_count') }}</dd></div>
            <div class="people-facts__item"><dt>受影响主管</dt><dd>{{ rowText(impact, 'affected_manager_count') }}</dd></div>
            <div class="people-facts__item"><dt>工资成本中心</dt><dd>{{ rowText(impact, 'payroll_cost_center_count') }}</dd></div>
            <div class="people-facts__item"><dt>跨法人风险</dt><dd>{{ impact.cross_entity_warning === true ? '是' : '否' }}</dd></div>
          </dl>
        </div>

        <p v-if="formError" class="people-form__error" role="alert" data-testid="department-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>

        <div class="people-form__actions">
          <button v-if="form === 'department-move'" type="button" class="people-button people-button--secondary" :disabled="previewing || submitting" data-testid="department-preview" @click="preview">
            {{ previewing ? '预览中…' : '预览影响' }}
          </button>
          <button type="submit" class="people-button" :disabled="!canSubmit" data-testid="department-submit">
            {{ submitting ? '提交中…' : form === 'department-create' ? '创建部门' : form === 'department-member' ? '提交成员调整' : '执行迁移' }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>
