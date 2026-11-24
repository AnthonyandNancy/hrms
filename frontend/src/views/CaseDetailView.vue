<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getApi } from '@/api'
import type { CaseAction, CaseDetail } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone, formatBusinessDate, todayBusinessDate } from '@/utils/format'
import { decisionSchema, validate } from '@/utils/validation'
import StatusBadge from '@/components/StatusBadge.vue'
import Timeline from '@/components/Timeline.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'

const props = defineProps<{ id: string }>()
const router = useRouter()
const session = useSessionStore()

const loading = ref(true)
const error = ref('')
const detail = ref<CaseDetail | null>(null)
const dialogOpen = ref(false)
const submitting = ref(false)
const retrying = ref(false)
const decision = reactive<{ action: Extract<CaseAction, 'APPROVE' | 'REJECT' | 'RETURN'>; comment: string }>({
  action: 'APPROVE',
  comment: '',
})
const decisionErrors = ref<Record<string, string>>({})

const actions = computed(() => (detail.value ? session.caseActions(detail.value.availableActions) : []))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    detail.value = await getApi().cases.getCase(Number(props.id))
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

function openDialog(action: 'APPROVE' | 'REJECT' | 'RETURN'): void {
  decision.action = action
  decision.comment = ''
  decisionErrors.value = {}
  dialogOpen.value = true
}

async function submit(): Promise<void> {
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
    dialogOpen.value = false
    ElMessage.success('审批决定已记录。申请状态与生效状态已分别更新。')
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    submitting.value = false
  }
}

async function retry(): Promise<void> {
  if (!detail.value) return
  try {
    await ElMessageBox.confirm(
      '系统会以幂等键重新执行该案例的业务效果，不会重复扣减。确认重试？',
      '重试业务生效',
      { confirmButtonText: '重试', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  retrying.value = true
  try {
    detail.value = await getApi().cases.retryCaseEffect(detail.value.id)
    ElMessage.success('生效任务已重试，当前生效状态已刷新。')
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    retrying.value = false
  }
}

function back(): void {
  void router.push('/cases')
}

onMounted(load)
</script>

<template>
  <div class="page case-detail-page">
    <div class="row">
      <el-button text @click="back">← 返回审批中心</el-button>
    </div>

    <LoadingState v-if="loading" :rows="9" label="正在加载案例" />
    <ErrorState v-else-if="error" title="无法打开案例" :description="error" @retry="load" />

    <template v-else-if="detail">
      <header class="case-hero">
        <div class="case-hero__main">
          <p class="case-hero__meta">{{ detail.caseNo }} · {{ detail.type }}</p>
          <h1>{{ detail.title }}</h1>
          <p class="case-hero__sub">
            申请人 {{ detail.applicant }} · 提交于 {{ formatDateTimeInTimezone(detail.appliedAt) }}
          </p>
        </div>
        <div class="case-hero__status">
          <div class="case-hero__status-item">
            <span>申请状态</span>
            <StatusBadge kind="application" :status="detail.applicationStatus" />
          </div>
          <span class="case-hero__arrow" aria-hidden="true">+</span>
          <div class="case-hero__status-item">
            <span>生效状态</span>
            <StatusBadge kind="effect" :status="detail.effectStatus" />
          </div>
        </div>
      </header>

      <div class="case-detail-grid">
        <div class="stack">
          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">申请内容</h2>
                <p class="panel__subtitle">提交时的事实快照，审批后不可由申请人直接改写。</p>
              </div>
            </div>
            <div class="panel__body">
              <p class="case-description">{{ detail.description }}</p>
              <dl class="facts">
                <template v-for="fact in detail.facts" :key="fact.label">
                  <dt>{{ fact.label }}</dt>
                  <dd>{{ fact.value }}</dd>
                </template>
              </dl>
            </div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">时间线</h2>
                <p class="panel__subtitle">申请、决定、生效、通知和更正按发生时间排列。</p>
              </div>
            </div>
            <div class="panel__body">
              <Timeline :events="detail.timeline" />
            </div>
          </section>
        </div>

        <div class="stack">
          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">双状态核对</h2>
                <p class="panel__subtitle">审批通过不等于业务生效</p>
              </div>
            </div>
            <div class="panel__body stack">
              <div class="status-block status-block--application">
                <div class="status-block__heading">
                  <span class="status-block__eyebrow">申请状态</span>
                  <StatusBadge kind="application" :status="detail.applicationStatus" />
                </div>
                <p>审批人：{{ detail.approver ?? '—' }}</p>
                <p v-if="detail.currentNode">当前节点：{{ detail.currentNode }}</p>
                <p v-if="detail.dueAt">审批承诺：{{ formatDateTimeInTimezone(detail.dueAt) }}</p>
              </div>
              <div class="status-block status-block--effect">
                <div class="status-block__heading">
                  <span class="status-block__eyebrow">生效状态</span>
                  <StatusBadge kind="effect" :status="detail.effectStatus" />
                </div>
                <p>{{ detail.effectSummary }}</p>
                <p v-if="detail.effectScheduledAt">
                  计划生效：{{ formatDateTimeInTimezone(detail.effectScheduledAt) }}
                </p>
                <template v-if="detail.effectStatus === 'FAILED'">
                  <p class="field-error">{{ detail.effectFailedReason }}</p>
                  <el-button v-if="actions.includes('RETRY_EFFECT')" type="warning" size="small" :loading="retrying" @click="retry">
                    重试生效
                  </el-button>
                </template>
              </div>
            </div>
          </section>

          <section v-if="actions.some((action) => action !== 'RETRY_EFFECT')" class="panel">
            <div class="panel__header">
              <h2 class="panel__title">审批操作</h2>
            </div>
            <div class="panel__body stack--tight">
              <el-button v-if="actions.includes('APPROVE')" type="primary" @click="openDialog('APPROVE')">
                同意
              </el-button>
              <el-button v-if="actions.includes('RETURN')" @click="openDialog('RETURN')">
                退回补充材料
              </el-button>
              <el-button v-if="actions.includes('REJECT')" type="danger" plain @click="openDialog('REJECT')">
                驳回
              </el-button>
              <p class="small muted">提交操作需要 Idempotency-Key；重复点击不会生成两条决定。</p>
            </div>
          </section>

          <section class="panel case-facts">
            <div class="panel__header">
              <h2 class="panel__title">案例事实</h2>
            </div>
            <div class="panel__body">
              <dl class="facts">
                <dt>案例编号</dt>
                <dd class="mono">{{ detail.caseNo }}</dd>
                <dt>申请人</dt>
                <dd>{{ detail.applicant }}</dd>
                <dt>申请人组织</dt>
                <dd>{{ detail.applicantOrgName }}</dd>
                <dt>提交时间</dt>
                <dd>{{ formatDateTimeInTimezone(detail.appliedAt) }}</dd>
                <dt>数据截止</dt>
                <dd>{{ formatBusinessDate(todayBusinessDate()) }}</dd>
              </dl>
            </div>
          </section>
        </div>
      </div>
    </template>

    <el-dialog
      v-model="dialogOpen"
      :title="decision.action === 'APPROVE' ? '同意申请' : decision.action === 'REJECT' ? '驳回申请' : '退回补充材料'"
      width="500px"
    >
      <div class="stack">
        <p class="small">
          {{ decision.action === 'APPROVE' ? '通过后系统会尝试执行业务效果；如生效失败，将显示“生效失败”而不是“已完成”。' : '驳回与退回都需要填写具体依据，申请人会看到该意见。' }}
        </p>
        <label class="field-label" for="case-decision-comment">审批意见</label>
        <el-input
          id="case-decision-comment"
          v-model="decision.comment"
          type="textarea"
          :rows="4"
          maxlength="500"
          show-word-limit
          placeholder="填写审批依据、退回补充要求或拒绝原因"
          :aria-invalid="Boolean(decisionErrors.comment)"
        />
        <p v-if="decisionErrors.comment" class="field-error">{{ decisionErrors.comment }}</p>
      </div>
      <template #footer>
        <el-button @click="dialogOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">提交决定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.case-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 22px;
  padding: 20px 22px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.case-hero__meta {
  color: var(--color-ink-muted);
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 10.5px;
}

.case-hero__main h1 {
  margin: 4px 0;
  font-size: 23px;
  font-weight: 680;
  letter-spacing: -0.02em;
}

.case-hero__sub {
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.case-hero__status {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: var(--color-canvas);
  border-radius: 8px;
}

.case-hero__status-item {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.case-hero__status-item > span {
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.08em;
}

.case-hero__arrow {
  color: var(--color-line-strong);
  font-size: 16px;
}

.case-detail-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.55fr) minmax(300px, 1fr);
  gap: 16px;
  align-items: start;
}

.case-description {
  margin-bottom: 14px;
  color: var(--color-ink-secondary);
  font-size: 13px;
  line-height: 1.75;
}

.status-block {
  padding: 12px;
  border: 1px solid var(--color-line);
  border-radius: 7px;
}

.status-block--application {
  background: var(--color-info-soft);
  border-color: #ccdfeb;
}

.status-block--effect {
  background: #fdf9ef;
  border-color: #ecdcb7;
}

.status-block__heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 7px;
}

.status-block__eyebrow {
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.status-block p {
  margin-bottom: 4px;
  color: var(--color-ink-secondary);
  font-size: 11.5px;
  line-height: 1.55;
}

.case-facts :deep(.facts) {
  font-size: 11.5px;
}

.field-label {
  font-size: 12px;
  font-weight: 600;
}

.field-error {
  color: var(--color-danger);
  font-size: 11.5px;
}

@media (max-width: 1080px) {
  .case-detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 720px) {
  .case-hero {
    flex-direction: column;
  }

  .case-hero__status {
    width: 100%;
    justify-content: space-between;
  }
}
</style>
