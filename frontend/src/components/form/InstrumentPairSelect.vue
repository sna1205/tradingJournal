<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { Check, ChevronDown, ChevronRight, CircleHelp, Search, Star } from 'lucide-vue-next'
import FieldWrapper from './FieldWrapper.vue'
import type { Instrument } from '@/types/trade'

type GroupKey = 'forex' | 'crypto' | 'stocks' | 'indices' | 'futures' | 'commodities'

const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined
    label: string
    instruments: Instrument[]
    required?: boolean
    error?: string
    hint?: string
    size?: 'sm' | 'md'
    disabled?: boolean
    searchPlaceholder?: string
    placeholder?: string
    clearable?: boolean
    allLabel?: string
    showLabelHelp?: boolean
  }>(),
  {
    required: false,
    error: '',
    hint: '',
    size: 'md',
    disabled: false,
    searchPlaceholder: 'Select or type an instrument (e.g. EURUSD, NQ, AAPL, XAUUSD)',
    placeholder: 'Select instrument',
    clearable: false,
    allLabel: 'All instruments',
    showLabelHelp: true,
  }
)

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void
}>()

const fallbackId = useId()
const controlId = computed(() => `instrument-select-${fallbackId}`)
const controlClass = computed(() => [
  'field',
  'control-modern',
  props.size === 'sm' ? 'field-sm' : '',
  props.error ? 'field-invalid' : '',
])
const rootRef = ref<HTMLElement | null>(null)
const searchInputRef = ref<HTMLInputElement | null>(null)
const isOpen = ref(false)
const searchTerm = ref('')
const RECENT_KEY = 'tj_recent_instruments_v1'
const recentIds = ref<number[]>(readRecentIds())
const groupOrder: GroupKey[] = ['forex', 'crypto', 'stocks', 'indices', 'futures', 'commodities']
const groupLabels: Record<GroupKey, string> = {
  forex: 'FOREX',
  crypto: 'CRYPTO',
  stocks: 'STOCKS',
  indices: 'INDICES',
  futures: 'FUTURES',
  commodities: 'COMMODITIES',
}
const groupExpanded = ref<Record<GroupKey, boolean>>({
  forex: false,
  crypto: false,
  stocks: false,
  indices: false,
  futures: false,
  commodities: false,
})

const normalizedSearch = computed(() => searchTerm.value.trim().toLowerCase())
const selectedId = computed(() => Number(props.modelValue || 0))

const normalizedInstruments = computed(() => {
  const active = props.instruments.filter((instrument) => isInstrumentActive(instrument))
  const source = active.length > 0 ? active : props.instruments
  return source
    .slice()
    .sort((a, b) => a.symbol.localeCompare(b.symbol))
})

const selectedInstrument = computed(() =>
  normalizedInstruments.value.find((instrument) => instrument.id === selectedId.value) ?? null
)

const frequentInstruments = computed(() => {
  const byId = new Map(normalizedInstruments.value.map((instrument) => [instrument.id, instrument]))
  return recentIds.value
    .map((id) => byId.get(id))
    .filter((instrument): instrument is Instrument => Boolean(instrument))
    .slice(0, 6)
})

const visibleFrequentInstruments = computed(() => {
  const term = normalizedSearch.value
  if (!term) return frequentInstruments.value
  return frequentInstruments.value.filter((instrument) => matchesInstrument(instrument, term))
})

const groupedInstruments = computed(() => {
  const grouped: Record<GroupKey, Instrument[]> = {
    forex: [],
    crypto: [],
    stocks: [],
    indices: [],
    futures: [],
    commodities: [],
  }

  for (const instrument of normalizedInstruments.value) {
    const key = groupFromAssetClass(instrument.asset_class)
    grouped[key].push(instrument)
  }

  return grouped
})

const visibleGroupedInstruments = computed(() => {
  const term = normalizedSearch.value
  const grouped = groupedInstruments.value
  if (!term) return grouped

  const filtered: Record<GroupKey, Instrument[]> = {
    forex: [],
    crypto: [],
    stocks: [],
    indices: [],
    futures: [],
    commodities: [],
  }

  for (const key of groupOrder) {
    filtered[key] = grouped[key].filter((instrument) => matchesInstrument(instrument, term))
  }

  return filtered
})

function toggleOpen() {
  if (props.disabled) return
  isOpen.value = !isOpen.value
}

function closeMenu() {
  isOpen.value = false
  searchTerm.value = ''
}

function onDocumentPointerDown(event: PointerEvent) {
  if (!rootRef.value) return
  const target = event.target as Node | null
  if (target && rootRef.value.contains(target)) return
  closeMenu()
}

function selectInstrument(instrument: Instrument) {
  emit('update:modelValue', String(instrument.id))
  rememberRecentInstrument(instrument.id)
  closeMenu()
}

function selectAll() {
  emit('update:modelValue', '')
  closeMenu()
}

function rememberRecentInstrument(id: number) {
  const next = [id, ...recentIds.value.filter((current) => current !== id)].slice(0, 12)
  recentIds.value = next
  writeRecentIds(next)
}

function onTriggerKeydown(event: KeyboardEvent) {
  if (props.disabled) return

  if (event.key === 'Escape') {
    closeMenu()
    return
  }

  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    toggleOpen()
  }
}

function toggleGroup(group: GroupKey) {
  if (normalizedSearch.value) return
  groupExpanded.value[group] = !groupExpanded.value[group]
}

function isGroupOpen(group: GroupKey) {
  if (normalizedSearch.value) return true
  return groupExpanded.value[group]
}

function onSearchKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeMenu()
  }
}

function groupFromAssetClass(assetClass: string): GroupKey {
  const normalized = assetClass.trim().toLowerCase()
  if (normalized === 'forex' || normalized === 'fx') return 'forex'
  if (normalized === 'crypto') return 'crypto'
  if (normalized === 'stocks' || normalized === 'stock' || normalized === 'equity') return 'stocks'
  if (normalized === 'indices' || normalized === 'index') return 'indices'
  if (normalized === 'futures' || normalized === 'future') return 'futures'
  if (normalized === 'metal' || normalized === 'commodity' || normalized === 'commodities') return 'commodities'
  return 'forex'
}

function badgeLabel(instrument: Instrument) {
  const group = groupFromAssetClass(instrument.asset_class)
  if (group === 'forex') return 'FX'
  if (group === 'crypto') return 'CRYPTO'
  if (group === 'stocks') return 'STOCK'
  if (group === 'indices') return 'INDEX'
  if (group === 'futures') return 'FUT'
  return 'CMDTY'
}

function badgeClass(instrument: Instrument) {
  const group = groupFromAssetClass(instrument.asset_class)
  return `instrument-option-badge is-${group}`
}

function matchesInstrument(instrument: Instrument, term: string) {
  if (!term) return true
  return instrument.symbol.toLowerCase().includes(term)
    || instrument.base_currency.toLowerCase().includes(term)
    || instrument.quote_currency.toLowerCase().includes(term)
    || instrument.asset_class.toLowerCase().includes(term)
}

function isInstrumentActive(instrument: Instrument): boolean {
  const value = (instrument as unknown as { is_active?: unknown }).is_active
  if (value === undefined || value === null) return true
  if (typeof value === 'boolean') return value
  if (typeof value === 'number') return value !== 0
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase()
    return normalized !== '' && normalized !== '0' && normalized !== 'false' && normalized !== 'no'
  }
  return Boolean(value)
}

function readRecentIds(): number[] {
  try {
    const raw = localStorage.getItem(RECENT_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return []
    return parsed
      .map((item) => Number(item))
      .filter((id) => Number.isInteger(id) && id > 0)
      .slice(0, 12)
  } catch {
    return []
  }
}

function writeRecentIds(ids: number[]) {
  try {
    localStorage.setItem(RECENT_KEY, JSON.stringify(ids))
  } catch {
    // Ignore storage write failures.
  }
}

watch(
  () => isOpen.value,
  (open) => {
    if (!open) return
    void nextTick(() => searchInputRef.value?.focus())
  }
)

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
})
</script>

<template>
  <FieldWrapper :label="label" :required="required" :error="error" :hint="hint" :for-id="controlId">
    <template v-if="showLabelHelp" #label-extra>
      <CircleHelp class="h-3.5 w-3.5 instrument-label-help" />
    </template>
    <div ref="rootRef" class="instrument-pair-select" :class="{ 'is-open': isOpen }">
      <button
        :id="controlId"
        type="button"
        :disabled="disabled"
        :class="[controlClass, 'instrument-trigger']"
        :aria-expanded="isOpen"
        aria-haspopup="listbox"
        @click="toggleOpen"
        @keydown="onTriggerKeydown"
      >
        <span class="instrument-trigger-value">{{ selectedInstrument?.symbol || placeholder }}</span>
        <ChevronDown class="h-4 w-4" />
      </button>

      <Transition name="fade">
        <div v-if="isOpen" class="instrument-menu" role="listbox">
          <div class="instrument-search-shell">
            <Search class="h-4 w-4 instrument-search-icon" />
            <input
              ref="searchInputRef"
              v-model="searchTerm"
              type="text"
              class="field control-modern instrument-search-input"
              :placeholder="searchPlaceholder"
              @keydown="onSearchKeydown"
            />
          </div>

          <div class="instrument-menu-scroll">
            <section v-if="clearable" class="instrument-group-block">
              <button
                type="button"
                class="instrument-option"
                :class="{ 'is-selected': !selectedInstrument }"
                :aria-selected="!selectedInstrument"
                @click="selectAll"
              >
                <span class="instrument-option-check">
                  <Check v-if="!selectedInstrument" class="h-3.5 w-3.5" />
                </span>
                <span class="instrument-option-label">{{ allLabel }}</span>
              </button>
            </section>

            <section v-if="visibleFrequentInstruments.length > 0" class="instrument-group-block">
              <div class="instrument-group-head is-fixed">
                <span class="instrument-group-title">
                  <Star class="h-3.5 w-3.5" />
                  FREQUENTLY USED
                </span>
                <span class="pill">{{ visibleFrequentInstruments.length }}</span>
              </div>

              <button
                v-for="instrument in visibleFrequentInstruments"
                :key="`recent-${instrument.id}`"
                type="button"
                class="instrument-option"
                :class="{ 'is-selected': instrument.id === selectedId }"
                :aria-selected="instrument.id === selectedId"
                @click="selectInstrument(instrument)"
              >
                <span class="instrument-option-check">
                  <Check v-if="instrument.id === selectedId" class="h-3.5 w-3.5" />
                </span>
                <span class="instrument-option-label">{{ instrument.symbol }}</span>
                <span :class="badgeClass(instrument)">{{ badgeLabel(instrument) }}</span>
              </button>
            </section>

            <section v-for="group in groupOrder" :key="group" class="instrument-group-block">
              <button
                type="button"
                class="instrument-group-head"
                @click="toggleGroup(group)"
              >
                <span class="instrument-group-title">
                  <ChevronRight v-if="!isGroupOpen(group)" class="h-3.5 w-3.5" />
                  <ChevronDown v-else class="h-3.5 w-3.5" />
                  {{ groupLabels[group] }}
                </span>
                <span class="pill">{{ visibleGroupedInstruments[group].length }}</span>
              </button>

              <div v-if="isGroupOpen(group)" class="instrument-group-list">
                <button
                  v-for="instrument in visibleGroupedInstruments[group]"
                  :key="`group-${group}-${instrument.id}`"
                  type="button"
                  class="instrument-option"
                  :class="{ 'is-selected': instrument.id === selectedId }"
                  :aria-selected="instrument.id === selectedId"
                  @click="selectInstrument(instrument)"
                >
                  <span class="instrument-option-check">
                    <Check v-if="instrument.id === selectedId" class="h-3.5 w-3.5" />
                  </span>
                  <span class="instrument-option-label">{{ instrument.symbol }}</span>
                  <span :class="badgeClass(instrument)">{{ badgeLabel(instrument) }}</span>
                </button>
              </div>
            </section>
          </div>
        </div>
      </Transition>
    </div>
  </FieldWrapper>
</template>

<style scoped>
.instrument-pair-select {
  position: relative;
  margin-top: var(--space-1);
  z-index: var(--z-base);
}

.instrument-pair-select.is-open {
  z-index: var(--z-dropdown);
}

.instrument-trigger {
  margin-top: 0;
  display: inline-flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  text-align: left;
  cursor: pointer;
}

.instrument-label-help {
  color: color-mix(in srgb, var(--muted) 84%, transparent 16%);
}

.instrument-trigger-value {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 700;
}

.instrument-menu {
  position: absolute;
  left: 0;
  right: 0;
  top: calc(100% + 0.45rem);
  z-index: calc(var(--z-dropdown) + 1);
  border: 1px solid color-mix(in srgb, var(--border) 82%, transparent 18%);
  border-radius: 0.95rem;
  background: color-mix(in srgb, var(--panel) 94%, var(--panel-soft) 6%);
  box-shadow: var(--shadow-soft);
  overflow: hidden;
}

.instrument-search-shell {
  position: sticky;
  top: 0;
  z-index: var(--z-base);
  border-bottom: 1px solid color-mix(in srgb, var(--border) 82%, transparent 18%);
  background: color-mix(in srgb, var(--panel) 94%, var(--panel-soft) 6%);
  padding: 0.55rem 0.55rem 0.5rem;
}

.instrument-search-icon {
  position: absolute;
  left: 1.25rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  pointer-events: none;
}

.instrument-search-input {
  margin-top: 0;
  padding-left: 2rem !important;
}

.instrument-menu-scroll {
  max-height: 26rem;
  overflow: auto;
  padding-bottom: 0.35rem;
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.instrument-menu-scroll::-webkit-scrollbar {
  width: 0;
  height: 0;
  display: none;
}

.instrument-group-block {
  border-top: 1px solid color-mix(in srgb, var(--border) 90%, transparent 10%);
}

.instrument-group-head {
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  border: 0;
  background: transparent;
  color: var(--muted);
  font-size: 0.8rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  padding: 0.56rem 0.62rem;
  cursor: pointer;
}

.instrument-group-head.is-fixed {
  cursor: default;
}

.instrument-group-title {
  display: inline-flex;
  align-items: center;
  gap: 0.44rem;
}

.instrument-group-list {
  display: grid;
}

.instrument-option {
  width: 100%;
  display: inline-flex;
  align-items: center;
  gap: 0.56rem;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: var(--text);
  padding: 0.62rem 0.62rem;
  text-align: left;
  cursor: pointer;
}

.instrument-option:hover {
  background: color-mix(in srgb, var(--primary-soft) 36%, transparent 64%);
}

.instrument-option.is-selected {
  background: color-mix(in srgb, var(--warning-soft) 44%, var(--panel) 56%);
}

.instrument-option-check {
  width: 1rem;
  display: inline-grid;
  place-items: center;
  color: color-mix(in srgb, var(--warning) 82%, var(--text) 18%);
}

.instrument-option-label {
  font-size: 0.9rem;
  font-weight: 700;
}

.instrument-option-badge {
  margin-left: auto;
  border: 1px solid transparent;
  border-radius: 0.48rem;
  padding: 0.2rem 0.45rem;
  font-size: 0.67rem;
  font-weight: 800;
  letter-spacing: 0.02em;
}

.instrument-option-badge.is-forex {
  background: color-mix(in srgb, #2563eb 26%, transparent 74%);
  border-color: color-mix(in srgb, #2563eb 50%, transparent 50%);
  color: color-mix(in srgb, #93c5fd 78%, var(--text) 22%);
}

.instrument-option-badge.is-crypto {
  background: color-mix(in srgb, #f59e0b 24%, transparent 76%);
  border-color: color-mix(in srgb, #f59e0b 50%, transparent 50%);
  color: color-mix(in srgb, #fbbf24 80%, var(--text) 20%);
}

.instrument-option-badge.is-stocks {
  background: color-mix(in srgb, #22c55e 24%, transparent 76%);
  border-color: color-mix(in srgb, #22c55e 48%, transparent 52%);
  color: color-mix(in srgb, #86efac 80%, var(--text) 20%);
}

.instrument-option-badge.is-indices {
  background: color-mix(in srgb, #0ea5e9 24%, transparent 76%);
  border-color: color-mix(in srgb, #0ea5e9 48%, transparent 52%);
  color: color-mix(in srgb, #7dd3fc 80%, var(--text) 20%);
}

.instrument-option-badge.is-futures {
  background: color-mix(in srgb, #a855f7 24%, transparent 76%);
  border-color: color-mix(in srgb, #a855f7 48%, transparent 52%);
  color: color-mix(in srgb, #d8b4fe 80%, var(--text) 20%);
}

.instrument-option-badge.is-commodities {
  background: color-mix(in srgb, #f97316 24%, transparent 76%);
  border-color: color-mix(in srgb, #f97316 48%, transparent 52%);
  color: color-mix(in srgb, #fdba74 80%, var(--text) 20%);
}
</style>
