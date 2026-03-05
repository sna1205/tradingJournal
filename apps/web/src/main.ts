import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from '@/router'
import { useAuthStore } from '@/stores/authStore'
import { useUiStore } from '@/stores/uiStore'
import { useUserPreferencesStore } from '@/stores/userPreferencesStore'

async function bootstrap() {
  const app = createApp(App)
  const pinia = createPinia()

  app.use(pinia)

  const uiStore = useUiStore(pinia)
  uiStore.initTheme()

  const authStore = useAuthStore(pinia)
  await authStore.initialize()
  const userPreferencesStore = useUserPreferencesStore(pinia)
  try {
    await userPreferencesStore.initialize(true)
  } catch {
    // Do not block initial render when preference sync is unauthorized/unavailable.
  }

  app.use(router)
  app.mount('#app')
}

void bootstrap()
