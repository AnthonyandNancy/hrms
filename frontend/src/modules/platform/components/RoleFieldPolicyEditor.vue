<script setup lang="ts">
import { computed, ref, watch } from 'vue'

export type FieldPolicyGrant = {
  capability: string
  field_key?: string | null
  field_profile: string
}

export type FieldPolicyCapability = {
  code: string
  field_profile_max?: string
}

const PROFILES = ['P0_PUBLIC', 'P1_INTERNAL', 'P2_SENSITIVE', 'P3_RESTRICTED'] as const
type FieldProfile = (typeof PROFILES)[number]

const PROFILE_LABELS: Record<FieldProfile, string> = {
  P0_PUBLIC: 'P0 公开',
  P1_INTERNAL: 'P1 内部',
  P2_SENSITIVE: 'P2 敏感',
  P3_RESTRICTED: 'P3 受限',
}

const props = defineProps<{
  grants: FieldPolicyGrant[]
  capabilities: FieldPolicyCapability[]
  originalProfiles?: Record<string, string>
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update-field-profile': [payload: { capability: string; field_key: string; field_profile: FieldProfile }]
}>()

const drafts = ref<Record<string, FieldProfile>>({})
const baseline = ref<Record<string, FieldProfile>>({})

watch(
  () => [props.grants, props.originalProfiles] as const,
  () => {
    const next: Record<string, FieldProfile> = {}
    for (const grant of props.grants) {
      next[grant.capability] = normalize(props.originalProfiles?.[grant.capability] ?? grant.field_profile)
    }
    baseline.value = next
    drafts.value = {}
  },
  { immediate: true, deep: true },
)

const capabilityByCode = computed(() => new Map(props.capabilities.map((row) => [row.code, row])))
const changedRows = computed(() =>
  props.grants
    .filter((grant) => profileOf(grant) !== (baseline.value[grant.capability] ?? normalize(grant.field_profile)))
    .map((grant) => ({
      capability: grant.capability,
      field_key: grant.field_key ?? grant.capability,
      field_profile: profileOf(grant),
    })),
)

function profileOf(grant: FieldPolicyGrant): FieldProfile {
  return drafts.value[grant.capability] ?? normalize(grant.field_profile)
}

function normalize(value: string): FieldProfile {
  if ((PROFILES as readonly string[]).includes(value)) return value as FieldProfile
  // P3_RESTRICTED predates the Volume 2 decision; render it as the single
  // high-sensitivity profile without writing the legacy spelling.
  return value === 'P3_RESTRICTED' ? 'P3_RESTRICTED' : 'P0_PUBLIC'
}

function ceilingOf(capability: string): FieldProfile {
  return normalize(capabilityByCode.value.get(capability)?.field_profile_max ?? 'P0_PUBLIC')
}

function exceedsCeiling(grant: FieldPolicyGrant): boolean {
  return PROFILES.indexOf(profileOf(grant)) > PROFILES.indexOf(ceilingOf(grant.capability))
}

function change(grant: FieldPolicyGrant, event: Event): void {
  if (props.disabled === true) return
  const value = (event.target as HTMLSelectElement).value
  if (!(PROFILES as readonly string[]).includes(value)) return
  drafts.value[grant.capability] = value as FieldProfile
  // Emit on every valid change, including reverting to the original profile;
  // the parent owns the diff and must see the current selection.
  emit('update-field-profile', {
    capability: grant.capability,
    field_key: grant.field_key ?? grant.capability,
    field_profile: value as FieldProfile,
  })
}
</script>

<template>
  <section class="role-field-policy-editor" data-testid="role-field-policy-editor">
    <p class="muted small">
      字段档位按 P0 公开 / P1 内部 / P2 敏感 / P3 高敏选择。超过能力上限时页面会提示，服务端仍会最终校验。
      只提交与当前授权不同的字段行。
    </p>

    <p v-if="props.grants.length === 0" class="empty-inline">尚未选择动作，暂无字段权限可配置。</p>

    <div v-else class="platform-table-wrap">
      <table class="platform-table">
        <caption class="visually-hidden">角色字段档位</caption>
        <thead>
          <tr>
            <th scope="col">字段键 / 能力</th>
            <th scope="col">能力上限</th>
            <th scope="col">字段档位</th>
            <th scope="col">校验</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="grant in props.grants" :key="grant.capability" :data-field-capability="grant.capability">
            <td>
              <div class="mono">{{ grant.field_key ?? grant.capability }}</div>
              <div v-if="grant.field_key !== undefined && grant.field_key !== null" class="small muted">{{ grant.capability }}</div>
            </td>
            <td>{{ PROFILE_LABELS[ceilingOf(grant.capability)] }}</td>
            <td>
              <select
                :data-testid="`field-profile-${grant.capability}`"
                :value="profileOf(grant)"
                :disabled="props.disabled"
                @change="change(grant, $event)"
              >
                <option v-for="profile in PROFILES" :key="profile" :value="profile">
                  {{ PROFILE_LABELS[profile] }}
                </option>
              </select>
            </td>
            <td>
              <span v-if="exceedsCeiling(grant)" class="inline-warning" :data-testid="`field-ceiling-warning-${grant.capability}`">
                所选 {{ PROFILE_LABELS[profileOf(grant)] }} 超过能力上限 {{ PROFILE_LABELS[ceilingOf(grant.capability)] }}，保存会被服务端拒绝。
              </span>
              <span v-else class="muted small">未超过能力上限</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="changedRows.length > 0" class="small muted" data-testid="field-changed-count">
      待提交字段变更：{{ changedRows.length }} 行（仅提交变更行）。
    </p>
  </section>
</template>

<style scoped>
.role-field-policy-editor {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
</style>
