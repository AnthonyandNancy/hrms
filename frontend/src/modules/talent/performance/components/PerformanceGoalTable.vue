<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  goalWeightTotal,
  validateGoalWeights,
  validateScoreInput,
  type PerformanceGoalDraft,
} from '../api'

const props = withDefaults(
  defineProps<{
    goals: PerformanceGoalDraft[]
    readOnly?: boolean
    /** 'self' hides manager inputs; 'manager' hides self inputs. */
    mode?: 'edit' | 'self' | 'manager'
  }>(),
  { readOnly: false, mode: 'edit' },
)

const emit = defineEmits<{
  'update:goals': [goals: PerformanceGoalDraft[]]
}>()

const localGoals = ref<PerformanceGoalDraft[]>(props.goals.map((goal) => ({ ...goal })))

watch(
  () => props.goals,
  (goals) => {
    localGoals.value = goals.map((goal) => ({ ...goal }))
  },
  { deep: true },
)

const total = computed(() => goalWeightTotal(localGoals.value))
const validation = computed(() => validateGoalWeights(localGoals.value))

function emitGoals(next: PerformanceGoalDraft[]): void {
  localGoals.value = next
  emit('update:goals', next.map((goal) => ({ ...goal })))
}

function update(index: number, patch: Partial<PerformanceGoalDraft>): void {
  emitGoals(localGoals.value.map((goal, position) =>
    position === index ? { ...goal, ...patch } : goal,
  ))
}

function updateWeight(index: number, raw: string): void {
  const parsed = /^-?\d+$/.test(raw.trim()) ? Number(raw.trim()) : Number.NaN
  update(index, { weight: Number.isSafeInteger(parsed) ? parsed : 0 })
}

function addGoal(): void {
  emitGoals([
    ...localGoals.value,
    { title: '', targetValue: '', weight: 0, selfScore: '', managerScore: '' },
  ])
}

function removeGoal(index: number): void {
  emitGoals(localGoals.value.filter((_, position) => position !== index))
}

function scoreError(value: string): string | null {
  return validateScoreInput(value)
}
</script>

<template>
  <section class="performance-goals" data-testid="performance-goal-table">
    <header class="performance-goals__header">
      <div>
        <h3 class="performance-goals__title">绩效目标</h3>
        <p class="performance-goals__hint">
          权重为整数百分比，逐项必须在 1 到 100 之间，合计必须等于 100。分数按服务端原文
          保留，不进行浮点换算。
        </p>
      </div>
      <p
        class="performance-goals__total"
        :data-valid="validation.valid"
        data-testid="goal-weight-total"
        aria-live="polite"
      >
        权重合计：<strong>{{ total }} / 100</strong>
        <span v-if="validation.valid">（100% 权重已分配）</span>
      </p>
    </header>

    <p v-if="!validation.valid" class="performance-goals__error" role="alert" data-testid="goal-weight-error">
      {{ validation.errors.join(' ') }}
    </p>

    <div class="performance-goals__wrap">
      <table class="performance-goals__table">
        <caption class="visually-hidden">绩效目标与评分</caption>
        <thead>
          <tr>
            <th scope="col">目标</th>
            <th scope="col">权重（%）</th>
            <th scope="col">目标值</th>
            <th scope="col">自评分数</th>
            <th scope="col">主管分数</th>
            <th v-if="!readOnly" scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(goal, index) in localGoals" :key="index" :data-testid="`goal-row-${index}`">
            <td>
              <span v-if="readOnly" :data-testid="`goal-title-${index}`">{{ goal.title }}</span>
              <input
                v-else
                :value="goal.title"
                type="text"
                maxlength="200"
                placeholder="目标标题"
                :data-testid="`goal-title-${index}`"
                @input="update(index, { title: ($event.target as HTMLInputElement).value })"
              />
            </td>
            <td class="is-numeric">
              <span v-if="readOnly" :data-testid="`goal-weight-${index}`">{{ goal.weight }}%</span>
              <input
                v-else
                :value="goal.weight"
                type="number"
                min="1"
                max="100"
                step="1"
                inputmode="numeric"
                :class="{ 'is-invalid': goal.weight < 1 || goal.weight > 100 }"
                :data-testid="`goal-weight-${index}`"
                @input="updateWeight(index, ($event.target as HTMLInputElement).value)"
              />
            </td>
            <td>
              <span v-if="readOnly" :data-testid="`goal-target-${index}`">{{ goal.targetValue }}</span>
              <input
                v-else
                :value="goal.targetValue"
                type="text"
                maxlength="120"
                placeholder="目标值"
                :data-testid="`goal-target-${index}`"
                @input="update(index, { targetValue: ($event.target as HTMLInputElement).value })"
              />
            </td>
            <td>
              <span v-if="readOnly" :data-testid="`goal-self-score-${index}`">{{ goal.selfScore === '' ? '—' : goal.selfScore }}</span>
              <template v-else>
                <input
                  :value="goal.selfScore"
                  type="text"
                  inputmode="decimal"
                  placeholder="0-100"
                  :disabled="mode === 'manager'"
                  :aria-invalid="scoreError(goal.selfScore) !== null"
                  :data-testid="`goal-self-score-${index}`"
                  @input="update(index, { selfScore: ($event.target as HTMLInputElement).value })"
                />
                <small v-if="scoreError(goal.selfScore)" class="performance-goals__field-error">
                  {{ scoreError(goal.selfScore) }}
                </small>
              </template>
            </td>
            <td>
              <span v-if="readOnly" :data-testid="`goal-manager-score-${index}`">{{ goal.managerScore === '' ? '—' : goal.managerScore }}</span>
              <template v-else>
                <input
                  :value="goal.managerScore"
                  type="text"
                  inputmode="decimal"
                  placeholder="0-100"
                  :disabled="mode === 'self'"
                  :aria-invalid="scoreError(goal.managerScore) !== null"
                  :data-testid="`goal-manager-score-${index}`"
                  @input="update(index, { managerScore: ($event.target as HTMLInputElement).value })"
                />
                <small v-if="scoreError(goal.managerScore)" class="performance-goals__field-error">
                  {{ scoreError(goal.managerScore) }}
                </small>
              </template>
            </td>
            <td v-if="!readOnly">
              <button type="button" :data-testid="`goal-remove-${index}`" @click="removeGoal(index)">
                移除
              </button>
            </td>
          </tr>
          <tr v-if="localGoals.length === 0">
            <td :colspan="readOnly ? 5 : 6" class="performance-goals__empty">
              该评估还没有目标。{{ readOnly ? '' : '请添加目标后再保存。' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <button
      v-if="!readOnly"
      type="button"
      class="performance-goals__add"
      data-testid="goal-add"
      @click="addGoal"
    >
      添加目标
    </button>
  </section>
</template>

<style scoped>
.performance-goals {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.performance-goals__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.performance-goals__title {
  margin: 0;
  font-size: 14px;
}

.performance-goals__hint {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11.5px;
  line-height: 1.5;
}

.performance-goals__total {
  margin: 0;
  padding: 4px 10px;
  border-radius: 999px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 12px;
  white-space: nowrap;
}

.performance-goals__total[data-valid='true'] {
  background: #ecfdf5;
  color: #047857;
}

.performance-goals__error {
  margin: 0;
  padding: 8px 12px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 12px;
}

.performance-goals__wrap {
  overflow-x: auto;
}

.performance-goals__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.performance-goals__table th,
.performance-goals__table td {
  padding: 7px 8px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: top;
}

.performance-goals__table thead th {
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}

.performance-goals__table input {
  width: 100%;
  min-width: 72px;
  padding: 6px 8px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font: inherit;
}

.performance-goals__table input:disabled {
  background: #f8fafc;
  color: #475569;
}

.performance-goals__table input.is-invalid,
.performance-goals__table input[aria-invalid='true'] {
  border-color: #f87171;
  background: #fff7f7;
}

.performance-goals__table .is-numeric input {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.performance-goals__field-error {
  display: block;
  margin-top: 3px;
  color: #b91c1c;
  font-size: 10.5px;
}

.performance-goals__empty {
  padding: 16px 10px;
  color: #64748b;
  text-align: center;
}

.performance-goals__add {
  align-self: flex-start;
  padding: 6px 12px;
  border: 1px dashed #94a3b8;
  border-radius: 8px;
  background: #fff;
  color: #334155;
  font-size: 12px;
  cursor: pointer;
}
</style>
