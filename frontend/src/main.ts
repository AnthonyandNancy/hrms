import { createApp } from 'vue'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { installChunkFailureFallback } from './utils/chunkFailure'
import { resolveApi } from './api'
import 'element-plus/dist/index.css'
import './styles/main.css'

async function bootstrap(): Promise<void> {
  // Resolve the adapter before any view can call getApi(): in development
  // VITE_API_MODE=mock lazily loads the synthetic adapter; production always
  // uses the HTTP adapter and never ships the mock module.
  await resolveApi()

  installChunkFailureFallback(router)

  const app = createApp(App)
  app.use(createPinia())
  app.use(router)
  app.use(ElementPlus, { locale: zhCn })
  app.mount('#app')
}

void bootstrap()
