<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { getApi } from '@/api'
import type { AdminSnapshot, AuthorityBinding, CapabilityDefinition } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { FIELD_PROFILE_LABEL } from '@/utils/status'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'

const session = useSessionStore()
const tab = ref('users')
const loading = ref(true)
const error = ref('')
const snapshot = ref<AdminSnapshot | null>(null)
const search = ref('')
const bindingDialogOpen = ref(false)
const bindingError = ref('')
const bindingSubmitting = ref(false)
const bindingForm = reactive({
  principalName: '',
  principalEmploymentNo: '',
  capabilityCode: 'people.employee.read',
  capabilityName: '查看员工目录',
  anchorType: 'ORG_UNIT',
  anchorName: '',
  fieldProfiles: ['P0_PUBLIC'] as AuthorityBinding['fieldProfiles'],
  purpose: '',
  validFrom: todayBusinessDate(),
  expiresOn: '2027-02-28',
})

const tabs = computed(() => [
  { key: 'users', label: '用户', capability: 'iam.user.read' },
  { key: 'roles', label: '角色', capability: 'iam.role.manage' },
  { key: 'capabilities', label: '能力目录（只读）', capability: 'iam.user.read' },
  { key: 'bindings', label: '授权绑定', capability: 'iam.binding.manage' },
  { key: 'access-review', label: '访问复核', capability: 'iam.audit.read' },
  { key: 'audit', label: '审计搜索', capability: 'iam.audit.read' },
].filter((item) => session.can(item.capability)))

const userColumns: DataTableColumn[] = [
  { key: 'name', label: '姓名', minWidth: '130px' },
  { key: 'employmentNo', label: '工号', width: '130px' },
  { key: 'orgName', label: '组织', minWidth: '130px' },
  { key: 'roles', label: '角色', minWidth: '150px' },
  { key: 'status', label: '状态', width: '90px' },
  { key: 'mfaEnabled', label: 'MFA', width: '80px' },
  { key: 'lastLoginAt', label: '最后登录', minWidth: '150px' },
]

const canManageBindings = computed(() => session.can('iam.binding.manage'))
const canReview = computed(() => session.can('iam.audit.read'))

const filteredBindings = computed(() => {
  const list = snapshot.value?.bindings ?? []
  if (!search.value.trim()) return list
  const query = search.value.trim().toLowerCase()
  return list.filter((binding) =>
    `${binding.principalName} ${binding.principalEmploymentNo} ${binding.capabilityName} ${binding.anchorName}`
      .toLowerCase()
      .includes(query),
  )
})

const filteredAudit = computed(() => {
  const list = snapshot.value?.audit.items ?? []
  if (!search.value.trim()) return list
  const query = search.value.trim().toLowerCase()
  return list.filter((event) =>
    `${event.actorName} ${event.action} ${event.target} ${event.requestId}`.toLowerCase().includes(query),
  )
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const next = await getApi().admin.getAdminSnapshot(tab.value)

    // Bindings are per user; load them on demand for the first visible user
    // instead of pretending the tenant has no grants.
    if (tab.value === 'bindings' && next.users.items.length > 0) {
      const firstUser = next.users.items[0]

      if (firstUser) {
        const bindings = await getApi().admin.loadUserBindings(firstUser.id)
        next.bindings = Array.isArray(bindings) ? (bindings as AdminSnapshot['bindings']) : []
      }
    }

    snapshot.value = next
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

watch(tab, () => {
  search.value = ''
  void load()
})

function capabilityByCode(code: string): CapabilityDefinition | undefined {
  return snapshot.value?.capabilities.find((capability) => capability.code === code)
}

function openBindingDialog(): void {
  bindingForm.purpose = ''
  bindingForm.anchorName = ''
  bindingError.value = ''
  bindingDialogOpen.value = true
}

async function submitBinding(): Promise<void> {
  if (bindingForm.purpose.trim().length < 5) {
    bindingError.value = '请填写至少 5 个字的业务目的；无目的不授予权限。'
    return
  }
  if (bindingForm.anchorName.trim().length < 2) {
    bindingError.value = '请填写授权锚点（例如组织名称）。'
    return
  }
  const capability = capabilityByCode(bindingForm.capabilityCode)
  bindingSubmitting.value = true
  bindingError.value = ''
  try {
    const created = await getApi().admin.createBinding({
      principalName: bindingForm.principalName || '新授权对象',
      principalEmploymentNo: bindingForm.principalEmploymentNo || 'DEMO-0000',
      capabilityCode: bindingForm.capabilityCode,
      capabilityName: capability?.name ?? bindingForm.capabilityCode,
      anchorType: bindingForm.anchorType,
      anchorName: bindingForm.anchorName,
      fieldProfiles: bindingForm.fieldProfiles,
      purpose: bindingForm.purpose,
      validFrom: bindingForm.validFrom,
      expiresOn: bindingForm.expiresOn,
    })
    if (snapshot.value) snapshot.value.bindings = [created, ...snapshot.value.bindings]
    bindingDialogOpen.value = false
    ElMessage.success('授权绑定已创建，下一次请求即按新绑定校验。')
  } catch (reason) {
    bindingError.value = errorCopy(reason)
  } finally {
    bindingSubmitting.value = false
  }
}

async function reviewAccess(id: number, action: 'KEEP' | 'REVOKE'): Promise<void> {
  if (!snapshot.value) return
  try {
    const updated = await getApi().admin.reviewAccess(id, action)
    const item = snapshot.value.accessReview.find((entry) => entry.id === id)
    if (item) Object.assign(item, updated)
    ElMessage.success(action === 'KEEP' ? '已保留访问，复核结论已记录。' : '已回收访问，下一次请求将被拒绝。')
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  }
}

function auditResultLabel(result: string): string {
  return result === 'SUCCESS' ? '成功' : '已拒绝'
}

onMounted(load)
</script>

<template>
  <div class="page admin-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">治理</p>
        <h1 class="page-header__title">权限治理</h1>
        <p class="page-header__description">
          能力语义由代码拥有，管理员只能授予绑定，不能新建权限码。每个绑定都有锚点、字段档位、目的与到期时间。
        </p>
      </div>
      <div class="page-header__actions">
        <span class="plain-chip">P0–P3 字段档位</span>
      </div>
    </header>

    <div class="admin-tabs" role="tablist" aria-label="权限治理功能">
      <button
        v-for="item in tabs"
        :key="item.key"
        type="button"
        role="tab"
        class="admin-tab"
        :class="{ 'admin-tab--active': tab === item.key }"
        :aria-selected="tab === item.key"
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </div>

    <ErrorState v-if="error" description="权限治理数据暂时无法加载。" :code="error" @retry="load" />
    <LoadingState v-else-if="loading" :rows="8" label="正在加载权限治理数据" />

    <template v-else-if="snapshot">
      <section v-if="tab === 'users'" class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">用户账户</h2>
            <p class="panel__subtitle">账户、角色与 MFA 状态。撤权与离职会提升 auth_version，使旧会话下一次请求失效。</p>
          </div>
        </div>
        <DataTable
          :columns="userColumns"
          :rows="snapshot.users.items as unknown as Array<Record<string, unknown>>"
          :total="snapshot.users.total"
          :page="snapshot.users.page"
          :page-size="snapshot.users.page_size"
          row-key="id"
          empty-title="本租户暂无账户"
        >
          <template #cell-roles="{ row }">
            <span class="chip-row">
              <span v-for="role in (row as unknown as { roles: string[] }).roles" :key="role" class="plain-chip">{{ role }}</span>
            </span>
          </template>
          <template #cell-status="{ row }">
            <span class="plain-chip">{{ (row as unknown as { status: string }).status === 'ACTIVE' ? '启用' : '已停用' }}</span>
          </template>
          <template #cell-mfaEnabled="{ row }">
            <span :class="{ 'text-success': (row as unknown as { mfaEnabled: boolean }).mfaEnabled }">
              {{ (row as unknown as { mfaEnabled: boolean }).mfaEnabled ? '已启用' : '未启用' }}
            </span>
          </template>
          <template #cell-lastLoginAt="{ row }">
            <span class="small">{{ formatDateTimeInTimezone((row as unknown as { lastLoginAt: string | null }).lastLoginAt) }}</span>
          </template>
        </DataTable>
      </section>

      <section v-else-if="tab === 'roles'" class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">角色模板与例外角色</h2>
            <p class="panel__subtitle">例外角色必须有 owner、业务目的与到期日；例外占比目标 ≤5%。</p>
          </div>
        </div>
        <div class="role-grid">
          <article v-for="role in snapshot.roles" :key="role.id" class="role-card">
            <div class="role-card__head">
              <div>
                <p class="role-card__name">{{ role.name }}</p>
                <p class="role-card__code mono">{{ role.code }}</p>
              </div>
              <span class="plain-chip" :class="{ 'plain-chip--warning': role.template === false }">
                {{ role.template ? '模板' : '例外' }}
              </span>
            </div>
            <p class="role-card__purpose">{{ role.purpose }}</p>
            <dl class="role-card__facts">
              <div><dt>成员</dt><dd>{{ role.memberCount }}</dd></div>
              <div><dt>能力</dt><dd>{{ role.capabilityCount }}</dd></div>
              <div><dt>风险</dt><dd>{{ role.riskLevel === 'HIGH' ? '高' : role.riskLevel === 'MEDIUM' ? '中' : '低' }}</dd></div>
            </dl>
            <p class="small muted">
              Owner：{{ role.owner ?? '—' }}
              <template v-if="role.expiresOn"> · 到期 {{ formatBusinessDate(role.expiresOn) }}</template>
            </p>
          </article>
        </div>
      </section>

      <section v-else-if="tab === 'capabilities'" class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">能力目录</h2>
            <p class="panel__subtitle">只读代码目录。新增能力必须走代码评审、后端校验与权限负测，不能在界面里创建。</p>
          </div>
        </div>
        <div class="capability-table-wrap">
          <table class="capability-table">
            <caption class="visually-hidden">能力目录</caption>
            <thead>
              <tr>
                <th scope="col">能力码</th>
                <th scope="col">说明</th>
                <th scope="col">可用锚点</th>
                <th scope="col">最大字段</th>
                <th scope="col">风险</th>
                <th scope="col">职责分离</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="capability in snapshot.capabilities" :key="capability.code">
                <td><span class="mono capability-code">{{ capability.code }}</span><span class="capability-name">{{ capability.name }}</span></td>
                <td class="small">{{ capability.description }}</td>
                <td class="small">{{ capability.anchorTypes.join(' · ') }}</td>
                <td><span class="plain-chip">{{ FIELD_PROFILE_LABEL[capability.maxFieldProfile] }}</span></td>
                <td>{{ capability.riskLevel === 'HIGH' ? '高' : capability.riskLevel === 'MEDIUM' ? '中' : '低' }}</td>
                <td class="small">{{ capability.separationRule ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-else-if="tab === 'bindings'" class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">授权绑定</h2>
            <p class="panel__subtitle">展示角色 → 能力 → 锚点 → 字段 → 目的 → 到期；包括已回收与已过期记录。</p>
          </div>
          <div class="row">
            <el-input v-model="search" size="small" clearable placeholder="搜索人员、能力或组织" style="width: 230px" aria-label="搜索授权绑定" />
            <el-button v-if="canManageBindings" type="primary" size="small" @click="openBindingDialog">新增绑定</el-button>
          </div>
        </div>
        <div class="binding-table-wrap">
          <table class="binding-table">
            <caption class="visually-hidden">授权绑定列表</caption>
            <thead>
              <tr>
                <th scope="col">授权对象</th>
                <th scope="col">能力</th>
                <th scope="col">锚点</th>
                <th scope="col">字段档位</th>
                <th scope="col">业务目的</th>
                <th scope="col">有效期</th>
                <th scope="col">状态</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="binding in filteredBindings" :key="binding.id">
                <td>
                  <span class="binding-person">{{ binding.principalName }}</span>
                  <span class="mono small muted">{{ binding.principalEmploymentNo }}</span>
                </td>
                <td>
                  <span class="capability-name">{{ binding.capabilityName }}</span>
                  <span class="mono capability-code">{{ binding.capabilityCode }}</span>
                </td>
                <td class="small">{{ binding.anchorType }} · {{ binding.anchorName }}</td>
                <td>
                  <span class="chip-row">
                    <span v-for="profile in binding.fieldProfiles" :key="profile" class="plain-chip">
                      {{ FIELD_PROFILE_LABEL[profile] }}
                    </span>
                  </span>
                </td>
                <td class="small">{{ binding.purpose }}</td>
                <td class="small tabular">
                  {{ binding.validFrom }}<br />
                  <span class="muted">至 {{ binding.expiresOn ?? '长期' }}</span>
                </td>
                <td><span class="plain-chip">{{ binding.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-else-if="tab === 'access-review'" class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">访问复核</h2>
            <p class="panel__subtitle">高危季度复核、普通年度复核；离职、转岗与长休触发即时复核。</p>
          </div>
        </div>
        <ul class="review-list">
          <li v-for="item in snapshot.accessReview" :key="item.id" class="review-row">
            <div class="review-row__main">
              <p class="review-row__name">
                {{ item.principalName }}
                <span class="mono small muted">{{ item.employmentNo }}</span>
              </p>
              <p class="small">{{ item.roleName }} · {{ item.scope }}</p>
              <p class="small muted">{{ item.reason }} · 最近使用 {{ formatDateTimeInTimezone(item.lastUsedAt) }}</p>
            </div>
            <div class="review-row__meta">
              <span class="plain-chip">截止 {{ formatBusinessDate(item.dueOn) }}</span>
              <span class="plain-chip">{{ item.riskLevel }}</span>
            </div>
            <div v-if="item.status === 'PENDING' && canReview" class="review-row__actions">
              <el-button size="small" @click="reviewAccess(item.id, 'KEEP')">保留</el-button>
              <el-button size="small" type="danger" plain @click="reviewAccess(item.id, 'REVOKE')">回收</el-button>
            </div>
            <span v-else class="plain-chip">{{ item.status }}</span>
          </li>
        </ul>
      </section>

      <section v-else-if="tab === 'audit'" class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">审计搜索</h2>
            <p class="panel__subtitle">授权变更、敏感字段访问、导出与拒绝事件均可检索；保留期按 6.5 矩阵执行。</p>
          </div>
          <el-input v-model="search" size="small" clearable placeholder="搜索操作者、动作、对象或 request_id" style="width: 300px" aria-label="搜索审计事件" />
        </div>
        <div class="audit-table-wrap">
          <table class="audit-table">
            <caption class="visually-hidden">审计事件列表</caption>
            <thead>
              <tr>
                <th scope="col">时间</th>
                <th scope="col">操作者</th>
                <th scope="col">动作</th>
                <th scope="col">对象</th>
                <th scope="col">结果</th>
                <th scope="col">目的</th>
                <th scope="col">请求编号</th>
                <th scope="col">IP</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="event in filteredAudit" :key="event.id">
                <td class="small tabular">{{ formatDateTimeInTimezone(event.occurredAt) }}</td>
                <td>{{ event.actorName }}</td>
                <td class="mono small">{{ event.action }}</td>
                <td class="small">{{ event.target }}</td>
                <td>
                  <span class="audit-result" :class="event.result === 'SUCCESS' ? 'audit-result--ok' : 'audit-result--denied'">
                    {{ auditResultLabel(event.result) }}
                  </span>
                </td>
                <td class="small">{{ event.purpose ?? '—' }}</td>
                <td class="mono small">{{ event.requestId }}</td>
                <td class="mono small muted">{{ event.ipAddress }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <el-dialog v-model="bindingDialogOpen" title="新增授权绑定" width="560px">
      <div class="stack">
        <el-alert v-if="bindingError" :title="bindingError" type="error" :closable="false" show-icon />
        <div class="binding-form-grid">
          <label class="field-label"><span>授权对象</span><el-input v-model="bindingForm.principalName" placeholder="员工姓名" /></label>
          <label class="field-label"><span>工号</span><el-input v-model="bindingForm.principalEmploymentNo" placeholder="DEMO-0000" /></label>
        </div>
        <label class="field-label">
          <span>能力</span>
          <el-select v-model="bindingForm.capabilityCode" filterable>
            <el-option
              v-for="capability in snapshot?.capabilities"
              :key="capability.code"
              :label="`${capability.name}（${capability.code}）`"
              :value="capability.code"
            />
          </el-select>
        </label>
        <div class="binding-form-grid">
          <label class="field-label">
            <span>锚点类型</span>
            <el-select v-model="bindingForm.anchorType">
              <el-option label="组织单元" value="ORG_UNIT" />
              <el-option label="法人" value="LEGAL_ENTITY" />
              <el-option label="本人" value="SELF" />
            </el-select>
          </label>
          <label class="field-label"><span>锚点</span><el-input v-model="bindingForm.anchorName" placeholder="例如：人力资源部" /></label>
        </div>
        <label class="field-label">
          <span>字段档位</span>
          <el-select v-model="bindingForm.fieldProfiles" multiple>
            <el-option v-for="(label, value) in FIELD_PROFILE_LABEL" :key="value" :label="label" :value="value" />
          </el-select>
        </label>
        <label class="field-label">
          <span>业务目的（必填）</span>
          <el-input v-model="bindingForm.purpose" type="textarea" :rows="2" maxlength="120" show-word-limit placeholder="无目的不授予权限" />
        </label>
        <div class="binding-form-grid">
          <label class="field-label"><span>生效日</span><el-date-picker v-model="bindingForm.validFrom" type="date" value-format="YYYY-MM-DD" format="YYYY-MM-DD" /></label>
          <label class="field-label"><span>到期日</span><el-date-picker v-model="bindingForm.expiresOn" type="date" value-format="YYYY-MM-DD" format="YYYY-MM-DD" /></label>
        </div>
        <el-alert title="绑定创建后立即影响下一次请求；前端菜单只是辅助，后端始终独立校验。" type="info" :closable="false" show-icon />
      </div>
      <template #footer>
        <el-button @click="bindingDialogOpen = false">取消</el-button>
        <el-button type="primary" :loading="bindingSubmitting" @click="submitBinding">创建绑定并记录审计</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.admin-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  padding: 0 4px;
  border-bottom: 1px solid var(--color-line);
}

.admin-tab {
  padding: 9px 12px 10px;
  color: var(--color-ink-muted);
  font-size: 12px;
  font-weight: 600;
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  cursor: pointer;
}

.admin-tab--active {
  color: var(--color-brand);
  border-bottom-color: var(--color-brand);
}

.role-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 16px;
}

.role-card {
  padding: 13px;
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.role-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 6px;
}

.role-card__name {
  font-size: 13px;
  font-weight: 650;
}

.role-card__code {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.role-card__purpose {
  margin: 7px 0;
  color: var(--color-ink-secondary);
  font-size: 11.5px;
  line-height: 1.55;
}

.role-card__facts {
  display: flex;
  gap: 15px;
  margin: 0 0 7px;
}

.role-card__facts div {
  display: flex;
  gap: 3px;
  font-size: 10.5px;
}

.role-card__facts dt {
  color: var(--color-ink-muted);
}

.role-card__facts dd {
  margin: 0;
  font-weight: 650;
}

.plain-chip--warning {
  color: var(--color-warning);
  background: var(--color-warning-soft);
  border-color: #ead49f;
}

.capability-table-wrap,
.binding-table-wrap,
.audit-table-wrap {
  overflow-x: auto;
}

.capability-table,
.binding-table,
.audit-table {
  width: 100%;
  min-width: 980px;
  border-collapse: collapse;
  font-size: 11.5px;
}

.capability-table th,
.binding-table th,
.audit-table th {
  padding: 9px 13px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 650;
  letter-spacing: 0.03em;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.capability-table td,
.binding-table td,
.audit-table td {
  padding: 10px 13px;
  vertical-align: top;
  border-bottom: 1px solid #edf1ef;
}

.capability-code {
  display: block;
  color: var(--color-brand);
  font-size: 9.5px;
}

.capability-name {
  display: block;
  font-weight: 650;
}

.binding-person {
  display: block;
  font-weight: 650;
}

.review-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.review-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 16px;
  border-bottom: 1px solid #edf1ef;
}

.review-row:last-child {
  border-bottom: 0;
}

.review-row__main {
  flex: 1;
  min-width: 0;
}

.review-row__name {
  font-size: 12.5px;
  font-weight: 650;
}

.review-row__meta {
  display: flex;
  gap: 5px;
}

.review-row__actions {
  display: flex;
  gap: 6px;
}

.audit-result {
  display: inline-flex;
  padding: 1px 6px;
  font-size: 9.5px;
  font-weight: 650;
  border-radius: 999px;
}

.audit-result--ok {
  color: var(--color-success);
  background: var(--color-success-soft);
}

.audit-result--denied {
  color: var(--color-danger);
  background: var(--color-danger-soft);
}

.field-label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.field-label :deep(.el-date-editor) {
  width: 100%;
}

.binding-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.text-success {
  color: var(--color-success);
}

@media (max-width: 1000px) {
  .role-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 680px) {
  .role-grid,
  .binding-form-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .review-row {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
