export function useApi() {
  const config = useRuntimeConfig()

  return <T>(path: string, options: Parameters<typeof $fetch<T>>[1] = {}) =>
    $fetch<T>(`${config.public.apiBase}${path}`, options)
}
