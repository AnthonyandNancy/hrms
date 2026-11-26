<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { ApiError } from '@/api/errors'
import {
  REQUIRED_REQUIREMENT_FIELDS,
  listPositionRequirements,
  requirementFieldLabel,
  requirementValueText,
  versionPositionRequirements,
  type PositionRequirementPage,
  type PositionRequirementVersion,
} from '../api'

const props = defineProps<{
  positionId: number | null
  asOf: string
  canManage: boolean
}>()

defineOptions({ name: 'PositionRequirementsPanel' })

type RequirementDraftRow = {
  key: string
  label: string
  value: string
  baseline: string
}

const loading = ref(false)
const loadError = ref('')
const loadRequestId = ref<string | null>(null)
const page = ref<PositionRequirementPage | null>(null)
const draftRows = ref<RequirementDraftRow[]>([])
const effectiveFrom = ref('')
const saving = ref(false)
const saveError = ref<ApiError | null>(null)
const saveLocalError = ref('')

let requestSequence = 0

const latest = computed<PositionRequirementVersion | null>(() => page.value?.items[0] ?? null)

const versionHistory = computed(() => page.value?.items ?? [])

const changedRows = computed(() =>
  draftRows.value.filter((row) => row.value !== row.baseline),
)

const fieldErrors = computed<Record<string, string[]>>(() => saveError.value?.fieldErrors ?? {})

function firstFieldError(field: string): string {
  const messages = fieldErrors.value[field]
  return Array.isArray(messages) && messages.length > 0 ? messages[0] ?? '' : ''
}

function buildDraftRows(version: PositionRequirementVersion | null): RequirementDraftRow[] {
  const requirements = version?.requirements ?? {}
  const keys = [
    ...REQUIRED_REQUIREMENT_FIELDS.map((field) => field.key),
    ...Object.keys(requirements).filter(
      (key) => !REQUIRED_REQUIREMENT_FIELDS.some((field) => field.key === key),
    ),
  ]

  return [...new Set(keys)].map((key) => {
    const value = requirementValueText(requirements[key])
    return { key, label: requirementFieldLabel(key), value, baseline: value }
  })
}

function hydrateDraft(): void {
  draftRows.value = buildDraftRows(latest.value)
  effectiveFrom.value = props.asOf
  saveError.value = null
  saveLocalError.value = ''
}

async function load(): Promise<void> {
  const positionId = props.positionId
  if (positionId === null) {
    page.value = null
    draftRows.value = []
    loadError.value = ''
    return
  }
  if (!props.canManage) {
    page.value = null
    draftRows.value = []
    loadError.value = ''
    return
  }

  const sequence = ++requestSequence
  loading.value = true
  loadError.value = ''
  loadRequestId.value = null
  try {
    const next = await listPositionRequirements(positionId, props.asOf)
    if (sequence !== requestSequence) return
    page.value = next
    hydrateDraft()
  } catch (error) {
    if (sequence !== requestSequence) return
    page.value = null
    draftRows.value = []
    loadError.value = error instanceof Error ? error.message : '任职资格加载失败。'
    loadRequestId.value = error instanceof ApiError ? error.requestId : null
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

function summary(version: PositionRequirementVersion): string {
  const entries = Object.entries(version.requirements)
    .map(([key, value]) => `${requirementFieldLabel(key)}：${requirementValueText(value) || '—'}`)
  return entries.length === 0 ? '无任职条件' : entries.join('；')
}

async function save(): Promise<void> {
  saveError.value = null
  saveLocalError.value = ''

  if (props.positionId === null) {
    saveLocalError.value = '请先选择岗位。'
    return
  }
  if (effectiveFrom.value === '') {
    saveLocalError.value = '请填写任职资格生效日期。'
    return
  }
  if (changedRows.value.length === 0) {
    saveLocalError.value = '没有变更的任职条件行；未提交新版本。'
    return
  }

  const requirements: Record<string, string> = {}
  for (const row of changedRows.value) requirements[row.key] = row.value.trim()

  saving.value = true
  try {
    await versionPositionRequirements(props.positionId, {
      requirements,
      effectiveFrom: effectiveFrom.value,
    })
    ElMessage.success('任职资格已追加为新版本，历史版本保持不变。')
    await load()
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : null
    if (saveError.value === null) {
      saveLocalError.value = error instanceof Error ? error.message : '任职资格保存失败。'
    }
  } finally {
    saving.value = false
  }
}

watch(
  () => [props.positionId, props.asOf, props.canManage] as const,
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
  <section class="requirements-panel" data-testid="position-requirements">
    <header class="requirements-panel__header">
      <div>
        <h3 class="requirements-panel__title">任职资格</h3>
        <p class="requirements-panel__subtitle">
          资格版本只追加、不覆盖；保存时仅提交发生变化的行，并立即重新读取版本列表。
        </p>
      </div>
      <button
        type="button"
        class="requirements-panel__refresh"
        data-testid="position-requirements-refresh"
        :disabled="loading || !canManage || positionId === null"
        @click="load"
      >
        刷新
      </button>
    </header>

    <p
      v-if="!canManage"
      class="requirements-panel__state"
      data-testid="position-requirements-gap"
    >
      当前账号缺少 org.position.qualification.manage 能力，任职资格只读不可编辑。
    </p>

    <template v-else>
      <p v-if="positionId === null" class="requirements-panel__state">请先选择一个岗位查看任职资格。</p>
      <p v-else-if="loading" class="requirements-panel__state" data-testid="position-requirements-loading">
        正在加载任职资格…
      </p>
      <p
        v-else-if="loadError !== ''"
        class="requirements-panel__state requirements-panel__state--error"
        data-testid="position-requirements-error"
      >
        {{ loadError }}
        <span v-if="loadRequestId">（请求编号 {{ loadRequestId }}）</span>
        <button type="button" class="requirements-panel__link" @click="load">重试</button>
      </p>

      <template v-else>
        <div v-if="latest === null" class="requirements-panel__empty" data-testid="position-requirements-empty">
          该岗位暂无任职资格版本。请在下方填写技能与资格要求并保存首个版本。
        </div>
        <div v-else class="requirements-panel__latest" data-testid="position-requirements-latest">
          <span>当前版本：第 {{ latest.version }} 版</span>
          <span>生效日期：{{ latest.effective_from }}</span>
        </div>

        <div class="requirements-panel__editor">
          <label
            v-for="row in draftRows"
            :key="row.key"
            class="requirements-panel__field"
          >
            <span>{{ row.label }}</span>
            <input
              v-model="row.value"
              :data-testid="`requirement-input-${row.key}`"
              autocomplete="off"
            />
            <small v-if="row.value !== row.baseline" class="requirements-panel__changed">已修改</small>
          </label>

          <label class="requirements-panel__field">
            <span>生效日期 *</span>
            <input
              v-model="effectiveFrom"
              type="date"
              data-testid="position-requirement-effective-from"
            />
            <small v-if="firstFieldError('effective_from') !== ''" class="requirements-panel__error">
              {{ firstFieldError('effective_from') }}
            </small>
          </label>

          <div class="requirements-panel__actions">
            <span class="requirements-panel__changed-count" data-testid="requirements-changed-count">
              已修改 {{ changedRows.length }} 行
            </span>
            <button
              type="button"
              class="requirements-panel__save"
              data-testid="position-requirements-save"
              :disabled="saving"
              @click="save"
            >
              {{ saving ? '保存中…' : '保存为新版本' }}
            </button>
          </div>

          <p
            v-if="saveError !== null"
            class="requirements-panel__error"
            role="alert"
            data-testid="position-requirements-error-save"
          >
            提交内容未通过校验：{{ saveError.message }}
            <span v-if="saveError.requestId">（请求编号 {{ saveError.requestId }}）</span>
          </p>
          <p
            v-else-if="saveLocalError !== ''"
            class="requirements-panel__error"
            role="alert"
            data-testid="position-requirements-error-save"
          >
            {{ saveLocalError }}
          </p>
        </div>

        <div v-if="versionHistory.length > 0" class="requirements-panel__history">
          <h4 class="requirements-panel__history-title">历史版本</h4>
          <ul class="requirements-panel__history-list">
            <li v-for="version in versionHistory" :key="version.id">
              <strong>第 {{ version.version }} 版</strong>
              <span>{{ version.effective_from }}</span>
              <span>{{ summary(version) }}</span>
            </li>
          </ul>
        </div>
      </template>
    </template>
  </section>
</template>

<style scoped>
.requirements-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.requirements-panel__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.requirements-panel__title {
  margin: 0;
  font-size: 13px;
  font-weight: 650;
}

.requirements-panel__subtitle {
  margin: 3px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.requirements-panel__refresh,
.requirements-panel__save,
.requirements-panel__link {
  color: var(--color-brand);
  font-size: 11px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.requirements-panel__refresh {
  padding: 4px 9px;
}

.requirements-panel__link {
  padding: 0;
  border: 0;
  text-decoration: underline;
}

.requirements-panel__state,
.requirements-panel__empty {
  padding: 10px 12px;
  color: var(--color-ink-muted);
  font-size: 12px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}

.requirements-panel__state--error,
.requirements-panel__error {
  color: var(--color-danger);
  background: var(--color-danger-soft);
}

.requirements-panel__latest {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  padding: 8px 10px;
  color: var(--color-ink-secondary);
  font-size: 11px;
  background: var(--color-brand-soft);
  border-radius: var(--radius-sm);
}

.requirements-panel__editor {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 12px;
}

.requirements-panel__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.requirements-panel__field > span {
  font-weight: 600;
}

.requirements-panel__field input {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.requirements-panel__changed {
  color: var(--color-warning);
  font-size: 10px;
}

.requirements-panel__error {
  grid-column: span 2;
  padding: 8px 10px;
  font-size: 12px;
  border-radius: var(--radius-sm);
}

.requirements-panel__actions {
  display: flex;
  grid-column: span 2;
  justify-content: flex-end;
  gap: 10px;
  align-items: center;
}

.requirements-panel__changed-count {
  color: var(--color-ink-muted);
  font-size: 11px;
}

.requirements-panel__save {
  padding: 6px 12px;
  color: #fff;
  background: var(--color-brand);
  border-color: var(--color-brand);
}

.requirements-panel__save:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.requirements-panel__history-title {
  margin: 0 0 6px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  font-weight: 650;
}

.requirements-panel__history-list {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin: 0;
  padding: 0;
  list-style: none;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.requirements-panel__history-list li {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
  padding: 5px 8px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}
</style>
