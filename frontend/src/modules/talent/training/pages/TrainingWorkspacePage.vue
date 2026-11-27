<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatMoney } from '@/utils/format'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import TrainingProgramDrawer from '../components/TrainingProgramDrawer.vue'
import { DISPLAY_CODES } from '@/shared/display/dictionary'
import {
  createProgram,
  deliveryModeLabel,
  listPrograms,
  programStatusLabel,
  trainingActionErrorCopy,
  type ProgramDraft,
  type TrainingProgram,
  type DeliveryMode,
  type ProgramStatus,
} from '../api'

defineOptions({ name: 'TrainingWorkspacePage' })

const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('training.program.read'))
const canManage = computed(() => session.can('training.program.manage'))
const canManageEnrollments = computed(() => session.can('training.enrollment.manage'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')

const programColumns: DataTableColumn[] = [
  { key: 'title', label: '培训项目', minWidth: '200px' },
  { key: 'category', label: '分类', minWidth: '110px' },
  { key: 'deliveryMode', label: '形式', width: '90px' },
  { key: 'capacity', label: '报名 / 容量', minWidth: '150px' },
  { key: 'status', label: '状态', width: '100px' },
  { key: 'cost', label: '成本', minWidth: '110px' },
  { key: 'actions', label: '操作', minWidth: '200px' },
]

const programs = ref<TrainingProgram[]>([])
const successMessage = ref('')

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const drawerProgram = ref<TrainingProgram | null>(null)
const drawerSubmitting = ref(false)
const drawerError = ref('')
const editNoticeProgram = ref<TrainingProgram | null>(null)
const editNotice = ref('')

const confirmOpen = ref(false)
const pendingAction = ref<'publish' | 'close' | null>(null)
const pendingProgram = ref<TrainingProgram | null>(null)
const unsupportedRoute = ref('')

const isEmpty = computed(
  () => loaded.value && !loading.value && errorMessage.value === '' && programs.value.length === 0,
)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value =
      reason.code === 'FORBIDDEN'
        ? '无权读取培训项目。请确认已获得 training.program.read 能力。'
        : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '培训工作台暂时无法加载。'
  }
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''

  try {
    programs.value = await listPrograms()
  } catch (reason) {
    programs.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openCreate(): void {
  drawerMode.value = 'create'
  drawerProgram.value = null
  drawerError.value = ''
  drawerOpen.value = true
}

function openEdit(program: TrainingProgram): void {
  drawerMode.value = 'edit'
  drawerProgram.value = program
  drawerError.value = ''
  editNoticeProgram.value = program
  editNotice.value =
    '服务端尚未提供培训项目更新接口，编辑内容不会保存。'
  drawerOpen.value = true
}

async function save(draft: ProgramDraft): Promise<void> {
  if (drawerMode.value === 'edit') {
    // There is no program update route yet. Never issue a guessed PATCH.
    editNoticeProgram.value = drawerProgram.value
    editNotice.value =
      '服务端尚未提供培训项目更新接口，本次修改未提交。'
    drawerOpen.value = false
    return
  }

  drawerSubmitting.value = true
  drawerError.value = ''
  try {
    await createProgram(draft)
    drawerOpen.value = false
    successMessage.value = '培训项目已创建，列表已按服务端最新数据刷新。'
    await load()
  } catch (reason) {
    drawerError.value = trainingActionErrorCopy(reason)
  } finally {
    drawerSubmitting.value = false
  }
}

function requestLifecycle(action: 'publish' | 'close', program: TrainingProgram): void {
  pendingAction.value = action
  pendingProgram.value = program
  unsupportedRoute.value = ''
  confirmOpen.value = true
}

function confirmLifecycle(): void {
  const program = pendingProgram.value
  const action = pendingAction.value
  if (program === null || action === null) return

  // The confirmation is real; the mutation is not invented. Publishing and
  // closing have no backend route, so the page reports the exact missing
  // contract instead of faking a status transition.
  unsupportedRoute.value = `/v1/training/programs/${program.id}/${action}`
  successMessage.value = ''
  confirmOpen.value = false
  pendingAction.value = null
  pendingProgram.value = null
}

function cancelLifecycle(): void {
  confirmOpen.value = false
  pendingAction.value = null
  pendingProgram.value = null
}

function openDetail(program: TrainingProgram): void {
  void router.push(`/training/programs/${program.id}`)
}

function lifecycleLabel(action: 'publish' | 'close'): string {
  return action === 'publish' ? '发布' : '关闭'
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="training-workspace" data-page="training-workspace">
    <header class="training-workspace__header">
      <div>
        <p class="training-workspace__breadcrumb">人才发展 · 培训管理</p>
        <h1 class="training-workspace__title">培训工作台</h1>
        <p class="training-workspace__description">
          按培训项目查看交付方式、容量与实时报名人数。名额与完成状态全部来自服务端投影，
          页面不生成任何示例行；金额以整数最小单位返回，仅在此处格式化。
        </p>
      </div>
      <div class="training-workspace__header-actions">
        <button type="button" class="training-workspace__secondary" :disabled="loading" @click="load">
          刷新
        </button>
        <button
          v-if="canManage"
          type="button"
          class="training-workspace__primary"
          data-testid="training-program-create"
          @click="openCreate"
        >
          新建培训项目
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="training-workspace__state" role="alert" data-testid="training-forbidden">
      <h2>无权访问培训工作台</h2>
      <p>当前账号缺少 training.program.read 能力，页面不会发起任何培训项目读取请求。</p>
    </section>

    <template v-else>
      <p v-if="successMessage !== ''" class="training-workspace__success" role="status">
        {{ successMessage }}
      </p>
      <p v-if="unsupportedRoute !== ''" class="training-workspace__warning" role="alert" data-testid="training-unsupported-action">
        服务端尚未提供培训项目发布/关闭接口，未提交任何状态变更。缺少的路由：<strong>{{ unsupportedRoute }}</strong>。
        请联系后端补充受能力保护的命令接口后再试。
      </p>
      <p v-if="editNotice !== '' && editNoticeProgram !== null" class="training-workspace__warning" role="alert" data-testid="training-program-unsupported-edit">
        服务端尚未提供培训项目更新接口，无法保存对「{{ editNoticeProgram.title }}」的修改。
        期望路由：<strong>/v1/training/programs/{{ editNoticeProgram.id }}</strong>。
      </p>

      <ErrorState
        v-if="errorMessage !== ''"
        title="培训工作台暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载培训项目" />

      <EmptyState
        v-else-if="isEmpty"
        title="还没有培训项目"
        description="当前数据范围内没有培训项目。具备 training.program.manage 的账号可以新建第一个项目。"
      />

      <section v-else class="training-programs panel" aria-label="培训项目列表">
        <DataTable
          :columns="programColumns"
          :rows="programs as unknown as Array<Record<string, unknown>>"
          :total="programs.length"
          :page="1"
          :page-size="Math.max(1, programs.length)"
          row-key="id"
          :row-test-id="(row) => `training-program-row-${String(row.id)}`"
          data-testid="training-program-table"
          empty-title="还没有培训项目"
        >
          <template #cell-title="{ row }">
            <button type="button" class="training-programs__link" @click="openDetail(row as unknown as TrainingProgram)">
              {{ row.title }}
            </button>
            <span class="training-programs__meta">{{ row.code }} · {{ row.sessionCount }} 个场次</span>
          </template>
          <template #cell-category="{ row }">{{ row.category ?? '未分类' }}</template>
          <template #cell-deliveryMode="{ row }">{{ deliveryModeLabel(row.deliveryMode as DeliveryMode | null) }}</template>
          <template #cell-capacity="{ row }">
            <span class="training-programs__capacity">{{ row.enrolledCount }} / {{ row.capacity }}</span>
            <span class="training-programs__meta">
              {{ Number(row.remainingSeats) > 0 ? `剩余 ${row.remainingSeats} 个名额` : '名额已满' }}
            </span>
          </template>
          <template #cell-status="{ row }">
            <span class="training-programs__chip" :class="`is-${String(row.status).toLowerCase()}`">
              {{ programStatusLabel(row.status as ProgramStatus) }}
            </span>
          </template>
          <template #cell-cost="{ row }">
            <template v-if="row.trainingCostCents === undefined">已按字段权限隐藏</template>
            <template v-else>{{ formatMoney(Number(row.trainingCostCents)) }}</template>
          </template>
          <template #cell-actions="{ row }">
            <div class="training-programs__actions">
              <button type="button" class="training-programs__button" @click="openDetail(row as unknown as TrainingProgram)">
                查看详情
              </button>
              <template v-if="canManage">
                <button
                  type="button"
                  class="training-programs__button"
                  :data-testid="`training-program-edit-${String(row.id)}`"
                  @click="openEdit(row as unknown as TrainingProgram)"
                >
                  编辑
                </button>
                <button
                  v-if="row.status === DISPLAY_CODES.trainingProgramStatus.active"
                  type="button"
                  class="training-programs__button"
                  :data-testid="`training-program-publish-${String(row.id)}`"
                  @click="requestLifecycle('publish', row as unknown as TrainingProgram)"
                >
                  发布
                </button>
                <button
                  v-else
                  type="button"
                  class="training-programs__button is-danger"
                  :data-testid="`training-program-close-${String(row.id)}`"
                  @click="requestLifecycle('close', row as unknown as TrainingProgram)"
                >
                  关闭
                </button>
              </template>
            </div>
          </template>
        </DataTable>
      </section>

      <p v-if="!canManageEnrollments" class="training-workspace__hint" role="note">
        当前账号可以查看培训项目，但没有 training.enrollment.manage 能力，报名与完成操作不可用。
      </p>
    </template>

    <TrainingProgramDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :program="drawerProgram"
      :submitting="drawerSubmitting"
      :server-error="drawerError"
      @save="save"
    />

    <el-dialog
      v-model="confirmOpen"
      :title="pendingAction === 'publish' ? '发布培训项目' : '关闭培训项目'"
      width="460px"
      :close-on-click-modal="false"
      data-testid="training-program-confirm"
    >
      <p class="training-programs__confirm">
        确认{{ pendingAction === null ? '' : lifecycleLabel(pendingAction) }}培训项目
        「{{ pendingProgram?.title ?? '' }}」？此操作会改变该项目的可用状态。
      </p>
      <p class="training-programs__confirm-meta">
        该动作需要 training.program.manage 能力，服务端会再次校验。
      </p>
      <template #footer>
        <el-button data-testid="training-program-confirm-cancel" @click="cancelLifecycle">取消</el-button>
        <el-button type="primary" data-testid="training-program-confirm-submit" @click="confirmLifecycle">
          确认
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.training-workspace {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 20px 24px 48px;
}

.training-workspace__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.training-workspace__breadcrumb {
  margin: 0 0 4px;
  color: #64748b;
  font-size: 12px;
}

.training-workspace__title {
  margin: 0 0 6px;
  font-size: 22px;
  color: #0f172a;
}

.training-workspace__description {
  margin: 0;
  max-width: 760px;
  color: #475569;
  font-size: 12.5px;
  line-height: 1.65;
}

.training-workspace__header-actions {
  display: flex;
  gap: 8px;
}

.training-workspace__primary,
.training-workspace__secondary {
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 12.5px;
  cursor: pointer;
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #0f172a;
}

.training-workspace__primary {
  background: #1d4ed8;
  border-color: #1d4ed8;
  color: #fff;
}

.training-workspace__success,
.training-workspace__warning,
.training-workspace__hint {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 12.5px;
  line-height: 1.6;
}

.training-workspace__success {
  background: #ecfdf5;
  color: #166534;
}

.training-workspace__warning {
  background: #fffbeb;
  color: #92400e;
}

.training-workspace__hint {
  background: #f8fafc;
  color: #475569;
}

.training-workspace__state {
  padding: 24px;
  border: 1px solid #fecaca;
  border-radius: 12px;
  background: #fef2f2;
  color: #991b1b;
}

.training-programs table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.training-programs th,
.training-programs td {
  padding: 10px 12px;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
  vertical-align: top;
}

.training-programs th {
  background: #f8fafc;
  color: #64748b;
  font-weight: 600;
}

.training-programs__link {
  padding: 0;
  border: none;
  background: none;
  color: #1d4ed8;
  font-weight: 600;
  font-size: 13px;
  cursor: pointer;
}

.training-programs__meta {
  display: block;
  color: #94a3b8;
  font-size: 11px;
  margin-top: 2px;
}

.training-programs__capacity {
  font-weight: 600;
}

.training-programs__chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  background: #dcfce7;
  color: #166534;
}

.training-programs__chip.is-archived {
  background: #f1f5f9;
  color: #475569;
}

.training-programs__actions {
  min-width: 210px;
}

.training-programs__button {
  margin-right: 6px;
  margin-bottom: 4px;
  padding: 4px 9px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  font-size: 11.5px;
  cursor: pointer;
}

.training-programs__button.is-danger {
  color: #b91c1c;
  border-color: #fecaca;
}

.training-programs__confirm {
  margin: 0 0 8px;
  font-size: 13px;
  line-height: 1.6;
}

.training-programs__confirm-meta {
  margin: 0;
  color: #64748b;
  font-size: 12px;
}
</style>
