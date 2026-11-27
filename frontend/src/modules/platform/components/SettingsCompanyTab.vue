<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getCompanyProfile,
  isForbidden,
  platformErrorCopy,
  updateCompanyProfile,
  type CompanyProfile,
} from '../api/settings'
import { nextIdempotencyKey } from '../platformRead'
import { requestIdFromError } from '@/utils/renderErrors'
import { useSessionStore } from '@/stores/session'

const session = useSessionStore()
const canManage = computed(() => session.can('system.settings.manage'))

const loading = ref(true)
const loadError = ref<unknown>(null)
const profile = ref<CompanyProfile | null>(null)
const saving = ref(false)
const saveError = ref('')
const saveMessage = ref('')

const draft = reactive({
  name: '',
  countryCode: '',
  timezone: '',
  currencyCode: '',
})

const forbidden = computed(() => loadError.value !== null && isForbidden(loadError.value))
const requestId = computed(() => requestIdFromError(loadError.value))

const TIMEZONE_OPTIONS = [
  { value: 'Asia/Shanghai', label: '中国标准时间（上海）' },
  { value: 'Asia/Hong_Kong', label: '中国香港时间' },
  { value: 'Asia/Singapore', label: '新加坡时间' },
  { value: 'UTC', label: '协调世界时' },
]

function syncDraft(next: CompanyProfile): void {
  draft.name = next.name
  draft.countryCode = next.countryCode
  draft.timezone = next.timezone
  draft.currencyCode = next.currencyCode
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  saveMessage.value = ''
  saveError.value = ''
  try {
    profile.value = await getCompanyProfile()
    syncDraft(profile.value)
  } catch (error) {
    profile.value = null
    loadError.value = error
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saveError.value = ''
  saveMessage.value = ''
  const name = draft.name.trim()
  if (name === '') {
    saveError.value = '公司名称不能为空。'
    return
  }
  if (!/^[A-Za-z]{2}$/.test(draft.countryCode.trim())) {
    saveError.value = '国家代码必须是两位 ISO 3166-1 字母代码，例如 CN。'
    return
  }
  if (!/^[A-Za-z]{3}$/.test(draft.currencyCode.trim())) {
    saveError.value = '币种必须是三位 ISO 4217 代码，例如 CNY。'
    return
  }

  saving.value = true
  try {
    profile.value = await updateCompanyProfile({
      name,
      countryCode: draft.countryCode.trim().toUpperCase(),
      timezone: draft.timezone,
      currencyCode: draft.currencyCode.trim().toUpperCase(),
    }, nextIdempotencyKey('company-profile'))
    syncDraft(profile.value)
    saveMessage.value = '公司资料已保存，并已写入审计记录。'
    ElMessage.success(saveMessage.value)
  } catch (error) {
    saveError.value = platformErrorCopy(error)
    ElMessage.error(saveError.value)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="settings-tab" data-testid="settings-company" aria-labelledby="settings-company-title">
    <div class="settings-tab__header">
      <div>
        <h2 id="settings-company-title" class="panel__title">公司资料</h2>
        <p class="panel__subtitle">单公司部署只有一份公司资料；时区决定业务日期，修改会写入审计。</p>
      </div>
      <el-button plain :disabled="loading" @click="load">刷新</el-button>
    </div>

    <div v-if="loading" class="panel__body" data-testid="settings-company-loading">
      <el-skeleton :rows="4" animated />
      <span class="visually-hidden">正在加载公司资料</span>
    </div>

    <div v-else-if="forbidden" class="panel__body" data-testid="settings-company-forbidden">
      <p class="muted">当前账号没有公司资料管理权限。请联系系统管理员授予系统设置能力。</p>
    </div>

    <div v-else-if="loadError !== null" class="panel__body" data-testid="settings-company-error">
      <p class="muted">{{ platformErrorCopy(loadError) }}</p>
      <p v-if="requestId" class="muted small">请求编号：<span class="mono">{{ requestId }}</span></p>
      <el-button type="primary" plain @click="load">重试</el-button>
    </div>

    <form v-else class="settings-tab__form" @submit.prevent="save">
      <p v-if="saveMessage" class="inline-success" data-testid="settings-company-success">{{ saveMessage }}</p>
      <p v-if="saveError" class="inline-error" role="alert" data-testid="settings-company-error-message">{{ saveError }}</p>

      <label class="settings-field">
        <span>公司名称 *</span>
        <el-input v-model="draft.name" maxlength="160" data-testid="settings-company-name" />
      </label>
      <label class="settings-field">
        <span>国家代码 *</span>
        <el-input v-model="draft.countryCode" maxlength="2" data-testid="settings-company-country" placeholder="CN" />
      </label>
      <label class="settings-field">
        <span>时区 *</span>
        <el-select v-model="draft.timezone" data-testid="settings-company-timezone">
          <el-option v-for="zone in TIMEZONE_OPTIONS" :key="zone.value" :label="zone.label" :value="zone.value" />
        </el-select>
      </label>
      <label class="settings-field">
        <span>币种 *</span>
        <el-input v-model="draft.currencyCode" maxlength="3" data-testid="settings-company-currency" placeholder="CNY" />
      </label>

      <div class="settings-tab__actions">
        <el-button
          type="primary"
          native-type="submit"
          :loading="saving"
          :disabled="!canManage"
          data-testid="settings-company-save"
        >
          保存公司资料
        </el-button>
        <span v-if="!canManage" class="muted small">当前账号只读。</span>
      </div>
    </form>
  </section>
</template>

<style scoped>
.settings-tab {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.settings-tab__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.settings-tab__form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
}
.settings-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.settings-tab__actions {
  display: flex;
  grid-column: 1 / -1;
  gap: 10px;
  align-items: center;
}
.inline-success {
  grid-column: 1 / -1;
  padding: 9px 12px;
  margin: 0;
  color: #146c43;
  font-size: 12px;
  background: #e8f6ee;
  border: 1px solid #b7dcc7;
  border-radius: 7px;
}
.inline-error {
  grid-column: 1 / -1;
  padding: 9px 12px;
  margin: 0;
  color: #a12828;
  font-size: 12px;
  background: #fdecec;
  border: 1px solid #efc2c2;
  border-radius: 7px;
}
</style>
