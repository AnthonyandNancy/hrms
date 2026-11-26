<script setup lang="ts">
import { ElDialog } from 'element-plus'

const props = defineProps<{ modelValue: boolean; title: string; confirmLoading?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [] }>()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <ElDialog :model-value="props.modelValue" :title="props.title" width="640px" @close="close">
    <slot />
    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="props.confirmLoading" @click="emit('confirm')">保存</el-button>
    </template>
  </ElDialog>
</template>
