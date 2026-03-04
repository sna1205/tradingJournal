function normalizeBaseUrl(value) {
  const raw = String(value || '').trim()
  if (raw === '') return ''
  return raw.endsWith('/') ? raw.slice(0, -1) : raw
}

function normalizeStorageBaseUrl(value) {
  const normalized = normalizeBaseUrl(value)
  if (normalized === '') return ''
  return normalized.endsWith('/api') ? normalized.slice(0, -4) : normalized
}

function extractPathParts(pathValue) {
  if (Array.isArray(pathValue)) return pathValue
  if (typeof pathValue === 'string' && pathValue.trim() !== '') return [pathValue]
  return []
}

function getUpstreamSetCookieHeaders(upstreamResponse) {
  const headers = upstreamResponse?.headers
  if (!headers) return []

  if (typeof headers.getSetCookie === 'function') {
    const values = headers.getSetCookie()
    if (Array.isArray(values)) {
      return values.filter((value) => typeof value === 'string' && value.trim() !== '')
    }
  }

  if (typeof headers.raw === 'function') {
    const raw = headers.raw()
    if (raw && Array.isArray(raw['set-cookie'])) {
      return raw['set-cookie'].filter((value) => typeof value === 'string' && value.trim() !== '')
    }
  }

  const combined = headers.get('set-cookie')
  return typeof combined === 'string' && combined.trim() !== '' ? [combined] : []
}

module.exports = async function handler(req, res) {
  const baseSource = process.env.STORAGE_BASE_URL || process.env.API_BASE_URL || process.env.VITE_API_BASE_URL
  const storageBaseUrl = normalizeStorageBaseUrl(baseSource)
  if (storageBaseUrl === '') {
    res.status(500).json({
      message: 'Missing STORAGE_BASE_URL or API_BASE_URL environment variable for storage proxy.',
    })
    return
  }

  const pathParts = extractPathParts(req.query.path)
  const upstreamPath = pathParts.map((part) => encodeURIComponent(part)).join('/')
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(req.query)) {
    if (key === 'path') continue
    if (Array.isArray(value)) {
      for (const item of value) {
        query.append(key, String(item))
      }
      continue
    }
    if (value !== undefined) {
      query.append(key, String(value))
    }
  }

  const querySuffix = query.toString() ? `?${query.toString()}` : ''
  const trailingPath = upstreamPath === '' ? '' : `/${upstreamPath}`
  const upstreamUrl = `${storageBaseUrl}/storage${trailingPath}${querySuffix}`

  const headers = { ...req.headers }
  delete headers.host
  delete headers.connection
  delete headers['content-length']
  delete headers['x-forwarded-for']
  delete headers['x-forwarded-host']
  delete headers['x-forwarded-port']
  delete headers['x-forwarded-proto']

  const init = {
    method: req.method,
    headers,
  }

  const shouldForwardBody = req.method !== 'GET' && req.method !== 'HEAD'
  if (shouldForwardBody) {
    init.body = req
    init.duplex = 'half'
  }

  try {
    const upstream = await fetch(upstreamUrl, init)
    const setCookieValues = getUpstreamSetCookieHeaders(upstream)
    for (const [key, value] of upstream.headers.entries()) {
      const normalized = key.toLowerCase()
      if (normalized === 'set-cookie') continue
      if (normalized === 'content-encoding') continue
      if (normalized === 'transfer-encoding') continue
      if (normalized === 'connection') continue
      res.setHeader(key, value)
    }
    if (setCookieValues.length > 0) {
      res.setHeader('set-cookie', setCookieValues)
    }
    if (!upstream.headers.has('cache-control') && req.method === 'GET') {
      res.setHeader('cache-control', 'public, max-age=300, stale-while-revalidate=300')
    }

    res.status(upstream.status)
    const buffer = Buffer.from(await upstream.arrayBuffer())
    res.send(buffer)
  } catch (error) {
    res.status(502).json({
      message: 'Unable to reach upstream storage endpoint from Vercel proxy.',
      detail: error instanceof Error ? error.message : 'Unknown proxy error',
    })
  }
}

module.exports.config = {
  api: {
    bodyParser: false,
  },
}
