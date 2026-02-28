import axios, { type InternalAxiosRequestConfig } from 'axios'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'
const LEGACY_AUTH_KEYS = ['tj_auth_token', 'tj_auth_user_id']

const api = axios.create({
  baseURL: API_BASE_URL,
  timeout: 10000,
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
})

const csrfClient = axios.create({
  baseURL: resolveApiOrigin(API_BASE_URL) || undefined,
  timeout: 10000,
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
})

let csrfCookieReady = false
let csrfCookieRequest: Promise<void> | null = null

clearLegacyAuthStorage()

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (csrfCookieReady && !force) {
    return
  }
  if (csrfCookieRequest && !force) {
    return csrfCookieRequest
  }

  csrfCookieRequest = csrfClient.get('/sanctum/csrf-cookie')
    .then(() => {
      csrfCookieReady = true
    })
    .finally(() => {
      csrfCookieRequest = null
    })

  return csrfCookieRequest
}

api.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  if (requiresCsrf(config.method)) {
    await ensureCsrfCookie()
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status
    if (status === 419) {
      csrfCookieReady = false
    }
    if (status === 401 || status === 419) {
      window.dispatchEvent(new Event('auth:unauthorized'))
    }
    return Promise.reject(error)
  }
)

function requiresCsrf(method?: string): boolean {
  const normalized = String(method ?? 'get').toLowerCase()
  return normalized === 'post'
    || normalized === 'put'
    || normalized === 'patch'
    || normalized === 'delete'
}

function resolveApiOrigin(baseUrl: string): string {
  const trimmed = baseUrl.trim()
  if (trimmed === '' || trimmed.startsWith('/')) {
    return ''
  }

  try {
    const parsed = new URL(trimmed)
    return parsed.origin
  } catch {
    return ''
  }
}

function clearLegacyAuthStorage(): void {
  if (typeof window === 'undefined') {
    return
  }

  for (const key of LEGACY_AUTH_KEYS) {
    try {
      window.localStorage.removeItem(key)
      window.sessionStorage.removeItem(key)
    } catch {
      // Ignore storage access failures.
    }
  }
}

export default api
