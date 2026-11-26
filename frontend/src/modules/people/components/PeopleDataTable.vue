<script setup lang="ts">
import { computed } from 'vue'
import type { PeopleColumn, PeopleRow } from '../types'
import { rowNumber, rowText } from '../peopleRead'

const props = withDefaults(
  defineProps<{
    columns: readonly PeopleColumn[]
    rows: PeopleRow[]
    total: number
    label: string
    selectable?: boolean
    selectedId?: string
    showSearch?: boolean
    searchValue?: string
    searchLabel?: string
  }>(),
  {
    selectable: false,
    selectedId: '',
    showSearch: false,
    searchValue: '',
    searchLabel: '搜索',
  },
)

const emit = defineEmits<{
  'update:searchValue': [value: string]
  'update:selectedId': [value: string]
  select: [row: PeopleRow]
}>()

const searchModel = computed({
  get: () => props.searchValue,
  set: (value: string) => emit('update:searchValue', value),
})

function display(row: PeopleRow, column: string): string {
  if (column === 'phone') return maskedPhone(row)
  if (column === 'overstaffed') return row.overstaffed === true ? '是' : '否'
  if (column === 'is_primary') return row.is_primary === true ? '是' : '否'
  if (column === 'is_emergency') return row.is_emergency === true ? '是' : '否'
  if (column === 'diff') {
    const diff = row.diff
    if (diff === null || typeof diff !== 'object' || Array.isArray(diff)) return '—'
    return `${Object.keys(diff).length} 项`
  }
  if (column === 'days_to_expiry') {
    const days = rowNumber(row, column)
    return days === null ? '—' : `${days} 天`
  }
  return rowText(row, column)
}

function maskedPhone(row: PeopleRow): string {
  const value = row.phone
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return '已遮罩'
  const record = value as Record<string, unknown>
  if (record.masked === true) {
    return `已遮罩 · ${String(record.profile ?? 'P2')}`
  }
  return typeof record.display === 'string' && record.display !== '' ? record.display : '已遮罩 · P2'
}

function choose(row: PeopleRow): void {
  const id = row.id ?? row.case_id ?? row.employment_id ?? row.org_unit_id
  if (typeof id === 'number' || typeof id === 'string') {
    emit('update:selectedId', String(id))
    emit('select', row)
  }
}
</script>

<template>
  <section class="panel people-table-panel">
    <header class="panel__header people-table-panel__header">
      <div>
        <h2 class="panel__title">{{ label }}</h2>
        <p class="panel__subtitle">共 {{ total }} 条记录</p>
      </div>
      <div class="people-table-panel__tools">
        <el-input
          v-if="showSearch"
          v-model="searchModel"
          :placeholder="searchLabel"
          clearable
          class="people-table-panel__search"
          aria-label="筛选列表"
        />
        <slot name="tools" />
      </div>
    </header>

    <div class="panel__body panel__body--flush">
      <div class="people-table-wrap" role="region" aria-label="数据表格，可横向滚动" tabindex="0">
        <table class="people-table">
          <caption class="visually-hidden">{{ label }}数据表，共 {{ total }} 条</caption>
          <thead>
            <tr>
              <th v-if="selectable" scope="col" class="people-table__select" />
              <th
                v-for="column in columns"
                :key="column.key"
                scope="col"
                :style="{ width: column.width, minWidth: column.minWidth, textAlign: column.align ?? 'left' }"
              >
                {{ column.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0">
              <td :colspan="columns.length + (selectable ? 1 : 0)" class="people-table__empty">当前筛选下没有记录。</td>
            </tr>
            <tr
              v-for="(row, index) in rows"
              v-else
              :key="String(row.id ?? row.case_id ?? row.employment_id ?? index)"
              :class="{ 'people-table__row--selected': selectedId !== '' && selectedId === String(row.id ?? row.case_id ?? row.employment_id ?? '') }"
              @click="selectable ? choose(row) : undefined"
            >
              <td v-if="selectable" class="people-table__select">
                <input
                  type="radio"
                  :checked="selectedId === String(row.id ?? row.case_id ?? row.employment_id ?? '')"
                  :aria-label="`选择第 ${index + 1} 行`"
                  @change="choose(row)"
                />
              </td>
              <td
                v-for="column in columns"
                :key="column.key"
                :style="{ textAlign: column.align ?? 'left' }"
                :data-column="column.key"
              >
                <span v-if="column.key === 'phone'" class="people-masked-phone">{{ display(row, column.key) }}</span>
                <template v-else>{{ display(row, column.key) }}</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <footer class="panel__footer people-table-panel__footer">
      <span>数据来自实时接口，不展示演示行。</span>
      <slot name="footer" />
    </footer>
  </section>
</template>
