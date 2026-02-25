import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api, { getAuthToken, setAuthToken } from '@/services/api'

const AUTH_USER_ID_KEY = 'tj_auth_user_id'

interface AuthUser {
  id: number
  name: string
  email: string
}

interface AuthResponse {
  token: string
  token_type: string
  user: AuthUser
}

let unauthorizedListenerBound = false

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const initialized = ref(false)
  const loading = ref(false)

  const isAuthenticated = computed(() => Boolean(user.value && getAuthToken()))

  function clearSession() {
    user.value = null
    setAuthToken(null)
    localStorage.removeItem(AUTH_USER_ID_KEY)
  }

  function setUserScope(nextUser: AuthUser | null) {
    if (!nextUser) {
      localStorage.removeItem(AUTH_USER_ID_KEY)
      return
    }
    localStorage.setItem(AUTH_USER_ID_KEY, String(nextUser.id))
  }

  async function initialize() {
    if (initialized.value) return
    if (!unauthorizedListenerBound) {
      window.addEventListener('auth:unauthorized', clearSession)
      unauthorizedListenerBound = true
    }

    const token = getAuthToken()
    if (!token) {
      initialized.value = true
      return
    }

    loading.value = true
    try {
      await fetchMe()
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
      const { data } = await api.post<AuthResponse>('/auth/login', { email, password })
      setAuthToken(data.token)
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
      const { data } = await api.post<AuthResponse>('/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      setAuthToken(data.token)
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
      if (getAuthToken()) {
        await api.post('/auth/logout')
      }
    } catch {
      // Session state is cleared locally regardless of API response.
    } finally {
      clearSession()
      loading.value = false
      initialized.value = true
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
    clearSession,
  }
})
