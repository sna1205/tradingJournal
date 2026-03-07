import axios, { AxiosHeaders, type InternalAxiosRequestConfig } from 'axios'
import { createIdempotencyKey } from '@/utils/idempotency'

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

  if (requiresIdempotencyKey(config)) {
    const headers = AxiosHeaders.from(config.headers ?? {})
    if (!headers.has('Idempotency-Key')) {
      headers.set('Idempotency-Key', createIdempotencyKey())
    }
    config.headers = headers
  }

  attachIfMatchHeader(config)

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

function requiresIdempotencyKey(config: InternalAxiosRequestConfig): boolean {
  const method = String(config.method ?? 'get').toLowerCase()
  if (method !== 'post') {
    return false
  }

  const path = normalizeRequestPath(config.url)
  return path === '/trades'
    || /^\/trades\/\d+\/legs$/.test(path)
    || /^\/trades\/\d+\/images$/.test(path)
}

function attachIfMatchHeader(config: InternalAxiosRequestConfig): void {
  if (!requiresIfMatch(config)) {
    return
  }

  const headers = AxiosHeaders.from(config.headers ?? {})
  if (headers.has('If-Match')) {
    config.headers = headers
    return
  }

  const revisionRaw = headers.get('X-Trade-Revision')
  const revisionText = typeof revisionRaw === 'string' ? revisionRaw.trim() : ''
  const revision = Number.parseInt(revisionText, 10)
  if (Number.isInteger(revision) && revision > 0) {
    headers.set('If-Match', String(revision))
  }
  headers.delete('X-Trade-Revision')
  config.headers = headers
}

function requiresIfMatch(config: InternalAxiosRequestConfig): boolean {
  const method = String(config.method ?? 'get').toLowerCase()
  const path = normalizeRequestPath(config.url)

  if (method === 'put' && /^\/trades\/\d+\/(psychology|checklist-responses|rule-responses)$/.test(path)) {
    return true
  }

  if (method === 'post' && /^\/trades\/\d+\/images$/.test(path)) {
    return true
  }

  if ((method === 'put' || method === 'delete') && /^\/trade-images\/\d+$/.test(path)) {
    return true
  }

  return false
}

function normalizeRequestPath(rawUrl?: string): string {
  const raw = String(rawUrl ?? '').trim()
  if (raw === '') {
    return '/'
  }

  let path = raw
  try {
    path = new URL(raw, 'http://localhost').pathname
  } catch {
    path = raw.split('?')[0] ?? raw
  }

  if (!path.startsWith('/')) {
    path = `/${path}`
  }

  if (path.startsWith('/api/')) {
    return path.slice(4)
  }
  if (path === '/api') {
    return '/'
  }

  return path
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
