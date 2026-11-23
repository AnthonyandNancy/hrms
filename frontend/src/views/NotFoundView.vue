<script setup lang="ts">
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<template>
  <div class="not-found">
    <div class="not-found__panel">
      <p class="not-found__code">404</p>
      <h1>页面不存在</h1>
      <p>链接可能已失效，或记录被撤回、移出你的数据范围。</p>
      <el-button type="primary" @click="router.push('/workspace')">返回工作台</el-button>
    </div>
  </div>
</template>

<style scoped>
.not-found {
  display: grid;
  min-height: 100vh;
  padding: 24px;
  place-items: center;
  background: var(--color-canvas);
}

.not-found__panel {
  max-width: 440px;
  padding: 32px;
  text-align: center;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.not-found__code {
  color: var(--color-brand);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.2em;
}

.not-found h1 {
  margin: 8px 0;
  font-size: 26px;
}

.not-found p {
  margin-bottom: 20px;
  color: var(--color-ink-secondary);
  font-size: 13px;
}
</style>
