<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getApi } from '@/api'
import type { Notice, NoticeStatus, OrgNode } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { publishNoticeSchema, validate } from '@/utils/validation'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'

const session = useSessionStore()
const filter = ref<'all' | 'unread' | 'managed'>('all')
const loading = ref(true)
const pageError = usePageError()
const notices = ref<Notice[]>([])
const dialogOpen = ref(false)
const submitting = ref(false)
const formErrors = ref<Record<string, string>>({})
function defaultPublishAt(): string {
  // A hard-coded date silently becomes a past timestamp once the wall clock
  // passes it, and the server then rejects the notice as already published.
  return `${todayBusinessDate()} 09:00`
}

const form = reactive({
  title: '',
  body: '',
  audience: 'ALL' as 'ALL' | 'ORG' | 'ROLE',
  audienceLabel: '全体员工',
  orgUnitIds: [] as number[],
  roleCodes: [] as string[],
  publishAt: defaultPublishAt(),
  requiresAck: true,
})

const orgOptions = ref<Array<{ id: number; label: string }>>([])
const roleOptions = ref<Array<{ code: string; label: string }>>([])
const audienceOptionsLoading = ref(false)

const canManage = computed(() => session.can('engagement.notice.manage'))
const filters = computed(() =>
  [
    { key: 'all' as const, label: '已发布' },
    { key: 'unread' as const, label: '待我确认' },
    ...(canManage.value ? [{ key: 'managed' as const, label: '管理（含草稿）' }] : []),
  ],
)

const unreadCount = computed(() => notices.value.filter((notice) => !notice.acknowledged).length)

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    notices.value = await getApi().notices.listNotices(filter.value)
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
}

watch(filter, () => void load())

async function acknowledge(notice: Notice): Promise<void> {
  try {
    const updated = await getApi().notices.acknowledgeNotice(notice.id)
    Object.assign(notice, updated)
    ElMessage.success('已确认阅读，确认记录已保存。')
  } catch (reason) {
    pageError.reject(reason)
  }
}

async function retract(notice: Notice): Promise<void> {
  try {
    await ElMessageBox.confirm(
      '撤回后公告不再对员工展示，但已确认阅读记录会保留。确认撤回？',
      '撤回公告',
      { confirmButtonText: '撤回', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    const updated = await getApi().notices.retractNotice(notice.id)
    Object.assign(notice, updated)
    ElMessage.success('公告已撤回。')
  } catch (reason) {
    pageError.reject(reason)
  }
}

function openPublish(): void {
  form.title = ''
  form.body = ''
  form.audience = 'ALL'
  form.audienceLabel = '全体员工'
  form.orgUnitIds = []
  form.roleCodes = []
  form.publishAt = defaultPublishAt()
  form.requiresAck = true
  formErrors.value = {}
  dialogOpen.value = true
}

async function submitPublish(): Promise<void> {
  formErrors.value = {}

  if (form.audience === 'ORG' && form.orgUnitIds.length === 0) {
    formErrors.value.orgUnitIds = '请选择至少一个组织单位；只填写受众说明不会送达任何人。'
    return
  }

  if (form.audience === 'ROLE' && form.roleCodes.length === 0) {
    formErrors.value.roleCodes = '请选择至少一个角色；只填写受众说明不会送达任何人。'
    return
  }

  const parsed = validate(publishNoticeSchema, form)
  if (!parsed.success) {
    for (const issue of parsed.issues) formErrors.value[issue.path] = issue.message
    return
  }
  submitting.value = true
  try {
    await getApi().notices.publishNotice({
      title: form.title,
      body: form.body,
      audience: form.audience,
      audienceLabel: form.audienceLabel,
      orgUnitIds: form.audience === 'ORG' ? form.orgUnitIds : undefined,
      roleCodes: form.audience === 'ROLE' ? form.roleCodes : undefined,
      publishAt: form.publishAt.replace(' ', 'T') + ':00+08:00',
      requiresAck: form.requiresAck,
    })
    ElMessage.success('公告已发布；目标受众会在公告中心看到。')
    dialogOpen.value = false
    filter.value = 'managed'
    await load()
  } catch (reason) {
    formErrors.value.form = errorCopy(reason)
  } finally {
    submitting.value = false
  }
}

async function loadAudienceOptions(): Promise<void> {
  if (audienceOptionsLoading.value) return
  audienceOptionsLoading.value = true

  try {
    const tree = await getApi().organization.getOrgTree(todayBusinessDate())
    const flat: Array<{ id: number; label: string }> = []
    const walk = (nodes: OrgNode[], depth: number): void => {
      for (const node of nodes) {
        flat.push({ id: node.id, label: `${'　'.repeat(depth)}${node.name}` })
        walk(node.children, depth + 1)
      }
    }
    walk(tree, 0)
    orgOptions.value = flat
  } catch {
    orgOptions.value = []
  }

  if (session.can('iam.role.manage')) {
    try {
      const snapshot = await getApi().admin.getAdminSnapshot('roles')
      roleOptions.value = snapshot.roles.map((role) => ({ code: role.code, label: role.name }))
    } catch {
      roleOptions.value = []
    }
  }

  audienceOptionsLoading.value = false
}

function statusLabel(status: NoticeStatus): string {
  return status === 'PUBLISHED' ? '已发布' : status === 'DRAFT' ? '草稿' : '已撤回'
}

onMounted(() => {
  void load()
  void loadAudienceOptions()
})
</script>

<template>
  <div class="page notices-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">协作</p>
        <h1 class="page-header__title">公告与通知</h1>
        <p class="page-header__description">
          公告按组织、角色或全员定向发布；需要确认的公告会进入阅读待办。通知只携带记录 ID，不复制敏感内容。
        </p>
      </div>
      <div class="page-header__actions">
        <span v-if="unreadCount" class="plain-chip">{{ unreadCount }} 条待确认</span>
        <el-button v-if="canManage" type="primary" @click="openPublish">发布公告</el-button>
      </div>
    </header>

    <div class="notice-toolbar">
      <div class="notice-filters" role="group" aria-label="公告筛选">
        <button
          v-for="item in filters"
          :key="item.key"
          type="button"
          class="notice-filter"
          :class="{ 'notice-filter--active': filter === item.key }"
          :aria-pressed="filter === item.key"
          @click="filter = item.key"
        >
          {{ item.label }}
        </button>
      </div>
    </div>

    <ErrorState v-if="pageError.message.value" description="公告暂时无法加载。" :code="pageError.code.value" @retry="load" />
    <LoadingState v-else-if="loading" :rows="6" label="正在加载公告" />
    <EmptyState
      v-else-if="notices.length === 0"
      :title="filter === 'unread' ? '没有待确认的公告' : '暂无公告'"
      :description="filter === 'unread' ? '所有已发布且需要确认的公告都已完成阅读确认。' : '有新的全员公告或定向通知时会出现在这里。'"
    />

    <section v-else class="notice-feed">
      <article v-for="notice in notices" :key="notice.id" class="notice-card">
        <div class="notice-card__main">
          <div class="notice-card__meta">
            <span class="plain-chip">{{ statusLabel(notice.status) }}</span>
            <span v-if="notice.requiresAck && !notice.acknowledged" class="notice-card__ack-chip">需要确认</span>
            <span v-else-if="notice.requiresAck" class="notice-card__ack-chip notice-card__ack-chip--done">已确认</span>
            <span>{{ notice.audienceLabel }}</span>
            <span>·</span>
            <time :datetime="notice.publishedAt ?? undefined">{{ formatDateTimeInTimezone(notice.publishedAt) }}</time>
          </div>
          <RouterLink :to="`/notices/${notice.id}`" class="notice-card__title">{{ notice.title }}</RouterLink>
          <p class="notice-card__summary">{{ notice.summary }}</p>
          <div class="notice-card__footer">
            <span class="small muted">发布人：{{ notice.authorName }}</span>
            <span v-if="notice.status === 'PUBLISHED'" class="small muted">
              已确认 {{ notice.acknowledgementCount }} / {{ notice.audienceCount }}
            </span>
          </div>
        </div>
        <div class="notice-card__actions">
          <el-button
            v-if="notice.requiresAck && !notice.acknowledged && notice.status === 'PUBLISHED'"
            type="primary"
            plain
            size="small"
            @click="acknowledge(notice)"
          >
            确认已读
          </el-button>
          <el-button v-if="canManage && notice.status === 'PUBLISHED'" text size="small" @click="retract(notice)">
            撤回
          </el-button>
          <RouterLink :to="`/notices/${notice.id}`" class="notice-card__link">查看全文 →</RouterLink>
        </div>
      </article>
    </section>

    <el-dialog v-model="dialogOpen" title="发布公告" width="600px" :close-on-click-modal="false">
      <div class="stack">
        <el-alert v-if="formErrors.form" :title="formErrors.form" type="error" :closable="false" show-icon />
        <label class="field-label">
          <span>标题</span>
          <el-input v-model="form.title" maxlength="80" show-word-limit placeholder="例：关于 12 月薪酬发放日的安排" />
          <em v-if="formErrors.title" class="field-error">{{ formErrors.title }}</em>
        </label>
        <label class="field-label">
          <span>正文</span>
          <el-input
            v-model="form.body"
            type="textarea"
            :rows="6"
            maxlength="3000"
            show-word-limit
            placeholder="填写真实公告内容。员工会看到全部正文。"
          />
          <em v-if="formErrors.body" class="field-error">{{ formErrors.body }}</em>
        </label>
        <div class="notice-form-grid">
          <label class="field-label">
            <span>受众类型</span>
            <el-select v-model="form.audience">
              <el-option label="全员" value="ALL" />
              <el-option label="按组织" value="ORG" />
              <el-option label="按角色" value="ROLE" />
            </el-select>
          </label>
          <label class="field-label">
            <span>受众说明</span>
            <el-input v-model="form.audienceLabel" placeholder="例：研发中心 + 数字产品部" />
            <em v-if="formErrors.audienceLabel" class="field-error">{{ formErrors.audienceLabel }}</em>
          </label>
        </div>
        <label v-if="form.audience === 'ORG'" class="field-label">
          <span>目标组织（必选）</span>
          <el-select v-model="form.orgUnitIds" multiple filterable :loading="audienceOptionsLoading" placeholder="选择至少一个组织单位">
            <el-option v-for="option in orgOptions" :key="option.id" :label="option.label" :value="option.id" />
          </el-select>
          <em v-if="formErrors.orgUnitIds" class="field-error">{{ formErrors.orgUnitIds }}</em>
          <em v-else-if="orgOptions.length === 0" class="field-error">当前没有可选择的组织单位，请先确认组织架构已建立。</em>
        </label>
        <label v-if="form.audience === 'ROLE'" class="field-label">
          <span>目标角色（必选）</span>
          <el-select v-model="form.roleCodes" multiple filterable :loading="audienceOptionsLoading" placeholder="选择至少一个角色">
            <el-option v-for="option in roleOptions" :key="option.code" :label="option.label" :value="option.code" />
          </el-select>
          <em v-if="formErrors.roleCodes" class="field-error">{{ formErrors.roleCodes }}</em>
          <em v-else-if="roleOptions.length === 0" class="field-error">当前无法读取角色目录，缺少 iam.role.manage 能力。</em>
        </label>
        <div class="notice-form-grid">
          <label class="field-label">
            <span>发布时间（Asia/Shanghai）</span>
            <el-date-picker
              v-model="form.publishAt"
              type="datetime"
              format="YYYY-MM-DD HH:mm"
              value-format="YYYY-MM-DD HH:mm"
              aria-label="公告发布时间"
            />
          </label>
          <label class="notice-check">
            <el-checkbox v-model="form.requiresAck">要求阅读确认并生成待办</el-checkbox>
          </label>
        </div>
      </div>
      <template #footer>
        <el-button @click="dialogOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitPublish">发布并记录审计</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.notice-toolbar {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.notice-filters {
  display: flex;
  gap: 4px;
  padding: 3px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 7px;
}

.notice-filter {
  padding: 5px 12px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  font-weight: 600;
  background: transparent;
  border: 0;
  border-radius: 5px;
  cursor: pointer;
}

.notice-filter--active {
  color: var(--color-brand);
  background: var(--color-brand-soft);
}

.notice-feed {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.notice-card {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  padding: 16px 18px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.notice-card__main {
  flex: 1;
  min-width: 0;
}

.notice-card__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.notice-card__ack-chip {
  padding: 1px 6px;
  color: var(--color-warning);
  font-size: 9.5px;
  font-weight: 650;
  background: var(--color-warning-soft);
  border-radius: 999px;
}

.notice-card__ack-chip--done {
  color: var(--color-success);
  background: var(--color-success-soft);
}

.notice-card__title {
  display: inline-block;
  margin: 7px 0 4px;
  color: var(--color-ink);
  font-size: 15px;
  font-weight: 650;
  letter-spacing: -0.01em;
}

.notice-card__title:hover {
  color: var(--color-brand);
}

.notice-card__summary {
  color: var(--color-ink-secondary);
  font-size: 12.5px;
  line-height: 1.65;
}

.notice-card__footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
}

.notice-card__actions {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 8px;
}

.notice-card__link {
  font-size: 11px;
  white-space: nowrap;
}

.field-label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.field-error {
  color: var(--color-danger);
  font-size: 11px;
  font-style: normal;
  font-weight: 400;
}

.notice-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  align-items: end;
}

.notice-check {
  padding-bottom: 8px;
}

@media (max-width: 760px) {
  .notice-card {
    flex-direction: column;
  }

  .notice-card__actions {
    width: 100%;
    justify-content: flex-start;
  }

  .notice-form-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
