<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import PerformanceGoalTable from './PerformanceGoalTable.vue'
import {
  ratingLabel,
  reviewStatusLabel,
  validateGoalWeights,
  validateScoreInput,
  type PerformanceGoalDraft,
  type PerformanceReviewRow,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    cycleName: string
    review: PerformanceReviewRow | null
    readOnly?: boolean
    canManage?: boolean
    canSubmit?: boolean
    saving?: boolean
  }>(),
  { readOnly: false, canManage: false, canSubmit: false, saving: false },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: {
    selfRating: string
    selfAssessment: string
    managerRating: string
    managerAssessment: string
    goals: PerformanceGoalDraft[]
  }]
  submit: [payload: { managerRating: string; managerAssessment: string }]
}>()

const selfRating = ref('')
const selfAssessment = ref('')
const managerRating = ref('')
const managerAssessment = ref('')
const goals = ref<PerformanceGoalDraft[]>([])

const confirmSaveOpen = ref(false)
const confirmSubmitOpen = ref(false)

function resetDraft(review: PerformanceReviewRow | null): void {
  selfRating.value = review?.selfRating ?? ''
  selfAssessment.value = review?.selfAssessment ?? ''
  managerRating.value = review?.managerRating ?? ''
  managerAssessment.value = review?.managerAssessment ?? ''
  goals.value = (review?.goals ?? []).map((goal) => ({
    title: goal.title,
    targetValue: goal.targetValue,
    weight: goal.weight,
    selfScore: goal.selfScore,
    managerScore: goal.managerScore,
  }))
}

watch(
  () => props.review,
  (review) => resetDraft(review),
  { immediate: true },
)

watch(
  () => props.modelValue,
  (open) => {
    if (open) resetDraft(props.review)
  },
)

const weight = computed(() => validateGoalWeights(goals.value))
const scoreErrors = computed(() => {
  const errors: string[] = []
  const selfError = validateScoreInput(selfRating.value)
  const managerError = validateScoreInput(managerRating.value)
  if (selfError !== null) errors.push(`自评分数：${selfError}`)
  if (managerError !== null) errors.push(`主管分数：${managerError}`)
  for (const [index, goal] of goals.value.entries()) {
    const selfGoalError = validateScoreInput(goal.selfScore)
    const managerGoalError = validateScoreInput(goal.managerScore)
    if (selfGoalError !== null) errors.push(`第 ${index + 1} 项目标自评分：${selfGoalError}`)
    if (managerGoalError !== null) errors.push(`第 ${index + 1} 项目标主管分：${managerGoalError}`)
  }
  for (const [index, goal] of goals.value.entries()) {
    if (goal.title.trim() === '') errors.push(`第 ${index + 1} 项目标缺少标题。`)
    if (goal.targetValue.trim() === '') errors.push(`第 ${index + 1} 项目标缺少目标值。`)
  }
  return errors
})

const canSave = computed(
  () =>
    !props.readOnly &&
    props.canManage &&
    weight.value.valid &&
    scoreErrors.value.length === 0 &&
    !props.saving,
)

const canSubmitCapability = computed(() => props.canSubmit)
const canSubmit = computed(
  () =>
    !props.readOnly &&
    props.canSubmit &&
    weight.value.valid &&
    scoreErrors.value.length === 0 &&
    !props.saving,
)

const finalRatingText = computed(() => {
  // Strictly a server projection. Editing manager input cannot change this.
  const value = props.review?.finalRating ?? ''
  return value === '' ? '待服务端计算' : ratingLabel(value)
})

const statusText = computed(() => (props.review === null ? '' : reviewStatusLabel(props.review.status)))

function close(): void {
  emit('update:modelValue', false)
}

function requestSave(): void {
  if (!canSave.value) return
  confirmSaveOpen.value = true
}

function confirmSave(): void {
  confirmSaveOpen.value = false
  emit('save', {
    selfRating: selfRating.value.trim(),
    selfAssessment: selfAssessment.value,
    managerRating: managerRating.value.trim(),
    managerAssessment: managerAssessment.value,
    goals: goals.value,
  })
}

function requestSubmit(): void {
  if (!canSubmit.value) return
  confirmSubmitOpen.value = true
}

function confirmSubmit(): void {
  confirmSubmitOpen.value = false
  emit('submit', {
    managerRating: managerRating.value.trim(),
    managerAssessment: managerAssessment.value,
  })
}
</script>

<template>
  <el-drawer
    :model-value="modelValue"
    :title="review === null ? '绩效评分' : `绩效评分 · 员工 #${review.employmentId}`"
    size="min(880px, 96vw)"
    data-testid="performance-score-drawer"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <template v-if="review !== null">
      <header class="score-drawer__head">
        <div>
          <p class="score-drawer__eyebrow">绩效评估</p>
          <h2 class="score-drawer__title">{{ cycleName }}</h2>
          <p class="score-drawer__meta">
            员工 #{{ review.employmentId }}
            <template v-if="review.managerEmploymentId !== null"> · 主管 #{{ review.managerEmploymentId }}</template>
          </p>
        </div>
        <div class="score-drawer__statuses">
          <span class="score-drawer__status" data-testid="performance-review-status">{{ statusText }}</span>
          <span v-if="readOnly" class="score-drawer__readonly-badge">只读</span>
        </div>
      </header>

      <p v-if="readOnly" class="score-drawer__readonly" role="status">
        该评估已提交或周期已发布，评分与目标为只读。最终等级只显示服务端结果，页面不会自行计算。
      </p>

      <section class="score-drawer__final" aria-label="服务端最终等级">
        <div>
          <dt>最终等级</dt>
          <dd data-testid="performance-final-rating">{{ finalRatingText }}</dd>
        </div>
        <div>
          <dt>自评分数</dt>
          <dd>{{ review.selfRating === '' ? '—' : `${review.selfRating} 分` }}</dd>
        </div>
        <div>
          <dt>主管分数</dt>
          <dd>{{ review.managerRating === '' ? '—' : `${review.managerRating} 分` }}</dd>
        </div>
        <div>
          <dt>校准等级</dt>
          <dd>{{ review.calibratedRating === '' ? '—' : ratingLabel(review.calibratedRating) }}</dd>
        </div>
      </section>

      <section class="score-drawer__section">
        <h3>评分</h3>
        <div class="score-drawer__score-grid">
          <label>
            <span>自评分数</span>
            <input
              v-model="selfRating"
              type="text"
              inputmode="decimal"
              placeholder="0-100"
              :disabled="readOnly || !canManage"
              :aria-invalid="validateScoreInput(selfRating) !== null"
              data-testid="performance-self-rating"
            />
            <small v-if="validateScoreInput(selfRating)" class="score-drawer__error">
              {{ validateScoreInput(selfRating) }}
            </small>
          </label>
          <label>
            <span>主管分数</span>
            <input
              v-model="managerRating"
              type="text"
              inputmode="decimal"
              placeholder="0-100"
              :disabled="readOnly || !canManage"
              :aria-invalid="validateScoreInput(managerRating) !== null"
              data-testid="performance-manager-rating"
            />
            <small v-if="validateScoreInput(managerRating)" class="score-drawer__error">
              {{ validateScoreInput(managerRating) }}
            </small>
          </label>
        </div>

        <label class="score-drawer__assessment">
          <span>自评说明</span>
          <textarea
            v-model="selfAssessment"
            rows="2"
            maxlength="10000"
            :disabled="readOnly || !canManage"
            data-testid="performance-self-assessment"
          />
        </label>
        <label class="score-drawer__assessment">
          <span>主管评语</span>
          <textarea
            v-model="managerAssessment"
            rows="2"
            maxlength="10000"
            :disabled="readOnly || !canManage"
            data-testid="performance-manager-assessment"
          />
        </label>
      </section>

      <section class="score-drawer__section">
        <PerformanceGoalTable
          :goals="goals"
          :read-only="readOnly || !canManage"
          mode="edit"
          @update:goals="goals = $event"
        />
      </section>

      <ul v-if="scoreErrors.length > 0" class="score-drawer__errors" role="alert">
        <li v-for="error in scoreErrors" :key="error">{{ error }}</li>
      </ul>

      <p class="score-drawer__note">
        分数以字符串原样提交，不使用浮点数；权重合计与最终等级由服务端再次校验。决定后的评估不可再评分。
      </p>

      <footer class="score-drawer__footer">
        <el-button @click="close">关闭</el-button>
        <el-button
          v-if="canManage"
          :disabled="!canSave"
          :loading="saving"
          data-testid="performance-score-save"
          @click="requestSave"
        >
          保存
        </el-button>
        <el-button
          v-if="canSubmitCapability"
          type="primary"
          :disabled="!canSubmit"
          :loading="saving"
          data-testid="performance-score-submit"
          @click="requestSubmit"
        >
          提交主管复核
        </el-button>
      </footer>

      <el-dialog
        v-model="confirmSaveOpen"
        title="保存评分与目标"
        width="460px"
        :close-on-click-modal="false"
        data-testid="performance-save-confirm"
      >
        <p>将保存 {{ cycleName }} 中员工 #{{ review.employmentId }} 的自评、主管评分与 {{ goals.length }} 项目标。</p>
        <p>权重合计：<strong>{{ weight.total }} / 100</strong>。分数将按字符串原样提交。</p>
        <template #footer>
          <el-button data-testid="performance-save-confirm-cancel" @click="confirmSaveOpen = false">取消</el-button>
          <el-button type="primary" :loading="saving" data-testid="performance-save-confirm-submit" @click="confirmSave">
            确认保存
          </el-button>
        </template>
      </el-dialog>

      <el-dialog
        v-model="confirmSubmitOpen"
        title="提交主管复核"
        width="460px"
        :close-on-click-modal="false"
        data-testid="performance-submit-confirm"
      >
        <p>提交后该评估进入主管复核，不能再修改自评与目标。确认提交员工 #{{ review.employmentId }}？</p>
        <p>最终等级由服务端计算，页面不会预先写入等级。</p>
        <template #footer>
          <el-button data-testid="performance-submit-confirm-cancel" @click="confirmSubmitOpen = false">取消</el-button>
          <el-button type="primary" :loading="saving" data-testid="performance-submit-confirm-submit" @click="confirmSubmit">
            确认提交
          </el-button>
        </template>
      </el-dialog>
    </template>

    <div v-else class="score-drawer__empty">请选择一名员工查看绩效评分。</div>
  </el-drawer>
</template>

<style scoped>
.score-drawer__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.score-drawer__eyebrow {
  margin: 0;
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.08em;
}

.score-drawer__title {
  margin: 4px 0 0;
  font-size: 17px;
}

.score-drawer__meta {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 12px;
}

.score-drawer__statuses {
  display: flex;
  gap: 8px;
}

.score-drawer__status,
.score-drawer__readonly-badge {
  padding: 2px 10px;
  border-radius: 999px;
  background: #eef2f7;
  color: #475569;
  font-size: 11.5px;
  font-weight: 600;
}

.score-drawer__readonly-badge {
  background: #fef3c7;
  color: #92400e;
}

.score-drawer__readonly {
  margin: 12px 0 0;
  padding: 10px 12px;
  border: 1px solid #fde68a;
  border-radius: 8px;
  background: #fffbeb;
  color: #92400e;
  font-size: 12px;
  line-height: 1.6;
}

.score-drawer__final {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  margin: 14px 0 18px;
  padding: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #f8fafc;
}

.score-drawer__final dt {
  color: #64748b;
  font-size: 11px;
}

.score-drawer__final dd {
  margin: 3px 0 0;
  font-size: 14px;
  font-weight: 600;
}

.score-drawer__section {
  margin-top: 16px;
}

.score-drawer__section h3 {
  margin: 0 0 8px;
  font-size: 13px;
}

.score-drawer__score-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.score-drawer__score-grid label,
.score-drawer__assessment {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: #475569;
  font-size: 12px;
}

.score-drawer__score-grid input,
.score-drawer__assessment textarea {
  padding: 7px 9px;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  font: inherit;
}

.score-drawer__score-grid input:disabled,
.score-drawer__assessment textarea:disabled {
  background: #f8fafc;
  color: #475569;
}

.score-drawer__score-grid input[aria-invalid='true'] {
  border-color: #f87171;
  background: #fff7f7;
}

.score-drawer__assessment {
  margin-top: 10px;
}

.score-drawer__error {
  color: #b91c1c;
  font-size: 10.5px;
}

.score-drawer__errors {
  margin: 10px 0 0;
  padding: 8px 12px 8px 28px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 11.5px;
}

.score-drawer__note {
  margin: 14px 0 0;
  color: #64748b;
  font-size: 11px;
  line-height: 1.6;
}

.score-drawer__footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid #e2e8f0;
}

.score-drawer__empty {
  padding: 20px;
  color: #475569;
  font-size: 12.5px;
}
</style>
