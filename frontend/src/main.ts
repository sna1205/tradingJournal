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

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.component('VChart', VueECharts)
app.mount('#app')
