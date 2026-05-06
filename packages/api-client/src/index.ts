import axios, { type AxiosInstance } from 'axios'

export interface ApiClientOptions {
  baseURL: string
  withCredentials?: boolean
  csrfHeader?: string
}

export interface ApiError {
  status: number
  code: string
  message: string
  details?: unknown
  traceId?: string
}

/**
 * Builds an axios instance configured for Catalyst Engine's JSON:API conventions.
 *
 * Sprint 0 ships the contract; Sprint 1 layers on Sanctum CSRF handling and
 * authenticated request flows. Subsequent sprints add per-module typed methods.
 */
export function createApiClient(options: ApiClientOptions): AxiosInstance {
  const instance = axios.create({
    baseURL: options.baseURL,
    withCredentials: options.withCredentials ?? true,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  })

  instance.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
      if (axios.isAxiosError(error) && error.response) {
        const data = error.response.data as Partial<ApiError> | undefined
        const apiError: ApiError = {
          status: error.response.status,
          code: data?.code ?? `HTTP_${error.response.status}`,
          message: data?.message ?? error.message,
          ...(data?.details !== undefined ? { details: data.details } : {}),
          ...(data?.traceId !== undefined ? { traceId: data.traceId } : {}),
        }
        return Promise.reject(apiError)
      }
      return Promise.reject(error)
    },
  )

  return instance
}

export type { AxiosInstance } from 'axios'
