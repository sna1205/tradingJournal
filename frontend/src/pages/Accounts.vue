<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import type { AxiosError } from 'axios'
import { Pencil, Plus, Trash2, WalletCards, X } from 'lucide-vue-next'
import GlassPanel from '@/components/layout/GlassPanel.vue'
import SkeletonBlock from '@/components/layout/SkeletonBlock.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import BaseInput from '@/components/form/BaseInput.vue'
import BaseSelect from '@/components/form/BaseSelect.vue'
import MiniSparkline from '@/components/charts/MiniSparkline.vue'
import { useAccountStore, type AccountPayload } from '@/stores/accountStore'
import { useUiStore } from '@/stores/uiStore'
import { asCurrency } from '@/utils/format'
import type { Account, AccountType } from '@/types/account'

interface AccountAnalyticsRow {
  account_id: number
  name: string
  broker: string
  account_type: AccountType
  currency: string
  is_active: boolean
  starting_balance: number
  current_balance: number
  computed_current_balance: number
  total_trades: number
  win_rate: number
  net_profit: number
  profit_factor: number | null
  expectancy: number
  max_drawdown: number
  max_drawdown_percent: number
}

const accountStore = useAccountStore()
const uiStore = useUiStore()
const { accounts, loading, saving } = storeToRefs(accountStore)

const analyticsRows = ref<AccountAnalyticsRow[]>([])
const sparklineByAccountId = ref<Record<number, number[]>>({})
const loadingAnalytics = ref(false)

const modalOpen = ref(false)
const isEditing = ref(false)
const editingId = ref<number | null>(null)
const submitAttempted = ref(false)

const accountTypeOptions = [
  { label: 'Funded', value: 'funded' },
  { label: 'Personal', value: 'personal' },
  { label: 'Demo', value: 'demo' },
]

const statusOptions = [
  { label: 'Active', value: 'true' },
  { label: 'Inactive', value: 'false' },
]

const form = reactive({
  name: '',
  broker: '',
  account_type: 'personal' as AccountType,
  starting_balance: 10000,
  currency: 'USD',
  is_active: true,
})

const cards = computed(() =>
  accounts.value.map((account) => {
    const analytics = analyticsRows.value.find((row) => row.account_id === account.id)
    return {
      account,
      analytics,
      sparkline: sparklineByAccountId.value[account.id] ?? [],
    }
  })
)

const formErrors = computed<Record<string, string>>(() => {
  const errors: Record<string, string> = {}

  if (!form.name.trim()) {
    errors.name = 'Account name is required.'
  }
  if (!form.broker.trim()) {
    errors.broker = 'Broker is required.'
  }
  if (!(Number(form.starting_balance) > 0)) {
    errors.starting_balance = 'Starting balance must be greater than 0.'
  }
  if (!form.currency.trim()) {
    errors.currency = 'Currency is required.'
  }

  return errors
})

function fieldError(name: string) {
  return submitAttempted.value ? formErrors.value[name] : ''
}

function resetForm() {
  form.name = ''
  form.broker = ''
  form.account_type = 'personal'
  form.starting_balance = 10000
  form.currency = 'USD'
  form.is_active = true
  editingId.value = null
  isEditing.value = false
  submitAttempted.value = false
}

function openCreateModal() {
  resetForm()
  modalOpen.value = true
}

function openEditModal(account: Account) {
  resetForm()
  isEditing.value = true
  editingId.value = account.id
  form.name = account.name
  form.broker = account.broker
  form.account_type = account.account_type
  form.starting_balance = Number(account.starting_balance)
  form.currency = account.currency
  form.is_active = account.is_active
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

function toPayload(): AccountPayload {
  return {
    name: form.name.trim(),
    broker: form.broker.trim(),
    account_type: form.account_type,
    starting_balance: Number(form.starting_balance),
    currency: form.currency.trim().toUpperCase(),
    is_active: form.is_active,
  }
}

async function submitAccount() {
  submitAttempted.value = true
  const firstError = Object.values(formErrors.value)[0]
  if (firstError) {
    uiStore.toast({
      type: 'error',
      title: 'Invalid account input',
      message: firstError,
    })
    return
  }

  try {
    const payload = toPayload()
    if (isEditing.value && editingId.value !== null) {
      await accountStore.updateAccount(editingId.value, payload)
      uiStore.toast({ type: 'success', title: 'Account updated' })
    } else {
      await accountStore.createAccount(payload)
      uiStore.toast({ type: 'success', title: 'Account created' })
    }

    closeModal()
    await fetchAnalyticsData()
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Failed to save account',
      message: extractErrorMessage(error),
    })
  }
}

async function removeAccount(account: Account) {
  const confirmed = await uiStore.askConfirmation({
    title: 'Delete account?',
    message: `Delete ${account.name}? This cannot be undone.`,
    confirmText: 'Delete',
    danger: true,
  })
  if (!confirmed) return

  try {
    await accountStore.deleteAccount(account.id)
    delete sparklineByAccountId.value[account.id]
    analyticsRows.value = analyticsRows.value.filter((row) => row.account_id !== account.id)
    uiStore.toast({ type: 'success', title: 'Account deleted' })
  } catch (error) {
    uiStore.toast({
      type: 'error',
      title: 'Delete failed',
      message: extractErrorMessage(error),
    })
  }
}

async function fetchAnalyticsData() {
  loadingAnalytics.value = true
  try {
    const rows = await Promise.all(
      accounts.value.map(async (account) => {
        try {
          const data = await accountStore.fetchAccountAnalytics(account.id)
          const currentBalance = Number(account.current_balance)
          return {
            account_id: account.id,
            name: account.name,
            broker: account.broker,
            account_type: account.account_type,
            currency: account.currency,
            is_active: account.is_active,
            starting_balance: Number(account.starting_balance),
            current_balance: currentBalance,
            computed_current_balance: currentBalance,
            total_trades: Number(data.total_trades ?? 0),
            win_rate: Number(data.win_rate ?? 0),
            net_profit: Number(data.net_profit ?? 0),
            profit_factor: data.profit_factor === null ? null : Number(data.profit_factor),
            expectancy: Number(data.expectancy ?? 0),
            max_drawdown: Number(data.max_drawdown ?? 0),
            max_drawdown_percent: Number(data.max_drawdown_percent ?? 0),
          } satisfies AccountAnalyticsRow
        } catch {
          return null
        }
      })
    )
    analyticsRows.value = rows.filter((item): item is AccountAnalyticsRow => item !== null)

    const sparklineEntries = await Promise.all(
      accounts.value.map(async (account) => {
        try {
          const equity = await accountStore.fetchAccountEquity(account.id)
          return [account.id, equity.equity_points] as const
        } catch {
          return [account.id, []] as const
        }
      })
    )

    sparklineByAccountId.value = Object.fromEntries(sparklineEntries)
  } finally {
    loadingAnalytics.value = false
  }
}

function accountTypeBadgeClass(type: AccountType) {
  if (type === 'funded') return 'pill pill-badge-funded'
  if (type === 'personal') return 'pill pill-badge-personal'
  return 'pill pill-badge-demo'
}

function accountCardClass(type: AccountType) {
  return type === 'funded' ? 'account-card-funded' : ''
}

function asReturnPercent(netProfit: number, startingBalance: number) {
  if (startingBalance <= 0) return '0.00%'
  return `${((netProfit / startingBalance) * 100).toFixed(2)}%`
}

function extractErrorMessage(error: unknown): string {
  const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
  const responseMessage = axiosError.response?.data?.message
  const responseErrors = axiosError.response?.data?.errors
  const firstValidationError = responseErrors
    ? Object.values(responseErrors).flat().find((message) => Boolean(message))
    : null

  return firstValidationError || responseMessage || 'Please review values and try again.'
}

onMounted(async () => {
  try {
    await accountStore.fetchAccounts()
    await fetchAnalyticsData()
  } catch {
    uiStore.toast({
      type: 'error',
      title: 'Failed to load accounts',
      message: 'Please refresh and try again.',
    })
  }
})
</script>

<template>
  <div class="space-y-5 accounts-minimal">
    <GlassPanel>
      <div class="section-head">
        <div>
          <h2 class="section-title">Account Center</h2>
          <p class="section-note">Manage funded, personal, and demo accounts with isolated balance and performance tracking.</p>
        </div>
        <button type="button" class="btn btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm" @click="openCreateModal">
          <Plus class="h-4 w-4" />
          New Account
        </button>
      </div>
    </GlassPanel>

    <div v-if="loading || loadingAnalytics" class="grid grid-premium md:grid-cols-2 xl:grid-cols-3">
      <SkeletonBlock v-for="item in 6" :key="`account-skeleton-${item}`" height-class="h-56" rounded-class="rounded-2xl" />
    </div>

    <EmptyState
      v-else-if="cards.length === 0"
      title="No accounts yet"
      description="Create your first account to start account-isolated analytics."
      :icon="WalletCards"
      cta-text="New Account"
      @cta="openCreateModal"
    />

    <section v-else class="grid grid-premium md:grid-cols-2 xl:grid-cols-3">
      <GlassPanel
        v-for="row in cards"
        :key="row.account.id"
        class="account-card"
        :class="accountCardClass(row.account.account_type)"
      >
        <div class="section-head">
          <div>
            <p class="text-base font-semibold">{{ row.account.name }}</p>
            <p class="text-xs muted">{{ row.account.broker }}</p>
          </div>
          <span :class="accountTypeBadgeClass(row.account.account_type)">{{ row.account.account_type }}</span>
        </div>

        <div class="grid grid-cols-2 gap-3 text-sm">
          <article class="panel p-3">
            <p class="kicker-label">Starting</p>
            <p class="mt-1 value-display font-semibold">{{ asCurrency(Number(row.account.starting_balance)) }}</p>
          </article>
          <article class="panel p-3">
            <p class="kicker-label">Current</p>
            <p class="mt-1 value-display font-semibold">{{ asCurrency(Number(row.account.current_balance)) }}</p>
          </article>
          <article class="panel p-3">
            <p class="kicker-label">Net Profit</p>
            <p class="mt-1 value-display font-semibold" :class="Number(row.analytics?.net_profit ?? 0) >= 0 ? 'positive' : 'negative'">
              {{ asCurrency(Number(row.analytics?.net_profit ?? 0)) }}
            </p>
          </article>
          <article class="panel p-3">
            <p class="kicker-label">Return</p>
            <p class="mt-1 value-display font-semibold" :class="Number(row.analytics?.net_profit ?? 0) >= 0 ? 'positive' : 'negative'">
              {{ asReturnPercent(Number(row.analytics?.net_profit ?? 0), Number(row.account.starting_balance)) }}
            </p>
          </article>
        </div>

        <article class="panel mt-3 p-3">
          <div class="mb-2 flex items-center justify-between">
            <p class="kicker-label">Drawdown</p>
            <p class="value-display text-xs" :class="Number(row.analytics?.max_drawdown_percent ?? 0) > 10 ? 'negative' : 'muted'">
              {{ Number(row.analytics?.max_drawdown_percent ?? 0).toFixed(2) }}%
            </p>
          </div>
          <MiniSparkline
            :values="row.sparkline"
            :positive="Number(row.analytics?.net_profit ?? 0) >= 0"
          />
        </article>

        <div class="mt-3 flex items-center justify-end gap-2">
          <button type="button" class="btn btn-ghost p-2" @click="openEditModal(row.account)">
            <Pencil class="h-4 w-4" />
          </button>
          <button type="button" class="btn btn-ghost is-danger p-2" @click="removeAccount(row.account)">
            <Trash2 class="h-4 w-4" />
          </button>
        </div>
      </GlassPanel>
    </section>

    <Transition name="fade">
      <div v-if="modalOpen" class="app-modal-overlay fixed inset-0 flex items-center justify-center bg-black/45 px-4 backdrop-blur-sm">
        <div class="panel w-full max-w-2xl p-6">
          <div class="section-head">
            <h3 class="section-title">{{ isEditing ? 'Edit Account' : 'New Account' }}</h3>
            <button type="button" class="btn btn-ghost p-2" @click="closeModal">
              <X class="h-4 w-4" />
            </button>
          </div>

          <form class="form-block space-y-4" @submit.prevent="submitAccount">
            <div class="grid grid-premium md:grid-cols-2">
              <BaseInput v-model="form.name" label="Account Name" required placeholder="FTMO 100K" :error="fieldError('name')" />
              <BaseInput v-model="form.broker" label="Broker" required placeholder="FTMO" :error="fieldError('broker')" />
              <BaseSelect v-model="form.account_type" label="Account Type" :options="accountTypeOptions" />
              <BaseSelect
                :model-value="String(form.is_active)"
                label="Status"
                :options="statusOptions"
                @update:model-value="form.is_active = $event === 'true'"
              />
              <BaseInput
                v-model="form.starting_balance"
                label="Starting Balance"
                type="number"
                required
                min="0.01"
                step="0.01"
                :error="fieldError('starting_balance')"
              />
              <BaseInput v-model="form.currency" label="Currency" required placeholder="USD" :error="fieldError('currency')" />
            </div>

            <div class="flex items-center justify-end gap-2">
              <button type="button" class="btn btn-ghost px-4 py-2 text-sm" @click="closeModal">Cancel</button>
              <button type="submit" class="btn btn-primary px-4 py-2 text-sm" :disabled="saving">
                {{ saving ? 'Saving...' : isEditing ? 'Update Account' : 'Create Account' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </div>
</template>
