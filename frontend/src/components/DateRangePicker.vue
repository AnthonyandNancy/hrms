<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate } from '@/utils/format'

const props = defineProps<{
  modelValue: [string, string] | null
  label?: string
  startPlaceholder?: string
  endPlaceholder?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: [string, string] | null]
}>()

const model = computed({
  get: () => props.modelValue,
  set: (value: [string, string] | null) => emit('update:modelValue', value),
})

const summary = computed(() => {
  if (!props.modelValue) return '未选择期间'
  return `${formatBusinessDate(props.modelValue[0])} 至 ${formatBusinessDate(props.modelValue[1])}`
})
</script>

<template>
  <div class="range-picker">
    <el-date-picker
      v-model="model"
      type="daterange"
      value-format="YYYY-MM-DD"
      format="YYYY-MM-DD"
      unlink-panels
      :start-placeholder="startPlaceholder ?? '开始日期'"
      :end-placeholder="endPlaceholder ?? '结束日期'"
      :aria-label="label ?? '日期范围'"
    />
    <span class="range-picker__summary" aria-live="polite">{{ summary }}</span>
  </div>
</template>
