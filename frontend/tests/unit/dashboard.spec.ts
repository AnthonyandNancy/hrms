import { describe, expect, it } from 'vitest'
import { normaliseDashboard } from '@/api/http'
import type { BackendDashboardData } from '@/api/contracts'

function backendDashboard(): BackendDashboardData {
  return {
    asOf: '2026-11-23',
    freshness: '2026-11-23T09:05:00Z',
    greeting: 'Welcome back',
    metrics: [
      {
        code: 'pending_approvals',
        label: 'Pending approvals',
        value: 1,
        unit: 'COUNT',
        status: 'AVAILABLE',
        reason: null,
        trend: null,
        trendDirection: 'FLAT',
        drilldownLabel: 'View details',
        sourceHash: 'a'.repeat(64),
        computedAt: '2026-11-23T09:05:00Z',
      },
    ],
    pendingApprovals: [
      {
        id: 8204,
        caseNo: 'CASE-8204',
        title: 'Synthetic leave request',
        status: 'PENDING',
        ageDays: 0,
        dueAt: '2026-11-24T09:00:00Z',
      },
    ],
    recentNotices: [
      {
        id: 301,
        title: 'Synthetic notice',
        publishedAt: '2026-11-22T09:00:00Z',
        requiresAck: true,
        acknowledged: false,
        authorName: null,
      },
    ],
    contractAlerts: [
      {
        employmentId: 2,
        employeeName: 'Demo Employee',
        employeeNo: 'DEMO-0002',
        orgName: 'Platform',
        endDate: '2026-12-10',
        daysRemaining: 17,
      },
    ],
  }
}

describe('real dashboard response normalisation', () => {
  it('passes bounded backend panels through without fabricating rows', () => {
    const dashboard = normaliseDashboard(backendDashboard())

    expect(dashboard.pendingApprovals).toEqual([
      {
        id: 8204,
        caseNo: 'CASE-8204',
        title: 'Synthetic leave request',
        status: 'PENDING',
        ageDays: 0,
        dueAt: '2026-11-24T09:00:00Z',
      },
    ])
    expect(dashboard.recentNotices).toEqual([
      {
        id: 301,
        title: 'Synthetic notice',
        publishedAt: '2026-11-22T09:00:00Z',
        requiresAck: true,
        acknowledged: false,
        authorName: null,
      },
    ])
    expect(dashboard.contractAlerts).toEqual([
      {
        employmentId: 2,
        employeeName: 'Demo Employee',
        employeeNo: 'DEMO-0002',
        orgName: 'Platform',
        endDate: '2026-12-10',
        daysRemaining: 17,
      },
    ])
  })

  it('produces empty arrays when optional panel fields are absent or null', () => {
    const dashboard = normaliseDashboard({
      asOf: '2026-11-23',
      freshness: '2026-11-23T09:05:00Z',
      greeting: 'Welcome back',
      metrics: [],
      pendingApprovals: null,
      recentNotices: null,
      contractAlerts: null,
    } as unknown as BackendDashboardData)

    expect(dashboard.pendingApprovals).toEqual([])
    expect(dashboard.recentNotices).toEqual([])
    expect(dashboard.contractAlerts).toEqual([])
  })
})
