/// <reference types="vite/client" />

/** AH-121 C2: the Sentry release name, injected at build time (empty = none). */
declare const __SENTRY_RELEASE__: string

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
  readonly VITE_APP_NAME: string
  readonly VITE_SENTRY_DSN_MAIN?: string
  readonly VITE_SUPPORT_EMAIL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
