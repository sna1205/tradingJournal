import axios from 'axios'

const AUTH_TOKEN_KEY = 'tj_auth_token'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  timeout: 10000,
  withCredentials: ['1', 'true'].includes(
    String(import.meta.env.VITE_API_WITH_CREDENTIALS || '').toLowerCase()
  ),
})

export function getAuthToken(): string | null {
  const token = localStorage.getItem(AUTH_TOKEN_KEY)
  return token && token.trim() !== '' ? token : null
}

export function setAuthToken(token: string | null) {
  if (!token || token.trim() === '') {
    localStorage.removeItem(AUTH_TOKEN_KEY)
    return
  }

  localStorage.setItem(AUTH_TOKEN_KEY, token.trim())
}

api.interceptors.request.use((config) => {
  const token = getAuthToken()
  if (token) {
    config.headers = config.headers ?? {}
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status
    if (status === 401 || status === 419) {
      setAuthToken(null)
      window.dispatchEvent(new Event('auth:unauthorized'))
    }
    return Promise.reject(error)
  }
)

export default api
