import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import {
  createLocalAccount,
  deleteLocalAccount,
  fetchLocalAccountAnalytics,
  fetchLocalAccountEquity,
  fetchLocalAccounts,
  shouldUseLocalFallback,
  updateLocalAccount,
} from '@/services/localFallback'
import { useSyncStatusStore } from '@/stores/syncStatusStore'
import type {
  Account,
  AccountAnalyticsPayload,
  AccountChallenge,
  AccountChallengeStatusPayload,
  AccountEquityPayload,
  AccountType,
  ChallengeStatus,
} from '@/types/account'

export interface AccountPayload {
  user_id?: number | null
  name: string
  broker: string
  account_type: AccountType
  starting_balance: number
  currency: string
  is_active: boolean
}

export interface AccountChallengePayload {
  provider?: string
  phase?: string
  starting_balance?: number
  profit_target_pct?: number
  max_daily_loss_pct?: number
  max_total_drawdown_pct?: number
  min_trading_days?: number
  start_date?: string
  status?: ChallengeStatus
  passed_at?: string | null
  failed_at?: string | null
}

const SELECTED_ACCOUNT_KEY = 'analytics_selected_account_id'

export const useAccountStore = defineStore('accounts', () => {
  const syncStatusStore = useSyncStatusStore()
  const accounts = ref<Account[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const selectedAccountId = ref<number | null>(readSelectedAccountId())

  const activeAccounts = computed(() => accounts.value.filter((account) => account.is_active))
  const selectedAccount = computed(() =>
    accounts.value.find((account) => account.id === selectedAccountId.value) ?? null
  )

  function setSelectedAccountId(accountId: number | null) {
    selectedAccountId.value = accountId
    if (accountId === null) {
      localStorage.removeItem(SELECTED_ACCOUNT_KEY)
      return
    }

    localStorage.setItem(SELECTED_ACCOUNT_KEY, String(accountId))
  }

  async function fetchAccounts(params?: { is_active?: boolean }) {
    loading.value = true
    try {
      const { data } = await api.get<unknown>('/accounts', {
        params,
      })
      syncStatusStore.markServerHealthy()
      const source = Array.isArray(data)
        ? data
        : (isRecord(data) && Array.isArray(data.data) ? data.data : [])
      accounts.value = source
        .map((row) => normalizeAccount(row))
        .filter((row): row is Account => row !== null)

      if (selectedAccountId.value !== null) {
        const exists = accounts.value.some((account) => account.id === selectedAccountId.value)
        if (!exists) {
          setSelectedAccountId(null)
        }
      }
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }

      syncStatusStore.markLocalFallback('accounts')
      accounts.value = fetchLocalAccounts(params)
      if (selectedAccountId.value !== null) {
        const exists = accounts.value.some((account) => account.id === selectedAccountId.value)
        if (!exists) {
          setSelectedAccountId(null)
        }
      }
    } finally {
      loading.value = false
    }
  }

  async function createAccount(payload: AccountPayload) {
    saving.value = true
    try {
      const { data } = await api.post<unknown>('/accounts', payload)
      syncStatusStore.markServerHealthy()
      const normalized = normalizeAccount(data)
      if (!normalized) {
        throw new Error('Invalid account payload returned by API.')
      }
      accounts.value = [normalized, ...accounts.value]
      return normalized
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }

      syncStatusStore.markLocalFallback('accounts')
      const data = createLocalAccount(payload)
      accounts.value = fetchLocalAccounts()
      return data
    } finally {
      saving.value = false
    }
  }

  async function updateAccount(id: number, payload: Partial<AccountPayload>) {
    saving.value = true
    try {
      const { data } = await api.put<unknown>(`/accounts/${id}`, payload)
      syncStatusStore.markServerHealthy()
      const normalized = normalizeAccount(data)
      if (!normalized) {
        throw new Error('Invalid account payload returned by API.')
      }
      const index = accounts.value.findIndex((account) => account.id === id)
      if (index >= 0) {
        accounts.value[index] = normalized
      } else {
        accounts.value.push(normalized)
      }
      return normalized
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }

      syncStatusStore.markLocalFallback('accounts')
      const data = updateLocalAccount(id, payload)
      accounts.value = fetchLocalAccounts()
      return data
    } finally {
      saving.value = false
    }
  }

  async function deleteAccount(id: number) {
    saving.value = true
    try {
      await api.delete(`/accounts/${id}`)
      syncStatusStore.markServerHealthy()
      accounts.value = accounts.value.filter((account) => account.id !== id)
      if (selectedAccountId.value === id) {
        setSelectedAccountId(null)
      }
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }

      syncStatusStore.markLocalFallback('accounts')
      deleteLocalAccount(id)
      accounts.value = fetchLocalAccounts()
      if (selectedAccountId.value === id) {
        setSelectedAccountId(null)
      }
    } finally {
      saving.value = false
    }
  }

  async function fetchAccountEquity(id: number) {
    try {
      const { data } = await api.get<AccountEquityPayload>(`/accounts/${id}/equity`)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('account-equity')
      return fetchLocalAccountEquity(id)
    }
  }

  async function fetchAccountAnalytics(id: number) {
    try {
      const { data } = await api.get<AccountAnalyticsPayload>(`/accounts/${id}/analytics`)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('account-analytics')
      return fetchLocalAccountAnalytics(id)
    }
  }

  async function fetchAccountChallenge(id: number): Promise<AccountChallenge | null> {
    try {
      const { data } = await api.get<AccountChallenge>(`/accounts/${id}/challenge`)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('account-challenge')
      return null
    }
  }

  async function updateAccountChallenge(id: number, payload: AccountChallengePayload): Promise<AccountChallenge> {
    try {
      const { data } = await api.put<AccountChallenge>(`/accounts/${id}/challenge`, payload)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('account-challenge')
      throw new Error('Challenge configuration is unavailable in local fallback mode.')
    }
  }

  async function fetchAccountChallengeStatus(id: number): Promise<AccountChallengeStatusPayload | null> {
    try {
      const { data } = await api.get<AccountChallengeStatusPayload>(`/accounts/${id}/challenge-status`)
      syncStatusStore.markServerHealthy()
      return data
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('account-challenge-status')
      return null
    }
  }

  return {
    accounts,
    activeAccounts,
    selectedAccountId,
    selectedAccount,
    loading,
    saving,
    setSelectedAccountId,
    fetchAccounts,
    createAccount,
    updateAccount,
    deleteAccount,
    fetchAccountEquity,
    fetchAccountAnalytics,
    fetchAccountChallenge,
    updateAccountChallenge,
    fetchAccountChallengeStatus,
  }
})

function readSelectedAccountId(): number | null {
  const raw = localStorage.getItem(SELECTED_ACCOUNT_KEY)
  if (!raw) return null

  const value = Number(raw)
  if (!Number.isInteger(value) || value <= 0) return null
  return value
}

function normalizeAccount(input: unknown): Account | null {
  if (!isRecord(input)) return null

  const id = toInt(input.id)
  if (id <= 0) return null

  const startingBalance = asMoney(input.starting_balance)
  const currentBalance = asMoney(input.current_balance ?? input.starting_balance)

  return {
    id,
    user_id: input.user_id === null || input.user_id === undefined ? null : toInt(input.user_id),
    name: String(input.name ?? 'Account'),
    broker: String(input.broker ?? 'Broker'),
    account_type: normalizeAccountType(input.account_type),
    starting_balance: startingBalance,
    current_balance: currentBalance,
    currency: String(input.currency ?? 'USD').toUpperCase(),
    is_active: Boolean(input.is_active ?? true),
    created_at: String(input.created_at ?? new Date().toISOString()),
    updated_at: String(input.updated_at ?? new Date().toISOString()),
  }
}

function normalizeAccountType(value: unknown): AccountType {
  const normalized = String(value ?? 'personal').toLowerCase()
  if (normalized === 'funded' || normalized === 'personal' || normalized === 'demo') {
    return normalized
  }

  // Accept legacy naming variants to avoid UI grouping failures.
  if (normalized === 'prop') return 'funded'
  if (normalized === 'live') return 'personal'

  return 'personal'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function toInt(value: unknown): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? Math.trunc(parsed) : 0
}

function asMoney(value: unknown): string {
  const parsed = Number(value)
  const safe = Number.isFinite(parsed) ? parsed : 0
  return safe.toFixed(2)
}
