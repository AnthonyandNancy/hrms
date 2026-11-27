<script setup lang="ts">
import { computed, ref } from 'vue'
import { useSessionStore } from '@/stores/session'

const session = useSessionStore()
const employmentId = ref<number | null>(session.principal?.employmentId ?? null)

const filesRoute = computed(() =>
  employmentId.value !== null && Number.isSafeInteger(employmentId.value) && employmentId.value > 0
    ? `/hr/employees/${employmentId.value}/files`
    : null,
)
</script>

<template>
  <section class="settings-tab" data-testid="settings-file" aria-labelledby="settings-file-title">
    <div class="settings-tab__header">
      <div>
        <h2 id="settings-file-title" class="panel__title">员工文件</h2>
        <p class="panel__subtitle">文件接口按员工任职范围提供，不存在跨员工的全量文件列表；页面不伪造文件行或容量。</p>
      </div>
    </div>

    <section class="settings-subpanel" data-testid="settings-files">
      <div class="settings-file-row">
        <label class="settings-field">
          <span>任职编号</span>
          <el-input-number
            v-model="employmentId"
            :min="1"
            :controls="false"
            data-testid="settings-files-employment"
            aria-label="任职编号"
          />
        </label>
        <router-link
          v-if="filesRoute"
          :to="filesRoute"
          class="settings-files__link"
          data-testid="settings-files-link"
        >
          打开员工附件页
        </router-link>
        <span v-else class="muted small">输入有效任职编号后打开员工附件页。</span>
      </div>
      <p class="muted small">附件列表与上传都由员工附件服务提供，需先选择任职记录。文件容量与全局访问策略尚无 API，本页不展示虚构开关。</p>
    </section>
  </section>
</template>

<style scoped>
.settings-tab {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.settings-tab__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.settings-subpanel {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}
.settings-file-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
}
.settings-field {
  display: flex;
  max-width: 180px;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.settings-files__link {
  padding: 6px 10px;
  color: var(--color-brand-deep, #0f4956);
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  border: 1px solid #a8d2dc;
  border-radius: 6px;
}
</style>
