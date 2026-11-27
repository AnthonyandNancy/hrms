<script setup lang="ts">
import { computed } from 'vue'
import { filterLabel, reportDomainLabel, type OrgUnitOption, type ReportDefinition } from '../api'

const props = withDefaults(
  defineProps<{
    definition: ReportDefinition
    asOf: string
    filters: Record<string, string>
    orgUnits?: OrgUnitOption[]
    running?: boolean
  }>(),
  { orgUnits: () => [], running: false },
)

const emit = defineEmits<{
  'update:asOf': [value: string]
  'update:filters': [value: Record<string, string>]
  run: []
}>()

const filterRows = computed(() => props.definition.filters)

function onAsOf(event: Event): void {
  const value = (event.target as HTMLInputElement).value
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) emit('update:asOf', value)
}

function onFilter(key: string, event: Event): void {
  const target = event.target as HTMLInputElement | HTMLSelectElement
  emit('update:filters', { ...props.filters, [key]: target.value })
}

function optionsFor(type: string, options: string[]): Array<{ value: string; label: string }> {
  if (type === 'ORG') {
    return props.orgUnits.map((unit) => ({ value: String(unit.id), label: unit.label }))
  }
  return options.map((option) => ({ value: option, label: option }))
}
</script>

<template>
  <section class="panel report-runner" data-testid="report-runner">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">{{ definition.name }}</h2>
        <p class="panel__subtitle">{{ definition.description }}</p>
      </div>
      <div class="report-runner__chips">
        <span class="plain-chip">{{ reportDomainLabel(definition.domain ?? '') }}</span>
        <span class="plain-chip">{{ definition.fields.length }} 个字段</span>
      </div>
    </div>

    <div class="panel__body report-parameters">
      <label class="report-parameter">
        <span>业务日期</span>
        <input
          :value="asOf"
          type="date"
          data-testid="report-as-of"
          aria-label="业务日期"
          @input="onAsOf"
        />
      </label>

      <label v-for="filter in filterRows" :key="filter.key" class="report-parameter">
        <span>{{ filterLabel(filter.key, filter.label) }}</span>
        <select
          v-if="filter.type === 'ORG' || filter.type === 'SELECT'"
          :value="filters[filter.key] ?? ''"
          :data-testid="`report-filter-${filter.key}`"
          :aria-label="filterLabel(filter.key, filter.label)"
          @change="onFilter(filter.key, $event)"
        >
          <option value="">全部</option>
          <option v-for="option in optionsFor(filter.type, filter.options)" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <input
          v-else
          :value="filters[filter.key] ?? ''"
          :data-testid="`report-filter-${filter.key}`"
          :aria-label="filterLabel(filter.key, filter.label)"
          @input="onFilter(filter.key, $event)"
        />
      </label>

      <el-button
        type="primary"
        :loading="running"
        :disabled="running"
        data-testid="report-run"
        @click="emit('run')"
      >
        {{ running ? '正在查询' : '运行报表' }}
      </el-button>
    </div>
  </section>
</template>

<style scoped>
.report-runner__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.report-parameters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
}

.report-parameter {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-secondary);
  font-size: 11px;
}

.report-parameter input,
.report-parameter select {
  min-width: 140px;
  padding: 7px 9px;
  font: inherit;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}
</style>
