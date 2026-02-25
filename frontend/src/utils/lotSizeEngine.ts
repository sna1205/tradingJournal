export type RiskMode = 'percent' | 'fixed'
export type TradeDirection = 'long' | 'short'

const SCALE_DIGITS = 8
const SCALE = 10n ** BigInt(SCALE_DIGITS)

const DEFAULT_TIGHT_STOP_TICKS = 5
const DEFAULT_EXTREME_LOT_MULTIPLIER = 200

export interface LotSizeInstrumentSpec {
  symbol: string
  tick_size: string
  tick_value: string
  contract_size: string
  lot_step: string
  min_lot: string
  pip_size?: string | null
}

export interface LotSizeInput {
  account_balance: string
  risk_mode: RiskMode
  risk_percent: string
  risk_amount_fixed: string
  direction: TradeDirection
  entry_price: string
  stop_loss: string
  take_profit?: string
  spread_ticks?: string
  commission_per_lot?: string
  slippage_ticks?: string
  leverage?: string
  instrument: LotSizeInstrumentSpec | null
  policy_max_risk_pct?: number | null
  tight_stop_ticks_threshold?: number
  extreme_lot_multiplier_threshold?: number
}

export type LotSizeFieldErrorKey =
  | 'account_balance'
  | 'risk_percent'
  | 'risk_amount_fixed'
  | 'entry_price'
  | 'stop_loss'
  | 'take_profit'
  | 'instrument'

export interface LotSizeResult {
  valid: boolean
  field_errors: Partial<Record<LotSizeFieldErrorKey, string>>
  warnings: string[]
  target_risk_amount: number
  target_risk_percent: number
  stop_distance: number
  stop_distance_ticks: number
  stop_distance_pips: number | null
  risk_per_one_lot: number
  lot_size_raw: number
  lot_size: number
  lot_size_text: string
  actual_risk_at_stop: number
  estimated_costs: number
  total_loss_if_stopped: number
  actual_risk_percent: number
  expected_profit_at_tp: number | null
  rr_ratio: number | null
  margin_required: number | null
  pip_or_point_value_per_lot: number | null
  if_sl_hits_text: string
  used_min_lot: boolean
}

export function calculateLotSize(input: LotSizeInput): LotSizeResult {
  const fieldErrors: Partial<Record<LotSizeFieldErrorKey, string>> = {}
  const warnings: string[] = []

  if (!input.instrument) {
    fieldErrors.instrument = 'Instrument is required.'
    return emptyResult(fieldErrors, warnings)
  }

  const accountBalance = parseFixed(input.account_balance)
  if (accountBalance === null || accountBalance <= 0n) {
    fieldErrors.account_balance = 'Account balance must be greater than 0.'
  }

  const entry = parseFixed(input.entry_price)
  if (entry === null || entry <= 0n) {
    fieldErrors.entry_price = 'Entry price must be greater than 0.'
  }

  const stop = parseFixed(input.stop_loss)
  if (stop === null || stop <= 0n) {
    fieldErrors.stop_loss = 'Stop loss must be greater than 0.'
  }

  const tickSize = parseFixed(input.instrument.tick_size)
  const tickValue = parseFixed(input.instrument.tick_value)
  const contractSize = parseFixed(input.instrument.contract_size)
  const lotStep = parseFixed(input.instrument.lot_step)
  const minLot = parseFixed(input.instrument.min_lot)
  const pipSize = parseFixed(input.instrument.pip_size ?? '')

  if (tickSize === null || tickSize <= 0n || tickValue === null || tickValue <= 0n || lotStep === null || lotStep <= 0n || minLot === null || minLot <= 0n || contractSize === null || contractSize <= 0n) {
    fieldErrors.instrument = 'Instrument specification is invalid.'
  }

  const riskPercent = parseFixed(input.risk_percent)
  const riskFixed = parseFixed(input.risk_amount_fixed)
  let targetRiskAmount: bigint | null = null

  if (input.risk_mode === 'percent') {
    if (riskPercent === null || riskPercent <= 0n) {
      fieldErrors.risk_percent = 'Risk % must be greater than 0.'
    } else if (accountBalance !== null && accountBalance > 0n) {
      targetRiskAmount = mulFixed(accountBalance, divFixed(riskPercent, fromInt(100)))
    }
  } else {
    if (riskFixed === null || riskFixed <= 0n) {
      fieldErrors.risk_amount_fixed = 'Risk amount must be greater than 0.'
    } else {
      targetRiskAmount = riskFixed
    }
  }

  if (Object.keys(fieldErrors).length > 0 || entry === null || stop === null || accountBalance === null || tickSize === null || tickValue === null || lotStep === null || minLot === null || contractSize === null || targetRiskAmount === null) {
    return emptyResult(fieldErrors, warnings)
  }

  const stopDistance = absFixed(entry - stop)
  if (stopDistance === 0n) {
    fieldErrors.stop_loss = 'Stop loss must differ from entry.'
    return emptyResult(fieldErrors, warnings)
  }

  const stopTicks = divFixed(stopDistance, tickSize)
  if (stopTicks <= 0n) {
    fieldErrors.stop_loss = 'Stop distance is too small for selected instrument tick size.'
    return emptyResult(fieldErrors, warnings)
  }

  const riskPerOneLot = mulFixed(stopTicks, tickValue)
  if (riskPerOneLot <= 0n) {
    fieldErrors.stop_loss = 'Unable to compute risk per lot.'
    return emptyResult(fieldErrors, warnings)
  }

  const lotRaw = divFixed(targetRiskAmount, riskPerOneLot)
  let lotStepped = floorToStep(lotRaw, lotStep)
  let usedMinLot = false
  if (lotStepped < minLot) {
    lotStepped = minLot
    usedMinLot = true
    warnings.push('Calculated lot size is below broker minimum. Minimum lot is applied.')
  }

  const spreadTicks = parseFixed(input.spread_ticks ?? '') ?? 0n
  const slippageTicks = parseFixed(input.slippage_ticks ?? '') ?? 0n
  const commissionPerLot = parseFixed(input.commission_per_lot ?? '') ?? 0n
  const leverage = parseFixed(input.leverage ?? '') ?? null

  const spreadPerLot = mulFixed(spreadTicks, tickValue)
  const slippagePerLot = mulFixed(slippageTicks, tickValue)
  const transactionCostPerLot = spreadPerLot + slippagePerLot + commissionPerLot

  const actualRiskAtStop = mulFixed(riskPerOneLot, lotStepped)
  const estimatedCosts = mulFixed(transactionCostPerLot, lotStepped)
  const totalLossIfStopped = actualRiskAtStop + estimatedCosts
  const actualRiskPercent = accountBalance > 0n
    ? mulFixed(divFixed(totalLossIfStopped, accountBalance), fromInt(100))
    : 0n
  const targetRiskPercent = accountBalance > 0n
    ? mulFixed(divFixed(targetRiskAmount, accountBalance), fromInt(100))
    : 0n

  const policyMaxRiskPct = input.policy_max_risk_pct ?? null
  if (typeof policyMaxRiskPct === 'number' && Number.isFinite(policyMaxRiskPct) && policyMaxRiskPct > 0) {
    const maxRisk = parseFixed(String(policyMaxRiskPct))
    if (maxRisk !== null && actualRiskPercent > maxRisk) {
      warnings.push(`Actual risk ${toNumber(actualRiskPercent, 3).toFixed(2)}% exceeds policy limit ${policyMaxRiskPct.toFixed(2)}%.`)
    }
  }

  const tightStopThreshold = Number.isFinite(input.tight_stop_ticks_threshold)
    ? Number(input.tight_stop_ticks_threshold)
    : DEFAULT_TIGHT_STOP_TICKS
  if (toNumber(stopTicks, 4) < tightStopThreshold) {
    warnings.push('Stop distance is very tight; lot size may become unstable.')
  }

  const extremeLotMultiplier = Number.isFinite(input.extreme_lot_multiplier_threshold)
    ? Number(input.extreme_lot_multiplier_threshold)
    : DEFAULT_EXTREME_LOT_MULTIPLIER
  const extremeThreshold = mulFixed(minLot, fromInt(extremeLotMultiplier))
  if (lotRaw > extremeThreshold) {
    warnings.push('Lot size is unusually large for the selected stop distance.')
  }

  let expectedProfitAtTp: bigint | null = null
  let rrRatio: bigint | null = null
  let tpDistance: bigint | null = null

  const tp = parseFixed(input.take_profit ?? '')
  if (input.take_profit && input.take_profit.trim() !== '' && (tp === null || tp <= 0n)) {
    fieldErrors.take_profit = 'Take profit must be greater than 0.'
  } else if (tp !== null && tp > 0n) {
    tpDistance = absFixed(tp - entry)
    const rewardPerOneLot = mulFixed(divFixed(tpDistance, tickSize), tickValue)
    expectedProfitAtTp = mulFixed(rewardPerOneLot, lotStepped)
    if (targetRiskAmount > 0n) {
      rrRatio = divFixed(expectedProfitAtTp, targetRiskAmount)
    }
  }

  if (input.direction === 'long' && tpDistance !== null && tp !== null && tp <= entry) {
    warnings.push('Take profit is below entry for a long setup.')
  }
  if (input.direction === 'short' && tpDistance !== null && tp !== null && tp >= entry) {
    warnings.push('Take profit is above entry for a short setup.')
  }

  const pipOrPointValuePerLot = pipSize !== null && pipSize > 0n
    ? mulFixed(divFixed(pipSize, tickSize), tickValue)
    : null
  const stopDistancePips = pipSize !== null && pipSize > 0n
    ? divFixed(stopDistance, pipSize)
    : null

  const marginRequired = leverage !== null && leverage > 0n
    ? divFixed(mulFixed(mulFixed(lotStepped, contractSize), entry), leverage)
    : null

  const lotPrecision = Math.min(decimalPlaces(input.instrument.lot_step), 8)
  const lotText = toScaledString(lotStepped, lotPrecision)

  return {
    valid: Object.keys(fieldErrors).length === 0,
    field_errors: fieldErrors,
    warnings,
    target_risk_amount: toNumber(targetRiskAmount, 6),
    target_risk_percent: toNumber(targetRiskPercent, 6),
    stop_distance: toNumber(stopDistance, 8),
    stop_distance_ticks: toNumber(stopTicks, 6),
    stop_distance_pips: stopDistancePips !== null ? toNumber(stopDistancePips, 6) : null,
    risk_per_one_lot: toNumber(riskPerOneLot, 6),
    lot_size_raw: toNumber(lotRaw, 8),
    lot_size: toNumber(lotStepped, 8),
    lot_size_text: lotText,
    actual_risk_at_stop: toNumber(actualRiskAtStop, 6),
    estimated_costs: toNumber(estimatedCosts, 6),
    total_loss_if_stopped: toNumber(totalLossIfStopped, 6),
    actual_risk_percent: toNumber(actualRiskPercent, 6),
    expected_profit_at_tp: expectedProfitAtTp !== null ? toNumber(expectedProfitAtTp, 6) : null,
    rr_ratio: rrRatio !== null ? toNumber(rrRatio, 6) : null,
    margin_required: marginRequired !== null ? toNumber(marginRequired, 6) : null,
    pip_or_point_value_per_lot: pipOrPointValuePerLot !== null ? toNumber(pipOrPointValuePerLot, 6) : null,
    if_sl_hits_text: `If SL hits -> You lose ${formatSignedCurrency(toNumber(totalLossIfStopped, 2), false)}`,
    used_min_lot: usedMinLot,
  }
}

function emptyResult(
  fieldErrors: Partial<Record<LotSizeFieldErrorKey, string>>,
  warnings: string[]
): LotSizeResult {
  return {
    valid: Object.keys(fieldErrors).length === 0,
    field_errors: fieldErrors,
    warnings,
    target_risk_amount: 0,
    target_risk_percent: 0,
    stop_distance: 0,
    stop_distance_ticks: 0,
    stop_distance_pips: null,
    risk_per_one_lot: 0,
    lot_size_raw: 0,
    lot_size: 0,
    lot_size_text: '0',
    actual_risk_at_stop: 0,
    estimated_costs: 0,
    total_loss_if_stopped: 0,
    actual_risk_percent: 0,
    expected_profit_at_tp: null,
    rr_ratio: null,
    margin_required: null,
    pip_or_point_value_per_lot: null,
    if_sl_hits_text: 'If SL hits -> You lose $0.00',
    used_min_lot: false,
  }
}

function normalizeDecimalInput(value: string): string {
  return value.trim().replace(/,/g, '')
}

function parseFixed(raw: string): bigint | null {
  const normalized = normalizeDecimalInput(raw)
  if (normalized === '') return null
  if (!/^-?\d+(\.\d+)?$/.test(normalized)) return null

  const sign = normalized.startsWith('-') ? -1n : 1n
  const unsigned = normalized.startsWith('-') ? normalized.slice(1) : normalized
  const [wholeRaw, fractionRaw = ''] = unsigned.split('.')
  const whole = BigInt(wholeRaw || '0')
  const paddedFraction = (fractionRaw + '0'.repeat(SCALE_DIGITS)).slice(0, SCALE_DIGITS)
  const fraction = BigInt(paddedFraction || '0')
  return sign * (whole * SCALE + fraction)
}

function decimalPlaces(raw: string): number {
  const normalized = normalizeDecimalInput(raw)
  const dotIndex = normalized.indexOf('.')
  if (dotIndex < 0) return 0
  return normalized.slice(dotIndex + 1).replace(/0+$/, '').length
}

function fromInt(value: number): bigint {
  return BigInt(value) * SCALE
}

function absFixed(value: bigint): bigint {
  return value < 0n ? -value : value
}

function mulFixed(a: bigint, b: bigint): bigint {
  return (a * b) / SCALE
}

function divFixed(a: bigint, b: bigint): bigint {
  if (b === 0n) return 0n
  return (a * SCALE) / b
}

function floorToStep(value: bigint, step: bigint): bigint {
  if (step <= 0n) return value
  if (value < 0n) return 0n
  return (value / step) * step
}

function toNumber(value: bigint, decimals = 4): number {
  return Number(toScaledString(value, decimals))
}

function toScaledString(value: bigint, decimals = 2): string {
  const safeDecimals = Math.max(0, Math.min(decimals, 8))
  const sign = value < 0n ? '-' : ''
  const abs = absFixed(value)
  const scaleOut = 10n ** BigInt(safeDecimals)
  const rounded = (abs * scaleOut + (SCALE / 2n)) / SCALE
  const whole = rounded / scaleOut
  const fraction = rounded % scaleOut

  if (safeDecimals === 0) return `${sign}${whole.toString()}`
  return `${sign}${whole.toString()}.${fraction.toString().padStart(safeDecimals, '0')}`
}

function formatSignedCurrency(value: number, withSign = true): string {
  const formatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })

  if (!withSign) {
    return formatter.format(value)
  }

  if (value > 0) return `+${formatter.format(value)}`
  return formatter.format(value)
}
