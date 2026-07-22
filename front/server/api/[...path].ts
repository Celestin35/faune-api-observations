export default defineEventHandler((event) => {
  const config = useRuntimeConfig(event)
  const path = event.context.params?.path ?? ''
  const segments = path.split('/')

  if (segments.some(segment => segment === '.' || segment === '..')) {
    throw createError({ statusCode: 400, statusMessage: 'Invalid API path' })
  }

  const encodedPath = segments.map(encodeURIComponent).join('/')
  const target = `${config.apiBase.replace(/\/$/, '')}/${encodedPath}${getRequestURL(event).search}`

  return proxyRequest(event, target)
})
