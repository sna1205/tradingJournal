import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
} from 'echarts/components'

let lineChartsRegistered = false

export function ensureLineChartsRegistered(): void {
  if (lineChartsRegistered) return

  use([
    CanvasRenderer,
    LineChart,
    GridComponent,
    TooltipComponent,
  ])

  lineChartsRegistered = true
}
