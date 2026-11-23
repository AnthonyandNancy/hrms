<script setup lang="ts">
import { computed, ref } from 'vue'
import { Lock, View } from '@element-plus/icons-vue'
import type { MaskedFieldValue } from '@/api/contracts'
import { FIELD_PROFILE_LABEL } from '@/utils/status'

const props = withDefaults(
  defineProps<{
    value: MaskedFieldValue
    label: string
    canReveal?: boolean
  }>(),
  { canReveal: false },
)

const emit = defineEmits<{
  reveal: [value: MaskedFieldValue]
}>()

const revealing = ref(false)
const revealed = ref(false)

const displayValue = computed(() => {
  if (!props.value.display) return '未登记'
  if (props.value.masked && !revealed.value) return props.value.display
  return props.value.display
})

function requestReveal(): void {
  if (!props.value.masked) return
  revealing.value = true
  // The actual value comes from a field-authorized backend endpoint. This
  // component never fabricates cleartext from a mask.
  emit('reveal', props.value)
  revealing.value = false
}
</script>

<template>
  <span class="masked-field" :class="{ 'masked-field--masked': value.masked }">
    <span class="masked-field__label">{{ label }}</span>
    <span class="masked-field__value" :aria-label="`${label}：${displayValue}${value.masked ? '（已遮罩）' : ''}`">
      {{ displayValue }}
    </span>
    <el-tag
      v-if="value.masked"
      size="small"
      type="info"
      effect="plain"
      class="masked-field__tag"
      :title="`字段档位 ${FIELD_PROFILE_LABEL[value.profile]}；${value.exportable ? '可导出' : '不可导出'}`"
    >
      <el-icon aria-hidden="true"><Lock /></el-icon>
      已遮罩
    </el-tag>
    <el-button
      v-if="value.masked && canReveal"
      text
      size="small"
      :loading="revealing"
      :aria-label="`查看 ${label} 的完整值（将记录审计）`"
      @click="requestReveal"
    >
      <el-icon aria-hidden="true"><View /></el-icon>
      查看
    </el-button>
  </span>
</template>
