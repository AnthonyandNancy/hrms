export const PAGE_COMPONENTS = {
  'performance/index': () => import('../performance/pages/PerformanceWorkspacePage.vue'),
  'performance/cycles/by-id': () => import('../performance/pages/PerformanceCycleDetailPage.vue'),
  'recruitment/requests/index': () => import('./RequisitionListPage.vue'),
  'recruitment/candidates/index': () => import('./CandidateListPage.vue'),
  'recruitment/candidates/by-id': () => import('./CandidateDetailPage.vue'),
  'training/index': () => import('../training/pages/TrainingWorkspacePage.vue'),
} as const
