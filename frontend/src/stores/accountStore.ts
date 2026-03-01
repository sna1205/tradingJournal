import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import {
  enqueueSyncCreate,
  enqueueSyncDelete,
  enqueueSyncUpdate,
} from '@/services/offlineSyncQueue'
import {
  createLocalAccount,
  deleteLocalAccount,
  fetchLocalAccountAnalytics,
  fetchLocalAccountEquity,
  fetchLocalAccounts,
  setLocalAccountSyncStatus,
  shouldUseLocalFallback,
  upsertLocalAccountSnapshot,
  updateLocalAccount,
} from '@/services/localFallback'
import { createRequestManager, isAbortError, stableSerialize } from '@/services/requestManager'
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
  const requestManager = createRequestManager()
  const accounts = ref<Account[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const selectedAccountId = ref<number | null>(readSelectedAccountId())
  let fetchAccountsRequestVersion = 0

  const activeAccounts = computed(() => accounts.value.filter((account) => account.is_active))
  const selectedAccount = computed(() =>
    accounts.value.find((account) => account.id === selectedAccountId.value) ?? null
  )

  function invalidateAccountLoadCaches(): void {
    requestManager.invalidateCacheByPrefix('accounts:')
  }

  function setSelectedAccountId(accountId: number | null) {
    selectedAccountId.value = accountId
    if (accountId === null) {
      localStorage.removeItem(SELECTED_ACCOUNT_KEY)
      return
    }

    localStorage.setItem(SELECTED_ACCOUNT_KEY, String(accountId))
  }

  async function fetchAccounts(params?: { is_active?: boolean }) {
    const requestVersion = ++fetchAccountsRequestVersion
    loading.value = true
    const requestParams = {
      is_active: params?.is_active,
    }
    const fingerprint = stableSerialize(requestParams)
    try {
      const response = await requestManager.run({
        key: 'fetchAccounts',
        fingerprint,
        cacheKey: `accounts:fetch:${fingerprint}`,
        cacheTtlMs: 1_500,
        execute: async ({ signal }) => {
          const { data } = await api.get<unknown>('/accounts', {
            params,
            signal,
          })
          return data
        },
      })
      if (response.stale) return
      const data = response.value
      syncStatusStore.markServerHealthy()
      const source = Array.isArray(data)
        ? data
        : (isRecord(data) && Array.isArray(data.data) ? data.data : [])
      accounts.value = source
        .map((row) => normalizeAccount(row))
        .filter((row): row is Account => row !== null)
      for (const account of accounts.value) {
        upsertLocalAccountSnapshot({
          ...account,
          local_sync_status: 'synced',
        })
      }

      if (selectedAccountId.value !== null) {
        const exists = accounts.value.some((account) => account.id === selectedAccountId.value)
        if (!exists) {
          setSelectedAccountId(null)
        }
      }
    } catch (error) {
      if (isAbortError(error)) {
        return
      }

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
      if (requestVersion === fetchAccountsRequestVersion) {
        loading.value = false
      }
    }
  }

  async function createAccount(payload: AccountPayload) {
    saving.value = true
    try {
      const { data } = await api.post<unknown>('/accounts', payload)
      syncStatusStore.markServerHealthy()
      invalidateAccountLoadCaches()
      const normalized = normalizeAccount(data)
      if (!normalized) {
        throw new Error('Invalid account payload returned by API.')
      }
      upsertLocalAccountSnapshot({
        ...normalized,
        local_sync_status: 'synced',
      })
      accounts.value = [normalized, ...accounts.value]
      return normalized
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }

      syncStatusStore.markLocalFallback('accounts')
      invalidateAccountLoadCaches()
      const data = createLocalAccount(payload)
      enqueueSyncCreate({
        entity: 'accounts',
        local_id: data.id,
        payload: pruneUndefined(payload),
        context: 'accounts',
      })
      setLocalAccountSyncStatus(data.id, 'draft_local')
      void syncStatusStore.refreshQueueState()
      accounts.value = fetchLocalAccounts()
      return data
    } finally {
      saving.value = false
    }
  }

  async function updateAccount(id: number, payload: Partial<AccountPayload>) {
    saving.value = true
    const existing = accounts.value.find((account) => account.id === id) ?? null
    try {
      const { data } = await api.put<unknown>(`/accounts/${id}`, payload)
      syncStatusStore.markServerHealthy()
      invalidateAccountLoadCaches()
      const normalized = normalizeAccount(data)
      if (!normalized) {
        throw new Error('Invalid account payload returned by API.')
      }
      upsertLocalAccountSnapshot({
        ...normalized,
        local_sync_status: 'synced',
      })
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
      invalidateAccountLoadCaches()
      if (existing) {
        upsertLocalAccountSnapshot(existing)
      }
      const data = updateLocalAccount(id, payload)
      enqueueSyncUpdate({
        entity: 'accounts',
        local_id: data.id,
        server_id: id,
        expected_updated_at: existing?.updated_at ?? null,
        payload: pruneUndefined(payload),
        context: 'accounts',
      })
      setLocalAccountSyncStatus(data.id, 'draft_local')
      void syncStatusStore.refreshQueueState()
      accounts.value = fetchLocalAccounts()
      return data
    } finally {
      saving.value = false
    }
  }

  async function deleteAccount(id: number) {
    saving.value = true
    const existing = accounts.value.find((account) => account.id === id) ?? null
    try {
      await api.delete(`/accounts/${id}`)
      syncStatusStore.markServerHealthy()
      invalidateAccountLoadCaches()
      try {
        deleteLocalAccount(id)
      } catch {
        // Local mirror cleanup is best-effort.
      }
      accounts.value = accounts.value.filter((account) => account.id !== id)
      if (selectedAccountId.value === id) {
        setSelectedAccountId(null)
      }
    } catch (error) {
      if (!shouldUseLocalFallback(error)) {
        throw error
      }

      syncStatusStore.markLocalFallback('accounts')
      invalidateAccountLoadCaches()
      if (existing) {
        upsertLocalAccountSnapshot(existing)
      }
      deleteLocalAccount(id)
      enqueueSyncDelete({
        entity: 'accounts',
        local_id: id,
        server_id: id,
        expected_updated_at: existing?.updated_at ?? null,
        context: 'accounts',
      })
      void syncStatusStore.refreshQueueState()
      accounts.value = fetchLocalAccounts()
      if (selectedAccountId.value === id) {
        setSelectedAccountId(null)
      }
    } finally {
      saving.value = false
    }
  }

  async function fetchAccountEquity(id: number) {
    const fingerprint = `id:${id}`
    try {
      const response = await requestManager.run({
        key: `fetchAccountEquity:${id}`,
        fingerprint,
        cacheKey: `accounts:equity:${fingerprint}`,
        cacheTtlMs: 2_000,
        execute: async ({ signal }) => {
          const { data } = await api.get<AccountEquityPayload>(`/accounts/${id}/equity`, { signal })
          return data
        },
      })
      if (response.stale) {
        return response.value
      }
      syncStatusStore.markServerHealthy()
      return response.value
    } catch (error) {
      if (isAbortError(error)) {
        throw error
      }
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('account-equity')
      return fetchLocalAccountEquity(id)
    }
  }

  async function fetchAccountAnalytics(id: number) {
    const fingerprint = `id:${id}`
    try {
      const response = await requestManager.run({
        key: `fetchAccountAnalytics:${id}`,
        fingerprint,
        cacheKey: `accounts:analytics:${fingerprint}`,
        cacheTtlMs: 2_000,
        execute: async ({ signal }) => {
          const { data } = await api.get<AccountAnalyticsPayload>(`/accounts/${id}/analytics`, { signal })
          return data
        },
      })
      if (response.stale) {
        return response.value
      }
      syncStatusStore.markServerHealthy()
      return response.value
    } catch (error) {
      if (isAbortError(error)) {
        throw error
      }
      if (!shouldUseLocalFallback(error)) {
        throw error
      }
      syncStatusStore.markLocalFallback('account-analytics')
      return fetchLocalAccountAnalytics(id)
    }
  }

  async function fetchAccountChallenge(id: number): Promise<AccountChallenge | null> {
    const fingerprint = `id:${id}`
    try {
      const response = await requestManager.run({
        key: `fetchAccountChallenge:${id}`,
        fingerprint,
        cacheKey: `accounts:challenge:${fingerprint}`,
        cacheTtlMs: 2_000,
        execute: async ({ signal }) => {
          const { data } = await api.get<AccountChallenge>(`/accounts/${id}/challenge`, { signal })
          return data
        },
      })
      if (response.stale) {
        return response.value
      }
      syncStatusStore.markServerHealthy()
      return response.value
    } catch (error) {
      if (isAbortError(error)) {
        throw error
      }
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

  async function fetchAccountChallengeStatus(id: number, signal?: AbortSignal): Promise<AccountChallengeStatusPayload | null> {
    const fingerprint = `id:${id}`
    try {
      const response = await requestManager.run({
        key: `fetchAccountChallengeStatus:${id}`,
        fingerprint,
        cacheKey: `accounts:challenge-status:${fingerprint}`,
        cacheTtlMs: 1_500,
        externalSignal: signal,
        execute: async ({ signal: managedSignal }) => {
          const { data } = await api.get<AccountChallengeStatusPayload>(`/accounts/${id}/challenge-status`, {
            signal: managedSignal,
          })
          return data
        },
      })
      if (response.stale) {
        return response.value
      }
      syncStatusStore.markServerHealthy()
      return response.value
    } catch (error) {
      if (isAbortError(error)) {
        throw error
      }
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

function pruneUndefined<T extends object>(payload: T): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(payload as Record<string, unknown>).filter(([, value]) => value !== undefined)
  )
}
