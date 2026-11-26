<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import {
  businessDateToday,
  buildDepartmentTree,
  canUseAsParent,
  createDepartment,
  errorMessage,
  fetchDepartmentMembers,
  fetchDepartmentPage,
  fetchOrgTree,
  flattenDepartments,
  isForbidden,
  moveDepartment,
  updateDepartment,
  type Department,
  type DepartmentCreateInput,
  type DepartmentMember,
  type DepartmentNode,
  type MemberPage,
  type OrgTreeNode,
} from '../api'
import DepartmentTree from '../components/DepartmentTree.vue'
import DepartmentFormDrawer from '../components/DepartmentFormDrawer.vue'
import DepartmentMembersPanel from '../components/DepartmentMembersPanel.vue'

defineOptions({ name: 'DepartmentWorkspacePage' })

const session = useSessionStore()
const canManage = computed(() => session.can('org.unit.manage'))

const TYPE_LABELS: Record<string, string> = {
  COMPANY: '公司',
  DIVISION: '事业部',
  DEPARTMENT: '部门',
  TEAM: '团队',
  COST_CENTER: '成本中心',
  SHARED_SERVICE: '共享服务',
}

const ASSIGNMENT_LABELS: Record<string, string> = {
  PRIMARY: '主任职',
  SECONDMENT: '借调',
  MATRIX: '矩阵任职',
  PROJECT: '项目任职',
}

const asOf = ref(businessDateToday())
const loading = ref(true)
const forbidden = ref(false)
const loadError = ref('')
const loadRequestId = ref<string | null>(null)
const partialReasons = ref<string[]>([])

const departments = ref<Department[]>([])
const treeRows = ref<OrgTreeNode[]>([])
const selectedId = ref<number | null>(null)

const members = ref<MemberPage | null>(null)
const membersLoading = ref(false)
const membersError = ref('')
const membersRequestId = ref<string | null>(null)

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const saving = ref(false)
const saveError = ref<ApiError | null>(null)

const moveOpen = ref(false)
const moveParentId = ref('')
const moveDate = ref('')
const moveStep = ref<'choose' | 'confirm'>('choose')
const moveSaving = ref(false)
const moveError = ref('')

const tree = computed<DepartmentNode[]>(() => buildDepartmentTree(treeRows.value, departments.value))
const sortedDepartments = computed(() => flattenDepartments(departments.value))

const selectedDepartment = computed<Department | null>(() => {
  if (selectedId.value === null) return null
  return departments.value.find((department) => department.id === selectedId.value) ?? null
})

function findNode(nodes: readonly DepartmentNode[], id: number): DepartmentNode | null {
  for (const node of nodes) {
    if (node.id === id) return node
    const child = findNode(node.children, id)
    if (child !== null) return child
  }
  return null
}

const selectedNode = computed<DepartmentNode | null>(() => {
  if (selectedId.value === null) return null
  return findNode(tree.value, selectedId.value)
})

const selectedTypeLabel = computed(() =>
  selectedNode.value === null ? '—' : TYPE_LABELS[selectedNode.value.type] ?? '未知类型',
)

const selectedStatusLabel = computed(() =>
  selectedNode.value?.status === 'INACTIVE'
    ? '停用'
    : selectedNode.value?.status === 'ACTIVE'
      ? '启用'
      : '—',
)

const selectedParentName = computed(() => {
  const parentId = selectedNode.value?.parent_id ?? null
  if (parentId === null) return '无（根节点）'
  return sortedDepartments.value.find((candidate) => candidate.id === parentId)?.name ?? `#${parentId}`
})

const memberCount = computed(() => members.value?.total ?? 0)

const memberPreview = computed<DepartmentMember[]>(() => (members.value?.items ?? []).slice(0, 5))

const parentOptions = computed(() => {
  if (drawerMode.value === 'create') return sortedDepartments.value
  const editingId = selectedDepartment.value?.id ?? null
  return sortedDepartments.value.filter((candidate) => {
    if (editingId === null) return true
    return canUseAsParent(departments.value, candidate.id, editingId)
  })
})

const moveCandidateParents = computed(() => {
  const movingId = selectedNode.value?.id ?? null
  if (movingId === null) return []
  return sortedDepartments.value.filter(
    (candidate) => candidate.id !== movingId && canUseAsParent(departments.value, candidate.id, movingId),
  )
})

const moveTargetParent = computed<Department | null>(() => {
  if (moveParentId.value === '') return null
  const id = Number(moveParentId.value)
  return sortedDepartments.value.find((candidate) => candidate.id === id) ?? null
})

const moveDescendantRefused = computed(() => {
  if (selectedNode.value === null || moveTargetParent.value === null) return false
  return !canUseAsParent(departments.value, moveTargetParent.value.id, selectedNode.value.id)
})

async function loadWorkspace(): Promise<void> {
  loading.value = true
  forbidden.value = false
  loadError.value = ''
  loadRequestId.value = null
  partialReasons.value = []

  try {
    const [departmentPage, orgTree] = await Promise.all([
      fetchDepartmentPage(asOf.value)
        .then((page) => ({ ok: true as const, page }))
        .catch((error: unknown) => ({ ok: false as const, error })),
      fetchOrgTree(asOf.value)
        .then((page) => ({ ok: true as const, page }))
        .catch((error: unknown) => ({ ok: false as const, error })),
    ])

    if (!departmentPage.ok && !orgTree.ok) throw departmentPage.error

    if (!departmentPage.ok) {
      if (isForbidden(departmentPage.error)) throw departmentPage.error
      departments.value = []
      partialReasons.value.push(
        `部门在编投影加载失败：${errorMessage(departmentPage.error)}；组织层级仍可浏览，在编人数显示为 —。`,
      )
      if (departmentPage.error instanceof ApiError) loadRequestId.value = departmentPage.error.requestId
    } else {
      departments.value = departmentPage.page.items
      if (departmentPage.page.partial) {
        partialReasons.value.push(
          departmentPage.page.partialReason ?? '部门在编投影只返回了部分结果。',
        )
      }
    }

    if (!orgTree.ok) {
      if (isForbidden(orgTree.error)) throw orgTree.error
      treeRows.value = departments.value.map(({ id, parent_id, code, name, type }) => ({
        id,
        parent_id,
        code,
        name,
        type,
      }))
      partialReasons.value.push(`组织树加载失败：${errorMessage(orgTree.error)}；仅展示部门投影。`)
    } else {
      treeRows.value = orgTree.page.items
      if (orgTree.page.partial) partialReasons.value.push('组织树只返回了部分结果。')
    }

    const available = new Set([
      ...treeRows.value.map((row) => row.id),
      ...departments.value.map((row) => row.id),
    ])
    if (selectedId.value === null || !available.has(selectedId.value)) {
      const first = sortedDepartments.value[0]
      const firstTreeRow = treeRows.value[0]
      selectedId.value = first?.id ?? firstTreeRow?.id ?? null
    }

    if (selectedId.value !== null) await loadMembers(selectedId.value)
    else members.value = null
  } catch (error) {
    if (isForbidden(error)) forbidden.value = true
    else {
      loadError.value = errorMessage(error)
      if (error instanceof ApiError) loadRequestId.value = error.requestId
    }
    departments.value = []
    treeRows.value = []
    members.value = null
  } finally {
    loading.value = false
  }
}

async function loadMembers(id: number): Promise<void> {
  membersLoading.value = true
  membersError.value = ''
  membersRequestId.value = null
  try {
    members.value = await fetchDepartmentMembers(id, asOf.value)
  } catch (error) {
    members.value = null
    membersError.value = errorMessage(error)
    if (error instanceof ApiError) membersRequestId.value = error.requestId
  } finally {
    membersLoading.value = false
  }
}

async function selectDepartment(id: number): Promise<void> {
  selectedId.value = id
  closeMove()
  await loadMembers(id)
}

function openCreate(): void {
  drawerMode.value = 'create'
  saveError.value = null
  drawerOpen.value = true
}

function openEdit(): void {
  if (selectedDepartment.value === null) return
  drawerMode.value = 'edit'
  saveError.value = null
  drawerOpen.value = true
}

async function saveDepartment(input: DepartmentCreateInput): Promise<void> {
  saving.value = true
  saveError.value = null
  try {
    if (drawerMode.value === 'create') {
      await createDepartment(input)
      ElMessage.success('部门已创建。')
    } else {
      const current = selectedDepartment.value
      if (current === null) return
      await updateDepartment(current.id, input)
      ElMessage.success('部门信息已保存。')
    }
    drawerOpen.value = false
    await loadWorkspace()
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : null
    if (saveError.value === null) ElMessage.error(errorMessage(error))
  } finally {
    saving.value = false
  }
}

function openMove(): void {
  moveParentId.value = ''
  moveDate.value = ''
  moveStep.value = 'choose'
  moveError.value = ''
  moveOpen.value = true
}

function closeMove(): void {
  moveOpen.value = false
}

function enterMoveConfirmation(): void {
  moveError.value = ''
  if (moveDate.value === '') {
    moveError.value = '请填写迁移生效日期。'
    return
  }
  if (moveTargetParent.value === null) {
    moveError.value = '请选择新的上级部门。'
    return
  }
  if (moveDescendantRefused.value) {
    moveError.value = `${moveTargetParent.value.name} 是当前部门或其后代，不能作为上级；已拒绝提交。`
    return
  }
  moveStep.value = 'confirm'
}

async function confirmMove(): Promise<void> {
  if (selectedNode.value === null || moveTargetParent.value === null) return
  if (moveDescendantRefused.value) {
    moveError.value = `${moveTargetParent.value.name} 是 ${selectedNode.value.name} 的下级部门，不能作为上级；已拒绝提交。`
    return
  }

  moveSaving.value = true
  moveError.value = ''
  try {
    await moveDepartment(selectedNode.value.id, {
      parent_id: moveTargetParent.value.id,
      effective_from: moveDate.value,
    })
    ElMessage.success('部门迁移已提交；历史组织路径保留。')
    moveOpen.value = false
    await loadWorkspace()
  } catch (error) {
    moveError.value =
      error instanceof ApiError && Object.keys(error.fieldErrors).length > 0
        ? Object.values(error.fieldErrors)[0]?.[0] ?? error.message
        : errorMessage(error)
  } finally {
    moveSaving.value = false
  }
}

function refreshMembers(): void {
  if (selectedId.value === null) return
  void loadMembers(selectedId.value)
}

onMounted(loadWorkspace)
</script>

<template>
  <div class="page department-workspace-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">员工与组织 · 部门管理</p>
        <h1 class="page-header__title">部门工作台</h1>
        <p class="page-header__description">
          左树浏览组织层级与在编人数，右侧维护部门、负责人缺口、成员与部门迁移；全部数据来自实时接口。
        </p>
      </div>
      <div class="page-header__actions">
        <label class="department-asof">
          <span>业务日期</span>
          <input v-model="asOf" type="date" data-testid="department-as-of" @change="loadWorkspace" />
        </label>
        <el-button :disabled="loading" @click="loadWorkspace">刷新</el-button>
        <el-button
          v-if="canManage && !forbidden"
          type="primary"
          data-testid="department-create"
          @click="openCreate"
        >
          新增部门
        </el-button>
      </div>
    </header>

    <section
      v-if="forbidden"
      class="state-panel error-state"
      role="alert"
      data-testid="departments-forbidden"
    >
      <div class="state-panel__mark" aria-hidden="true">!</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权限查看部门工作台</h2>
        <p class="state-panel__body">
          当前账号缺少 org.unit.read（或数据范围不含该组织子树）。联系管理员授权后再试。
        </p>
      </div>
      <el-button type="primary" plain @click="loadWorkspace">重试</el-button>
    </section>

    <section
      v-else-if="loadError !== ''"
      class="state-panel error-state"
      role="alert"
      data-testid="departments-error"
    >
      <div class="state-panel__mark" aria-hidden="true">!</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">部门工作台暂时无法加载</h2>
        <p class="state-panel__body">{{ loadError }}</p>
        <p v-if="loadRequestId" class="state-panel__request">
          请求编号：<span class="mono">{{ loadRequestId }}</span>
        </p>
      </div>
      <el-button type="primary" plain @click="loadWorkspace">重试</el-button>
    </section>

    <div
      v-else-if="loading"
      class="loading-state"
      role="status"
      aria-live="polite"
      aria-label="正在加载部门工作台"
    >
      <div class="loading-state__rows" aria-hidden="true">
        <span
          v-for="index in 6"
          :key="index"
          class="loading-state__row"
          :style="{ width: `${92 - index * 6}%` }"
        />
      </div>
      <span class="visually-hidden">正在加载部门工作台</span>
    </div>

    <template v-else>
      <el-alert
        v-for="(reason, index) in partialReasons"
        :key="index"
        type="warning"
        :closable="false"
        show-icon
        class="department-partial"
        data-testid="departments-partial"
      >
        <template #title>部分数据：{{ reason }}</template>
      </el-alert>

      <div class="department-workspace">
        <section class="panel department-workspace__tree">
          <header class="panel__header">
            <div>
              <h2 class="panel__title">组织架构</h2>
              <p class="panel__subtitle">业务日期 {{ asOf }}；节点显示直属在编人数。</p>
            </div>
          </header>
          <div class="panel__body panel__body--flush">
            <p
              v-if="tree.length === 0"
              class="department-workspace__empty"
              data-testid="departments-empty"
            >
              暂无部门。业务日期 {{ asOf }} 没有生效的组织节点。
              <button
                v-if="canManage"
                type="button"
                class="department-link"
                data-testid="department-empty-create"
                @click="openCreate"
              >
                新增部门
              </button>
            </p>
            <DepartmentTree
              v-else
              :nodes="tree"
              :selected-id="selectedId"
              @select="selectDepartment"
            />
          </div>
        </section>

        <div class="department-workspace__detail">
          <template v-if="selectedNode !== null">
            <section class="panel" data-testid="department-detail">
              <header class="panel__header">
                <div>
                  <p class="page-header__breadcrumb">部门详情</p>
                  <h2 class="panel__title">{{ selectedNode.name }}</h2>
                  <p class="panel__subtitle">
                    {{ selectedNode.code }} · {{ selectedTypeLabel }}
                  </p>
                </div>
                <div class="department-detail__actions">
                  <button
                    v-if="canManage"
                    type="button"
                    class="department-button"
                    data-testid="department-edit"
                    @click="openEdit"
                  >
                    编辑部门
                  </button>
                  <button
                    v-if="canManage"
                    type="button"
                    class="department-button department-button--secondary"
                    data-testid="department-move"
                    @click="openMove"
                  >
                    迁移部门
                  </button>
                </div>
              </header>

              <div class="panel__body">
                <dl class="department-facts">
                  <div class="department-facts__item">
                    <dt>部门编码</dt>
                    <dd>{{ selectedNode.code }}</dd>
                  </div>
                  <div class="department-facts__item">
                    <dt>组织类型</dt>
                    <dd>{{ selectedTypeLabel }}</dd>
                  </div>
                  <div class="department-facts__item">
                    <dt>状态</dt>
                    <dd>{{ selectedStatusLabel }}</dd>
                  </div>
                  <div class="department-facts__item">
                    <dt>上级部门</dt>
                    <dd>{{ selectedParentName }}</dd>
                  </div>
                  <div class="department-facts__item">
                    <dt>在编人数</dt>
                    <dd>{{ selectedNode.headcount === null ? '—' : `${selectedNode.headcount} 人` }}</dd>
                  </div>
                  <div class="department-facts__item">
                    <dt>编制上限</dt>
                    <dd>{{ selectedNode.headcount_limit === null ? '未设置' : `${selectedNode.headcount_limit} 人` }}</dd>
                  </div>
                  <div class="department-facts__item">
                    <dt>成员数</dt>
                    <dd data-testid="department-member-count">
                      {{ membersLoading ? '加载中…' : membersError !== '' ? '—' : `${memberCount} 人` }}
                    </dd>
                  </div>
                  <div class="department-facts__item">
                    <dt>有效期</dt>
                    <dd>
                      {{ selectedNode.valid_from || '—' }} 至
                      {{
                        selectedNode.valid_to === '9999-12-31' || selectedNode.valid_to === ''
                          ? '长期'
                          : selectedNode.valid_to
                      }}
                    </dd>
                  </div>
                </dl>

                <div class="department-manager" data-testid="department-manager-gap">
                  <strong>负责人：</strong>
                  <span v-if="selectedNode.manager_name">{{ selectedNode.manager_name }}</span>
                  <span v-else>—（接口未返回）</span>
                  <small>当前部门契约未返回负责人字段；补齐后姓名与联系方式按字段策略裁剪展示。</small>
                </div>
              </div>
            </section>

            <section class="panel">
              <header class="panel__header">
                <div>
                  <h2 class="panel__title">成员概览</h2>
                  <p class="panel__subtitle">仅展示实时任职投影，联系方式不在此页面渲染。</p>
                </div>
                <button
                  type="button"
                  class="department-link"
                  data-testid="department-members-refresh"
                  @click="refreshMembers"
                >
                  刷新成员
                </button>
              </header>
              <div class="panel__body">
                <p
                  v-if="membersError !== ''"
                  class="department-detail__state department-detail__state--error"
                  data-testid="department-members-error"
                >
                  {{ membersError }}
                  <span v-if="membersRequestId">（请求编号 {{ membersRequestId }}）</span>
                </p>
                <p v-else-if="membersLoading" class="department-detail__state">正在加载成员…</p>
                <p
                  v-else-if="memberPreview.length === 0"
                  class="department-detail__state"
                  data-testid="department-members-empty"
                >
                  当前部门暂无生效成员。
                </p>
                <ul v-else class="department-member-preview">
                  <li v-for="member in memberPreview" :key="member.assignment_id">
                    <span>员工关系 #{{ member.employment_id }}</span>
                    <span>{{ ASSIGNMENT_LABELS[member.assignment_type] ?? '未知任职' }}</span>
                    <span>{{ member.valid_from }} 起</span>
                  </li>
                </ul>
              </div>
            </section>

            <DepartmentMembersPanel
              :department-id="selectedId"
              :members="members"
              :loading="membersLoading"
              :error="membersError"
              :partial-reason="members?.partialReason ?? ''"
              :can-manage="canManage"
              @refresh="refreshMembers"
            />
          </template>
          <section v-else class="panel">
            <div
              class="panel__body department-workspace__empty"
              data-testid="department-detail-empty"
            >
              请从左侧组织树选择一个部门查看详情。
            </div>
          </section>
        </div>
      </div>
    </template>

    <DepartmentFormDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :department="selectedDepartment"
      :departments="departments"
      :parent-options="parentOptions"
      :saving="saving"
      :server-error="saveError"
      @save="saveDepartment"
    />

    <el-dialog
      v-model="moveOpen"
      title="迁移部门"
      width="480px"
      :close-on-click-modal="false"
      data-testid="department-move-dialog"
    >
      <div v-if="selectedNode !== null" class="department-move">
        <p>
          将 <strong>{{ selectedNode.name }}</strong> 迁移到新的上级部门。系统会先校验目标，若目标为当前部门或其后代则拒绝提交。
        </p>

        <label class="department-field">
          <span>新的上级部门</span>
          <select v-model="moveParentId" data-testid="move-parent" :disabled="moveStep === 'confirm'">
            <option value="">请选择上级部门</option>
            <option
              v-for="candidate in moveCandidateParents"
              :key="candidate.id"
              :value="String(candidate.id)"
            >
              {{ candidate.name }}（{{ candidate.code }}）
            </option>
          </select>
          <small>当前部门及其下级部门不会出现在可选项；后端也会以 422 拒绝成环。</small>
        </label>

        <label class="department-field">
          <span>生效日期</span>
          <input
            v-model="moveDate"
            type="date"
            data-testid="move-effective-date"
            :disabled="moveStep === 'confirm'"
          />
        </label>

        <p
          v-if="moveStep === 'confirm'"
          class="department-move__confirm"
          data-testid="move-confirmation"
        >
          确认将部门 <strong>{{ selectedNode.name }}</strong>（{{ selectedNode.code }}）
          迁移到 <strong>{{ moveTargetParent?.name }}</strong>（{{ moveTargetParent?.code }}）？
          生效日期：{{ moveDate }}。目标若是当前部门的下级部门会被拒绝。
        </p>

        <p v-if="moveError !== ''" class="department-form__error" role="alert" data-testid="move-error">
          {{ moveError }}
        </p>
      </div>

      <template #footer>
        <el-button @click="closeMove">取消</el-button>
        <el-button
          v-if="moveStep === 'choose'"
          type="primary"
          data-testid="move-confirm"
          :disabled="moveTargetParent === null || moveDate === ''"
          @click="enterMoveConfirmation"
        >
          下一步：确认迁移
        </el-button>
        <el-button
          v-else
          type="danger"
          :loading="moveSaving"
          data-testid="move-confirm-final"
          @click="confirmMove"
        >
          确认迁移
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.department-asof {
  display: flex;
  gap: 6px;
  align-items: center;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.department-asof input {
  padding: 5px 7px;
  font-size: 12px;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}
.department-workspace {
  display: grid;
  grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
  gap: 14px;
  align-items: start;
}
.department-workspace__tree {
  position: sticky;
  top: 12px;
  overflow: hidden;
}
.department-workspace__detail {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}
.department-workspace__empty {
  padding: 18px 14px;
  color: var(--color-ink-muted);
  font-size: 12px;
}
.department-detail__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.department-facts {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin: 0;
}
.department-facts__item {
  min-width: 0;
}
.department-facts__item dt {
  color: var(--color-ink-muted);
  font-size: 11px;
}
.department-facts__item dd {
  margin: 3px 0 0;
  color: var(--color-ink);
  font-size: 13px;
  font-weight: 600;
}
.department-manager {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: baseline;
  margin-top: 16px;
  padding: 9px 10px;
  font-size: 12px;
  background: #fff7e6;
  border-radius: var(--radius-sm);
}
.department-manager small {
  flex-basis: 100%;
  color: #8a5a00;
  font-size: 10px;
}
.department-detail__state {
  padding: 8px 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}
.department-detail__state--error {
  color: var(--color-danger);
}
.department-member-preview {
  margin: 0;
  padding: 0;
  list-style: none;
}
.department-member-preview li {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  padding: 7px 0;
  font-size: 12px;
  border-bottom: 1px solid var(--color-line);
}
.department-button {
  padding: 7px 14px;
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  background: var(--color-brand);
  border: 1px solid var(--color-brand);
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.department-button--secondary {
  color: var(--color-brand);
  background: #fff;
}
.department-link {
  padding: 0;
  color: var(--color-brand);
  font-size: 12px;
  background: transparent;
  border: 0;
  cursor: pointer;
}
.department-partial {
  margin-bottom: 12px;
}
.department-move {
  display: flex;
  flex-direction: column;
  gap: 12px;
  font-size: 12px;
}
.department-move__confirm {
  padding: 9px 10px;
  font-size: 12px;
  background: var(--color-brand-soft);
  border-radius: var(--radius-sm);
}
.department-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.department-field > span {
  font-weight: 600;
}
.department-field select,
.department-field input {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}
.department-field small {
  color: var(--color-ink-muted);
  font-size: 10px;
}
.department-form__error {
  padding: 8px 10px;
  color: var(--color-danger);
  font-size: 12px;
  background: var(--color-danger-soft);
  border-radius: var(--radius-sm);
}
@media (max-width: 900px) {
  .department-workspace {
    grid-template-columns: minmax(0, 1fr);
  }
  .department-workspace__tree {
    position: static;
  }
  .department-facts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
