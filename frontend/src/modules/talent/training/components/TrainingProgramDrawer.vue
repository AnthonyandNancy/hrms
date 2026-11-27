<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { ProgramDraft, TrainingProgram } from '../api'

const props = defineProps<{
  modelValue: boolean
  mode: 'create' | 'edit'
  program?: TrainingProgram | null
  submitting?: boolean
  serverError?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [draft: ProgramDraft]
}>()

defineOptions({ name: 'TrainingProgramDrawer' })

const draft = reactive<{ code: string; title: string; defaultCapacity: string }>({
  code: '',
  title: '',
  defaultCapacity: '20',
})

const localError = ref('')

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

const title = computed(() => (props.mode === 'create' ? '新建培训项目' : '编辑培训项目'))

watch(
  () => props.modelValue,
  (value) => {
    if (!value) return
    localError.value = ''
    draft.code = props.program?.code ?? ''
    draft.title = props.program?.title ?? ''
    draft.defaultCapacity = String(props.program?.defaultCapacity ?? props.program?.capacity ?? 20)
  },
)

function submit(): void {
  const code = draft.code.trim()
  const programTitle = draft.title.trim()
  const capacity = Number(draft.defaultCapacity)

  if (code === '') {
    localError.value = '请填写项目编码。'
    return
  }
  if (programTitle === '') {
    localError.value = '请填写项目名称。'
    return
  }
  if (!Number.isSafeInteger(capacity) || capacity < 0 || capacity > 10000) {
    localError.value = '默认容量必须为 0 到 10000 之间的整数。'
    return
  }

  localError.value = ''
  emit('save', { code, title: programTitle, defaultCapacity: capacity })
}

function close(): void {
  open.value = false
}
</script>

<template>
  <el-drawer v-model="open" :title="title" size="480px" data-testid="training-program-drawer">
    <div class="training-program-drawer">
      <p class="training-program-drawer__note">
        项目编码用于幂等创建；同编码不同内容会返回冲突而不是静默复用。创建成功后由服务端返回真实项目编号。
        默认容量只作为新场次的建议值，实际名额以每个场次返回的容量为准。
      </p>

      <label class="training-program-drawer__field">
        <span>项目编码</span>
        <input
          v-model="draft.code"
          type="text"
          maxlength="64"
          data-testid="training-program-code"
          autocomplete="off"
        />
      </label>

      <label class="training-program-drawer__field">
        <span>项目名称</span>
        <input
          v-model="draft.title"
          type="text"
          maxlength="160"
          data-testid="training-program-title"
          autocomplete="off"
        />
      </label>

      <label class="training-program-drawer__field">
        <span>默认容量</span>
        <input
          v-model="draft.defaultCapacity"
          type="number"
          min="0"
          max="10000"
          data-testid="training-program-capacity"
        />
      </label>

      <p v-if="localError !== ''" class="training-program-drawer__error" role="alert" data-testid="training-program-form-error">
        {{ localError }}
      </p>
      <p v-if="serverError !== ''" class="training-program-drawer__error" role="alert" data-testid="training-program-server-error">
        {{ serverError }}
      </p>

      <p class="training-program-drawer__hint">
        分类与交付方式由课程与场次事实派生，不在项目行上手工填写。
      </p>
    </div>

    <template #footer>
      <el-button :disabled="submitting === true" @click="close">取消</el-button>
      <el-button
        type="primary"
        :loading="submitting === true"
        data-testid="training-program-save"
        @click="submit"
      >
        保存
      </el-button>
    </template>
  </el-drawer>
</template>

<style scoped>
.training-program-drawer {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 4px 4px 16px;
}

.training-program-drawer__note,
.training-program-drawer__hint {
  margin: 0;
  color: #64748b;
  font-size: 12px;
  line-height: 1.6;
}

.training-program-drawer__field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12.5px;
  color: #334155;
}

.training-program-drawer__field input {
  height: 34px;
  padding: 0 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
}

.training-program-drawer__error {
  margin: 0;
  color: #b91c1c;
  font-size: 12px;
}
</style>
