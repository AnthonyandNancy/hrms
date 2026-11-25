<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import PageErrorBoundary from '@/components/PageErrorBoundary.vue'
import { isChunkLoadError } from '@/utils/renderErrors'
import {
  ArrowDown,
  Bell,
  Calendar,
  Clock,
  DataAnalysis,
  Document,
  Fold,
  HomeFilled,
  Lock,
  Menu,
  Money,
  Share,
  Stamp,
  User,
  Wallet,
  Expand,
} from '@element-plus/icons-vue'
import { useSessionStore } from '@/stores/session'
import { useAppStore } from '@/stores/app'
import { GROUP_LABEL, type NavigationItem } from '@/router/navigation'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const routeChunkLoadFailed = ref(false)
let removeRouterErrorListener: (() => void) | null = null

function reloadPage(): void {
  window.location.reload()
}

watch(
  () => route.fullPath,
  () => {
    routeChunkLoadFailed.value = false
  },
)

const mfaEnabled = false
const tenantLabel = computed(() => session.principal?.legalEntityName ?? session.principal?.tenantName ?? 'HRMS')

const app = useAppStore()

const icons: Record<string, unknown> = {
  Bell,
  Calendar,
  Clock,
  DataAnalysis,
  Document,
  HomeFilled,
  Lock,
  Money,
  Share,
  Stamp,
  User,
  Wallet,
}

const currentNavKey = computed(() => (typeof route.meta.navKey === 'string' ? route.meta.navKey : ''))
const groupedNavigation = computed(() => {
  const groups = new Map<NavigationItem['group'], NavigationItem[]>()
  for (const item of session.navigation) {
    const list = groups.get(item.group) ?? []
    list.push(item)
    groups.set(item.group, list)
  }
  return [...groups.entries()]
})

const pageTitle = computed(() => (typeof route.meta.title === 'string' ? route.meta.title : 'HRMS'))

function badgeFor(item: NavigationItem): string {
  if (item.badge === 'approvals') return app.approvalBadge
  if (item.badge === 'notices') return app.noticeBadge
  return ''
}

function closeMobileNav(): void {
  app.toggleMobileNav(false)
}

function handleResize(): void {
  if (window.innerWidth > 960) app.toggleMobileNav(false)
}

async function handleCommand(command: string): Promise<void> {
  if (command === 'logout') {
    await ElMessageBox.confirm('确定要退出当前登录吗？未保存的输入将丢失。', '退出登录', {
      confirmButtonText: '退出',
      cancelButtonText: '取消',
      type: 'warning',
    })
    await session.logout()
    await router.push({ name: 'login' })
  } else if (command === 'mfa') {
    await router.push({ name: 'login', query: { mfa: 'enroll' } })
  } else if (command === 'workspace') {
    await router.push({ name: 'workspace' })
  }
}

onMounted(() => {
  window.addEventListener('resize', handleResize)
  // A lazy route component can fail in the router guard before RouterView
  // renders. Keep that failure local to the shell instead of letting it reach
  // the app-level boundary.
  removeRouterErrorListener = router.onError((error) => {
    if (isChunkLoadError(error)) routeChunkLoadFailed.value = true
  })
  void app.loadShellCounters()
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize)
  removeRouterErrorListener?.()
})
</script>

<template>
  <div class="app-shell" :class="{ 'app-shell--collapsed': app.sidebarCollapsed }">
    <a class="skip-link" href="#main-content">跳到主要内容</a>
    <div v-if="app.mobileNavOpen" class="nav-scrim" aria-hidden="true" @click="closeMobileNav" />

    <aside class="sidebar" :class="{ 'sidebar--open': app.mobileNavOpen }" aria-label="主导航">
      <div class="sidebar__brand">
        <span class="sidebar__mark" aria-hidden="true">澄</span>
        <span class="sidebar__brand-copy">
          <span class="sidebar__name">澄明 HR</span>
          <span class="sidebar__tenant">{{ tenantLabel }}</span>
        </span>
        <button
          class="sidebar__collapse"
          type="button"
          :aria-label="app.sidebarCollapsed ? '展开侧边栏' : '收起侧边栏'"
          :aria-expanded="!app.sidebarCollapsed"
          @click="app.toggleSidebar()"
        >
          <el-icon aria-hidden="true">
            <Expand v-if="app.sidebarCollapsed" />
            <Fold v-else />
          </el-icon>
        </button>
      </div>

      <nav class="sidebar__nav">
        <div v-for="[group, items] in groupedNavigation" :key="group" class="sidebar__group">
          <p class="sidebar__group-label">{{ GROUP_LABEL[group] }}</p>
          <RouterLink
            v-for="item in items"
            :key="item.key"
            :to="item.path"
            class="nav-link"
            :class="{ 'nav-link--active': currentNavKey === item.key }"
            :aria-current="currentNavKey === item.key ? 'page' : undefined"
            :title="app.sidebarCollapsed ? item.label : undefined"
            @click="closeMobileNav"
          >
            <el-icon class="nav-link__icon" aria-hidden="true">
              <component :is="icons[item.icon]" />
            </el-icon>
            <span class="nav-link__label">{{ item.label }}</span>
            <span v-if="badgeFor(item)" class="nav-link__badge" :aria-label="`${badgeFor(item)} 条待处理`">
              {{ badgeFor(item) }}
            </span>
          </RouterLink>
        </div>
      </nav>
    </aside>

    <div class="main-area">
      <header class="topbar">
        <button
          class="topbar__menu"
          type="button"
          :aria-label="app.mobileNavOpen ? '关闭主导航' : '打开主导航'"
          :aria-expanded="app.mobileNavOpen"
          @click="app.toggleMobileNav()"
        >
          <el-icon aria-hidden="true"><Menu /></el-icon>
        </button>
        <div class="topbar__context">
          <p class="topbar__eyebrow">澄明科技 · 人力资源</p>
          <h1 class="topbar__title">{{ pageTitle }}</h1>
        </div>
        <div class="topbar__spacer" />
        <RouterLink to="/notices" class="topbar__notice" aria-label="查看未读公告">
          <el-badge :value="app.unreadNoticeCount" :hidden="app.unreadNoticeCount === 0" :max="9">
            <el-icon aria-hidden="true"><Bell /></el-icon>
          </el-badge>
        </RouterLink>
        <el-dropdown trigger="click" @command="handleCommand">
          <button class="account" type="button" aria-label="打开账户菜单">
            <span class="account__avatar" aria-hidden="true">{{ session.principal?.initials }}</span>
            <span class="account__copy">
              <span class="account__name">{{ session.principal?.displayName }}</span>
              <span class="account__role">{{ session.principal?.jobTitle }}</span>
            </span>
            <el-icon aria-hidden="true"><ArrowDown /></el-icon>
          </button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="workspace">我的工作台</el-dropdown-item>
              <el-dropdown-item v-if="mfaEnabled" command="mfa">
                {{ session.principal?.hasMfa ? '重新绑定 MFA' : '启用 MFA' }}
              </el-dropdown-item>
              <el-dropdown-item divided command="logout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </header>

      <div v-if="session.unauthorizedMessage" class="inline-banner inline-banner--warning" role="status">
        <span>{{ session.unauthorizedMessage }}</span>
        <RouterLink to="/login">重新登录</RouterLink>
      </div>

      <main id="main-content" class="main-content" tabindex="-1">
        <section v-if="routeChunkLoadFailed" class="state-panel error-state page-boundary" role="alert">
          <div class="state-panel__mark" aria-hidden="true">!</div>
          <div class="state-panel__content">
            <h2 class="state-panel__title">页面版本已更新，请刷新</h2>
            <p class="state-panel__body">
              当前页面引用的资源版本已过期。刷新后会加载最新版本，不会影响已保存的数据。
            </p>
          </div>
          <el-button type="primary" plain @click="reloadPage">刷新页面</el-button>
        </section>
        <RouterView v-else v-slot="{ Component }">
          <PageErrorBoundary :key="route.fullPath">
            <Suspense>
              <component :is="Component" v-if="Component" />
              <template #fallback>
                <div class="route-loading" role="status">正在加载页面…</div>
              </template>
            </Suspense>
          </PageErrorBoundary>
        </RouterView>
      </main>

      <footer class="app-footer">
        <span>澄明科技 HRMS</span>
        <span>审计与导出操作会记录操作者、目的与时间</span>
      </footer>
    </div>
  </div>
</template>
