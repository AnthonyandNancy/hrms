<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { PeopleForm, PeopleRow } from '../types'
import { peopleErrorInfo, type PeopleErrorInfo } from '../peopleErrors'
import { patchRow, postRow } from '../write'
import { rowNumber, rowText } from '../peopleRead'

const props = defineProps<{
  form: PeopleForm
  rows: PeopleRow[]
  positionId?: string
}>()

const emit = defineEmits<{ saved: [] }>()

type Draft = {
  org_unit_id: string
  code: string
  title: string
  grade: string
  headcount_limit: string
  valid_from: string
  valid_to: string
  position_id: string
  status: string
  requirement_education: string
  requirement_years: string
  requirement_notes: string
  requirement_effective_from: string
  grade_code: string
  grade_name: string
  grade_level: string
  grade_active: string
}

const defaults: Record<string, Partial<Draft>> = {
  'position-editor': { org_unit_id: '', code: '', title: '', grade: '', headcount_limit: '', valid_from: '', valid_to: '' },
  'position-headcount': { position_id: '', headcount_limit: '', title: '', grade: '', status: 'ACTIVE' },
  'position-requirement': { position_id: '', requirement_education: '', requirement_years: '', requirement_notes: '', requirement_effective_from: '' },
  'position-grade': { grade_code: '', grade_name: '', grade_level: '', grade_active: 'true' },
}

const draft = reactive<Draft>({ ...(defaults[props.form] ?? {}) } as Draft)
const submitting = ref(false)
const formError = ref<PeopleErrorInfo | null>(null)

function reset(form: PeopleForm): void {
  for (const key of Object.keys(draft) as Array<keyof Draft>) delete draft[key]
  Object.assign(draft, defaults[form])
  if (form === 'position-headcount' && props.positionId !== undefined) draft['position_id'] = props.positionId
}

watch(
  () => props.form,
  (form) => {
    reset(form)
    formError.value = null
  },
)

watch(
  () => props.positionId,
  (id) => {
    if (props.form === 'position-headcount' && id !== undefined) draft['position_id'] = id
  },
)

const formTitle = computed(() => {
  if (props.form === 'position-requirement') return '配置岗位任职资格'
  if (props.form === 'position-grade') return '维护职级目录'
  return props.form === 'position-editor' ? '新建岗位' : '调整岗位编制'
})

const formSubtitle = computed(() => {
  if (props.form === 'position-requirement') return '每次提交都会追加新的资格版本（POST /v1/positions/{id}/requirements），已有版本不被覆盖。'
  if (props.form === 'position-grade') return '职级按公司维护（POST /v1/positions/grades）；公司内职级代码唯一，可停用而不删除。'
  return props.form === 'position-editor'
    ? '岗位目录当前不提供编辑既有岗位的完整字段；请新建岗位，或使用编制页更新。'
    : '编制上限通过 PATCH /v1/positions/{id} 提交；服务端拒绝给有在岗人员的岗位停用。'
})

const positionOptions = computed(() => props.rows.filter((row) => row.id !== undefined && row.id !== null))

const submitLabel = computed(() => {
  if (props.form === 'position-requirement') return '追加资格版本'
  if (props.form === 'position-grade') return '保存职级'
  return props.form === 'position-editor' ? '创建岗位' : '保存编制'
})

const selectedPosition = computed(() => {
  const id = draft.position_id.trim()
  if (id === '') return null
  return props.rows.find((row) => String(row.id ?? '') === id) ?? null
})

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function validation(message: string): void {
  formError.value = { code: 'VALIDATION', message, requestId: null, reason: null, fieldErrors: {} }
}

function choosePosition(): void {
  const position = selectedPosition.value
  if (position === null) return
  draft['title'] = rowText(position, 'title', '')
  draft['grade'] = rowText(position, 'grade', '')
  const limit = rowNumber(position, 'headcount_limit')
  draft['headcount_limit'] = limit === null ? '' : String(limit)
  const status = rowText(position, 'status', 'ACTIVE')
  draft['status'] = status === 'DISABLED' ? 'DISABLED' : 'ACTIVE'
}

async function submit(): Promise<void> {
  formError.value = null
  if (props.form === 'position-editor') {
    if (draft.code.trim() === '' || draft.title.trim() === '') {
      validation('请填写岗位编码与岗位名称。')
      return
    }
    submitting.value = true
    try {
      await postRow(
        '/v1/positions',
        {
          org_unit_id: numeric(draft.org_unit_id),
          code: draft.code.trim(),
          title: draft.title.trim(),
          grade: draft.grade.trim() === '' ? null : draft.grade.trim(),
          headcount_limit: numeric(draft.headcount_limit),
          valid_from: draft['valid_from'] === '' ? null : draft.valid_from,
          valid_to: draft['valid_to'] === '' ? null : draft.valid_to,
        },
        'position-create',
      )
      ElMessage.success('岗位已创建，编制由服务端按有效任职实时计算占用。')
      draft['code'] = ''
      draft['title'] = ''
      draft['grade'] = ''
      draft['headcount_limit'] = ''
      emit('saved')
    } catch (error) {
      formError.value = peopleErrorInfo(error)
    } finally {
      submitting.value = false
    }
    return
  }

  if (props.form === 'position-requirement') {
    const positionId = numeric(draft.position_id)
    if (positionId === null) {
      validation('请选择要配置任职资格的岗位。')
      return
    }
    if (draft.requirement_effective_from === '') {
      validation('请填写任职资格生效日期。')
      return
    }
    const requirements: Record<string, unknown> = {
      education: draft.requirement_education.trim() === '' ? null : draft.requirement_education.trim(),
      years: numeric(draft.requirement_years),
      notes: draft.requirement_notes.trim() === '' ? null : draft.requirement_notes.trim(),
    }
    submitting.value = true
    try {
      await postRow(
        `/v1/positions/${positionId}/requirements`,
        { requirements, effective_from: draft.requirement_effective_from },
        'position-requirement',
      )
      ElMessage.success('任职资格已追加为新版本，历史版本保持不变。')
      draft.requirement_education = ''
      draft.requirement_years = ''
      draft.requirement_notes = ''
      draft.requirement_effective_from = ''
      emit('saved')
    } catch (error) {
      formError.value = peopleErrorInfo(error)
    } finally {
      submitting.value = false
    }
    return
  }

  if (props.form === 'position-grade') {
    const level = numeric(draft.grade_level)
    if (draft.grade_code.trim() === '' || draft.grade_name.trim() === '' || level === null) {
      validation('请填写职级代码、职级名称与职等。')
      return
    }
    submitting.value = true
    try {
      await postRow(
        '/v1/positions/grades',
        {
          code: draft.grade_code.trim(),
          name: draft.grade_name.trim(),
          level,
          active: draft.grade_active === 'true',
        },
        'position-grade',
      )
      ElMessage.success('职级已保存；公司内职级代码唯一。')
      draft.grade_code = ''
      draft.grade_name = ''
      draft.grade_level = ''
      emit('saved')
    } catch (error) {
      formError.value = peopleErrorInfo(error)
    } finally {
      submitting.value = false
    }
    return
  }

  const positionId = numeric(draft.position_id)
  if (positionId === null) {
    validation('请选择要调整的岗位。')
    return
  }
  submitting.value = true
  try {
    await patchRow(
      `/v1/positions/${positionId}`,
      {
        title: draft.title.trim() === '' ? undefined : draft.title.trim(),
        grade: draft.grade.trim() === '' ? null : draft.grade.trim(),
        headcount_limit: numeric(draft.headcount_limit),
        status: draft.status,
      },
      'position-update',
    )
    ElMessage.success('岗位已更新；如岗位仍有在岗人员，服务端会拒绝停用。')
    emit('saved')
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="panel people-form-panel">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">{{ formTitle }}</h2>
        <p class="panel__subtitle">{{ formSubtitle }}</p>
      </div>
    </header>
    <div class="panel__body">
      <form class="people-form" @submit.prevent="submit">
        <div class="people-form__grid">
          <template v-if="form === 'position-editor'">
            <label class="people-field"><span>组织编号</span><input v-model="draft.org_unit_id" data-testid="position-org-unit" inputmode="numeric" /></label>
            <label class="people-field"><span>岗位编码 *</span><input v-model="draft.code" data-testid="position-code" /></label>
            <label class="people-field"><span>岗位名称 *</span><input v-model="draft.title" data-testid="position-title" /></label>
            <label class="people-field"><span>职级</span><input v-model="draft.grade" data-testid="position-grade" /></label>
            <label class="people-field"><span>核定编制</span><input v-model="draft.headcount_limit" data-testid="position-headcount-limit" inputmode="numeric" /></label>
          </template>

          <template v-else-if="form === 'position-requirement'">
            <label class="people-field"><span>岗位编号 *</span><input v-model="draft.position_id" data-testid="position-requirement-position" inputmode="numeric" /></label>
            <label class="people-field"><span>生效日期 *</span><input v-model="draft.requirement_effective_from" data-testid="position-requirement-effective" type="date" /></label>
            <label class="people-field"><span>学历要求</span><input v-model="draft.requirement_education" data-testid="position-requirement-education" /></label>
            <label class="people-field"><span>年限要求</span><input v-model="draft.requirement_years" data-testid="position-requirement-years" inputmode="numeric" /></label>
            <label class="people-field people-field--wide"><span>补充说明</span><textarea v-model="draft.requirement_notes" data-testid="position-requirement-notes" rows="2" /></label>
          </template>

          <template v-else-if="form === 'position-grade'">
            <label class="people-field"><span>职级代码 *</span><input v-model="draft.grade_code" data-testid="position-grade-code" /></label>
            <label class="people-field"><span>职级名称 *</span><input v-model="draft.grade_name" data-testid="position-grade-name" /></label>
            <label class="people-field"><span>职等 *</span><input v-model="draft.grade_level" data-testid="position-grade-level" inputmode="numeric" /></label>
            <label class="people-field"><span>启用</span><select v-model="draft.grade_active" data-testid="position-grade-active"><option value="true">是</option><option value="false">否</option></select></label>
          </template>

          <template v-else>
            <label class="people-field"><span>岗位 *</span><select v-model="draft.position_id" data-testid="position-select" @change="choosePosition"><option value="">请选择岗位</option><option v-for="row in positionOptions" :key="String(row.id)" :value="String(row.id)">{{ rowText(row, 'code') }} · {{ rowText(row, 'title') }}</option></select></label>
            <div v-if="selectedPosition" class="people-field people-field--wide people-inline-note">
              已占用 {{ rowNumber(selectedPosition, 'occupancy') ?? 0 }} 人；核定编制 {{ rowNumber(selectedPosition, 'headcount_limit') ?? '未设置' }}；空缺 {{ rowNumber(selectedPosition, 'vacancies') ?? '—' }}。
            </div>
            <label class="people-field"><span>岗位名称</span><input v-model="draft.title" data-testid="position-title" /></label>
            <label class="people-field"><span>职级</span><input v-model="draft.grade" data-testid="position-grade" /></label>
            <label class="people-field"><span>核定编制</span><input v-model="draft.headcount_limit" data-testid="position-headcount-limit" inputmode="numeric" /></label>
            <label class="people-field"><span>状态</span><select v-model="draft.status" data-testid="position-status"><option value="ACTIVE">启用</option><option value="DISABLED">停用</option></select></label>
          </template>
        </div>

        <p v-if="formError" class="people-form__error" role="alert" data-testid="position-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>

        <div class="people-form__actions">
          <button type="submit" class="people-button" :disabled="submitting" data-testid="position-submit">
            {{ submitting ? '提交中…' : submitLabel }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>
