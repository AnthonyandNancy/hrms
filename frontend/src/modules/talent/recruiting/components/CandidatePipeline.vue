<script setup lang="ts">
import { computed, reactive } from 'vue'
import {
  MOVABLE_STAGES,
  PIPELINE_STAGES,
  type Candidate,
  type PipelineStage,
} from '../api'

const props = withDefaults(
  defineProps<{
    candidates?: readonly Candidate[]
    canMove?: boolean
    movingId?: number | null
  }>(),
  {
    candidates: () => [],
    canMove: false,
    movingId: null,
  },
)

const emit = defineEmits<{
  move: [candidate: Candidate, status: string]
}>()

defineOptions({ name: 'CandidatePipeline' })

const targets = reactive<Record<number, string>>({})

function nextStage(candidate: Candidate): string {
  const current = PIPELINE_STAGES.findIndex((stage) => stage.key === candidate.stage)
  if (candidate.stage === 'REJECTED') return 'SCREENING'
  const next = PIPELINE_STAGES[current + 1]
  return next === undefined || next.key === 'HIRED' ? 'REJECTED' : next.key
}

function targetFor(candidate: Candidate): string {
  if (targets[candidate.id] === undefined) {
    targets[candidate.id] = nextStage(candidate)
  }
  return targets[candidate.id] ?? ''
}

function setTarget(candidate: Candidate, value: string): void {
  targets[candidate.id] = value
}

function column(stage: PipelineStage): Candidate[] {
  return props.candidates.filter((candidate) => candidate.stage === stage)
}

const grouped = computed(() =>
  PIPELINE_STAGES.map((stage) => ({
    ...stage,
    candidates: column(stage.key),
  })),
)
</script>

<template>
  <section class="candidate-pipeline" aria-label="候选人招聘管道">
    <div class="candidate-pipeline__board">
      <section
        v-for="stage in grouped"
        :key="stage.key"
        class="pipeline-column"
        :class="`pipeline-column--${stage.key.toLowerCase()}`"
        :data-testid="`pipeline-column-${stage.key}`"
      >
        <header class="pipeline-column__header">
          <h2>{{ stage.label }}</h2>
          <span class="pipeline-column__count">{{ stage.candidates.length }}</span>
        </header>

        <p v-if="stage.candidates.length === 0" class="pipeline-column__empty">暂无候选人</p>

        <article
          v-for="candidate in stage.candidates"
          :key="candidate.id"
          class="pipeline-card"
          :data-testid="`pipeline-card-${candidate.id}`"
        >
          <div class="pipeline-card__title">
            <router-link :to="`/recruitment/candidates/${candidate.id}`">
              {{ candidate.displayName }}
            </router-link>
            <span class="pipeline-card__status">{{ candidate.statusLabel }}</span>
          </div>
          <p class="pipeline-card__meta">
            来源：{{ candidate.source ?? '未登记' }}
            <template v-if="candidate.nextInterviewAt !== null"> · 下次面试 {{ candidate.nextInterviewAt.slice(0, 10) }}</template>
          </p>
          <p v-if="candidate.convertedEmployeeNo !== null" class="pipeline-card__hired" data-testid="pipeline-hired-no">
            员工编号 {{ candidate.convertedEmployeeNo }}
          </p>
          <p v-else-if="candidate.offerStatus !== null" class="pipeline-card__meta">
            Offer {{ candidate.offerStatus }}
          </p>

          <div v-if="canMove && candidate.stage !== 'HIRED'" class="pipeline-card__move">
            <label>
              <span class="visually-hidden">移动到阶段</span>
              <select
                :value="targetFor(candidate)"
                :data-testid="`candidate-target-${candidate.id}`"
                @change="setTarget(candidate, ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="option in MOVABLE_STAGES" :key="option.key" :value="option.key">
                  {{ option.label }}
                </option>
              </select>
            </label>
            <button
              type="button"
              class="pipeline-card__button"
              :disabled="movingId === candidate.id"
              :data-testid="`candidate-move-${candidate.id}`"
              @click="emit('move', candidate, targetFor(candidate))"
            >
              移动
            </button>
          </div>
        </article>
      </section>
    </div>
  </section>
</template>

<style scoped>
.candidate-pipeline__board {
  display: grid;
  grid-template-columns: repeat(6, minmax(190px, 1fr));
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 8px;
}

.pipeline-column {
  display: flex;
  min-height: 360px;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #f8fafc;
}

.pipeline-column__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.pipeline-column__header h2 {
  margin: 0;
  color: #334155;
  font-size: 13px;
}

.pipeline-column__count {
  display: inline-flex;
  min-width: 22px;
  justify-content: center;
  padding: 1px 6px;
  border-radius: 999px;
  background: #e2e8f0;
  color: #475569;
  font-size: 11px;
}

.pipeline-column__empty {
  margin: 0;
  color: #94a3b8;
  font-size: 12px;
}

.pipeline-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.pipeline-card__title {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
}

.pipeline-card__title a {
  color: #1d4ed8;
  text-decoration: none;
}

.pipeline-card__status {
  flex: 0 0 auto;
  padding: 1px 6px;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 10.5px;
  font-weight: 500;
}

.pipeline-card__meta {
  margin: 0;
  color: #64748b;
  font-size: 11.5px;
  line-height: 1.5;
}

.pipeline-card__hired {
  margin: 0;
  color: #047857;
  font-size: 11.5px;
  font-weight: 600;
}

.pipeline-card__move {
  display: flex;
  gap: 6px;
  padding-top: 4px;
}

.pipeline-card__move label {
  min-width: 0;
  flex: 1;
}

.pipeline-card__move select {
  width: 100%;
  height: 26px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  color: #334155;
  font-size: 11px;
}

.pipeline-card__button {
  height: 26px;
  padding: 0 9px;
  border: 1px solid #1d4ed8;
  border-radius: 6px;
  background: #1d4ed8;
  color: #fff;
  font-size: 11px;
  cursor: pointer;
}

.pipeline-card__button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}
</style>
