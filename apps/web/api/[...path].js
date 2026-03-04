function normalizeBaseUrl(value) {
  const raw = String(value || '').trim()
  if (raw === '') return ''
  return raw.endsWith('/') ? raw.slice(0, -1) : raw
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
  const apiBaseUrl = normalizeBaseUrl(process.env.API_BASE_URL || process.env.VITE_API_BASE_URL)
  if (apiBaseUrl === '') {
    res.status(500).json({
      message: 'Missing API_BASE_URL environment variable for Vercel API proxy.',
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
  const upstreamUrl = `${apiBaseUrl}/${upstreamPath}${querySuffix}`

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
    // Forward raw request stream (JSON, form-data, and binary bodies) without mutation.
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

    res.status(upstream.status)

    const buffer = Buffer.from(await upstream.arrayBuffer())
    res.send(buffer)
  } catch (error) {
    res.status(502).json({
      message: 'Unable to reach upstream API from Vercel proxy.',
      detail: error instanceof Error ? error.message : 'Unknown proxy error',
    })
  }
}

module.exports.config = {
  api: {
    bodyParser: false,
  },
}
