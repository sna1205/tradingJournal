import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart, PieChart, RadarChart } from 'echarts/charts'
import {
  GridComponent,
  LegendComponent,
  RadarComponent,
  TitleComponent,
  TooltipComponent,
} from 'echarts/components'
import VueECharts from 'vue-echarts'
import './style.css'
import App from './App.vue'
import router from '@/router'
import { useAuthStore } from '@/stores/authStore'
import { useUiStore } from '@/stores/uiStore'
import { useUserPreferencesStore } from '@/stores/userPreferencesStore'

use([
  CanvasRenderer,
  LineChart,
  BarChart,
  PieChart,
  RadarChart,
  GridComponent,
  RadarComponent,
  TooltipComponent,
  TitleComponent,
  LegendComponent,
])

async function bootstrap() {
  const app = createApp(App)
  const pinia = createPinia()

  app.use(pinia)

  const uiStore = useUiStore(pinia)
  uiStore.initTheme()

  const authStore = useAuthStore(pinia)
  await authStore.initialize()
  const userPreferencesStore = useUserPreferencesStore(pinia)
  await userPreferencesStore.initialize(true)

  app.use(router)
  app.component('VChart', VueECharts)
  app.mount('#app')
}

void bootstrap()
