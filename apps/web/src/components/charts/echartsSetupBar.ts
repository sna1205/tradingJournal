import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
} from 'echarts/components'

let barChartsRegistered = false

export function ensureBarChartsRegistered(): void {
  if (barChartsRegistered) return

  use([
    CanvasRenderer,
    BarChart,
    GridComponent,
    TooltipComponent,
  ])

  barChartsRegistered = true
}
