<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePut } from '@/api/resourceClient'
import { useSessionStore } from '@/stores/session'
import { FIELD_PROFILE_LABEL } from '@/utils/status'
import { nextIdempotencyKey, rowText, rowsFrom, type PlatformRow } from '../platformRead'

type FieldProfile = 'P0_PUBLIC' | 'P1_INTERNAL' | 'P2_SENSITIVE' | 'P3_RESTRICTED'
type ProfileAccess = { visible: string; editable: string; exportable: string }

const PROFILES: FieldProfile[] = ['P0_PUBLIC', 'P1_INTERNAL', 'P2_SENSITIVE', 'P3_RESTRICTED']

const ACCESS: Record<FieldProfile, ProfileAccess> = {
  P0_PUBLIC: { visible: '可见', editable: '可编辑', exportable: '可导出' },
  P1_INTERNAL: { visible: '可见（内部）', editable: '可编辑', exportable: '需导出能力' },
  P2_SENSITIVE: { visible: '遮罩', editable: '不可编辑', exportable: '禁止导出' },
  P3_RESTRICTED: { visible: '不可见（P3 持有者除外）', editable: '不可编辑', exportable: '禁止导出' },
}

const BUSINESS_OBJECT_LABELS: Record<string, string> = {
  employee: '员工档案',
  payslip: '工资条',
  compensation: '薪酬档案',
  contract: '劳动合同',
  user: '系统账号',
}

const session = useSessionStore()
const canManage = computed(() => session.can('iam.field.manage'))

const policies = ref<PlatformRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const forbidden = ref(false)
const partial = ref(false)
const message = ref('')
const actionError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})
const drafts = ref<Record<string, FieldProfile>>({})
const confirmKey = ref('')

const FIELD_ERROR_LABELS: Record<string, string> = {
  field_profile: '字段档位',
  field_key: '字段',
}

const rows = computed(() =>
  policies.value.map((row) => {
    const fieldKey = rowText(row, 'field_key', '')
    const businessObject = fieldKey.split('.')[0] ?? ''
    const current = rowText(row, 'profile', 'P0_PUBLIC') as FieldProfile
    return {
      fieldKey,
      businessObject,
      businessObjectLabel: BUSINESS_OBJECT_LABELS[businessObject] ?? businessObject,
      current,
      declared: rowText(row, 'declared_profile', current) as FieldProfile,
      canTighten: row.can_tighten === true,
      history: changeHistory(row),
    }
  }),
)

type HistoryEntry = { changedAt: string; from: string; to: string; actor: string }

function changeHistory(row: PlatformRow): HistoryEntry[] {
  const value = row.change_history
  if (!Array.isArray(value)) return []
  return value
    .filter((entry): entry is PlatformRow => typeof entry === 'object' && entry !== null)
    .map((entry) => ({
      changedAt: rowText(entry, 'changed_at', rowText(entry, 'occurred_at', '')),
      from: rowText(entry, 'from_profile', rowText(entry, 'before', rowText(entry, 'from', ''))),
      to: rowText(entry, 'to_profile', rowText(entry, 'after', rowText(entry, 'to', ''))),
      actor: rowText(entry, 'actor', rowText(entry, 'actor_username', rowText(entry, 'changed_by', ''))),
    }))
    .slice(0, 5)
}

const groups = computed(() => {
  const grouping = new Map<string, { key: string; label: string; rows: typeof rows.value }>()
  for (const row of rows.value) {
    const group = grouping.get(row.businessObject) ?? {
      key: row.businessObject,
      label: row.businessObjectLabel,
      rows: [],
    }
    group.rows.push(row)
    grouping.set(row.businessObject, group)
  }
  return [...grouping.values()].sort((left, right) => left.label.localeCompare(right.label))
})

const confirmRow = computed(() => rows.value.find((row) => row.fieldKey === confirmKey.value) ?? null)
const beforeAccess = computed(() => (confirmRow.value === null ? null : ACCESS[confirmRow.value.current]))
const afterAccess = computed(() => {
  if (confirmRow.value === null) return null
  const draft = drafts.value[confirmRow.value.fieldKey] ?? confirmRow.value.current
  return ACCESS[draft] ?? null
})

function profileText(profile: string): string {
  return FIELD_PROFILE_LABEL[profile as keyof typeof FIELD_PROFILE_LABEL] ?? profile
}

function targetProfile(row: (typeof rows.value)[number]): FieldProfile {
  return drafts.value[row.fieldKey] ?? row.current
}

function setDraft(row: (typeof rows.value)[number], value: string): void {
  if (isProfile(value)) {
    drafts.value[row.fieldKey] = value
    fieldErrors.value = {}
    actionError.value = null
  }
}

function isProfile(value: string): value is FieldProfile {
  return (PROFILES as string[]).includes(value)
}

function requestedProfileError(fieldKey: string): string {
  if (fieldErrors.value.field_profile !== undefined) {
    return `字段档位（${FIELD_ERROR_LABELS.field_profile}）未通过服务端校验，请选择更严格或相同的档位。`
  }
  if (fieldErrors.value.field_key !== undefined) {
    return `字段（${fieldKey}）未在代码敏感字段清单中登记。`
  }
  return '字段策略未通过服务端校验。'
}

function clearFeedback(): void {
  message.value = ''
  actionError.value = null
  fieldErrors.value = {}
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  forbidden.value = false
  partial.value = false
  try {
    const payload = await resourceGet<unknown>('/v1/system/field-policies', { page: 1, page_size: 100 })
    policies.value = rowsFrom(payload)
    partial.value = payload !== null && typeof payload === 'object' && (payload as PlatformRow).partial === true
  } catch (reason) {
    if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      forbidden.value = true
    } else {
      error.value = errorCopy(reason)
    }
  } finally {
    loading.value = false
  }
}

function requestSave(row: (typeof rows.value)[number]): void {
  if (!canManage.value) return
  clearFeedback()
  const target = targetProfile(row)
  if (target === row.current) {
    actionError.value = '目标档位与当前档位相同，没有需要保存的变更。'
    return
  }
  // The server is authoritative for tightening rules; the preview still
  // shows the exact before/after so the operator chooses deliberately.
  confirmKey.value = row.fieldKey
}

async function confirmSave(): Promise<void> {
  const row = confirmRow.value
  if (row === null || !canManage.value) return
  const target = targetProfile(row)
  clearFeedback()
  try {
    await resourcePut<unknown>(
      `/v1/system/field-policies/${encodeURIComponent(row.fieldKey)}`,
      { field_profile: target },
      { idempotencyKey: nextIdempotencyKey('field-policy-update') },
    )
    confirmKey.value = ''
    message.value = '字段策略已保存；读取、写入与导出将统一按新档位裁剪。'
    await load()
  } catch (reason) {
    if (reason instanceof ApiError && reason.code === 'VALIDATION_FAILED') {
      fieldErrors.value = { ...reason.fieldErrors }
      actionError.value = '字段策略未通过服务端校验，请按下方字段提示修正。'
      return
    }
    if (reason instanceof ApiError && reason.code === 'CONFLICT') {
      actionError.value = '服务端拒绝放宽敏感字段档位。字段策略只能收紧，不能放宽。'
      return
    }
    actionError.value = errorCopy(reason)
  }
}

onMounted(load)
</script>

<template>
  <div class="page-stack" data-testid="system-field-policies-page">
    <header class="page-header">
      <div>
        <h1>字段级权限</h1>
        <p class="muted">
          敏感字段由代码清单声明，档位 P0 公开 / P1 内部 / P2 敏感 / P3 受限；策略只能收紧，保存前必须核对可见、可编辑与可导出差异。
        </p>
      </div>
      <div class="platform-inline-actions">
        <span class="plain-chip">{{ rows.length }} 个字段</span>
        <button type="button" class="plain-chip" :disabled="loading" @click="load">刷新</button>
      </div>
    </header>

    <p v-if="!canManage" class="inline-warning">只读：当前账号缺少 iam.field.manage，不能修改字段策略。</p>
    <p v-if="forbidden" class="inline-error" role="alert">你没有读取字段策略的权限。如需访问，请提交授权申请。</p>
    <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
    <p v-if="partial" class="inline-warning">部分字段策略读取失败，已保留成功返回的记录。</p>
    <p v-if="message" class="inline-success">{{ message }}</p>
    <p v-if="actionError" class="inline-error" role="alert">{{ actionError }}</p>
    <p v-if="loading" class="muted" role="status">加载字段策略…</p>

    <p v-if="!loading && !forbidden && !error && rows.length === 0" class="empty-inline">没有返回字段策略。</p>

    <section v-for="group in groups" :key="group.key" class="panel" :data-testid="`field-policy-group-${group.key}`">
      <div class="panel__header">
        <h2 class="panel__title">{{ group.label }}</h2>
        <span class="plain-chip">{{ group.rows.length }} 个字段</span>
      </div>
      <div class="platform-table-wrap">
        <table class="platform-table">
          <caption class="visually-hidden">{{ group.label }}字段策略</caption>
          <thead>
            <tr>
              <th scope="col">字段键 / 当前档位</th>
              <th scope="col">影响范围</th>
              <th scope="col">可收紧</th>
              <th scope="col">目标档位</th>
              <th scope="col">变更历史</th>
              <th scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in group.rows" :key="row.fieldKey" :data-testid="`field-policy-row-${row.fieldKey}`">
              <td>
                <div class="mono">{{ row.fieldKey }}</div>
                <div class="small">当前：{{ profileText(row.current) }} · 声明：{{ profileText(row.declared) }}</div>
              </td>
              <td class="small">
                影响所有持有该字段读取能力的角色与授权绑定；读取、写入、导出统一裁剪。
                <div v-if="row.declared === row.current && !row.canTighten" class="muted">该字段已处于声明上限。</div>
              </td>
              <td>{{ row.canTighten ? '可继续收紧' : '已达声明档位' }}</td>
              <td>
                <select
                  :value="targetProfile(row)"
                  :disabled="!canManage"
                  :data-testid="`field-policy-profile-${row.fieldKey}`"
                  @change="setDraft(row, ($event.target as HTMLSelectElement).value)"
                >
                  <option v-for="profile in PROFILES" :key="profile" :value="profile">{{ profileText(profile) }}</option>
                </select>
              </td>
              <td class="small">
                <ul v-if="row.history.length > 0">
                  <li v-for="entry in row.history" :key="`${entry.changedAt}-${entry.to}`">
                    {{ entry.changedAt }}
                    <span class="mono">{{ profileText(entry.from) }} → {{ profileText(entry.to) }}</span>
                    {{ entry.actor === '' ? '' : `· ${entry.actor}` }}
                  </li>
                </ul>
                <span v-else class="muted">暂无变更历史（接口未返回）。</span>
              </td>
              <td>
                <button
                  type="button"
                  :disabled="!canManage"
                  :data-testid="`field-policy-save-${row.fieldKey}`"
                  @click="requestSave(row)"
                >
                  预览并保存
                </button>
                <p
                  v-if="(fieldErrors.field_profile !== undefined || fieldErrors.field_key !== undefined) && confirmKey === row.fieldKey"
                  class="inline-error"
                  :data-testid="`field-policy-field-error-${row.fieldKey}`"
                >
                  {{ requestedProfileError(row.fieldKey) }}
                </p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="confirmRow" class="panel" :data-testid="`field-policy-confirm-${confirmRow.fieldKey}`">
      <div class="panel__header"><h2 class="panel__title">确认字段策略变更</h2></div>
      <div class="panel__body stack">
        <p>字段 <span class="mono">{{ confirmRow.fieldKey }}</span>：{{ confirmRow.businessObjectLabel }}</p>
        <div class="platform-form-grid">
          <div>
            <strong>变更前</strong>
            <p>{{ profileText(confirmRow.current) }}</p>
            <ul v-if="beforeAccess">
              <li>可见：{{ beforeAccess.visible }}</li>
              <li>可编辑：{{ beforeAccess.editable }}</li>
              <li>可导出：{{ beforeAccess.exportable }}</li>
            </ul>
          </div>
          <div>
            <strong>变更后</strong>
            <p>{{ profileText(targetProfile(confirmRow)) }}</p>
            <ul v-if="afterAccess">
              <li>可见：{{ afterAccess.visible }}</li>
              <li>可编辑：{{ afterAccess.editable }}</li>
              <li>可导出：{{ afterAccess.exportable }}</li>
            </ul>
          </div>
        </div>
        <div class="platform-inline-actions">
          <button
            type="button"
            class="danger-button"
            :data-testid="`field-policy-confirm-button-${confirmRow.fieldKey}`"
            @click="confirmSave"
          >
            确认变更
          </button>
          <button type="button" @click="confirmKey = ''">取消</button>
        </div>
      </div>
    </section>
  </div>
</template>
