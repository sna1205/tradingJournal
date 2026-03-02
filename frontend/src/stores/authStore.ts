import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api, { ensureCsrfCookie } from '@/services/api'
import { setSyncQueueUserScope } from '@/services/offlineSyncQueue'
import {
  initializeLocalFallbackPersistence,
  migrateLegacyLocalFallbackKeys,
  purgeLocalFallbackPersistenceForUser,
} from '@/services/localFallback'
import { getScope, purgeScopedStorageForUser, setScopeAccountId, setScopeUserId } from '@/services/storageScope'

interface AuthUser {
  id: number
  name: string
  email: string
}

interface AuthResponse {
  user: AuthUser
}

interface LogoutAllResponse {
  message: string
  revoked_sessions: number
  revoked_tokens: number
}

let unauthorizedListenerBound = false

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const initialized = ref(false)
  const loading = ref(false)

  const isAuthenticated = computed(() => Boolean(user.value))

  async function clearSession() {
    const previousUserId = user.value?.id ?? getScope().userId
    purgeScopedStorageForUser(previousUserId)
    await purgeLocalFallbackPersistenceForUser(previousUserId)
    setScopeUserId(null)
    setScopeAccountId(null)
    user.value = null
    setSyncQueueUserScope(null)
    migrateLegacyLocalFallbackKeys()
  }

  async function setUserScope(nextUser: AuthUser | null) {
    const userId = nextUser?.id ?? null
    setSyncQueueUserScope(userId)
    setScopeUserId(userId)
    setScopeAccountId(null)
    migrateLegacyLocalFallbackKeys()
    await initializeLocalFallbackPersistence()
  }

  async function initialize() {
    if (initialized.value) return
    if (!unauthorizedListenerBound) {
      window.addEventListener('auth:unauthorized', () => {
        void clearSession()
      })
      unauthorizedListenerBound = true
    }

    loading.value = true
    try {
      await fetchMe()
    } catch {
      await clearSession()
    } finally {
      loading.value = false
      initialized.value = true
    }
  }

  async function fetchMe() {
    const { data } = await api.get<AuthUser>('/auth/me')
    user.value = data
    await setUserScope(data)
    return data
  }

  async function login(email: string, password: string) {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const { data } = await api.post<AuthResponse>('/auth/login', { email, password })
      user.value = data.user
      await setUserScope(data.user)
      initialized.value = true
      return data.user
    } finally {
      loading.value = false
    }
  }

  async function register(name: string, email: string, password: string, passwordConfirmation: string) {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const { data } = await api.post<AuthResponse>('/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      user.value = data.user
      await setUserScope(data.user)
      initialized.value = true
      return data.user
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    loading.value = true
    try {
      await ensureCsrfCookie()
      await api.post('/auth/logout')
    } catch {
      // Session state is cleared locally regardless of API response.
    } finally {
      await clearSession()
      loading.value = false
      initialized.value = true
    }
  }

  async function logoutAll() {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const { data } = await api.post<LogoutAllResponse>('/auth/logout-all')
      initialized.value = true
      return data
    } finally {
      loading.value = false
    }
  }

  return {
    user,
    initialized,
    loading,
    isAuthenticated,
    initialize,
    fetchMe,
    login,
    register,
    logout,
    logoutAll,
    clearSession,
  }
})
