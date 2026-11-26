<script setup lang="ts">
import type { PeopleField, PeopleRow } from '../types'
import { rowText } from '../peopleRead'

withDefaults(
  defineProps<{
    fields: readonly PeopleField[]
    row: PeopleRow | null
    title?: string
  }>(),
  { title: '档案要点' },
)

function valueOf(row: PeopleRow | null, key: string): string {
  return row === null ? '—' : rowText(row, key)
}
</script>

<template>
  <section v-if="row" class="panel people-detail-panel">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">{{ title }}</h2>
        <p class="panel__subtitle">字段按服务端返回的当前有效记录展示</p>
      </div>
    </header>
    <div class="panel__body">
      <dl class="people-facts">
        <div v-for="field in fields" :key="field.key" class="people-facts__item">
          <dt>{{ field.label }}</dt>
          <dd>{{ valueOf(row, field.key) }}</dd>
        </div>
      </dl>
    </div>
  </section>
</template>
