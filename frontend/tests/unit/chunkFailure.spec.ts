import { afterEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { installChunkFailureFallback } from '@/utils/chunkFailure'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div>home</div>' } },
      {
        path: '/stale',
        component: () => Promise.reject(new Error('Failed to fetch dynamically imported module: /assets/stale.js')),
      },
    ],
  })
}

afterEach(() => {
  document.getElementById('chunk-version-panel')?.remove()
})

describe('initial lazy-chunk failure fallback', () => {
  it('renders one accessible refresh overlay when the first route chunk is stale', async () => {
    const router = makeRouter()
    installChunkFailureFallback(router)

    await router.push('/stale').catch(() => undefined)

    const panels = document.querySelectorAll('#chunk-version-panel')
    expect(panels).toHaveLength(1)
    expect(panels[0]?.textContent).toContain('页面版本已更新，请刷新')
    expect(panels[0]?.textContent).toContain('刷新页面')
    expect(panels[0]?.getAttribute('role')).toBe('alert')
  })

  it('does not stack duplicate overlays for repeated failures', async () => {
    const router = makeRouter()
    installChunkFailureFallback(router)

    await router.push('/stale').catch(() => undefined)
    await router.push('/stale').catch(() => undefined)

    expect(document.querySelectorAll('#chunk-version-panel')).toHaveLength(1)
  })
})
