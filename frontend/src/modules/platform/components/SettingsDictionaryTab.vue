<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  isForbidden,
  listDictionaries,
  platformErrorCopy,
  updateDictionaryEntry,
  type DictionaryEntry,
} from '../api/settings'
import { nextIdempotencyKey } from '../platformRead'
import { requestIdFromError } from '@/utils/renderErrors'
import { useSessionStore } from '@/stores/session'

const session = useSessionStore()
const canManage = computed(() => session.can('system.dictionary.manage'))

const loading = ref(true)
const loadError = ref<unknown>(null)
const rows = ref<DictionaryEntry[]>([])
const total = ref(0)
const saving = ref(false)
const actionError = ref('')
const actionMessage = ref('')
const editing = ref<DictionaryEntry | null>(null)
const draft = reactive({ label: '', sortOrder: 0, active: true })

const forbidden = computed(() => loadError.value !== null && isForbidden(loadError.value))
const isEmpty = computed(() => !loading.value && loadError.value === null && rows.value.length === 0)
const requestId = computed(() => requestIdFromError(loadError.value))

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const result = await listDictionaries()
    rows.value = result.items
    total.value = result.total
  } catch (error) {
    rows.value = []
    total.value = 0
    loadError.value = error
  } finally {
    loading.value = false
  }
}

function openEdit(entry: DictionaryEntry): void {
  if (!canManage.value) return
  editing.value = entry
  draft.label = entry.label
  draft.sortOrder = entry.sortOrder
  draft.active = entry.active
  actionError.value = ''
}

async function save(): Promise<void> {
  if (editing.value === null) return
  const label = draft.label.trim()
  if (label === '') {
    actionError.value = '字典名称不能为空。'
    return
  }
  saving.value = true
  actionError.value = ''
  actionMessage.value = ''
  try {
    await updateDictionaryEntry(editing.value.id, {
      label,
      sortOrder: draft.sortOrder,
      active: draft.active,
    }, nextIdempotencyKey(`dictionary-${editing.value.id}`))
    actionMessage.value = `字典项 ${editing.value.type}.${editing.value.code} 已更新，并已写入审计记录。`
    ElMessage.success(actionMessage.value)
    editing.value = null
    await load()
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="settings-tab" data-testid="settings-dictionaries" aria-labelledby="settings-dictionary-title">
    <div class="settings-tab__header">
      <div>
        <h2 id="settings-dictionary-title" class="panel__title">字典项</h2>
        <p class="panel__subtitle">字典类型与代码由服务端目录定义；这里只修改已存在条目的名称、排序与启停。共 {{ total }} 项。</p>
      </div>
      <el-button plain :disabled="loading" @click="load">刷新</el-button>
    </div>

    <p v-if="actionMessage" class="inline-success" data-testid="settings-action-message">{{ actionMessage }}</p>
    <p v-if="actionError" class="inline-error" role="alert" data-testid="settings-action-error">{{ actionError }}</p>

    <div v-if="loading" class="panel__body" data-testid="settings-dictionaries-loading">
      <el-skeleton :rows="3" animated />
      <span class="visually-hidden">正在加载字典项</span>
    </div>
    <div v-else-if="forbidden" class="panel__body" data-testid="settings-dictionaries-forbidden">
      <p class="muted">当前账号没有字典维护能力。</p>
    </div>
    <div v-else-if="loadError !== null" class="panel__body" data-testid="settings-dictionaries-error">
      <p class="muted">{{ platformErrorCopy(loadError) }}</p>
      <p v-if="requestId" class="muted small">请求编号：<span class="mono">{{ requestId }}</span></p>
      <el-button type="primary" plain @click="load">重试</el-button>
    </div>
    <div v-else-if="isEmpty" class="panel__body" data-testid="settings-dictionaries-empty">
      <p class="muted">服务端当前没有可展示的字典条目；页面不会创建未登记的类型或代码。</p>
    </div>
    <ul v-else class="settings-list">
      <li v-for="entry in rows" :key="entry.id" class="settings-row" :data-testid="`settings-dictionary-${entry.id}`">
        <div class="settings-row__main">
          <div class="settings-row__heading">
            <strong>{{ entry.label }}</strong>
            <span class="plain-chip plain-chip--info">{{ entry.type }} · {{ entry.code }}</span>
            <span class="plain-chip" :class="entry.active ? 'plain-chip--success' : 'plain-chip--muted'">{{ entry.active ? '启用' : '停用' }}</span>
          </div>
          <p class="settings-row__description">排序 {{ entry.sortOrder }}</p>
        </div>
        <div class="settings-row__actions">
          <el-button v-if="canManage" text size="small" :data-testid="`settings-dictionary-edit-${entry.id}`" @click="openEdit(entry)">编辑</el-button>
          <span v-else class="muted small">只读</span>
        </div>
      </li>
    </ul>

    <el-dialog :model-value="editing !== null" title="编辑字典项" width="480px" :close-on-click-modal="false" data-testid="dictionary-dialog" @close="editing = null">
      <div v-if="editing !== null" class="settings-dialog">
        <p class="muted small">{{ editing.type }} · {{ editing.code }}</p>
        <label class="settings-field"><span>字典名称 *</span><el-input v-model="draft.label" data-testid="dictionary-label" maxlength="160" /></label>
        <label class="settings-field"><span>排序</span><el-input-number v-model="draft.sortOrder" :min="0" :max="100000" data-testid="dictionary-sort-order" /></label>
        <el-checkbox :model-value="draft.active" data-testid="dictionary-active" @change="draft.active = Boolean($event)">启用该字典项</el-checkbox>
      </div>
      <template #footer>
        <el-button @click="editing = null">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="dictionary-save" @click="save">保存字典项</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.settings-tab { display: flex; flex-direction: column; gap: 14px; }
.settings-tab__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.settings-list { display: flex; flex-direction: column; padding: 0; margin: 0; list-style: none; }
.settings-row { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 13px 16px; border-top: 1px solid var(--color-line); }
.settings-row__main { flex: 1 1 360px; min-width: 260px; }
.settings-row__heading { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; font-size: 13px; }
.settings-row__description { margin: 5px 0 0; color: var(--color-ink-secondary); font-size: 12px; }
.settings-row__actions { display: flex; gap: 8px; align-items: center; }
.settings-dialog { display: flex; flex-direction: column; gap: 12px; }
.settings-field { display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 600; }
.inline-success, .inline-error { padding: 9px 12px; margin: 0; font-size: 12px; border-radius: 7px; }
.inline-success { color: #146c43; background: #e8f6ee; border: 1px solid #b7dcc7; }
.inline-error { color: #a12828; background: #fdecec; border: 1px solid #efc2c2; }
.plain-chip--success { color: #146c43; background: #e8f6ee; border-color: #b7dcc7; }
.plain-chip--info { color: #0f5466; background: #eaf4f7; border-color: #c4dde4; }
.plain-chip--muted { color: var(--color-ink-muted); }
</style>
