import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { DashboardData, Notice } from '@/api/contracts'
import { getApi } from '@/api'

/**
 * Shell-level data used by navigation badges and the session-expiry banner.
 * Page data remains local to pages; this store only holds cross-page counters.
 */
export const useAppStore = defineStore('app', () => {
  const sidebarCollapsed = ref(false)
  const mobileNavOpen = ref(false)
  const pendingApprovalCount = ref(0)
  const unreadNoticeCount = ref(0)
  const globalBanner = ref<{ tone: 'warning' | 'danger' | 'info'; text: string } | null>(null)
  const dashboardCache = ref<DashboardData | null>(null)
  const notices = ref<Notice[]>([])

  const approvalBadge = computed(() => (pendingApprovalCount.value > 0 ? String(pendingApprovalCount.value) : ''))
  const noticeBadge = computed(() => (unreadNoticeCount.value > 0 ? String(unreadNoticeCount.value) : ''))

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  function toggleMobileNav(value?: boolean): void {
    mobileNavOpen.value = value ?? !mobileNavOpen.value
  }

  async function loadShellCounters(): Promise<void> {
    const api = getApi()
    try {
      const [dashboard, unread] = await Promise.all([
        api.dashboard.getDashboard(),
        api.notices.listNotices('unread'),
      ])
      dashboardCache.value = dashboard
      notices.value = unread
      pendingApprovalCount.value = dashboard.metrics.find((metric) => metric.code === 'pending_approvals')?.value ?? 0
      unreadNoticeCount.value = unread.filter((notice) => !notice.acknowledged).length
    } catch {
      // Shell counters are non-blocking; page-level error states own the detail.
    }
  }

  function decrementApprovals(): void {
    pendingApprovalCount.value = Math.max(0, pendingApprovalCount.value - 1)
  }

  function markNoticeRead(notice: Notice): void {
    const cached = notices.value.find((item) => item.id === notice.id)
    if (cached) cached.acknowledged = true
    unreadNoticeCount.value = notices.value.filter((item) => !item.acknowledged).length
  }

  return {
    sidebarCollapsed,
    mobileNavOpen,
    pendingApprovalCount,
    unreadNoticeCount,
    globalBanner,
    dashboardCache,
    notices,
    approvalBadge,
    noticeBadge,
    toggleSidebar,
    toggleMobileNav,
    loadShellCounters,
    decrementApprovals,
    markNoticeRead,
  }
})
