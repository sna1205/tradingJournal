import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api from '@/services/api'
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

interface AuthConfigResponse {
  allow_self_register?: boolean
}

interface LogoutAllResponse {
  message: string
  revoked_sessions: number
  revoked_tokens: number
  supports_session_revocation: boolean
  session_driver: string
}

type AuthStatus = 'unknown' | 'checking' | 'authenticated' | 'guest'
type AuthAction = 'idle' | 'login' | 'register' | 'logout' | 'logout_all'

let unauthorizedListenerBound = false

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const status = ref<AuthStatus>('unknown')
  const action = ref<AuthAction>('idle')
  const allowSelfRegister = ref(true)
  const initialized = computed(() => status.value !== 'unknown' && status.value !== 'checking')
  const loading = computed(() => status.value === 'checking' || action.value !== 'idle')

  const isAuthenticated = computed(() => status.value === 'authenticated')
  let initPromise: Promise<void> | null = null

  async function clearSession() {
    const previousUserId = user.value?.id ?? getScope().userId
    purgeScopedStorageForUser(previousUserId)
    await purgeLocalFallbackPersistenceForUser(previousUserId)
    setScopeUserId(null)
    setScopeAccountId(null)
    user.value = null
    setSyncQueueUserScope(null)
    migrateLegacyLocalFallbackKeys()
    status.value = 'guest'
  }

  async function setUserScope(nextUser: AuthUser | null) {
    const userId = nextUser?.id ?? null
    setSyncQueueUserScope(userId)
    setScopeUserId(userId)
    setScopeAccountId(null)
    migrateLegacyLocalFallbackKeys()
    await initializeLocalFallbackPersistence()
  }

  async function initialize(force = false) {
    if (initialized.value && !force) {
      return
    }
    if (initPromise && !force) {
      return initPromise
    }
    if (!unauthorizedListenerBound && typeof window !== 'undefined') {
      window.addEventListener('auth:unauthorized', () => {
        void clearSession()
      })
      unauthorizedListenerBound = true
    }

    initPromise = (async () => {
      status.value = 'checking'
      try {
        await fetchAuthConfig()
        await fetchMe()
      } catch {
        await clearSession()
      } finally {
        if (status.value === 'checking') {
          status.value = user.value ? 'authenticated' : 'guest'
        }
      }
    })().finally(() => {
      initPromise = null
    })

    return initPromise
  }

  async function fetchMe() {
    const { data } = await api.get<AuthUser>('/auth/me')
    user.value = data
    await setUserScope(data)
    status.value = 'authenticated'
    return data
  }

  async function fetchAuthConfig() {
    try {
      const { data } = await api.get<AuthConfigResponse>('/auth/config')
      allowSelfRegister.value = Boolean(data?.allow_self_register)
    } catch {
      allowSelfRegister.value = true
    }

    return allowSelfRegister.value
  }

  async function login(email: string, password: string) {
    action.value = 'login'
    try {
      await api.post<AuthResponse>('/auth/login', { email, password })
      return await fetchMe()
    } finally {
      action.value = 'idle'
    }
  }

  async function register(name: string, email: string, password: string, passwordConfirmation: string) {
    if (!allowSelfRegister.value) {
      throw new Error('Self-registration is disabled.')
    }

    action.value = 'register'
    try {
      await api.post<AuthResponse>('/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      return await fetchMe()
    } finally {
      action.value = 'idle'
    }
  }

  async function logout() {
    action.value = 'logout'
    try {
      await api.post('/auth/logout')
    } catch {
      // Session state is cleared locally regardless of API response.
    } finally {
      await clearSession()
      action.value = 'idle'
    }
  }

  async function logoutAll() {
    action.value = 'logout_all'
    try {
      const { data } = await api.post<LogoutAllResponse>('/auth/logout-all')
      return data
    } finally {
      action.value = 'idle'
    }
  }

  return {
    user,
    status,
    action,
    initialized,
    loading,
    allowSelfRegister,
    isAuthenticated,
    initialize,
    fetchAuthConfig,
    fetchMe,
    login,
    register,
    logout,
    logoutAll,
    clearSession,
  }
})
