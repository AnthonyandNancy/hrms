<script setup lang="ts">
import type { TalentPageConfig } from './types'

defineProps<{
  page: TalentPageConfig
  actionCount: number
}>()

const domainCopy = {
  performance: '绩效考核',
  'recruitment-requests': '招聘需求',
  'recruitment-candidates': '候选人',
  training: '培训管理',
} as const
</script>

<template>
  <div class="page talent-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">{{ domainCopy[page.domain] }} · {{ page.group }}</p>
        <h1 class="page-header__title">{{ page.title }}</h1>
        <p class="page-header__description">{{ page.description }}</p>
      </div>
      <div class="page-header__actions">
        <span class="talent-page__scope">读取 {{ page.readLabel }}</span>
        <span v-if="actionCount > 0" class="talent-page__scope">{{ actionCount }} 个写入动作</span>
      </div>
    </header>

    <div class="talent-workspace">
      <aside class="talent-workspace__rail" aria-label="业务域导航">
        <p class="talent-workspace__rail-label">Talent 域</p>
        <ul class="talent-workspace__rail-list">
          <li class="talent-workspace__rail-item talent-workspace__rail-item--active">
            <span class="talent-workspace__rail-dot" aria-hidden="true" />
            <span>{{ page.title }}</span>
          </li>
        </ul>
        <p class="talent-workspace__rail-note">
          页面只展示实际接口返回的数据。无后端能力的区域会列出计划路径，不生成演示行。
        </p>
      </aside>

      <div class="talent-workspace__main">
        <slot name="states" />
        <slot name="data" />
        <slot name="forms" />
        <slot name="panels" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.talent-page {
  gap: 16px;
}

.talent-page__scope {
  padding: 4px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}

.talent-workspace {
  display: grid;
  grid-template-columns: 210px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.talent-workspace__rail {
  position: sticky;
  top: calc(var(--topbar-height) + 12px);
  padding: 14px;
  background: linear-gradient(165deg, #153f49, #0d313a);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.talent-workspace__rail-label {
  margin-bottom: 10px;
  color: #9dbcb8;
  font-size: 10px;
  font-weight: 650;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.talent-workspace__rail-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.talent-workspace__rail-item {
  display: flex;
  align-items: center;
  gap: 9px;
  min-height: 36px;
  padding: 0 9px;
  color: #e4efec;
  font-size: 12.5px;
  border-radius: 6px;
}

.talent-workspace__rail-item--active {
  background: rgb(255 255 255 / 11%);
  box-shadow: inset 3px 0 0 #e1b95f;
}

.talent-workspace__rail-dot {
  width: 7px;
  height: 7px;
  background: #e1b95f;
  border-radius: 50%;
}

.talent-workspace__rail-note {
  margin-top: 14px;
  padding-top: 12px;
  color: #a7c1bd;
  font-size: 11px;
  line-height: 1.6;
  border-top: 1px solid rgb(255 255 255 / 12%);
}

.talent-workspace__main {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 14px;
}

@media (max-width: 900px) {
  .talent-workspace {
    grid-template-columns: 1fr;
  }

  .talent-workspace__rail {
    position: static;
  }

  .talent-workspace__rail-note {
    display: none;
  }
}
</style>
