<script setup lang="ts">
import { computed, ref } from 'vue'
import { Calendar } from '@element-plus/icons-vue'
import { formatBusinessDate } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    allowFuture?: boolean
    disabled?: boolean
    description?: string
  }>(),
  { label: 'as-of 业务日期', allowFuture: false, disabled: false, description: '' },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  change: [value: string]
}>()

const pickerValue = ref(props.modelValue)
const model = computed({
  get: () => pickerValue.value,
  set: (value: string) => {
    pickerValue.value = value
    emit('update:modelValue', value)
    emit('change', value)
  },
})

const disabledDate = (date: Date) => {
  if (props.allowFuture) return false
  const end = new Date()
  end.setHours(23, 59, 59, 999)
  return date.getTime() > end.getTime()
}
</script>

<template>
  <div class="as-of-picker">
    <el-date-picker
      v-model="model"
      type="date"
      value-format="YYYY-MM-DD"
      format="YYYY-MM-DD"
      :placeholder="label"
      :disabled="disabled"
      :disabled-date="disabledDate"
      :prefix-icon="Calendar"
      :aria-label="label"
    />
    <span v-if="description" class="as-of-picker__description">
      {{ description }} · 当前查看 {{ formatBusinessDate(modelValue) }}
    </span>
    <span v-else class="as-of-picker__description">按 {{ formatBusinessDate(modelValue) }} 的有效关系回答</span>
  </div>
</template>
