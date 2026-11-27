<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import PageErrorBoundary from '@/components/PageErrorBoundary.vue'
import { isChunkLoadError } from '@/utils/renderErrors'
import {
  ArrowDown,
  ArrowRight,
  Bell,
  Briefcase,
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
  Refresh,
  Files,
  Timer,
  Van,
  TrendCharts,
  Search,
  UserFilled,
  Reading,
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
const companyLabel = computed(() => session.principal?.companyName ?? 'HRMS')

const app = useAppStore()

const icons: Record<string, unknown> = {
  Bell,
  Briefcase,
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
  Refresh,
  Files,
  Timer,
  Van,
  TrendCharts,
  Search,
  UserFilled,
  Reading,
}

const buildSha = import.meta.env.VITE_BUILD_SHA ?? 'unknown'
const currentNavKey = computed(() => (typeof route.meta.navKey === 'string' ? route.meta.navKey : ''))
const groupedNavigation = computed(() => session.navigation)

const NAV_EXPANDED_KEY = 'hrms.nav.expanded'

/** Restore only the user's display preference, never an authorization result. */
function readExpandedGroups(): Set<string> {
  try {
    const raw = localStorage.getItem(NAV_EXPANDED_KEY)
    const parsed: unknown = raw === null ? [] : JSON.parse(raw)
    return new Set(
      Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : [],
    )
  } catch {
    return new Set()
  }
}

const expandedGroups = ref<Set<string>>(readExpandedGroups())

function persistExpandedGroups(groups: Set<string>): void {
  try {
    localStorage.setItem(NAV_EXPANDED_KEY, JSON.stringify([...groups]))
  } catch {
    // Menu expansion remains usable when storage is unavailable or full.
  }
}

function isGroupOpen(item: NavigationItem): boolean {
  return expandedGroups.value.has(item.key) || item.children.some((child) => child.key === currentNavKey.value)
}

function toggleGroup(item: NavigationItem): void {
  const next = new Set(expandedGroups.value)
  if (next.has(item.key)) {
    next.delete(item.key)
  } else {
    next.add(item.key)
  }
  expandedGroups.value = next
  persistExpandedGroups(next)
}

function isChildActive(childKey: string): boolean {
  return currentNavKey.value === childKey
}

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
          <span class="sidebar__tenant">{{ companyLabel }}</span>
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

      <nav class="sidebar__nav" aria-label="V1.1 功能菜单">
        <div v-for="item in groupedNavigation" :key="item.key" class="sidebar__group">
          <template v-if="item.children.length === 0">
            <RouterLink
              :to="item.path"
              class="nav-link"
              :class="{ 'nav-link--active': currentNavKey === item.key }"
              :aria-current="currentNavKey === item.key ? 'page' : undefined"
              :title="app.sidebarCollapsed ? item.label : undefined"
              @click="closeMobileNav"
            >
              <el-icon class="nav-link__icon" aria-hidden="true"><HomeFilled /></el-icon>
              <span class="nav-link__label">{{ item.label }}</span>
            </RouterLink>
          </template>
          <template v-else>
            <button
              type="button"
              class="nav-link nav-link--group"
              :class="{ 'nav-link--group-active': isGroupOpen(item) }"
              :aria-expanded="isGroupOpen(item)"
              :aria-controls="`nav-group-${item.key}`"
              :title="app.sidebarCollapsed ? item.label : undefined"
              @click="toggleGroup(item)"
            >
              <el-icon class="nav-link__icon" aria-hidden="true">
                <component :is="icons[item.icon]" />
              </el-icon>
              <span class="nav-link__label">{{ GROUP_LABEL[item.group] }}</span>
              <span v-if="badgeFor(item)" class="nav-link__badge" :aria-label="`${badgeFor(item)} 条待处理`">
                {{ badgeFor(item) }}
              </span>
              <el-icon class="nav-link__chevron" aria-hidden="true">
                <ArrowDown v-if="isGroupOpen(item)" />
                <ArrowRight v-else />
              </el-icon>
            </button>
            <div
              v-if="isGroupOpen(item) && !app.sidebarCollapsed"
              :id="`nav-group-${item.key}`"
              class="sidebar__children"
            >
              <RouterLink
                v-for="child in item.children"
                :key="child.key"
                :to="child.path"
                class="nav-link nav-link--child"
                :class="{ 'nav-link--active': isChildActive(child.key) }"
                :aria-current="isChildActive(child.key) ? 'page' : undefined"
                @click="closeMobileNav"
              >
                <el-icon class="nav-link__child-icon" aria-hidden="true">
                  <component :is="icons[child.icon] ?? Document" />
                </el-icon>
                <span class="nav-link__label">{{ child.label }}</span>
              </RouterLink>
            </div>
          </template>
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
          <span class="topbar__title">{{ pageTitle }}</span>
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
        <span data-testid="build-sha" class="mono">构建 {{ buildSha }}</span>
      </footer>
    </div>
  </div>
</template>
