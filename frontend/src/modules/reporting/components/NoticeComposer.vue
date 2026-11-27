<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  audienceText,
  publishNotice,
  type Notice,
  type OrgUnitOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    orgUnits?: OrgUnitOption[]
    busy?: boolean
  }>(),
  { orgUnits: () => [], busy: false },
)

const emit = defineEmits<{
  created: [notice: Notice]
}>()

const title = ref('')
const body = ref('')
const audience = ref<'ALL' | 'ORG'>('ALL')
const orgUnitId = ref<number | null>(null)
const requiresAck = ref(true)

const confirming = ref(false)
const submitting = ref(false)
const formError = ref('')
const successMessage = ref('')

const selectedOrg = computed(() => props.orgUnits.find((unit) => unit.id === orgUnitId.value) ?? null)
const audienceLabel = computed(() => (audience.value === 'ORG' ? selectedOrg.value?.name ?? '指定组织' : '全体员工'))
const confirmationText = computed(() =>
  `确认向${audienceLabel.value}发布「${title.value.trim()}」？发布后目标受众会立即看到公告。`,
)

function requestPublish(): void {
  formError.value = ''
  successMessage.value = ''
  if (title.value.trim().length < 4) {
    formError.value = '公告标题至少需要 4 个字。'
    return
  }
  if (body.value.trim().length < 10) {
    formError.value = '公告正文至少需要 10 个字。'
    return
  }
  if (audience.value === 'ORG' && orgUnitId.value === null) {
    formError.value = '按组织发布时请选择至少一个组织单位。'
    return
  }
  confirming.value = true
}

function cancelConfirm(): void {
  confirming.value = false
}

async function confirmPublish(): Promise<void> {
  confirming.value = false
  submitting.value = true
  formError.value = ''
  try {
    const created = await publishNotice({
      title: title.value.trim(),
      body: body.value.trim(),
      audience: audience.value,
      audienceLabel: audienceLabel.value,
      orgUnitIds: audience.value === 'ORG' && orgUnitId.value !== null ? [orgUnitId.value] : undefined,
      requiresAck: requiresAck.value,
    })
    successMessage.value = `公告已发布：${audienceText(created)}可见。`
    title.value = ''
    body.value = ''
    audience.value = 'ALL'
    orgUnitId.value = null
    requiresAck.value = true
    emit('created', created)
  } catch {
    formError.value = '公告未发布，请稍后重试；如反复失败请联系管理员。'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="panel notice-composer" data-testid="notice-composer">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">发布公告</h2>
        <p class="panel__subtitle">发布前确认受众；范围只使用组织单位，不使用租户或手填范围说明。</p>
      </div>
    </div>

    <form class="panel__body notice-composer__form" @submit.prevent="requestPublish">
      <label class="composer-field">
        <span>标题</span>
        <input v-model="title" data-testid="notice-title" maxlength="200" placeholder="例：十一月薪酬发放安排" />
      </label>

      <label class="composer-field">
        <span>正文</span>
        <textarea v-model="body" data-testid="notice-body" rows="6" maxlength="20000" placeholder="填写员工实际看到的公告正文" />
      </label>

      <div class="composer-row">
        <label class="composer-field">
          <span>发布范围</span>
          <select v-model="audience" data-testid="notice-audience">
            <option value="ALL">全体员工</option>
            <option value="ORG">指定组织</option>
          </select>
        </label>

        <label v-if="audience === 'ORG'" class="composer-field">
          <span>组织单位</span>
          <select v-model="orgUnitId" data-testid="notice-org-unit">
            <option :value="null">请选择组织</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
          </select>
        </label>

        <label class="composer-field composer-field--check">
          <input v-model="requiresAck" type="checkbox" data-testid="notice-requires-ack" />
          <span>需要阅读确认</span>
        </label>
      </div>

      <p v-if="formError" class="composer-error" role="alert" data-testid="notice-form-error">{{ formError }}</p>
      <p v-if="successMessage" class="composer-success" role="status" data-testid="notice-success">{{ successMessage }}</p>

      <div class="composer-actions">
        <el-button type="primary" :loading="submitting || busy" data-testid="notice-publish" @click="requestPublish">
          发布公告
        </el-button>
      </div>
    </form>

    <div v-if="confirming" class="composer-confirm" role="alertdialog" aria-modal="true" data-testid="notice-confirm">
      <h3 class="composer-confirm__title">确认发布公告</h3>
      <p class="composer-confirm__copy">{{ confirmationText }}</p>
      <p class="small muted">
        受众：{{ audienceLabel }} · 阅读确认：{{ requiresAck ? '需要' : '不需要' }}
      </p>
      <div class="composer-confirm__actions">
        <el-button data-testid="notice-confirm-cancel" @click="cancelConfirm">返回修改</el-button>
        <el-button type="primary" data-testid="notice-confirm-publish" :loading="submitting" @click="confirmPublish">
          确认发布
        </el-button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.notice-composer__form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.composer-field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: 11.5px;
  color: var(--color-ink-secondary);
}

.composer-field input,
.composer-field select,
.composer-field textarea {
  padding: 8px 10px;
  font: inherit;
  color: var(--color-ink);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.composer-field--check {
  flex-direction: row;
  align-items: center;
  gap: 8px;
}

.composer-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.composer-error,
.composer-success {
  margin: 0;
  padding: 9px 11px;
  font-size: 12px;
  border-radius: var(--radius-sm);
}

.composer-error {
  color: #8a2c2c;
  background: #fff4f2;
  border: 1px solid #f0c7c0;
}

.composer-success {
  color: #1d5b43;
  background: #f1faf5;
  border: 1px solid #bfe3d0;
}

.composer-confirm {
  margin: 0 18px 18px;
  padding: 14px 16px;
  border: 1px solid #e7c98a;
  border-radius: var(--radius-md);
  background: #fffbf0;
}

.composer-confirm__title {
  margin: 0 0 6px;
  font-size: 14px;
}

.composer-confirm__copy {
  margin: 0 0 4px;
  font-size: 13px;
  line-height: 1.55;
}

.composer-confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}

@media (max-width: 760px) {
  .composer-row {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
