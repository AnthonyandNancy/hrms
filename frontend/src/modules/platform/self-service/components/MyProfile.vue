<script setup lang="ts">
import { computed } from 'vue'
import type { SelfServiceProfile } from '../api'

const props = defineProps<{
  profile: SelfServiceProfile | null
  employmentLinked: boolean
}>()

type FieldKey =
  | 'employeeNo'
  | 'name'
  | 'jobTitle'
  | 'orgName'
  | 'managerName'
  | 'workLocation'
  | 'email'
  | 'phone'

type Field = {
  key: FieldKey
  label: string
  sensitive: boolean
}

const FIELDS: readonly Field[] = [
  { key: 'employeeNo', label: '工号', sensitive: false },
  { key: 'name', label: '姓名', sensitive: false },
  { key: 'jobTitle', label: '岗位', sensitive: false },
  { key: 'orgName', label: '组织', sensitive: false },
  { key: 'managerName', label: '直属经理', sensitive: false },
  { key: 'workLocation', label: '工作地点', sensitive: false },
  { key: 'email', label: '邮箱', sensitive: true },
  { key: 'phone', label: '手机号', sensitive: true },
]

const unavailableCopy = '无权查看'

/**
 * A field is rendered only from the server projection. A null value is a real
 * "not recorded" answer; an absent key means the projection withheld the field
 * and must be disclosed as 无权查看 rather than guessed or shown blank.
 */
function fieldState(field: Field): 'value' | 'not-recorded' | 'withheld' {
  const profile = props.profile
  if (profile === null) return 'withheld'

  if (!Object.prototype.hasOwnProperty.call(profile, field.key)) return 'withheld'
  if (field.key === 'phone') {
    return profile.phone === null || profile.phone === undefined ? 'not-recorded' : 'value'
  }
  const value = profile[field.key]
  return value === null || value === undefined || value === '' ? 'not-recorded' : 'value'
}

function valueFor(field: Field): string {
  const profile = props.profile
  if (profile === null) return unavailableCopy

  if (field.key === 'phone') {
    const phone = profile.phone
    if (phone === null || phone === undefined) return '未登记'
    if (phone.display === null || phone.display === '') {
      return phone.masked ? `${unavailableCopy}（字段策略遮蔽）` : '未登记'
    }
    return phone.display
  }

  const value = profile[field.key]
  return value === null || value === undefined || value === '' ? '未登记' : String(value)
}

const visibleFields = computed(() => FIELDS.map((field) => ({ ...field, state: fieldState(field) })))
</script>

<template>
  <section class="panel self-profile" data-testid="my-profile" aria-labelledby="my-profile-title">
    <div class="panel__header">
      <div>
        <h2 id="my-profile-title" class="panel__title">我的档案</h2>
        <p class="panel__subtitle">仅展示服务端投影给当前账号的本人字段；缺失字段一律显示“无权查看”。</p>
      </div>
      <span class="plain-chip">{{ employmentLinked ? '已关联任职' : '未关联任职' }}</span>
    </div>

    <div v-if="profile === null" class="panel__body">
      <p class="profile-empty" data-testid="profile-empty">服务端未返回员工档案。请确认账号已关联有效任职。</p>
    </div>

    <dl v-else class="profile-grid">
      <div
        v-for="field in visibleFields"
        :key="field.key"
        class="profile-grid__item"
        :data-profile-field="field.key"
      >
        <dt>{{ field.label }}</dt>
        <dd :class="{ 'profile-grid__withheld': field.state === 'withheld', 'profile-grid__muted': field.state === 'not-recorded' }">
          <template v-if="field.state === 'withheld'">{{ unavailableCopy }}</template>
          <template v-else>{{ valueFor(field) }}</template>
        </dd>
      </div>
    </dl>

    <p v-if="!employmentLinked" class="profile-note" role="note">
      当前账号未关联有效任职。自助服务只展示本人数据；如需处理业务，请联系人事管理员完成任职关联。
    </p>
  </section>
</template>

<style scoped>
.profile-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0;
  margin: 0;
}

.profile-grid__item {
  padding: 14px 18px;
  border-top: 1px solid var(--color-line);
}

.profile-grid__item:nth-child(-n + 2) {
  border-top: 0;
}

.profile-grid__item dt {
  color: var(--color-ink-muted);
  font-size: 12px;
}

.profile-grid__item dd {
  margin: 5px 0 0;
  color: var(--color-ink);
  font-size: 14px;
  font-weight: 600;
  overflow-wrap: anywhere;
}

.profile-grid__withheld {
  color: var(--color-ink-muted);
  font-weight: 500;
}

.profile-grid__muted {
  color: var(--color-ink-muted);
  font-weight: 400;
}

.profile-empty {
  margin: 0;
  padding: 18px;
  color: var(--color-ink-muted);
}

.profile-note {
  margin: 0;
  padding: 12px 18px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  background: var(--color-brand-soft, #eef5f3);
  border-top: 1px solid #cfe0da;
}
</style>
