<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import TrainingProgramDrawer from '../components/TrainingProgramDrawer.vue'
import TrainingPlansPanel from '../components/TrainingPlansPanel.vue'
import TrainingCoursesPanel from '../components/TrainingCoursesPanel.vue'
import TrainingSessionsPanel from '../components/TrainingSessionsPanel.vue'
import TrainingEnrollmentPanel from '../components/TrainingEnrollmentPanel.vue'
import TrainingCheckinPanel from '../components/TrainingCheckinPanel.vue'
import TrainingExamPanel from '../components/TrainingExamPanel.vue'
import TrainingFeedbackPanel from '../components/TrainingFeedbackPanel.vue'
import TrainingRecordsPanel from '../components/TrainingRecordsPanel.vue'
import TrainingReportsPanel from '../components/TrainingReportsPanel.vue'
import SessionOperationDrawer from '../components/SessionOperationDrawer.vue'
import {
  businessDate,
  createProgram,
  listPlans,
  listPrograms,
  listSessions,
  programStatusLabel,
  trainingActionErrorCopy,
  type ProgramDraft,
  type TrainingPlan,
  type TrainingProgram,
  type TrainingSession,
} from '../api'

defineOptions({ name: 'TrainingWorkspacePage' })

const tabs: Array<{ name: WorkspaceTab; label: string }> = [
  { name: 'plans', label: '计划' },
  { name: 'courses', label: '课程' },
  { name: 'sessions', label: '场次' },
  { name: 'enrollments', label: '报名' },
  { name: 'checkins', label: '签到' },
  { name: 'exams', label: '考试' },
  { name: 'feedback', label: '反馈' },
  { name: 'records', label: '档案' },
  { name: 'reports', label: '报表' },
]

type WorkspaceTab =
  | 'plans'
  | 'courses'
  | 'sessions'
  | 'enrollments'
  | 'checkins'
  | 'exams'
  | 'feedback'
  | 'records'
  | 'reports'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('training.program.read'))
const canManageProgram = computed(() => session.can('training.program.manage'))

const activeTab = ref<WorkspaceTab>(
  // A deep link that names a real session opens the session operation center
  // directly. Without this, ?program=..&session=.. silently lands on plans and
  // the drawer can never open.
  route.query.tab === undefined && parseId(route.query.session) !== null
    ? 'sessions'
    : parseTab(route.query.tab),
)
const loading = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const successMessage = ref('')

const plans = ref<TrainingPlan[]>([])
const programs = ref<TrainingProgram[]>([])
const sessions = ref<TrainingSession[]>([])
const selectedProgramId = ref<number | null>(parseId(route.query.program))
const selectedSessionId = ref<number | null>(parseId(route.query.session))

const drawerOpen = computed({
  get: () => activeTab.value === 'sessions' && selectedSessionId.value !== null,
  set: (value: boolean) => { if (!value) closeDrawer() },
})
const drawerMode = ref<'create' | 'edit'>('create')
const drawerSubmitting = ref(false)
const drawerError = ref('')
const createOpen = ref(false)

const selectedProgram = computed(
  () => programs.value.find((row) => row.id === selectedProgramId.value) ?? null,
)
const selectedSession = computed(
  () => sessions.value.find((row) => row.id === selectedSessionId.value) ?? null,
)
const needsSession = computed(() =>
  ['enrollments', 'checkins', 'exams', 'feedback'].includes(activeTab.value),
)

function parseId(value: unknown): number | null {
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function parseTab(value: unknown): WorkspaceTab {
  const candidate = typeof value === 'string' ? value : ''
  return tabs.some((tab) => tab.name === candidate) ? (candidate as WorkspaceTab) : 'plans'
}

function defaultTabForCapabilities(): WorkspaceTab {
  if (session.can('training.plan.manage')) return 'plans'
  if (session.can('training.course.manage')) return 'courses'
  if (session.can('training.session.manage')) return 'sessions'
  if (session.can('training.enrollment.manage')) return 'enrollments'
  if (session.can('training.checkin.manage')) return 'checkins'
  if (session.can('training.exam.manage')) return 'exams'
  if (session.can('training.feedback.manage')) return 'feedback'
  if (session.can('training.record.read')) return 'records'
  if (session.can('training.report.read')) return 'reports'
  return 'plans'
}

function tabVisible(tab: WorkspaceTab): boolean {
  switch (tab) {
    case 'plans':
      return session.can('training.plan.manage')
    case 'courses':
      return session.can('training.course.manage')
    case 'sessions':
      return session.can('training.session.manage')
    case 'enrollments':
      return session.can('training.enrollment.manage')
    case 'checkins':
      return session.can('training.checkin.manage')
    case 'exams':
      return session.can('training.exam.manage')
    case 'feedback':
      return session.can('training.feedback.manage')
    case 'records':
      return session.can('training.record.read')
    case 'reports':
      return session.can('training.report.read')
  }
}

const visibleTabs = computed(() => tabs.filter((tab) => tabVisible(tab.name)))

function syncQuery(): void {
  const query: Record<string, string | undefined> = {
    ...(route.query.tab === undefined ? {} : { tab: String(route.query.tab) }),
  }
  if (activeTab.value !== 'plans') query.tab = activeTab.value
  else delete query.tab
  query.program = selectedProgramId.value === null ? undefined : String(selectedProgramId.value)
  query.session = selectedSessionId.value === null ? undefined : String(selectedSessionId.value)
  const current = typeof route.query.tab === 'string' ? route.query.tab : ''
  const currentProgram = parseId(route.query.program)
  const currentSession = parseId(route.query.session)
  if (
    current === (query.tab ?? '') &&
    currentProgram === selectedProgramId.value &&
    currentSession === selectedSessionId.value
  ) {
    return
  }
  void router.replace({ path: '/training', query }).catch(() => undefined)
}

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取培训项目。请确认已获得 training.program.read 能力。'
      : trainingActionErrorCopy(reason) || errorCopy(reason)
}

async function load(): Promise<void> {
  if (!canRead.value) {
    return
  }

  loading.value = true
  errorMessage.value = ''

  try {
    const [planRows, programRows] = await Promise.all([
      session.can('training.plan.manage') ? listPlans(businessDate()).catch(() => []) : Promise.resolve([] as TrainingPlan[]),
      listPrograms(businessDate()),
    ])
    plans.value = planRows
    programs.value = programRows

    if (selectedProgramId.value === null && programRows.length > 0) {
      selectedProgramId.value = programRows[0]?.id ?? null
    }

    if (selectedProgramId.value !== null && session.can('training.session.manage')) {
      sessions.value = await listSessions(selectedProgramId.value, undefined, businessDate())
    } else {
      sessions.value = []
    }

    if (
      session.can('training.session.manage') &&
      selectedSessionId.value !== null &&
      !sessions.value.some((row) => row.id === selectedSessionId.value)
    ) {
      selectedSessionId.value = sessions.value[0]?.id ?? null
    }

  } catch (reason) {
    plans.value = []
    programs.value = []
    sessions.value = []
    captureError(reason)
  } finally {
    loading.value = false
  }
}

async function reloadSessions(): Promise<void> {
  if (selectedProgramId.value === null || !session.can('training.session.manage')) {
    sessions.value = []
    return
  }
  try {
    sessions.value = await listSessions(selectedProgramId.value, undefined, businessDate())
  } catch (reason) {
    sessions.value = []
    captureError(reason)
  }
}

function selectProgram(id: number | null): void {
  selectedProgramId.value = id
  sessions.value = []
  if (activeTab.value === 'plans' || activeTab.value === 'courses' || activeTab.value === 'records' || activeTab.value === 'reports') {
    syncQuery()
    return
  }
  void reloadSessions().then(() => {
    if (selectedSessionId.value === null) {
      selectedSessionId.value = sessions.value[0]?.id ?? null
    }
    syncQuery()
  })
}

function openSession(row: TrainingSession): void {
  selectedProgramId.value = row.programId
  selectedSessionId.value = row.id
  activeTab.value = 'sessions'
  syncQuery()
}

function closeDrawer(): void {
  selectedSessionId.value = null
  syncQuery()
}

function openCreate(): void {
  if (!canManageProgram.value) return
  drawerMode.value = 'create'
  drawerError.value = ''
  createOpen.value = true
}

async function saveProgram(draft: ProgramDraft): Promise<void> {
  drawerSubmitting.value = true
  drawerError.value = ''
  try {
    const created = await createProgram(draft)
    createOpen.value = false
    successMessage.value = '培训项目已创建，列表已按服务端最新数据刷新。'
    await load()
    if (created.id > 0) {
      selectedProgramId.value = created.id
      await reloadSessions()
      syncQuery()
    }
  } catch (reason) {
    drawerError.value = trainingActionErrorCopy(reason)
  } finally {
    drawerSubmitting.value = false
  }
}

function changeTab(name: string | number): void {
  const next = parseTab(String(name))
  if (!tabVisible(next)) return
  activeTab.value = next
  syncQuery()
}


watch(activeTab, () => syncQuery())
watch(
  () => route.query.tab,
  (value) => {
    const next = parseTab(value)
    if (tabVisible(next)) activeTab.value = next
  },
)
watch(
  () => route.query.session,
  (value) => {
    const next = parseId(value)
    if (next !== selectedSessionId.value) selectedSessionId.value = next
  },
)

onMounted(async () => {
  if (!canRead.value) {
    return
  }
  if (!tabVisible(activeTab.value)) activeTab.value = defaultTabForCapabilities()
  await load()
})
</script>

<template>
  <PageShell
    title="培训工作台"
    description="计划、课程、场次、报名、签到、考试、反馈、档案与报表的唯一入口；场次详情在工作区内以查询参数打开的抽屉呈现。"
  >
    <template #actions>
      <button type="button" class="training-workspace__secondary" :disabled="loading" @click="load">刷新</button>
      <button
        v-if="canManageProgram"
        type="button"
        class="training-workspace__primary"
        data-testid="training-program-create"
        @click="openCreate"
      >
        新建培训项目
      </button>
    </template>

    <section v-if="!canRead" class="training-workspace__state" role="alert" data-testid="training-forbidden">
      <h2>无权访问培训工作台</h2>
      <p>当前账号缺少 training.program.read 能力，页面不会发起任何培训读取请求。</p>
    </section>

    <template v-else>
      <p v-if="successMessage !== ''" class="training-workspace__success" role="status">{{ successMessage }}</p>

      <ErrorState
        v-if="errorMessage !== ''"
        title="培训工作台暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载培训工作台" />

      <template v-else>
        <section class="training-workspace__filters" aria-label="培训筛选">
          <label>
            <span>培训项目</span>
            <el-select
              :model-value="selectedProgramId === null ? '' : String(selectedProgramId)"
              data-testid="training-program-filter"
              @update:model-value="(value: string | number) => selectProgram(value === '' ? null : Number(value))"
            >
              <el-option value="" label="全部项目" />
              <el-option v-for="program in programs" :key="program.id" :value="String(program.id)" :label="`${program.code} · ${program.title}`" />
            </el-select>
          </label>
          <label>
            <span>培训计划</span>
            <el-select :model-value="selectedProgram?.code ?? ''" disabled placeholder="项目关联的计划由课程与场次事实派生">
              <el-option :value="selectedProgram?.code ?? ''" :label="selectedProgram === null ? '未选择项目' : `${selectedProgram.code} · ${programStatusLabel(selectedProgram.status)}`" />
            </el-select>
          </label>
        </section>

        <el-tabs :model-value="activeTab" data-testid="training-workspace-tabs" @tab-change="changeTab">
          <el-tab-pane v-for="tab in visibleTabs" :key="tab.name" :label="tab.label" :name="tab.name" />
        </el-tabs>

        <EmptyState
          v-if="programs.length === 0 && activeTab !== 'plans' && activeTab !== 'courses' && activeTab !== 'records' && activeTab !== 'reports'"
          title="还没有培训项目"
          description="课程和场次需要先有培训项目；具备 training.program.manage 的账号可以在右上角新建第一个项目。"
        />
        <TrainingPlansPanel v-if="activeTab === 'plans'" />
        <TrainingCoursesPanel v-else-if="activeTab === 'courses'" />
        <TrainingSessionsPanel
          v-else-if="activeTab === 'sessions'"
          :program-id="selectedProgramId"
          :selected-session-id="selectedSessionId"
          @open-session="openSession"
        />
        <EmptyState
          v-else-if="needsSession && selectedSessionId === null"
          title="请选择培训场次"
          description="报名、签到、考试与反馈都按场次加载；请先在场次 Tab 选择或新建场次。"
        />
        <TrainingEnrollmentPanel
          v-else-if="activeTab === 'enrollments' && selectedSessionId !== null"
          :session-id="selectedSessionId"
          :selected-program-id="selectedProgramId"
        />
        <TrainingCheckinPanel v-else-if="activeTab === 'checkins' && selectedSessionId !== null" :session-id="selectedSessionId" />
        <TrainingExamPanel
          v-else-if="activeTab === 'exams' && selectedSessionId !== null"
          :session-id="selectedSessionId"
          :course-id="selectedSession?.courseId ?? null"
        />
        <TrainingFeedbackPanel v-else-if="activeTab === 'feedback' && selectedSessionId !== null" :session-id="selectedSessionId" />
        <TrainingRecordsPanel v-else-if="activeTab === 'records'" />
        <TrainingReportsPanel v-else-if="activeTab === 'reports'" />

        <SessionOperationDrawer
          :open="drawerOpen"
          :program-id="selectedProgramId"
          :session-id="selectedSessionId"
          :session-row="selectedSession"
          @update:open="(value) => { if (!value) closeDrawer() }"
        />
      </template>
    </template>

    <TrainingProgramDrawer
      v-model="createOpen"
      :mode="drawerMode"
      :submitting="drawerSubmitting"
      :server-error="drawerError"
      @save="saveProgram"
    />
  </PageShell>
</template>

<style scoped>
.training-workspace__primary,
.training-workspace__secondary {
  padding: 8px 14px;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
  background: #fff;
  font-size: 12.5px;
  cursor: pointer;
}
.training-workspace__primary {
  background: #1d4ed8;
  border-color: #1d4ed8;
  color: #fff;
}
.training-workspace__state {
  padding: 24px;
  border: 1px solid #fecaca;
  border-radius: 12px;
  background: #fef2f2;
  color: #991b1b;
}
.training-workspace__state h2 { margin: 0 0 6px; font-size: 16px; }
.training-workspace__state p { margin: 0; font-size: 12.5px; }
.training-workspace__success {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #ecfdf5;
  color: #166534;
  font-size: 12.5px;
}
.training-workspace__filters {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}
.training-workspace__filters label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 220px;
  font-size: 12px;
  color: #334155;
}
</style>
