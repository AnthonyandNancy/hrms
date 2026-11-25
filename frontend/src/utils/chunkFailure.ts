import type { Router } from 'vue-router'
import { isChunkLoadError } from '@/utils/renderErrors'

const PANEL_ID = 'chunk-version-panel'

/**
 * Last-resort lazy-chunk failure surface for the initial deep link.
 *
 * When a stale hashed chunk is requested before the shell mounts, no component
 * boundary exists yet. The router error is therefore surfaced as a standalone,
 * accessible overlay that keeps the URL and offers a hard refresh.
 */
export function installChunkFailureFallback(router: Router): void {
  router.onError((error) => {
    if (!isChunkLoadError(error) || document.getElementById(PANEL_ID) !== null) {
      return
    }

    const panel = document.createElement('section')
    panel.id = PANEL_ID
    panel.className = 'boundary'
    panel.setAttribute('role', 'alert')
    panel.setAttribute('aria-live', 'assertive')
    panel.innerHTML = `
      <div class="boundary__panel">
        <p class="boundary__eyebrow">界面版本更新</p>
        <h1>页面版本已更新，请刷新</h1>
        <p class="boundary__hint">当前页面引用的资源版本已过期。刷新后会加载最新版本，不会影响已保存的数据。</p>
        <p class="boundary__hint">请求编号：<span class="mono" data-chunk-request-id>无</span></p>
      </div>
    `
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'el-button el-button--primary el-button--small'
    button.textContent = '刷新页面'
    button.addEventListener('click', () => window.location.reload())
    panel.querySelector('.boundary__panel')?.appendChild(button)
    document.body.appendChild(panel)
  })
}
