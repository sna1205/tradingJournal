import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api, { ensureCsrfCookie } from '@/services/api'
import { setSyncQueueUserScope } from '@/services/offlineSyncQueue'

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

  function clearSession() {
    user.value = null
    setSyncQueueUserScope(null)
  }

  function setUserScope(nextUser: AuthUser | null) {
    setSyncQueueUserScope(nextUser?.id ?? null)
  }

  async function initialize() {
    if (initialized.value) return
    if (!unauthorizedListenerBound) {
      window.addEventListener('auth:unauthorized', clearSession)
      unauthorizedListenerBound = true
    }

    loading.value = true
    try {
      await fetchMe()
    } catch {
      clearSession()
    } finally {
      loading.value = false
      initialized.value = true
    }
  }

  async function fetchMe() {
    const { data } = await api.get<AuthUser>('/auth/me')
    user.value = data
    setUserScope(data)
    return data
  }

  async function login(email: string, password: string) {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const { data } = await api.post<AuthResponse>('/auth/login', { email, password })
      user.value = data.user
      setUserScope(data.user)
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
      setUserScope(data.user)
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
      clearSession()
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
