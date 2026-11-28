<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, ArrowRight } from '@element-plus/icons-vue'
import { getApi } from '@/api'
import type { MoveImpact, MoveImpactFacts, OrgNode } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { departmentTypeLabel } from '@/modules/people/departments/api'
import { usePageError } from '@/composables/usePageError'
import { formatBusinessDate, todayBusinessDate } from '@/utils/format'
import { useSessionStore } from '@/stores/session'
import AsOfDatePicker from '@/components/AsOfDatePicker.vue'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'

const session = useSessionStore()
const asOf = ref(todayBusinessDate())
const loading = ref(false)
const pageError = usePageError()
const tree = ref<OrgNode[]>([])
const expanded = ref(new Set<number>([1, 14, 11]))
const selectedId = ref<number | null>(null)
const targetId = ref<number | null>(null)
const moveDate = ref(asOf.value)
const previewOpen = ref(false)
const previewLoading = ref(false)
const impact = ref<MoveImpact | null>(null)
const impactError = ref('')
const committing = ref(false)

const canMove = computed(() => session.can('org.unit.manage'))

type FlatNode = { node: OrgNode; depth: number }
const flatNodes = computed(() => {
  const result: FlatNode[] = []
  const walk = (nodes: OrgNode[], depth: number) => {
    for (const node of nodes) {
      result.push({ node, depth })
      if (expanded.value.has(node.id)) walk(node.children, depth + 1)
    }
  }
  walk(tree.value, 0)
  return result
})

const allNodes = computed(() => {
  const result: OrgNode[] = []
  const walk = (nodes: OrgNode[]) => {
    for (const node of nodes) {
      result.push(node)
      walk(node.children)
    }
  }
  walk(tree.value)
  return result
})

const selected = computed(() => allNodes.value.find((node) => node.id === selectedId.value) ?? null)
const targetOptions = computed(() =>
  allNodes.value.filter((node) => node.id !== selectedId.value && node.type !== 'TEAM'),
)

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    tree.value = await getApi().organization.getOrgTree(asOf.value)
    if (!selectedId.value) selectedId.value = tree.value[0]?.id ?? null
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
}

function toggle(node: OrgNode): void {
  if (expanded.value.has(node.id)) expanded.value.delete(node.id)
  else expanded.value.add(node.id)
  expanded.value = new Set(expanded.value)
}

function expandAll(): void {
  expanded.value = new Set(allNodes.value.map((node) => node.id))
}

function collapseAll(): void {
  expanded.value = new Set(tree.value.map((node) => node.id))
}

function selectNode(node: OrgNode): void {
  selectedId.value = node.id
  targetId.value = null
}

async function openPreview(): Promise<void> {
  if (!selectedId.value || !targetId.value) {
    ElMessage.warning('请先选择要移动的组织与目标上级组织。')
    return
  }
  previewOpen.value = true
  previewLoading.value = true
  impact.value = null
  impactError.value = ''
  try {
    const facts: MoveImpactFacts = await getApi().organization.previewOrgMove(
      selectedId.value,
      targetId.value,
      moveDate.value,
    )
    impact.value = {
      ...facts,
      sourceName: selected.value?.name ?? `组织 #${selectedId.value}`,
      targetName: targetOptions.value.find((node) => node.id === targetId.value)?.name ?? `组织 #${targetId.value}`,
      effectiveDate: moveDate.value,
    }
  } catch (reason) {
    impactError.value = errorCopy(reason)
  } finally {
    previewLoading.value = false
  }
}

async function commitMove(): Promise<void> {
  if (!selectedId.value || !targetId.value || !impact.value) return
  try {
    await ElMessageBox.confirm(
      `将「${impact.value.sourceName}」移动到「${impact.value.targetName}」，生效日 ${formatBusinessDate(moveDate.value)}。移动会新增有效期版本，历史记录不删除。确认继续？`,
      '确认组织移动',
      { confirmButtonText: '确认移动', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  committing.value = true
  try {
    await getApi().organization.moveOrg(selectedId.value, targetId.value, moveDate.value)
    ElMessage.success('组织移动已提交，审批通过后按生效日切换。')
    previewOpen.value = false
    await load()
  } catch (reason) {
    pageError.reject(reason)
  } finally {
    committing.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page org-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">人员与组织</p>
        <h1 class="page-header__title">组织架构</h1>
        <p class="page-header__description">
          组织关系按有效期保存。移动节点前先查看影响预览：受影响员工、主管与工资成本中心数量，以及是否跨法人。
        </p>
      </div>
      <div class="page-header__actions">
        <AsOfDatePicker v-model="asOf" label="组织 as-of 日期" @change="load" />
      </div>
    </header>

    <ErrorState v-if="pageError.message.value" description="组织架构暂时无法加载。" :code="pageError.code.value" @retry="load" />

    <div v-else class="org-layout">
      <section class="panel org-tree-panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">组织树</h2>
            <p class="panel__subtitle">as-of {{ formatBusinessDate(asOf) }} · 括号内为在编人数</p>
          </div>
          <div class="row">
            <el-button size="small" text @click="expandAll">全部展开</el-button>
            <el-button size="small" text @click="collapseAll">全部收起</el-button>
          </div>
        </div>
        <div class="panel__body panel__body--flush">
          <LoadingState v-if="loading" :rows="7" label="正在加载组织树" />
          <EmptyState
            v-else-if="tree.length === 0"
            title="当前日期没有有效组织"
            description="请调整 as-of 日期，或确认组织数据是否已建立。"
          />
          <ul v-else class="org-tree" role="tree" aria-label="组织树">
            <li
              v-for="entry in flatNodes"
              :key="entry.node.id"
              role="treeitem"
              :aria-level="entry.depth + 1"
              :aria-expanded="entry.node.children.length ? expanded.has(entry.node.id) : undefined"
            >
              <div
                class="org-row"
                :class="{ 'org-row--selected': selectedId === entry.node.id }"
                :style="{ paddingLeft: `${14 + entry.depth * 20}px` }"
              >
                <button
                  v-if="entry.node.children.length"
                  type="button"
                  class="org-row__toggle"
                  :aria-label="expanded.has(entry.node.id) ? `收起 ${entry.node.name}` : `展开 ${entry.node.name}`"
                  @click="toggle(entry.node)"
                >
                  <el-icon aria-hidden="true">
                    <ArrowDown v-if="expanded.has(entry.node.id)" />
                    <ArrowRight v-else />
                  </el-icon>
                </button>
                <span v-else class="org-row__spacer" aria-hidden="true" />
                <button type="button" class="org-row__main" @click="selectNode(entry.node)">
                  <span class="org-row__name">{{ entry.node.name }}</span>
                  <span class="org-row__meta">{{ entry.node.code }} · 负责人 {{ entry.node.managerName }}</span>
                </button>
                <span class="org-row__headcount" :aria-label="`${entry.node.headcount} 人`">
                  {{ entry.node.headcount }}
                  <span>人</span>
                </span>
              </div>
            </li>
          </ul>
        </div>
      </section>

      <div class="stack org-side">
        <section v-if="selected" class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ selected.name }}</h2>
              <p class="panel__subtitle">{{ selected.code }} · {{ departmentTypeLabel(selected.type) }}</p>
            </div>
            <span class="plain-chip">{{ selected.headcount }} 人</span>
          </div>
          <div class="panel__body">
            <dl class="facts">
              <dt>负责人</dt>
              <dd>{{ selected.managerName }}</dd>
              <dt>直属人数</dt>
              <dd>{{ selected.directHeadcount }} 人</dd>
              <dt>下级组织</dt>
              <dd>{{ selected.children.length }} 个</dd>
              <dt>as-of</dt>
              <dd>{{ formatBusinessDate(asOf) }}</dd>
            </dl>
          </div>
          <div v-if="canMove" class="panel__footer org-move-form">
            <p class="section-title">移动组织</p>
            <p class="section-subtitle">先预览影响，再提交审批。历史有效期不会删除。</p>
            <label class="org-field">
              <span>目标上级组织</span>
              <el-select v-model="targetId" filterable placeholder="选择目标上级">
                <el-option
                  v-for="node in targetOptions"
                  :key="node.id"
                  :label="`${node.name}（${node.code}）`"
                  :value="node.id"
                />
              </el-select>
            </label>
            <label class="org-field">
              <span>生效日期</span>
              <el-date-picker v-model="moveDate" type="date" value-format="YYYY-MM-DD" format="YYYY-MM-DD" aria-label="组织移动生效日期" />
            </label>
            <el-button type="primary" :disabled="!targetId" @click="openPreview">
              预览移动影响
            </el-button>
          </div>
          <div v-else class="panel__footer">
            <p class="small muted">你没有 org.unit.manage 能力，只能查看组织。移动需在权限治理中申请授权。</p>
          </div>
        </section>

        <section class="panel org-legend">
          <div class="panel__header">
            <h2 class="panel__title">阅读说明</h2>
          </div>
          <div class="panel__body stack--tight small">
            <p><strong>历史不可覆盖：</strong>组织移动会结束旧版本有效期并新增版本；过去日期仍回答当时的组织关系。</p>
            <p><strong>影响预览：</strong>按生效日计算受影响员工、主管与工资成本中心，并标出跨法人移动；尚未实现的影响指标不在此展示。</p>
            <p><strong>生效前：</strong>组织移动通常以 Case 提交，审批通过不等于已经生效，需同时看生效状态。</p>
          </div>
        </section>
      </div>
    </div>

    <el-dialog v-model="previewOpen" title="组织移动影响预览" width="620px" :close-on-click-modal="false">
      <div class="stack">
        <LoadingState v-if="previewLoading" :rows="5" label="正在计算移动影响" />
        <ErrorState v-else-if="impactError" title="无法计算影响预览" :description="impactError" @retry="openPreview" />
        <template v-else-if="impact">
          <div class="impact-headline">
            <p class="muted small">移动对象</p>
            <h3>{{ impact.sourceName }} <span aria-hidden="true">→</span> {{ impact.targetName }}</h3>
            <p class="small">生效日 {{ formatBusinessDate(impact.effectiveDate) }}</p>
          </div>
          <div class="impact-grid">
            <div class="impact-item">
              <strong>{{ impact.affectedEmployees }}</strong>
              <span>受影响员工</span>
            </div>
            <div class="impact-item">
              <strong>{{ impact.affectedManagers }}</strong>
              <span>受影响主管</span>
            </div>
            <div class="impact-item">
              <strong>{{ impact.affectedPayrollCostCenters }}</strong>
              <span>工资成本中心</span>
            </div>
          </div>
          <el-alert v-if="impact.crossEntityWarning" type="warning" :closable="false" show-icon>
            <template #title>将跨法人移动</template>
            <p>目标上级组织属于另一个法人；移动后历史数据保留，新版本从生效日开始。</p>
          </el-alert>
          <p class="small muted">
            提交后系统会建立审批 Case；在获批并到达生效日之前，当前组织关系保持不变。
          </p>
        </template>
      </div>
      <template #footer>
        <el-button @click="previewOpen = false">取消</el-button>
        <el-button type="primary" :loading="committing" :disabled="!impact" @click="commitMove">
          提交移动审批
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.org-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.65fr) minmax(320px, 1fr);
  gap: 16px;
  align-items: start;
}

.org-tree-panel {
  min-width: 0;
}

.org-tree {
  margin: 0;
  padding: 6px 0;
  list-style: none;
}

.org-row {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 47px;
  padding-right: 14px;
  border-bottom: 1px solid #edf1ef;
}

.org-row:hover {
  background: #fafcfb;
}

.org-row--selected {
  background: var(--color-brand-soft);
  box-shadow: inset 3px 0 0 var(--color-brand);
}

.org-row__toggle {
  display: grid;
  flex: 0 0 22px;
  width: 22px;
  height: 22px;
  place-items: center;
  color: var(--color-ink-muted);
  background: transparent;
  border: 1px solid var(--color-line);
  border-radius: 4px;
  cursor: pointer;
}

.org-row__spacer {
  flex: 0 0 22px;
}

.org-row__main {
  display: flex;
  flex: 1;
  min-width: 0;
  flex-direction: column;
  padding: 4px 0;
  color: inherit;
  text-align: left;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.org-row__name {
  overflow: hidden;
  font-size: 13px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.org-row__meta {
  overflow: hidden;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.org-row__headcount {
  flex: 0 0 auto;
  color: var(--color-brand-deep);
  font-size: 15px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.org-row__headcount span {
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 500;
}

.org-side {
  min-width: 0;
}

.org-move-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.org-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.impact-headline h3 {
  margin: 3px 0;
  font-size: 17px;
  font-weight: 650;
}

.impact-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 7px;
}

.impact-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 11px 4px;
  text-align: center;
  background: var(--color-canvas);
  border-radius: 6px;
}

.impact-item strong {
  font-size: 21px;
  font-variant-numeric: tabular-nums;
}

.impact-item span {
  color: var(--color-ink-muted);
  font-size: 10px;
}

@media (max-width: 1080px) {
  .org-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .impact-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
