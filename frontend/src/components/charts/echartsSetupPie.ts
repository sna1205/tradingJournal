import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { PieChart } from 'echarts/charts'
import {
  LegendComponent,
  TooltipComponent,
} from 'echarts/components'

let pieChartsRegistered = false

export function ensurePieChartsRegistered(): void {
  if (pieChartsRegistered) return

  use([
    CanvasRenderer,
    PieChart,
    LegendComponent,
    TooltipComponent,
  ])

  pieChartsRegistered = true
}
