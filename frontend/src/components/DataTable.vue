<script setup lang="ts">
import { computed, ref } from 'vue'
import { Search } from '@element-plus/icons-vue'

export type DataTableColumn = {
  key: string
  label: string
  width?: string
  minWidth?: string
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
}

const props = withDefaults(
  defineProps<{
    columns: DataTableColumn[]
    rows: Array<Record<string, unknown>>
    total: number
    page: number
    pageSize: number
    loading?: boolean
    error?: string | null
    emptyTitle?: string
    searchPlaceholder?: string
    searchValue?: string
    showSearch?: boolean
    rowKey?: string
  }>(),
  {
    loading: false,
    error: null,
    emptyTitle: '没有匹配的记录',
    searchPlaceholder: '搜索姓名、编号或组织',
    searchValue: '',
    showSearch: false,
    rowKey: 'id',
  },
)

const emit = defineEmits<{
  'update:page': [page: number]
  'update:pageSize': [pageSize: number]
  'update:searchValue': [value: string]
  retry: []
}>()

const localSearch = ref(props.searchValue)
const searchModel = computed({
  get: () => localSearch.value,
  set: (value: string) => {
    localSearch.value = value
    emit('update:searchValue', value)
  },
})

function valueOf(row: Record<string, unknown>, key: string): unknown {
  return key.split('.').reduce<unknown>((value, segment) => {
    if (value && typeof value === 'object') return (value as Record<string, unknown>)[segment]
    return undefined
  }, row)
}

function display(row: Record<string, unknown>, key: string): string {
  const value = valueOf(row, key)
  if (value === null || value === undefined || value === '') return '—'
  return String(value)
}

const pageCount = computed(() => Math.max(1, Math.ceil(props.total / props.pageSize)))
</script>

<template>
  <div class="data-table">
    <div v-if="showSearch" class="data-table__toolbar">
      <el-input
        v-model="searchModel"
        :placeholder="searchPlaceholder"
        clearable
        :prefix-icon="Search"
        aria-label="搜索列表"
        class="data-table__search"
      />
      <slot name="toolbar" />
    </div>
    <div v-else class="data-table__toolbar data-table__toolbar--end">
      <slot name="toolbar" />
    </div>

    <el-alert v-if="error" type="error" :closable="false" show-icon class="data-table__error">
      <template #title>{{ error }}</template>
      <el-button text size="small" @click="emit('retry')">重试</el-button>
    </el-alert>

    <div class="data-table__scroller" role="region" aria-label="数据表格，可横向滚动" tabindex="0">
      <table class="data-table__table">
        <caption class="visually-hidden">
          共 {{ total }} 条记录，第 {{ page }} 页，共 {{ pageCount }} 页
        </caption>
        <thead>
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
              :style="{ width: column.width, minWidth: column.minWidth, textAlign: column.align ?? 'left' }"
              :aria-sort="column.sortable ? 'none' : undefined"
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>
        <tbody v-if="loading">
          <tr v-for="index in pageSize" :key="`skeleton-${index}`" class="data-table__skeleton">
            <td :colspan="columns.length"><span class="data-table__skeleton-line" /></td>
          </tr>
        </tbody>
        <tbody v-else-if="rows.length === 0">
          <tr>
            <td :colspan="columns.length">
              <div class="data-table__empty">
                <p>{{ emptyTitle }}</p>
                <slot name="empty-action" />
              </div>
            </td>
          </tr>
        </tbody>
        <tbody v-else>
          <tr v-for="(row, index) in rows" :key="String(row[rowKey] ?? index)" class="data-table__row">
            <td
              v-for="column in columns"
              :key="column.key"
              :style="{ textAlign: column.align ?? 'left' }"
              :data-label="column.label"
            >
              <slot :name="`cell-${column.key}`" :row="row" :value="valueOf(row, column.key)">
                {{ display(row, column.key) }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="data-table__pagination">
      <span class="data-table__count">共 {{ total }} 条</span>
      <el-pagination
        :current-page="page"
        :page-size="pageSize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="sizes, prev, pager, next"
        background
        small
        @current-change="emit('update:page', $event)"
        @size-change="emit('update:pageSize', $event)"
      />
    </div>
  </div>
</template>
