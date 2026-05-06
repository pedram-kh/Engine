import { createApiClient } from '@catalyst/api-client'

export const api = createApiClient({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
})
