<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('account')
if (config === null) throw new Error("Missing platform page config for account")
</script>

<template>
  <div class="account-security-page">
    <aside class="account-security-page__posture" role="note">
      <strong>密码登录为唯一必需方式。</strong>
      多因素认证是可选的纵深防御能力；未启用时不会阻止账号密码登录，也不会改变角色能力校验。
    </aside>
    <PlatformWorkspace :config="config" />
  </div>
</template>

<style scoped>
.account-security-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.account-security-page__posture {
  padding: 11px 14px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  line-height: 1.65;
  background: var(--color-brand-soft, #eef5f3);
  border: 1px solid #cfe0da;
  border-radius: 7px;
}

.account-security-page__posture strong {
  color: var(--color-brand-deep, #0f4956);
}
</style>
