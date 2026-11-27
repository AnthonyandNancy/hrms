<script setup lang="ts">
import { computed } from 'vue'
import {
  orgUnitName,
  positionGradeLabel,
  positionStatusLabel,
  type OrgUnitOption,
  type Position,
  type PositionGrade,
} from '../api'
import { DISPLAY_CODES } from '@/shared/display/dictionary'

const props = withDefaults(
  defineProps<{
    rows: readonly Position[]
    total: number
    page: number
    pageSize?: number
    orgUnits?: readonly OrgUnitOption[]
    grades?: readonly PositionGrade[]
  }>(),
  { orgUnits: () => [], grades: () => [], pageSize: 10 },
)

const emit = defineEmits<{
  'update:page': [page: number]
  view: [row: Position]
}>()

const pageSize = computed(() => props.pageSize ?? 10)
const pageCount = computed(() => Math.max(1, Math.ceil(props.total / pageSize.value)))

function headcountLimit(row: Position): string {
  return row.headcount_limit === null ? '未设置' : `${row.headcount_limit} 人`
}

function occupancyText(row: Position): string {
  return row.occupancy === null ? '—' : `${row.occupancy} 人`
}

function vacanciesText(row: Position): string {
  return row.vacancies === null ? '—' : `${row.vacancies} 人`
}

function overLimit(row: Position): boolean {
  return (
    row.headcount_limit !== null &&
    row.occupancy !== null &&
    row.occupancy > row.headcount_limit
  )
}

function varianceText(row: Position): string {
  if (row.headcount_limit === null || row.occupancy === null) return ''
  const excess = row.occupancy - row.headcount_limit
  return `在岗 ${row.occupancy} 人，超过核定编制 ${excess} 人；如需调整在岗人员，请走员工异动流程。`
}

function statusTone(row: Position): string {
  if (row.status === DISPLAY_CODES.positionStatus.active) return 'active'
  if (row.status === DISPLAY_CODES.positionStatus.disabled) return 'disabled'
  return 'unknown'
}

function previousPage(): void {
  if (props.page > 1) emit('update:page', props.page - 1)
}

function nextPage(): void {
  if (props.page < pageCount.value) emit('update:page', props.page + 1)
}
</script>

<template>
  <section class="position-table" data-testid="position-table">
    <div class="position-table__scroller" role="region" aria-label="岗位列表，可横向滚动" tabindex="0">
      <table class="position-table__table">
        <caption class="visually-hidden">
          岗位列表，共 {{ total }} 条，第 {{ page }} 页，共 {{ pageCount }} 页
        </caption>
        <thead>
          <tr>
            <th scope="col">岗位编码</th>
            <th scope="col">岗位名称</th>
            <th scope="col">部门</th>
            <th scope="col">职级</th>
            <th scope="col">编制（核定 / 在岗 / 空缺）</th>
            <th scope="col">状态</th>
            <th scope="col">编制差异</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in rows"
            :key="row.id"
            :data-testid="`position-row-${row.id}`"
            :class="{ 'position-table__row--selected': false }"
          >
            <td class="mono">
              <span :data-testid="`position-code-${row.id}`">{{ row.code }}</span>
            </td>
            <td>
              <span :data-testid="`position-title-${row.id}`">{{ row.title }}</span>
            </td>
            <td>
              <span :data-testid="`position-org-${row.id}`">
                {{ orgUnitName(row.org_unit_id, orgUnits) }}
              </span>
            </td>
            <td>
              <span :data-testid="`position-grade-${row.id}`">
                {{ positionGradeLabel(row.grade, grades) }}
              </span>
            </td>
            <td :data-testid="`position-headcount-${row.id}`">
              <span>核定 {{ headcountLimit(row) }}</span>
              <span class="position-table__separator">·</span>
              <span>在岗 {{ occupancyText(row) }}</span>
              <span class="position-table__separator">·</span>
              <span>空缺 {{ vacanciesText(row) }}</span>
            </td>
            <td>
              <span
                :data-testid="`position-status-${row.id}`"
                class="position-table__status"
                :class="`position-table__status--${statusTone(row)}`"
              >
                {{ positionStatusLabel(row.status) }}
              </span>
            </td>
            <td>
              <span
                v-if="overLimit(row)"
                :data-testid="`position-variance-${row.id}`"
                class="position-table__variance"
              >
                {{ varianceText(row) }}
              </span>
              <span v-else class="position-table__muted">—</span>
            </td>
            <td>
              <button
                type="button"
                class="position-table__view"
                :data-testid="`position-row-view-${row.id}`"
                @click="emit('view', row)"
              >
                查看
              </button>
            </td>
          </tr>
          <tr v-if="rows.length === 0">
            <td colspan="8" class="position-table__empty">当前筛选条件下没有岗位。</td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="position-table__footer">
      <span>共 {{ total }} 条记录</span>
      <nav class="position-table__pager" aria-label="岗位分页">
        <button
          type="button"
          class="position-table__page-button"
          data-testid="position-page-prev"
          :disabled="page <= 1"
          @click="previousPage"
        >
          上一页
        </button>
        <span class="position-table__page-indicator" data-testid="position-page-indicator">
          第 {{ page }} / {{ pageCount }} 页
        </span>
        <button
          type="button"
          class="position-table__page-button"
          data-testid="position-page-next"
          :disabled="page >= pageCount"
          @click="nextPage"
        >
          下一页
        </button>
      </nav>
    </footer>
  </section>
</template>

<style scoped>
.position-table {
  overflow: hidden;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.position-table__scroller {
  overflow-x: auto;
}

.position-table__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.position-table__table th,
.position-table__table td {
  padding: 9px 11px;
  text-align: left;
  vertical-align: top;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-line);
}

.position-table__table th {
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
  background: var(--color-canvas);
}

.position-table__table tbody tr:hover {
  background: var(--color-brand-soft);
}

.position-table__separator {
  margin: 0 3px;
  color: var(--color-ink-muted);
}

.position-table__status {
  display: inline-block;
  padding: 2px 7px;
  font-size: 11px;
  border-radius: 999px;
}

.position-table__status--active {
  color: var(--color-success);
  background: var(--color-success-soft);
}

.position-table__status--disabled {
  color: var(--color-ink-muted);
  background: var(--color-canvas);
}

.position-table__status--unknown {
  color: var(--color-warning);
  background: var(--color-warning-soft);
}

.position-table__variance {
  display: inline-block;
  max-width: 260px;
  color: var(--color-warning);
  font-size: 11px;
  white-space: normal;
}

.position-table__muted,
.position-table__empty {
  color: var(--color-ink-muted);
}

.position-table__empty {
  padding: 22px;
  text-align: center;
}

.position-table__view {
  padding: 3px 9px;
  color: var(--color-brand);
  font-size: 11px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.position-table__footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.position-table__pager {
  display: flex;
  gap: 8px;
  align-items: center;
}

.position-table__page-button {
  padding: 4px 9px;
  color: var(--color-brand);
  font-size: 11px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.position-table__page-button:disabled {
  color: var(--color-ink-muted);
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
