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

  if (req.method !== 'GET' && req.method !== 'HEAD') {
    if (req.body !== undefined && req.body !== null) {
      init.body = typeof req.body === 'string' || Buffer.isBuffer(req.body)
        ? req.body
        : JSON.stringify(req.body)
      if (!headers['content-type']) {
        init.headers['content-type'] = 'application/json'
      }
    }
  }

  try {
    const upstream = await fetch(upstreamUrl, init)
    const responseHeaders = Object.fromEntries(upstream.headers.entries())
    delete responseHeaders['content-encoding']
    delete responseHeaders['transfer-encoding']

    Object.entries(responseHeaders).forEach(([key, value]) => {
      res.setHeader(key, value)
    })

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
