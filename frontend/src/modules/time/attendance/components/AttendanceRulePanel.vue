<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import ActionGate from '@/components/ActionGate.vue'
import EmptyState from '@/components/EmptyState.vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  newAttendanceIdempotencyKey,
  publishAttendanceRule,
  type AttendanceRuleVersion,
} from '../api'

const props = defineProps<{
  rules: AttendanceRuleVersion[]
  loading: boolean
  error: string
}>()

const emit = defineEmits<{
  changed: [message: string]
}>()

const saving = ref(false)
const actionMessage = ref('')
const actionError = ref('')

const form = reactive({
  version: '',
  effectiveFrom: '',
  lateGraceSource: 'SHIFT',
  countBreak: true,
  overtimeAfterScheduled: true,
  incompleteThresholdMinutes: '1',
})

function configLabel(rule: AttendanceRuleVersion): string {
  const config = rule.config
  const bits: string[] = []
  if (typeof config.late_grace_source === 'string') bits.push(`宽限来源 ${config.late_grace_source}`)
  if (typeof config.count_break === 'boolean') bits.push(config.count_break ? '扣除休息' : '不扣休息')
  if (typeof config.overtime_after_scheduled === 'boolean') bits.push(config.overtime_after_scheduled ? '排班后计加班' : '不按排班计加班')
  if (typeof config.incomplete_threshold_minutes === 'number') bits.push(`不完整阈值 ${config.incomplete_threshold_minutes} 分钟`)
  return bits.length === 0 ? '配置由服务端返回，当前无可显示键' : bits.join(' · ')
}

const sortedRules = computed(() =>
  [...props.rules].sort((left, right) => {
    if (left.effectiveFrom !== right.effectiveFrom) return right.effectiveFrom.localeCompare(left.effectiveFrom)
    return right.id - left.id
  }),
)

async function submit(): Promise<void> {
  actionMessage.value = ''
  actionError.value = ''

  if (!/^[A-Za-z0-9._-]{3,32}$/.test(form.version.trim())) {
    actionError.value = '规则版本号需要 3 到 32 个字母、数字、点、下划线或短横线。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveFrom)) {
    actionError.value = '请选择有效的规则生效日期。'
    return
  }
  if (!/^[1-9]\d*$/.test(form.incompleteThresholdMinutes)) {
    actionError.value = '不完整判定阈值必须是正整数分钟。'
    return
  }

  saving.value = true
  try {
    const published = await publishAttendanceRule(
      {
        version: form.version.trim(),
        effectiveFrom: form.effectiveFrom,
        config: {
          late_grace_source: form.lateGraceSource,
          count_break: form.countBreak,
          overtime_after_scheduled: form.overtimeAfterScheduled,
          incomplete_threshold_minutes: Number(form.incompleteThresholdMinutes),
        },
      },
      newAttendanceIdempotencyKey('attendance-rule'),
    )
    actionMessage.value = `规则版本已发布：${published.version}（${published.effectiveFrom} 生效）`
    emit('changed', actionMessage.value)
  } catch (reason) {
    actionError.value = reason instanceof ApiError
      ? `${errorCopy(reason)}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
      : errorCopy(reason)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="attendance-rules" data-testid="attendance-rules-tab" aria-labelledby="attendance-rules-title">
    <header class="attendance-rules__header">
      <div>
        <h2 id="attendance-rules-title">考勤规则</h2>
        <p class="muted">规则按生效日期追加版本；已发布版本不可改写，重新发布同版本不同配置会返回冲突。</p>
      </div>
      <span class="attendance-rules__count">{{ rules.length }} 个版本</span>
    </header>

    <p v-if="error" class="attendance-rules__error" role="alert">{{ error }}</p>
    <p v-if="actionMessage" class="attendance-rules__success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError" class="attendance-rules__error" role="alert">{{ actionError }}</p>

    <ActionGate capability="time.attendance.rule.manage">
      <form class="attendance-rules__form" data-testid="attendance-rule-form" @submit.prevent="submit">
        <h3>发布新规则版本</h3>
        <div class="attendance-rules__grid">
          <label>
            <span>版本号</span>
            <input v-model="form.version" data-testid="attendance-rule-version" maxlength="32" required />
          </label>
          <label>
            <span>生效日</span>
            <input v-model="form.effectiveFrom" data-testid="attendance-rule-effective-from" type="date" required />
          </label>
          <label>
            <span>迟到宽限来源</span>
            <select v-model="form.lateGraceSource" data-testid="attendance-rule-grace-source">
              <option value="SHIFT">班次</option>
              <option value="COMPANY">公司</option>
              <option value="NONE">无</option>
            </select>
          </label>
          <label>
            <span>不完整阈值（分钟）</span>
            <input v-model="form.incompleteThresholdMinutes" data-testid="attendance-rule-threshold" inputmode="numeric" />
          </label>
          <label class="attendance-rules__check">
            <input v-model="form.countBreak" type="checkbox" />
            计入休息扣除
          </label>
          <label class="attendance-rules__check">
            <input v-model="form.overtimeAfterScheduled" type="checkbox" />
            排班后计加班
          </label>
        </div>
        <button type="submit" class="attendance-rules__primary" data-testid="attendance-rule-publish" :disabled="saving">
          {{ saving ? '发布中…' : '发布版本' }}
        </button>
      </form>
    </ActionGate>

    <EmptyState
      v-if="!loading && rules.length === 0"
      title="尚未发布考勤规则版本"
      description="服务端没有返回规则版本。没有规则时，考勤计算使用代码默认值；页面不会伪造规则行。"
    />

    <div v-else class="attendance-rules__scroller" role="region" aria-label="规则版本，可横向滚动" tabindex="0">
      <table class="attendance-rules__table">
        <caption class="visually-hidden">考勤规则版本</caption>
        <thead>
          <tr>
            <th scope="col">版本</th>
            <th scope="col">生效日</th>
            <th scope="col">生效状态</th>
            <th scope="col">配置</th>
            <th scope="col">校验摘要</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="rule in sortedRules" :key="rule.id" :data-testid="`attendance-rule-${rule.id}`">
            <td class="mono">{{ rule.version }}</td>
            <td>{{ rule.effectiveFrom }}</td>
            <td>{{ rule.isEffective ? '当前生效' : '历史版本' }}</td>
            <td>{{ configLabel(rule) }}</td>
            <td class="mono attendance-rules__hash">{{ rule.checksum || '——' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.attendance-rules {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-rules__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-rules__header h2 {
  font-size: 15px;
}

.attendance-rules__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-rules__count {
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #f5f8f7;
  border-radius: 999px;
}

.attendance-rules__form {
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-rules__form h3 {
  margin-bottom: 9px;
  font-size: 13px;
}

.attendance-rules__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 10px;
}

.attendance-rules__grid label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-rules__grid input,
.attendance-rules__grid select {
  padding: 6px 8px;
  color: var(--color-ink);
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-rules__check {
  flex-direction: row !important;
  align-items: center;
  min-height: 30px;
  color: var(--color-ink) !important;
}

.attendance-rules__primary {
  margin-top: 11px;
  padding: 6px 12px;
  color: #fff;
  font-size: 11.5px;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
  border-radius: 6px;
  cursor: pointer;
}

.attendance-rules__scroller {
  overflow-x: auto;
}

.attendance-rules__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.attendance-rules__table th,
.attendance-rules__table td {
  padding: 9px 11px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.attendance-rules__table th {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #fafcfb;
}

.attendance-rules__hash {
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.attendance-rules__error {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-rules__success {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 11.5px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}
</style>
