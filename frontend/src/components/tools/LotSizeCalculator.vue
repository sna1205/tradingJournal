<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { AlertTriangle, ShieldCheck, Target } from 'lucide-vue-next'
import BaseSelect from '@/components/form/BaseSelect.vue'
import InstrumentPairSelect from '@/components/form/InstrumentPairSelect.vue'
import FieldWrapper from '@/components/form/FieldWrapper.vue'
import api from '@/services/api'
import {
  FxRateResolutionError,
  FxToUsdService,
  resolveQuoteToUsdFromTable,
  type FxQuoteToUsdResolution,
} from '@/services/fxToUsdService'
import { livePriceFeedService } from '@/services/priceFeedService'
import { useAccountStore } from '@/stores/accountStore'
import { useTradeStore } from '@/stores/tradeStore'
import { asCurrency } from '@/utils/format'
import { calculateLotSize, type RiskMode, type TradeDirection } from '@/utils/lotSizeEngine'

interface AccountRiskPolicyLite {
  max_risk_per_trade_pct: number
  max_daily_loss_pct: number
  max_total_drawdown_pct: number
  max_open_risk_pct: number
}

const props = withDefaults(
  defineProps<{
    mode?: 'lots' | 'precheck'
    title?: string
    subtitle?: string
  }>(),
  {
    mode: 'lots',
  }
)

const accountStore = useAccountStore()
const tradeStore = useTradeStore()
const { accounts } = storeToRefs(accountStore)
const { instruments, fxRates } = storeToRefs(tradeStore)

const selectedAccountId = ref('')
const selectedInstrumentId = ref('')
const riskMode = ref<RiskMode>('percent')
const direction = ref<TradeDirection>('long')
const quoteTickVersion = ref(0)
const policyLoading = ref(false)
const policyError = ref('')
const policy = ref<AccountRiskPolicyLite | null>(null)
const fxResolver = new FxToUsdService(livePriceFeedService)
let stopTrackingSymbols: (() => void) | null = null
let unsubscribeQuoteListeners: Array<() => void> = []
const fxConversion = ref<FxQuoteToUsdResolution | null>(null)
const fxLoading = ref(false)
const fxErrorMessage = ref('')
const fxAttemptedSymbols = ref<string[]>([])
let fxResolveRequestId = 0

const form = reactive({
  account_balance: '',
  risk_percent: '1',
  risk_amount_fixed: '100',
  entry_price: '',
  stop_loss: '',
  take_profit: '',
  spread_ticks: '0',
  commission_per_lot: '0',
  slippage_ticks: '0',
  leverage: '100',
})

const heading = computed(() => props.title ?? (props.mode === 'precheck' ? 'Pre-Trade Check' : 'Lots Calculator'))
const subheading = computed(() =>
  props.subtitle
  ?? (props.mode === 'precheck'
    ? 'Validate risk exposure before execution and verify policy compliance.'
    : 'Instrument-aware position sizing with live risk and reward projection.')
)

const accountOptions = computed(() =>
  accounts.value.map((account) => ({
    label: account.name,
    value: String(account.id),
    subtitle: `${account.broker} - ${account.currency}`,
    badge: account.account_type === 'funded'
      ? 'Funded'
      : account.account_type === 'personal'
        ? 'Live'
        : 'Demo',
  }))
)

const selectedAccount = computed(() =>
  accounts.value.find((account) => String(account.id) === selectedAccountId.value) ?? null
)
const selectedInstrument = computed(() =>
  instruments.value.find((instrument) => String(instrument.id) === selectedInstrumentId.value) ?? null
)

const validationErrorsForDisplay = computed(() => {
  const rows = Object.entries(calculationResult.value.field_errors)
  if (!fxErrorMessage.value) return rows

  const quote = selectedInstrument.value?.quote_currency?.toUpperCase() ?? ''
  const prefix = quote ? `Missing live FX quote to convert ${quote}->USD` : ''
  return rows.filter(([key, message]) =>
    !(key === 'instrument' && prefix && message.startsWith(prefix))
  )
})
const hasValidationErrorsForDisplay = computed(() => validationErrorsForDisplay.value.length > 0)
const hasFxFetchInProgress = computed(() => {
  const quoteCurrency = selectedInstrument.value?.quote_currency?.toUpperCase() ?? ''
  return quoteCurrency !== '' && quoteCurrency !== 'USD' && fxLoading.value
})

const conversionTimestampLabel = computed(() => {
  const ts = fxConversion.value?.ts
  if (!ts) return 'live'

  const d = new Date(ts)
  if (Number.isNaN(d.getTime())) return 'live'
  return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })
})

const lotSizeDisplay = computed(() =>
  calculationResult.value.valid ? calculationResult.value.lot_size_text : '-'
)

const calculationResult = computed(() =>
  calculateLotSize({
    account_balance: form.account_balance,
    risk_mode: riskMode.value,
    risk_percent: form.risk_percent,
    risk_amount_fixed: form.risk_amount_fixed,
    direction: direction.value,
    entry_price: form.entry_price,
    stop_loss: form.stop_loss,
    take_profit: form.take_profit,
    spread_ticks: form.spread_ticks,
    commission_per_lot: form.commission_per_lot,
    slippage_ticks: form.slippage_ticks,
    leverage: form.leverage,
    instrument: selectedInstrument.value
      ? {
        symbol: selectedInstrument.value.symbol,
        asset_class: selectedInstrument.value.asset_class,
        base_currency: selectedInstrument.value.base_currency,
        quote_currency: selectedInstrument.value.quote_currency,
        tick_size: selectedInstrument.value.tick_size,
        contract_size: selectedInstrument.value.contract_size,
        lot_step: selectedInstrument.value.lot_step,
        min_lot: selectedInstrument.value.min_lot,
        pip_size: selectedInstrument.value.pip_size,
      }
      : null,
    fx_rate_quote_to_usd: fxConversion.value?.rate ?? null,
    fx_symbol_used: fxConversion.value?.symbolUsed ?? null,
    fx_rate_timestamp: fxConversion.value?.ts ?? null,
    fx_conversion_method: fxConversion.value?.method ?? null,
    fx_rate_mode: fxConversion.value?.mode ?? 'mid',
    policy_max_risk_pct: policy.value?.max_risk_per_trade_pct ?? null,
  })
)

const policyWarning = computed(() => {
  if (policyLoading.value) return 'Loading account risk policy...'
  if (policyError.value) return policyError.value
  if (!policy.value) return 'Risk policy unavailable. Using calculator values only.'
  return `Policy max risk per trade: ${Number(policy.value.max_risk_per_trade_pct).toFixed(2)}%`
})

const stopDistanceLabel = computed(() =>
  calculationResult.value.stop_distance_pips !== null ? 'Stop Distance (pips)' : 'Stop Distance (ticks)'
)
const stopDistanceValue = computed(() =>
  calculationResult.value.stop_distance_pips !== null
    ? calculationResult.value.stop_distance_pips
    : calculationResult.value.stop_distance_ticks
)

async function refreshFxConversion() {
  const instrument = selectedInstrument.value
  if (!instrument) {
    fxConversion.value = null
    fxErrorMessage.value = ''
    fxAttemptedSymbols.value = []
    fxLoading.value = false
    return
  }

  const quoteCurrency = instrument.quote_currency.toUpperCase()
  if (quoteCurrency === 'USD') {
    fxConversion.value = {
      rate: 1,
      symbolUsed: null,
      method: 'identity',
      mode: 'mid',
      ts: null,
      attemptedSymbols: [],
    }
    fxErrorMessage.value = ''
    fxAttemptedSymbols.value = []
    fxLoading.value = false
    return
  }

  const requestId = ++fxResolveRequestId
  fxLoading.value = true
  fxErrorMessage.value = ''
  fxAttemptedSymbols.value = []

  try {
    const resolved = await fxResolver.getRate(quoteCurrency, 'mid')
    if (requestId !== fxResolveRequestId) return
    fxConversion.value = resolved
    fxErrorMessage.value = ''
    fxAttemptedSymbols.value = []
  } catch (error) {
    if (requestId !== fxResolveRequestId) return
    const fallback = resolveQuoteToUsdFromTable(quoteCurrency, fxRates.value)
    if (fallback) {
      fxConversion.value = fallback
      fxErrorMessage.value = ''
      fxAttemptedSymbols.value = []
      return
    }

    fxConversion.value = null
    if (error instanceof FxRateResolutionError) {
      fxAttemptedSymbols.value = error.attemptedSymbols
      fxErrorMessage.value = error.message
    } else {
      fxAttemptedSymbols.value = []
      fxErrorMessage.value = `Missing live FX quote to convert ${quoteCurrency}->USD`
    }
  } finally {
    if (requestId === fxResolveRequestId) {
      fxLoading.value = false
    }
  }
}

watch(selectedAccount, (next) => {
  if (!next) return
  form.account_balance = `${next.current_balance ?? next.starting_balance ?? '0'}`
  const balance = Number(next.current_balance ?? next.starting_balance ?? 0)
  if (Number.isFinite(balance) && balance > 0) {
    form.risk_amount_fixed = (balance * 0.01).toFixed(2)
  }
})

watch(selectedAccountId, (next) => {
  const accountId = Number(next)
  if (!Number.isInteger(accountId) || accountId <= 0) {
    policy.value = null
    policyError.value = ''
    return
  }
  void loadRiskPolicy(accountId)
})

watch(
  () => selectedInstrument.value?.quote_currency ?? '',
  (quoteCurrency) => {
    for (const unsubscribe of unsubscribeQuoteListeners) {
      unsubscribe()
    }
    unsubscribeQuoteListeners = []
    if (stopTrackingSymbols) {
      stopTrackingSymbols()
      stopTrackingSymbols = null
    }

    const symbols = fxResolver.getTrackedSymbolsForQuoteCurrency(quoteCurrency)
    if (symbols.length === 0) {
      quoteTickVersion.value += 1
      void refreshFxConversion()
      return
    }

    stopTrackingSymbols = livePriceFeedService.trackSymbols(symbols)
    unsubscribeQuoteListeners = symbols.map((symbol) =>
      livePriceFeedService.subscribe(symbol, () => {
        quoteTickVersion.value += 1
      })
    )
    quoteTickVersion.value += 1
    void refreshFxConversion()
  },
  { immediate: true }
)

watch(
  () => quoteTickVersion.value,
  () => {
    void refreshFxConversion()
  }
)

watch(
  () => selectedInstrumentId.value,
  () => {
    void refreshFxConversion()
  }
)

onMounted(async () => {
  await Promise.all([
    accountStore.fetchAccounts().catch(() => undefined),
    tradeStore.fetchInstruments().catch(() => undefined),
    tradeStore.fetchFxRates().catch(() => undefined),
  ])

  if (!selectedAccountId.value && accounts.value.length > 0) {
    selectedAccountId.value = String(accounts.value[0]!.id)
  }
  if (!selectedInstrumentId.value && instruments.value.length > 0) {
    selectedInstrumentId.value = String(instruments.value[0]!.id)
  }
})

onBeforeUnmount(() => {
  for (const unsubscribe of unsubscribeQuoteListeners) {
    unsubscribe()
  }
  unsubscribeQuoteListeners = []
  if (stopTrackingSymbols) {
    stopTrackingSymbols()
    stopTrackingSymbols = null
  }
})

async function loadRiskPolicy(accountId: number) {
  policyLoading.value = true
  policyError.value = ''
  try {
    const { data } = await api.get<AccountRiskPolicyLite>(`/accounts/${accountId}/risk-policy`)
    policy.value = {
      max_risk_per_trade_pct: Number(data.max_risk_per_trade_pct ?? 1),
      max_daily_loss_pct: Number(data.max_daily_loss_pct ?? 5),
      max_total_drawdown_pct: Number(data.max_total_drawdown_pct ?? 10),
      max_open_risk_pct: Number(data.max_open_risk_pct ?? 2),
    }
  } catch {
    policy.value = null
    policyError.value = 'Could not load account risk policy.'
  } finally {
    policyLoading.value = false
  }
}

function asPercent(value: number) {
  const safeValue = Number.isFinite(value) ? value : 0
  return `${safeValue.toFixed(2)}%`
}

function asNumber(value: number, decimals = 2) {
  const safeValue = Number.isFinite(value) ? value : 0
  return safeValue.toFixed(decimals)
}

function switchRiskMode(nextMode: RiskMode) {
  if (riskMode.value === nextMode) return

  const balance = Number(form.account_balance)
  if (Number.isFinite(balance) && balance > 0) {
    if (nextMode === 'percent') {
      const fixed = Number(form.risk_amount_fixed)
      if (Number.isFinite(fixed) && fixed >= 0) {
        form.risk_percent = ((fixed / balance) * 100).toFixed(2)
      }
    } else {
      const percent = Number(form.risk_percent)
      if (Number.isFinite(percent) && percent >= 0) {
        form.risk_amount_fixed = ((balance * percent) / 100).toFixed(2)
      }
    }
  }

  riskMode.value = nextMode
}
</script>

<template>
  <div class="lot-calc-shell">
    <header class="panel lot-calc-header">
      <div class="lot-calc-head-title">
        <p class="lot-calc-kicker">{{ props.mode === 'precheck' ? 'Risk Precheck' : 'Position Sizing' }}</p>
        <h2>{{ heading }}</h2>
        <p class="section-note">{{ subheading }}</p>
      </div>
      <p class="lot-calc-policy-note" :class="policyError ? 'negative' : 'muted'">
        <ShieldCheck class="h-4 w-4" />
        <span>{{ policyWarning }}</span>
      </p>
    </header>

    <div class="lot-calc-layout">
      <section class="panel lot-calc-inputs">
        <section class="lot-calc-group">
          <div class="lot-calc-group-head">
            <h3>Account and Risk</h3>
          </div>
          <div class="lot-calc-grid">
            <BaseSelect
              v-model="selectedAccountId"
              label="Account"
              searchable
              search-placeholder="Search account..."
              :options="accountOptions"
              size="sm"
              :error="calculationResult.field_errors.account_balance"
            />
            <FieldWrapper label="Account Balance">
              <input v-model="form.account_balance" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="10000" />
            </FieldWrapper>
          </div>

          <div class="lot-calc-mode-row">
            <p class="kicker-label">Risk Mode</p>
            <div class="lot-calc-segment">
              <button
                type="button"
                class="lot-calc-segment-btn"
                :class="{ active: riskMode === 'percent' }"
                @click="switchRiskMode('percent')"
              >
                % of balance
              </button>
              <button
                type="button"
                class="lot-calc-segment-btn"
                :class="{ active: riskMode === 'fixed' }"
                @click="switchRiskMode('fixed')"
              >
                Fixed amount
              </button>
            </div>
          </div>

          <div class="lot-calc-grid">
            <FieldWrapper
              v-if="riskMode === 'percent'"
              label="Risk %"
              :error="calculationResult.field_errors.risk_percent"
            >
              <input v-model="form.risk_percent" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="1.0" />
            </FieldWrapper>
            <FieldWrapper
              v-else
              label="Risk Amount"
              :error="calculationResult.field_errors.risk_amount_fixed"
            >
              <input v-model="form.risk_amount_fixed" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="100" />
            </FieldWrapper>
            <div class="lot-calc-inline-metrics">
              <article class="lot-calc-inline-metric">
                <span>Target risk</span>
                <strong>{{ asCurrency(calculationResult.target_risk_amount) }}</strong>
              </article>
              <article class="lot-calc-inline-metric">
                <span>Equivalent</span>
                <strong>{{ asPercent(calculationResult.target_risk_percent) }}</strong>
              </article>
            </div>
          </div>
        </section>

        <section class="lot-calc-group">
          <div class="lot-calc-group-head">
            <h3>Trade Setup</h3>
          </div>
          <div class="lot-calc-grid">
            <InstrumentPairSelect
              v-model="selectedInstrumentId"
              label="Instrument"
              :instruments="instruments"
              size="sm"
              :show-label-help="false"
              :error="calculationResult.field_errors.instrument"
            />
            <div class="lot-calc-direction">
              <p class="kicker-label">Direction</p>
              <div class="lot-calc-segment">
                <button
                  type="button"
                  class="lot-calc-segment-btn"
                  :class="{ active: direction === 'long' }"
                  @click="direction = 'long'"
                >
                  Long
                </button>
                <button
                  type="button"
                  class="lot-calc-segment-btn"
                  :class="{ active: direction === 'short' }"
                  @click="direction = 'short'"
                >
                  Short
                </button>
              </div>
            </div>
            <FieldWrapper label="Entry Price" :error="calculationResult.field_errors.entry_price">
              <input v-model="form.entry_price" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="1.08500" />
            </FieldWrapper>
            <FieldWrapper label="Stop Loss" :error="calculationResult.field_errors.stop_loss">
              <input v-model="form.stop_loss" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="1.08350" />
            </FieldWrapper>
            <FieldWrapper label="Take Profit (optional)" :error="calculationResult.field_errors.take_profit">
              <input v-model="form.take_profit" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="1.08900" />
            </FieldWrapper>
          </div>
        </section>

        <section class="lot-calc-group">
          <details class="lot-calc-advanced">
            <summary>Advanced assumptions</summary>
            <div class="lot-calc-grid">
              <FieldWrapper label="Spread (ticks)">
                <input v-model="form.spread_ticks" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="0" />
              </FieldWrapper>
              <FieldWrapper label="Commission / Lot">
                <input v-model="form.commission_per_lot" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="0" />
              </FieldWrapper>
              <FieldWrapper label="Slippage Buffer (ticks)">
                <input v-model="form.slippage_ticks" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="0" />
              </FieldWrapper>
              <FieldWrapper label="Leverage (optional)">
                <input v-model="form.leverage" class="field control-modern field-sm" inputmode="decimal" type="text" placeholder="100" />
              </FieldWrapper>
            </div>
          </details>
        </section>

        <section v-if="hasValidationErrorsForDisplay" class="panel lot-calc-alert danger">
          <div class="lot-calc-alert-head">
            <AlertTriangle class="h-4 w-4" />
            <p>Input validation required</p>
          </div>
          <ul>
            <li v-for="[key, message] in validationErrorsForDisplay" :key="`error-${key}`">{{ message }}</li>
          </ul>
        </section>

        <section v-if="hasFxFetchInProgress" class="panel lot-calc-alert warning">
          <div class="lot-calc-alert-head">
            <AlertTriangle class="h-4 w-4" />
            <p>Fetching FX quote...</p>
          </div>
        </section>

        <section v-if="fxErrorMessage" class="panel lot-calc-alert danger">
          <div class="lot-calc-alert-head">
            <AlertTriangle class="h-4 w-4" />
            <p>Live FX quote required</p>
          </div>
          <ul>
            <li>{{ fxErrorMessage }}</li>
          </ul>
          <details v-if="fxAttemptedSymbols.length > 0" class="lot-calc-fx-debug">
            <summary>Tried symbols</summary>
            <p>{{ fxAttemptedSymbols.join(', ') }}</p>
          </details>
        </section>

        <p class="lot-calc-logic-note muted">Lot size is derived from Entry + Stop Loss + Risk. TP never changes lot size.</p>
      </section>

      <aside class="panel lot-calc-summary">
        <div class="lot-calc-summary-head">
          <p class="kicker-label">Live Summary</p>
          <Target class="h-4 w-4 muted" />
        </div>

        <div class="lot-calc-lot-block">
          <span>Position Size</span>
          <strong class="value-display">{{ lotSizeDisplay }}</strong>
          <small>lot</small>
        </div>

        <div class="lot-calc-kpi-grid">
          <article class="lot-calc-kpi">
            <span>Target risk</span>
            <strong>{{ asCurrency(calculationResult.target_risk_amount) }}</strong>
            <small>{{ asPercent(calculationResult.target_risk_percent) }}</small>
          </article>
          <article class="lot-calc-kpi">
            <span>Actual risk</span>
            <strong>{{ asCurrency(calculationResult.actual_risk_at_stop) }}</strong>
            <small>{{ asPercent(calculationResult.actual_risk_percent) }}</small>
          </article>
          <article class="lot-calc-kpi">
            <span>{{ stopDistanceLabel }}</span>
            <strong>{{ asNumber(stopDistanceValue, 2) }}</strong>
            <small>distance</small>
          </article>
          <article class="lot-calc-kpi">
            <span>Margin</span>
            <strong>{{ calculationResult.margin_required === null ? '-' : asCurrency(calculationResult.margin_required) }}</strong>
            <small>estimated</small>
          </article>
        </div>

        <div class="lot-calc-secondary">
          <p><span>Risk currency</span><strong>{{ calculationResult.risk_currency }}</strong></p>
          <p v-if="calculationResult.quote_currency && calculationResult.quote_currency !== 'USD'">
            <span>FX</span>
            <strong>
              {{
                hasFxFetchInProgress
                  ? `Fetching ${calculationResult.quote_currency}->USD...`
                  : calculationResult.conversion_rate_quote_to_usd === null
                  ? `${calculationResult.quote_currency}->USD missing`
                  : `${calculationResult.quote_currency}->USD via ${calculationResult.conversion_symbol_used ?? '?'} (${calculationResult.conversion_method ?? '?'}) @ ${asNumber(calculationResult.conversion_rate_quote_to_usd, 6)} (${calculationResult.conversion_rate_mode}) ${conversionTimestampLabel}`
              }}
            </strong>
          </p>
          <p><span>Expected Profit @ TP</span><strong>{{ calculationResult.expected_profit_at_tp === null ? '-' : asCurrency(calculationResult.expected_profit_at_tp) }}</strong></p>
          <p><span>Risk : Reward</span><strong>{{ calculationResult.rr_ratio === null ? '-' : `${asNumber(calculationResult.rr_ratio, 2)}R` }}</strong></p>
          <p><span>Tick Value (USD, 1 lot)</span><strong>{{ calculationResult.tick_value_per_lot_usd === null ? '-' : asCurrency(calculationResult.tick_value_per_lot_usd) }}</strong></p>
          <p v-if="calculationResult.pip_value_per_lot_usd !== null">
            <span>Pip Value (USD, 1 lot)</span>
            <strong>{{ asCurrency(calculationResult.pip_value_per_lot_usd) }}</strong>
          </p>
          <p><span>Estimated Costs</span><strong>{{ asCurrency(calculationResult.estimated_costs) }}</strong></p>
        </div>

        <div class="lot-calc-stop-line panel">
          {{ calculationResult.if_sl_hits_text }}
        </div>

        <section v-if="calculationResult.warnings.length > 0" class="panel lot-calc-alert warning">
          <div class="lot-calc-alert-head">
            <AlertTriangle class="h-4 w-4" />
            <p>Risk Protection Warnings</p>
          </div>
          <ul>
            <li v-for="(warning, index) in calculationResult.warnings" :key="`warning-${index}`">{{ warning }}</li>
          </ul>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.lot-calc-shell {
  --lot-accent: color-mix(in srgb, var(--primary) 74%, #0ea5a4 26%);
  --lot-accent-strong: color-mix(in srgb, var(--lot-accent) 78%, #5eead4 22%);
  --lot-metal: color-mix(in srgb, var(--warning) 62%, #c7a76d 38%);
  --lot-surface-1: color-mix(in srgb, var(--panel) 96%, var(--panel-soft) 4%);
  --lot-surface-2: color-mix(in srgb, var(--panel-soft) 74%, var(--panel) 26%);
  --lot-edge: color-mix(in srgb, var(--border) 70%, var(--primary) 30%);
  --lot-row-sep: color-mix(in srgb, var(--border) 78%, var(--primary) 22%);
  display: grid;
  gap: 0.9rem;
}

:global([data-theme='dark']) .lot-calc-shell {
  --lot-accent: color-mix(in srgb, var(--primary) 72%, #34d399 28%);
  --lot-accent-strong: color-mix(in srgb, var(--lot-accent) 76%, #86efac 24%);
  --lot-metal: #d4bc84;
  --lot-surface-1: color-mix(in srgb, var(--panel) 84%, #04130e 16%);
  --lot-surface-2: color-mix(in srgb, var(--panel-soft) 78%, #02100c 22%);
  --lot-edge: color-mix(in srgb, var(--border) 62%, var(--primary) 38%);
  --lot-row-sep: color-mix(in srgb, var(--border) 68%, var(--primary) 32%);
}

:global([data-theme='forest']) .lot-calc-shell {
  --lot-accent: color-mix(in srgb, var(--primary) 76%, #2f9d60 24%);
  --lot-accent-strong: color-mix(in srgb, var(--lot-accent) 78%, #6ecf94 22%);
  --lot-metal: #9f7d40;
  --lot-surface-1: color-mix(in srgb, var(--panel) 95%, #e9f5ee 5%);
  --lot-surface-2: color-mix(in srgb, var(--panel-soft) 80%, #e1eee6 20%);
  --lot-edge: color-mix(in srgb, var(--border) 68%, var(--primary) 32%);
  --lot-row-sep: color-mix(in srgb, var(--border) 76%, var(--primary) 24%);
}

:global([data-theme='dawn']) .lot-calc-shell {
  --lot-accent: color-mix(in srgb, var(--primary) 76%, #d98b4f 24%);
  --lot-accent-strong: color-mix(in srgb, var(--lot-accent) 78%, #f0bb83 22%);
  --lot-metal: #b68642;
  --lot-surface-1: color-mix(in srgb, var(--panel) 95%, #fff7ef 5%);
  --lot-surface-2: color-mix(in srgb, var(--panel-soft) 78%, #fbe8da 22%);
  --lot-edge: color-mix(in srgb, var(--border) 68%, var(--primary) 32%);
  --lot-row-sep: color-mix(in srgb, var(--border) 76%, var(--primary) 24%);
}

.lot-calc-header {
  padding: 1.1rem 1.15rem;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  border-radius: 1rem;
  border-color: color-mix(in srgb, var(--lot-edge) 86%, transparent 14%);
  box-shadow: inset 0 1px 0 color-mix(in srgb, #9be7cf 10%, transparent 90%), 0 10px 24px rgba(0, 0, 0, 0.22);
  background: linear-gradient(
    160deg,
    color-mix(in srgb, var(--lot-surface-1) 92%, var(--panel) 8%),
    color-mix(in srgb, var(--lot-surface-2) 88%, var(--panel-soft) 12%)
  );
}

.lot-calc-kicker {
  margin: 0;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.11em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--lot-metal) 60%, var(--muted) 40%);
}

.lot-calc-head-title h2 {
  margin: 0.22rem 0 0;
  font-size: clamp(1.15rem, 1.35vw, 1.4rem);
  font-weight: 750;
  letter-spacing: -0.01em;
}

.lot-calc-head-title .section-note {
  margin: 0.3rem 0 0;
  max-width: 42rem;
  line-height: 1.45;
}

.lot-calc-policy-note {
  margin: 0;
  display: inline-flex;
  align-items: flex-start;
  gap: 0.42rem;
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.35;
  border: 1px solid color-mix(in srgb, var(--lot-edge) 78%, transparent 22%);
  border-radius: 0.7rem;
  padding: 0.52rem 0.62rem;
  max-width: 21rem;
  background: color-mix(in srgb, var(--lot-surface-2) 82%, transparent 18%);
  box-shadow: inset 0 1px 0 color-mix(in srgb, var(--lot-accent) 10%, transparent 90%);
}

.lot-calc-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.22fr) minmax(20rem, 0.9fr);
  gap: 0.9rem;
}

.lot-calc-inputs {
  padding: 1rem;
  display: grid;
  gap: 0.82rem;
  border-radius: 1rem;
  border-color: color-mix(in srgb, var(--lot-edge) 84%, transparent 16%);
  background:
    radial-gradient(120% 100% at 0% 0%, color-mix(in srgb, var(--lot-accent) 12%, transparent 88%), transparent 52%),
    linear-gradient(175deg, color-mix(in srgb, var(--lot-surface-1) 94%, var(--panel) 6%), color-mix(in srgb, var(--lot-surface-2) 90%, var(--panel-soft) 10%));
}

.lot-calc-group {
  display: grid;
  gap: 0.58rem;
  padding: 0;
}

.lot-calc-group + .lot-calc-group {
  border-top: 1px solid color-mix(in srgb, var(--lot-row-sep) 78%, transparent 22%);
  padding-top: 0.85rem;
}

.lot-calc-group-head h3 {
  margin: 0;
  font-size: 0.83rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--lot-metal) 38%, var(--muted) 62%);
}

.lot-calc-grid {
  display: grid;
  gap: 0.62rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.lot-calc-mode-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.58rem;
}

.lot-calc-direction {
  display: grid;
  gap: 0.3rem;
}

.lot-calc-segment {
  display: inline-flex;
  align-items: center;
  gap: 0.22rem;
  border: 1px solid color-mix(in srgb, var(--lot-edge) 80%, transparent 20%);
  border-radius: 0.7rem;
  padding: 0.2rem;
  background: color-mix(in srgb, var(--lot-surface-2) 90%, var(--panel-soft) 10%);
}

.lot-calc-segment-btn {
  border: 0;
  border-radius: 0.52rem;
  padding: 0.4rem 0.74rem;
  font-size: 0.77rem;
  font-weight: 650;
  color: var(--muted);
  background: transparent;
  transition: 0.2s ease;
}

.lot-calc-segment-btn.active {
  color: color-mix(in srgb, var(--text) 94%, var(--lot-accent) 6%);
  background: linear-gradient(
    160deg,
    color-mix(in srgb, var(--lot-accent) 32%, var(--panel) 68%),
    color-mix(in srgb, var(--primary-soft) 44%, var(--panel) 56%)
  );
  box-shadow:
    inset 0 0 0 1px color-mix(in srgb, var(--lot-accent-strong) 42%, transparent 58%),
    0 0 0.35rem color-mix(in srgb, var(--lot-accent) 24%, transparent 76%);
}

.lot-calc-inline-metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.5rem;
}

.lot-calc-inline-metric {
  border: 1px solid color-mix(in srgb, var(--lot-edge) 78%, transparent 22%);
  border-radius: 0.68rem;
  padding: 0.5rem 0.54rem;
  background: linear-gradient(
    175deg,
    color-mix(in srgb, var(--lot-surface-1) 90%, var(--panel) 10%),
    color-mix(in srgb, var(--lot-surface-2) 88%, var(--panel-soft) 12%)
  );
}

.lot-calc-inline-metric span {
  display: block;
  color: var(--muted);
  font-size: 0.72rem;
}

.lot-calc-inline-metric strong {
  display: block;
  margin-top: 0.18rem;
  font-size: 0.89rem;
  letter-spacing: -0.01em;
}

.lot-calc-advanced {
  border: 0;
  border-radius: 0;
  padding: 0;
  background: transparent;
}

.lot-calc-advanced summary {
  cursor: pointer;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: color-mix(in srgb, var(--lot-metal) 32%, var(--muted) 68%);
}

.lot-calc-advanced .lot-calc-grid {
  margin-top: 0.62rem;
}

.lot-calc-summary {
  position: sticky;
  top: 6rem;
  align-self: start;
  padding: 0.98rem;
  display: grid;
  gap: 0.66rem;
  border-radius: 1rem;
  border-color: color-mix(in srgb, var(--lot-edge) 82%, transparent 18%);
  box-shadow:
    inset 0 1px 0 color-mix(in srgb, var(--lot-accent) 9%, transparent 91%),
    0 14px 36px rgba(0, 0, 0, 0.26);
  background: linear-gradient(
    165deg,
    color-mix(in srgb, var(--lot-surface-1) 88%, var(--panel) 12%),
    color-mix(in srgb, var(--lot-surface-2) 90%, var(--panel-soft) 10%)
  );
}

.lot-calc-summary-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.lot-calc-lot-block {
  border: 1px solid color-mix(in srgb, var(--lot-accent-strong) 38%, var(--lot-edge) 62%);
  border-radius: 0.82rem;
  padding: 0.72rem;
  background:
    radial-gradient(110% 110% at 100% 0%, color-mix(in srgb, var(--lot-accent) 22%, transparent 78%), transparent 56%),
    linear-gradient(
      168deg,
      color-mix(in srgb, var(--primary-soft) 30%, var(--lot-surface-1) 70%),
      color-mix(in srgb, var(--lot-surface-2) 88%, var(--panel-soft) 12%)
    );
  box-shadow: inset 0 1px 0 color-mix(in srgb, var(--lot-accent) 10%, transparent 90%);
}

.lot-calc-lot-block span {
  display: block;
  font-size: 0.72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.lot-calc-lot-block strong {
  display: block;
  margin-top: 0.18rem;
  font-size: clamp(2.05rem, 2.5vw, 2.55rem);
  line-height: 1;
  letter-spacing: -0.03em;
}

.lot-calc-lot-block small {
  display: inline-block;
  margin-top: 0.18rem;
  font-size: 0.74rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.lot-calc-kpi-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.48rem;
}

.lot-calc-kpi {
  border: 1px solid color-mix(in srgb, var(--lot-edge) 74%, transparent 26%);
  border-radius: 0.72rem;
  padding: 0.54rem;
  background: linear-gradient(
    180deg,
    color-mix(in srgb, var(--lot-surface-1) 90%, var(--panel) 10%),
    color-mix(in srgb, var(--lot-surface-2) 92%, var(--panel-soft) 8%)
  );
}

.lot-calc-kpi span {
  display: block;
  font-size: 0.67rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--lot-metal) 22%, var(--muted) 78%);
}

.lot-calc-kpi strong {
  display: block;
  margin-top: 0.18rem;
  font-size: 0.98rem;
  line-height: 1.25;
}

.lot-calc-kpi small {
  display: block;
  margin-top: 0.14rem;
  font-size: 0.7rem;
  color: var(--muted);
}

.lot-calc-secondary {
  display: grid;
  gap: 0.22rem;
}

.lot-calc-secondary p {
  margin: 0;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.48rem;
  border-bottom: 1px dashed color-mix(in srgb, var(--lot-row-sep) 75%, transparent 25%);
  padding: 0.28rem 0;
  font-size: 0.79rem;
}

.lot-calc-secondary span {
  color: var(--muted);
}

.lot-calc-stop-line {
  padding: 0.58rem 0.62rem;
  font-size: 0.8rem;
  font-weight: 700;
  color: color-mix(in srgb, #ff9797 72%, var(--text) 28%);
  border-color: color-mix(in srgb, var(--danger) 42%, var(--lot-edge) 58%);
  background: linear-gradient(
    165deg,
    color-mix(in srgb, var(--danger-soft) 46%, var(--lot-surface-1) 54%),
    color-mix(in srgb, var(--danger-soft) 34%, var(--lot-surface-2) 66%)
  );
}

.lot-calc-alert {
  padding: 0.58rem 0.64rem;
  border-radius: 0.7rem;
}

.lot-calc-alert.warning {
  border-color: color-mix(in srgb, var(--warning) 42%, var(--border) 58%);
  background: color-mix(in srgb, var(--warning-soft) 58%, var(--panel) 42%);
}

.lot-calc-alert.danger {
  border-color: color-mix(in srgb, var(--danger) 42%, var(--border) 58%);
  background: color-mix(in srgb, var(--danger-soft) 60%, var(--panel) 40%);
}

.lot-calc-alert-head {
  display: inline-flex;
  align-items: center;
  gap: 0.36rem;
  font-size: 0.8rem;
  font-weight: 700;
}

.lot-calc-alert-head p {
  margin: 0;
}

.lot-calc-alert ul {
  margin: 0.4rem 0 0;
  padding-left: 1rem;
  display: grid;
  gap: 0.2rem;
  font-size: 0.78rem;
}

.lot-calc-fx-debug {
  margin-top: 0.38rem;
  font-size: 0.74rem;
}

.lot-calc-fx-debug summary {
  cursor: pointer;
  font-weight: 600;
}

.lot-calc-fx-debug p {
  margin: 0.25rem 0 0;
  color: var(--muted);
  word-break: break-word;
}

.lot-calc-logic-note {
  margin: 0;
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

@media (max-width: 1023px) {
  .lot-calc-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .lot-calc-summary {
    position: static;
  }
}

@media (max-width: 767px) {
  .lot-calc-header {
    padding: 0.86rem;
    gap: 0.66rem;
    flex-direction: column;
  }

  .lot-calc-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .lot-calc-mode-row {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.35rem;
  }

  .lot-calc-segment {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .lot-calc-segment-btn {
    width: 100%;
    text-align: center;
  }

  .lot-calc-inline-metrics,
  .lot-calc-kpi-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .lot-calc-summary {
    padding: 0.84rem;
  }
}
</style>



