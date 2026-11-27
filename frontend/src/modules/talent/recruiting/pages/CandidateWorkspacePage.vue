<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCopy } from '@/api/errors'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { requestIdFromError } from '@/utils/renderErrors'
import CandidatePipeline from '../components/CandidatePipeline.vue'
import CandidateImportDrawer from '../components/CandidateImportDrawer.vue'
import {
  isForbidden,
  listCandidates,
  listRequisitions,
  moveCandidateStage,
  serverReason,
  stageForStatus,
  stageLabel,
  type Candidate,
  type Requisition,
} from '../api'

defineOptions({ name: 'CandidateWorkspacePage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const canRead = computed(() => session.can('recruitment.candidate.read'))
const canManage = computed(() => session.can('recruitment.candidate.manage'))
const canReadRequisitions = computed(() => session.can('recruitment.requisition.read'))
const canImport = computed(() => session.can('recruitment.candidate.import'))

const CANDIDATE_LIST_COLUMNS: DataTableColumn[] = [
  { key: 'displayName', label: '候选人', minWidth: '160px' },
  { key: 'statusLabel', label: '阶段', width: '120px' },
  { key: 'requisitionLabel', label: '关联需求', width: '130px' },
  { key: 'source', label: '来源', width: '120px' },
  { key: 'contactLabel', label: '联系方式', minWidth: '180px' },
]

const candidateListRows = computed(() => visibleCandidates.value.map((row) => ({
  ...row,
  requisitionLabel: row.requisitionId === null ? '未关联' : `需求 #${row.requisitionId}`,
  source: row.source ?? '未登记',
  contactLabel: `${row.hasEmail ? '邮箱已登记' : '无邮箱'} · ${row.hasMobile ? '手机已登记' : '无手机'}`,
})))

const state = ref<LoadState>('idle')
const candidates = ref<Candidate[]>([])
const requisitions = ref<Requisition[]>([])
const requisitionLookupError = ref('')
const pageError = ref<unknown>(null)
const requisitionFilter = ref('')
const search = ref('')
const movingId = ref<number | null>(null)
const viewMode = ref<'list' | 'pipeline'>('pipeline')
const talentPoolOnly = ref(false)
const importOpen = ref(false)

const moveTarget = ref<Candidate | null>(null)
const moveStatus = ref('')
const moveReason = ref('')
const moving = ref(false)
const moveError = ref('')

let requestSequence = 0

const loading = computed(() => state.value === 'idle' || state.value === 'loading')
const forbidden = computed(() => !canRead.value || state.value === 'forbidden')
const isEmpty = computed(() => state.value === 'ready' && candidates.value.length === 0)
const visibleCandidates = computed(() =>
  viewMode.value === 'pipeline' ? candidates.value : candidates.value,
)
const partial = computed(() => requisitionLookupError.value !== '' && state.value === 'ready')
const errorMessage = computed(() => {
  if (pageError.value === null) return '候选人看板暂时无法加载。'
  if (pageError.value instanceof ApiError && pageError.value.message !== '') return pageError.value.message
  return errorCopy(pageError.value)
})
const errorRequestId = computed(() =>
  pageError.value === null ? null : requestIdFromError(pageError.value),
)
const dialogStatusLabel = computed(() => stageLabel(moveStatus.value) || moveStatus.value)

async function load(): Promise<void> {
  if (!canRead.value) {
    state.value = 'forbidden'
    return
  }

  const sequence = ++requestSequence
  state.value = 'loading'
  pageError.value = null
  requisitionLookupError.value = ''

  const requisitionRead = canReadRequisitions.value
    ? listRequisitions({}, 1, 100)
    : Promise.reject(new ApiError('FORBIDDEN', '缺少招聘需求读取能力', { status: 403 }))

  const [requisitionPage, candidatePage] = await Promise.allSettled([
    requisitionRead,
    listCandidates({
      requisitionId: requisitionFilter.value === '' ? null : Number(requisitionFilter.value),
      search: search.value,
      talentPool: talentPoolOnly.value,
    }),
  ])

  if (sequence !== requestSequence) return

  if (requisitionPage.status === 'fulfilled') {
    requisitions.value = requisitionPage.value.items
  } else {
    requisitions.value = []
    requisitionLookupError.value = canReadRequisitions.value
      ? '部分数据：招聘需求目录加载失败，按需求筛选暂不可用。'
      : '部分数据：缺少 recruitment.requisition.read，按需求筛选暂不可用。'
  }

  if (candidatePage.status === 'rejected') {
    candidates.value = []
    pageError.value = candidatePage.reason
    state.value = isForbidden(candidatePage.reason) ? 'forbidden' : 'error'
    return
  }

  candidates.value = candidatePage.value.items
  state.value = 'ready'
}

function applyFilters(): void {
  void load()
}

function askMove(candidate: Candidate, status: string): void {
  if (!canManage.value) return
  moveTarget.value = candidate
  moveStatus.value = status
  moveReason.value = ''
  moveError.value = ''
}

function closeMoveDialog(): void {
  if (moving.value) return
  moveTarget.value = null
  moveStatus.value = ''
  moveReason.value = ''
  moveError.value = ''
}

function chineseMoveError(error: unknown): string {
  const reason = serverReason(error)
  switch (reason) {
    case 'CANDIDATE_STAGE_CONFLICT':
      return '候选人阶段已变化，请刷新后重新确认。'
    case 'CANDIDATE_ALREADY_HIRED':
      return '该候选人已入职，不能退回招聘流程。'
    case 'INVALID_CANDIDATE_STAGE':
      return '该阶段不能手工移动；已入职由录用转入职动作产生。'
    case 'IDEMPOTENCY_PAYLOAD_MISMATCH':
      return '本次移动与之前的幂等请求不一致，请关闭后重新发起。'
    default:
      if (error instanceof ApiError && error.code === 'FORBIDDEN') {
        return '当前账号没有移动候选人的能力（recruitment.candidate.manage）。'
      }
      return error instanceof ApiError && error.message !== ''
        ? error.message
        : '候选人阶段移动未完成，请重试。'
  }
}

async function confirmMove(): Promise<void> {
  if (moveTarget.value === null) return

  const target = moveTarget.value
  moving.value = true
  movingId.value = target.id
  moveError.value = ''

  try {
    const moved = await moveCandidateStage(target.id, moveStatus.value, moveReason.value)
    candidates.value = candidates.value.map((candidate) =>
      candidate.id === target.id
        ? {
            ...candidate,
            status: moved.status as Candidate['status'],
            statusLabel: stageLabel(moved.status),
            stage: stageForStatus(moved.status),
          }
        : candidate,
    )
    moveTarget.value = null
    moveReason.value = ''
  } catch (error) {
    moveError.value = chineseMoveError(error)
  } finally {
    moving.value = false
    movingId.value = null
  }
}

function applyViewMode(value: string | null): void {
  viewMode.value = value === 'list' ? 'list' : 'pipeline'
}

function setViewMode(value: 'list' | 'pipeline'): void {
  viewMode.value = value
  void router.replace({ query: { ...route.query, view: value } })
}

function openImport(): void {
  if (!canImport.value) return
  importOpen.value = true
}

function onImported(): void {
  void load()
}

watch(
  () => route.query.view,
  (value) => applyViewMode(typeof value === 'string' ? value : null),
  { immediate: true },
)

onMounted(load)
onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <PageShell
    title="候选人管道"
    description="按真实招聘阶段查看候选人；阶段移动需二次确认并携带幂等键，已入职由录用转入职动作产生。"
  >
    <template #actions>
      <div class="candidate-workspace__views" role="group" aria-label="候选人视图">
        <button
          type="button"
          class="candidate-workspace__secondary"
          :class="{ 'candidate-workspace__secondary--active': viewMode === 'pipeline' }"
          data-testid="candidate-view-pipeline"
          @click="setViewMode('pipeline')"
        >
          流程看板
        </button>
        <button
          type="button"
          class="candidate-workspace__secondary"
          :class="{ 'candidate-workspace__secondary--active': viewMode === 'list' }"
          data-testid="candidate-view-list"
          @click="setViewMode('list')"
        >
          列表
        </button>
      </div>
      <button
        v-if="canImport"
        type="button"
        class="candidate-workspace__primary"
        data-testid="candidate-import-open"
        @click="openImport"
      >
        导入简历
      </button>
      <button type="button" class="candidate-workspace__secondary" :disabled="loading" @click="load">刷新</button>
    </template>

    <section v-if="forbidden" class="candidate-workspace__forbidden" role="alert" data-testid="candidates-forbidden">
      <h2>无权查看候选人</h2>
      <p>当前账号缺少 recruitment.candidate.read，页面不会发起任何候选人读取请求。</p>
    </section>

    <LoadingState v-else-if="loading" :rows="6" label="正在加载候选人" />

    <ErrorState
      v-else-if="state === 'error'"
      title="候选人看板暂时无法加载"
      :description="errorMessage"
      :request-id="errorRequestId"
      @retry="load"
    />

    <template v-else>
      <p v-if="partial" class="candidate-workspace__warning" role="status" data-testid="candidates-partial">
        {{ requisitionLookupError }}
      </p>

      <section class="candidate-workspace__filters" aria-label="候选人筛选">
        <label>
          <span>招聘需求</span>
          <el-select
            v-model="requisitionFilter"
            data-testid="candidate-requisition-filter"
            :disabled="!canReadRequisitions"
            @change="applyFilters"
          >
            <el-option value="" label="全部需求" />
            <el-option
              v-for="requisition in requisitions"
              :key="requisition.id"
              :value="String(requisition.id)"
              :label="`${requisition.requisitionNo} · ${requisition.title}`"
            />
          </el-select>
        </label>
        <label>
          <span>搜索</span>
          <el-input
            v-model="search"
            data-testid="candidate-search"
            placeholder="候选人姓名"
            @keyup.enter="applyFilters"
          />
        </label>
        <label class="candidate-workspace__checkbox">
          <el-checkbox v-model="talentPoolOnly" data-testid="candidate-talent-pool-filter" @change="applyFilters">只看人才库</el-checkbox>
          <span>仅看人才库</span>
        </label>
        <button type="button" class="candidate-workspace__secondary" @click="applyFilters">查询</button>
      </section>

      <EmptyState
        v-if="isEmpty"
        title="还没有候选人"
        description="候选人由导入或新增动作进入管道；本页不会生成演示候选人。"
      />

      <CandidatePipeline
        v-else-if="viewMode === 'pipeline'"
        :candidates="visibleCandidates"
        :can-move="canManage"
        :moving-id="movingId"
        @move="askMove"
      />

      <section v-else class="candidate-workspace__list" data-testid="candidate-list-view">
        <DataTable
          :columns="CANDIDATE_LIST_COLUMNS"
          :rows="candidateListRows as unknown as Array<Record<string, unknown>>"
          :total="candidateListRows.length"
          :page="1"
          :page-size="Math.max(1, candidateListRows.length)"
          row-key="id"
          data-testid="candidate-list-table"
          empty-title="当前筛选下没有候选人"
        >
          <template #cell-displayName="{ row }">
            <router-link :to="`/recruitment/candidates/${row.id}`">{{ row.displayName }}</router-link>
          </template>
        </DataTable>
      </section>

      <p v-if="canManage" class="candidate-workspace__note">
        移动阶段会记录审计；“已入职”不能手工设置，必须通过候选人详情的“录用转入职”完成。
      </p>
    </template>

    <CandidateImportDrawer
      v-model="importOpen"
      :requisitions="requisitions"
      :can-import="canImport"
      @imported="onImported"
    />

    <div v-if="moveTarget !== null" class="candidate-workspace__modal" data-testid="candidate-stage-dialog">
      <section role="dialog" aria-modal="true" aria-labelledby="candidate-stage-title">
        <h2 id="candidate-stage-title">移动候选人阶段</h2>
        <p>
          将“{{ moveTarget.displayName }}”从“{{ moveTarget.statusLabel }}”移动到“{{ dialogStatusLabel }}”？
          该动作会写入审计，并从服务端重新读取管道。
        </p>
        <label>
          <span>移动原因（可选）</span>
          <el-input v-model="moveReason" type="textarea" :rows="2" maxlength="500" placeholder="例如：初筛通过" />
        </label>
        <p v-if="moveError !== ''" class="candidate-workspace__error" role="alert">{{ moveError }}</p>
        <footer>
          <button type="button" class="candidate-workspace__secondary" :disabled="moving" @click="closeMoveDialog">取消</button>
          <button
            type="button"
            class="candidate-workspace__primary"
            :disabled="moving"
            data-testid="candidate-stage-confirm"
            @click="confirmMove"
          >
            {{ moving ? '移动中…' : '确认移动' }}
          </button>
        </footer>
      </section>
    </div>
  </PageShell>
</template>

<style scoped>
.candidate-workspace__primary,
.candidate-workspace__secondary {
  height: 34px;
  padding: 0 14px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  color: #334155;
  cursor: pointer;
}

.candidate-workspace__primary {
  border-color: #1d4ed8;
  background: #1d4ed8;
  color: #fff;
}

.candidate-workspace__forbidden {
  padding: 26px;
  border: 1px solid #fecaca;
  border-radius: 12px;
  background: #fef2f2;
  color: #7f1d1d;
}

.candidate-workspace__forbidden h2 {
  margin: 0 0 8px;
  font-size: 17px;
}

.candidate-workspace__warning {
  margin: 0;
  padding: 10px 12px;
  border: 1px solid #fed7aa;
  border-radius: 8px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 12.5px;
}

.candidate-workspace__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
  padding: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #f8fafc;
}

.candidate-workspace__filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: #475569;
  font-size: 12px;
}

.candidate-workspace__filters select,
.candidate-workspace__filters input {
  height: 32px;
  padding: 0 10px;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  background: #fff;
  font: inherit;
}

.candidate-workspace__note {
  margin: 0;
  color: #64748b;
  font-size: 12px;
}

.candidate-workspace__modal {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: grid;
  place-items: center;
  background: rgb(15 23 42 / 42%);
}

.candidate-workspace__modal section {
  width: min(440px, 92vw);
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 22px;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 24px 60px rgb(15 23 42 / 25%);
}

.candidate-workspace__modal h2 {
  margin: 0;
  font-size: 17px;
}

.candidate-workspace__modal p {
  margin: 0;
  color: #475569;
  font-size: 12.5px;
  line-height: 1.7;
}

.candidate-workspace__modal label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  color: #334155;
  font-size: 12.5px;
}

.candidate-workspace__modal textarea {
  padding: 8px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font: inherit;
}

.candidate-workspace__modal footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.candidate-workspace__error {
  margin: 0;
  color: #b91c1c;
  font-size: 12.5px;
}
.candidate-workspace__views { display: inline-flex; gap: 6px; }
.candidate-workspace__secondary--active { border-color: #1d4ed8; color: #1d4ed8; background: #eff6ff; }
.candidate-workspace__checkbox { flex-direction: row !important; align-items: center; gap: 6px !important; height: 32px; }
.candidate-workspace__checkbox input { width: 14px; height: 14px; }
.candidate-workspace__list { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.candidate-workspace__list table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.candidate-workspace__list th, .candidate-workspace__list td { padding: 9px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
.candidate-workspace__list a { color: #1d4ed8; text-decoration: none; }
</style>
