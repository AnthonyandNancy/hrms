<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import type { TrainingSession } from '../api'
import TrainingEnrollmentPanel from './TrainingEnrollmentPanel.vue'
import TrainingCheckinPanel from './TrainingCheckinPanel.vue'
import TrainingExamPanel from './TrainingExamPanel.vue'
import TrainingFeedbackPanel from './TrainingFeedbackPanel.vue'

const props = defineProps<{
  open: boolean
  programId: number | null
  sessionId: number | null
  sessionRow?: TrainingSession | null
}>()

const emit = defineEmits<{ 'update:open': [value: boolean] }>()

defineOptions({ name: 'SessionOperationDrawer' })

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canViewEnrollment = computed(() => session.can('training.enrollment.manage'))
const canViewCheckin = computed(() => session.can('training.checkin.manage'))
const canViewExam = computed(() => session.can('training.exam.manage'))
const canViewFeedback = computed(() => session.can('training.feedback.manage'))

const drawer = computed({
  get: () => props.open,
  set: (value: boolean) => emit('update:open', value),
})
const activeTab = ref('enrollment')
const sessionLabel = computed(() => {
  if (props.sessionRow === null || props.sessionRow === undefined) return props.sessionId === null ? '未选择场次' : `场次 #${props.sessionId}`
  const starts = props.sessionRow.startsAt === null ? '' : ` ${formatDateTimeInTimezone(props.sessionRow.startsAt).slice(11)}`
  return `${formatBusinessDate(props.sessionRow.startsOn)}${starts}`
})


function close(): void {
  void router.replace({ path: '/training', query: { ...route.query, session: undefined } })
  drawer.value = false
}
</script>

<template>
  <el-drawer
    v-model="drawer"
    title="场次操作中心"
    size="640px"
    data-testid="training-session-drawer"
    @close="close"
  >
    <div class="session-drawer">
      <header class="session-drawer__summary">
        <div>
          <p class="session-drawer__eyebrow">培训管理 · 场次 #{{ sessionId ?? '—' }}</p>
          <h2>{{ sessionLabel }}</h2>
          <p>项目 #{{ programId ?? '—' }} · 场次操作全部为真实命令；任何失败都不会被伪造成成功。</p>
        </div>
      </header>

      <el-tabs v-model="activeTab" data-testid="training-session-tabs">
        <el-tab-pane label="报名" name="enrollment">
          <TrainingEnrollmentPanel
            v-if="sessionId !== null && canViewEnrollment"
            :session-id="sessionId"
            :selected-program-id="programId"
          />
          <p v-else class="session-drawer__forbidden">当前账号缺少 training.enrollment.manage，报名面板不可用。</p>
        </el-tab-pane>

        <el-tab-pane label="签到" name="checkin">
          <TrainingCheckinPanel v-if="sessionId !== null && canViewCheckin" :session-id="sessionId" />
          <p v-else class="session-drawer__forbidden">当前账号缺少 training.checkin.manage，签到面板不可用。</p>
        </el-tab-pane>

        <el-tab-pane label="考核" name="exam">
          <TrainingExamPanel
            v-if="sessionId !== null && canViewExam"
            :session-id="sessionId"
            :course-id="sessionRow?.courseId ?? null"
          />
          <p v-else class="session-drawer__forbidden">当前账号缺少 training.exam.manage，考核面板不可用。</p>
        </el-tab-pane>

        <el-tab-pane label="反馈" name="feedback">
          <TrainingFeedbackPanel v-if="sessionId !== null && canViewFeedback" :session-id="sessionId" />
          <p v-else class="session-drawer__forbidden">当前账号缺少 training.feedback.manage，反馈面板不可用。</p>
        </el-tab-pane>
      </el-tabs>
    </div>
  </el-drawer>
</template>

<style scoped>
.session-drawer { display: flex; flex-direction: column; gap: 12px; }
.session-drawer__summary { padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
.session-drawer__eyebrow { margin: 0 0 4px; color: #64748b; font-size: 11.5px; }
.session-drawer__summary h2 { margin: 0 0 4px; font-size: 16px; color: #0f172a; }
.session-drawer__summary p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.session-drawer__forbidden { margin: 0; padding: 16px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #991b1b; font-size: 12px; }
</style>
