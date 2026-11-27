/**
 * Performance workspace component map. The module has exactly two real views:
 * /performance (cycle list) and /performance/cycles/:id (cycle detail).
 * Detail is registered by the lead-owned router as kind: detail.
 */
export { default as PerformanceWorkspacePage } from './PerformanceWorkspacePage.vue'
export { default as PerformanceCycleDetailPage } from './PerformanceCycleDetailPage.vue'
