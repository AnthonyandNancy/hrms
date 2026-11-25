<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import type { ApiSafeLink } from '@/composables/useApiFeedback'

const props = withDefaults(
  defineProps<{
    title?: string
    description?: string
    requestId?: string | null
    links?: ApiSafeLink[]
  }>(),
  {
    title: '当前账号未绑定员工',
    description: '员工自助功能不可用。你仍可以通过治理视图查看员工数据或管理账号关联。',
    requestId: null,
    links: () => [],
  },
)

const router = useRouter()
const safeLinks = computed(() => props.links.filter((link) => link.path !== ''))

function go(path: string): void {
  void router.push(path)
}
</script>

<template>
  <section class="state-panel empty-state" data-testid="no-active-employment" role="status" aria-live="polite">
    <div class="state-panel__mark" aria-hidden="true">—</div>
    <div class="state-panel__content">
      <h2 class="state-panel__title">{{ title }}</h2>
      <p class="state-panel__body">{{ description }}</p>
      <p v-if="requestId" class="state-panel__request" data-testid="request-id">
        请求编号：<span class="mono">{{ requestId }}</span>
      </p>
      <div v-if="safeLinks.length > 0" class="no-employment-links">
        <el-button v-for="link in safeLinks" :key="link.path" plain @click="go(link.path)">
          {{ link.label }}
        </el-button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.no-employment-links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}
</style>
