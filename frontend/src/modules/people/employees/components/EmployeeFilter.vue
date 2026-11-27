<script setup lang="ts">
import { onBeforeUnmount, reactive, watch } from 'vue'
import type { OrgUnitOption } from '../api'

export type EmployeeFilterValue = {
  as_of: string
  q: string
  org_unit_id?: number
  include_archived: boolean
}

const props = withDefaults(
  defineProps<{
    asOf: string
    q: string
    orgUnitId?: number
    includeArchived: boolean
    orgUnits?: OrgUnitOption[]
    disabled?: boolean
  }>(),
  { orgUnitId: undefined, orgUnits: () => [], disabled: false },
)

const emit = defineEmits<{ change: [value: EmployeeFilterValue] }>()

const local = reactive({
  asOf: props.asOf,
  q: props.q,
  orgUnitId: props.orgUnitId === undefined ? '' : String(props.orgUnitId),
  includeArchived: props.includeArchived,
})

watch(
  () => props.asOf,
  (value) => {
    local.asOf = value
  },
)
watch(
  () => props.q,
  (value) => {
    local.q = value
  },
)
watch(
  () => props.orgUnitId,
  (value) => {
    local.orgUnitId = value === undefined ? '' : String(value)
  },
)
watch(
  () => props.includeArchived,
  (value) => {
    local.includeArchived = value
  },
)

let timer: ReturnType<typeof setTimeout> | null = null

function payload(): EmployeeFilterValue {
  return {
    as_of: local.asOf,
    q: local.q.trim(),
    org_unit_id: local.orgUnitId === '' ? undefined : Number(local.orgUnitId),
    include_archived: local.includeArchived,
  }
}

/**
 * Every filter shares one short debounce. A request is only issued after the
 * operator stops changing the form; the page then resets to page 1.
 */
function schedule(): void {
  if (props.disabled) return
  if (timer !== null) clearTimeout(timer)
  timer = setTimeout(() => {
    timer = null
    emit('change', payload())
  }, 250)
}

function reset(): void {
  local.q = ''
  local.orgUnitId = ''
  local.includeArchived = false
  schedule()
}

onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <section class="employee-filter" aria-label="员工筛选">
    <label class="employee-filter__field employee-filter__field--wide">
      <span>搜索</span>
      <input
        v-model="local.q"
        type="search"
        aria-label="搜索员工"
        placeholder="员工编号或姓名"
        :disabled="disabled"
        @input="schedule"
      />
    </label>

    <label class="employee-filter__field">
      <span>业务日期</span>
      <input
        v-model="local.asOf"
        type="date"
        aria-label="业务日期"
        :disabled="disabled"
        @change="schedule"
      />
    </label>

    <label class="employee-filter__field">
      <span>部门</span>
      <select
        v-model="local.orgUnitId"
        aria-label="按部门筛选"
        :disabled="disabled"
        @change="schedule"
      >
        <option value="">全部部门</option>
        <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
          {{ unit.name }}{{ unit.code ? `（${unit.code}）` : '' }}
        </option>
      </select>
    </label>

    <label class="employee-filter__check">
      <input
        v-model="local.includeArchived"
        type="checkbox"
        aria-label="包含已归档"
        :disabled="disabled"
        @change="schedule"
      />
      <span>包含已归档</span>
    </label>

    <button type="button" class="employee-filter__reset" :disabled="disabled" @click="reset">
      重置筛选
    </button>
  </section>
</template>

<style scoped>
.employee-filter {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  align-items: flex-end;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.employee-filter__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 170px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.employee-filter__field--wide {
  flex: 1 1 230px;
}

.employee-filter__field > span,
.employee-filter__check > span {
  font-weight: 600;
}

.employee-filter__field input,
.employee-filter__field select,
.employee-filter__reset {
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.employee-filter__field input:focus,
.employee-filter__field select:focus,
.employee-filter__reset:focus {
  border-color: var(--color-brand);
  outline: 2px solid var(--color-brand-soft);
  outline-offset: 0;
}

.employee-filter__check {
  display: flex;
  gap: 6px;
  align-items: center;
  min-height: 33px;
  color: var(--color-ink-secondary);
  font-size: 12px;
}

.employee-filter__reset {
  min-height: 33px;
  color: var(--color-brand);
  cursor: pointer;
}

.employee-filter__reset:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
