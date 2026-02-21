import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/services/api'
import type { Account, AccountAnalyticsPayload, AccountEquityPayload, AccountType } from '@/types/account'

export interface AccountPayload {
  user_id?: number | null
  name: string
  broker: string
  account_type: AccountType
  starting_balance: number
  currency: string
  is_active: boolean
}

const SELECTED_ACCOUNT_KEY = 'analytics_selected_account_id'

export const useAccountStore = defineStore('accounts', () => {
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
      const { data } = await api.get<Account[]>('/accounts', {
        params,
      })
      accounts.value = data

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
      const { data } = await api.post<Account>('/accounts', payload)
      accounts.value = [data, ...accounts.value]
      return data
    } finally {
      saving.value = false
    }
  }

  async function updateAccount(id: number, payload: Partial<AccountPayload>) {
    saving.value = true
    try {
      const { data } = await api.put<Account>(`/accounts/${id}`, payload)
      const index = accounts.value.findIndex((account) => account.id === id)
      if (index >= 0) {
        accounts.value[index] = data
      } else {
        accounts.value.push(data)
      }
      return data
    } finally {
      saving.value = false
    }
  }

  async function deleteAccount(id: number) {
    saving.value = true
    try {
      await api.delete(`/accounts/${id}`)
      accounts.value = accounts.value.filter((account) => account.id !== id)
      if (selectedAccountId.value === id) {
        setSelectedAccountId(null)
      }
    } finally {
      saving.value = false
    }
  }

  async function fetchAccountEquity(id: number) {
    const { data } = await api.get<AccountEquityPayload>(`/accounts/${id}/equity`)
    return data
  }

  async function fetchAccountAnalytics(id: number) {
    const { data } = await api.get<AccountAnalyticsPayload>(`/accounts/${id}/analytics`)
    return data
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
  }
})

function readSelectedAccountId(): number | null {
  const raw = localStorage.getItem(SELECTED_ACCOUNT_KEY)
  if (!raw) return null

  const value = Number(raw)
  if (!Number.isInteger(value) || value <= 0) return null
  return value
}
