<script setup lang="ts">
import { computed } from 'vue'
import { reportDomainLabel, type ReportDefinition } from '../api'

const props = defineProps<{
  definitions: ReportDefinition[]
  selected: string
}>()

const emit = defineEmits<{
  select: [code: string]
}>()

type DomainGroup = {
  domain: string
  label: string
  reports: ReportDefinition[]
}

/**
 * The catalog only renders server-returned definitions. Grouping is a stable
 * business-domain presentation over `definition.domain`; it never invents a
 * report, renames a server definition or exposes `source_projection`.
 */
const groups = computed<DomainGroup[]>(() => {
  const byDomain = new Map<string, DomainGroup>()

  for (const definition of props.definitions) {
    const domain = (definition.domain ?? 'PEOPLE').toUpperCase()
    const existing = byDomain.get(domain)

    if (existing !== undefined) {
      existing.reports.push(definition)
      continue
    }

    byDomain.set(domain, {
      domain,
      label: reportDomainLabel(domain),
      reports: [definition],
    })
  }

  return [...byDomain.values()]
})

function select(code: string): void {
  if (code !== '') emit('select', code)
}
</script>

<template>
  <aside class="panel report-catalog" aria-label="报表目录" data-testid="report-catalog">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">报表目录</h2>
        <p class="panel__subtitle">按业务域分组；字段与筛选由服务端定义。</p>
      </div>
      <span class="plain-chip">{{ definitions.length }} 张报表</span>
    </div>

    <nav class="report-catalog__list">
      <section v-for="group in groups" :key="group.domain" class="report-catalog__group" :data-testid="`report-domain-${group.domain}`">
        <h3 class="report-catalog__domain">{{ group.label }}</h3>
        <button
          v-for="definition in group.reports"
          :key="definition.code"
          type="button"
          class="report-catalog__item"
          :class="{ 'report-catalog__item--active': definition.code === selected }"
          :aria-current="definition.code === selected ? 'page' : undefined"
          :data-testid="`report-definition-${definition.code}`"
          @click="select(definition.code)"
        >
          <span class="report-catalog__name">{{ definition.name }}</span>
          <span class="report-catalog__description">{{ definition.description }}</span>
        </button>
      </section>
    </nav>
  </aside>
</template>

<style scoped>
.report-catalog__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 8px 0;
}

.report-catalog__domain {
  margin: 0;
  padding: 8px 16px 4px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  letter-spacing: 0.04em;
  text-transform: none;
  background: transparent;
}

.report-catalog__item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  width: 100%;
  padding: 9px 16px;
  font: inherit;
  text-align: left;
  color: inherit;
  background: transparent;
  border: 0;
  border-bottom: 1px solid #edf1ef;
  cursor: pointer;
}

.report-catalog__item:hover,
.report-catalog__item--active {
  background: #f4f8f7;
}

.report-catalog__item--active {
  box-shadow: inset 3px 0 0 var(--color-brand);
}

.report-catalog__name {
  font-size: 12.5px;
  font-weight: 600;
}

.report-catalog__description {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  line-height: 1.45;
}
</style>
