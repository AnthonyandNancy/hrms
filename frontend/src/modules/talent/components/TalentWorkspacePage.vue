<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { usePageError } from '@/composables/usePageError'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'
import TalentPage from '../TalentPage.vue'
import TalentForm from '../TalentForm.vue'
import { readTalentList, rowHasEmail, rowId, rowText } from '../talentRead'
import type { TalentFormKind, TalentPageConfig, TalentRow } from '../types'

const props = defineProps<{
  config: TalentPageConfig
}>()

const route = useRoute()
const pageError = usePageError()
const loading = ref(false)
const loaded = ref(false)
const rows = ref<TalentRow[]>([])
const total = ref(0)
const partial = ref(false)
const actionRows = ref<TalentRow[]>([])


const scopedCandidateId = ref<string>(typeof route.query.candidate_id === 'string' ? route.query.candidate_id : '')

const routeFilter = computed<Record<string, unknown>>(() => {
  if (props.config.routeFilterKey === undefined) return {}
  const routeValue = route.params.id
  const value = typeof routeValue === 'string' && /^\d+$/.test(routeValue) ? routeValue : scopedCandidateId.value
  if (!/^\d+$/.test(value)) return { [props.config.routeFilterKey]: null }
  return { [props.config.routeFilterKey]: Number(value) }
})

const columns = computed(() => props.config.columns)
const isPartial = computed(() => partial.value || props.config.pending !== undefined)
const routeId = computed(() => {
  const value = route.params.id
  return typeof value === 'string' ? value : undefined
})
const formKinds = computed<readonly TalentFormKind[]>(() => props.config.forms)

function numericCell(row: TalentRow, key: string): number {
  const value = row[key]
  return typeof value === 'number' && Number.isFinite(value) ? value : 0
}

const trainingSummary = computed<{ title: string; items: Array<{ label: string; value: string }> } | null>(() => {
  const current = props.config
  if (current.domain !== 'training' || rows.value.length === 0) return null

  const sum = (key: string): number => rows.value.reduce((total, row) => total + numericCell(row, key), 0)

  switch (current.key) {
    case 'training/index':
      return {
        title: '计划汇总（实时）',
        items: [
          { label: '计划数量', value: String(rows.value.length) },
          { label: '预算合计（整数分）', value: String(sum('budget_cents')) },
        ],
      }
    case 'training/courses':
      return {
        title: '课程汇总（实时）',
        items: [
          { label: '课程数量', value: String(rows.value.length) },
          { label: '成本合计（整数分）', value: String(sum('cost_cents')) },
          { label: '总时长（分钟）', value: String(sum('duration_minutes')) },
        ],
      }
    case 'training/checkins':
      return {
        title: '签到汇总（实时）',
        items: [
          { label: '签到条数', value: String(rows.value.length) },
          { label: '学时合计（分钟）', value: String(sum('study_minutes')) },
        ],
      }
    case 'training/exams':
      return {
        title: '考试版本汇总（实时）',
        items: [
          { label: '版本数量', value: String(rows.value.length) },
          {
            label: '已发布版本',
            value: String(rows.value.filter((row) => row.status === 'PUBLISHED').length),
          },
        ],
      }
    case 'training/feedback':
      return {
        title: '反馈汇总（实时）',
        items: [
          { label: '反馈条数', value: String(rows.value.length) },
          {
            label: '平均满意度',
            value: (() => {
              const rated = rows.value.filter((row) => typeof row.rating === 'number')
              if (rated.length === 0) return '—'
              return (rated.reduce((total, row) => total + numericCell(row, 'rating'), 0) / rated.length).toFixed(1)
            })(),
          },
        ],
      }
    case 'training/records':
      return {
        title: '档案汇总（实时）',
        items: [
          { label: '档案条数', value: String(rows.value.length) },
          { label: '学时合计（分钟）', value: String(sum('study_minutes')) },
          { label: '反馈合计', value: String(sum('feedback_count')) },
        ],
      }
    case 'training/reports':
      return {
        title: '报表汇总（实时）',
        items: [
          { label: '计划数量', value: String(rows.value.length) },
          { label: '报名合计', value: String(sum('enrolled_count')) },
          { label: '完成合计', value: String(sum('completed_count')) },
          { label: '学时合计（分钟）', value: String(sum('study_minutes_total')) },
        ],
      }
    default:
      return null
  }
})

function displayCell(row: TalentRow, column: string): string {
  if (column === 'has_email') return rowHasEmail(row) ? '已登记' : '未登记'
  return rowText(row, column)
}

function normaliseCandidate(row: TalentRow): TalentRow {
  return {
    candidate_id: row.candidate_id,
    display_name: row.display_name,
    status: row.status,
    has_email: row.has_email,
  }
}

function normalisedRow(row: TalentRow): TalentRow {
  if (props.config.domain !== 'recruitment-candidates') return row
  const key = props.config.key
  if (key !== 'recruitment/candidates/index' && key !== 'recruitment/candidates/by-id') return row
  return normaliseCandidate(row)
}

async function load(): Promise<void> {
  const current = props.config
  if (current === null) return

  pageError.clear()
  loaded.value = false
  loading.value = true
  try {
    const filterId = routeFilter.value.candidate_id
    if (current.readPath.includes('{id}') && typeof filterId !== 'number') {
      rows.value = []
      total.value = 0
      partial.value = false
      return
    }
    const readPath =
      typeof filterId === 'number' && current.readPath.includes('{id}')
        ? current.readPath.replace('{id}', String(filterId))
        : current.readPath
    const result = await readTalentList(readPath, routeFilter.value, normalisedRow)
    const filterKey = current.routeFilterKey
    const filterValue = filterKey === undefined ? null : routeFilter.value[filterKey]
    const filteredRows =
      filterKey !== undefined && typeof filterValue === 'number'
        ? result.rows.filter((row) => rowId(row, filterKey) === String(filterValue))
        : result.rows
    rows.value = filteredRows
    total.value = filterKey !== undefined && typeof filterValue === 'number' ? filteredRows.length : result.total
    partial.value = result.partial
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function onCreated(row: TalentRow): void {
  if (rowId(row, 'enrollment_id') !== '') {
    const id = rowId(row, 'enrollment_id')
    const existing = actionRows.value.findIndex((item) => rowId(item, 'enrollment_id') === id)
    if (existing >= 0) {
      actionRows.value[existing] = row
    } else {
      actionRows.value = [...actionRows.value, row]
    }
    return
  }
  if (rowId(row, 'template_id') !== '' || rowId(row, 'cycle_id') !== '' || rowId(row, 'program_id') !== '') {
    actionRows.value = [...actionRows.value, row]
  }
}

onMounted(load)
</script>

<template>
  <TalentPage :page="config" :action-count="formKinds.length">
    <template #states>
      <NoActiveEmploymentState
        v-if="pageError.noActiveEmployment.value"
        :request-id="pageError.requestId.value"
        :links="pageError.safeLinks.value"
      />
      <ErrorState
        v-else-if="pageError.message.value"
        :title="`${config.title}暂时无法加载`"
        :description="pageError.message.value"
        :code="pageError.code.value"
        :request-id="pageError.requestId.value"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="5" :label="`正在加载${config.title}`" />
      <EmptyState
        v-else-if="loaded && rows.length === 0"
        :title="`暂无${config.readLabel}`"
        :description="`${config.readLabel}接口当前没有记录；请先在对应业务动作中创建，再返回查看。`"
      />
    </template>

    <template #data>
      <form
        v-if="config.routeFilterKey === 'candidate_id'"
        class="panel talent-scope"
        data-testid="candidate-scope"
        @submit.prevent="load"
      >
        <label class="talent-field">
          候选人 ID
          <input v-model="scopedCandidateId" data-testid="candidate-scope-id" inputmode="numeric" required />
        </label>
        <button type="submit" :disabled="loading" data-testid="candidate-scope-load">读取候选人子资源</button>
      </form>

      <section
        v-if="pageError.message.value === '' && rows.length > 0"
        class="panel talent-data-panel"
        :data-page="config.key"
      >
        <header class="panel__header">
          <div>
            <h2 class="panel__title">{{ config.readLabel }}</h2>
            <p class="panel__subtitle">共 {{ total }} 条记录 · 实时读取 {{ config.readPath }}</p>
          </div>
          <button type="button" class="talent-refresh" :disabled="loading" @click="load">刷新</button>
        </header>
        <div class="panel__body panel__body--flush">
          <div class="talent-table-wrap">
            <table class="talent-table">
              <caption class="visually-hidden">{{ config.title }}数据表</caption>
              <thead>
                <tr>
                  <th v-for="column in columns" :key="column" scope="col">{{ column }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(row, index) in rows"
                  :key="`${config.key}-${index}`"
                  :data-testid="`${config.domain}-row-${index}`"
                >
                  <td v-for="column in columns" :key="column">{{ displayCell(row, column) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <footer v-if="isPartial" class="panel__footer">
          <span class="talent-partial" data-testid="talent-partial">
            部分数据：{{ config.pending ? '本页存在尚未上线的后端能力，停用区域不会展示伪造数据。' : '服务端标记本次响应不完整，请结合请求编号联系管理员。' }}
          </span>
        </footer>
      </section>

      <section
        v-if="trainingSummary"
        class="panel talent-lifecycle-summary"
        data-testid="lifecycle-summary"
      >
        <header class="panel__header">
          <div>
            <h2 class="panel__title">{{ trainingSummary.title }}</h2>
            <p class="panel__subtitle">数字均由本次服务端返回行实时计算，不缓存推算结果。</p>
          </div>
        </header>
        <div class="panel__body">
          <dl class="talent-lifecycle-summary__grid">
            <div v-for="item in trainingSummary.items" :key="item.label" class="talent-lifecycle-summary__item">
              <dt>{{ item.label }}</dt>
              <dd>{{ item.value }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section v-if="actionRows.length > 0" class="panel talent-action-panel">
        <header class="panel__header">
          <div>
            <h2 class="panel__title">本次操作结果</h2>
            <p class="panel__subtitle">仅展示当前会话内服务端已确认的写入结果</p>
          </div>
        </header>
        <div class="panel__body panel__body--flush">
          <ul class="talent-action-list">
            <li
              v-for="(row, index) in actionRows"
              :key="`action-${index}`"
              :data-testid="rowId(row, 'enrollment_id') !== '' ? `enrollment-row-${rowId(row, 'enrollment_id')}` : `action-row-${index}`"
            >
              <span v-if="rowId(row, 'enrollment_id') !== ''">报名编号 {{ rowId(row, 'enrollment_id') }}</span>
              <span v-else-if="rowId(row, 'template_id') !== ''">模板编号 {{ rowId(row, 'template_id') }}</span>
              <span v-else-if="rowId(row, 'cycle_id') !== ''">周期编号 {{ rowId(row, 'cycle_id') }}</span>
              <span v-else-if="rowId(row, 'program_id') !== ''">培训项目编号 {{ rowId(row, 'program_id') }}</span>
              <span v-else>服务端已确认</span>
              <span class="talent-action-list__meta">
                {{ rowText(row, 'status') === '—' ? '' : rowText(row, 'status') }}
                {{ rowText(row, 'channel') === '—' ? '' : `· ${rowText(row, 'channel')}` }}
              </span>
            </li>
          </ul>
        </div>
      </section>
    </template>

    <template #partial>
      <p v-if="isPartial && rows.length === 0" class="talent-partial talent-partial--standalone" data-testid="talent-partial">
        部分数据：本页存在尚未上线的后端能力，停用区域不会展示伪造数据。
      </p>
    </template>

    <template #forms>
      <TalentForm
        v-for="kind in formKinds"
        :key="kind"
        :kind="kind"
        :route-id="routeId ?? (scopedCandidateId !== '' ? scopedCandidateId : undefined)"
        @created="onCreated"
      />
    </template>

    <template #panels>
      <section v-if="config.pending" class="panel talent-pending" data-testid="backend-pending">
        <header class="panel__header">
          <div>
            <h2 class="panel__title">{{ config.pending.label }}：后端接口待补</h2>
            <p class="panel__subtitle">计划路径 <span class="mono">{{ config.pending.plannedPath }}</span></p>
          </div>
          <span class="talent-pending__badge">未上线</span>
        </header>
        <div class="panel__body">
          <p class="talent-pending__detail">{{ config.pending.detail }}</p>
          <p class="talent-pending__rule">
            此区域不展示推算、占位或演示数据；后端接口上线后再接入真实读取与动作。
          </p>
        </div>
      </section>

      <section
        v-for="panel in config.panels"
        :key="panel.title"
        class="panel talent-guidance"
      >
        <header class="panel__header">
          <div>
            <h2 class="panel__title">{{ panel.title }}</h2>
            <p class="panel__subtitle">{{ panel.body }}</p>
          </div>
        </header>
        <div class="panel__body">
          <ul class="talent-guidance__list">
            <li v-for="point in panel.points" :key="point">{{ point }}</li>
          </ul>
        </div>
      </section>
    </template>
  </TalentPage>
</template>

<style scoped>
.talent-data-panel,
.talent-action-panel,
.talent-pending,
.talent-guidance {
  overflow: hidden;
}

.talent-scope {
  display: flex;
  align-items: end;
  gap: 12px;
  padding: 14px 17px;
}

.talent-scope .talent-field {
  margin: 0;
}

.talent-refresh {
  min-height: 32px;
  padding: 6px 12px;
  color: var(--color-brand);
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.talent-table-wrap {
  overflow: auto;
}

.talent-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.talent-table th,
.talent-table td {
  padding: 10px 12px;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line);
}

.talent-table th {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 650;
  background: #fafcfb;
}

.talent-table tbody tr:hover {
  background: #f8fbf9;
}

.talent-action-list {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.talent-action-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 11px 17px;
  font-size: 12.5px;
  border-bottom: 1px solid var(--color-line);
}

.talent-action-list li:last-child {
  border-bottom: 0;
}

.talent-action-list__meta {
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.talent-partial {
  color: var(--color-warning);
  font-size: 12px;
  font-weight: 600;
}

.talent-partial--standalone {
  margin: 0;
  padding: 11px 14px;
  background: var(--color-warning-soft);
  border: 1px solid #e6cf9f;
  border-radius: var(--radius-sm);
}

.talent-pending {
  border-color: #e6cf9f;
  background: #fffdf7;
}

.talent-pending__badge {
  padding: 3px 8px;
  color: var(--color-warning);
  font-size: 11px;
  font-weight: 650;
  background: var(--color-warning-soft);
  border: 1px solid #e6cf9f;
  border-radius: 999px;
}

.talent-pending__detail {
  color: var(--color-ink-secondary);
  font-size: 12.5px;
}

.talent-pending__rule {
  margin-top: 8px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.talent-guidance__list {
  display: grid;
  gap: 6px;
  margin: 0;
  padding-left: 18px;
  color: var(--color-ink-secondary);
  font-size: 12px;
}

.talent-lifecycle-summary__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
  margin: 0;
}

.talent-lifecycle-summary__item {
  padding: 12px;
  background: #f7fbfa;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.talent-lifecycle-summary__item dt {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 650;
}

.talent-lifecycle-summary__item dd {
  margin-top: 4px;
  color: var(--color-ink);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 18px;
  font-weight: 700;
}
</style>
