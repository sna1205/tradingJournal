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

let chartsRegistered = false

export function ensureChartsRegistered(): void {
  if (chartsRegistered) return

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

  chartsRegistered = true
}
