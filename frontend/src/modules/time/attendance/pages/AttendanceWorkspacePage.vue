<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import { useRouter } from 'vue-router'
import AttendanceCalendar from '../components/AttendanceCalendar.vue'
import AttendanceCorrectionDrawer from '../components/AttendanceCorrectionDrawer.vue'
import AttendanceExceptionList from '../components/AttendanceExceptionList.vue'
import {
  getAttendanceDays,
  getAttendanceExceptions,
  newAttendanceIdempotencyKey,
  resolveAttendanceException,
  type AttendanceExceptionRow,
  type AttendanceExceptionPage,
  type AttendancePeriod,
} from '../api'

defineOptions({ name: 'AttendanceWorkspacePage' })

const session = useSessionStore()
const router = useRouter()
const pageError = usePageError()

const loading = ref(false)
const loaded = ref(false)
const period = ref<AttendancePeriod | null>(null)
const exceptions = ref<AttendanceExceptionPage | null>(null)
const exceptionError = ref<string | null>(null)
const actionMessage = ref('')
const actionError = ref('')
const correctionOpen = ref(false)
const correctionDate = ref('')
const resolvingId = ref<number | null>(null)

/**
 * Initial month is intentionally unset: the backend decides the current
 * business month from the company profile timezone. The browser clock is never
 * used to derive a business date.
 */
const selectedMonth = ref('')
const employeeFilter = ref('')
const orgUnitFilter = ref('')

const canRead = computed(() => session.can('time.attendance.read'))
const canRequestCorrection = computed(() => session.can('time.attendance.correct.request'))
const canResolveException = computed(() => session.can('time.attendance.manage'))
const canManageRules = computed(() => session.can('time.attendance.rule.manage'))

const hasDays = computed(() => (period.value?.days.length ?? 0) > 0)
const empty = computed(() => loaded.value && period.value !== null && !hasDays.value)
const partial = computed(() => period.value?.partial === true || exceptions.value?.partial === true || exceptionError.value !== null)
const partialReason = computed(
  () =>
    period.value?.partialReason ??
    exceptions.value?.partialReason ??
    exceptionError.value ??
    '',
)

const openExceptions = computed<AttendanceExceptionRow[]>(() =>
  (exceptions.value?.items ?? []).filter(
    (exception) => exception.status === 'OPEN' || exception.status === 'PENDING',
  ),
)

function integerFilter(value: string): number | null {
  const trimmed = value.trim()
  if (!/^\d+$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

async function load(): Promise<void> {
  if (!canRead.value) return
  pageError.clear()
  actionError.value = ''
  loading.value = true
  loaded.value = false
  exceptionError.value = null

  const query = {
    ...(selectedMonth.value === '' ? {} : { month: selectedMonth.value }),
    employeeId: integerFilter(employeeFilter.value),
    orgUnitId: integerFilter(orgUnitFilter.value),
  }

  try {
    period.value = await getAttendanceDays(query)
    selectedMonth.value = period.value.month !== '' ? period.value.month : selectedMonth.value
  } catch (reason) {
    period.value = null
    exceptions.value = null
    pageError.capture(reason)
    loading.value = false
    loaded.value = true
    return
  }

  try {
    exceptions.value = await getAttendanceExceptions(query)
  } catch (reason) {
    exceptions.value = null
    exceptionError.value = reason instanceof ApiError ? reasonCopy(reason) : '异常列表暂不可用'
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function reasonCopy(reason: ApiError): string {
  const field = Object.values(reason.fieldErrors).flat()[0]
  return field ?? reason.message ?? '异常列表暂不可用'
}

function applyFilters(): void {
  void load()
}

function openCorrection(businessDate = ''): void {
  if (!canRequestCorrection.value) return
  correctionDate.value = businessDate !== '' ? businessDate : (period.value?.days[0]?.businessDate ?? '')
  actionError.value = ''
  actionMessage.value = ''
  correctionOpen.value = true
}

function onCorrectionSubmitted(requestNo: string): void {
  actionMessage.value = `补卡申请已提交：${requestNo || '已受理'}`
  void load()
}

async function resolveException(exceptionId: number, note: string): Promise<void> {
  if (!canResolveException.value) return
  actionError.value = ''
  actionMessage.value = ''
  resolvingId.value = exceptionId
  try {
    await resolveAttendanceException(exceptionId, note, newAttendanceIdempotencyKey('attendance-exception'))
    actionMessage.value = '考勤异常已解决。'
    await load()
  } catch (reason) {
    actionError.value = reason instanceof ApiError ? (reason.message || '异常处理未完成。') : '异常处理未完成。'
  } finally {
    resolvingId.value = null
  }
}

function goToRules(): void {
  void router.push('/attendance/rules')
}

onMounted(() => {
  if (canRead.value) void load()
  else loaded.value = true
})
</script>

<template>
  <div class="page attendance-workspace" data-page="attendance">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">假勤与薪酬 · 考勤打卡</p>
        <h1 class="page-header__title">考勤工作台</h1>
        <p class="page-header__description">
          按月查看出勤、迟到、早退、缺勤与休假；补卡与异常处理遵循服务端业务日期和公司时区。
        </p>
      </div>
      <div class="page-header__actions">
        <a
          v-if="canManageRules"
          class="attendance-workspace__rules-link"
          href="/attendance/rules"
          data-testid="attendance-rules-link"
          @click.prevent="goToRules"
        >
          考勤规则
        </a>
        <button v-if="canRequestCorrection" type="button" class="attendance-workspace__primary" data-testid="open-correction" @click="openCorrection()">
          补卡
        </button>
        <button type="button" class="attendance-workspace__secondary" :disabled="loading || !canRead" @click="load">
          刷新
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="state-panel empty-state" role="status">
      <div class="state-panel__mark" aria-hidden="true">—</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权查看考勤</h2>
        <p class="state-panel__body">当前账号没有考勤查看权限。请通过角色授权申请后重试。</p>
      </div>
    </section>

    <template v-else>
      <form class="attendance-workspace__filters" aria-label="考勤筛选" @submit.prevent="applyFilters">
        <label>
          <span>业务月份</span>
          <input v-model="selectedMonth" data-testid="attendance-month" type="month" />
        </label>
        <label>
          <span>员工编号</span>
          <input v-model="employeeFilter" data-testid="attendance-employee" inputmode="numeric" placeholder="按授权范围筛选" />
        </label>
        <label>
          <span>组织单元编号</span>
          <input v-model="orgUnitFilter" data-testid="attendance-org-unit" inputmode="numeric" placeholder="主管按授权子树筛选" />
        </label>
        <button type="submit" class="attendance-workspace__secondary" :disabled="loading">应用筛选</button>
      </form>

      <p v-if="actionMessage" class="attendance-workspace__success" role="status">{{ actionMessage }}</p>
      <p v-if="actionError" class="attendance-workspace__error" role="alert">{{ actionError }}</p>

      <ErrorState
        v-if="pageError.message.value"
        title="考勤工作台暂时无法加载"
        :description="pageError.message.value"
        :code="pageError.code.value"
        :request-id="pageError.requestId.value"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载考勤工作台" />

      <template v-else>
        <section v-if="partial" class="attendance-workspace__partial" role="status" data-testid="attendance-partial">
          <strong>部分数据</strong>
          <span>{{ partialReason || '异常列表暂不可用；考勤日历仍按服务端返回结果展示。' }}</span>
        </section>

        <EmptyState
          v-if="empty"
          title="本月暂无考勤记录"
          description="服务端尚未返回该业务月份的考勤日。可调整月份或等待考勤计算完成。"
        />

        <template v-else-if="period !== null">
          <AttendanceCalendar
            :month="period.month"
            :timezone="period.timezone"
            :days="period.days"
            :can-correct="canRequestCorrection"
            @correct="openCorrection"
          />

          <AttendanceExceptionList
            :exceptions="openExceptions"
            :can-resolve="canResolveException"
            :resolving-id="resolvingId"
            @resolve="resolveException"
          />
        </template>
      </template>
    </template>

    <AttendanceCorrectionDrawer
      v-model="correctionOpen"
      :business-date="correctionDate"
      :timezone="period?.timezone ?? ''"
      :employment-id="period?.employmentId ?? null"
      @submitted="onCorrectionSubmitted"
    />
  </div>
</template>

<style scoped>
.attendance-workspace {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.attendance-workspace__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-workspace__filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 150px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-workspace__filters input {
  padding: 6px 8px;
  font: inherit;
  color: var(--color-ink);
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-workspace__primary,
.attendance-workspace__secondary,
.attendance-workspace__rules-link {
  padding: 6px 12px;
  font-size: 12px;
  text-decoration: none;
  border-radius: 6px;
  cursor: pointer;
}

.attendance-workspace__primary {
  color: #fff;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
}

.attendance-workspace__secondary,
.attendance-workspace__rules-link {
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
}

.attendance-workspace__success {
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 12px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}

.attendance-workspace__error {
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 12px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-workspace__partial {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 9px 12px;
  color: #6b4a00;
  font-size: 11.5px;
  background: #fffaf0;
  border: 1px dashed #e6c98d;
  border-radius: 6px;
}
</style>
