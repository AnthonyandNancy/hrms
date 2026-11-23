<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getApi } from '@/api'
import type { ApplicationStatus, CaseAction, CaseDetail, CaseSummary } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { APPLICATION_STATUS } from '@/utils/status'
import { formatDateTimeInTimezone } from '@/utils/format'
import { validate, decisionSchema } from '@/utils/validation'
import StatusBadge from '@/components/StatusBadge.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'

const router = useRouter()
const session = useSessionStore()

const scope = ref<'mine' | 'approvals' | 'all'>('approvals')
const status = ref<ApplicationStatus | 'ALL'>('ALL')
const loading = ref(false)
const error = ref('')
const rows = ref<CaseSummary[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(10)

const scopes = computed(() =>
  [
    { key: 'approvals' as const, label: '我的待办', count: 3 },
    { key: 'mine' as const, label: '我发起的', count: 2 },
    { key: 'all' as const, label: '全部案例', count: 7, capability: 'workflow.case.view.all' },
  ].filter((item) => !item.capability || session.can(item.capability)),
)

const statusTabs: Array<{ key: ApplicationStatus | 'ALL'; label: string }> = [
  { key: 'ALL', label: '全部申请状态' },
  ...Object.entries(APPLICATION_STATUS).map(([key, value]) => ({ key: key as ApplicationStatus, label: value.label })),
]

const drawerOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detail = ref<CaseDetail | null>(null)
const decisionOpen = ref(false)
const decision = reactive<{ action: Extract<CaseAction, 'APPROVE' | 'REJECT' | 'RETURN'>; comment: string }>({
  action: 'APPROVE',
  comment: '',
})
const decisionErrors = ref<Record<string, string>>({})
const submitting = ref(false)
const retrying = ref(false)

const visibleActions = computed(() =>
  detail.value ? session.caseActions(detail.value.availableActions) : [],
)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const result = await getApi().cases.listCases({ scope: scope.value, status: status.value, page: page.value, pageSize: pageSize.value })
    rows.value = result.items
    total.value = result.total
  } catch (reason) {
    error.value = errorCopy(reason)
    rows.value = []
  } finally {
    loading.value = false
  }
}

watch(scope, () => {
  page.value = 1
  void load()
})
watch(status, () => {
  page.value = 1
  void load()
})
watch(page, () => void load())

async function openDetail(id: number): Promise<void> {
  drawerOpen.value = true
  detailLoading.value = true
  detailError.value = ''
  detail.value = null
  try {
    detail.value = await getApi().cases.getCase(id)
  } catch (reason) {
    detailError.value = errorCopy(reason)
  } finally {
    detailLoading.value = false
  }
}

function openDecision(action: 'APPROVE' | 'REJECT' | 'RETURN'): void {
  decision.action = action
  decision.comment = ''
  decisionErrors.value = {}
  decisionOpen.value = true
}

async function submitDecision(): Promise<void> {
  if (!detail.value) return
  const parsed = validate(decisionSchema, decision)
  decisionErrors.value = {}
  if (!parsed.success) {
    for (const issue of parsed.issues) decisionErrors.value[issue.path] = issue.message
    return
  }
  submitting.value = true
  try {
    detail.value = await getApi().cases.decideCase(detail.value.id, {
      action: parsed.data!.action,
      comment: parsed.data!.comment,
    })
    decisionOpen.value = false
    ElMessage.success(decision.action === 'APPROVE' ? '已通过申请；请同时查看生效状态。' : '决定已记录。')
    await load()
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    submitting.value = false
  }
}

async function retryEffect(): Promise<void> {
  if (!detail.value) return
  try {
    await ElMessageBox.confirm(
      '重试会以幂等键重新执行业务效果；不会重复扣减或生成第二条生效记录。确认重试？',
      '重试生效任务',
      { confirmButtonText: '确认重试', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  retrying.value = true
  try {
    detail.value = await getApi().cases.retryCaseEffect(detail.value.id)
    ElMessage.success('生效任务重试完成，已刷新生效状态。')
    await load()
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    retrying.value = false
  }
}

function goToCasePreview(): void {
  if (detail.value) void router.push(`/cases/${detail.value.id}`)
}

onMounted(load)
</script>

<template>
  <div class="page cases-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">协作</p>
        <h1 class="page-header__title">审批中心</h1>
        <p class="page-header__description">
          申请状态回答“流程走到哪里”，生效状态回答“业务事实是否已改变”。两者分开显示，不合并为一个“已完成”。
        </p>
      </div>
      <div class="page-header__actions">
        <span class="plain-chip">最长等待：1 天</span>
      </div>
    </header>

    <section class="panel">
      <div class="case-scopes" role="tablist" aria-label="审批范围">
        <button
          v-for="item in scopes"
          :key="item.key"
          type="button"
          role="tab"
          class="case-scope"
          :class="{ 'case-scope--active': scope === item.key }"
          :aria-selected="scope === item.key"
          @click="scope = item.key"
        >
          {{ item.label }}
          <span v-if="item.count" class="case-scope__count">{{ item.count }}</span>
        </button>
      </div>

      <div class="case-status-tabs" role="group" aria-label="按申请状态筛选">
        <button
          v-for="tab in statusTabs"
          :key="tab.key"
          type="button"
          class="case-status-tab"
          :class="{ 'case-status-tab--active': status === tab.key }"
          :aria-pressed="status === tab.key"
          @click="status = tab.key"
        >
          {{ tab.label }}
        </button>
      </div>

      <ErrorState v-if="error" description="审批列表暂时无法加载。" :code="error" @retry="load" />
      <LoadingState v-else-if="loading" :rows="5" label="正在加载审批案例" />
      <EmptyState
        v-else-if="rows.length === 0"
        title="当前范围内没有审批案例"
        description="你可以切换范围或申请状态；新提交的申请会进入“我发起的”。"
      />
      <div v-else class="case-table-wrap">
        <table class="case-table">
          <caption class="visually-hidden">审批案例列表</caption>
          <thead>
            <tr>
              <th scope="col">申请</th>
              <th scope="col">申请人 / 组织</th>
              <th scope="col">提交时间</th>
              <th scope="col">申请状态</th>
              <th scope="col">生效状态</th>
              <th scope="col">当前节点</th>
              <th scope="col"><span class="visually-hidden">操作</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in rows" :key="item.id" class="case-row" @click="openDetail(item.id)">
              <td>
                <button type="button" class="case-title-link" @click.stop="openDetail(item.id)">
                  {{ item.title }}
                  <span class="case-no">{{ item.caseNo }}</span>
                </button>
              </td>
              <td>
                <span class="case-person">{{ item.applicantName }}</span>
                <span class="case-org">{{ item.applicantOrgName }}</span>
              </td>
              <td class="tabular small">{{ formatDateTimeInTimezone(item.appliedAt) }}</td>
              <td><StatusBadge kind="application" :status="item.applicationStatus" size="sm" /></td>
              <td><StatusBadge kind="effect" :status="item.effectStatus" size="sm" /></td>
              <td class="small">{{ item.currentNode ?? '—' }}</td>
              <td>
                <el-button text size="small" @click.stop="openDetail(item.id)">查看</el-button>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="case-pagination">
          <span class="small muted">共 {{ total }} 条</span>
          <el-pagination
            v-model:current-page="page"
            v-model:page-size="pageSize"
            :total="total"
            layout="prev, pager, next"
            small
            background
          />
        </div>
      </div>
    </section>

    <el-drawer v-model="drawerOpen" size="min(680px, 96vw)" title="案例详情">
      <div class="drawer-body">
        <LoadingState v-if="detailLoading" :rows="8" label="正在加载案例详情" />
        <ErrorState v-else-if="detailError" title="无法打开案例" :description="detailError" @retry="detail && openDetail(detail.id)" />
        <template v-else-if="detail">
          <div class="case-detail-heading">
            <div>
              <p class="muted small">{{ detail.caseNo }} · {{ detail.type }}</p>
              <h2>{{ detail.title }}</h2>
              <p class="small">{{ detail.applicant }} · 提交于 {{ formatDateTimeInTimezone(detail.appliedAt) }}</p>
            </div>
            <div class="status-pair">
              <StatusBadge kind="application" :status="detail.applicationStatus" show-label-prefix />
              <StatusBadge kind="effect" :status="detail.effectStatus" show-label-prefix />
            </div>
          </div>

          <el-alert
            v-if="detail.effectStatus === 'FAILED'"
            type="error"
            :closable="false"
            show-icon
            title="审批已通过，业务生效失败"
          >
            <p class="small">{{ detail.effectFailedReason }}</p>
            <p class="small">原数据未被修改；管理员可幂等重试生效任务。</p>
          </el-alert>
          <el-alert
            v-else-if="detail.effectStatus === 'SCHEDULED'"
            type="info"
            :closable="false"
            show-icon
            title="审批已通过，尚未到生效时间"
          >
            <p class="small">{{ detail.effectSummary }}</p>
          </el-alert>

          <section class="drawer-section">
            <h3 class="section-title">申请内容</h3>
            <p class="case-description">{{ detail.description }}</p>
            <dl class="facts">
              <template v-for="fact in detail.facts" :key="fact.label">
                <dt>{{ fact.label }}</dt>
                <dd>{{ fact.value }}</dd>
              </template>
            </dl>
          </section>

          <section class="drawer-section">
            <h3 class="section-title">申请状态与生效状态</h3>
            <div class="dual-status">
              <div class="dual-status__lane">
                <span class="dual-status__label">申请状态</span>
                <StatusBadge kind="application" :status="detail.applicationStatus" />
                <p>审批人：{{ detail.approver ?? '—' }}</p>
              </div>
              <div class="dual-status__lane dual-status__lane--effect">
                <span class="dual-status__label">生效状态</span>
                <StatusBadge kind="effect" :status="detail.effectStatus" />
                <p>{{ detail.effectSummary }}</p>
                <p v-if="detail.effectScheduledAt" class="small muted">
                  计划生效：{{ formatDateTimeInTimezone(detail.effectScheduledAt) }}
                </p>
              </div>
            </div>
          </section>

          <section class="drawer-section">
            <h3 class="section-title">时间线</h3>
            <p class="section-subtitle">提交、决策、生效与重试记录按时间排列。</p>
            <Timeline :events="detail.timeline" />
          </section>

          <section v-if="visibleActions.length" class="case-actions">
            <template v-for="action in visibleActions" :key="action">
              <el-button
                v-if="action === 'APPROVE'"
                type="primary"
                @click="openDecision('APPROVE')"
              >
                同意
              </el-button>
              <el-button
                v-else-if="action === 'REJECT'"
                type="danger"
                plain
                @click="openDecision('REJECT')"
              >
                驳回
              </el-button>
              <el-button
                v-else-if="action === 'RETURN'"
                @click="openDecision('RETURN')"
              >
                退回补充
              </el-button>
              <el-button
                v-else-if="action === 'RETRY_EFFECT'"
                type="warning"
                :loading="retrying"
                @click="retryEffect"
              >
                重试生效
              </el-button>
            </template>
            <el-button text @click="goToCasePreview">打开完整案例页</el-button>
          </section>
          <section v-else class="case-actions case-actions--note">
            <p class="small muted">当前身份与案例状态下没有可执行操作。能力隐藏只是 UI 辅助，后端仍会独立校验授权。</p>
          </section>
        </template>
      </div>
    </el-drawer>

    <el-dialog v-model="decisionOpen" :title="decision.action === 'APPROVE' ? '同意申请' : decision.action === 'REJECT' ? '驳回申请' : '退回补充材料'" width="500px">
      <div class="stack">
        <p class="small">
          {{ decision.action === 'APPROVE' ? '通过后会产生业务效果；请同时查看生效状态，不要向申请人承诺“已完成”。' : '决定会作为不可变证据记录，并保留在案例时间线中。' }}
        </p>
        <label class="field-label" for="decision-comment">
          审批意见
          <span v-if="decision.action !== 'APPROVE'" class="required">（必填）</span>
        </label>
        <el-input
          id="decision-comment"
          v-model="decision.comment"
          type="textarea"
          :rows="4"
          maxlength="500"
          show-word-limit
          placeholder="填写依据、补充要求或拒绝原因"
          :aria-invalid="Boolean(decisionErrors.comment)"
        />
        <p v-if="decisionErrors.comment" class="field-error">{{ decisionErrors.comment }}</p>
      </div>
      <template #footer>
        <el-button @click="decisionOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitDecision">确认提交决定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.case-scopes {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  padding: 10px 16px 0;
  border-bottom: 1px solid var(--color-line);
}

.case-scope {
  position: relative;
  padding: 9px 14px 11px;
  color: var(--color-ink-muted);
  font-size: 13px;
  font-weight: 600;
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  cursor: pointer;
}

.case-scope--active {
  color: var(--color-brand);
  border-bottom-color: var(--color-brand);
}

.case-scope__count {
  margin-left: 5px;
  padding: 0 6px;
  font-size: 10px;
  background: var(--color-brand-soft);
  border-radius: 8px;
}

.case-status-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  padding: 11px 16px;
  background: #fbfcfb;
  border-bottom: 1px solid var(--color-line);
}

.case-status-tab {
  padding: 3px 9px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 999px;
  cursor: pointer;
}

.case-status-tab--active {
  color: var(--color-brand);
  background: #fff;
  border-color: var(--color-line-strong);
}

.case-table-wrap {
  overflow-x: auto;
}

.case-table {
  width: 100%;
  min-width: 900px;
  border-collapse: collapse;
  font-size: 12px;
}

.case-table th {
  padding: 9px 14px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  letter-spacing: 0.04em;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.case-table td {
  padding: 11px 14px;
  vertical-align: middle;
  border-bottom: 1px solid #edf1ef;
}

.case-row {
  cursor: pointer;
}

.case-row:hover td {
  background: #fafcfb;
}

.case-title-link {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 0;
  color: var(--color-ink);
  font-size: 12.5px;
  font-weight: 600;
  text-align: left;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.case-title-link:hover {
  color: var(--color-brand);
  text-decoration: underline;
}

.case-no {
  color: var(--color-ink-muted);
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 9.5px;
  font-weight: 400;
}

.case-person,
.case-org {
  display: block;
}

.case-person {
  font-weight: 600;
}

.case-org {
  color: var(--color-ink-muted);
  font-size: 10px;
}

.case-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 16px;
}

.drawer-body {
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding: 20px 22px 34px;
}

.case-detail-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
}

.case-detail-heading h2 {
  margin: 3px 0;
  font-size: 19px;
  font-weight: 680;
  letter-spacing: -0.02em;
}

.case-detail-heading .status-pair {
  flex-direction: column;
  align-items: flex-end;
}

.drawer-section {
  padding-bottom: 16px;
  border-bottom: 1px solid var(--color-line);
}

.case-description {
  margin: 8px 0 12px;
  color: var(--color-ink-secondary);
  font-size: 12.5px;
  line-height: 1.7;
}

.dual-status {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-top: 9px;
}

.dual-status__lane {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  padding: 12px;
  background: var(--color-info-soft);
  border: 1px solid #ccdfeb;
  border-radius: 6px;
}

.dual-status__lane--effect {
  background: #fbf7ec;
  border-color: #ebdab3;
}

.dual-status__label {
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.dual-status__lane p {
  color: var(--color-ink-secondary);
  font-size: 11.5px;
  line-height: 1.55;
}

.case-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding-top: 4px;
}

.case-actions--note {
  padding: 10px 12px;
  background: var(--color-canvas);
  border-radius: 6px;
}

.field-label {
  font-size: 12px;
  font-weight: 600;
}

.required {
  color: var(--color-danger);
  font-weight: 500;
}

.field-error {
  color: var(--color-danger);
  font-size: 11.5px;
}

@media (max-width: 640px) {
  .dual-status {
    grid-template-columns: minmax(0, 1fr);
  }

  .case-detail-heading {
    flex-direction: column;
  }

  .case-detail-heading .status-pair {
    flex-direction: row;
    align-items: flex-start;
  }
}
</style>
