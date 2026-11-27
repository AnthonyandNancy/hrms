<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import {
  listPositionGrades,
  upsertPositionGrade,
  type PositionGrade,
  type PositionGradeInput,
} from '../api'

const props = defineProps<{
  asOf: string
  canManage: boolean
}>()

defineOptions({ name: 'PositionGradesPanel' })

type GradeDraft = {
  code: string
  name: string
  level: string
  active: boolean
}

const grades = ref<PositionGrade[]>([])
const loading = ref(false)
const loadError = ref<unknown>(null)
const saving = ref(false)
const saveError = ref<ApiError | null>(null)
const localError = ref('')
const editorOpen = ref(false)
const editorMode = ref<'create' | 'edit'>('create')

const draft = reactive<GradeDraft>({
  code: '',
  name: '',
  level: '',
  active: true,
})

let requestSequence = 0

const loadErrorMessage = computed(() =>
  loadError.value === null ? '职级目录暂时无法加载，请稍后重试。' : errorCopy(loadError.value),
)

const fieldErrors = computed<Record<string, string[]>>(() => saveError.value?.fieldErrors ?? {})

function firstFieldError(field: string): string {
  const messages = fieldErrors.value[field]
  return Array.isArray(messages) && messages.length > 0 ? messages[0] ?? '' : ''
}

function resetDraft(): void {
  draft.code = ''
  draft.name = ''
  draft.level = ''
  draft.active = true
  saveError.value = null
  localError.value = ''
}

async function load(): Promise<void> {
  if (!props.canManage) {
    grades.value = []
    loadError.value = null
    return
  }

  const sequence = ++requestSequence
  loading.value = true
  loadError.value = null

  try {
    const next = await listPositionGrades(props.asOf, { pageSize: 100 })
    if (sequence !== requestSequence) return
    grades.value = next
  } catch (error) {
    if (sequence !== requestSequence) return
    grades.value = []
    loadError.value = error
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

function openCreate(): void {
  if (!props.canManage) return
  editorMode.value = 'create'
  resetDraft()
  editorOpen.value = true
}

function openEdit(grade: PositionGrade): void {
  if (!props.canManage) return
  editorMode.value = 'edit'
  saveError.value = null
  localError.value = ''
  draft.code = grade.code
  draft.name = grade.name
  draft.level = String(grade.level)
  draft.active = grade.active
  editorOpen.value = true
}

function validate(): boolean {
  const level = Number(draft.level)
  if (draft.code.trim() === '' || draft.name.trim() === '') {
    localError.value = '请填写职级代码与名称。'
    return false
  }
  if (!/^\d+$/.test(draft.level.trim()) || !Number.isSafeInteger(level) || level > 9999) {
    localError.value = '职级序号必须是 0 到 9999 的整数。'
    return false
  }
  return true
}

async function save(): Promise<void> {
  localError.value = ''
  saveError.value = null

  if (!props.canManage || !validate()) return

  const payload: PositionGradeInput = {
    code: draft.code.trim(),
    name: draft.name.trim(),
    level: Number(draft.level),
    active: draft.active,
  }

  saving.value = true
  try {
    await upsertPositionGrade(payload)
    ElMessage.success(editorMode.value === 'create' ? '职级已创建。' : '职级已更新。')
    editorOpen.value = false
    await load()
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : null
    if (saveError.value === null) {
      localError.value = error instanceof Error ? error.message : '职级保存失败。'
    }
  } finally {
    saving.value = false
  }
}

watch(
  () => [props.asOf, props.canManage] as const,
  () => {
    void load()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <section class="grades-panel" data-testid="position-grades-panel">
    <p v-if="!canManage" class="grades-panel__state" data-testid="position-grades-gap">
      当前账号缺少 org.position.grade.manage 能力，无法查看或维护职级目录。
    </p>

    <template v-else>
      <header class="grades-panel__header">
        <div>
          <h3 class="grades-panel__title">职级 / 职等</h3>
          <p class="grades-panel__subtitle">
            职级由公司统一维护；岗位引用职级代码。停用职级不会改写既有岗位。
          </p>
        </div>
        <div class="grades-panel__actions">
          <button
            type="button"
            class="grades-panel__button"
            data-testid="position-grades-refresh"
            :disabled="loading"
            @click="load"
          >
            刷新
          </button>
          <button
            type="button"
            class="grades-panel__button grades-panel__button--primary"
            data-testid="position-grade-create"
            @click="openCreate"
          >
            新增职级
          </button>
        </div>
      </header>

      <p v-if="loading" class="grades-panel__state" data-testid="position-grades-loading">
        正在加载职级目录…
      </p>
      <p
        v-else-if="loadError !== null"
        class="grades-panel__state grades-panel__state--error"
        data-testid="position-grades-error"
      >
        {{ loadErrorMessage }}
        <button type="button" class="grades-panel__link" @click="load">重试</button>
      </p>
      <p
        v-else-if="grades.length === 0"
        class="grades-panel__state"
        data-testid="position-grades-empty"
      >
        尚未维护职级目录。新增职级后，岗位表单即可选择。
      </p>

      <div v-else class="grades-panel__table-wrap">
        <table class="grades-panel__table" data-testid="position-grades-table">
          <caption class="visually-hidden">职级目录</caption>
          <thead>
            <tr>
              <th scope="col">职级代码</th>
              <th scope="col">名称</th>
              <th scope="col">序号</th>
              <th scope="col">状态</th>
              <th scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="grade in grades" :key="grade.id" :data-testid="`position-grade-row-${grade.code}`">
              <td class="mono">{{ grade.code }}</td>
              <td>{{ grade.name }}</td>
              <td>{{ grade.level }}</td>
              <td>
                <span :class="grade.active ? 'grades-panel__active' : 'grades-panel__inactive'">
                  {{ grade.active ? '启用' : '停用' }}
                </span>
              </td>
              <td>
                <button
                  type="button"
                  class="grades-panel__link"
                  :data-testid="`position-grade-edit-${grade.code}`"
                  @click="openEdit(grade)"
                >
                  编辑
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="editorOpen" class="grades-panel__editor" data-testid="position-grade-editor">
        <h4>{{ editorMode === 'create' ? '新增职级' : '编辑职级' }}</h4>
        <label class="grades-panel__field">
          <span>职级代码 *</span>
          <input
            v-model="draft.code"
            data-testid="position-grade-code"
            maxlength="32"
            :disabled="editorMode === 'edit'"
            autocomplete="off"
          />
          <small v-if="firstFieldError('code') !== ''" class="grades-panel__error">
            {{ firstFieldError('code') }}
          </small>
        </label>
        <label class="grades-panel__field">
          <span>名称 *</span>
          <input v-model="draft.name" data-testid="position-grade-name" maxlength="120" autocomplete="off" />
          <small v-if="firstFieldError('name') !== ''" class="grades-panel__error">
            {{ firstFieldError('name') }}
          </small>
        </label>
        <label class="grades-panel__field">
          <span>序号 *</span>
          <input v-model="draft.level" data-testid="position-grade-level" inputmode="numeric" autocomplete="off" />
          <small v-if="firstFieldError('level') !== ''" class="grades-panel__error">
            {{ firstFieldError('level') }}
          </small>
        </label>
        <label class="grades-panel__field grades-panel__field--inline">
          <input v-model="draft.active" type="checkbox" data-testid="position-grade-active" />
          <span>启用</span>
        </label>

        <p v-if="localError !== ''" class="grades-panel__state grades-panel__state--error" role="alert">
          {{ localError }}
        </p>
        <p v-if="saveError !== null" class="grades-panel__state grades-panel__state--error" role="alert">
          提交内容未通过校验：{{ saveError.message }}
        </p>

        <div class="grades-panel__actions">
          <button type="button" class="grades-panel__button" @click="editorOpen = false">取消</button>
          <button
            type="button"
            class="grades-panel__button grades-panel__button--primary"
            data-testid="position-grade-save"
            :disabled="saving"
            @click="save"
          >
            {{ saving ? '保存中…' : '保存' }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.grades-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.grades-panel__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.grades-panel__title {
  margin: 0;
  font-size: 13px;
  font-weight: 650;
}

.grades-panel__subtitle {
  margin: 3px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.grades-panel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

.grades-panel__state {
  margin: 0;
  padding: 10px 12px;
  color: var(--color-ink-muted);
  font-size: 12px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}

.grades-panel__state--error {
  color: var(--color-danger);
  background: var(--color-danger-soft);
}

.grades-panel__button {
  padding: 6px 12px;
  color: var(--color-brand);
  font-size: 12px;
  font-weight: 600;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.grades-panel__button--primary {
  color: #fff;
  background: var(--color-brand);
  border-color: var(--color-brand);
}

.grades-panel__button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.grades-panel__link {
  padding: 0;
  color: var(--color-brand);
  font-size: 11px;
  background: transparent;
  border: 0;
  text-decoration: underline;
  cursor: pointer;
}

.grades-panel__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.grades-panel__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.grades-panel__table th,
.grades-panel__table td {
  padding: 8px 10px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line);
}

.grades-panel__table th {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
  background: var(--color-canvas);
}

.grades-panel__active {
  color: var(--color-success);
}

.grades-panel__inactive {
  color: var(--color-ink-muted);
}

.grades-panel__editor {
  display: grid;
  gap: 10px;
  padding: 12px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}

.grades-panel__editor h4 {
  margin: 0;
  font-size: 12px;
}

.grades-panel__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.grades-panel__field--inline {
  flex-direction: row;
  gap: 6px;
  align-items: center;
}

.grades-panel__field input:not([type='checkbox']) {
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.grades-panel__field input:disabled {
  color: var(--color-ink-muted);
  background: var(--color-canvas);
}

.grades-panel__error {
  color: var(--color-danger);
  font-size: 11px;
}
</style>
